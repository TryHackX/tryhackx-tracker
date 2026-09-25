<?php
/**
 * The description on a profile (1.69.0, includes/profilebio.php):
 *   php tests/profile_bio_test.php
 *
 * What the owner asked for, pinned: a short text under the name, on by default (the switch AND the
 * permission), five BBCode tags and not one more, a maximum the operator sets, safe validation.
 *
 *   1. the schema, on BOTH paths — a fresh install's CREATE and an upgrade's guarded ALTER, each run on
 *      a scratch database built here and dropped again;
 *   2. the two settings in their four places, and what they clamp to;
 *   3. the permission: registered, in the member preset, granted ONCE by the migration;
 *   4. the renderer: each allowed tag, every other tag literal, nesting, unclosed and stray tags, and
 *      hostile input — read back through a DOM, so "only these elements and attributes" is a fact about
 *      the output and not about a regex over it;
 *   5. the caps: visible characters, the source, links, lines;
 *   6. the save, through profileBioSaveRequest() (the whole endpoint): every refusal and the success —
 *      and the endpoint FILE run as a request in a child process, session and CSRF token included;
 *   7. what the page shows: hidden when the grant is lost, back when it returns, the admin blanket;
 *   8. the panel's clear, run as a request in a child process: the text gone, the member told, one
 *      audit line — and a second clear that changes nothing and says so.
 *
 * Every switch a check leans on is set explicitly in $cfgOn — nothing is inherited from this database's
 * live settings. Self-cleaning: the accounts it makes, their notifications and audit lines, the member
 * group's JSON and the grant marker are put back as they were.
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
require_once $root . '/includes/usermedia.php';
require_once $root . '/includes/people.php';
require_once $root . '/includes/audit.php';
require_once $root . '/includes/icons.php';
require_once $root . '/includes/settings_catalog.php';
require_once $root . '/includes/profilebio.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$GLOBALS['db'] = $db;
langInit([], 'en');
$cfgOn = array_merge($cfg, [
    'users_enabled' => '1', 'users_require_email_verify' => '1', 'profiles_enabled' => '1',
    'profile_bio_enabled' => '1', 'profile_bio_max' => '300', 'link_trusted_domains' => 'example.org',
]);
$GLOBALS['cfg'] = $cfgOn;

// ── what this run changes, and how it is put back ─────────────────────────────────────────────
const PB_USERS = ['pbtest_alice', 'pbtest_bob', 'pbtest_rate', 'pbtest_admin', 'pbtest_unver', 'pbtest_http'];
$memberBefore = (string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn();
$markerBefore = $db->query("SELECT `value` FROM settings WHERE `key` = 'schema_grant_v74_profile_bio'")->fetchColumn();
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$tmpFiles = [];
register_shutdown_function(function () use ($db, $memberBefore, $markerBefore, $auditFloor, &$tmpFiles) {
    foreach (PB_USERS as $name) {
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) userDeleteCascade($db, $id);
    }
    $db->prepare("DELETE FROM audit_log WHERE action = 'user.bio' AND id > ?")->execute([$auditFloor]);
    if ($memberBefore !== '') $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([$memberBefore]);
    if ($markerBefore === false) $db->exec("DELETE FROM settings WHERE `key` = 'schema_grant_v74_profile_bio'");
    else $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('schema_grant_v74_profile_bio', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([(string)$markerBefore]);
    foreach ($tmpFiles as $f) @unlink($f);
});

/** A verified member, made fresh: userEffectivePermissions() memoizes per account for the whole process. */
function pbUser(PDO $db, array $cfg, string $name, bool $verified = true): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0 && $verified) $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$id]);
    return $id;
}
$row = function (int $id) use ($db): array {
    $st = $db->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};

/* ══ 1. the schema, on both paths ═════════════════════════════════════════ */
check('the schema is at 74 or later', TRACKER_SCHEMA_VERSION >= 74, (string)TRACKER_SCHEMA_VERSION);
$create = '';
foreach (trackerSchemaStatements() as $sql) if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS `users`')) $create = $sql;
check('a fresh install\'s users table carries bio TEXT and bio_updated_at DATETIME',
      str_contains($create, '`bio` TEXT DEFAULT NULL') && str_contains($create, '`bio_updated_at` DATETIME DEFAULT NULL'));
$schemaSrc = $src('includes/schema.php');
check('… and an upgraded one gets them from a guarded ALTER',
      str_contains($schemaSrc, "schemaColumnExists(\$db, 'users', 'bio')) \$uparts[] = \"ADD COLUMN `bio` TEXT DEFAULT NULL\"")
      && str_contains($schemaSrc, "schemaColumnExists(\$db, 'users', 'bio_updated_at')) \$uparts[] = \"ADD COLUMN `bio_updated_at` DATETIME DEFAULT NULL\""));
