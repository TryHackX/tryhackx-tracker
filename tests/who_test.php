<?php
/**
 * "Who has this" in three sections — favourites, likes / ratings, lists (1.70.0, includes/who.php,
 * api/hash_who.php):
 *   php tests/who_test.php
 *
 * What the owner asked for, pinned: the overlay shows who has a torrent in their favourites, who liked or
 * rated it (with the vote), and the public lists it is on — each section loaded 20 at a time, paged and
 * searchable — "everything according to the users' preferences, the settings and the permissions".
 *
 *   1. schema 82 on BOTH paths (a scratch database built here and dropped): users.votes_listed, and the two
 *      pending-count keys on wl_content_edits;
 *   2. the two settings in their four places, and the words in both languages;
 *   3. who may open which section (whoSections()): the reader's account, each section's switches;
 *   4. the likes / ratings section's truth table, ONE flag at a time — the setting, rep_enabled, the likes on
 *      profiles, votes_listed, votes_public, the grant (and the administrator's blanket, which is not one),
 *      a suspended or unverified account, a membership not yet or no longer in force, a block, anonymous
 *      votes, the mode's values — each taking the member out of the rows AND the count;
 *   5. the lists section against the five list gates (and the account gates), by the same query
 *      listsContainingHash() now answers from — and (1.72.0) a list shared with friends: counted for the owner's
 *      friend only, either way round, and out again for a pending request, the friends feature off, the owner's
 *      friends.use gone, the section hidden, a block either way, the owner themselves, an unfriending;
 *   6. the favourites section's gates, unchanged;
 *   7. paging, clamping, the search (its wildcards literal), totals that equal the rows;
 *   8. EXPLAIN: each section drives through its hash's own index;
 *   9. the endpoint FILE and the 1.69.0 one and the privacy save, as requests in a child process (a session
 *      and its token): shapes, 404s, 400, 405, the rate limit, paging;
 *  10. the pages and scripts that carry it.
 *
 * Every switch a check leans on is set explicitly — nothing is inherited from this database's live settings.
 * Self-cleaning: the accounts, groups, votes, favourites and lists it makes, the admin group's JSON and the
 * rate-limit bucket it spends (a test address of its own) are put back or removed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/favourites.php';
require_once $root . '/includes/reputation.php';
require_once $root . '/includes/people.php';
require_once $root . '/includes/usermedia.php';
require_once $root . '/includes/profilevotes.php';
require_once $root . '/includes/lists.php';
require_once $root . '/includes/who.php';
require_once $root . '/includes/lang.php';
require_once $root . '/includes/settings_catalog.php';

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
    'users_enabled' => '1', 'users_require_email_verify' => '1', 'profiles_enabled' => '1',
    'fav_enabled' => '1', 'fav_public_enabled' => '1', 'fav_who_enabled' => '1',
    'rep_enabled' => '1', 'rep_mode' => 'thumbs', 'profile_votes_enabled' => '1', 'who_votes_enabled' => '1',
    'lists_enabled' => '1', 'lists_public_enabled' => '1', 'who_lists_enabled' => '1',
    // 1.72.0: a list shared with friends counts for a friend — the friends feature stated, not inherited.
    'friends_enabled' => '1',
]);
$GLOBALS['cfg'] = $cfgOn;
$cfgStars = array_merge($cfgOn, ['rep_mode' => 'stars']);

// ── what this run changes, and how it is put back ─────────────────────────────────────────────
$H  = str_repeat('f7f7', 10);   // the torrent everybody here has
$H2 = str_repeat('f6f6', 10);   // one nobody has
const WHO_TEST_IP = '127.0.0.78';
const WHO_TEST_IP_LIMIT = '127.0.0.79';
$adminBefore = (string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'admin'")->fetchColumn();
$tmpFiles = [];
$clean = function () use ($db, $H, $H2): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'whot\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
    $db->exec("DELETE FROM user_groups WHERE slug LIKE 'whot\\_%'");
    foreach ([$H, $H2] as $h) {
        $q = $db->quote($h);
        $db->exec("DELETE FROM hash_votes WHERE info_hash = $q");
        $db->exec("DELETE FROM user_favourites WHERE info_hash = $q");
        $db->exec("DELETE FROM user_list_items WHERE info_hash = $q");
    }
    // §8's bulk: other hashes, all under one prefix of this run's own.
    $db->exec("DELETE FROM hash_votes WHERE info_hash LIKE 'f5f5%'");
    $db->exec("DELETE FROM user_list_items WHERE info_hash LIKE 'f5f5%'");
};
$clean();
$rateClean = function (): void {
    // The bucket this run spent (its own addresses), taken back from every action's file it was counted in
    // (config/ratelimit/, one file and one lock per action since 1.74.0).
    rateLimitForgetWhere(fn(string $a, string $s): bool => $s === WHO_TEST_IP || $s === WHO_TEST_IP_LIMIT);
};
register_shutdown_function(function () use ($db, $clean, $adminBefore, &$tmpFiles, $rateClean) {
    $clean();
    if ($adminBefore !== '') $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'admin'")->execute([$adminBefore]);
    foreach ($tmpFiles as $f) @unlink($f);
    $rateClean();
});

$group = function (string $slug, array $perms) use ($db): int {
    $db->prepare("INSERT INTO user_groups (slug, name, description, color, priority, is_default, is_system, permissions) VALUES (?, ?, '', '', 2, 0, 0, ?)")
       ->execute([$slug, $slug, json_encode(array_fill_keys($perms, true), JSON_UNESCAPED_SLASHES)]);
    return (int)$db->lastInsertId();
};
/** A verified, active member in exactly ONE group, granted in the past (spelled out: see favourites_test 7b). */
$member = function (string $name, int $gid) use ($db, $cfgOn): int {
    $r = userCreate($db, $cfgOn, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    $db->prepare("UPDATE users SET email_verified = 1, status = 'active' WHERE id = ?")->execute([$id]);
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
    userGrantGroup($db, $id, $gid, null, 'test', 'who_test', false, '2000-01-01 00:00:00');
    return $id;
};
$moveTo = function (int $uid, int $gid, string $granted = '2000-01-01 00:00:00', ?string $expires = null) use ($db): void {
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$uid]);
    $db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at, expires_at) VALUES (?, ?, ?, ?)")->execute([$uid, $gid, $granted, $expires]);
    userPermissionsForget($uid);
};
$names = fn(array $r): array => array_map(fn($x) => $x['username'] ?? null, $r['rows'] ?? []);
$lnames = fn(array $r): array => array_map(fn($x) => $x['name'] ?? null, $r['rows'] ?? []);   // a list's own name
$all = ['page' => 1, 'per_page' => 50, 'search' => ''];

/* ══ 1. the schema, on both paths ═════════════════════════════════════════ */
check('the schema is at 82 or later', TRACKER_SCHEMA_VERSION >= 82 && (int)($cfg['schema_version'] ?? 0) >= 82, (string)($cfg['schema_version'] ?? ''));
$creates = implode("\n", array_filter(trackerSchemaStatements(), 'is_string'));
check('a fresh install\'s users table carries votes_listed TINYINT(1) NOT NULL DEFAULT 0 (right after votes_public)',
      str_contains($creates, "`votes_public` TINYINT(1) NOT NULL DEFAULT 0,") && str_contains($creates, '`votes_listed` TINYINT(1) NOT NULL DEFAULT 0')
      && strpos($creates, '`votes_listed`') > strpos($creates, '`votes_public`'));
check('… and wl_content_edits the two pending-count keys, one per home',
      str_contains($creates, 'KEY `idx_edits_wl_status` (`whitelist_id`, `status`)') && str_contains($creates, 'KEY `idx_edits_hc_status` (`hash_content_id`, `status`)'));
$schemaSrc = $src('includes/schema.php');
check('… and an upgraded one gets each from a guarded ALTER of the same shape',
      str_contains($schemaSrc, "if (!schemaColumnExists(\$db, 'users', 'votes_listed')) \$uparts[] = \"ADD COLUMN `votes_listed` TINYINT(1) NOT NULL DEFAULT 0\";")
      && str_contains($schemaSrc, "if (!schemaIndexExists(\$db, 'wl_content_edits', 'idx_edits_wl_status')) \$kparts[] = \"ADD KEY `idx_edits_wl_status` (`whitelist_id`, `status`)\";")
      && str_contains($schemaSrc, "if (!schemaIndexExists(\$db, 'wl_content_edits', 'idx_edits_hc_status')) \$kparts[] = \"ADD KEY `idx_edits_hc_status` (`hash_content_id`, `status`)\";"));
