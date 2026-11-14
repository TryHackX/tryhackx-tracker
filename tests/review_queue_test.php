<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
$libs = ['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
         'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
         'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/auth.php',
         'includes/api_auth.php', 'includes/audit.php', 'includes/lang.php', 'includes/forumdescs.php', 'includes/reputation.php'];
foreach ($libs as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');

$H = fn(int $i): string => str_repeat('cd', 19) . sprintf('%02x', $i);
$tmpFiles = [];
$tidy = function () use ($db): void {
    $db->exec("DELETE FROM forum_descs WHERE info_hash LIKE 'cdcdcd%'");
    $db->exec("DELETE FROM wl_content_edits WHERE info_hash LIKE 'cdcdcd%'");
    $db->exec("DELETE FROM whitelist WHERE info_hash LIKE 'cdcdcd%'");
};
$tidy();

$reg = function (int $i, string $desc, string $fmt, string $status, string $at) use ($db, $H): int {
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, probe_status, description, description_format, content_status)
                  VALUES (?, ?, 'web', ?, 'passed', ?, ?, ?)")
       ->execute([$H($i), 'Queue release ' . $i, $at, $desc, $fmt, $status]);
    return (int)$db->lastInsertId();
};
$sha = fn(string $fmt, string $text): string => hash('sha256', $fmt . "\n" . $text);
$status = function (int $id) use ($db): string {
    $st = $db->prepare("SELECT content_status FROM whitelist WHERE id = ?");
    $st->execute([$id]);
    return (string)$st->fetchColumn();
};

$runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rq_runner_' . bin2hex(random_bytes(4)) . '.php';
$tmpFiles[] = $runner;
file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.48";
$_GET = [];
$_POST = $a["post"];
foreach ($a["libs"] as $f) require_once $f;
$db = getDb();
$cfg = getSettings($db);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, "en");
$GLOBALS["__audit_endpoint"] = "admin/wl_content";
require $a["file"];
');
$run = function (array $post) use ($root, &$tmpFiles, $runner, $libs): array {
    $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rq_args_' . bin2hex(random_bytes(4)) . '.json';
    $tmpFiles[] = $argFile;
    file_put_contents($argFile, json_encode(['root' => $root, 'file' => $root . '/api/admin/wl_content.php', 'post' => $post, 'libs' => $libs,
        'session' => ['loggedin' => true, 'admin_via_user' => 0, 'csrf_token' => 'rq-token'], 'sid' => 'rqtest' . bin2hex(random_bytes(8))]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner) . ' ' . escapeshellarg($argFile) . ' 2>&1');
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['__raw' => substr($out, 0, 600)];
};

