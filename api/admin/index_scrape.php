<?php
requirePost();
$input = readJsonBody();
$hash = strtolower(trim((string)($input['hash'] ?? '')));
if (!isValidInfoHash($hash)) jsonResponse(['error' => 'Invalid hash'], 400);
$scrape = null;
try { $scrape = indexScrapeOne($db, $cfg, $hash); } catch (\Throwable $e) { $scrape = null; }
if (!$scrape) {
    $error = trim((string)($cfg['whitelist_scrape_url'] ?? '')) === '' ? 'Scrape URL is not configured' : 'Tracker did not answer';
    jsonResponse(['success' => false, 'scrape' => null, 'error' => $error]);
}
jsonResponse(['success' => true, 'scrape' => $scrape]);
