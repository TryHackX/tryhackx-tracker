<?php
/**
 * POST v1/blacklist/submit — server-to-server: file abuse reports against info hashes.
 *
 *   Authorization: Bearer <key_id>.<secret>
 *   Content-Type: application/json
 *   {"reporter":{"name":"Acme Pictures","representative":"Jan Kowalski",
 *                "company":"Acme Anti-Piracy","email":"abuse@acme.example"},
 *    "statement":true,
 *    "items":[{"magnet":"magnet:?xt=urn:btih:…","title":"Some Film (2026)",
 *              "evidence_url":"https://acme.example/catalogue/1234","reason":"Unlicensed copy"},
 *             {"hash":"<40 hex>","title":"…"}]}
 *
 * Response: {"ok":true,"results":[{"index","input","hash","status","report_id","error"}],
 *            "summary":{"received","blocked","duplicate","already_blocked","invalid"},
 *            "auto_block":bool,"required_fields":[…],"server_time":T}
 * Errors: 401 (no Authorization), 403 forbidden (banned / bad key / wrong scope), 405, 413, 422.
 *
 * ── why this is not v1/whitelist/submit with a flag ────────────────────────────────────────────
 *
 * Registering a hash and blocking one are not the same authority. A forum that lists releases is
 * trusted to say "this exists"; it is not thereby trusted to say "take this off the tracker". So
 * this endpoint has a scope of its own (`abuse`), and a key needs that scope even if it already
 * holds `whitelist`.
 *
 * ── and why the default is review, the opposite of the whitelist's ────────────────────────────
 *
 * `api_clients.auto_approve` defaults to 1: a partner's registrations go straight through, because
 * the cost of being wrong is a hash in a catalogue. `api_clients.abuse_auto_block` defaults to 0,
 * because the cost of being wrong here is a torrent that stops working for everybody who has it —
 * and nobody outside this server can undo that. An operator who trusts a rights holder that far has
 * to say so, per key, in the panel.
 */
requirePost();

$rawBody = apiReadRawBody();
$client  = apiAuthenticate($db, $cfg, 'v1/blacklist/submit', $rawBody);
apiRequireScope($client, 'abuse');

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
    jsonResponse(['ok' => false, 'error' => 'invalid_json', 'message' => 'Body must be a JSON object with an "items" array.'], 422);
}
$items = array_values($payload['items']);
if (count($items) === 0) jsonResponse(['ok' => false, 'error' => 'no_items'], 422);
if (count($items) > API_MAX_ITEMS) jsonResponse(['ok' => false, 'error' => 'too_many_items', 'max' => API_MAX_ITEMS], 422);

$autoBlock = (int)($client['abuse_auto_block'] ?? 0) === 1;
$required  = apiClientCleanFields((string)($client['required_fields'] ?? ''), 'abuse');
$clientId  = (int)($client['id'] ?? 0);
$ip        = getClientIp($cfg);

/** The reporter block, top level, with a per-item override — one filing is usually one company. */
$reporterOf = static function ($src) : array {
    $g = static fn($k) => (is_array($src) && isset($src[$k]) && is_scalar($src[$k])) ? trim((string)$src[$k]) : '';
    return [
        'name'           => mb_substr($g('name'), 0, 255),
        'representative' => mb_substr($g('representative'), 0, 255),
        'company'        => mb_substr($g('company'), 0, 255),
        'email'          => mb_substr($g('email'), 0, 255),
    ];
};
$baseReporter = $reporterOf($payload['reporter'] ?? null);
// A declaration is a statement by the sender about themselves, so it belongs to the batch. It is
// accepted as a boolean OR as the sentence itself, because an integrator will send whichever they
// have — and both mean "yes, we are saying this on the record".
$baseStatement = $payload['statement'] ?? null;
$hasStatement = static fn($v) => $v === true || $v === 1 || $v === '1'
                              || (is_string($v) && trim($v) !== '' && strtolower(trim($v)) !== 'false');

$maxMsg = (int)($cfg['max_message_length'] ?? 2000);
$results = [];
$summary = ['received' => 0, 'blocked' => 0, 'duplicate' => 0, 'already_blocked' => 0, 'invalid' => 0];

$dupSt   = $db->prepare("SELECT id FROM reports WHERE infoHash = ? LIMIT 1");
$insSt   = $db->prepare(
    "INSERT INTO reports (name, representative, company, email, objectTitle, link, infoHash,
                          magnet_link, ip, add_message, checked, blocked, api_client_id, timestamp)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, NOW())");

