<?php

const IMAGES_PLACES = ['pm', 'shout', 'bio', 'list', 'mod'];
const IMAGES_PLACE_DEFAULT = ['pm' => 4, 'shout' => 1, 'bio' => 2, 'list' => 4, 'mod' => 4];
const IMAGES_PLACE_MAX = 10;
const IMAGES_PLACE_SOURCES = ['pm', 'shout', 'bio', 'list', 'warn', 'crep', 'mrep'];

function imagesPlaceMax(array $cfg, string $place): int
{
    if (!in_array($place, IMAGES_PLACES, true)) return 0;
    $v = match ($place) {
        'pm'    => $cfg['images_in_pm'] ?? null,
        'shout' => $cfg['images_in_shout'] ?? null,
        'bio'   => $cfg['images_in_bio'] ?? null,
        'list'  => $cfg['images_in_list'] ?? null,
        default => $cfg['images_in_mod'] ?? null,
    };
    return is_numeric($v) ? max(0, min(IMAGES_PLACE_MAX, (int)$v)) : IMAGES_PLACE_DEFAULT[$place];
}

function imagesPlaceMayUpload(PDO $db, array $cfg, ?array $user, string $place): bool
{
    $uid = (int)($user['id'] ?? 0);
    return $uid > 0 && usersEnabled($cfg) && $place !== 'mod' && imagesPlaceMax($cfg, $place) > 0
        && userIdHasPermission($db, $cfg, $uid, 'images.upload');
}

function imagesPlacesFor(PDO $db, array $cfg, ?array $user): array
{
    $out = [];
    foreach (IMAGES_PLACES as $p) {
        if (imagesPlaceMayUpload($db, $cfg, $user, $p)) $out[$p] = imagesPlaceMax($cfg, $p);
    }
    return $out;
}

