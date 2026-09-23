<?php
/**
 * Shoutbox emotes and stickers (1.59.0, schema 64) — includes/shout.php against the migrated
 * database:
 *     php tests/shout_emotes_test.php
 *
 * The schema and the settings that ship with it, the permission that is registered and granted to
 * nobody, the clamps, the shipped examples the migration seeded, and then the part that actually
 * matters: WHAT AN SVG IS ALLOWED TO CONTAIN. A drawing with a script in it, an onload handler, a
 * javascript: address, a data:text/html address, a foreignObject or a reference to somebody else's
 * server is refused by the code that decides whether bytes are a picture at all — before the two
 * response headers that would catch it afterwards ever come into it.
 *
 * Then storing (caps, the per-person cap, the same bytes twice, the same code twice), the flags,
 * and rendering: `:code:` becomes an image in the TEXT of finished html and nowhere else, and a
 * shout that is nothing but one sticker token becomes a single big image — which is the shape
 * assets/js/shoutbox.js is written against.
 *
 * And the difference between WAITING and SWITCHED OFF, which `approved_at` is the whole of. Both
 * used to be `enabled = 0`, so an emote a moderator had deliberately taken down climbed back into
 * the approval queue and asked to be approved again — for ever. A row nobody has answered for is
 * waiting; a row somebody answered for is off; switching one off is not a question and switching it
 * back on is not a second approval. The backfill is here too: a site upgrading from 1.59.0 has no
 * queue, and its whole library must not become one overnight.
 *
 * Self-cleaning: every row it makes is removed in the finally, and no setting is written at all —
 * the functions take $cfg, so the whole run happens against an array this file builds.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/people.php';
require_once $root . '/includes/audit.php';
require_once $root . '/includes/settings_catalog.php';
require_once $root . '/includes/shout.php';

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
// ensureSchema() returns the moment schema_version already matches, so a box that recorded 65 from
// an earlier build of this release never sees a column ADDED to 65 afterwards — and every check
// below would then fail for a reason with nothing to do with the code under test. The guarded
// statement is the migration itself, asked for by name: it offers the ALTER only while the column is
// missing, which is exactly the question being asked here.
foreach (trackerSchemaGuardedStatements($db) as $q) {
    if (is_string($q) && str_contains($q, 'shout_emotes')) $db->exec($q);
}

// ── schema, settings, registry ───────────────────────────────────────────────
check('schema version is at least 65', (int)($cfg['schema_version'] ?? 0) >= 65, (string)($cfg['schema_version'] ?? '?'));
check('the shout_emotes table exists', (bool)$db->query("SHOW TABLES LIKE 'shout_emotes'")->fetchColumn());
$colRows = $db->query("SHOW COLUMNS FROM `shout_emotes`")->fetchAll(PDO::FETCH_ASSOC);
$cols = array_column($colRows, 'Field');
$want = ['id', 'code', 'name', 'mime', 'bytes', 'width', 'height', 'is_sticker', 'enabled', 'approved_at',
         'uploaded_by', 'sha1', 'data', 'created_at'];
check('… with every column the feature needs', array_diff($want, $cols) === [], implode(',', array_diff($want, $cols)));
$approvedCol = [];
foreach ($colRows as $c) if ($c['Field'] === 'approved_at') $approvedCol = $c;
// NULL is the entire point of the column — it is the only value that means "nobody has answered
// yet", so any default at all would be the database answering on somebody's behalf.
check('… and approved_at is nullable, with no default to speak for a decision nobody made',
      ($approvedCol['Null'] ?? '') === 'YES' && ($approvedCol['Default'] ?? null) === null, json_encode($approvedCol));
// The four places this codebase takes a column: both copies of the CREATE (a split between the two
// lists is what once stopped a fresh install at version 0), a guarded ALTER for an install that
// already has the table, and a one-time data migration for the rows that pre-date it.
$schemaSrc = (string)file_get_contents($root . '/includes/schema.php');
check('the column is in both CREATEs, in a guarded ALTER, and backfilled once',
      substr_count($schemaSrc, '`approved_at` DATETIME DEFAULT NULL') === 3
      && str_contains($schemaSrc, "schemaColumnExists(\$db, 'shout_emotes', 'approved_at')")
      && str_contains($schemaSrc, "schemaOnce(\$db, 'v65_emote_approved')")
      && str_contains($schemaSrc, 'UPDATE shout_emotes SET approved_at = created_at WHERE approved_at IS NULL'),
      'CREATE/ALTER copies: ' . substr_count($schemaSrc, '`approved_at` DATETIME DEFAULT NULL'));
$keys = array_unique(array_column($db->query("SHOW INDEX FROM `shout_emotes`")->fetchAll(PDO::FETCH_ASSOC), 'Key_name'));
check('… the code and the bytes are both unique, and the uploader is indexed',
      in_array('uq_emote_code', $keys, true) && in_array('uq_emote_sha1', $keys, true) && in_array('idx_emote_user', $keys, true),
      implode(',', $keys));
$defaults = trackerSchemaDefaultSettings();
foreach (['shout_emotes_enabled' => '1', 'shout_emote_max_kb' => '64', 'shout_emote_max_px' => '128',
          'shout_emote_per_user' => '20', 'shout_stickers_enabled' => '1',
          // v65: on, and it changes nothing until somebody is granted shout.upload_emote.
          'shout_emote_approval' => '1'] as $k => $v) {
    check("the default for $k ships as '$v'", ($defaults[$k] ?? null) === $v, var_export($defaults[$k] ?? null, true));
}
check('shout.upload_emote is in the registry', isset(userPermissionList()['shout.upload_emote']));
// The rule has always been "no MEMBER gets it" — adding pictures to a shared room is not part of
// writing in one. 1.65.0 does not change that; it gives the id a home. The seeded `premium` group
// carries it, which is a group an operator grants by hand or a shop sells, and every account that
// holds it holds it because somebody decided so.
check('… and neither the member nor the moderator preset hands it out',
      !in_array('shout.upload_emote', userGroupPresets()['member']['perms'], true)
      && !in_array('shout.upload_emote', userGroupPresets()['moderator']['perms'], true));
check('… the premium preset is where it lives, and it is the only one',
      in_array('shout.upload_emote', userGroupPresets()['premium']['perms'], true)
      && count(array_filter(userGroupPresets(), fn($p) => in_array('shout.upload_emote', $p['perms'], true))) === 1);
check('… and with accounts off the legacy fallback closes it like the rest of shout.*',
      userLegacyDefault('shout.upload_emote') === false);
$schemaSrc71 = (string)file_get_contents($root . '/includes/schema.php');
check('no schemaGrantOnce hands it to an existing group — that is what would give it to members',
      !preg_match('/schemaGrantOnce[^;]*shout\.upload_emote/s', $schemaSrc71));
// It arrives as a seed ROW, not as a grant onto somebody's existing group — the difference between
// "a new group has this" and "an established group just got this".
$seedRows = [];
preg_match_all('/INSERT IGNORE INTO `user_groups`.*?VALUES(.*?)"\s*\)/s', $schemaSrc71, $mSeed);
foreach ($mSeed[1] ?? [] as $stmt) if (str_contains($stmt, 'shout.upload_emote')) $seedRows[] = $stmt;
check('… it arrives only as part of the premium seed row',
      count($seedRows) === 1 && str_contains($seedRows[0], "'premium'"),
      count($seedRows) . ' seed statements mention it');
// Asked of the DATABASE, not of the source: this is the property that matters.
$emoteGrants = [];
foreach ($db->query("SELECT slug, permissions FROM user_groups")->fetchAll(PDO::FETCH_ASSOC) as $g) {
    $p = json_decode((string)$g['permissions'], true) ?: [];
    if (!empty($p['shout.upload_emote'])) $emoteGrants[] = $g['slug'];
}
check('on a migrated database no default group but premium has it',
      !in_array('member', $emoteGrants, true) && !in_array('guest', $emoteGrants, true)
      && !in_array('moderator', $emoteGrants, true) && in_array('premium', $emoteGrants, true),
      implode(',', $emoteGrants));

// ── the switches and their clamps ────────────────────────────────────────────
$on = ['users_enabled' => '1', 'shout_enabled' => '1'];
check('emotes need the room, and the room needs accounts',
      shoutEmotesEnabled($on) && !shoutEmotesEnabled(['users_enabled' => '1'])
      && !shoutEmotesEnabled($on + ['shout_emotes_enabled' => '0']));
check('a sticker is an emote first: emotes off closes stickers too',
      shoutStickersEnabled($on) && !shoutStickersEnabled($on + ['shout_emotes_enabled' => '0'])
      && !shoutStickersEnabled($on + ['shout_stickers_enabled' => '0']));
check('the three numbers are clamped on read',
      shoutEmoteMaxKb(['shout_emote_max_kb' => '99999']) === 512 && shoutEmoteMaxKb(['shout_emote_max_kb' => '0']) === 64
      && shoutEmoteMaxKb(['shout_emote_max_kb' => '1']) === 8
      && shoutEmoteMaxPx(['shout_emote_max_px' => '9999']) === 512 && shoutEmoteMaxPx(['shout_emote_max_px' => '1']) === 32
      && shoutEmotePerUser(['shout_emote_per_user' => '9999']) === 200 && shoutEmotePerUser(['shout_emote_per_user' => '0']) === 20
      && shoutEmoteMaxBytes([]) === 65536);
check('a code is two to thirty-two of [a-z0-9_]',
      shoutEmoteValidCode('ok') && shoutEmoteValidCode('thumbs_up') && shoutEmoteValidCode(str_repeat('a', 32))
      && !shoutEmoteValidCode('a') && !shoutEmoteValidCode(str_repeat('a', 33)) && !shoutEmoteValidCode('Fire')
      && !shoutEmoteValidCode('a-b') && !shoutEmoteValidCode('a b') && !shoutEmoteValidCode(':a:'));
check('a missing name falls back to the code, prettified', shoutEmotePrettyName('thumbs_up') === 'Thumbs up');
// A code the rich-text pass already owns is refused at the door: an image stored under 'fire'
// would be replaced by 🔥 before shoutRenderEmotes() ran and never show.
$svgOk = (string)file_get_contents(shoutEmoteSeedDir() . '/' . (scandir(shoutEmoteSeedDir())[2] ?? 'flame.svg'));
$resv = shoutEmoteStore($db, $on, 'fire', 'Fire', $svgOk, null, false);
check('a reserved shortcode (fire) is refused with api.emote.code_reserved',
      $resv['ok'] === false && ($resv['error'] ?? '') === 'api.emote.code_reserved', json_encode($resv));
check('… and no shipped seed collides with a built-in shortcode', array_values(array_filter(
      array_map(fn($f) => substr($f, 0, -4), array_filter(scandir(shoutEmoteSeedDir()) ?: [], fn($f) => preg_match('/\.svg$/', $f))),
      fn($c) => function_exists('richtextEmoji') && isset(richtextEmoji()[$c]))) === []);

// ── the shipped examples ─────────────────────────────────────────────────────
$seedDir = shoutEmoteSeedDir();
$files = array_values(array_filter(scandir($seedDir) ?: [], fn($f) => preg_match('/\.svg$/', $f)));
check('four to six example emotes ship under assets/emotes/', count($files) >= 4 && count($files) <= 6, implode(',', $files));
$badName = array_values(array_filter($files, fn($f) => !preg_match('/^[a-z0-9_]{2,32}\.svg$/', $f)));
check('… every file name is a usable code', $badName === [], implode(',', $badName));
$bad = [];
foreach ($files as $f) {
    $bytes = (string)file_get_contents($seedDir . '/' . $f);
    $kind = shoutEmoteSniff($bytes);
    if ($kind === null || $kind['mime'] !== 'image/svg+xml' || $kind['w'] !== 64 || $kind['h'] !== 64
        || strlen($bytes) > 4096) $bad[] = $f . ' ' . json_encode($kind) . ' ' . strlen($bytes) . 'B';
}
check('… each is a small 64×64 SVG this code is willing to serve', $bad === [], implode(' | ', $bad));
// The seed is a one-time migration on a database other suites edit: ask the migration itself
// (forget the marker, run the data migrations again — idempotent by design).
$db->exec("DELETE FROM settings WHERE `key` = 'schema_once_v64_emotes'");
trackerSchemaDataMigrations($db, getSettings($db, true));
shoutEmotesInvalidate();
$seeded = shoutEmotes($db, $on, false);
$missing = [];
foreach ($files as $f) {
    $code = substr($f, 0, -4);
    if (!isset($seeded[$code]) || $seeded[$code]['uploaded_by'] !== null || !$seeded[$code]['enabled']
        || $seeded[$code]['is_sticker']) $missing[] = $code;
}
check('the migration seeded every one of them, enabled, owned by nobody, none a sticker',
      $missing === [], implode(',', $missing));
check('… and running it twice does not duplicate them',
      (int)$db->query("SELECT COUNT(*) FROM shout_emotes WHERE uploaded_by IS NULL")->fetchColumn() === count($files),
      (string)$db->query("SELECT COUNT(*) FROM shout_emotes WHERE uploaded_by IS NULL")->fetchColumn());
$one = $seeded[substr($files[0], 0, -4)];
check('an emote URL is the endpoint plus the id plus eight characters of the sha1',
      shoutEmoteUrl($one, '/base/') === '/base/api.php?endpoint=shout_emote&id=' . $one['id'] . '&v=' . substr($one['sha1'], 0, 8),
      shoutEmoteUrl($one, '/base/'));
$client = shoutEmoteForClient($one, '/base/');
check('… and what the browser is handed carries no sha1, no uploader and no bytes',
      array_keys($client) === ['id', 'code', 'name', 'url', 'sticker', 'w', 'h'], implode(',', array_keys($client)));

// ── what an SVG may contain ──────────────────────────────────────────────────
$svg = function (string $inner = '', string $attrs = '', string $size = 'width="32" height="32" viewBox="0 0 32 32"'): string {
    return '<svg xmlns="http://www.w3.org/2000/svg" ' . $size . ' ' . $attrs . '><rect width="32" height="32" fill="#123"/>'
         . $inner . '</svg>';
};
check('a plain hand-written SVG is a picture', shoutEmoteSvgIssue($svg()) === null, (string)shoutEmoteSvgIssue($svg()));
check('… an XML declaration and a BOM in front of it change nothing',
      shoutEmoteSvgIssue("\xEF\xBB\xBF<?xml version=\"1.0\"?>\n" . $svg()) === null);
check('… and so does a <style> block, which is the one thing the policy still allows',
      shoutEmoteSvgIssue($svg('<style>rect{fill:#456}</style>')) === null);
// A LIST of pairs rather than a map: two of these reasons are checked twice, in the two shapes the
// trick actually arrives in, and a map would have kept only the second of each.
foreach ([
    ['script',         $svg('<script>alert(1)</script>'),                       'a script element'],
    ['script',         $svg('<script type="text/javascript">x</script>'),       'a script element with a type'],
    ['handler',        $svg('', 'onload="alert(1)"'),                           'onload on the svg itself'],
    ['handler',        $svg('<rect onmouseover="x()" width="1" height="1"/>'),  'a handler on a shape'],
    ['javascript',     $svg('<a href="javascript:alert(1)"><rect width="1" height="1"/></a>'), 'a javascript: address'],
    ['foreign_object', $svg('<foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject>'), 'a foreignObject'],
    ['embed',          $svg('<iframe src="about:blank"></iframe>'),             'an embedded frame'],
    ['entity',         "<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY x SYSTEM \"file:///etc/passwd\">]>" . $svg(), 'an entity declaration'],
    ['external_ref',   $svg('<image href="https://evil.example/x.png" width="4" height="4"/>'), 'an image from another server'],
    ['external_ref',   $svg('<use xlink:href="https://evil.example/x.svg#a"/>'), 'a use of another server\'s drawing'],
    ['not_svg',        'hello, this is not a drawing at all',                   'not a drawing at all'],
] as [$why, $bytes, $what]) {
    check("refused ($why): $what", shoutEmoteSvgIssue($bytes) === $why, var_export(shoutEmoteSvgIssue($bytes), true));
}
// The two shapes a literal search would miss, and the reason the check decodes entities first.
check('a scheme written as entities is the same word to this code',
      shoutEmoteSvgIssue($svg('<a href="java&#115;cript&#58;alert(1)"><rect width="1" height="1"/></a>')) === 'javascript');
check('a data:text/html reference is refused',
      shoutEmoteSvgIssue($svg('<a href="data:text/html;base64,PHNjcmlwdD4="><rect width="1" height="1"/></a>')) === 'data_html');
check('a local #fragment is NOT an external reference',
      shoutEmoteSvgIssue('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><defs><linearGradient id="g"/></defs><rect width="32" height="32" fill="url(#g)" xlink:href="#g"/></svg>') === null);
check('an attribute that merely contains the letters "on" is not a handler',
      shoutEmoteSvgIssue('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" version="1.1"><rect width="32" height="32"/></svg>') === null);
check('every refusal is a refusal of the SNIFF too, not only of the explanation',
      shoutEmoteSniff($svg('<script>x</script>')) === null && shoutEmoteSniff($svg('', 'onload="x"')) === null);

// ── sniffing, and measuring ──────────────────────────────────────────────────
$s = shoutEmoteSniff($svg());
check('an SVG is measured from its width/height', $s === ['mime' => 'image/svg+xml', 'ext' => 'svg', 'w' => 32, 'h' => 32], json_encode($s));
$s = shoutEmoteSniff($svg('', '', 'viewBox="0 0 48 24"'));
check('… or, failing that, from its viewBox', $s !== null && $s['w'] === 48 && $s['h'] === 24, json_encode($s));
$s = shoutEmoteSniff($svg('', '', 'width="100%" height="100%" viewBox="0 0 40 40"'));
check('… and a percentage is not a size', $s !== null && $s['w'] === 40 && $s['h'] === 40, json_encode($s));
$png1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
check('a real PNG is recognised and measured',
      shoutEmoteSniff($png1) === ['mime' => 'image/png', 'ext' => 'png', 'w' => 1, 'h' => 1], json_encode(shoutEmoteSniff($png1)));
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
check('a real GIF is too', (shoutEmoteSniff($gif)['mime'] ?? '') === 'image/gif', json_encode(shoutEmoteSniff($gif)));
check('a PNG header in front of rubbish is not a PNG', shoutEmoteSniff("\x89PNG\r\n\x1a\n" . str_repeat("\0", 400)) === null);
check('a GIF header in front of rubbish is not a GIF', shoutEmoteSniff('GIF89a' . str_repeat("\0", 400)) === null);
check('a RIFF that is not WEBP is nothing', shoutEmoteSniff('RIFF' . pack('V', 100) . 'WAVE' . str_repeat("\0", 100)) === null);
check('text is not a picture', shoutEmoteSniff(str_repeat('hello world ', 40)) === null);
check('the extension is chosen here, never taken from a file name',
      shoutEmoteExt('image/svg+xml') === 'svg' && shoutEmoteExt('image/png') === 'png'
      && shoutEmoteExt('image/gif') === 'gif' && shoutEmoteExt('image/webp') === 'webp' && shoutEmoteExt('text/html') === 'bin');

// ── storing ──────────────────────────────────────────────────────────────────
$names = ['emtuser', 'emtother'];
$in = implode(',', array_fill(0, count($names), '?'));
$db->prepare("DELETE FROM users WHERE username IN ($in)")->execute($names);
// The approval gate is OFF for the body of this suite: every check below is about what an upload IS,
// and a row that lands switched off because it is waiting for somebody would answer a different
// question. The gate has a section of its own further down, where it is switched on deliberately.
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'shout_enabled' => '1', 'users_require_email_verify' => '0',
                            'shout_emotes_enabled' => '1', 'shout_stickers_enabled' => '1',
                            'shout_emote_max_kb' => '64', 'shout_emote_max_px' => '128', 'shout_emote_per_user' => '20',
                            'shout_emote_approval' => '0',
                            'shout_max_chars' => '500', 'shout_flood_seconds' => '0', 'shout_format' => 'bbcode',
                            'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1']);
$uid = [];
foreach ($names as $name) {
    userCreate($db, $cfgOn, $name, $name . '@example.org', 'SmokePass123!', '127.0.0.1');
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    $uid[$name] = (int)($st->fetchColumn() ?: 0);
}
$db->prepare("UPDATE users SET email_verified = 1 WHERE username IN ($in)")->execute($names);
check('the two test accounts exist', count(array_filter($uid)) === 2, json_encode($uid));

$cleanup = function () use ($db, $uid, $in, $names) {
    $db->exec("DELETE FROM shout_emotes WHERE code LIKE 'zz%'");
    $ids = array_values($uid);
    $q = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE FROM shout_emotes WHERE uploaded_by IN ($q)")->execute($ids);
    $db->prepare("DELETE m FROM shout_mentions m JOIN shouts s ON s.id = m.shout_id WHERE s.user_id IN ($q)")->execute($ids);
    $db->prepare("DELETE FROM shouts WHERE user_id IN ($q)")->execute($ids);
    $db->prepare("DELETE FROM users WHERE username IN ($in)")->execute($names);
    shoutEmotesInvalidate();
};

try {
    $db->exec("DELETE FROM shout_emotes WHERE code LIKE 'zz%'");
    shoutEmotesInvalidate();
    $me = $uid['emtuser'];
    $r = shoutEmoteStore($db, $cfgOn, 'zzfire', '  My  fire  ', $svg('<title>zzfire</title>'), $me, false);
    check('a member\'s upload is stored, its name tidied, its type the sniffed one',
          !empty($r['ok']) && $r['row']['name'] === 'My fire' && $r['row']['mime'] === 'image/svg+xml'
          && $r['row']['width'] === 32 && $r['row']['uploaded_by'] === $me && $r['row']['enabled'] === true, json_encode($r));
    $firstId = (int)$r['row']['id'];
    check('… and an empty name falls back to the code',
          (shoutEmoteStore($db, $cfgOn, 'zzwave', '   ', $svg('<title>zzwave</title>'), $me, false)['row']['name'] ?? '') === 'Zzwave');
    $r = shoutEmoteStore($db, $cfgOn, 'ZZ-Bad!', 'x', $svg('<title>b</title>'), $me, false);
    check('a code that is not a code is refused before anything else happens',
          ($r['error'] ?? '') === 'api.emote.bad_code' && ($r['status'] ?? 0) === 400, json_encode($r));
    $r = shoutEmoteStore($db, $cfgOn, 'zzdupe', 'dupe', $svg('<title>zzfire</title>'), $me, false);
    check('the same bytes twice are one picture, and the answer names the code that has them',
          ($r['error'] ?? '') === 'api.emote.duplicate' && ($r['status'] ?? 0) === 409 && ($r['detail'] ?? '') === 'zzfire', json_encode($r));
    $r = shoutEmoteStore($db, $cfgOn, 'zzfire', 'again', $svg('<title>different</title>'), $me, false);
    check('the same code twice is refused', ($r['error'] ?? '') === 'api.emote.code_taken' && ($r['status'] ?? 0) === 409, json_encode($r));
    $r = shoutEmoteStore($db, $cfgOn, 'zztiny', 'tiny', 'x', $me, false);
    check('a handful of bytes is not a picture', ($r['error'] ?? '') === 'api.emote.too_small', json_encode($r));
    $r = shoutEmoteStore($db, array_merge($cfgOn, ['shout_emote_max_kb' => '8']), 'zzbig', 'big',
                         $svg('<title>' . str_repeat('x', 9000) . '</title>'), $me, false);
    check('over the size cap is 413, with the number it was judged against',
          ($r['error'] ?? '') === 'api.emote.too_large' && ($r['status'] ?? 0) === 413 && ($r['limit'] ?? 0) === 8, json_encode($r));
    $r = shoutEmoteStore($db, $cfgOn, 'zzwide', 'wide', $svg('<title>w</title>', '', 'width="999" height="999" viewBox="0 0 999 999"'), $me, false);
    check('bigger than shout_emote_max_px is refused, with the limit and the size it was',
          ($r['error'] ?? '') === 'api.emote.too_big_px' && ($r['limit'] ?? 0) === 128 && ($r['detail'] ?? '') === '999x999', json_encode($r));
    $r = shoutEmoteStore($db, $cfgOn, 'zzevil', 'evil', $svg('<script>alert(1)</script>'), $me, false);
    check('an SVG with a script is refused as UNSAFE, not merely as "not an image"',
          ($r['error'] ?? '') === 'api.emote.unsafe_svg' && ($r['detail'] ?? '') === 'script', json_encode($r));
    $r = shoutEmoteStore($db, $cfgOn, 'zzjunk', 'junk', str_repeat('nonsense ', 20), $me, false);
    check('… while something that is not a drawing at all says so', ($r['error'] ?? '') === 'api.emote.not_image', json_encode($r));
    $r = shoutEmoteStore($db, $cfgOn, 'zzpng', 'png', $png1, $me, true);
    check('a PNG sticker is stored with its real size and the sticker flag',
          !empty($r['ok']) && $r['row']['mime'] === 'image/png' && $r['row']['width'] === 1 && $r['row']['is_sticker'] === true, json_encode($r));

    // The per-person cap, and who it is about.
    $capCfg = array_merge($cfgOn, ['shout_emote_per_user' => '1']);
    $r = shoutEmoteStore($db, $capCfg, 'zzover', 'over', $svg('<title>over</title>'), $uid['emtother'], false);
    check('the first one under a cap of one goes in', !empty($r['ok']), json_encode($r));
    $r = shoutEmoteStore($db, $capCfg, 'zzover2', 'over2', $svg('<title>over2</title>'), $uid['emtother'], false);
    check('… the second is refused, and the answer says how many that account may have',
          ($r['error'] ?? '') === 'api.emote.too_many' && ($r['status'] ?? 0) === 409 && ($r['limit'] ?? 0) === 1, json_encode($r));
    $r = shoutEmoteStore($db, $capCfg, 'zzsite', 'site', $svg('<title>site</title>'), null, false);
    check('… and the panel is not an account, so the cap is not about it',
          !empty($r['ok']) && $r['row']['uploaded_by'] === null, json_encode($r));
    $siteId = (int)$r['row']['id'];
    check('the count is per account', shoutEmoteCountFor($db, $uid['emtother']) === 1 && shoutEmoteCountFor($db, $me) === 3,
          shoutEmoteCountFor($db, $me) . '/' . shoutEmoteCountFor($db, $uid['emtother']));

    // ── the flags ────────────────────────────────────────────────────────────
    check('an emote can be switched off', shoutEmoteSetFlag($db, $firstId, 'enabled', false));
    check('… and the reader\'s view stops listing it', !isset(shoutEmotes($db, $cfgOn)['zzfire']));
    check('… while the manager\'s view still does', isset(shoutEmotes($db, $cfgOn, false)['zzfire']));
    check('… and back on again', shoutEmoteSetFlag($db, $firstId, 'enabled', true) && isset(shoutEmotes($db, $cfgOn)['zzfire']));
    check('a sticker flag can be set and cleared',
          shoutEmoteSetFlag($db, $firstId, 'is_sticker', true) && shoutEmotes($db, $cfgOn)['zzfire']['is_sticker'] === true
          && shoutEmoteSetFlag($db, $firstId, 'is_sticker', false) && shoutEmotes($db, $cfgOn)['zzfire']['is_sticker'] === false);
    check('no other column can be reached through that door', shoutEmoteSetFlag($db, $firstId, 'code', true) === false);
    check('the whole list is nothing while the feature is off',
          shoutEmotes($db, array_merge($cfgOn, ['shout_emotes_enabled' => '0'])) === []);

    // ── the NAME, which nothing used to check at all (1.59.1) ────────────────
    // The sounds library's rule, for the reason that file gives: two emotes called "Wave" are two
    // identical lines in the manager and on the Emotes page, and whoever picks one is guessing.
    check('a name that is nothing after trimming is refused',
          shoutEmoteNameProblem($db, '   ') === 'api.emote.name_required'
          && shoutEmoteNameProblem($db, "\t\n") === 'api.emote.name_required');
    check('… one character is not a name', shoutEmoteNameProblem($db, ' x ') === 'api.emote.name_short');
    check('… two is', shoutEmoteNameProblem($db, 'ok') === null);
    // The upper bound is not an error: shoutEmoteCleanName() has already cut it to 60.
    check('… and a name longer than the column is CUT rather than refused',
          shoutEmoteNameProblem($db, str_repeat('a', 200)) === null
          && mb_strlen(shoutEmoteCleanName(str_repeat('a', 200))) === 60);
    check('a name another emote already answers to is taken, whatever its case',
          shoutEmoteNameProblem($db, 'My fire') === 'api.emote.name_taken'
          && shoutEmoteNameProblem($db, '  mY   FIRE  ') === 'api.emote.name_taken',
          (string)shoutEmoteNameProblem($db, 'mY FIRE'));
    check('… but a name is never taken by the row that already has it',
          shoutEmoteNameProblem($db, 'My fire', $firstId) === null);
    $r = shoutEmoteStore($db, $cfgOn, 'zztaken', 'my fire', $svg('<title>taken</title>'), $me, false);
    check('storing under a taken name is refused, and says which name it was',
          ($r['error'] ?? '') === 'api.emote.name_taken' && ($r['status'] ?? 0) === 409
          && ($r['detail'] ?? '') === 'my fire', json_encode($r));
    // The fallback is a name like any other: a second "Zzpretty" arrived at by nobody typing
    // anything is the same collision as one arrived at deliberately.
    check('an empty name still falls back to the prettified code',
          (shoutEmoteStore($db, $cfgOn, 'zzpretty', '', $svg('<title>pretty</title>'), $me, false)['row']['name'] ?? '') === 'Zzpretty');
    // `zz_pretty` prettifies to exactly the name the row below is given, which is the collision a
    // fallback walks into without anybody having typed a name at all.
    shoutEmoteStore($db, $cfgOn, 'zzclash', 'Zz pretty', $svg('<title>clash</title>'), $me, false);
    $r = shoutEmoteStore($db, $cfgOn, 'zz_pretty', '  ', $svg('<title>pretty2</title>'), $me, false);
    check('… and when that fallback collides it is refused rather than stored twice',
          ($r['error'] ?? '') === 'api.emote.name_taken' && ($r['detail'] ?? '') === 'Zz pretty', json_encode($r));

    // ── renaming: the NAME moves, the code never does ────────────────────────
    $r = shoutEmoteRename($db, $firstId, '  Renamed   fire  ');
    check('a rename tidies the new name and leaves the code alone',
          !empty($r['ok']) && ($r['row']['name'] ?? '') === 'Renamed fire' && ($r['row']['code'] ?? '') === 'zzfire',
          json_encode($r));
    check('… and the table says so', (shoutEmotes($db, $cfgOn, false)['zzfire']['name'] ?? '') === 'Renamed fire');
    check('renaming to a name somebody else has is refused',
          (shoutEmoteRename($db, $firstId, 'Zzpretty')['error'] ?? '') === 'api.emote.name_taken');
    check('… renaming a row to the name it already has is not a collision',
          !empty(shoutEmoteRename($db, $firstId, 'Renamed fire')['ok']));
    check('… one character is still not a name', (shoutEmoteRename($db, $firstId, 'x')['error'] ?? '') === 'api.emote.name_short');
    $r = shoutEmoteRename($db, $firstId, '');
    check('… and an empty box means the prettified code, which is a way back rather than a refusal',
          !empty($r['ok']) && ($r['row']['name'] ?? '') === 'Zzfire', json_encode($r));
    check('renaming something that is not there is not found',
          (shoutEmoteRename($db, 999999999, 'Whatever')['error'] ?? '') === 'api.emote.unknown');
    // And back to the name the rendering checks below are written against. A test that quietly
    // changes what a later one is measuring is worse than no test at all.
    check('… and the name goes back where the rest of this file expects it',
          !empty(shoutEmoteRename($db, $firstId, 'My fire')['ok']));

    // ── the approval gate (v65) ──────────────────────────────────────────────
    check('the gate is on unless it is switched off',
          shoutEmoteApproval([]) === true && shoutEmoteApproval(['shout_emote_approval' => '1']) === true
          && shoutEmoteApproval(['shout_emote_approval' => '0']) === false);
    $gateOn = array_merge($cfgOn, ['shout_emote_approval' => '1']);
    $r = shoutEmoteStore($db, $gateOn, 'zzhold', 'Zz hold', $svg('<title>hold</title>'), $me, false);
    check('with the gate on, a MEMBER\'s upload lands switched off and says it is waiting',
          !empty($r['ok']) && $r['row']['enabled'] === false && !empty($r['pending'])
          && $r['row']['approved_at'] === null && !empty($r['row']['waiting']), json_encode($r));
    $holdId = (int)$r['row']['id'];
    shoutEmotesInvalidate();
    // array_key_exists, not ??: the value under test IS null, and `?? something` cannot tell that
    // apart from a key nobody wrote — which would have made this check pass on a missing column.
    $held = shoutEmotes($db, $gateOn, false)['zzhold'] ?? [];
    check('… and the ROW says it, rather than the setting being asked about it later',
          ($held['waiting'] ?? false) === true
          && array_key_exists('approved_at', $held) && $held['approved_at'] === null, json_encode($held));
    check('… so the room does not have it: not in the reader\'s list, not rendered in a line',
          !isset(shoutEmotes($db, $gateOn)['zzhold'])
          && shoutRenderEmotes('hi :zzhold:', shoutEmotes($db, $gateOn), '') === 'hi :zzhold:');
    check('… while the manager, who has to be able to approve it, still sees it',
          isset(shoutEmotes($db, $gateOn, false)['zzhold']));
    // Switching the gate off afterwards does not answer the question for anybody: somebody is still
    // waiting to hear about this picture, and the only thing that can say so is the row.
    check('… and it is still waiting even if the gate is switched off afterwards',
          (shoutEmotes($db, $cfgOn, false)['zzhold']['waiting'] ?? false) === true);
    $r = shoutEmoteStore($db, $gateOn, 'zzsitenow', 'Zz site now', $svg('<title>sitenow</title>'), null, false);
    check('… and the panel\'s own never waits: the person who would approve it just added it',
          !empty($r['ok']) && $r['row']['enabled'] === true && empty($r['pending'])
          && $r['row']['approved_at'] !== null && empty($r['row']['waiting']), json_encode($r));

    // Approving, and then the thing the column exists for: taking that same emote down again.
    check('approving switches it on and stamps the decision',
          shoutEmoteApprove($db, $holdId) && isset(shoutEmotes($db, $gateOn)['zzhold']));
    $stamp = shoutEmotes($db, $gateOn, false)['zzhold']['approved_at'] ?? null;
    check('… with a real moment rather than a flag',
          is_string($stamp) && $stamp !== '' && abs(time() - (int)strtotime($stamp)) < 600, var_export($stamp, true));
    check('… so nobody is waiting on it any more',
          (shoutEmotes($db, $gateOn, false)['zzhold']['waiting'] ?? true) === false);
    // The 1.59.1 defect, in one check: "switched off by a moderator" and "waiting for a moderator"
    // were the same row, so a picture somebody had deliberately taken down asked to be approved
    // again every time the manager was opened.
    check('a moderator switching an approved emote off leaves the decision standing',
          shoutEmoteSetFlag($db, $holdId, 'enabled', false));
    $backOff = shoutEmotes($db, $gateOn, false)['zzhold'] ?? [];
    check('… so it is off, it is not waiting, and it does not climb back into the queue',
          ($backOff['enabled'] ?? true) === false && ($backOff['waiting'] ?? true) === false
          && ($backOff['approved_at'] ?? null) === $stamp, json_encode($backOff));
    check('… and switching it back on is not a second approval: the date does not move',
          shoutEmoteApprove($db, $holdId)
          && (shoutEmotes($db, $gateOn, false)['zzhold']['approved_at'] ?? '') === $stamp);
    check('nothing but approving may write that column',
          shoutEmoteSetFlag($db, $holdId, 'approved_at', true) === false
          && (shoutEmotes($db, $gateOn, false)['zzhold']['approved_at'] ?? '') === $stamp);
    $r = shoutEmoteStore($db, $cfgOn, 'zzfree', 'Zz free', $svg('<title>free</title>'), $me, false);
    check('with the gate OFF a member\'s upload is everybody\'s at once, exactly as in 1.59.0',
          !empty($r['ok']) && $r['row']['enabled'] === true && empty($r['pending'])
          && $r['row']['approved_at'] !== null && empty($r['row']['waiting']), json_encode($r));

    // ── the backfill: a library that pre-dates the column is not a queue ─────
    // A 1.59.0 row, simulated the only honest way there is: stored before anything remembered a
    // decision, and switched off — because THAT is the row the defect was really about. An operator
    // who had already taken a picture down must not be asked about it again after an upgrade.
    $r = shoutEmoteStore($db, $cfgOn, 'zzlegacy', 'Zz legacy', $svg('<title>legacy</title>'), $me, false);
    $legacyId = (int)$r['row']['id'];
    shoutEmoteSetFlag($db, $legacyId, 'enabled', false);
    $db->prepare("UPDATE shout_emotes SET approved_at = NULL WHERE id = ?")->execute([$legacyId]);
    shoutEmotesInvalidate();
    check('a row with no stamp reads as waiting, whatever it is switched to',
          (shoutEmotes($db, $cfgOn, false)['zzlegacy']['waiting'] ?? false) === true);
    // The migration itself, asked rather than trusted: forget the marker (schemaOnce burns one) and
    // run the data migrations again, exactly as the seed check above does.
    $db->exec("DELETE FROM settings WHERE `key` = 'schema_once_v65_emote_approved'");
    trackerSchemaDataMigrations($db, getSettings($db, true));
    shoutEmotesInvalidate();
    $legacy = shoutEmotes($db, $cfgOn, false)['zzlegacy'] ?? [];
    check('the migration stamps it with the day it arrived, not the day of the upgrade',
          ($legacy['approved_at'] ?? null) === ($legacy['created_at'] ?? ''),
          json_encode([$legacy['approved_at'] ?? null, $legacy['created_at'] ?? null]));
    check('… so a picture somebody had already switched off is off, and not a question',
          ($legacy['enabled'] ?? true) === false && ($legacy['waiting'] ?? true) === false, json_encode($legacy));
    check('… and every other row it found is answered for as well',
          (int)$db->query("SELECT COUNT(*) FROM shout_emotes WHERE approved_at IS NULL")->fetchColumn() === 0,
          (string)$db->query("SELECT COUNT(*) FROM shout_emotes WHERE approved_at IS NULL")->fetchColumn());

    // ── rendering ────────────────────────────────────────────────────────────
    shoutEmotesInvalidate();
    $em = shoutEmotes($db, $cfgOn);
    // The '&' between the query parameters is escaped in the attribute, as it must be: this is
    // markup being written, not a URL being printed.
    $fireUrl = htmlspecialchars(shoutEmoteUrl($em['zzfire'], ''), ENT_QUOTES, 'UTF-8');
    $out = shoutRenderEmotes('hello :zzfire: world', $em, '');
    check('a token in the text becomes an image with the code as its alt and the name as its title',
          str_contains($out, '<img class="shout-emote"') && str_contains($out, 'alt=":zzfire:"')
          && str_contains($out, 'title="My fire"') && str_contains($out, 'src="' . $fireUrl . '"')
          && str_contains($fireUrl, '&amp;id=')
          && str_starts_with($out, 'hello ') && str_ends_with($out, ' world'), $out);
    check('… carrying its own size, so nothing on the page jumps while it loads',
          str_contains($out, 'width="32" height="32"') && str_contains($out, 'loading="lazy"'), $out);
    $out = shoutRenderEmotes('<a href="https://x.example/:zzfire:">:zzfire:</a>', $em, '');
    check('a token INSIDE a tag is an address, not a token: only the text is replaced',
          substr_count($out, '<img') === 1 && str_contains($out, 'href="https://x.example/:zzfire:"'), $out);
    check('a code nobody has is left exactly as it was typed',
          shoutRenderEmotes('what about :nosuchthing: then', $em, '') === 'what about :nosuchthing: then');
    check('a clock is not an emote', shoutRenderEmotes('at 12:30:45 sharp', $em, '') === 'at 12:30:45 sharp');
    check('two tokens side by side are two images', substr_count(shoutRenderEmotes(':zzfire::zzwave:', $em, ''), '<img') === 2);
    check('nothing at all happens when there are no emotes', shoutRenderEmotes('hi :zzfire:', [], '') === 'hi :zzfire:');

    // The sticker rule, which the browser script is written against.
    $out = shoutRenderEmotes(':zzpng:', $em, '');
    check('a shout that is nothing but one sticker token IS the sticker, and nothing else',
          $out === shoutEmoteImg($em['zzpng'], '', true) && str_contains($out, 'class="shout-sticker"') && substr_count($out, '<img') === 1, $out);
    check('… even wrapped in the paragraph markdown would put round it',
          str_contains(shoutRenderEmotes('<p>:zzpng:</p>', $em, ''), 'class="shout-sticker"'));
    check('… but a sticker with something beside it is an ordinary inline emote',
          str_contains(shoutRenderEmotes('look :zzpng:', $em, ''), 'class="shout-emote"'));
    check('… and with stickers switched off it is inline everywhere',
          str_contains(shoutRenderEmotes(':zzpng:', $em, '', false), 'class="shout-emote"'));
    check('a token for an emote that is not a sticker stays inline on its own',
          str_contains(shoutRenderEmotes(':zzfire:', $em, ''), 'class="shout-emote"'));

    // ── and through the room itself ──────────────────────────────────────────
    $db->prepare("INSERT INTO shouts (user_id, body, body_format) VALUES (?, ?, 'plain')")->execute([$me, 'hi :zzfire: there']);
    $rowId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO shouts (user_id, body, body_format) VALUES (?, ?, 'plain')")->execute([$me, ' :zzpng: ']);
    $stickerId = (int)$db->lastInsertId();
    $rows = shoutRows($db, $cfgOn, ['id' => $me, 'username' => 'emtuser'], 50, $rowId - 1);
    $byId = [];
    foreach ($rows as $one) $byId[$one['id']] = $one;
    check('a line read out of the room carries the image, not the token',
          str_contains((string)($byId[$rowId]['html'] ?? ''), 'class="shout-emote"')
          && !str_contains((string)($byId[$rowId]['html'] ?? ''), ':zzfire: there'), (string)($byId[$rowId]['html'] ?? ''));
    check('… and a sticker on its own comes back as one big image',
          ($byId[$stickerId]['html'] ?? '') === shoutEmoteImg($em['zzpng'], function_exists('getBaseUrl') ? getBaseUrl() : '', true),
          (string)($byId[$stickerId]['html'] ?? ''));
    $off = shoutRows($db, array_merge($cfgOn, ['shout_emotes_enabled' => '0']), ['id' => $me], 50, $rowId - 1);
    $offById = [];
    foreach ($off as $one) $offById[$one['id']] = $one;
    check('with emotes switched off the same line is text again',
          str_contains((string)($offById[$rowId]['html'] ?? ''), ':zzfire:')
          && !str_contains((string)($offById[$rowId]['html'] ?? ''), '<img'), (string)($offById[$rowId]['html'] ?? ''));

    // ── removing one ─────────────────────────────────────────────────────────
    $r = shoutEmoteDelete($db, $firstId, $uid['emtother']);
    check('somebody else\'s upload is not yours to remove',
          ($r['error'] ?? '') === 'api.emote.not_yours' && ($r['status'] ?? 0) === 403, json_encode($r));
    $r = shoutEmoteDelete($db, $siteId, $me);
    check('… and neither is the site\'s own, which belongs to nobody',
          ($r['error'] ?? '') === 'api.emote.not_yours', json_encode($r));
    $r = shoutEmoteDelete($db, $firstId, $me);
    check('your own goes', !empty($r['ok']) && ($r['row']['code'] ?? '') === 'zzfire', json_encode($r));
    check('… and is gone from the list', !isset(shoutEmotes($db, $cfgOn, false)['zzfire']));
    check('deleting it twice is not found', (shoutEmoteDelete($db, $firstId, $me)['error'] ?? '') === 'api.emote.unknown');
    check('an id that never existed is not found', (shoutEmoteDelete($db, 999999999, null)['error'] ?? '') === 'api.emote.unknown');
    check('the panel passes no owner and removes anything', !empty(shoutEmoteDelete($db, $siteId, null)['ok']));
} finally {
    $cleanup();
}

// ── the wiring, by reading it ────────────────────────────────────────────────
$api = (string)file_get_contents($root . '/api.php');
check('the five endpoints are routed',
      str_contains($api, "'shout_emote'") && str_contains($api, "'shout_emotes'")
      && str_contains($api, "'shout_emote_upload'") && str_contains($api, "'shout_emote_delete'")
      && str_contains($api, "'admin/shout_emotes'"));
check('admin/shout_emotes is owner-only (not in the permission map)',
      !preg_match("/'admin\\/shout_emotes'\\s*=>\\s*'panel\\./", $api));
check('the picture stream is skipped by the janitors, like the other polled/streamed ones',
      (bool)preg_match("/in_array\\(\\\$endpoint, \\[[^\\]]*'shout_emote'/s", $api));
$stream = (string)file_get_contents($root . '/api/shout_emote.php');
check('the stream sends the sniffed type, nosniff, and a policy that forbids everything',
      str_contains($stream, "header('Content-Type: ' . \$row['mime'])")
      && str_contains($stream, "X-Content-Type-Options: nosniff")
      && str_contains($stream, "default-src 'none'; style-src 'unsafe-inline'"), 'api/shout_emote.php');
check('… and it lets go of the session and answers 304 to a browser that already has the bytes',
      str_contains($stream, 'session_write_close()') && str_contains($stream, 'HTTP_IF_NONE_MATCH')
      && str_contains($stream, '304'));
$upload = (string)file_get_contents($root . '/api/shout_emote_upload.php');
check('the member upload checks CSRF, the permission and the size before it decodes anything',
      str_contains($upload, 'verifyCsrfToken') && str_contains($upload, "'shout.upload_emote'")
      && strpos($upload, 'strlen($b64)') < strpos($upload, 'base64_decode'));
$save = (string)file_get_contents($root . '/api/admin/save_settings.php');
check('every emote setting is saveable and clamped',
      str_contains($save, "'shout_emotes_enabled'") && str_contains($save, "'shout_stickers_enabled'")
      && str_contains($save, "'shout_emote_approval'")
      && str_contains($save, "'shout_emote_max_kb' => [8, 512, 64]")
      && str_contains($save, "'shout_emote_max_px' => [32, 512, 128]")
      && str_contains($save, "'shout_emote_per_user' => [1, 200, 20]"));
$kw = settingsCatalogKeywords();
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
$gone = [];
foreach (['shout_emotes_enabled', 'shout_emote_max_kb', 'shout_emote_max_px', 'shout_emote_per_user',
          'shout_stickers_enabled', 'shout_emote_approval'] as $k) {
    if (!isset($kw[$k]) || !str_contains($tpl, 'name="' . $k . '"')) $gone[] = $k;
}
check('every setting is on the Settings page and in the search catalogue', $gone === [], implode(', ', $gone));
$adminJs = (string)file_get_contents($root . '/assets/js/admin-shout.js');
check('the manager is drawn in the shoutbox section and driven by admin-shout.js',
      str_contains($tpl, 'id="admin-emotes"') && str_contains($tpl, 'id="admin-emote-drop"')
      && str_contains($adminJs, "admin/shout_emotes"));
// 1.59.1: the shape the owner asked for, checked by reading it rather than by trusting the diff.
check('… as a TABLE with headings and a count, not a list',
      str_contains($tpl, 'id="admin-emotes-count"') && str_contains($adminJs, "el('thead'")
      && str_contains($adminJs, 'js.shoutadmin.col_code') && str_contains($adminJs, 'js.shoutadmin.count'));
check('… the buttons say what they do and the row says what it is',
      str_contains($adminJs, 'js.shoutadmin.emote_make_sticker') && str_contains($adminJs, 'js.shoutadmin.emote_rename')
      && str_contains($adminJs, 'js.shoutadmin.emote_state_on') && str_contains($adminJs, 'js.shoutadmin.emote_kind_sticker')
      && !str_contains($adminJs, 'js.shoutadmin.emote_inline'));
check('… and the waiting queue is drawn above it, with Approve',
      str_contains($tpl, 'id="admin-emotes-waiting"') && str_contains($adminJs, 'js.shoutadmin.emote_approve'));
$adminApi = (string)file_get_contents($root . '/api/admin/shout_emotes.php');
check('the manager can rename, and approving is audited',
      str_contains($adminApi, "\$op === 'rename'") && str_contains($adminApi, 'shoutEmoteRename')
      && str_contains($adminApi, "'shout.emote_approve'"));
// The selection, by reading it: a queue built on `enabled` is the defect, whatever it is called.
check('the queue asks whether anybody has approved, never whether the row is switched on',
      str_contains($adminApi, "'pending' => !empty(\$e['waiting'])")
      && !str_contains($adminApi, "\$e['enabled'] === false && \$e['uploaded_by']")
      && str_contains($adminApi, 'shoutEmoteApprove($db, $id)'));
check('… and the browser draws it from the row rather than from the setting',
      str_contains($adminJs, 'rows.filter((r) => r.pending)') && !str_contains($adminJs, 'approval ? rows.filter'));
// 1.59.1, the one-line tidy: two policies on one response is safe and untidy, so the page's own
// (and the report-only one, which is a different header name and would have survived) come off first.
check('the picture stream sends exactly ONE Content-Security-Policy',
      str_contains($stream, "header_remove('Content-Security-Policy')")
      && str_contains($stream, "header_remove('Content-Security-Policy-Report-Only')")
      && strpos($stream, "header_remove('Content-Security-Policy')") < strpos($stream, 'header("Content-Security-Policy:'));
// The widget and the page it links to, also by reading them: a rule that is in the stylesheet and
// nowhere in the markup styles nothing.
$widget = (string)file_get_contents($root . '/templates/partials/shoutbox_widget.php');
$css = (string)file_get_contents($root . '/assets/css/style.css');
check('the widget has a refresh button and the formatting rail beside the format select',
      str_contains($widget, 'id="shout-refresh"') && str_contains($widget, 'id="shout-body-tools"')
      && str_contains($widget, 'data-md="bold"') && str_contains($widget, 'data-md="spoiler"'));
check('… driven by the poll\'s own fetch rather than a second one',
      str_contains((string)file_get_contents($root . '/assets/js/shoutbox.js'), 'function fetchNew')
      && substr_count((string)file_get_contents($root . '/assets/js/shoutbox.js'), "get('shout_list&after=") === 1);
check('a link inside a shout keeps the link colour, and the site-wide rule is untouched',
      str_contains($css, '.shout-body a:visited') && str_contains($css, 'a:visited { color: var(--link-visited); }'));
check('the rows carry a dashed rule and the last one does not',
      str_contains($css, 'border-bottom: 1px dashed') && str_contains($css, '.shout-row:last-child { border-bottom: 0; }'));
$page = (string)file_get_contents($root . '/templates/pages/emotes.php');
check('the Emotes page offers the code as something to click, and no longer advertises :fire:',
      str_contains($page, 'data-emote-copy') && !str_contains($page, ':fire:')
      && !str_contains(__('shout.emotes_intro'), ':fire:'), __('shout.emotes_intro'));
check('… and the uploader\'s own card asks the same question the manager\'s queue does',
      str_contains($page, "\$emWait = !empty(\$e['waiting'])") && str_contains($page, 'if ($emWait):')
      && !str_contains($page, 'if ($emOff && $emApproval):'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
