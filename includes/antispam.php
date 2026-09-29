<?php
/**
 * ONE anti-spam layer for everything people write (1.71.0, schema 85).
 *
 * The owner: "writing comments and descriptions is protected against spam, I take it — if somebody is
 * too quick it fires a CAPTCHA or something? And a guest could always have one? Are messages protected
 * the same way, so nobody starts sending spam? And the other sensitive places, the shoutbox, so nobody
 * floods it? There a growing interval would do: the first 2-3 messages without a limit, then a 5 second
 * limiter, then 15, then 30, and the whole limit expiring after about 2 minutes — or a CAPTCHA; plan it."
 *
 * What there was: a file-based sliding window per address group (rateLimitAllow(), which fails OPEN on a
 * disk error and knows nothing about who is writing), a fixed five-second wall in the room, and no CAPTCHA
 * on anything a member writes. What there is now is this file, asked by EVERY place people write — a line
 * in the room and its correction, a message, a comment and its correction, a torrent's description (the
 * Info panel's submit, proposal and edit, and the whitelist form's), a list's name and description, the
 * profile's description, a report (of a comment, a description, a shout or a message), an emote upload and
 * a vote — with one question and one answer:
 *
 *     $t = antispamCheck($db, $cfg, 'shout', antispamSubject($me, $ip), $text);
 *     if (!$t['ok']) return $t['body'] with $t['status'];     // wait N s | a CAPTCHA | refused, and why
 *     … the write …   then antispamRecord($db, $t['ticket'])   (or antispamRelease() when it did not happen)
 *
 * ── the subject ─────────────────────────────────────────────────────────────────────────────────────
 * The ACCOUNT for a member ('u:<id>'), the ADDRESS GROUP for a guest ('g:<ipBucket>': an IPv4 address, an
 * IPv6 /64). Never the other way round: a member who changes address is the same member, and a guest has
 * nothing else to be known by.
 *
 * ── the ladder (the owner's idea, per context) ──────────────────────────────────────────────────────
 * A free burst, then growing pauses, all forgotten after a quiet period. The writes of the current streak
 * are its LEVEL; the pause before write k is none while k <= burst and steps[k - burst - 1] after it (the
 * last step for everything beyond); `reset` seconds without a write and the level is 0 again. The room, as
 * the owner sketched it: three lines free, then 5 s, 15 s, 30 s and 60 s between lines, two quiet minutes
 * and it starts over. A write refused for coming too early changes nothing but the bookkeeping below — the
 * streak is made of writes, and the quiet that resets it is counted from the last one.
 *
 * ── CAPTCHA ─────────────────────────────────────────────────────────────────────────────────────────
 * Whoever keeps hitting the TOP of a ladder — a write made at its last step, or an attempt refused while
 * waiting there — meets a CAPTCHA after `antispam_captcha_after` such hits (3), and solving it eases the
 * level back to the edge of the burst: the next pause is the first step again. A GUEST solves one every
 * time they write (a comment, a description; `antispam_guest_captcha`). The token is verified BEFORE the
 * state is locked (a verifier is a network call and a lock is not something to hold across one), and one
 * the same request already verified — the whitelist form asks for its own — counts. With no provider set
 * up there is nobody to ask: the layer falls back to its pauses alone, and Settings says so (a guest's
 * comment is still impossible without a provider — that is the comments' own rule, includes/comments.php).
 * The order a person meets things in: how long to wait first, then how many, then whether they said this
 * already, and only then the CAPTCHA — nobody solves a puzzle to be told to wait, or to be told they just
 * said that.
 *
 * ── atomic, and never open ──────────────────────────────────────────────────────────────────────────
 * The state is a row per (context, subject) in `antispam_state`, and the check DECIDES AND RESERVES under
 * SELECT … FOR UPDATE: two requests racing each other are served one after the other, and the second sees
 * the first's write — a double click cannot post twice, a script with ten connections cannot take ten free
 * lines. The times are unix seconds by the DATABASE's clock (a web server whose clock drifts cannot let a
 * burst through), stored as numbers so no time zone can reinterpret them. A reservation whose write did not
 * happen (the words were refused after the check) is handed back with antispamRelease(). Inside a caller's
 * own transaction (the message send holds one) the layer uses it, and the caller's rollback is the release.
 * Any database failure is a REFUSAL ("could not be checked, try again in a moment") — the file limiter's
 * "fail open" is exactly what this layer is not.
 *
 * ── the other rules ─────────────────────────────────────────────────────────────────────────────────
 * DUPLICATES: the same words (case and spacing aside) again in the same context within
 * `antispam_dup_seconds` (600) are refused — in the room, a message (to the same person), a comment, a
 * description. Fewer than 8 characters never count: "hi" and "+1" are not spam.
 * MESSAGES are paced by NEW CONVERSATIONS, not by lines — a conversation both people are in is a chat, and
 * the other person can block — and limited per hour and per 24 hours (much tighter for new accounts),
 * counted from the messages themselves; the same words to more than `antispam_pm_spread` people within the
 * duplicate window are refused as spam.
 * NEW ACCOUNTS (younger than `antispam_new_days`, 3): every pause and the reset × `antispam_new_factor` (2),
 * their own conversation limits, and links in what they write in the room, a comment, a list's description
 * and the profile's description drawn as TEXT (antispamWrittenNew(), asked at render from when the text was
 * written: a link written while new stays text; one written once trusted is a link).
 * STAFF (an account holding panel.access) skip the ladders, the escalation, the new-account rules and the
 * conversation limits while `antispam_staff_exempt` is on; the plain duplicate applies to everybody.
 * THE AUDIT hears of a subject refused three times in a row — once an hour per subject, at most.
 * With `antispam_enabled` off nothing here paces anybody, except the room's own old wall
 * (`shout_flood_seconds`, which with the layer on is the floor under the room's steps).
 */

