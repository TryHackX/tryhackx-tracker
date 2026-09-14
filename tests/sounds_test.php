<?php
/**
 * Sounds (1.56.0, schema 61) — includes/sounds.php against the migrated database:
 *     php tests/sounds_test.php
 *
 * The schema and the grant (asked of the migration itself, not of what the previous suite left),
 * the shipped library, sniffing real and fake files, the caps, storing and deleting an upload, the
 * clamps on a reader's preferences, how an event resolves, and the config a page is handed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/sounds.php';
require_once $root . '/includes/people.php';   // pmUnreadCount / pmUnreadCountFriends
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
$wasDefault = ['notification' => (string)($cfg['sound_default_notification'] ?? ''), 'message' => (string)($cfg['sound_default_message'] ?? '')];

// ── schema, registry, grant ──────────────────────────────────────────────────
check('schema version is at least 61', (int)($cfg['schema_version'] ?? 0) >= 61, (string)($cfg['schema_version'] ?? '?'));
check('the sounds table exists', (bool)$db->query("SHOW TABLES LIKE 'sounds'")->fetchColumn());
check('users.sound_prefs exists', schemaColumnExists($db, 'users', 'sound_prefs'));
foreach (['sounds_enabled' => '1', 'sound_default_notification' => '', 'sound_default_message' => ''] as $k => $v) {
    check("the default for $k ships as '$v'", (trackerSchemaDefaultSettings()[$k] ?? null) === $v);
}
check('sounds.use is in the registry', isset(userPermissionList()['sounds.use']));
check('the member preset carries it', in_array('sounds.use', userGroupPresets()['member']['perms'], true));
// The grant is a one-time migration on a shared database other suites edit: ask the migration
// itself (forget the marker, run the data migrations again — idempotent by design).
$db->exec("DELETE FROM settings WHERE `key` = 'schema_grant_v61_sounds'");
trackerSchemaDataMigrations($db, getSettings($db, true));
$cfg = getSettings($db, true);
check('the migration records its grant', isset($cfg['schema_grant_v61_sounds']));
$mp = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn(), true) ?: [];
check('… and the member group has it', ($mp['sounds.use'] ?? null) === true, json_encode($mp));
$gp = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'guest'")->fetchColumn(), true) ?: [];
check('… and the guest group does not', empty($gp['sounds.use']));
check('with accounts off the legacy fallback closes it (no account, nowhere to keep a preference)', userLegacyDefault('sounds.use') === false);
check('the feature needs accounts and its own switch',
      !soundsEnabled(['users_enabled' => '0', 'sounds_enabled' => '1']) && soundsEnabled(['users_enabled' => '1'])
      && !soundsEnabled(['users_enabled' => '1', 'sounds_enabled' => '0']));
check('three events: a notification, a message from a friend, a message from anyone else', soundEventKinds() === ['notification', 'message_friend', 'message']);
check('… and six with the shoutbox on: a shout from a friend, a shout from anyone else, an @-mention',
      !function_exists('shoutEnabled') || soundEventKinds(array_merge($cfg, ['users_enabled' => '1', 'shout_enabled' => '1'])) === ['notification', 'message_friend', 'message', 'shout_friend', 'shout', 'mention']);

// ── the shipped library ──────────────────────────────────────────────────────
$b = soundBuiltins('/x/');
check('the shipped files are listed', count($b) >= 10, (string)count($b));
$shape = true;
foreach ($b as $id => $e) {
    if (!str_starts_with($id, 'b:') || $e['id'] !== $id || !str_starts_with($e['url'], '/x/assets/sounds/') || $e['custom'] !== false) $shape = false;
}
check('ids are b:<slug> and the URLs point under assets/sounds/', $shape);
check('a name is prettified', soundPrettyName('notification-center') === 'Notification center' && ($b['b:ding']['name'] ?? '') === 'Ding');
$n7 = @file_get_contents($root . '/assets/sounds/notification-7.mp3');
$scan7 = $n7 === false ? null : soundMp3Scan($n7);
check('notification-7 ships cut to one hit (under 4.5 s)', $scan7 !== null && $scan7['ms'] > 3000 && $scan7['ms'] < 4500, json_encode($scan7));

// ── sniffing ─────────────────────────────────────────────────────────────────
$mp3 = (string)file_get_contents($root . '/assets/sounds/ding.mp3');
$s = soundSniff($mp3);
check('a real MP3 is recognised and measured', $s !== null && $s['mime'] === 'audio/mpeg' && $s['ext'] === 'mp3' && $s['ms'] > 500 && $s['ms'] < 3000, json_encode($s));
check('a PNG header is not a sound', soundSniff("\x89PNG\r\n\x1a\n" . str_repeat("\0", 2000)) === null);
check('text is not a sound', soundSniff(str_repeat('hello world ', 200)) === null);
check('a lone sync word is not an MP3', soundSniff("\xff\xfb" . str_repeat("\0", 3000)) === null);
check('an Ogg page with no identification header is not a sound', soundSniff('OggS' . str_repeat("\0", 600)) === null);
$oggPage = function (int $seq, int $granule, string $payload, int $type = 0): string {
    $segs = ''; $len = strlen($payload);
    while ($len >= 255) { $segs .= "\xff"; $len -= 255; }
    $segs .= chr($len);
    return 'OggS' . "\x00" . chr($type) . pack('P', $granule) . pack('V', 1) . pack('V', $seq) . pack('V', 0) . chr(strlen($segs)) . $segs . $payload;
};
$opusHead = 'OpusHead' . "\x01" . "\x02" . pack('v', 312) . pack('V', 48000) . pack('v', 0) . "\x00";
$ogg = $oggPage(0, 0, $opusHead, 2) . $oggPage(1, 48000 * 3 + 312, str_repeat("\0", 400), 4);
check('an Ogg Opus stream is measured from its last granule (minus the pre-skip)', soundSniff($ogg) === ['mime' => 'audio/ogg', 'ext' => 'ogg', 'ms' => 3000], json_encode(soundSniff($ogg)));
$oggLong = $oggPage(0, 0, $opusHead, 2) . $oggPage(1, 48000 * 20, str_repeat("\0", 400), 4);
check('… so a long Ogg is refused like a long WAV', (soundStore($db, 'st-ogglong', $oggLong)['error'] ?? '') === 'api.sounds.too_long');
$wavOf = function (int $seconds): string {
    $data = str_repeat("\0", 16000 * $seconds);   // 8 kHz, mono, 16-bit = 16000 bytes per second
    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE' . 'fmt ' . pack('V', 16) . pack('vvVVvv', 1, 1, 8000, 16000, 2, 16)
         . 'data' . pack('V', strlen($data)) . $data;
};
check('a WAV is recognised and measured', soundSniff($wavOf(1)) === ['mime' => 'audio/wav', 'ext' => 'wav', 'ms' => 1000]);
check('a RIFF that is not WAVE is not a sound', soundSniff('RIFF' . pack('V', 100) . 'AVI ' . str_repeat("\0", 600)) === null);
check('a WAVE without a fmt chunk is not a sound either', soundSniff('RIFF' . pack('V', 100) . 'WAVE' . 'data' . pack('V', 600) . str_repeat("\0", 600)) === null);

// ── storing an upload, and the caps ──────────────────────────────────────────
$db->exec("DELETE FROM sounds WHERE name LIKE 'st-%'");
$r = soundStore($db, ' st-ok ', $mp3);
check('a real file is stored, its name trimmed, its type the sniffed one',
      !empty($r['ok']) && $r['row']['name'] === 'st-ok' && $r['row']['bytes'] === strlen($mp3) && $r['row']['mime'] === 'audio/mpeg', json_encode($r));
$id = (int)($r['row']['id'] ?? 0);
check('the same bytes again are a duplicate', (soundStore($db, 'st-dup', $mp3)['error'] ?? '') === 'api.sounds.duplicate');
check('too small is refused', (soundStore($db, 'st-small', 'OggS' . str_repeat("\0", 10))['error'] ?? '') === 'api.sounds.too_small');
check('too large is refused before sniffing', (soundStore($db, 'st-big', str_repeat('x', SOUNDS_MAX_BYTES + 1))['error'] ?? '') === 'api.sounds.too_large');
check('not audio is refused', (soundStore($db, 'st-png', "\x89PNG" . str_repeat("\0", 3000))['error'] ?? '') === 'api.sounds.not_audio');
check('a blank name is refused', (soundStore($db, "  \x01 ", $wavOf(1))['error'] ?? '') === 'api.sounds.name_required');
check('longer than 15 s is refused', (soundStore($db, 'st-long', $wavOf(20))['error'] ?? '') === 'api.sounds.too_long');
$got = soundCustomGet($db, $id);
check('it reads back with its bytes and sha1', $got !== null && $got['data'] === $mp3 && $got['sha1'] === sha1($mp3));
$lib = soundLibrary($db, '/x/');
check('the library lists it as c:<id> with a sha1-versioned URL',
      isset($lib['c:' . $id]) && $lib['c:' . $id]['custom'] === true
      && str_contains($lib['c:' . $id]['url'], 'endpoint=sound&id=' . $id . '&v=' . substr(sha1($mp3), 0, 8)), json_encode($lib['c:' . $id] ?? null));
$forClient = soundLibraryForClient($lib);
check('the client list carries no internals', !isset($forClient[0]['bytes']) && !isset($forClient[0]['num']) && isset($forClient[0]['url']));
$ids = array_keys($lib);

// ── a reader's preferences ───────────────────────────────────────────────────
check('nothing stored = muted, 60%, a second of silence first, every event on the site default', soundPrefsValidate(null, $ids) === soundPrefsDefault() && soundPrefsDefault()['pre_kind'] === 'silence');
$p = soundPrefsValidate(['on' => 1, 'vol' => 150, 'pre' => 9999, 'pre_kind' => 'x', 'ev' => ['notification' => 'c:' . $id, 'message' => '', 'shout' => 'b:ding']], $ids);
check('values are clamped, an unknown kind of pre-roll dropped, an unknown event ignored',
      $p['on'] === 1 && $p['vol'] === 100 && $p['pre'] === 3000 && $p['pre_kind'] === 'silence' && $p['ev'] === ['notification' => 'c:' . $id, 'message_friend' => null, 'message' => ''], json_encode($p));
$p = soundPrefsValidate(['ev' => ['notification' => 'c:999999', 'message' => 'nonsense']], $ids);
check('an id the library does not have becomes "the site default"', $p['ev'] === ['notification' => null, 'message_friend' => null, 'message' => null], json_encode($p));
$p = soundPrefsValidate('{"on":1,"vol":"30","pre":"1250","pre_kind":"silence"}', $ids);
check('a stored JSON string decodes; the pre-roll rounds to tenths of a second', $p['vol'] === 30 && $p['pre'] === 1300 && $p['pre_kind'] === 'silence', json_encode($p));
check('garbage decodes to the default', soundPrefsValidate('{not json', $ids) === soundPrefsDefault() && soundPrefsValidate(42, $ids) === soundPrefsDefault());

// ── how an event resolves ────────────────────────────────────────────────────
$cfgT = ['sound_default_notification' => 'b:ding', 'sound_default_message' => ''];
$prefs = soundPrefsDefault();
check('an unset event follows the site default', (soundResolve($cfgT, $prefs, $lib, 'notification')['id'] ?? null) === 'b:ding');
check('… and a site default of nothing is silence', soundResolve($cfgT, $prefs, $lib, 'message') === null);
$prefs['ev']['notification'] = '';
check("'' is silence even with a site default", soundResolve($cfgT, $prefs, $lib, 'notification') === null);
$prefs['ev']['notification'] = 'c:' . $id;
check('a pick wins over the site default', (soundResolve($cfgT, $prefs, $lib, 'notification')['id'] ?? null) === 'c:' . $id);
check('a site default that is not in the library is silence', soundResolve(['sound_default_notification' => 'c:424242'], soundPrefsDefault(), $lib, 'notification') === null);
check('the site defaults are reported only when the library has them',
      soundSiteDefaults(['sound_default_notification' => 'b:ding', 'sound_default_message' => 'c:424242'], $lib) === ['notification' => 'b:ding', 'message_friend' => '', 'message' => '']);

// ── what a page is handed, with a real account ───────────────────────────────
$db->prepare("DELETE FROM users WHERE username = ?")->execute(['sndtest']);
userCreate($db, $cfg, 'sndtest', 'sndtest@example.org', 'SmokePass123!', '127.0.0.1');
$uid = (int)$db->query("SELECT id FROM users WHERE username = 'sndtest'")->fetchColumn();
check('the account exists', $uid > 0);
// how many of the waiting messages are from friends
$db->prepare("DELETE FROM users WHERE username IN (?, ?)")->execute(['sndfriend', 'sndother']);
userCreate($db, $cfg, 'sndfriend', 'sndfriend@example.org', 'SmokePass123!', '127.0.0.1');
userCreate($db, $cfg, 'sndother', 'sndother@example.org', 'SmokePass123!', '127.0.0.1');
$fid = (int)$db->query("SELECT id FROM users WHERE username = 'sndfriend'")->fetchColumn();
$oid = (int)$db->query("SELECT id FROM users WHERE username = 'sndother'")->fetchColumn();
$mk = function (int $a, int $b) use ($db): void {
    $lo = min($a, $b); $hi = max($a, $b);
    $db->prepare("INSERT INTO message_threads (u_low, u_high, last_message_at) VALUES (?, ?, NOW())")->execute([$lo, $hi]);
    $tid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, 'hi', 'bbcode')")->execute([$tid, $a]);
};
$mk($fid, $uid); $mk($oid, $uid);
check('two messages wait, none from a friend yet', pmUnreadCount($db, $uid) === 2 && pmUnreadCountFriends($db, $uid) === 0);
$db->prepare("INSERT INTO user_friends (user_id, friend_id, status, accepted_at) VALUES (?, ?, 'accepted', NOW())")->execute([$uid, $fid]);
check('… and one of them is from a friend once the friendship is accepted', pmUnreadCountFriends($db, $uid) === 1);
$db->prepare("DELETE FROM user_friends WHERE user_id = ? OR friend_id = ?")->execute([$uid, $uid]);
$db->prepare("DELETE FROM users WHERE username IN (?, ?)")->execute(['sndfriend', 'sndother']);
$u = ['id' => $uid];                       // no sound_prefs key: read from the table
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'sounds_enabled' => '1', 'sound_default_notification' => 'b:ding', 'sound_default_message' => '']);
try {
    check('muted: nothing for the page', soundClientConfig($db, $cfgOn, $u, '/x/', true) === null);
    soundPrefsSave($db, $uid, soundPrefsValidate(['on' => 1, 'vol' => 40, 'pre' => 500, 'pre_kind' => 'silence', 'ev' => ['message' => 'c:' . $id]], $ids));
    $cc = soundClientConfig($db, $cfgOn, $u, '/x/', true);
    check('switched on: volume, pre-roll and a URL per event',
          $cc !== null && $cc['vol'] === 40 && $cc['pre'] === 500 && $cc['pre_kind'] === 'silence'
          && ($cc['ev']['notification']['id'] ?? null) === 'b:ding' && str_starts_with((string)($cc['ev']['notification']['url'] ?? ''), '/x/assets/sounds/')
          && ($cc['ev']['message']['id'] ?? null) === 'c:' . $id, json_encode($cc));
    check('the array a page already holds is used as it is', soundClientConfig($db, $cfgOn, ['id' => $uid, 'sound_prefs' => null], '/x/', true) === null);
    check('feature off: nothing, whatever the reader chose', soundClientConfig($db, array_merge($cfgOn, ['sounds_enabled' => '0']), $u, '/x/', true) === null);
    check('no permission: nothing', soundClientConfig($db, $cfgOn, $u, '/x/', false) === null);
    soundPrefsSave($db, $uid, soundPrefsValidate(['on' => 1, 'ev' => ['notification' => '', 'message' => '']], $ids));
    check('on, but every event silent: nothing to carry', soundClientConfig($db, array_merge($cfgOn, ['sound_default_message' => 'b:ding']), $u, '/x/', true) === null);

    // ── deleting an upload ───────────────────────────────────────────────────
    soundPrefsSave($db, $uid, soundPrefsValidate(['on' => 1, 'ev' => ['message' => 'c:' . $id]], $ids));
    setSetting($db, 'sound_default_message', 'c:' . $id);
    check('deleting the upload also clears a site default that named it',
          soundDelete($db, $id) && (string)(getSettings($db, true)['sound_default_message'] ?? '') === '');
    check('… and a reader who picked it now follows the site default', soundPrefsFor($db, $u, soundLibrary($db))['ev']['message'] === null);
    check('… and it is gone from the library', !isset(soundLibrary($db)['c:' . $id]));
    check('deleting again says no', !soundDelete($db, $id));
} finally {
    $db->prepare("DELETE FROM users WHERE username = ?")->execute(['sndtest']);
    $db->exec("DELETE FROM sounds WHERE name LIKE 'st-%'");
    foreach ($wasDefault as $k => $v) setSetting($db, 'sound_default_' . $k, $v);
}

// ── the wiring, by reading it ────────────────────────────────────────────────
$api = (string)file_get_contents($root . '/api.php');
check('the four endpoints are routed', str_contains($api, "'sounds'") && str_contains($api, "'sound'") && str_contains($api, "'user_sound_prefs'") && str_contains($api, "'admin/sounds'"));
check('admin/sounds is owner-only (not in the permission map)', !preg_match("/'admin\\/sounds'\\s*=>\\s*'panel\\./", $api));
$ep = (string)file_get_contents($root . '/api/sound.php');
check('the stream releases the session first, sends nosniff and the sniffed type, answers 304',
      str_contains($ep, 'session_write_close') && str_contains($ep, 'nosniff') && str_contains($ep, "header('Content-Type: ' . \$row['mime'])") && str_contains($ep, '304'));
check('… and honours the feature switch', str_contains($ep, 'soundsEnabled($cfg)'));
check('the upload refuses an oversized body before reading it', str_contains((string)file_get_contents($root . '/api/admin/sounds.php'), "CONTENT_LENGTH"));
$js = (string)file_get_contents($root . '/assets/js/sounds.js');
check('the script has the pre-roll, the hum, the idle suspend and the honest note',
      str_contains($js, 'createOscillator') && str_contains($js, 'suspend()') && str_contains($js, 'sound-chip') && str_contains($js, "'blocked'"));
$app = (string)file_get_contents($root . '/assets/js/app.js');
check('the pulse feeds the observer from its own fetch only, and keeps asking while hidden for a reader who wants to hear',
      str_contains($app, 'window.Sounds.observe(r)') && str_contains($app, 'window.Sounds.wants()'));
$people = (string)file_get_contents($root . '/assets/js/people.js');
check('the inbox poll feeds the observer too, friends apart', str_contains($people, "window.Sounds.observe({ unread_pm: n, unread_pm_friend: fromFriends })") && str_contains($people, "badge(j.unread, j.unread_friend)"));
$nav = (string)file_get_contents($root . '/templates/nav.php');
check('the badge carries the config and the note sits beside the account link', str_contains($nav, 'data-sounds=') && str_contains($nav, 'id="sound-chip"'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
