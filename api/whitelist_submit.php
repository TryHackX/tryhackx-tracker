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

// With the probe on, a submission is a job somebody watches, not a paste-and-forget. The batch is
// capped to what the metadata worker can actually work on at once: five hundred rows in the priority
// lane are not resolved any faster, they just make everyone in the queue wait together.
if (wlProbeEnabled($cfg) && $validCount > wlProbeMaxPerSubmit($cfg)) {
    jsonResponse(['error' => 'This tracker checks each submission before serving it, so they go '
                           . 'through a few at a time. Send at most ' . wlProbeMaxPerSubmit($cfg)
                           . ' per submission.',
                  'max' => wlProbeMaxPerSubmit($cfg)], 400);
}

if (($sourceUrl !== '' || $descText !== '') && $validCount > 1) {
    jsonResponse(['error' => 'A source link or description can only be added when you register one '
                           . 'torrent at a time — it would otherwise be attached to all of them.'], 400);
}
// Attaching words to a torrent is its own permission: an operator may want registration open and
// descriptions restricted, and before this there was no way to say so.
if (($sourceUrl !== '' || $descText !== '') && !userCan($db, $cfg, 'content.submit')) {
    jsonResponse(['error' => 'Adding a source link or description needs an account with content '
                           . 'access on this tracker. The torrent itself can still be registered.'], 403);
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

// Attach them to the row — or, if the row already has words on it, PROPOSE replacing them.
//
// Anyone can register anyone's hash. That means the first person to describe a torrent is not
// automatically the right one, and it also means "whoever submits last wins" would be an invitation.
// So: an empty row is filled directly, and an occupied one gets a proposal a moderator decides on.
// Nothing a second submitter writes changes what the public sees until somebody says so.
$contentSaved = false;
$contentPending = false;
$contentProposed = false;
if ($sourceUrl !== '' || $descText !== '') {
    foreach ($r['results'] as $res) {
        if (empty($res['hash']) || !in_array($res['status'], ['added', 'exists'], true)) continue;

        $cur = $db->prepare("SELECT id, source_url, description, content_status FROM whitelist WHERE info_hash = ? LIMIT 1");
        $cur->execute([$res['hash']]);
        $row = $cur->fetch();
        if (!$row) break;

        $occupied = ($row['description'] !== null && $row['description'] !== '')
                 || ($row['source_url'] !== null && $row['source_url'] !== '');

        if ($occupied) {
            if (!userCan($db, $cfg, 'content.propose')) {
                jsonResponse(['error' => 'This torrent already has a description. Proposing a change '
                                       . 'to somebody else\'s needs an account with that access.'], 403);
            }
            $maxPending = max(0, min(50, (int)($cfg['wl_edit_max_pending'] ?? 3)));
            if ($maxPending === 0) {
                jsonResponse(['error' => 'This torrent already has a description, and this tracker does '
                                       . 'not accept proposals to change one.'], 409);
            }
            $st = $db->prepare("SELECT COUNT(*) FROM wl_content_edits WHERE whitelist_id = ? AND status = 'pending'");
            $st->execute([(int)$row['id']]);
            if ((int)$st->fetchColumn() >= $maxPending) {
                jsonResponse(['error' => 'There are already ' . $maxPending . ' proposals waiting for this '
                                       . 'torrent. A moderator has to work through those first.'], 429);
            }
            $db->prepare("INSERT INTO wl_content_edits (whitelist_id, info_hash, source_url, description,
                                 description_format, ip, user_id)
                          VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([(int)$row['id'], $res['hash'], $sourceUrl !== '' ? $sourceUrl : null,
                          $descText !== '' ? $descText : null, $descFmt, $ip,
                          $submitUser !== null ? (int)$submitUser['id'] : null]);
            $contentProposed = true;
            break;
        }

        // An empty row. Published at once when the operator has said so, otherwise it waits — and
        // either way the torrent is already registered and serving.
        $auto = ($cfg['wl_content_autopublish'] ?? '0') === '1';
        $status = ($auto || ($cfg['wl_content_review'] ?? '1') !== '1') ? 'approved' : 'pending';
        $db->prepare("UPDATE whitelist SET source_url = ?, description = ?, description_format = ?,
                             content_status = ?, content_reviewed_at = NULL, content_rejected_note = NULL
                       WHERE info_hash = ?")
           ->execute([$sourceUrl !== '' ? $sourceUrl : null,
                      $descText !== '' ? $descText : null,
                      $descFmt, $status, $res['hash']]);
        $contentSaved = true;
        $contentPending = ($status === 'pending');
        break;
    }
}

// Everything that was just added has to prove itself: metadata in, and at least one peer announcing
// to this tracker. Until then the accesslist generator skips it, so nothing is served on the
// strength of somebody having typed it.
$probing = [];
if (wlProbeEnabled($cfg)) {
    foreach ($r['results'] as $res) {
        if (!empty($res['hash']) && $res['status'] === 'added') $probing[] = $res['hash'];
    }
    if ($probing) wlProbeStart($db, $cfg, $probing);
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
    'content_pending' => $contentPending,
    'content_proposed' => $contentProposed,
    'probe' => wlProbeEnabled($cfg)
        ? ['on' => true, 'hashes' => $probing, 'timeout_minutes' => wlProbeTimeoutMinutes($cfg),
           'on_fail' => wlProbeOnFail($cfg)]
        : ['on' => false],
    'magnets' => $magnets,
    'active_in_seconds' => (int)$r['active_in_seconds'],
    'file_ok' => (bool)($r['file']['ok'] ?? true),
    'captcha_solved' => true,
]);
