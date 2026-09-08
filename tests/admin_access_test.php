<?php
/**
 * Tests for the panel address (includes/auth.php), the CAPTCHA provider layer
 * (includes/functions.php) and the Settings search catalogue (includes/settings_catalog.php):
 *   php tests/admin_access_test.php
 * Pure functions of $cfg — no database, no network, safe to run anywhere.
 * Prints PASS/FAIL lines and exits non-zero on failure.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/settings_catalog.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

// ── 1. admin sign-in address ─────────────────────────────────────────────────
check('default sign-in path is admin', adminLoginPath([]) === 'admin');
check('empty falls back to admin', adminLoginPath(['admin_login_path' => '   ']) === 'admin');
check('custom path kept', adminLoginPath(['admin_login_path' => 'admin123yzxadminxxx']) === 'admin123yzxadminxxx');
check('path is lower-cased (index.php lower-cases the action too)', adminLoginPath(['admin_login_path' => 'SeCrEt_Panel']) === 'secret_panel');
// index.php sanitises ?action= with the same character class, so stripping (not truncating) keeps
// the stored value and the reachable URL identical
check('junk characters stripped', adminLoginPath(['admin_login_path' => 'pa/../th?x=1']) === 'pathx1', adminLoginPath(['admin_login_path' => 'pa/../th?x=1']));
check('over-long path falls back', adminLoginPath(['admin_login_path' => str_repeat('a', 65)]) === 'admin');
check('reserved public route refused', adminLoginPath(['admin_login_path' => 'stats']) === 'admin');
check('reserved panel route refused', adminLoginPath(['admin_login_path' => 'settings']) === 'admin');
check('404 template route refused', adminLoginPath(['admin_login_path' => 'notfound']) === 'admin');
check('custom flag off by default', adminLoginPathCustom([]) === false);
check('custom flag on when moved', adminLoginPathCustom(['admin_login_path' => 'zzz']) === true);
// every panel action and every public route must be un-shadowable
foreach (adminPanelActions() as $a) {
    if ($a === 'admin') continue;   // 'admin' IS the default sign-in path
    check("panel action '$a' cannot be used as a sign-in path", adminLoginPath(['admin_login_path' => $a]) === 'admin');
}

check('hidden behaviour defaults to home', adminHiddenBehavior([]) === 'home');
check('hidden behaviour: login', adminHiddenBehavior(['admin_hidden_behavior' => 'login']) === 'login');
check('hidden behaviour: 404', adminHiddenBehavior(['admin_hidden_behavior' => '404']) === '404');
check('hidden behaviour: garbage falls back to home', adminHiddenBehavior(['admin_hidden_behavior' => 'whatever']) === 'home');

// ── 2. CAPTCHA providers ─────────────────────────────────────────────────────
check('four providers known', captchaProviders() === ['recaptcha', 'recaptcha_v3', 'turnstile', 'hcaptcha']);
check('unknown provider falls back to reCAPTCHA v2', captchaProvider(['captcha_provider' => 'nope']) === 'recaptcha');
$keys = [
    'recaptcha'    => ['recaptcha_site_key', 'recaptcha_secret'],
    'recaptcha_v3' => ['recaptcha_v3_site_key', 'recaptcha_v3_secret'],
    'turnstile'    => ['turnstile_site_key', 'turnstile_secret'],
    'hcaptcha'     => ['hcaptcha_site_key', 'hcaptcha_secret'],
];
foreach ($keys as $provider => [$siteKey, $secretKey]) {
    $cfg = ['recaptcha_enabled' => '1', 'captcha_provider' => $provider, $siteKey => 'SITE', $secretKey => 'SECRET'];
    check("$provider: site key resolved", captchaSiteKey($cfg) === 'SITE');
    check("$provider: secret resolved", captchaSecret($cfg) === 'SECRET');
    check("$provider: configured", captchaConfigured($cfg) === true);
    check("$provider: not configured without a secret", captchaConfigured(['recaptcha_enabled' => '1', 'captcha_provider' => $provider, $siteKey => 'SITE']) === false);
    check("$provider: master switch off wins", captchaConfigured(['recaptcha_enabled' => '0'] + $cfg) === false);
    // head tags: the loader host + the readiness callback the modal waits for
    $tags = captchaHeadTags($cfg);
    check("$provider: head tags emitted", $tags !== '' && str_contains($tags, "CAPTCHA_PROVIDER = '$provider'"));
    // The widget providers are loaded with ?onload=onCaptchaApiLoad, so the callback must be defined
    // in the inline script ABOVE the loader or the "API ready" signal can be missed. v3 renders no
    // widget (grecaptcha.ready() is its signal) and deliberately gets no callback.
    if ($provider === 'recaptcha_v3') {
        check("$provider: no loader callback needed", !str_contains($tags, 'onCaptchaApiLoad'));
    } else {
        check("$provider: onCaptchaApiLoad defined before the loader",
            strpos($tags, 'window.onCaptchaApiLoad') !== false
            && strpos($tags, 'window.onCaptchaApiLoad') < strpos($tags, '<script src=')
            && str_contains($tags, 'onload=onCaptchaApiLoad'));
    }
}
check('hcaptcha loads js.hcaptcha.com with explicit render', str_contains(captchaHeadTags(['recaptcha_enabled' => '1', 'captcha_provider' => 'hcaptcha', 'hcaptcha_site_key' => 'S', 'hcaptcha_secret' => 'X']), 'https://js.hcaptcha.com/1/api.js?onload=onCaptchaApiLoad&render=explicit'));
check('turnstile loads challenges.cloudflare.com', str_contains(captchaHeadTags(['recaptcha_enabled' => '1', 'captcha_provider' => 'turnstile', 'turnstile_site_key' => 'S', 'turnstile_secret' => 'X']), 'challenges.cloudflare.com/turnstile/v0/api.js'));
check('v3 binds the loader to the site key', str_contains(captchaHeadTags(['recaptcha_enabled' => '1', 'captcha_provider' => 'recaptcha_v3', 'recaptcha_v3_site_key' => 'S1', 'recaptcha_v3_secret' => 'X']), 'api.js?render=S1'));
check('no head tags when CAPTCHA is off', captchaHeadTags(['recaptcha_enabled' => '0']) === '');

// Every provider host in the head tags must be allow-listed by the CSP, or the script silently
// never loads (the failure that made hCaptcha unusable on a default install). TWO policies are
// checked from here on, and they are not the same policy:
//   * the one in .htaccess, which is the Apache-only FALLBACK for csp_mode='off' and still names
//     all four providers because a static file cannot ask a setting, and
//   * cspPolicy() in includes/csp.php, which is what actually goes out — checked in section 6
//     below, per provider, and it names only the provider that is configured.
$htaccess = (string)@file_get_contents($root . '/.htaccess');
// `set` would REPLACE the header PHP already sent, which would silently overwrite the nonce policy
// with this permissive one and make includes/csp.php decorative on every Apache install.
check('the .htaccess policy defers to the one PHP sends (setifempty, not set)',
      (bool)preg_match('/Header\s+setifempty\s+Content-Security-Policy/', $htaccess)
      && !preg_match('/Header\s+set\s+Content-Security-Policy/', $htaccess));
foreach (['https://www.google.com', 'https://challenges.cloudflare.com', 'https://js.hcaptcha.com'] as $host) {
    check("CSP allows $host", str_contains($htaccess, $host));
}
check('CSP frame-src allows hcaptcha', (bool)preg_match('/frame-src[^;]*hcaptcha\.com/', $htaccess));
check('CSP connect-src allows hcaptcha + turnstile', (bool)preg_match('/connect-src[^;]*hcaptcha\.com/', $htaccess)
    && (bool)preg_match('/connect-src[^;]*challenges\.cloudflare\.com/', $htaccess));

// token field names of every provider are accepted
check('token: generic name', captchaTokenFromInput(['captcha_token' => 'A']) === 'A');
check('token: reCAPTCHA field', captchaTokenFromInput(['g-recaptcha-response' => 'B']) === 'B');
check('token: Turnstile field', captchaTokenFromInput(['cf-turnstile-response' => 'C']) === 'C');
check('token: hCaptcha field', captchaTokenFromInput(['h-captcha-response' => 'D']) === 'D');
check('token: none', captchaTokenFromInput(['x' => 'y']) === '');
check('empty token never verifies', verifyCaptcha('', ['captcha_provider' => 'hcaptcha', 'hcaptcha_secret' => 'S']) === false);
check('hCaptcha fails closed without a secret', verifyHcaptcha('tok', []) === false);
check('v3 notice only for v3', captchaNoticeHtml(['recaptcha_enabled' => '1', 'captcha_provider' => 'recaptcha_v3', 'recaptcha_v3_site_key' => 'S', 'recaptcha_v3_secret' => 'X']) !== ''
    && captchaNoticeHtml(['recaptcha_enabled' => '1', 'captcha_provider' => 'hcaptcha', 'hcaptcha_site_key' => 'S', 'hcaptcha_secret' => 'X']) === '');

// ── 3. Settings search catalogue ─────────────────────────────────────────────
$groups = settingsCatalogGroups();
$kw = settingsCatalogKeywords();
check('catalogue: groups have id/title/icon/keywords', (bool)$groups && count(array_filter($groups, fn($g) => !empty($g['id']) && !empty($g['title']) && !empty($g['icon']) && !empty($g['keywords']))) === count($groups));
$ids = array_column($groups, 'id');
check('catalogue: group ids unique', count($ids) === count(array_unique($ids)));
check('catalogue: keywords are non-empty strings', count(array_filter($kw, fn($v) => is_string($v) && trim($v) !== '')) === count($kw));
// the Settings template files every section under a group — an unknown id would leave a dead chip
$tpl = (string)@file_get_contents($root . '/templates/admin/settings.php');
preg_match_all('/class="settings-section" id="[a-z-]+" data-group="([a-z-]+)"/', $tpl, $m);
$used = array_unique($m[1]);
check('catalogue: every section group exists', count($used) > 0 && !array_diff($used, $ids), implode(',', array_diff($used, $ids)));
check('catalogue: every group is used by a section', !array_diff($ids, $used), implode(',', array_diff($ids, $used)));
// every keyword key must be reachable from the page: a name="" control or a data-setting="" block
preg_match_all('/name="([a-z0-9_]+)"/', $tpl, $mn);
preg_match_all('/data-setting="([a-z0-9_]+)"/', $tpl, $ms);
$onPage = array_unique(array_merge($mn[1], $ms[1]));
$dead = array_diff(array_keys($kw), $onPage);
check('catalogue: no keyword entry for a setting that is not on the page', empty($dead), implode(', ', $dead));
$noKeywords = array_diff($onPage, array_keys($kw), ['viewport']);
check('catalogue: every setting on the page has keywords', empty($noKeywords), implode(', ', $noKeywords));

/* ── 4. one CIDR matcher, one list parser ─────────────────────────────────── */
//
// `trusted_proxy_ips` was compared with in_array(), so a CIDR typed into that box matched nothing
// and every visitor kept being counted as the proxy — the feature could not be configured for
// Cloudflare at all, which publishes ranges and no single addresses. Three near-identical matchers
// existed elsewhere in the tree and none of them was reachable from includes/functions.php; one of
// them (includes/api_auth.php) also indexed past the end of a four-byte address on a prefix like
// /999. The table below is the contract of the single matcher that replaced all of it.

