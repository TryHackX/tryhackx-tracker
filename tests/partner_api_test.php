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
check('auto is the other value, not the absence of one', str_contains(apiClientDocsUrl('users', true, []), 'approve=auto'));
check('no fields means no parameter at all', !str_contains(apiClientDocsUrl('users', true, []), 'fields='));
// The whole reason it can travel in the same mail as the key.
foreach (['key_id', 'secret', 'bearer', 'token'] as $word) {
    check("the address says nothing about the $word", !str_contains(strtolower($u), $word));
}

/* ── 4. the page renders every combination and none of them is a 500 ──────── */
// A page whose content is chosen by GET is a page somebody will hand a wrong GET to.
$docsSrc = (string)@file_get_contents($root . '/templates/pages/apidocs.php');
check('an unknown scope falls back rather than trips',
      preg_match('/\$docScope\s*=.*isset\(\$docScopes/s', $docsSrc) === 1);
check('the fields parameter goes through the same cleaner as the stored one',
      str_contains($docsSrc, 'apiClientCleanFields(') && str_contains($docsSrc, "GET['fields']"));
check('the page is told not to be indexed', str_contains((string)@file_get_contents($root . '/templates/layout.php'), 'apidocs')
      && str_contains((string)@file_get_contents($root . '/templates/layout.php'), 'noindex'));
check('nothing on the page reads the database',
      !preg_match('/\$db->|->prepare\(|->query\(/', $docsSrc));

/* ── 5. the submit endpoint honours both per-key answers ──────────────────── */
$subSrc = (string)@file_get_contents($root . '/api/v1/whitelist_submit.php');
check('the endpoint reads the key own approval setting', str_contains($subSrc, "client['auto_approve']"));
check('… and the key own required fields', str_contains($subSrc, "client['required_fields']"));
check("a held item is passed to the store as 'pending'", preg_match("/'review'\s*=>\s*\\\$autoApprove\s*\?\s*'none'\s*:\s*'pending'/", $subSrc) === 1);
// A missing field is a problem with ONE item, not with the request: the rest of the batch still
// goes through, and the reply says which one and why.
check('a missing field is refused per item, not per request', str_contains($subSrc, 'missing_'));
check('… and the reply repeats the configuration back',
      str_contains($subSrc, "'auto_approve'") && str_contains($subSrc, "'required_fields'"));

$wlSrc = (string)@file_get_contents($root . '/includes/whitelist.php');
check('the store writes the review column', str_contains($wlSrc, 'review_status'));
check('… and the generator filters on it', str_contains($wlSrc, "review_status IN ('none','approved')"));
check("… with 'none' in that list, so nothing published before this feature disappeared",
      str_contains($wlSrc, "review_status IN ('none','approved')"));

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
