"""
End-to-end exercise of the importer's merge paths against the local test database:
    python tests/federation_worker_test.py

Goes through merge_batch itself rather than over HTTP: the wire format is already covered by the
export tests and by --self-test, and what is new here is what the importer DOES with a page — which
of fill / quarantine / recognise-and-skip it picks, and what it writes.

Leaves the database as it found it.
"""
import io, os, sys, time

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(HERE, "worker"))
import federation as F   # noqa: E402

fails = 0
n = 0


def check(name, ok, info=""):
    global fails, n
    n += 1
    print(("PASS " if ok else "FAIL ") + name + ("" if ok or not info else "  -> " + str(info)))
    if not ok:
        fails += 1


db = F.Db(dict(host="127.0.0.1", port=3307, user="root", password="", database="tracker_test",
               charset="utf8mb4", autocommit=True, connect_timeout=10))


def q(sql, args=None, fetch=True):
    return db.query(sql, args, fetch=fetch)


def hs(i):
    return ("%040x" % (i * 0x9E3779B1 + 7))[-40:]


def row(i, mo=None, name=None):
    now = int(time.time())
    return {"h": hs(i), "name": name or ("package %d" % i), "size": 1000 + i, "fc": 2, "pl": 16384,
            "seeders": 3, "leechers": 1, "first": None, "last": None, "count": 1,
            "files": [("dir/a%d.bin" % i, 600), ("dir/b%d.bin" % i, 400 + i)],
            "mf": now - 60, "mo": mo if mo is not None else (now - 7 * 86400)}


def clean():
    q("DELETE FROM fed_review", fetch=False)
    q("DELETE FROM index_files WHERE info_hash LIKE %s", ("%",), fetch=False)
    q("TRUNCATE TABLE index_hashes", fetch=False)


def seen(i):
    """The hash is in the index but unresolved -- the ordinary 'fill' case."""
    q("INSERT INTO index_hashes (info_hash, first_seen, last_seen, seen_count, meta_status)"
      " VALUES (%s, NOW(), NOW(), 4, 'none')", (hs(i),), fetch=False)


BASE = {"fed_import_new": "0", "index_keep_files": "1", "index_grace_days": "3",
        "index_max_rows": "50000", "fed_import_mode": "fill"}

clean()

# ── 1. fill mode still fills, and now records where the description came from ────────────────
seen(1)
c = {"filled": 0, "inserted": 0, "skipped": 0, "queued": 0, "files": 0}
F.merge_batch(db, [row(1)], "alpha", BASE, c)
r = q("SELECT meta_status, name, meta_source, UNIX_TIMESTAMP(meta_origin_at) mo,"
      " UNIX_TIMESTAMP(meta_fetched_at) mf FROM index_hashes WHERE info_hash=%s", (hs(1),))[0]
check("fill: the row is resolved from the peer", c["filled"] == 1 and r["meta_status"] == "done", r)
check("fill: tagged with the peer it came from", r["meta_source"] == "fed:alpha", r["meta_source"])
check("fill: the ORIGIN time is the peer's, not the moment it arrived",
      abs(int(r["mo"]) - row(1)["mo"]) <= 1, "%s vs %s" % (r["mo"], row(1)["mo"]))
check("fill: the ARRIVAL time is now, so the export cursor still moves forward",
      abs(int(r["mf"]) - int(time.time())) < 120, r["mf"])
check("fill: the file list came with it",
      q("SELECT COUNT(*) c FROM index_files WHERE info_hash=%s", (hs(1),))[0]["c"] == 2)

# ── 2. the same description arriving by a longer route is recognised ─────────────────────────
c2 = {"filled": 0, "inserted": 0, "skipped": 0, "queued": 0, "files": 0}
F.merge_batch(db, [row(1)], "beta", BASE, c2)
check("echo: a row already resolved here is skipped", c2["skipped"] == 1 and c2["filled"] == 0, c2)

# A row that is NOT resolved here, offered with an origin no newer than the one we recorded, is the
# three-node case: it went A -> B -> C and came back. Nothing to learn, so nothing to write.
q("UPDATE index_hashes SET meta_status='none', name=NULL WHERE info_hash=%s", (hs(1),), fetch=False)
c3 = {"filled": 0, "inserted": 0, "skipped": 0, "queued": 0, "files": 0}
F.merge_batch(db, [row(1)], "beta", BASE, c3)
check("ping-pong: the same description coming round again is recognised by its origin, not rewritten",
      c3["skipped"] == 1 and c3["filled"] == 0, c3)

# …but a genuinely newer resolve DOES get through, or the rule would freeze the catalogue.
c4 = {"filled": 0, "inserted": 0, "skipped": 0, "queued": 0, "files": 0}
F.merge_batch(db, [row(1, mo=int(time.time()) - 10, name="a better name")], "beta", BASE, c4)
r = q("SELECT name, meta_source FROM index_hashes WHERE info_hash=%s", (hs(1),))[0]
check("ping-pong: a newer resolve is still accepted",
      c4["filled"] == 1 and r["name"] == "a better name" and r["meta_source"] == "fed:beta", (c4, r))

