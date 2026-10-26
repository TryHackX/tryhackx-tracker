<?php
/**
 * Rich messages (1.75.0, part B) — everything but the sanitizer (tests/htmlsafe_test.php) and the bulk mail's pure
 * formatting (tests/bulkmail_test.php); needs the local test database:
 *   php tests/rich_messages_test.php
 *
 *   1. SCHEMA 94 — the columns of this release exist with the right types on this database, and an OLD-shaped scratch
 *      database (VARCHAR(500) notes, a mail_queue enum without 'html', a user_notifications without `rich`, the
 *      install.php tables without a format) is brought to the same by the guarded statements — once: the second run
 *      has nothing left to do for these tables. The two new settings have their defaults.
 *   2. THE WORDS A MODERATOR WRITES — reportTextClean() keeps lines and drops what is not text; reportTextFormat()
 *      never offers a syntax the site has switched off; reportTextValidate() says too long / a picture / too many
 *      links in the dictionary's words, plain text excepted from the markup rules; reportTextHtml() is the site's
 *      renderer on escaped input; reportTextAudit() is an excerpt and a length.
 *   3. NOTIFICATIONS — userNotify() keeps the source and the format beside a plain body; bulkNotify() puts a formatted
 *      composer text in `rich` (bytes, not characters) and a plain one in `body` as before; a database without the
 *      columns still gets the plain notification; api/user_notifications.php hands out `rich_html` (our renderer) and,
 *      for HTML, the cleaned fragment AND a whole document for a frame with no script — and no `rich` source.
 *   4. THE MAIL — the footer (preferences link, site, the sender's identity) is its own function and reads on the
 *      ground it is put on; a bulk message in HTML sent WITHOUT the frame is a bare white document that still carries
 *      the footer; the text half is made from the HTML and ends with the unsubscribe line; a custom mail to a
 *      reporter and an appeal's answer render BBCode / Markdown in both halves; richtextRenderForEmail() has no class
 *      left, reads on both grounds, and shows [hide].
 *   5. THE WINDOWS' SERVER SIDE — content reports: a loud warning with a BBCode reason and a Markdown answer reaches
 *      the author and the reporter as `rich`, is kept raw with its format, is shown rendered on the card and in the
 *      warning list, audited as excerpts with the ids intact, refused (400 invalid_text) when it has a picture or is
 *      too long; the reported message's endpoint, the appeal's answer, the per-user note and the preview endpoint are
 *      run as the requests they are (a child process, a session).
 * Self-cleaning: its accounts (with their notifications, warnings, threads), its torrent, comment, reports, queue rows,
 * appeal and audit lines are removed; a scratch database is dropped.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/bulkmail.php', 'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/sounds.php',
          'includes/shout.php', 'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/auth.php',
          'includes/icons.php', 'includes/comments.php', 'includes/reports.php', 'includes/lang.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);
$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$GLOBALS['db'] = $db;
langInit([], 'en');
$cfgOn = array_merge($cfg, [
    'users_enabled' => '1', 'users_require_email_verify' => '1', 'profiles_enabled' => '1',
    'index_enabled' => '1', 'index_search_enabled' => '1', 'index_search_include_whitelist' => '1',
    'comments_enabled' => '1', 'comment_rate_per_hour' => '1000', 'comment_links' => '1',
    'recaptcha_enabled' => '0', 'default_language' => 'en', 'antispam_enabled' => '0', 'pm_enabled' => '1',
    'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1', 'site_name' => 'RM Test Site', 'site_email' => 'rm@example.org',
    'site_url' => 'http://localhost:8089/', 'hmac_secret' => 'rm-test-secret',
]);
$GLOBALS['cfg'] = $cfgOn;
// A session of this process's own, before the first line of output (a CLI sends its headers with it).
session_id('rmtest' . bin2hex(random_bytes(8)));
session_start();
$_SESSION['csrf_token'] = 'rm-test-token';
$_SESSION['loggedin'] = true; $_SESSION['admin_via_user'] = 0;
$T = 'rm-test-token';

const RM_USERS = ['rmtest_alice', 'rmtest_carol', 'rmtest_dave'];
const RM_H1 = 'a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5';
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$scratchName = null; $adm = null;
register_shutdown_function(function () use ($db, $auditFloor, &$scratchName, &$adm) {
    foreach (RM_USERS as $name) {
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            $db->prepare("DELETE FROM message_reports WHERE reporter_id = ? OR reported_user_id = ?")->execute([$id, $id]);
            $db->prepare("DELETE m FROM user_messages m JOIN message_threads t ON t.id = m.thread_id WHERE t.u_low = ? OR t.u_high = ?")->execute([$id, $id]);
            $db->prepare("DELETE FROM message_threads WHERE u_low = ? OR u_high = ?")->execute([$id, $id]);
            userDeleteCascade($db, $id);
        }
    }
    $db->prepare("DELETE FROM content_reports WHERE info_hash = ?")->execute([RM_H1]);
    $db->prepare("DELETE FROM hash_comments WHERE info_hash = ?")->execute([RM_H1]);
    $db->prepare("DELETE FROM index_hashes WHERE info_hash = ?")->execute([RM_H1]);
    $db->exec("DELETE FROM mail_queue WHERE subject LIKE 'rmtest %'");
    $db->exec("DELETE FROM appeal_archives WHERE infoHash = '" . RM_H1 . "'");
    $db->exec("DELETE FROM appeals WHERE infoHash = '" . RM_H1 . "'");
    $db->exec("DELETE FROM user_notifications WHERE title LIKE 'rmtest %'");
    $db->prepare("DELETE FROM audit_log WHERE id > ?")->execute([$auditFloor]);
    foreach (['content_reports', 'user_warnings', 'hash_comments', 'message_reports', 'message_threads', 'user_messages'] as $t) {
        if (!(int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()) {
            try { $db->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1"); } catch (\Throwable $e) { /* not ours to insist */ }
        }
    }
    if ($scratchName !== null && $adm instanceof PDO) { try { $adm->exec("DROP DATABASE IF EXISTS `$scratchName`"); } catch (\Throwable $e) { /* gone */ } }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});

