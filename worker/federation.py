#!/usr/bin/env python3
"""tracker-federation importer — pulls resolved index METADATA from federation peers.

Counterpart of the tracker web app's `v1/federation/export` endpoint (includes/federation.php).
Runs OUTSIDE PHP on purpose: a big exchange batch (thousands of rows + file lists) must not burn
web-request time. Walk every enabled peer, page through its export with the stored cursor and merge:

  - a hash this tracker has OBSERVED but not resolved (meta none/failed/pending) gets the peer's
    metadata (name/size/files) and becomes 'done' with meta_source='fed:<peer>' — the whole point:
    no second DHT fetch for something a peer already resolved;
  - a hash never seen here is inserted only when the `fed_import_new` setting is 1;
  - locally resolved rows ('done'/'fetching') and whitelisted/banned hashes are never touched.

Peers, cursors and per-peer status live in the `fed_peers` table (admin: Settings → Federation).
Feature switches live in the `settings` table (fed_enabled, fed_import_new, ...) — this script
reads them itself, so flipping a setting in the panel needs no service restart.

    python3 federation.py /etc/tracker-metadata.conf              # one pass (systemd timer)
    python3 federation.py /etc/tracker-metadata.conf --loop       # keep running (fed_pull_minutes)
    python3 federation.py /etc/tracker-metadata.conf --peer NAME  # sync one peer
    python3 federation.py --self-test                             # offline validation tests

Requires: python3-pymysql. Uses the same [db] credentials as worker.py — the `tracker_meta` DB user
additionally needs SELECT on `settings`/`whitelist`/`banned_hashes`, SELECT/UPDATE on `fed_peers`
and INSERT/UPDATE/DELETE on `index_hashes`/`index_files` (see README.md).
"""
import argparse, configparser, gzip, io, json, logging, re, sys, time
import urllib.request
import urllib.error

log = logging.getLogger("tracker-federation")