check('this database has them', schemaColumnExists($db, 'users', 'votes_listed') && schemaIndexExists($db, 'wl_content_edits', 'idx_edits_wl_status')
      && schemaIndexExists($db, 'wl_content_edits', 'idx_edits_hc_status'));
$scratch = 'tracker_who_' . bin2hex(random_bytes(3));
$dsnPort = (string)($db->query('SELECT @@port')->fetchColumn() ?: '3306');
$base = 'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';port=' . $dsnPort . ';charset=utf8mb4';
try {
    $adm = new PDO($base, defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adm->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $sdb = new PDO("$base;dbname=$scratch", defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '',
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $sdb->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        $runAll = function () use ($sdb): void { foreach (trackerSchemaGuardedStatements($sdb) as $s) $sdb->exec(is_array($s) ? end($s) : $s); };
        foreach (trackerSchemaStatements() as $s) $sdb->exec($s);
        $runAll();
        $col = fn() => $sdb->query("SHOW COLUMNS FROM users LIKE 'votes_listed'")->fetch() ?: [];
        $keyCols = fn(string $k) => implode(',', array_column(array_filter($sdb->query("SHOW INDEX FROM wl_content_edits")->fetchAll(), fn($r) => $r['Key_name'] === $k), 'Column_name'));
        $c = $col();
        check('fresh path: users.votes_listed tinyint(1), NOT NULL, default 0; the keys on (whitelist_id, status) and (hash_content_id, status)',
              strtolower((string)($c['Type'] ?? '')) === 'tinyint(1)' && ($c['Null'] ?? '') === 'NO' && (string)($c['Default'] ?? '') === '0'
              && $keyCols('idx_edits_wl_status') === 'whitelist_id,status' && $keyCols('idx_edits_hc_status') === 'hash_content_id,status', json_encode($c));
        // The upgrade path: an 81-shaped install, holding fifteen replaced versions of one description.
        $sdb->exec("ALTER TABLE users DROP COLUMN votes_listed");
        $sdb->exec("ALTER TABLE wl_content_edits DROP KEY idx_edits_wl_status, DROP KEY idx_edits_hc_status");
        $sdb->exec("INSERT INTO users (id, username, pass_hash, votes_public) VALUES (5, 'old', 'x', 1)");
        for ($i = 0; $i < 15; $i++) {
            $sdb->exec("INSERT INTO wl_content_edits (whitelist_id, info_hash, description, status, note) VALUES (1, '" . str_repeat('ab', 20) . "', 'v$i', 'rejected', 'replaced by a later proposal')");
        }
        $asked = array_values(array_filter(trackerSchemaGuardedStatements($sdb), fn($s) => is_string($s) && (str_contains($s, '`votes_listed`') || str_contains($s, 'idx_edits_wl_status') || str_contains($s, 'idx_edits_hc_status'))));
        $runAll();
        $c = $col();
        check('upgrade path: the guarded statements put the column and both keys back, the same shape',
              count($asked) === 2 && strtolower((string)($c['Type'] ?? '')) === 'tinyint(1)' && (string)($c['Default'] ?? '') === '0'
              && $keyCols('idx_edits_wl_status') === 'whitelist_id,status' && $keyCols('idx_edits_hc_status') === 'hash_content_id,status', json_encode($asked));
        check('… an existing account is named nowhere (votes_listed 0 even with votes_public 1)',
              (int)$sdb->query("SELECT votes_listed FROM users WHERE id = 5")->fetchColumn() === 0);
        check('… the migration deletes none of the kept versions (fifteen before, fifteen after): they are trimmed per description on apply',
              (int)$sdb->query("SELECT COUNT(*) FROM wl_content_edits WHERE note = 'replaced by a later proposal'")->fetchColumn() === 15);
        check('… and once it is there nothing is asked again',
              !array_filter(trackerSchemaGuardedStatements($sdb), fn($s) => is_string($s) && (str_contains($s, '`votes_listed`') || str_contains($s, 'idx_edits_wl_status') || str_contains($s, 'idx_edits_hc_status'))));
    } finally {
        $adm->exec("DROP DATABASE IF EXISTS `$scratch`");
    }
} catch (\Throwable $e) {
    check('a scratch database could be built for the two schema paths', false, $e->getMessage());
}

/* ══ 2. the two settings, in their four places ════════════════════════════ */
$defaults = trackerSchemaDefaultSettings();
check('who_votes_enabled and who_lists_enabled ship ON', ($defaults['who_votes_enabled'] ?? null) === '1' && ($defaults['who_lists_enabled'] ?? null) === '1');
$save = $src('api/admin/save_settings.php');
check('… in the save allow-list, and coerced to 0/1 with the other switches',
      str_contains($save, "    'who_votes_enabled', 'who_lists_enabled',\n") && str_contains($save, "          'who_votes_enabled', 'who_lists_enabled',\n          'shout_emotes_enabled',"));
$tpl = $src('templates/admin/settings.php');
$secOf = function (string $id) use ($tpl): string {
    $p = strpos($tpl, 'id="' . $id . '"');
    if ($p === false) return '';
    $e = strpos($tpl, 'class="settings-section"', $p + 20);
    return substr($tpl, $p, ($e === false ? strlen($tpl) : $e) - $p);
};
check('… each a control in its feature\'s section of Settings → Profiles: the likes\' and the lists\'',
      str_contains($secOf('section-profile-votes'), 'name="who_votes_enabled"') && str_contains($secOf('section-profile-votes'), 'data-setting="who_votes_enabled"')
      && str_contains($secOf('section-lists'), 'name="who_lists_enabled"') && str_contains($secOf('section-lists'), 'data-setting="who_lists_enabled"')
      && str_contains($secOf('section-profile-votes'), "__('settings.who_votes_enabled_hint')") && str_contains($secOf('section-lists'), "__('settings.who_lists_enabled_hint')"));
$kw = settingsCatalogKeywords();
check('… with the Settings search\'s words, in both languages', str_contains((string)($kw['who_votes_enabled'] ?? ''), 'who has this')
      && str_contains((string)($kw['who_votes_enabled'] ?? ''), 'polubienia') && str_contains((string)($kw['who_lists_enabled'] ?? ''), 'listy'));
$en = include $root . '/lang/en.php';
$pl = include $root . '/lang/pl.php';
$words = ['who.fav', 'who.votes_thumbs', 'who.votes_stars', 'who.lists', 'who.search_fav', 'who.search_votes', 'who.search_lists', 'who.why',
          'js.who.people_n', 'js.who.people_one', 'js.who.lists_n', 'js.who.lists_one', 'js.who.found', 'js.who.more', 'js.who.no_match',
          'js.who.fav_none', 'js.who.votes_none_thumbs', 'js.who.votes_none_stars', 'js.who.lists_none', 'js.who.rate_limited',
          'settings.who_votes_enabled', 'settings.who_votes_enabled_hint', 'settings.who_lists_enabled', 'settings.who_lists_enabled_hint',
          'account.votes_listed_label_thumbs', 'account.votes_listed_label_stars', 'account.votes_listed_hint_thumbs', 'account.votes_listed_hint_stars'];
$missing = array_filter($words, fn($k) => trim((string)($en[$k] ?? '')) === '' || trim((string)($pl[$k] ?? '')) === '');
$same = array_filter($words, fn($k) => ($en[$k] ?? '') === ($pl[$k] ?? '') && !in_array($k, ['js.who.people_one', 'js.who.lists_one'], true));
check('every word of it in both languages, and the Polish is Polish', !$missing && !$same, json_encode([array_values($missing), array_values($same)]));
check('… the sections named as the site names them: Likes / Polubienia, Ratings / Oceny, Lists / Listy; counts Polish need not agree with',
      $en['who.votes_thumbs'] === 'Likes' && $pl['who.votes_thumbs'] === 'Polubienia' && $en['who.votes_stars'] === 'Ratings' && $pl['who.votes_stars'] === 'Oceny'
      && $pl['who.lists'] === 'Listy' && $pl['js.who.people_n'] === 'Liczba osób: :n' && $pl['js.who.lists_n'] === 'Liczba list: :n');
check('… and the dead 1.69.0 words are gone (the head count, "none", "why", the lists\' old heading)',
      !isset($en['js.fav.who_count'], $en['js.fav.who_one'], $en['js.fav.who_none'], $en['js.fav.who_why'], $en['js.lists.who_head']));

/* ══ fixtures ═════════════════════════════════════════════════════════════ */
$gGrant  = $group('whot_grant', ['favourites.use', 'favourites.public', 'favourites.view_others', 'rating.vote', 'rating.public',
                                 'lists.use', 'lists.public', 'index.view']);
$gBare   = $group('whot_bare', ['favourites.use', 'favourites.view_others', 'rating.vote', 'lists.use', 'index.view']);
$gReader = $group('whot_reader', ['index.view', 'favourites.view_others']);
$gBlind  = $group('whot_blind', ['index.view']);
$gNoIdx  = $group('whot_noidx', ['favourites.view_others']);
$M = [];
for ($i = 1; $i <= 25; $i++) $M[$i] = $member(sprintf('whot_m%02d', $i), $gGrant);
$S1 = $member('whot_s1', $gGrant);
$S2 = $member('whot_s2', $gGrant);
$reader = $member('whot_reader', $gReader);
$blind  = $member('whot_blind', $gBlind);
$noIdx  = $member('whot_noidx', $gNoIdx);
$everyone = array_merge(array_values($M), [$S1, $S2]);
$db->exec("UPDATE users SET fav_public = 1, fav_listed = 1, votes_public = 1, votes_listed = 1, lists_public = 1 WHERE id IN (" . implode(',', $everyone) . ")");
$vIns = $db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote) VALUES (?, 'user', ?, ?)");
$fIns = $db->prepare("INSERT INTO user_favourites (user_id, info_hash) VALUES (?, ?)");
$lIns = $db->prepare("INSERT INTO user_lists (user_id, name, slug, visibility, created_at, updated_at) VALUES (?, ?, ?, 'public', '2026-01-01 00:00:00', ?)");
$iIns = $db->prepare("INSERT INTO user_list_items (list_id, info_hash, name) VALUES (?, ?, 'Who fixture')");
$L = [];
foreach ($M as $i => $uid) {
    $vIns->execute([$H, (string)$uid, $i <= 20 ? 1 : -1]);
    $fIns->execute([$uid, $H]);
    // Each list a minute apart, so "most recently changed first" has one answer: Pack 25 first.
    $lIns->execute([$uid, sprintf('Pack %02d', $i), sprintf('pack-%02d', $i), sprintf('2026-02-01 00:%02d:00', $i)]);
    $L[$i] = (int)$db->lastInsertId();
    $iIns->execute([$L[$i], $H]);
}
$vIns->execute([$H, (string)$S1, 7]);    // three and a half stars: a star vote, no thumb
$vIns->execute([$H, (string)$S2, 10]);   // five stars
// Votes cast from an address: nobody's, and never listed.
$db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote) VALUES (?, 'ip', '198.51.100.7', 1), (?, 'ip', '198.51.100.8', -1)")->execute([$H, $H]);
userPermissionsForget(0);
check('fixtures: 25 members who said yes to everything, two star voters, three readers, the hash\'s rows',
      count($M) === 25 && $S1 && $S2 && $reader && $blind && $noIdx
      && (int)$db->query("SELECT COUNT(*) FROM hash_votes WHERE info_hash = " . $db->quote($H))->fetchColumn() === 29);

