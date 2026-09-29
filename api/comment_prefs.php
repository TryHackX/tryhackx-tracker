<?php
/**
 * Which comments this account is told about (1.71.0, includes/comments.php, users.comment_notify).
 *
 *   GET                                                     → {success, prefs: {mine, desc, thread, mention}}
 *   POST {csrf_token, mine?, desc?, thread?, mention?: 0|1} → the same, after the change
 *
 * A key left out is left as it is. No password: a preference, like the mail ones beside it on the page.
 */
$me = currentUser($db);
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if (!$post && session_status() === PHP_SESSION_ACTIVE) session_write_close();
$r = commentPrefsRequest($db, $cfg, $me, $post ? readJsonBody() : [], $post);
jsonResponse($r['body'], (int)$r['status']);
