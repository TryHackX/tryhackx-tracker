<?php
/**
 * The HTML sanitizer (includes/htmlsafe.php, 1.75.0) — the one piece of this release that a mistake turns into a
 * script running in a member's browser or inbox:
 *   php tests/htmlsafe_test.php
 *
 * ── what is asserted, and why it is not "the output equals this string" ─────────────────────────
 * libxml differs between the development machine (2.11.9) and production (2.9.14), and the HTML5 parser differs from
 * both, so the exact bytes of a cleaned fragment are not a promise anybody can make. A PROPERTY is: whatever the
 * parser made of a hostile document, the OUTPUT is a string of our own tags — each on the list — carrying our own
 * attributes — each on its element's list — with values of the shape that attribute's grammar allows. That is checked
 * by READING THE OUTPUT BACK (hsSafe()) for every payload, under both parsers and both targets, and then again for
 * a few thousand documents made by shuffling the payloads' pieces together. The named payloads also have the
 * assertions anybody would write first (no `<script`, no `on…=`, no `javascript:`, no `url(`).
 *
 * The corpus is the audit's (plan 1.75.0, B1) and the bypasses of the last ten years that bear on a sanitizer
 * built this way: attribute text that closes a raw-text element when the markup is read twice (noscript, style
 * inside svg), namespace confusion (math / mglyph / svg), comment and CDATA tricks, an address hidden by entities,
 * tabs and control characters, templates, textareas, titles, base and meta refresh, srcdoc, formaction, xlink:href,
 * every kind of CSS that can fetch or run something, and the event-handler attributes themselves. And a realistic
 * table-layout campaign, which must come through whole.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/htmlsafe.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}

/**
 * Read an output back: is it made of nothing but our grammar? Returns '' when it is, else what is wrong.
 */
function hsSafe(string $out): string
{
    $keep = htmlSafeKeep();
    $tagRe = '/<(\/?)([a-zA-Z][a-zA-Z0-9:_-]*)([^>]*)>/';
    preg_match_all($tagRe, $out, $tags, PREG_SET_ORDER);
    foreach ($tags as $t) {
        $name = $t[2];
        if ($name !== strtolower($name) || !isset($keep[$name])) return 'tag not on the list: ' . $t[0];
        if ($t[1] === '/') { if (trim($t[3]) !== '') return 'closing tag with something in it: ' . $t[0]; continue; }
        $rest = $t[3];
        $allowed = $keep[$name];
        if ($name === 'a') $allowed = array_merge($allowed, ['target', 'rel']);
        if (preg_match_all('/\s+([a-z][a-z0-9-]*)="([^"]*)"/', $rest, $am, PREG_SET_ORDER)) {
            foreach ($am as $a) {
                $an = $a[1];
                $av = html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!in_array($an, $allowed, true)) return "attribute $an not allowed on $name: " . $t[0];
                if ($an === 'href' && !preg_match('#^(?:https?://|mailto:)[^\x00-\x20\x7F\\\\]+$#i', $av)) return 'bad href: ' . $av;
                if ($an === 'src' && !preg_match('#^https://[^\x00-\x20\x7F\\\\]+$#i', $av)) return 'bad src: ' . $av;
                if ($an === 'target' && $av !== '_blank') return 'bad target: ' . $av;
                if ($an === 'rel' && $av !== 'nofollow noopener noreferrer') return 'bad rel: ' . $av;
                if ($an === 'style') {
                    if (preg_match('/url\s*\(|expression|javascript|behavio|binding|image-set|var\s*\(|env\s*\(|attr\s*\(|calc\s*\(|@|\\\\|\/\*|!|[<>{}]/i', $av)) return 'bad style: ' . $av;
                    foreach (explode(';', $av) as $d) {
                        if (!preg_match('/^[a-z-]+:[A-Za-z0-9#.,%\s\'_()\/-]+$/', $d)) return 'style declaration off the grammar: ' . $d;
                    }
                }
                if (in_array($an, ['width', 'height'], true) && !preg_match('/^\d{1,4}%?$/', $av)) return 'bad size: ' . $av;
            }
            $rest = preg_replace('/\s+[a-z][a-z0-9-]*="[^"]*"/', '', $rest);
        }
        if (trim($rest) !== '') return 'something that is not an attribute in a tag: ' . $t[0];
    }
    $textOnly = preg_replace($tagRe, '', $out);
    if (str_contains((string)$textOnly, '<')) return 'a raw < outside a tag';
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $out)) return 'a control character';
    return '';
}

/** The attribute NAMES in an output's tags — an `onerror=` inside a title's escaped text is text, not a name. */
function hsAttrNames(string $out): array
{
    $names = [];
    preg_match_all('/<[a-zA-Z][^>]*>/', $out, $tags);
    foreach ($tags[0] as $t) {
        if (preg_match_all('/\s([^\s=<>"\'\/]+)\s*=\s*"/', $t, $m)) foreach ($m[1] as $a) $names[] = $a;
    }
    return $names;
}

/**
 * What a second reading of an output may and may not have changed. A browser (or a mail client) reading our output
 * can move an element — a <p> that a <table> closes, a foster-parented cell — and that is layout. It must not turn
 * text into markup, markup into text, or invent an attribute: so the TEXT is the same and every (name, value) of the
 * second reading is one of the first's. '' when so.
 */
