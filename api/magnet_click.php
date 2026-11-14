<?php
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!userCan($db, $cfg, 'index.magnet')) {
    jsonResponse(['error' => __('api.index.search_access_required')], 403);
}
$hash = strtolower(trim((string)($input['h'] ?? '')));
if (!preg_match('/^[0-9a-f]{40}$/', $hash)) jsonResponse(['error' => __('api.common.invalid_hash')], 400);
$ip = getClientIp($cfg);
if (!rateLimitAllow('magnet_click', ipBucket($ip), 600, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}
$me = usersEnabled($cfg) ? currentUser($db) : null;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$who = hash_hmac('sha256', $me !== null ? 'u' . (int)$me['id'] : 'ip' . ipBucket($ip), (string)($cfg['hmac_secret'] ?? 'magnet'));
$ins = $db->prepare("INSERT IGNORE INTO magnet_click_log (info_hash, who, day) VALUES (?, ?, UTC_DATE())");
$ins->execute([$hash, $who]);
if ($ins->rowCount() === 1) {
    $db->prepare("INSERT INTO magnet_clicks (info_hash, clicks, last_at) VALUES (?, 1, UTC_TIMESTAMP())
                  ON DUPLICATE KEY UPDATE clicks = clicks + 1, last_at = UTC_TIMESTAMP()")->execute([$hash]);
}
$st = $db->prepare("SELECT clicks FROM magnet_clicks WHERE info_hash = ?");
$st->execute([$hash]);
jsonResponse(['success' => true, 'clicks' => (int)$st->fetchColumn()]);
