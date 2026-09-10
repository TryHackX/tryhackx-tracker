<?php
/**
 * Favourites, public profiles and "my uploads" — the rules, in one place.
 *
 * ── the privacy rule, once, so every reader of this file has it ─────────────────────────────────
 *
 * Somebody appears on a list about themselves only when EVERY gate above them says yes: the site
 * allows public favourites at all, the site allows the "who has this" list at all, their group holds
 * `favourites.public`, their own list is public, and they have not asked to be left off other
 * people's lists. Any single no removes them from the rows AND from the count — never "14 people
 * have this, 3 shown". That difference is stable and cumulative, so anyone polling the counter
 * learns the exact moment a hidden person favourited something. A count that can be differenced is
 * a list of hidden names written slowly.
 *
 * ── what is deliberately NOT here ───────────────────────────────────────────────────────────────
 *
 * No denormalised counter on the catalogue row. repRecount() keeps one for votes and it is tempting
 * to copy — but a vote carries no privacy and a favourite does. An honest count depends on the flags
 * of everybody who favourited the hash, so it would have to be recomputed on every checkbox and
 * every group edit. A counter that lags is a leak that lags.
 *
 * No background job and nothing new in the per-request janitor: index.php already runs four of those
 * on every request. The unique key makes the toggle a single statement, and fav_max_per_user bounds
 * the table absolutely.
 */

/** The master switch. With it off, every endpoint here answers as though the feature did not exist. */
function favEnabled(array $cfg): bool {
    return usersEnabled($cfg) && (($cfg['fav_enabled'] ?? '0') === '1');
}

/** May a list be public at all on this site? Separate from the master switch on purpose. */
function favPublicEnabled(array $cfg): bool {
    return favEnabled($cfg) && (($cfg['fav_public_enabled'] ?? '0') === '1');
}

/** Does "who has this in their favourites" exist on this site? */
function favWhoEnabled(array $cfg): bool {
    return favPublicEnabled($cfg) && (($cfg['fav_who_enabled'] ?? '0') === '1');
}

/** Is ?action=u reachable? Its own switch: the uploads half of a profile does not need favourites. */
function profilesEnabled(array $cfg): bool {
    return usersEnabled($cfg) && (($cfg['profiles_enabled'] ?? '0') === '1');
}

/**
 * How many favourites one account may keep.
 *
 * The point is not the number, it is that there IS one. Everything else in this file assumes the
 * per-user set is small enough to be fetched whole and handed to an IN() — a table with no ceiling
 * would turn "show me my favourites" into a join against a 3.4-million-row catalogue.
 */
function favMaxPerUser(array $cfg): int {
    return max(10, min(5000, (int)($cfg['fav_max_per_user'] ?? 500) ?: 500));
}

/** Does the per-row visibility flag on a whitelist submission apply anywhere on this site? */
function uploadsPublicEnabled(array $cfg): bool {
    return usersEnabled($cfg) && (($cfg['wl_submitter_public'] ?? '0') === '1');
}

/**
 * Can this installation attribute a submission to anybody at all?
 *
 * api/whitelist_submit.php refuses every submission outside whitelist mode and the schedule, so on
 * a blacklist-mode tracker with no schedule there is nothing for a profile to show and the tab is
 * hidden ENTIRELY rather than shown empty. Note this is NOT the same as "whitelist mode": a
 * blacklist-mode install with the schedule on still takes submissions.
 */
function uploadsPossible(array $cfg): bool {
    return trackerMode($cfg) === 'whitelist'
        || (function_exists('scheduleEnabled') && scheduleEnabled($cfg));
}

/* ── the list itself ────────────────────────────────────────────────────────────────────────── */

