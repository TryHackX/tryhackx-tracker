<?php
/**
 * Database memory (includes/dbmem.php + tools/opentracker/tracker-dbmem.sh).
 *
 * The PHP half against fixed status documents, and the helper end to end against a STUB client:
 * a script that answers the few statements the helper sends (VERSION(), SHOW GLOBAL VARIABLES,
 * SHOW GLOBAL STATUS, SET GLOBAL) from a file it keeps, so "applied live" is a fact this test can
 * read back, and the drop-in lands in a temporary directory nobody else uses.
 *
 *   php tests/dbmem_test.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/dbmem.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    if (!$ok) $fails++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
}

/* ── 1. configuration rules ─────────────────────────────────────────────── */
check('off by default', !dbmemEnabled([]));
check('a command alone is not on', !dbmemEnabled(['dbmem_cmd' => 'sudo -n /x.sh']));
check('on with both', dbmemEnabled(['dbmem_cmd' => 'sudo -n /x.sh', 'dbmem_enabled' => '1']));
check('a command with a shell metacharacter is refused', !dbmemValidCommand('sudo -n /x.sh; id') && !dbmemValidCommand('sudo -n `id`'));
check('the ordinary command is accepted', dbmemValidCommand('sudo -n /usr/local/sbin/tracker-dbmem.sh'));

/* ── 2. validation against a status document ────────────────────────────── */
$st = ['ok' => true, 'engine' => 'mariadb', 'mem_total_kb' => 11963392, 'mem_available_kb' => 1200000,
       'vars' => ['innodb_buffer_pool_size' => 1610612736, 'innodb_buffer_pool_size_max' => 2147483648, 'max_connections' => 151,
                  'tmp_table_size' => 16777216, 'max_heap_table_size' => 16777216, 'table_open_cache' => 2000, 'innodb_log_file_size' => 100663296],
       'keys' => ['innodb_buffer_pool_size' => ['unit' => 'bytes', 'min' => 67108864, 'max' => 11713642496, 'dynamic' => true, 'supported' => true],
                  'innodb_buffer_pool_size_max' => ['unit' => 'bytes', 'min' => 67108864, 'max' => 12250513408, 'dynamic' => false, 'supported' => true],
                  'innodb_log_file_size' => ['unit' => 'bytes', 'min' => 16777216, 'max' => 8589934592, 'dynamic' => true, 'supported' => true],
                  'max_connections' => ['unit' => 'count', 'min' => 10, 'max' => 10000, 'dynamic' => true, 'supported' => true],
                  'tmp_table_size' => ['unit' => 'bytes', 'min' => 1048576, 'max' => 4294967296, 'dynamic' => true, 'supported' => true],
                  'max_heap_table_size' => ['unit' => 'bytes', 'min' => 1048576, 'max' => 4294967296, 'dynamic' => true, 'supported' => true],
                  'table_open_cache' => ['unit' => 'count', 'min' => 100, 'max' => 1000000, 'dynamic' => true, 'supported' => true]],
       'file_values' => ['innodb_buffer_pool_size' => 1610612736, 'innodb_buffer_pool_size_max' => 2147483648],
       'status' => ['Innodb_buffer_pool_pages_total' => 97344, 'Innodb_buffer_pool_pages_free' => 1604, 'Innodb_buffer_pool_reads' => 92206144,
                    'Innodb_buffer_pool_read_requests' => 13288826895, 'Created_tmp_tables' => 818716, 'Created_tmp_disk_tables' => 54019,
                    'Max_used_connections' => 21, 'Threads_connected' => 5, 'Uptime' => 900000],
       'conflicts' => []];
check('a sane pool size passes', dbmemValidate('innodb_buffer_pool_size', 2147483648, $st) === '');
check('a pool below the floor is refused', dbmemValidate('innodb_buffer_pool_size', 1024, $st) !== '');
check('a pool above the ceiling is refused', dbmemValidate('innodb_buffer_pool_size', 20000000000, $st) !== '');
check('a string of digits is a number', dbmemValidate('max_connections', '200', $st) === '');
check('rubbish is not a number', dbmemValidate('max_connections', '2e2', $st) !== '' && dbmemValidate('max_connections', '20 0', $st) !== '');
check('an unknown key is refused', dbmemValidate('innodb_evil', 1, $st) !== '');
$stMy = $st; $stMy['keys']['innodb_buffer_pool_size_max']['supported'] = false;
check('a key this engine lacks is refused', dbmemValidate('innodb_buffer_pool_size_max', 2147483648, $stMy) !== '');
check('bytes are humanised, counts are not', dbmemHumanValue('innodb_buffer_pool_size', 1610612736) === '1.5 GiB' && dbmemHumanValue('max_connections', 1500) === '1,500');