HASH_RE = re.compile(r"^[a-f0-9]{40}$")
BEARER_RE = re.compile(r"^[a-f0-9]{16}\.[a-f0-9]{64}$")
DT_RE = re.compile(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$")
MAX_FILES_PER_ROW = 5000
MAX_PAGES_PER_RUN = 200          # bound one run; the cursor continues next time
HTTP_TIMEOUT = 60
MAX_COMPRESSED_BYTES = 64 * 1024 * 1024     # per page on the wire
MAX_EXPANDED_BYTES = 512 * 1024 * 1024      # per page after gzip — a bomb from a compromised peer must not OOM the host


# ── validation (pure — covered by --self-test) ──────────────────────────────────

def sanitize_path(p):
    """One relative, printable file path or None. Mirrors what the local worker would store."""
    if not isinstance(p, str):
        return None
    p = p.replace("\\", "/")
    p = "".join(ch for ch in p if ch >= " " and ch != "\x7f")
    p = p.lstrip("/")
    if re.match(r"^[A-Za-z]:", p):          # windows drive prefix
        p = p[2:].lstrip("/")
    parts = [seg for seg in p.split("/") if seg not in ("", ".")]
    if not parts or any(seg == ".." for seg in parts):
        return None
    out = "/".join(parts)[:1000]
    return out or None


def valid_row(r):
    """Validate one export row; returns a normalised dict or None. Never trusts the peer."""
    if not isinstance(r, dict):
        return None
    h = str(r.get("h", "")).lower()
    if not HASH_RE.match(h):
        return None
    name = r.get("n")
    if name is not None:
        name = str(name)[:255].strip() or None
    def nn_int(v, lo=0, hi=2**63 - 1):
        try:
            v = int(v)
        except (TypeError, ValueError):
            return None
        return v if lo <= v <= hi else None
    size = nn_int(r.get("s"))
    fc = nn_int(r.get("fc"), 0, 50_000_000)
    pl = nn_int(r.get("pl"), 0, 2**31 - 1)
    sl = r.get("sl") or [0, 0]
    seeders = nn_int(sl[0] if isinstance(sl, list) and len(sl) > 0 else 0, 0, 100_000_000) or 0
    leechers = nn_int(sl[1] if isinstance(sl, list) and len(sl) > 1 else 0, 0, 100_000_000) or 0
    seen = r.get("seen") or {}
    def valid_dt(v):
        if not (isinstance(v, str) and DT_RE.match(v)):
            return None
        try:
            time.strptime(v, "%Y-%m-%d %H:%M:%S")
        except ValueError:
            return None
        return v
    first = valid_dt(seen.get("f") if isinstance(seen, dict) else None)
    last = valid_dt(seen.get("l") if isinstance(seen, dict) else None)
    count = nn_int(seen.get("c") if isinstance(seen, dict) else 1, 1, 2**31 - 1) or 1
    files = []
    for f in (r.get("files") or [])[:MAX_FILES_PER_ROW]:
        if not (isinstance(f, list) and len(f) >= 2):
            continue
        p = sanitize_path(f[0])
        s = nn_int(f[1])
        if p is not None and s is not None:
            files.append((p, s))
    return {"h": h, "name": name, "size": size, "fc": fc, "pl": pl,
            "seeders": seeders, "leechers": leechers,
            "first": first, "last": last, "count": count, "files": files}


def parse_cursor(raw):
    """fed_peers.last_pull_cursor '<since>:<after40hex>' -> (int, str)."""
    raw = (raw or "").strip()
    m = re.match(r"^(\d{1,12}):([a-f0-9]{40})$", raw)
    if m:
        return int(m.group(1)), m.group(2)
    m = re.match(r"^(\d{1,12}):?$", raw)
    if m:
        return int(m.group(1)), ""
    return 0, ""


# ── db + settings ───────────────────────────────────────────────────────────────

class Db:
    def __init__(self, params):
        self.params = params
        self.conn = None

    def _connect(self):
        import pymysql
        if self.conn is None:
            self.conn = pymysql.connect(**self.params)
        return self.conn

    def query(self, sql, args=None, fetch=False):
        import pymysql
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


def load_settings(db):
    out = {}
    for row in db.query("SELECT `key`, `value` FROM settings", fetch=True):
        out[row["key"]] = row["value"]
    return out


def setting_int(cfg, key, default, lo, hi):
    try:
        v = int(cfg.get(key, default))
    except (TypeError, ValueError):
        v = default
    return max(lo, min(hi, v))


# ── peer HTTP ───────────────────────────────────────────────────────────────────

def fetch_page(peer, since, after, limit, want_files):
    """One export page from a peer. Returns the decoded JSON dict; raises on transport errors."""
    url = peer["base_url"].rstrip("/") + "/api.php?endpoint=v1/federation/export"
    body = json.dumps({"since": since, "after": after, "limit": limit,
                       "files": bool(want_files), "gzip": True}).encode()
    req = urllib.request.Request(url, data=body, method="POST", headers={
        "Authorization": "Bearer " + peer["bearer"],
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "tryhackx-tracker/1.6 federation.py",
    })
    with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT) as res:
        raw = res.read(MAX_COMPRESSED_BYTES + 1)
        if len(raw) > MAX_COMPRESSED_BYTES:
            raise RuntimeError("peer response exceeds %d compressed bytes" % MAX_COMPRESSED_BYTES)
        if (res.headers.get("Content-Encoding") or "").lower() == "gzip":
            # decompress in bounded chunks — never trust the peer's compression ratio (gzip bombs)
            gz = gzip.GzipFile(fileobj=io.BytesIO(raw))
            out = bytearray()
            while True:
                chunk = gz.read(1 << 20)
                if not chunk:
                    break
                out += chunk
                if len(out) > MAX_EXPANDED_BYTES:
                    raise RuntimeError("peer response exceeds %d bytes after decompression" % MAX_EXPANDED_BYTES)
            raw = bytes(out)
    data = json.loads(raw.decode("utf-8", "replace"))
    if not isinstance(data, dict) or not data.get("ok"):
        raise RuntimeError("peer error: %s" % (data.get("error") if isinstance(data, dict) else "not json"))
    return data


# ── merge ───────────────────────────────────────────────────────────────────────

