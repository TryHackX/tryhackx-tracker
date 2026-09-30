<?php
/**
 * The scrape coverage card read as PASSES, and the poll's time budget (1.72.0):
 *   php tests/index_polls_test.php
 *
 *   1. one row read (indexPollPoint()): where each shape production writes starts, what it delivered, its
 *      kind — a cut, a continuation, a download that ended early, a failed poll, a 1.29.0 row that recorded
 *      the stored cursor, a continuation on a scrape that shrank below the cursor;
 *   2. passes (indexPollPasses()) on production's own rows (2026-09-28/29: cut at 1 586 043 + 82 062 more =
 *      one pass at 100 %; the 1 453-entry tail that read "0.1 %"), a pass in progress, a short download as a
 *      pass's start and in the middle of one, a failed poll ending a pass, a fresh start abandoning an open
 *      pass, a continuation whose start is not in the rows, the old cursor rule;
 *   3. the history reply (indexPollHistory()) on the database: fixture rows at a moment far from any real
 *      row (2033), the summary per pass, the look-back to the start of the window's first pass (`lead`), a
 *      continuation whose start is gone, the window bounded both ways — every fixture row deleted exactly;
 *   4. the budget: the clamp 5..300 (default 45), the one constant in the save's clamp, the field's
 *      min/max and the search catalogue, the download's own min(90, …) untouched;
 *   5. the estimate from the newest complete pass (rate, needs, polls), and its line under the field;
 *   6. the words: a cut says cut and continues, never "truncated", in both languages; a short download
 *      keeps words of its own; every key the card's script asks for exists.
 * Needs the local test database for section 3 (deploy/local_bootstrap.php); the rest is pure.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/stats_timeline.php';
require_once $root . '/includes/index.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
/** One index_polls row as the database returns it. */
function row(int $ts, int $entries, int $skip, bool $truncated, int $ms, ?int $total, ?string $partial = null, ?string $error = null, int $kept = -1): array {
    return ['ts' => $ts, 'entries' => $entries, 'skip_from' => $skip, 'kept' => $kept >= 0 ? $kept : intdiv($entries - min($entries, $skip), 2),
            'bytes' => 43000000, 'ms' => $ms, 'truncated' => $truncated ? 1 : 0, 'partial' => $partial, 'removed_wl' => 0,
            'removed_ban' => 0, 'rows_total' => $total, 'index_rows' => 4500000, 'error' => $error];
}
function passesOf(array $rows): array { return indexPollPasses(array_map('indexPollPoint', $rows)); }

// ── 1. one row ───────────────────────────────────────────────────────────────
$p = indexPollPoint(row(100, 1586043, 0, true, 126000, 1656646));
check('row: a poll the budget cut starts at 0 and delivered what it walked', $p['start'] === 0 && $p['delivered'] === 1586043 && $p['restart']);
check('row: … it is a cut — not a short download, not a failure', $p['cut'] && $p['kind'] === 'start' && $p['partial'] === null && $p['error'] === null);
$p = indexPollPoint(row(200, 1668105, 1586043, false, 14000, 1668187));
check('row: a continuation starts at the cursor and delivered what lies past it (82 062)', $p['start'] === 1586043 && $p['delivered'] === 82062 && !$p['restart']);
check('row: … its kind is resume, and an un-cut resume is not a cut', $p['kind'] === 'resume' && !$p['cut']);
$p = indexPollPoint(row(300, 432703, 0, true, 60000, 1650000, 'the transfer ended early'));
check('row: a download that ended early read from 0, is "short", and is never counted a cut', $p['start'] === 0 && $p['kind'] === 'short' && !$p['cut'] && $p['truncated']);
$p = indexPollPoint(row(400, 900000, 0, false, 50000, 1640000, null, 'Poll failed: gone away'));
check('row: a failed poll is an error and not a cut', $p['kind'] === 'error' && !$p['cut'] && $p['error'] === 'Poll failed: gone away');
// 1.29.0 recorded the STORED cursor on a short download (1 500 000) while it read the file from 0
$p = indexPollPoint(row(500, 432703, 1500000, true, 60000, 1386870, 'Operation timed out'));
check('row: the 1.29.0 cursor rule — a short download with a stored cursor above its entries read from 0 (delivered all 432 703)',
      $p['start'] === 0 && $p['delivered'] === 432703 && $p['restart']);