try {
    $t1 = "rqtest one [img]https://pics.rq.test/a.png[/img]";
    $t2 = "rqtest two from the forum " . str_repeat('long words ', 120);
    $t4 = "rqtest four";
    $id1 = $reg(1, $t1, 'bbcode', 'pending', '2026-10-01 10:00:00');
    $id2 = $reg(2, $t2, 'flarum', 'pending', '2026-10-02 10:00:00');
    $id3 = $reg(3, 'rqtest three', 'bbcode', 'approved', '2026-10-03 10:00:00');
    $id4 = $reg(4, $t4, 'bbcode', 'rejected', '2026-10-04 10:00:00');
    $db->prepare("INSERT INTO forum_descs (info_hash, client_id, discussion_id, post_id, import_sha, state) VALUES (?, 1, 77, 1077, ?, 'imported')")
       ->execute([$H(2), $sha('flarum', $t2)]);
    $db->prepare("INSERT INTO wl_content_edits (whitelist_id, info_hash, description, description_format, kind, ip) VALUES (?, ?, 'rqtest three, better', 'bbcode', 'rewrite', '127.0.0.48')")
       ->execute([$id3, $H(3)]);
    $editId = (int)$db->lastInsertId();

    $j = $run(['op' => 'list', 'search' => 'rqtest']);
    $rows = $j['rows'] ?? [];
    check('the queue lists what waits, oldest first, with a fingerprint of each text and no text itself',
          count($rows) === 2 && $rows[0]['id'] === $id1 && $rows[1]['id'] === $id2 && $rows[0]['sha'] === $sha('bbcode', $t1)
          && !array_key_exists('description', $rows[0]) && ($j['total'] ?? 0) === 2, substr(json_encode($j), 0, 400));
    check('… with the pictures counted and the forum\'s text recognised', $rows[0]['pics'] === 1 && $rows[0]['from_forum'] === false
          && $rows[1]['from_forum'] === true, json_encode([$rows[0]['pics'] ?? null, $rows[0]['from_forum'] ?? null, $rows[1]['from_forum'] ?? null]));
    $j = $run(['op' => 'list', 'search' => 'rqtest', 'source' => 'forum']);
    check('the source filter: from the forum only', count($j['rows'] ?? []) === 1 && $j['rows'][0]['id'] === $id2 && $j['source'] === 'forum');
    $j = $run(['op' => 'list', 'search' => 'rqtest', 'source' => 'site', 'status' => 'all']);
    check('… written here only, in every state', array_column($j['rows'] ?? [], 'id') === [$id1, $id3, $id4], json_encode(array_column($j['rows'] ?? [], 'id')));
    $j = $run(['op' => 'list', 'search' => 'rqtest', 'sort' => 'long']);
    $k = $run(['op' => 'list', 'search' => 'rqtest', 'sort' => 'short', 'per' => 7]);
    check('longest and shortest first; a page size that is not offered is 25', array_column($j['rows'] ?? [], 'id') === [$id2, $id1]
          && array_column($k['rows'] ?? [], 'id') === [$id1, $id2] && ($k['per'] ?? 0) === 25);
    $j = $run(['op' => 'ids', 'search' => 'rqtest']);
    check('"select all that match" gets every id with its fingerprint', count($j['items'] ?? []) === 2
          && ($j['items'][0] ?? []) === ['kind' => 'wl', 'id' => $id1, 'sha' => $sha('bbcode', $t1)], json_encode($j));
    $j = $run(['op' => 'item', 'id' => $id1, 'kind' => 'wl']);
    check('a row opened is rendered as a visitor would see it', ($j['sha'] ?? '') === $sha('bbcode', $t1)
          && str_contains((string)($j['description_html'] ?? ''), 'rqtest one'), substr(json_encode($j), 0, 300));

    $j = $run(['op' => 'approve', 'id' => $id1, 'kind' => 'wl', 'sha' => $sha('bbcode', $t1 . ' older')]);
    check('approving a text that changed after it was opened is refused: changed', ($j['changed'] ?? false) === true && $status($id1) === 'pending', json_encode($j));
    $j = $run(['op' => 'approve', 'id' => $id1, 'kind' => 'wl', 'sha' => $sha('bbcode', $t1)]);
    check('… the text as it was opened is published', !empty($j['success']) && $status($id1) === 'approved', json_encode($j));

    $j = $run(['op' => 'bulk', 'action' => 'reject', 'note' => 'rq', 'items' => [['kind' => 'wl', 'id' => $id2, 'sha' => $sha('flarum', 'stale')],
                                                                                 ['kind' => 'wl', 'id' => 0]]]);
    check('a bulk action skips a changed text and a bad id, and says so', ($j['changed'] ?? -1) === 1 && ($j['failed'] ?? -1) === 1
          && ($j['done'] ?? -1) === 0 && $status($id2) === 'pending', json_encode($j));
    $j = $run(['op' => 'bulk', 'action' => 'reject', 'note' => 'rq', 'items' => [['kind' => 'wl', 'id' => $id2, 'sha' => $sha('flarum', $t2)]]]);
    check('… and does the rest: the forum\'s text turned down is remembered as declined', ($j['done'] ?? 0) === 1 && $status($id2) === 'rejected'
          && forumDescFor($db, $H(2))['state'] === 'declined', json_encode($j));

    $j = $run(['op' => 'edit_apply', 'id' => $editId, 'sha' => $sha('bbcode', 'something else')]);
    check('applying a proposal that changed after it was opened is refused', ($j['changed'] ?? false) === true, json_encode($j));
    $j = $run(['op' => 'edit_apply', 'id' => $editId, 'sha' => $sha('bbcode', 'rqtest three, better')]);
    check('… the one opened is applied', !empty($j['success']), json_encode($j));

    check('deleting a text from the queue withdraws its proposals too', str_contains($src('api/admin/wl_content.php'), 'contentDelete($db, $cfg,'));
    check('the queue lives in Moderation for anyone with the content right', str_contains($src('includes/auth.php'), "'any' => ['panel.whitelist.content']")
          && is_file($root . '/assets/js/admin-review.js') && str_contains($src('templates/admin/moderation.php'), 'admin-review.js'));
} finally {
    $tidy();
    foreach ($tmpFiles as $f) @unlink($f);
}

echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
