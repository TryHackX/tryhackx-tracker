<?php
/**
 * Every route of api.php, held down by a ratchet (1.74.0 part E, QUAL-5):
 *   php tests/routes_ratchet_test.php
 * No database. The last section asks the local site on http://127.0.0.1:8089/ (VERIFY_BASE to point elsewhere) and
 * says SKIP without it.
 *
 *   1. every route of $apiRoutes has its file — a route to nothing is a 500 for whoever finds it;
 *   2. every `admin/*` route is either in adminEndpointPermission()'s map (a moderator may, with that permission) or
 *      on OWNER_ONLY below. The map is default-deny, so forgetting it is safe — but a new panel endpoint then gets a
 *      DECISION, written here, instead of quietly being the owner's;
 *   3. THE RATCHET: a route that no test, browser check or smoke names is on KNOWN_UNTESTED below, and the list only
 *      shrinks. A new route without a test fails here until it has one (or is added, by hand, with a reason to look
 *      at it later); a listed route that gained a test fails too, until it leaves the list;
 *   4. the router's refusals, asked once per kind of caller: a panel route without a session (401), a partner route
 *      without a key (refused), a public form without its CSRF token (403), an unknown name (404) — and each public
 *      route on the list runs to its first guard without a 5xx.
 * How a route counts as named (the audit's verifier's rule): its full key in quotes or after `endpoint=` / `/`, its
 * file's path, or — for a panel route — its bare name quoted in a test that builds `api/admin/` paths (the runners).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }

/**
 * The panel routes no moderator permission opens: the owner's, by decision. A new panel endpoint goes here or into
 * adminEndpointPermission()'s map — consciously. (Most of these also ask for the owner's password themselves.)
 */
const OWNER_ONLY = [
    'admin/twofa', 'admin/delete_all', 'admin/save_settings', 'admin/settings_catalog', 'admin/csp_reports', 'admin/change_password',
    'admin/account_email', 'admin/delete_permanently', 'admin/restart_tracker', 'admin/reload_tracker', 'admin/test_tracker_permission',
    'admin/ot_apply', 'admin/ot_test', 'admin/sysctl_apply', 'admin/sysctl_test', 'admin/dbmem_apply', 'admin/dbmem_test',
    'admin/ot_cluster_apply', 'admin/ot_cluster_test', 'admin/ip_list_action', 'admin/net_apply', 'admin/net_test', 'admin/backup_action',
    'admin/backup_test_path', 'admin/backup_download', 'admin/check_whitelist_path', 'admin/tracker_mode', 'admin/whitelist_regenerate',
    'admin/whitelist_import_blacklist', 'admin/page_content', 'admin/home_layout', 'admin/languages', 'admin/sounds', 'admin/iconpacks',
    'admin/index_poll_now', 'admin/fetch_api_clients', 'admin/api_client_create', 'admin/api_client_update', 'admin/api_client_delete',
    'admin/fetch_api_bans', 'admin/api_ban_lift', 'admin/api_ban_add', 'admin/shout_purge', 'admin/shout_emotes', 'admin/user_delete',
    'admin/bulk_send', 'admin/livesync_test', 'admin/livesync_apply', 'admin/group_save', 'admin/group_delete', 'admin/group_recommended',
    'admin/fetch_fed_peers', 'admin/fed_peer_save', 'admin/fed_peer_delete', 'admin/fed_peer_test', 'admin/fed_review', 'admin/fed_purge',
];
/** Before any session exists: the sign-in's two halves, each with its own gate (api.php). */
const PRE_AUTH = ['admin/login', 'admin/login_2fa'];

/**
 * The routes no test, browser check or smoke names (1.74.0: 28 — the audit counted 37 before the verifier's
 * correction, and 1.74.0's own tests took check_status, submit_appeal and save_email_preferences off it).
 * ONLY SHRINKS: give one of these a test and take it off; never add a route here to make this file pass without
 * writing down why it cannot be tested yet.
 */
