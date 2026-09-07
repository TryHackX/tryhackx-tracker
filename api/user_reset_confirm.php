<?php
/**
 * POST user_reset_confirm — set a new password with a valid reset token.
 * Body: {token, password, csrf_token}
 */
requirePost();

$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$ip = getClientIp($cfg);
if (!rateLimitAllow('user_reset', ipBucket($ip), 10, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}
$password = (string)($input['password'] ?? '');
if (!userValidPassword($password)) {
    jsonResponse(['error' => __('api.users.weak_password', ['rules' => USER_PASSWORD_RULES])], 400);
}
$userId = userResetConsume($db, (string)($input['token'] ?? ''), true);
if ($userId === null) {
    jsonResponse(['error' => __('api.users.reset_link_invalid')], 400);
}
$db->prepare("UPDATE users SET pass_hash = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
$db->prepare("DELETE FROM user_tokens WHERE type = 'remember' AND user_id = ?")->execute([$userId]);
userNotify($db, $userId, 'account', 'Your password was reset', 'If this was not you, contact the site admin.');
jsonResponse(['success' => true]);
