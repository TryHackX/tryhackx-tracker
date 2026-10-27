<?php
require_once __DIR__ . '/usermedia.php';
require_once __DIR__ . '/imagerefs.php';

const IMAGES_PAGE_EDGE = 960;
const IMAGES_PER_PAGE = 40;
const IMAGES_RECHECK_MINUTES = 10;
const IMAGES_RATE_PER_MINUTE = 30;
const IMAGES_SWEEP_SECONDS = 3600;
const IMAGES_SWEEP_BATCH = 200;
const IMAGES_GIF_MAX_BYTES = 8388608;
const IMAGES_GIF_MAX_FRAMES = 1000;
const IMAGES_REMOTE_PER_RUN = 30;
const IMAGES_REMOTE_BUDGET = 40;
const IMAGES_REMOTE_TIMEOUT = 15;
const IMAGES_REMOTE_CONNECT = 5;
const IMAGES_REMOTE_TRIES = 5;
const IMAGES_REMOTE_SCAN_SECONDS = 600;
const IMAGES_REMOTE_URL_MAX = 500;
const IMAGES_REMOTE_DOMAINS_MAX = 20;
const IMAGES_REMOTE_PER_PAGE = 50;
const IMAGES_PANEL_PER_PAGE = 48;
const IMAGES_USED_MAX = 50;

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

