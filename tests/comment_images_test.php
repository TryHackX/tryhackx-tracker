<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/sounds.php',
          'includes/shout.php', 'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/icons.php',
          'includes/api_auth.php', 'includes/settings_catalog.php', 'includes/comments.php', 'includes/lang.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$GLOBALS['db'] = $db;
langInit([], 'en');
$cfgOn = array_merge($cfg, [
    'users_enabled' => '1', 'users_require_email_verify' => '1', 'profiles_enabled' => '1', 'site_url' => 'https://img.test/',
    'index_enabled' => '1', 'index_search_enabled' => '1', 'index_search_include_whitelist' => '1',
    'comments_enabled' => '1', 'comment_max_chars' => '500', 'comment_links' => '1', 'comment_edit_minutes' => '15',
    'comment_delete_own_minutes' => '60', 'comments_per_page' => '5', 'comment_rate_per_hour' => '1000',
    'comments_guest_review' => '1', 'link_trusted_domains' => 'example.org', 'comments_reply_depth' => '3',
    'recaptcha_enabled' => '0', 'default_language' => 'en', 'antispam_enabled' => '0',
    'comment_images_upload' => '0', 'comment_images_links' => '1', 'comment_images_max' => '18', 'comment_image_max_mb' => '4',
    'comment_thumb_h' => '240', 'comment_thumb_w' => '360',
    'images_max_mb' => '8', 'images_max_edge' => '2048', 'images_max_mpx' => '24', 'images_webp_quality' => '82',
    'images_per_day' => '60', 'images_quota_mb' => '250',
]);
$GLOBALS['cfg'] = $cfgOn;

const CI_H = 'c1a9c1a9c1a9c1a9c1a9c1a9c1a9c1a9c1a9c1a9';
$guestBefore = (string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'guest'")->fetchColumn();
$cleanup = function () use ($db): void {
    foreach (['cimgtest_a', 'cimgtest_b'] as $name) {
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id <= 0) continue;
        $im = $db->prepare("SELECT id FROM images WHERE user_id = ?");
        $im->execute([$id]);
        foreach ($im->fetchAll(PDO::FETCH_COLUMN) as $i) imagesDelete($db, (int)$i);
        $db->prepare("DELETE FROM image_uploads WHERE user_id = ?")->execute([$id]);
        userDeleteCascade($db, $id);
    }
    $db->prepare("DELETE FROM hash_comments WHERE info_hash = ?")->execute([CI_H]);
    $db->prepare("DELETE FROM index_hashes WHERE info_hash = ?")->execute([CI_H]);
    $db->exec("DELETE FROM api_clients WHERE label = 'cimgtest'");
    $db->exec("DELETE FROM image_remote WHERE host LIKE '%assoc.test'");
};
$cleanup();
register_shutdown_function(function () use ($db, $guestBefore, $cleanup) {
    $cleanup();
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")->execute([$guestBefore]);
});

session_id('cimgtest' . bin2hex(random_bytes(8)));
session_start();
$_SESSION['csrf_token'] = 'ci-test-token';
$T = 'ci-test-token';

$mk = function (string $name) use ($db, $cfgOn): int {
    $r = userCreate($db, $cfgOn, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0) $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$id]);
    userPermissionsForget($id);
    return $id;
};
$A = $mk('cimgtest_a');
$me = userFindById($db, $A);
$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Comment pictures test torrent', 'done')")->execute([CI_H]);
check('a member to write with, and a torrent to write under', $A > 0 && $me !== null && commentHashVisible($db, $cfgOn, $me, CI_H));

$pic = function (int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 30, 160, 110));
    imagesetpixel($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    ob_start();
    imagepng($im);
    return (string)ob_get_clean();
};

$defaults = trackerSchemaDefaultSettings();
$save = $src('api/admin/save_settings.php');
$tpl = $src('templates/admin/settings.php');
$kw = settingsCatalogKeywords();
$want = ['comment_images_upload' => '0', 'comment_images_links' => '1', 'comment_images_max' => '18', 'comment_image_max_mb' => '4',
         'comment_thumb_h' => '240', 'comment_thumb_w' => '360'];
$missing = [];
foreach ($want as $k => $v) {
    if (($defaults[$k] ?? null) !== $v) $missing[] = "$k default";
    if (!str_contains($save, "'$k'")) $missing[] = "$k save";
    if (!isset($kw[$k])) $missing[] = "$k catalogue";
    if (!str_contains($tpl, 'name="' . $k . '"')) $missing[] = "$k page";
}
check('the six settings: uploading off and links on as shipped, eighteen to a comment, 4 MB, 240 × 360 — saved, searchable, on the page',
      $missing === [], implode(', ', $missing));
