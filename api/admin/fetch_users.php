<?php
// Admin user browser: pagination, username/email search, status filter, sort. Groups summarised per row.
//
// WHO SEES WHAT (1.74.0, PRIV-2): the page is `panel.users.view` (api.php) — the moderator's preset has it, "seeing
// the user list" (v25). The members' e-mail addresses, the addresses they signed up and last signed in from, and
// the accounts a partner site links them to are shown IN FULL only with `panel.users.edit` — the staff who change
// them, and the owner. Everybody else gets the address masked (maskEmail()), the network instead of the address
// (/24, /48 — userIpShort()), the partner's name without the ids, and a search by name only: a search by part of an
// address was an oracle for "has this mailbox an account here", and a sort by it the same list in another order.
$fullPii = panelCan($db, $cfg, 'panel.users.edit');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, (int)($cfg['items_per_page'] ?? 25));
$offset = ($page - 1) * $perPage;

// 'group' sorts by the user's top group name (highest priority active membership; groupless last)
$groupSortExpr = "(SELECT g2.name FROM user_group_members m2 JOIN user_groups g2 ON g2.id = m2.group_id
                   WHERE m2.user_id = users.id AND m2.granted_at <= NOW() AND (m2.expires_at IS NULL OR m2.expires_at >= NOW())
                   ORDER BY g2.priority DESC, g2.name LIMIT 1)";
$allowedSorts = ['id' => 'id', 'username' => 'username', 'email' => 'email', 'status' => 'status',
                 'created' => 'created_at', 'login' => 'last_login_at', 'group' => $groupSortExpr];
if (!$fullPii) unset($allowedSorts['email']);
$orderParts = [];
foreach (explode(',', trim((string)($_GET['sort'] ?? 'created:desc'))) as $part) {
    $pieces = explode(':', trim($part));
    $col = $allowedSorts[$pieces[0] ?? ''] ?? null;
    if (!$col) continue;
    $orderParts[] = $col . ((strtolower($pieces[1] ?? 'asc') === 'desc') ? ' DESC' : ' ASC');
}
if (!$orderParts) $orderParts[] = 'created_at DESC';
$orderParts[] = 'id DESC';

$where = []; $params = [];
$search = trim((string)($_GET['search'] ?? ''));
if ($search !== '') {
    if ($fullPii) {
        $where[] = "(username LIKE ? OR email LIKE ?)";
        $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%';
    } else {
        $where[] = "username LIKE ?";
        $params[] = '%' . $search . '%';
    }
}
$status = (string)($_GET['status'] ?? '');
if (in_array($status, ['active', 'banned'], true)) { $where[] = "status = ?"; $params[] = $status; }
$groupFilter = (int)($_GET['group_id'] ?? 0);
if ($groupFilter > 0) { $where[] = "id IN (SELECT user_id FROM user_group_members WHERE group_id = ?)"; $params[] = $groupFilter; }
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count = $db->prepare("SELECT COUNT(*) FROM users $whereClause");
$count->execute($params);
$total = (int)$count->fetchColumn();

