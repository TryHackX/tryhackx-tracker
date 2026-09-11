<?php
/**
 * People reaching each other: following, friendship, blocks, private messages, and the directory.
 *
 * ── one idea, four tables, and the rules that connect them ─────────────────────────────────────
 *
 * FOLLOWING AND FRIENDSHIP ARE ONE ROW. `user_friends` holds "A asked B", and until B answers it
 * means A follows B; accepted, the pair are friends. That is the operator's own decision — a
 * one-sided follow is useful on its own, and a friendship that exists in one direction and not the
 * other is a bug waiting to be written.
 *
 * A BLOCK IS ONE-DIRECTIONAL and says what it blocks. `hide_profile` is the second half: some
 * people want the messages to stop, some want to disappear. It is checked when somebody WRITES,
 * never when a message is displayed — a blocked person is told they are blocked (that is what the
 * operator asked for) rather than left shouting into a room nobody is in.
 *
 * A THREAD IS A PAIR OF ACCOUNTS. Two people have one conversation here, and `u_low`/`u_high` are
 * their ids sorted, so the same row is found whoever opens it.
 *
 * WHO MAY WRITE TO ME is answered by the reader's own `users.pm_who` when they have set one, and by
 * the site's `pm_who` when they have not. NULL is not a fourth value: it means "whatever the site
 * says", so an operator changing the default changes it for everybody who never expressed a
 * preference and overrules nobody who did.
 */

/* ── the switches ─────────────────────────────────────────────────────────── */

/** Private messages exist at all. Off, every endpoint answers 404 and no page draws an inbox. */
function pmEnabled(array $cfg): bool
{
    return usersEnabled($cfg) && (($cfg['pm_enabled'] ?? '0') === '1');
}

/** Following and friendship. Messages can run without it — then `pm_who = friends` means nobody. */
function friendsEnabled(array $cfg): bool
{
    return usersEnabled($cfg) && (($cfg['friends_enabled'] ?? '0') === '1');
}

/** The browsable list of members who asked to be on it. */
function directoryEnabled(array $cfg): bool
{
    return usersEnabled($cfg) && (($cfg['directory_enabled'] ?? '0') === '1');
}

/** The site-wide default for "who may write to me": all | friends | nobody. */
function pmDefaultWho(array $cfg): string
{
    $v = (string)($cfg['pm_who'] ?? 'friends');
    return in_array($v, ['all', 'friends', 'nobody'], true) ? $v : 'friends';
}

/** What THIS account has chosen, or the site default where they have not chosen. */
function pmWhoFor(array $cfg, array $user): string
{
    $v = (string)($user['pm_who'] ?? '');
    return in_array($v, ['all', 'friends', 'nobody'], true) ? $v : pmDefaultWho($cfg);
}

function pmMaxPerDay(array $cfg): int { return max(1, min(1000, (int)($cfg['pm_max_per_day'] ?? 50) ?: 50)); }
function pmMaxChars(array $cfg): int  { return max(200, min(20000, (int)($cfg['pm_max_chars'] ?? 4000) ?: 4000)); }

/**
 * How often an OPEN conversation asks whether anything arrived. 0 = never; the page stays as it was
 * until somebody reloads it, which is what every install does until an operator says otherwise.
 *
 * Clamped at two seconds because that is the floor at which this stops being a refresh and starts
 * being a load: one request per reader per interval, and the reply is empty unless something was
 * actually said.
 */
function pmLiveSeconds(array $cfg): int
{
    $v = (int)($cfg['pm_live_seconds'] ?? 0);
    return $v <= 0 ? 0 : max(2, min(60, $v));
}

/** The "…is writing" line. Its own switch: it costs a write every few keystrokes. */
function pmTypingEnabled(array $cfg): bool
{
    return pmLiveSeconds($cfg) > 0 && (($cfg['pm_typing_enabled'] ?? '0') === '1');
}