def merge_rows(db, rows, peer_name, cfg):
    """Merge validated rows. Returns (filled, inserted, skipped)."""
    import_new = str(cfg.get("fed_import_new", "0")) == "1"
    keep_files = str(cfg.get("index_keep_files", "1")) == "1"
    grace_days = setting_int(cfg, "index_grace_days", 3, 1, 90)
    max_rows = setting_int(cfg, "index_max_rows", 200000, 10, 5000000)
    source = ("fed:" + peer_name)[:24]
    filled = inserted = skipped = 0
    total = None
    for r in rows:
        h = r["h"]
        # never touch hashes that live in the whitelist / ban tables
        if db.query("SELECT 1 FROM whitelist WHERE info_hash=%s UNION SELECT 1 FROM banned_hashes WHERE info_hash=%s LIMIT 1", (h, h), fetch=True):
            skipped += 1
            continue
        existing = db.query("SELECT meta_status FROM index_hashes WHERE info_hash=%s", (h,), fetch=True)
        if existing:
            st = existing[0]["meta_status"]
            if st in ("done", "fetching"):
                skipped += 1
                continue
            n = db.query(
                "UPDATE index_hashes SET name=%s, total_size=%s, files_count=%s, piece_length=%s,"
                " meta_status='done', meta_fetched_at=NOW(), meta_error=NULL, meta_claim=NULL, meta_source=%s"
                " WHERE info_hash=%s AND meta_status NOT IN ('done','fetching')",
                (r["name"], r["size"], r["fc"], r["pl"], source, h))
            if not n:
                skipped += 1        # raced with the local worker — its result wins
                continue
            filled += 1
        else:
            if not import_new:
                skipped += 1
                continue
            if total is None:
                total = db.query("SELECT COUNT(*) c FROM index_hashes", fetch=True)[0]["c"]
            if total >= max_rows:
                skipped += 1        # over the cap — the pruner would evict these right away
                continue
            db.query(
                "INSERT IGNORE INTO index_hashes (info_hash, name, first_seen, last_seen, seen_count,"
                " last_seeders, last_leechers, grace_until, meta_status, meta_fetched_at, meta_source,"
                " total_size, files_count, piece_length)"
                " VALUES (%s, %s, COALESCE(%s, NOW()), COALESCE(%s, NOW()), %s, %s, %s,"
                "         NOW() + INTERVAL %s DAY, 'done', NOW(), %s, %s, %s, %s)",
                (h, r["name"], r["first"], r["last"], r["count"], r["seeders"], r["leechers"],
                 grace_days, source, r["size"], r["fc"], r["pl"]))
            total += 1
            inserted += 1
        if keep_files and r["files"]:
            db.query("DELETE FROM index_files WHERE info_hash=%s", (h,))
            conn = db._connect()
            with conn.cursor() as cur:
                cur.executemany("INSERT INTO index_files (info_hash, path, size) VALUES (%s, %s, %s)",
                                [(h, p, s) for p, s in r["files"]])
    return filled, inserted, skipped


def sync_peer(db, peer, cfg):
    """Pull every available page from one peer. Updates fed_peers status/cursor. Never raises."""
    name = peer["name"]
    since, after = parse_cursor(peer.get("last_pull_cursor"))
    want_files = int(peer.get("pull_files") or 0) == 1 and str(cfg.get("index_keep_files", "1")) == "1"
    batch = setting_int(cfg, "fed_export_max_batch", 2000, 100, 20000)
    filled = inserted = skipped = pages = 0
    status = "ok"
    try:
        for _page in range(MAX_PAGES_PER_RUN):
            data = fetch_page(peer, since, after, batch, want_files)
            rows = [v for v in (valid_row(r) for r in (data.get("rows") or [])) if v is not None]
            f, i, s = merge_rows(db, rows, name, cfg)
            filled += f
            inserted += i
            skipped += s
            pages += 1
            nxt = data.get("next")
            if isinstance(nxt, dict) and HASH_RE.match(str(nxt.get("after", ""))):
                since, after = int(nxt.get("since", since)), str(nxt["after"])
            if not data.get("has_more"):
                break
        else:
            status = "ok (page budget hit — continues next run)"
    except urllib.error.HTTPError as e:
        status = "HTTP %d from peer" % e.code
    except Exception as e:
        status = ("error: %s" % e)[:255]
    summary = "%s: +%d filled, +%d new, %d skipped, %d page(s)" % (status, filled, inserted, skipped, pages)
    log.info("[%s] %s", name, summary)
    db.query(
        "UPDATE fed_peers SET last_pull_at=NOW(), last_pull_cursor=%s, last_status=%s,"
        " rows_imported=rows_imported+%s WHERE id=%s",
        ("%d:%s" % (since, after), summary[:255], filled + inserted, peer["id"]))
    return filled + inserted