$p = indexPollPoint(row(501, 900000, 300000, true, 60000, 1386870, 'Operation timed out'));
check('row: … and one whose stored cursor sat BELOW its entries read from 0 as well (not 600 000)', $p['start'] === 0 && $p['delivered'] === 900000);
// a continuation on a scrape that shrank below the cursor walked the whole shorter file and found nothing past it
$p = indexPollPoint(row(600, 1550000, 1600000, false, 20000, 1549000));
check('row: a resume on a scrape that shrank below the cursor delivered nothing and is not a restart',
      $p['start'] === 1600000 && $p['delivered'] === 0 && !$p['restart'] && $p['kind'] === 'resume');
$p = indexPollPoint(row(700, 1000, 0, false, 22, null));
check('row: an unknown tracker count stays null, never 0', $p['rows_total'] === null);

// ── 2. passes ────────────────────────────────────────────────────────────────
// production, 2026-09-28 13:54 – 14:24 and 21:54 – 22:24, and the poll that was in progress at 18:55 the next day
$prod = [
    row(1000, 1679566, 0, false, 115000, 1679187),
    row(2800, 1586043, 0, true, 126000, 1656646),
    row(4600, 1668105, 1586043, false, 14000, 1668187),
    row(6400, 1598549, 0, true, 126000, 1597619),
    row(8200, 1600002, 1598549, false, 9000, 1599807),
    row(10000, 1421325, 0, true, 128016, 1946691),
];
$g = passesOf($prod);
$P = $g['passes'];
check('passes: six polls make four passes', count($P) === 4, (string)count($P));
check('passes: a poll that walked everything alone is a pass of one, complete, at 100 %',
      $P[0]['polls'] === 1 && $P[0]['status'] === 'complete' && $P[0]['coverage'] == 100.0 && $P[0]['counted']);
check('passes: cut at 1 586 043 + 82 062 more = ONE pass, complete, 1 668 105 walked, 100 %',
      $P[1]['polls'] === 2 && $P[1]['status'] === 'complete' && $P[1]['walked'] === 1668105 && $P[1]['delivered'] === 1668105 && $P[1]['coverage'] == 100.0,
      json_encode($P[1]));
check('passes: … the resuming half is part 2 of 2 of it, not a pass of its own', $g['points'][2]['pass'] === 1 && $g['points'][2]['pos'] === 2 && $g['points'][2]['of'] === 2);
check('passes: the 1 453-entry tail ("worst poll 0.1 %") is the end of a pass at 100 %',
      $g['points'][4]['delivered'] === 1453 && $P[2]['coverage'] == 100.0 && $P[2]['status'] === 'complete');
check('passes: its duration runs from the first start to the last end (1 800 s + 9 s)', $P[2]['duration'] === 1809 && $P[2]['seconds'] == 135.0, json_encode([$P[2]['duration'], $P[2]['seconds']]));
check('passes: coverage is measured against the LAST poll\'s tracker count', $P[1]['rows_total'] === 1668187);
check('passes: the newest poll cut and not yet continued = a pass IN PROGRESS, never counted',
      $P[3]['status'] === 'in_progress' && !$P[3]['counted'] && $P[3]['walked'] === 1421325);
check('passes: the cut polls are counted as cuts (3), the rest as not', array_sum(array_column($P, 'cut')) === 3);

// a short download as the START of a pass: read from 0, and the next poll continued from where it ended
$g = passesOf([row(100, 432703, 0, true, 60000, 1650000, 'the transfer ended early'),
               row(200, 1650100, 432703, false, 90000, 1650050)]);
check('short: a download that ended early can start a pass, and the next poll completes it',
      count($g['passes']) === 1 && $g['passes'][0]['status'] === 'complete' && $g['passes'][0]['short'] === 1 && $g['passes'][0]['coverage'] == 100.0,
      json_encode($g['passes']));
// …and in the MIDDLE of an open pass: indexPoll() leaves the cursor where the pass had got, so it belongs to it
$g = passesOf([row(100, 1500000, 0, true, 126000, 1950000),
               row(200, 432703, 0, true, 60000, 1950000, 'the transfer ended early'),
               row(300, 1950500, 1500000, false, 40000, 1950400)]);