function imagesPerDayMb(array $cfg): int
{
    return max(0, min(100000, (int)($cfg['images_per_day_mb'] ?? 100)));
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

function imagesOwnUrlAt(string $base, string $url): ?array
{
    if ($base === '' || strlen($url) <= strlen($base) || strncasecmp($url, $base, strlen($base)) !== 0) return null;
    if (!preg_match('#^i/(\d{1,10})-(\d{1,5})x(\d{1,5})\.(webp|gif)$#', substr($url, strlen($base)), $m)) return null;
    if ((int)$m[2] < 1 || (int)$m[3] < 1) return null;
    return ['id' => (int)$m[1], 'w' => (int)$m[2], 'h' => (int)$m[3], 'ext' => $m[4]];
}

function imagesOwnAt(string $base, string $url): bool
{
    $b = parse_url($base);
    $u = parse_url($url);
    if (!is_array($b) || !is_array($u) || empty($b['host']) || empty($u['host'])) return false;
    if (strtolower(rtrim((string)$u['host'], '.')) !== strtolower(rtrim((string)$b['host'], '.'))) return false;
    $path = (string)($b['path'] ?? '/');
    return strncmp((string)($u['path'] ?? '/'), $path, strlen($path)) === 0;
}

function imagesOwnStoreUrl(string $base, string $url): ?array
{
    if (!imagesOwnAt($base, $url)) return null;
    return imagesOwnUrlAt($base, (string)preg_replace('#^https?://#i', (string)parse_url($base, PHP_URL_SCHEME) . '://', $url));
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

function imagesGifBlocksEnd(string $b, int $p): ?int
{
    $n = strlen($b);
    while ($p < $n) {
        $size = ord($b[$p]);
        $p++;
        if ($size === 0) return $p;
        $p += $size;
    }
    return null;
}

function imagesGifClean(string $b): ?string
{
    $n = strlen($b);
    $head = substr($b, 0, 6);
    if ($n < 14 || ($head !== 'GIF89a' && $head !== 'GIF87a')) return null;
    $p = 13;
    $packed = ord($b[10]);
    if ($packed & 0x80) $p += 3 * (1 << (($packed & 7) + 1));
    if ($p > $n) return null;
    $out = substr($b, 0, $p);
    $frames = 0;
    while ($p < $n) {
        $c = ord($b[$p]);
        if ($c === 0x3B) return $frames > 0 ? $out . "\x3B" : null;
        if ($c === 0x21) {
            if ($p + 2 >= $n) return null;
            $label = ord($b[$p + 1]);
            $end = imagesGifBlocksEnd($b, $p + 2);
            if ($end === null) return null;
            $keep = $label === 0xF9 || ($label === 0xFF && ord($b[$p + 2]) === 11 && substr($b, $p + 3, 11) === 'NETSCAPE2.0');
            if ($keep) $out .= substr($b, $p, $end - $p);
            $p = $end;
            continue;
        }
        if ($c !== 0x2C || $p + 10 > $n || ++$frames > IMAGES_GIF_MAX_FRAMES) return null;
        $local = ord($b[$p + 9]);
        $q = $p + 10 + (($local & 0x80) ? 3 * (1 << (($local & 7) + 1)) : 0);
        if ($q >= $n) return null;
        $end = imagesGifBlocksEnd($b, $q + 1);
        if ($end === null) return null;
        $out .= substr($b, $p, $end - $p);
        $p = $end;
    }
    return null;
}

function imagesProcess(string $bytes, array $cfg, ?int $maxBytes = null): array
{
    $edge = imagesMaxEdge($cfg);
    $p = userMediaPrepare($bytes, imagesMediaCfg($cfg), $edge, $maxBytes);
    if (empty($p['ok'])) return $p;
    $gif = $p['from'] === 'gif' && strlen($bytes) <= IMAGES_GIF_MAX_BYTES && imagesGifFrames($bytes) > 1 ? imagesGifClean($bytes) : null;
    if ($gif !== null && imagesGifFrames($gif) > 1) {
        $info = @getimagesizefromstring($gif);
        $ow = (int)($info[0] ?? 0);
        $oh = (int)($info[1] ?? 0);
        if ($ow > 0 && $oh > 0 && max($ow, $oh) <= $edge) {
            return ['ok' => true, 'mime' => 'image/gif', 'animated' => 1, 'w' => $ow, 'h' => $oh, 'full_w' => $ow, 'full_h' => $oh,
                    'page' => $gif, 'full' => null];
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
              'api.images.daily_limit' => 'daily_limit', 'api.images.daily_mb' => 'daily_mb', 'api.images.quota' => 'quota',
              'api.images.shape' => 'bad_shape'];
    $vars += ['kb' => (int)floor(imagesMaxBytes($cfg) / 1024), 'mp' => imagesMaxMpx($cfg), 'px' => $detail,
              'kind' => strtoupper($detail), 'n' => imagesPerDay($cfg), 'max' => imagesQuotaMb($cfg), 'mb' => imagesPerDayMb($cfg)];
    return ['ok' => false, 'status' => $status, 'code' => $codes[$key] ?? 'bad_type', 'message' => __($key, $vars)];
}

function imagesUsage(PDO $db, int $userId): array
{
    $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS b FROM image_uploads WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 DAY");
    $st->execute([$userId]);
    $t = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'b' => 0];
    $st = $db->prepare("SELECT COALESCE(SUM(b.bytes), 0) FROM images i JOIN image_blobs b ON b.sha256 = i.blob_sha WHERE i.user_id = ?");
    $st->execute([$userId]);
    return ['today' => (int)$t['n'], 'today_bytes' => (int)$t['b'], 'bytes' => (int)$st->fetchColumn()];
}

function imagesCleanName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = (string)preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
    return mb_substr(trim($name), 0, 120);
}

function imagesShapeOk(int $w, int $h, float $maxRatio): bool
{
    if ($maxRatio <= 0) return true;
    return $w > 0 && $h > 0 && max($w / $h, $h / $w) <= $maxRatio;
}

function imagesStore(PDO $db, array $cfg, ?int $userId, string $bytes, string $name, string $source = 'upload', ?string $sourceUrl = null, array $opts = []): array
{
    $sha = hash('sha256', $bytes);
    $quota = imagesQuotaMb($cfg) * 1048576;
    $usage = ['today' => 0, 'today_bytes' => 0, 'bytes' => 0];
    $ratio = (float)($opts['max_ratio'] ?? 0);
    if ($userId !== null) {
        $st = $db->prepare("SELECT id FROM images WHERE user_id = ? AND blob_sha = ? LIMIT 1");
        $st->execute([$userId, $sha]);
        $have = (int)($st->fetchColumn() ?: 0);
        if ($have > 0) {
            $row = imagesRow($db, $have);
            if ($row !== null && !imagesShapeOk((int)$row['full_w'], (int)$row['full_h'], $ratio)) return imagesRefusal($cfg, 'api.images.shape', 400);
            return ['ok' => true, 'row' => $row, 'existing' => true];
        }
        $usage = imagesUsage($db, $userId);
        if (imagesPerDay($cfg) > 0 && $usage['today'] >= imagesPerDay($cfg)) {
            return imagesRefusal($cfg, 'api.images.daily_limit', 429);
        }
        if (imagesPerDayMb($cfg) > 0 && $usage['today_bytes'] + strlen($bytes) > imagesPerDayMb($cfg) * 1048576) {
            return imagesRefusal($cfg, 'api.images.daily_mb', 429);
        }
        if ($quota > 0 && $usage['bytes'] >= $quota) {
            return imagesRefusal($cfg, 'api.images.quota', 403, '', ['used' => (int)ceil($usage['bytes'] / 1048576)]);
        }
    }
    $st = $db->prepare("SELECT bytes, full_w, full_h FROM image_blobs WHERE sha256 = ?");
    $st->execute([$sha]);
    $known = $st->fetch(PDO::FETCH_ASSOC);
    $size = $known === false ? false : (int)$known['bytes'];
    if ($known !== false && !imagesShapeOk((int)$known['full_w'], (int)$known['full_h'], $ratio)) return imagesRefusal($cfg, 'api.images.shape', 400);
    if ($size === false) {
        $p = imagesProcess($bytes, $cfg, $source === 'copy' ? imagesMaxMb($cfg) * 1048576 : null);
        if (empty($p['ok'])) {
            return imagesRefusal($cfg, (string)($p['error'] ?? 'api.media.store_failed'), (int)($p['status'] ?? 400), (string)($p['detail'] ?? ''));
        }
        if (!imagesShapeOk((int)$p['full_w'], (int)$p['full_h'], $ratio)) return imagesRefusal($cfg, 'api.images.shape', 400);
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
    if ($userId !== null) $db->prepare("INSERT INTO image_uploads (user_id, bytes) VALUES (?, ?)")->execute([$userId, strlen($bytes)]);
    return ['ok' => true, 'row' => imagesRow($db, $newId), 'existing' => false];
}

function imagesRow(PDO $db, int $id): ?array
{
    $st = $db->prepare("SELECT i.id, i.user_id, i.blob_sha, i.name, i.source, i.source_url, i.created_at, i.public_at, i.checked_at,
                               i.removal_at, i.dead_since,
                               (i.checked_at IS NOT NULL AND i.checked_at > NOW() - INTERVAL " . IMAGES_PUBLIC_RECHECK_MINUTES . " MINUTE) AS fresh,
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
        'used' => array_key_exists('used', $r) ? (bool)$r['used'] : $r['public_at'] !== null, 'animated' => (bool)$r['animated'],
        'public' => $r['public_at'] !== null, 'removal' => ($r['removal_at'] ?? null) !== null,
    ];
}

function imagesLimits(PDO $db, array $cfg, int $userId, ?int $maxBytes = null): array
{
    $u = imagesUsage($db, $userId);
    return ['max_bytes' => $maxBytes ?? imagesMaxBytes($cfg), 'max_edge' => imagesMaxEdge($cfg), 'max_mpx' => imagesMaxMpx($cfg),
            'per_day' => imagesPerDay($cfg), 'today' => $u['today'],
            'per_day_bytes' => imagesPerDayMb($cfg) * 1048576, 'today_bytes' => $u['today_bytes'],
            'quota_bytes' => imagesQuotaMb($cfg) * 1048576, 'used_bytes' => $u['bytes']];
}

function imagesList(PDO $db, array $cfg, int $userId, int $page, ?int $maxBytes = null): array
{
    $st = $db->prepare("SELECT COUNT(*) FROM images WHERE user_id = ? AND removal_at IS NULL");
    $st->execute([$userId]);
    $total = (int)$st->fetchColumn();
    $page = max(1, min($page, max(1, (int)ceil($total / IMAGES_PER_PAGE))));
    $st = $db->prepare("SELECT i.id, i.user_id, i.blob_sha, i.name, i.source, i.source_url, i.created_at, i.public_at, i.checked_at, i.removal_at,
                               (SELECT COUNT(*) FROM image_refs r WHERE r.image_id = i.id) AS used,
                               b.mime, b.animated, b.w, b.h, b.full_w, b.full_h, b.bytes
                          FROM images i JOIN image_blobs b ON b.sha256 = i.blob_sha
                         WHERE i.user_id = ? AND i.removal_at IS NULL ORDER BY used DESC, i.id DESC LIMIT ? OFFSET ?");
    $st->bindValue(1, $userId, PDO::PARAM_INT);
    $st->bindValue(2, IMAGES_PER_PAGE, PDO::PARAM_INT);
    $st->bindValue(3, ($page - 1) * IMAGES_PER_PAGE, PDO::PARAM_INT);
    $st->execute();
    $rows = array_map(fn($r) => imagesRowOut($cfg, $r), $st->fetchAll(PDO::FETCH_ASSOC));
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => IMAGES_PER_PAGE, 'limits' => imagesLimits($db, $cfg, $userId, $maxBytes)];
}

function imagesReferenced(PDO $db, int $id, bool $publishedOnly): bool
{
    foreach (imagesRefsWhere($db, $id) as $r) {
        $state = imagesRefState($db, (string)$r['src'], (string)$r['src_key'])['state'];
        if ($publishedOnly ? $state === 'published' : $state !== 'dead') return true;
    }
    return false;
}

function imagesIsPublic(PDO $db, array $row): bool
{
    if ((string)($row['source'] ?? '') === 'copy') return true;
    if (!imagesRefsReady($db)) return $row['public_at'] !== null;
    if ($row['public_at'] !== null && !empty($row['fresh'])) return true;
    return imagesVisibilitySync($db, [(int)$row['id']])['public'] > 0;
}

function imagesServe(PDO $db, int $id, int $w, int $h, string $ext, bool $wantFull, ?int $viewerId, bool $moderator): ?array
{
    $row = imagesRow($db, $id);
    if ($row === null || $ext !== imagesExt($row)) return null;
    $isPage = $w === (int)$row['w'] && $h === (int)$row['h'];
    $isFull = $w === (int)$row['full_w'] && $h === (int)$row['full_h'];
    if (!$isPage && !$isFull) return null;
    $owner = $viewerId !== null && $row['user_id'] !== null && $viewerId === (int)$row['user_id'];
    $public = ($owner || $moderator) ? $row['public_at'] !== null : imagesIsPublic($db, $row);
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

function imagesSend(array $img): void
{
    header('ETag: ' . $img['etag']);
    header('Cache-Control: ' . ($img['public'] ? 'public, max-age=86400' : 'private, no-store'));
    header('X-Content-Type-Options: nosniff');
    header_remove('Expires');
    header_remove('Pragma');
    header_remove('Content-Security-Policy');
    header_remove('Content-Security-Policy-Report-Only');
    header("Content-Security-Policy: default-src 'none'");
    if ($img['public'] && trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $img['etag']) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $img['mime']);
    header('Content-Length: ' . strlen($img['data']));
    header('Content-Disposition: inline; filename="' . $img['name'] . '"');
    header('Accept-Ranges: none');
    echo $img['data'];
    exit;
}

function imagesDelete(PDO $db, int $id): bool
{
    $st = $db->prepare("SELECT blob_sha FROM images WHERE id = ?");
    $st->execute([$id]);
    $sha = $st->fetchColumn();
    if ($sha === false) return false;
    $db->prepare("DELETE FROM images WHERE id = ?")->execute([$id]);
    $db->prepare("DELETE FROM image_refs WHERE image_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM image_remote WHERE image_id = ? AND status = 'done'")->execute([$id]);
    $st = $db->prepare("SELECT COUNT(*) FROM images WHERE blob_sha = ?");
    $st->execute([$sha]);
    if ((int)$st->fetchColumn() === 0) $db->prepare("DELETE FROM image_blobs WHERE sha256 = ?")->execute([$sha]);
    return true;
}

function imagesSweep(PDO $db, array $cfg, bool $force = false): int
{
    if (!$force && imagesRefsReady($db, $cfg) && time() - (int)($cfg['images_swept_at'] ?? 0) < IMAGES_SWEEP_SECONDS) return 0;
    $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('images_swept_at', ?)")->execute([(string)time()]);
    $db->exec("DELETE FROM image_uploads WHERE created_at < NOW() - INTERVAL 2 DAY");
    if (imagesBase($cfg) === '') return 0;
    imagesRefsRebuild($db, $cfg);
    $ids = array_map('intval', $db->query("SELECT DISTINCT image_id FROM image_refs")->fetchAll(PDO::FETCH_COLUMN));
    foreach (array_chunk($ids, 500) as $chunk) imagesVisibilitySync($db, $chunk);
    $db->exec("UPDATE images i SET public_at = NULL, dead_since = NULL WHERE i.source = 'upload' AND i.public_at IS NOT NULL
                 AND NOT EXISTS (SELECT 1 FROM image_refs r WHERE r.image_id = i.id)");
    $hours = imagesUnusedHours($cfg);
    $st = $db->prepare("SELECT i.id FROM images i
                         WHERE (i.created_at < NOW() - INTERVAL ? HOUR AND NOT EXISTS (SELECT 1 FROM image_refs r WHERE r.image_id = i.id))
                            OR (i.dead_since IS NOT NULL AND i.dead_since < NOW() - INTERVAL ? HOUR)
                            OR (i.removal_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM image_refs r WHERE r.image_id = i.id))
                         ORDER BY i.id LIMIT " . IMAGES_SWEEP_BATCH);
    $st->bindValue(1, $hours, PDO::PARAM_INT);
    $st->bindValue(2, $hours, PDO::PARAM_INT);
    $st->execute();
    $gone = 0;
    foreach (array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)) as $id) {
        if (imagesDelete($db, $id)) $gone++;
    }
    return $gone;
}

function imagesLikeEscape(string $s): string
{
    return addcslashes($s, '\\%_');
}

function imagesDomainsClean($raw): array
{
    $items = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string)$raw);
    $out = [];
    foreach ($items ?: [] as $d) {
        if (!is_string($d)) continue;
        $d = strtolower(trim($d));
        if ($d === '') continue;
        if (preg_match('#^[a-z][a-z0-9+.-]*://#', $d)) $d = (string)(parse_url($d, PHP_URL_HOST) ?? '');
        $d = trim(explode('/', $d)[0], " \t.");
        if (str_starts_with($d, '*.')) $d = substr($d, 2);
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $d)) {
            $d = strtolower((string)(idn_to_ascii($d, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: ''));
        }
        if (strlen($d) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $d)) continue;
        $out[$d] = true;
        if (count($out) >= IMAGES_REMOTE_DOMAINS_MAX) break;
    }
    return array_keys($out);
}

