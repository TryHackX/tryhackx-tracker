<?php
/**
 * Test for includes/twofa.php:
 *   php tests/twofa_test.php
 *
 * The arithmetic is checked against RFC 6238's own published test vectors, because "my TOTP looks
 * right" is not a thing anyone can eyeball — a one-byte mistake in the dynamic truncation produces
 * codes that are wrong in a way nothing here would notice until an admin could not sign in.
 *
 * Everything that touches state uses a temporary file, so running this never disturbs a real setup.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/twofa.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

/* ── 1. RFC 6238 Appendix B, the SHA-1 rows ───────────────────────────────── */
//
// Seed = the ASCII string "12345678901234567890", eight digits, thirty-second step.

$seed = twofaBase32Encode('12345678901234567890');
$vectors = [
    59          => '94287082',
    1111111109  => '07081804',
    1111111111  => '14050471',
    1234567890  => '89005924',
    2000000000  => '69279037',
    20000000000 => '65353130',
];
foreach ($vectors as $t => $expect) {
    $got = twofaCodeAt($seed, $t, 8);
    check('RFC 6238 vector at T=' . $t, $got === $expect, 'got ' . $got . ', expected ' . $expect);
}

/* ── 2. base32 round trip, since that is what the operator types ──────────── */

check('base32 encodes the RFC seed the way an authenticator expects',
      $seed === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $seed);
check('base32 round-trips arbitrary bytes', twofaBase32Decode(twofaBase32Encode("\x00\xff\x10\x7f\x80")) === "\x00\xff\x10\x7f\x80");
check('spaces and lower case in a typed secret are tolerated',
      twofaBase32Decode('gezd gnbv gy3t qojq') === twofaBase32Decode('GEZDGNBVGY3TQOJQ'));
check('characters that are not base32 are ignored rather than corrupting the key',
      twofaBase32Decode('GEZD-GNBV_GY3T!QOJQ') === twofaBase32Decode('GEZDGNBVGY3TQOJQ'));

/* ── 3. the verification window ───────────────────────────────────────────── */

$now = 1700000000;
$secret = twofaBase32Encode(random_bytes(20));
$code = twofaCodeAt($secret, $now);
check('the current code verifies', twofaVerifyCode($secret, $code, $now) === intdiv($now, 30));
check('the previous step still verifies — clocks drift',
      twofaVerifyCode($secret, twofaCodeAt($secret, $now - 30), $now) === intdiv($now - 30, 30));
check('the next step verifies too', twofaVerifyCode($secret, twofaCodeAt($secret, $now + 30), $now) === intdiv($now + 30, 30));
check('two steps away does NOT — a wider window is minutes of validity for a stolen code',
      twofaVerifyCode($secret, twofaCodeAt($secret, $now + 90), $now) === null);
check('a wrong code is refused', twofaVerifyCode($secret, '000000', $now) === null || $code === '000000');
check('a five-digit code is refused outright', twofaVerifyCode($secret, '12345', $now) === null);
check('a code with spaces in it is still read', twofaVerifyCode($secret, substr($code, 0, 3) . ' ' . substr($code, 3), $now) !== null);

/* ── 4. state: replay, recovery, setup ────────────────────────────────────── */

$tmpDir = sys_get_temp_dir() . '/twofa_test_' . getmypid();
@mkdir($tmpDir . '/config', 0777, true);
@mkdir($tmpDir . '/includes', 0777, true);
// twofaFile() is __DIR__ . '/../config/admin_2fa.json'; run the state half in a copy of the library
// rooted at a temporary directory so a real setup is never touched.
copy($root . '/includes/twofa.php', $tmpDir . '/includes/twofa.php');

$runner = $tmpDir . '/run.php';
file_put_contents($runner, <<<'PHP'
<?php
require __DIR__ . '/includes/twofa.php';
$op = $argv[1] ?? '';
$arg = $argv[2] ?? '';
switch ($op) {
    case 'begin':
        echo json_encode(twofaBeginSetup(['site_name' => 'TryHackX', 'admin_username' => 'admin']));
        break;
    case 'confirm': echo json_encode(twofaConfirmSetup($arg)); break;
    case 'state':   echo json_encode(twofaState()); break;
    case 'check':   echo json_encode(['ok' => twofaCheck($arg)]); break;
    case 'code':    echo json_encode(['code' => twofaCodeAt(twofaState()['secret'], (int)$arg)]); break;
    case 'pcode':   echo json_encode(['code' => twofaCodeAt(twofaState()['pending']['secret'], (int)$arg)]); break;
    case 'rec':     echo json_encode(['left' => twofaUseRecovery($arg)]); break;
    case 'regen':   echo json_encode(twofaRegenerateRecovery()); break;
    case 'disable': echo json_encode(['ok' => twofaDisable()]); break;
    case 'enabled': echo json_encode(['on' => twofaEnabled()]); break;
}
PHP);

