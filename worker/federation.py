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
MAX_LINE_BYTES = 1 * 1024 * 1024            # one NDJSON row; a peer sending more is defeating streaming
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
    # `mf` is the peer's meta_fetched_at — the cursor field. It is what tells the importer where a
    # batch got to, so it has to survive validation; without it a committed batch could not say what
    # it had covered, and the next run would fetch the same rows for ever.
    mf = nn_int(r.get("mf"), 0, 2**31 - 1) or 0
    return {"h": h, "name": name, "size": size, "fc": fc, "pl": pl,
            "seeders": seeders, "leechers": leechers,
            "first": first, "last": last, "count": count, "files": files, "mf": mf}


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


# ── streaming import ────────────────────────────────────────────────────────────

def stream_page(peer, since, after, limit, want_files):
    """
    One export page as NDJSON, yielded a row at a time.

    The buffered path reads the peer's whole reply into memory, json.loads() it, and only then has
    anything to merge — for a page carrying half a million file records that is hundreds of
    megabytes before a single row is stored. Here the response is read LINE BY LINE off the gzip
    stream: the largest thing held is one line.

    Yields ("head", dict) once, then ("row", dict) per line, then ("end", dict) for the trailer.
    Raises on transport errors, on a line longer than MAX_LINE_BYTES (a peer that sends one
    enormous line is defeating the point of streaming), or past MAX_EXPANDED_BYTES in total.
    """
    url = peer["base_url"].rstrip("/") + "/api.php?endpoint=v1/federation/export"
    body = json.dumps({"since": since, "after": after, "limit": limit,
                       "files": bool(want_files), "gzip": True, "format": "ndjson"}).encode()
    req = urllib.request.Request(url, data=body, method="POST", headers={
        "Authorization": "Bearer " + peer["bearer"],
        "Content-Type": "application/json",
        "Accept": "application/x-ndjson, application/json",
        "User-Agent": "tryhackx-tracker/1.6 federation.py",
    })
    with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT) as res:
        ctype = (res.headers.get("Content-Type") or "").lower()
        if "ndjson" not in ctype:
            # An older peer that does not know the format. Not an error — fall back rather than
            # refuse to federate with someone who has not upgraded yet.
            raise NdjsonUnsupported(ctype or "no content-type")
        stream = res
        if (res.headers.get("Content-Encoding") or "").lower() == "gzip":
            stream = gzip.GzipFile(fileobj=res)
        for item in parse_ndjson(stream):
            yield item


def parse_ndjson(stream):
    """
    The line-by-line half of stream_page, split out so it can be tested without a network: give it
    anything iterable by lines and it yields ("head"|"row"|"end", dict).

    Everything it refuses to do is a defence against a peer that is compromised rather than merely
    old: a line bigger than MAX_LINE_BYTES (streaming defeated), a total past MAX_EXPANDED_BYTES
    (a gzip bomb), a line that is not JSON, and — the one that matters most — a stream that stops
    without its trailer. A truncated transfer must NOT look like the end of the data, or the cursor
    would step over everything that never arrived.
    """
    total = 0
    first = True
    trailer = None
    for raw in stream:
        if isinstance(raw, str):
            raw = raw.encode("utf-8", "replace")
        if len(raw) > MAX_LINE_BYTES:
            raise RuntimeError("peer sent a line of %d bytes (max %d)" % (len(raw), MAX_LINE_BYTES))
        total += len(raw)
        if total > MAX_EXPANDED_BYTES:
            raise RuntimeError("peer response exceeds %d bytes" % MAX_EXPANDED_BYTES)
        line = raw.strip()
        if not line:
            continue
        try:
            obj = json.loads(line.decode("utf-8", "replace"))
        except ValueError:
            raise RuntimeError("peer sent a line that is not JSON")
        if not isinstance(obj, dict):
            continue
        if first:
            first = False
            if not obj.get("ok"):
                raise RuntimeError("peer error: %s" % obj.get("error", "unknown"))
            yield ("head", obj)
            continue
        if obj.get("end"):
            trailer = obj
            continue
        yield ("row", obj)
    if first:
        raise RuntimeError("peer sent an empty response")
    if trailer is None:
        raise RuntimeError("peer response ended without a trailer — transfer truncated")
    yield ("end", trailer)


class NdjsonUnsupported(Exception):
    """The peer answered with something other than NDJSON — its export predates the format."""


