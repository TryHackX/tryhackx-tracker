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
 *      not run behind it — while any other failure of the fulltext pass still falls back to LIKE.
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
} finally {
    $cleanup();
}
check('cleanup: nothing of this run is left', (int)$db->query("SELECT COUNT(*) FROM index_hashes WHERE info_hash LIKE '" . CT . "%'")->fetchColumn() === 0
      && (int)$db->query("SELECT COUNT(*) FROM index_catalog WHERE info_hash LIKE '" . CT . "%'")->fetchColumn() === 0);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
