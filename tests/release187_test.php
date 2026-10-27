<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/sounds.php',
          'includes/shout.php', 'includes/emoji.php', 'includes/people.php', 'includes/audit.php', 'includes/icons.php',
          'includes/api_auth.php', 'includes/settings_catalog.php', 'includes/reputation.php', 'includes/lang.php',
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

check('schema 111: covers of each torrent, and their settings', TRACKER_SCHEMA_VERSION >= 111 && schemaTableExists($db, 'hash_thumbs') && thumbsReady($db)
      && ($cfg['thumbs_search'] ?? '') === '1' && ($cfg['thumbs_lists'] ?? '') === '1' && ($cfg['thumbs_width'] ?? '') === '64'
      && ($cfg['thumbs_speed'] ?? '') === '4' && ($cfg['thumbs_max'] ?? '') === '10');

$kept = [];
foreach (['site_url', 'thumbs_cursor'] as $k) {
    $st = $db->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $st->execute([$k]);
    $kept[$k] = $st->fetchColumn();
}
if (!preg_match('#^https?://#', (string)$kept['site_url'])) $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('site_url', 'http://tracker.test/')")->execute();
$cfgS = getSettings($db, true);
$H = fn(int $i): string => str_repeat('e187', 9) . sprintf('%04x', $i);
$made = [];
$tidy = function () use ($db, $H, &$made, $kept): void {
    foreach ($made as $id) imagesDelete($db, $id);
    foreach ([1, 2] as $i) {
        foreach (['hash_thumbs', 'hash_votes', 'whitelist', 'desc_facts'] as $tbl) $db->prepare("DELETE FROM $tbl WHERE info_hash = ?")->execute([$H($i)]);
    }
    foreach ($kept as $k => $v) {
        if ($v === false) $db->prepare("DELETE FROM settings WHERE `key` = ?")->execute([$k]);
        else $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES (?, ?)")->execute([$k, $v]);
    }
};
register_shutdown_function($tidy);
$pic = function (int $w, int $h, array $rgb): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
    imagesetpixel($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    ob_start();
    imagepng($im);
    return (string)ob_get_clean();
};
$store = function (int $w, int $h, array $rgb, string $name) use ($db, $cfgS, $pic, &$made): array {
    $r = imagesStore($db, $cfgS, null, $pic($w, $h, $rgb), $name, 'copy', 'https://forum.test/' . $name);
    $made[] = (int)$r['row']['id'];
    return $r['row'];
};
$a = $store(300, 450, [200, 40, 40], 'a.png');
$b = $store(320, 480, [40, 200, 40], 'b.png');
$c = $store(800, 450, [40, 40, 200], 'c.png');
$icon = $store(48, 48, [90, 90, 90], 'icon.png');
$url = fn(array $r): string => imagesUrl($cfgS, (int)$r['id'], (int)$r['w'], (int)$r['h'], imagesExt($r));
$text = "Opis [img]" . $url($a) . "[/img]\n[thxlabels][img]" . $url($b) . "[/img][/thxlabels]\n[img]" . $url($icon) . "[/img] " . $url($c) . " https://elsewhere.test/i/" . (int)$a['id'] . "-1x1.webp " . $url($a);
check('the order: posters from the labels first, then the text\'s order, each once, and only this site\'s pictures',
      thumbsIds($text, imagesBase($cfgS)) === [(int)$b['id'], (int)$a['id'], (int)$icon['id'], (int)$c['id']], json_encode(thumbsIds($text, imagesBase($cfgS))));
$db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned, probe_status, description, description_format, content_status) VALUES (?, 'Zyx Covers', 'forum', NOW(), 0, 'passed', ?, 'bbcode', 'approved')")
   ->execute([$H(1), $text]);
$made1 = thumbsFromText($db, $H(1), $text);
$st = $db->prepare("SELECT image_id FROM hash_thumbs WHERE info_hash = ? ORDER BY pos");
$st->execute([$H(1)]);
$rows = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
$file = thumbsDir() . '/' . thumbsName((int)$b['id'], (string)$b['blob_sha']);
$size = is_file($file) ? @getimagesize($file) : false;
check('three covers kept (the icon is too small), as 160×240 WebP files beside the site', $made1 === 3 && $rows === [(int)$b['id'], (int)$a['id'], (int)$c['id']]
      && is_array($size) && $size[0] === THUMBS_W && $size[1] === THUMBS_H && ($size['mime'] ?? '') === 'image/webp', json_encode([$made1, $rows, $size]));
