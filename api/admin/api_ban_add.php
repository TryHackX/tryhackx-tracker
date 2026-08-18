<?php
// Manual API ban from the panel.
requirePost();

$input = readJsonBody();
$ip = trim((string)($input['ip'] ?? ''));
if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    jsonResponse(['error' => 'Invalid IP address'], 400);
}
if (apiIpExempt($ip, $cfg)) {
    jsonResponse(['error' => 'This IP is on the exempt list (server / trusted address) and cannot be banned.'], 400);
}
$days = (int)($input['days'] ?? 0);
if ($days < 1) $days = max(1, (int)($cfg['api_ban_days'] ?? 30));
$days = min(3650, $days);
$reason = mb_substr(trim((string)($input['reason'] ?? '')), 0, 255);
$st = $db->prepare("INSERT INTO api_bans (ip, ip_bucket, reason, detail, key_id, endpoint, request_snapshot, expires_at)
                    VALUES (?, ?, 'manual', ?, NULL, 'admin', NULL, DATE_ADD(NOW(), INTERVAL ? DAY))");
$st->execute([$ip, ipBucket($ip), $reason !== '' ? $reason : 'Manual ban from admin panel', $days]);
jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId(), 'ip_bucket' => ipBucket($ip), 'days' => $days]);