check('this database has both columns', schemaColumnExists($db, 'users', 'bio') && schemaColumnExists($db, 'users', 'bio_updated_at'));
// Both paths, on a real server: a scratch database built the way install.php builds one, then the
// two columns dropped and the guarded statements asked to put them back — which is an upgrade.
$scratch = 'tracker_pb_' . bin2hex(random_bytes(3));
$dsnPort = (string)($db->query('SELECT @@port')->fetchColumn() ?: (defined('DB_PORT') ? DB_PORT : '3306'));
$base = 'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';port=' . $dsnPort . ';charset=utf8mb4';
try {
    $adm = new PDO($base, defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adm->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $sdb = new PDO("$base;dbname=$scratch", defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '',
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        // The table install.php makes before anything else: the guarded statements record their
        // one-time steps in it.
        $sdb->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
        foreach (trackerSchemaStatements() as $sql) $sdb->exec($sql);
        $cols = fn() => array_column($sdb->query("SHOW COLUMNS FROM users")->fetchAll(), 'Type', 'Field');
        $c = $cols();
        check('fresh path: CREATE makes users.bio (text) and users.bio_updated_at (datetime)',
              strtolower((string)($c['bio'] ?? '')) === 'text' && strtolower((string)($c['bio_updated_at'] ?? '')) === 'datetime', json_encode(array_intersect_key($c, ['bio' => 1, 'bio_updated_at' => 1])));
        $sdb->exec("ALTER TABLE users DROP COLUMN bio, DROP COLUMN bio_updated_at");
        $guarded = trackerSchemaGuardedStatements($sdb);
        $bioAlters = array_values(array_filter($guarded, fn($s) => is_string($s) && str_contains($s, 'ALTER TABLE `users`') && str_contains($s, '`bio`')));
        foreach ($bioAlters as $s) $sdb->exec($s);
        $c = $cols();
        check('upgrade path: the guarded ALTER puts both back on a table that predates them',
              count($bioAlters) === 1 && strtolower((string)($c['bio'] ?? '')) === 'text' && isset($c['bio_updated_at']), json_encode($bioAlters));
        check('… and asks for nothing once they are there', !array_filter(trackerSchemaGuardedStatements($sdb), fn($s) => is_string($s) && str_contains($s, '`bio`')));
    } finally {
        $adm->exec("DROP DATABASE IF EXISTS `$scratch`");
    }
} catch (\Throwable $e) {
    check('a scratch database could be built for the two schema paths', false, $e->getMessage());
}

/* ══ 2. the settings, in their four places ════════════════════════════════ */
$defs = trackerSchemaDefaultSettings();
check('profile_bio_enabled ships ON and profile_bio_max ships at 300',
      ($defs['profile_bio_enabled'] ?? null) === '1' && ($defs['profile_bio_max'] ?? null) === '300');
$save = $src('api/admin/save_settings.php');
check('both are in the save allow-list', str_contains($save, "'profile_bio_enabled', 'profile_bio_max',"));
check('… the maximum clamped from the constants, 20–1000, 300 by default',
      str_contains($save, "'profile_bio_max' => [PROFILE_BIO_MAX_MIN, PROFILE_BIO_MAX_MAX, PROFILE_BIO_MAX_DEFAULT]")
      && PROFILE_BIO_MAX_MIN === 20 && PROFILE_BIO_MAX_MAX === 1000 && PROFILE_BIO_MAX_DEFAULT === 300);
check('… and the switch coerced to 0/1 with the other switches', (bool)preg_match("/'covers_enabled', 'profile_bio_enabled'\] as \\\$k\)/", $save));
$tpl = $src('templates/admin/settings.php');
$sp = strpos($tpl, 'id="section-profile-bio"');
$sec = $sp === false ? '' : substr($tpl, $sp, (int)strpos($tpl, 'class="settings-section"', $sp + 20) - $sp);
check('a section of their own in Settings → Profiles, with both controls',
      str_contains($tpl, 'id="section-profile-bio" data-group="profiles"') && str_contains($sec, 'name="profile_bio_enabled"')
      && str_contains($sec, 'name="profile_bio_max"') && str_contains($sec, 'min="<?= PROFILE_BIO_MAX_MIN ?>" max="<?= PROFILE_BIO_MAX_MAX ?>"'));
check('… labelled and explained from the dictionary',
      str_contains($sec, "_h('settings.profile_bio_heading')") && str_contains($sec, "__('settings.profile_bio_enabled_hint')")
      && str_contains($sec, "__('settings.profile_bio_max_hint'"));
$kw = settingsCatalogKeywords();
check('both have search words', !empty($kw['profile_bio_enabled']) && !empty($kw['profile_bio_max']));
$en = include $root . '/lang/en.php';
$pl = include $root . '/lang/pl.php';
$helpOk = true;
foreach (['settings.profile_bio_intro', 'settings.profile_bio_enabled_hint', 'settings.profile_bio_max_hint'] as $k) {
    if (trim((string)($en[$k] ?? '')) === '' || trim((string)($pl[$k] ?? '')) === '' || ($en[$k] ?? '') === ($pl[$k] ?? '')) $helpOk = false;
}
check('the help texts exist in both languages, and the Polish is Polish', $helpOk && str_contains((string)$pl['settings.profile_bio_max_hint'], 'czytelnik'));
check('profileBioMax() clamps: nothing, junk and 0 are 300; 5 is 20; 5000 is 1000',
      profileBioMax([]) === 300 && profileBioMax(['profile_bio_max' => 'abc']) === 300 && profileBioMax(['profile_bio_max' => '0']) === 300
      && profileBioMax(['profile_bio_max' => '5']) === 20 && profileBioMax(['profile_bio_max' => '5000']) === 1000
      && profileBioMax(['profile_bio_max' => '120']) === 120);
check('the source may be four times the visible limit, never more than 4000 characters',
      profileBioSourceCap(['profile_bio_max' => '300']) === 1200 && profileBioSourceCap(['profile_bio_max' => '1000']) === 4000
      && PROFILE_BIO_SOURCE_BYTES === 8192);
check('the feature needs accounts, profiles and its own switch',
      profileBioEnabled($cfgOn) && !profileBioEnabled(array_merge($cfgOn, ['profile_bio_enabled' => '0']))
      && !profileBioEnabled(array_merge($cfgOn, ['users_enabled' => '0'])) && !profileBioEnabled(array_merge($cfgOn, ['profiles_enabled' => '0'])));

/* ══ 3. the permission ════════════════════════════════════════════════════ */
check('profile.bio is registered', isset(userPermissionList()['profile.bio']));
check('… in the member preset, and nowhere near the premium extras',
      in_array('profile.bio', userGroupPresets()['member']['perms'], true) && !in_array('profile.bio', userGroupPresets()['premium']['perms'], true));
check('… and with accounts switched off nobody has it (a description belongs to an account)', userLegacyDefault('profile.bio') === false);
// The one-time grant, asked of the MIGRATION: taken off member, marker removed, migrations run.
$db->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"profile.bio\"') WHERE slug = 'member'");
$db->exec("DELETE FROM settings WHERE `key` = 'schema_grant_v74_profile_bio'");
trackerSchemaDataMigrations($db, $cfgOn);
$gperm = fn(string $slug): array => json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = " . $db->quote($slug))->fetchColumn(), true) ?: [];
check('the v74 migration grants profile.bio to members', !empty($gperm('member')['profile.bio']), json_encode($gperm('member')));
check('… and not to guests', empty($gperm('guest')['profile.bio']));
check('… and stamps its marker', $db->query("SELECT COUNT(*) FROM settings WHERE `key` = 'schema_grant_v74_profile_bio'")->fetchColumn() == 1);
$db->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"profile.bio\"') WHERE slug = 'member'");
trackerSchemaDataMigrations($db, $cfgOn);
check('ONCE: an operator who takes it away afterwards keeps it taken away', empty($gperm('member')['profile.bio']));
$db->exec("UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, '{\"profile.bio\":true}') WHERE slug = 'member'");

