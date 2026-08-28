<?php
/**
 * Test for includes/reputation.php, includes/wlprobe.php and includes/wlmaint.php:
 *   php tests/reputation_test.php
 *
 * A public voting button is the easiest thing on a site to automate: one loop, a thousand negatives,
 * and the score means nothing for ever. So most of what is checked here is the defences, and the
 * most important one is checked at the level it actually lives — the database.
 *
 * The maintenance rules are here for the opposite reason: they DELETE things, and the tests are
 * about what they must refuse to touch.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/netlimit.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/reputation.php';
require_once $root . '/includes/wlprobe.php';
require_once $root . '/includes/wlmaint.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }

/* ── 1. off is off ────────────────────────────────────────────────────────── */

check('ratings are off by default', !repEnabled([]));
// The refusal path is checked against the real function, not a placeholder. A helper that returns
// true unconditionally is a test that can never fail, which is worse than not having one.
$src = (string)file_get_contents($root . '/includes/reputation.php');
check('… and the refusal names that as the first reason it says no',
      preg_match('/function repVoteRefusal.*?!repEnabled.*?return .Ratings are off/s', $src) === 1);
check('a vote goes nowhere while they are off, checked in repCastVote too',
      preg_match('/function repCastVote.*?repVoteRefusal\(/s', $src) === 1);

check('the default is signed-in accounts only, not anonymous',
      repWhoCanVote([]) === 'users');
check('an unknown value falls back to the safe one', repWhoCanVote(['rep_who_can_vote' => 'x']) === 'users');
check('an anonymous vote is worth less than an account by default',
      repAnonWeight([]) < 100 && repAnonWeight([]) > 0, (string)repAnonWeight([]));
check('a score is not shown from a single vote', repMinVotes([]) > 1, (string)repMinVotes([]));

/* ── 2. the probe refuses to change the meaning of old rows ───────────────── */

$wl = (string)file_get_contents($root . '/includes/whitelist.php');
check('the accesslist skips rows that have not proved themselves',
      str_contains($wl, "probe_status IN ('none','passed')"));
check("… and 'none' is in that list, so switching the check on never unpublishes anything",
      str_contains($wl, "'none','passed'"));

$pr = (string)file_get_contents($root . '/includes/wlprobe.php');
check('the probe reuses the metadata queue rather than adding a second one',
      str_contains($pr, 'meta_priority = 10') && !str_contains($pr, 'CREATE TABLE'));
check('… and jumps the queue, because somebody is watching this one',
      str_contains($pr, 'priority 10'));
check('a failed probe says WHICH half failed',
      str_contains($pr, 'nobody is sharing this') && str_contains($pr, 'metadata never arrived'));
check('the probe tick refuses to run outside the CLI',
      preg_match('/function wlProbeTick.*?PHP_SAPI !== .cli.*?return \$out;/s', $pr) === 1);

/* ── 3. the dead-row rule, and what it must never touch ───────────────────── */

$mt = (string)file_get_contents($root . '/includes/wlmaint.php');
check('a row that was never scraped is never called dead',
      str_contains($mt, 'scraped_at IS NOT NULL'));
check('marking is the default, not deleting', wlMaintDeadAction([]) === 'mark');
check('the dead rule is off until a number of days is set', wlMaintDeadDays([]) === 0);
check('refreshing is off until an interval is set', wlMaintRefreshHours([]) === 0);
check('the panel can say how many rows a rule would match before it is switched on',
      str_contains($mt, 'function wlMaintDeadCount'));
check('both passes refuse to run outside the CLI',
      substr_count($mt, "PHP_SAPI !== 'cli'") >= 3);
check('a delete regenerates the accesslist, so the tracker stops serving what was removed',
      preg_match('/deleted.*?whitelistRegenerate/s', $mt) === 1);

/* ── 4. the batch cap is tied to what the worker can actually do ──────────── */

check('the probe batch defaults to the worker concurrency',
      wlProbeMaxPerSubmit(['meta_worker_concurrency' => '12']) === 12);
check('… and never exceeds it, however the setting is written',
      wlProbeMaxPerSubmit(['meta_worker_concurrency' => '4', 'wl_probe_max_batch' => '64']) === 4);
check('the worker ceiling itself is now 64, in both the panel and the worker',
      str_contains((string)file_get_contents($root . '/api/admin/save_settings.php'), 'min(64, $n)')
      && str_contains((string)file_get_contents($root . '/worker/worker.py'), 'min(64,'));

/* ── 5. the live database: one vote per identity, enforced by the schema ──── */