check('short: a short download while a pass is open belongs to that pass — one pass, 100 %',
      count($g['passes']) === 1 && $g['passes'][0]['polls'] === 3 && $g['passes'][0]['coverage'] == 100.0 && $g['passes'][0]['status'] === 'complete',
      json_encode($g['passes']));
check('short: … and "walked" is the furthest entry reached, not the sum', $g['passes'][0]['walked'] === 1950500 && $g['passes'][0]['delivered'] === 1500000 + 432703 + 450500);
// a failed poll ends its pass (indexPoll() resets the cursor); the next poll starts a new one
$g = passesOf([row(100, 1500000, 0, true, 126000, 1950000),
               row(200, 1600000, 1500000, false, 20000, 1950000, null, 'Poll failed: MySQL server has gone away'),
               row(300, 1949000, 0, false, 150000, 1950000)]);
check('error: a failed poll ends the pass it continued — status error, counted, its coverage what it reached',
      count($g['passes']) === 2 && $g['passes'][0]['status'] === 'error' && $g['passes'][0]['failed'] === 1 && $g['passes'][0]['counted']
      && abs($g['passes'][0]['coverage'] - 82.05) < 0.01, json_encode($g['passes'][0]));
check('error: … and the next poll opens a fresh pass', $g['passes'][1]['polls'] === 1 && $g['passes'][1]['status'] === 'complete');
// a fresh full start while a pass was still open: the cursor was lost, that pass stopped where it was
$g = passesOf([row(100, 1500000, 0, true, 126000, 1950000), row(200, 1949000, 0, false, 150000, 1950000)]);
check('abandoned: a pass left open by a fresh start is "abandoned", counted at what it reached',
      $g['passes'][0]['status'] === 'abandoned' && $g['passes'][0]['counted'] && abs($g['passes'][0]['coverage'] - 76.92) < 0.01, json_encode($g['passes'][0]));
// a continuation whose start is not in the rows
$g = passesOf([row(100, 1668105, 1586043, false, 14000, 1668187)]);
check('began before: a continuation without its start is flagged and never counted',
      $g['passes'][0]['began_before'] && !$g['passes'][0]['counted'] && $g['passes'][0]['status'] === 'complete');
// the scrape shrank below the cursor: the continuation found nothing — the pass had already walked all there is
$g = passesOf([row(100, 1600000, 0, true, 126000, 1590000), row(200, 1550000, 1600000, false, 20000, 1549000)]);
check('shrank: a continuation that found nothing past the cursor completes the pass at 100 %',
      count($g['passes']) === 1 && $g['passes'][0]['status'] === 'complete' && $g['passes'][0]['coverage'] == 100.0, json_encode($g['passes']));
// the 1.29.0 rows: a cut, then a short download that recorded the stored cursor, then the continuation
$g = passesOf([row(100, 1500000, 0, true, 126000, 1386870), row(200, 432703, 1500000, true, 60000, 1386870, 'Operation timed out'),
               row(300, 1387000, 1500000, false, 5000, 1386870)]);
check('old rows: the 1.29.0 cursor on a short download is read as the restart it was, inside the open pass',
      count($g['passes']) === 1 && $g['points'][1]['delivered'] === 432703 && $g['passes'][0]['coverage'] == 100.0, json_encode($g['passes']));
// no tracker count anywhere in a pass: a gap, not a zero
$g = passesOf([row(100, 1000, 0, false, 22, null)]);
check('no count: a pass with no tracker count has no coverage (null), not 0', $g['passes'][0]['coverage'] === null);