$cs = @getimagesize(thumbsDir() . '/' . thumbsStripName((int)$c['id'], (string)$c['blob_sha']));
check('… and beside each a strip picture in its own shape, 240 high, for Info', is_array($cs) && $cs[1] === THUMBS_H && abs($cs[0] / $cs[1] - 800 / 450) < 0.02, json_encode($cs));
factsStore($db, $H(1), 'wl', $text, 'Zyx Covers');
$for = thumbsFor($db, $cfgS, [$H(1)], true)[$H(1)] ?? null;
check('a published description\'s covers: the files and the full pictures, the width and the pace', $for !== null && count($for['t']) === 3
      && $for['t'][0] === 'thumbs/' . thumbsName((int)$b['id'], (string)$b['blob_sha']) && $for['f'][0] === 'i/' . (int)$b['id'] . '-' . (int)$b['full_w'] . 'x' . (int)$b['full_h'] . '.webp'
      && $for['w'] === 64 && $for['s'] === 4, json_encode($for));
check('… at most as many as Settings says', count(thumbsFor($db, ['thumbs_max' => '2'] + $cfgS, [$H(1)], true)[$H(1)]['t'] ?? []) === 2);
$gal = thumbsGallery($db, $H(1), true);
check('Info\'s strip: every picture in the same order, in its own shape, with the full picture behind it', count($gal) === 3
      && $gal[0]['t'] === 'thumbs/' . thumbsStripName((int)$b['id'], (string)$b['blob_sha']) && $gal[0]['f'] === ($for['f'][0] ?? null)
      && $gal[2]['w'] === (int)$c['full_w'] && $gal[2]['h'] === (int)$c['full_h'], json_encode($gal));
$db->prepare("UPDATE whitelist SET content_status = 'pending' WHERE info_hash = ?")->execute([$H(1)]);
check('… and none while the description waits', thumbsFor($db, $cfgS, [$H(1)], true) === [] && thumbsGallery($db, $H(1), true) === []);
$db->prepare("UPDATE whitelist SET content_status = 'approved' WHERE info_hash = ?")->execute([$H(1)]);
thumbsForget($db, (int)$b['id']);
check('a picture taken away loses its cover file and its place at once', !is_file($file) && count(thumbsFor($db, $cfgS, [$H(1)], true)[$H(1)]['t'] ?? []) === 2);
$cFile = thumbsDir() . '/' . thumbsName((int)$c['id'], (string)$c['blob_sha']);
imagesDelete($db, (int)$c['id']);
$made = array_values(array_diff($made, [(int)$c['id']]));
check('deleting a picture deletes its cover', !is_file($cFile));
$db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('thumbs_cursor', '')")->execute();
$tick = thumbsTick($db, getSettings($db, true), 500);
$st = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'thumbs_cursor'");
$st->execute();
check('the janitor walks the descriptions already there and moves its cursor', $tick['read'] >= 1 && (string)$st->fetchColumn() !== '', json_encode($tick));
$aFile = thumbsDir() . '/' . thumbsName((int)$a['id'], (string)$a['blob_sha']);
$aStrip = thumbsDir() . '/' . thumbsStripName((int)$a['id'], (string)$a['blob_sha']);
@unlink($aFile);
@unlink($aStrip);
$db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('thumbs_cursor', 'done')")->execute();
$tick = thumbsTick($db, getSettings($db, true));
check('a cover file lost from the disk is made again on the next run', is_file($aFile) && is_file($aStrip) && $tick['made'] >= 1, json_encode($tick));
check('a description read again makes its covers again', str_contains($src('includes/facts.php'), "if (function_exists('thumbsFromText')) thumbsFromText(\$db, \$hash, \$text);")
      && str_contains($src('tools/janitor.php'), '$thumbsRun = thumbsTick($db, $cfg);'));
check('the moderation removal and the deletion both take the cover', str_contains($src('includes/images.php'), "if (function_exists('thumbsForget')) thumbsForget(\$db, \$one);")
      && str_contains($src('includes/images.php'), "if (function_exists('thumbsForget')) thumbsForget(\$db, \$id);"));

foreach ([[10, 'u1'], [9, 'u2'], [4, 'u3']] as [$v, $who]) {
    $db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote, scale) VALUES (?, 'ip', ?, ?, 'stars')")->execute([$H(2), $who, $v]);
}
check('the stars\' histogram and the last rating', repHistogram($db, $H(2)) === [5 => 2, 4 => 0, 3 => 0, 2 => 1, 1 => 0]
      && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)repLastRated($db, $H(2))) === 1 && repLastRated($db, $H(1)) === null, json_encode(repHistogram($db, $H(2))));
