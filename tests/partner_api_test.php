<?php
/**
 * The partner submission API — per-key approval, per-key required fields, the review queue
 * (needs the local test database):
 *
 *   php tests/partner_api_test.php
 *
 * ── the one thing this file exists for ─────────────────────────────────────────────────────────
 * A submission that is WAITING must not be in the file the tracker reads. Everything else here is
 * worth checking, but that one is the whole feature: "hold this partner's submissions for review"
 * means nothing at all if the tracker is already serving them while somebody decides. So that check
 * is not a grep — it writes a real pending row, runs the real generator against a real temporary
 * accesslist, and reads the file back.
 *
 * The HTTP side (a real bearer token against api.php) is proved in deploy/smoke_api.py; what is
 * proved here is what the database and the generator do, which is where the mistake would be silent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/schedule.php';
require_once $root . '/includes/index.php';
require_once $root . '/includes/api_auth.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$cfg = getSettings($db, true);

/* ── 1. the schema, in both the fresh path and the upgrade path ───────────── */
check('schema is at least 48', (int)($cfg['schema_version'] ?? 0) >= 48, (string)($cfg['schema_version'] ?? 'none'));

$wlCols = array_column($db->query("SHOW COLUMNS FROM whitelist")->fetchAll(PDO::FETCH_ASSOC), 'Type', 'Field');
foreach (['review_status', 'review_note', 'reviewed_at'] as $c) {
    check("whitelist.$c exists", isset($wlCols[$c]), implode(',', array_keys($wlCols)));
}
check("review_status is the four-value enum",
      str_contains((string)($wlCols['review_status'] ?? ''), "'none'")
      && str_contains((string)($wlCols['review_status'] ?? ''), "'pending'")
      && str_contains((string)($wlCols['review_status'] ?? ''), "'approved'")
      && str_contains((string)($wlCols['review_status'] ?? ''), "'rejected'"),
      (string)($wlCols['review_status'] ?? 'missing'));
// 'none' rather than 'approved' for the default: every row that existed before this feature was
// published directly, and calling that "approved" would claim a person looked at 159 rows nobody
// ever looked at.
$def = $db->query("SHOW COLUMNS FROM whitelist LIKE 'review_status'")->fetch(PDO::FETCH_ASSOC);
check("… and defaults to 'none', so nothing already published was retroactively 'approved'",
      ($def['Default'] ?? null) === 'none', var_export($def['Default'] ?? null, true));

$idx = array_column($db->query("SHOW INDEX FROM whitelist")->fetchAll(PDO::FETCH_ASSOC), 'Key_name');
check('idx_wl_review exists, so the queue is a lookup and not a scan', in_array('idx_wl_review', $idx, true));