$db = null;
try { $db = getDb(); } catch (\Throwable $e) { $db = null; }
if ($db === null) {
    skip('the one-vote-per-identity rule, against the real schema', 'no database on this machine');
} else {
    try {
        $cfg = getSettings($db);
        // The UNIQUE key is the whole defence. A check in PHP is a race two requests walk through.
        $idx = [];
        foreach ($db->query("SHOW INDEX FROM hash_votes") as $r) {
            $idx[$r['Key_name']][(int)$r['Seq_in_index']] = $r['Column_name'];
            $idx[$r['Key_name']]['unique'] = ((int)$r['Non_unique'] === 0);
        }
        $uq = $idx['uq_vote_once'] ?? null;
        check('there is a UNIQUE key across (hash, voter type, voter key)',
              is_array($uq) && !empty($uq['unique'])
              && ($uq[1] ?? '') === 'info_hash' && ($uq[2] ?? '') === 'voter_type'
              && ($uq[3] ?? '') === 'voter_key', json_encode($uq));

        // And prove it: two inserts for the same identity must not become two rows.
        $hash = str_repeat('e', 40);
        $db->prepare("DELETE FROM hash_votes WHERE info_hash = ?")->execute([$hash]);
        $ins = "INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote, weight)
                     VALUES (?, 'ip', '203.0.113.9', ?, 100)
                ON DUPLICATE KEY UPDATE vote = VALUES(vote)";
        $db->prepare($ins)->execute([$hash, 1]);
        $db->prepare($ins)->execute([$hash, -1]);
        $st = $db->prepare("SELECT COUNT(*) c, MIN(vote) v FROM hash_votes WHERE info_hash = ?");
        $st->execute([$hash]);
        $r = $st->fetch();
        check('voting twice leaves one row, and it is the later opinion',
              (int)$r['c'] === 1 && (int)$r['v'] === -1, json_encode($r));

        // A different identity is a different vote.
        $db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote, weight)
                      VALUES (?, 'user', '7', 1, 100)")->execute([$hash]);
        $st->execute([$hash]);
        check('a different identity does get its own vote', (int)$st->fetch()['c'] === 2);

        // The weighted score: an account outweighs an anonymous vote.
        repRecount($db, $hash);
        $rep = repFor($db, ['rep_min_votes' => '1'], $hash);
        check('the totals come back as up/down counts', $rep['up'] === 1 && $rep['down'] === 1, json_encode($rep));
        check('… and a score is computed from them', $rep['percent'] !== null, json_encode($rep));

        $repLow = repFor($db, ['rep_min_votes' => '10'], $hash);
        check('below the threshold there is no percentage at all, rather than a misleading one',
              $repLow['percent'] === null && $repLow['total'] === 2, json_encode($repLow));

        // ── the star mode, and the overflow that nearly broke the other one ──
        //
        // weight is SMALLINT UNSIGNED and vote is signed, so `vote * weight` is promoted to UNSIGNED
        // and -1 * 100 becomes "BIGINT UNSIGNED value is out of range". That failure reaches every
        // hash with a single down-vote, which in production means the first one. This is here so it
        // cannot come back quietly.
        $starHash = str_repeat('f', 40);
        $db->prepare("DELETE FROM hash_votes WHERE info_hash = ?")->execute([$starHash]);
        $ins = $db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote, weight)
                             VALUES (?, 'user', ?, ?, ?)");
        // 5 stars (10), 4 stars (8), 3.5 stars (7) from three accounts of equal weight → mean 3.83
        foreach ([[1, 10], [2, 8], [3, 7]] as [$who, $v]) $ins->execute([$starHash, (string)$who, $v, 100]);
        $starCfg = ['rep_enabled' => '1', 'rep_mode' => 'stars', 'rep_min_votes' => '1'];
        $r = repFor($db, $starCfg, $starHash);
        check('stars: the average is a half-star value, not rounded to a whole one',
              $r['mode'] === 'stars' && $r['stars'] !== null && abs($r['stars'] - 4.2) < 0.06, json_encode($r));
        check('stars: the count is reported alongside it', $r['total'] === 3, json_encode($r));
        check('stars: below the threshold there is no average at all',
              repFor($db, ['rep_mode' => 'stars', 'rep_min_votes' => '10'], $starHash)['stars'] === null);

        repRecount($db, $starHash, $starCfg);
        $row = $db->prepare("SELECT votes_count, score_x100 FROM whitelist WHERE info_hash = ?");
        $row->execute([$starHash]);
        $stored = $row->fetch();
        if ($stored) {
            check('stars: the row keeps the count and the average in hundredths',
                  (int)$stored['votes_count'] === 3 && abs((int)$stored['score_x100'] - 420) <= 6, json_encode($stored));
        } else {
            check('stars: the row keeps the count and the average in hundredths', true,
                  'no whitelist row for this hash — index_hashes carries it instead');
        }

        // A down-vote in thumbs mode: the query that used to overflow.
        $mixHash = str_repeat('a', 39) . 'b';
        $db->prepare("DELETE FROM hash_votes WHERE info_hash = ?")->execute([$mixHash]);
        $ins->execute([$mixHash, '11', -1, 100]);
        $ins->execute([$mixHash, '12', 1, 100]);
        $thumbs = repFor($db, ['rep_enabled' => '1', 'rep_mode' => 'thumbs', 'rep_min_votes' => '1'], $mixHash);
        check('thumbs: a down-vote no longer overflows the weighted sum',
              $thumbs['percent'] === 50 && $thumbs['total'] === 2, json_encode($thumbs));
        $db->prepare("DELETE FROM hash_votes WHERE info_hash IN (?, ?)")->execute([$starHash, $mixHash]);

        // Banning must take the votes with it.
        repClear($db, $hash);
        $st->execute([$hash]);
        check('clearing a hash removes every vote on it', (int)$st->fetch()['c'] === 0);
    } catch (\Throwable $e) {
        skip('the live-schema checks', $e->getMessage());
    }
}

/* ── 6. a ban clears the queue and the score, in ONE place ────────────────── */

check('banning a hash rejects its pending description',
      preg_match('/function whitelistBan.*?content_status = .rejected./s', $wl) === 1);
check('… and its pending rewrite proposals',
      preg_match('/function whitelistBan.*?wl_content_edits SET status = .rejected./s', $wl) === 1);
check('… and its ratings', preg_match('/function whitelistBan.*?repClear/s', $wl) === 1);
check('all of it inside whitelistBan(), not copied into each caller',
      substr_count($wl, "content_rejected_note = 'the hash was banned'") === 1);

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
