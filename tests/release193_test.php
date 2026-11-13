<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);
$ot = 'tools/opentracker/';

preg_match("/const TRACKER_VERSION = '([^']+)'/", $src('includes/functions.php'), $m);
check('version is 1.93.0 or later', version_compare($m[1] ?? '0', '1.93.0', '>='), $m[1] ?? '');
check('the changelog has the 1.93.0 entry', str_contains($src('CHANGELOG.md'), '## [1.93.0] — 2026-11-05'));

check('no opentracker patch is shipped any more', (glob($root . '/' . $ot . '*.patch') ?: []) === [],
      implode(', ', array_map('basename', glob($root . '/' . $ot . '*.patch') ?: [])));

foreach (['opentracker.white', 'opentracker.black'] as $bin) {
    check("$bin has no patch of ours", !str_contains($src($ot . 'bin/' . $bin), 'access.udp_reject_interval'));
}

foreach (['INSTALL.md', 'README.md', $ot . 'README.md'] as $doc) {
    $d = $src($doc);
    check("$doc builds with -DWANT_RESTRICT_FULLSCRAPE",
          str_contains($d, 'F="-DWANT_FULLSCRAPE -DWANT_COMPRESSION_GZIP -DWANT_RESTRICT_STATS -DWANT_RESTRICT_FULLSCRAPE -DWANT_MODEST_FULLSCRAPES -DOT_BUCKET_COUNT_BITS=14"'));
    check("$doc applies no patch", !preg_match('/patch -p1 --forward/', $d));
    check("$doc: its example config blesses loopback for full scrapes and has no reject-interval line",
          str_contains($d, "\naccess.fullscrape 127.0.0.1\n") && !preg_match('/^access\.udp_reject_interval/m', $d));
}

$nft = $src($ot . 'egress-budget/ottrack.nft');
check('egress budget: a refused client\'s reply (7020-7379 s, or 86400 from the old builds) never marks it good',
      substr_count($nft, 'udp length >= 28 @th,128,32 != { 7020-7379, 86400 } update @good') === 2
      && !str_contains($nft, '@th,128,32 != 86400 '));
check('egress budget: no word of the retired patch', !str_contains($nft, 'udp-reject-interval') && !str_contains($nft, 'udp_reject_interval'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