/* ══ 3. who may open which section ════════════════════════════════════════ */
$R = userFindById($db, $reader);
$secs = fn(array $c, ?array $v) => whoSections($db, $c, $v);
check('a reader whose account holds index.view and favourites.view_others: all three sections',
      $secs($cfgOn, $R) === ['fav' => true, 'votes' => true, 'lists' => true], json_encode($secs($cfgOn, $R)));
check('nobody signed in: none', $secs($cfgOn, null) === ['fav' => false, 'votes' => false, 'lists' => false]);
check('an account without favourites.view_others, or without index.view: none',
      !in_array(true, $secs($cfgOn, userFindById($db, $blind)), true) && !in_array(true, $secs($cfgOn, userFindById($db, $noIdx)), true));
$only = function (array $over) use ($secs, $cfgOn, $R): string {
    return implode(',', array_keys(array_filter($secs(array_merge($cfgOn, $over), $R))));
};
check('each switch closes its own section and no other',
      $only(['fav_who_enabled' => '0']) === 'votes,lists' && $only(['fav_public_enabled' => '0']) === 'votes,lists'
      && $only(['who_votes_enabled' => '0']) === 'fav,lists' && $only(['rep_enabled' => '0']) === 'fav,lists'
      && $only(['profile_votes_enabled' => '0']) === 'fav,lists'
      && $only(['who_lists_enabled' => '0']) === 'fav,votes' && $only(['lists_public_enabled' => '0']) === 'fav,votes' && $only(['lists_enabled' => '0']) === 'fav,votes',
      json_encode([$only(['fav_who_enabled' => '0']), $only(['who_votes_enabled' => '0']), $only(['who_lists_enabled' => '0'])]));
check('… accounts switched off: none at all', $only(['users_enabled' => '0']) === '');
check('the likes section needs ratings, the likes on profiles and its own switch; the lists section public lists and its own',
      whoVotesEnabled($cfgOn) && !whoVotesEnabled(array_merge($cfgOn, ['who_votes_enabled' => '0'])) && !whoVotesEnabled(array_merge($cfgOn, ['rep_enabled' => '0']))
      && whoListsEnabled($cfgOn) && !whoListsEnabled(array_merge($cfgOn, ['who_lists_enabled' => '0'])) && !whoListsEnabled(array_merge($cfgOn, ['lists_public_enabled' => '0'])));

/* ══ 4. likes / ratings: one flag at a time ═══════════════════════════════ */
$votes = fn(?array $c = null, int $viewer = 0, ?array $p = null) => whoVotesPage($db, $c ?? $cfgOn, $H, $viewer ?: $reader, $p ?? $all);
$m01 = 'whot_m01';
$base = $votes();
check('thumbs: the 25 members who said yes, and not the two star votes (7, 10), nor the two votes cast from an address',
      $base['total'] === 25 && count($base['rows']) === 25 && in_array($m01, $names($base), true)
      && !in_array('whot_s1', $names($base), true) && !in_array('whot_s2', $names($base), true) && $base['mode'] === 'thumbs', json_encode($base['total']));
check('… each with their vote as stored, the ups first, A to Z within each',
      $names($base) === array_merge(array_map(fn($i) => sprintf('whot_m%02d', $i), range(1, 20)), array_map(fn($i) => sprintf('whot_m%02d', $i), range(21, 25)))
      && array_column($base['rows'], 'vote') === array_merge(array_fill(0, 20, 1), array_fill(0, 5, -1)));
check('… a row is a name, a picture\'s address and a vote — no id, no time',
      array_keys($base['rows'][0]) === ['username', 'avatar', 'vote'] && !str_contains(json_encode($base), '"id"') && is_string($base['rows'][0]['avatar']));
