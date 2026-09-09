<?php
/**
 * "My uploads" — the whitelist rows an account registered while signed in.
 *
 *   GET  user_uploads[&user=<name>][&page=][&per_page=][&search=][&sort=][&status=]
 *   POST user_uploads  {op:'visibility', hash, value, csrf_token}
 *
 * ── this exists only where a submission can happen ─────────────────────────────────────────────
 * api/whitelist_submit.php refuses every submission outside whitelist mode and the schedule, so on
 * a blacklist-mode tracker with no schedule there is nothing to attribute and this answers 404
 * rather than an empty list. Note that is NOT the same as "whitelist mode": a blacklist install with
 * the schedule on still takes submissions.
 *
 * ── status is a FILTER, never a sort column ────────────────────────────────────────────────────
 * whitelistDisplayStatus() is a PHP function, and no ORDER BY can call one. The filter is a literal
 * SQL fragment chosen by key — the $contentSql idiom from includes/index.php, where nothing from the
 * request reaches the SQL. Sorting stays on real columns.
 */
if (!usersEnabled($cfg) || !uploadsPossible($cfg)) jsonResponse(['error' => 'not_found'], 404);

$me = currentUser($db);

/* ─────────────────────── POST: show this on my profile ──────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => __('api.csrf.invalid')], 403);
    }
    if (!$me) jsonResponse(['error' => 'login_required'], 401);
    if ((string)($input['op'] ?? '') !== 'visibility') jsonResponse(['error' => __('api.index.unknown_op')], 400);
    if (!uploadsPublicEnabled($cfg)) jsonResponse(['error' => 'uploads_public_disabled'], 403);
    if (!userCan($db, $cfg, 'uploads.public')) jsonResponse(['error' => 'no_permission'], 403);
    $hash = strtolower(trim((string)($input['hash'] ?? '')));
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) jsonResponse(['error' => __('api.common.invalid_hash')], 400);
    // Authorised by submitter_id and nothing else: this changes a row, and the only person who may
    // is the one it is attributed to.
    $st = $db->prepare("UPDATE whitelist SET submitter_public = ? WHERE info_hash = ? AND submitter_id = ?");
    $st->execute([!empty($input['value']) ? 1 : 0, $hash, (int)$me['id']]);
    if ($st->rowCount() === 0) {
        // Either not theirs or already in that state. Distinguishing the two would tell a caller
        // whether a hash they do not own exists.
        $own = $db->prepare("SELECT 1 FROM whitelist WHERE info_hash = ? AND submitter_id = ? LIMIT 1");
        $own->execute([$hash, (int)$me['id']]);
        if (!$own->fetchColumn()) jsonResponse(['error' => 'not_found'], 404);
    }
    jsonResponse(['success' => true, 'public' => !empty($input['value'])]);
}

/* ─────────────────────────── GET: a list ────────────────────────────────── */

$who = trim((string)($_GET['user'] ?? ''));
$owner = null;
$isOwn = false;