$cidrCases = [
    // [address, entry, matches?]
    ['10.0.0.5',              '10.0.0.0/8',           true],
    ['11.0.0.5',              '10.0.0.0/8',           false],
    ['173.245.48.77',         '173.245.48.0/20',      true],
    ['173.245.64.1',          '173.245.48.0/20',      false],
    ['192.168.1.130',         '192.168.1.128/25',     true],    // a prefix that ends mid-byte
    ['192.168.1.127',         '192.168.1.128/25',     false],
    ['1.2.3.4',               '1.2.3.4',              true],     // a bare address is a single host
    ['1.2.3.5',               '1.2.3.4',              false],
    ['1.2.3.4',               '1.2.3.4/32',           true],
    ['203.0.113.9',           '0.0.0.0/0',            true],      // /0 contains its whole family…
    ['::1',                   '0.0.0.0/0',            false],     // …and only its own family
    ['::1',                   '::/0',                 true],
    ['10.0.0.5',              '::/0',                 false],
    ['2400:cb00:1234::5',     '2400:cb00::/32',       true],
    ['2400:cb01::5',          '2400:cb00::/32',       false],
    ['2400:cb00::5',          '10.0.0.0/8',           false],     // a v6 peer never meets a v4 entry
    ['10.0.0.5',              '2400:cb00::/32',       false],     // nor the reverse
    ['::ffff:173.245.48.77',  '173.245.48.0/20',      true],      // v4-in-v6 unmapped on the address…
    ['173.245.48.77',         '::ffff:173.245.48.77', true],      // …and on a bare entry
    ['10.0.0.5',              '10.0.0.0/33',          false],     // out of range: matches NOTHING
    ['2400:cb00::5',          '2400:cb00::/129',      false],
    ['10.0.0.5',              '10.0.0.0/-1',          false],
    ['10.0.0.5',              '10.0.0.0/',            false],
    ['10.0.0.5',              '10.0.0.0/8a',          false],
    ['10.0.0.5',              'not-an-ip',            false],
    ['10.0.0.5',              '',                     false],
    ['not-an-ip',             '10.0.0.0/8',           false],
];
foreach ($cidrCases as [$ip, $entry, $want]) {
    check(sprintf('%-22s %s %s', $ip, $want ? 'is inside' : 'is NOT inside', $entry === '' ? '(empty)' : $entry),
          ipMatchesCidr($ip, $entry) === $want);
}
// The /33 case is the api_auth.php bug: an unchecked (int) cast then ord($bin[124]) on four bytes.
check('an out-of-range prefix is refused while parsing, so nothing reads past the address',
      ipCidrPack('10.0.0.0/33') === null && ipCidrValid('10.0.0.0/33') === false && ipCidrValid('10.0.0.0/8') === true);
check('an empty list trusts nothing', ipInCidrList('10.0.0.5', []) === false);
check('first match wins in a list', ipInCidrList('10.0.0.5', ['1.2.3.4', '10.0.0.0/8']) === true);

check('the list parser splits on commas, spaces, semicolons and new lines',
      ipParseList("10.0.0.1, 10.0.0.2\n10.0.0.3;10.0.0.4  10.0.0.5")
      === ['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4', '10.0.0.5']);
check('… and drops blanks and duplicates', ipParseList(' , 1.2.3.4 ,,1.2.3.4, ') === ['1.2.3.4']);
$many = [];
for ($i = 0; $i < 400; $i++) $many[] = '10.0.' . intdiv($i, 256) . '.' . ($i % 256);
check('… and is capped so one pasted file cannot make every request walk it',
      count(ipParseList(implode(',', $many), 5)) === 5);

