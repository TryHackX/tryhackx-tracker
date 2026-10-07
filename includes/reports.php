<?php
/**
 * Reports of what people write in public — a comment, a torrent's description, a shout — and WARNINGS
 * (v84, 1.71.0). The owner: "we have a decent system for reported messages; add reporting comments,
 * descriptions and even shouts: a tab for where each came from, a good filter, permissions for it; the
 * author told when an admin removes their comment; and a choice whether it is silent or loud (as a warning)".
 *
 * ── where they are kept ─────────────────────────────────────────────────────────────────────────
 * `content_reports`, beside `message_reports` and not instead of it (see the schema's note): a message report
 * is a privacy rule — the reported line and the one before it, nothing else — and these are public words.
 * ONE table for the three kinds, keyed by the TARGET (kind, target_id, info_hash): a comment's or a shout's id,
 * and the torrent a comment or a description belongs to. The words are kept AS REPORTED (`snapshot`): a shout
 * is pruned by retention, a comment can be corrected, a description replaced — the moderator reads what the
 * reporter read beside what is there now. One OPEN report per member per target is the database's rule
 * (`open_slot`); after it is closed the member may report the same thing again.
 *
 * ── the member's side ───────────────────────────────────────────────────────────────────────────
 * contentReportRequest() is the whole endpoint (api/content_report.php): the token, an account, the kind
 * switched on, `content.report`, a target THIS reader can see — the Info panel's own rule for a comment and a
 * description, `shout.view` for a shout —, not their own words, not already reported by them, the ONE limit
 * call (contentReportFloodCheck() — the site's one anti-spam layer, includes/antispam.php) and a reason.
 * The rows a page is drawn from carry `can_report` / `reported` (contentReportFlags(),
 * contentReportDescFlags()), so the reporter sees "Reported" afterwards, on every page, after a reload too.
 *
 * ── the moderator's side ────────────────────────────────────────────────────────────────────────
 * The Reports page's Comments, Descriptions and Shouts tabs — each drawn while its feature is on and the
 * session holds `panel.reports.<kind>.view` — list TARGETS, not reports: the words once, rendered by their own
 * renderer (commentRenderHtml(), richtextRenderIn(), shoutBodyHtml()), with all their reports and reasons,
 * where they live, and their author's state (banned, silenced, staff, reports so far, warnings so far and the
 * latest ones). contentReportActionRequest() is what `panel.reports.<kind>.handle` may do to one: close or
 * reopen, remove the words (a comment through commentDelete(), a description through contentDelete(), a shout
 * through shoutDelete() — each told not to tell its author itself), warn, silence or ban the author (the message
 * card's rules: never an account that can open the panel, never yourself, never a ban without a date).
 *
 * ── silent or loud ──────────────────────────────────────────────────────────────────────────────
 * Every action that reaches the AUTHOR carries the moderator's choice. Silent: the author is told nothing —
 * the words are simply gone, the silence simply applies. Loud: ONE notification of type 'warning', in the
 * author's own language, with the moderator's reason (required) and how many warnings the account has had, and
 * a row in `user_warnings`. A Warn on its own is loud by definition. Lifting a silence or a ban says so only
 * when loud, and is no warning.
 *
 * ── who hears what ──────────────────────────────────────────────────────────────────────────────
 * The reporters hear the outcome — closed, removed, handled — with the moderator's answer when there is one,
 * each once, in THEIR language. The author never learns who reported, and nobody learns the other reporters.
 * Every action writes an audit line (creport.*, and user.warn for a warning), besides the lines the removal
 * functions write themselves.
 */
require_once __DIR__ . '/antispam.php';

const CONTENT_REPORT_KINDS         = ['comment', 'description', 'shout'];
const CONTENT_REPORT_REASON_MAX    = 300;     // a member's reason
const CONTENT_REPORT_TEXT_MAX      = 500;     // a moderator's note, answer and reason (the columns' width)
const CONTENT_REPORT_RATE_PER_HOUR = 20;      // reports an account may file in an hour (contentReportFloodCheck())
const CONTENT_REPORT_SNAPSHOT_BYTES = 60000;  // the words kept with a report (a TEXT column holds 65 535 bytes)
const CONTENT_REPORT_WARNINGS_SHOWN = 3;      // the latest warnings on a card
const CONTENT_REPORT_FOREVER       = '2099-12-31 23:59:59';   // "until somebody lifts it", as the message card writes it
const CONTENT_REPORT_ACTIONS       = ['close', 'reopen', 'remove', 'warn', 'mute', 'unmute', 'ban', 'unban'];

/* ── the switches ─────────────────────────────────────────────────────────────────────────────────── */

/** Is this kind of report possible at all: accounts, and the feature the words belong to. */
function contentReportKindOn(array $cfg, string $kind): bool
{
    if (!function_exists('usersEnabled') || !usersEnabled($cfg)) return false;
    if ($kind === 'comment') return function_exists('commentsEnabled') && commentsEnabled($cfg);
    if ($kind === 'description') return function_exists('contentEnabled') && contentEnabled($cfg);
    if ($kind === 'shout') return function_exists('shoutEnabled') && shoutEnabled($cfg);
    return false;
}

/** The panel permission of one kind's queue: panel.reports.comments.view, …descriptions.handle, … */
function contentReportPerm(string $kind, string $what): string
{
    $plural = ['comment' => 'comments', 'description' => 'descriptions', 'shout' => 'shouts'][$kind] ?? 'none';
    return 'panel.reports.' . $plural . '.' . ($what === 'handle' ? 'handle' : 'view');
}

/** May this ACCOUNT report at all — `content.report`, asked of the account, never of a panel session. */
function contentReportMayReport(PDO $db, array $cfg, ?array $me): bool
{
    $uid = (int)($me['id'] ?? 0);
    return $uid > 0 && usersEnabled($cfg) && userIdHasPermission($db, $cfg, $uid, 'content.report');
}

/** A text somebody typed, as it is kept: control characters out, one line, trimmed, cut to $max characters. */
function contentReportClean($s, int $max): string
{
    if (!is_string($s)) return '';
    if (!mb_check_encoding($s, 'UTF-8')) $s = (string)mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
    $s = (string)preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s);   // no bidi tricks in a name for the panel
    $s = trim((string)preg_replace('/\s{2,}/u', ' ', $s));
    return mb_substr($s, 0, $max, 'UTF-8');
}

/** The words kept with a report, bounded by the column. */
function contentReportSnapshot(string $text): string
{
    return strlen($text) > CONTENT_REPORT_SNAPSHOT_BYTES ? rtrim(mb_strcut($text, 0, CONTENT_REPORT_SNAPSHOT_BYTES, 'UTF-8')) : $text;
}

/* ── the thing a member points at ─────────────────────────────────────────────────────────────────── */

/**
 * The target as THIS reader may see it, or null: ['kind', 'target_id', 'info_hash', 'author_id', 'text', 'format'].
 *   comment      a VISIBLE comment on a torrent the reader may open (the Info panel's rule, commentHashVisible())
 *                and read comments on (comment.view);
 *   description  the PUBLISHED words of a torrent the reader may open, of a home they may see (a registered
 *                torrent's only with whitelist.view, as the Info panel answers), with content.view; the text
 *                reported is the one published now;
 *   shout        a line in the room that is not deleted and not the SITE's own, for a reader with shout.view.
 */
