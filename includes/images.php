<?php
require_once __DIR__ . '/usermedia.php';

const IMAGES_PAGE_EDGE = 960;
const IMAGES_PER_PAGE = 40;
const IMAGES_RECHECK_MINUTES = 10;
const IMAGES_RATE_PER_MINUTE = 30;
const IMAGES_SWEEP_SECONDS = 3600;
const IMAGES_SWEEP_BATCH = 200;
const IMAGES_GIF_MAX_BYTES = 8388608;

function imagesMaxMb(array $cfg): int
{
    return max(1, min(20, (int)($cfg['images_max_mb'] ?? 8) ?: 8));
}

function imagesMaxEdge(array $cfg): int
{
    return max(640, min(4096, (int)($cfg['images_max_edge'] ?? 2048) ?: 2048));
}

function imagesMaxMpx(array $cfg): int
{
    return max(4, min(40, (int)($cfg['images_max_mpx'] ?? 24) ?: 24));
}

function imagesQuality(array $cfg): int
{
    return max(50, min(95, (int)($cfg['images_webp_quality'] ?? 82) ?: 82));
}

function imagesPerDay(array $cfg): int
{
    return max(0, min(1000, (int)($cfg['images_per_day'] ?? 60)));
}

function imagesQuotaMb(array $cfg): int
{
    return max(0, min(100000, (int)($cfg['images_quota_mb'] ?? 250)));
}

function imagesUnusedHours(array $cfg): int
{
    return max(1, min(720, (int)($cfg['images_unused_hours'] ?? 48) ?: 48));
}

function imagesMediaCfg(array $cfg): array
{
    return array_merge($cfg, ['avatar_max_kb' => (string)(imagesMaxMb($cfg) * 1024), 'avatar_max_mp' => (string)imagesMaxMpx($cfg)]);
}

function imagesMaxBytes(array $cfg): int
{
    return userMediaMaxBytes(imagesMediaCfg($cfg));
}

function imagesMayUpload(PDO $db, array $cfg, ?array $user): bool
{
    static $known = [];
    $id = (int)($user['id'] ?? 0);
    if ($id <= 0 || !usersEnabled($cfg)) return false;
    return $known[$id] ??= userIdHasPermission($db, $cfg, $id, 'content.images');
}

function imagesBase(array $cfg): string
{
    $base = rtrim(trim((string)($cfg['site_url'] ?? '')), '/');
    if (preg_match('#^https?://#i', $base)) return $base . '/';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) return '';
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    return ($https ? 'https' : 'http') . '://' . $host . getBaseUrl();
}

function imagesUrl(array $cfg, int $id, int $w, int $h, string $ext): string
{
    return imagesBase($cfg) . 'i/' . $id . '-' . $w . 'x' . $h . '.' . ($ext === 'gif' ? 'gif' : 'webp');
}

function imagesEncode(\GdImage $im, int $quality): string
{
    imagesavealpha($im, true);
    ob_start();
    $ok = @imagewebp($im, null, $quality);
    $out = (string)ob_get_clean();
    return $ok ? $out : '';
}

function imagesGifFrames(string $b): int
{
    return (int)preg_match_all('/\x00\x21\xF9\x04.{4}\x00(?:\x2C|\x21)/s', $b);
}