def merge_batch(db, rows, peer_name, cfg, counters, peer_id=None, cursor=None):
    """
    Merge one micro-batch in ONE transaction, with four bulk statements instead of the two-to-four
    queries PER ROW the previous version issued. On a full 2.17-million-row exchange that is the
    difference between roughly 4 300 round trips and 6.5 million.

    The cursor moves inside the same transaction, so an interrupted run costs exactly one batch:
    whatever was committed was also recorded as fetched, and whatever was not is fetched again.

    `counters` is a dict updated in place: filled / inserted / skipped / files.
    """
    if not rows:
        return
    import pymysql
    import_new = str(cfg.get("fed_import_new", "0")) == "1"
    keep_files = str(cfg.get("index_keep_files", "1")) == "1"
    grace_days = setting_int(cfg, "index_grace_days", 3, 1, 90)
    max_rows = setting_int(cfg, "index_max_rows", 200000, 10, 5000000)
    source = ("fed:" + peer_name)[:24]
    hashes = [r["h"] for r in rows]
    marks = ",".join(["%s"] * len(hashes))

    conn = db._connect()
    conn.begin()
    try:
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            # 1. what we already know about these hashes
            cur.execute("SELECT info_hash, meta_status FROM index_hashes WHERE info_hash IN (%s)" % marks, hashes)
            known = {r["info_hash"]: r["meta_status"] for r in cur.fetchall()}

            # 2. what is off limits — whitelisted or banned, on either side
            cur.execute(
                "SELECT info_hash FROM whitelist WHERE info_hash IN (%s)"
                " UNION SELECT info_hash FROM banned_hashes WHERE info_hash IN (%s)" % (marks, marks),
                hashes + hashes)
            protected = {r["info_hash"] for r in cur.fetchall()}

            room = None
            if import_new:
                cur.execute("SELECT COUNT(*) AS c FROM index_hashes")
                room = max(0, max_rows - int(cur.fetchone()["c"]))

            fill, add = [], []
            for r in rows:
                h = r["h"]
                if h in protected:
                    counters["skipped"] += 1
                    continue
                if h in known:
                    if known[h] in ("done", "fetching"):
                        counters["skipped"] += 1     # resolved locally — ours wins, always
                        continue
                    fill.append(r)
                elif import_new and room:
                    add.append(r)
                    room -= 1
                else:
                    counters["skipped"] += 1

            todo = fill + add
            if todo:
                # One statement for both cases. The ON DUPLICATE guard repeats the policy check
                # because a row can turn 'done' between the SELECT above and this INSERT — the local
                # worker is running at the same time and its result must win.
                #
                # ORDER MATTERS: MariaDB evaluates the assignments left to right, so meta_status is
                # written LAST. Put it first and every IF() below would read the value this very
                # statement just set, and the guard would protect nothing.
                cols = ("info_hash, name, first_seen, last_seen, seen_count, last_seeders, last_leechers,"
                        " grace_until, meta_status, meta_fetched_at, meta_source, total_size, files_count, piece_length")
                tpl = ("(%s, %s, COALESCE(%s, NOW()), COALESCE(%s, NOW()), %s, %s, %s,"
                       " NOW() + INTERVAL %s DAY, 'done', NOW(), %s, %s, %s, %s)")
                args = []
                for r in todo:
                    args += [r["h"], r["name"], r["first"], r["last"], r["count"], r["seeders"],
                             r["leechers"], grace_days, source, r["size"], r["fc"], r["pl"]]
                sql = ("INSERT INTO index_hashes (" + cols + ") VALUES " + ",".join([tpl] * len(todo)) +
                       " ON DUPLICATE KEY UPDATE"
                       "  name = IF(meta_status IN ('done','fetching'), name, VALUES(name)),"
                       "  total_size = IF(meta_status IN ('done','fetching'), total_size, VALUES(total_size)),"
                       "  files_count = IF(meta_status IN ('done','fetching'), files_count, VALUES(files_count)),"
                       "  piece_length = IF(meta_status IN ('done','fetching'), piece_length, VALUES(piece_length)),"
                       "  meta_source = IF(meta_status IN ('done','fetching'), meta_source, VALUES(meta_source)),"
                       "  meta_fetched_at = IF(meta_status IN ('done','fetching'), meta_fetched_at, NOW()),"
                       "  meta_error = IF(meta_status IN ('done','fetching'), meta_error, NULL),"
                       "  meta_claim = IF(meta_status IN ('done','fetching'), meta_claim, NULL),"
                       "  meta_status = IF(meta_status IN ('done','fetching'), meta_status, 'done')")
                cur.execute(sql, args)
                counters["filled"] += len(fill)
                counters["inserted"] += len(add)

                # 4. file lists, in bulk. Replaced wholesale per hash: a partial list is worse than
                # none, because search would answer from half a torrent.
                if keep_files:
                    with_files = [r for r in todo if r["files"]]
                    if with_files:
                        fh = [r["h"] for r in with_files]
                        cur.execute("DELETE FROM index_files WHERE info_hash IN (%s)" % ",".join(["%s"] * len(fh)), fh)
                        flat = [(r["h"], p, s) for r in with_files for p, s in r["files"]]
                        if flat:
                            cur.executemany("INSERT INTO index_files (info_hash, path, size) VALUES (%s, %s, %s)", flat)
                            counters["files"] += len(flat)

            # 5. the cursor, in the SAME transaction as the rows it describes
            if peer_id is not None and cursor is not None:
                cur.execute("UPDATE fed_peers SET last_pull_cursor=%s WHERE id=%s", (cursor, peer_id))
        conn.commit()
    except Exception:
        try:
            conn.rollback()
        except Exception:
            pass
        raise

