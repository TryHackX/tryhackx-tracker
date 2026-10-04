<?php
/**
 * Private messages: the inbox, one conversation, and what somebody may do with it.
 *
 *   GET  user_messages[&view=inbox|archive|trash]  — one place's conversations: one row per person, newest first,
 *                                                     with the three places' counts (1.73.0)
 *   GET  user_messages&with=<name>[&part=trash]     — that conversation, oldest first (marks what it shows read):
 *                                                     what is not in the Trash, or — with part=trash, or when
 *                                                     nothing else is left — what is; and where it is
 *   GET  user_messages&can=<name>            — may I write to them, and if not, why
 *   POST user_messages {op:'send', to:<name>, body, format}
 *   POST user_messages {op:'read'|'archive'|'unarchive'|'purge', with:<name>}
 *   POST user_messages {op:'trash', with:<name>, upto?:<id>}       — Delete: into the Trash (pm_trash_days 0: for good)
 *   POST user_messages {op:'restore', with:<name>, to?:<id>, from?:<id>}   — out of it (an Undo names what it undoes)
 *   POST user_messages {op:'empty_trash'}
 *   POST user_messages {op:'report', message:<id>, reason}
 *
 * ── the Archive and the Trash (1.73.0) ─────────────────────────────────────────────────────────
 * Every one of those operations is EXPLICIT and idempotent (includes/people.php says why): asking for what already
 * is answers success with `changed: false`, so an Undo is the opposite operation and an Undo that comes too late is
 * a no-op, never an error. Each answers where the conversation is now (`state`), the three places' counts and the
 * badge's number. `hide` and `delete` are the names 1.52 – 1.72 used, kept for a page loaded before the update:
 * they mean `archive` and `trash`. None of it is written to the audit log — it is somebody's own mailbox, and the
 * panel reads that log.
 *
 * ── a blocked sender is told ───────────────────────────────────────────────────────────────────
 * The gate lives in pmCanWrite() (includes/people.php) and this endpoint reports its reason back
 * verbatim. Silently accepting a message nobody will ever see teaches somebody that they are being
 * ignored, which is both false and slower to find out than the truth.
 *
 * ── a report carries two messages and no more ──────────────────────────────────────────────────
 * The reported one, and the one before it so the moderator can see what it answered. Not the
 * conversation: this is two people's correspondence, and somebody deciding about one line does not
 * need the rest of it. That rule is in the schema (`message_reports.context_id`), in the panel
 * endpoint, and here.
 */
if (!pmEnabled($cfg)) jsonResponse(['error' => 'pm_disabled'], 404);

$me = currentUser($db);
if (!$me) jsonResponse(['error' => 'login_required'], 401);
$uid = (int)$me['id'];

// The reader's clock (1.73.0 part E): every moment below also travels as an instant (`ts`) and as 'Y-m-d H:i' in
// THIS reader's zone (`time`, pmReaderTime()) — their own choice on the account page, the site's otherwise — the
// way the shoutbox says its times since 1.62.0. The plain DATETIME fields (`created`, `last_at`, `until`) stay as
// they were for anything that reads them; the page shows the `*time` ones.
$readerTz = userDisplayTimezone($me, $cfg);
$stateTimes = static fn(array $s): array => $s + ['trashed_time' => pmReaderTime($s['trashed_ts'] ?? null, $readerTz),
                                                  'until_time'   => pmReaderTime($s['until_ts'] ?? null, $readerTz)];

