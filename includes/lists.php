<?php
/**
 * Lists — collections somebody makes on purpose.
 *
 * ── how this differs from favourites, and why it is its own thing ──────────────────────────────
 *
 * A favourite is one bit about one hash: I like this. A list is a THING somebody made — it has a
 * name they chose, a reason they had, and its own answer to "who may see this". So it is two tables
 * of its own rather than a column on `user_favourites`: un-starring a torrent must never silently
 * empty somebody's pack, and a pack must be able to hold a hash its owner has not starred.
 *
 * ── the switches, and which one wins ──────────────────────────────────────────────────────────
 *
 * A list is visible to a stranger only when EVERY one of these says yes:
 *   1. `lists_enabled`          — the operator switched the feature on at all
 *   2. `lists_public_enabled`   — the operator allows any list to be shared at all
 *   3. `lists.public`           — the owner's group is allowed to publish (the GRANT)
 *   4. `users.lists_public`     — the owner shows the section on their profile
 *   5. `user_lists.visibility`  — the owner published THIS list ('public')
 *
 * The site-wide switch (2) can only ever narrow. Turning it off takes every list back off the
 * shared side without editing anybody's row, and their own choice is remembered — it takes effect
 * again when the operator turns it back on. That is the same rule favourites already follow, and
 * the reason the per-list answer is never rewritten by a settings change.
 *
 * ── friends only (1.72.0, schema 87) ──────────────────────────────────────────────────────────
 *
 * The per-list answer is a VISIBILITY — private, friends, public (a boolean `is_public` until v87). A
 * list shared with 'friends' is seen by its owner and by the members they are friends with, and nobody
 * else, when every one of these says yes:
 *   1, 2 and 4 above — the feature, the site's "lists may be shared" (off, EVERY list is private, a
 *                       friends list too) and the owner's section;
 *   6. `friends_enabled`        — the operator runs the friends feature at all (includes/people.php);
 *   7. `friends.use`            — the owner's account may use it: a feature, not consent, so asked as
 *                                 every feature is (userIdHasPermission() — the administrator's blanket
 *                                 counts, as it does for the friends page itself), of the named account;
 *   8. an ACCEPTED friendship between the owner and the reader, either way round (areFriends()), and no
 *      block between them in either direction: blocking somebody ends the friendship already
 *      (api/user_people.php), and a friendship row that outlived a block is still no friendship.
 * No new permission: a friend is somebody the owner chose, and sharing with them is the narrower share —
 * `lists.public` is consent to be seen by STRANGERS, which a friends list is not. The option is offered
 * exactly when 6 and 7 hold (and 1, 2), so a choice is never saved that nothing acts on; losing one of them
 * later makes the list private in effect, the choice remembered — the grant's rule. Nothing about it is
 * cached: every read asks the friendship table, so an unfriending takes the access away on the next request.
 *
 * ── whose business the state is ──────────────────────────────────────────────────────────────
 *
 * The answer (private / friends / public) is told to its OWNER only. A reader only ever gets the lists they
 * may see, so a badge would tell them nothing — except, between a friend's view and a stranger's, which of
 * the owner's lists are shared with whom. The endpoints leave it out of every answer but the owner's.
 */

// The lists a hash is on — "who has this" (1.70.0) — are asked there, one query for the overlay and for
// listsContainingHash() below.
require_once __DIR__ . '/who.php';
// Who is a friend, and who has blocked whom (1.72.0): a list shared with friends asks the friends feature's
// own answers (friendsEnabled(), areFriends(), blockRow()), wherever lists are loaded from.
require_once __DIR__ . '/people.php';
// How fast a list's name and description may be written, and whether a new account's links are words (1.71.0):
// the site's one anti-spam layer, which the lists' endpoints ask wherever they are loaded from.
require_once __DIR__ . '/antispam.php';

/** The master switch. Off, every endpoint answers 404 and no page draws anything about lists. */
function listsEnabled(array $cfg): bool
{
    return usersEnabled($cfg) && (($cfg['lists_enabled'] ?? '0') === '1');
}

/** May any list be public at all? Site-wide, and it only narrows what a reader chose. */
function listsPublicEnabled(array $cfg): bool
{
    return listsEnabled($cfg) && (($cfg['lists_public_enabled'] ?? '0') === '1');
}

/** How many lists one account may keep. Clamped, so a hand-edited settings row cannot uncap it. */
function listsMaxPerUser(array $cfg): int
{
    return max(1, min(200, (int)($cfg['lists_max_per_user'] ?? 20) ?: 20));
}

/** How many torrents may sit in one list. Same reasoning as fav_max_per_user. */
function listsMaxItems(array $cfg): int
{
    return max(10, min(5000, (int)($cfg['lists_max_items'] ?? 500) ?: 500));
}

