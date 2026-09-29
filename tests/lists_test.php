<?php
/**
 * Lists — the schema, the five gates, the slug and the cascade (needs the local test database):
 *   php tests/lists_test.php
 *
 * ── the one thing this file exists for ─────────────────────────────────────────────────────────
 * A list is visible to a stranger only when every one of five separate answers says yes, and four
 * of them belong to different people: the operator's master switch, the operator's public switch,
 * the owner's group, the owner's section flag, and the owner's per-list flag. Any single no has to
 * take the list off every page — the profile AND the "who has this" overlay, which decide it in two
 * different languages (PHP and SQL). So the table below is walked one flag at a time against the
 * real query, not against a grep.
 *
 * The HTTP side (creating, adding by magnet, the picker) is driven by a real browser in
 * scratchpad/shots/lists_check.js — userCan() answers true for any panel session, so nothing in a
 * CLI test could prove the permission half of it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/schedule.php';
require_once $root . '/includes/index.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/favourites.php';
require_once $root . '/includes/lists.php';
// 1.70.0 D: the description — its renderer, the room's emotes, the mute, the dictionary, Settings' words.
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/shout.php';
require_once $root . '/includes/people.php';
require_once $root . '/includes/lang.php';
require_once $root . '/includes/settings_catalog.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$cfg = getSettings($db, true);

/* ── 1. the schema, in both the fresh path and the upgrade path ───────────── */
check('schema is at least 51', (int)($cfg['schema_version'] ?? 0) >= 51, (string)($cfg['schema_version'] ?? 'none'));
foreach (['user_lists', 'user_list_items'] as $t) {
    check("$t exists", count($db->query("SHOW TABLES LIKE '$t'")->fetchAll()) === 1);
}
$stmts = implode("\n", trackerSchemaStatements($db));
check('both tables are in the statement list, not only in the migration',
    str_contains($stmts, 'CREATE TABLE IF NOT EXISTS `user_lists`')
    && str_contains($stmts, 'CREATE TABLE IF NOT EXISTS `user_list_items`'));
check('users.lists_public is in the CREATE too', str_contains($stmts, '`lists_public`'));
$idx = array_column($db->query("SHOW INDEX FROM user_list_items")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
check('adding the same hash twice cannot make a longer list', in_array('uq_list_item', $idx, true), implode(',', array_unique($idx)));
check('the "which lists is this hash on" index exists', in_array('idx_item_hash', $idx, true), implode(',', array_unique($idx)));

/* ── 2. the gates, and the order they are in ──────────────────────────────── */
$on = ['users_enabled' => '1', 'lists_enabled' => '1', 'lists_public_enabled' => '1'];
check('listsEnabled needs the user system', !listsEnabled(['lists_enabled' => '1']));
check('a public list needs lists first', !listsPublicEnabled(['users_enabled' => '1', 'lists_public_enabled' => '1']));
check('both on means both on', listsEnabled($on) && listsPublicEnabled($on));
check('the per-user ceiling is clamped, never trusted',
    listsMaxPerUser(['lists_max_per_user' => '99999']) === 200 && listsMaxPerUser(['lists_max_per_user' => '0']) === 20);
check('and so is the per-list one',
    listsMaxItems(['lists_max_items' => '99999']) === 5000 && listsMaxItems(['lists_max_items' => '1']) === 10);

/* ── 3. the slug is derived once and then left alone ──────────────────────── */
check('a name becomes an address', listSlugify('My Films 2026!') === 'my-films-2026', listSlugify('My Films 2026!'));
check('… and a name with nothing usable in it still becomes one', listSlugify('!!!') === 'list', listSlugify('!!!'));
$listSrc = (string)@file_get_contents($root . '/api/user_lists.php');
$renamePos = strpos($listSrc, '$op === ' . chr(39) . 'rename' . chr(39));
$renameSeg = $renamePos === false ? '' : substr($listSrc, $renamePos, 700);
check('renaming does NOT rebuild the slug — a link somebody was sent keeps working',
    $renameSeg !== '' && str_contains($renameSeg, 'UPDATE user_lists SET name = ?')
    && !str_contains($renameSeg, 'listUniqueSlug'), substr($renameSeg, 0, 120));

/* ── 4. THE TRUTH TABLE: five answers, and any single no hides the list ───── */
$db->prepare("DELETE FROM users WHERE username IN ('listowner')")->execute();
$mk = userCreate($db, $cfg, 'listowner', 'listowner@example.org', 'ListOwner123!', '127.0.0.1');
$uid = (int)($mk['user']['id'] ?? $mk['id'] ?? 0);
check('a test account was made for this', $uid > 0, json_encode($mk));
$db->prepare("UPDATE users SET status = 'active', email_verified = 1, lists_public = 1 WHERE id = ?")->execute([$uid]);

// A group that grants exactly what a list needs, and nothing else.
$db->prepare("DELETE FROM user_groups WHERE slug = 'listtest'")->execute();
$db->prepare("INSERT INTO user_groups (slug, name, description, priority, is_default, is_system, permissions)
              VALUES ('listtest', 'List test', 'fixture', 5, 0, 0, ?)")
   ->execute([json_encode(['lists.use' => true, 'lists.public' => true, 'favourites.view_others' => true])]);
$gid = (int)$db->lastInsertId();
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$uid]);
// granted_at spelled out: this client and the application may be on different clocks, and a row
// stamped into the future gives the account no groups at all.
$db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at) VALUES (?, ?, '2000-01-01 00:00:00')")
   ->execute([$uid, $gid]);

$HASH = str_repeat('5c', 20);
$db->prepare("DELETE FROM user_lists WHERE user_id = ?")->execute([$uid]);
$db->prepare("INSERT INTO user_lists (user_id, name, slug, is_public) VALUES (?, 'Public pack', 'public-pack', 1)")->execute([$uid]);
$lid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO user_list_items (list_id, info_hash, name) VALUES (?, ?, 'Fixture')")->execute([$lid, $HASH]);

$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'lists_enabled' => '1', 'lists_public_enabled' => '1']);
$owner = userFindById($db, $uid);
$named = static fn(array $rows) => implode(',', array_column($rows, 'name'));

check('with every answer yes, the list is on the hash', $named(listsContainingHash($db, $cfgOn, $HASH)) === 'Public pack',
    $named(listsContainingHash($db, $cfgOn, $HASH)));
check('… and the profile would show the section', listsVisibleFor($db, $cfgOn, $owner));

// (1) the master switch
check('lists off → the hash is on nothing',
    listsContainingHash($db, array_merge($cfgOn, ['lists_enabled' => '0']), $HASH) === []);
// (2) the site-wide public switch
check('public lists off → the hash is on nothing',
    listsContainingHash($db, array_merge($cfgOn, ['lists_public_enabled' => '0']), $HASH) === []);
check('… and the profile section goes with it',
    !listsVisibleFor($db, array_merge($cfgOn, ['lists_public_enabled' => '0']), $owner));
// (3) the owner's group
$db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")
   ->execute([json_encode(['lists.use' => true]), $gid]);
