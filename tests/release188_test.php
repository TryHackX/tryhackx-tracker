<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/sounds.php',
          'includes/shout.php', 'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/icons.php',
          'includes/api_auth.php', 'includes/settings_catalog.php', 'includes/reputation.php', 'includes/lang.php',
          'includes/lists.php', 'includes/profilebio.php', 'includes/reports.php', 'includes/moderation.php',
          'includes/facts.php', 'includes/tags.php', 'includes/thumbs.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');

$enumOk = schemaColumnType($db, 'image_refs', 'src') === "enum('wl','idx','edit','cm','page','pm','shout','bio','list','warn','crep','mrep')"
       && schemaColumnType($db, 'image_remote_refs', 'src') === "enum('wl','idx','edit','cm','page','pm','shout','bio','list','warn','crep','mrep')";
$member = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn(), true) ?: [];
check('schema 112: the places as sources of a picture, their settings, and the two permissions for members', TRACKER_SCHEMA_VERSION >= 112 && $enumOk
      && ($cfg['images_in_pm'] ?? '') === '4' && ($cfg['images_in_shout'] ?? '') === '1' && ($cfg['images_in_bio'] ?? '') === '2'
      && ($cfg['images_in_list'] ?? '') === '4' && ($cfg['images_in_mod'] ?? '') === '4'
      && !empty($member['images.upload']) && !empty($member['images.link']) && userLegacyDefault('images.upload') === false);

$kept = [];
foreach (['site_url'] as $k) {
    $st = $db->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $st->execute([$k]);
    $kept[$k] = $st->fetchColumn();
}
if (!preg_match('#^https?://#', (string)$kept['site_url'])) $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('site_url', 'http://tracker.test/')")->execute();
$cfgS = ['users_enabled' => '1'] + getSettings($db, true);
imagesRefsEnsure($db, $cfgS);
$made = [];
$users = [];
$rows = ['shouts' => [], 'user_lists' => [], 'user_warnings' => [], 'message_threads' => []];
$tidy = function () use ($db, &$made, &$users, &$rows, $kept): void {
    foreach ($made as $id) imagesDelete($db, $id);
    foreach ($rows as $tbl => $ids) {
        foreach ($ids as $id) $db->prepare("DELETE FROM $tbl WHERE id = ?")->execute([$id]);
    }
    foreach ($rows['message_threads'] as $id) $db->prepare("DELETE FROM user_messages WHERE thread_id = ?")->execute([$id]);
    foreach ($users as $id) {
        $db->prepare("DELETE FROM user_friends WHERE user_id = ? OR friend_id = ?")->execute([$id, $id]);
        userDeleteCascade($db, $id);
    }
    foreach ($kept as $k => $v) {
        if ($v === false) $db->prepare("DELETE FROM settings WHERE `key` = ?")->execute([$k]);
        else $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES (?, ?)")->execute([$k, $v]);
    }
};
register_shutdown_function($tidy);
$mk = function (string $name) use ($db, $cfgS, &$users): array {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    $old = (int)$st->fetchColumn();
    if ($old > 0) userDeleteCascade($db, $old);
    $r = userCreate($db, $cfgS, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$id]);
    userPermissionsForget($id);
    $users[] = $id;
    return userFindById($db, $id) ?? ['id' => $id];
};
$A = $mk('imgplace_a');
$B = $mk('imgplace_b');
$C = $mk('imgplace_c');
$pic = function (int $w, int $h, array $rgb): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
    imagesetpixel($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    ob_start();
    imagepng($im);
    return (string)ob_get_clean();
};
$store = function (?int $uid, string $source, array $rgb) use ($db, $cfgS, $pic, &$made): array {
    $r = imagesStore($db, $cfgS, $uid, $pic(320, 200, $rgb), 'p.png', $source, $source === 'copy' ? 'https://forum.test/p' . random_int(1, 1e6) . '.png' : null);
    $made[] = (int)$r['row']['id'];
    return imagesRow($db, (int)$r['row']['id']);
};
$url = fn(array $r): string => imagesUrl($cfgS, (int)$r['id'], (int)$r['w'], (int)$r['h'], imagesExt($r));
$a1 = $store((int)$A['id'], 'upload', [200, 50, 50]);
$a2 = $store((int)$A['id'], 'upload', [50, 200, 50]);
$b1 = $store((int)$B['id'], 'upload', [50, 50, 200]);
$cp = $store(null, 'copy', [120, 120, 120]);
$a3 = $store((int)$A['id'], 'upload', [220, 220, 40]);
check('three members and their pictures', (int)$A['id'] > 0 && (int)$B['id'] > 0 && (int)$C['id'] > 0 && $a1 && $a2 && $b1 && $cp);

