<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
if ($id < 1) {
    jsonResponse(['error' => 'Invalid ID'], 400);
}

$stmt = $db->prepare("SELECT id, info_hash, scrape_seeders, scrape_leechers, scrape_completed, scraped_at FROM whitelist WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    jsonResponse(['error' => 'Not found'], 404);
}

$scrape = null;
try {
    $scrape = scrapeOpenTracker($db, $cfg, $row, true);
} catch (\Throwable $e) {
    $scrape = null;
}

if (!$scrape) {
    // 200 on purpose: an unreachable tracker is a normal state the UI shows inline, not a request error
    $error = trim((string)($cfg['whitelist_scrape_url'] ?? '')) === '' ? 'Scrape URL is not configured' : 'Tracker did not answer';
    jsonResponse(['success' => false, 'scrape' => null, 'error' => $error]);
}

jsonResponse(['success' => true, 'scrape' => $scrape]);
