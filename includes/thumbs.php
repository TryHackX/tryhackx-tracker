<?php

const THUMBS_W = 160;
const THUMBS_H = 240;
const THUMBS_MAX = 10;
const THUMBS_KEEP = 30;
const THUMBS_TICK = 40;

function thumbsReady(PDO $db): bool
{
    static $ready = false;
    if ($ready) return true;
    try {
        $ready = (bool)$db->query("SHOW TABLES LIKE 'hash_thumbs'")->fetchColumn();
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function thumbsOn(array $cfg, string $place): bool
{
    return (['search' => $cfg['thumbs_search'] ?? '1', 'lists' => $cfg['thumbs_lists'] ?? '1', 'info' => $cfg['thumbs_info'] ?? '1'][$place] ?? '0') === '1';
}

function thumbsLimit(array $cfg): int
{
    return max(1, min(THUMBS_MAX, (int)($cfg['thumbs_max'] ?? THUMBS_MAX)));
}

function thumbsLook(array $cfg): array
{
    return ['w' => max(40, min(160, (int)($cfg['thumbs_width'] ?? 64))), 's' => max(2, min(15, (int)($cfg['thumbs_speed'] ?? 4)))];
}

function thumbsDir(): string
{
    return dirname(__DIR__) . '/thumbs';
}

function thumbsName(int $id, string $sha): string
{
    return $id . '-' . substr($sha, 0, 8) . '.webp';
}

function thumbsStripName(int $id, string $sha): string
{
    return $id . '-' . substr($sha, 0, 8) . '-s.webp';
}

function thumbsIds(string $text, string $base): array
{
    $ids = [];
    if ($base === '') return $ids;
    $take = static function (string $part) use (&$ids, $base): void {
        if (!preg_match_all('#https?://[^\s\[\]"\'<>()]+/i/\d{1,10}-\d{1,5}x\d{1,5}\.(?:webp|gif)#i', $part, $m)) return;
        foreach ($m[0] as $url) {
            $own = imagesOwnStoreUrl($base, $url);
            if ($own !== null && !in_array($own['id'], $ids, true)) $ids[] = $own['id'];
        }
    };
    if (preg_match_all('/\[thxlabels(?:=\d{1,2})?\](.*?)\[\/thxlabels\]/is', $text, $lab)) {
        foreach ($lab[1] as $part) $take($part);
    }
    $take($text);
    return $ids;
}

function thumbsEligible(PDO $db, array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $r = imagesRow($db, (int)$id);
        if ($r === null || ($r['removal_at'] ?? null) !== null) continue;
        if ((string)$r['source'] !== 'copy' && $r['public_at'] === null) continue;
        $w = (int)$r['w'];
        $h = (int)$r['h'];
        if ($w < 100 || $h < 100 || $w > 2.5 * $h || $h > 2.5 * $w) continue;
        $out[(int)$id] = $r;
    }
    return $out;
}

function thumbsMake(PDO $db, array $r): ?string
{
    $name = thumbsName((int)$r['id'], (string)$r['blob_sha']);
    $dir = thumbsDir();
    $want = [];
    foreach ([$name => 'cover', thumbsStripName((int)$r['id'], (string)$r['blob_sha']) => 'strip'] as $file => $kind) {
        if (!is_file($dir . '/' . $file)) $want[$file] = $kind;
    }
    if (!$want) return $name;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    $st = $db->prepare("SELECT data_page FROM image_blobs WHERE sha256 = ?");
    $st->execute([(string)$r['blob_sha']]);
    $data = $st->fetchColumn();
    if (!is_string($data) || $data === '') return null;
    $src = @imagecreatefromstring($data);
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) return null;
    foreach ($want as $file => $kind) {
        if ($kind === 'cover') {
            $scale = max(THUMBS_W / $w, THUMBS_H / $h);
            $cw = (int)min($w, round(THUMBS_W / $scale));
            $ch = (int)min($h, round(THUMBS_H / $scale));
            $dst = imagecreatetruecolor(THUMBS_W, THUMBS_H);
            imagecopyresampled($dst, $src, 0, 0, (int)max(0, round(($w - $cw) / 2)), (int)max(0, round(($h - $ch) / 2)), THUMBS_W, THUMBS_H, $cw, $ch);
        } else {
            $sh = min(THUMBS_H, $h);
            $sw = max(1, (int)round($w * $sh / $h));
            $dst = imagecreatetruecolor($sw, $sh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $sw, $sh, $w, $h);
        }
        if (!thumbsWrite($dir . '/' . $file, imagesEncode($dst, 72))) return null;
    }
    return $name;
}

