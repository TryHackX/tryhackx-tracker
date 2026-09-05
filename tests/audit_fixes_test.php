<?php
/**
 * Regression checks for the audit findings closed in 1.32.0 – 1.33.0:
 *   php tests/audit_fixes_test.php
 *
 * Most of these are checks on the SOURCE rather than on behaviour, and that is deliberate: each of
 * these bugs was a missing guard, a missing lock or a missing keyword, and the cheapest way to make
 * sure it is not quietly removed in a refactor is to grep for it. The two behavioural checks at the
 * end are the ones where behaviour is cheap to exercise (a lock file, a regex that used to blow up).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $rel): string => (string)file_get_contents($root . '/' . $rel);

// ── the two CRITICALs ───────────────────────────────────────────────────────
$tuner = $src('tools/tuner.py');
check('tuner passes --trusted/--blocked/--sets to the helper, so a probe step cannot empty the sets',
      substr_count($tuner, '--trusted=') >= 2 && substr_count($tuner, '--blocked=') >= 2);
check('a dry-run restore clears its own marker instead of leaving the janitor a real write',
      str_contains($tuner, "st.pop('restore', None)\n        st['restored_at']") || preg_match('/if dry:.{0,400}st\.pop\(\'restore\'/s', $tuner));
$appjs = $src('assets/js/app.js');
check('the public status page checks a submitted link\'s scheme before making it clickable',
      str_contains($appjs, "/^https?:\\/\\//i.test(String(json.link)"));
check('… and escapes it with escAttr, not the quote-blind escHtml',
      str_contains($appjs, 'escAttr(json.link)'));

// ── HIGH ────────────────────────────────────────────────────────────────────
$apiAuth = $src('includes/api_auth.php');
check('a failed bearer auth redacts the request body by field name', str_contains($apiAuth, 'function apiRedactBody'));
check('… and the snapshot goes through it', str_contains($apiAuth, '$body = apiRedactBody($body);'));
check('apiAuthenticate names the actor for the audit log', str_contains($apiAuth, "\$GLOBALS['apiClient'] = ["));
check('index_info gates on a permission that exists', !str_contains($src('api/index_info.php'), "'index.search'"));
$users = $src('includes/users.php');
check('a user with panel.access gets a panel session, not only an admin-group member',
      str_contains($users, '!userIsAdminGroup($db, (int)$user[\'id\']) && !userHasPanelAccess($db, (int)$user[\'id\'])'));
check('a remember-me return opens the panel session too',
      (bool)preg_match('/userRememberIssue\(\$db, \(int\)\$u\[\'id\'\], \(int\)\$tok\[\'exp\'\]\);\s*\n.*\n?.*\n?\s*userMaybeOpenPanelSession\(\$db, \$u\);/', $users));
$janitor = $src('tools/janitor.php');
check('the sets file is written whether the address lists are on or off',
      (bool)preg_match('/\$sets = ipListWriteSetsFile\(\$db, \$cfg\);\s*\n\s*if \(\(\$cfg\[\'net_lists_enabled\'\]/', $janitor));
check('… and the clear is pushed to the firewall when they are off', str_contains($janitor, "'lists-off'"));
$fn = $src('includes/functions.php');
check('rateLimitAllow holds one lock across the read and the write', str_contains($fn, 'function rateLimitAllowLocked'));
check('… on a separate lock file', str_contains($fn, "@fopen(\$file . '.lock', 'c')"));
$revoke = $src('api/v1/users_revoke.php');
check('v1/users/revoke refuses a panel-carrying group', str_contains($revoke, 'userIsPanelPermission'));
check('… and will not strip the admin group from the site owner', str_contains($revoke, 'userIsRootAdmin($u, $cfg)'));
check('fetch_index counts come from the keyed cache', str_contains($src('api/admin/fetch_index.php'), "indexStatusCached(\$db, 'listcounts'"));
check('bulk scrape counts the remainder once, on the first call',
      str_contains($src('api/admin/index_scrape_bulk.php'), "if (\$scope !== 'page' && \$after === '')"));
check('… and the client keeps the arithmetic', str_contains($src('assets/js/admin-index.js'), 'left = Math.max(0, left - (r.processed || 0))'));
$index = $src('includes/index.php');
check('fixed-vocabulary filter counts are cached', str_contains($index, "'listcount_' . md5("));
check('the orphan sweep runs only when a prune deleted something, and in batches',
      str_contains($index, 'WHERE h.info_hash IS NULL LIMIT 2000') && !str_contains($index, "|| \$force) {\n        \$res['orphan_files']"));
check('a forced prune that trimmed nothing stands down', str_contains($index, "force_idle_until"));
$worker = $src('worker/worker.py');
check('the worker stores status, files and claim in one transaction', str_contains($worker, 'conn.begin()') && str_contains($worker, 'conn.rollback()'));
check('… and releases the claim LAST', str_contains($worker, 'UPDATE %s SET meta_claim=NULL WHERE %s=%%s AND meta_claim=%%s'));

// ── MEDIUM / LOW ────────────────────────────────────────────────────────────
check('backup restore-db no longer shadows the PDO handle', !str_contains($src('api/admin/backup_action.php'), "\$db      = trim((string)(\$input['db']"));
check('clearLoginFailures runs under the failures lock', (bool)preg_match('/function clearLoginFailures.{0,900}loginAttemptsUpdate\(/s', $src('includes/auth.php')));
check('scheduleSyncBansToBlacklist appends under withBlacklistLock', str_contains($src('includes/schedule.php'), 'return withBlacklistLock($path, function () use ($db, $path, $banned, $out)'));
$audit = $src('includes/audit.php');
check('v1 server-to-server calls are audited', str_contains($audit, "str_starts_with(\$endpoint, 'v1/')"));
check('wlProbeTick has a wall-clock budget and a quiet-tracker abort', str_contains($src('includes/wlprobe.php'), '$deadline = microtime(true) + 20.0;') && str_contains($src('includes/wlprobe.php'), '$quiet >= 5'));
check('admin/logout is POST only', str_contains($src('api/admin/logout.php'), 'requirePost();'));
check('the overlap cache key covers the content of the blocked side', str_contains($src('includes/iplist.php'), "md5(implode(',', \$against))"));
$rt = $src('includes/richtext.php');
check('[quote=] title group is anchored (no ] inside) — the quadratic backtracking is gone',
      str_contains($rt, '([^\]\n]{1,64}?)') && str_contains($rt, '([^\]\n]{1,80}?)'));
check('… and a pattern that gives up falls back to its input instead of deleting the text',
      substr_count($rt, ') ?? $s;') >= 4);
check('the netlimit poll does not write over a pps field that has focus', str_contains($src('assets/js/admin-netlimit.js'), 'function ppsFieldBusy()'));
check('the sysctl poll does not repaint over an edit', str_contains($src('assets/js/admin-sysctl.js'), 'if (formDirty() || gridHasFocus()) return;'));
check('the index status card keeps the last good numbers on a failed poll', str_contains($src('assets/js/admin-index.js'), 'function markStale('));
check('transparency numbers rows from the page size the server used', str_contains($src('api/transparency.php'), "'per_page' => \$perPage"));
check('the coverage chart destroys uPlot before dropping it', str_contains($src('assets/js/admin-index-coverage.js'), 'chart.destroy()'));

// ── behaviour, where it is cheap ────────────────────────────────────────────
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/richtext.php';
$cfg = getSettings(getDb());
// The input from the finding: hundreds of [quote= openers followed by a long tail used to make PCRE
// give up past ~15 600 characters, and the description came back empty.
$bad = str_repeat('[quote=x]a[/quote] ', 400) . str_repeat('b', 20000);
$t = microtime(true); $html = richtextRender($bad, 'bbcode', $cfg, true); $ms = (microtime(true) - $t) * 1000;
check('the pathological quote input renders instead of vanishing', strlen($html) > 20000, strlen($html) . ' chars');
check('… with every quote rendered', substr_count($html, '<blockquote') === 400, (string)substr_count($html, '<blockquote'));
check('… in well under a second', $ms < 1000, sprintf('%.0f ms', $ms));

$lock = $root . '/config/rate_limits.json.lock';
@unlink($lock);
// A per-run action name: the window is 60 s, and a fixed key made a second run inside a minute
// find the bucket already full.
$act = 'audit_fixes_test_' . getmypid() . '_' . mt_rand();
rateLimitAllow($act, '203.0.113.9', 5, 60);
check('rateLimitAllow leaves its lock file behind, which is where the flock lives', is_file($lock));
$ok = 0; for ($i = 0; $i < 10; $i++) $ok += rateLimitAllow($act, '203.0.113.10', 5, 60) ? 1 : 0;
check('the limit still counts: 5 of 10 allowed', $ok === 5, (string)$ok);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
