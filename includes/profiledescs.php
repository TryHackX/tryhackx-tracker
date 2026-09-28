<?php
/**
 * The descriptions a member wrote, listed (v81, 1.70.0): the Descriptions tab of the account page,
 * straight after Likes / Ratings, and the section of the same name on a public profile, straight after
 * Likes / Ratings there too — the shape includes/profilevotes.php gave the likes.
 *
 * ── which descriptions ───────────────────────────────────────────────────────────────────────────
 * Every described torrent, in either home (includes/content.php: a registered torrent's whitelist row,
 * or the hash_content row of one the tracker has only seen), that CREDITS the member: as its author of
 * record — the first of its credit chain — or as a co-author, with the share of the text their edits
 * changed, added up. The owner sees theirs in every state (published, waiting for a moderator, turned
 * down) and, beside them, their own proposals still waiting or turned down — an edit or a rewrite of
 * somebody else's words is something they wrote too. The version a rewrite replaced, which the queue
 * keeps as a "rejected" proposal of its own author (CONTENT_ARCHIVE_NOTE), is not a proposal anybody
 * made and is left out. Anybody else sees only what is published, of a hash that is not banned, and a
 * registered torrent's only if they may see registered torrents at all (whitelist.view, as the Info
 * panel asks).
 *
 * ── one bounded read ────────────────────────────────────────────────────────────────────────────
 * The chain is JSON on the home row, and this project reads JSON in PHP, not in SQL: each home is asked
 * for the rows whose author is the member OR whose chain's text names them (contentCreditsLikeFor() —
 * a LIKE on the fixed text contentCreditsEncode() writes, a prefilter the PHP decode then settles), at
 * most PROFILE_DESCS_SCAN_MAX of each, newest first. Everything after that — what the member is to each
 * row, the search, the order, the page — is done on that one array, so the count and the page agree by
 * construction. Described rows are what the prefilter walks, and there are as many of those as people
 * have written descriptions; a member credited on more than two thousand is shown their newest two
 * thousand.
 *
 * ── who may see it ──────────────────────────────────────────────────────────────────────────────
 * profileDescsShownTo() is THE answer, asked by the profile page and by api/user_descriptions.php
 * alike: the site's switch and the descriptions under it; for somebody else's list everything that
 * opens their profile, the reader's own right to read descriptions (content.view), the owner's group's
 * GRANT of content.public (never the administrator's blanket), their own yes (users.descriptions_public,
 * which starts as no) — and their NAME shown on their descriptions (users.content_credit_public): a
 * list of what a member wrote, under their name, would say exactly what that switch keeps quiet.
 */

require_once __DIR__ . '/lists.php';   // listDescExcerpt(): the plain excerpt a list's card shows

const PROFILE_DESCS_PER_PAGE     = 25;
const PROFILE_DESCS_PER_PAGE_MAX = 100;
const PROFILE_DESCS_PAGE_MAX     = 100000;
const PROFILE_DESCS_SEARCH_MAX   = 120;
const PROFILE_DESCS_SCAN_MAX     = 2000;     // rows read per source (each home, the proposals), newest first
const PROFILE_DESCS_EXCERPT      = 160;
const PROFILE_DESCS_SORTS        = ['date', 'name', 'role'];

/**
 * The feature at all: accounts, the switch in Settings → Profiles, and descriptions or source links
 * themselves (contentEnabled()). With both of those off there is nothing a member could be writing,
 * so the tab and the section are drawn nowhere — whatever the switch says.
 */
function profileDescsEnabled(array $cfg): bool
{
    return usersEnabled($cfg)
        && (($cfg['profile_descriptions_enabled'] ?? '1') === '1')
        && function_exists('contentEnabled') && contentEnabled($cfg);
}

/**
 * May $viewer see $owner's list? The one decision, for the profile page and the endpoint — see the
 * header for every part of it. The reader's rights are asked of their ACCOUNT, not of the session.
 */
function profileDescsShownTo(PDO $db, array $cfg, array $owner, ?array $viewer): bool
{
    if (!profileDescsEnabled($cfg) || $viewer === null) return false;
    $ownerId = (int)($owner['id'] ?? 0);
    $viewerId = (int)($viewer['id'] ?? 0);
    if ($ownerId <= 0 || $viewerId <= 0) return false;
    if ($ownerId === $viewerId) return true;
    if (($owner['status'] ?? '') !== 'active') return false;
    if (!profilesEnabled($cfg)) return false;
    if (!userIdHasPermission($db, $cfg, $viewerId, 'favourites.view_others')) return false;
    if (!userIdHasPermission($db, $cfg, $viewerId, 'content.view')) return false;
    if (function_exists('profileHiddenFrom') && profileHiddenFrom($db, $ownerId, $viewerId)) return false;
    if ((int)($owner['descriptions_public'] ?? 0) !== 1) return false;
    if (!contentCreditPublic($owner)) return false;
    return userIdHasGrantedPermission($db, $cfg, $ownerId, 'content.public');
}

