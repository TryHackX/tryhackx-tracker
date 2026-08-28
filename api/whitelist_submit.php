<?php
/**
 * POST whitelist_submit — public, anonymous registration of info hashes / magnet links.
 * CSRF (session token) → mode/public/captcha-configured gates → CAPTCHA (always) → rate limits →
 * parse → daily caps → whitelistAddHashes(source=web). See README "Public registration".
 */
requirePost();

$input = readJsonBody();

if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}
// Registration is open in whitelist mode and, under a SCHEDULE, in blacklist mode too (the hashes are
// served during the next whitelist hours; the file is regenerated at the switch).
if ((trackerMode($cfg) !== 'whitelist' && !scheduleEnabled($cfg)) || ($cfg['whitelist_public_enabled'] ?? '1') !== '1') {
    jsonResponse(['error' => 'registration_disabled'], 400);
}

// Audience: 'public' (anyone + CAPTCHA) or 'users' (signed-in accounts with whitelist.add — no
// CAPTCHA; accounts are the abuse gate). 'users' with the account system off falls back to public.
$submitMode = (($cfg['whitelist_submit_mode'] ?? 'public') === 'users' && usersEnabled($cfg)) ? 'users' : 'public';
$submitUser = null;
if ($submitMode === 'users') {
    $submitUser = currentUser($db);
    if (!$submitUser) jsonResponse(['error' => 'login_required'], 401);
    if (!userCan($db, $cfg, 'whitelist.add')) jsonResponse(['error' => 'no_permission'], 403);
} else {
    if (!captchaConfigured($cfg)) {
        // Fail closed: anonymous registration without a CAPTCHA would be a free spam sink.
        jsonResponse(['error' => 'registration_unavailable'], 503);
    }
    // CAPTCHA is ALWAYS required for anonymous registration (no points/grace system here).
    $token = captchaTokenFromInput($input);
    if ($token === '' || !verifyCaptcha($token, $cfg)) {
        jsonResponse(['error' => 'CAPTCHA verification failed', 'captcha_required' => true], 400);
    }
    onCaptchaSolved();
}

$ip = getClientIp($cfg);
$bucket = ipBucket($ip);