function imagesProcess(string $bytes, array $cfg): array
{
    $edge = imagesMaxEdge($cfg);
    $p = userMediaPrepare($bytes, imagesMediaCfg($cfg), $edge);
    if (empty($p['ok'])) return $p;
    if ($p['from'] === 'gif' && strlen($bytes) <= IMAGES_GIF_MAX_BYTES && imagesGifFrames($bytes) > 1) {
        $info = @getimagesizefromstring($bytes);
        $ow = (int)($info[0] ?? 0);
        $oh = (int)($info[1] ?? 0);
        if ($ow > 0 && $oh > 0 && max($ow, $oh) <= $edge) {
            return ['ok' => true, 'mime' => 'image/gif', 'animated' => 1, 'w' => $ow, 'h' => $oh, 'full_w' => $ow, 'full_h' => $oh,
                    'page' => $bytes, 'full' => null];
        }
    }
    $q = imagesQuality($cfg);
    $full = imagesEncode($p['im'], $q);
    if ($full === '') return ['ok' => false, 'status' => 500, 'error' => 'api.media.store_failed'];
    $fw = (int)$p['w'];
    $fh = (int)$p['h'];
    if (max($fw, $fh) <= IMAGES_PAGE_EDGE) {
        return ['ok' => true, 'mime' => 'image/webp', 'animated' => 0, 'w' => $fw, 'h' => $fh, 'full_w' => $fw, 'full_h' => $fh,
                'page' => $full, 'full' => null];
    }
    $small = userMediaBound($p['im'], IMAGES_PAGE_EDGE);
    $page = imagesEncode($small, $q);
    if ($page === '') return ['ok' => false, 'status' => 500, 'error' => 'api.media.store_failed'];
    return ['ok' => true, 'mime' => 'image/webp', 'animated' => 0, 'w' => imagesx($small), 'h' => imagesy($small),
            'full_w' => $fw, 'full_h' => $fh, 'page' => $page, 'full' => $full];
}

function imagesRefusal(array $cfg, string $key, int $status, string $detail = '', array $vars = []): array
{
    $codes = ['api.media.too_large' => 'too_big', 'api.media.too_many_px' => 'too_many_pixels',
              'api.media.too_big_to_process' => 'too_many_pixels', 'api.media.store_failed' => 'upload_failed',
              'api.images.daily_limit' => 'daily_limit', 'api.images.quota' => 'quota'];
    $vars += ['kb' => (int)floor(imagesMaxBytes($cfg) / 1024), 'mp' => imagesMaxMpx($cfg), 'px' => $detail,
              'kind' => strtoupper($detail), 'n' => imagesPerDay($cfg), 'max' => imagesQuotaMb($cfg)];
    return ['ok' => false, 'status' => $status, 'code' => $codes[$key] ?? 'bad_type', 'message' => __($key, $vars)];
}

function imagesUsage(PDO $db, int $userId): array
{
    $st = $db->prepare("SELECT COUNT(*) FROM image_uploads WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 DAY");
    $st->execute([$userId]);
    $today = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT COALESCE(SUM(b.bytes), 0) FROM images i JOIN image_blobs b ON b.sha256 = i.blob_sha WHERE i.user_id = ?");
    $st->execute([$userId]);
    return ['today' => $today, 'bytes' => (int)$st->fetchColumn()];
}

function imagesCleanName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = (string)preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
    return mb_substr(trim($name), 0, 120);
}

