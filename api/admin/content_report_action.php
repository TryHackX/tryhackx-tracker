<?php
/**
 * What a moderator may do with one reported target — a comment, a torrent's description, a shout (1.71.0,
 * includes/reports.php).
 *
 *   POST admin/content_report_action {kind, target_id, hash, action, mode, reason, note, reply, days, seen}
 *     action: close | reopen | remove | warn | mute | unmute | ban | unban
 *     mode:   silent (the author is told nothing) | loud (a warning, with `reason`, in the author's language)
 *
 * The whole decision is contentReportActionRequest() — the kind's own `panel.reports.<kind>.handle` (the router
 * asks only panel.access), the message card's rules for an account (never staff, never yourself, never a ban
 * without a date), the removal through the kind's own function, who is told, the audit line — so
 * tests/content_reports_test.php puts every refusal to it without a web server. What it DID it writes itself
 * (creport.*, user.warn, and the removal's own line), so then the router's generic line is not written as well;
 * a refusal leaves the router's line (panel.content_report_action, not ok, the code as its summary).
 */
requirePost();
$r = contentReportActionRequest($db, $cfg, readJsonBody());
if (!empty($r['body']['success'])) auditSuppress();
jsonResponse($r['body'], (int)$r['status']);