// ── 3. the history reply on the database ─────────────────────────────────────
$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$cfg = getSettings($db, true);
$T = 2000000000;                                   // 2033 — no real poll row is anywhere near it
$fixtures = [
    // a continuation whose start is not in the table (its row lost): the poll before it ended un-cut
    row($T - 40000, 1640000, 0, false, 118000, 1640500),
    row($T - 36000, 1645000, 1000000, false, 60000, 1645100),
    // a pass that began before a 6-hour window (7 h 10 min earlier) and is continued inside it
    row($T - 25800, 1586043, 0, true, 126000, 1656646),
    row($T - 21000, 1668105, 1586043, false, 14000, 1668187),
    row($T - 18000, 1679566, 0, false, 115000, 1679187),
    row($T - 14400, 1598549, 0, true, 126000, 1597619),
    row($T - 12600, 1600002, 1598549, false, 9000, 1599807),
    row($T - 9000, 432703, 0, true, 60000, 1650000, 'the transfer ended early'),
    row($T - 7200, 1650100, 432703, false, 90000, 1650050),
    row($T - 5400, 900000, 0, false, 50000, 1640000, null, 'Poll failed: MySQL server has gone away'),
    row($T - 3600, 1705602, 0, true, 127000, 1955285),
    row($T - 1800, 1955578, 1705602, false, 25000, 1955727),
    row($T - 300, 1421325, 0, true, 128016, 1946691),
];
$tsList = implode(',', array_map(static fn($r) => (int)$r['ts'], $fixtures));
$clash = (int)$db->query("SELECT COUNT(*) FROM index_polls WHERE ts BETWEEN " . ($T - 200000) . " AND " . ($T + 200000))->fetchColumn();
check('db: no row of the database sits in the fixtures\' time (2033)', $clash === 0, (string)$clash);
$before = $db->query("SELECT COUNT(*), COALESCE(SUM(ts), 0), COALESCE(SUM(entries), 0) FROM index_polls")->fetch(PDO::FETCH_NUM);
$ins = $db->prepare("INSERT INTO index_polls (ts, entries, skip_from, kept, bytes, ms, truncated, partial, removed_wl, removed_ban, rows_total, index_rows, error)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
try {
    if ($clash === 0) {
        foreach ($fixtures as $r) $ins->execute([$r['ts'], $r['entries'], $r['skip_from'], $r['kept'], $r['bytes'], $r['ms'], $r['truncated'],
                                                 $r['partial'], 0, 0, $r['rows_total'], $r['index_rows'], $r['error']]);
        $h = indexPollHistory($db, $cfg, '6h', $T);
        $s = $h['summary'];
        check('history: the 6-hour window holds its ten polls (the one before it is not a point)', count($h['points']) === 10 && $s['polls'] === 10,
              (string)count($h['points']));
        check('history: the first point continues a pass whose start is BEFORE the window — read back as `lead`',
              count($h['lead']) === 1 && $h['lead'][0]['ts'] === $T - 25800 && $h['points'][0]['pass'] === 0);
        check('history: … so that pass is whole: two polls, complete, 100 %, counted',
              $h['passes'][0]['polls'] === 2 && $h['passes'][0]['coverage'] == 100.0 && $h['passes'][0]['counted'] && !$h['passes'][0]['began_before'],
              json_encode($h['passes'][0]));
        check('history: seven passes — five complete, one ended by a failed poll, one in progress',
              count($h['passes']) === 7 && count(array_filter($h['passes'], static fn($x) => $x['status'] === 'complete')) === 5
              && count(array_filter($h['passes'], static fn($x) => $x['status'] === 'error')) === 1 && $s['in_progress'] !== null,
              json_encode(array_column($h['passes'], 'status')));
        check('history: the worst PASS is the failed one (54.88 %), not a continuing half', abs($s['min_coverage'] - 54.88) < 0.01, json_encode($s['min_coverage']));
        check('history: the average is over the six finished passes', $s['counted'] === 6 && abs($s['avg_coverage'] - 92.5) < 0.05, json_encode([$s['counted'], $s['avg_coverage']]));
        check('history: cuts (3 in the window), short downloads (1), failed polls (1) counted apart', $s['cut'] === 3 && $s['short'] === 1 && $s['failed'] === 1,
              json_encode([$s['cut'], $s['short'], $s['failed']]));
        check('history: the last pass is the one in progress, 1 421 325 walked so far', $s['last_pass']['status'] === 'in_progress' && $s['last_pass']['walked'] === 1421325);
        check('history: the estimate comes from the newest COMPLETE pass (1 955 578 in 152 s) against the newest count',
              $s['estimate'] !== null && $s['estimate']['rate'] === 12866 && $s['estimate']['scrape'] === 1946691 && $s['estimate']['needs'] === 152,
              json_encode($s['estimate']));
        check('history: at the budget in force (' . indexPollBudget($cfg) . ' s) that is ' . (int)ceil(152 / indexPollBudget($cfg)) . ' polls',
              $s['estimate']['polls'] === (int)ceil(152 / indexPollBudget($cfg)) && $s['budget'] === indexPollBudget($cfg) && $s['budget_max'] === IDX_POLL_BUDGET_MAX);
        check('history: some pass took one poll, so min_polls is 1 (the "every pass needed more" note stays away)', $s['min_polls'] === 1 && $s['max_polls'] === 2);
        // a window that opens on a continuation whose start is gone: the row before it ended un-cut, so
        // nothing is read back, and the pass is flagged — never a number in the summary
        $h2 = indexPollHistory($db, $cfg, '1h', $T - 35500);
        check('history: a continuation whose start is gone reads nothing back and is flagged',
              count($h2['points']) === 1 && count($h2['lead']) === 0 && $h2['passes'][0]['began_before'] === true,
              json_encode(['points' => count($h2['points']), 'lead' => count($h2['lead']), 'passes' => $h2['passes']]));
        check('history: … and is not counted: no average, no worst', $h2['summary']['counted'] === 0 && $h2['summary']['avg_coverage'] === null && $h2['summary']['min_coverage'] === null);
        // a window that opens on a short download: the poll before it ended un-cut, so it starts its pass
        $h4 = indexPollHistory($db, $cfg, '1h', $T - 6000);
        check('history: a window opening on a short download reads back only what kept a pass open (nothing here)',
              count($h4['lead']) === 0 && $h4['points'][0]['kind'] === 'short', json_encode(['lead' => count($h4['lead']), 'first' => $h4['points'][0]['kind'] ?? null]));
        check('history: … the short download starts its pass, the next poll completes it at 100 %',
              count($h4['passes']) === 1 && $h4['passes'][0]['polls'] === 2 && $h4['passes'][0]['status'] === 'complete' && $h4['passes'][0]['short'] === 1
              && $h4['passes'][0]['coverage'] == 100.0 && $h4['summary']['short'] === 1, json_encode($h4['passes']));
        // the window is bounded above: at $T - 6000 the last four rows have not happened
        $h3 = indexPollHistory($db, $cfg, '24h', $T - 6000);
        check('history: nothing after the window\'s end is in it', max(array_column($h3['points'], 'ts')) <= $T - 6000 && count($h3['points']) === 9,
              (string)count($h3['points']));
        // a range name nobody knows is 24 hours; 'all' is the retention
        check('history: an unknown range reads as 24h', indexPollHistory($db, $cfg, 'nonsense', $T)['range'] === '24h');
        check('history: "all" reaches back the whole retention', indexPollHistory($db, $cfg, 'all', $T)['from'] === $T - max(1, min(3650, (int)($cfg['index_poll_keep_days'] ?? 90))) * 86400);
        // the endpoint is the function and nothing else
        $ep = (string)file_get_contents($root . '/api/admin/index_polls.php');
        check('endpoint: api/admin/index_polls.php answers with indexPollHistory()', str_contains($ep, 'indexPollHistory($db, $cfg,') && !str_contains($ep, 'SELECT'));
    }
} finally {
    $db->exec("DELETE FROM index_polls WHERE ts IN ($tsList)");
    $after = $db->query("SELECT COUNT(*), COALESCE(SUM(ts), 0), COALESCE(SUM(entries), 0) FROM index_polls")->fetch(PDO::FETCH_NUM);
    check('db: the table is exactly as it was (rows, their times, their entries)', $after == $before, json_encode([$before, $after]));
}

// ── 4. the budget ────────────────────────────────────────────────────────────
check('budget: the default is 45 s', indexPollBudget([]) === 45);
check('budget: the ceiling is 300 (was 120)', IDX_POLL_BUDGET_MAX === 300 && indexPollBudget(['index_poll_budget' => '300']) === 300
      && indexPollBudget(['index_poll_budget' => '301']) === 300 && indexPollBudget(['index_poll_budget' => '99999']) === 300);
check('budget: 121–300 is kept as asked', indexPollBudget(['index_poll_budget' => '121']) === 121 && indexPollBudget(['index_poll_budget' => '240']) === 240);
check('budget: the floor is 5', IDX_POLL_BUDGET_MIN === 5 && indexPollBudget(['index_poll_budget' => '4']) === 5 && indexPollBudget(['index_poll_budget' => '-10']) === 5);
check('budget: nothing, 0 and garbage fall back to 45', indexPollBudget(['index_poll_budget' => '0']) === 45 && indexPollBudget(['index_poll_budget' => 'abc']) === 45
      && indexPollBudget(['index_poll_budget' => '']) === 45);
$save = (string)file_get_contents($root . '/api/admin/save_settings.php');
check('budget: the save clamps with the same two constants', str_contains($save, "'index_poll_budget' => [IDX_POLL_BUDGET_MIN, IDX_POLL_BUDGET_MAX, 45]"));
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
check('budget: the field\'s min= and max= are the constants', str_contains($tpl, 'name="index_poll_budget"') && str_contains($tpl, 'min="<?= IDX_POLL_BUDGET_MIN ?>" max="<?= IDX_POLL_BUDGET_MAX ?>"'));
check('budget: no 120 is left beside the field', !preg_match('/name="index_poll_budget"[^>]*max="120"/', $tpl));
require_once $root . '/includes/settings_catalog.php';
$catWords = '';
foreach ((array)settingsCatalogKeywords() as $k => $w) { if ($k === 'index_poll_budget') $catWords = (string)$w; }
check('budget: the search catalogue knows it by its new words (300, cut, continues, pass)',
      str_contains($catWords, '300') && str_contains($catWords, 'cut') && str_contains($catWords, 'continues') && str_contains($catWords, 'pass'), $catWords);
$idx = (string)file_get_contents($root . '/includes/index.php');
check('budget: the download keeps its own ceiling, min(90, …)', str_contains($idx, 'indexFetchFullScrape(indexSourceUrl($cfg), min(90, max(5, indexPollBudget($cfg))), $tmpDir)'));

// ── 5. the estimate ──────────────────────────────────────────────────────────
$pass = ['status' => 'complete', 'began_before' => false, 'walked' => 1955578, 'seconds' => 152.0, 'ms' => 152000, 'first_ts' => 1, 'polls' => 2];
$e = indexPollEstimate($pass, 1955727, 120);
check('estimate: 1 955 578 walked in 152 s = 12 866 entries/s', $e['rate'] === 12866, json_encode($e));
check('estimate: the scrape of 1 955 727 needs about 153 s — at 120 s that is 2 polls', $e['needs'] === 153 && $e['polls'] === 2, json_encode($e));
check('estimate: at 300 s one poll walks it', indexPollEstimate($pass, 1955727, 300)['polls'] === 1);
check('estimate: at 45 s it takes 4', indexPollEstimate($pass, 1955727, 45)['polls'] === 4);
check('estimate: without a count, the pass\'s own length is the scrape', indexPollEstimate($pass, null, 300)['scrape'] === 1955578);
check('estimate: none from a pass in progress, one that began before, or none at all',
      indexPollEstimate(['status' => 'in_progress'] + $pass, 1, 120) === null && indexPollEstimate(['began_before' => true] + $pass, 1, 120) === null
      && indexPollEstimate(null, 1955727, 120) === null);
check('estimate: none from a pass with no time or nothing walked',
      indexPollEstimate(['ms' => 0, 'seconds' => 0] + $pass, 1, 120) === null && indexPollEstimate(['walked' => 0] + $pass, 1, 120) === null);
check('estimate: the Settings line is drawn from indexPollEstimateNow() under the field, and the script redoes its last clause',
      str_contains($tpl, 'indexPollEstimateNow($db, $cfg)') && str_contains($tpl, 'id="idx-budget-estimate"')
      && str_contains((string)file_get_contents($root . '/assets/js/admin-settings.js'), "document.getElementById('idx-budget-estimate')"));

// ── 6. the words ─────────────────────────────────────────────────────────────
$en = langLoad('en'); $pl = langLoad('pl');
$cut = ['js.coverage.all_truncated_alert', 'js.coverage.arrived_truncated', 'js.coverage.one_poll_truncated', 'js.coverage.truncated_note',
        'js.index.truncated_resumes', 'js.index.truncated_suffix', 'settings.index_poll_budget_hint'];
foreach ($cut as $k) {
    // cut, or what follows a cut (the next poll continues) — never the word for a broken download
    check("words: $k says cut / continues and never truncated (EN)", isset($en[$k]) && preg_match('/\bcut\b|continu/i', $en[$k]) && stripos($en[$k], 'truncat') === false, $en[$k] ?? 'missing');
    check("words: $k — przycięte / kontynuuje, never obcięte / ucięte (PL)", isset($pl[$k]) && preg_match('/przycię|kontynu/iu', $pl[$k])
          && !preg_match('/obci[ęe]t|uci[ęe]t/iu', $pl[$k]), $pl[$k] ?? 'missing');
}
check('words: the one-poll line says exactly what the brief asked', ($en['js.coverage.one_poll_truncated'] ?? '') === 'cut by the time budget — the next poll continues where this one stopped');
check('words: a continuing poll\'s tooltip reads "continues the previous poll from entry … — together …"',
      __('js.coverage.tip_resume', ['from' => '1 586 043', 'pct' => '100 %']) === 'continues the previous poll from entry 1 586 043 — together 100 %',
      __('js.coverage.tip_resume', ['from' => '1 586 043', 'pct' => '100 %']));
check('words: … and in Polish', langFor('pl', 'js.coverage.tip_resume', ['from' => '1 586 043', 'pct' => '100 %']) === 'kontynuuje poprzednie odpytanie od wpisu 1 586 043 — razem 100 %');
foreach (['js.coverage.ended_early', 'js.coverage.legend_short', 'js.index.ended_early_badge', 'js.index.partial_fetch'] as $k) {
    check("words: a short download keeps its own words — $k", stripos($en[$k] ?? '', 'ended early') !== false && str_contains($pl[$k] ?? '', 'urwa')
          && stripos($en[$k] ?? '', 'cut') === false, ($en[$k] ?? 'missing') . ' / ' . ($pl[$k] ?? 'missing'));
}
$coverageEn = array_filter($en, static fn($v, $k) => str_starts_with($k, 'js.coverage.'), ARRAY_FILTER_USE_BOTH);
check('words: no line of the coverage card says "truncated" any more', !array_filter($coverageEn, static fn($v) => stripos($v, 'truncat') !== false),
      implode(' | ', array_keys(array_filter($coverageEn, static fn($v) => stripos($v, 'truncat') !== false))));
check('words: the per-poll figures are gone (worst poll, polls in window, series)', !isset($en['js.coverage.worst_poll']) && !isset($en['js.coverage.polls_in_window'])
      && !isset($en['js.coverage.series_delivered']) && isset($en['js.coverage.worst_pass']) && isset($en['js.coverage.passes_in_window']));
check('words: the budget hint names its range', str_contains(__('settings.index_poll_budget_hint', ['min' => 5, 'max' => 300]), '5–300 s'));
check('words: the intro counts per pass (EN / PL)', str_contains($en['a.index.cov_intro'] ?? '', 'pass') && str_contains($pl['a.index.cov_intro'] ?? '', 'przebieg')
      && stripos($en['a.index.cov_intro'] ?? '', 'truncated') === false);
// every key the scripts ask for exists, in both languages
foreach (['assets/js/admin-index-coverage.js', 'assets/js/admin-index.js', 'assets/js/admin-settings.js'] as $f) {
    $js = (string)file_get_contents($root . '/' . $f);
    preg_match_all("/t\\('((?:js)\\.[a-z_]+\\.[a-z0-9_.]+)'/", $js, $mm);
    // keys built from a prefix ('js.coverage.pass_' + status, 'js.coverage.' + key) are listed by hand below
    $asked = array_filter(array_unique($mm[1]), static fn($k) => !str_ends_with($k, '_') && !str_ends_with($k, '.'));
    $missing = array_values(array_filter($asked, static fn($k) => !isset($en[$k]) || !isset($pl[$k])));
    check("words: every key $f asks for exists in both languages", $missing === [], implode(', ', $missing));
}
foreach (['pass_complete', 'pass_in_progress', 'pass_error', 'pass_abandoned', 'pass_began_before', 'legend_start', 'legend_resume',
          'legend_short', 'legend_error', 'legend_kept', 'legend_tracker', 'legend_progress'] as $k) {
    check("words: js.coverage.$k (built from a prefix) exists", isset($en['js.coverage.' . $k], $pl['js.coverage.' . $k]));
}
foreach (['complete', 'in_progress', 'error', 'abandoned'] as $st) {
    check("words: every pass status the server can send ($st) has words", isset($en['js.coverage.pass_' . $st]) && str_contains($idx, "'" . $st . "'"));
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
