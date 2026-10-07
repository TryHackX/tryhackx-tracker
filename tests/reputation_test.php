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
 *
 * Taking a vote back (1.71.0), sections 7–9: in both modes, only ever the caller's own row (an account's
 * vote is never an address's, an address's /64 is one voter), counted again in the site's mode, behind
 * every gate a vote passes and out of the same hourly budget — which the page drawing the buttons no
 * longer spends — and the endpoint's explicit operation, as requests with a session and its token.
 * Self-cleaning: its accounts, group, votes, catalogue rows and the rate-limit entries it spends.
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
// For sections 8 and 9 (1.71.0): the accounts that vote, and who is voting.
require_once $root . '/includes/schema.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/favourites.php';
require_once $root . '/includes/people.php';
require_once $root . '/includes/usermedia.php';
require_once $root . '/includes/profilevotes.php';
require_once $root . '/includes/lists.php';
require_once $root . '/includes/who.php';
require_once $root . '/includes/lang.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }
langInit([], 'en');   // the refusals below are compared in the dictionary's words, English here and in the requests

/* ── 1. off is off ────────────────────────────────────────────────────────── */

check('ratings are off by default', !repEnabled([]));
// The refusal path is checked against the real function, not a placeholder. A helper that returns
// true unconditionally is a test that can never fail, which is worse than not having one.
$src = (string)file_get_contents($root . '/includes/reputation.php');
// The sentence lives in the dictionary now (api.rep.off): the function must return that key first,
// and the key must still say ratings are off — the source check alone would pass a renamed key.
check('… and the refusal names that as the first reason it says no',
      preg_match('/function repVoteRefusal.*?!repEnabled.*?return __\(.api\.rep\.off.\)/s', $src) === 1
      && str_starts_with(__('api.rep.off'), 'Ratings are off'));
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
// 1.73.0: a row still proving itself is served — its proof is a peer announcing HERE, which a whitelist-mode
// tracker refuses for a hash it does not carry — and only a row that failed is left out
// (tests/partner_api_test.php writes the real file and reads it back).
check('the accesslist skips rows that failed to prove themselves, and serves the ones still proving',
      str_contains($wl, "probe_status IN ('none','probing','passed')"));
check("… and 'none' is in that list, so switching the check on never unpublishes anything",
      str_contains($wl, "'none','probing','passed'"));

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
// The property is not "the number is 64" — it is that the PANEL and the WORKER agree on it. They
// once did not: the panel offered up to 64 while the running worker enforced 16, and a stored 32 was
// therefore read as garbage and silently replaced by the config default of 4. So both ceilings are
// extracted and compared, and the test fails loudly if either cannot be found at all rather than
// passing on an empty match.
$panelSrc  = (string)file_get_contents($root . '/api/admin/save_settings.php');
$workerSrc = (string)file_get_contents($root . '/worker/worker.py');
$panelMax  = preg_match('/meta_worker_concurrency.*?min\((\d+),/s', $panelSrc, $pm) ? (int)$pm[1] : 0;
$workerMax = preg_match('/^CONCURRENCY_MAX\s*=\s*(\d+)/m', $workerSrc, $wm) ? (int)$wm[1] : 0;
check('the panel states a parallel-fetch ceiling', $panelMax > 0, 'found ' . $panelMax);
check('the worker states one too, as a named constant', $workerMax > 0, 'found ' . $workerMax);
check('and the two agree — a panel offering more than the worker accepts is how 32 became 4',
      $panelMax === $workerMax, "panel=$panelMax worker=$workerMax");
// An out-of-range number must be CLAMPED by the worker, never discarded: discarding falls back to
// the config file, which is how asking for more parallelism produced less than before.
check('the worker clamps an out-of-range request instead of ignoring it',
      str_contains($workerSrc, 'val = max(1, min(CONCURRENCY_MAX, asked))'));

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
        repClear($db, $cfg, $hash);
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

/* ── 7. taking a vote back (1.71.0): what the code says ───────────────────── */

