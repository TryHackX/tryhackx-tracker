<?php
/**
 * "Who has this" (1.70.0) — the three answers a torrent's overlay gives, each its own section with its own
 * count, search and pages: the people who keep it in their FAVOURITES, the people who LIKED or RATED it, and
 * the public LISTS it is on. The overlay opens from the Info panel (templates/partials/info_overlay.php);
 * api/hash_who.php answers one section at a time; api/hash_favourites.php is the 1.69.0 answer, kept.
 *
 * ── the rule, the same for all three ──────────────────────────────────────────────────────────────
 * Somebody appears only when every gate above them says yes, and any single no takes them out of the rows
 * AND out of the count. "14 people have this, 3 shown" would be a leak with a delay: the difference is
 * stable and cumulative, so whoever polls the counter learns the moment a hidden person acted. So every
 * gate is SQL — a group's GRANT as a literal list of ids (userGroupIdsWithPermission(); the administrator's
 * blanket is power, not consent), a membership in force today, the account active, its e-mail verified
 * where the site demands it, no `hide_profile` block against the reader — and the count and the page are
 * one FROM and one WHERE (whoRunPage()), so page two never shows a row page one's count did not admit to.
 *
 *   favourites — public favourites on at all and this list (favWhoEnabled()); the grant of
 *                `favourites.public`; the member's own fav_public and fav_listed (1.69.0, unchanged);
 *   likes /    — ratings on and the likes on profiles (profileVotesEnabled()) and this section
 *   ratings      (`who_votes_enabled`, Settings → Profiles → Likes and ratings); the grant of `rating.public`;
 *                the member's votes_public (their likes on their profile) AND votes_listed (v82, the twin of
 *                fav_listed: their name here, off until they say so). Only an ACCOUNT's vote — one cast from
 *                an address is nobody's — and only a value the current mode has: ±1 for thumbs, 1–10 half
 *                stars for stars (a switch of mode clears nothing, includes/profilevotes.php says why; 1 is in
 *                both). No score is made of them: each is its owner's vote, shown as their profile shows it;
 *   lists      — public lists on at all (listsPublicEnabled()) and this section (`who_lists_enabled`,
 *                Settings → Profiles → Lists); then the gates a public list has everywhere else (the five of
 *                tests/lists_test.php): the grant of `lists.public`, the owner's lists_public, the list's own
 *                is_public.
 * The READER, for every section: signed in, and their ACCOUNT holds `index.view` (a hash they cannot find is
 * a hash they cannot ask about) and `favourites.view_others` (the permission that shows people to each other
 * at all) — asked of the account, as profileVotesShownTo() asks it: a panel session in the same browser is
 * not the member's grant.
 *
 * ── what a row says ──────────────────────────────────────────────────────────────────────────────
 * A name and the address of its picture (userAvatarField(), 20 px); a vote's value; a list's name, slug and
 * size. Never an id and never a time — "who got interested when" is a fact about them, not about the
 * torrent. People A to Z (votes: the highest first, then A to Z); lists the most recently changed first, as a
 * profile's shelf orders them. A search is by name — a person's, or a list's or its owner's — and, like the
 * 1.69.0 list, a LIKE with a leading wildcard is acceptable here and only here: the driving set is the rows of
 * ONE hash, reached through its own index (idx_fav_hash, uq_vote_once, idx_item_hash), never a table.
 */

const WHO_SECTIONS      = ['fav', 'votes', 'lists'];   // the overlay's order
const WHO_PER_PAGE      = 20;      // the first look: what each section loads when the overlay opens
const WHO_PER_PAGE_MAX  = 50;
const WHO_PAGE_MAX      = 10000;   // an OFFSET past this is a script, not a reader
const WHO_SEARCH_MAX    = 60;      // the boxes' own maxlength

/** The likes / ratings section: ratings, the likes on profiles, and its own switch (default on). */
function whoVotesEnabled(array $cfg): bool
{
    return function_exists('profileVotesEnabled') && profileVotesEnabled($cfg)
        && (($cfg['who_votes_enabled'] ?? '1') === '1');
}

