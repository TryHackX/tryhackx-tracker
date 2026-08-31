<?php
/**
 * Test for includes/richtext.php:
 *   php tests/richtext_test.php
 *
 * This renderer takes text written by anonymous strangers and puts it on a public page. That makes
 * it the highest-risk file in the project, and the risk is not subtle: one unescaped angle bracket
 * and a description becomes a script running in an administrator's session.
 *
 * So the tests here are mostly attacks. The passing ones are the point — every case below is a real
 * technique, and each is checked against OUTPUT rather than against the rule that was meant to stop
 * it, because a rule can be right and still be reached too late.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/richtext.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$cfg = [
    'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1',
    'desc_max_chars' => '4000', 'desc_max_images' => '3', 'desc_max_links' => '10',
    'link_trusted_domains' => 'tryhackx.org',
];

/* ── 1. nothing the author writes may become HTML ─────────────────────────── */

$attacks = [
    '<script>alert(1)</script>',
    '<img src=x onerror=alert(1)>',
    '<svg/onload=alert(1)>',
    '<iframe src="https://evil.example"></iframe>',
    '<a href="javascript:alert(1)">x</a>',
    '<style>body{display:none}</style>',
    '"><script>alert(1)</script>',
    "<img src='x' onerror='alert(1)'>",
    '<body onload=alert(1)>',
    '<meta http-equiv="refresh" content="0;url=https://evil.example">',
    '<object data="https://evil.example"></object>',
    '<form action="https://evil.example"><input name=p></form>',
    '<!--[if IE]><script>alert(1)</script><![endif]-->',
    '<math><mtext><script>alert(1)</script></mtext></math>',
];
$leaked = [];
foreach (['bbcode', 'markdown'] as $fmt) {
    foreach ($attacks as $a) {
        $out = richtextRender($a, $fmt, $cfg);
        // The only tags allowed out are the ones this file writes. Anything else that survived as a
        // real tag is a hole, so the check is on the tag NAMES present, not on string matching.
        if (preg_match_all('/<\s*\/?\s*([a-zA-Z][a-zA-Z0-9]*)/', $out, $m)) {
            foreach ($m[1] as $tag) {
                if (!in_array(strtolower($tag), ['p', 'br', 'strong', 'em', 'u', 's', 'a', 'img',
                                                 'ul', 'li', 'blockquote', 'pre', 'code',
                                                 'h4', 'h5', 'h6'], true)) {
                    $leaked[] = $fmt . ': <' . $tag . '> from ' . mb_substr($a, 0, 40);
                }
            }
        }
        // An event handler only matters INSIDE a real tag. The word "onerror" sitting in the text
        // as `&lt;img src=x onerror=…&gt;` is the renderer working, not failing, and a check that
        // cannot tell those apart would have to be loosened later — at which point it stops
        // catching the real thing. So: look in the tags, not in the string.
        if (preg_match_all('/<[^>]*>/', $out, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/\son[a-z]+\s*=/i', $tag)) {
                    $leaked[] = $fmt . ': event handler in a real tag from ' . mb_substr($a, 0, 40);
                }
            }
        }
    }
}
check('not one of fourteen injection attempts produces a tag this file did not write',
      $leaked === [], implode(' | ', array_slice($leaked, 0, 4)));

/* ── 2. dangerous URLs never reach an href or a src ───────────────────────── */

$badUrls = [
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    "java\tscript:alert(1)",
    "java\nscript:alert(1)",
    'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
    ' javascript:alert(1)',
];
$bad = [];
foreach ($badUrls as $u) {
    if (richtextSafeUrl($u) !== null) $bad[] = 'safeUrl accepted ' . $u;
    foreach ([
        ['bbcode', '[url=' . $u . ']click[/url]'],
        ['bbcode', '[img]' . $u . '[/img]'],
        ['markdown', '[click](' . $u . ')'],
        ['markdown', '![x](' . $u . ')'],
    ] as [$fmt, $src]) {
        $out = richtextRender($src, $fmt, $cfg);
        if (preg_match('/(?:href|src)\s*=\s*"([^"]*)"/i', $out, $m)) {
            if (!preg_match('#^https?://#i', $m[1])) $bad[] = $fmt . ' emitted ' . $m[1];
        }
    }
}
check('a javascript:, data: or file: URL never becomes an href or a src',
      $bad === [], implode(' | ', array_slice($bad, 0, 4)));

