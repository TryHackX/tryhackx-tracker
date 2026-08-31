<?php
/**
 * CLI janitor for the whitelist service — run it from a systemd timer (or cron) as the web user:
 *
 *   sudo -u www-data php /var/www/tracker/tools/janitor.php
 *
 * It first applies the scheduled tracker mode (whitelist hours → blacklist/whitelist switch via the
 * configured root helper; see includes/schedule.php — this is the ONLY place the switch runs, never a
 * web request), then fires any due whitelist file regeneration / tracker reload (SIGHUP) that the
 * request-driven janitor could not run because nobody visited the site, removes stale temp files and
 * prunes old API bans. Then the statistics timeline tick (includes/stats_timeline.php): one sample per
 * configured interval (from the shared stats cache when fresh, otherwise from the tracker), 5-minute /
 * hourly roll-ups and retention. Then the observed-hash index, the inbound UDP traffic sample
 * (includes/netlimit.php — also where an expired "throttle hard" window is undone and where the
 * automatic limit moves), any due scheduled backup (includes/backup.php — this is the only place a
 * backup starts on a timer, never a web request) and the user-account tick. Safe to run every
 * minute; exits 0 always. See README "Whitelist mode".
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
if (!file_exists($root . '/config/installed.lock')) { fwrite(STDERR, "not installed\n"); exit(0); }
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/schedule.php';
require_once $root . '/includes/stats_timeline.php';
require_once $root . '/includes/index.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/netlimit.php';
require_once $root . '/includes/opentracker.php';
require_once $root . '/includes/sysctl.php';
require_once $root . '/includes/cluster.php';
require_once $root . '/includes/backup.php';
require_once $root . '/includes/bulkmail.php';
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/audit.php';
require_once $root . '/includes/tuner.php';
require_once $root . '/includes/livesync.php';
require_once $root . '/includes/reputation.php';
require_once $root . '/includes/wlmaint.php';
require_once $root . '/includes/wlprobe.php';

try {
    $db  = getDb();
    $cfg = getSettings($db);
    ensureSchema($db, $cfg);
    $GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfg;
    $before = whitelistStateRead();
    // scheduled mode first: it may flip tracker_mode (in $cfg too), which the whitelist janitor honours
    $sched = scheduleApply($db, $cfg);
    if ($sched['changed'] || !$sched['ok']) {
        echo sprintf('[schedule] %s %s→%s%s%s', $sched['ok'] ? 'switched' : 'FAILED', $sched['from'], $sched['to'] ?? '?',
            $sched['error'] ? ' error=' . $sched['error'] : '', $sched['notes'] ? ' notes=' . implode(' | ', $sched['notes']) : ''), "\n";
    }
    // Ask the helper what mode is REALLY running and cache it, so the panel's status card reads a warm
    // answer instead of forking a sudo on every poll — and so a panel/tracker disagreement is noticed
    // within a minute rather than whenever somebody happens to look.
    if (function_exists('scheduleModeAgreement')) {
        $agree = scheduleModeAgreement($cfg, true);
        if ($agree['known'] && $agree['match'] === false) {
            echo sprintf("[mode] MISMATCH: the panel says %s, the tracker is running %s
", $agree['panel'], $agree['actual']);
        } elseif (!$agree['known'] && $agree['error'] !== null && in_array('-v', $argv ?? [], true)) {
            echo '[mode] could not read the running mode: ' . $agree['error'] . "
";
        }
    }
    if (function_exists('auditPrune')) {
        $pruned = auditPrune($db, $cfg);
        if ($pruned > 0 && in_array('-v', $argv ?? [], true)) echo "[audit] pruned $pruned rows\n";
    }
    // The stability probe: start one that was asked for, and restore one whose process has died.
    // The reap is the important half — a tuner that is killed must not leave the machine on the limit
    // it happened to be testing.
    if (function_exists('tunerSpawn')) {
        $reap = tunerReap($cfg);
        if (!empty($reap['reaped'])) echo "[tuner] a run had stopped without restoring; settings put back\n";
        $sp = tunerSpawn($cfg);
        if (!empty($sp['started'])) echo "[tuner] started a requested run\n";
    }
    whitelistJanitor($db, $cfg);
    // statistics timeline: sample / roll up / prune (no-op when disabled)
    $tl = statsTimelineTick($db, $cfg);
    if ($tl['enabled'] && ($tl['error'] !== null || in_array('-v', $argv ?? [], true))) {
        echo sprintf('[timeline] sampled=%s source=%s rollup5m=%d rollup1h=%d%s%s', $tl['sampled'] ? 'yes' : 'no', $tl['source'] ?? '-',
            (int)($tl['rollup']['5m'] ?? 0), (int)($tl['rollup']['1h'] ?? 0),
            $tl['prune'] ? ' pruned=' . (int)$tl['prune']['raw'] . '/' . (int)$tl['prune']['5m'] : '',
            $tl['error'] !== null ? ' error=' . $tl['error'] : ''), "\n";
    }
    // observed-hash index: poll (if due) / meta budget / prune (no-op when disabled)
    $ix = indexTick($db, $cfg);
    if ($ix['enabled'] && ($ix['error'] !== null || $ix['polled'] || in_array('-v', $argv ?? [], true))) {
        echo sprintf('[index] polled=%s%s meta_queued=%d%s%s', $ix['polled'] ? 'yes' : 'no',
            $ix['poll'] ? ' (entries=' . (int)$ix['poll']['entries'] . ' kept=' . (int)$ix['poll']['kept'] . ($ix['poll']['truncated'] ? ' TRUNCATED' : '') . ' ms=' . (int)$ix['poll']['ms'] . ')' : '',
            (int)$ix['meta_queued'], $ix['prune'] ? ' pruned=' . (int)$ix['prune']['expired'] . '/' . (int)$ix['prune']['capped'] : '',
            $ix['error'] !== null ? ' error=' . $ix['error'] : ''), "\n";
    }
    // inbound UDP traffic: sample the nftables counters, expire a panic window, move the automatic
    // limit (no-op — not even a fork — while the monitor and the automatic mode are both off)
    $nl = netlimitTick($db, $cfg);
    if ($nl['enabled'] && ($nl['error'] !== null || $nl['auto'] !== null || $nl['panic'] !== null || $nl['persisted'] || in_array('-v', $argv ?? [], true))) {
        echo sprintf('[netlimit] sampled=%s%s%s%s pruned=%d%s', $nl['sampled'] ? 'yes' : 'no',
            $nl['auto'] ? ' auto=' . $nl['auto']['action'] . '→' . (int)$nl['auto']['pps'] . 'pps (' . $nl['auto']['reason'] . ')' : '',
            $nl['panic'] ? ' panic=restored' : '',
            $nl['persisted'] ? ' saved=ruleset' : '', (int)$nl['pruned'],
            $nl['error'] !== null ? ' error=' . $nl['error'] : ''), "\n";
    }
    // an OpenTracker drop-in the panel could not write itself (php-fpm cannot write /etc)
    $ot = otTick($cfg);
    if ($ot['pending'] || $ot['error'] !== null) {
        echo sprintf('[opentracker] pending=yes applied=%s%s', $ot['applied'] ? 'yes' : 'no',
            $ot['error'] !== null ? ' error=' . $ot['error'] : ''), "
";
    }
    // Kernel network buffers. This is the ONLY place on the machine that can write one: php-fpm
    // runs with ProtectKernelTunables=yes, so /proc/sys is read-only inside its mount namespace --
    // for root too, because it is a namespace and not a permission bit. The panel records what was
    // asked for; this performs it, and puts it back when an armed change was never confirmed.
    $sy = sysctlTick($cfg);
    if ($sy['did'] !== null) {
        echo sprintf('[sysctl] %s ok=%s%s%s', $sy['did'], $sy['ok'] ? 'yes' : 'no',
            $sy['reverted'] ? ' reverted=yes' : '',
            $sy['error'] !== null ? ' error=' . $sy['error'] : ''), "
";
    }

    // Extra opentracker instances: SIGHUP them when the accesslist has changed under them.
    //
    // Driven by the file's modification time rather than by hooking into whitelistJanitor(), for two
    // reasons. That function runs on EVERY API request by design, so a loop of `systemctl reload` in
    // it would let one visitor stall five php-fpm children. And it returns immediately in blacklist
    // mode -- where an extra would otherwise keep serving a hash that was banned an hour ago, which
    // on a takedown tracker is the failure with legal weight.
    $cl = otClusterTick($cfg);
    if ($cl['did'] !== null) {
        echo sprintf('[cluster] %s reloaded=%d failed=%d%s', $cl['did'], $cl['reloaded'], $cl['failed'],
            $cl['error'] !== null ? ' error=' . $cl['error'] : ''), "\n";
    }
    // scheduled backups: fire when a slot is due (no-op — not even a fork — while backups are off)
    $bk = backupTick($db, $cfg);
    if ($bk['enabled'] && ($bk['error'] !== null || $bk['started'] || in_array('-v', $argv ?? [], true))) {
        echo sprintf('[backup] started=%s%s%s', $bk['started'] ? 'yes' : 'no',
            $bk['id'] ? ' id=' . $bk['id'] : '', $bk['error'] !== null ? ' error=' . $bk['error'] : ''), "\n";
    }
    // user accounts: expire/warn timed group memberships, prune notifications + tokens (no-op when disabled)
    $us = usersTick($db, $cfg);
    if ($us['enabled'] && ($us['error'] !== null || $us['expired'] || $us['warned'] || in_array('-v', $argv ?? [], true))) {
        echo sprintf('[users] expired=%d warned=%d pruned=%d%s', (int)$us['expired'], (int)$us['warned'], (int)$us['pruned'],
            $us['error'] !== null ? ' error=' . $us['error'] : ''), "\n";
    }
    // bulk mail: a few messages a minute, and nothing at all while the feature is off. This is the
    // only place they are sent — the panel writes rows and stops, because a burst of mail from a
    // domain that normally sends a handful a day is what costs the password-reset messages.
    $bm = bulkTick($db, $cfg);
    if ($bm['sent'] || $bm['failed'] || in_array('-v', $argv ?? [], true)) {
        echo sprintf('[bulkmail] sent=%d failed=%d left=%d', $bm['sent'], $bm['failed'], $bm['left']), "
";
    }
    bulkPrune($db);

    // whitelist upkeep: refresh stale swarm counts, and the dead-row pass on its own schedule
    // submissions proving themselves: metadata in, at least one peer, or give up with a reason
    $wp = wlProbeTick($db, $cfg);
    if ($wp['passed'] || $wp['failed']) {
        echo sprintf('[wlprobe] checked=%d passed=%d failed=%d deleted=%d',
            $wp['checked'], $wp['passed'], $wp['failed'], $wp['deleted']), "
";
    }

    $wm = wlMaintTick($db, $cfg);
    if (($wm['refresh']['ran'] ?? false) || ($wm['dead']['ran'] ?? false) || $wm['error']) {
        echo sprintf('[wlmaint] scraped=%d dead_matched=%d marked=%d deleted=%d%s',
            (int)($wm['refresh']['scraped'] ?? 0), (int)($wm['dead']['matched'] ?? 0),
            (int)($wm['dead']['marked'] ?? 0), (int)($wm['dead']['deleted'] ?? 0),
            $wm['error'] ? ' error=' . $wm['error'] : ''), "
";
    }

    // live peer sync: refresh the cached view so the panel never forks a root script from a poll
    livesyncTick($cfg);

    $after = whitelistStateRead();
    $msg = sprintf('mode=%s schedule=%s pending_reload=%s regen_needed=%s last_reload_ok=%s fail_count=%d count=%d',
        trackerMode($cfg), scheduleEnabled($cfg) ? ('on desired=' . (scheduleDesiredMode($cfg) ?? 'invalid')) : 'off',
        $after['pending_reload'] ? 'yes' : 'no', $after['regen_needed'] ? 'yes' : 'no',
        $after['last_reload_ok'] === null ? 'n/a' : ($after['last_reload_ok'] ? 'yes' : 'no'), (int)$after['fail_count'], (int)$after['count']);
    if (in_array('-v', $argv ?? [], true) || $before['pending_reload'] || $before['regen_needed'] || $sched['changed']) echo $msg, "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '[janitor] ' . $e->getMessage() . "\n");
}
exit(0);