function imagesRemoteHosts(PDO $db, bool $fresh = false): array
{
    static $hosts = null;
    if ($hosts !== null && !$fresh) return $hosts;
    $all = [];
    try {
        $st = $db->query("SELECT image_domains FROM api_clients WHERE enabled = 1 AND image_domains <> '' AND image_consent = 1");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            foreach (imagesDomainsClean((string)$raw) as $d) $all[$d] = true;
        }
    } catch (\Throwable $e) {
        $all = [];
    }
    $hosts = array_keys($all);
    return $hosts;
}

function imagesConsentFrom($raw): ?int
{
    if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') return 1;
    if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') return 0;
    return null;
}

function imagesConsentNote(PDO $db, array $client, $raw): array
{
    $v = imagesConsentFrom($raw);
    $was = isset($client['image_consent']) ? (int)$client['image_consent'] : null;
    if ($v === null || $v === $was) return $client;
    $db->prepare("UPDATE api_clients SET image_consent = ?, image_consent_at = NOW() WHERE id = ?")->execute([$v, (int)$client['id']]);
    $client['image_consent'] = $v;
    return $client;
}

function imagesClientState(array $client): array
{
    $domains = imagesDomainsClean((string)($client['image_domains'] ?? ''));
    $state = !$domains ? 'off' : (isset($client['image_consent']) && (int)$client['image_consent'] === 1 ? 'copy' : 'no_consent');
    return ['images' => $state, 'image_domains' => $domains];
}

