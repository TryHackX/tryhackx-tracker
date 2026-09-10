<?php
/**
 * A second factor for a MEMBER account.
 *
 * ── why this is not includes/twofa.php ─────────────────────────────────────────────────────────
 * That file is the panel's, and the panel is one operator: its state is a JSON file with one secret
 * in it. An account's second factor belongs to a row, next to the account, and there can be
 * thousands of them. What the two share is the arithmetic — base32, HOTP, the TOTP window, the shape
 * of a recovery code, the otpauth:// URI — and this file uses those functions rather than repeating
 * them, so there is one implementation of the algorithm on this site and one place to be wrong.
 *
 * ── the three states a row can be in ───────────────────────────────────────────────────────────
 *   no row              — the account has no second factor.
 *   secret, enabled = 0 — a setup somebody started and never confirmed. It protects nothing and
 *                         blocks nothing; beginning a new setup overwrites it.
 *   secret, enabled = 1 — armed. Sign-in asks for a code.
 *
 * ── what makes a code single-use ───────────────────────────────────────────────────────────────
 * `last_step`. A TOTP code is valid for a whole 30-second step, plus one either side, so the same
 * six digits work for up to 90 seconds — long enough to be read over a shoulder, or off the wire on
 * an install that has not got TLS yet, and used again. Every accepted code stores its step, and a
 * step that is not newer than the stored one is refused however correct the digits are.
 */

require_once __DIR__ . '/twofa.php';

const USER2FA_SETUP_TTL = 900;      // an unconfirmed setup is stale after 15 minutes

function user2faFeatureEnabled(array $cfg): bool { return (($cfg['user_2fa_enabled'] ?? '0') === '1'); }

/**
 * Who MUST have one: 'off' | 'panel' | 'all'.
 *
 * 'panel' is the answer this exists for: an account that can open the admin panel is an account
 * whose password is worth stealing. The requirement is enforced where it costs nothing to be locked
 * out of — the PANEL does not open for such an account until the factor is on — and never by
 * refusing the sign-in itself. A rule that can lock somebody out of their own account is a rule the
 * operator turns off again after the first support mail.
 */
function user2faRequirement(array $cfg): string
{
    if (!user2faFeatureEnabled($cfg)) return 'off';
    $v = (string)($cfg['user_2fa_required'] ?? 'off');
    return in_array($v, ['off', 'panel', 'all'], true) ? $v : 'off';
}

