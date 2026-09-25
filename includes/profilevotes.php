<?php
/**
 * A member's likes or ratings, listed (v75, 1.69.0): the Likes / Ratings tab of the account page,
 * straight after Favourites, and the section of the same name on a public profile, straight after
 * Favourites there too.
 *
 * ── which votes ───────────────────────────────────────────────────────────────────────────────
 * `hash_votes` rows of the ACCOUNT (voter_type 'user', voter_key = its id as a string — the key
 * repVoterKey() writes), never an anonymous one: a vote cast from an address is nobody's, and a list
 * "by address" would be a list about whoever else shares that address. Only the values that mean
 * something in the mode the site is in — ±1 for thumbs, 1..10 for stars — because a mode switch does
 * not clear the table (includes/reputation.php): a star rating of 8 is not a thumb, and a list that
 * showed it as one would be making up a vote. The one value both modes share is 1 (a thumb up, or
 * half a star): the table does not record which mode a vote was cast in, so such a vote is listed in
 * either, read the way the current mode reads it.
 *
 * ── what a reader is told about a score ───────────────────────────────────────────────────────
 * The totals are the ones repRecount() keeps on the catalogue row (index_hashes AND whitelist, the
 * whitelist row winning where both exist, exactly as favRowsFor() reads them) — read the way the search
 * page reads them, in the current mode, and as current as the last vote on that torrent (a switch of
 * mode recounts nothing, there or here). Below repMinVotes() a score is not a score anywhere on the
 * site, and it is not one here either: the row carries null, sorting by the score puts such a row
 * AFTER every shown one in both directions — and orders it by nothing the page hides, so the order
 * cannot be read as the hidden number — and a filter on the average leaves it out altogether.
 *
 * ── one query and a count ─────────────────────────────────────────────────────────────────────
 * The member's votes drive, through idx_votes_voter (voter_type, voter_key, …); each is joined to its
 * catalogue row by that row's own unique key (index_hashes' primary key, whitelist's uq_whitelist_hash).
 * Filters and the order are SQL, so the count and the page agree by construction and paging never
 * shows a row the count did not admit to. The ORDER BY is chosen from a fixed map, never spliced
 * from the request. There is no per-row query anywhere: the page is one statement, the total another.
 * The only extra work is the optional search inside file names, which reuses favHashesMatchingFiles()
 * over the member's newest votes, bounded exactly like a favourites list (favMaxPerUser()).
 *
 * ── who may see it ────────────────────────────────────────────────────────────────────────────
 * profileVotesShownTo() is THE answer, asked by the profile page and by api/user_votes.php alike. The
 * site's switch and the rating system under it; for somebody else's list, everything that opens their
 * profile at all, their group's GRANT of rating.public (never the administrator's blanket — what is
 * shown of somebody is their consent, not their power), and their own yes (users.votes_public, which
 * starts as no).
 */

const PROFILE_VOTES_PER_PAGE     = 25;
const PROFILE_VOTES_PER_PAGE_MAX = 100;
const PROFILE_VOTES_PAGE_MAX     = 100000;    // an OFFSET past this is a script, not a reader
const PROFILE_VOTES_MIN_VOTES_MAX = 1000000;
const PROFILE_VOTES_SEARCH_MAX   = 120;       // the box's own maxlength
const PROFILE_VOTES_SORTS        = ['date', 'own', 'score', 'votes', 'name', 'size', 'seeders'];

/**
 * The feature at all: accounts, the switch in Settings → Profiles, and ratings themselves. With the
 * rating system off there is no vote a reader could ever have seen, so the tab and the section are
 * drawn nowhere — whatever the switch says.
 */
function profileVotesEnabled(array $cfg): bool
{
    return usersEnabled($cfg)
        && (($cfg['profile_votes_enabled'] ?? '1') === '1')
        && function_exists('repEnabled') && repEnabled($cfg);
}