check('… the numbers clamped on save, the two switches 0/1, in their own section of Formatting & pictures',
      str_contains($save, "'comment_images_max' => [0, 50, 18], 'comment_image_max_mb' => [1, 20, 4], 'comment_thumb_h' => [80, 600, 240], 'comment_thumb_w' => [80, 800, 360],")
      && str_contains($save, "          'comment_images_upload', 'comment_images_links',\n")
      && str_contains($tpl, 'id="section-comment-images" data-group="formatting"'));
check('the readers clamp what they are given',
      commentImagesMax(['comment_images_max' => '99']) === 50 && commentImagesMax(['comment_images_max' => '-3']) === 0
      && commentImagesMax(['comment_images_max' => 'x']) === 18 && commentImagesMax([]) === 18
      && commentImageMaxBytes(array_merge($cfgOn, ['comment_image_max_mb' => '4'])) === min(4 * 1048576, imagesMaxBytes($cfgOn))
      && commentImageMaxBytes(array_merge($cfgOn, ['comment_image_max_mb' => '6', 'images_max_mb' => '2'])) === imagesMaxBytes(array_merge($cfgOn, ['images_max_mb' => '2']))
      && commentThumbH(['comment_thumb_h' => '5000']) === 600 && commentThumbW(['comment_thumb_w' => '10']) === 80 && commentThumbH([]) === 240);

check('the permission: registered, a member\'s by the grant', isset(userPermissionList(true)['comment.images'])
      && in_array('comment.images', userGroupPresets()['member']['perms'], true) && userIdHasPermission($db, $cfgOn, $A, 'comment.images'));
$guestJson = json_decode($guestBefore, true) ?: [];
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")
   ->execute([json_encode(array_merge($guestJson, ['comment.view' => true, 'comment.post' => true, 'comment.images' => true]))]);
userPermissionsForget(0);
$guestMay = commentImagesMayUse($db, $cfgOn, null) || commentImageRules($db, $cfgOn, null) !== null;
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'guest'")->execute([$guestBefore]);
userPermissionsForget(0);
check('… and never a guest\'s, even where somebody ticks it for the guest group', !$guestMay);

$info = commentImageInfo($db, $cfgOn, $me);
$infoUp = commentImageInfo($db, array_merge($cfgOn, ['comment_images_upload' => '1']), $me);
check('what the composer is told: pictures from links, no upload as shipped, the most, this site\'s address, the upload limit',
      is_array($info) && $info['upload'] === false && $info['links'] === true && $info['max'] === 18 && $info['base'] === 'https://img.test/'
      && $info['max_bytes'] === min(4 * 1048576, imagesMaxBytes($cfgOn)) && is_array($info['hosts']) && is_array($infoUp) && $infoUp['upload'] === true
      && commentImageInfo($db, $cfgOn, null) === null && commentImageInfo($db, array_merge($cfgOn, ['comment_images_max' => '0']), $me) === null
      && commentImageInfo($db, array_merge($cfgOn, ['comment_images_links' => '0']), $me) === null
      && (commentComposerInfo($db, $cfgOn, $me)['images']['links'] ?? null) === true);

$cl = apiClientCreate($db, 'cimgtest', 'whitelist');
$db->prepare("UPDATE api_clients SET image_domains = 'assoc.test', image_consent = 1 WHERE id = ?")->execute([(int)$cl['id']]);
imagesRemoteHosts($db, true);
$rules = commentImageRules($db, $cfgOn, $me);
$rulesNoLinks = commentImageRules($db, array_merge($cfgOn, ['comment_images_links' => '0', 'comment_images_upload' => '1']), $me);
$P = fn(string $s, bool $links = true, ?array $img = null): array => commentParse(commentClean($s), $cfgOn, $links, $img ?? $rules);
$own = 'https://img.test/i/5-960x540.webp';
$p = $P("[img]$own" . "[/img] hi");
check('this site\'s picture: a thumbnail of the reserved size within 360 × 240, loaded later, the full one for the viewer; no characters counted',
      $p['html'] === '<img class="rt-img cm-img" alt="" loading="lazy" decoding="async" width="360" height="203" data-src="' . $own
                     . '" data-full="' . $own . '?full=1"> hi' && $p['chars'] === 3 && $p['images'] === 1, $p['html']);
$tall = $P('[img]https://img.test/i/8-600x900.webp[/img][img]https://img.test/i/9-100x50.webp[/img]')['html'];
check('… a tall one fits the height, a small one stays its size',
      str_contains($tall, 'width="160" height="240"') && str_contains($tall, 'width="100" height="50"'), $tall);