function hsProject(string $out): array
{
    $text = html_entity_decode(strip_tags(preg_replace('/<[^>]*>/', ' ', $out) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $pairs = [];
    preg_match_all('/<[a-zA-Z][^>]*>/', $out, $tags);
    foreach ($tags[0] as $t) {
        if (preg_match_all('/\s([^\s=<>"\'\/]+)\s*=\s*"([^"]*)"/', $t, $m, PREG_SET_ORDER)) foreach ($m as $a) $pairs[$a[1] . '=' . $a[2]] = true;
    }
    return [trim((string)preg_replace('/\s+/', ' ', $text)), $pairs];
}
function hsMutation(string $first, string $second): string
{
    [$t1, $a1] = hsProject($first);
    [$t2, $a2] = hsProject($second);
    if ($t1 !== $t2) return 'the text changed: [' . $t1 . '] => [' . $t2 . ']';
    foreach (array_keys($a2) as $p) if (!isset($a1[$p])) return 'a new attribute: ' . $p;
    return hsSafe($second);
}

/** The corpus: name => hostile markup. */
$corpus = [
    // raw-text and foreign content, the attribute that closes them when the output is read a second time
    'noscript-attr'       => '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
    'svg-style'           => '<svg><style><img src=x onerror=alert(1)></style></svg>',
    'svg-style-comment'   => '<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">',
    'svg-style-comment2'  => '<svg><style><!--</style><img src=x onerror=alert(1)>--></style></svg>',
    'math-mglyph'         => '<math><mtext><table><mglyph><style><!--</style><img title="--&gt;&lt;img src=x onerror=alert(1)&gt;">',
    'math-form'           => '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
    'svg-desc-cdata'      => '<div><p><svg><desc><![CDATA[</desc><script>alert(1)</script>]]></svg></p></div>',
    'svg-script'          => '<svg><script>alert(1)</script><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
    'svg-onload'          => '<svg onload=alert(1)><circle r=1></svg>',
    // comments and CDATA
    'comment-empty'       => '<!--><img src=x onerror=alert(1)>-->',
    'comment-bang'        => '<!--!><img src=x onerror=alert(1)>-->',
    'comment-dash'        => '<!---><img src=x onerror=alert(1)>-->',
    'comment-cond'        => '<!--[if gte IE 4]><script>alert(1)</script><![endif]-->visible',
    'comment-reveal'      => '<!--[if !mso]><!--><p>shown</p><!--<![endif]-->',
    'cdata'               => '<![CDATA[<script>alert(1)</script>]]>',
    'cdata-svg'           => '<svg><![CDATA[><img src=x onerror=alert(1)>]]></svg>',
    'pi'                  => '<?xml version="1.0"?><?php echo 1; ?><p>x</p>',
    // an address hidden from a naive scheme check
    'js-entity-tab'       => '<a href="jav&#x09;ascript:alert(1)">x</a>',
    'js-entity-letter'    => '<a href="&#106;avascript:alert(1)">x</a>',
    'js-entity-hex'       => '<a href="&#x6A;&#x61;vascript&#58;alert(1)">x</a>',
    'js-colon-name'       => '<a href="javascript&colon;alert(1)">x</a>',
    'js-newline'          => "<a href=\"java\nscript:alert(1)\">x</a>",
    'js-tab-raw'          => "<a href=\"java\tscript:alert(1)\">x</a>",
    'js-leading-space'    => '<a href="  javascript:alert(1)">x</a>',
    'js-control'          => "<a href=\"\x01javascript:alert(1)\">x</a>",
    'js-nul'              => "<a href=\"java\x00script:alert(1)\">x</a>",
    'js-case'             => '<a href="JaVaScRiPt:alert(1)">x</a>',
    'vbscript'            => '<a href="vbscript:msgbox(1)">x</a>',
    'data-html'           => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
    'protocol-relative'   => '<a href="//evil.example/x">x</a>',
    'relative'            => '<a href="/admin">x</a> <a href="../x">y</a> <a href="#frag">z</a> <a href="?action=admin">q</a>',
    'userinfo'            => '<a href="https://paypal.com@evil.example/">x</a>',
    'backslash'           => '<a href="https:\\\\evil.example/x">x</a>',
    'href-breakout'       => '<a href="https://x.example/&quot; onmouseover=&quot;alert(1)">x</a>',
    'attr-breakout'       => '<p title=\'" onmouseover="alert(1)\'>x</p>',
    'attr-gt'             => '<img alt="x" src="https://ok.example/a.png" title="><script>alert(1)</script>">',
    'attr-unclosed'       => '<p title="abc<img src=x onerror=alert(1)>',
    // images
    'img-js'              => '<img src="javascript:alert(1)">',
    'img-http'            => '<img src="http://ok.example/a.png">',
    'img-data'            => '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+">',
    'img-onerror'         => '<img src=x onerror=alert(1)>',
    'img-srcset'          => '<img src="https://ok.example/a.png" srcset="https://ok.example/b.png 2x" onload=alert(1)>',
    'img-dynsrc'          => '<img dynsrc="javascript:alert(1)" lowsrc="javascript:alert(1)">',
    // elements that are never wanted
    'template'            => '<template><script>alert(1)</script></template>after',
    'textarea'            => '<textarea><script>alert(1)</script></textarea>',
    'title'               => '<title><img src=x onerror=alert(1)></title>',
    'xmp'                 => '<xmp><script>alert(1)</script></xmp>',
    'plaintext'           => '<plaintext><script>alert(1)</script>',
    'noembed'             => '<noembed><img src=x onerror=alert(1)></noembed>',
    'noframes'            => '<noframes><img src=x onerror=alert(1)></noframes>',
    'iframe-srcdoc'       => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    'iframe-src'          => '<iframe src="javascript:alert(1)"></iframe>',
    'object'              => '<object data="javascript:alert(1)"><param name=x value=y></object>',
    'embed'               => '<embed src="javascript:alert(1)">',
    'base-meta'           => '<base href="https://evil.example/"><meta http-equiv="refresh" content="0;url=https://evil.example"><p>x</p>',
    'link-css'            => '<link rel=stylesheet href="https://evil.example/x.css"><p>x</p>',
    'style-block'         => '<style>p{background:url(https://evil.example/x)}</style><p>x</p>',
    'script-plain'        => '<script>alert(1)</script>',
    'script-upper'        => '<SCRIPT SRC=//evil.example/x.js></SCRIPT>',
    'script-nested'       => '<scr<script>ipt>alert(1)</scr</script>ipt>',
    'script-double-lt'    => '<<script>script>alert(1)</script>',
    'script-nul'          => "<scr\x00ipt>alert(1)</scr\x00ipt>",
    'frameset'            => '<frameset onload=alert(1)><frame src=javascript:alert(1)></frameset>',
    'marquee'             => '<marquee onstart=alert(1)>x</marquee>',
    'details-toggle'      => '<details open ontoggle=alert(1)>x</details>',
    'video-source'        => '<video><source onerror=alert(1)></video><audio src=x onerror=alert(1)>',
    'body-onload'         => '<body onload=alert(1) background="javascript:alert(1)"><p>x</p></body>',
    'html-attrs'          => '<html xmlns="http://www.w3.org/1999/xhtml" onload=alert(1)><head><style>x{}</style></head><body><p>x</p></body></html>',
    'isindex-keygen'      => '<isindex action="javascript:alert(1)"><keygen autofocus onfocus=alert(1)><bgsound src="javascript:alert(1)">',
    // forms
    'form-formaction'     => '<form action="https://evil.example"><button formaction="javascript:alert(1)">x</button><input type=image src=x onerror=alert(1)></form>',
    'select-template'     => '<select><template><style><img src=x onerror=alert(1)>',
    'xlink-href'          => '<a href="https://ok.example/" xlink:href="javascript:alert(1)" xml:base="javascript:x">x</a>',
    'a-ping'              => '<a href="https://ok.example/" ping="https://evil.example/track" download="x" target="_self" rel="opener">x</a>',
    // CSS
    'css-url-js'          => '<p style="background:url(javascript:alert(1))">x</p>',
    'css-expression'      => '<p style="width:expression(alert(1))">x</p>',
    'css-bg-image'        => '<p style="color:red;background-image:url(https://evil.example/x)">x</p>',
    'css-var'             => '<p style="color:var(--x)">x</p>',
    'css-image-set'       => '<p style="background:image-set(\'a.png\' 1x)">x</p>',
    'css-escape-prop'     => '<p style="col\\6f r:red">x</p>',
    'css-escape-value'    => '<p style="color:\\72 ed;background:\\75rl(https://evil.example/x)">x</p>',
    'css-import'          => '<p style="@import \'x.css\'">x</p>',
    'css-behavior'        => '<p style="behavior:url(x.htc);-moz-binding:url(x)">x</p>',
    'css-comment'         => '<p style="color:red/**/;c/**/olor:blue;background:/*;*/url(x)">x</p>',
    'css-position'        => '<p style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;float:left">x</p>',
    'css-env-attr-calc'   => '<p style="width:calc(100% - env(safe-area-inset-left));height:attr(data-h px);color:env(x)">x</p>',
    'css-important'       => '<p style="color:red !important;display:none ! important">x</p>',
    'css-semicolon-quote' => '<p style="font-family:\'a;background:url(x)\'">x</p>',
    'css-closing-quote'   => '<p style="font-family:&quot;x&quot;;color:red">x</p>',
    'css-huge'            => '<p style="font-size:99999px;margin:-99999px;padding:1e9px;width:9999999px">x</p>',
    // event handlers
    'on-click'            => '<p onclick=alert(1)>x</p>',
    'on-upper'            => '<p ONCLICK=alert(1) OnMouseOver=alert(2)>x</p>',
    'on-mix'              => '<div onmouseover="alert(1)" style="color:red">x</div>',
    'on-autofocus'        => '<a href="https://ok.example" onfocus=alert(1) autofocus>x</a>',
    'on-nul'              => "<img src=x on\x00error=alert(1)>",
    'on-slash'            => '<img/src=x/onerror=alert(1)>',
    // not UTF-8 at all
    'bad-utf8'            => "<p>caf\xE9 \xC0\xAF <script>alert(1)</script></p>",
    'bad-utf8-attr'       => "<a href=\"https://ok.example/\xC0\xAF\">x</a>",
];

/** The pieces the shuffler glues together: a hostile fragment is as hostile after another one. */
$pieces = array_values($corpus);
$pieces = array_merge($pieces, ['<', '>', '"', "'", '=', '</', '<!--', '-->', '<![CDATA[', ']]>', '<p>', '</p>', '<table>', '<tr>', '<td>',
    '</table>', '<svg>', '<math>', '<a href="', '<img src="', ' style="', 'javascript:', '&#x', ';', '<style>', '</style>', '<noscript>',
    '</noscript>', '<textarea>', '</textarea>', '<template>', '<select>', '<form>', '<b>', '</b>', '<mglyph>', '<title>', "\n", "\t", ' ']);

$engines = class_exists('Dom\\HTMLDocument') ? ['', 'legacy'] : ['legacy'];

/* ══ 0. the checker itself: a test that cannot fail proves nothing ═════════════════════════════════ */
check('the grammar checker refuses what it is there to refuse',
      hsSafe('<script>x</script>') !== '' && hsSafe('<a href="javascript:alert(1)" target="_blank" rel="nofollow noopener noreferrer">x</a>') !== ''
      && hsSafe('<p onclick="x">y</p>') !== '' && hsSafe('<img src="http://x.example/a.png" alt="">') !== '' && hsSafe('<p style="background:url(x)">y</p>') !== ''
      && hsSafe('<p>a < b</p>') !== '' && hsSafe('<iframe></iframe>') !== '' && hsSafe('<a href="https://x.example/">x</a>') === ''
      && hsSafe('<p style="color:red">&lt;script&gt;</p>') === '');

foreach ($engines as $engine) {
    $GLOBALS['__htmlsafe_engine'] = $engine;
    $label = $engine === '' ? 'HTML5 parser' : 'libxml parser';

    /* ══ 1. every named payload, both targets ═════════════════════════════════════════════════════ */
    foreach (['mail', 'site'] as $for) {
        $bad = [];
        $notIdem = [];
        foreach ($corpus as $name => $src) {
            $out = htmlSafeClean($src, $for);
            $why = hsSafe($out);
            $head = [];
            if (preg_match('/<\s*\/?\s*(script|style|iframe|object|embed|svg|math|form|input|button|textarea|select|link|meta|base|template|noscript|title|xmp|plaintext|frameset|frame|video|audio|source|marquee|details)\b/i', $out)) $head[] = 'a forbidden element';
            if (array_filter(hsAttrNames($out), fn($a) => stripos($a, 'on') === 0)) $head[] = 'an on*= attribute';
            // the tags only: "javascript:" as a WORD in a title's escaped text is words
            preg_match_all('/<[a-zA-Z][^>]*>/', $out, $tg);
            $tagText = html_entity_decode(implode(' ', $tg[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/(?:java|vb)script\s*:/i', preg_replace('/[\x00-\x20]/', '', $tagText) ?? '') && preg_match('/href="[^"]*(?:java|vb)script|src="[^"]*(?:java|vb)script/i', $out)) $head[] = 'a script address in a tag';
            if (preg_match('/style="[^"]*url\s*\(/i', $out)) $head[] = 'url( in a style';
            if (preg_match('/style="[^"]*(?:expression|@import|behavior|-moz-binding|image-set|var\()/i', $out)) $head[] = 'a CSS function in a style';
            if ($why !== '' || $head) $bad[] = $name . ' [' . $out . '] ' . $why . ' ' . implode(',', $head);
            // Read twice, nothing may turn into markup, text or an attribute it was not: layout may move.
            $m2 = hsMutation($out, htmlSafeClean($out, $for));
            if ($m2 !== '') $notIdem[] = $name . ' [' . $out . '] => [' . htmlSafeClean($out, $for) . '] ' . $m2;
        }
        check("$label / $for: all " . count($corpus) . ' payloads come out inside the grammar, with none of the headline signs', !$bad, implode(' || ', array_slice($bad, 0, 3)));
        check("$label / $for: and reading the output again changes no text and invents no attribute (no mutation)", !$notIdem, implode(' || ', array_slice($notIdem, 0, 3)));
    }

    /* ══ 2. what the named payloads must leave BEHIND ═════════════════════════════════════════════ */
    $c = fn(string $k, string $for = 'mail') => htmlSafeClean($corpus[$k], $for);
    check("$label: script-plain is empty", $c('script-plain') === '');
    check("$label: the words around a comment survive, the comment does not", str_contains($c('comment-cond'), 'visible') && !str_contains($c('comment-cond'), 'alert'));
    check("$label: an address behind a tab, a newline, an entity or a control character is not a link",
          !str_contains($c('js-entity-tab'), '<a') && !str_contains($c('js-newline'), '<a') && !str_contains($c('js-tab-raw'), '<a')
          && !str_contains($c('js-control'), '<a') && !str_contains($c('js-entity-hex'), '<a') && !str_contains($c('js-case'), '<a'),
          $c('js-entity-tab') . $c('js-newline') . $c('js-control'));
    check("$label: relative, protocol-relative, data: and vbscript: addresses are not links",
          !str_contains($c('relative'), '<a') && !str_contains($c('protocol-relative'), '<a')
          && !str_contains($c('data-html'), '<a') && !str_contains($c('vbscript'), '<a'), $c('relative'));
    check("$label: an address with a user name in it is not a link", !str_contains($c('userinfo'), '<a'), $c('userinfo'));
    check("$label: the words of a link with no usable address stay", str_contains($c('js-case'), 'x'));
    check("$label: an http image and a data: image are dropped, an https one stays",
          $c('img-http') === '' && $c('img-data') === '' && $c('img-js') === ''
          && str_contains(htmlSafeClean('<img src="https://ok.example/a.png" alt="A">'), 'src="https://ok.example/a.png"'));
    check("$label: srcset and the other image attributes are gone", !str_contains($c('img-srcset'), 'srcset') && !str_contains($c('img-dynsrc'), 'src'), $c('img-srcset'));
    check("$label: title, textarea, template and the like leave nothing of their insides",
          !str_contains($c('title'), 'alert') && !str_contains($c('textarea'), 'alert') && !str_contains($c('template'), 'alert')
          && str_contains($c('template'), 'after') && !str_contains($c('xmp'), 'alert') && !str_contains($c('noembed'), 'alert'));
    check("$label: base, meta, link and style give the page nothing", trim(strip_tags($c('base-meta'))) === 'x' && !str_contains($c('base-meta'), 'meta')
          && trim(strip_tags($c('link-css'))) === 'x' && trim(strip_tags($c('style-block'))) === 'x');
    check("$label: a form takes its button and input with it", !str_contains($c('form-formaction'), 'formaction') && trim(strip_tags($c('form-formaction'))) === '');
    check("$label: xlink:href and xml:base are not attributes of ours", !str_contains($c('xlink-href'), 'xlink') && !str_contains($c('xlink-href'), 'xml:'), $c('xlink-href'));
    check("$label: ping, download and target=_self are gone; target=_blank and rel are forced",
          str_contains($c('a-ping'), 'target="_blank" rel="nofollow noopener noreferrer"') && !str_contains($c('a-ping'), 'ping')
          && !str_contains($c('a-ping'), 'download') && !str_contains($c('a-ping'), '_self') && !str_contains($c('a-ping'), 'opener"'), $c('a-ping'));
    check("$label: a link keeps its address and gets the forced rel and target",
          htmlSafeClean('<A HREF="HTTPS://OK.EXAMPLE/p?a=1&b=2">go</A>') === '<a href="HTTPS://OK.EXAMPLE/p?a=1&amp;b=2" target="_blank" rel="nofollow noopener noreferrer">go</a>',
          htmlSafeClean('<A HREF="HTTPS://OK.EXAMPLE/p?a=1&b=2">go</A>'));
    check("$label: mailto: is a link", str_contains(htmlSafeClean('<a href="mailto:me@example.com">me</a>'), 'href="mailto:me@example.com"'));
    check("$label: a break-out of an attribute stays text inside it", !str_contains($c('attr-breakout'), 'onmouseover=') || str_contains($c('attr-breakout'), '&quot; onmouseover=&quot;'), $c('attr-breakout'));
    check("$label: html / head / body attributes are not carried", !str_contains($c('html-attrs'), 'onload') && !str_contains($c('html-attrs'), 'xmlns') && str_contains($c('html-attrs'), '<p>x</p>'), $c('html-attrs'));
    check("$label: bytes that are not UTF-8 are replaced, not passed on", hsSafe($c('bad-utf8')) === '' && mb_check_encoding($c('bad-utf8'), 'UTF-8') && !str_contains($c('bad-utf8'), 'alert'), $c('bad-utf8'));

    /* ══ 3. CSS: what stays, what goes, what the site clamps ══════════════════════════════════════ */
    $st = fn(string $css, string $for = 'mail') => htmlSafeStyle($css, $for);
    check("$label: a plain style is kept as it was, normalised", $st('Color: #FF0000 ; font-size:16PX;font-weight:bold') === 'color:#ff0000;font-size:16px;font-weight:bold', $st('Color: #FF0000 ; font-size:16PX;font-weight:bold'));
    check("$label: colours: names, hex, rgb() and hsla() are kept; a word that only looks like one is not",
          $st('color:red') === 'color:red' && $st('background-color:rgb(1, 2, 3)') === 'background-color:rgb(1, 2, 3)'
          && $st('color:hsla(120,50%,50%,0.5)') === 'color:hsla(120,50%,50%,0.5)' && $st('color:#fff') === 'color:#fff'
          && $st('color:expression') === '' && $st('color:redx') === '' && $st('color:#ff') === '');
    check("$label: url(), image-set(), var(), env(), attr(), calc(), an escape, a comment and an at-rule give no declaration",
          $st('background:url(https://e/x)') === '' && $st('background-image:url(x)') === '' && $st('background:image-set(a 1x)') === ''
          && $st('color:var(--x)') === '' && $st('width:env(x)') === '' && $st('width:calc(1px + 2px)') === '' && $st('color:\\72 ed') === ''
          && $st('color:red/**/') === '' && $st('@import "x"') === '' && $st('behavior:url(x)') === '' && $st('-moz-binding:url(x)') === '');
    check("$label: position, z-index, float, overflow, opacity and any unknown property never pass",
          $st('position:fixed') === '' && $st('z-index:9') === '' && $st('float:left') === '' && $st('overflow:auto') === ''
          && $st('opacity:0') === '' && $st('top:0') === '' && $st('mso-line-height-rule:exactly') === '' && $st('transform:scale(9)') === '');
    check("$label: !important is stripped, not obeyed", $st('color:red !important') === 'color:red' && $st('color:red!important') === 'color:red');
    check("$label: lengths: units px, em, rem, pt and %; unitless non-zero, vw and huge numbers are refused",
          $st('width:600px') === 'width:600px' && $st('width:50%') === 'width:50%' && $st('width:0') === 'width:0' && $st('width:12') === ''
          && $st('width:10vw') === '' && $st('width:99999px') === '' && $st('padding:0 10px 5px') === 'padding:0 10px 5px'
          && $st('padding:-1px') === '' && $st('margin:0 auto') === 'margin:0 auto');
    check("$label: border shorthand in any order, and its refusals",
          $st('border:1px solid #ccc') === 'border:1px solid #ccc' && $st('border:#ccc solid 2px') === 'border:2px solid #ccc'
          && $st('border:none') === 'border:none' && $st('border:1px solid url(x)') === '' && $st('border:1px 2px') === ''
          && $st('border-radius:4px 4px 0 0') === 'border-radius:4px 4px 0 0' && $st('border-collapse:collapse') === 'border-collapse:collapse');
    check("$label: font-family is rewritten from its names, quotes of our own", $st("font-family: 'Segoe UI', Arial, \"Helvetica Neue\", sans-serif") === "font-family:'Segoe UI',Arial,'Helvetica Neue',sans-serif",
          $st("font-family: 'Segoe UI', Arial, \"Helvetica Neue\", sans-serif"));
    check("$label: a font-family carrying a break-out has only its clean names left, and the rest of it no declaration",
          $st("font-family:'a;background:url(x)'") === '' && $st('font-family:Arial, x");color:red') === 'font-family:Arial;color:red'
          && $st('font-family:"x" onload=alert(1)') === '', $st('font-family:Arial, x");color:red'));
    check("$label: line-height as a multiplier or a length, text-align and text-decoration from their sets",
          $st('line-height:1.5') === 'line-height:1.5' && $st('line-height:20px') === 'line-height:20px' && $st('line-height:9') === ''
          && $st('text-align:center') === 'text-align:center' && $st('text-align:evil') === '' && $st('text-decoration:underline line-through') === 'text-decoration:underline line-through');
    check("$label: display: none is a mail's, never the site's", $st('display:none') === 'display:none' && $st('display:none', 'site') === '' && $st('display:block', 'site') === 'display:block');
    check("$label: a negative margin is a mail's, never the site's", $st('margin:-10px') === 'margin:-10px' && $st('margin:-10px', 'site') === '' && $st('margin-top:-1em', 'site') === '');
    check("$label: the site clamps font-size to 32px / 2em / 200%", $st('font-size:99px', 'site') === 'font-size:32px' && $st('font-size:5em', 'site') === 'font-size:2em'
          && $st('font-size:500%', 'site') === 'font-size:200%' && $st('font-size:18px', 'site') === 'font-size:18px' && $st('font-size:99px') === 'font-size:99px');
    check("$label: the same through a whole fragment", str_contains(htmlSafeClean('<p style="font-size:99px;margin:-9px;display:none;color:red">x</p>', 'site'), 'style="font-size:32px;color:red"'),
          htmlSafeClean('<p style="font-size:99px;margin:-9px;display:none;color:red">x</p>', 'site'));

    /* ══ 4. bounds ════════════════════════════════════════════════════════════════════════════════ */
    $t0 = microtime(true);
    $deep = htmlSafeClean(str_repeat('<div>', 5000) . 'deep' . str_repeat('</div>', 5000));
    $maxDepth = 0; $d = 0;
    preg_match_all('#</?div>#', $deep, $dm);
    foreach ($dm[0] as $tg) { $d += $tg === '<div>' ? 1 : -1; $maxDepth = max($maxDepth, $d); }
    check("$label: 5000 nested divs: at most " . HTMLSAFE_MAX_DEPTH . ' levels come out, balanced, and quickly', $maxDepth <= HTMLSAFE_MAX_DEPTH && $d === 0 && hsSafe($deep) === '' && microtime(true) - $t0 < 5,
          "depth $maxDepth, balance $d, " . round(microtime(true) - $t0, 2) . 's');
    $t0 = microtime(true);
    $nest = htmlSafeClean(str_repeat('<span><b><i>', 10000));
    check("$label: 30000 unclosed inline tags are bounded as well", hsSafe($nest) === '' && microtime(true) - $t0 < 5, round(microtime(true) - $t0, 2) . 's');
    $big = htmlSafeClean('<p>' . str_repeat('é', 70000) . '</p>');
    check("$label: more than " . HTMLSAFE_MAX_BYTES . ' bytes in is cut to a whole character', strlen($big) <= HTMLSAFE_MAX_BYTES + 20 && mb_check_encoding($big, 'UTF-8') && hsSafe($big) === '', (string)strlen($big));
    check("$label: nothing in, nothing out", htmlSafeClean('') === '' && htmlSafeClean("  \n ") === '' && htmlSafeClean('<!-- only a comment -->') === '');
    check("$label: plain text is escaped text", htmlSafeClean('a < b & c > d "q" \'s\'') === 'a &lt; b &amp; c &gt; d &quot;q&quot; &#039;s&#039;', htmlSafeClean('a < b & c > d "q" \'s\''));

    // Nested layout tables (review 1.75.0, M1): the HTML5 parser puts a <tbody> in every table and it, with the row, used to be
    // counted — eight nested tables then lost what was inside them, without a word. A table now costs two levels.
    $tbl = fn(int $n): string => '<p>TOP</p>' . str_repeat('<table><tr><td>', $n) . '<p>DEEP <a href="https://x.example/">cta</a></p>'
        . '<script>bad()</script><style>.s{}</style><div>second</div>' . str_repeat('</td></tr></table>', $n) . '<p>BOTTOM</p>';
    foreach ([10, 20, 30] as $nTables) {
        $o = htmlSafeClean($tbl($nTables), 'mail', $rep);
        check("$label: $nTables nested layout tables come through whole - words, link and structure - and nothing is reported as flattened",
              str_contains($o, 'DEEP') && str_contains($o, '<a href="https://x.example/"') && str_contains($o, 'second') && str_contains($o, 'BOTTOM')
              && substr_count($o, '<table') === $nTables && substr_count($o, '<table') === substr_count($o, '</table>') && ($rep['flattened'] ?? -1) === 0 && hsSafe($o) === '',
              'tables ' . substr_count($o, '<table') . ' flattened ' . json_encode($rep));
        $o = htmlSafeClean($tbl($nTables), 'site');
        check("$label / site: and for the site's frame as well", str_contains($o, 'DEEP') && str_contains($o, 'cta') && substr_count($o, '<table') === $nTables);
    }
    $o = htmlSafeClean($tbl(45), 'mail', $rep);
    check("$label: 45 nested tables: past the bound the tags go and the WORDS stay (the link is its text, a script's insides are not words), the count says so",
          str_contains($o, 'DEEP') && str_contains($o, 'cta') && str_contains($o, 'second') && str_contains($o, 'BOTTOM') && !str_contains($o, 'bad()') && !str_contains($o, '.s{}')
          && substr_count($o, '<table') < 45 && substr_count($o, '<table') === substr_count($o, '</table>') && ($rep['flattened'] ?? 0) >= 1 && hsSafe($o) === '' && !str_contains($o, '<a '),
          json_encode($rep) . ' ' . substr($o, -300));
    $o = htmlSafeClean(str_repeat('<font>', 100) . 'inside', 'mail', $rep);
    check("$label: a chain of unwrapped tags (100 <font>) is bounded too, and its words stay", $o === 'inside' && ($rep['flattened'] ?? 0) === 1, $o . json_encode($rep));
    if ($engine === '') {   // (libxml2's own parser gives up at 256 levels and keeps nothing of what is below; the HTML5 parser does not)
        $o = htmlSafeClean(str_repeat('<font>', 9000) . 'inside', 'mail', $rep);
        check("$label: … and 9000 of them", $o === 'inside' && ($rep['flattened'] ?? 0) === 1, $o . json_encode($rep));
    }
    $o = htmlSafeClean('<p>keep</p><div>' . str_repeat('<div>', 100) . 'one<p>two</p><br>three' . str_repeat('</div>', 100) . '</div>', 'mail', $rep);
    check("$label: flattened words do not run together (blocks and breaks leave a space)", str_contains($o, 'one two three') && ($rep['flattened'] ?? 0) === 1, $o);
    // The output bound: a markup that grows when it is written out cannot make a megabyte (the read-time cost of a notification).
    $o = htmlSafeClean(str_repeat('<a href="https://x/">x', 2700), 'site', $rep);     // 59 KB in, three times that out
    $opens = preg_match_all('#<a #', $o); $closes = preg_match_all('#</a>#', $o);
    check("$label: the output is bounded (" . HTMLSAFE_MAX_OUT . " bytes), cut where it ends, and what is written is balanced", strlen($o) <= HTMLSAFE_MAX_OUT + 2000 && ($rep['cut'] ?? false) === true
          && $opens === $closes && hsSafe($o) === '', strlen($o) . ' ' . json_encode($rep));
    htmlSafeClean('<p>small</p>', 'mail', $rep);
    check("$label: an ordinary message reports nothing flattened and nothing cut", $rep === ['flattened' => 0, 'cut' => false], json_encode($rep));

    // Addresses (review L3): a blank inside is its percent-encoding, not deleted; the bypasses stay out.
    $u = fn(string $raw, bool $https = false) => htmlSafeUrl($raw, $https);
    check("$label: a space in an address becomes %20 (a mailto: subject, a folder, a query), the ends are trimmed",
          $u('mailto:a@b.example?subject=Hello World') === 'mailto:a@b.example?subject=Hello%20World' && $u('https://e.example/a b/c?q=x y') === 'https://e.example/a%20b/c?q=x%20y'
          && $u("  https://e.example/p  ") === 'https://e.example/p', (string)$u('mailto:a@b.example?subject=Hello World'));
    check("$label: other blanks (no-break space, ideographic space) are percent-encoded, a tab / newline / control character is deleted as a browser does",
          $u("https://e.example/a\u{00A0}b") === 'https://e.example/a%C2%A0b' && $u("https://e.example/a\u{3000}b") === 'https://e.example/a%E3%80%80b'
          && $u("https://e.example/a\tb\nc\x01d") === 'https://e.example/abcd');
    check("$label: and a space or a tab in the scheme never makes a link",
          $u('java script:alert(1)') === null && $u("java\tscript:alert(1)") === null && $u('javascript :alert(1)') === null && $u(' javascript:alert(1)') === null
          && $u("java\x01script:alert(1)") === null && $u('https://exa mple.example/') === null && $u('https:// e.example/') === null);
    $o = htmlSafeClean('<a href="mailto:a@b.example?subject=Hello World">m</a><a href="https://e.example/a b/c">u</a>', 'mail');
    check("$label: through a whole fragment", str_contains($o, 'href="mailto:a@b.example?subject=Hello%20World"') && str_contains($o, 'href="https://e.example/a%20b/c"') && hsSafe($o) === '', $o);

    // <pre> (review L5): kept, with its lines and spaces; a text that begins with a line break survives a second reading.
    $o = htmlSafeClean("<pre>  a\n  b</pre>", 'mail');
    check("$label: <pre> keeps its lines and spaces", $o === "<pre>  a\n  b</pre>", $o);
    $o = htmlSafeClean("<pre>\n\nx</pre>", 'mail');
    // (libxml2's older parser does not eat the line break after <pre> the way the HTML5 parser — and every browser — does; only the HTML5 one is exact)
    check("$label: a <pre> whose text begins with a line break keeps it (the parser eats one after the tag, so one more is written)",
          $engine === '' ? ($o === "<pre>\n\nx</pre>" && htmlSafeClean($o, 'mail') === $o) : (str_starts_with($o, "<pre>\n") && hsSafe($o) === ''), json_encode($o));
    check("$label: and the text half of a mail keeps the lines of a <pre> (htmlToText)", htmlToText(htmlSafeClean("<p>code:</p><pre>  a\n  b</pre>", 'mail')) === "code:\n\n  a\n  b", json_encode(htmlToText(htmlSafeClean("<p>code:</p><pre>  a\n  b</pre>", 'mail'))));

    /* ══ 5. shuffled hostile pieces ═══════════════════════════════════════════════════════════════ */
    mt_srand(1750);
    $badFuzz = [];
    $t0 = microtime(true);
    for ($i = 0; $i < 1500; $i++) {
        $doc = '';
        $count = mt_rand(2, 9);
        for ($k = 0; $k < $count; $k++) $doc .= $pieces[mt_rand(0, count($pieces) - 1)];
        foreach (['mail', 'site'] as $for) {
            $out = htmlSafeClean($doc, $for);
            $why = hsSafe($out);
            if ($why !== '') { $badFuzz[] = $why . ' <= ' . substr($doc, 0, 160); break 2; }
            $again = htmlSafeClean($out, $for);
            $m2 = hsMutation($out, $again);
            if ($m2 !== '') { $badFuzz[] = 'mutation: [' . $out . '] => [' . $again . '] ' . $m2 . ' <= ' . substr($doc, 0, 160); break 2; }
        }
    }
    check("$label: 1500 documents shuffled from the payloads' pieces: every output inside the grammar, and read twice it changes no text and invents no attribute", !$badFuzz, implode(' || ', $badFuzz));
    check("$label: … in reasonable time", microtime(true) - $t0 < 60, round(microtime(true) - $t0, 1) . 's');
}
$GLOBALS['__htmlsafe_engine'] = '';

/* ══ 6. a realistic table-layout campaign must come through whole ═════════════════════════════════ */
$campaign = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Spring sale</title>
<style>.btn { background: #ff0066; } @media (max-width:600px) { .wrap { width: 100% !important; } }</style></head>
<body style="margin:0;padding:0;background-color:#f4f4f4" bgcolor="#f4f4f4" onload="x()">
<!--[if mso]><table><tr><td><![endif]-->
<center>
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" align="center" class="wrap" style="max-width:600px;background-color:#ffffff;border:1px solid #dddddd">
<tr><td align="center" valign="top" style="padding:20px;background-color:#222222"><a href="https://shop.example.com/?utm_source=mail&amp;utm_campaign=spring"><img src="https://cdn.example.com/logo.png" width="200" height="50" alt="Shop logo" style="display:block;border:0"></a></td></tr>
<tr><td style="padding:24px 30px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#333333">
<h1 style="margin:0 0 12px;font-size:26px;color:#111111">Spring sale &mdash; 30% off</h1>
<p style="margin:0 0 16px">Hello! This week only, <strong>everything</strong> is <em>cheaper</em>. See <a href="https://shop.example.com/sale" style="color:#ff0066;text-decoration:underline">the offers</a>.</p>
<ul style="margin:0 0 16px 20px;padding:0"><li>Free delivery</li><li>Free returns</li></ul>
<hr style="border:0;border-top:1px solid #cccccc">
<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="#ff0066" style="padding:12px 24px;border-radius:4px"><a href="https://shop.example.com/sale" style="color:#ffffff;font-weight:bold;text-decoration:none">Shop now</a></td></tr></table>
<table width="100%"><tr><td colspan="2" align="right" style="font-size:12px;color:#888888">Price list</td></tr><tr><td>One</td><td rowspan="1">Two</td></tr></table>
</td></tr>
</table>
</center>
<!--[if mso]></td></tr></table><![endif]-->
<script>document.write('<img src=//evil.example/p.gif>')</script>
</body></html>
HTML;
foreach ($engines as $engine) {
    $GLOBALS['__htmlsafe_engine'] = $engine;
    $label = $engine === '' ? 'HTML5 parser' : 'libxml parser';
    $o = htmlSafeClean($campaign, 'mail');
    check("$label: the campaign is inside the grammar", hsSafe($o) === '', hsSafe($o));
    // Attributes are written in OUR order, not the author's, and the HTML5 parser adds the <tbody> a browser would:
    // so the layout is asserted by its pieces, each one a fragment that does not depend on either.
    $want = [
        '<center>', 'width="600"', 'cellpadding="0"', 'cellspacing="0"', 'border="0"', 'bgcolor="#ffffff"', 'align="center"',
        'style="max-width:600px;background-color:#ffffff;border:1px solid #dddddd"', 'valign="top"',
        'style="padding:20px;background-color:#222222"', '<img src="https://cdn.example.com/logo.png" alt="Shop logo" width="200" height="50" style="display:block;border:0">',
        'href="https://shop.example.com/?utm_source=mail&amp;utm_campaign=spring"', '<h1 style="margin:0 0 12px;font-size:26px;color:#111111">',
        'style="padding:24px 30px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#333333"', '<strong>everything</strong>', '<em>cheaper</em>',
        '<ul style="margin:0 0 16px 20px;padding:0"><li>Free delivery</li><li>Free returns</li></ul>', '<hr style="border:0;border-top:1px solid #cccccc">',
        'bgcolor="#ff0066"', 'style="padding:12px 24px;border-radius:4px"', 'style="color:#ffffff;font-weight:bold;text-decoration:none"', 'colspan="2"', 'align="right"', 'rowspan="1"',
        'rel="nofollow noopener noreferrer"', 'Shop now', 'Price list', '<td>One</td>', '<td rowspan="1">Two</td>',
        'style="color:#ff0066;text-decoration:underline"', 'Spring sale &mdash;' === '' ? '' : 'Spring sale — 30% off',
    ];
    $missing = array_values(array_filter($want, fn($w) => !str_contains($o, $w)));
    check("$label: …and everything a layout needs is still there (tables, widths, bgcolor, valign, colspan, inline CSS, links, image)", !$missing, implode(' | ', $missing) . "\n" . $o);
    check("$label: …and none of the head, the script, the class, the role and the handler is", !str_contains($o, '<style') && !str_contains($o, 'script') && !str_contains($o, 'class=')
          && !str_contains($o, 'role=') && !str_contains($o, 'onload') && !str_contains($o, 'evil.example') && !str_contains($o, '@media') && !str_contains($o, 'Spring sale</title'), $o);
    check("$label: …a table opened is a table closed", substr_count($o, '<table') === substr_count($o, '</table>') && substr_count($o, '<td') === substr_count($o, '</td>')
          && substr_count($o, '<tr') === substr_count($o, '</tr>'));
    $site = htmlSafeClean($campaign, 'site');
    check("$label: for the site the same campaign passes the same grammar", hsSafe($site) === '' && str_contains($site, '<table width="600"'));
}
$GLOBALS['__htmlsafe_engine'] = '';

/* ══ 7. htmlToText ═════════════════════════════════════════════════════════════════════════════════ */
$txt = htmlToText('<h1>Title</h1><p>Hello <b>there</b>,<br>second&nbsp;line &amp; more.</p><ul><li>one</li><li>two</li></ul>'
    . '<p>See <a href="https://example.com/x?a=1&amp;b=2">the page</a> or <a href="https://example.com/">https://example.com/</a> or <a href="mailto:me@example.com">me@example.com</a>.</p>'
    . '<table><tr><td>A</td><td>B</td></tr><tr><td>C</td><td>D</td></tr></table><img src="https://x.example/a.png" alt="Logo"><hr><pre>code  here' . "\n" . '  indented</pre>'
    . '<script>alert(1)</script><style>p{}</style><!-- hidden -->   spaced    out   ');
check('htmlToText: blocks are lines, a paragraph is a blank line, entities are decoded',
      str_contains($txt, "Title\n\nHello there,\nsecond line & more.") && str_contains($txt, "- one\n- two"), $txt);
check('htmlToText: a link is "text (address)", unless the text is the address', str_contains($txt, 'the page (https://example.com/x?a=1&b=2)')
      && str_contains($txt, 'or https://example.com/ or') && str_contains($txt, 'or me@example.com.'), $txt);
check('htmlToText: table cells share a line, rows do not; an image is its alt', str_contains($txt, "A B\nC D") && str_contains($txt, '[Logo]'), $txt);
check('htmlToText: a <pre> keeps its lines and spaces; scripts, styles and comments are gone; whitespace is folded',
      str_contains($txt, "code  here\n  indented")
      && !str_contains($txt, 'alert') && !str_contains($txt, 'p{}') && !str_contains($txt, 'hidden') && str_contains($txt, 'spaced out'), $txt);
check('htmlToText: nothing is left of a tag', !preg_match('/<[a-z\/]/i', $txt) && !preg_match('/[\x01-\x05]/', $txt), $txt);
check('htmlToText: the old mailPlain case — <br> is a new line, every other tag goes, entities are decoded',
      htmlToText('Hello<br>you &amp; <strong>me</strong>') === "Hello\nyou & me");
check('htmlToText: empty and plain', htmlToText('') === '' && htmlToText('just words') === 'just words');

echo "\n" . $n . ' checks, ' . $fails . " failed\n";
exit($fails ? 1 : 0);