check('the limit per place: Settings within 0–10, a default where nothing is set; uploads for members, never in the panel\'s texts',
      imagesPlaceMax(['images_in_pm' => '25'], 'pm') === 10 && imagesPlaceMax([], 'shout') === 1 && imagesPlaceMax(['images_in_bio' => '0'], 'bio') === 0
      && imagesPlaceMax($cfgS, 'nowhere') === 0 && array_keys(imagesPlacesFor($db, $cfgS, $A)) === ['pm', 'shout', 'bio', 'list']
      && !array_key_exists('shout', imagesPlacesFor($db, ['images_in_shout' => '0'] + $cfgS, $A)) && imagesPlaceMayUpload($db, $cfgS, null, 'pm') === false);

$bb = fn(array ...$imgs): string => implode(' ', array_map(fn($r) => '[img]' . $url($r) . '[/img]', $imgs));
check('a member\'s own pictures pass; a picture from another site is left to the place\'s old rules (not counted here)',
      imagesPlaceCheck($db, $cfgS, 'pm', 'look ' . $bb($a1, $a2), 'bbcode', $A) === null
      && imagesPlaceCheck($db, $cfgS, 'pm', '[img]https://elsewhere.example/x.png[/img] [img]https://elsewhere.example/y.png[/img]', 'bbcode', $A) === null
      && imagesPlaceCheck($db, $cfgS, 'shout', '![x](https://elsewhere.example/x.png) ' . $bb($a1), 'markdown', $A) === null);
check('… no more than the place allows, none where it is off, and an old picture kept by an edit is not counted again',
      imagesPlaceCheck($db, $cfgS, 'shout', $bb($a1, $a2), 'bbcode', $A) === __('api.images.place_too_many', ['n' => 2, 'max' => 1])
      && imagesPlaceCheck($db, ['images_in_pm' => '0'] + $cfgS, 'pm', $bb($a1), 'bbcode', $A) === __('api.images.place_off')
      && imagesPlaceCheck($db, $cfgS, 'shout', $bb($a1, $a2), 'bbcode', $A, $bb($a1, $a2)) === null);
check('somebody else\'s private picture is refused; a picture already public passes with images.link',
      imagesPlaceCheck($db, $cfgS, 'pm', $bb($b1), 'bbcode', $A) === __('api.images.place_not_yours')
      && imagesPlaceCheck($db, $cfgS, 'pm', $bb($cp), 'bbcode', $A) === null);

$html = imagesPlaceHtml($cfgS, richtextRender($bb($a1) . ' [img]https://elsewhere.example/x.png[/img]', 'bbcode', $cfgS, true));
check('a moderator\'s text drawn: this tracker\'s picture as a picture, another site\'s (an old row) as a link',
      str_contains($html, 'src="' . htmlspecialchars($url($a1), ENT_QUOTES) . '"') && !str_contains($html, 'src="https://elsewhere.example')
      && str_contains($html, 'class="rt-img-link"') && str_contains($html, 'elsewhere.example'), $html);

$thread = pmThreadFor($db, (int)$A['id'], (int)$B['id']);
$rows['message_threads'][] = (int)$thread['id'];
$db->prepare("INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, 'bbcode')")->execute([(int)$thread['id'], (int)$A['id'], 'hi ' . $bb($a1)]);
$pmId = (int)$db->lastInsertId();
imagesRefsTouch($db, $cfgS, 'pm', (string)$pmId);
$st = $db->prepare("SELECT src FROM image_refs WHERE image_id = ? AND src_key = ?");
$st->execute([(int)$a1['id'], (string)$pmId]);
$serve = fn(array $r, ?array $who): ?array => imagesServe($db, (int)$r['id'], (int)$r['w'], (int)$r['h'], imagesExt($r), false, $who ? (int)$who['id'] : null, false);
$asB = $serve($a1, $B);
check('a message\'s picture: the two in the conversation see it, uncached; a third member and a guest do not; it never turns public',
      $st->fetchColumn() === 'pm' && $asB !== null && $asB['public'] === false && $serve($a1, $C) === null && $serve($a1, null) === null
      && imagesRow($db, (int)$a1['id'])['public_at'] === null);

