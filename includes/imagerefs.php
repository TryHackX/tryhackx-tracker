<?php
const IMAGES_REF_SOURCES = ['wl', 'idx', 'edit', 'cm', 'page', 'pm', 'shout', 'bio', 'list', 'warn', 'crep', 'mrep'];
const IMAGES_PUBLIC_RECHECK_MINUTES = 30;
const IMAGES_REFS_REBUILD_SECONDS = 3600;
const IMAGES_REFS_SCAN_BATCH = 500;
const IMAGES_REMOVAL_NOTE = 'removal of a picture its owner deleted';

function imagesRefsReady(PDO $db, ?array $cfg = null): bool
{
    $at = (int)($cfg['images_refs_built_at'] ?? 0);
    if ($at <= 0) {
        try {
            $st = $db->query("SELECT `value` FROM settings WHERE `key` = 'images_refs_built_at'");
            $at = (int)($st->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            return false;
        }
    }
    return $at > 0;
}

function imagesRefsEnsure(PDO $db, ?array $cfg = null): void
{
    static $busy = false;
    if ($busy || imagesRefsReady($db, $cfg)) return;
    $busy = true;
    try {
        imagesRefsRebuild($db, imagesRefsCfg($db, $cfg));
    } finally {
        $busy = false;
    }
}

function imagesRefsCfg(PDO $db, ?array $cfg): array
{
    if ($cfg !== null) return $cfg;
    if (isset($GLOBALS['cfg']) && is_array($GLOBALS['cfg'])) return $GLOBALS['cfg'];
    return function_exists('getSettings') ? getSettings($db) : [];
}

function imagesRefsTextOf(PDO $db, string $src, string $key): ?string
{
    if (in_array($src, IMAGES_PLACE_SOURCES, true)) {
        $st = $db->prepare((string)imagesPlaceTextSql($src));
        $st->execute([(int)$key]);
        $t = $st->fetchColumn();
        return $t === false || $t === null ? null : (string)$t;
    }
    if ($src === 'page') {
        $p = explode("\t", $key, 2);
        if (count($p) !== 2) return null;
        $st = $db->prepare("SELECT body FROM page_content WHERE page = ? AND lang = ?");
        $st->execute($p);
    } else {
        $sql = match ($src) {
            'wl'   => "SELECT description FROM whitelist WHERE id = ?",
            'idx'  => "SELECT description FROM hash_content WHERE id = ?",
            'edit' => "SELECT description FROM wl_content_edits WHERE id = ?",
            'cm'   => "SELECT body FROM hash_comments WHERE id = ?",
            default => null,
        };
        if ($sql === null) return null;
        $st = $db->prepare($sql);
        $st->execute([(int)$key]);
    }
    $t = $st->fetchColumn();
    return $t === false || $t === null ? null : (string)$t;
}

function imagesRefsParse(array $cfg, string $text, array $hosts): array
{
    $own = [];
    $base = imagesBase($cfg);
    if ($base !== '' && str_contains($text, '/i/')
        && preg_match_all('~https?://[^\s\[\]"\'<>()]+?/i/\d{1,10}-\d{1,5}x\d{1,5}\.(?:webp|gif)~i', $text, $m)) {
        foreach ($m[0] as $u) {
            $o = imagesOwnStoreUrl($base, (string)$u);
            if ($o !== null && $o['id'] > 0) $own[$o['id']] = true;
        }
    }
    $remote = [];
    if ($hosts) {
        foreach (imagesRemoteUrls($text, $hosts, $base) as $u) $remote[hash('sha256', (string)$u)] = (string)$u;
    }
    return ['own' => array_keys($own), 'remote' => $remote];
}

function imagesRefsSync(PDO $db, array $cfg, string $src, string $key, ?string $text, ?array $hosts = null): array
{
    $hosts ??= imagesRemoteHosts($db);
    $p = $text !== null && $text !== '' ? imagesRefsParse($cfg, $text, $hosts) : ['own' => [], 'remote' => []];
    $st = $db->prepare("SELECT image_id FROM image_refs WHERE src = ? AND src_key = ?");
    $st->execute([$src, $key]);
    $old = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $gone = array_values(array_diff($old, $p['own']));
    $new = array_values(array_diff($p['own'], $old));
    if ($gone) {
        $del = $db->prepare("DELETE FROM image_refs WHERE image_id = ? AND src = ? AND src_key = ?");
        foreach ($gone as $id) $del->execute([$id, $src, $key]);
    }
    if ($new) {
        $ins = $db->prepare("INSERT IGNORE INTO image_refs (image_id, src, src_key) VALUES (?, ?, ?)");
        foreach ($new as $id) $ins->execute([$id, $src, $key]);
    }
    $st = $db->prepare("SELECT url_sha FROM image_remote_refs WHERE src = ? AND src_key = ?");
    $st->execute([$src, $key]);
    $oldR = $st->fetchAll(PDO::FETCH_COLUMN);
    $wantR = array_keys($p['remote']);
    $goneR = array_values(array_diff($oldR, $wantR));
    $newR = array_values(array_diff($wantR, $oldR));
    if ($goneR) {
        $del = $db->prepare("DELETE FROM image_remote_refs WHERE url_sha = ? AND src = ? AND src_key = ?");
        foreach ($goneR as $sha) $del->execute([$sha, $src, $key]);
    }
    if ($newR) {
        $ins = $db->prepare("INSERT IGNORE INTO image_remote_refs (url_sha, src, src_key) VALUES (?, ?, ?)");
        foreach ($newR as $sha) $ins->execute([$sha, $src, $key]);
    }
    return ['images' => array_values(array_unique(array_merge($old, $p['own']))), 'remote' => $p['remote']];
}

function imagesRefState(PDO $db, string $src, string $key): array
{
    $dead = ['state' => 'dead', 'author' => null, 'trusted' => false, 'exists' => false];
    if (in_array($src, IMAGES_PLACE_SOURCES, true)) return imagesPlaceRefState($db, $src, (int)$key);
    if ($src === 'page') {
        $p = explode("\t", $key, 2);
        if (count($p) !== 2) return $dead;
        $st = $db->prepare("SELECT enabled FROM page_content WHERE page = ? AND lang = ?");
        $st->execute($p);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $dead;
        return ['state' => (int)$r['enabled'] === 1 ? 'published' : 'hidden', 'author' => null, 'trusted' => true, 'exists' => true];
    }
    $id = (int)$key;
    if ($src === 'wl' || $src === 'idx') {
        $sql = match ($src) {
            'wl'  => "SELECT content_status AS s, banned AS b, content_user_id AS u, content_reviewed_at AS r FROM whitelist WHERE id = ?",
            'idx' => "SELECT content_status AS s, 0 AS b, content_user_id AS u, content_reviewed_at AS r FROM hash_content WHERE id = ?",
        };
        $st = $db->prepare($sql);
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $dead;
        $state = match ((string)$r['s']) { 'approved' => 'published', 'pending' => 'pending', default => 'dead' };
        if ((int)$r['b'] === 1 && $state !== 'dead') $state = 'hidden';
        return ['state' => $state, 'author' => $r['u'] !== null ? (int)$r['u'] : null, 'trusted' => $r['r'] !== null, 'exists' => true];
    }
    if ($src === 'edit') {
        $st = $db->prepare("SELECT status, user_id FROM wl_content_edits WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $dead;
        return ['state' => (string)$r['status'] === 'pending' ? 'pending' : 'dead', 'author' => $r['user_id'] !== null ? (int)$r['user_id'] : null,
                'trusted' => false, 'exists' => true];
    }
    if ($src === 'cm') {
        $st = $db->prepare("SELECT status, user_id, approved_by FROM hash_comments WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $dead;
        $state = match ((string)$r['status']) { 'visible' => 'published', 'pending' => 'pending', default => 'dead' };
        return ['state' => $state, 'author' => $r['user_id'] !== null ? (int)$r['user_id'] : null, 'trusted' => $r['approved_by'] !== null, 'exists' => true];
    }
    return $dead;
}

function imagesVisibility(PDO $db, array $row): array
{
    $st = $db->prepare("SELECT src, src_key FROM image_refs WHERE image_id = ?");
    $st->execute([(int)$row['id']]);
    $refs = $st->fetchAll(PDO::FETCH_ASSOC);
    $owner = $row['user_id'] !== null ? (int)$row['user_id'] : null;
    $public = (string)($row['source'] ?? '') === 'copy';
    $live = false;
    $blocking = false;
    foreach ($refs as $r) {
        $s = imagesRefState($db, (string)$r['src'], (string)$r['src_key']);
        if ($s['state'] === 'dead') continue;
        $live = true;
        if (in_array((string)$r['src'], ['wl', 'idx', 'page'], true)) $blocking = true;
        if ($s['state'] === 'published' && ($s['trusted'] || $s['author'] === $owner)) $public = true;
    }
    return ['public' => $public, 'live' => $live, 'blocking' => $blocking, 'refs' => count($refs)];
}

function imagesVisibilitySync(PDO $db, array $ids): array
{
    $out = ['public' => 0, 'hidden' => 0, 'deleted' => 0];
    $st = $db->prepare("SELECT id, user_id, source, public_at, removal_at, dead_since FROM images WHERE id = ?");
    $up = $db->prepare("UPDATE images SET public_at = CASE WHEN ? = 1 THEN COALESCE(public_at, NOW()) ELSE NULL END, checked_at = NOW(),
                               dead_since = CASE WHEN ? = 1 THEN COALESCE(dead_since, NOW()) ELSE NULL END
                         WHERE id = ?");
    foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
        if ($id <= 0) continue;
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;
        $v = imagesVisibility($db, $row);
        if ($row['removal_at'] !== null && !$v['blocking']) {
            if (imagesDelete($db, $id)) $out['deleted']++;
            continue;
        }
        $up->execute([$v['public'] ? 1 : 0, ($v['refs'] > 0 && !$v['live']) ? 1 : 0, $id]);
        $out[$v['public'] ? 'public' : 'hidden']++;
    }
    return $out;
}

function imagesRefsTouch(PDO $db, ?array $cfg, string $src, string $key): void
{
    if (!in_array($src, IMAGES_REF_SOURCES, true) || $key === '' || $key === '0') return;
    if (function_exists('factsTouch')) factsTouch($db, $src, $key);
    try {
        $cfg = imagesRefsCfg($db, $cfg);
        $text = imagesRefsTextOf($db, $src, $key);
        $r = imagesRefsSync($db, $cfg, $src, $key, $text, in_array($src, IMAGES_PLACE_SOURCES, true) ? [] : null);
        if ($r['images']) imagesVisibilitySync($db, $r['images']);
        if ($r['remote'] && imagesRefState($db, $src, $key)['state'] !== 'dead') {
            imagesRemoteEnqueue($db, array_values($r['remote']));
            imagesRemoteSettleRows($db, $cfg, array_keys($r['remote']));
        }
    } catch (\Throwable $e) {
        if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) throw $e;
        error_log('[images] references of ' . $src . ' ' . $key . ': ' . $e->getMessage());
    }
}

function imagesRefsScanSql(string $src): string
{
    if (in_array($src, IMAGES_PLACE_SOURCES, true)) return imagesPlaceScanSql($src);
    return match ($src) {
        'wl'   => "SELECT id AS k, description AS t FROM whitelist WHERE id > ? AND description IS NOT NULL ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
        'idx'  => "SELECT id AS k, description AS t FROM hash_content WHERE id > ? AND description IS NOT NULL ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
        'edit' => "SELECT id AS k, description AS t FROM wl_content_edits WHERE id > ? AND description IS NOT NULL ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
        'cm'   => "SELECT id AS k, body AS t FROM hash_comments WHERE id > ? AND body <> '' ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
        default => "SELECT CONCAT(page, CHAR(9), lang) AS k, body AS t FROM page_content WHERE ? = 0",
    };
}

function imagesRefsRebuild(PDO $db, array $cfg): array
{
    $out = ['rows' => 0, 'refs' => 0, 'remote' => 0, 'queued' => 0, 'settled' => 0];
    $hosts = imagesRemoteHosts($db, true);
    $base = imagesBase($cfg);
    if ($base === '') return $out;
    $liveRemote = [];
    foreach (IMAGES_REF_SOURCES as $src) {
        $want = [];
        $wantR = [];
        $after = 0;
        $sql = imagesRefsScanSql($src);
        do {
            $st = $db->prepare($sql);
            $st->execute([$src === 'page' ? 0 : $after]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $key = (string)$r['k'];
                if ($src !== 'page') $after = (int)$key;
                $text = (string)$r['t'];
                if (!str_contains($text, 'http')) continue;
                $p = imagesRefsParse($cfg, $text, in_array($src, IMAGES_PLACE_SOURCES, true) ? [] : $hosts);
                if (!$p['own'] && !$p['remote']) continue;
                $out['rows']++;
                foreach ($p['own'] as $id) $want[$id . "\n" . $key] = [$id, $key];
                $live = null;
                foreach ($p['remote'] as $sha => $url) {
                    $wantR[$sha . "\n" . $key] = [$sha, $key];
                    if (isset($liveRemote[$sha])) continue;
                    $live ??= imagesRefState($db, $src, $key)['state'] !== 'dead';
                    if ($live) $liveRemote[$sha] = $url;
                }
            }
        } while ($src !== 'page' && count($rows) === IMAGES_REFS_SCAN_BATCH);
        $have = [];
        $st = $db->prepare("SELECT image_id, src_key FROM image_refs WHERE src = ?");
        $st->execute([$src]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $have[(int)$r['image_id'] . "\n" . $r['src_key']] = [(int)$r['image_id'], (string)$r['src_key']];
        $del = $db->prepare("DELETE FROM image_refs WHERE image_id = ? AND src = ? AND src_key = ?");
        foreach (array_diff_key($have, $want) as [$id, $key]) $del->execute([$id, $src, $key]);
        $ins = $db->prepare("INSERT IGNORE INTO image_refs (image_id, src, src_key) VALUES (?, ?, ?)");
        foreach (array_diff_key($want, $have) as [$id, $key]) $ins->execute([$id, $src, $key]);
        $out['refs'] += count($want);
        $haveR = [];
        $st = $db->prepare("SELECT url_sha, src_key FROM image_remote_refs WHERE src = ?");
        $st->execute([$src]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $haveR[$r['url_sha'] . "\n" . $r['src_key']] = [(string)$r['url_sha'], (string)$r['src_key']];
        $del = $db->prepare("DELETE FROM image_remote_refs WHERE url_sha = ? AND src = ? AND src_key = ?");
        foreach (array_diff_key($haveR, $wantR) as [$sha, $key]) $del->execute([$sha, $src, $key]);
        $ins = $db->prepare("INSERT IGNORE INTO image_remote_refs (url_sha, src, src_key) VALUES (?, ?, ?)");
        foreach (array_diff_key($wantR, $haveR) as [$sha, $key]) $ins->execute([$sha, $src, $key]);
        $out['remote'] += count($wantR);
    }
    if ($liveRemote) {
        $out['queued'] = imagesRemoteEnqueue($db, array_values($liveRemote));
        $out['settled'] = imagesRemoteSettleRows($db, $cfg, array_keys($liveRemote));
    }
    $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('images_refs_built_at', ?)")->execute([(string)time()]);
    return $out;
}

function imagesRefsWhere(PDO $db, int $imageId): array
{
    $st = $db->prepare("SELECT src, src_key FROM image_refs WHERE image_id = ? ORDER BY src, src_key LIMIT " . IMAGES_USED_MAX);
    $st->execute([$imageId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function imagesStripFromText(array $cfg, string $text, array $ids): string
{
    $base = imagesBase($cfg);
    if ($base === '' || !$ids || !str_contains($text, '/i/')) return $text;
    $want = array_fill_keys(array_map('intval', $ids), true);
    $urls = [];
    if (preg_match_all('~https?://[^\s\[\]"\'<>()]+?/i/\d{1,10}-\d{1,5}x\d{1,5}\.(?:webp|gif)~i', $text, $m)) {
        foreach ($m[0] as $u) {
            $o = imagesOwnStoreUrl($base, (string)$u);
            if ($o !== null && isset($want[$o['id']])) $urls[(string)$u] = true;
        }
    }
    foreach (array_keys($urls) as $u) {
        $q = preg_quote($u, '~') . '(?:\?full=1)?';
        $text = (string)(preg_replace('~\[img(?:=[^\]]*)?\]\s*' . $q . '\s*\[/img\]~i', '', $text) ?? $text);
        $text = (string)(preg_replace('~!\[[^\]]*\]\(\s*<?' . $q . '>?(?:\s+"[^"]*")?\s*\)~', '', $text) ?? $text);
        $text = (string)(preg_replace('~\[upl-image-preview[^\]]*?url=' . $q . '[^\]]*\]~i', '', $text) ?? $text);
        $text = (string)(preg_replace('~\[url=' . $q . '\](.*?)\[/url\]~is', '$1', $text) ?? $text);
        $text = imagesReplaceUrl($text, $u . '?full=1', '');
        $text = imagesReplaceUrl($text, $u, '');
    }
    return trim((string)(preg_replace("~\n{3,}~", "\n\n", $text) ?? $text));
}

function imagesRefsWrite(PDO $db, string $src, string $key, string $old, string $new, ?array $cfg = null): bool
{
    if ($src === 'page') {
        $p = explode("\t", $key, 2);
        if (count($p) !== 2) return false;
        $st = $db->prepare("UPDATE page_content SET body = ? WHERE page = ? AND lang = ? AND body = ?");
        $st->execute([$new, $p[0], $p[1], $old]);
    } else {
        $sql = match ($src) {
            'wl'   => "UPDATE whitelist SET description = ? WHERE id = ? AND description = ?",
            'idx'  => "UPDATE hash_content SET description = ? WHERE id = ? AND description = ?",
            'edit' => "UPDATE wl_content_edits SET description = ? WHERE id = ? AND description = ?",
            'cm'   => "UPDATE hash_comments SET body = ? WHERE id = ? AND body = ?",
            default => null,
        };
        if ($sql === null) return false;
        $st = $db->prepare($sql);
        $st->execute([$new, (int)$key, $old]);
    }
    if ($st->rowCount() < 1) return false;
    if ($src === 'wl' && function_exists('forumDescRewritten')) forumDescRewritten($db, (int)$key, $old, $new);
    imagesRefsTouch($db, $cfg, $src, $key);
    return true;
}

function imagesRemoteSettleRows(PDO $db, array $cfg, array $shas): int
{
    $shas = array_values(array_unique(array_filter(array_map('strval', $shas), static fn($x) => preg_match('/^[0-9a-f]{64}$/', $x) === 1)));
    if (!$shas || imagesBase($cfg) === '') return 0;
    $n = 0;
    foreach (array_chunk($shas, 200) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT r.url_sha, r.url, i.id, b.mime, b.w, b.h FROM image_remote r
                              JOIN images i ON i.id = r.image_id JOIN image_blobs b ON b.sha256 = i.blob_sha
                             WHERE r.url_sha IN ($in) AND r.status = 'done'");
        $st->execute($chunk);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string)$r['url_sha']] = [(string)$r['url'], imagesUrl($cfg, (int)$r['id'], (int)$r['w'], (int)$r['h'], imagesExt($r))];
        }
        try {
            $st = $db->prepare("SELECT r.url_sha, r.url, x.image_id AS id, x.mime, x.w, x.h FROM image_remote r
                                  JOIN image_removals x ON x.image_id = r.image_id
                                 WHERE r.url_sha IN ($in) AND r.status = 'ignored' AND r.error = 'moderation'");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[(string)$r['url_sha']] = [(string)$r['url'], imagesUrl($cfg, (int)$r['id'], (int)$r['w'], (int)$r['h'], imagesRemovedExt($r))];
            }
        } catch (\Throwable $e) {
            error_log('[images] removed copies not mapped: ' . $e->getMessage());
        }
        if (!$map) continue;
        $in = implode(',', array_fill(0, count($map), '?'));
        $st = $db->prepare("SELECT src, src_key, url_sha FROM image_remote_refs WHERE url_sha IN ($in)");
        $st->execute(array_keys($map));
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['src'] . "\n" . $r['src_key']][] = (string)$r['url_sha'];
        foreach ($rows as $k => $list) {
            [$src, $key] = explode("\n", $k, 2);
            $text = imagesRefsTextOf($db, $src, $key);
            if ($text === null) continue;
            $new = $text;
            foreach ($list as $sha) $new = imagesReplaceUrl($new, $map[$sha][0], $map[$sha][1]);
            if ($new !== $text && imagesRefsWrite($db, $src, $key, $text, $new, $cfg)) $n++;
        }
    }
    return $n;
}

function imagesRemovalIds(PDO $db, int $userId, int $plus): array
{
    $st = $db->prepare("SELECT id FROM images WHERE user_id = ? AND (removal_at IS NOT NULL OR id = ?)");
    $st->execute([$userId, $plus]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function imagesRemovalPropose(PDO $db, array $cfg, string $kind, int $recId, int $userId, array $ids): ?int
{
    $rec = contentRowById($db, $kind, $recId);
    if (!$rec || $rec['description'] === null) return null;
    $text = (string)$rec['description'];
    $new = imagesStripFromText($cfg, $text, $ids);
    if ($new === $text) return null;
    $sql = match ($kind) {
        'wl' => "SELECT id FROM wl_content_edits WHERE whitelist_id = ? AND status = 'pending' AND user_id = ? AND note = ? LIMIT 1",
        default => "SELECT id FROM wl_content_edits WHERE hash_content_id = ? AND status = 'pending' AND user_id = ? AND note = ? LIMIT 1",
    };
    $st = $db->prepare($sql);
    $st->execute([$recId, $userId, IMAGES_REMOVAL_NOTE]);
    $have = (int)($st->fetchColumn() ?: 0);
    if ($have > 0) {
        $db->prepare("UPDATE wl_content_edits SET description = ?, description_format = ?, source_url = ? WHERE id = ? AND status = 'pending'")
           ->execute([$new !== '' ? $new : null, (string)$rec['description_format'], $rec['source_url'], $have]);
        $id = $have;
    } else {
        $db->prepare("INSERT INTO wl_content_edits (whitelist_id, hash_content_id, info_hash, source_url, description, description_format,
                             kind, ip, user_id, note)
                      VALUES (?, ?, ?, ?, ?, ?, 'edit', '', ?, ?)")
           ->execute([$kind === 'wl' ? $recId : null, $kind === 'idx' ? $recId : null, (string)$rec['info_hash'], $rec['source_url'],
                      $new !== '' ? $new : null, (string)$rec['description_format'], $userId, IMAGES_REMOVAL_NOTE]);
        $id = (int)$db->lastInsertId();
    }
    imagesRefsTouch($db, $cfg, 'edit', (string)$id);
    return $id;
}

function imagesStripRow(PDO $db, array $cfg, string $src, string $key, int $imageId, int $userId): bool
{
    $text = imagesRefsTextOf($db, $src, $key);
    if ($text === null) return false;
    $new = imagesStripFromText($cfg, $text, [$imageId]);
    if ($new === $text) return false;
    if ($src === 'cm') {
        $up = $db->prepare("UPDATE hash_comments SET body = ?, edited_at = NOW(), edited_by = ? WHERE id = ? AND body = ? AND status <> 'deleted'");
        $up->execute([$new, $userId, (int)$key, $text]);
        if ($up->rowCount() < 1) return false;
        imagesRefsTouch($db, $cfg, 'cm', $key);
        return true;
    }
    return imagesRefsWrite($db, $src, $key, $text, $new, $cfg);
}

function imagesRequestRemoval(PDO $db, array $cfg, array $img, array $me): array
{
    imagesRefsEnsure($db, $cfg);
    $id = (int)$img['id'];
    $uid = (int)$me['id'];
    $out = ['deleted' => false, 'proposals' => 0, 'stripped' => 0, 'kept' => 0];
    $ids = imagesRemovalIds($db, $uid, $id);
    $st = $db->prepare("SELECT src, src_key FROM image_refs WHERE image_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $src = (string)$r['src'];
        $key = (string)$r['src_key'];
        $state = imagesRefState($db, $src, $key);
        if ($state['state'] === 'dead') continue;
        $mine = $state['author'] === $uid;
        $direct = ($src === 'cm' && $mine) || ($src === 'edit' && $mine) || (($src === 'wl' || $src === 'idx') && $mine && $state['state'] !== 'published');
        if ($direct) {
            if (imagesStripRow($db, $cfg, $src, $key, $id, $uid)) $out['stripped']++; else $out['kept']++;
            continue;
        }
        if ($src === 'wl' || $src === 'idx') {
            if (imagesRemovalPropose($db, $cfg, $src, (int)$key, $uid, $ids) !== null) $out['proposals']++; else $out['kept']++;
            continue;
        }
        $out['kept']++;
    }
    $db->prepare("UPDATE images SET removal_at = NOW() WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
    $out['deleted'] = imagesVisibilitySync($db, [$id])['deleted'] > 0;
    return $out;
}

function imagesCancelRemoval(PDO $db, array $cfg, array $img, int $uid): int
{
    $id = (int)$img['id'];
    $db->prepare("UPDATE images SET removal_at = NULL WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
    $st = $db->prepare("SELECT id FROM images WHERE user_id = ? AND removal_at IS NOT NULL");
    $st->execute([$uid]);
    $left = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = $db->prepare("SELECT id, whitelist_id, hash_content_id FROM wl_content_edits WHERE status = 'pending' AND user_id = ? AND note = ?");
    $st->execute([$uid, IMAGES_REMOVAL_NOTE]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $kind = $e['whitelist_id'] !== null ? 'wl' : 'idx';
        $rec = contentRowById($db, $kind, (int)($e['whitelist_id'] ?? $e['hash_content_id']));
        $text = (string)($rec['description'] ?? '');
        $new = $left ? imagesStripFromText($cfg, $text, $left) : $text;
        if ($new === $text) {
            $db->prepare("UPDATE wl_content_edits SET status = 'rejected', reviewed_at = NOW(), note = ? WHERE id = ? AND status = 'pending'")
               ->execute([defined('CONTENT_WITHDRAWN_NOTE') ? CONTENT_WITHDRAWN_NOTE : 'withdrawn', (int)$e['id']]);
        } else {
            $db->prepare("UPDATE wl_content_edits SET description = ? WHERE id = ? AND status = 'pending'")->execute([$new !== '' ? $new : null, (int)$e['id']]);
        }
        imagesRefsTouch($db, $cfg, 'edit', (string)$e['id']);
        $n++;
    }
    imagesVisibilitySync($db, [$id]);
    return $n;
}
