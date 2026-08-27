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
// ── 4. per-client rate limits on the S2S API ────────────────────────────────
// The ban machinery only ever reacted to BAD authentication, so a valid key — a peer pulling too
// eagerly, or a key that leaked — could not be slowed down at all. These budgets are the answer.
check('rate accessor: default is 60/min', apiRateLimitPerMin([]) === 60);
check('rate accessor: 0 means unlimited and survives', apiRateLimitPerMin(['api_rate_limit_per_min' => '0']) === 0);
check('rate accessor: rubbish falls back to 0, never negative', apiRateLimitPerMin(['api_rate_limit_per_min' => '-5']) === 0);
check('rate accessor: absurd values are capped', apiRateLimitPerMin(['api_rate_limit_per_min' => '9999999']) === 100000);
check('byte accessor: default is 5 GB', apiRateLimitBytesDay([]) === 5368709120);
check('byte accessor: 0 means unlimited', apiRateLimitBytesDay(['api_rate_limit_bytes_day' => '0']) === 0);

$db->exec("DELETE FROM api_clients WHERE label LIKE 'rl-test%'");
$rc = apiClientCreate($db, 'rl-test client', 'federation');
$rcId = (int)$rc['id'];
$cfgRl = array_merge($cfg, ['api_rate_limit_per_min' => '3', 'api_rate_limit_bytes_day' => '1000',
                            'api_ban_exempt_ips' => '']);   // exempt list off, or the test host skips the limiter