$gone = function (string $what, callable $do, callable $undo, ?array $c = null) use ($votes, $names, $m01): void {
    $do();
    $r = $votes($c);
    check("$what: out of the rows AND the count", $r['total'] === 24 && !in_array($m01, $names($r), true), json_encode(['total' => $r['total']]));
    $undo();
};
$gone('votes_listed off (the new consent)', fn() => $db->exec("UPDATE users SET votes_listed = 0 WHERE id = " . $M[1]), fn() => $db->exec("UPDATE users SET votes_listed = 1 WHERE id = " . $M[1]));
$gone('votes_public off (the likes on their profile)', fn() => $db->exec("UPDATE users SET votes_public = 0 WHERE id = " . $M[1]), fn() => $db->exec("UPDATE users SET votes_public = 1 WHERE id = " . $M[1]));
$gone('a group that does not grant rating.public', fn() => $moveTo($M[1], $gBare), fn() => $moveTo($M[1], $gGrant));
$adminId = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'admin'")->fetchColumn();
$adminJson = json_decode($adminBefore, true) ?: [];
unset($adminJson['rating.public']);
$db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")->execute([json_encode($adminJson ?: new stdClass()), $adminId]);
$gone('the administrator\'s blanket alone (the admin group, not granting it) — power is not consent', fn() => $moveTo($M[1], $adminId), fn() => null);
$db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")->execute([json_encode($adminJson + ['rating.public' => true]), $adminId]);
check('… and granting it to that group is what puts them back', in_array($m01, $names($votes()), true) && $votes()['total'] === 25);
$db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")->execute([$adminBefore, $adminId]);
$moveTo($M[1], $gGrant);
$gone('a suspended account', fn() => $db->exec("UPDATE users SET status = 'suspended' WHERE id = " . $M[1]), fn() => $db->exec("UPDATE users SET status = 'active' WHERE id = " . $M[1]));
$gone('an unverified account where the site demands verification', fn() => $db->exec("UPDATE users SET email_verified = 0 WHERE id = " . $M[1]), fn() => null);
check('… and the same account counts where it does not', $votes(array_merge($cfgOn, ['users_require_email_verify' => '0']))['total'] === 25);
$db->exec("UPDATE users SET email_verified = 1 WHERE id = " . $M[1]);
$gone('a membership that starts next year', fn() => $moveTo($M[1], $gGrant, '2099-01-01 00:00:00'), fn() => $moveTo($M[1], $gGrant));
$gone('a membership that ran out', fn() => $moveTo($M[1], $gGrant, '2000-01-01 00:00:00', '2001-01-01 00:00:00'), fn() => $moveTo($M[1], $gGrant));
$db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 1)")->execute([$M[1], $reader]);
$gone('a member who hid their profile from this reader', fn() => null, fn() => null);
check('… while another reader still sees them', in_array($m01, $names($votes(null, $M[2])), true));
$db->prepare("UPDATE user_blocks SET hide_profile = 0 WHERE user_id = ? AND blocked_id = ?")->execute([$M[1], $reader]);
check('… and a block that only stops messages hides nothing', in_array($m01, $names($votes()), true));
$db->prepare("DELETE FROM user_blocks WHERE user_id = ?")->execute([$M[1]]);
$st = $votes($cfgStars);
check('stars: the star votes and the thumbs up (a vote of 1 is half a star in either mode) — no thumb down, which has no star',
      $st['mode'] === 'stars' && $st['total'] === 22 && $names($st)[0] === 'whot_s2' && $names($st)[1] === 'whot_s1'
      && array_column($st['rows'], 'vote') === array_merge([10, 7], array_fill(0, 20, 1)) && !in_array('whot_m21', $names($st), true), json_encode(array_slice($st['rows'], 0, 3)));
check('anonymous votes never appear, in either mode', !array_filter(array_merge($base['rows'], $st['rows']), fn($r) => !str_starts_with($r['username'], 'whot_')));
check('the section is about THIS hash: another one has nobody', whoVotesPage($db, $cfgOn, $H2, $reader, $all)['total'] === 0);
$db->exec("UPDATE user_groups SET permissions = '{}' WHERE slug = 'whot_grant'");
$ng = $votes();
check('the members\' one group granting nothing any more: nobody at all — the grant is read from the group row at every look',
      $ng['total'] === 0 && $ng['rows'] === [], json_encode($ng['total']));
check('… and where nobody CAN be in a section the table is not even asked (whoSql() null): public lists off, a section that does not exist',
      whoSql($db, array_merge($cfgOn, ['lists_public_enabled' => '0']), 'lists', $H, $reader) === null && whoSql($db, $cfgOn, 'nonsense', $H, $reader) === null);
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'whot_grant'")->execute([json_encode(array_fill_keys(['favourites.use', 'favourites.public',
    'favourites.view_others', 'rating.vote', 'rating.public', 'lists.use', 'lists.public', 'index.view'], true), JSON_UNESCAPED_SLASHES)]);
userPermissionsForget(0);

// A vote taken back (1.71.0, repRemoveVote() — the Info panel's second press): the member is out of the section at
// once, the rows and the count, in either mode; nobody else is. Put back after, for the sections below.
$takeBack = function (int $uid, array $c) use ($db, $H): array {
    $GLOBALS['__current_user_loaded'] = true;
    $GLOBALS['__current_user_cache'] = userFindById($db, $uid);
    $r = repRemoveVote($db, $c, $H);
    $GLOBALS['__current_user_loaded'] = false;
    return $r;
};
$tb = $takeBack($M[25], $cfgOn);
$after = $votes();
check('a member takes their thumb back: out of the likes at once, the rows and the count, and nobody else is',
      !empty($tb['success']) && $tb['removed'] === true && $after['total'] === 24 && !in_array('whot_m25', $names($after), true)
      && $names($after) === array_merge(array_map(fn($i) => sprintf('whot_m%02d', $i), range(1, 20)), array_map(fn($i) => sprintf('whot_m%02d', $i), range(21, 24))),
      json_encode([$tb['error'] ?? null, $after['total']]));
$tb = $takeBack($S1, $cfgStars);
$after = $votes($cfgStars);
check('… and a rating taken back leaves the ratings the same way (22 → 21, whot_s1 gone, whot_s2 first still)',
      !empty($tb['success']) && $tb['removed'] === true && $after['total'] === 21 && !in_array('whot_s1', $names($after), true) && $names($after)[0] === 'whot_s2',
      json_encode([$tb['error'] ?? null, $after['total']]));
$vIns->execute([$H, (string)$M[25], -1]);
$vIns->execute([$H, (string)$S1, 7]);
$rlKeys = ['user:' . $M[25], 'user:' . $S1];
register_shutdown_function(function () use ($rlKeys) {
    // the hour's budget the two removals spent, taken back from the vote's own file (config/ratelimit/, 1.74.0)
    rateLimitForget('repvote', fn(string $s): bool => in_array($s, $rlKeys, true));
});
check('… (both put back for what follows: 25 likes, 22 ratings)', $votes()['total'] === 25 && $votes($cfgStars)['total'] === 22);
$fjWho = $src('assets/js/favourites.js');
check('the overlay asks its likes again when a vote changes under it, on its own torrent, while it is open',
      preg_match("/document\.addEventListener\('rating:changed', function \(e\) \{\s*var d = e && e\.detail;\s*if \(box\.hidden \|\| !d \|\| d\.hash !== hash\) return;\s*secs\.forEach\(function \(s\) \{ if \(s\.name === 'votes' && s\.state !== 'gone'\) load\(s, false\); \}\);/", $fjWho) === 1);

/* ══ 5. lists: the five gates, and the account's ══════════════════════════ */
$lists = fn(?array $c = null, ?array $p = null) => whoListsPage($db, $c ?? $cfgOn, $H, $reader, $p ?? $all);
$lb = $lists();
check('lists: the 25 public lists, the most recently changed first, each with its owner and its size',
      $lb['total'] === 25 && $lnames($lb)[0] === 'Pack 25' && $lnames($lb)[24] === 'Pack 01' && $lb['rows'][0]['username'] === 'whot_m25'
      && $lb['rows'][0]['items'] === 1 && $lb['rows'][0]['slug'] === 'pack-25', json_encode($lb['rows'][0] ?? null));
check('… a row: name, slug, owner, size, the owner\'s picture — the shape listsContainingHash() has always had, no id',
      array_keys($lb['rows'][0]) === ['name', 'slug', 'username', 'items', 'avatar'] && !str_contains(json_encode($lb), '"id"'));
check('… listsContainingHash() is the same query\'s first page', array_map(fn($r) => $r['name'], listsContainingHash($db, $cfgOn, $H, 20, $reader)) === array_slice($lnames($lb), 0, 20));
$lgone = function (string $what, callable $do, callable $undo, ?array $c = null) use ($lists, $lnames): void {
    $do();
    $r = $lists($c);
    check("lists — $what: out of the rows AND the count", $r['total'] === 24 && !in_array('Pack 01', $lnames($r), true) && in_array('Pack 02', $lnames($r), true),
          json_encode($r['total']));
    $undo();
};
check('lists — (1) lists switched off: nothing, and no section', $lists(array_merge($cfgOn, ['lists_enabled' => '0']))['total'] === 0
      && !whoSections($db, array_merge($cfgOn, ['lists_enabled' => '0']), $R)['lists']);
check('lists — (2) public lists switched off: nothing, and no section', $lists(array_merge($cfgOn, ['lists_public_enabled' => '0']))['total'] === 0
      && !whoSections($db, array_merge($cfgOn, ['lists_public_enabled' => '0']), $R)['lists']);