/* ══ 4. the renderer ══════════════════════════════════════════════════════ */
$r = fn(string $s): string => profileBioParse(profileBioClean($s), $cfgOn)['html'];
$m = fn(string $s): array => profileBioParse(profileBioClean($s), $cfgOn);
check('[b] [i] [u] [s], in any case', $r('[b]a[/b] [I]b[/I] [u]c[/U] [S]d[/s]') === '<strong>a</strong> <em>b</em> <u>c</u> <s>d</s>', $r('[b]a[/b] [I]b[/I] [u]c[/U] [S]d[/s]'));
$link = $r('[url=https://example.org/x]words[/url]');
check('[url=address]words[/url] is a link with the site\'s rel and target, no leaving-warning for a trusted domain',
      $link === '<a href="https://example.org/x" rel="nofollow noopener noreferrer ugc" target="_blank">words</a>', $link);
$link2 = $r('[URL]https://other.test/p?a=1&b=2[/URL]');
check('[url]address[/url] shows the address, escaped, and an off-site link carries data-external',
      $link2 === '<a href="https://other.test/p?a=1&amp;b=2" rel="nofollow noopener noreferrer ugc" target="_blank" data-external="1">https://other.test/p?a=1&amp;b=2</a>', $link2);
check('[url="…"] with quotes round the address works too', str_contains($r('[url="https://q.example.org"]q[/url]'), 'href="https://q.example.org"'));
check('[url=address][/url] shows its address as its words', $r('[url=https://example.org][/url]') === '<a href="https://example.org" rel="nofollow noopener noreferrer ugc" target="_blank">https://example.org</a>');
$literal = [
    '[img]https://example.org/x.png[/img]', '[color=red]r[/color]', '[quote]q[/quote]', '[quote=bob]q[/quote]',
    '[table][tr][td]1[/td][/tr][/table]', '[size=9]s[/size]', '[spoiler]x[/spoiler]', '[spoiler=t]x[/spoiler]', '**bold**', '*it*',
    '[code]c[/code]', '[hide]h[/hide]', '[center]c[/center]', '[list][*]a[/list]', '[youtube]dQw4w9WgXcQ[/youtube]',
    '[email]a@b.cz[/email]', '[b=1]x[/b=1]', ':fire:', 'https://example.org/bare', '[mark]m[/mark]', '[sub]x[/sub]', '[hr]',
];
$notLiteral = [];
foreach ($literal as $t) {
    $h = $r($t);
    if ($h !== htmlspecialchars($t, ENT_QUOTES, 'UTF-8')) $notLiteral[] = $t . ' => ' . $h;
}
check('every other tag, Markdown, a shortcode and a bare address stay exactly the text they are', $notLiteral === [], implode(' | ', $notLiteral));
check('nesting', $r('[b][i]x[/i][/b]') === '<strong><em>x</em></strong>' && $r('[url=https://example.org][b]w[/b][/url]') === '<a href="https://example.org" rel="nofollow noopener noreferrer ugc" target="_blank"><strong>w</strong></a>');
check('a closer that crosses another tag closes both and reopens the inner one', $r('[b][i]x[/b]y[/i]') === '<strong><em>x</em></strong><em>y</em>', $r('[b][i]x[/b]y[/i]'));
check('… but never reopens a link: one link does not become two',
      $r('[b][url=https://example.org]a[/b]b[/url]') === '<strong><a href="https://example.org" rel="nofollow noopener noreferrer ugc" target="_blank">a</a></strong>b[/url]', $r('[b][url=https://example.org]a[/b]b[/url]'));
