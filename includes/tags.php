<?php

const TAG_ORIGIN_MEMBER = 1;
const TAG_ORIGIN_MODERATOR = 2;
const TAG_ORIGIN_FORUM = 4;
const TAGS_LIST_CAP = 10000;

function tagsEnabled(array $cfg): bool
{
    return ($cfg['tags_enabled'] ?? '1') === '1';
}

function tagsReady(PDO $db): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $ready = function_exists('schemaTableExists') ? schemaTableExists($db, 'hash_tags') : (bool)$db->query("SHOW TABLES LIKE 'hash_tags'")->fetchColumn(); }
        catch (\Throwable $e) { $ready = false; }
    }
    return $ready;
}

function tagsLimit(array $cfg, string $key, int $default, int $min, int $max): int
{
    $v = (int)($cfg[$key] ?? $default);
    return max($min, min($max, $v));
}

function tagsSlug(string $name): string
{
    $s = factsGenreSlug($name);
    if ($s === '' && function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($name)));
        if (is_string($t)) $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-');
    }
    return trim(substr($s, 0, 64), '-');
}

function tagsName(string $name, int $max): ?string
{
    $n = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[\x{0000}-\x{001F}\x{007F}<>]/u', '', $name)));
    if ($n === '' || mb_strlen($n) > $max || tagsSlug($n) === '') return null;
    return $n;
}

function tagsColor(?string $c): ?string
{
    $c = strtolower(trim((string)$c));
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $c, $m)) return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
    return preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : null;
}