$odd = $P('[img]https://img.test/i/7-50x2000.webp[/img]')['html'];
check('… a picture more than eight to one is a link to it, never a 1-pixel sliver',
      str_starts_with($odd, '<a class="cm-img-odd" href="https://img.test/i/7-50x2000.webp?full=1" target="_blank" rel="noopener">') && !str_contains($odd, '<img'), $odd);
$foreign = $P('[img]https://x.example/a.png[/img]');
check('another site\'s picture is a link (with the site\'s link attributes), never loaded by the reader',
      str_contains($foreign['html'], '<a href="https://x.example/a.png" rel="nofollow noopener noreferrer ugc" target="_blank" data-external="1" class="cm-img-link"')
      && !str_contains($foreign['html'], '<img') && !str_contains($foreign['html'], 'src=') && $foreign['images'] === 1 && $foreign['chars'] === 0, $foreign['html']);
$asText = $P('[img]https://x.example/a.png[/img]', false);
$assocText = $P('[img]https://cdn.assoc.test/a.png[/img]', false);
check('… text where its writer may not link: refused on save for another site, kept for a key\'s domain (it will be copied)',
      $asText['html'] === 'https://x.example/a.png' && $asText['img_foreign_text'] === 1
      && $assocText['html'] === 'https://cdn.assoc.test/a.png' && $assocText['img_foreign_text'] === 0, json_encode([$asText, $assocText]));
$off = $P('[img]https://x.example/a.png[/img] [img]' . $own . '[/img]', true, $rulesNoLinks);
check('pictures from links switched off: another site\'s is the text that was typed, this site\'s still a picture',
      str_starts_with($off['html'], '[img]https://x.example/a.png[/img] <img class="rt-img cm-img"') && $off['img_remote_off'] === 1, $off['html']);
$none = commentParse(commentClean("[img]$own" . "[/img]"), $cfgOn, true, null);
check('no pictures for this writer: the tag is text, and counted for the refusal',
      $none['html'] === '[img]' . $own . '[/img]' && $none['img_tags'] === 1 && $none['images'] === 0);
$bad = $P('[img]not a url[/img] [img]javascript:alert(1)[/img] [img]https://x.example/a.png');
check('a bad address and a tag left open are text', $bad['img_bad'] === 2 && $bad['images'] === 0
      && str_contains($bad['html'], '[img]javascript:alert(1)[/img]') && str_ends_with($bad['html'], '[img]https://x.example/a.png'), $bad['html']);
$nest = $P('[url=https://e.example/x]see [img]' . $own . '[/img][/url] [code][img]' . $own . '[/img][/code]');
check('inside a link\'s words or in code, a picture tag is text', !str_contains($nest['html'], '<img') && $nest['images'] === 0, $nest['html']);
$xss = $P('[img]https://img.test/i/5-960x540.webp"onerror="alert(1)[/img] [img]https://x.example/a.png?a=<b>&c="d"[/img]')['html'];
check('an address cannot carry an attribute or a tag in with it',
      !preg_match('/<[^>]+\son[a-z]+\s*=/i', $xss) && !str_contains($xss, '<b>') && substr_count($xss, '<a ') === 2, $xss);

$prob = fn(string $s, array $c = [], ?array $r = null, bool $links = true) => commentProblem(commentClean($s), array_merge($cfgOn, $c), $links, $r ?? $rules)['code'] ?? null;
check('what the save says: no pictures for you, a bad address, links switched off, a link you may not write, too many',
      commentProblem(commentClean("[img]$own" . "[/img]"), $cfgOn, true, null)['code'] === 'no_images'
      && $prob('[img]x[/img]') === 'bad_image'
      && $prob('[img]https://x.example/a.png[/img]', [], $rulesNoLinks) === 'no_image_links'
      && $prob('[img]https://x.example/a.png[/img]', [], null, false) === 'foreign_image'
      && $prob('[img]https://cdn.assoc.test/a.png[/img]', [], null, false) === null
      && $prob(str_repeat("[img]$own" . "[/img] ", 3), [], commentImageRules($db, array_merge($cfgOn, ['comment_images_max' => '2']), $me)) === 'too_many_images'
      && $prob("[img]$own" . "[/img] fine") === null);
check('the refusals say what is wrong, in words', langHas('api.comment.no_images') && langHas('api.comment.bad_image') && langHas('api.comment.no_image_links')
      && langHas('api.comment.foreign_image') && langHas('api.comment.too_many_images') && langHas('api.images.shape'));