const ANTISPAM_CONTEXTS = ['shout', 'message', 'comment', 'description', 'report', 'list', 'bio', 'emote', 'vote'];
// [burst, [steps, seconds], reset seconds] — the shipped ladders (and the defaults' source of truth).
const ANTISPAM_DEFAULTS = [
    'shout'       => [3,  [5, 15, 30, 60],       120],
    'message'     => [3,  [30, 60, 120, 300],    600],
    'comment'     => [2,  [15, 30, 60, 120],     300],
    'description' => [2,  [60, 300, 900, 1800],  3600],
    'report'      => [3,  [30, 120, 300, 900],   1800],
    'list'        => [3,  [10, 30, 60, 120],     600],
    'bio'         => [3,  [30, 60, 120, 300],    900],
    'emote'       => [3,  [30, 60, 120, 300],    1800],
    'vote'        => [10, [2, 5, 10, 30],        120],
];
const ANTISPAM_DUP_CONTEXTS   = ['shout', 'message', 'comment', 'description'];   // each write is a new visible thing
const ANTISPAM_GUEST_CONTEXTS = ['comment', 'description'];                       // what a guest can write
const ANTISPAM_DUP_MIN_CHARS  = 8;        // shorter words are never a duplicate
const ANTISPAM_RECENT_MAX     = 12;       // fingerprints kept per subject and context
const ANTISPAM_STEPS_MAX      = 8;
const ANTISPAM_STEP_MAX       = 86400;    // a pause of at most a day
const ANTISPAM_BURST_MAX      = 50;
const ANTISPAM_RESET_MIN      = 10;
const ANTISPAM_RESET_MAX      = 604800;   // a week
const ANTISPAM_AUDIT_AFTER    = 3;        // refusals in a row before the audit log hears of it…
const ANTISPAM_AUDIT_EVERY    = 3600;     // …at most once per subject in this many seconds
const ANTISPAM_KEEP_SECONDS   = 172800;   // a row untouched for two days is forgotten (antispamPrune())
const ANTISPAM_EDIT_GAP       = 5;        // seconds between two corrections (the room: shout_flood_seconds)

/* ── the settings, clamped on read ────────────────────────────────────────────────────────────────── */

/** The master switch (Settings → Security & CAPTCHA → Anti-spam). */
function antispamOn(array $cfg): bool { return ($cfg['antispam_enabled'] ?? '1') === '1'; }

/** Top-of-the-ladder hits before a CAPTCHA is asked (0 = never). */
function antispamCaptchaAfter(array $cfg): int { return max(0, min(50, (int)($cfg['antispam_captcha_after'] ?? 3))); }

/** Does a guest solve a CAPTCHA every time they write? (Its own switch: it holds with the layer off too.) */
function antispamGuestCaptcha(array $cfg): bool { return ($cfg['antispam_guest_captcha'] ?? '1') === '1'; }

/** Are staff (panel.access) exempt from the ladders, the escalation and the new-account rules? */
function antispamStaffExemptOn(array $cfg): bool { return ($cfg['antispam_staff_exempt'] ?? '1') === '1'; }

/** How many days an account counts as new (0 = never). */
function antispamNewDays(array $cfg): int { return max(0, min(90, (int)($cfg['antispam_new_days'] ?? 3))); }

/** How much longer a new account's pauses and reset are. */
function antispamNewFactor(array $cfg): int { return max(1, min(10, (int)($cfg['antispam_new_factor'] ?? 2) ?: 2)); }

/** Are a new account's links drawn as text? */
function antispamNewLinksOn(array $cfg): bool { return ($cfg['antispam_new_links'] ?? '1') === '1'; }

/** The duplicate window in seconds (0 = no duplicate rule). */
function antispamDupSeconds(array $cfg): int { return max(0, min(86400, (int)($cfg['antispam_dup_seconds'] ?? 600))); }

/** New conversations an hour and a day: ['hour' => n, 'day' => n], 0 = no limit — a new account's own pair. */
function antispamPmLimits(array $cfg, bool $newAccount): array
{
    $sfx = $newAccount ? '_new' : '';
    $d = $newAccount ? [2, 4] : [8, 20];
    return ['hour' => max(0, min(1000, (int)($cfg['antispam_pm_new_hour' . $sfx] ?? $d[0]))),
            'day'  => max(0, min(10000, (int)($cfg['antispam_pm_new_day' . $sfx] ?? $d[1])))];
}

/** The same words to at most this many people inside the duplicate window (0 = no such rule). */
function antispamPmSpread(array $cfg): int { return max(0, min(50, (int)($cfg['antispam_pm_spread'] ?? 2))); }

