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
      richtextFormats(['desc_allow_bbcode' => '0', 'desc_allow_markdown' => '0', 'desc_allow_flarum' => '0']) === ['bbcode']);

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

/* ── the site's emotes and stickers, outside the room (1.70.0) ─────────────────
 *
 * emoteRenderHtml() (includes/emoji.php) is a stage AFTER the renderer, over its finished HTML, fed a map
 * of the store's approved, switched-on rows for the context (emoteMapFor(), includes/shout.php — the
 * database half is tests/shout_emotes_test.php). Here the map is handed in, so every rule is about the
 * OUTPUT: only a code the map names, only in text, never in an address, an attribute or a code block,
 * every attribute escaped, the address held to the room's image endpoint, and nothing that decides what
 * a description may contain (its picture limit, a mail) is touched by it. */
$emap = [
    'wave'   => ['id' => 7, 'code' => 'wave', 'name' => 'Wave', 'url' => '/api.php?endpoint=shout_emote&id=7&v=0a1b2c3d', 'w' => 64, 'h' => 64, 'sticker' => false, 'draw' => 'emote'],
    'party'  => ['id' => 8, 'code' => 'party', 'name' => 'Party', 'url' => '/api.php?endpoint=shout_emote&id=8&v=0a1b2c3e', 'w' => 128, 'h' => 128, 'sticker' => true, 'draw' => 'sticker'],
    'cake'   => ['id' => 9, 'code' => 'cake', 'name' => 'Cake', 'url' => 'https://t.example/sub/api.php?endpoint=shout_emote&id=9&v=ff', 'w' => 0, 'h' => 0, 'sticker' => true, 'draw' => 'small'],
    'evil'   => ['id' => 10, 'code' => 'evil', 'name' => '"><script>alert(1)</script>', 'url' => '/api.php?endpoint=shout_emote&id=10&v=aa', 'w' => 16, 'h' => 16, 'sticker' => false, 'draw' => 'emote'],
    'away'   => ['id' => 11, 'code' => 'away', 'name' => 'Away', 'url' => 'https://evil.example/x.svg', 'w' => 16, 'h' => 16, 'sticker' => false, 'draw' => 'emote'],
    'quote'  => ['id' => 12, 'code' => 'quote', 'name' => 'Q', 'url' => '/api.php?endpoint=shout_emote&id=12&v=aa" onerror="alert(1)', 'w' => 16, 'h' => 16, 'sticker' => false, 'draw' => 'emote'],
];
$er = fn(string $src, string $fmt = 'bbcode') => emoteRenderHtml(richtextRender($src, $fmt, $cfg, true), $emap);
$h1 = $er('hello :wave: world');
check('an emote in a sentence is an image of the room\'s endpoint, the sentence kept round it',
      str_contains($h1, 'hello <img class="rt-emote" src="/api.php?endpoint=shout_emote&amp;id=7&amp;v=0a1b2c3d" alt=":wave:" title="Wave" loading="lazy" decoding="async" width="64" height="64"> world'), $h1);
$h2 = $er(':party: and :cake:');
check('a sticker at the size its context gives it: .rt-sticker, and .rt-sticker-small where it is bounded',
      str_contains($h2, '<img class="rt-sticker" src="/api.php?endpoint=shout_emote&amp;id=8&amp;v=0a1b2c3e"')
      && str_contains($h2, '<img class="rt-sticker rt-sticker-small" src="https://t.example/sub/api.php?endpoint=shout_emote&amp;id=9&amp;v=ff"')
      && !preg_match('/id=9[^>]*width=/', $h2), $h2);
check('a code the map does not name, an upper-case one and a one-letter one stay the text they are',
      $er(':nobody: :WAVE: :w:') === richtextRender(':nobody: :WAVE: :w:', 'bbcode', $cfg, true));
$h3 = $er(':evil:');
check('every attribute escaped: a hostile name stays inside title, no tag of its own',
      str_contains($h3, 'title="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"') && !str_contains($h3, '<script'), $h3);
check('an address that is not the room\'s image endpoint — or would leave its attribute — is no picture: the token stays text',
      !str_contains($er(':away:'), '<img') && str_contains($er(':away:'), ':away:')
      && !str_contains($er(':quote:'), '<img') && !str_contains($er(':quote:'), 'onerror="'));