check('the group losing lists.public takes the list off the hash', listsContainingHash($db, $cfgOn, $HASH) === []);
check('… and off the profile', !listsVisibleFor($db, $cfgOn, $owner));
$db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")
   ->execute([json_encode(['lists.use' => true, 'lists.public' => true, 'favourites.view_others' => true]), $gid]);
// (4) the owner's section flag
$db->prepare("UPDATE users SET lists_public = 0 WHERE id = ?")->execute([$uid]);
check('the owner hiding the section takes the list off the hash', listsContainingHash($db, $cfgOn, $HASH) === []);
$db->prepare("UPDATE users SET lists_public = 1 WHERE id = ?")->execute([$uid]);
// (5) the list's own flag
$db->prepare("UPDATE user_lists SET is_public = 0 WHERE id = ?")->execute([$lid]);
check('a private list is not on the hash either', listsContainingHash($db, $cfgOn, $HASH) === []);
$db->prepare("UPDATE user_lists SET is_public = 1 WHERE id = ?")->execute([$lid]);
check('and putting the last answer back puts it there again',
    $named(listsContainingHash($db, $cfgOn, $HASH)) === 'Public pack');
// a suspended account is nobody's public list
$db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$uid]);
check('a suspended account publishes nothing', listsContainingHash($db, $cfgOn, $HASH) === []);
$db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$uid]);

/* ── 5. a name that outlives the catalogue ────────────────────────────────── */
$rows = [['info_hash' => $HASH, 'name' => 'Recorded when added', 'added_at' => '2026-01-01 00:00:00']];
$meta = listItemsWithMeta($db, $rows);
check('a hash the catalogue never knew keeps the name it was added with',
    ($meta[0]['name'] ?? '') === 'Recorded when added' && ($meta[0]['gone'] ?? false) === true, json_encode($meta[0] ?? []));

/* ── 6. deleting the account takes the lists AND their rows ───────────────── */
$gone = userDeleteCascade($db, $uid);
$left = function (string $sql, array $args) use ($db): int {
    $st = $db->prepare($sql); $st->execute($args); return (int)$st->fetchColumn();
};
check('the account is gone', $left("SELECT COUNT(*) FROM users WHERE id = ?", [$uid]) === 0);
check('its lists are gone', $left("SELECT COUNT(*) FROM user_lists WHERE user_id = ?", [$uid]) === 0);
check('and the rows inside them, which are keyed by list and not by user',
    $left("SELECT COUNT(*) FROM user_list_items WHERE list_id = ?", [$lid]) === 0, json_encode($gone));
$db->prepare("DELETE FROM user_groups WHERE id = ?")->execute([$gid]);

/* ── 7. the settings exist in every place a setting has to exist ──────────── */
$defaults = trackerSchemaDefaultSettings();
foreach (['lists_enabled' => '0', 'lists_public_enabled' => '0'] as $k => $v) {
    check("$k ships as '$v'", ($defaults[$k] ?? null) === $v, var_export($defaults[$k] ?? null, true));
}
$catalogue = function_exists('settingsSearchWords') ? settingsSearchWords() : [];
$saveSrc = (string)@file_get_contents($root . '/api/admin/save_settings.php');
$setTpl  = (string)@file_get_contents($root . '/templates/admin/settings.php');
foreach (['lists_enabled', 'lists_public_enabled', 'lists_max_per_user', 'lists_max_items'] as $k) {
    check("$k is in the save allow-list", str_contains($saveSrc, "'$k'"));
    check("$k has a control on the Settings page", str_contains($setTpl, "name=\"$k\""));
}

/* ── 8. the endpoints refuse everything while the feature is off ──────────── */
foreach (['api/user_lists.php', 'api/user_list_items.php'] as $f) {
    $src = (string)@file_get_contents($root . '/' . $f);
    check(basename($f) . ' answers 404 while lists are off', str_contains($src, "if (!listsEnabled(\$cfg)) jsonResponse"));
    check(basename($f) . ' checks the CSRF token before it writes', str_contains($src, 'verifyCsrfToken'));
    check(basename($f) . ' scopes every write to the owner', str_contains($src, 'user_id = ?'));
}

/* ══════════════ 1.70.0 D — a list's description as rich text, and "Edit" ══════════════
 *
 * The owner asked for descriptions on lists, written with BBCode or Markdown and the emoji, in a window
 * the card's "Rename" (now "Edit") opens. What is proved here, without a browser: the shape the schema
 * gives the column on a fresh install and on an upgraded one, the setting in its four places, the old
 * plain descriptions rewritten so that nothing in them becomes markup, the one request that saves the
 * name and the description (every refusal, every limit, the mute, the rate limit, CSRF — the endpoint
 * itself run as a request), the rendering (hostile BBCode and Markdown, the emotes, no picture from
 * elsewhere), and the counter's twin in assets/js/favourites.js giving the server's number. The window
 * itself is scratchpad/shots/lists_check.js's.
 */
langInit($cfg, 'en');

/* ── 9. v80: the description's shape, fresh and upgraded ──────────────────── */
check('1.70.0: the schema is at 80 or later', TRACKER_SCHEMA_VERSION >= 80 && (int)($cfg['schema_version'] ?? 0) >= 80, (string)($cfg['schema_version'] ?? ''));
$createOf = static function (array $list): string {
    foreach ($list as $s) if (is_string($s) && str_contains($s, 'CREATE TABLE IF NOT EXISTS `user_lists`')) return $s;
    return '';
};
$freshSql = $createOf(trackerSchemaStatements());
$guardSql = $createOf(trackerSchemaGuardedStatements($db));
$v80Shape = static fn(string $s): bool => str_contains($s, '`description` TEXT DEFAULT NULL')
    && str_contains($s, "`description_format` ENUM('bbcode','markdown') NOT NULL DEFAULT 'bbcode'") && !str_contains($s, 'VARCHAR(500)');
check('fresh path: the CREATE makes the description TEXT (NULL = none) and its format an ENUM of the two syntaxes, BBCode by default',
      $v80Shape($freshSql), substr($freshSql, 0, 240));
check('… and the copy the upgrade list carries says exactly the same', $v80Shape($guardSql));
$liveCols = [];
foreach ($db->query("SHOW COLUMNS FROM user_lists")->fetchAll(PDO::FETCH_ASSOC) as $c) $liveCols[$c['Field']] = $c;
check('the live table — an UPGRADED one — has both: description text NULL, description_format enum(bbcode, markdown) NOT NULL default bbcode',
      strtolower((string)($liveCols['description']['Type'] ?? '')) === 'text' && ($liveCols['description']['Null'] ?? '') === 'YES'
      && strtolower((string)($liveCols['description_format']['Type'] ?? '')) === "enum('bbcode','markdown')"
      && ($liveCols['description_format']['Null'] ?? '') === 'NO' && ($liveCols['description_format']['Default'] ?? '') === 'bbcode',
      json_encode([$liveCols['description'] ?? null, $liveCols['description_format'] ?? null]));
check('… and the migration asks for nothing more once it is there', schemaListDescMigration($db) === []);

