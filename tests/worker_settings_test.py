#!/usr/bin/env python3
"""The bridge between the panel's `settings` table and what the metadata worker actually does.

Two settings cross that bridge — `meta_worker_concurrency` (1.7.0) and `meta_max_files` (1.38.0) —
and until this suite existed neither was tested by BEHAVIOUR. What existed was
tests/reputation_test.php, which greps both source files for the parallel-fetch ceiling and checks
the two numbers match. That is a real check and it stays there, but it cannot see any of this:

  * whether the panel's value actually WINS over the config file,
  * whether an out-of-range value is CLAMPED or thrown away (the difference between "you asked for
    32, this build tops out at 16, so 16" and the historical bug where asking for 32 silently
    produced the config's 4 — LESS than before the operator touched anything),
  * whether an empty string still means "use the config file",
  * whether a database blip resets a live cap or leaves the last known value standing,
  * and whether the cap clips the STORED LIST without also clipping the torrent's real file count,
    which is the one pair of facts the panel and the public pages read to say "5 000 of 18 000".

Everything below drives the real methods with fake objects. No database, no libtorrent.

    python tests/worker_settings_test.py
"""
import json, os, re, sys, tempfile, types

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
sys.path.insert(0, os.path.join(ROOT, 'worker'))

# worker.py imports libtorrent and pymysql at module level and exits if either is missing. Neither
# has anything to do with reading a settings table, so they are stubbed — the alternative is a suite
# that only runs on the production machine, which is the one place it is least useful.
for name, attrs in (('libtorrent', {'__version__': '0.0-stub'}), ('pymysql', {})):
    if name not in sys.modules:
        mod = types.ModuleType(name)
        for k, v in attrs.items():
            setattr(mod, k, v)
        if name == 'libtorrent':
            mod.options_t = types.SimpleNamespace(delete_files=0)
        if name == 'pymysql':
            mod.cursors = types.SimpleNamespace(DictCursor=object)
            mod.err = types.SimpleNamespace(OperationalError=Exception, InterfaceError=Exception)
        sys.modules[name] = mod

import worker as W  # noqa: E402

fails = 0
n = 0


def check(name, ok, info=''):
    global fails, n
    n += 1
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok or info == '' else '  -> ' + str(info)))
    if not ok:
        fails += 1


# ── fakes ────────────────────────────────────────────────────────────────────

class FakeDb:
    """Answers the settings query from a dict; counts how many round trips were made."""

    def __init__(self, rows=None, raises=False):
        self.rows = dict(rows or {})
        self.raises = raises
        self.queries = []
        self.time_zone = None

    def query(self, sql, args=None, fetch=False):
        self.queries.append(sql)
        if self.raises:
            raise RuntimeError('settings table unreadable')
        keys = re.findall(r"'([a-z_0-9]+)'", sql)
        return [{'key': k, 'value': v} for k, v in self.rows.items() if k in keys]

    def adopt_time_zone(self, tz):
        return False


def cfg(**kw):
    base = dict(concurrency=4, max_files=5000, index_table='index_hashes', index_keep_files=True,
                heartbeat='', tmp_dir='')
    base.update(kw)
    return types.SimpleNamespace(**base)


def conc_worker(db, c=cfg()):
    ns = types.SimpleNamespace(db=db, cfg=c, _conc_override=None, _conc_checked_at=0.0)
    ns.effective_concurrency = W.Worker.effective_concurrency.__get__(ns)
    return ns


def order_worker(db, c=cfg(), usable=None):
    """A worker stub wired for effective_order(), which is where meta_max_files is read."""
    ns = types.SimpleNamespace(
        db=db, cfg=c,
        _order_mode='oldest', _order_shares=dict(W.ORDER_MIX_DEFAULT),
        _order_plan=['oldest'] * W.ORDER_ROTATION, _order_checked_at=0.0, _order_seq=0,
        _max_files_override=None)
    ns.usable_selectors = usable or (lambda: {s: True for s in W.ORDER_SELECTORS})
    ns.adopt_max_files = W.Worker.adopt_max_files.__get__(ns)
    ns.effective_order = W.Worker.effective_order.__get__(ns)
    ns.effective_max_files = W.Worker.effective_max_files.__get__(ns)
    return ns


def refresh(ns):
    """Force the next call past the 60 s cache."""
    ns._order_checked_at = 0.0
    ns.effective_order()


# ── 1. parallel fetches: the older half of the bridge, finally driven ────────

