<?php
/**
 * HTML written by an administrator, made safe to hand to a member (1.75.0).
 *
 * ── why this exists, and why it is not a library ────────────────────────────────────────────────
 *
 * A campaign — the owner's word — is HTML somebody designed elsewhere: tables for layout, inline colours,
 * an image or two. Until now the site had BBCode, Markdown and plain text, all of which are ESCAPED FIRST and
 * rebuilt from the escaped text (includes/richtext.php), so the question "what may HTML do" never came up. Here
 * it has to: the markup is the input. The project does not take libraries for this kind of job
 * (richtext.php:5-26), and a general sanitizer is a blacklist that must be kept ahead of whoever is trying.
 * This one is the other shape: PARSE the HTML the way a browser does, WALK the tree, and WRITE a new string from
 * nothing but what this file itself decided to keep. Nothing of the input's bytes is ever copied through.
 *
 * ── the rules (the audit of plan 1.75.0, B1) ────────────────────────────────────────────────────
 *
 *  * Parser. `Dom\HTMLDocument::createFromString()` where PHP has it (8.4+): an HTML5 parser that builds the
 *    tree a browser builds, so what we judge is what a browser would have. Older PHP: DOMDocument::loadHTML()
 *    (libxml2's HTML4 parser) with the input scrubbed to valid UTF-8, NUL taken out, every non-ASCII character
 *    written as a numeric entity (no charset guessing), LIBXML_NONET, and NO closing wrapper — a wrapper closed
 *    after the input was swallowed by an attribute left open in it, in the prototype.
 *  * The output is built from OUR walk, never saveHTML(): element and text nodes only (no comment, no CDATA, no
 *    processing instruction), the tag read from the node's name and looked up in a table, every text and every
 *    attribute value written through htmlspecialchars(ENT_QUOTES). The DOM-decoded value is what is judged.
 *  * DEFAULT DENY. An element that is in no list is dropped WITH its content. A short list of harmless wrappers
 *    (section, font, code …) is UNWRAPPED — the tag goes, its children stay. The dangerous ones (script, style,
 *    iframe, svg, math, template, noscript, form, …) are named below as well, though the default would drop them
 *    anyway: the list is documentation and a test target, not the security.
 *  * Attributes: an exact, lower-case allow-list per element. Everything else — every on*, id, class, name,
 *    srcset, formaction, xmlns, xlink:href, xml:*, data-* — is gone.
 *  * URLs: the DOM-decoded value read the way a browser reads an address — control characters and the tab / line
 *    feed / carriage return deleted (java<TAB>script: is a real bypass; browsers delete those three), a space and
 *    the other blanks inside it written as their percent-encoding (a mailto: subject keeps its words) — then
 *    ^https?:// or mailto: — no relative and no protocol-relative address; an <img> takes https only.
 *  * CSS: not a blacklist of bad words but a per-PROPERTY VALUE allow-list — lengths, colours, keywords — so
 *    url(), image-set(), var(), env(), attr(), expression(), \escapes and @-rules have nothing to match.
 *    `!important` is stripped. For the site (not a mail) a font-size is clamped, a negative margin and
 *    display:none are refused.
 *  * Bounded: 60 000 bytes in, 64 levels deep, 128 KB out. The levels are the ones an author draws: the <tbody>
 *    the HTML5 parser puts into every table and the <tr> are not counted (a layout table costs two levels, table and
 *    cell — counted at four, eight nested layout tables lost their content on the production parser). Past the
 *    bound the TAGS go and the WORDS stay (a flattened subtree is its text, and the caller is told how many were:
 *    $report['flattened']); nothing is silently deleted because a design was deep.
 *
 * What does NOT survive on purpose: <style> blocks and class attributes (the head's CSS is gone, so a campaign
 * must have its CSS inlined — the composer says so), forms, media, frames, scripts, comments (conditional
 * comments with them), position / float / z-index / background images / animations.
 *
 * htmlToText() is the other half of a mail: the text/plain part, made from the same HTML.
 */

const HTMLSAFE_MAX_BYTES  = 60000;
const HTMLSAFE_MAX_DEPTH  = 64;          // levels the author drew (see HTMLSAFE_IMPLIED_LEVEL)
const HTMLSAFE_MAX_LEVELS = 256;         // elements of ANY kind, uncounted ones included: what bounds the walk itself
const HTMLSAFE_MAX_OUT    = 131072;      // bytes written; a pathological input (<p><b> × 9800) wrote 344 KB from 59 KB
const HTMLSAFE_XHTML_NS   = 'http://www.w3.org/1999/xhtml';

/** The parts of a table that cost no level: the parser puts the tbody in, and a row is only the cells' line. */
const HTMLSAFE_IMPLIED_LEVEL = ['tbody' => true, 'thead' => true, 'tfoot' => true, 'tr' => true];

