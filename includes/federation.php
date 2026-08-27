<?php
/**
 * Federation / clustering (schema v7): tracker nodes exchange observed-hash METADATA so every
 * operator gets a bigger searchable index without re-fetching everything from the DHT.
 *
 * Model — pull-based, additive-only:
 *   - Export: v1/federation/export serves rows whose metadata is resolved ('done'), keyed by an
 *     opaque cursor (meta_fetched_at UNIX ts + info_hash tie-break). Peers authenticate like any
 *     S2S consumer (api_clients row with scope 'federation') and inherit the ban machinery.
 *   - Import: worker/federation.py (a systemd timer, NOT PHP — big batches must not burn web
 *     request time) walks fed_peers with pull_enabled=1, pulls pages from each peer's export and
 *     merges them: an existing index row without metadata is filled in (meta_source 'fed:<peer>'),
 *     an unknown hash is inserted only when fed_import_new=1. Whitelisted/banned hashes are never
 *     touched (the index poll drops them anyway).
 *   - The `fed_peers` row stores BOTH directions for one partner: our outbound bearer for pulling
 *     from them, and the api_clients row id we granted them for pulling from us.
 *
 * Everything off unless fed_enabled=1; export additionally needs fed_export_enabled=1.
 */

function fedEnabled(array $cfg): bool { return (($cfg['fed_enabled'] ?? '0') === '1'); }
function fedNodeName(array $cfg): string { return mb_substr(trim((string)($cfg['fed_node_name'] ?? '')), 0, 64); }
function fedExportEnabled(array $cfg): bool { return fedEnabled($cfg) && (($cfg['fed_export_enabled'] ?? '0') === '1'); }
function fedExportFiles(array $cfg): bool { return (($cfg['fed_export_files'] ?? '1') === '1'); }
function fedExportMaxBatch(array $cfg): int { return max(100, min(20000, (int)($cfg['fed_export_max_batch'] ?? 2000) ?: 2000)); }
function fedImportNew(array $cfg): bool { return (($cfg['fed_import_new'] ?? '0') === '1'); }
/**
 * fill = a peer's answer goes straight into the catalogue. review = it goes into fed_review and
 * nothing is visible until somebody accepts it. The difference matters the moment a peer is someone
 * else's machine: in fill mode "trusted" is decided once, when you add the peer, and every row it
 * ever sends inherits that decision.
 */
function fedImportMode(array $cfg): string { return (($cfg['fed_import_mode'] ?? 'fill') === 'review') ? 'review' : 'fill'; }
/** Per row. A pathological package must not turn one quarantined row into a megabyte of MEDIUMTEXT. */
const FED_REVIEW_FILES_MAX = 2000;
/**
 * Budgets that end an export page. `fed_export_max_batch` (rows) has always existed and is the one
 * that does NOT bound the work: a page of 20 000 rows can carry millions of file records, because
 * it counts torrents rather than what a torrent contains. These two count the things that actually
 * grow — bytes on the wire and file records — so a page is bounded whatever the rows hold.
 * 0 switches a budget off.
 */
function fedExportMaxBytes(array $cfg): int { return max(0, min(1073741824, (int)($cfg['fed_export_max_bytes'] ?? 8388608))); }
function fedExportMaxFiles(array $cfg): int { return max(0, min(50000000, (int)($cfg['fed_export_max_files'] ?? 200000))); }
/** Rows read from the database at a time while streaming. Bounds memory; not user-visible. */
const FED_STREAM_CHUNK = 500;
function fedPullMinutes(array $cfg): int { return max(5, min(1440, (int)($cfg['fed_pull_minutes'] ?? 60) ?: 60)); }

/**
 * One export page. Cursor = (since = UNIX ts of meta_fetched_at, after_hash = tie-break within the
 * same second). Returns ['rows'=>[], 'next'=>['since'=>int,'after'=>hash]|null, 'has_more'=>bool].
 * Row shape (short keys — packages are large): h, n (name), s (size), fc (files count),
 * pl (piece length), sl [seeders, leechers], seen {f,l,c}, mf (meta_fetched_at ts), files [[p,sz]..].
 */