/**
 * Is this account silenced, and until when? NULL when it is not.
 *
 * A moment rather than a flag, and read rather than swept: a mute that has passed is simply a date
 * in the past, so nothing has to run for it to end. The janitor tidies the column later because a
 * stale value is confusing to READ, not because it would still be in force.
 */
function pmMutedUntil(?array $user): ?string
{
    if (!$user) return null;
    $until = $user['pm_muted_until'] ?? null;
    if ($until === null || $until === '') return null;
    return strtotime((string)$until) > time() ? (string)$until : null;
}

/** How long one keystroke keeps somebody "writing" — a little longer than the poll interval. */
function pmTypingWindow(array $cfg): int { return max(4, min(20, pmLiveSeconds($cfg) * 2 + 2)); }

/**
 * Somebody pressed a key. One upsert, no history, and the row means nothing a second after `until`.
 *
 * Deliberately not "started typing" / "stopped typing" events: a stop that never arrives (a closed
 * tab, a dropped connection, a phone that slept) would leave the other person watching a line that
 * is not true. An expiry cannot be forgotten.
 */
function pmTypingTouch(PDO $db, array $cfg, int $threadId, int $userId): void
{
    if (!pmTypingEnabled($cfg)) return;
    try {
        $db->prepare("INSERT INTO message_typing (thread_id, user_id, until)
                      VALUES (?, ?, NOW() + INTERVAL ? SECOND)
                      ON DUPLICATE KEY UPDATE until = VALUES(until)")
           ->execute([$threadId, $userId, pmTypingWindow($cfg)]);
    } catch (\Throwable $e) { /* a database that predates v54: the line simply never appears */ }
}

/** Is the OTHER person writing? One primary-key lookup. */
function pmSomeoneTyping(PDO $db, array $cfg, int $threadId, int $otherId): bool
{
    if (!pmTypingEnabled($cfg)) return false;
    try {
        $st = $db->prepare("SELECT 1 FROM message_typing WHERE thread_id = ? AND user_id = ? AND until > NOW() LIMIT 1");
        $st->execute([$threadId, $otherId]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) { return false; }
}

/** Rows whose moment has passed. Called from the janitor; a minute of slack costs nothing. */
function pmTypingPrune(PDO $db): int
{
    try {
        $st = $db->prepare("DELETE FROM message_typing WHERE until < NOW() - INTERVAL 1 MINUTE LIMIT 500");
        $st->execute();
        return $st->rowCount();
    } catch (\Throwable $e) { return 0; }
}

/* ── friendship and following ─────────────────────────────────────────────── */

/**
 * What A is to B, from A's side: 'none' | 'following' | 'follower' | 'friends'.
 *
 * One query over a unique key in each direction. `following` means A asked and B has not answered;
 * `follower` means B asked A — which is the row A needs to see in order to answer it.
 */
function friendState(PDO $db, int $me, int $them): string
{
    if ($me <= 0 || $them <= 0 || $me === $them) return 'none';
    $st = $db->prepare("SELECT user_id, friend_id, status FROM user_friends
                         WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)");
    $st->execute([$me, $them, $them, $me]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['status'] === 'accepted') return 'friends';
        return (int)$r['user_id'] === $me ? 'following' : 'follower';
    }
    return 'none';
}

/** Are these two friends? The question the message gate asks most often. */
function areFriends(PDO $db, int $a, int $b): bool
{
    if ($a <= 0 || $b <= 0 || $a === $b) return false;
    $st = $db->prepare("SELECT 1 FROM user_friends
                         WHERE status = 'accepted'
                           AND ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)) LIMIT 1");
    $st->execute([$a, $b, $b, $a]);
    return (bool)$st->fetchColumn();
}

/* ── blocks ───────────────────────────────────────────────────────────────── */

