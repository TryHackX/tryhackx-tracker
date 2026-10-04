<?php
/**
 * Account mail: what is always sent, and what a member may switch off (needs the local test database;
 * the one-click half needs the local site on http://127.0.0.1:8089/ — VERIFY_BASE to point elsewhere —
 * and says SKIP without it):
 *   php tests/mail_test.php
 *
 * ── what this file holds down (1.73.0) ──────────────────────────────────────────────────────────
 *
 * 1. A MEMBER CANNOT LOCK THEMSELVES OUT. The password reset, every step of an e-mail change with its
 *    confirmations, and the verification mail are sent whatever the address unsubscribed from — the
 *    account page's "Account mail" switch, or the unsubscribe page's master switch, which any mail's
 *    footer leads to. Until 1.73.0 both stopped the reset mail.
 * 2. THAT MAIL CARRIES NO UNSUBSCRIBE. No List-Unsubscribe header (a mail client's one-click button) on
 *    a mail there is nothing to unsubscribe from.
 * 3. THE SWITCH GOVERNS WHAT IT SAYS. Group notices (granted, about to end) and the operator's notice copies
 *    stop when it is off, and carry the header when it is on; the account page names exactly those.
 * 4. THE ONE-CLICK WORKS. A mail client's RFC 8058 POST to the List-Unsubscribe address (the unsubscribe
 *    page) used to render the page and record nothing.
 *
 * ── how a sent mail is seen without a mail server ───────────────────────────────────────────────
 * PHP's own `mail.log` records every mail() call — To, the headers, the subject — before it tries to
 * deliver. This file runs itself again with mail.log pointed at a scratch file and the mail server at
 * 0.0.0.0 (Windows: the connection fails at once) / sendmail_path `true` (elsewhere), so the real
 * sendEmail() runs end to end and nothing leaves the machine. Its accounts and rows are removed at the end.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }

// ── run again with mail.log on (it is PHP_INI_SYSTEM|PERDIR: only -d can set it) ─────────────────────
if ((string)ini_get('mail.log') === '' && getenv('TRACKER_MAIL_TEST_LOG') === false) {
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tracker_mail_test_' . getmypid() . '.log';
    @unlink($log);
    $env = getenv();
    $env['TRACKER_MAIL_TEST_LOG'] = $log;
    $p = proc_open([PHP_BINARY, '-d', 'mail.log=' . $log, '-d', 'SMTP=0.0.0.0', '-d', 'smtp_port=25',
                    '-d', 'sendmail_path=true', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), __FILE__],
                   [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, null, $env);
    $code = is_resource($p) ? proc_close($p) : 2;
    @unlink($log);
    exit($code);
}
$LOG = (string)(getenv('TRACKER_MAIL_TEST_LOG') ?: ini_get('mail.log'));

require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$cfg = getSettings($db, true);

/** Every mail() call so far: [to, headers, subject (decoded)]. */
$mails = function () use ($LOG): array {
    $out = [];
    foreach (is_file($LOG) ? (array)file($LOG, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (!preg_match('/ To: (.*?) -- Headers: (.*) -- Subject: (.*)$/', $line, $m)) continue;
        $subj = preg_replace_callback('/=\?UTF-8\?B\?([^?]*)\?=/i', fn($x) => (string)base64_decode($x[1]), $m[3]);
        $out[] = ['to' => trim($m[1]), 'headers' => $m[2], 'subject' => $subj];
    }
    return $out;
};
/** The mails one action sent, to one address. */
$sent = function (callable $act, string $to) use ($mails): array {
    $before = count($mails());
    $act();
    return array_values(array_filter(array_slice($mails(), $before), fn($m) => strcasecmp($m['to'], $to) === 0));
};
$hasUnsub = fn(array $m): bool => stripos($m['headers'], 'List-Unsubscribe') !== false;

check('mail.log is on in this run, so a sent mail can be seen', $LOG !== '', 'run as: php tests/mail_test.php');

// ── a fresh member, unsubscribed from everything that can be switched off ───────────────────────────
$NAME = 'mailtest_e730';
$OLD = 'mailtest.e730@example.org';
$NEW = 'mailtest.e730.new@example.org';
$ONE = 'oneclick.e730@example.org';
$cleanup = function () use ($db, $cfg, $NAME, $OLD, $NEW, $ONE) {
    $st = $db->prepare("SELECT id FROM users WHERE username = ? OR email IN (?, ?)");
    $st->execute([$NAME, $OLD, $NEW]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id, $cfg);
    foreach (['email_preferences', 'unsubscribed_emails'] as $t) {
        $db->prepare("DELETE FROM `$t` WHERE email IN (?, ?, ?)")->execute([$OLD, $NEW, $ONE]);
    }
};
$cleanup();
$r = userCreate($db, $cfg, $NAME, $OLD, 'MailPass123!', '127.0.0.1');
$uid = (int)($r['user']['id'] ?? 0);
check('a member to mail', $uid > 0, json_encode($r['errors'] ?? $r));
$db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$uid]);
$offAll = function (string $email) use ($db) {
    unsubscribeAll($db, $email);
    $st = $db->prepare("INSERT INTO email_preferences (email, type, enabled) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE enabled = 0");
    foreach (['account', 'bulk'] as $t) $st->execute([$email, $t]);
};
$offAll($OLD);
check('the address is unsubscribed from account mail…', isUnsubscribed($db, $OLD, 'account'));
check('… and from everything else (the unsubscribe page\'s master switch)', isUnsubscribed($db, $OLD));

