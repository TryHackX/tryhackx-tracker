<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/auth.php',
          'includes/lang.php', 'includes/settings_catalog.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
$cfgI = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '0', 'site_url' => 'https://img.test/',
    'images_max_mb' => '8', 'images_max_edge' => '2048', 'images_max_mpx' => '24', 'images_webp_quality' => '82',
    'images_per_day' => '60', 'images_quota_mb' => '250', 'images_unused_hours' => '48']);

$names = ['imgtest_a', 'imgtest_b'];
$in = implode(',', array_fill(0, count($names), '?'));
$gone = $db->prepare("SELECT id FROM users WHERE username IN ($in)");
$gone->execute($names);
$oldIds = array_map('intval', $gone->fetchAll(PDO::FETCH_COLUMN));
$wipe = function (array $ids) use ($db): void {
    if (!$ids) return;
    $q = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id FROM images WHERE user_id IN ($q)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) imagesDelete($db, (int)$id);
    $db->prepare("DELETE FROM user_group_members WHERE user_id IN ($q)")->execute($ids);
    $db->prepare("DELETE FROM users WHERE id IN ($q)")->execute($ids);
};
$wipe($oldIds);
$db->exec("DELETE FROM page_content WHERE page = 'imgtest'");
$uid = [];
foreach ($names as $name) {
    userCreate($db, $cfgI, $name, $name . '@example.org', 'SmokePass123!', '127.0.0.1');
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    $uid[$name] = (int)($st->fetchColumn() ?: 0);
}
$db->prepare("UPDATE users SET email_verified = 1 WHERE username IN ($in)")->execute($names);
$A = $uid['imgtest_a'];
$B = $uid['imgtest_b'];
$stale = $db->prepare("SELECT id FROM images WHERE user_id IN (?, ?)");
$stale->execute([$A, $B]);
foreach ($stale->fetchAll(PDO::FETCH_COLUMN) as $staleId) imagesDelete($db, (int)$staleId);
$forgetDay = fn() => $db->prepare("DELETE FROM image_uploads WHERE user_id IN (?, ?)")->execute([$A, $B]);
$forgetDay();
check('the two test accounts exist', $A > 0 && $B > 0, json_encode($uid));

$pic = function (int $w, int $h, string $type = 'png', bool $noise = false): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 200, 50, 50));
    imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), max(1, intdiv($w, 3)), max(1, intdiv($h, 3)), imagecolorallocate($im, 20, 200, 90));
    imagesetpixel($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    if ($noise) {
        for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) imagesetpixel($im, $x, $y, random_int(0, 0xFFFFFF));
    }
    ob_start();
    if ($type === 'jpeg') imagejpeg($im, null, 90); elseif ($type === 'gif') imagegif($im); else imagepng($im);
    return (string)ob_get_clean();
};
$gif1 = (function (): string {
    $im = imagecreate(40, 30);
    imagecolorallocate($im, 10, 120, 200);
    imagefilledrectangle($im, 5, 5, 20, 20, imagecolorallocate($im, 250, 250, random_int(0, 255)));
    ob_start();
    imagegif($im);
    return (string)ob_get_clean();
})();
$packed = ord($gif1[10]);
$gct = ($packed & 0x80) ? 3 * (2 << ($packed & 7)) : 0;
$rest = (string)preg_replace('/^\x21\xF9\x04.{4}\x00/s', '', substr($gif1, 13 + $gct));
$block = substr($rest, 0, -1);
$gce = "\x21\xF9\x04\x00\x0A\x00\x00\x00";
$anim = substr($gif1, 0, 13 + $gct) . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00" . $gce . $block . $gce . $block . "\x3B";

