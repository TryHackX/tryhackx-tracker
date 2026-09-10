<?php
/**
 * The lists themselves: the owner's own, or a profile's as a stranger may see them.
 *
 *   GET  user_lists[&user=<name>][&search=][&hash=<40 hex>]
 *   POST user_lists {op:'create'|'rename'|'describe'|'visibility'|'delete', id, name, value, csrf_token}
 *
 * With `hash=`, a signed-in reader's OWN lists come back each carrying `has` — which of them the
 * torrent is already in. That is what the "add to a list" picker needs, and it is one query rather
 * than one per list.
 *
 * ── one 404 for every "no" ─────────────────────────────────────────────────────────────────────
 * A wrong name, no such account, a suspended one, a hidden section, the feature switched off, the
 * permission missing — all the same answer, for the reason api/user_favourites.php gives at length.
 */
if (!listsEnabled($cfg)) jsonResponse(['error' => 'lists_disabled'], 404);

$me = currentUser($db);

/* ──────────────────────────── POST: the shelf ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => __('api.csrf.invalid')], 403);
    }
    if (!$me) jsonResponse(['error' => 'login_required'], 401);
    if (!userCan($db, $cfg, 'lists.use')) jsonResponse(['error' => 'no_permission'], 403);
    $perHour = (int)($cfg['rate_limit_favourites'] ?? 240);
    if (!rateLimitAllow('listedit', ipBucket(getClientIp($cfg)), $perHour, 3600)) {
        jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
    }

    $uid = (int)$me['id'];
    $op  = (string)($input['op'] ?? '');
    $id  = (int)($input['id'] ?? 0);

    // Every op but `create` acts on a row this person owns. The ownership is part of the WHERE and
    // never a separate check: a lookup that fetches first and compares afterwards is one refactor
    // away from forgetting to compare.
    $own = null;
    if ($op !== 'create') {
        if ($id < 1) jsonResponse(['error' => 'not_found'], 404);
        $st = $db->prepare("SELECT * FROM user_lists WHERE id = ? AND user_id = ? LIMIT 1");
        $st->execute([$id, $uid]);
        $own = $st->fetch(PDO::FETCH_ASSOC);
        if (!$own) jsonResponse(['error' => 'not_found'], 404);
    }

    if ($op === 'create') {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') jsonResponse(['error' => 'name_required'], 400);
        $name = mb_substr($name, 0, 80);
        $max = listsMaxPerUser($cfg);
        $cnt = $db->prepare("SELECT COUNT(*) FROM user_lists WHERE user_id = ?");
        $cnt->execute([$uid]);
        if ((int)$cnt->fetchColumn() >= $max) jsonResponse(['error' => 'too_many_lists', 'limit' => $max], 409);
        $slug = listUniqueSlug($db, $uid, $name);
        $db->prepare("INSERT INTO user_lists (user_id, name, slug, description) VALUES (?, ?, ?, '')")
           ->execute([$uid, $name, $slug]);
        $newId = (int)$db->lastInsertId();
        jsonResponse(['success' => true, 'id' => $newId, 'name' => $name, 'slug' => $slug, 'is_public' => false, 'items' => 0]);
    }

    if ($op === 'rename') {
        $name = mb_substr(trim((string)($input['name'] ?? '')), 0, 80);
        if ($name === '') jsonResponse(['error' => 'name_required'], 400);
        // The SLUG IS NOT REBUILT. It is the address somebody may already have been given, and a
        // rename is not a reason to break a link that is out in the world.
        $db->prepare("UPDATE user_lists SET name = ? WHERE id = ? AND user_id = ?")->execute([$name, $id, $uid]);
        jsonResponse(['success' => true, 'name' => $name, 'slug' => (string)$own['slug']]);
    }

    if ($op === 'describe') {
        $desc = mb_substr(trim((string)($input['value'] ?? '')), 0, 500);
        $db->prepare("UPDATE user_lists SET description = ? WHERE id = ? AND user_id = ?")->execute([$desc, $id, $uid]);
        jsonResponse(['success' => true, 'description' => $desc]);
    }

    if ($op === 'visibility') {
        $want = !empty($input['value']) && $input['value'] !== '0' && $input['value'] !== 'false';
        // Publishing needs the site switch AND the group. Turning it OFF never does: somebody must
        // always be able to take their own list back, whatever the operator has since changed.
        if ($want && !(listsPublicEnabled($cfg) && userCan($db, $cfg, 'lists.public'))) {
            jsonResponse(['error' => 'no_permission'], 403);
        }
        $db->prepare("UPDATE user_lists SET is_public = ? WHERE id = ? AND user_id = ?")->execute([$want ? 1 : 0, $id, $uid]);
        jsonResponse(['success' => true, 'is_public' => $want]);
    }

    if ($op === 'delete') {
        // The items first: they are keyed by list, so removing the list first would leave rows
        // pointing at an id nothing will ever look at again.
        $db->prepare("DELETE FROM user_list_items WHERE list_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM user_lists WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
        jsonResponse(['success' => true, 'deleted' => $id]);
    }

    jsonResponse(['error' => 'unknown_op'], 400);
}

/* ───────────────────────────── GET: the shelf ───────────────────────────── */