/**
 * What the account page needs, in one call (profileVotesContext()'s shape): whether the tab is drawn,
 * and what the two privacy switches are drawn from.
 *   credit_ok       — the name switch (users.content_credit_public) is drawn: accounts and descriptions
 *                     are on. It is about every place that credits the member, so it does not wait for
 *                     the list feature;
 *   enabled         — the tab (the list feature is on);
 *   public_ok, may_publish, publish_blocked — the list switch, as the likes' one: drawn while the
 *                     feature is on, with the "who has to grant what" sentence when content.public is
 *                     missing;
 *   name_hidden     — the member's name is off right now, so the list is shown to nobody else either,
 *                     and the page says so beside the switch.
 */
function profileDescsContext(PDO $db, array $cfg, ?array $viewer): array
{
    $on = profileDescsEnabled($cfg) && $viewer !== null;
    $uid = $viewer !== null ? (int)($viewer['id'] ?? 0) : 0;
    $grant = $on && $uid > 0 && userIdHasGrantedPermission($db, $cfg, $uid, 'content.public');
    return [
        'credit_ok'       => $viewer !== null && usersEnabled($cfg) && function_exists('contentEnabled') && contentEnabled($cfg),
        'enabled'         => $on,
        'public_ok'       => $on,
        'may_publish'     => $on && $grant,
        'publish_blocked' => $on && $uid > 0 && !$grant,
        'name_hidden'     => $viewer !== null && !contentCreditPublic($viewer),
    ];
}

/**
 * The request's parameters, every one validated (profileVotesParams()'s rules): a number outside its
 * range is clamped, a word where a number goes or an unknown sort is the default.
 */
function profileDescsParams(array $q): array
{
    $int = static function ($v, int $lo, int $hi, int $def): int {
        if (is_int($v)) $n = $v;
        elseif (is_string($v) && preg_match('/^\s*-?\d{1,9}\s*$/', $v)) $n = (int)trim($v);
        else return $def;
        return max($lo, min($hi, $n));
    };
    $pick = static fn($v, array $allowed, string $def): string => (is_string($v) && in_array($v, $allowed, true)) ? $v : $def;
    $search = is_string($q['search'] ?? null) ? (string)$q['search'] : '';
    if (!mb_check_encoding($search, 'UTF-8')) $search = '';
    $search = trim((string)preg_replace('/[\x{0000}-\x{001F}\x{007F}]/u', ' ', $search));
    return [
        'page'     => $int($q['page'] ?? null, 1, PROFILE_DESCS_PAGE_MAX, 1),
        'per_page' => $int($q['per_page'] ?? null, 1, PROFILE_DESCS_PER_PAGE_MAX, PROFILE_DESCS_PER_PAGE),
        'search'   => mb_substr($search, 0, PROFILE_DESCS_SEARCH_MAX),
        'sort'     => $pick($q['sort'] ?? null, PROFILE_DESCS_SORTS, 'date'),
        'dir'      => $pick($q['dir'] ?? null, ['asc', 'desc'], 'desc'),
    ];
}

/**
 * The rows crediting the member, before the search, the order and the page: every source read once,
 * bounded. $reader as in profileDescsList().
 */
