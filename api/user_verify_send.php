<?php
/**
 * POST user_verify_send — (re)send the email-verification link for the signed-in user.
 * Body: {csrf_token}. Rate limited (3/hour/IP) — each send invalidates the previous token.
 */
requirePost();

$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);
if (trim((string)$u['email']) === '') jsonResponse(['error' => __('api.users.no_email_address')], 400);
if ((int)$u['email_verified'] === 1) jsonResponse(['error' => __('api.users.already_verified')], 400);
if (!rateLimitAllow('user_verify', ipBucket(getClientIp($cfg)), 3, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}

$sent = userVerifySend($db, $cfg, $u);
jsonResponse(['success' => true, 'sent' => $sent,
    'message' => $sent ? __('api.users.verify_link_sent') : __('api.users.verify_mail_failed')]);
