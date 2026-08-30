<?php
/**
 * Descriptions written by strangers, rendered without handing them the page.
 *
 * ── why this is not a library ───────────────────────────────────────────────
 *
 * Every general-purpose Markdown and BBCode parser is built to be permissive: they pass raw HTML
 * through by design, and the ones that filter it do so with a blacklist that has to be kept ahead of
 * whoever is trying. The text here comes from anonymous submitters on a public form. A blacklist is
 * the wrong shape for that problem.
 *
 * So: the input is escaped FIRST, in full, before a single rule runs. Everything after that operates
 * on text that already cannot be HTML, and the only tags in the output are the ones this file itself
 * writes. There is no path — not a clever nesting, not a broken tag, not an encoding trick — by which
 * a `<script>` in the input becomes a `<script>` in the output, because by the time any rule sees it
 * the angle brackets are already `&lt;`.
 *
 * ── what is allowed ────────────────────────────────────────────────────────
 *
 * Bold, italic, underline, strikethrough, inline code, code blocks, quotes, headings, lists, links,
 * images, and line breaks. That is the whole list. `[code]` matters most and is handled first,
 * because somebody pasting a config file is the main reason a description is worth having at all.
 *
 * Links and images go through the same URL check as the source-link field: http/https only, and a
 * `javascript:` in an `[img]` is not a clever attack, it is the first one anybody tries.
 */

/** Formats the site is willing to accept, from settings. Never empty — falls back to bbcode. */
function richtextFormats(array $cfg): array {
    $out = [];
    if (($cfg['desc_allow_bbcode'] ?? '1') === '1') $out[] = 'bbcode';
    if (($cfg['desc_allow_markdown'] ?? '1') === '1') $out[] = 'markdown';
    return $out ?: ['bbcode'];
}

function richtextMaxChars(array $cfg): int {
    return max(0, min(20000, (int)($cfg['desc_max_chars'] ?? 4000) ?: 4000));
}
function richtextMaxImages(array $cfg): int {
    return max(0, min(50, (int)($cfg['desc_max_images'] ?? 3)));
}
function richtextMaxLinks(array $cfg): int {
    return max(0, min(100, (int)($cfg['desc_max_links'] ?? 10)));
}

/**
 * Is this a URL we are willing to put in an href or a src?
 *
 * Deliberately strict, and deliberately the same rule for the source link, `[url]` and `[img]`.
 * A scheme allow-list is the only part that actually matters — everything else here is tidying.
 */
function richtextSafeUrl(string $url): ?string {
    $url = trim($url);
    if ($url === '' || strlen($url) > 500) return null;
    // Strip control characters first: "java\tscript:" is a real bypass against naive scheme checks.
    $url = preg_replace('/[\x00-\x20\x7F]/', '', $url);
    if (!preg_match('#^https?://#i', $url)) return null;
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') return null;
    return $url;
}

/**
 * Everything wrong with a source link, in the reader's terms, or null if it is fine.
 *
 * HTTPS is required rather than preferred. This link is shown to visitors of a site that exists to
 * be careful about what it points at; sending them somewhere over plain HTTP, from a page they
 * reached over TLS, is a downgrade the site would be choosing on their behalf.
 */
function richtextValidateSourceUrl(string $url, array $cfg): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if (strlen($url) > 500) return 'That link is too long (500 characters maximum).';
    if (preg_match('#^http://#i', $url)) {
        return 'That link is plain HTTP. Only https:// links are accepted — this page is served over '
             . 'TLS and must not send anyone to a downgraded one.';
    }
    if (!preg_match('#^https://#i', $url)) return 'A source link must start with https://';
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) return 'That does not look like a working link.';
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        return 'Remove the username and password from the link — they would be visible to everyone.';
    }
    $host = strtolower($parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return 'That link points at the machine reading it, not at a public page.';
    }
    // Literal private addresses. A NAME that resolves to one is not caught here on purpose: this is
    // a link a human will click, not something the server fetches, so DNS is not our business.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'That is a private or reserved address — nobody outside your own network could open it.';
        }
    }
    if (!str_contains($host, '.')) return 'That host name has no domain — did you mean a public address?';
    return null;
}

/** Domains whose links skip the "you are leaving" warning. */
function richtextTrustedDomains(array $cfg): array {
    $raw = (string)($cfg['link_trusted_domains'] ?? '');
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $d) {
        $d = strtolower(trim($d));
        if ($d === '') continue;
        if (preg_match('#^[a-z]+://#', $d)) $d = (string)(parse_url($d, PHP_URL_HOST) ?? '');
        $d = trim($d, " \t/.");
        if ($d !== '') $out[$d] = true;
    }
    return array_keys($out);
}

/** Is this link one the operator has said needs no warning? Subdomains of a trusted domain count. */
function richtextIsTrusted(string $url, array $cfg): bool {
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    if ($host === '') return false;
    foreach (richtextTrustedDomains($cfg) as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) return true;
    }
    return false;
}

/** How many images and links a body contains, for the limit check. Counts both syntaxes. */
function richtextCount(string $text, string $format = 'bbcode', ?array $cfg = null): array {
    // Counted from the RENDERED output, not from the source syntax.
    //
    // The old version looked for [img] and [url] and the two Markdown shapes, and therefore missed
    // every link the renderer creates by other means: a bare pasted URL, an [email], a [youtube], a
    // link inside a table cell or a list item. The limit it enforced was not the limit it claimed —
    // "at most 10 links" let a description through with fifty, because forty-odd of them were not
    // written in a syntax this function knew about.
    //
    // Counting the output cannot drift from what a visitor sees, because it IS what a visitor sees.
    // The cost is one render per validation, which happens on submit and on preview — both already
    // do a render.
    if ($cfg === null) {
        $cfg = ['desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1',
                'desc_max_chars' => '0', 'desc_max_images' => '999', 'desc_max_links' => '999'];
    }
    // signedIn = true: hidden content still counts against the limits. A description is not allowed
    // fifty extra images just because they are behind a [hide].
    $html = richtextRender($text, $format, $cfg, true);
    return [
        'images' => (int)preg_match_all('/<img\b/i', $html),
        'links'  => (int)preg_match_all('/<a\b/i', $html),
    ];
}