const KNOWN_UNTESTED = [
    // the reports' queue actions — reached by clicking in the panel, no test of their own yet
    'admin/change_status', 'admin/unblock_hash', 'admin/send_email', 'admin/delete_report', 'admin/check_blacklist',
    'admin/notify_review', 'admin/fetch_appeals', 'admin/block_archived',
    // panel reads drawn by pages (the browser checks open the pages, not the routes by name)
    'admin/settings_catalog', 'admin/csp_reports', 'admin/audit_log',
    // the helpers that act as root, or test paths on the machine: need the helper (a stub) to be worth testing
    'admin/reload_tracker', 'admin/test_tracker_permission', 'admin/ot_test', 'admin/backup_test_path', 'admin/livesync_test',
    'admin/whitelist_import_blacklist', 'admin/index_scrape',
    // accounts made and taken from the panel; the public sign-up, the reset's second half, the verification mail
    'admin/user_create', 'admin/user_revoke', 'user_register', 'user_reset_confirm', 'user_verify_send',
    // the probe's poll on the whitelist form
    'whitelist_probe',
    // the federation panel
    'admin/fetch_fed_peers', 'admin/fed_peer_save', 'admin/fed_peer_delete', 'admin/fed_peer_test',
];

/* ── the route table and the permission map, read out of api.php ──────────────────────────────────────────── */
$api = (string)@file_get_contents($root . '/api.php');
$routes = [];
if (preg_match('~\$apiRoutes\s*=\s*\[(.*?)\n\];~s', $api, $m)) {
    preg_match_all("~^\s*'([a-z0-9_/]+)'\s*=>\s*'(api/[a-z0-9_/]+\.php)'\s*,~m", $m[1], $rr, PREG_SET_ORDER);
    foreach ($rr as $x) $routes[$x[1]] = $x[2];
}
check('the route table was read out of api.php (' . count($routes) . ' routes)', count($routes) > 150, (string)count($routes));
$map = [];
if (preg_match('~function adminEndpointPermission\(string \$endpoint\): \?string \{(.*?)\n\}~s', $api, $fm)) {
    preg_match_all("~'(admin/[a-z0-9_]+)'\s*=>\s*'([a-z0-9_.]+)'~", $fm[1], $mm, PREG_SET_ORDER);
    foreach ($mm as $x) $map[$x[1]] = $x[2];
}
check('… and so was the panel\'s permission map (' . count($map) . ' entries)', count($map) > 40, (string)count($map));

/* ── 1. every route has its file ──────────────────────────────────────────────────────────────────────────── */
$missing = array_keys(array_filter($routes, fn($f) => !is_file($root . '/' . $f)));
check('every route has its file', $missing === [], implode(', ', $missing));

/* ── 2. every panel route is a decision ───────────────────────────────────────────────────────────────────── */
$undecided = []; $both = [];
foreach (array_keys($routes) as $k) {
    if (!str_starts_with($k, 'admin/') || in_array($k, PRE_AUTH, true)) continue;
    $mapped = isset($map[$k]);
    $owner = in_array($k, OWNER_ONLY, true);
    if (!$mapped && !$owner) $undecided[] = $k;
    if ($mapped && $owner) $both[] = $k;
}
check('every panel route is in the permission map or on OWNER_ONLY — a new one is a decision, not a default',
      $undecided === [], implode(', ', $undecided));
check('… and none is both', $both === [], implode(', ', $both));
$gone = array_values(array_filter(OWNER_ONLY, fn($k) => !isset($routes[$k])));
check('… OWNER_ONLY names only routes that exist', $gone === [], implode(', ', $gone));
$unrouted = array_values(array_filter(array_keys($map), fn($k) => !isset($routes[$k])));
check('… and so does the map', $unrouted === [], implode(', ', $unrouted));