/* ── the description (v80, 1.70.0) ────────────────────────────────────────────────────────────────
 *
 * The site's rich text — BBCode or Markdown, whichever Settings allows (richtextFormats()) — kept as
 * typed with its format, and drawn by the description renderer with the room's emotes and stickers
 * (richtextRenderIn('list', …): an emote inline, a sticker bounded to 96 px — includes/shout.php says why).
 *
 * WHAT COUNTS. The limit Settings sets (`lists_desc_max`) is what a READER sees — the words, not the tags
 * that format them — the way the profile's description counts (includes/profilebio.php); a token
 * (`:fire:`, `:fa-rocket:`, an emote's `:code:`) counts as the characters it is typed as. It is counted
 * by a STRIP of the syntax (listDescVisible()), not by a render, because the browser has to count it the
 * same way while somebody types: assets/js/favourites.js carries its twin, so the counter under the box,
 * the Preview's counter and the save all say one number. The text as typed has a ceiling of its own —
 * four times the visible limit, never more than 16 000 characters — so tags cannot store a novel in a
 * field whose visible limit is a thousand.
 *
 * NO PICTURES — stricter than a torrent's description, on purpose. A torrent's description waits in the
 * review queue before anybody reads it; a list's is published by its owner the moment it is saved, and a
 * picture from another host is a request from every reader's browser to whoever runs that host (their
 * address, their time, which list they opened). The room's emotes and stickers are the pictures a list
 * may have: the site's own images, approved by its operator, from its own endpoint. So `[img]` and
 * `![](…)` are refused by the save, drawn as nothing if a row has one anyway, and the editor offers no
 * picture button. Links keep the description's rules: http and https only, rel="nofollow noopener
 * noreferrer ugc", a new tab, the "you are leaving" warning, at most `desc_max_links` of them.
 */
const LIST_NAME_MAX           = 80;
const LIST_DESC_MAX_MIN       = 50;
const LIST_DESC_MAX_MAX       = 5000;
const LIST_DESC_MAX_DEFAULT   = 1000;
const LIST_DESC_SOURCE_FACTOR = 4;         // the text as typed may be this many times the visible limit…
const LIST_DESC_SOURCE_CHARS  = 16000;     // …and never more characters than this (four bytes each still fit a TEXT)
const LIST_DESC_SOURCE_BYTES  = 65000;     // …nor more bytes than a TEXT column holds, with room to spare
const LIST_DESC_RAW_BYTES     = 65536;     // what a request may carry at all, before any work is done
const LIST_DESC_EXCERPT       = 200;       // the card's excerpt, before the stylesheet folds it to two lines

/** The longest description, in characters a reader sees, as Settings says — clamped. */
function listsDescMax(array $cfg): int
{
    $v = (int)($cfg['lists_desc_max'] ?? LIST_DESC_MAX_DEFAULT);
    return max(LIST_DESC_MAX_MIN, min(LIST_DESC_MAX_MAX, $v ?: LIST_DESC_MAX_DEFAULT));
}

/** The most characters the text AS TYPED may hold, tags included. */
function listDescSourceCap(array $cfg): int
{
    return min(listsDescMax($cfg) * LIST_DESC_SOURCE_FACTOR, LIST_DESC_SOURCE_CHARS);
}

/** A stored format as the renderer takes it: one of the two syntaxes, BBCode for anything else. */
function listDescFormatOf($format): string
{
    return $format === 'markdown' ? 'markdown' : 'bbcode';
}

/**
 * A description as it is kept and judged: one kind of line break, trimmed, and without what is no text at
 * all — the C0 and C1 controls but the tab and the line break (three C0 ones are the renderer's own
 * placeholders) and the bidi embeddings, overrides and isolates, which make a link's words read backwards
 * (profileBioClean()'s reasons; its folding of spaces is not taken — a code block here keeps its own).
 * U+2060 stays: it is the escape of the texts v80 rewrote (schemaListDescPlainToBbcode()).
 */
function listDescClean(string $raw): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $raw);
    $s = preg_replace('/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s) ?? $s;
    return trim($s);
}

/**
 * The text a reader sees, as a strip of the syntax — what listDescVisible() counts and the excerpt is cut
 * from. The TWIN of listDescStrip() in assets/js/favourites.js, pattern for pattern: change one, change
 * both (tests/lists_test.php holds them to the same answers). BBCode: a picture goes whole, every tag the
 * renderer knows goes and its words stay. Markdown: a picture goes, a link is its words, the marks at the
 * start of a line (a heading, a quote, a bullet, a number) and the paired ones (** ~~ == || ` ^ ~ *) go.
 * Both: U+2060 draws nothing — taken out AFTER the syntax, so a tag v80 escaped (`[⁠b]`, shown as the
 * text it is) is counted as the text it is — and every run of white space is one character, as a page
 * draws it. ($keepJoiner is the excerpt's: it draws the tokens after this, and an escaped token must not
 * become one there.)
 */