$hLink = $er('[url=https://e.org/x/:wave:/y]see[/url] [img]https://e.org/:wave:.png[/img]');
check('never inside an address: a link\'s href and a picture\'s src keep their bytes',
      str_contains($hLink, 'href="https://e.org/x/:wave:/y"') && str_contains($hLink, 'src="https://e.org/:wave:.png"') && substr_count($hLink, 'rt-emote') === 0, $hLink);
check('… while an emote as a link\'s words is a picture inside the link, as it is in a shout',
      preg_match('#<a [^>]*>\s*<img class="rt-emote"[^>]*>\s*</a>#', $er('[url=https://e.org]:wave:[/url]')) === 1, $er('[url=https://e.org]:wave:[/url]'));
$hCode = $er("[code]:wave:[/code] and [b]:wave:[/b]");
check('a code block keeps the token as typed; the words after it are drawn',
      str_contains($hCode, '<code>:wave:</code>') && substr_count($hCode, 'rt-emote') === 1, $hCode);
$hMd = $er("`:wave:` and <kbd>:wave:</kbd> and **:wave:**", 'markdown');
check('in Markdown too: inline code and <kbd> keep it, bold draws it',
      str_contains($hMd, '<code class="rt-inline">:wave:</code>') && str_contains($hMd, '<kbd>:wave:</kbd>') && substr_count($hMd, 'rt-emote') === 1, $hMd);
check('a line of nothing but an emote is still a paragraph with its picture in it',
      preg_match('#^<p><img class="rt-emote"[^>]*></p>$#', $er(':wave:')) === 1, $er(':wave:'));
check('the fixed :shortcode: emoji run first and stay characters: :fire: is the flame, never looked up',
      str_contains(emoteRenderHtml(richtextRender(':fire:', 'bbcode', $cfg), ['fire' => $emap['wave']]), "\u{1F525}")
      && !str_contains(emoteRenderHtml(richtextRender(':fire:', 'bbcode', $cfg), ['fire' => $emap['wave']]), '<img'));
check('a Font Awesome face and an emote side by side: each its own stage, neither eats the other',
      str_contains($er(':fa-face-grin: :wave:'), 'rt-emote') && !str_contains($er(':fa-face-grin: :wave:'), ':fa-face-grin:'));
check('an empty map, or no colon at all, is the renderer\'s HTML untouched (the cheap path)',
      emoteRenderHtml('<p>a :wave: b</p>', []) === '<p>a :wave: b</p>' && emoteRenderHtml('<p>no tokens</p>', $emap) === '<p>no tokens</p>');
check('the stage is outside the renderer: richtextRenderIn() without a database is richtextRender(), and the picture limit counts none of them',
      richtextRenderIn('message', null, 'x :wave: y', 'bbcode', $cfg) === richtextRender('x :wave: y', 'bbcode', $cfg)
      && richtextCount(':wave: :wave: :wave: :wave:', 'bbcode', $cfg)['images'] === 0
      && richtextValidate(':wave: :wave: :wave: :wave: :wave:', 'bbcode', $cfg) === null);
check('in an e-mail an emote is always its code (the mail renderer never draws them)',
      str_contains(richtextRenderForEmail('hi :wave:', 'bbcode', $cfg), ':wave:') && !str_contains(richtextRenderForEmail('hi :wave:', 'bbcode', $cfg), '<img'));
check('the token grammar: two to thirty-two of [a-z0-9_] between colons, nothing looser',
      EMOTE_TOKEN_RE === '/:([a-z0-9_]{2,32}):/' && preg_match(EMOTE_TOKEN_RE, ':fa-face-grin:') === 0 && preg_match(EMOTE_TOKEN_RE, ':a-b:') === 0);
check('the address rule: the room\'s endpoint under any base, and nothing else',
      emoteSafeUrl('api.php?endpoint=shout_emote&id=1&v=abc') && emoteSafeUrl('/t/api.php?endpoint=shout_emote&id=12&v=') && emoteSafeUrl('https://h.example/api.php?endpoint=shout_emote&id=3&v=0f')
      && !emoteSafeUrl('api.php?endpoint=shout_emote&id=0&v=a') && !emoteSafeUrl('api.php?endpoint=user_media&id=1&v=a') && !emoteSafeUrl('javascript:alert(1)//api.php?endpoint=shout_emote&id=1&v=a b')
      && !emoteSafeUrl("/api.php?endpoint=shout_emote&id=1&v=a'") && !emoteSafeUrl('/api.php?endpoint=shout_emote&id=1&v=a&x=1'));
