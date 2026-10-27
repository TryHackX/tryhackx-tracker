<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/sounds.php', 'includes/shout.php',
          'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/auth.php', 'includes/icons.php',
          'includes/comments.php', 'includes/images.php', 'includes/imagerefs.php', 'includes/reports.php', 'includes/lang.php'] as $f) require_once $root . '/' . $f;

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
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '1', 'antispam_enabled' => '0',
                            'recaptcha_enabled' => '0', 'default_language' => 'en', 'site_url' => 'https://r183.test']);
$GLOBALS['cfg'] = $cfgOn;

const IR_USERS = ['irtest_up', 'irtest_rep'];
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$made = [];
$cleanRate = function (): void {
    $file = dirname(__DIR__) . '/config/rate_limits.json';
    if (!is_file($file)) return;
    $lock = @fopen($file . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $raw = @file_get_contents($file);
    $data = $raw ? (json_decode($raw, true) ?: []) : [];
    foreach (array_keys($data) as $k) if (str_starts_with($k, 'creport|')) unset($data[$k]);
    rateLimitWrite($file, $data);
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
};
$wipe = function () use ($db, &$made, $auditFloor, $cleanRate): void {
    foreach ($made as $id) {
        imagesDelete($db, (int)$id);
        $db->prepare("DELETE FROM image_removals WHERE image_id = ?")->execute([(int)$id]);
        $db->prepare("DELETE FROM content_reports WHERE kind = 'image' AND target_id = ?")->execute([(int)$id]);
    }
    $db->exec("DELETE FROM image_remote WHERE url LIKE 'https://forum.r183.test/%'");
    foreach (IR_USERS as $name) {
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            $db->prepare("DELETE FROM images WHERE user_id = ?")->execute([$id]);
            userDeleteCascade($db, $id);
        }
    }
    $db->prepare("DELETE FROM audit_log WHERE id > ?")->execute([$auditFloor]);
    $cleanRate();
};
register_shutdown_function(function () use ($wipe) {
    $wipe();
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
$cleanRate();

function irUser(PDO $db, array $cfg, string $name): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0) $db->prepare("UPDATE users SET email_verified = 1, language = 'en' WHERE id = ?")->execute([$id]);
    userPermissionsForget($id);
    return $id;
}
$pic = function (int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, random_int(0, 255), 80, 120));
    imagesetpixel($im, 1, 1, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    ob_start();
    imagepng($im);
    return (string)ob_get_clean();
};

session_start();
$_SESSION['csrf_token'] = 'ir-test-token';
$T = 'ir-test-token';
$up = irUser($db, $cfgOn, 'irtest_up');
$rep = irUser($db, $cfgOn, 'irtest_rep');
$me = fn(int $id): array => userFindById($db, $id) ?? [];
$store = function (?int $uid, string $bytes, string $name, string $source = 'upload', ?string $url = null) use ($db, $cfgOn, &$made): array {
    $r = imagesStore($db, $cfgOn, $uid, $bytes, $name, $source, $url);
    if (!empty($r['ok'])) $made[] = (int)$r['row']['id'];
    return $r;
};
$publish = fn(int $id) => $db->prepare("UPDATE images SET public_at = NOW(), checked_at = NOW() WHERE id = ?")->execute([$id]);
$rq = fn(int $who, array $in) => contentReportRequest($db, $cfgOn, $me($who), $in + ['csrf_token' => $T, 'kind' => 'image'], '127.0.0.1');

check('schema 103: the reports take pictures, and removals are kept', TRACKER_SCHEMA_VERSION >= 103 && schemaTableExists($db, 'image_removals')
      && str_contains((string)schemaColumnType($db, 'content_reports', 'kind'), "'image'"));
check('a picture is a kind of report with its own pair of panel permissions', contentReportKindOn($cfgOn, 'image')
      && contentReportPerm('image', 'view') === 'panel.reports.images.view' && contentReportPerm('image', 'handle') === 'panel.reports.images.handle'
      && str_contains($src('includes/users.php'), "'panel.reports.images.handle'       =>"));
