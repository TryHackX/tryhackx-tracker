<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/auth.php',
          'includes/api_auth.php', 'includes/audit.php', 'includes/lang.php', 'includes/settings_catalog.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
$cfgI = array_merge($cfg, ['users_enabled' => '1', 'site_url' => 'https://img.test/', 'images_max_mb' => '8', 'images_max_edge' => '2048',
    'images_max_mpx' => '24', 'images_webp_quality' => '82', 'images_unused_hours' => '48', 'images_remote_scan_at' => '0']);

$pic = function (int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 60, 90, 200));
    imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), max(1, intdiv($w, 3)), max(1, intdiv($h, 3)), imagecolorallocate($im, 240, 200, 20));
    imagesetpixel($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    ob_start();
    imagepng($im);
    return (string)ob_get_clean();
};

$H = str_repeat('c0', 20);
$tidy = function () use ($db, $H): void {
    $st = $db->query("SELECT id FROM images WHERE source_url LIKE 'https://forum.copy.test/%' OR source_url LIKE 'https://tracker.copy.test/%'");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) imagesDelete($db, (int)$id);
    $db->exec("DELETE FROM image_remote WHERE host LIKE '%copy.test'");
    $db->exec("DELETE FROM api_clients WHERE label = 'imgcopytest'");
    $db->exec("DELETE FROM page_content WHERE page = 'imgcopytest'");
    $db->prepare("DELETE FROM hash_comments WHERE info_hash = ?")->execute([$H]);
};
$tidy();

check('schema: the copying queue, the image domains on a key (a CREATE column and a guarded ALTER), one download per address',
      schemaTableExists($db, 'image_remote') && schemaColumnExists($db, 'api_clients', 'image_domains')
      && str_contains($src('includes/schema.php'), "`image_domains` VARCHAR(1000) NOT NULL DEFAULT '',")
      && str_contains($src('includes/schema.php'), "ALTER TABLE `api_clients` ADD COLUMN `image_domains` VARCHAR(1000) NOT NULL DEFAULT ''")
      && str_contains($src('includes/schema.php'), "`url_sha` CHAR(64) NOT NULL PRIMARY KEY,"));

$d = imagesDomainsClean("https://Forum.Copy.TEST/path\n*.cdn.copy.test, 10.0.0.1 localhost bad_domain.test x..y  copy.test copy.test");
check('a key\'s domains are cleaned: an address becomes its host, *. is dropped, IPs, single names and junk go, each once',
      $d === ['forum.copy.test', 'cdn.copy.test', 'copy.test'], json_encode($d));
check('… at most twenty, and an array is read the same way as a text',
      count(imagesDomainsClean(implode(' ', array_map(fn($i) => 'd' . $i . '.copy.test', range(1, 30))))) === IMAGES_REMOTE_DOMAINS_MAX
      && imagesDomainsClean(['copy.test', 'x', 7, null]) === ['copy.test']);
check('a domain covers itself and its subdomains, never a name that merely ends with it',
      imagesHostMatches('copy.test', ['copy.test']) && imagesHostMatches('img.forum.copy.test', ['copy.test'])
      && imagesHostMatches('FORUM.copy.test.', ['copy.test'])
      && !imagesHostMatches('evilcopy.test', ['copy.test']) && !imagesHostMatches('copy.test.evil.example', ['copy.test']) && !imagesHostMatches('', ['copy.test']));

$text = "[img]https://forum.copy.test/a/poster.png[/img] ![x](https://forum.copy.test/b.JPG) [upl-image-preview url=https://forum.copy.test/assets/files/c.webp] "
      . "see https://forum.copy.test/d.gif. [img]https://forum.copy.test/render?id=7[/img] https://forum.copy.test/page.html "
      . "[img]https://other.example/e.png[/img] https://evilcopy.test/f.png [url=https://forum.copy.test/g.png]g[/url]";