/** Has $owner blocked $other? Returns the row (so `hide_profile` travels with it) or null. */
function blockRow(PDO $db, int $owner, int $other): ?array
{
    if ($owner <= 0 || $other <= 0) return null;
    $st = $db->prepare("SELECT * FROM user_blocks WHERE user_id = ? AND blocked_id = ? LIMIT 1");
    $st->execute([$owner, $other]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * May $viewer see $owner's profile at all?
 *
 * Only a block WITH `hide_profile` closes it, and only in that one direction: blocking somebody to
 * stop their messages is not the same as leaving the room, and conflating the two would take a
 * decision away from the person making it.
 */
function profileHiddenFrom(PDO $db, int $ownerId, int $viewerId): bool
{
    $b = blockRow($db, $ownerId, $viewerId);
    return $b !== null && (int)$b['hide_profile'] === 1;
}

/* ── may I write to you ───────────────────────────────────────────────────── */

/**
 * Everything about one attempt to write, as one answer.
 *
 *   ok        — the message may be sent
 *   reason    — why not: 'disabled' | 'self' | 'no_permission' | 'blocked' | 'friends_only' |
 *               'nobody' | 'not_found' | 'rate'
 *
 * A BLOCKED SENDER IS TOLD. That is the operator's decision and it is written here rather than left
 * to a page: silently swallowing a message teaches somebody that the other person is ignoring them,
 * which is a worse thing to believe than the truth and takes longer to find out.
 */
function pmCanWrite(PDO $db, array $cfg, array $sender, ?array $target): array
{
    if (!pmEnabled($cfg)) return ['ok' => false, 'reason' => 'disabled'];
    if (!$target) return ['ok' => false, 'reason' => 'not_found'];
    $sid = (int)$sender['id'];
    $tid = (int)$target['id'];
    if ($sid === $tid) return ['ok' => false, 'reason' => 'self'];
    if (($target['status'] ?? '') !== 'active') return ['ok' => false, 'reason' => 'not_found'];
    // The SENDER's permission, asked about the sender — not userCan(), which answers about whoever
    // is making the request. They are the same person when this runs behind the endpoint, and are
    // not the same person anywhere else: a CLI test has no session at all, and a panel session
    // answers yes to everything. A gate that cannot be asked about a named account is a gate that
    // cannot be tested.
    if (!userIdHasPermission($db, $cfg, $sid, 'pm.send')) return ['ok' => false, 'reason' => 'no_permission'];
    // Silenced by a moderator. Asked about the SENDER and before anything about the recipient: a
    // mute is about this account writing at all, not about who it is writing to — and the person
    // is told, because a message that vanishes teaches somebody that the site is broken.
    if (pmMutedUntil($sender) !== null) return ['ok' => false, 'reason' => 'muted'];
    if (blockRow($db, $tid, $sid) !== null) return ['ok' => false, 'reason' => 'blocked'];
    // Blocking somebody also stops YOU writing to THEM: a one-way conversation with somebody you
    // have blocked is not something to offer, and the reply could never arrive.
    if (blockRow($db, $sid, $tid) !== null) return ['ok' => false, 'reason' => 'you_blocked'];
    $who = pmWhoFor($cfg, $target);
    if ($who === 'nobody') return ['ok' => false, 'reason' => 'nobody'];
    if ($who === 'friends' && !areFriends($db, $sid, $tid)) return ['ok' => false, 'reason' => 'friends_only'];
    return ['ok' => true, 'reason' => ''];
}

/** How many messages this account has sent since midnight — the per-day ceiling's own question. */
function pmSentToday(PDO $db, int $userId): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM user_messages WHERE sender_id = ? AND created_at >= CURDATE()");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/* ── threads ──────────────────────────────────────────────────────────────── */

/** The thread these two share, made if it does not exist yet. Ids sorted, so there is only ever one. */
function pmThreadFor(PDO $db, int $a, int $b, bool $create = true): ?array
{
    $low = min($a, $b); $high = max($a, $b);
    $st = $db->prepare("SELECT * FROM message_threads WHERE u_low = ? AND u_high = ? LIMIT 1");
    $st->execute([$low, $high]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if ($t) return $t;
    if (!$create) return null;
    $db->prepare("INSERT INTO message_threads (u_low, u_high) VALUES (?, ?)")->execute([$low, $high]);
    $st->execute([$low, $high]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Is this account one of the two people in this thread? The only membership question there is. */
function pmInThread(array $thread, int $userId): bool
{
    return (int)$thread['u_low'] === $userId || (int)$thread['u_high'] === $userId;
}

/** The other person's id in a thread. */
function pmOtherId(array $thread, int $userId): int
{
    return (int)$thread['u_low'] === $userId ? (int)$thread['u_high'] : (int)$thread['u_low'];
}

/** How many messages are waiting for this account, across every thread. */
function pmUnreadCount(PDO $db, int $userId): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM user_messages m
                          JOIN message_threads t ON t.id = m.thread_id
                         WHERE m.sender_id <> ? AND m.read_at IS NULL
                           AND ((t.u_low = ? AND t.u_low_hidden = 0) OR (t.u_high = ? AND t.u_high_hidden = 0))");
    $st->execute([$userId, $userId, $userId]);
    return (int)$st->fetchColumn();
}

/**
 * The moment of the newest line anywhere in this reader's inbox — the one fact that says whether a
 * drawn list is still the truth.
 *
 * Two lookups rather than one with OR: `idx_thread_low` and `idx_thread_high` each answer
 * MAX(last_message_at) for one constant from the end of the index, and an OR across both indexes is
 * the shape that has cost this project a 500 before. The two are compared as strings, which for
 * 'Y-m-d H:i:s' is the same order as time.
 *
 * It is handed out BOTH by the drawing of the list and by the poll that watches it, because the
 * only honest baseline is the moment the list on the screen was built. Letting the first tick set
 * the baseline instead loses everything that arrived between the draw and that tick.
 */
function pmInboxStamp(PDO $db, int $userId): string
{
    $one = $db->prepare("SELECT MAX(last_message_at) FROM message_threads WHERE u_low = ? AND u_low_hidden = 0");
    $one->execute([$userId]);
    $a = (string)($one->fetchColumn() ?: '');
    $two = $db->prepare("SELECT MAX(last_message_at) FROM message_threads WHERE u_high = ? AND u_high_hidden = 0");
    $two->execute([$userId]);
    $b = (string)($two->fetchColumn() ?: '');
    return $a > $b ? $a : $b;
}

/**
 * Everything a page needs about one reader and this whole area, in one call.
 *
 * Same shape as favContext() and listsContext() — a page that reassembles gates is a page that gets
 * one of them wrong.
 */
function peopleContext(PDO $db, array $cfg, ?array $viewer): array
{
    $signed = $viewer !== null;
    $pm = pmEnabled($cfg);
    return [
        'pm'            => $pm,
        'friends'       => friendsEnabled($cfg),
        'directory'     => directoryEnabled($cfg),
        'may_message'   => $pm && $signed && userCan($db, $cfg, 'pm.send'),
        'may_report'    => $pm && $signed && userCan($db, $cfg, 'pm.report'),
        'may_friend'    => friendsEnabled($cfg) && $signed && userCan($db, $cfg, 'friends.use'),
        'may_directory' => directoryEnabled($cfg) && $signed && userCan($db, $cfg, 'directory.view'),
        'who_default'   => pmDefaultWho($cfg),
        'my_who'        => $signed ? pmWhoFor($cfg, $viewer) : pmDefaultWho($cfg),
        'max_chars'     => pmMaxChars($cfg),
        'max_per_day'   => pmMaxPerDay($cfg),
        'live_seconds'  => pmLiveSeconds($cfg),
        'typing'        => pmTypingEnabled($cfg),
        'unread'        => ($pm && $signed) ? pmUnreadCount($db, (int)$viewer['id']) : 0,
    ];
}

/**
 * One message, rendered the way a description is rendered.
 *
 * The same sanitizer the public descriptions use, and for the same reason: a message is a stranger's
 * text, and there is exactly one place in this codebase that knows what is safe to let through.
 */
function pmRenderBody(string $body, string $format, array $cfg, bool $signedIn = true): string
{
    if (function_exists('richtextRender')) return richtextRender($body, $format, $cfg, $signedIn);
    return nl2br(sanitize($body));
}
