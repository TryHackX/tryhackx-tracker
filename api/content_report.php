<?php
/**
 * POST content_report {csrf_token, kind: comment|description|shout, id | hash, reason} — a member reports a
 * comment, a torrent's description or a shout to the moderators (1.71.0, includes/reports.php).
 *
 *   → 200 {success, reported: true, already: bool, message}
 *   → 4xx {success: false, error: <code>, message: <in the reader's language>}
 *
 * The whole decision is contentReportRequest() — the gates in their order, the ONE limit call part F replaces,
 * the one open report per member per target — so tests/content_reports_test.php puts every refusal to it
 * without a web server.
 */
requirePost();
$r = contentReportRequest($db, $cfg, usersEnabled($cfg) ? currentUser($db) : null, readJsonBody(), getClientIp($cfg));
jsonResponse($r['body'], (int)$r['status']);