/** Everything wrong with a description, or null. */
function richtextValidate(string $text, string $format, array $cfg): ?string {
    $text = trim($text);
    if ($text === '') return null;
    $max = richtextMaxChars($cfg);
    if ($max > 0 && mb_strlen($text) > $max) {
        return 'That description is ' . mb_strlen($text) . ' characters; the limit is ' . $max . '.';
    }
    if (!in_array($format, richtextFormats($cfg), true)) {
        return 'That format is not accepted here.';
    }
    $c = richtextCount($text, $format, $cfg);
    $maxImg = richtextMaxImages($cfg);
    if ($c['images'] > $maxImg) {
        return $maxImg === 0
            ? 'Images are not allowed in descriptions here.'
            : 'That description has ' . $c['images'] . ' images; the limit is ' . $maxImg . '.';
    }
    $maxLink = richtextMaxLinks($cfg);
    if ($c['links'] > $maxLink) {
        return 'That description has ' . $c['links'] . ' links; the limit is ' . $maxLink . '.';
    }
    return null;
}

/* ── rendering ─────────────────────────────────────────────────────────────
 *
 * One rule for both syntaxes: escape everything, then build tags out of the escaped text. Code
 * spans are pulled out before anything else and put back at the very end, so that a description
 * explaining BBCode does not get its example silently rendered.
 */

/** Wrap a URL for output, marking off-site links so the page can warn before following them. */
function richtextLinkAttrs(string $url, array $cfg): string {
    $safe = richtextSafeUrl($url);
    if ($safe === null) return '';
    $attrs = ' href="' . htmlspecialchars($safe, ENT_QUOTES, 'UTF-8') . '"'
           . ' rel="nofollow noopener noreferrer ugc" target="_blank"';
    if (!richtextIsTrusted($safe, $cfg)) $attrs .= ' data-external="1"';
    return $attrs;
}

/**
 * The same HTML, but survivable in a mail client.
 *
 * Mail clients throw away <style> blocks and most of them throw away class attributes too, so the
 * panel's stylesheet is not available: `<pre class="rt-code">` arrives as an unstyled block and a
 * heading arrives as ordinary text. Rather than keep a second renderer in step with this one, the
 * markup is produced exactly as on the site and the handful of classes are then swapped for the
 * inline styles they stand for. If a rule changes on the site, the mail follows.
 *
 * `data-external` also goes: it drives the "you are leaving" dialog on the site, and there is no
 * dialog in an inbox.
 */
/**
 * The link an IMPORTER recorded, if it may be shown to the public.
 *
 * `whitelist.source_ref` is written by api/v1/whitelist_submit.php when the forum (or another API
 * client) posts a magnet, and it usually holds the URL of the post the magnet came from. That
 * answers the same question as the source link somebody types into the form, so it belongs in the
 * same place — but it has NOT been through the review queue, and an API client is not necessarily
 * run by the operator.
 *
 * So the rule is: publish it only when it points at this operator's own site. Anybody else's
 * importer can still record a link; that one stays visible to admins and waits for a moderator to
 * approve it as an ordinary source link. Returns null when there is nothing publishable.
 */
function richtextAutoSourceUrl(?string $sourceRefJson, array $cfg): ?string {
    if ($sourceRefJson === null || trim($sourceRefJson) === '') return null;
    $ref = json_decode($sourceRefJson, true);
    if (!is_array($ref)) return null;
    $url = isset($ref['url']) && is_string($ref['url']) ? trim($ref['url']) : '';
    if ($url === '') return null;
    if (richtextValidateSourceUrl($url, $cfg) !== null) return null;   // same rules as a typed one
    return richtextIsTrusted($url, $cfg) ? $url : null;
}

function richtextRenderForEmail(?string $text, string $format, array $cfg): string {
    $html = richtextRender($text, $format, $cfg);
    if ($html === '') return '';
    $style = [
        'rt-code'   => 'margin:0.6rem 0;padding:10px 12px;border-radius:4px;background:#f4f5f7;border:1px solid #dcdfe4;overflow-x:auto;font-family:Consolas,Menlo,monospace;font-size:13px;white-space:pre-wrap',
        'rt-inline' => 'padding:1px 5px;border-radius:3px;background:#f4f5f7;font-family:Consolas,Menlo,monospace;font-size:13px',
        'rt-quote'  => 'margin:10px 0;padding:6px 14px;border-left:3px solid #c9ced6;color:#555',
        'rt-list'   => 'margin:8px 0 10px 20px;padding:0',
        'rt-h'      => 'margin:14px 0 6px;font-weight:600',
        'rt-img'    => 'max-width:100%;height:auto;border-radius:4px;margin:8px 0',
    ];
    foreach ($style as $cls => $css) {
        $html = str_replace(' class="' . $cls . '"', ' style="' . $css . '"', $html);
    }
    return str_replace(' data-external="1"', '', $html);
}

/* ── sanitisers for the tags that carry a VALUE ──────────────────────────────
 *
 * Every tag below puts author-supplied text somewhere a browser reads as more than text: a colour
 * into a style attribute, a size into a length, a name into a font stack. Escaping is not enough
 * there — `color:red;background:url(...)` is perfectly valid escaped text and still an injection —
 * so each of these returns a value from a CLOSED set, or null. Nothing author-written is ever
 * concatenated into CSS unchecked.
 */

