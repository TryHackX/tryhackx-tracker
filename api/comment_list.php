<?php
/**
 * GET comment_list&hash=H[&before=ID][&find=ID] — a page of a torrent's comments and what this reader may do in
 * the thread (1.71.0, includes/comments.php); since 1.72.0 each top-level comment carries its first replies
 * (`thread`), and GET comment_list&hash=H&thread=R&after=ID brings a thread's next ones ("Show N more replies").
 *
 *   → 200 {success, hash, rows[] (oldest first), earlier, count, pending, per_page, reply_depth, me}
 *   → 200 {success, hash, thread, rows[], last, more, count, reply_depth}          (thread=R)
 *   → 4xx {success: false, error: <code>, message: <in the reader's language>}
 *
 * The whole answer is commentListRequest(): the torrent exactly as visible as its Info panel, comment.view,
 * the page, the rows as they are drawn. A read path: the account is read, then the session lock goes, so a
 * reader's other requests are not queued behind this one.
 */
$me = currentUser($db);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');
$r = commentListRequest($db, $cfg, $me, $_GET);
jsonResponse($r['body'], (int)$r['status']);
