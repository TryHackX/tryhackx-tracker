<?php
/**
 * Pictures and profile covers (1.63.0, includes/usermedia.php), without a web server:
 *   php tests/usermedia_test.php
 *
 * The pipeline refuses what it must refuse, in the order that keeps the server alive — size and
 * pixel count from the header, BEFORE a pixel is decoded — and what it accepts comes out upright,
 * bounded, re-encoded to WebP and carrying none of the EXIF or GPS it arrived with. The avatar
 * squares are checked against the research's formula by reading their PIXELS, not by trusting the
 * function that computes the window. Then the table: a replacement deletes what it replaced, a
 * removal clears the columns, a deleted account takes its images with it, and the uncropped source
 * answers to nobody but its owner and the panel. And the helpers phase B will call.
 *
 * Every switch the checks lean on is set explicitly in $cfgOn — nothing is inherited from whatever
 * the live settings of this database happen to be. Self-cleaning: the two accounts, their rows, any
 * site-default rows it wrote, the settings it touched and the member group's JSON are put back.
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
require_once $root . '/includes/usermedia.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$GLOBALS['db'] = $db;

// The switches, all of them, stated. A check that says "with pictures on" must not be relying on
// the local database having them on today.
$cfgOn = array_merge($cfg, [
    'users_enabled' => '1', 'users_require_email_verify' => '1',
    'avatars_enabled' => '1', 'covers_enabled' => '1',
    'avatar_max_kb' => '8192', 'avatar_max_mp' => '24',
    'avatar_default' => 'generated', 'avatar_default_sha' => '', 'cover_default_sha' => '',
]);
$GLOBALS['cfg'] = $cfgOn;

// ── what this run changes, and how it is put back ─────────────────────────────────────────────
$defaultKeys = ['avatar_default_sha', 'avatar_default_x', 'avatar_default_y', 'avatar_default_zoom',
                'cover_default_sha', 'cover_default_x', 'cover_default_y', 'cover_default_zoom'];
$settingsBefore = [];
foreach ($defaultKeys as $k) $settingsBefore[$k] = array_key_exists($k, $cfg) ? (string)$cfg[$k] : null;
$memberBefore = (string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn();
// The site's own rows (user_id NULL) are backed up whole: this run writes and removes a default
// picture and a default cover, and must not take away one an operator had set on this database.
$siteRows = $db->query("SELECT user_id, kind, size, sha1, mime, bytes, width, height, data, created_at FROM user_media WHERE user_id IS NULL")
               ->fetchAll(PDO::FETCH_ASSOC) ?: [];
const UM_USERS = ['umtest_alice', 'umtest_bob', 'umtest_gone', 'umtest_perm', 'umtest_unver', 'umtest_prem'];
register_shutdown_function(function () use ($db, $settingsBefore, $memberBefore, $siteRows) {
    foreach (UM_USERS as $name) {
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) userDeleteCascade($db, $id);
    }
    $db->exec("DELETE FROM user_media WHERE user_id IS NULL");
    foreach ($siteRows as $r) {
        $st = $db->prepare("INSERT INTO user_media (user_id, kind, size, sha1, mime, bytes, width, height, data, created_at)
                            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->bindValue(1, $r['kind']);
        $st->bindValue(2, $r['size'], $r['size'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(3, $r['sha1']);
        $st->bindValue(4, $r['mime']);
        $st->bindValue(5, (int)$r['bytes'], PDO::PARAM_INT);
        $st->bindValue(6, (int)$r['width'], PDO::PARAM_INT);
        $st->bindValue(7, (int)$r['height'], PDO::PARAM_INT);
        $st->bindValue(8, $r['data'], PDO::PARAM_LOB);
        $st->bindValue(9, $r['created_at']);
        $st->execute();
    }
    foreach ($settingsBefore as $k => $v) {
        if ($v === null) $db->prepare("DELETE FROM settings WHERE `key` = ?")->execute([$k]);
        else setSetting($db, $k, $v);
    }
    if ($memberBefore !== '') $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([$memberBefore]);
});

// ── fixtures, drawn here so the test says exactly what it feeds in ────────────────────────────
function umPng(\GdImage $im): string { ob_start(); imagepng($im); return (string)ob_get_clean(); }
function umJpeg(\GdImage $im, int $q = 92): string { ob_start(); imagejpeg($im, null, $q); return (string)ob_get_clean(); }
function umGif(\GdImage $im): string { ob_start(); imagegif($im); return (string)ob_get_clean(); }
/** Left half red, right half blue: which way is up can be read off the result. */
function umHalves(int $w, int $h): \GdImage {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h - 1, imagecolorallocate($im, 230, 20, 20));
    imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocate($im, 20, 20, 230));
    return $im;
}
/** Red = x, green = y: a pixel of any cut says which source pixel it came from. */
function umGradient(int $w, int $h): \GdImage {
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $g = (int)round($y * 255 / ($h - 1));
        for ($x = 0; $x < $w; $x++) imagesetpixel($im, $x, $y, (((int)round($x * 255 / ($w - 1))) << 16) | ($g << 8) | 128);
    }
    return $im;
}
function umRgb(\GdImage $im, int $x, int $y): array {
    $c = imagecolorat($im, $x, $y);
    return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, ($c >> 24) & 0x7F];
}
function umNear(array $got, array $want, int $tol): bool {
    foreach ($want as $i => $v) if ($v !== null && abs($got[$i] - $v) > $tol) return false;
    return true;
}
/**
 * A JPEG with an APP1 Exif block after SOI: IFD0 carries Orientation and a pointer to a GPS IFD
 * with a latitude and a longitude (Warsaw, give or take). Big-endian TIFF, offsets from its header.
 */
