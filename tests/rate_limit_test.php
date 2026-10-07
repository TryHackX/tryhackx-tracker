<?php
/**
 * The file limiter and the panel's sign-in lockout (includes/functions.php, includes/auth.php — Temat L of the 1.73.2
 * audit, 1.74.0). No database needed:
 *   php tests/rate_limit_test.php
 *
 * What is held down here:
 *   1. a limit counts and refuses; a short window prunes only its own action (the 1.x regression this file began with);
 *   2. an address means an address GROUP: two addresses of one IPv6 /64 share one count, IPv4 is counted as before;
 *   3. one file and one lock per action — a flood of one action leaves another's file, and its cost, alone;
 *   4. the hard cap per file: the subjects whose last hit is oldest go first;
 *   5. config/rate_limits.json is the reset switch: a deleted line (or file) forgets that action's count;
 *   6. a peek spends nothing; forgetting takes back exactly what it is asked to;
 *   7. fail-open stays the contract — a file that cannot be written still answers true — AND rateLimitHealth() says
 *      so; an unreadable file is set aside as .bad, logged, and reported;
 *   8. a SIGN-IN whose count cannot be written costs a delay (AUTH-7) — the member's and the panel's;
 *   9. the panel's lockout per /64: five failures from five addresses of one /64 lock the sixth address of it;
 *  10. the daily cap on confirmation mails counts, stops, starts again the next day, and fails CLOSED.
 * Every action counted here is this run's own (rltest_<pid>_…); the real ones are touched only where the test is
 * about them (user_login, the lockout file, the mail count) and those are put back exactly as they were.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$P = 'rltest_' . getmypid() . '_';
$mine = [];                                    // every action this run counted, forgotten on the way out
$act = function (string $name) use ($P, &$mine): string { $a = $P . $name; $mine[] = $a; return $a; };
$idx = rateLimitIndexFile();
$idxWas = is_file($idx) ? (string)file_get_contents($idx) : null;
$laFile = loginAttemptsFile();
$laWas = is_file($laFile) ? (string)file_get_contents($laFile) : null;
$mailFile = confirmMailFile();
$mailWas = is_file($mailFile) ? (string)file_get_contents($mailFile) : null;
$ulFile = rateLimitFile('user_login');
$ulWas = is_file($ulFile) ? (string)file_get_contents($ulFile) : null;
$rmTree = function (string $p) use (&$rmTree): void {
    if (is_dir($p) && !is_link($p)) { foreach (glob($p . '/*') ?: [] as $c) $rmTree($c); @rmdir($p); }
    elseif (file_exists($p)) @unlink($p);
};
$cleanup = function () use (&$mine, $idx, $idxWas, $laFile, $laWas, $mailFile, $mailWas, $ulFile, $ulWas, $rmTree): void {
    foreach (array_unique($mine) as $a) {
        $f = rateLimitFile($a);
        $rmTree($f);
        foreach (glob($f . '.bad.*') ?: [] as $b) @unlink($b);
        @unlink($f . '.lock');
    }
    $put = function (string $f, ?string $body) use ($rmTree): void {
        $rmTree($f);
        if ($body !== null) file_put_contents($f, $body);
    };
    $put($laFile, $laWas);
    $put($mailFile, $mailWas);
    $put($ulFile, $ulWas);
    $put($idx, $idxWas);
    @unlink(rateLimitDir() . '/_health.json');
};
register_shutdown_function($cleanup);
rateLimitEnsureDir();

/* ── 1. counting, refusing, a window of its own ───────────────────────────────────────────────── */
$A = $act('appeal');
$ip = '203.0.113.7';
$ok = [];
for ($i = 1; $i <= 5; $i++) $ok[] = rateLimitAllow($A, $ip, 5, 3600);
check('five hits of a five-an-hour limit are let through', $ok === array_fill(0, 5, true), json_encode($ok));
check('… and the sixth is refused', rateLimitAllow($A, $ip, 5, 3600) === false);
check('… another address still has its whole budget', rateLimitAllow($A, '203.0.113.8', 5, 3600) === true);
$fA = rateLimitFile($A);
$dA = json_decode((string)@file_get_contents($fA), true);
$idxNow = json_decode((string)@file_get_contents($idx), true) ?: [];
check('the action has a file of its own in config/ratelimit/, naming itself and its generation',
      is_array($dA) && ($dA['a'] ?? '') === $A && is_string($dA['g'] ?? null) && str_starts_with($fA, rateLimitDir() . '/')
      && count($dA['h'][$ip] ?? []) === 5, json_encode($dA));