$urls = imagesRemoteUrls($text, ['copy.test']);
check('the pictures a text holds from a key\'s domains: in [img], Markdown, an upload tag, bare with an image ending; a page link and other hosts are not',
      $urls === ['https://forum.copy.test/a/poster.png', 'https://forum.copy.test/b.JPG', 'https://forum.copy.test/assets/files/c.webp',
                 'https://forum.copy.test/d.gif', 'https://forum.copy.test/render?id=7', 'https://forum.copy.test/g.png'], json_encode($urls));
check('… and nothing at all without a domain to copy from', imagesRemoteUrls($text, []) === []);

$t5 = imagesReplaceUrl("[img]https://f.copy.test/a.png[/img] https://f.copy.test/a.png.webp https://f.copy.test/a.png. (https://f.copy.test/a.png) https://f.copy.test/a.pngx",
                       'https://f.copy.test/a.png', 'https://img.test/i/9-10x10.webp');
check('an address is replaced only where it ends: never the start of a longer address',
      $t5 === "[img]https://img.test/i/9-10x10.webp[/img] https://f.copy.test/a.png.webp https://img.test/i/9-10x10.webp. (https://img.test/i/9-10x10.webp) https://f.copy.test/a.pngx", $t5);

$resolveOk = fn(string $h): array => ['93.184.216.34'];
$calls = [];
$getPng = function (string $url, string $host, int $port, string $ip, int $max) use (&$calls, $pic): array {
    $calls[] = [$url, $host, $port, $ip];
    return ['ok' => true, 'bytes' => $pic(1200, 800)];
};
$f = fn(string $u, array $more = [], int $max = 8388608) => imagesRemoteFetch($u, ['copy.test'], $max, array_merge(['resolve' => $resolveOk, 'get' => $getPng], $more));
check('only http and https, only ports 80 and 443, no credentials in the address',
      ($f('ftp://forum.copy.test/a.png')['error'] ?? '') === 'bad_url' && ($f('https://forum.copy.test:8443/a.png')['error'] ?? '') === 'bad_port'
      && ($f('http://forum.copy.test:443/a.png')['error'] ?? '') === 'bad_port' && ($f('https://u:p@forum.copy.test/a.png')['error'] ?? '') === 'bad_url'
      && ($f('javascript:alert(1)')['error'] ?? '') === 'bad_url');
check('only a key\'s domain, never an address written as a number',
      ($f('https://other.example/a.png')['error'] ?? '') === 'not_allowed' && ($f('https://93.184.216.34/a.png')['error'] ?? '') === 'not_allowed'
      && ($f('https://[2001:db8::1]/a.png')['error'] ?? '') === 'not_allowed' && ($f('https://evilcopy.test/a.png')['error'] ?? '') === 'not_allowed');
$priv = fn(array $ips) => ($f('https://forum.copy.test/a.png', ['resolve' => fn() => $ips])['error'] ?? '');
check('a name that resolves to a private, loopback, link-local or reserved address is refused — if any one of its addresses is',
      $priv(['127.0.0.1']) === 'private_address' && $priv(['10.1.2.3']) === 'private_address' && $priv(['169.254.169.254']) === 'private_address'
      && $priv(['93.184.216.34', '192.168.1.5']) === 'private_address' && $priv(['::1']) === 'private_address' && $priv(['fd00::5']) === 'private_address'
      && $priv(['::ffff:127.0.0.1']) === 'private_address' && $priv([]) === 'private_address' && $priv(['0.0.0.0']) === 'private_address');
$calls = [];
$ok = $f('https://img.forum.copy.test/a.png');
check('… and a public one is fetched AT the checked address (the connection is pinned to it)',
      !empty($ok['ok']) && count($calls) === 1 && $calls[0][1] === 'img.forum.copy.test' && $calls[0][2] === 443 && $calls[0][3] === '93.184.216.34', json_encode($calls));
