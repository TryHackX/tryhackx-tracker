<?php
/**
 * PUNISHMENTS, in one place (1.75.0, schema 94) — and the automatic penalties that follow them.
 *
 * Until 1.75.0 a warning, a silence and a ban were three things written in four places: the content report cards
 * (includes/reports.php), the message card (api/admin/message_report_action.php), the Users page's status box and, for
 * the warning alone, userWarn(). Each applied its own UPDATE, the lifts were copied between the two cards, a warning
 * was counted by a plain COUNT(*) in the statement after it was written (two moderators warning one member at the same
 * moment both read "warning number 2"), and a SILENT action left no trace at all — so nobody could find who was
 * silenced for what, nor lift a silence without a report card in front of them.
 *
 * ── the one path ────────────────────────────────────────────────────────────────────────────────────
 * userModerate() is every punishment and every lift:
 *
 *   BEGIN;  SELECT status, banned_until, pm_muted_until FROM users WHERE id = ? FOR UPDATE   (the FIRST statement: the
 *   REPEATABLE READ snapshot of the next plain read starts after the lock is held, so the count below sees what a
 *   concurrent moderator committed);  apply the action (userRestrict() / userLift());  write the `user_warnings` row —
 *   silent ones too, with `loud` 0;  count;  evaluate the automatic rules (moderationAutoEvaluate());  COMMIT;  and only
 *   then tell the member, sign the devices of a banned account out, and write the audit lines. Nothing that can fail
 *   late is done under the lock, and nothing is announced that was rolled back.
 *
 * ── what counts ─────────────────────────────────────────────────────────────────────────────────────
 * A WARNING, for the number the member is told, for the badge on the Users list and for the thresholds below, is a row
 * that is LOUD (the member was told), NOT REVOKED and NOT AUTOMATIC (MODERATION_COUNTED). Removing words, silencing and
 * banning are warnings when they are loud — say so where it is shown. A raw report never counts (a brigade of reports
 * must not be able to silence anybody), a silent action never counts (the member was never told), a revoked warning
 * never counts (it stays in the log, struck through), and an automatic penalty's own row never counts (no cascade).
 *
 * ── the automatic penalties (Part D) ────────────────────────────────────────────────────────────────
 * `auto_penalty_rules` is a ladder: "N counted warnings inside D days → silence / ban for X days (0: until lifted)". It
 * runs only when the setting `auto_penalties_enabled` is '1', inside userModerate() right after a loud row is written,
 * under the same row lock:
 *   - the strongest matching rule wins: a ban over a silence, then the one that ends later;
 *   - it is applied only if its end is LATER than what the member already has: it never shortens, never turns a ban into
 *     a silence. A rule that fires again with the next warning (the count is still at or above its threshold) extends
 *     from that moment — a member who keeps being warned keeps being silenced, which is the point of a ladder;
 *   - a ban with no end date (status banned, banned_until NULL: the owner's, from the Users page) is never touched; an
 *     active ban skips the silence rules; "until lifted" is the 2099 sentinel, never NULL; the dates are compared in PHP
 *     (GREATEST(NULL, x) is NULL);
 *   - staff are exempt, asked again here: whoever can open the panel by any group, an unverified moderator included
 *     (moderationIsStaff() ignores the verification gate that makes such an account a guest everywhere else);
 *   - the penalty is a row of its own (source_kind 'auto', source_id the rule, reason "<warnings>|<days>"), told to the
 *     member in a notification of its own, and written as user.autopenalty.
 * The dates come from PHP's clock: config/database.php aligns the database session's zone with it, so the column strings
 * and time() agree (the message card has always written its dates this way).
 */

const MODERATION_FOREVER   = '2099-12-31 23:59:59';   // "until somebody lifts it": a date far enough away to mean it, never NULL
const MODERATION_MAX_DAYS  = 3650;
const MODERATION_ACTIONS   = ['warn', 'remove', 'mute', 'ban', 'unmute', 'unban'];
const MODERATION_PAGE      = 40;                      // rows of the warnings log per request
const MODERATION_RESTRICTED_MAX = 1000;               // "Restricted now" lists at most this many members
const MODERATION_RULES_MAX = 20;
/** The SQL that says "this row is a warning that counts" (alias-free: used on a bare `user_warnings` and on `w`). */
const MODERATION_COUNTED   = "loud = 1 AND revoked_at IS NULL AND source_kind <> 'auto'";

/** A refusal: ['ok' => false, 'status', 'error' => the code] + extras. */
function moderationFail(int $status, string $code, array $extra = []): array
{
    return ['ok' => false, 'status' => $status, 'error' => $code] + $extra;
}

/**
 * Can this account open the panel through ANY group of theirs — the Admin group, or one that holds panel.access? Asked
 * of the memberships themselves, NOT of userIdHasPermission(): that one applies the e-mail verification gate, under
 * which an unverified moderator reads as a guest — right for what they may do on the site, wrong for "may a rule
 * silence them". Staff are untouchable here (reportAccountGuard() asks this too).
 */
function moderationIsStaff(PDO $db, int $userId): bool
{
    if ($userId <= 0) return false;
    foreach (userGroups($db, $userId) as $g) {
        if ((string)$g['slug'] === 'admin') return true;
        $p = userGroupPermissions((string)$g['permissions']);
        if (!empty($p['panel.access'])) return true;
    }
    return false;
}

/** The unix moment a stored DATETIME means (PHP's clock is the database session's), or null for none. */
function moderationTs($v): ?int
{
    if ($v === null || $v === '') return null;
    $t = strtotime((string)$v);
    return $t === false ? null : $t;
}

/** Is this stored end "until somebody lifts it"? (the sentinel's day; a 3650-day mute never reaches 2099 from a clock before 2089) */
function moderationIsForever($v): bool
{
    return $v !== null && substr((string)$v, 0, 4) >= '2099';
}

/* ── the two writes ───────────────────────────────────────────────────────────────────────────────────
 *
 * The ONLY places that set or clear users.pm_muted_until / banned_until for a punishment (the janitor's expiry in
 * userLiftExpiredPunishments() and the Users page's status box are the other two writers of those columns). The caller
 * holds the row lock; the side effects of a ban (devices signed out, permissions forgotten) are the caller's, after the
 * commit — moderationAfterBan().
 */

/** Silence ('mute') or ban an account until $endTs (a unix moment), or until lifted when null. Returns what was written. */
function userRestrict(PDO $db, int $userId, string $kind, ?int $endTs): string
{
    $val = $endTs === null ? MODERATION_FOREVER : date('Y-m-d H:i:s', $endTs);
    if ($kind === 'mute') {
        $db->prepare("UPDATE users SET pm_muted_until = ? WHERE id = ?")->execute([$val, $userId]);
    } else {
        $db->prepare("UPDATE users SET status = 'banned', banned_until = ? WHERE id = ?")->execute([$val, $userId]);
    }
    return $val;
}