/* ── 3. the ratchet ───────────────────────────────────────────────────────────────────────────────────────── */
$corpus = ''; $runners = ''; $files = 0;
foreach (array_merge(glob($root . '/tests/*.php') ?: [], glob($root . '/tests/*.py') ?: [], glob($root . '/scratchpad/shots/*.js') ?: [],
                     glob($root . '/scratchpad/shots/*.php') ?: [], glob($root . '/scratchpad/shots/*.py') ?: [],
                     glob(dirname($root) . '/deploy/smoke_*.py') ?: []) as $f) {
    if (basename($f) === basename(__FILE__)) continue;   // this file names every route on its lists
    $c = (string)@file_get_contents($f);
    $corpus .= $c . "\n";
    if (str_contains($c, 'api/admin/')) $runners .= $c . "\n";
    $files++;
}
check('the tests, the browser checks and the smokes were read (' . $files . ' files)', $files > 60, (string)$files);
$named = function (string $k, string $file) use ($corpus, $runners): bool {
    if (preg_match('~(?:[\'"`=/]|\bendpoint=)' . preg_quote($k, '~') . '(?:[\'"`&?\s\)\]]|$)~m', $corpus)) return true;
    if (str_contains($corpus, $file)) return true;
    return str_starts_with($k, 'admin/') && (bool)preg_match('~([\'"])' . preg_quote(substr($k, 6), '~') . '\1~', $runners);
};
$untested = [];
foreach ($routes as $k => $file) if (!$named($k, $file)) $untested[] = $k;
$new = array_values(array_diff($untested, KNOWN_UNTESTED));
check('THE RATCHET: no route without a test that is not already on KNOWN_UNTESTED (' . count($untested) . ' untested)',
      $new === [], 'give these a test (or write down why not on the list): ' . implode(', ', $new));
$gained = array_values(array_diff(KNOWN_UNTESTED, $untested));
check('… and a listed route that has a test now leaves the list (it only shrinks)', $gained === [],
      'take these off KNOWN_UNTESTED: ' . implode(', ', $gained));
$unknown = array_values(array_filter(KNOWN_UNTESTED, fn($k) => !isset($routes[$k])));
check('… KNOWN_UNTESTED names only routes that exist', $unknown === [], implode(', ', $unknown));

/* ── 4. the router's refusals, once per kind of caller ────────────────────────────────────────────────────── */
$site = rtrim(getenv('VERIFY_BASE') ?: 'http://127.0.0.1:8089/', '/') . '/';
$probe = @file_get_contents($site . '?action=tos', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
if ($probe === false || !function_exists('curl_init')) {
    skip('the refusals over HTTP', 'the local site does not answer at ' . $site);
} else {
    $call = function (string $endpoint, string $method = 'GET', ?array $json = null, array $headers = []) use ($site): array {
        $c = curl_init($site . 'api.php?endpoint=' . $endpoint);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
        if ($method === 'POST') {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($json ?? []));
            $headers[] = 'Content-Type: application/json';
        }
        if ($headers) curl_setopt($c, CURLOPT_HTTPHEADER, $headers);
        $body = (string)curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        return ['code' => $code, 'json' => json_decode($body, true), 'body' => substr($body, 0, 200)];
    };
    $r = $call('admin/fetch_users');
    check('a panel route without a session: 401 (the router asks before the endpoint\'s file is read)', $r['code'] === 401, $r['code'] . ' ' . $r['body']);
    $r = $call('admin/save_settings', 'POST', ['site_name' => 'x']);
    check('… a panel write without one too: 401, nothing saved', $r['code'] === 401, $r['code'] . ' ' . $r['body']);
    $r = $call('v1/whitelist/ping');
    check('a partner route without a key: refused (401 without a key, or 503 while the API is off) — never 200',
          in_array($r['code'], [401, 403, 503], true), $r['code'] . ' ' . $r['body']);
    $r = $call('user_register', 'POST', ['username' => 'nobody_here', 'password' => 'x']);
    check('a public form without its CSRF token: 403', $r['code'] === 403, $r['code'] . ' ' . $r['body']);
    $r = $call('no_such_route_' . bin2hex(random_bytes(3)));
    check('an unknown name: 404 "Unknown endpoint"', $r['code'] === 404 && ($r['json']['error'] ?? '') === 'Unknown endpoint', $r['code'] . ' ' . $r['body']);
    // The public routes nothing else tests run, at least, to their first guard — a JSON answer under 500.
    $bad = [];
    foreach (KNOWN_UNTESTED as $k) {
        if (str_starts_with($k, 'admin/')) continue;
        $r = $call($k);
        if ($r['code'] === 0 || $r['code'] >= 500 || !is_array($r['json'])) $bad[] = $k . ' ' . $r['code'] . ' ' . $r['body'];
    }
    check('each public route on KNOWN_UNTESTED answers a plain GET with JSON and no 5xx (its first guard)', $bad === [], implode(' | ', $bad));
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails ? 1 : 0);