function imagesPlacePictures(array $cfg, string $html): array
{
    $own = [];
    $foreign = 0;
    $base = imagesBase($cfg);
    if (str_contains($html, 'rt-img') && preg_match_all('#<img class="rt-img[^"]*"[^>]*?\ssrc="([^"]*)"#', $html, $m)) {
        foreach ($m[1] as $src) {
            $o = $base !== '' ? imagesOwnStoreUrl($base, html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
            if ($o === null) $foreign++;
            else $own[(int)$o['id']] = true;
        }
    }
    return ['own' => array_keys($own), 'foreign' => $foreign];
}

function imagesPlaceProblem(PDO $db, array $cfg, string $place, string $html, ?array $writer, array $keep = []): ?string
{
    $p = imagesPlacePictures($cfg, $html);
    if (!$p['own']) return null;
    $max = imagesPlaceMax($cfg, $place);
    $new = array_values(array_diff($p['own'], array_map('intval', $keep)));
    if (!$new) return null;
    if ($max === 0) return __('api.images.place_off');
    if (count($p['own']) > $max) return __('api.images.place_too_many', ['n' => count($p['own']), 'max' => $max]);
    $uid = (int)($writer['id'] ?? 0);
    $mayUpload = $uid > 0 && userIdHasPermission($db, $cfg, $uid, 'images.upload');
    $mayLink = $uid > 0 && userIdHasPermission($db, $cfg, $uid, 'images.link');
    foreach ($new as $id) {
        $r = imagesRow($db, $id);
        if ($r === null || $r['removal_at'] !== null) return __('api.images.place_gone');
        $mine = $r['user_id'] !== null && (int)$r['user_id'] === $uid;
        $public = (string)$r['source'] === 'copy' || $r['public_at'] !== null;
        if ($mine ? !$mayUpload : !($mayLink && $public)) return __('api.images.place_not_yours');
    }
    return null;
}

function imagesPlaceCheck(PDO $db, array $cfg, string $place, string $text, string $format, ?array $writer, string $before = ''): ?string
{
    if ($format === 'plain' || !str_contains($text, '/i/')) return null;
    $html = richtextRender($text, $format, $cfg, true);
    $keep = $before !== '' && str_contains($before, '/i/') ? imagesPlacePictures($cfg, richtextRender($before, $format, $cfg, true))['own'] : [];
    return imagesPlaceProblem($db, $cfg, $place, $html, $writer, $keep);
}

function imagesPlaceModProblem(array $cfg, string $html): ?string
{
    $p = imagesPlacePictures($cfg, $html);
    if ($p['foreign'] > 0 || count($p['own']) > imagesPlaceMax($cfg, 'mod')) return __('api.report_text.no_images');
    return null;
}

function imagesPlaceHtml(array $cfg, string $html, bool $foreignAsLink = true): string
{
    if (!str_contains($html, 'rt-img')) return $html;
    $base = imagesBase($cfg);
    return preg_replace_callback('#<img class="rt-img[^"]*"[^>]*?\ssrc="([^"]*)"[^>]*>#', function ($m) use ($cfg, $base, $foreignAsLink) {
        $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($base !== '' && imagesOwnStoreUrl($base, $url) !== null) return $m[0];
        if (!$foreignAsLink) return '';
        $label = htmlspecialchars((string)(parse_url($url, PHP_URL_HOST) ?: $url), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attrs = function_exists('richtextLinkAttrs') ? richtextLinkAttrs($url, $cfg) : '';
        if ($attrs === '') return $label;
        return '<a' . $attrs . ' class="rt-img-link"><i class="bi bi-image" aria-hidden="true"></i> ' . $label . '</a>';
    }, $html) ?? $html;
}

function imagesPlaceTextSql(string $src): ?string
{
    return match ($src) {
        'pm'    => "SELECT body FROM user_messages WHERE id = ?",
        'shout' => "SELECT body FROM shouts WHERE id = ? AND deleted_at IS NULL",
        'bio'   => "SELECT bio FROM users WHERE id = ?",
        'list'  => "SELECT description FROM user_lists WHERE id = ?",
        'warn'  => "SELECT reason FROM user_warnings WHERE id = ?",
        'crep'  => "SELECT reply FROM content_reports WHERE id = ?",
        'mrep'  => "SELECT reply FROM message_reports WHERE id = ?",
        default => null,
    };
}

function imagesPlaceScanSql(string $src): string
{
    return match ($src) {
        'pm'    => "SELECT id AS k, body AS t FROM user_messages WHERE id > ? AND body LIKE '%/i/%' ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
        'shout' => "SELECT id AS k, body AS t FROM shouts WHERE id > ? AND deleted_at IS NULL AND body LIKE '%/i/%' ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
        'bio'   => "SELECT id AS k, bio AS t FROM users WHERE id > ? AND bio LIKE '%/i/%' ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
        'list'  => "SELECT id AS k, description AS t FROM user_lists WHERE id > ? AND description LIKE '%/i/%' ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
        'warn'  => "SELECT id AS k, reason AS t FROM user_warnings WHERE id > ? AND reason LIKE '%/i/%' ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
        'crep'  => "SELECT id AS k, reply AS t FROM content_reports WHERE id > ? AND reply LIKE '%/i/%' ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
        default => "SELECT id AS k, reply AS t FROM message_reports WHERE id > ? AND reply LIKE '%/i/%' ORDER BY id LIMIT " . IMAGES_REFS_SCAN_BATCH,
    };
}

function imagesPlaceFriends(PDO $db, int $uid): array
{
    $st = $db->prepare("SELECT friend_id FROM user_friends WHERE user_id = ? AND status = 'accepted'
                        UNION SELECT user_id FROM user_friends WHERE friend_id = ? AND status = 'accepted'");
    $st->execute([$uid, $uid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function imagesPlaceRefState(PDO $db, string $src, int $id): array
{
    $dead = ['state' => 'dead', 'author' => null, 'trusted' => false, 'exists' => false, 'viewers' => []];
    $sql = match ($src) {
        'pm'    => "SELECT m.sender_id AS a, t.u_low AS v1, t.u_high AS v2, 'private' AS vis FROM user_messages m JOIN message_threads t ON t.id = m.thread_id WHERE m.id = ?",
        'shout' => "SELECT user_id AS a, NULL AS v1, NULL AS v2, IF(deleted_at IS NULL, 'members', 'dead') AS vis FROM shouts WHERE id = ?",
        'bio'   => "SELECT id AS a, NULL AS v1, NULL AS v2, 'members' AS vis FROM users WHERE id = ?",
        'list'  => "SELECT user_id AS a, user_id AS v1, NULL AS v2, visibility AS vis FROM user_lists WHERE id = ?",
        'warn'  => "SELECT NULL AS a, user_id AS v1, NULL AS v2, 'private' AS vis FROM user_warnings WHERE id = ?",
        'crep'  => "SELECT NULL AS a, reporter_id AS v1, NULL AS v2, 'private' AS vis FROM content_reports WHERE id = ?",
        'mrep'  => "SELECT NULL AS a, reporter_id AS v1, NULL AS v2, 'private' AS vis FROM message_reports WHERE id = ?",
        default => null,
    };
    if ($sql === null) return $dead;
    $st = $db->prepare($sql);
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || (string)$r['vis'] === 'dead') return $dead;
    $vis = (string)$r['vis'];
    $viewers = array_values(array_filter([(int)($r['v1'] ?? 0), (int)($r['v2'] ?? 0)]));
    if ($src === 'list' && $vis === 'friends') $viewers = array_merge($viewers, imagesPlaceFriends($db, (int)$r['v1']));
    $members = $vis === 'members' || ($src === 'list' && $vis === 'public');
    return ['state' => $members ? 'members' : 'private', 'author' => $r['a'] !== null ? (int)$r['a'] : null, 'trusted' => false,
            'exists' => true, 'viewers' => $viewers];
}

function imagesPlaceViewerMay(PDO $db, int $imageId, int $viewerId): bool
{
    if ($viewerId <= 0 || !imagesRefsReady($db)) return false;
    foreach (imagesRefsWhere($db, $imageId) as $r) {
        if (!in_array((string)$r['src'], IMAGES_PLACE_SOURCES, true)) continue;
        $s = imagesPlaceRefState($db, (string)$r['src'], (int)$r['src_key']);
        if ($s['state'] === 'members') return true;
        if ($s['state'] === 'private' && in_array($viewerId, $s['viewers'], true)) return true;
    }
    return false;
}

function imagesPlaceLink(string $kind): string
{
    return match ($kind) {
        'pm'    => '?action=account#messages',
        'list'  => '?action=account#lists',
        'shout' => '',
        default => '?action=account',
    };
}