function umExifJpeg(string $jpeg, int $orientation): string {
    $be16 = fn($v) => pack('n', $v);
    $be32 = fn($v) => pack('N', $v);
    $entry = fn($tag, $type, $count, $value) => $be16($tag) . $be16($type) . $be32($count) . $value;
    $tiff  = "MM\x00\x2A" . $be32(8);
    $ifd0  = $be16(2)
           . $entry(0x0112, 3, 1, $be16($orientation) . "\x00\x00")
           . $entry(0x8825, 4, 1, $be32(38))                    // GPS IFD at 38
           . $be32(0);
    $gps   = $be16(4)
           . $entry(0x0001, 2, 2, "N\x00\x00\x00")
           . $entry(0x0002, 5, 3, $be32(92))                    // three rationals at 92
           . $entry(0x0003, 2, 2, "E\x00\x00\x00")
           . $entry(0x0004, 5, 3, $be32(116))                   // three more at 116
           . $be32(0);
    $rat   = fn($a, $b) => $be32($a) . $be32($b);
    $data  = $rat(52, 1) . $rat(13, 1) . $rat(3000, 100) . $rat(21, 1) . $rat(0, 1) . $rat(1200, 100);
    $body  = "Exif\x00\x00" . $tiff . $ifd0 . $gps . $data;
    return "\xFF\xD8" . "\xFF\xE1" . $be16(strlen($body) + 2) . $body . substr($jpeg, 2);
}
/** Two single-frame GIFs from GD spliced into one looping, animated GIF (frame 2 gets a local table). */
function umAnimatedGif(array $frames): string {
    $parse = function (string $g): array {
        $packed = ord($g[10]);
        $gctLen = ($packed & 0x80) ? 3 * (2 << ($packed & 7)) : 0;
        $p = 13 + $gctLen;
        while ($p < strlen($g) && $g[$p] === "\x21") {            // skip GD's own extensions
            $p += 2;
            while (($len = ord($g[$p])) > 0) $p += $len + 1;
            $p++;
        }
        $desc = substr($g, $p, 10);
        $p += 10;
        $lct = '';
        if (ord($desc[9]) & 0x80) { $l = 3 * (2 << (ord($desc[9]) & 7)); $lct = substr($g, $p, $l); $p += $l; }
        $start = $p;
        $p++;                                                     // LZW minimum code size
        while (($len = ord($g[$p])) > 0) $p += $len + 1;
        $p++;
        return ['lsd' => substr($g, 6, 7), 'gct' => substr($g, 13, $gctLen), 'packed' => $packed,
                'desc' => $desc, 'lct' => $lct, 'data' => substr($g, $start, $p - $start)];
    };
    $f0 = $parse($frames[0]);
    $out = 'GIF89a' . $f0['lsd'] . $f0['gct'] . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
    foreach ($frames as $i => $g) {
        $f = $i === 0 ? $f0 : $parse($g);
        $out .= "\x21\xF9\x04\x00" . pack('v', 10) . "\x00\x00";
        $desc = $f['desc'];
        $lct = $f['lct'];
        if ($i > 0 && $lct === '' && $f['gct'] !== '') {          // its own palette travels with it
            $desc[9] = chr(0x80 | ($f['packed'] & 7));
            $lct = $f['gct'];
        }
        $out .= $desc . $lct . $f['data'];
    }
    return $out . "\x3B";
}
/** The RIFF chunk ids of a WebP file, in order. */
function umWebpChunks(string $b): array {
    $out = [];
    for ($p = 12; $p + 8 <= strlen($b); ) {
        $id = substr($b, $p, 4);
        $len = unpack('V', substr($b, $p + 4, 4))[1];
        $out[] = $id;
        $p += 8 + $len + ($len & 1);
    }
    return $out;
}
function umUser(PDO $db, array $cfg, string $name, bool $verified = true): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0 && $verified) $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$id]);
    return $id;
}
function umRows(PDO $db, ?int $uid): array {
    $st = $db->prepare("SELECT id, kind, size, sha1, mime, width, height, bytes FROM user_media WHERE user_id <=> ? ORDER BY id");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function umUserRow(PDO $db, int $uid): array {
    $st = $db->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$uid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

// ── 1. schema 69 ──────────────────────────────────────────────────────────────────────────────
check('schema is at 69 or later', (int)($cfg['schema_version'] ?? 0) >= 69, (string)($cfg['schema_version'] ?? ''));
check('the user_media table exists', schemaTableExists($db, 'user_media'));
$missing = [];
foreach (['id', 'user_id', 'kind', 'size', 'sha1', 'mime', 'bytes', 'width', 'height', 'data', 'created_at'] as $c) {
    if (!schemaColumnExists($db, 'user_media', $c)) $missing[] = $c;
}
check('… with every column the brief names', !$missing, implode(',', $missing));
check('… and both keys: (user_id, kind) and (sha1, size)',
      schemaIndexExists($db, 'user_media', 'idx_media_user') && schemaIndexExists($db, 'user_media', 'idx_media_sha'));
$missing = [];
foreach (['avatar_sha', 'avatar_x', 'avatar_y', 'avatar_zoom', 'cover_sha', 'cover_x', 'cover_y', 'cover_zoom'] as $c) {
    if (!schemaColumnExists($db, 'users', $c)) $missing[] = $c;
}
check('users carries the eight small columns and no image bytes', !$missing && !schemaColumnExists($db, 'users', 'avatar_data'), implode(',', $missing));
$src = (string)file_get_contents($root . '/includes/schema.php');
check('the table is created in BOTH schema paths (fresh install and upgrade)', substr_count($src, 'CREATE TABLE IF NOT EXISTS `user_media`') === 2);
$defs = trackerSchemaDefaultSettings();
$want = ['avatars_enabled' => '1', 'covers_enabled' => '1', 'avatar_max_kb' => '8192', 'avatar_max_mp' => '24',
         'cover_height' => '220', 'cover_height_mobile' => '160', 'cover_overlay' => 'gradient', 'avatar_default' => 'generated'];
$bad = [];
foreach ($want as $k => $v) if (!array_key_exists($k, $defs) || $defs[$k] !== $v) $bad[] = $k . '=' . var_export($defs[$k] ?? null, true);
check('the eight settings ship with the brief\'s defaults', !$bad, implode(' ', $bad));
check('… and the site defaults\' own rows start empty/centred',
      array_key_exists('avatar_default_sha', $defs) && $defs['avatar_default_sha'] === '' && $defs['cover_default_zoom'] === '1');

// The four places a setting lives: the defaults (above), the save allow-list with its clamps, the
// Settings form, the search catalogue.
$save = (string)file_get_contents($root . '/api/admin/save_settings.php');
$tpl  = (string)file_get_contents($root . '/templates/admin/settings.php');
require_once $root . '/includes/settings_catalog.php';
$kw = settingsCatalogKeywords();
$bad = [];
foreach (array_keys($want) as $k) {
    if (!str_contains($save, "'$k'")) $bad[] = "$k:save";
    if (!str_contains($tpl, 'name="' . $k . '"')) $bad[] = "$k:form";
    if (!isset($kw[$k])) $bad[] = "$k:catalogue";
}
check('every setting is saveable, on the form and findable', !$bad, implode(' ', $bad));
check('the numeric ones are clamped to the brief\'s ranges from the constants',
      str_contains($save, "'avatar_max_kb' => [USER_MEDIA_KB_MIN, USER_MEDIA_KB_MAX") && USER_MEDIA_KB_MIN === 256 && USER_MEDIA_KB_MAX === 20480
      && str_contains($save, "'avatar_max_mp' => [USER_MEDIA_MP_MIN, USER_MEDIA_MP_MAX") && USER_MEDIA_MP_MIN === 4 && USER_MEDIA_MP_MAX === 40
      && str_contains($save, "'cover_height' => [USER_COVER_H_MIN, USER_COVER_H_MAX") && USER_COVER_H_MIN === 96 && USER_COVER_H_MAX === 600);
check('Profiles is a chip of its own', in_array('profiles', array_column(settingsCatalogGroups(), 'id'), true)
      && str_contains($tpl, 'id="section-profiles" data-group="profiles"'));

// ── 2. the permissions: the picture to members, the COVER to premium — asked of the MIGRATION ──
// Other suites rewrite the member group's JSON and both markers are already stamped on this
// database, so the split is proved by undoing it and letting the migrations run again: v69 hands
// both ids to members, and v71 takes the cover back off them and puts it in `premium`. Running them
// in that order is exactly what an upgrading server does.
$db->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"profile.avatar\"', '$.\"profile.cover\"') WHERE slug = 'member'");
$db->exec("DELETE FROM settings WHERE `key` IN ('schema_grant_v69_profile_media', 'schema_once_v71_group_matrix')");
trackerSchemaDataMigrations($db, $cfgOn);
$mp = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn(), true) ?: [];
$gp = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'guest'")->fetchColumn(), true) ?: [];
$pp = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'premium'")->fetchColumn(), true) ?: [];
check('the migration grants profile.avatar to members', !empty($mp['profile.avatar']), json_encode($mp));
check('… and takes profile.cover back off them — the one removal v71 makes', empty($mp['profile.cover']), json_encode($mp));
check('… putting it on the seeded premium group instead', !empty($pp['profile.cover']));
check('… and not to guests', empty($gp['profile.avatar']) && empty($gp['profile.cover']));
check('both ids are registered, and each preset carries its own',
      isset(userPermissionList()['profile.avatar'], userPermissionList()['profile.cover'])
      && in_array('profile.avatar', userGroupPresets()['member']['perms'], true)
      && !in_array('profile.cover', userGroupPresets()['member']['perms'], true)
      && in_array('profile.cover', userGroupPresets()['premium']['perms'], true));
$permUid = umUser($db, $cfgOn, 'umtest_perm');
$unverUid = umUser($db, $cfgOn, 'umtest_unver', false);
check('a verified member may set a picture, and may NOT set a cover',
      userIdHasPermission($db, $cfgOn, $permUid, 'profile.avatar') && !userIdHasPermission($db, $cfgOn, $permUid, 'profile.cover'));
// A SECOND account, in the group somebody bought or was given: the cover opens for it. It has to be
// a different one — userEffectivePermissions() caches per user for the life of the process, so an
// account that has already been asked about would answer from before the grant.
$premGid = (int)$db->query("SELECT id FROM user_groups WHERE slug = 'premium'")->fetchColumn();
$premUid = umUser($db, $cfgOn, 'umtest_prem');
userGrantGroup($db, $premUid, $premGid, null, 'test', 'usermedia', false);
check('… an account in the premium group may', $premGid > 0 && userIdHasPermission($db, $cfgOn, $premUid, 'profile.cover'));
check('… an unverified one is at guest level and may not', !userIdHasPermission($db, $cfgOn, $unverUid, 'profile.avatar'));
check('… and an anonymous visitor may not', empty(userEffectivePermissions($db, null, $cfgOn)['profile.avatar']));
check('with accounts switched off nobody may (a picture belongs to an account)', userLegacyDefault('profile.avatar') === false && userLegacyDefault('profile.cover') === false);

// ── 3. the refusals, each one for its own reason ──────────────────────────────────────────────
$max = userMediaMaxBytes($cfgOn);
$phpUp = userMediaIniBytes((string)ini_get('upload_max_filesize'));
check('the effective ceiling is the lower of the setting and what PHP accepts',
      $max <= 8192 * 1024 && ($phpUp <= 0 || $max <= $phpUp)
      && userMediaMaxBytes(array_merge($cfgOn, ['avatar_max_kb' => '256'])) === min(256 * 1024, $phpUp > 0 ? $phpUp : PHP_INT_MAX),
      $max . ' / php ' . $phpUp);