// The width rule lives in the RUNTIME, not only in save_settings: `trusted_proxy_ips` had no
// validation at all, so an installation can already hold a CIDR that was inert and would become
// live on the first request after the upgrade — before anybody can open Settings.
check('an over-wide v4 entry is not honoured at run time', trustedProxyList(['trusted_proxy_ips' => '0.0.0.0/0']) === []);
check('… nor a /7', trustedProxyList(['trusted_proxy_ips' => '10.0.0.0/7']) === []);
check('… while a /8 is', trustedProxyList(['trusted_proxy_ips' => '10.0.0.0/8']) === ['10.0.0.0/8']);
check('an over-wide v6 entry is not honoured', trustedProxyList(['trusted_proxy_ips' => '::/0']) === []);
check('… nor a /15', trustedProxyList(['trusted_proxy_ips' => '2400:cb00::/15']) === []);
check('… while Cloudflare\'s widest v6 block is', trustedProxyList(['trusted_proxy_ips' => '2400:cb00::/32']) === ['2400:cb00::/32']);
check('a malformed entry is dropped rather than honoured', trustedProxyList(['trusted_proxy_ips' => 'nonsense, 1.2.3.4']) === ['1.2.3.4']);
check('and the panel can name what was dropped instead of dropping it in silence',
      trustedProxyRejected(['trusted_proxy_ips' => '0.0.0.0/0, 1.2.3.4, junk']) === ['0.0.0.0/0', 'junk']);

/* ── 5. getClientIp() through the matcher ─────────────────────────────────── */

$srvSaved = $_SERVER;
$clientIp = function (array $server, array $proxyCfg): string {
    $_SERVER = $server;
    return getClientIp($proxyCfg);
};
$cfExact = ['trusted_proxy_ips' => '10.0.0.1', 'client_ip_header' => 'X-Forwarded-For'];
$cfRange = ['trusted_proxy_ips' => '173.245.48.0/20', 'client_ip_header' => 'X-Forwarded-For'];
$cfV6    = ['trusted_proxy_ips' => '2400:cb00::/32', 'client_ip_header' => 'X-Forwarded-For'];

check('an exact proxy address is still trusted, exactly as before',
      $clientIp(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], $cfExact) === '203.0.113.9');
check('a proxy inside a trusted range is trusted now',
      $clientIp(['REMOTE_ADDR' => '173.245.48.77', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], $cfRange) === '203.0.113.9');
