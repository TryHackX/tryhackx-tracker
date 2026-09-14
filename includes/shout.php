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
    // The emote table, once for the page rather than once per line (1.59.0). Empty when the feature
    // is off, which is what makes shoutRenderEmotes() a no-op instead of a second switch.
    $emotes = shoutEmotes($db, $cfg);
    $stickersOn = shoutStickersEnabled($cfg);

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
            // Mentions first, emotes last: both walk the TEXT of finished html and neither may see
            // the other's tag as writing. `:code:` inside an href is an address, not an emote.
            'html'        => shoutRenderEmotes(shoutLinkMentions($html, $known, $base, $meName), $emotes, $base, $stickersOn),
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

/* ── emotes and stickers (1.59.0, schema 64) ───────────────────────────────────────────────────
 *
 * Emoji are not here, and that is the decision: a smiley in a shout is a Unicode character, drawn
 * by whatever emoji font the reader's own device has (Segoe UI Emoji, Noto, Apple's). The site
 * ships no picture of one, which is why the picker in the browser is plain text and this file
 * knows nothing about it.
 *
 * What IS here is the CUSTOM half: an image somebody uploaded, addressed by a short code and
 * written as `:code:`. Kept as rows of `shout_emotes` for the same reason the sounds are rows —
 * the web root is installed read-only and a backup that carries the database carries these. A
 * STICKER is the same row with one flag: when a whole shout is nothing but its token, it is drawn
 * big instead of inline, which is the only difference between the two.
 *
 * ── the bytes decide, and an SVG is guilty until proven otherwise ──────────────────────────────
 *
 * An <img> is not a safe frame for an SVG: an SVG is a document, it can carry a script, event
 * handlers and references to other origins, and served from this origin it would run with this
 * origin's rights. So there are three independent walls and any one of them is meant to be enough:
 *
 *   1. shoutEmoteSvgIssue() refuses the upload — a script element, on*= handlers, javascript:,
 *      data:text/html, foreignObject, an embedded frame, an entity declaration, or any href that is
 *      not a local `#fragment`. Checked on the bytes with entities decoded, so `java&#115;cript:`
 *      is the same word to this code as it is to a browser.
 *   2. api/shout_emote.php serves it with the type the sniff CHOSE (never the uploader's), plus
 *      `X-Content-Type-Options: nosniff`, so nothing can be re-read as HTML.
 *   3. …and with `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'` on that
 *      response, so even a trick none of the above spotted has nothing it is allowed to do.
 */

/** `:code:` — short, lower case, and the same character class a file name may have. */
const SHOUT_EMOTE_CODE_RE = '/^[a-z0-9_]{2,32}$/';
/** The token as it appears inside a line of text. Kept beside the code rule so the two agree. */
const SHOUT_EMOTE_TOKEN_RE = '/:([a-z0-9_]{2,32}):/';
const SHOUT_EMOTE_MIN_BYTES = 32;
const SHOUT_EMOTE_NAME_MAX = 60;

/** Where the shipped examples live; the v64 migration seeds the table from this directory. */
function shoutEmoteSeedDir(): string { return dirname(__DIR__) . '/assets/emotes'; }

/* ── the switches ──────────────────────────────────────────────────────────── */

/** Custom images at all. A room that is off has no emotes either — this is the whole feature. */
function shoutEmotesEnabled(array $cfg): bool
{
    return shoutEnabled($cfg) && (($cfg['shout_emotes_enabled'] ?? '1') === '1');
}

/** The big ones. A sticker is an emote first, so this cannot be on while emotes are off. */
function shoutStickersEnabled(array $cfg): bool
{
    return shoutEmotesEnabled($cfg) && (($cfg['shout_stickers_enabled'] ?? '1') === '1');
}

function shoutEmoteMaxKb(array $cfg): int    { return max(8, min(512, (int)($cfg['shout_emote_max_kb'] ?? 64) ?: 64)); }
function shoutEmoteMaxBytes(array $cfg): int { return shoutEmoteMaxKb($cfg) * 1024; }
function shoutEmoteMaxPx(array $cfg): int    { return max(32, min(512, (int)($cfg['shout_emote_max_px'] ?? 128) ?: 128)); }
function shoutEmotePerUser(array $cfg): int  { return max(1, min(200, (int)($cfg['shout_emote_per_user'] ?? 20) ?: 20)); }

