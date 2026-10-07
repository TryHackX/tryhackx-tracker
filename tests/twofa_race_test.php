<?php
/**
 * A code is spent ONCE even when it is spent twice at the same moment (1.74.0, AUTH-2) — needs the local test database:
 *   php tests/twofa_race_test.php
 *
 * The audit's verifier started two PHP processes at the same instant, each spending the same code: a member's TOTP
 * code was let in twice in 10 rounds out of 10, a recovery code likewise, and the panel's own code and recovery codes
 * the same. One intercepted code, two sessions — while the 1.57.0 changelog said "a TOTP code is spent atomically".
 * The sequential replay was always refused (tests/user2fa_test.php, tests/twofa_test.php); the race was not.
 *
 * Here, for each of the four — member TOTP, member recovery code, panel TOTP, panel recovery code — ROUNDS rounds of
 * two processes released at the same microsecond: exactly one of the two may say yes, every round.
 * The member is an account of this run's own (removed on the way out); the panel's state is a copy of
 * includes/twofa.php in a temporary directory, so a real setup is never touched.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/twofa.php';
require_once $root . '/includes/user2fa.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

const ROUNDS = 6;
$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$cfg = getSettings($db, true);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'twofa_race_' . getmypid();
@mkdir($tmp . '/config', 0777, true);
@mkdir($tmp . '/includes', 0777, true);
$uid = 0;
$cleanup = function () use ($db, &$uid, $tmp): void {
    if ($uid > 0) { try { userDeleteCascade($db, $uid); } catch (\Throwable $e) { $db->prepare("DELETE FROM user_twofa WHERE user_id = ?")->execute([$uid]); } }
    foreach (array_merge(glob($tmp . '/config/*') ?: [], glob($tmp . '/includes/*') ?: [], glob($tmp . '/*.php') ?: []) as $f) @unlink($f);
    @rmdir($tmp . '/config'); @rmdir($tmp . '/includes'); @rmdir($tmp);
};
register_shutdown_function($cleanup);

/** Two processes, released together; each prints one JSON line. Returns both answers. */
$race = function (string $script, array $args) use ($tmp): array {
    $at = sprintf('%.6f', microtime(true) + 0.9);       // far enough ahead for both to be started and waiting
    $procs = [];
    foreach ([0, 1] as $i) {
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($script) . ' ' . escapeshellarg($at);
        foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $o = trim((string)stream_get_contents($pipes[1]));
        $e = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($p);
        $j = json_decode($o, true);
        $out[] = is_array($j) ? $j : ['raw' => substr($o . ' ' . $e, 0, 300)];
    }
    return $out;
};
$wins = fn(array $pair, string $k): int => count(array_filter($pair, fn($r) => !empty($r[$k])));

