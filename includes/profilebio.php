<?php
/**
 * The description on a profile (v74, 1.69.0): a member's own few lines under their name, with the
 * five tags the owner asked for and not one more.
 *
 * ── why this is not richtextRender() with a switch ─────────────────────────────────────────────
 * The shared renderer (includes/richtext.php) is thirty-odd regex passes over escaped text: emoji
 * shortcodes, bare URLs made into links, paragraphs, [hide] stashes, tables, quotes. An allow-list
 * argument would have to gate every one of those passes, the bio would still inherit the ones that
 * are not tags at all (a pasted address turning into a link, `:fire:` turning into an emoji), and the
 * three rules this text needs are not rules that renderer has: it pairs an opener with the NEXT closer
 * by regex, so an unclosed `[b]` stays literal there and `[b][i]x[/b][/i]` comes out crossed. So this
 * is its own small renderer, and the shared one is not touched — descriptions, messages and shouts
 * render exactly as they did, because nothing they run through changed.
 *
 * ── the rules ─────────────────────────────────────────────────────────────────────────────────
 * Only `[b] [i] [u] [s] [url]address[/url] [url=address]words[/url]`, in any case, become markup.
 * Every other tag, and anything that merely looks like one, is shown as the text it is. HTML is
 * always text: the source is never un-escaped, every run of text is escaped on its way out, and the
 * only tags in the output are the ones written below. A tag left open is closed at the end; a closer
 * with nothing to close is text; a closer that crosses another tag closes that one too and opens it
 * again after itself (a link is never reopened — one link must not become two). A link goes through
 * richtextSafeUrl() and richtextLinkAttrs(), the checks and the rel/target every other link on the
 * site gets; a link inside a link is text; at most three links. Line breaks are kept, at most two in a
 * row, at most PROFILE_BIO_MAX_LINES lines.
 *
 * ── what "characters" means here ─────────────────────────────────────────────────────────────
 * The limit an operator sets is what a READER sees: the words and the literal text, not the tags that
 * format them. Counted in code points (mb_strlen — what `[...s].length` counts in the browser), so an
 * emoji is one character and a Polish letter is one. A line break counts as one. The source as typed
 * has a ceiling of its own (four times the visible limit, never over 4000 characters or 8 KB), so tags
 * cannot be used to store a novel in a field whose visible limit is three hundred.
 *
 * Nothing here reads a request or a session except profileBioSaveRequest(), whose one session read
 * is the CSRF token — so the whole feature, the endpoint's refusals included, can be driven from
 * tests/profile_bio_test.php. assets/js/profile-bio.js carries a twin of profileBioClean() and of the
 * counting half of profileBioParse() for the live counter; the server's count is the one that decides.
 */
require_once __DIR__ . '/richtext.php';

const PROFILE_BIO_MAX_MIN     = 20;
const PROFILE_BIO_MAX_MAX     = 1000;
const PROFILE_BIO_MAX_DEFAULT = 300;
const PROFILE_BIO_SOURCE_FACTOR = 4;       // the source may be this many times the visible limit…
const PROFILE_BIO_SOURCE_CHARS  = 4000;    // …and never more characters than this…
const PROFILE_BIO_SOURCE_BYTES  = 8192;    // …or bytes (4000 four-byte characters would be 16 KB)
const PROFILE_BIO_RAW_BYTES     = 65536;   // what a request may carry at all, before any work is done
const PROFILE_BIO_MAX_LINES   = 8;         // a blank line counts: "a\n\nb" is three lines
const PROFILE_BIO_MAX_LINKS   = 3;
const PROFILE_BIO_MAX_DEPTH   = 8;         // tags open at once; an opener past this is text
const PROFILE_BIO_RATE        = 20;        // saves per hour, per account

/** The feature at all: accounts, profiles to show it on, and the switch in Settings → Profiles. */
function profileBioEnabled(array $cfg): bool
{
    return usersEnabled($cfg)
        && (!function_exists('profilesEnabled') || profilesEnabled($cfg))
        && (($cfg['profile_bio_enabled'] ?? '1') === '1');
}

