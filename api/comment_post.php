<?php
/**
 * POST comment_post {csrf_token, hash, body[, captcha_token]} — a comment under a torrent (1.71.0,
 * includes/comments.php).
 *
 *   → 200 {success, comment, pending, count, message}
 *   → 428 {error: captcha_required, captcha_required: true} — the page solves one and sends the same again
 *   → 4xx {success: false, error: <code>, message: <in the reader's language>}
 *
 * The whole decision is commentPostRequest() — the order of the gates, the text rules, the write and the
 * people told — so tests/comments_test.php puts every refusal to it without a web server.
 */
requirePost();
$r = commentPostRequest($db, $cfg, currentUser($db), readJsonBody(), getClientIp($cfg));
jsonResponse($r['body'], (int)$r['status']);