check('an unclosed tag is closed at the end', $r('[b]open [i]and more') === '<strong>open <em>and more</em></strong>');
check('a stray closer is text', $r('text[/b] and [/url]') === 'text[/b] and [/url]');
check('a link inside a link is text', $r('[url=https://example.org][url=https://example.org/b]in[/url][/url]')
      === '<a href="https://example.org" rel="nofollow noopener noreferrer ugc" target="_blank">[url=https://example.org/b]in</a>[/url]', $r('[url=https://example.org][url=https://example.org/b]in[/url][/url]'));
check('tags open at once are capped at eight; the ninth is text',
      substr_count($r(str_repeat('[b]', 9) . 'x'), '<strong>') === 8 && str_contains($r(str_repeat('[b]', 9) . 'x'), '[b]x'));
$hostile = [
    'js' => '[url=javascript:alert(1)]x[/url]', 'jscase' => '[url=JaVaScRiPt:alert(1)]x[/url]',
    'jstab' => "[url=java\tscript:alert(1)]x[/url]", 'data' => '[url=data:text/html;base64,PHNjcmlwdD4=]x[/url]',
    'vb' => '[url=vbscript:msgbox(1)]x[/url]', 'rel' => '[url]//evil.example[/url]', 'relp' => '[url=//evil.example]x[/url]',
    'js2' => '[url]javascript:alert(1)[/url]', 'nohost' => '[url=https://]x[/url]', 'space' => '[url]https://exa mple.org[/url]',
];
$leaked = [];
foreach ($hostile as $k => $t) {
    $h = $r($t);
    if (str_contains($h, '<a') || stripos($h, 'href') !== false) $leaked[] = $k . ' => ' . $h;
}
check('javascript:, data:, vbscript:, //host, no host and an address with a space make no link at all', $leaked === [], implode(' | ', $leaked));
check('… they are shown as the text that was typed', $r($hostile['js']) === '[url=javascript:alert(1)]x[/url]');
$q = $r('[url=https://example.org/"onmouseover="alert(1)]q[/url]');
check('a quote and an event handler inside an address stay inside the escaped href',
      $q === '<a href="https://example.org/&quot;onmouseover=&quot;alert(1)" rel="nofollow noopener noreferrer ugc" target="_blank">q</a>', $q);
