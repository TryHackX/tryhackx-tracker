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
/* ── searching the shelf ───────────────────────────────────────────────────────────────────────
 *
 * By default this matches the NAME of a list, which is what somebody looking for "Films" means.
 * `items=1` also matches the torrents ON a list, and `files=1` the file names inside those torrents
 * — "which of my lists has that episode in it?", which no list name can answer.
 *
 * The wider two are opt-in for a reason: they read one more table each. Both are bounded by this
 * owner's own rows (lists_max_per_user × lists_max_items, capped again below), never by the
 * catalogue, and the file search is gated on the same `index.files` permission as everywhere else.
 */
$wantItems = (string)($_GET['items'] ?? '') === '1';
$wantFiles = (string)($_GET['files'] ?? '') === '1' && userCan($db, $cfg, 'index.files');
$where  = ['l.user_id = ?'];
$params = [(int)$owner['id']];
if (!$isOwn) $where[] = 'l.is_public = 1';
$deepIds = null;
if ($search !== '') {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
    if ($wantItems || $wantFiles) {
        // One pass over this owner's items, then the answers in PHP — the same shape
        // api/user_list_items.php uses, and for the same reason: the driving set is small and
        // bounded, so a LIKE per row would buy nothing over one bounded read.
        $rowsQ = $db->prepare("SELECT i.list_id, i.info_hash, i.name FROM user_list_items i
                                 JOIN user_lists l2 ON l2.id = i.list_id
                                WHERE l2.user_id = ? LIMIT 20000");
        $rowsQ->execute([(int)$owner['id']]);
        $items = $rowsQ->fetchAll(PDO::FETCH_ASSOC);
        $needle = mb_strtolower($search);
        $deepIds = [];
        $hashes = [];
        foreach ($items as $it) {
            if ($wantItems && $it['name'] !== null && str_contains(mb_strtolower((string)$it['name']), $needle)) {
                $deepIds[(int)$it['list_id']] = true;
                continue;
            }
            if ($wantFiles) $hashes[strtolower((string)$it['info_hash'])] = true;
        }
        if ($wantFiles && $hashes) {
            $inFiles = favHashesMatchingFiles($db, array_slice(array_keys($hashes), 0, 5000), $search);
            if ($inFiles) {
                foreach ($items as $it) {
                    if (isset($inFiles[strtolower((string)$it['info_hash'])])) $deepIds[(int)$it['list_id']] = true;
                }
            }
        }
    }
    if ($deepIds) {
        // The name match still counts: a list called "Films" answers "films" whatever is in it.
        $ph = implode(',', array_fill(0, count($deepIds), '?'));
        $where[] = "(l.name LIKE ? OR l.description LIKE ? OR l.id IN ($ph))";
        $params[] = $like; $params[] = $like;
        foreach (array_keys($deepIds) as $lid) $params[] = $lid;
    } else {
        $where[] = '(l.name LIKE ? OR l.description LIKE ?)';
        $params[] = $like; $params[] = $like;
    }
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
