<?php
/**
 * The janitor's retention step (includes/retention.php, 1.73.0), with a clock passed in (needs the local test
 * database; the two config files it prunes are copied aside and put back byte for byte):
 *   php tests/retention_test.php
 *
 * Each of these had a retention rule that did not run, or ran only by accident:
 *   - API bans: pruned only by whitelistJanitor() — whitelist mode, one request in fifty;
 *   - the sign-in bridge's tickets: "called from the janitor", by nothing but the next ticket minted;
 *   - config/login_attempts.json: only the failing address's own list was trimmed — any other stayed for ever;
 *   - config/rate_limits.json: an action trims only its own keys, when it is next called.
 * What the texts say (Info: "for as long as the limit lasts") has to be true without anybody coming back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/retention.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$cfg = getSettings($db, true);
$NOW = 1790000000;   // a clock of our own: 2026-09-21 14:13:20 UTC

// The two files, aside: whatever is in them now goes back exactly as it was.
$files = [__DIR__ . '/../config/rate_limits.json', __DIR__ . '/../config/login_attempts.json'];
$saved = [];
foreach ($files as $f) $saved[$f] = is_file($f) ? file_get_contents($f) : null;
$restore = function () use ($saved) {
    foreach ($saved as $f => $body) {
        if ($body === null) @unlink($f); else file_put_contents($f, $body);
    }
};

try {
    // ── config/rate_limits.json ─────────────────────────────────────────────────────────────────
    $rl = $files[0];
    file_put_contents($rl, json_encode([
        'appeal|203.0.113.7'       => [$NOW - 7200, $NOW - 5000],          // nobody called "appeal" again: both stale
        'idxsearch|198.51.100.0/24' => [$NOW - 4000, $NOW - 120, $NOW - 5], // one stale, two live
        'health|192.0.2.1'         => [$NOW - 30],                          // live
    ]));
    $dropped = rateLimitPrune($NOW);
    $after = json_decode((string)file_get_contents($rl), true);
    check('the limits\' file: every hit older than the longest window goes, whatever the action', $dropped === 3, (string)$dropped);
    check('… a key left with nothing goes with them (the address of an action nobody called again)', !isset($after['appeal|203.0.113.7']), json_encode($after));
    check('… the live hits stay', ($after['idxsearch|198.51.100.0/24'] ?? null) === [$NOW - 120, $NOW - 5]
          && ($after['health|192.0.2.1'] ?? null) === [$NOW - 30], json_encode($after));
    check('… and a pass with nothing left to drop drops nothing', rateLimitPrune($NOW) === 0);
    check('the longest window is an hour', RATE_LIMIT_KEEP_SECONDS === 3600);
    // Every caller's window, read out of the code with PHP's own tokeniser (a call's arguments, at their own
    // depth): a window longer than the prune keeps would be forgotten early. No 4th argument = the default, 3600.
    $longer = []; $calls = 0;
    $paths = [$root . '/index.php', $root . '/api.php'];
    foreach (['api', 'includes', 'templates', 'tools'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir)) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $paths[] = $f->getPathname();
        }
    }
    foreach ($paths as $path) {
        $tok = @token_get_all((string)file_get_contents($path));
        $cnt = count($tok);
        for ($i = 0; $i < $cnt; $i++) {
            if (!is_array($tok[$i]) || $tok[$i][0] !== T_STRING || !in_array($tok[$i][1], ['rateLimitAllow', 'rateLimitPeek'], true)) continue;
            $p = $i - 1;
            while ($p >= 0 && is_array($tok[$p]) && $tok[$p][0] === T_WHITESPACE) $p--;
            if ($p >= 0 && is_array($tok[$p]) && $tok[$p][0] === T_FUNCTION) continue;   // the definition
            $j = $i + 1;
            while ($j < $cnt && is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) $j++;
            if (($tok[$j] ?? null) !== '(') continue;
            $depth = 0; $args = [''];
            for ($j++; $j < $cnt; $j++) {
                $t = $tok[$j];
                $s = is_array($t) ? $t[1] : $t;
                if ($s === '(' || $s === '[') $depth++;
                if ($s === ')' || $s === ']') { if ($depth === 0) break; $depth--; }
                if ($s === ',' && $depth === 0) { $args[] = ''; continue; }
                $args[count($args) - 1] .= $s;
            }
            $calls++;
            $win = isset($args[3]) ? trim($args[3]) : '3600';
            if (!preg_match('/^\d+$/', $win)) $longer[] = basename($path) . ': a window that is not a number (' . $win . ')';
            elseif ((int)$win > RATE_LIMIT_KEEP_SECONDS) $longer[] = basename($path) . ': ' . $win;
        }
    }
    check('the code\'s limiter calls were found', $calls > 40, (string)$calls);
    check('no caller passes a window longer than the prune keeps', $longer === [], implode(' ; ', $longer));

    // ── config/login_attempts.json ──────────────────────────────────────────────────────────────
    $la = $files[1];
    $win = loginLockWindowSec($cfg);
    file_put_contents($la, json_encode([
        '203.0.113.9'  => [$NOW - $win - 60, $NOW - $win - 1],   // failed once, never came back
        '198.51.100.4' => [$NOW - $win - 10, $NOW - 30],         // one old, one inside the window
    ]));
    $d = loginAttemptsPrune($cfg, $NOW);
    $after = json_decode((string)file_get_contents($la), true);
    check('the panel\'s failed sign-ins: every address\'s old failures go, not only the one failing now', $d === 3, (string)$d);
    check('… an address left with none is gone', !isset($after['203.0.113.9']), json_encode($after));
    check('… a failure inside the lockout window stays', ($after['198.51.100.4'] ?? null) === [$NOW - 30], json_encode($after));
    check('… nothing more to do the second time', loginAttemptsPrune($cfg, $NOW) === 0);
    check('… and a missing file is not an error', (function () use ($la, $cfg, $NOW) { @unlink($la); return loginAttemptsPrune($cfg, $NOW) === 0 && !is_file($la); })());

    // ── api_bans ────────────────────────────────────────────────────────────────────────────────
    $db->exec("DELETE FROM api_bans WHERE reason = 'retention_test'");
    $ins = $db->prepare("INSERT INTO api_bans (ip, ip_bucket, reason, detail, created_at, expires_at)
                         VALUES (?, ?, 'retention_test', ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))");
    $ins->execute(['192.0.2.10', '192.0.2.10', 'expired 100 days ago', $NOW - 130 * 86400, $NOW - 100 * 86400]);
    $ins->execute(['192.0.2.11', '192.0.2.11', 'expired 10 days ago', $NOW - 40 * 86400, $NOW - 10 * 86400]);
    $ins->execute(['192.0.2.12', '192.0.2.12', 'still active', $NOW - 86400, $NOW + 29 * 86400]);
    $gone = apiBansPrune($db, $NOW);
    $left = $db->query("SELECT detail FROM api_bans WHERE reason = 'retention_test' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    check('API bans: a row that ran out more than ' . API_BAN_KEEP_DAYS . ' days ago goes', $gone >= 1 && !in_array('expired 100 days ago', $left, true),
          $gone . ' ' . json_encode($left));
    check('… one that ran out ten days ago is kept, as is an active one', $left === ['expired 10 days ago', 'still active'], json_encode($left));
    check('… in every tracker mode: whitelistJanitor() no longer holds it (it ran in whitelist mode, one request in fifty)',
          !str_contains((string)file_get_contents($root . '/includes/whitelist.php'), 'DELETE FROM api_bans'));
    $db->exec("DELETE FROM api_bans WHERE reason = 'retention_test'");

    // ── auth_handoffs (the sign-in bridge's tickets) ────────────────────────────────────────────
    $db->exec("DELETE FROM auth_handoffs WHERE created_ip = '192.0.2.99'");
    $tk = $db->prepare("INSERT INTO auth_handoffs (token_hash, user_id, client_id, direction, expires_at, used_at, created_ip)
                        VALUES (?, 0, 0, 'in', FROM_UNIXTIME(?), ?, '192.0.2.99')");
    $tk->execute([hash('sha256', 'rt-expired'), $NOW - 7200, null]);                       // expired two hours ago
    $tk->execute([hash('sha256', 'rt-spent'), $NOW + 60, date('Y-m-d H:i:s', $NOW - 7200)]); // spent two hours ago
    $tk->execute([hash('sha256', 'rt-live'), $NOW + 60, null]);                            // still valid
    // used_at above is written in PHP's zone; the app's session zone is PHP's own (getDb() SETs it), so both agree.
    $rt = retentionTick($db, $cfg, $NOW);
    $live = $db->query("SELECT token_hash FROM auth_handoffs WHERE created_ip = '192.0.2.99'")->fetchAll(PDO::FETCH_COLUMN);
    check('the bridge\'s tickets: expired and spent ones go through the janitor\'s step', $live === [hash('sha256', 'rt-live')], json_encode($live));
    check('… which reports each step', isset($rt['api_bans'], $rt['bridge_tickets'], $rt['login_attempts'], $rt['rate_limits'])
          && $rt['bridge_tickets'] >= 2 && $rt['errors'] === [], json_encode($rt));
    $db->exec("DELETE FROM auth_handoffs WHERE created_ip = '192.0.2.99'");

    // ── the janitor runs it, in every mode, bounded ─────────────────────────────────────────────
    $jan = (string)file_get_contents($root . '/tools/janitor.php');
    check('tools/janitor.php loads the step and runs it', str_contains($jan, "require_once \$root . '/includes/retention.php';")
          && str_contains($jan, '$rt = retentionTick($db, $cfg);'));
    check('… outside any tracker-mode condition', !preg_match('/if\s*\(\s*trackerMode[^\n]*\n[^\n]*retentionTick/', $jan));
    $ret = (string)file_get_contents($root . '/includes/retention.php');
    $aa = (string)file_get_contents($root . '/includes/api_auth.php');
    $ab = (string)file_get_contents($root . '/includes/authbridge.php');
    check('the table prunes are bounded', str_contains($aa, 'LIMIT " . max(1, $limit)') && str_contains($ab, 'LIMIT " . max(1, $limit)'));
    check('the files are rewritten under their own locks',
          (bool)preg_match('/function rateLimitPrune.*?flock\(\$lockH, LOCK_EX\)/s', (string)file_get_contents($root . '/includes/functions.php'))
          && (bool)preg_match('/function loginAttemptsPrune.*?loginAttemptsUpdate\(/s', (string)file_get_contents($root . '/includes/auth.php')));
    check('the step module names what it prunes', str_contains($ret, 'apiBansPrune') && str_contains($ret, 'authHandoffPrune')
          && str_contains($ret, 'loginAttemptsPrune') && str_contains($ret, 'rateLimitPrune'));
} finally {
    $restore();
    $db->exec("DELETE FROM api_bans WHERE reason = 'retention_test'");
    $db->exec("DELETE FROM auth_handoffs WHERE created_ip = '192.0.2.99'");
}
$same = true;
foreach ($saved as $f => $body) $same = $same && ($body === null ? !is_file($f) : file_get_contents($f) === $body);
check('the two config files are back exactly as they were', $same);

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
