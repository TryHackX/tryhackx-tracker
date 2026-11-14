<?php
/**
 * GET — what each scrape poll delivered, read as PASSES.
 *
 * ?range=1h|6h|24h|7d|2w|1m|all
 *
 * THE NUMBERS THIS ENDPOINT EXISTS TO GET RIGHT
 * ---------------------------------------------
 * `entries` is a FILE POSITION, not a delivery count: a poll that resumes at a cursor walks past
 * everything the previous poll already handled. `delivered` is what a poll contributed past the point
 * it started from.
 *
 * And a poll is not the unit coverage is about. When the scrape is longer than one poll's time budget,
 * a poll is cut at the budget and the next one continues from the cursor: the two together are the
 * whole scrape. Judging each poll alone read every continuing half as a collapse ("worst poll 0.1 %" —
 * the 1 453-entry tail of a pass that had walked everything) and called every cut "arrived truncated".
 * So coverage, its worst and its average are per PASS (includes/index.php, indexPollPasses()); a pass
 * whose newest poll was cut is in progress, never a low number. The polls stay in the reply — they are
 * real, and the chart draws them as the parts of their pass.
 */

try {
    $reply = indexPollHistory($db, $cfg, (string)($_GET['range'] ?? '24h'));
} catch (\Throwable $e) {
    jsonResponse(['success' => true, 'range' => (string)($_GET['range'] ?? '24h'), 'points' => [], 'lead' => [], 'passes' => [],
                  'unavailable' => true, 'message' => __('api.index.no_poll_history')]);
}
jsonResponse($reply);
