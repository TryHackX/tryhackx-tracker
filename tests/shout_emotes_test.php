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

// ── schema, settings, registry ───────────────────────────────────────────────
check('schema version is at least 64', (int)($cfg['schema_version'] ?? 0) >= 64, (string)($cfg['schema_version'] ?? '?'));
check('the shout_emotes table exists', (bool)$db->query("SHOW TABLES LIKE 'shout_emotes'")->fetchColumn());
$cols = array_column($db->query("SHOW COLUMNS FROM `shout_emotes`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$want = ['id', 'code', 'name', 'mime', 'bytes', 'width', 'height', 'is_sticker', 'enabled', 'uploaded_by', 'sha1', 'data', 'created_at'];
check('… with every column the feature needs', array_diff($want, $cols) === [], implode(',', array_diff($want, $cols)));
$keys = array_unique(array_column($db->query("SHOW INDEX FROM `shout_emotes`")->fetchAll(PDO::FETCH_ASSOC), 'Key_name'));
check('… the code and the bytes are both unique, and the uploader is indexed',
      in_array('uq_emote_code', $keys, true) && in_array('uq_emote_sha1', $keys, true) && in_array('idx_emote_user', $keys, true),
      implode(',', $keys));
$defaults = trackerSchemaDefaultSettings();
foreach (['shout_emotes_enabled' => '1', 'shout_emote_max_kb' => '64', 'shout_emote_max_px' => '128',
          'shout_emote_per_user' => '20', 'shout_stickers_enabled' => '1'] as $k => $v) {
    check("the default for $k ships as '$v'", ($defaults[$k] ?? null) === $v, var_export($defaults[$k] ?? null, true));
}
check('shout.upload_emote is in the registry', isset(userPermissionList()['shout.upload_emote']));
check('… and no preset hands it out: adding pictures to a shared room is the operator\'s decision',
      !in_array('shout.upload_emote', userGroupPresets()['member']['perms'], true)
      && !in_array('shout.upload_emote', userGroupPresets()['moderator']['perms'], true));
check('… and with accounts off the legacy fallback closes it like the rest of shout.*',
      userLegacyDefault('shout.upload_emote') === false);
check('no migration grants it either',
      !preg_match('/schemaGrantOnce[^;]*shout\.upload_emote/s', (string)file_get_contents($root . '/includes/schema.php')));

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
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'shout_enabled' => '1', 'users_require_email_verify' => '0',
                            'shout_emotes_enabled' => '1', 'shout_stickers_enabled' => '1',
                            'shout_emote_max_kb' => '64', 'shout_emote_max_px' => '128', 'shout_emote_per_user' => '20',
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
      && str_contains($save, "'shout_emote_max_kb' => [8, 512, 64]")
      && str_contains($save, "'shout_emote_max_px' => [32, 512, 128]")
      && str_contains($save, "'shout_emote_per_user' => [1, 200, 20]"));
$kw = settingsCatalogKeywords();
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
$gone = [];
foreach (['shout_emotes_enabled', 'shout_emote_max_kb', 'shout_emote_max_px', 'shout_emote_per_user', 'shout_stickers_enabled'] as $k) {
    if (!isset($kw[$k]) || !str_contains($tpl, 'name="' . $k . '"')) $gone[] = $k;
}
check('every setting is on the Settings page and in the search catalogue', $gone === [], implode(', ', $gone));
check('the manager is drawn in the shoutbox section and driven by admin-shout.js',
      str_contains($tpl, 'id="admin-emotes"') && str_contains($tpl, 'id="admin-emote-drop"')
      && str_contains((string)file_get_contents($root . '/assets/js/admin-shout.js'), "admin/shout_emotes"));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
