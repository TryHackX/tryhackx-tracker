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

/* ── 4b. an endpoint that reads by POST writes no line (1.69.0) ─────────────── */
//
// admin/bulk_send answers the Users page's bulk-mail tab by POST — the preview of an audience, the
// rendered body, the recent batches, a batch's progress — and the router logs every POST to an admin
// endpoint: two `bulk.queue` lines per visit to the tab, with nothing queued. Run as the request it is
// (the endpoint FILE in a child process, a panel session, the router's audit hook), each read must
// leave the log as it was, while a queue, a cancel and a test copy each write their own line — here
// all three refused before anything could be sent (no password; mail switched off for the test copy).
$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'au_runner_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/richtext.php", "includes/mail.php", "includes/bulkmail.php", "includes/users.php", "includes/audit.php",
          "includes/auth.php", "includes/lang.php", "includes/twofa.php", "includes/federation.php", "includes/schedule.php",
          "includes/livesync.php", "includes/content.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
$_SESSION = ["loggedin" => true, "login_time" => time(), "last_activity" => time()];
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
$GLOBALS["__audit_endpoint"] = "admin/" . $a["endpoint"];
require "api/admin/" . $a["endpoint"] . ".php";
');
// One POST to an admin endpoint FILE, as the router runs it (1.69.0: any endpoint, bulk_send first).
$adminRun = function (string $endpoint, array $post, array $cfgExtra = []) use ($root, $runner): array {
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'au_args_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($argFile, json_encode(['root' => $root, 'endpoint' => $endpoint, 'post' => $post, 'cfg' => $cfgExtra, 'sid' => 'autest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    @unlink($argFile);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 300)];
};
$bulkRun = fn(array $post, array $cfgExtra = []): array => $adminRun('bulk_send', $post, $cfgExtra);
$bulkLines = function (int $floor) use ($db): array {
    return array_map(fn($r) => [(string)$r[0], (int)$r[1]],
        $db->query("SELECT action, ok FROM audit_log WHERE id > " . $floor . " AND action LIKE 'bulk.%' ORDER BY id")->fetchAll(PDO::FETCH_NUM));
};
$floor = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$reads = [];
foreach ([['op' => 'preview', 'audience' => ['kind' => 'all']], ['op' => 'batches'], ['op' => 'render', 'body' => 'hi', 'format' => 'plain'],
          ['op' => 'status', 'batch_id' => 'abc123']] as $post) {
    $reads[$post['op']] = $bulkRun($post);
}
check('the bulk-mail tab\'s four reads answer as before (preview, batches, render, status)',
      !empty($reads['preview']['success']) && !empty($reads['batches']['success']) && isset($reads['render']['html']) && !empty($reads['status']['success']),
      json_encode(array_map(fn($r) => array_slice($r, 0, 3), $reads)));
check('… and write no line in the log', $bulkLines($floor) === [], json_encode($bulkLines($floor)));
$q = $bulkRun(['op' => 'queue', 'password' => '', 'audience' => ['kind' => 'all'], 'subject' => 's', 'body' => 'b', 'email' => true]);
$c = $bulkRun(['op' => 'cancel', 'password' => '', 'batch_id' => 'abc123']);
$tc = $bulkRun(['op' => 'test', 'subject' => 's', 'body' => 'b'], ['bulk_mail_enabled' => '0']);
$lines = $bulkLines($floor);
check('a queue, a cancel and a test copy each write one line, under their own names, refused (nothing was sent)',
      !empty($q['error']) && !empty($c['error']) && !empty($tc['error'])
      && $lines === [['bulk.queue', 0], ['bulk.cancel', 0], ['bulk.test', 0]], json_encode([$lines, $q, $c, $tc]));
check('… all three in the mail group', auditGroupOf('bulk.queue') === 'mail' && auditGroupOf('bulk.cancel') === 'mail' && auditGroupOf('bulk.test') === 'mail');
$db->exec("DELETE FROM audit_log WHERE id > " . $floor . " AND action LIKE 'bulk.%'");