/** A CSS colour: #rgb / #rrggbb / #rrggbbaa, or one of a fixed list of names. Null otherwise. */
function richtextSafeColor(string $c): ?string {
    $c = strtolower(trim(html_entity_decode($c, ENT_QUOTES, 'UTF-8')));
    if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/', $c)) return $c;
    // A list, not a regex over "letters": `expression` and `url` are letters too, and CSS has a long
    // history of parsers that were happy to find something executable inside a word.
    static $named = [
        'black', 'silver', 'gray', 'grey', 'white', 'maroon', 'red', 'purple', 'fuchsia', 'green',
        'lime', 'olive', 'yellow', 'navy', 'blue', 'teal', 'aqua', 'cyan', 'magenta', 'orange',
        'pink', 'brown', 'gold', 'violet', 'indigo', 'coral', 'salmon', 'crimson', 'khaki',
        'lavender', 'turquoise', 'tan', 'beige', 'ivory', 'plum', 'orchid', 'tomato', 'skyblue',
        'lightblue', 'lightgreen', 'darkblue', 'darkgreen', 'darkred', 'transparent',
    ];
    return in_array($c, $named, true) ? $c : null;
}

/** A font size in pixels, clamped to something that cannot break a page. Null if not a number. */
function richtextSafeSize(string $v): ?int {
    $v = trim(html_entity_decode($v, ENT_QUOTES, 'UTF-8'));
    // Forums write both "18" (px) and "150%" — and phpBB writes 1..7. All three mean "bigger".
    if (preg_match('/^(\d{1,3})\s*%$/', $v, $m)) {
        $px = (int)round(16 * ((int)$m[1] / 100));
    } elseif (preg_match('/^(\d{1,4})$/', $v, $m)) {
        $n = (int)$m[1];
        $px = $n <= 7 ? [1 => 10, 2 => 12, 3 => 14, 4 => 16, 5 => 20, 6 => 24, 7 => 30][$n] : $n;
    } else {
        return null;
    }
    return max(9, min(32, $px));
}

/**
 * A font family, mapped onto one of three CLASSES rather than echoed into a style attribute.
 *
 * The author's own string never reaches the page. A font name is the easiest of these values to get
 * wrong — it is quoted text inside a property that accepts a comma-separated list — and no
 * description needs the difference between Courier New and Consolas badly enough to justify putting
 * arbitrary text in a `font-family`.
 */
function richtextSafeFont(string $name): ?string {
    $n = strtolower(trim(html_entity_decode($name, ENT_QUOTES, 'UTF-8')));
    static $map = [
        'courier' => 'mono', 'courier new' => 'mono', 'consolas' => 'mono', 'monaco' => 'mono',
        'menlo' => 'mono', 'monospace' => 'mono', 'lucida console' => 'mono',
        'times' => 'serif', 'times new roman' => 'serif', 'georgia' => 'serif', 'garamond' => 'serif',
        'serif' => 'serif', 'palatino' => 'serif', 'book antiqua' => 'serif',
        'arial' => 'sans', 'helvetica' => 'sans', 'verdana' => 'sans', 'tahoma' => 'sans',
        'segoe ui' => 'sans', 'sans-serif' => 'sans', 'trebuchet ms' => 'sans', 'calibri' => 'sans',
    ];
    return $map[$n] ?? null;
}

/** A YouTube video id, which is a fixed alphabet and a fixed length. Accepts a full URL too. */
function richtextYoutubeId(string $v): ?string {
    $v = trim(html_entity_decode($v, ENT_QUOTES, 'UTF-8'));
    if (preg_match('~(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_-]{11})~', $v, $m)) return $m[1];
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $v) ? $v : null;
}

/** An email address, or null. Used for [email] — a mailto: is still a link somebody will click. */
function richtextSafeEmail(string $v): ?string {
    $v = trim(html_entity_decode($v, ENT_QUOTES, 'UTF-8'));
    if (strlen($v) > 190) return null;
    return filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : null;
}

/** The emoji shortcodes worth having. A fixed map, so `:anything:` cannot become anything. */
function richtextEmoji(): array {
    return [
        'rocket' => '🚀', 'thumbsup' => '👍', 'thumbsdown' => '👎', 'warning' => '⚠️',
        'check' => '✅', 'x' => '❌', 'fire' => '🔥', 'star' => '⭐', 'bulb' => '💡',
        'lock' => '🔒', 'unlock' => '🔓', 'eyes' => '👀', 'tada' => '🎉', 'zap' => '⚡',
        'bug' => '🐛', 'wrench' => '🔧', 'package' => '📦', 'book' => '📖', 'link' => '🔗',
        'question' => '❓', 'exclamation' => '❗', 'heart' => '❤️', 'smile' => '🙂',
        'cry' => '😢', 'thinking' => '🤔', 'clap' => '👏', 'ok' => '👌', 'point_right' => '👉',
    ];
}

/**
 * Turn author text into HTML.
 *
 * $signedIn decides what [hide] does, and only that. It defaults to FALSE because the safe reading of
 * "I do not know who is looking" is "assume a guest": hidden content is then removed on the server
 * rather than delivered and hidden with CSS, which is a hint rather than a rule.
 */