/* ── 3. what the counters say ───────────────────────────────────────────── */
$adv = dbmemAdvice($st);
$texts = implode(' | ', array_map(fn($a) => $a['text'], $adv));
check('a full pool with disk reads is called out', count(array_filter($adv, fn($a) => $a['level'] === 'warn')) >= 1, $texts);
check('no restart is pending when the drop-in matches the engine', dbmemRestartPending($st) === []);
$st2 = $st; $st2['file_values']['innodb_buffer_pool_size'] = 2147483648;
check('a drop-in value the engine is not running is a pending restart', dbmemRestartPending($st2) === ['innodb_buffer_pool_size']);
$st3 = $st; $st3['conflicts'] = [['file' => '/etc/mysql/mariadb.conf.d/60-old.cnf', 'key' => 'innodb_buffer_pool_size', 'value' => 1]];
check('another file setting the same key is reported', str_contains(implode(' ', array_map(fn($a) => $a['text'], dbmemAdvice($st3))), '60-old.cnf'));

/* ── 4. the state file round-trips and the tick only runs in the CLI ────── */
$backup = is_file(dbmemStateFile()) ? file_get_contents(dbmemStateFile()) : null;
dbmemStateUpdate(function (array &$s) { $s['pending'] = ['max_connections' => 200]; $s['pending_at'] = 123; return true; });
check('pending pairs survive a write and a read', (dbmemStateRead()['pending']['max_connections'] ?? null) === 200);
$tickOff = dbmemTick(['dbmem_cmd' => '', 'dbmem_enabled' => '0']);
check('the tick does nothing while the feature is off', $tickOff['did'] === null);

/* ── 4b. what the review asked of the helper and a non-root test cannot run ─ */
$sh = (string)@file_get_contents($root . '/tools/opentracker/tracker-dbmem.sh');
check('helper: sets its own PATH before looking anything up', preg_match('/^PATH=\/usr\/local\/sbin:/m', $sh) === 1);
check('helper: refuses the test hooks when running as root', str_contains($sh, 'HOOKS_SET') && str_contains($sh, 'not accepted as root'));
check('helper: every client call is bounded by timeout', substr_count($sh, 'bounded "$DB_TIMEOUT"') >= 3);
check('helper: systemctl restart is bounded too', str_contains($sh, 'bounded "$SYSTEMCTL_TIMEOUT" "$SYSTEMCTL_BIN" restart'));
check('helper: two restarts within the gap are refused', str_contains($sh, 'RESTART_MIN_GAP') && str_contains($sh, 'restarted $(( now - last )) s ago'));
check('helper: a key the server lacks is refused in parse_pairs (before apply and persist write anything)', str_contains($sh, 'key_supported "$k" || failerr'));

