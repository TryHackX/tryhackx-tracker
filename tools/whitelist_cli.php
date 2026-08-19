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
        $source = in_array($opts['source'] ?? 'admin', ['web', 'api', 'admin', 'forum'], true) ? $opts['source'] : 'admin';
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

    default:
        fwrite(STDERR, "unknown command: $cmd\n");
        exit(2);
}
exit(0);