def run_once(db, only_peer=None, force=False):
    cfg = load_settings(db)
    if str(cfg.get("fed_enabled", "0")) != "1" and not force:
        log.info("federation disabled (fed_enabled=0) — nothing to do")
        return 0
    peers = db.query("SELECT id, name, base_url, bearer, pull_enabled, pull_files, last_pull_cursor FROM fed_peers", fetch=True)
    total = 0
    for p in peers:
        if only_peer and p["name"] != only_peer:
            continue
        if not force and int(p.get("pull_enabled") or 0) != 1:
            continue
        bearer = (p.get("bearer") or "").strip().lower()   # hex either case; PHP normalises new rows, this covers old ones
        if not BEARER_RE.match(bearer):
            log.info("[%s] skipped: no outbound bearer configured", p["name"])
            continue
        p["bearer"] = bearer
        total += sync_peer(db, p, cfg)
    return total


# ── self test (offline) ─────────────────────────────────────────────────────────

def self_test():
    ok = True
    def t(name, cond):
        nonlocal ok
        print(("PASS " if cond else "FAIL ") + name)
        ok = ok and cond
    t("sanitize keeps a normal path", sanitize_path("dir/sub/file.bin") == "dir/sub/file.bin")
    t("sanitize strips leading slash + drive", sanitize_path("C:/evil/f") == "evil/f" and sanitize_path("/abs/f") == "abs/f")
    t("sanitize rejects traversal", sanitize_path("a/../b") is None and sanitize_path("..") is None)
    t("sanitize normalises backslashes", sanitize_path("a\\b\\c") == "a/b/c")
    t("sanitize drops control chars", sanitize_path("a\x00b/c\x1fd") == "ab/cd")
    t("sanitize rejects empty", sanitize_path("") is None and sanitize_path("///") is None and sanitize_path(123) is None)
    good = valid_row({"h": "a" * 40, "n": "Name", "s": 10, "fc": 2, "pl": 16384, "sl": [3, 1],
                      "seen": {"f": "2026-01-01 00:00:00", "l": "2026-01-02 00:00:00", "c": 5},
                      "mf": 1, "files": [["x/y", 5], ["bad/../z", 1], ["ok", "notint"]]})
    t("valid_row accepts a good row", good is not None and good["name"] == "Name" and good["count"] == 5)
    t("valid_row keeps only sane files", good is not None and good["files"] == [("x/y", 5)])
    t("valid_row rejects a bad hash", valid_row({"h": "xyz"}) is None and valid_row("nope") is None)
    t("valid_row survives hostile types", valid_row({"h": "b" * 40, "s": "big", "sl": "x", "seen": [1], "files": "no"}) is not None)
    bad_dt = valid_row({"h": "c" * 40, "seen": {"f": "DROP TABLE", "l": "2026-13-99 99:99:99"}})
    t("valid_row drops malformed datetimes", bad_dt is not None and bad_dt["first"] is None and bad_dt["last"] is None)
    t("cursor parses", parse_cursor("123:" + "a" * 40) == (123, "a" * 40) and parse_cursor("") == (0, "") and parse_cursor("junk") == (0, ""))
    print("self-test: " + ("OK" if ok else "FAILED"))
    return 0 if ok else 1


def main():
    ap = argparse.ArgumentParser(description="Federation metadata importer")
    ap.add_argument("conf", nargs="?", help="path to tracker-metadata.conf (its [db] section is used)")
    ap.add_argument("--loop", action="store_true", help="run forever, sleeping fed_pull_minutes between passes")
    ap.add_argument("--peer", help="sync only the peer with this name")
    ap.add_argument("--force", action="store_true", help="ignore fed_enabled / pull_enabled flags")
    ap.add_argument("--self-test", action="store_true", help="run offline validation tests and exit")
    args = ap.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    if args.self_test:
        sys.exit(self_test())
    if not args.conf:
        ap.error("conf path required (unless --self-test)")
    cp = configparser.ConfigParser()
    if not cp.read(args.conf):
        sys.exit("cannot read config %s" % args.conf)
    dbc = cp["db"]
    db = Db(dict(host=dbc.get("host", "localhost"), user=dbc.get("user"), password=dbc.get("password"),
                 database=dbc.get("name", "tracker"), charset="utf8mb4", autocommit=True,
                 connect_timeout=10, read_timeout=120, write_timeout=120))
    if not args.loop:
        run_once(db, args.peer, args.force)
        return
    while True:
        try:
            run_once(db, args.peer, args.force)
        except Exception as e:
            log.warning("pass failed: %s", e)
        cfg = {}
        try:
            cfg = load_settings(db)
        except Exception:
            pass
        time.sleep(setting_int(cfg, "fed_pull_minutes", 60, 5, 1440) * 60)


if __name__ == "__main__":
    main()