/* ── 5. the helper, end to end, against a stub client ───────────────────── */
$helper = $root . '/tools/opentracker/tracker-dbmem.sh';
$bash = null;
foreach (['bash', '/bin/bash', '/usr/bin/bash', 'C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $cand) {
    $probe = []; $rc = null;
    @exec((str_contains($cand, ' ') ? '"' . $cand . '"' : $cand) . ' -c "echo ok" 2>&1', $probe, $rc);
    if ($rc === 0 && trim(implode('', $probe)) === 'ok') { $bash = $cand; break; }
}
if ($bash === null || !trackerExecAvailable() || !is_file($helper)) {
    echo "SKIP helper end to end  -> no usable bash (or exec() disabled) — run this suite on the server for that half\n";
} else {
    $posix = fn(string $p) => str_replace('\\', '/', $p);
    $tmp = sys_get_temp_dir() . '/dbmem_test_' . getmypid();
    @mkdir($tmp . '/conf', 0777, true);
    $vars = $tmp . '/vars.txt';
    // the stub: answers from vars.txt; SET GLOBAL rewrites the line; the log records every statement
    file_put_contents($vars, "innodb_buffer_pool_size\t1610612736\ninnodb_buffer_pool_size_max\t2147483648\ninnodb_buffer_pool_chunk_size\t0\n"
        . "innodb_buffer_pool_instances\t1\ninnodb_log_file_size\t100663296\nmax_connections\t151\ntmp_table_size\t16777216\nmax_heap_table_size\t16777216\ntable_open_cache\t2000\n");
    $stub = $tmp . '/client.sh';
    file_put_contents($stub, <<<'SH'
#!/usr/bin/env bash
# a database client that is a text file: -N -B -e "<sql>"
sql=""
while [ $# -gt 0 ]; do case "$1" in -e) sql="$2"; shift 2 ;; *) shift ;; esac; done
echo "$sql" >> "$STUB_LOG"
case "$sql" in
  "SELECT 1") echo 1 ;;
  "SELECT VERSION()") echo "$STUB_VERSION" ;;
  "SHOW GLOBAL VARIABLES"*)
     # the backported key exists on 10.11.12+ / 11.4.6+ / 11.8.2+ only; the stub models one of them
     case "$STUB_VERSION" in *11.8*MariaDB*) cat "$STUB_VARS" ;; *) grep -v '^innodb_buffer_pool_size_max' "$STUB_VARS" ;; esac ;;
  "SHOW GLOBAL STATUS"*) printf 'Innodb_buffer_pool_pages_total\t97344\nInnodb_buffer_pool_pages_free\t1604\nUptime\t900000\nThreads_connected\t5\nMax_used_connections\t21\n' ;;
  "SET GLOBAL "*)
     k="$(printf '%s' "$sql" | sed -E 's/^SET GLOBAL ([a-z_]+) = ([0-9]+)$/\1/')"; v="$(printf '%s' "$sql" | sed -E 's/^SET GLOBAL ([a-z_]+) = ([0-9]+)$/\2/')"
     [ "$STUB_REFUSE" = "$k" ] && exit 1
     awk -F'\t' -v k="$k" -v v="$v" 'BEGIN{OFS="\t"} $1==k {$2=v} {print}' "$STUB_VARS" > "$STUB_VARS.new" && mv "$STUB_VARS.new" "$STUB_VARS" ;;
  *) exit 1 ;;