check('<script> is text, and &lt; comes back as the four characters typed',
      $r('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;' && $r('&lt;b&gt;') === '&amp;lt;b&amp;gt;');
// The whole hostile set at once, read back through a DOM: only these elements, only these attributes.
$all = implode("\n", array_merge(array_values($hostile), $literal, [
    '<img src=x onerror=alert(1)>', '"><svg onload=alert(1)>', "[url=https://example.org/'><script>]a[/url]",
    '[b][i][u][s][url=https://example.org]deep[/url][/s][/u][/i][/b]', '[url=https://a.example/x"y]ok[/url]',
]));
$html = profileBioParse(profileBioClean($all), array_merge($cfgOn, ['profile_bio_max' => '1000']))['html'];
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
$badNodes = [];
foreach ((new DOMXPath($dom))->query('//div[@id="root"]//*') as $el) {
    if (!in_array($el->nodeName, ['strong', 'em', 'u', 's', 'a', 'br'], true)) $badNodes[] = $el->nodeName;
    foreach ($el->attributes as $at) {
        if ($el->nodeName !== 'a' || !in_array($at->name, ['href', 'rel', 'target', 'data-external'], true)) $badNodes[] = $el->nodeName . '@' . $at->name;
    }
    if ($el->nodeName === 'a') {
        if (!preg_match('#^https?://#i', $el->getAttribute('href'))) $badNodes[] = 'href:' . $el->getAttribute('href');
        if ($el->getAttribute('rel') !== 'nofollow noopener noreferrer ugc' || $el->getAttribute('target') !== '_blank') $badNodes[] = 'rel/target';
    }
}
check('read through a DOM, hostile input yields only strong/em/u/s/a/br, and an <a> only href/rel/target/data-external',
      $badNodes === [], implode(', ', array_unique($badNodes)));
// Characters that draw nothing or turn a line round are taken out; the ones emoji and scripts need stay.
$cleaned = profileBioClean("a\u{202E}b\u{2066}c\u{200B}d\u{FEFF}e\x07f\x00g\u{0085}h\u{200D}i\u{200C}j\u{200E}k");
check('bidi overrides and isolates, zero-width space, BOM and control characters are removed; ZWJ, ZWNJ and LRM stay',
      $cleaned === "abcdefgh\u{200D}i\u{200C}j\u{200E}k", bin2hex($cleaned));
check('a text of nothing but invisible characters is empty — it clears rather than saving blank',
      profileBioClean("\u{200B}\u{FEFF} \u{2060}\n\n\u{202E}") === '');
$emoji = $m("\u{1F600}\u{1F44D}ąę");
check('a four-byte emoji is one character, like a Polish letter', $emoji['chars'] === 4, (string)$emoji['chars']);
check('line breaks: CRLF and CR are one kind, three or more become two, spaces round them go',
      profileBioClean("a  \r\n\r\n\r\n\r\n  b\rc\td") === "a\n\nb\nc d" && $r("a\n\n\nb") === 'a<br><br>b');
check('the visible count is what a reader sees: tags do not count, their words do, a link\'s address counts when shown',
      $m('[b]ab[/b]')['chars'] === 2 && $m('[url=https://example.org]ab[/url]')['chars'] === 2
      && $m('[url]https://example.org[/url]')['chars'] === 19 && $m('[color=red]x[/color]')['chars'] === 20 && $m("a\nb")['chars'] === 3);

/* ══ 5. the caps ══════════════════════════════════════════════════════════ */
$problem = fn(string $s, array $c = []) => profileBioProblem(profileBioClean($s), array_merge($cfgOn, $c));
check('300 visible characters pass, 301 do not — and the message names both numbers',
      $problem(str_repeat('a', 300)) === null && ($problem(str_repeat('a', 301))['code'] ?? '') === 'too_long'
      && ($problem(str_repeat('a', 301))['vars'] ?? []) === ['n' => 301, 'max' => 300]);
check('tags do not count against it: 300 bold characters pass', $problem('[b]' . str_repeat('a', 300) . '[/b]') === null);
check('… but the source has its own ceiling (four times the limit)',
      ($problem(str_repeat('[b][/b]', 172))['code'] ?? '') === 'too_long_source' && $problem(str_repeat('[b][/b]', 171)) === null);
check('… and bytes are capped too: 1000 four-byte characters are 4000 bytes, fine; the byte ceiling is 8 KB',
      $problem(str_repeat("\u{1F600}", 1000), ['profile_bio_max' => '1000']) === null
      && ($problem(str_repeat("\u{1F600}", 2100), ['profile_bio_max' => '1000'])['code'] ?? '') === 'too_long_source');
$four = '[url=https://1.example]1[/url] [url=https://2.example]2[/url] [url=https://3.example]3[/url] [url=https://4.example]4[/url]';
check('three links pass, a fourth is refused', $problem('[url=https://1.example]1[/url] [url=https://2.example]2[/url] [url=https://3.example]3[/url]') === null
      && ($problem($four)['code'] ?? '') === 'too_many_links');
check('… and a fourth that reached the page anyway is text', substr_count($r($four), '<a ') === 3 && str_contains($r($four), '[url=https://4.example]4[/url]'));
check('eight lines pass, nine do not (a blank line counts)',
      $problem(implode("\n", array_fill(0, 8, 'x'))) === null && ($problem(implode("\n", array_fill(0, 9, 'x')))['code'] ?? '') === 'too_many_lines'
      && ($problem("a\n\nb\n\nc\n\nd\n\ne")['code'] ?? '') === 'too_many_lines');
check('an address that is not one is refused, so nobody finds out by looking', ($problem('see [url=example.org]this[/url]')['code'] ?? '') === 'bad_link');
$stored = implode("\n", range(1, 12));
check('a stored text past the line cap (never saved through the endpoint) keeps its words, not its breaks',
      profileBioRender($stored, $cfgOn) === '1<br>2<br>3<br>4<br>5<br>6<br>7<br>8 9 10 11 12', profileBioRender($stored, $cfgOn));
// The shared renderer is not touched by any of this: descriptions, messages and shouts go on rendering
// the tags this one refuses.
check('the shared renderer still renders what this one refuses ([color], [quote], a bare address)',
      str_contains(richtextRender('[color=red]x[/color]', 'bbcode', $cfgOn), 'rt-color')
      && str_contains(richtextRender('[quote]q[/quote]', 'bbcode', $cfgOn), 'rt-quote')
      && str_contains(richtextRender('https://example.org', 'bbcode', $cfgOn), '<a '));

/* ══ 6. the save: profileBioSaveRequest(), the whole endpoint ═════════════ */
$aliceId = pbUser($db, $cfgOn, 'pbtest_alice');
$bobId = pbUser($db, $cfgOn, 'pbtest_bob');
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$bobId]);   // no groups: no permissions
userPermissionsForget($bobId);
$alice = fn() => $row($aliceId);
$_SESSION['csrf_token'] = 'pb-test-token';
$req = fn(?array $me, array $in, array $c = []) => profileBioSaveRequest($db, array_merge($cfgOn, $c), $me, $in, '127.0.0.1');
$ok = ['csrf_token' => 'pb-test-token'];
$x = $req($alice(), ['bio' => 'hello']);
check('no token: 403, before anything else is asked', $x['status'] === 403 && ($x['body']['error'] ?? '') === 'csrf');
$x = $req($alice(), ['csrf_token' => 'wrong', 'bio' => 'hello']);
check('a wrong token: 403', $x['status'] === 403 && ($x['body']['error'] ?? '') === 'csrf');
$x = $req(null, $ok + ['bio' => 'hello']);
check('signed out: 401', $x['status'] === 401 && ($x['body']['error'] ?? '') === 'login_required' && ($x['body']['message'] ?? '') !== '');
$x = $req($alice(), $ok + ['bio' => 'hello'], ['profile_bio_enabled' => '0']);
check('the feature off: 403 disabled', $x['status'] === 403 && ($x['body']['error'] ?? '') === 'disabled');
$x = $req($row($bobId), $ok + ['bio' => 'hello']);
check('an account whose groups do not grant profile.bio: 403 no_permission', $x['status'] === 403 && ($x['body']['error'] ?? '') === 'no_permission');
$x = $req($alice(), $ok + ['bio' => str_repeat('b', 301)]);
check('too long: 400, with the numbers in the message', $x['status'] === 400 && ($x['body']['error'] ?? '') === 'too_long'
      && str_contains((string)($x['body']['message'] ?? ''), '301') && str_contains((string)($x['body']['message'] ?? ''), '300'), json_encode($x['body']));
