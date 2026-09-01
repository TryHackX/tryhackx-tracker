#!/usr/bin/env python3
"""The metadata worker's fetch order — the rotation, the queue split, and the SQL each mode runs.

Why this suite exists at all: the index queue is ~3 million rows and resolves at a few per second,
so the ORDER of that queue decides what the tracker knows anything about for months. Three things
can go wrong quietly.

  * A rotation that is arithmetically right but BLOCKED (seventy of one kind, then fifteen of the
    next) makes each wave of parallel fetches a single kind and the balance only appears over hours —
    the numbers in the panel would still read 70/15/15.
  * A selector whose ORDER BY no index can serve turns every claim into a filesort over three
    million rows, several times a second. That looks like a slow machine, not a wrong setting.
  * The whitelist — rows a person asked for by name — silently losing its absolute priority.

All three are tested here by property, not by eye.

    python tests/meta_order_test.py
"""
import os, re, sys, types

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'worker'))

# worker.py imports libtorrent and pymysql at module level and exits if either is missing. Neither
# has anything to do with the order of a queue, so they are stubbed: the alternative is a suite that
# only runs on the production machine, which is the one place it is least useful.
for name, attrs in (('libtorrent', {'__version__': '0.0-stub'}), ('pymysql', {})):
    if name not in sys.modules:
        mod = types.ModuleType(name)
        for k, v in attrs.items():
            setattr(mod, k, v)
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


# ── the rotation ─────────────────────────────────────────────────────────────
plan = W.order_rotation({'seeders': 70, 'newest': 15, 'random': 15, 'oldest': 0})
check('rotation is exactly 100 claims long', len(plan) == 100, len(plan))
counts = {s: plan.count(s) for s in set(plan)}
check('70/15/15 gives 70/15/15 slots', counts == {'seeders': 70, 'newest': 15, 'random': 15}, counts)
check('a zero share never appears', 'oldest' not in plan)

# The point of the interleave: any window the size of a wave of parallel fetches is already a
# proportional sample. A window shorter than the longest legitimate run of the 70 % selector can of
# course be all one kind — that is what 70 % MEANS.
worst = max(''.join('S' if p == 'seeders' else '.' for p in plan).split('.'), key=len)
check('the biggest run of one selector is short, so a wave is never one kind', len(worst) <= 6, len(worst))
for size in (8, 16, 32):
    windows = [plan[i:i + size] for i in range(0, 100 - size + 1)]
    check('every window of %d claims mixes at least two selectors' % size,
          all(len(set(w)) > 1 for w in windows))

# One percent is one claim in a hundred — small, but never "never". This is the whole reason the
# rotation is 100 long rather than, say, the parallel-fetch count.
tiny = W.order_rotation({'seeders': 99, 'random': 1})
check('a 1 % share still gets exactly one slot', tiny.count('random') == 1, tiny.count('random'))

# Deterministic: two workers reading the same settings must build the same plan, or "the order" is
# whatever the last process to start happened to compute.
check('the same shares always produce the same plan',
      W.order_rotation({'seeders': 70, 'newest': 15, 'random': 15}) ==
      W.order_rotation({'seeders': 70, 'newest': 15, 'random': 15}))

odd = W.order_rotation({'seeders': 2, 'newest': 1})
check('shares that do not total 100 still fill the rotation',
      len(odd) == 100 and 0 < odd.count('newest') < 50, odd.count('newest'))
check('every share at zero falls back to a usable plan, not an empty one',
      W.order_rotation({}) == ['oldest'] * 100)

# The whitelist takes part in the rotation like any other share.
wlplan = W.order_rotation({'whitelist': 25, 'seeders': 75})
check('the whitelist can hold a share of the rotation', wlplan.count('whitelist') == 25, wlplan.count('whitelist'))
check('…and it is spread through it, not bunched at the front',
      max(len(r) for r in ''.join('W' if p == 'whitelist' else '.' for p in wlplan).split('.')) <= 2)

