<?php
/**
 * The public catalogue's narrow table, index_catalog (1.74.0, PERF-2 / PERF-3), and the search around it:
 *   php tests/index_catalog_test.php            (needs the local test database — deploy/local_bootstrap.php)
 *
 *   1. the table, and the keepers: a sync writes members and takes the rest out; the rolling walk fills it from
 *      nothing, takes out what is gone or no longer named, puts changed values right, and records its pass;
 *   2. THE SAME ANSWERS: every public sort, browsing and searching (fulltext, the LIKE fallback, a hash prefix, file
 *      names), the content filters, with and without the whitelist arm, page after page — the catalogue path
 *      returns exactly what the wide table returns, row for row;
 *   3. the gate: the search reads the catalogue only after a whole pass over a table big enough to need it;
 *   4. the writers that keep it: the poll's batches and its whitelist removal, indexDelete(), the prune, what the
 *      Python writers resolve (indexCatalogSyncRecent()), federation's purge;
 *   5. the capped count (PERF-2 (1)): a search counts to max(1000, offset + 10 pages) and says "more";
 *   6. a search the database stops for time (production 2026-10-06) is IndexSearchTimeout — the LIKE fallback is
 *      not run behind it — while any other failure of the fulltext pass still falls back to LIKE;
 *   7. the ranking keys (1.75.0): words_hit and in_order in the three orders, the wide table and the catalogue giving the
 *      same order, the SQL of a one-word query unchanged, the all-words fast path answering the page the full query gives,
 *      a hash found by name and by file name coming back once, the whitelist merged by words_hit, the hex rule, the LIKE
 *      escape.
 *
 * Its rows carry their own hash prefix and are removed at the end; config/index_state.json is put back as it was.
 * It TRUNCATEs nothing.
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
require_once $root . '/includes/federation.php';
require_once $root . '/includes/lang.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . mb_substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
$GLOBALS['cfg'] = $cfg;
// The table as the v93 migration makes it (until the release's version bump, the CREATE runs here too — the same DDL).
$db->exec(schemaIndexCatalogDdl('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
indexCatalogExists($db, true);

const CT = '7e57ca7a';   // every hash of this file starts so
function ch(int $i): string { return CT . sprintf('%032x', $i); }

$stateFile = indexStateFile();
$stateWas = is_file($stateFile) ? (string)file_get_contents($stateFile) : null;
$dropCatCaches = static function () use ($root): void {
    foreach (glob($root . '/config/index_cat*_cache.json') ?: [] as $f) @unlink($f);
};
$cleanup = static function () use ($db, $stateFile, $stateWas, $dropCatCaches): void {
    $like = CT . '%';
    foreach (['index_files', 'index_catalog', 'index_hashes', 'banned_hashes'] as $t) $db->prepare("DELETE FROM `$t` WHERE info_hash LIKE ?")->execute([$like]);
    $db->prepare("DELETE f FROM whitelist_files f JOIN whitelist w ON w.id = f.whitelist_id WHERE w.info_hash LIKE ?")->execute([$like]);
    $db->prepare("DELETE FROM whitelist WHERE info_hash LIKE ?")->execute([$like]);
    $db->exec("DELETE FROM index_polls WHERE ts = 1747000000");
    if ($stateWas === null) @unlink($stateFile); else file_put_contents($stateFile, $stateWas);
    $dropCatCaches();
    indexTotalCacheDrop(); indexStatusCacheDrop();
};
$cleanup();

try {
/* ── fixtures ─────────────────────────────────────────────────────────────── */
$words = ['quokka', 'wombat', 'numbat', 'dingo', 'echidna'];
$ins = $db->prepare("INSERT INTO index_hashes (info_hash, name, first_seen, last_seen, seen_count, last_seeders, last_leechers, last_completed,
                         grace_until, protected_until, meta_status, meta_fetched_at, total_size, files_count, scrape_seeders, scrape_leechers, scraped_at)
                     VALUES (?, ?, NOW(), NOW() - INTERVAL ? MINUTE, 1, ?, ?, 0, NOW() + INTERVAL 3 DAY, ?, ?, ?, ?, ?, ?, ?, ?)");
$named = [];
for ($i = 1; $i <= 40; $i++) {
    $name = $words[$i % 5] . ' ' . $words[($i * 3) % 5] . ' release ' . $i . ($i % 7 === 0 ? ' quokka' : '');
    $scr = $i % 4 === 0 ? ($i * 5) % 23 : null;            // a scrape figure beside the announce one, sometimes
    $ins->execute([ch($i), $name, ($i * 37) % 500, ($i * 13) % 17, ($i * 7) % 11, $i % 9 === 0 ? null : date('Y-m-d H:i:s', time() + 864000),
                   $i % 9 === 0 ? 'pending' : 'done', date('Y-m-d H:i:s', time() - 7200), $i % 6 === 0 ? null : $i * 1048576 + ($i % 3),
                   $i % 5 === 0 ? null : 1 + $i % 4, $scr, $scr === null ? null : $i % 6, $scr === null ? null : date('Y-m-d H:i:s')]);
    $named[] = ch($i);
}
// done without a name (the old filter lists those too — and so does the catalogue, as an empty name)
for ($i = 41; $i <= 43; $i++) $ins->execute([ch($i), null, $i, 3, 1, null, 'done', date('Y-m-d H:i:s'), 5000, 1, null, null, null]);
// never resolved, no name: not in the catalogue
for ($i = 44; $i <= 53; $i++) $ins->execute([ch($i), null, $i, 9, 2, null, 'pending', null, null, null, null, null, null]);
// files: a wombat whose FILE says quokka, and the quokka rows' own files
$db->prepare("INSERT INTO index_files (info_hash, path, size) VALUES (?, 'quokka/ep1.mkv', 10), (?, 'wombat/readme.txt', 5), (?, 'quokka/extras.mkv', 7)")
   ->execute([ch(1), ch(7), ch(14)]);
