<?php
/**
 * The security release's smaller fixes, as behaviour (1.74.0) — needs the local test database:
 *   php tests/security_1740_test.php
 *
 *   PUB-3   an array where a string belongs: a clean 4xx JSON from every public endpoint the audit named, never an
 *           uncaught TypeError (on production a blank 500); the token helpers and sanitize() take anything;
 *   PUB-2   the status lookup, the block lookup, appeals and the report form's hourly limit count an IPv6 host by
 *           its /64 — two addresses of one /64 share one budget, as requests to the real endpoint files;
 *   PUB-1   the report form's confirmation mail is sent only under the day's cap;
 *   QUAL-21 an empty HMAC secret verifies no unsubscribe token;
 *   AUTH-5  the duplicate fingerprint sees through zero-width characters, soft hyphens and compatibility forms;
 *   AUTH-6  the sign-in bridge refuses without a site URL; PUB-4 its `next` refuses any backslash;
 *   AUTH-9  a session id the server never issued is not adopted;
 *   XSS-6   the footer prints an http(s) address or none; XSS-7 the Traffic page's dry-run title is escaped;
 *   SRV-1   a magnet from the public form keeps its hash and its name and nothing else, rebuilt on our announce URLs;
 *   XSS-1   reCAPTCHA's two extras in the policy, only where a CAPTCHA can be drawn.
 * Self-cleaning: its reports, whitelist rows and limiter counts are removed, the mail count put back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
// Everything api.php loads, read out of api.php itself, so this file and the router cannot drift apart.
preg_match_all("#^require_once __DIR__ \. '/([^']+)';#m", (string)file_get_contents($root . '/api.php'), $apiReq);
$includes = $apiReq[1];
foreach ($includes as $f) require_once $root . '/' . $f;
require_once $root . '/includes/health.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$cfg = getSettings($db, true);
langInit([], 'en');
$tmpFiles = [];
const SEC_HASHES = ['e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e1', 'e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e2', 'e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e3',
                    'e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e4', 'e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e5'];
$mailFile = confirmMailFile();
$mailWas = is_file($mailFile) ? (string)file_get_contents($mailFile) : null;
$cleanup = function () use ($db, &$tmpFiles, $mailFile, $mailWas): void {
    $ph = implode(',', array_fill(0, count(SEC_HASHES), '?'));
    foreach (['reports', 'appeals'] as $t) $db->prepare("DELETE FROM $t WHERE infoHash IN ($ph)")->execute(SEC_HASHES);
    $db->exec("DELETE FROM reports WHERE email = 'sectest@example.org' OR ip LIKE '2001:db8:5ec:%' OR ip LIKE '198.51.100.4_'");
    $db->prepare("DELETE FROM whitelist WHERE info_hash IN ($ph)")->execute(SEC_HASHES);
    rateLimitForgetWhere(fn(string $a, string $s): bool => str_starts_with($s, '2001:db8:5ec:') || str_starts_with($s, '198.51.100.23'));
    if ($mailWas === null) @unlink($mailFile); else file_put_contents($mailFile, $mailWas);
    foreach ($tmpFiles as $f) @unlink($f);
};
$cleanup();
register_shutdown_function($cleanup);

/* ── the endpoint files, as requests: a runner that loads what api.php loads and includes the file ── */
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sec1740_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = $a["ip"];
$_GET = $a["get"];
$_POST = $a["post"];
register_shutdown_function(function () {
    $e = error_get_last();
    $fatal = ($e && in_array($e["type"], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) ? $e["message"] : null;
    echo "\n@@" . json_encode(["code" => http_response_code(), "fatal" => $fatal]);
});
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
foreach ($a["includes"] as $f) require_once $a["root"] . "/" . $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
langInit($cfg, "en");
require $a["root"] . "/" . $a["file"];
');
$call = function (string $file, string $method, array $get, array $post, array $cfgX = [], string $ip = '198.51.100.230', array $session = []) use ($root, $runner, $includes, &$tmpFiles): array {
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sec1740_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmpFiles[] = $argFile;
    $base = ['recaptcha_enabled' => '0', 'trusted_proxy_ips' => '', 'client_ip_header' => '', 'transparency_enabled' => '1', 'users_enabled' => '1'];
    file_put_contents($argFile, json_encode(['root' => $root, 'file' => $file, 'method' => $method, 'get' => $get, 'post' => $post,
        'cfg' => array_merge($base, $cfgX), 'ip' => $ip, 'includes' => $includes, 'session' => $session + ['csrf_token' => 'sec-token'],
        'sid' => 'sectest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    $pos = strrpos($out, "\n@@");
    $meta = $pos !== false ? (json_decode(substr($out, $pos + 3), true) ?: []) : [];
    $body = trim($pos !== false ? substr($out, 0, $pos) : $out);
    return ['code' => (int)($meta['code'] ?? 0), 'fatal' => $meta['fatal'] ?? null, 'json' => json_decode($body, true), 'raw' => substr($body, 0, 400)];
};
$clean4xx = fn(array $r): bool => $r['fatal'] === null && is_array($r['json']) && $r['code'] >= 400 && $r['code'] < 500 && isset($r['json']['error']);

/* ══ PUB-3 ══════════════════════════════════════════════════════════════════════════════════════ */
check('the helpers take anything: verifyCsrfToken([…]) is no token', !verifyCsrfToken(['x']) && !verifyCsrfToken(null) && !verifyCsrfToken(5));
check('… verifyUnsubscribeToken with an array for the address or the token is false', !verifyUnsubscribeToken(['a@b.c'], 'x', 'secret')
      && !verifyUnsubscribeToken('a@b.c', ['x'], 'secret'));
check('… sanitize() of an array is empty, of a number its digits', sanitize(['<x>']) === '' && sanitize(42) === '42' && sanitize(' <b> ') === '&lt;b&gt;');
check('… strInput(): a string as it is, a number as its digits, anything else the default',
      strInput(['k' => 'v'], 'k') === 'v' && strInput(['k' => 7], 'k') === '7' && strInput(['k' => ['v']], 'k', 'd') === 'd'
      && strInput(['k' => null], 'k', 'd') === 'd' && strInput([], 'k') === '');
$H = SEC_HASHES[0];
$reqs = [
    'check_block GET hash[]=x'                => ['api/check_block.php', 'GET', ['hash' => ['x']], []],
    'check_block POST {"hash":["x"]}'         => ['api/check_block.php', 'POST', [], ['hash' => ['x']]],
    'check_status POST search_query[]'        => ['api/check_status.php', 'POST', [], ['search_query' => ['x'], 'email' => 'a@b.cd']],
    'submit_report POST email[] (valid CSRF)' => ['api/submit_report.php', 'POST', [], ['csrf_token' => 'sec-token', 'email' => ['x'], 'name' => 'n']],
    'submit_appeal POST infoHash[]'           => ['api/submit_appeal.php', 'POST', [], ['csrf_token' => 'sec-token', 'infoHash' => ['x']]],
    'unsubscribe GET email[]=a'               => ['api/unsubscribe.php', 'GET', ['email' => ['a'], 'token' => 'b'], []],
    'save_email_preferences POST email[]'     => ['api/save_email_preferences.php', 'POST', [], ['email' => ['x'], 'token' => 'y']],
    'user_login POST csrf_token[]'            => ['api/user_login.php', 'POST', [], ['csrf_token' => ['x'], 'login' => 'a', 'password' => 'b']],
    'submit_report POST csrf_token[]'         => ['api/submit_report.php', 'POST', [], ['csrf_token' => ['x']]],
];
$bad = [];
foreach ($reqs as $label => [$file, $method, $get, $post]) {
    $r = $call($file, $method, $get, $post, ['rate_limit_appeal' => '100', 'rate_limit' => '100'], '198.51.100.231');
    if (!$clean4xx($r)) $bad[$label] = ['code' => $r['code'], 'fatal' => $r['fatal'], 'raw' => $r['raw']];
}
check('an array where a string belongs: a clean 4xx JSON answer from each of ' . count($reqs) . ' requests, not an uncaught TypeError',
      $bad === [], json_encode($bad));
$tr = $call('api/transparency.php', 'GET', ['sort' => ['x']], []);
check('transparency with sort[]=x answers the default order, 200 JSON', $tr['fatal'] === null && $tr['code'] === 200 && !empty($tr['json']['success']),
      json_encode([$tr['code'], $tr['fatal'], $tr['raw']]));
rateLimitForgetWhere(fn(string $a, string $s): bool => str_starts_with($s, '198.51.100.23'));

/* ══ PUB-2: an address GROUP, at the real endpoints ═════════════════════════════════════════════ */
$sameA = '2001:db8:5ec:1::1'; $sameB = '2001:db8:5ec:1:ffff::2'; $sameC = '2001:db8:5ec:1::dead'; $other = '2001:db8:5ec:2::1';
$codes = function (string $file, string $method, array $get, array $post, array $cfgX) use ($call, $sameA, $sameB, $sameC, $other): array {
    return [$call($file, $method, $get, $post, $cfgX, $sameA)['code'], $call($file, $method, $get, $post, $cfgX, $sameB)['code'],
            $call($file, $method, $get, $post, $cfgX, $sameC)['code'], $call($file, $method, $get, $post, $cfgX, $other)['code']];
};
$cb = $codes('api/check_block.php', 'GET', ['hash' => $H], [], ['rate_limit_block_check' => '2']);
check('the block lookup (GET, no CAPTCHA by design): two addresses of one /64 spend its budget of two, the third address of it is refused, the next /64 is not',
      $cb[0] === 200 && $cb[1] === 200 && $cb[2] === 429 && $cb[3] === 200, json_encode($cb));
$cs = $codes('api/check_status.php', 'POST', [], ['search_query' => '1', 'email' => 'sectest@example.org'], ['rate_limit_status' => '2']);
check('the status lookup the same', $cs[0] === 200 && $cs[1] === 200 && $cs[2] === 429 && $cs[3] === 200, json_encode($cs));
$ap = $codes('api/submit_appeal.php', 'POST', [], ['csrf_token' => 'sec-token', 'infoHash' => $H, 'name' => 'n', 'email' => 'sectest@example.org',
             'message' => 'please'], ['rate_limit_appeal' => '2']);
check('appeals the same (their own limit; the third address of the /64 refused before anything else is read)',
      $ap[0] !== 429 && $ap[1] !== 429 && $ap[2] === 429 && $ap[3] !== 429, json_encode($ap));
$insRep = $db->prepare("INSERT INTO reports (name, representative, company, email, objectTitle, link, infoHash, ip, add_message, checked, blocked, timestamp)
                        VALUES ('n', 'r', 'c', 'sectest@example.org', 'o', 'https://example.org/x', ?, ?, '', 0, 0, NOW())");
$insRep->execute([SEC_HASHES[1], '2001:db8:5ec:1::5']);
$insRep->execute([SEC_HASHES[2], '2001:db8:5ec:1:abcd::6']);
$insRep->execute([SEC_HASHES[3], '198.51.100.40']);
check('the report form\'s hourly limit counts the /64: two reports from two addresses of it fill a limit of two for a third address',
      checkRateLimit($db, '2001:db8:5ec:1::ffff', 2) === false && checkRateLimit($db, '2001:db8:5ec:2::1', 2) === true);
check('… and IPv4 as before: the address itself', checkRateLimit($db, '198.51.100.40', 1) === false && checkRateLimit($db, '198.51.100.41', 1) === true
      && checkRateLimit($db, '::ffff:198.51.100.40', 1) === false);
$db->prepare("DELETE FROM reports WHERE infoHash IN (?, ?, ?)")->execute([SEC_HASHES[1], SEC_HASHES[2], SEC_HASHES[3]]);

/* ══ PUB-1: the day's cap on confirmation mails, at the report form ═════════════════════════════ */
file_put_contents($mailFile, json_encode(['day' => date('Y-m-d'), 'n' => 0]));
$rep = fn(string $hash) => $call('api/submit_report.php', 'POST', [], ['csrf_token' => 'sec-token', 'name' => 'Sec Test', 'representative' => 'Rep',
    'company' => 'Co', 'email' => 'sectest@example.org', 'objectTitle' => 'Thing', 'link' => 'https://example.org/thing', 'infoHash' => $hash],
    ['confirm_mail_daily_cap' => '1', 'rate_limit' => '100'], '198.51.100.232');
$r1 = $rep(SEC_HASHES[3]);
$r2 = $rep(SEC_HASHES[4]);
check('two reports are both taken…', !empty($r1['json']['success']) && !empty($r2['json']['success']), json_encode([$r1['raw'], $r2['raw']]));
check('… but under a cap of one a day only the first confirmation was counted out — the second report got no mail',
      confirmMailSentToday() === 1, (string)confirmMailSentToday());
rateLimitForgetWhere(fn(string $a, string $s): bool => str_starts_with($s, '198.51.100.23'));

/* ══ QUAL-21 ════════════════════════════════════════════════════════════════════════════════════ */
check('an empty HMAC secret verifies nothing — not even the token computed with it', !verifyUnsubscribeToken('a@b.c', generateUnsubscribeToken('a@b.c', ''), '')
      && !verifyUnsubscribeToken('a@b.c', generateUnsubscribeToken('a@b.c', ''), '   '));
check('… while a real one still verifies its own token, and only its own', verifyUnsubscribeToken('a@b.c', generateUnsubscribeToken('a@b.c', 's3cret'), 's3cret')
      && !verifyUnsubscribeToken('x@b.c', generateUnsubscribeToken('a@b.c', 's3cret'), 's3cret'));

/* ══ AUTH-5 ═════════════════════════════════════════════════════════════════════════════════════ */
$base = antispamFingerprint('Buy cheap pills here now');
$variants = ['zero-width space' => "Buy che\u{200B}ap pills here now", 'soft hyphen' => "Buy che\u{00AD}ap pills here now",
             'word joiner' => "Buy cheap\u{2060} pills here now", 'BOM' => "\u{FEFF}Buy cheap pills here now",
             'zero-width joiner' => "Buy chea\u{200D}p pills here now", 'full-width letters' => 'Ｂｕｙ cheap pills here now',
             'case and spacing' => '  BUY   cheap pills here NOW '];
$diff = array_keys(array_filter($variants, fn($v) => antispamFingerprint($v) !== $base));
check('the same words with invisible characters or compatibility forms have the same fingerprint (' . implode(', ', array_keys($variants)) . ')',
      $base !== '' && $diff === [], implode(', ', $diff));
check('… and different words still differ', antispamFingerprint('Buy cheap pills here later') !== $base);

/* ══ AUTH-6, PUB-4 ══════════════════════════════════════════════════════════════════════════════ */
$bridgeOn = array_merge($cfg, ['users_enabled' => '1', 'auth_bridge_enabled' => '1']);
$client = ['id' => 999999, 'label' => 'sec-test'];
foreach (['' => 'empty', 'tracker.example.org' => 'not an http(s) address', 'javascript:alert(1)' => 'a script address'] as $su => $what) {
    check("the bridge refuses with a site URL that is $what — the handoff link would be built from the request's Host header",
          (authBridgeResolve($db, array_merge($bridgeOn, ['site_url' => $su]), $client, ['external_id' => '1'])['error'] ?? '') === 'bridge_needs_site_url');
}
check('… and goes on with one (here to the next refusal: an empty external id)',
      (authBridgeResolve($db, array_merge($bridgeOn, ['site_url' => 'https://tracker.example.org/']), $client, ['external_id' => ''])['error'] ?? '') === 'invalid_external_id');
check('next= refuses a backslash after the slash — a browser reads /\\host as //host', authBridgeNext('/\\evil.example/x', '/HOME') === '/HOME');
check('… or anywhere in the path', authBridgeNext('/ok/\\evil', '/HOME') === '/HOME' && authBridgeNext('/?action=search&q=a\\b', '/HOME') === '/HOME');
check('… and still takes a plain path', authBridgeNext('/?action=search', '/HOME') === '/?action=search');

/* ══ AUTH-9 ═════════════════════════════════════════════════════════════════════════════════════ */
$strict = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sec1740_strict_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $strict;
file_put_contents($strict, '<?php
require ' . var_export($root . '/includes/functions.php', true) . ';
session_id("madeupbyavisitor1234567890");
@session_start(sessionCookieParams([]));
$_SESSION["x"] = 1;
echo json_encode(["id" => session_id()]);
@session_destroy();
');
$sid = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($strict)), true);
check('a session id the server never issued is not adopted: the session that starts has an id of the server\'s own',
      is_array($sid) && ($sid['id'] ?? '') !== '' && $sid['id'] !== 'madeupbyavisitor1234567890', json_encode($sid));
check('… because every session_start() of the site is given strict mode', (sessionCookieParams([])['use_strict_mode'] ?? null) === true);

/* ══ XSS-6: the footer ══════════════════════════════════════════════════════════════════════════ */
check('safeHttpUrl(): http(s) with a host, or nothing', safeHttpUrl('https://example.org/x') === 'https://example.org/x' && safeHttpUrl('http://forum.lan/') !== ''
      && safeHttpUrl('javascript:alert(1)') === '' && safeHttpUrl('//evil.example') === '' && safeHttpUrl('data:text/html,x') === ''
      && safeHttpUrl('https://') === '' && safeHttpUrl("https://a.example/\nx") === '' && safeHttpUrl(['https://a.example']) === '');
$footerCfg = array_merge($cfg, ['footer_brand_enabled' => '1', 'footer_brand_name' => 'BrandX', 'footer_brand_url' => 'javascript:window.__xss=1',
    'footer_tracker_enabled' => '1', 'footer_tracker_name' => 'TrackX', 'footer_tracker_url' => 'https://tracker.example.org/',
    'footer_tracker_author' => 'AuthorX', 'footer_tracker_author_url' => 'JavaScript:alert(2)', 'footer_os_enabled' => '1',
    'footer_os_name' => 'OsX', 'footer_os_url' => 'data:text/html,x', 'github_url' => 'javascript:alert(3)//']);
$renderFooter = function (array $cfg): string { ob_start(); include dirname(__DIR__) . '/templates/footer.php'; return (string)ob_get_clean(); };
$foot = $renderFooter($footerCfg);
check('a footer address that is not http(s) prints as the plain name, never as an href',
      !preg_match('/href="\s*(javascript|data):/i', $foot) && str_contains($foot, 'BrandX') && str_contains($foot, 'AuthorX') && str_contains($foot, 'OsX')
      && !str_contains($foot, 'alert('), substr(strip_tags($foot), 0, 300));
check('… while an http(s) one is still a link', str_contains($foot, 'href="https://tracker.example.org/"'));
check('… and with no repository address there is no GitHub link and no licence link', !str_contains($foot, 'GitHub</a>') && !str_contains($foot, '/blob/main/LICENSE'));

/* ══ XSS-7: the Traffic page's dry-run title, rendered with a hostile translation ════════════════ */
$tpl = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sec1740_traffic_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $tpl;
file_put_contents($tpl, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
foreach ($a["includes"] as $f) require_once $a["root"] . "/" . $f;
$db = getDb();
$cfg = getSettings($db);
langInit($cfg, "en");
$GLOBALS["__lang"]["strings"]["a.traffic.tn_dry_title"] = "x\" onmouseover=\"window.__xss=1\" data-x=\"y &mdash; z";
$_SESSION = ["loggedin" => true, "login_time" => time(), "last_activity" => time()];
$baseUrl = "/"; $csrfToken = "t";
ob_start();
try { include $a["root"] . "/templates/admin/traffic.php"; } catch (\Throwable $e) { echo "\n<!--THROWN " . $e->getMessage() . "-->"; }
$html = (string)ob_get_clean();
echo preg_match("#<button[^>]*id=\"tn-dry\"[^>]*>#", $html, $m) ? $m[0] : "NO BUTTON " . substr($html, -300);
');
$tArgs = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sec1740_targs_' . bin2hex(random_bytes(4)) . '.json';
$tmpFiles[] = $tArgs;
file_put_contents($tArgs, json_encode(['root' => $root, 'includes' => $includes]));
$btn = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($tpl) . ' ' . escapeshellarg($tArgs) . ' 2>&1'));
check('a translation carrying a quote stays inside the dry-run button\'s title: no attribute of its own',
      str_starts_with($btn, '<button') && !preg_match('/\sonmouseover="/i', $btn) && str_contains($btn, 'x&quot; onmouseover=&quot;'), $btn);
check('… and the shipped text\'s entity reads as the dash, not as its name', str_contains($btn, 'y — z') && !str_contains($btn, '&amp;mdash;'), $btn);

/* ══ SRV-1: what a submitted magnet may carry into the table ═══════════════════════════════════════ */
$wlCfg = array_merge($cfg, ['tracker_mode' => 'blacklist', 'tracker_schedule_enabled' => '0', 'shout_system_lines' => '0',
    'announce_url' => 'udp://tracker.example.org:6969/announce', 'announce_url_https' => '', 'ot_cluster_enabled' => '0']);
$hostile = 'magnet:?xt=urn:btih:%s&dn=Sec+Test+Name&tr=http%%3A%%2F%%2F127.0.0.1%%3A8080%%2Fannounce&tr=udp://10.0.0.5:6969/announce'
         . '&tr=http://169.254.169.254:80/latest/meta-data/&xs=http://169.254.169.254/x.torrent&ws=http://127.0.0.1:9/seed&x.pe=10.0.0.1:6881';
$stored = function (string $hash) use ($db): string {
    $st = $db->prepare("SELECT magnet_link FROM whitelist WHERE info_hash = ?");
    $st->execute([$hash]);
    return (string)$st->fetchColumn();
};
whitelistAddHashes($db, $wlCfg, [parseMagnetOrHash(sprintf($hostile, SEC_HASHES[0]))], ['source' => 'web', 'ip' => '198.51.100.233', 'auto_meta' => false]);
$m = $stored(SEC_HASHES[0]);
check('a magnet from the public form keeps its hash and its name…', str_contains($m, 'xt=urn:btih:' . SEC_HASHES[0]) && str_contains($m, 'dn=Sec%20Test%20Name'), $m);
check('… and none of its trackers, web seeds, sources or peers', !str_contains($m, '127.0.0.1') && !str_contains($m, '10.0.0.5') && !str_contains($m, '169.254')
      && !preg_match('/[?&](xs|ws|x\.pe)=/', $m), $m);
check('… its announce is this tracker\'s own', $m === buildMagnet(SEC_HASHES[0], 'Sec Test Name', $wlCfg) && str_contains($m, 'tr=' . rawurlencode('udp://tracker.example.org:6969/announce')), $m);
whitelistAddHashes($db, $wlCfg, [parseMagnetOrHash(sprintf($hostile, SEC_HASHES[1]))], ['source' => 'api', 'ip' => '198.51.100.233', 'auto_meta' => false]);
check('a partner key\'s magnet (its submissions queue metadata by themselves) is rebuilt the same way', !str_contains($stored(SEC_HASHES[1]), '127.0.0.1')
      && !str_contains($stored(SEC_HASHES[1]), 'xs='), $stored(SEC_HASHES[1]));
whitelistAddHashes($db, $wlCfg, [parseMagnetOrHash(sprintf($hostile, SEC_HASHES[2]))], ['source' => 'admin', 'ip' => '198.51.100.233', 'auto_meta' => false]);
check('the panel\'s own addition stays as typed', $stored(SEC_HASHES[2]) === sprintf($hostile, SEC_HASHES[2]), $stored(SEC_HASHES[2]));
whitelistAddHashes($db, $wlCfg, [parseMagnetOrHash(SEC_HASHES[3])], ['source' => 'web', 'ip' => '198.51.100.233', 'auto_meta' => false]);
check('a bare hash stores no magnet at all, as before', $stored(SEC_HASHES[3]) === '');
$db->prepare("DELETE FROM whitelist WHERE info_hash IN (?, ?, ?, ?)")->execute([SEC_HASHES[0], SEC_HASHES[1], SEC_HASHES[2], SEC_HASHES[3]]);

/* ══ XSS-1: reCAPTCHA's two extras, only where a CAPTCHA can be drawn ═══════════════════════════ */
$rc = ['csp_mode' => 'enforce', 'recaptcha_enabled' => '1', 'captcha_provider' => 'recaptcha', 'recaptcha_site_key' => 'S', 'recaptcha_secret' => 'X'];
$rc3 = ['csp_mode' => 'enforce', 'recaptcha_enabled' => '1', 'captcha_provider' => 'recaptcha_v3', 'recaptcha_v3_site_key' => 'S', 'recaptcha_v3_secret' => 'X'];
$ts = ['csp_mode' => 'enforce', 'recaptcha_enabled' => '1', 'captcha_provider' => 'turnstile', 'turnstile_site_key' => 'S', 'turnstile_secret' => 'X'];
$scr = fn(string $pol): string => preg_match('/script-src([^;]*)/', $pol, $mm) ? $mm[1] : '';
$sty = fn(string $pol): string => preg_match('/style-src([^;]*)/', $pol, $mm) ? $mm[1] : '';
check('reCAPTCHA (v2 and v3) on a public page: style-src gains www.gstatic.com and script-src \'unsafe-eval\'',
      str_contains($sty(cspPolicy($rc, 'public')), 'https://www.gstatic.com') && str_contains($scr(cspPolicy($rc, 'public')), "'unsafe-eval'")
      && str_contains($sty(cspPolicy($rc3, 'public')), 'https://www.gstatic.com') && str_contains($scr(cspPolicy($rc3, 'public')), "'unsafe-eval'"));
check('… on the panel\'s dashboard (its deletion CAPTCHA) too', str_contains($scr(cspPolicy($rc, 'panel', 'admin')), "'unsafe-eval'"));
check('… but on no other panel page — Settings, Users, Traffic', !str_contains(cspPolicy($rc, 'panel', 'settings'), "'unsafe-eval'")
      && !str_contains(cspPolicy($rc, 'panel', 'admin-users'), "'unsafe-eval'") && !str_contains(cspPolicy($rc, 'panel', 'admin-traffic'), "'unsafe-eval'"));
check('… never with Turnstile, and never without a CAPTCHA', !str_contains(cspPolicy($ts, 'public'), "'unsafe-eval'") && !str_contains(cspPolicy($ts, 'public'), 'gstatic')
      && !str_contains(cspPolicy(['csp_mode' => 'enforce'], 'public'), "'unsafe-eval'"));
check('… and never \'unsafe-inline\' in script-src', !str_contains($scr(cspPolicy($rc, 'public')), "'unsafe-inline'") && !str_contains($scr(cspPolicy($rc, 'panel', 'admin')), "'unsafe-inline'"));

$cleanup();
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