# A peer whose clock is wrong must not be able to make its rows permanently newest.
far = F.valid_row({"h": hs(1), "n": "x", "mf": 1, "mo": int(time.time()) + 86400 * 365})
check("clock: a future origin is clamped to now", far["mo"] <= int(time.time()) + 1, far["mo"])
old_style = F.valid_row({"h": hs(1), "n": "x", "mf": 12345})
check("compatibility: a node that sends no origin falls back to its fetch time",
      old_style["mo"] == 12345, old_style["mo"])

# ── 3. review mode publishes nothing ────────────────────────────────────────────────────────
clean()
seen(2)
seen(3)
REVIEW = dict(BASE, fed_import_mode="review")
c5 = {"filled": 0, "inserted": 0, "skipped": 0, "queued": 0, "files": 0}
F.merge_batch(db, [row(2), row(3)], "alpha", REVIEW, c5, peer_id=None)
check("review: nothing was merged", c5["filled"] == 0 and c5["inserted"] == 0, c5)
check("review: both packages are waiting", c5["queued"] == 2, c5)
st = q("SELECT meta_status FROM index_hashes WHERE info_hash IN (%s,%s)", (hs(2), hs(3)))
check("review: the catalogue is untouched", all(x["meta_status"] == "none" for x in st), st)
check("review: no file records leaked in",
      q("SELECT COUNT(*) c FROM index_files WHERE info_hash IN (%s,%s)", (hs(2), hs(3)))[0]["c"] == 0)
rv = q("SELECT info_hash, name, files_json, files_truncated, UNIX_TIMESTAMP(origin_at) o"
       " FROM fed_review WHERE peer_name='alpha' ORDER BY info_hash")
check("review: the queue holds enough to judge the package",
      len(rv) == 2 and rv[0]["name"] and rv[0]["files_json"] and int(rv[0]["o"]) > 0, rv[:1])

# The same page arriving again must not pile up duplicates.
c6 = {"filled": 0, "inserted": 0, "skipped": 0, "queued": 0, "files": 0}
F.merge_batch(db, [row(2), row(3)], "alpha", REVIEW, c6)
check("review: a second offer of the same packages does not duplicate the queue",
      q("SELECT COUNT(*) c FROM fed_review")[0]["c"] == 2)

# A rejected package must not come back.
q("UPDATE fed_review SET state='rejected' WHERE info_hash=%s", (hs(2),), fetch=False)
F.merge_batch(db, [row(2)], "alpha", REVIEW, {"filled": 0, "inserted": 0, "skipped": 0, "queued": 0, "files": 0})
st = q("SELECT state FROM fed_review WHERE info_hash=%s AND peer_name='alpha'", (hs(2),))
check("review: a rejected package is not re-offered by the next pull",
      len(st) == 1 and st[0]["state"] == "rejected", st)

# A file list longer than the cap is kept, capped, and says so.
clean()
seen(4)
big = row(4)
big["files"] = [("f/%d.bin" % i, i) for i in range(F.FED_REVIEW_FILES_MAX + 50)]
F.merge_batch(db, [big], "alpha", REVIEW, {"filled": 0, "inserted": 0, "skipped": 0, "queued": 0, "files": 0})
rv = q("SELECT files_truncated, files_json FROM fed_review WHERE info_hash=%s", (hs(4),))[0]
import json as _json
check("review: an enormous file list is capped rather than stored whole",
      rv["files_truncated"] == 1 and len(_json.loads(rv["files_json"])) == F.FED_REVIEW_FILES_MAX,
      rv["files_truncated"])

# ── 4. undoing an import ────────────────────────────────────────────────────────────────────
clean()
for i in (5, 6, 7):
    seen(i)
F.merge_batch(db, [row(5), row(6)], "alpha", BASE, {"filled": 0, "inserted": 0, "skipped": 0, "queued": 0, "files": 0})
F.merge_batch(db, [row(7)], "beta", BASE, {"filled": 0, "inserted": 0, "skipped": 0, "queued": 0, "files": 0})
check("undo: dry run counts and changes nothing",
      F.purge_peer(db, "alpha", dry_run=True) == 2
      and q("SELECT COUNT(*) c FROM index_hashes WHERE meta_status='done'")[0]["c"] == 3)
done = F.purge_peer(db, "alpha", batch=1)   # batch=1 so the loop itself is exercised
check("undo: every row from that peer goes back to unresolved", done == 2)
left = q("SELECT info_hash, meta_status, name, seen_count FROM index_hashes ORDER BY info_hash")
byhash = {r["info_hash"]: r for r in left}
check("undo: the peer's rows are unresolved again",
      byhash[hs(5)]["meta_status"] == "none" and byhash[hs(5)]["name"] is None, byhash[hs(5)])
check("undo: their local history survives — it was never the peer's",
      byhash[hs(5)]["seen_count"] == 4, byhash[hs(5)]["seen_count"])
check("undo: the other peer's row is untouched", byhash[hs(7)]["meta_status"] == "done")
check("undo: the peer's file records are gone",
      q("SELECT COUNT(*) c FROM index_files WHERE info_hash IN (%s,%s)", (hs(5), hs(6)))[0]["c"] == 0)
check("undo: the other peer's file records stay",
      q("SELECT COUNT(*) c FROM index_files WHERE info_hash=%s", (hs(7),))[0]["c"] == 2)
check("undo: an unknown peer is a quiet no-op", F.purge_peer(db, "nobody") == 0)

clean()
print("\n%d checks, %d failed" % (n, fails))
sys.exit(1 if fails else 0)
