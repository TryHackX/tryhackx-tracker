<?php
/**
 * POST admin/twofa — turning two-factor authentication on, off, and re-issuing recovery codes.
 *
 *   {"op":"status"}                                    — is it on, how many codes are left
 *   {"op":"begin","password":"…"}                      — a pending secret + ten codes, shown once
 *   {"op":"confirm","code":"123456"}                   — proves the secret arrived intact, activates
 *   {"op":"cancel"}                                    — throw the pending setup away
 *   {"op":"disable","password":"…","code":"…"}         — password AND a code
 *   {"op":"regen","password":"…","code":"…"}           — new recovery codes, shown once
 *
 * ── why disabling needs both ────────────────────────────────────────────────
 *
 * Two-factor authentication exists precisely for the case where someone else has the password. If the
 * password alone could switch it off, it would protect nothing against that person: they would sign
 * in, turn it off, and carry on. So disabling asks for the password AND a current code — or a
 * recovery code, which is the same second factor by another route.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
if (!in_array($op, ['status', 'begin', 'confirm', 'cancel', 'disable', 'regen'], true)) {
    jsonResponse(['error' => 'Unknown operation'], 400);
}

twofaSyncSetting($db, $cfg);
$state = twofaState();

if ($op === 'status') {
    jsonResponse([
        'success' => true,
        'enabled' => twofaEnabled(),
        'recovery_left' => twofaRecoveryLeft(),
        'confirmed_at' => (int)($state['confirmed_at'] ?? 0),
        'pending' => !empty($state['pending']),
        'writable' => is_writable(dirname(twofaFile())),
    ]);
}

if ($op === 'cancel') {
    $s = twofaState();
    $s['pending'] = null;
    twofaStateWrite($s);
    jsonResponse(['success' => true]);
}

/** The second factor, by either route. Used for disable and regen. */
$secondFactorOk = function (string $code): bool {
    $code = trim($code);
    if ($code === '') return false;
    if (preg_match('/^\s*\d(\s*\d){5}\s*$/', $code)) return twofaCheck($code);
    return twofaUseRecovery($code) !== null;
};

$password = (string)($input['password'] ?? '');
$needPassword = in_array($op, ['begin', 'disable', 'regen'], true);
if ($needPassword) {
    if ($password === '' || ADMIN_PASSWORD_HASH === '' || !password_verify($password, ADMIN_PASSWORD_HASH)) {
        jsonResponse(['error' => 'Wrong admin password'], 403);
    }
}

if ($op === 'begin') {
    if (twofaEnabled()) {
        jsonResponse(['error' => 'Two-factor authentication is already on. Turn it off first if you want a new secret — '
                              . 'that way the old one stops working the moment the new one starts.'], 409);
    }
    if (!is_writable(dirname(twofaFile()))) {
        jsonResponse(['error' => 'config/ is not writable, so the secret could not be stored. Fix that before '
                              . 'starting: a setup that cannot be saved would leave you with an app generating '
                              . 'codes for a secret this server has forgotten.'], 500);
    }
    $r = twofaBeginSetup($cfg);
    if (!empty($r['error'])) jsonResponse(['error' => $r['error']], 500);
    // The QR is drawn HERE, by this project's own encoder, from the URI this server just built. That is
    // the whole reason the encoder exists: every hosted QR service and every CDN-loaded QR library
    // would be handed a secret that is as good as the password. Loaded only on this one path — it is
    // several hundred lines that no other request has any use for.
    //
    // If the drawing fails the setup still works. The key underneath is the real payload; the QR is a
    // shortcut, and a shortcut that breaks must not take the whole page with it.
    $qr = null;
    try {
        require_once dirname(__DIR__, 2) . '/includes/qr.php';
        $qr = qrSvg(qrMatrix((string)($r['uri'] ?? '')));
    } catch (Throwable $e) {
        error_log('2FA QR could not be drawn: ' . $e->getMessage());
    }
    // Nothing has changed yet. The secret is pending until a code proves it arrived intact.
    jsonResponse(['success' => true] + $r + [
        'note' => 'Nothing is switched on yet. Add the key to your authenticator app, then enter a code from '
                . 'it below — that is what proves the key arrived intact, and only then does anything change.',
        'qr' => $qr,
        'qr_note' => $qr === null
            ? 'The QR code could not be drawn on this server, so add the key by hand — every authenticator '
            . 'app supports that, and the key below carries exactly what the QR would have.'
            : 'Drawn on this server and never sent anywhere: this secret is as good as your password, so it '
            . 'does not go to a QR service. Cannot scan it? The key below is the same thing, by hand.',
    ]);
}

if ($op === 'confirm') {
    $r = twofaConfirmSetup((string)($input['code'] ?? ''));
    if (!empty($r['error'])) jsonResponse(['error' => $r['error']], 400);
    twofaSyncSetting($db, $cfg);
    jsonResponse(['success' => true, 'recovery_left' => twofaRecoveryLeft(),
                  'message' => 'Two-factor authentication is on. Your next sign-in will ask for a code.']);
}

if (!twofaEnabled()) jsonResponse(['error' => 'Two-factor authentication is not on.'], 409);

if (!$secondFactorOk((string)($input['code'] ?? ''))) {
    jsonResponse(['error' => 'A current code (or a recovery code) is required as well as the password. '
                          . 'Two-factor authentication is for the case where somebody else has the password, so '
                          . 'the password alone cannot switch it off.'], 403);
}

if ($op === 'disable') {
    if (!twofaDisable()) jsonResponse(['error' => 'Could not clear the stored secret.'], 500);
    twofaSyncSetting($db, $cfg);
    jsonResponse(['success' => true, 'message' => 'Two-factor authentication is off. The secret and every recovery code are gone.']);
}

$r = twofaRegenerateRecovery();
if (!empty($r['error'])) jsonResponse(['error' => $r['error']], 500);
jsonResponse(['success' => true, 'recovery' => $r['recovery'],
              'message' => 'Ten new recovery codes. Every previous code stopped working just now — save these.']);