function shoutEmoteValidCode(string $code): bool { return (bool)preg_match(SHOUT_EMOTE_CODE_RE, $code); }

/** "thumbs_up" -> "Thumbs up". The fallback name when the uploader gave none. */
function shoutEmotePrettyName(string $code): string
{
    return ucfirst(trim((string)preg_replace('/[-_]+/', ' ', $code)));
}

/** A display name: printable, trimmed, bounded. '' when nothing usable is left. */
function shoutEmoteCleanName(string $name): string
{
    $name = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name));
    $name = (string)preg_replace('/\s+/', ' ', $name);
    return mb_substr($name, 0, SHOUT_EMOTE_NAME_MAX);
}

/* ── the table ─────────────────────────────────────────────────────────────── */

/** Forget the per-request cache. Called by everything that writes a row. */
function shoutEmotesInvalidate(): void { shoutEmotes(null, [], true, true); }

/**
 * The emotes, KEYED BY CODE, without their bytes.
 *
 * `$enabledOnly` (the default) is the reader's view and answers NOTHING while the feature is
 * switched off — which is what makes shoutRenderEmotes() a no-op rather than a second switch to
 * forget. `false` is the manager's view: the panel has to list what it may switch back on.
 *
 * Cached per request, because every rendered line asks for it.
 */
function shoutEmotes(?PDO $db, array $cfg, bool $enabledOnly = true, bool $flush = false): array
{
    static $cache = [];
    if ($flush) { $cache = []; return []; }
    if ($db === null) return [];
    $on = !$enabledOnly || shoutEmotesEnabled($cfg);
    $key = ($enabledOnly ? 'on' : 'all') . ':' . ($on ? '1' : '0');
    if (isset($cache[$key])) return $cache[$key];
    if (!$on) return $cache[$key] = [];

    $sql = "SELECT id, code, name, mime, bytes, width, height, is_sticker, enabled, uploaded_by, sha1, created_at
              FROM shout_emotes ";
    $sql .= $enabledOnly ? "WHERE enabled = 1 ORDER BY code" : "ORDER BY code";
    try {
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return $cache[$key] = [];   // the table arrives with schema 64; mid-upgrade there are none
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['code']] = [
            'id'          => (int)$r['id'],
            'code'        => (string)$r['code'],
            'name'        => (string)$r['name'],
            'mime'        => (string)$r['mime'],
            'bytes'       => (int)$r['bytes'],
            'width'       => $r['width'] === null ? null : (int)$r['width'],
            'height'      => $r['height'] === null ? null : (int)$r['height'],
            'is_sticker'  => (int)$r['is_sticker'] === 1,
            'enabled'     => (int)$r['enabled'] === 1,
            'uploaded_by' => $r['uploaded_by'] === null ? null : (int)$r['uploaded_by'],
            'sha1'        => (string)$r['sha1'],
            'created_at'  => (string)$r['created_at'],
        ];
    }
    return $cache[$key] = $out;
}

