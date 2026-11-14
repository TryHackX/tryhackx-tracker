<?php
requirePost();
$input = readJsonBody();
$hash = strtolower(trim((string)($input['hash'] ?? '')));
if (!isValidInfoHash($hash)) jsonResponse(['error' => __('api.index.invalid_hash')], 400);
$scrape = null;
try { $scrape = indexScrapeOne($db, $cfg, $hash); } catch (\Throwable $e) { $scrape = null; }
if (!$scrape) {
    $error = trim((string)($cfg['whitelist_scrape_url'] ?? '')) === '' ? __('api.index.scrape_url_missing') : __('api.index.tracker_no_answer');
    jsonResponse(['success' => false, 'scrape' => null, 'error' => $error]);
}
jsonResponse(['success' => true, 'scrape' => $scrape]);