if ($who === '') {
    if (!$me) jsonResponse(['error' => 'login_required'], 401);
    $owner = $me;
    $isOwn = true;
} else {
    if (!$me) jsonResponse(['error' => 'not_found'], 404);
    if (!profilesEnabled($cfg) || !uploadsPublicEnabled($cfg)) jsonResponse(['error' => 'not_found'], 404);
    if (!userCan($db, $cfg, 'favourites.view_others')) jsonResponse(['error' => 'not_found'], 404);
    if (!userValidUsername($who)) jsonResponse(['error' => 'not_found'], 404);
    $owner = userFindByLogin($db, $who);
    if (!$owner || ($owner['status'] ?? '') !== 'active') jsonResponse(['error' => 'not_found'], 404);
    $isOwn = (int)$owner['id'] === (int)($me['id'] ?? 0);
    if (!$isOwn && !userIdHasPermission($db, $cfg, (int)$owner['id'], 'uploads.public')) {
        jsonResponse(['error' => 'not_found'], 404);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
$search = trim((string)($_GET['search'] ?? ''));

$where = ['submitter_id = ?'];
$params = [(int)$owner['id']];
// The visibility flag is enforced HERE and in the profile's own render, and nowhere else. It decides
// whose list a torrent appears on — never what the tracker serves, never what the search finds.
if (!$isOwn) $where[] = 'submitter_public = 1';
if ($search !== '') {
    $where[] = '(name LIKE ? OR info_hash LIKE ?)';
    $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
    $params[] = strtolower($search) . '%';
}
// Literal fragments chosen by key; nothing from the request reaches the SQL. `live` is everything
// that is neither blocked nor mid-probe nor refused — the rows the tracker actually serves.
$statusSql = [
    'blocked' => 'banned = 1',
    'refused' => "banned = 0 AND probe_status = 'failed'",
    'waiting' => "banned = 0 AND probe_status = 'probing'",
    'live'    => "banned = 0 AND (probe_status IS NULL OR probe_status NOT IN ('failed', 'probing'))",
];
$status = (string)($_GET['status'] ?? '');
if (isset($statusSql[$status])) $where[] = $statusSql[$status];

$sortCols = ['added' => 'created_at', 'name' => 'name', 'size' => 'total_size',
             'seeders' => 'scrape_seeders', 'content' => 'content_status', 'visibility' => 'submitter_public'];
[$col, $dir] = array_pad(explode(':', (string)($_GET['sort'] ?? 'added:desc'), 2), 2, 'desc');
$order = ($sortCols[$col] ?? 'created_at') . (strtolower($dir) === 'asc' ? ' ASC' : ' DESC') . ', id DESC';

$w = 'WHERE ' . implode(' AND ', $where);
$cnt = $db->prepare("SELECT COUNT(*) FROM whitelist $w");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

$st = $db->prepare("SELECT info_hash, name, total_size, files_count, created_at, banned, probe_status,
                           content_status, submitter_public, scrape_seeders, scrape_leechers, scraped_at
                      FROM whitelist $w ORDER BY $order LIMIT ? OFFSET ?");
$i = 1;
foreach ($params as $v) $st->bindValue($i++, $v, PDO::PARAM_STR);
$st->bindValue($i++, $perPage, PDO::PARAM_INT);
$st->bindValue($i, ($page - 1) * $perPage, PDO::PARAM_INT);
$st->execute();

$canMagnet = userCan($db, $cfg, 'index.magnet');
$rows = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rows[] = [
        'info_hash'  => ($canMagnet && (int)$r['banned'] !== 1) ? $r['info_hash'] : null,
        'name'       => $r['name'],
        'total_size' => $r['total_size'] !== null ? (int)$r['total_size'] : null,
        'files_count'=> $r['files_count'] !== null ? (int)$r['files_count'] : null,
        'created_at' => $r['created_at'],
        'seeders'    => $r['scrape_seeders'] !== null ? (int)$r['scrape_seeders'] : null,
        'leechers'   => $r['scrape_leechers'] !== null ? (int)$r['scrape_leechers'] : null,
        // Three separate claims, deliberately not folded into one badge: what the tracker does with
        // it, what happened to the words attached to it, and whose list it shows up on.
        'status'         => whitelistDisplayStatus($r, $cfg),
        'content_status' => (string)($r['content_status'] ?? 'none'),
        'public'         => (int)$r['submitter_public'] === 1,
    ];
}

jsonResponse([
    'success'    => true,
    'own'        => $isOwn,
    'user'       => ['username' => $owner['username'], 'member_since' => $owner['created_at'] ?? null],
    'rows'       => $rows,
    'total'      => $total,
    'page'       => $page,
    'pages'      => max(1, (int)ceil($total / $perPage)),
    'per_page'   => $perPage,
    'may_publish'=> $isOwn && uploadsPublicEnabled($cfg) && userCan($db, $cfg, 'uploads.public'),
]);