$ex = commentExcerpt("[b]Look[/b] [img]$own" . "[/img] here", 140, '[obraz]');
check('a notification quotes a picture as a word: "[obraz]" in Polish, "[picture]" in English',
      $ex === 'Look [obraz] here' && commentExcerpt("[img]$own" . "[/img]") === '[picture]'
      && langFor('pl', 'comment.image_word') === '[obraz]' && langFor('en', 'comment.image_word') === '[picture]', $ex);
$c = $src('includes/comments.php');
check('… each in its reader\'s language: the new comment\'s, the mention\'s and the corrected one\'s quote',
      str_contains($c, "\$excerpts[\$lang] ??= commentExcerpt((string)\$row['body'], COMMENT_EXCERPT, langFor(\$lang, 'comment.image_word'));")
      && str_contains($c, "commentExcerpt((string)\$row['body'], COMMENT_EXCERPT, langFor(\$lang, 'comment.image_word'))]), commentLink(")
      && str_contains($c, "commentExcerpt(\$newBody, COMMENT_EXCERPT, langFor(\$lang, 'comment.image_word'))"));

$upCfg = array_merge($cfgOn, ['comment_images_upload' => '1']);
$up = imagesStore($db, $upCfg, $A, $pic(800, 600), 'shot.png', 'upload', null, ['max_ratio' => COMMENT_IMAGE_RATIO_MAX]);
$sliver = imagesStore($db, $upCfg, $A, $pic(40, 900), 'sliver.png', 'upload', null, ['max_ratio' => COMMENT_IMAGE_RATIO_MAX]);
$plain = imagesStore($db, $upCfg, $A, $pic(40, 900), 'sliver2.png');
$again = $plain['ok'] ?? false ? imagesStore($db, $upCfg, $A, $pic(40, 900), 'sliver3.png', 'upload', null, ['max_ratio' => COMMENT_IMAGE_RATIO_MAX]) : null;
check('an upload for a comment: kept, and one more than eight to one refused — for a comment only',
      !empty($up['ok']) && ($sliver['code'] ?? '') === 'bad_shape' && ($sliver['status'] ?? 0) === 400 && !empty($plain['ok'])
      && ($again['code'] ?? '') === 'bad_shape', json_encode([$up['code'] ?? 'ok', $sliver, $plain['code'] ?? 'ok', $again]));
$row = $up['row'] ?? null;
$url = $row ? imagesUrl($upCfg, (int)$row['id'], (int)$row['w'], (int)$row['h'], 'webp') : '';
check('… private until something shows it', $row !== null && imagesServe($db, (int)$row['id'], (int)$row['w'], (int)$row['h'], 'webp', false, null, false) === null);
$r = commentPostRequest($db, $cfgOn, $me, ['csrf_token' => $T, 'hash' => CI_H, 'body' => "Here: [img]$url" . "[/img]"], '127.0.0.61');
$html = (string)($r['body']['comment']['html'] ?? '');
check('a comment with a picture: saved, and drawn as a thumbnail that loads after the thread',
      $r['status'] === 200 && str_contains($html, '<img class="rt-img cm-img"') && str_contains($html, 'data-src="' . $url . '"') && !str_contains($html, ' src='), json_encode($r['body']));
$served = imagesServe($db, (int)$row['id'], (int)$row['w'], (int)$row['h'], 'webp', false, null, false);
check('… and the moment a visible comment shows the picture, it is public — the uploader\'s preview before that held nothing up',
      $served !== null && $served['public'] === true && imagesRow($db, (int)$row['id'])['public_at'] !== null
      && str_contains($c, "if (\$imgRules !== null && \$status === 'visible') imagesPublishIn(\$db, \$cfg, \$clean);")
      && str_contains($c, "if (\$imgRules !== null && \$row['status'] === 'visible') imagesPublishIn(\$db, \$cfg, \$clean);"));
check('… a deleted comment no longer keeps it public or in use, a visible one does',
      str_contains(implode(' ', imagesTextSources(true)), "FROM hash_comments WHERE body LIKE ? AND status = 'visible'")
      && str_contains(implode(' ', imagesTextSources(false)), "FROM hash_comments WHERE body LIKE ? AND status <> 'deleted'")
      && imagesReferenced($db, (int)$row['id'], false));
$r = commentPostRequest($db, array_merge($cfgOn, ['comment_images_upload' => '0', 'comment_images_links' => '0']), $me,
                        ['csrf_token' => $T, 'hash' => CI_H, 'body' => "[img]$url" . "[/img]"], '127.0.0.61');
