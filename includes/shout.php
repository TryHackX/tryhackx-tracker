<?php
/**
 * Shoutbox (1.58.0, schema 63): one room, one line each, nothing kept for long.
 *
 * ── what it is, and what it deliberately is not ────────────────────────────────────────────────
 *
 * A shout is a sentence, not a post. It cannot be edited (delete it and say it again), it carries no
 * notification of its own, and it expires: `shout_keep_rows` newest rows and `shout_keep_days` of
 * age, whichever bites first, swept by the janitor. That is the whole retention policy, and it is
 * why the table has no archive and no history — a room people talk in is not a record.
 *
 * ── only new rows ever travel ──────────────────────────────────────────────────────────────────
 *
 * The widget is drawn by the server with its newest id in the markup, and the browser asks for
 * `after=<that id>` from then on. Nothing is ever redrawn: the lesson of 1.48 and 1.50 is that a
 * list which repaints itself eats what the person below it is typing. Reading older lines is the
 * mirror image (`before=<oldest id>`), prepended, with the scroll position kept.
 *
 * ── who may do what ────────────────────────────────────────────────────────────────────────────
 *
 * Four permissions, no switches: `shout.view` (a member's by default — a guest reads nothing unless
 * the operator says so), `shout.post`, `shout.delete_own`, `shout.moderate`. Silence is INHERITED
 * rather than duplicated: `users.pm_muted_until` is the one moment a moderator sets, and somebody
 * silenced in private messages is silenced here too. They still read — a mute is about writing.
 *
 * ── three kinds of "new" ───────────────────────────────────────────────────────────────────────
 *
 * A shout from a FRIEND, a shout from anybody else and an @-mention of me are three different
 * events, because the sounds (includes/sounds.php) tell them apart. They are counted from one
 * column, `users.shout_seen_id`: "how many since the last id this reader saw", by the primary key,
 * instead of a table of read marks that would need its own retention.
 *
 * Mentions are resolved ONCE, when the shout is written (`shout_mentions`), rather than by parsing
 * every body on every read — a rename afterwards does not retro-mention somebody, which is the
 * behaviour a person expects from "did this line mean me".
 */

/* ── the switches, all clamped on read ─────────────────────────────────────── */

/** The feature as a whole: accounts on, and the owner has switched the shoutbox on. */
function shoutEnabled(array $cfg): bool
{
    return usersEnabled($cfg) && (($cfg['shout_enabled'] ?? '0') === '1');
}

/** Where it is drawn: 'home' (the front-page block), 'page' (?action=shoutbox) or 'both'. */
function shoutPlacement(array $cfg): string
{
    $v = (string)($cfg['shout_placement'] ?? 'home');
    return in_array($v, ['home', 'page', 'both'], true) ? $v : 'home';
}

/**
 * The DEFAULT markup of a shout: plain | bbcode | markdown.
 *
 * 'plain' is not merely a third syntax — it switches formatting off entirely, composer included.
 * With bbcode or markdown the writer picks per shout; this only says which one the box starts on.
 */
function shoutFormat(array $cfg): string
{
    $v = (string)($cfg['shout_format'] ?? 'bbcode');
    return in_array($v, ['plain', 'bbcode', 'markdown'], true) ? $v : 'bbcode';
}

/** The formats a writer may choose from. One entry for 'plain' — there is nothing to choose. */
function shoutFormatChoices(array $cfg): array
{
    return shoutFormat($cfg) === 'plain' ? ['plain'] : ['bbcode', 'markdown'];
}

/**
 * How often an on-screen widget asks for new lines. 0 = never, which is what an install that has
 * not thought about it should be doing; anything above is floored at three seconds, because below
 * that this stops being a refresh and becomes a load.
 */
function shoutLiveSeconds(array $cfg): int
{
    $v = (int)($cfg['shout_live_seconds'] ?? 10);
    return $v <= 0 ? 0 : max(3, min(120, $v));
}

function shoutMaxChars(array $cfg): int   { return max(1, min(2000, (int)($cfg['shout_max_chars'] ?? 500) ?: 500)); }
function shoutWidgetRows(array $cfg): int { return max(5, min(100, (int)($cfg['shout_widget_rows'] ?? 25) ?: 25)); }
function shoutPageRows(array $cfg): int   { return max(20, min(500, (int)($cfg['shout_page_rows'] ?? 100) ?: 100)); }
function shoutFloodSeconds(array $cfg): int { return max(0, min(300, (int)($cfg['shout_flood_seconds'] ?? 5))); }
function shoutKeepRows(array $cfg): int   { return max(100, min(100000, (int)($cfg['shout_keep_rows'] ?? 2000) ?: 2000)); }
function shoutKeepDays(array $cfg): int   { return max(1, min(3650, (int)($cfg['shout_keep_days'] ?? 30) ?: 30)); }