// Read back through a DOM: whatever the input, the stage adds <img> with these attributes and nothing else.
$hostileAll = implode("\n", [':evil: :quote: :away:', '<img src=x onerror=alert(1)> :wave:', '[url=https://e.org/":wave:"]x[/url]',
                             '[color=red]:wave:[/color] [quote=":party:"]:cake:[/quote]', '[spoiler=:wave:]:wave:[/spoiler]']);
$hd = emoteRenderHtml(richtextRender($hostileAll, 'bbcode', $cfg, true), $emap);
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $hd . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
$imgBad = [];
$xp = new DOMXPath($dom);
foreach ($xp->query('//img') as $im) {
    foreach ($im->attributes as $at) if (!in_array($at->name, ['class', 'src', 'alt', 'title', 'loading', 'decoding', 'width', 'height', 'referrerpolicy'], true)) $imgBad[] = $at->name;
    if (!preg_match('#^(rt-img|rt-emote|rt-sticker( rt-sticker-small)?)$#', $im->getAttribute('class'))) $imgBad[] = 'class:' . $im->getAttribute('class');
}
foreach ($xp->query('//*') as $any) {
    if ($any->nodeName === 'script') $imgBad[] = 'script';
    foreach ($any->attributes as $at) if (str_starts_with(strtolower($at->name), 'on')) $imgBad[] = $any->nodeName . '@' . $at->name;
}
check('read through a DOM, hostile input yields pictures with only their own attributes and classes, and no element anywhere a script or a handler',
      $imgBad === [] && $xp->query('//img[@class="rt-emote"]')->length >= 3, implode(', ', $imgBad) . ' | ' . substr($hd, 0, 300));

$cfgF = array_merge($cfg, ['desc_allow_flarum' => '1', 'desc_max_images' => '20', 'desc_max_links' => '20']);
check('flarum: offered for descriptions only when switched on, and not where a place has no setting of its own',
      !in_array('flarum', richtextFormats(array_merge($cfg, ['desc_allow_flarum' => '0'])), true) && in_array('flarum', richtextFormats($cfgF), true)
      && in_array('flarum', richtextFormats($cfg), true)
      && !in_array('flarum', richtextFormats($cfgF, 'list'), true) && !in_array('flarum', richtextFormats($cfgF, 'report'), true)
      && !in_array('flarum', richtextFormats($cfgF, 'mail'), true) && !in_array('flarum', richtextFormats($cfgF, 'page'), true));
check('flarum: with BBCode and Markdown both off, BBCode is still offered beside it',
      richtextFormats(array_merge($cfgF, ['desc_allow_bbcode' => '0', 'desc_allow_markdown' => '0'])) === ['bbcode', 'flarum']);
check('flarum: the format has its own name for the editor', richtextFormatLabel('flarum') === 'Flarum' && richtextFormatLabel('x') === 'BBCode');
check('formats per place: each place reads its own setting, in one fixed order, and a place left with nothing it knows offers BBCode',
      richtextFormats(['rt_formats_message' => 'flarum'], 'message') === ['flarum']
      && richtextFormats(['rt_formats_page' => 'flarum,bbcode'], 'page') === ['bbcode', 'flarum']
      && richtextFormats(['rt_formats_list' => 'markdown', 'rt_formats_report' => 'flarum'], 'list') === ['markdown']
      && richtextFormats(['rt_formats_shout' => ''], 'shout') === ['bbcode']
      && richtextFormats(['rt_formats_mail' => 'html,plain,<b>'], 'mail') === ['bbcode']);
check('formats per place: a description keeps its three switches and reads no place\'s setting',
      richtextFormats(array_merge($cfgF, ['rt_formats_description' => 'markdown', 'rt_formats_message' => 'markdown'])) === ['bbcode', 'markdown', 'flarum']
      && richtextFormats(array_merge($cfgF, ['desc_allow_flarum' => '0', 'rt_formats_message' => 'flarum'])) === ['bbcode', 'markdown']);
check('formats per place: the validator asks the place it is given',
      richtextValidate('x', 'flarum', array_merge($cfg, ['rt_formats_message' => 'bbcode']), 'message') !== null
      && richtextValidate('x', 'flarum', array_merge($cfg, ['rt_formats_message' => 'bbcode,flarum']), 'message') === null
      && richtextValidate('x', 'flarum', array_merge($cfg, ['desc_allow_flarum' => '0', 'rt_formats_message' => 'flarum']), 'message') === null
      && richtextValidate('x', 'flarum', array_merge($cfg, ['desc_allow_flarum' => '0', 'rt_formats_message' => 'flarum'])) !== null);

