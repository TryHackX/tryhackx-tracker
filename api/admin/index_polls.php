<?php
/**
 * GET — what each scrape poll delivered, as a series.
 *
 * ?range=1h|6h|24h|7d|2w|1m|all
 *
 * THE NUMBER THIS ENDPOINT EXISTS TO GET RIGHT
 * --------------------------------------------
 * `entries` is a FILE POSITION, not a delivery count. The parser counts every entry it walks past,
 * and a pass that resumes at a cursor walks past everything the previous pass already handled — so
 * a resumed poll reports the whole file again. `delivered` is `entries - skip_from`, which is what
 * that pass actually contributed, and it is what the coverage ratio is built from. Reporting
 * `entries` as coverage is how a resumed poll would read as 100 % and a fresh one as a collapse.
 */

$ranges = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '2w' => 1209600, '1m' => 2592000];
$key = (string)($_GET['range'] ?? '24h');
$now = time();
if ($key === 'all') {
    $keep = max(1, min(3650, (int)($cfg['index_poll_keep_days'] ?? 90)));
    $from = $now - $keep * 86400;
} else {
    if (!isset($ranges[$key])) $key = '24h';
    $from = $now - $ranges[$key];
}

// Newest first with a cap, then flipped: a window wider than the cap must lose its OLDEST points,
// not its most recent ones. A plain LIMIT on an ascending scan does the opposite.
$rows = [];
try {
    $st = $db->prepare("SELECT ts, entries, skip_from, kept, bytes, ms, truncated, partial,
                               removed_wl, removed_ban, rows_total, index_rows, error
                          FROM index_polls WHERE ts >= ? ORDER BY ts DESC LIMIT 4000");
    $st->execute([$from]);
    $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
} catch (\Throwable $e) {
    jsonResponse(['success' => true, 'range' => $key, 'points' => [], 'unavailable' => true,
                  'message' => __('api.index.no_poll_history')]);
}

$points = [];
foreach ($rows as $r) {
    $entries = (int)$r['entries'];
    $skip = (int)$r['skip_from'];
    // A cursor ABOVE the entries walked means the pass restarted from the beginning — a truncated
    // download reads the short file from zero while the stored cursor stays where a longer earlier
    // pass left it. You cannot walk past fewer entries than you skipped, so this is not ambiguous,
    // and reading it as "delivered nothing" is how a poll that stored 189 434 rows was charted at
    // 0 % coverage. Rows written before 1.29.1 recorded the stored cursor rather than the applied
    // one; this is what makes them readable too.
    $delivered = ($skip >= $entries) ? $entries : ($entries - $skip);
    $total = $r['rows_total'] !== null ? (int)$r['rows_total'] : null;
    $points[] = [
        'ts'        => (int)$r['ts'],
        'delivered' => $delivered,
        'entries'   => $entries,
        'skip_from' => $skip,
        'kept'      => (int)$r['kept'],
        'bytes'     => (int)$r['bytes'],
        'ms'        => (int)$r['ms'],
        'truncated' => (int)$r['truncated'] === 1,
        'partial'   => $r['partial'],
        // NULL rather than 0 when the tracker's own count was not available — the chart leaves a gap
        // instead of drawing a coverage of nothing.
        'coverage'  => ($total !== null && $total > 0) ? min(100.0, round(100 * $delivered / $total, 2)) : null,
        'rows_total' => $total,
        'index_rows' => $r['index_rows'] !== null ? (int)$r['index_rows'] : null,
        'error'     => $r['error'],
    ];
}

// A one-line reading of the window, so the card says something even before anyone looks at the chart.
$done = array_values(array_filter($points, static fn($p) => $p['error'] === null));
$cov = array_values(array_filter(array_map(static fn($p) => $p['coverage'], $done), static fn($v) => $v !== null));
$summary = [
    'polls'        => count($points),
    'failed'       => count($points) - count($done),
    'truncated'    => count(array_filter($points, static fn($p) => $p['truncated'])),
    'avg_coverage' => $cov ? round(array_sum($cov) / count($cov), 1) : null,
    'min_coverage' => $cov ? min($cov) : null,
    'last'         => $points ? end($points) : null,
];

jsonResponse(['success' => true, 'range' => $key, 'from' => $from, 'points' => $points, 'summary' => $summary]);
