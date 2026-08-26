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

// every provider host in the head tags must be allow-listed by the shipped CSP, or the script
// silently never loads (the failure that made hCaptcha unusable on a default install)
$htaccess = (string)@file_get_contents($root . '/.htaccess');
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

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
