<?php
/**
 * Where this account is signed in, and how to end all of it but here.
 *
 *   GET  user_sessions                                → {sessions:[{current,ip,ua,created,expires}], count}
 *   POST user_sessions {op:'others', current_password} → {ended:N}
 *
 * ── what "N devices" honestly means ────────────────────────────────────────────────────────────
 * A PHP session is a file with no account in its name, so the site cannot enumerate them. What it
 * can enumerate is remember-me tokens: one per browser that asked to be remembered, rotated on every
 * return. That is what this lists, and the page says so rather than implying the number is every
 * open tab in the world.
 *
 * Ending them is not limited that way. `sessions_valid_from` on the account is what makes an
 * already-open session stop counting on its next request, so "sign out everywhere else" reaches the
 * sessions this endpoint cannot show — including one opened without a remember cookie.
 *
 * The password is asked for because this is the button somebody presses when they think another
 * person is in their account, and the other person may be the one sitting at the screen.
 */
$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);
$uid = (int)$me['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Cache-Control: private, no-store');
    $rows = userSessionList($db, $uid);
    jsonResponse([
        'success'  => true,
        'sessions' => $rows,
        'count'    => count($rows),
        // The account page shows this beside the list: a remembered browser is a row, a plain
        // sign-in is not, and the reader deserves to know which they are looking at.
        'this_one_remembered' => (bool)array_filter($rows, static fn($r) => !empty($r['current'])),
        'since'    => (int)($me['sessions_valid_from'] ?? 0),
    ]);
}

$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!rateLimitAllow('usersessions', ipBucket(getClientIp($cfg)), 20, 900)) {
    jsonResponse(['error' => __('api.login.too_many')], 429);
}
if ((string)($input['op'] ?? '') !== 'others') jsonResponse(['error' => __('api.index.unknown_op')], 400);
if (!password_verify((string)($input['current_password'] ?? ''), (string)$me['pass_hash'])) {
    jsonResponse(['error' => __('api.account.current_password_wrong')], 403);
}

$ended = userSignOutOthers($db, $uid, true);
// Worth a notification of its own: if this was NOT the account's owner, the owner reads it later and
// knows exactly when somebody swept the sessions.
userNotify($db, $uid, 'account', __('notify.sessions_ended'), __('notify.sessions_ended_body', ['n' => $ended]));
auditLog($db, 'user.sessions_cleared', ['target_type' => 'user', 'target_id' => $uid,
                                        'summary' => 'signed out ' . $ended . ' remembered device(s)']);
jsonResponse(['success' => true, 'ended' => $ended]);
