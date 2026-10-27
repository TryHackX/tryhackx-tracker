<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/auth.php',
          'includes/audit.php', 'includes/lang.php', 'includes/comments.php', 'includes/profileimages.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
$cfgG = array_merge($cfg, ['users_enabled' => '1', 'profiles_enabled' => '1', 'users_require_email_verify' => '0', 'site_url' => 'https://gal.test/',
    'images_gallery_enabled' => '1', 'images_per_day' => '0', 'images_per_day_mb' => '0', 'images_quota_mb' => '0', 'images_max_mb' => '8',
    'wl_allow_description' => '1', 'desc_allow_bbcode' => '1', 'wl_edit_max_pending' => '3']);
$H = fn(int $i): string => str_repeat('ab', 19) . sprintf('%02x', $i);
$tidy = function () use ($db): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'galtest%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        foreach ($db->query("SELECT id FROM images WHERE user_id = " . (int)$id)->fetchAll(PDO::FETCH_COLUMN) as $img) imagesDelete($db, (int)$img);
        userDeleteCascade($db, (int)$id);
    }
    $db->exec("DELETE FROM wl_content_edits WHERE info_hash LIKE 'ababab%'");
    $db->exec("DELETE FROM whitelist WHERE info_hash LIKE 'ababab%'");
    $db->exec("DELETE FROM hash_comments WHERE info_hash LIKE 'ababab%'");
};
$tidy();
$pic = function (int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, random_int(0, 255), 90, 200));
    imagesetpixel($im, 1, 1, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    ob_start();
    imagepng($im);
    return (string)ob_get_clean();
};
$mkUser = function (string $name) use ($db, $cfgG): array {
    $r = userCreate($db, $cfgG, $name, $name . '@example.org', bin2hex(random_bytes(8)) . 'Aa1!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
    return userFindById($db, $id) ?? [];
};

try {
    $A = $mkUser('galtestowner');
    $B = $mkUser('galtestreader');
    $aid = (int)$A['id'];
    $bid = (int)$B['id'];
    check('two members to work with', $aid > 0 && $bid > 0);

    check('the gallery is the owner\'s own, always, and nobody else\'s until they say so',
          profileImagesShownTo($db, $cfgG, $A, $A) && !profileImagesShownTo($db, $cfgG, $A, $B) && !profileImagesShownTo($db, $cfgG, $A, null));
    $db->prepare("UPDATE users SET images_public = 1 WHERE id = ?")->execute([$aid]);
    $A = userFindById($db, $aid);
    check('… with their yes and their group\'s grant of images.public, another member sees it',
          userIdHasGrantedPermission($db, $cfgG, $aid, 'images.public') && profileImagesShownTo($db, $cfgG, $A, $B));
    check('… and never with the gallery switched off', !profileImagesShownTo($db, array_merge($cfgG, ['images_gallery_enabled' => '0']), $A, $B));

    $up = fn(int $w, int $h, string $name) => imagesStore($db, $cfgG, $aid, $pic($w, $h), $name)['row'] ?? null;
    $unused = $up(300, 200, 'unused.png');
    $inComment = $up(320, 200, 'comment.png');
    $inDesc = $up(340, 200, 'desc.png');
    $urlOf = fn(array $r) => imagesUrl($cfgG, (int)$r['id'], (int)$r['w'], (int)$r['h'], 'webp');
    $db->prepare("INSERT INTO hash_comments (info_hash, user_id, body, status) VALUES (?, ?, ?, 'visible')")
       ->execute([$H(1), $aid, "Mine: [img]" . $urlOf($inComment) . "[/img] nice"]);
    $cm = (int)$db->lastInsertId();
    imagesRefsTouch($db, $cfgG, 'cm', (string)$cm);
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, probe_status, description, description_format, content_status, content_user_id, content_reviewed_at)
                  VALUES (?, 'Gallery release', 'web', NOW(), 'passed', ?, 'bbcode', 'approved', ?, NOW())")
       ->execute([$H(2), "Poster:\n[img]" . $urlOf($inDesc) . "[/img]\nEnd.", $bid]);
    $wid = (int)$db->lastInsertId();
    imagesRefsTouch($db, $cfgG, 'wl', (string)$wid);

    $own = profileImagesList($db, $cfgG, $aid, true, []);
    $used = profileImagesList($db, $cfgG, $aid, true, ['filter' => 'used']);
    $free = profileImagesList($db, $cfgG, $aid, true, ['filter' => 'unused']);
    check('the owner sees every picture, newest first, with how often each is used',
          $own['total'] === 3 && array_column($own['rows'], 'id') === [(int)$inDesc['id'], (int)$inComment['id'], (int)$unused['id']]
          && $own['rows'][0]['uses'] === 1 && $own['rows'][2]['uses'] === 0, json_encode($own));
    check('… and can narrow it to the used and the unused ones', $used['total'] === 2 && $free['total'] === 1 && $free['rows'][0]['id'] === (int)$unused['id']);
    $pub = profileImagesList($db, $cfgG, $aid, false, []);
    check('another member sees only what published texts already show, without file names',
          $pub['total'] === 2 && !in_array((int)$unused['id'], array_column($pub['rows'], 'id'), true)
          && ($pub['rows'][0]['name'] ?? 'x') === '' && !array_key_exists('uses', $pub['rows'][0]), json_encode($pub['rows']));

    $r1 = imagesRequestRemoval($db, $cfgG, imagesRow($db, (int)$unused['id']), $A);
    check('deleting a picture nothing uses deletes it at once', $r1['deleted'] === true && imagesRow($db, (int)$unused['id']) === null, json_encode($r1));
    $r2 = imagesRequestRemoval($db, $cfgG, imagesRow($db, (int)$inComment['id']), $A);
    $body = (string)$db->query("SELECT body FROM hash_comments WHERE id = " . $cm)->fetchColumn();
    check('a picture in the owner\'s own comment is taken out of the comment, and then deleted',
          $r2['stripped'] === 1 && $r2['deleted'] === true && imagesRow($db, (int)$inComment['id']) === null && $body === 'Mine:  nice', json_encode([$r2, $body]));

    $r3 = imagesRequestRemoval($db, $cfgG, imagesRow($db, (int)$inDesc['id']), $A);
    $prop = $db->query("SELECT * FROM wl_content_edits WHERE whitelist_id = " . $wid . " AND status = 'pending'")->fetch(PDO::FETCH_ASSOC);
    check('a picture in a published description waits: a proposed change takes it out, filed by its owner for a moderator',
          $r3['proposals'] === 1 && $r3['deleted'] === false && $prop && $prop['note'] === IMAGES_REMOVAL_NOTE && $prop['kind'] === 'edit'
          && (int)$prop['user_id'] === $aid && $prop['description'] === "Poster:\n\nEnd." && imagesRow($db, (int)$inDesc['id'])['removal_at'] !== null, json_encode([$r3, $prop]));
    $afterOwn = profileImagesList($db, $cfgG, $aid, true, []);
    $waiting = profileImagesList($db, $cfgG, $aid, true, ['filter' => 'removal']);
    $afterPub = profileImagesList($db, $cfgG, $aid, false, []);
    check('… it is gone from both galleries at once, listed apart for its owner, and the description still shows it',
          $afterOwn['total'] === 0 && $waiting['total'] === 1 && $afterPub['total'] === 0
          && imagesServe($db, (int)$inDesc['id'], (int)$inDesc['w'], (int)$inDesc['h'], 'webp', false, null, false) !== null);
    check('… and its owner may change their mind: the proposal is withdrawn, the picture is back',
          imagesCancelRemoval($db, $cfgG, imagesRow($db, (int)$inDesc['id']), $aid) === 1
          && imagesRow($db, (int)$inDesc['id'])['removal_at'] === null && profileImagesList($db, $cfgG, $aid, true, [])['total'] === 1
          && (int)$db->query("SELECT COUNT(*) FROM wl_content_edits WHERE whitelist_id = " . $wid . " AND status = 'pending'")->fetchColumn() === 0);
    imagesRequestRemoval($db, $cfgG, imagesRow($db, (int)$inDesc['id']), $A);
    $e = contentEditById($db, (int)$db->query("SELECT id FROM wl_content_edits WHERE whitelist_id = " . $wid . " AND status = 'pending'")->fetchColumn());
    $applied = $e !== null && contentEditApply($db, $cfgG, $e);
    $w = $db->query("SELECT description, content_user_id, content_credits FROM whitelist WHERE id = " . $wid)->fetch(PDO::FETCH_ASSOC);
    check('a moderator approving the change takes the picture out, keeps the author and the credits, and the picture is deleted for good',
          $applied && $w['description'] === "Poster:\n\nEnd." && (int)$w['content_user_id'] === $bid && !str_contains((string)$w['content_credits'], '"u":' . $aid)
          && imagesRow($db, (int)$inDesc['id']) === null, json_encode([$applied, $w]));
    $notes = $db->prepare("SELECT title FROM user_notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $notes->execute([$aid]);
    $nOwner = (string)$notes->fetchColumn();
    $notes->execute([$bid]);
    $nAuthor = (string)$notes->fetchColumn();
    check('… the owner is told the picture is out, the author that a picture left their description — without the owner\'s name',
          str_contains($nOwner, 'Gallery release') && str_contains($nAuthor, 'Gallery release') && !str_contains($nAuthor, 'galtestowner'), json_encode([$nOwner, $nAuthor]));

    $inQuiet = $up(360, 200, 'quiet.png');
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, probe_status, description, description_format, content_status, content_user_id, content_reviewed_at)
                  VALUES (?, 'Quiet release', 'web', NOW(), 'passed', ?, 'bbcode', 'approved', ?, NOW())")
       ->execute([$H(3), "[img]" . $urlOf($inQuiet) . "[/img]\nQuiet.", $bid]);
    $wq = (int)$db->lastInsertId();
    $db->exec("DELETE FROM settings WHERE `key` = 'images_refs_built_at'");
    $db->prepare("DELETE FROM image_refs WHERE image_id = ?")->execute([(int)$inQuiet['id']]);
    $cfgN = $cfgG;
    unset($cfgN['images_refs_built_at']);
    $r4 = imagesRequestRemoval($db, $cfgN, imagesRow($db, (int)$inQuiet['id']), $A);
    check('before the references were ever counted, a picture a description shows is counted first and waits — never deleted at once',
          $r4['deleted'] === false && $r4['proposals'] === 1 && imagesRow($db, (int)$inQuiet['id']) !== null && imagesRefsReady($db), json_encode($r4));
    $e = contentEditById($db, (int)$db->query("SELECT id FROM wl_content_edits WHERE whitelist_id = " . $wq . " AND status = 'pending'")->fetchColumn());
    $rejected = $e !== null && contentEditReject($db, $cfgG, $e);
    $kept = imagesRow($db, (int)$inQuiet['id']);
    check('a moderator turning the change down keeps the picture in the description and on the server, still out of the gallery',
          $rejected && $kept !== null && $kept['removal_at'] !== null
          && str_contains((string)$db->query("SELECT description FROM whitelist WHERE id = " . $wq)->fetchColumn(), $urlOf($inQuiet))
          && profileImagesList($db, $cfgG, $aid, true, [])['total'] === 0 && profileImagesList($db, $cfgG, $aid, true, ['filter' => 'removal'])['total'] === 1);
    imagesCancelRemoval($db, $cfgG, $kept, $aid);
    check('… and its owner can still take it back into the gallery',
          imagesRow($db, (int)$inQuiet['id'])['removal_at'] === null && profileImagesList($db, $cfgG, $aid, true, [])['total'] === 1);
    $db->exec("DELETE FROM settings WHERE `key` = 'images_refs_built_at'");
    $db->exec("DELETE FROM image_refs");
    $cfg = $cfgG;
    check('the editor\'s check before a delete counts the references first, so a used picture is never taken for unused',
          imagesReferenced($db, (int)$inQuiet['id'], false) && imagesRefsReady($db));

    check('the account page has the tab and the switch, the profile the section, both drawn by user-images.js',
          str_contains($src('templates/pages/account.php'), 'data-pane="images"') && str_contains($src('templates/pages/account.php'), 'id="acc-images-public"')
          && str_contains($src('templates/pages/profile.php'), 'id="profile-images"') && str_contains($src('templates/layout.php'), 'assets/js/user-images.js')
          && str_contains($src('assets/js/favourites.js'), "['acc-images-public', 'images_public']") && str_contains($src('assets/js/favourites.js'), "'descriptions', 'images']"));
    check('the endpoint user_images is routed, and the privacy switch is saved by user_privacy',
          str_contains($src('api.php'), "'user_images'                => 'api/user_images.php',") && str_contains($src('api/user_privacy.php'), "'votes_public', 'images_public'] as \$flag"));
    check('the editor still refuses to delete a used picture, and says where to do it instead',
          str_contains((string)__('api.images.in_use'), 'Images tab'));
} finally {
    $tidy();
}

echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