/**
 * May $viewer see $owner's list? The one decision, for the profile page and the endpoint.
 *
 * Your own: always, while the feature is on — on the account page and on your own profile, public or
 * not, the way your own favourites are. Somebody else's needs every one of these, and any single no is
 * the same no: their profile can be opened at all (profiles on, the reader's own account holds
 * `favourites.view_others`, the owner has not hidden their profile from this reader with a block),
 * their account is active, a group of theirs GRANTS `rating.public` (userIdHasGrantedPermission(): the
 * admin group's blanket is about what somebody may fix, not what may be shown of them), and they said
 * yes themselves. The reader's permission is asked of their ACCOUNT, not of the session: a panel session
 * in the same browser is not the member's grant, and the question has to be answerable from a test.
 */
function profileVotesShownTo(PDO $db, array $cfg, array $owner, ?array $viewer): bool
{
    if (!profileVotesEnabled($cfg) || $viewer === null) return false;
    $ownerId = (int)($owner['id'] ?? 0);
    $viewerId = (int)($viewer['id'] ?? 0);
    if ($ownerId <= 0 || $viewerId <= 0) return false;
    if ($ownerId === $viewerId) return true;
    if (($owner['status'] ?? '') !== 'active') return false;
    if (!profilesEnabled($cfg)) return false;
    if (!userIdHasPermission($db, $cfg, $viewerId, 'favourites.view_others')) return false;
    if (function_exists('profileHiddenFrom') && profileHiddenFrom($db, $ownerId, $viewerId)) return false;
    if ((int)($owner['votes_public'] ?? 0) !== 1) return false;
    return userIdHasGrantedPermission($db, $cfg, $ownerId, 'rating.public');
}

/**
 * What the account page needs to know, in one call (the shape favContext() has): whether the tab is
 * drawn, which mode names it, and the three answers the privacy switch is drawn from.
 *
 * The switch is shown exactly where the favourites one is: while the feature is on — profiles on or
 * not, as favContext() decides it, because an answer may be kept while nothing reads it and switching
 * profiles off and on again must not lose anybody's. With the grant missing it is STILL shown, with the
 * sentence that says who has to change what — hiding it would leave a member no way to find out why
 * their profile shows nothing (the same reasoning as favContext()'s publish_blocked).
 */
function profileVotesContext(PDO $db, array $cfg, ?array $viewer): array
{
    $on = profileVotesEnabled($cfg) && $viewer !== null;
    $uid = $viewer !== null ? (int)($viewer['id'] ?? 0) : 0;
    $publicOk = $on;
    $grant = $publicOk && $uid > 0 && userIdHasGrantedPermission($db, $cfg, $uid, 'rating.public');
    return [
        'enabled'         => $on,
        'mode'            => function_exists('repMode') ? repMode($cfg) : 'thumbs',
        'public_ok'       => $publicOk,
        'may_publish'     => $publicOk && $grant,
        'publish_blocked' => $publicOk && $uid > 0 && !$grant,
    ];
}

/**
 * The request's parameters, every one validated: a number outside its range is clamped to it, and a
 * value that is not one of the allowed ones — a word where a number goes, an unknown sort — is the
 * default rather than an error. Two ends of a range given the wrong way round are swapped: "from 4 to
 * 2" is a range somebody meant, not a typo worth an empty page.
 *
 * Both modes' filters are read whatever the mode, and only the current mode's reach the query.
 */
