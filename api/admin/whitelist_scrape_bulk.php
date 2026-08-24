<?php
// Bulk "Refresh S/L": scrape many hashes with as few tracker requests as possible (WL_SCRAPE_BATCH hashes
// per /scrape). Scopes: 'page' (ids[] currently shown), 'stale' (never scraped or older than
// WL_SCRAPE_STALE_AFTER), 'all' (every active row). Bounded by WL_SCRAPE_BULK_MAX_ROWS rows and a wall-time
// budget per call; the reply carries a cursor (after_id) and truncated=true so the UI can call again.
// Always 200 with success:true once the input is valid — an unreachable tracker is reported in `warning`.
requirePost();

$input = readJsonBody();
$scope = strtolower(trim((string)($input['scope'] ?? '')));
$afterId = max(0, (int)($input['after_id'] ?? 0));
if (!in_array($scope, ['page', 'stale', 'all', 'date'], true)) {
    jsonResponse(['error' => 'Invalid scope (page | stale | all | date)'], 400);
}
$dateRange = null;
if ($scope === 'date') {
    $dateRange = parseDateRangeInput($input);
    if ($dateRange === null) jsonResponse(['error' => 'Invalid date range: pass since_hours=N, or from (Y-m-d [H:i]) and optional to.'], 400);
}

$cap = WL_SCRAPE_BULK_MAX_ROWS;
$more = false;
$rows = [];
if ($scope === 'page') {
    $ids = $input['ids'] ?? [];
    if (!is_array($ids)) $ids = [$ids];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) {
        jsonResponse(['error' => 'No IDs provided'], 400);
    }
    if (count($ids) > 500) {
        jsonResponse(['error' => 'Too many IDs (max 500)'], 400);
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, info_hash FROM whitelist WHERE id IN ($ph) AND id > ? ORDER BY id");
    $st->execute(array_merge($ids, [$afterId]));
    $rows = $st->fetchAll();
} else {
    $where = "banned = 0 AND id > ?";
    $params = [$afterId];
    if ($scope === 'stale') $where .= " AND (scraped_at IS NULL OR scraped_at < DATE_SUB(NOW(), INTERVAL " . (int)WL_SCRAPE_STALE_AFTER . " SECOND))";
    if ($scope === 'date') { $where .= " AND created_at >= ? AND created_at <= ?"; $params[] = $dateRange[0]; $params[] = $dateRange[1]; }
    $st = $db->prepare("SELECT id, info_hash FROM whitelist WHERE $where ORDER BY id LIMIT " . ($cap + 1));
    $st->execute($params);
    $rows = $st->fetchAll();
    if (count($rows) > $cap) { $more = true; array_pop($rows); }
}

$res = ['scraped' => 0, 'requests' => 0, 'failed' => 0, 'processed' => 0, 'truncated' => false, 'last_id' => null, 'error' => null];
try {
    if ($rows) $res = scrapeOpenTrackerMany($db, $cfg, $rows);
} catch (\Throwable $e) {
    // never 500 on a tracker/DB hiccup — the UI shows the warning inline
    $res['error'] = 'Scrape failed: ' . $e->getMessage();
}

// Cursor: last row attempted (a budget cut leaves the tail of $rows for the next call); when nothing was
// attempted, the last row of this slice so a dead tracker still moves forward and the loop terminates.
$lastId = $res['last_id'] !== null ? (int)$res['last_id'] : ($rows ? (int)end($rows)['id'] : $afterId);
$truncated = ($res['error'] === null) && (!empty($res['truncated']) || $more);

$remaining = 0;
if ($scope !== 'page') {
    $where = "banned = 0 AND id > ?";
    $params = [$lastId];
    if ($scope === 'stale') $where .= " AND (scraped_at IS NULL OR scraped_at < DATE_SUB(NOW(), INTERVAL " . (int)WL_SCRAPE_STALE_AFTER . " SECOND))";
    if ($scope === 'date') { $where .= " AND created_at >= ? AND created_at <= ?"; $params[] = $dateRange[0]; $params[] = $dateRange[1]; }
    $st = $db->prepare("SELECT COUNT(*) FROM whitelist WHERE $where");
    $st->execute($params);
    $remaining = (int)$st->fetchColumn();
} else {
    $remaining = max(0, count($rows) - (int)$res['processed']);
}

jsonResponse([
    'success'   => true,
    'scope'     => $scope,
    'matched'   => count($rows),
    'processed' => (int)$res['processed'],
    'scraped'   => (int)$res['scraped'],
    'requests'  => (int)$res['requests'],
    'failed'    => (int)$res['failed'],
    'truncated' => $truncated,
    'after_id'  => $lastId,
    'remaining' => $remaining,
    'warning'   => $res['error'],
]);