w = conc_worker(FakeDb({'meta_worker_concurrency': '12'}))
check('concurrency: the panel value wins over the config file',
      w.effective_concurrency() == 12, w.effective_concurrency())

w = conc_worker(FakeDb({'meta_worker_concurrency': '999'}))
got = w.effective_concurrency()
check('concurrency: above the build ceiling is CLAMPED, not discarded',
      got == W.CONCURRENCY_MAX, got)
check('… and clamping is the whole point: it must never fall back to the config value',
      got != 4, got)

w = conc_worker(FakeDb({'meta_worker_concurrency': ''}))
check('concurrency: empty means "use the config file"', w.effective_concurrency() == 4)

w = conc_worker(FakeDb({'meta_worker_concurrency': 'lots'}))
check('concurrency: a non-numeric value is ignored, not a crash', w.effective_concurrency() == 4)

w = conc_worker(FakeDb({}, raises=True))
check('concurrency: an unreadable settings table falls back to the config value',
      w.effective_concurrency() == 4)

w = conc_worker(FakeDb({'meta_worker_concurrency': '12'}))
w.effective_concurrency()
w.effective_concurrency()
check('concurrency: re-read is rate limited, not once per claim', len(w.db.queries) == 1,
      len(w.db.queries))


# ── 2. stored files per torrent: the same three rules, deliberately ──────────

w = order_worker(FakeDb({'meta_max_files': '20000'}))
w.effective_order()
check('max files: the panel value wins over the config file',
      w.effective_max_files() == 20000, w.effective_max_files())

w = order_worker(FakeDb({'meta_max_files': '90000'}))
w.effective_order()
got = w.effective_max_files()
check('max files: above the build ceiling is CLAMPED to it', got == W.MAX_FILES_MAX, got)
check('… and never quietly back to the config file, which would store LESS than before asking',
      got != 5000, got)

w = order_worker(FakeDb({'meta_max_files': ''}))
w.effective_order()
check('max files: empty means "use the worker\'s own config file"', w.effective_max_files() == 5000)

w = order_worker(FakeDb({'meta_max_files': 'lots'}))
w.effective_order()
check('max files: a non-numeric value is ignored, not a crash', w.effective_max_files() == 5000)

w = order_worker(FakeDb({'meta_max_files': '0'}))
w.effective_order()
check('max files: 0 clamps to 1 — "store no files" is what index_keep_files is for',
      w.effective_max_files() == 1, w.effective_max_files())

# The read-error contract here is DIFFERENT from the concurrency one, on purpose, and the difference
# is worth pinning: riding in effective_order()'s query means the last known value survives a
# database blip. A blip is not an instruction to start truncating file lists.
w = order_worker(FakeDb({'meta_max_files': '20000'}))
w.effective_order()
w.db = FakeDb({}, raises=True)
refresh(w)
check('max files: a database blip leaves the LAST KNOWN cap standing, not the config value',
      w.effective_max_files() == 20000, w.effective_max_files())

# One query, not two. The whole reason the key rides along here.
w = order_worker(FakeDb({'meta_max_files': '20000', 'meta_order_mode': 'newest'}))
w.effective_order()
check('max files: read inside the EXISTING settings query, no extra round trip',
      len(w.db.queries) == 1, w.db.queries)
check('… and that query really does ask for the key',
      'meta_max_files' in w.db.queries[0], w.db.queries[0])

# Parsed FIRST, before anything in that block that can throw. usable_selectors() runs its own
# information_schema query and the whole body shares one `except`, so a failure up there must not
# leave the cap at yesterday's value while the panel shows today's.
def boom():
    raise RuntimeError('information_schema unreadable')


w = order_worker(FakeDb({'meta_max_files': '20000', 'meta_order_mode': 'seeders'}), usable=boom)
w.effective_order()
check('max files: adopted even when a later step in the same block throws',
      w.effective_max_files() == 20000, w.effective_max_files())

# Changing it back to empty must restore the config value rather than stick at the last number.
w = order_worker(FakeDb({'meta_max_files': '20000'}))
w.effective_order()
w.db.rows['meta_max_files'] = ''
refresh(w)
check('max files: clearing the field returns to the config value',
      w.effective_max_files() == 5000, w.effective_max_files())

w = order_worker(FakeDb({}))
check('max files: adopt_max_files reports whether the effective value moved',
      w.adopt_max_files('20000') is True and w.adopt_max_files('20000') is False)