# ── merge (buffered fallback, kept for --self-test) ────────────────────────────

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


def sync_peer(db, peer, cfg, deadline=None):
    """
    Pull from one peer until it runs out of pages, the run's time budget expires, or the page budget
    is reached. Never raises: a peer that is down must not stop the others.

    Rows are accumulated into MICRO-BATCHES and committed with the cursor, so an interruption — a
    kill, a reboot, a network drop — costs at most one batch. There is no state to repair
    afterwards, because there is no state between batches.
    """
    name = peer["name"]
    since, after = parse_cursor(peer.get("last_pull_cursor"))
    want_files = int(peer.get("pull_files") or 0) == 1 and str(cfg.get("index_keep_files", "1")) == "1"
    batch = setting_int(cfg, "fed_export_max_batch", 2000, 100, 20000)
    batch_rows = setting_int(cfg, "fed_import_batch_rows", 500, 25, 5000)
    batch_bytes = setting_int(cfg, "fed_import_batch_bytes", 33554432, 1048576, 268435456)
    counters = {"filled": 0, "inserted": 0, "skipped": 0, "files": 0}
    pages = 0
    status = "ok"
    streamed = True
    try:
        for _page in range(MAX_PAGES_PER_RUN):
            if deadline and time.monotonic() > deadline:
                status = "ok (time budget reached — continues next run)"
                break
            pending, pending_bytes = [], 0
            trailer = None
            try:
                for kind, obj in stream_page(peer, since, after, batch, want_files):
                    if kind == "head":
                        continue
                    if kind == "end":
                        trailer = obj
                        break
                    row = valid_row(obj)
                    if row is None:
                        counters["skipped"] += 1
                        continue
                    pending.append(row)
                    # A rough size, deliberately: the point is a ceiling on what is held, not an
                    # accurate byte count. File lists are what actually grow a batch.
                    pending_bytes += 200 + 80 * len(row["files"])
                    if len(pending) >= batch_rows or pending_bytes >= batch_bytes:
                        # The cursor for what has been merged so far — the LAST row in this batch.
                        cur_s, cur_a = int(pending[-1].get("mf") or since), pending[-1]["h"]
                        merge_batch(db, pending, name, cfg, counters, peer["id"], "%d:%s" % (cur_s, cur_a))
                        since, after = cur_s, cur_a
                        pending, pending_bytes = [], 0
                        if deadline and time.monotonic() > deadline:
                            break
            except NdjsonUnsupported as e:
                # Older peer: use the buffered endpoint for this one. Same merge path, so the only
                # thing lost is the flat memory profile on OUR side.
                log.info("[%s] peer has no NDJSON export (%s) — falling back to whole-page JSON", name, e)
                streamed = False
                data = fetch_page(peer, since, after, batch, want_files)
                rows = [v for v in (valid_row(r) for r in (data.get("rows") or [])) if v is not None]
                for i in range(0, len(rows), batch_rows):
                    chunk = rows[i:i + batch_rows]
                    cur_s, cur_a = int(chunk[-1].get("mf") or since), chunk[-1]["h"]
                    merge_batch(db, chunk, name, cfg, counters, peer["id"], "%d:%s" % (cur_s, cur_a))
                    since, after = cur_s, cur_a
                trailer = {"has_more": bool(data.get("has_more")), "next": data.get("next")}

            if pending:
                cur_s, cur_a = int(pending[-1].get("mf") or since), pending[-1]["h"]
                merge_batch(db, pending, name, cfg, counters, peer["id"], "%d:%s" % (cur_s, cur_a))
                since, after = cur_s, cur_a
            pages += 1

            nxt = (trailer or {}).get("next")
            if isinstance(nxt, dict) and HASH_RE.match(str(nxt.get("after", ""))):
                since, after = int(nxt.get("since", since)), str(nxt["after"])
            if not (trailer or {}).get("has_more"):
                break
        else:
            status = "ok (page budget hit — continues next run)"
    except urllib.error.HTTPError as e:
        # 429 is the peer asking us to slow down, not a fault. Say which it was.
        status = ("rate limited by peer (retry after %ss)" % (e.headers.get("Retry-After") or "?")) if e.code == 429 \
                 else "HTTP %d from peer" % e.code
    except Exception as e:
        status = ("error: %s" % e)[:255]

    summary = "%s: +%d filled, +%d new, %d skipped, %d files, %d page(s)%s" % (
        status, counters["filled"], counters["inserted"], counters["skipped"],
        counters["files"], pages, "" if streamed else " [buffered]")
    log.info("[%s] %s", name, summary)
    db.query(
        "UPDATE fed_peers SET last_pull_at=NOW(), last_pull_cursor=%s, last_status=%s,"
        " rows_imported=rows_imported+%s WHERE id=%s",
        ("%d:%s" % (since, after), summary[:255], counters["filled"] + counters["inserted"], peer["id"]))
    return counters["filled"] + counters["inserted"]