check('Info sends them', str_contains($src('api/index_info.php'), "'rating_hist' => repEnabled(\$cfg) && repMode(\$cfg) === 'stars' ? repHistogram(\$db, \$hash) : null,")
      && str_contains($src('api/index_info.php'), "'rated_at' => repEnabled(\$cfg) ? repLastRated(\$db, \$hash) : null,"));
check('Info sends the strip where the reader may read the description and Settings has it on, up to 30 pictures',
      str_contains($src('api/index_info.php'), "'gallery' => (\$canContent && \$hasWords && function_exists('thumbsOn') && thumbsOn(\$cfg, 'info')) ? thumbsGallery(\$db, \$hash, richtextViewerSignedIn(\$db)) : [],")
      && thumbsOn(['thumbs_info' => '0'], 'info') === false && thumbsOn([], 'info') === true && THUMBS_KEEP === 30 && str_contains($src('includes/thumbs.php'), 'if (count($keep) >= THUMBS_KEEP) break;'));
$app = $src('assets/js/app.js');
check('Info draws them; an empty rating is not shown to who cannot vote; on a phone the stars open in a window',
      str_contains($app, 'if (json.rating && (json.rating.total > 0 || json.can_vote)) {') && str_contains($app, 'function buildHist(hist, total)')
      && str_contains($app, "window.matchMedia('(max-width: 576px)').matches) rep.appendChild(rateOpenButton(r, json, hash));") && str_contains($app, "dlg.showModal()"));
$js = $src('assets/js/filters.js');
check('the cover slider: one picture at first, the next ones only while in view, held by the pointer, still for less motion, a click opens the pictures',
      str_contains($js, 'window.ThumbSlider = {') && str_contains($js, "layers[0].src = list[0];") && str_contains($js, 'new IntersectionObserver(')
      && str_contains($js, "box.addEventListener('mouseenter', () => { hover = true; api.stop(); });") && str_contains($js, "prefers-reduced-motion: reduce")
      && str_contains($js, 'window.SiteLightbox.show(full.map('));
check('… in the search and on profile lists, the name and its tags in the middle beside it', str_contains($app, 'nameWrap.append(thumbBox, nameBody);')
      && str_contains($src('assets/css/style.css'), '.search-name-wrap { display: flex; align-items: center; gap: 0.6rem; }')
      && str_contains($src('assets/js/favourites.js'), 'window.ThumbSlider.mount(thumbBox, r.thumb)') && str_contains($src('api/index_search.php'), "\$row['thumb'] = \$thumbMap[")
      && str_contains($src('includes/facts.php'), "if (isset(\$thumbs[\$h])) \$r['thumb'] = \$thumbs[\$h];") && str_contains($src('assets/css/style.css'), '@media (max-width: 576px) { .ts { width: 56px; } }'));
check('Info draws the strip at the top: arrows only while there is more to see, a click opens them all in the viewer',
      str_contains($app, 'if (Array.isArray(json.gallery) && json.gallery.length) body.appendChild(infoGallery(json.gallery));')
      && str_contains($app, 'window.SiteLightbox.show(full, i, img)') && str_contains($app, "box.classList.toggle('can-next', track.scrollLeft < end);")
      && str_contains($src('assets/css/style.css'), '.info-gal-nav:disabled { opacity: 0; pointer-events: none; }'));
check('the settings: on the page, saved within their bounds, with search words', str_contains($src('templates/admin/settings.php'), 'data-setting="thumbs_speed"')
      && str_contains($src('templates/admin/settings.php'), 'data-setting="thumbs_info"') && isset(settingsCatalogKeywords()['thumbs_info'])
      && str_contains($src('api/admin/save_settings.php'), "\$data['thumbs_width'] = (string)max(40, min((int)\$data['thumbs_width'] ?: 64, 160));")
      && isset(settingsCatalogKeywords()['thumbs_max']) && str_contains($src('.gitignore'), 'thumbs/'));
$plL = include $root . '/lang/pl.php';
$enL = include $root . '/lang/en.php';
$words = ['js.app.thumbs_open', 'js.app.rated_at', 'js.app.rate_open', 'js.app.rate_close', 'js.app.hist_row', 'settings.thumbs_search', 'settings.thumbs_speed_hint', 'settings.thumbs_max', 'settings.thumbs_info', 'settings.thumbs_info_hint', 'js.app.gal_label', 'js.app.gal_open', 'js.app.gal_prev', 'js.app.gal_next'];
check('the words in both languages', !array_diff($words, array_keys($plL)) && !array_diff($words, array_keys($enL)) && $plL['js.app.rate_open'] === 'Oceń');
check('version and changelog', TRACKER_VERSION === '1.87.0' && str_contains($src('CHANGELOG.md'), '## [1.87.0]'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
