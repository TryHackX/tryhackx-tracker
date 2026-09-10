<?php
/**
 * The member directory: the people who asked to be findable.
 *
 *   GET user_directory[&search=][&page=][&per_page=]
 *
 * ── who is on it ───────────────────────────────────────────────────────────────────────────────
 * `users.profile_listed`, and nothing else — not `fav_public`, not `lists_public`. "My profile is
 * public" and "put me on a list strangers browse" are different sentences, and reading one as the
 * other would put somebody on a page they never asked to be on because they once shared a torrent
 * list. It needs the same GRANT every other visibility decision needs (`directory.view` is what the
 * READER needs; the person listed needs `favourites.view_others`-style consent through their own
 * flag), the site switch, and an active account.
 *
 * ── and what it says about them ────────────────────────────────────────────────────────────────
 * A name and how long they have been here. No e-mail, no last sign-in, no counts of what they have
 * — a directory is a way to find somebody, not a dossier.
 */
if (!directoryEnabled($cfg)) jsonResponse(['error' => 'directory_disabled'], 404);

$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);
if (!userCan($db, $cfg, 'directory.view')) jsonResponse(['error' => 'no_permission'], 403);

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

$uid     = (int)$me['id'];
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 30)));
$search  = trim((string)($_GET['search'] ?? ''));
$sort    = (string)($_GET['sort'] ?? 'name:asc');

$where  = ["u.status = 'active'", "u.profile_listed = 1"];
$params = [];
if ($search !== '') {
    // A LIKE with a leading wildcard over `users`, which is the small table in this database and is
    // bounded by the page size below. Do not copy this to the catalogue.
    $where[] = 'u.username LIKE ?';
    $params[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
}
// Somebody who has hidden their profile from me is not in my directory: the block already means
// "do not show me to them", and a row that leads to a 404 is worse than no row.
$where[] = "NOT EXISTS (SELECT 1 FROM user_blocks b WHERE b.user_id = u.id AND b.blocked_id = ? AND b.hide_profile = 1)";
$params[] = $uid;
// …and neither is somebody I have blocked. Browsing a list of people includes them by definition,
// and the point of blocking was to stop meeting them.
$where[] = "NOT EXISTS (SELECT 1 FROM user_blocks b2 WHERE b2.user_id = ? AND b2.blocked_id = u.id)";
$params[] = $uid;
$w = 'WHERE ' . implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM users u $w");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

// Sorting, from a fixed set — never from the query string. Two columns, two directions, and the
// user's own name as the tie-break so a page boundary cannot show the same person twice.
$sortKey = $sort;
$ORDER = [
    'name:asc'   => 'u.username ASC',
    'name:desc'  => 'u.username DESC',
    'since:desc' => 'u.created_at DESC, u.username ASC',
    'since:asc'  => 'u.created_at ASC, u.username ASC',
];
$order = $ORDER[$sortKey] ?? $ORDER['name:asc'];

$st = $db->prepare("SELECT u.id, u.username, u.created_at FROM users u $w
                     ORDER BY $order LIMIT ? OFFSET ?");
$i = 1;
foreach ($params as $v) $st->bindValue($i++, $v, PDO::PARAM_STR);
$st->bindValue($i++, $perPage, PDO::PARAM_INT);
$st->bindValue($i, ($page - 1) * $perPage, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// What each of them is to me, in one query rather than one per row.
$states = [];
if ($rows && friendsEnabled($cfg)) {
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $db->prepare("SELECT user_id, friend_id, status FROM user_friends
                        WHERE (user_id = ? AND friend_id IN ($in)) OR (friend_id = ? AND user_id IN ($in))");
    $q->execute(array_merge([$uid], $ids, [$uid], $ids));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $other = (int)$f['user_id'] === $uid ? (int)$f['friend_id'] : (int)$f['user_id'];
        $states[$other] = $f['status'] === 'accepted' ? 'friends'
                        : ((int)$f['user_id'] === $uid ? 'following' : 'follower');
    }
}

jsonResponse([
    'success'  => true,
    'rows'     => array_map(static fn($r) => [
        'username' => (string)$r['username'],
        'since'    => substr((string)$r['created_at'], 0, 10),
        'state'    => $states[(int)$r['id']] ?? 'none',
        // The reader is in their own directory — they asked to be listed, and hiding them from it
        // would make the list disagree with the switch. What they must NOT be offered is a message
        // to themselves or a friend request to themselves, so the row says which one is them.
        'self'     => (int)$r['id'] === $uid,
    ], $rows),
    'total'    => $total,
    'page'     => $page,
    'pages'    => $pages,
    'per_page' => $perPage,
    'may_friend' => friendsEnabled($cfg) && userCan($db, $cfg, 'friends.use'),
    'may_message' => pmEnabled($cfg) && userCan($db, $cfg, 'pm.send'),
]);