check('… and config/rate_limits.json holds that generation and when it was minted, nothing else about it',
      ($idxNow[$A . '|*'][0] ?? null) === ($dA['g'] ?? '') && is_int($idxNow[$A . '|*'][1] ?? null)
      && !array_filter(array_keys($idxNow), fn($k) => str_starts_with((string)$k, $A . '|') && $k !== $A . '|*'), json_encode($idxNow[$A . '|*'] ?? null));
// The window prunes its own action only: an hourly action's hits survive a 60-second action's call.
$T = $act('timeline');
$dA['h'][$ip] = array_map(fn($t) => $t - 61, $dA['h'][$ip]);
rateLimitWrite($fA, $dA);
check('a 60-second action is let through', rateLimitAllow($T, $ip, 60, 60) === true);
check('… and the hourly action\'s hits, 61 s old, still refuse it', rateLimitAllow($A, $ip, 5, 3600) === false);
$fT = rateLimitFile($T);
$dT = json_decode((string)file_get_contents($fT), true);
$dT['h'][$ip] = array_map(fn($t) => $t - 120, $dT['h'][$ip]);
rateLimitWrite($fT, $dT);
check('a hit older than its own 60-second window no longer counts', rateLimitAllow($T, $ip, 1, 60) === true);
check('a limit of 0 is no limit', rateLimitAllow($A, $ip, 0, 3600) === true);

/* ── 2. an address group ──────────────────────────────────────────────────────────────────────── */
check('an IPv6 address counts as its /64', ipBucket('2001:db8:beef:1::1') === '2001:db8:beef:1::/64'
      && ipBucket('2001:db8:beef:1:ffff:ffff:ffff:ffff') === '2001:db8:beef:1::/64', ipBucket('2001:db8:beef:1::1'));
check('an IPv4 address counts as itself — mapped or not', ipBucket('203.0.113.7') === '203.0.113.7' && ipBucket('::ffff:203.0.113.7') === '203.0.113.7');
$B = $act('status');
$v6 = ['2001:db8:beef:1::1', '2001:db8:beef:1::2', '2001:db8:beef:1:abcd::3'];
$got = array_map(fn($a) => rateLimitAllow($B, ipBucket($a), 3, 3600), $v6);
check('three addresses of one /64 spend one budget of three', $got === [true, true, true], json_encode($got));
check('… so a fourth address of the same /64 is refused', rateLimitAllow($B, ipBucket('2001:db8:beef:1::dead'), 3, 3600) === false);
check('… while the next /64 over has its own', rateLimitAllow($B, ipBucket('2001:db8:beef:2::1'), 3, 3600) === true);
check('… and one subject in the file for the whole /64, not one per address',
      array_keys(rateLimitHits($B)) === ['2001:db8:beef:1::/64', '2001:db8:beef:2::/64'], json_encode(array_keys(rateLimitHits($B))));

/* ── 3. one file and one lock per action ──────────────────────────────────────────────────────── */
$F = $act('flood');
$G = $act('quiet');
rateLimitAllow($F, 'seed', 10, 3600);                                   // mints F's generation
$genF = (string)(rateLimitReadJson($idx, false)['data'][$F . '|*'][0] ?? '');
$hF = [];
$now = time();
for ($i = 0; $i < 4000; $i++) $hF['198.18.' . intdiv($i, 250) . '.' . ($i % 250)] = [$now - 10];
rateLimitWrite(rateLimitFile($F), ['a' => $F, 'g' => $genF, 'h' => $hF]);
$before = (string)file_get_contents(rateLimitFile($F));
$ms = [];
for ($i = 0; $i < 15; $i++) { $t0 = microtime(true); rateLimitAllow($G, '192.0.2.' . $i, 100, 3600); $ms[] = (microtime(true) - $t0) * 1000; }
sort($ms);
check('four thousand subjects of one action leave another action\'s file alone: byte for byte',
      (string)file_get_contents(rateLimitFile($F)) === $before && rateLimitFile($F) !== rateLimitFile($G));
check('… the other action\'s file holds its own subjects and nobody else\'s', count(rateLimitHits($G)) === 15 && !isset(rateLimitHits($G)['198.18.0.1']),
      (string)count(rateLimitHits($G)));