/* ── the member's factor (a row in user_twofa) ───────────────────────────────────────────────── */
$db->exec("DELETE FROM users WHERE username LIKE 'tfrace\\_%'");
$made = userCreate($db, array_merge($cfg, ['users_enabled' => '1']), 'tfrace_' . getmypid(), 'tfrace' . getmypid() . '@example.org', 'TfRace123!pass', '127.0.0.1');
$uid = (int)($made['user']['id'] ?? 0);
check('an account to race with', $uid > 0, json_encode($made['errors'] ?? $made));
$secret = twofaBase32Encode(random_bytes(20));
$rec = twofaMakeRecovery();
$recJson = json_encode($rec['hashes']);
$db->prepare("INSERT INTO user_twofa (user_id, secret, enabled, recovery, last_step, created_at, confirmed_at)
              VALUES (?, ?, 1, ?, NULL, NOW(), NOW())
              ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled = 1, recovery = VALUES(recovery), last_step = NULL")
   ->execute([$uid, $secret, $recJson]);
$member = $tmp . '/member.php';
file_put_contents($member, "<?php
\$root = " . var_export($root, true) . ";
require \$root . '/config/database.php';
require_once \$root . '/includes/settings.php';
require_once \$root . '/includes/functions.php';
require_once \$root . '/includes/twofa.php';
require_once \$root . '/includes/user2fa.php';
\$db = getDb();
\$at = (float)\$argv[1];
while (microtime(true) < \$at) { /* the starting line */ }
echo json_encode(['ok' => user2faVerify(\$db, (int)\$argv[2], (string)\$argv[3])]);
");
$code = twofaCodeAt($secret, time());
$res = [];
for ($r = 0; $r < ROUNDS; $r++) {
    $db->prepare("UPDATE user_twofa SET last_step = NULL WHERE user_id = ?")->execute([$uid]);
    $res[] = $wins($race($member, [(string)$uid, $code]), 'ok');
}
check('a member\'s TOTP code spent by two sign-ins at the same moment: one of them gets in, every round (' . ROUNDS . ')',
      $res === array_fill(0, ROUNDS, 1), json_encode($res));
check('… and afterwards it is spent: a third try is refused', user2faVerify($db, $uid, $code) === false);
$res = [];
for ($r = 0; $r < ROUNDS; $r++) {
    $db->prepare("UPDATE user_twofa SET recovery = ? WHERE user_id = ?")->execute([$recJson, $uid]);
    $res[] = $wins($race($member, [(string)$uid, $rec['plain'][0]]), 'ok');
}
$left = json_decode((string)$db->query("SELECT recovery FROM user_twofa WHERE user_id = " . (int)$uid)->fetchColumn(), true);
check('a member\'s recovery code spent twice at once: one sign-in, every round (' . ROUNDS . ')', $res === array_fill(0, ROUNDS, 1), json_encode($res));
check('… and the list is exactly one shorter', is_array($left) && count($left) === TWOFA_RECOVERY_COUNT - 1, json_encode($left));

/* ── the panel's factor (config/admin_2fa.json, here a copy in a temporary directory) ─────────── */
copy($root . '/includes/twofa.php', $tmp . '/includes/twofa.php');
$panel = $tmp . '/panel.php';
file_put_contents($panel, "<?php
require " . var_export($root . '/includes/lang.php', true) . ";
require __DIR__ . '/includes/twofa.php';
\$at = (float)\$argv[1];
while (microtime(true) < \$at) { /* the starting line */ }
echo json_encode(\$argv[2] === 'code' ? ['ok' => twofaCheck((string)\$argv[3])] : ['ok' => twofaUseRecovery((string)\$argv[3]) !== null]);
");
$pState = function (array $recovery) use ($tmp, $secret): void {
    file_put_contents($tmp . '/config/admin_2fa.json', json_encode(['enabled' => true, 'secret' => $secret, 'recovery' => $recovery,
        'last_step' => 0, 'confirmed_at' => time(), 'pending' => null], JSON_PRETTY_PRINT));
};
$res = [];
for ($r = 0; $r < ROUNDS; $r++) { $pState($rec['hashes']); $res[] = $wins($race($panel, ['code', $code]), 'ok'); }
check('the panel\'s TOTP code spent by two sign-ins at the same moment: one of them gets in, every round (' . ROUNDS . ')',
      $res === array_fill(0, ROUNDS, 1), json_encode($res));
$res = [];
for ($r = 0; $r < ROUNDS; $r++) { $pState($rec['hashes']); $res[] = $wins($race($panel, ['rec', $rec['plain'][1]]), 'ok'); }
$st = json_decode((string)file_get_contents($tmp . '/config/admin_2fa.json'), true);
check('the panel\'s recovery code spent twice at once: one sign-in, every round (' . ROUNDS . ')', $res === array_fill(0, ROUNDS, 1), json_encode($res));
check('… and the list is exactly one shorter', count((array)($st['recovery'] ?? [])) === TWOFA_RECOVERY_COUNT - 1);
check('the state file\'s lock is beside it (config/admin_2fa.json.lock)', is_file($tmp . '/config/admin_2fa.json.lock'));

$cleanup();
$uid = 0;
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
