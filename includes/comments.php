<?php
/**
 * Comments on a torrent (v83, 1.71.0): what members — and, where the operator allows it, guests — say
 * under a torrent, in its Info panel.
 *
 * The owner: "comments on hashes — plan it well, safely: emoji, simple BBCode without pictures, a link
 * perhaps, a length the operator sets, a new notification sound for somebody having commented;
 * permissions and the rest". This file is all of it but the page (assets/js/comments.js) and the
 * endpoints, which are thin: every request is a function here that answers ['status', 'body'], so
 * tests/comments_test.php puts every refusal to it without a web server.
 *
 * ── safe by construction ──────────────────────────────────────────────────────────────────────
 * Not richtextRender() with a switch, for the reason the profile's description is not either
 * (includes/profilebio.php): a comment's allow-list is short, and the shared renderer is thirty-odd
 * passes over escaped text of which an allow-list would have to gate every one. So this is the
 * description's own kind of walker over the RAW text: every run of text escaped on its way out, and the
 * only tags in the output the ones written below —
 *   [b] [i] [u] [s]                        → strong em u s
 *   [spoiler] [spoiler=title]              → the site's <details class="rt-spoiler">
 *   [quote] [quote=name]                   → <blockquote class="rt-quote"> — ONE level: a quote inside a
 *                                            quote is the text it is, and so is its closer
 *   [code]…[/code]                         → <code class="rt-inline">, or <pre class="rt-code"> over
 *                                            several lines; everything inside is literal
 *   [url]address[/url] [url=address]words  → a link through richtextSafeUrl() / richtextLinkAttrs() (the
 *                                            rel every link gets, and the "you are leaving" question) —
 *                                            only while Settings allows links AND the writer may link
 *                                            (a member; a guest never). Otherwise the tag is text, and
 *                                            the save refuses it, so nobody is surprised by it later.
 * Nothing else: no picture, no table, no size, no colour, no alignment — a tag the list does not name
 * is shown as what was typed. A tag left open is closed at the end; a closer with nothing to close is
 * text; a closer that crosses another tag closes it and opens it again (a link and a block are never
 * opened twice). Line breaks are kept, at most two in a row and COMMENT_MAX_LINES in all.
 *
 * Then the three things every text on the site with the picker gets, on the finished HTML's text only
 * (an address keeps its bytes): Font Awesome's `:fa-NAME:` (emojiFaRenderHtml()), the site's `:code:`
 * emotes in the `comment` context (emoteRenderHtml(): an emote at the size of the words, a sticker drawn
 * as one — a thread has no room for big pictures), and `@name` as a link to the profile
 * (shoutLinkMentions(), the room's own).
 *
 * ── what "characters" means ──────────────────────────────────────────────────────────────────
 * What a READER sees, as the profile's description counts it: the words and any literal text, not the
 * tags that format them, in code points. The source as typed has a ceiling of its own (four times the
 * visible limit, never over COMMENT_SOURCE_CHARS characters or COMMENT_SOURCE_BYTES bytes), so tags
 * cannot store a novel in a box whose visible limit is five hundred.
 *
 * ── who ───────────────────────────────────────────────────────────────────────────────────────
 * Every question is asked of the ACCOUNT (userIdHasPermission) — or, for somebody signed out, of the
 * guest group — never of a panel session's blanket: a moderator in the panel is not thereby a member
 * here, and a gate that cannot be asked about a named account is a gate a test cannot ask either.
 * comment.view / comment.post / comment.edit_own / comment.delete_own / comment.moderate; accounts off,
 * none of it exists (commentsEnabled(), and userLegacyDefault() says no to every comment.* id).
 *
 * A GUEST (only where the operator grants the guest group comment.post): signed "Guest #4f2a" — a keyed
 * hash of the day and the address group, which tells two guests apart on one day and says nothing about
 * either (commentGuestTag()) — with no link and no picture; a CAPTCHA every time, and none at all is no
 * guest comment (a site with no CAPTCHA provider is a site where "Guest" would be anybody's script);
 * never a link; held for a moderator while `comments_guest_review` is on (status 'pending'); never
 * corrected, taken back, told of anything or @-mentioned — there is nobody to be.
 *
 * ── what the page is told, and who is told what ───────────────────────────────────────────────
 * A new visible comment tells (commentNotifyNew()), once each, in THEIR language: the member who
 * registered the torrent, the author of record of its published description, the members who commented
 * on it before (the most recent COMMENT_PARTICIPANTS_MAX), and the members it @-mentions — never its
 * author, never across a block either way, never somebody who may not read comments, never a kind the
 * member switched off (users.comment_notify), and a thread already unread in somebody's notifications is
 * not announced to them again. A notification carries its `link` (userNotify()), and the pulse counts
 * the unread ones (commentUnreadCounts()), which is what the two sounds of their own play from.
 *
 * ── for the parts after this one ─────────────────────────────────────────────────────────────
 * E (reports, includes/reports.php): commentDelete() takes `$opts['notify']` — false is the silent removal —
 * and `$opts['authority'] = 'panel'` for the Reports page; every row the page is handed carries `can_report` /
 * `reported`, and the page's actions row takes the Report button through window.Comments.onActions()
 * (assets/js/reports.js); the held guest comments are commentPendingList() / commentApprove().
 * F (one anti-spam layer, includes/antispam.php): commentFloodCheck() is the ONE call every write here makes,
 * and it is the layer's now — a guest's CAPTCHA every time, a member's ladder and its CAPTCHA at the top, the
 * same words again refused, a correction a few seconds from the last; a new account's links are drawn as text
 * (commentShape() asks antispamWrittenNew() per comment, from when its words were written).
 */
require_once __DIR__ . '/richtext.php';
require_once __DIR__ . '/antispam.php';

const COMMENT_MAX_MIN      = 20;
const COMMENT_MAX_MAX      = 5000;
const COMMENT_MAX_DEFAULT  = 500;
const COMMENT_SOURCE_FACTOR = 4;       // the source may be this many times the visible limit…
const COMMENT_SOURCE_CHARS  = 20000;   // …and never more characters than this…
const COMMENT_SOURCE_BYTES  = 60000;   // …or bytes (under a TEXT column's 65 535)
const COMMENT_RAW_BYTES     = 262144;  // what a request may carry at all, before any work is done
const COMMENT_MAX_LINES    = 20;       // a blank line counts
const COMMENT_MAX_LINKS    = 3;
const COMMENT_MAX_DEPTH    = 8;        // tags open at once; an opener past this is text
const COMMENT_REASON_MAX   = 255;      // a moderator's reason, shown to the author
const COMMENT_PARTICIPANTS_MAX = 50;   // the thread's earlier commenters told of a new one, newest first
const COMMENT_EXCERPT      = 140;      // the words a notification quotes
const COMMENT_PAGE_MIN     = 5;
const COMMENT_PAGE_MAX     = 100;
const COMMENT_PAGE_DEFAULT = 20;

// users.comment_notify — one bit per kind of news, all four on by default.
const COMMENT_NOTIFY_MINE    = 1;   // a comment on a torrent I registered
const COMMENT_NOTIFY_DESC    = 2;   // …on a torrent whose published description I wrote
const COMMENT_NOTIFY_THREAD  = 4;   // …in a thread I commented in
const COMMENT_NOTIFY_MENTION = 8;   // …that @-mentions me
const COMMENT_NOTIFY_ALL     = 15;
const COMMENT_NOTIFY_KEYS    = ['mine' => COMMENT_NOTIFY_MINE, 'desc' => COMMENT_NOTIFY_DESC,
                                'thread' => COMMENT_NOTIFY_THREAD, 'mention' => COMMENT_NOTIFY_MENTION];

/* ── the switches, all clamped on read ─────────────────────────────────────────────────────────── */

/** The feature: accounts, and Settings → Descriptions, comments & ratings → Comments. */
function commentsEnabled(array $cfg): bool
{
    return function_exists('usersEnabled') && usersEnabled($cfg) && (($cfg['comments_enabled'] ?? '1') === '1');
}

/** The most characters a reader may be shown, as Settings says, clamped. */
function commentMax(array $cfg): int
{
    $v = (int)($cfg['comment_max_chars'] ?? COMMENT_MAX_DEFAULT);
    return max(COMMENT_MAX_MIN, min(COMMENT_MAX_MAX, $v ?: COMMENT_MAX_DEFAULT));
}

/** The most characters the SOURCE may hold, tags included. */
function commentSourceCap(array $cfg): int
{
    return min(commentMax($cfg) * COMMENT_SOURCE_FACTOR, COMMENT_SOURCE_CHARS);
}

/** Links in comments at all (a member's; a guest's never). */
function commentLinksOn(array $cfg): bool { return ($cfg['comment_links'] ?? '1') === '1'; }

/** How long a member may correct their own comment, in minutes: 0 = never (clamped to a day). */
function commentEditMinutes(array $cfg): int { return max(0, min(1440, (int)($cfg['comment_edit_minutes'] ?? 15))); }

/** How long a member may take their own comment back, in minutes: 0 = no limit (clamped to a day). */
function commentDeleteOwnMinutes(array $cfg): int { return max(0, min(1440, (int)($cfg['comment_delete_own_minutes'] ?? 60))); }

/** Comments to a page. */
function commentsPerPage(array $cfg): int
{
    $v = (int)($cfg['comments_per_page'] ?? COMMENT_PAGE_DEFAULT);
    return max(COMMENT_PAGE_MIN, min(COMMENT_PAGE_MAX, $v ?: COMMENT_PAGE_DEFAULT));
}

/** Comments an hour, for one account (or one guest address group). */
function commentRatePerHour(array $cfg): int { return max(1, min(1000, (int)($cfg['comment_rate_per_hour'] ?? 30) ?: 30)); }

/** Does a guest's comment wait for a moderator? */
function commentGuestReview(array $cfg): bool { return ($cfg['comments_guest_review'] ?? '1') === '1'; }

/* ── who may do what ───────────────────────────────────────────────────────────────────────────── */

/**
 * Does this reader hold a comment.* permission — the ACCOUNT's grant, or for somebody signed out the
 * guest group's. Never a panel session's blanket (see the head of this file).
 */
function commentCan(PDO $db, array $cfg, ?array $me, string $perm): bool
{
    if (!commentsEnabled($cfg)) return false;
    $uid = (int)($me['id'] ?? 0);
    if ($uid > 0) return userIdHasPermission($db, $cfg, $uid, $perm);
    $p = userEffectivePermissions($db, null, $cfg);
    return !empty($p[$perm]);
}

