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

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
