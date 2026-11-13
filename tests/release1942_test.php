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
check('version is 1.94.2 or later', version_compare($m[1] ?? '0', '1.94.2', '>='), $m[1] ?? '');
check('the changelog has the 1.94.2 entry', str_contains($src('CHANGELOG.md'), '## [1.94.2] — 2026-11-13'));

$sums = ['opentracker.white' => 'bbd33ecdab8c1f1b1019e683a12473220a5a4d69eb6e8be6a2ff9431ada0d6dc',
         'opentracker.black' => '1091a5f8c93985c1d9290a9ab6e61639b30154e15058c81a5ab96d4bddbef1fa'];
foreach ($sums as $bin => $sum) {
    $data = $src($ot . 'bin/' . $bin);
    check("$bin matches its published sha256", hash('sha256', $data) === $sum, hash('sha256', $data));
    check("$bin is a 64-bit position-independent ELF of opentracker 2.2.1 that knows access.fullscrape",
          str_starts_with($data, "\x7fELF\x02") && ord($data[16] ?? "\0") === 3 && str_contains($data, '2.2.1')
          && str_contains($data, 'access.fullscrape') && str_contains($data, 'WANT_RESTRICT_FULLSCRAPE')
          && str_contains($data, 'tracker.empty_torrent_timeout'));
    check("$bin has no patch of ours and no s24s report", !str_contains($data, 'access.udp_reject_interval') && !str_contains($data, 's24s'));
    check("$bin: tools/opentracker/README.md and INSTALL.md carry its sum",
          str_contains($src($ot . 'README.md'), "$sum  $bin") && str_contains($src('INSTALL.md'), "$sum  $bin"));
}

foreach (['INSTALL.md', 'README.md', $ot . 'README.md'] as $doc) {
    $d = $src($doc);
    check("$doc builds from the verified 2.2.1 tarball",
          str_contains($d, 'opentracker-2.2.1.tar.bz2') && str_contains($d, 'be4318e50affa65000e80963a6d05245d32cd4b73875df19aeec2aeee81c8fc7')
          && !str_contains($d, 'opentracker-2.2.0.tar.bz2'));
}
$g = $src($ot . 'README.md');
check('the binaries guide names the 2.2.1 tag and commit',
      str_contains($g, 'release    2.2.1 — tag v2.2.1 = commit 530d3ee20a06f2a128b1eddaf2398011d3f1d119'));
check('the binaries guide says what 2.2.1 changed', str_contains($g, '### What 2.2.1 changed') && str_contains($g, '`-DWANT_SYSLOGS`'));
check('the binaries guide gives the size and the memory measured', str_contains($g, 'Both are 113 024 bytes, stripped,')
      && str_contains($g, '2.2.1 used 155'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