/** Any permission of the site's own, asked the same way (index.view, whitelist.view for the hash's rule). */
function commentReaderCan(PDO $db, array $cfg, ?array $me, string $perm): bool
{
    $uid = (int)($me['id'] ?? 0);
    if ($uid > 0) return userIdHasPermission($db, $cfg, $uid, $perm);
    if (!usersEnabled($cfg)) return userLegacyDefault($perm);
    $p = userEffectivePermissions($db, null, $cfg);
    return !empty($p[$perm]);
}

/**
 * May this reader see this torrent at all — the Info panel's own rule (api/index_info.php): the search
 * switched on, `index.view`, and the hash an index row, or a whitelist row the reader may see
 * (`whitelist.view` with the whitelist included in the search) that is not banned. A thread is exactly as
 * visible as the panel it is drawn in: a hash nobody may ask about has no comments to read or write.
 */
function commentHashVisible(PDO $db, array $cfg, ?array $me, string $hash): bool
{
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return false;
    if (!function_exists('indexEnabled') || !indexEnabled($cfg) || ($cfg['index_search_enabled'] ?? '1') !== '1') return false;
    if (!commentReaderCan($db, $cfg, $me, 'index.view')) return false;
    $st = $db->prepare("SELECT 1 FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    if ($st->fetchColumn()) return true;
    if (!commentReaderCan($db, $cfg, $me, 'whitelist.view') || ($cfg['index_search_include_whitelist'] ?? '1') !== '1') return false;
    $st = $db->prepare("SELECT 1 FROM whitelist WHERE info_hash = ? AND banned = 0 LIMIT 1");
    $st->execute([$hash]);
    return (bool)$st->fetchColumn();
}

/** Is a CAPTCHA provider set up at all? A guest cannot comment without one (see the head of this file). */
function commentCaptchaReady(array $cfg): bool
{
    return function_exists('captchaConfigured') && captchaConfigured($cfg);
}

/**
 * May this reader write here, and if not, why — one answer the composer prints:
 *   reason '' | 'disabled' | 'login' | 'no_permission' | 'muted' ('until') | 'guest_captcha'
 * `login` is a signed-out reader where guests may not write; `guest_captcha` a guest group holding
 * comment.post on a site with no CAPTCHA provider. Muted comes last, as the shoutbox has it: it is the
 * only one that passes.
 */
function commentMayPost(PDO $db, array $cfg, ?array $me): array
{
    $no = static fn(string $r, ?string $until = null): array => ['ok' => false, 'reason' => $r, 'until' => $until];
    if (!commentsEnabled($cfg)) return $no('disabled');
    $uid = (int)($me['id'] ?? 0);
    if (!commentCan($db, $cfg, $me, 'comment.view') || !commentCan($db, $cfg, $me, 'comment.post')) {
        return $no($uid > 0 ? 'no_permission' : 'login');
    }
    if ($uid <= 0) {
        return commentCaptchaReady($cfg) ? ['ok' => true, 'reason' => '', 'until' => null] : $no('guest_captcha');
    }
    $until = function_exists('pmMutedUntil') ? pmMutedUntil($me) : null;
    if ($until !== null) return $no('muted', $until);
    return ['ok' => true, 'reason' => '', 'until' => null];
}

/** May this writer put links in (a member, while Settings allows them)? */
function commentWriterMayLink(array $cfg, ?array $me): bool
{
    return commentLinksOn($cfg) && (int)($me['id'] ?? 0) > 0;
}

/* ── the guest's name ──────────────────────────────────────────────────────────────────────────── */

/**
 * The four hex characters after "Guest #": an HMAC of the day (UTC) and the ADDRESS GROUP (ipBucket():
 * an IPv6 /64, an IPv4 as it is), keyed by secrets only this server holds. Two guests on one day almost
 * always differ; the same guest tomorrow is somebody else; and nothing in it can be turned back into an
 * address — the key is never on a page and the day changes it. Computed when the comment is WRITTEN and
 * stored with it, so a later day does not rename a comment.
 */
function commentGuestTag(string $ip, array $cfg, ?int $at = null): string
{
    $bucket = function_exists('ipBucket') ? ipBucket($ip) : $ip;
    $key = 'comment-guest|' . (string)($cfg['hmac_secret'] ?? '') . '|' . (defined('ADMIN_PASSWORD_HASH') ? ADMIN_PASSWORD_HASH : '')
         . '|' . (defined('DB_PASS') ? DB_PASS : '') . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|' . __DIR__;
    return substr(hash_hmac('sha256', gmdate('Y-m-d', $at ?? time()) . '|' . $bucket, $key), 0, 4);
}

/* ── the text: cleaned, walked, counted ────────────────────────────────────────────────────────── */

/**
 * The text as it is kept and as it is judged — the profile description's cleaning (profileBioClean()),
 * except for spaces: a comment may hold code, and indentation is part of it, so runs of spaces stay
 * (the page collapses them outside code anyway) and only the spaces that end a line go.
 *
 * Out: C0 and C1 controls, the bidi embeddings, overrides and isolates (U+202A–E, U+2066–9 — how a link's
 * words are made to lie about where it goes), and the characters that draw nothing (zero-width space,
 * word joiner and the invisible operators, the BOM, the Mongolian vowel separator, the interlinear
 * annotation marks). Kept: the zero-width JOINER and NON-JOINER (emoji sequences, Persian) and the LRM /
 * RLM marks. A tab is four spaces; at most two line breaks in a row; trimmed.
 */
function commentClean(string $raw): string
{
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = (string)mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    $s = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $raw);
    $s = str_replace("\t", '    ', $s);
    $s = (string)preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{180E}\x{200B}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFB}]/u', '', $s);
    $s = (string)preg_replace('/ +\n/', "\n", $s);
    $s = (string)preg_replace('/\n{3,}/', "\n\n", $s);
    return trim($s, " \n");
}

/** How many lines a cleaned text has (0 for none); a blank line between paragraphs is one of them. */
function commentLines(string $clean): int
{
    return $clean === '' ? 0 : substr_count($clean, "\n") + 1;
}

/**
 * A link's address, or null when it is not one this site will point at — the profile description's rule
 * (profileBioUrl()): http or https with a host (richtextSafeUrl()), quotes round the whole address
 * forgiven, and nothing with white space in it (richtextSafeUrl() would quietly remove the space, and a
 * link must not show one address and go to another).
 */
function commentUrl(string $raw): ?string
{
    $u = trim($raw);
    if (strlen($u) >= 2 && ($u[0] === '"' || $u[0] === "'") && substr($u, -1) === $u[0]) $u = trim(substr($u, 1, -1));
    if ($u === '' || preg_match('/[\s\x00-\x1F\x7F]/u', $u)) return null;
    return richtextSafeUrl($u);
}

/** A quote's or a spoiler's title: one line, no quotes round it, bounded. */
function commentTitle(string $raw, int $max = 64): string
{
    $t = trim($raw);
    if (strlen($t) >= 2 && ($t[0] === '"' || $t[0] === "'") && substr($t, -1) === $t[0]) $t = trim(substr($t, 1, -1));
    return mb_substr($t, 0, $max, 'UTF-8');
}

/**
 * Turn a CLEANED text into HTML and count what a reader will see.
 *
 * `$links`: may this text's writer link (commentWriterMayLink()) — false, and a [url] is the text it is
 * (counted in `url_tags`, which the save refuses so the writer is told). Returns ['html', 'chars' (code
 * points shown), 'links' (url tags with a good address, those past the third included — shown as text,
 * refused by the save), 'bad_links' (url tags whose address is not one), 'url_tags' (url tags written
 * while links are off), 'lines'].
 *
 * The walk is the profile description's (profileBioParse()), with two kinds of block beside the inline
 * tags. The rules that are new here:
 *   · [code] takes everything up to the next [/code] whole, like [url]address[/url] — no closer, and the
 *     opener is text; one line is inline code, several a <pre> keeping its line breaks;
 *   · [quote] and [spoiler] are ONE level each: an opener while one of its kind is open is text, and so
 *     is the closer that answers it (a counter of the literal ones), so the outer block ends where the
 *     writer ended it;
 *   · a closer that crosses tags closes them and reopens the inline ones only — a block, like a link,
 *     is never opened twice.
 */