check('an ordinary https link is accepted', richtextSafeUrl('https://example.org/a?b=1#c') !== null);
check('http is accepted for rendering (only the SOURCE field demands TLS)',
      richtextSafeUrl('http://example.org/a') !== null);

/* ── 3. code is code, not markup ──────────────────────────────────────────── */

$out = richtextRender('[code][b]not bold[/b] <b>nor this</b>[/code]', 'bbcode', $cfg);
check('BBCode inside [code] is shown, not interpreted',
      str_contains($out, '[b]not bold[/b]') && !str_contains($out, '<strong>'), $out);
check('… and HTML inside [code] is still escaped', str_contains($out, '&lt;b&gt;'), $out);

$out = richtextRender("```\n**not bold** <i>nor this</i>\n```", 'markdown', $cfg);
check('Markdown inside a fenced block is shown, not interpreted',
      str_contains($out, '**not bold**') && !str_contains($out, '<strong>'), $out);

$out = richtextRender('`[b]x[/b]`', 'markdown', $cfg);
check('an inline code span is left alone too', str_contains($out, '[b]x[/b]'), $out);

/* ── 4. the markup that IS meant to work ──────────────────────────────────── */

$b = richtextRender('[b]a[/b] [i]b[/i] [u]c[/u] [s]d[/s]', 'bbcode', $cfg);
check('BBCode emphasis renders',
      str_contains($b, '<strong>a</strong>') && str_contains($b, '<em>b</em>')
      && str_contains($b, '<u>c</u>') && str_contains($b, '<s>d</s>'), $b);

$m = richtextRender('**a** *b* ~~c~~', 'markdown', $cfg);
check('Markdown emphasis renders',
      str_contains($m, '<strong>a</strong>') && str_contains($m, '<em>b</em>')
      && str_contains($m, '<s>c</s>'), $m);

$l = richtextRender("[list][*]one[*]two[/list]", 'bbcode', $cfg);
check('a BBCode list becomes a list', substr_count($l, '<li>') === 2, $l);

$l = richtextRender("- one\n- two\n- three", 'markdown', $cfg);
check('a Markdown list becomes a list', substr_count($l, '<li>') === 3, $l);

$q = richtextRender('[quote]said[/quote]', 'bbcode', $cfg);
check('a quote becomes a blockquote', str_contains($q, '<blockquote'), $q);

/* ── 5. valid HTML structure, because a broken block breaks the page ──────── */

$out = richtextRender("text\n\n[code]x[/code]\n\nmore", 'bbcode', $cfg);
check('a code block is never nested inside a paragraph',
      !preg_match('#<p>\s*<pre#', $out), $out);
check('… and the text either side of it stays in paragraphs',
      substr_count($out, '<p>') === 2, $out);
$out = richtextRender("> quoted\n- a\n- b", 'markdown', $cfg);
check('a block never picks up a stray <br> from the line it ended on',
      !preg_match('#</blockquote>\s*<br>#', $out), $out);

/* ── 6. off-site links are marked, trusted ones are not ───────────────────── */

$ext = richtextRender('[url=https://evil.example/x]go[/url]', 'bbcode', $cfg);
check('an off-site link is marked so the page can warn before following it',
      str_contains($ext, 'data-external="1"'), $ext);
$int = richtextRender('[url=https://tryhackx.org/x]go[/url]', 'bbcode', $cfg);
check('a trusted domain is not marked', !str_contains($int, 'data-external'), $int);
$sub = richtextRender('[url=https://files.tryhackx.org/x]go[/url]', 'bbcode', $cfg);
check('… and a subdomain of a trusted domain counts as trusted',
      !str_contains($sub, 'data-external'), $sub);
check('every rendered link carries rel="nofollow noopener noreferrer ugc"',
      str_contains($ext, 'nofollow') && str_contains($ext, 'noopener')
      && str_contains($ext, 'noreferrer') && str_contains($ext, 'ugc'), $ext);
