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

// How fast a message may be sent — the conversations somebody starts, the same words to many people (1.71.0):
// the site's one anti-spam layer, which the messages' endpoint asks wherever it is loaded from.
require_once __DIR__ . '/antispam.php';

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
/**
 * Messages an hour from one ADDRESS GROUP (rate_limit_pm, 1.71.0): the send's ceiling against a script with a
 * list of accounts. It used to read `rate_limit_favourites`, which no setting ever defined — so always 240,
 * the default here. The pace of one account is the anti-spam layer's (includes/antispam.php).
 */
function pmRatePerHour(array $cfg): int { return max(1, min(100000, (int)($cfg['rate_limit_pm'] ?? 240) ?: 240)); }
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
 * Does a new message bring an archived conversation back to the inbox (1.73.0, `pm_archive_returns`)? Yes unless
 * the operator said no: that is what "hide" did from 1.52.0 to 1.72.x. With no, it stays in the Archive, marked
 * unread there — and counted on the badge, whose number the Archive tab then explains (pmBoxCounts()).
 */
function pmArchiveReturns(array $cfg): bool
{
    return (string)($cfg['pm_archive_returns'] ?? '1') !== '0';
}

/**
 * How many days a conversation stays in its Trash before the janitor deletes it for that member (1.73.0,
 * `pm_trash_days`, 30). 0 = no Trash: Delete deletes at once, as it did from 1.64.0 on. At most a year.
 */
