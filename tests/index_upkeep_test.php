<?php
/**
 * The index's upkeep, measured rather than read (1.74.0):
 *   php tests/index_upkeep_test.php           (needs the local test database)
 *
 *   PERF-4  indexStatus(): the queue states, the rows in grace and the rows whose grace ends within a day out of ONE
 *           pass — the same numbers the three old queries gave; a second look inside IDX_STATUS_TTL asks the database
 *           nothing; the operator's own actions drop the cache; the file count is exact on a small table.
 *   PERF-5  indexPrune(): what it deletes takes its file rows with it BY KEY (DELETE … RETURNING) — a pending row
 *           with files (a re-fetch's) included — without the anti-join over index_files, which now runs once a day.
 *   VPERF-1 a resolved row asked for again (indexRequestMeta(), the 'all' scope) is not deleted by the next prune
 *           for a grace that ran out long ago: it gets the time a new row gets, and never less than its protection.
 *
 * Every prune here runs inside a transaction that is rolled back, so the test database's other rows stay as they
 * were; its own rows carry their own hash prefix and are removed; config/index_state.json is put back.
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
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . mb_substr($info, 0, 500)) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$GLOBALS['cfg'] = $cfg;
$db->exec(schemaIndexCatalogDdl('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
indexCatalogExists($db, true);

const UP = '7e57a9ee';
function uh(int $i): string { return UP . sprintf('%032x', $i); }
$stateFile = indexStateFile();
$stateWas = is_file($stateFile) ? (string)file_get_contents($stateFile) : null;
$cleanup = static function () use ($db, $stateFile, $stateWas): void {
    foreach (['index_files', 'index_catalog', 'index_hashes'] as $t) $db->prepare("DELETE FROM `$t` WHERE info_hash LIKE ?")->execute([UP . '%']);
    if ($stateWas === null) @unlink($stateFile); else file_put_contents($stateFile, $stateWas);
    indexStatusCacheDrop(); indexTotalCacheDrop();
};
$cleanup();
$st = static function (PDO $db, string $name): int {
    return (int)($db->query("SHOW SESSION STATUS LIKE '$name'")->fetch(PDO::FETCH_NUM)[1] ?? 0);
};
$alive = static function (PDO $db, string $h): bool {
    return (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash = '$h'")->fetchColumn() === 1;
};
$filesOf = static function (PDO $db, string $h): int {
    return (int)$db->query("SELECT COUNT(*) FROM index_files WHERE info_hash = '$h'")->fetchColumn();
};
$cfgP = array_merge($cfg, ['index_max_rows' => '5000000', 'index_grace_days' => '3', 'index_protect_days' => '10', 'index_keep_saved' => 'off']);

try {
/* ── PERF-4: the status card ──────────────────────────────────────────────── */
$ins = $db->prepare("INSERT INTO index_hashes (info_hash, name, last_seen, grace_until, protected_until, meta_status, meta_fetched_at)
                     VALUES (?, ?, NOW(), ?, ?, ?, ?)");
$rows = [
    [uh(1), null, '+2 DAY', null, 'pending', null],       // in grace
    [uh(2), null, '+12 HOUR', null, 'pending', null],     // in grace, expiring within a day
    [uh(3), null, '-1 DAY', null, 'none', null],          // past grace (expiring: it is < NOW() + 1 DAY)
    [uh(4), 'resolved', '-5 DAY', '+5 DAY', 'done', '-1 HOUR'],
    [uh(5), null, '+3 DAY', null, 'failed', '-2 HOUR'],
    [uh(6), null, null, null, 'fetching', null],          // no grace at all
];
foreach ($rows as [$h, $name, $g, $p, $s, $f]) {
    $ins->execute([$h, $name, $g === null ? null : date('Y-m-d H:i:s', strtotime($g)), $p === null ? null : date('Y-m-d H:i:s', strtotime($p)), $s,
                   $f === null ? null : date('Y-m-d H:i:s', strtotime($f))]);
}
indexStatusCacheDrop();
$s1 = indexStatus($db, $cfg);
$c = $s1['counts'];
$old = [
    'in_grace' => (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status <> 'done' AND grace_until >= NOW()")->fetchColumn(),
    'expiring_24h' => (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE meta_status <> 'done' AND grace_until IS NOT NULL AND grace_until < NOW() + INTERVAL 1 DAY")->fetchColumn(),
    'total' => (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn(),
    'files' => (int)$db->query("SELECT COUNT(*) FROM index_files")->fetchColumn(),
];
$byStatus = [];
foreach ($db->query("SELECT meta_status, COUNT(*) c FROM index_hashes GROUP BY meta_status") as $r) $byStatus['meta_' . $r['meta_status']] = (int)$r['c'];
$mismatch = [];
foreach (['in_grace', 'total', 'files'] as $k) if ($c[$k] !== $old[$k]) $mismatch[$k] = [$c[$k], $old[$k]];
if ($s1['flow']['expiring_24h'] !== $old['expiring_24h']) $mismatch['expiring_24h'] = [$s1['flow']['expiring_24h'], $old['expiring_24h']];
foreach (['meta_none', 'meta_pending', 'meta_fetching', 'meta_done', 'meta_failed'] as $k) if ($c[$k] !== ($byStatus[$k] ?? 0)) $mismatch[$k] = [$c[$k], $byStatus[$k] ?? 0];
check('one pass gives what the separate queries gave: every queue state, the rows in grace, the ones expiring within a day, the total',
      $mismatch === [], json_encode($mismatch));
check('… on a table this small the file count is exact, and says so', $c['files_approx'] === false && $c['files'] === $old['files']);
check('… the card\'s shape is the one admin-index.js reads (expiring in flow, not among the counts)',
      !array_key_exists('expiring_24h', $c) && isset($s1['flow']['resolved_24h']) && array_key_exists('days_to_cover', $s1['flow']));
$sel0 = $st($db, 'Com_select');
$sel1 = $st($db, 'Com_select');          // what one look at the counter costs by itself
$s2 = indexStatus($db, $cfg);
$sel2 = $st($db, 'Com_select');
$sel = ($sel2 - $sel1) - ($sel1 - $sel0);
check('a second look inside IDX_STATUS_TTL (' . IDX_STATUS_TTL . ' s) asks the database nothing — not one SELECT',
      $sel === 0 && $s2['counts'] === $s1['counts'], "selects=$sel");
check('… and IDX_STATUS_TTL is at least the five minutes the card can wait', IDX_STATUS_TTL >= 300);
$f = indexStatusCacheFile('counts');
indexRequestMeta($db, [uh(5)], 5, $cfgP);
check('the operator\'s own action on the page drops the cache, so the card shows it at once', !is_file($f));
indexStatus($db, $cfg);
$db->beginTransaction();          // cancelling touches every queued row of the database: put back afterwards
indexMetaCancel($db);
$db->rollBack();
check('… and so does cancelling the queue', !is_file($f));

/* ── PERF-5: the prune takes files by key ─────────────────────────────────── */
$db->exec("DELETE FROM index_hashes WHERE info_hash LIKE '" . UP . "%'");
$mk = $db->prepare("INSERT INTO index_hashes (info_hash, name, last_seen, grace_until, protected_until, meta_status) VALUES (?, ?, NOW(), ?, ?, ?)");
$mk->execute([uh(10), 'expired done', date('Y-m-d H:i:s', time() - 9 * 86400), date('Y-m-d H:i:s', time() - 86400), 'done']);
$mk->execute([uh(11), 'expired re-fetch', date('Y-m-d H:i:s', time() - 9 * 86400), null, 'pending']);   // flipped done → pending, files kept
$mk->execute([uh(12), 'kept', date('Y-m-d H:i:s', time() + 86400), date('Y-m-d H:i:s', time() + 9 * 86400), 'done']);
$db->exec("INSERT INTO index_files (info_hash, path, size) VALUES ('" . uh(10) . "', 'a.iso', 1), ('" . uh(10) . "', 'b.iso', 2), ('" . uh(11) . "', 'c.mkv', 3)");
// 12 000 file rows of a row that stays: a scan of index_files would have to walk past every one of them
$db->exec("INSERT INTO index_files (info_hash, path, size) SELECT '" . uh(12) . "', CONCAT('kept/', seq, '.bin'), seq FROM seq_1_to_12000");
indexCatalogSync($db, [uh(10), uh(11), uh(12)]);
$keepFiles = $filesOf($db, uh(12));
// a file row whose torrent is gone, which only the daily sweep looks for
$db->exec("INSERT INTO index_files (info_hash, path, size) VALUES ('" . uh(13) . "', 'orphan.bin', 1)");
// The same prune twice on the same rows (each rolled back): (A) the daily sweep not due, (B) due. Whatever else the
// prune reads (the rest of index_hashes) is the same in both, so B − A is what walking index_files costs — and A has
// to have done its file work without it.
$prune = static function (bool $sweepDue) use ($db, $cfgP, $st, $alive, $filesOf, $keepFiles): array {
    indexStateUpdate(function (array &$s) use ($sweepDue) { $s['orphan_sweep_at'] = $sweepDue ? time() - 86401 : time(); $s['protect_backfill_at'] = time(); return true; });
    $db->beginTransaction();
    $r0 = $st($db, 'Handler_read_next') + $st($db, 'Handler_read_rnd_next');
    $pr = indexPrune($db, $cfgP, null, true);
    $out = ['pr' => $pr, 'reads' => $st($db, 'Handler_read_next') + $st($db, 'Handler_read_rnd_next') - $r0,
            'gone10' => !$alive($db, uh(10)) && $filesOf($db, uh(10)) === 0,
            'gone11' => !$alive($db, uh(11)) && $filesOf($db, uh(11)) === 0,
            'kept12' => $alive($db, uh(12)) && $filesOf($db, uh(12)) === $keepFiles,
            'catOut' => (int)$db->query("SELECT COUNT(*) FROM index_catalog WHERE info_hash IN ('" . uh(10) . "', '" . uh(11) . "')")->fetchColumn() === 0,
            'orphan' => $filesOf($db, uh(13)), 'swept_at' => (int)indexStateRead()['orphan_sweep_at']];
    $db->rollBack();
    return $out;
};
$A = $prune(false);
$B = $prune(true);
check('the prune deletes an expired resolved row AND its files', $A['pr'] !== null && $A['gone10'], json_encode($A['pr']));
check('… an expired re-fetch (pending, with the files of when it was done) too — not only `done` rows have files', $A['gone11']);
check('… leaves the row that stays and its 12 000 files alone, and takes the deleted ones out of the catalogue', $A['kept12'] && $A['catOut']);
check(sprintf('… WITHOUT walking index_files: %d reads for the whole prune, %d with the daily sweep (the walk of 12 000+ file rows)', $A['reads'], $B['reads']),
      $B['reads'] - $A['reads'] >= 12000 && (int)$A['pr']['orphan_files'] >= 3 && $A['orphan'] === 1, json_encode([$A['reads'], $B['reads'], $A['pr']]));
check('once a day the sweep still finds a file row whose torrent is gone, and records that it ran',
      $B['orphan'] === 0 && $B['gone10'] && $B['swept_at'] >= time() - 5, json_encode($B['pr']));
$db->exec("DELETE FROM index_files WHERE info_hash = '" . uh(13) . "'");

/* ── VPERF-1: asked again, not deleted for an old grace ───────────────────── */
$mk->execute([uh(20), 'resolved, protected', date('Y-m-d H:i:s', time() - 30 * 86400), date('Y-m-d H:i:s', time() + 5 * 86400), 'done']);
$mk->execute([uh(21), 'resolved, protection over', date('Y-m-d H:i:s', time() - 30 * 86400), date('Y-m-d H:i:s', time() - 86400), 'done']);
$mk->execute([uh(22), 'resolved, by scope', date('Y-m-d H:i:s', time() - 30 * 86400), date('Y-m-d H:i:s', time() + 2 * 86400), 'done']);
$mk->execute([uh(23), null, date('Y-m-d H:i:s', time() - 86400), null, 'failed']);   // never resolved: its grace stays its grace
// the control: the same flip with the grace left where it was — what indexRequestMeta() did until 1.74.0
$mk->execute([uh(24), 'flipped the old way', date('Y-m-d H:i:s', time() - 30 * 86400), date('Y-m-d H:i:s', time() + 5 * 86400), 'done']);
indexRequestMeta($db, [uh(20), uh(21), uh(23)], 5, $cfgP);
$db->exec("UPDATE index_hashes SET meta_status = 'pending' WHERE info_hash = '" . uh(24) . "'");
$g = static fn(string $h) => (string)$db->query("SELECT grace_until FROM index_hashes WHERE info_hash = '$h'")->fetchColumn();
$p = static fn(string $h) => (string)$db->query("SELECT protected_until FROM index_hashes WHERE info_hash = '$h'")->fetchColumn();
check('asking for a resolved row again gives it a grace reaching at least its protection',
      strtotime($g(uh(20))) >= strtotime($p(uh(20))) - 1, $g(uh(20)) . ' vs ' . $p(uh(20)));
check('… and at least index_grace_days from now when its protection is already over',
      strtotime($g(uh(21))) >= time() + 3 * 86400 - 120, $g(uh(21)));
check('… while a row that never resolved keeps the grace it had (no free extension for the unresolved queue)',
      strtotime($g(uh(23))) < time(), $g(uh(23)));
// the 'all' scope re-asks every resolved row in one statement — on a transaction, the test database's other rows are put back
$db->beginTransaction();
indexQueueMetaByScope($db, 'all', $cfgP);
$scopeGrace = $g(uh(22));
$scopeStatus = (string)$db->query("SELECT meta_status FROM index_hashes WHERE info_hash = '" . uh(22) . "'")->fetchColumn();
$db->rollBack();
check('… and the \'all\' scope does the same for every resolved row it re-queues', $scopeStatus === 'pending' && strtotime($scopeGrace) >= time() + 2 * 86400 - 120, "$scopeStatus $scopeGrace");
$db->beginTransaction();
indexPrune($db, $cfgP, null, true);
$a20 = $alive($db, uh(20)); $a21 = $alive($db, uh(21)); $a24 = $alive($db, uh(24));
$db->rollBack();
check('the next prune leaves both re-fetches alone', $a20 && $a21);
check('… where the old flip (grace untouched) is deleted at once, protected or not — the bug this closes', !$a24);
} finally {
    $cleanup();
}
check('cleanup: nothing of this run is left', (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash LIKE '" . UP . "%'")->fetchColumn() === 0
      && (int)$db->query("SELECT COUNT(*) FROM index_files WHERE info_hash LIKE '" . UP . "%'")->fetchColumn() === 0);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