check('… and its calls do not pay for the flood (median ' . sprintf('%.1f', $ms[7]) . ' ms)', $ms[7] < 60, sprintf('%.1f ms', $ms[7]));
check('… each action has its own lock file', is_file(rateLimitFile($F) . '.lock') && is_file(rateLimitFile($G) . '.lock'));

/* ── 4. the hard cap ──────────────────────────────────────────────────────────────────────────── */
$C = $act('cap');
rateLimitAllow($C, 'seed', 10, 3600);
$genC = (string)(rateLimitReadJson($idx, false)['data'][$C . '|*'][0] ?? '');
$hC = [];
for ($i = 0; $i < RATE_LIMIT_MAX_KEYS; $i++) $hC['s' . $i] = [$now - 3000 + intdiv($i, 2)];   // s0 is the oldest
rateLimitWrite(rateLimitFile($C), ['a' => $C, 'g' => $genC, 'h' => $hC]);
check('a new subject past the cap is let through', rateLimitAllow($C, 'newcomer', 10, 3600) === true);
$hits = rateLimitHits($C);
check('… the file holds at most ' . RATE_LIMIT_MAX_KEYS . ' subjects, the one whose last hit was oldest gone, the newcomer kept',
      count($hits) === RATE_LIMIT_MAX_KEYS && !isset($hits['s0']) && isset($hits['newcomer']) && isset($hits['s' . (RATE_LIMIT_MAX_KEYS - 1)]),
      count($hits) . ' ' . json_encode([isset($hits['s0']), isset($hits['newcomer'])]));
$hBig = [];
for ($i = 0; $i < 3; $i++) $hBig['big' . $i] = array_fill(0, intdiv(RATE_LIMIT_MAX_HITS, 2), $now - 100 + $i);
$capped = $hBig;
check('the cap counts times as well as subjects: one subject\'s worth too many and the oldest goes', rateLimitCap($capped) === 1 && !isset($capped['big0']) && isset($capped['big2']));

/* ── 5. the reset switch ──────────────────────────────────────────────────────────────────────── */
$R = $act('reset');
for ($i = 0; $i < 3; $i++) rateLimitAllow($R, $ip, 3, 3600);
check('a spent budget is refused', rateLimitAllow($R, $ip, 3, 3600) === false);
$map = rateLimitReadJson($idx, false)['data'];
unset($map[$R . '|*']);
rateLimitWrite($idx, $map);
check('taking the action\'s line out of config/rate_limits.json forgets its count at once (what the tests and the checks do)',
      rateLimitAllow($R, $ip, 3, 3600) === true);
check('… and only that action\'s: another action\'s spent budget still refuses', rateLimitAllow($A, $ip, 5, 3600) === false);
$keep = (string)file_get_contents($idx);
@unlink($idx);
check('deleting config/rate_limits.json forgets every count (the README\'s troubleshooting step)', rateLimitAllow($A, $ip, 5, 3600) === true);
file_put_contents($idx, $keep);
check('… a stale file of an old generation is not counted, and a new hit replaces it', count(rateLimitHits($R)) === 1 && count(rateLimitHits($R)[$ip] ?? []) === 1,
      json_encode(rateLimitHits($R)));

/* ── 6. peek and forget ───────────────────────────────────────────────────────────────────────── */
$K = $act('peek');
check('a peek at an untouched budget says yes', rateLimitPeek($K, $ip, 2, 3600) === true);
for ($i = 0; $i < 5; $i++) rateLimitPeek($K, $ip, 2, 3600);
check('… and five peeks spent nothing', rateLimitHits($K) === []);
rateLimitAllow($K, $ip, 2, 3600); rateLimitAllow($K, $ip, 2, 3600);
check('a spent budget peeks as no', rateLimitPeek($K, $ip, 2, 3600) === false);
rateLimitAllow($K, '192.0.2.99', 2, 3600);
check('forgetting by subject takes back only what it is asked to', rateLimitForget($K, fn(string $s): bool => $s === $ip) === 1
      && array_keys(rateLimitHits($K)) === ['192.0.2.99'] && rateLimitPeek($K, $ip, 2, 3600) === true);
check('forgetting across actions takes one subject wherever it was counted',
      rateLimitForgetWhere(fn(string $a, string $s): bool => str_starts_with($a, $P) && $s === '192.0.2.99') >= 1 && rateLimitHits($K) === []);
