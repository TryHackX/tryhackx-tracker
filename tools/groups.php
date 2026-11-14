<?php
/**
 * The groups' RECOMMENDED permission sets from the shell (1.72.0) — the same plans, the same writes and the same
 * audit lines as Admin → Users → Groups → Recommended (includes/users.php, userGroupRecommended() and beside it):
 *
 *   sudo -u www-data php tools/groups.php list
 *   sudo -u www-data php tools/groups.php diff [--group=slug] [--consent]
 *   sudo -u www-data php tools/groups.php apply --group=slug|--all --mode=add|reset [--consent] [--dry-run]
 *   sudo -u www-data php tools/groups.php user <id|name>
 *
 * The recommended set of a seeded group is its preset (guest, member, premium, moderator) or, for admin, every
 * registered id. `add` writes what a group is missing of it and NEVER removes anything; `reset` also takes away what
 * is not in it — never a capability of the Admin group. `--consent` also gives the Admin group the consent ids
 * (content.public, favourites.public, lists.public, rating.public, uploads.public: what others may see of an
 * administrator, which the blanket does not count for and no migration grants). `--dry-run` says what would change
 * and writes nothing. Idempotent: a second run finds nothing to do, says so and writes nothing.
 *
 * Every group it changes is ONE `group.recommend` line in the panel's audit log — the panel's own line, attributed to
 * cli:<user> — and the permission memo is forgotten after each write (userGroupApplyRecommended()). `user` is
 * read-only: an account's groups, and for each consent id whether a group of theirs GRANTS it.
 *
 * AS THE WEB USER, as the other tools are run. Exit status: 0 done (nothing to do included), 1 refused or failed,
 * 2 usage.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!file_exists($root . '/config/installed.lock')) { fwrite(STDERR, "not installed\n"); exit(1); }
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
// What the pages that read groups load: a CLI asking a page's question must load what the page loads, or a
// function_exists() guard somewhere answers "no" without saying so (1.65.0's lesson).
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/favourites.php';
require_once $root . '/includes/audit.php';

$db  = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfg;

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$opts = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
$cmd = $args[0] ?? '';

$user = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (string)(posix_getpwuid(posix_geteuid())['name'] ?? get_current_user()) : get_current_user();
$actor = ['type' => 'system', 'id' => null, 'name' => mb_substr('cli:' . $user, 0, 64)];
$out = fn(string $s) => print($s . "\n");
$usage = function (string $why = ''): void {
    if ($why !== '') fwrite(STDERR, $why . "\n");
    fwrite(STDERR, "usage: php tools/groups.php list | diff [--group=slug] [--consent]"
        . " | apply --group=slug|--all --mode=add|reset [--consent] [--dry-run] | user <id|name>\n");
    exit(2);
};
$known = ['list' => [], 'diff' => ['group', 'consent'], 'apply' => ['group', 'all', 'mode', 'consent', 'dry-run'], 'user' => []];
if (!isset($known[$cmd])) $usage($cmd === '' ? '' : 'unknown command: ' . $cmd);
foreach (array_keys($opts) as $o) if (!in_array($o, $known[$cmd], true)) $usage('unknown option for ' . $cmd . ': --' . $o);
foreach (['consent', 'dry-run', 'all'] as $flag) if (isset($opts[$flag]) && $opts[$flag] !== true) $usage('--' . $flag . ' takes no value');

$reg = array_keys(userPermissionList());
$ids = fn(array $l): string => $l ? implode(' ', $l) : 'nothing';
/** Every group, the most senior first, with its member count — the same order as the panel's table. */
$groupsAll = function () use ($db): array {
    return $db->query("SELECT g.*, (SELECT COUNT(*) FROM user_group_members m WHERE m.group_id = g.id) AS members
                         FROM user_groups g ORDER BY g.priority DESC, g.name")->fetchAll(PDO::FETCH_ASSOC);
};
$bySlug = function (string $slug) use ($groupsAll): ?array {
    foreach ($groupsAll() as $g) if ((string)$g['slug'] === $slug) return $g;
    return null;
};
/** The groups a command is about: one by --group, or every group with a recommended set. */
$targets = function () use ($opts, $bySlug, $groupsAll, $usage): array {
    if (isset($opts['group'])) {
        if ($opts['group'] === true || $opts['group'] === '') $usage('--group needs a slug');
        $g = $bySlug((string)$opts['group']);
        if ($g === null) { fwrite(STDERR, 'no such group: ' . $opts['group'] . " (php tools/groups.php list)\n"); exit(1); }
        if (userGroupRecommended((string)$g['slug']) === null) {
            fwrite(STDERR, 'group ' . $g['slug'] . ' has no recommended set: only the seeded groups have one (' . implode(', ', USER_RECOMMENDED_SLUGS) . ")\n");
            exit(1);
        }
        return [$g];
    }
    return array_values(array_filter($groupsAll(), fn($g) => userGroupRecommended((string)$g['slug']) !== null));
};

switch ($cmd) {
    case 'list':
        $out(sprintf('registered permissions: %d (%d capabilities, %d consent: %s)', count($reg), count(userCapabilityPermissions()),
            count(userConsentPermissions()), implode(' ', userConsentPermissions())));
        $out(sprintf('  %-12s %-16s %5s  %-15s %7s  %-7s  %s', 'slug', 'name', 'prio', 'flags', 'members', 'holds', 'recommended set'));
        $adminLine = 'admin stores every registered id: no admin group';
        foreach ($groupsAll() as $g) {
            $held = array_keys(userGroupPermissions($g['permissions'] ?? null));
            $flags = trim(((int)$g['is_system'] ? 'system ' : '') . ((int)$g['is_default'] ? 'default' : ''));
            $plan = userGroupRecommendedPlan($g, false);
            if ($plan === null) {
                $rec = "none (a group of the operator's own)";
            } else {
                $rec = count($plan['recommended']) . ' — ' . (!$plan['add'] && !$plan['remove'] && !$plan['consent_add'] ? 'held exactly'
                     : 'missing ' . count($plan['add']) . ($plan['consent_add'] ? ' (+' . count($plan['consent_add']) . ' consent with --consent)' : '')
                       . ', a reset would remove ' . count($plan['remove']));
            }
            $out(sprintf('  %-12s %-16s %5d  %-15s %7d  %3d/%-3d  %s', (string)$g['slug'], mb_substr((string)$g['name'], 0, 16), (int)$g['priority'],
                $flags, (int)$g['members'], count($held), count($reg), $rec));
            if ((string)$g['slug'] === 'admin') {
                $missing = array_values(array_diff($reg, $held));
                $adminLine = 'admin stores every registered id: ' . ($missing ? 'no — missing ' . count($missing) . ': ' . implode(' ', $missing) : 'yes (' . count($reg) . ')');
            }
        }
        $out($adminLine);
        exit(0);

    case 'diff':
        $consent = isset($opts['consent']);
        foreach ($targets() as $g) {
            $plan = userGroupRecommendedPlan($g, $consent);
            $label = userGroupRecommendedLabel((string)$g['slug']);
            $out(sprintf('== %s (%s, %d member%s) — recommended: %s (%d)', $g['slug'], $g['name'], (int)$g['members'], (int)$g['members'] === 1 ? '' : 's',
                $label['label'], count($plan['recommended'])));
            $out('   add: ' . $ids($plan['add']));
            if ((string)$g['slug'] === 'admin' && !$consent && $plan['consent_add']) $out('   consent, with --consent: ' . $ids($plan['consent_add']));
            $out('   a reset would also remove: ' . $ids($plan['remove']));
        }
        exit(0);

    case 'apply':
        $mode = $opts['mode'] ?? null;
        if (!in_array($mode, ['add', 'reset'], true)) $usage('--mode=add or --mode=reset');
        if (isset($opts['all']) === isset($opts['group'])) $usage('one of --group=slug or --all');
        $consent = isset($opts['consent']);
        $dry = isset($opts['dry-run']);
        $changed = 0; $same = 0; $bad = 0;
        foreach ($targets() as $g) {
            $slug = (string)$g['slug'];
            if ($dry) {
                $plan = userGroupRecommendedPlan($g, $consent);
                $add = $plan['add']; $remove = $mode === 'reset' ? $plan['remove'] : [];
                if (!$add && !$remove) { $out($slug . ': nothing to change'); $same++; continue; }
                $out($slug . ': would add ' . count($add) . ' (' . $ids($add) . ')' . ($mode === 'reset' ? ', would remove ' . count($remove) . ' (' . $ids($remove) . ')' : ''));
                $changed++;
                continue;
            }
            $r = userGroupApplyRecommended($db, (int)$g['id'], (string)$mode, $consent);
            if (isset($r['error'])) {
                fwrite(STDERR, $slug . ': refused — ' . $r['error'] . ($r['error'] === 'changed' ? ' (the group was edited meanwhile; nothing written — run it again)' : '') . "\n");
                $bad++;
                continue;
            }
            if (!$r['changed']) { $out($slug . ': nothing to change'); $same++; continue; }
            $a = userGroupRecommendedAudit($r['group'], (string)$mode, $consent && $slug === 'admin', $r['added'], $r['removed']);
            auditLog($db, $a['action'], ['actor' => $actor, 'target_type' => $a['target_type'], 'target_id' => $a['target_id'],
                                          'summary' => $a['summary'] . ', from the shell', 'detail' => $a['detail']]);
            $out($slug . ': added ' . count($r['added']) . ' (' . $ids($r['added']) . ')' . ($mode === 'reset' ? ', removed ' . count($r['removed']) . ' (' . $ids($r['removed']) . ')' : ''));
            $changed++;
        }
        // The memo is forgotten by every write already; once more for whatever this process asks next.
        userPermissionsForget();
        $out(sprintf('done: %d group%s %s, %d unchanged%s%s', $changed, $changed === 1 ? '' : 's', $dry ? 'would change' : 'changed', $same,
            $bad ? ', ' . $bad . ' refused' : '', $dry ? ' (dry run: nothing written)' : ''));
        exit($bad ? 1 : 0);

    case 'user':
        $who = (string)($args[1] ?? '');
        if ($who === '') $usage('user needs an account id or name');
        if (ctype_digit($who)) {
            $u = userFindById($db, (int)$who);
        } else {
            $st = $db->prepare("SELECT * FROM users WHERE username = ?");
            $st->execute([$who]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$u) { fwrite(STDERR, 'no such account: ' . $who . "\n"); exit(1); }
        $uid = (int)$u['id'];
        $groups = userGroups($db, $uid);
        $out(sprintf('account %d (%s, e-mail %s) — groups in force: %s', $uid, (string)$u['status'],
            (int)($u['email_verified'] ?? 0) === 1 ? 'verified' : 'NOT verified', $groups ? implode(', ', array_column($groups, 'slug')) : 'none'));
        $out('accounts switched on (users_enabled): ' . (usersEnabled($cfg) ? 'yes' : 'NO — every answer below is the accounts-off default'));
        $parts = [];
        foreach (userConsentPermissions() as $p) {
            $from = [];
            foreach ($groups as $g) if (!empty(userGroupPermissions($g['permissions'] ?? null)[$p])) $from[] = (string)$g['slug'];
            $parts[] = $p . ': ' . (userIdHasGrantedPermission($db, $cfg, $uid, $p) ? 'yes (' . implode(', ', $from) . ')' : 'no');
        }
        $out('consent by a grant (the blanket does not count): ' . implode(' · ', $parts));
        $caps = userCapabilityPermissions();
        $held = count(array_filter($caps, fn($p) => userIdHasPermission($db, $cfg, $uid, $p)));
        $out(sprintf('capabilities in effect: %d of %d%s', $held, count($caps), in_array('admin', array_column($groups, 'slug'), true) ? ' (the Admin blanket)' : ''));
        exit(0);
}