function commentParse(string $s, array $cfg, bool $links = true): array
{
    static $inl = ['b' => 'strong', 'i' => 'em', 'u' => 'u', 's' => 's'];
    $out = '';
    $chars = 0;
    $nLinks = 0;
    $bad = 0;
    $urlTags = 0;
    $stack = [];            // ['tag' => b|i|u|s|url|quote|spoiler, 'mark' => $chars when it opened, 'url' => …]
    $literal = ['quote' => 0, 'spoiler' => 0];   // openers of a kind already open, written as text
    $text = function (string $t, bool $pre = false) use (&$out, &$chars): void {
        if ($t === '') return;
        $chars += mb_strlen($t, 'UTF-8');
        $e = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out .= $pre ? $e : str_replace("\n", '<br>', $e);
    };
    $has = function (string $tag) use (&$stack): bool {
        foreach ($stack as $f) if ($f['tag'] === $tag) return true;
        return false;
    };
    $openInline = function (string $tag) use (&$out, &$stack, &$chars, $inl): void {
        $stack[] = ['tag' => $tag, 'mark' => $chars];
        $out .= '<' . $inl[$tag] . '>';
    };
    $close = function (array $f) use (&$out, &$chars, $text, $inl): void {
        switch ($f['tag']) {
            case 'url':
                // A link whose words were empty writes the address as its words: a link nobody can see is
                // not a link anybody can use.
                if ($chars === $f['mark']) $text((string)$f['url']);
                $out .= '</a>';
                return;
            case 'quote':   $out .= '</blockquote>'; return;
            case 'spoiler': $out .= '</div></details>'; return;
            default:        $out .= '</' . $inl[$f['tag']] . '>';
        }
    };

    $re = '~\[(?:(b|i|u|s)|/(b|i|u|s|url|quote|spoiler|code)|(quote|spoiler)(?:=([^\]\n]{0,80}))?|(code)|url(?:(=)([^\]\n]*))?)\]~i';
    $pos = 0;
    $nextUrlCloser = -1;
    while (preg_match($re, $s, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $pos)) {
        $tok = $m[0][0];
        $at = $m[0][1];
        $text(substr($s, $pos, $at - $pos));
        $pos = $at + strlen($tok);

        if ($m[1][0] !== null) {                                    // [b] [i] [u] [s]
            if (count($stack) >= COMMENT_MAX_DEPTH) { $text($tok); continue; }
            $openInline(strtolower($m[1][0]));
            continue;
        }
        if ($m[2][0] !== null) {                                    // a closer
            $name = strtolower($m[2][0]);
            if ($name === 'code') { $text($tok); continue; }        // a [/code] with no [code] before it
            if (($name === 'quote' || $name === 'spoiler') && $literal[$name] > 0) {
                $literal[$name]--;                                  // answers an opener written as text
                $text($tok);
                continue;
            }
            $idx = null;
            for ($k = count($stack) - 1; $k >= 0; $k--) {
                if ($stack[$k]['tag'] === $name) { $idx = $k; break; }
            }
            if ($idx === null) { $text($tok); continue; }           // closes nothing: it is text
            $reopen = [];
            while (count($stack) - 1 > $idx) {
                $f = array_pop($stack);
                $close($f);
                if (isset($inl[$f['tag']])) array_unshift($reopen, $f['tag']);
            }
            $close(array_pop($stack));
            foreach ($reopen as $t) $openInline($t);
            continue;
        }
        if ($m[3][0] !== null) {                                    // [quote] [quote=…] [spoiler] [spoiler=…]
            $kind = strtolower($m[3][0]);
            if ($has($kind)) { $literal[$kind]++; $text($tok); continue; }   // one level
            if ($has('url') || count($stack) >= COMMENT_MAX_DEPTH) { $text($tok); continue; }
            $title = $m[4][0] !== null ? commentTitle((string)$m[4][0], $kind === 'quote' ? 64 : 80) : '';
            $stack[] = ['tag' => $kind, 'mark' => $chars];
            if ($kind === 'quote') {
                $out .= '<blockquote class="rt-quote">';
                if ($title !== '') {
                    $out .= '<cite class="rt-cite">';
                    $text($title);
                    $out .= '</cite>';
                }
            } else {
                $out .= '<details class="rt-spoiler"><summary><i class="bi bi-chevron-right disc-chev" aria-hidden="true"></i>';
                if ($title !== '') $text($title);
                else $out .= htmlspecialchars(__('comment.spoiler'), ENT_QUOTES, 'UTF-8');
                $out .= '</summary><div class="rt-spoiler-body">';
            }
            continue;
        }
        if ($m[5][0] !== null) {                                    // [code]…[/code], taken whole
            if (!preg_match('~\[/code\]~i', $s, $cm, PREG_OFFSET_CAPTURE, $pos)) { $text($tok); continue; }
            $inner = substr($s, $pos, (int)$cm[0][1] - $pos);
            $pos = (int)$cm[0][1] + strlen($cm[0][0]);
            $inner = trim($inner, "\n");
            if (str_contains($inner, "\n")) {
                $out .= '<pre class="rt-code"><code>';
                $text($inner, true);
                $out .= '</code></pre>';
            } else {
                $out .= '<code class="rt-inline">';
                $text($inner);
                $out .= '</code>';
            }
            continue;
        }

        // [url=…] and [url]
        if (!$links) { $urlTags++; $text($tok); continue; }
        if ($has('url') || count($stack) >= COMMENT_MAX_DEPTH) { $text($tok); continue; }
        if ($m[6][0] !== null) {                                    // [url=address]words[/url]
            $url = commentUrl((string)$m[7][0]);
            if ($url === null) { $bad++; $text($tok); continue; }
            if (++$nLinks > COMMENT_MAX_LINKS) { $text($tok); continue; }
            $stack[] = ['tag' => 'url', 'mark' => $chars, 'url' => $url];
            $out .= '<a' . richtextLinkAttrs($url, $cfg) . '>';
            continue;
        }
        // [url]address[/url]: the address is everything up to the next closer, taken whole.
        if ($nextUrlCloser < $pos) {
            $nextUrlCloser = preg_match('~\[/url\]~i', $s, $um, PREG_OFFSET_CAPTURE, $pos) ? (int)$um[0][1] : PHP_INT_MAX;
        }
        if ($nextUrlCloser === PHP_INT_MAX) { $text($tok); continue; }
        $url = commentUrl(substr($s, $pos, $nextUrlCloser - $pos));
        if ($url === null) { $bad++; $text($tok); continue; }
        if (++$nLinks > COMMENT_MAX_LINKS) { $text($tok); continue; }
        $out .= '<a' . richtextLinkAttrs($url, $cfg) . '>';
        $text($url);
        $out .= '</a>';
        $pos = $nextUrlCloser + strlen('[/url]');
    }
    $text(substr($s, $pos));
    while ($stack) $close(array_pop($stack));
    return ['html' => $out, 'chars' => $chars, 'links' => $nLinks, 'bad_links' => $bad, 'url_tags' => $urlTags,
            'lines' => commentLines($s)];
}

/**
 * Everything wrong with a cleaned text, as ['code' => …, 'vars' => […]] for api.comment.<code>, or null.
 * The cheap, bounding questions first: the source's size before the text is walked at all.
 */
function commentProblem(string $clean, array $cfg, bool $links): ?array
{
    if ($clean === '') return ['code' => 'empty', 'vars' => []];
    $cap = commentSourceCap($cfg);
    if (mb_strlen($clean, 'UTF-8') > $cap || strlen($clean) > COMMENT_SOURCE_BYTES) {
        return ['code' => 'too_long_source', 'vars' => ['max' => $cap]];
    }
    if (commentLines($clean) > COMMENT_MAX_LINES) return ['code' => 'too_many_lines', 'vars' => ['max' => COMMENT_MAX_LINES]];
    $p = commentParse($clean, $cfg, $links);
    $max = commentMax($cfg);
    if ($p['chars'] > $max) return ['code' => 'too_long', 'vars' => ['n' => $p['chars'], 'max' => $max]];
    if ($p['url_tags'] > 0) return ['code' => 'no_links', 'vars' => []];
    if ($p['bad_links'] > 0) return ['code' => 'bad_link', 'vars' => []];
    if ($p['links'] > COMMENT_MAX_LINKS) return ['code' => 'too_many_links', 'vars' => ['max' => COMMENT_MAX_LINKS]];
    return null;
}

/**
 * A stored comment as the page shows it.
 *
 * Cleaned and bounded again on the way out, not only on the way in: a row written before a rule changed,
 * restored from a backup or typed into a MySQL client has never been through the endpoint. Lines past the
 * last one allowed are joined with a space rather than dropped. `plain` — and any format this code does
 * not know — is text: escaped, its line breaks kept, the tokens drawn, no tag.
 *
 * $ctx: ['links' => bool (may this comment's writer link — see commentRenderContext()), 'db' => ?PDO (the
 * emotes), 'known' => the @-names that are accounts, 'base' => the site's address, 'me_name' => the reader].
 */