# ── 3. what finish() actually writes ────────────────────────────────────────
#
# Two facts, asserted TOGETHER, because they are the whole reported bug: the list is clipped and the
# COUNT is not. A test that only counted inserted rows would pass on a build that also clipped
# files_count — and then the panel and the public pages would agree on a wrong number and nobody
# would ever see the shortfall.

class FakeCursor:
    def __init__(self, log):
        self.log = log

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def execute(self, sql, args=None):
        self.log.append(('execute', sql, args))

    def executemany(self, sql, rows):
        self.log.append(('executemany', sql, list(rows)))


class FakeConn:
    def __init__(self):
        self.log = []
        self.committed = False

    def cursor(self, *a, **kw):
        return FakeCursor(self.log)

    def begin(self):
        self.log.append(('begin', None, None))

    def commit(self):
        self.committed = True

    def rollback(self):
        self.log.append(('rollback', None, None))


class FakeFiles:
    def __init__(self, count):
        self.count = count

    def num_files(self):
        return self.count

    def file_path(self, i):
        return 'dir/file%05d.bin' % i

    def file_size(self, i):
        return 1000 + i


class FakeTi:
    def __init__(self, count):
        self._files = FakeFiles(count)

    def name(self):
        return 'A big torrent'

    def total_size(self):
        return 42 * 1024 * 1024

    def piece_length(self):
        return 262144

    def files(self):
        return self._files


IDXQ = {'table': 'index_hashes', 'files': 'index_files', 'key_col': 'info_hash',
        'files_fk': 'info_hash', 'select': 'info_hash'}


def run_finish(stored_cap, real_files=18000, keep_files=True, q=IDXQ):
    conn = FakeConn()
    c = cfg(index_keep_files=keep_files)
    db = types.SimpleNamespace(_connect=lambda: conn, query=lambda *a, **k: 1)
    row = {'_q': q, 'info_hash': 'a' * 40, 'id': 7}
    ns = types.SimpleNamespace(
        cfg=c, db=db, active={'tok': {'row': row, 'handle': object(), 'started': 0}},
        ses=types.SimpleNamespace(remove_torrent=lambda *a: None),
        _meta_source_ok=True,
        effective_max_files=lambda: stored_cap)
    W.Worker.finish.__get__(ns)('tok', True, ti=FakeTi(real_files))
    return conn


conn = run_finish(5000)
inserts = [e for e in conn.log if e[0] == 'executemany']
updates = [e for e in conn.log if e[0] == 'execute' and e[1].startswith('UPDATE index_hashes SET name')]
check('finish: exactly the effective cap of file rows is written',
      len(inserts) == 1 and len(inserts[0][2]) == 5000, [len(i[2]) for i in inserts])
check("finish: the row still records the torrent's REAL file count, unclipped",
      len(updates) == 1 and updates[0][2][2] == 18000, updates and updates[0][2])
check('finish: the whole thing is one transaction that commits', conn.committed)
check('finish: the stored list is the FIRST n paths, in order',
      inserts[0][2][0][1] == 'dir/file00000.bin' and inserts[0][2][-1][1] == 'dir/file04999.bin',
      (inserts[0][2][0], inserts[0][2][-1]))

conn = run_finish(20000)
inserts = [e for e in conn.log if e[0] == 'executemany']
check('finish: raising the cap really does store more of the same torrent',
      len(inserts[0][2]) == 18000, len(inserts[0][2]))
check('… and never more rows than the torrent has files',
      len(inserts[0][2]) <= 18000)

# index_keep_files=0 skips file rows for INDEX rows entirely. The new cap must not be read as
# "store at least one".
conn = run_finish(5000, keep_files=False)
check('finish: with file lists off, no file rows are written at all',
      not [e for e in conn.log if e[0] == 'executemany'])
updates = [e for e in conn.log if e[0] == 'execute' and e[1].startswith('UPDATE index_hashes SET name')]
check('… but files_count is still recorded, so the count/list gap stays visible',
      updates[0][2][2] == 18000, updates[0][2])

# One cap, both queues: finish() is shared, and index_keep_files is forced on for the whitelist.
WLQ = {'table': 'whitelist', 'files': 'whitelist_files', 'key_col': 'id', 'files_fk': 'info_hash',
       'select': 'id, info_hash, magnet_link'}
conn = run_finish(5000, keep_files=False, q=WLQ)
inserts = [e for e in conn.log if e[0] == 'executemany']
check('finish: the whitelist keeps its file list whatever index_keep_files says',
      len(inserts) == 1 and len(inserts[0][2]) == 5000, [len(i[2]) for i in inserts])
