<?php
/**
 * Rebuild the "fetched hashes" series for samples taken before the column existed.
 *
 *   php tools/backfill_fetched.php --dry-run
 *   php tools/backfill_fetched.php --apply
 *
 * WHY THIS IS HONEST AND NOT FABRICATION
 * --------------------------------------
 * `index_hashes.meta_fetched_at` records WHEN each hash's metadata was resolved. So the number that
 * would have been sampled at time T is simply how many rows have a fetch time at or before T — that
 * is a fact the database still holds, not an interpolation between two points.
 *
 * WHAT IT CANNOT KNOW, AND SAYS SO
 * --------------------------------
 * It counts hashes that are STILL resolved today. A hash fetched last week and since deleted, or
 * re-queued and now pending, is not in the count — so the rebuilt curve is a LOWER BOUND on what the
 * real one was. For an index that mostly grows the difference is small, but "small" is not "none",
 * and a rebuilt point is not the same kind of thing as a measured one. Rows filled here are therefore
 * only ever written where the value is NULL: a real measurement is never overwritten by a
 * reconstruction, and the marker in `settings` records that a rebuild happened at all.
 *
 * HOW IT AVOIDS 10,000 COUNT QUERIES
 * ----------------------------------
 * One ordered pass over the fetch times and one over the sample times, advancing a pointer. Both
 * lists are already sorted by the database (there is an index on meta_fetched_at), so the whole job
 * is O(n + m) and finishes in seconds instead of hammering a 3-million-row table once per sample.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/audit.php';

$apply = in_array('--apply', $argv, true);
$dry   = !$apply;

$db = getDb();
$cfg = getSettings($db);

echo $dry ? "DRY RUN — nothing will be written.\n\n" : "Applying.\n\n";

// ── the fetch times, in order ────────────────────────────────────────────────
$times = $db->query(
    "SELECT UNIX_TIMESTAMP(meta_fetched_at) t
       FROM index_hashes
      WHERE meta_status = 'done' AND meta_fetched_at IS NOT NULL
      ORDER BY meta_fetched_at"
)->fetchAll(PDO::FETCH_COLUMN);

$total = count($times);
if ($total === 0) {
    echo "No hash carries a fetch time, so there is nothing to rebuild from.\n";
    exit(0);
}
printf("%s hashes carry a fetch time, from %s to %s.\n",
    number_format($total), date('Y-m-d H:i', (int)$times[0]), date('Y-m-d H:i', (int)$times[$total - 1]));

$earliest = (int)$times[0];
$filled = 0;
$skippedEarly = 0;

foreach (['stats_samples', 'stats_samples_5m', 'stats_samples_1h'] as $table) {
    $rows = $db->query("SELECT ts FROM `$table` WHERE index_fetched IS NULL ORDER BY ts")
               ->fetchAll(PDO::FETCH_COLUMN);
    if (!$rows) { printf("  %-18s nothing to fill\n", $table); continue; }

    $upd = $db->prepare("UPDATE `$table` SET index_fetched = ? WHERE ts = ? AND index_fetched IS NULL");
    $ptr = 0;
    $tableFilled = 0;
    $tableEarly = 0;

    if ($apply) $db->beginTransaction();
    foreach ($rows as $ts) {
        $ts = (int)$ts;
        // A sample from BEFORE the first fetch time cannot be reconstructed as 0: the column did not
        // exist then either, and "nobody had fetched anything yet" is a claim this data cannot
        // support. Those stay NULL, and the chart keeps its honest gap.
        if ($ts < $earliest) { $tableEarly++; continue; }
        while ($ptr < $total && (int)$times[$ptr] <= $ts) $ptr++;
        if ($apply) $upd->execute([$ptr, $ts]);
        $tableFilled++;
    }
    if ($apply) $db->commit();

    printf("  %-18s %s filled, %s left NULL (older than the first fetch)\n",
        $table, number_format($tableFilled), number_format($tableEarly));
    $filled += $tableFilled;
    $skippedEarly += $tableEarly;
}

printf("\n%s points rebuilt, %s deliberately left empty.\n", number_format($filled), number_format($skippedEarly));

if ($apply) {
    $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('index_fetched_backfilled_at', ?)
                  ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([(string)time()]);
    auditLog($db, 'settings.save', [
        'actor' => ['type' => 'system', 'id' => null, 'name' => 'backfill'],
        'summary' => 'rebuilt ' . number_format($filled) . ' fetched-hash points from meta_fetched_at',
        'detail' => ['filled' => $filled, 'left_null' => $skippedEarly, 'source' => 'index_hashes.meta_fetched_at',
                     'caveat' => 'counts hashes still resolved today, so it is a lower bound'],
    ]);
    echo "Recorded in the audit log. The chart shows these as an ordinary line — they are a lower bound.\n";
} else {
    echo "Run again with --apply to write them.\n";
}