function pmTrashDays(array $cfg): int
{
    $v = $cfg['pm_trash_days'] ?? '30';
    return max(0, min(365, is_numeric($v) ? (int)$v : 30));
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
    if (!userIsActive($target)) return ['ok' => false, 'reason' => 'not_found'];
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
    // A block that hides the profile hides the account: the profile page answers not-found for it,
    // and this must not be the cheap way to learn what that page refuses to say.
    $blockedBy = blockRow($db, $tid, $sid);
    if ($blockedBy !== null) return ['ok' => false, 'reason' => !empty($blockedBy['hide_profile']) ? 'not_found' : 'blocked'];
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

/* ── "delete this conversation", which means FOR ME (v70) ─────────────────────────────────────
 *
 * `message_threads` has one row per pair, so a conversation cannot be deleted without deleting
 * somebody else's copy of it. What each side gets instead is a WATERMARK: the id of the last
 * message they had when they pressed delete. Every read path filters `m.id > ` their own — the
 * open conversation, the poll, the inbox list and its preview subqueries, the deep search and both
 * unread counts — so the thread disappears for them and stays whole for the other person, and a
 * new message (a higher id) brings it back showing only what has arrived since.
 *
 * Since 1.73.0 the watermark is what deletes FOR GOOD (Delete forever, Empty the Trash, the Trash
 * running out); Delete itself puts a conversation in the reader's Trash first, and the read paths
 * filter by pmVisibleSql() — the watermark or the Trash's edge, whichever is higher. See the section
 * after these three.
 *
 * Nothing is removed from `user_messages`: a reported message has to stay readable to the panel.
 */

/** The column holding this reader's watermark in this thread. */
function pmClearedCol(array $thread, int $userId): string
{
    return (int)$thread['u_low'] === $userId ? 'u_low_cleared_id' : 'u_high_cleared_id';
}

/** The id below which this reader has deleted their side of this thread. 0 = nothing deleted. */
function pmClearedId(array $thread, int $userId): int
{
    return (int)($thread[pmClearedCol($thread, $userId)] ?? 0);
}

/**
 * The same question as SQL, for a query that has `t` in scope and one `?` to spend on the reader's
 * id: "which watermark is mine in this row". Written once so seven queries cannot disagree.
 */
function pmClearedSql(string $t = 't'): string
{
    return "IF($t.u_low = ?, $t.u_low_cleared_id, $t.u_high_cleared_id)";
}

/* ── Inbox, Archive, Trash: where a conversation is, for each side (1.73.0, schema 90) ───────────
 *
 * Each side of a thread has four columns of its own, and nothing either side does moves the other's:
 *
 *   u_*_hidden       ARCHIVED — the column "hide" wrote since 1.52.0, under its new name. Nothing is deleted.
 *   u_*_cleared_id   the v70 watermark: everything up to it is DELETED for this side, for good.
 *   u_*_trash_upto   the TRASH: everything above the watermark and up to it (0 = an empty trash)…
 *   u_*_trashed_at   …put there at this moment (NULL exactly when trash_upto is 0), so the janitor
 *                    can finish the deletion pm_trash_days later (pmTrashPurgeExpired()).
 *
 * Which gives every read path TWO ranges to choose from, and never a third:
 *
 *   the conversation   `m.id > GREATEST(cleared_id, trash_upto)` — pmVisibleSql(). What the Inbox or, when
 *                      archived, the Archive shows; what the unread counters count; what the poll brings;
 *                      what a search in those two looks through. Wherever v70 filtered `m.id > cleared_id`,
 *                      this is that filter's twin, so what is in the Trash is exactly as absent from them as
 *                      what is deleted.
 *   the Trash          `cleared_id < m.id <= trash_upto` — pmClearedSql() and pmTrashUptoSql(). What the
 *                      Trash view lists, previews and searches, and what a conversation opened there shows.
 *
 * A conversation is in the Inbox (something above the floor, not archived), in the Archive (above the floor,
 * archived), in the Trash (something in the range) — and after a new message in a trashed conversation, in
 * both: the message is above trash_upto, so it opens the conversation again with only itself in it, while
 * the older part waits in the Trash to be restored or to run out.
 *
 * Every operation is EXPLICIT and idempotent — archive, unarchive, trash, restore, purge, empty — never a
 * toggle on the server: a page that is out of date asks for what it believes, and asking for what already
 * is changes nothing. So an Undo is simply the opposite operation, and an Undo that comes too late is a no-op.
 */

/**
 * This reader's four columns in this thread (pmClearedCol()'s rule: picked by comparing the reader's id
 * with the thread's own two, never carried in).
 */
function pmSide(array $thread, int $userId): array
{
    $side = (int)$thread['u_low'] === $userId ? 'u_low' : 'u_high';
    return ['hidden' => $side . '_hidden', 'cleared' => $side . '_cleared_id',
            'upto' => $side . '_trash_upto', 'at' => $side . '_trashed_at'];
}

/** The last message id this reader has in their Trash; 0 = an empty trash. */
function pmTrashUpto(array $thread, int $userId): int
{
    return (int)($thread[pmSide($thread, $userId)['upto']] ?? 0);
}

/** The floor of what this reader is shown of the conversation: neither deleted nor in the Trash above it. */
function pmVisibleFrom(array $thread, int $userId): int
{
    return max(pmClearedId($thread, $userId), pmTrashUpto($thread, $userId));
}

/** Has this reader archived it? */
function pmArchived(array $thread, int $userId): bool
{
    return (int)($thread[pmSide($thread, $userId)['hidden']] ?? 0) === 1;
}

/**
 * pmVisibleFrom() as SQL, for a query with `t` in scope and one `?` to spend on the reader's id — the twin of
 * pmClearedSql() every read path uses instead of it.
 */
function pmVisibleSql(string $t = 't'): string
{
    return "IF($t.u_low = ?, GREATEST($t.u_low_cleared_id, $t.u_low_trash_upto), GREATEST($t.u_high_cleared_id, $t.u_high_trash_upto))";
}

/** pmTrashUpto() as SQL (one `?`: the reader's id). The Trash is `m.id > pmClearedSql() AND m.id <= this`. */
function pmTrashUptoSql(string $t = 't'): string
{
    return "IF($t.u_low = ?, $t.u_low_trash_upto, $t.u_high_trash_upto)";
}

/**
 * Where this conversation is for this reader, read in two index ranges:
 *   place   'inbox' | 'archive' (something shown, archived or not) | 'trash' (only the Trash) | 'none'
 *   live    something above the floor at all
 *   trash   how many messages are in their Trash; upto / trashed_at / until — its edge, when, and when it runs out
 */
function pmThreadState(PDO $db, array $cfg, array $thread, int $userId): array
{
    $cleared = pmClearedId($thread, $userId);
    $upto = pmTrashUpto($thread, $userId);
    $live = $db->prepare("SELECT 1 FROM user_messages WHERE thread_id = ? AND id > ? LIMIT 1");
    $live->execute([(int)$thread['id'], max($cleared, $upto)]);
    $hasLive = (bool)$live->fetchColumn();
    $inTrash = 0;
    if ($upto > $cleared) {
        $n = $db->prepare("SELECT COUNT(*) FROM user_messages WHERE thread_id = ? AND id > ? AND id <= ?");
        $n->execute([(int)$thread['id'], $cleared, $upto]);
        $inTrash = (int)$n->fetchColumn();
    }
    $at = $inTrash ? ($thread[pmSide($thread, $userId)['at']] ?? null) : null;
    $archived = pmArchived($thread, $userId);
    // The same two moments as INSTANTS (1.73.0 part E), asked of the database as the rows' are — it knows the zone
    // its DATETIME was written in — for the reader's clock (pmReaderTime()).
    $atTs = null;
    if ($at !== null && $at !== '') {
        try {
            $q = $db->prepare("SELECT UNIX_TIMESTAMP(?)");
            $q->execute([(string)$at]);
            $v = $q->fetchColumn();
            $atTs = is_numeric($v) ? (int)$v : null;
        } catch (\Throwable $e) { $atTs = null; }
    }
    return [
        'place'      => $hasLive ? ($archived ? 'archive' : 'inbox') : ($inTrash ? 'trash' : 'none'),
        'archived'   => $archived,
        'live'       => $hasLive,
        'trash'      => $inTrash,
        'upto'       => $inTrash ? $upto : 0,
        'trashed_at' => $at !== null ? (string)$at : null,
        'until'      => pmTrashUntil($cfg, $at),
        'trashed_ts' => $atTs,
        'until_ts'   => $atTs !== null ? $atTs + pmTrashDays($cfg) * 86400 : null,
    ];
}

/**
 * A message's moment on the reader's clock (1.73.0 part E): 'Y-m-d H:i' in THEIR zone — users.timezone, the site's
 * otherwise (userDisplayTimezone(), includes/db_clock.php) — from an instant (UNIX_TIMESTAMP() of the column). The
 * messages showed the database session's wall clock, cut to sixteen characters by the page, while the shoutbox (1.62.0)
 * has said its times in the reader's zone; '' for no moment. Digits only, so a live language switch has nothing to say
 * again in it.
 */
function pmReaderTime($ts, DateTimeZone $tz): string
{
    if ($ts === null || $ts === '' || !is_numeric($ts)) return '';
    return userDisplayTime((int)$ts, $tz, 'Y-m-d H:i');
}

/** When something put in the Trash at $at runs out ('Y-m-d H:i:s', PHP's zone = the session's), or null. */
function pmTrashUntil(array $cfg, $at): ?string
{
    if ($at === null || $at === '' || ($ts = strtotime((string)$at)) === false) return null;
    return date('Y-m-d H:i:s', $ts + pmTrashDays($cfg) * 86400);
}

/**
 * The three places' sizes for this reader, and what is waiting unread in the two that are counted — one query.
 * The counterpart's account active, as everywhere here: a conversation nobody can open is not a number.
 *   inbox / archive / trash   conversations there (one with a Trash and a live part is in two)
 *   unread_inbox / unread_archive   unread messages; their sum IS pmUnreadCount() — the badge, explained
 */
function pmBoxCounts(PDO $db, int $userId): array
{
    $st = $db->prepare("SELECT COALESCE(SUM(x.live AND x.h = 0), 0) AS inbox, COALESCE(SUM(x.live AND x.h = 1), 0) AS archive,
                               COALESCE(SUM(x.tr), 0) AS trash,
                               COALESCE(SUM(IF(x.h = 0, x.unread, 0)), 0) AS unread_inbox,
                               COALESCE(SUM(IF(x.h = 1, x.unread, 0)), 0) AS unread_archive
                          FROM (SELECT IF(t.u_low = ?, t.u_low_hidden, t.u_high_hidden) AS h,
                                       EXISTS(SELECT 1 FROM user_messages m WHERE m.thread_id = t.id AND m.id > " . pmVisibleSql() . ") AS live,
                                       EXISTS(SELECT 1 FROM user_messages m WHERE m.thread_id = t.id
                                                 AND m.id > " . pmClearedSql() . " AND m.id <= " . pmTrashUptoSql() . ") AS tr,
                                       (SELECT COUNT(*) FROM user_messages m WHERE m.thread_id = t.id AND m.sender_id <> ?
                                                 AND m.read_at IS NULL AND m.id > " . pmVisibleSql() . ") AS unread
                                  FROM message_threads t
                                  JOIN users u ON u.id = IF(t.u_low = ?, t.u_high, t.u_low)
                                 WHERE (t.u_low = ? OR t.u_high = ?) AND u.status = 'active') x");
    $st->execute(array_fill(0, 9, $userId));
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach (['inbox', 'archive', 'trash', 'unread_inbox', 'unread_archive'] as $k) $out[$k] = (int)($r[$k] ?? 0);
    return $out;
}

/** How many messages are waiting for this account, across every thread. */
function pmUnreadCount(PDO $db, int $userId): int
{
    // The same rule the inbox listing applies: a counterpart that is no longer active (banned,
    // deleted) has no row to open, so its messages must not be a number either — a badge nothing on
    // the page can clear is the bug 1.50.0 was written to remove.
    //
    // 1.73.0: above the reader's floor (pmVisibleSql) — neither deleted (v70) nor in the Trash — and in the
    // Inbox OR the Archive: an archived conversation's unread message is still waiting, the Archive tab carries
    // its number (pmBoxCounts), and opening it there reads it. Until 1.72.x a hidden conversation was not
    // counted — and could not have anything unread in it, because every new message un-hid it; with
    // pm_archive_returns off it stays archived, and a message nobody is told about is the one they miss.
    $st = $db->prepare("SELECT COUNT(*) FROM user_messages m
                          JOIN message_threads t ON t.id = m.thread_id
                          JOIN users u ON u.id = IF(t.u_low = ?, t.u_high, t.u_low)
                         WHERE m.sender_id <> ? AND m.read_at IS NULL AND u.status = 'active'
                           AND (t.u_low = ? OR t.u_high = ?)
                           AND m.id > " . pmVisibleSql() . "");
    $st->execute([$userId, $userId, $userId, $userId, $userId]);
    return (int)$st->fetchColumn();
}

/**
 * Of the messages waiting (pmUnreadCount), how many are from a FRIEND — the sounds tell the two
 * apart (includes/sounds.php). Same rows, same rule about the counterpart being active, one more
 * condition: an accepted friendship in either direction.
 */
function pmUnreadCountFriends(PDO $db, int $userId): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM user_messages m
                          JOIN message_threads t ON t.id = m.thread_id
                          JOIN users u ON u.id = IF(t.u_low = ?, t.u_high, t.u_low)
                         WHERE m.sender_id <> ? AND m.read_at IS NULL AND u.status = 'active'
                           AND (t.u_low = ? OR t.u_high = ?)
                           AND m.id > " . pmVisibleSql() . "
                           AND EXISTS (SELECT 1 FROM user_friends f WHERE f.status = 'accepted'
                                          AND ((f.user_id = ? AND f.friend_id = u.id) OR (f.user_id = u.id AND f.friend_id = ?)))");
    $st->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    return (int)$st->fetchColumn();
}

/**
 * The moment of the newest line anywhere in this reader's messages — the one fact that says whether a
 * drawn list (and the three tabs' numbers above it) is still the truth.
 *
 * Two lookups rather than one with OR: `idx_thread_low` and `idx_thread_high` each answer
 * MAX(last_message_at) for one constant from the end of the index, and an OR across both indexes is
 * the shape that has cost this project a 500 before. The two are compared as strings, which for
 * 'Y-m-d H:i:s' is the same order as time.
 *
 * 1.73.0: every conversation of this reader's, archived or not. A new message changes a tab wherever it lands —
 * the Inbox's list, the Archive's count and its unread pill, a conversation coming back out of the Trash — so
 * it is a reason to redraw whichever of them is on the screen. (Until 1.72.x it was the unhidden ones only: a
 * hidden conversation could not receive a message without being un-hidden by it.)
 *
 * It is handed out BOTH by the drawing of the list and by the poll that watches it, because the
 * only honest baseline is the moment the list on the screen was built. Letting the first tick set
 * the baseline instead loses everything that arrived between the draw and that tick.
 */
function pmInboxStamp(PDO $db, int $userId): string
{
    $one = $db->prepare("SELECT MAX(t.last_message_at) FROM message_threads t JOIN users u ON u.id = t.u_high
                          WHERE t.u_low = ? AND u.status = 'active'");
    $one->execute([$userId]);
    $a = (string)($one->fetchColumn() ?: '');
    $two = $db->prepare("SELECT MAX(t.last_message_at) FROM message_threads t JOIN users u ON u.id = t.u_low
                          WHERE t.u_high = ? AND u.status = 'active'");
    $two->execute([$userId]);
    $b = (string)($two->fetchColumn() ?: '');
    return $a > $b ? $a : $b;
}

/* ── the read paths, each with its range (1.73.0) ───────────────────────────────────────────────── */

/**
 * One place's list of conversations for this reader: 'inbox', 'archive' or 'trash'. One row per conversation,
 * newest first (the Trash by when it was put there), each with the last line of ITS part and, outside the
 * Trash, how many of that part are unread. $ids (a deep search's answer) narrows it; [] is nothing at all.
 *
 * Rows: id, other_id, other_name, other_avatar_sha, last_id, last_body, last_sender, last_at, unread (inbox /
 * archive), n + trashed_at (trash).
 */
function pmListThreads(PDO $db, int $userId, string $view, ?array $ids = null, int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $in = '';
    if ($ids !== null) {
        if (!$ids) return [];
        $ids = array_values(array_map('intval', $ids));
        $in = ' AND t.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    }
    if ($view === 'trash') {
        $st = $db->prepare("SELECT z.*, lm.body AS last_body, lm.sender_id AS last_sender, lm.created_at AS last_at,
                   UNIX_TIMESTAMP(lm.created_at) AS last_ts, UNIX_TIMESTAMP(z.trashed_at) AS trashed_ts
              FROM (SELECT t.id, IF(t.u_low = ?, t.u_high, t.u_low) AS other_id, u.username AS other_name, u.avatar_sha AS other_avatar_sha,
                           IF(t.u_low = ?, t.u_low_trashed_at, t.u_high_trashed_at) AS trashed_at,
                           (SELECT MAX(m.id) FROM user_messages m WHERE m.thread_id = t.id
                                AND m.id > " . pmClearedSql() . " AND m.id <= " . pmTrashUptoSql() . ") AS last_id,
                           (SELECT COUNT(*) FROM user_messages m WHERE m.thread_id = t.id
                                AND m.id > " . pmClearedSql() . " AND m.id <= " . pmTrashUptoSql() . ") AS n
                      FROM message_threads t
                      JOIN users u ON u.id = IF(t.u_low = ?, t.u_high, t.u_low)
                     WHERE ((t.u_low = ? AND t.u_low_trash_upto > t.u_low_cleared_id)
                         OR (t.u_high = ? AND t.u_high_trash_upto > t.u_high_cleared_id))
                       AND u.status = 'active'" . $in . ") z
              JOIN user_messages lm ON lm.id = z.last_id
             ORDER BY z.trashed_at DESC, z.id DESC LIMIT " . $limit);
        $st->execute(array_merge(array_fill(0, 9, $userId), $ids ?? []));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $archived = $view === 'archive' ? 1 : 0;
    $st = $db->prepare("SELECT z.*, lm.body AS last_body, lm.sender_id AS last_sender, lm.created_at AS last_at,
               UNIX_TIMESTAMP(lm.created_at) AS last_ts, UNIX_TIMESTAMP(z.last_message_at) AS last_message_ts
          FROM (SELECT t.id, t.last_message_at, IF(t.u_low = ?, t.u_high, t.u_low) AS other_id,
                       u.username AS other_name, u.avatar_sha AS other_avatar_sha,
                       (SELECT MAX(m.id) FROM user_messages m WHERE m.thread_id = t.id AND m.id > " . pmVisibleSql() . ") AS last_id,
                       (SELECT COUNT(*) FROM user_messages m WHERE m.thread_id = t.id AND m.sender_id <> ? AND m.read_at IS NULL
                            AND m.id > " . pmVisibleSql() . ") AS unread
                  FROM message_threads t
                  JOIN users u ON u.id = IF(t.u_low = ?, t.u_high, t.u_low)
                 WHERE ((t.u_low = ? AND t.u_low_hidden = ?) OR (t.u_high = ? AND t.u_high_hidden = ?))
                   AND u.status = 'active'" . $in . ") z
          JOIN user_messages lm ON lm.id = z.last_id
         ORDER BY z.last_message_at DESC, z.id DESC LIMIT " . $limit);
    $st->execute(array_merge([$userId, $userId, $userId, $userId, $userId, $userId, $archived, $userId, $archived], $ids ?? []));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The conversations of one place whose words hold $search (a LIKE over this reader's own messages, in THAT
 * place's range only: a word in the Trash is no hit in the Inbox, a deleted one is no hit anywhere). Thread ids.
 */
function pmDeepSearchIds(PDO $db, int $userId, string $view, string $search): array
{
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    if ($view === 'trash') {
        $st = $db->prepare("SELECT DISTINCT m.thread_id FROM user_messages m
                              JOIN message_threads t ON t.id = m.thread_id
                             WHERE (t.u_low = ? OR t.u_high = ?) AND m.body LIKE ?
                               AND m.id > " . pmClearedSql() . " AND m.id <= " . pmTrashUptoSql() . "
                             LIMIT 200");
        $st->execute([$userId, $userId, $like, $userId, $userId]);
    } else {
        $st = $db->prepare("SELECT DISTINCT m.thread_id FROM user_messages m
                              JOIN message_threads t ON t.id = m.thread_id
                             WHERE (t.u_low = ? OR t.u_high = ?) AND m.body LIKE ?
                               AND m.id > " . pmVisibleSql() . "
                             LIMIT 200");
        $st->execute([$userId, $userId, $like, $userId]);
    }
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * One part of a conversation, oldest first: 'live' (above the floor — what the Inbox or the Archive shows) or
 * 'trash' (the Trash's range). $after: only messages above it (the poll's). Raw rows.
 */
function pmThreadMessages(PDO $db, array $thread, int $userId, string $part, int $after = 0, int $limit = 500): array
{
    $limit = max(1, min(500, $limit));
    if ($part === 'trash') {
        $upto = pmTrashUpto($thread, $userId);
        $from = max($after, pmClearedId($thread, $userId));
        if ($upto <= $from) return [];
        $st = $db->prepare("SELECT id, sender_id, body, body_format, created_at, UNIX_TIMESTAMP(created_at) AS created_ts, read_at, reported
                              FROM user_messages WHERE thread_id = ? AND id > ? AND id <= ? ORDER BY id ASC LIMIT " . $limit);
        $st->execute([(int)$thread['id'], $from, $upto]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    // `created_ts` (1.73.0 part E): the moment as an INSTANT, converted by the database from the zone its DATETIME
    // was written in — what pmReaderTime() turns into the reader's clock, as the shoutbox's times are (1.62.0).
    $st = $db->prepare("SELECT id, sender_id, body, body_format, created_at, UNIX_TIMESTAMP(created_at) AS created_ts, read_at, reported
                          FROM user_messages WHERE thread_id = ? AND id > ? ORDER BY id ASC LIMIT " . $limit);
    $st->execute([(int)$thread['id'], max($after, pmVisibleFrom($thread, $userId))]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The other side's messages in the part this reader is looking at, marked read — ONLY that part: a read receipt
 * is a statement that somebody saw a message, and one in their Trash (or deleted) was not shown to them.
 */
function pmMarkRead(PDO $db, array $thread, int $userId, string $part): int
{
    if ($part === 'trash') {
        $st = $db->prepare("UPDATE user_messages SET read_at = NOW()
                             WHERE thread_id = ? AND sender_id <> ? AND read_at IS NULL AND id > ? AND id <= ?");
        $st->execute([(int)$thread['id'], $userId, pmClearedId($thread, $userId), pmTrashUpto($thread, $userId)]);
        return $st->rowCount();
    }
    $st = $db->prepare("UPDATE user_messages SET read_at = NOW()
                         WHERE thread_id = ? AND sender_id <> ? AND read_at IS NULL AND id > ?");
    $st->execute([(int)$thread['id'], $userId, pmVisibleFrom($thread, $userId)]);
    return $st->rowCount();
}

/** Is anything of this conversation shown to this reader — above both their watermark and their Trash? */
function pmHasVisible(PDO $db, array $thread, int $userId): bool
{
    $st = $db->prepare("SELECT 1 FROM user_messages WHERE thread_id = ? AND id > ? LIMIT 1");
    $st->execute([(int)$thread['id'], pmVisibleFrom($thread, $userId)]);
    return (bool)$st->fetchColumn();
}

/* ── the operations (1.73.0) ────────────────────────────────────────────────────────────────────── */

/** This thread's row as it is now, locked for the rest of the caller's transaction. */
function pmThreadLocked(PDO $db, int $threadId): ?array
{
    $st = $db->prepare("SELECT * FROM message_threads WHERE id = ? FOR UPDATE");
    $st->execute([$threadId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Into the Archive ($on) or out of it — the explicit `archive` / `unarchive`. Asking for what already is
 * changes nothing and is not an error (a stale Undo). Returns whether anything changed.
 */
function pmSetArchived(PDO $db, array $thread, int $userId, bool $on): bool
{
    $col = pmSide($thread, $userId)['hidden'];
    $st = $db->prepare("UPDATE message_threads SET `$col` = ? WHERE id = ? AND `$col` <> ?");
    $st->execute([$on ? 1 : 0, (int)$thread['id'], $on ? 1 : 0]);
    return $st->rowCount() > 0;
}

/**
 * Delete, which means: into the Trash (`trash`). Everything of the conversation this reader is shown, up to the
 * last message there is — or up to $upto, the last one the page had (a message that arrived after the page last
 * looked stays where it is: it was not what somebody chose to delete). The moment is written only when the Trash
 * actually grows, so asking twice does not restart its clock.
 *
 * With pm_trash_days = 0 there is no Trash: the watermark moves over it at once — v70's delete, final.
 *
 * Returns ['changed', 'final', 'was' (trash_upto before — what an Undo goes back to), 'upto' (after)].
 */
function pmTrash(PDO $db, array $cfg, array $thread, int $userId, ?int $upto = null): array
{
    $side = (int)$thread['u_low'] === $userId ? 'u_low' : 'u_high';
    $final = pmTrashDays($cfg) === 0;
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $t = pmThreadLocked($db, (int)$thread['id']);
        if (!$t) { if ($own) $db->commit(); return ['changed' => false, 'final' => $final, 'was' => 0, 'upto' => 0]; }
        $cleared = (int)$t[$side . '_cleared_id'];
        $was = (int)$t[$side . '_trash_upto'];
        $mx = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM user_messages WHERE thread_id = ?");
        $mx->execute([(int)$t['id']]);
        $target = (int)$mx->fetchColumn();
        if ($upto !== null && $upto > 0) $target = min($target, $upto);
        if ($target <= max($cleared, $was)) {
            if ($own) $db->commit();
            return ['changed' => false, 'final' => $final, 'was' => $was, 'upto' => $was];
        }
        if ($final) {
            // Everything up to the target, and whatever was already in the Trash below it, deleted for this side.
            $db->prepare("UPDATE message_threads SET `{$side}_cleared_id` = GREATEST(`{$side}_cleared_id`, ?),
                                 `{$side}_trash_upto` = 0, `{$side}_trashed_at` = NULL WHERE id = ?")
               ->execute([$target, (int)$t['id']]);
            if ($own) $db->commit();
            return ['changed' => true, 'final' => true, 'was' => $was, 'upto' => 0];
        }
        $db->prepare("UPDATE message_threads SET `{$side}_trash_upto` = ?, `{$side}_trashed_at` = NOW() WHERE id = ?")
           ->execute([$target, (int)$t['id']]);
        if ($own) $db->commit();
        return ['changed' => true, 'final' => false, 'was' => $was, 'upto' => $target];
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Out of the Trash (`restore`): everything in it, back into the conversation — to the Inbox or the Archive,
 * wherever the conversation is (the Trash never touched `hidden`, so a conversation deleted from the Archive
 * goes back to the Archive).
 *
 * An UNDO of a Trash names what it undoes: $from = the trash_upto that Trash set, $to = the one before it
 * (0, or an older part that was already there). If the Trash is no longer what it set — restored, deleted for
 * good, the janitor's, or grown since — the Undo is stale and changes nothing.
 *
 * Returns ['changed', 'upto' (after), 'was' (before), 'place' ('inbox' | 'archive')].
 */
function pmRestore(PDO $db, array $thread, int $userId, ?int $to = null, ?int $from = null): array
{
    $side = (int)$thread['u_low'] === $userId ? 'u_low' : 'u_high';
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $t = pmThreadLocked($db, (int)$thread['id']);
        $place = $t && (int)$t[$side . '_hidden'] === 1 ? 'archive' : 'inbox';
        $was = $t ? (int)$t[$side . '_trash_upto'] : 0;
        if (!$t || $was === 0 || ($from !== null && $from !== $was)) {
            if ($own) $db->commit();
            return ['changed' => false, 'upto' => $was, 'was' => $was, 'place' => $place, 'stale' => $from !== null && $was !== (int)$from];
        }
        $cleared = (int)$t[$side . '_cleared_id'];
        $keep = ($to !== null && $to > $cleared && $to < $was) ? (int)$to : 0;
        $db->prepare("UPDATE message_threads SET `{$side}_trash_upto` = ?,
                             `{$side}_trashed_at` = IF(? = 0, NULL, `{$side}_trashed_at`) WHERE id = ?")
           ->execute([$keep, $keep, (int)$t['id']]);
        if ($own) $db->commit();
        return ['changed' => true, 'upto' => $keep, 'was' => $was, 'place' => $place, 'stale' => false];
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Delete for good (`purge`): what is in this reader's Trash, under the watermark — v70's delete, which nothing
 * brings back. The other side's copy is untouched, and nothing leaves `user_messages` (a reported message stays
 * readable to the panel). Returns whether anything changed.
 */
function pmPurge(PDO $db, array $thread, int $userId): bool
{
    $side = (int)$thread['u_low'] === $userId ? 'u_low' : 'u_high';
    // Left to right, as MariaDB and MySQL assign: the watermark reads trash_upto before it is emptied.
    $st = $db->prepare("UPDATE message_threads SET `{$side}_cleared_id` = GREATEST(`{$side}_cleared_id`, `{$side}_trash_upto`),
                               `{$side}_trash_upto` = 0, `{$side}_trashed_at` = NULL
                         WHERE id = ? AND `{$side}_trash_upto` > 0");
    $st->execute([(int)$thread['id']]);
    return $st->rowCount() > 0;
}

/** Empty the Trash (`empty_trash`): pmPurge() for every conversation of this reader's. Returns how many. */
function pmEmptyTrash(PDO $db, int $userId): int
{
    $n = 0;
    foreach (['u_low', 'u_high'] as $side) {
        $st = $db->prepare("UPDATE message_threads SET `{$side}_cleared_id` = GREATEST(`{$side}_cleared_id`, `{$side}_trash_upto`),
                                   `{$side}_trash_upto` = 0, `{$side}_trashed_at` = NULL
                             WHERE `$side` = ? AND `{$side}_trash_upto` > 0");
        $st->execute([$userId]);
        $n += $st->rowCount();
    }
    return $n;
}

/**
 * The janitor's half of the Trash: whatever has been there pm_trash_days (0: everything), deleted for good for
 * the side that put it there — pmPurge() per row, in batches: per side ONE UPDATE a batch, oldest first by
 * `idx_thread_trash_low` / `_high`, at most $maxBatches of them a tick (the next tick takes the rest). $now is
 * the clock (tests pass one). Returns ['purged' => rows, 'batches' => statements].
 */
function pmTrashPurgeExpired(PDO $db, array $cfg, ?int $now = null, int $batch = 500, int $maxBatches = 10): array
{
    $out = ['purged' => 0, 'batches' => 0];
    $batch = max(1, min(5000, $batch));
    $cut = date('Y-m-d H:i:s', ($now ?? time()) - pmTrashDays($cfg) * 86400);
    try {
        foreach (['u_low', 'u_high'] as $side) {
            for ($i = 0; $i < max(1, $maxBatches); $i++) {
                $st = $db->prepare("UPDATE message_threads SET `{$side}_cleared_id` = GREATEST(`{$side}_cleared_id`, `{$side}_trash_upto`),
                                           `{$side}_trash_upto` = 0, `{$side}_trashed_at` = NULL
                                     WHERE `{$side}_trashed_at` IS NOT NULL AND `{$side}_trashed_at` <= ?
                                     ORDER BY `{$side}_trashed_at` LIMIT " . $batch);
                $st->execute([$cut]);
                $n = $st->rowCount();
                $out['batches']++;
                $out['purged'] += $n;
                if ($n < $batch) break;
            }
        }
    } catch (\Throwable $e) {
        // A database that predates v90 has nothing in any Trash; a busy one is asked again next minute.
    }
    return $out;
}

/**
 * A message was just written (1.73.0): whose Archive it leaves. The writer's own — writing in a conversation
 * brings it back to your Inbox. The other side's when the site says so (pm_archive_returns, on by default), and
 * ALSO when nothing of the conversation was left for them outside the Trash (all of it trashed or deleted): a
 * conversation somebody threw away comes back as a new one in the Inbox, never into the Archive.
 * $recipientHadVisible: pmHasVisible() for the other side, asked BEFORE the message was inserted.
 */
function pmAfterMessage(PDO $db, array $cfg, array $thread, int $senderId, bool $recipientHadVisible): void
{
    $writerCol = pmSide($thread, $senderId)['hidden'];
    $readerCol = pmSide($thread, pmOtherId($thread, $senderId))['hidden'];
    $back = (pmArchiveReturns($cfg) || !$recipientHadVisible) ? 1 : 0;
    $db->prepare("UPDATE message_threads SET last_message_at = NOW(), `$writerCol` = 0,
                         `$readerCol` = IF(? = 1, 0, `$readerCol`) WHERE id = ?")
       ->execute([$back, (int)$thread['id']]);
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
        // The Archive and the Trash (1.73.0): how long the Trash keeps a conversation (0 = there is none) and
        // whether a new message brings one back out of the Archive.
        'trash_days'      => pmTrashDays($cfg),
        'archive_returns' => pmArchiveReturns($cfg),
    ];
}

/**
 * One message, rendered the way a description is rendered.
 *
 * The same sanitizer the public descriptions use, and for the same reason: a message is a stranger's
 * text, and there is exactly one place in this codebase that knows what is safe to let through.
 *
 * With `$db`, the site's emotes and stickers too (1.70.0, richtextRenderIn('message')): a sticker at the
 * room's size, a message being a conversation with one person. The whole thread's emotes are one query.
 */
function pmRenderBody(string $body, string $format, array $cfg, bool $signedIn = true, ?PDO $db = null): string
{
    if (function_exists('richtextRenderIn')) return richtextRenderIn('message', $db, $body, $format, $cfg, $signedIn);
    if (function_exists('richtextRender')) return richtextRender($body, $format, $cfg, $signedIn);
    return nl2br(sanitize($body));
}