/** The elements that stay, with the attributes each may carry. (href / src also get their own value rules.) */
function htmlSafeKeep(): array
{
    static $keep = null;
    if ($keep !== null) return $keep;
    $text   = ['style', 'title'];
    $block  = ['style', 'title', 'align'];
    $cell   = ['width', 'height', 'align', 'valign', 'bgcolor', 'colspan', 'rowspan', 'style', 'title'];
    $row    = ['align', 'valign', 'bgcolor', 'style', 'title'];
    $keep = [
        'p' => $block, 'div' => $block, 'h1' => $block, 'h2' => $block, 'h3' => $block, 'h4' => $block,
        'center' => $text, 'span' => $text, 'pre' => $text,     // pre: its lines and spaces ARE its content
        'strong' => $text, 'b' => $text, 'em' => $text, 'i' => $text, 'u' => $text, 's' => $text,
        'small' => $text, 'sup' => $text, 'sub' => $text,
        'br' => [],
        'a' => ['href', 'title', 'style'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'align', 'style'],
        'ul' => $text, 'ol' => $text, 'li' => $text, 'blockquote' => $text,
        'hr' => ['width', 'align', 'style'],
        'table' => ['width', 'align', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'style', 'title'],
        'thead' => $row, 'tbody' => $row, 'tfoot' => $row, 'tr' => $row,
        'td' => $cell, 'th' => $cell,
    ];
    return $keep;
}

/** Wrappers whose tag goes and whose children stay. */
function htmlSafeUnwrap(): array
{
    static $u = null;
    if ($u === null) {
        $u = array_fill_keys(['section', 'article', 'header', 'footer', 'main', 'nav', 'aside', 'figure', 'figcaption',
            'font', 'label', 'abbr', 'cite', 'code', 'del', 'ins', 'mark', 'big', 'tt', 'kbd', 'q',
            'dl', 'dt', 'dd', 'caption', 'colgroup', 'col', 'address'], true);
    }
    return $u;
}

/**
 * Dropped WITH their content. Documentation and test target: an element that is in no list is dropped with its
 * content as well (default deny), so this list can only ever say what the default already does.
 */
function htmlSafeDrop(): array
{
    return ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select',
            'svg', 'math', 'link', 'meta', 'base', 'template', 'noscript', 'title', 'xmp', 'plaintext', 'noembed',
            'noframes', 'frameset', 'frame', 'applet', 'param', 'head', 'video', 'audio', 'source', 'track',
            'picture', 'canvas', 'portal', 'option', 'optgroup', 'datalist', 'marquee'];
}

/** Can this PHP parse HTML at all (ext-dom)? Without it nothing is let through: htmlSafeClean() answers ''. */
function htmlSafeAvailable(): bool
{
    return class_exists('Dom\\HTMLDocument') || class_exists('DOMDocument');
}

/**
 * Make an administrator's HTML safe. $for: 'mail' (a message in an inbox) or 'site' (shown inside the site's
 * own notification frame: the font-size is clamped, a negative margin and display:none are refused). The result
 * is the BODY's children as a fragment — '' for nothing, and for anything this function could not parse.
 *
 * $report (out): ['flattened' => the subtrees that were deeper than the bounds and came out as their words only,
 * 'cut' => true when the output hit HTMLSAFE_MAX_OUT and the rest was left off]. Callers that tell an author what
 * became of the design read it (bulkMailParts()); the others need not pass it.
 */
function htmlSafeClean(string $html, string $for = 'mail', ?array &$report = null): string
{
    $for = $for === 'site' ? 'site' : 'mail';
    $report = ['flattened' => 0, 'cut' => false];
    if (trim($html) === '' || !htmlSafeAvailable()) return '';
    // Valid UTF-8 first, so the cut below cannot split a character; then the cap in BYTES (a TEXT column and a
    // mail are measured in bytes, not characters).
    $html = mb_scrub($html, 'UTF-8');
    if (strlen($html) > HTMLSAFE_MAX_BYTES) $html = mb_strcut($html, 0, HTMLSAFE_MAX_BYTES, 'UTF-8');
    // NUL and the C0 controls but tab / line feed / carriage return: no tag, no value, no text needs them, and
    // "java\x01script:" is not worth finding out what some client does with.
    $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $html) ?? '';
    if (trim($html) === '') return '';
    try {
        $body = htmlSafeParse($html);
        if ($body === null) return '';
        $st = ['left' => HTMLSAFE_MAX_OUT, 'flattened' => 0, 'cut' => false];
        $out = trim(htmlSafeChildren($body, 1, $for, $st));
        $report = ['flattened' => $st['flattened'], 'cut' => $st['cut']];
        return $out;
    } catch (\Throwable $e) {
        return '';      // a parser that failed is not a reason to let the input through
    }
}

/**
 * The BODY element of the parsed input, or null. $GLOBALS['__htmlsafe_engine'] = 'legacy' forces the older
 * parser (tests/htmlsafe_test.php runs the corpus through both on a PHP that has the new one).
 */