// The same fault, found by opening every panel page and tab and reading the log after each (1.69.0):
// Settings asked admin/twofa for its status and admin/fed_review for its queue, and the Whitelist's
// Review tab asked admin/wl_content for the queue and the rewrites — `twofa.change` on a mere visit
// would alarm anybody who reads the log. The other reads that answer by POST — a tracker mode's status,
// live sync's status and plan, the previews of the firewall, sysctl and OpenTracker files, a cluster
// node's plan, a federation purge's count — were logged under their apply's name the same way. Each
// read writes nothing now; a decision still writes its line — a content decision under its own name,
// which the Content group lists and nothing wrote until now (they were all `content.review`).
$lines = function (int $floor) use ($db): array {
    return array_map(fn($r) => [(string)$r[0], (int)$r[1]],
        $db->query("SELECT action, ok FROM audit_log WHERE id > " . $floor . " AND actor_name <> 'audit-test' ORDER BY id")->fetchAll(PDO::FETCH_NUM));
};
$floor2 = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
$reads = [
    'twofa status'           => $adminRun('twofa', ['op' => 'status']),
    'fed_review counts'      => $adminRun('fed_review', ['op' => 'counts']),
    'fed_review list'        => $adminRun('fed_review', ['op' => 'list']),
    'wl_content list'        => $adminRun('wl_content', ['op' => 'list']),
    'wl_content edits'       => $adminRun('wl_content', ['op' => 'edits']),
    'tracker_mode status'    => $adminRun('tracker_mode', ['op' => 'status']),
    'livesync_apply status'  => $adminRun('livesync_apply', ['op' => 'status']),
    'livesync_apply plan'    => $adminRun('livesync_apply', ['op' => 'plan']),
    'net_apply preview'      => $adminRun('net_apply', ['op' => 'preview']),
    'sysctl_apply preview'   => $adminRun('sysctl_apply', ['op' => 'preview']),
    'ot_apply preview'       => $adminRun('ot_apply', ['op' => 'preview']),
    'ot_cluster_apply plan'  => $adminRun('ot_cluster_apply', ['op' => 'plan', 'name' => 'edge-a']),
    'fed_purge count'        => $adminRun('fed_purge', ['op' => 'count', 'peer' => 'nobody']),
];
check('the reads the panel\'s pages make by POST answer as before (2FA status, the federation and content queues)',
      isset($reads['twofa status']['enabled']) && !empty($reads['fed_review counts']['success']) && !empty($reads['fed_review list']['success'])
      && !empty($reads['wl_content list']['success']) && isset($reads['wl_content edits']['success']),
      json_encode(array_map(fn($r) => array_slice($r, 0, 3), array_slice($reads, 0, 5, true))));
check('… and none of the thirteen reads — answered or refused — writes a line', $lines($floor2) === [], json_encode($lines($floor2)));
$d1 = $adminRun('twofa', ['op' => 'begin', 'password' => '']);
$d2 = $adminRun('fed_review', ['op' => 'accept']);
$d3 = $adminRun('wl_content', ['op' => 'approve', 'id' => 0, 'kind' => 'wl']);
$d4 = $adminRun('tracker_mode', ['op' => 'switch', 'mode' => 'whitelist', 'password' => '']);
$dl = $lines($floor2);
check('… while a decision still writes one: 2FA set up, a federation accept, a description approved, a tracker switch (all refused here)',
      !empty($d1['error']) && !empty($d2['error']) && !empty($d3['error']) && !empty($d4['error'])
      && $dl === [['twofa.change', 0], ['panel.fed_review', 0], ['content.approve', 0], ['tracker.mode', 0]], json_encode([$dl, $d1, $d2, $d3, $d4]));
check('… a content decision under its own name, in the Content group', auditGroupOf('content.approve') === 'content' && auditGroupOf('content.edit_reject') === 'content');
$db->exec("DELETE FROM audit_log WHERE id > " . $floor2 . " AND actor_name <> 'audit-test'");
@unlink($runner);

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
