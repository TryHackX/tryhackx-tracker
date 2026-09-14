<?php
/**
 * "What does this tracker know about this hash?" — one answer, assembled from every table that can
 * hold a hash, for the status page (api/hash_check.php).
 *
 * Four facts, each from its own place, each answered separately rather than folded into one word:
 *   - banned:     banned_hashes — the ban list in both modes (in blacklist mode it IS the accesslist)
 *   - registered: whitelist — with the state the row is in: live on the accesslist, waiting for a
 *                 person's review, waiting for its first peer, rejected, failed its probe, or banned
 *   - seen:       index_hashes — observed in the swarm, when, how often, how big
 *   - metadata:   whichever row carries a name and files, and how far the worker got
 *
 * A hash the tracker has never met answers "unknown" in every column. That is a real answer, and it
 * is why this is gated (status.hash_check, granted to members by default) and rate-limited: unbounded,
 * it is a free oracle for probing the catalogue one hash at a time.
 *
 * status.hash_check buys the QUESTION, not every answer. The catalogue's own facts — that a hash is
 * in the index, that it is registered here, that somebody has written about it — belong to the
 * permissions that publish them elsewhere (api/index_info.php), and this page must not be the way
 * round them: one permission granted to members by default would otherwise serve, hash by hash,
 * exactly what index.view, whitelist.view and content.view exist to hold back. Known-or-unknown and
 * the ban stay for everybody who may ask at all — a ban is what a person checking a hash came for.
 */

require_once __DIR__ . '/content.php';

/**
 * Which sections of the answer THIS reader may be told — the same expressions api/index_info.php
 * decides the Info panel with, so the two pages cannot disagree about one hash.
 */
function hashCheckGates(PDO $db, array $cfg): array {
    $indexOn = function_exists('indexEnabled') ? indexEnabled($cfg) : (($cfg['index_enabled'] ?? '0') === '1');
    return [
        'seen'       => $indexOn && ($cfg['index_search_enabled'] ?? '1') === '1' && userCan($db, $cfg, 'index.view'),
        'registered' => userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1',
        'content'    => userCan($db, $cfg, 'content.view'),
    ];
}

/**
 * Everything the tracker knows about one lower-case 40-hex hash, minus what $gates withholds.
 *
 * $gates is hashCheckGates() for a reader; null asks it for the caller, so a new caller that forgets
 * gets the answer its own permissions allow rather than everything. A caller that wants the raw
 * facts (the panel, the tests) says so by passing them.
 */
function hashCheckLookup(PDO $db, array $cfg, string $hash, ?array $gates = null): array {
    $hash = strtolower($hash);
    // Missing keys are closed keys: a half-filled array must not read as permission.
    $gates = ($gates ?? hashCheckGates($db, $cfg)) + ['seen' => false, 'registered' => false, 'content' => false];
    $out = [
        'hash'       => $hash,
        'mode'       => function_exists('trackerMode') ? trackerMode($cfg) : 'whitelist',
        'banned'     => null,
        'registered' => null,
        'seen'       => null,
        'meta'       => ['status' => 'none', 'source' => null, 'name' => null],
        'files'      => ['fetched' => 0, 'total' => null],
        'known'      => false,
    ];

    $st = $db->prepare("SELECT reason, source, created_at FROM banned_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    if ($b = $st->fetch(PDO::FETCH_ASSOC)) {
        $out['banned'] = ['since' => $b['created_at'], 'source' => $b['source']];
    }

    $st = $db->prepare(
        "SELECT name, banned, review_status, probe_status, content_status, meta_status, files_count, created_at
           FROM whitelist WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    $w = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $st = $db->prepare(
        "SELECT name, first_seen, last_seen, seen_count, last_seeders, last_leechers, meta_status, files_count
           FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    $i = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Read BEFORE anything is withheld. "Does this tracker know this hash?" is the question the page
    // exists to answer, and the answer to it does not change with who is asking — only the detail does.
    $out['known'] = $out['banned'] !== null || $w !== null || $i !== null;

    if ($w !== null && $gates['registered']) {
        // One word for the state, decided in the order the states matter: a banned row is banned
        // whatever its review says, a row under review is not live whatever its probe says.
        if ((int)$w['banned'] === 1) $state = 'banned';
        elseif ($w['review_status'] === 'pending') $state = 'review';
        elseif ($w['review_status'] === 'rejected') $state = 'rejected';
        elseif ($w['probe_status'] === 'probing') $state = 'probing';
        elseif ($w['probe_status'] === 'failed') $state = 'failed';
        else $state = 'live';
        $out['registered'] = [
            'state'   => $state,
            'since'   => $w['created_at'],
            'name'    => $w['name'] !== null && $w['name'] !== '' ? $w['name'] : null,
            'content' => (string)($w['content_status'] ?? 'none'),
        ];
        $out['meta'] = ['status' => (string)$w['meta_status'], 'source' => 'whitelist', 'name' => $out['registered']['name']];
        $out['files']['total'] = $w['files_count'] !== null ? (int)$w['files_count'] : null;
    }

    if ($i !== null && $gates['seen']) {
        $name = $i['name'] !== null && $i['name'] !== '' ? $i['name'] : null;
        $out['seen'] = [
            'first'    => $i['first_seen'],
            'last'     => $i['last_seen'],
            'times'    => (int)$i['seen_count'],
            'seeders'  => (int)$i['last_seeders'],
            'leechers' => (int)$i['last_leechers'],
            'name'     => $name,
        ];
        // The index row is the one the worker writes to, so its word on the metadata wins where both
        // rows exist; the whitelist's stays only for a hash the index has never carried.
        $out['meta'] = ['status' => (string)$i['meta_status'], 'source' => 'index',
                        'name' => $name ?? ($out['meta']['name'] ?? null)];
        if ($i['files_count'] !== null) $out['files']['total'] = (int)$i['files_count'];

        // The rows actually stored, which for a huge torrent is fewer than the torrent has (the worker
        // keeps the first meta_max_files paths) — so both numbers are reported, never one for the other.
        // The name, the size and the file list are the index's facts too, so they wait on the same gate.
        $st = $db->prepare("SELECT COUNT(*) FROM index_files WHERE info_hash = ?");
        $st->execute([$hash]);
        $out['files']['fetched'] = (int)$st->fetchColumn();
    }

    // The words about it, from whichever home holds them (includes/content.php) — a hash the tracker
    // has only seen can carry a description too since 1.53.0. A whitelist row's words are that row's:
    // withheld from a reader who may not be told the row exists, exactly as api/index_info.php does it.
    $out['content'] = null;
    if ($gates['content'] && function_exists('contentRecordFor')) {
        $rec = contentRecordFor($db, $hash);
        if ($rec !== null && ($rec['content_status'] ?? 'none') !== 'none'
            && ($rec['kind'] !== 'wl' || $gates['registered'])) {
            $out['content'] = ['status' => (string)$rec['content_status'], 'kind' => $rec['kind']];
        }
    }

    // Which sections were held back, so a page can say "you may not be told" instead of reading a
    // withheld section as "no". Names only — it says nothing about this hash.
    $withheld = [];
    foreach (['registered', 'seen', 'content'] as $k) if (!$gates[$k]) $withheld[] = $k;
    $out['withheld'] = $withheld;
    return $out;
}
