<?php
/**
 * The panel's secrets and the switches that arm the janitor, over HTTP (1.74.0: PANEL-1, PANEL-2, XSS-6):
 *   php tests/security_http_1740_test.php
 * Needs the local site on http://127.0.0.1:8089/ (VERIFY_BASE to point elsewhere) and the local owner password
 * (ADMIN_PASS, default admin123); says SKIP without the site.
 *
 * The audit's verifier signed in an ADMIN-GROUP account through the public sign-in — it did not know the owner's
 * password — opened Settings, read `hmac_secret` out of the HTML, put a backup download token together offline and
 * downloaded the full archive (every database password on the box). Held down here:
 *   1. that account's Settings page carries none of the six secrets — the HMAC key, the four CAPTCHA secrets, the
 *      health token — not even in a field's value;
 *   2. op `token` still asks for the owner's password, and a token signed with the HMAC key alone is refused: 403;
 *   3. the owner's way still works: password → token → the download passes the token's gates (here it stops at the
 *      missing root helper), and the same link a second time is "already used";
 *   4. saving Settings with a credential's field left empty keeps it; an empty health token still switches it off;
 *   5. arming what the janitor does as root (the schedule, the inbound limit, its automatic band, the monitor,
 *      backups) asks for the password; an untouched save does not;
 *   6. a footer address that is not http(s) is refused on save.
 * Self-cleaning: every settings row it touches is put back (value AND time), its account goes, its audit lines go.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/backup.php';
require_once $root . '/includes/lang.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }
langInit([], 'en');

$site = rtrim(getenv('VERIFY_BASE') ?: 'http://127.0.0.1:8089/', '/') . '/';
$probe = @file_get_contents($site . '?action=tos', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
if ($probe === false || !function_exists('curl_init')) {
    skip('the panel over HTTP', 'the local site does not answer at ' . $site . ' (or no curl)');
    echo "\n$n checks, $fails failed, $skips skipped\n";
    exit(0);
}
$ownerPass = getenv('ADMIN_PASS') ?: 'admin123';
$db = getDb();
$cfg = getSettings($db);
$auditFloor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$keep = ['users_enabled', 'users_require_email_verify', 'turnstile_secret', 'health_token', 'hmac_secret', 'recaptcha_secret',
         'recaptcha_v3_secret', 'hcaptcha_secret', 'tracker_schedule_enabled', 'net_limit_enabled', 'net_auto_enabled',
         'net_monitor_enabled', 'backup_enabled', 'footer_brand_url', 'items_per_page'];
$was = [];
foreach ($keep as $k) {
    $st = $db->prepare("SELECT `value`, updated_at FROM settings WHERE `key` = ?");
    $st->execute([$k]);
    $was[$k] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$tmp = [];
$cleanup = function () use ($db, $was, &$tmp, $auditFloor): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'sechttp\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
    foreach ($was as $k => $row) {
        if ($row === null) $db->prepare("DELETE FROM settings WHERE `key` = ?")->execute([$k]);
        else $db->prepare("INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)")
                ->execute([$k, $row['value'], $row['updated_at']]);
    }
    $db->prepare("DELETE FROM audit_log WHERE id > ?")->execute([$auditFloor]);
    if (function_exists('apcu_clear_cache')) @apcu_clear_cache();
    foreach ($tmp as $f) @unlink($f);
    rateLimitForget('user_login', fn(string $s): bool => $s === '127.0.0.1' || $s === '::/64');
    clearLoginFailures('127.0.0.1');
};
register_shutdown_function($cleanup);

/** A browser with its own cookie jar: GET (redirects followed) or POST JSON, with the CSRF header when given one. */
$browser = function () use ($site, &$tmp): array {
    $jar = tempnam(sys_get_temp_dir(), 'sechttp');
    $tmp[] = $jar;
    $req = function (string $path, ?array $json = null, string $csrf = '', bool $follow = true) use ($site, $jar): array {
        $c = curl_init($site . $path);
        $h = ['Accept-Language: en'];
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 30,
                               CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
        if ($json !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($json));
            $h[] = 'Content-Type: application/json';
            $h[] = 'Accept: application/json';
            if ($csrf !== '') $h[] = 'X-CSRF-Token: ' . $csrf;
        }
        curl_setopt($c, CURLOPT_HTTPHEADER, $h);
        $body = (string)curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $url = (string)curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
        curl_close($c);
        return ['code' => $code, 'body' => $body, 'url' => $url, 'json' => json_decode($body, true)];
    };
    return ['get' => fn(string $p, bool $follow = true) => $req($p, null, '', $follow),
            'post' => fn(string $endpoint, array $body, string $csrf = '') => $req('api.php?endpoint=' . $endpoint, $body, $csrf)];
};
$meta = fn(string $html): string => preg_match('/<meta name="csrf-token" content="([^"]*)">/', $html, $m) ? $m[1] : '';
$panelCsrf = fn(string $html): string => preg_match('/data-csrf="([^"]*)"/', $html, $m) ? $m[1] : '';
$setRow = function (string $k, string $v) use ($db): void {
    $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$k, $v]);
    if (function_exists('apcu_clear_cache')) @apcu_clear_cache();
};
$row = function (string $k) use ($db): ?string {
    $st = $db->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
};