// ── 1 + 2: the transactional mail, every one of them ────────────────────────────────────────────────
$u = userFindById($db, $uid);
$m = $sent(fn() => userResetSend($db, $cfg, $u), $OLD);
check('the password reset IS sent to an address unsubscribed from everything', count($m) === 1, json_encode($m));
check('… with the reset subject', str_contains($m[0]['subject'] ?? '', 'password reset'), $m[0]['subject'] ?? '');
check('… and no List-Unsubscribe header', isset($m[0]) && !$hasUnsub($m[0]), $m[0]['headers'] ?? '');
$tok = $db->prepare("SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND type = 'reset'");
$tok->execute([$uid]);
check('… and its token exists', (int)$tok->fetchColumn() === 1);
check('nobody to send to: no account, an inactive one or no address',
      userResetSend($db, $cfg, null) === false && userResetSend($db, $cfg, ['id' => $uid, 'status' => 'banned', 'email' => $OLD]) === false
      && userResetSend($db, $cfg, ['id' => $uid, 'status' => 'active', 'email' => '']) === false);

$m = $sent(fn() => userVerifySend($db, $cfg, $u), $OLD);
check('the verification mail is sent', count($m) === 1, json_encode($m));
check('… with no List-Unsubscribe header', isset($m[0]) && !$hasUnsub($m[0]), $m[0]['headers'] ?? '');

$offAll($NEW);
$m = $sent(function () use ($db, $cfg, $u, $NEW, &$start) { $start = userEmailChangeStart($db, $cfg, $u, $NEW); }, $OLD);
check('an e-mail change starts (step 1 from the current address)', ($start['stage'] ?? '') === 'old', json_encode($start));
check('… and its confirmation IS sent to the unsubscribed current address', count($m) === 1, json_encode($m));
check('… with no List-Unsubscribe header', isset($m[0]) && !$hasUnsub($m[0]), $m[0]['headers'] ?? '');

// The steps' links carry tokens this test cannot read out of a mail, so it writes its own — the same
// row userEmailChangeStart() writes, with a token it knows.
$stepToken = function (string $type) use ($db, $uid): string {
    $t = bin2hex(random_bytes(32));
    $db->prepare("DELETE FROM user_tokens WHERE user_id = ? AND type IN ('echange_old','echange_new')")->execute([$uid]);
    $db->prepare("INSERT INTO user_tokens (user_id, type, token_hash, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 1 HOUR)")
       ->execute([$uid, $type, hash('sha256', $t)]);
    return $t;
};
$t1 = $stepToken('echange_old');
$m = $sent(function () use ($db, $cfg, $t1, &$res) { $res = userEmailChangeConsume($db, $cfg, $t1); }, $NEW);
check('step 1 confirmed: the new address is asked (step 2)', ($res['stage'] ?? '') === 'old_ok', json_encode($res));
check('… and that mail IS sent to an unsubscribed new address', count($m) === 1, json_encode($m));
check('… with no List-Unsubscribe header', isset($m[0]) && !$hasUnsub($m[0]), $m[0]['headers'] ?? '');