function htmlSafeParse(string $html): ?object
{
    $legacy = (($GLOBALS['__htmlsafe_engine'] ?? '') === 'legacy') || !class_exists('Dom\\HTMLDocument');
    $prev = libxml_use_internal_errors(true);
    try {
        if (!$legacy) {
            // With a doctype of OUR choosing in front: the parser then builds the tree of a standards-mode document
            // — the mail's and the notification frame's both start `<!DOCTYPE html>` — and not the quirks-mode tree
            // an input without one would get (where a <table> does not close an open <p>). A doctype of the
            // input's own comes second and is ignored, so a campaign cannot choose its mode.
            $doc = \Dom\HTMLDocument::createFromString('<!DOCTYPE html>' . $html, LIBXML_NOERROR, 'UTF-8');
            $body = $doc->body;
            return ($body !== null && strtolower((string)$body->nodeName) === 'body') ? $body : null;
        }
        // Every non-ASCII character as a numeric entity: libxml2's HTML parser guesses a charset otherwise.
        $src = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $doc = new \DOMDocument();
        // No closing wrapper after the input — see the head of this file.
        if (!$doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $src,
                            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return null;
        $list = $doc->getElementsByTagName('body');
        return $list->length > 0 ? $list->item(0) : null;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
    }
}

/**
 * The kept rendering of a node's children. $depth is the nesting level of THOSE children (the author's levels:
 * HTMLSAFE_IMPLIED_LEVEL costs none), $level the elements of any kind above them. $st is the walk's state: the bytes
 * it may still write, and what it had to flatten or leave off.
 */
function htmlSafeChildren(object $parent, int $depth, string $for, array &$st, int $level = 0): string
{
    $out = '';
    foreach ($parent->childNodes as $c) {
        if ($st['left'] <= 0) { $st['cut'] = true; break; }     // the output bound: what is left is left off, tags stay balanced
        $type = $c->nodeType;
        if ($type === 3) {                   // text — nothing else but elements and text is ever carried over
            $out .= htmlSafeSpend($st, htmlSafeText((string)$c->nodeValue));
        } elseif ($type === 1) {
            $out .= htmlSafeElement($c, $depth, $for, $st, $level);
        }
    }
    return $out;
}

/** A piece of the output, counted against the walk's byte bound. Only what is WRITTEN is counted, once. */
function htmlSafeSpend(array &$st, string $s): string
{
    $st['left'] -= strlen($s);
    return $s;
}

/**
 * A subtree past the bounds, as its words: the text nodes of every element the walk would have kept or unwrapped (a
 * script's or a style's insides are still not words), a space where a block, a row or a cell began or ended so that
 * neighbours do not run together. Iterative — the subtree can be thousands of levels deep, which is why it is here.
 */
function htmlSafeFlatten(object $root, array &$st): string
{
    static $block = null;
    if ($block === null) {
        $block = array_fill_keys(['p', 'div', 'h1', 'h2', 'h3', 'h4', 'center', 'br', 'hr', 'ul', 'ol', 'li', 'blockquote', 'pre', 'table',
            'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'section', 'article', 'header', 'footer', 'main', 'nav', 'aside', 'figure',
            'figcaption', 'dl', 'dt', 'dd', 'caption', 'address'], true);
    }
    $st['flattened']++;
    $keep = htmlSafeKeep();
    $unwrap = htmlSafeUnwrap();
    $out = '';
    $gap = static function () use (&$out): void {
        if ($out !== '' && substr($out, -1) !== ' ') $out .= ' ';
    };
    $n = $root->firstChild;
    while ($n !== null) {
        if ($st['left'] - strlen($out) <= 0) { $st['cut'] = true; break; }
        $descend = false;
        if ($n->nodeType === 3) {
            $out .= htmlSafeText((string)$n->nodeValue);
        } elseif ($n->nodeType === 1) {
            $ns = $n->namespaceURI;
            $tag = strtolower((string)$n->nodeName);
            if (($ns === null || $ns === '' || $ns === HTMLSAFE_XHTML_NS) && (isset($keep[$tag]) || isset($unwrap[$tag]))) {
                if (isset($block[$tag])) $gap();
                $descend = $n->firstChild !== null;
            }
        }
        if ($descend) { $n = $n->firstChild; continue; }
        // The next node in document order, never leaving $root; leaving an element is a gap if it was a block.
        while ($n !== null) {
            if ($n->nextSibling !== null) { $n = $n->nextSibling; break; }
            $n = $n->parentNode;
            if ($n === null || $n->isSameNode($root)) { $n = null; break; }
            if (isset($block[strtolower((string)$n->nodeName)])) $gap();
        }
    }
    return htmlSafeSpend($st, $out);
}

/** A text node as it is written: no controls, no bidi overrides, every markup character escaped. */
function htmlSafeText(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
    $s = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function htmlSafeElement(object $el, int $depth, string $for, array &$st, int $level = 0): string
{
    // An element of another namespace (svg, math — dropped by name anyway) is never one of ours.
    $ns = $el->namespaceURI;
    if ($ns !== null && $ns !== '' && $ns !== HTMLSAFE_XHTML_NS) return '';
    $tag = strtolower((string)$el->nodeName);
    $unwrap = htmlSafeUnwrap();
    $keep = htmlSafeKeep();
    if (!isset($unwrap[$tag]) && !isset($keep[$tag])) return '';     // default deny: gone, with everything inside it
    // Past the bounds the tags go and the words stay (see the head of this file). The check is here, on the way DOWN, so
    // an unwrapped chain (<font> × 10 000) is bounded as well as a kept one.
    if ($depth > HTMLSAFE_MAX_DEPTH || $level > HTMLSAFE_MAX_LEVELS) return htmlSafeFlatten($el, $st);
    $level++;
    // The levels an author draws: a table body and a row are the parser's and the layout's, not a nesting anyone chose.
    $inner = isset(HTMLSAFE_IMPLIED_LEVEL[$tag]) ? $depth : $depth + 1;
    if (isset($unwrap[$tag])) return htmlSafeChildren($el, $inner, $for, $st, $level);

    $allowed = $keep[$tag];
    $vals = [];
    foreach ($el->attributes as $a) {
        $ans = $a->namespaceURI;
        if ($ans !== null && $ans !== '') continue;
        $name = (string)$a->nodeName;
        if (!in_array($name, $allowed, true)) continue;   // exact and lower-case: xlink:href, xml:lang, HREF are gone
        $v = htmlSafeAttrValue($name, (string)$a->nodeValue, $for);
        if ($v !== null) $vals[$name] = $v;
    }

    if ($tag === 'br') return htmlSafeSpend($st, '<br>');
    if ($tag === 'a') {
        if (!isset($vals['href'])) return htmlSafeChildren($el, $inner, $for, $st, $level);   // a link with no usable address is words
        $attrs = ' href="' . htmlSafeAttr($vals['href']) . '"';
        if (isset($vals['title']) && $vals['title'] !== '') $attrs .= ' title="' . htmlSafeAttr($vals['title']) . '"';
        if (isset($vals['style'])) $attrs .= ' style="' . htmlSafeAttr($vals['style']) . '"';
        // Forced, whatever was written: a new tab for a frame that cannot navigate itself, and no referrer.
        $attrs .= ' target="_blank" rel="nofollow noopener noreferrer"';
        htmlSafeSpend($st, '<a' . $attrs . '></a>');
        return '<a' . $attrs . '>' . htmlSafeChildren($el, $inner, $for, $st, $level) . '</a>';
    }
    if ($tag === 'img') {
        if (!isset($vals['src'])) return '';
        $attrs = ' src="' . htmlSafeAttr($vals['src']) . '" alt="' . htmlSafeAttr($vals['alt'] ?? '') . '"';
        foreach (['title', 'width', 'height', 'align', 'style'] as $n) {
            if (isset($vals[$n]) && $vals[$n] !== '') $attrs .= ' ' . $n . '="' . htmlSafeAttr($vals[$n]) . '"';
        }
        return htmlSafeSpend($st, '<img' . $attrs . '>');
    }
    $attrs = '';
    foreach ($allowed as $n) {
        if (isset($vals[$n]) && $vals[$n] !== '') $attrs .= ' ' . $n . '="' . htmlSafeAttr($vals[$n]) . '"';
    }
    if ($tag === 'hr') return htmlSafeSpend($st, '<hr' . $attrs . '>');
    htmlSafeSpend($st, '<' . $tag . $attrs . '></' . $tag . '>');
    $inside = htmlSafeChildren($el, $inner, $for, $st, $level);
    // <pre>: the parser drops ONE newline right after the tag, so a text that really begins with a line break needs a
    // second one written or the next reader (a mail client, the notification frame) eats the first.
    if ($tag === 'pre' && str_starts_with($inside, "\n")) $inside = "\n" . $inside;
    return '<' . $tag . $attrs . '>' . $inside . '</' . $tag . '>';
}

function htmlSafeAttr(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** One attribute's value as it will be written, or null when it is not acceptable ('' is a value: alt=""). */
function htmlSafeAttrValue(string $name, string $v, string $for): ?string
{
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v) ?? '';
    switch ($name) {
        case 'href':  return htmlSafeUrl($v, false);
        case 'src':   return htmlSafeUrl($v, true);
        case 'alt':
        case 'title':
            $v = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $v) ?? '';
            return mb_substr(trim($v), 0, 300, 'UTF-8');
        case 'width':
        case 'height':
            $v = trim($v);
            return preg_match('/^\d{1,4}%?$/', $v) ? $v : null;
        case 'align':
            $v = strtolower(trim($v));
            return in_array($v, ['left', 'right', 'center', 'justify'], true) ? $v : null;
        case 'valign':
            $v = strtolower(trim($v));
            return in_array($v, ['top', 'middle', 'bottom', 'baseline'], true) ? $v : null;
        case 'colspan':
        case 'rowspan':
            $v = trim($v);
            return (preg_match('/^\d{1,3}$/', $v) && (int)$v >= 1 && (int)$v <= 100) ? (string)(int)$v : null;
        case 'bgcolor':
            $v = strtolower(trim($v));
            if (preg_match('/^[0-9a-f]{6}$/', $v)) $v = '#' . $v;      // the old attribute allowed it bare
            return htmlSafeCssColor($v);
        case 'cellpadding':
        case 'cellspacing':
        case 'border':
            $v = trim($v);
            return preg_match('/^\d{1,2}$/', $v) ? (string)(int)$v : null;
        case 'style':
            $s = htmlSafeStyle($v, $for);
            return $s === '' ? null : $s;
    }
    return null;
}

/**
 * An address a member's mail client or browser may be sent to, or null. The DOM-decoded value read as a browser reads
 * an address (the WHATWG URL parser), so the scheme check judges what the browser will see:
 *   * the control characters, and the tab / line feed / carriage return a browser deletes wherever they stand
 *     ("java<TAB>script:" is a real bypass), go;
 *   * a blank at either end goes; a space inside, and the other blanks of Unicode (no-break, em, ideographic space …),
 *     are written as their percent-encoding — deleting them glued the words of a mailto: subject and the folders of a
 *     path together ("Hello World" → "HelloWorld", "/a b/c" → "/ab/c");
 * then http(s):// or mailto: — never relative, never protocol-relative, never data: or javascript:. $httpsOnly (an
 * <img>): https.
 */
function htmlSafeUrl(string $raw, bool $httpsOnly): ?string
{
    if (strlen($raw) > 8192) return null;
    $u = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\t\n\r]/', '', $raw);
    if ($u !== null) $u = trim($u, ' ');
    if ($u !== null) $u = str_replace(' ', '%20', $u);
    if ($u !== null) $u = preg_replace_callback('/[\x{00A0}\x{1680}\x{2000}-\x{200B}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u',
        static fn(array $m): string => rawurlencode($m[0]), $u);
    if ($u === null || $u === '' || strlen($u) > 2048 || str_contains($u, '\\')) return null;
    if (preg_match('#^mailto:#i', $u)) {
        if ($httpsOnly) return null;
        return preg_match('#^mailto:[^<>"]{3,}$#i', $u) ? $u : null;
    }
    if (!preg_match($httpsOnly ? '#^https://#i' : '#^https?://#i', $u)) return null;
    $parts = parse_url($u);
    if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    if (!preg_match('/^[\p{L}\p{N}._\-\[\]:]+$/u', (string)$parts['host'])) return null;
    return $u;
}