/** End a silence ('mute') or a ban: the column back to NULL, the status back to active. */
function userLift(PDO $db, int $userId, string $kind): void
{
    if ($kind === 'mute') {
        $db->prepare("UPDATE users SET pm_muted_until = NULL WHERE id = ?")->execute([$userId]);
    } else {
        $db->prepare("UPDATE users SET status = 'active', banned_until = NULL WHERE id = ?")->execute([$userId]);
    }
}

/** A banned account's remembered devices end now and its permissions are read afresh (its sessions end on their next request). */
function moderationAfterBan(PDO $db, int $userId): void
{
    if (function_exists('userSignOutOthers')) userSignOutOthers($db, $userId, false);
    if (function_exists('userPermissionsForget')) userPermissionsForget($userId);
}

/**
 * What an account IS right now, from the locked row and the clock: ['ban' => active, 'ban_end' => unix|null,
 * 'dateless' => the owner's ban, 'mute' => active, 'mute_end' => unix|null]. A ban whose date has passed is not
 * in force (everything that reads the column compares it with the clock; the janitor only tidies it).
 */
function moderationState(array $row, int $now): array
{
    $banned = (string)($row['status'] ?? '') === 'banned';
    $banEnd = moderationTs($row['banned_until'] ?? null);
    $dateless = $banned && $banEnd === null;
    $muteEnd = moderationTs($row['pm_muted_until'] ?? null);
    return [
        'ban' => $banned && ($dateless || $banEnd > $now), 'ban_end' => $banEnd, 'dateless' => $dateless,
        'mute' => $muteEnd !== null && $muteEnd > $now, 'mute_end' => $muteEnd,
    ];
}

/** The unix moment "until lifted" stands for, to compare with. */
function moderationForeverTs(): int
{
    static $t = null;
    return $t ??= (int)strtotime(MODERATION_FOREVER);
}

/* ── the one path ─────────────────────────────────────────────────────────────────────────────────── */

/**
 * Warn, remove words, silence, ban — or lift a silence or a ban — with ONE row lock, ONE count and ONE place that
 * evaluates the automatic rules. $req:
 *   user_id        the account;
 *   action         warn | remove | mute | ban | unmute | unban (the last two are lifts and write no warning row);
 *   days           for mute / ban: 0 = until somebody lifts it (otherwise 1..3650);
 *   loud           true: the member is told (a warning with the reason; a lift says so) — false: silent (the row is
 *                  still written, `loud` 0, and never counts). 'warn' is always loud;
 *   reason, reason_format   what the member reads (required when loud, a warning's body) / for a lift an optional note;
 *   src            ['kind' => comment|description|shout|message|manual, 'id', 'hash', 'name'] — what it was about; manual
 *                  by default (a moderator's own decision, no report in front of them);
 *   may_dateless   the caller may lift a ban that has NO end date (panel.users.edit) — default no;
 *   by             ['id' => ?int, 'name' => string] the moderator; default auditActor().
 * Returns ['ok' => true, 'action', 'warning' => the row's id|null, 'count' => the member's counted warnings now, 'until'
 * => what was written for a silence / ban (the sentinel when until lifted), 'forever' => bool, 'noop' => a lift of nothing,
 * 'auto' => the automatic penalty applied, if any] or moderationFail(): unknown_action, no_author, target_is_staff,
 * target_is_you, reason_required, invalid_text (+ message, field), ban_is_permanent (409 on a ban over one; 403 on a lift
 * without may_dateless).
 *
 * A caller that is itself inside a transaction (a test) keeps it: the lock is taken, nothing is committed, and the
 * notifications follow at once.
 */
