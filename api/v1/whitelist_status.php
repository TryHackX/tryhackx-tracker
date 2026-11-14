<?php
/**
 * GET/POST v1/whitelist/status — what happened to the hashes a partner sent.
 *
 *   Authorization: Bearer <key_id>.<secret>
 *   GET  v1/whitelist/status?hash=<40 hex>[,<40 hex>…]
 *   POST v1/whitelist/status  {"items":["<40 hex>","magnet:?xt=urn:btih:…"]}
 *
 * ── why this endpoint exists ───────────────────────────────────────────────────────────────────
 * A key with `auto_approve = 0` gets `pending` back from v1/whitelist/submit, and then the partner's
 * system has no way to find out what happened next. A person on this side approves the row or turns
 * it down with a note explaining why — and that note was written FOR the partner, who could not read
 * it. The loop was open at exactly the point where it mattered: the submitter learned nothing, so
 * they either re-submitted the same thing or gave up on it.
 *
 * ── who may see the note ───────────────────────────────────────────────────────────────────────
 * The status of a hash is answerable to any key with the whitelist scope: v1/whitelist/submit
 * already says `exists` for a hash the tracker holds, so nothing new is being disclosed. The NOTE is
 * different — it is a message from a moderator to a particular partner about a particular
 * submission — so it is returned only to the key that submitted the row. Everybody else sees the
 * status and `mine: false`.
 *
 * Response: {"ok":true,"results":[{"index","input","hash","status","served","mine","name",
 *            "review_note","reviewed_at","submitted_at"}],"summary":{…},"server_time":T}
 *
 *   status: unknown  — this tracker has no row for it
 *           pending  — waiting for a person; NOT being served
 *           rejected — a person turned it down; not being served, `review_note` says why
 *           live     — being served (approved, or from a key that publishes directly)
 *           banned   — refused by this tracker; nothing will change that from out here
 */
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => __('api.common.method_not_allowed')], 405);
}
$rawBody = $_SERVER['REQUEST_METHOD'] === 'POST' ? apiReadRawBody() : '';
$client  = apiAuthenticate($db, $cfg, 'v1/whitelist/status', $rawBody);
apiRequireScope($client, 'whitelist');

// Both shapes, because both are natural: a dashboard polling one row uses the query string, and a
// nightly reconciliation of a backlog posts the list.
$items = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string)$rawBody, true);
    if (!is_array($payload)) {
        jsonResponse(['ok' => false, 'error' => 'invalid_json', 'message' => 'Body must be a JSON object.'], 422);
    }
    $items = (array)($payload['items'] ?? $payload['hashes'] ?? []);
} else {
    $q = (string)($_GET['hash'] ?? $_GET['hashes'] ?? '');
    if ($q !== '') $items = preg_split('/[\s,]+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
$items = array_values($items);
if (!$items)                          jsonResponse(['ok' => false, 'error' => 'no_items'], 422);
if (count($items) > API_MAX_ITEMS)    jsonResponse(['ok' => false, 'error' => 'too_many_items', 'max' => API_MAX_ITEMS], 422);

// The same parser the submit endpoint uses, so a partner can hand back exactly what they sent —
// a magnet link, a bare hash, whatever their own records hold — rather than having to normalise it
// first to ask about it.
$parsed = [];
$wanted = [];
foreach ($items as $i => $it) {
    $token = is_array($it) ? (string)($it['magnet'] ?? $it['hash'] ?? '') : (string)$it;
    $p = parseMagnetOrHash(trim($token));
    $parsed[$i] = $p;
    if ($p['hash'] !== null) $wanted[strtolower($p['hash'])] = true;
}

// One query for the whole batch, keyed by the column that is UNIQUE. Chunked for the same reason
// the review endpoint chunks: a native prepare is capped at 65 535 placeholders and a partner's
// backlog is allowed to be long.
$rows = [];
foreach (array_chunk(array_keys($wanted), WL_SQL_IN_CHUNK) as $chunk) {
    if (!$chunk) continue;
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $st = $db->prepare("SELECT info_hash, name, banned, review_status, review_note, reviewed_at,
                               created_at, api_client_id
                          FROM whitelist WHERE info_hash IN ($ph)");
    $st->execute($chunk);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[strtolower((string)$r['info_hash'])] = $r;
}

$results = [];
$summary = ['unknown' => 0, 'pending' => 0, 'rejected' => 0, 'live' => 0, 'banned' => 0, 'invalid' => 0];
foreach ($parsed as $i => $p) {
    $input = mb_substr((string)($p['input'] ?? ''), 0, 200);
    if ($p['hash'] === null) {
        $summary['invalid']++;
        $results[] = ['index' => $i, 'input' => $input, 'hash' => null, 'status' => 'invalid',
                      'error' => $p['error'] ?? 'invalid'];
        continue;
    }
    $hash = strtolower($p['hash']);
    $row  = $rows[$hash] ?? null;
    if (!$row) {
        $summary['unknown']++;
        $results[] = ['index' => $i, 'input' => $input, 'hash' => $hash, 'status' => 'unknown',
                      'served' => false, 'mine' => false];
        continue;
    }
    // The same reading the accesslist generator makes: banned first, then the review column, and
    // 'none' is served because it is every row that predates the review feature.
    $review = (string)$row['review_status'];
    $status = (int)$row['banned'] === 1 ? 'banned'
            : ($review === 'pending' ? 'pending'
            : ($review === 'rejected' ? 'rejected' : 'live'));
    $mine = (int)($row['api_client_id'] ?? 0) === (int)$client['id'];
    $summary[$status]++;
    $results[] = [
        'index'        => $i,
        'input'        => $input,
        'hash'         => $hash,
        'status'       => $status,
        'served'       => $status === 'live',
        'mine'         => $mine,
        'name'         => $row['name'] !== null ? (string)$row['name'] : null,
        // Written by a moderator to the partner who sent the row, and returned to nobody else.
        'review_note'  => $mine ? ($row['review_note'] !== null ? (string)$row['review_note'] : null) : null,
        'reviewed_at'  => $row['reviewed_at'] !== null ? date('c', strtotime((string)$row['reviewed_at'])) : null,
        'submitted_at' => $row['created_at'] !== null ? date('c', strtotime((string)$row['created_at'])) : null,
    ];
}

jsonResponse([
    'ok' => true,
    'results' => $results,
    'summary' => $summary,
    'mode' => trackerMode($cfg),
    'server_time' => time(),
]);