/** The lists section: public lists at all, and its own switch (default on). */
function whoListsEnabled(array $cfg): bool
{
    return function_exists('listsPublicEnabled') && listsPublicEnabled($cfg)
        && (($cfg['who_lists_enabled'] ?? '1') === '1');
}

/**
 * Which sections THIS reader may open: ['fav' => bool, 'votes' => bool, 'lists' => bool]. The Info panel
 * draws its button when any is true (the overlay then carries exactly those sections), and the endpoint
 * answers 404 for the others — the one decision, asked by both.
 */
function whoSections(PDO $db, array $cfg, ?array $viewer): array
{
    $out = array_fill_keys(WHO_SECTIONS, false);
    $vid = $viewer !== null ? (int)($viewer['id'] ?? 0) : 0;
    if ($vid <= 0) return $out;
    if (!userIdHasPermission($db, $cfg, $vid, 'index.view') || !userIdHasPermission($db, $cfg, $vid, 'favourites.view_others')) return $out;
    $out['fav']   = favWhoEnabled($cfg);
    $out['votes'] = whoVotesEnabled($cfg);
    $out['lists'] = whoListsEnabled($cfg);
    return $out;
}

/**
 * The request's paging and search, validated: a number outside its range is clamped to it, anything that
 * is not a number is the default. $perPageDefault / $perPageMax are the caller's (20 / 50 for the overlay;
 * api/hash_favourites.php keeps its 1.69.0 pair). Text that is not UTF-8 is no name anybody could have.
 */
function whoParams(array $q, int $perPageDefault = WHO_PER_PAGE, int $perPageMax = WHO_PER_PAGE_MAX): array
{
    $int = static function ($v, int $lo, int $hi, int $def): int {
        if (is_int($v)) $n = $v;
        elseif (is_string($v) && preg_match('/^\s*-?\d{1,9}\s*$/', $v)) $n = (int)trim($v);
        else return $def;
        return max($lo, min($hi, $n));
    };
    $s = is_string($q['search'] ?? null) ? (string)$q['search'] : '';
    if (!mb_check_encoding($s, 'UTF-8')) $s = '';
    $s = trim((string)preg_replace('/[\x{0000}-\x{001F}\x{007F}]/u', ' ', $s));
    return [
        'page'     => $int($q['page'] ?? null, 1, WHO_PAGE_MAX, 1),
        'per_page' => $int($q['per_page'] ?? null, 1, max(1, $perPageMax), max(1, min($perPageMax, $perPageDefault))),
        'search'   => mb_substr($s, 0, WHO_SEARCH_MAX, 'UTF-8'),
    ];
}

/** A LIKE argument that matches the text anywhere, with its own %, _ and \ taken literally. */
function whoLikeArg(string $s): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
}

/**
 * "A group of theirs GRANTS it, and that membership is in force today", as SQL over `$userCol` (a column
 * named by the code, never by a request). The ids are integers read from the group rows; `granted_at <= NOW()`
 * for the reason userGroups() has it — a membership that starts next week is not a membership today.
 */
function whoGrantedSql(array $groupIds, string $userCol): string
{
    $in = implode(',', array_map('intval', $groupIds));
    return "EXISTS (SELECT 1 FROM user_group_members m WHERE m.user_id = $userCol AND m.group_id IN ($in)
                     AND m.granted_at <= NOW() AND (m.expires_at IS NULL OR m.expires_at > NOW()))";
}

/**
 * The gates every person on these lists passes besides their own switches and their group's grant: active,
 * verified where the site demands it (an unverified account runs at guest level, so its membership counts for
 * nothing), and not hiding their profile from this reader — the directory's clause. Returns [sql, args];
 * the account table is always joined as `u`.
 */
