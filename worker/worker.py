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
import configparser, json, logging, os, re, secrets, signal, sys, time

# The parallel-fetch ceiling, in ONE place. It used to be written as a literal in two of them, which
# is how a build enforcing 16 and a panel offering 64 ended up in the same install.
CONCURRENCY_MAX = 64
WORKER_VERSION = 4

# ── which pending hash goes next ─────────────────────────────────────────────
#
# The index queue is ~2.9 million rows deep and resolves at a few per second, so the ORDER of the
# queue is not a detail: it decides which torrents this tracker knows anything about for the next
# several months. "Queue order" is fair, and it is also the reason a brand-new release sits behind
# a million hashes nobody has seeded since 2019.
#
# Each selector below is one ORDER BY that an EXISTING index can serve. That constraint is the whole
# design: a claim runs on every fetch slot, so a sort the database has to compute (name, size, file
# count) would mean a filesort over three million rows several times a second.
#
# TWO LANES, AND THIS IS THE PART THAT COSTS 54 SECONDS IF YOU GET IT WRONG
# -------------------------------------------------------------------------
# `meta_priority` is -1 for everything the daily budget queued (2.91 M rows here) and 0 or 5 for the
# 43 k rows somebody asked for by name. The obvious query -- "priority first, then X" over the whole
# queue -- cannot use any index for its order, because with meta_status fixed and meta_priority still
# free, no index gives the sequence. MariaDB filesorts the entire table. MEASURED on production:
#
#     ORDER BY meta_priority DESC, meta_requested_at ASC   3 722 ms   <- the historical default
#     ORDER BY meta_priority DESC, seen_count      DESC   23 689 ms
#     ORDER BY meta_priority DESC, last_seeders    DESC   54 448 ms
#
# ...on EVERY claim. Splitting the same question into two lanes makes both halves cheap, and means
# exactly the same thing: everything somebody asked for, then the bulk queue in the chosen order.
#
#     lane 1   meta_priority > -1   43 k rows; any ordering is affordable          89 ms
#     lane 2   meta_priority = -1   now BOTH leading index columns are equalities,
#                                   so the NEXT column in the index supplies the
#                                   order for free -- no filesort at all       66-99 ms
#
# The orderings, as they run in lane 2:
#
#   oldest     meta_requested_at ASC    idx_index_meta            (queue order, as added to pending)
#   newest     meta_requested_at DESC   idx_index_meta            (same index, backwards)
#   seeders    last_seeders   DESC      idx_index_meta_seed
#   seen       seen_count     DESC      idx_index_meta_seen       (schema v31)
#   completed  last_completed DESC      idx_index_meta_completed  (schema v31)
#   random     info_hash >= <random>    PRIMARY KEY
#
# `random` deserves a note: `ORDER BY RAND()` reads and sorts the whole table, which is exactly what
# must not happen here. Info hashes are SHA-1 digests, so they are uniformly spread across the key
# space — picking a random 20-byte point and taking the first pending row at or after it is an index
# seek, and it is uniform for the same reason the digests are.
ORDER_SELECTORS = ("oldest", "newest", "seeders", "seen", "completed", "random")
ORDER_MODES = ORDER_SELECTORS + ("mix",)

# `whitelist` is a share of the mix and NOT a mode. Outside the mix the whitelist always drains
# first; and there is nothing to sort by inside the index, because a hash that reaches the whitelist
# is deleted from the index on the next poll. As a share it answers the real question — "a bulk
# import just put fifty thousand rows in front of my index, can both move?" — by giving the whitelist
# a guaranteed slice of the rotation instead of all of it.
ORDER_MIX_KEYS = ("whitelist",) + ORDER_SELECTORS

# The index each selector rides on. Checked against the live database before the plan is built: the
# v31 indexes are created by a heavy ALTER the janitor runs out of band, so there is a window where
# the setting exists and the index does not. Starting a filesort over three million rows in that
# window would not look like a missing index; it would look like a dead worker.
ORDER_INDEX = {
    "oldest": "idx_index_meta",
    "newest": "idx_index_meta",
    "seeders": "idx_index_meta_seed",
    "seen": "idx_index_meta_seen",
    "completed": "idx_index_meta_completed",
    "random": None,
    "whitelist": None,
}