function userModerate(PDO $db, array $cfg, array $req): array
{
    $userId = (int)($req['user_id'] ?? 0);
    $action = is_string($req['action'] ?? null) ? (string)$req['action'] : '';
    if (!in_array($action, MODERATION_ACTIONS, true)) return moderationFail(400, 'unknown_action');
    $lift = $action === 'unmute' || $action === 'unban';
    $loud = $action === 'warn' || !empty($req['loud']);
    $silentRemove = $action === 'remove' && !$loud;

    // Who may be acted on — asked before anything is touched. A silent removal of words changes nothing about the account
    // (only its log), so it needs an account and nothing more; a lift is never refused for the account being staff.
    if ($silentRemove) {
        if ($userId <= 0 || userFindById($db, $userId) === null) return moderationFail(400, 'no_author');
    } else {
        $why = reportAccountGuard($db, $cfg, $userId);
        if ($lift && $why === 'target_is_staff') $why = '';
        if ($why !== '') return moderationFail($why === 'no_author' ? 400 : 403, $why);
    }
    $user = userFindById($db, $userId);
    if ($user === null) return moderationFail(400, 'no_author');

    // The words the member reads: lines kept, a format of the site's, judged here so that "too long" is said, not cut off.
    $reason = reportTextClean($req['reason'] ?? '', REPORT_TEXT_MAX + 1);
    $fmt = reportTextFormat($req['reason_format'] ?? 'plain', $cfg);
    if (($msg = reportTextValidate($reason, $fmt, $cfg, REPORT_TEXT_MAX, true)) !== null) return moderationFail(400, 'invalid_text', ['message' => $msg, 'field' => 'reason']);
    if ($reason === '') $fmt = 'plain';
    if (!$lift && $loud && $reason === '') return moderationFail(400, 'reason_required');
    $days = max(0, min(MODERATION_MAX_DAYS, (int)($req['days'] ?? 0)));

    $src = is_array($req['src'] ?? null) ? $req['src'] : [];
    $kind = in_array($src['kind'] ?? '', ['comment', 'description', 'shout', 'image', 'message', 'manual'], true) ? (string)$src['kind'] : 'manual';
    $hash = preg_match('/^[0-9a-f]{40}$/', (string)($src['hash'] ?? '')) ? (string)$src['hash'] : '';
    $srcId = max(0, (int)($src['id'] ?? 0));
    $name = (string)($src['name'] ?? '');
    $by = is_array($req['by'] ?? null) ? $req['by'] : (function_exists('auditActor') ? auditActor($db) : ['id' => null, 'name' => 'panel']);
    $byId = !empty($by['id']) ? (int)$by['id'] : null;
    $byName = mb_substr((string)($by['name'] ?? ''), 0, 64);
    $mayDateless = !empty($req['may_dateless']);

    $now = time();
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    $out = ['ok' => true, 'status' => 200, 'action' => $action, 'warning' => null, 'count' => null, 'until' => null, 'forever' => false,
            'noop' => false, 'auto' => null];
    $banApplied = false;
    $auto = null;
    $autoWarning = 0;
    try {
        // THE LOCK, as the first statement of the transaction (see the head of this file).
        $lk = $db->prepare("SELECT status, banned_until, pm_muted_until FROM users WHERE id = ? FOR UPDATE");
        $lk->execute([$userId]);
        $row = $lk->fetch(PDO::FETCH_ASSOC);
        if (!$row) { if ($own) $db->rollBack(); return moderationFail(400, 'no_author'); }
        $st = moderationState($row, $now);

        if ($action === 'mute' || $action === 'ban') {
            if ($action === 'ban' && $st['dateless']) { if ($own) $db->rollBack(); return moderationFail(409, 'ban_is_permanent'); }
            $end = $days > 0 ? $now + $days * 86400 : null;
            $out['until'] = userRestrict($db, $userId, $action, $end);
            $out['forever'] = $end === null;
            $endTs = $end ?? moderationForeverTs();
            if ($action === 'mute') { $st['mute'] = true; $st['mute_end'] = $endTs; }
            else { $st['ban'] = true; $st['ban_end'] = $endTs; $st['dateless'] = false; $banApplied = true; }
        } elseif ($lift) {
            if ($action === 'unban') {
                if ($st['dateless'] && !$mayDateless) { if ($own) $db->rollBack(); return moderationFail(403, 'ban_is_permanent'); }
                if ((string)$row['status'] === 'banned') userLift($db, $userId, 'ban'); else $out['noop'] = true;
            } else {
                if ($row['pm_muted_until'] !== null) userLift($db, $userId, 'mute'); else $out['noop'] = true;
            }
        }

        if (!$lift) {
            $ins = $db->prepare("INSERT INTO user_warnings (user_id, reason, reason_format, source_kind, source_id, info_hash, action, loud, by_id, by_name)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$userId, $reason, $fmt, $kind, $srcId, $hash, $action, $loud ? 1 : 0, $byId, $byName]);
            $out['warning'] = (int)$db->lastInsertId();
            if (function_exists('imagesRefsTouch') && str_contains($reason, '/i/')) imagesRefsTouch($db, $cfg, 'warn', (string)$out['warning']);
            // The count AFTER the lock: the first plain read from here on is the transaction's snapshot.
            $out['count'] = moderationCount($db, $userId);
            if ($loud && ($cfg['auto_penalties_enabled'] ?? '0') === '1') {
                $auto = moderationAutoEvaluate($db, $userId, $st, $now);
                if ($auto !== null) {
                    $autoWarning = $auto['warning'];
                    if ($auto['action'] === 'ban') $banApplied = true;
                }
            }
        }
        if ($own) $db->commit();
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }

    // ── after the commit ────────────────────────────────────────────────────────────────────────────────
    if ($banApplied) moderationAfterBan($db, $userId);
    elseif ($lift) userPermissionsForget($userId);
    if ($auto !== null) $out['auto'] = $auto;

    $untilTs = ($action === 'mute' || $action === 'ban') ? ($out['forever'] ? null : $now + $days * 86400) : null;
    if ($lift) {
        if (!$out['noop'] && $loud) moderationNoticeLift($db, $cfg, $user, $action === 'unmute' ? 'mute' : 'ban', $reason, $fmt);
        if (function_exists('auditLog')) {
            $ex = reportTextAudit($reason, 80);
            auditLog($db, 'user.lift', ['target_type' => 'user', 'target_id' => $userId,
                'summary' => $byName . ' → lifted the ' . ($action === 'unmute' ? 'silence' : 'ban') . ' of ' . (string)$user['username'] . ($loud ? ' (told)' : ' (silently)'),
                'detail' => ['what' => $action === 'unmute' ? 'mute' : 'ban', 'loud' => $loud, 'noop' => $out['noop'], 'note' => $ex, 'note_format' => $reason !== '' ? $fmt : null]]);
        }
        return $out;
    }
    if ($loud) {
        moderationNoticeWarning($db, $cfg, $user, ['action' => $action, 'kind' => $kind, 'id' => $srcId, 'hash' => $hash, 'name' => $name,
                                'reason' => $reason, 'fmt' => $fmt, 'until' => $untilTs, 'n' => (int)$out['count']]);
    }
    if (function_exists('auditLog')) {
        // An excerpt and the length, not the whole reason: a log line's detail is cut at 4 000 bytes (AUDIT_MAX_DETAIL).
        $ex = reportTextAudit($reason, 80);
        auditLog($db, 'user.warn', ['target_type' => 'user', 'target_id' => $userId,
            'summary' => $byName . ' → ' . ($loud ? 'warned ' : 'silently recorded for ') . (string)$user['username'] . ' (' . $action . ', ' . $kind . ')'
                         . ($ex !== null ? ': ' . $ex['excerpt'] . ($ex['len'] > 80 ? '…' : '') : ''),
            'detail' => ['warning' => $out['warning'], 'count' => $out['count'], 'kind' => $kind, 'id' => $srcId, 'hash' => $hash, 'action' => $action,
                         'loud' => $loud, 'days' => $days, 'until' => $untilTs, 'reason_format' => $reason !== '' ? $fmt : null, 'reason' => $ex]]);
    }
    if ($auto !== null) {
        $user2 = userFindById($db, $userId) ?? $user;
        moderationNoticeAuto($db, $cfg, $user2, $auto);
        if (function_exists('auditLog')) {
            auditLog($db, 'user.autopenalty', ['target_type' => 'user', 'target_id' => $userId,
                'summary' => 'automatic: ' . $auto['count'] . ' warnings in ' . $auto['window'] . ' days → ' . ($auto['action'] === 'ban' ? 'banned ' : 'silenced ')
                             . (string)$user['username'] . ' ' . ($auto['until'] === null ? 'until lifted' : 'until ' . date('Y-m-d H:i', (int)$auto['until'])),
                'detail' => ['rule' => $auto['rule'], 'warning' => $autoWarning, 'trigger' => $out['warning'], 'count' => $auto['count'], 'window_days' => $auto['window'],
                             'action' => $auto['action'], 'duration_days' => $auto['duration'], 'until' => $auto['until']]]);
        }
    }
    return $out;
}

/** The member's counted warnings (MODERATION_COUNTED). */
function moderationCount(PDO $db, int $userId): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM user_warnings WHERE user_id = ? AND " . MODERATION_COUNTED);
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/* ── the automatic penalties ──────────────────────────────────────────────────────────────────────── */

/** The ladder: every rule, or only the enabled ones, in their order. A database without the table has none. */
function moderationRules(PDO $db, bool $onlyEnabled = false): array
{
    try {
        // (one of two literal statements: tests/sql_safety_test.php reads the name `$sql`)
        $sql = $onlyEnabled
            ? "SELECT id, warnings, window_days, action, duration_days, enabled, sort FROM auto_penalty_rules WHERE enabled = 1 ORDER BY sort, id"
            : "SELECT id, warnings, window_days, action, duration_days, enabled, sort FROM auto_penalty_rules ORDER BY sort, id";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        foreach (['id', 'warnings', 'window_days', 'duration_days', 'enabled', 'sort'] as $k) $r[$k] = (int)$r[$k];
        $r['action'] = (string)$r['action'];
    }
    unset($r);
    return $rows;
}

/**
 * The ladder, applied (see the head of this file). Called under userModerate()'s row lock, after a loud row was written,
 * with the state it holds ($st: moderationState(), already carrying the action just applied). Returns what it did —
 * ['rule', 'action', 'count', 'window', 'duration', 'until' => unix|null, 'warning' => the penalty's row] — or null:
 * nothing matched, the account is staff, or nothing it could do was LATER than what the account already has.
 */
function moderationAutoEvaluate(PDO $db, int $userId, array $st, int $now): ?array
{
    $rules = moderationRules($db, true);
    if (!$rules) return null;
    if ($st['dateless']) return null;                       // the owner's ban is nobody's to extend or replace
    if (moderationIsStaff($db, $userId)) return null;       // staff are exempt — asked again, unverified moderators included
    $forever = moderationForeverTs();
    $count = $db->prepare("SELECT COUNT(*) FROM user_warnings WHERE user_id = ? AND " . MODERATION_COUNTED . " AND created_at >= ?");
    $best = null;
    foreach ($rules as $r) {
        $count->execute([$userId, date('Y-m-d H:i:s', $now - $r['window_days'] * 86400)]);
        $n = (int)$count->fetchColumn();
        if ($n < $r['warnings']) continue;
        $end = $r['duration_days'] > 0 ? $now + $r['duration_days'] * 86400 : $forever;
        $rank = $r['action'] === 'ban' ? 2 : 1;
        if ($best === null || $rank > $best['rank'] || ($rank === $best['rank'] && $end > $best['end'])) {
            $best = ['rule' => $r, 'rank' => $rank, 'end' => $end, 'count' => $n];
        }
    }
    if ($best === null) return null;
    $rule = $best['rule'];
    if ($rule['action'] === 'mute') {
        if ($st['ban']) return null;                                                 // an active ban skips the silence rules
        if ($best['end'] <= ($st['mute'] ? (int)$st['mute_end'] : 0)) return null;   // never shortens
    } else {
        if ($best['end'] <= ($st['ban'] ? (int)$st['ban_end'] : 0)) return null;
    }
    $endTs = $best['end'] >= $forever ? null : $best['end'];
    userRestrict($db, $userId, $rule['action'], $endTs);
    $db->prepare("INSERT INTO user_warnings (user_id, reason, reason_format, source_kind, source_id, info_hash, action, loud, by_id, by_name)
                  VALUES (?, ?, 'plain', 'auto', ?, '', ?, 1, NULL, '')")
       ->execute([$userId, $best['count'] . '|' . $rule['window_days'], $rule['id'], $rule['action']]);
    return ['rule' => $rule['id'], 'action' => $rule['action'], 'count' => $best['count'], 'window' => $rule['window_days'],
            'duration' => $rule['duration_days'], 'until' => $endTs, 'warning' => (int)$db->lastInsertId()];
}

/**
 * Check a rule list from the editor: [rows or an error sentence-code]. Each row: warnings 1–100, window_days 1–365,
 * action mute|ban, duration_days 0–365 (0: until lifted), enabled; at most MODERATION_RULES_MAX rules. Returns
 * ['rows' => cleaned] or ['error' => code, 'row' => index].
 */
function moderationRulesClean($rows): array
{
    if (!is_array($rows)) return ['error' => 'rules_invalid', 'row' => -1];
    $rows = array_values($rows);
    if (count($rows) > MODERATION_RULES_MAX) return ['error' => 'rules_too_many', 'row' => -1];
    $out = [];
    foreach ($rows as $i => $r) {
        if (!is_array($r)) return ['error' => 'rules_invalid', 'row' => $i];
        $int = static fn($v): ?int => (is_int($v) || (is_string($v) && preg_match('/^\d{1,6}$/', trim($v)))) ? (int)$v : null;
        $w = $int($r['warnings'] ?? null); $d = $int($r['window_days'] ?? null); $dur = $int($r['duration_days'] ?? null);
        $action = (string)($r['action'] ?? '');
        if ($w === null || $w < 1 || $w > 100) return ['error' => 'rule_warnings', 'row' => $i];
        if ($d === null || $d < 1 || $d > 365) return ['error' => 'rule_window', 'row' => $i];
        if (!in_array($action, ['mute', 'ban'], true)) return ['error' => 'rule_action', 'row' => $i];
        if ($dur === null || $dur < 0 || $dur > 365) return ['error' => 'rule_duration', 'row' => $i];
        $id = $int($r['id'] ?? null);
        $out[] = ['id' => $id !== null && $id > 0 ? $id : 0, 'warnings' => $w, 'window_days' => $d, 'action' => $action, 'duration_days' => $dur,
                  'enabled' => empty($r['enabled']) || (string)$r['enabled'] === '0' ? 0 : 1];
    }
    return ['rows' => $out];
}

/**
 * Save the ladder as the editor sent it (already moderationRulesClean()-ed): rules with an id are updated in place (their
 * penalties in the log keep pointing at them), new ones inserted, the ones left out deleted; the order is the order given.
 * $enabled (null: leave) is the switch. Returns ['rules' => the saved list, 'enabled' => '0'|'1'].
 */
function moderationRulesSave(PDO $db, array $rows, ?bool $enabled): array
{
    $db->beginTransaction();
    try {
        $keep = [];
        $upd = $db->prepare("UPDATE auto_penalty_rules SET warnings = ?, window_days = ?, action = ?, duration_days = ?, enabled = ?, sort = ? WHERE id = ?");
        $ins = $db->prepare("INSERT INTO auto_penalty_rules (warnings, window_days, action, duration_days, enabled, sort) VALUES (?, ?, ?, ?, ?, ?)");
        $existing = array_map('intval', $db->query("SELECT id FROM auto_penalty_rules")->fetchAll(PDO::FETCH_COLUMN));
        foreach ($rows as $i => $r) {
            $sort = ($i + 1) * 10;
            if ($r['id'] > 0 && in_array($r['id'], $existing, true)) {
                $upd->execute([$r['warnings'], $r['window_days'], $r['action'], $r['duration_days'], $r['enabled'], $sort, $r['id']]);
                $keep[] = $r['id'];
            } else {
                $ins->execute([$r['warnings'], $r['window_days'], $r['action'], $r['duration_days'], $r['enabled'], $sort]);
                $keep[] = (int)$db->lastInsertId();
            }
        }
        $gone = array_values(array_diff($existing, $keep));
        if ($gone) {
            $in = implode(',', array_fill(0, count($gone), '?'));
            $db->prepare("DELETE FROM auto_penalty_rules WHERE id IN ($in)")->execute($gone);
        }
        if ($enabled !== null) {
            $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('auto_penalties_enabled', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
               ->execute([$enabled ? '1' : '0']);
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    if (function_exists('getSettings')) getSettings($db, true);
    return ['rules' => moderationRules($db), 'enabled' => $enabled === null ? null : ($enabled ? '1' : '0')];
}

/* ── revoking a warning ───────────────────────────────────────────────────────────────────────────── */

/**
 * REVOKE a warning (or any row of the log): it stays, struck through, and counts nowhere any more. $opts: note + note_format
 * (what the moderator writes — kept, and told to the member when `tell`), tell (the member is told, when the row was a
 * loud one), lift_current (also lift the silence and the ban that run now — a ban with no end date only with may_dateless),
 * may_dateless. Revoking never lifts anything by itself and never re-evaluates the automatic rules. The same row lock as
 * userModerate(), so a count read at the same moment is of one state or the other. Returns ['ok' => true, 'user_id',
 * 'told', 'lifted' => ['mute' => bool, 'ban' => bool], 'lift_skipped' => ?code] or moderationFail(): not_found,
 * already_revoked, invalid_text.
 */
function userWarningRevoke(PDO $db, array $cfg, int $warningId, array $opts = []): array
{
    $note = reportTextClean($opts['note'] ?? '', REPORT_TEXT_MAX + 1);
    $fmt = reportTextFormat($opts['note_format'] ?? 'plain', $cfg);
    if (($msg = reportTextValidate($note, $fmt, $cfg)) !== null) return moderationFail(400, 'invalid_text', ['message' => $msg, 'field' => 'note']);
    if ($note === '') $fmt = 'plain';
    $tell = !empty($opts['tell']);
    $liftCurrent = !empty($opts['lift_current']);
    $mayDateless = !empty($opts['may_dateless']);

    $q = $db->prepare("SELECT id, user_id, action, loud, source_kind, created_at FROM user_warnings WHERE id = ?");
    $q->execute([$warningId]);
    $w = $q->fetch(PDO::FETCH_ASSOC);
    if (!$w) return moderationFail(404, 'not_found');
    $userId = (int)$w['user_id'];
    $user = userFindById($db, $userId);
    if ($user === null) return moderationFail(404, 'not_found');
    $by = function_exists('auditActor') ? auditActor($db) : ['id' => null, 'name' => 'panel'];
    $byName = mb_substr((string)($by['name'] ?? ''), 0, 64);
    $now = time();

    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    $lifted = ['mute' => false, 'ban' => false];
    $skipped = null;
    try {
        $lk = $db->prepare("SELECT status, banned_until, pm_muted_until FROM users WHERE id = ? FOR UPDATE");
        $lk->execute([$userId]);
        $row = $lk->fetch(PDO::FETCH_ASSOC);
        if (!$row) { if ($own) $db->rollBack(); return moderationFail(404, 'not_found'); }
        $up = $db->prepare("UPDATE user_warnings SET revoked_at = NOW(), revoked_by_id = ?, revoked_by_name = ?, revoke_note = ?, revoke_note_format = ?
                             WHERE id = ? AND revoked_at IS NULL");
        $up->execute([!empty($by['id']) ? (int)$by['id'] : null, $byName, $note !== '' ? $note : null, $fmt, $warningId]);
        if ($up->rowCount() !== 1) { if ($own) $db->rollBack(); return moderationFail(409, 'already_revoked'); }
        if ($liftCurrent) {
            $st = moderationState($row, $now);
            if ($st['mute']) { userLift($db, $userId, 'mute'); $lifted['mute'] = true; }
            if ($st['ban']) {
                if ($st['dateless'] && !$mayDateless) $skipped = 'ban_is_permanent';
                else { userLift($db, $userId, 'ban'); $lifted['ban'] = true; }
            }
        }
        if ($own) $db->commit();
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
    if ($lifted['ban'] || $lifted['mute']) userPermissionsForget($userId);

    $told = $tell && (int)$w['loud'] === 1;
    if ($told) moderationNoticeRevoked($db, $cfg, $user, strtotime((string)$w['created_at']) ?: $now, $note, $fmt);
    if ($told) {
        foreach (['mute', 'ban'] as $k) if ($lifted[$k]) moderationNoticeLift($db, $cfg, $user, $k, '', 'plain');
    }
    if (function_exists('auditLog')) {
        $ex = reportTextAudit($note, 80);
        auditLog($db, 'warning.revoke', ['target_type' => 'user', 'target_id' => $userId,
            'summary' => $byName . ' → revoked warning #' . $warningId . ' of ' . (string)$user['username'] . ' (' . (string)$w['action'] . ')'
                         . ($ex !== null ? ': ' . $ex['excerpt'] : ''),
            'detail' => ['warning' => $warningId, 'action' => (string)$w['action'], 'kind' => (string)$w['source_kind'], 'told' => $told, 'lifted' => $lifted,
                         'lift_skipped' => $skipped, 'note' => $ex, 'note_format' => $note !== '' ? $fmt : null]]);
        foreach (['mute', 'ban'] as $k) if ($lifted[$k]) {
            auditLog($db, 'user.lift', ['target_type' => 'user', 'target_id' => $userId,
                'summary' => $byName . ' → lifted the ' . ($k === 'mute' ? 'silence' : 'ban') . ' of ' . (string)$user['username'] . ' (with the revoked warning #' . $warningId . ')',
                'detail' => ['what' => $k, 'loud' => $told, 'with_revoke' => $warningId]]);
        }
    }
    return ['ok' => true, 'status' => 200, 'user_id' => $userId, 'told' => $told, 'lifted' => $lifted, 'lift_skipped' => $skipped];
}

/* ── what the member is told ──────────────────────────────────────────────────────────────────────── */

/** "3 warnings", "30 days" in a language, by that language's plural rule (notify.auto_*_one / _few / _many). */
function moderationCounted(string $lang, string $family, int $n): string
{
    return plural($n, $family, [], $lang);
}

/**
 * The WARNING notification (type 'warning', in the member's language): what happened — by the action and what it was
 * about —, the moderator's reason and which warning this is on the account. $w: action, kind, id, hash, name, reason,
 * fmt, until (unix|null for until lifted), n. A plain reason is in the body; a BBCode / Markdown one travels as `rich`
 * (the body is then only the lead-ins, so nothing of it is ever escaped into markup) — api/user_notifications.php draws it.
 */
function moderationNoticeWarning(PDO $db, array $cfg, array $user, array $w): void
{
    $lang = recipientLang($cfg, $user);
    $action = (string)$w['action'];
    $kind = (string)$w['kind'];
    if ($action === 'mute' || $action === 'ban') {
        if ($w['until'] === null) {
            $title = langFor($lang, $action === 'mute' ? 'notify.warn_muted_forever' : 'notify.warn_banned_forever');
        } else {
            $date = function_exists('userDisplayTime') ? userDisplayTime((int)$w['until'], userDisplayTimezone($user, $cfg), 'Y-m-d H:i') : date('Y-m-d H:i', (int)$w['until']);
            $title = langFor($lang, $action === 'mute' ? 'notify.warn_muted' : 'notify.warn_banned', ['date' => $date]);
        }
    } else {
        $what = in_array($kind, ['comment', 'description', 'shout', 'image', 'message', 'manual'], true) ? $kind : 'manual';
        if ($action === 'remove' && $what === 'manual') $what = 'message';   // (no words were removed by hand: never reached)
        $title = langFor($lang, ($action === 'remove' ? 'notify.warn_removed_' : 'notify.warn_about_') . $what, ['name' => (string)$w['name']]);
    }
    $reason = (string)$w['reason'];
    $fmt = (string)$w['fmt'];
    if ($fmt === 'plain') {
        $body = langFor($lang, 'notify.warn_reason', ['reason' => $reason])
              . ($action === 'mute' ? "\n" . langFor($lang, 'notify.warn_muted_what') : '')
              . "\n" . langFor($lang, 'notify.warn_count', ['n' => (int)$w['n']]);
        $rich = null;
    } else {
        // The lead-ins in the order they are READ; the account page draws the formatted reason right under the body.
        $body = ($action === 'mute' ? langFor($lang, 'notify.warn_muted_what') . "\n" : '')
              . langFor($lang, 'notify.warn_count', ['n' => (int)$w['n']]) . "\n" . langFor($lang, 'notify.warn_reason_lead');
        $rich = $reason;
    }
    // Where it happened, when that is a place on the site — the torrent's Info panel. A removed comment's link would find
    // nothing, so it is the torrent's.
    $link = null;
    $hash = (string)$w['hash'];
    if ($hash !== '' && ($kind === 'comment' || $kind === 'description')) {
        $link = ($kind === 'comment' && $action !== 'remove' && (int)$w['id'] > 0 && function_exists('commentLink'))
            ? commentLink($hash, (int)$w['id']) : '?action=search&hash=' . $hash;
    }
    userNotify($db, (int)$user['id'], 'warning', $title, $body, $link, null, $rich, $rich !== null ? $fmt : null);
}

/** The notification of an AUTOMATIC penalty (its own text: "N warnings in D days → silenced until X"), with a link to the account's standing. */
function moderationNoticeAuto(PDO $db, array $cfg, array $user, array $a): void
{
    $lang = recipientLang($cfg, $user);
    $vars = ['warnings' => moderationCounted($lang, 'notify.auto_warnings', (int)$a['count']),
             'window' => moderationCounted($lang, 'notify.auto_window', (int)$a['window'])];
    if ($a['until'] === null) {
        $title = langFor($lang, $a['action'] === 'ban' ? 'notify.auto_banned_forever' : 'notify.auto_muted_forever', $vars);
    } else {
        $vars['date'] = function_exists('userDisplayTime') ? userDisplayTime((int)$a['until'], userDisplayTimezone($user, $cfg), 'Y-m-d H:i') : date('Y-m-d H:i', (int)$a['until']);
        $title = langFor($lang, $a['action'] === 'ban' ? 'notify.auto_banned' : 'notify.auto_muted', $vars);
    }
    $body = langFor($lang, 'notify.auto_body') . ($a['action'] === 'mute' ? "\n" . langFor($lang, 'notify.warn_muted_what') : '');
    userNotify($db, (int)$user['id'], 'warning', $title, $body, '?action=account#acc-card-standing');
}

/** Good news, said plainly: a silence or a ban lifted — with the moderator's note under it when there is one. */
function moderationNoticeLift(PDO $db, array $cfg, array $user, string $what, string $note, string $fmt): void
{
    $lang = recipientLang($cfg, $user);
    $title = langFor($lang, $what === 'mute' ? 'notify.unmuted' : 'notify.unbanned');
    $body = langFor($lang, $what === 'mute' ? 'notify.unmuted_body' : 'notify.unbanned_body');
    moderationNoticeWithNote($db, (int)$user['id'], 'account', $title, $body, $lang, $note, $fmt);
}

/** "A warning on your account was withdrawn" (the date of that warning in the member's zone), with the note. */
function moderationNoticeRevoked(PDO $db, array $cfg, array $user, int $warnedAt, string $note, string $fmt): void
{
    $lang = recipientLang($cfg, $user);
    $date = function_exists('userDisplayTime') ? userDisplayTime($warnedAt, userDisplayTimezone($user, $cfg), 'Y-m-d') : date('Y-m-d', $warnedAt);
    moderationNoticeWithNote($db, (int)$user['id'], 'account', langFor($lang, 'notify.warn_revoked'),
                             langFor($lang, 'notify.warn_revoked_body', ['date' => $date]), $lang, $note, $fmt);
}

/** A notification with an optional moderator's note under it: plain → in the body, BBCode / Markdown → `rich` below a lead-in. */
function moderationNoticeWithNote(PDO $db, int $userId, string $type, string $title, string $body, string $lang, string $note, string $fmt): void
{
    if ($note === '') { userNotify($db, $userId, $type, $title, $body); return; }
    if ($fmt === 'plain') {
        userNotify($db, $userId, $type, $title, $body . "\n" . langFor($lang, 'notify.report_reply', ['reply' => $note]));
        return;
    }
    userNotify($db, $userId, $type, $title, $body . "\n" . langFor($lang, 'notify.report_reply_lead'), null, null, $note, $fmt);
}

/* ── the Moderation page's reads ──────────────────────────────────────────────────────────────────── */

/**
 * Where a warning's subject lives on the site, as a site-relative address the panel opens in a new tab ('' for none: a
 * private message is nobody's to open, a manual or automatic row has no place).
 */
function moderationWhere(array $cfg, string $kind, int $id, string $hash): string
{
    if (!function_exists('contentReportWhere') || !in_array($kind, ['comment', 'description', 'shout'], true)) return '';
    return contentReportWhere($cfg, $kind, $id, $hash);
}

/** One `user_warnings` row (joined with its member's name) as the panel is sent it. */
function moderationRowOut(array $cfg, array $w, string $base): array
{
    $fmt = (string)($w['reason_format'] ?? 'plain');
    $kind = (string)$w['source_kind'];
    $auto = $kind === 'auto';
    $reason = (string)($w['reason'] ?? '');
    $o = [
        'id' => (int)$w['id'], 'user_id' => (int)$w['user_id'], 'user' => (string)($w['username'] ?? ''),
        'avatar' => ($w['username'] ?? '') !== '' && function_exists('userAvatarField') ? userAvatarField(['username' => $w['username'], 'avatar_sha' => $w['avatar_sha'] ?? null], 20, $base, $cfg) : '',
        'action' => (string)$w['action'], 'loud' => (int)$w['loud'] === 1, 'kind' => $kind, 'source_id' => (int)$w['source_id'],
        'where' => moderationWhere($cfg, $kind, (int)$w['source_id'], (string)$w['info_hash']),
        'by' => $auto ? '' : (string)$w['by_name'], 'at' => (string)$w['created_at'], 'auto' => $auto,
        'reason' => $auto ? '' : $reason, 'reason_format' => $fmt,
        'reason_html' => (!$auto && $fmt !== 'plain' && $reason !== '') ? reportTextHtml($reason, $fmt, $cfg) : '',
        'revoked' => $w['revoked_at'] !== null, 'revoked_at' => $w['revoked_at'] !== null ? (string)$w['revoked_at'] : null,
        'revoked_by' => (string)($w['revoked_by_name'] ?? ''), 'revoke_note' => (string)($w['revoke_note'] ?? ''),
        'revoke_note_format' => (string)($w['revoke_note_format'] ?? 'plain'),
        'revoke_note_html' => ($w['revoke_note'] ?? '') !== '' && (string)($w['revoke_note_format'] ?? 'plain') !== 'plain'
            ? reportTextHtml((string)$w['revoke_note'], (string)$w['revoke_note_format'], $cfg) : '',
    ];
    if ($auto) {
        [$n, $d] = array_pad(explode('|', $reason, 2), 2, '');
        $o['auto_info'] = ['rule' => (int)$w['source_id'], 'count' => (int)$n, 'window' => (int)$d];
    }
    return $o;
}

/** The warnings log's filters, cleaned: member, moderator (name prefixes), action, state (active|revoked), from, to, user (an id). */
function moderationFilters(array $q): array
{
    $date = static fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) ? $v : '';
    $action = (string)($q['action'] ?? '');
    $state = (string)($q['state'] ?? '');
    return [
        'member' => contentReportClean((string)($q['member'] ?? ''), 64),
        'moderator' => contentReportClean((string)($q['moderator'] ?? ''), 64),
        'action' => in_array($action, ['warn', 'remove', 'mute', 'ban', 'auto'], true) ? $action : '',
        'state' => in_array($state, ['active', 'revoked'], true) ? $state : '',
        'from' => $date($q['from'] ?? ''), 'to' => $date($q['to'] ?? ''),
        'user' => max(0, (int)($q['user'] ?? 0)),
    ];
}

/**
 * The warnings log, newest first, a page at a time: keyset on the id (`after` = the last id of the previous page), so
 * a deep page costs what the first costs. ['rows', 'next' => the cursor or null, 'total' => the filtered count].
 */
function moderationWarningsPage(PDO $db, array $cfg, array $f, int $after = 0, int $limit = MODERATION_PAGE): array
{
    $where = ['1 = 1'];
    $args = [];
    if ($f['member'] !== '') { $where[] = 'u.username LIKE ?'; $args[] = contentReportLike($f['member'], true); }
    if ($f['moderator'] !== '') { $where[] = 'w.by_name LIKE ?'; $args[] = contentReportLike($f['moderator'], true); }
    if ($f['action'] === 'auto') { $where[] = "w.source_kind = 'auto'"; }
    elseif ($f['action'] !== '') { $where[] = 'w.action = ?'; $args[] = $f['action']; }
    if ($f['state'] === 'active') $where[] = 'w.revoked_at IS NULL';
    if ($f['state'] === 'revoked') $where[] = 'w.revoked_at IS NOT NULL';
    if ($f['from'] !== '') { $where[] = 'w.created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'] !== '') { $where[] = 'w.created_at < ? + INTERVAL 1 DAY'; $args[] = $f['to'] . ' 00:00:00'; }
    if ($f['user'] > 0) { $where[] = 'w.user_id = ?'; $args[] = $f['user']; }
    // Literal fragments only; every value is in $args (tests/sql_safety_test.php reads the name `$joinClause`).
    $joinClause = "FROM user_warnings w LEFT JOIN users u ON u.id = w.user_id WHERE " . implode(' AND ', $where);
    $cnt = $db->prepare("SELECT COUNT(*) $joinClause");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();
    $limit = max(1, min(100, $limit));
    // The cursor is a number cast to int, not a value from the request as it came.
    $sql = "SELECT w.*, u.username, u.avatar_sha $joinClause" . ($after > 0 ? ' AND w.id < ' . (int)$after : '') . " ORDER BY w.id DESC LIMIT " . ($limit + 1);
    $st = $db->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $base = function_exists('getBaseUrl') ? getBaseUrl() : '';
    $out = array_map(fn($w) => moderationRowOut($cfg, $w, $base), $rows);
    // What each of these members is held back by NOW (the Revoke window offers to lift it): one read for the page.
    $uids = array_values(array_unique(array_map(fn($o) => (int)$o['user_id'], $out)));
    $held = [];
    if ($uids) {
        $in = implode(',', array_fill(0, count($uids), '?'));
        $q = $db->prepare("SELECT id, status, banned_until, pm_muted_until FROM users WHERE id IN ($in)");
        $q->execute($uids);
        $t = time();
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $s = moderationState($u, $t);
            if (!$s['mute'] && !$s['ban']) continue;
            $held[(int)$u['id']] = ['mute' => $s['mute'] ? (string)$u['pm_muted_until'] : null,
                                    'ban' => $s['ban'] ? ($s['dateless'] ? MODERATION_FOREVER : (string)$u['banned_until']) : null, 'dateless' => $s['dateless']];
        }
    }
    foreach ($out as &$o) $o['now'] = $held[$o['user_id']] ?? null;
    unset($o);
    return ['rows' => $out, 'next' => ($more && $out) ? $out[count($out) - 1]['id'] : null, 'total' => $total];
}

/**
 * Every member silenced or banned RIGHT NOW (a ban or a silence whose date has passed is not in force), newest first by
 * when the penalty was given, with what imposed it: the latest silence / ban row of the log — who, the reason rendered,
 * whether it was automatic. At most MODERATION_RESTRICTED_MAX; 'more' says it was cut.
 */
function moderationRestricted(PDO $db, array $cfg): array
{
    $st = $db->query("SELECT id, username, status, banned_until, pm_muted_until, avatar_sha FROM users
                       WHERE (status = 'banned' AND (banned_until IS NULL OR banned_until > NOW()))
                          OR (pm_muted_until IS NOT NULL AND pm_muted_until > NOW())
                       ORDER BY id DESC LIMIT " . (MODERATION_RESTRICTED_MAX + 1));
    $users = $st->fetchAll(PDO::FETCH_ASSOC);
    $more = count($users) > MODERATION_RESTRICTED_MAX;
    $users = array_slice($users, 0, MODERATION_RESTRICTED_MAX);
    if (!$users) return ['rows' => [], 'more' => false];
    $ids = array_map(fn($u) => (int)$u['id'], $users);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $last = ['mute' => [], 'ban' => []];
    $q = $db->prepare("SELECT w.* FROM user_warnings w
                         JOIN (SELECT MAX(id) AS mid FROM user_warnings WHERE user_id IN ($in) AND action IN ('mute', 'ban') GROUP BY user_id, action) m ON m.mid = w.id");
    $q->execute($ids);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $w) $last[(string)$w['action']][(int)$w['user_id']] = $w;
    $counts = moderationCounts($db, $ids);
    $base = function_exists('getBaseUrl') ? getBaseUrl() : '';
    $now = time();
    $rows = [];
    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $state = moderationState($u, $now);
        $one = static function (string $kind, ?int $endTs, ?string $endStr, bool $dateless) use ($last, $uid, $cfg, $base): array {
            $w = $last[$kind][$uid] ?? null;
            return [
                'until' => $endStr, 'forever' => $endStr !== null && moderationIsForever($endStr), 'dateless' => $dateless,
                'warning' => $w === null ? null : moderationRowOut($cfg, $w + ['username' => '', 'avatar_sha' => null], $base),
            ];
        };
        $row = [
            'id' => $uid, 'name' => (string)$u['username'],
            'avatar' => function_exists('userAvatarField') ? userAvatarField(['username' => $u['username'], 'avatar_sha' => $u['avatar_sha']], 20, $base, $cfg) : '',
            'staff' => moderationIsStaff($db, $uid), 'warnings' => (int)($counts[$uid] ?? 0),
            'mute' => $state['mute'] ? $one('mute', $state['mute_end'], (string)$u['pm_muted_until'], false) : null,
            'ban' => $state['ban'] ? $one('ban', $state['ban_end'], $u['banned_until'] !== null ? (string)$u['banned_until'] : null, $state['dateless']) : null,
        ];
        $row['since'] = max(
            (string)($row['mute']['warning']['at'] ?? ''),
            (string)($row['ban']['warning']['at'] ?? ''));
        $rows[] = $row;
    }
    // Newest penalty first; the ones nothing in the log explains (the owner's dateless bans) after them, newest account first.
    usort($rows, static fn($a, $b) => strcmp($b['since'], $a['since']) ?: ($b['id'] <=> $a['id']));
    return ['rows' => $rows, 'more' => $more];
}

/** The counted warnings (MODERATION_COUNTED) of these accounts: [uid => n]. */
function moderationCounts(PDO $db, array $userIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($v) => $v > 0)));
    $out = array_fill_keys($ids, 0);
    if (!$ids) return $out;
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT user_id, COUNT(*) AS n FROM user_warnings WHERE user_id IN ($in) AND " . MODERATION_COUNTED . " GROUP BY user_id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['user_id']] = (int)$r['n'];
    } catch (\Throwable $e) { /* the table arrives with schema 84 */ }
    return $out;
}

