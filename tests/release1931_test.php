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
check('version is 1.93.1 or later', version_compare($m[1] ?? '0', '1.93.1', '>='), $m[1] ?? '');
check('the changelog has the 1.93.1 entry', str_contains($src('CHANGELOG.md'), '## [1.93.1] — 2026-11-07'));

foreach (['opentracker.white', 'opentracker.black'] as $bin) {
    $data = $src($ot . 'bin/' . $bin);
    check("$bin carries the stack protector and fortified libc calls",
          str_contains($data, '__stack_chk_fail') && (bool)preg_match('/__[a-z]+_chk\x00/', $data));
}
$g = $src($ot . 'README.md');
check('the binaries guide says what 2.1.1 fixed, the keep-alive loop first', str_contains($g, '### What 2.1.1 fixed')
      && str_contains($g, 'on a `-DWANT_KEEPALIVE` build'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
