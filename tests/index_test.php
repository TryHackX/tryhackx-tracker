<?php
/**
 * Test for includes/index.php (needs the local test database — see deploy/local_bootstrap.php):
 *   php tests/index_test.php
 * Prints PASS/FAIL lines and exits non-zero on failure. Uses a fake full-scrape file (no tracker).
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

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
check('schema version >= 6', (int)($cfg['schema_version'] ?? 0) >= 6, (string)($cfg['schema_version'] ?? 'none'));
foreach (['index_hashes', 'index_files', 'whitelist', 'whitelist_files', 'banned_hashes'] as $t) $db->exec("TRUNCATE TABLE `$t`");
@unlink(indexStateFile());
$tmp = sys_get_temp_dir();

/** Build a gz (or plain) full-scrape file. $entries = [[hash_hex, complete, incomplete, downloaded], ...]. */
function makeScrape(array $entries, bool $gzip, string $path): void {
    $body = 'd5:filesd';
    foreach ($entries as $e) {
        $body .= '20:' . hex2bin($e[0]) . 'd8:completei' . $e[1] . 'e10:downloadedi' . ($e[3] ?? 0) . 'e10:incompletei' . ($e[2] ?? 0) . 'ee';
    }
    $body .= 'ee';
    if ($gzip) { $fh = gzopen($path, 'wb1'); gzwrite($fh, $body); gzclose($fh); }
    else file_put_contents($path, $body);
}
function h(int $i): string { return substr(sprintf('%040x', $i * 0x9E3779B1 + 1), -40); }

$cfg['index_enabled'] = '1';
$cfg['index_min_seeders'] = '1';
$cfg['index_grace_days'] = '3';
$cfg['index_protect_days'] = '10';
$cfg['index_max_rows'] = '200000';
$cfg['index_poll_budget'] = '30';
$cfg['index_meta_daily_budget'] = '5';