/** The most characters a reader may be shown, as Settings says, clamped. */
function profileBioMax(array $cfg): int
{
    $v = (int)($cfg['profile_bio_max'] ?? PROFILE_BIO_MAX_DEFAULT);
    return max(PROFILE_BIO_MAX_MIN, min(PROFILE_BIO_MAX_MAX, $v ?: PROFILE_BIO_MAX_DEFAULT));
}

/** The most characters the SOURCE may hold, tags included. */
function profileBioSourceCap(array $cfg): int
{
    return min(profileBioMax($cfg) * PROFILE_BIO_SOURCE_FACTOR, PROFILE_BIO_SOURCE_CHARS);
}

/**
 * The text as it is kept and as it is judged: line breaks made one kind, invisible and directional
 * control characters taken out, runs of spaces made one, at most two line breaks in a row, trimmed.
 *
 * What goes, and why: C0 and C1 controls (a NUL in a profile is not a character anybody typed); the
 * bidi embeddings, overrides and isolates (U+202A–U+202E, U+2066–U+2069), which can make a line read
 * backwards and are how a link's text is made to lie about where it goes — right-to-left text needs
 * none of them, the block is dir="auto"; and the characters that draw nothing at all (zero-width
 * space, word joiner and the invisible operators, the BOM, the Mongolian vowel separator, the
 * interlinear annotation marks), which would let a text that looks empty not be. What stays: the
 * zero-width JOINER and NON-JOINER (an emoji family is three emoji and two joiners; Persian needs
 * the non-joiner), and the left-to-right and right-to-left MARKS, which only nudge punctuation.
 * Spaces collapse because the page collapses them anyway — the stored text should be the text shown.
 */
function profileBioClean(string $raw): string
{
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = (string)mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    $s = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $raw);
    $s = str_replace("\t", ' ', $s);
    $s = (string)preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{180E}\x{200B}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFB}]/u', '', $s);
    $s = (string)preg_replace('/ {2,}/', ' ', $s);
    $s = (string)preg_replace('/ *\n */', "\n", $s);
    $s = (string)preg_replace('/\n{3,}/', "\n\n", $s);
    return trim($s, " \n");
}

/** How many lines a cleaned text has (0 for none). A blank line between paragraphs is one of them. */
function profileBioLines(string $clean): int
{
    return $clean === '' ? 0 : substr_count($clean, "\n") + 1;
}

/**
 * A link's address, or null when it is not one this site will point at: http or https with a host
 * (richtextSafeUrl(), the rule every link on the site obeys), written without spaces. Quotes round
 * the whole address are forgiven — `[url="https://…"]` is how several forums write it.
 *
 * richtextSafeUrl() quietly REMOVES spaces and control characters before its scheme check, which is
 * right for a pasted address and wrong here: `[url]https://exa mple.org[/url]` would show one
 * address and go to another. So anything with white space in it is simply not an address.
 */
function profileBioUrl(string $raw): ?string
{
    $u = trim($raw);
    if (strlen($u) >= 2 && ($u[0] === '"' || $u[0] === "'") && substr($u, -1) === $u[0]) $u = trim(substr($u, 1, -1));
    if ($u === '' || preg_match('/[\s\x00-\x1F\x7F]/u', $u)) return null;
    return richtextSafeUrl($u);
}

/**
 * Turn a CLEANED text into HTML, and count what a reader will see.
 *
 * Returns ['html', 'chars' (code points shown), 'links' (url tags with a good address, the ones past
 * the third included — they are shown as text, and the save refuses them), 'bad_links' (url tags whose
 * address is not one), 'lines'].
 *
 * A walk over the tags, not a regex per tag: the stack is what lets a tag left open be closed at the
 * end, a stray closer stay text, and a closer that crosses another tag close both and reopen the
 * inner one — which is what a browser would do to the crossed markup anyway, done here on purpose.
 */