function imagesStore(PDO $db, array $cfg, ?int $userId, string $bytes, string $name, string $source = 'upload', ?string $sourceUrl = null): array
{
    $sha = hash('sha256', $bytes);
    $quota = imagesQuotaMb($cfg) * 1048576;
    $usage = ['today' => 0, 'bytes' => 0];
    if ($userId !== null) {
        $st = $db->prepare("SELECT id FROM images WHERE user_id = ? AND blob_sha = ? LIMIT 1");
        $st->execute([$userId, $sha]);
        $have = (int)($st->fetchColumn() ?: 0);
        if ($have > 0) return ['ok' => true, 'row' => imagesRow($db, $have), 'existing' => true];
        $usage = imagesUsage($db, $userId);
        if (imagesPerDay($cfg) > 0 && $usage['today'] >= imagesPerDay($cfg)) {
            return imagesRefusal($cfg, 'api.images.daily_limit', 429);
        }
        if ($quota > 0 && $usage['bytes'] >= $quota) {
            return imagesRefusal($cfg, 'api.images.quota', 403, '', ['used' => (int)ceil($usage['bytes'] / 1048576)]);
        }
    }
    $st = $db->prepare("SELECT bytes FROM image_blobs WHERE sha256 = ?");
    $st->execute([$sha]);
    $size = $st->fetchColumn();
    if ($size === false) {
        $p = imagesProcess($bytes, $cfg);
        if (empty($p['ok'])) {
            return imagesRefusal($cfg, (string)($p['error'] ?? 'api.media.store_failed'), (int)($p['status'] ?? 400), (string)($p['detail'] ?? ''));
        }
        $size = strlen($p['page']) + strlen((string)$p['full']);
        if ($userId !== null && $quota > 0 && $usage['bytes'] + $size > $quota) {
            return imagesRefusal($cfg, 'api.images.quota', 403, '', ['used' => (int)ceil($usage['bytes'] / 1048576)]);
        }
        $ins = $db->prepare("INSERT IGNORE INTO image_blobs (sha256, mime, animated, w, h, full_w, full_h, bytes, data_page, data_full)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->bindValue(1, $sha);
        $ins->bindValue(2, $p['mime']);
        $ins->bindValue(3, (int)$p['animated'], PDO::PARAM_INT);
        $ins->bindValue(4, (int)$p['w'], PDO::PARAM_INT);
        $ins->bindValue(5, (int)$p['h'], PDO::PARAM_INT);
        $ins->bindValue(6, (int)$p['full_w'], PDO::PARAM_INT);
        $ins->bindValue(7, (int)$p['full_h'], PDO::PARAM_INT);
        $ins->bindValue(8, $size, PDO::PARAM_INT);
        $ins->bindValue(9, $p['page'], PDO::PARAM_LOB);
        $ins->bindValue(10, $p['full'], $p['full'] === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $ins->execute();
    } elseif ($userId !== null && $quota > 0 && $usage['bytes'] + (int)$size > $quota) {
        return imagesRefusal($cfg, 'api.images.quota', 403, '', ['used' => (int)ceil($usage['bytes'] / 1048576)]);
    }
    if ($userId === null) {
        $st = $db->prepare("SELECT id FROM images WHERE user_id IS NULL AND blob_sha = ? LIMIT 1");
        $st->execute([$sha]);
        $have = (int)($st->fetchColumn() ?: 0);
        if ($have > 0) return ['ok' => true, 'row' => imagesRow($db, $have), 'existing' => true];
    }
    $ins = $db->prepare("INSERT INTO images (user_id, blob_sha, name, source, source_url) VALUES (?, ?, ?, ?, ?)");
    $ins->bindValue(1, $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $ins->bindValue(2, $sha);
    $ins->bindValue(3, imagesCleanName($name));
    $ins->bindValue(4, $source === 'copy' ? 'copy' : 'upload');
    $ins->bindValue(5, $sourceUrl === null ? null : mb_substr($sourceUrl, 0, 500), $sourceUrl === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $ins->execute();
    $newId = (int)$db->lastInsertId();
    if ($userId !== null) $db->prepare("INSERT INTO image_uploads (user_id) VALUES (?)")->execute([$userId]);
    return ['ok' => true, 'row' => imagesRow($db, $newId), 'existing' => false];
}

function imagesRow(PDO $db, int $id): ?array
{
    $st = $db->prepare("SELECT i.id, i.user_id, i.blob_sha, i.name, i.source, i.source_url, i.created_at, i.public_at, i.checked_at,
                               b.mime, b.animated, b.w, b.h, b.full_w, b.full_h, b.bytes
                          FROM images i JOIN image_blobs b ON b.sha256 = i.blob_sha WHERE i.id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function imagesExt(array $r): string
{
    return ($r['mime'] ?? '') === 'image/gif' ? 'gif' : 'webp';
}

function imagesRowOut(array $cfg, array $r): array
{
    $id = (int)$r['id'];
    return [
        'id' => $id,
        'url' => imagesUrl($cfg, $id, (int)$r['w'], (int)$r['h'], imagesExt($r)),
        'full_url' => imagesUrl($cfg, $id, (int)$r['full_w'], (int)$r['full_h'], imagesExt($r)),
        'w' => (int)$r['w'], 'h' => (int)$r['h'], 'full_w' => (int)$r['full_w'], 'full_h' => (int)$r['full_h'],
        'bytes' => (int)$r['bytes'], 'name' => (string)$r['name'], 'created_at' => (string)$r['created_at'],
        'used' => $r['public_at'] !== null, 'animated' => (bool)$r['animated'],
    ];
}

function imagesLimits(PDO $db, array $cfg, int $userId): array
{
    $u = imagesUsage($db, $userId);
    return ['max_bytes' => imagesMaxBytes($cfg), 'max_edge' => imagesMaxEdge($cfg), 'max_mpx' => imagesMaxMpx($cfg),
            'per_day' => imagesPerDay($cfg), 'today' => $u['today'],
            'quota_bytes' => imagesQuotaMb($cfg) * 1048576, 'used_bytes' => $u['bytes']];
}

function imagesList(PDO $db, array $cfg, int $userId, int $page): array
{
    $st = $db->prepare("SELECT COUNT(*) FROM images WHERE user_id = ?");
    $st->execute([$userId]);
    $total = (int)$st->fetchColumn();
    $page = max(1, min($page, max(1, (int)ceil($total / IMAGES_PER_PAGE))));
    $st = $db->prepare("SELECT i.id, i.user_id, i.blob_sha, i.name, i.source, i.source_url, i.created_at, i.public_at, i.checked_at,
                               b.mime, b.animated, b.w, b.h, b.full_w, b.full_h, b.bytes
                          FROM images i JOIN image_blobs b ON b.sha256 = i.blob_sha
                         WHERE i.user_id = ? ORDER BY i.id DESC LIMIT ? OFFSET ?");
    $st->bindValue(1, $userId, PDO::PARAM_INT);
    $st->bindValue(2, IMAGES_PER_PAGE, PDO::PARAM_INT);
    $st->bindValue(3, ($page - 1) * IMAGES_PER_PAGE, PDO::PARAM_INT);
    $st->execute();
    $rows = array_map(fn($r) => imagesRowOut($cfg, $r), $st->fetchAll(PDO::FETCH_ASSOC));
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => IMAGES_PER_PAGE, 'limits' => imagesLimits($db, $cfg, $userId)];
}

function imagesTextSources(bool $publishedOnly): array
{
    return $publishedOnly
        ? ["SELECT description FROM whitelist WHERE description LIKE ? AND content_status = 'approved' AND banned = 0",
           "SELECT description FROM hash_content WHERE description LIKE ? AND content_status = 'approved'",
           "SELECT body FROM page_content WHERE body LIKE ? AND enabled = 1"]
        : ["SELECT description FROM whitelist WHERE description LIKE ?",
           "SELECT description FROM hash_content WHERE description LIKE ?",
           "SELECT description FROM wl_content_edits WHERE description LIKE ?",
           "SELECT body FROM page_content WHERE body LIKE ?"];
}

function imagesReferenced(PDO $db, int $id, bool $publishedOnly): bool
{
    foreach (imagesTextSources($publishedOnly) as $sql) {
        $st = $db->prepare($sql . ' LIMIT 1');
        $st->execute(['%/i/' . $id . '-%']);
        if ($st->fetchColumn() !== false) return true;
    }
    return false;
}

function imagesReferencedIds(PDO $db): array
{
    $ids = [];
    foreach (imagesTextSources(false) as $sql) {
        $st = $db->prepare($sql);
        $st->execute(['%/i/%']);
        while (($t = $st->fetchColumn()) !== false) {
            if (preg_match_all('#/i/(\d+)-\d+x\d+\.(?:webp|gif)#', (string)$t, $m)) {
                foreach ($m[1] as $i) $ids[(int)$i] = true;
            }
        }
    }
    return $ids;
}

function imagesIsPublic(PDO $db, array $row): bool
{
    if ($row['public_at'] !== null) return true;
    $id = (int)$row['id'];
    $st = $db->prepare("UPDATE images SET checked_at = NOW()
                         WHERE id = ? AND (checked_at IS NULL OR checked_at < NOW() - INTERVAL " . IMAGES_RECHECK_MINUTES . " MINUTE)");
    $st->execute([$id]);
    if ($st->rowCount() !== 1 || !imagesReferenced($db, $id, true)) return false;
    $db->prepare("UPDATE images SET public_at = NOW() WHERE id = ? AND public_at IS NULL")->execute([$id]);
    return true;
}

function imagesServe(PDO $db, int $id, int $w, int $h, string $ext, bool $wantFull, ?int $viewerId, bool $moderator): ?array
{
    $row = imagesRow($db, $id);
    if ($row === null || $ext !== imagesExt($row)) return null;
    $isPage = $w === (int)$row['w'] && $h === (int)$row['h'];
    $isFull = $w === (int)$row['full_w'] && $h === (int)$row['full_h'];
    if (!$isPage && !$isFull) return null;
    $public = imagesIsPublic($db, $row);
    $owner = $viewerId !== null && $row['user_id'] !== null && $viewerId === (int)$row['user_id'];
    if (!$public && !$owner && !$moderator) return null;
    $variant = ($wantFull || !$isPage) ? 'full' : 'page';
    if ($variant === 'full') {
        $st = $db->prepare("SELECT COALESCE(data_full, data_page) FROM image_blobs WHERE sha256 = ?");
    } else {
        $st = $db->prepare("SELECT data_page FROM image_blobs WHERE sha256 = ?");
    }
    $st->execute([$row['blob_sha']]);
    $data = $st->fetchColumn();
    if ($data === false || $data === null) return null;
    return ['data' => (string)$data, 'mime' => (string)$row['mime'], 'public' => $public,
            'etag' => '"' . substr((string)$row['blob_sha'], 0, 16) . '-' . $variant . '"',
            'name' => 'image-' . $id . '.' . imagesExt($row)];
}

function imagesDelete(PDO $db, int $id): bool
{
    $st = $db->prepare("SELECT blob_sha FROM images WHERE id = ?");
    $st->execute([$id]);
    $sha = $st->fetchColumn();
    if ($sha === false) return false;
    $db->prepare("DELETE FROM images WHERE id = ?")->execute([$id]);
    $st = $db->prepare("SELECT COUNT(*) FROM images WHERE blob_sha = ?");
    $st->execute([$sha]);
    if ((int)$st->fetchColumn() === 0) $db->prepare("DELETE FROM image_blobs WHERE sha256 = ?")->execute([$sha]);
    return true;
}

function imagesSweep(PDO $db, array $cfg, bool $force = false): int
{
    if (!$force && time() - (int)($cfg['images_swept_at'] ?? 0) < IMAGES_SWEEP_SECONDS) return 0;
    $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('images_swept_at', ?)")->execute([(string)time()]);
    $db->exec("DELETE FROM image_uploads WHERE created_at < NOW() - INTERVAL 2 DAY");
    $st = $db->prepare("SELECT id FROM images WHERE created_at < NOW() - INTERVAL ? HOUR ORDER BY id");
    $st->bindValue(1, imagesUnusedHours($cfg), PDO::PARAM_INT);
    $st->execute();
    $old = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$old) return 0;
    $used = imagesReferencedIds($db);
    $gone = 0;
    foreach ($old as $id) {
        if ($gone >= IMAGES_SWEEP_BATCH) break;
        if (!isset($used[$id]) && imagesDelete($db, $id)) $gone++;
    }
    return $gone;
}
