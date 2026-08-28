<?php
/**
 * Up or down, on one hash. Nothing in between.
 *
 * ── the whole problem is abuse, not counting ────────────────────────────────
 *
 * Tallying two numbers is trivial. What is not trivial is that a public voting button is the easiest
 * thing on a site to automate: one loop, a thousand negatives, and the score means nothing for ever.
 * So almost everything here is about who is allowed to vote and how often, and the counting is an
 * afterthought.
 *
 * Four layers, because no single one holds:
 *
 *   1. **One vote per hash per identity, enforced by a UNIQUE key** — not by a SELECT in PHP. Two
 *      requests arriving together must collide in the database, where the collision is real; a
 *      check-then-insert in PHP is a race with a comfortable window.
 *   2. **Rate limit** per address and per account, on the existing rate limiter.
 *   3. **CAPTCHA on points**, using the scheme already here (`captcha_pts_*`): a vote costs points
 *      and the CAPTCHA appears at the threshold. Not on the first vote — on the tenth.
 *   4. **Weight**, so a fresh anonymous vote and a long-standing account do not count the same.
 *
 * ── about attributing votes to an IP ────────────────────────────────────────
 *
 * `REMOTE_ADDR` comes from the TCP connection: forging it means completing a handshake from the
 * forged address, which over the internet means it is not forged. PHP is not "broken" here. What IS
 * forgeable is a header, and getClientIp() already refuses to read one unless the request genuinely
 * arrived from an address listed in `trusted_proxy_ips`.
 *
 * What no amount of care fixes: one person with a VPN, a phone, and a /64 of IPv6 has as many
 * "addresses" as they care to use. So IPv6 is bucketed to /64 (a single customer allocation), the
 * limits are per bucket, accounts outweigh anonymous votes, and the panel says plainly that a score
 * built from anonymous votes is a weak signal. Pretending otherwise would be the actual failure.
 */

function repEnabled(array $cfg): bool { return ($cfg['rep_enabled'] ?? '0') === '1'; }

/** 'off' | 'users' | 'all' — who may cast a vote. */
function repWhoCanVote(array $cfg): string {
    $v = (string)($cfg['rep_who_can_vote'] ?? 'users');
    return in_array($v, ['off', 'users', 'all'], true) ? $v : 'users';
}

function repShowInResults(array $cfg): bool { return ($cfg['rep_show_in_results'] ?? '0') === '1'; }

/**
 * 'thumbs' — up or down. 'stars' — five stars in half-star steps.
 *
 * Stars are stored as 1..10, which is what "half a star" actually means: the display rounds to five,
 * the storage does not, and an average of 3.5 is a real value rather than something inferred from a
 * percentage. The two modes share a table because a vote is a vote; only the reading differs.
 */
function repMode(array $cfg): string {
    $m = (string)($cfg['rep_mode'] ?? 'thumbs');
    return in_array($m, ['thumbs', 'stars'], true) ? $m : 'thumbs';
}

/** The values a vote may take in the current mode: [-1, 1] or [1..10]. */
function repAllowedValues(array $cfg): array {
    return repMode($cfg) === 'stars' ? range(1, 10) : [-1, 1];
}

/** Below this many votes the score is not a score, and the UI says so instead of showing a number. */
function repMinVotes(array $cfg): int { return max(1, min(1000, (int)($cfg['rep_min_votes'] ?? 3) ?: 3)); }

/** What an anonymous vote is worth against a signed-in one, in hundredths. */
function repAnonWeight(array $cfg): int { return max(0, min(100, (int)($cfg['rep_anon_weight'] ?? 25))); }

/** Votes per hour, per identity. */
function repRatePerHour(array $cfg): int { return max(1, min(1000, (int)($cfg['rep_rate_per_hour'] ?? 30) ?: 30)); }

/**
 * Who is voting, as a stable key.
 *
 * A signed-in account is itself. Everyone else is their address BUCKET — IPv4 whole, IPv6 to /64,
 * because a /64 is one customer and treating each address in it as a separate voter would be
 * treating one person as eighteen quintillion people.
 */
function repVoterKey(PDO $db, array $cfg): ?array {
    if (function_exists('currentUser')) {
        $u = currentUser($db);
        if ($u && !empty($u['id'])) return ['type' => 'user', 'key' => (string)(int)$u['id'], 'weight' => 100];
    }
    if (repWhoCanVote($cfg) !== 'all') return null;
    $ip = function_exists('ipBucket') ? ipBucket(getClientIp($cfg)) : getClientIp($cfg);
    if ($ip === '') return null;
    return ['type' => 'ip', 'key' => $ip, 'weight' => repAnonWeight($cfg)];
}