function contentReportTargetFor(PDO $db, array $cfg, ?array $me, string $kind, int $id, string $hash): ?array
{
    $uid = (int)($me['id'] ?? 0);
    if ($uid <= 0) return null;
    if ($kind === 'comment') {
        if (!function_exists('commentRow') || $id <= 0) return null;
        $row = commentRow($db, $id);
        if ($row === null || (string)$row['status'] !== 'visible') return null;
        if (!commentHashVisible($db, $cfg, $me, (string)$row['info_hash']) || !commentCan($db, $cfg, $me, 'comment.view')) return null;
        return ['kind' => 'comment', 'target_id' => (int)$row['id'], 'info_hash' => (string)$row['info_hash'],
                'author_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'text' => (string)$row['body'], 'format' => (string)$row['body_format']];
    }
    if ($kind === 'description') {
        $hash = strtolower(trim($hash));
        if (!preg_match('/^[0-9a-f]{40}$/', $hash) || !function_exists('contentRecordFor')) return null;
        if (!function_exists('commentHashVisible') || !commentHashVisible($db, $cfg, $me, $hash)) return null;
        if (!userIdHasPermission($db, $cfg, $uid, 'content.view')) return null;
        $rec = contentRecordFor($db, $hash);
        if ($rec === null || !contentOccupied($rec) || ($rec['content_status'] ?? '') !== 'approved' || !empty($rec['banned'])) return null;
        // A registered torrent's words are that row's: invisible to a reader who may not see the row (api/index_info.php).
        if ($rec['kind'] === 'wl' && (!userIdHasPermission($db, $cfg, $uid, 'whitelist.view') || ($cfg['index_search_include_whitelist'] ?? '1') !== '1')) return null;
        $text = (string)($rec['description'] ?? '');
        $format = (string)($rec['description_format'] ?? 'bbcode');
        if ($text === '') { $text = (string)($rec['source_url'] ?? ''); $format = 'plain'; }
        return ['kind' => 'description', 'target_id' => 0, 'info_hash' => $hash,
                'author_id' => $rec['content_user_id'] !== null ? (int)$rec['content_user_id'] : null,
                'text' => $text, 'format' => $format];
    }
    if ($kind === 'shout') {
        if ($id <= 0 || !function_exists('shoutEnabled') || !shoutEnabled($cfg) || !userIdHasPermission($db, $cfg, $uid, 'shout.view')) return null;
        $st = $db->prepare("SELECT id, user_id, body, body_format, is_system FROM shouts WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !empty($row['is_system'])) return null;
        return ['kind' => 'shout', 'target_id' => (int)$row['id'], 'info_hash' => '',
                'author_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'text' => (string)$row['body'], 'format' => (string)$row['body_format']];
    }
    return null;
}

/** Has this member an OPEN report on this target? */
function contentReportOpenBy(PDO $db, string $kind, int $targetId, string $hash, int $uid): bool
{
    try {
        $st = $db->prepare("SELECT 1 FROM content_reports WHERE kind = ? AND target_id = ? AND info_hash = ? AND reporter_id = ? AND status = 'open' LIMIT 1");
        $st->execute([$kind, $targetId, $hash, $uid]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) {
        return false;   // the table arrives with schema 84
    }
}

/**
 * For a batch of comment or shout rows as the page is handed them: which ones THIS reader may report and which
 * they already reported (an open report of theirs). [id => ['can' => bool, 'reported' => bool]] — one query for
 * the batch. `can` is true on a reported row as well: the button is drawn, pressed. Rows: id, user_id, and
 * status (a comment) or is_system (a shout).
 */
function contentReportFlags(PDO $db, array $cfg, ?array $me, string $kind, array $rows): array
{
    $out = [];
    foreach ($rows as $r) $out[(int)($r['id'] ?? 0)] = ['can' => false, 'reported' => false];
    $uid = (int)($me['id'] ?? 0);
    if (!$rows || $uid <= 0 || !in_array($kind, ['comment', 'shout'], true) || !contentReportKindOn($cfg, $kind)
        || !contentReportMayReport($db, $cfg, $me)) return $out;
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $author = ($r['user_id'] ?? null) === null ? 0 : (int)$r['user_id'];
        if ($id <= 0 || $author === $uid) continue;
        if ($kind === 'comment' && (string)($r['status'] ?? 'visible') !== 'visible') continue;
        if ($kind === 'shout' && !empty($r['is_system'])) continue;
        $out[$id]['can'] = true;
        $ids[] = $id;
    }
    if (!$ids) return $out;
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT target_id FROM content_reports WHERE kind = ? AND reporter_id = ? AND status = 'open' AND target_id IN ($in)");
        $st->execute(array_merge([$kind, $uid], $ids));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) if (isset($out[(int)$t])) $out[(int)$t]['reported'] = true;
    } catch (\Throwable $e) { /* the table arrives with schema 84 */ }
    return $out;
}

/** The same for a torrent's description, as the Info panel draws it: ['can' => bool, 'reported' => bool]. */
function contentReportDescFlags(PDO $db, array $cfg, ?array $me, string $hash, ?array $rec): array
{
    $no = ['can' => false, 'reported' => false];
    $uid = (int)($me['id'] ?? 0);
    if ($uid <= 0 || $rec === null || !contentOccupied($rec) || ($rec['content_status'] ?? '') !== 'approved' || !empty($rec['banned'])) return $no;
    if (!contentReportKindOn($cfg, 'description') || !contentReportMayReport($db, $cfg, $me)) return $no;
    if (($rec['content_user_id'] ?? null) !== null && (int)$rec['content_user_id'] === $uid) return $no;
    return ['can' => true, 'reported' => contentReportOpenBy($db, 'description', 0, strtolower($hash), $uid)];
}

/**
 * THE ONE limit every report passes — the site's one anti-spam layer since 1.71.0 part F (includes/antispam.php,
 * context `report`: three free, then 30 s, 2 min, 5 min, 15 min; half an hour of quiet and it starts over; a
 * CAPTCHA for whoever keeps at the top), and then the plain hourly limit per account as before. Null when the
 * report may go ahead (its reservation in `$ticket`, recorded by the caller once the row is in, handed back when
 * the reason is refused), else ['status', 'error', 'body' => the layer's own answer] or ['status', 'error', …].
 * A message's report (api/user_messages.php, op report) passes the same context.
 */
function contentReportFloodCheck(PDO $db, array $cfg, ?array $me, string $ip, array $input = [], ?array &$ticket = null): ?array
{
    $ticket = null;
    $uid = (int)($me['id'] ?? 0);
    $t = antispamCheck($db, $cfg, 'report', antispamSubject($me, $ip), null, ['input' => $input]);
    if (!$t['ok']) return ['status' => (int)$t['status'], 'error' => (string)$t['body']['error'], 'body' => $t['body']];
    if (!rateLimitAllow('creport', 'u' . $uid, CONTENT_REPORT_RATE_PER_HOUR, 3600)) {
        antispamRelease($db, $t['ticket']);
        return ['status' => 429, 'error' => 'rate_limit', 'retry_after' => 3600];
    }
    $ticket = $t['ticket'];
    return null;
}

/** A refusal: the code, and the sentence in the reader's language. */
function contentReportFail(int $status, string $code, array $vars = [], array $extra = []): array
{
    return ['status' => $status, 'body' => ['success' => false, 'error' => $code, 'message' => __('api.creport.' . $code, $vars)] + $extra];
}

/**
 * POST content_report {csrf_token, kind: comment|description|shout, id (a comment's, a shout's) | hash (a
 * description's), reason} — the whole endpoint. The gates in the order a person asks them: is the request
 * real, is there an account, does the feature exist, may it report, is there such a thing FOR THIS READER, is
 * it somebody else's, have they reported it already (then it simply says so — the button shows "Reported"),
 * how fast are they reporting, and only then what they wrote.
 *   → 200 {success, reported: true, already: bool, message}
 */
function contentReportRequest(PDO $db, array $cfg, ?array $me, array $input, string $ip = ''): array
{
    if (empty($input['csrf_token']) || !is_string($input['csrf_token']) || !function_exists('verifyCsrfToken') || !verifyCsrfToken($input['csrf_token'])) {
        return ['status' => 403, 'body' => ['success' => false, 'error' => 'csrf', 'message' => __('api.csrf.invalid')]];
    }
    $kind = is_string($input['kind'] ?? null) ? (string)$input['kind'] : '';
    if (!in_array($kind, CONTENT_REPORT_KINDS, true)) return contentReportFail(400, 'bad_kind');
    $uid = (int)($me['id'] ?? 0);
    if ($uid <= 0 || !usersEnabled($cfg)) return contentReportFail(401, 'login');
    if (!contentReportKindOn($cfg, $kind)) return contentReportFail(404, 'disabled');
    if (!contentReportMayReport($db, $cfg, $me)) return contentReportFail(403, 'no_permission');
    $id = is_numeric($input['id'] ?? null) ? (int)$input['id'] : 0;
    $hash = is_string($input['hash'] ?? null) ? strtolower(trim((string)$input['hash'])) : '';
    $t = contentReportTargetFor($db, $cfg, $me, $kind, $id, $hash);
    if ($t === null) return contentReportFail(404, 'not_found');
    if ($t['author_id'] !== null && (int)$t['author_id'] === $uid) return contentReportFail(400, 'own');
    if (contentReportOpenBy($db, $kind, (int)$t['target_id'], (string)$t['info_hash'], $uid)) {
        return ['status' => 200, 'body' => ['success' => true, 'reported' => true, 'already' => true, 'message' => __('api.creport.already')]];
    }
    $ticket = null;
    $flood = contentReportFloodCheck($db, $cfg, $me, $ip, $input, $ticket);
    if ($flood !== null) {
        if (isset($flood['body']) && is_array($flood['body'])) return ['status' => (int)$flood['status'], 'body' => $flood['body']];
        $extra = $flood;
        unset($extra['status'], $extra['error']);
        return contentReportFail((int)$flood['status'], (string)$flood['error'], [], $extra);
    }
    $raw = is_string($input['reason'] ?? null) ? (string)$input['reason'] : '';
    $reason = contentReportClean($raw, CONTENT_REPORT_REASON_MAX + 1);
    if ($reason === '') { antispamRelease($db, $ticket); return contentReportFail(400, 'reason_required'); }
    if (mb_strlen($reason, 'UTF-8') > CONTENT_REPORT_REASON_MAX) {
        antispamRelease($db, $ticket);
        return contentReportFail(400, 'reason_too_long', ['max' => CONTENT_REPORT_REASON_MAX]);
    }
    try {
        $db->prepare("INSERT INTO content_reports (kind, target_id, info_hash, author_id, reporter_id, reason, snapshot, snapshot_format)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$kind, (int)$t['target_id'], (string)$t['info_hash'], $t['author_id'], $uid, $reason,
                      contentReportSnapshot((string)$t['text']), mb_substr((string)$t['format'], 0, 16)]);
    } catch (\PDOException $e) {
        antispamRelease($db, $ticket);
        // Two presses at once: the key held, and the first one is the report.
        if ($e->getCode() === '23000') {
            return ['status' => 200, 'body' => ['success' => true, 'reported' => true, 'already' => true, 'message' => __('api.creport.already')]];
        }
        return contentReportFail(500, 'failed');
    }
    $rid = (int)$db->lastInsertId();
    antispamRecord($db, $ticket);
    if (function_exists('auditLog')) {
        auditLog($db, 'creport.file', ['target_type' => $kind, 'target_id' => $kind === 'description' ? $t['info_hash'] : (string)$t['target_id'],
            'summary' => (string)($me['username'] ?? '#' . $uid) . ' → reported a ' . $kind . ($kind === 'shout' ? ' #' . $t['target_id'] : ' on ' . substr((string)$t['info_hash'], 0, 12)),
            'detail' => ['report' => $rid, 'kind' => $kind, 'target' => (int)$t['target_id'], 'hash' => (string)$t['info_hash'],
                         'author_id' => $t['author_id']]]);
    }
    return ['status' => 200, 'body' => ['success' => true, 'reported' => true, 'already' => false, 'message' => __('api.creport.reported')]];
}