$big = "\x89PNG\r\n\x1a\n" . str_repeat("\0", $max + 1);
$r = userMediaPrepare($big, $cfgOn, 1600);
check('too big: refused by length, 413', ($r['error'] ?? '') === 'api.media.too_large' && ($r['status'] ?? 0) === 413, json_encode(array_diff_key($r, ['im' => 1])));
$r = userMediaPrepare(str_repeat('x', 20), $cfgOn, 1600);
check('too small to be a picture', ($r['error'] ?? '') === 'api.media.too_small');
$r = userMediaPrepare("<?php echo 'I am a picture, honest'; ?>\n" . str_repeat('#', 200), $cfgOn, 1600);
check('not an image (a script sent as avatar.png) says so', ($r['error'] ?? '') === 'api.media.not_image', json_encode($r));
$svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="red"/></svg>';
$r = userMediaPrepare($svg, $cfgOn, 1600);
check('SVG is refused by name, never decoded', ($r['error'] ?? '') === 'api.media.unsupported' && ($r['detail'] ?? '') === 'svg' && ($r['status'] ?? 0) === 415, json_encode($r));
$bmpIm = imagecreatetruecolor(40, 40);
ob_start(); imagebmp($bmpIm); $bmp = (string)ob_get_clean();
$r = userMediaPrepare($bmp, $cfgOn, 1600);
check('BMP is refused by name', ($r['error'] ?? '') === 'api.media.unsupported' && ($r['detail'] ?? '') === 'bmp', json_encode($r));
$heic = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\0", 100);
check('a phone\'s HEIC is named too, rather than called "not a picture"', (userMediaPrepare($heic, $cfgOn, 1600)['detail'] ?? '') === 'heif');
// A type that lies: the magic bytes of a PNG in front of something that is not one. The header
// parser is asked, and it does not agree that this is a picture at all.
$r = userMediaPrepare("\x89PNG\r\n\x1a\n" . str_repeat("\x07", 300), $cfgOn, 1600);
check('a PNG signature in front of noise is refused, never decoded',
      empty($r['ok']) && in_array($r['error'] ?? '', ['api.media.not_image', 'api.media.mismatch', 'api.media.too_many_px'], true),
      json_encode(array_diff_key($r, ['im' => 1])));
// A GIF header over a PNG body: whatever the GIF decoder makes of it, the PNG does not come out the
// other side — refused, or re-encoded into a WebP in which none of the PNG's own bytes survive.
$liar = "GIF89a" . pack('v', 64) . pack('v', 64) . "\x00\x00\x00" . umPng(umHalves(64, 64));
$r = userMediaPrepare($liar, $cfgOn, 1600);
$lo = !empty($r['ok']) ? userMediaEncode($r['im']) : '';
check('a GIF header over a PNG body gets as far as the decoder and no further',
      empty($r['ok']) ? in_array($r['error'] ?? '', ['api.media.unreadable', 'api.media.not_image', 'api.media.mismatch'], true)
                      : (str_starts_with($lo, 'RIFF') && !str_contains($lo, 'IHDR')),
      json_encode(array_diff_key($r, ['im' => 1])));
// The megapixel ceiling, BEFORE the decode: a PNG header claiming 50000×50000 with no pixel data.
// Decoding it would say "unreadable" (there is nothing to decode); being refused as too many
// pixels proves the header answered first. The memory and the clock say the same thing.
$ihdr = pack('N', 50000) . pack('N', 50000) . "\x08\x06\x00\x00\x00";
$bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
      . pack('N', 0) . 'IEND' . pack('N', crc32('IEND')) . str_repeat("\0", 64);