/** One emote WITH its bytes, or null. Only api/shout_emote.php needs this shape. */
function shoutEmoteGet(PDO $db, int $id): ?array
{
    if ($id <= 0) return null;
    try {
        $st = $db->prepare("SELECT id, code, name, mime, bytes, width, height, is_sticker, enabled, sha1, data FROM shout_emotes WHERE id = ?");
        $st->execute([$id]);
    } catch (\Throwable $e) {
        return null;
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** How many this account has uploaded. NULL asks for the shipped/panel ones. */
function shoutEmoteCountFor(PDO $db, ?int $uploader): int
{
    try {
        if ($uploader === null) {
            return (int)$db->query("SELECT COUNT(*) FROM shout_emotes WHERE uploaded_by IS NULL")->fetchColumn();
        }
        $st = $db->prepare("SELECT COUNT(*) FROM shout_emotes WHERE uploaded_by = ?");
        $st->execute([$uploader]);
        return (int)$st->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Where the image is. The first eight characters of the sha1 ride along, so replacing an emote is a
 * new address and the old one may be cached for a month — the trick api/sound.php uses.
 */
function shoutEmoteUrl(array $row, string $baseUrl = ''): string
{
    return $baseUrl . 'api.php?endpoint=shout_emote&id=' . (int)$row['id'] . '&v=' . substr((string)$row['sha1'], 0, 8);
}

/** One emote as the browser sees it: no sha1, no uploader, no bytes. */
function shoutEmoteForClient(array $row, string $baseUrl = ''): array
{
    return ['id' => (int)$row['id'], 'code' => (string)$row['code'], 'name' => (string)$row['name'],
            'url' => shoutEmoteUrl($row, $baseUrl), 'sticker' => !empty($row['is_sticker']),
            'w' => $row['width'] === null ? null : (int)$row['width'],
            'h' => $row['height'] === null ? null : (int)$row['height']];
}

/* ── drawing them ──────────────────────────────────────────────────────────── */

/** The image tag for one emote, inline or as a sticker. Every attribute escaped, nothing trusted. */
function shoutEmoteImg(array $row, string $baseUrl = '', bool $sticker = false): string
{
    $w = (int)($row['width'] ?? 0);
    $h = (int)($row['height'] ?? 0);
    $out = '<img class="' . ($sticker ? 'shout-sticker' : 'shout-emote') . '"'
         . ' src="' . htmlspecialchars(shoutEmoteUrl($row, $baseUrl), ENT_QUOTES, 'UTF-8') . '"'
         . ' alt="' . htmlspecialchars(':' . (string)$row['code'] . ':', ENT_QUOTES, 'UTF-8') . '"'
         . ' title="' . htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8') . '"'
         . ' loading="lazy" decoding="async"';
    if ($w > 0 && $h > 0) $out .= ' width="' . $w . '" height="' . $h . '"';
    return $out . '>';
}

/**
 * Replace `:code:` with an image, in the TEXT of already-rendered HTML and nowhere else.
 *
 * The split is on tags, exactly as shoutLinkMentions() does it and for the same reason: by this
 * point richtextRender() has escaped every byte the writer typed, so nothing between '<' and '>'
 * came from them — and `:code:` inside an href is part of an address, not a token.
 *
 * ONE STICKER ON ITS OWN IS THE WHOLE LINE. That is decided on the text content rather than on the
 * html, because markdown wraps a paragraph and bbcode leaves a line break, and neither of those is
 * the person saying something besides the sticker. The frontend relies on this: a shout that is one
 * sticker arrives as a single `<img class="shout-sticker">` and nothing else.
 */
function shoutRenderEmotes(string $html, array $emotes, string $baseUrl = '', bool $stickersOn = true): string
{
    if ($html === '' || !$emotes || !str_contains($html, ':')) return $html;

    if ($stickersOn) {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/^:([a-z0-9_]{2,32}):$/', $text, $m) && !empty($emotes[$m[1]]['is_sticker'])) {
            return shoutEmoteImg($emotes[$m[1]], $baseUrl, true);
        }
    }

    $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return $html;
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1 || $part === '' || !str_contains($part, ':')) continue;   // odd entries are the tags
        $parts[$i] = preg_replace_callback(SHOUT_EMOTE_TOKEN_RE, function ($m) use ($emotes, $baseUrl) {
            $e = $emotes[$m[1]] ?? null;
            return $e === null ? $m[0] : shoutEmoteImg($e, $baseUrl, false);
        }, $part) ?? $part;
    }
    return implode('', $parts);
}

/* ── what an upload is ─────────────────────────────────────────────────────── */

/**
 * Why these bytes are not an SVG this site will serve — or null when they are.
 *
 * Codes: not_svg | script | handler | javascript | data_html | foreign_object | embed | entity |
 * external_ref. The scheme checks run on the bytes with entities decoded and whitespace removed,
 * because a browser reads `java&#115;cript&#58;` and `java script :` as the word this refuses.
 */
function shoutEmoteSvgIssue(string $b): ?string
{
    // A BOM and leading whitespace are packaging, not content.
    $s = ltrim($b, "\xEF\xBB\xBF \t\r\n");
    if ($s === '' || !preg_match('/^(<\?xml|<!--|<!DOCTYPE|<svg)/i', substr($s, 0, 64))) return 'not_svg';
    $low = strtolower($s);
    if (!str_contains($low, '<svg')) return 'not_svg';

    if (preg_match('/<\s*script/i', $s)) return 'script';
    if (preg_match('/<\s*foreignobject/i', $s)) return 'foreign_object';
    if (preg_match('/<\s*(iframe|embed|object|frame|set|animate)\b/i', $s)) return 'embed';
    if (str_contains($low, '<!entity') || (str_contains($low, '<!doctype') && str_contains($low, 'entity'))) return 'entity';
    // An event handler is an attribute, so the character before it cannot be a letter: `monitor=`
    // is somebody's attribute name, `<svg onload=` is not.
    if (preg_match('/(?<![a-z-])on[a-z]+\s*=/i', $s)) return 'handler';

    // Entities decoded and whitespace gone: the two ways a scheme hides from a literal search.
    $flat = strtolower((string)preg_replace('/\s+/', '', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (str_contains($flat, 'javascript:')) return 'javascript';
    if (str_contains($flat, 'data:text/html')) return 'data_html';

    // A reference that leaves this document is a reference to somebody else's server. `#id` is the
    // only kind an emote needs, and refusing the rest is cheaper than judging origins.
    if (preg_match_all('/\b(?:xlink:href|href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $raw = ($one[1] ?? '') !== '' ? $one[1] : ((($one[2] ?? '') !== '') ? $one[2] : ($one[3] ?? ''));
            $v = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($v === '' || $v[0] === '#') continue;
            return 'external_ref';
        }
    }
    return null;
}

/** The size an SVG declares: its width/height, or failing that its viewBox. 0 when it says nothing. */
function shoutEmoteSvgSize(string $b): array
{
    if (!preg_match('/<svg\b[^>]*>/i', $b, $m)) return [0, 0];
    $tag = $m[0];
    $num = function (string $attr) use ($tag): int {
        if (!preg_match('/\b' . $attr . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $a)) return 0;
        $v = ($a[1] ?? '') !== '' ? $a[1] : ($a[2] ?? '');
        if (str_contains($v, '%')) return 0;                 // a percentage is not a size
        return preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)/', $v, $d) ? (int)round((float)$d[1]) : 0;
    };
    $w = $num('width');
    $h = $num('height');
    if (($w <= 0 || $h <= 0)
        && preg_match('/\bviewBox\s*=\s*["\']\s*[-0-9.]+[\s,]+[-0-9.]+[\s,]+([0-9.]+)[\s,]+([0-9.]+)/i', $tag, $v)) {
        if ($w <= 0) $w = (int)round((float)$v[1]);
        if ($h <= 0) $h = (int)round((float)$v[2]);
    }
    return [max(0, $w), max(0, $h)];
}

/**
 * What these bytes are, decided from the bytes: ['mime', 'ext', 'w', 'h'] or null.
 *
 * PNG, GIF and WebP are recognised by their magic bytes AND measured by getimagesizefromstring(),
 * which has to agree about the type — a GIF header in front of something else is not a GIF. An SVG
 * has to survive shoutEmoteSvgIssue() before it is a picture at all.
 */
function shoutEmoteSniff(string $b): ?array
{
    if ($b === '') return null;
    $png  = str_starts_with($b, "\x89PNG\r\n\x1a\n");
    $gif  = str_starts_with($b, 'GIF87a') || str_starts_with($b, 'GIF89a');
    $webp = strlen($b) > 12 && str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP';
    if ($png || $gif || $webp) {
        $info = @getimagesizefromstring($b);
        if (!is_array($info) || (int)($info[0] ?? 0) <= 0 || (int)($info[1] ?? 0) <= 0) return null;
        $want = $png ? IMAGETYPE_PNG : ($gif ? IMAGETYPE_GIF : IMAGETYPE_WEBP);
        if ((int)($info[2] ?? 0) !== $want) return null;
        $kind = [IMAGETYPE_PNG => ['image/png', 'png'], IMAGETYPE_GIF => ['image/gif', 'gif'],
                 IMAGETYPE_WEBP => ['image/webp', 'webp']][$want];
        return ['mime' => $kind[0], 'ext' => $kind[1], 'w' => (int)$info[0], 'h' => (int)$info[1]];
    }
    if (shoutEmoteSvgIssue($b) !== null) return null;
    [$w, $h] = shoutEmoteSvgSize($b);
    return ['mime' => 'image/svg+xml', 'ext' => 'svg', 'w' => $w, 'h' => $h];
}

/** The extension this site serves each type under. Never the uploader's file name. */
function shoutEmoteExt(string $mime): string
{
    return ['image/svg+xml' => 'svg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'bin';
}

/**
 * Keep an upload. ['ok' => true, 'row' => …] or ['ok' => false, 'error' => <lang key>, 'status' => N].
 *
 * `$uploader` is the account that asked, or NULL for the panel — and the per-person cap is the one
 * rule that difference changes, because the owner uploading from Settings is not "a person filling
 * the room with pictures", which is what that cap is about.
 *
 * The order is the order of the questions: is this a code at all, is it a plausible size, is it an
 * image, is it small enough on screen, has this person had enough, and only then whether anybody
 * already has these exact bytes or this exact code.
 */
function shoutEmoteStore(PDO $db, array $cfg, string $code, string $name, string $bytes, ?int $uploader, bool $sticker): array
{
    $code = strtolower(trim($code));
    if (!shoutEmoteValidCode($code)) return ['ok' => false, 'status' => 400, 'error' => 'api.emote.bad_code'];
    // A code the rich-text pass already turns into a Unicode emoji (richtextEmoji: fire, heart, star…)
    // would be replaced before shoutRenderEmotes() ever ran, so the uploaded image would never show.
    // Refuse it rather than store a picture nobody can see.
    if (function_exists('richtextEmoji') && isset(richtextEmoji()[$code])) {
        return ['ok' => false, 'status' => 409, 'error' => 'api.emote.code_reserved'];
    }
    $name = shoutEmoteCleanName($name);
    if ($name === '') $name = shoutEmotePrettyName($code);

    $len = strlen($bytes);
    if ($len < SHOUT_EMOTE_MIN_BYTES) return ['ok' => false, 'status' => 400, 'error' => 'api.emote.too_small'];
    $maxKb = shoutEmoteMaxKb($cfg);
    if ($len > $maxKb * 1024) return ['ok' => false, 'status' => 413, 'error' => 'api.emote.too_large', 'limit' => $maxKb];

    $kind = shoutEmoteSniff($bytes);
    if ($kind === null) {
        // "Not an image" and "an SVG this site will not serve" are different answers, and somebody
        // whose drawing was refused for carrying a script deserves to be told which one it was.
        $issue = shoutEmoteSvgIssue($bytes);
        return ['ok' => false, 'status' => 400, 'detail' => (string)$issue,
                'error' => ($issue !== null && $issue !== 'not_svg') ? 'api.emote.unsafe_svg' : 'api.emote.not_image'];
    }
    $px = shoutEmoteMaxPx($cfg);
    if ($kind['w'] > $px || $kind['h'] > $px) {
        return ['ok' => false, 'status' => 400, 'error' => 'api.emote.too_big_px', 'limit' => $px,
                'detail' => $kind['w'] . 'x' . $kind['h']];
    }
    if ($uploader !== null) {
        $cap = shoutEmotePerUser($cfg);
        if (shoutEmoteCountFor($db, $uploader) >= $cap) {
            return ['ok' => false, 'status' => 409, 'error' => 'api.emote.too_many', 'limit' => $cap];
        }
    }

    $sha = sha1($bytes);
    $st = $db->prepare("SELECT code FROM shout_emotes WHERE sha1 = ? LIMIT 1");
    $st->execute([$sha]);
    $same = $st->fetchColumn();
    if ($same !== false) return ['ok' => false, 'status' => 409, 'error' => 'api.emote.duplicate', 'detail' => (string)$same];
    $st = $db->prepare("SELECT id FROM shout_emotes WHERE code = ? LIMIT 1");
    $st->execute([$code]);
    if ($st->fetchColumn() !== false) return ['ok' => false, 'status' => 409, 'error' => 'api.emote.code_taken'];

    $st = $db->prepare("INSERT INTO shout_emotes (code, name, mime, bytes, width, height, is_sticker, enabled, uploaded_by, sha1, data)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");
    $st->bindValue(1, $code);
    $st->bindValue(2, $name);
    $st->bindValue(3, $kind['mime']);
    $st->bindValue(4, $len, PDO::PARAM_INT);
    $st->bindValue(5, $kind['w'] > 0 ? $kind['w'] : null, $kind['w'] > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $st->bindValue(6, $kind['h'] > 0 ? $kind['h'] : null, $kind['h'] > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $st->bindValue(7, $sticker ? 1 : 0, PDO::PARAM_INT);
    $st->bindValue(8, $uploader, $uploader === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(9, $sha);
    $st->bindValue(10, $bytes, PDO::PARAM_LOB);
    $st->execute();
    shoutEmotesInvalidate();

    $id = (int)$db->lastInsertId();
    return ['ok' => true, 'status' => 200, 'row' => [
        'id' => $id, 'code' => $code, 'name' => $name, 'mime' => $kind['mime'], 'bytes' => $len,
        'width' => $kind['w'] > 0 ? $kind['w'] : null, 'height' => $kind['h'] > 0 ? $kind['h'] : null,
        'is_sticker' => $sticker, 'enabled' => true, 'uploaded_by' => $uploader, 'sha1' => $sha,
    ]];
}

/**
 * Remove one. `$asUser` NULL is the panel; an id means "and it has to be theirs" — a member's own
 * upload and nothing else, which is why a shipped emote (uploaded_by NULL) can never match one.
 *
 * The endpoint is what decides whether a moderator may pass NULL; this only enforces ownership.
 */
function shoutEmoteDelete(PDO $db, int $id, ?int $asUser = null): array
{
    if ($id <= 0) return ['ok' => false, 'status' => 404, 'error' => 'api.emote.unknown'];
    $st = $db->prepare("SELECT id, code, name, uploaded_by FROM shout_emotes WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'status' => 404, 'error' => 'api.emote.unknown'];
    if ($asUser !== null && ($row['uploaded_by'] === null || (int)$row['uploaded_by'] !== $asUser)) {
        return ['ok' => false, 'status' => 403, 'error' => 'api.emote.not_yours'];
    }
    $db->prepare("DELETE FROM shout_emotes WHERE id = ?")->execute([$id]);
    shoutEmotesInvalidate();
    return ['ok' => true, 'status' => 200,
            'row' => ['id' => (int)$row['id'], 'code' => (string)$row['code'], 'name' => (string)$row['name'],
                      'uploaded_by' => $row['uploaded_by'] === null ? null : (int)$row['uploaded_by']]];
}

/**
 * Switch one flag on a row. `$field` is one of two names and each has its own literal statement —
 * there is no column name built from a string here, and there is not going to be one.
 */
function shoutEmoteSetFlag(PDO $db, int $id, string $field, bool $on): bool
{
    $sql = match ($field) {
        'enabled'    => "UPDATE shout_emotes SET enabled = ? WHERE id = ?",
        'is_sticker' => "UPDATE shout_emotes SET is_sticker = ? WHERE id = ?",
        default      => '',
    };
    if ($sql === '' || $id <= 0) return false;
    $st = $db->prepare($sql);
    $st->execute([$on ? 1 : 0, $id]);
    shoutEmotesInvalidate();
    return $st->rowCount() > 0;
}