check('an image never leaks the referrer to the host serving it',
      str_contains(richtextRender('[img]https://x.example/a.png[/img]', 'bbcode', $cfg),
                   'referrerpolicy="no-referrer"'));

/* ── 7. the source-link rules ─────────────────────────────────────────────── */

check('an empty source link is allowed — the field is optional',
      richtextValidateSourceUrl('', $cfg) === null);
check('plain HTTP is refused, and the message says why',
      str_contains((string)richtextValidateSourceUrl('http://example.org/a', $cfg), 'TLS'));
check('a javascript: link is refused', richtextValidateSourceUrl('javascript:alert(1)', $cfg) !== null);
check('credentials in the URL are refused',
      richtextValidateSourceUrl('https://user:pw@example.org/a', $cfg) !== null);
check('localhost is refused', richtextValidateSourceUrl('https://localhost/a', $cfg) !== null);
check('a private address is refused', richtextValidateSourceUrl('https://192.168.1.10/a', $cfg) !== null);
check('a host with no domain is refused', richtextValidateSourceUrl('https://intranet/a', $cfg) !== null);
check('an ordinary https link is accepted',
      richtextValidateSourceUrl('https://example.org/torrent/123', $cfg) === null);

/* ── 8. limits ────────────────────────────────────────────────────────────── */

check('a description over the character limit is refused',
      richtextValidate(str_repeat('a', 4001), 'bbcode', $cfg) !== null);
check('one at the limit is accepted',
      richtextValidate(str_repeat('a', 4000), 'bbcode', $cfg) === null);
check('too many images are refused, counting both syntaxes',
      richtextValidate(str_repeat('[img]https://a.example/x.png[/img]', 4), 'bbcode', $cfg) !== null
      && richtextValidate(str_repeat('![x](https://a.example/x.png)', 4), 'markdown', $cfg) !== null);
check('a format the operator has switched off is refused',
      richtextValidate('x', 'markdown', ['desc_allow_markdown' => '0', 'desc_allow_bbcode' => '1']) !== null);
check('with both formats off, bbcode is still offered rather than nothing',
      richtextFormats(['desc_allow_bbcode' => '0', 'desc_allow_markdown' => '0']) === ['bbcode']);

/* ── 9. the excerpt is plain text ─────────────────────────────────────────── */

$ex = richtextExcerpt('[b]bold[/b] <script>x</script> and **more**');
check('an excerpt carries no markup and no HTML',
      !str_contains($ex, '<') && !str_contains($ex, '[b]') && !str_contains($ex, '**'), $ex);
check('a long excerpt is cut and marked', mb_strlen(richtextExcerpt(str_repeat('word ', 100), 60)) <= 60);


/* -- the link an importer recorded ---------------------------------------- */
//
// whitelist.source_ref never passes the review queue, so it may be published only when it points at
// the operator's own site. Everything else is somebody else's importer writing a link onto a public
// page, which is exactly what the queue exists to stop.

$auto = fn(?string $json) => richtextAutoSourceUrl($json, $cfg);

check('auto source: a link on a trusted domain is published',
      $auto('{"url":"https://tryhackx.org/d/12-thread","post_id":3}') === 'https://tryhackx.org/d/12-thread');
check('auto source: a subdomain of a trusted domain counts as trusted',
      $auto('{"url":"https://forum.tryhackx.org/d/12"}') === 'https://forum.tryhackx.org/d/12');
check("auto source: somebody else's domain is NOT published",
      $auto('{"url":"https://elsewhere.example/post/1"}') === null);
check('auto source: a javascript: URL is refused before the trust check',
      $auto('{"url":"javascript:alert(1)"}') === null);
check('auto source: plain http is refused like a typed link',
      $auto('{"url":"http://tryhackx.org/d/12"}') === null);
check('auto source: a ref with no url yields nothing',
      $auto('{"post_id":3,"discussion_id":9}') === null);
check('auto source: broken JSON yields nothing rather than an error',
      $auto('{not json') === null);
check('auto source: an empty ref yields nothing', $auto('') === null && $auto(null) === null);
check('auto source: credentials in the URL are refused',
      $auto('{"url":"https://user:pw@tryhackx.org/x"}') === null);