function profileBioParse(string $s, array $cfg): array
{
    static $el = ['b' => 'strong', 'i' => 'em', 'u' => 'u', 's' => 's', 'url' => 'a'];
    $out = '';
    $chars = 0;
    $links = 0;
    $bad = 0;
    $stack = [];            // ['tag' => b|i|u|s|url, 'mark' => $chars when it opened, 'url' => …]
    $text = function (string $t) use (&$out, &$chars): void {
        if ($t === '') return;
        $chars += mb_strlen($t, 'UTF-8');
        $out .= str_replace("\n", '<br>', htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    };
    $open = function (string $tag, string $attrs = '') use (&$out, &$stack, &$chars, $el): void {
        $stack[] = ['tag' => $tag, 'mark' => $chars];
        $out .= '<' . $el[$tag] . $attrs . '>';
    };
    // Closing a link whose words were empty (`[url=…][/url]`) writes the address as its words: a link
    // nobody can see is not a link anybody can use.
    $close = function (array $frame) use (&$out, &$chars, $text, $el): void {
        if ($frame['tag'] === 'url' && $chars === $frame['mark']) $text((string)$frame['url']);
        $out .= '</' . $el[$frame['tag']] . '>';
    };
    $inLink = function () use (&$stack): bool {
        foreach ($stack as $f) if ($f['tag'] === 'url') return true;
        return false;
    };

    $re = '~\[(?:(b|i|u|s)|/(b|i|u|s|url)|url(?:(=)([^\]\n]*))?)\]~i';
    $pos = 0;
    $nextCloser = -1;       // where the next [/url] starts, found once and reused (see [url] below)
    while (preg_match($re, $s, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $pos)) {
        $tok = $m[0][0];
        $at = $m[0][1];
        $text(substr($s, $pos, $at - $pos));
        $pos = $at + strlen($tok);

        if ($m[1][0] !== null) {                                   // [b] [i] [u] [s]
            if (count($stack) >= PROFILE_BIO_MAX_DEPTH) { $text($tok); continue; }
            $open(strtolower($m[1][0]));
            continue;
        }
        if ($m[2][0] !== null) {                                   // a closer
            $name = strtolower($m[2][0]);
            $idx = null;
            for ($k = count($stack) - 1; $k >= 0; $k--) {
                if ($stack[$k]['tag'] === $name) { $idx = $k; break; }
            }
            if ($idx === null) { $text($tok); continue; }           // closes nothing: it is text
            $reopen = [];
            while (count($stack) - 1 > $idx) {
                $frame = array_pop($stack);
                $close($frame);
                if ($frame['tag'] !== 'url') array_unshift($reopen, $frame['tag']);
            }
            $close(array_pop($stack));
            foreach ($reopen as $t) $open($t);
            continue;
        }

        // [url=…] and [url]
        if ($inLink() || count($stack) >= PROFILE_BIO_MAX_DEPTH) { $text($tok); continue; }
        if ($m[3][0] !== null) {                                   // [url=address]words[/url]
            $url = profileBioUrl((string)$m[4][0]);
            if ($url === null) { $bad++; $text($tok); continue; }
            if (++$links > PROFILE_BIO_MAX_LINKS) { $text($tok); continue; }
            $stack[] = ['tag' => 'url', 'mark' => $chars, 'url' => $url];
            $out .= '<a' . richtextLinkAttrs($url, $cfg) . '>';
            continue;
        }
        // [url]address[/url]: the address is everything up to the next closer, taken whole. No
        // closer, and the opener is text — there is no address to point at.
        if ($nextCloser < $pos) {
            $nextCloser = preg_match('~\[/url\]~i', $s, $cm, PREG_OFFSET_CAPTURE, $pos) ? (int)$cm[0][1] : PHP_INT_MAX;
        }
        if ($nextCloser === PHP_INT_MAX) { $text($tok); continue; }
        $url = profileBioUrl(substr($s, $pos, $nextCloser - $pos));
        if ($url === null) { $bad++; $text($tok); continue; }
        if (++$links > PROFILE_BIO_MAX_LINKS) { $text($tok); continue; }
        $out .= '<a' . richtextLinkAttrs($url, $cfg) . '>';
        $text($url);
        $out .= '</a>';
        $pos = $nextCloser + strlen('[/url]');
    }
    $text(substr($s, $pos));
    while ($stack) $close(array_pop($stack));
    return ['html' => $out, 'chars' => $chars, 'links' => $links, 'bad_links' => $bad, 'lines' => profileBioLines($s)];
}

/**
 * A stored description as a page shows it — '' for none.
 *
 * Cleaned and bounded again on the way out, not only on the way in: a row written before a rule
 * changed, restored from a backup or typed into a MySQL client has never been through the endpoint.
 * Lines past the last one allowed are joined with a space rather than dropped, so nothing written is
 * lost from view, only its line breaks.
 */
function profileBioRender(?string $source, array $cfg): string
{
    $c = profileBioClean((string)$source);
    if ($c === '') return '';
    if (strlen($c) > PROFILE_BIO_SOURCE_BYTES) $c = rtrim(mb_strcut($c, 0, PROFILE_BIO_SOURCE_BYTES, 'UTF-8'));
    $lines = explode("\n", $c);
    if (count($lines) > PROFILE_BIO_MAX_LINES) {
        $c = implode("\n", array_slice($lines, 0, PROFILE_BIO_MAX_LINES - 1)) . "\n"
           . implode(' ', array_filter(array_slice($lines, PROFILE_BIO_MAX_LINES - 1), fn($l) => $l !== ''));
    }
    return profileBioParse($c, $cfg)['html'];
}

/**
 * Everything wrong with a cleaned text, as ['code' => …, 'vars' => […]] for api.bio.<code>, or null.
 * The cheap, bounding questions first: the source's size is asked before the text is walked at all.
 */
function profileBioProblem(string $clean, array $cfg): ?array
{
    $cap = profileBioSourceCap($cfg);
    if (mb_strlen($clean, 'UTF-8') > $cap || strlen($clean) > PROFILE_BIO_SOURCE_BYTES) {
        return ['code' => 'too_long_source', 'vars' => ['max' => $cap]];
    }
    if (profileBioLines($clean) > PROFILE_BIO_MAX_LINES) {
        return ['code' => 'too_many_lines', 'vars' => ['max' => PROFILE_BIO_MAX_LINES]];
    }
    $p = profileBioParse($clean, $cfg);
    $max = profileBioMax($cfg);
    if ($p['chars'] > $max) return ['code' => 'too_long', 'vars' => ['n' => $p['chars'], 'max' => $max]];
    // An address that is not one is shown as the text it is — but somebody who meant a link would
    // only find that out by looking, so the save says it instead.
    if ($p['bad_links'] > 0) return ['code' => 'bad_link', 'vars' => []];
    if ($p['links'] > PROFILE_BIO_MAX_LINKS) return ['code' => 'too_many_links', 'vars' => ['max' => PROFILE_BIO_MAX_LINKS]];
    return null;
}

/** May this ACCOUNT write one? Asked of the account, like every grant a member's own write needs. */
function profileBioMayWrite(PDO $db, array $cfg, ?array $user): bool
{
    $uid = (int)($user['id'] ?? 0);
    return $uid > 0 && profileBioEnabled($cfg) && userIdHasPermission($db, $cfg, $uid, 'profile.bio');
}

/**
 * Somebody's description as their profile draws it, or '' for nothing at all.
 *
 * The same rule as a cover (userCoverFor()): the question is asked of the account the words belong
 * to, at the moment of drawing, and it is the CAPABILITY — the admin group's blanket counts, so the
 * owner's own profile keeps its text although that group stores no grant. A member whose groups lose
 * `profile.bio` has the text hidden, not deleted: it is back on the next page load when the grant is.
 */
function profileBioFor(PDO $db, array $cfg, array $owner): string
{
    if (!profileBioEnabled($cfg)) return '';
    $src = (string)($owner['bio'] ?? '');
    if (trim($src) === '') return '';
    $uid = (int)($owner['id'] ?? 0);
    if ($uid <= 0 || !userIdHasPermission($db, $cfg, $uid, 'profile.bio')) return '';
    return profileBioRender($src, $cfg);
}

/** Write it: the cleaned text, or NULL for none. The stamp moves either way. */
function profileBioStore(PDO $db, int $userId, string $clean): void
{
    $db->prepare("UPDATE users SET bio = ?, bio_updated_at = NOW() WHERE id = ?")
       ->execute([$clean === '' ? null : $clean, $userId]);
}

/**
 * A moderator's clear. True when there was something to take away — false is "nothing changed", and
 * the panel endpoint writes no audit line and sends no notification for it.
 */
function profileBioClear(PDO $db, int $userId): bool
{
    $st = $db->prepare("UPDATE users SET bio = NULL, bio_updated_at = NOW() WHERE id = ? AND bio IS NOT NULL");
    $st->execute([$userId]);
    return $st->rowCount() > 0;
}

/**
 * POST profile_bio {csrf_token, bio} — the whole endpoint, as ['status' => int, 'body' => array].
 *
 * The gates in the order a person asks the questions (the shoutbox's order): is the request real, is
 * somebody making it, does the feature exist, may THEY write one, are they silenced — then how fast
 * they are saving — and only then, is what they wrote acceptable. Being told "too long" by a page you
 * may not write on answers a question nobody asked.
 *
 * An EMPTY text clears, and clearing your own words is never behind the permission or the mute:
 * taking something of yours off a site is not something to be allowed to do. It still needs the
 * token and a session, and it is still counted by the rate limit.
 */
function profileBioSaveRequest(PDO $db, array $cfg, ?array $me, array $input, string $ip = ''): array
{
    $fail = static function (int $status, string $code, array $vars = []): array {
        return ['status' => $status, 'body' => ['success' => false, 'error' => $code, 'message' => __('api.bio.' . $code, $vars)]];
    };
    if (empty($input['csrf_token']) || !is_string($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        return ['status' => 403, 'body' => ['success' => false, 'error' => 'csrf', 'message' => __('api.csrf.invalid')]];
    }
    $uid = (int)($me['id'] ?? 0);
    if ($uid <= 0) return $fail(401, 'login_required');
    $raw = $input['bio'] ?? '';
    if (!is_string($raw)) return $fail(400, 'invalid');
    // Measured before anything is decoded or walked: a body of megabytes is refused for its size.
    if (strlen($raw) > PROFILE_BIO_RAW_BYTES) return $fail(413, 'too_long_source', ['max' => profileBioSourceCap($cfg)]);
    if (!mb_check_encoding($raw, 'UTF-8')) return $fail(400, 'bad_encoding');
    $clean = profileBioClean($raw);
    $clearing = $clean === '';
    if (!$clearing) {
        if (!profileBioEnabled($cfg)) return $fail(403, 'disabled');
        if (!userIdHasPermission($db, $cfg, $uid, 'profile.bio')) return $fail(403, 'no_permission');
        // Silenced by a moderator (the mute messages and the shoutbox already obey): a description is
        // words other people read, and a mute is about this account writing to them at all.
        $until = function_exists('pmMutedUntil') ? pmMutedUntil($me) : null;
        if ($until !== null) return $fail(403, 'muted', ['until' => $until]);
    }
    if (!rateLimitAllow('profile_bio', 'u' . $uid, PROFILE_BIO_RATE, 3600)) {
        $r = $fail(429, 'rate_limit');
        $r['body']['retry_after'] = 3600;
        return $r;
    }
    if (!$clearing) {
        $bad = profileBioProblem($clean, $cfg);
        if ($bad !== null) return $fail(400, $bad['code'], $bad['vars']);
    }
    profileBioStore($db, $uid, $clean);
    $p = $clearing ? ['html' => '', 'chars' => 0] : profileBioParse($clean, $cfg);
    return ['status' => 200, 'body' => [
        'success' => true,
        // The server's own HTML — the page puts exactly this in place and renders nothing itself.
        'html'    => $clearing ? '' : profileBioRender($clean, $cfg),
        'text'    => $clean,
        'chars'   => (int)$p['chars'],
        'max'     => profileBioMax($cfg),
        'message' => __($clearing ? 'api.bio.cleared' : 'api.bio.saved'),
    ]];
}
