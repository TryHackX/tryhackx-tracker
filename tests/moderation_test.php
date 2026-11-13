<?php
/**
 * ONE path for every punishment, the Moderation page, and the automatic penalties (1.75.0, includes/moderation.php):
 *   php tests/moderation_test.php
 *
 *   1. the schema: the new columns, keys and table, the seeded ladder (once, never given back), the grants (once; the
 *      rules permission to nobody but the Admin group), the permissions in the registry and the presets, the page in
 *      the panel's three lists;
 *   2. userModerate(): warn, remove, silence, ban and the lifts — loud and silent, every refusal (staff, yourself, a
 *      ban over a ban without a date, a reason, a text), the row each leaves (SILENT ones too, `loud` 0), the notices;
 *   3. warning NUMBERS: two moderators at the same moment never get the same one — a child process is held at the row
 *      lock while this process commits a warning, and then counts it;
 *   4. what counts (MODERATION_COUNTED): revoked, silent and automatic rows are in no number, no badge, no threshold;
 *      revoking (it stays, struck through; the member told or not; the running silence / ban lifted or not);
 *   5. the automatic penalties: the strongest rule, never shortens, an auto row does not cascade, "until lifted" is the
 *      2099 sentinel, a ban with no end date is never touched, an active ban skips the silence rules, staff are exempt
 *      (an unverified moderator too), the switch, a disabled rule, the notice and the audit line;
 *   6. the rules editor: validated, saved in place, the switch;
 *   7. the Moderation page's reads (restricted now, the log with its filters and keyset paging) and the endpoints, run
 *      as requests; the Users page's status filters and until-dates; the saveEdit bug (a save of the window no longer
 *      turns a timed ban dateless);
 *   8. the member's own standing (accountStandingContext());
 *   9. an account going: the moderator who revoked leaves a name, not an id; the source.
 *
 * Self-cleaning: its accounts (with their warnings and notifications), its group, its audit lines; the ladder's rules
 * are put back as they were.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/sounds.php', 'includes/shout.php',
          'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/auth.php', 'includes/icons.php',
          'includes/comments.php', 'includes/reports.php', 'includes/lang.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$GLOBALS['db'] = $db;
langInit([], 'en');
$cfgOn = array_merge($cfg, [
    'users_enabled' => '1', 'users_require_email_verify' => '1', 'default_language' => 'en', 'antispam_enabled' => '0',
    'recaptcha_enabled' => '0', 'comments_enabled' => '1', 'shout_enabled' => '1', 'pm_enabled' => '1',
    'auto_penalties_enabled' => '0',
]);
$cfgAuto = array_merge($cfgOn, ['auto_penalties_enabled' => '1']);
$GLOBALS['cfg'] = $cfgOn;

// ── what this run changes, and how it is put back ─────────────────────────────────────────────
const MT_USERS = ['mdtest_a', 'mdtest_b', 'mdtest_c', 'mdtest_d', 'mdtest_e', 'mdtest_f', 'mdtest_g', 'mdtest_h', 'mdtest_staff', 'mdtest_mod', 'mdtest_http', 'mdtest_race1', 'mdtest_race2'];
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$rulesBackup = $db->query("SELECT * FROM auto_penalty_rules ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$tmpFiles = [];
register_shutdown_function(function () use ($db, $auditFloor, &$tmpFiles, $rulesBackup) {
    foreach (MT_USERS as $name) {
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) userDeleteCascade($db, $id);
    }
    $db->exec("DELETE m FROM user_group_members m JOIN user_groups g ON g.id = m.group_id WHERE g.slug LIKE 'mdtest\\_%'");
    $db->exec("DELETE FROM user_groups WHERE slug LIKE 'mdtest\\_%'");
    $db->prepare("DELETE FROM audit_log WHERE id > ?")->execute([$auditFloor]);
    // the ladder as it was found (ids and all), the switch as it was
    $db->exec("DELETE FROM auto_penalty_rules");
    $ins = $db->prepare("INSERT INTO auto_penalty_rules (id, warnings, window_days, action, duration_days, enabled, sort) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($rulesBackup as $r) $ins->execute([$r['id'], $r['warnings'], $r['window_days'], $r['action'], $r['duration_days'], $r['enabled'], $r['sort']]);
    $db->exec("INSERT INTO settings (`key`, `value`) VALUES ('auto_penalties_enabled', '0') ON DUPLICATE KEY UPDATE `value` = '0'");
    foreach (['user_warnings'] as $t) {
        if (!(int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()) {
            try { $db->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1"); } catch (\Throwable $e) { /* not ours to insist */ }
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    foreach ($tmpFiles as $f) @unlink($f);
});

