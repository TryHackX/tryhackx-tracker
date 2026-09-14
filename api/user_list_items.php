<?php
/**
 * What is inside one list.
 *
 *   GET  user_list_items&list=<id>[&user=<name>][&page=][&per_page=][&search=][&sort=]
 *   POST user_list_items {op:'add'|'remove', list, hash|magnet, csrf_token}
 *
 * ── adding by hash, not by finding the row first ───────────────────────────────────────────────
 *
 * A magnet link or a bare info hash is enough. The catalogue is asked what it knows — and the
 * answer only decides what NAME the row carries, never whether it may be added: a hash the tracker
 * has never seen still builds a working magnet, and refusing it would make the feature useless for
 * exactly the case it exists for (gathering a pack in blacklist mode, where there is no whitelist to
 * search). A hash the tracker REFUSES is a different matter and is refused here too.
 *
 * ── two cheap queries, never a join ────────────────────────────────────────────────────────────
 * The list's own rows first (bounded by lists_max_items), then the metadata for one page by literal
 * IN(), exactly as api/user_favourites.php does and for the same reason.
 */
if (!listsEnabled($cfg)) jsonResponse(['error' => 'lists_disabled'], 404);

$me = currentUser($db);

/* ─────────────────────────── POST: add / remove ─────────────────────────── */
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
    $uid  = (int)$me['id'];
    $lid  = (int)($input['list'] ?? 0);
    $own  = $db->prepare("SELECT id FROM user_lists WHERE id = ? AND user_id = ? LIMIT 1");
    $own->execute([$lid, $uid]);
    if (!$own->fetchColumn()) jsonResponse(['error' => 'not_found'], 404);

    $op = (string)($input['op'] ?? 'add');

    // ── removing a row by its own id ──────────────────────────────────────────────────────────
    // The GET below hands out rows with info_hash = null to a reader without `index.magnet`, so the
    // hash is not always a name the page can use. The row's id always is. Owner-checked by the
    // list: $lid was matched against this account above, and the DELETE is keyed on both.
    $itemId = (int)($input['id'] ?? 0);
    if ($op === 'remove' && $itemId > 0) {
        $db->prepare("DELETE FROM user_list_items WHERE id = ? AND list_id = ?")->execute([$itemId, $lid]);
        $db->prepare("UPDATE user_lists SET updated_at = NOW() WHERE id = ?")->execute([$lid]);
        jsonResponse(['success' => true, 'removed' => $itemId, 'items' => listItemCount($db, $lid)]);
    }

    // A magnet or a hash — the same parser the whitelist submission uses, so what is accepted here
    // is what is accepted there, including base32 and the name inside a magnet's `dn=`.
    $token = trim((string)($input['magnet'] ?? $input['hash'] ?? ''));
    $p = parseMagnetOrHash($token);
    if ($p['hash'] === null) jsonResponse(['error' => 'invalid_hash', 'detail' => $p['error'] ?? null], 400);
    $hash = strtolower($p['hash']);
    // What this account may be told about a hash: the pair api/index_info.php asks before it hands
    // over a whitelist row. Without it `check` would answer "registered here: <name>" out of rows
    // the search page refuses this reader.
    $canWl = userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1';

    // What the box under a list asks while somebody is still typing. Behind the same rate limit and
    // the same permission as an add, so live feedback cannot become a faster way to probe the
    // catalogue than adding to it would be.
    if ($op === 'check') {
        if (!userCan($db, $cfg, 'index.view')) jsonResponse(['error' => 'no_permission'], 403);
        $k = listHashKnown($db, $hash, $canWl);
        jsonResponse(['success' => true, 'hash' => $hash, 'known' => $k['known'], 'name' => $k['name'],
                      'blocked' => isHashBlocked($db, $cfg, $hash)]);
    }
    if ($op === 'remove') {
        $db->prepare("DELETE FROM user_list_items WHERE list_id = ? AND info_hash = ?")->execute([$lid, $hash]);
        $db->prepare("UPDATE user_lists SET updated_at = NOW() WHERE id = ?")->execute([$lid]);
        jsonResponse(['success' => true, 'removed' => $hash, 'items' => listItemCount($db, $lid)]);
    }

    // A hash somebody cannot find is a hash they cannot collect: the same rule the star applies.
    if (!userCan($db, $cfg, 'index.view')) jsonResponse(['error' => 'no_permission'], 403);
    // And one the tracker refuses is not something to gather a pack around.
    if (isHashBlocked($db, $cfg, $hash)) jsonResponse(['error' => 'hash_blocked'], 409);
    // IT HAS TO BE A HASH THIS TRACKER KNOWS. Not necessarily RESOLVED — a registered row whose
    // metadata the worker has not fetched yet is still a torrent this tracker has, and waiting for a
    // background job is not a reason to refuse it. But any forty hex characters at all would make a
    // list a place to keep arbitrary strings on somebody else's server, and would hand the reader a
    // row that can never become anything but a hash.
    $known = listHashKnown($db, $hash, $canWl);
    if (!$known['known']) jsonResponse(['error' => 'hash_unknown'], 404);

    $max = listsMaxItems($cfg);

    // The name, in this order: what the sender's magnet called it, then what the catalogue knows,
    // then nothing. The stored name is a fallback for the day the catalogue prunes the hash — see
    // listItemsWithMeta(), which prefers the live row whenever there is one.
    // The catalogue's name wins where there is one — it is what every other page shows for this
    // hash — and the magnet's own `dn=` is the fallback for a row that is registered but unresolved.
    $name = $known['name'] !== null ? mb_substr((string)$known['name'], 0, 255) : null;
    if ($name === null || $name === '') {
        $name = $p['name'] !== null ? mb_substr(cleanTorrentName((string)$p['name']), 0, 255) : null;
    }
    // Count and insert are two statements, so they run as one behind a lock on the account's own
    // row: two tabs adding at once both counted `max - 1` and both wrote, and a ceiling that can be
    // crossed by going faster is not a ceiling. The lock is on the person, not on the list, so it
    // also holds when they are filling two of their own lists at the same time.
    try {
        $db->beginTransaction();
        $db->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE")->execute([$uid]);
        if (listItemCount($db, $lid) >= $max) {
            $db->rollBack();
            jsonResponse(['error' => 'list_full', 'limit' => $max], 409);
        }
        $db->prepare("INSERT IGNORE INTO user_list_items (list_id, info_hash, name) VALUES (?, ?, ?)")
           ->execute([$lid, $hash, $name !== '' ? $name : null]);
        $db->prepare("UPDATE user_lists SET updated_at = NOW() WHERE id = ?")->execute([$lid]);
        $items = listItemCount($db, $lid);
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    jsonResponse(['success' => true, 'hash' => $hash, 'name' => $name, 'items' => $items]);
}

