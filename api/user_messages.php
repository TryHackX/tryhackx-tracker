<?php
/**
 * Private messages: the inbox, one conversation, and what somebody may do with it.
 *
 *   GET  user_messages                       — the inbox: one row per person, newest first
 *   GET  user_messages&with=<name>           — that conversation, oldest first (marks it read)
 *   GET  user_messages&can=<name>            — may I write to them, and if not, why
 *   POST user_messages {op:'send', to:<name>, body, format}
 *   POST user_messages {op:'read'|'hide', with:<name>}
 *   POST user_messages {op:'report', message:<id>, reason}
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
        // per-day one is about an account. Neither alone is enough.
        if (!rateLimitAllow('pmsend', ipBucket(getClientIp($cfg)), (int)($cfg['rate_limit_favourites'] ?? 240), 3600)) {
            jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
        }
        $perDay = pmMaxPerDay($cfg);
        if (pmSentToday($db, $uid) >= $perDay) jsonResponse(['error' => 'day_limit', 'limit' => $perDay], 429);

        $name = trim((string)($input['to'] ?? ''));
        $them = userValidUsername($name) ? userFindByLogin($db, $name) : null;
        $gate = pmCanWrite($db, $cfg, $me, $them);
        if (!$gate['ok']) jsonResponse(['error' => $gate['reason']], $gate['reason'] === 'not_found' ? 404 : 403);

        $body = trim((string)($input['body'] ?? ''));
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
        $db->prepare("INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, ?)")
           ->execute([(int)$thread['id'], $uid, $body, $format]);
        // A thread somebody cleared out comes back for them when it has something new in it —
        // hiding is "I am done with this for now", not "never show me this person again".
        $db->prepare("UPDATE message_threads SET last_message_at = NOW(), u_low_hidden = 0, u_high_hidden = 0 WHERE id = ?")
           ->execute([(int)$thread['id']]);
        // No notification. The message IS the notification: it is counted on the Messages tab and
        // added into the number on the account link, and reading it clears both. A second record of
        // the same event was a number that stayed up after the conversation had been read.
        jsonResponse(['success' => true, 'thread' => (int)$thread['id']]);
    }

    if ($op === 'read' || $op === 'hide') {
        $name = trim((string)($input['with'] ?? ''));
        $them = userValidUsername($name) ? userFindByLogin($db, $name) : null;
        if (!$them) jsonResponse(['error' => 'not_found'], 404);
        $thread = pmThreadFor($db, $uid, (int)$them['id'], false);
        if (!$thread || !pmInThread($thread, $uid)) jsonResponse(['error' => 'not_found'], 404);
        if ($op === 'read') {
            $db->prepare("UPDATE user_messages SET read_at = NOW() WHERE thread_id = ? AND sender_id <> ? AND read_at IS NULL")
               ->execute([(int)$thread['id'], $uid]);
        } else {
            $col = (int)$thread['u_low'] === $uid ? 'u_low_hidden' : 'u_high_hidden';
            $db->prepare("UPDATE message_threads SET `$col` = 1 WHERE id = ?")->execute([(int)$thread['id']]);
        }
        jsonResponse(['success' => true, 'unread' => pmUnreadCount($db, $uid)]);
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
                      'stamp' => pmInboxStamp($db, $uid), 'unread' => pmUnreadCount($db, $uid)]);
    }
    $them = userValidUsername($with) ? userFindByLogin($db, $with) : null;
    if (!$them) jsonResponse(['error' => 'not_found'], 404);
    $thread = pmThreadFor($db, $uid, (int)$them['id'], false);
    if (!$thread) jsonResponse(['success' => true, 'rows' => [], 'typing' => false, 'unread' => 0, 'read_upto' => 0]);

    $st = $db->prepare("SELECT id, sender_id, body, body_format, created_at, read_at, reported
                          FROM user_messages WHERE thread_id = ? AND id > ? ORDER BY id ASC LIMIT 50");
    $st->execute([(int)$thread['id'], $after]);
    $fresh = $st->fetchAll(PDO::FETCH_ASSOC);
    // Arriving in front of somebody who is looking at the conversation is the same as opening it:
    // they have read it. Only written when something actually came in.
    $incoming = array_filter($fresh, static fn($m) => (int)$m['sender_id'] !== $uid);
    if ($incoming) {
        $db->prepare("UPDATE user_messages SET read_at = NOW() WHERE thread_id = ? AND sender_id <> ? AND read_at IS NULL")
           ->execute([(int)$thread['id'], $uid]);
    }
    // …and the other half of the same courtesy: how far the other side has read MY side.
    $rd = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM user_messages WHERE thread_id = ? AND sender_id = ? AND read_at IS NOT NULL");
    $rd->execute([(int)$thread['id'], $uid]);

    jsonResponse([
        'success'   => true,
        'rows'      => array_map(static fn($m) => [
            'id'      => (int)$m['id'],
            'mine'    => (int)$m['sender_id'] === $uid,
            'html'    => pmRenderBody((string)$m['body'], (string)$m['body_format'], $cfg),
            'created' => (string)$m['created_at'],
            'read'    => $m['read_at'] !== null,
            'reported' => (int)$m['reported'] === 1,
        ], $fresh),
        'typing'    => pmSomeoneTyping($db, $cfg, (int)$thread['id'], (int)$them['id']),
        'read_upto' => (int)$rd->fetchColumn(),
        'unread'    => pmUnreadCount($db, $uid),
    ]);
}

$with = trim((string)($_GET['with'] ?? ''));
if ($with !== '') {
    $them = userValidUsername($with) ? userFindByLogin($db, $with) : null;
    if (!$them) jsonResponse(['error' => 'not_found'], 404);
    $thread = pmThreadFor($db, $uid, (int)$them['id'], false);
    if (!$thread) {
        // No conversation yet is not an error: it is an empty one, and the page needs to know
        // whether it may start it.
        $gate = pmCanWrite($db, $cfg, $me, $them);
        jsonResponse(['success' => true, 'with' => (string)$them['username'], 'rows' => [],
                      'can_write' => $gate['ok'], 'reason' => $gate['reason'], 'unread' => pmUnreadCount($db, $uid)]);
    }
    $db->prepare("UPDATE user_messages SET read_at = NOW() WHERE thread_id = ? AND sender_id <> ? AND read_at IS NULL")
       ->execute([(int)$thread['id'], $uid]);
    $st = $db->prepare("SELECT m.id, m.sender_id, m.body, m.body_format, m.created_at, m.read_at, m.reported
                          FROM user_messages m WHERE m.thread_id = ? ORDER BY m.id ASC LIMIT 500");
    $st->execute([(int)$thread['id']]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $rows[] = [
            'id'       => (int)$m['id'],
            'mine'     => (int)$m['sender_id'] === $uid,
            'html'     => pmRenderBody((string)$m['body'], (string)$m['body_format'], $cfg),
            'created'  => (string)$m['created_at'],
            'read'     => $m['read_at'] !== null,
            'reported' => (int)$m['reported'] === 1,
        ];
    }
    $gate = pmCanWrite($db, $cfg, $me, $them);
    jsonResponse(['success' => true, 'with' => (string)$them['username'], 'rows' => $rows,
                  'can_write' => $gate['ok'], 'reason' => $gate['reason'],
                  'may_report' => userCan($db, $cfg, 'pm.report'),
                  'live' => pmLiveSeconds($cfg), 'typing_on' => pmTypingEnabled($cfg),
                  'unread' => pmUnreadCount($db, $uid)]);
}

/* ── the inbox ─────────────────────────────────────────────────────────────────────────────────
 *
 * One row per conversation, with the last line of it and how many are waiting — the two facts
 * somebody scans an inbox for.
 *
 * `search` filters by the other person's name, which the browser could do by itself. `deep=1` also
 * looks INSIDE this reader's own conversations, which it could not: that is a LIKE over
 * `user_messages`, so it is opt-in, needs two characters, and costs one of a small budget per
 * address. It reads only threads this account is in — the WHERE below is the same one the listing
 * uses, not a second opinion about who may read what.
 */
