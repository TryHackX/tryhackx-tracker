<?php
/**
 * The smaller performance fixes of 1.74.0, each checked by what it does:
 *   php tests/perf_misc_test.php              (needs the local test database)
 *
 *   PERF-6  the panel's whitelist search "name or files" is two branches and a UNION — the same rows, the same
 *           count as the OR it replaced, and no subquery run once per row (EXPLAIN of what the endpoint ran);
 *   PERF-12 admin/whitelist_item and admin/index_polls let go of the session lock before they work (the real
 *           api.php, run as a request in a child process);
 *   PERF-13 the OpenTracker card's helper answer is kept in a file between requests: two polls, one helper run;
 *           Apply / Reset / Restart / a changed command ask again;
 *   PERF-17 the timeline's cache outlives the chart's own poll;
 *   PERF-7  the assets' cache rule matches versioned addresses only (the expression assets/.htaccess gives Apache).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/netlimit.php';
require_once $root . '/includes/opentracker.php';
require_once $root . '/includes/stats_timeline.php';
require_once $root . '/includes/lang.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . mb_substr($info, 0, 500)) . "\n";
    if (!$ok) $fails++;
}
$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'perf_misc_' . getmypid();
@mkdir($tmp);
$php = escapeshellarg(PHP_BINARY);
$runChild = static function (string $script, array $args) use ($php): string {
    return (string)shell_exec($php . ' -d display_errors=0 ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
};

/* ── PERF-6: the panel's whitelist search ─────────────────────────────────── */
const WP = '7e57f6b1';
$wh = static fn(int $i) => WP . sprintf('%032x', $i);
$wlClean = static function () use ($db): void {
    $db->prepare("DELETE f FROM whitelist_files f JOIN whitelist w ON w.id = f.whitelist_id WHERE w.info_hash LIKE ?")->execute([WP . '%']);
    $db->prepare("DELETE FROM whitelist WHERE info_hash LIKE ?")->execute([WP . '%']);
};
$wlClean();
$addWl = $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned, content_status, meta_status) VALUES (?, ?, 'admin', NOW() - INTERVAL ? MINUTE, 0, 'none', 'done')");
$addFile = $db->prepare("INSERT INTO whitelist_files (whitelist_id, path, size) VALUES (?, ?, 1)");
$ids = [];
foreach ([1 => 'kakapo field recordings', 2 => 'unrelated set', 3 => 'kakapo extras', 4 => 'nothing here', 5 => 'another unrelated one'] as $i => $name) {
    $addWl->execute([$wh($i), $name, $i]);
    $ids[$i] = (int)$db->lastInsertId();
}
$addFile->execute([$ids[2], 'kakapo/song.flac']);      // a file match only
$addFile->execute([$ids[3], 'kakapo/extra.flac']);     // a name AND a file match
$addFile->execute([$ids[4], 'other.bin']);
$runner = $tmp . DIRECTORY_SEPARATOR . 'endpoint_runner.php';
file_put_contents($runner, '<?php
// A panel endpoint FILE run as a GET request, recording every statement it prepares (and the values bound to it).
// The query arrives in a FILE: a JSON argument does not survive the Windows command line\'s quoting.
[$_x, $root, $file, $getFile, $out] = $argv;
chdir($root);
$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; $_SERVER["SCRIPT_NAME"] = "/api.php";
$_GET = json_decode((string)file_get_contents($getFile), true) ?: [];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/lang.php"] as $f) require_once $f;
class RecStmt extends PDOStatement {
    public static array $log = [];
    private array $bound = [];
    protected function __construct() {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool { $this->bound[$param] = [$value, $type]; return parent::bindValue($param, $value, $type); }
    public function execute(?array $params = null): bool {
        $b = [];
        if ($params !== null) { $i = 1; foreach ($params as $v) $b[$i++] = [$v, PDO::PARAM_STR]; } else $b = $this->bound;
        self::$log[] = ["sql" => $this->queryString, "bound" => $b];
        return parent::execute($params);
    }
}
$db = getDb();
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecStmt::class, []]);
$cfg = getSettings($db);
langInit($cfg, null);
register_shutdown_function(function () use ($out) { file_put_contents($out, json_encode(RecStmt::$log)); });
require $file;
');
$fetchWl = static function (array $get) use ($runChild, $runner, $root, $tmp): array {
    $out = $tmp . DIRECTORY_SEPARATOR . 'stmts_' . md5(json_encode($get)) . '.json';
    $in = $tmp . DIRECTORY_SEPARATOR . 'get_' . md5(json_encode($get)) . '.json';
    file_put_contents($in, json_encode($get));
    $raw = $runChild($runner, [$root, $root . '/api/admin/fetch_whitelist.php', $in, $out]);
    $j = json_decode(trim($raw), true);
    return [is_array($j) ? $j : ['__raw' => mb_substr($raw, 0, 300)], json_decode((string)@file_get_contents($out), true) ?: []];
};
/** The OR the endpoint ran until 1.74.0 — the reference. */
$orIds = static function (string $nameClause, string $fileClause, array $p) use ($db): array {
    $st = $db->prepare("SELECT id FROM whitelist WHERE banned = 0 AND info_hash LIKE '" . WP . "%' AND ($nameClause OR id IN (SELECT whitelist_id FROM whitelist_files WHERE $fileClause))
                        ORDER BY created_at DESC, id DESC");
    $st->execute($p);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};
foreach (['fulltext' => ['kakapo', "MATCH(name) AGAINST(? IN BOOLEAN MODE)", "MATCH(path) AGAINST(? IN BOOLEAN MODE)", ['kakapo*', 'kakapo*']],
          'LIKE (two letters)' => ['ka', "name LIKE ?", "path LIKE ?", ['%ka%', '%ka%']]] as $label => [$term, $nc, $fc, $p]) {
    [$j, $log] = $fetchWl(['search' => $term, 'search_files' => '1', 'sort' => 'date:desc', 'per_page' => '50']);
    $got = array_values(array_filter(array_map(fn($r) => (int)$r['id'], $j['rows'] ?? []), fn($id) => in_array($id, $ids, true)));
    $want = $orIds($nc, $fc, $p);
    check("PERF-6 $label: name or files — the same rows, in the same order, as the OR it replaced (name only, file only, both once)",
          $got === $want && count($want) === 3, json_encode([$got, $want, $j['__raw'] ?? null]));
    $selects = array_values(array_filter($log, fn($e) => preg_match('/^\s*SELECT/i', $e['sql']) && str_contains($e['sql'], 'whitelist_files')));
    $bad = [];
    foreach ($selects as $e) {
        $ex = $db->prepare('EXPLAIN ' . $e['sql']);
        foreach ($e['bound'] as $k => [$v, $t]) $ex->bindValue(is_numeric($k) ? (int)$k : $k, $v, (int)$t);
        $ex->execute();
        foreach ($ex->fetchAll(PDO::FETCH_ASSOC) as $r) if (stripos((string)$r['select_type'], 'DEPENDENT') !== false) $bad[] = $r['select_type'] . ' ' . $r['table'];
    }
    check("PERF-6 $label: what the endpoint ran — " . count($selects) . " statements (count and page) — has no subquery run once per row",
          count($selects) >= 2 && $bad === [] && str_contains($selects[0]['sql'], 'UNION'), json_encode($bad));
}
[$jn] = $fetchWl(['search' => 'kakapo', 'sort' => 'date:desc', 'per_page' => '50']);
check('PERF-6: without "files" the names alone match, as before',
      count(array_filter($jn['rows'] ?? [], fn($r) => in_array((int)$r['id'], $ids, true))) === 2, json_encode(array_column($jn['rows'] ?? [], 'id')));
$wlClean();

/* ── PERF-12: the two pollers let go of the session ───────────────────────── */
$sid = 'perf12' . bin2hex(random_bytes(10));
$mkSession = $tmp . DIRECTORY_SEPARATOR . 'mk_session.php';
file_put_contents($mkSession, '<?php session_id($argv[1]); session_start(); $_SESSION["loggedin"] = true; $_SESSION["login_time"] = time(); $_SESSION["last_activity"] = time(); session_write_close(); echo session_save_path();');
$savePath = trim($runChild($mkSession, [$sid]));
$apiRunner = $tmp . DIRECTORY_SEPARATOR . 'api_runner.php';
file_put_contents($apiRunner, '<?php
// The real api.php as one GET request with a panel session: what is the session\'s state when it answers?
[$_x, $root, $sid, $endpoint, $getFile, $out] = $argv;
chdir($root);
$_GET = ["endpoint" => $endpoint] + (json_decode((string)file_get_contents($getFile), true) ?: []);
$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; $_SERVER["SCRIPT_NAME"] = "/api.php";
$_SERVER["HTTP_HOST"] = "127.0.0.1"; $_SERVER["REQUEST_URI"] = "/api.php?endpoint=" . $endpoint;
session_id($sid);
register_shutdown_function(function () use ($out) { file_put_contents($out, json_encode(["status" => session_status()])); });
require $root . "/api.php";
');
$sessionAt = static function (string $endpoint, array $get = []) use ($runChild, $apiRunner, $root, $sid, $tmp): array {
    $out = $tmp . DIRECTORY_SEPARATOR . 'sess_' . md5($endpoint) . '.json';
    $in = $tmp . DIRECTORY_SEPARATOR . 'sget_' . md5($endpoint) . '.json';
    file_put_contents($in, json_encode($get));
    @unlink($out);
    $raw = $runChild($apiRunner, [$root, $sid, $endpoint, $in, $out]);
    $j = json_decode((string)@file_get_contents($out), true);
    return ['status' => is_array($j) ? (int)$j['status'] : -1, 'reply' => mb_substr(trim($raw), 0, 200)];
};
$wi = $sessionAt('admin/whitelist_item', ['hash' => str_repeat('0', 40)]);
$ip = $sessionAt('admin/index_polls', ['range' => '1h']);
$ctl = $sessionAt('admin/index_item', ['hash' => str_repeat('0', 40)]);
check('PERF-12: admin/whitelist_item answers with the session already let go (its modal polls every 3 s, a scrape inside)',
      $wi['status'] === PHP_SESSION_NONE && str_contains($wi['reply'], '"error"'), json_encode($wi));
check('PERF-12: admin/index_polls too', $ip['status'] === PHP_SESSION_NONE && str_contains($ip['reply'], '"success"'), json_encode($ip));
check('… while an endpoint that is not on the list still holds it when it answers (the check can tell the two apart)',
      $ctl['status'] === PHP_SESSION_ACTIVE, json_encode($ctl));
$savePath = preg_replace('/^\d+;/', '', $savePath);   // "N;/path" — the directory depth prefix, when set
@unlink(rtrim($savePath !== '' ? $savePath : sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid);

/* ── PERF-13: the helper's answer between requests ────────────────────────── */
$cacheFile = otStatusCacheFile();
$cacheWas = is_file($cacheFile) ? (string)file_get_contents($cacheFile) : null;
@unlink($cacheFile);
$counter = $tmp . DIRECTORY_SEPARATOR . 'runs.txt';
file_put_contents($tmp . DIRECTORY_SEPARATOR . 'fake_ot.php', '<?php file_put_contents(' . var_export($counter, true) . ', ($argv[1] ?? "?") . "\n", FILE_APPEND);
echo json_encode(["ok" => true, "nice" => -2, "cpu_weight" => 100, "cpu_affinity" => "", "limit_nofile" => 65536, "workers" => 4, "cpus" => 4]), "\n";');
$here = getcwd();
chdir($tmp);   // the helper command may not carry a drive letter (otValidCommand()): it runs relative to here
$otCfg = ['ot_perf_cmd' => 'php fake_ot.php'];
$runs = static fn() => is_file($counter) ? count(file($counter, FILE_IGNORE_NEW_LINES)) : 0;
try {
    if (!trackerExecAvailable()) {
        echo "SKIP PERF-13: exec() is not available to this PHP\n";
    } else {
        $a = otStatus($otCfg);
        $b = otStatus($otCfg);
        check('PERF-13: two polls, ONE run of the helper — the second answered from the file (the static died with each php-fpm request)',
              $runs() === 1 && !empty($a['ok']) && $a['cached'] === false && $b['cached'] === true && $b['workers'] === 4, json_encode([$runs(), $a['cached'] ?? null, $b['cached'] ?? null]));
        otStatus($otCfg, false, time() + OT_STATUS_TTL + 1);
        check('… past OT_STATUS_TTL the helper is asked again', $runs() === 2, (string)$runs());
        otStatus($otCfg, true);
        check('… and `fresh` always asks', $runs() === 3, (string)$runs());
        otStatus(['ot_perf_cmd' => 'php  fake_ot.php']);
        check('… a different helper command never reads the other one\'s answer', $runs() === 4, (string)$runs());
        otStatus($otCfg);
        $before = $runs();
        otApply($otCfg, false);
        $afterApply = otStatus($otCfg);
        check('… Apply drops it: the next poll shows what Apply changed', !$afterApply['cached'] && $runs() === $before + 2, json_encode([$runs(), $before]));
        otApply($otCfg, true);
        check('… a dry run changes nothing and keeps it', otStatus($otCfg)['cached'] === true);
        otRestart($otCfg);
        check('… a restart drops it', otStatus($otCfg)['cached'] === false);
    }
} finally {
    chdir($here);
    if ($cacheWas === null) @unlink($cacheFile); else file_put_contents($cacheFile, $cacheWas);
}

/* ── PERF-17 and PERF-7 ───────────────────────────────────────────────────── */
check('PERF-17: the timeline\'s cache outlives the chart\'s own 60-second poll, so the next poll reads the file',
      ST_API_CACHE_TTL > 60, (string)ST_API_CACHE_TTL);
$ht = (string)file_get_contents($root . '/assets/.htaccess');
$ok7 = preg_match('#<If\s+"%\{QUERY_STRING\}\s+=~\s+/(.+?)/">\s*Header set Cache-Control "public, max-age=31536000, immutable"\s*</If>#s', $ht, $m) === 1;
$re = $ok7 ? '/' . $m[1] . '/' : null;
$hits = $re === null ? [] : array_map(fn($q) => preg_match($re, $q) === 1, ['v=1759670000', 'x=1&v=2', 'v=', 'nv=1', 'version=3', '', 'a=1']);
check('PERF-7: a year of immutable for a versioned address only (?v=… or …&v=…), never for a bare one',
      $ok7 && $hits === [true, true, true, false, false, false, false], json_encode([$re, $hits]));
check('PERF-7: and no ETag on assets/, so a compressing server cannot answer every revalidation with the whole file',
      preg_match('/^\s*FileETag\s+None\s*$/m', $ht) === 1);

foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
@rmdir($tmp);
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
