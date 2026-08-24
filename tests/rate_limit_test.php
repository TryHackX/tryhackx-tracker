<?php
/**
 * Regression test for rateLimitAllow() (no DB needed): a short-window action (the public timeline
 * poller, 60 s) must NOT evict another action's hourly hits from the shared state file.
 *   php tests/rate_limit_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$file = __DIR__ . '/../config/rate_limits.json';
$backup = is_file($file) ? file_get_contents($file) : null;

// clean slate
@unlink($file);
$ip = '203.0.113.7';

// 1) exhaust an hourly limit (5/h, like appeals)
for ($i = 1; $i <= 5; $i++) check("appeal hit $i allowed", rateLimitAllow('appeal', $ip, 5, 3600) === true);
check('6th appeal denied', rateLimitAllow('appeal', $ip, 5, 3600) === false);

// 2) age the stored hits by 61 s (older than the timeline window, far younger than the hour)
$data = json_decode(file_get_contents($file), true);
foreach ($data as $k => $times) $data[$k] = array_map(fn($t) => $t - 61, $times);
file_put_contents($file, json_encode($data));

// 3) a short-window public call (timeline: 60 req / 60 s) must not evict the appeal hits
check('timeline call allowed', rateLimitAllow('timeline', $ip, 60, 60) === true);
check('appeal hits survived the short-window call', rateLimitAllow('appeal', $ip, 5, 3600) === false);

// 4) the short window still prunes ITS OWN namespace
$data = json_decode(file_get_contents($file), true);
$data['timeline|' . $ip] = array_map(fn($t) => $t - 120, $data['timeline|' . $ip]);   // 2 min old
file_put_contents($file, json_encode($data));
check('timeline hit older than 60 s pruned on next call', rateLimitAllow('timeline', $ip, 1, 60) === true);

// 5) max<=0 disables
check('max=0 always allows', rateLimitAllow('appeal', $ip, 0, 3600) === true);

// restore
if ($backup !== null) file_put_contents($file, $backup); else @unlink($file);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
