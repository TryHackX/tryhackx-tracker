<?php
if (!indexEnabled($cfg) || ($cfg['index_search_enabled'] ?? '1') !== '1') {
    jsonResponse(['success' => true, 'items' => new stdClass()]);
}
if (!userCan($db, $cfg, 'index.view') || !userCan($db, $cfg, 'index.magnet')) {
    jsonResponse(['error' => __('api.index.search_access_required')], 403);
}
$canWl = userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$hashes = [];
foreach (explode(',', strtolower((string)($_GET['h'] ?? ''))) as $h) {
    $h = trim($h);
    if (preg_match('/^[0-9a-f]{40}$/', $h)) $hashes[$h] = true;
    if (count($hashes) >= 50) break;
}
$items = [];
if ($hashes) {
    $keys = array_keys($hashes);
    $in = implode(',', array_fill(0, count($keys), '?'));
    $st = $db->prepare("SELECT info_hash, last_seeders, last_leechers, last_completed FROM index_hashes WHERE info_hash IN ($in)");
    $st->execute($keys);
    foreach ($st->fetchAll() as $r) {
        $items[$r['info_hash']] = ['s' => (int)$r['last_seeders'], 'l' => (int)$r['last_leechers'], 'c' => (int)$r['last_completed']];
    }
    if ($canWl) {
        $st = $db->prepare("SELECT info_hash, scrape_seeders, scrape_leechers, scrape_completed FROM whitelist WHERE banned = 0 AND info_hash IN ($in)");
        $st->execute($keys);
        foreach ($st->fetchAll() as $r) {
            $items[$r['info_hash']] ??= ['s' => (int)$r['scrape_seeders'], 'l' => (int)$r['scrape_leechers'], 'c' => (int)$r['scrape_completed']];
        }
    }
}
jsonResponse(['success' => true, 'items' => (object)$items]);
