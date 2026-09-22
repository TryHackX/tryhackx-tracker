<?php
/**
 * user_cover — a member's own profile cover. The same gates, the same three operations and the same
 * answers as a picture (see api/user_avatar.php, which this includes), with `profile.cover`, the
 * covers switch and userCoverStore()/userCoverReposition() in place of the picture's.
 */
$umWhat = 'cover';
require __DIR__ . '/user_avatar.php';