/**
 * The document a cleaned fragment ('site') is shown in: the srcdoc of the account page's notification frame
 * (`<iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox">` — no allow-scripts, so even a
 * hole in the sanitizer runs nothing; allow-same-origin only so the page can measure the frame's height, allow-popups so
 * a link's target=_blank opens a tab). A frame has no stylesheet of the site's, so this one carries the minimum: the
 * site's dark ground made transparent (the card behind shows), its text colour and type, a link colour that reads, and
 * nothing may be wider than the frame (images, tables). $fragment is htmlSafeClean()'s output.
 */
function htmlSafeSiteFrame(string $fragment): string
{
    // color-scheme: dark, like the page that holds the frame: a frame whose document is of ANOTHER scheme than its page is
    // painted an opaque white by the browser, whatever the document says (the card behind would not show through).
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
         . '<meta name="color-scheme" content="dark">'
         . '<style>html,body{margin:0;padding:0;background:transparent}html{color-scheme:dark}'
         . 'body{padding:2px 1px;font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:#e0e0e0;overflow-wrap:anywhere}'
         . 'a{color:#6ea8ff}img{max-width:100%;height:auto}table{max-width:100%}*{box-sizing:border-box;max-width:100%}'
         . 'h1,h2,h3,h4{line-height:1.25;margin:.6em 0 .3em}p{margin:.5em 0}hr{border:0;border-top:1px solid rgba(255,255,255,.2)}'
         . 'pre{white-space:pre-wrap;margin:.5em 0}'
         . '</style></head><body>' . $fragment . '</body></html>';
}