/* ── languages, the actor, the author's account ───────────────────────────────────────────────────── *
 *
 * A notification is written in its RECIPIENT's language — recipientLang() (includes/users.php), the one helper
 * this file and includes/comments.php had a copy each of until 1.74.0.
 */

/** Who is acting in the panel: ['id' => the account, 0 for the owner's own session, 'username' => the name]. */
function reportPanelActor(PDO $db): array
{
    $a = function_exists('auditActor') ? auditActor($db) : ['id' => null, 'name' => 'panel'];
    return ['id' => (int)($a['id'] ?? 0), 'username' => (string)($a['name'] ?? 'panel')];
}

/**
 * May the panel act on this ACCOUNT ('' yes; else why not): 'no_author' (a guest, the site, a deleted account),
 * 'target_is_staff' (it can open the panel — that decision belongs on the Users page, where it is visible as what
 * it is), 'target_is_you'. The message card's rules, asked for every warning, silence and ban here.
 */
function reportAccountGuard(PDO $db, array $cfg, ?int $target): string
{
    if ($target === null || $target <= 0 || userFindById($db, $target) === null) return 'no_author';
    if (userIdHasPermission($db, $cfg, $target, 'panel.access')) return 'target_is_staff';
    if (!empty($_SESSION['admin_via_user']) && (int)$_SESSION['admin_via_user'] === $target) return 'target_is_you';
    return '';
}

/* ── warnings ─────────────────────────────────────────────────────────────────────────────────────── */

/**
 * Record a WARNING and tell its member — the loud half of every action (see the head of this file). ONE
 * notification of type 'warning', in the member's language: what happened (its title, by `$action` and the
 * kind), the moderator's reason and which warning this is on the account. Returns the warning's id.
 *
 * $src: ['kind' => comment|description|shout|message|'', 'id' => int, 'hash' => string, 'name' => the torrent's
 * name for the sentence]; $action: warn | remove | mute | ban; $until: for a silence or a ban, the unix moment it
 * ends (null: until somebody lifts it).
 */
