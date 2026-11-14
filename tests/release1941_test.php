<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/netlimit.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

check('version is 1.94.1 or later', version_compare(TRACKER_VERSION, '1.94.1', '>='), TRACKER_VERSION);
check('the changelog has the 1.94.1 entry', str_contains($src('CHANGELOG.md'), '## [1.94.1] — 2026-11-09'));

$lib = $src('includes/netlimit.php');
check('the chart series is built by netlimitSeriesFromRows with the bucket or the sample interval as its step',
      str_contains($lib, 'netlimitSeriesFromRows($st->fetchAll(PDO::FETCH_ASSOC) ?: [], $bucket > 0 ? $bucket : netlimitSampleSeconds($cfg))'));
$js = $src('assets/js/admin-netlimit.js');
check('the traffic chart still leaves a gap where a value is null', str_contains($js, 'spanGaps: false')
      && str_contains($js, '(s[def.key] || []).map(v => (v == null ? null : Number(v)))'));

$row = static fn(int $t, int $v): array => ['t' => $t, 'pps_total' => $v, 'pps_passed' => 90000, 'pps_capped' => $v - 90000,
    'epps_ok' => 85000, 'epps_capped' => 0, 'limit_pps' => 90000];
$rows = [];
for ($t = 1762604700; $t <= 1762608480; $t += 60) $rows[] = $row($t, 150000);
for ($t = 1762701393; $t <= 1762705000; $t += 60) $rows[] = $row($t, 180000);
$s = netlimitSeriesFromRows($rows, 60);
$gap = array_keys($s['pps_total'], null, true);
check('a 27-hour stop is one break in the line, not a slope from one day to the next', count($gap) === 1
      && $s['t'][$gap[0] - 1] === 1762608480 && $s['t'][$gap[0] + 1] === 1762701393, json_encode($gap));
$json = json_encode(['series' => $s]);
check('the break travels to the chart as null', str_contains($json, ',null,'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