$client = ['id' => $rcId, 'label' => 'rl-test client'];
$counters = static function (PDO $db, int $id): array {
    $r = $db->query("SELECT rl_min_count, rl_day_bytes, rl_blocked_count FROM api_clients WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    return ['min' => (int)$r['rl_min_count'], 'bytes' => (int)$r['rl_day_bytes'], 'blocked' => (int)$r['rl_blocked_count']];
};
check('schema: the counters exist on api_clients', $counters($db, $rcId) === ['min' => 0, 'bytes' => 0, 'blocked' => 0]);

// apiRateLimit() exits the process when it refuses, so drive the counter statement directly: it is
// the piece with the ordering trap (MariaDB evaluates SET left to right, so the count must be
// written before the window start it tests, or every request would look like a fresh window).
$bump = static function (PDO $db, int $id, int $bytes) {
    $db->prepare("UPDATE api_clients SET
            rl_min_count = IF(rl_min_start IS NULL OR rl_min_start <= NOW() - INTERVAL 60 SECOND, 1, rl_min_count + 1),
            rl_min_start = IF(rl_min_start IS NULL OR rl_min_start <= NOW() - INTERVAL 60 SECOND, NOW(), rl_min_start),
            rl_day_bytes = IF(rl_day IS NULL OR rl_day <> CURDATE(), ?, rl_day_bytes + ?),
            rl_day = CURDATE()
         WHERE id = ?")->execute([$bytes, $bytes, $id]);
};
$bump($db, $rcId, 100);
check('counter: the first request opens the window at 1', $counters($db, $rcId)['min'] === 1);
$bump($db, $rcId, 100);
$bump($db, $rcId, 100);
$c3 = $counters($db, $rcId);
check('counter: it really counts up rather than resetting', $c3['min'] === 3, json_encode($c3));
check('counter: bytes accumulate alongside', $c3['bytes'] === 300, json_encode($c3));
$bump($db, $rcId, 100);
check('counter: the 4th request is over a budget of 3', $counters($db, $rcId)['min'] === 4);

// an expired window must start again at 1, not carry the old count forward
$db->prepare("UPDATE api_clients SET rl_min_start = NOW() - INTERVAL 61 SECOND WHERE id = ?")->execute([$rcId]);
$bump($db, $rcId, 50);
$cw = $counters($db, $rcId);
check('counter: a minute later the window starts over', $cw['min'] === 1, json_encode($cw));
check('counter: … but the daily bytes do NOT reset with it', $cw['bytes'] === 450, json_encode($cw));

// a new day resets the byte budget without touching the request window
$db->prepare("UPDATE api_clients SET rl_day = CURDATE() - INTERVAL 1 DAY WHERE id = ?")->execute([$rcId]);
$bump($db, $rcId, 7);
$cd = $counters($db, $rcId);
check('counter: a new day starts the byte budget from this request', $cd['bytes'] === 7, json_encode($cd));

// what the endpoints call to charge a reply they have already produced
apiChargeBytes($db, ['id' => $rcId], 1000);
check('apiChargeBytes adds what we SENT to the same budget', $counters($db, $rcId)['bytes'] === 1007);
apiChargeBytes($db, ['id' => $rcId], 0);
check('apiChargeBytes ignores a zero-byte reply', $counters($db, $rcId)['bytes'] === 1007);
apiChargeBytes($db, ['id' => 0], 500);
check('apiChargeBytes ignores an unknown client', $counters($db, $rcId)['bytes'] === 1007);

// the exempt list covers these budgets too — the operator's own integrations must not throttle
check('the never-ban list also exempts from the rate limit',
      apiIpExempt('127.0.0.1', array_merge($cfg, ['api_ban_exempt_ips' => '127.0.0.1, ::1'])) === true);
$db->exec("DELETE FROM api_clients WHERE id = $rcId");

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
// regression: the OPEN second may still receive commits — rows stamped now/future must not be
// served yet (a cursor landing inside that second would skip later same-second commits forever)
$ins->execute([h(30), 'Open-second row', time() + 3]);
$fresh = fedExportRows($db, $cfgF, 0, '', 100, false);
check('rows in the open/future second are held back', !in_array(h(30), array_column($fresh['rows'], 'h'), true), json_encode(array_column($fresh['rows'], 'h')));
$db->exec("DELETE FROM index_hashes WHERE info_hash = '" . h(30) . "'");
// regression: locally banned / whitelisted hashes must never leave the node, even before the next poll purge
$db->exec("INSERT INTO banned_hashes (info_hash) VALUES ('" . h(1) . "')");
$db->exec("INSERT INTO whitelist (info_hash) VALUES ('" . h(2) . "')");
$clean = fedExportRows($db, $cfgF, 0, '', 100, false);
$cleanHashes = array_column($clean['rows'], 'h');
check('banned + whitelisted hashes are excluded from the export', !in_array(h(1), $cleanHashes, true) && !in_array(h(2), $cleanHashes, true) && count($cleanHashes) === 10, json_encode($cleanHashes));
$db->exec("DELETE FROM banned_hashes WHERE info_hash = '" . h(1) . "'");
$db->exec("DELETE FROM whitelist WHERE info_hash = '" . h(2) . "'");

// ── 2. peers CRUD ────────────────────────────────────────────────────────────
$r = fedPeerSave($db, null, ['name' => 'peer-one', 'base_url' => 'https://other.example.org/', 'bearer' => '', 'pull_enabled' => 1, 'pull_files' => 1]);
check('peer created', isset($r['id']), json_encode($r));
$peerId = (int)$r['id'];
$peers = fedPeersList($db);
check('peers list has the peer (URL trimmed)', count($peers) === 1 && $peers[0]['base_url'] === 'https://other.example.org');
check('bearer never leaks via the list', !isset($peers[0]['bearer']) && $peers[0]['has_bearer'] === false);
check('bad URL rejected', isset(fedPeerSave($db, null, ['name' => 'x2', 'base_url' => 'ftp://nope'])['error']));
// The bearer we hold for a peer travels in a header on every single pull, so http is not a
// preference — it would put a key that reads our whole resolved index on the wire in clear text.
$httpTry = fedPeerSave($db, null, ['name' => 'x-http', 'base_url' => 'http://plain.example.org']);
check('http:// peer URL rejected', isset($httpTry['error']));
check('… and the message says why', str_contains(strtolower((string)($httpTry['error'] ?? '')), 'https'), (string)($httpTry['error'] ?? ''));
check('… and nothing was written', (int)$db->query("SELECT COUNT(*) FROM fed_peers WHERE name = 'x-http'")->fetchColumn() === 0);
check('HTTPS:// in capitals is still accepted', !isset(fedPeerSave($db, null, ['name' => 'x-caps', 'base_url' => 'HTTPS://caps.example.org'])['error']));
$db->exec("DELETE FROM fed_peers WHERE name = 'x-caps'");
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

// regression: the INSERT path must lowercase a pasted mixed-case bearer (federation.py matches lowercase)
$ru = fedPeerSave($db, null, ['name' => 'peer-upper', 'base_url' => 'https://up.example.org',
    'bearer' => strtoupper(str_repeat('a', 16)) . '.' . strtoupper(str_repeat('b', 64)), 'pull_enabled' => 0, 'pull_files' => 1]);
$storedBearer = $db->query("SELECT bearer FROM fed_peers WHERE id = " . (int)$ru['id'])->fetchColumn();
check('peer INSERT lowercases the bearer', $storedBearer === str_repeat('a', 16) . '.' . str_repeat('b', 64), (string)$storedBearer);
$db->exec("TRUNCATE TABLE fed_peers");

// ── 3. settings accessors ────────────────────────────────────────────────────
check('fedExportEnabled needs both flags', !fedExportEnabled(array_merge($cfg, ['fed_enabled' => '0', 'fed_export_enabled' => '1'])) && fedExportEnabled($cfgF));
check('fedExportMaxBatch clamps', fedExportMaxBatch(array_merge($cfg, ['fed_export_max_batch' => '999999'])) === 20000);

// ── 5. streaming export: bounded memory and three budgets ───────────────────
// The buffered exporter builds a whole page, files and all, before sending anything: rows count
// TORRENTS, so 20 000 rows of a hundred files each is two million records in a PHP array. These
// checks pin down that the streaming path never does that, and that a page ends on whichever
// budget runs out first while still handing back a usable cursor.
$db->exec("TRUNCATE TABLE index_hashes");
$db->exec("TRUNCATE TABLE index_files");
$base = time() - 7200;
$ins = $db->prepare("INSERT INTO index_hashes (info_hash, name, total_size, files_count, piece_length,
        last_seeders, last_leechers, first_seen, last_seen, seen_count, meta_status, meta_fetched_at)
    VALUES (?, ?, ?, ?, 262144, 3, 4, NOW(), NOW(), 1, 'done', FROM_UNIXTIME(?))");
$insF = $db->prepare("INSERT INTO index_files (info_hash, path, size) VALUES (?, ?, ?)");
for ($i = 0; $i < 40; $i++) {
    $h = str_pad(dechex($i + 0x100), 40, '0', STR_PAD_LEFT);
    $ins->execute([$h, 'torrent ' . $i, 1000 + $i, 5, $base + $i]);
    for ($f = 0; $f < 5; $f++) $insF->execute([$h, 'dir/file-' . $f . '.bin', 100 + $f]);
}

$collect = static function (array $cfgUse, int $since = 0, string $after = '', int $limit = 1000, bool $files = true) use ($db) {
    $lines = [];
    $res = fedExportStream($db, $cfgUse, $since, $after, $limit, $files, static function (string $l) use (&$lines) {
        $lines[] = rtrim($l, "\n");
        return strlen($l);
    });
    return [$res, $lines];
};

$cfgS = array_merge($cfg, ['fed_export_max_batch' => '20000', 'fed_export_max_bytes' => '0', 'fed_export_max_files' => '0']);
[$res, $lines] = $collect($cfgS);
check('stream: every row is emitted as its own line', $res['rows'] === 40 && count($lines) === 40, json_encode([$res['rows'], count($lines)]));
check('stream: each line is a complete JSON object', count(array_filter($lines, static fn($l) => is_array(json_decode($l, true)))) === 40);
check('stream: files travel with their row', count(json_decode($lines[0], true)['files'] ?? []) === 5, $lines[0]);
check('stream: the file records are counted', $res['files'] === 200, (string)$res['files']);
check('stream: nothing left to fetch', $res['has_more'] === false && $res['stopped_by'] === 'end');
$first = json_decode($lines[0], true); $last = json_decode($lines[39], true);
check('stream: rows come back in cursor order', $first['mf'] < $last['mf'], json_encode([$first['mf'], $last['mf']]));
check('stream: the cursor points at the last row sent',
      $res['next']['since'] === $last['mf'] && $res['next']['after'] === $last['h'], json_encode($res['next']));

// the row budget
[$res2, $lines2] = $collect($cfgS, 0, '', 10);
check('stream: the row budget ends the page', $res2['rows'] === 10 && $res2['stopped_by'] === 'rows', json_encode($res2));
check('stream: … and it reports there is more', $res2['has_more'] === true);
[$res3, $lines3] = $collect($cfgS, $res2['next']['since'], $res2['next']['after'], 10);
check('stream: the cursor resumes without repeating a row',
      json_decode($lines3[0], true)['h'] !== json_decode($lines2[9], true)['h'], $lines3[0]);
check('stream: … and without skipping one', json_decode($lines3[0], true)['h'] === json_decode($lines[10], true)['h']);

// the byte budget
$cfgB = array_merge($cfgS, ['fed_export_max_bytes' => '600']);
[$res4, $lines4] = $collect($cfgB);
check('stream: the byte budget ends the page early', $res4['stopped_by'] === 'bytes' && $res4['rows'] < 40, json_encode($res4));
check('stream: … at or just past the budget, never far beyond', $res4['bytes'] >= 600 && $res4['bytes'] < 600 + 2000, (string)$res4['bytes']);
check('stream: … and the cursor is still usable', is_array($res4['next']) && strlen((string)$res4['next']['after']) === 40);

// the file-record budget
$cfgF = array_merge($cfgS, ['fed_export_max_files' => '12']);
[$res5, $lines5] = $collect($cfgF);
check('stream: the file budget ends the page', $res5['stopped_by'] === 'files' && $res5['files'] >= 12, json_encode($res5));
check('stream: … after only a few rows', $res5['rows'] === 3, (string)$res5['rows']);

// a budget smaller than a single row must still make progress, or the peer loops forever
$cfgTiny = array_merge($cfgS, ['fed_export_max_bytes' => '1']);
[$res6, $lines6] = $collect($cfgTiny);
check('stream: a budget smaller than one row still sends that row', $res6['rows'] === 1 && count($lines6) === 1, json_encode($res6));
check('stream: … so the cursor always advances', $res6['next'] !== null && $res6['has_more'] === true);

// files off
[$res7, $lines7] = $collect($cfgS, 0, '', 5, false);
check('stream: files can be left out', $res7['files'] === 0 && !isset(json_decode($lines7[0], true)['files']), $lines7[0]);

// the same exclusions as the buffered exporter
$db->prepare("INSERT INTO banned_hashes (info_hash, reason) VALUES (?, 'test')")->execute([str_pad(dechex(0x100), 40, '0', STR_PAD_LEFT)]);
[$res8, $lines8] = $collect($cfgS);
check('stream: a banned hash never leaves the node', $res8['rows'] === 39
      && !in_array(str_pad(dechex(0x100), 40, '0', STR_PAD_LEFT), array_map(static fn($l) => json_decode($l, true)['h'], $lines8), true));
$db->exec("TRUNCATE TABLE banned_hashes");

// the streaming and buffered paths must agree on what they would send
[$res9, $lines9] = $collect($cfgS, 0, '', 7);
$buf = fedExportRows($db, $cfgS, 0, '', 7, true);
check('stream and buffered exporters agree on the rows',
      array_map(static fn($l) => json_decode($l, true)['h'], $lines9) === array_column($buf['rows'], 'h'));
check('… and on the cursor they hand back', $res9['next'] === $buf['next'], json_encode([$res9['next'], $buf['next']]));

check('export budget accessors: defaults', fedExportMaxBytes([]) === 8388608 && fedExportMaxFiles([]) === 200000);
check('export budget accessors: 0 means no limit', fedExportMaxBytes(['fed_export_max_bytes' => '0']) === 0);
check('export budget accessors: negatives clamp to 0', fedExportMaxFiles(['fed_export_max_files' => '-9']) === 0);

$db->exec("TRUNCATE TABLE index_hashes");
$db->exec("TRUNCATE TABLE index_files");

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
