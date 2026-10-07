<?php
/**
 * What sends, deletes or switches the tracker on its own — the three the audit found no test for (1.74.0 part E,
 * QUAL-6; needs the local test database):
 *   php tests/state_switch_test.php
 *
 *   1. bulkTick() — the bulk mail's janitor: off is off; on, it sends what is due in id order up to the per-tick
 *      number and no further; a row another janitor already claimed is not sent twice; a refused mail is retried
 *      later, then given up with its reason.
 *   2. wlMaintDeadTick() — the whitelist's dead rows: only a row whose last scrape said 0/0 longer ago than the
 *      window; never an unscraped, a live, a recent or a banned row; once per its period; "mark" marks, "delete"
 *      deletes and the served file no longer carries it.
 *   3. scheduleSwitchTo() — the nightly mode switch, against a stub helper: it asks for the right mode, the setting
 *      flips only when the helper says yes, a failing or contradicting helper leaves the mode as it was (and says
 *      why), and switching to the blacklist writes the bans into the blacklist file first.
 * Everything in the database happens inside a transaction that is rolled back; the state files the functions write
 * (config/net_state.json, config/whitelist_state.json, config/blacklist_changes.json) are put back byte for byte;
 * the list files and the stub live in a temporary directory. Mail never leaves: sendEmail() is this file's own.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }

/** The mailer, as bulkTick() sees it: records, and answers what $GLOBALS['SST_SEND'] says (default: accepted). */
function sendEmail(string $to, string $subject, string $plainText, string $htmlBody, array $cfg, string $unsubscribeUrl = ''): bool {
    $GLOBALS['SST_SENT'][] = $to;
    $f = $GLOBALS['SST_SEND'] ?? null;
    return is_callable($f) ? (bool)$f($to) : true;
}
// The application without includes/mail.php (its sendEmail() is the one above).
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php', 'includes/lang.php',
          'includes/richtext.php', 'includes/netlimit.php', 'includes/whitelist.php', 'includes/schedule.php', 'includes/index.php',
          'includes/wlmaint.php', 'includes/bulkmail.php', 'includes/users.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }
$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfg;
langInit([], 'en');

// The state files, aside — whatever they hold now goes back exactly as it was.
$stateFiles = [netlimitStateFile(), whitelistStateFile(), blacklistChangesFile()];
$saved = [];
foreach ($stateFiles as $f) $saved[$f] = is_file($f) ? (string)file_get_contents($f) : null;
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sst_' . getmypid() . '_' . bin2hex(random_bytes(3));
@mkdir($tmpDir, 0777, true);
$putBack = function () use ($saved, $tmpDir, $db): void {
    if ($db->inTransaction()) $db->rollBack();
    foreach ($saved as $f => $body) { if ($body === null) @unlink($f); else file_put_contents($f, $body); }
    foreach ((array)glob($tmpDir . DIRECTORY_SEPARATOR . '*') as $x) @unlink($x);
    @rmdir($tmpDir);
};
register_shutdown_function($putBack);
$row = function (string $sql, array $args = []) use ($db): ?array {
    $st = $db->prepare($sql);
    $st->execute($args);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
};

