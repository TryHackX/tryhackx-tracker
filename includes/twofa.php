<?php
/**
 * Two-factor authentication for the admin panel (schema v18) — TOTP, RFC 6238.
 *
 * ── where the secret lives, and why not in `settings` ───────────────────────
 *
 * A TOTP secret is a credential: anyone holding it can produce valid codes for ever. The settings
 * table is dumped by every backup, read by half the panel, and returned by more than one admin
 * endpoint. So this lives beside the password hash in config/, which the web server is denied by
 * config/.htaccess and which deploy/deploy.py never uploads or overwrites. One settings row mirrors
 * the on/off state so the settings search can find the section and cheap checks stay cheap; the FILE
 * is authoritative, and if the two ever disagree the file wins and the row is corrected.
 *
 * ── the three things that go wrong with 2FA, and what is done about them ────
 *
 * 1. LOCKING YOURSELF OUT WHILE TURNING IT ON. A secret that is stored the moment it is generated
 *    means a mis-scanned QR or a mistyped key locks the admin out of their own panel with no warning.
 *    So setup is two-step: the secret is PENDING until a code generated from it has been verified,
 *    and only then does it become real.
 * 2. LOSING THE PHONE. Ten single-use recovery codes, shown once, stored as hashes. Without them a
 *    lost phone is a lost panel, and the only way back would be editing the database by hand.
 * 3. A CODE BEING USED TWICE. A TOTP code stays valid for its whole 30-second step (and one step
 *    either side, for clock drift), so a code seen over someone's shoulder or in a log works again
 *    seconds later. The last accepted step is recorded and never accepted again.
 */

const TWOFA_DIGITS   = 6;
const TWOFA_PERIOD   = 30;
/** One step either side. Any wider and a code an attacker saw is valid for minutes, not seconds. */
const TWOFA_WINDOW   = 1;
const TWOFA_RECOVERY_COUNT = 10;
/** A pending setup that nobody finished must not sit there for ever waiting to be confirmed. */
const TWOFA_SETUP_TTL = 900;

function twofaFile(): string { return __DIR__ . '/../config/admin_2fa.json'; }

function twofaState(): array {
    $f = twofaFile();
    $d = [];
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        $d = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    return array_replace([
        'enabled' => false, 'secret' => '', 'recovery' => [], 'last_step' => 0,
        'confirmed_at' => 0, 'pending' => null,
    ], is_array($d) ? $d : []);
}

function twofaStateWrite(array $s): bool {
    $f = twofaFile();
    $tmp = $f . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return @rename($tmp, $f);
}

function twofaEnabled(): bool { return !empty(twofaState()['enabled']) && twofaState()['secret'] !== ''; }

/* ── base32, because that is what authenticator apps speak ────────────────── */

function twofaBase32Encode(string $bin): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $bits = '';
    for ($i = 0, $n = strlen($bin); $i < $n; $i++) $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function twofaBase32Decode(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $p = strpos($alphabet, $b32[$i]);
        if ($p === false) continue;
        $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) < 8) break;                 // a trailing partial byte is padding
        $out .= chr(bindec($byte));
    }
    return $out;
}

/* ── the algorithm itself ─────────────────────────────────────────────────── */

/** HOTP over a counter (RFC 4226); TOTP is this with counter = floor(time / period). */
function twofaHotp(string $secretBin, int $counter, int $digits = TWOFA_DIGITS): string {
    $bin = pack('J', $counter);                        // 64-bit big-endian
    $hash = hash_hmac('sha1', $bin, $secretBin, true);
    $offset = ord($hash[19]) & 0x0F;
    $part = ((ord($hash[$offset]) & 0x7F) << 24)
          | ((ord($hash[$offset + 1]) & 0xFF) << 16)
          | ((ord($hash[$offset + 2]) & 0xFF) << 8)
          | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($part % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function twofaCodeAt(string $secretB32, int $timestamp, int $digits = TWOFA_DIGITS, int $period = TWOFA_PERIOD): string {
    return twofaHotp(twofaBase32Decode($secretB32), intdiv($timestamp, $period), $digits);
}

/**
 * Verify a code and return the time step it matched, or null.
 *
 * The step is returned rather than a bare true so the caller can refuse to accept it twice: a code is
 * valid for a whole 30-second window plus one step either side, which is long enough for one read
 * over a shoulder, from a log, or from a phone left unlocked on a desk.
 */
function twofaVerifyCode(string $secretB32, string $code, ?int $now = null, int $window = TWOFA_WINDOW): ?int {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== TWOFA_DIGITS) return null;
    $now = $now ?? time();
    $step = intdiv($now, TWOFA_PERIOD);
    $bin = twofaBase32Decode($secretB32);
    if ($bin === '') return null;
    for ($d = -$window; $d <= $window; $d++) {
        if (hash_equals(twofaHotp($bin, $step + $d), $code)) return $step + $d;
    }
    return null;
}

/** Full verification against the stored secret, including the replay refusal. */
function twofaCheck(string $code, ?int $now = null): bool {
    $s = twofaState();
    if (empty($s['enabled']) || $s['secret'] === '') return false;
    $step = twofaVerifyCode((string)$s['secret'], $code, $now);
    if ($step === null) return false;
    if ($step <= (int)$s['last_step']) return false;    // already used, or older than one already used
    $s['last_step'] = $step;
    twofaStateWrite($s);
    return true;
}

/* ── recovery codes ───────────────────────────────────────────────────────── */

/** Ten codes, shown once. Returns the plain codes; only their hashes are kept. */
function twofaMakeRecovery(): array {
    $plain = [];
    $hashes = [];
    for ($i = 0; $i < TWOFA_RECOVERY_COUNT; $i++) {
        // Crockford-ish: no I, L, O, U, so a code read off a screen and typed back cannot be
        // ambiguous. Ten characters of this alphabet is about 46 bits, which is plenty for a code
        // that is single-use and rate-limited by the same lockout as a password.
        $alphabet = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
        $c = '';
        for ($j = 0; $j < 10; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $c = substr($c, 0, 5) . '-' . substr($c, 5);
        $plain[] = $c;
        $hashes[] = hash('sha256', twofaNormalizeRecovery($c));
    }
    return ['plain' => $plain, 'hashes' => $hashes];
}

function twofaNormalizeRecovery(string $c): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c));
}

