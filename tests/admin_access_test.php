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

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