$php = PHP_BINARY;
$run = static function (string $op, string $arg = '') use ($php, $runner) {
    $out = [];
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($op) . ' ' . escapeshellarg($arg) . ' 2>&1', $out);
    return json_decode(trim(implode('', $out)), true);
};

check('nothing is enabled to begin with', ($run('enabled')['on'] ?? true) === false);
$begin = $run('begin');
check('setup hands back a secret, a URI and ten recovery codes',
      !empty($begin['secret']) && str_starts_with((string)$begin['uri'], 'otpauth://totp/')
      && count((array)$begin['recovery']) === 10, json_encode($begin));
check('the URI names the issuer and the account', str_contains((string)$begin['uri'], 'issuer=TryHackX'));
check('the secret is offered in groups a human can type', str_contains((string)$begin['secret_grouped'], ' '));
// The point of the two-step setup: nothing has changed yet.
check('and NOTHING is switched on until a code proves the secret arrived intact',
      ($run('enabled')['on'] ?? true) === false);

check('a wrong confirmation code is refused', !empty($run('confirm', '000000')['error']));
check('… and it still is not on', ($run('enabled')['on'] ?? true) === false);
check('the refusal points at the clock, which is what it usually is',
      str_contains((string)$run('confirm', '000000')['error'], 'clock'));

$pcode = $run('pcode', (string)time())['code'];
check('the right code turns it on', !empty($run('confirm', $pcode)['ok']));
check('… and now it is on', ($run('enabled')['on'] ?? false) === true);

// Replay: the very code that confirmed the setup must not also be a valid login.
check('the confirming code cannot be used again to sign in', ($run('check', $pcode)['ok'] ?? true) === false);

// One step ahead is inside the drift window and must work; two steps is outside it and must not.
$fresh = $run('code', (string)(time() + 30))['code'];
check('a code one step ahead verifies once', ($run('check', $fresh)['ok'] ?? false) === true);
check('… and not twice — otherwise a code seen over a shoulder is good for another 90 seconds',
      ($run('check', $fresh)['ok'] ?? true) === false);
check('a code two steps ahead is refused, however valid it looks',
      ($run('check', $run('code', (string)(time() + 120))['code'])['ok'] ?? true) === false);

$state = $run('state');
check('the secret is stored, and the recovery codes are NOT stored in the clear',
      !empty($state['secret']) && count($state['recovery']) === 10
      && preg_match('/^[a-f0-9]{64}$/', $state['recovery'][0]) === 1, json_encode($state['recovery'][0] ?? null));

$rc = $begin['recovery'][0];
check('a recovery code works and is consumed', $run('rec', $rc)['left'] === 9);
check('… and does not work a second time', $run('rec', $rc)['left'] === null);
check('… and is accepted in lower case without the dash',
      $run('rec', strtolower(str_replace('-', '', $begin['recovery'][1])))['left'] === 8);
check('a code that was never issued is refused', $run('rec', 'AAAAA-BBBBB')['left'] === null);

$regen = $run('regen');
check('regeneration hands back ten new codes', count((array)($regen['recovery'] ?? [])) === 10);
check('… and the old ones stop working', $run('rec', $begin['recovery'][2])['left'] === null);
check('… while a new one works', $run('rec', $regen['recovery'][0])['left'] === 9);

check('disabling clears the secret and every code',
      !empty($run('disable')['ok']) && ($run('enabled')['on'] ?? true) === false
      && ($run('state')['secret'] ?? 'x') === '');

$rm = static function (string $d) use (&$rm) {
    foreach (glob($d . '/*') ?: [] as $f) { is_dir($f) ? $rm($f) : @unlink($f); }
    @rmdir($d);
};
$rm($tmpDir);

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
