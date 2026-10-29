<?php

function wlReqOn(array $cfg): bool
{
    return ($cfg['wl_req_enabled'] ?? '1') === '1';
}

function wlReqReady(PDO $db): bool
{
    static $ready = false;
    if ($ready) return true;
    try {
        $ready = (bool)$db->query("SHOW TABLES LIKE 'wl_request_votes'")->fetchColumn();
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function wlReqDays(array $cfg): int
{
    return max(0, min(365, (int)($cfg['wl_req_days'] ?? 7)));
}

function wlReqPerDay(array $cfg): int
{
    return max(1, min(100, (int)($cfg['wl_req_per_day'] ?? 5)));
}

function wlReqClean(string $text): string
{
    return trim(mb_substr((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[\x00-\x1F\x7F<>]/u', ' ', $text)), 0, 300));
}

function wlReqBanned(PDO $db, string $hash): bool
{
    try {
        $st = $db->prepare("SELECT 1 FROM banned_hashes WHERE info_hash = ? LIMIT 1");
        $st->execute([$hash]);
        if ($st->fetchColumn()) return true;
    } catch (\Throwable $e) {
        error_log('[wl requests] banned_hashes: ' . $e->getMessage());
    }
    $st = $db->prepare("SELECT 1 FROM whitelist WHERE info_hash = ? AND banned = 1 LIMIT 1");
    $st->execute([$hash]);
    return (bool)$st->fetchColumn();
}

function wlReqListed(PDO $db, string $hash): bool
{
    $st = $db->prepare("SELECT 1 FROM whitelist WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    return (bool)$st->fetchColumn();
}

function wlReqState(PDO $db, array $cfg, string $hash, ?array $me): ?array
{
    if (!$me || !wlReqOn($cfg) || !wlReqReady($db) || !userCan($db, $cfg, 'whitelist.request') || wlReqListed($db, $hash)) return null;
    $st = $db->prepare("SELECT 1 FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    if (!$st->fetchColumn() || wlReqBanned($db, $hash)) return null;
    $out = ['state' => 'none', 'n' => 0, 'mine' => false, 'again' => null, 'note' => ($cfg['wl_req_note'] ?? '1') === '1'];
    $st = $db->prepare("SELECT r.supporters, EXISTS(SELECT 1 FROM wl_request_votes v WHERE v.request_id = r.id AND v.user_id = ?) AS mine
                          FROM wl_requests r WHERE r.info_hash = ? AND r.open_slot = 1");
    $st->execute([(int)$me['id'], $hash]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) return ['state' => 'open', 'n' => (int)$r['supporters'], 'mine' => (bool)$r['mine']] + $out;
    $days = wlReqDays($cfg);
    if ($days > 0) {
        $st = $db->prepare("SELECT decided_at + INTERVAL $days DAY FROM wl_requests
                             WHERE info_hash = ? AND status = 'rejected' AND decided_at > UTC_TIMESTAMP() - INTERVAL $days DAY ORDER BY decided_at DESC LIMIT 1");
        $st->execute([$hash]);
        $again = $st->fetchColumn();
        if ($again) return ['state' => 'rejected', 'again' => (string)$again] + $out;
    }
    return $out;
}

function wlReqSubmit(PDO $db, array $cfg, string $hash, int $userId, string $note): array
{
    if (!wlReqOn($cfg) || !wlReqReady($db)) return ['ok' => false, 'error' => 'off'];
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return ['ok' => false, 'error' => 'invalid'];
    if (wlReqListed($db, $hash)) return ['ok' => false, 'error' => 'listed'];
    $st = $db->prepare("SELECT name FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    $name = $st->fetchColumn();
    if ($name === false || wlReqBanned($db, $hash)) return ['ok' => false, 'error' => 'not_found'];
    $days = wlReqDays($cfg);
    if ($days > 0) {
        $st = $db->prepare("SELECT 1 FROM wl_requests WHERE info_hash = ? AND status = 'rejected' AND decided_at > UTC_TIMESTAMP() - INTERVAL $days DAY LIMIT 1");
        $st->execute([$hash]);
        if ($st->fetchColumn()) return ['ok' => false, 'error' => 'cooldown'];
    }
    $find = $db->prepare("SELECT id FROM wl_requests WHERE info_hash = ? AND open_slot = 1");
    $find->execute([$hash]);
    $id = (int)$find->fetchColumn();
    if ($id) {
        $st = $db->prepare("SELECT 1 FROM wl_request_votes WHERE request_id = ? AND user_id = ?");
        $st->execute([$id, $userId]);
        if ($st->fetchColumn()) return ['ok' => true, 'id' => $id, 'already' => true];
    }
    $st = $db->prepare("SELECT COUNT(*) FROM wl_request_votes WHERE user_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY");
    $st->execute([$userId]);
    if ((int)$st->fetchColumn() >= wlReqPerDay($cfg)) return ['ok' => false, 'error' => 'limit'];
    $note = ($cfg['wl_req_note'] ?? '1') === '1' ? wlReqClean($note) : '';
    if (!$id) {
        try {
            $db->prepare("INSERT INTO wl_requests (info_hash, name, status, open_slot, user_id, note, supporters, created_at) VALUES (?, ?, 'open', 1, ?, ?, 0, UTC_TIMESTAMP())")
               ->execute([$hash, mb_substr((string)$name, 0, 500), $userId, $note !== '' ? $note : null]);
            $id = (int)$db->lastInsertId();
        } catch (\PDOException $e) {
            $find->execute([$hash]);
            $id = (int)$find->fetchColumn();
            if (!$id) throw $e;
        }
    }
    $db->prepare("INSERT IGNORE INTO wl_request_votes (request_id, user_id, note, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")
       ->execute([$id, $userId, $note !== '' ? $note : null]);
    $db->prepare("UPDATE wl_requests SET supporters = (SELECT COUNT(*) FROM wl_request_votes WHERE request_id = ?) WHERE id = ?")->execute([$id, $id]);
    return ['ok' => true, 'id' => $id];
}

function wlReqOpenCount(PDO $db): int
{
    if (!wlReqReady($db)) return 0;
    return (int)$db->query("SELECT COUNT(*) FROM wl_requests WHERE status = 'open'")->fetchColumn();
}

function wlReqList(PDO $db, string $status, int $page): array
{
    if (!wlReqReady($db)) return ['rows' => [], 'total' => 0, 'open' => 0, 'page' => 1, 'pages' => 1];
    $status = in_array($status, ['open', 'approved', 'rejected'], true) ? $status : 'open';
    $per = 50;
    $st = $db->prepare("SELECT COUNT(*) FROM wl_requests WHERE status = ?");
    $st->execute([$status]);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min($pages, $page));
    $order = $status === 'open' ? 'r.supporters DESC, r.created_at, r.id' : 'r.decided_at DESC, r.id DESC';
    $st = $db->prepare("SELECT r.id, r.info_hash, r.name, r.status, r.supporters, r.note, r.created_at, r.decided_at, r.reply,
                               u.username AS who, d.username AS decided_name, h.last_seen, COALESCE(h.scrape_seeders, h.last_seeders) AS seeders, h.total_size
                          FROM wl_requests r LEFT JOIN users u ON u.id = r.user_id LEFT JOIN users d ON d.id = r.decided_by
                          LEFT JOIN index_hashes h ON h.info_hash = r.info_hash
                         WHERE r.status = ? ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute([$status]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $ids = array_map('intval', array_column($rows, 'id'));
        $vs = $db->prepare("SELECT v.request_id, u.username, v.note FROM wl_request_votes v LEFT JOIN users u ON u.id = v.user_id
                             WHERE v.request_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY v.created_at");
        $vs->execute($ids);
        $by = [];
        foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) $by[(int)$v['request_id']][] = ['who' => $v['username'], 'note' => $v['note']];
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['supporters'] = (int)$r['supporters'];
            $r['seeders'] = $r['seeders'] !== null ? (int)$r['seeders'] : null;
            $r['total_size'] = $r['total_size'] !== null ? (int)$r['total_size'] : null;
            $r['votes'] = array_slice($by[$r['id']] ?? [], 0, 20);
        }
        unset($r);
    }
    return ['rows' => $rows, 'total' => $total, 'open' => wlReqOpenCount($db), 'page' => $page, 'pages' => $pages];
}

function wlReqDecide(PDO $db, array $cfg, int $id, string $op, string $reply, ?int $actorId): array
{
    if (!wlReqReady($db) || !in_array($op, ['approve', 'reject'], true)) return ['ok' => false, 'error' => 'invalid'];
    $st = $db->prepare("SELECT * FROM wl_requests WHERE id = ? AND status = 'open'");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['ok' => false, 'error' => 'not_found'];
    $hash = (string)$r['info_hash'];
    if ($op === 'approve' && !wlReqListed($db, $hash)) {
        if (wlReqBanned($db, $hash)) return ['ok' => false, 'error' => 'banned'];
        $st = $db->prepare("SELECT name FROM index_hashes WHERE info_hash = ? LIMIT 1");
        $st->execute([$hash]);
        $name = (string)($st->fetchColumn() ?: '');
        whitelistAddHashes($db, $cfg, [['hash' => $hash, 'name' => $name !== '' ? $name : (string)$r['name'], 'input' => $hash]],
                           ['source' => 'admin', 'ip' => '', 'auto_meta' => true, 'submitter_id' => (int)$r['user_id'], 'submitter_public' => false]);
        if (!wlReqListed($db, $hash)) return ['ok' => false, 'error' => 'add_failed'];
        $db->prepare("UPDATE index_hashes SET promoted_at = NOW() WHERE info_hash = ?")->execute([$hash]);
        if (function_exists('indexStatusCacheDrop')) indexStatusCacheDrop();
    }
    $reply = wlReqClean($reply);
    $db->prepare("UPDATE wl_requests SET status = ?, open_slot = NULL, decided_by = ?, decided_at = UTC_TIMESTAMP(), reply = ? WHERE id = ?")
       ->execute([$op === 'approve' ? 'approved' : 'rejected', $actorId, $reply !== '' ? $reply : null, $id]);
    wlReqNotify($db, $cfg, $r, $op, $reply);
    return ['ok' => true];
}

function wlReqNotify(PDO $db, array $cfg, array $r, string $op, string $reply): void
{
    if (!function_exists('userNotify')) return;
    $st = $db->prepare("SELECT user_id FROM wl_request_votes WHERE request_id = ?");
    $st->execute([(int)$r['id']]);
    $name = mb_substr((string)$r['name'], 0, 120);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $lang = function_exists('recipientLangOf') ? recipientLangOf($db, $cfg, (int)$uid) : null;
        try {
            userNotify($db, (int)$uid, 'wl_request', langFor($lang, $op === 'approve' ? 'wlreq.n_approved' : 'wlreq.n_rejected', ['name' => $name]),
                       $reply, '?action=search&hash=' . $r['info_hash']);
        } catch (\Throwable $e) {
            error_log('[wl requests] notify ' . (int)$uid . ': ' . $e->getMessage());
        }
    }
}

function wlReqTick(PDO $db): void
{
    if (!wlReqReady($db)) return;
    $db->exec("UPDATE wl_requests r JOIN whitelist w ON w.info_hash = r.info_hash
                  SET r.status = 'approved', r.open_slot = NULL, r.decided_at = UTC_TIMESTAMP()
                WHERE r.open_slot = 1 AND w.banned = 0");
    $db->exec("DELETE v FROM wl_request_votes v JOIN wl_requests r ON r.id = v.request_id
                WHERE r.status <> 'open' AND r.decided_at < UTC_TIMESTAMP() - INTERVAL 365 DAY");
    $db->exec("DELETE FROM wl_requests WHERE status <> 'open' AND decided_at < UTC_TIMESTAMP() - INTERVAL 365 DAY");
}