/* ── CSS ────────────────────────────────────────────────────────────────────────────────────────────── */

/**
 * A style attribute, rebuilt: each declaration kept only if its PROPERTY is on the list and its VALUE fits that
 * property's small grammar (htmlSafeCssValue()). Splitting on ';' naively is safe BECAUSE of that: a piece cut
 * out of a quoted string or a comment fails the grammar and goes; nothing is ever copied that was not matched.
 */
function htmlSafeStyle(string $css, string $for): string
{
    if (strlen($css) > 2000) $css = substr($css, 0, 2000);
    $css = str_replace(["\r", "\n", "\t"], ' ', $css);
    $out = [];
    foreach (explode(';', $css) as $decl) {
        $pos = strpos($decl, ':');
        if ($pos === false) continue;
        $prop = strtolower(trim(substr($decl, 0, $pos)));
        $val  = trim(substr($decl, $pos + 1));
        $val  = (string)preg_replace('/\s*!\s*important\s*$/i', '', $val);
        if ($prop === '' || $val === '' || strlen($val) > 300) continue;
        // Belt: nothing that could be a function with an address, an escape, a comment, an at-rule or markup is
        // a value of any property below. The grammars would refuse them as well.
        if (preg_match('/[\\\\@<>{}`!\x00-\x1F\x7F]|\/\*|\*\/|url\s*\(|expression|javascript|behavio|binding|image-set|var\s*\(|env\s*\(|attr\s*\(|calc\s*\(/i', $val)) continue;
        $norm = htmlSafeCssValue($prop, $val, $for);
        if ($norm !== null && $norm !== '') $out[$prop] = $norm;   // the last one of a property wins, as in a browser
    }
    $parts = [];
    foreach ($out as $p => $v) $parts[] = $p . ':' . $v;
    return implode(';', $parts);
}