$search = trim((string)($_GET['search'] ?? ''));
$deep   = (string)($_GET['deep'] ?? '') === '1' && mb_strlen($search) >= 2;
$deepIds = null;
if ($deep) {
    if (!rateLimitAllow('pmsearch', ipBucket(getClientIp($cfg)), 60, 60)) {
        jsonResponse(['error' => 'rate_limit', 'retry_after' => 60], 429);
    }
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
    $q = $db->prepare("SELECT DISTINCT m.thread_id FROM user_messages m
                         JOIN message_threads t ON t.id = m.thread_id
                        WHERE (t.u_low = ? OR t.u_high = ?) AND m.body LIKE ?
                        LIMIT 200");
    $q->execute([$uid, $uid, $like]);
    $deepIds = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

// A deep search that found nothing must return nothing, not everything: `IN ()` is not valid SQL,
// so the impossible condition is spelled out rather than left to an empty list quietly vanishing.
$deepWhere = '';
$deepParams = [];
if ($deepIds !== null) {
    $deepWhere = $deepIds ? ' AND t.id IN (' . implode(',', array_fill(0, count($deepIds), '?')) . ')' : ' AND 1 = 0';
    $deepParams = $deepIds;
}
$st = $db->prepare(
    "SELECT t.id, t.last_message_at,
            IF(t.u_low = ?, t.u_high, t.u_low) AS other_id,
            u.username AS other_name,
            (SELECT COUNT(*) FROM user_messages m WHERE m.thread_id = t.id AND m.sender_id <> ? AND m.read_at IS NULL) AS unread,
            (SELECT m2.body FROM user_messages m2 WHERE m2.thread_id = t.id ORDER BY m2.id DESC LIMIT 1) AS last_body,
            (SELECT m3.sender_id FROM user_messages m3 WHERE m3.thread_id = t.id ORDER BY m3.id DESC LIMIT 1) AS last_sender
       FROM message_threads t
       JOIN users u ON u.id = IF(t.u_low = ?, t.u_high, t.u_low)
      WHERE ((t.u_low = ? AND t.u_low_hidden = 0) OR (t.u_high = ? AND t.u_high_hidden = 0))
        AND u.status = 'active'" . $deepWhere . "
      ORDER BY t.last_message_at DESC LIMIT 200");
$st->execute(array_merge([$uid, $uid, $uid, $uid, $uid], $deepParams));
$threads = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
    // A PREVIEW, not the message: the markup is rendered when a conversation is opened, and an
    // inbox line is a plain-text reminder of what was said.
    $preview = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($t['last_body'] ?? '')))) ?: '';
    $threads[] = [
        'with'    => (string)$t['other_name'],
        'unread'  => (int)$t['unread'],
        'last_at' => (string)$t['last_message_at'],
        'mine'    => (int)$t['last_sender'] === $uid,
        'preview' => mb_substr($preview, 0, 140),
    ];
}
// The stamp goes out with the list itself: the baseline the poll compares against has to be the
// moment THIS list was built, or everything that arrives before the first tick is never drawn.
jsonResponse(['success' => true, 'threads' => $threads, 'deep' => $deep, 'live' => pmLiveSeconds($cfg),
              'stamp' => pmInboxStamp($db, $uid), 'unread' => pmUnreadCount($db, $uid),
              'max_chars' => pmMaxChars($cfg), 'max_per_day' => pmMaxPerDay($cfg),
              'sent_today' => pmSentToday($db, $uid)]);