def run_once(db, only_peer=None, force=False, max_seconds=None):
    cfg = load_settings(db)
    if str(cfg.get("fed_enabled", "0")) != "1" and not force:
        log.info("federation disabled (fed_enabled=0) — nothing to do")
        return 0
    # A run has a time budget so a timer that fires every minute cannot stack copies of itself on a
    # slow peer. Whatever is left is simply picked up next time — the cursor makes that free.
    if max_seconds is None:
        max_seconds = setting_int(cfg, "fed_import_max_seconds", 600, 30, 21600)
    deadline = time.monotonic() + max_seconds
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
        total += sync_peer(db, p, cfg, deadline)
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
    t("valid_row keeps the cursor field", (valid_row({"h": "a"*40, "n": "x", "mf": 1700000000}) or {}).get("mf") == 1700000000)
    t("valid_row: a missing cursor field becomes 0, never absent",
      "mf" in (valid_row({"h": "a"*40, "n": "x"}) or {}) and (valid_row({"h": "a"*40, "n": "x"}) or {})["mf"] == 0)
    t("valid_row: a rubbish cursor field is not trusted", (valid_row({"h": "a"*40, "mf": "tomorrow"}) or {}).get("mf") == 0)
    t("cursor parses", parse_cursor("123:" + "a" * 40) == (123, "a" * 40) and parse_cursor("") == (0, "") and parse_cursor("junk") == (0, ""))
    # ── streaming parser ────────────────────────────────────────────────────
    # Everything below is about what a peer can do to us. It is the half of federation that has to
    # assume the other side is compromised rather than merely old.
    def ndj(*lines):
        return [(l + "\n").encode() for l in lines]

    head = '{"ok":true,"node":"p","format":"ndjson"}'
    row1 = '{"h":"' + ("a" * 40) + '","n":"one","s":1,"fc":1,"pl":16384,"sl":[1,2],"seen":{},"mf":100}'
    row2 = '{"h":"' + ("b" * 40) + '","n":"two","s":2,"fc":1,"pl":16384,"sl":[1,2],"seen":{},"mf":101}'
    end = '{"end":true,"rows":2,"has_more":false,"next":null}'

    got = list(parse_ndjson(ndj(head, row1, row2, end)))
    t("ndjson: head, rows and trailer come back in order",
      [k for k, _ in got] == ["head", "row", "row", "end"])
    t("ndjson: the rows are the ones sent", got[1][1]["h"] == "a" * 40 and got[2][1]["h"] == "b" * 40)
    t("ndjson: the trailer carries the cursor state", got[3][1].get("has_more") is False)

    # A cut-off transfer must NOT look like the end of the data — if it did, the cursor would step
    # over every row that never arrived, and they would never be fetched again.
    try:
        list(parse_ndjson(ndj(head, row1, row2)))
        t("ndjson: a missing trailer is an error", False)
    except RuntimeError as e:
        t("ndjson: a missing trailer is an error", "truncated" in str(e))

    try:
        list(parse_ndjson(ndj('{"ok":false,"error":"export_disabled"}')))
        t("ndjson: a refusing peer raises", False)
    except RuntimeError as e:
        t("ndjson: a refusing peer raises", "export_disabled" in str(e))

    try:
        list(parse_ndjson(ndj(head, "this is not json", end)))
        t("ndjson: a non-JSON line is an error", False)
    except RuntimeError as e:
        t("ndjson: a non-JSON line is an error", "not JSON" in str(e))

    try:
        list(parse_ndjson([(head + "\n").encode(), b"x" * (MAX_LINE_BYTES + 10)]))
        t("ndjson: one enormous line is refused", False)
    except RuntimeError as e:
        t("ndjson: one enormous line is refused", "line of" in str(e))

    try:
        list(parse_ndjson([]))
        t("ndjson: an empty response is an error", False)
    except RuntimeError as e:
        t("ndjson: an empty response is an error", "empty" in str(e))

    t("ndjson: blank lines are ignored",
      [k for k, _ in parse_ndjson(ndj(head, "", row1, "", end))] == ["head", "row", "end"])
    t("ndjson: a non-object line is skipped rather than fatal",
      [k for k, _ in parse_ndjson(ndj(head, "[1,2,3]", row1, end))] == ["head", "row", "end"])

    # ── the memory ceiling ──────────────────────────────────────────────────
    # The one guard that does not depend on getting arithmetic right elsewhere.
    t("memory cap: 0 means no cap", cap_memory(0) is None)
    if sys.platform != "win32":
        capped = cap_memory(4096)
        t("memory cap: a sane value is accepted", capped is None or capped >= 64 * 1024 * 1024)
    else:
        print("SKIP memory cap on POSIX  -> RLIMIT_AS does not exist on Windows")
    print("self-test: " + ("OK" if ok else "FAILED"))
    return 0 if ok else 1