$post = "[center]\n## Big Buck Bunny (2008) [1.2 GiB] [AVC] [Full]\n[/center]\n\n### Quick look:\n"
      . "[b]Title:[/b] Big Buck Bunny\n[b]Runtime:[/b] \\~10 min, [b]Type:[/b] WEB-DL\n"
      . "[magnet]magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=bbb&tr=udp%3A%2F%2Fx[/magnet]\n___\n\n"
      . "### Labels:\n[thxlabels=3][thxrow][img]https://example.org/a.jpg[/img][img]https://example.org/b.jpg[/img]"
      . "[img]https://example.org/c.jpg[/img][/thxrow][thxrow][img]https://example.org/d.jpg[/img][img]https://example.org/e.jpg[/img]"
      . "[/thxrow][/thxlabels]\n\n### Cast:\n[code]\nBunny as himself\n[/code]\n\n* [b]Files:[/b] 1\n* [b]Years:[/b] 2008\n\n---\n\n"
      . "### Preview:\n[center]https://www.youtube.com/watch?v=aqz-KE-bpKQ[/center]\nhttps://example.org/poster.png\n\n"
      . "### More labels:\n[thxlabels=1][thxrow][img]https://example.org/f.jpg[/img][/thxrow][/thxlabels]\n";
$h = richtextRender($post, 'flarum', $cfgF, true);
check('flarum: a heading inside [center] is a centred heading, its literal [brackets] kept as words',
      str_contains($h, '<div class="rt-center"><h4 class="rt-h">Big Buck Bunny (2008) [1.2 GiB] [AVC] [Full]</h4></div>'), substr($h, 0, 300));
check('flarum: ### is a heading and [b] is bold, side by side as on the forum',
      str_contains($h, '<h5 class="rt-h">Quick look:</h5>') && str_contains($h, '<strong>Title:</strong> Big Buck Bunny'));
check('flarum: \\~ is a plain tilde — no backslash and no subscript',
      str_contains($h, '~10 min') && !str_contains($h, '\\~') && !str_contains($h, '<sub>10'));
check('flarum: the magnet is a card with its name — no raw tag, no empty line where it stood',
      str_contains($h, '<div class="rt-magnet') && str_contains($h, '>bbb<') && !str_contains($h, '[magnet]') && !str_contains($h, 'WEB-DL<br><br>'), $h);
check('flarum: ___ and a --- after a blank line are rules', substr_count($h, '<hr class="rt-hr">') === 2, (string)substr_count($h, '<hr'));
check('flarum: label grids in two places, each its own block — three and two, then one',
      preg_match_all('/<div class="rt-labels rt-labels-c(\d)">/', $h, $lm) === 2 && $lm[1] === ['3', '1']
      && substr_count($h, '<div class="rt-labels-row">') === 3 && substr_count($h, 'class="rt-img rt-label"') === 6);
check('flarum: [code] keeps its lines, and lines of * [b]…[/b] are a list',
      str_contains($h, '<pre class="rt-code"><code>Bunny as himself</code></pre>')
      && str_contains($h, '<ul class="rt-list"><li><strong>Files:</strong> 1</li><li><strong>Years:</strong> 2008</li></ul>'));
check('flarum: a bare YouTube address is the YouTube link, a bare picture address is the picture',
      str_contains($h, 'class="rt-video"') && str_contains($h, 'watch?v=aqz-KE-bpKQ') && str_contains($h, 'src="https://example.org/poster.png"'));
$cnt = richtextCount($post, 'flarum', $cfgF);
check('flarum: the counters see seven pictures and two links (the magnet card is one)', $cnt === ['images' => 7, 'links' => 2], json_encode($cnt));
check('flarum: no line break is left hanging beside a block', !preg_match('#<div class="rt-center"><br>|</h[45]><br>|<br></div>#', $h), $h);
check('flarum: sixty such posts in one description render in one go',
      str_contains(richtextRender(str_repeat($post, 60), 'flarum', $cfgF, true), 'Quick look:'));

$md = "**b1** __b2__ *i1* _i2_ ~~gone~~ x^2 H~2O snake_case_word\n"
    . ">!secret!< and ||hush||\n\n>! block spoiler\n\n> quote\n> > nested\n\nSetext\n===\n\n"
    . "[site](https://example.org \"t\") ![pic](https://example.org/p.png) <https://example.org/x> [ref][1] [Full]\n\n"
    . "[1]: https://example.org/r\n\n@\"Some One\"#p12 said\n"
    . "[upl-image-preview uuid=1 url=https://example.org/u.png alt=up] [upl-file uuid=2 size=10KB]notes.txt[/upl-file]\n"
    . "[quote author=Ann]hi[/quote] [spoiler title=Plot]twist[/spoiler] [del]old[/del] [code=php]echo 1;[/code]\n"
    . "```js\nlet a = 1;\n```\n";