$memBefore = memory_get_peak_usage(true);
$t0 = microtime(true);
$r = userMediaPrepare($bomb, $cfgOn, 1600);
$ms = (microtime(true) - $t0) * 1000;
check('a header claiming 50000×50000 is refused as too many megapixels', ($r['error'] ?? '') === 'api.media.too_many_px' && ($r['detail'] ?? '') === '50000×50000', json_encode($r));
check('… before any decode: no memory grew and it took no time', memory_get_peak_usage(true) - $memBefore < 2 * 1024 * 1024 && $ms < 50, round($ms, 2) . ' ms');
$ihdr2 = pack('N', 3000) . pack('N', 3000) . "\x08\x06\x00\x00\x00";
$nine = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr2 . pack('N', crc32('IHDR' . $ihdr2)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND')) . str_repeat("\0", 64);
$oldLimit = ini_get('memory_limit');
ini_set('memory_limit', (string)(memory_get_usage(true) + 40 * 1024 * 1024));
$r = userMediaPrepare($nine, $cfgOn, 1600);
ini_set('memory_limit', $oldLimit);
check('a picture under the ceiling that this process could not hold is refused, also before decoding',
      ($r['error'] ?? '') === 'api.media.too_big_to_process', json_encode($r));

// ── 4. what comes out ─────────────────────────────────────────────────────────────────────────
$plain = umJpeg(umHalves(200, 100));
$exif6 = umExifJpeg($plain, 6);
$efp = fopen('php://memory', 'r+b'); fwrite($efp, $exif6); rewind($efp);
$e = @exif_read_data($efp);
fclose($efp);
check('the fixture really carries an orientation and GPS', (int)($e['Orientation'] ?? 0) === 6 && isset($e['GPSLatitude'], $e['GPSLongitude']),
      json_encode(array_intersect_key((array)$e, ['Orientation' => 1, 'GPSLatitude' => 1, 'GPSLongitude' => 1])));
$r = userMediaPrepare($exif6, $cfgOn, 1600);
$out = !empty($r['ok']) ? userMediaEncode($r['im']) : '';
$back = $out !== '' ? imagecreatefromstring($out) : null;
check('EXIF orientation 6 is applied: 200×100 comes out 100×200', $back && imagesx($back) === 100 && imagesy($back) === 200,
      $back ? imagesx($back) . 'x' . imagesy($back) : json_encode($r));
check('… turned clockwise: the left (red) half is now on top', $back && umNear(umRgb($back, 50, 25), [230, 20, 20], 40) && umNear(umRgb($back, 50, 175), [20, 20, 230], 40),
      $back ? json_encode([umRgb($back, 50, 25), umRgb($back, 50, 175)]) : '');
$r8 = userMediaPrepare(umExifJpeg($plain, 8), $cfgOn, 1600);
$b8 = !empty($r8['ok']) ? $r8['im'] : null;
check('… and 8 turns the other way: red at the bottom', $b8 && imagesx($b8) === 100 && umNear(umRgb($b8, 50, 175), [230, 20, 20], 40), $b8 ? json_encode(umRgb($b8, 50, 175)) : '');
$r3 = userMediaPrepare(umExifJpeg($plain, 3), $cfgOn, 1600);
check('… and 3 is half a turn: red on the right', !empty($r3['ok']) && imagesx($r3['im']) === 200 && umNear(umRgb($r3['im'], 175, 50), [230, 20, 20], 40));
check('re-encoded to WebP', str_starts_with($out, 'RIFF') && substr($out, 8, 4) === 'WEBP');
$chunks = umWebpChunks($out);
check('… carrying no EXIF and no GPS: only image chunks, and neither word anywhere in the bytes',
      !array_diff($chunks, ['VP8 ', 'VP8L', 'VP8X', 'ALPH']) && !str_contains($out, 'Exif') && !str_contains($out, 'GPS')
      && !in_array('EXIF', $chunks, true) && !in_array('XMP ', $chunks, true), implode(',', $chunks));
// A polyglot: a real JPEG with a script after its end marker. Decoded and re-encoded, the tail
// is simply not there any more.
$poly = $plain . "<script>alert('also a web page')</script><?php system('id'); ?>";
$rp = userMediaPrepare($poly, $cfgOn, 1600);
$po = !empty($rp['ok']) ? userMediaEncode($rp['im']) : '';
check('a file that is also something else is accepted as the picture and nothing more', $po !== '' && !str_contains($po, 'script') && !str_contains($po, 'system'));
// An animated GIF becomes its first frame.
$red = imagecreatetruecolor(48, 48); imagefilledrectangle($red, 0, 0, 47, 47, imagecolorallocate($red, 220, 30, 30));
$blue = imagecreatetruecolor(48, 48); imagefilledrectangle($blue, 0, 0, 47, 47, imagecolorallocate($blue, 30, 30, 220));
$anim = umAnimatedGif([umGif($red), umGif($blue)]);
check('the fixture is an animated GIF with two frames', substr_count($anim, "\x21\xF9\x04") === 2 && str_contains($anim, 'NETSCAPE2.0') && @getimagesizefromstring($anim) !== false);
$ra = userMediaPrepare($anim, $cfgOn, 1600);
$ao = !empty($ra['ok']) ? userMediaEncode($ra['im']) : '';
check('an animated GIF becomes its first frame, as a still WebP',
      $ao !== '' && !in_array('ANIM', umWebpChunks($ao), true) && !in_array('ANMF', umWebpChunks($ao), true)
      && umNear(umRgb(imagecreatefromstring($ao), 24, 24), [220, 30, 30], 30), json_encode($ra['error'] ?? null));
// Alpha survives.
$alpha = imagecreatetruecolor(80, 80);
imagealphablending($alpha, false); imagesavealpha($alpha, true);
imagefill($alpha, 0, 0, imagecolorallocatealpha($alpha, 0, 0, 0, 127));
imagefilledellipse($alpha, 40, 40, 40, 40, imagecolorallocatealpha($alpha, 10, 200, 10, 0));
$rz = userMediaPrepare(umPng($alpha), $cfgOn, 1600);
$zo = imagecreatefromstring(userMediaEncode($rz['im']));
check('transparency is kept (a PNG\'s clear corner is still clear)', (umRgb($zo, 2, 2)[3] ?? 0) === 127 && (umRgb($zo, 40, 40)[3] ?? 127) === 0);
// Bounded.
$wide = umHalves(3000, 1200);
$wb = umPng($wide);
$ro = userMediaPrepare($wb, $cfgOn, USER_AVATAR_SRC_EDGE);
check('a picture\'s source is bounded to 1600 px on the long edge', !empty($ro['ok']) && $ro['w'] === 1600 && $ro['h'] === 640, ($ro['w'] ?? '?') . 'x' . ($ro['h'] ?? '?'));
unset($ro);
$small = userMediaPrepare(umPng(umHalves(300, 200)), $cfgOn, USER_AVATAR_SRC_EDGE);
check('… and never enlarged', !empty($small['ok']) && $small['w'] === 300 && $small['h'] === 200);

// ── 5. the numbers somebody sends ─────────────────────────────────────────────────────────────
check('focus: clamped to 0-100 and rounded to two decimals', userMediaFocusValue('150', 50) === 100.0 && userMediaFocusValue(-3, 50) === 0.0
      && userMediaFocusValue('33.3333', 50) === 33.33 && userMediaFocusValue('', 42) === 42.0);
check('… and nonsense is refused, not guessed', userMediaFocusValue('left', 50) === null && userMediaFocusValue('NAN', 50) === null);
check('zoom: clamped to 0.5-4 — the whole range, not the extension\'s slider that stopped at 3',
      userMediaZoomValue('9', 1) === 4.0 && userMediaZoomValue('0.1', 1) === 0.5 && userMediaZoomValue('3.5', 1) === 3.5 && userMediaZoomValue('x', 1) === null);

// ── 6. the squares, against the formula — by their pixels ─────────────────────────────────────
$grad = umGradient(1000, 600);
$gradBytes = umPng($grad);
$cases = [[50.0, 50.0, 1.0], [20.0, 80.0, 2.0], [0.0, 100.0, 1.5]];
foreach ($cases as [$fx, $fy, $fz]) {
    [$l, $t, $sd] = userAvatarWindow(1000, 600, $fx, $fy, $fz);
    $side = (int)round(min(1000, 600) / max(0.01, $fz));
    $ok = $sd === $side && $l === (int)round($fx / 100 * (1000 - $side)) && $t === (int)round($fy / 100 * (600 - $side));
    $cut = userAvatarCut($grad, $fx, $fy, $fz);
    $sq = $cut[256] ?? null;
    // The square's first and last pixels are the window's first and last, so their colour names the
    // source pixel: red = x·255/999, green = y·255/599.
    $want0 = [(int)round($l * 255 / 999), (int)round($t * 255 / 599), 128];
    $wantN = [(int)round(($l + $side - 1) * 255 / 999), (int)round(($t + $side - 1) * 255 / 599), 128];
    $got0 = $sq ? umRgb($sq, 0, 0) : [];
    $gotN = $sq ? umRgb($sq, 255, 255) : [];
    check(sprintf('the window for focus %g/%g zoom %g is [%d, %d, %d] — the formula, and the pixels agree', $fx, $fy, $fz, $l, $t, $side),
          $ok && $sq && umNear($got0, $want0, 4) && umNear($gotN, $wantN, 4),
          json_encode(['win' => [$l, $t, $sd], 'got0' => $got0, 'want0' => $want0, 'gotN' => $gotN, 'wantN' => $wantN]));
}
// Below 1: the picture floats inside the window over a blurred backdrop.
[$l, $t, $sd] = userAvatarWindow(1000, 600, 50, 50, 0.5);
$cut = userAvatarCut($grad, 50, 50, 0.5);
$sq = $cut[256];
$f = 256 / $sd;
$cx = (int)round((500 - $l) * $f); $cy = (int)round((300 - $t) * $f);
check('zoom 0.5: the window is twice the short edge and centred ([-100, -300, 1200])', [$l, $t, $sd] === [-100, -300, 1200]);
check('… the source\'s centre lands where the formula puts it', umNear(umRgb($sq, $cx, $cy), [128, 128, 128], 6), json_encode([$cx, $cy, umRgb($sq, $cx, $cy)]));
// The gradient's blue is 128 everywhere, so whatever the blur did, the band's blue says how dark the
// backdrop was made: 128 × 0.85 = 109 — the editor's brightness(.85). The picture itself is not.
$band = umRgb($sq, 128, 20);
check('… and the band above it is the backdrop darkened by 15 %, opaque, while the picture is not darkened',
      $band[3] === 0 && abs($band[2] - 109) <= 8 && abs(umRgb($sq, $cx, $cy)[2] - 128) <= 6, json_encode($band));
// Never enlarged.
$tiny = umHalves(200, 200);
check('no upscaling: a 200 px window gives 64 and 128 and no 256', array_keys(userAvatarCut($tiny, 50, 50, 1.0)) === [64, 128]);
check('… a 50 px window (zoom 4) gives only the 64 every surface needs', array_keys(userAvatarCut($tiny, 50, 50, 4.0)) === [64]);
check('… a 1600 px source at zoom 4 still has all three', userAvatarSizesFor((int)round(1600 / 4)) === [64, 128, 256]);

// ── 7. the table: store, replace, reposition, remove ──────────────────────────────────────────
$alice = umUser($db, $cfgOn, 'umtest_alice');
$bob = umUser($db, $cfgOn, 'umtest_bob');
check('the test accounts exist', $alice > 0 && $bob > 0);
$r1 = userAvatarStore($db, $cfgOn, $alice, $gradBytes, 20, 80, 2);
$rows1 = umRows($db, $alice);
$u = umUserRow($db, $alice);
check('a new picture is stored as its source and three squares, all WebP',
      !empty($r1['ok']) && count($rows1) === 4 && count(array_filter($rows1, fn($x) => $x['kind'] === 'avatar_src')) === 1
      && array_values(array_map('intval', array_column(array_filter($rows1, fn($x) => $x['kind'] === 'avatar'), 'size'))) === [64, 128, 256]
      && !array_filter($rows1, fn($x) => $x['mime'] !== 'image/webp'), json_encode([$r1['error'] ?? null, $rows1]));
check('… the source kept at its bounded size (1000×600)', ($s0 = array_values(array_filter($rows1, fn($x) => $x['kind'] === 'avatar_src'))[0] ?? null) && (int)$s0['width'] === 1000 && (int)$s0['height'] === 600);
check('… and the account row carries the crop id and the framing', $u['avatar_sha'] === ($r1['crop'] ?? '')
      && (float)$u['avatar_x'] === 20.0 && (float)$u['avatar_y'] === 80.0 && (float)$u['avatar_zoom'] === 2.0);
check('the crop id is the source and the framing, nothing else', ($r1['crop'] ?? '') === userAvatarCropId((string)$r1['src_sha'], 20, 80, 2));
// The first crop is cut from the STORED source — reposition to the same numbers and the squares are
// the same bytes (the research's 6b.7: the first crop used the raw upload, re-crops the stored file).
$sq1 = $db->prepare("SELECT data FROM user_media WHERE user_id = ? AND kind = 'avatar' AND size = 128");
$sq1->execute([$alice]); $firstBytes = (string)$sq1->fetchColumn();
$rp = userAvatarReposition($db, $cfgOn, $alice, 20, 80, 2);
$sq1->execute([$alice]); $againBytes = (string)$sq1->fetchColumn();
check('the first crop and a re-crop at the same numbers are the same bytes', !empty($rp['ok']) && $firstBytes !== '' && $firstBytes === $againBytes);
$srcIdBefore = (int)$s0['id'];
$oldCrop = (string)$r1['crop'];
$rp2 = userAvatarReposition($db, $cfgOn, $alice, 70, 30, 1.25);
$rows2 = umRows($db, $alice);
$u = umUserRow($db, $alice);
check('repositioning re-cuts the squares and keeps the source', !empty($rp2['ok']) && count($rows2) === 4
      && (int)array_values(array_filter($rows2, fn($x) => $x['kind'] === 'avatar_src'))[0]['id'] === $srcIdBefore
      && $u['avatar_sha'] === $rp2['crop'] && $rp2['crop'] !== $oldCrop && (float)$u['avatar_zoom'] === 1.25);
check('… and the old crop is not reachable at its old address any more', userMediaFind($db, $cfgOn, substr($oldCrop, 0, 16), 128, null, true) === null);
$r3x = userAvatarStore($db, $cfgOn, $alice, umPng(umHalves(400, 300)), 50, 50, 1);
$rows3 = umRows($db, $alice);
check('a replacement deletes every row it replaces, in the same write', !empty($r3x['ok'])
      && count($rows3) === 1 + count(userAvatarSizesFor(300))
      && !array_intersect(array_column($rows3, 'id'), array_column($rows2, 'id')), json_encode(array_column($rows3, 'kind')));
check('… so the old source is gone as well (nothing left behind, public or not)', userMediaFind($db, $cfgOn, substr((string)$r1['src_sha'], 0, 16), 0, $alice, true) === null);
// Covers.
$c1 = userCoverStore($db, $cfgOn, $alice, $wb, 30, 60, 1.5);
$crows = array_values(array_filter(umRows($db, $alice), fn($x) => in_array($x['kind'], USER_MEDIA_KINDS_COVER, true)));
$u = umUserRow($db, $alice);
check('a cover is stored bounded to 2400 px, with a 1000 px thumb sharing its id',
      !empty($c1['ok']) && count($crows) === 2 && (int)$crows[0]['width'] === 2400 && (int)$crows[0]['height'] === 960
      && $crows[1]['kind'] === 'cover_thumb' && (int)$crows[1]['width'] === 1000 && $crows[1]['sha1'] === $crows[0]['sha1'],
      json_encode([$c1['error'] ?? null, $crows]));
check('… and the account row carries its hash and its framing', $u['cover_sha'] === $c1['sha'] && (float)$u['cover_x'] === 30.0 && (float)$u['cover_zoom'] === 1.5);
$rowsBeforeCr = umRows($db, $alice);
$cr = userCoverReposition($db, $cfgOn, $alice, 10, 90, 0.75);
$u = umUserRow($db, $alice);
check('repositioning a cover writes three numbers and touches no image', !empty($cr['ok']) && umRows($db, $alice) === $rowsBeforeCr
      && (float)$u['cover_x'] === 10.0 && (float)$u['cover_y'] === 90.0 && (float)$u['cover_zoom'] === 0.75);
$c2 = userCoverStore($db, $cfgOn, $alice, umPng(umHalves(800, 300)), 50, 50, 1);
$crows2 = array_values(array_filter(umRows($db, $alice), fn($x) => in_array($x['kind'], USER_MEDIA_KINDS_COVER, true)));
check('a smaller cover replaces the old one AND its thumb, and needs no thumb of its own',
      !empty($c2['ok']) && count($crows2) === 1 && $crows2[0]['kind'] === 'cover' && $crows2[0]['sha1'] === $c2['sha']);
check('… and asking for its thumb is answered with the cover itself', ($th = userMediaFind($db, $cfgOn, substr($c2['sha'], 0, 16), 1000, null, false)) !== null && $th['kind'] === 'cover');

// ── 8. the stream's rules ─────────────────────────────────────────────────────────────────────
$u = umUserRow($db, $alice);
$crop = (string)$u['avatar_sha'];
$pick = fn(int $s) => userMediaFind($db, $cfgOn, substr($crop, 0, 16), $s, null, false);
check('the stream serves the largest square at or below the size asked for',
      (int)($pick(256)['size'] ?? 0) === 256 && (int)($pick(200)['size'] ?? 0) === 128 && (int)($pick(64)['size'] ?? 0) === 64 && (int)($pick(1)['size'] ?? 0) === 64);
$tinyStore = userAvatarStore($db, $cfgOn, $bob, umPng(umHalves(200, 200)), 50, 50, 1);
$bobCrop = (string)(umUserRow($db, $bob)['avatar_sha'] ?? '');
check('… so a picture too small for 256 answers the 128 in its place', (int)(userMediaFind($db, $cfgOn, substr($bobCrop, 0, 16), 256, null, false)['size'] ?? 0) === 128);
$srcRow = userAvatarSourceRow($db, $alice);
$srcH = substr((string)$srcRow['sha1'], 0, 16);
check('the uncropped source streams to its owner', userMediaFind($db, $cfgOn, $srcH, 0, $alice, false) !== null);
check('… and to the panel', userMediaFind($db, $cfgOn, $srcH, 0, null, true) !== null);
check('… and NOT to another account', userMediaFind($db, $cfgOn, $srcH, 0, $bob, false) === null);
check('… nor to a visitor', userMediaFind($db, $cfgOn, $srcH, 0, null, false) === null);
check('an address that is not sixteen hex characters is nothing', userMediaFind($db, $cfgOn, "' OR 1=1 --", 64, null, true) === null
      && userMediaFind($db, $cfgOn, substr($crop, 0, 15), 64, null, true) === null);
$cfgNoAv = array_merge($cfgOn, ['avatars_enabled' => '0']);
check('pictures switched off: the squares stop answering the public, the panel still sees them',
      userMediaFind($db, $cfgNoAv, substr($crop, 0, 16), 64, null, false) === null && userMediaFind($db, $cfgNoAv, substr($crop, 0, 16), 64, null, true) !== null);

// ── 9. removal, and an account going ──────────────────────────────────────────────────────────
$rm = userMediaRemove($db, $alice, 'avatar');
$u = umUserRow($db, $alice);
check('removing a picture deletes every row of it', !empty($rm['ok']) && !array_filter(umRows($db, $alice), fn($x) => in_array($x['kind'], USER_MEDIA_KINDS_AVATAR, true)));
check('… and clears the columns back to "none, centred"', array_key_exists('avatar_sha', $u) && $u['avatar_sha'] === null
      && (float)$u['avatar_x'] === 50.0 && (float)$u['avatar_y'] === 50.0 && (float)$u['avatar_zoom'] === 1.0, json_encode(array_intersect_key($u, array_flip(['avatar_sha', 'avatar_x', 'avatar_zoom']))));
check('… while the cover is untouched', count(array_filter(umRows($db, $alice), fn($x) => $x['kind'] === 'cover')) === 1);
$rm2 = userMediaRemove($db, $alice, 'cover');
$u = umUserRow($db, $alice);
check('removing a cover does the same for the cover', !empty($rm2['ok']) && umRows($db, $alice) === [] && array_key_exists('cover_sha', $u) && $u['cover_sha'] === null && (float)$u['cover_zoom'] === 1.0);
check('there is nothing to reposition afterwards', (userAvatarReposition($db, $cfgOn, $alice, 50, 50, 1)['error'] ?? '') === 'api.media.no_picture'
      && (userCoverReposition($db, $cfgOn, $alice, 50, 50, 1)['error'] ?? '') === 'api.media.no_cover');
$gone = umUser($db, $cfgOn, 'umtest_gone');
userAvatarStore($db, $cfgOn, $gone, umPng(umHalves(300, 300)), 50, 50, 1);
userCoverStore($db, $cfgOn, $gone, umPng(umHalves(900, 300)), 50, 50, 1);
$before = count(umRows($db, $gone));
$removed = userDeleteCascade($db, $gone);
check('deleting an account deletes its picture and its cover', $before >= 4 && umRows($db, $gone) === [] && (int)($removed['pictures'] ?? 0) === $before,
      json_encode(['before' => $before, 'removed' => $removed]));

// ── 10. the site's own: default picture and default cover ─────────────────────────────────────
$d1 = userAvatarStore($db, $cfgOn, null, umPng(umHalves(500, 500)), 50, 50, 1);
$fresh = getSettings($db, true);
check('the site\'s default picture is a set of rows with no owner, named in the settings',
      !empty($d1['ok']) && ($fresh['avatar_default_sha'] ?? '') === $d1['crop'] && count(umRows($db, null)) >= 4);
check('… whose source only the panel may fetch', userMediaFind($db, $cfgOn, substr((string)$d1['src_sha'], 0, 16), 0, $bob, false) === null
      && userMediaFind($db, $cfgOn, substr((string)$d1['src_sha'], 0, 16), 0, null, true) !== null);
$dp = userAvatarReposition($db, $cfgOn, null, 10, 10, 2);
check('… and which repositions like anybody\'s', !empty($dp['ok']) && getSettings($db, true)['avatar_default_zoom'] === '2.00');
$dc = userCoverStore($db, $cfgOn, null, umPng(umHalves(1200, 400)), 40, 40, 1);
check('a default cover likewise', !empty($dc['ok']) && getSettings($db, true)['cover_default_sha'] === $dc['sha']);

// ── 11. the helpers phase B draws with ────────────────────────────────────────────────────────
$base = '/';
$withPic = ['id' => 5, 'username' => 'Alice', 'avatar_sha' => str_repeat('ab', 20)];
$noPic = ['id' => 6, 'username' => 'bob_the_builder', 'avatar_sha' => null];
check('an account\'s own square: sixteen characters of the crop id and twice the CSS size',
      userAvatarUrl($withPic, 32, $base, $cfgOn) === '/api.php?endpoint=user_media&h=' . str_repeat('ab', 8) . '&s=64'
      && userAvatarUrl($withPic, 64, $base, $cfgOn) === '/api.php?endpoint=user_media&h=' . str_repeat('ab', 8) . '&s=128'
      && userAvatarUrl($withPic, 128, $base, $cfgOn) === '/api.php?endpoint=user_media&h=' . str_repeat('ab', 8) . '&s=256');
check('… and the address carries no account id and no file name',
      !preg_match('/[?&](id|uid|user|u|name|file|username)=/', userAvatarUrl($withPic, 64, $base, $cfgOn))
      && !preg_match('/[?&](id|uid|user|u|name|file|username)=/', userAvatarUrl($noPic, 64, $base, $cfgOn)));
check('no picture of their own: the generated letter', userAvatarUrl($noPic, 24, $base, $cfgOn) === '/api.php?endpoint=user_avatar_default&l=B&c=' . userAvatarColourIndex('bob_the_builder'));
$cfgDef = array_merge($cfgOn, ['avatar_default' => 'image', 'avatar_default_sha' => str_repeat('cd', 20)]);
check('… or the site\'s default picture, once the owner chose it and it exists',
      userAvatarUrl($noPic, 24, $base, $cfgDef) === '/api.php?endpoint=user_media&h=' . str_repeat('cd', 8) . '&s=64'
      && userAvatarUrl($noPic, 24, $base, array_merge($cfgDef, ['avatar_default_sha' => ''])) === userAvatarGeneratedUrl('bob_the_builder', $base));
check('pictures off: the address is the letter, and the element is not drawn at all',
      userAvatarUrl($withPic, 64, $base, $cfgNoAv) === userAvatarGeneratedUrl('Alice', $base) && userAvatarHtml($withPic, 64, $base, 'x', $cfgNoAv) === '');
$html = userAvatarHtml($withPic, 64, $base, 'prof-av', $cfgOn);
check('the element is square, sized, decorative and lazy, with the letter to fall back to',
      str_contains($html, 'class="prof-av js-avatar"') && str_contains($html, 'width="64" height="64"') && str_contains($html, 'alt=""')
      && str_contains($html, 'loading="lazy"') && str_contains($html, 'decoding="async"')
      && str_contains($html, 'data-fallback="/api.php?endpoint=user_avatar_default&amp;l=A&amp;c='), $html);
check('… and escapes what it prints', !str_contains(userAvatarHtml(['username' => '"><script>', 'avatar_sha' => null], 24, $base, 'a"b', $cfgOn), '<script>'));
check('the helpers ask no query: no PDO in their signature', (new ReflectionFunction('userAvatarUrl'))->getNumberOfParameters() === 4
      && !array_filter((new ReflectionFunction('userAvatarUrl'))->getParameters(), fn($p) => (string)$p->getType() === 'PDO'));
// The letter.
check('the letter is the first letter or digit, upper-cased', userAvatarLetter('alice') === 'A' && userAvatarLetter('_42x') === '4'
      && userAvatarLetter('...') === 'U' && userAvatarLetter('Zed') === 'Z');
$again = [];
foreach (['alice', 'Alice', 'ALICE'] as $nm) $again[] = userAvatarColourIndex($nm);
check('the colour is deterministic per name, whatever its case', count(array_unique($again)) === 1 && userAvatarColourIndex('alice') === userAvatarColourIndex('alice'));
$spread = [];
for ($i = 0; $i < 60; $i++) $spread[userAvatarColourIndex('member' . $i)] = true;
check('… and spread across the twelve colours', count($spread) >= 10 && max(array_keys($spread)) <= 11 && min(array_keys($spread)) >= 0, (string)count($spread));
check('FNV-1a, so the JavaScript twin can give the same number (known values)', userAvatarColourIndex('') === 2166136261 % 12
      && userAvatarColourIndex('a') === 0xE40C292C % 12);
$svgOut = userAvatarDefaultSvg('A', 3);
check('the generated picture is a letter on its colour and nothing else',
      str_contains($svgOut, '>A</text>') && str_contains($svgOut, USER_AVATAR_COLOURS[3]) && !preg_match('/<script|on[a-z]+=|href/i', $svgOut));
check('… and a letter outside the 36 is not printed', str_contains(userAvatarDefaultSvg('<', 99), '>U</text>') && str_contains(userAvatarDefaultSvg('A', 99), USER_AVATAR_COLOURS[11]));
// Covers.
$withCover = ['username' => 'x', 'cover_sha' => str_repeat('ef', 20), 'cover_x' => '20.00', 'cover_y' => '70.00', 'cover_zoom' => '1.50'];
$cv = userCoverFor($withCover, $base, $cfgOn);
check('somebody\'s own cover, with its framing', $cv && $cv['url'] === '/api.php?endpoint=user_media&h=' . str_repeat('ef', 8) . '&s=0'
      && $cv['x'] === 20.0 && $cv['zoom'] === 1.5 && $cv['default'] === false);
check('… else the site\'s default cover, else no band at all',
      (userCoverFor(['cover_sha' => null], $base, array_merge($cfgOn, ['cover_default_sha' => str_repeat('12', 20), 'cover_default_zoom' => '2']))['default'] ?? false) === true
      && userCoverFor(['cover_sha' => null], $base, $cfgOn) === null
      && userCoverFor($withCover, $base, array_merge($cfgOn, ['covers_enabled' => '0'])) === null);
$vars = userCoverCssVars($cv);
check('the CSS it paints with is numbers and a hex address', $vars === '--cv-img:url("/api.php?endpoint=user_media&h=' . str_repeat('ef', 8) . '&s=0");--cv-x:20.00%;--cv-y:70.00%;--cv-z:1.50;');
$blk = userCoverStyleBlock('#profile-top', $cv, $cfgOn);
check('the page gets it as a nonce\'d <style>, never a style="" attribute',
      str_starts_with($blk, '<style nonce="' . cspNonce() . '">#profile-top{') && str_contains($blk, '--cv-h:220px') && userCoverStyleBlock('x{}', $cv, $cfgOn) === '');

// ── 11b. beside every name (phase B): the address a row carries, srcset, the site's mark ──────
// Every switch the checks lean on is in the $cfg handed to them, never the database's live one.
$sq = '/api.php?endpoint=user_media&h=' . str_repeat('ab', 8) . '&s=';
$letterA = userAvatarGeneratedUrl('Alice', $base);
check('the three shapes this file writes are recognised, and nothing else',
      (userAvatarUrlKind($sq . '64', $base)['kind'] ?? '') === 'media' && (userAvatarUrlKind($sq . '64', $base)['prefix'] ?? '') === $sq
      && (userAvatarUrlKind($letterA, $base)['kind'] ?? '') === 'letter'
      && (userAvatarUrlKind(userAvatarSiteUrl($base), $base)['kind'] ?? '') === 'site'
      && userAvatarUrlKind('https://evil.example/x.png', $base) === null
      && userAvatarUrlKind('javascript:alert(1)', $base) === null
      && userAvatarUrlKind('/api.php?endpoint=user_media&h=abab&s=64', $base) === null
      && userAvatarUrlKind($sq . '64&id=5', $base) === null
      && userAvatarUrlKind('/other/api.php?endpoint=user_media&h=' . str_repeat('ab', 8) . '&s=64', $base) === null
      && userAvatarUrlKind('/api.php?endpoint=user_avatar_default&l=A&c=12', $base) === null);
check('an address is redrawn at the size it is drawn at: the same crop, twice the CSS size',
      userAvatarSized($sq . '64', 48, $base) === $sq . '128' && userAvatarSized($sq . '256', 20, $base) === $sq . '64'
      && userAvatarSized($letterA, 48, $base) === $letterA && userAvatarSized(userAvatarSiteUrl($base), 48, $base) === userAvatarSiteUrl($base)
      && userAvatarSized('https://evil.example/x.png', 20, $base) === null);
check('srcset: nothing where one square suits every screen (20 px, a drawing)',
      userAvatarSrcset($sq . '64', 20, $base) === '' && userAvatarSrcset($letterA, 64, $base) === ''
      && userAvatarSrcset(userAvatarSiteUrl($base), 48, $base) === '' && userAvatarSrcset('https://evil.example/x', 48, $base) === '');
check('… and each density\'s square, listed at the HIGHEST density it serves, where they differ',
      userAvatarSrcset($sq . '64', 32, $base) === $sq . '64 2x, ' . $sq . '128 3x'
      && userAvatarSrcset($sq . '64', 24, $base) === $sq . '64 2x, ' . $sq . '128 3x'
      && userAvatarSrcset($sq . '128', 48, $base) === $sq . '64 1x, ' . $sq . '128 2x, ' . $sq . '256 3x'
      && userAvatarSrcset($sq . '256', 128, $base) === $sq . '128 1x, ' . $sq . '256 3x',
      userAvatarSrcset($sq . '64', 32, $base) . ' | ' . userAvatarSrcset($sq . '128', 48, $base));
$h32 = userAvatarHtml($withPic, 32, $base, 'row-av', $cfgOn);
check('the element carries that srcset, escaped, and src stays the square userAvatarUrl() picks',
      str_contains($h32, ' src="' . htmlspecialchars(userAvatarUrl($withPic, 32, $base, $cfgOn), ENT_QUOTES, 'UTF-8') . '"')
      && str_contains($h32, ' srcset="' . htmlspecialchars($sq . '64 2x, ' . $sq . '128 3x', ENT_QUOTES, 'UTF-8') . '"')
      && !str_contains(userAvatarHtml($withPic, 20, $base, 'x', $cfgOn), 'srcset='), $h32);
check('… the attributes in the order assets/js/avatar.js sets them, so the two serialise alike',
      (bool)preg_match('/^<img class="[^"]+" src="[^"]+" srcset="[^"]+" data-fallback="[^"]+" width="32" height="32" alt="" loading="lazy" decoding="async">$/', $h32), $h32);
$asRow = ['username' => 'Alice', 'avatar' => userAvatarUrl($withPic, 20, $base, $cfgOn)];
check('a row shaped for the browser (its `avatar` address) draws the SAME element as the account row it came from',
      userAvatarHtml($asRow, 20, $base, 'shout-av', $cfgOn) === userAvatarHtml($withPic, 20, $base, 'shout-av', $cfgOn)
      && userAvatarHtml($asRow, 48, $base, 'm', $cfgOn) === userAvatarHtml($withPic, 48, $base, 'm', $cfgOn));
check('… `avatar` = \'\' is the server saying "nothing here": nothing is drawn',
      userAvatarHtml(['username' => 'Alice', 'avatar' => ''], 20, $base, 'x', $cfgOn) === '');
$foreign = userAvatarHtml(['username' => 'Alice', 'avatar' => 'https://evil.example/x.png'], 20, $base, 'x', $cfgOn);
check('… and an address this file did not write is never drawn: the letter stands in',
      str_contains($foreign, 'src="' . htmlspecialchars($letterA, ENT_QUOTES, 'UTF-8') . '"') && !str_contains($foreign, 'evil'), $foreign);
$mark = userAvatarHtml(['username' => 'Local Tracker', 'avatar' => userAvatarSiteField($base, $cfgOn)], 20, $base, 'shout-av', $cfgOn);
check('the site\'s own mark draws as the same element, with the site name\'s letter behind it',
      str_contains($mark, 'src="/assets/img/favicon.svg"') && str_contains($mark, 'data-fallback="' . htmlspecialchars(userAvatarGeneratedUrl('Local Tracker', $base), ENT_QUOTES, 'UTF-8') . '"')
      && !str_contains($mark, 'srcset='), $mark);
check('for a JSON row: the address while pictures are on, \'\' while they are off',
      userAvatarField($withPic, 32, $base, $cfgOn) === userAvatarUrl($withPic, 32, $base, $cfgOn)
      && userAvatarField($noPic, 32, $base, $cfgOn) === userAvatarGeneratedUrl('bob_the_builder', $base)
      && userAvatarField($withPic, 32, $base, $cfgNoAv) === '' && userAvatarSiteField($base, $cfgOn) === '/assets/img/favicon.svg'
      && userAvatarSiteField($base, $cfgNoAv) === '');
check('… built from the name and the crop only: an id in the row changes nothing, and never reaches the address',
      userAvatarField($withPic + ['id' => 424242], 32, $base, $cfgOn) === userAvatarField(['username' => 'Alice', 'avatar_sha' => str_repeat('ab', 20)], 32, $base, $cfgOn)
      && !str_contains(userAvatarField($withPic + ['id' => 424242], 32, $base, $cfgOn), '424242'));
$tagOn = userAvatarScriptTag('/', $cfgOn);
check('the panel\'s tag for assets/js/avatar.js carries the switch, the base and the default\'s prefix',
      (bool)preg_match('#^<script src="/assets/js/avatar\.js\?v=\d+" data-base="/" data-avatars="1" data-def=""></script>$#', $tagOn)
      && str_contains(userAvatarScriptTag('/', $cfgNoAv), 'data-avatars="0"')
      && str_contains(userAvatarScriptTag('/', $cfgDef), 'data-def="' . str_repeat('cd', 8) . '"'), $tagOn);
foreach (['userAvatarField', 'userAvatarSiteField', 'userAvatarSized', 'userAvatarSrcset', 'userAvatarUrlKind', 'userAvatarHtml'] as $fn) {
    check("$fn() asks no query: no PDO in its signature",
          !array_filter((new ReflectionFunction($fn))->getParameters(), fn($p) => (string)$p->getType() === 'PDO'));
}

// ── 12. the rate limit ────────────────────────────────────────────────────────────────────────
$rlUid = 900000 + random_int(1, 99999);
$rlIp = '203.0.113.' . random_int(1, 250);
$okN = 0;
for ($i = 0; $i < USER_MEDIA_RATE_UPLOADS; $i++) if (userMediaRateAllow('upload', $rlUid, $rlIp)) $okN++;
check('six uploads a minute go through', $okN === 6);
check('… and the seventh does not', !userMediaRateAllow('upload', $rlUid, $rlIp));
check('… and it is PER ADDRESS too: another account from the same address is refused', !userMediaRateAllow('upload', $rlUid + 1, $rlIp));
check('re-crops have their own, looser allowance', userMediaRateAllow('recrop', $rlUid, $rlIp) && USER_MEDIA_RATE_RECROPS > USER_MEDIA_RATE_UPLOADS);

// ── 13. the endpoints are wired, and wired the way the brief says ─────────────────────────────
$api = (string)file_get_contents($root . '/api.php');
foreach (['user_avatar', 'user_cover', 'user_media', 'user_avatar_default', 'admin/user_media'] as $ep) {
    check("route $ep exists", str_contains($api, "'$ep'") && is_file($root . '/api/' . $ep . '.php'));
}
check('the two streams skip the janitors, like sound and shout_emote', (bool)preg_match("/in_array\(\\\$endpoint, \[[^\]]*'user_media'[^\]]*'user_avatar_default'/", $api));
check('the panel endpoint is gated like editing a user', (bool)preg_match("#'admin/user_media'\s*=>\s*'panel\.users\.edit'#", $api));

// ── 14. the preview, against the policy production enforces (1.63.1) ─────────────────────────
// 1.63.0 previewed a picked file through URL.createObjectURL(): a blob: address. The policy the
// live site ENFORCES — the .htaccess fallback, which Apache adds while PHP's own policy only
// reports — allows images from 'self' data: https: and not from blob:, so no picture or cover could
// be chosen there, on the account page or in Settings → Profiles. Nothing local showed it: php -S
// reads no .htaccess and csp_mode ships as report. So: the scripts may not make one, the reader
// must be the data: one, and both policies are read to say why.
$jsCode = static function (string $src): string {
    // Only code counts: a comment is allowed to explain why there is no object URL.
    $src = (string)preg_replace('#/\*.*?\*/#s', '', $src);
    return (string)preg_replace('#(^|[^:\\\\])//[^\n]*#', '$1', $src);
};
foreach (['assets/js/media-editor.js', 'assets/js/admin-profiles.js'] as $f) {
    $code = $jsCode((string)@file_get_contents($root . '/' . $f));
    check("$f feeds no image from an object URL (no createObjectURL, no blob: address)",
          $code !== '' && !preg_match('/\bcreateObjectURL\b|[\'"`]blob:/', $code));
}
$meCode = $jsCode((string)@file_get_contents($root . '/assets/js/media-editor.js'));
check('… the editor reads a picked file with FileReader.readAsDataURL(), into the data: URL the policy allows',
      str_contains($meCode, 'new FileReader()') && str_contains($meCode, '.readAsDataURL('));
check('… and both callers hand it the File to read, rather than an address of their own',
      substr_count($meCode . $jsCode((string)@file_get_contents($root . '/assets/js/admin-profiles.js')), 'file: fresh ? file : null') === 2);
$blobJs = [];
foreach (glob($root . '/assets/js/*.js') ?: [] as $f) {
    if (preg_match('/\bcreateObjectURL\b/', $jsCode((string)file_get_contents($f)))) $blobJs[] = basename($f);
}
// A download link is a navigation, which no fetch directive governs; an <img>, <audio> or worker
// from a blob: address is a load, and the fallback refuses every one of those (default-src 'self').
check('the only object URL left in assets/js is the language export\'s download link',
      $blobJs === ['admin-languages.js'], implode(', ', $blobJs));
$imgSrc = static function (string $policy): array {
    return preg_match('/(?:^|;)\s*img-src\s+([^;]*)/i', $policy, $m) ? preg_split('/\s+/', trim($m[1])) : [];
};
preg_match('/^\s*Header\s+setifempty\s+Content-Security-Policy\s+"([^"]+)"/mi', (string)@file_get_contents($root . '/.htaccess'), $hm);
$htImg = $imgSrc($hm[1] ?? '');
$cfgEnf = array_merge($cfgOn, ['csp_mode' => 'enforce']);
$appImg = array_merge($imgSrc(cspPolicy($cfgEnf, 'public')), $imgSrc(cspPolicy($cfgEnf, 'panel')));
check('the .htaccess fallback (what production enforces) and this application\'s policy both allow data: images',
      in_array('data:', $htImg, true) && in_array('data:', $appImg, true), json_encode([$htImg, $appImg]));
check('… and neither allows blob: ones — the reason for the checks above',
      $htImg !== [] && !in_array('blob:', $htImg, true) && !in_array('blob:', $appImg, true), json_encode([$htImg, $appImg]));

/* ── 1.66.0: the picture and the cover, placed apart ─────────────────────────────────────────────
   One setting (`account_media_side`, 1.64.0) moved both blocks; it is two now. The shipped answers,
   what a junk value reads as, the migration from the old key in each of its three cases, and the
   template echoing each block in the column its own setting names. */
$defs = trackerSchemaDefaultSettings();
check('the picture ships on the left and the cover on the right, and the old key ships no more',
      ($defs['account_picture_side'] ?? null) === 'left' && ($defs['account_cover_side'] ?? null) === 'right'
      && !array_key_exists('account_media_side', $defs));
check('each side reads anything it does not know as its own shipped answer',
      accountPictureSide([]) === 'left' && accountPictureSide(['account_picture_side' => 'right']) === 'right'
      && accountPictureSide(['account_picture_side' => 'middle']) === 'left'
      && accountCoverSide([]) === 'right' && accountCoverSide(['account_cover_side' => 'left']) === 'left'
      && accountCoverSide(['account_cover_side' => 'middle']) === 'right'
      && !function_exists('accountMediaSide'));
$saveSrc = (string)file_get_contents($root . '/api/admin/save_settings.php');
check('both are saveable and coerced on the way in; the old key is not saveable at all',
      str_contains($saveSrc, "'account_picture_side', 'account_cover_side'")
      && str_contains($saveSrc, "\$data['account_picture_side'] = 'left';") && str_contains($saveSrc, "\$data['account_cover_side'] = 'right';")
      && !str_contains($saveSrc, "'account_media_side'"));
$catKw = function_exists('settingsCatalogKeywords') ? settingsCatalogKeywords() : null;
if ($catKw === null) { require_once $root . '/includes/settings_catalog.php'; $catKw = settingsCatalogKeywords(); }
$setTpl = (string)file_get_contents($root . '/templates/admin/settings.php');
check('two controls on the Settings page, each with its own label, in the search catalogue — and the old one gone from both',
      isset($catKw['account_picture_side'], $catKw['account_cover_side']) && !isset($catKw['account_media_side'])
      && str_contains($setTpl, 'name="account_picture_side"') && str_contains($setTpl, 'name="account_cover_side"')
      && str_contains($setTpl, "_h('settings.account_picture_side')") && str_contains($setTpl, "_h('settings.account_cover_side')")
      && !str_contains($setTpl, 'account_media_side"'));

// The migration, asked of itself. What the three keys hold now is put back afterwards.
$sideKeys = ['account_media_side', 'account_picture_side', 'account_cover_side'];
$sidesWere = [];
foreach ($sideKeys as $k) {
    $st = $db->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $st->execute([$k]);
    $sidesWere[$k] = $st->fetchColumn();
}
$sideNow = function () use ($db, $sideKeys): array {
    $out = [];
    foreach ($sideKeys as $k) {
        $st = $db->prepare("SELECT `value` FROM settings WHERE `key` = ?");
        $st->execute([$k]);
        $v = $st->fetchColumn();
        $out[$k] = $v === false ? null : (string)$v;
    }
    return $out;
};
$runSides = function (?string $old, array $existing = []) use ($db) {
    $db->exec("DELETE FROM settings WHERE `key` IN ('account_media_side', 'account_picture_side', 'account_cover_side', 'schema_once_v72_account_sides')");
    if ($old !== null) setSetting($db, 'account_media_side', $old);
    foreach ($existing as $k => $v) setSetting($db, $k, $v);
    trackerSchemaDataMigrations($db, getSettings($db, true));
};
$fillDefaults = function () use ($db) {
    // What ensureSchema() does right after the data migrations: the defaults, INSERT IGNORE.
    $ins = $db->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
    foreach (['account_picture_side', 'account_cover_side'] as $k) $ins->execute([$k, trackerSchemaDefaultSettings()[$k]]);
};
try {
    $runSides('left');
    check('a stored "left" (somebody chose it; "right" was the default) seeds BOTH blocks on the left, and the old key goes',
          $sideNow() === ['account_media_side' => null, 'account_picture_side' => 'left', 'account_cover_side' => 'left'], json_encode($sideNow()));
    $runSides('right');
    $afterStep = $sideNow();
    $fillDefaults();
    check('a stored "right" is the shipped default, not a choice: it seeds nothing, and the new defaults put the picture left and the cover right',
          $afterStep === ['account_media_side' => null, 'account_picture_side' => null, 'account_cover_side' => null]
          && $sideNow() === ['account_media_side' => null, 'account_picture_side' => 'left', 'account_cover_side' => 'right'],
          json_encode([$afterStep, $sideNow()]));
    $runSides('left', ['account_picture_side' => 'right', 'account_cover_side' => 'left']);
    check('a key an install already has is never overwritten by the seeding',
          $sideNow() === ['account_media_side' => null, 'account_picture_side' => 'right', 'account_cover_side' => 'left'], json_encode($sideNow()));
    $runSides(null);
    $fillDefaults();
    check('with no old key at all the defaults are simply what arrives',
          $sideNow() === ['account_media_side' => null, 'account_picture_side' => 'left', 'account_cover_side' => 'right']);
} finally {
    $db->exec("DELETE FROM settings WHERE `key` IN ('account_media_side', 'account_picture_side', 'account_cover_side')");
    foreach ($sidesWere as $k => $v) if ($v !== false) setSetting($db, $k, (string)$v);
    $db->exec("INSERT IGNORE INTO settings (`key`, `value`) VALUES ('schema_once_v72_account_sides', '" . time() . "')");
}

// The template: each block buffered on its own and echoed at exactly one of two places, the left
// one AFTER Account security and the right one under the privacy answers; nothing wraps both.
$acc = (string)file_get_contents($root . '/templates/pages/account.php');
$secAt = strpos($acc, 'id="acc-security"');
$leftPic = strpos($acc, "if (\$accPicSide === 'left') echo \$accAvatarHtml;");
$leftCov = strpos($acc, "if (\$accCovSide === 'left') echo \$accCoverHtml;");
$card2 = strpos($acc, '<h2><?= _h(\'account.groups\') ?></h2>');
$privAt = strpos($acc, 'id="acc-privacy"');
$rightPic = strpos($acc, "if (\$accPicSide === 'right') echo \$accAvatarHtml;");
$rightCov = strpos($acc, "if (\$accCovSide === 'right') echo \$accCoverHtml;");
check('on the left each block sits in the first card after Account security, picture first',
      $secAt !== false && $leftPic !== false && $leftCov !== false && $card2 !== false
      && $secAt < $leftPic && $leftPic < $leftCov && $leftCov < $card2);
check('on the right each sits in the second card under the privacy answers, picture first',
      $privAt !== false && $rightPic !== false && $rightCov !== false && $card2 < $privAt && $privAt < $rightPic && $rightPic < $rightCov);
check('the two blocks are two buffers, with the editor\'s data printed once and no wrapper round both',
      substr_count($acc, 'id="acc-media-data"') === 1 && !str_contains($acc, '<div id="acc-media">')
      && str_contains($acc, '$accAvatarHtml = (string)ob_get_clean();') && str_contains($acc, '$accCoverHtml = (string)ob_get_clean();'));
check('… and the editor wires either block by its own id, not by a wrapper',
      str_contains((string)file_get_contents($root . '/assets/js/media-editor.js'),
                   "if (!data || !(document.getElementById('acc-avatar') || document.getElementById('acc-cover'))) return;"));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
