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
require_once $root . '/includes/backup.php';

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
