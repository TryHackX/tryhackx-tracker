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
    jsonResponse(['error' => __('api.twofa.unknown_op')], 400);
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
    requireAdminReauth($password, $cfg);
}

if ($op === 'begin') {
    if (twofaEnabled()) {
        jsonResponse(['error' => __('api.twofa.already_on')], 409);
    }
    if (!is_writable(dirname(twofaFile()))) {
        jsonResponse(['error' => __('api.twofa.config_not_writable')], 500);
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
        'note' => __('api.twofa.begin_note'),
        'qr' => $qr,
        'qr_note' => $qr === null
            ? __('api.twofa.qr_unavailable')
            : __('api.twofa.qr_local'),
    ]);
}

if ($op === 'confirm') {
    $r = twofaConfirmSetup((string)($input['code'] ?? ''));
    if (!empty($r['error'])) jsonResponse(['error' => $r['error']], 400);
    twofaSyncSetting($db, $cfg);
    jsonResponse(['success' => true, 'recovery_left' => twofaRecoveryLeft(),
                  'message' => __('api.twofa.confirmed')]);
}

if (!twofaEnabled()) jsonResponse(['error' => __('api.twofa.not_on')], 409);

if (!$secondFactorOk((string)($input['code'] ?? ''))) {
    jsonResponse(['error' => __('api.twofa.code_required')], 403);
}

if ($op === 'disable') {
    if (!twofaDisable()) jsonResponse(['error' => __('api.twofa.disable_failed')], 500);
    twofaSyncSetting($db, $cfg);
    jsonResponse(['success' => true, 'message' => __('api.twofa.disabled')]);
}

$r = twofaRegenerateRecovery();
if (!empty($r['error'])) jsonResponse(['error' => $r['error']], 500);
jsonResponse(['success' => true, 'recovery' => $r['recovery'],
              'message' => __('api.twofa.regenerated')]);