/** CSS Level 3's colour names: a fixed list, so a word that merely looks like one is never a colour. */
function htmlSafeCssColorNames(): array
{
    static $n = null;
    if ($n === null) {
        $n = array_fill_keys(explode(' ', 'aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue '
            . 'blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue '
            . 'darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid '
            . 'darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink '
            . 'deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold '
            . 'goldenrod gray green greenyellow grey honeydew hotpink indianred indigo ivory khaki lavender lavenderblush '
            . 'lawngreen lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey '
            . 'lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow '
            . 'lime limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen '
            . 'mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin '
            . 'navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen paleturquoise '
            . 'palevioletred papayawhip peachpuff peru pink plum powderblue purple red rosybrown royalblue saddlebrown '
            . 'salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow springgreen '
            . 'steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen '
            . 'transparent currentcolor inherit'), true);
    }
    return $n;
}

/** A colour: #rgb / #rgba / #rrggbb / #rrggbbaa, rgb() / rgba() / hsl() / hsla() of plain numbers, or a name. */
function htmlSafeCssColor(string $v): ?string
{
    $v = strtolower(trim($v));
    if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $v)) return $v;
    if (isset(htmlSafeCssColorNames()[$v])) return $v;
    $n = '\d{1,3}(?:\.\d{1,3})?%?';
    if (preg_match('/^(rgba?|hsla?)\(\s*(' . $n . ')\s*[,\s]\s*(' . $n . ')\s*[,\s]\s*(' . $n . ')\s*(?:[,\/]\s*(\d{0,3}(?:\.\d{1,3})?%?)\s*)?\)$/', $v, $m)) {
        return preg_replace('/\s+/', ' ', $v);
    }
    return null;
}

/** A length: 0, or a number with px / em / rem / pt / %. Unitless non-zero is not a length. $neg: may be negative. */
function htmlSafeCssLen(string $v, bool $neg = false, bool $pct = true): ?string
{
    $v = strtolower(trim($v));
    if (!preg_match('/^(-?)(\d{1,4}(?:\.\d{1,3})?|\.\d{1,3})(px|em|rem|pt|%)?$/', $v, $m)) return null;
    if ($m[1] === '-' && !$neg) return null;
    if (($m[3] ?? '') === '%' && !$pct) return null;
    if (($m[3] ?? '') === '') return ((float)$m[2] == 0.0) ? '0' : null;
    return $m[1] . $m[2] . $m[3];
}

/** 1 to $max whitespace-separated values, each through $one; the whole is null if any is. */
function htmlSafeCssList(string $v, int $max, callable $one): ?string
{
    $parts = preg_split('/\s+/', trim($v)) ?: [];
    if (!$parts || count($parts) > $max) return null;
    $out = [];
    foreach ($parts as $p) {
        $r = $one($p);
        if ($r === null) return null;
        $out[] = $r;
    }
    return implode(' ', $out);
}