rateLimitAllow($K, $ip, 2, 3600);
check('forgetting an action whole leaves neither its file nor its line', rateLimitForget($K) === 1 && !is_file(rateLimitFile($K))
      && !isset(rateLimitReadJson($idx, false)['data'][$K . '|*']));

/* ── 7. fail-open, and saying so ──────────────────────────────────────────────────────────────── */
$U = $act('unwritable');
$fU = rateLimitFile($U);
@mkdir($fU, 0777, true);                                                 // a directory where the file belongs
$opens = [];
for ($i = 0; $i < 4; $i++) $opens[] = rateLimitAllow($U, $ip, 1, 3600);
check('a state file that cannot be written: every call still let through (the contract — a disk problem locks nobody out)',
      $opens === array_fill(0, 4, true), json_encode($opens));
$relU = 'config/ratelimit/' . basename($fU);
$hp = rateLimitHealth(true);
check('… and rateLimitHealth() names it', in_array(['what' => 'file', 'path' => $relU], $hp, true), json_encode($hp));
check('… in a sentence for the warning card and the health endpoint', str_contains(rateLimitHealthText(['what' => 'file', 'path' => $relU]), $relU)
      && !str_contains(rateLimitHealthText(['what' => 'file', 'path' => $relU]), 'api.ratelimit'));
$w = getTrackerServiceWarnings(['tracker_mode' => 'blacklist']);
check('… which the dashboard\'s warning card carries as a warning', $w['level'] !== 'none'
      && (bool)array_filter($w['items'], fn($it) => ($it['level'] ?? '') === 'warn' && str_contains((string)$it['text'], $relU)), json_encode($w['items']));
$rmTree($fU);
check('… and stops naming once it can be written again', !in_array(['what' => 'file', 'path' => $relU], rateLimitHealth(true), true));
$V = $act('garbled');
rateLimitAllow($V, $ip, 3, 3600);
file_put_contents(rateLimitFile($V), '{"a":"garbled", "h": [1,2');            // half a map
$log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rltest_log_' . getmypid() . '.txt';
@unlink($log);
$prevLog = ini_set('error_log', $log);
$vOk = rateLimitAllow($V, $ip, 3, 3600);
ini_set('error_log', (string)$prevLog);
$bad = glob(rateLimitFile($V) . '.bad.*') ?: [];
check('an unreadable state file: the call is let through and counting starts over', $vOk === true && count(rateLimitHits($V)[$ip] ?? []) === 1,
      json_encode(rateLimitHits($V)));
check('… the file is set aside as .bad.<time>, not thrown away', count($bad) === 1 && str_contains((string)@file_get_contents($bad[0] ?? ''), '"h": [1,2'));
check('… with a [ratelimit] line in the error log', str_contains((string)@file_get_contents($log), '[ratelimit] ' . basename(rateLimitFile($V))), (string)@file_get_contents($log));
@unlink($log);
$hp = rateLimitHealth(true);
check('… and rateLimitHealth() reports it until somebody deletes it', (bool)array_filter($hp, fn($p) => $p['what'] === 'bad' && str_contains($p['path'], basename(rateLimitFile($V)) . '.bad.')),
      json_encode($hp));
foreach ($bad as $b) @unlink($b);
check('… then no more', !array_filter(rateLimitHealth(true), fn($p) => str_contains($p['path'], basename(rateLimitFile($V)))));
check('the answer is reused for a minute (the card is polled): a cache file beside the state', is_file(rateLimitDir() . '/_health.json'));

/* ── 8. a sign-in that cannot be counted costs time ───────────────────────────────────────────── */
$rmTree($ulFile);
@mkdir($ulFile, 0777, true);
$t0 = microtime(true);
$ulOk = rateLimitAllow('user_login', '192.0.2.200', 10, 900);
$ulMs = (microtime(true) - $t0) * 1000;
$rmTree($ulFile);
if ($ulWas !== null) file_put_contents($ulFile, $ulWas);
check('a member\'s sign-in whose count cannot be written is still let through (no lockout)…', $ulOk === true);
check('… after the delay a wrong panel password costs (AUTH-7): ' . (int)$ulMs . ' ms', $ulMs >= 1500, (int)$ulMs . ' ms');
$Q = $act('unwritable_quiet');
@mkdir(rateLimitFile($Q), 0777, true);
$t0 = microtime(true);
rateLimitAllow($Q, '192.0.2.200', 10, 900);
$qMs = (microtime(true) - $t0) * 1000;
check('… while any other action that cannot be counted answers at once (' . (int)$qMs . ' ms)', $qMs < 400, (int)$qMs . ' ms');

