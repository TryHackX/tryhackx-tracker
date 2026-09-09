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
// Re-read: ensureSchema() is what WRITES schema_version, so the copy fetched a line above predates
// it. On a database that some other suite had already migrated this passed by luck; on a genuinely
// fresh one it read 'none' and failed. Ordering between suites is not something a check should rely on.
$cfg = getSettings($db, true);
check('schema version >= 6', (int)($cfg['schema_version'] ?? 0) >= 6, (string)($cfg['schema_version'] ?? 'none'));
foreach (['index_hashes', 'index_files', 'whitelist', 'whitelist_files', 'banned_hashes'] as $t) $db->exec("TRUNCATE TABLE `$t`");
@unlink(indexStateFile());
// The count caches are checkout state, not database state, and TRUNCATE cannot clear them: a
// config/index_count.json left behind by a page view (or by this suite's previous run) would answer
// for a table that no longer holds those rows.
indexTotalCacheDrop(); indexStatusCacheDrop();
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
// A row is stamped "seen" at most once per IDX_SEEN_WINDOW_SEC (six hours): a poll every thirty
// minutes used to rewrite every unchanged row and four indexes with it, and that was 96 % of the
// poll's time on production. So the second poll only counts if the row's last stamp is older than
// the window — age it by hand first, the way six hours would.
$db->exec("UPDATE index_hashes SET last_seen = NOW() - INTERVAL 7 HOUR WHERE info_hash = '" . h(1) . "'");
$entries2 = [[h(1), 5, 2, 10], [h(2), 0, 3, 4]]; // h(1) now 5 seeders, h(2) now 0 (won't re-touch since not kept, but exists already? h(2) complete=2 first time so exists)
$file2 = $tmp . '/idx_test2.gz'; makeScrape($entries2, true, $file2);
$p2 = indexPoll($db, $cfg, function () use ($file2) { return ['file' => $file2, 'gzip' => true]; }, 1000100);
$r1b = $db->query("SELECT seen_count, last_seeders, peak_seeders, last_seen FROM index_hashes WHERE info_hash = '" . h(1) . "'")->fetch(PDO::FETCH_ASSOC);
check('second poll: seen_count incremented, peak=max(1,5)=5', (int)$r1b['seen_count'] === 2 && (int)$r1b['last_seeders'] === 5 && (int)$r1b['peak_seeders'] === 5, json_encode($r1b));
check('second poll: last_seen stamped now (the window had passed)', strtotime((string)$r1b['last_seen']) > time() - 120, (string)$r1b['last_seen']);
// ── 2b. a poll INSIDE the window: the counters follow the tracker, the stamp does not move ────────
$file2b = $tmp . '/idx_test2b.gz'; makeScrape([[h(1), 7, 1, 11], [h(2), 3, 3, 4]], true, $file2b);
$p2b = indexPoll($db, $cfg, function () use ($file2b) { return ['file' => $file2b, 'gzip' => true]; }, 1000150);
$r1c = $db->query("SELECT seen_count, last_seeders, last_leechers, peak_seeders, last_seen FROM index_hashes WHERE info_hash = '" . h(1) . "'")->fetch(PDO::FETCH_ASSOC);
check('inside the window: seeders/leechers/peak updated', (int)$r1c['last_seeders'] === 7 && (int)$r1c['last_leechers'] === 1 && (int)$r1c['peak_seeders'] === 7, json_encode($r1c));
check('inside the window: seen_count and last_seen untouched', (int)$r1c['seen_count'] === 2 && $r1c['last_seen'] === $r1b['last_seen'], json_encode([$r1c['seen_count'], $r1c['last_seen'], $r1b['last_seen']]));
check('inside the window: the poll still counts the row as kept', $p2b['kept'] === 2, json_encode($p2b));

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

// ── 5b. non-scrape body rejected; resume cursor; poll lock ────────────────────
$db->exec("TRUNCATE TABLE index_hashes"); @unlink(indexStateFile());
$htmlFile = $tmp . '/idx_html.bin'; file_put_contents($htmlFile, "<html><body>proxy error</body></html>");
$ph = indexPoll($db, $cfg, function () use ($htmlFile) { return ['file' => $htmlFile, 'gzip' => false]; }, 1000350);
check('non-scrape HTTP body rejected (error, not empty success)', !$ph['ok'] && str_contains((string)$ph['error'], 'scrape reply') && $ph['kept'] === 0, json_encode($ph));
check('rejected poll records the error in state', str_contains((string)indexStateRead()['last_error'], 'scrape reply'));

// resume cursor: a truncated poll advances poll_skip, the next poll continues at the tail
$db->exec("TRUNCATE TABLE index_hashes"); @unlink(indexStateFile());
$e5 = []; for ($i = 1; $i <= 10; $i++) $e5[] = [h(5000 + $i), 3, 0, 0];
$file5 = $tmp . '/idx_resume.gz'; makeScrape($e5, true, $file5);
// force truncation after the first batch by parsing with a tiny deadline is racy; instead drive the parser
// through indexPoll twice with a manual poll_skip to prove the skip is honoured
$parsedA = indexParseScrapeFile($file5, true, 1, function ($rows) {}, microtime(true) + 5, 4);
check('parser skips the first N entries (resume)', $parsedA['entries'] === 10 && $parsedA['kept'] === 6, json_encode($parsedA));
// poll_skip persistence: simulate a truncated pass by hand
indexStateUpdate(function (&$s) { $s['poll_skip'] = 3; return true; });
$got = [];
indexParseScrapeFile($file5, true, 1, function ($rows) use (&$got) { foreach ($rows as $r) $got[] = $r[0]; }, microtime(true) + 5, (int)indexStateRead()['poll_skip']);
check('resume pass processes only entries after the cursor', count($got) === 7);