function whoPersonSql(array $cfg, int $viewerId): array
{
    $sql = "u.status = 'active'"
         . " AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE b.user_id = u.id AND b.blocked_id = ? AND b.hide_profile = 1)";
    if (userEmailVerifyRequired($cfg)) $sql .= " AND u.email_verified = 1";
    return [$sql, [$viewerId]];
}

/**
 * One page and the total, from ONE `FROM … WHERE …` ($sql, built by the callers below from literals) —
 * so the count and the rows cannot disagree. A page past the end is empty, with the true total and pages:
 * a list that shrank under a "Show more" says so rather than repeating its last rows.
 */
function whoRunPage(PDO $db, string $sql, array $args, string $cols, string $orderBy, array $p): array
{
    $perPage = max(1, (int)($p['per_page'] ?? WHO_PER_PAGE));
    $page = max(1, (int)($p['page'] ?? 1));
    $st = $db->prepare("SELECT COUNT(*) $sql");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $rows = [];
    if ($total > 0 && $page <= $pages) {
        $limit = $perPage;
        $offset = ($page - 1) * $perPage;
        $st = $db->prepare("SELECT $cols $sql ORDER BY $orderBy LIMIT $limit OFFSET $offset");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
}

/** An answer with nobody in it — for a permission no group grants: nothing to ask the table. */
function whoNobody(array $p): array
{
    return ['rows' => [], 'total' => 0, 'page' => max(1, (int)($p['page'] ?? 1)), 'pages' => 1,
            'per_page' => max(1, (int)($p['per_page'] ?? WHO_PER_PAGE))];
}

/** The address of a person's picture, the one this project hands out (never their id), 20 px. */
function whoAvatar(array $row, array $cfg): string
{
    return function_exists('userAvatarField') ? userAvatarField($row, 20, getBaseUrl(), $cfg) : '';
}

/**
 * The statement a section runs, in pieces — ['sql' => "FROM … WHERE …", 'args', 'cols', 'order'] — or null when
 * nobody can be in it (no group grants the permission; public lists off). Built here once, so the count and the
 * page cannot disagree and tests/who_test.php can EXPLAIN exactly what runs. Every piece is literal SQL chosen
 * by the code; every value the request carries is in `args`.
 *
 *   fav   — user_favourites by its hash (idx_fav_hash), the account by its key;
 *   votes — hash_votes by its hash (uq_vote_once: info_hash, voter_type, voter_key), the account by its key —
 *           voter_key is the account's id as a string (repVoterKey()), read back as a number;
 *   lists — user_list_items by its hash (idx_item_hash), the list and its owner by their keys.
 */
function whoSql(PDO $db, array $cfg, string $section, string $hash, int $viewerId, string $search = ''): ?array
{
    $perm = ['fav' => 'favourites.public', 'votes' => 'rating.public', 'lists' => 'lists.public'][$section] ?? null;
    if ($perm === null) return null;
    if ($section === 'lists' && !(function_exists('listsPublicEnabled') && listsPublicEnabled($cfg))) return null;
    $groupIds = userGroupIdsWithPermission($db, $perm);
    if (!$groupIds) return null;
    [$person, $personArgs] = whoPersonSql($cfg, $viewerId);
    $granted = whoGrantedSql($groupIds, 'u.id');
    $args = array_merge([strtolower($hash)], $personArgs);
    $like = $search !== '' ? whoLikeArg($search) : null;
    if ($section === 'votes') {
        $where = "v.info_hash = ? AND v.voter_type = 'user' AND " . profileVotesModeSql($cfg)
               . " AND u.votes_public = 1 AND u.votes_listed = 1 AND $granted AND $person";
        if ($like !== null) { $where .= " AND u.username LIKE ?"; $args[] = $like; }
        return ['sql' => "FROM hash_votes v JOIN users u ON u.id = CAST(v.voter_key AS UNSIGNED) WHERE $where", 'args' => $args,
                'cols' => 'u.username, u.avatar_sha, v.vote', 'order' => 'v.vote DESC, u.username ASC'];
    }
    if ($section === 'lists') {
        $where = "i.info_hash = ? AND l.is_public = 1 AND u.lists_public = 1 AND $granted AND $person";
        if ($like !== null) { $where .= " AND (l.name LIKE ? OR u.username LIKE ?)"; array_push($args, $like, $like); }
        return ['sql' => "FROM user_list_items i JOIN user_lists l ON l.id = i.list_id JOIN users u ON u.id = l.user_id WHERE $where",
                'args' => $args,
                'cols' => 'l.name, l.slug, u.username, u.avatar_sha, (SELECT COUNT(*) FROM user_list_items x WHERE x.list_id = l.id) AS items',
                'order' => 'l.updated_at DESC, l.id DESC'];
    }
    $where = "f.info_hash = ? AND u.fav_public = 1 AND u.fav_listed = 1 AND $granted AND $person";
    if ($like !== null) { $where .= " AND u.username LIKE ?"; $args[] = $like; }
    return ['sql' => "FROM user_favourites f JOIN users u ON u.id = f.user_id WHERE $where", 'args' => $args,
            'cols' => 'u.username, u.avatar_sha', 'order' => 'u.username ASC'];
}

/** Runs whoSql() for one section and page. */
function whoSectionPage(PDO $db, array $cfg, string $section, string $hash, int $viewerId, array $p): array
{
    $q = whoSql($db, $cfg, $section, $hash, $viewerId, (string)($p['search'] ?? ''));
    return $q === null ? whoNobody($p) : whoRunPage($db, $q['sql'], $q['args'], $q['cols'], $q['order'], $p);
}

/** Favourites: {username, avatar} — the rows api/hash_favourites.php gave in 1.69.0, by the same gates. */
function whoFavPage(PDO $db, array $cfg, string $hash, int $viewerId, array $p): array
{
    $r = whoSectionPage($db, $cfg, 'fav', $hash, $viewerId, $p);
    $r['rows'] = array_map(static fn(array $x): array => ['username' => (string)$x['username'], 'avatar' => whoAvatar($x, $cfg)], $r['rows']);
    return $r;
}

/**
 * Likes / ratings: {username, avatar, vote} — `vote` as the current mode reads it (thumbs ±1; stars 1–10,
 * half stars), and `mode` beside the rows.
 */
function whoVotesPage(PDO $db, array $cfg, string $hash, int $viewerId, array $p): array
{
    $r = whoSectionPage($db, $cfg, 'votes', $hash, $viewerId, $p);
    $r['rows'] = array_map(static fn(array $x): array => ['username' => (string)$x['username'], 'avatar' => whoAvatar($x, $cfg),
                                                          'vote' => (int)$x['vote']], $r['rows']);
    return $r + ['mode' => repMode($cfg) === 'stars' ? 'stars' : 'thumbs'];
}

/**
 * Lists: {name, slug, username, items, avatar} — the shape listsContainingHash() has handed out since 1.44.0.
 * A search matches the list's name or its owner's.
 */
function whoListsPage(PDO $db, array $cfg, string $hash, int $viewerId, array $p): array
{
    $r = whoSectionPage($db, $cfg, 'lists', $hash, $viewerId, $p);
    $r['rows'] = array_map(static fn(array $x): array => [
        'name' => (string)$x['name'], 'slug' => (string)$x['slug'], 'username' => (string)$x['username'],
        'items' => (int)$x['items'], 'avatar' => whoAvatar($x, $cfg),
    ], $r['rows']);
    return $r;
}

/** One section's page, by name. The caller has asked whoSections(); this only reads. */
function whoPage(PDO $db, array $cfg, string $section, string $hash, int $viewerId, array $p): array
{
    if ($section === 'votes') return whoVotesPage($db, $cfg, $hash, $viewerId, $p);
    if ($section === 'lists') return whoListsPage($db, $cfg, $hash, $viewerId, $p);
    return whoFavPage($db, $cfg, $hash, $viewerId, $p);
}