function userWarn(PDO $db, array $cfg, int $userId, string $reason, array $src, string $action, ?int $until = null): int
{
    $user = userFindById($db, $userId);
    if ($user === null) return 0;
    $kind = in_array($src['kind'] ?? '', ['comment', 'description', 'shout', 'message'], true) ? (string)$src['kind'] : '';
    $hash = preg_match('/^[0-9a-f]{40}$/', (string)($src['hash'] ?? '')) ? (string)$src['hash'] : '';
    $by = function_exists('auditActor') ? auditActor($db) : ['id' => null, 'name' => 'panel'];
    $reason = contentReportClean($reason, CONTENT_REPORT_TEXT_MAX);
    $db->prepare("INSERT INTO user_warnings (user_id, reason, source_kind, source_id, info_hash, action, by_id, by_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([$userId, $reason, $kind, max(0, (int)($src['id'] ?? 0)), $hash, in_array($action, ['warn', 'remove', 'mute', 'ban'], true) ? $action : 'warn',
                  !empty($by['id']) ? (int)$by['id'] : null, mb_substr((string)($by['name'] ?? ''), 0, 64)]);
    $wid = (int)$db->lastInsertId();
    $st = $db->prepare("SELECT COUNT(*) FROM user_warnings WHERE user_id = ?");
    $st->execute([$userId]);
    $n = (int)$st->fetchColumn();

    $lang = recipientLang($cfg, $user);
    $name = (string)($src['name'] ?? '');
    if ($action === 'mute' || $action === 'ban') {
        if ($until === null) {
            $title = langFor($lang, $action === 'mute' ? 'notify.warn_muted_forever' : 'notify.warn_banned_forever');
        } else {
            $date = function_exists('userDisplayTime') ? userDisplayTime($until, userDisplayTimezone($user, $cfg), 'Y-m-d H:i') : date('Y-m-d H:i', $until);
            $title = langFor($lang, $action === 'mute' ? 'notify.warn_muted' : 'notify.warn_banned', ['date' => $date]);
        }
    } else {
        $what = $kind !== '' ? $kind : 'message';
        $title = langFor($lang, ($action === 'remove' ? 'notify.warn_removed_' : 'notify.warn_about_') . $what, ['name' => $name]);
    }
    $body = langFor($lang, 'notify.warn_reason', ['reason' => $reason])
          . ($action === 'mute' ? "\n" . langFor($lang, 'notify.warn_muted_what') : '')
          . "\n" . langFor($lang, 'notify.warn_count', ['n' => $n]);
    // Where it happened, when that is a place on the site — the torrent's Info panel. A removed comment's link
    // would find nothing, so it is the torrent's.
    $link = null;
    if ($hash !== '' && ($kind === 'comment' || $kind === 'description')) {
        $link = ($kind === 'comment' && $action !== 'remove' && (int)($src['id'] ?? 0) > 0 && function_exists('commentLink'))
            ? commentLink($hash, (int)$src['id']) : '?action=search&hash=' . $hash;
    }
    userNotify($db, $userId, 'warning', $title, $body, $link);
    if (function_exists('auditLog')) {
        auditLog($db, 'user.warn', ['target_type' => 'user', 'target_id' => $userId,
            'summary' => (string)($by['name'] ?? 'panel') . ' → warned ' . (string)$user['username'] . ' (' . $action . ($kind !== '' ? ', ' . $kind : '') . '): ' . $reason,
            'detail' => ['warning' => $wid, 'count' => $n, 'kind' => $kind, 'id' => (int)($src['id'] ?? 0), 'hash' => $hash, 'action' => $action, 'until' => $until]]);
    }
    return $wid;
}

/**
 * How many warnings each of these accounts has had, and the latest few: [uid => ['count' => n, 'latest' =>
 * [['at', 'reason', 'kind', 'action', 'by'], …]]] — for the report cards and Users. One query for the counts,
 * one small indexed one per account that has any.
 */
function userWarningsSummary(PDO $db, array $userIds, int $latest = CONTENT_REPORT_WARNINGS_SHOWN): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($v) => $v > 0)));
    $out = [];
    foreach ($ids as $id) $out[$id] = ['count' => 0, 'latest' => []];
    if (!$ids) return $out;
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT user_id, COUNT(*) AS n FROM user_warnings WHERE user_id IN ($in) GROUP BY user_id");
        $st->execute($ids);
        $lim = max(1, min(20, $latest));
        $one = $db->prepare("SELECT created_at, reason, source_kind, action, by_name FROM user_warnings WHERE user_id = ? ORDER BY id DESC LIMIT $lim");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['user_id'];
            $out[$uid]['count'] = (int)$r['n'];
            $one->execute([$uid]);
            foreach ($one->fetchAll(PDO::FETCH_ASSOC) as $w) {
                $out[$uid]['latest'][] = ['at' => (string)$w['created_at'], 'reason' => (string)$w['reason'], 'kind' => (string)$w['source_kind'],
                                          'action' => (string)$w['action'], 'by' => (string)$w['by_name']];
            }
        }
    } catch (\Throwable $e) { /* the table arrives with schema 84 */ }
    return $out;
}

/**
 * What these AUTHORS already are, for a report card: banned (and until when), silenced until, staff, whether the
 * panel session IS them, how many reports name them (messages and public words together), their warnings.
 * [uid => state]. Queries per page, not per card.
 */
function reportAuthorStates(PDO $db, array $cfg, array $userIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, username, status, pm_muted_until, banned_until, avatar_sha FROM users WHERE id IN ($in)");
    $st->execute($ids);
    $counts = array_fill_keys($ids, 0);
    foreach (["SELECT reported_user_id AS u, COUNT(*) AS n FROM message_reports WHERE reported_user_id IN ($in) GROUP BY reported_user_id",
              "SELECT author_id AS u, COUNT(*) AS n FROM content_reports WHERE author_id IN ($in) GROUP BY author_id"] as $sql) {
        try {
            $c = $db->prepare($sql);
            $c->execute($ids);
            foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[(int)$r['u']] = ($counts[(int)$r['u']] ?? 0) + (int)$r['n'];
        } catch (\Throwable $e) { /* a table this install does not have yet */ }
    }
    $warn = userWarningsSummary($db, $ids);
    $me = (int)($_SESSION['admin_via_user'] ?? 0);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $id = (int)$u['id'];
        $out[$id] = [
            'name'         => (string)$u['username'],
            'avatar_sha'   => $u['avatar_sha'] ?? null,   // for the caller's picture; not for a page
            'banned'       => (string)$u['status'] === 'banned',
            'banned_until' => $u['banned_until'] ? (string)$u['banned_until'] : null,
            'muted_until'  => ($u['pm_muted_until'] !== null && strtotime((string)$u['pm_muted_until']) > time()) ? (string)$u['pm_muted_until'] : null,
            'staff'        => userIdHasPermission($db, $cfg, $id, 'panel.access'),
            'you'          => $me > 0 && $me === $id,
            'reports'      => (int)($counts[$id] ?? 0),
            'warnings'     => (int)($warn[$id]['count'] ?? 0),
            'latest_warnings' => $warn[$id]['latest'] ?? [],
        ];
    }
    return $out;
}

/* ── the moderator's queue ────────────────────────────────────────────────────────────────────────── */

/**
 * The kinds THIS panel session may see, in the order of their tabs: [kind => ['handle' => bool, 'on' => bool]].
 * A kind is listed while the session holds its `view`; `on` is whether its feature is switched on — the tab is
 * drawn only then, and a queue of a feature switched off waits for it.
 */
function contentReportPanelKinds(PDO $db, array $cfg): array
{
    $out = [];
    foreach (CONTENT_REPORT_KINDS as $k) {
        if (!panelCan($db, $cfg, contentReportPerm($k, 'view'))) continue;
        $out[$k] = ['handle' => panelCan($db, $cfg, contentReportPerm($k, 'handle')), 'on' => contentReportKindOn($cfg, $k)];
    }
    return $out;
}

/** Open TARGETS per kind — what the tab badges count: a thing three people reported is one thing to decide. */
function contentReportOpenCounts(PDO $db, array $kinds): array
{
    $out = array_fill_keys($kinds, 0);
    if (!$kinds) return $out;
    try {
        $in = implode(',', array_fill(0, count($kinds), '?'));
        $st = $db->prepare("SELECT kind, COUNT(*) AS n FROM (SELECT kind, target_id, info_hash FROM content_reports
                             WHERE status = 'open' AND kind IN ($in) GROUP BY kind, target_id, info_hash) g GROUP BY kind");
        $st->execute(array_values($kinds));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['kind']] = (int)$r['n'];
    } catch (\Throwable $e) { /* the table arrives with schema 84 */ }
    return $out;
}