function imagesHostMatches(string $host, array $domains): bool
{
    $host = strtolower(rtrim($host, '.'));
    if ($host === '') return false;
    foreach ($domains as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) return true;
    }
    return false;
}

function imagesRemoteUrls(string $text, array $domains, string $base = ''): array
{
    if ($text === '' || !$domains || !preg_match_all('~https?://[^\s\[\]"\'<>()]+~i', $text, $m, PREG_OFFSET_CAPTURE)) return [];
    $out = [];
    foreach ($m[0] as [$url, $at]) {
        $url = rtrim((string)$url, '.,;:!?');
        if (strlen($url) > IMAGES_REMOTE_URL_MAX) continue;
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) continue;
        if (!imagesHostMatches((string)$p['host'], $domains) || ($base !== '' && imagesOwnAt($base, $url))) continue;
        $tagged = $at >= 5 && strcasecmp(substr($text, $at - 5, 5), '[img]') === 0;
        if (!$tagged && !preg_match('/\.(?:jpe?g|png|gif|webp|avif|bmp)$/i', (string)($p['path'] ?? ''))) continue;
        $out[$url] = true;
    }
    return array_keys($out);
}

function imagesReplaceUrl(string $text, string $from, string $to): string
{
    if ($from === '' || $from === $to || !str_contains($text, $from)) return $text;
    $re = '~' . preg_quote($from, '~') . '(?=[.,;:!?]*(?:[\s\[\]"\'<>()]|$))~';
    return preg_replace_callback($re, static fn() => $to, $text) ?? $text;
}

function imagesRewriteTargets(): array
{
    return [
        ["SELECT id AS k, description AS t FROM whitelist WHERE description LIKE ?",
         "UPDATE whitelist SET description = ? WHERE id = ? AND description = ?", 'wl'],
        ["SELECT id AS k, description AS t FROM hash_content WHERE description LIKE ?",
         "UPDATE hash_content SET description = ? WHERE id = ? AND description = ?", 'idx'],
        ["SELECT id AS k, description AS t FROM wl_content_edits WHERE description LIKE ?",
         "UPDATE wl_content_edits SET description = ? WHERE id = ? AND description = ?", 'edit'],
        ["SELECT id AS k, body AS t FROM hash_comments WHERE body LIKE ?",
         "UPDATE hash_comments SET body = ? WHERE id = ? AND body = ?", 'cm'],
        ["SELECT CONCAT(page, CHAR(9), lang) AS k, body AS t FROM page_content WHERE body LIKE ?",
         "UPDATE page_content SET body = ? WHERE CONCAT(page, CHAR(9), lang) = ? AND body = ?", 'page'],
    ];
}