$x = $req($alice(), $ok + ['bio' => "\xFF\xFE broken"]);
check('invalid UTF-8: 400', $x['status'] === 400 && ($x['body']['error'] ?? '') === 'bad_encoding');
$x = $req($alice(), $ok + ['bio' => ['not', 'text']]);
check('not a string: 400', $x['status'] === 400 && ($x['body']['error'] ?? '') === 'invalid');
$x = $req($alice(), $ok + ['bio' => str_repeat('x', 70000)]);
check('a body of more than 64 KB is refused for its size alone: 413', $x['status'] === 413);
$x = $req($alice(), $ok + ['bio' => '[url=javascript:alert(1)]x[/url]']);
check('a link that is not one: 400 bad_link', $x['status'] === 400 && ($x['body']['error'] ?? '') === 'bad_link');
// langInit() does its work once per request; a second reader in the same process is a fresh start.
$relang = function (array $c, ?string $user) { $GLOBALS['__lang']['current'] = null; langInit($c, $user); };
$relang(['default_language' => 'pl'], null);
$x = $req($alice(), $ok + ['bio' => str_repeat('b', 301)]);
check('the refusal is in the reader\'s language', ($x['body']['message'] ?? '') === 'Ten opis ma za dużo znaków: 301, a limit to 300.', (string)($x['body']['message'] ?? ''));
$relang([], 'en');
$typed = "  Hi [b]there[/b]\r\n\r\n\r\nsee [url=https://example.org]my page[/url] [color=red]x[/color]\u{202E}  ";
$x = $req($alice(), $ok + ['bio' => $typed]);
$stored = $alice();
check('success: 200 with the server\'s HTML, the cleaned text and the visible count',
      $x['status'] === 200 && !empty($x['body']['success'])
      && ($x['body']['html'] ?? '') === 'Hi <strong>there</strong><br><br>see <a href="https://example.org" rel="nofollow noopener noreferrer ugc" target="_blank">my page</a> [color=red]x[/color]'
      && ($x['body']['text'] ?? '') === "Hi [b]there[/b]\n\nsee [url=https://example.org]my page[/url] [color=red]x[/color]"
      && ($x['body']['chars'] ?? -1) === 42 && ($x['body']['max'] ?? 0) === 300, json_encode($x['body'], JSON_UNESCAPED_UNICODE));
check('… stored AS TYPED (cleaned, not rendered), with the stamp', (string)$stored['bio'] === (string)($x['body']['text'] ?? '') && !empty($stored['bio_updated_at']));
$x = $req($alice(), $ok + ['bio' => "  \u{200B}  "]);
check('an empty text clears: 200, the column NULL again', $x['status'] === 200 && ($x['body']['html'] ?? 'x') === '' && $alice()['bio'] === null);
$db->prepare("UPDATE users SET bio = 'kept by an old grant' WHERE id = ?")->execute([$bobId]);
$x = $req($row($bobId), $ok + ['bio' => '']);
check('clearing your own is never behind the permission', $x['status'] === 200 && $row($bobId)['bio'] === null);
$db->prepare("UPDATE users SET pm_muted_until = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id = ?")->execute([$aliceId]);
$x = $req($alice(), $ok + ['bio' => 'while muted']);
check('a muted account may not write one (the mute messages and the room obey): 403 with the date',
      $x['status'] === 403 && ($x['body']['error'] ?? '') === 'muted' && str_contains((string)($x['body']['message'] ?? ''), substr((string)$alice()['pm_muted_until'], 0, 10)));
$x = $req($alice(), $ok + ['bio' => '']);
check('… and may still take theirs down', $x['status'] === 200);
$db->prepare("UPDATE users SET pm_muted_until = NULL WHERE id = ?")->execute([$aliceId]);
$rateId = pbUser($db, $cfgOn, 'pbtest_rate');
$okN = 0;
for ($i = 0; $i < PROFILE_BIO_RATE; $i++) {
    $x = $req($row($rateId), $ok + ['bio' => 'take ' . $i]);
    if ($x['status'] === 200) $okN++;
}
$x = $req($row($rateId), $ok + ['bio' => 'one too many']);
check('twenty saves an hour go through, per account', $okN === 20 && PROFILE_BIO_RATE === 20, (string)$okN);
check('… the twenty-first is 429, and the text is what the twentieth left', $x['status'] === 429 && ($x['body']['error'] ?? '') === 'rate_limit'
      && (string)$row($rateId)['bio'] === 'take 19');
$api = $src('api.php');
check('the endpoint is routed and hands the request to profileBioSaveRequest() and nothing else',
      str_contains($api, "'profile_bio'                => 'api/profile_bio.php'")
      && str_contains($src('api/profile_bio.php'), "requirePost();\n\$r = profileBioSaveRequest(\$db, \$cfg, currentUser(\$db), readJsonBody(), getClientIp(\$cfg));\njsonResponse(\$r['body'], (int)\$r['status']);"));

// The endpoint FILE, run as a request in a child process: a started session holding the account and
// the token, a POST body, the same includes api.php loads — and the answer read off what it prints.
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pb_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/richtext.php", "includes/mail.php", "includes/users.php", "includes/favourites.php",
          "includes/usermedia.php", "includes/profilebio.php", "includes/people.php", "includes/audit.php", "includes/auth.php",
          "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
