<?php
/**
 * The member account's second factor.
 *
 *   GET  user_2fa                                   → {enabled, required, why, recovery_left, feature}
 *   POST user_2fa {op:'begin',   current_password}  → {secret, uri, expires_in}
 *   POST user_2fa {op:'confirm', code}              → {recovery:[…]}  — shown ONCE
 *   POST user_2fa {op:'disable', current_password}
 *   POST user_2fa {op:'recovery', current_password} → {recovery:[…]}  — a fresh set
 *
 * ── why the password is asked for again ────────────────────────────────────────────────────────
 * Turning the second factor ON or OFF is the security of the account itself, and the person doing it
 * may be somebody who sat down at an unlocked screen. `begin` and `disable` therefore cost the
 * password; `confirm` does not, because it is the second half of a `begin` that already did and it
 * carries a code from the phone anyway.
 *
 * Turning it off does NOT ask for a code. Somebody who has lost the phone and has a recovery code
 * left can sign in with it and then disable — asking for the very thing they no longer have would
 * make the recovery codes useless at exactly the moment they exist for.
 */
$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);
if (!user2faFeatureEnabled($cfg)) jsonResponse(['error' => 'twofa_disabled'], 404);

$uid = (int)$me['id'];
$need = user2faRequiredFor($db, $cfg, $me);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Cache-Control: private, no-store');
    jsonResponse([
        'success'       => true,
        'feature'       => true,
        'enabled'       => user2faEnabled($db, $uid),
        'required'      => (bool)$need['required'],
        'why'           => $need['why'],
        'recovery_left' => user2faRecoveryLeft($db, $uid),
    ]);
}

$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
// The same budget as any other password-checking endpoint: this one verifies a password and a code,
// so it is a guessing surface like the login form.
if (!rateLimitAllow('user2fa', ipBucket(getClientIp($cfg)), 20, 900)) {
    jsonResponse(['error' => __('api.login.too_many')], 429);
}
$op = (string)($input['op'] ?? '');
$password = (string)($input['current_password'] ?? '');
$needsPassword = in_array($op, ['begin', 'disable', 'recovery'], true);
if ($needsPassword && !password_verify($password, (string)$me['pass_hash'])) {
    jsonResponse(['error' => __('api.account.current_password_wrong')], 403);
}

switch ($op) {
    case 'begin':
        $setup = user2faBeginSetup($db, $cfg, $me);
        // The QR is drawn HERE, by this project's own encoder, from the URI this server just built —
        // the same reason the panel's setup does it that way. A hosted QR service or a CDN library
        // would be handed a secret that is as good as the password. If the drawing fails the setup
        // still works: the key underneath is the real payload and the QR is a shortcut.
        $qr = null;
        try {
            require_once dirname(__DIR__) . '/includes/qr.php';
            $qr = qrSvg(qrMatrix((string)$setup['uri']));
        } catch (\Throwable $e) {
            error_log('2FA QR could not be drawn: ' . $e->getMessage());
        }
        // The secret leaves the server exactly once, to the person who just proved they own the
        // account, over the same channel their password came in on.
        jsonResponse(['success' => true] + $setup + ['qr' => $qr]);
        // no break — jsonResponse exits

    case 'confirm':
        $r = user2faConfirmSetup($db, $uid, (string)($input['code'] ?? ''));
        if (!$r['ok']) jsonResponse(['error' => $r['error']], $r['error'] === 'bad_code' ? 400 : 409);
        userNotify($db, $uid, 'account', __('notify.twofa_on'), __('notify.twofa_on_body'));
        // The panel may have been shut waiting for this — see userMaybeOpenPanelSession(). Opening
        // it here rather than making them sign in again is the difference between a setting that
        // works and one that appears not to.
        userMaybeOpenPanelSession($db, $me);
        jsonResponse(['success' => true, 'recovery' => $r['recovery']]);

    case 'disable':
        if (!user2faEnabled($db, $uid)) jsonResponse(['error' => 'not_on'], 409);
        user2faDisable($db, $uid);
        userNotify($db, $uid, 'account', __('notify.twofa_off'), __('notify.twofa_off_body'));
        // If this account needed one to open the panel, it does not have a panel session any more.
        if ($need['required'] && !empty($_SESSION['admin_via_user'])) {
            unset($_SESSION['admin_via_user'], $_SESSION['loggedin'], $_SESSION['login_time'], $_SESSION['last_activity']);
            $_SESSION['panel_needs_2fa'] = true;
        }
        jsonResponse(['success' => true, 'enabled' => false]);

    case 'recovery':
        if (!user2faEnabled($db, $uid)) jsonResponse(['error' => 'not_on'], 409);
        jsonResponse(['success' => true, 'recovery' => user2faRegenerateRecovery($db, $uid)]);

    default:
        jsonResponse(['error' => __('api.index.unknown_op')], 400);
}
