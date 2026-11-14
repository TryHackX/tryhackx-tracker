<?php

const TAG_ORIGIN_MEMBER = 1;
const TAG_ORIGIN_MODERATOR = 2;
const TAG_ORIGIN_FORUM = 4;
const TAGS_LIST_CAP = 10000;
const TAGS_FORUM_MAX = 30;
const TAGS_FORUM_GENRES = ['animowane' => 'animacja', 'animowany' => 'animacja', 'fantastyka-naukowa' => 'sci-fi', 'dreszczowce' => 'thriller'];
const TAGS_BASE = [
    'extended'         => ['Extended', '#ffd54f', 'extended|extended cut|extended edition|wersja rozszerzona|rozszerzona wersja'],
    'director-s-cut'   => ["Director's Cut", '#ffd54f', "director's cut|directors cut|director cut|wersja reżyserska"],
    'uncut'            => ['Uncut', '#ffd54f', 'uncut|unrated|bez cięć|wersja nieocenzurowana'],
    'remaster'         => ['Remaster', '#ffd54f', 'remaster|remastered|zremasterowana|zremasterowany|4k remaster|restored|odrestaurowana'],
    'imax'             => ['IMAX', '#ffd54f', 'imax'],
    'wersja-kinowa'    => ['Wersja kinowa', '#ffd54f', 'theatrical cut|theatrical|wersja kinowa'],
    'kolekcja'         => ['Kolekcja', '#ffd54f', 'collection|kolekcja|box set|boxset|trylogia|trilogy|quadrilogy|duology|dylogia|tetralogy|tetralogia|pentalogy|pentalogia|hexalogy|heksalogia|saga|pakiet filmów'],
    'komplet-sezonow'  => ['Komplet sezonów', '#ffd54f', 'complete series|all seasons|komplet sezonów|wszystkie sezony|the complete series|kompletny serial|cały serial|sezony 1'],
    '3d'               => ['3D', '#90caf9', '3d|h-sbs|hsbs|half-sbs|half sbs|half-ou|anaglyph|anaglif'],
    'upscale-ai'       => ['Upscale AI', '#90caf9', 'upscale|upscaled|ai upscale|upscaling|ai enhanced|topaz'],
    'czarno-bialy'     => ['Czarno-biały', '#90caf9', 'black and white|black & white|czarno-biały|czarno-biała|b&w'],
    'koloryzacja'      => ['Koloryzacja', '#90caf9', 'colorized|colourized|koloryzowana|koloryzowany|koloryzacja'],
    'lektor-ivo'       => ['Lektor IVO', '#80deea', 'ivo|ivona|lektor ivo|lektor ivona'],
    'lektor-ai'        => ['Lektor AI', '#80deea', 'lektor ai|ai lektor|lektor syntetyczny|lektor maszynowy|syntezator mowy'],
    'dubbing-ai'       => ['Dubbing AI', '#80deea', 'dubbing ai|ai dubbing|dubbing syntetyczny'],
    'lektor-amatorski' => ['Lektor amatorski', '#80deea', 'lektor amatorski|lektor ama|amatorski lektor'],
    'napisy-wtopione'  => ['Napisy wtopione', '#80deea', 'hardsub|hardsubs|hardsubbed|hardcoded subs|hc subs|napisy wtopione|wtopione napisy|wtopione'],
    'napisy-sdh'       => ['Napisy SDH', '#80deea', 'sdh|dla niesłyszących|napisy dla niesłyszących|closed captions'],
    'audiodeskrypcja'  => ['Audiodeskrypcja', '#80deea', 'audiodeskrypcja|audio description|audiodescription'],
    'polska-produkcja' => ['Polska produkcja', '#c5e1a5', 'polska produkcja|produkcja polska|produkcja: polska|kraj: polska|kraj produkcji: polska'],
    'klasyka'          => ['Klasyka', '#c5e1a5', 'klasyka|klasyka kina|classic'],
    'koncert'          => ['Koncert', '#c5e1a5', 'koncert|concert|live at|live in|unplugged|na żywo'],
    'kabaret'          => ['Kabaret', '#c5e1a5', 'kabaret|kabareton'],
    'stand-up'         => ['Stand-up', '#c5e1a5', 'stand-up|standup|stand up comedy'],
    'sport'            => ['Sport', '#c5e1a5', 'ufc|formula 1|formuła 1|wrc|nba|nfl|nhl|mecz|liga mistrzów|champions league|ekstraklasa|premier league|bundesliga|la liga|mma|ksw|boks|boxing|motogp|tour de france|igrzyska|olympics|mundial|world cup'],
    'gra'              => ['Gra', '#b0bec5', 'repack|fitgirl|dodi|elamigos|gog|pc game|gra pc|codex|skidrow|plaza|empress|tenoke|razor1911|flt|goldberg|steamrip'],
    'program'          => ['Program', '#b0bec5', 'software|portable|oprogramowanie|x64|x86|installer|multilingual'],
    'muzyka'           => ['Muzyka', '#b0bec5', 'discography|dyskografia|album|soundtrack|ost|320kbps|320 kbps|mp3 320|vinyl|winyl|24bit|24-bit|hi-res|lossless'],
    'audiobook'        => ['Audiobook', '#b0bec5', 'audiobook|audiobooki|słuchowisko|czyta|czytają|m4b'],
    'e-book'           => ['E-book', '#b0bec5', 'ebook|e-book|epub|mobi|azw3|azw|fb2|ebooki|e-booki'],
    'netflix'          => ['Netflix', '#ef9a9a', 'netflix|nf'],
    'amazon-prime'     => ['Amazon Prime', '#ffcc80', 'amazon prime|prime video|amazon|amzn'],
    'disney'           => ['Disney+', '#90caf9', 'disney+|disney plus|dsnp'],
    'hbo-max'          => ['HBO Max', '#b39ddb', 'hbo max|hbomax|hmax|hbo'],
    'apple-tv'         => ['Apple TV+', '#cfd8dc', 'apple tv+|apple tv plus|apple tv|atvp'],
    'skyshowtime'      => ['SkyShowtime', '#80cbc4', 'skyshowtime|skst'],
    'canal'            => ['Canal+', '#b0bec5', 'canal+|canal plus|cnlp'],
];

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
              'target' => __('api.tags.target'), 'fav_limit' => __('api.tags.fav_limit'), 'exists' => __('api.tags.cat_exists')];
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

