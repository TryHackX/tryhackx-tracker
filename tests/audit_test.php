<?php
/**
 * Test for includes/audit.php:
 *   php tests/audit_test.php
 *
 * The log exists to be read after something has gone wrong, which means the two things worth testing
 * are the ones that would make it useless at that moment: that it never contains a credential, and
 * that a failure to write it never stops the action it was describing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/audit.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$cfg = getSettings($db, true);
ensureSchema($db, $cfg);
$GLOBALS['db'] = $db;
$GLOBALS['cfg'] = $cfg;

$db->exec("DELETE FROM audit_log WHERE actor_name = 'audit-test'");
$actor = ['type' => 'system', 'id' => null, 'name' => 'audit-test'];

/* ── 1. credentials never reach the log ──────────────────────────────────── */
//
// Matched on the key NAME rather than a hand-kept list, so a credential added later is covered
// without anybody remembering to come back here.

$secretish = ['hmac_secret', 'recaptcha_secret', 'admin_password', 'api_key', 'turnstile_secret',
              'backup_gpg_key', 'mail_password', 'session_salt', 'private_token'];
foreach ($secretish as $k) {
    check("$k is treated as a credential", auditIsSecretKey($k));
}
$plain = ['site_name', 'announce_url', 'rep_mode', 'audit_keep_days', 'index_max_rows'];
foreach ($plain as $k) {
    check("$k is not", !auditIsSecretKey($k));
}

$diff = auditSettingsDiff(
    ['site_name' => 'Old', 'hmac_secret' => 'letmein', 'rep_mode' => 'thumbs'],
    ['site_name' => 'New', 'hmac_secret' => 'hunter2', 'rep_mode' => 'thumbs']);
check('a diff records only what moved', array_keys($diff) === ['site_name', 'hmac_secret'], json_encode($diff));
check('the ordinary value is recorded both ways',
      ($diff['site_name']['from'] ?? '') === 'Old' && ($diff['site_name']['to'] ?? '') === 'New');
check('the credential is recorded as changed and NOTHING else',
      !empty($diff['hmac_secret']['changed'])
      && strpos(json_encode($diff), 'letmein') === false
      && strpos(json_encode($diff), 'hunter2') === false, json_encode($diff['hmac_secret']));

/* ── 2. writing a line never breaks the action ───────────────────────────── */

$before = (int)$db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
auditLog(null, 'settings.save', ['actor' => $actor]);                 // no database at all
auditLog($db, '', ['actor' => $actor]);                                // no action name
$after = (int)$db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
check('a call with nothing usable writes nothing and throws nothing', $after === $before);

// a detail bigger than the cap is replaced, not truncated mid-JSON
auditLog($db, 'settings.save', ['actor' => $actor, 'summary' => 'big',
                                'detail' => ['blob' => str_repeat('x', AUDIT_MAX_DETAIL * 2)]]);
$row = $db->query("SELECT detail FROM audit_log WHERE actor_name = 'audit-test' ORDER BY id DESC LIMIT 1")->fetchColumn();
$decoded = json_decode((string)$row, true);
check('an oversized detail is replaced by a note, and stays valid JSON',
      is_array($decoded) && !empty($decoded['truncated']), (string)$row);

/* ── 3. the shape a reader needs ─────────────────────────────────────────── */

auditLog($db, 'whitelist.ban', ['actor' => $actor, 'target_type' => 'hash',
                                'target_id' => str_repeat('ab', 20), 'summary' => 'banned by test']);
auditLog($db, 'login.fail', ['actor' => $actor, 'ok' => false, 'summary' => 'wrong password']);

$r = auditFetch($db, ['actor' => 'audit-test', 'per_page' => 50]);
check('entries come back newest first', $r['rows'][0]['action'] === 'login.fail', $r['rows'][0]['action'] ?? '?');
check('a failure is marked as one', $r['rows'][0]['ok'] === false);
check('the group is derived from the action',
      $r['rows'][0]['action_group'] === 'auth'
      && $r['rows'][1]['action_group'] === 'hashes', json_encode(array_column($r['rows'], 'action_group')));
check('an unknown action still lands in a group rather than nowhere', auditGroupOf('something.new') === 'other');

$onlyFailed = auditFetch($db, ['actor' => 'audit-test', 'failed_only' => true]);
check('the failure filter returns only failures',
      count($onlyFailed['rows']) === 1 && $onlyFailed['rows'][0]['action'] === 'login.fail');

$searched = auditFetch($db, ['actor' => 'audit-test', 'search' => 'banned by']);
check('search reaches the summary', count($searched['rows']) === 1
      && $searched['rows'][0]['action'] === 'whitelist.ban');
$byHash = auditFetch($db, ['actor' => 'audit-test', 'search' => substr(str_repeat('ab', 20), 0, 12)]);
check('and the target', count($byHash['rows']) === 1);

/* ── 4. the router half ──────────────────────────────────────────────────── */
//
// The map is what makes a new endpoint logged BY DEFAULT: anything not named still produces a line
// under its own endpoint name, and only the explicitly quiet list is dropped.

check('a known endpoint maps to a readable action', auditEndpointAction('admin/save_settings') === 'settings.save');
check('an unknown one is not in the map', auditEndpointAction('admin/something_new') === null);
check('polls and tests are recognised as noise',
      auditIsNoise('admin/net_test') && auditIsNoise('admin/whitelist_status') && auditIsNoise('admin/backup_status'));
check('a real action is not', !auditIsNoise('admin/whitelist_ban') && !auditIsNoise('admin/save_settings'));

// Every action named in the groups must be reachable: a group entry nobody ever writes is a filter
// option that always returns nothing.
$mapped = [];
foreach (['admin/save_settings', 'admin/whitelist_ban', 'admin/user_grant', 'admin/tracker_mode',
          'admin/login', 'admin/backup_action'] as $ep) {
    $a = auditEndpointAction($ep);
    if ($a !== null) $mapped[] = $a;
}
check('the endpoints that matter all map to something', count($mapped) === 6, implode(',', $mapped));

/* ── 5. retention ────────────────────────────────────────────────────────── */

check('the retention window is clamped to something sane',
      auditKeepDays(['audit_keep_days' => '0']) >= 7
      && auditKeepDays(['audit_keep_days' => '99999']) <= 3650
      && auditKeepDays([]) === AUDIT_KEEP_DAYS_DEFAULT);

$db->prepare("UPDATE audit_log SET at = NOW() - INTERVAL 400 DAY WHERE actor_name = 'audit-test'")->execute();
$removed = auditPrune($db, ['audit_keep_days' => '30']);
// Three rows: the oversized-detail one, the ban and the failed sign-in. The two calls with nothing
// usable wrote nothing, which is the point of the check above them.
check('pruning removes what is past the window', $removed === 3, (string)$removed);
check('and leaves nothing of the test behind',
      (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE actor_name = 'audit-test'")->fetchColumn() === 0);

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