function commentRenderHtml(string $body, string $format, array $cfg, array $ctx = []): string
{
    $c = commentClean($body);
    if ($c === '') return '';
    if (strlen($c) > COMMENT_SOURCE_BYTES) $c = rtrim(mb_strcut($c, 0, COMMENT_SOURCE_BYTES, 'UTF-8'));
    $lines = explode("\n", $c);
    if (count($lines) > COMMENT_MAX_LINES) {
        $c = implode("\n", array_slice($lines, 0, COMMENT_MAX_LINES - 1)) . "\n"
           . implode(' ', array_filter(array_slice($lines, COMMENT_MAX_LINES - 1), fn($l) => $l !== ''));
    }
    $html = $format === 'bbcode'
        ? commentParse($c, $cfg, !empty($ctx['links']))['html']
        : str_replace("\n", '<br>', htmlspecialchars($c, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    return commentTokens($html, $cfg, $ctx);
}

/**
 * The stage after the walk, on the finished HTML's TEXT only: @-names that are accounts become links to
 * their profiles (the room's shoutLinkMentions(): a name inside a link or an attribute stays as it is), then
 * Font Awesome's `:fa-NAME:`, then the site's `:code:` emotes in the comment context (emoteRenderHtml():
 * an emote at the size of the words, a sticker drawn as one). A token inside <code> stays what was typed.
 */
function commentTokens(string $html, array $cfg, array $ctx = []): string
{
    if ($html === '') return $html;
    if (!empty($ctx['known']) && function_exists('shoutLinkMentions') && str_contains($html, '@')) {
        $html = commentOutsideCode($html, static fn(string $h): string =>
            shoutLinkMentions($h, (array)$ctx['known'], (string)($ctx['base'] ?? ''), (string)($ctx['me_name'] ?? '')));
    }
    if (!str_contains($html, ':')) return $html;
    if (function_exists('emojiFaRenderHtml')) $html = commentOutsideCode($html, static fn(string $h): string => emojiFaRenderHtml($h, $cfg));
    $db = $ctx['db'] ?? null;
    if ($db instanceof PDO && function_exists('emoteMapFor') && function_exists('emoteRenderHtml')) {
        $html = emoteRenderHtml($html, emoteMapFor($db, $cfg, 'comment'));   // skips <code>/<pre> itself
    }
    return $html;
}

/**
 * Apply a text stage to everything BUT the code: the pieces between <code>…</code> and <pre>…</pre> are
 * handed back untouched, so a mention or a token written as code is what the writer typed. (The emote stage
 * knows this already; the mention and Font Awesome stages are the room's and the site's, written for text
 * with no code in it.)
 */
function commentOutsideCode(string $html, callable $stage): string
{
    if (!str_contains($html, '<code') && !str_contains($html, '<pre')) return $stage($html);
    $parts = preg_split('~(<pre class="rt-code"><code>.*?</code></pre>|<code class="rt-inline">.*?</code>)~s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return $html;
    foreach ($parts as $i => $p) if ($i % 2 === 0 && $p !== '') $parts[$i] = $stage($p);
    return implode('', $parts);
}

/** A short plain-text version (a notification's quote): the tags taken out, spaces made one, cut. */
function commentExcerpt(string $body, int $len = COMMENT_EXCERPT): string
{
    $t = commentClean($body);
    $t = (string)preg_replace('~\[/?(?:b|i|u|s|code|url(?:=[^\]\n]*)?|quote(?:=[^\]\n]*)?|spoiler(?:=[^\]\n]*)?)\]~i', '', $t);
    $t = trim((string)preg_replace('/\s+/u', ' ', $t));
    return mb_strlen($t, 'UTF-8') > $len ? rtrim(mb_substr($t, 0, $len - 1, 'UTF-8')) . '…' : $t;
}

/* ── mentions ──────────────────────────────────────────────────────────────────────────────────── */

/**
 * The accounts a comment @-mentions: up to five existing members, never the author — the room's own
 * parse (shoutParseMentions()), and none at all in a guest's comment (a guest can reach nobody).
 */
function commentMentionIds(PDO $db, string $body, int $authorId): array
{
    if ($authorId <= 0 || !function_exists('shoutParseMentions')) return [];
    // Written as code, a name is an example, not a call.
    $plain = (string)preg_replace('~\[code\].*?\[/code\]~is', ' ', $body);
    return array_values(array_map('intval', shoutParseMentions($db, $plain, $authorId)));
}

/* ── rows ──────────────────────────────────────────────────────────────────────────────────────── */

/**
 * The columns every read of a comment takes (the guest's address group among them — for the operator's own
 * code; commentShape() hands a page none of the raw columns). LEFT JOIN, because a guest's comment has no account. The
 * moment as an INSTANT (created_ts), converted by the database from the zone its DATETIME was written in
 * (includes/db_clock.php), and the age by the database's clock — the two windows are measured without
 * PHP's clock taking part.
 */
const COMMENT_ROW_SELECT = "SELECT c.id, c.info_hash, c.user_id, c.guest_tag, c.body, c.body_format, c.status, c.ip_bucket,
                                   c.created_at, c.edited_at, c.edited_by, c.approved_at, c.approved_by, c.deleted_at, c.deleted_by, c.delete_reason,
                                   u.username, u.avatar_sha, u.status AS user_status,
                                   UNIX_TIMESTAMP(c.created_at) AS created_ts, UNIX_TIMESTAMP(c.edited_at) AS edited_ts,
                                   TIMESTAMPDIFF(SECOND, c.created_at, NOW()) AS age_s,
                                   TIMESTAMPDIFF(SECOND, u.created_at, COALESCE(c.edited_at, c.created_at)) AS author_age_s
                              FROM hash_comments c LEFT JOIN users u ON u.id = c.user_id";

/** One comment by id (any status), or null. */
function commentRow(PDO $db, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $db->prepare(COMMENT_ROW_SELECT . " WHERE c.id = ? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** How many visible comments a torrent has — an index range on (info_hash, status, id). */
function commentCount(PDO $db, string $hash): int
{
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM hash_comments WHERE info_hash = ? AND status = 'visible'");
        $st->execute([$hash]);
        return (int)$st->fetchColumn();
    } catch (\Throwable $e) {
        return 0;   // the table arrives with schema 83
    }
}

/** How many guest comments wait for a moderator — on one torrent, or everywhere (null). */
function commentPendingCount(PDO $db, ?string $hash = null): int
{
    try {
        if ($hash === null) return (int)$db->query("SELECT COUNT(*) FROM hash_comments WHERE status = 'pending'")->fetchColumn();
        $st = $db->prepare("SELECT COUNT(*) FROM hash_comments WHERE info_hash = ? AND status = 'pending'");
        $st->execute([$hash]);
        return (int)$st->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * A page of a thread, OLDEST FIRST as it is read: the newest `$limit` comments below `$before` (none: the
 * newest of all), and whether there is anything earlier. A moderator's page carries the held guest
 * comments too, in their place.
 */
function commentPage(PDO $db, string $hash, ?int $before, int $limit, bool $withPending): array
{
    $limit = max(1, min(COMMENT_PAGE_MAX, $limit));
    $where = $withPending ? "c.info_hash = ? AND c.status IN ('visible','pending')" : "c.info_hash = ? AND c.status = 'visible'";
    $args = [$hash];
    if ($before !== null && $before > 0) { $where .= " AND c.id < ?"; $args[] = $before; }
    $per = $limit + 1;
    $st = $db->prepare(COMMENT_ROW_SELECT . " WHERE $where ORDER BY c.id DESC LIMIT $per");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $earlier = count($rows) > $limit;
    if ($earlier) array_pop($rows);
    return ['rows' => array_reverse($rows), 'earlier' => $earlier];
}

/**
 * What the renderer needs for a batch of bodies, asked ONCE: the @-names that are accounts, the site's
 * address, the reader's own name (their mention drawn as theirs), the database for the emotes.
 */
function commentRenderContext(PDO $db, array $cfg, ?array $me, array $bodies): array
{
    return [
        'db'      => $db,
        'known'   => function_exists('shoutKnownNames') ? shoutKnownNames($db, $bodies) : [],
        'base'    => function_exists('getBaseUrl') ? getBaseUrl() : '',
        'me_name' => (string)($me['username'] ?? ''),
    ];
}

/**
 * May this reader correct this comment right now? '' when they may, else 'no_permission' | 'too_late' |
 * 'muted'. The ONE place the rule lives — commentShape() draws the pencil from it and commentEdit() asks it.
 *   · comment.moderate edits anybody's at any time (the author is told, the edit is marked and audited);
 *   · comment.edit_own edits your OWN (a member's — a guest owns nothing) for comment_edit_minutes from
 *     when it was written, while you may still write here at all (an edit is writing: a mute stops it);
 *   · nobody edits a deleted comment, and a held one only a moderator.
 */
function commentEditRight(PDO $db, array $cfg, ?array $me, array $row): string
{
    $uid = (int)($me['id'] ?? 0);
    if ($uid <= 0 || ($row['status'] ?? '') === 'deleted') return 'no_permission';
    if (commentCan($db, $cfg, $me, 'comment.moderate')) return '';
    $own = $row['user_id'] !== null && (int)$row['user_id'] === $uid;
    if (!$own || ($row['status'] ?? '') !== 'visible' || !commentCan($db, $cfg, $me, 'comment.edit_own')) return 'no_permission';
    $gate = commentMayPost($db, $cfg, $me);
    if (!$gate['ok']) return $gate['reason'] === 'muted' ? 'muted' : 'no_permission';
    $win = commentEditMinutes($cfg) * 60;
    if ($win <= 0) return 'no_permission';
    return (int)($row['age_s'] ?? PHP_INT_MAX) < $win ? '' : 'too_late';
}

/**
 * May this reader take this comment down, and as whom? ['right' => 'own'|'any'|'', 'error' => ''|code].
 *   · comment.moderate: anybody's, any time — a reason is asked when it is not your own;
 *   · comment.delete_own: your own (a member's), for comment_delete_own_minutes (0 = no limit). NOT behind
 *     a mute: taking your own words off a site is not something to be allowed to do.
 * A deleted comment is nobody's to delete again.
 */
function commentDeleteRight(PDO $db, array $cfg, ?array $me, array $row): array
{
    $uid = (int)($me['id'] ?? 0);
    if ($uid <= 0 || ($row['status'] ?? '') === 'deleted') return ['right' => '', 'error' => 'no_permission'];
    $own = $row['user_id'] !== null && (int)$row['user_id'] === $uid;
    if (commentCan($db, $cfg, $me, 'comment.moderate')) return ['right' => $own ? 'own' : 'any', 'error' => ''];
    if (!$own || !commentCan($db, $cfg, $me, 'comment.delete_own')) return ['right' => '', 'error' => 'no_permission'];
    $win = commentDeleteOwnMinutes($cfg) * 60;
    if ($win > 0 && (int)($row['age_s'] ?? PHP_INT_MAX) >= $win) return ['right' => '', 'error' => 'too_late'];
    return ['right' => 'own', 'error' => ''];
}

/**
 * Raw rows → what the page is handed. The ONE place a comment becomes something to display.
 *
 * Per row: who (a member's name, picture and whether their profile opens for this reader — a profile the
 * author hid from this reader with a block is not linked; a guest's tag; nothing else), the words as HTML
 * (links only where the writer may link, now), the moment in the READER's zone (`time`, and `at` with its
 * offset for the title), the marks (edited — and by a moderator —, held for review), and what this reader
 * may do: edit (with the seconds left), delete ('own'/'any', seconds left), and whether the author is
 * somebody THEY blocked (the page folds the comment away until asked). `can_report` / `reported`: may this
 * reader report it, and have they (part E, contentReportFlags() in includes/reports.php).
 */
function commentShape(PDO $db, array $cfg, ?array $me, array $raw): array
{
    if (!$raw) return [];
    $uid = (int)($me['id'] ?? 0);
    $ctx = commentRenderContext($db, $cfg, $me, array_column($raw, 'body'));
    $base = (string)$ctx['base'];
    $profiles = function_exists('profilesEnabled') && profilesEnabled($cfg);
    $linksOn = commentLinksOn($cfg);
    $mod = $uid > 0 && commentCan($db, $cfg, $me, 'comment.moderate');
    $mayEditOwn = $uid > 0 && commentCan($db, $cfg, $me, 'comment.edit_own');
    $mayDelOwn = $uid > 0 && commentCan($db, $cfg, $me, 'comment.delete_own');
    $writable = $uid > 0 && commentMayPost($db, $cfg, $me)['ok'];
    $editWin = commentEditMinutes($cfg) * 60;
    $delWin = commentDeleteOwnMinutes($cfg) * 60;
    // The reader's clock, once for the batch; a guest reads the site's.
    $tzRow = $uid > 0 ? $me : null;
    if ($uid > 0 && !array_key_exists('timezone', (array)$me)) {
        try {
            $st = $db->prepare("SELECT timezone FROM users WHERE id = ?");
            $st->execute([$uid]);
            $tzRow = ['timezone' => $st->fetchColumn() ?: null];
        } catch (\Throwable $e) { $tzRow = null; }
    }
    $tz = userDisplayTimezone($tzRow, $cfg);
    // The authors THIS reader blocked, and the ones who hid their profile from this reader — two queries
    // for the batch, not two per row.
    $authors = [];
    foreach ($raw as $r) if ($r['user_id'] !== null && (int)$r['user_id'] > 0 && (int)$r['user_id'] !== $uid) $authors[(int)$r['user_id']] = true;
    $blocked = [];
    $hiddenFrom = [];
    if ($uid > 0 && $authors) {
        $ids = array_keys($authors);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT blocked_id FROM user_blocks WHERE user_id = ? AND blocked_id IN ($in)");
        $st->execute(array_merge([$uid], $ids));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $b) $blocked[(int)$b] = true;
        $st = $db->prepare("SELECT user_id FROM user_blocks WHERE blocked_id = ? AND hide_profile = 1 AND user_id IN ($in)");
        $st->execute(array_merge([$uid], $ids));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $b) $hiddenFrom[(int)$b] = true;
    }
    // Which of them THIS reader may report, and has already reported (1.71.0 part E, includes/reports.php) —
    // one query for the batch.
    $reportFlags = function_exists('contentReportFlags') ? contentReportFlags($db, $cfg, $me, 'comment', $raw) : [];

    $out = [];
    foreach ($raw as $r) {
        $aid = $r['user_id'] === null ? 0 : (int)$r['user_id'];
        $name = ($aid > 0 && $r['username'] !== null) ? (string)$r['username'] : '';
        $guest = $aid <= 0 && $r['user_id'] === null;
        $own = $uid > 0 && $aid === $uid;
        $age = (isset($r['age_s']) && is_numeric($r['age_s'])) ? max(0, (int)$r['age_s']) : null;
        $status = (string)$r['status'];
        // A guest's links are never links; a NEW account's are text too (1.71.0, includes/antispam.php) — decided
        // by when these words were written, so a link written on an account's first day stays text for good.
        $ctx['links'] = $linksOn && !$guest && !antispamWrittenNew($db, $cfg, $aid, $r['author_age_s'] ?? null);
        // What this reader may do — the same facts commentEditRight()/commentDeleteRight() read, asked once
        // for the batch; the endpoints ask again either way.
        $editLeft = 0;
        $editable = false;
        if ($mod && $status !== 'deleted') {
            $editable = true;
        } elseif ($own && $status === 'visible' && $mayEditOwn && $writable && $editWin > 0 && $age !== null && $age < $editWin) {
            $editable = true;
            $editLeft = $editWin - $age;
        }
        $delete = null;
        $delLeft = 0;
        if ($mod) {
            $delete = $own ? 'own' : 'any';
        } elseif ($own && $mayDelOwn) {
            if ($delWin === 0) $delete = 'own';
            elseif ($age !== null && $age < $delWin) { $delete = 'own'; $delLeft = $delWin - $age; }
        }
        $ts = (isset($r['created_ts']) && is_numeric($r['created_ts'])) ? (int)$r['created_ts'] : null;
        $editedTs = (isset($r['edited_ts']) && is_numeric($r['edited_ts'])) ? (int)$r['edited_ts'] : null;
        $editedBy = ($r['edited_by'] ?? null) === null ? 0 : (int)$r['edited_by'];
        $out[] = [
            'id'         => (int)$r['id'],
            'guest'      => $guest,
            'guest_tag'  => $guest ? (string)($r['guest_tag'] ?? '') : '',
            'user'       => $name,
            // A deleted account's comments go with it (commentForgetAccount()); a name that is missing
            // anyway (a row from a MySQL client) is "a deleted account" rather than a blank.
            'gone'       => !$guest && $name === '',
            'avatar'     => ($name !== '' && function_exists('userAvatarField'))
                ? userAvatarField(['username' => $name, 'avatar_sha' => $r['avatar_sha'] ?? null], 24, $base, $cfg) : '',
            'profile'    => $name !== '' && $profiles && ($r['user_status'] ?? '') === 'active' && !isset($hiddenFrom[$aid]),
            'html'       => commentRenderHtml((string)$r['body'], (string)$r['body_format'], $cfg, $ctx),
            'ts'         => $ts,
            'time'       => $ts !== null ? userDisplayTime($ts, $tz, 'Y-m-d H:i') : '',
            'at'         => $ts !== null ? userDisplayTime($ts, $tz, 'Y-m-d H:i:s P') : '',
            'status'     => $status,
            'edited'     => $editedTs !== null,
            'edited_mod' => $editedTs !== null && $editedBy > 0 && $editedBy !== $aid,
            'edited_at'  => $editedTs !== null ? userDisplayTime($editedTs, $tz, 'Y-m-d H:i:s P') : '',
            'own'        => $own,
            'blocked'    => isset($blocked[$aid]),
            'can_edit'   => $editable,
            'edit_left'  => $editLeft,
            'can_delete' => $delete,
            'del_left'   => $delLeft,
            'can_approve' => $mod && $status === 'pending',
            // Part E's Report button (window.Comments.onActions): somebody else's visible comment, a reader holding
            // content.report; `reported` — an open report of theirs on it, drawn as "Reported".
            'can_report' => !empty($reportFlags[(int)$r['id']]['can']),
            'reported'   => !empty($reportFlags[(int)$r['id']]['reported']),
        ];
    }
    return $out;
}

/* ── notifications ─────────────────────────────────────────────────────────────────────────────── */

/** The language a notification is written in for THIS account: theirs, else the site's, else English. */
function commentLangFor(array $cfg, ?array $user): string
{
    $mine = strtolower(trim((string)($user['language'] ?? '')));
    if ($mine !== '' && function_exists('langSupported') && langSupported($cfg, $mine)) return $mine;
    $site = strtolower(trim((string)($cfg['default_language'] ?? '')));
    if ($site !== '' && $site !== 'auto' && function_exists('langSupported') && langSupported($cfg, $site)) return $site;
    return defined('LANG_FALLBACK') ? LANG_FALLBACK : 'en';
}

/** The address a notification about a comment points at: the search page's Info panel, at the comment. */
function commentLink(string $hash, int $id): string
{
    return '?action=search&hash=' . $hash . '#comment-' . $id;
}

/** The torrent's name, for a sentence ("a comment on …"): the catalogue's, the whitelist's, or the hash. */
function commentTorrentName(PDO $db, string $hash): string
{
    if (function_exists('contentNameFor')) {
        $n = trim(contentNameFor($db, $hash));
        if ($n !== '') return mb_substr($n, 0, 120, 'UTF-8');
    }
    return substr($hash, 0, 12) . '…';
}

/**
 * Tell the people a new VISIBLE comment is news to (see the head of this file). Returns [user id => type].
 * A held guest comment tells nobody until it is let through (commentApprove() calls this then); a guest's
 * comment mentions nobody. Every notification is written in the recipient's language, with the link.
 */
function commentNotifyNew(PDO $db, array $cfg, array $row): array
{
    if (($row['status'] ?? '') !== 'visible' || !commentsEnabled($cfg)) return [];
    $hash = (string)$row['info_hash'];
    $author = $row['user_id'] === null ? 0 : (int)$row['user_id'];
    $id = (int)$row['id'];
    // Who, and why: EVERY reason each account has — each is told once, by the strongest reason they did not
    // switch off (a member who switched mentions off still hears of the thread they are in).
    $why = [];   // uid => ['mention' => true, 'mine' => true, 'desc' => true, 'thread' => true] (any of)
    foreach (commentMentionIds($db, (string)$row['body'], $author) as $m) $why[(int)$m]['mention'] = true;
    try {
        $st = $db->prepare("SELECT submitter_id FROM whitelist WHERE info_hash = ? LIMIT 1");
        $st->execute([$hash]);
        $reg = (int)($st->fetchColumn() ?: 0);
        if ($reg > 0) $why[$reg]['mine'] = true;
    } catch (\Throwable $e) { /* no whitelist row */ }
    if (function_exists('contentRecordFor')) {
        $rec = contentRecordFor($db, $hash);
        if ($rec !== null && ($rec['content_status'] ?? '') === 'approved' && !empty($rec['content_user_id'])) {
            $why[(int)$rec['content_user_id']]['desc'] = true;
        }
    }
    $cap = COMMENT_PARTICIPANTS_MAX;
    $st = $db->prepare("SELECT user_id FROM hash_comments WHERE info_hash = ? AND status = 'visible' AND user_id IS NOT NULL AND id < ?
                         GROUP BY user_id ORDER BY MAX(id) DESC LIMIT $cap");
    $st->execute([$hash, $id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) { $p = (int)$p; if ($p > 0) $why[$p]['thread'] = true; }
    unset($why[$author], $why[0]);
    if (!$why) return [];

    // The accounts, their switches and their blocks — one query for the lot.
    $ids = array_keys($why);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, username, language, status, comment_notify FROM users WHERE id IN ($in)");
    $st->execute($ids);
    $users = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $users[(int)$u['id']] = $u;
    $blocked = [];
    if ($author > 0) {
        $st = $db->prepare("SELECT user_id, blocked_id FROM user_blocks
                             WHERE (blocked_id = ? AND user_id IN ($in)) OR (user_id = ? AND blocked_id IN ($in))");
        $st->execute(array_merge([$author], $ids, [$author], $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $blocked[(int)$b['user_id'] === $author ? (int)$b['blocked_id'] : (int)$b['user_id']] = true;
        }
    }
    $authorName = '';
    if ($author > 0) {
        $st = $db->prepare("SELECT username FROM users WHERE id = ?");
        $st->execute([$author]);
        $authorName = (string)($st->fetchColumn() ?: '');
    }
    $who = $authorName !== '' ? $authorName : null;
    $torrent = commentTorrentName($db, $hash);
    $excerpt = commentExcerpt((string)$row['body']);
    $link = commentLink($hash, $id);
    // The strongest reason first: a mention, then "my torrent", "my description", "my thread".
    $bit = ['mention' => COMMENT_NOTIFY_MENTION, 'mine' => COMMENT_NOTIFY_MINE, 'desc' => COMMENT_NOTIFY_DESC, 'thread' => COMMENT_NOTIFY_THREAD];
    $told = [];
    foreach ($why as $uid => $reasons) {
        $u = $users[$uid] ?? null;
        if ($u === null || ($u['status'] ?? '') !== 'active' || isset($blocked[$uid])) continue;
        $on = (int)($u['comment_notify'] ?? COMMENT_NOTIFY_ALL);
        $reason = null;
        foreach ($bit as $r => $b) {
            if (isset($reasons[$r]) && ($on & $b) !== 0) { $reason = $r; break; }
        }
        if ($reason === null) continue;                                     // every reason they have is switched off
        if (!commentCan($db, $cfg, $u, 'comment.view')) continue;           // may not read it: not news to them
        $type = $reason === 'mention' ? 'comment_mention' : 'comment';
        // A thread already unread in their notifications is not announced again (a mention always is).
        if ($type === 'comment') {
            $st = $db->prepare("SELECT 1 FROM user_notifications WHERE user_id = ? AND read_at IS NULL AND type = 'comment'
                                   AND link LIKE ? LIMIT 1");
            $st->execute([$uid, '?action=search&hash=' . $hash . '#%']);
            if ($st->fetchColumn()) continue;
        }
        $lang = commentLangFor($cfg, $u);
        $name = $who ?? langFor($lang, 'notify.comment_guest');
        $key = 'notify.comment_' . $reason;
        userNotify($db, $uid, $type, langFor($lang, $key, ['user' => $name, 'name' => $torrent]),
                   langFor($lang, 'notify.comment_body', ['text' => $excerpt]), $link);
        $told[$uid] = $type;
    }
    return $told;
}

/** The comment notifications this account has not read: ['comment' => every one, 'comment_mention' => the mentions]. */
function commentUnreadCounts(PDO $db, int $userId): array
{
    $zero = ['comment' => 0, 'comment_mention' => 0];
    if ($userId <= 0) return $zero;
    try {
        $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(type = 'comment_mention'), 0) AS m FROM user_notifications
                             WHERE user_id = ? AND read_at IS NULL AND type IN ('comment','comment_mention')");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['comment' => (int)($r['n'] ?? 0), 'comment_mention' => (int)($r['m'] ?? 0)];
    } catch (\Throwable $e) {
        return $zero;
    }
}

/* ── writing ───────────────────────────────────────────────────────────────────────────────────── */

/**
 * THE ONE anti-abuse gate every comment write passes (a new one, a correction) — the site's one anti-spam
 * layer since 1.71.0 part F (includes/antispam.php, context `comment`). Null when the write may go ahead (its
 * reservation in `$ticket`, for the caller to record once written or hand back when the words are refused),
 * else ['status', 'error', 'body' => the layer's own answer] or ['status', 'error' => 'rate_limit', …].
 *
 *   · a GUEST solves a CAPTCHA every time (the layer's guest rule; the verified token is spent on this write);
 *   · a MEMBER is paced by the comment ladder — two free, then 15 s, 30 s, 1 min, 2 min, five quiet minutes
 *     and it starts over — and meets a CAPTCHA only by staying at its top; the same words again are refused;
 *     a new account waits twice as long and its links are text;
 *   · a CORRECTION is not laddered: a few seconds from the last one, that is all;
 *   · then the plain hourly limit, per account — or, for a guest, per address group (comment_rate_per_hour).
 * A member's comment still adds `captcha_pts_comment` to the smart CAPTCHA's score that the site's forms read.
 * The CAPTCHA question is asked through `$opts['verify']` when a test hands one in, else verifyCaptcha().
 */
function commentFloodCheck(PDO $db, array $cfg, ?array $me, string $ip, array $input, string $action = 'post', array $opts = [], ?array &$ticket = null): ?array
{
    $uid = (int)($me['id'] ?? 0);
    $guest = $uid <= 0;
    $session = session_status() === PHP_SESSION_ACTIVE;
    $ticket = null;
    $words = is_string($input['body'] ?? null) ? commentClean((string)$input['body']) : '';
    $t = antispamCheck($db, $cfg, 'comment', antispamSubject($me, $ip), $action === 'post' ? $words : null,
                       ['mode' => $action === 'post' ? 'write' : 'edit', 'input' => $input, 'verify' => $opts['verify'] ?? null]);
    if (!$t['ok']) return ['status' => (int)$t['status'], 'error' => (string)$t['body']['error'], 'body' => $t['body']];
    $key = $guest ? 'g' . (function_exists('ipBucket') ? ipBucket($ip) : $ip) : 'u' . $uid;
    if (!rateLimitAllow('comment', $key, commentRatePerHour($cfg), 3600)) {
        antispamRelease($db, $t['ticket']);
        return ['status' => 429, 'error' => 'rate_limit', 'retry_after' => 3600];
    }
    if ($action === 'post' && !$guest && $session && function_exists('addCaptchaPoints')) addCaptchaPoints($cfg, 'comment');
    $ticket = $t['ticket'];
    return null;
}

/** What a refused flood check becomes as a request's answer: the layer's own, or the hourly limit's. */
function commentFloodAnswer(array $flood): array
{
    if (isset($flood['body']) && is_array($flood['body'])) return ['status' => (int)$flood['status'], 'body' => $flood['body']];
    $extra = $flood;
    unset($extra['status'], $extra['error'], $extra['body']);
    return commentFail((int)$flood['status'], (string)$flood['error'], [], $extra);
}

/** The failure a request function answers with: the code, and the sentence in the reader's language. */
function commentFail(int $status, string $code, array $vars = [], array $extra = []): array
{
    return ['status' => $status, 'body' => ['success' => false, 'error' => $code, 'message' => __('api.comment.' . $code, $vars)] + $extra];
}

/** The CSRF question every write asks first. */
function commentCsrfOk(array $input): bool
{
    return !empty($input['csrf_token']) && is_string($input['csrf_token']) && function_exists('verifyCsrfToken')
        && verifyCsrfToken($input['csrf_token']);
}

/** The body a request carries, measured before anything is decoded: [clean text] or a failure. */
function commentBodyFrom(array $input, array $cfg): array
{
    $raw = $input['body'] ?? '';
    if (!is_string($raw)) return ['fail' => commentFail(400, 'invalid')];
    if (strlen($raw) > COMMENT_RAW_BYTES) return ['fail' => commentFail(413, 'too_long_source', ['max' => commentSourceCap($cfg)])];
    if (!mb_check_encoding($raw, 'UTF-8')) return ['fail' => commentFail(400, 'bad_encoding')];
    return ['clean' => commentClean($raw)];
}

/**
 * POST comment_post {csrf_token, hash, body[, captcha_token]} — the whole endpoint.
 *
 * The gates in the order a person asks them (the shoutbox's order): is the request real, does the feature
 * exist, is there such a torrent for THIS reader, may they write here (and if not, why), how fast are they
 * writing — and only then, is what they wrote acceptable. Then the row, the people told, and the row as
 * the thread will show it.
 *   → 200 {success, comment: <row>, pending: bool, message}
 */
function commentPostRequest(PDO $db, array $cfg, ?array $me, array $input, string $ip = '', array $opts = []): array
{
    if (!commentCsrfOk($input)) return ['status' => 403, 'body' => ['success' => false, 'error' => 'csrf', 'message' => __('api.csrf.invalid')]];
    if (!commentsEnabled($cfg)) return commentFail(404, 'disabled');
    $hash = strtolower(trim((string)($input['hash'] ?? '')));
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return commentFail(400, 'bad_hash');
    if (!commentHashVisible($db, $cfg, $me, $hash)) return commentFail(404, 'not_found');
    $gate = commentMayPost($db, $cfg, $me);
    if (!$gate['ok']) {
        $st = ['disabled' => 404, 'login' => 401][$gate['reason']] ?? 403;
        return commentFail($st, $gate['reason'], ['until' => (string)($gate['until'] ?? '')], $gate['until'] ? ['until' => $gate['until']] : []);
    }
    $b = commentBodyFrom($input, $cfg);
    if (isset($b['fail'])) return $b['fail'];
    $clean = $b['clean'];
    if ($clean === '') return commentFail(400, 'empty');
    $ticket = null;
    $flood = commentFloodCheck($db, $cfg, $me, $ip, $input, 'post', $opts, $ticket);
    if ($flood !== null) return commentFloodAnswer($flood);
    $bad = commentProblem($clean, $cfg, commentWriterMayLink($cfg, $me));
    if ($bad !== null) {
        antispamRelease($db, $ticket);   // the words were refused: the reserved comment goes back to the layer
        return commentFail(400, $bad['code'], $bad['vars']);
    }

    $uid = (int)($me['id'] ?? 0);
    $guest = $uid <= 0;
    $status = $guest && commentGuestReview($cfg) ? 'pending' : 'visible';
    $db->prepare("INSERT INTO hash_comments (info_hash, user_id, guest_tag, body, body_format, status, ip_bucket) VALUES (?, ?, ?, ?, 'bbcode', ?, ?)")
       ->execute([$hash, $guest ? null : $uid, $guest ? commentGuestTag($ip, $cfg) : null, $clean, $status,
                  $guest ? mb_substr(function_exists('ipBucket') ? ipBucket($ip) : $ip, 0, 45) : null]);
    $id = (int)$db->lastInsertId();
    antispamRecord($db, $ticket);
    $row = commentRow($db, $id);
    if ($row === null) return commentFail(500, 'failed');
    if ($status === 'visible') commentNotifyNew($db, $cfg, $row);
    $shaped = commentShape($db, $cfg, $me, [$row]);
    return ['status' => 200, 'body' => [
        'success' => true,
        'comment' => $shaped[0] ?? null,
        'pending' => $status === 'pending',
        'count'   => commentCount($db, $hash),
        'message' => __($status === 'pending' ? 'api.comment.posted_pending' : 'api.comment.posted'),
    ]];
}

/**
 * GET comment_edit&id= — the words as they are STORED, for the editor to start from; the same gate as the
 * save answers, so a window that closed since the page was drawn is caught before anybody types a word.
 *   → 200 {success, id, body, left}
 */
function commentEditSourceRequest(PDO $db, array $cfg, ?array $me, int $id): array
{
    if (!commentsEnabled($cfg)) return commentFail(404, 'disabled');
    $row = commentRow($db, $id);
    if ($row === null || $row['status'] === 'deleted' || !commentHashVisible($db, $cfg, $me, (string)$row['info_hash'])) return commentFail(404, 'not_found');
    $why = commentEditRight($db, $cfg, $me, $row);
    if ($why !== '') return commentFail(403, $why);
    $left = 0;
    if (!commentCan($db, $cfg, $me, 'comment.moderate')) $left = max(0, commentEditMinutes($cfg) * 60 - (int)$row['age_s']);
    return ['status' => 200, 'body' => ['success' => true, 'id' => (int)$row['id'], 'body' => (string)$row['body'], 'left' => $left,
                                        'own' => $row['user_id'] !== null && (int)$row['user_id'] === (int)($me['id'] ?? 0)]];
}

/**
 * POST comment_edit {csrf_token, id, body[, reason]} — correct a comment.
 *
 * Through the same door as writing one, in the same order: may they touch THIS comment (the window, the
 * mute, comment.edit_own — or comment.moderate), how fast, and what they typed, judged exactly as a new
 * comment is (with the AUTHOR's right to link: a moderator does not give a guest's comment a link).
 * Words that did not change change nothing. Somebody else's words changed are an audited act, the comment
 * says "edited by a moderator", and its author is told — with the reason when one is given — unless
 * `$opts['notify']` is false (part E's silent choice). Names newly @-mentioned by the edit are told.
 *   → 200 {success, comment: <row>, changed}
 */
function commentEditRequest(PDO $db, array $cfg, ?array $me, array $input, string $ip = '', array $opts = []): array
{
    if (!commentCsrfOk($input)) return ['status' => 403, 'body' => ['success' => false, 'error' => 'csrf', 'message' => __('api.csrf.invalid')]];
    if (!commentsEnabled($cfg)) return commentFail(404, 'disabled');
    $row = commentRow($db, (int)($input['id'] ?? 0));
    if ($row === null || $row['status'] === 'deleted' || !commentHashVisible($db, $cfg, $me, (string)$row['info_hash'])) return commentFail(404, 'not_found');
    $why = commentEditRight($db, $cfg, $me, $row);
    if ($why !== '') return commentFail(403, $why);
    $b = commentBodyFrom($input, $cfg);
    if (isset($b['fail'])) return $b['fail'];
    $clean = $b['clean'];
    if ($clean === '') return commentFail(400, 'empty');
    $ticket = null;
    $flood = commentFloodCheck($db, $cfg, $me, $ip, $input, 'edit', $opts, $ticket);
    if ($flood !== null) return commentFloodAnswer($flood);
    $authorId = $row['user_id'] === null ? 0 : (int)$row['user_id'];
    $bad = commentProblem($clean, $cfg, commentLinksOn($cfg) && $authorId > 0);
    if ($bad !== null) {
        antispamRelease($db, $ticket);
        return commentFail(400, $bad['code'], $bad['vars']);
    }
    $reason = trim(mb_substr((string)($input['reason'] ?? ''), 0, COMMENT_REASON_MAX, 'UTF-8'));
    $uid = (int)$me['id'];
    $changed = $clean !== (string)$row['body'];
    if (!$changed) antispamRelease($db, $ticket);   // the same words: not a correction at all
    if ($changed) {
        $st = $db->prepare("UPDATE hash_comments SET body = ?, edited_at = NOW(), edited_by = ? WHERE id = ? AND status <> 'deleted'");
        $st->execute([$clean, $uid, (int)$row['id']]);
        if ($st->rowCount() < 1) {
            antispamRelease($db, $ticket);
            return commentFail(404, 'not_found');
        }
        antispamRecord($db, $ticket);
        $hash = (string)$row['info_hash'];
        if ($authorId !== $uid) {
            if (function_exists('auditLog')) {
                auditLog($db, 'comment.edit', ['target_type' => 'comment', 'target_id' => (string)$row['id'],
                    'summary' => (string)($me['username'] ?? '#' . $uid) . ' → edited comment #' . $row['id'] . ' by '
                               . ($authorId > 0 ? (string)($row['username'] ?? '#' . $authorId) : 'Guest #' . $row['guest_tag']),
                    'detail' => ['editor_id' => $uid, 'author_id' => $authorId, 'hash' => $hash, 'reason' => $reason]]);
            }
            if ($authorId > 0 && ($opts['notify'] ?? true) !== false) {
                $author = userFindById($db, $authorId);
                if ($author !== null) {
                    $lang = commentLangFor($cfg, $author);
                    userNotify($db, $authorId, 'comment_mod', langFor($lang, 'notify.comment_edited', ['name' => commentTorrentName($db, $hash)]),
                               $reason !== '' ? langFor($lang, 'notify.comment_reason', ['reason' => $reason]) : '', commentLink($hash, (int)$row['id']));
                }
            }
        }
        // Somebody the new words name and the old ones did not: told now (a mention is news once).
        if ($row['status'] === 'visible' && $authorId > 0) {
            $before = commentMentionIds($db, (string)$row['body'], $authorId);
            $new = array_values(array_diff(commentMentionIds($db, $clean, $authorId), $before));
            if ($new) {
                $fresh = commentRow($db, (int)$row['id']);
                if ($fresh !== null) commentNotifyMentions($db, $cfg, $fresh, $new);
            }
        }
    }
    $fresh = commentRow($db, (int)$row['id']);
    $shaped = $fresh !== null ? commentShape($db, $cfg, $me, [$fresh]) : [];
    return ['status' => 200, 'body' => ['success' => true, 'comment' => $shaped[0] ?? null, 'changed' => $changed,
                                        'message' => __($changed ? 'api.comment.edited' : 'api.comment.unchanged')]];
}

/** Tell only these (mentioned) accounts about this comment — commentNotifyNew()'s rules, one reason. */
function commentNotifyMentions(PDO $db, array $cfg, array $row, array $ids): array
{
    $author = $row['user_id'] === null ? 0 : (int)$row['user_id'];
    $told = [];
    foreach (array_unique(array_map('intval', $ids)) as $uid) {
        if ($uid <= 0 || $uid === $author) continue;
        $u = userFindById($db, $uid);
        if ($u === null || ($u['status'] ?? '') !== 'active') continue;
        if (((int)($u['comment_notify'] ?? COMMENT_NOTIFY_ALL) & COMMENT_NOTIFY_MENTION) === 0) continue;
        if (function_exists('blockRow') && (blockRow($db, $uid, $author) !== null || blockRow($db, $author, $uid) !== null)) continue;
        if (!commentCan($db, $cfg, $u, 'comment.view')) continue;
        $lang = commentLangFor($cfg, $u);
        $st = $db->prepare("SELECT username FROM users WHERE id = ?");
        $st->execute([$author]);
        $name = (string)($st->fetchColumn() ?: langFor($lang, 'notify.comment_guest'));
        userNotify($db, $uid, 'comment_mention', langFor($lang, 'notify.comment_mention', ['user' => $name, 'name' => commentTorrentName($db, (string)$row['info_hash'])]),
                   langFor($lang, 'notify.comment_body', ['text' => commentExcerpt((string)$row['body'])]), commentLink((string)$row['info_hash'], (int)$row['id']));
        $told[$uid] = 'comment_mention';
    }
    return $told;
}

/**
 * Take a comment down — SOFTLY: the row keeps who, when and why, and nobody reads it any more.
 * ['ok' => true, 'right' => 'own'|'any'] or ['ok' => false, 'error' => code, 'status' => int].
 *
 * Your own (commentDeleteRight()): no reason asked, no audit line, nobody told — tidying. Somebody else's
 * (comment.moderate): the reason is REQUIRED (it is what the author is shown), one audit line, and the
 * author told with the reason — unless `$opts['notify']` is false: part E's silent removal. A guest's
 * held comment turned down is the same act; there is nobody to tell.
 *
 * `$opts['authority'] === 'panel'` (1.71.0 part E, includes/reports.php): the Reports page, whose caller has
 * already asked panelCan() for `panel.reports.comments.handle` — the removal is a moderator's ('any') whatever
 * the account behind the panel session holds, and the owner's own session (no account, id 0) stamps no one.
 */
function commentDelete(PDO $db, array $cfg, ?array $me, array $row, string $reason = '', array $opts = []): array
{
    $right = ($opts['authority'] ?? '') === 'panel' && ($row['status'] ?? '') !== 'deleted'
        ? ['right' => 'any', 'error' => ''] : commentDeleteRight($db, $cfg, $me, $row);
    if ($right['right'] === '') return ['ok' => false, 'error' => $right['error'], 'status' => 403];
    $reason = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason));
    $reason = mb_substr($reason, 0, COMMENT_REASON_MAX, 'UTF-8');
    if ($right['right'] === 'any' && $reason === '') return ['ok' => false, 'error' => 'reason_required', 'status' => 400];
    $uid = (int)($me['id'] ?? 0);
    $st = $db->prepare("UPDATE hash_comments SET status = 'deleted', deleted_at = NOW(), deleted_by = ?, delete_reason = ?
                         WHERE id = ? AND status <> 'deleted'");
    $st->execute([$uid > 0 ? $uid : null, $reason !== '' ? $reason : null, (int)$row['id']]);
    if ($st->rowCount() < 1) return ['ok' => false, 'error' => 'not_found', 'status' => 404];
    if ($right['right'] === 'any') {
        $authorId = $row['user_id'] === null ? 0 : (int)$row['user_id'];
        $hash = (string)$row['info_hash'];
        $silent = ($opts['notify'] ?? true) === false;
        if (function_exists('auditLog')) {
            auditLog($db, 'comment.delete', ['target_type' => 'comment', 'target_id' => (string)$row['id'],
                'summary' => (string)($me['username'] ?? '#' . $uid) . ' → comment #' . $row['id'] . ' by '
                           . ($authorId > 0 ? (string)($row['username'] ?? '#' . $authorId) : 'Guest #' . $row['guest_tag']) . ': ' . $reason,
                'detail' => ['moderator_id' => $uid, 'author_id' => $authorId, 'hash' => $hash, 'reason' => $reason,
                             'was' => (string)$row['status'], 'silent' => $silent]]);
        }
        if ($authorId > 0 && !$silent) {
            $author = userFindById($db, $authorId);
            if ($author !== null) {
                $lang = commentLangFor($cfg, $author);
                userNotify($db, $authorId, 'comment_mod', langFor($lang, 'notify.comment_removed', ['name' => commentTorrentName($db, $hash)]),
                           langFor($lang, 'notify.comment_reason', ['reason' => $reason]), '?action=search&hash=' . $hash);
            }
        }
    }
    return ['ok' => true, 'right' => $right['right']];
}

/**
 * POST comment_delete {csrf_token, id[, reason]} → 200 {success, right, count}. The request's half of
 * commentDelete(); `$opts` goes through to it (part E's silent choice).
 */
function commentDeleteRequest(PDO $db, array $cfg, ?array $me, array $input, array $opts = []): array
{
    if (!commentCsrfOk($input)) return ['status' => 403, 'body' => ['success' => false, 'error' => 'csrf', 'message' => __('api.csrf.invalid')]];
    if (!commentsEnabled($cfg)) return commentFail(404, 'disabled');
    if ((int)($me['id'] ?? 0) <= 0) return commentFail(401, 'login');
    $row = commentRow($db, (int)($input['id'] ?? 0));
    if ($row === null || $row['status'] === 'deleted' || !commentHashVisible($db, $cfg, $me, (string)$row['info_hash'])) return commentFail(404, 'not_found');
    $r = commentDelete($db, $cfg, $me, $row, is_string($input['reason'] ?? null) ? (string)$input['reason'] : '', $opts);
    if (!$r['ok']) return commentFail((int)$r['status'], (string)$r['error']);
    return ['status' => 200, 'body' => ['success' => true, 'right' => $r['right'], 'count' => commentCount($db, (string)$row['info_hash']),
                                        'message' => __($r['right'] === 'any' ? 'api.comment.removed' : 'api.comment.deleted')]];
}

/**
 * Let a held guest comment through (comment.moderate): visible, stamped, audited — and only NOW are the
 * people it is news to told. ['ok' => true] or ['ok' => false, 'error', 'status']. Part E's Reports page
 * calls this for its queue as the Info panel does.
 */
function commentApprove(PDO $db, array $cfg, ?array $me, array $row): array
{
    if ((int)($me['id'] ?? 0) <= 0 || !commentCan($db, $cfg, $me, 'comment.moderate')) return ['ok' => false, 'error' => 'no_permission', 'status' => 403];
    if ($row['status'] !== 'pending') return ['ok' => false, 'error' => 'not_pending', 'status' => 409];
    $st = $db->prepare("UPDATE hash_comments SET status = 'visible', approved_at = NOW(), approved_by = ? WHERE id = ? AND status = 'pending'");
    $st->execute([(int)$me['id'], (int)$row['id']]);
    if ($st->rowCount() < 1) return ['ok' => false, 'error' => 'not_pending', 'status' => 409];
    if (function_exists('auditLog')) {
        auditLog($db, 'comment.approve', ['target_type' => 'comment', 'target_id' => (string)$row['id'],
            'summary' => (string)($me['username'] ?? '#' . $me['id']) . ' → let through comment #' . $row['id'] . ' by Guest #' . $row['guest_tag'],
            'detail' => ['moderator_id' => (int)$me['id'], 'hash' => (string)$row['info_hash']]]);
    }
    $fresh = commentRow($db, (int)$row['id']);
    if ($fresh !== null) commentNotifyNew($db, $cfg, $fresh);
    return ['ok' => true];
}

/** POST comment_approve {csrf_token, id} → 200 {success, comment, count}. */
function commentApproveRequest(PDO $db, array $cfg, ?array $me, array $input): array
{
    if (!commentCsrfOk($input)) return ['status' => 403, 'body' => ['success' => false, 'error' => 'csrf', 'message' => __('api.csrf.invalid')]];
    if (!commentsEnabled($cfg)) return commentFail(404, 'disabled');
    $row = commentRow($db, (int)($input['id'] ?? 0));
    if ($row === null || !commentHashVisible($db, $cfg, $me, (string)$row['info_hash'])) return commentFail(404, 'not_found');
    $r = commentApprove($db, $cfg, $me, $row);
    if (!$r['ok']) return commentFail((int)$r['status'], (string)$r['error']);
    $fresh = commentRow($db, (int)$row['id']);
    $shaped = $fresh !== null ? commentShape($db, $cfg, $me, [$fresh]) : [];
    return ['status' => 200, 'body' => ['success' => true, 'comment' => $shaped[0] ?? null, 'count' => commentCount($db, (string)$row['info_hash']),
                                        'message' => __('api.comment.approved')]];
}

/**
 * The held guest comments, oldest first — for part E's Reports page (and anything else that works the
 * queue): [id, info_hash, guest_tag, body, created_at]. The moderator's permission is the caller's to ask.
 */
function commentPendingList(PDO $db, int $limit = 50, int $offset = 0): array
{
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    try {
        $st = $db->prepare("SELECT id, info_hash, guest_tag, body, created_at FROM hash_comments WHERE status = 'pending'
                             ORDER BY created_at ASC, id ASC LIMIT $limit OFFSET $offset");
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/* ── reading ───────────────────────────────────────────────────────────────────────────────────── */

/**
 * What the composer is told about THIS reader: whether they may write here and why not, as whom, what they
 * may put in it, and the picker's data for the comment context (emojiPickerData()).
 */
function commentComposerInfo(PDO $db, array $cfg, ?array $me): array
{
    $gate = commentMayPost($db, $cfg, $me);
    $uid = (int)($me['id'] ?? 0);
    $picker = null;
    if ($gate['ok'] && function_exists('emojiPickerData')) {
        $d = emojiPickerData($db, $cfg, function_exists('getBaseUrl') ? getBaseUrl() : '', 'comment');
        $picker = ['for' => 'comment', 'files' => $d['files'], 'fa' => $d['fa'], 'fa_v' => $d['fa_v'],
                   'emotes' => (bool)$d['emotes'], 'stickers' => (bool)$d['stickers'], 'emotes_page' => (bool)$d['emotes_page']];
    }
    return [
        'can_post'  => (bool)$gate['ok'],
        'why'       => (string)$gate['reason'],
        'until'     => $gate['until'] ?? null,
        'guest'     => $uid <= 0,
        'name'      => (string)($me['username'] ?? ''),
        'links'     => $gate['ok'] && commentWriterMayLink($cfg, $me),
        'max'       => commentMax($cfg),
        'source_max' => commentSourceCap($cfg),
        'max_links' => COMMENT_MAX_LINKS,
        'review'    => $uid <= 0 && commentGuestReview($cfg),
        // A guest's CAPTCHA every time (the anti-spam layer's guest rule, includes/antispam.php) — the page asks
        // for one before it sends; a new account's links will be text, and the composer says so (1.71.0).
        'captcha'   => $uid <= 0 && commentCaptchaReady($cfg) && antispamGuestCaptcha($cfg),
        'links_text' => $gate['ok'] && $uid > 0 && commentWriterMayLink($cfg, $me) && antispamLinksTextNow($db, $cfg, $me),
        'new_days'  => antispamNewDays($cfg),
        'mentions'  => $gate['ok'] && $uid > 0,
        'moderator' => $uid > 0 && commentCan($db, $cfg, $me, 'comment.moderate'),
        'edit_minutes' => commentEditMinutes($cfg),
        'picker'    => $picker,
    ];
}

/**
 * GET comment_list&hash=H[&before=ID] — a page of a thread and what this reader may do in it.
 *   → 200 {success, hash, rows: [<row>…] oldest first, earlier: bool, count, pending (moderators),
 *          per_page, me: commentComposerInfo()}
 */
function commentListRequest(PDO $db, array $cfg, ?array $me, array $query): array
{
    if (!commentsEnabled($cfg)) return commentFail(404, 'disabled');
    $hash = strtolower(trim((string)($query['hash'] ?? '')));
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return commentFail(400, 'bad_hash');
    if (!commentHashVisible($db, $cfg, $me, $hash)) return commentFail(404, 'not_found');
    if (!commentCan($db, $cfg, $me, 'comment.view')) return commentFail((int)($me['id'] ?? 0) > 0 ? 403 : 401, 'no_view');
    $mod = (int)($me['id'] ?? 0) > 0 && commentCan($db, $cfg, $me, 'comment.moderate');
    $before = isset($query['before']) && is_numeric($query['before']) ? max(0, (int)$query['before']) : null;
    $per = commentsPerPage($cfg);
    $page = commentPage($db, $hash, $before, $per, $mod);
    return ['status' => 200, 'body' => [
        'success'  => true,
        'hash'     => $hash,
        'rows'     => commentShape($db, $cfg, $me, $page['rows']),
        'earlier'  => $page['earlier'],
        'count'    => commentCount($db, $hash),
        'pending'  => $mod ? commentPendingCount($db, $hash) : 0,
        'per_page' => $per,
        'me'       => commentComposerInfo($db, $cfg, $me),
    ]];
}

/**
 * What the Info panel's one answer carries about the comments (api/index_info.php): whether the section is
 * drawn at all, how many there are for this reader to open, and — for a reader who may not read them —
 * that there ARE some, so the space does not read as "nobody said anything".
 */
function commentPanelInfo(PDO $db, array $cfg, ?array $me, string $hash): ?array
{
    if (!commentsEnabled($cfg)) return null;
    $view = commentCan($db, $cfg, $me, 'comment.view');
    $n = commentCount($db, $hash);
    if (!$view && $n === 0) return null;
    return ['view' => $view, 'count' => $n, 'post' => $view && commentMayPost($db, $cfg, $me)['ok'],
            'signed_in' => (int)($me['id'] ?? 0) > 0];
}

/* ── the account's own choices ─────────────────────────────────────────────────────────────────── */

/** users.comment_notify as the four named switches. */
function commentNotifyPrefs(?array $user): array
{
    $v = (int)($user['comment_notify'] ?? COMMENT_NOTIFY_ALL);
    $out = [];
    foreach (COMMENT_NOTIFY_KEYS as $k => $bit) $out[$k] = ($v & $bit) !== 0;
    return $out;
}

/**
 * GET / POST comment_prefs {csrf_token, mine?, desc?, thread?, mention?: 0|1} — which comments this account
 * is told about. A key left out is left as it is. No password: a preference, like the mail ones beside it.
 *   → 200 {success, prefs: {mine, desc, thread, mention}}
 */
function commentPrefsRequest(PDO $db, array $cfg, ?array $me, array $input, bool $post): array
{
    $uid = (int)($me['id'] ?? 0);
    if (!commentsEnabled($cfg)) return commentFail(404, 'disabled');
    if ($uid <= 0) return commentFail(401, 'login');
    if ($post) {
        if (!commentCsrfOk($input)) return ['status' => 403, 'body' => ['success' => false, 'error' => 'csrf', 'message' => __('api.csrf.invalid')]];
        $v = (int)($me['comment_notify'] ?? COMMENT_NOTIFY_ALL);
        $any = false;
        foreach (COMMENT_NOTIFY_KEYS as $k => $bit) {
            if (!array_key_exists($k, $input)) continue;
            $any = true;
            $v = !empty($input[$k]) ? ($v | $bit) : ($v & ~$bit);
        }
        if (!$any) return commentFail(400, 'nothing_to_change');
        $v &= COMMENT_NOTIFY_ALL;
        $db->prepare("UPDATE users SET comment_notify = ? WHERE id = ?")->execute([$v, $uid]);
        $me['comment_notify'] = $v;
    }
    return ['status' => 200, 'body' => ['success' => true, 'prefs' => commentNotifyPrefs($me)]];
}

/* ── an account leaving ────────────────────────────────────────────────────────────────────────── */

/**
 * Everything of this account in the comments, for userDeleteCascade(): its comments deleted (see the note
 * there), and every stamp that names it as the one who edited, took down or let through somebody else's
 * comment forgotten (NULL — the audit log keeps the name). ['comments' => n, 'comment_stamps' => n].
 */
function commentForgetAccount(PDO $db, int $userId): array
{
    $out = ['comments' => 0, 'comment_stamps' => 0];
    if ($userId <= 0) return $out;
    try {
        $st = $db->prepare("DELETE FROM hash_comments WHERE user_id = ?");
        $st->execute([$userId]);
        $out['comments'] = $st->rowCount();
        foreach (['edited_by', 'deleted_by', 'approved_by'] as $col) {
            $st = $db->prepare("UPDATE hash_comments SET `$col` = NULL WHERE `$col` = ?");
            $st->execute([$userId]);
            $out['comment_stamps'] += $st->rowCount();
        }
    } catch (\Throwable $e) { /* the table arrives with schema 83 */ }
    return $out;
}