// the whitelist arm: one hash in both tables (as between two polls), one only in the whitelist
$db->prepare("INSERT INTO whitelist (info_hash, name, total_size, files_count, source, created_at, banned, content_status, meta_status, scrape_seeders)
              VALUES (?, 'quokka on the whitelist', 777, 1, 'admin', NOW(), 0, 'approved', 'done', 15),
                     (?, 'numbat whitelisted only', 888, 2, 'admin', NOW(), 0, 'none', 'done', 4)")->execute([ch(3), ch(60)]);
$members = array_merge($named, [ch(41), ch(42), ch(43)]);
$catHas = static function (PDO $db, string $h): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM index_catalog WHERE info_hash = ?"); $st->execute([$h]); return (int)$st->fetchColumn() === 1;
};
$catRow = static function (PDO $db, string $h): ?array {
    $st = $db->prepare("SELECT * FROM index_catalog WHERE info_hash = ?"); $st->execute([$h]); return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$mine = static function (PDO $db): array {
    return $db->query("SELECT info_hash FROM index_catalog WHERE info_hash LIKE '" . CT . "%' ORDER BY info_hash")->fetchAll(PDO::FETCH_COLUMN);
};

/* ── 1. the table, the sync, the walk ─────────────────────────────────────── */
$keys = array_column($db->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'index_catalog'")->fetchAll(PDO::FETCH_ASSOC), 'INDEX_NAME');
$want = ['PRIMARY', 'idx_cat_seed', 'idx_cat_leech', 'idx_cat_size', 'idx_cat_files', 'idx_cat_last', 'idx_cat_name', 'ft_cat_name'];
check('index_catalog exists with a key for every public order and its own fulltext', array_diff($want, $keys) === [], json_encode(array_values(array_unique($keys))));

indexCatalogSync($db, [ch(1), ch(2), ch(44), ch(41)]);
check('a sync writes the members among the hashes it is given (a name, or done without one) and nothing else',
      $catHas($db, ch(1)) && $catHas($db, ch(2)) && $catHas($db, ch(41)) && !$catHas($db, ch(44)));
$r1 = $catRow($db, ch(1));
$h1 = $db->query("SELECT name, COALESCE(scrape_seeders, last_seeders) s, COALESCE(scrape_leechers, last_leechers) l, total_size, files_count, last_seen FROM index_hashes WHERE info_hash = '" . ch(1) . "'")->fetch(PDO::FETCH_ASSOC);
check('… carrying exactly what index_hashes says: the name, the seeders and leechers the catalogue sorts by, size, files, last seen',
      $r1 !== null && $r1['name'] === $h1['name'] && (int)$r1['eff_seeders'] === (int)$h1['s'] && (int)$r1['eff_leechers'] === (int)$h1['l']
      && $r1['total_size'] === $h1['total_size'] && $r1['files_count'] === $h1['files_count'] && $r1['last_seen'] === $h1['last_seen'], json_encode([$r1, $h1]));
check('… and a done row without a name is in it with an empty one', ($catRow($db, ch(41))['name'] ?? null) === '');
$db->exec("UPDATE index_hashes SET name = NULL, meta_status = 'none' WHERE info_hash = '" . ch(2) . "'");
indexCatalogSync($db, [ch(2)]);
check('a row that stopped being a member leaves on its next sync', !$catHas($db, ch(2)));
$db->prepare("UPDATE index_hashes SET name = ?, meta_status = 'done' WHERE info_hash = ?")->execute([$words[2] . ' ' . $words[1] . ' release 2', ch(2)]);
indexCatalogSync($db, [ch(2)], false);
check('… and $mayLeave false (the poll, the scrapes) still writes the members', $catHas($db, ch(2)));

// the walk, from nothing: a stray row it must take out, a changed value it must put right
$db->exec("DELETE FROM index_catalog WHERE info_hash LIKE '" . CT . "%'");
$db->prepare("INSERT INTO index_catalog (info_hash, name, eff_seeders, eff_leechers, total_size, files_count, last_seen) VALUES (?, 'gone', 1, 1, 1, 1, NOW())")->execute([ch(99)]);
@unlink($stateFile);
$passes = 0;
do { $w = indexCatalogWalk($db, 5.0); $passes++; } while (!$w['passed'] && $passes < 1000);
$s = indexStateRead();
$allRows = (int)$db->query("SELECT COUNT(*) FROM index_hashes")->fetchColumn();
check('the walk goes round the whole table and records its pass (when, and how many rows it walked)',
      $w['passed'] && (int)$s['catalog_pass_at'] > 0 && (int)$s['catalog_pass_rows'] === $allRows && $s['catalog_cursor'] === '',
      json_encode([$w, $s['catalog_pass_rows'], $allRows]));
$got = $mine($db); sort($members);
check('… and fills the catalogue with exactly the members — the stray row out, nothing unnamed in', $got === $members,
      json_encode(array_values(array_diff($got, $members))) . ' / missing ' . json_encode(array_values(array_diff($members, $got))));
check('… everywhere, not only in these rows: the catalogue has as many rows as index_hashes has members',
      (int)$db->query("SELECT COUNT(*) FROM index_catalog")->fetchColumn() === (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE " . IDX_CATALOG_MEMBER)->fetchColumn());
$db->exec("UPDATE index_hashes SET last_seeders = 4242, scrape_seeders = NULL WHERE info_hash = '" . ch(5) . "'");
$db->exec("UPDATE index_hashes SET name = NULL, meta_status = 'failed' WHERE info_hash = '" . ch(6) . "'");
do { $w = indexCatalogWalk($db, 5.0, 1000000); } while (!$w['passed']);
check('a later pass puts a changed value right and takes out a row that lost its name',
      (int)($catRow($db, ch(5))['eff_seeders'] ?? 0) === 4242 && !$catHas($db, ch(6)), json_encode($catRow($db, ch(5))));
$db->prepare("UPDATE index_hashes SET name = ?, meta_status = 'done' WHERE info_hash = ?")->execute([$words[1] . ' ' . $words[3] . ' release 6', ch(6)]);
indexCatalogSync($db, [ch(6)]);
$w = indexCatalogWalk($db);
check('after a pass, a tick walks IDX_CATALOG_KEEP_ROWS at most (the safety net, not the keeper)', $w['rows'] <= IDX_CATALOG_KEEP_ROWS, json_encode($w));

/* ── 3. the gate ──────────────────────────────────────────────────────────── */
check('the search does not read the catalogue for a table smaller than IDX_CATALOG_MIN_ROWS, pass or not',
      $allRows >= IDX_CATALOG_MIN_ROWS || indexCatalogReady($db) === false);
check('… forced (the tests), it does once a pass exists; forced off, never', indexCatalogReady($db, true) === true && indexCatalogReady($db, false) === false);
@unlink($stateFile);
check('… and never before a whole pass has been made, forced or not', indexCatalogReady($db, true) === false);
do { $w = indexCatalogWalk($db, 5.0); } while (!$w['passed']);

/* ── 2. the same answers ──────────────────────────────────────────────────── */
$norm = static function (array $rows): array {
    return array_map(static fn(array $r) => array_map(static fn($v) => $v === null ? null : (string)$v, $r), $rows);
};
$both = static function (array $q) use ($db, $cfg, $dropCatCaches): array {
    $dropCatCaches();
    $old = indexSearchCatalogue($db, $cfg, $q + ['catalog' => false]);
    $dropCatCaches();
    $new = indexSearchCatalogue($db, $cfg, $q + ['catalog' => true]);
    return [$old, $new];
};
$sorts = ['relevance:desc', 'seeders:desc', 'seeders:asc', 'leechers:desc', 'size:desc', 'size:asc', 'last:desc', 'name:asc', 'name:desc',
          'files:desc', 'size:desc,seeders:asc', 'name:asc,leechers:desc'];
// 'ok' is two characters: too short for the fulltext index, so it is the LIKE branch (the endpoint refuses it from a
// browser; the function still answers it, and that branch is the fallback's).
$queries = ['browse' => ['search' => ''], 'fulltext' => ['search' => 'quokka'], 'files' => ['search' => 'quokka', 'search_files' => 1],
            'two words' => ['search' => 'wombat numbat'], 'three letters' => ['search' => 'ech'], 'like' => ['search' => 'ok'],
            'like + files' => ['search' => 'ok', 'search_files' => 1],
            'hash prefix' => ['search' => CT . '0000'], 'content=approved' => ['search' => '', 'content' => 'approved'],
            'content=none' => ['search' => 'numbat', 'content' => 'none']];
$diff = [];
$cases = 0;
$relevanceCases = [];
foreach ($queries as $label => $base) {
    foreach ($sorts as $sort) {
        foreach ([false, true] as $wl) {
            foreach ([1, 2, 3] as $page) {
                $q = $base + ['sort' => $sort, 'include_whitelist' => $wl, 'page' => $page, 'per_page' => 7];
                [$old, $new] = $both($q);
                $cases++;
                $sameRows = $norm($old['rows']) === $norm($new['rows']);
                // Relevance is a FULLTEXT index's own number. Two words: each index weighs a word by how rare it is in
                // THEIR rows, so the ranking may differ. With the whitelist arm: its rows are scored by the whitelist's
                // index and the others by the catalogue's (before, by index_hashes') — numbers from two indexes, so where
                // the whitelist's rows fall among the others was never a comparison of like with like and may move.
                // Those cases are checked on the whole list below (the same rows; each arm in its own order).
                if (!$sameRows && str_starts_with($sort, 'relevance') && ($label === 'two words' || $wl)) {
                    $relevanceCases[] = $base + ['sort' => $sort, 'include_whitelist' => $wl];
                    $sameRows = true;
                }
                if (!$sameRows || $old['total'] !== $new['total'] || $old['pages'] !== $new['pages'] || $old['total_capped'] !== $new['total_capped']) {
                    $diff[] = "$label / $sort / wl=" . (int)$wl . " / p$page: old " . $old['total'] . ' [' . implode(',', array_map(fn($r) => substr($r['info_hash'], -4), $old['rows']))
                            . '] new ' . $new['total'] . ' [' . implode(',', array_map(fn($r) => substr($r['info_hash'], -4), $new['rows'])) . ']';
                }
            }
        }
    }
}
check("the catalogue answers every case as the wide table does — $cases cases: rows (every column), totals, pages",
      $diff === [], implode(' | ', array_slice($diff, 0, 6)));
$all = static function (array $q, bool $cat) use ($db, $cfg, $dropCatCaches): array {
    $dropCatCaches();
    return array_column(indexSearchCatalogue($db, $cfg, $q + ['catalog' => $cat, 'per_page' => 100, 'page' => 1])['rows'], 'info_hash');
};
$a = $all(['search' => 'wombat numbat', 'sort' => 'relevance:desc'], false); $b = $all(['search' => 'wombat numbat', 'sort' => 'relevance:desc'], true);
sort($a); sort($b);
check('… two words by relevance: the same rows in the whole list', $a === $b && $a !== [], json_encode([count($a), count($b)]));
// The relevance cases that differed page by page: the same rows on the whole list, and — for one word, where one index's
// ranking is the other's — each arm's rows in the same order.
$relBad = [];
foreach ($relevanceCases as $q) {
    $dropCatCaches();
    $o = indexSearchCatalogue($db, $cfg, $q + ['catalog' => false, 'per_page' => 100, 'page' => 1]);
    $dropCatCaches();
    $w = indexSearchCatalogue($db, $cfg, $q + ['catalog' => true, 'per_page' => 100, 'page' => 1]);
    $ho = array_column($o['rows'], 'info_hash'); $hw = array_column($w['rows'], 'info_hash');
    $so = $ho; $sw = $hw; sort($so); sort($sw);
    $arm = static fn(array $r, string $src) => array_column(array_values(array_filter($r, fn($x) => $x['src'] === $src)), 'info_hash');
    $oneWord = !str_contains(trim((string)$q['search']), ' ');
    if ($so !== $sw || ($oneWord && ($arm($o['rows'], 'index') !== $arm($w['rows'], 'index') || $arm($o['rows'], 'whitelist') !== $arm($w['rows'], 'whitelist')))) {
        $relBad[] = json_encode($q);
    }
}
check('… relevance with two words or with the whitelist: the same rows, each arm in its own order (' . count($relevanceCases) . ' cases)',
      $relBad === [], implode(' | ', $relBad));
$bw = indexSearchCatalogue($db, $cfg, ['search' => 'quokka', 'include_whitelist' => true, 'catalog' => true, 'per_page' => 100]);
$hs = array_column($bw['rows'], 'info_hash');
$at = array_search(ch(3), $hs, true);
check('a hash in both tables comes once, as the whitelist row (the catalogue arm leaves it out)',
      count(array_keys($hs, ch(3), true)) === 1 && $at !== false && $bw['rows'][$at]['src'] === 'whitelist', json_encode($hs));
$likeCat = $all(['search' => 'ok', 'sort' => 'seeders:desc'], true);
check('the LIKE branch is the same over the catalogue (a name inside a word: "ok" in quokka)',
      $likeCat !== [] && $likeCat === $all(['search' => 'ok', 'sort' => 'seeders:desc'], false), (string)count($likeCat));

/* ── 5. the capped count ──────────────────────────────────────────────────── */
// 1 100 rows that all match one word: the count stops at max(1000, offset + 10 pages).
$db->exec("INSERT INTO index_hashes (info_hash, name, last_seen, last_seeders, grace_until, meta_status, total_size, files_count)
           SELECT CONCAT('" . CT . "', LPAD(LOWER(HEX(1000 + seq)), 32, '0')), CONCAT('bilby capped ', seq), NOW(), seq % 50, NOW() + INTERVAL 3 DAY, 'done', seq, 1
             FROM seq_1_to_1100");
indexCatalogSync($db, $db->query("SELECT info_hash FROM index_hashes WHERE name LIKE 'bilby capped %'")->fetchAll(PDO::FETCH_COLUMN));
foreach ([false, true] as $cat) {
    $p2 = indexSearchCatalogue($db, $cfg, ['search' => 'bilby', 'sort' => 'seeders:desc', 'page' => 2, 'per_page' => 25, 'catalog' => $cat]);
    check(($cat ? 'catalogue' : 'wide table') . ': a search past the cap counts to 1 000 and says so (total_capped, 40 pages)',
          $p2['total'] === 1000 && $p2['total_capped'] === true && $p2['pages'] === 40 && count($p2['rows']) === 25, json_encode(array_diff_key($p2, ['rows' => 1])));
    $p40 = indexSearchCatalogue($db, $cfg, ['search' => 'bilby', 'sort' => 'seeders:desc', 'page' => 40, 'per_page' => 25, 'catalog' => $cat]);
    check(($cat ? 'catalogue' : 'wide table') . ': … deeper, the cap moves with the page (offset + 10 pages) and the exact 1 100 comes back',
          $p40['total'] === 1100 && $p40['total_capped'] === false && $p40['pages'] === 44, json_encode(array_diff_key($p40, ['rows' => 1])));
    $few = indexSearchCatalogue($db, $cfg, ['search' => 'echidna', 'sort' => 'seeders:desc', 'page' => 2, 'per_page' => 5, 'catalog' => $cat]);
    $exact = (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE " . IDX_CATALOG_MEMBER . " AND MATCH(name) AGAINST('echidna*' IN BOOLEAN MODE)")->fetchColumn();
    check(($cat ? 'catalogue' : 'wide table') . ': under the cap the count is exact', $few['total'] === $exact && $few['total_capped'] === false && $exact > 5, "$exact " . json_encode(array_diff_key($few, ['rows' => 1])));
    $p200 = indexSearchCatalogue($db, $cfg, ['search' => 'bilby', 'sort' => 'seeders:desc', 'page' => 2, 'per_page' => 200, 'catalog' => $cat]);
    check(($cat ? 'catalogue' : 'wide table') . ': per_page 200 is honoured (the clamp said 100): 200 rows, 6 pages of the 1 100',
          count($p200['rows']) === 200 && $p200['per_page'] === 200 && $p200['pages'] === 6 && $p200['total'] === 1100, json_encode(array_diff_key($p200, ['rows' => 1])));
    $short = indexSearchCatalogue($db, $cfg, ['search' => 'echidna', 'sort' => 'seeders:desc', 'page' => 1, 'per_page' => 100, 'catalog' => $cat]);
    check(($cat ? 'catalogue' : 'wide table') . ': a first page that is not full IS the total (nothing counted)', $short['total'] === count($short['rows']) && $short['total_capped'] === false);
}

/* ── 6. a search the database stops for time ──────────────────────────────── */
$db->exec("SET SESSION max_statement_time = 0.000001");
$thrown = null;
try { indexSearchCatalogue($db, $cfg, ['search' => 'bilby', 'sort' => 'size:desc', 'page' => 3, 'per_page' => 25, 'catalog' => false]); }
catch (\Throwable $e) { $thrown = $e; }
$db->exec("SET SESSION max_statement_time = 0");
check('with max_statement_time forced tiny, the search throws IndexSearchTimeout (not a PDOException for a 500)',
      $thrown instanceof IndexSearchTimeout && indexIsStatementTimeout($thrown->getPrevious()), $thrown ? get_class($thrown) . ': ' . $thrown->getMessage() : 'nothing thrown');

/** A connection that fails the way the test says, and records what it was asked (no real connection behind it). */
final class IdxFailPdo extends PDO {
    public array $asked = [];
    public ?int $code = null;
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->asked[] = $query;
        $e = new PDOException($this->code === 1969 ? 'SQLSTATE[70100]: <<Unknown error>>: 1969 Query execution was interrupted (max_statement_time exceeded)'
                                                   : 'SQLSTATE[HY000]: General error: 1191 Can\'t find FULLTEXT index matching the column list');
        $e->errorInfo = $this->code === 1969 ? ['70100', 1969, 'Query execution was interrupted (max_statement_time exceeded)'] : ['HY000', 1191, 'no fulltext'];
        throw $e;
    }
}
$fp = (new ReflectionClass(IdxFailPdo::class))->newInstanceWithoutConstructor();
$fp->code = 1969;
$t = null;
try { indexSearchCatalogue($fp, $cfg, ['search' => 'quokka', 'catalog' => false]); } catch (\Throwable $e) { $t = $e; }
check('a fulltext pass stopped for time is not followed by the LIKE pass (the slower of the two): one statement asked, IndexSearchTimeout',
      $t instanceof IndexSearchTimeout && count($fp->asked) === 1 && str_contains($fp->asked[0], 'MATCH('), json_encode($fp->asked));
$fp->asked = []; $fp->code = 1191;
$t = null;
try { indexSearchCatalogue($fp, $cfg, ['search' => 'quokka', 'catalog' => false]); } catch (\Throwable $e) { $t = $e; }
check('… while any other failure of the fulltext pass still falls back to LIKE (and that failure is not a timeout)',
      count($fp->asked) === 2 && str_contains($fp->asked[1], 'name LIKE ?') && !str_contains($fp->asked[1], 'MATCH(')
      && $t instanceof PDOException && !($t instanceof IndexSearchTimeout), json_encode($fp->asked));
check('the endpoint\'s sentence exists in both languages, and they differ',
      langFor('en', 'api.search.timeout') !== 'api.search.timeout' && langFor('pl', 'api.search.timeout') !== 'api.search.timeout'
      && langFor('en', 'api.search.timeout') !== langFor('pl', 'api.search.timeout'), langFor('pl', 'api.search.timeout'));

/* ── 4. the writers that keep it ──────────────────────────────────────────── */
// the poll's batches: new seeders for two rows, one of them now on the whitelist (the poll takes it out of both)
$db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned, content_status, meta_status) VALUES (?, 'moved to the whitelist', 'admin', NOW(), 0, 'none', 'none')")->execute([ch(8)]);
$scrape = sys_get_temp_dir() . '/idx_catalog_test_scrape.bin';
$body = 'd5:filesd';
foreach ([[ch(10), 333, 4], [ch(8), 5, 1]] as [$h, $c, $inc]) $body .= '20:' . hex2bin($h) . 'd8:completei' . $c . 'e10:downloadedi0e10:incompletei' . $inc . 'ee';
file_put_contents($scrape, $body . 'ee');
$db->exec("UPDATE index_hashes SET scrape_seeders = NULL WHERE info_hash = '" . ch(10) . "'");
indexCatalogSync($db, [ch(10)]);
$poll = indexPoll($db, array_merge($cfg, ['index_enabled' => '1', 'index_min_seeders' => '1', 'index_poll_budget' => '60']),
                  fn() => ['file' => $scrape, 'gzip' => false], 1747000000);
@unlink($scrape);
check('the poll\'s batch moves the catalogue\'s numbers with it, in the same transaction',
      $poll['ok'] && (int)($catRow($db, ch(10))['eff_seeders'] ?? -1) === 333 && (int)($catRow($db, ch(10))['eff_leechers'] ?? -1) === 4, json_encode([$poll['error'], $catRow($db, ch(10))]));
check('… and its whitelist removal takes the row out of the catalogue too', !$catHas($db, ch(8))
      && (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash = '" . ch(8) . "'")->fetchColumn() === 0);
indexDelete($db, [ch(11)]);
check('indexDelete() takes its rows out of the catalogue', !$catHas($db, ch(11)));
// the prune (inside a transaction, rolled back: the test database's other expired rows stay as they were)
$db->exec("UPDATE index_hashes SET protected_until = NOW() - INTERVAL 1 DAY, meta_status = 'done' WHERE info_hash = '" . ch(12) . "'");
$db->beginTransaction();
$pr = indexPrune($db, array_merge($cfg, ['index_max_rows' => '5000000', 'index_keep_saved' => 'off']), null, true);
$prunedOut = !$catHas($db, ch(12)) && (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash = '" . ch(12) . "'")->fetchColumn() === 0;
$db->rollBack();
check('the prune takes what it deletes out of the catalogue (by the keys its DELETE returned)', $pr !== null && $prunedOut, json_encode($pr));
// what Python resolves: done + name + meta_fetched_at, written behind PHP's back
indexStateUpdate(function (array &$s) { $s['catalog_recent_from'] = ''; return true; });
check('the first look at what Python resolved only sets the mark', indexCatalogSyncRecent($db) === 0 && indexStateRead()['catalog_recent_from'] !== '');
$db->exec("UPDATE index_hashes SET name = 'echidna resolved by the worker', meta_status = 'done', meta_fetched_at = NOW() WHERE info_hash = '" . ch(50) . "'");
$wrote = indexCatalogSyncRecent($db);
check('… the next one writes what the worker or federation resolved since (meta_fetched_at), no walk needed',
      $wrote >= 1 && ($catRow($db, ch(50))['name'] ?? '') === 'echidna resolved by the worker', (string)$wrote);
// federation's purge (PHP side)
$db->exec("UPDATE index_hashes SET meta_source = 'fed:c7catpeer' WHERE info_hash IN ('" . ch(13) . "', '" . ch(14) . "')");
$purged = fedPurgeBatch($db, 'c7catpeer');
check('federation\'s purge takes the names it removes out of the catalogue', $purged === 2 && !$catHas($db, ch(13)) && !$catHas($db, ch(14)), (string)$purged);

/* ── 7. the ranking keys and the all-words fast path (1.75.0) ───────────────── */
// The words of a search, and the SQL written from them.
$rwOf = static fn(string $s): array => indexRankWords($s);
$rw = $rwOf('Rick and Morty');
check('rank words: the query split on anything that is not a letter or a digit, lower-cased, in order',
      $rw['w'] === ['rick', 'and', 'morty'] && $rw['c'] === ['rick', 'and', 'morty'], json_encode($rw));
$rw = $rwOf('The Office');
check('… the countable ones leave out the stop words and the short ones ("the"), the order test keeps every word',
      $rw['w'] === ['the', 'office'] && $rw['c'] === ['office'], json_encode($rw));
check('… "of tv uk" has nothing countable, "the and of" only "and"', $rwOf('of tv uk')['c'] === [] && $rwOf('the and of')['c'] === ['and'] && count($rwOf('of tv uk')['w']) === 3);
check('… a word typed twice counts once; at most eight distinct words take part',
      $rwOf('rick rick morty')['w'] === ['rick', 'morty'] && count($rwOf('aa1 bb2 cc3 dd4 ee5 ff6 gg7 hh8 ii9 jj0')['w']) === 8
      && count($rwOf('aa1 bb2 cc3 dd4 ee5 ff6 gg7 hh8 ii9 jj0')['c']) === 8);
check('… punctuation at a word\'s edge is no matter (the star stays on the word), Polish letters are letters',
      $rwOf('Rick, and Morty!')['c'] === ['rick', 'and', 'morty'] && $rwOf('Zażółć gęślą: jaźń')['c'] === ['zażółć', 'gęślą', 'jaźń'], json_encode($rwOf('Zażółć gęślą: jaźń')));
check('… but a delimiter INSIDE a word makes the fulltext WHERE and the counts disagree, so nothing is counted (the order test still has the words)',
      $rwOf('rick_and_morty')['c'] === [] && $rwOf('rick.and.morty')['c'] === [] && $rwOf("don't stop")['c'] === [] && $rwOf('rick_and_morty')['w'] === ['rick', 'and', 'morty']
      && $rwOf('rick & morty')['c'] === ['rick', 'morty'] && $rwOf('spider-man 2002')['c'] === ['spider', 'man', '2002']);
check('… invalid UTF-8 and the empty string do not break it', $rwOf("\xff\xfe") === ['w' => [], 'c' => []] && $rwOf('  ') === ['w' => [], 'c' => []]);
$rs = indexRankSql(['w' => ['rick', 'and', 'morty'], 'c' => ['rick', 'and', 'morty']], true, true);
check('words_hit: one MATCH per countable word, the prefix the WHERE searches with',
      $rs['hit'][0] === '(MATCH(name) AGAINST(? IN BOOLEAN MODE) > 0) + (MATCH(name) AGAINST(? IN BOOLEAN MODE) > 0) + (MATCH(name) AGAINST(? IN BOOLEAN MODE) > 0)'
      && $rs['hit'][1] === ['rick*', 'and*', 'morty*'], json_encode($rs['hit']));
check('in_order: the LIKE gate, then the adjacent REGEXP (2), then the in-order one (1) — word starts by lookbehind, not \\b',
      $rs['ord'][0] === 'CASE WHEN name NOT LIKE ? THEN 0 WHEN name REGEXP ? THEN 2 WHEN name REGEXP ? THEN 1 ELSE 0 END'
      && $rs['ord'][1] === ['%rick%and%morty%',
          '(?i)(?<![\p{L}\p{N}])rick[\p{L}\p{N}]*[^\p{L}\p{N}]+and[\p{L}\p{N}]*[^\p{L}\p{N}]+morty',
          '(?i)(?<![\p{L}\p{N}])rick.*(?<![\p{L}\p{N}])and.*(?<![\p{L}\p{N}])morty'], json_encode($rs['ord']));
$rs1 = indexRankSql(['w' => ['the', 'office'], 'c' => ['office']], true, true);
check('… with one countable word there is no words_hit; with no second word, no in_order',
      $rs1['hit'] === ['', []] && $rs1['ord'][0] !== '' && indexRankSql(['w' => ['rick'], 'c' => ['rick']], true, true) === ['hit' => ['', []], 'ord' => ['', []]]);
check('the LIKE gate escapes the LIKE wildcards', indexLikeEscape('50%_off\\') === '50\\%\\_off\\\\');

// The fixtures: the names of the brief (and a few more), each with a seeder count and a size of its own.
$rkRows = [
    'r1' => ['Rick and Morty S01', 5, 5000], 'r2' => ['Rick.and.Morty.S02', 9, 3000], 'r3' => ['rick_and_morty_s03', 50, 100],
    'r4' => ['Rick Sanchez and and and', 40, 200], 'r5' => ['Morty Rick and', 7, 9000], 'r6' => ['Rick', 60, 50],
    'o1' => ['The Office UK', 3, 700], 'o2' => ['Home Office Tour', 30, 600], 'p1' => ['Pokémon Movie', 2, 10],
    'l1' => ['ab_cd lik', 11, 1], 'l2' => ['abxcd lik', 12, 2], 'l3' => ['sale 5% off lik', 13, 3], 'l4' => ['sale 50 off lik', 14, 4],
    'd1' => ['decade facade release', 15, 5],
];
$labels = [];
$rkIds = [];
$i = 6000;
foreach ($rkRows as $label => [$nm, $seed, $size]) {
    $ins->execute([ch($i), $nm, 5, $seed, 0, null, 'done', date('Y-m-d H:i:s', time() - 7200), $size, 1, null, null, null]);
    $labels[ch($i)] = $label; $rkIds[$label] = ch($i); $i++;
}
$db->prepare("INSERT INTO whitelist (info_hash, name, total_size, files_count, source, created_at, banned, content_status, meta_status, scrape_seeders)
              VALUES (?, 'Rick and Morty S04 whitelist', 4000, 1, 'admin', NOW(), 0, 'none', 'done', 20)")->execute([ch(6100)]);
$labels[ch(6100)] = 'w1'; $rkIds['w1'] = ch(6100);
indexCatalogSync($db, array_values(array_filter($rkIds, fn($h) => $h !== ch(6100))));
$orderOf = static function (array $q) use ($db, $cfg, $dropCatCaches, $labels): array {
    $out = [];
    foreach ([false, true] as $cat) {
        $dropCatCaches();
        $res = indexSearchCatalogue($db, $cfg, $q + ['catalog' => $cat, 'per_page' => 200, 'page' => 1]);
        $out[$cat ? 'cat' : 'wide'] = array_values(array_filter(array_map(fn($r) => $labels[$r['info_hash']] ?? null, $res['rows'])));
    }
    return $out;
};
$same = static function (string $name, array $got, array $want): void {
    check($name, $got['wide'] === $want && $got['cat'] === $want, 'want ' . implode(' ', $want) . ' | wide ' . implode(' ', $got['wide']) . ' | catalogue ' . implode(' ', $got['cat']));
};

// "rick and morty": r1/r2 have all three words side by side, r5 all three out of order, r4 two (and "and" three times —
// it used to tie with the real thing), r3 is one fulltext token (rick_and_morty_s03: only "rick*" matches) but reads in order, r6 one.
$same('Best match: more words first, then the words in order, then the score and the seeders (r2 beats r1 on seeders; both score alike)',
      $orderOf(['search' => 'rick and morty', 'sort' => 'relevance:desc']), ['r2', 'r1', 'r5', 'r4', 'r3', 'r6']);
$same('Best match + Size, largest first: the column decides INSIDE the groups — r1 (5000) before r2 (3000), the groups as before',
      $orderOf(['search' => 'rick and morty', 'sort' => 'relevance:desc,size:desc']), ['r1', 'r2', 'r5', 'r4', 'r3', 'r6']);
$same('Best match + Size, smallest first: r2 (3000) before r1 (5000); r3 (reads in order) still before r6 whatever their sizes',
      $orderOf(['search' => 'rick and morty', 'sort' => 'relevance:desc,size:asc']), ['r2', 'r1', 'r5', 'r4', 'r3', 'r6']);
$same('Best match + S/L: the seeders decide inside a group — r6 (60 seeders) does not climb over r3, the order test comes first',
      $orderOf(['search' => 'rick and morty', 'sort' => 'relevance:desc,seeders:desc']), ['r2', 'r1', 'r5', 'r4', 'r3', 'r6']);
$same('plain search, S/L: grouped by how many words matched (3, then 2, then 1), the seeders inside each group',
      $orderOf(['search' => 'rick and morty', 'sort' => 'seeders:desc']), ['r2', 'r5', 'r1', 'r4', 'r6', 'r3']);
$same('plain search, Size: the same groups, the size inside them', $orderOf(['search' => 'rick and morty', 'sort' => 'size:desc']), ['r5', 'r1', 'r2', 'r4', 'r3', 'r6']);
$same('with the whitelist beside it: words_hit is one number in both arms, so the whitelist row (3 words, in order, 4000) takes its place among the index rows',
      $orderOf(['search' => 'rick and morty', 'sort' => 'relevance:desc,size:desc', 'include_whitelist' => true]), ['r1', 'w1', 'r2', 'r5', 'r4', 'r3', 'r6']);
$same('… and the plain search with it (the whitelist row has 20 seeders)', $orderOf(['search' => 'rick and morty', 'sort' => 'seeders:desc', 'include_whitelist' => true]), ['w1', 'r2', 'r5', 'r1', 'r4', 'r6', 'r3']);
// one word: no ranking key, but Best match + a column no longer has the score in front of the column — the column decides
$same('one word, Best match + Size: the column decides (the score used to stand in front of it and nothing ever got past it)',
      $orderOf(['search' => 'rick', 'sort' => 'relevance:desc,size:desc']), ['r5', 'r1', 'r2', 'r4', 'r3', 'r6']);
$same('… ascending', $orderOf(['search' => 'rick', 'sort' => 'relevance:desc,size:asc']), ['r6', 'r3', 'r4', 'r2', 'r1', 'r5']);
$same('… S/L: the seeders decide, descending', $orderOf(['search' => 'rick', 'sort' => 'relevance:desc,seeders:desc']), ['r6', 'r3', 'r4', 'r2', 'r5', 'r1']);
// "the office": "the" is a stop word, so one countable word — no words_hit, only the order test (Best match)
$same('"the office", Best match: no words_hit (one countable word) but the words in order still come first — The Office UK before Home Office Tour',
      $orderOf(['search' => 'the office', 'sort' => 'relevance:desc']), ['o1', 'o2']);
$same('… with a column: the order test first, then the seeders (Home Office Tour 30 would lead without it)',
      $orderOf(['search' => 'the office', 'sort' => 'relevance:desc,seeders:desc']), ['o1', 'o2']);
$same('… plain: no ranking keys at all, the column alone (1.74.4\'s order)', $orderOf(['search' => 'the office', 'sort' => 'seeders:desc']), ['o2', 'o1']);
// accents: the fulltext index ignores them, the order test (REGEXP) does not
$same('"pokemon movie" finds Pokémon Movie (both words match, the order test cannot read the accent: in_order 0, never a lost row)',
      $orderOf(['search' => 'pokemon movie', 'sort' => 'relevance:desc']), ['p1']);
$same('… and "pokémon movie" with the accent too', $orderOf(['search' => 'pokémon movie', 'sort' => 'relevance:desc']), ['p1']);
// underscores: one fulltext token, so the query is not counted at all, and its WHERE is what it always was
$same('"rick_and_morty" is not counted by words (InnoDB reads it as one token) and finds the underscore name; order test only',
      $orderOf(['search' => 'rick_and_morty', 'sort' => 'relevance:desc']), ['r3']);
// the fault of the hash-prefix rule: a six-letter word of a–f is a word
foreach ([true, false] as $hs) {
    $res = indexSearchCatalogue($db, $cfg, ['search' => 'decade', 'sort' => 'seeders:desc', 'hash_search' => $hs, 'catalog' => true]);
    check('"decade" is a word, not a hash prefix (hash_search ' . ($hs ? 'on' : 'off') . ')', in_array($rkIds['d1'], array_column($res['rows'], 'info_hash'), true), (string)count($res['rows']));
}
check('the shared rule: a digit among the characters, or twelve of them, makes a hex term a hash prefix',
      !indexIsHashPrefix('abcdef') && !indexIsHashPrefix('decade') && !indexIsHashPrefix('FACADE') && indexIsHashPrefix('abc123') && indexIsHashPrefix('abcdef012345')
      && indexIsHashPrefix('abcdefabcdef') && !indexIsHashPrefix('abcde') && !indexIsHashPrefix('abcdeg') && indexIsHashPrefix(str_repeat('a', 12)) && !indexIsHashPrefix(str_repeat('a', 41)));
// the LIKE fallback takes % and _ literally
$likeHits = static function (string $term) use ($db, $cfg, $dropCatCaches, $labels): array {
    $out = [];
    foreach ([false, true] as $cat) {
        $dropCatCaches();
        $res = indexSearchCatalogue($db, $cfg, ['search' => $term, 'sort' => 'seeders:desc', 'catalog' => $cat, 'per_page' => 200]);
        $got = array_values(array_filter(array_map(fn($r) => $labels[$r['info_hash']] ?? null, $res['rows'])));
        sort($got); $out[] = $got;
    }
    return $out[0] === $out[1] ? $out[0] : ['differ'];
};
check('the LIKE fallback: "b_" is a letter b and an underscore, not "b and any character"', $likeHits('b_') === ['l1'], json_encode($likeHits('b_')));
check('… "5%" is a five and a percent sign', $likeHits('5%') === ['l3'], json_encode($likeHits('5%')));
// the file-name clause (the LIKE of a term fulltext cannot take) is escaped by the same rule
check('… and so is the file-name clause', indexFilePathClause('a_') === ['path LIKE ?', ['%a\\_%']], json_encode(indexFilePathClause('a_')));

// The SQL. A recording connection: every statement the search prepares is written down, then really run.
final class IdxRecordPdo extends PDO {
    public array $asked = [];
    public PDO $real;
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->asked[] = $query; return $this->real->prepare($query, $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { return $this->real->query($query); }
    public function exec(string $statement): int|false { return $this->real->exec($statement); }
}
$rec = (new ReflectionClass(IdxRecordPdo::class))->newInstanceWithoutConstructor();
$rec->real = $db;
$sqlOf = static function (array $q) use ($rec, $cfg, $dropCatCaches): array {
    $rec->asked = [];
    $dropCatCaches();
    indexSearchCatalogue($rec, $cfg, $q);
    return array_map(static fn(string $s): string => preg_replace('/\s+/', ' ', $s), $rec->asked);
};
// one word: Best match alone and the plain search are 1.74.4's statements, nothing added; Best match + a column loses the score in front of the column (1.75.0)
foreach ([false, true] as $cat) {
    $cs = $cat ? 'catalogue' : 'wide table';
    $best = $sqlOf(['search' => 'quokka', 'sort' => 'relevance:desc', 'catalog' => $cat])[0];
    $col  = $sqlOf(['search' => 'quokka', 'sort' => 'relevance:desc,size:desc', 'catalog' => $cat])[0];
    $plain = $sqlOf(['search' => 'quokka', 'sort' => 'size:asc', 'catalog' => $cat])[0];
    $one = fn(string $s) => !str_contains($s, 'words_hit') && !str_contains($s, 'in_order') && !str_contains($s, 'CASE') && !str_contains($s, 'REGEXP') && substr_count($s, 'MATCH(') === 2;
    $e1 = $cat ? 'ORDER BY score DESC, eff_seeders DESC, info_hash DESC LIMIT ? OFFSET ?) k JOIN index_hashes h ON h.info_hash = k.info_hash AND (h.meta_status = \'done\' OR (h.name IS NOT NULL AND h.name <> \'\')) ORDER BY k.score DESC, k.eff_seeders DESC, k.info_hash DESC'
                : 'ORDER BY score DESC, eff_seeders DESC, info_hash DESC LIMIT ? OFFSET ?';
    $e2 = $cat ? 'ORDER BY total_size DESC, info_hash DESC LIMIT ? OFFSET ?) k JOIN index_hashes h ON h.info_hash = k.info_hash AND (h.meta_status = \'done\' OR (h.name IS NOT NULL AND h.name <> \'\')) ORDER BY k.total_size DESC, k.info_hash DESC'
                : 'ORDER BY total_size DESC, info_hash DESC LIMIT ? OFFSET ?';
    $e3 = $cat ? 'ORDER BY total_size ASC, info_hash ASC LIMIT ? OFFSET ?) k JOIN index_hashes h ON h.info_hash = k.info_hash AND (h.meta_status = \'done\' OR (h.name IS NOT NULL AND h.name <> \'\')) ORDER BY k.total_size ASC, k.info_hash ASC'
                : 'ORDER BY total_size ASC, info_hash ASC LIMIT ? OFFSET ?';
    check("$cs, one word: Best match is 'score DESC, eff_seeders DESC, info_hash DESC' as in 1.74.4 — no ranking key, no extra MATCH", $one($best) && str_ends_with($best, $e1), $best);
    check("$cs, one word: Best match + Size is 'total_size DESC, info_hash DESC' — no score in front of the column (1.75.0), no ranking key", $one($col) && str_ends_with($col, $e2), $col);
    check("$cs, one word: the plain search is the column and the hash as before", $one($plain) && str_ends_with($plain, $e3), $plain);
}
// three words, the full query (the fast path off): the keys in the three orders, in every statement shape
foreach ([false, true] as $cat) {
    $cs = $cat ? 'catalogue' : 'wide table';
    $q3 = ['search' => 'rick and morty', 'catalog' => $cat, 'fast_path' => false];
    $a = $sqlOf($q3 + ['sort' => 'relevance:desc'])[0];
    $b = $sqlOf($q3 + ['sort' => 'relevance:desc,size:desc'])[0];
    $c = $sqlOf($q3 + ['sort' => 'seeders:desc'])[0];
    if ($cat) {
        check("$cs, three words, Best match: words_hit, in_order, score, seeders, hash — in the inner query and the outer one",
              str_contains($a, 'ORDER BY words_hit DESC, in_order DESC, score DESC, eff_seeders DESC, info_hash DESC LIMIT ? OFFSET ?) k')
              && str_ends_with($a, 'ORDER BY k.words_hit DESC, k.in_order DESC, k.score DESC, k.eff_seeders DESC, k.info_hash DESC')
              && str_contains($a, 'k.score AS score, k.words_hit AS words_hit, k.in_order AS in_order'), $a);
        check("$cs, Best match + Size: words_hit, in_order, size, hash (the score is out of the way of the column)",
              str_contains($b, 'ORDER BY words_hit DESC, in_order DESC, total_size DESC, info_hash DESC LIMIT ? OFFSET ?) k')
              && str_ends_with($b, 'ORDER BY k.words_hit DESC, k.in_order DESC, k.total_size DESC, k.info_hash DESC'), $b);
        check("$cs, plain: words_hit, then the column and the hash — no in_order",
              str_contains($c, 'ORDER BY words_hit DESC, eff_seeders DESC, info_hash DESC LIMIT ? OFFSET ?) k') && !str_contains($c, 'in_order')
              && str_ends_with($c, 'ORDER BY k.words_hit DESC, k.eff_seeders DESC, k.info_hash DESC'), $c);
    } else {
        check("$cs, three words, Best match: words_hit, in_order, score, seeders, hash",
              str_ends_with($a, 'ORDER BY words_hit DESC, in_order DESC, score DESC, eff_seeders DESC, info_hash DESC LIMIT ? OFFSET ?'), $a);
        check("$cs, Best match + Size: words_hit, in_order, size, hash", str_ends_with($b, 'ORDER BY words_hit DESC, in_order DESC, total_size DESC, info_hash DESC LIMIT ? OFFSET ?'), $b);
        check("$cs, plain: words_hit, then the column and the hash — no in_order", str_ends_with($c, 'ORDER BY words_hit DESC, eff_seeders DESC, info_hash DESC LIMIT ? OFFSET ?') && !str_contains($c, 'in_order'), $c);
    }
}
// two arms: the same columns in the same positions in both halves, and the merge sorts by the same keys
foreach ([false, true] as $cat) {
    $cs = $cat ? 'catalogue' : 'wide table';
    $s2 = $sqlOf(['search' => 'rick and morty', 'catalog' => $cat, 'fast_path' => false, 'include_whitelist' => true, 'sort' => 'relevance:desc,size:desc'])[0];
    $halves = $cat ? (str_contains($s2, 'a.score, a.words_hit, a.in_order') && str_contains($s2, 'b.score, b.words_hit, b.in_order'))
                   : (substr_count($s2, 'AS score, (MATCH(name)') === 2 && substr_count($s2, 'AS words_hit, CASE WHEN name NOT LIKE ?') === 2);
    check("$cs, two arms: both halves carry words_hit and in_order after the score, the merge sorts by them first",
          $halves && str_ends_with($s2, ') cat ORDER BY words_hit DESC, in_order DESC, total_size DESC, info_hash DESC LIMIT ? OFFSET ?'), $s2);
}

// The all-words fast path: the page it answers with is the page the full query would give.
$z = ['zymase', 'xylitol', 'quartzite'];
$zIns = $db->prepare("INSERT INTO index_hashes (info_hash, name, first_seen, last_seen, seen_count, last_seeders, last_leechers, last_completed, grace_until, meta_status, meta_fetched_at, total_size, files_count)
                      VALUES (?, ?, NOW(), NOW(), 1, ?, 0, 0, NOW() + INTERVAL 3 DAY, 'done', NOW() - INTERVAL 2 HOUR, ?, 1)");
$zHashes = []; $zi = 6200;
for ($k = 1; $k <= 60; $k++) { $zIns->execute([ch($zi), "zymase xylitol quartzite A$k", ($k * 7) % 31, $k * 100]); $zHashes[] = ch($zi); $zi++; }
for ($k = 1; $k <= 40; $k++) { $zIns->execute([ch($zi), $z[$k % 3] . ' ' . $z[($k + 1) % 3] . " B$k", ($k * 5) % 29, $k * 90]); $zHashes[] = ch($zi); $zi++; }
for ($k = 1; $k <= 30; $k++) { $zIns->execute([ch($zi), $z[$k % 3] . " C$k", ($k * 3) % 23, $k * 80]); $zHashes[] = ch($zi); $zi++; }
$zIns->execute([ch(6500), 'Unrelated title', 1, 1]); $zHashes[] = ch(6500);
indexCatalogSync($db, $zHashes);
$wlz = $db->prepare("INSERT INTO whitelist (info_hash, name, total_size, files_count, source, created_at, banned, content_status, meta_status, scrape_seeders)
                     VALUES (?, ?, ?, 1, 'admin', NOW(), 0, 'none', 'done', ?)");
for ($k = 1; $k <= 4; $k++) $wlz->execute([ch(6600 + $k), "zymase xylitol quartzite WL$k", $k * 150, ($k * 11) % 17]);
for ($k = 5; $k <= 7; $k++) $wlz->execute([ch(6600 + $k), "xylitol zymase WL$k", $k * 150, ($k * 11) % 17]);
// files: three three-word names, two one-word names (found by name AND by file), and one torrent only a file name finds
$db->prepare("INSERT INTO index_files (info_hash, path, size) VALUES (?, 'zymase/xylitol quartzite.mkv', 5), (?, 'zymase xylitol quartzite.mkv', 6), (?, 'zymase/xylitol/quartzite.mkv', 7),
              (?, 'xylitol quartzite zymase.mkv', 8), (?, 'quartzite zymase xylitol.mkv', 9), (?, 'zymase xylitol quartzite.mkv', 10)")
   ->execute([ch(6201), ch(6202), ch(6203), ch(6301), ch(6302), ch(6500)]);
$hashesOf = static fn(array $r): array => array_column($r['rows'], 'info_hash');
$bad = []; $cases = 0; $fastRan = 0; $fullRan = 0;
foreach ([false, true] as $cat) {
    foreach ([false, true] as $wl) {
        foreach (['relevance:desc', 'relevance:desc,size:desc', 'relevance:desc,seeders:asc', 'seeders:desc', 'size:asc,leechers:desc'] as $sort) {
            foreach ([1, 2, 3, 4] as $page) {
                $q = ['search' => 'zymase xylitol quartzite', 'sort' => $sort, 'include_whitelist' => $wl, 'page' => $page, 'per_page' => 25, 'catalog' => $cat];
                $dropCatCaches(); $fast = indexSearchCatalogue($db, $cfg, $q);
                $dropCatCaches(); $full = indexSearchCatalogue($db, $cfg, $q + ['fast_path' => false]);
                $cases++;
                if ($hashesOf($fast) !== $hashesOf($full) || $fast['total'] !== $full['total'] || $fast['total_capped'] !== $full['total_capped']) {
                    $bad[] = ($cat ? 'cat' : 'wide') . "/wl$wl/$sort/p$page: " . count($hashesOf($fast)) . ' vs ' . count($hashesOf($full));
                }
            }
        }
    }
}
check("the all-words fast path answers the page the full query gives — $cases cases (both tables, with and without the whitelist, five orders, pages 1-4: full pages from the fast tier and the ones that fall back)",
      $bad === [], implode(' | ', array_slice($bad, 0, 6)));
// … and it IS the shortcut: a full page of the tier is one statement (+ the count), no words_hit anywhere; a short tier runs the full query after it
foreach ([false, true] as $cat) {
    $cs = $cat ? 'catalogue' : 'wide table';
    $s = $sqlOf(['search' => 'zymase xylitol quartzite', 'sort' => 'relevance:desc', 'page' => 1, 'per_page' => 25, 'catalog' => $cat]);
    check("$cs: page 1 of a tier of 60 is answered by the all-words query alone (and the count)",
          count($s) === 2 && !str_contains($s[0], 'words_hit') && str_contains($s[0], 'in_order') && !str_contains($s[0], 'UNION'), implode(' || ', $s));
    $s = $sqlOf(['search' => 'zymase xylitol quartzite', 'sort' => 'relevance:desc', 'page' => 3, 'per_page' => 25, 'catalog' => $cat]);
    check("$cs: page 3 reaches past the tier (60 rows): the all-words query comes back short and the full query follows",
          count($s) >= 2 && !str_contains($s[0], 'words_hit') && str_contains($s[1], 'words_hit'), implode(' || ', array_map(fn($x) => substr($x, 0, 80), $s)));
    $s = $sqlOf(['search' => 'zymase xylitol quartzite', 'sort' => 'seeders:desc', 'page' => 1, 'per_page' => 25, 'catalog' => $cat]);
    check("$cs: the plain search has the shortcut too — its all-words query carries no ranking key at all",
          count($s) === 2 && !str_contains($s[0], 'words_hit') && !str_contains($s[0], 'in_order'), $s[0]);
    $s = $sqlOf(['search' => 'rick_and_morty', 'sort' => 'relevance:desc', 'page' => 1, 'per_page' => 25, 'catalog' => $cat]);
    check("$cs: a query the counts cannot agree with (rick_and_morty) has no shortcut and no words_hit — today's WHERE",
          !str_contains($s[0], 'words_hit') && substr_count($s[0], 'MATCH(') === 2 && str_contains($s[0], 'in_order'), $s[0]);
}
// file-name hits: the same expressions in both halves of the arm, so a torrent found by name AND by file is ONE row
foreach ([false, true] as $cat) {
    foreach ([false, true] as $wl) {
        foreach (['relevance:desc', 'relevance:desc,size:desc', 'seeders:desc'] as $sort) {
            $dropCatCaches();
            $r = indexSearchCatalogue($db, $cfg, ['search' => 'zymase xylitol quartzite', 'sort' => $sort, 'search_files' => 1, 'include_whitelist' => $wl, 'per_page' => 200, 'catalog' => $cat]);
            $hs = $hashesOf($r);
            $dup = array_keys(array_filter(array_count_values($hs), fn($c) => $c > 1));
            $pos = array_search(ch(6500), $hs, true);
            $both = array_intersect([ch(6201), ch(6202), ch(6301), ch(6302)], $hs);
            check(($cat ? 'catalogue' : 'wide table') . ', ' . ($wl ? 'with' : 'without') . ' the whitelist, ' . $sort . ': search in file names — no hash twice, a name-and-file hit once, the file-only torrent last',
                  $dup === [] && count($both) === 4 && $pos === count($hs) - 1, 'dup ' . json_encode($dup) . ' pos ' . json_encode($pos) . ' of ' . count($hs));
        }
    }
}
// the whitelist merge: a whitelist row with all three words stands among the index rows by words_hit, not by a score of another index
$dropCatCaches();
$wm = indexSearchCatalogue($db, $cfg, ['search' => 'zymase xylitol quartzite', 'sort' => 'size:asc', 'include_whitelist' => true, 'per_page' => 200, 'catalog' => true]);
$wmSrc = array_column($wm['rows'], 'src', 'info_hash');
$firstWl = array_search(ch(6601), array_column($wm['rows'], 'info_hash'), true);
$lastAll = max(array_keys(array_filter(array_column($wm['rows'], 'name'), fn($nm) => str_starts_with($nm, 'zymase xylitol quartzite'))));
$firstTwo = min(array_keys(array_filter(array_column($wm['rows'], 'name'), fn($nm) => !str_starts_with($nm, 'zymase xylitol quartzite'))));
check('the whitelist merge: whitelist rows with all three words are inside the three-word group, before the first two-word row',
      ($wmSrc[ch(6601)] ?? '') === 'whitelist' && $firstWl !== false && $firstWl <= $lastAll && $lastAll < $firstTwo, "wl at $firstWl, last three-word row $lastAll, first fewer-word row $firstTwo");
} finally {
    $cleanup();
}
check('cleanup: nothing of this run is left', (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash LIKE '" . CT . "%'")->fetchColumn() === 0
      && (int)$db->query("SELECT COUNT(*) FROM index_catalog WHERE info_hash LIKE '" . CT . "%'")->fetchColumn() === 0);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
