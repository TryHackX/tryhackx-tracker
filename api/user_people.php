<?php
/**
 * Following, friendship and blocks.
 *
 *   GET  user_people[&view=friends|pending|incoming|following|blocks][&search=]
 *   POST user_people {op:'follow'|'unfollow'|'accept'|'decline'|'block'|'unblock'|'block_hide',
 *                     user:<name>, value:0|1, csrf_token}
 *
 * ── one row, read two ways ─────────────────────────────────────────────────────────────────────
 * `user_friends` holds "A asked B". Until B answers, that IS a follow — which is why `follow` and
 * "send a friend request" are the same button and the same row, and why accepting is an UPDATE and
 * not a second insert. See includes/people.php.
 *
 * ── blocking is not silent ─────────────────────────────────────────────────────────────────────
 * Blocking somebody stops their messages and, if they ask for it, hides the profile from them. It
 * does NOT quietly swallow what they write: api/user_messages.php tells a blocked sender that they
 * are blocked. That is the operator's decision, written down where both halves can see it.
 */
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 404);

$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);
$uid = (int)$me['id'];

/* ─────────────────────────────── POST ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => __('api.csrf.invalid')], 403);
    }
    $perHour = (int)($cfg['rate_limit_favourites'] ?? 240);
    if (!rateLimitAllow('people', ipBucket(getClientIp($cfg)), $perHour, 3600)) {
        jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
    }
    $op   = (string)($input['op'] ?? '');
    $name = trim((string)($input['user'] ?? ''));
    if (!userValidUsername($name)) jsonResponse(['error' => 'not_found'], 404);
    $them = userFindByLogin($db, $name);
    if (!$them || ($them['status'] ?? '') !== 'active') jsonResponse(['error' => 'not_found'], 404);
    $tid = (int)$them['id'];
    if ($tid === $uid) jsonResponse(['error' => 'self'], 400);

    $friendOps = ['follow', 'unfollow', 'accept', 'decline'];
    if (in_array($op, $friendOps, true)) {
        if (!friendsEnabled($cfg)) jsonResponse(['error' => 'friends_disabled'], 404);
        if (!userCan($db, $cfg, 'friends.use')) jsonResponse(['error' => 'no_permission'], 403);
    }

    switch ($op) {
        case 'follow':
            // Blocked either way, no request: a request is a message of a kind, and the whole point
            // of a block is that it stops those.
            if (blockRow($db, $tid, $uid) !== null || blockRow($db, $uid, $tid) !== null) {
                jsonResponse(['error' => 'blocked'], 403);
            }
            // If THEY already asked ME, following back is what accepting means. One row, and the
            // pair end up friends rather than each holding a request the other cannot see.
            $st = $db->prepare("UPDATE user_friends SET status = 'accepted', accepted_at = NOW()
                                 WHERE user_id = ? AND friend_id = ? AND status = 'pending'");
            $st->execute([$tid, $uid]);
            if ($st->rowCount() === 1) {
                userNotify($db, $tid, 'friend_accepted', __('notify.friend_accepted', ['user' => $me['username']]));
                jsonResponse(['success' => true, 'state' => 'friends']);
            }
            $db->prepare("INSERT IGNORE INTO user_friends (user_id, friend_id, status) VALUES (?, ?, 'pending')")
               ->execute([$uid, $tid]);
            userNotify($db, $tid, 'friend_request', __('notify.friend_request', ['user' => $me['username']]));
            jsonResponse(['success' => true, 'state' => friendState($db, $uid, $tid)]);
            // no break — jsonResponse() exits

        case 'unfollow':
            // Taking back a request, or ending a friendship. Both directions go, because a
            // friendship is one row and leaving half of it behind would leave the other person
            // with a friend who does not have them.
            $db->prepare("DELETE FROM user_friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)")
               ->execute([$uid, $tid, $tid, $uid]);
            jsonResponse(['success' => true, 'state' => 'none']);

        case 'accept':
            $st = $db->prepare("UPDATE user_friends SET status = 'accepted', accepted_at = NOW()
                                 WHERE user_id = ? AND friend_id = ? AND status = 'pending'");
            $st->execute([$tid, $uid]);
            if ($st->rowCount() !== 1) jsonResponse(['error' => 'not_found'], 404);
            userNotify($db, $tid, 'friend_accepted', __('notify.friend_accepted', ['user' => $me['username']]));
            jsonResponse(['success' => true, 'state' => 'friends']);

        case 'decline':
            $db->prepare("DELETE FROM user_friends WHERE user_id = ? AND friend_id = ? AND status = 'pending'")
               ->execute([$tid, $uid]);
            jsonResponse(['success' => true, 'state' => friendState($db, $uid, $tid)]);

        case 'block':
            // Two decisions in one action, because the modal asks both: stop the messages, and
            // optionally disappear from them. Blocking also ends whatever the two were to each
            // other — keeping a friendship with somebody you have just blocked is not a state
            // anybody meant to be in.
            $hide = !empty($input['hide_profile']) && $input['hide_profile'] !== '0' && $input['hide_profile'] !== 'false';
            $db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile, note) VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE hide_profile = VALUES(hide_profile), note = VALUES(note)")
               ->execute([$uid, $tid, $hide ? 1 : 0, mb_substr(trim((string)($input['note'] ?? '')), 0, 200)]);
            $db->prepare("DELETE FROM user_friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)")
               ->execute([$uid, $tid, $tid, $uid]);
            jsonResponse(['success' => true, 'blocked' => true, 'hide_profile' => $hide]);

        case 'block_hide':
            $hide = !empty($input['value']) && $input['value'] !== '0' && $input['value'] !== 'false';
            $st = $db->prepare("UPDATE user_blocks SET hide_profile = ? WHERE user_id = ? AND blocked_id = ?");
            $st->execute([$hide ? 1 : 0, $uid, $tid]);
            jsonResponse(['success' => true, 'hide_profile' => $hide]);

        case 'unblock':
            $db->prepare("DELETE FROM user_blocks WHERE user_id = ? AND blocked_id = ?")->execute([$uid, $tid]);
            jsonResponse(['success' => true, 'blocked' => false]);
    }
    jsonResponse(['error' => 'unknown_op'], 400);
}

/* ─────────────────────────────── GET ────────────────────────────────────── */

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