function richtextRender(?string $text, string $format, array $cfg, bool $signedIn = false): string {
    $text = (string)$text;
    if (trim($text) === '') return '';

    // Everything is escaped before a single rule runs. This one line is the whole security model.
    $s = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = str_replace(["\r\n", "\r"], "\n", $s);

    // Code first, and out of the way. Somebody pasting a config file is the main reason to have
    // descriptions at all, and a [b] inside their paste is theirs, not markup.
    $stash = [];
    $keep = function (string $html) use (&$stash): string {
        $stash[] = $html;
        return "\x00CODE" . (count($stash) - 1) . "\x00";
    };
    if ($format === 'bbcode') {
        $s = preg_replace_callback('/\[code\](.*?)\[\/code\]/is',
            fn($m) => $keep('<pre class="rt-code"><code>' . trim($m[1], "\n") . '</code></pre>'), $s);
    } else {
        $s = preg_replace_callback('/```[a-z0-9+#-]*\n?(.*?)```/is',
            fn($m) => $keep('<pre class="rt-code"><code>' . trim($m[1], "\n") . '</code></pre>'), $s);
        $s = preg_replace_callback('/`([^`\n]+)`/',
            fn($m) => $keep('<code class="rt-inline">' . $m[1] . '</code>'), $s);
    }

    // ── emoji shortcodes ──
    //
    // FIRST, before any rule builds a URL or an attribute. Running this at the end rewrote text that
    // had already been validated and escaped: a link whose href passed richtextSafeUrl() could still
    // be mutated afterwards by a shortcode inside it. Here the string is escaped plain text with no
    // markup in it, so whatever a shortcode becomes is validated like anything else the author typed.
    // The map is fixed, so `:anything:` can only ever become one of these.
    $s = preg_replace_callback('/:([a-z0-9_+-]{1,24}):/', function ($m) {
        $e = richtextEmoji();
        return $e[$m[1]] ?? $m[0];
    }, $s);

    if ($format === 'bbcode') {
        // ── inline emphasis ──
        $s = preg_replace('/\[b\](.*?)\[\/b\]/is', '<strong>$1</strong>', $s);
        $s = preg_replace('/\[i\](.*?)\[\/i\]/is', '<em>$1</em>', $s);
        $s = preg_replace('/\[u\](.*?)\[\/u\]/is', '<u>$1</u>', $s);
        $s = preg_replace('/\[s\](.*?)\[\/s\]/is', '<s>$1</s>', $s);
        $s = preg_replace('/\[sub\](.*?)\[\/sub\]/is', '<sub>$1</sub>', $s);
        $s = preg_replace('/\[sup\](.*?)\[\/sup\]/is', '<sup>$1</sup>', $s);

        // ── the tags that carry a value: colour, size, font, highlight ──
        // Each one drops to plain text when the value is not in its closed set, rather than emitting
        // a tag with something unchecked in a style attribute.
        $s = preg_replace_callback('/\[color=([^\]]{1,32})\](.*?)\[\/color\]/is', function ($m) {
            $c = richtextSafeColor($m[1]);
            return $c === null ? $m[2] : '<span class="rt-color" style="color:' . $c . '">' . $m[2] . '</span>';
        }, $s);
        $s = preg_replace_callback('/\[size=([^\]]{1,12})\](.*?)\[\/size\]/is', function ($m) {
            $px = richtextSafeSize($m[1]);
            return $px === null ? $m[2] : '<span class="rt-size" style="font-size:' . $px . 'px">' . $m[2] . '</span>';
        }, $s);
        $s = preg_replace_callback('/\[font=([^\]]{1,40})\](.*?)\[\/font\]/is', function ($m) {
            $f = richtextSafeFont($m[1]);
            return $f === null ? $m[2] : '<span class="rt-font-' . $f . '">' . $m[2] . '</span>';
        }, $s);
        $s = preg_replace_callback('/\[(?:highlight|mark)(?:=([^\]]{1,32}))?\](.*?)\[\/(?:highlight|mark)\]/is', function ($m) {
            $c = ($m[1] ?? '') !== '' ? richtextSafeColor($m[1]) : null;
            return '<mark class="rt-mark"' . ($c !== null ? ' style="background:' . $c . '"' : '') . '>' . $m[2] . '</mark>';
        }, $s);

        // ── alignment and rules ──
        foreach (['center', 'right', 'left'] as $al) {
            $s = preg_replace('/\[' . $al . '\](.*?)\[\/' . $al . '\]/is', '<div class="rt-' . $al . '">$1</div>', $s);
        }
        $s = preg_replace('/\[hr\]|\[hr\s*\/\]/i', '<hr class="rt-hr">', $s);

        // ── quotes, with or without an author ──
        // Longest form first: [quote=x] would otherwise be eaten by the bare [quote] pattern.
        $s = preg_replace_callback('/\[quote=(?:&quot;|&#039;)?(.{1,64}?)(?:&quot;|&#039;)?\](.*?)\[\/quote\]/is',
            fn($m) => '<blockquote class="rt-quote"><cite class="rt-cite">' . trim($m[1]) . '</cite>' . $m[2] . '</blockquote>', $s);
        $s = preg_replace('/\[quote\](.*?)\[\/quote\]/is', '<blockquote class="rt-quote">$1</blockquote>', $s);

        // ── spoilers ──
        $s = preg_replace_callback('/\[spoiler=(?:&quot;|&#039;)?(.{1,80}?)(?:&quot;|&#039;)?\](.*?)\[\/spoiler\]/is',
            fn($m) => '<details class="rt-spoiler"><summary>' . trim($m[1]) . '</summary><div class="rt-spoiler-body">' . $m[2] . '</div></details>', $s);
        $s = preg_replace('/\[spoiler\](.*?)\[\/spoiler\]/is',
            '<details class="rt-spoiler"><summary>Spoiler</summary><div class="rt-spoiler-body">$1</div></details>', $s);

        // ── media and links ──
        $s = preg_replace_callback('/\[img(?:=[^\]]*)?\](.*?)\[\/img\]/is', function ($m) {
            $u = richtextSafeUrl(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            return $u === null ? '' : '<img class="rt-img" loading="lazy" referrerpolicy="no-referrer" alt="" src="'
                                    . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '">';
        }, $s);
        // No iframe. An embedded player is a third-party frame that sees every visitor, and this
        // site's own CSP forbids frame sources anyway — an <iframe> here would render as a blank box
        // and quietly lie about working. A labelled link does what the author meant.
        $s = preg_replace_callback('/\[(?:youtube|yt)\](.*?)\[\/(?:youtube|yt)\]/is', function ($m) use ($cfg) {
            $id = richtextYoutubeId($m[1]);
            if ($id === null) return $m[1];
            $url = 'https://www.youtube.com/watch?v=' . $id;
            $a = richtextLinkAttrs($url, $cfg);
            return $a === '' ? $url : '<a class="rt-video"' . $a . '>▶ YouTube: ' . $id . '</a>';
        }, $s);
        $s = preg_replace_callback('/\[email=([^\]]{1,190})\](.*?)\[\/email\]/is', function ($m) {
            $e = richtextSafeEmail($m[1]);
            return $e === null ? $m[2] : '<a href="mailto:' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8')
                                       . '" rel="nofollow noopener">' . $m[2] . '</a>';
        }, $s);
        $s = preg_replace_callback('/\[email\](.*?)\[\/email\]/is', function ($m) {
            $e = richtextSafeEmail($m[1]);
            return $e === null ? $m[1] : '<a href="mailto:' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8')
                                       . '" rel="nofollow noopener">' . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . '</a>';
        }, $s);
        $s = preg_replace_callback('/\[url=([^\]]+)\](.*?)\[\/url\]/is', function ($m) use ($cfg) {
            $a = richtextLinkAttrs(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $cfg);
            return $a === '' ? $m[2] : '<a' . $a . '>' . $m[2] . '</a>';
        }, $s);
        $s = preg_replace_callback('/\[url\](.*?)\[\/url\]/is', function ($m) use ($cfg) {
            $a = richtextLinkAttrs(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $cfg);
            return $a === '' ? $m[1] : '<a' . $a . '>' . $m[1] . '</a>';
        }, $s);

        // ── lists, ordered and not ──
        // [list=1] / [list=a] give an <ol>; the type is a class, because `type=` from author text is
        // one more attribute nobody needs to be able to write.
        $s = preg_replace_callback('/\[list=([^\]]{1,3})\](.*?)\[\/list\]/is', function ($m) {
            $items = preg_split('/\[\*\]/', $m[2]);
            array_shift($items);
            if (!$items) return '';
            $kind = preg_match('/^[aA]$/', trim($m[1])) ? 'alpha' : (preg_match('/^[iI]$/', trim($m[1])) ? 'roman' : 'num');
            $li = '';
            foreach ($items as $i) $li .= '<li>' . trim($i) . '</li>';
            return '<ol class="rt-list rt-list-' . $kind . '">' . $li . '</ol>';
        }, $s);
        $s = preg_replace_callback('/\[list\](.*?)\[\/list\]/is', function ($m) {
            $items = preg_split('/\[\*\]/', $m[1]);
            array_shift($items);
            if (!$items) return '';
            $li = '';
            foreach ($items as $i) $li .= '<li>' . trim($i) . '</li>';
            return '<ul class="rt-list">' . $li . '</ul>';
        }, $s);

        // ── tables ──
        // Built by walking the rows, not by rewriting each tag on its own. Turning [tr] into <tr>
        // with a regex means an author who opens a row and never closes it emits a stray tag into
        // the page; here anything that is not a well-formed row simply does not become one.
        $s = preg_replace_callback('/\[table(?:=[^\]]*)?\](.*?)\[\/table\]/is', function ($m) {
            $rows = '';
            if (preg_match_all('/\[tr\](.*?)\[\/tr\]/is', $m[1], $rm)) {
                foreach ($rm[1] as $rowSrc) {
                    $cells = '';
                    if (preg_match_all('/\[(th|td)\](.*?)\[\/\1\]/is', $rowSrc, $cm, PREG_SET_ORDER)) {
                        foreach ($cm as $c) {
                            $tag = strtolower($c[1]) === 'th' ? 'th' : 'td';
                            $cells .= '<' . $tag . '>' . trim($c[2]) . '</' . $tag . '>';
                        }
                    }
                    if ($cells !== '') $rows .= '<tr>' . $cells . '</tr>';
                }
            }
            return $rows === '' ? '' : '<div class="rt-table-wrap"><table class="rt-table">' . $rows . '</table></div>';
        }, $s);

        // ── what we deliberately do not do ──
        // [attach] needs an attachment store this project does not have. Left as plain text rather
        // than silently deleted, so an author pasting a template from another forum can see why
        // nothing appeared.
    } else {
        // Markdown, the small useful half of it plus the GitHub-flavoured extensions people expect.

        // ── tables, before anything that touches line starts ──
        // A pipe table is recognised by its SEPARATOR row; without one, a paragraph containing pipes
        // is just a paragraph, which is what stops an innocent line becoming a one-cell table.
        $s = preg_replace_callback('/^(\|.+\|)\n(\|[\s:|-]+\|)\n((?:\|.*\|\n?)*)/m', function ($m) {
            $cells = function (string $line): array {
                $line = trim($line);
                $line = preg_replace('/^\||\|$/', '', $line);
                return array_map('trim', explode('|', $line));
            };
            $align = [];
            foreach ($cells($m[2]) as $spec) {
                $l = str_starts_with($spec, ':');
                $r = str_ends_with($spec, ':');
                $align[] = $l && $r ? 'center' : ($r ? 'right' : ($l ? 'left' : ''));
            }
            $td = function (array $vals, string $tag) use ($align): string {
                $out = '';
                foreach ($vals as $i => $v) {
                    $a = $align[$i] ?? '';
                    $out .= '<' . $tag . ($a !== '' ? ' class="rt-t-' . $a . '"' : '') . '>' . $v . '</' . $tag . '>';
                }
                return '<tr>' . $out . '</tr>';
            };
            $html = $td($cells($m[1]), 'th');
            foreach (preg_split('/\n/', trim($m[3])) as $row) {
                if (trim($row) === '') continue;
                $html .= $td($cells($row), 'td');
            }
            return '<div class="rt-table-wrap"><table class="rt-table">' . $html . '</table></div>' . "\n";
        }, $s);

        // ── admonitions: > [!NOTE] and friends, before the plain blockquote rule ──
        $s = preg_replace_callback('/(?:^&gt;\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*\n?(?:^&gt;.*\n?)*)/mi', function ($m) {
            $kind = strtolower($m[1]);
            $body = preg_replace('/^&gt;\s*(?:\[![A-Z]+\]\s*)?/mi', '', trim($m[0]));
            return '<div class="rt-callout rt-callout-' . $kind . '"><div class="rt-callout-k">'
                 . ucfirst($kind) . '</div>' . trim($body) . '</div>' . "\n";
        }, $s);

        $s = preg_replace('/^######\s+(.+)$/m', '<h6 class="rt-h">$1</h6>', $s);
        $s = preg_replace('/^#{4,5}\s+(.+)$/m', '<h5 class="rt-h">$1</h5>', $s);
        $s = preg_replace('/^###\s+(.+)$/m', '<h5 class="rt-h">$1</h5>', $s);
        $s = preg_replace('/^##\s+(.+)$/m', '<h4 class="rt-h">$1</h4>', $s);
        $s = preg_replace('/^#\s+(.+)$/m', '<h4 class="rt-h">$1</h4>', $s);
        $s = preg_replace('/^&gt;\s?(.*)$/m', '<blockquote class="rt-quote">$1</blockquote>', $s);
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $s);
        $s = preg_replace('/~~(.+?)~~/s', '<s>$1</s>', $s);
        // == before = anything else; ~~ above already consumed the strikethrough, so a single ~ left
        // over is a subscript rather than a broken strikethrough.
        $s = preg_replace('/==(.+?)==/s', '<mark class="rt-mark">$1</mark>', $s);
        $s = preg_replace('/(?<!~)~([^~\s][^~\n]*)~(?!~)/', '<sub>$1</sub>', $s);
        $s = preg_replace('/\^([^\^\s][^\^\n]*)\^/', '<sup>$1</sup>', $s);
        $s = preg_replace('/\|\|(.+?)\|\|/s',
            '<details class="rt-spoiler"><summary>Spoiler</summary><div class="rt-spoiler-body">$1</div></details>', $s);
        $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
            $u = richtextSafeUrl(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            return $u === null ? '' : '<img class="rt-img" loading="lazy" referrerpolicy="no-referrer" alt="'
                                    . $m[1] . '" src="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '">';
        }, $s);
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) use ($cfg) {
            $a = richtextLinkAttrs(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), $cfg);
            return $a === '' ? $m[1] : '<a' . $a . '>' . $m[1] . '</a>';
        }, $s);
        // Bullets, including task lists. The checkbox is rendered as a glyph, not an <input>: a
        // description is not a form, and a disabled input in the middle of prose is a control that
        // looks broken rather than a mark that looks ticked.
        $s = preg_replace_callback('/(?:^[-*+]\s+.+\n?)+/m', function ($m) {
            $li = '';
            $task = false;
            foreach (preg_split('/\n/', trim($m[0])) as $line) {
                $item = preg_replace('/^[-*+]\s+/', '', $line);
                if (preg_match('/^\[( |x|X)\]\s*(.*)$/', $item, $t)) {
                    $task = true;
                    $done = strtolower($t[1]) === 'x';
                    $li .= '<li class="rt-task' . ($done ? ' rt-task-done' : '') . '">'
                         . '<span class="rt-task-box" aria-hidden="true">' . ($done ? '☑' : '☐') . '</span> '
                         . $t[2] . '</li>';
                } else {
                    $li .= '<li>' . $item . '</li>';
                }
            }
            return '<ul class="rt-list' . ($task ? ' rt-list-task' : '') . '">' . $li . '</ul>';
        }, $s);
        // Numbered lists. The author's own numbers are ignored on purpose — an <ol> renumbers itself,
        // and honouring "1. 1. 1." would produce a list that argues with its own markup.
        $s = preg_replace_callback('/(?:^\d{1,3}[.)]\s+.+\n?)+/m', function ($m) {
            $li = '';
            foreach (preg_split('/\n/', trim($m[0])) as $line) {
                $li .= '<li>' . preg_replace('/^\d{1,3}[.)]\s+/', '', $line) . '</li>';
            }
            return '<ol class="rt-list rt-list-num">' . $li . '</ol>';
        }, $s);

        // ── footnotes ──
        // Definitions are collected first and removed from the flow, then the references become
        // links to them. A reference with no definition stays as written rather than becoming a link
        // to nowhere.
        $notes = [];
        $s = preg_replace_callback('/^\[\^([^\]]{1,32})\]:\s*(.+)$/m', function ($m) use (&$notes) {
            $notes[$m[1]] = trim($m[2]);
            return '';
        }, $s);
        if ($notes) {
            $order = [];
            $s = preg_replace_callback('/\[\^([^\]]{1,32})\]/', function ($m) use ($notes, &$order) {
                if (!isset($notes[$m[1]])) return $m[0];
                if (!in_array($m[1], $order, true)) $order[] = $m[1];
                $n = array_search($m[1], $order, true) + 1;
                $id = preg_replace('/[^a-z0-9]+/i', '-', $m[1]);
                return '<sup class="rt-fn"><a href="#fn-' . $id . '">' . $n . '</a></sup>';
            }, $s);
            if ($order) {
                $list = '';
                foreach ($order as $i => $key) {
                    $id = preg_replace('/[^a-z0-9]+/i', '-', $key);
                    $list .= '<li id="fn-' . $id . '">' . $notes[$key] . '</li>';
                }
                $s = rtrim($s) . "\n\n" . '<ol class="rt-list rt-footnotes">' . $list . '</ol>';
            }
        }

        // ── the handful of literal HTML tags worth allowing ──
        // Un-escaping is done from an exact list of complete strings, with no attributes anywhere in
        // it. That is the only shape in which this is safe: the moment a rule accepts "a tag name
        // followed by something", it accepts an event handler too.
        // A PAIR at a time, never an opener and a closer independently. Replacing them separately
        // let `<kbd onclick="x">y</kbd>` through as escaped text plus a real, unmatched `</kbd>` —
        // harmless in itself, but an unbalanced tag is how a renderer starts closing elements the
        // author never opened. Requiring the pair means the only thing this can emit is exactly the
        // tag it names, with nothing between the angle brackets.
        $allow = ['kbd', 'mark', 'ins', 'sub', 'sup', 'small', 'abbr'];
        foreach ($allow as $tag) {
            $s = preg_replace('/&lt;' . $tag . '&gt;(.*?)&lt;\/' . $tag . '&gt;/is',
                              '<' . $tag . '>$1</' . $tag . '>', $s);
        }
    }



    // ── content only signed-in visitors may read ──
    //
    // Removed on the SERVER, not hidden with CSS. "Hidden from guests" has to mean the bytes
    // never leave the building; a display:none block is a hint, not a rule.
    //
    // The first version of this was a single lazy match with an alternation on the closing tag,
    // and it FAILED OPEN. `[hide]a[hide]b[/hide] SECRET[/hide]` paired the outer opener with the
    // INNER closer, so everything after it — SECRET — fell outside the match and was printed to
    // guests verbatim, next to a placeholder claiming the content was hidden. The same happened
    // with a stray `[/hide]`, with a `[/postshide]` closing a `[hide]`, and when a `[code]` block
    // swallowed the real closing tag into the code stash. Every one of those is an ordinary
    // authoring mistake, and the person who loses the secret is the author who most wanted it
    // kept — which is the worst possible direction for this particular rule to fail in.
    //
    // So: innermost-outward, closer must match its own opener, and anything left unbalanced is
    // treated as unreadable rather than published. Guessing wrong here costs a secret; guessing
    // wrong the other way costs a paragraph.
    $hideTag = '/\[(hide|postshide)(?:=[^\]]*)?\]((?:(?!\[(?:hide|postshide)[\]=])[\s\S])*?)\[\/\1\]/i';
    for ($pass = 0; $pass < 16; $pass++) {
        $before = $s;
        $s = preg_replace_callback($hideTag,
            fn($m) => $signedIn ? '<div class="rt-hide">' . $m[2] . '</div>'
                                : '<div class="rt-hide rt-hide-locked">Hidden — sign in to read this part.</div>', $s);
        if ($s === $before) break;
    }
    // Anything still carrying a hide token is unbalanced, and for a guest the WHOLE description
    // is then withheld — not merely the part after the token.
    //
    // Truncating at the token was the obvious thing and it was still wrong: in
    // `[hide]a[/hide] SECRET[/hide]` the leftover is a stray CLOSER and the secret sits BEFORE
    // it. A stray closer says the author believed they were inside a hidden block; a stray
    // opener says they believed they were entering one. Either way the fences no longer say
    // where the private part begins, and there is no reading of a broken fence that is safe to
    // publish. So nothing is published. The author is signed in and still sees their own text,
    // which is exactly who needs to notice and fix it.
    if (!$signedIn && preg_match('/\[\/?(?:hide|postshide)\b/i', $s)) {
        $s = '<div class="rt-hide rt-hide-locked">This description uses a hidden block whose tags '
           . 'are not balanced, so none of it is shown. Sign in, or ask the author to fix it.</div>';
    }

    // Bare URLs, in both syntaxes. Somebody who pastes a link expects it to be one.
    //
    // The links and images already built above are put out of reach first, rather than trying to
    // recognise them with a lookbehind. The lookbehind was `(?<![">=\]])`, and it did keep the rule
    // out of an existing href — but it also refused any URL whose preceding character happened to be
    // `>`, which is to say every URL that came straight after a tag. `[hide]https://example.org[/hide]`
    // rendered its first link as plain text for that reason, and the link counter (which counts the
    // OUTPUT) then reported one link fewer than the description really had.
    $anchors = [];
    $s = preg_replace_callback('#<(?:a\b[^>]*>.*?</a>|img\b[^>]*>)#is', function ($m) use (&$anchors) {
        $anchors[] = $m[0];
        return "\x00LINK" . (count($anchors) - 1) . "\x00";
    }, $s);
    $s = preg_replace_callback('#(https?://[^\s<]+)#i', function ($m) use ($cfg) {
        $a = richtextLinkAttrs(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $cfg);
        return $a === '' ? $m[1] : '<a' . $a . '>' . $m[1] . '</a>';
    }, $s);
    foreach ($anchors as $i => $html) {
        $s = str_replace("\x00LINK" . $i . "\x00", $html, $s);
    }

    // Paragraphs and breaks, last, so block tags above are not wrapped in <br>.
    $s = preg_replace('/\n{2,}/', "</p><p>", $s);
    $s = str_replace("\n", '<br>', $s);
    $s = '<p>' . $s . '</p>';

    // Put the code back BEFORE tidying blocks. The stash placeholders are opaque text while the
    // rules run -- which is the point -- but that also means the paragraph tidy-up cannot see that a
    // placeholder is really a <pre>, and would leave it wrapped in a <p> it is not allowed inside.
    foreach ($stash as $i => $html) {
        $s = str_replace("\x00CODE" . $i . "\x00", $html, $s);
    }

    // A block element may not sit inside a paragraph, and a <br> on either side of one is a gap the
    // author never asked for.
    // Every block-level tag this renderer can emit. A tag missing from this list ends up inside a
    // <p>, which browsers close early — producing an empty paragraph and a gap the author never
    // wrote. It bit the table, the alignment divs and the spoiler the moment they were added.
    $blocks = 'ul|ol|blockquote|pre|h[1-6]|div|table|details|hr';
    // A block element in the MIDDLE of a paragraph needs the paragraph split around it, not just
    // trimmed at its edges. `<p>a <div>b</div> c</p>` is invalid, and a browser does not render it as
    // written: it closes the <p> before the <div> and leaves the tail orphaned, which shows up as a
    // gap nobody typed. The rules below only ever handled a block at the START or the END.
    $s = preg_replace('#(<(?:' . $blocks . ')\b)#', '</p>$1', $s);
    $s = preg_replace('#(</(?:' . $blocks . ')>)#', '$1<p>', $s);
    $s = preg_replace('#(<hr class="rt-hr">)#', '$1<p>', $s);   // void: it has no closing tag to hook on

    $s = preg_replace('#<p>\s*(<(?:' . $blocks . ')\b)#', '$1', $s);
    $s = preg_replace('#(</(?:' . $blocks . ')>)\s*</p>#', '$1', $s);
    $s = preg_replace('#(</(?:' . $blocks . ')>)\s*(?:<br>\s*)+#', '$1', $s);
    $s = preg_replace('#(?:<br>\s*)+(<(?:' . $blocks . ')\b)#', '$1', $s);
    $s = preg_replace('#(<hr class="rt-hr">)\s*(?:<br>\s*)+#', '$1', $s);
    $s = preg_replace('#(?:<br>\s*)+(<hr class="rt-hr">)#', '$1', $s);
    $s = preg_replace('#<p>\s*</p>#', '', $s);

    // Drop UNMATCHED </p>. The rules above remove an opening <p> whenever a block element follows
    // it, and that leaves the closing tag behind — a paragraph that starts with a table produces a
    // stray </p> after it. Walking the tags is duller than another regex and cannot surprise anyone:
    // a </p> with no <p> open is simply not written out.
    $depth = 0;
    $s = preg_replace_callback('#</?p>#', function ($m) use (&$depth) {
        if ($m[0] === '<p>') { $depth++; return '<p>'; }
        if ($depth <= 0) return '';
        $depth--;
        return '</p>';
    }, $s);
    if ($depth > 0) $s .= str_repeat('</p>', $depth);
    return $s;
}