/* ── the member's own standing (the account page) ─────────────────────────────────────────────────── */

/**
 * What the account page's "Account standing" card says — the member's OWN, so the one argument is the session's id and
 * nothing else may be passed: the silence or the ban that runs now with its end, and the warnings that count (loud, not
 * revoked) with their date and the reason rendered. No moderator's name, ever; a silent action and a revoked warning are
 * not shown (the member was never told of the first, and the second was withdrawn). Automatic penalties are shown with
 * the sentence the notification carried. [] when there is nothing to show. The page reads `user_warnings` through this
 * function only (tests/content_reports_test.php: no public page or endpoint names the table).
 * ['mute' => ?['until' => 'Y-m-d H:i'|null, 'forever'], 'ban' => the same, 'warnings' => [['at', 'action', 'kind',
 * 'html', 'auto' => ?['count', 'window', 'until']], …], 'count' => n]
 */
function accountStandingContext(PDO $db, array $cfg, int $userId): array
{
    if ($userId <= 0) return [];
    $user = userFindById($db, $userId);
    if ($user === null) return [];
    $tz = function_exists('userDisplayTimezone') ? userDisplayTimezone($user, $cfg) : null;
    $when = static fn(int $ts): string => $tz !== null && function_exists('userDisplayTime') ? userDisplayTime($ts, $tz, 'Y-m-d H:i') : date('Y-m-d H:i', $ts);
    $state = moderationState($user, time());
    $out = ['mute' => null, 'ban' => null, 'warnings' => [], 'count' => 0];
    if ($state['mute']) $out['mute'] = ['forever' => moderationIsForever($user['pm_muted_until']), 'until' => $when((int)$state['mute_end'])];
    if ($state['ban']) {
        $out['ban'] = ['forever' => $state['dateless'] || moderationIsForever($user['banned_until']), 'until' => $state['dateless'] ? null : $when((int)$state['ban_end'])];
    }
    try {
        $st = $db->prepare("SELECT id, reason, reason_format, source_kind, source_id, action, created_at FROM user_warnings
                             WHERE user_id = ? AND loud = 1 AND revoked_at IS NULL ORDER BY id DESC LIMIT 50");
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $auto = (string)$w['source_kind'] === 'auto';
            $item = ['at' => $when((int)strtotime((string)$w['created_at'])), 'action' => (string)$w['action'], 'kind' => (string)$w['source_kind'],
                     'html' => '', 'auto' => null];
            if ($auto) {
                [$n, $d] = array_pad(explode('|', (string)$w['reason'], 2), 2, '');
                $item['auto'] = ['count' => (int)$n, 'window' => (int)$d];
            } else {
                $item['html'] = reportTextHtml((string)$w['reason'], (string)$w['reason_format'], $cfg);
                $out['count']++;
            }
            $out['warnings'][] = $item;
        }
    } catch (\Throwable $e) { /* the table arrives with schema 84 */ }
    if ($out['mute'] === null && $out['ban'] === null && !$out['warnings']) return [];
    return $out;
}