check('both switches off: a picture is refused, said in words', $r['status'] === 400 && ($r['body']['error'] ?? '') === 'no_images', json_encode($r['body']));
$r = commentPostRequest($db, array_merge($cfgOn, ['comment_images_max' => '1']), $me,
                        ['csrf_token' => $T, 'hash' => CI_H, 'body' => "[img]$url" . "[/img] [img]$url" . "[/img]"], '127.0.0.61');
check('… and more than the most', $r['status'] === 400 && ($r['body']['error'] ?? '') === 'too_many_images', json_encode($r['body']));
check('a comment\'s save settles a key\'s pictures: copied ones become this site\'s at once, new ones join the queue',
      str_contains($c, "if (\$imgRules !== null) \$clean = imagesRemoteSettle(\$db, \$cfg, \$clean);")
      && substr_count($c, "imagesRemoteSettle(\$db, \$cfg, \$clean)") === 2
      && str_contains($c, "\$imgRules = \$authorId > 0 ? commentImageRules(\$db, \$cfg, userFindById(\$db, \$authorId)) : null;"));

$api = $src('api/images.php');
check('the upload endpoint takes place=comment: the comment permission, its own size, the shape rule',
      str_contains($api, "\$forComment = (string)(\$_GET['place'] ?? '') === 'comment';")
      && str_contains($api, "commentImagesMayUpload(\$db, \$cfg, \$me)") && str_contains($api, '$maxBytes = $forComment ? commentImageMaxBytes($cfg) : imagesMaxBytes($cfg);')
      && str_contains($api, "\$forComment ? ['max_ratio' => COMMENT_IMAGE_RATIO_MAX] : []"));
check('… and the editor\'s script is on the page for somebody who may upload into a comment only',
      str_contains($src('templates/layout.php'), "|| (function_exists('commentImagesMayUpload') && commentImagesMayUpload(\$db, \$cfg, \$navUser))): ?>"));
$pv = $src('api/richtext_preview.php');
check('the Preview draws pictures as the thread will, judges them as the save will, and counts them',
      str_contains($pv, '$imgRules = commentImageRules($db, $cfg, $me);') && str_contains($pv, "'images' => \$imgRules];")
      && str_contains($pv, "'images'  => ['used' => (int)\$p['images'], 'limit' => \$imgRules !== null ? (int)\$imgRules['max'] : 0],"));

$js = $src('assets/js/comments.js');
check('the comment box: an upload bar where uploading is on (no label grids), a strip of the pictures with an x each, a pasted picture address wrapped',
      str_contains($js, "root.dataset.place = 'comment';") && str_contains($js, "root.dataset.labels = '0';")
      && str_contains($js, "if (me.images.upload) wrap.append(imagesBar(id, me));") && str_contains($js, 'wrap.append(imageStrip(ta, me));')
      && str_contains($js, "x.addEventListener('click', () => removeAt(i));") && str_contains($js, "ta.setRangeText(pre + '[img]' + raw + '[/img]' + post, start, end, 'end');")
      && str_contains($js, "const copy = !own && hostMatches(host, me.images.hosts);"));
check('… thumbnails load last: no src until the browser is idle after drawing, then lazily',
      str_contains($js, "new MutationObserver(wakeImages).observe(body, { childList: true, subtree: true });")
      && str_contains($js, "window.requestIdleCallback(run, { timeout: 1500 }); else setTimeout(run, 200);") && str_contains($js, "if (/^https?:\\/\\//i.test(src)) im.src = src;"));
check('… the counter counts as the server does, pictures included',
      str_contains($js, "used: visibleChars(text, !!me.links, img)") && str_contains($js, 'function visibleChars(src, links, img) {'));
$ie = $src('assets/js/images-editor.js');
check('the image editor knows its place: the comment limits, no label grid, pictures on one line',
      str_contains($ie, "const place = root.dataset.place || '';") && str_contains($ie, "const labelsOn = root.dataset.labels !== '0';")
      && str_contains($ie, "const sep = place === 'comment' ? ' ' : '\\n';") && str_contains($ie, "x.open('POST', API + 'images' + placeArg(place));")
      && str_contains($ie, "fetch(API + 'images&page=' + encodeURIComponent(page) + placeArg(place)"));
$css = $src('assets/css/style.css');
check('a comment\'s thumbnail keeps its box and is cropped rather than stretched; the strip\'s x is a button of its own',
      str_contains($css, '.rt-body .rt-img.cm-img { display: inline-block;') && str_contains($css, 'object-fit: cover;')
      && str_contains($css, '.cm-img-chip-x { position: absolute;'));
check('the words a reader sees are in the public dictionary', langHas('js.comments.img_remove') && langHas('js.comments.img_count')
      && langHas('js.comments.hint_img') && in_array('js.comments.', LANG_JS_PUBLIC, true));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
