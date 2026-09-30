<?php
/**
 * tools/groups.php — a seeded group's recommended permission set applied from the shell (1.72.0), run as the
 * operator runs it (a separate process) against the local test database:
 *
 *   php tests/groups_cli_test.php
 *
 * What it holds the tool to (the brief's words): a dry run changes nothing; `add` is idempotent and never removes;
 * `reset` removes only the extras and never a capability of the Admin group; `--consent` is the only way the Admin
 * group's consent is written; every group it changes is one `group.recommend` audit line, the panel's own shape,
 * attributed to the shell; and the permission memo is forgotten after a write (in this process, through the function
 * the tool and the panel share). Plus the usage answers and the guard that keeps the file off the web.
 *
 * Self-cleaning: every group's permissions are put back byte for byte, the audit lines it caused are deleted and the
 * account it makes is removed — in a shutdown function, so a run that dies half way leaves nothing behind either.
 * Never run beside a browser check (both write the groups).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/favourites.php';
require_once $root . '/includes/audit.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
/** The tool, as a process of its own: [exit code, stdout, stderr]. No shell: the arguments go as they are. */
function cli(array $args): array {
    global $root;
    $p = proc_open(array_merge([PHP_BINARY, $root . '/tools/groups.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($p)) return [255, '', 'proc_open failed'];
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfg;
check('the database is at schema 89 or later (the admin row written by the migration)', (int)($cfg['schema_version'] ?? 0) >= 89,
      (string)($cfg['schema_version'] ?? ''));

// ── what is put back, whatever happens ──────────────────────────────────────────────────────────────────────────
$groupsWere = $db->query("SELECT id, permissions FROM user_groups ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
register_shutdown_function(function () use ($db, $groupsWere, $auditFloor) {
    $up = $db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?");
    foreach ($groupsWere as $id => $json) $up->execute([$json, $id]);
    $db->prepare("DELETE FROM audit_log WHERE id > ? AND (action = 'group.recommend' OR target_type = 'group')")->execute([$auditFloor]);
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'gclitest\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $uid) userDeleteCascade($db, (int)$uid);
    userPermissionsForget();
});

$gid = fn(string $slug) => (int)$db->query("SELECT id FROM user_groups WHERE slug = " . $db->quote($slug))->fetchColumn();
$json = fn(string $slug) => (string)$db->query("SELECT permissions FROM user_groups WHERE slug = " . $db->quote($slug))->fetchColumn();
$held = function (string $slug) use ($json): array { $k = array_keys(userGroupPermissions($json($slug))); sort($k); return $k; };
$setIds = function (string $slug, array $ids) use ($db) {
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = ?")->execute([json_encode(array_fill_keys(array_values($ids), true), JSON_UNESCAPED_SLASHES), $slug]);
};
$audits = function () use ($db, $auditFloor): array {
    $st = $db->prepare("SELECT * FROM audit_log WHERE id > ? AND action = 'group.recommend' ORDER BY id");
    $st->execute([$auditFloor]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$sorted = function (array $a): array { $a = array_values(array_unique($a)); sort($a); return $a; };
$memberRec = userGroupRecommended('member');
$caps = userCapabilityPermissions();
$consent = userConsentPermissions();
foreach (['member', 'admin', 'guest', 'moderator', 'premium'] as $s) if ($gid($s) <= 0) { check("the seeded group $s exists", false); exit(1); }

/* ══ 1. list, and the usage answers ═══════════════════════════════════════════════════════════════════════════ */
[$c, $o] = cli(['list']);
check('list: exit 0, the registry counted, every group on a line, and whether the admin group stores every id',
      $c === 0 && str_contains($o, 'registered permissions: ' . count(userPermissionList()) . ' (' . count($caps) . ' capabilities, ' . count($consent) . ' consent')
      && preg_match('/^  member\s+Member\s/m', $o) && preg_match('/^  vip\s.*none \(a group of the operator\'s own\)$/m', $o) === (int)($gid('vip') > 0)
      && preg_match('/^admin stores every registered id: (yes|no)/m', $o) === 1, $o);
foreach ([
    [[], 2, 'no command'],
    [['frobnicate'], 2, 'an unknown command'],
    [['apply', '--mode=add'], 2, 'apply without --group or --all'],
    [['apply', '--all', '--group=member', '--mode=add'], 2, 'apply with both'],
    [['apply', '--all', '--mode=sideways'], 2, 'a mode that is neither add nor reset'],
    [['apply', '--all', '--mode=add', '--consent=yes'], 2, 'a value given to a flag'],
    [['diff', '--bogus'], 2, 'an unknown option'],
    [['diff', '--group=no-such-group'], 1, 'a group that does not exist'],
] as [$args, $want, $what]) {
    [$c, $o, $e] = cli($args);
    check("usage: $what answers $want, and writes nothing", $c === $want && $audits() === [], $c . ' ' . $e);
}
if ($gid('vip') > 0) {
    [$c, , $e] = cli(['apply', '--group=vip', '--mode=add']);
    check('a group of the operator\'s own has no recommended set: refused (1), untouched', $c === 1 && str_contains($e, 'no recommended set'), $e);
}

/* ══ 2. member: dry run, add (twice), reset ═══════════════════════════════════════════════════════════════════ */
// Two recommended ids missing, one the operator added by hand.
$setIds('member', array_merge(array_diff($memberRec, ['comment.reply', 'content.report']), ['shout.moderate']));
$before = $json('member');
[$c, $o] = cli(['apply', '--group=member', '--mode=reset', '--dry-run']);
check('a dry run says what it would do — the two to add, the one a reset takes — and exits 0',
      $c === 0 && str_contains($o, 'member: would add 2 (comment.reply content.report), would remove 1 (shout.moderate)')
      && str_contains($o, '(dry run: nothing written)'), $o);
check('… and changes nothing: the group byte for byte, no audit line', $json('member') === $before && $audits() === []);

[$c, $o] = cli(['apply', '--group=member', '--mode=add']);
$afterAdd = $held('member');
check('add: exit 0, prints exactly what it added', $c === 0 && str_contains($o, 'member: added 2 (comment.reply content.report)')
      && str_contains($o, 'done: 1 group changed, 0 unchanged'), $o);
check('… the two are there, and the operator\'s own id is still there: add never removes',
      in_array('comment.reply', $afterAdd, true) && in_array('content.report', $afterAdd, true) && in_array('shout.moderate', $afterAdd, true)
      && $afterAdd === $sorted(array_merge($memberRec, ['shout.moderate'])), implode(',', $afterAdd));
$a = $audits();
$d = $a ? json_decode((string)$a[0]['detail'], true) : [];
check('… one audit line: group.recommend under Users, the group as its target, attributed to the shell',
      count($a) === 1 && $a[0]['action_group'] === 'users' && $a[0]['target_type'] === 'group' && $a[0]['target_id'] === 'member'
      && $a[0]['actor_type'] === 'system' && str_starts_with((string)$a[0]['actor_name'], 'cli:') && (int)$a[0]['ok'] === 1
      && str_ends_with((string)$a[0]['summary'], ', from the shell'), json_encode($a));
check('… saying what was added and nothing removed — the very detail the panel writes (userGroupRecommendedAudit())',
      $d === userGroupRecommendedAudit(['id' => $gid('member'), 'slug' => 'member'], 'add', false, ['comment.reply', 'content.report'], [])['detail'],
      json_encode($d));

$before = $json('member');
[$c, $o] = cli(['apply', '--group=member', '--mode=add']);
check('add again: nothing to change, exit 0 — idempotent', $c === 0 && str_contains($o, 'member: nothing to change')
      && str_contains($o, 'done: 0 groups changed, 1 unchanged'), $o);
check('… not a byte written and no second audit line', $json('member') === $before && count($audits()) === 1);

[$c, $o] = cli(['apply', '--group=member', '--mode=reset']);
check('reset: removes exactly the extra, adds nothing (nothing is missing), exit 0',
      $c === 0 && str_contains($o, 'member: added 0 (nothing), removed 1 (shout.moderate)'), $o);
check('… the group is now the recommended set, exactly', $held('member') === $sorted($memberRec), implode(',', $held('member')));
$a = $audits();
$d = $a ? json_decode((string)end($a)['detail'], true) : [];
check('… one more audit line, a reset\'s', count($a) === 2 && ($d['mode'] ?? '') === 'reset' && ($d['removed'] ?? null) === ['shout.moderate']
      && ($d['added'] ?? null) === [], json_encode($d));
[$c, $o] = cli(['apply', '--group=member', '--mode=reset']);
check('reset again: nothing to change, no line', $c === 0 && str_contains($o, 'member: nothing to change') && count($audits()) === 2, $o);

/* ══ 3. admin: capabilities by the migration's rule, consent only by --consent, never a capability lost ═══════ */
$adminLacks = ['panel.audit.view', 'comment.moderate'];
$setIds('admin', array_merge(array_diff($caps, $adminLacks), ['favourites.public']));
[$c, $o] = cli(['diff', '--group=admin']);
check('diff on the admin group: the capabilities it lacks to add, its missing consent listed apart, a reset removes nothing',
      $c === 0 && str_contains($o, '   add: ' . implode(' ', userPermissionsOrdered($adminLacks)))
      && str_contains($o, '   consent, with --consent: ' . implode(' ', userPermissionsOrdered(array_diff($consent, ['favourites.public']))))
      && str_contains($o, '   a reset would also remove: nothing'), $o);
[$c, $o] = cli(['apply', '--group=admin', '--mode=reset']);
$adm = $held('admin');
check('reset on the admin group: every capability back, the consent it had kept, none it did not have given',
      $c === 0 && array_values(array_diff($caps, $adm)) === [] && in_array('favourites.public', $adm, true)
      && array_values(array_intersect($adm, $consent)) === ['favourites.public'], $o . ' | ' . implode(',', $adm));
check('… and the lines it printed name what it added and that it removed nothing',
      str_contains($o, 'admin: added 2 (' . implode(' ', userPermissionsOrdered($adminLacks)) . '), removed 0 (nothing)'), $o);
// A consent id is never an "extra" of the Admin group (its set is every id): a reset never takes one away either.
[$c, $o] = cli(['apply', '--group=admin', '--mode=reset']);
check('a second reset: nothing to change — the consent it holds stays', $c === 0 && str_contains($o, 'admin: nothing to change')
      && in_array('favourites.public', $held('admin'), true), $o);
[$c, $o] = cli(['apply', '--group=admin', '--mode=add', '--consent', '--dry-run']);
check('--consent in a dry run: the four consent ids it lacks, nothing written',
      $c === 0 && str_contains($o, 'admin: would add 4 (' . implode(' ', userPermissionsOrdered(array_diff($consent, ['favourites.public']))) . ')')
      && array_values(array_intersect($held('admin'), $consent)) === ['favourites.public'], $o);
[$c, $o] = cli(['apply', '--group=admin', '--mode=add', '--consent']);
$d = ($a = $audits()) ? json_decode((string)end($a)['detail'], true) : [];
check('--consent: the Admin group gives every consent too — every registered id is stored',
      $c === 0 && $held('admin') === $sorted(array_keys(userPermissionList())) && ($d['consent'] ?? null) === true
      && ($d['added'] ?? null) === userPermissionsOrdered(array_diff($consent, ['favourites.public'])), $o . ' | ' . json_encode($d));
[$c, $o] = cli(['list']);
check('… and list says so: "admin stores every registered id: yes"', $c === 0 && str_contains($o, 'admin stores every registered id: yes (' . count(userPermissionList()) . ')'), $o);

/* ══ 4. --all ═════════════════════════════════════════════════════════════════════════════════════════════════ */
[$c, $o] = cli(['apply', '--all', '--mode=add', '--dry-run']);
$lines = array_values(array_filter(explode("\n", trim($o)), fn($l) => preg_match('/^[a-z0-9_-]+: /', $l) && !str_starts_with($l, 'done: ')));
$slugsSeen = array_map(fn($l) => explode(':', $l)[0], $lines);
sort($slugsSeen);
$want = USER_RECOMMENDED_SLUGS;
sort($want);
check('--all: every seeded group, and only those (a group of the operator\'s own is not one)', $c === 0 && $slugsSeen === $want, $o);

/* ══ 5. in this process: the memo, the stale preview, the Admin group's guard ═════════════════════════════════ */
// An account in member, asked without the configuration: its memo key is the bare id, which PHP stores as an INTEGER
// key — the one 1.71.0's userPermissionsForget() could not match. Apply must make the next question see the change.
$mk = userCreate($db, array_merge($cfg, ['users_enabled' => '1']), 'gclitest_memo', 'gclitest_memo@example.org', 'Password123!', '127.0.0.1');
$uid = (int)($mk['user']['id'] ?? 0);
check('an account for the memo check', $uid > 0, json_encode($mk));
$db->prepare("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"comment.reply\"') WHERE slug = 'member'")->execute();
userPermissionsForget();
$p0 = userEffectivePermissions($db, $uid);
$r = userGroupApplyRecommended($db, $gid('member'), 'add');
$p1 = userEffectivePermissions($db, $uid);
check('apply forgets the permission memo (an integer key too): the same process sees the group\'s new set at once',
      empty($p0['comment.reply']) && ($r['changed'] ?? false) === true && ($r['added'] ?? null) === ['comment.reply'] && !empty($p1['comment.reply']),
      json_encode(['before' => isset($p0['comment.reply']), 'r' => $r['added'] ?? $r, 'after' => isset($p1['comment.reply'])]));
// What the panel sends: the preview it showed. A group changed since is not touched.
$db->prepare("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"comment.reply\"', '$.\"content.report\"') WHERE slug = 'member'")->execute();
$before = $json('member');
$r = userGroupApplyRecommended($db, $gid('member'), 'add', false, ['add' => ['comment.reply']]);
check('a stale preview (one id, where two are missing now) is refused as "changed", with the plan as it is now — nothing written',
      ($r['error'] ?? '') === 'changed' && ($r['plan']['add'] ?? null) === userPermissionsOrdered(['comment.reply', 'content.report']) && $json('member') === $before,
      json_encode($r));
$r = userGroupApplyRecommended($db, $gid('member'), 'add', false, ['add' => ['content.report', 'comment.reply']]);
check('… and the preview as it is now goes through (order does not matter)', ($r['changed'] ?? false) === true && $held('member') === $sorted($memberRec),
      json_encode($r['added'] ?? $r));
// A reset's preview has to have shown what it takes away: one that did not (an extra added meanwhile) is refused.
$setIds('member', array_merge($memberRec, ['shout.moderate']));
$before = $json('member');
$r = userGroupApplyRecommended($db, $gid('member'), 'reset', false, ['add' => []]);
check('a reset whose preview did not show the extra it would remove is refused ("changed"), nothing written',
      ($r['error'] ?? '') === 'changed' && ($r['plan']['remove'] ?? null) === ['shout.moderate'] && $json('member') === $before, json_encode($r));
$r = userGroupApplyRecommended($db, $gid('member'), 'reset', false, ['add' => [], 'remove' => ['shout.moderate']]);
check('… and with it shown, it removes exactly that', ($r['removed'] ?? null) === ['shout.moderate'] && $held('member') === $sorted($memberRec),
      json_encode($r['removed'] ?? $r));
check('an unknown mode and a group without a set are refused',
      (userGroupApplyRecommended($db, $gid('member'), 'sideways')['error'] ?? '') === 'bad_mode'
      && ($gid('vip') <= 0 || (userGroupApplyRecommended($db, $gid('vip'), 'add')['error'] ?? '') === 'no_recommended')
      && (userGroupApplyRecommended($db, 987654321, 'add')['error'] ?? '') === 'not_found');
// The Admin group cannot lose a capability — the plan never lists one, and the write refuses one regardless.
$setIds('admin', $caps);
$r = userGroupApplyRecommended($db, $gid('admin'), 'reset');
check('a reset of an admin group holding exactly its capabilities changes nothing (consent is never an extra)',
      ($r['changed'] ?? null) === false && $held('admin') === $sorted($caps), json_encode($r['removed'] ?? $r));

/* ══ 6. the file itself ═══════════════════════════════════════════════════════════════════════════════════════ */
$src = (string)file_get_contents($root . '/tools/groups.php');
check('the tool refuses to run anywhere but the command line (the first statement)',
      (bool)preg_match("/\\A<\\?php\\s*\\/\\*\\*.*?\\*\\/\\s*if \\(PHP_SAPI !== 'cli'\\) \\{ http_response_code\\(404\\); exit; \\}/s", $src));
check('… loads what the pages that read groups load (users.php, favourites.php) and writes through the shared function',
      str_contains($src, "require_once \$root . '/includes/users.php';") && str_contains($src, "require_once \$root . '/includes/favourites.php';")
      && str_contains($src, 'userGroupApplyRecommended(') && !preg_match('/UPDATE\s+user_groups/i', $src));
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
$body = @file_get_contents('http://127.0.0.1:8089/tools/groups.php?cmd=list', false, $ctx);
if ($body === false) {
    echo "NOTE the local site is not answering — the web half of the guard was not asked\n";
} else {
    $status = (int)preg_replace('/^HTTP\/\S+\s+(\d+).*$/', '$1', (string)($http_response_header[0] ?? ''));
    check('over the web the file answers 404 and says nothing', $status === 404 && !str_contains($body, 'registered permissions'),
          ($http_response_header[0] ?? '?') . ' ' . substr($body, 0, 120));
}

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
