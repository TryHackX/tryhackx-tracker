<?php
/**
 * POST v1/whitelist/submit — server-to-server: register info hashes / magnet links.
 *
 *   Authorization: Bearer <key_id>.<secret>
 *   Content-Type: application/json
 *   {"items":[{"magnet":"magnet:?xt=urn:btih:…","name":"optional","ref":{"post_id":1,"discussion_id":2,"url":"https://…"}},
 *             {"hash":"<40 hex>"}],
 *    "source":"forum"}
 *
 * Idempotent (re-posting → "exists"). Additive only — there is deliberately no remove endpoint.
 * Response: {"ok":true,"results":[{"index","input","hash","status":"added|exists|banned|invalid","error"}],
 *            "summary":{"added","exists","banned","invalid"},"active_in_seconds":N,"server_time":T}
 * Errors: 401 (no Authorization), 403 forbidden (banned / bad key — see api_auth.php), 405, 413, 422.
 */
requirePost();

$rawBody = apiReadRawBody();
$client  = apiAuthenticate($db, $cfg, 'v1/whitelist/submit', $rawBody);
apiRequireScope($client, 'whitelist');

$payload = json_decode((string)$rawBody, true);
if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
    jsonResponse(['ok' => false, 'error' => 'invalid_json', 'message' => 'Body must be a JSON object with an "items" array.'], 422);
}
$items = array_values($payload['items']);
if (count($items) === 0) {
    jsonResponse(['ok' => false, 'error' => 'no_items'], 422);
}
if (count($items) > API_MAX_ITEMS) {
    jsonResponse(['ok' => false, 'error' => 'too_many_items', 'max' => API_MAX_ITEMS], 422);
}
$source = (isset($payload['source']) && $payload['source'] === 'forum') ? 'forum' : 'api';

// ── what THIS key is allowed to do, and what it must send ──────────────────────────────────────
//
// Two settings live on the key rather than on the site, because a partner is not a policy: one feed
// is trusted enough to publish straight to the tracker and another is not, and the operator decides
// that per partner when they hand out the key. Both are on the row api_authenticate() already read,
// so this costs nothing.
//
// auto_approve = 0 puts every row this key creates into the review queue on the Whitelist page. The
// accesslist generator withholds `pending`, so nothing is served until a person says so — and the
// reply says `pending` rather than `added`, so the partner's own system can show the right thing.
$autoApprove = (int)($client['auto_approve'] ?? 1) === 1;
$requiredFields = array_values(array_filter(array_map('trim', explode(',', (string)($client['required_fields'] ?? '')))));

$parsed = [];
foreach ($items as $i => $it) {
    $token = '';
    $name = null; $ref = null;
    if (is_array($it)) {
        if (!empty($it['magnet']) && is_string($it['magnet'])) $token = $it['magnet'];
        elseif (!empty($it['hash']) && is_string($it['hash'])) $token = $it['hash'];
        if (isset($it['name']) && is_string($it['name'])) $name = cleanTorrentName($it['name']);
        if (isset($it['ref']) && is_array($it['ref'])) {
            $ref = [];
            foreach (['post_id', 'discussion_id'] as $k) if (isset($it['ref'][$k]) && is_numeric($it['ref'][$k])) $ref[$k] = (int)$it['ref'][$k];
            if (!empty($it['ref']['url']) && is_string($it['ref']['url']) && preg_match('#^https?://#i', $it['ref']['url'])) $ref['url'] = mb_substr($it['ref']['url'], 0, 300);
        }
    } elseif (is_string($it)) {
        $token = $it;
    }
    $p = parseMagnetOrHash(trim($token));
    if ($p['name'] === null && $name !== null) $p['name'] = $name;
    $p['ref'] = $ref;
    // A missing required field is refused HERE, as `invalid`, with the field named. Refusing at the
    // door beats accepting a row nobody can identify: the operator asked for a title because a
    // review queue of unnamed hashes cannot be reviewed.
    if ($p['hash'] !== null && $requiredFields) {
        $have = [
            'name'      => $p['name'] !== null && trim((string)$p['name']) !== '',
            'url'       => is_array($ref) && !empty($ref['url']),
            'source_id' => is_array($ref) && (!empty($ref['post_id']) || !empty($ref['discussion_id'])),
        ];
        $missing = array_values(array_filter($requiredFields, static fn($f) => empty($have[$f])));
        if ($missing) {
            $p['hash'] = null;
            $p['error'] = 'missing_' . $missing[0];
        }
    }
    $parsed[$i] = $p;
}

// whitelistAddHashes works on a flat item list; refs differ per item so we run it in groups by ref
// (a forum batch usually has one ref per post — still cheap). Keep result indexes stable.
$results = array_fill(0, count($parsed), null);
$summary = ['added' => 0, 'exists' => 0, 'banned' => 0, 'invalid' => 0, 'pending' => 0];
$groups = [];
foreach ($parsed as $i => $p) {
    $gk = $p['ref'] ? json_encode($p['ref']) : '';
    $groups[$gk][] = $i;
}
$activeIn = 0;
foreach ($groups as $gk => $idxs) {
    $chunk = [];
    foreach ($idxs as $i) $chunk[] = $parsed[$i];
    $r = whitelistAddHashes($db, $cfg, $chunk, [
        'source' => $source, 'ip' => getClientIp($cfg), 'api_client_id' => (int)$client['id'],
        'ref' => $gk !== '' ? json_decode($gk, true) : null,
        'review' => $autoApprove ? 'none' : 'pending',
    ]);
    foreach ($r['results'] as $j => $res) {
        $i = $idxs[$j];
        // A key that does not publish directly gets `pending` back rather than `added`. The row was
        // created either way; what differs is whether the tracker is serving it, and a partner whose
        // dashboard says "added" for something nobody has looked at yet has been misled.
        $status = ($res['status'] === 'added' && !$autoApprove) ? 'pending' : $res['status'];
        $results[$i] = ['index' => $i, 'input' => mb_substr((string)$res['input'], 0, 200), 'hash' => $res['hash'], 'status' => $status, 'error' => $res['error']];
    }
    foreach (['exists', 'banned', 'invalid'] as $k) $summary[$k] += (int)($r['summary'][$k] ?? 0);
    // The row was created either way; the column it lands in says whether the tracker is serving it.
    $summary[$autoApprove ? 'added' : 'pending'] += (int)($r['summary']['added'] ?? 0);
    $activeIn = max($activeIn, (int)($r['active_in_seconds'] ?? 0));
}

jsonResponse([
    'ok' => true,
    'results' => $results,
    'summary' => $summary,
    'active_in_seconds' => $activeIn,
    'mode' => trackerMode($cfg),
    // Said in the reply rather than left to be inferred: a partner integrating against this needs to
    // know whether "accepted" means "being served" or "queued for a person".
    'auto_approve' => $autoApprove,
    'required_fields' => $requiredFields,
    'server_time' => time(),
]);