/** A short plain-text version, for listings and meta descriptions. */
function richtextExcerpt(?string $text, int $len = 160): string {
    $s = (string)$text;
    // Hidden blocks come out FIRST, body and all. The old version stripped BBCode tags generically
    // and kept whatever was between them, so an excerpt shown in a listing or a meta description
    // published exactly the text the author had marked as members-only — the tags disappeared and
    // the secret stayed. Anything unbalanced drops the rest of the string for the same reason the
    // renderer withholds it.
    $s = preg_replace('/\[(hide|postshide)(?:=[^\]]*)?\][\s\S]*?\[\/\1\]/i', ' ', $s) ?? $s;
    if (preg_match('/\[\/?(?:hide|postshide)\b/i', $s, $m, PREG_OFFSET_CAPTURE)) {
        $s = substr($s, 0, $m[0][1]);
    }
    $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)) ?? '');
    $s = preg_replace('/\[\/?[a-z][^\]]*\]/i', '', $s) ?? $s;
    $s = preg_replace('/[*_`#>~]+/', '', $s) ?? $s;
    $s = trim($s);
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
}

/**
 * The source link and description for one hash, ready to display.
 *
 * Used by both admin detail panels and the public Info panel, so that what a moderator approves and
 * what a visitor sees are produced by the same code. `$asAdmin` is the only difference: an
 * administrator sees text that is still waiting or was rejected, marked as such, because reviewing
 * something you cannot look at is not reviewing.
 */
function richtextContentFor(PDO $db, array $cfg, string $hash, bool $asAdmin = false): array {
    $out = ['source_url' => null, 'source_trusted' => false, 'description_html' => '',
            'content_status' => 'none', 'format' => 'bbcode', 'rejected_note' => null];
    $st = $db->prepare("SELECT source_url, description, description_format, content_status,
                               content_rejected_note
                          FROM whitelist WHERE info_hash = ? LIMIT 1");
    $st->execute([strtolower($hash)]);
    $r = $st->fetch();
    if (!$r) return $out;

    $status = (string)($r['content_status'] ?? 'none');
    $out['content_status'] = $status;
    $out['format'] = (string)($r['description_format'] ?? 'bbcode');
    $out['rejected_note'] = $r['content_rejected_note'] ?? null;
    if (!$asAdmin && $status !== 'approved') return $out;   // pending or rejected is not public

    $out['source_url'] = $r['source_url'] ?: null;
    $out['source_trusted'] = $out['source_url'] ? richtextIsTrusted((string)$out['source_url'], $cfg) : false;
    $out['description_html'] = richtextRender($r['description'] ?? '', $out['format'], $cfg);
    return $out;
}