function thumbsWrite(string $path, string $out): bool
{
    if ($out === '') return false;
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $out) === false) return false;
    @chmod($tmp, 0644);
    if (@rename($tmp, $path)) return true;
    @unlink($tmp);
    return false;
}

function thumbsFromText(PDO $db, string $hash, string $text): int
{
    if (!thumbsReady($db) || !function_exists('imagesRow')) return 0;
    $hash = strtolower($hash);
    $cfg = getSettings($db);
    $keep = [];
    foreach (thumbsEligible($db, thumbsIds($text, imagesBase($cfg))) as $id => $r) {
        if (count($keep) >= THUMBS_KEEP) break;
        if (thumbsMake($db, $r) !== null) $keep[] = $id;
    }
    $db->prepare("DELETE FROM hash_thumbs WHERE info_hash = ?")->execute([$hash]);
    $ins = $db->prepare("REPLACE INTO hash_thumbs (info_hash, pos, image_id) VALUES (?, ?, ?)");
    foreach ($keep as $pos => $id) $ins->execute([$hash, $pos, $id]);
    return count($keep);
}

function thumbsFor(PDO $db, array $cfg, array $hashes, bool $signedIn): array
{
    if (!thumbsReady($db) || !$hashes) return [];
    $hashes = array_keys(factsPublished($db, $hashes, $signedIn));
    if (!$hashes) return [];
    $lim = thumbsLimit($cfg);
    $look = thumbsLook($cfg);
    $dir = thumbsDir();
    $out = [];
    foreach (array_chunk($hashes, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT t.info_hash, t.image_id, i.blob_sha, b.full_w, b.full_h, b.mime
                              FROM hash_thumbs t JOIN images i ON i.id = t.image_id JOIN image_blobs b ON b.sha256 = i.blob_sha
                             WHERE t.info_hash IN ($in) AND i.removal_at IS NULL AND (i.source = 'copy' OR i.public_at IS NOT NULL)
                             ORDER BY t.info_hash, t.pos");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $h = strtolower((string)$r['info_hash']);
            if (count($out[$h]['t'] ?? []) >= $lim) continue;
            $name = thumbsName((int)$r['image_id'], (string)$r['blob_sha']);
            if (!is_file($dir . '/' . $name)) continue;
            $out[$h] ??= ['t' => [], 'f' => [], 'w' => $look['w'], 's' => $look['s']];
            $out[$h]['t'][] = 'thumbs/' . $name;
            $out[$h]['f'][] = 'i/' . (int)$r['image_id'] . '-' . (int)$r['full_w'] . 'x' . (int)$r['full_h'] . '.' . ($r['mime'] === 'image/gif' ? 'gif' : 'webp');
        }
    }
    return $out;
}