/** The optional house rules printed above the box. Plain text, bounded where it is saved. */
function shoutRules(array $cfg): string { return trim((string)($cfg['shout_rules'] ?? '')); }

/* ── who may read, who may write ───────────────────────────────────────────── */

/** May the reader of THIS request see the room at all? */
function shoutMayView(PDO $db, array $cfg): bool
{
    return shoutEnabled($cfg) && userCan($db, $cfg, 'shout.view');
}

/**
 * May this ACCOUNT write, and if not, why — as one answer the composer can print.
 *
 *   reason  '' | 'disabled' | 'login' | 'no_permission' | 'muted'   ('until' carries the date)
 *
 * Asked about a NAMED user rather than about whoever is making the request (userIdHasPermission,
 * not userCan): a CLI test has no session and a panel session answers yes to everything, so a gate
 * that cannot be asked about an account is a gate that cannot be tested. Muted comes last of the
 * four because it is the only one that is temporary — the others are about who somebody is.
 */
function shoutMayPost(PDO $db, array $cfg, ?array $user): array
{
    if (!shoutEnabled($cfg)) return ['ok' => false, 'reason' => 'disabled', 'until' => null];
    if (!$user || (int)($user['id'] ?? 0) <= 0) return ['ok' => false, 'reason' => 'login', 'until' => null];
    if (!userIdHasPermission($db, $cfg, (int)$user['id'], 'shout.post')) {
        return ['ok' => false, 'reason' => 'no_permission', 'until' => null];
    }
    $until = function_exists('pmMutedUntil') ? pmMutedUntil($user) : null;
    if ($until !== null) return ['ok' => false, 'reason' => 'muted', 'until' => $until];
    return ['ok' => true, 'reason' => '', 'until' => null];
}

/* ── mentions ──────────────────────────────────────────────────────────────── */

/** The @-tokens in a body, as written, without deciding yet whether they name anybody. */
function shoutMentionTokens(string $body): array
{
    // Not preceded by a word character (so an e-mail address is not a mention) and not by another
    // '@'. The character class is userValidUsername()'s, so a token that cannot be a name is never
    // looked up.
    if (!preg_match_all('/(?<![\w@])@([A-Za-z0-9_.-]{3,32})/', $body, $m)) return [];
    $out = [];
    foreach ($m[1] as $name) {
        $k = mb_strtolower($name);
        if (!isset($out[$k])) $out[$k] = $name;
    }
    return $out;   // lower-case key => the token as it was typed
}

/**
 * Which accounts a body actually mentions: up to five distinct existing users, never the author.
 *
 * Five because a mention is a nudge, not a mailing list — and because the row count per shout is
 * what decides whether `shout_mentions` stays a small table. The author is dropped rather than
 * refused: @-ing yourself is a typo, not an offence.
 */
function shoutParseMentions(PDO $db, string $body, int $authorId): array
{
    $tokens = shoutMentionTokens($body);
    if (!$tokens) return [];
    $names = array_slice(array_values($tokens), 0, 20);   // look up a bounded number, keep five
    $in = implode(',', array_fill(0, count($names), '?'));
    $st = $db->prepare("SELECT id FROM users WHERE username IN ($in) LIMIT 20");
    $st->execute($names);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $id = (int)$id;
        if ($id <= 0 || $id === $authorId || in_array($id, $ids, true)) continue;
        $ids[] = $id;
        if (count($ids) >= 5) break;
    }
    return $ids;
}

/**
 * The names in a batch of bodies that really are accounts, as lower-case => the account's own
 * spelling. One query for the whole page rather than one per row.
 */
function shoutKnownNames(PDO $db, array $bodies): array
{
    $tokens = [];
    foreach ($bodies as $b) {
        foreach (shoutMentionTokens((string)$b) as $k => $raw) {
            if (!isset($tokens[$k])) $tokens[$k] = $raw;
            if (count($tokens) >= 100) break 2;   // a page of shouts, not a dictionary attack
        }
    }
    if (!$tokens) return [];
    $names = array_values($tokens);
    $in = implode(',', array_fill(0, count($names), '?'));
    $st = $db->prepare("SELECT username FROM users WHERE username IN ($in)");
    $st->execute($names);
    $known = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) $known[mb_strtolower((string)$u)] = (string)$u;
    return $known;
}