check('schema 96: the two tables, the defaults and the grant', TRACKER_SCHEMA_VERSION >= 96
      && schemaTableExists($db, 'images') && schemaTableExists($db, 'image_blobs')
      && schemaColumnType($db, 'image_blobs', 'data_page') === 'mediumblob' && schemaColumnType($db, 'image_blobs', 'data_full') === 'mediumblob'
      && (trackerSchemaDefaultSettings()['images_max_mb'] ?? null) === '8' && (trackerSchemaDefaultSettings()['images_max_edge'] ?? null) === '2048'
      && (trackerSchemaDefaultSettings()['images_max_mpx'] ?? null) === '24' && (trackerSchemaDefaultSettings()['images_webp_quality'] ?? null) === '82'
      && (trackerSchemaDefaultSettings()['images_per_day'] ?? null) === '60' && (trackerSchemaDefaultSettings()['images_quota_mb'] ?? null) === '250'
      && (trackerSchemaDefaultSettings()['images_unused_hours'] ?? null) === '48');
$memberPerms = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn(), true) ?: [];
$guestPerms = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'guest'")->fetchColumn(), true) ?: [];
check('content.images: registered, a member has it, a guest does not, and without accounts nobody does',
      isset(userPermissionList()['content.images']) && !empty($memberPerms['content.images']) && empty($guestPerms['content.images'])
      && in_array('content.images', userGroupPresets()['member']['perms'], true) && userLegacyDefault('content.images') === false
      && langFor('pl', 'perm.content.images') === 'Wgrywanie obrazów i wstawianie ich do pisanych opisów');
check('a member may upload, nobody without an account, and nothing with accounts switched off',
      imagesMayUpload($db, $cfgI, userFindById($db, $A)) && !imagesMayUpload($db, $cfgI, null)
      && !imagesMayUpload($db, array_merge($cfgI, ['users_enabled' => '0']), userFindById($db, $A)));

$big = imagesProcess($pic(2400, 1200), $cfgI);
check('a large picture: the full size bounded to the longest edge, a page copy of about 960, both WebP and nothing from the camera',
      !empty($big['ok']) && $big['mime'] === 'image/webp' && $big['full_w'] === 2048 && $big['full_h'] === 1024 && $big['w'] === 960 && $big['h'] === 480
      && str_starts_with($big['page'], 'RIFF') && str_starts_with((string)$big['full'], 'RIFF') && strlen($big['page']) < strlen((string)$big['full'])
      && !str_contains($big['page'] . $big['full'], 'Exif'), json_encode(array_diff_key($big, ['page' => 1, 'full' => 1, 'im' => 1])));
$small = imagesProcess($pic(800, 600, 'jpeg'), $cfgI);
check('a picture smaller than a page is kept once, at its own size', !empty($small['ok']) && $small['w'] === 800 && $small['h'] === 600
      && $small['full'] === null && $small['full_w'] === 800);
$ga = imagesProcess($anim, $cfgI);
check('an animated GIF stays the animated GIF it was', imagesGifFrames($anim) === 2 && !empty($ga['ok']) && $ga['mime'] === 'image/gif'
      && $ga['animated'] === 1 && $ga['page'] === $anim && $ga['w'] === 40 && $ga['h'] === 30, json_encode(array_diff_key($ga, ['page' => 1, 'full' => 1])));