/** "5, 15,30;60" → [5, 15, 30, 60]: whole seconds, each 1..a day, at most eight — the order as typed. */
function antispamParseSteps(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $p) {
        if ($p === '' || !ctype_digit($p)) continue;
        $out[] = max(1, min(ANTISPAM_STEP_MAX, (int)$p));
        if (count($out) >= ANTISPAM_STEPS_MAX) break;
    }
    return $out;
}

/**
 * One context's ladder as Settings says, clamped: ['burst' => n, 'steps' => [s…], 'reset' => s, 'floor' => s].
 * For a NEW account every pause and the reset are multiplied (antispam_new_factor). `floor` is the room's
 * `shout_flood_seconds` — the least pause once any pause applies, and with the layer off the room's whole wall.
 */
function antispamLadder(array $cfg, string $ctx, bool $newAccount = false): array
{
    $d = ANTISPAM_DEFAULTS[$ctx] ?? [3, [30, 60, 120, 300], 600];
    $burst = max(0, min(ANTISPAM_BURST_MAX, (int)($cfg['antispam_' . $ctx . '_burst'] ?? $d[0])));
    $raw = $cfg['antispam_' . $ctx . '_steps'] ?? null;
    $steps = $raw === null ? $d[1] : antispamParseSteps((string)$raw);
    $reset = max(ANTISPAM_RESET_MIN, min(ANTISPAM_RESET_MAX, (int)($cfg['antispam_' . $ctx . '_reset'] ?? $d[2]) ?: $d[2]));
    $floor = $ctx === 'shout' && function_exists('shoutFloodSeconds') ? shoutFloodSeconds($cfg) : 0;
    if ($newAccount) {
        $f = antispamNewFactor($cfg);
        $steps = array_map(fn(int $s): int => min(ANTISPAM_STEP_MAX, $s * $f), $steps);
        $reset = min(ANTISPAM_RESET_MAX, $reset * $f);
    }
    return ['burst' => $burst, 'steps' => array_values($steps), 'reset' => $reset, 'floor' => $floor];
}

/** The pause before the k-th write of a streak (k from 1): none inside the burst, then the steps, the last one repeated. */
function antispamPauseBefore(array $lad, int $k): int
{
    if ($k <= $lad['burst'] || !$lad['steps']) return 0;
    $i = min($k - $lad['burst'] - 1, count($lad['steps']) - 1);
    return max((int)$lad['steps'][$i], (int)$lad['floor']);
}

/** Is the k-th write of a streak made at the TOP of the ladder (its last step)? */
function antispamAtTop(array $lad, int $k): bool
{
    return $lad['steps'] && $k >= $lad['burst'] + count($lad['steps']);
}

/* ── who is writing ──────────────────────────────────────────────────────────────────────────────── */

/** The subject: the account for a member, the address group for a guest. */
function antispamSubject(?array $me, string $ip = ''): array
{
    $uid = (int)($me['id'] ?? 0);
    if ($uid > 0) return ['key' => 'u:' . $uid, 'uid' => $uid, 'guest' => false, 'user' => $me, 'ip' => $ip];
    $bucket = $ip !== '' ? (function_exists('ipBucket') ? ipBucket($ip) : $ip) : '0.0.0.0';
    return ['key' => mb_substr('g:' . $bucket, 0, 64), 'uid' => 0, 'guest' => true, 'user' => null, 'ip' => $ip];
}

/** Staff, as far as the layer is concerned: an account holding panel.access, while the exemption is on. */
function antispamIsStaff(PDO $db, array $cfg, int $uid): bool
{
    return $uid > 0 && antispamStaffExemptOn($cfg) && function_exists('userIdHasPermission')
        && userIdHasPermission($db, $cfg, $uid, 'panel.access');
}