// ── a truncated DOWNLOAD must not be skipped past ────────────────────────────
// The regression this exists for, exactly as production hit it: one poll's transfer dies part-way
// and leaves the cursor at N; the next poll's transfer dies EARLIER, so every entry in the short
// file sits below the cursor. Carrying the cursor over means the poll keeps nothing at all — and
// with the tracker mis-framing every scrape, that is every poll, for ever. The index stops being
// refreshed and nothing in the panel says why.
$db->exec("TRUNCATE TABLE index_hashes"); @unlink(indexStateFile());
$long = []; for ($i = 1; $i <= 20; $i++) $long[] = [h(6000 + $i), 4, 0, 0];
$short = array_slice($long, 0, 8);
$fileLong  = $tmp . '/idx_part_long.gz';  makeScrape($long, true, $fileLong);
$fileShort = $tmp . '/idx_part_short.gz'; makeScrape($short, true, $fileShort);

// first poll: the transfer ends early after 20 entries
$pa = indexPoll($db, $cfg, function () use ($fileLong) {
    return ['file' => $fileLong, 'gzip' => true, 'partial' => 'the transfer ended early'];
}, 1000700);
check('partial poll keeps what arrived', $pa['ok'] && $pa['kept'] === 20, json_encode($pa));
check('partial poll is marked truncated', $pa['truncated'] === true);
check('…and leaves a resume cursor', (int)indexStateRead()['poll_skip'] === 20, (string)indexStateRead()['poll_skip']);

// second poll: the transfer dies EARLIER — 8 entries, all of them below the cursor
$pb = indexPoll($db, $cfg, function () use ($fileShort) {
    return ['file' => $fileShort, 'gzip' => true, 'partial' => 'the transfer ended early'];
}, 1000710);
check('a SHORTER partial file is read from the start, not skipped past',
      $pb['kept'] === 8, json_encode($pb));
check('…and the cursor does not walk backwards',
      (int)indexStateRead()['poll_skip'] === 20, (string)indexStateRead()['poll_skip']);

// third poll: the transfer completes. The cursor still applies — this file DOES reach past it.
$pc = indexPoll($db, $cfg, function () use ($fileLong) {
    return ['file' => $fileLong, 'gzip' => true];
}, 1000720);
check('a complete file still honours the cursor', $pc['entries'] === 20 && $pc['kept'] === 0, json_encode($pc));
check('…and a complete pass clears the cursor', (int)indexStateRead()['poll_skip'] === 0);

// poll lock: while the lock is held, indexPoll returns "already polling" and does nothing
$lh = fopen(indexPollLockFile(), 'c'); flock($lh, LOCK_EX);
$blocked = indexPoll($db, $cfg, function () use ($file5) { return ['file' => $file5, 'gzip' => true]; }, 1000360);
check('poll lock: second poll returns "already polling"', !$blocked['ok'] && $blocked['error'] === 'already polling' && $blocked['entries'] === 0, json_encode($blocked));
flock($lh, LOCK_UN); fclose($lh);
indexStateUpdate(function (&$s) { $s['poll_skip'] = 0; return true; });   // clear the simulated cursor
$after = indexPoll($db, $cfg, function () use ($file5) { return ['file' => $file5, 'gzip' => true]; }, 1000370);
check('poll works once the lock is released', $after['ok'] && $after['kept'] === 10, json_encode($after));
check('poll_skip resets to 0 after a complete (non-truncated) poll', (int)indexStateRead()['poll_skip'] === 0);

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