// Known secrets for this run, so the page can be searched for them; the HMAC key stays the install's own.
$secrets = ['turnstile_secret' => '0xSECTEST' . bin2hex(random_bytes(8)), 'hcaptcha_secret' => '0xHCTEST' . bin2hex(random_bytes(8)),
            'recaptcha_secret' => '6LeSECTEST' . bin2hex(random_bytes(8)), 'recaptcha_v3_secret' => '6LeV3TEST' . bin2hex(random_bytes(8)),
            'health_token' => 'health-sectest-' . bin2hex(random_bytes(10))];
foreach ($secrets as $k => $v) $setRow($k, $v);
$hmac = (string)$row('hmac_secret');
if ($hmac === '') { $hmac = bin2hex(random_bytes(16)); $setRow('hmac_secret', $hmac); }
$secrets['hmac_secret'] = $hmac;
$setRow('users_enabled', '1');
$setRow('users_require_email_verify', '0');

/* ── 1. an admin-group account, signed in through the public sign-in ─────────────────────────────── */
$db->exec("DELETE FROM users WHERE username LIKE 'sechttp\\_%'");
$made = userCreate($db, array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '0']), 'sechttp_adm', 'sechttp_adm@example.org', 'SecHttp123!pass', '127.0.0.1');
$aid = (int)($made['user']['id'] ?? 0);
$db->prepare("UPDATE users SET email_verified = 1, status = 'active' WHERE id = ?")->execute([$aid]);
$adminGroup = userGroupBySlug($db, 'admin');
if ($aid > 0 && $adminGroup) userGrantGroup($db, $aid, (int)$adminGroup['id'], null, 'security_http_1740_test', '', false);
check('an admin-group account to sign in with', $aid > 0 && $adminGroup !== null && userIsAdminGroup($db, $aid), json_encode($made['error'] ?? null));
rateLimitForget('user_login', fn(string $s): bool => $s === '127.0.0.1' || $s === '::/64');
$m = $browser();
$lt = $meta(($m['get'])('?action=login&lang=en')['body']);
$in = ($m['post'])('user_login', ['csrf_token' => $lt, 'login' => 'sechttp_adm', 'password' => 'SecHttp123!pass', 'session' => '1h']);
check('… it signs in through the PUBLIC sign-in, without the owner\'s password', !empty($in['json']['success']), $in['body']);
$set = ($m['get'])('?action=settings&lang=en');
check('… and reaches Settings (the admin group may)', $set['code'] === 200 && str_contains($set['body'], 'id="settings-form"'), $set['code'] . ' ' . $set['url']);
$leaks = array_keys(array_filter($secrets, fn($v) => $v !== '' && str_contains($set['body'], $v)));
check('Settings carries none of the six secrets — the HMAC key, the four CAPTCHA secrets, the health token — anywhere in the page',
      $leaks === [], implode(', ', $leaks));