/** Consume a recovery code. Returns how many are left, or null if it was not one. */
function twofaUseRecovery(string $code): ?int {
    $s = twofaState();
    if (empty($s['enabled'])) return null;
    $want = hash('sha256', twofaNormalizeRecovery($code));
    $left = [];
    $found = false;
    foreach ((array)$s['recovery'] as $h) {
        if (!$found && hash_equals((string)$h, $want)) { $found = true; continue; }
        $left[] = $h;
    }
    if (!$found) return null;
    $s['recovery'] = $left;
    twofaStateWrite($s);
    return count($left);
}

function twofaRecoveryLeft(): int { return count((array)twofaState()['recovery']); }

/* ── setup, confirmation, teardown ────────────────────────────────────────── */

function twofaOtpauthUri(array $cfg, string $secretB32): string {
    $issuer = trim((string)($cfg['site_name'] ?? 'Tracker'));
    if ($issuer === '') $issuer = 'Tracker';
    $account = trim((string)($cfg['admin_username'] ?? 'admin'));
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
         . '?secret=' . $secretB32
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=' . TWOFA_DIGITS . '&period=' . TWOFA_PERIOD;
}

/**
 * Start setup: a new secret and a new set of recovery codes, held as PENDING.
 *
 * Nothing about the login changes until confirm() succeeds. That is the whole point: a secret stored
 * at generation time turns a mistyped key into a locked panel, and the admin would not find out until
 * their next sign-in.
 */
function twofaBeginSetup(array $cfg): array {
    $secret = twofaBase32Encode(random_bytes(20));      // 160-bit, what RFC 4226 recommends
    $rec = twofaMakeRecovery();
    $s = twofaState();
    $s['pending'] = ['secret' => $secret, 'recovery' => $rec['hashes'], 'at' => time()];
    if (!twofaStateWrite($s)) return ['error' => 'Could not write ' . basename(twofaFile()) . ' — check that config/ is writable.'];
    return [
        'secret' => $secret,
        'secret_grouped' => trim(chunk_split($secret, 4, ' ')),
        'uri' => twofaOtpauthUri($cfg, $secret),
        'recovery' => $rec['plain'],
    ];
}

function twofaConfirmSetup(string $code): array {
    $s = twofaState();
    $p = is_array($s['pending'] ?? null) ? $s['pending'] : null;
    if (!$p) return ['error' => 'Nothing is waiting to be confirmed. Start again.'];
    if (time() - (int)($p['at'] ?? 0) > TWOFA_SETUP_TTL) {
        $s['pending'] = null;
        twofaStateWrite($s);
        return ['error' => 'That setup expired. Start again so you get a fresh secret.'];
    }
    $step = twofaVerifyCode((string)$p['secret'], $code);
    if ($step === null) {
        return ['error' => 'That code is not right. Check the clock on the device generating it — TOTP is time-based, and a phone a minute out produces codes this server will not accept.'];
    }
    $s['enabled'] = true;
    $s['secret'] = (string)$p['secret'];
    $s['recovery'] = (array)$p['recovery'];
    $s['last_step'] = $step;                            // the confirming code is spent
    $s['confirmed_at'] = time();
    $s['pending'] = null;
    if (!twofaStateWrite($s)) return ['error' => 'Could not save the setup.'];
    return ['ok' => true];
}

/** Turn it off completely. The caller is responsible for having demanded a password AND a code. */
function twofaDisable(): bool {
    return twofaStateWrite(['enabled' => false, 'secret' => '', 'recovery' => [], 'last_step' => 0,
                            'confirmed_at' => 0, 'pending' => null]);
}

/** New recovery codes, replacing every old one. Shown once, like the first set. */
function twofaRegenerateRecovery(): array {
    $s = twofaState();
    if (empty($s['enabled'])) return ['error' => 'Two-factor authentication is not on.'];
    $rec = twofaMakeRecovery();
    $s['recovery'] = $rec['hashes'];
    if (!twofaStateWrite($s)) return ['error' => 'Could not save the new codes.'];
    return ['recovery' => $rec['plain']];
}

/**
 * Keep the settings mirror honest.
 *
 * The row exists so the settings search can find the section and so a cheap check does not have to
 * read a file. The file is authoritative; when they disagree the row is wrong and gets corrected.
 */
function twofaSyncSetting(PDO $db, array &$cfg): void {
    $want = twofaEnabled() ? '1' : '0';
    if ((string)($cfg['admin_2fa_enabled'] ?? '0') === $want) return;
    try {
        setSettings($db, ['admin_2fa_enabled' => $want]);
        $cfg['admin_2fa_enabled'] = $want;
    } catch (\Throwable $e) { /* the file still decides; a stale row is cosmetic */ }
}
