<?php
/**
 * What a moderator may do with a reported message.
 *
 *   POST admin/message_report_action {id, action, note, days?, mode?, reason?}
 *     action: 'close' | 'reopen' | 'delete_message' | 'warn' | 'mute' | 'unmute' | 'ban' | 'unban'
 *     days:   for 'mute' and 'ban' — 0 means "until somebody lifts it"
 *     mode:   (1.71.0) for every action that reaches the author — 'silent': they are told nothing; 'loud': a
 *             WARNING in their own language with the moderator's `reason` (required), and a row in
 *             `user_warnings` (includes/moderation.php, userModerate()). 'warn' is always loud. NO mode — what every
 *             caller sent before 1.71.0 — is what it always was: the author told the plain fact.
 *
 * Closing says a person looked; deleting removes the line that was reported and NOTHING else in the
 * conversation. Muting stops that account writing MESSAGES; banning stops the account. There is
 * still deliberately no "read the thread", no "message this user" and no "reply as the tracker" —
 * the panel's business here is one line of somebody else's correspondence.
 *
 * ── why a mute and a ban are DATES ─────────────────────────────────────────────────────────────
 * Because a punishment with an end needs nobody to remember to end it. A flag would need the person
 * who set it — usually the one who was angry a week ago — to come back and unset it, and the
 * accounts nobody remembers are exactly the ones that stay punished for ever. The janitor tidies
 * the columns; nothing depends on it, because every reader compares them with NOW().
 *
 * ── who cannot be punished from here ───────────────────────────────────────────────────────────
 * An account that can open the panel, and yourself. This card is a moderation queue, not a way for
 * one moderator to remove another — that decision belongs on the Users page, where it is visible as
 * what it is.
 *
 * Every action is written to the audit log with the two accounts named. Somebody reading a private
 * message, even a reported one, is exactly the kind of act an audit log exists for.
 */
requirePost();
if (!panelCan($db, $cfg, 'panel.messages.handle')) jsonResponse(['error' => 'no_permission'], 403);

$input  = readJsonBody();
$id     = (int)($input['id'] ?? 0);
$action = (string)($input['action'] ?? '');
$note   = mb_substr(trim((string)($input['note'] ?? '')), 0, 500);
// Two texts, two readers (1.54.0). `note` is for the log and stays in the panel; `reply` is the
// answer to the person who reported, and THEY see it. Before this the only field was the note,
// labelled "for the log" — and a moderator who typed an answer into it and pressed Close had
// answered nobody. The reporter learns what happened to the report, never what happened to the
// other account beyond what the moderator chooses to write.
//
// The answer and the reason are texts with LINES and a format (1.75.0: plain, or BBCode / Markdown where the site has
// them on), up to REPORT_TEXT_MAX characters, kept raw — includes/reports.php reportTextClean() / reportTextValidate().
// One character past the limit is read in so that "too long" is said instead of the text being cut off silently.
$reply  = reportTextClean($input['reply'] ?? '', REPORT_TEXT_MAX + 1);
$replyFormat = reportTextFormat($input['reply_format'] ?? 'plain', $cfg);
// Silent or loud (1.71.0) — see the header. '' is the behaviour from before the choice existed.
$mode   = in_array($input['mode'] ?? null, ['silent', 'loud'], true) ? (string)$input['mode'] : '';
if ($action === 'warn') $mode = 'loud';
$reason = reportTextClean($input['reason'] ?? '', REPORT_TEXT_MAX + 1);
$reasonFormat = reportTextFormat($input['reason_format'] ?? 'plain', $cfg);
if ($id < 1) jsonResponse(['error' => __('api.report.invalid_id')], 400);
foreach ([[$reply, $replyFormat, 'reply'], [$reason, $reasonFormat, 'reason']] as [$txt, $fmt, $field]) {
    if (($why = reportTextValidate($txt, $fmt, $cfg)) !== null) jsonResponse(['error' => 'invalid_text', 'message' => $why, 'field' => $field], 400);
}