$gs = imagesProcess($gif1, $cfgI);
check('a GIF of one frame becomes a WebP like any picture', !empty($gs['ok']) && $gs['mime'] === 'image/webp' && imagesGifFrames($gif1) <= 1);
$r1 = imagesStore($db, $cfgI, $A, 'hello, this is not a picture at all', 'x.png');
$r2 = imagesStore($db, $cfgI, $A, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>' . str_repeat(' ', 40), 'x.svg');
$r3 = imagesStore($db, array_merge($cfgI, ['images_max_mpx' => '4']), $A, $pic(3000, 1400), 'big.png');
$noisy = $pic(900, 700, 'png', true);
$r4 = imagesStore($db, array_merge($cfgI, ['images_max_mb' => '1']), $A, $noisy, 'noise.png');
check('refused with a code and a sentence: not a picture, an SVG, too many pixels, a file over the size',
      ($r1['code'] ?? '') === 'bad_type' && ($r2['code'] ?? '') === 'bad_type' && ($r3['code'] ?? '') === 'too_many_pixels'
      && strlen($noisy) > 1048576 && ($r4['code'] ?? '') === 'too_big' && is_string($r1['message'] ?? null) && ($r3['status'] ?? 0) === 413,
      json_encode([$r1['code'] ?? null, $r2['code'] ?? null, $r3['code'] ?? null, $r4['code'] ?? null, strlen($noisy)]));

$bytesA = $pic(2400, 1200);
$sa = imagesStore($db, $cfgI, $A, $bytesA, "C:\\fakepath\\holiday\x07.png");
$sa2 = imagesStore($db, $cfgI, $A, $bytesA, 'again.png');
$sb = imagesStore($db, $cfgI, $B, $bytesA, 'mine.png');
$blobs = $db->prepare("SELECT COUNT(*) FROM image_blobs WHERE sha256 = ?");
$blobs->execute([hash('sha256', $bytesA)]);
$ia = (int)($sa['row']['id'] ?? 0);
$ib = (int)($sb['row']['id'] ?? 0);
check('the same file is stored once: the same person gets the same picture back, another person a picture of their own on the same bytes',
      !empty($sa['ok']) && ($sa2['row']['id'] ?? 0) === $sa['row']['id'] && !empty($sa2['existing']) && $ib > 0 && $ib !== $ia
      && (int)$blobs->fetchColumn() === 1 && ($sa['row']['name'] ?? '') === 'holiday.png', json_encode([$sa['row']['name'] ?? null]));
$out = imagesRowOut($cfgI, $sa['row']);
check('the answer a picture is given in: its address with its size in it, the full one, and nothing else',
      array_keys($out) === ['id', 'url', 'full_url', 'w', 'h', 'full_w', 'full_h', 'bytes', 'name', 'created_at', 'used', 'animated']
      && $out['url'] === 'https://img.test/i/' . $ia . '-960x480.webp' && $out['full_url'] === 'https://img.test/i/' . $ia . '-2048x1024.webp'
      && $out['used'] === false && $out['animated'] === false, json_encode($out));
$list = imagesList($db, $cfgI, $A, 1);
check('"My images": the newest first, with the limits the page shows',
      array_keys($list) === ['rows', 'total', 'page', 'per_page', 'limits'] && $list['total'] === 1 && ($list['rows'][0]['id'] ?? 0) === $ia
      && array_keys($list['limits']) === ['max_bytes', 'max_edge', 'max_mpx', 'per_day', 'today', 'quota_bytes', 'used_bytes']
      && $list['limits']['today'] === 1 && $list['limits']['quota_bytes'] === 250 * 1048576 && $list['limits']['used_bytes'] > 0,
      json_encode([$list['total'], array_column($list['rows'], 'id'), array_column($list['rows'], 'name'), $ia, $list['limits']]));

$day = imagesStore($db, array_merge($cfgI, ['images_per_day' => '1']), $A, $pic(300, 200), 'c.png');
$fakeSha = str_repeat('f', 63) . (string)random_int(0, 9);
$db->prepare("DELETE FROM image_blobs WHERE sha256 = ?")->execute([$fakeSha]);
$db->prepare("INSERT INTO image_blobs (sha256, mime, animated, w, h, full_w, full_h, bytes, data_page) VALUES (?, 'image/webp', 0, 1, 1, 1, 1, 2000000, 'x')")->execute([$fakeSha]);
$db->prepare("INSERT INTO images (user_id, blob_sha, name) VALUES (?, ?, 'heavy')")->execute([$B, $fakeSha]);
$quota = imagesStore($db, array_merge($cfgI, ['images_quota_mb' => '1']), $B, $pic(300, 200), 'd.png');
$p1 = imagesStore($db, array_merge($cfgI, ['images_per_day' => '2']), $A, $pic(200, 100), 'p1.png');
$p1Gone = imagesDelete($db, (int)($p1['row']['id'] ?? 0));
$p2 = imagesStore($db, array_merge($cfgI, ['images_per_day' => '2']), $A, $pic(200, 120), 'p2.png');
check('the day counts uploads, not pictures kept: deleting one does not give the upload back',
      !empty($p1['ok']) && $p1Gone && ($p2['code'] ?? '') === 'daily_limit' && imagesUsage($db, $A)['today'] === 2, json_encode([$p2['code'] ?? null, imagesUsage($db, $A)]));
$db->prepare("UPDATE image_uploads SET created_at = NOW() - INTERVAL 3 DAY WHERE user_id = ?")->execute([$B]);
$keptB = (int)$db->query("SELECT COUNT(*) FROM image_uploads WHERE user_id = " . $B)->fetchColumn();
check('the day\'s uploads and the account\'s space are limits, said in numbers', ($day['code'] ?? '') === 'daily_limit' && ($day['status'] ?? 0) === 429
      && str_contains((string)($day['message'] ?? ''), '1') && ($quota['code'] ?? '') === 'quota' && str_contains((string)($quota['message'] ?? ''), '1 MB'),
      json_encode([$day['message'] ?? null, $quota['message'] ?? null]));

$p = imagesRow($db, $ia);
check('a picture nobody has published is its uploader\'s and the moderators\', a 404 to anybody else',
      imagesServe($db, $ia, 960, 480, 'webp', false, null, false) === null && imagesServe($db, $ia, 960, 480, 'webp', false, $B, false) === null
      && ($own = imagesServe($db, $ia, 960, 480, 'webp', false, $A, false)) !== null && $own['public'] === false && str_starts_with($own['data'], 'RIFF')
      && imagesServe($db, $ia, 960, 480, 'webp', false, null, true) !== null);
check('only its own two sizes and its own type answer', imagesServe($db, $ia, 961, 480, 'webp', false, $A, false) === null
      && imagesServe($db, $ia, 960, 480, 'gif', false, $A, false) === null
      && strlen((string)(imagesServe($db, $ia, 2048, 1024, 'webp', false, $A, false)['data'] ?? '')) > strlen($own['data'])
      && (imagesServe($db, $ia, 960, 480, 'webp', true, $A, false)['data'] ?? '') === (imagesServe($db, $ia, 2048, 1024, 'webp', false, $A, false)['data'] ?? 'x'));
$db->prepare("UPDATE images SET checked_at = NULL, public_at = NULL WHERE id = ?")->execute([$ia]);
$first = imagesServe($db, $ia, 960, 480, 'webp', false, null, false);
$db->prepare("INSERT INTO page_content (page, lang, format, body, enabled) VALUES ('imgtest', 'en', 'bbcode', ?, 1)")
   ->execute(['[img]https://img.test/i/' . $ia . '-960x480.webp[/img]']);
$second = imagesServe($db, $ia, 960, 480, 'webp', false, null, false);
$db->prepare("UPDATE images SET checked_at = NOW() - INTERVAL 11 MINUTE WHERE id = ?")->execute([$ia]);
$third = imagesServe($db, $ia, 960, 480, 'webp', false, null, false);
check('published once, a picture is everybody\'s: found when it is asked for, and asked for at most every ten minutes by strangers',
      $first === null && $second === null && $third !== null && $third['public'] === true && imagesRow($db, $ia)['public_at'] !== null
      && imagesRowOut($cfgI, imagesRow($db, $ia))['used'] === true);
check('a reference counts for "in use": a page, also a page that is not live yet', imagesReferenced($db, $ia, false) && imagesReferenced($db, $ia, true)
      && !imagesReferenced($db, $ib, false));
$db->exec("UPDATE page_content SET enabled = 0 WHERE page = 'imgtest'");
check('… and an unpublished page keeps a picture alive without making it public', imagesReferenced($db, $ia, false) && !imagesReferenced($db, $ia, true));

$old = imagesStore($db, $cfgI, $B, $pic(320, 240), 'old.png');
$io = (int)($old['row']['id'] ?? 0);
$db->prepare("UPDATE images SET created_at = NOW() - INTERVAL 49 HOUR WHERE id IN (?, ?)")->execute([$io, $ia]);
$swept = imagesSweep($db, $cfgI, true);
check('the sweep removes a picture nothing refers to once it is older than the setting, and keeps a used one',
      $swept >= 1 && imagesRow($db, $io) === null && imagesRow($db, $ia) !== null, (string)$swept);
check('… and runs at most once an hour', imagesSweep($db, array_merge($cfgI, ['images_swept_at' => (string)time()])) === 0);
check('… and forgets the uploads counted more than two days ago', $keptB > 0
      && (int)$db->query("SELECT COUNT(*) FROM image_uploads WHERE user_id = " . $B . " AND created_at < NOW() - INTERVAL 2 DAY")->fetchColumn() === 0);
check('the day\'s count lives in the database (the request limiter keeps an hour), and an account\'s deletion takes it along',
      schemaTableExists($db, 'image_uploads') && str_contains($src('includes/users.php'), "\$del(\"DELETE FROM image_uploads WHERE user_id = ?\", [\$userId], 'image_uploads');")
      && !str_contains($src('includes/images.php'), 'rateLimitHits'));

check('deleting the last picture of a file deletes the file; one still held by another person keeps it',
      imagesDelete($db, $ib) && imagesRow($db, $ib) === null
      && (function () use ($db, $bytesA) { $st = $db->prepare("SELECT COUNT(*) FROM image_blobs WHERE sha256 = ?"); $st->execute([hash('sha256', $bytesA)]); return (int)$st->fetchColumn(); })() === 1);

$cfgR = ['site_url' => 'https://img.test/'];
$h1 = richtextRender('[img]https://img.test/i/5-960x540.webp[/img]', 'bbcode', $cfgR);
$h2 = richtextRender('![x](https://img.test/i/7-300x200.gif)', 'markdown', $cfgR);
$h3 = richtextRender('[img]https://other.example/i/5-960x540.webp[/img]', 'bbcode', $cfgR);
$h4 = richtextRender('[quote][img]https://img.test/i/5-960x540.webp[/img][/quote]', 'bbcode', $cfgR);
$h5 = richtextRender('[thxlabels=2][thxrow][img]https://img.test/i/8-600x900.webp[/img][img]https://img.test/i/9-500x300.webp[/img][/thxrow][/thxlabels]', 'bbcode', $cfgR);
check('the renderer gives this site\'s pictures their size (no jump while loading), no taller than a description shows one, and the full one for the viewer; nobody else\'s',
      str_contains($h1, 'width="924" height="520" data-full="https://img.test/i/5-960x540.webp?full=1" src="https://img.test/i/5-960x540.webp"')
      && str_contains($h2, 'width="300" height="200"') && !str_contains($h3, 'width=') && substr_count($h4, 'width=') === 1
      && str_contains($h5, '<img class="rt-img rt-label" loading="lazy" referrerpolicy="no-referrer" alt="" width="347" height="520" data-full="https://img.test/i/8-600x900.webp?full=1"')
      && str_contains($h5, 'width="500" height="300"'), $h1 . ' | ' . $h5);
check('a picture with its size given is never stretched: fitted inside its box in a description, its own width in a shout',
      str_contains($src('assets/css/style.css'), '.rt-body .rt-img { max-width: 100%; max-height: 520px; height: auto; border-radius: 4px; margin: 0.5rem 0; object-fit: contain; }')
      && str_contains($src('assets/css/style.css'), '.shout-body.rt-body .rt-img { max-height: 3.5rem; margin: 0; vertical-align: text-bottom; width: auto; }')
      && RICHTEXT_IMG_MAX_H === 520);

check('the address is routed before the catch-all, and the stream is the avatars\' kind of stream',
      str_contains($src('.htaccess'), "RewriteRule ^i/([0-9]+)-([0-9]+)x([0-9]+)\\.(webp|gif)$ api.php?endpoint=image&id=$1&w=$2&h=$3&ext=$4 [QSA,L]")
      && strpos($src('.htaccess'), 'endpoint=image&') < strpos($src('.htaccess'), 'RewriteRule ^(.*)$ index.php')
      && str_contains($src('api.php'), "'image'                      => 'api/image.php'") && str_contains($src('api.php'), "'images'                     => 'api/images.php'")
      && str_contains($src('api.php'), "'user_media', 'image', 'user_avatar_default'")
      && str_contains($src('api/image.php'), "header(\"Content-Security-Policy: default-src 'none'\");")
      && str_contains($src('api/image.php'), "'public, max-age=31536000, immutable' : 'private, no-store'")
      && str_contains($src('api/image.php'), "header('X-Content-Type-Options: nosniff');"));
check('uploads ask for the token and the limiter; a delete asks for the token and refuses a picture in use',
      str_contains($src('api/images.php'), "verifyCsrfToken((string)(\$_POST['csrf_token'] ?? ''))")
      && str_contains($src('api/images.php'), "rateLimitAllow('imgupload', 'u' . \$uid, IMAGES_RATE_PER_MINUTE, 60)")
      && str_contains($src('api/images.php'), "verifyCsrfToken((string)(\$input['csrf_token'] ?? ''))")
      && str_contains($src('api/images.php'), "imagesReferenced(\$db, (int)\$row['id'], false)) jsonResponse(['error' => __('api.images.in_use'), 'code' => 'in_use'], 409);"));
check('the pages load the library, the janitor sweeps, and the viewer opens the full picture',
      str_contains($src('index.php'), "require_once __DIR__ . '/includes/images.php';") && str_contains($src('tools/janitor.php'), "imagesSweep(\$db, \$cfg)")
      && str_contains($src('assets/js/app.js'), 'src: im.dataset.full || im.currentSrc || im.src'));
$tpl = $src('templates/admin/settings.php');
$save = $src('api/admin/save_settings.php');
$kw = settingsCatalogKeywords();
$miss = [];
foreach (['images_max_mb', 'images_max_edge', 'images_max_mpx', 'images_webp_quality', 'images_per_day', 'images_quota_mb', 'images_unused_hours'] as $k) {
    if (!str_contains($tpl, 'name="' . $k . '"')) $miss[] = "$k page";
    if (!preg_match("/'" . $k . "' => \\[\\d+, \\d+, \\d+\\]/", $save) || substr_count($save, "'" . $k . "'") < 2) $miss[] = "$k save";
    if (!isset($kw[$k])) $miss[] = "$k catalogue";
}
check('the seven picture settings: on the page in their own section of Formatting & images, saved within bounds, found by the search',
      $miss === [] && str_contains($tpl, 'id="section-images" data-group="formatting"'), implode(', ', $miss));

userDeleteCascade($db, $A, $cfgI);
check('a deleted account\'s pictures stay with the texts that use them, owned by nobody',imagesRow($db, $ia) !== null && imagesRow($db, $ia)['user_id'] === null);
imagesDelete($db, $ia);
$db->prepare("INSERT INTO images (user_id, blob_sha, name) VALUES (?, ?, 'no file')")->execute([$B, str_repeat('e', 64)]);
$noFile = (int)$db->lastInsertId();
check('a picture whose file is gone is still deleted, not left behind', imagesDelete($db, $noFile)
      && (int)$db->query("SELECT COUNT(*) FROM images WHERE id = " . $noFile)->fetchColumn() === 0);
$db->exec("DELETE FROM page_content WHERE page = 'imgtest'");
$wipe([$A, $B]);
$forgetDay();
$fakeLeft = $db->prepare("SELECT COUNT(*) FROM image_blobs WHERE sha256 = ?");
$fakeLeft->execute([$fakeSha]);
$left = $db->prepare("SELECT COUNT(*) FROM image_blobs WHERE sha256 = ?");
$left->execute([hash('sha256', $bytesA)]);
check('cleanup: the accounts, their pictures and the files only they held are gone', (int)$left->fetchColumn() === 0 && (int)$fakeLeft->fetchColumn() === 0);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