/**
 * Turn @name into a link, in the TEXT of already-rendered HTML and nowhere else.
 *
 * The split is on tags, so an '@' inside an href or a class attribute is left alone — richtextRender
 * escapes every author byte before it builds a tag, so nothing between '<' and '>' at this point
 * came from the writer, and nothing outside them is markup. Text already inside a link is skipped
 * too: a mention written as the label of a [url] must not become a second <a> inside the first.
 */
function shoutLinkMentions(string $html, array $known, string $baseUrl, string $meName = ''): string
{
    if ($html === '' || !$known) return $html;
    $meKey = $meName === '' ? '' : mb_strtolower($meName);
    $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return $html;
    $inLink = 0;
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {                                   // odd entries are the tags
            if (preg_match('#^<a\b#i', $part)) $inLink++;
            elseif (preg_match('#^</a\s*>#i', $part)) $inLink = max(0, $inLink - 1);
            continue;
        }
        if ($inLink > 0 || $part === '' || !str_contains($part, '@')) continue;
        $parts[$i] = preg_replace_callback('/(?<![\w@])@([A-Za-z0-9_.-]{3,32})/', function ($m) use ($known, $baseUrl, $meKey) {
            $key = mb_strtolower($m[1]);
            if (!isset($known[$key])) return $m[0];
            $cls = 'shout-mention' . ($meKey !== '' && $key === $meKey ? ' shout-mention-me' : '');
            return '<a class="' . $cls . '" href="' . htmlspecialchars($baseUrl . '?action=u&name=' . rawurlencode($known[$key]), ENT_QUOTES, 'UTF-8')
                 . '">@' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</a>';
        }, $part) ?? $part;
    }
    return implode('', $parts);
}

/* ── writing one ───────────────────────────────────────────────────────────── */

/**
 * Write a shout. ['ok' => true, 'row' => …] or ['ok' => false, 'error' => code] with codes
 * disabled | no_permission | muted | flood | empty | too_long | invalid_body.
 *
 * The ORDER is the endpoint's order and is part of the contract: who somebody is comes before how
 * fast they are typing, and both come before what they typed — being told "too long" by a room you
 * may not write in at all is an answer to a question nobody asked.
 */
function shoutPost(PDO $db, array $cfg, array $user, string $body, string $format, string $ip): array
{
    $gate = shoutMayPost($db, $cfg, $user);
    if (!$gate['ok']) return ['ok' => false, 'error' => $gate['reason'], 'until' => $gate['until'], 'row' => null];
    $uid = (int)$user['id'];

    // Flood: measured by the DATABASE's clock against the row's own timestamp, so a web server
    // whose clock drifts from MariaDB's cannot let a burst through (or refuse an honest shout).
    $wait = shoutFloodSeconds($cfg);
    if ($wait > 0) {
        $st = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM shouts WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$uid]);
        $since = $st->fetchColumn();
        if ($since !== false && $since !== null && (int)$since < $wait) {
            return ['ok' => false, 'error' => 'flood', 'retry_after' => max(1, $wait - (int)$since), 'row' => null];
        }
    }

    $body = trim($body);
    if ($body === '') return ['ok' => false, 'error' => 'empty', 'row' => null];
    $max = shoutMaxChars($cfg);
    if (mb_strlen($body) > $max) return ['ok' => false, 'error' => 'too_long', 'limit' => $max, 'row' => null];

    // 'plain' is the whole feature's answer, not this shout's: when the site says plain, nothing is
    // parsed and there is nothing to validate beyond the length above.
    $format = in_array($format, shoutFormatChoices($cfg), true) ? $format : shoutFormat($cfg);
    if ($format !== 'plain' && function_exists('richtextValidate')) {
        // The SHOUT's limit, not the description's: the counter under the box has to say the number
        // the send is actually judged against (the same trick api/user_messages.php uses).
        $bad = richtextValidate($body, $format, array_merge($cfg, ['desc_max_chars' => (string)$max]));
        if ($bad !== null) return ['ok' => false, 'error' => 'invalid_body', 'detail' => $bad, 'row' => null];
    }

    $bucket = function_exists('ipBucket') && $ip !== '' ? ipBucket($ip) : '';
    $db->prepare("INSERT INTO shouts (user_id, body, body_format, ip_bucket) VALUES (?, ?, ?, ?)")
       ->execute([$uid, $body, $format, mb_substr($bucket, 0, 45)]);
    $id = (int)$db->lastInsertId();

    foreach (shoutParseMentions($db, $body, $uid) as $mid) {
        // IGNORE: the primary key is the pair, and two mentions of one person in one line are one.
        $db->prepare("INSERT IGNORE INTO shout_mentions (shout_id, user_id) VALUES (?, ?)")->execute([$id, $mid]);
    }

    // Rendered by the same function the poll uses, so the line that appears on Enter is byte for
    // byte the line everybody else will be handed. Checked by id rather than trusted: if this row
    // were already gone the answer must be "nothing to append", not somebody else's sentence.
    $rows = shoutRows($db, $cfg, $user, 1, $id - 1);
    $row = ($rows && (int)$rows[0]['id'] === $id) ? $rows[0] : null;
    return ['ok' => true, 'error' => '', 'row' => $row, 'id' => $id];
}