$lgone('(3) the owner\'s group does not grant lists.public', fn() => $moveTo($M[1], $gBare), fn() => $moveTo($M[1], $gGrant));
$lgone('(4) the owner hides the section (lists_public)', fn() => $db->exec("UPDATE users SET lists_public = 0 WHERE id = " . $M[1]), fn() => $db->exec("UPDATE users SET lists_public = 1 WHERE id = " . $M[1]));
// (updated_at follows every write to the row — ON UPDATE — so the fixture's own moment is written back with it)
$lgone('(5) the list itself is private', fn() => $db->exec("UPDATE user_lists SET visibility = 'private' WHERE id = " . $L[1]),
       fn() => $db->exec("UPDATE user_lists SET visibility = 'public', updated_at = '2026-02-01 00:01:00' WHERE id = " . $L[1]));
$lgone('(5b) the list is shared with friends, and this reader is not one (1.72.0)', fn() => $db->exec("UPDATE user_lists SET visibility = 'friends' WHERE id = " . $L[1]),
       fn() => $db->exec("UPDATE user_lists SET visibility = 'public', updated_at = '2026-02-01 00:01:00' WHERE id = " . $L[1]));

/* ── 5c. (1.72.0) a list shared with FRIENDS: counted for a friend of its owner, and for nobody else ──
 * The owner's group has friends.use and NOT lists.public: a friends list needs no grant of consent. One
 * friends list, the newest of all (so a friend's first row is it); the gates one at a time, each taking it
 * out of the rows AND the count; the public 25 never move. */
$gFriends = $group('whot_friends', ['favourites.use', 'favourites.view_others', 'lists.use', 'friends.use', 'index.view']);
$gNoFriends = $group('whot_nofriends', ['favourites.use', 'favourites.view_others', 'lists.use', 'index.view']);
$FO = $member('whot_fowner', $gFriends);
$db->exec("UPDATE users SET lists_public = 1 WHERE id = $FO");
$db->prepare("INSERT INTO user_lists (user_id, name, slug, visibility, created_at, updated_at) VALUES (?, 'Friends pack', 'friends-pack', 'friends', '2026-01-01 00:00:00', '2026-03-01 00:00:00')")->execute([$FO]);
$FL = (int)$db->lastInsertId();
$iIns->execute([$FL, $H]);
$befriend = fn(int $a, int $b, string $st = 'accepted') => $db->prepare("INSERT INTO user_friends (user_id, friend_id, status) VALUES (?, ?, ?)")->execute([$a, $b, $st]);
$unfriend = fn() => $db->exec("DELETE FROM user_friends WHERE user_id IN ($FO, $reader) OR friend_id IN ($FO, $reader)");
$hasFp = fn(array $r): bool => in_array('Friends pack', $lnames($r), true);
$r0 = $lists();
check('lists — a friends list is not counted for a reader who is not the owner\'s friend (the public 25 as they were)',
      $r0['total'] === 25 && !$hasFp($r0), json_encode($r0['total']));
$befriend($reader, $FO);
$r1 = $lists();
check('… a friendship ACCEPTED (asked by the reader): counted, in the rows and the count — first, as the newest',
      $r1['total'] === 26 && ($lnames($r1)[0] ?? '') === 'Friends pack' && ($r1['rows'][0]['username'] ?? '') === 'whot_fowner', json_encode([$r1['total'], $lnames($r1)[0] ?? null]));
$unfriend();
$befriend($FO, $reader);
check('… either way round: asked by the owner, accepted by the reader', $lists()['total'] === 26 && $hasFp($lists()));
check('… its search finds it by its name and by its owner\'s', $lists(null, ['page' => 1, 'per_page' => 50, 'search' => 'Friends pack'])['total'] === 1
      && $lists(null, ['page' => 1, 'per_page' => 50, 'search' => 'whot_fowner'])['total'] === 1);
check('… and listsContainingHash() says the same for the friend', in_array('Friends pack', array_column(listsContainingHash($db, $cfgOn, $H, 50, $reader), 'name'), true));
$fgone = function (string $what, callable $do, callable $undo, ?array $c = null, ?int $who = null) use ($db, $cfgOn, $H, $reader, $all, $hasFp): void {
    $do();
    $r = whoListsPage($db, $c ?? $cfgOn, $H, $who ?? $reader, $all);
    check("lists — friends — $what: out of the rows AND the count", $r['total'] === 25 && !$hasFp($r), json_encode($r['total']));
    $undo();
};
$fgone('a friendship only asked, not yet accepted', function () use ($db, $FO, $reader) { $db->exec("UPDATE user_friends SET status = 'pending' WHERE user_id = $FO AND friend_id = $reader"); },
       function () use ($db, $FO, $reader) { $db->exec("UPDATE user_friends SET status = 'accepted' WHERE user_id = $FO AND friend_id = $reader"); });
$fgone('the friends feature switched off (friends_enabled)', fn() => null, fn() => null, array_merge($cfgOn, ['friends_enabled' => '0']));
$fgone('the owner\'s groups no longer give friends.use', fn() => $moveTo($FO, $gNoFriends), fn() => $moveTo($FO, $gFriends));
$fgone('the owner hides the section (lists_public)', fn() => $db->exec("UPDATE users SET lists_public = 0 WHERE id = $FO"), fn() => $db->exec("UPDATE users SET lists_public = 1 WHERE id = $FO"));
$fgone('the owner blocks the reader (no hide_profile) while the friendship row is still there',
       fn() => $db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 0)")->execute([$FO, $reader]),
       fn() => $db->prepare("DELETE FROM user_blocks WHERE user_id = ?")->execute([$FO]));
$fgone('the READER blocks the owner, the friendship row still there',
       fn() => $db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 0)")->execute([$reader, $FO]),
       fn() => $db->prepare("DELETE FROM user_blocks WHERE user_id = ?")->execute([$reader]));
$fgone('asked by the OWNER: their own friends list is not theirs to be counted in (as their private ones never were)', fn() => null, fn() => null, null, $FO);
$fgone('asked by another member, not a friend', fn() => null, fn() => null, null, $M[2]);
$fgone('the list made private again', fn() => $db->exec("UPDATE user_lists SET visibility = 'private' WHERE id = $FL"),
       fn() => $db->exec("UPDATE user_lists SET visibility = 'friends', updated_at = '2026-03-01 00:00:00' WHERE id = $FL"));
$adminG = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'admin'")->fetchColumn();
$moveTo($FO, $adminG);
check('… an owner in the system admin group alone: the blanket gives friends.use (a feature, not consent) — counted',
      $adminG > 0 && $lists()['total'] === 26 && $hasFp($lists()), (string)$lists()['total']);
$moveTo($FO, $gFriends);
check('… and with the site keeping every list private (lists_public_enabled off) the friends list goes with the public ones',
      whoListsPage($db, array_merge($cfgOn, ['lists_public_enabled' => '0']), $H, $reader, $all)['total'] === 0);
$unfriend();
check('… and the unfriending takes it away at once: the next question is answered without it', $lists()['total'] === 25 && !$hasFp($lists()));
$befriend($reader, $FO);
$r2 = whoListsPage($db, $cfgOn, $H, $reader, ['page' => 2, 'per_page' => 20, 'search' => '']);
check('… with it back, the pages agree with the count: 26 over two pages of 20 (6 on the second)', $r2['total'] === 26 && count($r2['rows']) === 6 && $r2['pages'] === 2, json_encode([$r2['total'], count($r2['rows'])]));
$unfriend();
userDeleteCascade($db, $FO);
$lgone('a suspended owner', fn() => $db->exec("UPDATE users SET status = 'suspended' WHERE id = " . $M[1]), fn() => $db->exec("UPDATE users SET status = 'active' WHERE id = " . $M[1]));
$lgone('an unverified owner', fn() => $db->exec("UPDATE users SET email_verified = 0 WHERE id = " . $M[1]), fn() => $db->exec("UPDATE users SET email_verified = 1 WHERE id = " . $M[1]));
$lgone('an owner who hid their profile from this reader', fn() => $db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 1)")->execute([$M[1], $reader]),
       fn() => $db->prepare("DELETE FROM user_blocks WHERE user_id = ?")->execute([$M[1]]));
check('lists — the section\'s own switch closes it; the lists stay public on the profiles', !whoSections($db, array_merge($cfgOn, ['who_lists_enabled' => '0']), $R)['lists']
      && listsVisibleFor($db, $cfgOn, userFindById($db, $M[1])));