// ── 1. parse + filter + upsert ────────────────────────────────────────────────
$entries = [];
for ($i = 1; $i <= 100; $i++) $entries[] = [h($i), $i <= 60 ? ($i % 4) : ($i), $i, $i * 2]; // first 40 have complete 1..3, i<=60 some 0
// count how many have complete>=1
$expectKept = 0; foreach ($entries as $e) if ($e[1] >= 1) $expectKept++;
$file = $tmp . '/idx_test.gz'; makeScrape($entries, true, $file);
$p = indexPoll($db, $cfg, function () use ($file) { return ['file' => $file, 'gzip' => true]; }, 1000000);
check('poll ok', $p['ok'] && $p['error'] === null, json_encode($p));
check('poll parsed all 100 entries', $p['entries'] === 100, (string)$p['entries']);
check('poll kept only complete>=1', $p['kept'] === $expectKept, $p['kept'] . ' vs ' . $expectKept);
$rows = (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn();
check('rows inserted = kept', $rows === $expectKept, (string)$rows);
$row = $db->query("SELECT * FROM index_hashes WHERE info_hash = '" . h(2) . "'")->fetch(PDO::FETCH_ASSOC);
check('row has grace_until, meta none, seen_count 1', $row && $row['meta_status'] === 'none' && (int)$row['seen_count'] === 1 && $row['grace_until'] !== null, json_encode($row));
// pick one that is kept: h(1) complete=1
$r1 = $db->query("SELECT last_seeders, peak_seeders, last_leechers FROM index_hashes WHERE info_hash = '" . h(1) . "'")->fetch(PDO::FETCH_ASSOC);
check('kept row seeders/peak/leechers', (int)$r1['last_seeders'] === 1 && (int)$r1['peak_seeders'] === 1 && (int)$r1['last_leechers'] === 1, json_encode($r1));

// ── 2. second poll: seen_count++, peak tracks max ─────────────────────────────
$entries2 = [[h(1), 5, 2, 10], [h(2), 0, 3, 4]]; // h(1) now 5 seeders, h(2) now 0 (won't re-touch since not kept, but exists already? h(2) complete=2 first time so exists)
$file2 = $tmp . '/idx_test2.gz'; makeScrape($entries2, true, $file2);
$p2 = indexPoll($db, $cfg, function () use ($file2) { return ['file' => $file2, 'gzip' => true]; }, 1000100);
$r1b = $db->query("SELECT seen_count, last_seeders, peak_seeders FROM index_hashes WHERE info_hash = '" . h(1) . "'")->fetch(PDO::FETCH_ASSOC);
check('second poll: seen_count incremented, peak=max(1,5)=5', (int)$r1b['seen_count'] === 2 && (int)$r1b['last_seeders'] === 5 && (int)$r1b['peak_seeders'] === 5, json_encode($r1b));

// ── 3. whitelist / banned removal ─────────────────────────────────────────────
$db->exec("INSERT INTO whitelist (info_hash, source) VALUES ('" . h(1) . "', 'admin')");
$db->exec("INSERT INTO banned_hashes (info_hash) VALUES ('" . h(3) . "')");
$file3 = $tmp . '/idx_test3.gz'; makeScrape([[h(1), 2, 0, 0], [h(3), 2, 0, 0], [h(5), 2, 0, 0]], true, $file3);
$p3 = indexPoll($db, $cfg, function () use ($file3) { return ['file' => $file3, 'gzip' => true]; }, 1000200);
check('poll removes whitelisted + banned', $p3['removed_wl'] === 1 && $p3['removed_ban'] === 1, json_encode([$p3['removed_wl'], $p3['removed_ban']]));
check('whitelisted hash gone from index', (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash = '" . h(1) . "'")->fetchColumn() === 0);
check('banned hash gone from index', (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash = '" . h(3) . "'")->fetchColumn() === 0);

// ── 4. plain (non-gzip) scrape parses too ─────────────────────────────────────
$filePlain = $tmp . '/idx_plain.bin'; makeScrape([[h(500), 3, 1, 0], [h(501), 0, 1, 0]], false, $filePlain);
$pp = indexPoll($db, $cfg, function () use ($filePlain) { return ['file' => $filePlain, 'gzip' => false]; }, 1000300);
check('plain scrape parsed, kept 1 (complete>=1)', $pp['entries'] === 2 && $pp['kept'] === 1, json_encode($pp));

// ── 5. time budget truncation ─────────────────────────────────────────────────
$big = []; for ($i = 1000; $i < 6000; $i++) $big[] = [h($i), 2, 0, 0];
$fileBig = $tmp . '/idx_big.gz'; makeScrape($big, true, $fileBig);
$cfgB = $cfg; $cfgB['index_poll_budget'] = '5';
// deadline is now+budget; parse of 5000 tiny rows is fast, so force truncation via a 0-budget clamp min 5s won't trigger.
// Instead test the parser directly with an already-passed deadline.
$parsed = indexParseScrapeFile($fileBig, true, 1, function ($rows) {}, microtime(true) - 1);
check('parser respects an already-passed deadline (truncated, few entries)', $parsed['truncated'] === true, json_encode($parsed));

// ── 6. meta budget: spread + daily cap + reset ────────────────────────────────
foreach (['index_hashes'] as $t) $db->exec("TRUNCATE TABLE `$t`");
@unlink(indexStateFile());
$e6 = []; for ($i = 1; $i <= 20; $i++) $e6[] = [h(2000 + $i), $i, 0, 0]; // seeders 1..20
$file6 = $tmp . '/idx6.gz'; makeScrape($e6, true, $file6);
indexPoll($db, $cfg, function () use ($file6) { return ['file' => $file6, 'gzip' => true]; }, 1000400);
$q1 = indexQueueMetaBudget($db, $cfg, 1000400);
check('meta budget queues up to daily budget (5)', $q1 === 5, (string)$q1);
$pendingSeeders = $db->query("SELECT last_seeders FROM index_hashes WHERE meta_status = 'pending' ORDER BY last_seeders DESC")->fetchAll(PDO::FETCH_COLUMN);
check('meta budget picks highest seeders first', $pendingSeeders === ['20', '19', '18', '17', '16'] || array_map('intval', $pendingSeeders) === [20, 19, 18, 17, 16], json_encode($pendingSeeders));
$future = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status='pending' AND meta_requested_at > NOW()")->fetchColumn();
check('meta requested_at spread into the future', $future >= 1, (string)$future);
$q2 = indexQueueMetaBudget($db, $cfg, 1000500);
check('same-day budget exhausted → 0 more', $q2 === 0, (string)$q2);
$q3 = indexQueueMetaBudget($db, $cfg, 1000400 + 86400 + 10); // next day
check('next day → budget resets, queues more', $q3 === 5, (string)$q3);

// ── 7. protected_until extension on poll for done rows ────────────────────────
$db->exec("UPDATE index_hashes SET meta_status='done', protected_until=NOW() + INTERVAL 1 DAY WHERE info_hash='" . h(2001) . "'");
$before = $db->query("SELECT protected_until FROM index_hashes WHERE info_hash='" . h(2001) . "'")->fetchColumn();
$file7 = $tmp . '/idx7.gz'; makeScrape([[h(2001), 3, 0, 0]], true, $file7); // has seeders → extend
indexPoll($db, $cfg, function () use ($file7) { return ['file' => $file7, 'gzip' => true]; }, 1000600);
$after = $db->query("SELECT protected_until FROM index_hashes WHERE info_hash='" . h(2001) . "'")->fetchColumn();
check('done row with seeders → protection extended', strtotime($after) > strtotime($before), $before . ' -> ' . $after);

// ── 8. prune: grace expiry, protection expiry, cap, orphan files ──────────────
$db->exec("TRUNCATE TABLE index_hashes"); $db->exec("TRUNCATE TABLE index_files"); @unlink(indexStateFile());
// a: past grace, not done → drop
$db->exec("INSERT INTO index_hashes (info_hash, last_seen, grace_until, meta_status) VALUES ('" . h(1) . "', NOW(), NOW() - INTERVAL 1 DAY, 'none')");
// b: done, protection expired → drop
$db->exec("INSERT INTO index_hashes (info_hash, last_seen, grace_until, protected_until, meta_status) VALUES ('" . h(2) . "', NOW(), NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 1 DAY, 'done')");
// c: in grace → keep
$db->exec("INSERT INTO index_hashes (info_hash, last_seen, grace_until, meta_status) VALUES ('" . h(3) . "', NOW(), NOW() + INTERVAL 2 DAY, 'none')");
// d: done, protected → keep
$db->exec("INSERT INTO index_hashes (info_hash, last_seen, grace_until, protected_until, meta_status) VALUES ('" . h(4) . "', NOW(), NOW() - INTERVAL 5 DAY, NOW() + INTERVAL 5 DAY, 'done')");
$db->exec("INSERT INTO index_files (info_hash, path, size) VALUES ('" . h(1) . "', 'a/b.iso', 10), ('" . h(4) . "', 'c/d.iso', 20)");
$pr = indexPrune($db, $cfg, null, true);
check('prune drops expired (grace + protection): 2', $pr['expired'] === 2, json_encode($pr));
$left = $db->query("SELECT info_hash FROM index_hashes ORDER BY info_hash")->fetchAll(PDO::FETCH_COLUMN);
sort($left);
$expected = [h(3), h(4)]; sort($expected);
check('prune keeps in-grace + protected', $left === $expected, json_encode($left));
check('prune removes orphaned files (h1 gone), keeps h4 files', (int)$db->query("SELECT COUNT(*) FROM index_files WHERE info_hash='" . h(1) . "'")->fetchColumn() === 0
    && (int)$db->query("SELECT COUNT(*) FROM index_files WHERE info_hash='" . h(4) . "'")->fetchColumn() === 1);

// cap: 5 unprotected rows, max_rows=3 → drop 2 oldest by last_seen
$db->exec("TRUNCATE TABLE index_hashes"); @unlink(indexStateFile());
for ($i = 1; $i <= 12; $i++) $db->exec("INSERT INTO index_hashes (info_hash, last_seen, grace_until, meta_status) VALUES ('" . h(700 + $i) . "', NOW() - INTERVAL " . (13 - $i) . " HOUR, NOW() + INTERVAL 2 DAY, 'none')");
$cfgCap = $cfg; $cfgCap['index_max_rows'] = '10';   // helper floor is 10
$pr2 = indexPrune($db, $cfgCap, null, true);
check('cap prunes 2 oldest unprotected (12 - max 10)', $pr2['capped'] === 2 && (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn() === 10, json_encode($pr2));
$oldest = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash IN ('" . h(701) . "','" . h(702) . "')")->fetchColumn();
check('cap kept the newest (dropped h701,h702)', $oldest === 0);
// protected rows are never capped
$db->exec("TRUNCATE TABLE index_hashes"); @unlink(indexStateFile());
for ($i = 1; $i <= 12; $i++) $db->exec("INSERT INTO index_hashes (info_hash, last_seen, protected_until, meta_status) VALUES ('" . h(800 + $i) . "', NOW() - INTERVAL " . (13 - $i) . " HOUR, NOW() + INTERVAL 5 DAY, 'done')");
$pr3 = indexPrune($db, $cfgCap, null, true);
check('protected rows survive the cap', $pr3['capped'] === 0 && (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn() === 12, json_encode($pr3));

// prune throttled
check('prune throttled (< 1h since last)', indexPrune($db, $cfg, time() + 10) === null);

// ── 9. promote → whitelist + promoted_at ──────────────────────────────────────
$db->exec("TRUNCATE TABLE index_hashes"); $db->exec("TRUNCATE TABLE whitelist"); @unlink(indexStateFile());
$db->exec("INSERT INTO index_hashes (info_hash, name, last_seen, grace_until, meta_status) VALUES ('" . h(9001) . "', 'Cool Torrent', NOW(), NOW() + INTERVAL 3 DAY, 'done')");
$cfgP = $cfg; $cfgP['whitelist_path'] = ''; // avoid file writes in promote (whitelistAddHashes)
$pr9 = indexPromote($db, $cfgP, [h(9001)]);
check('promote returns promoted 1', $pr9['promoted'] === 1 && $pr9['error'] === null, json_encode($pr9));
check('promote added to whitelist (source admin)', (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE info_hash='" . h(9001) . "' AND source='admin'")->fetchColumn() === 1);
check('promote set promoted_at + carried name', $db->query("SELECT promoted_at FROM index_hashes WHERE info_hash='" . h(9001) . "'")->fetchColumn() !== null
    && $db->query("SELECT name FROM whitelist WHERE info_hash='" . h(9001) . "'")->fetchColumn() === 'Cool Torrent');
check('promote invalid hash → error', indexPromote($db, $cfgP, ['nothex'])['error'] !== null);

// ── 10. delete + requestMeta + scope + rowsCount + status ─────────────────────
$db->exec("TRUNCATE TABLE index_hashes"); $db->exec("TRUNCATE TABLE index_files");
for ($i = 1; $i <= 4; $i++) $db->exec("INSERT INTO index_hashes (info_hash, last_seen, grace_until, meta_status) VALUES ('" . h(300 + $i) . "', NOW(), NOW() + INTERVAL 3 DAY, '" . ['none','failed','done','none'][$i - 1] . "')");
$db->exec("INSERT INTO index_files (info_hash, path, size) VALUES ('" . h(301) . "', 'x', 1)");
check('rowsCount = 4', indexRowsCount($db) === 4);
$del = indexDelete($db, [h(301), h(302)]);
check('delete 2 by hash + cascade files', $del === 2 && (int)$db->query("SELECT COUNT(*) FROM index_files WHERE info_hash='" . h(301) . "'")->fetchColumn() === 0);
$rm = indexRequestMeta($db, [h(303), h(304)], 5);
check('requestMeta queues (done → pending too)', $rm === 2 && (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status='pending' AND meta_priority=5")->fetchColumn() === 2, (string)$rm);
$db->exec("INSERT INTO index_hashes (info_hash, last_seen, grace_until, meta_status) VALUES ('" . h(305) . "', NOW(), NOW() + INTERVAL 3 DAY, 'done')");
$sc = indexQueueMetaByScope($db, 'all');
check('scope all requeues non-fetching (the done row)', $sc >= 1, (string)$sc);
check('scope invalid → null', indexQueueMetaByScope($db, 'bogus') === null);
$status = indexStatus($db, $cfg);
check('status shape', isset($status['counts']['total'], $status['state']['last_poll_at']) && $status['enabled'] === true);

// cleanup
foreach (['index_hashes', 'index_files', 'whitelist', 'whitelist_files', 'banned_hashes'] as $t) $db->exec("TRUNCATE TABLE `$t`");
@unlink(indexStateFile());
foreach (glob($tmp . '/idx*.gz') ?: [] as $f) @unlink($f);
@unlink($tmp . '/idx_plain.bin');

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