$t2 = $stepToken('echange_new');
$before = count($mails());
$res = userEmailChangeConsume($db, $cfg, $t2);
$after = array_slice($mails(), $before);
$toOld = array_values(array_filter($after, fn($x) => strcasecmp($x['to'], $OLD) === 0));
$toNew = array_values(array_filter($after, fn($x) => strcasecmp($x['to'], $NEW) === 0));
check('step 2 confirmed: the change is done', ($res['stage'] ?? '') === 'done', json_encode($res));
check('"your address was changed" IS sent to the old, unsubscribed address', count($toOld) === 1, json_encode($after));
check('"change confirmed" IS sent to the new, unsubscribed address', count($toNew) === 1, json_encode($after));
check('… neither with a List-Unsubscribe header',
      isset($toOld[0], $toNew[0]) && !$hasUnsub($toOld[0]) && !$hasUnsub($toNew[0]));

// ── 3: what the switch governs ──────────────────────────────────────────────────────────────────────
$u = userFindById($db, $uid);   // on $NEW now
check('the account is on its new address', ($u['email'] ?? '') === $NEW);
$m = $sent(fn() => userNotifyMail($db, $cfg, $u, 'Test — you are now in the "premium" group', 'Access granted permanently.'), $NEW);
check('a group notice is NOT sent while the address is unsubscribed', $m === [], json_encode($m));
// Back on: what the account page's switch does (api/user_email_prefs.php), and the master switch undone.
$db->prepare("INSERT INTO email_preferences (email, type, enabled) VALUES (?, 'account', 1) ON DUPLICATE KEY UPDATE enabled = 1")->execute([$NEW]);
$db->prepare("DELETE FROM unsubscribed_emails WHERE email = ?")->execute([$NEW]);
$m = $sent(fn() => userNotifyMail($db, $cfg, $u, 'Test — you are now in the "premium" group', 'Access granted permanently.'), $NEW);
check('with "Account mail" on, the group notice is sent', count($m) === 1, json_encode($m));
check('… WITH the List-Unsubscribe header (it can be switched off)', isset($m[0]) && $hasUnsub($m[0]), $m[0]['headers'] ?? '');
check('… pointing at this address\'s own preferences', isset($m[0]) && str_contains($m[0]['headers'], getUnsubscribeUrl($NEW, $cfg)),
      $m[0]['headers'] ?? '');

// The expiry warning, through the janitor's own tick: on → mailed with the header; off → not mailed.
$gid = (int)($db->query("SELECT id FROM user_groups WHERE slug = 'member'")->fetchColumn() ?: 0);
$cfgUsers = array_merge($cfg, ['users_enabled' => '1', 'users_notify_expiry_days' => '3']);
// One membership per group (uq_ugm_user_group): the account's own, made to end tomorrow — granted long ago,
// by the application's clock (a row the mysql client wrote would be two hours ahead of it).
$db->prepare("INSERT INTO user_group_members (user_id, group_id, granted_at, expires_at, granted_by, note)
              VALUES (?, ?, '2000-01-01 00:00:00', NOW() + INTERVAL 1 DAY, 'mail_test', '')
              ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at), warned_at = NULL")->execute([$uid, $gid]);
$mq = $db->prepare("SELECT id FROM user_group_members WHERE user_id = ? AND group_id = ?");
$mq->execute([$uid, $gid]);
$memId = (int)$mq->fetchColumn();
check('a membership that ends tomorrow', $gid > 0 && $memId > 0);
$m = $sent(fn() => usersTick($db, $cfgUsers), $NEW);
check('an expiry warning is mailed while "Account mail" is on', count($m) === 1, json_encode($m));
check('… with the List-Unsubscribe header', isset($m[0]) && $hasUnsub($m[0]));
$db->prepare("UPDATE user_group_members SET warned_at = NULL WHERE id = ?")->execute([$memId]);
$db->prepare("UPDATE email_preferences SET enabled = 0 WHERE email = ? AND type = 'account'")->execute([$NEW]);
$m = $sent(fn() => usersTick($db, $cfgUsers), $NEW);
check('… and is NOT mailed once it is switched off', $m === [], json_encode($m));
$w = $db->prepare("SELECT warned_at FROM user_group_members WHERE id = ?");
$w->execute([$memId]);
check('… while the in-app warning still happens (warned_at stamped)', $w->fetchColumn() !== null);