// The upgrade itself, walked on a scratch table of 1.69.0's shape, against a scratch table today's CREATE makes.
$sfx = getmypid() . '_' . bin2hex(random_bytes(2));
$oldT = 'lists_v79_' . $sfx;
$newT = 'lists_v80_' . $sfx;
$plainRows = [
    'empty'   => '',
    'hostile' => '[b]not bold[/b] [url=https://e.example]x[/url] [img]https://e.example/p.png[/img] [hide]h[/hide] :fire: :flame: '
               . ':fa-face-grin-tears: https://e.example/a <script>alert(1)</script> & 12:30',
    'long'    => str_repeat('[i]', 166) . 'xy',      // 500 characters, which the rewrite takes past 500
    'lines'   => "line one\r\nline two\n\nafter a blank",
    'plain'   => 'Just words, nothing to escape.',
];
try {
    $db->exec("CREATE TABLE `$oldT` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `name` VARCHAR(80) NOT NULL,
        `slug` VARCHAR(90) NOT NULL,
        `description` VARCHAR(500) NOT NULL DEFAULT '',
        `is_public` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_list_slug` (`user_id`, `slug`),
        KEY `idx_list_user` (`user_id`, `updated_at`),
        KEY `idx_list_public` (`is_public`, `updated_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec(str_replace('CREATE TABLE IF NOT EXISTS `user_lists`', "CREATE TABLE `$newT`", $freshSql));
    $ins = $db->prepare("INSERT INTO `$oldT` (user_id, name, slug, description, updated_at) VALUES (1, ?, ?, ?, '2020-01-02 03:04:05')");
    foreach ($plainRows as $k => $v) $ins->execute([$k, $k, $v]);
    $wantUpdates = count(array_filter($plainRows, static fn($v) => $v !== '' && schemaListDescPlainToBbcode($v) !== $v));
    $stmts = schemaListDescMigration($db, $oldT);
    $updates = array_values(array_filter($stmts, static fn($s) => str_starts_with($s, 'UPDATE')));
    check('upgrade path: the column made TEXT first, then one rewrite per text that needs one, the format column LAST',
          count($stmts) === $wantUpdates + 2 && str_starts_with($stmts[0], "ALTER TABLE `$oldT` MODIFY COLUMN `description` TEXT DEFAULT NULL")
          && str_contains((string)end($stmts), "ADD COLUMN `description_format` ENUM('bbcode','markdown') NOT NULL DEFAULT 'bbcode'")
          && count($updates) === $wantUpdates && $wantUpdates === 2, json_encode($stmts));
    check('… each rewrite holds updated_at where it was, so no list moves on its owner\'s shelf',
          $updates !== [] && !array_filter($updates, static fn($s) => !str_contains($s, '`updated_at` = `updated_at`')));
    // Stopped before the format column arrived (a timeout, a restart): the same question, asked again.
    foreach (array_slice($stmts, 0, -1) as $s) $db->exec($s);
    $again = schemaListDescMigration($db, $oldT);
    check('a run that stopped before the format column is run again: the column change, NO second rewrite, the column',
          count($again) === 2 && str_contains($again[0], 'MODIFY COLUMN') && str_contains($again[1], 'ADD COLUMN'), json_encode($again));
    foreach ($again as $s) $db->exec($s);
    check('… and once the column is there, nothing', schemaListDescMigration($db, $oldT) === []);
    $colsOf = static function (PDO $db, string $t): array {
        $o = [];
        foreach ($db->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $o[$c['Field']] = strtolower((string)$c['Type']) . '|' . $c['Null'] . '|' . var_export($c['Default'], true) . '|' . strtolower((string)$c['Extra']);
        }
        ksort($o);
        return $o;
    };
    $co = $colsOf($db, $oldT);
    $cn = $colsOf($db, $newT);
    check('the upgraded table and one made by today\'s CREATE are the same: every column, its type, NULL and default',
          $co === $cn, json_encode(['upgraded' => array_diff_assoc($co, $cn), 'fresh' => array_diff_assoc($cn, $co)]));
    $got = [];
    foreach ($db->query("SELECT name, description, description_format, updated_at FROM `$oldT`")->fetchAll(PDO::FETCH_ASSOC) as $r) $got[$r['name']] = $r;
    $rewrittenOk = true;
    foreach ($plainRows as $k => $v) {
        $rewrittenOk = $rewrittenOk && (string)($got[$k]['description'] ?? '?') === schemaListDescPlainToBbcode($v)
                     && ($got[$k]['description_format'] ?? '') === 'bbcode' && ($got[$k]['updated_at'] ?? '') === '2020-01-02 03:04:05';
    }
    check('every old text is its BBCode-safe rewrite, in the BBCode format, its updated_at untouched', $rewrittenOk, json_encode($got));
    check('the 500-character text grew past its old column (TEXT first) and kept every character',
          mb_strlen((string)$got['long']['description']) === 666 && str_replace("\u{2060}", '', (string)$got['long']['description']) === $plainRows['long']);
} catch (\Throwable $e) {
    check('the upgrade walk ran', false, $e->getMessage());
} finally {
    $db->exec("DROP TABLE IF EXISTS `$oldT`");
    $db->exec("DROP TABLE IF EXISTS `$newT`");
}

/* ── 10. the setting, in its four places (and the dictionary) ────────────── */
$defaults = trackerSchemaDefaultSettings();
check('lists_desc_max ships at 1000 (visible characters)', ($defaults['lists_desc_max'] ?? null) === '1000' && LIST_DESC_MAX_DEFAULT === 1000);
$saveSrc = (string)@file_get_contents($root . '/api/admin/save_settings.php');
check('… is in the save allow-list, clamped by the same constants the code clamps with',
      str_contains($saveSrc, "'lists_desc_max',") && str_contains($saveSrc, "'lists_desc_max' => [LIST_DESC_MAX_MIN, LIST_DESC_MAX_MAX, LIST_DESC_MAX_DEFAULT]"));
$setTpl = (string)@file_get_contents($root . '/templates/admin/settings.php');
$secLists = (string)substr($setTpl, (int)strpos($setTpl, 'id="section-lists"'), 5200);
check('… has its control in Settings → Profiles → Lists, with the same bounds and a hint',
      str_contains($secLists, 'data-group="profiles"') && str_contains($secLists, 'data-setting="lists_desc_max"') && str_contains($secLists, 'name="lists_desc_max"')
      && str_contains($secLists, 'min="<?= LIST_DESC_MAX_MIN ?>" max="<?= LIST_DESC_MAX_MAX ?>"') && str_contains($secLists, "__('settings.lists_desc_max_hint'"));
$kw = settingsCatalogKeywords();
check('… and has the Settings search\'s words, in both languages', str_contains((string)($kw['lists_desc_max'] ?? ''), 'description') && str_contains((string)($kw['lists_desc_max'] ?? ''), 'opis'));
$en = require $root . '/lang/en.php';
$pl = require $root . '/lang/pl.php';
$langOk = true;
foreach (['settings.lists_desc_max', 'settings.lists_desc_max_hint', 'lists.edit_title', 'js.lists.edit', 'api.lists.too_long', 'api.lists.no_images'] as $k) {
    $langOk = $langOk && trim((string)($en[$k] ?? '')) !== '' && trim((string)($pl[$k] ?? '')) !== '' && $en[$k] !== $pl[$k];
}
check('its words and the window\'s exist in both languages, and the card says Edit / Edytuj where it said Rename',
      $langOk && $en['js.lists.edit'] === 'Edit' && $pl['js.lists.edit'] === 'Edytuj' && !isset($en['js.lists.rename']) && !isset($pl['js.lists.rename']));
check('the maximum is clamped, never trusted: 50 to 5000, 1000 for nothing or nonsense',
      listsDescMax(['lists_desc_max' => '99999']) === 5000 && listsDescMax(['lists_desc_max' => '1']) === 50
      && listsDescMax(['lists_desc_max' => '']) === 1000 && listsDescMax(['lists_desc_max' => 'x']) === 1000 && listsDescMax([]) === 1000);
check('the text as typed may be four times the visible limit, and never more than 16 000 characters',
      listDescSourceCap(['lists_desc_max' => '1000']) === 4000 && listDescSourceCap(['lists_desc_max' => '5000']) === 16000);

/* ── 11. the old plain text: nothing in it becomes markup ─────────────────── */
// Everything the renderer and its two stages would make of a plain text by accident, and the rewrite's
// promise over each: rendered as BBCode it is exactly its words — no tag but paragraphs and line breaks,
// no emoji, no icon, no emote, no link — the count is what a reader sees, and a second rewrite changes nothing.
$cfgAll = array_merge($cfg, ['users_enabled' => '1', 'lists_enabled' => '1', 'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1',
                             'shout_enabled' => '1', 'shout_emotes_enabled' => '1', 'emotes_everywhere' => '1']);
$hostilePlain = [
    '[b]bold[/b] [i]x[/i] [U]u[/U] [s]s[/s] [center]c[/center] [hr] [*] [/quote] [color=red]r[/color]',
    '[url=https://evil.example]click[/url] [url]https://evil.example[/url] [email]a@b.example[/email] [youtube]dQw4w9WgXcQ[/youtube]',
    '[img]https://evil.example/pixel.png[/img] [IMG]https://evil.example/p.png[/IMG]',
    '[hide]secret[/hide] [postshide]x[/postshide] [spoiler]s[/spoiler] [code]c[/code] [table][tr][td]1[/td][/tr][/table]',
    ':fire: :rocket: :flame: :fa-rocket: :fa-face-grin-tears: :fa-rocket/solid: :a:b:c:',
    'https://evil.example/path http://plain.example xhttps://x.example/y HTTPS://UPPER.EXAMPLE',
    '<script>alert(1)</script> <img src=x onerror=alert(1)> &amp; &lt;b&gt;',
    "tabs\tand\r\nlines\n\n\nand controls \x00\x01\x02\x07\x1B\x7F end",
    '**not bold** *not em* # not a heading > not a quote [x](https://e.example) ![y](https://e.example/y.png)',
];
$seenOf = static function (string $html): string {
    // A paragraph's end and a line break are white space to a reader; strip_tags() alone would glue the words.
    $t = html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('/[ \t\n\x0B\f]+/u', ' ', str_replace("\u{2060}", '', $t)));
};
$plainBad = [];
$controlBad = 0;
foreach ($hostilePlain as $p) {
    $esc = schemaListDescPlainToBbcode($p);
    $html = listDescRender($db, $cfgAll, $esc, 'bbcode');
    preg_match_all('/<\s*\/?\s*([a-zA-Z][a-zA-Z0-9]*)/', $html, $tm);
    $tags = array_unique(array_map('strtolower', $tm[1]));
    $words = trim((string)preg_replace('/[ \t\n\x0B\f]+/u', ' ', (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace("\r\n", "\n", $p))));
    if (array_diff($tags, ['p', 'br']) !== []) $plainBad[] = 'tags ' . implode(',', $tags) . ' from ' . mb_substr($p, 0, 30);
    if ($seenOf($html) !== $words) $plainBad[] = 'reads ' . json_encode($seenOf($html)) . ' for ' . json_encode($words);
    if (listDescVisible($esc, 'bbcode') !== mb_strlen($words)) $plainBad[] = 'count ' . listDescVisible($esc, 'bbcode') . ' for ' . mb_strlen($words);
    if (schemaListDescPlainToBbcode($esc) !== $esc) $plainBad[] = 'not idempotent: ' . mb_substr($p, 0, 30);
    // The control: the same text NOT rewritten does become markup — so the checks above are about something.
    if (preg_match('/<(?!\/?(?:p|br)\b)/', listDescRender($db, $cfgAll, $p, 'bbcode')) || $seenOf(listDescRender($db, $cfgAll, $p, 'bbcode')) !== $words) $controlBad++;
}
check('an old plain text, rewritten, reads as exactly its words: no tag but paragraphs and breaks, no emoji, icon, emote or link, and a second rewrite changes nothing',
      $plainBad === [], implode(' | ', array_slice($plainBad, 0, 3)));
