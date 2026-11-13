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

preg_match("/const TRACKER_VERSION = '([^']+)'/", $src('includes/functions.php'), $m);
check('version is 1.92.1 or later', version_compare($m[1] ?? '0', '1.92.1', '>='), $m[1] ?? '');
check('the changelog has the 1.92.1 entry', str_contains($src('CHANGELOG.md'), '## [1.92.1] — 2026-11-04'));

$h = $src('tools/opentracker/tracker-instance.sh');
check('helper: reads /proc through one overridable root', str_contains($h, 'PROC="${OT_PROC:-/proc}"') && !str_contains($h, '"/proc/$pid'));
check('helper: says when the sample was taken', str_contains($h, "printf ',\"uptime_cs\":%s' \"\$(uptime_cs)\""));
check('helper: counts the UDP sockets from the same ss read as the drops',
      str_contains($h, 'ssout="$(ss_udp)"') && str_contains($h, 'socket_drops "$ssout"') && str_contains($h, 'udp_sockets "$ssout"'));
check('helper: a role for every thread', str_contains($h, '"role":"%s"') && str_contains($h, 'thread_role "$pid"'));
check('helper: the receive calls of x86_64 and arm64', str_contains($h, "x86_64) printf '45 47 299'") && str_contains($h, "aarch64|arm64) printf '207 212 243'"));
check('helper: a UDP worker is a thread waiting on a socket listed in /proc/net/udp or udp6',
      str_contains($h, '"$PROC/net/udp" "$PROC/net/udp6"') && str_contains($h, 'grep -qx "$ino"'));
check('helper: every tick is read before the roles are worked out',
      strpos($h, 'tids+=("$tid")') !== false && strpos($h, 'tids+=("$tid")') < strpos($h, 'inodes="$(udp_inodes)"'));

$js = $src('assets/js/admin-otperf.js');
check('card: measures by the time the sample was taken', str_contains($js, 'const up = Number(s.uptime_cs || 0);')
      && str_contains($js, 'const now = up > 0 ? up * 10 : Date.now();'));
check('card: a reused answer is not a new reading', str_contains($js, 'if (prev && cur.sampled && cur.at === prev.at) return lastGood;'));
check('card: a restart or a baseline of another process starts again',
      str_contains($js, '|| restarted') && str_contains($js, '(age >= MIN_WINDOW && matched === 0)) prev = cur;'));
check('card: the early second reading is fresh', str_contains($js, 'setTimeout(() => load(true, true), 4000);')
      && str_contains($js, "apiCall('admin/ot_status' + (fresh ? '&fresh=1' : ''))"));
check('card: bars are labelled by role', str_contains($js, "t.key('js.otperf.role_udp', { n: udp.indexOf(th) + 1 })")
      && str_contains($js, "t.key('js.otperf.role_main')") && str_contains($js, "t.key('js.otperf.role_other')"));
check('card: one line for the UDP threads together', str_contains($js, "t.key('js.otperf.udp_summary'"));
check('card: the UDP sockets in the workers tile', str_contains($js, "t.key('js.otperf.udp_sockets'") && str_contains($js, "t.key('js.otperf.udp_shared_socket')"));
check('card: no thread variable hides the translator', !str_contains($js, 'forEach(t => {
                const row'));
check('css: room for a role name', str_contains($src('assets/css/admin.css'), '.ot-thread { display: grid; grid-template-columns: 4.8rem minmax(0, 1fr) 2.6rem;'));

foreach (['en', 'pl'] as $lang) {
    $l = $src("lang/$lang.php");
    foreach (['udp_sockets', 'udp_shared_socket', 'udp_summary', 'role_udp', 'role_main', 'role_other'] as $k) {
        check("lang $lang: js.otperf.$k", str_contains($l, "'js.otperf.$k' => "));
    }
}

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