function profileVotesParams(array $q): array
{
    $int = static function ($v, int $lo, int $hi, int $def): int {
        if (is_int($v)) $n = $v;
        elseif (is_string($v) && preg_match('/^\s*-?\d{1,9}\s*$/', $v)) $n = (int)trim($v);
        else return $def;
        return max($lo, min($hi, $n));
    };
    $pick = static fn($v, array $allowed, string $def): string => (is_string($v) && in_array($v, $allowed, true)) ? $v : $def;
    $search = is_string($q['search'] ?? null) ? (string)$q['search'] : '';
    // Text that is not UTF-8 is not a name anybody could have, and it would fail in the driver.
    if (!mb_check_encoding($search, 'UTF-8')) $search = '';
    $search = trim((string)preg_replace('/[\x{0000}-\x{001F}\x{007F}]/u', ' ', $search));
    $p = [
        'page'      => $int($q['page'] ?? null, 1, PROFILE_VOTES_PAGE_MAX, 1),
        'per_page'  => $int($q['per_page'] ?? null, 1, PROFILE_VOTES_PER_PAGE_MAX, PROFILE_VOTES_PER_PAGE),
        'search'    => mb_substr($search, 0, PROFILE_VOTES_SEARCH_MAX),
        // Default yes, as on a favourites list: it is bounded by one person's rows, and an unticked
        // box has to be able to say so.
        'files'     => (string)($q['files'] ?? '1') !== '0',
        'sort'      => $pick($q['sort'] ?? null, PROFILE_VOTES_SORTS, 'date'),
        'dir'       => $pick($q['dir'] ?? null, ['asc', 'desc'], 'desc'),
        // stars: the member's own rating, in half-star units, and the overall average, in hundredths
        'own_min'   => $int($q['own_min'] ?? null, 1, 10, 1),
        'own_max'   => $int($q['own_max'] ?? null, 1, 10, 10),
        'avg_min'   => $int($q['avg_min'] ?? null, 0, 500, 0),
        'avg_max'   => $int($q['avg_max'] ?? null, 0, 500, 500),
        // thumbs: which way the member voted, and how many votes the torrent has from everybody
        'vote'      => $pick($q['vote'] ?? null, ['all', 'up', 'down'], 'all'),
        'min_votes' => $int($q['min_votes'] ?? null, 0, PROFILE_VOTES_MIN_VOTES_MAX, 0),
    ];
    if ($p['own_min'] > $p['own_max']) [$p['own_min'], $p['own_max']] = [$p['own_max'], $p['own_min']];
    if ($p['avg_min'] > $p['avg_max']) [$p['avg_min'], $p['avg_max']] = [$p['avg_max'], $p['avg_min']];
    return $p;
}

/** The SQL that keeps a member's votes in the current mode, and only those. Literal, chosen by mode. */
function profileVotesModeSql(array $cfg): string
{
    return repMode($cfg) === 'stars' ? 'v.vote BETWEEN 1 AND 10' : 'v.vote IN (-1, 1)';
}

/**
 * Which of the member's voted hashes have a FILE whose name matches — the favourites helper, as it is.
 *
 * favHashesMatchingFiles() is allowed to exist because its driving set is one person's own rows and
 * small. A favourites list is capped by favMaxPerUser(); votes have no cap of their own, so the same
 * cap is applied here: the file names of the member's newest votes, that many of them. Name and hash
 * still match across every vote — only the file-name half is bounded.
 */
function profileVotesFileHits(PDO $db, array $cfg, int $ownerId, string $needle): array
{
    if ($ownerId <= 0 || mb_strlen(trim($needle)) < 2 || !function_exists('favHashesMatchingFiles')) return [];
    $limit = favMaxPerUser($cfg);
    $sql = "SELECT v.info_hash FROM hash_votes v WHERE v.voter_type = 'user' AND v.voter_key = ? AND "
         . profileVotesModeSql($cfg) . " ORDER BY v.updated_at DESC, v.id DESC LIMIT " . (int)$limit;
    $st = $db->prepare($sql);
    $st->execute([(string)$ownerId]);
    $hashes = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    return array_keys(favHashesMatchingFiles($db, $hashes, $needle));
}

/**
 * One page of a member's votes, and how many there are in all.
 *
 * $reader says what the person ASKING may see, the way favRowsFor()'s arguments do:
 *   is_owner  — it is their own list: a banned row stays (they voted on it, and should see that), and
 *               the hash travels even without index.magnet (it is what opens the Info panel they
 *               voted in); for anybody else a banned row is dropped from the rows AND the count, and
 *               the hash is withheld unless they may have magnets;
 *   can_wl    — whitelist.view and index_search_include_whitelist: the only way a whitelisted hash's
 *               name, size and swarm reach anybody (such a hash is deleted out of index_hashes);
 *   can_hash  — index.magnet;
 *   file_hits — the hashes a file-name search matched (profileVotesFileHits()), or null.
 * $tz is the reader's own zone (userDisplayTimezone()): a vote's time is read as an instant
 * (UNIX_TIMESTAMP) and written in it, like every other time a reader sees.
 */