$src = (string)file_get_contents($root . '/includes/reputation.php');
$rh = (string)file_get_contents($root . '/api/rate_hash.php');
$ii = (string)file_get_contents($root . '/api/index_info.php');
check('the old rule is gone — "There is no third state and no \"unvote\"" — and the reasoning for taking a vote back stands in its place',
      !preg_match('/There is no third state and no\s+"unvote"/', $src) && str_contains($src, '── taking a vote back (1.71.0)')
      && str_contains($src, 'a separate and EXPLICIT operation (rate_hash {op: \'remove\'})'));
check('repRemoveVote() asks the same refusal a vote asks, and spends from the same hourly budget (both pass true)',
      preg_match('/function repRemoveVote\(PDO \$db, array \$cfg, string \$hash\): array \{.*?\$refusal = repVoteRefusal\(\$db, \$cfg, true\);/s', $src) === 1
      && preg_match('/function repCastVote\(.*?\$refusal = repVoteRefusal\(\$db, \$cfg, true\);/s', $src) === 1);
check('… deletes only the caller\'s own row: the hash AND the identity repVoterKey() casts with',
      preg_match('/function repRemoveVote.*?\$voter = repVoterKey\(\$db, \$cfg\);\s*\$st = \$db->prepare\("DELETE FROM hash_votes WHERE info_hash = \? AND voter_type = \? AND voter_key = \?"\);\s*\$st->execute\(\[\$hash, \$voter\[.type.\], \$voter\[.key.\]\]\);/s', $src) === 1);
check('… and counts again in the site\'s mode', preg_match('/function repRemoveVote.*?repRecount\(\$db, \$hash, \$cfg\);/s', $src) === 1);
$rcParams = (new ReflectionFunction('repClear'))->getParameters();
check('repClear() takes $cfg — required, so it cannot be left out again — and recounts with it (it recounted without)',
      count($rcParams) === 3 && $rcParams[1]->getName() === 'cfg' && (string)$rcParams[1]->getType() === 'array' && !$rcParams[1]->isOptional()
      && preg_match('/function repClear\(PDO \$db, array \$cfg, string \$hash\): int \{.*?repRecount\(\$db, \$hash, \$cfg\);/s', $src) === 1);
check('… and the ban hands it the site\'s', str_contains($wl, 'repClear($db, $cfg, (string)$h)') && !preg_match('/repClear\(\$db, \(string\)/', $wl));
check('rate_hash: the operation is named — vote (the default, the old shape) or remove — and anything else is a 400',
      str_contains($rh, "\$op = \$input['op'] ?? 'vote';") && str_contains($rh, "in_array(\$op, ['vote', 'remove'], true)")
      && str_contains($rh, "jsonResponse(['error' => __('api.rep.unknown_op')], 400);"));
// 1.71.0: the CAPTCHA a vote meets is the anti-spam layer's (context `vote`, includes/antispam.php) — the old gate
// asked isCaptchaRequired('vote'), whose `recaptcha_on_vote` switch no setting ever defined.
check('… a removal meets the CAPTCHA and pays its points exactly as a vote does: both come before the branch',
      preg_match('/antispamCheck\(\$db, \$cfg, .vote.,.*?addCaptchaPoints\(\$cfg, .vote.\);\s*if \(\$op === .remove.\) \{\s*\$r = repRemoveVote\(\$db, \$cfg, \$hash\);/s', $rh) === 1
      && !str_contains($rh, 'isCaptchaRequired($cfg'));
check('drawing the buttons only LOOKS at the hour\'s budget: the refusal spends when told to, and peeks (rateLimitPeek) otherwise',
      str_contains($src, 'function repVoteRefusal(PDO $db, array $cfg, bool $spend = false): ?string')
      && preg_match('/\$spend \? \(function_exists\(.rateLimitAllow.\) && !rateLimitAllow\(.repvote., \$bucket.*?: \(function_exists\(.rateLimitPeek.\) && !rateLimitPeek\(.repvote., \$bucket/s', $src) === 1
      && function_exists('rateLimitPeek'));
// rate_hash asks three times without spending: the GET's two, and the POST's look before the anti-spam layer (1.71.0).
check('… the Info panel and rate_hash\'s GET ask without spending (no third argument anywhere but the two actions)',
      substr_count($ii, 'repVoteRefusal($db, $cfg)') === 2 && substr_count($rh, 'repVoteRefusal($db, $cfg)') === 3
      && substr_count($src, 'repVoteRefusal($db, $cfg, true)') === 2);