/**
 * The name of the peer this inbound API client belongs to, or '' if the key is not a peer's.
 *
 * Used for split horizon (below). A key with the federation scope that no peer row claims is a
 * perfectly ordinary thing — someone testing with curl — and simply gets the unfiltered export.
 */
function fedPeerNameForClient(PDO $db, int $clientId): string {
    if ($clientId <= 0) return '';
    try {
        $st = $db->prepare("SELECT name FROM fed_peers WHERE api_client_id = ? LIMIT 1");
        $st->execute([$clientId]);
        $n = $st->fetchColumn();
    } catch (PDOException $e) {
        return '';
    }
    return $n === false ? '' : (string)$n;
}

function fedExportRows(PDO $db, array $cfg, int $since, string $afterHash, int $limit, bool $withFiles, string $excludeSource = ''): array {
    $limit = max(1, min(fedExportMaxBatch($cfg), $limit));
    $afterHash = preg_match('/^[a-f0-9]{40}$/', $afterHash) ? $afterHash : '';
    // Two invariants keep the cursor sound and the export clean:
    //  - meta_fetched_at < the CURRENT second: the open second may still receive commits (the DHT
    //    worker and a running import both stamp NOW() row by row) — if the cursor landed inside it,
    //    rows committed a moment later with a smaller hash would be skipped forever.
    //  - never export hashes that are locally whitelisted/banned: the index poll purges them, but
    //    only when it runs — a ban must stop leaving the node immediately.
    $st = $db->prepare(
        "SELECT info_hash, name, total_size, files_count, piece_length, last_seeders, last_leechers,
                first_seen, last_seen, seen_count, UNIX_TIMESTAMP(meta_fetched_at) AS mf,
                -- `mf` paginates (it is when the row arrived HERE, and it only ever moves
                -- forward, so it makes a sound cursor); `mo` is when the metadata was first
                -- resolved anywhere, and it is what the far side compares against to decide
                -- whether it already knows this. Older nodes send no `mo`; the importer then
                -- falls back to `mf`, which is what they have always effectively meant.
                UNIX_TIMESTAMP(COALESCE(meta_origin_at, meta_fetched_at)) AS mo
         FROM index_hashes i
         WHERE meta_status = 'done' AND meta_fetched_at IS NOT NULL
           AND meta_fetched_at < FROM_UNIXTIME(UNIX_TIMESTAMP())
           -- GREATEST(1, ?) is not decoration. MariaDB 11.8 returns NULL for FROM_UNIXTIME(0)
           -- (unix time 0 is outside the TIMESTAMP range), NULL poisons the comparison, and the
           -- whole clause becomes unknown — so a peer starting from a cold cursor of 0 received
           -- an EMPTY page, every time, for ever. MariaDB 11.4 happens to return a value, which
           -- is why every local test passed while production exported nothing at all.
           AND (meta_fetched_at > FROM_UNIXTIME(GREATEST(1, ?))
                OR (meta_fetched_at = FROM_UNIXTIME(GREATEST(1, ?)) AND info_hash > ?))
           AND NOT EXISTS (SELECT 1 FROM banned_hashes b WHERE b.info_hash = i.info_hash)
           AND NOT EXISTS (SELECT 1 FROM whitelist w WHERE w.info_hash = i.info_hash)
           -- Split horizon: never hand a peer back the rows it gave us. Importing re-stamps
           -- meta_fetched_at, which puts every borrowed row into OUR export window, so without this
           -- two nodes spend every cycle shipping each other's own catalogue back and forth --
           -- megabytes of transfer for zero writes, for ever. The exclusion is by source tag, so it
           -- costs one string comparison and needs no extra state.
           AND (? = '' OR meta_source IS NULL OR meta_source <> ?)
         ORDER BY meta_fetched_at, info_hash
         LIMIT " . ($limit + 1));
    $ex = $excludeSource === '' ? '' : fedPurgeSource($excludeSource);
    $st->execute([$since, $since, $afterHash, $ex, $ex]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    $out = [];
    $hashes = [];
    foreach ($rows as $r) {
        $hashes[] = $r['info_hash'];
        $out[$r['info_hash']] = [
            'h' => $r['info_hash'], 'n' => $r['name'], 's' => $r['total_size'] !== null ? (int)$r['total_size'] : null,
            'fc' => $r['files_count'] !== null ? (int)$r['files_count'] : null,
            'pl' => $r['piece_length'] !== null ? (int)$r['piece_length'] : null,
            'sl' => [(int)$r['last_seeders'], (int)$r['last_leechers']],
            'seen' => ['f' => $r['first_seen'], 'l' => $r['last_seen'], 'c' => (int)$r['seen_count']],
            'mf' => (int)$r['mf'],
            'mo' => (int)($r['mo'] ?: $r['mf']),
        ];
    }
    if ($withFiles && $hashes) {
        $in = implode(',', array_fill(0, count($hashes), '?'));
        $fs = $db->prepare("SELECT info_hash, path, size FROM index_files WHERE info_hash IN ($in) ORDER BY id");
        $fs->execute($hashes);
        foreach ($fs->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[$f['info_hash']]['files'][] = [$f['path'], (int)$f['size']];
        }
    }
    $out = array_values($out);
    $next = null;
    if ($out) {
        $lastRow = $out[count($out) - 1];
        $next = ['since' => $lastRow['mf'], 'after' => $lastRow['h']];
    }
    return ['rows' => $out, 'next' => $next, 'has_more' => $hasMore];
}

/**
 * Stream one export page as NDJSON, with a bounded memory footprint whatever the page contains.
 *
 * The buffered exporter above builds the whole page — rows AND every file record of every row — in
 * a PHP array and then json_encodes the lot. That is fine for a few thousand small torrents and
 * fatal for a real catalogue: `fed_export_max_batch` counts TORRENTS, so 20 000 rows of a hundred
 * files each is two million records assembled in memory before a single byte is sent. This walks
 * the same cursor in chunks of FED_STREAM_CHUNK, emits each row as it is built, and never holds
 * more than one chunk.
 *
 * Three budgets end a page, whichever is reached first — rows, bytes on the wire, file records —
 * and the cursor comes back so the peer simply asks for the next page. "Smaller pages" therefore
 * happen automatically on a heavy catalogue instead of being something the client has to guess.
 *
 * $emit receives each finished line (newline included) and returns how many bytes that actually put
 * on the wire, so the byte budget counts what is sent rather than what was built.
 *
 * Returns ['rows'=>int, 'files'=>int, 'bytes'=>int, 'next'=>['since'=>int,'after'=>string]|null,
 *          'has_more'=>bool, 'stopped_by'=>'rows'|'bytes'|'files'|'end'].
 */
function fedExportStream(PDO $db, array $cfg, int $since, string $afterHash, int $limit, bool $withFiles, callable $emit, string $excludeSource = ''): array {
    $limit    = max(1, min(fedExportMaxBatch($cfg), $limit));
    $maxBytes = fedExportMaxBytes($cfg);
    $maxFiles = fedExportMaxFiles($cfg);
    $afterHash = preg_match('/^[a-f0-9]{40}$/', $afterHash) ? $afterHash : '';

    // Same two invariants as the buffered exporter: never read into the open second (rows are still
    // being committed into it, and a cursor landing inside would skip them forever), and never
    // export a hash that is locally whitelisted or banned.
    $sql =
        "SELECT info_hash, name, total_size, files_count, piece_length, last_seeders, last_leechers,
                first_seen, last_seen, seen_count, UNIX_TIMESTAMP(meta_fetched_at) AS mf,
                -- `mf` paginates (it is when the row arrived HERE, and it only ever moves
                -- forward, so it makes a sound cursor); `mo` is when the metadata was first
                -- resolved anywhere, and it is what the far side compares against to decide
                -- whether it already knows this. Older nodes send no `mo`; the importer then
                -- falls back to `mf`, which is what they have always effectively meant.
                UNIX_TIMESTAMP(COALESCE(meta_origin_at, meta_fetched_at)) AS mo
         FROM index_hashes i
         WHERE meta_status = 'done' AND meta_fetched_at IS NOT NULL
           AND meta_fetched_at < FROM_UNIXTIME(UNIX_TIMESTAMP())
           -- GREATEST(1, ?) is not decoration. MariaDB 11.8 returns NULL for FROM_UNIXTIME(0)
           -- (unix time 0 is outside the TIMESTAMP range), NULL poisons the comparison, and the
           -- whole clause becomes unknown — so a peer starting from a cold cursor of 0 received
           -- an EMPTY page, every time, for ever. MariaDB 11.4 happens to return a value, which
           -- is why every local test passed while production exported nothing at all.
           AND (meta_fetched_at > FROM_UNIXTIME(GREATEST(1, ?))
                OR (meta_fetched_at = FROM_UNIXTIME(GREATEST(1, ?)) AND info_hash > ?))
           AND NOT EXISTS (SELECT 1 FROM banned_hashes b WHERE b.info_hash = i.info_hash)
           AND NOT EXISTS (SELECT 1 FROM whitelist w WHERE w.info_hash = i.info_hash)
           -- Split horizon: never hand a peer back the rows it gave us. Importing re-stamps
           -- meta_fetched_at, which puts every borrowed row into OUR export window, so without this
           -- two nodes spend every cycle shipping each other's own catalogue back and forth --
           -- megabytes of transfer for zero writes, for ever. The exclusion is by source tag, so it
           -- costs one string comparison and needs no extra state.
           AND (? = '' OR meta_source IS NULL OR meta_source <> ?)
         ORDER BY meta_fetched_at, info_hash
         LIMIT ";
    $st = $db->prepare($sql . (FED_STREAM_CHUNK + 1));

    $ex = $excludeSource === '' ? '' : fedPurgeSource($excludeSource);
    $curSince = $since; $curAfter = $afterHash;
    $rowsOut = 0; $filesOut = 0; $bytesOut = 0;
    $next = null; $hasMore = false; $stoppedBy = 'end';

    while (true) {
        $st->execute([$curSince, $curSince, $curAfter, $ex, $ex]);
        $chunk = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        if (!$chunk) break;
        $moreAfterChunk = count($chunk) > FED_STREAM_CHUNK;
        if ($moreAfterChunk) array_pop($chunk);

        // Files for this chunk only — one round trip per 500 hashes instead of one per row, and the
        // result set is bounded by the chunk rather than by the whole page.
        $files = [];
        if ($withFiles) {
            $hashes = array_column($chunk, 'info_hash');
            $in = implode(',', array_fill(0, count($hashes), '?'));
            $fs = $db->prepare("SELECT info_hash, path, size FROM index_files WHERE info_hash IN ($in) ORDER BY info_hash, id");
            $fs->execute($hashes);
            while ($f = $fs->fetch(PDO::FETCH_ASSOC)) {
                $files[$f['info_hash']][] = [$f['path'], (int)$f['size']];
            }
            $fs->closeCursor();
        }

        foreach ($chunk as $r) {
            $h = $r['info_hash'];
            $row = [
                'h' => $h, 'n' => $r['name'],
                's'  => $r['total_size']   !== null ? (int)$r['total_size']   : null,
                'fc' => $r['files_count']  !== null ? (int)$r['files_count']  : null,
                'pl' => $r['piece_length'] !== null ? (int)$r['piece_length'] : null,
                'sl' => [(int)$r['last_seeders'], (int)$r['last_leechers']],
                'seen' => ['f' => $r['first_seen'], 'l' => $r['last_seen'], 'c' => (int)$r['seen_count']],
                'mf' => (int)$r['mf'],
                'mo' => (int)($r['mo'] ?: $r['mf']),
            ];
            $rowFiles = $files[$h] ?? [];
            if ($rowFiles) $row['files'] = $rowFiles;

            $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line === false) continue;   // one unencodable row must not kill the whole page
            $bytesOut += $emit($line . "\n");
            $rowsOut++;
            $filesOut += count($rowFiles);
            $curSince = (int)$r['mf']; $curAfter = $h;
            $next = ['since' => $curSince, 'after' => $curAfter];

            // Budgets are checked AFTER the row is sent, so a page always carries at least one row
            // even when a single torrent is larger than the whole budget — otherwise a catalogue
            // with one huge entry could never make progress past it.
            if ($rowsOut >= $limit)                      { $stoppedBy = 'rows';  break 2; }
            if ($maxBytes > 0 && $bytesOut >= $maxBytes) { $stoppedBy = 'bytes'; break 2; }
            if ($maxFiles > 0 && $filesOut >= $maxFiles) { $stoppedBy = 'files'; break 2; }
        }
        if (!$moreAfterChunk) break;
    }

    if ($stoppedBy !== 'end') {
        // A budget stopped us, so there MAY be more. Confirm it rather than assuming: claiming
        // has_more when the page happened to land on the last row would cost the peer one pointless
        // request every cycle, forever.
        $probe = $db->prepare($sql . '1');
        $probe->execute([$curSince, $curSince, $curAfter, $ex, $ex]);
        $hasMore = $probe->fetchColumn(0) !== false;
        $probe->closeCursor();
    }
    return ['rows' => $rowsOut, 'files' => $filesOut, 'bytes' => $bytesOut,
            'next' => $next, 'has_more' => $hasMore, 'stopped_by' => $stoppedBy];
}

/** Peers with the inbound api_client joined (label/enabled/last use) — for the admin UI + CLI. */
function fedPeersList(PDO $db): array {
    $st = $db->query(
        "SELECT p.*, c.label AS client_label, c.key_id AS client_key_id, c.enabled AS client_enabled,
                c.last_used_at AS client_last_used_at, c.requests_count AS client_requests
         FROM fed_peers p LEFT JOIN api_clients c ON c.id = p.api_client_id
         ORDER BY p.name");
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        foreach (['id', 'api_client_id', 'pull_enabled', 'pull_files', 'rows_imported'] as $k) {
            if ($p[$k] !== null) $p[$k] = (int)$p[$k];
        }
        $p['client_enabled'] = $p['client_enabled'] !== null ? (int)$p['client_enabled'] : null;
        $p['has_bearer'] = trim((string)$p['bearer']) !== '';
        unset($p['bearer']);   // never echo the outbound credential back to the browser
        $rows[] = $p;
    }
    return $rows;
}

function fedPeerValidName(string $name): bool { return (bool)preg_match('/^[A-Za-z0-9 _.\-]{2,64}$/', $name); }

/**
 * Create/update a peer. $data: name, base_url, bearer (''=keep, 'CLEAR'=drop), pull_enabled,
 * pull_files; $id null = insert. Returns ['id'=>int] or ['error'=>message].
 */
function fedPeerSave(PDO $db, ?int $id, array $data): array {
    $name = trim((string)($data['name'] ?? ''));
    $url = trim((string)($data['base_url'] ?? ''));
    if (!fedPeerValidName($name)) return ['error' => 'Peer name: 2-64 chars, letters/digits/space/._-'];
    // The bearer we hold for this partner travels in a header on EVERY pull. Over http it is
    // readable by anything on the path, and a leaked federation key is a licence to read our whole
    // resolved index. No amount of convenience is worth that, so http is refused outright.
    if (!preg_match('#^https?://[^\s]+$#i', $url)) return ['error' => 'Base URL must be an https URL (the peer site root, no /api.php)'];
    if (!preg_match('#^https://#i', $url)) {
        return ['error' => 'Base URL must start with https:// — the bearer for this peer is sent on every pull, and http would put it on the wire in clear text'];
    }
    $url = rtrim($url, '/');
    $bearer = trim((string)($data['bearer'] ?? ''));
    if ($bearer !== '' && $bearer !== 'CLEAR' && !preg_match('/^[a-f0-9]{16}\.[a-f0-9]{64}$/i', $bearer)) {
        return ['error' => 'Bearer must look like <16 hex>.<64 hex> (from the peer\'s API client), or be left empty'];
    }
    $pull = !empty($data['pull_enabled']) ? 1 : 0;
    $pullFiles = !empty($data['pull_files']) ? 1 : 0;
    try {
        if ($id === null) {
            $db->prepare("INSERT INTO fed_peers (name, base_url, bearer, pull_enabled, pull_files) VALUES (?, ?, ?, ?, ?)")
               ->execute([$name, $url, $bearer === 'CLEAR' ? '' : strtolower($bearer), $pull, $pullFiles]);
            return ['id' => (int)$db->lastInsertId()];
        }
        $sets = "name = ?, base_url = ?, pull_enabled = ?, pull_files = ?";
        $args = [$name, $url, $pull, $pullFiles];
        if ($bearer === 'CLEAR') { $sets .= ", bearer = ''"; }
        elseif ($bearer !== '') { $sets .= ", bearer = ?"; $args[] = strtolower($bearer); }
        $args[] = $id;
        $st = $db->prepare("UPDATE fed_peers SET $sets WHERE id = ?");
        $st->execute($args);
        return ['id' => $id];
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) return ['error' => 'A peer with this name already exists'];
        throw $e;
    }
}

