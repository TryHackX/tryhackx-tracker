<?php
/**
 * Unit test for includes/schedule.php (pure functions, no DB):
 *   php tests/schedule_test.php
 * Prints PASS/FAIL lines and exits non-zero on failure.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schedule.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
/** Local wall-clock moment in the schedule tz. */
function at(string $ymdHis, string $tz = 'Europe/Warsaw'): DateTimeImmutable {
    return new DateTimeImmutable($ymdHis, new DateTimeZone($tz));
}
function fmt(?DateTimeImmutable $t): string {
    return $t ? $t->format('D Y-m-d H:i T') : 'null';
}

// The owner's example: Mon–Fri whitelist 10:00 → 02:30 next day, Sat + Sun whitelist all day.
$example = json_encode([
    'mon' => ['from' => '10:00', 'to' => '02:30'],
    'tue' => ['from' => '10:00', 'to' => '02:30'],
    'wed' => ['from' => '10:00', 'to' => '02:30'],
    'thu' => ['from' => '10:00', 'to' => '02:30'],
    'fri' => ['from' => '10:00', 'to' => '02:30'],
    'sat' => 'all',
    'sun' => 'all',
]);
$cfg = ['tracker_schedule_enabled' => '1', 'tracker_schedule' => $example, 'tracker_schedule_tz' => 'Europe/Warsaw', 'tracker_mode' => 'whitelist'];

// Week of 2026-08-17 (Mon) … 2026-08-23 (Sun); 2026-08-24 is a Monday.
$cases = [
    ['Tue 01:00', '2026-08-18 01:00:00', 'whitelist'],
    ['Tue 03:00', '2026-08-18 03:00:00', 'blacklist'],
    ['Tue 12:00', '2026-08-18 12:00:00', 'whitelist'],
    ['Sat 05:00', '2026-08-22 05:00:00', 'whitelist'],
    ['Sun 23:59', '2026-08-23 23:59:00', 'whitelist'],
    ['Mon 00:30', '2026-08-24 00:30:00', 'blacklist'],
    ['Mon 09:59', '2026-08-24 09:59:00', 'blacklist'],
    ['Mon 10:00', '2026-08-24 10:00:00', 'whitelist'],
    ['Fri 23:00', '2026-08-21 23:00:00', 'whitelist'],
    ['Sat 02:29', '2026-08-22 02:29:00', 'whitelist'],
    ['Tue 02:29:59', '2026-08-18 02:29:59', 'whitelist'],
    ['Tue 02:30:00', '2026-08-18 02:30:00', 'blacklist'],
    ['Mon 10:00:30', '2026-08-24 10:00:30', 'whitelist'],
];
foreach ($cases as [$label, $when, $exp]) {
    $got = scheduleDesiredMode($cfg, at($when));
    check("desired mode $label = $exp", $got === $exp, "got " . var_export($got, true));
}

// UTC input must be converted to the schedule zone (2026-08 = CEST, UTC+2): 08:30 UTC = 10:30 Warsaw
check('desired mode from a UTC instant (Mon 08:30Z = 10:30 CEST)', scheduleDesiredMode($cfg, at('2026-08-24 08:30:00', 'UTC')) === 'whitelist');
check('desired mode from a UTC instant (Mon 07:30Z = 09:30 CEST)', scheduleDesiredMode($cfg, at('2026-08-24 07:30:00', 'UTC')) === 'blacklist');

// next change
$nc = [
    ['Tue 01:00', '2026-08-18 01:00:00', '2026-08-18 02:30:00'],
    ['Tue 03:00', '2026-08-18 03:00:00', '2026-08-18 10:00:00'],
    ['Tue 12:00', '2026-08-18 12:00:00', '2026-08-19 02:30:00'],
    ['Fri 23:00 (Fri window spills into Sat=all → next change Mon 00:00)', '2026-08-21 23:00:00', '2026-08-24 00:00:00'],
    ['Sat 05:00', '2026-08-22 05:00:00', '2026-08-24 00:00:00'],
    ['Sun 23:59', '2026-08-23 23:59:00', '2026-08-24 00:00:00'],
    ['Mon 00:30', '2026-08-24 00:30:00', '2026-08-24 10:00:00'],
    ['Mon 10:00 (boundary itself → next is Tue 02:30)', '2026-08-24 10:00:00', '2026-08-25 02:30:00'],
];
foreach ($nc as [$label, $when, $exp]) {
    $got = scheduleNextChange($cfg, at($when));
    $expT = at($exp);
    check("next change from $label = $exp", $got !== null && $got->getTimestamp() === $expT->getTimestamp(), 'got ' . fmt($got));
}
$got = scheduleNextChange($cfg, at('2026-08-18 01:00:00'));
check('next change is returned in the schedule timezone', $got && $got->getTimezone()->getName() === 'Europe/Warsaw', $got ? $got->getTimezone()->getName() : 'null');