/* == [hide] must fail CLOSED ============================================== */
//
// Every case below leaked a secret to guests in the first version of the tag. The rule was one lazy
// match with an alternation on the closing tag, so an outer [hide] paired with an INNER [/hide] and
// everything after it was printed verbatim — beside a placeholder claiming it was hidden. None of
// these needs an attacker: they are what happens when somebody pastes a template from another forum.
//
// The assertion is on the OUTPUT containing the secret, never on the rule that was meant to stop it.

$hideCases = [
    'nested hide'                  => '[hide]a[hide]b[/hide] SECRET-TOKEN[/hide]',
    'hide closed by postshide'     => '[hide]a[postshide]b[/postshide] SECRET-TOKEN[/hide]',
    'stray closing tag'            => '[hide]a[/hide] SECRET-TOKEN[/hide]',
    'closer swallowed by [code]'   => '[hide]password SECRET-TOKEN[code]x[/hide][/code]',
    'opened and never closed'      => '[hide]a SECRET-TOKEN',
    'closer with no opener'        => 'SECRET-TOKEN [/hide]',
    'mixed case tags'              => '[HIDE]a[/HIDE] SECRET-TOKEN[/hide]',
    'hide with a title'            => '[hide=Title]SECRET-TOKEN[/hide]',
    'hide inside a quote'          => '[quote][hide]a[/hide] SECRET-TOKEN[/hide][/quote]',
];
foreach ($hideCases as $name => $src) {
    $out = richtextRender($src, 'bbcode', $cfg, false);
    check("guest never sees the secret: $name", strpos($out, 'SECRET-TOKEN') === false, $out);
}

// …and the feature still works when the tags ARE balanced.
$ok = richtextRender('before [hide]members only[/hide] after', 'bbcode', $cfg, false);
check('a well-formed hide keeps the surrounding text visible',
      strpos($ok, 'before') !== false && strpos($ok, 'after') !== false
      && strpos($ok, 'members only') === false, $ok);
$in = richtextRender('[hide]a[hide]b[/hide]c[/hide]', 'bbcode', $cfg, true);
check('a signed-in reader sees nested hidden content, with no tag text left over',
      strpos($in, 'a') !== false && strpos($in, 'b') !== false && strpos($in, 'c') !== false
      && strpos($in, '[hide') === false, $in);

/* == the new tags, and the values they carry =============================== */

$bb = fn(string $t) => richtextRender($t, 'bbcode', $cfg, false);

check('[color] accepts a hex value', strpos($bb('[color=#e74c3c]x[/color]'), 'color:#e74c3c') !== false);
check('[color] accepts a named colour', strpos($bb('[color=blue]x[/color]'), 'color:blue') !== false);
check('[color] drops anything that is not a colour, keeping the text',
      strpos($bb('[color=expression(alert(1))]x[/color]'), 'expression') === false
      && strpos($bb('[color=expression(alert(1))]x[/color]'), 'x') !== false);
check('[color] cannot smuggle a second CSS property',
      strpos($bb('[color=red;background:url(//evil)]x[/color]'), 'background') === false);
check('[size] clamps rather than trusting the number',
      strpos($bb('[size=9999]x[/size]'), 'font-size:32px') !== false);
check('[size] understands percentages', strpos($bb('[size=150%]x[/size]'), 'font-size:24px') !== false);
check('[font] maps to a class and never echoes the name',
      strpos($bb('[font=Courier New]x[/font]'), 'rt-font-mono') !== false
      && strpos($bb('[font=evil;x:1]y[/font]'), 'evil') === false);
check('[table] builds only well-formed rows',
      strpos($bb('[table][tr][td]A[/td][/tr][/table]'), '<td>A</td>') !== false
      && strpos($bb('[table][tr][td]A[/tr][/table]'), '<td>') === false);
check('[quote=name] renders the author, without a leftover quote entity',
      strpos($bb('[quote="Jan"]x[/quote]'), '<cite class="rt-cite">Jan</cite>') !== false);
check('[youtube] emits a link and never an iframe',
      strpos($bb('[youtube]dQw4w9WgXcQ[/youtube]'), '<iframe') === false
      && strpos($bb('[youtube]dQw4w9WgXcQ[/youtube]'), 'youtube.com/watch?v=dQw4w9WgXcQ') !== false);
