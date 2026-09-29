<?php
/**
 * Correcting a comment (1.71.0, includes/comments.php).
 *
 *   GET  comment_edit&id=N                             → {success, id, body, left, own} — the words as stored
 *   POST comment_edit {csrf_token, id, body[, reason]} → {success, comment, changed, message}
 *
 * The author inside comment_edit_minutes (comment.edit_own), or comment.moderate — decided in
 * commentEditRight(), which the GET asks too, so a window that closed since the page was drawn is said
 * before anybody types a word.
 */
$me = currentUser($db);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Cache-Control: private, no-store');
    $r = commentEditSourceRequest($db, $cfg, $me, (int)($_GET['id'] ?? 0));
    jsonResponse($r['body'], (int)$r['status']);
}
$r = commentEditRequest($db, $cfg, $me, readJsonBody(), getClientIp($cfg));
jsonResponse($r['body'], (int)$r['status']);
