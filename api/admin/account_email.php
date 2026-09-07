<?php
/**
 * Admin: change (or remove) the email address of the panel admin's own account.
 *
 * The panel identity is mirrored into `users` (schema v8), so this is the SAME address, and the same
 * two-step confirmation, that a normal member gets on their account page: confirm from the current
 * mailbox first, then from the new one (includes/users.php → userEmailChangeStart/Consume). Nothing
 * is written to `users.email` until both links are opened; an account that has no address yet takes
 * the direct path and receives the usual verification mail.
 *
 * Body: {current_password, email}  — email '' removes the address
 *       {cancel: 1}                — drop a pending change (no password needed, nothing sensitive)
 *
 * The gate here is the PANEL password (config/hash.txt), which is what the admin just typed in the
 * same form; the account page gates on the account password instead. Either way the address only
 * moves after the mailboxes confirm it.
 */
requirePost();

$input = readJsonBody();
if (!$input || !is_array($input)) jsonResponse(['error' => __('api.account.invalid_input')], 400);

$u = userFindByLogin($db, (string)($cfg['admin_username'] ?? 'admin'));
if (!$u) {
    jsonResponse(['error' => __('api.account.no_linked_account')], 400);
}

// Cancelling only undoes a pending change — same rule as the account page.
if (!empty($input['cancel'])) {
    userEmailChangeCancel($db, (int)$u['id']);
    jsonResponse(['success' => true, 'message' => __('api.account.email_change_cancelled')]);
}

$currentPassword = (string)($input['current_password'] ?? '');
if ($currentPassword === '') jsonResponse(['error' => __('api.account.current_password_required')], 400);
requireAdminReauth($currentPassword, $cfg);

$r = userEmailChangeStart($db, $cfg, $u, (string)($input['email'] ?? ''));
if (isset($r['error'])) {
    $msg = [
        'invalid_email' => __('api.account.email_invalid'),
        'same_email'    => __('api.account.email_same'),
        'email_taken'   => __('api.account.email_taken'),
        'cooldown'      => __('api.account.email_cooldown', ['until' => ($r['until'] ?? '')]),
    ][$r['error']] ?? __('api.account.email_change_failed');
    jsonResponse(['error' => $msg], 400);
}

// No old address to confirm from: the change already landed, send the verification link.
if (($r['stage'] ?? '') === 'done_direct') {
    $fresh = userFindById($db, (int)$u['id']);
    $sent = ($fresh && trim((string)$fresh['email']) !== '') ? userVerifySend($db, $cfg, $fresh) : false;
    userNotify($db, (int)$u['id'], 'account', 'Your email was set', 'Set from the admin panel.');
    jsonResponse(['success' => true, 'stage' => 'done_direct', 'verify_sent' => $sent,
        'message' => $sent ? __('api.account.email_saved_sent') : __('api.account.email_saved_not_sent')]);
}

jsonResponse(['success' => true, 'stage' => 'old',
    'message' => __('api.account.email_confirm_old')]);