check('… and it lands in whitelist_files, under the same cap',
      'whitelist_files' in inserts[0][1], inserts[0][1])


# ── 4. the heartbeat has to carry it, or the panel reports the setting back ──
#
# A worker started from an older worker.py ignores meta_max_files and says nothing. The panel can
# only tell the difference by the ABSENCE of these keys, so their presence is the contract.

hbdir = tempfile.mkdtemp()
hbfile = os.path.join(hbdir, 'heartbeat')
hbdb = FakeDb({'meta_worker_concurrency': '8'})
ns = types.SimpleNamespace(cfg=cfg(heartbeat=hbfile), db=hbdb, active={},
                           _conc_override=None, _conc_checked_at=0.0, _max_files_override=20000,
                           _order_mode='seeders', _order_shares={'seeders': 100})
ns.effective_concurrency = W.Worker.effective_concurrency.__get__(ns)
ns.effective_max_files = W.Worker.effective_max_files.__get__(ns)
W.Worker.heartbeat.__get__(ns)()
hb = json.loads(open(hbfile, encoding='utf-8').read())
check('heartbeat: says what the worker is RUNNING', hb.get('max_files') == 20000, hb.get('max_files'))
check('heartbeat: says what the config file alone would give',
      hb.get('max_files_config') == 5000, hb.get('max_files_config'))
check('heartbeat: says what this build accepts, so "asked 90 000, tops out at N" is a fact',
      hb.get('max_files_max') == W.MAX_FILES_MAX, hb.get('max_files_max'))
os.unlink(hbfile)
os.rmdir(hbdir)


# ── 5. the ceiling is written twice, in two languages, and must agree ────────
#
# It cannot be written once: one half enforces it in Python, the other offers it in a PHP form. So
# the property is not "the number is 50 000", it is that the two agree — and that neither file grew
# a third copy as a bare literal. This is the CONCURRENCY_MAX story, which produced a panel offering
# 64 against a worker enforcing 16.

def read(rel):
    with open(os.path.join(ROOT, rel), encoding='utf-8') as fh:
        return fh.read()


worker_src, index_src = read('worker/worker.py'), read('includes/index.php')
save_src, tpl_src = read('api/admin/save_settings.php'), read('templates/admin/settings.php')
m = re.search(r'^MAX_FILES_MAX\s*=\s*(\d+)', worker_src, re.M)
p = re.search(r"const META_MAX_FILES_MAX\s*=\s*(\d+)", index_src)
check('the worker states a stored-files ceiling as a named constant', m is not None)
check('the panel states one too, in exactly one PHP place', p is not None)
if m and p:
    check('and the two agree — a panel offering more than the worker stores is the old 32-becomes-4 bug',
          int(m.group(1)) == int(p.group(1)), (m.group(1), p.group(1)))
check('the save clamp uses the constant rather than retyping the number',
      re.search(r"meta_max_files.*?min\(META_MAX_FILES_MAX", save_src, re.S) is not None)
check('the settings field renders its max= from the same constant',
      'name="meta_max_files"' in tpl_src and 'max="<?= META_MAX_FILES_MAX ?>"' in tpl_src)
# Empty must survive the save path untouched, or "use the worker's config file" stops existing.
check("the save path leaves an empty value alone (it means 'use the worker's config file')",
      re.search(r"\$data\['meta_max_files'\] !== ''", save_src) is not None)
check('meta_max_files is NOT in the $intClamp table, which rewrites empty to the default',
      re.search(r"\$intClamp\s*=\s*\[(.*?)\n\];", save_src, re.S) is not None
      and 'meta_max_files' not in re.search(r"\$intClamp\s*=\s*\[(.*?)\n\];", save_src, re.S).group(1))

# The federation writers keep their OWN limits, and that is a decision rather than an oversight —
# pin it so nobody "fixes" it into a second meaning for the same number, and so the panel's promise
# that imported rows are not governed by this setting stays true.
fed_py, fed_php = read('worker/federation.py'), read('includes/federation.php')
check('federation import bounds a peer row by its own constant, not by meta_max_files',
      re.search(r'^MAX_FILES_PER_ROW\s*=\s*\d+', fed_py, re.M) is not None
      and 'meta_max_files' not in fed_py.replace('`meta_max_files`', ''))
check('the review-approval path does not reach for it either',
      'meta_max_files' not in re.sub(r'//[^\n]*', '', fed_php))

print(chr(10) + '%d checks, %d failed' % (n, fails))
sys.exit(1 if fails else 0)