$stmt = $db->prepare("SELECT id, username, email, status, email_verified, created_at, created_ip, last_login_at, last_login_ip,
                             avatar_sha, cover_sha, bio
                      FROM users $whereClause ORDER BY " . implode(', ', $orderParts) . " LIMIT ? OFFSET ?");
$i = 1;
foreach ($params as $v) $stmt->bindValue($i++, $v, PDO::PARAM_STR);
$stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($i, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ids = array_map(fn($r) => (int)$r['id'], $rows);
$byUser = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $gm = $db->prepare(
        "SELECT m.user_id, g.slug, g.name, g.color, m.expires_at, m.granted_at,
                (m.granted_at <= NOW() AND (m.expires_at IS NULL OR m.expires_at >= NOW())) AS active
         FROM user_group_members m JOIN user_groups g ON g.id = m.group_id WHERE m.user_id IN ($in)
         ORDER BY g.priority DESC, g.name");
    $gm->execute($ids);
    foreach ($gm->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $byUser[(int)$g['user_id']][] = ['slug' => $g['slug'], 'name' => $g['name'], 'color' => $g['color'],
            'granted_at' => $g['granted_at'], 'expires_at' => $g['expires_at'], 'active' => (bool)$g['active']];
    }
}
// WHERE EACH ACCOUNT CAN SIGN IN FROM. One query for the page, like the groups above — an
// operator looking at a list of members needs to know which of them a partner can sign in as, and
// asking per row would be a query per row.
$bridgeBy = [];
if ($ids) {
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $bs = $db->prepare("SELECT user_id, provider, external_id, external_name, last_login_at, logout_at
                              FROM user_identities WHERE user_id IN ($in) ORDER BY created_at ASC");
        $bs->execute($ids);
        foreach ($bs->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $bridgeBy[(int)$b['user_id']][] = [
                'provider' => (string)$b['provider'],
                // The partner's own id and name for the person — the full view only (PRIV-2 above).
                'external_id' => $fullPii ? (string)$b['external_id'] : '',
                'external_name' => $fullPii ? $b['external_name'] : null, 'last_login_at' => $b['last_login_at'],
                'signed_out_there' => $b['logout_at'] !== null,
            ];
        }
    } catch (\Throwable $e) { $bridgeBy = []; }   // a database that predates v49 is not a failure
}
// The WARNINGS each account has had (1.71.0, includes/reports.php) — how many, and the latest five for the
// member's window: a moderator deciding about a member needs what others already told them. One query for the
// counts, one small one per account that has any.
$warned = ($ids && function_exists('userWarningsSummary')) ? userWarningsSummary($db, $ids, 5) : [];
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['email_verified'] = (int)$r['email_verified'];
    $r['groups'] = $byUser[$r['id']] ?? [];
    $r['identities'] = $bridgeBy[$r['id']] ?? [];
    $r['warnings'] = (int)($warned[$r['id']]['count'] ?? 0);
    $r['latest_warnings'] = $warned[$r['id']]['latest'] ?? [];
    // the mirrored panel admin — the UI greys out delete/ban/revoke-admin for this row
    $r['root_admin'] = userIsRootAdmin($r, $cfg);
    // The picture and the cover (1.63.0): whether there is one to take down in the edit modal, and
    // the picture's own square to show beside the button. The hashes themselves stay here.
    $r['has_avatar'] = function_exists('userMediaValidSha') && userMediaValidSha((string)($r['avatar_sha'] ?? ''));
    $r['has_cover'] = function_exists('userMediaValidSha') && userMediaValidSha((string)($r['cover_sha'] ?? ''));
    $r['avatar'] = $r['has_avatar'] ? userMediaUrl((string)$r['avatar_sha'], 128, getBaseUrl()) : '';
    // What is drawn beside the NAME (1.63.0 phase B) — in the list, and in the edit, grant and notify
    // windows: the account's own square, the site's default or the letter, and '' while pictures are
    // switched off. Not the same thing as `avatar` above, which is the account's own picture for the
    // remove button and is there whatever the switch says.
    $r['name_avatar'] = function_exists('userAvatarField') ? userAvatarField($r, 24, getBaseUrl(), $cfg) : '';
    // The description on their profile (1.69.0), for the edit modal's Clear: rendered here by the same
    // function the profile uses, so the moderator reads exactly what the page shows — and `bio_shown`
    // says whether the page shows it right now (the switch, and the account's own grant). Only rows
    // that have one pay for either question.
    $bioSrc = function_exists('profileBioClean') ? profileBioClean((string)($r['bio'] ?? '')) : '';
    $r['has_bio'] = $bioSrc !== '';
    $r['bio_html'] = $bioSrc !== '' ? profileBioRender($bioSrc, $cfg, $db) : '';
    $r['bio_shown'] = $bioSrc !== '' && profileBioFor($db, $cfg, $r) !== '';
    unset($r['avatar_sha'], $r['cover_sha'], $r['bio']);
    // The masked view (PRIV-2, the header): last, after everything above has read the row as it is.
    if (!$fullPii) {
        $mail = trim((string)($r['email'] ?? ''));
        $r['email'] = $mail !== '' ? maskEmail($mail) : $r['email'];
        $r['created_ip'] = userIpShort($r['created_ip'] ?? '');
        $r['last_login_ip'] = userIpShort($r['last_login_ip'] ?? '');
    }
}
unset($r);

$counts = ['total' => 0, 'active' => 0, 'banned' => 0];
try {
    foreach ($db->query("SELECT status, COUNT(*) c FROM users GROUP BY status") as $c) {
        $counts['total'] += (int)$c['c'];
        if (isset($counts[$c['status']])) $counts[$c['status']] = (int)$c['c'];
    }
} catch (\Throwable $e) {
    // Not "0 accounts" (1.74.0, QUAL-26): the panel says it could not load and keeps what it had.
    error_log('[admin] fetch_users: the status counts failed: ' . $e->getMessage());
    jsonResponse(['error' => __('api.db_unavailable')], 503);
}

jsonResponse(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage)),
              'counts' => $counts, 'enabled' => usersEnabled($cfg)]);