/** Why this visitor may not vote right now, or null. */
function repVoteRefusal(PDO $db, array $cfg): ?string {
    if (!repEnabled($cfg)) return 'Ratings are off on this tracker.';
    $who = repWhoCanVote($cfg);
    if ($who === 'off') return 'Ratings are read-only here.';
    $voter = repVoterKey($db, $cfg);
    if ($voter === null) {
        return $who === 'users' ? 'Sign in to rate.' : 'Could not identify you well enough to accept a rating.';
    }
    if ($who === 'users' && $voter['type'] !== 'user') return 'Sign in to rate.';
    // A group can be denied rating without being denied everything else. Anonymous voters are
    // governed by the guest group, which is what "who can vote: anyone" really means.
    if (function_exists('userCan') && !userCan($db, $cfg, 'rating.vote')) {
        return $voter['type'] === 'user'
            ? 'Your account does not have rating access.'
            : 'Ratings are limited to accounts with rating access here.';
    }
    if (function_exists('rateLimitAllow')
        && !rateLimitAllow('repvote', $voter['type'] . ':' . $voter['key'], repRatePerHour($cfg), 3600)) {
        return 'That is a lot of ratings in one hour. Try again later.';
    }
    return null;
}

/**
 * Record a vote. Returns the fresh totals, or an error.
 *
 * $vote is 1 or -1. Voting the same way twice is idempotent; voting the other way changes the
 * existing vote rather than adding a second one. There is no third state and no "unvote": a button
 * that can be un-pressed doubles the surface for automation and buys nothing a change of mind does
 * not already cover.
 */