/**
 * EVERYTHING WAITING on the Reports page, as its tabs count it (1.74.3) — the one number the header's "Reports" button
 * carries on every panel page, so a queue nobody has opened this morning shows from wherever the panel is open (the
 * Shouts tab's badge was the only place a waiting shout report showed).
 *
 * The parts are the tab badges' own numbers, from the same questions the tabs' endpoints ask: the torrent reports and the
 * archived ones nobody reviewed (api/admin/fetch_reports.php `pending_reports` / `pending_archives`: checked = 0 and
 * blocked = 0), the appeals (fetch_appeals.php `pending_count`), the open reported messages (fetch_message_reports.php
 * `open`) and the open TARGETS of each content kind (content_reports.php `counts`: contentReportOpenCounts()). And each is
 * counted for a session only where its tab is drawn (templates/admin/dashboard.php): the torrent three behind
 * `panel.reports.view`, the messages behind `panel.messages.view` while messages are on, a content kind behind its view
 * while its feature is on. [ 'parts' => [part => n], 'total' => n ]; a table not there yet (an older schema) counts 0.
 * assets/js/admin-common.js reportsWaiting() keeps the number up to date from the same parts as their badges change.
 */
function panelReportsWaiting(PDO $db, array $cfg): array
{
    $parts = ['reports' => 0, 'archives' => 0, 'appeals' => 0, 'messages' => 0, 'comment' => 0, 'description' => 0, 'shout' => 0];
    $count = static function (string $sql) use ($db): int {
        try { return (int)$db->query($sql)->fetchColumn(); } catch (\Throwable $e) { return 0; }
    };
    if (panelCan($db, $cfg, 'panel.reports.view')) {
        $parts['reports']  = $count("SELECT COUNT(*) FROM reports WHERE checked = 0 AND blocked = 0");
        $parts['archives'] = $count("SELECT COUNT(*) FROM archives WHERE checked = 0 AND blocked = 0");
        $parts['appeals']  = $count("SELECT COUNT(*) FROM appeals WHERE status = 'pending'");
    }
    if (function_exists('pmEnabled') && pmEnabled($cfg) && panelCan($db, $cfg, 'panel.messages.view')) {
        $parts['messages'] = $count("SELECT COUNT(*) FROM message_reports WHERE status = 'open'");
    }
    $on = array_keys(array_filter(contentReportPanelKinds($db, $cfg), fn($k) => $k['on']));
    foreach (contentReportOpenCounts($db, $on) as $k => $n) $parts[(string)$k] = (int)$n;
    return ['parts' => $parts, 'total' => array_sum($parts)];
}

/** The filters a listing takes, cleaned: status, author, reporter, from, to (Y-m-d), q. */
function contentReportFilters(array $q): array
{
    $status = (string)($q['status'] ?? 'open');
    $date = static fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) ? $v : '';
    return [
        'status'   => in_array($status, ['open', 'closed', 'all'], true) ? $status : 'open',
        'author'   => contentReportClean((string)($q['author'] ?? ''), 64),
        'reporter' => contentReportClean((string)($q['reporter'] ?? ''), 64),
        'from'     => $date($q['from'] ?? ''),
        'to'       => $date($q['to'] ?? ''),
        'q'        => contentReportClean((string)($q['q'] ?? ''), 100),
    ];
}

/** A LIKE pattern for a text typed into a filter: its % and _ taken literally. */
function contentReportLike(string $s, bool $prefixOnly = false): string
{
    $e = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    return ($prefixOnly ? '' : '%') . $e . '%';
}

/**
 * The target as it is NOW, for the panel (any status): ['exists' => bool, 'state' => visible|pending|deleted|
 * gone|none, 'text', 'format', 'author_id', 'author' => name, 'name' => the torrent's name]. Null never:
 * a target that is gone is ['exists' => false, 'state' => 'gone'].
 */
