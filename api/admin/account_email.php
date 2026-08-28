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
if (!$input || !is_array($input)) jsonResponse(['error' => 'Invalid input'], 400);

$u = userFindByLogin($db, (string)($cfg['admin_username'] ?? 'admin'));
if (!$u) {
    jsonResponse(['error' => 'This panel login has no linked account, so it has no email address. '
        . 'Create a user with the same username on the Users page (or rename it back) to manage one.'], 400);
}

// Cancelling only undoes a pending change — same rule as the account page.
if (!empty($input['cancel'])) {
    userEmailChangeCancel($db, (int)$u['id']);
    jsonResponse(['success' => true, 'message' => 'Pending email change cancelled.']);
}

$currentPassword = (string)($input['current_password'] ?? '');
if ($currentPassword === '') jsonResponse(['error' => 'Current password is required'], 400);
requireAdminReauth($currentPassword, $cfg);

$r = userEmailChangeStart($db, $cfg, $u, (string)($input['email'] ?? ''));
if (isset($r['error'])) {
    $msg = [
        'invalid_email' => 'That email address does not look valid.',
        'same_email'    => 'That is already the address on this account.',
        'email_taken'   => 'Another account already uses this email address.',
        'cooldown'      => 'The address was changed recently — the next change is possible after ' . ($r['until'] ?? '') . '.',
    ][$r['error']] ?? 'Email change failed.';
    jsonResponse(['error' => $msg], 400);
}

// No old address to confirm from: the change already landed, send the verification link.
if (($r['stage'] ?? '') === 'done_direct') {
    $fresh = userFindById($db, (int)$u['id']);
    $sent = ($fresh && trim((string)$fresh['email']) !== '') ? userVerifySend($db, $cfg, $fresh) : false;
    userNotify($db, (int)$u['id'], 'account', 'Your email was set', 'Set from the admin panel.');
    jsonResponse(['success' => true, 'stage' => 'done_direct', 'verify_sent' => $sent,
        'message' => $sent ? 'Address saved — open the verification link we just sent.' : 'Address saved (the verification mail could not be sent).']);
}

jsonResponse(['success' => true, 'stage' => 'old',
    'message' => 'Confirmation sent to the CURRENT address — open that link, then confirm from the new mailbox.']);