function rmUser(PDO $db, array $cfg, string $name, string $lang = 'en'): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0) $db->prepare("UPDATE users SET email_verified = 1, language = ? WHERE id = ?")->execute([$lang, $id]);
    userPermissionsForget($id);
    return $id;
}
$notes = function (int $uid, int $floor = 0) use ($db): array {
    $st = $db->prepare("SELECT id, type, title, body, rich, rich_format FROM user_notifications WHERE user_id = ? AND id > ? ORDER BY id");
    $st->execute([$uid, $floor]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$noteFloor = fn(): int => (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM user_notifications")->fetchColumn();

/* ══ 1. schema 94 ═════════════════════════════════════════════════════════════════════════════════════════════ */
check('the schema is at 94 or later, and its line says what 94 is', TRACKER_SCHEMA_VERSION >= 94
      && str_contains($src('includes/schema.php'), '94 = rich messages (1.75.0, includes/htmlsafe.php;'));
$want = [
    ['mail_queue', 'format', "enum('plain','bbcode','markdown','html','flarum')"], ['mail_queue', 'frame', "enum('site','none')"],
    ['user_notifications', 'rich', 'mediumtext'], ['user_notifications', 'rich_format', "enum('bbcode','markdown','html','flarum')"],
    ['content_reports', 'note', 'text'], ['content_reports', 'reply', 'text'], ['content_reports', 'reply_format', "enum('plain','bbcode','markdown','flarum')"],
    ['message_reports', 'note', 'text'], ['message_reports', 'reply', 'text'], ['message_reports', 'reply_format', "enum('plain','bbcode','markdown','flarum')"],
    ['user_warnings', 'reason', 'text'], ['user_warnings', 'reason_format', "enum('plain','bbcode','markdown','flarum')"],
    ['appeals', 'admin_response_format', "enum('plain','bbcode','markdown','flarum')"], ['appeal_archives', 'admin_response_format', "enum('plain','bbcode','markdown','flarum')"],
    ['sent_emails', 'message_format', "enum('plain','bbcode','markdown','flarum')"],
];
$wrong = [];
foreach ($want as [$t, $c, $ty]) { $got = schemaColumnType($db, $t, $c); if ($got !== $ty) $wrong[] = "$t.$c is " . var_export($got, true) . ", wanted $ty"; }
check('this database has every column of 94, of the type wanted', !$wrong, implode(' | ', $wrong));
check('schemaColumnType(): lower-case type, null for a column that is not there', schemaColumnType($db, 'mail_queue', 'nope') === null && schemaColumnType($db, 'users', 'id') !== null);
check('the two settings have their defaults (no cap, no sender identity)', (trackerSchemaDefaultSettings()['bulk_mail_daily_cap'] ?? null) === '0'
      && (trackerSchemaDefaultSettings()['mail_sender_address'] ?? null) === ''
      && str_contains($src('api/admin/save_settings.php'), "'bulk_mail_daily_cap', 'mail_sender_address'")
      && str_contains($src('includes/settings_catalog.php'), "'mail_sender_address'") && str_contains($src('templates/admin/settings.php'), 'name="mail_sender_address"'));
$rtfKeys = array_map(fn($p) => 'rt_formats_' . $p, richtextPlaces());
$rtfIn = implode(',', array_fill(0, count($rtfKeys), '?'));
$rtfRead = function () use ($db, $rtfKeys, $rtfIn): array {
    $st = $db->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ($rtfIn)");
    $st->execute($rtfKeys);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
};
$rtfWas = $rtfRead();
$db->prepare("DELETE FROM settings WHERE `key` IN ($rtfIn) OR `key` = 'schema_once_v95_formats'")->execute($rtfKeys);
trackerSchemaDataMigrations($db, array_merge($cfg, ['desc_allow_bbcode' => '1', 'desc_allow_markdown' => '0']));
$rtfGot = $rtfRead();
check('v95: an upgraded site\'s places start from the description switches it had, with Flarum beside them',
      count($rtfGot) === count($rtfKeys) && array_unique(array_values($rtfGot)) === ['bbcode,flarum'], $J($rtfGot));
$db->exec("UPDATE settings SET `value` = 'markdown' WHERE `key` = 'rt_formats_message'");
trackerSchemaDataMigrations($db, $cfg);
check('… once: a place the operator set afterwards keeps what they set', ($rtfRead()['rt_formats_message'] ?? '') === 'markdown', $J($rtfRead()));
$db->prepare("DELETE FROM settings WHERE `key` IN ($rtfIn)")->execute($rtfKeys);
$rtfPut = $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?)");
foreach ($rtfWas as $k => $v) $rtfPut->execute([$k, $v]);

$scratchName = 'tracker_rm_' . bin2hex(random_bytes(3));
$dsnPort = (string)($db->query('SELECT @@port')->fetchColumn() ?: '3306');
$base = 'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';port=' . $dsnPort . ';charset=utf8mb4';
try {
    $adm = new PDO($base, defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adm->exec("CREATE DATABASE `$scratchName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $sdb = new PDO("$base;dbname=$scratchName", defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '',
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $sdb->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT, `updated_at` DATETIME NULL)
                ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (trackerSchemaStatements() as $sql) $sdb->exec($sql);
    // the install.php tables, as they were before 94
    $e = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $sdb->exec("CREATE TABLE appeals (id INT AUTO_INCREMENT PRIMARY KEY, infoHash VARCHAR(40) NOT NULL, admin_response TEXT DEFAULT NULL) $e");
    $sdb->exec("CREATE TABLE appeal_archives (id INT PRIMARY KEY, infoHash VARCHAR(40) NOT NULL, admin_response TEXT DEFAULT NULL) $e");
    $sdb->exec("CREATE TABLE sent_emails (id INT AUTO_INCREMENT PRIMARY KEY, report_id INT NOT NULL, message TEXT) $e");
    // …and the 93 shapes of the others
    foreach ([
        "ALTER TABLE mail_queue MODIFY COLUMN format ENUM('plain','bbcode','markdown') NOT NULL DEFAULT 'plain', DROP COLUMN frame",
        "ALTER TABLE user_notifications DROP COLUMN rich, DROP COLUMN rich_format",
        "ALTER TABLE content_reports MODIFY COLUMN note VARCHAR(500) NOT NULL DEFAULT '', MODIFY COLUMN reply VARCHAR(500) DEFAULT NULL, DROP COLUMN reply_format",
        "ALTER TABLE message_reports MODIFY COLUMN note VARCHAR(500) NOT NULL DEFAULT '', MODIFY COLUMN reply VARCHAR(500) DEFAULT NULL, DROP COLUMN reply_format",
        "ALTER TABLE user_warnings MODIFY COLUMN reason VARCHAR(500) NOT NULL DEFAULT '', DROP COLUMN reason_format",
    ] as $q) $sdb->exec($q);
    $sdb->exec("INSERT INTO content_reports (kind, target_id, info_hash, reporter_id, reason, snapshot, note, reply) VALUES ('comment', 1, '" . RM_H1 . "', 1, 'r', 's', 'a note', 'an answer')");
    $sdb->exec("INSERT INTO user_warnings (user_id, reason) VALUES (1, 'an old reason')");
    $guarded = trackerSchemaGuardedStatements($sdb);
    $errs = [];
    foreach ($guarded as $s) {
        $last = null;
        foreach ((is_array($s) ? $s : [$s]) as $t) { try { $sdb->exec($t); $last = null; break; } catch (\Throwable $ex) { $last = $ex; } }
        if ($last) $errs[] = $last->getMessage();
    }
    $bad = [];
    foreach ($want as [$t, $c, $ty]) { $got = schemaColumnType($sdb, $t, $c); if ($got !== $ty) $bad[] = "$t.$c is " . var_export($got, true); }
    check('an upgrade (93-shaped tables): the guarded statements run without an error and end with every column of 94', !$errs && !$bad, implode(' | ', $errs) . ' || ' . implode(' | ', $bad));
    check('… the rows already written keep their words (a note, an answer, a reason) and read as plain',
          $sdb->query("SELECT CONCAT(note, '|', reply, '|', reply_format) FROM content_reports")->fetchColumn() === 'a note|an answer|plain'
          && $sdb->query("SELECT CONCAT(reason, '|', reason_format) FROM user_warnings")->fetchColumn() === 'an old reason|plain');
    $touching = array_filter(array_map(fn($s) => is_array($s) ? implode(' ', $s) : $s, trackerSchemaGuardedStatements($sdb)),
        fn($s) => preg_match('/ALTER TABLE `(mail_queue|user_notifications|content_reports|message_reports|user_warnings|appeals|appeal_archives|sent_emails)`/', $s));
    check('… and a second run has nothing left to do for these tables (a MODIFY only when the type differs)', !$touching, implode(' | ', array_map(fn($s) => substr($s, 0, 120), $touching)));
    $sdb->exec("DROP TABLE appeals, appeal_archives, sent_emails");
    $skipped = array_filter(array_map(fn($s) => is_array($s) ? implode(' ', $s) : $s, trackerSchemaGuardedStatements($sdb)), fn($s) => str_contains($s, '`appeals`') || str_contains($s, '`sent_emails`') && str_contains($s, 'ALTER'));
    check('… the install.php tables are guarded on their existence: a database without them gets no ALTER for them', !$skipped, implode(' | ', array_map(fn($s) => substr($s, 0, 100), $skipped)));
} catch (\Throwable $e) {
    check('the scratch database for the schema checks', false, $e->getMessage());
}

/* ══ 2. the words a moderator writes ══════════════════════════════════════════════════════════════════════════ */
check('reportTextClean(): lines stay (CRLF → LF), tabs and control characters go, bidi overrides go, three blank lines are two',
      reportTextClean("a\r\nb\t\x01c\n\n\n\nd \u{202E}e  ") === "a\nb  c\n\nd e", $J(reportTextClean("a\r\nb\t\x01c\n\n\n\nd \u{202E}e  ")));
check('… cut at the limit in CHARACTERS, never in the middle of one; not a string is nothing',
      mb_strlen(reportTextClean(str_repeat('ą', 3000))) === REPORT_TEXT_MAX && reportTextClean(['x']) === '' && reportTextClean(null) === '');
check('reportTextFormat(): plain, and BBCode / Markdown / Flarum only where the site has them on for moderators\' texts',
      reportTextFormat('bbcode', $cfgOn) === 'bbcode' && reportTextFormat('markdown', $cfgOn) === 'markdown' && reportTextFormat('html', $cfgOn) === 'plain'
      && reportTextFormat('markdown', array_merge($cfgOn, ['rt_formats_report' => 'bbcode'])) === 'plain' && reportTextFormat(null, $cfgOn) === 'plain'
      && reportTextFormat('bbcode', array_merge($cfgOn, ['rt_formats_report' => 'markdown'])) === 'plain'
      && reportTextFormat('flarum', array_merge($cfgOn, ['rt_formats_report' => 'bbcode,flarum'])) === 'flarum'
      && reportTextFormat('flarum', array_merge($cfgOn, ['rt_formats_report' => 'bbcode,markdown'])) === 'plain');
check('reportTextValidate(): empty and short texts are fine, in every format',
      reportTextValidate('', 'bbcode', $cfgOn) === null && reportTextValidate('hello', 'plain', $cfgOn) === null && reportTextValidate('[b]hi[/b]', 'bbcode', $cfgOn) === null);
check('… too long says how long, in the dictionary\'s words (plain too)', str_contains((string)reportTextValidate(str_repeat('a', 2001), 'plain', $cfgOn), '2001')
      && str_contains((string)reportTextValidate(str_repeat('a', 2001), 'bbcode', $cfgOn), '2000'));
check('… a picture is refused in BBCode and in Markdown, and means nothing in plain text',
      reportTextValidate('[img]https://example.org/a.png[/img]', 'bbcode', $cfgOn) === __('api.report_text.no_images')
      && reportTextValidate('![x](https://example.org/a.png)', 'markdown', $cfgOn) === __('api.report_text.no_images')
      && reportTextValidate('[img]https://example.org/a.png[/img]', 'plain', $cfgOn) === null);
$links = implode(' ', array_map(fn($i) => "https://example.org/$i", range(1, 6)));
check('… more than five links are refused (rendered links are counted: a bare address is one)',
      str_contains((string)reportTextValidate($links, 'bbcode', $cfgOn), '6') && reportTextValidate(implode(' ', array_slice(explode(' ', $links), 0, 5)), 'bbcode', $cfgOn) === null
      && reportTextValidate($links, 'plain', $cfgOn) === null);
check('… a format the site does not take is refused', reportTextValidate('x', 'markdown', array_merge($cfgOn, ['rt_formats_report' => 'bbcode'])) === __('api.report_text.bad_format'));
check('reportTextHtml(): BBCode and Markdown through the renderer, escaped; plain is escaped text with its lines',
      str_contains(reportTextHtml('[b]x[/b] <script>', 'bbcode', $cfgOn), '<strong>x</strong>') && !str_contains(reportTextHtml('<script>alert(1)</script>', 'markdown', $cfgOn), '<script')
      && reportTextHtml("a<b\nc", 'plain', $cfgOn) === "a&lt;b<br>\nc" && reportTextHtml('  ', 'bbcode', $cfgOn) === ''
      && str_contains(reportTextHtml('[hide]secret[/hide]', 'bbcode', $cfgOn), 'secret'));
$ex = reportTextAudit(str_repeat('word ', 100));
check('reportTextAudit(): an excerpt and the length — the whole text never', is_array($ex) && $ex['len'] === 499 && mb_strlen($ex['excerpt']) === 120 && reportTextAudit('  ') === null);

/* ══ 3. notifications ═════════════════════════════════════════════════════════════════════════════════════════ */
$alice = rmUser($db, $cfgOn, 'rmtest_alice', 'pl');
$carol = rmUser($db, $cfgOn, 'rmtest_carol', 'en');
$dave  = rmUser($db, $cfgOn, 'rmtest_dave', 'en');
$f = $noteFloor();
userNotify($db, $dave, 'info', 'rmtest plain', 'just words');
userNotify($db, $dave, 'info', 'rmtest bbcode', 'lead', null, null, "[b]rich[/b]\nline two", 'bbcode');
userNotify($db, $dave, 'info', 'rmtest bad format', 'lead', null, null, 'x', 'wiki');
userNotify($db, $dave, 'info', 'rmtest empty', 'lead', null, null, '   ', 'markdown');
userNotify($db, $dave, 'info', 'rmtest html', '', null, null, '<p>hi</p>', 'html');
$nd = $notes($dave, $f);
check('userNotify(): a plain notification is as before (no rich words, no format)', ($nd[0]['body'] ?? '') === 'just words' && $nd[0]['rich'] === null && $nd[0]['rich_format'] === null);
check('… with a source and a format the source is kept raw beside a plain body',
      ($nd[1]['body'] ?? '') === 'lead' && ($nd[1]['rich'] ?? '') === "[b]rich[/b]\nline two" && ($nd[1]['rich_format'] ?? '') === 'bbcode' && ($nd[4]['rich_format'] ?? '') === 'html');
check('… a format that is none, or a source that is empty, is no rich words at all', $nd[2]['rich'] === null && $nd[2]['rich_format'] === null && $nd[3]['rich'] === null);
userNotify($db, $dave, 'info', 'rmtest long', 'lead', null, null, str_repeat('é', 40000), 'markdown');
$long = $notes($dave, $f);
check('… 60 000 bytes at most, cut at a character', strlen((string)end($long)['rich']) <= 60000 && mb_check_encoding((string)end($long)['rich'], 'UTF-8'));

$f = $noteFloor();
$spec = ['mode' => 'selected', 'ids' => [$carol]];
check('bulkNotify(): a plain message is in `body` (5 000 characters), as it always was',
      bulkNotify($db, $spec, 'rmtest bulk plain', str_repeat('x', 6000)) === 1 && mb_strlen((string)($notes($carol, $f)[0]['body'] ?? '')) === 5000 && $notes($carol, $f)[0]['rich'] === null);
$f2 = $noteFloor();
check('… a BBCode / Markdown / HTML message is kept in `rich` with its format and `body` stays empty',
      bulkNotify($db, $spec, 'rmtest bulk bb', '[b]x[/b]', 'bbcode') === 1 && bulkNotify($db, $spec, 'rmtest bulk html', '<h1>x</h1>', 'html') === 1
      && ($r = $notes($carol, $f2)) && $r[0]['body'] === null && $r[0]['rich'] === '[b]x[/b]' && $r[0]['rich_format'] === 'bbcode' && $r[1]['rich'] === '<h1>x</h1>' && $r[1]['rich_format'] === 'html', $J($r ?? null));
$f3 = $noteFloor();
bulkNotify($db, $spec, 'rmtest bulk big', str_repeat('é', 40000), 'markdown');
$big = $notes($carol, $f3)[0] ?? [];
check('… a long one is cut at ' . BULK_BODY_MAX_BYTES . ' BYTES (not 5 000 characters, which split a tag)', strlen((string)($big['rich'] ?? '')) <= BULK_BODY_MAX_BYTES && strlen((string)($big['rich'] ?? '')) > 59000);

/* ── the requests, as the router runs them ─────────────────────────────────────────────────────────── */
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rm_runner_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = $a["post"]; $_GET = $a["get"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/index.php", "includes/api_auth.php", "includes/auth.php", "includes/richtext.php", "includes/bulkmail.php",
          "includes/mail.php", "includes/users.php", "includes/favourites.php", "includes/shout.php", "includes/people.php", "includes/audit.php",
          "includes/content.php", "includes/comments.php", "includes/reports.php", "includes/lang.php", "includes/twofa.php", "includes/federation.php",
          "includes/schedule.php", "includes/livesync.php", "includes/wlmaint.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
$_SESSION = $a["uid"] > 0 ? ["user_id" => $a["uid"], "user_login_time" => time()] : ["loggedin" => true, "login_time" => time(), "last_activity" => time()];
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
$GLOBALS["__audit_endpoint"] = "admin/" . $a["endpoint"];
require $a["file"];
');
$run = function (string $file, array $post, array $cfgExtra = [], int $uid = 0, string $method = 'POST', array $get = []) use ($root, $runner): array {
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rm_args_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($argFile, json_encode(['root' => $root, 'file' => $file, 'endpoint' => basename($file, '.php'), 'method' => $method, 'post' => $post, 'get' => $get,
                                             'cfg' => $cfgExtra, 'uid' => $uid, 'sid' => 'rmtest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    @unlink($argFile);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 400)];
};
$admin = fn(string $ep, array $post, array $cfgx = []) => $run('api/admin/' . $ep . '.php', $post, $cfgx + ['users_enabled' => '1'], 0);

$api = $run('api/user_notifications.php', [], ['users_enabled' => '1'], $dave, 'GET', ['page' => 1]);
$byTitle = [];
foreach (($api['notifications'] ?? []) as $x) $byTitle[$x['title']] = $x;
check('api/user_notifications: a plain row has no rich words ("" and null), as before', isset($byTitle['rmtest plain']) && $byTitle['rmtest plain']['rich_html'] === '' && $byTitle['rmtest plain']['rich_format'] === null
      && $byTitle['rmtest plain']['rich_doc'] === '' && !array_key_exists('rich', $byTitle['rmtest plain']), $J($api));
check('… a BBCode row comes rendered by the site\'s renderer, with its format, and without the source',
      str_contains($byTitle['rmtest bbcode']['rich_html'] ?? '', '<strong>rich</strong>') && ($byTitle['rmtest bbcode']['rich_format'] ?? '') === 'bbcode' && ($byTitle['rmtest bbcode']['rich_doc'] ?? 'x') === ''
      && !array_key_exists('rich', $byTitle['rmtest bbcode']));
check('… an HTML row: a whole document for a frame (a stylesheet of its own, the cleaned fragment inside), NO fragment to put into the page (rich_html stays empty), no source',
      ($byTitle['rmtest html']['rich_format'] ?? '') === 'html' && ($byTitle['rmtest html']['rich_html'] ?? 'x') === ''
      && str_starts_with($byTitle['rmtest html']['rich_doc'] ?? '', '<!DOCTYPE html>') && str_contains($byTitle['rmtest html']['rich_doc'], '<body') && str_contains($byTitle['rmtest html']['rich_doc'], '<p>hi</p>')
      && str_contains($byTitle['rmtest html']['rich_doc'], 'max-width:100%') && !str_contains($byTitle['rmtest html']['rich_doc'], '<script'));
userNotify($db, $dave, 'info', 'rmtest evil', '', null, null, '<p onclick="x()">a</p><script>alert(1)</script><img src=x onerror=alert(1)><iframe src="javascript:1"></iframe>', 'html');
$api = $run('api/user_notifications.php', [], ['users_enabled' => '1'], $dave, 'GET', ['page' => 1]);
$ev = null; foreach (($api['notifications'] ?? []) as $x) if ($x['title'] === 'rmtest evil') $ev = $x;
check('… an HTML notification is cleaned when it is READ — on its way out, whatever was stored', $ev && $ev['rich_html'] === ''
      && !str_contains($ev['rich_doc'], 'onclick') && !str_contains($ev['rich_doc'], 'iframe') && !str_contains($ev['rich_doc'], 'onerror') && !str_contains(substr($ev['rich_doc'], strpos($ev['rich_doc'], '<body')), 'script'), $J($ev));
// A notification of HTML that the cleaner leaves nothing of has no frame at all (the page draws nothing for it).
userNotify($db, $dave, 'info', 'rmtest nothing', '', null, null, '<script>alert(1)</script>', 'html');
$api = $run('api/user_notifications.php', [], ['users_enabled' => '1'], $dave, 'GET', ['page' => 1]);
$nn = null; foreach (($api['notifications'] ?? []) as $x) if ($x['title'] === 'rmtest nothing') $nn = $x;
check('… and HTML with nothing left after cleaning has no frame document and no fragment', $nn && $nn['rich_format'] === 'html' && $nn['rich_doc'] === '' && $nn['rich_html'] === '', $J($nn));
$appSrc = (string)file_get_contents($root . '/assets/js/app.js');
check('the account page never falls back to innerHTML for an HTML notification: it returns nothing when there is no frame document',
      preg_match("/function richNotifBox\\(n\\) \\{\\s*if \\(n\\.rich_format === 'html'\\) \\{\\s*if \\(!n\\.rich_doc\\) return null;/", $appSrc) === 1
      && str_contains($appSrc, "if (n.rich_format !== 'bbcode' && n.rich_format !== 'markdown') return null;") && str_contains($appSrc, 'const rich = richNotifBox(n);'));

/* ══ 4. the mail ══════════════════════════════════════════════════════════════════════════════════════════════ */
$cfgM = array_merge($cfgOn, ['mail_sender_address' => "Acme Ltd,\n1 Long Street, Warsaw"]);
check('mailSenderIdentity(): one line, bounded', mailSenderIdentity($cfgM) === 'Acme Ltd,, 1 Long Street, Warsaw' && mailSenderIdentity($cfgOn) === ''
      && mb_strlen(mailSenderIdentity(['mail_sender_address' => str_repeat('x', 500)])) === 300);
$fd = mailFooterHtml($cfgM, 'en', 'http://localhost:8089/?action=unsubscribe&email=a&token=b', 'dark');
$fl = mailFooterHtml($cfgM, 'en', 'http://localhost:8089/?action=unsubscribe&email=a&token=b', 'light');
check('mailFooterHtml(): the preferences link, the site and its address, the sender\'s identity — escaped; on its own ground, with colours that read',
      str_contains($fd, 'Manage notification preferences') && str_contains($fd, 'href="http://localhost:8089/?action=unsubscribe&amp;email=a&amp;token=b"') && str_contains($fd, 'RM Test Site')
      && str_contains($fd, 'rm@example.org') && str_contains($fd, 'Acme Ltd,, 1 Long Street, Warsaw') && str_contains($fd, 'background:#0a0a14') && !str_contains($fd, '#3a3a4a') && !str_contains($fd, 'color:#5a6a7a')
      && str_contains($fl, 'background:#f4f5f7') && str_contains($fl, 'color:#555b66') && !str_contains($fl, '#0a0a14'));
check('… no link without an address (a transactional mail), no identity line when none is set',
      !str_contains(mailFooterHtml($cfgOn, 'en', '', 'dark'), '<a ') && substr_count(mailFooterHtml($cfgOn, 'en', '', 'dark'), '<p ') === 1
      && !str_contains(mailFooterHtml(array_merge($cfgOn, ['mail_sender_address' => '<b>x</b>']), 'en', '', 'dark'), '<b>x</b>'));
$ft = mailFooterText($cfgM, 'en', 'http://localhost:8089/?action=unsubscribe&email=a&token=b');
check('mailFooterText(): the unsubscribe line with the address, the site, the identity', str_contains($ft, "Unsubscribe: http://localhost:8089/?action=unsubscribe&email=a&token=b\n") && str_contains($ft, 'RM Test Site - rm@example.org')
      && str_ends_with($ft, 'Acme Ltd,, 1 Long Street, Warsaw') && mailFooterText($cfgOn, 'en', '') === 'RM Test Site - rm@example.org');
$unsub = 'http://localhost:8089/?action=unsubscribe&email=a&token=b';
$campaign = '<table width="600" bgcolor="#ffffff"><tr><td style="padding:10px;color:#333333">Hello <a href="https://shop.example.com/x">shop</a><script>alert(1)</script></td></tr></table>';
$site = bulkMailParts('rmtest Spring', $campaign, 'html', 'site', $cfgM, $unsub, 'en');
$bare = bulkMailParts('rmtest Spring', $campaign, 'html', 'none', $cfgM, $unsub, 'en');
check('bulkMailParts(), the site\'s frame: the dark frame, the campaign inside (cleaned), the footer; the body is in a <div>, not a <p>',
      str_contains($site['html'], 'background:#111') && str_contains($site['html'], '<table width="600" bgcolor="#ffffff">') && !str_contains($site['html'], 'script') && str_contains($site['html'], 'Manage notification preferences')
      && str_contains($site['html'], '<div style="line-height:1.7;margin:0 0 16px;"><div><table') && !str_contains($site['html'], '<p style="line-height:1.7'));
check('… no frame: a bare white document with the title, the campaign and ONLY the footer (preferences link, site, identity)',
      str_starts_with($bare['html'], '<!DOCTYPE html>') && str_contains($bare['html'], '<title>rmtest Spring</title>') && str_contains($bare['html'], 'background:#ffffff') && !str_contains($bare['html'], 'background:#111')
      && !str_contains($bare['html'], '#0a0a1a') && str_contains($bare['html'], '<div><table width="600"') && str_contains($bare['html'], 'Manage notification preferences')
      && str_contains($bare['html'], 'Acme Ltd,, 1 Long Street, Warsaw') && !str_contains($bare['html'], 'script'));
check('… the text half is made from the HTML (no tags, the link as "words (address)") and ends with the unsubscribe line, for the frame and without it',
      str_contains($site['text'], 'Hello shop (https://shop.example.com/x)') && !str_contains($site['text'], '<') && !str_contains($site['text'], 'alert')
      && str_contains($site['text'], "--\nUnsubscribe: $unsub") && str_contains($bare['text'], "Unsubscribe: $unsub") && str_contains($bare['text'], 'Acme Ltd'));
$md = bulkMailParts('rmtest md', "# Title\n\n**bold** and [a link](https://example.org/x)\n\n- one\n- two", 'markdown', 'site', $cfgOn, $unsub, 'en');
check('… Markdown and BBCode no longer put their markup in the text half: it reads as text, the list as "- "',
      str_contains($md['text'], 'Title') && !str_contains($md['text'], '**') && !str_contains($md['text'], '# ') && str_contains($md['text'], 'bold and a link (https://example.org/x)') && str_contains($md['text'], "- one\n- two"), $md['text']);
$bb = bulkMailParts('rmtest bb', "[b]Hi[/b] there\n[url=https://example.org]site[/url]", 'bbcode', 'none', $cfgOn, $unsub, 'en');
check('… ' . 'BBCode too; plain text is its own text half, untouched', !str_contains($bb['text'], '[b]') && str_contains($bb['text'], 'Hi there') && str_contains($bb['text'], 'site (https://example.org)')
      && str_starts_with(bulkMailParts('rmtest p', "line one\n  line two  <b>", 'plain', 'site', $cfgOn, $unsub, 'en')['text'], "line one\n  line two  <b>\n\n--\n"));
check('bulkBodyProblem(): the byte cap, and an HTML with nothing left in it',
      bulkBodyProblem(str_repeat('a', BULK_BODY_MAX_BYTES + 1), 'plain', $cfgOn) === __('api.bulk.too_long', ['max' => '60 000']) && bulkBodyProblem(str_repeat('é', 30001), 'markdown', $cfgOn) !== null
      && bulkBodyProblem(str_repeat('a', BULK_BODY_MAX_BYTES), 'plain', $cfgOn) === null
      && bulkBodyProblem('<script>x</script><!-- c -->', 'html', $cfgOn) === __('api.bulk.html_empty') && bulkBodyProblem('<p>x</p>', 'html', $cfgOn) === null
      && bulkBodyProblem('<img src="https://x.example/a.png">', 'html', $cfgOn) === null);
// The finished document is bounded too (review 1.75.0, L4): the cap above counts what was typed, a markup grows when it is
// written out, and Gmail hides everything past ~102 KB — the footer with the unsubscribe link is the last thing in the document.
$amp = str_repeat('<a href="https://x/">x', 2700);               // 59 KB of source, three times that as a mail
$ampDoc = bulkMailParts('rmtest amp', $amp, 'html', 'site', $cfgOn, $unsub, 'en');
check('bulkBodyProblem(): a message whose finished HTML DOCUMENT is past ' . BULK_DOC_MAX_BYTES . ' bytes is refused, whatever was typed; below it is not; the bare frame is judged as well',
      strlen($amp) < BULK_BODY_MAX_BYTES && strlen($ampDoc['html']) > BULK_DOC_MAX_BYTES && str_contains((string)bulkBodyProblem($amp, 'html', $cfgOn), '95 KB')
      && bulkBodyProblem($amp, 'html', $cfgOn, 'none') !== null && bulkBodyProblem($amp, 'html', $cfgOn, 'site', $ampDoc) !== null
      && bulkBodyProblem('<p>an ordinary message</p>', 'html', $cfgOn) === null && bulkBodyProblem("**an** ordinary [message](https://example.org/)", 'markdown', $cfgOn) === null, $J(strlen($ampDoc['html'])));
$mdBig = str_repeat('**x** ', 9000);
check('… the same rule for the markups: refused exactly when the rendered document is past the cap',
      (strlen(bulkMailParts('…', $mdBig, 'markdown', 'site', $cfgOn, $unsub, 'en')['html']) > BULK_DOC_MAX_BYTES) === (bulkBodyProblem($mdBig, 'markdown', $cfgOn) !== null));
// Nested layout tables (review M1), under BOTH parsers: a ten-deep campaign comes through whole, a forty-five-deep one keeps its words,
// says so, and is not refused for it.
$t10 = '<p>TOP</p>' . str_repeat('<table><tr><td>', 10) . '<p>DEEP <a href="https://x.example/">cta</a></p>' . str_repeat('</td></tr></table>', 10) . '<p>BOTTOM</p>';
$t45 = '<p>TOP</p>' . str_repeat('<table><tr><td>', 45) . '<p>DEEP <a href="https://x.example/">cta</a></p>' . str_repeat('</td></tr></table>', 45) . '<p>BOTTOM</p>';
$engines = class_exists('Dom\\HTMLDocument') ? [['', 'HTML5 parser'], ['legacy', 'libxml parser']] : [['legacy', 'libxml parser']];
foreach ($engines as [$eng, $engName]) {
    $GLOBALS['__htmlsafe_engine'] = $eng;
    $p10 = bulkMailParts('rmtest t10', $t10, 'html', 'site', $cfgOn, $unsub, 'en');
    check("$engName: a campaign of 10 nested layout tables comes through whole (words, link, 10 tables, in the text half too) and with no warning",
          str_contains($p10['html'], 'DEEP') && str_contains($p10['html'], 'href="https://x.example/"') && substr_count($p10['html'], '<table') >= 10 && $p10['flattened'] === 0
          && bulkBodyWarning($p10) === null && bulkBodyProblem($t10, 'html', $cfgOn) === null && str_contains($p10['text'], 'DEEP cta (https://x.example/)'), $p10['text']);
    $p45 = bulkMailParts('rmtest t45', $t45, 'html', 'site', $cfgOn, $unsub, 'en');
    check("$engName: … and 45 of them: the words are all there, the flattening is counted and said in a sentence, and it is NOT a refusal",
          str_contains($p45['html'], 'DEEP') && str_contains($p45['html'], 'BOTTOM') && str_contains($p45['text'], 'DEEP cta') && $p45['flattened'] >= 1
          && bulkBodyWarning($p45) === __('api.bulk.html_flattened', ['n' => $p45['flattened'], 'max' => HTMLSAFE_MAX_DEPTH]) && bulkBodyProblem($t45, 'html', $cfgOn) === null, $J([$p45['flattened'], $p45['text']]));
}
$GLOBALS['__htmlsafe_engine'] = '';
check('bulkFormats(): plain, the ones the site has on for mail, and html where the server can parse it',
      bulkFormats(array_merge($cfgOn, ['rt_formats_mail' => 'bbcode,markdown'])) === ['plain', 'bbcode', 'markdown', 'html']
      && bulkFormats(array_merge($cfgOn, ['rt_formats_mail' => 'bbcode'])) === ['plain', 'bbcode', 'html']
      && bulkFormats(array_merge($cfgOn, ['rt_formats_mail' => 'bbcode,markdown,flarum'])) === ['plain', 'bbcode', 'markdown', 'flarum', 'html']);
$q = bulkQueue($db, $cfgOn, ['mode' => 'selected', 'ids' => [$carol]], 'rmtest queue', '<p>hi</p>', 'html', 'none');
$row = $db->query("SELECT format, frame, subject FROM mail_queue WHERE subject = 'rmtest queue'")->fetch(PDO::FETCH_ASSOC);
check('bulkQueue(): an HTML message and its frame are kept on the row (or the mail is skipped — an account with no address)',
      (($q['queued'] ?? 0) === 1 && ($row['format'] ?? '') === 'html' && ($row['frame'] ?? '') === 'none') || (($q['queued'] ?? -1) === 0 && ($q['skipped'] ?? 0) === 1), $J([$q, $row]));
check('… too long and empty HTML are refused before a single row', isset(bulkQueue($db, $cfgOn, ['mode' => 'selected', 'ids' => [$carol]], 'rmtest big', str_repeat('a', BULK_BODY_MAX_BYTES + 1))['error'])
      && isset(bulkQueue($db, $cfgOn, ['mode' => 'selected', 'ids' => [$carol]], 'rmtest empty html', '<script>1</script>', 'html')['error'])
      && !(int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE subject IN ('rmtest big', 'rmtest empty html')")->fetchColumn());

// richtextRenderForEmail(): every class is gone, the colours read on both grounds, [hide] shows
$all = "# H\n\n> quote\n\n- a\n- b\n\n1. x\n2. y\n\n| A | B |\n|:-:|--:|\n| 1 | 2 |\n\n```\ncode\n```\n\n`in`\n\n==mark==\n\n> [!NOTE]\n> a callout\n\n- [ ] todo\n- [x] done\n\n||spoiler||\n\n[link](https://example.org/x)\n\nnote[^1]\n\n[^1]: the footnote";
$m = richtextRenderForEmail($all, 'markdown', $cfgOn);
check('richtextRenderForEmail(): no class survives, none is left as class="…" anywhere', !preg_match('/\sclass="/', $m), $m);
check('… tables, callouts, rules, marks, ordered / task lists, footnotes and links are styled inline',
      str_contains($m, '<table style="border-collapse:collapse') && str_contains($m, '<th style="border:1px solid #8a94a6;padding:5px 9px;text-align:left;font-weight:600;background:rgba(127,127,127,0.16);text-align:center">')
      && str_contains($m, 'text-align:right') && str_contains($m, 'border-left:3px solid #8a94a6;border-radius:0 4px 4px 0') && str_contains($m, 'border-left-color:#4a9eff') && str_contains($m, 'text-transform:uppercase')
      && str_contains($m, 'background:#f0b429;color:#111111') && str_contains($m, 'list-style-type:decimal')
      && str_contains($m, 'list-style:none;margin-left:0') && str_contains($m, 'text-decoration:line-through') && str_contains($m, 'padding:0 10px 8px')
      && preg_match('/<a href="https:\/\/example.org\/x"[^>]*style="color:#3b82f6;text-decoration:underline"/', $m) && str_contains($m, 'font-size:12px;margin-top:12px'), $m);
check('… and the colours read on a dark frame AND on white: the old light chips (#f4f5f7) and dark quote text (#555) are gone',
      !str_contains($m, '#f4f5f7') && !str_contains($m, 'color:#555') && !str_contains($m, '#dcdfe4') && str_contains($m, 'rgba(127,127,127,0.18)'));
$bbm = richtextRenderForEmail("[center]c[/center][right]r[/right][hr][color=red]x[/color][mark=blue]m[/mark][font=courier]f[/font][quote=Ann]said[/quote][hide]secret[/hide][list=a][*]one[/list][spoiler=Title]hidden[/spoiler]", 'bbcode', $cfgOn);
check('… BBCode\'s alignment, fonts, cite and nested styles are inline too, an author\'s own style comes last, and [hide] shows its content',
      str_contains($bbm, '<hr style="border:0;border-top:1px solid #8a94a6;margin:14px 0">') && str_contains($bbm, 'text-align:center') && str_contains($bbm, 'text-align:right') && str_contains($bbm, 'font-family:Consolas,Menlo,monospace') && str_contains($bbm, 'display:block;font-weight:600')
      && str_contains($bbm, 'style="background:#f0b429;color:#111111;padding:0 3px;border-radius:2px;background:blue"') && str_contains($bbm, 'color:red') && str_contains($bbm, 'secret') && !str_contains($bbm, 'sign in')
      && str_contains($bbm, 'list-style-type:lower-alpha') && !preg_match('/\sclass="/', $bbm), $bbm);
check('… the quote does not carry a text colour of its own, and no <p> got a style (the frame\'s container decides)',
      !preg_match('/<blockquote[^>]*color:/', $bbm) && str_contains(richtextRenderForEmail('hi', 'bbcode', $cfgOn), '<p>hi</p>'));

[$h, $t] = mailRichParts("[b]hello[/b]\n[url=https://example.org]x[/url]", 'bbcode', $cfgOn);
check('mailRichParts(): BBCode / Markdown for a mail in both halves; plain is escaped text with its lines',
      str_contains($h, '<strong>hello</strong>') && $t === "hello\nx (https://example.org)" && mailRichParts("a<b\nc", 'plain', $cfgOn) === ["a&lt;b<br />\nc", "a<b\nc"]
      && mailRichParts('', 'bbcode', $cfgOn) === ['', ''] && mailRichParts('x', 'html', $cfgOn)[0] === 'x');
$app = ['appeal_type' => 'unblock', 'email' => 'x@example.org', 'name' => 'Ann', 'infoHash' => RM_H1];
$ap = mailAppealDecisionParts($app, 'rejected', $cfgOn, 'en', ['response' => "[b]No[/b], because\n\nof reasons", 'response_format' => 'bbcode', 'title' => 'T']);
$apPlain = mailAppealDecisionParts($app, 'rejected', $cfgOn, 'en', ['response' => 'a <b> & b', 'title' => 'T']);
check('an appeal\'s answer in BBCode: rendered in the HTML half, text in the text half; plain stays escaped text',
      str_contains($ap['html'], '<strong>No</strong>') && str_contains($ap['plain'], "--- Message from our team ---\nNo, because\n\nof reasons") && !str_contains($ap['plain'], '[b]')
      && str_contains($apPlain['html'], 'a &lt;b&gt; &amp; b') && str_contains($apPlain['plain'], 'a <b> & b'), $ap['plain']);
check('sendCustomEmail() takes a format and a cap, and the log keeps the RAW words', str_contains($src('includes/mail.php'), 'function sendCustomEmail(PDO $db, int $reportId, string $customMessage, array $cfg, ?string $lang = null, string $format = \'plain\')')
      && str_contains($src('includes/mail.php'), 'mb_substr(trim($customMessage), 0, MAIL_TEAM_TEXT_MAX') && str_contains($src('includes/mail.php'), '$customMessage, $format, $report[\'infoHash\']')
      && !str_contains($src('includes/mail.php'), 'sanitize($customMessage), $report'));

/* ══ 5. the windows' server side ══════════════════════════════════════════════════════════════════════════════ */
$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Rich test torrent', 'done')")->execute([RM_H1]);
$db->prepare("INSERT INTO hash_comments (info_hash, user_id, guest_tag, body, status) VALUES (?, ?, NULL, 'rmtest rude words', 'visible')")->execute([RM_H1, $carol]);
$cid = (int)$db->lastInsertId();
$rep = contentReportRequest($db, $cfgOn, userFindById($db, $alice), ['csrf_token' => $T, 'kind' => 'comment', 'id' => $cid, 'reason' => 'Rude'], '127.0.0.1');
check('(a report to work on)', !empty($rep['body']['success']), $J($rep));
$act = fn(array $in) => contentReportActionRequest($db, $cfgOn, $in + ['kind' => 'comment', 'target_id' => $cid, 'hash' => RM_H1]);
$bigText = str_repeat('word ', 401);
$r1 = $act(['action' => 'warn', 'reason' => "[img]https://example.org/a.png[/img] no", 'reason_format' => 'bbcode']);
$r2 = $act(['action' => 'warn', 'reason' => $bigText, 'reason_format' => 'plain']);
$r3 = $act(['action' => 'close', 'reply' => $bigText, 'reply_format' => 'markdown']);
check('a picture in a reason, and a reason or an answer over 2 000 characters: 400 invalid_text, a sentence, the field — and nothing done',
      $r1['status'] === 400 && $r1['body']['error'] === 'invalid_text' && $r1['body']['field'] === 'reason' && $r1['body']['message'] === __('api.report_text.no_images')
      && $r2['status'] === 400 && $r2['body']['field'] === 'reason' && $r3['status'] === 400 && $r3['body']['field'] === 'reply'
      && !(int)$db->query("SELECT COUNT(*) FROM user_warnings WHERE user_id = $carol")->fetchColumn()
      && (int)$db->query("SELECT COUNT(*) FROM content_reports WHERE info_hash = '" . RM_H1 . "' AND status = 'open'")->fetchColumn() === 1, $J([$r1, $r2, $r3]));
$fa = $noteFloor();
$reasonSrc = "[b]Please[/b] stop\n\nThis is the second paragraph.";
$replySrc = "*Thank you*\n\nwe have **warned** them.";
$r = $act(['action' => 'warn', 'reason' => $reasonSrc, 'reason_format' => 'bbcode', 'reply' => $replySrc, 'reply_format' => 'markdown', 'note' => 'for the log']);
$w = $db->query("SELECT reason, reason_format FROM user_warnings WHERE user_id = $carol")->fetch(PDO::FETCH_ASSOC);
$nc = $notes($carol, $fa); $na = $notes($alice, $fa);
check('a loud warning with a BBCode reason: kept RAW with its format', $r['status'] === 200 && ($w['reason'] ?? '') === $reasonSrc && ($w['reason_format'] ?? '') === 'bbcode', $J([$r, $w]));
check('… the author\'s notification: a warning whose `rich` is the reason, and whose body holds only the lead-ins (the count, "the moderator\'s reason:") — not the reason',
      count($nc) === 1 && $nc[0]['type'] === 'warning' && $nc[0]['rich'] === $reasonSrc && $nc[0]['rich_format'] === 'bbcode' && str_contains($nc[0]['body'], 'warning number 1')
      && str_ends_with($nc[0]['body'], 'The moderator’s reason:') && !str_contains($nc[0]['body'], 'Please') && !str_contains($nc[0]['body'], 'second paragraph'), $J($nc));
check('… the reporter\'s: in HER language (Polish), `rich` is the Markdown answer, the body only "Moderator napisał(a):"',
      count($na) === 1 && $na[0]['rich'] === $replySrc && $na[0]['rich_format'] === 'markdown' && $na[0]['body'] === 'Moderator napisał(a):' && str_contains($na[0]['title'], 'rozpatrzone'), $J($na));
$row = $db->query("SELECT note, reply, reply_format, status, outcome FROM content_reports WHERE info_hash = '" . RM_H1 . "'")->fetch(PDO::FETCH_ASSOC);
check('… the report: the answer raw with its format, the note plain, closed as warned', ($row['reply'] ?? '') === $replySrc && ($row['reply_format'] ?? '') === 'markdown' && ($row['note'] ?? '') === 'for the log'
      && ($row['status'] ?? '') === 'closed' && ($row['outcome'] ?? '') === 'warned');
$L = contentReportList($db, $cfgOn, 'comment', contentReportFilters(['status' => 'all']), 1, 25);
$card = $L['groups'][0] ?? [];
check('… the card shows the answer rendered (reply_html), and the warning list the reason rendered with its format',
      str_contains($card['reports'][0]['reply_html'] ?? '', '<em>Thank you</em>') && str_contains($card['reports'][0]['reply_html'] ?? '', '<strong>warned</strong>') && ($card['reports'][0]['reply_format'] ?? '') === 'markdown'
      && str_contains($card['author_state']['latest_warnings'][0]['reason_html'] ?? '', '<strong>Please</strong>') && ($card['author_state']['latest_warnings'][0]['reason_format'] ?? '') === 'bbcode', $J($card['author_state']['latest_warnings'] ?? null));
$au = $db->query("SELECT action, summary, detail FROM audit_log WHERE id > $auditFloor AND action IN ('user.warn', 'creport.warn') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$dw = json_decode((string)($au[0]['detail'] ?? ''), true) ?: []; $dc = json_decode((string)($au[1]['detail'] ?? ''), true) ?: [];
check('… the audit log: excerpts and lengths, not the texts — and the ids are all there (the detail was never truncated)',
      ($dw['warning'] ?? 0) > 0 && ($dw['reason']['len'] ?? 0) === mb_strlen($reasonSrc) && str_starts_with((string)($dw['reason']['excerpt'] ?? ''), '[b]Please[/b] stop') && !isset($dw['truncated'])
      && ($dc['reply']['len'] ?? 0) === mb_strlen($replySrc) && ($dc['reports'][0] ?? 0) > 0 && ($dc['reason_format'] ?? '') === 'bbcode' && ($dc['reply_format'] ?? '') === 'markdown' && !isset($dc['truncated'])
      && !isset($dw['reason']['text']) && !isset($dc['reply']['text']) && array_keys($dw['reason']) === ['len', 'excerpt'], $J($au));
// plain, several lines: stays in the body, as before
$fb = $noteFloor();
$act(['action' => 'warn', 'reason' => "line one\nline two"]);
$nb = $notes($carol, $fb);
check('a plain reason with two lines stays in the body, lines kept — as before, no `rich`', count($nb) === 1 && str_contains($nb[0]['body'], "line one\nline two") && $nb[0]['rich'] === null
      && $db->query("SELECT reason_format FROM user_warnings WHERE user_id = $carol ORDER BY id DESC LIMIT 1")->fetchColumn() === 'plain');
$fc = $noteFloor();
$act(['action' => 'warn', 'reason' => '[b]x[/b]', 'reason_format' => 'markdown_not_allowed_here']);
$act(['action' => 'warn', 'reason' => '**md**', 'reason_format' => 'markdown']);
$nm = $notes($carol, $fc);
check('a format the site does not know is plain; Markdown is Markdown', $nm[0]['rich'] === null && str_contains($nm[0]['body'], '[b]x[/b]') && ($nm[1]['rich_format'] ?? '') === 'markdown');
check('userWarningsSummary(): the latest warnings carry their format, and a rendering only when there is one', ($s = userWarningsSummary($db, [$carol], 5)[$carol]['latest']) && $s[0]['reason_html'] !== '' && $s[0]['reason_format'] === 'markdown' && $s[1]['reason_html'] === '' && $s[1]['reason_format'] === 'plain' && $s[2]['reason_html'] === '' && $s[3]['reason_html'] !== '' && $s[3]['reason_format'] === 'bbcode', $J($s ?? null));

// the reported MESSAGE's endpoint
$low = min($alice, $carol); $high = max($alice, $carol);
$db->prepare("INSERT INTO message_threads (u_low, u_high) VALUES (?, ?)")->execute([$low, $high]);
$tid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO user_messages (thread_id, sender_id, body) VALUES (?, ?, 'rmtest rude message')")->execute([$tid, $carol]);
$mid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO message_reports (message_id, thread_id, reporter_id, reported_user_id, reason) VALUES (?, ?, ?, ?, 'Rude')")->execute([$mid, $tid, $alice, $carol]);
$mrid = (int)$db->lastInsertId();
$cfgx = ['pm_enabled' => '1'];
$bad1 = $admin('message_report_action', ['id' => $mrid, 'action' => 'warn', 'reason' => '[img]https://example.org/a.png[/img]', 'reason_format' => 'bbcode'], $cfgx);
$bad2 = $admin('message_report_action', ['id' => $mrid, 'action' => 'close', 'reply' => $bigText], $cfgx);
check('the message report: a picture in the reason and an answer over the limit are refused (invalid_text, the field), nothing done',
      ($bad1['error'] ?? '') === 'invalid_text' && ($bad1['field'] ?? '') === 'reason' && ($bad2['error'] ?? '') === 'invalid_text' && ($bad2['field'] ?? '') === 'reply'
      && $db->query("SELECT status FROM message_reports WHERE id = $mrid")->fetchColumn() === 'open', $J([$bad1, $bad2]));
$fd2 = $noteFloor();
$ok = $admin('message_report_action', ['id' => $mrid, 'action' => 'warn', 'reason' => "Be **kind**\n\nplease", 'reason_format' => 'markdown',
                                       'reply' => "[b]Thanks[/b]\nwe acted", 'reply_format' => 'bbcode', 'note' => 'n'], $cfgx);
$mr = $db->query("SELECT note, reply, reply_format, status FROM message_reports WHERE id = $mrid")->fetch(PDO::FETCH_ASSOC);
$ncm = $notes($carol, $fd2); $nam = $notes($alice, $fd2);
check('the message report, a warning with Markdown and an answer in BBCode: done, the answer kept raw with its format, the note plain',
      !empty($ok['success']) && ($mr['reply'] ?? '') === "[b]Thanks[/b]\nwe acted" && ($mr['reply_format'] ?? '') === 'bbcode' && ($mr['note'] ?? '') === 'n', $J([$ok, $mr]));
check('… the author\'s warning has the Markdown reason as `rich`; the reporter\'s notice has the BBCode answer as `rich`',
      count($ncm) === 1 && $ncm[0]['rich'] === "Be **kind**\n\nplease" && $ncm[0]['rich_format'] === 'markdown' && count($nam) === 1 && $nam[0]['rich'] === "[b]Thanks[/b]\nwe acted" && $nam[0]['rich_format'] === 'bbcode', $J([$ncm, $nam]));
$ok2 = $admin('message_report_action', ['id' => $mrid, 'action' => 'close', 'note' => 'done', 'reply' => 'plain answer'], $cfgx);
$mr2 = $db->query("SELECT reply, reply_format, status FROM message_reports WHERE id = $mrid")->fetch(PDO::FETCH_ASSOC);
check('… closing it with a plain answer: plain, closed', !empty($ok2['success']) && $mr2['reply'] === 'plain answer' && $mr2['reply_format'] === 'plain' && $mr2['status'] === 'closed');
$db->prepare("UPDATE message_reports SET reply = '[b]rendered[/b] answer', reply_format = 'bbcode' WHERE id = ?")->execute([$mrid]);
$fmr = $run('api/admin/fetch_message_reports.php', [], $cfgx + ['users_enabled' => '1'], 0, 'GET', ['status' => 'all']);
$mine = null; foreach (($fmr['reports'] ?? []) as $x) if ((int)$x['id'] === $mrid) $mine = $x;
check('the message queue sends the answer rendered (reply_html) with its format; a plain answer has none',
      $mine && str_contains($mine['reply_html'], '<strong>rendered</strong>') && $mine['reply_format'] === 'bbcode' && $mine['reply'] === '[b]rendered[/b] answer'
      && $mine['note'] === 'done', $J($fmr));

// the appeal's answer
$db->exec("DELETE FROM appeals WHERE infoHash = '" . RM_H1 . "'");
$db->prepare("INSERT INTO appeals (infoHash, name, email, message, appeal_type, status, ip) VALUES (?, 'Ann', '', 'please', 'unblock', 'pending', '127.0.0.1')")->execute([RM_H1]);
$aid = (int)$db->lastInsertId();
$ra = $admin('resolve_appeal', ['id' => $aid, 'status' => 'rejected', 'admin_response' => "No, **sorry** <b>\nbecause", 'admin_response_format' => 'markdown']);
$arch = $db->query("SELECT admin_response, admin_response_format, status FROM appeal_archives WHERE id = $aid")->fetch(PDO::FETCH_ASSOC);
check('an appeal\'s answer is stored RAW (not escaped) with its format, and the archive keeps both', !empty($ra['success']) && ($arch['admin_response'] ?? '') === "No, **sorry** <b>\nbecause" && ($arch['admin_response_format'] ?? '') === 'markdown', $J([$ra, $arch]));
$fa2 = $run('api/admin/fetch_appeals.php', [], ['users_enabled' => '1'], 0, 'GET', ['source' => 'archives', 'id' => $aid]);
check('… and the panel gets it rendered: Markdown through the renderer, escaped', str_contains($fa2['appeal']['admin_response_html'] ?? '', '<strong>sorry</strong>') && !str_contains($fa2['appeal']['admin_response_html'] ?? '', '<b>'), $J($fa2));
$ra2 = $admin('resolve_appeal', ['id' => $aid, 'status' => 'rejected', 'admin_response' => str_repeat('a', 2001)]);
check('… an answer over the limit is refused', isset($ra2['error']));
$db->exec("DELETE FROM appeal_archives WHERE infoHash = '" . RM_H1 . "'");

// the note to one member
$fn = $noteFloor();
$un = $admin('user_notify', ['id' => $dave, 'title' => 'rmtest note', 'body' => "a **bold**\nline", 'format' => 'markdown']);
$unp = $admin('user_notify', ['id' => $dave, 'title' => 'rmtest note plain', 'body' => "a **bold**\nline"]);
$unl = $admin('user_notify', ['id' => $dave, 'title' => 'rmtest note long', 'body' => str_repeat('x', 5001), 'format' => 'markdown']);
$nn = $notes($dave, $fn);
check('the note to a member: Markdown is kept as `rich` (body empty); a plain note as before; a formatted one over 5 000 characters is refused, not cut',
      !empty($un['success']) && $nn[0]['rich'] === "a **bold**\nline" && $nn[0]['rich_format'] === 'markdown' && $nn[0]['body'] === null && !empty($unp['success']) && $nn[1]['body'] === "a **bold**\nline" && $nn[1]['rich'] === null
      && isset($unl['error']) && count($nn) === 2, $J([$un, $unp, $unl, $nn]));

// The formatted note is held to the rules of every other text a moderator writes (review L2): no picture, five links at most, its own limit.
$unImg = $admin('user_notify', ['id' => $dave, 'title' => 'rmtest note img', 'body' => "see [img]https://attacker.example/p.png?u=1[/img]", 'format' => 'bbcode']);
$unImgMd = $admin('user_notify', ['id' => $dave, 'title' => 'rmtest note img md', 'body' => '![x](https://attacker.example/p.png)', 'format' => 'markdown']);
$unLinks = $admin('user_notify', ['id' => $dave, 'title' => 'rmtest note links', 'body' => implode(' ', array_map(fn($i) => '[url=https://example.org/' . $i . ']l' . $i . '[/url]', range(1, 6))), 'format' => 'bbcode']);
$unOk = $admin('user_notify', ['id' => $dave, 'title' => 'rmtest note fine', 'body' => "[b]one[/b] [url=https://example.org/a]link[/url]", 'format' => 'bbcode']);
$unBig = $admin('user_notify', ['id' => $dave, 'title' => 'rmtest note 4000', 'body' => str_repeat('x', 4000), 'format' => 'markdown']);
$nn2 = $notes($dave, $fn);
check('the note to a member: a picture (BBCode or Markdown) and six links are refused with the sentence, 4 000 characters and a link are fine (5 000 is this window\'s limit)',
      ($unImg['error'] ?? '') === __('api.report_text.no_images') && ($unImgMd['error'] ?? '') === __('api.report_text.no_images') && str_contains((string)($unLinks['error'] ?? ''), '6')
      && !empty($unOk['success']) && !empty($unBig['success']) && count($nn2) === 4, $J([$unImg, $unImgMd, $unLinks, $unOk['success'] ?? null, $unBig['success'] ?? null, count($nn2)]));
// (a static read of the files that take such a text — not a test of their routes, so they are listed by file name)
$rtv = array_merge(array_map(fn($b) => 'api/admin/' . $b . '.php', explode(' ', 'user_notify send_email resolve_appeal message_report_action rich_preview')), ['includes/reports.php']);
if (is_file($root . '/includes/moderation.php')) $rtv[] = 'includes/moderation.php';
check('every entry point that takes a moderator\'s text with a format validates it with reportTextValidate()',
      array_filter($rtv, fn($f) => !str_contains($src($f), 'reportTextValidate(')) === [], implode(', ', array_filter($rtv, fn($f) => !str_contains($src($f), 'reportTextValidate('))));

// bulk_send's render: the preview says what became of the campaign (review M1, L4)
$rn = $admin('bulk_send', ['op' => 'render', 'subject' => 'rmtest r', 'body' => $t45, 'format' => 'html']);
$rb = $admin('bulk_send', ['op' => 'render', 'subject' => 'rmtest r', 'body' => $amp, 'format' => 'html']);
$ro = $admin('bulk_send', ['op' => 'render', 'subject' => 'rmtest r', 'body' => $t10, 'format' => 'html']);
check('admin/bulk_send render: a flattened campaign carries the note in `problem` (the document is still the mail), a document past the cap says so, an ordinary campaign has none',
      !empty($rn['success']) && str_contains((string)($rn['problem'] ?? ''), '64') && str_contains((string)$rn['html'], 'DEEP') && !empty($rb['success']) && str_contains((string)($rb['problem'] ?? ''), '95 KB')
      && !empty($ro['success']) && array_key_exists('problem', $ro) && $ro['problem'] === null, $J([$rn['problem'] ?? null, $rb['problem'] ?? null, $ro['problem'] ?? null]));

// the preview endpoint
$pv = $admin('rich_preview', ['text' => "[b]x[/b]\n<script>", 'format' => 'bbcode']);
$pvp = $admin('rich_preview', ['text' => "a<b\nc", 'format' => 'html']);
$pvl = $admin('rich_preview', ['text' => '[img]https://example.org/a.png[/img]', 'format' => 'bbcode']);
$pvs = $admin('rich_preview', ['text' => '', 'format' => 'plain'], ['rt_formats_report' => 'bbcode']);
check('admin/rich_preview: the formats the site accepts, the site\'s own rendering of escaped input, the problem the text would be refused with',
      !empty($pv['success']) && $pv['formats'] === array_merge(['plain'], richtextFormats($cfgOn, 'report')) && str_contains($pv['html'], '<strong>x</strong>') && !str_contains($pv['html'], '<script') && $pv['problem'] === null
      && $pvp['format'] === 'plain' && $pvp['html'] === "a&lt;b<br>\nc" && $pvl['problem'] === __('api.report_text.no_images') && $pvs['formats'] === ['plain', 'bbcode'], $J([$pv, $pvp, $pvl, $pvs]));
check('… it is a route with a decision (any panel session) and writes no audit line',
      str_contains($src('api.php'), "'admin/rich_preview'          => 'panel.access'") && str_contains($src('api.php'), "'admin/rich_preview'          => 'api/admin/rich_preview.php'")
      && !(int)$db->query("SELECT COUNT(*) FROM audit_log WHERE id > $auditFloor AND action LIKE '%rich_preview%'")->fetchColumn());

@unlink($runner);
echo "\n" . $n . ' checks, ' . $fails . " failed\n";
exit($fails ? 1 : 0);