/* ── reading them ──────────────────────────────────────────────────────────── */

/** The newest id in the room — the baseline a freshly drawn widget starts polling from. */
function shoutNewestId(PDO $db): int
{
    try {
        return (int)($db->query("SELECT COALESCE(MAX(id), 0) FROM shouts WHERE deleted_at IS NULL")->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        return 0;   // the table arrives with schema 63; a page rendered mid-upgrade has no room yet
    }
}

/**
 * Is there anything ABOVE this id? One indexed lookup, and it is what decides whether the "older"
 * button is drawn at all — by the endpoint AND by the first server-side render of the widget, so
 * the two cannot disagree about whether there is more to read.
 */
function shoutHasOlder(PDO $db, int $oldestId): bool
{
    if ($oldestId <= 0) return false;
    try {
        $st = $db->prepare("SELECT 1 FROM shouts WHERE deleted_at IS NULL AND id < ? LIMIT 1");
        $st->execute([$oldestId]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Rows for the client, NEWEST LAST — the order they are read in, so the browser appends rather than
 * reverses. `$after` asks for what is newer than an id, `$before` for what is older; neither asks
 * for the last `$limit`.
 *
 * `$me` may be an empty array: a guest the operator handed `shout.view` reads the room and owns
 * nothing in it.
 */
function shoutRows(PDO $db, array $cfg, array $me, int $limit, ?int $after = null, ?int $before = null): array
{
    $limit = max(1, min(500, $limit));
    $meId = (int)($me['id'] ?? 0);
    if ($after !== null) {
        $st = $db->prepare("SELECT s.id, s.user_id, s.body, s.body_format, s.created_at, u.username
                              FROM shouts s JOIN users u ON u.id = s.user_id
                             WHERE s.deleted_at IS NULL AND s.id > ? ORDER BY s.id ASC LIMIT $limit");
        $st->execute([(int)$after]);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Newest first for the query (that is what the index answers from the end), reversed below.
        $sql = "SELECT s.id, s.user_id, s.body, s.body_format, s.created_at, u.username
                  FROM shouts s JOIN users u ON u.id = s.user_id
                 WHERE s.deleted_at IS NULL";
        $args = [];
        if ($before !== null) { $sql .= " AND s.id < ?"; $args[] = (int)$before; }
        $st = $db->prepare($sql . " ORDER BY s.id DESC LIMIT $limit");
        $st->execute($args);
        $raw = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    }
    if (!$raw) return [];

    // Two permissions, asked once for the reader rather than once per row.
    $mayModerate = $meId > 0 && userIdHasPermission($db, $cfg, $meId, 'shout.moderate');
    $mayDeleteOwn = $meId > 0 && userIdHasPermission($db, $cfg, $meId, 'shout.delete_own');
    $known = shoutKnownNames($db, array_column($raw, 'body'));
    $base = function_exists('getBaseUrl') ? getBaseUrl() : '';
    $meName = (string)($me['username'] ?? '');

    // Which of these lines named me — from the table written when they were said, not from parsing
    // them again now.
    $mine = [];
    if ($meId > 0) {
        $ids = array_map('intval', array_column($raw, 'id'));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT shout_id FROM shout_mentions WHERE user_id = ? AND shout_id IN ($in)");
        $st->execute(array_merge([$meId], $ids));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) $mine[(int)$sid] = true;
    }

    $out = [];
    foreach ($raw as $r) {
        $own = $meId > 0 && (int)$r['user_id'] === $meId;
        $fmt = (string)$r['body_format'];
        $html = $fmt === 'plain'
            ? nl2br(htmlspecialchars((string)$r['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false)
            : richtextRender((string)$r['body'], $fmt, $cfg, true);
        $out[] = [
            'id'          => (int)$r['id'],
            'user'        => (string)$r['username'],
            'user_id'     => (int)$r['user_id'],
            'at'          => (string)$r['created_at'],
            'html'        => shoutLinkMentions($html, $known, $base, $meName),
            'own'         => $own,
            'deletable'   => $mayModerate || ($own && $mayDeleteOwn),
            'mentions_me' => isset($mine[(int)$r['id']]),
        ];
    }
    return $out;
}

/* ── removing one ──────────────────────────────────────────────────────────── */

/**
 * Delete a shout. ['ok' => true] or ['ok' => false, 'error' => 'not_found' | 'no_permission'].
 *
 * A SOFT delete: the row keeps its place until retention takes it, so a moderator's decision leaves
 * a trace for as long as anything here lasts. Publicly the line is simply gone — every read path
 * asks for `deleted_at IS NULL`.
 *
 * Somebody else's line is an audited act; your own is not. Deleting what you just said is tidying.
 */
function shoutDelete(PDO $db, array $cfg, array $me, int $id): array
{
    $meId = (int)($me['id'] ?? 0);
    if ($meId <= 0) return ['ok' => false, 'error' => 'no_permission'];
    $st = $db->prepare("SELECT s.id, s.user_id, u.username FROM shouts s JOIN users u ON u.id = s.user_id
                         WHERE s.id = ? AND s.deleted_at IS NULL LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'error' => 'not_found'];

    $own = (int)$row['user_id'] === $meId;
    $mayModerate = userIdHasPermission($db, $cfg, $meId, 'shout.moderate');
    if (!$mayModerate && !($own && userIdHasPermission($db, $cfg, $meId, 'shout.delete_own'))) {
        return ['ok' => false, 'error' => 'no_permission'];
    }
    $db->prepare("UPDATE shouts SET deleted_by = ?, deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL")
       ->execute([$meId, $id]);
    if (!$own && function_exists('auditLog')) {
        auditLog($db, 'shout.delete', ['target_type' => 'user', 'target_id' => (int)$row['user_id'],
            'summary' => (string)($me['username'] ?? '#' . $meId) . ' → shout #' . $id . ' by ' . (string)$row['username']]);
    }
    return ['ok' => true, 'error' => ''];
}

/* ── what is new for one reader ────────────────────────────────────────────── */

/** Remember how far this reader has read. GREATEST, so a slow tab cannot move the mark backwards. */
function shoutSeen(PDO $db, int $userId, int $id): void
{
    if ($userId <= 0 || $id <= 0) return;
    $db->prepare("UPDATE users SET shout_seen_id = GREATEST(shout_seen_id, ?) WHERE id = ?")->execute([$id, $userId]);
}

/**
 * How much is new: ['shout' => n, 'shout_friend' => n, 'mention' => n].
 *
 * `shout` is the TOTAL; the other two are subsets of it (a friend's shout that mentions me is in
 * all three). The sounds want "a friend", "anybody else" and "me by name" as three events, and
 * anybody else is the subtraction the client does — the same shape as the message counts.
 *
 * My own lines are never new to me, and a deleted one is not new to anybody.
 */
function shoutUnreadCounts(PDO $db, array $cfg, array $me): array
{
    $zero = ['shout' => 0, 'shout_friend' => 0, 'mention' => 0];
    $meId = (int)($me['id'] ?? 0);
    if ($meId <= 0 || !shoutEnabled($cfg)) return $zero;
    $seen = array_key_exists('shout_seen_id', $me) ? (int)$me['shout_seen_id'] : null;
    try {
        if ($seen === null) {
            $st = $db->prepare("SELECT shout_seen_id FROM users WHERE id = ?");
            $st->execute([$meId]);
            $seen = (int)($st->fetchColumn() ?: 0);
        }
        // One pass over the tail of the primary key. `shouts` is bounded by retention, so the worst
        // case here is a reader who has never looked at a full room.
        $st = $db->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(EXISTS(SELECT 1 FROM user_friends f WHERE f.status = 'accepted'
                                          AND ((f.user_id = ? AND f.friend_id = s.user_id)
                                            OR (f.user_id = s.user_id AND f.friend_id = ?)))), 0) AS friends,
                    COALESCE(SUM(EXISTS(SELECT 1 FROM shout_mentions m WHERE m.shout_id = s.id AND m.user_id = ?)), 0) AS mentions
               FROM shouts s
              WHERE s.id > ? AND s.deleted_at IS NULL AND s.user_id <> ?");
        $st->execute([$meId, $meId, $meId, $seen, $meId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['shout' => (int)($r['total'] ?? 0), 'shout_friend' => (int)($r['friends'] ?? 0),
                'mention' => (int)($r['mentions'] ?? 0)];
    } catch (\Throwable $e) {
        return $zero;   // mid-upgrade: no table, no numbers, no error page
    }
}

/* ── retention ─────────────────────────────────────────────────────────────── */

/** The rows a batch is allowed to remove in one pass, so one janitor tick cannot lock the table. */
const SHOUT_PRUNE_BATCH = 1000;

/** Delete these ids and the mentions that point at them. Mentions FIRST: no orphan is ever visible. */
function shoutDeleteIds(PDO $db, array $ids): int
{
    if (!$ids) return 0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE FROM shout_mentions WHERE shout_id IN ($in)")->execute($ids);
    $st = $db->prepare("DELETE FROM shouts WHERE id IN ($in)");
    $st->execute($ids);
    return $st->rowCount();
}

/**
 * Retention, both halves: ['by_age' => n, 'by_count' => n].
 *
 * HARD deletes — a swept shout leaves nothing, moderator-deleted or not. Batched by primary key
 * rather than done in one statement because "one DELETE" on a table somebody is reading is how a
 * shared MariaDB becomes everybody's problem; the janitor comes back in a minute for the rest.
 */
function shoutPrune(PDO $db, array $cfg): array
{
    $out = ['by_age' => 0, 'by_count' => 0];
    try {
        $days = shoutKeepDays($cfg);
        $st = $db->prepare("SELECT id FROM shouts WHERE created_at < (NOW() - INTERVAL ? DAY) ORDER BY id ASC LIMIT " . SHOUT_PRUNE_BATCH);
        $st->execute([$days]);
        $out['by_age'] = shoutDeleteIds($db, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));

        // By count: everything older than the `keep`-th newest row. OFFSET is interpolated because a
        // bound parameter is a string there under emulated prepares; it is an integer this file
        // clamped two lines up.
        $keep = shoutKeepRows($cfg);
        $cut = (int)($db->query("SELECT id FROM shouts ORDER BY id DESC LIMIT 1 OFFSET " . $keep)->fetchColumn() ?: 0);
        if ($cut > 0) {
            $st = $db->prepare("SELECT id FROM shouts WHERE id <= ? ORDER BY id ASC LIMIT " . SHOUT_PRUNE_BATCH);
            $st->execute([$cut]);
            $out['by_count'] = shoutDeleteIds($db, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        }
    } catch (\Throwable $e) {
        return $out;   // no table yet, or the database is busy: the next tick tries again
    }
    return $out;
}

/**
 * The owner emptying the room from Settings: everything ($olderThanDays === null) or everything
 * older than N days. Hard, and not batched — this is a deliberate act with a password behind it,
 * and it is meant to finish.
 */
function shoutPurge(PDO $db, array $cfg, ?int $olderThanDays): int
{
    try {
        if ($olderThanDays === null) {
            $db->exec("DELETE FROM shout_mentions");
            return (int)$db->exec("DELETE FROM shouts");
        }
        $days = max(0, min(3650, $olderThanDays));
        $gone = 0;
        // In batches for the same reason the janitor is: an operator purging a year of a busy room
        // must not be the reason the site stops answering.
        while (true) {
            $st = $db->prepare("SELECT id FROM shouts WHERE created_at < (NOW() - INTERVAL ? DAY) ORDER BY id ASC LIMIT " . SHOUT_PRUNE_BATCH);
            $st->execute([$days]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$ids) break;
            $gone += shoutDeleteIds($db, $ids);
        }
        return $gone;
    } catch (\Throwable $e) {
        return 0;
    }
}