$db->prepare("INSERT INTO shouts (user_id, body, body_format) VALUES (?, ?, 'bbcode')")->execute([(int)$A['id'], $bb($a2)]);
$shoutId = (int)$db->lastInsertId();
$rows['shouts'][] = $shoutId;
imagesRefsTouch($db, $cfgS, 'shout', (string)$shoutId);
$seenC = $serve($a2, $C) !== null && $serve($a2, null) === null;
$db->prepare("UPDATE shouts SET deleted_at = NOW() WHERE id = ?")->execute([$shoutId]);
imagesVisibilitySync($db, [(int)$a2['id']]);
check('a shout\'s picture: any signed-in member, no guest; once the shout is gone nobody else does and the picture starts its grace time',
      $seenC && $serve($a2, $C) === null && imagesRow($db, (int)$a2['id'])['dead_since'] !== null);

$db->prepare("INSERT INTO user_lists (user_id, name, slug, description, description_format, visibility) VALUES (?, 'Pics', ?, ?, 'bbcode', 'private')")
   ->execute([(int)$B['id'], 'pics-' . random_int(1, 1e9), $bb($b1)]);
$listId = (int)$db->lastInsertId();
$rows['user_lists'][] = $listId;
imagesRefsTouch($db, $cfgS, 'list', (string)$listId);
$private = $serve($b1, $A) === null;
$db->prepare("UPDATE user_lists SET visibility = 'friends' WHERE id = ?")->execute([$listId]);
$db->prepare("INSERT INTO user_friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')")->execute([(int)$B['id'], (int)$A['id']]);
$friends = $serve($b1, $A) !== null && $serve($b1, $C) === null;
$db->prepare("UPDATE user_lists SET visibility = 'public' WHERE id = ?")->execute([$listId]);
check('a list\'s picture follows the list: private (the owner), friends (and their friends), public (signed-in members)',
      $private && $friends && $serve($b1, $C) !== null && $serve($b1, null) === null);

$db->prepare("INSERT INTO user_warnings (user_id, reason, reason_format, source_kind, source_id, info_hash, action, loud, by_id, by_name) VALUES (?, ?, 'bbcode', 'manual', 0, '', 'warn', 1, NULL, 'mod')")
   ->execute([(int)$C['id'], 'see ' . $bb($cp, $a3)]);
$warnId = (int)$db->lastInsertId();
$rows['user_warnings'][] = $warnId;
imagesRefsTouch($db, $cfgS, 'warn', (string)$warnId);
check('a warning\'s picture: the warned member sees it (and the moderators), nobody else',
      imagesPlaceViewerMay($db, (int)$a3['id'], (int)$C['id']) && !imagesPlaceViewerMay($db, (int)$a3['id'], (int)$B['id'])
      && imagesServe($db, (int)$a3['id'], (int)$a3['w'], (int)$a3['h'], 'webp', false, null, true) !== null);

check('the moderators\' texts: this tracker\'s pictures where they are allowed, another site\'s never, the appeal and the mail none at all',
      reportTextValidate($bb($a1), 'bbcode', $cfgS, REPORT_TEXT_MAX, true) === null
      && reportTextValidate('[img]https://elsewhere.example/x.png[/img]', 'bbcode', $cfgS, REPORT_TEXT_MAX, true) === __('api.report_text.no_images')
      && reportTextValidate($bb($a1), 'bbcode', $cfgS) === __('api.report_text.no_images')
      && reportTextValidate($bb($a1), 'bbcode', ['images_in_mod' => '0'] + $cfgS, REPORT_TEXT_MAX, true) === __('api.report_text.no_images')
      && str_contains(reportTextHtml('[img]https://elsewhere.example/x.png[/img]', 'bbcode', $cfgS), 'rt-img-link'));

$p = profileBioParse('Me ' . $bb($a1) . ' [img]https://elsewhere.example/x.png[/img]', $cfgS);
check('the profile\'s description takes [img] of this tracker\'s pictures; any other [img] stays the text it was',
      $p['images'] === 1 && str_contains($p['html'], '[img]https://elsewhere.example/x.png[/img]') && str_contains(profileBioRender('Me ' . $bb($a1), $cfgS), 'class="rt-img"')
      && imagesPlaceProblem($db, $cfgS, 'bio', $p['html'], $A) === null
      && imagesPlaceProblem($db, $cfgS, 'bio', profileBioParse($bb($b1), $cfgS)['html'], $A) === __('api.images.place_not_yours'));
