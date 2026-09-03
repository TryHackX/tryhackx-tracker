<?php
/**
 * Whitelist maintenance CLI — run as the web user so file ownership/permissions match the panel:
 *
 *   sudo -u www-data php tools/whitelist_cli.php status
 *   sudo -u www-data php tools/whitelist_cli.php add [--source=admin|api|forum|web] < hashes.txt   (one magnet/hash per line)
 *   sudo -u www-data php tools/whitelist_cli.php regen [--reload]
 *   sudo -u www-data php tools/whitelist_cli.php import-blacklist
 *   sudo -u www-data php tools/whitelist_cli.php reload
 *   sudo -u www-data php tools/whitelist_cli.php mode [--apply]      (scheduled mode: current / desired / next change; --apply switches now)
 *   sudo -u www-data php tools/whitelist_cli.php timeline [--tick]  (statistics timeline: state + row counts; --tick samples/rolls up now)
 *   sudo -u www-data php tools/whitelist_cli.php index [--tick] [--poll] (observed-hash index: status; --tick runs the janitor tick, --poll forces a poll now)
 *
 * Used for the initial bootstrap (e.g. piping the forum's magnet hashes) and for scripted maintenance.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
if (!file_exists($root . '/config/installed.lock')) { fwrite(STDERR, "not installed\n"); exit(1); }
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/schedule.php';
require_once $root . '/includes/stats_timeline.php';
require_once $root . '/includes/index.php';

$db  = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfg;

$args = array_slice($argv, 1);
$cmd = $args[0] ?? 'status';
$opts = [];
foreach ($args as $a) if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;

switch ($cmd) {
    case 'status':
        $s = whitelistStatus($db, $cfg);
        printf("mode=%s path=%s perm_ok=%s active=%d banned_rows=%d banned_hashes=%d pending_meta=%d\n",
            $s['mode'], $s['path'] ?: '(none)', $s['permissions']['ok'] ? 'yes' : 'no', $s['counts']['active'], $s['counts']['banned_rows'], $s['counts']['banned_hashes'], $s['counts']['pending_meta']);
        printf("file: exists=%s size=%d lines≈%d | state: count=%d appended=%d regen_needed=%s pending_reload=%s last_reload_ok=%s fail_count=%d\n",
            $s['file']['exists'] ? 'yes' : 'no', $s['file']['size'], $s['file']['lines_estimate'], $s['state']['count'], $s['state']['appended_since_regen'],
            $s['state']['regen_needed'] ? 'yes' : 'no', $s['state']['pending_reload'] ? 'yes' : 'no',
            $s['state']['last_reload_ok'] === null ? 'n/a' : ($s['state']['last_reload_ok'] ? 'yes' : 'no'), (int)$s['state']['fail_count']);
        foreach ($s['permissions']['errors'] as $e) echo "  ERROR: $e\n";
        foreach ($s['warnings'] as $w) echo "  [{$w['level']}] {$w['text']}\n";
        break;

    case 'add':
        // Bound once. The old form tested `$opts['source'] ?? 'admin'` and then returned
        // `$opts['source']` — so with no --source the test passed on the default and the RESULT was
        // an undefined index, which lands as '' rather than as 'admin'.
        $srcIn = (string)($opts['source'] ?? 'admin');
        $source = in_array($srcIn, ['web', 'api', 'admin', 'forum'], true) ? $srcIn : 'admin';
        $text = stream_get_contents(STDIN);
        $parsed = parseHashInput((string)$text, 1000000);
        $items = $parsed['items'];
        $r = whitelistAddHashes($db, $cfg, $items, ['source' => $source, 'ip' => '', 'auto_meta' => (bool)($opts['meta'] ?? true)]);
        printf("added=%d exists=%d banned=%d invalid=%d file=%s/%s reload=%s\n", $r['summary']['added'], $r['summary']['exists'], $r['summary']['banned'], $r['summary']['invalid'],
            $r['file']['mode'], $r['file']['ok'] ? 'ok' : ('FAIL: ' . $r['file']['error']), $r['reload'] ? ($r['reload']['ok'] ? 'ok' : 'failed') : 'not attempted');
        if (!empty($opts['verbose'])) foreach ($r['results'] as $x) if ($x['status'] !== 'added') echo "  {$x['status']}: " . ($x['hash'] ?? mb_substr($x['input'], 0, 60)) . ($x['error'] ? " ({$x['error']})" : '') . "\n";
        break;

    case 'regen':
        $r = whitelistRegenerate($db, $cfg);
        printf("regen: %s count=%d bytes=%d ms=%d%s\n", $r['ok'] ? 'ok' : 'FAILED', $r['count'], $r['bytes'], $r['ms'], $r['error'] ? " error={$r['error']}" : '');
        if ($r['ok'] && !empty($opts['reload'])) {
            whitelistMarkDirty(true);
            $rl = whitelistMaybeReload($cfg, true);
            echo 'reload: ' . ($rl ? ($rl['ok'] ? 'ok' : 'FAILED ' . $rl['output']) : 'not attempted (service/exec not configured)') . "\n";
        }
        exit($r['ok'] ? 0 : 1);

    case 'import-blacklist':
        $r = whitelistImportBlacklist($db, $cfg);
        printf("imported=%d skipped=%d invalid=%d%s\n", $r['imported'], $r['skipped'], $r['invalid'], $r['error'] ? " error={$r['error']}" : '');
        break;

    case 'reload':
        whitelistMarkDirty(true);
        $rl = whitelistMaybeReload($cfg, true);
        echo 'reload: ' . ($rl ? ($rl['ok'] ? 'ok' : 'FAILED ' . $rl['output']) : 'not attempted (service/exec not configured)') . "\n";
        break;

    case 'mode':
        $printMode = function () use (&$cfg) {
            $st = scheduleStatus($cfg);
            printf("schedule=%s tz=%s current=%s desired=%s next_change=%s\n",
                $st['enabled'] ? ($st['valid'] ? 'on' : 'on (INVALID JSON)') : 'off', $st['tz'], $st['current'],
                $st['desired'] ?? 'n/a', $st['next_change_local'] ?? 'n/a');
            echo "hours: " . $st['describe'] . "\n";
            printf("cmd: %s | last: %s%s%s\n", $st['cmd'] !== '' ? $st['cmd'] : '(none — setting flip only)',
                $st['last_result'] ?? 'never', $st['last_switch_at'] ? ' at ' . date('Y-m-d H:i:s', $st['last_switch_at']) . " ({$st['last_from']}→{$st['last_to']})" : '',
                $st['last_error'] ? ' error=' . $st['last_error'] : '');
        };
        $printMode();
        if (!empty($opts['apply'])) {
            $r = scheduleApply($db, $cfg, true);
            printf("apply: %s changed=%s%s%s%s%s\n", $r['ok'] ? 'ok' : 'FAILED', $r['changed'] ? 'yes' : 'no',
                $r['to'] ? " {$r['from']}→{$r['to']}" : '', $r['skipped'] ? " skipped={$r['skipped']}" : '',
                $r['error'] ? " error={$r['error']}" : '', $r['notes'] ? ' notes=' . implode(' | ', $r['notes']) : '');
            if ($r['output'] !== '') echo "output: " . $r['output'] . "\n";
            $printMode();
            exit($r['ok'] ? 0 : 1);
        }
        break;

    case 'timeline':
        if (!empty($opts['tick'])) {
            $t = statsTimelineTick($db, $cfg);
            printf("tick: enabled=%s sampled=%s source=%s rollup5m=%d rollup1h=%d prune=%s%s\n", $t['enabled'] ? 'yes' : 'no', $t['sampled'] ? 'yes' : 'no',
                $t['source'] ?? '-', (int)($t['rollup']['5m'] ?? 0), (int)($t['rollup']['1h'] ?? 0),
                $t['prune'] ? "{$t['prune']['raw']}/{$t['prune']['5m']}" : 'skipped', $t['error'] !== null ? " error={$t['error']}" : '');
        }
        $s = statsTimelineStatus($db, $cfg);
        $st = $s['state'];
        printf("timeline=%s public=%s interval=%ds raw_days=%d keep_days=%d rows: raw=%d 5m=%d 1h=%d\n", $s['enabled'] ? 'on' : 'off', $s['public'] ? 'yes' : 'no',
            $s['interval'], $s['raw_days'], $s['keep_days'], $s['counts']['raw'], $s['counts']['5m'], $s['counts']['1h']);
        printf("last_sample=%s (%s) total=%d last_tick=%s rollup_until: 5m=%s 1h=%s last_prune=%s%s\n",
            $st['last_sample_at'] ? date('Y-m-d H:i:s', $st['last_sample_at']) : 'never', $st['last_sample_source'] ?: '-', (int)$st['samples_total'],
            $st['last_tick_at'] ? date('Y-m-d H:i:s', $st['last_tick_at']) : 'never',
            $st['rollup_5m_until'] ? date('Y-m-d H:i', $st['rollup_5m_until']) : '-', $st['rollup_1h_until'] ? date('Y-m-d H:i', $st['rollup_1h_until']) : '-',
            $st['last_prune_at'] ? date('Y-m-d H:i', $st['last_prune_at']) : 'never',
            $st['last_error'] ? " LAST ERROR ({$st['last_error_at']}): {$st['last_error']}" : '');
        if ($st['last_sample']) echo 'last values: ' . json_encode($st['last_sample']) . "\n";
        break;

    case 'index':
        if (!empty($opts['poll'])) {
            $p = indexPoll($db, $cfg, null, time());
            printf("poll: %s entries=%d kept=%d%s removed_wl=%d removed_ban=%d bytes=%d ms=%d%s\n", $p['ok'] ? 'ok' : 'FAILED',
                $p['entries'], $p['kept'], $p['truncated'] ? ' TRUNCATED' : '', $p['removed_wl'], $p['removed_ban'], $p['bytes'], $p['ms'], $p['error'] ? " error={$p['error']}" : '');
        }
        if (!empty($opts['tick'])) {
            $t = indexTick($db, $cfg);
            printf("tick: enabled=%s polled=%s meta_queued=%d prune=%s%s\n", $t['enabled'] ? 'yes' : 'no', $t['polled'] ? 'yes' : 'no',
                (int)$t['meta_queued'], $t['prune'] ? "{$t['prune']['expired']}/{$t['prune']['capped']}" : 'skipped', $t['error'] ? " error={$t['error']}" : '');
        }
        $s = indexStatus($db, $cfg);
        $c = $s['counts']; $st = $s['state'];
        printf("index=%s source=%s poll=%dm min_seeders=%d max_rows=%d grace=%dd protect=%dd meta_budget=%d/day\n",
            $s['enabled'] ? 'on' : 'off', $s['source_url'], $s['poll_minutes'], $s['min_seeders'], $s['max_rows'], $s['grace_days'], $s['protect_days'], $s['meta_daily_budget']);
        printf("rows: total=%d in_grace=%d protected=%d promoted=%d files=%d | meta none=%d pending=%d fetching=%d done=%d failed=%d\n",
            $c['total'], $c['in_grace'], $c['protected'], $c['promoted'], $c['files'], $c['meta_none'], $c['meta_pending'], $c['meta_fetching'], $c['meta_done'], $c['meta_failed']);
        printf("last_poll=%s%s meta_budget_used=%d/%s last_prune=%s%s\n",
            $st['last_poll_at'] ? date('Y-m-d H:i:s', $st['last_poll_at']) : 'never',
            $st['last_poll'] ? " (entries={$st['last_poll']['entries']} kept={$st['last_poll']['kept']} ms={$st['last_poll']['ms']})" : '',
            (int)$st['meta_budget_used'], $st['meta_budget_day'] ?: '-',
            $st['last_prune_at'] ? date('Y-m-d H:i', $st['last_prune_at']) : 'never',
            $st['last_error'] ? " LAST ERROR ({$st['last_error_at']}): {$st['last_error']}" : '');
        break;

    default:
        fwrite(STDERR, "unknown command: $cmd\n");
        exit(2);
}
exit(0);