function mtUser(PDO $db, array $cfg, string $name, string $lang = 'en', bool $verified = true): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0) $db->prepare("UPDATE users SET email_verified = ?, language = ? WHERE id = ?")->execute([$verified ? 1 : 0, $lang, $id]);
    userPermissionsForget($id);
    return $id;
}
$notes = function (int $uid, int $floor = 0) use ($db): array {
    $st = $db->prepare("SELECT type, title, body, rich, rich_format, link FROM user_notifications WHERE user_id = ? AND id > ? ORDER BY id");
    $st->execute([$uid, $floor]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$noteFloor = fn(): int => (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM user_notifications")->fetchColumn();
$rowsOf = function (int $uid) use ($db): array {
    $st = $db->prepare("SELECT * FROM user_warnings WHERE user_id = ? ORDER BY id");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$usr = function (int $uid) use ($db): array {
    $st = $db->prepare("SELECT status, banned_until, pm_muted_until FROM users WHERE id = ?");
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$near = fn($col, int $seconds): bool => $col !== null && abs(strtotime((string)$col) - (time() + $seconds)) <= 8;
$mod = function (int $uid, string $action, array $more = [], ?array $cfgUse = null) use ($db, $cfgOn): array {
    return userModerate($db, $cfgUse ?? $cfgOn, $more + ['user_id' => $uid, 'action' => $action, 'by' => ['id' => null, 'name' => 'mdtest_mod']]);
};
$auditSince = function (string $action) use ($db, $auditFloor): array {
    $st = $db->prepare("SELECT action, action_group, summary, detail FROM audit_log WHERE id > ? AND action = ? ORDER BY id");
    $st->execute([$auditFloor, $action]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$setLadder = function (array $rules) use ($db): array {
    $db->exec("DELETE FROM auto_penalty_rules");
    $ins = $db->prepare("INSERT INTO auto_penalty_rules (warnings, window_days, action, duration_days, enabled, sort) VALUES (?, ?, ?, ?, ?, ?)");
    $ids = [];
    foreach ($rules as $i => [$w, $d, $a, $dur, $en]) { $ins->execute([$w, $d, $a, $dur, $en, ($i + 1) * 10]); $ids[] = (int)$db->lastInsertId(); }
    return $ids;
};

/* ══ 1. the schema, the registry, the page in the panel's lists ═══════════════════════════════════ */
check('the schema is at 94 or later, and its line says what Parts C + D added',
      TRACKER_SCHEMA_VERSION >= 94 && str_contains($src('includes/schema.php'), '94 = rich messages (1.75.0, includes/htmlsafe.php;')
      && str_contains($src('includes/schema.php'), '`auto_penalty_rules`') && str_contains($src('includes/schema.php'), 'v94_moderation'));
foreach (['loud', 'revoked_at', 'revoked_by_id', 'revoked_by_name', 'revoke_note', 'revoke_note_format'] as $col) {
    check("user_warnings.$col exists", schemaColumnExists($db, 'user_warnings', $col));
}
check('user_warnings.loud defaults to 1 (every old row was a warning the member was told of)', schemaColumnDefault($db, 'user_warnings', 'loud') === '1');
check('… the key on the revoker, and the key "Restricted now" asks the muted by', schemaIndexExists($db, 'user_warnings', 'idx_warn_revoked_by') && schemaIndexExists($db, 'users', 'idx_users_muted'));
check('auto_penalty_rules exists with its columns', schemaTableExists($db, 'auto_penalty_rules')
      && !array_filter(['warnings', 'window_days', 'action', 'duration_days', 'enabled', 'sort'], fn($c) => !schemaColumnExists($db, 'auto_penalty_rules', $c)));
check("the setting auto_penalties_enabled ships as '0'", (trackerSchemaDefaultSettings()['auto_penalties_enabled'] ?? null) === '0');

$perms = userPermissionList();
$three = ['panel.moderation.view', 'panel.moderation.handle', 'panel.moderation.rules'];
check('the three permissions are registered, with words in the dictionary', !array_filter($three, fn($p) => !isset($perms[$p])) && !array_filter($three, fn($p) => !langHas('perm.' . $p)));
$presets = userGroupPresets();
check('the moderator preset reads and handles; the rules are nobody\'s preset', in_array('panel.moderation.view', $presets['moderator']['perms'], true)
      && in_array('panel.moderation.handle', $presets['moderator']['perms'], true) && !in_array('panel.moderation.rules', $presets['moderator']['perms'], true));
check('the page is in the panel\'s three lists and its nav item asks panel.moderation.view',
      in_array('admin-moderation', adminReservedActions(), true) && in_array('admin-moderation', adminPanelActions(), true)
      && (function () { foreach (adminNavItems() as $i) if ($i['action'] === 'admin-moderation') return $i['perm'] === 'panel.moderation.view'; return false; })()
      && str_contains($src('index.php'), "\$action === 'admin-moderation'") && langHas('a.head.nav.admin_moderation'));
check('the three endpoints are routed and mapped to their permissions',
      str_contains($src('api.php'), "'admin/fetch_moderation'      => 'api/admin/fetch_moderation.php',")
      && str_contains($src('api.php'), "'admin/fetch_moderation'      => 'panel.moderation.view',")
      && str_contains($src('api.php'), "'admin/moderation_action'     => 'panel.moderation.handle',")
      && str_contains($src('api.php'), "'admin/moderation_rules'      => 'panel.moderation.rules',"));
check('the audit log files the four new actions under users', !array_filter(['user.autopenalty', 'user.lift', 'warning.revoke', 'autopenalty.rules'], fn($a) => auditGroupOf($a) !== 'users'));

// A scratch database: the seed (once), the grants (once), the upgrade of a v93 user_warnings.
$scratch = 'tracker_md_' . bin2hex(random_bytes(3));
$dsnPort = (string)($db->query('SELECT @@port')->fetchColumn() ?: '3306');
$base = 'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';port=' . $dsnPort . ';charset=utf8mb4';
try {
    $adm = new PDO($base, defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adm->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $sdb = new PDO("$base;dbname=$scratch", defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '',
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $sdb->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT, `updated_at` DATETIME NULL)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (trackerSchemaStatements() as $sql) $sdb->exec($sql);
        // an upgrade from 1.74: user_warnings without the new columns, no ladder yet
        $sdb->exec("ALTER TABLE user_warnings DROP COLUMN loud, DROP COLUMN revoked_at, DROP COLUMN revoked_by_id, DROP COLUMN revoked_by_name, DROP COLUMN revoke_note, DROP COLUMN revoke_note_format, DROP INDEX idx_warn_revoked_by");
        $sdb->exec("ALTER TABLE users DROP INDEX idx_users_muted");
        $sdb->exec("INSERT INTO user_warnings (user_id, reason, source_kind, action, by_name) VALUES (1, 'an old warning', 'comment', 'warn', 'someone')");
        $errs = [];
        foreach (trackerSchemaGuardedStatements($sdb) as $s) {
            $last = null;
            foreach ((is_array($s) ? $s : [$s]) as $t) { try { $sdb->exec($t); $last = null; break; } catch (\Throwable $e) { $last = $e; } }
            if ($last) $errs[] = $last->getMessage();
        }
        check('upgrade (a 1.74 database): the guarded statements add the columns and the keys, without an error', !$errs
              && !array_filter(['loud', 'revoked_at', 'revoked_by_id', 'revoked_by_name', 'revoke_note', 'revoke_note_format'], fn($c) => !schemaColumnExists($sdb, 'user_warnings', $c))
              && schemaIndexExists($sdb, 'user_warnings', 'idx_warn_revoked_by') && schemaIndexExists($sdb, 'users', 'idx_users_muted'), implode(' | ', $errs));
        check('… an old row is a loud, un-revoked warning — it counts, as it always did',
              (int)$sdb->query("SELECT loud FROM user_warnings")->fetchColumn() === 1 && $sdb->query("SELECT revoked_at FROM user_warnings")->fetchColumn() === null);
        $again = [];
        foreach (trackerSchemaGuardedStatements($sdb) as $s) if (is_string($s) && preg_match('/idx_users_muted|idx_warn_revoked_by|`loud`|`revoked_at`|`revoke_note/', $s) && str_starts_with($s, 'ALTER')) $again[] = $s;
        check('… and a second run has nothing more to alter on those two tables', !$again, implode(' | ', $again));

        $sdb->exec("INSERT IGNORE INTO user_groups (slug, name, description, priority, is_default, is_system, permissions) VALUES ('moderator', 'Moderator', 'm', 500, 0, 1, '{}')");
        $sdb->exec("UPDATE user_groups SET permissions = '{\"index.view\":true}' WHERE slug IN ('member','guest')");
        trackerSchemaDataMigrations($sdb, ['admin_username' => '']);
        $ladder = array_map(fn($r) => [(int)$r[0], (int)$r[1], (string)$r[2], (int)$r[3], (int)$r[4]],
                            $sdb->query("SELECT warnings, window_days, action, duration_days, enabled FROM auto_penalty_rules ORDER BY sort, id")->fetchAll(PDO::FETCH_NUM));
        check('the data migration seeds the suggested ladder: 3 in 30 → mute 3, 5 in 60 → mute 14, 7 in 90 → ban 30',
              $ladder === [[3, 30, 'mute', 3, 1], [5, 60, 'mute', 14, 1], [7, 90, 'ban', 30, 1]], json_encode($ladder));
        trackerSchemaDataMigrations($sdb, ['admin_username' => '']);
        check('… once: a second pass adds none', (int)$sdb->query("SELECT COUNT(*) FROM auto_penalty_rules")->fetchColumn() === 3);
        $sdb->exec("DELETE FROM auto_penalty_rules");
        trackerSchemaDataMigrations($sdb, ['admin_username' => '']);
        check('… and an operator who deleted every rule does not get them back', (int)$sdb->query("SELECT COUNT(*) FROM auto_penalty_rules")->fetchColumn() === 0);
        $pd = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'moderator'")->fetchColumn(), true) ?: [];
        $pm = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn(), true) ?: [];
        $pa = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'admin'")->fetchColumn(), true) ?: [];
        check('the grant (once): the moderator reads and handles; nobody else but the Admin group gets the rules; members nothing',
              !empty($pd['panel.moderation.view']) && !empty($pd['panel.moderation.handle']) && empty($pd['panel.moderation.rules'])
              && !array_filter($three, fn($p) => !empty($pm[$p])) && !array_filter($three, fn($p) => empty($pa[$p])), json_encode([$pd, $pa]));
        $sdb->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"panel.moderation.handle\"') WHERE slug = 'moderator'");
        trackerSchemaDataMigrations($sdb, ['admin_username' => '']);
        $pd = json_decode((string)$sdb->query("SELECT permissions FROM user_groups WHERE slug = 'moderator'")->fetchColumn(), true) ?: [];
        check('… ONCE: taken away afterwards, it is not given back', empty($pd['panel.moderation.handle']));
    } finally {
        $adm->exec("DROP DATABASE IF EXISTS `$scratch`");
    }
} catch (\Throwable $e) {
    check('the scratch database for the schema checks', false, $e->getMessage());
}

/* ══ 2. userModerate() ════════════════════════════════════════════════════════════════════════════ */
check('userWarn() is gone: a warning is a call of userModerate()', !function_exists('userWarn') && function_exists('userModerate') && function_exists('userRestrict') && function_exists('userLift'));
$a = mtUser($db, $cfgOn, 'mdtest_a');
$b = mtUser($db, $cfgOn, 'mdtest_b', 'pl');
$c = mtUser($db, $cfgOn, 'mdtest_c');
$f0 = $noteFloor();
$r = $mod($a, 'warn', ['reason' => 'Mind your words', 'src' => ['kind' => 'manual']]);
$rows = $rowsOf($a);
check('a loud warning: ok, a row (loud, manual, the moderator\'s name), counted as number 1', !empty($r['ok']) && $r['count'] === 1 && count($rows) === 1
      && (int)$rows[0]['loud'] === 1 && $rows[0]['source_kind'] === 'manual' && $rows[0]['action'] === 'warn' && $rows[0]['by_name'] === 'mdtest_mod' && $rows[0]['reason'] === 'Mind your words', json_encode($r));
$n1 = $notes($a, $f0);
check('… ONE notification of type warning, with the reason and the number, in the member\'s language',
      count($n1) === 1 && $n1[0]['type'] === 'warning' && $n1[0]['title'] === langFor('en', 'notify.warn_about_manual')
      && str_contains($n1[0]['body'], 'Mind your words') && str_contains($n1[0]['body'], 'number 1'), json_encode($n1));
$r = $mod($a, 'warn', ['reason' => 'Again']);
check('a second one is number 2', $r['count'] === 2 && str_contains($notes($a, $f0)[1]['body'] ?? '', 'number 2'));
$r = $mod($a, 'warn', ['reason' => '']);
check('a warning without a reason: refused (400 reason_required), nothing written', ($r['error'] ?? '') === 'reason_required' && $r['status'] === 400 && count($rowsOf($a)) === 2);
$r = $mod($a, 'warn', ['reason' => str_repeat('x', 2001)]);
check('a reason over 2 000 characters: refused as invalid_text with the sentence and the field', ($r['error'] ?? '') === 'invalid_text' && ($r['field'] ?? '') === 'reason' && !empty($r['message']), json_encode($r));
$r = $mod($a, 'dance');
check('an unknown action: 400', ($r['error'] ?? '') === 'unknown_action' && $r['status'] === 400);
$r = $mod(0, 'warn', ['reason' => 'x']);
check('no such account: 400 no_author', ($r['error'] ?? '') === 'no_author');

$f1 = $noteFloor();
$r = $mod($b, 'mute', ['days' => 1, 'loud' => false, 'reason' => 'quiet please']);
$u = $usr($b); $rows = $rowsOf($b);
check('a SILENT silence: the column is set (a day), the row is written with loud 0, nobody is told, no number moves',
      !empty($r['ok']) && $near($u['pm_muted_until'], 86400) && count($rows) === 1 && (int)$rows[0]['loud'] === 0 && $rows[0]['action'] === 'mute'
      && $notes($b, $f1) === [] && $r['count'] === 0 && $r['forever'] === false, json_encode([$r, $u]));
check('… and a silent row is no warning anywhere: summary count 0, but it is in the list, marked silent',
      userWarningsSummary($db, [$b])[$b]['count'] === 0 && userWarningsSummary($db, [$b])[$b]['latest'][0]['loud'] === false);
$r = $mod($b, 'mute', ['days' => 0, 'loud' => true, 'reason' => 'Until you calm down']);
$u = $usr($b);
check('a loud silence until lifted: the 2099 sentinel (never NULL), a warning titled "until a moderator lifts it"',
      $u['pm_muted_until'] === '2099-12-31 23:59:59' && $r['forever'] === true && $r['until'] === '2099-12-31 23:59:59'
      && ($notes($b, $f1)[0]['title'] ?? '') === langFor('pl', 'notify.warn_muted_forever'), json_encode($notes($b, $f1)));
$r = $mod($c, 'ban', ['days' => 7, 'loud' => true, 'reason' => 'A week out']);
$u = $usr($c);
$cw = array_values(array_filter($notes($c), fn($x) => $x['type'] === 'warning'));
check('a loud ban for 7 days: status banned, a date 7 days away, the warning says until when, the devices are signed out',
      $u['status'] === 'banned' && $near($u['banned_until'], 7 * 86400) && str_starts_with((string)($cw[0]['title'] ?? ''), 'Warning: your account is banned until ')
      && (int)$db->query("SELECT sessions_valid_from FROM users WHERE id = $c")->fetchColumn() > 0, json_encode([$u, $cw]));
$r = $mod($c, 'ban', ['days' => 3, 'loud' => false]);
check('a second ban re-dates the first (an explicit decision), silently', !empty($r['ok']) && $near($usr($c)['banned_until'], 3 * 86400));
// the owner's ban: no date
$db->prepare("UPDATE users SET status = 'banned', banned_until = NULL WHERE id = ?")->execute([$c]);
$r = $mod($c, 'ban', ['days' => 5]);
check('a ban over a ban with NO end date: 409 ban_is_permanent, nothing changed', ($r['error'] ?? '') === 'ban_is_permanent' && $r['status'] === 409 && $usr($c)['banned_until'] === null);
$r = $mod($c, 'unban');
check('its lift without the Users-page right: 403 ban_is_permanent', ($r['error'] ?? '') === 'ban_is_permanent' && $r['status'] === 403 && $usr($c)['status'] === 'banned');
$f2 = $noteFloor();
$r = $mod($c, 'unban', ['may_dateless' => true, 'loud' => true, 'reason' => 'Sorry about that']);
$nl = $notes($c, $f2);
check('… with it: lifted, and a LOUD lift says so plainly (an account notice, not a warning) with the moderator\'s note under it',
      !empty($r['ok']) && $usr($c)['status'] === 'active' && $usr($c)['banned_until'] === null && count($nl) === 1 && $nl[0]['type'] === 'account'
      && $nl[0]['title'] === langFor('en', 'notify.unbanned') && str_contains($nl[0]['body'], 'Sorry about that'), json_encode($nl));
$r = $mod($c, 'unban');
check('lifting what is not there: ok, a no-op', !empty($r['ok']) && $r['noop'] === true);
$f3 = $noteFloor();
$r = $mod($b, 'unmute', ['loud' => false]);
check('a SILENT lift: the column cleared, nobody told', !empty($r['ok']) && $usr($b)['pm_muted_until'] === null && $notes($b, $f3) === []);
$mod($b, 'mute', ['days' => 2, 'loud' => false]);
$f4 = $noteFloor();
$r = $mod($b, 'unmute', ['loud' => true, 'reason' => '[b]Back[/b] again', 'reason_format' => 'bbcode']);
$nl = $notes($b, $f4);
check('a loud lift with a BBCode note: the note travels as rich text under the plain lead-in',
      count($nl) === 1 && $nl[0]['rich_format'] === 'bbcode' && $nl[0]['rich'] === '[b]Back[/b] again' && str_contains($nl[0]['body'], langFor('pl', 'notify.report_reply_lead')), json_encode($nl));
check('the lifts are in the log, filed under users', count($auditSince('user.lift')) >= 3 && auditGroupOf('user.lift') === 'users');

// formatted reasons
$f5 = $noteFloor();
$r = $mod($a, 'warn', ['reason' => '[b]Read the rules[/b]', 'reason_format' => 'bbcode']);
$nl = $notes($a, $f5);
$last = $rowsOf($a)[count($rowsOf($a)) - 1];
check('a BBCode reason: kept raw with its format; the notification\'s body is the lead-ins and `rich` is the source',
      $last['reason_format'] === 'bbcode' && $last['reason'] === '[b]Read the rules[/b]' && $nl[0]['rich'] === '[b]Read the rules[/b]' && $nl[0]['rich_format'] === 'bbcode'
      && str_contains($nl[0]['body'], 'number 3') && !str_contains($nl[0]['body'], 'Read the rules'), json_encode($nl));

// the guards
$staff = mtUser($db, $cfgOn, 'mdtest_staff', 'en', false);      // an UNVERIFIED moderator: a guest to userIdHasPermission()
$db->prepare("INSERT INTO user_groups (slug, name, description, priority, is_default, is_system, permissions) VALUES ('mdtest_staff', 'mdtest staff', 'moderation_test', 3, 0, 0, ?)")
   ->execute([json_encode(['panel.access' => true])]);
$staffGid = (int)$db->lastInsertId();
userGrantGroup($db, $staff, $staffGid, null, 'test', 'moderation_test', false);
userPermissionsForget($staff);
check('an unverified moderator reads as a guest to the permission check — and as STAFF to moderationIsStaff()',
      !userIdHasPermission($db, $cfgOn, $staff, 'panel.access') && moderationIsStaff($db, $staff));
$r = $mod($staff, 'warn', ['reason' => 'x']);
check('staff cannot be warned, silenced or banned (the unverified one too): 403 target_is_staff', ($r['error'] ?? '') === 'target_is_staff' && $r['status'] === 403 && !$rowsOf($staff));
$r = $mod($staff, 'mute', ['days' => 1]);
check('… nor silenced silently', ($r['error'] ?? '') === 'target_is_staff');
$r = $mod($staff, 'remove', ['loud' => false]);
check('… but words of theirs may be removed silently (it changes nothing about the account, and is logged)', !empty($r['ok']) && count($rowsOf($staff)) === 1 && (int)$rowsOf($staff)[0]['loud'] === 0);
$_SESSION['admin_via_user'] = $a;
$r = $mod($a, 'warn', ['reason' => 'x']);
unset($_SESSION['admin_via_user']);
check('yourself: 403 target_is_you', ($r['error'] ?? '') === 'target_is_you');

/* ══ 3. warning NUMBERS cannot repeat ═════════════════════════════════════════════════════════════ */
$race = mtUser($db, $cfgOn, 'mdtest_race1');
$childRunner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'md_child_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $childRunner;
file_put_contents($childRunner, '<?php
chdir($argv[1]);
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/index.php", "includes/richtext.php", "includes/content.php", "includes/mail.php",
          "includes/users.php", "includes/favourites.php", "includes/usermedia.php", "includes/sounds.php", "includes/shout.php",
          "includes/emoji.php", "includes/people.php", "includes/audit.php", "includes/auth.php", "includes/comments.php",
          "includes/reports.php", "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), ["users_enabled" => "1", "users_require_email_verify" => "1", "default_language" => "en"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
langInit($cfg, null);
$m = userModerate($db, $cfg, ["user_id" => (int)$argv[2], "action" => "warn", "loud" => true, "reason" => "child " . $argv[3], "by" => ["id" => null, "name" => "mdtest_child"]]);
echo json_encode(["ok" => $m["ok"] ?? false, "count" => $m["count"] ?? null, "warning" => $m["warning"] ?? null, "error" => $m["error"] ?? null]);
');
$spawn = function (int $uid, string $tag) use ($childRunner, $root): array {
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', $childRunner, $root, (string)$uid, $tag], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return ['p' => $p, 'pipes' => $pipes];
};
$finish = function (array $h): array {
    $out = stream_get_contents($h['pipes'][1]);
    stream_get_contents($h['pipes'][2]);
    fclose($h['pipes'][1]); fclose($h['pipes'][2]);
    proc_close($h['p']);
    return json_decode(trim((string)$out), true) ?: ['__raw' => (string)$out];
};
// A moderator's transaction holds the member's row; the second moderator's request waits at the lock, and when the
// first commits a warning, the second counts it: number 2, not 1.
$db->beginTransaction();
$db->prepare("SELECT status, banned_until, pm_muted_until FROM users WHERE id = ? FOR UPDATE")->execute([$race]);
$h = $spawn($race, 'waits');
usleep(1500000);
$st = proc_get_status($h['p']);
check('a second moderator\'s warning WAITS while the first holds the member\'s row', $st['running'] === true);
$db->prepare("INSERT INTO user_warnings (user_id, reason, source_kind, action, loud, by_name) VALUES (?, 'the first moderator', 'manual', 'warn', 1, 'first')")->execute([$race]);
$db->commit();
$j = $finish($h);
check('… and counts what the first committed: it is warning number 2, never a second number 1', !empty($j['ok']) && ($j['count'] ?? 0) === 2, json_encode($j));
// two at once
$h1 = $spawn($race, 'one'); $h2 = $spawn($race, 'two');
$j1 = $finish($h1); $j2 = $finish($h2);
$counts = [(int)($j1['count'] ?? 0), (int)($j2['count'] ?? 0)];
sort($counts);
check('two moderators at the same moment get distinct numbers (3 and 4)', $counts === [3, 4], json_encode([$j1, $j2]));
check('… and the member has exactly those four warnings', moderationCount($db, $race) === 4 && count($rowsOf($race)) === 4);

/* ══ 4. what counts; revoking ═════════════════════════════════════════════════════════════════════ */
$d = mtUser($db, $cfgOn, 'mdtest_d');
$mod($d, 'warn', ['reason' => 'one']);
$mod($d, 'warn', ['reason' => 'two']);
$mod($d, 'mute', ['days' => 1, 'loud' => false]);
$db->prepare("INSERT INTO user_warnings (user_id, reason, source_kind, source_id, action, loud, by_name) VALUES (?, '3|30', 'auto', 1, 'mute', 1, '')")->execute([$d]);
$ws = userWarningsSummary($db, [$d]);
check('a warning that counts is loud, not revoked and not automatic: two warnings, a silent silence and an automatic penalty make 2',
      moderationCount($db, $d) === 2 && $ws[$d]['count'] === 2 && count($ws[$d]['latest']) === 3 && $ws[$d]['latest'][0]['auto'] === true
      && $ws[$d]['latest'][0]['auto_info'] === ['count' => 3, 'window' => 30], json_encode($ws));
$warn1 = (int)$rowsOf($d)[0]['id'];
$f6 = $noteFloor();
$_SESSION['loggedin'] = true; $_SESSION['admin_via_user'] = $staff;      // a panel session of a named moderator
$rv = userWarningRevoke($db, $cfgOn, $warn1, ['note' => 'Issued by mistake', 'tell' => true, 'may_dateless' => false]);
unset($_SESSION['loggedin'], $_SESSION['admin_via_user']);
$row1 = $db->query("SELECT * FROM user_warnings WHERE id = $warn1")->fetch(PDO::FETCH_ASSOC);
check('revoking: the row STAYS, stamped (when, by whom — name and id —, the note)', !empty($rv['ok']) && $row1['revoked_at'] !== null && $row1['revoked_by_name'] === 'mdtest_staff'
      && (int)$row1['revoked_by_id'] === $staff && $row1['revoke_note'] === 'Issued by mistake' && $row1['revoke_note_format'] === 'plain', json_encode([$rv, $row1]));
$nl = $notes($d, $f6);
check('… the member is told (a loud row), with the note; the count falls to 1 everywhere',
      $rv['told'] === true && count($nl) === 1 && $nl[0]['title'] === langFor('en', 'notify.warn_revoked') && str_contains($nl[0]['body'], 'Issued by mistake')
      && moderationCount($db, $d) === 1 && userWarningsSummary($db, [$d])[$d]['count'] === 1
      && reportAuthorStates($db, $cfgOn, [$d])[$d]['warnings'] === 1, json_encode($nl));
$ls = userWarningsSummary($db, [$d], 10)[$d]['latest'];
check('… and the revoked row is in the list, marked revoked', (bool)array_filter($ls, fn($w) => $w['revoked'] === true));
$rv2 = userWarningRevoke($db, $cfgOn, $warn1, []);
check('a second revoke: 409 already_revoked', ($rv2['error'] ?? '') === 'already_revoked' && $rv2['status'] === 409);
check('an unknown warning: 404', (userWarningRevoke($db, $cfgOn, 999999999, [])['error'] ?? '') === 'not_found');
$r = $mod($d, 'warn', ['reason' => 'three']);
check('the next warning is number 2 again — a revoked one does not count toward the number', $r['count'] === 2 && str_contains($notes($d)[count($notes($d)) - 1]['body'], 'number 2'));
// revoking the silent row: the member is not told (never told of it), and a running silence is lifted on request
$silent = (int)$db->query("SELECT id FROM user_warnings WHERE user_id = $d AND action = 'mute' AND loud = 0")->fetchColumn();
$f7 = $noteFloor();
$rv = userWarningRevoke($db, $cfgOn, $silent, ['tell' => true, 'lift_current' => true]);
check('revoking a SILENT row: nobody is told (they never were), and "also lift" ends the silence that runs', !empty($rv['ok']) && $rv['told'] === false && $rv['lifted']['mute'] === true
      && $usr($d)['pm_muted_until'] === null && $notes($d, $f7) === [], json_encode([$rv, $notes($d, $f7)]));
// the lift of a ban with no end date needs the Users-page right
$e = mtUser($db, $cfgOn, 'mdtest_e');
$mod($e, 'warn', ['reason' => 'x']);
$db->prepare("UPDATE users SET status = 'banned', banned_until = NULL WHERE id = ?")->execute([$e]);
$we = (int)$rowsOf($e)[0]['id'];
$rv = userWarningRevoke($db, $cfgOn, $we, ['lift_current' => true, 'may_dateless' => false]);
check('revoking with "also lift" over a ban with no end date: revoked, the ban kept, and it says why', !empty($rv['ok']) && $rv['lifted']['ban'] === false && $rv['lift_skipped'] === 'ban_is_permanent'
      && $usr($e)['status'] === 'banned');
check('the revocation is in the log: warning.revoke, in users', count($auditSince('warning.revoke')) === 3 && auditGroupOf('warning.revoke') === 'users');

/* ══ 5. the automatic penalties ═══════════════════════════════════════════════════════════════════ */
[$idA, $idB, $idC] = $setLadder([[3, 30, 'mute', 3, 1], [5, 60, 'mute', 14, 1], [7, 90, 'ban', 30, 1]]);
check('the ladder is read in order', array_column(moderationRules($db), 'id') === [$idA, $idB, $idC] && count(moderationRules($db, true)) === 3);
$g = mtUser($db, $cfgOn, 'mdtest_f');
$mod($g, 'warn', ['reason' => 'w1'], $cfgAuto);
$r = $mod($g, 'warn', ['reason' => 'w2'], $cfgAuto);
check('two counted warnings: no rule fires', $r['auto'] === null && $usr($g)['pm_muted_until'] === null);
$f8 = $noteFloor();
$r = $mod($g, 'warn', ['reason' => 'w3'], $cfgAuto);
$u = $usr($g); $rows = $rowsOf($g); $autoRow = $rows[count($rows) - 1];
check('the third fires the first rule: silenced for 3 days, by the rule (an auto row: kind auto, source_id the rule, loud, not by anybody)',
      $r['auto'] !== null && $r['auto']['rule'] === $idA && $r['auto']['action'] === 'mute' && $near($u['pm_muted_until'], 3 * 86400)
      && $autoRow['source_kind'] === 'auto' && (int)$autoRow['source_id'] === $idA && (int)$autoRow['loud'] === 1 && $autoRow['by_id'] === null && $autoRow['by_name'] === ''
      && $autoRow['reason'] === '3|30' && $autoRow['action'] === 'mute', json_encode([$r, $autoRow]));
$nl = $notes($g, $f8);
check('… the member got TWO notices: the warning, and the penalty in its own words ("3 warnings within 30 days")',
      count($nl) === 2 && $nl[1]['type'] === 'warning' && str_starts_with($nl[1]['title'], 'Automatic penalty: 3 warnings within 30 days — you are silenced until ')
      && $nl[1]['link'] === '?action=account#acc-card-standing', json_encode($nl));
check('… and the audit log has user.autopenalty (users group)', count($auditSince('user.autopenalty')) === 1 && auditGroupOf('user.autopenalty') === 'users');
check('… the automatic row counts nowhere: 3 warnings, not 4', moderationCount($db, $g) === 3 && $r['count'] === 3);
// a longer silence from elsewhere is never shortened; the auto row did not cascade (4 counted warnings, not 5)
$db->prepare("UPDATE users SET pm_muted_until = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', time() + 10 * 86400), $g]);
$longMute = $usr($g)['pm_muted_until'];
$r = $mod($g, 'warn', ['reason' => 'w4'], $cfgAuto);
check('the fourth: the first rule still matches but would END EARLIER than the 10 days the member has — never shortens; and the second rule (5) is not reached: the auto row did not count',
      $r['auto'] === null && $usr($g)['pm_muted_until'] === $longMute && $r['count'] === 4, json_encode($r));
$r = $mod($g, 'warn', ['reason' => 'w5'], $cfgAuto);
check('the fifth: the strongest matching rule (5 in 60 → 14 days) wins and is later than what the member has — applied', $r['auto'] !== null && $r['auto']['rule'] === $idB
      && $near($usr($g)['pm_muted_until'], 14 * 86400), json_encode($r));
$mod($g, 'warn', ['reason' => 'w6'], $cfgAuto);
$r = $mod($g, 'warn', ['reason' => 'w7'], $cfgAuto);
$u = $usr($g);
check('the seventh: the ban rule (a ban beats a silence): banned for 30 days, the silence left as it is', $r['auto'] !== null && $r['auto']['rule'] === $idC && $r['auto']['action'] === 'ban'
      && $u['status'] === 'banned' && $near($u['banned_until'], 30 * 86400) && $near($u['pm_muted_until'], 14 * 86400), json_encode([$r, $u]));
check('… banning signs the devices out', (int)$db->query("SELECT sessions_valid_from FROM users WHERE id = $g")->fetchColumn() > 0);
// switch off, a disabled rule
$h2u = mtUser($db, $cfgOn, 'mdtest_g');
foreach (['a', 'b', 'c', 'd'] as $i) $r = $mod($h2u, 'warn', ['reason' => $i], $cfgOn);       // the switch is off in $cfgOn
check('with the switch OFF nothing is applied however many warnings there are', $r['auto'] === null && $usr($h2u)['pm_muted_until'] === null && $r['count'] === 4);
$db->prepare("UPDATE auto_penalty_rules SET enabled = 0 WHERE id = ?")->execute([$idA]);
$r = $mod($h2u, 'warn', ['reason' => 'e'], $cfgAuto);
check('a DISABLED rule does not fire (the second one, 5 in 60, does)', $r['auto'] !== null && $r['auto']['rule'] === $idB);
$db->prepare("UPDATE auto_penalty_rules SET enabled = 1 WHERE id = ?")->execute([$idA]);

// the ones the ladder must leave alone
$h3 = mtUser($db, $cfgOn, 'mdtest_h');
for ($i = 0; $i < 3; $i++) $db->prepare("INSERT INTO user_warnings (user_id, reason, source_kind, action, loud, by_name) VALUES (?, 'x', 'manual', 'warn', 1, 'm')")->execute([$h3]);
$now = time();
$state = fn(int $uid) => moderationState($db->query("SELECT status, banned_until, pm_muted_until FROM users WHERE id = $uid")->fetch(PDO::FETCH_ASSOC), time());
$db->prepare("UPDATE users SET status = 'banned', banned_until = NULL WHERE id = ?")->execute([$h3]);
check('a ban with NO end date is never touched', moderationAutoEvaluate($db, $h3, $state($h3), $now) === null && $usr($h3)['banned_until'] === null);
$db->prepare("UPDATE users SET status = 'banned', banned_until = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $now + 10 * 86400), $h3]);
check('an active ban skips the silence rules', moderationAutoEvaluate($db, $h3, $state($h3), $now) === null && $usr($h3)['pm_muted_until'] === null);
for ($i = 0; $i < 4; $i++) $db->prepare("INSERT INTO user_warnings (user_id, reason, source_kind, action, loud, by_name) VALUES (?, 'x', 'manual', 'warn', 1, 'm')")->execute([$h3]);
$db->prepare("UPDATE users SET banned_until = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $now + 60 * 86400), $h3]);
check('a ban that already ends LATER than the ban rule is not shortened', moderationAutoEvaluate($db, $h3, $state($h3), $now) === null && $near($usr($h3)['banned_until'], 60 * 86400));
$db->prepare("UPDATE users SET banned_until = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $now + 5 * 86400), $h3]);
$x = moderationAutoEvaluate($db, $h3, $state($h3), $now);
check('… and one that ends earlier IS extended to the rule\'s 30 days', $x !== null && $x['action'] === 'ban' && $near($usr($h3)['banned_until'], 30 * 86400), json_encode($x));
for ($i = 0; $i < 7; $i++) $db->prepare("INSERT INTO user_warnings (user_id, reason, source_kind, action, loud, by_name) VALUES (?, 'x', 'manual', 'warn', 1, 'm')")->execute([$staff]);
check('staff are exempt at evaluation, asked again — an unverified moderator too', moderationAutoEvaluate($db, $staff, $state($staff), $now) === null && $usr($staff)['pm_muted_until'] === null);
// revoked and silent rows are no threshold; a window of days
$i1 = mtUser($db, $cfgOn, 'mdtest_race2');
$ins = $db->prepare("INSERT INTO user_warnings (user_id, reason, source_kind, action, loud, by_name, created_at, revoked_at) VALUES (?, 'x', 'manual', 'warn', ?, 'm', ?, ?)");
$ins->execute([$i1, 1, date('Y-m-d H:i:s', $now - 40 * 86400), null]);          // older than the 30-day window (but inside the 60-day one)
$ins->execute([$i1, 1, date('Y-m-d H:i:s', $now - 86400), date('Y-m-d H:i:s', $now)]);   // revoked
$ins->execute([$i1, 0, date('Y-m-d H:i:s', $now - 86400), null]);              // silent
$r = $mod($i1, 'warn', ['reason' => 'counted'], $cfgAuto);
check('revoked, silent and out-of-window rows are no threshold: 2 counted (one inside the window) fire nothing', $r['auto'] === null && $r['count'] === 2 && $usr($i1)['pm_muted_until'] === null, json_encode($r));

// "until lifted" is the sentinel, never NULL
$setLadder([[2, 30, 'mute', 0, 1]]);
$k = mtUser($db, $cfgOn, 'mdtest_http');
$f9 = $noteFloor();
$mod($k, 'warn', ['reason' => 'a'], $cfgAuto);
$r = $mod($k, 'warn', ['reason' => 'b'], $cfgAuto);
check('a rule with duration 0 silences until lifted: the 2099 sentinel, never NULL; the notice says "until a moderator lifts it"',
      $usr($k)['pm_muted_until'] === '2099-12-31 23:59:59' && $r['auto'] !== null && $r['auto']['until'] === null
      && str_contains(($notes($k, $f9)[count($notes($k, $f9)) - 1]['title'] ?? ''), 'until a moderator lifts it'), json_encode($notes($k, $f9)));
$r = $mod($k, 'warn', ['reason' => 'c'], $cfgAuto);
check('… and it does not fire again over an end that is already "until lifted" (not later)', $r['auto'] === null);

/* ══ 6. the rules editor ══════════════════════════════════════════════════════════════════════════ */
$good = ['rules' => [['id' => 0, 'warnings' => 2, 'window_days' => 7, 'action' => 'mute', 'duration_days' => 1, 'enabled' => 1],
                     ['id' => 0, 'warnings' => 4, 'window_days' => 14, 'action' => 'ban', 'duration_days' => 0, 'enabled' => 0]]];
$cl = moderationRulesClean($good['rules']);
check('a good ladder is accepted', isset($cl['rows']) && count($cl['rows']) === 2 && $cl['rows'][1]['enabled'] === 0);
foreach ([['warnings', 0, 'rule_warnings'], ['warnings', 101, 'rule_warnings'], ['window_days', 0, 'rule_window'], ['window_days', 366, 'rule_window'],
          ['duration_days', 366, 'rule_duration'], ['duration_days', -1, 'rule_duration'], ['action', 'kick', 'rule_action']] as [$field, $val, $code]) {
    $bad = $good['rules'];
    $bad[0][$field] = $val;
    $res = moderationRulesClean($bad);
    check("a rule with $field = " . json_encode($val) . " is refused ($code), naming the rule", ($res['error'] ?? '') === $code && ($res['row'] ?? -1) === 0, json_encode($res));
}
check('1–100 warnings, 1–365 days, 0–365 duration are the edges', isset(moderationRulesClean([['warnings' => 100, 'window_days' => 365, 'action' => 'ban', 'duration_days' => 365, 'enabled' => 1]])['rows'])
      && isset(moderationRulesClean([['warnings' => 1, 'window_days' => 1, 'action' => 'mute', 'duration_days' => 0, 'enabled' => 1]])['rows']));
check('not a list, or too many rules: refused', moderationRulesClean('x')['error'] === 'rules_invalid' && moderationRulesClean(array_fill(0, 21, $good['rules'][0]))['error'] === 'rules_too_many');
$before = array_column(moderationRules($db), 'id');
$keepId = $before[0];
$saved = moderationRulesSave($db, [['id' => $keepId, 'warnings' => 9, 'window_days' => 9, 'action' => 'ban', 'duration_days' => 9, 'enabled' => 1]] + [], true);
$after = moderationRules($db);
check('saving updates a rule IN PLACE (the penalties it gave keep pointing at it), deletes the ones left out, and sets the switch',
      count($after) === 1 && $after[0]['id'] === $keepId && $after[0]['warnings'] === 9 && $after[0]['action'] === 'ban' && $saved['enabled'] === '1'
      && $db->query("SELECT `value` FROM settings WHERE `key` = 'auto_penalties_enabled'")->fetchColumn() === '1');
moderationRulesSave($db, [['id' => $keepId, 'warnings' => 3, 'window_days' => 30, 'action' => 'mute', 'duration_days' => 3, 'enabled' => 1],
                          ['id' => 0, 'warnings' => 5, 'window_days' => 60, 'action' => 'mute', 'duration_days' => 14, 'enabled' => 1]], false);
$after = moderationRules($db);
check('a new rule is inserted after it; the order given is the order kept; the switch can go off again', count($after) === 2 && $after[0]['id'] === $keepId && $after[1]['warnings'] === 5
      && $db->query("SELECT `value` FROM settings WHERE `key` = 'auto_penalties_enabled'")->fetchColumn() === '0');

/* ══ 7. the page's reads, the endpoints as requests ═══════════════════════════════════════════════ */
$setLadder([[3, 30, 'mute', 3, 1], [5, 60, 'mute', 14, 1], [7, 90, 'ban', 30, 1]]);
$rs = moderationRestricted($db, $cfgOn);
$byName = [];
foreach ($rs['rows'] as $row) $byName[$row['name']] = $row;
check('Restricted now: the banned and the silenced, a ban with no end date flagged dateless, one with the 2099 date flagged forever',
      isset($byName['mdtest_e']['ban']) && $byName['mdtest_e']['ban']['dateless'] === true && isset($byName['mdtest_http']['mute']) && $byName['mdtest_http']['mute']['forever'] === true
      && isset($byName['mdtest_f']['ban'], $byName['mdtest_f']['mute']) && !isset($byName['mdtest_a']), implode(',', array_keys($byName)));
check('… each with what imposed it: who, the reason, automatic or not',
      ($byName['mdtest_http']['mute']['warning']['auto'] ?? null) === true && ($byName['mdtest_f']['ban']['warning']['auto'] ?? null) === true
      && ($byName['mdtest_f']['mute']['warning']['auto_info'] ?? null) !== null && $byName['mdtest_e']['ban']['warning'] === null);
$pg = moderationWarningsPage($db, $cfgOn, moderationFilters(['member' => 'mdtest_f']), 0, 3);
check('the log: a page of 3, newest first, with a cursor for the next', count($pg['rows']) === 3 && $pg['next'] === $pg['rows'][2]['id'] && $pg['rows'][0]['id'] > $pg['rows'][1]['id'] && $pg['total'] >= 9);
$pg2 = moderationWarningsPage($db, $cfgOn, moderationFilters(['member' => 'mdtest_f']), (int)$pg['next'], 3);
check('… and the next page continues after it (keyset), without a repeat', $pg2['rows'] && $pg2['rows'][0]['id'] < $pg['next'] && !array_intersect(array_column($pg['rows'], 'id'), array_column($pg2['rows'], 'id')));
$pgAuto = moderationWarningsPage($db, $cfgOn, moderationFilters(['action' => 'auto']), 0, 50);
check('the filter "automatic" lists only the penalties the rules gave', $pgAuto['rows'] && !array_filter($pgAuto['rows'], fn($w) => !$w['auto']));
$pgRev = moderationWarningsPage($db, $cfgOn, moderationFilters(['state' => 'revoked', 'member' => 'mdtest_d']), 0, 50);
check('… "revoked" only the revoked, with who revoked and the note', count($pgRev['rows']) === 2 && !array_filter($pgRev['rows'], fn($w) => !$w['revoked'])
      && (bool)array_filter($pgRev['rows'], fn($w) => $w['revoke_note'] === 'Issued by mistake' && $w['revoked_by'] === 'mdtest_staff'));
$pgMod = moderationWarningsPage($db, $cfgOn, moderationFilters(['moderator' => 'mdtest_mod', 'action' => 'mute', 'state' => 'active', 'from' => date('Y-m-d'), 'to' => date('Y-m-d')]), 0, 50);
check('… by moderator, action, state and date together', $pgMod['rows'] && !array_filter($pgMod['rows'], fn($w) => $w['action'] !== 'mute' || $w['by'] !== 'mdtest_mod' || $w['revoked']));
check('a filter that is not a filter is dropped (an action that is not one, a date that is not a date)', moderationFilters(['action' => 'x', 'state' => 'y', 'from' => '2026-13-45']) === ['member' => '', 'moderator' => '', 'action' => '', 'state' => '', 'from' => '', 'to' => '', 'user' => 0]);

// the endpoint files, as requests in a child process
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'md_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = "127.0.0.47";
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/index.php", "includes/richtext.php", "includes/content.php", "includes/mail.php",
          "includes/users.php", "includes/favourites.php", "includes/usermedia.php", "includes/sounds.php", "includes/shout.php",
          "includes/emoji.php", "includes/people.php", "includes/audit.php", "includes/auth.php", "includes/comments.php",
          "includes/reports.php", "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
$GLOBALS["__audit_endpoint"] = $a["endpoint"];
require $a["file"];
');
$GLOBALS['runner'] = $runner;
$run = function (string $endpoint, string $method, array $post, array $get, array $session) use ($root, &$tmpFiles, $cfgOn): array {
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'md_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmpFiles[] = $argFile;
    $cfgX = array_intersect_key($cfgOn, array_flip(['users_enabled', 'users_require_email_verify', 'default_language', 'antispam_enabled', 'recaptcha_enabled', 'comments_enabled', 'shout_enabled', 'pm_enabled']));
    file_put_contents($argFile, json_encode(['root' => $root, 'endpoint' => $endpoint, 'file' => $root . '/api/' . $endpoint . '.php', 'method' => $method,
        'post' => $post, 'get' => $get, 'session' => $session, 'cfg' => $cfgX, 'sid' => 'mdtest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($GLOBALS['runner']) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 600)];
};
$owner = ['loggedin' => true, 'admin_via_user' => 0, 'csrf_token' => 'md-token'];
$j = $run('admin/fetch_moderation', 'GET', [], ['tab' => 'restricted'], $owner);
check('fetch_moderation?tab=restricted as a request: the rows, and what the session may do', !empty($j['rows']) && $j['may_handle'] === true && $j['may_rules'] === true && $j['may_dateless'] === true, substr(json_encode($j), 0, 300));
$j = $run('admin/fetch_moderation', 'GET', [], ['tab' => 'warnings', 'member' => 'mdtest_d', 'state' => 'revoked'], $owner);
check('… tab=warnings with filters: the revoked ones of one member, the filters echoed', count($j['rows'] ?? []) === 2 && ($j['filters']['state'] ?? '') === 'revoked' && ($j['total'] ?? 0) === 2, substr(json_encode($j), 0, 300));
$j = $run('admin/fetch_moderation', 'GET', [], ['tab' => 'auto'], $owner);
check('… tab=auto: the rules, the switch, the log of the penalties', count($j['rules'] ?? []) === 3 && array_key_exists('enabled', $j) && !empty($j['rows']) && !array_filter($j['rows'], fn($w) => !$w['auto']), substr(json_encode($j), 0, 300));
// the acting endpoint
$ap = mtUser($db, $cfgOn, 'mdtest_http');
$j = $run('admin/moderation_action', 'POST', ['op' => 'apply', 'user_id' => $ap, 'action' => 'mute', 'days' => 2, 'loud' => false], [], $owner);
check('moderation_action apply as a request: a silent two-day silence, the row manual and silent', !empty($j['success']) && $near($usr($ap)['pm_muted_until'], 2 * 86400)
      && $rowsOf($ap)[0]['source_kind'] === 'manual' && (int)$rowsOf($ap)[0]['loud'] === 0, json_encode($j));
$j = $run('admin/moderation_action', 'POST', ['op' => 'apply', 'user_id' => $ap, 'action' => 'warn', 'reason' => ''], [], $owner);
check('… a warning without a reason: 400 reason_required', ($j['error'] ?? '') === 'reason_required' && empty($j['success']), json_encode($j));
$j = $run('admin/moderation_action', 'POST', ['op' => 'apply', 'user_id' => $staff, 'action' => 'warn', 'reason' => 'x'], [], $owner);
check('… staff: target_is_staff', ($j['error'] ?? '') === 'target_is_staff', json_encode($j));
$j = $run('admin/moderation_action', 'POST', ['op' => 'lift', 'user_id' => $ap, 'what' => 'mute', 'loud' => true, 'note' => 'Welcome back', 'note_format' => 'plain'], [], $owner);
check('moderation_action lift as a request: lifted, the member told with the note', !empty($j['success']) && $usr($ap)['pm_muted_until'] === null
      && str_contains((string)($notes($ap)[count($notes($ap)) - 1]['body'] ?? ''), 'Welcome back'), json_encode($j));
$wid = (int)$rowsOf($ap)[0]['id'];
$j = $run('admin/moderation_action', 'POST', ['op' => 'revoke', 'id' => $wid, 'note' => 'Not needed', 'tell' => true, 'lift_current' => false], [], $owner);
check('moderation_action revoke as a request: revoked; a silent row, so nobody is told', !empty($j['success']) && $j['told'] === false
      && $db->query("SELECT revoked_by_name FROM user_warnings WHERE id = $wid")->fetchColumn() === 'panel', json_encode($j));
$j = $run('admin/moderation_action', 'POST', ['op' => 'dance'], [], $owner);
check('… an unknown op: 400', ($j['error'] ?? '') === 'unknown_action');
// the rules' endpoint
$j = $run('admin/moderation_rules', 'POST', ['rules' => [['id' => 0, 'warnings' => 0, 'window_days' => 7, 'action' => 'mute', 'duration_days' => 1, 'enabled' => 1]], 'enabled' => true], [], $owner);
check('moderation_rules as a request: a bad rule is refused with its sentence and its row, nothing saved', ($j['error'] ?? '') === 'rule_warnings' && ($j['row'] ?? -1) === 0
      && !empty($j['message']) && count(moderationRules($db)) === 3, json_encode($j));
$j = $run('admin/moderation_rules', 'POST', ['rules' => [['id' => $idA, 'warnings' => 4, 'window_days' => 30, 'action' => 'mute', 'duration_days' => 2, 'enabled' => 1]], 'enabled' => true], [], $owner);
$audit = $auditSince('autopenalty.rules');
check('… a good one: saved, the switch on, and ONE audit line (autopenalty.rules, users) with the ladder before and after',
      !empty($j['success']) && count($j['rules']) === 1 && $j['enabled'] === true && count($audit) === 1 && auditGroupOf('autopenalty.rules') === 'users'
      && str_contains((string)$audit[0]['detail'], '"after":[[4,30,"mute",2,1]]'), json_encode([$j, $audit]));
$setLadder([[3, 30, 'mute', 3, 1], [5, 60, 'mute', 14, 1], [7, 90, 'ban', 30, 1]]);
$db->exec("INSERT INTO settings (`key`, `value`) VALUES ('auto_penalties_enabled', '0') ON DUPLICATE KEY UPDATE `value` = '0'");

// the Users page: the dates and the status filters; the saveEdit bug
$j = $run('admin/fetch_users', 'GET', [], ['status' => 'muted', 'search' => 'mdtest_'], $owner);
$names = array_column($j['rows'] ?? [], 'username');
check('fetch_users?status=muted: only the silenced, each with the end of it', in_array('mdtest_f', $names, true) && !in_array('mdtest_a', $names, true)
      && !array_filter($j['rows'] ?? [], fn($r) => empty($r['muted_until'])) && ($j['can_moderate'] ?? null) === true, implode(',', $names));
$j = $run('admin/fetch_users', 'GET', [], ['status' => 'warned', 'search' => 'mdtest_'], $owner);
$names = array_column($j['rows'] ?? [], 'username');
check('… status=warned: only those with a warning that COUNTS (a loud silence counts; the one whose only warning is revoked, and the one with only silent rows, do not)',
      in_array('mdtest_a', $names, true) && in_array('mdtest_f', $names, true) && in_array('mdtest_d', $names, true) && in_array('mdtest_b', $names, true) && !in_array('mdtest_e', $names, true), implode(',', $names));
$j = $run('admin/fetch_users', 'GET', [], ['status' => 'banned', 'search' => 'mdtest_f'], $owner);
check('… and a ban carries its date to the row', ($j['rows'][0]['status'] ?? '') === 'banned' && $near($j['rows'][0]['banned_until'] ?? null, 30 * 86400), substr(json_encode($j['rows'][0] ?? []), 0, 200));
$tb = mtUser($db, $cfgOn, 'mdtest_c');
$mod($tb, 'ban', ['days' => 3, 'loud' => false]);
$dateBefore = $usr($tb)['banned_until'];
$j = $run('admin/user_update', 'POST', ['id' => $tb, 'status' => 'banned', 'email' => 'mdtest_c_new@example.org'], [], $owner);
check('saving the edit window of a member under a TIMED ban (status sent unchanged): the address changes, the ban keeps its date',
      !empty($j['success']) && !in_array('status', $j['changed'] ?? [], true) && $usr($tb)['banned_until'] === $dateBefore, json_encode($j));
$j = $run('admin/user_update', 'POST', ['id' => $tb, 'status' => 'banned'], [], $owner);
check('… a save that changes nothing but resends the status: nothing_to_change, the date untouched', ($j['error'] ?? '') === __('api.users.nothing_to_change') && $usr($tb)['banned_until'] === $dateBefore, json_encode($j));
$j = $run('admin/user_update', 'POST', ['id' => $tb, 'status' => 'active'], [], $owner);
check('… and a CHANGE of status still works: active lifts the ban', !empty($j['success']) && $usr($tb)['status'] === 'active' && $usr($tb)['banned_until'] === null);
check('the window sends the status only when the moderator changed it', str_contains($src('assets/js/admin-users.js'), "if (\$('ue-status').value !== editUser.status) body.status = \$('ue-status').value;"));
// the address is a CHANGE too: writing it back marks it verified, so setting only a password verified an address nobody had confirmed
$mailOf = function (int $uid) use ($db): array {
    $st = $db->prepare("SELECT email, email_verified FROM users WHERE id = ?");
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$curMail = (string)$mailOf($tb)['email'];
$db->prepare("UPDATE users SET email_verified = 0 WHERE id = ?")->execute([$tb]);
$j = $run('admin/user_update', 'POST', ['id' => $tb, 'email' => $curMail, 'password' => 'Another-Pass-123!'], [], $owner);
check('a save that sets a password and resends the SAME address: the password changes, the address stays unverified',
      !empty($j['success']) && ($j['changed'] ?? []) === ['password'] && (int)$mailOf($tb)['email_verified'] === 0 && $mailOf($tb)['email'] === $curMail, json_encode($j));
$j = $run('admin/user_update', 'POST', ['id' => $tb, 'email' => $curMail . ' '], [], $owner);
check('… the same address (spaces trimmed) and nothing else: nothing_to_change, still unverified',
      ($j['error'] ?? '') === __('api.users.nothing_to_change') && (int)$mailOf($tb)['email_verified'] === 0, json_encode($j));
$j = $run('admin/user_update', 'POST', ['id' => $tb, 'email' => 'mdtest_c_newer@example.org'], [], $owner);
check('… and a CHANGED address is still written and counts as verified (the moderator vouches for it)',
      !empty($j['success']) && ($j['changed'] ?? []) === ['email'] && (int)$mailOf($tb)['email_verified'] === 1 && $mailOf($tb)['email'] === 'mdtest_c_newer@example.org', json_encode($j));
check('the window sends the address only when the moderator changed it, and sends nothing at all when nothing changed',
      str_contains($src('assets/js/admin-users.js'), "if (mail !== (editUser.email || '')) body.email = mail;")
      && str_contains($src('assets/js/admin-users.js'), "if (Object.keys(body).length === 1) {"));

/* ══ 8. the member's own standing ═════════════════════════════════════════════════════════════════ */
$clean = mtUser($db, $cfgOn, 'mdtest_race2');
check('a member in good standing: nothing to show', accountStandingContext($db, $cfgOn, $clean) === []);
$sd = $a;                                    // warned (3 counted: two plain, one BBCode), nothing running
$mod($sd, 'mute', ['days' => 4, 'loud' => false]);       // silent: not shown as a warning, but the silence runs
$st = accountStandingContext($db, $cfgOn, $sd);
check('the standing: the silence that runs (with its end), and the warnings that count, the reason rendered',
      isset($st['mute']['until']) && $st['mute']['forever'] === false && $st['count'] === 3 && count($st['warnings']) === 3
      && str_contains($st['warnings'][0]['html'], 'Read the rules') && !str_contains($st['warnings'][0]['html'], '[b]'), json_encode($st));
check('… no silent action and no moderator\'s name anywhere in it', !str_contains(json_encode($st), 'mdtest_mod') && !str_contains(json_encode($st), 'quiet please')
      && count(array_filter($st['warnings'], fn($w) => $w['action'] === 'mute')) === 0);
$rowsA = $rowsOf($a);
$db->prepare("UPDATE user_warnings SET revoked_at = NOW() WHERE id = ?")->execute([(int)$rowsA[0]['id']]);
check('a revoked warning is not shown to the member', accountStandingContext($db, $cfgOn, $a)['count'] === 2);
$stG = accountStandingContext($db, $cfgOn, $g);
check('an automatic penalty is shown as such, with the numbers, and does not count as a warning', (bool)array_filter($stG['warnings'], fn($w) => $w['auto'] !== null && $w['auto']['count'] >= 3)
      && $stG['count'] === 7, json_encode($stG['warnings'][0] ?? []));
$stBan = accountStandingContext($db, $cfgOn, $g);
check('a ban is part of the standing, with its end', isset($stBan['ban']['until']) && $stBan['ban']['forever'] === false);
$acc = $src('templates/pages/account.php');
check('the account page reads it with the SESSION\'S OWN id only, and says nothing of the table', str_contains($acc, 'accountStandingContext($db, $cfg, (int)$meUser[\'id\'])')
      && substr_count($acc, 'accountStandingContext(') === 1 && !str_contains($acc, 'user_warnings'));
$leak = [];
foreach (array_merge(glob($root . '/api/*.php'), glob($root . '/templates/pages/*.php'), glob($root . '/templates/partials/*.php')) as $fp) {
    $s = (string)file_get_contents($fp);
    if (str_contains($s, 'user_warnings') || str_contains($s, 'userWarningsSummary') || str_contains($s, 'moderationRowOut') || str_contains($s, 'moderationWarningsPage')) $leak[] = basename($fp);
}
check('no public endpoint or page reads the warnings, but through accountStandingContext()', $leak === [], implode(',', $leak));

/* ══ 9. an account going; the source ══════════════════════════════════════════════════════════════ */
$gone = userDeleteCascade($db, $staff);
check('a moderator who revoked a warning leaves: the revocation keeps their NAME and forgets the account',
      $db->query("SELECT revoked_by_id FROM user_warnings WHERE id = $warn1")->fetchColumn() === null
      && $db->query("SELECT revoked_by_name FROM user_warnings WHERE id = $warn1")->fetchColumn() === 'mdtest_staff' && ($gone['warnings_revoked_unlinked'] ?? 0) >= 1, json_encode($gone));
$goneD = userDeleteCascade($db, $d);
check('a member leaving: every row of theirs goes (the revoked, the silent, the automatic)', !(int)$db->query("SELECT COUNT(*) FROM user_warnings WHERE user_id = $d")->fetchColumn() && ($goneD['warnings'] ?? 0) >= 4, json_encode($goneD));
$lib = $src('includes/moderation.php');
check('the lock is the FIRST statement of the transaction, taken with FOR UPDATE, and the count comes after it',
      (bool)preg_match('/beginTransaction\(\);.*?SELECT status, banned_until, pm_muted_until FROM users WHERE id = \? FOR UPDATE.*?INSERT INTO user_warnings.*?moderationCount\(/s', $lib));
check('the two writes of a punishment\'s columns are userRestrict() and userLift() — and the cards no longer carry their own UPDATE of them',
      !str_contains($src('includes/reports.php'), 'UPDATE users SET pm_muted_until') && !str_contains($src('includes/reports.php'), "UPDATE users SET status = 'banned'")
      && !str_contains($src('api/admin/message_report_action.php'), 'UPDATE users SET') && substr_count($lib, 'UPDATE users SET pm_muted_until') === 2);
check('every caller ends on userModerate(): the content cards, the message card, the Moderation page',
      substr_count($src('includes/reports.php'), 'userModerate(') >= 2 && substr_count($src('api/admin/message_report_action.php'), 'userModerate(') >= 3
      && str_contains($src('api/admin/moderation_action.php'), 'userModerate('));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
