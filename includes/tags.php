<?php

const TAG_ORIGIN_MEMBER = 1;
const TAG_ORIGIN_MODERATOR = 2;
const TAG_ORIGIN_FORUM = 4;
const TAGS_LIST_CAP = 10000;
const TAGS_FORUM_MAX = 30;
const TAGS_FORUM_GENRES = ['animowane' => 'animacja', 'animowany' => 'animacja', 'fantastyka-naukowa' => 'sci-fi', 'dreszczowce' => 'thriller'];

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
    $genres = null;
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
            } elseif (factsTagValid($id)) {
                $chip = ['id' => $id, 'g' => substr($id, 0, 1), 'l' => str_starts_with($id, 'g:') ? tagsGenreLabel($id, $genres ??= tagsGenreLabels($db)) : factsTagLabel($id)];
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
    if (tagsForumReady($db)) {
        $db->exec("DELETE hf FROM hash_forum_tags hf LEFT JOIN whitelist w ON w.info_hash = hf.info_hash LEFT JOIN api_clients c ON c.id = hf.client_id
                   WHERE w.info_hash IS NULL OR c.id IS NULL");
        $db->exec("DELETE m FROM tag_forum_map m LEFT JOIN api_clients c ON c.id = m.client_id WHERE c.id IS NULL");
    }
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

function tagsForumOn(array $cfg): bool
{
    return tagsEnabled($cfg) && ($cfg['tags_forum'] ?? '1') === '1';
}

function tagsForumReady(PDO $db): bool
{
    static $ready = false;
    if ($ready) return true;
    try {
        $ready = (bool)$db->query("SHOW TABLES LIKE 'hash_forum_tags'")->fetchColumn()
              && (bool)$db->query("SHOW COLUMNS FROM api_clients LIKE 'tags_import'")->fetchColumn();
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function tagsForumList($raw): ?array
{
    if (!is_array($raw)) return null;
    $clean = static fn($v): string => is_string($v) ? trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $v)) : '';
    $out = [];
    foreach ($raw as $t) {
        if (count($out) >= TAGS_FORUM_MAX) break;
        if (!is_array($t)) continue;
        $slug = $clean($t['slug'] ?? null);
        if ($slug === '' || mb_strlen($slug) > 100 || isset($out[$slug])) continue;
        $parent = $clean($t['parent'] ?? null);
        $out[$slug] = [
            'slug'   => $slug,
            'name'   => tagsName(is_string($t['name'] ?? null) ? $t['name'] : '', 100) ?? $slug,
            'color'  => tagsColor(is_string($t['color'] ?? null) ? $t['color'] : null),
            'parent' => $parent !== '' && mb_strlen($parent) <= 100 ? $parent : null,
        ];
    }
    return array_values($out);
}

function tagsGenreLabels(PDO $db): array
{
    try {
        $st = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'facts_counts'");
        $st->execute();
        $c = json_decode((string)($st->fetchColumn() ?: ''), true);
    } catch (\Throwable $e) {
        return [];
    }
    return is_array($c['g'] ?? null) ? $c['g'] : [];
}

function tagsGenreLabel(string $id, array $genres): string
{
    $slug = substr($id, 2);
    if (isset($genres[$slug])) return (string)$genres[$slug];
    $s = str_replace('-', ' ', $slug);
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

function tagsForumAuto(PDO $db, string $name, string $slug): ?string
{
    $parts = array_values(array_filter(array_map('trim', preg_split('~[/()|,+]~u', $name) ?: []), fn($p) => $p !== ''));
    foreach (array_merge([$name, $slug], $parts) as $c) {
        if (($id = tagsAutoMatch($c)) !== null) return $id;
        $s = tagsSlug($c);
        if ($s === '') continue;
        foreach (FACTS_LABELS as $id => $label) {
            foreach (explode('/', $label) as $piece) {
                if (tagsSlug($piece) === $s) return $id;
            }
        }
    }
    $genres = tagsGenreLabels($db);
    $s = tagsSlug($name) ?: tagsSlug($slug);
    if ($s === '' || !$genres) return null;
    $alias = TAGS_FORUM_GENRES[$s] ?? null;
    if ($alias !== null && isset($genres[$alias])) return 'g:' . $alias;
    if (isset($genres[$s])) return factsTagValid('g:' . $s) ? 'g:' . $s : null;
    $best = null;
    $score = 0;
    foreach (array_keys($genres) as $g) {
        $g = (string)$g;
        $cp = strspn($s ^ $g, "\0");
        if ($cp < 4 || $cp < strlen($g) - 2 || $cp * 100 - strlen($g) <= $score) continue;
        $best = $g;
        $score = $cp * 100 - strlen($g);
    }
    return $best !== null && factsTagValid('g:' . $best) ? 'g:' . $best : null;
}

function tagsForumTarget(PDO $db, string $name, string $slug): string
{
    $auto = tagsForumAuto($db, $name, $slug);
    if ($auto !== null) return $auto;
    $s = tagsSlug($name) ?: tagsSlug($slug);
    return 'x:' . ($s !== '' ? $s : substr(sha1($slug), 0, 12));
}

function tagsForumEnsure(PDO $db, string $target, string $name, ?string $color): ?string
{
    if (!factsTagValid($target)) return null;
    if (!tagsIsExplicit($target)) return $target;
    $row = tagsRow($db, $target);
    if ($row && $row['status'] === 'rejected') return null;
    if ($row) {
        if ($row['status'] === 'pending') $db->prepare("UPDATE tags SET status = 'approved' WHERE id = ?")->execute([(int)$row['id']]);
        return $target;
    }
    $label = tagsName(mb_substr($name, 0, 40), 40) ?? substr($target, 2);
    $db->prepare("INSERT IGNORE INTO tags (slug, name, color, status, origin, created_by, created_at) VALUES (?, ?, ?, 'approved', 'forum', NULL, UTC_TIMESTAMP())")
       ->execute([substr($target, 2), mb_substr($label, 0, 40), tagsColor($color)]);
    return tagsRow($db, $target) ? $target : null;
}

function tagsForumSet(PDO $db, array $cfg, int $clientId, string $hash, array $tags): void
{
    if (!tagsForumReady($db)) return;
    $hash = strtolower($hash);
    $slugs = array_values(array_unique(array_map(fn($t) => (string)$t['slug'], $tags)));
    $in = implode(',', array_fill(0, max(1, count($slugs)), '?'));
    if ($slugs) {
        $st = $db->prepare("SELECT forum_slug FROM tag_forum_map WHERE client_id = ? AND forum_slug IN ($in)");
        $st->execute(array_merge([$clientId], $slugs));
        $known = array_flip(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
        $st = $db->prepare("SELECT tags_import FROM api_clients WHERE id = ?");
        $st->execute([$clientId]);
        $auto = (int)$st->fetchColumn() === 1 && ($cfg['tags_forum_review'] ?? 'bypass') !== 'queue';
        $seen = $db->prepare("UPDATE tag_forum_map SET name = ?, color = ?, parent = ?, seen_at = UTC_TIMESTAMP() WHERE client_id = ? AND forum_slug = ?");
        $add = $db->prepare("INSERT IGNORE INTO tag_forum_map (client_id, forum_slug, name, color, parent, target, state, seen_at, decided_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), IF(? = 'approved', UTC_TIMESTAMP(), NULL))");
        foreach ($tags as $t) {
            if (isset($known[$t['slug']])) {
                $seen->execute([$t['name'], $t['color'], $t['parent'], $clientId, $t['slug']]);
                continue;
            }
            $target = tagsForumTarget($db, $t['name'], $t['slug']);
            $state = $auto && tagsForumEnsure($db, $target, $t['name'], $t['color']) !== null ? 'approved' : 'proposed';
            $add->execute([$clientId, $t['slug'], $t['name'], $t['color'], $t['parent'], $target, $state, $state]);
        }
    }
    $where = $slugs ? "AND forum_slug NOT IN ($in)" : '';
    $db->prepare("DELETE FROM hash_forum_tags WHERE info_hash = ? AND client_id = ? $where")->execute(array_merge([$hash, $clientId], $slugs));
    $put = $db->prepare("INSERT IGNORE INTO hash_forum_tags (info_hash, client_id, forum_slug) VALUES (?, ?, ?)");
    foreach ($slugs as $s) $put->execute([$hash, $clientId, $s]);
    tagsForumReconcile($db, $cfg, $hash);
}

function tagsForumClear(PDO $db, int $clientId, string $hash): void
{
    if (!tagsForumReady($db)) return;
    $hash = strtolower($hash);
    $db->prepare("DELETE FROM hash_forum_tags WHERE info_hash = ? AND client_id = ?")->execute([$hash, $clientId]);
    tagsForumReconcile($db, [], $hash);
}

function tagsForumReconcile(PDO $db, array $cfg, ?string $hash = null, int $limit = 2000): array
{
    $done = ['added' => 0, 'removed' => 0];
    if (!tagsReady($db) || !tagsForumReady($db)) return $done;
    $limit = max(1, $limit);
    $args = $hash !== null ? [strtolower($hash)] : [];
    $on = tagsForumOn($cfg);
    $where = $hash !== null ? 'AND ht.info_hash = ?' : '';
    $sql = $on
        ? "SELECT ht.info_hash, ht.tag, ht.origin FROM hash_tags ht
            WHERE (ht.origin & 4) <> 0 $where
              AND NOT EXISTS (SELECT 1 FROM hash_forum_tags hf
                                JOIN tag_forum_map m ON m.client_id = hf.client_id AND m.forum_slug = hf.forum_slug AND m.state = 'approved' AND m.target = ht.tag
                                JOIN api_clients c ON c.id = hf.client_id AND c.tags_import = 1
                               WHERE hf.info_hash = ht.info_hash)
            LIMIT $limit"
        : "SELECT ht.info_hash, ht.tag, ht.origin FROM hash_tags ht WHERE (ht.origin & 4) <> 0 $where LIMIT $limit";
    $st = $db->prepare($sql);
    $st->execute($args);
    $drop = $db->prepare("DELETE FROM hash_tags WHERE info_hash = ? AND tag = ? AND origin = 4");
    $keep = $db->prepare("UPDATE hash_tags SET origin = origin & ~4 WHERE info_hash = ? AND tag = ?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int)$r['origin'] === TAG_ORIGIN_FORUM) $drop->execute([$r['info_hash'], $r['tag']]);
        if ((int)$r['origin'] !== TAG_ORIGIN_FORUM || $drop->rowCount() === 0) $keep->execute([$r['info_hash'], $r['tag']]);
        $done['removed']++;
    }
    if (!$on) return $done;
    $where = $hash !== null ? 'AND hf.info_hash = ?' : '';
    $st = $db->prepare("SELECT DISTINCT hf.info_hash, m.target FROM hash_forum_tags hf
                          JOIN tag_forum_map m ON m.client_id = hf.client_id AND m.forum_slug = hf.forum_slug AND m.state = 'approved' AND m.target IS NOT NULL
                          JOIN api_clients c ON c.id = hf.client_id AND c.tags_import = 1
                          LEFT JOIN hash_tags ht ON ht.info_hash = hf.info_hash AND ht.tag = m.target
                          LEFT JOIN hash_tag_blocks b ON b.info_hash = hf.info_hash AND b.tag = m.target
                          LEFT JOIN tags t ON m.target LIKE 'x:%' AND t.slug = SUBSTRING(m.target, 3)
                         WHERE (ht.info_hash IS NULL OR (ht.origin & 4) = 0) AND b.info_hash IS NULL
                           AND (m.target NOT LIKE 'x:%' OR (t.id IS NOT NULL AND t.status <> 'rejected' AND t.hidden = 0)) $where
                         LIMIT $limit");
    $st->execute($args);
    $put = $db->prepare("INSERT INTO hash_tags (info_hash, tag, origin, added_by, created_at) VALUES (?, ?, 4, NULL, UTC_TIMESTAMP())
                         ON DUPLICATE KEY UPDATE origin = origin | 4");
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$h, $tag]) {
        if (!factsTagValid((string)$tag)) continue;
        $put->execute([$h, $tag]);
        $done['added']++;
    }
    return $done;
}

function tagsForumLabel(PDO $db, string $target, string $name, array $genres): array
{
    if (tagsIsExplicit($target)) {
        $row = tagsRow($db, $target);
        return $row ? ['l' => (string)$row['name'], 'g' => 'x', 'new' => false, 'st' => (string)$row['status'], 'c' => $row['color']] : ['l' => $name, 'g' => 'x', 'new' => true];
    }
    if (str_starts_with($target, 'g:')) return ['l' => tagsGenreLabel($target, $genres), 'g' => 'g', 'new' => false];
    return ['l' => factsTagLabel($target), 'g' => substr($target, 0, 1), 'new' => false];
}

function tagsForumTargets(PDO $db, array $cfg): array
{
    $out = [];
    $seen = [];
    foreach (factsCatalog($cfg) as $c) {
        $out[] = ['id' => $c['id'], 'g' => $c['g'], 'l' => $c['l']];
        $seen[$c['id']] = true;
    }
    foreach (tagsGenreLabels($db) as $slug => $label) {
        if (isset($seen['g:' . $slug]) || !factsTagValid('g:' . $slug)) continue;
        $out[] = ['id' => 'g:' . $slug, 'g' => 'g', 'l' => mb_substr((string)$label, 0, 40)];
    }
    foreach ($db->query("SELECT slug, name FROM tags WHERE status <> 'rejected' AND hidden = 0 ORDER BY name LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => 'x:' . $r['slug'], 'g' => 'x', 'l' => (string)$r['name']];
    }
    return $out;
}

function tagsForumAdmin(PDO $db, array $cfg): array
{
    $base = ['on' => tagsForumOn($cfg), 'review' => ($cfg['tags_forum_review'] ?? 'bypass') === 'queue' ? 'queue' : 'bypass', 'clients' => [], 'targets' => []];
    if (!tagsReady($db) || !tagsForumReady($db)) return $base;
    $clients = [];
    foreach ($db->query("SELECT id, label, tags_import FROM api_clients ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $clients[(int)$c['id']] = ['id' => (int)$c['id'], 'label' => (string)$c['label'], 'on' => (int)$c['tags_import'] === 1, 'proposed' => 0, 'rows' => []];
    }
    $genres = tagsGenreLabels($db);
    $st = $db->query("SELECT m.client_id, m.forum_slug, m.name, m.color, m.parent, m.target, m.state,
                             (SELECT COUNT(*) FROM hash_forum_tags hf WHERE hf.client_id = m.client_id AND hf.forum_slug = m.forum_slug) AS n
                        FROM tag_forum_map m ORDER BY m.client_id, n DESC, m.name");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['client_id'];
        if (!isset($clients[$cid])) continue;
        $clients[$cid]['rows'][] = ['slug' => (string)$r['forum_slug'], 'name' => (string)$r['name'], 'color' => $r['color'], 'parent' => $r['parent'],
                                    'target' => (string)$r['target'], 'state' => (string)$r['state'], 'n' => (int)$r['n'],
                                    'to' => tagsForumLabel($db, (string)$r['target'], (string)$r['name'], $genres)];
        if ($r['state'] === 'proposed') $clients[$cid]['proposed']++;
    }
    $base['clients'] = array_values(array_filter($clients, fn($c) => $c['rows'] || $c['on']));
    $base['targets'] = tagsForumTargets($db, $cfg);
    return $base;
}

function tagsForumDecide(PDO $db, array $cfg, string $op, array $input): array
{
    if (!tagsReady($db) || !tagsForumReady($db)) return ['ok' => false, 'error' => 'unavailable'];
    $cid = (int)($input['client'] ?? 0);
    $st = $db->prepare("SELECT 1 FROM api_clients WHERE id = ?");
    $st->execute([$cid]);
    if ($cid < 1 || !$st->fetchColumn()) return ['ok' => false, 'error' => 'invalid'];
    if ($op === 'forum_import') {
        $db->prepare("UPDATE api_clients SET tags_import = ? WHERE id = ?")->execute([!empty($input['on']) ? 1 : 0, $cid]);
        $done = tagsForumReconcile($db, $cfg, null, 50000);
        tagsUsesTick($db);
        return ['ok' => true, 'n' => 1] + $done;
    }
    if ($op === 'forum_all') {
        $st = $db->prepare("SELECT * FROM tag_forum_map WHERE client_id = ? AND state = 'proposed' ORDER BY forum_slug");
        $st->execute([$cid]);
    } else {
        $slugs = array_values(array_unique(array_filter(array_map(fn($s) => is_string($s) ? $s : '', (array)($input['slugs'] ?? [$input['slug'] ?? ''])), fn($s) => $s !== '')));
        if (!$slugs || count($slugs) > 500) return ['ok' => false, 'error' => 'invalid'];
        $in = implode(',', array_fill(0, count($slugs), '?'));
        $st = $db->prepare("SELECT * FROM tag_forum_map WHERE client_id = ? AND forum_slug IN ($in)");
        $st->execute(array_merge([$cid], $slugs));
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows && $op !== 'forum_all') return ['ok' => false, 'error' => 'invalid'];
    $set = $db->prepare("UPDATE tag_forum_map SET target = ?, state = ?, decided_at = UTC_TIMESTAMP() WHERE client_id = ? AND forum_slug = ?");
    $n = 0;
    foreach ($rows as $r) {
        $target = (string)$r['target'];
        if ($op === 'forum_target') {
            $target = is_string($input['target'] ?? null) ? $input['target'] : '';
            if ($target === 'new') {
                $s = tagsSlug((string)$r['name']) ?: tagsSlug((string)$r['forum_slug']);
                $target = $s !== '' ? 'x:' . $s : '';
            }
        }
        if ($op === 'forum_ignore') {
            $set->execute([$target, 'ignored', $cid, $r['forum_slug']]);
        } else {
            $ok = tagsForumEnsure($db, $target, (string)$r['name'], $r['color'] !== null ? (string)$r['color'] : null);
            if ($ok === null) return ['ok' => false, 'error' => tagsIsExplicit($target) ? 'rejected' : 'unknown'];
            $set->execute([$ok, 'approved', $cid, $r['forum_slug']]);
        }
        $n++;
    }
    $done = tagsForumReconcile($db, $cfg, null, 50000);
    tagsUsesTick($db);
    return ['ok' => true, 'n' => $n] + $done;
}