$m = richtextRender($md, 'flarum', $cfgF, true);
check('flarum: ** and __ are bold, * and _ italic, ~~ struck — and an underscore inside a word is part of it',
      str_contains($m, '<strong>b1</strong>') && str_contains($m, '<strong>b2</strong>') && str_contains($m, '<em>i1</em>')
      && str_contains($m, '<em>i2</em>') && str_contains($m, '<s>gone</s>') && str_contains($m, 'snake_case_word'), $m);
check('flarum: ^ and ~ raise and lower what follows, as the forum does', str_contains($m, 'x<sup>2</sup>') && str_contains($m, 'H<sub>2O</sub>'), $m);
check('flarum: >!…!< and ||…|| hide words inside a line, >! hides a block',
      substr_count($m, '<span class="rt-ispoiler" tabindex="0">') === 2 && str_contains($m, '<details class="rt-spoiler">'), $m);
check('flarum: > quotes, one inside another',
      str_contains($m, '<blockquote class="rt-quote">quote<blockquote class="rt-quote">nested</blockquote></blockquote>'), $m);
check('flarum: === under a line makes it a heading', str_contains($m, '<h4 class="rt-h">Setext</h4>'), $m);
check('flarum: links with a title, pictures, <address> and a reference resolve; a bracketed word with no reference stays words',
      str_contains($m, '>site</a>') && str_contains($m, 'src="https://example.org/p.png"') && str_contains($m, 'href="https://example.org/x"')
      && str_contains($m, 'href="https://example.org/r"') && str_contains($m, '[Full]') && !str_contains($m, '[1]: https'), $m);
check('flarum: a mention reads as the name', str_contains($m, '@Some One said') && !str_contains($m, '#p12'), $m);
check('flarum: an uploaded picture shows, an uploaded file is named',
      str_contains($m, 'src="https://example.org/u.png"') && str_contains($m, '<span class="rt-file">') && str_contains($m, 'notes.txt'), $m);
check('flarum: [quote author=], [spoiler title=], [del], [code=…] and a fenced block',
      str_contains($m, '<cite class="rt-cite">Ann</cite>') && str_contains($m, 'Plot</summary>') && str_contains($m, '<s>old</s>')
      && str_contains($m, '<code>echo 1;</code>') && str_contains($m, '<code>let a = 1;</code>'), $m);

$fAttacks = [
    '[img]javascript:alert(1)[/img]', '[img alt="x&quot; onerror=&quot;alert(1)"]https://e.org/a.png[/img]',
    '![x" onerror="alert(1)](https://e.org/a.png)', '[x](javascript:alert(1))', '<script>alert(1)</script>',
    '[upl-image-preview url=javascript:alert(1)]', '[upl-image-preview url="https://e.org/a.png" alt="\" onerror=\"alert(1)"]',
    '[upl-text-preview url=javascript:alert(1)]x[/upl-text-preview]',
    '[thxlabels=3"><script>alert(1)</script>][thxrow][img]https://e.org/a.png[/img][/thxrow][/thxlabels]',
    '[thxlabels=9][thxrow]<img src=x onerror=alert(1)>[/thxrow][/thxlabels]', '@"<img src=x onerror=alert(1)>"#p1',
    '[url=https://e.org/" onmouseover="alert(1)]x[/url]', '<https://e.org/"onmouseover="alert(1)>',
    "[r]: javascript:alert(1)\n[x][r]", '||<script>alert(1)</script>||', '>!<b onclick=alert(1)>x</b>!<',
    '\\<script\\>alert(1)\\</script\\>', 'https://e.org/x.png" onerror="alert(1)', '[img=javascript:alert(1)][/img]',
    '[quote author="<script>"]x[/quote]', '[spoiler title="<img src=x onerror=alert(1)>"]x[/spoiler]',
    "```\n<script>alert(1)</script>\n```", '`<img src=x onerror=alert(1)>`', '![a](https://e.org/a.png "<script>")',
];
$fAll = richtextRender(implode("\n\n", $fAttacks), 'flarum', $cfgF, true);
$fDom = new DOMDocument();
@$fDom->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $fAll . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
$fBad = [];
foreach ((new DOMXPath($fDom))->query('//*') as $node) {
    if (in_array($node->nodeName, ['script', 'iframe', 'object', 'embed', 'svg', 'style', 'form'], true)) $fBad[] = $node->nodeName;
    foreach ($node->attributes as $at) {
        $an = strtolower($at->name);
        if (str_starts_with($an, 'on') || $an === 'style') $fBad[] = $node->nodeName . '@' . $an;
        if (in_array($an, ['href', 'src'], true) && !preg_match('#^(https?://|mailto:|\#)#i', $at->value)) $fBad[] = $node->nodeName . '@' . $an . '=' . $at->value;
    }
}
check('flarum: read through a DOM, every attack is words — no script, no handler, no style, no address but http(s)', $fBad === [], implode(', ', $fBad));