/** Delete a peer and (optionally) the inbound api_client we created for it. */
function fedPeerDelete(PDO $db, int $id, bool $dropClient = true): bool {
    $st = $db->prepare("SELECT api_client_id FROM fed_peers WHERE id = ?");
    $st->execute([$id]);
    $clientId = $st->fetchColumn();
    if ($clientId === false) return false;
    $db->prepare("DELETE FROM fed_peers WHERE id = ?")->execute([$id]);
    if ($dropClient && $clientId !== null && (int)$clientId > 0) {
        $db->prepare("DELETE FROM api_clients WHERE id = ? AND scope = 'federation'")->execute([(int)$clientId]);
    }
    return true;
}

/* ───────────────────────── quarantine (fed_import_mode = review) ─────────────────────────
 *
 * The panel half. The importer writes rows here; everything below is what an admin does with them.
 * Accepting is deliberately the same merge the fill path performs, so review mode changes WHEN a row
 * is trusted and never HOW it is stored — one code path, one set of guarantees.
 */

/** How much is waiting, per peer. Cheap enough to put on the settings page. */
function fedReviewCounts(PDO $db): array {
    $out = ['pending' => 0, 'rejected' => 0, 'peers' => []];
    try {
        $st = $db->query("SELECT peer_name, state, COUNT(*) c FROM fed_review GROUP BY peer_name, state");
    } catch (PDOException $e) {
        return $out;   // table not migrated yet — a count is never worth an exception
    }
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $n = (int)$r['c'];
        $state = (string)$r['state'];
        $out[$state] = ($out[$state] ?? 0) + $n;
        $peer = (string)$r['peer_name'];
        if (!isset($out['peers'][$peer])) $out['peers'][$peer] = ['pending' => 0, 'rejected' => 0];
        $out['peers'][$peer][$state] = $n;
    }
    return $out;
}