// The session file is this run\'s own: gone again before PHP would write it at the end.
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
$GLOBALS["__audit_endpoint"] = $a["endpoint"];
require $a["file"];
');
$run = function (string $endpoint, string $file, array $post, array $session, array $cfgExtra = []) use ($root, &$tmpFiles): array {
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pb_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmpFiles[] = $argFile;
    file_put_contents($argFile, json_encode(['root' => $root, 'endpoint' => $endpoint, 'file' => $root . '/' . $file, 'post' => $post,
        'session' => $session, 'cfg' => $cfgExtra, 'sid' => 'pbtest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($GLOBALS['runner']) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => $out];
};
$GLOBALS['runner'] = $runner;
$httpId = pbUser($db, $cfgOn, 'pbtest_http');
$sessFor = fn(int $uid) => ['user_id' => $uid, 'user_login_time' => time(), 'csrf_token' => 'pb-child-token'];
$bioCfg = ['users_enabled' => '1', 'profiles_enabled' => '1', 'profile_bio_enabled' => '1', 'profile_bio_max' => '300'];
$j = $run('profile_bio', 'api/profile_bio.php', ['csrf_token' => 'pb-child-token', 'bio' => '[i]from the endpoint[/i] [img]x[/img]'], $sessFor($httpId), $bioCfg);
check('the endpoint file, as a request: signed in by the session, saved, and answered with the server\'s HTML',
      !empty($j['success']) && ($j['html'] ?? '') === '<em>from the endpoint</em> [img]x[/img]' && (string)$row($httpId)['bio'] === '[i]from the endpoint[/i] [img]x[/img]',
      json_encode($j));
$j = $run('profile_bio', 'api/profile_bio.php', ['csrf_token' => 'not-the-token', 'bio' => 'x'], $sessFor($httpId), $bioCfg);
check('… and refuses a request without the session\'s token', ($j['error'] ?? '') === 'csrf' && empty($j['success']), json_encode($j));
$j = $run('profile_bio', 'api/profile_bio.php', ['csrf_token' => 'pb-child-token', 'bio' => 'x'], ['csrf_token' => 'pb-child-token'], $bioCfg);
check('… and one with no account in the session', ($j['error'] ?? '') === 'login_required', json_encode($j));

/* ══ 7. what a profile shows ══════════════════════════════════════════════ */
$db->prepare("UPDATE users SET bio = ? WHERE id = ?")->execute(['[b]shown[/b] while allowed', $aliceId]);
check('a member\'s description is drawn', profileBioFor($db, $cfgOn, $alice()) === '<strong>shown</strong> while allowed');
check('… and nothing is drawn for an empty one', profileBioFor($db, $cfgOn, ['id' => $bobId, 'bio' => null]) === ''
      && profileBioFor($db, $cfgOn, ['id' => $bobId, 'bio' => "  \n "]) === '');
$memberGid = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'member'")->fetchColumn();
userRevokeGroup($db, $aliceId, $memberGid, false);
check('when their groups lose profile.bio the text is HIDDEN', profileBioFor($db, $cfgOn, $alice()) === ''
      && !profileBioMayWrite($db, $cfgOn, $alice()));
check('… and KEPT', (string)$alice()['bio'] === '[b]shown[/b] while allowed');
userGrantGroup($db, $aliceId, $memberGid, null, 'test', 'profile_bio', false);
check('… and back, unchanged, the moment the grant is', profileBioFor($db, $cfgOn, $alice()) === '<strong>shown</strong> while allowed'
      && profileBioMayWrite($db, $cfgOn, $alice()));
check('with the feature switched off nobody\'s is drawn and nobody may write one',
      profileBioFor($db, array_merge($cfgOn, ['profile_bio_enabled' => '0']), $alice()) === ''
      && !profileBioMayWrite($db, array_merge($cfgOn, ['profile_bio_enabled' => '0']), $alice()));
// The owner's own account is in the admin group, which stores no profile.bio and passes by its blanket.
$adminId = pbUser($db, $cfgOn, 'pbtest_admin');
$adminGid = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'admin'")->fetchColumn();
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$adminId]);
userGrantGroup($db, $adminId, $adminGid, null, 'test', 'profile_bio', false);
$db->prepare("UPDATE users SET bio = 'the owner' WHERE id = ?")->execute([$adminId]);
$adminStored = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'admin'")->fetchColumn(), true) ?: [];
check('an account only in the admin group has its text drawn, though that group stores no profile.bio',
      empty($adminStored['profile.bio']) && profileBioFor($db, $cfgOn, $row($adminId)) === 'the owner');
$unverId = pbUser($db, $cfgOn, 'pbtest_unver', false);
check('an unverified account sits at guest level: it may not write one', !profileBioMayWrite($db, $cfgOn, $row($unverId)));
$prof = $src('templates/pages/profile.php');
check('the profile page asks profileBioFor() of the owner and profileBioMayWrite() of its reader, on its own profile only',
      str_contains($prof, '$bioHtml = function_exists(\'profileBioFor\') ? profileBioFor($db, $cfg, $profile) : \'\';')
      && str_contains($prof, '$bioEdit = $isSelf && function_exists(\'profileBioMayWrite\') && profileBioMayWrite($db, $cfg, $viewer);')
      && str_contains($prof, '<?php if ($bioHtml !== \'\' || $bioEdit): ?>'));
check('… in the text column beside the picture, under the name row, with ids for the language switch and no style=""',
      strpos($prof, 'class="profile-head-row"') < strpos($prof, 'id="profile-bio"') && str_contains($prof, 'data-lang-keep')
      && str_contains($prof, 'dir="auto"') && !preg_match('/id="profile-bio[^>]*style=/', $prof));
check('the account page shows the row only to an account that may write one, and links to #bio',
      str_contains($src('templates/pages/account.php'), 'profileBioMayWrite($db, $cfg, $meUser)') && str_contains($src('templates/pages/account.php'), '#bio"'));
$lay = $src('templates/layout.php');
check('the editor script is loaded on profile pages only, and only while the feature is on',
      str_contains($lay, "<?php if (\$action === 'u' && function_exists('profileBioEnabled') && profileBioEnabled(\$cfg)): ?>")
      && str_contains($lay, 'assets/js/profile-bio.js'));
check('its strings reach the public pages (js.bio. in LANG_JS_PUBLIC)', in_array('js.bio.', LANG_JS_PUBLIC, true));
$js = $src('assets/js/profile-bio.js');
$jsCode = (string)preg_replace(['#/\*.*?\*/#s', '#(^|[^:])//[^\n]*#'], ['', '$1'], $js);
check('the editor renders no markup itself: the only HTML it sets is the server\'s',
      substr_count($jsCode, 'innerHTML =') === 1 && str_contains($jsCode, "textEl.innerHTML = typeof r.html === 'string' ? r.html : '';")
      && !preg_match('/\bon[a-z]+\s*=\s*["\']/i', $jsCode) && !str_contains($jsCode, 'insertAdjacentHTML'));
$map = iconFaMap();
check('the five toolbar icons are Bootstrap names the Font Awesome map knows',
      str_contains($js, "'bi-type-bold'") && str_contains($js, "'bi-type-italic'") && str_contains($js, "'bi-type-underline'")
      && str_contains($js, "'bi-type-strikethrough'") && str_contains($js, "'bi-link-45deg'")
      && isset($map['type-bold'], $map['type-italic'], $map['type-underline'], $map['type-strikethrough'], $map['link-45deg']));

/* ══ 8. the panel's clear ═════════════════════════════════════════════════ */
check('admin/user_bio is routed, gated like editing a user and audited as user.bio in the users group',
      str_contains($api, "'admin/user_bio'             => 'api/admin/user_bio.php'")
      && (bool)preg_match("#'admin/user_bio'\s*=>\s*'panel\.users\.edit'#", $api)
      && auditEndpointAction('admin/user_bio') === 'user.bio' && auditGroupOf('user.bio') === 'users');
$db->prepare("UPDATE users SET bio = ? WHERE id = ?")->execute(['[u]rude words[/u] here', $aliceId]);
$noteFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM user_notifications")->fetchColumn();
$auditNow = fn() => $db->query("SELECT * FROM audit_log WHERE action = 'user.bio' AND id > " . (int)$auditFloor . " ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$panelSess = ['loggedin' => true, 'login_time' => time(), 'last_activity' => time()];
$j = $run('admin/user_bio', 'api/admin/user_bio.php', ['op' => 'clear', 'id' => $aliceId], $panelSess);
$lines = $auditNow();
$told = (int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = " . (int)$aliceId . " AND type = 'account' AND id > " . $noteFloor)->fetchColumn();
check('Clear: the text is gone for good', !empty($j['success']) && !empty($j['cleared']) && $alice()['bio'] === null && ($j['user']['has_bio'] ?? true) === false, json_encode($j));
check('… the member is told in a notification', $told === 1, (string)$told);
check('… and one audit line says who cleared whose, with what was there',
      count($lines) === 1 && (string)$lines[0]['target_type'] === 'user' && (int)$lines[0]['target_id'] === $aliceId && (int)$lines[0]['ok'] === 1
      && str_contains((string)$lines[0]['summary'], 'pbtest_alice') && str_contains((string)$lines[0]['detail'], 'rude words'),
      json_encode($lines));
$j = $run('admin/user_bio', 'api/admin/user_bio.php', ['op' => 'clear', 'id' => $aliceId], $panelSess);
check('clearing what is already gone changes nothing and says so — no second line, no second note',
      !empty($j['success']) && ($j['cleared'] ?? true) === false && count($auditNow()) === 1
      && (int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = " . (int)$aliceId . " AND type = 'account' AND id > " . $noteFloor)->fetchColumn() === 1,
      json_encode($j));
$j = $run('admin/user_bio', 'api/admin/user_bio.php', ['op' => 'wipe', 'id' => $aliceId], $panelSess);
check('an unknown operation is refused', !empty($j['error']) && empty($j['success']), json_encode($j));
check('profileBioClear() answers whether it cleared anything', profileBioClear($db, $bobId) === false);
$fu = $src('api/admin/fetch_users.php');
check('the user list carries the rendered description for the edit modal, made by the profile\'s own function',
      str_contains($fu, "\$r['bio_html'] = \$bioSrc !== '' ? profileBioRender(\$bioSrc, \$cfg) : '';") && str_contains($fu, 'unset($r[\'avatar_sha\'], $r[\'cover_sha\'], $r[\'bio\']);'));
$ut = $src('templates/admin/users.php');
check('the edit modal has the description and a Clear with a Bootstrap icon', str_contains($ut, 'id="ue-bio"') && str_contains($ut, 'id="ue-bio-clear"><i class="bi bi-eraser"></i>'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