/* ───────────────────────────── GET: the rows ────────────────────────────── */

$lid = (int)($_GET['list'] ?? 0);
if ($lid < 1) jsonResponse(['error' => 'not_found'], 404);

$st = $db->prepare("SELECT l.*, u.username, u.status, u.lists_public FROM user_lists l
                    JOIN users u ON u.id = l.user_id WHERE l.id = ? LIMIT 1");
$st->execute([$lid]);
$list = $st->fetch(PDO::FETCH_ASSOC);
if (!$list || ($list['status'] ?? '') !== 'active') jsonResponse(['error' => 'not_found'], 404);

$isOwn = $me !== null && (int)$list['user_id'] === (int)$me['id'];
if ($isOwn) {
    if (!userCan($db, $cfg, 'lists.use')) jsonResponse(['error' => 'not_found'], 404);
} else {
    // Every gate the profile applies, and one 404 for all of them — including profilesEnabled(),
    // because a list of somebody else's is read on their profile and with profiles off there is no
    // page for it to be on.
    if (!$me || !profilesEnabled($cfg) || !userCan($db, $cfg, 'favourites.view_others')) jsonResponse(['error' => 'not_found'], 404);
    if ((int)$list['is_public'] !== 1) jsonResponse(['error' => 'not_found'], 404);
    // A `hide_profile` block closes the page; the rows behind it answer the same 404.
    if (profileHiddenFrom($db, (int)$list['user_id'], (int)$me['id'])) jsonResponse(['error' => 'not_found'], 404);
    $ownerRow = ['id' => (int)$list['user_id'], 'lists_public' => (int)$list['lists_public']];
    if (!listsVisibleFor($db, $cfg, $ownerRow)) jsonResponse(['error' => 'not_found'], 404);
}

// What this reader may be shown of each row: whitelist rows at all, and the hash itself. Asked
// after the gates, because a reader who got this far is a reader the list is open to.
$canWlView = userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1';
$canMagnet = userCan($db, $cfg, 'index.magnet');

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
$search  = trim((string)($_GET['search'] ?? ''));
$sort    = (string)($_GET['sort'] ?? 'added:desc');
// Whether the file names are searched too. Default yes — this is bounded by one person's own
// hashes, and the reader who typed a file name is the reason it exists — but the toolbar carries the
// same checkbox the search page carries, and an unticked box has to be able to say so. Gated on
// index.files either way: searching inside a file list you may not open is still reading it.
$wantFiles = (string)($_GET['files'] ?? '1') !== '0' && userCan($db, $cfg, 'index.files');

// The rows of ONE list, bounded by the same ceiling that bounds writing to it — so the page is cut
// from an array that can never be bigger than lists_max_items, and the search runs over the stored
// name and the hash without a LIKE on a catalogue table.
// The ceiling is bound, not interpolated. It is an int from a clamped helper and would be safe
// either way, but a query built by concatenation is a query somebody has to read twice — and
// tests/sql_safety_test.php is right to make that cost visible.
// `id` travels with the row: it is how a reader without `index.magnet` names one to remove it.
$all = $db->prepare("SELECT id, info_hash, name, added_at FROM user_list_items
                      WHERE list_id = ? ORDER BY added_at DESC, id DESC LIMIT ?");
$all->bindValue(1, $lid, PDO::PARAM_INT);
$all->bindValue(2, listsMaxItems($cfg), PDO::PARAM_INT);
$all->execute();
$rows = $all->fetchAll(PDO::FETCH_ASSOC);
$items = listItemsWithMeta($db, $rows, $canWlView, $isOwn);

if ($search !== '') {
    $needle = mb_strtolower($search);
    // The file names too, bounded by the hashes this list already holds — see favHashesMatchingFiles().
    // Charged to the search page's own hourly bucket: it reads a files table, and where the cost is
    // the same the meter is the same.
    if ($wantFiles && !rateLimitAllow('idxsearch', ipBucket(getClientIp($cfg)), (int)($cfg['rate_limit_index_search'] ?? 120), 3600)) {
        jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
    }
    $inFiles = $wantFiles ? favHashesMatchingFiles($db, array_column($items, 'info_hash'), $search) : [];
    $items = array_values(array_filter($items, static function ($i) use ($needle, $inFiles) {
        return str_contains(mb_strtolower((string)($i['name'] ?? '')), $needle)
            || str_contains((string)$i['info_hash'], $needle)
            || isset($inFiles[strtolower((string)$i['info_hash'])]);
    }));
}
usort($items, static function ($a, $b) use ($sort) {
    [$key, $dir] = array_pad(explode(':', $sort, 2), 2, 'desc');
    $mul = $dir === 'asc' ? 1 : -1;
    return match ($key) {
        'name'    => $mul * strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')),
        'size'    => $mul * ((int)($a['total_size'] ?? 0) <=> (int)($b['total_size'] ?? 0)),
        'seeders' => $mul * ((int)($a['seeders'] ?? 0) <=> (int)($b['seeders'] ?? 0)),
        default   => $mul * (strcmp((string)($a['added_at'] ?? ''), (string)($b['added_at'] ?? ''))),
    };
});

$total = count($items);
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

$slice = array_slice($items, ($page - 1) * $perPage, $perPage);
foreach ($slice as &$row) {
    // The same rule api/user_favourites.php applies to its own rows: no `index.magnet`, no hash —
    // and none for a banned row either, because the tracker refuses to serve it and a link that
    // cannot work is worse than saying so. The row's `id` is what remains to name it by, which is
    // why it is there: a row nobody can name is a row its owner cannot remove.
    if (!$canMagnet || $row['banned']) $row['info_hash'] = null;
}
unset($row);

jsonResponse([
    'success'  => true,
    'list'     => ['id' => (int)$list['id'], 'name' => (string)$list['name'], 'slug' => (string)$list['slug'],
                   'description' => (string)$list['description'], 'is_public' => (int)$list['is_public'] === 1,
                   'owner' => (string)$list['username'], 'own' => $isOwn],
    'rows'     => $slice,
    'total'    => $total,
    'page'     => $page,
    'pages'    => $pages,
    'per_page' => $perPage,
]);
