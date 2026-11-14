<?php
/**
 * The comment counter counts as the server does (1.74.0, part D — QUAL-14):
 *   php tests/comment_twin_test.php
 *
 * assets/js/comments.js counts a comment while it is typed; the server — commentParse(commentClean($text)) in
 * includes/comments.php — decides. The script counted the RAW text and treated every [url=…] as a link: 130 words
 * between tabs counted 649 against a limit of 500 and the server said 1036, "[url=http://example.org/x]" counted 0
 * and the server 20. The twin is the block between "TWIN" markers in comments.js; it is run here by node on the
 * cases the audit found and on 600 seeded random texts (tabs, CRLF, line separators, invisible characters, runs of
 * blank lines, every tag the comments know, good and bad addresses, links with and without words, unclosed ones), with
 * links allowed and refused, and every answer must be the server's. No database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/comments.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
$cfg = ['site_url' => 'https://tracker.example/', 'link_trusted_domains' => ''];
$server = static fn(string $s, bool $links): int => (int)commentParse(commentClean($s), $cfg, $links)['chars'];

$js = str_replace("\r\n", "\n", (string)@file_get_contents($root . '/assets/js/comments.js'));
$from = strpos($js, '// ── TWIN:');
$to = strpos($js, '// ── TWIN END ──');
$twin = ($from !== false && $to !== false && $to > $from) ? substr($js, $from, $to - $from) : '';
check('comments.js carries the twin between its markers (commentCleanJs, commentUrlJs, commentTitleJs, visibleChars)',
      $twin !== '' && str_contains($twin, 'function commentCleanJs(raw)') && str_contains($twin, 'function visibleChars(src, links, img)')
      && str_contains($twin, 'function ownImageJs(url, base)'));

// The audit's cases, and the shapes a comment really has.
$cases = [
    'plain text', '[b]bold[/b] and [i]it[/i]', "a\tb", "x   \ny", "a\n\n\n\nb", '  padded  ', "z\u{200B}w",
    '[url=http://example.org/x]', "\u{1F680}[URL=http://up.example]", trim(str_repeat("word\t", 130)),
    '[url=https://e.example/a]words[/url] and [url]https://e.example/b[/url]', '[url=javascript:alert(1)]x[/url]',
    '[url= "https://e.example/q" ]quoted[/url]', '[url]not an address[/url]', '[url=https://e.example]', '[url][/url]',
    "[quote=\"Ann\"]said[/quote] [spoiler=Title]hid[/spoiler] [spoiler]x[/spoiler]", "[code]\n\tindented\n[/code] after",
    "[b][i]crossed[/b] still italic[/i]", '[url=https://e.example/1]a[/url][url=https://e.example/2]b[/url][url=https://e.example/3]c[/url][url=https://e.example/4]d[/url]',
    "line\r\nline\u{2028}line\u{2029}line", "\u{FEFF}\u{2060}hidden\u{202E}marks", "  \n\n  lead and trail  \n\n ",
    'Zażółć gęślą jaźń 🚀 [b]pogrubione[/b]', '[quote][quote]nested[/quote][/quote]', '[/b] stray closer [/url] [/code]',
    'see [magnet]magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=Big.Buck_Bunny%20(2008)&tr=udp%3A%2F%2Fx[/magnet] ok',
    '[magnet="Wersja 4K"]magnet:?xt=urn:btih:AERUKZ4JVPG66AJDIVSYVK3PPMRUNXXX[/magnet]', '[magnet]magnet:?xt=urn:btih:zz[/magnet]',
    '[magnet]magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=Zażółć+gęślą[/magnet] [magnet]no closer',
    '[url=https://e.example/a]a[/url][magnet]magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567[/magnet][url=https://e.example/b]b[/url][url]https://e.example/c[/url]',
];
// 600 seeded random texts.
mt_srand(1740);
$bits = ['word', 'słowo', '🚀', ' ', ' ', "\t", "\n", "\n\n\n", "\r\n", "\u{200B}", "\u{2060}", "\u{FEFF}", "  \n", '[b]', '[/b]', '[i]',
         '[/i]', '[u]', '[/u]', '[s]', '[/s]', '[quote]', '[/quote]', '[quote="Bob"]', '[spoiler]', '[/spoiler]', '[spoiler=Note]',
         '[code]', '[/code]', '[url=https://e.example/p]', '[url=http://e.example:8080/x]', '[url=https://u:p@e.example/]',
         '[url=javascript:void(0)]', '[url=https://]', '[url= https://e.example/sp ]', '[url]', '[/url]', 'https://e.example/raw',
         'not a link', '[URL=HTTPS://E.EXAMPLE/UP]', '[url="https://e.example/q"]', '[', ']', '=', 'x',
         '[magnet]', '[/magnet]', '[magnet=Alt name]', 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=A.b_c',
         'magnet:?xt=urn:btih:AERUKZ4JVPG66AJDIVSYVK3PPMRUNXXX', '&dn=x%20y', 'magnet:?dn=only'];
for ($i = 0; $i < 600; $i++) {
    $s = '';
    $len = mt_rand(1, 40);
    for ($j = 0; $j < $len; $j++) $s .= $bits[mt_rand(0, count($bits) - 1)];
    $cases[] = $s;
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ctw_' . bin2hex(random_bytes(4));
file_put_contents($tmp . '.js', $twin . "\nconst cases = JSON.parse(require('fs').readFileSync(process.argv[2], 'utf8'));\n"
    . "process.stdout.write(JSON.stringify(cases.map((s) => [visibleChars(s, true), visibleChars(s, false)])));\n");
file_put_contents($tmp . '.json', json_encode($cases, JSON_UNESCAPED_UNICODE));
$raw = (string)@shell_exec('node ' . escapeshellarg($tmp . '.js') . ' ' . escapeshellarg($tmp . '.json') . ' 2>&1');
@unlink($tmp . '.js'); @unlink($tmp . '.json');
$got = json_decode($raw, true);
if (!is_array($got) || count($got) !== count($cases)) {
    check('node ran the twin on every text', false, substr($raw, 0, 600));
} else {
    $fixed = count($cases) - 600;
    $badFixed = $badRandom = [];
    foreach ($cases as $i => $s) {
        $want = [$server($s, true), $server($s, false)];
        if ($got[$i] === $want) continue;
        $line = json_encode($s, JSON_UNESCAPED_UNICODE) . ' js ' . json_encode($got[$i]) . ' php ' . json_encode($want);
        if ($i < $fixed) $badFixed[] = $line; else $badRandom[] = $line;
    }
    check('the audit\'s cases (' . $fixed . '): tabs (4 spaces), blank-line runs, trailing spaces, invisible characters, a link with no words, bad addresses',
          $badFixed === [], implode(' | ', array_slice($badFixed, 0, 4)));
    check('… and 600 seeded random texts: the counter and the server agree on every one, links allowed and refused',
          $badRandom === [], count($badRandom) . ' differ: ' . implode(' | ', array_slice($badRandom, 0, 4)));
    $tabs = trim(str_repeat("word\t", 130));
    check('… "130 words between tabs" counts what the server counts (1036 — over a limit of 500), not 649',
          $got[9][0] === $server($tabs, true) && $server($tabs, true) > 1000, json_encode([$got[9], $server($tabs, true)]));
}

$imgOn = ['base' => 'https://tracker.example/', 'remote' => true, 'hosts' => [], 'max' => 50, 'w' => 360, 'h' => 240];
$imgOff = ['remote' => false] + $imgOn;
$own = 'https://tracker.example/i/5-960x540.webp';
$icases = [
    '[img]' . $own . '[/img] after', '[img]https://x.example/a.png[/img] text', '[IMG]HTTPS://TRACKER.EXAMPLE/i/6-10x10.gif[/IMG]',
    '[img]not a url[/img]', '[img]https://x.example/a.png', '[url=https://e.example]w [img]' . $own . '[/img][/url]',
    '[code][img]' . $own . '[/img][/code]', '[img][/img]', '[img] https://x.example/a.png [/img]', '[img]"https://x.example/q.png"[/img]',
    '[b][img]https://x.example/a.png[/img][/b] z', '[img]' . $own . '[img]' . $own . '[/img]', '[img]https://tracker.example/i/0-0x10.webp[/img]',
];
mt_srand(1770);
$ibits = array_merge($bits, ['[img]', '[/img]', '[IMG]', $own, 'https://x.example/p.png', 'https://tracker.example/i/9-30x20.gif',
                             '[img]' . $own . '[/img]', '[img]https://x.example/z.jpg[/img]', '[img]bad address[/img]']);
for ($i = 0; $i < 400; $i++) {
    $s = '';
    $len = mt_rand(1, 40);
    for ($j = 0; $j < $len; $j++) $s .= $ibits[mt_rand(0, count($ibits) - 1)];
    $icases[] = $s;
}
$itmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ctwi_' . bin2hex(random_bytes(4));
file_put_contents($itmp . '.js', $twin . "\nconst cases = JSON.parse(require('fs').readFileSync(process.argv[2], 'utf8'));\n"
    . "const on = { base: 'https://tracker.example/', remote: true }, off = { base: 'https://tracker.example/', remote: false };\n"
    . "process.stdout.write(JSON.stringify(cases.map((s) => [visibleChars(s, true, on), visibleChars(s, false, on), visibleChars(s, true, off), visibleChars(s, false, off)])));\n");
file_put_contents($itmp . '.json', json_encode($icases, JSON_UNESCAPED_UNICODE));
$iraw = (string)@shell_exec('node ' . escapeshellarg($itmp . '.js') . ' ' . escapeshellarg($itmp . '.json') . ' 2>&1');
@unlink($itmp . '.js'); @unlink($itmp . '.json');
$igot = json_decode($iraw, true);
$ichars = static fn(string $s, bool $links, array $img): int => (int)commentParse(commentClean($s), $cfg, $links, $img)['chars'];
if (!is_array($igot) || count($igot) !== count($icases)) {
    check('node ran the twin on every text with pictures', false, substr($iraw, 0, 600));
} else {
    $ibad = [];
    foreach ($icases as $i => $s) {
        $want = [$ichars($s, true, $imgOn), $ichars($s, false, $imgOn), $ichars($s, true, $imgOff), $ichars($s, false, $imgOff)];
        if ($igot[$i] !== $want) $ibad[] = json_encode($s, JSON_UNESCAPED_UNICODE) . ' js ' . json_encode($igot[$i]) . ' php ' . json_encode($want);
    }
    check('pictures (1.77.0): this site\'s counts nothing, another site\'s nothing as a link and its address as text, a refused one is typed text — '
          . count($icases) . ' texts, with and without links, with pictures from links on and off', $ibad === [], count($ibad) . ' differ: ' . implode(' | ', array_slice($ibad, 0, 4)));
    check('… "[img]' . $own . '[/img] after" counts the six characters after it', $igot[0] === [6, 6, 6, 6] && $ichars($icases[0], true, $imgOn) === 6, json_encode($igot[0]));
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