function listDescStrip(string $text, string $format, bool $keepJoiner = false): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $text);
    if ($format === 'markdown') {
        $s = preg_replace('/!\[[^\]\n]*\]\([^) \t\n\x0B\f\r]*\)/u', '', $s) ?? $s;
        $s = preg_replace('/\[([^\]\n]*)\]\([^) \t\n\x0B\f\r]*\)/u', '$1', $s) ?? $s;
        $s = preg_replace('/(^|\n)[ \t]*(?:#{1,6}|>|[-*+]|\d{1,3}[.)])[ \t]+/u', '$1', $s) ?? $s;
        $s = preg_replace('/==/u', '', $s) ?? $s;
        $s = preg_replace('/[*~^`|]/u', '', $s) ?? $s;
    } else {
        $s = preg_replace('/\[img(?:=[^\]\n]*)?\][\s\S]*?\[\/img\]/iu', '', $s) ?? $s;
        $s = preg_replace('/\[\/?(?:b|i|u|s|sub|sup|color|size|font|highlight|mark|center|right|left|quote|spoiler|url|email|list|table|tr|th|td|code|hide|postshide|youtube|yt|hr|\*)(?:=[^\]\n]*)?\]/iu', '', $s) ?? $s;
    }
    if (!$keepJoiner) $s = str_replace("\u{2060}", '', $s);
    $s = preg_replace('/[ \t\n\x0B\f]+/u', ' ', $s) ?? $s;
    return trim($s, ' ');
}

/** How many characters a reader sees: code points of the strip above (an emoji is one, a token as typed). */
function listDescVisible(string $text, string $format): int
{
    return mb_strlen(listDescStrip($text, listDescFormatOf($format)), 'UTF-8');
}

/**
 * Everything wrong with a CLEANED description, as ['code' => …, 'vars' => […]] for api.lists.<code>, or
 * null. The cheap, bounding questions first: the text's size before it is walked, the syntax before it is
 * rendered — and one render, by richtextCount(), for the pictures and the links.
 */
function listDescProblem(string $clean, string $format, array $cfg): ?array
{
    if ($clean === '') return null;
    $cap = listDescSourceCap($cfg);
    if (mb_strlen($clean, 'UTF-8') > $cap || strlen($clean) > LIST_DESC_SOURCE_BYTES) {
        return ['code' => 'too_long_source', 'vars' => ['max' => $cap]];
    }
    if (!in_array($format, richtextFormats($cfg), true)) return ['code' => 'bad_format', 'vars' => []];
    $n = listDescVisible($clean, $format);
    $max = listsDescMax($cfg);
    if ($n > $max) return ['code' => 'too_long', 'vars' => ['n' => $n, 'max' => $max]];
    $c = richtextCount($clean, $format, $cfg);
    if ($c['images'] > 0) return ['code' => 'no_images', 'vars' => []];
    $maxLinks = richtextMaxLinks($cfg);
    if ($c['links'] > $maxLinks) return ['code' => 'too_many_links', 'vars' => ['n' => $c['links'], 'max' => $maxLinks]];
    return null;
}

/**
 * A stored description as the list's window draws it — '' for none. The description renderer and the
 * room's emotes (richtextRenderIn('list', …)), and then no picture from elsewhere: the renderer's own
 * `<img class="rt-img">` dropped with a paragraph that held nothing else — a row the save would refuse
 * (typed into a database client, restored from an old backup) still shows none. The emotes are
 * `rt-emote` / `rt-sticker` and stay. The format as stored: a description written as Markdown stays
 * Markdown after the operator allows BBCode only, as a torrent's does.
 *
 * `$linksText` (1.71.0, includes/antispam.php): written while its owner's account was new — every link is
 * drawn as its words (antispamWrittenNew(), asked by the caller from when the list was last written).
 */
function listDescRender(?PDO $db, array $cfg, ?string $text, $format, bool $linksText = false): string
{
    $text = (string)$text;
    if (trim($text) === '') return '';
    $html = richtextRenderIn('list', $db, $text, listDescFormatOf($format), $linksText ? ['rt_links_text' => '1'] + $cfg : $cfg,
                             function_exists('richtextViewerSignedIn') ? richtextViewerSignedIn($db) : false);
    $html = preg_replace('#<img class="rt-img"[^>]*>#', '', $html) ?? $html;
    return preg_replace('#<p>\s*</p>#', '', $html) ?? $html;
}

/**
 * The card's line or two: the text a reader sees (listDescStrip()), without what is hidden or folded —
 * a [hide] block's words (a summary is not the place for them, whoever reads it), a spoiler's (that is
 * its point), and nothing at all past a hide fence that does not close (the renderer's own rule) — the
 * shortcodes as their emoji and Font Awesome's tokens as what a mail shows (a face's emoji, `[Rocket]`),
 * an emote as its code. Plain text: the page puts it in with textContent.
 */