function user2faRow(PDO $db, int $userId): ?array
{
    try {
        $st = $db->prepare("SELECT * FROM user_twofa WHERE user_id = ?");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (\Throwable $e) { return null; }   // a database that predates v53
}

/** Armed, and therefore asked for at sign-in. A pending setup is not armed. */
function user2faEnabled(PDO $db, int $userId): bool
{
    $r = user2faRow($db, $userId);
    return $r !== null && (int)$r['enabled'] === 1 && (string)$r['secret'] !== '';
}

/** How many recovery codes this account has left — the number worth showing next to the switch. */
function user2faRecoveryLeft(PDO $db, int $userId): int
{
    $r = user2faRow($db, $userId);
    if (!$r || (int)$r['enabled'] !== 1) return 0;
    $codes = json_decode((string)($r['recovery'] ?? '[]'), true);
    return is_array($codes) ? count($codes) : 0;
}

/**
 * Start (or restart) a setup: a fresh secret, unconfirmed, and the URI a phone can scan.
 *
 * The secret is written now rather than held in the session, because the person is about to point a
 * camera at it and the confirmation may come from a different tab, after a reload, or ten minutes
 * later. An unconfirmed row is inert — see the header — so the cost of writing it early is nothing.
 */
function user2faBeginSetup(PDO $db, array $cfg, array $user): array
{
    $secret = twofaBase32Encode(random_bytes(20));
    $db->prepare("INSERT INTO user_twofa (user_id, secret, enabled, recovery, last_step, created_at)
                  VALUES (?, ?, 0, NULL, NULL, NOW())
                  ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled = 0, recovery = NULL,
                                          last_step = NULL, created_at = NOW(), confirmed_at = NULL")
       ->execute([(int)$user['id'], $secret]);
    return [
        'secret' => $secret,
        // The label carries the account name, so somebody with two accounts here can tell the two
        // entries apart in their authenticator.
        'uri'    => user2faOtpauthUri($cfg, (string)$user['username'], $secret),
        'expires_in' => USER2FA_SETUP_TTL,
    ];
}

/** otpauth://totp/<site>:<user>?secret=…&issuer=<site> — the same shape the panel's setup uses. */
function user2faOtpauthUri(array $cfg, string $username, string $secretB32): string
{
    $issuer = trim((string)($cfg['site_name'] ?? 'Tracker'));
    if ($issuer === '') $issuer = 'Tracker';
    $label = rawurlencode($issuer) . ':' . rawurlencode($username);
    return 'otpauth://totp/' . $label . '?secret=' . $secretB32
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=' . TWOFA_DIGITS . '&period=' . TWOFA_PERIOD;
}

/**
 * Confirm a setup with a code from the phone. Returns ['ok' => bool, 'error' => ?string,
 * 'recovery' => string[]] — the recovery codes are returned ONCE, in the clear, and stored hashed.
 */
function user2faConfirmSetup(PDO $db, int $userId, string $code): array
{
    $r = user2faRow($db, $userId);
    if (!$r || (string)$r['secret'] === '') return ['ok' => false, 'error' => 'no_setup'];
    if ((int)$r['enabled'] === 1)           return ['ok' => false, 'error' => 'already_on'];
    if (strtotime((string)$r['created_at']) < time() - USER2FA_SETUP_TTL - 3600) {
        // Deliberately generous: the TTL is measured against a DATETIME the DATABASE wrote and a
        // clock PHP read, and this project has been bitten by those two disagreeing. An hour of slack
        // costs nothing — an expired setup is refused, not accepted — and a stale row is overwritten
        // by the next attempt anyway.
        return ['ok' => false, 'error' => 'setup_expired'];
    }
    $step = twofaVerifyCode((string)$r['secret'], $code);
    if ($step === null) return ['ok' => false, 'error' => 'bad_code'];

    // The panel's generator, alphabet and hashing — one implementation of "recovery code" on this
    // site, so the two cannot drift into different strengths.
    $rec = twofaMakeRecovery();
    $db->prepare("UPDATE user_twofa SET enabled = 1, recovery = ?, last_step = ?, confirmed_at = NOW() WHERE user_id = ?")
       ->execute([json_encode($rec['hashes']), $step, $userId]);
    return ['ok' => true, 'error' => null, 'recovery' => $rec['plain']];
}

/**
 * A code at sign-in: six digits from the phone, or one recovery code.
 *
 * A used recovery code is REMOVED rather than marked, so the list is always exactly what is left.
 * Both paths advance `last_step` — a recovery code is single-use by being deleted, a TOTP code by
 * the step it carries.
 */
function user2faVerify(PDO $db, int $userId, string $code): bool
{
    $r = user2faRow($db, $userId);
    if (!$r || (int)$r['enabled'] !== 1) return false;
    $code = trim($code);
    if ($code === '') return false;

    $step = twofaVerifyCode((string)$r['secret'], $code);
    if ($step !== null) {
        // Replay: the same digits are valid for up to 90 seconds, and this is what makes them once.
        if ($r['last_step'] !== null && $step <= (int)$r['last_step']) return false;
        $db->prepare("UPDATE user_twofa SET last_step = ?, last_used_at = NOW() WHERE user_id = ?")
           ->execute([$step, $userId]);
        return true;
    }

    $codes = json_decode((string)($r['recovery'] ?? '[]'), true);
    if (!is_array($codes) || !$codes) return false;
    $want = hash('sha256', twofaNormalizeRecovery($code));
    $left = [];
    $found = false;
    foreach ($codes as $c) {
        if (!$found && is_string($c) && hash_equals($c, $want)) { $found = true; continue; }
        $left[] = $c;
    }
    if (!$found) return false;
    $db->prepare("UPDATE user_twofa SET recovery = ?, last_used_at = NOW() WHERE user_id = ?")
       ->execute([json_encode($left), $userId]);
    return true;
}

/** Turn it off and forget the secret. The caller is responsible for asking for the password first. */
function user2faDisable(PDO $db, int $userId): bool
{
    try {
        $db->prepare("DELETE FROM user_twofa WHERE user_id = ?")->execute([$userId]);
        return true;
    } catch (\Throwable $e) { return false; }
}

/** A fresh set of recovery codes, replacing whatever is left. Returned once, stored hashed. */
function user2faRegenerateRecovery(PDO $db, int $userId): array
{
    $rec = twofaMakeRecovery();
    $db->prepare("UPDATE user_twofa SET recovery = ? WHERE user_id = ? AND enabled = 1")
       ->execute([json_encode($rec['hashes']), $userId]);
    return $rec['plain'];
}

/**
 * Should this account be told it needs one? ['required' => bool, 'why' => 'panel'|'all'|null].
 *
 * Asked by the account page (to show the warning) and by the panel gate (to keep the panel shut).
 * `panelCan` is not used here: the question is about the ACCOUNT's permissions, not about whatever
 * session happens to be open.
 */
function user2faRequiredFor(PDO $db, array $cfg, array $user): array
{
    $mode = user2faRequirement($cfg);
    if ($mode === 'off') return ['required' => false, 'why' => null];
    if ($mode === 'all') return ['required' => true, 'why' => 'all'];
    $panel = function_exists('userIdHasPermission') && userIdHasPermission($db, $cfg, (int)$user['id'], 'panel.access');
    return ['required' => (bool)$panel, 'why' => $panel ? 'panel' : null];
}
