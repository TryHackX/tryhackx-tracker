<?php
/**
 * CLI janitor for the whitelist service — run it from a systemd timer (or cron) as the web user:
 *
 *   sudo -u www-data php /var/www/tracker.tryhackx.org/tools/janitor.php
 *
 * It fires any due whitelist file regeneration / tracker reload (SIGHUP) that the request-driven
 * janitor could not run because nobody visited the site, removes stale temp files and prunes old
 * API bans. Safe to run every minute; exits 0 always. See README "Whitelist mode".
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

try {
    $db  = getDb();
    $cfg = getSettings($db);
    ensureSchema($db, $cfg);
    $GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfg;
    $before = whitelistStateRead();
    whitelistJanitor($db, $cfg);
    $after = whitelistStateRead();
    $msg = sprintf('mode=%s pending_reload=%s regen_needed=%s last_reload_ok=%s fail_count=%d count=%d',
        trackerMode($cfg), $after['pending_reload'] ? 'yes' : 'no', $after['regen_needed'] ? 'yes' : 'no',
        $after['last_reload_ok'] === null ? 'n/a' : ($after['last_reload_ok'] ? 'yes' : 'no'), (int)$after['fail_count'], (int)$after['count']);
    if (in_array('-v', $argv ?? [], true) || $before['pending_reload'] || $before['regen_needed']) echo $msg, "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '[janitor] ' . $e->getMessage() . "\n");
}
exit(0);