foreach ($items as $i => $it) {
    $row = ['index' => $i, 'input' => null, 'hash' => null, 'status' => 'invalid', 'report_id' => null, 'error' => null];
    $token = '';
    if (is_array($it)) {
        if (!empty($it['magnet']) && is_string($it['magnet'])) $token = $it['magnet'];
        elseif (!empty($it['hash']) && is_string($it['hash'])) $token = $it['hash'];
    } elseif (is_string($it)) {
        $token = $it;
    }
    $row['input'] = mb_substr(trim($token), 0, 120);
    $p = parseMagnetOrHash(trim($token));
    if ($p['hash'] === null) {
        $row['error'] = $p['error'] ?? 'invalid_hash';
        $summary['invalid']++; $results[] = $row; continue;
    }
    $hash = strtolower($p['hash']);
    $row['hash'] = $hash;

    $title    = is_array($it) && isset($it['title']) && is_string($it['title']) ? trim($it['title']) : (string)($p['name'] ?? '');
    $evidence = is_array($it) && isset($it['evidence_url']) && is_string($it['evidence_url']) ? trim($it['evidence_url']) : '';
    $reason   = is_array($it) && isset($it['reason']) && is_string($it['reason']) ? trim($it['reason']) : '';
    $reporter = (is_array($it) && isset($it['reporter']) && is_array($it['reporter']))
        ? $reporterOf($it['reporter']) : $baseReporter;
    $statement = (is_array($it) && array_key_exists('statement', $it)) ? $it['statement'] : $baseStatement;

    // The same validator the public form uses: FILTER_VALIDATE_URL accepts `javascript://x/%0aalert(1)`
    // and this value is echoed into an href in the panel.
    $evidence = $evidence === '' ? '' : (richtextSafeUrl($evidence) ?? '');

    // WHAT THIS KEY WAS TOLD TO SEND, refused at the door with the field named. A queue of reports
    // nobody can act on is not a queue — the operator asked for evidence because a claim without it
    // cannot be reviewed, and answering "accepted" to one would be a lie of omission.
    $have = [
        'title'        => $title !== '',
        'evidence_url' => $evidence !== '',
        'reason'       => $reason !== '',
        'reporter'     => $reporter['name'] !== '' && $reporter['representative'] !== ''
                          && $reporter['company'] !== '' && filter_var($reporter['email'], FILTER_VALIDATE_EMAIL) !== false,
        'statement'    => $hasStatement($statement),
    ];
    $missing = array_values(array_filter($required, static fn($f) => empty($have[$f])));
    if ($missing) {
        $row['error'] = 'missing_' . $missing[0];
        $summary['invalid']++; $results[] = $row; continue;
    }

    // Already blocked here: not an error and not a new row. The partner's own system asked for
    // something that is already true, and telling them so is more use than a duplicate report.
    if (isHashBlocked($db, $cfg, $hash)) {
        $row['status'] = 'already_blocked';
        $summary['already_blocked']++; $results[] = $row; continue;
    }
    // One open report per hash, the same rule the public form enforces — two people reporting the
    // same torrent is one thing to look at, not two.
    $dupSt->execute([$hash]);
    $existing = (int)($dupSt->fetchColumn() ?: 0);
    if ($existing > 0) {
        $row['status'] = 'duplicate';
        $row['report_id'] = $existing;
        $summary['duplicate']++; $results[] = $row; continue;
    }

    $magnet = str_starts_with(strtolower(trim($token)), 'magnet:') ? mb_substr(trim($token), 0, 2000) : null;
    $insSt->execute([
        mb_substr($reporter['name'], 0, 255),
        mb_substr($reporter['representative'], 0, 255),
        mb_substr($reporter['company'], 0, 255),
        mb_substr($reporter['email'], 0, 255),
        mb_substr($title, 0, 255),
        mb_substr($evidence, 0, 500),
        $hash,
        $magnet,
        $ip,
        mb_substr($reason, 0, max(1, $maxMsg)),
        $clientId ?: null,
    ]);
    $reportId = (int)$db->lastInsertId();
    $row['report_id'] = $reportId;
    $row['status'] = 'received';
    $summary['received']++;

    if ($autoBlock) {
        // Exactly what the panel's own Block button does, in the same order: the row is marked
        // first, the tracker second, and the record is archived last. No mail: the answer to this
        // request IS the notification, and it reaches the partner before an e-mail could.
        $db->prepare("UPDATE reports SET blocked = 1, checked = 1 WHERE id = ?")->execute([$reportId]);
        $block = trackerBlockHash($db, $cfg, $hash, [
            'source' => 'report', 'source_id' => $reportId,
            'reason' => 'API report #' . $reportId . ' (' . ($client['label'] ?? $client['key_id']) . ')',
        ]);
        $full = $db->prepare("SELECT * FROM reports WHERE id = ?");
        $full->execute([$reportId]);
        $saved = $full->fetch(PDO::FETCH_ASSOC);
        if ($saved) archiveReport($db, $saved);
        $row['status'] = 'blocked';
        $summary['blocked']++; $summary['received']--;
        // Honest about the half that can fail on its own: the row is blocked in the database even
        // when the file could not be written, and a partner told "blocked" deserves to know which.
        if (!$block['file_ok']) $row['error'] = 'blocked_in_database_only';
    }
    $results[] = $row;
}

// One line per BATCH, not per item: the interesting fact is that a partner filed n reports and how
// many of them the tracker acted on by itself. apiAuthenticate() has already named the actor.
auditLog($db, 'api.abuse.submit', [
    'target_type' => 'api_client', 'target_id' => $clientId,
    'summary' => ($client['label'] ?? $client['key_id']) . ' — ' . count($items) . ' item(s)',
    'detail' => $summary + ['auto_block' => $autoBlock],
]);

jsonResponse([
    'ok' => true,
    'results' => $results,
    'summary' => $summary,
    'auto_block' => $autoBlock,
    'required_fields' => $required,
    'server_time' => time(),
]);