function listDescExcerpt(?string $text, $format, array $cfg, int $len = LIST_DESC_EXCERPT): string
{
    // U+2060 kept until the tokens are drawn: in a text v80 rewrote it is what keeps `[hide]`, `:fire:` or
    // `:fa-rocket:` the words they were — here as in the window.
    $s = (string)$text;
    if (trim(str_replace("\u{2060}", '', $s)) === '') return '';
    $fmt = listDescFormatOf($format);
    $s = preg_replace('/\[(hide|postshide)(?:=[^\]]*)?\][\s\S]*?\[\/\1\]/i', ' ', $s) ?? $s;
    if (preg_match('/\[\/?(?:hide|postshide)\b/i', $s)) return '';
    $s = $fmt === 'markdown'
        ? (preg_replace('/\|\|[\s\S]*?\|\|/', ' ', $s) ?? $s)
        : (preg_replace('/\[spoiler(?:=[^\]]*)?\][\s\S]*?\[\/spoiler\]/i', ' ', $s) ?? $s);
    $plain = listDescStrip($s, $fmt, true);
    if (function_exists('richtextEmoji')) {
        $map = richtextEmoji();
        $plain = preg_replace_callback('/:([a-z0-9_+-]{1,24}):/', fn($m) => $map[$m[1]] ?? $m[0], $plain) ?? $plain;
    }
    if (str_contains($plain, ':fa-') && function_exists('emojiFaRenderHtml')) {
        $html = emojiFaRenderHtml(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), array_merge($cfg, ['shout_emoji_fa' => 'off']));
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $plain = str_replace("\u{2060}", '', $plain);
    $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    return mb_strlen($plain, 'UTF-8') > $len ? rtrim(mb_substr($plain, 0, $len - 1, 'UTF-8')) . '…' : $plain;
}

/** What a shelf is told about one list's description: the text as typed (the editor's), its format, the card's excerpt. */
function listDescForClient(array $row, array $cfg): array
{
    $text = (string)($row['description'] ?? '');
    $fmt = listDescFormatOf($row['description_format'] ?? null);
    return ['description' => $text, 'description_format' => $fmt, 'excerpt' => listDescExcerpt($text, $fmt, $cfg)];
}

/**
 * POST user_lists {op: 'edit', id, name, description, format} — the name and the description in ONE
 * request (the Edit window), as ['status' => int, 'body' => array]. `describe` (the description alone,
 * `value` or `description`) is the same without the name, so no second path writes a description.
 *
 * The endpoint has already asked everything every op asks — the token, somebody signed in, `lists.use`,
 * the hourly rate limit — and found the row ($own) by id AND owner. This asks the rest in the order a
 * person would: the name, the text's size and encoding before it is walked, whether it CHANGED, then (only
 * then) a moderator's mute and the description's own rules. What is not changed is not judged again: a
 * rename of a list whose description was written under a longer limit, or in a syntax Settings has since
 * switched off, is still a rename. The slug is never rebuilt — a link somebody was sent keeps working.
 *
 * `visibility` (1.72.0): who sees it — 'private' | 'friends' | 'public' — saved in the same request, the Edit
 * window's third question. Absent is "as it is". An answer this account may not give (listVisibilityAllowed())
 * is refused, 403 no_permission, nothing written — unless it is the one the list already has: the same "not
 * changed, not judged again", so a list published before its owner's group lost the grant can still be renamed.
 */