# ── normalising what is in the settings table ────────────────────────────────
full = dict(W.ORDER_MIX_DEFAULT)
mode, sh = W.order_normalise('mix', {k: str(v) for k, v in full.items()})
check('a valid mix survives untouched', mode == 'mix' and sh == full, (mode, sh))
check('an unknown mode becomes the default, not a crash', W.order_normalise('sideways', {})[0] == 'oldest')
check('an empty mode becomes the default', W.order_normalise('', {})[0] == 'oldest')
# Each of these keeps ONE valid share alongside the bad one, so the "everything is zero" fallback
# does not fire and hide what is being tested.
check('nonsense in a share reads as zero, not as an exception',
      W.order_normalise('mix', {'seeders': 'lots', 'newest': '10'})[1]['seeders'] == 0)
check('a share is clamped to 0..100', W.order_normalise('mix', {'seeders': '900'})[1]['seeders'] == 100)
check('a negative share is clamped to zero',
      W.order_normalise('mix', {'seeders': '-5', 'newest': '10'})[1]['seeders'] == 0)
check('…and the valid share beside it survives',
      W.order_normalise('mix', {'seeders': '-5', 'newest': '10'})[1]['newest'] == 10)
check('mix with every share empty falls back to the default mix',
      W.order_normalise('mix', {})[1] == W.ORDER_MIX_DEFAULT)
check('normalise returns every mix key, including whitelist',
      set(W.order_normalise('mix', {})[1]) == set(W.ORDER_MIX_KEYS))

# ── the SQL each selector runs ───────────────────────────────────────────────
IDX = {'table': 'index_hashes', 'select': 'info_hash', 'gate': ' AND meta_requested_at <= NOW()',
       'key_col': 'info_hash', 'orderable': True}
WL = {'table': 'whitelist', 'select': 'id, info_hash, magnet_link', 'gate': '', 'key_col': 'id'}
q = W.Worker.claim_query.__get__(types.SimpleNamespace())

COLUMN = {'oldest': 'meta_requested_at ASC', 'newest': 'meta_requested_at DESC',
          'seeders': 'last_seeders DESC', 'seen': 'seen_count DESC', 'completed': 'last_completed DESC'}
for sel, frag in COLUMN.items():
    sql, params = q(IDX, sel)
    check('%s sorts by %s' % (sel, frag), 'ORDER BY meta_priority DESC, ' + frag in sql, sql)
    check('%s passes no parameters' % sel, params == (), params)

sql_rand, p_rand = q(IDX, 'random')
check('random seeks on the primary key instead of sorting the table',
      'info_hash >= %s' in sql_rand and 'ORDER BY info_hash' in sql_rand and 'RAND()' not in sql_rand, sql_rand)
check('…and it passes a full-width random hash',
      len(p_rand) == 1 and re.fullmatch(r'[0-9a-f]{40}', p_rand[0]) is not None, p_rand)
check('two random claims do not ask for the same point', q(IDX, 'random')[1] != q(IDX, 'random')[1])

# The expensive thing this design exists to avoid.
for sel in W.ORDER_SELECTORS:
    sql, _ = q(IDX, sel)
    check('%s never sorts by an unindexed column' % sel,
          'RAND()' not in sql and 'ORDER BY name' not in sql and 'total_size' not in sql, sql)
    check('%s still claims only ONE row' % sel, sql.rstrip().endswith('LIMIT 1'), sql)
    check('%s keeps the pending filter and the queue gate' % sel,
          "meta_status='pending'" in sql and 'meta_requested_at <= NOW()' in sql, sql)
    check('%s names an index this project actually creates' % sel,
          W.ORDER_INDEX[sel] is None or W.ORDER_INDEX[sel].startswith('idx_index_'), W.ORDER_INDEX[sel])

