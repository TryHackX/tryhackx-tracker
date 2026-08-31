#!/usr/bin/env python3
"""The metadata worker's fetch order — the rotation, the fallbacks, and the SQL each mode runs.

Why this suite exists at all: the index queue is ~3 million rows and resolves at a few per second,
so the ORDER of that queue decides what the tracker knows anything about for months. Two things can
go wrong quietly. A rotation that is arithmetically right but BLOCKED (seventy of one kind, then
fifteen of the next) makes each wave of parallel fetches a single kind and the balance only appears
over hours — the numbers in the panel would still read 70/15/15. And a selector whose ORDER BY no
index can serve turns every claim into a filesort over three million rows, several times a second,
which shows up as a slow machine rather than as a wrong setting.

Both are tested here by property, not by eye.

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
# proportional sample. With 70 % seeders, no window of 20 claims may be entirely seeders.
worst = max(''.join('S' if p == 'seeders' else '.' for p in plan).split('.'), key=len)
check('the biggest run of one selector is short, so a wave is never one kind', len(worst) <= 6, len(worst))
# A window shorter than the longest legitimate run of the 70 % selector can of course be all one
# kind — that is what 70 % MEANS, and asserting otherwise would be asserting the shares are wrong.
# The property worth holding is that once a wave is bigger than that run, it is always mixed.
for size in (8, 16, 32):
    windows = [plan[i:i + size] for i in range(0, 100 - size + 1)]
    ok = all(len(set(w)) > 1 for w in windows)
    check('every window of %d claims mixes at least two selectors' % size, ok)

# One percent is one claim in a hundred — small, but never "never". This is the whole reason the
# rotation is 100 long rather than, say, the parallel-fetch count.
tiny = W.order_rotation({'seeders': 99, 'random': 1})
check('a 1 % share still gets exactly one slot', tiny.count('random') == 1, tiny.count('random'))

# Deterministic: two workers reading the same settings must build the same plan, or "the order" is
# whatever the last process to start happened to compute.
check('the same shares always produce the same plan',
      W.order_rotation({'seeders': 70, 'newest': 15, 'random': 15}) ==
      W.order_rotation({'seeders': 70, 'newest': 15, 'random': 15}))

# Shares that do not add to 100 still produce a full, proportional plan — the panel normalises, but
# a value edited straight into the database must not produce a short or empty rotation.
odd = W.order_rotation({'seeders': 2, 'newest': 1})
check('shares that do not total 100 still fill the rotation', len(odd) == 100 and 0 < odd.count('newest') < 50,
      odd.count('newest'))
check('every share at zero falls back to a usable plan, not an empty one',
      W.order_rotation({}) == ['oldest'] * 100)

# ── normalising what is in the settings table ────────────────────────────────
mode, sh = W.order_normalise('mix', {'seeders': '70', 'newest': '15', 'random': '15', 'oldest': '0'})
check('a valid mix survives untouched', mode == 'mix' and sh['seeders'] == 70, (mode, sh))
check('an unknown mode becomes the default, not a crash',
      W.order_normalise('sideways', {})[0] == 'oldest')
check('an empty mode becomes the default', W.order_normalise('', {})[0] == 'oldest')
# Each of these keeps ONE valid share alongside the bad one, so the "everything is zero" fallback
# below does not fire and hide what is being tested. Getting that wrong the first time is the point:
# a bad value and an empty configuration are different things and must not be checked together.
check('nonsense in a share reads as zero, not as an exception',
      W.order_normalise('mix', {'seeders': 'lots', 'newest': '10'})[1]['seeders'] == 0)
check('a share is clamped to 0..100', W.order_normalise('mix', {'seeders': '900'})[1]['seeders'] == 100)
check('a negative share is clamped to zero',
      W.order_normalise('mix', {'seeders': '-5', 'newest': '10'})[1]['seeders'] == 0)
check('…and the valid share beside it survives',
      W.order_normalise('mix', {'seeders': '-5', 'newest': '10'})[1]['newest'] == 10)
# A mix mode with nothing in it would leave the worker with an empty rotation, so it falls back to
# the shipped default rather than to "no ordering at all".
check('mix with every share empty falls back to the default mix',
      W.order_normalise('mix', {})[1] == W.ORDER_MIX_DEFAULT)
check('a NON-mix mode keeps its zero shares (they are not consulted)',
      W.order_normalise('seeders', {})[1] == dict.fromkeys(W.ORDER_SELECTORS, 0))

# ── the SQL each selector runs ───────────────────────────────────────────────
# Built without touching a database: claim_query is a pure function of the queue description and the
# selector, which is exactly what makes it testable off the production machine.
IDX = {'table': 'index_hashes', 'select': 'info_hash', 'gate': ' AND meta_requested_at <= NOW()',
       'key_col': 'info_hash', 'orderable': True}
WL = {'table': 'whitelist', 'select': 'id, info_hash, magnet_link', 'gate': '', 'key_col': 'id'}

worker_self = types.SimpleNamespace(claim_query=W.Worker.claim_query.__get__(types.SimpleNamespace()))
q = worker_self.claim_query

sql_oldest, p_oldest = q(IDX, 'oldest')
check('oldest orders by priority then longest waiting',
      'ORDER BY meta_priority DESC, meta_requested_at ASC' in sql_oldest and p_oldest == (), sql_oldest)
sql_new, _ = q(IDX, 'newest')
check('newest walks the SAME index backwards',
      'ORDER BY meta_priority DESC, meta_requested_at DESC' in sql_new, sql_new)
sql_seed, _ = q(IDX, 'seeders')
check('seeders orders by last_seeders, which idx_index_meta_seed covers',
      'ORDER BY meta_priority DESC, last_seeders DESC' in sql_seed, sql_seed)
sql_rand, p_rand = q(IDX, 'random')
check('random seeks on the primary key instead of sorting the table',
      'info_hash >= %s' in sql_rand and 'ORDER BY info_hash' in sql_rand and 'RAND()' not in sql_rand, sql_rand)
check('…and it passes a full-width random hash', len(p_rand) == 1 and re.fullmatch(r'[0-9a-f]{40}', p_rand[0]),
      p_rand)
check('two random claims do not ask for the same point', q(IDX, 'random')[1] != q(IDX, 'random')[1])

# The expensive thing this design exists to avoid.
for sel in W.ORDER_SELECTORS:
    sql, _ = q(IDX, sel)
    check('%s never sorts by an unindexed column' % sel,
          'RAND()' not in sql and 'seen_count' not in sql and 'ORDER BY name' not in sql, sql)
    check('%s still claims only ONE row' % sel, sql.rstrip().endswith('LIMIT 1'), sql)
    check('%s keeps the pending filter and the queue gate' % sel,
          "meta_status='pending'" in sql and 'meta_requested_at <= NOW()' in sql, sql)

# The whitelist is NOT reorderable: those rows are there because a person asked for them by name.
for sel in W.ORDER_SELECTORS:
    sql, params = q(WL, sel)
    check('the whitelist ignores the "%s" selector' % sel,
          'ORDER BY meta_priority DESC, meta_requested_at ASC' in sql and params == (), sql)
    check('…and never gets an index-only column in its SQL (%s)' % sel,
          'last_seeders' not in sql and 'info_hash >=' not in sql, sql)

# ── the panel and the worker must agree on the names ─────────────────────────
root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
schema = open(os.path.join(root, 'includes', 'schema.php'), encoding='utf-8').read()
save = open(os.path.join(root, 'api', 'admin', 'save_settings.php'), encoding='utf-8').read()
tpl = open(os.path.join(root, 'templates', 'admin', 'settings.php'), encoding='utf-8').read()
cat = open(os.path.join(root, 'includes', 'settings_catalog.php'), encoding='utf-8').read()

for key in ['meta_order_mode'] + ['meta_order_mix_' + s for s in W.ORDER_SELECTORS]:
    # Two of the four places a setting has to be registered, or it silently does nothing: the schema
    # defaults (no row = the worker never sees it) and the save allow-list (dropped on save).
    check('%s has a schema default' % key, "'" + key + "'" in schema)
    check('%s is in the save allow-list' % key, "'" + key + "'" in save)
    check('%s is in the settings catalogue' % key, "'" + key + "'" in cat)

# The form builds its fields from includes/meta_order.php rather than spelling them out, so the
# check that matters is that the PHP list and the Python list are the SAME list. Grepping the
# template for a literal field name would only prove the template contains a PHP loop.
php = open(os.path.join(root, 'includes', 'meta_order.php'), encoding='utf-8').read()
php_sel = re.search(r"function metaOrderSelectors\(\): array \{\s*return \[(.*?)\];", php, re.S)
php_sel = tuple(re.findall(r"'(\w+)'", php_sel.group(1))) if php_sel else ()
check('the panel and the worker define the same selectors, in the same order',
      php_sel == W.ORDER_SELECTORS, (php_sel, W.ORDER_SELECTORS))

php_labels = re.search(r"function metaOrderModeLabels\(\): array \{\s*return \[(.*?)\];", php, re.S)
labelled = tuple(re.findall(r"'(\w+)'\s*=>", php_labels.group(1))) if php_labels else ()
check('every mode the worker runs has a label in the form', set(labelled) == set(W.ORDER_MODES),
      (labelled, W.ORDER_MODES))

php_shares = re.search(r"function metaOrderShareLabels\(\): array \{\s*return \[(.*?)\];", php, re.S)
share_keys = set(re.findall(r"'(\w+)'\s*=>", php_shares.group(1))) if php_shares else set()
check('every selector has a share field', share_keys == set(W.ORDER_SELECTORS), share_keys)

php_mix = re.search(r"function metaOrderDefaultMix\(\): array \{\s*return \[(.*?)\];", php, re.S)
php_mix = {k: int(v) for k, v in re.findall(r"'(\w+)'\s*=> (\d+)", php_mix.group(1))} if php_mix else {}
check('the panel and the worker ship the SAME default mix', php_mix == W.ORDER_MIX_DEFAULT,
      (php_mix, W.ORDER_MIX_DEFAULT))
check('the default mix adds up to 100', sum(W.ORDER_MIX_DEFAULT.values()) == 100)

# The template must actually use that definition — a copy pasted back in would pass every check above.
check('the form drives its labels and defaults from includes/meta_order.php',
      'metaOrderModeLabels()' in tpl and 'metaOrderShareLabels()' in tpl and 'metaOrderDefaultMix()' in tpl)

# The field NAMES, though, are spelled out rather than assembled at render time — the settings search
# finds a control by its name, and the panel suite checks every catalogue key is reachable on the
# page. A name built inside a PHP loop is invisible to that check, so a setting could quietly stop
# being findable with nothing failing. This is the check that keeps them written out.
for sel in W.ORDER_SELECTORS:
    check('the share field for "%s" is written out, not generated' % sel,
          'name="meta_order_mix_%s"' % sel in tpl)
check('the save path normalises through the same file', 'metaOrderNormalise(' in save)

print(chr(10) + '%d checks, %d failed' % (n, fails))
sys.exit(1 if fails else 0)