function listEditRequest(PDO $db, array $cfg, array $me, array $own, array $input, bool $withName = true): array
{
    $fail = static function (int $status, string $code, array $vars = []): array {
        return ['status' => $status, 'body' => ['success' => false, 'error' => $code, 'message' => __('api.lists.' . $code, $vars)]];
    };
    $name = (string)$own['name'];
    if ($withName) {
        $rawName = $input['name'] ?? '';
        if (!is_string($rawName)) return $fail(400, 'name_required');
        if (!mb_check_encoding($rawName, 'UTF-8')) return $fail(400, 'bad_encoding');
        $name = trim($rawName);
        if ($name === '') return $fail(400, 'name_required');
        if (mb_strlen($name, 'UTF-8') > LIST_NAME_MAX) return $fail(400, 'name_too_long', ['max' => LIST_NAME_MAX]);
    }
    $wasText = (string)($own['description'] ?? '');
    $wasFmt = listDescFormatOf($own['description_format'] ?? null);
    // Absent is "as it is" — a request that says nothing about the description does not wipe it.
    $raw = $input['description'] ?? ($withName ? null : ($input['value'] ?? null)) ?? $wasText;
    if (!is_string($raw)) return $fail(400, 'invalid');
    if (strlen($raw) > LIST_DESC_RAW_BYTES) return $fail(413, 'too_long_source', ['max' => listDescSourceCap($cfg)]);
    if (!mb_check_encoding($raw, 'UTF-8')) return $fail(400, 'bad_encoding');
    $desc = listDescClean($raw);

    $fmtIn = $input['format'] ?? null;
    if ($fmtIn !== null && !is_string($fmtIn)) return $fail(400, 'bad_format');
    $fmt = $fmtIn === null || $fmtIn === '' ? $wasFmt : $fmtIn;
    if ($desc === '') {
        // Nothing written has no syntax to refuse: kept in the one asked for when it is one the site takes.
        $allowed = richtextFormats($cfg);
        $fmt = in_array($fmt, $allowed, true) ? $fmt : ($allowed[0] ?? 'bbcode');
    } elseif ($desc !== $wasText || $fmt !== $wasFmt) {
        // Silenced by a moderator (messages, the room and the profile's description obey it already): a
        // description is words other people read. Taking yours away never waits for anybody.
        $until = function_exists('pmMutedUntil') ? pmMutedUntil($me) : null;
        if ($until !== null) return $fail(403, 'muted', ['until' => $until]);
        $bad = listDescProblem($desc, $fmt, $cfg);
        if ($bad !== null) return $fail(400, $bad['code'], $bad['vars']);
    }
    $fmt = listDescFormatOf($fmt);
    $wasVis = listVisibilityOf($own['visibility'] ?? null);
    $vis = $wasVis;
    if (($input['visibility'] ?? null) !== null) {
        $vis = listVisibilityFromInput($input['visibility']);
        if ($vis === null) return $fail(400, 'bad_visibility');
        if ($vis !== $wasVis && !listVisibilityAllowed($db, $cfg, (int)$me['id'], $vis)) return $fail(403, 'no_permission');
    }
    $db->prepare("UPDATE user_lists SET name = ?, description = ?, description_format = ?, visibility = ? WHERE id = ? AND user_id = ?")
       ->execute([$name, $desc, $fmt, $vis, (int)$own['id'], (int)$me['id']]);
    return ['status' => 200, 'body' => [
        'success'            => true,
        'name'               => $name,
        'slug'               => (string)$own['slug'],
        'description'        => $desc,
        'description_format' => $fmt,
        'visibility'         => $vis,
        'excerpt'            => listDescExcerpt($desc, $fmt, $cfg),
        'chars'              => $desc === '' ? 0 : listDescVisible($desc, $fmt),
        'max'                => listsDescMax($cfg),
        'message'            => __('api.lists.saved'),
    ]];
}

/**
 * Does an `edit` / `describe` request change what people READ — the name, the description or its syntax —
 * or only who sees the list (1.72.0)? Asked by the endpoint before the anti-spam layer: showing, hiding and
 * deleting your own list is not writing, and the Edit window, where the visibility is chosen now, sends all of
 * it in one request. The same normalisation listEditRequest() applies; anything it would refuse counts as
 * words (the layer is asked, and hands the reservation back when the request fails).
 */
function listEditChangesWords(array $own, array $input, bool $withName = true): bool
{
    if ($withName) {
        $n = $input['name'] ?? '';
        if (!is_string($n) || trim($n) !== (string)$own['name']) return true;
    }
    $wasText = (string)($own['description'] ?? '');
    $wasFmt = listDescFormatOf($own['description_format'] ?? null);
    $raw = $input['description'] ?? ($withName ? null : ($input['value'] ?? null)) ?? $wasText;
    if (!is_string($raw) || strlen($raw) > LIST_DESC_RAW_BYTES || !mb_check_encoding($raw, 'UTF-8')) return true;
    $desc = listDescClean($raw);
    if ($desc !== $wasText) return true;
    $fmtIn = $input['format'] ?? null;
    if ($fmtIn !== null && !is_string($fmtIn)) return true;
    $fmt = $fmtIn === null || $fmtIn === '' ? $wasFmt : $fmtIn;
    return $desc !== '' && $fmt !== $wasFmt;
}

/**
 * A URL-safe name for a list, derived once and then LEFT ALONE.
 *
 * The slug is what a link somebody sent points at, so renaming a list must not break it. This runs
 * when a list is created and never again; two lists of one person cannot share one, which is what
 * the unique key enforces and what the numeric suffix below is for.
 */
function listSlugify(string $name): string
{
    $s = function_exists('transliterator_transliterate')
        ? (string)transliterator_transliterate('Any-Latin; Latin-ASCII', $name)
        : $name;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    $s = mb_substr($s, 0, 60);
    return $s === '' ? 'list' : $s;
}

/**
 * The slug this person may actually use, with a number on the end if the plain one is taken.
 *
 * This LOOKS then the caller WRITES, so on its own it is a race: two tabs creating "Films" at the
 * same moment both find `films` free. It is not made safe here — a loop that re-checked after the
 * insert would still be guessing. The caller holds the account's own row (`SELECT … FOR UPDATE`),
 * which serialises one person against themselves, and uq_list_slug is the backstop behind that:
 * api/user_lists.php turns SQLSTATE 23000 into a 409 rather than a 500.
 */
