<?php
/**
 * POST comment_delete {csrf_token, id[, reason]} — take a comment down (1.71.0, includes/comments.php).
 *
 *   → 200 {success, right: 'own'|'any', count, tomb, message}
 *
 * Your own for comment_delete_own_minutes (comment.delete_own), or anybody's with comment.moderate — then
 * with a REASON, which the author is shown in a notification (commentDelete(); part E adds the choice to
 * say nothing). Soft: the row keeps who, when and why. `tomb` (1.72.0): 'deleted' | 'removed' when replies under
 * it keep its place as a tombstone for this reader, '' when it simply goes.
 */
requirePost();
$r = commentDeleteRequest($db, $cfg, currentUser($db), readJsonBody());
jsonResponse($r['body'], (int)$r['status']);