check('[youtube] refuses anything that is not a video id',
      strpos($bb('[youtube]../../etc/passwd[/youtube]'), 'youtube.com') === false);
check('[email] only accepts an address',
      strpos($bb('[email]a@b.pl[/email]'), 'mailto:a@b.pl') !== false
      && strpos($bb('[email]javascript:alert(1)[/email]'), 'mailto:') === false);
check('[img] renders an image again', strpos($bb('[img]https://e.org/x.png[/img]'), '<img') !== false);

$md = fn(string $t) => richtextRender($t, 'markdown', $cfg, false);
check('markdown tables need a separator row, so a line of pipes stays a line of pipes',
      strpos($md("| A | B |\n|---|---|\n| 1 | 2 |"), '<table') !== false
      && strpos($md('a | b | c'), '<table') === false);
check('task lists render as marks, not as form controls',
      strpos($md("- [x] done"), '<input') === false && strpos($md("- [x] done"), 'rt-task-done') !== false);
check('a callout keeps its kind', strpos($md("> [!WARNING]\n> careful"), 'rt-callout-warning') !== false);
check('emoji come from the fixed map only',
      strpos($md(':rocket:'), "\u{1F680}") !== false && strpos($md(':definitely_not_an_emoji:'), ':definitely_not_an_emoji:') !== false);
check('only the listed literal HTML tags survive, and only as a matched pair',
      strpos($md('<kbd>Ctrl</kbd>'), '<kbd>Ctrl</kbd>') !== false
      && strpos($md('<script>alert(1)</script>'), '<script') === false
      // the attribute form must stay TEXT: escaped is fine, a real tag is not
      && strpos($md('<kbd onclick="x">y</kbd>'), '<kbd onclick') === false
      && strpos($md('<kbd onclick="x">y</kbd>'), '</kbd>') === false);
// A block in the MIDDLE of a paragraph has to split it. `<p>a <div>b</div> c</p>` is invalid and a
// browser closes the <p> itself, leaving the tail orphaned and a gap nobody typed.
$midBlock = richtextRender('before [center]middle[/center] after [hr] end', 'bbcode', $cfg, false);
check('a block in mid-paragraph splits the paragraph instead of sitting inside it',
      preg_match('#<p>[^<]*<div#', $midBlock) === 0 && strpos($midBlock, '<p></p>') === false, $midBlock);

check('paragraph tags come out balanced',
      substr_count($md("| A |\n|---|\n| 1 |\n\ntext\n\nmore"), '<p>')
      === substr_count($md("| A |\n|---|\n| 1 |\n\ntext\n\nmore"), '</p>'));


/* == the renderer must never emit an unbalanced block tag ================= */
//
// An unclosed <div> or <details> does not merely look wrong: it swallows the rest of the page for
// every reader. Malformed input is the normal case here — people paste half a template — so this
// runs the whole zoo of broken markup and counts tags rather than eyeballing one example.