function imagesRewrite(PDO $db, string $from, string $to): int
{
    if ($from === '' || $from === $to) return 0;
    $n = 0;
    foreach (imagesRewriteTargets() as [$sel, $sql, $src]) {
        try {
            $st = $db->prepare($sel);
            $st->execute(['%' . imagesLikeEscape($from) . '%']);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) continue;
            $up = $db->prepare($sql);
            foreach ($rows as $r) {
                $old = (string)$r['t'];
                $new = imagesReplaceUrl($old, $from, $to);
                if ($new === $old) continue;
                $up->execute([$new, $r['k'], $old]);
                $hit = $up->rowCount();
                $n += $hit;
                if ($hit < 1) continue;
                if ($src === 'wl' && function_exists('forumDescRewritten')) forumDescRewritten($db, (int)$r['k'], $old, $new);
                imagesRefsTouch($db, null, $src, (string)$r['k']);
            }
        } catch (\Throwable $e) {
            continue;
        }
    }
    return $n;
}

function imagesRemoteEnqueue(PDO $db, array $urls): int
{
    $n = 0;
    $st = $db->prepare("INSERT IGNORE INTO image_remote (url_sha, url, host) VALUES (?, ?, ?)");
    foreach ($urls as $u) {
        $u = (string)$u;
        if ($u === '' || strlen($u) > IMAGES_REMOTE_URL_MAX) continue;
        $st->execute([hash('sha256', $u), $u, mb_substr(strtolower((string)parse_url($u, PHP_URL_HOST)), 0, 255)]);
        $n += $st->rowCount();
    }
    return $n;
}

function imagesRemoteDone(PDO $db, array $cfg, array $urls): array
{
    $out = [];
    if (!$urls || imagesBase($cfg) === '') return $out;
    $st = $db->prepare("SELECT i.id, b.mime, b.w, b.h FROM image_remote r
                          JOIN images i ON i.id = r.image_id JOIN image_blobs b ON b.sha256 = i.blob_sha
                         WHERE r.url_sha = ? AND r.status = 'done'");
    foreach ($urls as $u) {
        $st->execute([hash('sha256', (string)$u)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) $out[(string)$u] = imagesUrl($cfg, (int)$r['id'], (int)$r['w'], (int)$r['h'], imagesExt($r));
    }
    return $out;
}

function imagesRemoteSettle(PDO $db, array $cfg, string $text, bool $queue = true): string
{
    try {
        $hosts = imagesRemoteHosts($db);
        if (!$hosts) return $text;
        $urls = imagesRemoteUrls($text, $hosts, imagesBase($cfg));
        if (!$urls) return $text;
        if ($queue) imagesRemoteEnqueue($db, $urls);
        foreach (imagesRemoteDone($db, $cfg, $urls) as $from => $to) $text = imagesReplaceUrl($text, (string)$from, $to);
    } catch (\Throwable $e) {
        if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) throw $e;
        return $text;
    }
    return $text;
}

function imagesPublicIp(string $ip): bool
{
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | (defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : 0);
    return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
}

function imagesRemoteResolve(string $host): array
{
    $ips = [];
    foreach ((array)@gethostbynamel($host) as $ip) if (is_string($ip) && $ip !== '') $ips[] = $ip;
    if (function_exists('dns_get_record')) {
        foreach ((array)@dns_get_record($host, DNS_AAAA) as $r) {
            if (is_array($r) && !empty($r['ipv6'])) $ips[] = (string)$r['ipv6'];
        }
    }
    return array_values(array_unique($ips));
}

function imagesRemoteFetch(string $url, array $domains, int $maxBytes, array $io = []): array
{
    $p = parse_url($url);
    if (!is_array($p)) return ['ok' => false, 'error' => 'bad_url'];
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host = strtolower((string)($p['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user']) || isset($p['pass'])) return ['ok' => false, 'error' => 'bad_url'];
    $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port !== ($scheme === 'https' ? 443 : 80)) return ['ok' => false, 'error' => 'bad_port'];
    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || !imagesHostMatches($host, $domains)) return ['ok' => false, 'error' => 'not_allowed'];
    $resolve = $io['resolve'] ?? 'imagesRemoteResolve';
    $ips = array_values(array_filter(array_map('strval', (array)$resolve($host)), static fn($ip) => $ip !== ''));
    $public = array_values(array_filter($ips, 'imagesPublicIp'));
    if (!$ips || count($public) !== count($ips)) return ['ok' => false, 'error' => 'private_address'];
    $get = $io['get'] ?? 'imagesRemoteGet';
    $r = $get($url, $host, $port, $public[0], $maxBytes);
    if (!empty($r['ok']) && strlen((string)($r['bytes'] ?? '')) > $maxBytes) return ['ok' => false, 'error' => 'too_big'];
    return is_array($r) ? $r : ['ok' => false, 'error' => 'network'];
}

