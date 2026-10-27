<?php
require_once __DIR__ . '/images.php';

const PROFILE_IMAGES_PER_DEFAULT = 30;

function profileImagesEnabled(array $cfg): bool
{
    return usersEnabled($cfg) && ($cfg['images_gallery_enabled'] ?? '1') === '1';
}

function profileImagesShownTo(PDO $db, array $cfg, array $owner, ?array $viewer): bool
{
    if (!profileImagesEnabled($cfg) || $viewer === null) return false;
    $ownerId = (int)($owner['id'] ?? 0);
    $viewerId = (int)($viewer['id'] ?? 0);
    if ($ownerId <= 0 || $viewerId <= 0) return false;
    if ($ownerId === $viewerId) return true;
    if (($owner['status'] ?? '') !== 'active') return false;
    if (!profilesEnabled($cfg)) return false;
    if (!userIdHasPermission($db, $cfg, $viewerId, 'favourites.view_others')) return false;
    if (function_exists('profileHiddenFrom') && profileHiddenFrom($db, $ownerId, $viewerId)) return false;
    if ((int)($owner['images_public'] ?? 0) !== 1) return false;
    return userIdHasGrantedPermission($db, $cfg, $ownerId, 'images.public');
}

function profileImagesContext(PDO $db, array $cfg, ?array $viewer): array
{
    $uid = $viewer !== null ? (int)($viewer['id'] ?? 0) : 0;
    $on = profileImagesEnabled($cfg) && $uid > 0;
    $has = false;
    if ($on) {
        $st = $db->prepare("SELECT 1 FROM images WHERE user_id = ? LIMIT 1");
        $st->execute([$uid]);
        $has = $st->fetchColumn() !== false || imagesMayUpload($db, $cfg, $viewer)
            || (function_exists('commentImagesMayUpload') && commentImagesMayUpload($db, $cfg, $viewer));
    }
    $grant = $on && $has && userIdHasGrantedPermission($db, $cfg, $uid, 'images.public');
    return ['enabled' => $on && $has, 'may_publish' => $grant, 'publish_blocked' => $on && $has && !$grant];
}

function profileImagesList(PDO $db, array $cfg, int $ownerId, bool $own, array $q): array
{
    imagesRefsEnsure($db, $cfg);
    $filter = $own ? (string)($q['filter'] ?? '') : '';
    if (!in_array($filter, ['used', 'unused', 'removal'], true)) $filter = '';
    $where = match (true) {
        !$own => "i.user_id = ? AND i.source = 'upload' AND i.public_at IS NOT NULL AND i.removal_at IS NULL",
        $filter === 'removal' => "i.user_id = ? AND i.removal_at IS NOT NULL",
        $filter === 'used' => "i.user_id = ? AND i.removal_at IS NULL AND EXISTS (SELECT 1 FROM image_refs r WHERE r.image_id = i.id)",
        $filter === 'unused' => "i.user_id = ? AND i.removal_at IS NULL AND NOT EXISTS (SELECT 1 FROM image_refs r WHERE r.image_id = i.id)",
        default => "i.user_id = ? AND i.removal_at IS NULL",
    };
    $st = $db->prepare("SELECT COUNT(*) FROM images i WHERE " . $where);
    $st->execute([$ownerId]);
    $total = (int)$st->fetchColumn();
    $per = (int)($q['per'] ?? 0);
    $per = $per > 0 ? max(6, min(60, $per)) : PROFILE_IMAGES_PER_DEFAULT;
    $offset = max(0, min((int)($q['offset'] ?? 0), max(0, $total - 1)));
    $offset -= $offset % $per;
    $st = $db->prepare("SELECT i.id, i.user_id, i.blob_sha, i.name, i.source, i.source_url, i.created_at, i.public_at, i.checked_at, i.removal_at,
                               (SELECT COUNT(*) FROM image_refs r WHERE r.image_id = i.id) AS used,
                               b.mime, b.animated, b.w, b.h, b.full_w, b.full_h, b.bytes
                          FROM images i JOIN image_blobs b ON b.sha256 = i.blob_sha
                         WHERE " . $where . " ORDER BY i.id DESC LIMIT ? OFFSET ?");
    $st->bindValue(1, $ownerId, PDO::PARAM_INT);
    $st->bindValue(2, $per, PDO::PARAM_INT);
    $st->bindValue(3, $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $o = imagesRowOut($cfg, $r) + ['uses' => (int)$r['used']];
        if (!$own) {
            $o['name'] = '';
            unset($o['used'], $o['uses'], $o['public'], $o['removal']);
        }
        $rows[] = $o;
    }
    return ['rows' => $rows, 'total' => $total, 'page' => intdiv($offset, $per) + 1, 'pages' => max(1, (int)ceil($total / $per)),
            'per_page' => $per, 'offset' => $offset, 'filter' => $filter];
}

function profileImagesWhere(PDO $db, int $imageId): array
{
    $out = [];
    foreach (imagesWhereUsed($db, $imageId) as $u) {
        $hash = (string)$u['hash'];
        $u['name'] = $hash !== '' && function_exists('commentTorrentName') ? commentTorrentName($db, $hash) : '';
        if ($u['kind'] === 'comment' && function_exists('commentLink')) $u['link'] = commentLink($hash, (int)$u['id']);
        elseif ($hash !== '') $u['link'] = '?action=search&hash=' . $hash;
        else $u['link'] = '?action=' . rawurlencode((string)($u['page'] ?? ''));
        $out[] = $u;
    }
    return $out;
}