$ls = $lists(null, ['page' => 1, 'per_page' => 50, 'search' => 'Pack 0']);
$lo = $lists(null, ['page' => 1, 'per_page' => 50, 'search' => 'whot_m1']);
check('lists — the search: by the list\'s name ("Pack 0": 9) or its owner\'s ("whot_m1": 10)', $ls['total'] === 9 && $lo['total'] === 10, json_encode([$ls['total'], $lo['total']]));

/* ══ 6. favourites: the 1.69.0 gates ══════════════════════════════════════ */
$favs = fn() => whoFavPage($db, $cfgOn, $H, $reader, $all);
$fb = $favs();
check('favourites: the 25, A to Z, a name and a picture\'s address each', $fb['total'] === 25 && $names($fb)[0] === 'whot_m01' && array_keys($fb['rows'][0]) === ['username', 'avatar']);
foreach (['fav_listed' => 'the "list me" consent', 'fav_public' => 'their public favourites'] as $flag => $what) {
    $db->exec("UPDATE users SET `$flag` = 0 WHERE id = " . $M[1]);
    $r = $favs();
    check("favourites — $what off: out of the rows AND the count", $r['total'] === 24 && !in_array($m01, $names($r), true));
    $db->exec("UPDATE users SET `$flag` = 1 WHERE id = " . $M[1]);
}
$moveTo($M[1], $gBare);
check('favourites — a group without favourites.public: out', $favs()['total'] === 24);
$moveTo($M[1], $gGrant);

/* ══ 7. paging, clamping, the search, totals that are true ════════════════ */
$p1 = $votes(null, 0, whoParams([]));
$p2 = $votes(null, 0, whoParams(['page' => '2']));
$p3 = $votes(null, 0, whoParams(['page' => '3']));
check('20 a page by default: 20, then 5, then a page past the end EMPTY with the true total (a "Show more" never repeats rows)',
      count($p1['rows']) === 20 && $p1['pages'] === 2 && count($p2['rows']) === 5 && $p3['rows'] === [] && $p3['total'] === 25
      && !array_intersect($names($p1), $names($p2)), json_encode([count($p1['rows']), count($p2['rows']), $p3['total']]));
$wp = whoParams(['per_page' => '999', 'page' => '-3', 'search' => "  whot\x01m0  "]);
check('whoParams(): per_page clamped to 50, page to 1, controls made spaces and the ends trimmed',
      $wp === ['page' => 1, 'per_page' => 50, 'search' => 'whot m0'], json_encode($wp));
check('… a word where a number goes is the default (20, 1); per_page 0 is 1; text that is not UTF-8 is no name; 60 characters at most',
      whoParams(['per_page' => 'abc', 'page' => 'x']) === ['page' => 1, 'per_page' => 20, 'search' => '']
      && whoParams(['per_page' => '0'])['per_page'] === 1 && whoParams(['search' => "\xC3\x28"])['search'] === ''
      && mb_strlen(whoParams(['search' => str_repeat('ą', 200)])['search']) === 60 && whoParams(['search' => ['x']])['search'] === '');
check('… and the 1.69.0 answer keeps its own pair (25 by default, 100 at most)', whoParams([], 25, 100)['per_page'] === 25 && whoParams(['per_page' => '500'], 25, 100)['per_page'] === 100);
$srch = fn(string $s) => $votes(null, 0, ['page' => 1, 'per_page' => 50, 'search' => $s])['total'];
check('the search matches a name anywhere, in any case ("whot_m2": 6, "WHOT_M0": 9) — and its %, _ and \\ are the characters, not wildcards',
      $srch('whot_m2') === 6 && $srch('WHOT_M0') === 9 && $srch('%m2') === 0 && $srch('m_2') === 0 && $srch('\\') === 0, json_encode([$srch('whot_m2'), $srch('%m2'), $srch('m_2')]));
foreach (['fav', 'votes', 'lists'] as $sec) {
    $whole = whoSectionPage($db, $cfgOn, $sec, $H, $reader, $all);
    $one = whoSectionPage($db, $cfgOn, $sec, $H, $reader, ['page' => 1, 'per_page' => 7, 'search' => '']);
    check("$sec: the total is the rows — never a count of people the rows do not show", $whole['total'] === count($whole['rows']) && $one['total'] === $whole['total'] && $one['pages'] === 4);
}

/* ══ 8. EXPLAIN: each section drives through its hash's own index ══════════ */
// Around the torrent asked about, tables of the kind a real site has: three thousand votes and three thousand
// list rows on OTHER hashes (a prefix of this run's own, removed at the end). On a table of thirty rows that
// nearly all match, a scan IS the cheapest plan, and the optimiser rightly takes it; that says nothing.
$bulkV = []; $bulkL = [];
for ($i = 0; $i < 3000; $i++) {
    $bh = sprintf('f5f5%036x', $i);
    // Members' votes, as most votes on a site with accounts are (keys of accounts that do not exist: joined to nobody).
    $bulkV[] = "('$bh', 'user', '" . (900000000 + $i) . "', 1)";
    $bulkL[] = '(' . $L[25] . ", '$bh', 'bulk')";
}
foreach (array_chunk($bulkV, 500) as $ch) $db->exec("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote) VALUES " . implode(',', $ch));
foreach (array_chunk($bulkL, 500) as $ch) $db->exec("INSERT INTO user_list_items (list_id, info_hash, name) VALUES " . implode(',', $ch));
$db->query("ANALYZE TABLE hash_votes, user_list_items, user_favourites")->fetchAll();
$plan = function (string $sec) use ($db, $cfgOn, $H, $reader): array {
    $q = whoSql($db, $cfgOn, $sec, $H, $reader, 'x');
    $st = $db->prepare("EXPLAIN SELECT " . $q['cols'] . ' ' . $q['sql'] . ' ORDER BY ' . $q['order'] . ' LIMIT 20');
    $st->execute($q['args']);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['table']] = [(string)$r['type'], (string)$r['key']];
    return $out;
};
$pv = $plan('votes'); $pl2 = $plan('lists'); $pf = $plan('fav');
$keyed = fn(array $t): bool => !in_array($t[0] ?? 'ALL', ['ALL', 'index'], true);
check('votes: hash_votes by its hash (uq_vote_once / idx_votes_hash), the account by its primary key',
      $keyed($pv['v'] ?? []) && (bool)array_intersect(explode('|', $pv['v'][1] ?? ''), ['uq_vote_once', 'idx_votes_hash'])
      && ($pv['u'][1] ?? '') === 'PRIMARY', json_encode($pv));
// The join ORDER of the lists was the optimiser's until 1.72.0 (on these few accounts it could start from the members of
// the granting groups); under the OR of the public and the friends arms it started from every list, so the query writes
// it down (STRAIGHT_JOIN: the items, then the list, then the owner). What matters is that the big table, the items, is
// never read whole — only the rows of this hash (idx_item_hash) or one row per list (uq_list_item: list, hash).
check('lists: user_list_items only through an index (idx_item_hash / uq_list_item), the list and the owner by their keys',
      $keyed($pl2['i'] ?? []) && in_array($pl2['i'][1] ?? '', ['idx_item_hash', 'uq_list_item'], true) && $keyed($pl2['l'] ?? []) && ($pl2['u'][1] ?? '') === 'PRIMARY',
      json_encode($pl2));
check('favourites: user_favourites by its hash (idx_fav_hash), the account by its key',
      ($pf['f'][1] ?? '') === 'idx_fav_hash' && $keyed($pf['f'] ?? []) && ($pf['u'][1] ?? '') === 'PRIMARY', json_encode($pf));

/* ══ 9. the endpoints and the privacy save, as requests ═══════════════════ */
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'who_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = $a["ip"];
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/richtext.php", "includes/reputation.php", "includes/mail.php", "includes/users.php",
          "includes/favourites.php", "includes/usermedia.php", "includes/people.php", "includes/profilevotes.php", "includes/lists.php",
          "includes/who.php", "includes/audit.php", "includes/auth.php", "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
require $a["file"];
');
$reqCfg = ['users_enabled' => '1', 'users_require_email_verify' => '1', 'fav_enabled' => '1', 'fav_public_enabled' => '1', 'fav_who_enabled' => '1',
           'rep_enabled' => '1', 'rep_mode' => 'thumbs', 'profile_votes_enabled' => '1', 'who_votes_enabled' => '1', 'lists_enabled' => '1',
           'lists_public_enabled' => '1', 'who_lists_enabled' => '1', 'rate_limit_index_search' => '0'];