function profileVotesList(PDO $db, array $cfg, int $ownerId, array $p, array $reader, ?DateTimeZone $tz = null): array
{
    $stars   = repMode($cfg) === 'stars';
    $min     = repMinVotes($cfg);
    $showHash = !empty($reader['is_owner']) || !empty($reader['can_hash']);
    $perPage = max(1, min(PROFILE_VOTES_PER_PAGE_MAX, (int)($p['per_page'] ?? PROFILE_VOTES_PER_PAGE)));
    $tz = $tz ?? new DateTimeZone(siteTimezone($cfg));
    $q = profileVotesSql($cfg, $ownerId, $p, $reader);
    $joinClause = $q['join'];
    $where = $q['where'];
    $cols = $q['cols'];
    $orderBy = $q['order'];

    $st = $db->prepare("SELECT COUNT(*) FROM hash_votes v $joinClause WHERE $where");
    $st->execute($q['args']);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    // Past the last page is the last page: a list that shrank under a stale pager shows its end, not
    // an empty table with a "next" that leads nowhere.
    $page = max(1, min((int)($p['page'] ?? 1), $pages));
    $limit = $perPage;
    $offset = ($page - 1) * $perPage;

    $rows = [];
    if ($total > 0) {
        $st = $db->prepare("SELECT $cols FROM hash_votes v $joinClause WHERE $where ORDER BY $orderBy LIMIT $limit OFFSET $offset");
        $st->execute($q['args']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $count = (int)$r['votes_count'];
            $isShown = $count >= $min;
            $ts = (int)$r['voted_ts'];
            $int = static fn($v): ?int => $v === null ? null : (int)$v;
            $rows[] = [
                'info_hash'   => $showHash ? strtolower((string)$r['info_hash']) : null,
                'name'        => $r['name'] !== null ? (string)$r['name'] : null,
                'total_size'  => $int($r['total_size']),
                'files_count' => $int($r['files_count']),
                'seeders'     => $int($r['seeders']),
                'leechers'    => $int($r['leechers']),
                'last_seen'   => $r['last_seen'] !== null ? (string)$r['last_seen'] : null,
                'src'         => $r['src'] !== null ? (string)$r['src'] : null,
                'banned'      => (int)$r['banned'] === 1,
                'own_vote'    => (int)$r['own_vote'],
                // The vote's own last change, in the reader's zone: a date for the cell, the whole of
                // it with the offset for the tooltip.
                'voted_at'    => $ts > 0 ? userDisplayTime($ts, $tz, 'Y-m-d H:i') : null,
                'voted_full'  => $ts > 0 ? userDisplayTime($ts, $tz, 'Y-m-d H:i:s P') : null,
                'votes_count' => $count,
                'score_shown' => $isShown,
                // Null, not zero, below the threshold — and the up/down split with it, which IS the
                // score in thumbs mode. In stars mode there is no up and no down to tell.
                'score_x100'  => $isShown ? (int)$r['score_x100'] : null,
                'votes_up'    => $isShown && !$stars ? (int)$r['votes_up'] : null,
                'votes_down'  => $isShown && !$stars ? (int)$r['votes_down'] : null,
            ];
        }
    }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
}

/**
 * The pieces of the two statements profileVotesList() runs — the joins, the conditions with their
 * arguments, the columns and the order — built here once so the count and the page cannot disagree,
 * and so tests/profile_votes_test.php can EXPLAIN exactly what runs. Every piece is literal SQL chosen
 * by the code; every value the request carries is in `args`.
 */