/* ── 9. the panel's lockout, per address group ────────────────────────────────────────────────── */
$cfgL = ['login_lockout_attempts' => '5', 'login_lockout_minutes' => '15'];
$rmTree($laFile);
foreach (['2001:db8:beef:7::1', '2001:db8:beef:7::2', '2001:db8:beef:7::3', '2001:db8:beef:7::4', '2001:db8:beef:7::5'] as $a) recordLoginFailure($a, $cfgL);
check('five failures from five addresses of one /64 lock a sixth address of it', isLoginLocked('2001:db8:beef:7::ffff', $cfgL) === true);
check('… and leave the next /64 over free', isLoginLocked('2001:db8:beef:8::1', $cfgL) === false);
$la = json_decode((string)file_get_contents($laFile), true) ?: [];
check('… counted under the /64, not under any one address', array_keys($la) === ['2001:db8:beef:7::/64'], json_encode(array_keys($la)));
for ($i = 0; $i < 5; $i++) recordLoginFailure('203.0.113.50', $cfgL);
check('IPv4 as before: five failures lock the address', isLoginLocked('203.0.113.50', $cfgL) === true && isLoginLocked('203.0.113.51', $cfgL) === false);
clearLoginFailures('2001:db8:beef:7::9');
check('a successful sign-in from any address of the /64 clears its count', isLoginLocked('2001:db8:beef:7::1', $cfgL) === false
      && isLoginLocked('203.0.113.50', $cfgL) === true);
$rmTree($laFile);
@mkdir($laFile, 0777, true);
$t0 = microtime(true);
recordLoginFailure('203.0.113.60', $cfgL);
$laMs = (microtime(true) - $t0) * 1000;
check('a panel failure that cannot be recorded costs the same delay (AUTH-7): ' . (int)$laMs . ' ms', $laMs >= 1500, (int)$laMs . ' ms');
$hp = rateLimitHealth(true);
check('… and the unwritable lockout file is on the warning card too', in_array(['what' => 'file', 'path' => 'config/login_attempts.json'], $hp, true), json_encode($hp));
$rmTree($laFile);
if ($laWas !== null) file_put_contents($laFile, $laWas);

/* ── 10. the daily cap on confirmation mails ──────────────────────────────────────────────────── */
$rmTree($mailFile);
$day = strtotime('2031-05-05 12:00:00');
$cap2 = ['confirm_mail_daily_cap' => '2'];
$got = [confirmMailAllow($cap2, $day), confirmMailAllow($cap2, $day + 60), confirmMailAllow($cap2, $day + 120)];
check('a cap of two a day: two mails, the third not', $got === [true, true, false], json_encode($got));
check('… the count is the day\'s', confirmMailSentToday($day) === 2);
check('… and the next day starts at zero', confirmMailAllow($cap2, $day + 86400) === true && confirmMailSentToday($day + 86400) === 1);
check('0 means none at all', confirmMailAllow(['confirm_mail_daily_cap' => '0'], $day + 2 * 86400) === false);
check('the default is 200 a day, garbage reads as the default, the ceiling holds', confirmMailDailyCap([]) === 200
      && confirmMailDailyCap(['confirm_mail_daily_cap' => 'lots']) === 200 && confirmMailDailyCap(['confirm_mail_daily_cap' => '9999999']) === CONFIRM_MAIL_CAP_MAX);
$rmTree($mailFile);
@mkdir($mailFile, 0777, true);
check('a count that cannot be kept sends nothing (fail-CLOSED, the opposite of the limits)', confirmMailAllow($cap2, $day + 3 * 86400) === false);
$rmTree($mailFile);

$cleanup();
$same = (is_file($idx) ? (string)file_get_contents($idx) : null) === $idxWas
     && (is_file($laFile) ? (string)file_get_contents($laFile) : null) === $laWas
     && (is_file($mailFile) ? (string)file_get_contents($mailFile) : null) === $mailWas;
check('the reset switch, the lockout file and the mail count are back exactly as they were, and no file of this run is left',
      $same && !array_filter(glob(rateLimitDir() . '/*') ?: [], fn($f) => str_contains(basename($f), $P)));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