function repCastVote(PDO $db, array $cfg, string $hash, int $vote): array {
    $hash = strtolower(trim($hash));
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return ['error' => 'Invalid hash'];
    if (!in_array($vote, repAllowedValues($cfg), true)) {
        return ['error' => repMode($cfg) === 'stars'
            ? 'A rating is between half a star and five stars.'
            : 'A rating is either up or down.'];
    }
    $refusal = repVoteRefusal($db, $cfg);
    if ($refusal !== null) return ['error' => $refusal];
    $voter = repVoterKey($db, $cfg);

    // The UNIQUE key is what actually enforces one vote per identity. ON DUPLICATE KEY turns a
    // second vote into a change of mind, atomically — two requests racing each other collide here,
    // in the database, rather than both passing a SELECT that was true a millisecond ago.
    $db->prepare(
        "INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote, weight, created_at, updated_at)
              VALUES (?, ?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE vote = VALUES(vote), weight = VALUES(weight), updated_at = NOW()")
       ->execute([$hash, $voter['type'], $voter['key'], $vote, (int)$voter['weight']]);

    repRecount($db, $hash, $cfg);
    return ['success' => true] + repFor($db, $cfg, $hash);
}

/**
 * Recompute the stored totals for one hash.
 *
 * Kept on the row rather than aggregated per listing: a search page showing a score for fifty rows
 * would otherwise be fifty aggregate queries, and this project has already been bitten once by a
 * listing that did per-row work over a large table.
 */
function repRecount(PDO $db, string $hash, ?array $cfg = null): void {
    $mode = is_array($cfg) ? repMode($cfg) : 'thumbs';
    // CAST(weight AS SIGNED) is not decoration. weight is SMALLINT UNSIGNED and vote is signed, so
    // MySQL promotes the product to UNSIGNED and -1 * 100 becomes a number the size of the universe:
    // "BIGINT UNSIGNED value is out of range". The query then fails for any hash that ever received a
    // down-vote — which is to say, in production, on the first one.
    $st = $db->prepare(
        "SELECT COUNT(*) n,
                SUM(vote = 1) up, SUM(vote = -1) down,
                SUM(CASE WHEN vote = 1 THEN weight ELSE 0 END) wup,
                SUM(CASE WHEN vote = -1 THEN weight ELSE 0 END) wdown,
                SUM(vote * CAST(weight AS SIGNED)) wsum, SUM(weight) wtot
           FROM hash_votes WHERE info_hash = ?");
    $st->execute([$hash]);
    $r = $st->fetch() ?: [];
    $n    = (int)($r['n'] ?? 0);
    $up   = (int)($r['up'] ?? 0);
    $down = (int)($r['down'] ?? 0);

    if ($mode === 'stars') {
        // The weighted mean, in hundredths of a star: 4.5 stars is 450. Weight is what makes an
        // account count for more than an anonymous voter, exactly as in the other mode.
        $wsum = (int)($r['wsum'] ?? 0);
        $wtot = (int)($r['wtot'] ?? 0);
        // vote is 1..10 (half-star steps), so the mean is halved to land back on a five-star scale.
        $score = $wtot > 0 ? (int)round($wsum * 100 / $wtot / 2) : 0;
        $up = $n; $down = 0;   // meaningless here; votes_count is the number that is read
    } else {
        $wu = (int)($r['wup'] ?? 0);
        $wd = (int)($r['wdown'] ?? 0);
        $score = ($wu + $wd) > 0 ? (int)round($wu * 10000 / ($wu + $wd)) : 0;
    }

    foreach (['index_hashes', 'whitelist'] as $t) {
        try {
            $db->prepare("UPDATE `$t` SET votes_up = ?, votes_down = ?, votes_count = ?, score_x100 = ?
                           WHERE info_hash = ?")
               ->execute([$up, $down, $n, $score, $hash]);
        } catch (\Throwable $e) { /* a table without the columns yet is not worth failing on */ }
    }
}

/** The reputation of one hash, as the UI needs it — in whichever mode is switched on. */
function repFor(PDO $db, array $cfg, string $hash): array {
    $st = $db->prepare("SELECT COUNT(*) n, SUM(vote = 1) up, SUM(vote = -1) down,
                               SUM(CASE WHEN vote = 1 THEN weight ELSE 0 END) wup,
                               SUM(CASE WHEN vote = -1 THEN weight ELSE 0 END) wdown,
                               SUM(vote * CAST(weight AS SIGNED)) wsum, SUM(weight) wtot
                          FROM hash_votes WHERE info_hash = ?");
    $st->execute([strtolower($hash)]);
    $r = $st->fetch() ?: [];
    $mode  = repMode($cfg);
    $total = (int)($r['n'] ?? 0);
    $min   = repMinVotes($cfg);
    $enough = $total >= $min;

    $out = [
        'mode'      => $mode,
        'total'     => $total,
        'enough'    => $enough,
        'min_votes' => $min,
        'up'        => (int)($r['up'] ?? 0),
        'down'      => (int)($r['down'] ?? 0),
        'percent'   => null,
        'stars'     => null,
    ];

    // Null, not zero, below the threshold. "0%" and "nobody has said" are different facts, and a bar
    // or a row of stars that cannot tell them apart is worse than showing nothing.
    if (!$enough) return $out;

    if ($mode === 'stars') {
        $wsum = (int)($r['wsum'] ?? 0);
        $wtot = (int)($r['wtot'] ?? 0);
        // One decimal, because that is the precision a half-star scale actually has. Rounding to a
        // whole star would throw away the thing the mode exists for.
        $out['stars'] = $wtot > 0 ? round($wsum / $wtot / 2, 1) : null;
    } else {
        $wu = (int)($r['wup'] ?? 0);
        $wd = (int)($r['wdown'] ?? 0);
        $out['percent'] = ($wu + $wd) > 0 ? (int)round($wu * 100 / ($wu + $wd)) : null;
    }
    return $out;
}

/** My own vote on this hash, so the button can show which way it went. 0 = none. */
function repMyVote(PDO $db, array $cfg, string $hash): int {
    $voter = repVoterKey($db, $cfg);
    if ($voter === null) return 0;
    $st = $db->prepare("SELECT vote FROM hash_votes WHERE info_hash = ? AND voter_type = ? AND voter_key = ? LIMIT 1");
    $st->execute([strtolower($hash), $voter['type'], $voter['key']]);
    return (int)($st->fetchColumn() ?: 0);
}

/**
 * How much CAPTCHA pressure a vote adds.
 *
 * The site already has a points scheme: actions add points and the CAPTCHA appears past a threshold.
 * A vote joins it rather than inventing a second mechanism — which means somebody voting steadily is
 * never bothered, and somebody voting fifty times in a minute meets a CAPTCHA without anybody having
 * to write a bot detector.
 */
function repCaptchaPoints(array $cfg): int { return max(0, min(100, (int)($cfg['captcha_pts_vote'] ?? 2))); }

/** Wipe every vote on a hash — used when a hash is banned, so a ban does not leave a score behind. */
function repClear(PDO $db, string $hash): int {
    $st = $db->prepare("DELETE FROM hash_votes WHERE info_hash = ?");
    $st->execute([strtolower($hash)]);
    $n = $st->rowCount();
    if ($n) repRecount($db, strtolower($hash));   // mode does not matter: everything is zero now
    return $n;
}