# The mix repeats over this many claims, so one percentage point is one claim in a hundred — a share
# can be small, but it can never round down to "never".
ORDER_ROTATION = 100

# What the auto-queue and the daily budget write. Everything above it was asked for by a person, and
# that is the only thing the two lanes are dividing on. Written once here because the panel writes
# the same value from five places and a disagreement would strand rows in a lane nobody reads.
META_PRIORITY_BULK = -1

# Tried in this order for every claim. `any` exists only as a safety net: it is the un-split query,
# so it can see a row with a priority BELOW the bulk value that neither lane would match. It costs
# nothing in normal running because it is only reached when both real lanes came back empty.
CLAIM_LANES = ("priority", "bulk", "any")
# whitelist 0 = keep absolute priority (the behaviour every earlier release had).
ORDER_MIX_DEFAULT = {"whitelist": 0, "oldest": 0, "newest": 15, "seeders": 70,
                     "seen": 0, "completed": 0, "random": 15}


def order_rotation(shares, length=ORDER_ROTATION):
    """Spread `shares` over `length` claims, interleaved rather than blocked.

    70/15/15 laid out as seventy seeders, then fifteen newest, then fifteen random would be correct
    on average and wrong in practice: the worker claims in waves the size of its parallel-fetch
    setting, so a blocked plan makes each wave a single kind and the "balance" only appears over
    hours. Each slot therefore goes to whichever selector is furthest behind its entitlement at that
    point — the same rule proportional seat allocation uses, and it puts the two 15 % selectors at
    even intervals through the 70 % one.

    Ties go to the earliest key in ORDER_MIX_KEYS, so the plan is deterministic: two workers reading
    the same settings build the same rotation.
    """
    live = [n for n in ORDER_MIX_KEYS if int(shares.get(n, 0) or 0) > 0]
    if not live:
        return ["oldest"] * length
    total = sum(int(shares[n]) for n in live)
    assigned = dict.fromkeys(live, 0)
    plan = []
    for i in range(1, length + 1):
        pick = max(live, key=lambda n: (int(shares[n]) * i / total - assigned[n], -ORDER_MIX_KEYS.index(n)))
        assigned[pick] += 1
        plan.append(pick)
    return plan