function profileDescsRows(PDO $db, array $cfg, int $ownerId, array $reader): array
{
    $isOwner = !empty($reader['is_owner']);
    $like = contentCreditsLikeFor($ownerId);
    $limit = PROFILE_DESCS_SCAN_MAX;
    $out = [];
    $credit = static function (array $r, string $home) use ($ownerId): ?array {
        $uid = $r['content_user_id'] !== null ? (int)$r['content_user_id'] : null;
        $c = contentCreditsFor(contentCreditsDecode($r['content_credits'], $uid), $ownerId);
        if ($c === null && $uid === $ownerId) $c = ['author' => true, 'share' => 0, 'at' => 0];
        if ($c === null) return null;                // the LIKE's candidate, and not theirs after all
        $author = $c['author'] || $uid === $ownerId;
        return [
            'home' => $home, 'id' => (int)$r['id'], 'info_hash' => strtolower((string)$r['info_hash']),
            'name' => $r['name'] !== null && $r['name'] !== '' ? (string)$r['name'] : null,
            'role' => $author ? 'author' : 'coauthor', 'share' => $author ? null : max(1, (int)$c['share']),
            'status' => ['approved' => 'published', 'pending' => 'pending', 'rejected' => 'rejected'][(string)$r['content_status']] ?? 'pending',
            'banned' => (int)$r['banned'] > 0,
            'ts' => (int)$c['at'] > 0 ? (int)$c['at'] : (int)$r['at_ts'],
            'description' => $r['description'], 'description_format' => (string)$r['description_format'],
            'source_url' => $r['source_url'],
        ];
    };

    // The registered torrents: a reader who may not see those rows sees none of them here either.
    if ($isOwner || !empty($reader['can_wl'])) {
        $where = "w.content_status <> 'none' AND (w.content_user_id = ? OR w.content_credits LIKE ?)";
        if (!$isOwner) $where .= " AND w.content_status = 'approved' AND w.banned = 0";
        $st = $db->prepare("SELECT w.id, w.info_hash, w.name, w.description, w.description_format, w.source_url, w.content_status,
                                   w.content_user_id, w.content_credits, w.banned,
                                   UNIX_TIMESTAMP(COALESCE(w.content_reviewed_at, w.created_at)) AS at_ts
                              FROM whitelist w WHERE $where ORDER BY w.id DESC LIMIT $limit");
        $st->execute([$ownerId, $like]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if ($row = $credit($r, 'wl')) $out[] = $row;
    }
    // The torrents the tracker has only seen.
    $where = "c.content_status <> 'none' AND (c.content_user_id = ? OR c.content_credits LIKE ?)";
    if (!$isOwner) $where .= " AND c.content_status = 'approved' AND NOT EXISTS (SELECT 1 FROM banned_hashes b WHERE b.info_hash = c.info_hash)";
    $st = $db->prepare("SELECT c.id, c.info_hash, i.name, c.description, c.description_format, c.source_url, c.content_status,
                               c.content_user_id, c.content_credits,
                               (SELECT COUNT(*) FROM banned_hashes b WHERE b.info_hash = c.info_hash) AS banned,
                               UNIX_TIMESTAMP(COALESCE(c.content_reviewed_at, c.created_at)) AS at_ts
                          FROM hash_content c LEFT JOIN index_hashes i ON i.info_hash = c.info_hash
                         WHERE $where ORDER BY c.id DESC LIMIT $limit");
    $st->execute([$ownerId, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if ($row = $credit($r, 'idx')) $out[] = $row;

    // Their own proposals, waiting or turned down — for them alone.
    if ($isOwner) {
        $st = $db->prepare("SELECT e.id, e.info_hash, e.kind, e.status, e.note, e.description, e.description_format, e.source_url,
                                   e.whitelist_id, COALESCE(w.name, i.name) AS name, COALESCE(w.banned, 0) AS banned,
                                   UNIX_TIMESTAMP(e.created_at) AS created_ts, UNIX_TIMESTAMP(e.reviewed_at) AS reviewed_ts
                              FROM wl_content_edits e
                              LEFT JOIN whitelist w ON w.id = e.whitelist_id
                              LEFT JOIN index_hashes i ON i.info_hash = e.info_hash
                             WHERE e.user_id = ? AND e.status IN ('pending', 'rejected') AND (e.note IS NULL OR e.note <> ?)
                             ORDER BY e.created_at DESC, e.id DESC LIMIT $limit");
        $st->execute([$ownerId, CONTENT_ARCHIVE_NOTE]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pending = $r['status'] === 'pending';
            $out[] = [
                'home' => $r['whitelist_id'] !== null ? 'wl' : 'idx', 'id' => (int)$r['id'],
                'info_hash' => strtolower((string)$r['info_hash']),
                'name' => $r['name'] !== null && $r['name'] !== '' ? (string)$r['name'] : null,
                'role' => $r['kind'] === 'edit' ? 'edit' : 'rewrite', 'share' => null,
                'status' => $pending ? 'pending' : ($r['note'] === CONTENT_WITHDRAWN_NOTE ? 'withdrawn' : 'declined'),
                'banned' => (int)$r['banned'] > 0,
                'ts' => (int)($pending ? $r['created_ts'] : ($r['reviewed_ts'] ?: $r['created_ts'])),
                'description' => $r['description'], 'description_format' => (string)$r['description_format'],
                'source_url' => $r['source_url'],
            ];
        }
    }
    return $out;
}

/**
 * One page of a member's descriptions, and how many there are in all.
 *
 * $reader says what the person ASKING may see (profileVotesList()'s arguments):
 *   is_owner — their own list: every state, their proposals too, the hash always (it opens the Info
 *              panel of what they wrote);
 *   can_wl   — whitelist.view and index_search_include_whitelist: registered torrents at all;
 *   can_hash — index.magnet: the hash travels to anybody else only with it (a hash is a magnet).
 * $tz is the reader's own zone. Every value in a row is data for textContent.
 */
function profileDescsList(PDO $db, array $cfg, int $ownerId, array $p, array $reader, ?DateTimeZone $tz = null): array
{
    $tz = $tz ?? new DateTimeZone(siteTimezone($cfg));
    $isOwner = !empty($reader['is_owner']);
    $showHash = $isOwner || !empty($reader['can_hash']);
    $perPage = max(1, min(PROFILE_DESCS_PER_PAGE_MAX, (int)($p['per_page'] ?? PROFILE_DESCS_PER_PAGE)));
    $rows = profileDescsRows($db, $cfg, $ownerId, $reader);

    $search = trim((string)($p['search'] ?? ''));
    if ($search !== '') {
        $hex = $showHash && preg_match('/^[0-9a-f]{2,40}$/i', $search) ? strtolower($search) : null;
        $rows = array_values(array_filter($rows, static fn($r) =>
            ($r['name'] !== null && mb_stripos($r['name'], $search, 0, 'UTF-8') !== false)
            || ($hex !== null && str_starts_with($r['info_hash'], $hex))));
    }

    // The order: one column at a time, a fixed rule each; every order ends on the date (newest first),
    // the home and the id, so two pages never disagree about a tie. A torrent with no name left (the
    // catalogue let it go) sorts last by name in both directions.
    $dir = ($p['dir'] ?? 'desc') === 'asc' ? 1 : -1;
    $sort = in_array($p['sort'] ?? '', PROFILE_DESCS_SORTS, true) ? (string)$p['sort'] : 'date';
    $weight = static fn(array $r): int => $r['role'] === 'author' ? 1000 : ($r['role'] === 'coauthor' ? (int)$r['share'] : 0);
    $tie = static fn(array $a, array $b): int => ($b['ts'] <=> $a['ts']) ?: strcmp($a['home'], $b['home']) ?: ($b['id'] <=> $a['id']);
    usort($rows, static function (array $a, array $b) use ($sort, $dir, $weight, $tie): int {
        if ($sort === 'name') {
            if (($a['name'] === null) !== ($b['name'] === null)) return $a['name'] === null ? 1 : -1;
            $c = $a['name'] === null ? 0 : $dir * strcmp(mb_strtolower($a['name'], 'UTF-8'), mb_strtolower($b['name'], 'UTF-8'));
            return $c ?: $tie($a, $b);
        }
        if ($sort === 'role') return ($dir * ($weight($a) <=> $weight($b))) ?: $tie($a, $b);
        return ($dir * ($a['ts'] <=> $b['ts'])) ?: (strcmp($a['home'], $b['home']) ?: ($dir * ($a['id'] <=> $b['id'])));
    });

    $total = count($rows);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min((int)($p['page'] ?? 1), $pages));
    $out = [];
    foreach (array_slice($rows, ($page - 1) * $perPage, $perPage) as $r) {
        $excerpt = listDescExcerpt($r['description'], $r['description_format'], $cfg, PROFILE_DESCS_EXCERPT);
        $out[] = [
            'info_hash'  => $showHash ? $r['info_hash'] : null,
            'name'       => $r['name'],
            'role'       => $r['role'],
            'share'      => $r['share'],
            'status'     => $r['status'],
            'banned'     => $r['banned'],
            'src'        => $r['home'] === 'wl' ? 'whitelist' : 'index',
            'at'         => $r['ts'] > 0 ? userDisplayTime($r['ts'], $tz, 'Y-m-d H:i') : null,
            'at_full'    => $r['ts'] > 0 ? userDisplayTime($r['ts'], $tz, 'Y-m-d H:i:s P') : null,
            'excerpt'    => $excerpt,
            // Only when there are no words to quote: a description can be a source link alone.
            'source_url' => $excerpt === '' && is_string($r['source_url']) && $r['source_url'] !== '' ? $r['source_url'] : null,
        ];
    }
    return ['rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
}