def cap_memory(mb):
    """
    A hard ceiling on this process's address space.

    Every other guard here is a promise: batch sizes, line limits, decompression bounds. This is the
    one that does not depend on getting the arithmetic right — if anything slips past them the
    process dies with MemoryError instead of taking the machine's RAM with it, and the timer starts
    it again from the last committed batch. On a box that also runs mail, a forum and a tracker,
    that difference matters.

    POSIX only, and best effort: a platform without RLIMIT_AS simply keeps the softer guards.
    """
    if mb <= 0:
        return None
    try:
        import resource
    except ImportError:
        return None
    try:
        soft, hard = resource.getrlimit(resource.RLIMIT_AS)
        want = mb * 1024 * 1024
        if hard != resource.RLIM_INFINITY and want > hard:
            want = hard
        resource.setrlimit(resource.RLIMIT_AS, (want, hard))
        return want
    except (ValueError, OSError):
        return None


def main():
    ap = argparse.ArgumentParser(description="Federation metadata importer")
    ap.add_argument("conf", nargs="?", help="path to tracker-metadata.conf (its [db] section is used)")
    ap.add_argument("--loop", action="store_true", help="run forever, sleeping fed_pull_minutes between passes")
    ap.add_argument("--peer", help="sync only the peer with this name")
    ap.add_argument("--force", action="store_true", help="ignore fed_enabled / pull_enabled flags")
    ap.add_argument("--self-test", action="store_true", help="run offline validation tests and exit")
    ap.add_argument("--max-seconds", type=int, default=None,
                    help="time budget for one pass (default: the fed_import_max_seconds setting)")
    ap.add_argument("--mem-mb", type=int, default=None,
                    help="hard address-space ceiling in MB (default: the fed_worker_mem_mb setting, 256)")
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
    # Read the ceiling from the panel before clamping, so an operator can raise it without editing
    # a unit file — and clamp before any real work starts.
    mem_mb = args.mem_mb
    if mem_mb is None:
        try:
            mem_mb = setting_int(load_settings(db), "fed_worker_mem_mb", 256, 64, 4096)
        except Exception:
            mem_mb = 256
    capped = cap_memory(mem_mb)
    if capped:
        log.info("address space capped at %d MB", capped // (1024 * 1024))

    if not args.loop:
        run_once(db, args.peer, args.force, args.max_seconds)
        return
    while True:
        try:
            run_once(db, args.peer, args.force, args.max_seconds)
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