// describe: grouping
check('describe groups consecutive identical days',
    scheduleDescribe($cfg) === 'Mon–Fri 10:00–02:30 (next day), Sat–Sun all day (Europe/Warsaw)', scheduleDescribe($cfg));

// disabled schedule → null desired / next, describe still works
$off = $cfg; $off['tracker_schedule_enabled'] = '0';
check('disabled → desired null', scheduleDesiredMode($off, at('2026-08-18 12:00:00')) === null);
check('disabled → next change null', scheduleNextChange($off, at('2026-08-18 12:00:00')) === null);
check('disabled → describe still works', str_starts_with(scheduleDescribe($off), 'Mon–Fri 10:00'));
check('scheduleParse null when disabled', scheduleParse($off) === null);
check('scheduleParse(requireEnabled=false) works when disabled', is_array(scheduleParse($off, false)));

// same-day window (to > from), and a window ending exactly at midnight next day
$sameDay = $cfg;
$sameDay['tracker_schedule'] = json_encode(['mon' => ['from' => '08:00', 'to' => '18:00'], 'tue' => 'none', 'wed' => ['from' => '22:00', 'to' => '00:00'], 'thu' => 'none', 'fri' => 'none', 'sat' => 'none', 'sun' => 'none']);
check('same-day window Mon 07:59 → blacklist', scheduleDesiredMode($sameDay, at('2026-08-17 07:59:00')) === 'blacklist');
check('same-day window Mon 08:00 → whitelist', scheduleDesiredMode($sameDay, at('2026-08-17 08:00:00')) === 'whitelist');
check('same-day window Mon 17:59 → whitelist', scheduleDesiredMode($sameDay, at('2026-08-17 17:59:00')) === 'whitelist');
check('same-day window Mon 18:00 → blacklist', scheduleDesiredMode($sameDay, at('2026-08-17 18:00:00')) === 'blacklist');
check('same-day window Mon 23:00 → blacklist', scheduleDesiredMode($sameDay, at('2026-08-17 23:00:00')) === 'blacklist');
check('window 22:00–00:00 Wed 23:30 → whitelist', scheduleDesiredMode($sameDay, at('2026-08-19 23:30:00')) === 'whitelist');
check('window 22:00–00:00 Thu 00:00 → blacklist', scheduleDesiredMode($sameDay, at('2026-08-20 00:00:00')) === 'blacklist');
$got = scheduleNextChange($sameDay, at('2026-08-17 12:00:00'));
check('same-day window next change Mon 12:00 → Mon 18:00', $got && $got->getTimestamp() === at('2026-08-17 18:00:00')->getTimestamp(), fmt($got));
$got = scheduleNextChange($sameDay, at('2026-08-20 01:00:00'));
check('next change wraps to next week (Thu 01:00 → next Mon 08:00)', $got && $got->getTimestamp() === at('2026-08-24 08:00:00')->getTimestamp(), fmt($got));
check('describe with single days and same-day windows', scheduleDescribe($sameDay) === 'Mon 08:00–18:00, Wed 22:00–00:00 (next day) (Europe/Warsaw)', scheduleDescribe($sameDay));

// Sunday window spilling into Monday of the next week
$spill = $cfg;
$spill['tracker_schedule'] = json_encode(['sun' => ['from' => '20:00', 'to' => '03:00']]);   // other days default to none
check('missing days default to none (parse ok)', scheduleParseJson($spill['tracker_schedule']) !== null);
check('Sunday spill: Mon 02:00 → whitelist', scheduleDesiredMode($spill, at('2026-08-24 02:00:00')) === 'whitelist');
check('Sunday spill: Mon 03:00 → blacklist', scheduleDesiredMode($spill, at('2026-08-24 03:00:00')) === 'blacklist');
$got = scheduleNextChange($spill, at('2026-08-24 02:00:00'));
check('Sunday spill: next change Mon 02:00 → Mon 03:00', $got && $got->getTimestamp() === at('2026-08-24 03:00:00')->getTimestamp(), fmt($got));