check('… each is an empty field that says only that one is set', preg_match_all('/name="(hmac_secret|recaptcha_secret|recaptcha_v3_secret|turnstile_secret|hcaptcha_secret|health_token)"[^>]*value=""/', $set['body']) === 6
      && !preg_match('/name="hmac_secret"[^>]*value="[0-9a-f]+"/', $set['body']) && substr_count($set['body'], 'data-secret="1"') === 6);
check('… and the health check\'s address names the token\'s place without the token', str_contains($set['body'], '?action=health&amp;token=' . __('settings.health_token_in_url')));
$csrfM = $panelCsrf($set['body']);

/* ── 2. the backup token: the password, and a forged one ─────────────────────────────────────────── */
$id = 'backup-tryhackx-20261006-010203';
$tk = ($m['post'])('admin/backup_action', ['op' => 'token', 'id' => $id, 'password' => ''], $csrfM);
check('op token still asks the owner\'s password of that account: 403', $tk['code'] === 403 && empty($tk['json']['token']), $tk['code'] . ' ' . $tk['body']);
$exp = time() + 120;
$nonce = bin2hex(random_bytes(16));
$forged = $exp . '.' . $nonce . '.' . hash_hmac('sha256', $id . '|' . $exp . '|' . $nonce, $hmac);
$dl = ($m['get'])('api.php?endpoint=admin/backup_download&id=' . rawurlencode($id) . '&token=' . rawurlencode($forged));
check('a token put together from the HMAC key alone — the verifier\'s attack — is refused: 403, "not issued"',
      $dl['code'] === 403 && str_contains($dl['body'], __('api.backup.dl_not_issued')), $dl['code'] . ' ' . substr($dl['body'], 0, 200));
$exp2 = 4102444800;
$far = $exp2 . '.' . $nonce . '.' . hash_hmac('sha256', $id . '|' . $exp2 . '|' . $nonce, $hmac);
$dl2 = ($m['get'])('api.php?endpoint=admin/backup_download&id=' . rawurlencode($id) . '&token=' . rawurlencode($far));
check('… and one dated the year 2100 never gets that far: 410, not valid', $dl2['code'] === 410, (string)$dl2['code']);
$old = $exp . '.' . hash_hmac('sha256', $id . '|' . $exp, $hmac);
check('… nor one of the old shape (expiry.signature)', ($m['get'])('api.php?endpoint=admin/backup_download&id=' . rawurlencode($id) . '&token=' . rawurlencode($old))['code'] === 410);

/* ── 3. the owner's way still works ──────────────────────────────────────────────────────────────── */
clearLoginFailures('127.0.0.1');
$o = $browser();
$ot = $meta(($o['get'])('?action=admin&lang=en')['body']);
$oin = ($o['post'])('admin/login', ['csrf_token' => $ot, 'username' => (string)($cfg['admin_username'] ?? 'admin'), 'password' => $ownerPass]);
$ownerIn = !empty($oin['json']['success']);
check('the owner signs in to the panel', $ownerIn, $oin['body']);
$oset = ($o['get'])('?action=settings&lang=en');
$csrfO = $panelCsrf($oset['body']);
$tok = ($o['post'])('admin/backup_action', ['op' => 'token', 'id' => $id, 'password' => $ownerPass], $csrfO);
$url = (string)($tok['json']['url'] ?? '');
check('with the password, op token hands out a link', !empty($tok['json']['success']) && str_contains($url, 'endpoint=admin/backup_download'), $tok['code'] . ' ' . $tok['body']);
$path = ltrim((string)preg_replace('#^.*?(api\.php\?)#', '$1', $url), '/');
$g1 = ($o['get'])($path);
check('… the link passes the token\'s gates (here it stops at the root helper this machine has not got: not 403, not 410)',
      $url !== '' && $g1['code'] !== 403 && $g1['code'] !== 410, $g1['code'] . ' ' . substr($g1['body'], 0, 200));
