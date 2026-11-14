<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/lang.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
$cfg['search_count_common_words'] = '1';

$h = fn(int $i): string => 'ce1822' . str_repeat('0', 33) . $i;
$mine = fn(array $rows): array => array_values(array_map(fn($r) => (int)substr($r['info_hash'], -1),
    array_filter($rows, fn($r) => str_starts_with($r['info_hash'], 'ce1822'))));
$wipe = function () use ($db): void {
    $db->exec("DELETE FROM index_catalog WHERE info_hash LIKE 'ce1822%'");
    $db->exec("DELETE FROM index_hashes WHERE info_hash LIKE 'ce1822%'");
};
$wipe();
$fixture = [
    1 => ['Dr Qorvex S01 1080p', 5, 30],
    2 => ['Dr.Qorvex.S02.720p', 9, 20],
    3 => ['Qorvexian Drama S01', 50, 10],
    4 => ['Qorvex Show', 70, 5],
    5 => ['Dr Who Qorvex Edition', 7, 1],
];
$ins = $db->prepare("INSERT INTO index_hashes (info_hash, name, first_seen, last_seen, last_seeders, meta_status) VALUES (?, ?, NOW() - INTERVAL ? MINUTE, NOW() - INTERVAL ? MINUTE, ?, 'done')");
$cat = $db->prepare("INSERT INTO index_catalog (info_hash, name, eff_seeders, last_seen) VALUES (?, ?, ?, NOW() - INTERVAL ? MINUTE)");
foreach ($fixture as $i => [$name, $seeders, $age]) {
    $ins->execute([$h($i), $name, $age, $age, $seeders]);
    $cat->execute([$h($i), $name, $seeders, $age]);
}

$r = indexListSelect($db, $cfg, ['search' => 'dr qorvex', 'sort' => 'seeders:desc', 'per_page' => 50, 'catalog' => false]);
check('a name search of several words finds the names with every word — "dr" as a whole word, so not "Drama" — by the seeders',
      $mine($r['rows']) === [2, 5, 1], json_encode($mine($r['rows'])));
$r = indexListSelect($db, $cfg, ['search' => 'qorvex', 'sort' => 'seeders:desc', 'per_page' => 50, 'catalog' => false]);
check('… one word is the prefix search it always was', $mine($r['rows']) === [4, 3, 2, 5, 1], json_encode($mine($r['rows'])));

$aw = indexRankAllWords(indexRankWords('dr qorvex', true));
$clause = ['(' . $aw[0] . ')', $aw[1]];
$hits = indexListCatalogHits($db, [$clause, ['name LIKE ?', ['%dr qorvex%']]], 50);
$got = $hits ? array_values(array_filter($hits[0], fn($x) => str_starts_with($x, 'ce1822'))) : null;
sort($got);
check('the catalogue gives the matches of the same rule, with the clause that answered', $got === [$h(1), $h(2), $h(5)] && $hits[1] === $clause,
      json_encode($hits));

$cols = 'info_hash, name, first_seen, last_seeders';
[$total, $rows] = indexListByHashes($db, [], [], [$h(1), $h(2), $h(3), $h(5)], $cols, 'first_seen DESC, info_hash DESC', 2, 0);
check('… which are then sorted by any column of the index, a page at a time, the total counted', $total === 4 && $mine($rows) === [5, 3],
      json_encode([$total, $mine($rows)]));
[$total, $rows] = indexListByHashes($db, ['last_seeders >= ?'], ['6'], [$h(1), $h(2), $h(3), $h(5)], $cols, 'last_seeders DESC, info_hash DESC', 10, 0);
check('… with the filters of the page', $total === 3 && $mine($rows) === [3, 2, 5], json_encode([$total, $mine($rows)]));
check('… and nothing matched is an empty page', indexListByHashes($db, [], [], [], $cols, 'first_seen DESC', 10, 0) === [0, []]);

$rows = indexListFromCatalog($db, $clause, 'eff_seeders', true, 10, 0, $cols);
check('over the cap, the catalogue sorts by the seeders the public search counts', $mine($rows) === [2, 5, 1], json_encode($mine($rows)));

$slow = new class extends PDO {
    public int $calls = 0;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->calls++;
        $e = new PDOException('Query execution was interrupted (max_statement_time exceeded)');
        $e->errorInfo = ['70100', 1969, 'Query execution was interrupted (max_statement_time exceeded)'];
        throw $e;
    }
};
try { indexListCatalogHits($slow, [$clause, ['name LIKE ?', ['%x%']]], 5); $out = 'nothing'; }
catch (IndexSearchTimeout $e) { $out = 'timeout'; }
catch (\Throwable $e) { $out = get_class($e); }
check('a catalogue search the database stopped for time is a timeout, and the slower LIKE is not tried after it', $out === 'timeout' && $slow->calls === 1,
      $out . ' ' . $slow->calls);

$idx = $src('includes/index.php');
check('the list asks the catalogue first and sorts its matches in the index', str_contains($idx, 'indexListByHashes($db, $where, $params, array_values(array_unique(array_merge($found[0], $fileHashes))),')
      && str_contains($idx, "'seeders' => 'eff_seeders', 'sseeders' => 'eff_seeders', 'leechers' => 'eff_leechers'"));
check('… and does not run LIKE after a fulltext search that ran out of time', str_contains($idx, "if (indexIsStatementTimeout(\$e)) throw new IndexSearchTimeout(\$e->getMessage(), 0, \$e);\n            [\$total, \$rows] = \$runQuery(\$likeClause);"));
check('over the cap, a sort or a filter the catalogue cannot serve is refused at once, not run for a minute and a half',
      str_contains($idx, "} elseif (\$found !== null) {\n        throw new IndexSearchTooBroad(")
      && str_contains($src('api/admin/fetch_index.php'), "'code' => 'too_broad'], 422);")
      && str_contains(__('api.index.too_broad', ['n' => '10 000']), '10 000'));
check('the panel answers a search that ran out of time with a sentence and 503, not a server error',
      str_contains($src('api/admin/fetch_index.php'), "jsonResponse(['success' => false, 'error' => __('api.search.timeout'), 'code' => 'search_timeout'], 503);"));

$wipe();
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