check('a list\'s description: another site\'s picture is still "no_images", this tracker\'s passes within the limit',
      listDescProblem('[img]https://e.example/p.png[/img]', 'bbcode', $cfgS)['code'] === 'no_images'
      && listDescProblem($bb($a1), 'bbcode', $cfgS, $db, $A) === null
      && listDescProblem($bb($b1), 'bbcode', $cfgS, $db, $A)['code'] === 'images'
      && str_contains(listDescRender($db, $cfgS, $bb($a1), 'bbcode'), 'class="rt-img'));

$imgSrc = $src('includes/images.php');
check('every /i/ answer says Cross-Origin-Resource-Policy: same-site, and so do the static covers',
      str_contains($imgSrc, "header('Cross-Origin-Resource-Policy: same-site');")
      && str_contains($src('.htaccess'), 'Header set Cross-Origin-Resource-Policy "same-site"'));
check('the places are sources the hourly sweep walks, without copying anybody else\'s site',
      in_array('shout', IMAGES_REF_SOURCES, true) && in_array('mrep', IMAGES_REF_SOURCES, true)
      && str_contains(imagesRefsScanSql('pm'), "body LIKE '%/i/%'") && str_contains($src('includes/imagerefs.php'), "in_array(\$src, IMAGES_PLACE_SOURCES, true) ? [] : \$hosts"));
check('each place checks on save and registers its pictures',
      str_contains($src('api/user_messages.php'), "imagesPlaceCheck(\$db, \$cfg, 'pm', \$body, \$format, \$me)")
      && str_contains($src('api/user_messages.php'), "imagesRefsTouch(\$db, \$cfg, 'pm', (string)\$pmId)")
      && str_contains($src('includes/shout.php'), "imagesPlaceCheck(\$db, \$cfg, 'shout', \$body, \$format, \$user)")
      && str_contains($src('includes/profilebio.php'), "imagesRefsTouch(\$db, \$cfg, 'bio', (string)\$uid)")
      && str_contains($src('includes/lists.php'), "imagesRefsTouch(\$db, \$cfg, 'list', (string)\$own['id'])")
      && str_contains($src('includes/moderation.php'), "imagesRefsTouch(\$db, \$cfg, 'warn', (string)\$out['warning'])")
      && str_contains($src('includes/reports.php'), "imagesRefsTouch(\$db, null, 'crep', (string)\$rid)")
      && str_contains($src('api/admin/message_report_action.php'), "imagesRefsTouch(\$db, \$cfg, 'mrep', (string)\$id)"));
check('the editors: a picture button (and a pasted screenshot) where the member may upload, sending waits for it',
      str_contains($src('assets/js/images-editor.js'), 'function mini(opts)') && str_contains($src('assets/js/images-editor.js'), "ta.addEventListener('paste'")
      && str_contains($src('templates/layout.php'), 'const APP_IMG_PLACES = ')
      && str_contains($src('assets/js/people.js'), "images: 'pm'") && str_contains($src('assets/js/favourites.js'), "images: 'list'")
      && str_contains($src('assets/js/shoutbox.js'), "images: 'shout'") && str_contains($src('assets/js/profile-bio.js'), "place: 'bio'")
      && str_contains($src('assets/js/people.js'), "window.ImagesEditor.busy(ta)"));
check('the settings: one row per place, saved within 0–10, with search words',
      str_contains($src('templates/admin/settings.php'), 'data-setting="images_in_shout"')
      && str_contains($src('api/admin/save_settings.php'), "'images_in_pm' => [0, 10, 4]") && isset(settingsCatalogKeywords()['images_in_mod']));
$plL = include $root . '/lang/pl.php';
$enL = include $root . '/lang/en.php';
$words = ['perm.images.upload', 'perm.images.link', 'api.images.place_not_yours', 'api.images.place_too_many', 'api.bio.images', 'api.lists.images',
          'settings.images_places_h', 'settings.images_in_pm', 'settings.images_in_mod_who', 'js.images.mini_add', 'js.aimg.kind_pm', 'js.images.ug_kind_shout'];
check('the words in both languages', !array_diff($words, array_keys($plL)) && !array_diff($words, array_keys($enL)) && $plL['js.images.mini_add'] === 'Dodaj obraz');
check('version and changelog', version_compare(TRACKER_VERSION, '1.88.0', '>=') && str_contains($src('CHANGELOG.md'), '## [1.88.0]'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