$who   = trim((string)($_GET['user'] ?? ''));
$owner = null;
$isOwn = false;

if ($who === '') {
    if (!$me) jsonResponse(['error' => 'login_required'], 401);
    if (!userCan($db, $cfg, 'lists.use')) jsonResponse(['error' => 'no_permission'], 403);
    $owner = $me;
    $isOwn = true;
} else {
    // Somebody else's: every gate, and one 404 for all of them.
    if (!$me || !userCan($db, $cfg, 'favourites.view_others')) jsonResponse(['error' => 'not_found'], 404);
    if (!userValidUsername($who)) jsonResponse(['error' => 'not_found'], 404);
    $cand = userFindByLogin($db, $who);
    if (!$cand || ($cand['status'] ?? '') !== 'active') jsonResponse(['error' => 'not_found'], 404);
    $isOwn = (int)$cand['id'] === (int)$me['id'];
    if (!$isOwn && !listsVisibleFor($db, $cfg, $cand)) jsonResponse(['error' => 'not_found'], 404);
    $owner = $cand;
}

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

$search = trim((string)($_GET['search'] ?? ''));
$where  = ['l.user_id = ?'];
$params = [(int)$owner['id']];
if (!$isOwn) $where[] = 'l.is_public = 1';
if ($search !== '') {
    $where[] = '(l.name LIKE ? OR l.description LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
    $params[] = $like; $params[] = $like;
}
$w = 'WHERE ' . implode(' AND ', $where);

// The count comes from a correlated subquery rather than a GROUP BY: the driving set is at most
// lists_max_per_user rows, and a join would sort every item of every list to produce a number.
$st = $db->prepare("SELECT l.id, l.name, l.slug, l.description, l.is_public, l.created_at, l.updated_at,
                           (SELECT COUNT(*) FROM user_list_items i WHERE i.list_id = l.id) AS items
                      FROM user_lists l $w ORDER BY l.updated_at DESC, l.id DESC LIMIT 200");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// `has` — is this torrent already in that list? Only for the reader's own lists, and only when they
// asked about a hash: it is the picker's question, and it is nobody else's business.
$hash = strtolower(trim((string)($_GET['hash'] ?? '')));
$inLists = [];
if ($isOwn && preg_match('/^[0-9a-f]{40}$/', $hash) && $rows) {
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    $in = implode(',', $ids);
    $q = $db->prepare("SELECT list_id FROM user_list_items WHERE info_hash = ? AND list_id IN ($in)");
    $q->execute([$hash]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $lid) $inLists[(int)$lid] = true;
}

jsonResponse([
    'success'   => true,
    'owner'     => (string)$owner['username'],
    'own'       => $isOwn,
    'may_publish' => $isOwn && listsPublicEnabled($cfg) && userCan($db, $cfg, 'lists.public'),
    'max_lists' => listsMaxPerUser($cfg),
    'max_items' => listsMaxItems($cfg),
    'lists'     => array_map(static fn($r) => [
        'id'          => (int)$r['id'],
        'name'        => (string)$r['name'],
        'slug'        => (string)$r['slug'],
        'description' => (string)$r['description'],
        'is_public'   => (int)$r['is_public'] === 1,
        'items'       => (int)$r['items'],
        'updated_at'  => (string)$r['updated_at'],
        'has'         => isset($inLists[(int)$r['id']]),
    ], $rows),
]);