function contentReportCurrent(PDO $db, array $cfg, string $kind, int $targetId, string $hash): array
{
    $gone = ['exists' => false, 'state' => 'gone', 'text' => '', 'format' => 'plain', 'author_id' => null, 'author' => '', 'name' => ''];
    if ($kind === 'comment') {
        $row = function_exists('commentRow') ? commentRow($db, $targetId) : null;
        $name = ($hash !== '' && function_exists('commentTorrentName')) ? commentTorrentName($db, $hash) : '';
        if ($row === null) return ['name' => $name] + $gone;
        return ['exists' => (string)$row['status'] !== 'deleted', 'state' => (string)$row['status'], 'text' => (string)$row['body'],
                'format' => (string)$row['body_format'], 'author_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'author' => (string)($row['username'] ?? ''), 'name' => $name, 'guest_tag' => (string)($row['guest_tag'] ?? ''),
                'delete_reason' => (string)($row['delete_reason'] ?? '')];
    }
    if ($kind === 'description') {
        $rec = function_exists('contentRecordFor') ? contentRecordFor($db, $hash) : null;
        $name = function_exists('contentNameFor') ? contentNameFor($db, $hash) : $hash;
        if ($rec === null || !contentOccupied($rec)) return ['state' => 'none', 'name' => $name] + $gone;
        $text = (string)($rec['description'] ?? '');
        $format = (string)($rec['description_format'] ?? 'bbcode');
        if ($text === '') { $text = (string)($rec['source_url'] ?? ''); $format = 'plain'; }
        $author = null;
        if ($rec['content_user_id'] !== null) {
            $u = userFindById($db, (int)$rec['content_user_id']);
            $author = $u ? (string)$u['username'] : null;
        }
        return ['exists' => true, 'state' => (string)$rec['content_status'], 'text' => $text, 'format' => $format,
                'author_id' => $rec['content_user_id'] !== null ? (int)$rec['content_user_id'] : null, 'author' => (string)($author ?? ''),
                'name' => $name, 'source_url' => (string)($rec['source_url'] ?? ''), 'banned' => !empty($rec['banned'])];
    }
    if ($kind === 'shout') {
        try {
            $st = $db->prepare("SELECT s.id, s.user_id, s.body, s.body_format, s.is_system, s.deleted_at, u.username
                                  FROM shouts s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1");
            $st->execute([$targetId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $row = false; }
        if (!$row) return $gone;
        return ['exists' => $row['deleted_at'] === null, 'state' => $row['deleted_at'] === null ? 'visible' : 'deleted',
                'text' => (string)$row['body'], 'format' => (string)$row['body_format'],
                'author_id' => $row['user_id'] !== null ? (int)$row['user_id'] : null, 'author' => (string)($row['username'] ?? ''), 'name' => ''];
    }
    return $gone;
}

/**
 * Words of one kind, as HTML, through THAT kind's own renderer — the one the public page uses: a comment's
 * walker (links only where its writer may link), a description's renderer with the hidden parts shown (a
 * moderator is signed in) and the emotes, the room's pipeline for a shout. 'plain' is text, whatever the kind.
 */
function contentReportRender(PDO $db, array $cfg, string $kind, string $text, string $format, ?int $authorId): string
{
    if ($text === '') return '';
    $plain = fn(string $t): string => nl2br(htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    try {
        if ($kind === 'comment' && function_exists('commentRenderHtml')) {
            $ctx = commentRenderContext($db, $cfg, null, [$text]);
            $ctx['links'] = commentLinksOn($cfg) && ($authorId ?? 0) > 0;
            return commentRenderHtml($text, $format, $cfg, $ctx);
        }
        if ($kind === 'description' && $format !== 'plain' && function_exists('richtextRenderIn')) {
            return richtextRenderIn('description', $db, $text, $format, $cfg, true);
        }
        if ($kind === 'shout' && function_exists('shoutBodyHtml')) {
            return shoutBodyHtml($text, $format, $cfg, shoutRenderContext($db, $cfg, [], [$text]));
        }
    } catch (\Throwable $e) { /* fall back to the words as text */ }
    return $plain($text);
}

/** Where the target lives on the site, as a site-relative address (the panel opens it in a new tab). */
function contentReportWhere(array $cfg, string $kind, int $targetId, string $hash): string
{
    if ($kind === 'comment' && $hash !== '') return '?action=search&hash=' . $hash . '#comment-' . $targetId;
    if ($kind === 'description' && $hash !== '') return '?action=search&hash=' . $hash;
    if ($kind === 'shout') {
        $page = function_exists('shoutPlacement') && in_array(shoutPlacement($cfg), ['page', 'both'], true);
        return ($page ? '?action=' . (function_exists('shoutPageAction') ? shoutPageAction($cfg) : 'shoutbox') : '?') . '#shout-' . $targetId;
    }
    return '';
}

/**
 * One kind's queue, grouped by TARGET: the page of targets that match the filters (a target matches when any of
 * its reports does), each with ALL its reports, newest first, its words now and as reported, where it lives and
 * its author's state. ['groups' => […], 'total' => targets, 'page', 'pages'].
 */
function contentReportList(PDO $db, array $cfg, string $kind, array $f, int $page, int $perPage): array
{
    $where = ['r.kind = ?'];
    $args = [$kind];
    if ($f['author'] !== '') { $where[] = 'au.username LIKE ?'; $args[] = contentReportLike($f['author'], true); }
    if ($f['reporter'] !== '') { $where[] = 'rep.username LIKE ?'; $args[] = contentReportLike($f['reporter'], true); }
    if ($f['from'] !== '') { $where[] = 'r.created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'] !== '') { $where[] = 'r.created_at < ? + INTERVAL 1 DAY'; $args[] = $f['to'] . ' 00:00:00'; }
    if ($f['q'] !== '') {
        $where[] = '(r.reason LIKE ? OR r.snapshot LIKE ? OR r.note LIKE ? OR r.reply LIKE ? OR rep.username LIKE ? OR au.username LIKE ?)';
        $like = contentReportLike($f['q']);
        array_push($args, $like, $like, $like, $like, $like, $like);
    }
    $having = $f['status'] === 'open' ? 'HAVING has_open = 1' : ($f['status'] === 'closed' ? 'HAVING has_open = 0' : '');
    // Literal fragments only; every value is in $args (tests/sql_safety_test.php reads these names).
    $sql = "SELECT r.target_id, r.info_hash, MAX(r.status = 'open') AS has_open, COUNT(*) AS n, MAX(r.created_at) AS last_at
              FROM content_reports r LEFT JOIN users rep ON rep.id = r.reporter_id LEFT JOIN users au ON au.id = r.author_id
             WHERE " . implode(' AND ', $where) . " GROUP BY r.target_id, r.info_hash $having";
    $cnt = $db->prepare("SELECT COUNT(*) FROM ($sql) g");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    $st = $db->prepare("$sql ORDER BY has_open DESC, last_at DESC, r.target_id DESC, r.info_hash DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
    $st->execute($args);
    $keys = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$keys) return ['groups' => [], 'total' => $total, 'page' => $page, 'pages' => $pages];

    // Every report of the page's targets — all of them, not only the ones the filter matched: the target is the
    // unit a moderator decides about, and its whole history is part of the decision. One literal pair of
    // placeholders per target, the values bound.
    $pairs = [];
    $rargs = [$kind];
    foreach ($keys as $k) { $pairs[] = '(r.target_id = ? AND r.info_hash = ?)'; $rargs[] = (int)$k['target_id']; $rargs[] = (string)$k['info_hash']; }
    $clause = implode(' OR ', $pairs);
    $rs = $db->prepare("SELECT r.*, rep.username AS reporter_name, rep.avatar_sha AS reporter_avatar_sha
                          FROM content_reports r LEFT JOIN users rep ON rep.id = r.reporter_id
                         WHERE r.kind = ? AND ($clause) ORDER BY r.created_at DESC, r.id DESC");
    $rs->execute($rargs);
    $byKey = [];
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) $byKey[(int)$r['target_id'] . '|' . $r['info_hash']][] = $r;

    $base = function_exists('getBaseUrl') ? getBaseUrl() : '';
    $face = static fn(string $name, $sha): string => ($name !== '' && function_exists('userAvatarField'))
        ? userAvatarField(['username' => $name, 'avatar_sha' => $sha], 20, $base, $cfg) : '';
    $groups = [];
    $authors = [];
    foreach ($keys as $k) {
        $tid = (int)$k['target_id'];
        $hash = (string)$k['info_hash'];
        $rows = $byKey[$tid . '|' . $hash] ?? [];
        $now = contentReportCurrent($db, $cfg, $kind, $tid, $hash);
        // Whose words: the author they have now, else the one the newest report found.
        $authorId = $now['author_id'] ?? null;
        if ($authorId === null) foreach ($rows as $r) if ($r['author_id'] !== null) { $authorId = (int)$r['author_id']; break; }
        if ($authorId !== null) $authors[] = $authorId;
        $latest = $rows[0] ?? null;
        $snapText = $latest !== null ? (string)$latest['snapshot'] : '';
        $snapFmt = $latest !== null ? (string)$latest['snapshot_format'] : 'plain';
        $changed = $now['exists'] && $latest !== null && $snapText !== (string)$now['text'];
        $reports = [];
        $open = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'open') $open++;
            $reports[] = [
                'id' => (int)$r['id'], 'status' => (string)$r['status'], 'reason' => (string)$r['reason'],
                'reporter' => (string)($r['reporter_name'] ?? ''), 'reporter_avatar' => $face((string)($r['reporter_name'] ?? ''), $r['reporter_avatar_sha'] ?? null),
                'created_at' => (string)$r['created_at'], 'outcome' => (string)$r['outcome'], 'handled_by' => (string)$r['handled_by'],
                'handled_at' => $r['handled_at'] ? (string)$r['handled_at'] : null, 'note' => (string)$r['note'], 'reply' => (string)($r['reply'] ?? ''),
                // the words this reporter saw, when they are not what the card shows as the latest report's
                'snapshot_differs' => (string)$r['snapshot'] !== $snapText,
            ];
        }
        $groups[] = [
            'kind' => $kind, 'target_id' => $tid, 'hash' => $hash, 'name' => (string)($now['name'] ?? ''),
            'where' => contentReportWhere($cfg, $kind, $tid, $hash),
            'state' => (string)$now['state'], 'exists' => (bool)$now['exists'],
            'html' => $now['exists'] ? contentReportRender($db, $cfg, $kind, (string)$now['text'], (string)$now['format'], $authorId) : '',
            // what the moderator is looking at, for Remove to check nothing changed under them
            'sha' => $now['exists'] ? sha1((string)$now['text']) : '',
            // the words as the newest report found them, when they are not what is there now (changed, or gone)
            'reported_html' => ($changed || !$now['exists']) && $snapText !== '' ? contentReportRender($db, $cfg, $kind, $snapText, $snapFmt, $authorId) : '',
            'changed' => $changed,
            'author_id' => $authorId,
            'author' => (string)($now['author'] ?? ''),
            'guest_tag' => (string)($now['guest_tag'] ?? ''),
            'open' => $open,
            'reports' => $reports,
        ];
    }
    // The authors' names and pictures, and what they are, once for the page.
    $states = reportAuthorStates($db, $cfg, $authors);
    foreach ($groups as &$g) {
        $s = $g['author_id'] !== null ? ($states[$g['author_id']] ?? null) : null;
        if ($s !== null && $g['author'] === '') $g['author'] = $s['name'];
        $g['author_avatar'] = $s !== null ? $face($s['name'], $s['avatar_sha']) : '';
        if ($s !== null) unset($s['avatar_sha']);
        $g['author_state'] = $s;
        unset($g['author_id']);
    }
    unset($g);
    return ['groups' => $groups, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/* ── acting on a target ───────────────────────────────────────────────────────────────────────────── */

/**
 * Close these (open) reports with an outcome, a note and an answer. Returns how many were closed.
 */
function contentReportCloseRows(PDO $db, array $ids, string $outcome, string $note, string $reply, string $by): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (!$ids) return 0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("UPDATE content_reports SET status = 'closed', open_slot = NULL, outcome = ?, note = ?, reply = ?, handled_by = ?, handled_at = NOW()
                         WHERE status = 'open' AND id IN ($in)");
    $st->execute(array_merge([$outcome, $note, $reply !== '' ? $reply : null, mb_substr($by, 0, 64)], $ids));
    return $st->rowCount();
}

/**
 * The reporters of these reports hear what became of them — each once, in THEIR language, with the answer when
 * the moderator wrote one. $outcome: closed | removed | handled. Returns how many were told. Never the name of
 * anybody else who reported it, and the author never hears who did.
 */
function contentReportTellReporters(PDO $db, array $cfg, array $rows, string $outcome, string $reply, string $kind, string $name, string $author): int
{
    $told = [];
    foreach ($rows as $r) {
        $rid = (int)($r['reporter_id'] ?? 0);
        if ($rid <= 0 || isset($told[$rid])) continue;
        $u = userFindById($db, $rid);
        if ($u === null) continue;
        $lang = recipientLang($cfg, $u);
        $vars = ['name' => $name, 'user' => $author !== '' ? $author : langFor($lang, 'notify.creport_someone')];
        if ($outcome === 'removed') {
            $title = langFor($lang, 'notify.creport_removed_' . $kind, $vars);
        } else {
            $what = langFor($lang, 'notify.creport_what_' . $kind, $vars);
            $title = langFor($lang, $outcome === 'closed' ? 'notify.creport_closed' : 'notify.creport_handled', ['what' => $what]);
        }
        userNotify($db, $rid, 'report', $title,
            $reply !== '' ? langFor($lang, 'notify.report_reply', ['reply' => $reply]) : langFor($lang, 'notify.report_no_reply'));
        $told[$rid] = true;
    }
    return count($told);
}

/** A panel answer: ['status', 'body']. */
function contentReportPanelFail(int $status, string $code, array $extra = []): array
{
    return ['status' => $status, 'body' => ['success' => false, 'error' => $code] + $extra];
}

/**
 * POST admin/content_report_action {kind, target_id, hash, action, mode: silent|loud, reason, note, reply,
 * days, seen} — what `panel.reports.<kind>.handle` may do to ONE target (all its reports at once):
 *
 *   close    the open reports closed, "no action"; the reporters told, with the answer.
 *   reopen   the closed ones open again (a second thought: the notes and answers stay); nobody told.
 *   remove   the words taken down through their own function — a comment's commentDelete(), a description's
 *            contentDelete(), a shout's shoutDelete(), none of which tells the author itself here; `seen` is the
 *            sha of the words the moderator was shown, and a change since is refused (409 changed).
 *   warn     a warning, and nothing else (always loud).
 *   mute     users.pm_muted_until (messages, shouts, comments, a profile's and a list's words) for `days`
 *            (0: until somebody lifts it), measured by the database's clock; unmute lifts it.
 *   ban      the account suspended for `days` (0: until lifted), its remembered devices signed out; unban lifts
 *            a ban THIS card may lift — one with a date. A ban without one is the owner's, from Users.
 * mode: every action that reaches the author — remove, mute, ban, and the lifts — is silent (the author told
 * nothing) or loud (a warning, with `reason`, which is then required; a lift says so plainly and is no warning).
 * remove, warn, mute and ban close the target's open reports as their outcome, and the reporters hear "removed"
 * or "handled". The account actions follow the message card's rules: never an account that can open the panel,
 * never yourself, never a ban without a date. One audit line, creport.<action>.
 */
function contentReportActionRequest(PDO $db, array $cfg, array $input): array
{
    $kind = is_string($input['kind'] ?? null) ? (string)$input['kind'] : '';
    if (!in_array($kind, CONTENT_REPORT_KINDS, true)) return contentReportPanelFail(400, 'bad_kind');
    if (!panelCan($db, $cfg, contentReportPerm($kind, 'handle'))) return contentReportPanelFail(403, 'no_permission');
    if (!contentReportKindOn($cfg, $kind)) return contentReportPanelFail(404, 'disabled');
    $action = is_string($input['action'] ?? null) ? (string)$input['action'] : '';
    if (!in_array($action, CONTENT_REPORT_ACTIONS, true)) return contentReportPanelFail(400, 'unknown_action');
    $mode = ($input['mode'] ?? 'silent') === 'loud' ? 'loud' : 'silent';
    if ($action === 'warn') $mode = 'loud';
    $targetId = $kind === 'description' ? 0 : max(0, (int)($input['target_id'] ?? 0));
    $hash = $kind === 'shout' ? '' : strtolower(trim((string)($input['hash'] ?? '')));
    if ($kind !== 'shout' && !preg_match('/^[0-9a-f]{40}$/', $hash)) return contentReportPanelFail(400, 'not_found');
    $note = contentReportClean($input['note'] ?? '', CONTENT_REPORT_TEXT_MAX);
    $reply = contentReportClean($input['reply'] ?? '', CONTENT_REPORT_TEXT_MAX);
    $reason = contentReportClean($input['reason'] ?? '', CONTENT_REPORT_TEXT_MAX);
    $days = max(0, min(3650, (int)($input['days'] ?? 0)));

    $st = $db->prepare("SELECT * FROM content_reports WHERE kind = ? AND target_id = ? AND info_hash = ? ORDER BY id DESC");
    $st->execute([$kind, $targetId, $hash]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return contentReportPanelFail(404, 'not_found');
    $openRows = array_values(array_filter($rows, fn($r) => $r['status'] === 'open'));
    $now = contentReportCurrent($db, $cfg, $kind, $targetId, $hash);
    $authorId = $now['author_id'] ?? null;
    if ($authorId === null) foreach ($rows as $r) if ($r['author_id'] !== null) { $authorId = (int)$r['author_id']; break; }
    $author = $authorId !== null ? userFindById($db, (int)$authorId) : null;
    $authorName = $author !== null ? (string)$author['username'] : (string)($now['author'] ?? '');
    $name = (string)($now['name'] ?? '');
    $actor = reportPanelActor($db);
    $by = $actor['username'];

    // What reaches the account: the guards first, before anything is done.
    $touchesAccount = in_array($action, ['warn', 'mute', 'unmute', 'ban', 'unban'], true) || ($action === 'remove' && $mode === 'loud');
    if ($touchesAccount) {
        $why = reportAccountGuard($db, $cfg, $authorId !== null ? (int)$authorId : null);
        if ($why !== '') return contentReportPanelFail($why === 'no_author' ? 400 : 403, $why);
    }
    if ($mode === 'loud' && in_array($action, ['remove', 'warn', 'mute', 'ban'], true) && $reason === '') {
        return contentReportPanelFail(400, 'reason_required');
    }
    if (in_array($action, ['ban', 'unban'], true) && $author !== null
        && (string)$author['status'] === 'banned' && empty($author['banned_until'])) {
        return contentReportPanelFail($action === 'ban' ? 409 : 403, 'ban_is_permanent');
    }
    $src = ['kind' => $kind, 'id' => $targetId, 'hash' => $hash, 'name' => $name];
    $detail = ['kind' => $kind, 'target' => $targetId, 'hash' => $hash, 'mode' => $mode, 'author_id' => $authorId,
               'reports' => array_map(fn($r) => (int)$r['id'], $openRows), 'note' => $note !== '' ? $note : null,
               'reply' => $reply !== '' ? $reply : null, 'reason' => $reason !== '' ? $reason : null];
    $out = ['success' => true, 'action' => $action, 'mode' => $mode];
    $what = $kind . ($kind === 'shout' ? ' #' . $targetId : ' on ' . ($name !== '' ? $name : substr($hash, 0, 12)));

    if ($action === 'close' || $action === 'reopen') {
        if ($action === 'close') {
            $n = contentReportCloseRows($db, array_map(fn($r) => (int)$r['id'], $openRows), 'closed', $note, $reply, $by);
            $out['closed'] = $n;
            $out['told'] = $n > 0 ? contentReportTellReporters($db, $cfg, $openRows, 'closed', $reply, $kind, $name, $authorName) : 0;
        } else {
            // A second thought: the notes and the answers the first time left stay. UPDATE IGNORE: a report whose
            // reporter has opened a new one on the same target since stays closed — the new one stands for it.
            $up = $db->prepare("UPDATE IGNORE content_reports SET status = 'open', open_slot = 1 WHERE kind = ? AND target_id = ? AND info_hash = ? AND status = 'closed'");
            $up->execute([$kind, $targetId, $hash]);
            $out['reopened'] = $up->rowCount();
        }
        auditLog($db, 'creport.' . $action, ['target_type' => $kind, 'target_id' => $kind === 'description' ? $hash : (string)$targetId,
            'summary' => $by . ' → ' . $action . ' the reports of a ' . $what, 'detail' => $detail]);
        return ['status' => 200, 'body' => $out];
    }

    if ($action === 'remove') {
        if (!$now['exists'] || ($kind === 'description' && ($now['state'] ?? '') === 'none')) return contentReportPanelFail(409, 'gone');
        $seen = is_string($input['seen'] ?? null) ? (string)$input['seen'] : '';
        if ($seen !== '' && !hash_equals(sha1((string)$now['text']), $seen)) return contentReportPanelFail(409, 'changed');
        $me = ['id' => $actor['id'], 'username' => $by];
        if ($kind === 'comment') {
            $row = commentRow($db, $targetId);
            if ($row === null) return contentReportPanelFail(409, 'gone');
            // The row keeps why it went; a silent removal without a reason still says it was a report.
            $why = $reason !== '' ? $reason : 'after a report (#' . (int)$rows[0]['id'] . ')';
            $r = commentDelete($db, $cfg, $me, $row, $why, ['notify' => false, 'authority' => 'panel']);
            if (empty($r['ok'])) return contentReportPanelFail((int)($r['status'] ?? 409), (string)($r['error'] ?? 'failed'));
        } elseif ($kind === 'description') {
            $rec = contentRecordFor($db, $hash);
            if ($rec === null || !contentOccupied($rec)) return contentReportPanelFail(409, 'gone');
            contentDelete($db, $cfg, $rec, $me, 'any', ['notify' => false]);
        } else {
            $r = shoutDelete($db, $cfg, $me, $targetId, ['authority' => 'panel']);
            if (empty($r['ok'])) return contentReportPanelFail($r['error'] === 'not_found' ? 409 : 403, $r['error'] === 'not_found' ? 'gone' : (string)$r['error']);
        }
        $n = contentReportCloseRows($db, array_map(fn($r) => (int)$r['id'], $openRows), 'removed', $note, $reply, $by);
        $out['closed'] = $n;
        $out['told'] = $openRows ? contentReportTellReporters($db, $cfg, $openRows, 'removed', $reply, $kind, $name, $authorName) : 0;
        if ($mode === 'loud' && $authorId !== null) $out['warning'] = userWarn($db, $cfg, (int)$authorId, $reason, $src, 'remove');
        $detail['warning'] = $out['warning'] ?? null;
        auditLog($db, 'creport.remove', ['target_type' => $kind, 'target_id' => $kind === 'description' ? $hash : (string)$targetId,
            'summary' => $by . ' → removed a ' . $what . ($authorName !== '' ? ' by ' . $authorName : '') . ' (' . $mode . ')', 'detail' => $detail]);
        return ['status' => 200, 'body' => $out];
    }

    // The account actions. $authorId is an account the guards above let through.
    $uid = (int)$authorId;
    $until = null;
    if ($action === 'warn') {
        $out['warning'] = userWarn($db, $cfg, $uid, $reason, $src, 'warn');
    } elseif ($action === 'mute' || $action === 'ban') {
        // By the DATABASE's clock, which is the one every reader of the column compares with; "until somebody
        // lifts it" as a date far enough away to mean it (the message card's convention).
        if ($action === 'mute') {
            $db->prepare("UPDATE users SET pm_muted_until = " . ($days > 0 ? "NOW() + INTERVAL ? DAY" : "?") . " WHERE id = ?")
               ->execute([$days > 0 ? $days : CONTENT_REPORT_FOREVER, $uid]);
            $col = 'pm_muted_until';
        } else {
            $db->prepare("UPDATE users SET status = 'banned', banned_until = " . ($days > 0 ? "NOW() + INTERVAL ? DAY" : "?") . " WHERE id = ?")
               ->execute([$days > 0 ? $days : CONTENT_REPORT_FOREVER, $uid]);
            // A banned account's sessions end on their next request (currentUser() refuses it); its remembered devices go now.
            if (function_exists('userSignOutOthers')) userSignOutOthers($db, $uid, false);
            if (function_exists('userPermissionsForget')) userPermissionsForget($uid);
            $col = 'banned_until';
        }
        $q = $db->prepare("SELECT `$col`, UNIX_TIMESTAMP(`$col`) FROM users WHERE id = ?");
        $q->execute([$uid]);
        $v = $q->fetch(PDO::FETCH_NUM) ?: [null, null];
        $out['until'] = $v[0];
        if ($days > 0 && $v[1] !== null) $until = (int)$v[1];
        if ($mode === 'loud') $out['warning'] = userWarn($db, $cfg, $uid, $reason, $src, $action, $until);
    } else {   // unmute | unban
        if ($action === 'unmute') {
            $db->prepare("UPDATE users SET pm_muted_until = NULL WHERE id = ?")->execute([$uid]);
        } else {
            $db->prepare("UPDATE users SET status = 'active', banned_until = NULL WHERE id = ?")->execute([$uid]);
            if (function_exists('userPermissionsForget')) userPermissionsForget($uid);
        }
        // Lifting one is good news, not a warning: said plainly when loud, not at all when silent.
        if ($mode === 'loud' && $author !== null) {
            $lang = recipientLang($cfg, $author);
            userNotify($db, $uid, 'account', langFor($lang, $action === 'unmute' ? 'notify.unmuted' : 'notify.unbanned'),
                       langFor($lang, $action === 'unmute' ? 'notify.unmuted_body' : 'notify.unbanned_body'));
        }
    }
    if (in_array($action, ['warn', 'mute', 'ban'], true)) {
        $outcome = ['warn' => 'warned', 'mute' => 'muted', 'ban' => 'banned'][$action];
        $n = contentReportCloseRows($db, array_map(fn($r) => (int)$r['id'], $openRows), $outcome, $note, $reply, $by);
        $out['closed'] = $n;
        // The reporters hear it was handled — not what was done to the other account, unless the moderator writes it.
        $out['told'] = $openRows ? contentReportTellReporters($db, $cfg, $openRows, 'handled', $reply, $kind, $name, $authorName) : 0;
    }
    $detail += ['days' => $days, 'until' => $out['until'] ?? null, 'warning' => $out['warning'] ?? null];
    auditLog($db, 'creport.' . $action, ['target_type' => 'user', 'target_id' => $uid,
        'summary' => $by . ' → ' . $action . ' ' . $authorName . ' after a report of a ' . $what . ' (' . $mode . ($days > 0 ? ', ' . $days . 'd' : '') . ')',
        'detail' => $detail]);
    return ['status' => 200, 'body' => $out];
}
