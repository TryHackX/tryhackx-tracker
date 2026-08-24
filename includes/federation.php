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
function fedPullMinutes(array $cfg): int { return max(5, min(1440, (int)($cfg['fed_pull_minutes'] ?? 60) ?: 60)); }

/**
 * One export page. Cursor = (since = UNIX ts of meta_fetched_at, after_hash = tie-break within the
 * same second). Returns ['rows'=>[], 'next'=>['since'=>int,'after'=>hash]|null, 'has_more'=>bool].
 * Row shape (short keys — packages are large): h, n (name), s (size), fc (files count),
 * pl (piece length), sl [seeders, leechers], seen {f,l,c}, mf (meta_fetched_at ts), files [[p,sz]..].
 */
function fedExportRows(PDO $db, array $cfg, int $since, string $afterHash, int $limit, bool $withFiles): array {
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
                first_seen, last_seen, seen_count, UNIX_TIMESTAMP(meta_fetched_at) AS mf
         FROM index_hashes i
         WHERE meta_status = 'done' AND meta_fetched_at IS NOT NULL
           AND meta_fetched_at < FROM_UNIXTIME(UNIX_TIMESTAMP())
           AND (meta_fetched_at > FROM_UNIXTIME(?) OR (meta_fetched_at = FROM_UNIXTIME(?) AND info_hash > ?))
           AND NOT EXISTS (SELECT 1 FROM banned_hashes b WHERE b.info_hash = i.info_hash)
           AND NOT EXISTS (SELECT 1 FROM whitelist w WHERE w.info_hash = i.info_hash)
         ORDER BY meta_fetched_at, info_hash
         LIMIT " . ($limit + 1));
    $st->execute([$since, $since, $afterHash]);
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
    if (!preg_match('#^https?://[^\s]+$#i', $url)) return ['error' => 'Base URL must be an http(s) URL (the peer site root, no /api.php)'];
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
