<?php
// Bulk "Refresh S/L" for the index. Scopes: 'page' (hashes[]), 'stale', 'all'. Cursor by info_hash.
requirePost();
$input = readJsonBody();
$scope = strtolower(trim((string)($input['scope'] ?? '')));
$after = strtolower(trim((string)($input['after'] ?? '')));
if (!preg_match('/^[a-f0-9]{0,40}$/', $after)) $after = '';
if (!in_array($scope, ['page', 'stale', 'all', 'date'], true)) jsonResponse(['error' => __('api.index.invalid_scrape_scope')], 400);
$dateRange = null;
if ($scope === 'date') {
    $dateRange = parseDateRangeInput($input);
    if ($dateRange === null) jsonResponse(['error' => __('api.index.invalid_date_range')], 400);
}

$cap = WL_SCRAPE_BULK_MAX_ROWS;
$more = false;
$rows = [];
if ($scope === 'page') {
    $hashes = $input['hashes'] ?? [];
    if (!is_array($hashes)) $hashes = [$hashes];
    $clean = [];
    foreach ($hashes as $h) { $h = strtolower(trim((string)$h)); if (isValidInfoHash($h)) $clean[$h] = true; }
    $clean = array_keys($clean);
    if (!$clean) jsonResponse(['error' => __('api.index.no_hashes')], 400);
    if (count($clean) > 500) jsonResponse(['error' => __('api.index.too_many_hashes')], 400);
    $ph = implode(',', array_fill(0, count($clean), '?'));
    $st = $db->prepare("SELECT info_hash FROM index_hashes WHERE info_hash IN ($ph) AND info_hash > ? ORDER BY info_hash");
    $st->execute(array_merge($clean, [$after]));
    $rows = $st->fetchAll();
} else {
    $w = "info_hash > ?";
    $params = [$after];
    if ($scope === 'stale') $w .= " AND (scraped_at IS NULL OR scraped_at < DATE_SUB(NOW(), INTERVAL " . (int)WL_SCRAPE_STALE_AFTER . " SECOND))";
    if ($scope === 'date') { $w .= " AND first_seen >= ? AND first_seen <= ?"; $params[] = $dateRange[0]; $params[] = $dateRange[1]; }
    $st = $db->prepare("SELECT info_hash FROM index_hashes WHERE $w ORDER BY info_hash LIMIT " . ($cap + 1));
    $st->execute($params);
    $rows = $st->fetchAll();
    if (count($rows) > $cap) { $more = true; array_pop($rows); }
}

$res = ['scraped' => 0, 'requests' => 0, 'failed' => 0, 'processed' => 0, 'truncated' => false, 'last_id' => null, 'error' => null];
try { if ($rows) $res = indexScrapeMany($db, $cfg, $rows); }
catch (\Throwable $e) { $res['error'] = __('api.index.scrape_failed', ['error' => $e->getMessage()]); }

$lastHash = $res['last_id'] !== null ? (string)$res['last_id'] : ($rows ? (string)end($rows)['info_hash'] : $after);
$truncated = ($res['error'] === null) && (!empty($res['truncated']) || $more);

// COUNTED ONCE, on the first call of a run. The browser drives this endpoint in a loop of up to
// 500 calls, and each used to re-count the entire remainder of the table for an "(N left)" label.
// The first answer carries the count; the client subtracts what each later call processed. A
// later call answers null, which the client reads as "keep your own arithmetic".
$remaining = null;
if ($scope !== 'page' && $after === '') {
    $remaining = 0;
    $w = "info_hash > ?";
    $params = [$lastHash];
    if ($scope === 'stale') $w .= " AND (scraped_at IS NULL OR scraped_at < DATE_SUB(NOW(), INTERVAL " . (int)WL_SCRAPE_STALE_AFTER . " SECOND))";
    if ($scope === 'date') { $w .= " AND first_seen >= ? AND first_seen <= ?"; $params[] = $dateRange[0]; $params[] = $dateRange[1]; }
    $st = $db->prepare("SELECT COUNT(*) FROM index_hashes WHERE $w");
    $st->execute($params);
    $remaining = (int)$st->fetchColumn();
} elseif ($scope === 'page') {
    $remaining = max(0, count($rows) - (int)$res['processed']);
}

jsonResponse(['success' => true, 'scope' => $scope, 'matched' => count($rows), 'processed' => (int)$res['processed'],
    'scraped' => (int)$res['scraped'], 'requests' => (int)$res['requests'], 'failed' => (int)$res['failed'],
    'truncated' => $truncated, 'after' => $lastHash, 'remaining' => $remaining, 'warning' => $res['error']]);