def order_normalise(mode, shares):
    """Whatever is in the settings table -> (mode, shares) this worker can act on.

    The panel keeps the shares adding up to 100 and refuses a share too small to mean anything; this
    is the second line, for a value edited straight in the database or left behind by an older
    release. An unknown mode is the DEFAULT one, not a crash and not "no ordering at all": a worker
    that stops claiming because a string was misspelt is worse than one that falls back to the order
    it has always used.
    """
    mode = (mode or "").strip().lower()
    if mode not in ORDER_MODES:
        mode = "oldest"
    clean = {}
    for n in ORDER_MIX_KEYS:
        try:
            v = int(shares.get(n, 0) or 0)
        except (TypeError, ValueError):
            v = 0
        clean[n] = max(0, min(100, v))
    if mode == "mix" and sum(clean.values()) <= 0:
        clean = dict(ORDER_MIX_DEFAULT)
    return mode, clean

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
        # 64, not 16. libtorrent holds one handle per fetch and each is a small set of DHT and
        # peer connections, so the ceiling is file descriptors and memory rather than anything
        # in libtorrent. What it costs at the top end: roughly a few hundred sockets and a few
        # hundred MB, plus outbound traffic to match. Raise it because a machine has spare
        # capacity, not because the number is available.
        self.concurrency = max(1, min(CONCURRENCY_MAX, int(w.get("concurrency", 3))))
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
    # A named zone or a numeric offset, and nothing else: this value is interpolated into a SET
    # statement, and it arrives from a settings table the panel writes.
    TZ_RE = re.compile(r"^(?:[+-](?:[01]\d|2[0-3]):[0-5]\d|[A-Za-z][A-Za-z0-9_+-]*(?:/[A-Za-z0-9_+-]+){0,2})$")

    def __init__(self, params):
        self.params = params
        self.conn = None
        self.time_zone = None    # what the panel says its own session uses; None = leave the server default

    def _connect(self):
        if self.conn is None:
            self.conn = pymysql.connect(**self.params)
            self._apply_time_zone(self.conn)
        return self.conn

    def _apply_time_zone(self, conn):
        """Match the panel's session clock, on this connection and on every reconnection.

        Without this the worker runs in the SERVER's zone while the panel runs in PHP's, and on this
        machine those are two hours apart. Everything still "works", which is what makes it nasty:
        `meta_fetched_at` written by the worker reads two hours in the future to the panel, and the
        panel's `meta_requested_at <= NOW()` gate -- the one that spreads an auto-queue over an hour
        -- opens two hours early because the worker compares UTC values against a CEST clock.
        """
        if not self.time_zone:
            return
        try:
            with conn.cursor() as cur:
                cur.execute("SET time_zone = %s", (self.time_zone,))
        except Exception as e:
            log.warning("could not adopt the panel's time zone %r: %s", self.time_zone, e)

    def adopt_time_zone(self, tz):
        """Take the panel's published zone. Returns True when it changed."""
        tz = (tz or "").strip()
        if tz and not self.TZ_RE.match(tz):
            log.warning("db_time_zone %r does not look like a time zone -- ignoring it", tz[:40])
            return False
        if tz == (self.time_zone or ""):
            return False
        self.time_zone = tz or None
        if self.conn is not None:
            self._apply_time_zone(self.conn)
        log.info("database session clock: %s (published by the panel)", tz or "server default")
        return True

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
        self._conc_override = None   # live override from the settings table (panel), None = use config
        self._conc_checked_at = 0.0
        self._order_mode = "oldest"  # live from the settings table, same 60 s refresh
        self._order_shares = dict(ORDER_MIX_DEFAULT)
        self._order_plan = ["oldest"] * ORDER_ROTATION
        self._order_checked_at = 0.0
        self._order_seq = 0          # which slot of the rotation the next claim takes
        self._order_indexes = None   # which selectors the database can actually serve
        self._order_indexes_at = 0.0
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
            # Only the index queue is orderable. The whitelist keeps "admin priority, then longest
            # waiting" whatever the setting says: those rows are there because a person asked for
            # them by name, and a share-based rotation would answer a direct request with a dice roll.
            self.queues.append({"table": cfg.index_table, "files": cfg.index_files_table, "key_col": "info_hash",
                                "files_fk": "info_hash", "select": "info_hash", "gate": " AND meta_requested_at <= NOW()",
                                "orderable": True})
        log.info("session started (libtorrent %s), listen %s, concurrency %d, timeout %ds, queues %s",
                 lt.__version__, cfg.listen_port, cfg.concurrency, cfg.timeout, ",".join(q["table"] for q in self.queues))

    def effective_concurrency(self):
        """Config concurrency, unless the panel setting `meta_worker_concurrency` overrides it.

        A number OUTSIDE the range this build supports is CLAMPED, not discarded. That distinction
        cost a real diagnosis: the panel's ceiling was raised from 16 to 64, the file on disk was
        updated, and the long-running process was never restarted -- so it still enforced 1..16, saw
        the admin's 32, decided it was garbage, and fell back to the CONFIG value of 4. The admin
        asked for more parallelism and silently got less than before. Clamping turns that into
        "you asked for 32, this build tops out at 16, so 16", which is the honest reading of an
        out-of-range number and degrades in the right direction.

        A read error still falls back to the config value -- an unreachable settings table says
        nothing about what the operator wants -- but it is logged as an error rather than as a
        deliberate "no override".

        Re-read at most every 60 s.
        """
        now = time.time()
        if now - self._conc_checked_at >= 60:
            self._conc_checked_at = now
            val, why = None, "no row"
            try:
                rows = self.db.query("SELECT `value` FROM settings WHERE `key`='meta_worker_concurrency'", fetch=True)
                if rows:
                    raw = str(rows[0].get("value") or "").strip()
                    if raw == "":
                        why = "empty (use the config file)"
                    elif raw.isdigit():
                        asked = int(raw)
                        val = max(1, min(CONCURRENCY_MAX, asked))
                        why = ("as asked" if val == asked
                               else "asked %d, this build tops out at %d" % (asked, CONCURRENCY_MAX))
                    else:
                        why = "not a number: %r" % raw[:20]
            except Exception as e:
                val, why = None, "settings unreadable (%s)" % e
            if val != self._conc_override:
                log.info("concurrency override from settings: %s (%s; config %d)",
                         val if val is not None else "none", why, self.cfg.concurrency)
                self._conc_override = val
        return self._conc_override or self.cfg.concurrency

    # ── queue ──────────────────────────────────────────────────────────────
    def usable_selectors(self):
        """Which orderings this database can serve right now, checked rather than assumed.

        The two v31 indexes are built by a heavy ALTER the janitor runs out of band, so there is a
        window -- minutes on a table this size -- where the setting exists and the index does not.
        Running `ORDER BY seen_count DESC` in that window is not a slower setting, it is a filesort
        over three million rows several times a second. So the worker asks, and falls back.

        A failed check claims everything is usable: an unreadable information_schema says nothing
        about the indexes, and disabling every selector over a permissions error would be worse than
        the thing being guarded against.
        """
        now = time.time()
        if self._order_indexes is not None and now - self._order_indexes_at < 600:
            return self._order_indexes
        self._order_indexes_at = now
        have = set()
        ok = True
        if self.cfg.index_table:
            try:
                rows = self.db.query(
                    "SELECT DISTINCT INDEX_NAME n FROM information_schema.STATISTICS "
                    "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", (self.cfg.index_table,),
                    fetch=True)
                have = {str(r["n"]) for r in (rows or [])}
            except Exception as e:
                log.warning("usable_selectors: %s", e)
                ok = False
        usable = {}
        for sel, idx in ORDER_INDEX.items():
            usable[sel] = True if (not ok or idx is None or not self.cfg.index_table) else (idx in have)
        self._order_indexes = usable
        return usable

    def effective_order(self):
        """(mode, shares, plan) from the settings table, re-read at most every 60 s.

        Read the same way as the parallel-fetch override and for the same reason: an operator who
        changes the order in the panel expects the next fetch to obey it, not the next restart.
        A settings table that cannot be read leaves the last known value in place -- a database blip
        is not an instruction to reorder a three-million-row queue.
        """
        now = time.time()
        if now - self._order_checked_at >= 60:
            self._order_checked_at = now
            try:
                keys = ",".join("'meta_order_mix_" + n + "'" for n in ORDER_MIX_KEYS)
                rows = self.db.query(
                    "SELECT `key`, `value` FROM settings WHERE `key` IN "
                    "('meta_order_mode','db_time_zone'," + keys + ")", fetch=True)
                got = {str(r["key"]): str(r["value"] or "") for r in (rows or [])}
                # Read here rather than in its own query: this is the one place that already asks the
                # settings table on a timer, and the clock is exactly as live a setting as the order.
                self.db.adopt_time_zone(got.get("db_time_zone", ""))
                mode, shares = order_normalise(
                    got.get("meta_order_mode", ""),
                    {n: got.get("meta_order_mix_" + n, "") for n in ORDER_MIX_KEYS})

                # An ordering whose index is missing becomes queue order rather than a filesort.
                usable = self.usable_selectors()
                if not usable.get(mode, True):
                    log.warning("fetch order %r needs index %s, which does not exist yet -- "
                                "using queue order until it does", mode, ORDER_INDEX.get(mode))
                    mode = "oldest"
                for n in list(shares):
                    if shares[n] and not usable.get(n, True):
                        log.warning("mix share %r (%d%%) needs index %s, which does not exist yet -- "
                                    "folding it into queue order", n, shares[n], ORDER_INDEX.get(n))
                        shares["oldest"] = shares.get("oldest", 0) + shares[n]
                        shares[n] = 0

                if mode != self._order_mode or shares != self._order_shares:
                    log.info("fetch order: %s%s", mode,
                             "" if mode != "mix" else " (" + ", ".join(
                                 "%s %d%%" % (n, shares[n]) for n in ORDER_MIX_KEYS if shares[n]) + ")")
                    self._order_mode, self._order_shares = mode, shares
                    self._order_plan = (order_rotation(shares) if mode == "mix"
                                        else [mode] * ORDER_ROTATION)
                    self._order_seq = 0
            except Exception as e:
                log.warning("effective_order: %s", e)
        return self._order_mode, self._order_shares, self._order_plan

    def claim_targets(self):
        """The queues to try for THIS claim, in order, as (queue, selector) pairs.

        Two rules, and the second one is the whole reason this is a list rather than a choice:

          * Outside the mix -- and inside it whenever the whitelist share is 0 -- the whitelist
            drains FIRST, absolutely. That is what every release before this one did, and those rows
            are there because a person asked for them by name.
          * With a whitelist share, the rotation decides which queue a slot belongs to. A slot whose
            queue turns out to be empty is not wasted: it falls through to the other one. So the
            share is a floor for the whitelist and never a ceiling on total throughput.
        """
        mode, shares, plan = self.effective_order()
        slot = plan[self._order_seq % len(plan)]
        self._order_seq += 1
        wl = [q for q in self.queues if not q.get("orderable")]
        idx = [q for q in self.queues if q.get("orderable")]
        if slot == "whitelist":
            return [(q, "oldest") for q in wl] + [(q, "oldest") for q in idx]
        if mode == "mix" and int(shares.get("whitelist", 0) or 0) > 0:
            return [(q, slot) for q in idx] + [(q, "oldest") for q in wl]
        return [(q, "oldest") for q in wl] + [(q, slot) for q in idx]

    def claim_query(self, q, selector, lane="bulk"):
        """(sql, params) picking ONE candidate row for this queue, selector and lane.

        See the table at the top of this file for why the lane matters more than the selector does.
        """
        gate = q["gate"]
        if lane == "priority":
            # Everything a person asked for, newest priority first, then longest waiting. Small
            # enough that the filesort here is free -- 43 k rows against three million.
            return ("SELECT %s FROM %s WHERE meta_status='pending'%s AND meta_priority > %d "
                    "ORDER BY meta_priority DESC, meta_requested_at ASC LIMIT 1"
                    % (q["select"], q["table"], gate, META_PRIORITY_BULK), ())

        if lane == "any":
            # The safety net: no priority predicate at all, so a row with an unexpected priority is
            # still reachable. Slow, and only ever reached when both lanes above are empty.
            return ("SELECT %s FROM %s WHERE meta_status='pending'%s "
                    "ORDER BY meta_priority DESC, meta_requested_at ASC LIMIT 1"
                    % (q["select"], q["table"], gate), ())

        # lane == "bulk": meta_priority is pinned to one value, which is what lets the next column of
        # each index provide the ordering with no sort at all.
        bulk = " AND meta_priority = %d" % META_PRIORITY_BULK
        if not q.get("orderable") or selector == "oldest":
            return ("SELECT %s FROM %s WHERE meta_status='pending'%s%s "
                    "ORDER BY meta_requested_at ASC LIMIT 1"
                    % (q["select"], q["table"], gate, bulk), ())
        if selector == "newest":
            return ("SELECT %s FROM %s WHERE meta_status='pending'%s%s "
                    "ORDER BY meta_requested_at DESC LIMIT 1"
                    % (q["select"], q["table"], gate, bulk), ())
        if selector in ("seeders", "seen", "completed"):
            col = {"seeders": "last_seeders", "seen": "seen_count", "completed": "last_completed"}[selector]
            return ("SELECT %s FROM %s WHERE meta_status='pending'%s%s ORDER BY %s DESC LIMIT 1"
                    % (q["select"], q["table"], gate, bulk, col), ())
        # random: a uniform point in the key space, then the first pending row at or after it.
        # When the point lands past the last pending row the caller wraps to the start of the
        # queue; without that wrap the tail of the key space would simply never be claimed.
        return ("SELECT %s FROM %s WHERE meta_status='pending'%s%s AND info_hash >= %%s "
                "ORDER BY info_hash LIMIT 1" % (q["select"], q["table"], gate, bulk),
                (secrets.token_hex(20),))

    def heartbeat(self):
        """Touch the file the panel watches -- and write what this worker is actually doing.

        It used to be an empty file whose mtime was the whole message, so the panel could say "the
        worker is alive" and nothing else. That is exactly how a worker can sit at 4 parallel fetches
        for a day while the panel shows the 32 somebody typed: the panel had no way to ask, so it
        reported the setting back to the operator and called it status.

        One JSON line, rewritten each tick. Anything that cannot be written is not worth failing a
        fetch over, so errors are logged and swallowed as before."""
        try:
            payload = {
                "at": int(time.time()),
                "pid": os.getpid(),
                "version": WORKER_VERSION,
                "concurrency": self.effective_concurrency(),
                "concurrency_config": self.cfg.concurrency,
                "concurrency_max": CONCURRENCY_MAX,
                "active": len(self.active),
                # Reported for the same reason the effective concurrency is: the panel must be able
                # to show what the worker is DOING, not read a setting back to the operator.
                "order": self._order_mode,
                "order_shares": dict(self._order_shares),
            }
            tmp = self.cfg.heartbeat + ".tmp"
            with open(tmp, "w", encoding="utf-8") as fh:
                fh.write(json.dumps(payload))
            os.replace(tmp, self.cfg.heartbeat)
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
        """Claim one pending row, or None.

        Three nested choices, outermost first: which QUEUE (whitelist or index, decided by the
        rotation), which LANE (asked-for, then bulk, then the safety net), and then up to three
        attempts inside a lane in case another worker takes the candidate first.

        Two-step within a lane: a plain SELECT picks the candidate (consistent read, NO row locks --
        the old single UPDATE ... ORDER BY ... LIMIT 1 filesorted AND X-locked the whole pending set),
        then the UPDATE claims exactly that row by primary key with a status recheck.
        """
        for q, selector in self.claim_targets():
            for lane in CLAIM_LANES:
                row = self.claim_from(q, selector, lane)
                if row is not None:
                    return row
        return None

    def claim_from(self, q, selector, lane):
        """One (queue, selector, lane) combination. None means "nothing here, try the next"."""
        for _attempt in range(3):
            token = secrets.token_hex(8)
            try:
                sql, params = self.claim_query(q, selector, lane)
                # `or None`: pymysql runs `sql % args` for anything that is not None, so an empty
                # tuple would make a literal % in a future ORDER BY a runtime error.
                rows = self.db.query(sql, params or None, fetch=True)
                if not rows and selector == "random" and lane == "bulk":
                    # the random point landed past the last pending hash -- wrap to the start
                    sql, params = self.claim_query(q, "oldest", lane)
                    rows = self.db.query(sql, params or None, fetch=True)
                if not rows:
                    return None
                row = rows[0]
                n = self.db.query(
                    "UPDATE %s SET meta_status='fetching', meta_claim=%%s, meta_claimed_at=NOW() "
                    "WHERE %s=%%s AND meta_status='pending'" % (q["table"], q["key_col"]),
                    (token, row[q["key_col"]]))
                if not n:
                    continue                       # raced -- pick a fresh candidate
            except Exception as e:
                log.warning("claim(%s/%s): %s", q["table"], lane, e)
                return None
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
                while len(self.active) < self.effective_concurrency():
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