$acCols = array_column($db->query("SHOW COLUMNS FROM api_clients")->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach (['auto_approve', 'required_fields'] as $c) {
    check("api_clients.$c exists", in_array($c, $acCols, true), implode(',', $acCols));
}
$acDef = $db->query("SHOW COLUMNS FROM api_clients LIKE 'auto_approve'")->fetch(PDO::FETCH_ASSOC);
check('a key publishes immediately unless somebody says otherwise', (string)($acDef['Default'] ?? '') === '1',
      var_export($acDef['Default'] ?? null, true));

$schemaSrc = (string)@file_get_contents($root . '/includes/schema.php');
foreach (['review_status', 'review_note', 'reviewed_at', 'idx_wl_review', 'auto_approve', 'required_fields'] as $c) {
    // Twice: once in trackerSchemaStatements() for a fresh install, once in the guarded ALTERs.
    check("$c is in the schema source at least twice (fresh install + upgrade)",
          substr_count($schemaSrc, $c) >= 2, (string)substr_count($schemaSrc, $c));
}

/* ── 2. the field list is a fixed vocabulary ──────────────────────────────── */
check('the three fields are the whole vocabulary', API_REQUIRED_FIELDS === ['name', 'url', 'source_id']);
check('a comma string is accepted', apiClientCleanFields('name,url') === ['name', 'url']);
check('an array is accepted', apiClientCleanFields(['url', 'source_id']) === ['url', 'source_id']);
check('nonsense is dropped rather than stored', apiClientCleanFields(['name', 'DROP TABLE', '../x']) === ['name']);
check('duplicates collapse', apiClientCleanFields('name,name,name') === ['name']);
check('empty stays empty', apiClientCleanFields('') === [] && apiClientCleanFields(null) === []);
check('case does not matter to a partner typing it', apiClientCleanFields('NAME, Url') === ['name', 'url']);

/* ── 3. the guide address is built from the three answers and carries no key ── */
$u = apiClientDocsUrl('whitelist', false, ['name', 'url']);
check('the guide address names the page', str_contains($u, 'action=apidocs'));
check('… the scope', str_contains($u, 'scope=whitelist'));
check('… the approval mode', str_contains($u, 'approve=review'));
check('… and the required fields', str_contains($u, 'fields=name%2Curl') || str_contains($u, 'fields=name,url'));
check('auto is the other value, not the absence of one', str_contains(apiClientDocsUrl('whitelist', true, []), 'approve=auto'));
check('no fields means no parameter at all', !str_contains(apiClientDocsUrl('whitelist', true, []), 'fields='));
// ONE ANSWER PER CHAPTER (1.73.0): approve= is what happens to a registration, block= what happens to a report.
// An abuse key's guide used to carry its creator's auto_approve — "blocks on arrival" for a key that holds
// reports for review — and an `all` key's reporting chapter borrowed the registrations' answer.
$ab = apiClientDocsUrl('abuse', true, ['reporter'], false);
check('an abuse key\'s guide says what happens to a REPORT: block=, its own answer', str_contains($ab, 'block=review'), $ab);
check('… and nothing about registrations it cannot make', !str_contains($ab, 'approve='), $ab);
check('… block=auto when the key blocks on arrival', str_contains(apiClientDocsUrl('abuse', false, [], true), 'block=auto'));
$al = apiClientDocsUrl('all', true, ['name', 'reporter'], false);
check('an `all` key\'s guide carries both answers, each its own', str_contains($al, 'approve=auto') && str_contains($al, 'block=review'), $al);
check('a key that sends nothing gets neither (users, shop, federation)',
      !preg_match('/approve=|block=|fields=/', apiClientDocsUrl('users', true, ['name']) . apiClientDocsUrl('shop', true, [])
                                              . apiClientDocsUrl('federation', false, [], true)));
$createSrc = (string)@file_get_contents($root . '/api/admin/api_client_create.php');
$fetchSrc = (string)@file_get_contents($root . '/api/admin/fetch_api_clients.php');
check('the new key\'s guide link gets the key\'s own blocking answer',
      str_contains($createSrc, 'apiClientDocsUrl((string)$c[\'scope\'], $autoApprove === 1, $fields, $autoBlock === 1)'));
check('… and so does every row of the key list',
      str_contains($fetchSrc, "apiClientDocsUrl((string)\$c['scope'], \$c['auto_approve'], \$c['required_fields'], \$c['abuse_auto_block'])"));
$wlJs = (string)@file_get_contents($root . '/assets/js/admin-whitelist.js');
check('the panel\'s live preview builds the same two answers',
      str_contains($wlJs, "if (submits) u.searchParams.set('approve'") && str_contains($wlJs, "if (reports) u.searchParams.set('block'")
      && str_contains($wlJs, 'docsUrlFor(v.scope, v.auto_approve !== 0, v.required_fields || [], v.abuse_auto_block === 1)'));
// The whole reason it can travel in the same mail as the key.
foreach (['key_id', 'secret', 'bearer', 'token'] as $word) {
    check("the address says nothing about the $word", !str_contains(strtolower($u), $word));
}

/* ── 4. the page renders every combination and none of them is a 500 ──────── */
// A page whose content is chosen by GET is a page somebody will hand a wrong GET to. Rendered for real (1.74.0,
// QUAL-7 — these were greps of the template), in a process of its own WITHOUT a database: a page that read one
// would fail there, which is the property "nothing on the page reads the database" is about.
$docRunner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pat_docs_' . getmypid() . '.php';
file_put_contents($docRunner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
foreach (["includes/functions.php", "includes/lang.php", "includes/api_auth.php", "includes/authbridge.php", "includes/pagecontent.php",
          "includes/federation.php", "includes/users.php"] as $f) require_once $f;
$cfg = $a["cfg"]; $_GET = $a["get"]; $baseUrl = "/";
langInit($cfg, "en");
ob_start();
require "templates/pages/apidocs.php";
echo json_encode(["html" => ob_get_clean()]);
');
$renderDocs = function (array $get) use ($docRunner, $root): ?string {
    $arg = $docRunner . '.' . bin2hex(random_bytes(3)) . '.json';
    file_put_contents($arg, json_encode(['root' => $root, 'get' => $get, 'cfg' => ['api_enabled' => '1', 'site_url' => 'https://tracker.example.org']]));
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($docRunner) . ' ' . escapeshellarg($arg) . ' 2>&1');
    @unlink($arg);
    $j = json_decode(trim($out), true);
    return is_array($j) ? (string)$j['html'] : null;
};
$bogus = $renderDocs(['scope' => 'no-such-scope', 'approve' => 'review', 'fields' => 'name,DROP TABLE,url']);
check('an unknown scope renders the default chapter (the whitelist\'s) instead of tripping — with no database at all',
      $bogus !== null && str_contains($bogus, 'v1/whitelist/submit'), substr((string)$bogus, 0, 200));
check('… the fields parameter goes through the same cleaner as the stored one: "DROP TABLE" is not on the page, name and url are',
      $bogus !== null && !str_contains($bogus, 'DROP TABLE') && str_contains($bogus, 'name, url'));
$auth = $renderDocs(['scope' => 'auth']);
check('… and an old ?scope=auth link still opens the accounts chapter it always was', $auth !== null && str_contains($auth, 'v1/users/lookup'));
@unlink($docRunner);
check('the page is told not to be indexed', str_contains((string)@file_get_contents($root . '/templates/layout.php'), 'apidocs')
      && str_contains((string)@file_get_contents($root . '/templates/layout.php'), 'noindex'));

/* ── 5. the submit endpoint honours both per-key answers ──────────────────── */
// Proved by a real submission in section 11 (1.74.0, QUAL-7: these were greps of the endpoint): a key that holds
// for review gets `pending` back and its row is stored waiting, the reply repeats the key's two answers, an item
// that lacks a required field is refused alone, and an `all` key is asked only for what a registration can carry.
// What the store does with the review column is section 6: the generator leaves a waiting row out of the file.

/* ── 6. THE ONE THAT MATTERS: a waiting row is not in the file ────────────── */
// Against the real generator, the real table and a real file on disk. Everything above could be
// green while this is wrong, and if this is wrong the feature is a lie.
$mk = static fn(string $tag) => str_pad(substr(hash('sha1', 'partner-test-' . $tag), 0, 40), 40, '0');
$hPending  = $mk('pending');
$hApproved = $mk('approved');
$hNone     = $mk('none');
$hRejected = $mk('rejected');
$all = [$hPending, $hApproved, $hNone, $hRejected];

$db->prepare("DELETE FROM whitelist WHERE info_hash IN (?,?,?,?)")->execute($all);
$ins = $db->prepare("INSERT INTO whitelist (info_hash, name, source, review_status, banned, probe_status)
                     VALUES (?, ?, 'api', ?, 0, 'none')");
$ins->execute([$hPending,  'partner test pending',  'pending']);
$ins->execute([$hApproved, 'partner test approved', 'approved']);
$ins->execute([$hNone,     'partner test none',     'none']);
$ins->execute([$hRejected, 'partner test rejected', 'rejected']);

$tmpDir = sys_get_temp_dir() . '/thx_partner_' . bin2hex(random_bytes(4));
@mkdir($tmpDir, 0777, true);
$tmpFile = $tmpDir . '/whitelist';
$saved = $cfg['whitelist_path'] ?? null;
$cfgGen = array_merge($cfg, ['whitelist_path' => $tmpFile]);
$res = null;
try {
    $res = whitelistRegenerate($db, $cfgGen);
} catch (\Throwable $e) {
    $res = ['ok' => false, 'error' => $e->getMessage()];
}
$written = is_file($tmpFile) ? (string)file_get_contents($tmpFile) : '';
check('the generator wrote a file', $written !== '', json_encode($res));
check('a WAITING submission is NOT in the file the tracker reads', !str_contains($written, $hPending));
check('a turned-down submission is not in it either', !str_contains($written, $hRejected));
check('an approved submission IS in it', str_contains($written, $hApproved));
check('a row that never went through review is in it, as it always was', str_contains($written, $hNone));

/* ── 7. approving is what puts it there, and only from 'pending' ──────────── */
// Mirrors api/admin/whitelist_review.php: the UPDATE is guarded on review_status = 'pending' so a
// bulk selection cannot quietly overturn a colleague decision an hour later.
$up = $db->prepare("UPDATE whitelist SET review_status = 'approved', reviewed_at = NOW()
                     WHERE review_status = 'pending' AND info_hash = ?");
$up->execute([$hPending]);
check('approving a waiting row changes exactly one row', $up->rowCount() === 1, (string)$up->rowCount());
$up->execute([$hRejected]);
check('… and the same UPDATE leaves a turned-down row alone', $up->rowCount() === 0, (string)$up->rowCount());

whitelistRegenerate($db, $cfgGen);
$written2 = is_file($tmpFile) ? (string)file_get_contents($tmpFile) : '';
check('after approval the tracker serves it', str_contains($written2, $hPending));
check('and still not the one that was turned down', !str_contains($written2, $hRejected));

$revSrc = (string)@file_get_contents($root . '/api/admin/whitelist_review.php');
check("the review endpoint updates only rows that are waiting", str_contains($revSrc, "review_status = 'pending'"));
check('… is behind the content permission', str_contains($revSrc, "panel.whitelist.content"));
check('… regenerates the accesslist when it approves', str_contains($revSrc, 'whitelistRegenerate'));
check('… and never deletes anything', !preg_match('/\bDELETE\b/i', $revSrc));

/* ── 8. the panel can see who sent it ─────────────────────────────────────── */
$listSrc = (string)@file_get_contents($root . '/api/admin/fetch_whitelist.php');
check('the list carries the review state', str_contains($listSrc, 'review_status'));
check('… and resolves the partner to a name, in one query for the page',
      str_contains($listSrc, 'api_client_label') && str_contains($listSrc, 'id IN ($ph)'));
check('the queue can be filtered down to what is waiting',
      preg_match("/\\['none',\s*'pending',\s*'approved',\s*'rejected'\\]/", $listSrc) === 1);
$jsSrc = (string)@file_get_contents($root . '/assets/js/admin-whitelist.js');
check('the table draws the partner beside the row', str_contains($jsSrc, 'api_client_label'));
check('the details panel draws the review state', str_contains($jsSrc, "js.wl.review_"));
check('the key editor offers both per-key settings',
      str_contains($jsSrc, 'auto_approve') && str_contains($jsSrc, 'required_fields'));
check('the guide link is copyable from the key row', str_contains($jsSrc, 'docs_url'));

/* ── 9. the FAST PATH holds a waiting row back too (1.73.0) ───────────────── */
// The generator was right; the add path was not. In whitelist mode whitelistAddHashes() APPENDS what it
// added to the live file — and it appended a held row as well, so a partner's waiting submission was served
// from the second it arrived until the next full regeneration. The file is seeded first, so the append path
// (not a regeneration of an empty file) is the one under test.
require_once $root . '/includes/wlprobe.php';
$hHeld = $mk('fastpath-held');
$hDirect = $mk('fastpath-direct');
$hProbing = $mk('probe-probing');
$hFailed = $mk('probe-failed');
$fast = [$hHeld, $hDirect, $hProbing, $hFailed];
$db->prepare("DELETE FROM whitelist WHERE info_hash IN (?,?,?,?)")->execute($fast);
$cfgLive = array_merge($cfg, ['whitelist_path' => $tmpFile, 'tracker_mode' => 'whitelist', 'opentracker_service_name' => '']);
file_put_contents($tmpFile, str_repeat('a', 40) . "\n");   // a non-empty, writable file: the append path
$add = function (string $hash, string $review) use ($db, $cfgLive) {
    return whitelistAddHashes($db, $cfgLive, [['input' => $hash, 'hash' => $hash, 'name' => 'fast path test']],
        ['source' => 'api', 'ip' => '127.0.0.1', 'review' => $review]);
};
$r1 = $add($hHeld, 'pending');
$file1 = (string)@file_get_contents($tmpFile);
check('a held registration is stored', ($r1['summary']['added'] ?? 0) === 1, json_encode($r1['summary'] ?? $r1));
check('… and NOT appended to the file the tracker reads', !str_contains($file1, $hHeld), $file1);
$r2 = $add($hDirect, 'none');
$file2 = (string)@file_get_contents($tmpFile);
check('a registration that publishes directly IS appended at once', str_contains($file2, $hDirect), json_encode($r2['file'] ?? null));
check('… and the held one is still not there', !str_contains($file2, $hHeld));

/* ── 10. a registration proving itself is served while it does; a failed one leaves at once (1.73.0) ─ */
// Its proof is a peer announcing HERE, and a whitelist-mode tracker refuses the announces of a hash its list
// does not carry — so a probe whose hash is not served can never pass (includes/wlprobe.php).
$pi = $db->prepare("INSERT INTO whitelist (info_hash, name, source, banned, probe_status, probe_started_at, meta_status)
                    VALUES (?, 'probe test', 'web', 0, ?, NOW() - INTERVAL 1 MINUTE, ?)");
$pi->execute([$hProbing, 'probing', 'pending']);
$pi->execute([$hFailed, 'failed', 'failed']);
whitelistRegenerate($db, $cfgLive);
$file3 = (string)@file_get_contents($tmpFile);
check('a full regeneration keeps a registration that is still proving itself', str_contains($file3, $hProbing));
check('… and leaves out one that failed', !str_contains($file3, $hFailed));
check('… and still leaves out the held one', !str_contains($file3, $hHeld));
// The probe fails it (its metadata could not be fetched): the tick withdraws it from the file at once.
$db->prepare("UPDATE whitelist SET meta_status = 'failed' WHERE info_hash = ?")->execute([$hProbing]);
$cfgProbe = array_merge($cfgLive, ['wl_probe_required' => '1', 'wl_probe_on_fail' => 'keep']);
$tick = wlProbeTick($db, $cfgProbe);
$file4 = (string)@file_get_contents($tmpFile);
$st = $db->prepare("SELECT probe_status FROM whitelist WHERE info_hash = ?");
$st->execute([$hProbing]);
check('the probe gives up on it', $st->fetchColumn() === 'failed', json_encode($tick));
check('… and the file no longer carries it — no waiting for some other regeneration (the tick regenerated on a failure)',
      !str_contains($file4, $hProbing), json_encode($tick));
$db->prepare("DELETE FROM whitelist WHERE info_hash IN (?,?,?,?)")->execute($fast);

/* ── 11. over HTTP: an `all` key that asks for a reporter still registers (1.73.0) ─────────────── */
// The endpoint read the key's required fields raw: an `all` key's list holds the report's fields too, and a
// whitelist item can never carry `reporter`, so every item came back invalid, missing_reporter.
$site = rtrim(getenv('VERIFY_BASE') ?: 'http://127.0.0.1:8089/', '/') . '/';
$probe = @file_get_contents($site . '?action=tos', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
if ($probe === false || !function_exists('curl_init')) {
    echo "SKIP the HTTP half: the local site is not answering at $site\n";
} else {
    $apiWas = $cfg['api_enabled'] ?? null;
    $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('api_enabled', '1') ON DUPLICATE KEY UPDATE `value` = '1'")->execute();
    $db->prepare("DELETE FROM api_clients WHERE label = 'partner-api-test-all'")->execute();
    $key = apiClientCreate($db, 'partner-api-test-all', 'all');
    // Held for review, so nothing this sends is served (the fast path above).
    $db->prepare("UPDATE api_clients SET auto_approve = 0, abuse_auto_block = 0, required_fields = 'name,reporter' WHERE id = ?")
       ->execute([(int)$key['id']]);
    $hNamed = $mk('http-named'); $hBare = $mk('http-bare');
    $db->prepare("DELETE FROM whitelist WHERE info_hash IN (?, ?)")->execute([$hNamed, $hBare]);
    $c = curl_init($site . 'api.php?endpoint=v1/whitelist/submit');
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key['key_id'] . '.' . $key['secret']],
        CURLOPT_POSTFIELDS => json_encode(['items' => [['hash' => $hNamed, 'name' => 'Named release'], ['hash' => $hBare]]])]);
    $body = (string)curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    $j = json_decode($body, true) ?: [];
    check('the `all` key\'s submission is answered 200', $code === 200, $code . ' ' . substr($body, 0, 300));
    check('… an item with a name is taken (held for review, as the key says), not refused for a reporter',
          ($j['results'][0]['status'] ?? '') === 'pending', json_encode($j['results'][0] ?? null));
    check('… an item without one is refused for the field it lacks: the name',
          ($j['results'][1]['status'] ?? '') === 'invalid' && ($j['results'][1]['error'] ?? '') === 'missing_name',
          json_encode($j['results'][1] ?? null));
    check('… and the reply names only the fields a registration is asked for', ($j['required_fields'] ?? null) === ['name'],
          json_encode($j['required_fields'] ?? null));
    // The key's approval answer, read by the endpoint and said back (1.74.0, QUAL-7: this was a grep of the endpoint).
    check('… the reply repeats the key\'s approval answer: held, not published', ($j['auto_approve'] ?? null) === false, json_encode($j['auto_approve'] ?? null));
    $stored = $db->prepare("SELECT review_status, api_client_id FROM whitelist WHERE info_hash = ?");
    $stored->execute([$hNamed]);
    $sr = $stored->fetch(PDO::FETCH_ASSOC) ?: [];
    check('… and the row is stored waiting for a person, under the key that sent it',
          ($sr['review_status'] ?? '') === 'pending' && (int)($sr['api_client_id'] ?? 0) === (int)$key['id'], json_encode($sr));
    $db->prepare("DELETE FROM whitelist WHERE info_hash IN (?, ?)")->execute([$hNamed, $hBare]);
    $db->prepare("DELETE FROM api_clients WHERE id = ?")->execute([(int)$key['id']]);
    if ($apiWas === null) $db->prepare("DELETE FROM settings WHERE `key` = 'api_enabled'")->execute();
    else $db->prepare("UPDATE settings SET `value` = ? WHERE `key` = 'api_enabled'")->execute([$apiWas]);
}

/* ── clean up ─────────────────────────────────────────────────────────────── */
$db->prepare("DELETE FROM whitelist WHERE info_hash IN (?,?,?,?)")->execute($all);
if (is_file($tmpFile)) @unlink($tmpFile);
foreach ((array)@glob($tmpDir . '/*') as $f) @unlink($f);
@rmdir($tmpDir);
// The live file has to be right again: the run above regenerated against a temporary path, but a
// later failure must not leave the real one describing a database that has since changed.
if ($saved !== null) { try { whitelistRegenerate($db, $cfg); } catch (\Throwable $e) { /* not this test's business */ } }

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
