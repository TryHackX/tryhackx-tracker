<?php
/**
 * POST comment_approve {csrf_token, id} — let a guest's held comment through (1.71.0, includes/comments.php).
 *
 *   → 200 {success, comment, count, message}
 *
 * comment.moderate. Turning one down is taking it down (comment_delete, with a reason). The people the
 * comment is news to are told now, when it becomes visible — not when the guest wrote it.
 */
requirePost();
$r = commentApproveRequest($db, $cfg, currentUser($db), readJsonBody());
jsonResponse($r['body'], (int)$r['status']);