/* ── 8. taking a vote back, against the live schema ───────────────────────── */

if ($db === null) {
    skip('taking a vote back against the real schema', 'no database on this machine');
} else {
    $cfg = getSettings($db);
    $GLOBALS['db'] = $db;
    $T = str_repeat('c7c7', 10);   // thumbs
    $S = str_repeat('c6c6', 10);   // stars
    // The addresses (as their buckets) this run votes from: documentation ranges, nobody's real traffic.
    $repTestIps = ['198.51.100.71', '198.51.100.72', '198.51.100.73', '198.51.100.74', '2001:db8:71:1::/64', '2001:db8:71:2::/64'];
    $repTestUsers = [];
    $repClean = function () use ($db, $T, $S, $root, $repTestIps, &$repTestUsers): void {
        foreach ($db->query("SELECT id FROM users WHERE username LIKE 'reptest\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $repTestUsers[] = (int)$id;
            userDeleteCascade($db, (int)$id);
        }
        $db->exec("DELETE FROM user_groups WHERE slug LIKE 'reptest\\_%'");
        $db->prepare("DELETE FROM hash_votes WHERE info_hash IN (?, ?)")->execute([$T, $S]);
        $db->prepare("DELETE FROM index_hashes WHERE info_hash IN (?, ?)")->execute([$T, $S]);
        // The hour's budget this run spent — its own accounts and addresses — taken back from the vote's own file
        // (config/ratelimit/, one per action since 1.74.0), under that file's lock.
        $mine = array_merge(array_map(fn($ip) => 'ip:' . $ip, $repTestIps), array_map(fn($id) => 'user:' . $id, array_unique($repTestUsers)));
        rateLimitForget('repvote', fn(string $s): bool => in_array($s, $mine, true));
    };
    $repClean();
    register_shutdown_function($repClean);
    try {
        $base = array_merge($cfg, ['rep_enabled' => '1', 'rep_who_can_vote' => 'all', 'rep_min_votes' => '1', 'rep_rate_per_hour' => '1000',
                                   'rep_anon_weight' => '25', 'users_enabled' => '1', 'users_require_email_verify' => '0',
                                   'trusted_proxy_ips' => '', 'client_ip_header' => '']);
        $cT = array_merge($base, ['rep_mode' => 'thumbs']);
        $cS = array_merge($base, ['rep_mode' => 'stars']);
        // An anonymous voter's own policy is the feature's settings (who may vote: anyone): with accounts off,
        // rating.vote is the legacy default and no group is asked — so no guest group is touched here.
        $aT = array_merge($cT, ['users_enabled' => '0']);
        $aS = array_merge($cS, ['users_enabled' => '0']);
        $GLOBALS['cfg'] = $cT;
        $db->prepare("INSERT INTO index_hashes (info_hash, name, first_seen, last_seen) VALUES (?, 'reptest thumbs', '2026-09-01 00:00:00', '2026-09-01 00:00:00'),
                                                                                         (?, 'reptest stars', '2026-09-01 00:00:00', '2026-09-01 00:00:00')")->execute([$T, $S]);
        $db->prepare("INSERT INTO user_groups (slug, name, description, color, priority, is_default, is_system, permissions) VALUES ('reptest_v', 'reptest_v', '', '', 2, 0, 0, ?)")
           ->execute([json_encode(['rating.vote' => true])]);
        $gid = (int)$db->lastInsertId();
        $mk = function (string $name) use ($db, $cT, $gid): int {
            $r = userCreate($db, $cT, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
            $id = (int)($r['user']['id'] ?? 0);
            $db->prepare("UPDATE users SET email_verified = 1, status = 'active' WHERE id = ?")->execute([$id]);
            $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$id]);
            userGrantGroup($db, $id, $gid, null, 'test', 'reputation_test', false, '2000-01-01 00:00:00');
            userPermissionsForget($id);
            return $id;
        };
        $A = $mk('reptest_a'); $B = $mk('reptest_b');
        // Who is voting: the account the session would hold (currentUser()'s own memo) and the address.
        $as = function (?int $uid, string $ip) use ($db): void {
            $GLOBALS['__current_user_loaded'] = true;
            $GLOBALS['__current_user_cache'] = $uid ? userFindById($db, $uid) : null;
            $_SERVER['REMOTE_ADDR'] = $ip;
        };
        // Every voter on a hash as "type:key=vote", sorted here — so the expectations below are sorted too.
        $rows = function (string $h) use ($db): array {
            $st = $db->prepare("SELECT voter_type, voter_key, vote FROM hash_votes WHERE info_hash = ?");
            $st->execute([$h]);
            $out = array_map(fn($r) => $r['voter_type'] . ':' . $r['voter_key'] . '=' . $r['vote'], $st->fetchAll(PDO::FETCH_ASSOC));
            sort($out, SORT_STRING);
            return $out;
        };
        $srt = function (array $x): array { sort($x, SORT_STRING); return $x; };
        $cat = function (string $h) use ($db): array {
            $st = $db->prepare("SELECT votes_up, votes_down, votes_count, score_x100 FROM index_hashes WHERE info_hash = ?");
            $st->execute([$h]);
            return array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: []);
        };
        [$ip1, $ip2, $ip3, $ip4] = ['198.51.100.71', '198.51.100.72', '198.51.100.73', '198.51.100.74'];

        // ── thumbs: four voters, then each takes back only what is theirs ──
        $as($A, $ip1); $r1 = repCastVote($db, $cT, $T, 1);
        $as($B, $ip2); $r2 = repCastVote($db, $cT, $T, -1);
        $as(null, $ip1); $r3 = repCastVote($db, $aT, $T, 1);                 // somebody signed out, at A's address
        $as(null, '2001:db8:71:1::5'); $r4 = repCastVote($db, $aT, $T, -1);  // an IPv6 visitor
        check('thumbs: two accounts and two addresses (one of them the first account\'s own) each have a vote',
              !empty($r1['success']) && !empty($r2['success']) && !empty($r3['success']) && !empty($r4['success'])
              && $rows($T) === $srt(['ip:198.51.100.71=1', 'ip:2001:db8:71:1::/64=-1', 'user:' . $A . '=1', 'user:' . $B . '=-1']),
              json_encode([$rows($T), $r1['error'] ?? null, $r3['error'] ?? null]));
        $as(null, $ip1); $x = repRemoveVote($db, $aT, $T);
        check('an address takes back its own vote — and never the vote of an account cast from that same address',
              !empty($x['success']) && $x['removed'] === true
              && $rows($T) === $srt(['ip:2001:db8:71:1::/64=-1', 'user:' . $A . '=1', 'user:' . $B . '=-1']), json_encode([$x, $rows($T)]));
        check('… counted again: 1 up, 2 down, and the weighted score (an account 100, an address 25) 100 / 225',
              $cat($T) === ['votes_up' => 1, 'votes_down' => 2, 'votes_count' => 3, 'score_x100' => 4444], json_encode($cat($T)));
        $as($A, $ip1); $x = repRemoveVote($db, $cT, $T);
        check('an account takes back its own vote: its row goes, every other voter\'s stays',
              !empty($x['success']) && $x['removed'] === true && repMyVote($db, $cT, $T) === 0
              && $rows($T) === $srt(['ip:2001:db8:71:1::/64=-1', 'user:' . $B . '=-1']) && $x['total'] === 2 && $x['up'] === 0 && $x['down'] === 2,
              json_encode([$x, $rows($T)]));
        check('… and the catalogue row says so at once (0 up, 2 down)', $cat($T) === ['votes_up' => 0, 'votes_down' => 2, 'votes_count' => 2, 'score_x100' => 0], json_encode($cat($T)));
        $x = repRemoveVote($db, $cT, $T);
        check('taking back a vote that is not there: success, removed false, nothing else touched (idempotent)',
              !empty($x['success']) && $x['removed'] === false && $rows($T) === $srt(['ip:2001:db8:71:1::/64=-1', 'user:' . $B . '=-1']), json_encode($x));
        $as(null, '2001:db8:71:2::5'); $x = repRemoveVote($db, $aT, $T);
        check('an address in another /64 is another voter: nothing to take back',
              !empty($x['success']) && $x['removed'] === false && count($rows($T)) === 2, json_encode($x));
        $as(null, '2001:db8:71:1::99'); $x = repRemoveVote($db, $aT, $T);
        check('… an address in the SAME /64 is the same voter (one customer, one vote), and takes it back',
              !empty($x['success']) && $x['removed'] === true && $rows($T) === ['user:' . $B . '=-1'], json_encode([$x, $rows($T)]));

        // ── the same gates as a vote, and the row stays when one says no ──
        $as($A, $ip1); repCastVote($db, $cT, $T, 1);
        $gates = [
            'ratings off'                          => [repRemoveVote($db, array_merge($cT, ['rep_enabled' => '0']), $T), __('api.rep.off')],
            'read-only (who may vote: nobody)'     => [repRemoveVote($db, array_merge($cT, ['rep_who_can_vote' => 'off']), $T), __('api.rep.read_only')],
        ];
        $as(null, $ip1);
        $gates['a visitor where only accounts vote'] = [repRemoveVote($db, array_merge($cT, ['rep_who_can_vote' => 'users']), $T), __('api.rep.sign_in')];
        $db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")->execute([json_encode(['index.view' => true]), $gid]);
        userPermissionsForget($A);
        // (Found here, 1.71.0: with e-mail verification off the memo's key is the bare id, which PHP keeps as an
        // integer, and userPermissionsForget() compared it strictly with a string — so it forgot nothing.)
        check('a group changed in the same request is what the account may do next: userPermissionsForget() forgets a bare-id memo',
              userEffectivePermissions($db, $A, $cT) === ['index.view' => true] && !userIdHasPermission($db, $cT, $A, 'rating.vote'),
              json_encode(userEffectivePermissions($db, $A, $cT)));
        $as($A, $ip1);
        $gates['an account whose groups do not grant rating.vote'] = [repRemoveVote($db, $cT, $T), __('api.rep.no_access_account')];
        $db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")->execute([json_encode(['rating.vote' => true]), $gid]);
        userPermissionsForget($A);
        $bad = array_filter($gates, fn($g) => ($g[0]['error'] ?? '') !== $g[1] || !empty($g[0]['success']));
        check('a removal is refused wherever a vote is — ' . implode(', ', array_keys($gates)) . ' — each with the vote\'s own words',
              !$bad, json_encode(array_map(fn($g) => $g[0], $bad)));
        check('… and the vote those refusals were about is still there', in_array('user:' . $A . '=1', $rows($T), true), json_encode($rows($T)));

        // ── stars: counted again in the site's mode ──
        $as($A, $ip1); repCastVote($db, $cS, $S, 10);
        $as($B, $ip2); repCastVote($db, $cS, $S, 6);
        $as(null, $ip3); repCastVote($db, $aS, $S, 2);
        check('stars: three ratings — 5, 3 and 1 (an address, weight 25) — and the catalogue\'s weighted mean 1650 / 225 / 2',
              $cat($S) === ['votes_up' => 3, 'votes_down' => 0, 'votes_count' => 3, 'score_x100' => 367], json_encode($cat($S)));
        $as($A, $ip1); $x = repRemoveVote($db, $cS, $S);
        check('stars: taking one back recounts in STARS — 650 / 125 / 2 = 2.60 — where a recount without the mode read the rest as thumbs and wrote 0',
              !empty($x['success']) && $x['removed'] === true && $x['mode'] === 'stars' && abs(((float)$x['stars']) - 2.6) < 0.001
              && $cat($S) === ['votes_up' => 2, 'votes_down' => 0, 'votes_count' => 2, 'score_x100' => 260], json_encode([$x, $cat($S)]));
        check('… and it was only that account\'s rating', $rows($S) === $srt(['ip:198.51.100.73=2', 'user:' . $B . '=6']) && repMyVote($db, $cS, $S) === 0, json_encode($rows($S)));
        repRecount($db, $S, $cT);
        $asThumbs = $cat($S);
        repRecount($db, $S, $cS);
        check('(the proof the mode matters: the same rows recounted as thumbs give another score)',
              $asThumbs['score_x100'] !== 260 && $cat($S)['score_x100'] === 260, json_encode($asThumbs));

        // ── repClear(), with the site's configuration ──
        $n2 = repClear($db, $cS, $S);
        check('repClear() with $cfg: every vote on the hash gone, the catalogue at zero', $n2 === 2 && $rows($S) === []
              && $cat($S) === ['votes_up' => 0, 'votes_down' => 0, 'votes_count' => 0, 'score_x100' => 0], json_encode([$n2, $cat($S)]));

        // ── the hour's budget: looking is free, voting and taking back are not ──
        $c3 = array_merge($aT, ['rep_rate_per_hour' => '3']);
        $as(null, $ip4);
        $looks = [];
        for ($i = 0; $i < 10; $i++) $looks[] = repVoteRefusal($db, $c3);
        $rl = rateLimitHits('repvote');
        check('ten looks at whether a visitor may vote (the Info panel asks twice an opening) spend nothing',
              $looks === array_fill(0, 10, null) && empty($rl['ip:' . $ip4]), json_encode($rl['ip:' . $ip4] ?? null));
        $acts = [repCastVote($db, $c3, $T, 1), repRemoveVote($db, $c3, $T), repCastVote($db, $c3, $T, -1)];
        $fourth = repRemoveVote($db, $c3, $T);
        check('three actions spend the three an hour allows — a vote, taking it back, a vote — and the fourth is refused',
              !empty($acts[0]['success']) && !empty($acts[1]['success']) && !empty($acts[2]['success'])
              && ($fourth['error'] ?? '') === __('api.rep.rate_limited'), json_encode([$acts, $fourth]));
        check('… the refused removal left the vote where it was, and now the buttons are refused too (a look sees the spent budget)',
              in_array('ip:' . $ip4 . '=-1', $rows($T), true) && repVoteRefusal($db, $c3) === __('api.rep.rate_limited'), json_encode($rows($T)));
    } catch (\Throwable $e) {
        check('taking a vote back against the real schema ran to the end', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

/* ── 9. the endpoint: rate_hash's explicit operation, as requests with a session and its token ──── */

if ($db === null) {
    skip('rate_hash as requests', 'no database on this machine');
} else {
    $tmpFiles = [];
    register_shutdown_function(function () use (&$tmpFiles) { foreach ($tmpFiles as $f) @unlink($f); });
    $runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rep_runner_' . bin2hex(random_bytes(4)) . '.php';
    $tmpFiles[] = $runner;
    // who_test's runner, and one thing more: the session's CAPTCHA points, written out before the session goes.
    file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = $a["ip"];
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/richtext.php", "includes/reputation.php", "includes/mail.php", "includes/users.php",
          "includes/favourites.php", "includes/usermedia.php", "includes/people.php", "includes/profilevotes.php", "includes/lists.php",
          "includes/who.php", "includes/audit.php", "includes/auth.php", "includes/antispam.php", "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () use ($a) {
    @file_put_contents($a["pts"], (string)($_SESSION["captcha_points"] ?? 0));
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
langInit($cfg, "en");
require $a["file"];
');
    $reqCfg = ['rep_enabled' => '1', 'rep_mode' => 'thumbs', 'rep_who_can_vote' => 'users', 'rep_min_votes' => '1', 'rep_rate_per_hour' => '1000',
               'users_enabled' => '1', 'users_require_email_verify' => '0', 'recaptcha_enabled' => '0', 'captcha_pts_vote' => '3',
               'trusted_proxy_ips' => '', 'client_ip_header' => ''];
    $post = function (array $body, ?int $uid) use ($root, $runner, &$tmpFiles, $reqCfg): array {
        $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rep_args_' . bin2hex(random_bytes(4)) . '.json';
        $ptsFile = $argFile . '.pts';
        $tmpFiles[] = $argFile; $tmpFiles[] = $ptsFile;
        $session = ['csrf_token' => 'rep-child-token'] + ($uid ? ['user_id' => $uid, 'user_login_time' => time()] : []);
        file_put_contents($argFile, json_encode(['root' => $root, 'file' => $root . '/api/rate_hash.php', 'method' => 'POST', 'ip' => '198.51.100.71',
            'get' => [], 'post' => $body, 'session' => $session, 'cfg' => $reqCfg, 'pts' => $ptsFile, 'sid' => 'reptest' . bin2hex(random_bytes(8))]));
        $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner) . ' ' . escapeshellarg($argFile) . ' 2>&1');
        $j = json_decode(trim($out), true);
        $j = is_array($j) ? $j : ['__raw' => substr($out, 0, 400)];
        $j['__pts'] = is_file($ptsFile) ? (int)file_get_contents($ptsFile) : null;
        return $j;
    };
    try {
        $T = str_repeat('c7c7', 10);
        $A = (int)$db->query("SELECT id FROM users WHERE username = 'reptest_a'")->fetchColumn();
        $B = (int)$db->query("SELECT id FROM users WHERE username = 'reptest_b'")->fetchColumn();
        $votesOf = function (int $uid) use ($db, $T): array {
            $st = $db->prepare("SELECT vote FROM hash_votes WHERE info_hash = ? AND voter_type = 'user' AND voter_key = ?");
            $st->execute([$T, (string)$uid]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        };
        if ($A <= 0 || $B <= 0) throw new RuntimeException('the accounts of section 8 are missing');
        $db->prepare("DELETE FROM hash_votes WHERE info_hash = ? AND voter_type = 'user' AND voter_key = ?")->execute([$T, (string)$A]);
        $j = $post(['hash' => $T, 'vote' => 1, 'csrf_token' => 'rep-child-token'], $A);
        check('POST rate_hash {hash, vote} — the old shape, no op — still casts: my_vote 1', !empty($j['success']) && ($j['my_vote'] ?? null) === 1 && $votesOf($A) === [1], json_encode($j));
        $votePts = $j['__pts'];
        $j = $post(['hash' => $T, 'op' => 'remove', 'csrf_token' => 'rep-child-token'], $A);
        check('POST rate_hash {hash, op: remove}: removed, my_vote 0, the rating without it', !empty($j['success']) && ($j['removed'] ?? null) === true
              && ($j['my_vote'] ?? null) === 0 && $votesOf($A) === [] && isset($j['rating']['total']), json_encode($j));
        check('… and it paid the CAPTCHA points a vote pays (captcha_pts_vote 3, both)', $votePts === 3 && $j['__pts'] === 3, json_encode([$votePts, $j['__pts']]));
        $j = $post(['hash' => $T, 'op' => 'remove', 'csrf_token' => 'rep-child-token'], $A);
        check('… asked again: success, removed false — an explicit operation, not a toggle', !empty($j['success']) && ($j['removed'] ?? null) === false && $votesOf($A) === [], json_encode($j));
        $j = $post(['hash' => $T, 'op' => 'vote', 'vote' => -1, 'csrf_token' => 'rep-child-token'], $A);
        check('POST {op: vote, vote: -1} casts', !empty($j['success']) && ($j['my_vote'] ?? null) === -1 && $votesOf($A) === [-1], json_encode($j));
        $j = $post(['hash' => $T, 'op' => 'remove', 'csrf_token' => 'not-the-token'], $A);
        check('never without the session\'s token: 403 in the dictionary\'s words, and the vote stays', ($j['error'] ?? '') === __('api.csrf.invalid') && $votesOf($A) === [-1], json_encode($j));
        $j = $post(['hash' => $T, 'op' => 'remove'], $A);
        check('… nor with no token at all', ($j['error'] ?? '') === __('api.csrf.invalid') && $votesOf($A) === [-1], json_encode($j));
        $j = $post(['hash' => $T, 'op' => 'toggle', 'csrf_token' => 'rep-child-token'], $A);
        check('an operation that is neither: 400, "Unknown operation", nothing changed', ($j['error'] ?? '') === __('api.rep.unknown_op') && $votesOf($A) === [-1], json_encode($j));
        $j = $post(['hash' => $T, 'op' => 'remove', 'csrf_token' => 'rep-child-token'], null);
        check('signed out, where only accounts vote: the vote\'s own refusal ("Sign in to rate.")', ($j['error'] ?? '') === __('api.rep.sign_in') && $votesOf($A) === [-1], json_encode($j));
        $bBefore = $votesOf($B);
        $j = $post(['hash' => $T, 'op' => 'remove', 'csrf_token' => 'rep-child-token'], $A);
        check('an account\'s removal over the wire takes its own vote and leaves the other account\'s',
              !empty($j['success']) && ($j['removed'] ?? null) === true && $votesOf($A) === [] && $votesOf($B) === $bBefore && $bBefore === [-1], json_encode([$j, $bBefore]));
    } catch (\Throwable $e) {
        check('rate_hash as requests ran to the end', false, $e->getMessage());
    }
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