// constant schedules → no next change
$allWeek = $cfg; $allWeek['tracker_schedule'] = json_encode(array_fill_keys(SCHEDULE_DAYS, 'all'));
check('all week whitelist → desired whitelist', scheduleDesiredMode($allWeek, at('2026-08-19 03:00:00')) === 'whitelist');
check('all week whitelist → next change null', scheduleNextChange($allWeek, at('2026-08-19 03:00:00')) === null);
$noneWeek = $cfg; $noneWeek['tracker_schedule'] = json_encode(array_fill_keys(SCHEDULE_DAYS, 'none'));
check('all week open → desired blacklist', scheduleDesiredMode($noneWeek, at('2026-08-19 03:00:00')) === 'blacklist');
check('all week open → next change null', scheduleNextChange($noneWeek, at('2026-08-19 03:00:00')) === null);
check('all week open → describe', str_starts_with(scheduleDescribe($noneWeek), 'no whitelist hours'), scheduleDescribe($noneWeek));

// 24 h window: to == from → ends next day at the same time
$full = $cfg; $full['tracker_schedule'] = json_encode(['tue' => ['from' => '10:00', 'to' => '10:00']]);
check('to == from → 24 h window (Wed 09:59 whitelist)', scheduleDesiredMode($full, at('2026-08-19 09:59:00')) === 'whitelist');
check('to == from → 24 h window (Wed 10:00 blacklist)', scheduleDesiredMode($full, at('2026-08-19 10:00:00')) === 'blacklist');

// JSON validation
check('parse rejects unknown key', scheduleParseJson('{"mon":"all","xyz":"all"}') === null);
check('parse rejects bad time', scheduleParseJson('{"mon":{"from":"25:00","to":"02:00"}}') === null);
check('parse rejects bad time (minutes)', scheduleParseJson('{"mon":{"from":"10:60","to":"02:00"}}') === null);
check('parse rejects bad value', scheduleParseJson('{"mon":"sometimes"}') === null);
check('parse rejects non-object', scheduleParseJson('"all"') === null);
check('parse rejects garbage', scheduleParseJson('not json') === null);
$p = scheduleParseJson('{"mon":{"from":"9:05","to":"2:00"}}');
check('parse normalises times to HH:MM', $p !== null && $p['mon'] === ['from' => '09:05', 'to' => '02:00'], json_encode($p));
check('desired null on invalid JSON when enabled', scheduleDesiredMode(['tracker_schedule_enabled' => '1', 'tracker_schedule' => 'nope'], at('2026-08-19 10:00:00')) === null);

// timezone handling
check('valid tz accepted', scheduleValidTimezone('Europe/Warsaw') && scheduleValidTimezone('UTC'));
check('invalid tz rejected', !scheduleValidTimezone('Mars/Olympus') && !scheduleValidTimezone(''));
check('invalid tz falls back to default', scheduleTimezone(['tracker_schedule_tz' => 'Nope/Nope']) === 'Europe/Warsaw');
$tokyo = $cfg; $tokyo['tracker_schedule_tz'] = 'Asia/Tokyo';
// Mon 10:00 Warsaw (08:00Z) = Mon 17:00 Tokyo → whitelist in Tokyo schedule; Mon 00:30 Tokyo (Sun 15:30Z) = blacklist
check('tz respected: Sun 15:30Z is Mon 00:30 Tokyo → blacklist', scheduleDesiredMode($tokyo, at('2026-08-23 15:30:00', 'UTC')) === 'blacklist');
check('tz respected: Sun 15:30Z is Sun 17:30 Warsaw → whitelist', scheduleDesiredMode($cfg, at('2026-08-23 15:30:00', 'UTC')) === 'whitelist');

// switch command validation
check('cmd: default valid', scheduleValidSwitchCommand(SCHEDULE_DEFAULT_CMD));
check('cmd: empty valid', scheduleValidSwitchCommand(''));
check('cmd: metacharacters rejected', !scheduleValidSwitchCommand('sudo x; rm -rf /') && !scheduleValidSwitchCommand('a && b') && !scheduleValidSwitchCommand('a | b') && !scheduleValidSwitchCommand('$(x)') && !scheduleValidSwitchCommand("a\nb") && !scheduleValidSwitchCommand('a > b'));
check('cmd: invalid config → not executed (empty)', scheduleSwitchCommand(['tracker_mode_switch_cmd' => 'sudo x; y']) === '');
check('cmd: unset → default', scheduleSwitchCommand([]) === SCHEDULE_DEFAULT_CMD);

// status shape
$st = scheduleStatus($cfg, at('2026-08-18 01:00:00'));
check('status: desired + next change', $st['enabled'] && $st['desired'] === 'whitelist' && $st['next_change'] === at('2026-08-18 02:30:00')->getTimestamp() && $st['next_change_local'] === 'Tue 2026-08-18 02:30', json_encode($st));
check('scheduleFormatLocal', scheduleFormatLocal($cfg, at('2026-08-18 02:30:00')) === '02:30 Tue');

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