// ── 6b. auto-queue mode: every 'none' row queued, budget ignored ──────────────
$remainingNone = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status = 'none'")->fetchColumn();
check('auto-queue precondition: some rows still none', $remainingNone === 10, (string)$remainingNone);
$cfgAuto = $cfg; $cfgAuto['index_meta_auto_queue'] = '1';
check('indexMetaAutoQueue accessor', indexMetaAutoQueue($cfgAuto) && !indexMetaAutoQueue($cfg));
$noneHashes = $db->query("SELECT info_hash FROM index_hashes WHERE meta_status = 'none'")->fetchAll(PDO::FETCH_COLUMN);
$qa = indexQueueMetaAuto($db);
check('auto-queue queues ALL remaining none rows (no budget)', $qa === 10, (string)$qa);
check('auto-queue leaves no none rows', (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status = 'none'")->fetchColumn() === 0);
$inNone = "'" . implode("','", $noneHashes) . "'";
$badSpread = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash IN ($inNone) AND meta_requested_at > NOW() + INTERVAL 3601 SECOND")->fetchColumn();
check('auto-queue spread stays within ~1 h', $badSpread === 0, (string)$badSpread);
check('auto-queue rows carry janitor priority -1', (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash IN ($inNone) AND (meta_status <> 'pending' OR meta_priority <> -1)")->fetchColumn() === 0);
check('auto-queue idempotent → 0 when nothing is none', indexQueueMetaAuto($db) === 0);
// indexTick routes to auto-queue when the setting is on (fresh 'none' row + a poll that is not due)
$db->exec("INSERT INTO index_hashes (info_hash, first_seen, last_seen, seen_count, grace_until) VALUES ('" . h(9001) . "', NOW(), NOW(), 1, NOW() + INTERVAL 3 DAY)");
$tk = indexTick($db, $cfgAuto, function () { return ['file' => null, 'error' => 'no poll in this test']; }, 1000500);
check('indexTick (auto mode) queues the new none row', $tk['meta_queued'] === 1, json_encode($tk));

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

// a row whose metadata resolved between polls (protected_until NULL) must NOT be cap-pruned:
// prune backfills its protection first
$db->exec("TRUNCATE TABLE index_hashes"); @unlink(indexStateFile());
$db->exec("INSERT INTO index_hashes (info_hash, last_seen, grace_until, meta_status, protected_until) VALUES ('" . h(950) . "', NOW() - INTERVAL 12 HOUR, NOW() + INTERVAL 1 DAY, 'done', NULL)");
for ($i = 1; $i <= 11; $i++) $db->exec("INSERT INTO index_hashes (info_hash, last_seen, grace_until, meta_status) VALUES ('" . h(950 + $i) . "', NOW() - INTERVAL " . (13 - $i) . " HOUR, NOW() + INTERVAL 2 DAY, 'none')");
$prB = indexPrune($db, $cfgCap, null, true);   // 12 rows, cap 10 → 2 pruned, but NEVER the done row
check('prune backfills protection for freshly-done rows', $prB['protected_backfill'] === 1, json_encode($prB));
check('freshly-done row survives the cap prune', (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash='" . h(950) . "' AND protected_until IS NOT NULL")->fetchColumn() === 1);
// prune lock: while held, a concurrent prune is refused (returns null even with force)
$plh = fopen(indexPruneLockFile(), 'c'); flock($plh, LOCK_EX);
check('prune lock: concurrent prune returns null', indexPrune($db, $cfgCap, null, true) === null);
flock($plh, LOCK_UN); fclose($plh);

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

// ── 11. public search: the too-short guard, and the hash-prefix path it must leave alone ───────
// api/index_search.php answers 400 from indexSearchTooShort() before touching the catalogue; the
// rule is tested here as a function because the endpoint exits through jsonResponse(). What
// matters: empty is browsing (allowed), one and two CHARACTERS are refused whatever their byte
// count, three are allowed, and a hex prefix is never refused — that path is an indexed lookup.
check('too short: empty is browsing, not a search', !indexSearchTooShort('') && !indexSearchTooShort('   '));
check('too short: one and two characters refused', indexSearchTooShort('a') && indexSearchTooShort('ab'));
check('too short: surrounding whitespace does not count as length', indexSearchTooShort(' a ') && indexSearchTooShort("ab\t"));
check('too short: three characters allowed', !indexSearchTooShort('abc') && !indexSearchTooShort('a b'));
check('too short: counts characters, not bytes (ąę = 2, ąęó = 3)', indexSearchTooShort('ąę') && !indexSearchTooShort('ąęó'));
check('too short: two hex digits are still too short (not a hash prefix)', indexSearchTooShort('ab') && indexSearchTooShort('1f'));
check('too short: a hash prefix is never refused', !indexSearchTooShort('abcdef') && !indexSearchTooShort(strtoupper(h(1))));
check('hash prefix regex is the shared constant', preg_match(INDEX_HASH_PREFIX_RE, 'abcdef') === 1 && preg_match(INDEX_HASH_PREFIX_RE, 'abcde') === 0
    && preg_match(INDEX_HASH_PREFIX_RE, 'abcdeg') === 0 && preg_match(INDEX_HASH_PREFIX_RE, h(1) . '0') === 0);

// the indexed path the guard exists to protect: a prefix of the hash finds the row. Not h(): its
// hashes are a small number zero-padded to 40 digits, so they all share the same first 29 characters
// and a prefix would match every row — hand-made heads that differ from the first character on.
$hA = 'cafe' . str_repeat('0', 36); $hB = 'beef' . str_repeat('0', 36);
$db->exec("TRUNCATE TABLE index_hashes"); $db->exec("TRUNCATE TABLE index_files");
$db->exec("INSERT INTO index_hashes (info_hash, name, last_seen, grace_until, meta_status, last_seeders, last_leechers) VALUES ('$hA', 'Prefix Lookup Target', NOW(), NOW() + INTERVAL 3 DAY, 'done', 4, 1)");
$db->exec("INSERT INTO index_hashes (info_hash, name, last_seen, grace_until, meta_status, last_seeders, last_leechers) VALUES ('$hB', 'Another Resolved Row', NOW(), NOW() + INTERVAL 3 DAY, 'done', 2, 0)");
$byPrefix = indexSearchCatalogue($db, $cfg, ['search' => 'CAFE0000', 'include_whitelist' => false]);
check('catalogue: 8-char hex prefix (any case) finds exactly its row', $byPrefix['total'] === 1 && count($byPrefix['rows']) === 1
    && $byPrefix['rows'][0]['info_hash'] === $hA && $byPrefix['rows'][0]['name'] === 'Prefix Lookup Target', json_encode($byPrefix));
$byFull = indexSearchCatalogue($db, $cfg, ['search' => $hB, 'include_whitelist' => false]);
check('catalogue: the full hash finds its row', $byFull['total'] === 1 && ($byFull['rows'][0]['info_hash'] ?? '') === $hB, json_encode($byFull));
$browse = indexSearchCatalogue($db, $cfg, ['search' => '', 'include_whitelist' => false]);
check('catalogue: the empty search still lists everything', $browse['total'] === 2, json_encode($browse));

// ── 12. the file list says how much of it was ever stored ─────────────────────
// The metadata worker writes only the first max_files paths of a torrent (worker.py:645) but
// records libtorrent's REAL count (worker.py:669), so a big entry has a short list under a big
// number — 620 rows on production, every one with exactly 5 000 paths against counts up to 27 260.
// The three file-list endpoints are router fragments that exit through jsonResponse() and cannot be
// included here, so what is driven is the predicate they all call, fed by the rows and the same
// LIMIT+1 query they actually run. Five stored paths against a count of 18 000 stand in for 5 000
// against 18 000: what matters is that the list ENDS on the limit.
$db->exec("TRUNCATE TABLE index_hashes"); $db->exec("TRUNCATE TABLE index_files");
$db->exec("TRUNCATE TABLE whitelist"); $db->exec("TRUNCATE TABLE whitelist_files");
$hShort = h(7001); $hWhole = h(7002);
$db->exec("INSERT INTO index_hashes (info_hash, name, last_seen, grace_until, meta_status, files_count) VALUES ('$hShort', 'Big Torrent', NOW(), NOW() + INTERVAL 3 DAY, 'done', 18000)");
$db->exec("INSERT INTO index_hashes (info_hash, name, last_seen, grace_until, meta_status, files_count) VALUES ('$hWhole', 'Small Torrent', NOW(), NOW() + INTERVAL 3 DAY, 'done', 4)");
for ($i = 1; $i <= 5; $i++) $db->exec("INSERT INTO index_files (info_hash, path, size) VALUES ('$hShort', 'big/$i.bin', 100)");
for ($i = 1; $i <= 4; $i++) $db->exec("INSERT INTO index_files (info_hash, path, size) VALUES ('$hWhole', 'small/$i.bin', 100)");

/** One reply of api/index_files.php / api/admin/index_item.php: the row, the page, the shortfall. */
$filesReply = function (string $hash, int $limit, int $offset = 0) use ($db) {
    $st = $db->prepare("SELECT files_count FROM index_hashes WHERE info_hash = ?");
    $st->execute([$hash]);
    $fc = $st->fetchColumn();
    $fs = $db->prepare("SELECT path, size FROM index_files WHERE info_hash = ? ORDER BY id LIMIT ? OFFSET ?");
    $fs->bindValue(1, $hash, PDO::PARAM_STR);
    $fs->bindValue(2, $limit + 1, PDO::PARAM_INT);
    $fs->bindValue(3, $offset, PDO::PARAM_INT);
    $fs->execute();
    $rows = $fs->fetchAll(PDO::FETCH_ASSOC);
    return indexFilesShortfall($fc === false || $fc === null ? null : (int)$fc, count($rows), $limit, $offset);
};

// The reported bug. The stored list ends exactly ON the limit, so the +1 probe comes back full but
// not over and used to read as "complete" — a 5 000-row list under an 18 000-file heading, silently.
$atCap = $filesReply($hShort, 5);
check('list ending on the limit: not truncated, but SHORT of the torrent count', $atCap['truncated'] === false && $atCap['short'] === true && $atCap['stored'] === 5, json_encode($atCap));
$whole = $filesReply($hWhole, 5);
check('a complete list is neither truncated nor short', $whole['truncated'] === false && $whole['short'] === false && $whole['stored'] === 4, json_encode($whole));
// Truncated and short are different answers and must not be merged: truncated means asking again
// gets the rest, short means there is no rest to get. Only the first may offer a "load all" button.
$more = $filesReply($hShort, 3);
check('rows still waiting → truncated, and NOT short (asking again gets them)', $more['truncated'] === true && $more['short'] === false, json_encode($more));
$last = $filesReply($hShort, 3, 3);
check('the last page of a paged list reports the shortfall, counted from the offset', $last['truncated'] === false && $last['short'] === true && $last['stored'] === 5, json_encode($last));
check('a row whose metadata was never fetched is never called short', indexFilesShortfall(null, 0, 5)['short'] === false);
// The whitelist arm of the same endpoints reads its own table and its own files_count.
$db->exec("INSERT INTO whitelist (info_hash, name, source, meta_status, files_count) VALUES ('" . h(7003) . "', 'Big Whitelisted', 'admin', 'done', 27260)");
$wlId = (int)$db->lastInsertId();
for ($i = 1; $i <= 5; $i++) $db->exec("INSERT INTO whitelist_files (whitelist_id, path, size) VALUES ($wlId, 'wl/$i.bin', 100)");
$wf = $db->prepare("SELECT path FROM whitelist_files WHERE whitelist_id = ? ORDER BY id LIMIT ?");
$wf->bindValue(1, $wlId, PDO::PARAM_INT); $wf->bindValue(2, 6, PDO::PARAM_INT); $wf->execute();
$wlShort = indexFilesShortfall(27260, count($wf->fetchAll(PDO::FETCH_ASSOC)), 5);
check('whitelist arm reports the same shortfall', $wlShort['truncated'] === false && $wlShort['short'] === true && $wlShort['stored'] === 5, json_encode($wlShort));

// ── 13. how a file list loads: the clamps, and the ceiling the endpoints enforce ──────
// The clamp that actually holds is the one on READ: a settings row can arrive from install.php, a
// restored backup or a MySQL client on a database three other applications also use, and none of
// those went through save_settings.php. So every one of these feeds a hand-built $cfg — the shape
// of a row that never met the form.
check('defaults reproduce 1.37.0: 2 000 a page, scrolling', indexFilesBatch(trackerSchemaDefaultSettings()) === 2000 && indexFilesMode(trackerSchemaDefaultSettings()) === 'scroll');
check('defaults reproduce 1.37.0: the panel shows 5 000 and waits for the button', indexFilesAdminBatch(trackerSchemaDefaultSettings()) === 5000 && indexFilesAdminMax(trackerSchemaDefaultSettings()) === 1000000 && indexFilesAdminMode(trackerSchemaDefaultSettings()) === 'button');
check('public batch: above the ceiling clamps down', indexFilesBatch(['index_files_batch' => '50000']) === IDX_FILES_BATCH_MAX);
check('public batch: below the floor clamps up', indexFilesBatch(['index_files_batch' => '10']) === IDX_FILES_BATCH_MIN);
check('public batch: empty and missing both mean the default', indexFilesBatch(['index_files_batch' => '']) === 2000 && indexFilesBatch([]) === 2000);
check('public total: above the ceiling clamps down', indexFilesMax(['index_files_max' => '99999999']) === IDX_FILES_MAX_HARD);
// A max under one batch would answer the very first request with "capped, nothing here". Repaired
// on read rather than refused on save, so one bad number cannot reject a save of every other field.
check('public total below one batch is raised to one batch', indexFilesMax(['index_files_batch' => '5000', 'index_files_max' => '1000']) === 5000);
check('panel batch: above the ceiling clamps down', indexFilesAdminBatch(['index_files_admin_batch' => '999999']) === IDX_FILES_ADMIN_BATCH_MAX);
check('panel total: above the ceiling clamps down', indexFilesAdminMax(['index_files_admin_max' => '99999999']) === IDX_FILES_ADMIN_MAX_HARD);
// The two modes fall back to DIFFERENT values, because their audiences behaved differently before
// 1.38.0: a garbage row must not flip the panel into fetching whole file lists unasked.
check('mode: a value that is not a mode falls back per audience', indexFilesMode(['index_files_mode' => '; DROP']) === 'scroll' && indexFilesAdminMode(['index_files_admin_mode' => '; DROP']) === 'button');
check('mode: a real mode survives on both sides', indexFilesMode(['index_files_mode' => 'all']) === 'all' && indexFilesAdminMode(['index_files_admin_mode' => 'scroll']) === 'scroll');

// The ceiling itself, driven through the endpoint's own query and its own predicates: 12 stored
// paths, a batch of 5, a total of 10. What is asserted is that the reply never says "there is more,
// keep asking" AND "the site stopped you" at once — the pair that turns an open tab into a request
// loop against a per-IP bucket, since the page's loop condition is truncated && can_more.
$db->exec("TRUNCATE TABLE index_hashes"); $db->exec("TRUNCATE TABLE index_files");
$hCap = h(7004);
$db->exec("INSERT INTO index_hashes (info_hash, name, last_seen, grace_until, meta_status, files_count) VALUES ('$hCap', 'Capped Torrent', NOW(), NOW() + INTERVAL 3 DAY, 'done', 12)");
for ($i = 1; $i <= 12; $i++) $db->exec("INSERT INTO index_files (info_hash, path, size) VALUES ('$hCap', 'cap/$i.bin', 100)");
/** One page of api/index_files.php: its limit arithmetic, its LIMIT+1 query, its two predicates. */
$pageOf = function (int $offset, int $batch, int $max) use ($db, $hCap) {
    if ($offset >= $max) return ['files' => 0, 'truncated' => false, 'capped' => true];
    $limit = min($batch, $max - $offset);
    $fs = $db->prepare("SELECT path FROM index_files WHERE info_hash = ? ORDER BY id LIMIT ? OFFSET ?");
    $fs->bindValue(1, $hCap, PDO::PARAM_STR); $fs->bindValue(2, $limit + 1, PDO::PARAM_INT); $fs->bindValue(3, $offset, PDO::PARAM_INT);
    $fs->execute();
    $rows = $fs->fetchAll(PDO::FETCH_ASSOC);
    $sf = indexFilesShortfall(12, count($rows), $limit, $offset);
    $n = min(count($rows), $limit);
    $capped = indexFilesCapped($sf['truncated'], $offset, $n, $max);
    return ['files' => $n, 'truncated' => $capped ? false : $sf['truncated'], 'capped' => $capped];
};
$p0 = $pageOf(0, 5, 10);
check('first page under the total: 5 files, another page waiting, not capped', $p0 === ['files' => 5, 'truncated' => true, 'capped' => false], json_encode($p0));
$p1 = $pageOf(5, 5, 10);
check('the page that reaches the total is capped and NOT truncated', $p1 === ['files' => 5, 'truncated' => false, 'capped' => true], json_encode($p1));
$p2 = $pageOf(10, 5, 10);
check('an offset at or past the total answers empty, capped, not truncated', $p2 === ['files' => 0, 'truncated' => false, 'capped' => true], json_encode($p2));
check('a list shorter than the total is never capped', $pageOf(0, 20, 10000)['capped'] === false && $pageOf(0, 20, 10000)['files'] === 12);
// The admin one-shot replies are the same predicate at offset 0: the Load-all button is offered
// only while a bigger request exists, so it can never return exactly the list already on screen.
check('panel: a first slice below the panel total leaves the Load-all button', indexFilesCapped(true, 0, 5000, 1000000) === false);
check('panel: a list already at the panel total takes the button away', indexFilesCapped(true, 0, 5000, 5000) === true);
check('a complete list is never capped, whatever the total', indexFilesCapped(false, 0, 12, 10) === false);

// ── 14. counting the catalogue is a decision, not a habit ─────────────────────
//
// MEASURED on production 2026-09-08: `SELECT COUNT(*) FROM index_hashes` is an index scan over
// 3 368 887 entries of idx_index_seeders ("Using index"), 939 ms per execution, and
// information_schema.INDEX_STATISTICS showed 6 737 774 rows read on that index in a clean 60 s
// window — exactly two full scans a minute, all day, on a database shared with the mail server, the
// forum and the file host.
//
// These checks are about WHO PAYS FOR IT, and they are written in rows the storage engine actually
// read rather than in the text of the source: an InnoDB COUNT(*) moves Handler_read_next by one per
// row it walks, and SHOW SESSION STATUS needs no privilege (FLUSH STATUS would need RELOAD, which
// the test user does not have — read the counter twice and subtract instead). The first two checks
// are the control that gives every check below it its meaning: without them a build that counted
// nothing because the table happened to be empty would pass the lot.
$db->exec("TRUNCATE TABLE index_hashes"); $db->exec("TRUNCATE TABLE index_files"); $db->exec("TRUNCATE TABLE index_polls");
@unlink(indexStateFile()); indexTotalCacheDrop(); indexStatusCacheDrop();

/** Rows read through an index on THIS connection. Nothing else in a tick moves it by thousands. */
$rowsRead = function () use ($db): int {
    $r = $db->query("SHOW SESSION STATUS LIKE 'Handler_read_next'")->fetch(PDO::FETCH_NUM);
    return (int)($r[1] ?? 0);
};
$setState = function (array $kv): void {
    indexStateUpdate(function (array &$s) use ($kv) { foreach ($kv as $k => $v) $s[$k] = $v; return true; });
};
$seed = function (int $from, int $count) use ($db): void {
    $vals = [];
    for ($i = 1; $i <= $count; $i++) $vals[] = "('" . h($from + $i) . "', NOW(), NOW() + INTERVAL 3 DAY, 'none', $i)";
    foreach (array_chunk($vals, 1000) as $chunk) {
        $db->exec("INSERT INTO index_hashes (info_hash, last_seen, grace_until, meta_status, last_seeders) VALUES " . implode(',', $chunk));
    }
};
$N = 3000;
$seed(20000, $N);
$noise = intdiv($N, 4);   // "a handful of rows" for a tick that did not count; a count costs $N

indexTotalCacheDrop();
$b = $rowsRead(); $coldTotal = indexTotalCached($db); $cold = $rowsRead() - $b;
check('control: a cold count really walks the whole table', $coldTotal === $N && $cold >= $N, "total=$coldTotal read=$cold of $N");
$b = $rowsRead(); indexTotalCached($db); $warm = $rowsRead() - $b;
check('control: a warm cached total reads no rows at all', $warm < $noise, "read=$warm");

// The tick's gate. index_meta_daily_budget stays at 0 so the only thing that could walk the table
// on these ticks is the count under test.
$cfgTick = $cfg;
$cfgTick['index_enabled'] = '1';
$cfgTick['index_meta_daily_budget'] = '0';
// Pin EVERY branch of the tick that can walk this table, not just the budget. Inheriting
// index_meta_auto_queue from whatever the database happens to hold made this measurement depend on
// the suite that ran before it: after the smokes it was on, indexQueueMetaAuto() walked the
// catalogue, and the count under test was blamed for 9 698 rows it never read.
$cfgTick['index_meta_auto_queue'] = '0';
$cfgTick['index_search_enabled'] = '0';
$cfgTick['index_keep_files'] = '0';
// And the poll interval, which is the one that actually bit: with a shorter interval inherited from
// whatever ran before, "no poll since the last tick" became a tick that POLLS, and the poll's own
// reads were charged to the count under test. Measured with a probe rather than reasoned about:
// indexPollDue() answered DUE and indexTick() came back having pruned 132 rows.
$cfgTick['index_poll_minutes'] = '1440';
$T = 2000000;

// (a) the stand-down: indexPrune() sets force_idle_until for a whole hour precisely because the last
// forced prune found nothing it could trim. The old condition ran the 939 ms count first and threw
// the answer away sixty times an hour.
$cfgTick['index_max_rows'] = '100';
$setState(['last_poll_at' => $T, 'last_tick_at' => $T - 60, 'last_prune_at' => $T, 'force_idle_until' => $T + 600, 'max_rows_seen' => 100, 'poll_skip' => 0]);
$b = $rowsRead(); $tkA = indexTick($db, $cfgTick, null, $T); $dA = $rowsRead() - $b;
check('the hour-long stand-down skips the count entirely', $tkA['prune'] === null && $dA < $noise, "read=$dA of $N, prune=" . json_encode($tkA['prune']));

// (b) an ordinary minute: no poll since the last tick, the cap where it was. Nothing on this side
// can have made the table bigger, so there is nothing to count.
$setState(['last_poll_at' => $T - 600, 'last_tick_at' => $T + 60, 'last_prune_at' => $T + 60, 'force_idle_until' => 0, 'max_rows_seen' => 100]);
$b = $rowsRead(); $tkB = indexTick($db, $cfgTick, null, $T + 60); $dB = $rowsRead() - $b;
check('an ordinary minute — no poll, cap unmoved — does not count', $tkB['prune'] === null && $dB < $noise, "read=$dB of $N");

// (c) a poll ran since the last tick: that is what buys the count. The table is under the cap here,
// and the prune is throttled, so the rows read are the count and nothing else.
// Reseeded, so that a build that failed (a) or (b) by trimming the table fails (c) and (d) on their
// own terms rather than on an empty table left behind by the check before them.
$db->exec("TRUNCATE TABLE index_hashes"); $seed(20000, $N);
$cfgTick['index_max_rows'] = '200000';
$setState(['last_poll_at' => $T + 180, 'last_tick_at' => $T + 120, 'last_prune_at' => $T + 180, 'force_idle_until' => 0, 'max_rows_seen' => 200000]);
$b = $rowsRead(); $tkC = indexTick($db, $cfgTick, null, $T + 180); $dC = $rowsRead() - $b;
check('a poll since the last tick is what pays for a count', $tkC['prune'] === null && $dC >= $N, "read=$dC of $N");

// (d) …and the behaviour the gate exists for is intact: an overshoot is trimmed on the same tick
// rather than at the top of the hour. Throttled prune, forced through by the gate.
$cfgTick['index_max_rows'] = '100';
$setState(['last_poll_at' => $T + 240, 'last_tick_at' => $T + 200, 'last_prune_at' => $T + 200, 'force_idle_until' => 0, 'max_rows_seen' => 100]);
$tkD = indexTick($db, $cfgTick, null, $T + 240);
check('an overshoot after a poll is still trimmed on the spot', $tkD['prune'] !== null && $tkD['prune']['capped'] === $N - 100 && indexRowsCount($db) === 100,
      json_encode([$tkD['prune'], indexRowsCount($db)]));

// (e) the one cost of the gate, paid back: an operator LOWERING index_max_rows in Settings is not a
// poll, so the state file remembers the cap the last tick ran under and a change is noticed on the
// next tick — a minute, not an hour.
$db->exec("TRUNCATE TABLE index_hashes"); $seed(30000, $N);
$cfgTick['index_max_rows'] = '100';
$setState(['last_poll_at' => $T + 300, 'last_tick_at' => $T + 360, 'last_prune_at' => $T + 300, 'force_idle_until' => 0, 'max_rows_seen' => 200000]);
$tkE = indexTick($db, $cfgTick, null, $T + 360);
check('lowering the cap is noticed on the next tick, with no poll in sight', $tkE['prune'] !== null && $tkE['prune']['capped'] === $N - 100, "capped=" . json_encode($tkE['prune']));
check('…and the tick remembers the new cap, so it is not re-noticed every minute', (int)indexStateRead()['max_rows_seen'] === 100, json_encode(indexStateRead()['max_rows_seen']));

// (f) the deliberate hole, asserted so nobody closes it by accident: rows that appear without a
// poll and without a cap change (worker/federation.py writes from Python and cannot mark the state
// file) do NOT buy an early trim. The hourly prune catches them, which is why the tick may skip.
$db->exec("TRUNCATE TABLE index_hashes");
$seed(40000, $N);
$setState(['last_poll_at' => $T + 400, 'last_tick_at' => $T + 460, 'last_prune_at' => $T + 400, 'force_idle_until' => 0, 'max_rows_seen' => 100]);
$b = $rowsRead(); $tkF = indexTick($db, $cfgTick, null, $T + 460); $dF = $rowsRead() - $b;
check('rows that arrived from outside PHP do not buy a count on every tick', $tkF['prune'] === null && $dF < $noise, "read=$dF of $N");
$prF = indexPrune($db, $cfgTick, $T + 400 + IDX_PRUNE_EVERY + 1);
check('…and the hourly prune still trims them, which is what makes skipping safe', $prF !== null && $prF['capped'] === $N - 100, json_encode($prF));

// (g) THE REGRESSION GUARD WITH TEETH. A cache poisoned with an absurd total must not be able to
// size a DELETE: the prune re-reads the exact count at its own line, and this asserts a NUMBER, so
// a future edit that "tidies" that call into indexTotalCached() fails here loudly.
$db->exec("TRUNCATE TABLE index_hashes");
$seed(50000, 50);
@file_put_contents(indexTotalCacheFile(), json_encode(['at' => time(), 'total' => 9999999]));
$cfgPoison = $cfgTick; $cfgPoison['index_max_rows'] = '40';
$setState(['last_prune_at' => 0, 'poll_skip' => 0, 'force_idle_until' => 0]);
$prG = indexPrune($db, $cfgPoison, time(), true);
check('a poisoned cache cannot inflate a delete: 10 capped, 40 left', $prG !== null && $prG['capped'] === 10 && indexRowsCount($db) === 40, json_encode([$prG['capped'] ?? null, indexRowsCount($db)]));

// (h) …nor reach the poll history. index_polls.index_rows is defined as what the catalogue held
// AFTER the poll, and the statistics timeline warms this very cache a second or two earlier in the
// same janitor tick — so a cached read here would write the PRE-poll total into the history and the
// coverage chart for ever, with nothing on screen to show it was wrong.
$db->exec("TRUNCATE TABLE index_hashes"); $db->exec("TRUNCATE TABLE index_polls"); @unlink(indexStateFile());
@file_put_contents(indexTotalCacheFile(), json_encode(['at' => time(), 'total' => 9999999]));
$entH = []; for ($i = 1; $i <= 10; $i++) $entH[] = [h(60000 + $i), 3, 1, 0];
$fileH = $tmp . '/idx_hist.gz'; makeScrape($entH, true, $fileH);
$tsH = 1500000;
$pH = indexPoll($db, $cfg, function () use ($fileH) { return ['file' => $fileH, 'gzip' => true]; }, $tsH);
$histRows = $db->query("SELECT index_rows FROM index_polls WHERE ts = $tsH")->fetchColumn();
check('the poll history records the total AFTER the poll, not a cache warmed before it',
      $pH['kept'] === 10 && (int)$histRows === 10, json_encode([$pH['kept'], $histRows]));

// (i) And the structural half, because two of the three sites above can regress in a way a passing
// suite would not notice for months: the deciding call sites must not even MENTION the cached
// total. Read through the tokenizer off the real function boundaries, so a comment that names it
// (this file is full of them) is not a false alarm, and with a positive control below proving the
// same check can still see a cached read where one belongs.
$callsCached = function (string $fn): bool {
    $r = new ReflectionFunction($fn);
    $src = implode('', array_slice(file($r->getFileName()), $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    foreach (token_get_all('<?php ' . $src) as $t) {
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'indexTotalCached') return true;
    }
    return false;
};
foreach (['indexPoll' => 'the poll history is the total AFTER the poll',
          'indexPrune' => 'this number sizes a DELETE',
          'indexTick' => 'this number stands one call away from that DELETE'] as $fn => $why) {
    check("$fn() reads the exact count, never the cache", !$callsCached($fn), $why);
}
check('control: the same check does see a cached read where one belongs (indexStatus)', $callsCached('indexStatus'));

$db->exec("TRUNCATE TABLE index_polls");
@unlink($fileH);
indexTotalCacheDrop(); indexStatusCacheDrop();

// cleanup
foreach (['index_hashes', 'index_files', 'whitelist', 'whitelist_files', 'banned_hashes'] as $t) $db->exec("TRUNCATE TABLE `$t`");

/* ── "search inside file lists" is a UNION of two indexed branches, not an OR ─────────────────
 *
 * THE BUG THIS EXISTS FOR: the clause was `MATCH(name) AGAINST(…) OR info_hash IN (…)`. A
 * disjunction over two different indexes cannot be served from either, so MariaDB scanned — and
 * with `ORDER BY eff_seeders` it chose to walk idx_index_eff_seed in order and filter as it went.
 * Measured on the live catalogue: 32.6 s for eighty-eight matching rows, against php-fpm's 30 s
 * max_execution_time, so the reader got an intermittent 500. The same search with "best match
 * first" ticked ordered by score instead and came in at 16 s, which is why it looked random.
 * Split into two branches and UNIONed: 4.6 s, same rows.
 *
 * What a small table can still prove is the part a rewrite like that gets wrong: that the two
 * branches together return exactly what the OR returned, ONCE each, under every sort — a row whose
 * name matches AND whose files match is one row, not two, and that is what UNION (not UNION ALL)
 * is doing there. */
foreach (['index_hashes', 'index_files', 'whitelist', 'whitelist_files'] as $t) $db->exec("TRUNCATE TABLE `$t`");
$mk = function (string $hash, string $name, int $seeders) use ($db) {
    $db->prepare("INSERT INTO index_hashes (info_hash, name, last_seeders, last_leechers, meta_status, total_size, files_count)
                  VALUES (?, ?, ?, 0, 'done', 1000, 1)")->execute([$hash, $name, $seeders]);
};
$file = function (string $hash, string $path) use ($db) {
    $db->prepare("INSERT INTO index_files (info_hash, path, size) VALUES (?, ?, 10)")->execute([$hash, $path]);
};
$H = ['name' => str_repeat('a1', 20), 'file' => str_repeat('b2', 20), 'both' => str_repeat('c3', 20), 'none' => str_repeat('d4', 20)];
$mk($H['name'], 'quokka documentary', 7);
$mk($H['file'], 'unrelated release',  9);
$mk($H['both'], 'quokka extras',      3);
$mk($H['none'], 'nothing to see',     5);
$file($H['name'], 'readme.txt');
$file($H['file'], 'quokka/episode1.mkv');
$file($H['both'], 'quokka/episode2.mkv');
$file($H['none'], 'other.bin');

$ask = function (array $extra) use ($db, $cfg): array {
    $r = indexSearchCatalogue($db, $cfg, $extra + ['search' => 'quokka', 'page' => 1, 'per_page' => 50]);
    return $r;
};
$namesOf = static fn(array $r): array => array_column($r['rows'], 'info_hash');

$plain = $ask(['sort' => 'relevance:desc']);
check('files off: only the names match', count($plain['rows']) === 2 && $plain['total'] === 2,
    implode(',', $namesOf($plain)));

foreach (['relevance:desc', 'seeders:desc', 'seeders:asc', 'name:asc', 'last:desc'] as $sort) {
    $r = $ask(['sort' => $sort, 'search_files' => 1]);
    $got = $namesOf($r);
    sort($got);
    $want = [$H['name'], $H['both'], $H['file']];
    sort($want);
    check("files on, sort=$sort: name-match, file-match and both — each exactly once",
        $got === $want && count($got) === 3, implode(',', $got));
    check("files on, sort=$sort: total agrees with the rows", (int)$r['total'] === 3, (string)$r['total']);
}

// The sort still has to sort. If the union arm were ordered by a column the derived table does not
// expose, MariaDB would refuse the query outright — this is the check that would catch that.
$asc = $ask(['sort' => 'seeders:asc', 'search_files' => 1]);
$desc = $ask(['sort' => 'seeders:desc', 'search_files' => 1]);
check('files on: ascending and descending are reverses of one another',
    $namesOf($asc) === array_reverse($namesOf($desc)),
    implode(',', $namesOf($asc)) . ' | ' . implode(',', $namesOf($desc)));

// A short term skips fulltext and falls back to LIKE — the same OR lived on that branch too.
$short = indexSearchCatalogue($db, $cfg, ['search' => 'quok', 'search_files' => 1, 'sort' => 'seeders:desc', 'page' => 1, 'per_page' => 50]);
check('the LIKE fallback branch searches file names too', count($short['rows']) === 3, (string)count($short['rows']));

// TWO ARMS. The exclusion that keeps a hash from being returned by both tables used to be
// concatenated onto the finished arm SQL; with a UNION arm that would have attached it to the last
// branch only, so the duplicate would come back through the first one.
$wlHash = str_repeat('e5', 20);
$db->prepare("INSERT INTO whitelist (info_hash, name, total_size, files_count, source, created_at, banned, content_status)
              VALUES (?, 'quokka on the whitelist', 500, 1, 'admin', NOW(), 0, 'none')")->execute([$wlHash]);
$wlRowId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO whitelist_files (whitelist_id, path, size) VALUES (?, 'quokka/wl.mkv', 10)")->execute([$wlRowId]);
$mk($wlHash, 'quokka on the whitelist', 11);          // the same hash in BOTH tables, as between polls
$file($wlHash, 'quokka/wl.mkv');
foreach (['relevance:desc', 'seeders:desc'] as $sort) {
    $r = indexSearchCatalogue($db, $cfg, ['search' => 'quokka', 'search_files' => 1, 'include_whitelist' => true,
                                          'sort' => $sort, 'page' => 1, 'per_page' => 50]);
    $hashes = array_column($r['rows'], 'info_hash');
    $at = array_search($wlHash, $hashes, true);
    check("two arms, sort=$sort: a hash in both tables is returned once",
        count(array_keys($hashes, $wlHash, true)) === 1, implode(',', $hashes));
    check("two arms, sort=$sort: and it is the whitelist row that wins",
        $at !== false && ($r['rows'][$at]['src'] ?? '') === 'whitelist', json_encode($at === false ? null : $r['rows'][$at]));
    check("two arms, sort=$sort: four distinct rows in total",
        count($hashes) === 4 && count(array_unique($hashes)) === 4 && (int)$r['total'] === 4,
        implode(',', $hashes) . ' total=' . $r['total']);
}

$db->exec("TRUNCATE TABLE index_hashes"); $db->exec("TRUNCATE TABLE index_files");


/* The panel's own listing (indexListSelect) had the same OR, and now has the same split. Different function, same
 * trap, so it gets its own check rather than being assumed to travel with the public one. */
foreach (['index_hashes', 'index_files'] as $t) $db->exec("TRUNCATE TABLE `$t`");
$mk($H['name'], 'wombat documentary', 7);
$mk($H['file'], 'unrelated release',  9);
$mk($H['both'], 'wombat extras',      3);
$mk($H['none'], 'nothing to see',     5);
$file($H['name'], 'readme.txt');
$file($H['file'], 'wombat/episode1.mkv');
$file($H['both'], 'wombat/episode2.mkv');
$file($H['none'], 'other.bin');
foreach (['last:desc', 'seeders:desc', 'name:asc', 'size:desc'] as $sort) {
    $r = indexListSelect($db, $cfg, ['search' => 'wombat', 'search_files' => 1, 'sort' => $sort, 'page' => 1, 'per_page' => 50]);
    $got = array_column($r['rows'], 'info_hash');
    sort($got);
    $want = [$H['name'], $H['both'], $H['file']];
    sort($want);
    check("panel listing, sort=$sort: name, file and both — each exactly once",
        $got === $want && (int)$r['total'] === 3, implode(',', $got) . ' total=' . $r['total']);
}
$noFiles = indexListSelect($db, $cfg, ['search' => 'wombat', 'sort' => 'last:desc', 'page' => 1, 'per_page' => 50]);
check('panel listing without the file search: only the names match',
    count($noFiles['rows']) === 2 && (int)$noFiles['total'] === 2, (string)$noFiles['total']);
$shortP = indexListSelect($db, $cfg, ['search' => 'womb', 'search_files' => 1, 'sort' => 'last:desc', 'page' => 1, 'per_page' => 50]);
check('panel listing: the LIKE fallback searches file names too', count($shortP['rows']) === 3, (string)count($shortP['rows']));
$db->exec("TRUNCATE TABLE index_hashes"); $db->exec("TRUNCATE TABLE index_files");

@unlink(indexStateFile());
foreach (glob($tmp . '/idx*.gz') ?: [] as $f) @unlink($f);
@unlink($tmp . '/idx_plain.bin');

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