$run = function (string $file, string $method, array $get, array $post, array $session, array $cfgExtra = [], string $ip = WHO_TEST_IP) use ($root, $runner, &$tmpFiles, $reqCfg): array {
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'who_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmpFiles[] = $argFile;
    file_put_contents($argFile, json_encode(['root' => $root, 'file' => $root . '/' . $file, 'method' => $method, 'ip' => $ip,
        'get' => $get, 'post' => $post, 'session' => $session, 'cfg' => $cfgExtra + $reqCfg, 'sid' => 'whotest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 400)];
};
$sess = fn(int $uid) => ['user_id' => $uid, 'user_login_time' => time(), 'csrf_token' => 'who-child-token'];
$who = fn(array $get, int $uid, array $c = []) => $run('api/hash_who.php', 'GET', $get + ['hash' => $H], [], $uid ? $sess($uid) : ['csrf_token' => 'who-child-token'], $c);
$j = $who(['section' => 'votes'], $reader);
check('hash_who, votes: 200, its first 20 of 25, the mode, rows of {username, avatar, vote}',
      !empty($j['success']) && ($j['section'] ?? '') === 'votes' && ($j['total'] ?? 0) === 25 && count($j['rows'] ?? []) === 20 && ($j['per_page'] ?? 0) === 20
      && ($j['mode'] ?? '') === 'thumbs' && array_keys($j['rows'][0] ?? []) === ['username', 'avatar', 'vote'], json_encode(array_diff_key($j, ['rows' => 1])));
$j = $who(['section' => 'votes', 'page' => '2'], $reader);
check('… page 2: the last 5, the thumbs down', count($j['rows'] ?? []) === 5 && array_unique(array_column($j['rows'] ?? [], 'vote')) === [-1], json_encode($j['rows'] ?? $j));
$j = $who(['section' => 'votes', 'per_page' => '500', 'search' => 'whot_m2'], $reader);
check('… a search, and per_page held to 50', ($j['total'] ?? 0) === 6 && ($j['per_page'] ?? 0) === 50, json_encode(array_diff_key($j, ['rows' => 1])));
$j = $who(['section' => 'fav'], $reader);
check('hash_who, fav: {username, avatar}', ($j['total'] ?? 0) === 25 && array_keys($j['rows'][0] ?? []) === ['username', 'avatar'], json_encode(array_diff_key($j, ['rows' => 1])));
$j = $who(['section' => 'lists'], $reader);
check('hash_who, lists: {name, slug, username, items, avatar}, the newest first',
      ($j['total'] ?? 0) === 25 && array_keys($j['rows'][0] ?? []) === ['name', 'slug', 'username', 'items', 'avatar'] && ($j['rows'][0]['name'] ?? '') === 'Pack 25', json_encode(array_diff_key($j, ['rows' => 1])));
$nf = $who(['section' => 'nonsense'], $reader);
check('… a section that does not exist: 404 not_found', ($nf['error'] ?? '') === 'not_found' && empty($nf['success']), json_encode($nf));
$same = [
    'nobody signed in'                     => $who(['section' => 'votes'], 0),
    'an account without favourites.view_others' => $who(['section' => 'votes'], $blind),
    'an account without index.view'        => $who(['section' => 'votes'], $noIdx),
    'the likes section switched off'       => $who(['section' => 'votes'], $reader, ['who_votes_enabled' => '0']),
    'ratings switched off'                 => $who(['section' => 'votes'], $reader, ['rep_enabled' => '0']),
    'the lists section switched off'       => $who(['section' => 'lists'], $reader, ['who_lists_enabled' => '0']),
    'favourites\' list switched off'       => $who(['section' => 'fav'], $reader, ['fav_who_enabled' => '0']),
];
check('… and the very same 404 for every other no: ' . implode(', ', array_keys($same)),
      !array_filter($same, fn($x) => json_encode($x) !== json_encode($nf)), json_encode($same));
$j = $who(['section' => 'fav'], $reader, ['who_votes_enabled' => '0']);
check('… a section switched off takes nothing from the others', ($j['total'] ?? 0) === 25);
$j = $who(['section' => 'votes', 'hash' => 'not-a-hash'], $reader);
check('… a hash that is not one: 400, with the words', ($j['error'] ?? '') === __('api.common.invalid_hash'), json_encode($j));
$j = $run('api/hash_who.php', 'POST', ['section' => 'votes', 'hash' => $H], ['csrf_token' => 'who-child-token'], $sess($reader));
check('… GET only: 405', ($j['error'] ?? '') === 'method_not_allowed', json_encode($j));
$limited = [];
for ($i = 0; $i < 4; $i++) $limited[] = $run('api/hash_who.php', 'GET', ['section' => 'fav', 'hash' => $H], [], $sess($reader), ['rate_limit_index_search' => '1'], WHO_TEST_IP_LIMIT);
check('the rate limit: three sections to one look — with rate_limit_index_search 1 an address has 3 an hour, the 4th is 429',
      !empty($limited[2]['success']) && ($limited[3]['error'] ?? '') === 'rate_limit', json_encode(array_map(fn($x) => $x['error'] ?? 'ok', $limited)));
$j = $run('api/hash_favourites.php', 'GET', ['hash' => $H], [], $sess($reader));
check('api/hash_favourites.php, the 1.69.0 answer: rows {username, avatar} 25 a page, and the first 20 lists {name, slug, username, items, avatar}',
      !empty($j['success']) && ($j['total'] ?? 0) === 25 && count($j['rows'] ?? []) === 25 && ($j['per_page'] ?? 0) === 25 && array_keys($j['rows'][0] ?? []) === ['username', 'avatar']
      && count($j['lists'] ?? []) === 20 && array_keys($j['lists'][0] ?? []) === ['name', 'slug', 'username', 'items', 'avatar'], json_encode(array_diff_key($j, ['rows' => 1, 'lists' => 1])));
$j = $run('api/hash_favourites.php', 'GET', ['hash' => $H], [], $sess($reader), ['who_lists_enabled' => '0']);
check('… its lists obey the lists section\'s switch too', !empty($j['success']) && ($j['lists'] ?? null) === [], json_encode(array_diff_key($j, ['rows' => 1])));
$db->exec("UPDATE users SET votes_listed = 0 WHERE id = " . $reader);
$j = $run('api/user_privacy.php', 'POST', [], ['csrf_token' => 'who-child-token', 'votes_listed' => 1], $sess($reader));
check('the privacy save: votes_listed goes on, and the answer says so and that the section exists',
      !empty($j['success']) && ($j['votes_listed'] ?? false) === true && ($j['votes_who_enabled'] ?? false) === true
      && (int)$db->query("SELECT votes_listed FROM users WHERE id = " . $reader)->fetchColumn() === 1, json_encode($j));
$j = $run('api/user_privacy.php', 'POST', [], ['csrf_token' => 'who-child-token', 'votes_listed' => 0], $sess($reader));
check('… off again', ($j['votes_listed'] ?? true) === false && (int)$db->query("SELECT votes_listed FROM users WHERE id = " . $reader)->fetchColumn() === 0);
$j = $run('api/user_privacy.php', 'POST', [], ['csrf_token' => 'not-it', 'votes_listed' => 1], $sess($reader));
check('… never without the session\'s token', !empty($j['error']) && (int)$db->query("SELECT votes_listed FROM users WHERE id = " . $reader)->fetchColumn() === 0);

/* ══ 10. the pages and the scripts that carry it ══════════════════════════ */
$api = $src('api.php');
check('the endpoint is routed; api.php and index.php load the library (lists.php does too, for its one wrapper)',
      str_contains($api, "'hash_who'                   => 'api/hash_who.php'") && str_contains($api, "require_once __DIR__ . '/includes/who.php';")
      && str_contains($src('index.php'), "require_once __DIR__ . '/includes/who.php';") && str_contains($src('includes/lists.php'), "require_once __DIR__ . '/who.php';"));
$io = $src('templates/partials/info_overlay.php');
check('the overlay: one section per open one, in the order fav, votes, lists, each with an id on every word and data-lang-keep on what the script writes',
      str_contains($io, '$ioWhoSecs = function_exists(\'whoSections\') ? whoSections($db, $cfg, $ioViewer)') && str_contains($io, '$ioWho    = in_array(true, $ioWhoSecs, true);')
      && str_contains($io, '<section class="who-sec" id="who-sec-<?= $ioSec ?>" data-sec="<?= $ioSec ?>"') && preg_match_all('/data-lang-keep(?=>|\s+hidden>)/', $io) === 3
      && str_contains($io, 'id="who-<?= $ioSec ?>-search"') && str_contains($io, 'id="who-why"') && WHO_SECTIONS === ['fav', 'votes', 'lists']);
$acc = $src('templates/pages/account.php');
$aVp = strpos($acc, 'id="acc-votes-public"'); $aVl = strpos($acc, 'id="acc-votes-listed"'); $aDp = strpos($acc, 'id="acc-descs-public"');
check('the account page: the new consent right under the likes\' own switch, named by the mode, only while the section exists',
      $aVp !== false && $aVl > $aVp && $aDp > $aVl && str_contains($acc, "_h('account.votes_listed_label_' . \$accVotes['mode'])")
      && str_contains($acc, "function_exists('whoVotesEnabled') && whoVotesEnabled(\$cfg)"));
$fj = $src('assets/js/favourites.js');
$wjFrom = (int)strpos($fj, 'function initWho(');
$wj = $wjFrom > 0 ? substr($fj, $wjFrom, (int)strpos($fj, 'the account page\'s tab bar', $wjFrom) - $wjFrom) : '';
check('the script: asks hash_who per section, all at once on opening, draws with textContent only, again on a live language switch',
      $wj !== '' && str_contains($wj, "'hash_who&section=' + s.name") && str_contains($wj, 'secs.forEach(function (s) { load(s, false); });')
      && !str_contains($wj, 'innerHTML') && str_contains($wj, "document.addEventListener('langswap'") && str_contains($wj, 'layerFor(box, close)'));
check('… the privacy switch posts votes_listed', str_contains($fj, "['acc-votes-listed', 'votes_listed']"));
check('its strings reach the public pages (js.who. in LANG_JS_PUBLIC)', in_array('js.who.', LANG_JS_PUBLIC, true));
$aj = $src('assets/js/app.js');
check('one Esc, one layer: escLayer() in app.js, the Info panel on it, the leave dialog keeping its Esc (here and in the panel)',
      str_contains($aj, 'const escLayer = (box, close) => {') && str_contains($aj, 'infoLayer = escLayer(infoOverlay, closeInfo);')
      && preg_match('/function onEsc\(e\) \{\s*if \(e\.key !== .Escape.\) return;\s*e\.preventDefault\(\);\s*e\.stopImmediatePropagation\(\);/', $aj) === 1
      && preg_match('/function onEsc\(e\) \{\s*if \(e\.key !== .Escape.\) return;\s*e\.preventDefault\(\);\s*e\.stopImmediatePropagation\(\);/', $src('assets/js/admin-common.js')) === 1);
$css = $src('assets/css/style.css');
check('the look: the sections\' rule, head and search, and the chips as before (a name that was opened stays the link colour)',
      str_contains($css, '.who-sec:not([hidden]) ~ .who-sec:not([hidden])') && str_contains($css, '.who-sec-head .who-sec-search')
      && preg_match('/:visited\s*[,{]/', $css) === 0);

/* ══ 11. ONE gate, two views of it (1.74.0, QUAL-13) ═════════════════════
 * The profile asks userIdHasGrantedPermission() of one account; this section asks SQL of all of them. Until 1.74.0
 * the two disagreed: an account with the verified flag and no address was listed here by name while its own profile
 * refused it, a membership ended a second earlier here, and an Admin-group member (exempt from the e-mail gate in
 * PHP) was missing here. Now both come from one generator (userMembershipSql() / userEmailGateSql(), includes/
 * favourites.php) — walked here account by account: the profile's answer IS the list's. */
$mx = [];
$mkx = function (string $name, array $gids, ?string $email, int $flag) use ($db, $cfgOn, $H2, &$mx): int {
    $r = userCreate($db, $cfgOn, $name, '', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    $db->prepare("UPDATE users SET email = ?, email_verified = ?, status = 'active', fav_public = 1, fav_listed = 1 WHERE id = ?")->execute([$email, $flag, $id]);
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
    foreach ($gids as $g) $db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at) VALUES (?, ?, '2000-01-01 00:00:00')")->execute([$id, $g]);
    $db->prepare("INSERT INTO user_favourites (user_id, info_hash) VALUES (?, ?)")->execute([$id, $H2]);
    userPermissionsForget($id);
    return $mx[$name] = $id;
};
$adminGx = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'admin'")->fetchColumn();
$mkx('whot_q_ok', [$gGrant], 'whot_q_ok@example.org', 1);           // an address, verified
$mkx('whot_q_unver', [$gGrant], 'whot_q_unver@example.org', 0);     // an address, not verified
$mkx('whot_q_noemail', [$gGrant], null, 1);                         // the flag and NO address (the admin page could write it)
$mkx('whot_q_empty', [$gGrant], '', 1);                             // the flag and an empty address
$mkx('whot_q_admin', [$gGrant, $adminGx], null, 0);                 // the Admin group: exempt from the e-mail gate
$mkx('whot_q_bare', [$gBare], 'whot_q_bare@example.org', 1);        // verified, no grant
$listed = $names(whoFavPage($db, $cfgOn, $H2, $reader, ['page' => 1, 'per_page' => 50, 'search' => '']));
$disagree = [];
foreach ($mx as $nm => $id) {
    $php = userIdHasGrantedPermission($db, $cfgOn, $id, 'favourites.public');
    if ($php !== in_array($nm, $listed, true)) $disagree[] = $nm . ' (profile ' . ($php ? 'yes' : 'no') . ', list ' . ($php ? 'no' : 'yes') . ')';
}
check('QUAL-13: for every account, the profile\'s grant (PHP) and "who has this" (SQL) give the same answer — the e-mail gate on',
      $disagree === [] && count($mx) === 6, implode(' | ', $disagree) . ' — listed: ' . implode(',', $listed));
check('… which is: an address AND the flag, or the Admin group; never the flag alone, never an empty address, never without the grant',
      array_values(array_intersect(['whot_q_admin', 'whot_q_bare', 'whot_q_empty', 'whot_q_noemail', 'whot_q_ok', 'whot_q_unver'], $listed)) === ['whot_q_admin', 'whot_q_ok'],
      implode(',', $listed));
$cfgNoGate = array_merge($cfgOn, ['users_require_email_verify' => '0']);
$listedNg = $names(whoFavPage($db, $cfgNoGate, $H2, $reader, ['page' => 1, 'per_page' => 50, 'search' => '']));
userPermissionsForget();
$disagreeNg = [];
foreach ($mx as $nm => $id) {
    if (userIdHasGrantedPermission($db, $cfgNoGate, $id, 'favourites.public') !== in_array($nm, $listedNg, true)) $disagreeNg[] = $nm;
}
check('… and with the gate off, the same agreement (everybody the grant reaches)', $disagreeNg === [] && in_array('whot_q_unver', $listedNg, true)
      && !in_array('whot_q_bare', $listedNg, true), implode(',', $disagreeNg) . ' — listed: ' . implode(',', $listedNg));
// The Users page can no longer write the flag for an account without an address — its endpoint, as a request.
$owner = ['loggedin' => true, 'login_time' => time(), 'last_activity' => time(), 'csrf_token' => 'who-child-token'];
$j = $run('api/admin/user_update.php', 'POST', [], ['id' => $mx['whot_q_noemail'], 'email_verified' => 1], $owner);
$j2 = $run('api/admin/user_update.php', 'POST', [], ['id' => $mx['whot_q_unver'], 'email_verified' => 1], $owner);
check('… the Users page\'s "verified" is said of an ADDRESS: asked for an account without one, it leaves it unverified; with one, it verifies',
      !empty($j['success']) && (int)$db->query("SELECT email_verified FROM users WHERE id = " . $mx['whot_q_noemail'])->fetchColumn() === 0
      && !empty($j2['success']) && (int)$db->query("SELECT email_verified FROM users WHERE id = " . $mx['whot_q_unver'])->fetchColumn() === 1,
      json_encode([$j, $j2]));
$db->prepare("DELETE FROM user_favourites WHERE info_hash = ?")->execute([$H2]);

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