esac
SH
    );
    @chmod($stub, 0755);
    file_put_contents($tmp . '/meminfo', "MemTotal:       11963392 kB\nMemAvailable:    1200000 kB\n");
    // Environment through putenv, not a `VAR=x cmd` prologue: PHP's exec goes through cmd.exe on
    // Windows, which has no such syntax.
    foreach ([
        'DBMEM_CLIENT'  => $posix($stub),
        'DBMEM_CONF_DIR' => $posix($tmp . '/conf'),
        'DBMEM_MEMINFO' => $posix($tmp . '/meminfo'),
        'STUB_VARS'     => $posix($vars),
        'STUB_LOG'      => $posix($tmp . '/log.txt'),
        'DBMEM_DATADIR_FREE' => '34359738368',   // 32 GiB free: the redo-log ceiling stays at 8 GiB
    ] as $ev => $val) putenv($ev . '=' . $val);
    $bashCmd = str_contains($bash, ' ') ? '"' . $bash . '"' : $bash;
    $run = static function (string $version, string $args, string $refuse = '') use ($bashCmd, $helper, $posix): array {
        putenv('STUB_VERSION=' . $version); putenv('STUB_REFUSE=' . $refuse);
        $cmd = $bashCmd . ' ' . escapeshellarg($posix($helper)) . ' ' . $args . ' 2>&1';
        $out = []; @exec($cmd, $out);
        $last = null;
        for ($i = count($out) - 1; $i >= 0; $i--) { $l = trim($out[$i]); if ($l !== '' && $l[0] === '{') { $last = json_decode($l, true); if (is_array($last)) break; $last = null; } }
        return ['json' => $last, 'raw' => implode("\n", $out)];
    };

    $s = $run('11.8.6-MariaDB-0+deb13u1', 'status');
    check('status: the helper answers with JSON', is_array($s['json']) && !empty($s['json']['ok']), $s['raw']);
    check('status: MariaDB 11.8 is recognised', ($s['json']['engine'] ?? '') === 'mariadb' && !empty($s['json']['keys']['innodb_buffer_pool_size']['dynamic']), $s['raw']);
    check('status: the pool ceiling is startup-only and supported on MariaDB 11', ($s['json']['keys']['innodb_buffer_pool_size_max']['dynamic'] ?? true) === false && !empty($s['json']['keys']['innodb_buffer_pool_size_max']['supported']));
    check('status: the log file size is dynamic on MariaDB >= 10.9', !empty($s['json']['keys']['innodb_log_file_size']['dynamic']));
    check('status: the ceiling for the pool leaves 512 MiB to the machine', (int)($s['json']['keys']['innodb_buffer_pool_size']['max'] ?? 0) === 11963392 * 1024 - 536870912);
    check('status: the reply is one JSON line, built whole and printed once', substr_count(trim($s['raw']), "\n") === 0, $s['raw']);
    check('status: the redo-log ceiling is 8 GiB when the data disk has room', (int)($s['json']['keys']['innodb_log_file_size']['max'] ?? 0) === 8589934592);
    check('status: the client was asked once for the variables (cached), not once per key',
          substr_count((string)@file_get_contents($tmp . '/log.txt'), 'SHOW GLOBAL VARIABLES') <= 2, (string)@file_get_contents($tmp . '/log.txt'));
    putenv('DBMEM_DATADIR_FREE=1073741824');
    $sd = $run('11.8.6-MariaDB', 'status');
    check('status: the redo-log ceiling is a quarter of the free space on the data disk', (int)($sd['json']['keys']['innodb_log_file_size']['max'] ?? 0) === 268435456, $sd['raw']);
    $ld = $run('11.8.6-MariaDB', 'apply innodb_log_file_size=536870912');
    check('apply: a redo log that would fill the data disk is refused', empty($ld['json']['ok']) && str_contains((string)($ld['json']['error'] ?? ''), 'ceiling'), $ld['raw']);
    putenv('DBMEM_DATADIR_FREE=34359738368');

    $m = $run('8.0.36-0ubuntu0.22.04.1', 'status');
    check('status: MySQL 8 is recognised', ($m['json']['engine'] ?? '') === 'mysql', $m['raw']);
    check('status: on MySQL the pool is dynamic, the log file size is not, the ceiling does not exist',
          !empty($m['json']['keys']['innodb_buffer_pool_size']['dynamic']) && empty($m['json']['keys']['innodb_log_file_size']['dynamic'])
          && ($m['json']['keys']['innodb_buffer_pool_size_max']['supported'] ?? true) === false, $m['raw']);
    $o = $run('10.6.18-MariaDB', 'status');
    check('status: MariaDB 10.6 grows the pool live but not the redo log', !empty($o['json']['keys']['innodb_buffer_pool_size']['dynamic']) && empty($o['json']['keys']['innodb_log_file_size']['dynamic']), $o['raw']);
    check('status: existence of the pool ceiling comes from the server, not a version table (11.4.5 lacks it)',
          ($run('11.4.5-MariaDB', 'status')['json']['keys']['innodb_buffer_pool_size_max']['supported'] ?? true) === false);
    $nx = $run('8.0.36-0ubuntu0.22.04.1', 'apply innodb_buffer_pool_size_max=2147483648');
    check('apply: a key this server lacks is refused, so it never reaches the drop-in', empty($nx['json']['ok']) && str_contains((string)($nx['json']['error'] ?? ''), 'not a variable'), $nx['raw']);
    $nx2 = $run('11.4.5-MariaDB', 'persist innodb_buffer_pool_size_max=2147483648');
    check('persist: the same refusal (a drop-in naming an unknown variable stops the engine)', empty($nx2['json']['ok']), $nx2['raw']);
    check('persist: nothing was written for the refused key', !is_file($tmp . '/conf/70-tracker-panel.cnf'));

    $a = $run('11.8.6-MariaDB', 'apply innodb_buffer_pool_size=1879048192 max_connections=200');
    check('apply: both keys changed live and were read back', !empty($a['json']['ok'])
          && !empty($a['json']['applied']['innodb_buffer_pool_size']['live']) && (int)($a['json']['applied']['innodb_buffer_pool_size']['landed'] ?? 0) === 1879048192
          && !empty($a['json']['applied']['max_connections']['live']), $a['raw']);
    $conf = @file_get_contents($tmp . '/conf/70-tracker-panel.cnf') ?: '';
    check('apply: the drop-in was written under [mysqld] with both keys in bytes',
          str_contains($conf, '[mysqld]') && preg_match('/^innodb_buffer_pool_size\s+= 1879048192/m', $conf) === 1 && preg_match('/^max_connections\s+= 200/m', $conf) === 1, $conf);
    check('apply: the statements sent were SET GLOBAL with integers and nothing else',
          preg_match_all('/^SET GLOBAL [a-z_]+ = \d+$/m', (string)@file_get_contents($tmp . '/log.txt')) === 2);

    $b = $run('11.8.6-MariaDB', 'apply innodb_buffer_pool_size=3221225472');
    check('apply: a pool above innodb_buffer_pool_size_max is not set live and says restart', !empty($b['json']['ok'])
          && empty($b['json']['applied']['innodb_buffer_pool_size']['live']) && !empty($b['json']['applied']['innodb_buffer_pool_size']['restart_required']), $b['raw']);

    $c = $run('11.8.6-MariaDB', 'apply innodb_buffer_pool_size_max=4294967296');
    check('apply: a startup-only key goes to the drop-in and says restart', !empty($c['json']['applied']['innodb_buffer_pool_size_max']['restart_required']), $c['raw']);
    $confC = (string)@file_get_contents($tmp . '/conf/70-tracker-panel.cnf');
    check('apply: the version-dependent key is written with the loose- prefix', preg_match('/^loose-innodb_buffer_pool_size_max\s+= 4294967296/m', $confC) === 1, $confC);
    $sc = $run('11.8.6-MariaDB', 'status');
    check('status: the loose- line is read back under the key\'s own name', (int)($sc['json']['file_values']['innodb_buffer_pool_size_max'] ?? 0) === 4294967296, $sc['raw']);

    $d = $run('11.8.6-MariaDB', 'apply max_connections=5');
    check('apply: below the floor is refused before anything runs', empty($d['json']['ok']) && str_contains((string)($d['json']['error'] ?? ''), 'floor'), $d['raw']);
    $e = $run('11.8.6-MariaDB', 'apply innodb_evil=1');
    check('apply: an unknown key is refused', empty($e['json']['ok']), $e['raw']);
    $f = $run('11.8.6-MariaDB', 'apply ' . escapeshellarg('max_connections=200; id'));
    check('apply: a value with a shell payload is refused', empty($f['json']['ok']), $f['raw']);
    $f2 = $run('11.8.6-MariaDB', 'apply innodb_buffer_pool_size=1e9');
    check('apply: an exponent is not a whole number', empty($f2['json']['ok']), $f2['raw']);
    $g = $run('11.8.6-MariaDB', 'apply tmp_table_size=33554432', 'tmp_table_size');
    check('apply: a refused SET GLOBAL becomes "restart required", not a lie', !empty($g['json']['applied']['tmp_table_size']['restart_required']) && empty($g['json']['applied']['tmp_table_size']['live']), $g['raw']);

    // status after apply reads the file back and reports the conflict with a sibling file
    file_put_contents($tmp . '/conf/60-old.cnf', "[mysqld]\ninnodb_buffer_pool_size = 1536M\n");
    $s2 = $run('11.8.6-MariaDB', 'status');
    check('status: the drop-in values are read back', (int)($s2['json']['file_values']['tmp_table_size'] ?? 0) === 33554432, $s2['raw']);
    check('status: a sibling file setting a managed key is a conflict, with its value in bytes',
          !empty($s2['json']['conflicts']) && (int)$s2['json']['conflicts'][0]['value'] === 1610612736, $s2['raw']);
    file_put_contents($tmp . '/conf/61-dash.cnf', "[mysqld]\nloose-innodb-buffer-pool-size = \"1GiB\"\r\n");
    $s3 = $run('11.8.6-MariaDB', 'status');
    $dash = array_values(array_filter($s3['json']['conflicts'] ?? [], fn($c) => str_ends_with((string)$c['file'], '61-dash.cnf')));
    check('status: dashes, a loose- prefix, quotes, CRLF and "GiB" in a sibling file are still the same key and value',
          $dash !== [] && ($dash[0]['key'] ?? '') === 'innodb_buffer_pool_size' && (int)$dash[0]['value'] === 1073741824, $s3['raw']);

    $p = $run('11.8.6-MariaDB', 'persist max_connections=300');
    check('persist writes the drop-in', !empty($p['json']['persisted']) && preg_match('/^max_connections\s+= 300/m', (string)@file_get_contents($tmp . '/conf/70-tracker-panel.cnf')) === 1, $p['raw']);
    putenv('DBMEM_CONF_DIR=' . $posix($tmp . '/nope'));
    $p2 = $run('11.8.6-MariaDB', 'persist max_connections=300');
    check('persist into a place that does not exist is deferred, not an error', !empty($p2['json']['ok']) && !empty($p2['json']['deferred']), $p2['raw']);
    putenv('DBMEM_CONF_DIR=' . $posix($tmp . '/conf'));

    // clean up
    foreach (glob($tmp . '/conf/*') ?: [] as $f) @unlink($f);
    putenv('DBMEM_DATADIR_FREE');
    foreach (['vars.txt', 'client.sh', 'log.txt', 'meminfo'] as $f) @unlink($tmp . '/' . $f);
    @rmdir($tmp . '/conf'); @rmdir($tmp);
    foreach (['DBMEM_CLIENT', 'DBMEM_CONF_DIR', 'DBMEM_MEMINFO', 'STUB_VARS', 'STUB_LOG', 'STUB_VERSION', 'STUB_REFUSE'] as $ev) putenv($ev);
}