$fSlow = [];
foreach ([['**', 4000], ['__', 4000], ['~', 4000], ['^', 4000], ['||', 4000], ['&gt;!', 2000], ['[thxlabels=3][thxrow]', 1000],
          ['[magnet]', 2000], ['`', 4000], ["___\n", 2000], ["> ", 2000], ['[x](', 2000], ['![', 2000], ['\\', 4000]] as [$tok, $rep]) {
    $t0 = microtime(true);
    $out = richtextRender(str_repeat($tok, $rep), 'flarum', $cfgF, false);
    $ms = (microtime(true) - $t0) * 1000;
    if ($ms > 2000 || $out === '') $fSlow[] = $tok . ' x' . $rep . ' = ' . round($ms) . 'ms' . ($out === '' ? ' (empty)' : '');
}
check('flarum: pathological input neither hangs the renderer nor empties the description', $fSlow === [], implode(', ', $fSlow));

$bb = richtextRender("[thxlabels=2][thxrow][img]https://example.org/a.jpg[/img][img]https://example.org/b.jpg[/img][/thxrow][/thxlabels]\n"
                   . "[magnet]magnet:?xt=urn:btih:abc[/magnet]", 'bbcode', $cfg, true);
check('bbcode: [thxlabels] is a label grid there too, and a [magnet] with no valid hash stays the words it is',
      str_contains($bb, '<div class="rt-labels rt-labels-c2"><div class="rt-labels-row">') && str_contains($bb, '[magnet]magnet:?xt=urn:btih:abc[/magnet]'), $bb);
check('excerpt: a forum post loses its magnet and its backslashes',
      !str_contains(richtextExcerpt($post, 400), 'magnet:') && !str_contains(richtextExcerpt($post, 400), '\\'), richtextExcerpt($post, 400));
$fMail = richtextRenderForEmail($post, 'flarum', $cfgF);
check('mail: the label grid keeps its shape as inline styles', str_contains($fMail, 'max-width:31%') && !str_contains($fMail, 'rt-labels'));

require_once $root . '/includes/schema.php';
$fStmts = implode("\n", array_map(fn($x) => is_array($x) ? implode("\n", $x) : (string)$x, trackerSchemaStatements()));
check('schema: every home of a description can store the flarum format',
      substr_count($fStmts, "`description_format` ENUM('markdown','bbcode','flarum')") >= 3
      && !str_contains($fStmts, "`description_format` ENUM('markdown','bbcode') "));
check('schema: the switch has a default, and it is on', (trackerSchemaDefaultSettings()['desc_allow_flarum'] ?? null) === '1');
require_once $root . '/includes/settings_catalog.php';
$rtfSave = (string)file_get_contents($root . '/api/admin/save_settings.php');
$rtfTpl = (string)file_get_contents($root . '/templates/admin/settings.php');
$rtfMissing = [];
foreach (richtextPlaces() as $rtfPlace) {
    $k = 'rt_formats_' . $rtfPlace;
    if ((trackerSchemaDefaultSettings()[$k] ?? null) !== 'bbcode,markdown,flarum') $rtfMissing[] = "$k default";
    if (!str_contains($rtfSave, "'$k'")) $rtfMissing[] = "$k save";
    if (!isset(settingsCatalogKeywords()[$k])) $rtfMissing[] = "$k catalogue";
}
check('formats per place: every place ships with all three on, is saved and is found by the search', $rtfMissing === [], implode(', ', $rtfMissing));
check('formats per place: a saved place keeps only the formats the renderer knows, in its order, and never nothing',
      str_contains($rtfSave, "\$keep = array_values(array_intersect(richtextRichFormats(), \$want));")
      && str_contains($rtfSave, "\$data['rt_formats_' . \$rtfPlace] = implode(',', \$keep ?: ['bbcode']);"));