function tagsHashKnown(PDO $db, string $hash): bool
{
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return false;
    $st = $db->prepare("SELECT 1 FROM whitelist WHERE info_hash = ? AND banned = 0 UNION ALL SELECT 1 FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash, $hash]);
    return (bool)$st->fetchColumn();
}

function tagsMessage(string $code): string
{
    $words = ['name' => __('api.tags.name'), 'rejected' => __('api.tags.rejected'), 'limit' => __('api.tags.limit'),
              'unavailable' => __('api.tags.unavailable'), 'unknown' => __('api.tags.unknown'), 'blocked' => __('api.tags.blocked'),
              'too_many' => __('api.tags.too_many'), 'forbidden' => __('api.tags.forbidden'), 'not_found' => __('api.tags.not_found'),
              'target' => __('api.tags.target')];
    return $words[$code] ?? __('api.tags.invalid');
}

function tagsIsExplicit(string $id): bool
{
    return preg_match('/^x:[a-z0-9-]{1,64}$/', $id) === 1;
}

function tagsAutoMatch(string $name): ?string
{
    $n = mb_strtolower(trim($name));
    $slug = tagsSlug($name);
    foreach (array_merge(array_keys(FACTS_LABELS), FACTS_WORDS) as $id) {
        $label = factsTagLabel($id);
        if (mb_strtolower($label) === $n || tagsSlug($label) === $slug || str_replace('_', '-', substr($id, 2)) === $slug) return $id;
    }
    return null;
}

function tagsRow(PDO $db, string $id): ?array
{
    if (!tagsIsExplicit($id) || !tagsReady($db)) return null;
    $st = $db->prepare("SELECT * FROM tags WHERE slug = ?");
    $st->execute([substr($id, 2)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function tagsCreate(PDO $db, array $cfg, string $name, ?int $userId, bool $trusted, string $origin = 'member'): array
{
    if (!tagsReady($db)) return ['ok' => false, 'error' => 'unavailable'];
    $clean = tagsName($name, tagsLimit($cfg, 'tags_name_max', 32, 2, 40));
    if ($clean === null) return ['ok' => false, 'error' => 'name'];
    $auto = tagsAutoMatch($clean);
    if ($auto !== null) return ['ok' => true, 'id' => $auto, 'auto' => true, 'status' => 'approved'];
    $slug = tagsSlug($clean);
    $existing = tagsRow($db, 'x:' . $slug);
    if ($existing) {
        if ($existing['status'] === 'rejected') return ['ok' => false, 'error' => 'rejected'];
        return ['ok' => true, 'id' => 'x:' . $slug, 'status' => (string)$existing['status']];
    }
    if (!$trusted && $userId !== null) {
        $st = $db->prepare("SELECT COUNT(*) FROM tags WHERE created_by = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY");
        $st->execute([$userId]);
        if ((int)$st->fetchColumn() >= tagsLimit($cfg, 'tags_create_per_day', 20, 0, 500)) return ['ok' => false, 'error' => 'limit'];
    }
    $status = $trusted || ($cfg['tags_review'] ?? 'approve') === 'open' ? 'approved' : 'pending';
    $db->prepare("INSERT IGNORE INTO tags (slug, name, status, origin, created_by, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
       ->execute([$slug, $clean, $status, in_array($origin, ['member', 'moderator', 'forum'], true) ? $origin : 'member', $userId]);
    $row = tagsRow($db, 'x:' . $slug);
    return $row ? ['ok' => true, 'id' => 'x:' . $slug, 'status' => (string)$row['status'], 'created' => true] : ['ok' => false, 'error' => 'unavailable'];
}

function tagsBlocked(PDO $db, array $hashes): array
{
    $hashes = array_values(array_unique(array_filter(array_map('strtolower', $hashes), fn($h) => preg_match('/^[0-9a-f]{40}$/', $h) === 1)));
    if (!$hashes || !tagsReady($db)) return [];
    $out = [];
    foreach (array_chunk($hashes, 1000) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT info_hash, tag FROM hash_tag_blocks WHERE info_hash IN ($in)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$h, $t]) $out[strtolower((string)$h)][] = (string)$t;
    }
    return $out;
}

function tagsAttach(PDO $db, array $cfg, string $hash, string $id, ?int $userId, int $origin, bool $moderate): array
{
    $hash = strtolower($hash);
    if (!preg_match('/^[0-9a-f]{40}$/', $hash) || !tagsReady($db)) return ['ok' => false, 'error' => 'invalid'];
    if (!factsTagValid($id) || str_starts_with($id, 'g:')) return ['ok' => false, 'error' => 'invalid'];
    if (tagsIsExplicit($id)) {
        $row = tagsRow($db, $id);
        if (!$row || $row['status'] === 'rejected' || (int)$row['hidden'] === 1) return ['ok' => false, 'error' => 'unknown'];
    }
    $blocked = in_array($id, tagsBlocked($db, [$hash])[$hash] ?? [], true);
    if ($blocked && !$moderate) return ['ok' => false, 'error' => 'blocked'];
    if ($blocked) tagsUnblock($db, $hash, $id);
    $st = $db->prepare("SELECT COUNT(*) FROM hash_tags WHERE info_hash = ?");
    $st->execute([$hash]);
    $have = $db->prepare("SELECT 1 FROM hash_tags WHERE info_hash = ? AND tag = ?");
    $have->execute([$hash, $id]);
    if (!$have->fetchColumn() && (int)$st->fetchColumn() >= tagsLimit($cfg, 'tags_max_per_torrent', 40, 1, 100)) return ['ok' => false, 'error' => 'too_many'];
    $db->prepare("INSERT INTO hash_tags (info_hash, tag, origin, added_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
                  ON DUPLICATE KEY UPDATE origin = origin | VALUES(origin), added_by = COALESCE(added_by, VALUES(added_by))")
       ->execute([$hash, $id, $origin, $userId]);
    return ['ok' => true, 'unblocked' => $blocked];
}

function tagsUnblock(PDO $db, string $hash, string $id): void
{
    $db->prepare("DELETE FROM hash_tag_blocks WHERE info_hash = ? AND tag = ?")->execute([strtolower($hash), $id]);
    factsRefresh($db, $hash);
}

function tagsDetach(PDO $db, string $hash, string $id, ?int $userId, bool $moderate): array
{
    $hash = strtolower($hash);
    if (!preg_match('/^[0-9a-f]{40}$/', $hash) || !tagsReady($db)) return ['ok' => false, 'error' => 'invalid'];
    $st = $db->prepare("SELECT origin, added_by FROM hash_tags WHERE info_hash = ? AND tag = ?");
    $st->execute([$hash, $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $mine = $userId !== null && (int)$row['added_by'] === $userId;
        if (!$moderate && !$mine) return ['ok' => false, 'error' => 'forbidden'];
        $db->prepare("DELETE FROM hash_tags WHERE info_hash = ? AND tag = ?")->execute([$hash, $id]);
        if (((int)$row['origin'] & TAG_ORIGIN_FORUM) && $moderate) {
            $db->prepare("INSERT IGNORE INTO hash_tag_blocks (info_hash, tag, blocked_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")->execute([$hash, $id, $userId]);
        }
        return ['ok' => true, 'removed' => 'explicit', 'others' => !$mine];
    }
    if (tagsIsExplicit($id) || !factsTagValid($id)) return ['ok' => false, 'error' => 'not_found'];
    if (!$moderate) return ['ok' => false, 'error' => 'forbidden'];
    $db->prepare("INSERT IGNORE INTO hash_tag_blocks (info_hash, tag, blocked_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")->execute([$hash, $id, $userId]);
    factsRefresh($db, $hash);
    return ['ok' => true, 'removed' => 'blocked'];
}

function tagsForHashes(PDO $db, array $hashes, ?int $viewerId, bool $moderate): array
{
    $hashes = array_values(array_unique(array_filter(array_map('strtolower', array_map('strval', $hashes)), fn($h) => preg_match('/^[0-9a-f]{40}$/', $h) === 1)));
    if (!$hashes || !tagsReady($db)) return [];
    $out = [];
    foreach (array_chunk($hashes, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT ht.info_hash, ht.tag, ht.origin, ht.added_by, t.name, t.color, t.status, t.hidden
                              FROM hash_tags ht LEFT JOIN tags t ON ht.tag LIKE 'x:%' AND t.slug = SUBSTRING(ht.tag, 3)
                             WHERE ht.info_hash IN ($in) ORDER BY ht.created_at, ht.tag");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (string)$r['tag'];
            $mine = $viewerId !== null && (int)$r['added_by'] === $viewerId;
            if (tagsIsExplicit($id)) {
                if ($r['status'] === null || $r['status'] === 'rejected' || (int)$r['hidden'] === 1) continue;
                if ($r['status'] === 'pending' && !$mine && !$moderate) continue;
                $chip = ['id' => $id, 'g' => 'x', 'l' => (string)$r['name']];
                if ($r['color']) $chip['c'] = (string)$r['color'];
                if ($r['status'] === 'pending') $chip['p'] = 1;
            } elseif (factsTagValid($id) && !str_starts_with($id, 'g:')) {
                $chip = ['id' => $id, 'g' => substr($id, 0, 1), 'l' => factsTagLabel($id)];
            } else {
                continue;
            }
            if ($mine) $chip['m'] = 1;
            $out[strtolower((string)$r['info_hash'])]['add'][] = $chip;
        }
    }
    foreach (tagsBlocked($db, $hashes) as $h => $ids) $out[$h]['blocks'] = $ids;
    return $out;
}

function tagsMergeChips(?array $facts, ?array $extra, array $cfg): ?array
{
    if (!$extra) return $facts;
    $blocks = array_flip($extra['blocks'] ?? []);
    $tags = [];
    $seen = [];
    foreach ($facts['tags'] ?? [] as $t) {
        if (isset($blocks[$t['id']]) || isset($seen[$t['id']])) continue;
        $seen[$t['id']] = true;
        $tags[] = $t;
    }
    foreach ($extra['add'] ?? [] as $t) {
        if (isset($seen[$t['id']])) {
            if (!empty($t['m'])) foreach ($tags as &$x) if ($x['id'] === $t['id']) $x['m'] = 1;
            unset($x);
            continue;
        }
        $seen[$t['id']] = true;
        $tags[] = $t;
    }
    if (!$tags && $facts === null) return null;
    $out = $facts ?? ['max' => factsChipsMax($cfg)];
    $out['tags'] = $tags;
    return $out;
}

function tagsFilterExplicit(PDO $db, array $ids, int $cap = TAGS_LIST_CAP): ?array
{
    if (!tagsReady($db)) return null;
    $ok = [];
    foreach ($ids as $id) {
        if (tagsIsExplicit($id)) {
            $row = tagsRow($db, $id);
            if ($row && $row['status'] === 'approved' && (int)$row['hidden'] === 0) $ok[] = $id;
        } elseif (factsTagValid($id)) {
            $ok[] = $id;
        }
    }
    if (!$ok) return ['hashes' => []];
    $in = implode(',', array_fill(0, count($ok), '?'));
    $sql = "SELECT DISTINCT info_hash FROM hash_tags WHERE tag IN ($in) LIMIT " . ($cap + 1);
    $st = $db->prepare($sql);
    $st->execute($ok);
    $hashes = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
    if (count($hashes) <= $cap) return ['hashes' => $hashes];
    return ['tpl' => "(SELECT 1 FROM hash_tags ht WHERE ht.info_hash = {T}.info_hash AND ht.tag IN ($in) LIMIT 1) IS NOT NULL", 'args' => $ok];
}

function tagsCatalog(PDO $db, int $limit = 300): array
{
    if (!tagsReady($db)) return [];
    $limit = max(1, (int)$limit);
    $st = $db->query("SELECT slug, name, color, uses FROM tags WHERE status = 'approved' AND hidden = 0 AND uses > 0 ORDER BY uses DESC, name LIMIT $limit");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = ['id' => 'x:' . $r['slug'], 'g' => 'x', 'l' => (string)$r['name'], 'a' => str_replace('-', ' ', (string)$r['slug']), 'n' => (int)$r['uses']];
        if ($r['color']) $c['c'] = (string)$r['color'];
        $out[] = $c;
    }
    return $out;
}

function tagsSearch(PDO $db, array $cfg, string $q, int $limit = 12): array
{
    $q = mb_strtolower(trim($q));
    if ($q === '') return [];
    $out = [];
    foreach (factsCatalog($cfg) as $c) {
        if ($c['g'] === 'x') continue;
        if (str_contains(mb_strtolower($c['l']), $q) || str_contains((string)($c['a'] ?? ''), $q)) $out[] = ['id' => $c['id'], 'g' => $c['g'], 'l' => $c['l']];
        if (count($out) >= $limit) return $out;
    }
    if (!tagsReady($db)) return $out;
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $limit = max(1, (int)$limit);
    $st = $db->prepare("SELECT slug, name, color FROM tags WHERE status = 'approved' AND hidden = 0 AND (name LIKE ? OR slug LIKE ?) ORDER BY uses DESC, name LIMIT $limit");
    $st->execute([$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = ['id' => 'x:' . $r['slug'], 'g' => 'x', 'l' => (string)$r['name']];
        if ($r['color']) $c['c'] = (string)$r['color'];
        $out[] = $c;
        if (count($out) >= $limit) break;
    }
    return $out;
}

function tagsHashChips(PDO $db, array $cfg, string $hash, ?int $viewerId, bool $moderate, bool $signedIn, bool $canContent, string $place = 'info'): ?array
{
    $hash = strtolower($hash);
    $desc = $canContent ? (factsChipsFor($db, [$hash], $signedIn)[$hash] ?? null) : null;
    $st = $db->prepare("SELECT name FROM index_catalog WHERE info_hash = ? UNION ALL SELECT name FROM whitelist WHERE info_hash = ? UNION ALL SELECT name FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash, $hash, $hash]);
    $name = (string)($st->fetchColumn() ?: '');
    if (!factsChipsOn($cfg, $place)) return null;
    return tagsMergeChips(factsRowChips($desc, $name, $cfg, $place), tagsForHashes($db, [$hash], $viewerId, $moderate)[$hash] ?? null, $cfg);
}

function tagsUsesTick(PDO $db): void
{
    if (!tagsReady($db)) return;
    $db->exec("DELETE ht FROM hash_tags ht LEFT JOIN index_hashes h ON h.info_hash = ht.info_hash LEFT JOIN whitelist w ON w.info_hash = ht.info_hash
               WHERE h.info_hash IS NULL AND w.info_hash IS NULL");
    $db->exec("UPDATE tags t LEFT JOIN (SELECT SUBSTRING(tag, 3) AS slug, COUNT(*) AS n FROM hash_tags WHERE tag LIKE 'x:%' GROUP BY tag) c ON c.slug = t.slug
               SET t.uses = COALESCE(c.n, 0)");
}

function tagsAdminList(PDO $db, array $q): array
{
    if (!tagsReady($db)) return ['rows' => [], 'total' => 0, 'pending' => 0];
    $where = [];
    $params = [];
    $status = (string)($q['status'] ?? '');
    if (in_array($status, ['approved', 'pending', 'rejected'], true)) { $where[] = 't.status = ?'; $params[] = $status; }
    if ($status === 'hidden') $where[] = 't.hidden = 1';
    $search = trim((string)($q['q'] ?? ''));
    if ($search !== '') { $where[] = '(t.name LIKE ? OR t.slug LIKE ?)'; $like = '%' . addcslashes($search, '%_\\') . '%'; $params[] = $like; $params[] = $like; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $orderClause = ['uses' => 't.uses DESC, t.name', 'name' => 't.name', 'new' => 't.created_at DESC, t.id DESC'][(string)($q['sort'] ?? 'uses')] ?? 't.uses DESC, t.name';
    $perPage = max(10, min(200, (int)($q['per_page'] ?? 50)));
    $page = max(1, (int)($q['page'] ?? 1));
    $st = $db->prepare("SELECT COUNT(*) FROM tags t $whereClause");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $limit = (int)$perPage;
    $offset = ($page - 1) * $perPage;
    $st = $db->prepare("SELECT t.id, t.slug, t.name, t.color, t.description, t.status, t.hidden, t.origin, t.uses, t.created_at, u.username AS created_by_name
                          FROM tags t LEFT JOIN users u ON u.id = t.created_by $whereClause ORDER BY $orderClause LIMIT $limit OFFSET $offset");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['hidden'] = (int)$r['hidden']; $r['uses'] = (int)$r['uses']; $r['tag'] = 'x:' . $r['slug']; }
    unset($r);
    $pending = (int)$db->query("SELECT COUNT(*) FROM tags WHERE status = 'pending'")->fetchColumn();
    return ['rows' => $rows, 'total' => $total, 'pending' => $pending, 'page' => $page, 'pages' => max(1, (int)ceil($total / $perPage))];
}

function tagsAdminAction(PDO $db, array $cfg, string $op, array $in, bool $manage): array
{
    if (!tagsReady($db)) return ['ok' => false, 'error' => 'unavailable'];
    $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [$in['id'] ?? 0])), fn($v) => $v > 0));
    if (!$ids) return ['ok' => false, 'error' => 'invalid'];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    if ($op === 'approve' || $op === 'reject') {
        $st = $db->prepare("UPDATE tags SET status = ? WHERE id IN ($ph)");
        $st->execute(array_merge([$op === 'approve' ? 'approved' : 'rejected'], $ids));
        if ($op === 'reject') {
            $st = $db->prepare("SELECT CONCAT('x:', slug) FROM tags WHERE id IN ($ph)");
            $st->execute($ids);
            $tagIds = $st->fetchAll(PDO::FETCH_COLUMN);
            $qs = implode(',', array_fill(0, count($tagIds), '?'));
            if ($tagIds) $db->prepare("DELETE FROM hash_tags WHERE tag IN ($qs)")->execute($tagIds);
        }
        return ['ok' => true, 'n' => count($ids)];
    }
    if (!$manage) return ['ok' => false, 'error' => 'forbidden'];
    if ($op === 'hide' || $op === 'unhide') {
        $db->prepare("UPDATE tags SET hidden = ? WHERE id IN ($ph)")->execute(array_merge([$op === 'hide' ? 1 : 0], $ids));
        return ['ok' => true, 'n' => count($ids)];
    }
    if ($op === 'save') {
        $name = tagsName((string)($in['name'] ?? ''), 40);
        if ($name === null) return ['ok' => false, 'error' => 'name'];
        $desc = trim(mb_substr((string)($in['description'] ?? ''), 0, 200));
        $db->prepare("UPDATE tags SET name = ?, color = ?, description = ? WHERE id = ?")
           ->execute([$name, tagsColor($in['color'] ?? null), $desc !== '' ? $desc : null, $ids[0]]);
        return ['ok' => true, 'n' => 1];
    }
    if ($op === 'delete' || $op === 'merge') {
        $target = null;
        if ($op === 'merge') {
            $st = $db->prepare("SELECT slug FROM tags WHERE id = ?");
            $st->execute([(int)($in['into'] ?? 0)]);
            $target = $st->fetchColumn();
            if (!$target || in_array((int)($in['into'] ?? 0), $ids, true)) return ['ok' => false, 'error' => 'target'];
        }
        $st = $db->prepare("SELECT CONCAT('x:', slug) FROM tags WHERE id IN ($ph)");
        $st->execute($ids);
        $tagIds = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($tagIds) {
            $qs = implode(',', array_fill(0, count($tagIds), '?'));
            if ($target) {
                $db->prepare("INSERT IGNORE INTO hash_tags (info_hash, tag, origin, added_by, created_at)
                              SELECT info_hash, ?, origin, added_by, created_at FROM hash_tags WHERE tag IN ($qs)")->execute(array_merge(['x:' . $target], $tagIds));
            }
            $db->prepare("DELETE FROM hash_tags WHERE tag IN ($qs)")->execute($tagIds);
            $db->prepare("DELETE FROM hash_tag_blocks WHERE tag IN ($qs)")->execute($tagIds);
        }
        $db->prepare("DELETE FROM tags WHERE id IN ($ph)")->execute($ids);
        tagsUsesTick($db);
        return ['ok' => true, 'n' => count($ids)];
    }
    return ['ok' => false, 'error' => 'invalid'];
}
