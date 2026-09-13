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
 */

require_once __DIR__ . '/content.php';

/** Everything the tracker knows about one lower-case 40-hex hash. */
function hashCheckLookup(PDO $db, array $cfg, string $hash): array {
    $hash = strtolower($hash);
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
    if ($w = $st->fetch(PDO::FETCH_ASSOC)) {
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

    $st = $db->prepare(
        "SELECT name, first_seen, last_seen, seen_count, last_seeders, last_leechers, meta_status, files_count
           FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    if ($i = $st->fetch(PDO::FETCH_ASSOC)) {
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
    }

    // The rows actually stored, which for a huge torrent is fewer than the torrent has (the worker
    // keeps the first meta_max_files paths) — so both numbers are reported, never one for the other.
    $st = $db->prepare("SELECT COUNT(*) FROM index_files WHERE info_hash = ?");
    $st->execute([$hash]);
    $out['files']['fetched'] = (int)$st->fetchColumn();

    // The words about it, from whichever home holds them (includes/content.php) — a hash the tracker
    // has only seen can carry a description too since 1.53.0.
    if (function_exists('contentRecordFor')) {
        $rec = contentRecordFor($db, $hash);
        $out['content'] = $rec !== null && ($rec['content_status'] ?? 'none') !== 'none'
            ? ['status' => (string)$rec['content_status'], 'kind' => $rec['kind']] : null;
    } else {
        $out['content'] = null;
    }

    $out['known'] = $out['banned'] !== null || $out['registered'] !== null || $out['seen'] !== null;
    return $out;
}
