<?php
/**
 * GET  admin/csp_reports — what browsers have reported, newest first.
 * POST admin/csp_reports {op:"clear"} — empty the table.
 *
 * NOT in adminEndpointPermission() (api.php), and that is the decision, not an omission: an
 * endpoint absent from that map is reachable only by the OWNER's own session. This table is drawn
 * on the Settings page, and includes/auth.php:62 makes the whole of Settings owner-only — a
 * moderator can never see the section, so an endpoint they could call to read it would be a hole
 * with no door in front of it.
 *
 * Rows are written by a PUBLIC endpoint (csp-report.php). The values here are therefore attacker-
 * chosen strings that the store has already reduced to an origin and a directive name; the panel
 * draws every one of them with textContent (templates/admin/settings.php). Nothing is re-escaped
 * here — JSON is not HTML, and escaping twice is how a legitimate host ends up unreadable.
 */

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($isPost) {
    $in = readJsonBody();
    if (($in['op'] ?? '') !== 'clear') {
        jsonResponse(['error' => __('api.csp.unknown_op')], 400);
    }
    try {
        $n = (int)$db->exec("DELETE FROM csp_reports");
    } catch (\Throwable $e) {
        jsonResponse(['error' => __('api.csp.unavailable')], 500);
    }
    // The audit log names this 'csp.clear' (includes/audit.php); the count is what makes the line
    // worth reading afterwards.
    auditNote(['summary' => __('api.csp.cleared', ['n' => $n]), 'detail' => ['deleted' => $n]]);
    jsonResponse(['ok' => true, 'deleted' => $n]);
}

// A read: let the session lock go, the way every other panel poll does, so this cannot queue behind
// the other cards on the page.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
    // 200 rows, not the whole table: csp_report_keep_rows may be 5000, and the panel draws a table
    // the operator reads, not a data export. They are already aggregated — 200 KINDS of violation
    // is far past the point where the answer is "fix the first five".
    $st = $db->query("SELECT scope, directive, blocked, sample_doc, hits, first_seen, last_seen
                        FROM csp_reports ORDER BY last_seen DESC LIMIT 200");
    $rows = $st->fetchAll();
    // COUNT(*) only when the page is full, which is the only case where it can say anything the
    // rows do not. The Settings page loads this list on every render, and a COUNT(*) that is
    // always the length of an array already in hand is the shape that cost this panel 939 ms twice
    // a minute on index_hashes.
    $total = count($rows) < 200 ? count($rows) : (int)$db->query("SELECT COUNT(*) FROM csp_reports")->fetchColumn();
} catch (\Throwable $e) {
    // The table is created by ensureSchema() on the first web request after a deploy. If it is not
    // there yet, say so plainly rather than showing an empty list that reads as "nothing is wrong".
    jsonResponse(['error' => __('api.csp.unavailable')], 500);
}

foreach ($rows as &$r) { $r['hits'] = (int)$r['hits']; }
unset($r);

jsonResponse([
    'ok'        => true,
    'rows'      => $rows,
    'total'     => $total,
    // What the page needs to explain an empty list: an empty table means "nothing was reported"
    // only while collection is actually on.
    'reporting' => cspReportingOn($cfg),
    'mode'      => cspMode($cfg),
    'keep_rows' => cspReportKeepRows($cfg),
]);
