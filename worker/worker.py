#!/usr/bin/env python3
"""tracker-metadata worker — fetches torrent metadata (name, size, file list) for whitelisted hashes.

Queue = the `whitelist` table of the tracker web app (columns meta_status/meta_claim/...); the web
app sets meta_status='pending' (admin "Fetch details", API/forum/admin additions), this daemon
claims rows, resolves the metadata through DHT + the configured trackers with libtorrent in
upload_mode (never downloads payload — only the ~KB metadata) and writes name/total_size/
files_count/piece_length + the file list into `whitelist_files`. Heartbeat = mtime of a file the
web app can stat. Runs as the unprivileged `tracker` user under systemd (see tracker-metadata.service).

    python3 worker.py /etc/tracker-metadata.conf

Requires: python3-libtorrent (libtorrent-rasterbar 2.x bindings), python3-pymysql.
"""
import configparser, json, logging, os, secrets, signal, sys, time

try:
    import libtorrent as lt
except Exception as e:  # pragma: no cover
    sys.exit(f"python3-libtorrent is required: {e}")
try:
    import pymysql
except Exception as e:  # pragma: no cover
    sys.exit(f"python3-pymysql is required: {e}")

log = logging.getLogger("tracker-metadata")


class Config:
    def __init__(self, path):
        cp = configparser.ConfigParser()
        if not cp.read(path):
            sys.exit(f"cannot read config {path}")
        db = cp["db"]
        w = cp["worker"] if cp.has_section("worker") else {}
        self.db = dict(host=db.get("host", "localhost"), user=db.get("user"), password=db.get("password"), database=db.get("name", "tracker"),
                       charset="utf8mb4", autocommit=True, connect_timeout=10, read_timeout=30, write_timeout=30)
        self.concurrency = max(1, min(8, int(w.get("concurrency", 3))))
        self.timeout = max(20, min(600, int(w.get("timeout_seconds", 90))))
        # Second queue: the observed-hash index (includes/index.php). Empty = disabled (default), so an
        # existing deployment keeps whitelist-only behaviour until index_table is configured AND the
        # tracker_meta DB user is granted on these tables (see worker/README.md).
        self.index_table = (w.get("index_table", "") or "").strip()
        self.index_files_table = (w.get("index_files_table", "index_files") or "index_files").strip()
        self.index_keep_files = str(w.get("index_keep_files", "1")).strip() not in ("0", "false", "no", "")
        self.poll = max(1, min(60, int(w.get("poll_interval", 3))))
        self.stale_minutes = max(2, int(w.get("stale_claim_minutes", 10)))
        self.heartbeat = w.get("heartbeat_file", "/home/tracker/metadata_worker/heartbeat")
        self.listen_port = int(w.get("listen_port", 6881))
        self.trackers = [t.strip() for t in w.get("trackers", "").split(",") if t.strip()]
        self.max_files = max(1, min(50000, int(w.get("max_files", 5000))))
        self.tmp_dir = w.get("tmp_dir", "/home/tracker/metadata_worker/tmp")
        self.log_level = w.get("log_level", "INFO").upper()
        self.dht_routers = [r.strip() for r in w.get("dht_routers", "router.bittorrent.com:6881,router.utorrent.com:6881,dht.transmissionbt.com:6881,dht.aelitis.com:6881,dht.libtorrent.org:25401").split(",") if r.strip()]
        self.download_rate = int(w.get("download_rate_limit", 262144))
        self.connections = int(w.get("connections_limit", 200))


class Db:
    def __init__(self, params):
        self.params = params
        self.conn = None

    def _connect(self):
        if self.conn is None:
            self.conn = pymysql.connect(**self.params)
        return self.conn

    def query(self, sql, args=None, fetch=False):
        for attempt in (1, 2):
            try:
                conn = self._connect()
                with conn.cursor(pymysql.cursors.DictCursor) as cur:
                    cur.execute(sql, args or ())
                    if fetch:
                        return cur.fetchall()
                    return cur.rowcount
            except (pymysql.err.OperationalError, pymysql.err.InterfaceError) as e:
                log.warning("db error (%s), reconnecting", e)
                try:
                    if self.conn:
                        self.conn.close()
                except Exception:
                    pass
                self.conn = None
                if attempt == 2:
                    raise
                time.sleep(1)


