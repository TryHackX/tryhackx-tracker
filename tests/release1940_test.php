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
check('version is 1.94.0 or later', version_compare($m[1] ?? '0', '1.94.0', '>='), $m[1] ?? '');
check('the changelog has the 1.94.0 entry', str_contains($src('CHANGELOG.md'), '## [1.94.0] — 2026-11-09'));
$g = $src($ot . 'README.md');
check('the binaries guide still says what 2.2.0 changed', str_contains($g, '### What 2.2.0 changed') && str_contains($g, '`tracker.empty_torrent_timeout`'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