check('formats per place: one table in a group of its own, a row per place, the description\'s three switches in it and nowhere else',
      in_array('formatting', array_column(settingsCatalogGroups(), 'id'), true)
      && str_contains($rtfTpl, 'id="section-formats" data-group="formatting"')
      && str_contains($rtfTpl, "<?php foreach (array_merge(['description'], richtextPlaces()) as \$rtfPlace):")
      && str_contains($rtfTpl, 'name="rt_formats_<?= $rtfPlace ?>"') && str_contains($rtfTpl, 'name="desc_allow_<?= $rtfF ?>"')
      && !str_contains($rtfTpl, 'data-setting="desc_allow_bbcode"') && !str_contains($rtfTpl, 'name="desc_allow_markdown"'));

$cfgYt = fn(string $mode) => array_merge($cfgF, ['rt_youtube' => $mode]);
$ytBare = 'https://youtu.be/aqz-KE-bpKQ?t=1m30s';
$ytThumb = richtextRenderIn('description', null, $ytBare, 'flarum', $cfgYt('thumb'));
$ytClick = richtextRenderIn('message', null, '[youtube]https://www.youtube.com/watch?v=aqz-KE-bpKQ&t=45[/youtube]', 'bbcode', $cfgYt('click'));
check('youtube: in a description or a message the link carries the video for the player, its start, and the thumbnail only when asked for',
      str_contains($ytThumb, 'class="rt-video"') && str_contains($ytThumb, 'data-yt="aqz-KE-bpKQ" data-yt-start="90" data-yt-thumb="1"')
      && str_contains($ytClick, 'data-yt="aqz-KE-bpKQ" data-yt-start="45"') && !str_contains($ytClick, 'data-yt-thumb'), $ytThumb . ' | ' . $ytClick);
check('youtube: "link", the other places, a render with no place, mail and a new account\'s words keep the plain link',
      !str_contains(richtextRenderIn('description', null, $ytBare, 'flarum', $cfgYt('link')), 'data-yt')
      && !str_contains(richtextRenderIn('description', null, $ytBare, 'flarum', $cfgF), 'data-yt')
      && !str_contains(richtextRenderIn('shout', null, $ytBare, 'flarum', $cfgYt('thumb')), 'data-yt')
      && !str_contains(richtextRenderIn('list', null, $ytBare, 'flarum', $cfgYt('thumb')), 'data-yt')
      && !str_contains(richtextRender($ytBare, 'flarum', $cfgYt('thumb')), 'data-yt')
      && !str_contains(richtextRenderForEmail($ytBare, 'flarum', richtextVideoCfg($cfgYt('thumb'))), 'data-yt')
      && !str_contains(richtextRenderIn('description', null, $ytBare, 'flarum', $cfgYt('thumb') + ['rt_links_text' => '1']), 'data-yt'));
check('youtube: the start time reads seconds, 1h2m3s and start=, and nothing else',
      richtextYoutubeStart('https://youtu.be/x?t=90') === 90 && richtextYoutubeStart('https://youtu.be/x?t=90s') === 90
      && richtextYoutubeStart('https://www.youtube.com/watch?v=x&t=1h2m3s') === 3723 && richtextYoutubeStart('https://www.youtube.com/embed/x?start=15') === 15
      && richtextYoutubeStart('https://youtu.be/x?t=abc') === 0 && richtextYoutubeStart('https://youtu.be/x') === 0
      && richtextYoutubeStart('https://youtu.be/x?t=999999') === 86400 && richtextYoutubeStart('https://youtu.be/x?tt=5') === 0);
check('youtube: the pages a moderator writes (Terms, Info, the home sections) get the player too',
      str_contains((string)file_get_contents($root . '/templates/pages/_custom.php'), 'richtextRender($__pcBody, $__pcFormat, richtextVideoCfg($cfg), ')
      && str_contains((string)file_get_contents($root . '/templates/pages/home.php'), "richtextRender(\$text, (string)\$custom['format'], richtextVideoCfg(\$cfg),"));
check('youtube: the setting ships as "link", is saved as one of its three answers, has search words and a control',
      (trackerSchemaDefaultSettings()['rt_youtube'] ?? null) === 'link'
      && str_contains($rtfSave, "'rt_youtube'") && str_contains($rtfSave, "!in_array(\$data['rt_youtube'], ['link', 'click', 'thumb'], true)")
      && isset(settingsCatalogKeywords()['rt_youtube']) && str_contains($rtfTpl, 'name="rt_youtube"'));
