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
function richtextCount(string $text): array {
    $imgs = preg_match_all('/\[img\b[^\]]*\]/i', $text) + preg_match_all('/!\[[^\]]*\]\(/', $text);
    $links = preg_match_all('/\[url\b[^\]]*\]/i', $text)
           + preg_match_all('/(?<!!)\[[^\]]*\]\(/', $text);
    return ['images' => (int)$imgs, 'links' => (int)$links];
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
    $c = richtextCount($text);
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

function richtextRender(?string $text, string $format, array $cfg): string {
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

    if ($format === 'bbcode') {
        $s = preg_replace('/\[b\](.*?)\[\/b\]/is', '<strong>$1</strong>', $s);
        $s = preg_replace('/\[i\](.*?)\[\/i\]/is', '<em>$1</em>', $s);
        $s = preg_replace('/\[u\](.*?)\[\/u\]/is', '<u>$1</u>', $s);
        $s = preg_replace('/\[s\](.*?)\[\/s\]/is', '<s>$1</s>', $s);
        $s = preg_replace('/\[quote\](.*?)\[\/quote\]/is', '<blockquote class="rt-quote">$1</blockquote>', $s);
        $s = preg_replace_callback('/\[img\](.*?)\[\/img\]/is', function ($m) {
            $u = richtextSafeUrl(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            return $u === null ? '' : '<img class="rt-img" loading="lazy" referrerpolicy="no-referrer" alt="" src="'
                                    . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '">';
        }, $s);
        $s = preg_replace_callback('/\[url=([^\]]+)\](.*?)\[\/url\]/is', function ($m) use ($cfg) {
            $a = richtextLinkAttrs(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $cfg);
            return $a === '' ? $m[2] : '<a' . $a . '>' . $m[2] . '</a>';
        }, $s);
        $s = preg_replace_callback('/\[url\](.*?)\[\/url\]/is', function ($m) use ($cfg) {
            $a = richtextLinkAttrs(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $cfg);
            return $a === '' ? $m[1] : '<a' . $a . '>' . $m[1] . '</a>';
        }, $s);
        // Lists: [list] with [*] items, the shape every forum user already knows.
        $s = preg_replace_callback('/\[list\](.*?)\[\/list\]/is', function ($m) {
            $items = preg_split('/\[\*\]/', $m[1]);
            array_shift($items);
            if (!$items) return '';
            $li = '';
            foreach ($items as $i) $li .= '<li>' . trim($i) . '</li>';
            return '<ul class="rt-list">' . $li . '</ul>';
        }, $s);
    } else {
        // Markdown, the small useful half of it.
        $s = preg_replace('/^######\s+(.+)$/m', '<h6 class="rt-h">$1</h6>', $s);
        $s = preg_replace('/^#{4,5}\s+(.+)$/m', '<h5 class="rt-h">$1</h5>', $s);
        $s = preg_replace('/^###\s+(.+)$/m', '<h5 class="rt-h">$1</h5>', $s);
        $s = preg_replace('/^##\s+(.+)$/m', '<h4 class="rt-h">$1</h4>', $s);
        $s = preg_replace('/^#\s+(.+)$/m', '<h4 class="rt-h">$1</h4>', $s);
        $s = preg_replace('/^&gt;\s?(.*)$/m', '<blockquote class="rt-quote">$1</blockquote>', $s);
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $s);
        $s = preg_replace('/~~(.+?)~~/s', '<s>$1</s>', $s);
        $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', function ($m) {
            $u = richtextSafeUrl(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            return $u === null ? '' : '<img class="rt-img" loading="lazy" referrerpolicy="no-referrer" alt="'
                                    . $m[1] . '" src="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '">';
        }, $s);
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) use ($cfg) {
            $a = richtextLinkAttrs(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), $cfg);
            return $a === '' ? $m[1] : '<a' . $a . '>' . $m[1] . '</a>';
        }, $s);
        $s = preg_replace_callback('/(?:^[-*+]\s+.+\n?)+/m', function ($m) {
            $li = '';
            foreach (preg_split('/\n/', trim($m[0])) as $line) {
                $li .= '<li>' . preg_replace('/^[-*+]\s+/', '', $line) . '</li>';
            }
            return '<ul class="rt-list">' . $li . '</ul>';
        }, $s);
    }

    // Bare URLs, in both syntaxes. Somebody who pastes a link expects it to be one.
    $s = preg_replace_callback('#(?<![">=\]])(https?://[^\s<]+)#i', function ($m) use ($cfg) {
        $a = richtextLinkAttrs(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $cfg);
        return $a === '' ? $m[1] : '<a' . $a . '>' . $m[1] . '</a>';
    }, $s);

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
    $blocks = 'ul|blockquote|pre|h[1-6]';
    $s = preg_replace('#<p>\s*(<(?:' . $blocks . ')\b)#', '$1', $s);
    $s = preg_replace('#(</(?:' . $blocks . ')>)\s*</p>#', '$1', $s);
    $s = preg_replace('#(</(?:' . $blocks . ')>)\s*(?:<br>\s*)+#', '$1', $s);
    $s = preg_replace('#(?:<br>\s*)+(<(?:' . $blocks . ')\b)#', '$1', $s);
    $s = preg_replace('#<p>\s*</p>#', '', $s);
    return $s;
}

/** A short plain-text version, for listings and meta descriptions. */
function richtextExcerpt(?string $text, int $len = 160): string {
    $s = trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)) ?? '');
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