# The whitelist is NOT reorderable: those rows are there because a person asked for them by name.
for sel in W.ORDER_SELECTORS:
    sql, params = q(WL, sel)
    check('the whitelist ignores the "%s" selector' % sel,
          'ORDER BY meta_priority DESC, meta_requested_at ASC' in sql and params == (), sql)
    check('…and never gets an index-only column in its SQL (%s)' % sel,
          'last_seeders' not in sql and 'seen_count' not in sql and 'info_hash >=' not in sql, sql)


# ── which queue a claim goes to ──────────────────────────────────────────────
# claim_targets is the subtlest thing here: it decides whether the whitelist keeps the absolute
# priority every earlier release gave it, or takes a share. Driven directly, with no database.
def targets(mode, shares, seq=0):
    w = types.SimpleNamespace(
        queues=[WL, IDX],
        _order_seq=seq,
        effective_order=lambda: (mode, shares,
                                 W.order_rotation(shares) if mode == 'mix' else [mode] * W.ORDER_ROTATION),
    )
    return W.Worker.claim_targets(w)


for m in W.ORDER_SELECTORS:
    t = targets(m, dict(W.ORDER_MIX_DEFAULT))
    check('mode %s: the whitelist is tried FIRST' % m, t[0][0] is WL, [x[0]['table'] for x in t])
    check('mode %s: the index is tried with that ordering' % m, t[1] == (IDX, m), t[1][1])
    check('mode %s: the whitelist is claimed in queue order, never reordered' % m, t[0][1] == 'oldest')

# whitelist share 0 inside the mix = the old behaviour, unchanged
mixNoWl = {'whitelist': 0, 'seeders': 70, 'newest': 15, 'random': 15, 'oldest': 0, 'seen': 0, 'completed': 0}
t = targets('mix', mixNoWl)
check('mix with whitelist 0: the whitelist still drains first', t[0][0] is WL and t[0][1] == 'oldest',
      [(x[0]['table'], x[1]) for x in t])
check('mix with whitelist 0: no slot is ever labelled whitelist',
      'whitelist' not in W.order_rotation(mixNoWl))

# whitelist with a share: the rotation decides, and an empty queue never wastes a slot
mixWl = {'whitelist': 50, 'seeders': 50, 'newest': 0, 'random': 0, 'oldest': 0, 'seen': 0, 'completed': 0}
plan50 = W.order_rotation(mixWl)
wl_slot = plan50.index('whitelist')
idx_slot = plan50.index('seeders')
t = targets('mix', mixWl, seq=wl_slot)
check('a whitelist slot goes to the whitelist first', t[0][0] is WL, [x[0]['table'] for x in t])
check('…but still falls through to the index if the whitelist is empty', t[1][0] is IDX, len(t))
t = targets('mix', mixWl, seq=idx_slot)
check('an index slot goes to the index FIRST when the whitelist has its own share',
      t[0][0] is IDX and t[0][1] == 'seeders', [(x[0]['table'], x[1]) for x in t])
check('…and still falls through to the whitelist if the index is empty', t[1][0] is WL, len(t))
check('every claim offers both queues, so no slot is ever wasted',
      all(len(targets('mix', mixWl, seq=i)) == 2 for i in range(100)))

# The rotation must advance, or one selector would take every claim for ever.
w = types.SimpleNamespace(queues=[WL, IDX], _order_seq=0,
                          effective_order=lambda: ('mix', mixWl, plan50))
seen_slots = [W.Worker.claim_targets(w)[0][0]['table'] for _ in range(100)]
check('a hundred claims use the rotation, not slot zero a hundred times',
      len(set(seen_slots)) == 2 and 40 <= seen_slots.count('whitelist') <= 60, seen_slots.count('whitelist'))

# ── the panel and the worker must agree ──────────────────────────────────────
root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
schema = open(os.path.join(root, 'includes', 'schema.php'), encoding='utf-8').read()
save = open(os.path.join(root, 'api', 'admin', 'save_settings.php'), encoding='utf-8').read()
tpl = open(os.path.join(root, 'templates', 'admin', 'settings.php'), encoding='utf-8').read()
cat = open(os.path.join(root, 'includes', 'settings_catalog.php'), encoding='utf-8').read()
php = open(os.path.join(root, 'includes', 'meta_order.php'), encoding='utf-8').read()

