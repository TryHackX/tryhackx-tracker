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
check('version is 1.92.0 or later', version_compare($m[1] ?? '0', '1.92.0', '>='), $m[1] ?? '');
check('the changelog has the 1.92.0 entry', str_contains($src('CHANGELOG.md'), '## [1.92.0] — 2026-11-04'));

check('the white build reads a whitelist and the black one a blacklist',
      str_contains($src($ot . 'bin/opentracker.white'), 'access.whitelist') && !str_contains($src($ot . 'bin/opentracker.white'), 'access.blacklist')
      && str_contains($src($ot . 'bin/opentracker.black'), 'access.blacklist') && !str_contains($src($ot . 'bin/opentracker.black'), 'access.whitelist'));

foreach (['INSTALL.md', 'README.md', $ot . 'README.md'] as $doc) {
    $d = $src($doc);
    check("$doc no longer applies the retired patches",
          !preg_match('/patch -p1 --forward < \$P\/(sighup-udp-workers|libowfat-no-zerocopy|opentracker-review-fixes|opentracker-batch-realloc)/', $d)
          && !str_contains($d, 'LIBOWFAT_HEADERS'));
}
check('INSTALL.md states the glibc floor', str_contains($src('INSTALL.md'), 'glibc 2.36'));
check('the README example config has no comment at the end of a line',
      !preg_match('/^(access|tracker|listen)\.\S+ \S+[ \t]+#/m', $src('README.md')));

$tpl = $src('templates/pages/stats.php');
check('stats page: the tracker id row is hidden when the tracker sends none',
      str_contains($tpl, "<tr<?= (\$hasCache && \$trackerId === '') ? ' hidden' : '' ?>>"));
check('stats page: the version row is hidden when the tracker sends none',
      str_contains($tpl, "<tr<?= (\$hasCache && trim((string)\$cacheData['version']) === '') ? ' hidden' : '' ?>>"));
$js = $src('assets/js/app.js');
check('stats page refresh hides and shows both rows',
      str_contains($js, "trackerIdEl.closest('tr').hidden = !res.tracker_id;") && str_contains($js, "versionEl.closest('tr').hidden = !res.version;"));

check('instance helper adds up the drop counters of every socket',
      str_contains($src($ot . 'tracker-instance.sh'), 'if (n != "") s += n} END {print s+0}'));
$sys = $src($ot . 'tracker-sysctl.sh');
check('sysctl helper sums drops and queue, reads the buffer once',
      str_contains($sys, 'd="$(num_or_zero "$(socket_sum "$line" d)")"') && str_contains($sys, 'r="$(num_or_zero "$(socket_sum "$line" r)")"')
      && str_contains($sys, 'rb="$(num_or_zero "$(socket_field "$line" rb)")"'));
check('stability probe sums the sockets of one port', str_contains($src('tools/tuner.py'), 'out[local] = out.get(local, 0) + int(d.group(1))'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