/** Every hash this user has favourited, newest first. Bounded by the limit, served by idx_fav_user. */
function favHashesOf(PDO $db, int $userId, int $limit): array {
    $st = $db->prepare("SELECT info_hash FROM user_favourites WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit);
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function favCountOf(PDO $db, int $userId): int {
    $st = $db->prepare("SELECT COUNT(*) FROM user_favourites WHERE user_id = ?");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

function favHas(PDO $db, int $userId, string $hash): bool {
    $st = $db->prepare("SELECT 1 FROM user_favourites WHERE user_id = ? AND info_hash = ? LIMIT 1");
    $st->execute([$userId, $hash]);
    return (bool)$st->fetchColumn();
}

/**
 * Add or remove, idempotently, in one statement each.
 *
 * `INSERT IGNORE` and `DELETE` both lean on uq_fav_once, so two taps in the same second cannot make
 * two rows and removing something that is already gone is a no-op rather than an error. Returns
 * ['on' => bool, 'added' => bool] — `on` is the state afterwards, which is what the star renders.
 */
function favToggle(PDO $db, array $cfg, int $userId, string $hash, ?bool $want = null): array {
    $has = favHas($db, $userId, $hash);
    $target = $want === null ? !$has : $want;
    if ($target === $has) return ['on' => $has, 'changed' => false];
    if ($target) {
        // Checked here rather than by a trigger so the reply can say WHY, and checked before the
        // insert so the limit is a limit rather than a suggestion.
        if (favCountOf($db, $userId) >= favMaxPerUser($cfg)) {
            return ['on' => false, 'changed' => false, 'error' => 'fav_limit', 'limit' => favMaxPerUser($cfg)];
        }
        $db->prepare("INSERT IGNORE INTO user_favourites (user_id, info_hash) VALUES (?, ?)")->execute([$userId, $hash]);
        return ['on' => true, 'changed' => true];
    }
    $db->prepare("DELETE FROM user_favourites WHERE user_id = ? AND info_hash = ?")->execute([$userId, $hash]);
    return ['on' => false, 'changed' => true];
}

/**
 * Which of these hashes the user has favourited — ONE query for a whole page of results.
 *
 * Never per row. A page of 200 results would otherwise be 200 round trips against a table that lives
 * in the same database as the mail and the forum.
 */
function favMarkFor(PDO $db, int $userId, array $hashes): array {
    $hashes = array_values(array_filter(array_unique($hashes), static fn($h) => is_string($h) && preg_match('/^[0-9a-f]{40}$/i', $h)));
    if (!$hashes || $userId <= 0) return [];
    $out = [];
    foreach (array_chunk($hashes, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT info_hash FROM user_favourites WHERE user_id = ? AND info_hash IN ($in)");
        $st->execute(array_merge([$userId], array_map('strtolower', $chunk)));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $h) $out[$h] = true;
    }
    return $out;
}

/**
 * The metadata for a page of favourited hashes — the two-cheap-queries pattern, never a join.
 *
 * NEVER `FROM index_hashes h JOIN user_favourites f … MATCH(h.name)`: the optimiser starts at the
 * fulltext index and that is the plan behind the twenty-four-minute outage this codebase already
 * has a comment about. Here: the small side first (this user's hashes, bounded by the limit), then
 * one literal IN() per chunk for the metadata.
 *
 * A favourite OUTLIVES the catalogue row. The janitor prunes index_hashes, and deleting favourites
 * along with it would quietly empty people's lists; a hash on its own is still useful, because
 * magnetFor(hash, name) builds a working magnet from nothing else. A row with no catalogue entry
 * comes back with name === null and the page says so.
 */
function favRowsFor(PDO $db, array $hashes): array {
    $out = [];
    foreach ($hashes as $h) $out[$h] = ['info_hash' => $h, 'name' => null, 'total_size' => null,
                                        'files_count' => null, 'seeders' => null, 'leechers' => null,
                                        'last_seen' => null, 'src' => null, 'banned' => false];
    foreach (array_chunk($hashes, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT info_hash, name, total_size, files_count,
                                   COALESCE(scrape_seeders, last_seeders) AS seeders,
                                   COALESCE(scrape_leechers, last_leechers) AS leechers, last_seen
                              FROM index_hashes WHERE info_hash IN ($in)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['info_hash']] = $r + ['src' => 'index', 'banned' => false];
        }
        // The whitelist arm second, and it wins: a whitelisted hash is deleted out of index_hashes,
        // so where both exist the whitelist row is the newer truth. `banned` travels with the row —
        // a banned hash still renders, without a magnet, because pretending it is gone would leave
        // the reader with a favourite they can neither see nor remove knowingly.
        $st = $db->prepare("SELECT info_hash, name, total_size, files_count,
                                   COALESCE(scrape_seeders, 0) AS seeders, COALESCE(scrape_leechers, 0) AS leechers,
                                   COALESCE(scraped_at, updated_at, created_at) AS last_seen, banned
                              FROM whitelist WHERE info_hash IN ($in)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $banned = (int)$r['banned'] === 1;
            unset($r['banned']);
            $out[$r['info_hash']] = $r + ['src' => 'whitelist', 'banned' => $banned];
        }
    }
    return array_values($out);
}

/**
 * The group ids that hold one permission, as SQL can use them.
 *
 * Permissions live as JSON on a handful of group rows, so the decision cannot be made in SQL — but
 * it MUST be made there. Filtering in PHP after the LIMIT would make `total` a lie, and paginating
 * a lie is a leak: page two would show rows page one's count did not admit to. One cheap query,
 * decoded in PHP, and the answer goes into the WHERE as a literal list of integers.
 */
function userGroupIdsWithPermission(PDO $db, string $perm): array {
    $ids = [];
    try {
        foreach ($db->query("SELECT id, slug, permissions FROM user_groups")->fetchAll(PDO::FETCH_ASSOC) as $g) {
            // The system `admin` group answers yes to everything, whatever its JSON happens to list.
            // userEffectivePermissions() has said so since accounts existed — "membership in the
            // system admin group grants every registered permission" — and its stored permissions are
            // a much shorter list, written when the group was seeded and never extended since. A
            // helper that read only the JSON therefore disagreed with the function it exists to
            // mirror, in SQL, where nothing could notice: an administrator who had ticked both
            // privacy boxes was missing from "who has this in favourites" AND from its count, on
            // their own tracker, with no way to tell that from the feature being broken.
            if ((string)($g['slug'] ?? '') === 'admin') { $ids[] = (int)$g['id']; continue; }
            $p = json_decode((string)$g['permissions'], true);
            if (is_array($p) && !empty($p[$perm])) $ids[] = (int)$g['id'];
        }
    } catch (\Throwable $e) { return []; }
    return $ids;
}

/**
 * The `admin` group ids — the ones whose members pass every check in PHP.
 *
 * Needed wherever a decision is made in SQL and has to agree with userEffectivePermissions(),
 * including its last clause: an administrator is exempt from the e-mail verification gate too.
 */
function userAdminGroupIds(PDO $db): array {
    try {
        return array_map('intval', $db->query("SELECT id FROM user_groups WHERE slug = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
    } catch (\Throwable $e) { return []; }
}

/**
 * ONE display status for a whitelist row, computed from what is already there.
 *
 * In this order, and exactly one answer:
 *   blocked — banned = 1, the tracker refuses it
 *   refused — the metadata probe failed, so the accesslist generator leaves it out
 *   waiting — the probe is still running
 *   live    — everything else, which is what the tracker serves
 *
 * `live` IS NOT "approved". Nobody approved it: either the operator's policy demanded a probe and it
 * passed, or the policy never asked. Calling it "approved" teaches a member that a person looked,
 * which is untrue and is exactly what they will believe at the first problem. The state of the
 * DESCRIPTION is a different claim and stays in `content_status`, where the words approved, pending
 * and rejected honestly belong.
 */
function whitelistDisplayStatus(array $row, array $cfg): string {
    if ((int)($row['banned'] ?? 0) === 1) return 'blocked';
    $probe = (string)($row['probe_status'] ?? '');
    if ($probe === 'failed') return 'refused';
    if ($probe === 'probing') return 'waiting';
    return 'live';
}

/**
 * Everything the account/profile pages need to know about one reader's favourites feature.
 * One call, so no page has to reassemble the gates and get one of them wrong.
 */
function favContext(PDO $db, array $cfg, ?array $viewer): array {
    $on = favEnabled($cfg);
    return [
        'enabled'      => $on,
        'may_use'      => $on && $viewer !== null && userCan($db, $cfg, 'favourites.use'),
        'public_ok'    => favPublicEnabled($cfg),
        'may_publish'  => favPublicEnabled($cfg) && $viewer !== null && userCan($db, $cfg, 'favourites.public'),
        'who_ok'       => favWhoEnabled($cfg),
        'may_view'     => $viewer !== null && userCan($db, $cfg, 'favourites.view_others'),
        'profiles'     => profilesEnabled($cfg),
        'uploads'      => uploadsPossible($cfg),
        'uploads_pub'  => uploadsPublicEnabled($cfg) && $viewer !== null && userCan($db, $cfg, 'uploads.public'),
        'max'          => favMaxPerUser($cfg),
    ];
}