/** An account's age in seconds, by the database's clock (null: no such account). */
function antispamAccountAge(PDO $db, int $uid): ?int
{
    if ($uid <= 0) return null;
    $st = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM users WHERE id = ?");
    $st->execute([$uid]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? null : (int)$v;
}

/** Is this subject a NEW account (and not staff)? Guests are not accounts at all. */
function antispamIsNew(PDO $db, array $cfg, array $subject): bool
{
    $days = antispamNewDays($cfg);
    if ($days <= 0 || !empty($subject['guest']) || (int)$subject['uid'] <= 0) return false;
    if (antispamIsStaff($db, $cfg, (int)$subject['uid'])) return false;
    $age = antispamAccountAge($db, (int)$subject['uid']);
    return $age !== null && $age < $days * 86400;
}

/**
 * Were these words written while their author's account was NEW — so their links are drawn as text?
 * `$ageAtWrite` is how old the account was, in seconds, when the words were last written (their edit, else
 * their writing): the room and the comments have the database compute it in the row's query; a list's and the
 * profile's description compute it with antispamAgeAt(). Asked at render, from WHEN the words were written, not
 * from the account's age today: a link written on the first day stays text on the fourth (nobody's spam goes
 * live by itself three days later), and one written once the account is trusted is a link. A moderator's
 * correction re-dates the words. Staff (while exempt) are never new.
 */
function antispamWrittenNew(PDO $db, array $cfg, int $authorId, $ageAtWrite): bool
{
    if (!antispamOn($cfg) || !antispamNewLinksOn($cfg) || $authorId <= 0) return false;
    $days = antispamNewDays($cfg);
    if ($days <= 0 || $ageAtWrite === null || $ageAtWrite === '' || !is_numeric($ageAtWrite)) return false;
    if ((int)$ageAtWrite >= $days * 86400) return false;
    return !antispamIsStaff($db, $cfg, $authorId);
}

/** Would words THIS account writes now be drawn with their links as text? (A Preview asks, before anything is written.) */
function antispamLinksTextNow(PDO $db, array $cfg, ?array $me): bool
{
    if (!antispamOn($cfg) || !antispamNewLinksOn($cfg) || (int)($me['id'] ?? 0) <= 0) return false;
    return antispamIsNew($db, $cfg, antispamSubject($me));
}

/**
 * An account's age when something was written: two DATETIMEs of THIS database (users.created_at and the text's
 * own time), whose difference is the same whichever zone they were both written in. Null when either is missing.
 */
function antispamAgeAt(?string $accountCreated, ?string $writtenAt): ?int
{
    if ($accountCreated === null || $accountCreated === '' || $writtenAt === null || $writtenAt === '') return null;
    $a = strtotime($accountCreated);
    $w = strtotime($writtenAt);
    return ($a === false || $w === false) ? null : $w - $a;
}

/* ── the words ───────────────────────────────────────────────────────────────────────────────────── */

/** A text's fingerprint for the duplicate rule: case and spacing aside; '' for words too short to count. */
function antispamFingerprint(?string $text): string
{
    if ($text === null) return '';
    $t = mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
    if (mb_strlen($t, 'UTF-8') < ANTISPAM_DUP_MIN_CHARS) return '';
    return substr(hash('sha256', $t), 0, 16);
}

/** "12 s", "2 min 5 s", "1 h 5 min" — in the reader's language, no plural to get wrong. */
function antispamTimeText(int $s): string
{
    $s = max(1, $s);
    $u = fn(string $k): string => function_exists('__') ? __('api.antispam.u_' . $k) : $k;
    if ($s < 60) return $s . ' ' . $u('s');
    if ($s < 3600) {
        $m = intdiv($s, 60); $r = $s % 60;
        return $m . ' ' . $u('min') . ($r ? ' ' . $r . ' ' . $u('s') : '');
    }
    $h = intdiv($s, 3600); $m = (int)ceil(($s % 3600) / 60);
    if ($m === 60) { $h++; $m = 0; }
    return $h . ' ' . $u('h') . ($m ? ' ' . $m . ' ' . $u('min') : '');
}

/* ── the answer a refusal is ──────────────────────────────────────────────────────────────────────── */

/**
 * A refusal, ready for jsonResponse(): ['ok' => false, 'kind', 'status', 'body']. The body carries `error`
 * (antispam_wait | captcha_required | captcha_failed | antispam_duplicate | antispam_spread |
 * antispam_conversations | antispam_unavailable), the sentence in the reader's language with the time in it,
 * `retry_after`, and `antispam` {kind, context, seconds, tpl} — `tpl` is the same sentence with "{time}" where
 * the time goes, so a page can count down in it. A CAPTCHA request also carries `captcha_required` and the
 * provider and its public key (`captcha`), so a page that had no CAPTCHA box can load one and ask.
 */
function antispamRefusal(array $cfg, string $kind, string $ctx, array $vars = []): array
{
    $seconds = max(0, (int)($vars['seconds'] ?? 0));
    $time = $seconds > 0 ? antispamTimeText($seconds) : '';
    $t = fn(string $key, array $v = []): string => function_exists('__') ? __($key, $v) : $key;
    $status = 429;
    $error = 'antispam_' . $kind;
    $key = 'api.antispam.' . $kind;
    $v = $vars + ['time' => $time];
    switch ($kind) {
        case 'wait':
            $key = 'api.antispam.wait_' . (($vars['mode'] ?? 'write') === 'edit' ? 'edit' : $ctx);
            break;
        case 'captcha':
            $status = 428;
            $error = !empty($vars['failed']) ? 'captcha_failed' : 'captcha_required';
            $key = !empty($vars['failed']) ? 'api.antispam.captcha_failed' : (!empty($vars['guest']) ? 'api.antispam.captcha_guest' : 'api.antispam.captcha');
            break;
        case 'duplicate':
            $status = 409;
            break;
        case 'spread':
            $status = 429;
            break;
        case 'conversations':
            $key = 'api.antispam.conversations_' . (($vars['per'] ?? 'hour') === 'day' ? 'day' : 'hour') . (!empty($vars['new']) ? '_new' : '');
            break;
        case 'unavailable':
            $status = 503;
            break;
    }
    $body = [
        'success'     => false,
        'error'       => $error,
        'message'     => $t($key, $v),
        'antispam'    => ['kind' => $kind, 'context' => $ctx, 'seconds' => $seconds,
                          'tpl' => $seconds > 0 ? $t($key, ['time' => '{time}'] + $vars) : ''],
    ];
    if ($seconds > 0) $body['retry_after'] = $seconds;
    if ($kind === 'captcha') {
        $body['captcha_required'] = true;
        if (function_exists('captchaConfigured') && captchaConfigured($cfg)) {
            $body['captcha'] = ['provider' => captchaProvider($cfg), 'site_key' => captchaSiteKey($cfg)];
        }
    }
    if ($seconds > 0 && PHP_SAPI !== 'cli' && !headers_sent()) header('Retry-After: ' . $seconds);
    return ['ok' => false, 'kind' => $kind, 'status' => $status, 'seconds' => $seconds, 'body' => $body];
}

/* ── the check ───────────────────────────────────────────────────────────────────────────────────── */

/**
 * May this subject write here now — and if so, the write is RESERVED. See the head of this file.
 *
 * $opts:
 *   mode        'write' (default) | 'edit' — a correction: never laddered, no duplicate, no CAPTCHA; only a gap
 *               of `edit_gap` seconds between two corrections (the room: shout_flood_seconds, as before)
 *   target      the other side, for the duplicate and spread rules of a message ('u:<id>')
 *   new         a message: does this line START a conversation (only those climb its ladder and count)
 *   input       the request's body, where a CAPTCHA token is read from
 *   captcha_ok  this request already verified a CAPTCHA (the whitelist form's own)
 *   verify      fn(string $token): bool — a test's verifier in place of the provider's
 *   edit_gap    seconds between two corrections
 * → ['ok' => true, 'ticket' => [...]] or antispamRefusal().
 *
 * Called inside the CALLER's transaction (the message send holds one around its count and its insert), the
 * layer opens none of its own: its reservation commits with the caller's write, and the caller's rollback is
 * the release. A refusal's bookkeeping (the refusals in a row, a hit at the top, a CAPTCHA now due) is then
 * part of that transaction as well — so a caller COMMITS before answering a refusal (it has written nothing
 * else by then), or a script hammering the endpoint would never be counted.
 */
function antispamCheck(PDO $db, array $cfg, string $ctx, array $subject, ?string $text = null, array $opts = []): array
{
    // Closed on EVERY failure, the questions asked before the lock included (who is staff, how old the account
    // is): a database that cannot answer is a refusal, never a pass.
    try {
        return antispamCheckAsked($db, $cfg, $ctx, $subject, $text, $opts);
    } catch (\Throwable $e) {
        error_log('[antispam] ' . $ctx . ' ' . ($subject['key'] ?? '?') . ': ' . $e->getMessage());
        return antispamRefusal($cfg, 'unavailable', $ctx);
    }
}

/** antispamCheck()'s body — see there. */
function antispamCheckAsked(PDO $db, array $cfg, string $ctx, array $subject, ?string $text, array $opts): array
{
    if (!in_array($ctx, ANTISPAM_CONTEXTS, true)) return antispamRefusal($cfg, 'unavailable', $ctx);
    $mode = ($opts['mode'] ?? 'write') === 'edit' ? 'edit' : 'write';
    $on = antispamOn($cfg);
    $guest = !empty($subject['guest']);
    $uid = (int)($subject['uid'] ?? 0);
    $captchaOn = function_exists('captchaConfigured') && captchaConfigured($cfg);
    $exempt = $on && !$guest && antispamIsStaff($db, $cfg, $uid);
    $isNew = $on && !$exempt && antispamIsNew($db, $cfg, $subject);
    $lad = antispamLadder($cfg, $ctx, $isNew);
    // The message ladder counts conversations started, not lines.
    $counts = $ctx !== 'message' || !empty($opts['new']);
    // The layer off: the room keeps its old fixed wall (shout_flood_seconds between every two lines), nothing else.
    if (!$on) $lad = ['burst' => 0, 'steps' => $lad['floor'] > 0 ? [$lad['floor']] : [], 'reset' => ANTISPAM_RESET_MAX, 'floor' => $lad['floor']];
    $pacing = $mode === 'write' && $counts && (($on && !$exempt) || (!$on && $lad['floor'] > 0));
    // The room always knows its last line, paced or not: the wall and the floor are measured from it. Its last
    // CORRECTION too (the gap between two edits), so a wall set in Settings applies at once to the edit before it.
    $keepLast = $mode === 'write' && $ctx === 'shout' && !$pacing;
    $keepEdit = $mode === 'edit' && $ctx === 'shout';
    $guestCaptcha = $mode === 'write' && $guest && antispamGuestCaptcha($cfg) && $captchaOn && in_array($ctx, ANTISPAM_GUEST_CONTEXTS, true);
    // The escalation belongs to a ladder: only a write that climbs one can be asked for a CAPTCHA by it.
    $escalate = $pacing && $on && !$exempt && $captchaOn && antispamCaptchaAfter($cfg) > 0;
    $dupOn = $mode === 'write' && $on && in_array($ctx, ANTISPAM_DUP_CONTEXTS, true) && antispamDupSeconds($cfg) > 0;
    $fp = $dupOn ? antispamFingerprint($text) : '';
    $target = mb_substr((string)($opts['target'] ?? ''), 0, 24);
    $convLimits = $ctx === 'message' && $mode === 'write' && $on && !$exempt && !empty($opts['new']) && $uid > 0;
    $editGap = $mode === 'edit' ? max(0, (int)($opts['edit_gap'] ?? ANTISPAM_EDIT_GAP)) : 0;
    if ($mode === 'edit' && (!$on || $exempt)) $editGap = (!$on && $ctx === 'shout') ? $lad['floor'] : 0;

    // Nothing to ask and nothing to keep: no row, no lock.
    if (!$pacing && !$keepLast && !$keepEdit && !$guestCaptcha && !$escalate && $fp === '' && $editGap <= 0 && !$convLimits) {
        return ['ok' => true, 'ticket' => null];
    }

    // A token the request carries is verified NOW, before any lock (see the head of this file).
    $verified = !empty($opts['captcha_ok']);
    $tokenBad = false;
    if (!$verified && ($guestCaptcha || $escalate) && $mode === 'write') {
        $input = (array)($opts['input'] ?? []);
        $token = function_exists('captchaTokenFromInput') ? captchaTokenFromInput($input) : (string)($input['captcha_token'] ?? '');
        if ($token !== '') {
            $verify = $opts['verify'] ?? null;
            $verified = is_callable($verify) ? (bool)$verify($token) : (function_exists('verifyCaptcha') && verifyCaptcha($token, $cfg));
            $tokenBad = !$verified;
        }
    }

    $own = !$db->inTransaction();
    try {
        if ($own) $db->beginTransaction();
        $now = (int)$db->query("SELECT UNIX_TIMESTAMP()")->fetchColumn();
        antispamLockRow($db, $ctx, (string)$subject['key'], $now);
        $st = $db->prepare("SELECT * FROM antispam_state WHERE context = ? AND subject = ? FOR UPDATE");
        $st->execute([$ctx, $subject['key']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('antispam: no state row');
        $prev = $row;
        $s = [
            'level'    => (int)$row['level'],    'last_at' => (int)$row['last_at'], 'next_at' => (int)$row['next_at'],
            'top_hits' => (int)$row['top_hits'], 'captcha' => (int)$row['captcha'], 'refused' => (int)$row['refused'],
            'edit_at'  => (int)$row['edit_at'],  'recent'  => antispamRecentDecode((string)$row['recent']),
        ];
        // A quiet streak is forgotten, CAPTCHA demand and all: "the whole limit expires".
        if ($s['last_at'] > 0 && $now - $s['last_at'] >= $lad['reset']) {
            $s['level'] = 0; $s['top_hits'] = 0; $s['captcha'] = 0;
        }
        $win = antispamDupSeconds($cfg);
        $s['recent'] = array_values(array_filter($s['recent'], fn(array $e): bool => $now - (int)$e[1] < max($win, 1)));

        $refuse = function (array $refusal) use ($db, $cfg, $ctx, $subject, &$s, $now, $own, $lad, $pacing): array {
            $s['refused']++;
            antispamSave($db, $ctx, (string)$subject['key'], $s, $now);
            if ($s['refused'] >= ANTISPAM_AUDIT_AFTER) antispamAuditMaybe($db, $cfg, $ctx, $subject, $s, $now, (string)$refusal['kind']);
            if ($own) $db->commit();
            return $refusal;
        };

        if ($mode === 'edit') {
            if ($editGap > 0 && $s['edit_at'] > 0 && $now - $s['edit_at'] < $editGap) {
                return $refuse(antispamRefusal($cfg, 'wait', $ctx, ['seconds' => $editGap - ($now - $s['edit_at']), 'mode' => 'edit']));
            }
            $s['edit_at'] = $now;
            $rev = antispamSave($db, $ctx, (string)$subject['key'], $s, $now);
            if ($own) $db->commit();
            return ['ok' => true, 'ticket' => antispamTicket($ctx, $subject, $prev, $rev, !$own, $db)];
        }

        // 1. how long to wait — from the last write, by the ladder as Settings says NOW (`next_at` is kept for
        //    whoever reads the table; a pause shortened or lengthened in Settings applies at once)
        if ($pacing && $s['last_at'] > 0) {
            $until = $s['last_at'] + antispamPauseBefore($lad, $s['level'] + 1);
            if ($until > $now) {
                if ($on && antispamAtTop($lad, $s['level'] + 1)) {
                    $s['top_hits']++;
                    if ($escalate && $s['top_hits'] >= antispamCaptchaAfter($cfg)) $s['captcha'] = 1;
                }
                return $refuse(antispamRefusal($cfg, 'wait', $ctx, ['seconds' => $until - $now]));
            }
        }
        // 2. how many (a message that starts a conversation)
        if ($convLimits) {
            $lim = antispamPmLimits($cfg, $isNew);
            foreach (['hour' => 3600, 'day' => 86400] as $per => $span) {
                if ($lim[$per] <= 0) continue;
                $started = antispamConversationsStarted($db, $uid, $span);
                if (count($started) >= $lim[$per]) {
                    $oldest = (int)$started[count($started) - $lim[$per]];
                    return $refuse(antispamRefusal($cfg, 'conversations', $ctx, ['seconds' => max(1, $oldest + $span - $now),
                        'per' => $per, 'n' => $lim[$per], 'new' => $isNew, 'days' => antispamNewDays($cfg)]));
                }
            }
        }
        // 3. said already (and, for a message, said to too many people)
        if ($fp !== '') {
            foreach ($s['recent'] as $e) {
                if ((string)$e[0] === $fp && ($ctx !== 'message' || (string)($e[2] ?? '') === $target)) {
                    return $refuse(antispamRefusal($cfg, 'duplicate', $ctx));
                }
            }
            if ($ctx === 'message' && !$exempt && ($spread = antispamPmSpread($cfg)) > 0) {
                $others = [];
                foreach ($s['recent'] as $e) if ((string)$e[0] === $fp && (string)($e[2] ?? '') !== $target) $others[(string)($e[2] ?? '')] = true;
                if (count($others) >= $spread) return $refuse(antispamRefusal($cfg, 'spread', $ctx));
            }
        }
        // 4. a CAPTCHA, where one is due
        $need = $guestCaptcha || ($escalate && $s['captcha'] === 1);
        $solved = false;
        if ($need) {
            if (!$verified) {
                return $refuse(antispamRefusal($cfg, 'captcha', $ctx, ['failed' => $tokenBad, 'guest' => $guestCaptcha]));
            }
            $solved = true;
            if ($s['captcha'] === 1) {
                // …and the level eases: this write counts as the burst's last, so the next pause is the first step.
                $s['level'] = max(0, min($s['level'], $lad['burst'] - 1));
                $s['top_hits'] = 0;
                $s['captcha'] = 0;
            }
        }
        // The write is reserved.
        if ($pacing) {
            if ($on) {
                $s['level']++;
                if (antispamAtTop($lad, $s['level'])) {
                    $s['top_hits']++;
                    if ($escalate && $s['top_hits'] >= antispamCaptchaAfter($cfg)) $s['captcha'] = 1;
                }
            } else {
                $s['level'] = 0;   // no streak while the layer is off: the room's wall is the same for every line
            }
            $s['last_at'] = $now;
            $s['next_at'] = $now + antispamPauseBefore($lad, $s['level'] + 1);
        } elseif ($keepLast) {
            $s['level'] = 0;
            $s['last_at'] = $now;
            $s['next_at'] = $now;
        }
        $s['refused'] = 0;
        if ($fp !== '') {
            $s['recent'][] = [$fp, $now, $target];
            $s['recent'] = array_slice($s['recent'], -ANTISPAM_RECENT_MAX);
        }
        $rev = antispamSave($db, $ctx, (string)$subject['key'], $s, $now);
        if ($own) $db->commit();
        if (($solved || $verified) && session_status() === PHP_SESSION_ACTIVE && function_exists('onCaptchaSolved') && empty($opts['captcha_ok'])) {
            onCaptchaSolved();
        }
        return ['ok' => true, 'ticket' => antispamTicket($ctx, $subject, $prev, $rev, !$own, $db) + ['solved' => $solved, 'new_account' => $isNew]];
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) { try { $db->rollBack(); } catch (\Throwable $e2) { /* gone already */ } }
        error_log('[antispam] ' . $ctx . ' ' . $subject['key'] . ': ' . $e->getMessage());
        return antispamRefusal($cfg, 'unavailable', $ctx);
    }
}

/**
 * Make sure the row exists AND hold it — one statement. An upsert, not INSERT IGNORE: on a row that is already
 * there INSERT IGNORE takes a SHARED lock, and two requests each holding one and each wanting the exclusive
 * lock of the SELECT … FOR UPDATE that follows are a deadlock (the loser would be refused as "could not be
 * checked"). ON DUPLICATE KEY UPDATE takes the exclusive lock at once, so the second request simply waits
 * for the first to finish — which is the point.
 */
function antispamLockRow(PDO $db, string $ctx, string $key, int $now): void
{
    $db->prepare("INSERT INTO antispam_state (context, subject, updated_at) VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE rev = rev")
       ->execute([$ctx, $key, $now]);
}

/** The fingerprints column, read defensively: [[fp, at, target], …]. */
function antispamRecentDecode(string $raw): array
{
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    if (!is_array($j)) return [];
    $out = [];
    foreach ($j as $e) if (is_array($e) && isset($e[0], $e[1]) && is_string($e[0])) $out[] = [(string)$e[0], (int)$e[1], (string)($e[2] ?? '')];
    return $out;
}

/** Write the state back (the row is locked by the caller); returns the new revision. */
function antispamSave(PDO $db, string $ctx, string $key, array $s, int $now): int
{
    $recent = $s['recent'] ? json_encode(array_values($s['recent']), JSON_UNESCAPED_SLASHES) : '';
    if (strlen($recent) > 1000) $recent = json_encode(array_slice(array_values($s['recent']), -6), JSON_UNESCAPED_SLASHES);
    $db->prepare("UPDATE antispam_state SET level = ?, last_at = ?, next_at = ?, top_hits = ?, captcha = ?, refused = ?,
                         edit_at = ?, recent = ?, rev = rev + 1, updated_at = ? WHERE context = ? AND subject = ?")
       ->execute([min(65535, max(0, $s['level'])), max(0, $s['last_at']), max(0, $s['next_at']), min(65535, max(0, $s['top_hits'])),
                  $s['captcha'] ? 1 : 0, min(65535, max(0, $s['refused'])), max(0, $s['edit_at']), $recent, $now, $ctx, $key]);
    $st = $db->prepare("SELECT rev FROM antispam_state WHERE context = ? AND subject = ?");
    $st->execute([$ctx, $key]);
    return (int)$st->fetchColumn();
}

/** A reservation, as the caller holds it until the write happened (record) or did not (release). */
function antispamTicket(string $ctx, array $subject, array $prev, int $rev, bool $inTx, PDO $db): array
{
    return ['context' => $ctx, 'subject' => (string)$subject['key'], 'rev' => $rev, 'in_tx' => $inTx, 'prev' => $prev];
}

/** The write happened: the reservation stands. (Nothing left to write — the check already kept it.) */
function antispamRecord(PDO $db, ?array $ticket): void
{
    // Kept as the second half of the handshake on purpose: every writer says, after its write, that the
    // write happened — tests/antispam_test.php reads each endpoint for the pair — and a later rule that needs
    // to know about the finished write (its id, say) has its place here.
}

/**
 * The write did NOT happen (its words were refused after the check): the reservation is handed back — the
 * state as it was before the check, if nobody has written since. Inside a caller's transaction there is
 * nothing to do: the caller's rollback is the release.
 */
function antispamRelease(PDO $db, ?array $ticket): void
{
    if (!$ticket || !empty($ticket['in_tx'])) return;
    $p = (array)$ticket['prev'];
    try {
        $db->beginTransaction();
        $st = $db->prepare("SELECT rev FROM antispam_state WHERE context = ? AND subject = ? FOR UPDATE");
        $st->execute([$ticket['context'], $ticket['subject']]);
        if ((int)$st->fetchColumn() === (int)$ticket['rev']) {
            $db->prepare("UPDATE antispam_state SET level = ?, last_at = ?, next_at = ?, top_hits = ?, captcha = ?, refused = ?,
                                 edit_at = ?, recent = ?, rev = rev + 1 WHERE context = ? AND subject = ?")
               ->execute([(int)$p['level'], (int)$p['last_at'], (int)$p['next_at'], (int)$p['top_hits'], (int)$p['captcha'],
                          (int)$p['refused'], (int)$p['edit_at'], (string)$p['recent'], $ticket['context'], $ticket['subject']]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { try { $db->rollBack(); } catch (\Throwable $e2) { /* gone already */ } }
        error_log('[antispam] release: ' . $e->getMessage());
    }
}

/**
 * The conversations this account STARTED in the last $seconds — its messages that were the first in their
 * thread — as their unix times, oldest first. Counted from the messages themselves: nothing to keep in step,
 * and a conversation the other person began is never counted against the one who answers.
 */
function antispamConversationsStarted(PDO $db, int $uid, int $seconds): array
{
    $st = $db->prepare("SELECT UNIX_TIMESTAMP(m.created_at) FROM user_messages m
                         WHERE m.sender_id = ? AND m.created_at >= NOW() - INTERVAL ? SECOND
                           AND NOT EXISTS (SELECT 1 FROM user_messages p WHERE p.thread_id = m.thread_id AND p.id < m.id)
                         ORDER BY m.id ASC");
    $st->execute([$uid, max(1, $seconds)]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * The audit log hears of a subject refused ANTISPAM_AUDIT_AFTER times in a row — once per ANTISPAM_AUDIT_EVERY
 * per subject, whatever the context (the '*' row keeps when it last did). Inside the check's transaction.
 */
function antispamAuditMaybe(PDO $db, array $cfg, string $ctx, array $subject, array $s, int $now, string $kind): void
{
    if (!function_exists('auditLog')) return;
    antispamLockRow($db, '*', (string)$subject['key'], $now);
    $st = $db->prepare("SELECT last_at FROM antispam_state WHERE context = '*' AND subject = ? FOR UPDATE");
    $st->execute([$subject['key']]);
    $last = (int)$st->fetchColumn();
    if ($last > 0 && $now - $last < ANTISPAM_AUDIT_EVERY) return;
    $db->prepare("UPDATE antispam_state SET last_at = ?, updated_at = ? WHERE context = '*' AND subject = ?")->execute([$now, $now, $subject['key']]);
    $name = !empty($subject['guest']) ? 'guest ' . substr((string)$subject['key'], 2) : (string)($subject['user']['username'] ?? ('#' . (int)$subject['uid']));
    auditLog($db, 'antispam.refuse', [
        'actor'       => !empty($subject['guest']) ? ['type' => 'user', 'id' => null, 'name' => 'guest']
                                                   : ['type' => 'user', 'id' => (int)$subject['uid'], 'name' => (string)($subject['user']['username'] ?? '')],
        'target_type' => 'antispam',
        'target_id'   => (string)$subject['key'],
        'summary'     => 'anti-spam refused ' . $name . ' ' . $s['refused'] . ' times in a row (' . $ctx . ', ' . $kind . ')',
        'detail'      => ['context' => $ctx, 'subject' => (string)$subject['key'], 'refused' => $s['refused'], 'kind' => $kind,
                          'level' => $s['level'], 'top_hits' => $s['top_hits'], 'captcha' => (bool)$s['captcha']],
    ]);
}

/** The janitor's share: rows nobody touched for two days are forgotten. Returns how many went. */
function antispamPrune(PDO $db): int
{
    try {
        $st = $db->prepare("DELETE FROM antispam_state WHERE updated_at < UNIX_TIMESTAMP() - ? LIMIT 5000");
        $st->execute([ANTISPAM_KEEP_SECONDS]);
        return $st->rowCount();
    } catch (\Throwable $e) {
        return 0;   // the table arrives with schema 85
    }
}

/**
 * What the Settings page and a composer say about the CAPTCHA half: is there a provider to ask at all.
 * Without one the layer paces and refuses, and nobody is asked to prove they are a person.
 */
function antispamCaptchaAvailable(array $cfg): bool
{
    return function_exists('captchaConfigured') && captchaConfigured($cfg);
}