$st = $db->prepare("SELECT r.*, reporter.username AS reporter_name, reported.username AS reported_name
                      FROM message_reports r
                      JOIN users reporter ON reporter.id = r.reporter_id
                      JOIN users reported ON reported.id = r.reported_user_id
                     WHERE r.id = ? LIMIT 1");
$st->execute([$id]);
$rep = $st->fetch(PDO::FETCH_ASSOC);
if (!$rep) jsonResponse(['error' => __('api.report.not_found')], 404);

$who = $rep['reporter_name'] . ' → ' . $rep['reported_name'];

/** The reporter hears what became of the report: the moderator's answer when there is one — in THEIR language
 *  (1.74.0, QUAL-18: until then the moderator's, whose request this is). */
$tellReporter = function (string $titleKey) use ($db, $cfg, $rep, $reply, $replyFormat): void {
    $lang = recipientLangOf($db, $cfg, (int)$rep['reporter_id']);
    // A formatted answer (1.75.0) travels as `rich`, the body being only "The moderator wrote:" above it.
    if ($reply !== '' && $replyFormat !== 'plain') {
        userNotify($db, (int)$rep['reporter_id'], 'report', langFor($lang, $titleKey, ['user' => $rep['reported_name']]),
            langFor($lang, 'notify.report_reply_lead'), null, null, $reply, $replyFormat);
        return;
    }
    userNotify($db, (int)$rep['reporter_id'], 'report',
        langFor($lang, $titleKey, ['user' => $rep['reported_name']]),
        $reply !== '' ? langFor($lang, 'notify.report_reply', ['reply' => $reply]) : langFor($lang, 'notify.report_no_reply'));
};
/**
 * Write the moderator's note (the panel's own) and answer (the reporter's, with its format) on the report, closing it
 * when $closing. 1.75.0: one place for what four statements did, and the answer's format with it.
 */
$writeAnswer = function (bool $closing) use ($db, $id, $note, $reply, $replyFormat): void {
    $by = mb_substr((string)(auditActor($db)['name'] ?? 'admin'), 0, 64);
    $fmt = $reply !== '' ? $replyFormat : 'plain';
    // One of two literal fragments — nothing the request wrote (tests/sql_safety_test.php: a clause assembled from literals).
    $extraSql = $closing ? "status = 'closed', " : '';
    try {
        $db->prepare("UPDATE message_reports SET " . $extraSql . "note = ?, reply = ?, reply_format = ?, handled_by = ?, handled_at = NOW() WHERE id = ?")
           ->execute([$note, $reply !== '' ? $reply : null, $fmt, $by, $id]);
    } catch (\PDOException $e) {
        // A database whose v94 migration has not run yet: the answer as it was kept before (plain, 500 characters).
        if ((string)$e->getCode() !== '42S22') throw $e;
        $db->prepare("UPDATE message_reports SET " . $extraSql . "note = ?, reply = ?, handled_by = ?, handled_at = NOW() WHERE id = ?")
           ->execute([$note, $reply !== '' ? mb_substr($reply, 0, 500) : null, $by, $id]);
    }
};
/** The reported account's own notices, in its language too. */
$authorLang = recipientLangOf($db, $cfg, (int)$rep['reported_user_id']);

if ($action === 'close' || $action === 'reopen') {
    $to = $action === 'close' ? 'closed' : 'open';
    if ($action === 'reopen') {
        // A second thought, not a new answer: the note and the reply the first moderator left stay,
        // so the next one sees what was done. The boxes on a freshly drawn card are empty, and
        // writing them here erased exactly what the card promises to keep.
        $db->prepare("UPDATE message_reports SET status = 'open' WHERE id = ?")->execute([$id]);
    } else {
        $writeAnswer(true);
    }
    // Closing is the answer; reopening is the moderator's own second thought and says nothing yet.
    if ($action === 'close') $tellReporter('notify.report_closed');
    auditLog($db, 'pm.report.' . $action, [
        'target_type' => 'user', 'target_id' => (int)$rep['reported_user_id'],
        'summary' => $who, 'detail' => ['report' => $id, 'note' => reportTextAudit($note), 'reply' => reportTextAudit($reply), 'reply_format' => $reply !== '' ? $replyFormat : null],
    ]);
    jsonResponse(['success' => true, 'status' => $to]);
}

/**
 * The loud half (1.71.0): a WARNING goes to an account the panel may act on — never one that can open the
 * panel, never yourself (the same rule as a mute) — and only with the moderator's reason, which is its body.
 * Asked BEFORE anything is done, so a refusal leaves the message and the account as they were.
 */
$loudGate = function () use ($db, $cfg, $rep, $reason): void {
    $why = function_exists('reportAccountGuard') ? reportAccountGuard($db, $cfg, (int)$rep['reported_user_id']) : '';
    if ($why !== '') jsonResponse(['error' => $why], $why === 'no_author' ? 400 : 403);
    if ($reason === '') jsonResponse(['error' => 'reason_required'], 400);
};

/** What a refused userModerate() answers: its code, with its message and field when it is a refused text. */
$refuse = function (array $m): void {
    $body = ['error' => (string)$m['error']];
    foreach (['message', 'field'] as $k) if (isset($m[$k])) $body[$k] = $m[$k];
    jsonResponse($body, (int)$m['status']);
};
/** What this report's message is the SOURCE of, for the log row (userModerate() keeps it with the punishment). */
$source = ['kind' => 'message', 'id' => (int)$rep['message_id']];

if ($action === 'delete_message') {
    if ($mode === 'loud') $loudGate();
    // The reported line only. The context row is left where it is: it belongs to the other person,
    // it was never what anybody complained about, and removing it would edit a conversation rather
    // than answer a report.
    $db->prepare("DELETE FROM user_messages WHERE id = ?")->execute([(int)$rep['message_id']]);
    $writeAnswer(true);
    // Both people hear. The reporter: the line they complained about is gone. Its author: one of
    // their messages was removed — the fact and nothing else, because the note is the moderator's
    // and the reply is the reporter's. (1.71.0: unless the moderator chose silence — nothing — or a
    // warning, which says the same with their reason and is kept on the account.)
    $tellReporter('notify.report_message_removed');
    // The log row is written in EVERY mode now (1.75.0: a silent action is recorded, loud 0, and never counts), through the
    // one path; only a loud one is a warning the member reads.
    $m = userModerate($db, $cfg, ['user_id' => (int)$rep['reported_user_id'], 'action' => 'remove', 'loud' => $mode === 'loud', 'reason' => $reason,
                                  'reason_format' => $reasonFormat, 'src' => $source]);
    $warning = null;
    if ($mode === 'loud') {
        if (empty($m['ok'])) $refuse($m);
        $warning = $m['warning'];
    } elseif ($mode === '') {
        userNotify($db, (int)$rep['reported_user_id'], 'account', langFor($authorLang, 'notify.message_removed'), langFor($authorLang, 'notify.message_removed_body'));
    }
    auditLog($db, 'pm.message.delete', [
        'target_type' => 'user', 'target_id' => (int)$rep['reported_user_id'],
        'summary' => $who, 'detail' => ['report' => $id, 'message' => (int)$rep['message_id'], 'note' => reportTextAudit($note), 'reply' => reportTextAudit($reply),
                                        'mode' => $mode !== '' ? $mode : null, 'reason' => reportTextAudit($reason), 'warning' => $warning],
    ]);
    jsonResponse(['success' => true, 'deleted' => (int)$rep['message_id'], 'status' => 'closed']);
}

/* ── a warning, and nothing else (1.71.0) ─────────────────────────────────────────────────── */
if ($action === 'warn') {
    $loudGate();
    $m = userModerate($db, $cfg, ['user_id' => (int)$rep['reported_user_id'], 'action' => 'warn', 'loud' => true, 'reason' => $reason,
                                  'reason_format' => $reasonFormat, 'src' => $source]);
    if (empty($m['ok'])) $refuse($m);
    $warning = $m['warning'];
    // Like a mute: the report is answered, not closed — the card keeps it for the moderator to close.
    $writeAnswer(false);
    $tellReporter('notify.report_handled');
    auditLog($db, 'pm.user.warn', [
        'target_type' => 'user', 'target_id' => (int)$rep['reported_user_id'],
        'summary' => $who, 'detail' => ['report' => $id, 'warning' => $warning, 'reason' => reportTextAudit($reason), 'note' => reportTextAudit($note), 'reply' => reportTextAudit($reply)],
    ]);
    jsonResponse(['success' => true, 'action' => 'warn', 'warning' => $warning]);
}

/* ── the two that change what an ACCOUNT may do ────────────────────────────────────────────── */

if (in_array($action, ['mute', 'unmute', 'ban', 'unban'], true)) {
    $target = (int)$rep['reported_user_id'];
    // Never a moderator, and never yourself, and a ban with no end date is not this card's — all of it is userModerate()'s
    // (the guard, the lock, the apply; see the header). 0 days = until somebody lifts it; anything else is a number of days,
    // clamped to something a calendar can hold: a mute measured in centuries is a permanent one that nobody labelled.
    $days  = max(0, min(3650, (int)($input['days'] ?? 0)));
    $lift = $action === 'unmute' || $action === 'unban';
    // What the account is told (1.71.0): as before when no mode was chosen; nothing when silent; when loud, a silence or a
    // ban comes as a warning with the reason (userModerate() writes it), and lifting one is said plainly (good news is no
    // warning) — to everybody but a silent mode.
    $m = userModerate($db, $cfg, ['user_id' => $target, 'action' => $action, 'days' => $days, 'src' => $source,
                                  'loud' => $lift ? $mode !== 'silent' : $mode === 'loud', 'reason' => $reason, 'reason_format' => $reasonFormat]);
    if (empty($m['ok'])) $refuse($m);
    $warning = $m['warning'];
    $until = (!$lift && !$m['forever']) ? $m['until'] : null;
    // The mute of a request that chose no mode is told as the plain fact it always was.
    if ($action === 'mute' && $mode === '') {
        userNotify($db, $target, 'account', langFor($authorLang, 'notify.muted'),
            $until !== null ? langFor($authorLang, 'notify.muted_until', ['date' => $until]) : langFor($authorLang, 'notify.muted_forever'));
    }

    $writeAnswer(false);
    // A mute or a ban answers the report; the reporter is told it was handled, not what was done —
    // the other account's punishment is that account's business, unless the moderator writes it.
    // Lifting one answers nothing.
    if ($action === 'mute' || $action === 'ban') $tellReporter('notify.report_handled');
    auditLog($db, 'pm.user.' . $action, [
        'target_type' => 'user', 'target_id' => $target,
        'summary' => $who . ($days > 0 ? ' (' . $days . 'd)' : ''),
        'detail' => ['report' => $id, 'days' => $days, 'until' => $until, 'note' => reportTextAudit($note), 'reply' => reportTextAudit($reply),
                     'mode' => $mode !== '' ? $mode : null, 'reason' => reportTextAudit($reason), 'warning' => $warning],
    ]);
    jsonResponse(['success' => true, 'action' => $action, 'until' => $until]);
}

jsonResponse(['error' => 'unknown_action'], 400);