$modPerms = (string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'moderator'")->fetchColumn();
check('… which the moderators were given once', str_contains($modPerms, 'panel.reports.images.view') && str_contains($modPerms, 'panel.reports.images.handle'));

$bytes1 = $pic(300, 200);
$s1 = $store($up, $bytes1, 'cat.png');
$id1 = (int)($s1['row']['id'] ?? 0);
$publish($id1);
$s2 = $store($rep, $bytes1, 'same.png');
$id2 = (int)($s2['row']['id'] ?? 0);
$publish($id2);
$s3 = $store($up, $pic(120, 90), 'dog.png');
$id3 = (int)($s3['row']['id'] ?? 0);
$publish($id3);
$s4 = $store($up, $pic(64, 64), 'hidden.png');
$id4 = (int)($s4['row']['id'] ?? 0);
check('the pictures of the test', $id1 > 0 && $id2 > 0 && $id3 > 0 && $id4 > 0 && $id1 !== $id2, json_encode([$s1['ok'] ?? null, $s2['ok'] ?? null]));

$r = $rq($rep, ['id' => $id1, 'reason' => 'not allowed here']);
$row = $db->query("SELECT * FROM content_reports WHERE kind = 'image' AND target_id = " . $id1)->fetch(PDO::FETCH_ASSOC);
check('a member reports a picture somebody else uploaded', ($r['body']['success'] ?? false) === true && $row
      && (int)$row['author_id'] === $up && str_contains((string)$row['snapshot'], 'cat.png') && str_contains((string)$row['snapshot'], '/i/' . $id1 . '-'),
      json_encode([$r, $row]));
$r = $rq($rep, ['id' => $id1, 'reason' => 'again']);
check('… once while it is open', ($r['body']['already'] ?? false) === true, json_encode($r));
$r = $rq($up, ['id' => $id1, 'reason' => 'mine']);
check('… not their own picture', ($r['body']['error'] ?? '') === 'own_image' && str_contains((string)($r['body']['message'] ?? ''), 'your own picture'), json_encode($r));
$r = $rq($rep, ['id' => $id4, 'reason' => 'x']);
check('… nor one this reader cannot see', ($r['body']['error'] ?? '') === 'not_found', json_encode($r));
$r = $rq($rep, ['id' => 999999999, 'reason' => 'x']);
check('… nor one that does not exist', ($r['body']['error'] ?? '') === 'not_found', json_encode($r));
$r = $rq($rep, ['id' => $id3, 'reason' => 'second one']);
check('a second picture reported', ($r['body']['success'] ?? false) === true, json_encode($r));

$_SESSION['loggedin'] = true;
$_SESSION['admin_via_user'] = 0;
$kinds = contentReportPanelKinds($db, $cfgOn);
check('the panel has a Pictures queue', isset($kinds['image']) && $kinds['image']['on'] && $kinds['image']['handle']);
$list = contentReportList($db, $cfgOn, 'image', contentReportFilters([]), 1, 50);
$g = null;
foreach ($list['groups'] as $x) if ((int)$x['target_id'] === $id1) $g = $x;
check('… a card per picture: the picture, its size and address, where it is used, its uploader', $g !== null && $g['exists']
      && str_contains($g['html'], 'class="crep-img"') && str_contains($g['html'], '/i/' . $id1 . '-') && $g['author'] === 'irtest_up'
      && $g['copy'] === false && is_array($g['places']), json_encode($g));

$hc = 'c1830000000000000000000000000000000000c1';
$db->prepare("DELETE FROM hash_content WHERE info_hash = ?")->execute([$hc]);
$pageUrl = imagesUrl($cfgOn, $id1, (int)$s1['row']['w'], (int)$s1['row']['h'], 'webp');
$fullUrl = imagesUrl($cfgOn, $id1, (int)$s1['row']['full_w'], (int)$s1['row']['full_h'], 'webp');
$db->prepare("INSERT INTO hash_content (info_hash, description, description_format, content_status) VALUES (?, ?, 'bbcode', 'approved')")
   ->execute([$hc, "Look:\n[img]" . $pageUrl . "[/img]\n[url=" . $fullUrl . "?full=1]big[/url]"]);
$hcId = (int)$db->lastInsertId();
imagesRefsTouch($db, $cfgOn, 'idx', (string)$hcId);
$floor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM user_notifications")->fetchColumn();
$r = contentReportActionRequest($db, $cfgOn, ['kind' => 'image', 'target_id' => $id1, 'action' => 'remove', 'block' => 1, 'mode' => 'silent', 'seen' => $g['sha'] ?? '']);
$rm1 = imagesRemoval($db, $id1);
$rm2 = imagesRemoval($db, $id2);
check('remove and block: the picture and its other copies are gone, and the file is blocked',
      ($r['body']['success'] ?? false) === true && ($r['body']['removed_images'] ?? 0) === 2 && imagesRow($db, $id1) === null && imagesRow($db, $id2) === null
      && $rm1 !== null && (int)$rm1['blocked'] === 1 && $rm2 !== null && (int)$rm2['blocked'] === 1, json_encode([$r, $rm1, $rm2]));
$st = $db->prepare("SELECT title FROM user_notifications WHERE user_id = ? AND id > ?");
$st->execute([$rep, $floor]);
check('… the reporter hears it was removed', in_array('The picture you reported was removed', $st->fetchAll(PDO::FETCH_COLUMN), true));
$st = $db->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND id > ?");
$st->execute([$up, $floor]);
check('… the uploader hears nothing when it was silent', (int)$st->fetchColumn() === 0);
$closed = $db->query("SELECT status, outcome FROM content_reports WHERE kind = 'image' AND target_id = " . $id1)->fetch(PDO::FETCH_ASSOC);
check('… the report is closed as removed', ($closed['status'] ?? '') === 'closed' && ($closed['outcome'] ?? '') === 'removed', json_encode($closed));
$list = contentReportList($db, $cfgOn, 'image', contentReportFilters(['status' => 'all']), 1, 50);
$g = null;
foreach ($list['groups'] as $x) if ((int)$x['target_id'] === $id1) $g = $x;
check('… and its card says removed and blocked', $g !== null && !$g['exists'] && $g['state'] === 'deleted' && $g['blocked'] === true, json_encode($g));

$after = (string)$db->query("SELECT description FROM hash_content WHERE id = " . $hcId)->fetchColumn();
$db->prepare("DELETE FROM hash_content WHERE id = ?")->execute([$hcId]);
check('… and the texts that showed it point at a new address of the placeholder, so no browser shows it from its cache',
      str_contains($after, '[img]' . substr($pageUrl, 0, -4) . 'gif[/img]') && str_contains($after, substr($fullUrl, 0, -4) . 'gif?full=1]big')
      && !str_contains($after, '.webp'), $after);
$w = (int)$s1['row']['w']; $h = (int)$s1['row']['h'];
$svg = imagesServeRemoved($db, $id1, $w, $h, 'webp', __('images.removed_placeholder'));
check('its address answers with a placeholder of the same size', $svg !== null && $svg['mime'] === 'image/svg+xml' && $svg['public'] === false
      && str_contains($svg['data'], 'Picture removed by moderation') && str_contains($svg['data'], 'width="' . $w . '"'), json_encode($svg));
check('… at the new address too, and only for its own sizes', imagesServeRemoved($db, $id1, $w, $h, 'gif', 'x') !== null
      && imagesServeRemoved($db, $id1, $w + 1, $h, 'webp', 'x') === null && imagesServeRemoved($db, $id3, 120, 90, 'webp', 'x') === null);
check('… served by the picture endpoint', str_contains($src('api/image.php'), 'imagesServeRemoved($db,') && str_contains($src('api/image.php'), "panelCan(\$db, \$cfg, 'panel.reports.images.view')"));

$again = imagesStore($db, $cfgOn, $rep, $bytes1, 'again.png');
check('the blocked file cannot be uploaded again', empty($again['ok']) && ($again['code'] ?? '') === 'blocked' && ($again['status'] ?? 0) === 403
      && str_contains((string)($again['message'] ?? ''), 'removed by moderation'), json_encode($again));
$copy = imagesStore($db, $cfgOn, null, $bytes1, 'x.png', 'copy', 'https://forum.r183.test/x.png');
check('… nor copied from the forum: the copy points at the removed picture', empty($copy['ok']) && ($copy['code'] ?? '') === 'blocked' && (int)($copy['removal'] ?? 0) === $id1,
      json_encode($copy));
$url = 'https://forum.r183.test/assets/files/cat.png';
$db->prepare("INSERT INTO image_remote (url_sha, url, host, status, error, image_id) VALUES (?, ?, 'forum.r183.test', 'ignored', 'moderation', ?)")
   ->execute([hash('sha256', $url), $url, $id1]);
$map = imagesRemoteDone($db, $cfgOn, [$url]);
check('… and a forum address of it is settled to the placeholder\'s address', ($map[$url] ?? '') === imagesUrl($cfgOn, $id1, $w, $h, 'gif'), json_encode($map));
check('… the same for the texts already waiting for it', str_contains($src('includes/imagerefs.php'), "WHERE r.url_sha IN (\$in) AND r.status = 'ignored' AND r.error = 'moderation'")
      && str_contains($src('includes/images.php'), "UPDATE image_remote SET status = 'ignored', error = 'moderation', image_id = ?, done_at = NOW() WHERE url_sha = ?"));

$r = contentReportActionRequest($db, $cfgOn, ['kind' => 'image', 'target_id' => $id3, 'action' => 'remove', 'mode' => 'silent']);
$rm3 = imagesRemoval($db, $id3);
check('remove without block: only that picture goes, and the file may come back', ($r['body']['success'] ?? false) === true && ($r['body']['removed_images'] ?? 0) === 1
      && $rm3 !== null && (int)$rm3['blocked'] === 0 && imagesBlockedSha($db, (string)$rm3['blob_sha']) === null, json_encode([$r, $rm3]));

check('the uploader can be warned about a picture', str_contains($src('includes/moderation.php'), "['comment', 'description', 'shout', 'image', 'message', 'manual']")
      && __('notify.warn_removed_image') === 'Warning: your picture was removed' && __('notify.creport_what_image') === 'a picture');

$lb = $src('assets/js/lightbox.js');
check('the picture viewer has a Report flag for this site\'s own pictures, for a reader who may report',
      str_contains($lb, "window.Reports.open(flag, frame, { kind: 'image', id: id }") && str_contains($lb, "typeof APP_REPORT_IMAGES !== 'undefined' && APP_REPORT_IMAGES === true")
      && str_contains($lb, 'u.origin !== location.origin') && str_contains($src('templates/layout.php'), "const APP_REPORT_IMAGES = <?= json_encode(\$reportsHere && contentReportKindOn(\$cfg, 'image')) ?>;"));
check('… not in one\'s own gallery', str_contains($src('assets/js/user-images.js'), "alt: x.name || '', report: false }"));
check('… and its box keeps its keys from the viewer', str_contains($lb, "if (a && a.closest && a.closest('.report-box')) return;"));
$cr = $src('assets/js/admin-contentreports.js');
check('the panel card: remove the picture, block the file, where it is used', str_contains($cr, "t.key('js.creport.remove_image')")
      && str_contains($cr, "block: action === 'remove' && blockBox && blockBox.checked ? 1 : 0,") && str_contains($cr, "places(c, g.places || [])")
      && str_contains($src('templates/admin/dashboard.php'), "'image' => ['images', 'bi-image', 'a.reports.tab_images']"));
check('the words, in both languages', __('a.reports.tab_images') === 'Pictures' && __('js.creport.block_image') !== 'js.creport.block_image'
      && __('js.report.tip_image') === 'Report this picture to the moderators');

$wipe();
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