/** One page of the queue, newest first. */
function fedReviewList(PDO $db, string $peer = '', string $state = 'pending', int $limit = 50, int $offset = 0): array {
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $where = ["state = ?"];
    $args = [$state === 'rejected' ? 'rejected' : 'pending'];
    if ($peer !== '') { $where[] = "peer_name = ?"; $args[] = $peer; }
    $sql = "SELECT id, info_hash, peer_name, name, total_size, files_count, piece_length, origin_at,
                   files_truncated, state, created_at
            FROM fed_review WHERE " . implode(' AND ', $where) . "
            ORDER BY id DESC LIMIT $limit OFFSET $offset";
    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        foreach (['id', 'total_size', 'files_count', 'piece_length', 'files_truncated'] as $k) {
            if ($r[$k] !== null) $r[$k] = (int)$r[$k];
        }
    }
    return $rows;
}

/**
 * Accept quarantined rows into the catalogue.
 *
 * $ids empty + $peer set = accept everything still pending from that peer (the only workable move
 * when a first sync parks twenty thousand rows). Returns a per-outcome tally rather than a bare
 * count, because "accepted 900 of 1000" is a different fact from "accepted 1000" and the 100 that
 * were skipped were skipped for a reason worth seeing.
 */
function fedReviewAccept(PDO $db, array $ids = [], string $peer = '', int $max = 5000): array {
    $tally = ['accepted' => 0, 'skipped' => 0, 'files' => 0];
    $rows = fedReviewFetchForDecision($db, $ids, $peer, $max);
    if (!$rows) return $tally;

    $upd = $db->prepare(
        "UPDATE index_hashes
            SET name = ?, total_size = ?, files_count = ?, piece_length = ?,
                meta_status = 'done', meta_source = ?, meta_fetched_at = NOW(),
                meta_origin_at = COALESCE(?, meta_origin_at, NOW()),
                meta_error = NULL, meta_claim = NULL, meta_priority = -1
          WHERE info_hash = ? AND meta_status NOT IN ('done', 'fetching')");
    $delFiles = $db->prepare("DELETE FROM index_files WHERE info_hash = ?");
    $insFile  = $db->prepare("INSERT INTO index_files (info_hash, path, size) VALUES (?, ?, ?)");
    $done     = $db->prepare("DELETE FROM fed_review WHERE id = ?");

    foreach ($rows as $r) {
        $db->beginTransaction();
        try {
            $upd->execute([
                $r['name'], $r['total_size'], $r['files_count'], $r['piece_length'],
                'fed:' . mb_substr((string)$r['peer_name'], 0, 20), $r['origin_at'], $r['info_hash'],
            ]);
            if ($upd->rowCount() > 0) {
                $tally['accepted']++;
                $files = $r['files_json'] ? json_decode((string)$r['files_json'], true) : null;
                if (is_array($files) && $files) {
                    $delFiles->execute([$r['info_hash']]);
                    foreach ($files as $f) {
                        if (!is_array($f) || count($f) < 2) continue;
                        $insFile->execute([$r['info_hash'], mb_substr((string)$f[0], 0, 512), max(0, (int)$f[1])]);
                        $tally['files']++;
                    }
                }
            } else {
                // The hash was resolved locally (or is not in the index at all) while it sat in the
                // queue. Local always wins — the same rule the fill path follows.
                $tally['skipped']++;
            }
            $done->execute([$r['id']]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
    return $tally;
}

/**
 * Reject: keep the row, marked, so the next pull does not offer it again. Rejections are the record
 * of a decision — deleting them would make the peer re-send the same package for ever.
 */
function fedReviewReject(PDO $db, array $ids = [], string $peer = '', int $max = 20000): int {
    $rows = fedReviewFetchForDecision($db, $ids, $peer, $max);
    if (!$rows) return 0;
    $n = 0;
    $st = $db->prepare("UPDATE fed_review SET state = 'rejected', decided_at = NOW(), files_json = NULL WHERE id = ? AND state = 'pending'");
    foreach (array_chunk($rows, 500) as $chunk) {
        $db->beginTransaction();
        foreach ($chunk as $r) { $st->execute([$r['id']]); $n += $st->rowCount(); }
        $db->commit();
    }
    return $n;
}

/** Forget a rejection, so the peer may offer the package again on the next pull. */
function fedReviewUnreject(PDO $db, array $ids = [], string $peer = ''): int {
    $rows = fedReviewFetchForDecision($db, $ids, $peer, 20000, 'rejected');
    if (!$rows) return 0;
    $st = $db->prepare("DELETE FROM fed_review WHERE id = ? AND state = 'rejected'");
    $n = 0;
    foreach (array_chunk($rows, 500) as $chunk) {
        $db->beginTransaction();
        foreach ($chunk as $r) { $st->execute([$r['id']]); $n += $st->rowCount(); }
        $db->commit();
    }
    return $n;
}

function fedReviewFetchForDecision(PDO $db, array $ids, string $peer, int $max, string $state = 'pending'): array {
    $max = max(1, min(50000, $max));
    if ($ids) {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (!$ids) return [];
        $ids = array_slice($ids, 0, $max);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT * FROM fed_review WHERE id IN ($in) AND state = ?");
        $st->execute(array_merge($ids, [$state]));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($peer === '') return [];
    $st = $db->prepare("SELECT * FROM fed_review WHERE peer_name = ? AND state = ? ORDER BY id LIMIT $max");
    $st->execute([$peer, $state]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ───────────────────────── undoing an import (F7) ─────────────────────────
 *
 * Parting with a partner should not mean writing SQL by hand at two in the morning. Everything a
 * peer contributed is tagged `meta_source = 'fed:<name>'`, so it can be found again exactly.
 *
 * Undo means putting each row back to unresolved — NOT deleting it. The hash itself was observed by
 * this tracker's own swarm; only the description came from the peer. Deleting the row would throw
 * away local history (first_seen, seen_count, the seeder peaks) that was never theirs to take.
 */
function fedPurgeSource(string $peerName): string { return 'fed:' . mb_substr(trim($peerName), 0, 20); }

/** What an undo would touch, without touching it. */
function fedPurgeCount(PDO $db, string $peerName): array {
    $src = fedPurgeSource($peerName);
    $st = $db->prepare("SELECT COUNT(*) FROM index_hashes WHERE meta_source = ?");
    $st->execute([$src]);
    $rows = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT COUNT(*) FROM index_files f JOIN index_hashes i ON i.info_hash = f.info_hash WHERE i.meta_source = ?");
    $st->execute([$src]);
    return ['source' => $src, 'rows' => $rows, 'files' => (int)$st->fetchColumn()];
}

/**
 * One batch of the undo. Batched and resumable on purpose: a peer that has been feeding this node
 * for a month can easily own a million rows, and a single statement over a million rows on a shared
 * MariaDB is how you take a mail server down as a side effect. Call until it returns 0.
 */
function fedPurgeBatch(PDO $db, string $peerName, int $limit = 500): int {
    $limit = max(1, min(5000, $limit));
    $src = fedPurgeSource($peerName);
    $st = $db->prepare("SELECT info_hash FROM index_hashes WHERE meta_source = ? LIMIT $limit");
    $st->execute([$src]);
    $hashes = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$hashes) return 0;
    $in = implode(',', array_fill(0, count($hashes), '?'));
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM index_files WHERE info_hash IN ($in)")->execute($hashes);
        $db->prepare(
            "UPDATE index_hashes
                SET name = NULL, total_size = NULL, files_count = NULL, piece_length = NULL,
                    meta_status = 'none', meta_source = NULL, meta_fetched_at = NULL,
                    meta_origin_at = NULL, meta_error = NULL, meta_claim = NULL,
                    meta_priority = -1, meta_requested_at = NULL, meta_claimed_at = NULL
              WHERE info_hash IN ($in)")->execute($hashes);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return count($hashes);
}