/* ─────────────────────────────── POST ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => __('api.csrf.invalid')], 403);
    }
    $op = (string)($input['op'] ?? 'send');

    if ($op === 'send') {
        if (!userCan($db, $cfg, 'pm.send')) jsonResponse(['error' => 'no_permission'], 403);
        // Two ceilings, and they answer different questions: the IP one is about a script, the
        // per-day one is about an account. Neither alone is enough. The IP one is messages' OWN
        // (rate_limit_pm, 1.71.0) — it used to borrow the favourites' number.
        if (!rateLimitAllow('pmsend', ipBucket(getClientIp($cfg)), pmRatePerHour($cfg), 3600)) {
            jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
        }
        $perDay = pmMaxPerDay($cfg);
        // The count and the insert under one lock on the account's row: two requests from one
        // account in the same instant both read "one below the limit" otherwise, and the ceiling is
        // only the IP one in practice. Every refusal below exits, which rolls this back.
        $db->beginTransaction();
        $db->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE")->execute([$uid]);
        if (pmSentToday($db, $uid) >= $perDay) { $db->rollBack(); jsonResponse(['error' => 'day_limit', 'limit' => $perDay], 429); }

        $name = trim((string)($input['to'] ?? ''));
        $them = userValidUsername($name) ? userFindByLogin($db, $name) : null;
        $gate = pmCanWrite($db, $cfg, $me, $them);
        if (!$gate['ok']) jsonResponse(['error' => $gate['reason']], $gate['reason'] === 'not_found' ? 404 : 403);

        $body = trim((string)($input['body'] ?? ''));
        // How fast (1.71.0): the site's one anti-spam layer (includes/antispam.php, context `message`) — paced
        // by the conversations somebody STARTS (a first message in a thread with none), with an hour's and a
        // day's limit of them (much tighter for a new account); the same words again to the same person, or to
        // too many people at once, refused. Inside this transaction: the count above and the insert below are
        // one step with the layer's own. A refusal COMMITS what the layer counted (nothing else is written by
        // then) — the refusals in a row and a CAPTCHA now due must outlive the answer.
        $tid0 = (int)$them['id'];
        $thread0 = pmThreadFor($db, $uid, $tid0, false);
        $newConversation = true;
        if ($thread0) {
            $has = $db->prepare("SELECT 1 FROM user_messages WHERE thread_id = ? LIMIT 1");
            $has->execute([(int)$thread0['id']]);
            $newConversation = !$has->fetchColumn();
        }
        $as = antispamCheck($db, $cfg, 'message', antispamSubject($me, getClientIp($cfg)), $body,
                            ['target' => 'u:' . $tid0, 'new' => $newConversation, 'input' => $input]);
        if (!$as['ok']) {
            if ($db->inTransaction()) $db->commit();
            jsonResponse($as['body'], (int)$as['status']);
        }
        if ($body === '') jsonResponse(['error' => 'empty'], 400);
        $max = pmMaxChars($cfg);
        if (mb_strlen($body) > $max) jsonResponse(['error' => 'too_long', 'limit' => $max], 400);
        // The same two formats the descriptions use, validated by the same validator: a message is
        // a stranger's text and there is one place in this codebase that knows what may pass.
        $format = (string)($input['format'] ?? 'bbcode');
        if (!in_array($format, ['bbcode', 'markdown'], true)) $format = 'bbcode';
        if (function_exists('richtextValidate')) {
            $bad = richtextValidate($body, $format, array_merge($cfg, ['desc_max_chars' => (string)$max]));
            if ($bad !== null) jsonResponse(['error' => 'invalid_body', 'detail' => $bad], 400);
        }

        $tid = (int)$them['id'];
        $thread = pmThreadFor($db, $uid, $tid);
        if (!$thread) jsonResponse(['error' => 'not_found'], 404);
        // Asked BEFORE the insert (1.73.0): was anything of this conversation left for them outside the Trash?
        $theyHadVisible = pmHasVisible($db, $thread, $tid);
        $db->prepare("INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, ?)")
           ->execute([(int)$thread['id'], $uid, $body, $format]);
        // Whose Archive it leaves (1.73.0, pmAfterMessage()): the writer's always — writing in a conversation brings
        // it back to your inbox; the other side's when pm_archive_returns says so (as hiding always did: "I am done
        // with this for now", not "never show me this person again") or when all they had of it was in the Trash.
        // The message's id is above everybody's Trash, so it is shown on its own there, the older part staying put.
        pmAfterMessage($db, $cfg, $thread, $uid, $theyHadVisible);
        antispamRecord($db, $as['ticket']);
        $db->commit();
        // No notification. The message IS the notification: it is counted on the Messages tab and
        // added into the number on the account link, and reading it clears both. A second record of
        // the same event was a number that stayed up after the conversation had been read.
        jsonResponse(['success' => true, 'thread' => (int)$thread['id']]);
    }

    // The names 1.52 – 1.72 used (a page loaded before the update may still send them): the Archive IS what hiding
    // was, and Delete is now the Trash — restorable, or with pm_trash_days 0 exactly what `delete` did.
    if ($op === 'hide') $op = 'archive';
    if ($op === 'delete') $op = 'trash';

    /* ── one conversation's place: read, archive / unarchive, trash / restore / purge (1.73.0) ────────
     *
     * Nothing here removes a message. The Trash and "for good" are two marks on THIS reader's side of the thread
     * (includes/people.php): the other person's copy is untouched, and a reported message stays readable to the
     * panel. Explicit and idempotent, each one — see the header. */
    if (in_array($op, ['read', 'archive', 'unarchive', 'trash', 'restore', 'purge'], true)) {
        $name = trim((string)($input['with'] ?? ''));
        $them = userValidUsername($name) ? userFindByLogin($db, $name) : null;
        if (!$them) jsonResponse(['error' => 'not_found'], 404);
        $thread = pmThreadFor($db, $uid, (int)$them['id'], false);
        if (!$thread || !pmInThread($thread, $uid)) jsonResponse(['error' => 'not_found'], 404);
        $num = static fn(string $k): ?int => (isset($input[$k]) && is_numeric($input[$k])) ? max(0, (int)$input[$k]) : null;
        $out = ['success' => true, 'op' => $op, 'with' => (string)$them['username']];
        if ($op === 'read') {
            // What this reader is shown, and nothing in their Trash (pmMarkRead()).
            $out['changed'] = pmMarkRead($db, $thread, $uid, 'live') > 0;
        } elseif ($op === 'archive' || $op === 'unarchive') {
            $out['changed'] = pmSetArchived($db, $thread, $uid, $op === 'archive');
        } elseif ($op === 'trash') {
            // `upto`: the last message the page had — one that arrived after it last looked is not what was chosen.
            $out += pmTrash($db, $cfg, $thread, $uid, $num('upto'));
        } elseif ($op === 'restore') {
            // An Undo of a Trash sends what that Trash answered: to = its `was`, from = its `upto`.
            $out += pmRestore($db, $thread, $uid, $num('to'), $num('from'));
        } else {
            $out['changed'] = pmPurge($db, $thread, $uid);
            $out['final'] = true;
        }
        $now = pmThreadFor($db, $uid, (int)$them['id'], false) ?: $thread;
        $out['state'] = $stateTimes(pmThreadState($db, $cfg, $now, $uid));
        $out['counts'] = pmBoxCounts($db, $uid);
        $out['unread'] = pmUnreadCount($db, $uid);
        $out['trash_days'] = pmTrashDays($cfg);
        jsonResponse($out);
    }

    if ($op === 'empty_trash') {
        $n = pmEmptyTrash($db, $uid);
        jsonResponse(['success' => true, 'op' => $op, 'changed' => $n > 0, 'purged' => $n, 'final' => true,
                      'counts' => pmBoxCounts($db, $uid), 'unread' => pmUnreadCount($db, $uid)]);
    }

    if ($op === 'report') {
        if (!userCan($db, $cfg, 'pm.report')) jsonResponse(['error' => 'no_permission'], 403);
        $mid = (int)($input['message'] ?? 0);
        $st = $db->prepare("SELECT m.*, t.u_low, t.u_high FROM user_messages m
                             JOIN message_threads t ON t.id = m.thread_id WHERE m.id = ? LIMIT 1");
        $st->execute([$mid]);
        $msg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$msg || !pmInThread($msg, $uid)) jsonResponse(['error' => 'not_found'], 404);
        // Reporting your own message is not a thing to support: the queue is for what somebody else
        // sent you, and letting an account file against itself is a way to waste a moderator's time.
        if ((int)$msg['sender_id'] === $uid) jsonResponse(['error' => 'own_message'], 400);
        // How fast (1.71.0): a report of a message passes the one limit every report passes — the anti-spam
        // layer's `report` context and the hour's limit (contentReportFloodCheck(), includes/reports.php).
        $rticket = null;
        $flood = contentReportFloodCheck($db, $cfg, $me, getClientIp($cfg), $input, $rticket);
        if ($flood !== null) {
            if (isset($flood['body'])) jsonResponse($flood['body'], (int)$flood['status']);
            jsonResponse(['error' => 'rate_limit', 'retry_after' => (int)($flood['retry_after'] ?? 3600)], 429);
        }
        // The one before it, for context — and NOTHING else travels with the report.
        $ctx = $db->prepare("SELECT id FROM user_messages WHERE thread_id = ? AND id < ? ORDER BY id DESC LIMIT 1");
        $ctx->execute([(int)$msg['thread_id'], $mid]);
        $ctxId = $ctx->fetchColumn();
        $db->prepare("INSERT INTO message_reports (message_id, context_id, thread_id, reporter_id, reported_user_id, reason)
                      VALUES (?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE reason = VALUES(reason)")
           ->execute([$mid, $ctxId ?: null, (int)$msg['thread_id'], $uid, (int)$msg['sender_id'],
                      mb_substr(trim((string)($input['reason'] ?? '')), 0, 500)]);
        $db->prepare("UPDATE user_messages SET reported = 1 WHERE id = ?")->execute([$mid]);
        antispamRecord($db, $rticket);
        auditLog($db, 'pm.report', ['target_type' => 'user', 'target_id' => (int)$msg['sender_id'],
            'summary' => $me['username'] . ' → message #' . $mid]);
        jsonResponse(['success' => true, 'reported' => $mid]);
    }

    if ($op === 'typing') {
        // Cheapest write in the application: one upsert, no history, and it expires by itself.
        // Gated the same way sending is — somebody who may not write here may not say they are
        // writing here — and rate-limited, because it is a POST that a keyboard can produce.
        if (!pmTypingEnabled($cfg)) jsonResponse(['success' => true, 'typing' => false]);
        if (!rateLimitAllow('pmtyping', ipBucket(getClientIp($cfg)), 120, 60)) {
            jsonResponse(['success' => true, 'typing' => false]);
        }
        $them = userValidUsername((string)($input['with'] ?? '')) ? userFindByLogin($db, (string)$input['with']) : null;
        if (!$them) jsonResponse(['error' => 'not_found'], 404);
        $gate = pmCanWrite($db, $cfg, $me, $them);
        if (!$gate['ok']) jsonResponse(['error' => $gate['reason']], 403);
        $thread = pmThreadFor($db, $uid, (int)$them['id'], false);
        if ($thread) pmTypingTouch($db, $cfg, (int)$thread['id'], $uid);
        jsonResponse(['success' => true, 'typing' => true]);
    }

    jsonResponse(['error' => 'unknown_op'], 400);
}

/* ─────────────────────────────── GET ────────────────────────────────────── */

// "May I write to them?" — asked by the profile before it draws a button, so the button can say
// what will happen instead of finding out after somebody has typed a paragraph.
// Let go of the session lock before any of the read paths. PHP serialises requests from one
// browser on the session file, so a conversation polling every few seconds would hold up every
// other request that browser makes. Nothing below this line writes to the session.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

$can = trim((string)($_GET['can'] ?? ''));
if ($can !== '') {
    $them = userValidUsername($can) ? userFindByLogin($db, $can) : null;
    $gate = pmCanWrite($db, $cfg, $me, $them);
    jsonResponse(['success' => true, 'ok' => $gate['ok'], 'reason' => $gate['reason'],
                  'max_chars' => pmMaxChars($cfg)]);
}

/* ── the poll ──────────────────────────────────────────────────────────────────────────────────
 *
 * What an OPEN conversation asks every few seconds: has anything arrived, has the other side read
 * what I said, and are they writing. It answers with NEW ROWS ONLY — `after` is the last id the
 * page already has — so the usual reply is three small numbers and an empty list.
 *
 * Deliberately narrow. It does not re-send the conversation, does not re-check who may write (the
 * page has that from opening it), and does not exist at all while `pm_live_seconds` is 0: an
 * operator who has not asked for this pays nothing for it.
 */
if ((string)($_GET['poll'] ?? '') === '1') {
    if (pmLiveSeconds($cfg) < 1) jsonResponse(['success' => true, 'off' => true, 'rows' => []]);
    if (!rateLimitAllow('pmpoll', ipBucket(getClientIp($cfg)), 600, 60)) {
        jsonResponse(['error' => 'rate_limit', 'retry_after' => 60], 429);
    }
    $with = trim((string)($_GET['with'] ?? ''));
    $after = max(0, (int)($_GET['after'] ?? 0));
    // ── no conversation named: the INBOX is asking ────────────────────────────────────────────
    //
    // A list of conversations goes stale exactly as fast as an open one does, and the person
    // looking at it is looking at it. So it gets the same courtesy for less: two facts, neither of
    // them a row — the moment of the newest line anywhere in this inbox (see pmInboxStamp), and how
    // many are unread. The page redraws the list only when the first has moved from what it drew.
    if ($with === '') {
        jsonResponse(['success' => true, 'inbox' => true,
                      'stamp' => pmInboxStamp($db, $uid), 'unread' => pmUnreadCount($db, $uid),
                      'unread_friend' => pmUnreadCountFriends($db, $uid)]);
    }
    $them = userValidUsername($with) ? userFindByLogin($db, $with) : null;
    if (!$them) jsonResponse(['error' => 'not_found'], 404);
    $thread = pmThreadFor($db, $uid, (int)$them['id'], false);
    if (!$thread) jsonResponse(['success' => true, 'rows' => [], 'typing' => false, 'unread' => 0, 'read_upto' => 0]);

    // v70: never below MY watermark — whatever `after` says, a conversation I deleted starts again
    // at the first message that arrived after I deleted it. 1.73.0: nor below my Trash (pmThreadMessages()'s
    // 'live' part — the floor is pmVisibleFrom()): what I put there stays there until I restore it.
    $fresh = pmThreadMessages($db, $thread, $uid, 'live', $after, 50);
    // Arriving in front of somebody who is looking at the conversation is the same as opening it:
    // they have read it. Only written when something actually came in — and only what they are shown.
    $incoming = array_filter($fresh, static fn($m) => (int)$m['sender_id'] !== $uid);
    if ($incoming) pmMarkRead($db, $thread, $uid, 'live');
    // …and the other half of the same courtesy: how far the other side has read MY side.
    $rd = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM user_messages WHERE thread_id = ? AND sender_id = ? AND read_at IS NOT NULL");
    $rd->execute([(int)$thread['id'], $uid]);

    jsonResponse([
        'success'   => true,
        // The tabs' numbers (1.73.0) — only when something came in and was read here, which is when they move.
        'counts'    => $incoming ? pmBoxCounts($db, $uid) : null,
        'rows'      => array_map(static fn($m) => [
            'id'      => (int)$m['id'],
            'mine'    => (int)$m['sender_id'] === $uid,
            'html'    => pmRenderBody((string)$m['body'], (string)$m['body_format'], $cfg, true, $db),
            'created' => (string)$m['created_at'],
            'ts'      => is_numeric($m['created_ts'] ?? null) ? (int)$m['created_ts'] : null,
            'time'    => pmReaderTime($m['created_ts'] ?? null, $readerTz),
            'read'    => $m['read_at'] !== null,
            'reported' => (int)$m['reported'] === 1,
        ], $fresh),
        'typing'    => pmSomeoneTyping($db, $cfg, (int)$thread['id'], (int)$them['id']),
        'read_upto' => (int)$rd->fetchColumn(),
        'unread'    => pmUnreadCount($db, $uid),
    ]);
}

/**
 * The picture beside a name (1.63.0): an ADDRESS built here from the row already read — the inbox's
 * join, or the account the conversation is with — never the id this endpoint deliberately keeps to
 * itself. '' while pictures are switched off, which the page reads as "draw nothing".
 */
$pmFace = static fn(array $u): string => function_exists('userAvatarField')
    ? userAvatarField(['username' => (string)($u['username'] ?? ''), 'avatar_sha' => $u['avatar_sha'] ?? null], 32, getBaseUrl(), $cfg)
    : '';

$with = trim((string)($_GET['with'] ?? ''));
if ($with !== '') {
    $them = userValidUsername($with) ? userFindByLogin($db, $with) : null;
    if (!$them) jsonResponse(['error' => 'not_found'], 404);
    // Hidden from this reader by a block: the same not-found the profile gives, old lines included —
    // see pmCanWrite().
    $hid = blockRow($db, (int)$them['id'], $uid);
    if ($hid !== null && !empty($hid['hide_profile'])) jsonResponse(['error' => 'not_found'], 404);
    $thread = pmThreadFor($db, $uid, (int)$them['id'], false);
    if (!$thread) {
        // No conversation yet is not an error: it is an empty one, and the page needs to know
        // whether it may start it.
        $gate = pmCanWrite($db, $cfg, $me, $them);
        jsonResponse(['success' => true, 'with' => (string)$them['username'], 'with_avatar' => $pmFace($them), 'rows' => [],
                      'can_write' => $gate['ok'], 'reason' => $gate['reason'], 'unread' => pmUnreadCount($db, $uid),
                      'part' => 'live', 'state' => ['place' => 'none', 'archived' => false, 'live' => false, 'trash' => 0,
                                                     'upto' => 0, 'trashed_at' => null, 'until' => null,
                                                     'trashed_ts' => null, 'until_ts' => null,
                                                     'trashed_time' => '', 'until_time' => ''],
                      'trash_days' => pmTrashDays($cfg)]);
    }
    // WHICH PART (1.73.0). What is not in this reader's Trash — the Inbox's or the Archive's conversation — unless
    // the Trash is asked for (its view opens its conversations so), or unless the Trash is all there is: a
    // conversation opened from somebody's profile while it lies in the Trash shows what lies there, under a bar
    // that says so and offers it back, rather than an empty page that suggests it is gone.
    $state = $stateTimes(pmThreadState($db, $cfg, $thread, $uid));
    $part = ((string)($_GET['part'] ?? '') === 'trash' && $state['trash'] > 0) || (!$state['live'] && $state['trash'] > 0) ? 'trash' : 'live';
    // v70: never what is under MY watermark — a conversation this reader deleted opens empty until something new
    // arrives in it, and then shows only that. Read marks: only the part on the screen (pmMarkRead()).
    pmMarkRead($db, $thread, $uid, $part);
    $rows = [];
    foreach (pmThreadMessages($db, $thread, $uid, $part) as $m) {
        $rows[] = [
            'id'       => (int)$m['id'],
            'mine'     => (int)$m['sender_id'] === $uid,
            'html'     => pmRenderBody((string)$m['body'], (string)$m['body_format'], $cfg, true, $db),
            'created'  => (string)$m['created_at'],
            'ts'       => is_numeric($m['created_ts'] ?? null) ? (int)$m['created_ts'] : null,
            'time'     => pmReaderTime($m['created_ts'] ?? null, $readerTz),
            'read'     => $m['read_at'] !== null,
            'reported' => (int)$m['reported'] === 1,
        ];
    }
    $gate = pmCanWrite($db, $cfg, $me, $them);
    jsonResponse(['success' => true, 'with' => (string)$them['username'], 'with_avatar' => $pmFace($them), 'rows' => $rows,
                  'can_write' => $gate['ok'], 'reason' => $gate['reason'],
                  'may_report' => userCan($db, $cfg, 'pm.report'),
                  'live' => pmLiveSeconds($cfg), 'typing_on' => pmTypingEnabled($cfg),
                  'unread' => pmUnreadCount($db, $uid),
                  // Where it is (1.73.0): the part shown, the place (inbox / archive / trash), what its Trash holds —
                  // and the three places' sizes after this opening read what it showed (an Archive tab's unread pill).
                  'part' => $part, 'state' => $state, 'trash_days' => pmTrashDays($cfg),
                  'counts' => pmBoxCounts($db, $uid)]);
}

/* ── the inbox, the Archive, the Trash ─────────────────────────────────────────────────────────
 *
 * One row per conversation, with the last line of it and how many are waiting — the two facts
 * somebody scans an inbox for. `view` (1.73.0) is which of the three places: each lists, previews,
 * counts and searches ITS part only (pmListThreads(): the Inbox and the Archive above the reader's
 * floor — neither deleted nor in the Trash —, the Trash its own range), and every answer carries the
 * three places' sizes for the tabs above the list.
 *
 * `search` filters by the other person's name, which the browser could do by itself. `deep=1` also
 * looks INSIDE this reader's own conversations, which it could not: that is a LIKE over
 * `user_messages`, so it is opt-in, needs two characters, and costs one of a small budget per
 * address. It reads only threads this account is in, and only the part of each that the place on the
 * screen shows (pmDeepSearchIds()): a word in a message this reader deleted is no hit anywhere, a word
 * in the Trash is a hit in the Trash only.
 */
$view = (string)($_GET['view'] ?? 'inbox');
if (!in_array($view, ['inbox', 'archive', 'trash'], true)) $view = 'inbox';
$search = trim((string)($_GET['search'] ?? ''));
$deep   = (string)($_GET['deep'] ?? '') === '1' && mb_strlen($search) >= 2;
$deepIds = null;
if ($deep) {
    if (!rateLimitAllow('pmsearch', ipBucket(getClientIp($cfg)), 60, 60)) {
        jsonResponse(['error' => 'rate_limit', 'retry_after' => 60], 429);
    }
    // A deep search that found nothing returns nothing, not everything: [] is "no rows" to pmListThreads().
    $deepIds = pmDeepSearchIds($db, $uid, $view, $search);
}
$threads = [];
foreach (pmListThreads($db, $uid, $view, $deepIds) as $t) {
    // A PREVIEW, not the message: the markup is rendered when a conversation is opened, and an
    // inbox line is a plain-text reminder of what was said.
    $preview = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($t['last_body'] ?? '')))) ?: '';
    $row = [
        'with'    => (string)$t['other_name'],
        'avatar'  => $pmFace(['username' => $t['other_name'], 'avatar_sha' => $t['other_avatar_sha']]),
        'unread'  => (int)($t['unread'] ?? 0),
        'last_at' => (string)($view === 'trash' ? $t['last_at'] : $t['last_message_at']),
        'last_time' => pmReaderTime($view === 'trash' ? ($t['last_ts'] ?? null) : ($t['last_message_ts'] ?? null), $readerTz),
        'mine'    => (int)$t['last_sender'] === $uid,
        'preview' => mb_substr($preview, 0, 140),
        // The last message of the part listed: what a Delete from this row moves into the Trash, and no further.
        'last_id' => (int)$t['last_id'],
    ];
    if ($view === 'trash') {
        $row['n'] = (int)$t['n'];
        $row['trashed_at'] = (string)$t['trashed_at'];
        $row['until'] = pmTrashUntil($cfg, $t['trashed_at']);
        $tts = is_numeric($t['trashed_ts'] ?? null) ? (int)$t['trashed_ts'] : null;
        $row['trashed_time'] = pmReaderTime($tts, $readerTz);
        $row['until_time'] = pmReaderTime($tts !== null ? $tts + pmTrashDays($cfg) * 86400 : null, $readerTz);
    }
    $threads[] = $row;
}
// The stamp goes out with the list itself: the baseline the poll compares against has to be the
// moment THIS list was built, or everything that arrives before the first tick is never drawn.
jsonResponse(['success' => true, 'view' => $view, 'threads' => $threads, 'deep' => $deep, 'live' => pmLiveSeconds($cfg),
              'stamp' => pmInboxStamp($db, $uid), 'unread' => pmUnreadCount($db, $uid),
              'counts' => pmBoxCounts($db, $uid), 'trash_days' => pmTrashDays($cfg),
              'archive_returns' => pmArchiveReturns($cfg),
              'max_chars' => pmMaxChars($cfg), 'max_per_day' => pmMaxPerDay($cfg),
              'sent_today' => pmSentToday($db, $uid)]);