/* ── 6. the rest of the panel knows about it ────────────────────────────── */
$sv = file_get_contents($root . '/api/admin/save_settings.php');
check('the two keys are in the save allow-list', str_contains($sv, "'dbmem_cmd', 'dbmem_enabled'"));
check('the helper command is behind the password gate', str_contains($sv, "'dbmem_cmd'") && preg_match('/\$reauthKeys = \[.*?\'dbmem_cmd\'/s', $sv) === 1);
check('the command is validated on save', str_contains($sv, 'dbmemValidCommand'));
require_once $root . '/includes/schema.php';
$defaults = trackerSchemaDefaultSettings();
check('schema defaults exist and the feature is off in them',
      is_array($defaults) && ($defaults['dbmem_enabled'] ?? null) === '0' && ($defaults['dbmem_cmd'] ?? null) === '');
check('the schema version was bumped for the release', TRACKER_SCHEMA_VERSION >= 42);
check('the settings search knows the keys', str_contains(file_get_contents($root . '/includes/settings_catalog.php'), "'dbmem_cmd'"));
$tpl = file_get_contents($root . '/templates/admin/settings.php');
check('the settings page carries the two fields', str_contains($tpl, 'name="dbmem_cmd"') && str_contains($tpl, 'name="dbmem_enabled"'));
$traffic = file_get_contents($root . '/templates/admin/traffic.php');
check('the Traffic page carries the card and its script', str_contains($traffic, 'id="dbmem-card"') && str_contains($traffic, 'admin-dbmem.js'));
check('the janitor finishes a deferred write', str_contains(file_get_contents($root . '/tools/janitor.php'), 'dbmemTick('));
$api = file_get_contents($root . '/api.php');
check('the three endpoints are routed and status is readable with panel.traffic.view',
      str_contains($api, "'admin/dbmem_status'") && str_contains($api, "'admin/dbmem_apply'") && str_contains($api, "'admin/dbmem_test'")
      && preg_match("/'admin\\/dbmem_status'\\s*=>\\s*'panel\\.traffic\\.view'/", $api) === 1);
check('apply is owner-only (no permission map entry)', preg_match("/'admin\\/dbmem_apply'\\s*=>\\s*'panel\\./", $api) !== 1);

// put the state file back the way it was
if ($backup === null) @unlink(dbmemStateFile()); else file_put_contents(dbmemStateFile(), $backup);
@unlink(dbmemStateLock());

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