// Submissions per hour (file-based sliding window; best-effort) — per IP bucket, and per ACCOUNT
// in users mode so a member can't dodge the limit by hopping addresses.
$perHour = (int)($cfg['rate_limit_whitelist'] ?? 10);
if (!rateLimitAllow('whitelist', $bucket, $perHour, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}
if ($submitUser !== null && !rateLimitAllow('whitelist', 'u' . (int)$submitUser['id'], $perHour, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

$max = max(1, min(500, (int)($cfg['whitelist_max_per_submission'] ?? 20)));
$raw = (string)($input['input'] ?? '');
if (strlen($raw) > $max * 2100) {
    jsonResponse(['error' => 'too_many', 'max' => $max], 400);
}
$parsed = parseHashInput($raw, $max);
if ($parsed['too_many']) {
    jsonResponse(['error' => 'too_many', 'max' => $max], 400);
}
$items = $parsed['items'];

// Optional gate: only magnets that announce to THIS tracker (a hash whose torrent never points at
// us would occupy the whitelist for nothing). Bare hashes cannot prove it, so they are refused too.
if (($cfg['whitelist_require_tracker'] ?? '0') === '1') {
    $ourHosts = whitelistTrackerHosts($cfg);
    $need = 'Magnet link must include our tracker (' . implode(' or ', array_filter([(string)($cfg['announce_url'] ?? ''), (string)($cfg['announce_url_https'] ?? '')])) . ')';
    foreach ($items as &$it) {
        if (empty($it['hash'])) continue;
        if (empty($it['magnet']) || !magnetHasTrackerHost((string)$it['magnet'], $ourHosts)) {
            $it['hash'] = null;
            $it['error'] = empty($it['magnet']) ? 'Bare hashes are not accepted — paste the full magnet link containing our tracker' : $need;
        }
    }
    unset($it);
}
$validCount = count(array_filter($items, fn($it) => !empty($it['hash'])));
if ($validCount === 0) {
    jsonResponse(['error' => 'no_valid', 'results' => array_map(fn($it) => ['input' => $it['input'], 'hash' => null, 'status' => 'invalid', 'error' => $it['error']], $items)], 400);
}

// Daily caps (DB-backed, indexed on ip_bucket/created_at). Only NEW rows count.
$ipDaily = (int)($cfg['whitelist_ip_daily_max'] ?? 50);
if ($ipDaily > 0) {
    $st = $db->prepare("SELECT COUNT(*) FROM whitelist WHERE ip_bucket = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $st->execute([$bucket]);
    if ((int)$st->fetchColumn() + $validCount > $ipDaily) {
        jsonResponse(['error' => 'daily_cap', 'retry_after' => 3600, 'scope' => 'ip'], 429);
    }
}
$globalDaily = (int)($cfg['whitelist_daily_cap'] ?? 2000);
if ($globalDaily > 0) {
    $n = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
    if ($n + $validCount > $globalDaily) {
        jsonResponse(['error' => 'daily_cap', 'retry_after' => 3600, 'scope' => 'global'], 429);
    }
}

// ── the optional source link and description ────────────────────────────────
//
// Both are validated BEFORE anything is written. A submission that would be rejected for its
// description must not leave the hash registered and the description lost: the person would have no
// idea which half worked. So either the whole thing is acceptable, or nothing happens.
//
// They also only apply to a SINGLE torrent. One description cannot describe twelve of them, and
// attaching it to all twelve would publish a claim about eleven torrents nobody made.
$srcOn    = ($cfg['wl_allow_source_url'] ?? '0') === '1';
$descOn   = ($cfg['wl_allow_description'] ?? '0') === '1';
$sourceUrl = $srcOn ? trim((string)($input['source_url'] ?? '')) : '';
$descText  = $descOn ? trim((string)($input['description'] ?? '')) : '';
$descFmt   = (string)($input['description_format'] ?? 'bbcode');

if (($sourceUrl !== '' || $descText !== '') && $validCount > 1) {
    jsonResponse(['error' => 'A source link or description can only be added when you register one '
                           . 'torrent at a time — it would otherwise be attached to all of them.'], 400);
}
if ($sourceUrl !== '') {
    $e = richtextValidateSourceUrl($sourceUrl, $cfg);
    if ($e !== null) jsonResponse(['error' => $e], 400);
}
if ($descText !== '') {
    if (!in_array($descFmt, richtextFormats($cfg), true)) $descFmt = richtextFormats($cfg)[0];
    $e = richtextValidate($descText, $descFmt, $cfg);
    if ($e !== null) jsonResponse(['error' => $e], 400);
}

$addCtx = ['source' => 'web', 'ip' => $ip, 'auto_meta' => false];
if ($submitUser !== null) $addCtx['ref'] = ['user' => $submitUser['username'], 'id' => (int)$submitUser['id']];
$r = whitelistAddHashes($db, $cfg, $items, $addCtx);

// Attach them to the row that was just created. `content_status` decides whether anybody but an
// administrator ever sees them: with review on they wait, and the torrent works regardless.
$contentSaved = false;
if ($sourceUrl !== '' || $descText !== '') {
    foreach ($r['results'] as $res) {
        if (empty($res['hash']) || !in_array($res['status'], ['added', 'exists'], true)) continue;
        $status = ($cfg['wl_content_review'] ?? '1') === '1' ? 'pending' : 'approved';
        $db->prepare("UPDATE whitelist SET source_url = ?, description = ?, description_format = ?,
                             content_status = ?, content_reviewed_at = NULL, content_rejected_note = NULL
                       WHERE info_hash = ?")
           ->execute([$sourceUrl !== '' ? $sourceUrl : null,
                      $descText !== '' ? $descText : null,
                      $descFmt, $status, $res['hash']]);
        $contentSaved = true;
        break;
    }
}

$magnets = [];
foreach ($r['results'] as $res) {
    if (!empty($res['hash']) && in_array($res['status'], ['added', 'exists'], true)) {
        $name = null;
        foreach ($items as $it) { if (($it['hash'] ?? null) === $res['hash']) { $name = $it['name'] ?? null; break; } }
        $magnets[$res['hash']] = buildMagnet($res['hash'], $name, $cfg);
    }
}

jsonResponse([
    'success' => true,
    'results' => array_map(fn($x) => ['input' => mb_substr((string)$x['input'], 0, 200), 'hash' => $x['hash'], 'status' => $x['status'], 'error' => $x['error']], $r['results']),
    'summary' => $r['summary'],
    // `all` carries the extra instances' ports too; udp/http stay exactly what they were, so nothing
    // already reading this response has to change.
    'announce' => ['udp' => (string)($cfg['announce_url'] ?? ''), 'http' => (string)($cfg['announce_url_https'] ?? ''),
                   'all' => announceUrls($cfg)],
    'content_saved' => $contentSaved,
    'content_pending' => $contentSaved && ($cfg['wl_content_review'] ?? '1') === '1',
    'magnets' => $magnets,
    'active_in_seconds' => (int)$r['active_in_seconds'],
    'file_ok' => (bool)($r['file']['ok'] ?? true),
    'captcha_solved' => true,
]);