class Worker:
    def __init__(self, cfg):
        self.cfg = cfg
        self.db = Db(cfg.db)
        self.active = {}  # claim token -> dict(row, handle, deadline)
        self.running = True
        os.makedirs(cfg.tmp_dir, exist_ok=True)
        os.makedirs(os.path.dirname(cfg.heartbeat), exist_ok=True)
        settings = {
            "listen_interfaces": f"0.0.0.0:{cfg.listen_port},[::]:{cfg.listen_port}",
            "enable_dht": True, "enable_lsd": False, "enable_upnp": False, "enable_natpmp": False,
            "download_rate_limit": cfg.download_rate, "connections_limit": cfg.connections,
            "user_agent": "tracker-metadata/1.0 libtorrent/" + lt.__version__,
            "alert_mask": lt.alert.category_t.status_notification | lt.alert.category_t.error_notification,
            "announce_to_all_trackers": True, "announce_to_all_tiers": True,
            # session-level DHT bootstrap (add_dht_router() is deprecated in libtorrent 2.x)
            "dht_bootstrap_nodes": ",".join(cfg.dht_routers),
        }
        self.ses = lt.session(settings)
        # Queues drained in order: the whitelist first, then (if configured) the observed-hash index —
        # so an index fetch never delays a whitelist one. Each descriptor says how to claim/finish rows.
        #   key_col : the WHERE key for finish()/claim re-select (whitelist has a numeric id; index is keyed
        #             by info_hash). files_fk : the column in the files table linking to the parent row.
        #   gate    : extra WHERE for claim (the index spreads meta_requested_at into the future).
        self.queues = [
            {"table": "whitelist", "files": "whitelist_files", "key_col": "id", "files_fk": "whitelist_id",
             "select": "id, info_hash, magnet_link", "gate": ""},
        ]
        if cfg.index_table:
            self.queues.append({"table": cfg.index_table, "files": cfg.index_files_table, "key_col": "info_hash",
                                "files_fk": "info_hash", "select": "info_hash", "gate": " AND meta_requested_at <= NOW()"})
        log.info("session started (libtorrent %s), listen %s, concurrency %d, timeout %ds, queues %s",
                 lt.__version__, cfg.listen_port, cfg.concurrency, cfg.timeout, ",".join(q["table"] for q in self.queues))

    # ── queue ──────────────────────────────────────────────────────────────
    def heartbeat(self):
        try:
            with open(self.cfg.heartbeat, "a"):
                pass
            os.utime(self.cfg.heartbeat, None)
        except Exception as e:
            log.warning("heartbeat: %s", e)

    def reset_stale(self):
        for q in self.queues:
            try:
                n = self.db.query("UPDATE %s SET meta_status='pending', meta_claim=NULL WHERE meta_status='fetching' AND meta_claimed_at < NOW() - INTERVAL %%s MINUTE" % q["table"], (self.cfg.stale_minutes,))
                if n:
                    log.info("reset %d stale claims in %s", n, q["table"])
            except Exception as e:
                log.warning("reset_stale(%s): %s", q["table"], e)

    def claim(self):
        # try each queue in order; the whitelist must be empty before an index row is claimed.
        # Two-step claim: a plain SELECT picks the candidate (consistent read, NO row locks — the old
        # single UPDATE ... ORDER BY ... LIMIT 1 filesorted AND X-locked the whole pending set on a big
        # queue), then the UPDATE claims exactly that row by primary key with a status recheck; if
        # another actor won the race, retry with the next candidate.
        for q in self.queues:
            for _attempt in range(3):
                token = secrets.token_hex(8)
                try:
                    rows = self.db.query(
                        "SELECT %s FROM %s WHERE meta_status='pending'%s ORDER BY meta_priority DESC, meta_requested_at ASC LIMIT 1" % (q["select"], q["table"], q["gate"]),
                        fetch=True)
                    if not rows:
                        break                      # queue empty — fall through to the next queue
                    row = rows[0]
                    n = self.db.query(
                        "UPDATE %s SET meta_status='fetching', meta_claim=%%s, meta_claimed_at=NOW() WHERE %s=%%s AND meta_status='pending'" % (q["table"], q["key_col"]),
                        (token, row[q["key_col"]]))
                    if not n:
                        continue                   # raced — pick a fresh candidate
                except Exception as e:
                    log.warning("claim(%s): %s", q["table"], e)
                    break
                row["token"] = token
                row["_q"] = q
                return row
        return None

    def start(self, row):
        magnet = row.get("magnet_link") or ""
        if not magnet.startswith("magnet:?"):
            magnet = "magnet:?xt=urn:btih:" + row["info_hash"]
        try:
            params = lt.parse_magnet_uri(magnet)
        except Exception:
            params = lt.parse_magnet_uri("magnet:?xt=urn:btih:" + row["info_hash"])
        params.save_path = self.cfg.tmp_dir
        params.flags |= lt.torrent_flags.upload_mode
        # libtorrent's default add_torrent_params flags include `paused` AND `auto_managed` (the queue
        # manager is what un-pauses auto-managed torrents). We take the torrent out of the queue
        # manager, so we MUST clear `paused` too — otherwise it never connects to anyone and every
        # fetch ends in a timeout.
        params.flags &= ~(lt.torrent_flags.auto_managed | lt.torrent_flags.paused)
        try:
            params.trackers = list(dict.fromkeys(list(params.trackers) + self.cfg.trackers))
        except Exception:
            pass
        h = self.ses.add_torrent(params)
        self.active[row["token"]] = {"row": row, "handle": h, "deadline": time.time() + self.cfg.timeout, "started": time.time()}
        log.info("fetch %s:%s %s (%d active)", row["_q"]["table"], row.get("id", row["info_hash"]), row["info_hash"], len(self.active))

    def finish(self, token, ok, ti=None, error=None):
        item = self.active.pop(token, None)
        if not item:
            return
        row = item["row"]
        try:
            self.ses.remove_torrent(item["handle"], lt.options_t.delete_files)
        except Exception:
            try:
                self.ses.remove_torrent(item["handle"])
            except Exception:
                pass
        q = row["_q"]
        kc = q["key_col"]                 # 'id' (whitelist) or 'info_hash' (index)
        kv = row[kc]
        keep_files = self.cfg.index_keep_files if q["table"] == self.cfg.index_table else True
        if ok and ti is not None:
            try:
                name = ti.name()[:255]
                total = int(ti.total_size())
                piece = int(ti.piece_length())
                fs = ti.files()
                count = int(fs.num_files())
                files = []
                for i in range(min(count, self.cfg.max_files)):
                    p = fs.file_path(i)
                    if isinstance(p, bytes):
                        p = p.decode("utf-8", "replace")
                    files.append((p[:1000], int(fs.file_size(i))))
                # the index table (schema v7) records where the metadata came from ('dht' vs 'fed:<peer>');
                # the whitelist table has no such column. Against a not-yet-migrated DB (or a grant that
                # doesn't cover the new column yet) the stamped UPDATE fails — fall back WITHOUT the
                # stamp instead of throwing the fetched metadata away as 'failed'.
                src = ", meta_source='dht'" if (q["table"] == self.cfg.index_table and getattr(self, "_meta_source_ok", True)) else ""
                sql = "UPDATE %s SET name=%%s, total_size=%%s, files_count=%%s, piece_length=%%s, meta_status='done', meta_fetched_at=NOW(), meta_error=NULL, meta_claim=NULL%s WHERE %s=%%s AND meta_claim=%%s"
                try:
                    self.db.query(sql % (q["table"], src, kc), (name, total, count, piece, kv, token))
                except Exception as e:
                    if not src:
                        raise
                    log.warning("meta_source column unavailable (%s) — storing without it from now on", e)
                    self._meta_source_ok = False
                    self.db.query(sql % (q["table"], "", kc), (name, total, count, piece, kv, token))
                self.db.query("DELETE FROM %s WHERE %s=%%s" % (q["files"], q["files_fk"]), (row["info_hash"] if q["files_fk"] == "info_hash" else kv,))
                if files and keep_files:
                    conn = self.db._connect()
                    with conn.cursor() as cur:
                        fk = row["info_hash"] if q["files_fk"] == "info_hash" else kv
                        cur.executemany("INSERT INTO %s (%s, path, size) VALUES (%%s, %%s, %%s)" % (q["files"], q["files_fk"]), [(fk, p, s) for p, s in files])
                log.info("done %s:%s %s name=%r size=%d files=%d in %ds", q["table"], kv, row["info_hash"], name, total, count, time.time() - item["started"])
                return
            except Exception as e:
                error = f"store failed: {e}"
                log.warning("store %s:%s: %s", q["table"], kv, e)
        err = (error or "unknown error")[:255]
        try:
            self.db.query("UPDATE %s SET meta_status='failed', meta_error=%%s, meta_fetched_at=NOW(), meta_claim=NULL WHERE %s=%%s AND meta_claim=%%s" % (q["table"], kc), (err, kv, token))
        except Exception as e:
            log.warning("mark failed %s:%s: %s", q["table"], kv, e)
        log.info("failed %s:%s %s: %s", q["table"], kv, row["info_hash"], err)

    # ── main loop ──────────────────────────────────────────────────────────
    def run(self):
        last_hb = 0
        last_stale = 0
        while self.running:
            now = time.time()
            if now - last_hb >= self.cfg.poll:
                self.heartbeat()
                last_hb = now
            if now - last_stale >= 60:
                self.reset_stale()
                last_stale = now
            # process alerts (never let one malformed alert kill the daemon)
            try:
                for a in self.ses.pop_alerts():
                    if isinstance(a, lt.metadata_received_alert):
                        h = a.handle
                        for token, item in list(self.active.items()):
                            if item["handle"] == h:
                                try:
                                    ti = h.torrent_file()
                                except Exception:
                                    ti = None
                                if ti is not None:
                                    self.finish(token, True, ti)
                                break
                    elif isinstance(a, lt.torrent_error_alert):
                        for token, item in list(self.active.items()):
                            if item["handle"] == a.handle:
                                # index rows have no 'id' (keyed by info_hash) — use the same fallback as start()
                                log.debug("torrent error #%s: %s", item["row"].get("id", item["row"]["info_hash"]), a.message())
                                break
            except Exception as e:
                log.warning("alert processing: %s", e)
            # deadlines
            for token, item in list(self.active.items()):
                try:
                    st = item["handle"].status()
                    if st.has_metadata:
                        ti = item["handle"].torrent_file()
                        if ti is not None:
                            self.finish(token, True, ti)
                            continue
                except Exception:
                    pass
                if time.time() > item["deadline"]:
                    self.finish(token, False, None, f"timeout (no metadata within {self.cfg.timeout} s)")
            # claim new work
            try:
                while len(self.active) < self.cfg.concurrency:
                    row = self.claim()
                    if not row:
                        break
                    self.start(row)
            except Exception as e:
                log.warning("claim/start: %s", e)
                time.sleep(2)
            time.sleep(0.5)
        # shutdown: release claims
        for token, item in list(self.active.items()):
            try:
                q = item["row"]["_q"]
                self.db.query("UPDATE %s SET meta_status='pending', meta_claim=NULL WHERE %s=%%s AND meta_claim=%%s" % (q["table"], q["key_col"]), (item["row"][q["key_col"]], token))
                self.ses.remove_torrent(item["handle"], lt.options_t.delete_files)
            except Exception:
                pass
        log.info("stopped")


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    cfg = Config(sys.argv[1])
    logging.basicConfig(level=getattr(logging, cfg.log_level, logging.INFO), format="%(asctime)s %(levelname)s %(message)s", stream=sys.stdout)
    w = Worker(cfg)

    def stop(signum, frame):
        log.info("signal %s, stopping", signum)
        w.running = False
    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    w.run()
    return 0


if __name__ == "__main__":
    sys.exit(main())