function listUniqueSlug(PDO $db, int $userId, string $name, int $exceptId = 0): string
{
    $base = listSlugify($name);
    $st = $db->prepare("SELECT 1 FROM user_lists WHERE user_id = ? AND slug = ? AND id <> ? LIMIT 1");
    for ($i = 0; $i < 50; $i++) {
        $try = $i === 0 ? $base : ($base . '-' . ($i + 1));
        $st->execute([$userId, $try, $exceptId]);
        if (!$st->fetchColumn()) return $try;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

/* ── who sees a list (1.72.0, schema 87) ──────────────────────────────────────────────────────────
 *
 * The three answers a list can give, and what each needs — the top of this file says it at length. One
 * place, asked by every path that serves a list or its rows: the owner's shelf and the Edit window (what
 * may be CHOSEN), the profile, the list endpoints and "who has this" (what may be READ) — so the option
 * offered and the readers can never disagree.
 */
const LIST_VISIBILITIES = ['private', 'friends', 'public'];

/** A stored visibility as the code takes it: one of the three; private for anything else (NULL mid-migration). */
function listVisibilityOf($v): string
{
    return is_string($v) && in_array($v, LIST_VISIBILITIES, true) ? $v : 'private';
}

/**
 * What a request asked for, or null when it is none of the answers: 'private' | 'friends' | 'public' — and,
 * for a client of the 1.44.0 op, the boolean it sent (1 / true = public, 0 / false = private).
 */
function listVisibilityFromInput($v): ?string
{
    if (is_string($v) && in_array($v, LIST_VISIBILITIES, true)) return $v;
    if ($v === true || $v === 1 || $v === '1' || $v === 'true') return 'public';
    if ($v === false || $v === 0 || $v === '0' || $v === 'false' || $v === '') return 'private';
    return null;
}

/** May a list be shared with friends on this site at all: lists may be shared, and the friends feature runs. */
function listsFriendsEnabled(array $cfg): bool
{
    return listsPublicEnabled($cfg) && function_exists('friendsEnabled') && friendsEnabled($cfg);
}

/**
 * May this ACCOUNT make a list public: the site switch and its group's GRANT, for the reason favContext()
 * gives — publishing is consent, and every page that reads a published list asks the grant. A control that
 * asks a different question is a control that saves an answer nothing acts on.
 */
function listsMayPublish(PDO $db, array $cfg, int $userId): bool
{
    return $userId > 0 && listsPublicEnabled($cfg) && userIdHasGrantedPermission($db, $cfg, $userId, 'lists.public');
}

/**
 * May this ACCOUNT share a list with its friends: the site (listsFriendsEnabled()) and the account's own
 * `friends.use`. A feature, not consent — asked as the friends page asks it, the administrator's blanket
 * included — and of the named account, never of whoever holds the session (the owner's panel session answers
 * yes to everything, and a reader's permission is not the owner's).
 */
function listsMayShareFriends(PDO $db, array $cfg, int $userId): bool
{
    return $userId > 0 && listsFriendsEnabled($cfg) && userIdHasPermission($db, $cfg, $userId, 'friends.use');
}

/** May this account choose this answer for its own list? Private always: taking a list back never waits for anybody. */
function listVisibilityAllowed(PDO $db, array $cfg, int $userId, string $vis): bool
{
    if ($vis === 'public') return listsMayPublish($db, $cfg, $userId);
    if ($vis === 'friends') return listsMayShareFriends($db, $cfg, $userId);
    return $vis === 'private';
}

/**
 * Are these two friends, as far as a list is concerned: an ACCEPTED friendship, either way round, and no block
 * between them in either direction. Blocking somebody ends the friendship (api/user_people.php); a friendship
 * row that outlived a block — a restored backup, a request answered in the same moment — is still none.
 */
function listsFriendOf(PDO $db, int $ownerId, int $viewerId): bool
{
    if ($ownerId <= 0 || $viewerId <= 0 || $ownerId === $viewerId || !function_exists('areFriends')) return false;
    return areFriends($db, $ownerId, $viewerId)
        && blockRow($db, $ownerId, $viewerId) === null && blockRow($db, $viewerId, $ownerId) === null;
}

/**
 * Which of an owner's lists a reader who is NOT the owner may see: [] (none — and then not the section
 * either), or the answers they may, of 'public' and 'friends'. `$owner` is the account's row (id,
 * lists_public); `$viewerId` the reader's account (0: nobody — then the public side alone, which is what the
 * 1.44.0 callers asked).
 *
 * The caller has asked what every read of somebody else asks first — somebody signed in, profiles on,
 * `favourites.view_others`, the account active, no `hide_profile` block. This asks the rest: the site's
 * switch and the owner's section, then each answer's own — the GRANT of `lists.public` for the public
 * side (consent, never the administrator's blanket: includes/favourites.php has the whole argument), the
 * friends feature, the owner's `friends.use` and a friendship with THIS reader for the friends side.
 */
function listsVisibilitiesFor(PDO $db, array $cfg, array $owner, int $viewerId = 0): array
{
    $oid = (int)($owner['id'] ?? 0);
    if ($oid <= 0 || !listsPublicEnabled($cfg) || (int)($owner['lists_public'] ?? 0) !== 1) return [];
    $out = [];
    if (userIdHasGrantedPermission($db, $cfg, $oid, 'lists.public')) $out[] = 'public';
    if ($viewerId > 0 && $viewerId !== $oid && listsMayShareFriends($db, $cfg, $oid) && listsFriendOf($db, $oid, $viewerId)) $out[] = 'friends';
    return $out;
}

/**
 * Are somebody ELSE's lists visible on their profile — to this reader ($viewerId), or, without one, to a
 * stranger? The section exists for a reader who may see at least one kind of the owner's lists.
 */
function listsVisibleFor(PDO $db, array $cfg, array $owner, int $viewerId = 0): bool
{
    return listsVisibilitiesFor($db, $cfg, $owner, $viewerId) !== [];
}

/**
 * Everything a page needs to know about one reader and the lists feature, in one call.
 *
 * Mirrors favContext() deliberately: a page that has to reassemble five gates is a page that will
 * get one of them wrong, and the two features answer the same shape of question.
 */
function listsContext(PDO $db, array $cfg, ?array $viewer): array
{
    $on = listsEnabled($cfg);
    $mayUse = $on && $viewer !== null && userCan($db, $cfg, 'lists.use');
    $uid = $viewer !== null ? (int)$viewer['id'] : 0;
    $grant = $uid > 0 && userIdHasGrantedPermission($db, $cfg, $uid, 'lists.public');
    $friends = $mayUse && listsMayShareFriends($db, $cfg, $uid);
    return [
        'enabled'     => $on,
        'public_ok'   => listsPublicEnabled($cfg),
        'may_use'     => $mayUse,
        // May THIS reader mark a list public — the site switch and their group's grant.
        'may_publish' => $mayUse && listsPublicEnabled($cfg) && $grant,
        'publish_blocked' => $mayUse && listsPublicEnabled($cfg) && !$grant,
        // …and share one with their friends (1.72.0): the site, the friends feature and their friends.use.
        'may_friends' => $friends,
        // Why a choice the Edit window draws is not theirs to make — '' when it is: the site keeps every list
        // private ('sharing_off'), the friends feature is off ('friends_off'), their groups do not give them
        // friends.use ('no_friends') or do not grant lists.public ('no_grant').
        'public_why'  => !$mayUse ? '' : (!listsPublicEnabled($cfg) ? 'sharing_off' : ($grant ? '' : 'no_grant')),
        'friends_why' => !$mayUse || $friends ? '' : (!listsPublicEnabled($cfg) ? 'sharing_off'
                         : (!listsFriendsEnabled($cfg) ? 'friends_off' : 'no_friends')),
        // May they read somebody else's? The same permission that opens profiles and favourites:
        // one decision about whether this install shows people to each other at all — asked of the READER's
        // account (1.74.0, PRIV-6), as the endpoints behind it ask it, never of a panel session.
        'may_view'    => $on && $uid > 0 && userIdHasPermission($db, $cfg, $uid, 'favourites.view_others'),
        'max_lists'   => listsMaxPerUser($cfg),
        'max_items'   => listsMaxItems($cfg),
    ];
}

/**
 * Does this tracker know this hash at all, and what does it call it?
 *
 * "Known" is deliberately weaker than "resolved": a registered whitelist row whose metadata the
 * worker has not fetched yet is a torrent this tracker has, and refusing it would mean somebody can
 * only collect a torrent after a background job catches up with it. What it excludes is forty hex
 * characters nobody here has ever seen — which is not a torrent, it is a string.
 *
 * $canWl is the reader's `whitelist.view` (with index_search_include_whitelist), asked by the
 * caller. Without it the whitelist arm is skipped: answering "registered here: <name>" out of rows
 * the search page refuses to show this reader would turn the add box into a way to read them one
 * hash at a time.
 */
function listHashKnown(PDO $db, string $hash, bool $canWl = true): array
{
    $hash = strtolower(trim($hash));
    $out = ['known' => false, 'name' => null, 'source' => null];
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return $out;
    try {
        // The whitelist first, and it wins: a registered hash is deleted out of index_hashes, so
        // where both exist the whitelist row is the newer truth (the same order favRowsFor uses).
        if ($canWl) {
            $st = $db->prepare("SELECT name FROM whitelist WHERE info_hash = ? LIMIT 1");
            $st->execute([$hash]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return ['known' => true, 'name' => $row['name'] ?: null, 'source' => 'whitelist'];
        }
        $st = $db->prepare("SELECT name FROM index_hashes WHERE info_hash = ? LIMIT 1");
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return ['known' => true, 'name' => $row['name'] ?: null, 'source' => 'index'];
    } catch (Throwable $e) { /* a table this install does not have is not a failure */ }
    return $out;
}

/** How many torrents are in one list. One statement, so the ceiling and the reply agree. */
function listItemCount(PDO $db, int $listId): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM user_list_items WHERE list_id = ?");
    $st->execute([$listId]);
    return (int)$st->fetchColumn();
}

/**
 * One list's rows, with whatever the catalogue currently knows about each hash.
 *
 * The stored `name` is the fallback, not the truth: a hash the catalogue still holds is described
 * by the catalogue (it may have been resolved since), and one it has pruned is described by the
 * name that was recorded when the row was added. A list of forty-hex strings is unreadable to the
 * person who made it, which is the failure this avoids.
 *
 * $canWl and $isOwner are the reader's, and they mean what they mean in favRowsFor(): may this
 * reader see whitelist rows at all, and is this their own list.
 */
function listItemsWithMeta(PDO $db, array $rows, bool $canWl = true, bool $isOwner = true): array
{
    if (!$rows) return [];
    $hashes = array_values(array_unique(array_map(static fn($r) => (string)$r['info_hash'], $rows)));
    // favRowsFor() is the one place that knows how to describe a hash from the catalogue and the
    // whitelist together, with the whitelist winning. A second copy of that reasoning here would be
    // a second thing to keep in step.
    // Asked AS THE OWNER even when the reader is not one: the banned flag has to survive the lookup
    // so the loop below can drop the whole ITEM. Letting favRowsFor() drop the metadata instead
    // would leave the item row here with banned = false on it — a magnet button on a hash the
    // tracker refuses, which is the opposite of what dropping it is for.
    $meta = favRowsFor($db, $hashes, $canWl, true);
    $byHash = [];
    foreach ($meta as $m) {
        $h = strtolower((string)($m['info_hash'] ?? ''));
        if ($h !== '' && ($m['name'] !== null || !empty($m['src']))) $byHash[$h] = $m;
    }
    $out = [];
    foreach ($rows as $r) {
        $h = strtolower((string)$r['info_hash']);
        $m = $byHash[$h] ?? [];
        // Somebody else's list does not show a row the tracker refuses to serve; the owner's does,
        // because they are the only person who can take it off.
        if (!$isOwner && !empty($m['banned'])) continue;
        $out[] = [
            // The row's own id, so it can be named without its hash: a reader without index.magnet
            // gets rows with info_hash = null and still has to be able to remove one.
            'id'         => isset($r['id']) ? (int)$r['id'] : null,
            'info_hash'  => $h,
            'name'       => $m['name'] ?? ($r['name'] ?? null),
            'total_size' => $m['total_size'] ?? null,
            'seeders'    => $m['seeders'] ?? null,
            'leechers'   => $m['leechers'] ?? null,
            'banned'     => (bool)($m['banned'] ?? false),
            'added_at'   => $r['added_at'] ?? null,
            // true when the catalogue has nothing: the row still works as a magnet, and the page
            // says where its name came from rather than pretending the catalogue knows it.
            'gone'       => !isset($byHash[$h]),
        ];
    }
    return $out;
}

/**
 * The lists a hash appears on that $viewerId may see — the public ones, and (1.72.0) those shared with
 * friends when the reader is one of the owner's — "who has this", for collections: the first $limit of them.
 *
 * Since 1.70.0 the query is the "who has this" overlay's Lists section (whoListsPage(), includes/who.php),
 * which pages and searches; this is its first page, as the 1.69.0 overlay asked for it. Every gate the
 * profile applies is applied there, in SQL, for the reason api/hash_favourites.php gives at length:
 * filtering after the LIMIT makes the total a lie. A list whose owner has since taken their section down,
 * or whose group lost the permission, is not there — nor one whose owner has hidden their profile from
 * $viewerId (`hide_profile`): a chip on a torrent's page that links straight into it would be the profile
 * answering after all. A friends list counts only for a friend, and not after an unfriending or a block.
 *
 * Who calls it: since 1.70.0 nothing on the site — the overlay asks api/hash_who.php, and
 * api/hash_favourites.php (the 1.69.0 answer, kept) calls whoListsPage() itself. The TESTS do, and it
 * is kept for them: tests/lists_test.php (the truth table of the five gates at the top of this file),
 * tests/audit_lists_test.php (a reader the list is hidden from, a grant that has not started yet) and
 * tests/who_test.php (that this is the Lists section's own first page, so the table holds there too).
 */
function listsContainingHash(PDO $db, array $cfg, string $hash, int $limit = 20, int $viewerId = 0): array
{
    try {
        return whoListsPage($db, $cfg, $hash, $viewerId, ['page' => 1, 'per_page' => max(1, min(100, $limit)), 'search' => ''])['rows'];
    } catch (\Throwable $e) { return []; }
}