check('… where the same texts NOT rewritten would have become markup (the control: 8 of the 9 do)', $controlBad >= 8, (string)$controlBad);
check('the rewrite is the invisible word joiner, and only where a rule would begin: a text with nothing to escape is left byte for byte',
      schemaListDescPlainToBbcode('[b]x') === "[\u{2060}b]x" && schemaListDescPlainToBbcode(':fire:') === ":\u{2060}fire:"
      && schemaListDescPlainToBbcode('see https://a.example') === "see https\u{2060}://a.example"
      && schemaListDescPlainToBbcode('Kraków, 12 [2024] — ok: yes') === 'Kraków, 12 [2024] — ok: yes');

/* ── 12. the one request that saves the name and the description ─────────── */
$fx = ['listdescowner', 'listdescpeer', 'listdescnobody'];
$fxIds = [];
$fxGroup = 0;
$fxLists = [];
$cleanupD = static function () use ($db, $fx, &$fxIds, &$fxGroup) {
    foreach ($fxIds as $id) {
        $db->prepare("DELETE i FROM user_list_items i JOIN user_lists l ON l.id = i.list_id WHERE l.user_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM user_lists WHERE user_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM user_notifications WHERE user_id = ?")->execute([$id]);
    }
    foreach ($fx as $u) $db->prepare("DELETE FROM users WHERE username = ?")->execute([$u]);
    if ($fxGroup > 0) $db->prepare("DELETE FROM user_groups WHERE id = ?")->execute([$fxGroup]);
    $db->prepare("DELETE FROM user_groups WHERE slug = 'listdesctest'")->execute();
};
$cleanupD();
try {
    foreach ($fx as $u) {
        $r = userCreate($db, $cfg, $u, $u . '@example.org', 'ListDesc123!', '127.0.0.1');
        $fxIds[$u] = (int)($r['user']['id'] ?? $r['id'] ?? 0);
    }
    check('fixtures: three accounts (the owner, a member who reads, one without lists)', count(array_filter($fxIds)) === 3, json_encode($fxIds));
    $db->prepare("UPDATE users SET status = 'active', email_verified = 1, lists_public = 1 WHERE username IN ('listdescowner', 'listdescpeer', 'listdescnobody')")->execute();
    $db->prepare("INSERT INTO user_groups (slug, name, description, priority, is_default, is_system, permissions)
                  VALUES ('listdesctest', 'List description test', 'fixture', 5, 0, 0, ?)")
       ->execute([json_encode(['lists.use' => true, 'lists.public' => true, 'favourites.view_others' => true, 'index.view' => true])]);
    $fxGroup = (int)$db->lastInsertId();
    foreach ($fxIds as $u => $id) {
        $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
        if ($u !== 'listdescnobody') {
            $db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at) VALUES (?, ?, '2000-01-01 00:00:00')")->execute([$id, $fxGroup]);
        }
    }
    $mkList = static function (int $uid, string $name, string $slug, int $public, string $desc = '', string $fmt = 'bbcode') use ($db): int {
        $db->prepare("INSERT INTO user_lists (user_id, name, slug, description, description_format, is_public) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$uid, $name, $slug, $desc, $fmt, $public]);
        return (int)$db->lastInsertId();
    };
    $ownerId = $fxIds['listdescowner'];
    $fxLists['pub'] = $mkList($ownerId, 'Desc pack', 'desc-pack', 1);
    $fxLists['priv'] = $mkList($ownerId, 'Secret pack', 'secret-pack', 0, 'Private words [b]only[/b]');
    $fxLists['peer'] = $mkList($fxIds['listdescpeer'], 'Peer pack', 'peer-pack', 0);

    $cfgE = array_merge($cfgAll, ['lists_desc_max' => '50', 'desc_max_links' => '2']);
    $rowOf = static function (int $id) use ($db): array {
        $st = $db->prepare("SELECT * FROM user_lists WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $meOwner = static fn(): array => userFindById($db, $ownerId) ?? [];
    $edit = static function (array $in, bool $withName = true, ?array $cfgX = null) use ($db, $cfgE, $rowOf, $meOwner, &$fxLists): array {
        return listEditRequest($db, $cfgX ?? $cfgE, $meOwner(), $rowOf($fxLists['pub']), $in, $withName);
    };
    $err = static fn(array $r): string => (string)($r['status'] ?? '') . ' ' . (string)($r['body']['error'] ?? '');

    // The name.
    check('a name is required: empty, blank or not text is 400 name_required',
          $err($edit(['name' => ''])) === '400 name_required' && $err($edit(['name' => "  \t "])) === '400 name_required'
          && $err($edit(['name' => ['x']])) === '400 name_required');
    $long = $edit(['name' => str_repeat('n', 81)]);
    check('a name over 80 characters is refused — not cut, as the old rename did — and the answer says the limit',
          $err($long) === '400 name_too_long' && str_contains((string)$long['body']['message'], '80'), json_encode($long));
    $r = $edit(['name' => str_repeat('ą', 80), 'description' => '']);
    check('80 characters (Polish ones included) are a name', ($r['status'] ?? 0) === 200 && $rowOf($fxLists['pub'])['name'] === str_repeat('ą', 80), json_encode($r['body'] ?? []));
    $r = $edit(['name' => 'Renamed pack', 'description' => '']);
    check('renaming keeps the slug: a link somebody was given keeps working', ($r['body']['slug'] ?? '') === 'desc-pack' && $rowOf($fxLists['pub'])['slug'] === 'desc-pack'
          && $rowOf($fxLists['pub'])['name'] === 'Renamed pack');

    // The text's size and encoding, before anything walks it.
    check('a description that is not text is 400 invalid', $err($edit(['name' => 'N', 'description' => ['x']])) === '400 invalid');
    check('a request of more than 64 KiB is refused for its size (413) before it is read', $err($edit(['name' => 'N', 'description' => str_repeat('a', 65537)])) === '413 too_long_source');
    check('bytes that are not UTF-8 are 400 bad_encoding', $err($edit(['name' => 'N', 'description' => "ok \xff\xfe"])) === '400 bad_encoding');

    // The syntax.
    check('a format that is not one of the site\'s is 400 bad_format — with text; without text there is nothing to refuse',
          $err($edit(['name' => 'N', 'description' => 'x', 'format' => 'html'])) === '400 bad_format'
          && $err($edit(['name' => 'N', 'description' => 'x', 'format' => ['bbcode']])) === '400 bad_format'
          && ($edit(['name' => 'N', 'description' => '', 'format' => 'html'])['status'] ?? 0) === 200 && $rowOf($fxLists['pub'])['description_format'] === 'bbcode');
    check('Markdown switched off in Settings is not a syntax a new description may use',
          $err($edit(['name' => 'N', 'description' => '**x**', 'format' => 'markdown'], true, array_merge($cfgE, ['desc_allow_markdown' => '0']))) === '400 bad_format');

    // What counts is what a reader sees (the limit is 50 here).
    $fifty = '[b][i][u]' . str_repeat('x', 50) . '[/u][/i][/b]';
    $r = $edit(['name' => 'N', 'description' => $fifty, 'format' => 'bbcode']);
    check('fifty visible characters inside three tags are fifty: the tags are not counted', ($r['status'] ?? 0) === 200 && ($r['body']['chars'] ?? 0) === 50 && ($r['body']['max'] ?? 0) === 50, json_encode($r['body'] ?? []));
    $r51 = $edit(['name' => 'N', 'description' => str_repeat('x', 51)]);
    check('fifty-one are refused (400 too_long), and the answer names both numbers', $err($r51) === '400 too_long'
          && str_contains((string)$r51['body']['message'], '51') && str_contains((string)$r51['body']['message'], '50'), json_encode($r51));
    check('a token counts as it is typed (:flame: is seven), an emoji as one, a link as its words, a line break as one space',
          listDescVisible(':flame: ' . "\u{1F600}", 'bbcode') === 9 && listDescVisible('[url=https://e.example]words[/url]', 'bbcode') === 5
          && listDescVisible("# Title\n- **one**\n[two](https://e.example)", 'markdown') === 13,
          json_encode([listDescVisible(':flame: ' . "\u{1F600}", 'bbcode'), listDescVisible('[url=https://e.example]words[/url]', 'bbcode'),
                       listDescStrip("# Title\n- **one**\n[two](https://e.example)", 'markdown')]));
    check('the text as typed has its own ceiling (four times the limit): tags cannot store a novel',
          $err($edit(['name' => 'N', 'description' => str_repeat('[b][/b]', 29) . 'x'])) === '400 too_long_source');

    // No pictures from elsewhere; the room's emotes are words.
    check('a picture is refused, in either syntax (400 no_images)',
          $err($edit(['name' => 'N', 'description' => '[img]https://e.example/p.png[/img]'])) === '400 no_images'
          && $err($edit(['name' => 'N', 'description' => '![p](https://e.example/p.png)', 'format' => 'markdown'])) === '400 no_images');
    check('an emote is not a picture: :flame: saves', ($edit(['name' => 'N', 'description' => 'Hot :flame:'])['status'] ?? 0) === 200);
    check('links: the description\'s limit (two here) — a third is 400 too_many_links',
          $err($edit(['name' => 'N', 'description' => 'https://a.io https://b.io https://c.io'])) === '400 too_many_links'
          && ($edit(['name' => 'N', 'description' => 'https://a.io https://b.io'])['status'] ?? 0) === 200);

    // What is kept, and how.
    $r = $edit(['name' => 'N', 'description' => "  one\r\ntwo \u{202E}evil\u{202C} \x07bell\u{2060}  ", 'format' => 'markdown']);
    $row = $rowOf($fxLists['pub']);
    check('kept cleaned: one kind of line break, no bidi override, no control, trimmed — the word joiner (v80\'s escape) stays',
          ($r['status'] ?? 0) === 200 && $row['description'] === "one\ntwo evil bell\u{2060}" && $row['description_format'] === 'markdown', json_encode($row['description']));
    $r = $edit(['name' => 'Kept']);
    check('an edit that says nothing about the description does not wipe it', ($r['status'] ?? 0) === 200 && $rowOf($fxLists['pub'])['description'] === "one\ntwo evil bell\u{2060}");
    $r = $edit(['value' => '[b]Described[/b] :flame:', 'format' => 'bbcode'], false);
    $row = $rowOf($fxLists['pub']);
    check('`describe` is the same request without the name: the description changes, the name does not',
          ($r['status'] ?? 0) === 200 && $row['description'] === '[b]Described[/b] :flame:' && $row['name'] === 'Kept');
    check('the answer carries the card\'s excerpt — plain, no markup — the visible count and the limit',
          ($r['body']['excerpt'] ?? '') === 'Described :flame:' && ($r['body']['chars'] ?? 0) === 17 && ($r['body']['description_format'] ?? '') === 'bbcode'
          && ($r['body']['message'] ?? '') === 'Saved.', json_encode($r['body']));

    // What is not changed is not judged again.
    $db->prepare("UPDATE user_lists SET description = ?, description_format = 'bbcode' WHERE id = ?")->execute([str_repeat('y', 60), $fxLists['pub']]);
    check('a description written under a longer limit survives a rename (it is not judged again) …',
          ($edit(['name' => 'Renamed again', 'description' => str_repeat('y', 60), 'format' => 'bbcode'])['status'] ?? 0) === 200);
    check('… and is judged the moment it changes', $err($edit(['name' => 'Renamed again', 'description' => str_repeat('y', 61)])) === '400 too_long');

    // A moderator's mute.
    $db->prepare("UPDATE users SET pm_muted_until = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ?")->execute([$ownerId]);
    $m = $edit(['name' => 'Renamed again', 'description' => 'Something new']);
    check('muted by a moderator: a new description is refused (403 muted), saying until when', $err($m) === '403 muted' && str_contains((string)$m['body']['message'], date('Y')), json_encode($m));
    check('… a rename that leaves the description alone is not', ($edit(['name' => 'Muted rename', 'description' => str_repeat('y', 60)])['status'] ?? 0) === 200);
    check('… and taking the description away never waits for anybody', ($edit(['name' => 'Muted rename', 'description' => ''])['status'] ?? 0) === 200 && $rowOf($fxLists['pub'])['description'] === '');
    $db->prepare("UPDATE users SET pm_muted_until = NULL WHERE id = ?")->execute([$ownerId]);

    $listSrc2 = (string)@file_get_contents($root . '/api/user_lists.php');
    $libSrc = (string)@file_get_contents($root . '/includes/lists.php');
    $reqAt = (int)strpos($libSrc, 'function listEditRequest(');
    $reqSrc = (string)substr($libSrc, $reqAt, (int)strpos($libSrc, "\n}\n", $reqAt) - $reqAt);
    check('the endpoint sends `edit` and `describe` through that one function; nothing else writes a description',
          str_contains($listSrc2, "\$op === 'edit' || \$op === 'describe'") && str_contains($listSrc2, "listEditRequest(\$db, \$cfg, \$me, \$own, \$input, \$op === 'edit')")
          && substr_count($listSrc2, 'SET description') === 0 && !str_contains($listSrc2, 'mb_substr(trim((string)($input[\'value\']'));
    check('… whose write is the owner\'s row by id AND owner, and never touches the slug',
          str_contains($reqSrc, 'UPDATE user_lists SET name = ?, description = ?, description_format = ? WHERE id = ? AND user_id = ?') && !str_contains($reqSrc, 'slug ='));

    /* ── 13. the endpoints, run as requests ──────────────────────────────── */
    $runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstd_runner_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = $a["ip"];
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php", "includes/whitelist.php",
          "includes/index.php", "includes/richtext.php", "includes/mail.php", "includes/users.php", "includes/favourites.php", "includes/lists.php",
          "includes/people.php", "includes/profilebio.php", "includes/shout.php", "includes/audit.php", "includes/auth.php", "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () {
    fwrite(STDERR, "STATUS:" . (int)http_response_code() . "\n");
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
langInit($cfg, "en");
require "api/" . $a["endpoint"] . ".php";
');
    $ask = static function (string $endpoint, string $method, array $cfgX, array $session, array $get = [], array $post = [], string $ip = '127.0.0.9') use ($root, $runner): array {
        $arg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstd_args_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($arg, json_encode(['root' => $root, 'endpoint' => $endpoint, 'method' => $method, 'get' => $get, 'post' => $post,
                                             'cfg' => $cfgX, 'session' => $session, 'ip' => $ip, 'sid' => 'lstd' . bin2hex(random_bytes(8))]));
        $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'xdebug.mode=off', $runner, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string)stream_get_contents($pipes[1]);
        $errOut = (string)stream_get_contents($pipes[2]);
        foreach ($pipes as $h) fclose($h);
        proc_close($p);
        @unlink($arg);
        $j = json_decode(trim($out), true);
        $j = is_array($j) ? $j : ['__raw' => substr($out . ' ' . $errOut, 0, 300)];
        $j['__status'] = preg_match('/STATUS:(\d+)/', $errOut, $sm) ? (int)$sm[1] : 0;
        return $j;
    };
    // The anti-spam layer (1.71.0) is OFF here, explicitly: these requests come faster than a person writes and their
    // owner is a fresh account, whose links the layer would draw as text — the layer is tests/antispam_test.php's.
    $cfgHttp = ['users_enabled' => '1', 'lists_enabled' => '1', 'lists_public_enabled' => '1', 'profiles_enabled' => '1', 'lists_desc_max' => '1000',
                'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1', 'desc_max_links' => '10', 'rate_limit_favourites' => '1000',
                'shout_enabled' => '1', 'shout_emotes_enabled' => '1', 'emotes_everywhere' => '1', 'user_require_email_verify' => '0',
                'antispam_enabled' => '0'];
    $sess = static fn(int $uid): array => ['user_id' => $uid, 'user_login_time' => time() + 60, 'csrf_token' => 'lstd-token'];
    $ip = '203.0.113.' . random_int(10, 250);
    $body = ['op' => 'edit', 'id' => $fxLists['pub'], 'name' => 'Desc pack', 'description' => "[b]Bold[/b] and a [url=https://example.org]link[/url] :flame:", 'format' => 'bbcode'];

    $j = $ask('user_lists', 'POST', $cfgHttp, $sess($ownerId), [], $body, $ip);
    check('POST edit without the token: 403, nothing written', $j['__status'] === 403 && $rowOf($fxLists['pub'])['description'] === '', json_encode($j));
    $j = $ask('user_lists', 'POST', $cfgHttp, ['csrf_token' => 'lstd-token'], [], $body + ['csrf_token' => 'lstd-token'], $ip);
    check('… signed out: 401 login_required', $j['__status'] === 401 && ($j['error'] ?? '') === 'login_required', json_encode($j));
    $j = $ask('user_lists', 'POST', $cfgHttp, $sess($fxIds['listdescnobody']), [], $body + ['csrf_token' => 'lstd-token'], $ip);
    check('… an account without lists.use: 403 no_permission', $j['__status'] === 403 && ($j['error'] ?? '') === 'no_permission', json_encode($j));
    $j = $ask('user_lists', 'POST', $cfgHttp, $sess($fxIds['listdescpeer']), [], $body + ['csrf_token' => 'lstd-token'], $ip);
    check('… somebody else\'s list: 404 not_found, and it is untouched', $j['__status'] === 404 && ($j['error'] ?? '') === 'not_found' && $rowOf($fxLists['pub'])['description'] === '', json_encode($j));
    $j = $ask('user_lists', 'POST', $cfgHttp, $sess($ownerId), [], $body + ['csrf_token' => 'lstd-token'], $ip);
    $row = $rowOf($fxLists['pub']);
    check('the owner\'s edit: 200, the name and the description and its format saved in the one request',
          $j['__status'] === 200 && !empty($j['success']) && $row['description'] === $body['description'] && $row['name'] === 'Desc pack'
          && ($j['excerpt'] ?? '') === 'Bold and a link :flame:', json_encode($j));
    $ipRl = '203.0.113.' . random_int(10, 250) . '1';
    $cfgRl = array_merge($cfgHttp, ['rate_limit_favourites' => '1']);
    $j1 = $ask('user_lists', 'POST', $cfgRl, $sess($ownerId), [], $body + ['csrf_token' => 'lstd-token'], $ipRl);
    $j2 = $ask('user_lists', 'POST', $cfgRl, $sess($ownerId), [], $body + ['csrf_token' => 'lstd-token'], $ipRl);
    check('rate-limited like every list write (the same hourly bucket): one allowed, the next 429',
          $j1['__status'] === 200 && $j2['__status'] === 429 && ($j2['error'] ?? '') === 'rate_limit', json_encode([$j1['__status'], $j2]));

    // What the shelf and the window are told.
    $j = $ask('user_lists', 'GET', $cfgHttp, $sess($ownerId), [], [], $ip);
    $mine = [];
    foreach ((array)($j['lists'] ?? []) as $l) $mine[(string)$l['name']] = $l;
    check('the owner\'s shelf: each list with its description as typed, its format and the card\'s plain excerpt',
          ($mine['Desc pack']['description'] ?? '') === $body['description'] && ($mine['Desc pack']['description_format'] ?? '') === 'bbcode'
          && ($mine['Desc pack']['excerpt'] ?? '') === 'Bold and a link :flame:' && ($mine['Secret pack']['excerpt'] ?? '') === 'Private words only'
          && (int)($j['desc_max'] ?? 0) === 1000, json_encode($j));
    $j = $ask('user_list_items', 'GET', $cfgHttp, $sess($ownerId), ['list' => $fxLists['pub']], [], $ip);
    $dh = (string)($j['list']['description_html'] ?? '');
    check('the list\'s window: the description DRAWN — the tags, a link with the site\'s rel and target, the emote from the room\'s endpoint',
          str_contains($dh, '<strong>Bold</strong>') && str_contains($dh, 'rel="nofollow noopener noreferrer ugc" target="_blank"')
          && (bool)preg_match('#<img class="rt-emote" src="[^"]*api\.php\?endpoint=shout_emote&amp;id=\d+&amp;v=[0-9a-f]*" alt=":flame:"#', $dh), $dh);
    $j = $ask('user_lists', 'GET', $cfgHttp, $sess($fxIds['listdescpeer']), ['user' => 'listdescowner'], [], $ip);
    $theirs = array_column((array)($j['lists'] ?? []), null, 'name');
    check('another member, on the owner\'s profile: the public list with its excerpt — and not the private one, nor its words',
          isset($theirs['Desc pack']) && ($theirs['Desc pack']['excerpt'] ?? '') === 'Bold and a link :flame:' && !isset($theirs['Secret pack'])
          && !str_contains(json_encode($j), 'Private words'), json_encode($j));
    $j = $ask('user_list_items', 'GET', $cfgHttp, $sess($fxIds['listdescpeer']), ['list' => $fxLists['pub']], [], $ip);
    check('… opening it: the description drawn for them as for the owner', str_contains((string)($j['list']['description_html'] ?? ''), '<strong>Bold</strong>'), json_encode($j));
    $j = $ask('user_list_items', 'GET', $cfgHttp, $sess($fxIds['listdescpeer']), ['list' => $fxLists['priv']], [], $ip);
    check('… the private one by its id: 404, not a word of it', $j['__status'] === 404 && !str_contains(json_encode($j), 'Private'), json_encode($j));
    $j = $ask('user_list_items', 'GET', array_merge($cfgHttp, ['lists_public_enabled' => '0']), $sess($fxIds['listdescpeer']), ['list' => $fxLists['pub']], [], $ip);
    check('… and with public lists switched off, the public one too: the gates are the ones they were', $j['__status'] === 404, json_encode($j));
    // The Preview, as the list's own context.
    $prev = static fn(array $cfgX, int $uid, string $text, string $fmt = 'bbcode') => $ask('richtext_preview', 'POST', $cfgX, $sess($uid), [],
        ['text' => $text, 'format' => $fmt, 'for' => 'list', 'csrf_token' => 'lstd-token'], $ip);
    $j = $prev($cfgHttp, $ownerId, '[b][i]' . str_repeat('z', 20) . '[/i][/b] :flame: [img]https://e.example/p.png[/img]');
    check('the Preview of a list\'s description: drawn as its window will (the emote, no picture), counted as a reader sees it, its limit, the save\'s own refusal',
          $j['__status'] === 200 && ($j['length'] ?? -1) === 28 && ($j['limit'] ?? 0) === 1000 && ($j['images']['limit'] ?? -1) === 0
          && str_contains((string)($j['html'] ?? ''), 'rt-emote') && !str_contains((string)($j['html'] ?? ''), 'rt-img')
          && str_contains((string)($j['problem'] ?? ''), 'pictures'), json_encode($j));
    $j = $prev($cfgHttp, $fxIds['listdescnobody'], 'x');
    check('… refused (403) to an account that may not keep lists', $j['__status'] === 403, json_encode($j));

    /* ── 14. rendering safety: hostile BBCode and Markdown through the list's renderer ── */
    $attacks = [
        ['bbcode', '<script>alert(1)</script>[b onclick=alert(1)]x[/b]'],
        ['bbcode', '[url=javascript:alert(1)]x[/url] [url]javascript:alert(1)[/url] [email]x@y.z" onmouseover="a[/email]'],
        ['bbcode', '[img]https://evil.example/pixel.png[/img][img]javascript:alert(1)[/img][url=https://e.example][img]https://e.example/i.png[/img][/url]'],
        ['bbcode', '[color=red;background:url(https://evil.example)]x[/color][size=99999]y[/size][font=x;y]z[/font]'],
        ['bbcode', '[quote="><script>x</script>]q[/quote][spoiler=<img src=x onerror=1>]s[/spoiler]'],
        ['markdown', '[x](javascript:alert(1)) ![y](https://evil.example/p.png) <kbd onclick="x">k</kbd> <img src=x onerror=alert(1)>'],
        ['markdown', '![<kbd>x</kbd>](https://e.example/a.png) [a](https://e.example/"onmouseover="x)'],
        ['bbcode', ':flame: [url=https://e.example/:flame:]:flame:[/url] [code]:flame:[/code] :notanemote: :FLAME:'],
    ];
    $bad = [];
    $allowed = ['p', 'br', 'strong', 'em', 'u', 's', 'a', 'img', 'span', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h4', 'h5', 'h6',
                'div', 'details', 'summary', 'i', 'cite', 'hr', 'mark', 'sub', 'sup', 'kbd', 'table', 'tr', 'td', 'th'];
    $all = '';
    foreach ($attacks as [$f, $src]) {
        $html = listDescRender($db, $cfgAll, $src, $f);
        $all .= $html;
        preg_match_all('/<\s*\/?\s*([a-zA-Z][a-zA-Z0-9]*)/', $html, $m);
        foreach (array_unique(array_map('strtolower', $m[1])) as $tg) if (!in_array($tg, $allowed, true)) $bad[] = "<$tg> ($f)";
        if (preg_match_all('/<[^>]*>/', $html, $tt)) foreach ($tt[0] as $tag) {
            if (preg_match('/\son[a-z]+\s*=/i', $tag)) $bad[] = 'handler in ' . $tag;
            if (preg_match('/\b(?:href|src)\s*=\s*"(?!https?:\/\/|mailto:|[^"]*api\.php\?endpoint=shout_emote)/i', $tag)) $bad[] = 'address in ' . $tag;
            if (preg_match('/<img\b/i', $tag) && !preg_match('/class="rt-(?:emote|sticker)/', $tag)) $bad[] = 'picture ' . $tag;
            if (preg_match('/<a\b[^>]*href="https?:/i', $tag) && !str_contains($tag, 'rel="nofollow noopener noreferrer ugc" target="_blank"')) $bad[] = 'link without rel/target ' . $tag;
        }
    }
    check('hostile BBCode and Markdown: only the renderer\'s own tags, no handler, no address but http(s)/mailto and the room\'s image endpoint, no picture but an emote, every link rel + target',
          $bad === [], implode(' | ', array_slice($bad, 0, 4)));
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $all . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $xp = new DOMXPath($dom);
    $domBad = [];
    foreach ($xp->query('//*') as $el) {
        if (in_array($el->nodeName, ['script', 'iframe', 'object', 'embed', 'style', 'form', 'input'], true)) $domBad[] = $el->nodeName;
        foreach ($el->attributes as $at) if (str_starts_with(strtolower($at->name), 'on') || strtolower($at->name) === 'style' && preg_match('/url\(|expression/i', $at->value)) $domBad[] = $el->nodeName . '@' . $at->name;
    }
    $emotes = $xp->query('//img[@class="rt-emote"]')->length;
    check('read back through a DOM: no script, frame, form or handler anywhere; the emote drawn in the words and nowhere else (not in a link\'s address, not in code, not as :FLAME:)',
          $domBad === [] && $emotes === 2 && $xp->query('//img[@class="rt-img"]')->length === 0 && $xp->query('//code[contains(., ":flame:")]')->length === 1,
          implode(',', $domBad) . ' emotes=' . $emotes);

    /* ── 15. the counter's twin (assets/js/favourites.js) gives the server's number ── */
    $js = (string)@file_get_contents($root . '/assets/js/favourites.js');
    $from = strpos($js, '    function listDescStrip(text, fmt) {');
    $to = $from === false ? false : strpos($js, "\n    }\n", $from);
    $twin = ($from !== false && $to !== false) ? substr($js, $from, $to - $from + 6) : '';
    $samples = [
        ['bbcode', "[b]Bold[/b] and [url=https://e.example]a link[/url] :flame: \u{1F600}"],
        ['bbcode', "[quote=\"Ann\"]q[/quote]\n[list]\n[*] one\n[*] two\n[/list]\n[img]https://e.example/p.png[/img] [\u{2060}b]escaped[\u{2060}/b]"],
        ['bbcode', "  lots   of\t\twhite\n\n\nspace  [SIZE=18]big[/SIZE] [hr] [spoiler=Title]s[/spoiler] "],
        ['markdown', "# Title\n> quote\n- **one**\n1. *two*\n[three](https://e.example) ![four](https://e.example/4.png) ==hi== ||sp|| `c` ~s~ ^t^"],
        ['markdown', "Zażółć gęślą jaźń 🚀 [link](https://e.example/a_b) | table | cell |\n|---|---|"],
        ['bbcode', "[\u{2060}hide]plain text from 1.69.0[\u{2060}/hide] :\u{2060}fire: https\u{2060}://e.example"],
    ];
    $tmpJs = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstd_twin_' . bin2hex(random_bytes(4)) . '.js';
    file_put_contents($tmpJs, $twin . "\nconst s = JSON.parse(require('fs').readFileSync(process.argv[2], 'utf8'));\n"
        . "process.stdout.write(JSON.stringify(s.map(x => [listDescStrip(x[1], x[0]), Array.from(listDescStrip(x[1], x[0])).length])));\n");
    $tmpIn = $tmpJs . '.json';
    file_put_contents($tmpIn, json_encode($samples));
    $node = trim((string)@shell_exec('node ' . escapeshellarg($tmpJs) . ' ' . escapeshellarg($tmpIn) . ' 2>&1'));
    @unlink($tmpJs);
    @unlink($tmpIn);
    $jsOut = json_decode($node, true);
    $twinBad = [];
    foreach ($samples as $i => [$f, $s]) {
        $php = [listDescStrip($s, $f), listDescVisible($s, $f)];
        if (!is_array($jsOut) || ($jsOut[$i] ?? null) !== $php) $twinBad[] = $i . ': php ' . json_encode($php) . ' js ' . json_encode($jsOut[$i] ?? $node);
    }
    check('the counter\'s twin in favourites.js strips and counts exactly as listDescStrip()/listDescVisible() do (run by node on six texts)',
          $twin !== '' && $twinBad === [], implode(' | ', $twinBad) ?: substr($twin, 0, 80));
} catch (\Throwable $e) {
    check('the 1.70.0 D sections ran to the end', false, $e->getMessage() . ' @ ' . $e->getLine());
} finally {
    $cleanupD();
    if (isset($runner)) @unlink($runner);
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