$g2 = ($o['get'])($path);
check('… and the same link a second time is "already used": 410', $g2['code'] === 410 && str_contains($g2['body'], __('api.backup.dl_used')), $g2['code'] . ' ' . substr($g2['body'], 0, 200));

/* ── 4. saving with a secret's field left empty keeps the secret ─────────────────────────────────── */
$save = fn(array $body) => ($o['post'])('admin/save_settings', $body, $csrfO);
// What the page sends (templates/admin/settings.php): every field — the five credentials empty unless typed — beside
// the ordinary ones; the health token's untouched field is left out of the save, and sent empty only by "switch off".
$s1 = $save(['hmac_secret' => '', 'turnstile_secret' => '', 'recaptcha_secret' => '', 'recaptcha_v3_secret' => '', 'hcaptcha_secret' => '',
             'items_per_page' => (string)($row('items_per_page') ?? '25')]);
check('a save with every secret field empty goes through without the password…', !empty($s1['json']['success']), $s1['code'] . ' ' . $s1['body']);
check('… and keeps every secret as it was', $row('hmac_secret') === $hmac && $row('turnstile_secret') === $secrets['turnstile_secret']
      && $row('recaptcha_secret') === $secrets['recaptcha_secret'] && $row('hcaptcha_secret') === $secrets['hcaptcha_secret']
      && $row('recaptcha_v3_secret') === $secrets['recaptcha_v3_secret'] && $row('health_token') === $secrets['health_token']);
$newTs = '0xNEWTS' . bin2hex(random_bytes(6));
$s2 = $save(['turnstile_secret' => $newTs]);
check('a typed value replaces it', !empty($s2['json']['success']) && $row('turnstile_secret') === $newTs, $s2['body']);
$s3 = $save(['hmac_secret' => bin2hex(random_bytes(16))]);
check('… a new HMAC key still asks for the owner\'s password', $s3['code'] === 403 && !empty($s3['json']['reauth_required']) && $row('hmac_secret') === $hmac, $s3['body']);
$s4 = $save(['health_token' => '']);
check('an empty health token is still a real answer — the endpoint switched off (what "switch off" sends, and the smoke\'s call)',
      !empty($s4['json']['success']) && $row('health_token') === '', $s4['body']);

/* ── 5. arming the janitor asks for the password ─────────────────────────────────────────────────── */
$armed = [];
foreach (['tracker_schedule_enabled', 'net_limit_enabled', 'net_auto_enabled', 'net_monitor_enabled', 'backup_enabled'] as $k) {
    $now = (string)($row($k) ?? '0');
    $r = $save([$k => $now === '1' ? '0' : '1']);
    if (!($r['code'] === 403 && !empty($r['json']['reauth_required']) && in_array($k, (array)($r['json']['reauth_keys'] ?? []), true) && (string)($row($k) ?? '0') === $now)) {
        $armed[$k] = $r['code'] . ' ' . $r['body'];
    }
}
check('switching what the janitor then does as root — schedule, inbound limit, its automatic band, monitor, backups — asks for the password',
      $armed === [], json_encode($armed));
$s5 = $save(['tracker_schedule_enabled' => (string)($row('tracker_schedule_enabled') ?? '0'), 'items_per_page' => (string)($row('items_per_page') ?? '50')]);
check('… while a save that changes none of them does not', !empty($s5['json']['success']), $s5['body']);

/* ── 6. a footer address that is not http(s) ─────────────────────────────────────────────────────── */
$s6 = $save(['footer_brand_url' => 'javascript:window.__xss=1']);
check('a footer address that is not http(s) is refused on save, by name', $s6['code'] === 400 && str_contains((string)($s6['json']['error'] ?? ''), 'javascript:')
      && $row('footer_brand_url') !== 'javascript:window.__xss=1', $s6['body']);

$cleanup();
echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
