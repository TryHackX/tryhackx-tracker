<?php
/**
 * Test for includes/federation.php (needs the local test database):
 *   php tests/federation_test.php
 * Covers the export query/cursor and the peers CRUD helpers. The Python importer
 * (worker/federation.py) is exercised separately by its --self-test mode.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/index.php';
require_once $root . '/includes/api_auth.php';
require_once $root . '/includes/federation.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function h(int $i): string { return substr(sprintf('%040x', $i * 0x9E3779B1 + 7), -40); }

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$db->exec("TRUNCATE TABLE index_hashes");
$db->exec("TRUNCATE TABLE index_files");
$db->exec("TRUNCATE TABLE fed_peers");
$db->exec("DELETE FROM api_clients WHERE scope = 'federation'");

$cfgF = $cfg;
$cfgF['fed_enabled'] = '1'; $cfgF['fed_export_enabled'] = '1'; $cfgF['fed_export_files'] = '1';
$cfgF['fed_export_max_batch'] = '2000'; $cfgF['fed_node_name'] = 'test-node';

// ── 1. export rows + cursor ──────────────────────────────────────────────────
// 10 done rows resolved at t0+i, 3 not-done rows that must never be exported
$t0 = time() - 1000;
$ins = $db->prepare("INSERT INTO index_hashes (info_hash, name, first_seen, last_seen, last_seeders, last_leechers, seen_count,
                     meta_status, meta_fetched_at, total_size, files_count, piece_length)
                     VALUES (?, ?, NOW(), NOW(), 5, 2, 3, 'done', FROM_UNIXTIME(?), 1000, 2, 262144)");
for ($i = 1; $i <= 10; $i++) {
    $ins->execute([h($i), 'Torrent ' . $i, $t0 + $i]);
    $db->prepare("INSERT INTO index_files (info_hash, path, size) VALUES (?, ?, 500)")->execute([h($i), 'dir/file' . $i . '.bin']);
}
$db->exec("INSERT INTO index_hashes (info_hash, meta_status) VALUES ('" . h(90) . "', 'none'), ('" . h(91) . "', 'pending'), ('" . h(92) . "', 'failed')");

$p1 = fedExportRows($db, $cfgF, 0, '', 4, true);
check('page 1: 4 rows', count($p1['rows']) === 4, (string)count($p1['rows']));
check('page 1: has_more', $p1['has_more'] === true);
check('page 1: ordered by meta_fetched_at', $p1['rows'][0]['h'] === h(1) && $p1['rows'][3]['h'] === h(4));
check('row shape: name/size/sl/mf', $p1['rows'][0]['n'] === 'Torrent 1' && $p1['rows'][0]['s'] === 1000 && $p1['rows'][0]['sl'] === [5, 2] && $p1['rows'][0]['mf'] === $t0 + 1);
check('row carries files', isset($p1['rows'][0]['files']) && $p1['rows'][0]['files'][0][0] === 'dir/file1.bin');
$p2 = fedExportRows($db, $cfgF, $p1['next']['since'], $p1['next']['after'], 4, false);
check('page 2 continues after the cursor', count($p2['rows']) === 4 && $p2['rows'][0]['h'] === h(5));
check('files omitted when not requested', !isset($p2['rows'][0]['files']));
$p3 = fedExportRows($db, $cfgF, $p2['next']['since'], $p2['next']['after'], 4, false);
check('page 3 is the tail (2 rows, no more)', count($p3['rows']) === 2 && $p3['has_more'] === false);
check('never exports unresolved rows', !in_array(h(90), array_column($p3['rows'], 'h'), true));
$p4 = fedExportRows($db, $cfgF, $p3['next']['since'], $p3['next']['after'], 4, false);
check('cursor at the end → empty page', count($p4['rows']) === 0 && $p4['next'] === null);
// same-second tie-break: two rows at the same ts
$ins->execute([h(20), 'Same-sec A', $t0 + 500]);
$ins->execute([h(21), 'Same-sec B', $t0 + 500]);
$tie1 = fedExportRows($db, $cfgF, $t0 + 499, '', 1, false);
$tie2 = fedExportRows($db, $cfgF, $tie1['next']['since'], $tie1['next']['after'], 10, false);
$tieHashes = array_column($tie2['rows'], 'h');
$firstTie = min(h(20), h(21)); $secondTie = max(h(20), h(21));
check('same-second rows split cleanly across pages', $tie1['rows'][0]['h'] === $firstTie && in_array($secondTie, $tieHashes, true) && !in_array($firstTie, $tieHashes, true), json_encode([$tie1['rows'][0]['h'], $tieHashes]));

// ── 2. peers CRUD ────────────────────────────────────────────────────────────
$r = fedPeerSave($db, null, ['name' => 'peer-one', 'base_url' => 'https://other.example.org/', 'bearer' => '', 'pull_enabled' => 1, 'pull_files' => 1]);
check('peer created', isset($r['id']), json_encode($r));
$peerId = (int)$r['id'];
$peers = fedPeersList($db);
check('peers list has the peer (URL trimmed)', count($peers) === 1 && $peers[0]['base_url'] === 'https://other.example.org');
check('bearer never leaks via the list', !isset($peers[0]['bearer']) && $peers[0]['has_bearer'] === false);
check('bad URL rejected', isset(fedPeerSave($db, null, ['name' => 'x2', 'base_url' => 'ftp://nope'])['error']));
check('bad name rejected', isset(fedPeerSave($db, null, ['name' => '!', 'base_url' => 'https://x.org'])['error']));
check('bad bearer rejected', isset(fedPeerSave($db, $peerId, ['name' => 'peer-one', 'base_url' => 'https://other.example.org', 'bearer' => 'not-a-key'])['error']));
$ok = fedPeerSave($db, $peerId, ['name' => 'peer-one', 'base_url' => 'https://other.example.org', 'bearer' => str_repeat('a', 16) . '.' . str_repeat('b', 64), 'pull_enabled' => 1, 'pull_files' => 0]);
check('bearer accepted + stored', !isset($ok['error']) && fedPeersList($db)[0]['has_bearer'] === true);
check('duplicate name rejected', isset(fedPeerSave($db, null, ['name' => 'peer-one', 'base_url' => 'https://x.org'])['error']));
// inbound client lifecycle
$c = apiClientCreate($db, 'federation: peer-one', 'federation');
$db->prepare("UPDATE fed_peers SET api_client_id = ? WHERE id = ?")->execute([(int)$c['id'], $peerId]);
check('peer delete drops the inbound client too', fedPeerDelete($db, $peerId) === true
    && (int)$db->query("SELECT COUNT(*) FROM fed_peers")->fetchColumn() === 0
    && (int)$db->query("SELECT COUNT(*) FROM api_clients WHERE id = " . (int)$c['id'])->fetchColumn() === 0);

// ── 3. settings accessors ────────────────────────────────────────────────────
check('fedExportEnabled needs both flags', !fedExportEnabled(array_merge($cfg, ['fed_enabled' => '0', 'fed_export_enabled' => '1'])) && fedExportEnabled($cfgF));
check('fedExportMaxBatch clamps', fedExportMaxBatch(array_merge($cfg, ['fed_export_max_batch' => '999999'])) === 20000);

$db->exec("TRUNCATE TABLE index_hashes");
$db->exec("TRUNCATE TABLE index_files");

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
