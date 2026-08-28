<?php
/**
 * GET index_info&hash=… — everything the public Info panel shows about one hash.
 *
 * Read-only, and gated by the same permission as the search itself: a hash somebody cannot find is a
 * hash they cannot ask about either.
 *
 * The description arrives already rendered. The renderer lives on the server for a reason — it is
 * the only place that can guarantee what comes out — and shipping the raw text to the browser to be
 * turned into HTML there would move that guarantee to the least trustworthy place in the system.
 *
 * POST with {op:"refresh"} scrapes this one hash live, when the operator has allowed it. That is a
 * button which turns a stranger's click into a request to the tracker, so it is off by default and
 * rate-limited per hash across everybody — otherwise it is a load generator with a nice icon.
 */
if (!indexEnabled($cfg) || ($cfg['index_search_enabled'] ?? '1') !== '1') {
    jsonResponse(['error' => 'Search is not available.'], 404);
}
if (!userCan($db, $cfg, 'index.search')) {
    jsonResponse(['error' => 'Search access is required.'], 403);
}

$hash = strtolower(trim((string)($_GET['hash'] ?? '')));
if (!preg_match('/^[0-9a-f]{40}$/', $hash)) jsonResponse(['error' => 'Invalid hash'], 400);

/** Everything about this hash from both tables, whichever has it. */
$loadRow = function () use ($db, $hash): array {
    $out = ['index' => null, 'whitelist' => null];
    $st = $db->prepare(
        "SELECT info_hash, name, first_seen, last_seen, seen_count, last_seeders, last_leechers,
                last_completed, peak_seeders, meta_status, total_size, files_count, promoted_at
           FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    $out['index'] = $st->fetch() ?: null;

    $st = $db->prepare(
        "SELECT info_hash, name, created_at, total_size, files_count, scrape_seeders, scrape_leechers,
                scrape_completed, scraped_at, source_url, description, description_format,
                content_status, banned
           FROM whitelist WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    $out['whitelist'] = $st->fetch() ?: null;
    return $out;
};

// ── the live refresh ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if ((string)($input['op'] ?? '') !== 'refresh') jsonResponse(['error' => 'Unknown operation'], 400);
    if (($cfg['search_allow_sl_refresh'] ?? '0') !== '1') {
        jsonResponse(['error' => 'Refreshing is not enabled on this tracker.'], 403);
    }
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
    // Per hash, across every visitor. A per-session limit would be no limit at all.
    $cool = max(10, min(3600, (int)($cfg['search_sl_refresh_seconds'] ?? 120) ?: 120));
    $stamp = sys_get_temp_dir() . '/idx_sl_' . $hash . '.stamp';
    $age = is_file($stamp) ? (time() - (int)@filemtime($stamp)) : PHP_INT_MAX;
    if ($age < $cool) {
        jsonResponse(['error' => 'Just refreshed — try again in ' . ($cool - $age) . ' s.',
                      'retry_after' => $cool - $age], 429);
    }
    @touch($stamp);
    // force = true: the caller has already paid the cooldown above, and a cached answer is exactly
    // what they pressed the button to avoid.
    $sl = scrapeOpenTracker($db, $cfg, ['info_hash' => $hash], true);
    if (!is_array($sl)) jsonResponse(['error' => 'The tracker did not answer.'], 502);
    $db->prepare("UPDATE index_hashes SET last_seeders = ?, last_leechers = ?, last_completed = ?,
                         peak_seeders = GREATEST(peak_seeders, ?) WHERE info_hash = ?")
       ->execute([(int)$sl['seeders'], (int)$sl['leechers'], (int)$sl['completed'], (int)$sl['seeders'], $hash]);
    $db->prepare("UPDATE whitelist SET scrape_seeders = ?, scrape_leechers = ?, scrape_completed = ?,
                         scraped_at = NOW() WHERE info_hash = ?")
       ->execute([(int)$sl['seeders'], (int)$sl['leechers'], (int)$sl['completed'], $hash]);
    jsonResponse(['success' => true, 'seeders' => (int)$sl['seeders'],
                  'leechers' => (int)$sl['leechers'], 'completed' => (int)$sl['completed'],
                  'message' => 'Refreshed from the tracker.']);
}

$row = $loadRow();
$idx = $row['index'];
$wl  = $row['whitelist'];
if (!$idx && !$wl) jsonResponse(['error' => 'Not found.'], 404);

$canWl = userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1';

// The link and the description belong to the whitelist row, and only once approved. A pending one is
// text nobody has looked at yet; a rejected one is text somebody decided against. Neither is public.
$sourceUrl = null;
$descHtml  = '';
if ($wl && !(int)$wl['banned'] && ($wl['content_status'] ?? 'none') === 'approved') {
    $sourceUrl = $wl['source_url'] ?: null;
    $descHtml  = richtextRender($wl['description'] ?? '', (string)$wl['description_format'], $cfg);
}

jsonResponse([
    'success'   => true,
    'info_hash' => $hash,
    'name'      => $idx['name'] ?? ($wl['name'] ?? null),
    'whitelisted' => $canWl ? (bool)$wl : null,
    'source_url'  => $sourceUrl,
    'source_trusted' => $sourceUrl ? richtextIsTrusted($sourceUrl, $cfg) : false,
    'description_html' => $descHtml,
    'stats' => [
        'first_seen'  => $idx['first_seen'] ?? ($wl['created_at'] ?? null),
        'last_seen'   => $idx['last_seen'] ?? null,
        'seen_count'  => $idx ? (int)$idx['seen_count'] : null,
        'seeders'     => $idx ? (int)$idx['last_seeders'] : ($wl ? (int)$wl['scrape_seeders'] : null),
        'leechers'    => $idx ? (int)$idx['last_leechers'] : ($wl ? (int)$wl['scrape_leechers'] : null),
        'completed'   => $idx ? (int)$idx['last_completed'] : ($wl ? (int)$wl['scrape_completed'] : null),
        'peak_seeders' => $idx ? (int)$idx['peak_seeders'] : null,
        'total_size'  => $idx['total_size'] ?? ($wl['total_size'] ?? null),
        'files_count' => $idx['files_count'] ?? ($wl['files_count'] ?? null),
        'scraped_at'  => $wl['scraped_at'] ?? null,
    ],
    'can_refresh' => ($cfg['search_allow_sl_refresh'] ?? '0') === '1',
    'can_files'   => userCan($db, $cfg, 'index.files'),
]);