for key in ['meta_order_mode'] + ['meta_order_mix_' + s for s in W.ORDER_MIX_KEYS]:
    check('%s has a schema default' % key, "'" + key + "'" in schema)
    check('%s is in the save allow-list' % key, "'" + key + "'" in save)
    check('%s is in the settings catalogue' % key, "'" + key + "'" in cat)
    # The field NAMES are spelled out rather than assembled at render time: the settings search finds
    # a control by its name, and the panel suite checks every catalogue key is reachable on the page.
    # A name built inside a PHP loop is invisible to both.
    if key != 'meta_order_mode':
        check('%s has a literal form field' % key, 'name="%s"' % key in tpl)


def php_list(fn):
    m = re.search(r"function %s\(\): array \{\s*return \[(.*?)\];" % fn, php, re.S)
    return tuple(re.findall(r"'(\w+)'", m.group(1))) if m else ()


def php_map(fn):
    m = re.search(r"function %s\(\): array \{\s*return \[(.*?)\];" % fn, php, re.S)
    return tuple(re.findall(r"'(\w+)'\s*=>", m.group(1))) if m else ()


check('the panel and the worker define the same selectors, in the same order',
      php_list('metaOrderSelectors') == W.ORDER_SELECTORS,
      (php_list('metaOrderSelectors'), W.ORDER_SELECTORS))
check('every mode the worker runs has a label in the form',
      set(php_map('metaOrderModeLabels')) == set(W.ORDER_MODES),
      (php_map('metaOrderModeLabels'), W.ORDER_MODES))
check('every mix key has a share field label',
      set(php_map('metaOrderShareLabels')) == set(W.ORDER_MIX_KEYS), php_map('metaOrderShareLabels'))
check('the panel and the worker distribute over the same mix keys',
      set(php_map('metaOrderDefaultMix')) == set(W.ORDER_MIX_KEYS), php_map('metaOrderDefaultMix'))

m = re.search(r"function metaOrderDefaultMix\(\): array \{\s*return \[(.*?)\];", php, re.S)
php_mix = {k: int(v) for k, v in re.findall(r"'(\w+)'\s*=> (\d+)", m.group(1))} if m else {}
check('the panel and the worker ship the SAME default mix', php_mix == W.ORDER_MIX_DEFAULT,
      (php_mix, W.ORDER_MIX_DEFAULT))
check('the default mix adds up to 100', sum(W.ORDER_MIX_DEFAULT.values()) == 100)
check('the shipped default keeps the whitelist on absolute priority',
      W.ORDER_MIX_DEFAULT['whitelist'] == 0)

m = re.search(r"function metaOrderIndexes\(\): array \{\s*return \[(.*?)\];", php, re.S)
php_idx = {}
if m:
    for k, v in re.findall(r"'(\w+)'\s*=> '?(\w+)'?", m.group(1)):
        php_idx[k] = None if v == 'null' else v
check('the panel and the worker agree on which index each ordering rides',
      php_idx == W.ORDER_INDEX, (php_idx, W.ORDER_INDEX))

# Every index named must actually be created by the schema, or the worker disables the selector for
# ever and the panel shows "building" that never finishes.
for sel, idx in W.ORDER_INDEX.items():
    if idx:
        check('schema.php creates %s (needed by "%s")' % (idx, sel), '`%s`' % idx in schema)

check('the form drives its labels and defaults from includes/meta_order.php',
      'metaOrderModeLabels()' in tpl and 'metaOrderShareLabels()' in tpl and 'metaOrderDefaultMix()' in tpl)
check('the save path normalises through the same file', 'metaOrderNormalise(' in save)
check('the form asks the database which orderings are usable', 'metaOrderAvailable(' in tpl)

print(chr(10) + '%d checks, %d failed' % (n, fails))
sys.exit(1 if fails else 0)
