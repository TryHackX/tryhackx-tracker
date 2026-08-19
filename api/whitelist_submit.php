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
if (!captchaConfigured($cfg)) {
    // Fail closed: registration without a CAPTCHA would be a free spam sink.
    jsonResponse(['error' => 'registration_unavailable'], 503);
}

// CAPTCHA is ALWAYS required for registration (no points/grace system here).
$token = captchaTokenFromInput($input);
if ($token === '' || !verifyCaptcha($token, $cfg)) {
    jsonResponse(['error' => 'CAPTCHA verification failed', 'captcha_required' => true], 400);
}
onCaptchaSolved();

$ip = getClientIp($cfg);
$bucket = ipBucket($ip);

// Submissions per hour per IP bucket (file-based sliding window; best-effort).
$perHour = (int)($cfg['rate_limit_whitelist'] ?? 10);
if (!rateLimitAllow('whitelist', $bucket, $perHour, 3600)) {
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

$r = whitelistAddHashes($db, $cfg, $items, ['source' => 'web', 'ip' => $ip, 'auto_meta' => false]);

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
    'announce' => ['udp' => (string)($cfg['announce_url'] ?? ''), 'http' => (string)($cfg['announce_url_https'] ?? '')],
    'magnets' => $magnets,
    'active_in_seconds' => (int)$r['active_in_seconds'],
    'file_ok' => (bool)($r['file']['ok'] ?? true),
    'captcha_solved' => true,
]);