check('… a body over the limit is refused even when the transfer let it through',
      ($f('https://forum.copy.test/a.png', ['get' => fn() => ['ok' => true, 'bytes' => str_repeat('x', 2000)]], 1000)['error'] ?? '') === 'too_big');
$g = $src('includes/images.php');
$getFn = (string)substr($g, (int)strpos($g, 'function imagesRemoteGet('), 2600);
check('the transfer: pinned with CURLOPT_RESOLVE, no redirect followed, http(s) only, timeouts, the body capped while it arrives',
      str_contains($getFn, 'CURLOPT_RESOLVE =>') && str_contains($getFn, 'CURLOPT_FOLLOWLOCATION => false') && str_contains($getFn, 'CURLOPT_MAXREDIRS => 0')
      && str_contains($getFn, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS') && str_contains($getFn, 'CURLOPT_TIMEOUT => IMAGES_REMOTE_TIMEOUT')
      && str_contains($getFn, 'CURLOPT_CONNECTTIMEOUT => IMAGES_REMOTE_CONNECT') && str_contains($getFn, 'CURLOPT_WRITEFUNCTION =>')
      && str_contains($getFn, "return ['ok' => false, 'error' => 'redirect'];") && str_contains($getFn, 'CURLOPT_SSL_VERIFYPEER => true'));

$cl = apiClientCreate($db, 'imgcopytest', 'whitelist');
$cid = (int)$cl['id'];
$db->prepare("UPDATE api_clients SET image_domains = ?, image_consent = 1 WHERE id = ?")->execute(['copy.test', $cid]);
check('the domains copied from are the ENABLED keys\' ones', in_array('copy.test', imagesRemoteHosts($db, true), true));
$db->prepare("UPDATE api_clients SET enabled = 0 WHERE id = ?")->execute([$cid]);
$off = imagesRemoteHosts($db, true);
$db->prepare("UPDATE api_clients SET enabled = 1 WHERE id = ?")->execute([$cid]);
check('… a disabled key copies nothing', !in_array('copy.test', $off, true) && in_array('copy.test', imagesRemoteHosts($db, true), true));

$U1 = 'https://forum.copy.test/assets/files/poster.png';
$U2 = 'https://forum.copy.test/assets/files/broken.png';
$U3 = 'https://forum.copy.test/assets/files/notimage.png';
$db->prepare("INSERT INTO page_content (page, lang, format, body, enabled) VALUES ('imgcopytest', 'en', 'bbcode', ?, 1)")
   ->execute(["Poster: [img]$U1" . "[/img] and [img]$U2" . "[/img] and [img]$U3" . "[/img] and [img]https://other.example/x.png[/img]"]);
$db->prepare("INSERT INTO hash_comments (info_hash, user_id, body, status) VALUES (?, NULL, ?, 'visible')")->execute([$H, "look [img]$U1" . "[/img]"]);
$cmId = (int)$db->lastInsertId();
$calls = [];
$get = function (string $url, string $host, int $port, string $ip, int $max) use (&$calls, $pic): array {
    $calls[] = $url;
    if (str_contains($url, 'broken')) return ['ok' => false, 'error' => 'http_404'];
    if (str_contains($url, 'notimage')) return ['ok' => true, 'bytes' => 'hello, this is not a picture'];
    return ['ok' => true, 'bytes' => $pic(1200, 800)];
};
$io = ['force_scan' => true, 'resolve' => $resolveOk, 'get' => $get];
$t = imagesRemoteTick($db, $cfgI, $io);
$rowOf = function (string $u) use ($db): array {
    $st = $db->prepare("SELECT * FROM image_remote WHERE url_sha = ?");
    $st->execute([hash('sha256', $u)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$r1 = $rowOf($U1);
$img = $r1 ? imagesRow($db, (int)$r1['image_id']) : null;
check('the janitor\'s pass: the texts scanned, three addresses queued, one copied, the others failed for now',
      $t['hosts'] >= 1 && $t['queued'] >= 3 && $t['copied'] >= 1 && $t['failed'] >= 2 && $t['error'] === null
      && count(array_filter([$U1, $U2, $U3], fn($u) => $rowOf($u) !== [])) === 3, json_encode($t));
check('… the copy: one picture, nobody\'s, marked as copied from its address, public at once',
      ($r1['status'] ?? '') === 'done' && $img !== null && $img['user_id'] === null && $img['source'] === 'copy'
      && $img['source_url'] === $U1 && $img['public_at'] !== null && (int)$img['w'] === 960 && (int)$img['h'] === 640, json_encode([$r1, $img]));
$own = $img ? imagesUrl($cfgI, (int)$img['id'], 960, 640, 'webp') : '';
$page = (string)$db->query("SELECT body FROM page_content WHERE page = 'imgcopytest'")->fetchColumn();
$cm = (string)$db->query("SELECT body FROM hash_comments WHERE id = " . $cmId)->fetchColumn();
check('… every text that showed it now shows this site\'s copy; the address that failed and the other site stay as written',
      $own !== '' && str_contains($page, "[img]$own" . "[/img]") && !str_contains($page, $U1) && str_contains($page, $U2)
      && str_contains($page, 'https://other.example/x.png') && $cm === "look [img]$own" . "[/img]", $page . ' | ' . $cm);
check('… one download for an address that is in two texts', count(array_filter($calls, fn($u) => $u === $U1)) === 1, json_encode($calls));
$r2 = $rowOf($U2);
$r3 = $rowOf($U3);
check('a passing failure waits and is tried again later; something that is not a picture is given up at once',
      ($r2['status'] ?? '') === 'pending' && (int)$r2['tries'] === 1 && $r2['error'] === 'http_404'
      && strtotime((string)$r2['next_at']) > strtotime((string)$db->query("SELECT NOW()")->fetchColumn())
      && ($r3['status'] ?? '') === 'failed' && $r3['error'] === 'bad_type', json_encode([$r2, $r3]));
$calls = [];
imagesRemoteTick($db, $cfgI, $io);
check('… the next pass downloads nothing that is not due', $calls === [], json_encode($calls));
$db->prepare("UPDATE image_remote SET tries = 4, next_at = NOW() WHERE url_sha = ?")->execute([hash('sha256', $U2)]);
imagesRemoteTick($db, $cfgI, $io);
check('… and the fifth failure is the last', ($rowOf($U2)['status'] ?? '') === 'failed' && (int)$rowOf($U2)['tries'] === 5);
check('a failed copy can be tried again from the panel, or dropped; a finished one cannot be dropped',
      imagesRemoteRetry($db, hash('sha256', $U2)) && ($rowOf($U2)['status'] ?? '') === 'pending' && (int)$rowOf($U2)['tries'] === 0
      && imagesRemoteForget($db, hash('sha256', $U2)) && $rowOf($U2) === [] && !imagesRemoteForget($db, hash('sha256', $U1)));

$U4 = 'https://forum.copy.test/assets/files/new.png';
$settled = imagesRemoteSettle($db, $cfgI, "a [img]$U1" . "[/img] b [img]$U4" . "[/img]");
check('a text being saved: a copied address becomes this site\'s at once, a new one joins the queue',
      $settled === "a [img]$own" . "[/img] b [img]$U4" . "[/img]" && ($rowOf($U4)['status'] ?? '') === 'pending', $settled);
$U5 = 'https://forum.copy.test/assets/files/looked-at.png';
$looked = imagesRemoteSettle($db, $cfgI, "a [img]$U1" . "[/img] c [img]$U5" . "[/img]", false);
check('… a text only being compared shows the copies and queues nothing', $looked === "a [img]$own" . "[/img] c [img]$U5" . "[/img]"
      && $rowOf($U5) === [], $looked);
check('the scan runs at most once in ten minutes unless the janitor is told otherwise',
      imagesRemoteScan($db, array_merge($cfgI, ['images_remote_scan_at' => (string)time()]), ['copy.test']) === 0);
$served = $img ? imagesServe($db, (int)$img['id'], 960, 640, 'webp', false, null, false) : null;
check('a copy is served to anybody, cached for good', $served !== null && $served['public'] === true && $served['mime'] === 'image/webp');

$sum = imagesPanelSummary($db);
check('the panel\'s summary: pictures, space, public, copied, and the queue by state',
      $sum['images'] >= 1 && $sum['copies'] >= 1 && $sum['bytes'] > 0 && $sum['queue']['done'] >= 1 && $sum['queue']['failed'] >= 1 && $sum['queue']['pending'] >= 1, json_encode($sum));
$byId = imagesPanelList($db, $cfgI, ['q' => '#' . (int)$img['id']]);
$copied = imagesPanelList($db, $cfgI, ['state' => 'copied', 'sort' => 'big']);
$private = imagesPanelList($db, $cfgI, ['state' => 'private']);
check('the panel\'s list: by #id, the copied ones, the unpublished ones; each with its owner, origin and both sizes',
      $byId['total'] === 1 && $byId['rows'][0]['id'] === (int)$img['id'] && $byId['rows'][0]['source'] === 'copy'
      && $byId['rows'][0]['source_url'] === $U1 && $byId['rows'][0]['user_id'] === null && $byId['rows'][0]['full_w'] === 1200
      && in_array((int)$img['id'], array_column($copied['rows'], 'id'), true) && !in_array((int)$img['id'], array_column($private['rows'], 'id'), true)
      && $copied['per_page'] === IMAGES_PANEL_PER_PAGE, json_encode($byId));
$like = imagesPanelList($db, $cfgI, ['q' => 'poster.png']);
check('… found by its file name or address too', in_array((int)$img['id'], array_column($like['rows'], 'id'), true));
$used = imagesWhereUsed($db, (int)$img['id']);
check('where a picture is used: the page and the comment that show it',
      count(array_filter($used, fn($u) => $u['kind'] === 'page' && $u['page'] === 'imgcopytest')) === 1
      && count(array_filter($used, fn($u) => $u['kind'] === 'comment' && $u['id'] === $cmId && $u['hash'] === $H)) === 1, json_encode($used));
check('a copy deleted in the panel is never copied again', imagesPanelDelete($db, (int)$img['id']) && imagesRow($db, (int)$img['id']) === null
      && ($rowOf($U1)['status'] ?? '') === 'failed' && $rowOf($U1)['error'] === 'removed');

$auth = $src('includes/auth.php');
$nav = array_values(array_filter(adminNavItems(), fn($i) => $i['action'] === 'admin-images'));
check('the Images page: a panel page of its own, behind panel.images.view, routed, with its words',
      in_array('admin-images', adminReservedActions(), true) && in_array('admin-images', adminPanelActions(), true)
      && count($nav) === 1 && $nav[0]['perm'] === 'panel.images.view' && $nav[0]['icon'] === 'bi-image'
      && str_contains($src('index.php'), "\$action === 'admin-images'") && str_contains($src('index.php'), "include __DIR__ . '/templates/admin/images.php';")
      && langHas('a.head.nav.admin_images') && langHas('a.img.title') && langHas('js.aimg.summary') && langHas('js.aimg.delete_used_one') && langHas('js.aimg.delete_used_many'));
$api = $src('api.php');
check('… its two endpoints: reading needs panel.images.view, deleting and the queue panel.images.manage',
      str_contains($api, "'admin/images'                => 'api/admin/images.php',") && str_contains($api, "'admin/image_action'          => 'api/admin/image_action.php',")
      && str_contains($api, "'admin/images'                => 'panel.images.view',") && str_contains($api, "'admin/image_action'          => 'panel.images.manage',")
      && str_contains($src('api/admin/image_action.php'), 'requirePost();') && str_contains($src('api/admin/image_action.php'), "auditNote(['action' => 'image.delete'"));
check('… the permissions exist, the moderator holds them, and a deletion is filed under content in the log',
      isset(userPermissionList(true)['panel.images.view'], userPermissionList(true)['panel.images.manage'])
      && in_array('panel.images.view', userGroupPresets()['moderator']['perms'], true) && in_array('panel.images.manage', userGroupPresets()['moderator']['perms'], true)
      && auditGroupOf('image.delete') === 'content' && auditGroupOf('image.retry') === 'content');
check('… a moderator of the page may open a picture nobody has published yet',
      str_contains($src('api/image.php'), "panelCan(\$db, \$cfg, 'panel.images.view')"));
$tpl = $src('templates/admin/images.php');
$js = $src('assets/js/admin-images.js');
check('… the page and its script: the list with its filters, where a picture is used, deleting, the copying queue',
      str_contains($tpl, "\$current = 'admin-images';") && str_contains($tpl, 'id="img-grid"') && str_contains($tpl, 'id="img-q-body"')
      && str_contains($tpl, 'assets/js/admin-images.js') && str_contains($js, "apiCall('admin/images&view=used&id='")
      && str_contains($js, "apiCall('admin/image_action', 'POST', { op: 'delete', id: r.id })") && str_contains($js, "queueAct('retry', r, b)")
      && !preg_match('~^\s*//|/\*~m', $js));

check('a key\'s image domains: asked in the key\'s dialog, saved on create and on change, listed with the key',
      str_contains($src('templates/admin/whitelist.php'), 'id="cl-opts-imgdomains"')
      && str_contains($src('assets/js/admin-whitelist.js'), "image_domains: imgDomains.value.split(")
      && str_contains($src('assets/js/admin-whitelist.js'), 'const body = { id: c.id, label: v.label, image_domains: v.image_domains };')
      && str_contains($src('api/admin/api_client_create.php'), "\$domains = imagesDomainsClean(\$input['image_domains'] ?? []);")
      && str_contains($src('api/admin/api_client_update.php'), "\$params[] = implode(' ', imagesDomainsClean(\$input['image_domains']));")
      && str_contains($src('api/admin/fetch_api_clients.php'), "\$c['image_domains'] = imagesDomainsClean((string)\$c['image_domains']);"));
$cfgO = array_merge($cfgI, ['site_url' => 'https://tracker.copy.test/']);
$ownText = "[img]https://tracker.copy.test/i/5-10x10.webp[/img] [img]https://forum.copy.test/assets/files/z.png[/img] https://TRACKER.copy.test/assets/logo.png";
check('this site\'s own pictures are never queued, even when its host is under a key\'s domain',
      imagesRemoteUrls($ownText, ['copy.test'], 'https://tracker.copy.test/') === ['https://forum.copy.test/assets/files/z.png']
      && imagesOwnAt('https://copy.test/tracker/', 'http://COPY.test/tracker/i/1-1x1.webp') && !imagesOwnAt('https://copy.test/tracker/', 'https://copy.test/forum/a.png')
      && !imagesOwnAt('https://tracker.copy.test/', 'https://forum.copy.test/a.png') && !imagesOwnAt('https://tracker.copy.test/', 'https://evil.example/https://tracker.copy.test/'),
      json_encode(imagesRemoteUrls($ownText, ['copy.test'], 'https://tracker.copy.test/')));
$calls = [];
$ownCopy = imagesRemoteCopy($db, $cfgO, hash('sha256', 'own'), 'https://tracker.copy.test/i/5-10x10.webp', 0, ['copy.test'], ['resolve' => $resolveOk, 'get' => $getPng]);
check('… and one already in the queue is given up at once, without a download',
      empty($ownCopy['ok']) && ($ownCopy['error'] ?? '') === 'own_site' && !empty($ownCopy['final']) && $calls === [], json_encode($ownCopy));

$UF = 'https://forum.copy.test/assets/files/chain.png';
$ca = imagesStore($db, $cfgO, null, $pic(300, 200), 'chain.png', 'copy', $UF)['row'];
$caUrl = imagesUrl($cfgO, (int)$ca['id'], (int)$ca['w'], (int)$ca['h'], 'webp');
$cb = imagesStore($db, $cfgO, null, $pic(300, 200), 'b.webp', 'copy', $caUrl)['row'];
$cbUrl = imagesUrl($cfgO, (int)$cb['id'], (int)$cb['w'], (int)$cb['h'], 'webp');
$cc = imagesStore($db, $cfgO, null, $pic(300, 200), 'c.webp', 'copy', $cbUrl)['row'];
$ccUrl = imagesUrl($cfgO, (int)$cc['id'], (int)$cc['w'], (int)$cc['h'], 'webp');
$q = $db->prepare("INSERT INTO image_remote (url_sha, url, host, status, image_id) VALUES (?, ?, ?, ?, ?)");
foreach ([[$UF, 'forum.copy.test', 'done', (int)$ca['id']], [$caUrl, 'tracker.copy.test', 'done', (int)$cb['id']],
          [$cbUrl, 'tracker.copy.test', 'done', (int)$cc['id']], [$ccUrl, 'tracker.copy.test', 'pending', null]] as [$u, $h, $s, $i]) {
    $q->execute([hash('sha256', $u), $u, $h, $s, $i]);
}
$db->prepare("INSERT INTO page_content (page, lang, format, body, enabled) VALUES ('imgcopytest', 'de', 'bbcode', ?, 1)")->execute(["chain [img]$ccUrl" . "[/img]."]);
$db->prepare("INSERT INTO hash_comments (info_hash, user_id, body, status) VALUES (?, NULL, ?, 'visible')")->execute([$H, "older $cbUrl, here"]);
$chainCm = (int)$db->lastInsertId();
$rep = imagesOwnCopiesRepair($db, $cfgO);
$pageDe = (string)$db->query("SELECT body FROM page_content WHERE page = 'imgcopytest' AND lang = 'de'")->fetchColumn();
$cmDe = (string)$db->query("SELECT body FROM hash_comments WHERE id = " . $chainCm)->fetchColumn();
check('copies of this site\'s own copies are undone: every text shows the first copy again, the later ones and their queue rows are gone',
      $pageDe === "chain [img]$caUrl" . "[/img]." && $cmDe === "older $caUrl, here" && imagesRow($db, (int)$ca['id']) !== null
      && imagesRow($db, (int)$cb['id']) === null && imagesRow($db, (int)$cc['id']) === null
      && $rowOf($caUrl) === [] && $rowOf($cbUrl) === [] && $rowOf($ccUrl) === [] && ($rowOf($UF)['image_id'] ?? null) == $ca['id']
      && $rep === ['queue' => 3, 'texts' => 2, 'images' => 2], json_encode([$rep, $pageDe, $cmDe]));
check('… once: a second pass finds nothing to do, and the janitor\'s pass runs it first and says so',
      imagesOwnCopiesRepair($db, $cfgO) === ['queue' => 0, 'texts' => 0, 'images' => 0]
      && array_key_exists('repaired', imagesRemoteTick($db, $cfgO, ['resolve' => $resolveOk, 'get' => $getPng]))
      && str_contains($src('tools/janitor.php'), "repaired=%d"));

check('the copying runs in the janitor\'s slow half, never in a request',
      str_contains($src('tools/janitor.php'), '$ir = imagesRemoteTick($db, $cfg);')
      && strpos($src('tools/janitor.php'), '$ir = imagesRemoteTick($db, $cfg);') < strpos($src('tools/janitor.php'), 'function janitorHeavyDispatch('));

$tidy();
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