check('a peer outside the range is the client, and its header is ignored',
      $clientIp(['REMOTE_ADDR' => '173.245.64.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], $cfRange) === '173.245.64.1');
check('an IPv4-mapped peer matches the v4 range it is really in',
      $clientIp(['REMOTE_ADDR' => '::ffff:173.245.48.77', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], $cfRange) === '203.0.113.9');
check('a v6 proxy range works too',
      $clientIp(['REMOTE_ADDR' => '2400:cb00:1234::5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], $cfV6) === '203.0.113.9');
check('a v4 entry never trusts a v6 peer',
      $clientIp(['REMOTE_ADDR' => '2400:cb00::5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], $cfRange) === '2400:cb00::5');
check('the right-to-left walk skips a hop inside a trusted RANGE',
      $clientIp(['REMOTE_ADDR' => '173.245.48.77', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 173.245.48.5'], $cfRange) === '203.0.113.9');
check('an XFF whose every hop is trusted falls back to the peer',
      $clientIp(['REMOTE_ADDR' => '173.245.48.77', 'HTTP_X_FORWARDED_FOR' => '173.245.48.5, 173.245.48.6'], $cfRange) === '173.245.48.77');
check('a list written one per line works (explode on "," alone did not)',
      $clientIp(['REMOTE_ADDR' => '173.245.48.77', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
               ['trusted_proxy_ips' => "1.2.3.4\n173.245.48.0/20", 'client_ip_header' => 'X-Forwarded-For']) === '203.0.113.9');
check('garbage in the box never matches and never throws',
      $clientIp(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
               ['trusted_proxy_ips' => '10.0.0.0/33, 10.0.0.0/, not-an-ip', 'client_ip_header' => 'X-Forwarded-For']) === '10.0.0.5');
check('an empty trusted list ignores the header entirely',
      $clientIp(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
               ['trusted_proxy_ips' => '', 'client_ip_header' => 'X-Forwarded-For']) === '10.0.0.5');
check('an empty header name ignores it too, whatever the list says',
      $clientIp(['REMOTE_ADDR' => '173.245.48.77', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
               ['trusted_proxy_ips' => '173.245.48.0/20', 'client_ip_header' => '']) === '173.245.48.77');
// The one that keeps the upgrade safe: `0.0.0.0/0` typed into that box before CIDR support existed
// must not become a client-IP-spoofing bypass on the first request after the deploy.
check('"0.0.0.0/0" in the box does NOT make everybody a trusted proxy',
      $clientIp(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'],
               ['trusted_proxy_ips' => '0.0.0.0/0', 'client_ip_header' => 'X-Forwarded-For']) === '198.51.100.7');

/* ── 6. was this request HTTPS, and what did the cookies get ──────────────── */
//
// Behind nginx terminating TLS $_SERVER['HTTPS'] is unset unless the vhost passes
// `fastcgi_param HTTPS $https if_not_empty`, so the five copies of
// `!empty($_SERVER['HTTPS'])` this replaced computed cookie_secure = false on a site served
// entirely over HTTPS. Everything below is asserted against a crafted $_SERVER.

$isHttps = function (array $server, array $c = []): bool { $_SERVER = $server; return requestIsHttps($c); };
$signalOf = function (array $server, array $c = []): string { $_SERVER = $server; return requestHttpsSignal($c); };
$trustedProxy = ['trusted_proxy_ips' => '10.0.0.0/8', 'client_proto_header' => 'X-Forwarded-Proto'];

check('an empty $_SERVER is not HTTPS', $isHttps([]) === false);
check('HTTPS=on is', $isHttps(['HTTPS' => 'on']) === true);
check('HTTPS=off is not', $isHttps(['HTTPS' => 'off']) === false);
check('… whatever its case', $isHttps(['HTTPS' => 'OFF']) === false);
check('REQUEST_SCHEME=https is', $isHttps(['REQUEST_SCHEME' => 'https']) === true);
check('REQUEST_SCHEME=http is not', $isHttps(['REQUEST_SCHEME' => 'http']) === false);
// Deliberately dropped and must stay dropped: it is the one signal that could claim HTTPS for a
// plain-HTTP request, and a false positive here locks the operator out of their own panel.
check('SERVER_PORT 443 alone does NOT count as HTTPS', $isHttps(['SERVER_PORT' => '443']) === false);
check('a proto header from an UNTRUSTED peer is ignored',
      $isHttps(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'], $trustedProxy) === false);
check('… and from a trusted one it is honoured',
      $isHttps(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https'], $trustedProxy) === true);
check('… reported as the proxy header rather than as local TLS',
      $signalOf(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https'], $trustedProxy) === 'proxy_header');
check('a proto header can never DOWNGRADE a connection that really is TLS',
      $isHttps(['HTTPS' => 'on', 'REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'http'], $trustedProxy) === true);
check('a proto list is read left-most, like a scheme and unlike XFF',
      $isHttps(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https,http'], $trustedProxy) === true
      && $isHttps(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'http,https'], $trustedProxy) === false);
check('X-Forwarded-SSL is honoured from a trusted peer only',
      $isHttps(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_SSL' => 'on'], $trustedProxy) === true
      && $isHttps(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_SSL' => 'on'], $trustedProxy) === false);
check('RFC 7239 Forwarded is read, first element only',
      $isHttps(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_FORWARDED' => 'for=203.0.113.9;proto=https'], $trustedProxy) === true
      && $isHttps(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_FORWARDED' => 'for=203.0.113.9;proto=http, for=10.1.2.3;proto=https'], $trustedProxy) === false);
check('an empty header name switches the whole proxy path off',
      $isHttps(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https'],
               ['trusted_proxy_ips' => '10.0.0.0/8', 'client_proto_header' => '']) === false);
check('an over-wide trusted entry cannot enable the header path either',
      $isHttps(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'],
               ['trusted_proxy_ips' => '0.0.0.0/0', 'client_proto_header' => 'X-Forwarded-Proto']) === false);

// ONE policy, and every cookie the site sets reads it.
foreach ([['TLS', ['HTTPS' => 'on'], true], ['plain', [], false]] as [$label, $srv, $secure]) {
    $_SERVER = $srv;
    $sess = sessionCookieParams([]);
    $rem  = cookieBaseParams([], ['expires' => 123]);
    $lng  = cookieBaseParams([], ['httponly' => false]);
    check("over $label transport the three cookies agree about Secure",
          $sess['cookie_secure'] === $secure && $rem['secure'] === $secure && $lng['secure'] === $secure);
}
$_SERVER = [];
check('mode "always" is Secure even over plain HTTP', cookieSecureFlag(['cookie_secure_mode' => 'always']) === true);
check('mode "never" is not, and a garbage mode falls back to auto',
      cookieSecureFlag(['cookie_secure_mode' => 'never']) === false
      && cookieSecureFlag(['cookie_secure_mode' => 'whatever']) === false
      && cookieSecureMode(['cookie_secure_mode' => 'whatever']) === 'auto');
$_SERVER = ['HTTPS' => 'on'];
check('… and "never" wins over a genuinely TLS request', cookieSecureFlag(['cookie_secure_mode' => 'never']) === false);
$lang = cookieBaseParams([], ['httponly' => false]);
check('the language cookie stays readable to the switcher and stays a session cookie',
      $lang['httponly'] === false && !array_key_exists('expires', $lang) && $lang['path'] === '/' && $lang['samesite'] === 'Lax');
$issue = cookieBaseParams([], ['expires' => 2000000000]);
$clear = cookieBaseParams([], ['expires' => 1]);
check('the remember-me DELETE carries the same attributes as the ISSUE, differing only in the expiry',
      array_diff_key($issue, ['expires' => 1]) === array_diff_key($clear, ['expires' => 1]) && $issue['expires'] !== $clear['expires']);

/* ── 7. HSTS ──────────────────────────────────────────────────────────────── */

$hstsOn = ['hsts_enabled' => '1'];
$_SERVER = ['HTTPS' => 'on'];
check('nothing is sent while the switch is off', hstsHeaderValue([]) === '' && hstsHeaderValue(['hsts_enabled' => '0']) === '');
check('the shipped age is one day, not one year', hstsHeaderValue($hstsOn) === 'max-age=86400');
check('includeSubDomains is appended only when asked',
      hstsHeaderValue($hstsOn + ['hsts_include_subdomains' => '1']) === 'max-age=86400; includeSubDomains');
check('preload needs BOTH subdomains and a full year, or the token is not printed',
      hstsHeaderValue($hstsOn + ['hsts_preload' => '1']) === 'max-age=86400'
      && hstsHeaderValue($hstsOn + ['hsts_preload' => '1', 'hsts_include_subdomains' => '1']) === 'max-age=86400; includeSubDomains'
      && hstsHeaderValue($hstsOn + ['hsts_preload' => '1', 'hsts_include_subdomains' => '1', 'hsts_max_age' => '31536000'])
         === 'max-age=31536000; includeSubDomains; preload');
check('max-age 0 is the RETRACTION and must survive, not read as "off"',
      hstsHeaderValue($hstsOn + ['hsts_max_age' => '0']) === 'max-age=0');
check('an absurd age falls back rather than being published',
      hstsHeaderValue($hstsOn + ['hsts_max_age' => '99999999999']) === 'max-age=86400');
// The single most important assertion in the change: a hand-edited row cannot pin a hostname for a
// browser that reached this site over plain HTTP.
$_SERVER = [];
check('NOTHING is sent over a plain-HTTP request, even with the row switched on',
      hstsHeaderValue($hstsOn + ['hsts_max_age' => '31536000', 'hsts_include_subdomains' => '1', 'hsts_preload' => '1']) === '');
$_SERVER = $srvSaved;

// The one matcher, actually used by the one place that had its own broken copy.
$apiAuthSrc = (string)file_get_contents($root . '/includes/api_auth.php');
check('the API never-ban list goes through the shared matcher now',
      str_contains($apiAuthSrc, 'ipInCidrList($ip, $list)') && !str_contains($apiAuthSrc, 'ord($bin[$bytes])'));
// The empty box means "exempt nobody", including the server itself: where a second front end talks
// to nginx over loopback, REMOTE_ADDR and SERVER_ADDR are the same address for every visitor, so an
// operator who empties this box on purpose must not thereby exempt the entire internet.
// apiIpExempt() lives here, and this file requires only functions/auth/settings_catalog at the top:
// without this line the suite died with "Call to undefined function apiIpExempt()" partway through,
// so everything after it — including the whole catalogue-vs-page section — was never reached.
// api_auth.php is constants and functions only; including it runs nothing.
require_once $root . '/includes/api_auth.php';
$srvSaved2 = $_SERVER;
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
check('an empty never-ban list exempts nobody, not even the server itself',
      apiIpExempt('127.0.0.1', ['api_ban_exempt_ips' => '']) === false);
check('… and with a list, the server address is still exempt',
      apiIpExempt('127.0.0.1', ['api_ban_exempt_ips' => '10.9.9.9']) === true);
check('… and a listed range still matches',
      apiIpExempt('10.9.9.7', ['api_ban_exempt_ips' => '10.9.9.0/24']) === true);
check('… while an address outside it does not',
      apiIpExempt('10.9.8.7', ['api_ban_exempt_ips' => '10.9.9.0/24']) === false);
$_SERVER = $srvSaved2;
$fnSrc = (string)file_get_contents($root . '/includes/functions.php');
check('and getClientIp() parses the box with the shared parser rather than explode(",")',
      str_contains($fnSrc, '$trusted = trustedProxyList($cfg);')
      && !str_contains($fnSrc, "explode(',', \$cfg['trusted_proxy_ips']"));

/* ── re-confirming the password happens in exactly one place ──────────────── */
//
// The session gate keeps a stranger out of admin/*. It does nothing about somebody who already has a
// session -- a borrowed laptop, an unlocked screen, a stolen cookie -- and that is precisely the case
// the password prompt on every dangerous action exists for. It used to be fourteen separate inline
// password_verify() calls with no counter between them, so that person could guess for ever.
//
// This checks the PROPERTY rather than the fix: no endpoint may verify the admin password by itself.
// An endpoint written next year gets the throttle because there is nowhere else to get the check.

$offenders = [];
foreach (array_merge(glob($root . '/api/*.php') ?: [], glob($root . '/api/*/*.php') ?: []) as $f) {
    if (preg_match('/password_verify[^;]*ADMIN_PASSWORD_HASH/', (string)file_get_contents($f))) {
        $offenders[] = str_replace($root . '/', '', $f);
    }
}
check('no endpoint checks the admin password on its own — they all go through adminReauth()',
      $offenders === [], implode(', ', $offenders));

$auth = (string)file_get_contents($root . '/includes/auth.php');
check('a wrong confirmation costs progressively more time, starting at the first one',
      str_contains($auth, 'function adminReauthDelayUs'));
check('… and enough of them destroy the session rather than just refusing the action',
      preg_match('/function adminReauth\(.*?logout\(\);/s', $auth) === 1);
check('… and they also count against the sign-in lockout, so this is not a way around it',
      preg_match('/function adminReauth\(.*?recordLoginFailure\(/s', $auth) === 1);
check('the one-line helper exits rather than returning a value a caller could ignore',
      preg_match('/function requireAdminReauth\(.*?jsonResponse\(/s', $auth) === 1);

// A count, so that deleting call sites cannot quietly make the rule above vacuous.
$gated = 0;
foreach (glob($root . '/api/admin/*.php') ?: [] as $f) {
    if (str_contains((string)file_get_contents($f), 'dminReauth(')) $gated++;
}
check('and the gate is actually used by the dangerous endpoints', $gated >= 12, (string)$gated);

/* ── the settings that DEFINE the dangerous actions are behind the same gate ─ */
//
// Running a backup, switching the tracker mode, applying a firewall limit: each asks for the
// password. The setting that says WHICH command those actions run used to save on the session cookie
// alone, and an admin-group account holds such a cookie — so the prompt guarded the trigger while the
// gun was reloaded around it. save_settings.php now keeps a list of those settings ($reauthKeys) and
// requires the owner's password when any of them changes. These checks read the list back out of the
// source rather than trusting a count, so a key that is executed but falls off the list is a failure
// here and not a surprise later.

$sv = (string)file_get_contents($root . '/api/admin/save_settings.php');
preg_match('/\$allowed\s*=\s*\[(.*?)\];/s', $sv, $ma);
preg_match_all("/'([a-z0-9_]+)'/", $ma[1] ?? '', $mk);
$allowKeys = $mk[1];
preg_match('/\$reauthKeys\s*=\s*\[(.*?)\];/s', $sv, $mr);
preg_match_all("/'([a-z0-9_]+)'\s*=>/", $mr[1] ?? '', $mk);
$reauthKeys = $mk[1];
check('save_settings names the settings that need the password again', count($reauthKeys) >= 10, (string)count($reauthKeys));
check('every one of them is a setting the endpoint accepts at all',
      array_diff($reauthKeys, $allowKeys) === [], implode(', ', array_diff($reauthKeys, $allowKeys)));
// Every helper command in the allow-list, by name: a new *_cmd registered without joining the list
// is exactly the omission this exists to catch.
$cmdKeys = array_values(array_filter($allowKeys, fn($k) => str_ends_with($k, '_cmd')));
check('every *_cmd the endpoint accepts is on the list', count($cmdKeys) >= 6 && array_diff($cmdKeys, $reauthKeys) === [],
      implode(', ', array_diff($cmdKeys, $reauthKeys)));
// …and the ones whose name does not say so: the interpreter that is exec()ed, the script the backup
// helper runs, what systemctl is told, and the two that decide whose address the panel believes.
// …and the two whose consequence lives outside this server: Secure=Always can lock every account
// out of the panel until somebody runs SQL, and the preload token is compiled into browser releases.
foreach (['tuner_python', 'backup_script_path', 'opentracker_service_name', 'opentracker_restart_use_sudo',
          'trusted_proxy_ips', 'client_ip_header', 'hmac_secret',
          'cookie_secure_mode', 'hsts_preload'] as $k) {
    check("$k is on the list", in_array($k, $reauthKeys, true));
}
// Every refusal added for the transport settings must fire on a CHANGE and be judged against the
// configuration this save would LEAVE BEHIND. The page posts every named control on every save, so
// a guard written against the value alone makes one bad state unsavable for the whole page; and $cfg
// is not refreshed until setSettings(), so a guard written against $cfg refuses the very save that
// would fix detection and switch the feature on in one click.
// These two are SHAPE checks and they know it: the property that matters — post all six unchanged
// and the save succeeds; post a dangerous one over plain HTTP and it is refused — is exercised for
// real over HTTP in deploy/smoke_admin.py, because save_settings.php is a router fragment that
// exits through jsonResponse() and nothing here can include it. What is left here is the one thing
// a grep CAN prove and the smoke cannot: that no guard asks the question of the OLD configuration.
check('no transport guard judges the save by the configuration it is replacing',
      !str_contains($sv, 'requestIsHttps($cfg)'));
check('every transport refusal is gated on the value having changed',
      preg_match_all('/if \(\$transportChanged\(/', $sv) === preg_match_all('/\$transportChanged\(/', $sv));
// The runtime is the boundary for the width rule; the 400 here is only the message. An installation
// that already holds an over-wide entry must still be able to save every other field on the page.
check('an untouched trusted-proxy box is never re-validated, so a stored entry cannot block the page',
      str_contains($sv, "if (\$data['trusted_proxy_ips'] !== (string)(\$cfg['trusted_proxy_ips'] ?? '')) {"));
check('the gate compares against the stored value and goes through requireAdminReauth()',
      preg_match('/foreach \(\$reauthKeys as \$k => \$fallback\).*?\$cfg\[\$k\] \?\? \$fallback.*?requireAdminReauth\(\$confirmPassword, \$cfg\)/s', $sv) === 1);
check('a save without the password is refused with a flag, not silently applied',
      preg_match('/\$reauthChanged\).*?\'reauth_required\' => true.*?, 403\)/s', $sv) === 1);
// The page must not keep its own copy of the list — it acts on the reply.
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
check('the settings page opens the password modal on that flag', str_contains($tpl, 'json.reauth_required'));
check('… with a body that says why', str_contains($tpl, "settings.confirm_body_exec") && str_contains($tpl, 'id="settings-confirm-body"'));

// attemptLogin() granted a session on the password alone, which two-factor authentication made
// wrong — the sign-in path was split into adminCredentialsValid() + adminGrantSession() and nothing
// called it since. A function that hands out a session and has no caller is a function waiting for
// one, so it is gone; this makes sure it stays gone.
$callers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php' || realpath($f->getPathname()) === __FILE__) continue;   // this file names it
    if (preg_match('/\battemptLogin\s*\(/', (string)file_get_contents($f->getPathname()))) {
        $callers[] = str_replace($root . DIRECTORY_SEPARATOR, '', $f->getPathname());
    }
}
check('attemptLogin() no longer exists and nothing calls it', $callers === [], implode(', ', $callers));

/* == panel permissions: the moderator boundary ============================= */
//
// The panel had no permissions at all until 1.21.0 — every endpoint was gated by "is there a session"
// — so these tests are about the boundary itself rather than about any single endpoint.

require_once $root . '/includes/users.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/schema.php';
// This suite runs without a database for its earlier checks; the boundary checks need one, and the
// live schema is the only place the seeded moderator group actually exists.
$db = getDb();
$cfg = array_merge($cfg, getSettings($db, true));
ensureSchema($db, $cfg);

$allPerms = userPermissionList();
$panelIds = array_values(array_filter(array_keys($allPerms), 'userIsPanelPermission'));
check('panel permissions exist at all', count($panelIds) >= 10, count($panelIds) . ' found');

// A typo in the endpoint map denies an endpoint to every moderator FOREVER, silently — there is no
// error, the permission simply never matches. So every id the map names must be a real one.
$apiSrc = (string)file_get_contents($root . '/api.php');
preg_match_all("/'(admin\/[a-z0-9_]+)'\s*=>\s*'(panel\.[a-z.]+)'/", $apiSrc, $mm, PREG_SET_ORDER);
check('the endpoint map was found in api.php', count($mm) > 20, count($mm) . ' entries');
$badIds = [];
foreach ($mm as $row) if (!isset($allPerms[$row[2]])) $badIds[] = $row[1] . ' => ' . $row[2];
check('every permission the endpoint map names is registered', $badIds === [], implode(', ', $badIds));

// And every endpoint it names must be routed, or the entry is decoration.
preg_match_all("/'(admin\/[a-z0-9_]+)'\s*=>\s*'api\/admin\//", $apiSrc, $rr);
$routed = array_flip($rr[1]);
$badEp = [];
foreach ($mm as $row) if (!isset($routed[$row[1]])) $badEp[] = $row[1];
check('every endpoint the map names is actually routed', $badEp === [], implode(', ', $badEp));

// Default deny: the dangerous endpoints must NOT be in the map at any permission.
$mapped = [];
foreach ($mm as $row) $mapped[$row[1]] = $row[2];
$mustBeOwnerOnly = ['admin/save_settings', 'admin/change_password', 'admin/account_email', 'admin/twofa',
                    'admin/group_save', 'admin/group_delete', 'admin/user_delete', 'admin/backup_action',
                    'admin/backup_download', 'admin/restart_tracker', 'admin/net_apply', 'admin/sysctl_apply',
                    'admin/ot_apply', 'admin/api_client_create', 'admin/bulk_send', 'admin/tracker_mode',
                    'admin/whitelist_regenerate', 'admin/delete_all', 'admin/delete_permanently'];
$leaked = array_values(array_intersect(array_keys($mapped), $mustBeOwnerOnly));
check('nothing that changes the machine or the owner credentials is grantable',
      $leaked === [], implode(', ', $leaked));

// The legacy fallback must never open the panel: with accounts off there is nobody to be a moderator,
// and the fallback's final `return true` would otherwise hand out every panel id.
$leakedLegacy = array_values(array_filter($panelIds, 'userLegacyDefault'));
check('userLegacyDefault never grants a panel permission', $leakedLegacy === [], implode(', ', $leakedLegacy));

// panelCan(), against real sessions.
$_SESSION['loggedin'] = true;
unset($_SESSION['admin_via_user']);
check('the owner session passes every panel check',
      panelCan($db, $cfg, 'panel.access') && panelCan($db, $cfg, 'panel.users.groups')
      && panelCan($db, $cfg, 'panel.no.such.permission'));

$modGroup = userGroupBySlug($db, 'moderator');
check('the moderator group is seeded and is a system group',
      $modGroup !== null && (int)$modGroup['is_system'] === 1);
$modPerms = $modGroup ? userGroupPermissions($modGroup['permissions']) : [];
check('it can open the panel and work the report queue',
      isset($modPerms['panel.access'], $modPerms['panel.reports.status'], $modPerms['panel.whitelist.content']));
check('it cannot edit users, change groups or reach Traffic or Backups by default',
      !isset($modPerms['panel.users.edit']) && !isset($modPerms['panel.users.groups'])
      && !isset($modPerms['panel.traffic.view']) && !isset($modPerms['panel.backups.view']));
check('no permission exists for Settings at all, so it cannot be granted',
      !array_key_exists('panel.settings', $allPerms)
      && !count(array_filter(array_keys($allPerms), fn($k) => str_contains($k, 'settings'))));

// A real moderator session: granted exactly what the group holds and nothing else.
$db->prepare("INSERT INTO users (username, email, pass_hash, status, email_verified)
              VALUES ('modprobe', 'modprobe@example.org', ?, 'active', 1)
              ON DUPLICATE KEY UPDATE status = 'active'")->execute([password_hash('x', PASSWORD_DEFAULT)]);
$modUid = (int)$db->query("SELECT id FROM users WHERE username = 'modprobe'")->fetchColumn();
$db->prepare("INSERT IGNORE INTO user_group_members (user_id, group_id, granted_by, note) VALUES (?, ?, 'test', 'boundary')")
   ->execute([$modUid, (int)$modGroup['id']]);
$_SESSION['admin_via_user'] = $modUid;
check('a moderator session may open the panel', panelCan($db, $cfg, 'panel.access'));
check('… and may act on reports', panelCan($db, $cfg, 'panel.reports.status'));
check('… but may NOT reach an ungranted area', !panelCan($db, $cfg, 'panel.users.edit'));
check('… and may NOT pass a check for a permission that does not exist',
      !panelCan($db, $cfg, 'panel.owner.__never__'));
check('… so Settings is closed to them', !adminPageAllowed($db, $cfg, 'settings'));
check('… while Reports is open', adminPageAllowed($db, $cfg, 'admin'));
check('the nav shows only what they may open',
      count(adminNavItemsFor($db, $cfg)) > 0
      && count(adminNavItemsFor($db, $cfg)) < count(adminNavItems()));

$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$modUid]);
$db->prepare("DELETE FROM users WHERE id = ?")->execute([$modUid]);
unset($_SESSION['admin_via_user']);

// ── the report link, which is the one field a stranger fully controls ────────
//
// It was the only report field that skipped sanitize(), and its only gate was
// filter_var(FILTER_VALIDATE_URL) -- which accepts `javascript://x/%0aalert(1)` outright, and
// accepts a double quote inside the URL. The panel rendered it as href="${esc(r.link)}", and esc()
// is innerHTML serialisation: it escapes & < > and LEAVES QUOTES ALONE. A quote closed the
// attribute, in the owner's session, from the public form.
//
// Both halves are checked because neither alone is sufficient: richtextSafeUrl kills the
// `javascript:` class, and only escAttr() at the sink stops a quote inside an otherwise valid
// https URL (both validators accept that one).
require_once $root . '/includes/richtext.php';
check('a javascript: URL is refused by the validator the report form now uses',
      richtextSafeUrl('javascript://x/%0aalert(1)') === null);
check('… and so is one with a control character in the scheme',
      richtextSafeUrl("java\tscript:alert(1)") === null);
check('an ordinary https link still passes',
      richtextSafeUrl('https://example.org/torrent/1') === 'https://example.org/torrent/1');
$sr = (string)file_get_contents($root . '/api/submit_report.php');
check('the report form validates the link rather than merely parsing it',
      str_contains($sr, 'richtextSafeUrl($link)'));
check('… and no longer trusts FILTER_VALIDATE_URL for it',
      !str_contains($sr, 'filter_var($link, FILTER_VALIDATE_URL)'));
$aj = (string)file_get_contents($root . '/assets/js/admin.js');
check('the panel puts the link in the attribute with escAttr(), not esc()',
      !str_contains($aj, 'href="${esc(r.link)}"') && substr_count($aj, 'href="${escAttr(r.link)}"') === 2,
      (string)substr_count($aj, 'href="${escAttr(r.link)}"'));
$pj = (string)file_get_contents($root . '/assets/js/app.js');
check('the public status page escapes the quote too', str_contains($pj, 'replace(/"/g,'));

/* ── 6. Content-Security-Policy (includes/csp.php) ────────────────────────── */
//
// The policy used to exist only in .htaccess — a file nginx never reads — so production served no
// policy at all unless somebody had hand-copied the line into the server block. It is built in PHP
// now, which is also the only place a per-request nonce can come from: a static `add_header` cannot
// mint one. What follows is the contract of that move.

/**
 * A file's source with every PHP comment blanked out (line count preserved).
 *
 * Every source-shape check below needs this, and the first draft of them proved why: they all
 * failed on the PROSE that explains them. includes/csp.php documents `<script<?= nonceAttr() ?>>`,
 * includes/functions.php and includes/lang.php both discuss "an inline <script>", and
 * includes/richtext.php mentions `<kbd onclick="x">` while explaining what it escapes. A grep that
 * cannot tell code from a comment about the code is the grep somebody widens until it means
 * nothing.
 */
function srcNoComments(string $file): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($file)) as $tok) {
        if (!is_array($tok)) { $out .= $tok; continue; }
        $out .= in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)
            ? str_repeat("\n", substr_count($tok[1], "\n"))
            : $tok[1];
    }
    return $out;
}

check('mode off sends nothing at all',
      cspPolicy(['csp_mode' => 'off'], 'public') === '' && cspHeaderName(['csp_mode' => 'off']) === '');
check('report-only is the shipped default', cspHeaderName([]) === 'Content-Security-Policy-Report-Only');
check('enforce sends the real header name', cspHeaderName(['csp_mode' => 'enforce']) === 'Content-Security-Policy');
// A garbled row must never be read as the mode that can break every page.
check('an unknown mode reads as report, never as enforce', cspMode(['csp_mode' => 'whatever']) === 'report');

$pub = cspPolicy([], 'public');
$pan = cspPolicy([], 'panel');
$apiPol = cspPolicy([], 'api');
check('the nonce is in script-src', str_contains($pub, "'nonce-" . cspNonce() . "'"));
// One value per request, static in cspNonce(). A nonce that differed between the header and the
// page would block every inline block on the site — the failure mode that looks like "JavaScript
// is broken" and reads in the console as a policy the operator did write.
check('one nonce per request, not one per call', substr_count($pub . $pan, "'nonce-" . cspNonce() . "'") === 2);
// 'unsafe-inline' alongside a nonce is 'unsafe-inline' in every browser too old to know what a
// nonce is, and a promise the author did not mean in every browser that does.
check('script-src carries neither unsafe-inline nor unsafe-eval',
      !preg_match("/script-src[^;]*'unsafe-inline'/", $pub) && !str_contains($pub, "'unsafe-eval'"));
// Kept on purpose: includes/richtext.php builds style="color:…" out of author BBCode and templates/
// still carries ~104 style="" attributes. Asserted so that removing it later is a decision somebody
// makes, not something that quietly happens.
check('style-src keeps unsafe-inline (richtext builds style="" from author text)',
      (bool)preg_match("/style-src[^;]*'unsafe-inline'/", $pub));
check('img-src keeps https: (images in descriptions)', (bool)preg_match('/img-src[^;]*https:/', $pub));
// `frame-src ;` is legal and means "block everything", but it reads like a bug and the next person
// to edit this file will delete it.
check('no directive is ever printed with an empty source list',
      !preg_match('/(^|;\s*)[a-z-]+-src\s*(;|$)/', $pub) && !preg_match('/(^|;\s*)[a-z-]+-src\s*(;|$)/', $pan));
check('the panel may not be framed by anything', str_contains($pan, "frame-ancestors 'none'"));
check('a public page may still be framed by itself', str_contains($pub, "frame-ancestors 'self'"));
// Bootstrap's bundle is loaded from jsDelivr on panel pages only. A public page pulls ICONS (CSS +
// fonts) from there and nothing executable, so telling every anonymous visitor's browser that a CDN
// may run scripts would be paying the whole price of a third-party script origin for nothing.
check('jsDelivr may run scripts in the panel', (bool)preg_match('/script-src[^;]*cdn\.jsdelivr\.net/', $pan));
check('… and may not on a public page', !preg_match('/script-src[^;]*cdn\.jsdelivr\.net/', $pub));
check('… but still serves styles and fonts there',
      (bool)preg_match('/style-src[^;]*cdn\.jsdelivr\.net/', $pub)
      && (bool)preg_match('/font-src[^;]*cdn\.jsdelivr\.net/', $pub));
check('a JSON response gets default-src none', str_starts_with($apiPol, "default-src 'none'"));

// The .htaccess list names all four CAPTCHA providers because a static file cannot read a setting.
// This is the narrowing that moving into PHP bought — and, more importantly, the check that the
// host a provider's loader ACTUALLY uses is the host the policy allows. That pairing is what broke
// before: a provider added to captchaHeadTags() and forgotten in the policy loads no script, and
// every protected form then fails with "CAPTCHA unavailable".
$capCfgs = [
    'recaptcha'    => ['recaptcha_enabled' => '1', 'captcha_provider' => 'recaptcha',    'recaptcha_site_key' => 'S',    'recaptcha_secret' => 'X'],
    'recaptcha_v3' => ['recaptcha_enabled' => '1', 'captcha_provider' => 'recaptcha_v3', 'recaptcha_v3_site_key' => 'S', 'recaptcha_v3_secret' => 'X'],
    'turnstile'    => ['recaptcha_enabled' => '1', 'captcha_provider' => 'turnstile',    'turnstile_site_key' => 'S',    'turnstile_secret' => 'X'],
    'hcaptcha'     => ['recaptcha_enabled' => '1', 'captcha_provider' => 'hcaptcha',     'hcaptcha_site_key' => 'S',     'hcaptcha_secret' => 'X'],
];
foreach ($capCfgs as $prov => $capCfg) {
    $policy = cspPolicy($capCfg, 'public');
    preg_match('#<script src="(https://[^/"]+)#', captchaHeadTags($capCfg), $mLoader);
    check("$prov: the loader host is allowed by script-src",
          $mLoader && (bool)preg_match('/script-src[^;]*' . preg_quote($mLoader[1], '/') . '/', $policy),
          $mLoader[1] ?? 'no loader host found');
    foreach (captchaCspHosts($capCfg)['frame'] as $h) {
        check("$prov: frame-src allows $h", (bool)preg_match('/frame-src[^;]*' . preg_quote($h, '/') . '/', $policy));
    }
    foreach (captchaCspHosts($capCfg)['connect'] as $h) {
        check("$prov: connect-src allows $h", (bool)preg_match('/connect-src[^;]*' . preg_quote($h, '/') . '/', $policy));
    }
}
check('a Turnstile install does not also allow hCaptcha and Google',
      !str_contains(cspPolicy($capCfgs['turnstile'], 'public'), 'hcaptcha')
      && !str_contains(cspPolicy($capCfgs['turnstile'], 'public'), 'google.com'));
check('no CAPTCHA configured means no provider host in the policy at all',
      !str_contains($pub, 'google.com') && !str_contains($pub, 'hcaptcha') && !str_contains($pub, 'cloudflare'));

// csp_extra_hosts is written VERBATIM into a response header, so cspHostOk() is the whole security
// boundary of that box — and it is applied on READ as well as on save, because the settings table
// sits in a MariaDB three other applications can reach.
check('a plain host, a wildcard and a port are accepted',
      cspHostOk('cdn.example.org') && cspHostOk('https://*.example.net') && cspHostOk('example.org:8443'));
foreach (["'unsafe-inline'", '*', '*.', 'a*', 'localhost', 'data:', 'example.org/path',
          'example.org; script-src *', "ok.example.org\nX-Evil: 1", 'exa mple.org', '"x"', ''] as $badHost) {
    check('host expression refused: ' . str_replace("\n", '\n', $badHost === '' ? '(empty)' : $badHost), !cspHostOk($badHost));
}
$extraCfg = ['csp_extra_hosts' => 'cdn.example.org, https://*.example.net oops!!bad'];
check('extra hosts: the good ones reach the policy',
      (bool)preg_match('/script-src[^;]*cdn\.example\.org/', cspPolicy($extraCfg, 'public'))
      && (bool)preg_match('/connect-src[^;]*\*\.example\.net/', cspPolicy($extraCfg, 'public')));
check('extra hosts: a bad one is dropped rather than printed', !str_contains(cspPolicy($extraCfg, 'public'), 'oops'));
check('extra hosts: the page can name what was dropped', cspExtraHostsRejected($extraCfg) === ['oops!!bad']);
check('extra hosts: never more than the cap',
      count(cspExtraHosts(['csp_extra_hosts' => implode(' ', array_map(fn($i) => "h$i.example.org", range(1, 20)))])) === CSP_EXTRA_HOSTS_MAX);
// The belt: even if something reached the join, a CR/LF in a response header is response splitting.
check('no control character can reach the header',
      !preg_match('/[\x00-\x1F\x7F]/', cspPolicy(['csp_extra_hosts' => "ok.example.org\r\nX-Evil: 1"], 'public')));

check('no report-uri while collecting is off', !str_contains($pub, 'report-uri'));
check('report-uri appears when collecting is on',
      str_contains(cspPolicy(['csp_report_enabled' => '1'], 'public'), 'report-uri'));
check('mode off sends no report-uri either',
      cspPolicy(['csp_mode' => 'off', 'csp_report_enabled' => '1'], 'public') === '');
check('the two scopes report separately', cspReportUri('panel') !== cspReportUri('public'));
check('report-uri names the file the deploy actually ships', str_contains(cspReportUri('public'), 'csp-report.php'));
// THE failure this pairing prevents: report-uri pointing at a path with no file behind it. Apache
// then falls through to index.php?action=…, which sanitises the unknown action to 'home' and renders
// the WHOLE front page — session, schema check, four janitors — for every violation POST.
check('the report endpoint exists where report-uri points', is_file($root . '/csp-report.php'));
$deployPy = (string)@file_get_contents(dirname($root) . '/deploy/deploy.py');
if ($deployPy !== '') {
    check('the report endpoint is in the deploy allow-list', str_contains($deployPy, '"csp-report.php"'));
}
$repSrc = srcNoComments($root . '/csp-report.php');
check('the report endpoint starts no session and runs no janitor or migration',
      !str_contains($repSrc, 'session_start') && !str_contains($repSrc, 'ensureSchema')
      && !str_contains($repSrc, "includes/functions.php"));
check('the report endpoint refuses anything but POST', str_contains($repSrc, "!== 'POST'"));

// Two browsers, two wire formats, ONE row. Firefox posts {"csp-report":{…}} as application/csp-report,
// Chrome posts [{"type":"csp-violation","body":{…}}] as application/reports+json. If they did not
// normalise to the same triple, the panel's count would be a lie about how many distinct things are
// wrong: one problem seen in two browsers is one problem.
$ffRep = ['csp-report' => ['effective-directive' => 'script-src-elem',
                           'blocked-uri'  => 'https://evil.example/x.js?tok=SECRET',
                           'document-uri' => 'https://t.example/?action=whitelist&q=private']];
$crRep = [['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'script-src-elem',
                           'blockedURL'  => 'https://evil.example/x.js?tok=SECRET',
                           'documentURL' => 'https://t.example/?action=whitelist&q=private']]];
check('both browser shapes normalise to the same triple', cspReportNormalise($ffRep) === cspReportNormalise($crRep));
$oneRep = cspReportNormalise($ffRep);
// The row lands in a database three other applications can read and every backup dumps, and a
// blocked-uri routinely carries a token in its query.
check('a blocked URL is reduced to its origin — path and token dropped', $oneRep['blocked'] === 'https://evil.example');
check('the page is reduced to its action — the visitor’s query is not stored', cspDocAction($oneRep['doc']) === 'whitelist');
check('a document with no action reads as home', cspDocAction('https://t.example/') === 'home');
// Firefox puts the whole directive VALUE in violated-directive.
check('violated-directive keeps only the directive name',
      cspReportNormalise(['csp-report' => ['violated-directive' => "script-src 'self' 'nonce-abc'", 'blocked-uri' => 'inline']])['directive'] === 'script-src');
check('the spec literals are kept as they are',
      cspReportNormalise(['csp-report' => ['effective-directive' => 'script-src-attr', 'blocked-uri' => 'inline']])['blocked'] === 'inline');
// Add-ons injecting their own scripts are the largest source of CSP reports on any site, and not
// one of those reports is about this site.
foreach (['chrome-extension://abcdef/x.js', 'moz-extension://abcdef/x.js', 'about:blank', 'data:text/html,x'] as $noise) {
    check('extension/scheme noise is dropped: ' . $noise,
          cspReportNormalise(['csp-report' => ['effective-directive' => 'script-src', 'blocked-uri' => $noise]]) === null);
}
check('a report about somebody else’s site is not ours',
      !cspDocIsOurs('https://elsewhere.example/x', 't.example')
      && cspDocIsOurs('https://t.example/x', 't.example:8443'));
check('keep-rows is clamped both ways',
      cspReportKeepRows(['csp_report_keep_rows' => '99999']) === CSP_ROWS_MAX
      && cspReportKeepRows(['csp_report_keep_rows' => '-5']) === 0
      && cspReportKeepRows([]) === CSP_ROWS_DEFAULT);
check('collecting is off unless BOTH the switch and a mode say so',
      !cspReportingOn([]) && !cspReportingOn(['csp_mode' => 'off', 'csp_report_enabled' => '1'])
      && cspReportingOn(['csp_report_enabled' => '1']));

// A nonce helper nobody can forget: every inline <script> in shipped PHP must carry it. A source
// grep rather than a served page, so it fails in a suite that needs no database and no web server —
// and anchored inside the TAG, because `api.js?onload=onCaptchaApiLoad` and a description that
// happens to contain the word "onclick" are both legitimate and must not trip it.
$nonceMisses = [];
$scriptFiles = array_merge(glob($root . '/templates/*.php') ?: [], glob($root . '/templates/*/*.php') ?: [],
                           glob($root . '/templates/*/*/*.php') ?: [], glob($root . '/includes/*.php') ?: []);
foreach ($scriptFiles as $f) {
    if (preg_match_all('/<script\b([^>]*)>/i', srcNoComments($f), $tags, PREG_SET_ORDER)) {
        foreach ($tags as $tag) {
            if (stripos($tag[1], 'src=') !== false) continue;      // external file: allow-listed by host, no nonce needed
            if (stripos($tag[1], 'nonceAttr') !== false) continue;
            $nonceMisses[] = basename($f) . ': ' . trim($tag[0]);
        }
    }
}
check('every inline <script> in shipped PHP carries the nonce', empty($nonceMisses), implode(' | ', $nonceMisses));
// A nonce does NOT rescue an on*= attribute: script-src without 'unsafe-inline' blocks those
// outright, nonce or no nonce. So the templates must not have any, or enforcing simply will not run
// them. Anchored to an attribute position (whitespace, then on…=") so a query string cannot match.
$handlers = [];
foreach ($scriptFiles as $f) {
    if (preg_match_all('/\son[a-z]{3,12}\s*=\s*["\']/i', srcNoComments($f), $hm)) {
        $handlers[] = basename($f) . ': ' . implode(',', array_map('trim', $hm[0]));
    }
}
check('no inline on*= handler survives in a template (a nonce cannot save one)', empty($handlers), implode(' | ', $handlers));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