function thumbsGallery(PDO $db, string $hash, bool $signedIn): array
{
    $hash = strtolower($hash);
    if (!thumbsReady($db) || !isset(factsPublished($db, [$hash], $signedIn)[$hash])) return [];
    $st = $db->prepare("SELECT t.image_id, i.blob_sha, b.full_w, b.full_h, b.mime
                          FROM hash_thumbs t JOIN images i ON i.id = t.image_id JOIN image_blobs b ON b.sha256 = i.blob_sha
                         WHERE t.info_hash = ? AND i.removal_at IS NULL AND (i.source = 'copy' OR i.public_at IS NOT NULL)
                         ORDER BY t.pos");
    $st->execute([$hash]);
    $dir = thumbsDir();
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = thumbsStripName((int)$r['image_id'], (string)$r['blob_sha']);
        if (!is_file($dir . '/' . $name)) continue;
        $out[] = ['t' => 'thumbs/' . $name, 'f' => 'i/' . (int)$r['image_id'] . '-' . (int)$r['full_w'] . 'x' . (int)$r['full_h'] . '.' . ($r['mime'] === 'image/gif' ? 'gif' : 'webp'),
                  'w' => (int)$r['full_w'], 'h' => (int)$r['full_h']];
    }
    return $out;
}

function thumbsForget(PDO $db, int $imageId): void
{
    if (thumbsReady($db)) $db->prepare("DELETE FROM hash_thumbs WHERE image_id = ?")->execute([$imageId]);
    foreach (glob(thumbsDir() . '/' . $imageId . '-*.webp') ?: [] as $f) @unlink($f);
}

function thumbsTick(PDO $db, array $cfg, int $limit = THUMBS_TICK): array
{
    $done = ['read' => 0, 'gone' => 0, 'made' => 0];
    if (!thumbsReady($db)) return $done;
    $cursor = (string)($cfg['thumbs_cursor'] ?? '');
    if ($cursor !== 'done') {
        [$src, $after] = array_pad(explode(':', $cursor, 2), 2, '0');
        $src = in_array($src, ['wl', 'hc'], true) ? $src : 'wl';
        $limit = max(1, $limit);
        $sql = $src === 'wl'
            ? "SELECT id, info_hash, description FROM whitelist WHERE id > ? AND description IS NOT NULL AND description <> '' ORDER BY id LIMIT $limit"
            : "SELECT id, info_hash, description FROM hash_content WHERE id > ? AND description IS NOT NULL AND description <> '' ORDER BY id LIMIT $limit";
        $st = $db->prepare($sql);
        $st->execute([(int)$after]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            thumbsFromText($db, (string)$r['info_hash'], (string)$r['description']);
            $done['read']++;
        }
        $next = $rows ? $src . ':' . (int)end($rows)['id'] : ($src === 'wl' ? 'hc:0' : 'done');
        $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('thumbs_cursor', ?)")->execute([$next]);
    }
    $db->exec("DELETE t FROM hash_thumbs t LEFT JOIN desc_facts f ON f.info_hash = t.info_hash WHERE f.info_hash IS NULL");
    $gone = $db->query("SELECT DISTINCT t.image_id FROM hash_thumbs t LEFT JOIN images i ON i.id = t.image_id
                         WHERE i.id IS NULL OR i.removal_at IS NOT NULL OR (i.source <> 'copy' AND i.public_at IS NULL) LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($gone as $id) thumbsForget($db, (int)$id);
    $done['gone'] = count($gone);
    $dir = thumbsDir();
    if (is_dir($dir)) {
        $used = array_flip(array_map('intval', $db->query("SELECT DISTINCT image_id FROM hash_thumbs")->fetchAll(PDO::FETCH_COLUMN)));
        foreach (glob($dir . '/*.webp') ?: [] as $f) {
            if (preg_match('/^(\d+)-/', basename($f), $m) && !isset($used[(int)$m[1]]) && filemtime($f) < time() - 3600) {
                @unlink($f);
                $done['gone']++;
            }
        }
    }
    $tries = 0;
    foreach ($db->query("SELECT DISTINCT i.id, i.blob_sha FROM hash_thumbs t JOIN images i ON i.id = t.image_id ORDER BY i.id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (is_file($dir . '/' . thumbsName((int)$r['id'], (string)$r['blob_sha'])) && is_file($dir . '/' . thumbsStripName((int)$r['id'], (string)$r['blob_sha']))) continue;
        if (++$tries > max(1, $limit)) break;
        if (thumbsMake($db, $r) !== null) $done['made']++;
    }
    return $done;
}