/* ══ 1. bulkTick() ═════════════════════════════════════════════════════════════════════════════════════════════ */
$db->beginTransaction();
try {
    // Everything else in the queue is not due during this run (rolled back with the rest).
    $db->exec("UPDATE mail_queue SET next_attempt_at = NOW() + INTERVAL 1 DAY WHERE status = 'queued'");
    $batch = 'sst' . bin2hex(random_bytes(6));
    $ins = $db->prepare("INSERT INTO mail_queue (batch_id, user_id, email, subject, body, format, status, next_attempt_at)
                         VALUES (?, NULL, ?, 'SST subject', 'SST body', 'plain', ?, NOW() + INTERVAL ? SECOND)");
    foreach ([['a@sst.example', 'queued', -60], ['b@sst.example', 'queued', -50], ['c@sst.example', 'queued', -40],
              ['later@sst.example', 'queued', 3600], ['claimed@sst.example', 'sending', -70]] as [$to, $status, $at]) {
        $ins->execute([$batch, $to, $status, $at]);
    }
    $state = function (string $to) use ($row, $batch): array {
        return $row("SELECT status, attempts, last_error, TIMESTAMPDIFF(SECOND, NOW(), next_attempt_at) AS wait FROM mail_queue WHERE batch_id = ? AND email = ?", [$batch, $to]) ?? [];
    };
    $cfgB = array_merge($cfg, ['bulk_mail_enabled' => '1', 'bulk_mail_per_minute' => '2', 'bulk_mail_max_attempts' => '2', 'hmac_secret' => 'sst-secret']);
    $GLOBALS['SST_SENT'] = [];
    $r0 = bulkTick($db, array_merge($cfgB, ['bulk_mail_enabled' => '0']));
    check('bulk mail switched off: the tick sends nothing', $r0['sent'] === 0 && $GLOBALS['SST_SENT'] === [], $J($r0));
    $GLOBALS['SST_SENT'] = [];
    $r1 = bulkTick($db, $cfgB);
    check('on, two per tick: the first two due rows go, in the order they were queued', $r1['sent'] === 2 && $GLOBALS['SST_SENT'] === ['a@sst.example', 'b@sst.example'],
          $J([$r1, $GLOBALS['SST_SENT']]));
    check('… and are marked sent', ($state('a@sst.example')['status'] ?? '') === 'sent' && ($state('b@sst.example')['status'] ?? '') === 'sent');
    check('… the third waits for the next tick, a row not due yet is not touched', ($state('c@sst.example')['status'] ?? '') === 'queued'
          && ($state('later@sst.example')['status'] ?? '') === 'queued' && (int)($state('later@sst.example')['attempts'] ?? -1) === 0);
    check('… and a row another janitor has already claimed is never sent twice', !in_array('claimed@sst.example', $GLOBALS['SST_SENT'], true)
          && ($state('claimed@sst.example')['status'] ?? '') === 'sending');
    $GLOBALS['SST_SEND'] = fn($to) => false;
    $GLOBALS['SST_SENT'] = [];
    $r2 = bulkTick($db, $cfgB);
    $c = $state('c@sst.example');
    check('a mail the mailer refuses is tried again later, not at once: queued, one attempt, a wait of two minutes, the reason kept',
          $r2['failed'] === 0 && ($c['status'] ?? '') === 'queued' && (int)($c['attempts'] ?? 0) === 1 && (int)($c['wait'] ?? 0) > 100
          && ($c['last_error'] ?? '') !== '', $J([$r2, $c]));
    $db->prepare("UPDATE mail_queue SET next_attempt_at = NOW() - INTERVAL 1 SECOND WHERE batch_id = ? AND email = 'c@sst.example'")->execute([$batch]);
    $r3 = bulkTick($db, $cfgB);
    $c = $state('c@sst.example');
    check('… and at the most attempts it is given up — failed, with its reason — not retried for ever',
          $r3['failed'] === 1 && ($c['status'] ?? '') === 'failed' && (int)($c['attempts'] ?? 0) === 2 && ($c['last_error'] ?? '') !== '', $J([$r3, $c]));
    unset($GLOBALS['SST_SEND']);
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
check('… and the queue is as it was (the run was a transaction)', !$row("SELECT id FROM mail_queue WHERE batch_id = ?", [$batch]));

/* ══ 2. wlMaintDeadTick() ═════════════════════════════════════════════════════════════════════════════════════ */
$h = static fn(string $tag): string => substr(hash('sha1', 'state-switch-test-' . $tag), 0, 40);
$db->beginTransaction();
try {
    // Every other row of the whitelist is kept out of the rule for this run (rolled back with the rest).
    $db->exec("UPDATE whitelist SET scraped_at = NOW() WHERE scraped_at IS NOT NULL");
    $wi = $db->prepare("INSERT INTO whitelist (info_hash, name, source, banned, scraped_at, scrape_seeders, scrape_leechers)
                        VALUES (?, ?, 'admin', ?, ?, ?, ?)");
    $old = date('Y-m-d H:i:s', time() - 60 * 86400);
    $recent = date('Y-m-d H:i:s', time() - 5 * 86400);
    $wi->execute([$h('dead'), 'SST dead', 0, $old, 0, 0]);
    $wi->execute([$h('alive'), 'SST alive', 0, $old, 3, 0]);
    $wi->execute([$h('never'), 'SST never scraped', 0, null, null, null]);
    $wi->execute([$h('recent'), 'SST recently empty', 0, $recent, 0, 0]);
    $wi->execute([$h('banned'), 'SST banned', 1, $old, 0, 0]);
    $deadSince = fn(string $tag) => $row("SELECT dead_since FROM whitelist WHERE info_hash = ?", [$h($tag)])['dead_since'] ?? null;
    $cfgD = array_merge($cfg, ['wl_dead_after_days' => '30', 'wl_dead_action' => 'mark', 'wl_dead_every_days' => '1',
                               'whitelist_path' => $tmpDir . DIRECTORY_SEPARATOR . 'whitelist', 'opentracker_service_name' => '', 'tracker_mode' => 'whitelist']);
    wlMaintStateSet(['last_dead_at' => 0]);
    check('the rule counts what it would touch before it touches it: only the row scraped empty 60 days ago', wlMaintDeadCount($db, $cfgD) === 1,
          (string)wlMaintDeadCount($db, $cfgD));
    $r = wlMaintDeadTick($db, $cfgD);
    check('"mark": the one row whose last scrape said 0/0 longer ago than the window is marked dead',
          $r['ran'] && $r['matched'] === 1 && $r['marked'] === 1 && $deadSince('dead') !== null, $J($r));
    check('… never a live row, an unscraped one, one empty only for days, or a banned one',
          $deadSince('alive') === null && $deadSince('never') === null && $deadSince('recent') === null && $deadSince('banned') === null);
    $again = wlMaintDeadTick($db, $cfgD);
    check('… and it runs once per its period, not on every tick', $again['ran'] === false, $J($again));
    check('the action "none" does nothing', wlMaintDeadTick($db, array_merge($cfgD, ['wl_dead_action' => 'none']))['ran'] === false);
    check('a window of 0 days is off', wlMaintDeadTick($db, array_merge($cfgD, ['wl_dead_after_days' => '0']))['ran'] === false);
    wlMaintStateSet(['last_dead_at' => 0]);
    $r = wlMaintDeadTick($db, array_merge($cfgD, ['wl_dead_action' => 'delete']));
    $file = (string)@file_get_contents($cfgD['whitelist_path']);
    check('"delete" deletes that row and only that row', $r['deleted'] === 1 && !$row("SELECT id FROM whitelist WHERE info_hash = ?", [$h('dead')])
          && $row("SELECT id FROM whitelist WHERE info_hash = ?", [$h('alive')]) && $row("SELECT id FROM whitelist WHERE info_hash = ?", [$h('never')]), $J($r));
    check('… and the file the tracker serves is written again without it, with the rows that stay',
          $file !== '' && !str_contains($file, $h('dead')) && str_contains($file, $h('alive')) && str_contains($file, $h('never')), substr($file, 0, 200));
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

/* ══ 3. scheduleSwitchTo() ════════════════════════════════════════════════════════════════════════════════════ */
$stub = $tmpDir . DIRECTORY_SEPARATOR . 'mode_stub.php';
file_put_contents($stub, '<?php
$d = __DIR__;
file_put_contents($d . "/calls.log", ($argv[1] ?? "") . "\n", FILE_APPEND);
$c = is_file($d . "/ctl.json") ? (json_decode((string)file_get_contents($d . "/ctl.json"), true) ?: []) : [];
echo isset($c["out"]) ? $c["out"] : ($argv[1] ?? ""), "\n";
exit((int)($c["rc"] ?? 0));
');
// A command the switch's own rule accepts ([A-Za-z0-9 _./-]): on Windows the drive goes, "/Users/…" is that drive's.
$stubPath = str_replace('\\', '/', (string)realpath($stub));
$stubPath = preg_replace('#^[A-Za-z]:#', '', $stubPath);
$cmd = 'php ' . $stubPath;
$probeOut = []; $probeRc = null;
@exec('php -r "echo 1;" 2>&1', $probeOut, $probeRc);
if (!scheduleValidSwitchCommand($cmd) || !trackerExecAvailable() || $probeRc !== 0) {
    skip('scheduleSwitchTo() against a stub helper', 'the stub\'s command is not one the switch accepts here, or exec()/php is unavailable: ' . $cmd);
} else {
    $ctl = fn(?array $c) => $c === null ? @unlink($tmpDir . '/ctl.json') : file_put_contents($tmpDir . '/ctl.json', json_encode($c));
    $calls = fn(): array => array_values(array_filter(explode("\n", (string)@file_get_contents($tmpDir . '/calls.log'))));
    $bl = $tmpDir . DIRECTORY_SEPARATOR . 'blacklist';
    file_put_contents($bl, '');
    $mode = fn(): string => (string)($row("SELECT `value` FROM settings WHERE `key` = 'tracker_mode'")['value'] ?? '');
    $fresh = fn() => ['ok' => true, 'changed' => false, 'from' => null, 'to' => null, 'error' => null, 'output' => '', 'notes' => [], 'skipped' => null];
    // scheduleSwitchTo() also moves the process's own $GLOBALS['cfg'] — which, at this file's top level, IS $cfg.
    $modeBefore = $mode();
    $cfgModeBefore = $cfg['tracker_mode'] ?? null;
    $db->beginTransaction();
    try {
        setSetting($db, 'tracker_mode', 'blacklist');
        $cfgS = array_merge($cfg, ['tracker_mode' => 'blacklist', 'tracker_mode_switch_cmd' => $cmd, 'whitelist_path' => $tmpDir . DIRECTORY_SEPARATOR . 'whitelist',
                                   'blacklist_path' => $bl, 'opentracker_service_name' => '', 'opentracker_auto_reload' => '0']);
        $ctl(null);
        $out = $fresh();
        $ok = scheduleSwitchTo($db, $cfgS, 'whitelist', $out);
        check('to the whitelist: the helper is asked for "white", the setting flips, the caller\'s $cfg too',
              $ok === true && $out['changed'] === true && $calls() === ['white'] && $mode() === 'whitelist' && $cfgS['tracker_mode'] === 'whitelist', $J([$out, $calls()]));
        check('… and the file the restarted tracker will load was written first', is_file($cfgS['whitelist_path']));
        $ctl(['rc' => 3, 'out' => 'boom: the unit did not start']);
        $out = $fresh();
        $ok = scheduleSwitchTo($db, $cfgS, 'blacklist', $out);
        check('a helper that fails (exit 3): false, the reason with its code and its words, and the mode stays as it was',
              $ok === false && $out['ok'] === false && str_contains((string)$out['error'], '3') && str_contains((string)$out['error'], 'boom')
              && $mode() === 'whitelist' && $cfgS['tracker_mode'] === 'whitelist', $J($out));
        $ctl(['rc' => 0, 'out' => 'white']);
        $out = $fresh();
        $ok = scheduleSwitchTo($db, $cfgS, 'blacklist', $out);
        check('a helper that answers the other mode: false, said, and the mode stays — the setting never claims what is not running',
              $ok === false && str_contains((string)$out['error'], 'white') && $mode() === 'whitelist', $J($out));
        $ban = substr(hash('sha1', 'state-switch-test-ban'), 0, 40);
        $db->prepare("INSERT INTO banned_hashes (info_hash, reason, source) VALUES (?, 'state_switch_test', 'admin')")->execute([$ban]);
        $ctl(null);
        $out = $fresh();
        $ok = scheduleSwitchTo($db, $cfgS, 'blacklist', $out);
        check('to the blacklist: the bans are written into the blacklist file before the switch, and the mode flips',
              $ok === true && str_contains((string)@file_get_contents($bl), $ban) && $mode() === 'blacklist', $J([$out, $calls()]));
        $asked = $calls();
        check('… having asked the helper for "black"', ($asked[count($asked) - 1] ?? '') === 'black', $J($asked));
        check('… and the running process\'s own settings follow the switch too ($GLOBALS[\'cfg\'])', ($GLOBALS['cfg']['tracker_mode'] ?? null) === 'blacklist');
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        if ($cfgModeBefore === null) unset($cfg['tracker_mode']); else $cfg['tracker_mode'] = $cfgModeBefore;
    }
    check('… and the mode is back where it was (the run was a transaction)', $mode() === $modeBefore, $mode() . ' / ' . $modeBefore);
}

$putBack();
$same = true;
foreach ($saved as $f => $body) $same = $same && ($body === null ? !is_file($f) : (string)file_get_contents($f) === $body);
check('the state files are back exactly as they were', $same);

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails ? 1 : 0);