$broken = ['[center]a', '[center]a[/right]', '[/center]a', '[spoiler]a', '[spoiler=T]a', '[/spoiler]',
           '[table][tr][td]a', '[table]junk[/table]', '[quote]a', '[/quote]a', '[list][*]a',
           '[hide]a', '[hide]a[/hide]', '[center][spoiler][quote][table][tr][td]x',
           "> [!NOTE]
> a", "| a |
|---|", '[color=red]a', str_repeat('[center]', 30) . 'x'];
$blockTags = ['div', 'details', 'blockquote', 'table', 'ul', 'ol', 'p', 'summary', 'span', 'mark'];
$unbalanced = [];
foreach ([false, true] as $signedIn) {
    foreach (['bbcode', 'markdown'] as $fmt) {
        foreach ($broken as $src) {
            $h = richtextRender($src, $fmt, $cfg, $signedIn);
            foreach ($blockTags as $t) {
                // The opener needs a boundary, or <p> also counts <pre> and the totals come out wrong in a
                // way that reads exactly like a real imbalance. Written as a character class rather
                // than as a backslash escape: a tool in this session turned \b into a literal
                // backspace byte here, which is the trap HANDOFF.md already warns about.
                $opens  = preg_match_all('#<' . $t . '(?=[ >/])#i', $h);
                $closes = preg_match_all('#</' . $t . '>#i', $h);
                if ($opens !== $closes) {
                    $unbalanced[] = "$t $opens/$closes [$fmt] " . substr($src, 0, 24) . ' -> ' . substr($h, 0, 70);
                }
            }
        }
    }
}
check('no malformed input produces an unbalanced block tag',
      $unbalanced === [], implode(' | ', array_slice($unbalanced, 0, 4)));

// A visitor must not be able to hang the renderer with a pathological string.
$slow = [];
foreach ([['[b]', 4000], ['|', 4000], ['~', 4000], ['[hide]', 500], ['[center]', 2000]] as [$tok, $rep]) {
    $t0 = microtime(true);
    richtextRender(str_repeat($tok, $rep), 'bbcode', $cfg, false);
    $ms = (microtime(true) - $t0) * 1000;
    if ($ms > 2000) $slow[] = $tok . ' x' . $rep . ' = ' . round($ms) . 'ms';
}
check('pathological input does not hang the renderer', $slow === [], implode(', ', $slow));

/* == the limits must count what a reader actually gets ==================== */
//
// The old counter looked for [img] and [url] and the two Markdown shapes, so every link made another
// way — a pasted URL, [email], [youtube], one inside a table cell — was invisible to it. "At most 10
// links" let fifty through.
$mixed = 'https://a.org https://b.org [email]x@y.pl[/email] [youtube]dQw4w9WgXcQ[/youtube] [url=https://c.org]c[/url]';
$cnt = richtextCount($mixed, 'bbcode', $cfg);
check('every link the renderer makes is counted, whatever syntax made it', $cnt['links'] === 5, json_encode($cnt));
check('the link limit actually refuses a description that exceeds it',
      richtextValidate(str_repeat('https://x.org ', 20), 'bbcode', $cfg) !== null);
check('hidden content still counts against the limits',
      richtextCount('[hide]' . str_repeat('https://x.org ', 5) . '[/hide]', 'bbcode', $cfg)['links'] === 5);

/* == an excerpt must not publish what [hide] withheld ===================== */
check('an excerpt drops hidden blocks entirely',
      strpos(richtextExcerpt('visible [hide]SECRET-TOKEN[/hide] end'), 'SECRET-TOKEN') === false);
check('an unbalanced hide truncates the excerpt rather than exposing it',
      strpos(richtextExcerpt('visible [hide]SECRET-TOKEN'), 'SECRET-TOKEN') === false);
check('an ordinary excerpt still reads normally',
      richtextExcerpt('visible [hide]x[/hide] end') === 'visible end');

/* == the second attack round: [hide] ran too late ========================= */
//
// Every case below leaked to a guest because a pass that MOVES or CONSUMES text ran before the pass
// that decides what may be published. [hide] is now resolved on the raw input, first, and its bytes
// never enter the pipeline for a reader who may not see them.

$leakCases = [
    'footnote lifted out of the block' => ['markdown', "[hide]see[^a][/hide]\n\n[^a]: SECRET-TOKEN"],
    'footnote defined inside it'       => ['markdown', "[hide]see[^a]\n\n[^a]: SECRET-TOKEN\n[/hide]"],
    'greedy value-tag eats the opener' => ['bbcode',   '[color=x[hide]y]SECRET-TOKEN[/color]'],
    'code fence hides the tokens'      => ['bbcode',   '[code][hide]SECRET-TOKEN[/hide][/code]'],
    'img swallows the marker'          => ['bbcode',   '[img][hide]SECRET-TOKEN[/hide][/img]'],
    'table swallows the marker'        => ['bbcode',   '[table][hide]SECRET-TOKEN[/hide][/table]'],
    'link label eats both tokens'      => ['markdown', '[[hide]SECRET-TOKEN[/hide]](https://e.org)'],
    'lone closing tag'                 => ['bbcode',   'SECRET-TOKEN [/hide]'],
    'closer inside a code fence'       => ['bbcode',   '[hide]SECRET-TOKEN[code]x[/hide][/code]'],
];
foreach ($leakCases as $name => [$fmt, $src]) {
    $out = richtextRender($src, $fmt, $cfg, false);
    check("guest never sees the secret: $name", strpos($out, 'SECRET-TOKEN') === false, $out);
}
check('an excerpt of an unbalanced hide is empty, not truncated around the secret',
      richtextExcerpt('[hide]a[/hide] SECRET-TOKEN[/hide]') === ''
      && richtextExcerpt('SECRET-TOKEN [/hide]') === '');

// …and a member still gets the content, with the markup inside it rendered.
$inner = richtextRender('[hide][b]bold[/b] and [url=https://e.org]a link[/url][/hide]', 'bbcode', $cfg, true);
check('a member sees hidden content with its markup rendered',
      strpos($inner, '<strong>bold</strong>') !== false && strpos($inner, 'href="https://e.org"') !== false, $inner);
$nested = richtextRender('[hide]a[hide]b[/hide]c[/hide]', 'bbcode', $cfg, true);
check('nested hidden blocks nest as elements, not as text',
      substr_count($nested, 'class="rt-hide"') === 2 && strpos($nested, 'HIDE') === false
      && preg_match('#<p>[^<]*<div#', $nested) === 0, $nested);

/* == an attribute is text, and stays text ================================= */
//
// The Markdown image alt was inserted unescaped, so a rule that had already produced real markup put
// a raw `>` inside alt="…": the <img> closed early, the rest of the attribute fell into the document
// and the linkifier built an <a> inside what had been the src.

$altCases = [
    '![<kbd>x</kbd>](https://e.org/a.png)',
    '![||spoiler||](https://e.org/a.png)',
    '![**b**](https://e.org/a.png)',
    '![`code`](https://e.org/a.png)',
];
foreach ($altCases as $src) {
    $out = richtextRender($src, 'markdown', $cfg, false);
    check('the image alt cannot break out of its attribute: ' . substr($src, 0, 22),
          preg_match('#alt="[^"]*<#', $out) === 0 && substr_count($out, '<img') === 1
          && strpos($out, '<a href="<') === false, $out);
}
check('an allowed HTML tag still works in prose',
      strpos(richtextRender('press <kbd>Ctrl</kbd>', 'markdown', $cfg, false), '<kbd>Ctrl</kbd>') !== false);

/* == paragraphs must not be built by guessing ============================= */
//
// The old tidy-up inserted </p> before every block open and <p> after every close. That is wrong the
// moment a block opens INSIDE another block: a </p> appeared with no paragraph open, and a later
// pass read it as the closer of the <details> or <blockquote> around it.

$nestCases = [
    ['bbcode', '[spoiler=T][quote]q[/quote][list][*]a[/list][/spoiler]'],
    ['bbcode', '[center][spoiler]x[/spoiler][/center]'],
    ['bbcode', 'a[hide]b[/hide]c'],
    ['bbcode', '[b]before[center]mid[/center]after[/b]'],
    ['bbcode', '[url=https://e.org]l[center]x[/center]d[/url]'],
    ['markdown', "> [!NOTE]\n> a\n\n| A |\n|---|\n| 1 |"],
];
$bad = [];
foreach ($nestCases as [$fmt, $src]) {
    foreach ([false, true] as $who) {
        $h = richtextRender($src, $fmt, $cfg, $who);
        foreach (['p', 'div', 'details', 'blockquote', 'table', 'ul', 'ol', 'strong', 'em', 'a', 'span'] as $t) {
            if (preg_match_all('#<' . $t . '(?=[ >/])#i', $h) !== preg_match_all('#</' . $t . '>#i', $h)) {
                $bad[] = "$t in " . substr($src, 0, 26);
            }
        }
        if (preg_match('#<p>[^<]*<(?:div|table|details|blockquote|ul|ol|hr)#', $h)) {
            $bad[] = 'block inside <p> in ' . substr($src, 0, 26);
        }
    }
}
check('nesting stays valid and balanced for every shape', $bad === [], implode(' | ', array_slice($bad, 0, 4)));
check('an inline run interrupted by a block is closed and resumed, and a link is not duplicated',
      substr_count(richtextRender('[url=https://e.org]l[center]x[/center]d[/url]', 'bbcode', $cfg, false), '<a ') === 1);

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