// ── which caller is which, in the code ──────────────────────────────────────────────────────────────
$src = fn(string $f) => (string)@file_get_contents($root . '/' . $f);
$users = $src('includes/users.php');
check('five sends in users.php are transactional (the reset and the e-mail change\'s four)',
      substr_count($users, "'transactional' => true") === 5, (string)substr_count($users, "'transactional' => true"));
check('the reset endpoint sends through userResetSend()', str_contains($src('api/user_reset_request.php'), 'userResetSend($db, $cfg, $u)')
      && !str_contains($src('api/user_reset_request.php'), 'userNotifyMail('));
foreach (['api/admin/user_grant.php', 'api/v1/users_grant.php', 'api/admin/user_notify.php'] as $f) {
    check("$f stays governed by the switch", str_contains($src($f), 'userNotifyMail(') && !str_contains($src($f), 'transactional'));
}
check('the verification mail passes no unsubscribe address',
      preg_match("/function userVerifySend.*?'unsubscribe_url' => ''.*?sendEmail\([^;]*\\\$cfg, ''\)/s", $users) === 1);

// ── the account page's words ────────────────────────────────────────────────────────────────────────
$en = langFor('en', 'account.pref_account_note');
$pl = langFor('pl', 'account.pref_account_note');
check('the switch names what it governs (EN): groups and the operator\'s notices',
      str_contains($en, 'groups') && str_contains($en, 'operator'), $en);
check('… and says what always arrives (EN)', str_contains($en, 'Password resets') && str_contains($en, 'always'), $en);
check('the same in Polish', str_contains($pl, 'grupy') && str_contains($pl, 'operatora') && str_contains($pl, 'Resety hasła')
      && str_contains($pl, 'zawsze'), $pl);
check('it no longer claims security notices', !str_contains($en, 'security notices'), $en);

// ── 4: the one-click (RFC 8058), on the real page ───────────────────────────────────────────────────
$site = rtrim(getenv('VERIFY_BASE') ?: 'http://127.0.0.1:8089/', '/') . '/';
$probe = @file_get_contents($site . '?action=tos', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
if ($probe === false || !function_exists('curl_init')) {
    echo "SKIP the one-click half: the local site is not answering at $site\n";
} else {
    $post = function (string $email, ?string $body, string $method = 'POST') use ($site, $cfg): int {
        $url = $site . '?action=unsubscribe&email=' . urlencode($email) . '&token=' . generateUnsubscribeToken($email, $cfg['hmac_secret'] ?? '');
        $c = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method];
        if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = $body; $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded']; }
        curl_setopt_array($c, $opts);
        curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        return $code;
    };
    $reportKindsOff = function (string $email) use ($db): int {
        $st = $db->prepare("SELECT COUNT(*) FROM email_preferences WHERE email = ? AND enabled = 0
                             AND type IN ('submission','review','status','custom','appeal')");
        $st->execute([$email]);
        return (int)$st->fetchColumn();
    };
    check('before: the one-click address unsubscribed from nothing', !isUnsubscribed($db, $ONE));
    check('a plain GET of the page changes nothing', $post($ONE, null, 'GET') === 200 && !isUnsubscribed($db, $ONE));
    check('a POST without the one-click body changes nothing', $post($ONE, 'foo=bar') === 200 && !isUnsubscribed($db, $ONE));
    $code = $post($ONE, 'List-Unsubscribe=One-Click');
    check('the one-click POST is answered 200', $code === 200, (string)$code);
    check('… and the address IS unsubscribed now (the master switch)', isUnsubscribed($db, $ONE));
    check('… with the five report kinds off', $reportKindsOff($ONE) === 5, (string)$reportKindsOff($ONE));
    check('… which stops only what may be stopped: the preference is asked only when the mail is not transactional',
          str_contains($users, "if (!\$transactional && function_exists('isUnsubscribed') && isUnsubscribed(\$db, \$email, 'account')) return;"));
    $bad = $site . '?action=unsubscribe&email=' . urlencode($NEW) . '&token=' . str_repeat('0', 64);
    $c = curl_init($bad);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POSTFIELDS => 'List-Unsubscribe=One-Click']);
    curl_exec($c); curl_close($c);
    check('a one-click with a wrong token changes nothing', !isUnsubscribed($db, $NEW));
}

$cleanup();
$left = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
$left->execute([$NAME]);
check('the test account is gone again', (int)$left->fetchColumn() === 0);

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
