<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/users.php',
          'includes/lang.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
$base = ['desc_allow_bbcode' => '1', 'site_url' => 'https://r1821.test/', 'link_trusted_domains' => ''];
$mag = 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=Clip&xl=1048576';

$card = richtextRender("[magnet]{$mag}[/magnet]", 'bbcode', ['rt_magnet_uri' => '1'] + $base, true);
check('magnet card: the whole hash, with the hash as its title, after the size and the swarm numbers',
      str_contains($card, '<span class="rt-magnet-hash" title="0123456789abcdef0123456789abcdef01234567">0123456789abcdef0123456789abcdef01234567</span>')
      && strpos($card, 'rt-magnet-sl') < strpos($card, 'rt-magnet-hash') && strpos($card, 'rt-magnet-clicks') < strpos($card, 'rt-magnet-hash'), $card);
$small = richtextRender("[magnet]{$mag}[/magnet]", 'bbcode', ['rt_magnet_uri' => '1', 'rt_magnet_compact' => '1'] + $base, true);
check('… the one-line card carries the hash only on what counts a click', str_contains($small, 'class="rt-magnet-open" data-h="0123456789abcdef0123456789abcdef01234567"')
      && str_contains($small, 'data-h="0123456789abcdef0123456789abcdef01234567" data-magnet='), $small);
check('… and a reader without index.magnet still gets no hash', !str_contains(richtextRender("[magnet]{$mag}[/magnet]", 'bbcode', ['rt_magnet_uri' => '0'] + $base, true), '0123456789abcdef'));

$click = $src('api/magnet_click.php');
check('clicks: POST with the token, index.magnet, a rate limit, once a person a day, the person kept as a keyed hash',
      str_contains($click, 'requirePost();') && str_contains($click, 'verifyCsrfToken(') && str_contains($click, "userCan(\$db, \$cfg, 'index.magnet')")
      && str_contains($click, "rateLimitAllow('magnet_click'") && str_contains($click, 'INSERT IGNORE INTO magnet_click_log') && str_contains($click, 'hash_hmac(')
      && str_contains($src('api.php'), "'magnet_click'"));
check('… the tables exist and the janitor trims the log', schemaTableExists($db, 'magnet_clicks') && schemaTableExists($db, 'magnet_click_log')
      && str_contains($src('tools/janitor.php'), 'richtextMagnetClicksPrune') && TRACKER_SCHEMA_VERSION >= 102);
$db->exec("DELETE FROM magnet_click_log WHERE info_hash = '0123456789abcdef0123456789abcdef01234567'");
$db->exec("INSERT INTO magnet_click_log (info_hash, who, day) VALUES ('0123456789abcdef0123456789abcdef01234567', 'x', UTC_DATE() - INTERVAL 5 DAY)");
check('… a log row older than two days goes', richtextMagnetClicksPrune($db) >= 1
      && (int)$db->query("SELECT COUNT(*) FROM magnet_click_log WHERE info_hash = '0123456789abcdef0123456789abcdef01234567'")->fetchColumn() === 0);
check('the card stats answer the clicks and whether a refresh is allowed', str_contains($src('api/magnet_stats.php'), "'clicks' => (object)\$clicks")
      && str_contains($src('api/magnet_stats.php'), "'refresh' => (\$cfg['search_allow_sl_refresh']"));
$mc = $src('assets/js/magnetcard.js');
check('the card script counts opens and copies, and refreshes through the Info panel\'s own refresh',
      str_contains($mc, "post('magnet_click'") && str_contains($mc, "post('index_info&hash=' + h, { op: 'refresh'") && str_contains($mc, 'rt-magnet-refresh'));

$app = $src('assets/js/app.js');
check('editor: the two menus in one group, put before the emoji group', str_contains($app, "group.className = 'rt-tool-group rt-menus';")
      && str_contains($app, "tools.insertBefore(group, tools.querySelector('.rt-tool-emoji'));"));
check('… placed inside the window that clips them, opening upwards when there is no room below',
      str_contains($app, 'const placeMenu = (menu) => {') && str_contains($app, "menu.classList.add('rt-menu-up')")
      && str_contains($src('assets/css/style.css'), '.rt-menu.rt-menu-up {'));

$common = $src('assets/js/admin-common.js');
check('panel: the Whitelist and Index windows can be resized and remember it', str_contains($common, 'function resizableDialog(modal, key)')
      && str_contains($src('assets/js/admin-whitelist.js'), "resizableDialog(modalEl, 'thx-wl-detail-size')")
      && str_contains($src('assets/js/admin-index.js'), "resizableDialog(\$('idxModal'), 'thx-idx-detail-size')"));
check('… the review search has the panel\'s clear button, not the browser\'s',
      str_contains($src('templates/admin/moderation.php'), 'id="rq-search-clear"') && str_contains($src('assets/css/admin.css'), '::-webkit-search-cancel-button'));

check('Index: a name search reads the narrow catalogue, counted up to a cap', function_exists('indexListFromCatalog') && INDEX_LIST_CATALOG_CAP === 10000
      && str_contains($src('includes/index.php'), 'indexListFromCatalog($db, $search, $catCols[$first[0]]'));
$db->exec("DELETE FROM index_catalog WHERE info_hash LIKE 'ce1821%'");
$db->exec("DELETE FROM index_hashes WHERE info_hash LIKE 'ce1821%'");
$ins = $db->prepare("INSERT INTO index_hashes (info_hash, name, first_seen, last_seen, meta_status) VALUES (?, ?, NOW(), NOW() - INTERVAL ? MINUTE, 'done')");
$cat = $db->prepare("INSERT INTO index_catalog (info_hash, name, last_seen) VALUES (?, ?, NOW() - INTERVAL ? MINUTE)");
foreach ([1, 2, 3] as $i) {
    $h = 'ce1821' . str_repeat('0', 33) . $i;
    $ins->execute([$h, 'Zorblax release ' . $i, $i]);
    $cat->execute([$h, 'Zorblax release ' . $i, $i]);
}
$r = indexListFromCatalog($db, 'Zorblax', 'last_seen', true, 2, 0, 'info_hash, name');
check('… the newest first, a page at a time, the total counted', is_array($r) && $r[0] === 3 && $r[2] === false
      && array_column($r[1], 'name') === ['Zorblax release 1', 'Zorblax release 2'], json_encode($r));
$db->exec("DELETE FROM index_catalog WHERE info_hash LIKE 'ce1821%'");
$db->exec("DELETE FROM index_hashes WHERE info_hash LIKE 'ce1821%'");

check('forum: a topic may ask for a turned-down text back', forumDescTopic(['discussion_id' => 1, 'post_id' => 2, 'restore' => true])['restore'] === true
      && forumDescTopic(['discussion_id' => 1, 'post_id' => 2])['restore'] === false && str_contains($src('templates/pages/apidocs.php'), 'apidocs.describe_restore'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