function profileVotesSql(array $cfg, int $ownerId, array $p, array $reader): array
{
    $stars   = repMode($cfg) === 'stars';
    $min     = repMinVotes($cfg);
    $canWl   = !empty($reader['can_wl']);
    $isOwner = !empty($reader['is_owner']);
    $showHash = $isOwner || !empty($reader['can_hash']);

    // Each column the way favRowsFor() reads it: the whitelist row where there is one (it is the newer
    // truth, and a whitelisted hash has no index row left), the index row otherwise, nothing for a hash
    // the catalogue has let go of. Literal SQL, chosen by a bool.
    $pick = static fn(string $wl, string $idx): string => $canWl ? "CASE WHEN w.info_hash IS NOT NULL THEN $wl ELSE $idx END" : $idx;
    $x = [
        'name'      => $pick('w.name', 'h.name'),
        'size'      => $pick('w.total_size', 'h.total_size'),
        'files'     => $pick('w.files_count', 'h.files_count'),
        'seeders'   => $pick('COALESCE(w.scrape_seeders, 0)', 'COALESCE(h.scrape_seeders, h.last_seeders)'),
        'leechers'  => $pick('COALESCE(w.scrape_leechers, 0)', 'COALESCE(h.scrape_leechers, h.last_leechers)'),
        'last_seen' => $pick('COALESCE(w.scraped_at, w.updated_at, w.created_at)', 'h.last_seen'),
        'count'     => 'COALESCE(' . $pick('w.votes_count', 'h.votes_count') . ', 0)',
        'score'     => 'COALESCE(' . $pick('w.score_x100', 'h.score_x100') . ', 0)',
        'up'        => 'COALESCE(' . $pick('w.votes_up', 'h.votes_up') . ', 0)',
        'down'      => 'COALESCE(' . $pick('w.votes_down', 'h.votes_down') . ', 0)',
        'src'       => $canWl ? "CASE WHEN w.info_hash IS NOT NULL THEN 'whitelist' WHEN h.info_hash IS NOT NULL THEN 'index' END"
                              : "CASE WHEN h.info_hash IS NOT NULL THEN 'index' END",
        'banned'    => $canWl ? 'COALESCE(w.banned, 0)' : '0',
    ];
    // "Is there a score to show?" — the one threshold the whole site uses. An int from repMinVotes(),
    // clamped to [1, 1000] there, so it is spelled into the SQL rather than bound in an ORDER BY.
    $shown = '(' . $x['count'] . ' >= ' . (int)$min . ')';

    $joinClause = 'LEFT JOIN index_hashes h ON h.info_hash = v.info_hash'
                . ($canWl ? ' LEFT JOIN whitelist w ON w.info_hash = v.info_hash' : '');
    $where = "v.voter_type = 'user' AND v.voter_key = ? AND " . profileVotesModeSql($cfg);
    $args = [(string)$ownerId];
    // A stranger's copy has no row the tracker refuses to serve — in the rows AND in the count.
    if (!$isOwner && $canWl) $where .= ' AND COALESCE(w.banned, 0) = 0';

    $search = (string)($p['search'] ?? '');
    if ($search !== '') {
        $or = [$x['name'] . ' LIKE ?'];
        $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        // A hash prefix, the way a favourites list is searched — for a reader the hash is shown to.
        if ($showHash && preg_match('/^[0-9a-f]{2,40}$/i', $search)) {
            $or[] = 'v.info_hash LIKE ?';
            $args[] = strtolower($search) . '%';
        }
        $hits = array_values(array_filter((array)($reader['file_hits'] ?? []), static fn($h) => is_string($h) && preg_match('/^[0-9a-f]{40}$/', $h)));
        if ($hits) {
            $or[] = 'v.info_hash IN (' . implode(',', array_fill(0, count($hits), '?')) . ')';
            $args = array_merge($args, $hits);
        }
        $where .= ' AND (' . implode(' OR ', $or) . ')';
    }

    if ($stars) {
        $ownMin = max(1, min(10, (int)($p['own_min'] ?? 1)));
        $ownMax = max($ownMin, min(10, (int)($p['own_max'] ?? 10)));
        if ($ownMin > 1 || $ownMax < 10) {
            $where .= ' AND v.vote BETWEEN ? AND ?';
            array_push($args, $ownMin, $ownMax);
        }
        $avgMin = max(0, min(500, (int)($p['avg_min'] ?? 0)));
        $avgMax = max($avgMin, min(500, (int)($p['avg_max'] ?? 500)));
        // A range on the average asks about a number — and a torrent below the threshold has none.
        if ($avgMin > 0 || $avgMax < 500) {
            $where .= " AND $shown AND " . $x['score'] . ' BETWEEN ? AND ?';
            array_push($args, $avgMin, $avgMax);
        }
    } else {
        $vote = (string)($p['vote'] ?? 'all');
        if ($vote === 'up') $where .= ' AND v.vote = 1';
        elseif ($vote === 'down') $where .= ' AND v.vote = -1';
        $minVotes = max(0, min(PROFILE_VOTES_MIN_VOTES_MAX, (int)($p['min_votes'] ?? 0)));
        if ($minVotes > 0) {
            $where .= ' AND ' . $x['count'] . ' >= ?';
            $args[] = $minVotes;
        }
    }

    // The order, from a fixed map; the direction one of two literals. Every order ends on the vote's
    // own date (newest first) and id, so two pages never disagree about a tie.
    $d = ($p['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    $tie = 'v.updated_at DESC, v.id DESC';
    $sortKey = in_array($p['sort'] ?? '', PROFILE_VOTES_SORTS, true) ? (string)$p['sort'] : 'date';
    $orderBy = [
        'date'    => "v.updated_at $d, v.id $d",
        'own'     => "v.vote $d, $tie",
        // Shown scores first in BOTH directions; among the rest the hidden number orders nothing —
        // the CASE gives them all the same NULL — so their order cannot be read back as a score.
        'score'   => "$shown DESC, CASE WHEN $shown THEN " . $x['score'] . " END $d, " . $x['count'] . " DESC, $tie",
        'votes'   => $x['count'] . " $d, $tie",
        // A torrent the catalogue has let go of has no name, size or swarm: last, either way.
        'name'    => '(' . $x['name'] . ') IS NULL, ' . $x['name'] . " $d, $tie",
        'size'    => '(' . $x['size'] . ') IS NULL, ' . $x['size'] . " $d, $tie",
        'seeders' => '(' . $x['seeders'] . ') IS NULL, ' . $x['seeders'] . " $d, $tie",
    ][$sortKey];

    $cols = 'v.info_hash, v.vote AS own_vote, UNIX_TIMESTAMP(v.updated_at) AS voted_ts, '
          . $x['name'] . ' AS name, ' . $x['size'] . ' AS total_size, ' . $x['files'] . ' AS files_count, '
          . $x['seeders'] . ' AS seeders, ' . $x['leechers'] . ' AS leechers, ' . $x['last_seen'] . ' AS last_seen, '
          . $x['count'] . ' AS votes_count, ' . $x['score'] . ' AS score_x100, ' . $x['up'] . ' AS votes_up, '
          . $x['down'] . ' AS votes_down, ' . $x['src'] . ' AS src, ' . $x['banned'] . ' AS banned';
    return ['join' => $joinClause, 'where' => $where, 'args' => $args, 'cols' => $cols, 'order' => $orderBy];
}

/**
 * The half-star steps a range select offers, as [value => label]: ½, 1, 1½ … 5. `$from` is the first
 * value in half-star units (1 for a member's own rating, whose lowest is half a star; 0 for an
 * average). The fraction is a character of the number, not an icon standing in for one.
 */
function profileVotesStarSteps(int $from): array
{
    $out = [];
    for ($v = max(0, $from); $v <= 10; $v++) {
        $whole = intdiv($v, 2);
        $out[$v] = $v % 2 ? ($whole === 0 ? "\u{00BD}" : $whole . "\u{00BD}") : (string)$whole;
    }
    return $out;
}