$view   = (string)($_GET['view'] ?? 'friends');
$search = trim((string)($_GET['search'] ?? ''));
$like   = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';

// Every view is the same shape — a list of people with what they are to me — so the page can render
// them all with one function and the difference stays in SQL where it belongs.
$sql = '';
$params = [];
switch ($view) {
    case 'pending':     // I asked, they have not answered
        $sql = "SELECT u.id, u.username, u.created_at, f.created_at AS since FROM user_friends f
                  JOIN users u ON u.id = f.friend_id
                 WHERE f.user_id = ? AND f.status = 'pending' AND u.status = 'active'";
        $params = [$uid];
        break;
    case 'incoming':    // they asked me — the rows this page exists to let somebody answer
        $sql = "SELECT u.id, u.username, u.created_at, f.created_at AS since FROM user_friends f
                  JOIN users u ON u.id = f.user_id
                 WHERE f.friend_id = ? AND f.status = 'pending' AND u.status = 'active'";
        $params = [$uid];
        break;
    case 'blocks':
        $sql = "SELECT u.id, u.username, u.created_at, b.created_at AS since, b.hide_profile, b.note
                  FROM user_blocks b JOIN users u ON u.id = b.blocked_id
                 WHERE b.user_id = ? AND u.status = 'active'";
        $params = [$uid];
        break;
    case 'friends':
    default:
        $view = 'friends';
        $sql = "SELECT u.id, u.username, u.created_at, f.accepted_at AS since FROM user_friends f
                  JOIN users u ON u.id = IF(f.user_id = ?, f.friend_id, f.user_id)
                 WHERE (f.user_id = ? OR f.friend_id = ?) AND f.status = 'accepted' AND u.status = 'active'";
        $params = [$uid, $uid, $uid];
        break;
}
if ($search !== '') { $sql .= " AND u.username LIKE ?"; $params[] = $like; }
$sql .= " ORDER BY u.username ASC LIMIT 500";
$st = $db->prepare($sql);
$st->execute($params);

$rows = array_map(static fn($r) => [
    'username'     => (string)$r['username'],
    'since'        => (string)($r['since'] ?? ''),
    'hide_profile' => isset($r['hide_profile']) ? ((int)$r['hide_profile'] === 1) : null,
    'note'         => isset($r['note']) ? (string)$r['note'] : null,
], $st->fetchAll(PDO::FETCH_ASSOC));

// The counts every tab bar needs, in one round trip rather than four.
$cnt = static function (string $sql, array $args) use ($db): int {
    $q = $db->prepare($sql); $q->execute($args); return (int)$q->fetchColumn();
};
jsonResponse([
    'success' => true,
    'view'    => $view,
    'rows'    => $rows,
    'counts'  => [
        'friends'  => $cnt("SELECT COUNT(*) FROM user_friends WHERE status = 'accepted' AND (user_id = ? OR friend_id = ?)", [$uid, $uid]),
        'pending'  => $cnt("SELECT COUNT(*) FROM user_friends WHERE status = 'pending' AND user_id = ?", [$uid]),
        'incoming' => $cnt("SELECT COUNT(*) FROM user_friends WHERE status = 'pending' AND friend_id = ?", [$uid]),
        'blocks'   => $cnt("SELECT COUNT(*) FROM user_blocks WHERE user_id = ?", [$uid]),
    ],
    'friends_enabled' => friendsEnabled($cfg),
    // So a friend can be written to from the row that says they are one, rather than by going to
    // their profile to find the button. The same question the directory asks.
    'may_message' => pmEnabled($cfg) && userCan($db, $cfg, 'pm.send'),
]);
