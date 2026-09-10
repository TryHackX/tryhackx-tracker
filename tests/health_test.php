<?php
/**
 * The health endpoint's rules (needs the local test database):
 *   php tests/health_test.php
 *
 * The HTTP behaviour — a wrong token getting the ordinary page rather than a refusal — is checked
 * over the wire in deploy/smoke_admin.py, because that is a statement about the ROUTER. This file
 * is about the two decisions underneath it: who is let in, and what counts as unhealthy.
 *
 * The one that matters most is the token floor. `health_token = 'test'` must be treated as no token
 * at all, because an endpoint whose secret can be guessed in a hundred requests is not a protected
 * endpoint — it is a public one that nobody has noticed is public.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/schedule.php';
require_once $root . '/includes/digest.php';
require_once $root . '/includes/health.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$cfg = getSettings($db, true);

// ── 1. the token ─────────────────────────────────────────────────────────────
$GOOD = 'a-token-long-enough-to-be-one';
check('no token configured means no endpoint', healthToken([]) === '' && healthToken(['health_token' => '']) === '');
check('a token shorter than the floor counts as none — it would be a public endpoint',
    healthToken(['health_token' => 'test']) === '' && healthToken(['health_token' => str_repeat('x', 15)]) === '');
check('a long enough one is the token', healthToken(['health_token' => $GOOD]) === $GOOD);

$on = ['health_token' => $GOOD];
check('the header carries it', healthAuthorised($on, $GOOD, null));
check('so does the query string, for monitors that cannot send a header', healthAuthorised($on, null, $GOOD));
check('a wrong token is not authorised', !healthAuthorised($on, 'nope', null) && !healthAuthorised($on, null, 'nope'));
check('nor is an empty one', !healthAuthorised($on, '', '') && !healthAuthorised($on, null, null));
check('and with the feature off, nothing is — not even an empty token matching an empty setting',
    !healthAuthorised(['health_token' => ''], '', '') && !healthAuthorised([], null, null));
// A prefix of the right token must not pass. hash_equals() is what makes that true; this is here so
// that a "simplification" to === or a substring compare fails a test rather than a security review.
check('a prefix of the token is not the token', !healthAuthorised($on, substr($GOOD, 0, 10), null));

// ── 2. what the report says ──────────────────────────────────────────────────
//
// Blacklist mode, because in whitelist mode the report reads the real accesslist file and the real
// tracker service — on a developer's machine those are legitimately unhealthy, and a test that
// depends on them is a test that fails for being right.
$clean = array_merge($cfg, ['tracker_mode' => 'blacklist', 'tracker_schedule_enabled' => '0']);
$r = healthReport($db, $clean);
check('a healthy tracker answers ok, with 200', $r['status'] === 'ok' && $r['http'] === 200,
    $r['status'] . ' ' . json_encode($r['body']['problems']));
check('… and says so in the body too', $r['body']['ok'] === true && $r['body']['status'] === 'ok');
check('it reports the version and the schema it expects',
    $r['body']['version'] === TRACKER_VERSION && $r['body']['schema']['expected'] === (int)TRACKER_SCHEMA_VERSION
    && $r['body']['schema']['ok'] === true, json_encode($r['body']['schema']));
check('it carries the queue depths, so one poll answers "is anybody waiting?" too',
    array_key_exists('partners', $r['body']['queues']) && array_key_exists('descriptions', $r['body']['queues'])
    && array_key_exists('meta_pending', $r['body']['queues']), json_encode($r['body']['queues']));
check('and the accesslist facts a monitor would act on',
    array_key_exists('regen_needed', $r['body']['accesslist']) && array_key_exists('fail_count', $r['body']['accesslist']),
    json_encode($r['body']['accesslist']));

// A database behind the code is the one failure this endpoint can see that nothing else reports.
$behind = array_merge($clean, ['schema_version' => (string)((int)TRACKER_SCHEMA_VERSION - 1)]);
$r2 = healthReport($db, $behind);
check('a schema behind the code is a failure, not a warning', $r2['status'] === 'fail' && $r2['http'] === 503, $r2['status']);
check('… and the alert says which versions', (bool)preg_grep('/schema \d+, expected \d+/', $r2['body']['problems']),
    json_encode($r2['body']['problems']));
check('a failing report says ok:false, so a monitor reading one field is right',
    $r2['body']['ok'] === false);

// The panel's warnings carry markup for the dashboard. An alert in a monitor is a line of text.
$whitelistish = array_merge($cfg, ['tracker_mode' => 'whitelist']);
$r3 = healthReport($db, $whitelistish);
$markup = array_filter($r3['body']['problems'], static fn($p) => str_contains((string)$p, '<'));
check('problems reach the monitor as sentences, not as dashboard markup', $markup === [], json_encode($markup));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