/** One property's value, normalised, or null. */
function htmlSafeCssValue(string $prop, string $val, string $for): ?string
{
    $site = $for === 'site';
    $v = strtolower(trim($val));
    $kw = static fn(array $set): ?string => in_array($v, $set, true) ? $v : null;
    $sides = ['top', 'right', 'bottom', 'left'];
    $borderStyles = ['none', 'hidden', 'dotted', 'dashed', 'solid', 'double', 'groove', 'ridge', 'inset', 'outset'];
    $borderWidth = static function (string $p): ?string {
        $p = strtolower($p);
        return in_array($p, ['thin', 'medium', 'thick'], true) ? $p : htmlSafeCssLen($p, false, false);
    };

    switch ($prop) {
        case 'color':
        case 'background-color':
            return htmlSafeCssColor($v);
        case 'background':              // the shorthand, but only as a colour — no image, no gradient
            return htmlSafeCssColor($v);
        case 'font-size': {
            if (in_array($v, ['xx-small', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large', 'smaller', 'larger'], true)) return $v;
            $l = htmlSafeCssLen($v, false);
            if ($l === null) return null;
            if ($site && preg_match('/^(\d*\.?\d+)(px|em|rem|pt|%)$/', $l, $m)) {
                // A notification must not be able to shout: 32px, 2em, 200% at most.
                $max = ['px' => 32, 'pt' => 24, 'em' => 2, 'rem' => 2, '%' => 200][$m[2]];
                if ((float)$m[1] > $max) return $max . $m[2];
            }
            return $l;
        }
        case 'font-weight':
            return $kw(['normal', 'bold', 'bolder', 'lighter', '100', '200', '300', '400', '500', '600', '700', '800', '900']);
        case 'font-style':
            return $kw(['normal', 'italic', 'oblique']);
        case 'font-family': {
            $generic = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'];
            $names = [];
            foreach (explode(',', $val) as $n) {
                $n = trim($n);
                if (preg_match('/^(["\'])(.*)\1$/s', $n, $m)) $n = trim($m[2]);
                if (in_array(strtolower($n), $generic, true)) { $names[] = strtolower($n); continue; }
                // A name is words: letters, digits, space, underscore, hyphen — rewritten with our own quotes.
                if (!preg_match('/^[\p{L}][\p{L}\p{N} _\-]{0,39}$/u', $n)) continue;
                $names[] = preg_match('/^[A-Za-z][A-Za-z0-9_\-]*$/', $n) ? $n : "'" . $n . "'";
                if (count($names) >= 6) break;
            }
            return $names ? implode(',', $names) : null;
        }
        case 'text-align':
            return $kw(['left', 'right', 'center', 'justify', 'start', 'end']);
        case 'text-decoration':
            return htmlSafeCssList($v, 3, static fn($p) => in_array($p, ['none', 'underline', 'overline', 'line-through'], true) ? $p : null);
        case 'text-transform':
            return $kw(['none', 'uppercase', 'lowercase', 'capitalize']);
        case 'line-height':
            if ($v === 'normal') return $v;
            if (preg_match('/^\d(?:\.\d{1,3})?$/', $v) && (float)$v <= 4) return $v;       // a plain multiplier
            return htmlSafeCssLen($v, false);
        case 'letter-spacing':
            if ($v === 'normal') return $v;
            $l = htmlSafeCssLen($v, true, false);
            return ($l !== null && (float)$l >= -5 && (float)$l <= 20) ? $l : null;
        case 'white-space':
            return $kw(['normal', 'nowrap', 'pre-wrap', 'pre-line']);
        case 'word-break':
            return $kw(['normal', 'break-all', 'keep-all', 'break-word']);
        case 'vertical-align':
            if (in_array($v, ['baseline', 'top', 'middle', 'bottom', 'text-top', 'text-bottom', 'sub', 'super'], true)) return $v;
            return htmlSafeCssLen($v, true);
        case 'list-style-type':
            return $kw(['disc', 'circle', 'square', 'decimal', 'lower-alpha', 'upper-alpha', 'lower-roman', 'upper-roman', 'none']);
        case 'display': {
            $ok = ['block', 'inline', 'inline-block', 'table', 'inline-table', 'table-row', 'table-cell', 'table-row-group',
                   'table-header-group', 'table-footer-group', 'list-item'];
            if (!$site) $ok[] = 'none';       // a mail's hidden preheader is a real thing; the site's frame has no use for it
            return $kw($ok);
        }
        case 'width': case 'height': case 'min-width': case 'min-height':
            return $v === 'auto' ? $v : htmlSafeCssLen($v, false);
        case 'max-width': case 'max-height':
            return $v === 'none' ? $v : htmlSafeCssLen($v, false);
        case 'padding': case 'padding-top': case 'padding-right': case 'padding-bottom': case 'padding-left':
            return htmlSafeCssList($v, $prop === 'padding' ? 4 : 1, static fn($p) => htmlSafeCssLen($p, false));
        case 'margin': case 'margin-top': case 'margin-right': case 'margin-bottom': case 'margin-left':
            return htmlSafeCssList($v, $prop === 'margin' ? 4 : 1, static fn($p) => $p === 'auto' ? 'auto' : htmlSafeCssLen($p, !$site));
        case 'border': case 'border-top': case 'border-right': case 'border-bottom': case 'border-left': {
            // [width] [style] [colour] in any order, each at most once.
            $parts = preg_split('/\s+/', trim($v)) ?: [];
            if (!$parts || count($parts) > 3) return null;
            $got = ['w' => null, 's' => null, 'c' => null];
            foreach ($parts as $p) {
                if (in_array($p, $borderStyles, true) && $got['s'] === null) { $got['s'] = $p; continue; }
                if (($w = $borderWidth($p)) !== null && $got['w'] === null) { $got['w'] = $w; continue; }
                if (($c = htmlSafeCssColor($p)) !== null && $got['c'] === null) { $got['c'] = $c; continue; }
                return null;
            }
            return implode(' ', array_values(array_filter($got, static fn($x) => $x !== null)));
        }
        case 'border-width':
            return htmlSafeCssList($v, 4, $borderWidth);
        case 'border-style':
            return htmlSafeCssList($v, 4, static fn($p) => in_array($p, $borderStyles, true) ? $p : null);
        case 'border-color':
            // A colour function holds spaces, so this one is not split on them: a single colour, or hex / names only.
            return htmlSafeCssList($v, 4, static fn($p) => htmlSafeCssColor($p));
        case 'border-radius':
            return htmlSafeCssList($v, 4, static fn($p) => htmlSafeCssLen($p, false));
        case 'border-collapse':
            return $kw(['collapse', 'separate']);
        case 'border-spacing':
            return htmlSafeCssList($v, 2, static fn($p) => htmlSafeCssLen($p, false, false));
    }
    return null;
}

/* ── the text half of a mail ────────────────────────────────────────────────────────────────────────── */

/**
 * HTML as plain text, for the text/plain part of a mail and for anything that wants the words of a markup:
 * block tags become line breaks (a paragraph a blank line), `<a href=u>t</a>` becomes `t (u)`, a list item
 * "- text", an image its alt in brackets, entities are decoded and whitespace is folded the way a browser
 * folds it. A <pre> keeps its own lines and spaces. The input is trusted to be OUR markup (the renderers', the
 * sanitizer's): this is no sanitizer, and its output is text — which is why it can afford to be a few patterns.
 */
function htmlToText(string $html): string
{
    if ($html === '') return '';
    $s = str_replace(["\r\n", "\r"], "\n", $html);
    $s = preg_replace('#<(script|style|head|title|template|noscript)\b[^>]*>.*?</\1\s*>#is', '', $s) ?? $s;
    $s = preg_replace('/<!--.*?-->/s', '', $s) ?? $s;
    // A <pre> is exempt from the folding below: its newlines and spaces ride out as markers.
    // (A line break straight after the tag is not content: the parser drops one, so does this.)
    $s = preg_replace_callback('#(<pre\b[^>]*>)(.*?)(</pre\s*>)#is',
        static fn(array $m): string => $m[1] . str_replace(["\n", ' '], ["\x01", "\x02"], str_starts_with($m[2], "\n") ? substr($m[2], 1) : $m[2]) . $m[3], $s) ?? $s;
    $s = preg_replace('/[ \t\n\f]+/', ' ', $s) ?? $s;

    // A link: its words, then where it goes — unless the words ARE the address.
    $s = preg_replace_callback('#<a\b([^>]*)>(.*?)</a\s*>#is', static function (array $m): string {
        if (!preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $m[1], $h)) return $m[2];
        $url = '';
        for ($i = 1; $i <= 3; $i++) if (isset($h[$i]) && $h[$i] !== '') { $url = $h[$i]; break; }
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!preg_match('#^(?:https?://|mailto:)#i', $url)) return $m[2];
        $text = trim((string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $bare = (string)preg_replace('#^mailto:#i', '', $url);
        if ($text === '') $out = $url;
        elseif (strcasecmp(rtrim($text, '/'), rtrim($url, '/')) === 0 || strcasecmp($text, $bare) === 0) $out = $text;
        else $out = $text . ' (' . $url . ')';
        return htmlspecialchars($out, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }, $s) ?? $s;
    $s = preg_replace_callback('#<img\b[^>]*>#i', static function (array $m): string {
        if (!preg_match('/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $m[0], $a)) return '';
        $alt = trim(html_entity_decode(($a[1] ?? '') !== '' ? $a[1] : ($a[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $alt === '' ? '' : htmlspecialchars('[' . $alt . ']', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }, $s) ?? $s;

    // Breaks. \x04 = a line, \x05 = a blank line; a run of them is the stronger one; <br> is a real newline.
    $s = preg_replace('#<br\b[^>]*>#i', "\n", $s) ?? $s;
    $s = preg_replace('#<hr\b[^>]*>#i', "\x05--------\x05", $s) ?? $s;
    $s = preg_replace('#<li\b[^>]*>#i', "\x04- ", $s) ?? $s;
    $s = preg_replace('#</?(?:p|h[1-6]|blockquote|pre|ul|ol|table)\b[^>]*>#i', "\x05", $s) ?? $s;
    $s = preg_replace('#</?(?:div|center|tr|section|article|header|footer|main|nav|aside|figure|dl|dt|dd|li|thead|tbody|tfoot|caption)\b[^>]*>#i', "\x04", $s) ?? $s;
    $s = preg_replace('#</t[dh]\s*>#i', ' ', $s) ?? $s;

    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace("\u{00A0}", ' ', $s);
    $s = preg_replace_callback('/[\x04\x05]+/', static fn(array $m): string => str_contains($m[0], "\x05") ? "\n\n" : "\n", $s) ?? $s;

    $lines = [];
    foreach (explode("\n", $s) as $line) $lines[] = trim((string)preg_replace('/[ \t]+/', ' ', $line));
    $s = implode("\n", $lines);
    $s = preg_replace('/\n{3,}/', "\n\n", $s) ?? $s;
    $s = str_replace(["\x01", "\x02"], ["\n", ' '], $s);
    return trim($s);
}