function imagesRemoteGet(string $url, string $host, int $port, string $ip, int $maxBytes): array
{
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'no_curl'];
    $buf = '';
    $over = false;
    $ch = curl_init($url);
    if ($ch === false) return ['ok' => false, 'error' => 'network'];
    curl_setopt_array($ch, [
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_CONNECTTIMEOUT => IMAGES_REMOTE_CONNECT,
        CURLOPT_TIMEOUT => IMAGES_REMOTE_TIMEOUT,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_USERAGENT => 'TryHackX-Tracker/' . (defined('TRACKER_VERSION') ? TRACKER_VERSION : '1') . ' (image copy)',
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/*;q=0.8'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$buf, &$over, $maxBytes): int {
            if (strlen($buf) + strlen($chunk) > $maxBytes) {
                $over = true;
                return 0;
            }
            $buf .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    if ($over) return ['ok' => false, 'error' => 'too_big'];
    if ($ok === false || $errno !== 0) return ['ok' => false, 'error' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network'];
    if ($code >= 300 && $code < 400) return ['ok' => false, 'error' => 'redirect'];
    if ($code !== 200) return ['ok' => false, 'error' => 'http_' . $code];
    if ($buf === '') return ['ok' => false, 'error' => 'empty'];
    return ['ok' => true, 'bytes' => $buf];
}

function imagesRemoteCopy(PDO $db, array $cfg, string $sha, string $url, int $tries, array $hosts, array $io = []): array
{
    $f = imagesOwnAt(imagesBase($cfg), $url) ? ['ok' => false, 'error' => 'own_site'] : imagesRemoteFetch($url, $hosts, imagesMaxMb($cfg) * 1048576, $io);
    $stored = null;
    if (!empty($f['ok'])) {
        $name = basename((string)parse_url($url, PHP_URL_PATH));
        $stored = imagesStore($db, $cfg, null, (string)$f['bytes'], $name !== '' ? $name : 'image', 'copy', $url);
        if (empty($stored['ok'])) $f = ['ok' => false, 'error' => (string)($stored['code'] ?? 'bad_type')];
    }
    if (empty($f['ok'])) {
        $error = mb_substr((string)($f['error'] ?? 'network'), 0, 64);
        $tries++;
        $final = $tries >= IMAGES_REMOTE_TRIES
              || in_array($error, ['not_allowed', 'own_site', 'bad_url', 'bad_port', 'bad_type', 'too_big', 'too_many_pixels'], true);
        $wait = [5, 30, 120, 720][$tries - 1] ?? 1440;
        $up = $db->prepare("UPDATE image_remote SET tries = ?, error = ?, status = ?, next_at = NOW() + INTERVAL ? MINUTE WHERE url_sha = ?");
        $up->bindValue(1, min(255, $tries), PDO::PARAM_INT);
        $up->bindValue(2, $error);
        $up->bindValue(3, $final ? 'failed' : 'pending');
        $up->bindValue(4, $wait, PDO::PARAM_INT);
        $up->bindValue(5, $sha);
        $up->execute();
        return ['ok' => false, 'error' => $error, 'final' => $final];
    }
    $row = $stored['row'];
    $id = (int)$row['id'];
    $db->prepare("UPDATE images SET public_at = COALESCE(public_at, NOW()) WHERE id = ?")->execute([$id]);
    $db->prepare("UPDATE image_remote SET status = 'done', image_id = ?, error = '', done_at = NOW() WHERE url_sha = ?")->execute([$id, $sha]);
    $to = imagesUrl($cfg, $id, (int)$row['w'], (int)$row['h'], imagesExt($row));
    return ['ok' => true, 'image' => $id, 'url' => $to, 'rewritten' => imagesRemoteSettleRows($db, $cfg, [$sha])];
}

function imagesRemoteTick(PDO $db, array $cfg, array $io = []): array
{
    $out = ['hosts' => 0, 'queued' => 0, 'copied' => 0, 'failed' => 0, 'rewritten' => 0, 'repaired' => 0, 'error' => null];
    try {
        $out['repaired'] = imagesOwnCopiesRepair($db, $cfg)['images'];
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    $hosts = imagesRemoteHosts($db, true);
    $out['hosts'] = count($hosts);
    if (!$hosts) return $out;
    if (imagesBase($cfg) === '') {
        $out['error'] = 'site_url';
        return $out;
    }
    try {
        if (!empty($io['force_scan'])) $out['queued'] = imagesRefsRebuild($db, $cfg)['queued'];
        $start = microtime(true);
        $budget = (float)($io['budget'] ?? IMAGES_REMOTE_BUDGET);
        $where = [];
        $args = [];
        foreach ($hosts as $h) {
            $where[] = 'host = ? OR host LIKE ?';
            array_push($args, $h, '%.' . imagesLikeEscape($h));
        }
        $limit = IMAGES_REMOTE_PER_RUN;
        $st = $db->prepare("SELECT url_sha, url, tries FROM image_remote WHERE status = 'pending' AND next_at <= NOW() AND ("
                           . implode(' OR ', $where) . ") ORDER BY next_at, created_at LIMIT " . $limit);
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (microtime(true) - $start > $budget) break;
            $c = imagesRemoteCopy($db, $cfg, (string)$r['url_sha'], (string)$r['url'], (int)$r['tries'], $hosts, $io);
            if (!empty($c['ok'])) {
                $out['copied']++;
                $out['rewritten'] += (int)$c['rewritten'];
            } else {
                $out['failed']++;
            }
        }
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

function imagesOwnCopiesRepair(PDO $db, array $cfg): array
{
    $out = ['queue' => 0, 'texts' => 0, 'images' => 0];
    $base = imagesBase($cfg);
    $host = strtolower((string)(parse_url($base, PHP_URL_HOST) ?? ''));
    if ($host === '') return $out;
    $st = $db->prepare("SELECT url_sha, url FROM image_remote WHERE host = ?");
    $st->execute([$host]);
    $queued = [];
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $sha => $url) {
        if (imagesOwnAt($base, (string)$url)) $queued[] = (string)$sha;
    }
    if (!$queued) return $out;
    $parent = [];
    foreach ($db->query("SELECT id, source_url FROM images WHERE source = 'copy' AND source_url LIKE '%/i/%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $url) {
        $o = imagesOwnStoreUrl($base, (string)$url);
        if ($o !== null && $o['id'] < (int)$id) $parent[(int)$id] = $o['id'];
    }
    $rows = [];
    foreach (array_chunk(array_values(array_unique(array_merge(array_keys($parent), array_values($parent)))), 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT i.id, b.mime, b.w, b.h, b.full_w, b.full_h FROM images i JOIN image_blobs b ON b.sha256 = i.blob_sha WHERE i.id IN ($in)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = $r;
    }
    $target = [];
    foreach (array_keys($parent) as $id) {
        $root = null;
        for ($cur = $id, $hops = 0; isset($parent[$cur]) && $hops < 10000; $hops++) {
            $cur = $parent[$cur];
            if (isset($rows[$cur])) $root = $cur;
        }
        if ($root !== null && isset($rows[$id])) $target[$id] = $root;
    }
    $fix = static function (string $text) use ($cfg, $base, $target, $rows): string {
        return (string)(preg_replace_callback('~https?://[^\s\[\]"\'<>()]+~i', static function (array $m) use ($cfg, $base, $target, $rows): string {
            $url = rtrim($m[0], '.,;:!?');
            $o = imagesOwnStoreUrl($base, $url);
            if ($o === null || !isset($target[$o['id']])) return $m[0];
            $from = $rows[$o['id']];
            $to = $rows[$target[$o['id']]];
            $full = $o['w'] !== (int)$from['w'] || $o['h'] !== (int)$from['h'];
            return imagesUrl($cfg, (int)$to['id'], (int)$to[$full ? 'full_w' : 'w'], (int)$to[$full ? 'full_h' : 'h'], imagesExt($to)) . substr($m[0], strlen($url));
        }, $text) ?? $text);
    };
    if ($target) {
        foreach (imagesRewriteTargets() as $t) {
            [$sel, $sql] = $t;
            $st = $db->prepare($sel);
            $st->execute(['%/i/%']);
            $up = $db->prepare($sql);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $old = (string)$r['t'];
                $new = $fix($old);
                if ($new === $old) continue;
                $up->execute([$new, $r['k'], $old]);
                if ($up->rowCount() < 1) continue;
                $out['texts']++;
                if ($t[2] === 'wl' && function_exists('forumDescRewritten')) forumDescRewritten($db, (int)$r['k'], $old, $new);
                imagesRefsTouch($db, $cfg, $t[2], (string)$r['k']);
            }
        }
    }
    foreach (array_chunk($queued, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("DELETE FROM image_remote WHERE url_sha IN ($in)");
        $st->execute($chunk);
        $out['queue'] += $st->rowCount();
    }
    foreach (array_keys($target) as $id) {
        if (!imagesReferenced($db, $id, false) && imagesDelete($db, $id)) $out['images']++;
    }
    return $out;
}

function imagesPanelSummary(PDO $db): array
{
    $r = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(public_at IS NOT NULL), 0) AS pub, COALESCE(SUM(source = 'copy'), 0) AS copies FROM images")->fetch(PDO::FETCH_ASSOC) ?: [];
    $bytes = (int)$db->query("SELECT COALESCE(SUM(bytes), 0) FROM image_blobs")->fetchColumn();
    $queue = ['pending' => 0, 'failed' => 0, 'done' => 0, 'ignored' => 0];
    try {
        foreach ($db->query("SELECT status, COUNT(*) AS n FROM image_remote GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $queue[(string)$x['status']] = (int)$x['n'];
        }
    } catch (\Throwable $e) {
        $queue = ['pending' => 0, 'failed' => 0, 'done' => 0, 'ignored' => 0];
    }
    return ['images' => (int)($r['n'] ?? 0), 'public' => (int)($r['pub'] ?? 0), 'copies' => (int)($r['copies'] ?? 0),
            'bytes' => $bytes, 'queue' => $queue];
}

function imagesPanelRowOut(array $cfg, array $r): array
{
    return imagesRowOut($cfg, $r) + [
        'user_id' => $r['user_id'] === null ? null : (int)$r['user_id'],
        'user' => $r['username'] !== null ? (string)$r['username'] : '',
        'source' => (string)$r['source'],
        'source_url' => (string)($r['source_url'] ?? ''),
        'public_at' => $r['public_at'] !== null ? (string)$r['public_at'] : '',
    ];
}

function imagesPanelList(PDO $db, array $cfg, array $q): array
{
    $where = [];
    $args = [];
    $state = (string)($q['state'] ?? '');
    if ($state === 'public') $where[] = 'i.public_at IS NOT NULL';
    elseif ($state === 'private') $where[] = 'i.public_at IS NULL';
    elseif ($state === 'copied') $where[] = "i.source = 'copy'";
    elseif ($state === 'unused') $where[] = 'NOT EXISTS (SELECT 1 FROM image_refs r WHERE r.image_id = i.id)';
    elseif ($state === 'removal') $where[] = 'i.removal_at IS NOT NULL';
    $find = trim(mb_substr((string)($q['q'] ?? ''), 0, 120));
    if ($find !== '') {
        if (preg_match('/^#?(\d{1,10})$/', $find, $m)) {
            $where[] = 'i.id = ?';
            $args[] = (int)$m[1];
        } else {
            $where[] = '(u.username LIKE ? OR i.name LIKE ? OR i.source_url LIKE ?)';
            $like = '%' . imagesLikeEscape($find) . '%';
            array_push($args, $like, $like, $like);
        }
    }
    $where = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $orderBy = ['old' => ' ORDER BY i.id ASC', 'big' => ' ORDER BY b.bytes DESC, i.id DESC'][(string)($q['sort'] ?? '')] ?? ' ORDER BY i.id DESC';
    $st = $db->prepare("SELECT COUNT(*) FROM images i JOIN image_blobs b ON b.sha256 = i.blob_sha LEFT JOIN users u ON u.id = i.user_id" . $where);
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $per = (int)($q['per'] ?? 0);
    $per = $per > 0 ? max(12, min(120, $per)) : IMAGES_PANEL_PER_PAGE;
    $pages = max(1, (int)ceil($total / $per));
    if (isset($q['offset']) && is_numeric($q['offset'])) {
        $offset = max(0, min((int)$q['offset'], max(0, $total - 1)));
        $offset -= $offset % $per;
    } else {
        $offset = (max(1, min((int)($q['page'] ?? 1), $pages)) - 1) * $per;
    }
    $page = intdiv($offset, $per) + 1;
    $sql = "SELECT i.id, i.user_id, i.blob_sha, i.name, i.source, i.source_url, i.created_at, i.public_at, i.checked_at, i.removal_at,
                   EXISTS (SELECT 1 FROM image_refs r WHERE r.image_id = i.id) AS used,
                   b.mime, b.animated, b.w, b.h, b.full_w, b.full_h, b.bytes, u.username
              FROM images i JOIN image_blobs b ON b.sha256 = i.blob_sha LEFT JOIN users u ON u.id = i.user_id" . $where . $orderBy . " LIMIT ? OFFSET ?";
    $st = $db->prepare($sql);
    $k = 1;
    foreach ($args as $a) $st->bindValue($k++, $a, is_int($a) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $st->bindValue($k++, $per, PDO::PARAM_INT);
    $st->bindValue($k, $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = array_map(fn($r) => imagesPanelRowOut($cfg, $r), $st->fetchAll(PDO::FETCH_ASSOC));
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $per, 'offset' => $offset];
}

function imagesWhereUsed(PDO $db, int $id): array
{
    if ($id <= 0) return [];
    $out = [];
    foreach (imagesRefsWhere($db, $id) as $r) {
        $src = (string)$r['src'];
        $key = (string)$r['src_key'];
        try {
            if ($src === 'page') {
                [$page, $lang] = array_pad(explode("\t", $key, 2), 2, '');
                $st = $db->prepare("SELECT enabled FROM page_content WHERE page = ? AND lang = ?");
                $st->execute([$page, $lang]);
                $en = $st->fetchColumn();
                if ($en === false) continue;
                $out[] = ['kind' => 'page', 'id' => 0, 'hash' => '', 'page' => $page, 'lang' => $lang, 'state' => (int)$en === 1 ? 'enabled' : 'disabled'];
                continue;
            }
            $sql = match ($src) {
                'wl'   => "SELECT info_hash, content_status AS state FROM whitelist WHERE id = ?",
                'idx'  => "SELECT info_hash, content_status AS state FROM hash_content WHERE id = ?",
                'edit' => "SELECT info_hash, status AS state FROM wl_content_edits WHERE id = ?",
                default => "SELECT info_hash, status AS state FROM hash_comments WHERE id = ?",
            };
            $st = $db->prepare($sql);
            $st->execute([(int)$key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            $kind = match ($src) { 'wl', 'idx' => 'description', 'edit' => 'proposal', default => 'comment' };
            $out[] = ['kind' => $kind, 'id' => (int)$key, 'hash' => (string)$row['info_hash'], 'state' => (string)$row['state']];
        } catch (\Throwable $e) {
            continue;
        }
    }
    return array_slice($out, 0, IMAGES_USED_MAX);
}

function imagesPanelDelete(PDO $db, int $id, ?array $cfg = null): bool
{
    $row = imagesRow($db, $id);
    if ($row === null) return false;
    try {
        $cfg = imagesRefsCfg($db, $cfg);
        $st = $db->prepare("SELECT url_sha, url FROM image_remote WHERE image_id = ?");
        $st->execute([$id]);
        $back = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($back) {
            $db->prepare("UPDATE image_remote SET status = 'ignored', error = 'removed', image_id = NULL WHERE image_id = ?")->execute([$id]);
            $page = imagesUrl($cfg, $id, (int)$row['w'], (int)$row['h'], imagesExt($row));
            $full = imagesUrl($cfg, $id, (int)$row['full_w'], (int)$row['full_h'], imagesExt($row));
            $to = (string)reset($back);
            foreach (imagesRefsWhere($db, $id) as $r) {
                $text = imagesRefsTextOf($db, (string)$r['src'], (string)$r['src_key']);
                if ($text === null) continue;
                $new = imagesReplaceUrl(imagesReplaceUrl($text, $full . '?full=1', $to), $full, $to);
                $new = imagesReplaceUrl($new, $page . '?full=1', $to);
                $new = imagesReplaceUrl($new, $page, $to);
                if ($new !== $text) imagesRefsWrite($db, (string)$r['src'], (string)$r['src_key'], $text, $new, $cfg);
            }
        }
    } catch (\Throwable $e) {
        error_log('[images] write back before delete of ' . $id . ': ' . $e->getMessage());
    }
    return imagesDelete($db, $id);
}

function imagesRemoteList(PDO $db, string $status, int $page): array
{
    $status = in_array($status, ['pending', 'failed', 'done', 'ignored'], true) ? $status : 'pending';
    $st = $db->prepare("SELECT COUNT(*) FROM image_remote WHERE status = ?");
    $st->execute([$status]);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / IMAGES_REMOTE_PER_PAGE));
    $page = max(1, min($page, $pages));
    $st = $db->prepare("SELECT url_sha, url, host, status, tries, error, image_id, created_at, next_at, done_at
                          FROM image_remote WHERE status = ? ORDER BY created_at DESC, url_sha LIMIT ? OFFSET ?");
    $st->bindValue(1, $status);
    $st->bindValue(2, IMAGES_REMOTE_PER_PAGE, PDO::PARAM_INT);
    $st->bindValue(3, ($page - 1) * IMAGES_REMOTE_PER_PAGE, PDO::PARAM_INT);
    $st->execute();
    $rows = array_map(static fn($r) => [
        'id' => (string)$r['url_sha'], 'url' => (string)$r['url'], 'host' => (string)$r['host'], 'status' => (string)$r['status'],
        'tries' => (int)$r['tries'], 'error' => (string)$r['error'], 'image_id' => $r['image_id'] === null ? null : (int)$r['image_id'],
        'created_at' => (string)$r['created_at'], 'next_at' => (string)$r['next_at'], 'done_at' => (string)($r['done_at'] ?? ''),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    return ['status' => $status, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => IMAGES_REMOTE_PER_PAGE];
}

function imagesRemoteRetry(PDO $db, string $sha): bool
{
    $st = $db->prepare("UPDATE image_remote SET status = 'pending', tries = 0, error = '', next_at = NOW() WHERE url_sha = ? AND status IN ('failed', 'ignored')");
    $st->execute([$sha]);
    return $st->rowCount() === 1;
}

function imagesRemoteForget(PDO $db, string $sha): bool
{
    $st = $db->prepare("UPDATE image_remote SET status = 'ignored', error = 'removed', next_at = NOW() WHERE url_sha = ? AND status IN ('pending', 'failed')");
    $st->execute([$sha]);
    return $st->rowCount() === 1;
}