$ytJs = (string)file_get_contents($root . '/assets/js/ytplayer.js');
$ytFrom = strpos($ytJs, '    function embedUrl(id, start) {');
$ytTo = $ytFrom === false ? false : strpos($ytJs, "\n    }\n", $ytFrom);
$ytFn = ($ytFrom !== false && $ytTo !== false) ? substr($ytJs, $ytFrom, $ytTo - $ytFrom + 6) : '';
$ytTmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yt_embed_' . bin2hex(random_bytes(4)) . '.js';
file_put_contents($ytTmp, $ytFn . "\nprocess.stdout.write(JSON.stringify([embedUrl('aqz-KE-bpKQ', 90), embedUrl('aqz-KE-bpKQ', 0)]));\n");
$ytOut = json_decode(trim((string)@shell_exec('node ' . escapeshellarg($ytTmp) . ' 2>&1')), true);
@unlink($ytTmp);
check('youtube: the player is the privacy-enhanced embed, playing once pressed, from the start time when there is one',
      $ytOut === ['https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?autoplay=1&rel=0&start=90', 'https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ?autoplay=1&rel=0'],
      json_encode($ytOut));
check('youtube: the frame sends the page\'s origin (the embed refuses to play without one), the thumbnail sends nothing, and every public page loads the script',
      str_contains($ytJs, "referrerpolicy: 'strict-origin-when-cross-origin'") && str_contains($ytJs, "src: 'https://i.ytimg.com/vi/' + id + '/hqdefault.jpg'")
      && str_contains($ytJs, "referrerpolicy: 'no-referrer'") && str_contains((string)file_get_contents($root . '/templates/layout.php'), 'assets/js/ytplayer.js'));
check('youtube: the words of the player reach the public pages (their prefix is in the public bundle)',
      (bool)preg_match("/const LANG_JS_PUBLIC = \\[[^\\]]*'js\\.video\\.'/s", (string)file_get_contents($root . '/includes/lang.php')));

$cfgBig = array_merge($cfg, ['desc_allow_flarum' => '1', 'desc_max_chars' => '500000', 'desc_max_images' => '100', 'desc_max_links' => '100']);
$smuggle = "a \x00LINK0\x00 b \x00CODE0\x00 c \x01HIDE0\x01 d \x03 e \x05 f \x06 g [url=https://tryhackx.org/x]x[/url] `k`";
$leaks = [];
foreach (['bbcode', 'markdown', 'flarum'] as $f) {
    $out = richtextRender($smuggle, $f, $cfgBig, true);
    if (preg_match('/[\x00-\x06]/', $out) || !str_contains($out, 'LINK0') || !str_contains($out, 'CODE0') || !str_contains($out, 'HIDE0')) $leaks[] = $f;
}
check('the renderer\'s own placeholder bytes, typed by an author, are dropped and never turn into a link, code or a hidden part', !$leaks, implode(', ', $leaks));
$alt = richtextAlt("x\x00LINK3\x00 <b>y</b> \" onerror=alert(1) \x07");
check('a picture\'s description is plain text: no placeholder, no tag, no quote that ends the attribute',
      !preg_match('/[\x00-\x1F"<>]/', $alt) && str_contains($alt, '&quot; onerror=alert(1)') && str_starts_with($alt, 'x'), $alt);
$shapes = ['fence-open' => str_repeat("```x\n", 20000), 'tilde-open' => str_repeat("~~~x\n", 20000), 'md-link-open' => str_repeat('[a](', 25000),
           'quote-open' => str_repeat('[quote=a]', 11000), 'hide-open' => str_repeat('[hide=', 16000), 'brackets' => str_repeat('[', 100000),
           'gt-lines' => str_repeat("> a\n", 25000), 'backticks' => str_repeat('`a', 50000), 'spoiler-pipes' => str_repeat('||a', 33000),
           'url-many' => str_repeat('https://a.example/x ', 5000)];
$slow = [];
foreach ($shapes as $k => $text) {
    foreach (['bbcode', 'markdown', 'flarum'] as $f) {
        $t0 = microtime(true);
        richtextRender($text, $f, $cfgBig, true);
        $dt = microtime(true) - $t0;
        if ($dt > 3.0) $slow[] = $k . '/' . $f . ' ' . round($dt, 2) . 's';
    }
}
check('a hostile text of 100,000 characters renders in well under a few seconds, whatever its shape', !$slow, implode(', ', $slow));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
