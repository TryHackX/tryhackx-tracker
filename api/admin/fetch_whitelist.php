<?php
// Whitelist list with pagination, multi-column sorting, smart search and filters.

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 0);
if (!in_array($perPage, [15, 25, 50, 100, 200], true)) $perPage = max(1, (int)($cfg['items_per_page'] ?? 25));
$offset = ($page - 1) * $perPage;

// Safe sort whitelist
$allowedSorts = [
    'id'       => 'id',
    'hash'     => 'info_hash',
    'name'     => 'name',
    'size'     => 'total_size',
    'source'   => 'source',
    'ip'       => 'ip',
    'meta'     => 'meta_status',
    'seeders'  => 'scrape_seeders',
    'leechers' => 'scrape_leechers',
    'files'    => 'files_count',
    'date'     => 'created_at',
    'banned'   => 'banned',
];

// Multi-sort support: sort=ip:asc,date:desc — "group by IP" forces its own order
$groupByIp = ($_GET['group'] ?? '') === 'ip';
$orderParts = [];
if ($groupByIp) {
    $orderParts = ['ip ASC', 'created_at DESC'];
} else {
    $sortParam = trim($_GET['sort'] ?? 'date:desc');
    foreach (explode(',', $sortParam) as $part) {
        $pieces = explode(':', trim($part));
        $col = $allowedSorts[$pieces[0] ?? ''] ?? null;
        if (!$col) continue;
        $dir = (strtolower($pieces[1] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
        $orderParts[] = "$col $dir";
    }
}
if (empty($orderParts)) {
    $orderParts[] = 'created_at DESC';
}
$orderParts[] = 'id DESC'; // deterministic tie-break for pagination

/**
 * Turn free text into a MySQL BOOLEAN MODE expression: strip operators, prefix-match every word.
 * Returns '' when nothing usable remains.
 */
function whitelistFulltextTerm(string $term): string {
    $clean = preg_replace('/[+\-><()~"@*]/u', ' ', $term) ?? '';
    $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($words as $w) {
        if (mb_strlen($w) < 2) continue; // shorter than the InnoDB minimum token size anyway
        $out[] = $w . '*';
    }
    return implode(' ', $out);
}

// Build WHERE conditions
$where = [];
$params = [];

// Smart search: hash prefix / IP prefix / name (FULLTEXT with LIKE fallback)
$search = trim($_GET['search'] ?? '');
$searchFiles = ($_GET['search_files'] ?? '') === '1';
$fulltextClause = null; // ['sql' => ..., 'params' => [...]] tried first; LIKE fallback if the DB rejects it
$likeClause = null;
if ($search !== '') {
    if (preg_match('/^[a-f0-9]{6,40}$/i', $search)) {
        $where[] = "info_hash LIKE ?";
        $params[] = strtolower($search) . '%';
    } elseif (preg_match('/^[0-9a-f:.\/]+$/i', $search) && (str_contains($search, '.') || str_contains($search, ':'))) {
        $where[] = "ip LIKE ?";
        $params[] = $search . '%';
    } else {
        $like = ['sql' => "name LIKE ?", 'params' => ['%' . $search . '%']];
        if ($searchFiles) {
            $like = ['sql' => "(name LIKE ? OR id IN (SELECT whitelist_id FROM whitelist_files WHERE path LIKE ?))", 'params' => ['%' . $search . '%', '%' . $search . '%']];
        }
        $likeClause = $like;
        $ft = mb_strlen($search) >= 3 ? whitelistFulltextTerm($search) : '';
        if ($ft !== '') {
            $fulltextClause = ['sql' => "MATCH(name) AGAINST(? IN BOOLEAN MODE)", 'params' => [$ft]];
            if ($searchFiles) {
                $fulltextClause = ['sql' => "(MATCH(name) AGAINST(? IN BOOLEAN MODE) OR id IN (SELECT whitelist_id FROM whitelist_files WHERE MATCH(path) AGAINST(? IN BOOLEAN MODE)))", 'params' => [$ft, $ft]];
            }
        }
    }
}

// Filters
$sourceFilter = $_GET['source'] ?? '';
if (in_array($sourceFilter, ['web', 'api', 'admin', 'forum'], true)) {
    $where[] = "source = ?";
    $params[] = $sourceFilter;
}
$metaFilter = $_GET['meta'] ?? '';
if (in_array($metaFilter, ['none', 'pending', 'fetching', 'done', 'failed'], true)) {
    $where[] = "meta_status = ?";
    $params[] = $metaFilter;
}
$bannedFilter = $_GET['banned'] ?? 'active';
switch ($bannedFilter) {
    case 'banned':
        $where[] = "banned = 1";
        break;
    case 'all':
        break;
    default:
        $where[] = "banned = 0";
        break;
}
$ipFilter = trim($_GET['ip'] ?? '');
if ($ipFilter !== '') {
    $where[] = "(ip = ? OR ip_bucket = ?)";
    $params[] = $ipFilter;
    $params[] = $ipFilter;
}

$columns = "id, info_hash, name, source, source_ref, api_client_id, ip, ip_bucket, banned, meta_status, meta_error,
            total_size, files_count, scrape_seeders, scrape_leechers, scrape_completed, scraped_at, created_at, updated_at,
            source_url, description, description_format, content_status, content_rejected_note";
$orderClause = implode(', ', $orderParts);

/** Run count + page query for a given extra search clause (null = none). Throws on SQL error. */
$runQuery = function (?array $extra) use ($db, $where, $params, $columns, $orderClause, $perPage, $offset): array {
    $w = $where;
    $p = $params;
    if ($extra) {
        $w[] = $extra['sql'];
        $p = array_merge($p, $extra['params']);
    }
    $whereClause = $w ? 'WHERE ' . implode(' AND ', $w) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM whitelist $whereClause");
    $countStmt->execute($p);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT $columns FROM whitelist $whereClause ORDER BY $orderClause LIMIT ? OFFSET ?");
    $paramIdx = 1;
    foreach ($p as $v) {
        $stmt->bindValue($paramIdx++, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue($paramIdx++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($paramIdx, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return [$total, $stmt->fetchAll()];
};

$total = 0;
$rows = [];
if ($fulltextClause) {
    try {
        [$total, $rows] = $runQuery($fulltextClause);
    } catch (\Throwable $e) {
        // FULLTEXT index missing / term rejected — degrade to LIKE
        [$total, $rows] = $runQuery($likeClause);
    }
} else {
    [$total, $rows] = $runQuery($likeClause);
}
$pages = max(1, (int)ceil($total / $perPage));

// Normalise row types + decode source_ref
$ipsOnPage = [];
foreach ($rows as &$row) {
    $row['id'] = (int)$row['id'];
    $row['banned'] = (int)$row['banned'];
    $row['api_client_id'] = $row['api_client_id'] !== null ? (int)$row['api_client_id'] : null;
    foreach (['total_size', 'files_count', 'scrape_seeders', 'scrape_leechers', 'scrape_completed'] as $k) {
        $row[$k] = $row[$k] !== null ? (int)$row[$k] : null;
    }
    $ref = null;
    if (!empty($row['source_ref'])) {
        $decoded = json_decode((string)$row['source_ref'], true);
        if (is_array($decoded)) $ref = $decoded;
    }
    $row['source_ref'] = $ref;
    if ($row['ip'] !== '') $ipsOnPage[$row['ip']] = true;
}
unset($row);

// Per-IP totals for the IPs present on this page (one GROUP BY query)
$ipCounts = [];
if ($ipsOnPage) {
    $ips = array_keys($ipsOnPage);
    $ph = implode(',', array_fill(0, count($ips), '?'));
    $st = $db->prepare("SELECT ip, COUNT(*) AS c FROM whitelist WHERE ip IN ($ph) GROUP BY ip");
    $st->execute($ips);
    foreach ($st->fetchAll() as $r) $ipCounts[$r['ip']] = (int)$r['c'];
}

// Global counts for badges
$counts = ['active' => 0, 'banned' => 0, 'pending_meta' => 0];
try {
    $counts['active'] = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE banned = 0")->fetchColumn();
    $counts['banned'] = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE banned = 1")->fetchColumn();
    $counts['pending_meta'] = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE meta_status IN ('pending','fetching')")->fetchColumn();
} catch (\Throwable $e) {}

jsonResponse([
    'rows' => $rows,
    'total' => $total,
    'page' => $page,
    'pages' => $pages,
    'ip_counts' => (object)$ipCounts,
    'counts' => $counts,
    'mode' => trackerMode($cfg),
]);
