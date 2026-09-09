<?php
/**
 * GET hash_favourites&hash=<40 hex>[&page=][&per_page=][&search=] — who has this in their favourites.
 *
 * ── the rule, and why the count obeys it too ───────────────────────────────────────────────────
 *
 * Somebody appears here only when every gate above them says yes: the site allows public favourites
 * and allows this list, their group holds `favourites.public`, their own list is public, and they
 * have not asked to be left off other people's lists (`fav_listed`). Any single no removes them from
 * the rows AND from the total.
 *
 * "14 people have this, 3 shown" would be a leak with a delay. The difference is stable and
 * cumulative, so whoever polls the counter learns the exact moment a hidden person favourited
 * something — a channel nobody asked to open and no user setting closes. So a hidden reader is not
 * counted anonymously; they are not there at all.
 *
 * ── and why the permission is decided in SQL ───────────────────────────────────────────────────
 *
 * Permissions live as JSON on group rows, so the decision cannot be expressed in SQL directly — but
 * it must be MADE there. Filtering in PHP after the LIMIT makes `total` a lie, and paginating a lie
 * is a leak: page two shows rows page one's count did not admit to. userGroupIdsWithPermission()
 * does one cheap query, decodes in PHP, and hands the WHERE a literal list of integers.
 */
if (!favWhoEnabled($cfg)) jsonResponse(['error' => 'not_found'], 404);

$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'not_found'], 404);
// A hash somebody cannot find is a hash they cannot ask about — the same rule api/index_info.php
// applies, and the reason this is checked before anything else is read.
if (!userCan($db, $cfg, 'index.view')) jsonResponse(['error' => 'not_found'], 404);
if (!userCan($db, $cfg, 'favourites.view_others')) jsonResponse(['error' => 'not_found'], 404);

$perHour = (int)($cfg['rate_limit_index_search'] ?? 120);
if (!rateLimitAllow('idxsearch', ipBucket(getClientIp($cfg)), $perHour, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

$hash = strtolower(trim((string)($_GET['hash'] ?? '')));
if (!preg_match('/^[0-9a-f]{40}$/', $hash)) jsonResponse(['error' => __('api.common.invalid_hash')], 400);

// After the checks, before the queries — the pattern api/index_search.php documents at length.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

$groupIds = userGroupIdsWithPermission($db, 'favourites.public');
if (!$groupIds) jsonResponse(['success' => true, 'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 25]);

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
$search = trim((string)($_GET['search'] ?? ''));

$in = implode(',', array_map('intval', $groupIds));
$where = ["f.info_hash = ?", "u.status = 'active'", "u.fav_public = 1", "u.fav_listed = 1",
          "EXISTS (SELECT 1 FROM user_group_members m WHERE m.user_id = u.id AND m.group_id IN ($in)
                     AND (m.expires_at IS NULL OR m.expires_at > NOW()))"];
$params = [$hash];
// An unverified account runs at guest level (v9), so its membership cannot count towards a list its
// group would otherwise allow.
if (userEmailVerifyRequired($cfg)) $where[] = "u.email_verified = 1";
if ($search !== '') {
    // A LIKE with a leading wildcard, ACCEPTABLE HERE AND ONLY HERE: the driving set is the people
    // who favourited ONE hash, reached through idx_fav_hash — never a table scan. Do not copy this
    // to anything whose driving set is a table.
    $where[] = "u.username LIKE ?";
    $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
}
$w = 'WHERE ' . implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM user_favourites f JOIN users u ON u.id = f.user_id $w");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

// username ASC, and NO timestamp in the reply. `created_at DESC` over the people who favourited one
// hash is a timeline of who got interested when, which is a fact about them and not about the hash.
$st = $db->prepare("SELECT u.username FROM user_favourites f JOIN users u ON u.id = f.user_id
                    $w ORDER BY u.username ASC LIMIT ? OFFSET ?");
$i = 1;
foreach ($params as $v) $st->bindValue($i++, $v, PDO::PARAM_STR);
$st->bindValue($i++, $perPage, PDO::PARAM_INT);
$st->bindValue($i, ($page - 1) * $perPage, PDO::PARAM_INT);
$st->execute();

jsonResponse([
    'success'  => true,
    'rows'     => array_map(static fn($r) => ['username' => $r['username']], $st->fetchAll(PDO::FETCH_ASSOC)),
    'total'    => $total,
    'page'     => $page,
    'pages'    => max(1, (int)ceil($total / $perPage)),
    'per_page' => $perPage,
]);