function tagsAttach(PDO $db, array $cfg, string $hash, string $id, ?int $userId, int $origin, bool $moderate, bool $pending = false): array
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
    if (!tagsPendingCol($db)) {
        $db->prepare("INSERT INTO hash_tags (info_hash, tag, origin, added_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
                      ON DUPLICATE KEY UPDATE origin = origin | VALUES(origin), added_by = COALESCE(added_by, VALUES(added_by))")
           ->execute([$hash, $id, $origin, $userId]);
        return ['ok' => true, 'unblocked' => $blocked, 'pending' => false];
    }
    $db->prepare("INSERT INTO hash_tags (info_hash, tag, origin, added_by, created_at, pending) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)
                  ON DUPLICATE KEY UPDATE origin = origin | VALUES(origin), added_by = COALESCE(added_by, VALUES(added_by)), pending = LEAST(pending, VALUES(pending))")
       ->execute([$hash, $id, $origin, $userId, $pending ? 1 : 0]);
    $st = $db->prepare("SELECT pending FROM hash_tags WHERE info_hash = ? AND tag = ?");
    $st->execute([$hash, $id]);
    return ['ok' => true, 'unblocked' => $blocked, 'pending' => (int)$st->fetchColumn() === 1];
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

function tagsPrune(PDO $db, array $cfg, ?string $id = null): int
{
    if (($cfg['tags_prune_unused'] ?? '1') !== '1' || !tagsReady($db)) return 0;
    if ($id !== null && !tagsIsExplicit($id)) return 0;
    $base = array_keys(TAGS_BASE);
    $where = ["t.status IN ('approved','pending')", 't.hidden = 0', "t.slug NOT IN (" . implode(',', array_fill(0, count($base), '?')) . ")",
              "NOT EXISTS (SELECT 1 FROM hash_tags h WHERE h.tag = CONCAT('x:', t.slug))"];
    $args = $base;
    if (tagsForumReady($db)) $where[] = "NOT EXISTS (SELECT 1 FROM tag_forum_map m WHERE m.target = CONCAT('x:', t.slug))";
    if (tagsCatReady($db)) $where[] = 't.category_id IS NULL';
    if ($id !== null) {
        $where[] = 't.slug = ?';
        $args[] = substr($id, 2);
    } else {
        $where[] = 't.created_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE';
    }
    $st = $db->prepare("SELECT t.id, t.slug FROM tags t WHERE " . implode(' AND ', $where) . " LIMIT 500");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    $ids = array_map('intval', array_column($rows, 'id'));
    $slugs = array_map('strval', array_column($rows, 'slug'));
    $tagIds = array_map(fn($s) => 'x:' . $s, $slugs);
    $q = implode(',', array_fill(0, count($ids), '?'));
    if (tagsFavReady($db)) $db->prepare("DELETE FROM tag_favourites WHERE tag IN ($q)")->execute($tagIds);
    $db->prepare("DELETE FROM hash_tag_blocks WHERE tag IN ($q)")->execute($tagIds);
    if (tagsCatReady($db)) $db->prepare("DELETE FROM tag_cat_suggestions WHERE tag_slug IN ($q) AND status = 'open'")->execute($slugs);
    $db->prepare("DELETE FROM tags WHERE id IN ($q)")->execute($ids);
    return count($ids);
}

function tagsCount(PDO $db, string $id): void
{
    if (!tagsIsExplicit($id) || !tagsReady($db)) return;
    $clause = tagsPendingCol($db) ? ' AND pending = 0' : '';
    $db->prepare("UPDATE tags SET uses = (SELECT COUNT(*) FROM hash_tags WHERE tag = ?$clause) WHERE slug = ?")->execute([$id, substr($id, 2)]);
}

function tagsForHashes(PDO $db, array $hashes, ?int $viewerId, bool $moderate): array
{
    $hashes = array_values(array_unique(array_filter(array_map('strtolower', array_map('strval', $hashes)), fn($h) => preg_match('/^[0-9a-f]{40}$/', $h) === 1)));
    if (!$hashes || !tagsReady($db)) return [];
    $out = [];
    $genres = null;
    $sel = tagsPendingCol($db) ? 'ht.pending' : '0 AS pending';
    foreach (array_chunk($hashes, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT ht.info_hash, ht.tag, ht.origin, ht.added_by, $sel, t.name, t.color, t.status, t.hidden
                              FROM hash_tags ht LEFT JOIN tags t ON ht.tag LIKE 'x:%' AND t.slug = SUBSTRING(ht.tag, 3)
                             WHERE ht.info_hash IN ($in) ORDER BY ht.created_at, ht.tag");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (string)$r['tag'];
            $mine = $viewerId !== null && (int)$r['added_by'] === $viewerId;
            if ((int)$r['pending'] === 1 && !$mine && !$moderate) continue;
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
            if ((int)$r['pending'] === 1) $chip['p'] = 1;
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
    $live = tagsPendingCol($db);
    $clause = $live ? ' AND pending = 0' : '';
    $sql = "SELECT DISTINCT info_hash FROM hash_tags WHERE tag IN ($in)$clause LIMIT " . ($cap + 1);
    $st = $db->prepare($sql);
    $st->execute($ok);
    $hashes = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
    if (count($hashes) <= $cap) return ['hashes' => $hashes];
    return ['tpl' => "(SELECT 1 FROM hash_tags ht WHERE ht.info_hash = {T}.info_hash AND ht.tag IN ($in)" . ($live ? ' AND ht.pending = 0' : '') . " LIMIT 1) IS NOT NULL", 'args' => $ok];
}

function tagsCatalog(PDO $db, int $limit = 300): array
{
    if (!tagsReady($db)) return [];
    $limit = max(1, (int)$limit);
    $st = $db->query(tagsGroupReady($db)
        ? "SELECT t.slug, t.name, t.color, t.uses, t.origin, c.slug AS k, c.name AS kn, c.grp AS kg, c.sort AS ko, c.origin AS kor,
                  gg.slug AS p, gg.name AS pn, gg.sort AS po, gg.pinned AS pp
             FROM tags t LEFT JOIN tag_categories c ON c.id = t.category_id AND c.status = 'approved' LEFT JOIN tag_cat_groups gg ON gg.id = c.group_id
            WHERE t.status = 'approved' AND t.hidden = 0 AND t.uses > 0 ORDER BY t.uses DESC, t.name LIMIT $limit"
        : (tagsCatReady($db)
        ? "SELECT t.slug, t.name, t.color, t.uses, t.origin, c.slug AS k, c.name AS kn, c.grp AS kg, c.sort AS ko, c.origin AS kor
             FROM tags t LEFT JOIN tag_categories c ON c.id = t.category_id AND c.status = 'approved'
            WHERE t.status = 'approved' AND t.hidden = 0 AND t.uses > 0 ORDER BY t.uses DESC, t.name LIMIT $limit"
        : "SELECT slug, name, color, uses, origin FROM tags WHERE status = 'approved' AND hidden = 0 AND uses > 0 ORDER BY uses DESC, name LIMIT $limit"));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = ['id' => 'x:' . $r['slug'], 'g' => 'x', 'l' => (string)$r['name'], 'a' => str_replace('-', ' ', (string)$r['slug']), 'n' => (int)$r['uses']];
        if ($r['color']) $c['c'] = (string)$r['color'];
        if (!empty($r['k'])) {
            $c['k'] = (string)$r['k'];
            $c['kl'] = tagsCatLabel(['slug' => $r['k'], 'name' => $r['kn'], 'grp' => $r['kg'], 'origin' => $r['kor']]);
            $c['ko'] = (int)$r['ko'];
            if (!empty($r['kg'])) $c['kg'] = (string)$r['kg'];
            if (!empty($r['p'])) {
                $c['p'] = (string)$r['p'];
                $c['pl'] = (string)$r['pn'];
                $c['po'] = (int)$r['po'];
                $c['pp'] = (int)$r['pp'];
            }
        } elseif ($r['origin'] === 'forum') {
            $c['k'] = '~forum';
            $c['kl'] = __('tags.cat_forum');
            $c['ko'] = 100000;
        }
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
        if ($c['g'] === 'x' || $c['g'] === 't') continue;
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
    foreach (TAGS_BASE as $slug => [, , $aliases]) {
        if (count($out) >= $limit) break;
        if (!str_contains(mb_strtolower($aliases), $q) || in_array('x:' . $slug, array_column($out, 'id'), true)) continue;
        $row = tagsRow($db, 'x:' . $slug);
        if (!$row || $row['status'] !== 'approved' || (int)$row['hidden'] === 1) continue;
        $c = ['id' => 'x:' . $slug, 'g' => 'x', 'l' => (string)$row['name']];
        if ($row['color']) $c['c'] = (string)$row['color'];
        $out[] = $c;
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
    $clause = tagsPendingCol($db) ? ' AND pending = 0' : '';
    $db->exec("UPDATE tags t LEFT JOIN (SELECT SUBSTRING(tag, 3) AS slug, COUNT(*) AS n FROM hash_tags WHERE tag LIKE 'x:%'$clause GROUP BY tag) c ON c.slug = t.slug
               SET t.uses = COALESCE(c.n, 0)");
    if (tagsLogReady($db)) $db->exec("DELETE FROM hash_tag_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY");
    if (function_exists('schemaOnce') && schemaOnce($db, 'v109_base_tags')) tagsSeedBase($db);
    if (function_exists('schemaOnce') && schemaOnce($db, 'v113_base_tags')) tagsSeedBase($db);
    tagsPrune($db, $GLOBALS['cfg'] ?? []);
}

function tagsAdminList(PDO $db, array $q): array
{
    if (!tagsReady($db)) return ['rows' => [], 'total' => 0, 'pending' => 0];
    $where = [];
    $params = [];
    $status = (string)($q['status'] ?? '');
    if (in_array($status, ['approved', 'pending', 'rejected'], true)) { $where[] = 't.status = ?'; $params[] = $status; }
    if ($status === 'hidden') $where[] = 't.hidden = 1';
    if ($status === 'unused') $where[] = 't.uses = 0';
    $search = trim((string)($q['q'] ?? ''));
    if ($search !== '') { $where[] = '(t.name LIKE ? OR t.slug LIKE ?)'; $like = '%' . addcslashes($search, '%_\\') . '%'; $params[] = $like; $params[] = $like; }
    $cats = tagsCatReady($db);
    $cat = (string)($q['cat'] ?? '');
    if ($cats && $cat === 'none') $where[] = 't.category_id IS NULL';
    elseif ($cats && ctype_digit($cat)) { $where[] = 't.category_id = ?'; $params[] = (int)$cat; }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sortCols = ['name' => 't.name', 'status' => 't.status', 'uses' => 't.uses', 'created' => 't.created_at', 'new' => 't.created_at'] + ($cats ? ['cat' => 'cs'] : []);
    $orderParts = [];
    foreach (explode(',', (string)($q['sort'] ?? 'uses:desc')) as $part) {
        [$col, $dir] = array_pad(explode(':', trim($part)), 2, $part === 'new' ? 'desc' : ($part === 'uses' ? 'desc' : 'asc'));
        if (!isset($sortCols[$col]) || isset($orderParts[$sortCols[$col]])) continue;
        $orderParts[$sortCols[$col]] = $sortCols[$col] . (strtolower($dir) === 'desc' ? ' DESC' : ' ASC');
    }
    $orderClause = implode(', ', $orderParts ?: ['t.uses DESC']) . ', t.name, t.id';
    $perPage = max(10, min(200, (int)($q['per_page'] ?? 50)));
    $page = max(1, (int)($q['page'] ?? 1));
    $st = $db->prepare("SELECT COUNT(*) FROM tags t $whereClause");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $limit = (int)$perPage;
    $offset = ($page - 1) * $perPage;
    $catCol = $cats ? 't.category_id, COALESCE(c.sort, 999999) AS cs' : 'NULL AS category_id';
    $catJoin = $cats ? 'LEFT JOIN tag_categories c ON c.id = t.category_id' : '';
    $st = $db->prepare("SELECT t.id, t.slug, t.name, t.color, t.description, t.status, t.hidden, t.origin, t.uses, t.created_at, $catCol, u.username AS created_by_name
                          FROM tags t LEFT JOIN users u ON u.id = t.created_by $catJoin $whereClause ORDER BY $orderClause LIMIT $limit OFFSET $offset");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id']; $r['hidden'] = (int)$r['hidden']; $r['uses'] = (int)$r['uses']; $r['tag'] = 'x:' . $r['slug'];
        $r['category_id'] = $r['category_id'] !== null ? (int)$r['category_id'] : null;
        unset($r['cs']);
    }
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
        if (array_key_exists('category', $in) && tagsCatReady($db)) tagsCatAction($db, 'cat_assign', ['ids' => [$ids[0]], 'category' => (int)$in['category']], null);
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

const TAGS_FORUM_LANGS = ['pl' => 'l:audio_pl', 'polski' => 'l:audio_pl', 'polish' => 'l:audio_pl', 'polska' => 'l:audio_pl',
                          'en' => 'l:audio_en', 'eng' => 'l:audio_en', 'angielski' => 'l:audio_en', 'english' => 'l:audio_en'];

function tagsForumAuto(PDO $db, string $name, string $slug): ?string
{
    foreach ([tagsSlug($name), tagsSlug($slug)] as $s) {
        if (isset(TAGS_FORUM_LANGS[$s])) return TAGS_FORUM_LANGS[$s];
    }
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
    $clause = tagsPendingCol($db) ? ', pending = 0' : '';
    $put = $db->prepare("INSERT INTO hash_tags (info_hash, tag, origin, added_by, created_at) VALUES (?, ?, 4, NULL, UTC_TIMESTAMP())
                         ON DUPLICATE KEY UPDATE origin = origin | 4$clause");
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
    if ($op === 'forum_refresh') {
        $n = 0;
        $set = $db->prepare("UPDATE tag_forum_map SET target = ? WHERE client_id = ? AND forum_slug = ? AND state = 'proposed'");
        foreach ($db->query("SELECT client_id, forum_slug, name, target FROM tag_forum_map WHERE state = 'proposed'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $target = tagsForumTarget($db, (string)$r['name'], (string)$r['forum_slug']);
            if ($target === (string)$r['target']) continue;
            $set->execute([$target, (int)$r['client_id'], (string)$r['forum_slug']]);
            $n++;
        }
        $done = tagsForumReconcile($db, $cfg, null, 50000);
        tagsUsesTick($db);
        return ['ok' => true, 'n' => $n] + $done;
    }
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

function tagsPendingCol(PDO $db): bool
{
    static $ready = false;
    if ($ready) return true;
    try {
        $ready = (bool)$db->query("SHOW COLUMNS FROM hash_tags LIKE 'pending'")->fetchColumn();
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function tagsLogReady(PDO $db): bool
{
    static $ready = false;
    if ($ready) return true;
    try {
        $ready = (bool)$db->query("SHOW TABLES LIKE 'hash_tag_log'")->fetchColumn();
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function tagsLog(PDO $db, string $hash, string $tag, string $action, ?int $userId): void
{
    if (!tagsLogReady($db) || !in_array($action, ['add', 'remove', 'approve', 'reject', 'block'], true)) return;
    $db->prepare("INSERT INTO hash_tag_log (info_hash, tag, action, user_id, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")
       ->execute([strtolower($hash), $tag, $action, $userId]);
}

function tagsSeedBase(PDO $db): int
{
    if (!tagsReady($db)) return 0;
    $st = $db->prepare("INSERT IGNORE INTO tags (slug, name, color, status, origin, created_by, created_at) VALUES (?, ?, ?, 'approved', 'moderator', NULL, UTC_TIMESTAMP())");
    $n = 0;
    foreach (TAGS_BASE as $slug => [$name, $color]) {
        $st->execute([$slug, $name, $color]);
        $n += $st->rowCount();
    }
    return $n;
}

function tagsNorm(string $s): string
{
    $s = strtr(mb_strtolower($s), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', "'" => '', '’' => '', '`' => '']);
    return ' ' . trim((string)preg_replace('/[^a-z0-9&+]+/', ' ', $s)) . ' ';
}

function tagsSuggest(PDO $db, string $hash, string $name, string $text, array $have, int $limit = 8): array
{
    if (!tagsReady($db)) return [];
    $inName = tagsNorm($name);
    $inText = $text !== '' ? tagsNorm(mb_substr($text, 0, 20000)) : '';
    $skip = array_flip(array_merge($have, tagsBlocked($db, [$hash])[strtolower($hash)] ?? []));
    $first = [];
    $later = [];
    foreach ($db->query("SELECT slug, name, color FROM tags WHERE status = 'approved' AND hidden = 0 ORDER BY uses DESC, name LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = 'x:' . $r['slug'];
        if (isset($skip[$id])) continue;
        foreach (explode('|', (TAGS_BASE[$r['slug']][2] ?? '') . '|' . $r['name']) as $w) {
            $needle = tagsNorm($w);
            $len = strlen(trim($needle));
            if ($len < 2) continue;
            $at = str_contains($inName, $needle) ? 1 : ($len >= 3 && $inText !== '' && str_contains($inText, $needle) ? 2 : 0);
            if ($at === 0) continue;
            $chip = ['id' => $id, 'g' => 'x', 'l' => (string)$r['name'], 'w' => trim($w)];
            if ($r['color']) $chip['c'] = (string)$r['color'];
            if ($at === 1) $first[] = $chip;
            else $later[] = $chip;
            break;
        }
    }
    return array_slice(array_merge($first, $later), 0, $limit);
}

function tagsChangeChip(string $id, ?string $name, ?string $color, array $genres): array
{
    if (tagsIsExplicit($id)) {
        $chip = ['id' => $id, 'g' => 'x', 'l' => $name !== null && $name !== '' ? $name : substr($id, 2)];
        if ($color) $chip['c'] = $color;
        return $chip;
    }
    return ['id' => $id, 'g' => substr($id, 0, 1), 'l' => str_starts_with($id, 'g:') ? tagsGenreLabel($id, $genres) : factsTagLabel($id)];
}

function tagsChangesAdmin(PDO $db, array $cfg): array
{
    $out = ['review' => ($cfg['tags_add_review'] ?? 'open') === 'approve' ? 'approve' : 'open', 'n_pending' => 0, 'pending' => [], 'recent' => []];
    if (!tagsReady($db)) return $out;
    $genres = tagsGenreLabels($db);
    if (tagsPendingCol($db)) {
        $out['n_pending'] = (int)$db->query("SELECT COUNT(*) FROM hash_tags WHERE pending = 1")->fetchColumn();
        $st = $db->query("SELECT ht.info_hash, ht.tag, ht.created_at, u.username, t.name AS tname, t.color,
                                 COALESCE((SELECT w.name FROM whitelist w WHERE w.info_hash = ht.info_hash LIMIT 1),
                                          (SELECT h.name FROM index_hashes h WHERE h.info_hash = ht.info_hash LIMIT 1)) AS torrent
                            FROM hash_tags ht LEFT JOIN users u ON u.id = ht.added_by
                            LEFT JOIN tags t ON ht.tag LIKE 'x:%' AND t.slug = SUBSTRING(ht.tag, 3)
                           WHERE ht.pending = 1 ORDER BY ht.created_at LIMIT 300");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['pending'][] = ['hash' => strtolower((string)$r['info_hash']), 'tag' => (string)$r['tag'], 'who' => $r['username'], 'at' => $r['created_at'],
                                 'torrent' => $r['torrent'], 'chip' => tagsChangeChip((string)$r['tag'], $r['tname'], $r['color'], $genres)];
        }
    }
    if (tagsLogReady($db)) {
        $sel = tagsPendingCol($db) ? 'x.pending' : '0';
        $st = $db->query("SELECT l.id, l.info_hash, l.tag, l.action, l.created_at, u.username, t.name AS tname, t.color,
                                 (SELECT $sel FROM hash_tags x WHERE x.info_hash = l.info_hash AND x.tag = l.tag) AS still,
                                 COALESCE((SELECT w.name FROM whitelist w WHERE w.info_hash = l.info_hash LIMIT 1),
                                          (SELECT h.name FROM index_hashes h WHERE h.info_hash = l.info_hash LIMIT 1)) AS torrent
                            FROM hash_tag_log l LEFT JOIN users u ON u.id = l.user_id
                            LEFT JOIN tags t ON l.tag LIKE 'x:%' AND t.slug = SUBSTRING(l.tag, 3)
                           ORDER BY l.id DESC LIMIT 150");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['recent'][] = ['id' => (int)$r['id'], 'hash' => strtolower((string)$r['info_hash']), 'tag' => (string)$r['tag'], 'action' => (string)$r['action'],
                                'who' => $r['username'], 'at' => $r['created_at'], 'torrent' => $r['torrent'], 'still' => $r['still'] === null ? null : (int)$r['still'],
                                'chip' => tagsChangeChip((string)$r['tag'], $r['tname'], $r['color'], $genres)];
        }
    }
    return $out;
}

function tagsChangesAction(PDO $db, string $op, array $items, ?int $actor, bool $all = false): array
{
    if (!tagsReady($db) || !tagsPendingCol($db)) return ['ok' => false, 'error' => 'unavailable'];
    if ($all && $op === 'change_approve') {
        $items = $db->query("SELECT info_hash AS hash, tag FROM hash_tags WHERE pending = 1 ORDER BY created_at LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    }
    $approve = $db->prepare("UPDATE hash_tags SET pending = 0 WHERE info_hash = ? AND tag = ? AND pending = 1");
    $reject = $db->prepare("DELETE FROM hash_tags WHERE info_hash = ? AND tag = ? AND pending = 1");
    $n = 0;
    foreach (array_slice($items, 0, 500) as $it) {
        if (!is_array($it)) continue;
        $h = strtolower(is_string($it['hash'] ?? null) ? $it['hash'] : '');
        $tag = is_string($it['tag'] ?? null) ? $it['tag'] : '';
        if (!preg_match('/^[0-9a-f]{40}$/', $h) || !factsTagValid($tag)) continue;
        if ($op === 'change_approve' || $op === 'change_reject') {
            $q = $op === 'change_approve' ? $approve : $reject;
            $q->execute([$h, $tag]);
            if ($q->rowCount() < 1) continue;
            tagsLog($db, $h, $tag, $op === 'change_approve' ? 'approve' : 'reject', $actor);
            $n++;
        } elseif ($op === 'change_remove') {
            $r = tagsDetach($db, $h, $tag, $actor, true);
            if (empty($r['ok'])) continue;
            tagsLog($db, $h, $tag, $r['removed'] === 'blocked' ? 'block' : 'remove', $actor);
            $n++;
        }
    }
    if ($n) tagsUsesTick($db);
    return ['ok' => true, 'n' => $n];
}

const TAGS_FAV_MAX = 200;

function tagsFavReady(PDO $db): bool
{
    static $ready = false;
    if ($ready) return true;
    try {
        $ready = (bool)$db->query("SHOW TABLES LIKE 'tag_favourites'")->fetchColumn();
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function tagsFavList(PDO $db, int $userId): array
{
    if (!tagsFavReady($db)) return [];
    $st = $db->prepare("SELECT tag FROM tag_favourites WHERE user_id = ? ORDER BY created_at, tag LIMIT " . TAGS_FAV_MAX);
    $st->execute([$userId]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function tagsFavChips(PDO $db, array $ids): array
{
    $genres = null;
    $out = [];
    foreach ($ids as $id) {
        if (tagsIsExplicit($id)) {
            $row = tagsRow($db, $id);
            if (!$row || $row['status'] !== 'approved' || (int)$row['hidden'] === 1) continue;
            $chip = ['id' => $id, 'g' => 'x', 'l' => (string)$row['name']];
            if ($row['color']) $chip['c'] = (string)$row['color'];
        } elseif (factsTagValid($id)) {
            $chip = ['id' => $id, 'g' => substr($id, 0, 1), 'l' => str_starts_with($id, 'g:') ? tagsGenreLabel($id, $genres ??= tagsGenreLabels($db)) : factsTagLabel($id)];
        } else {
            continue;
        }
        $out[] = $chip;
    }
    return $out;
}

function tagsFavSet(PDO $db, int $userId, string $id, bool $on): array
{
    if (!tagsFavReady($db)) return ['ok' => false, 'error' => 'unavailable'];
    if (!factsTagValid($id) || !tagsFavChips($db, [$id])) return ['ok' => false, 'error' => 'unknown'];
    if (!$on) {
        $db->prepare("DELETE FROM tag_favourites WHERE user_id = ? AND tag = ?")->execute([$userId, $id]);
        return ['ok' => true, 'on' => false];
    }
    $st = $db->prepare("SELECT COUNT(*) FROM tag_favourites WHERE user_id = ?");
    $st->execute([$userId]);
    if ((int)$st->fetchColumn() >= TAGS_FAV_MAX) return ['ok' => false, 'error' => 'fav_limit'];
    $db->prepare("INSERT IGNORE INTO tag_favourites (user_id, tag, created_at) VALUES (?, ?, UTC_TIMESTAMP())")->execute([$userId, $id]);
    return ['ok' => true, 'on' => true];
}

const TAGS_CAT_SYSTEM = ['q' => 'resolution', 'v' => 'video', 'h' => 'hdr', 'a' => 'audio', 's' => 'source', 'l' => 'language', 'g' => 'genre'];
const TAGS_CAT_START = [
    'release'  => ['extended', 'director-s-cut', 'uncut', 'remaster', 'imax', 'wersja-kinowa', 'kolekcja', 'komplet-sezonow'],
    'picture'  => ['3d', 'upscale-ai', 'czarno-bialy', 'koloryzacja'],
    'kind'     => ['gra', 'program', 'muzyka', 'audiobook', 'e-book'],
    'platform' => ['netflix', 'amazon-prime', 'disney', 'hbo-max', 'apple-tv', 'skyshowtime', 'canal'],
    'other'    => ['polska-produkcja', 'klasyka', 'koncert', 'kabaret', 'stand-up', 'sport'],
    'language' => ['lektor-ivo', 'lektor-ai', 'dubbing-ai', 'lektor-amatorski', 'napisy-wtopione', 'napisy-sdh', 'audiodeskrypcja'],
];
const TAGS_CAT_MAX = 60;

function tagsCatReady(PDO $db): bool
{
    static $ready = false;
    if ($ready) return true;
    try {
        $ready = (bool)$db->query("SHOW TABLES LIKE 'tag_cat_suggestions'")->fetchColumn()
              && (bool)$db->query("SHOW COLUMNS FROM tags LIKE 'category_id'")->fetchColumn();
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function tagsGroupReady(PDO $db): bool
{
    static $ready = false;
    if ($ready) return true;
    try {
        $ready = (bool)$db->query("SHOW TABLES LIKE 'tag_cat_groups'")->fetchColumn()
              && (bool)$db->query("SHOW COLUMNS FROM tag_categories LIKE 'group_id'")->fetchColumn();
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function tagsGroupList(PDO $db): array
{
    if (!tagsGroupReady($db)) return [];
    $rows = $db->query("SELECT g.id, g.slug, g.name, g.sort, g.pinned, (SELECT COUNT(*) FROM tag_categories c WHERE c.group_id = g.id) AS n
                          FROM tag_cat_groups g ORDER BY g.sort, g.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['sort'] = (int)$r['sort'];
        $r['pinned'] = (int)$r['pinned'];
        $r['n'] = (int)$r['n'];
    }
    unset($r);
    return $rows;
}

function tagsGroupAction(PDO $db, string $op, array $in): array
{
    if (!tagsGroupReady($db)) return ['ok' => false, 'error' => 'unavailable'];
    if ($op === 'grp_add') {
        $name = tagsName((string)($in['name'] ?? ''), 40);
        if ($name === null) return ['ok' => false, 'error' => 'name'];
        $slug = tagsSlug($name);
        $st = $db->prepare("SELECT 1 FROM tag_cat_groups WHERE slug = ?");
        $st->execute([$slug]);
        if ($st->fetchColumn()) return ['ok' => false, 'error' => 'exists'];
        if ((int)$db->query("SELECT COUNT(*) FROM tag_cat_groups")->fetchColumn() >= 20) return ['ok' => false, 'error' => 'limit'];
        $sort = (int)$db->query("SELECT COALESCE(MAX(sort), 0) FROM tag_cat_groups")->fetchColumn() + 10;
        $db->prepare("INSERT INTO tag_cat_groups (slug, name, sort, pinned, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")
           ->execute([$slug, $name, $sort, !empty($in['pinned']) ? 1 : 0]);
        return ['ok' => true, 'n' => 1, 'id' => (int)$db->lastInsertId()];
    }
    if ($op === 'cat_group') {
        $gid = (int)($in['group'] ?? 0);
        if ($gid > 0) {
            $st = $db->prepare("SELECT 1 FROM tag_cat_groups WHERE id = ?");
            $st->execute([$gid]);
            if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'target'];
        }
        $st = $db->prepare("UPDATE tag_categories SET group_id = ? WHERE id = ? AND grp IS NULL");
        $st->execute([$gid > 0 ? $gid : null, (int)($in['id'] ?? 0)]);
        return ['ok' => true, 'n' => (int)$st->rowCount()];
    }
    $st = $db->prepare("SELECT * FROM tag_cat_groups WHERE id = ?");
    $st->execute([(int)($in['id'] ?? 0)]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g) return ['ok' => false, 'error' => 'not_found'];
    if ($op === 'grp_save') {
        $name = tagsName((string)($in['name'] ?? $g['name']), 40);
        if ($name === null) return ['ok' => false, 'error' => 'name'];
        $db->prepare("UPDATE tag_cat_groups SET name = ?, pinned = ? WHERE id = ?")
           ->execute([$name, array_key_exists('pinned', $in) ? (!empty($in['pinned']) ? 1 : 0) : (int)$g['pinned'], (int)$g['id']]);
        return ['ok' => true, 'n' => 1];
    }
    if ($op === 'grp_move') {
        $dir = (int)($in['dir'] ?? 0) < 0 ? -1 : 1;
        $ids = array_map('intval', $db->query("SELECT id FROM tag_cat_groups ORDER BY sort, id")->fetchAll(PDO::FETCH_COLUMN));
        $at = array_search((int)$g['id'], $ids, true);
        $to = $at + $dir;
        if ($at === false || $to < 0 || $to >= count($ids)) return ['ok' => true, 'n' => 0];
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $up = $db->prepare("UPDATE tag_cat_groups SET sort = ? WHERE id = ?");
        foreach ($ids as $k => $id) $up->execute([($k + 1) * 10, $id]);
        return ['ok' => true, 'n' => 1];
    }
    if ($op === 'grp_delete') {
        $db->prepare("UPDATE tag_categories SET group_id = NULL WHERE group_id = ?")->execute([(int)$g['id']]);
        $db->prepare("DELETE FROM tag_cat_groups WHERE id = ?")->execute([(int)$g['id']]);
        return ['ok' => true, 'n' => 1];
    }
    return ['ok' => false, 'error' => 'invalid'];
}

function tagsCatSeed(PDO $db): int
{
    if (!tagsCatReady($db)) return 0;
    $ins = $db->prepare("INSERT IGNORE INTO tag_categories (slug, name, grp, sort, status, origin, created_at) VALUES (?, ?, ?, ?, 'approved', 'system', UTC_TIMESTAMP())");
    $sort = 0;
    foreach (TAGS_CAT_SYSTEM as $g => $slug) $ins->execute([$slug, $slug, $g, $sort += 10]);
    foreach (array_keys(TAGS_CAT_START) as $slug) {
        if (!in_array($slug, TAGS_CAT_SYSTEM, true)) $ins->execute([$slug, $slug, null, $sort += 10]);
    }
    $set = $db->prepare("UPDATE tags t JOIN tag_categories c ON c.slug = ? SET t.category_id = c.id WHERE t.slug = ? AND t.category_id IS NULL");
    $n = 0;
    foreach (TAGS_CAT_START as $cat => $slugs) {
        foreach ($slugs as $s) {
            $set->execute([$cat, $s]);
            $n += $set->rowCount();
        }
    }
    return $n;
}

function tagsCatLabel(array $c): string
{
    if (($c['origin'] ?? '') === 'system') {
        if (!empty($c['grp'])) return __('js.filters.t_' . $c['grp']);
        if (isset(TAGS_CAT_START[$c['slug'] ?? ''])) return __('tags.cat_' . $c['slug']);
    }
    return (string)($c['name'] ?? '');
}

function tagsCatList(PDO $db, bool $all = false): array
{
    if (!tagsCatReady($db)) return [];
    $groupCol = tagsGroupReady($db) ? 'c.group_id' : 'NULL AS group_id';
    $rows = $db->query("SELECT c.id, c.slug, c.name, c.color, c.sort, c.grp, c.status, c.origin, c.created_at, u.username AS created_by_name, $groupCol,
                               (SELECT COUNT(*) FROM tags t WHERE t.category_id = c.id) AS n
                          FROM tag_categories c LEFT JOIN users u ON u.id = c.created_by"
                       . ($all ? '' : " WHERE c.status = 'approved'") . " ORDER BY c.sort, c.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['sort'] = (int)$r['sort'];
        $r['n'] = (int)$r['n'];
        $r['group_id'] = $r['group_id'] !== null ? (int)$r['group_id'] : null;
        $r['l'] = tagsCatLabel($r);
    }
    unset($r);
    return $rows;
}

function tagsCatOpen(PDO $db): int
{
    if (!tagsCatReady($db)) return 0;
    return (int)$db->query("SELECT COUNT(*) FROM tag_cat_suggestions WHERE status = 'open'")->fetchColumn();
}

function tagsCatAdmin(PDO $db): array
{
    if (!tagsCatReady($db)) return ['cats' => [], 'suggestions' => [], 'groups' => []];
    $cats = tagsCatList($db, true);
    $byId = array_column($cats, null, 'id');
    $rows = $db->query("SELECT s.id, s.tag_slug, s.category_id, s.new_name, s.created_at, u.username, t.name AS tag_name, t.color AS tag_color, t.category_id AS tag_cat
                          FROM tag_cat_suggestions s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN tags t ON t.slug = s.tag_slug
                         WHERE s.status = 'open' ORDER BY s.created_at LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $cat = $r['category_id'] !== null ? ($byId[(int)$r['category_id']] ?? null) : null;
        $now = $r['tag_cat'] !== null ? ($byId[(int)$r['tag_cat']] ?? null) : null;
        $out[] = ['id' => (int)$r['id'], 'tag' => 'x:' . $r['tag_slug'], 'tag_name' => (string)($r['tag_name'] ?? $r['tag_slug']), 'tag_color' => $r['tag_color'],
                  'cat' => $cat ? $cat['l'] : null, 'new' => $r['new_name'], 'now' => $now ? $now['l'] : null,
                  'who' => $r['username'], 'at' => $r['created_at']];
    }
    return ['cats' => $cats, 'suggestions' => $out, 'groups' => tagsGroupList($db)];
}

function tagsCatCreate(PDO $db, string $name, ?string $color, string $origin, ?int $userId, string $status = 'approved'): array
{
    $clean = tagsName($name, 40);
    if ($clean === null) return ['ok' => false, 'error' => 'name'];
    $slug = tagsSlug($clean);
    $st = $db->prepare("SELECT id, status FROM tag_categories WHERE slug = ?");
    $st->execute([$slug]);
    $have = $st->fetch(PDO::FETCH_ASSOC);
    if ($have) return ['ok' => true, 'id' => (int)$have['id'], 'exists' => true];
    if ((int)$db->query("SELECT COUNT(*) FROM tag_categories")->fetchColumn() >= TAGS_CAT_MAX) return ['ok' => false, 'error' => 'limit'];
    $sort = (int)$db->query("SELECT COALESCE(MAX(sort), 0) FROM tag_categories")->fetchColumn() + 10;
    $db->prepare("INSERT INTO tag_categories (slug, name, color, sort, status, origin, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
       ->execute([$slug, $clean, tagsColor($color), $sort, $status, $origin, $userId]);
    return ['ok' => true, 'id' => (int)$db->lastInsertId()];
}

function tagsCatAction(PDO $db, string $op, array $in, ?int $actor): array
{
    if (!tagsCatReady($db)) return ['ok' => false, 'error' => 'unavailable'];
    if ($op === 'cat_add') {
        $r = tagsCatCreate($db, (string)($in['name'] ?? ''), is_string($in['color'] ?? null) ? $in['color'] : null, 'moderator', $actor);
        if (!empty($r['exists'])) return ['ok' => false, 'error' => 'exists'];
        return empty($r['ok']) ? $r : ['ok' => true, 'n' => 1, 'id' => $r['id']];
    }
    if ($op === 'cat_assign') {
        $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [])), fn($v) => $v > 0));
        if (!$ids || count($ids) > 500) return ['ok' => false, 'error' => 'invalid'];
        $cat = (int)($in['category'] ?? 0);
        if ($cat > 0) {
            $st = $db->prepare("SELECT 1 FROM tag_categories WHERE id = ?");
            $st->execute([$cat]);
            if (!$st->fetchColumn()) return ['ok' => false, 'error' => 'target'];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE tags SET category_id = ? WHERE id IN ($ph)")->execute(array_merge([$cat > 0 ? $cat : null], $ids));
        return ['ok' => true, 'n' => count($ids)];
    }
    if ($op === 'sugg_accept' || $op === 'sugg_reject') {
        $st = $db->prepare("SELECT * FROM tag_cat_suggestions WHERE id = ? AND status = 'open'");
        $st->execute([(int)($in['id'] ?? 0)]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) return ['ok' => false, 'error' => 'not_found'];
        if ($op === 'sugg_accept') {
            $cat = $s['category_id'] !== null ? (int)$s['category_id'] : 0;
            if ($cat === 0) {
                $r = tagsCatCreate($db, (string)$s['new_name'], null, 'member', (int)$s['user_id']);
                if (empty($r['ok'])) return $r;
                $cat = (int)$r['id'];
                $db->prepare("UPDATE tag_categories SET status = 'approved' WHERE id = ?")->execute([$cat]);
            }
            $db->prepare("UPDATE tags SET category_id = ? WHERE slug = ?")->execute([$cat, $s['tag_slug']]);
        }
        $db->prepare("UPDATE tag_cat_suggestions SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP() WHERE id = ?")
           ->execute([$op === 'sugg_accept' ? 'accepted' : 'rejected', $actor, (int)$s['id']]);
        return ['ok' => true, 'n' => 1];
    }
    $st = $db->prepare("SELECT * FROM tag_categories WHERE id = ?");
    $st->execute([(int)($in['id'] ?? 0)]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return ['ok' => false, 'error' => 'not_found'];
    if ($op === 'cat_save') {
        $color = tagsColor(is_string($in['color'] ?? null) ? $in['color'] : null);
        if (!empty($c['grp'])) {
            $db->prepare("UPDATE tag_categories SET color = ? WHERE id = ?")->execute([$color, (int)$c['id']]);
            return ['ok' => true, 'n' => 1];
        }
        $name = tagsName((string)($in['name'] ?? ''), 40);
        if ($name === null) return ['ok' => false, 'error' => 'name'];
        $same = $c['origin'] === 'system' && $name === tagsCatLabel($c);
        $db->prepare("UPDATE tag_categories SET name = ?, color = ?, origin = ?, status = 'approved' WHERE id = ?")
           ->execute([$same ? $c['name'] : $name, $color, $same ? 'system' : ($c['origin'] === 'system' ? 'moderator' : $c['origin']), (int)$c['id']]);
        return ['ok' => true, 'n' => 1];
    }
    if ($op === 'cat_move') {
        $dir = (int)($in['dir'] ?? 0) < 0 ? -1 : 1;
        $all = $db->query("SELECT id, sort FROM tag_categories ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(fn($r) => (int)$r['id'], $all);
        $at = array_search((int)$c['id'], $ids, true);
        $to = $at + $dir;
        if ($at === false || $to < 0 || $to >= count($ids)) return ['ok' => true, 'n' => 0];
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $up = $db->prepare("UPDATE tag_categories SET sort = ? WHERE id = ?");
        foreach ($ids as $k => $id) $up->execute([($k + 1) * 10, $id]);
        return ['ok' => true, 'n' => 1];
    }
    if ($op === 'cat_delete') {
        if (!empty($c['grp'])) return ['ok' => false, 'error' => 'forbidden'];
        $db->prepare("UPDATE tags SET category_id = NULL WHERE category_id = ?")->execute([(int)$c['id']]);
        $db->prepare("DELETE FROM tag_cat_suggestions WHERE category_id = ? AND status = 'open'")->execute([(int)$c['id']]);
        $db->prepare("DELETE FROM tag_categories WHERE id = ?")->execute([(int)$c['id']]);
        return ['ok' => true, 'n' => 1];
    }
    return ['ok' => false, 'error' => 'invalid'];
}

function tagsCatSuggest(PDO $db, string $tagId, string $catSlug, string $newName, int $userId, bool $direct): array
{
    if (!tagsCatReady($db)) return ['ok' => false, 'error' => 'unavailable'];
    $row = tagsRow($db, $tagId);
    if (!$row || $row['status'] !== 'approved' || (int)$row['hidden'] === 1) return ['ok' => false, 'error' => 'unknown'];
    $cat = null;
    if ($catSlug !== '') {
        $st = $db->prepare("SELECT id FROM tag_categories WHERE slug = ? AND status = 'approved'");
        $st->execute([$catSlug]);
        $cat = $st->fetchColumn();
        if ($cat === false) return ['ok' => false, 'error' => 'target'];
        $cat = (int)$cat;
        if ($row['category_id'] !== null && (int)$row['category_id'] === $cat) return ['ok' => true, 'same' => true];
    } else {
        $newName = (string)tagsName($newName, 40);
        if ($newName === '') return ['ok' => false, 'error' => 'name'];
        $st = $db->prepare("SELECT id FROM tag_categories WHERE slug = ? AND status = 'approved'");
        $st->execute([tagsSlug($newName)]);
        $have = $st->fetchColumn();
        if ($have !== false) $cat = (int)$have;
    }
    if ($direct) {
        if ($cat === null) {
            $r = tagsCatCreate($db, $newName, null, 'moderator', $userId);
            if (empty($r['ok'])) return $r;
            $cat = (int)$r['id'];
        }
        $db->prepare("UPDATE tags SET category_id = ? WHERE id = ?")->execute([$cat, (int)$row['id']]);
        $db->prepare("UPDATE tag_cat_suggestions SET status = 'accepted', decided_by = ?, decided_at = UTC_TIMESTAMP() WHERE tag_slug = ? AND status = 'open' AND category_id <=> ?")
           ->execute([$userId, $row['slug'], $cat]);
        return ['ok' => true, 'direct' => true];
    }
    $st = $db->prepare("SELECT COUNT(*) FROM tag_cat_suggestions WHERE user_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY");
    $st->execute([$userId]);
    if ((int)$st->fetchColumn() >= 30) return ['ok' => false, 'error' => 'limit'];
    $db->prepare("DELETE FROM tag_cat_suggestions WHERE tag_slug = ? AND user_id = ? AND status = 'open'")->execute([$row['slug'], $userId]);
    $db->prepare("INSERT INTO tag_cat_suggestions (tag_slug, category_id, new_name, user_id, created_at, status) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), 'open')")
       ->execute([$row['slug'], $cat, $cat === null ? $newName : null, $userId]);
    return ['ok' => true, 'direct' => false];
}

function tagsPopularMax(array $cfg): int
{
    return tagsLimit($cfg, 'tags_popular_max', 100, 0, 500);
}
