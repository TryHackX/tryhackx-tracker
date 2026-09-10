<?php
/**
 * Lists — collections somebody makes on purpose.
 *
 * ── how this differs from favourites, and why it is its own thing ──────────────────────────────
 *
 * A favourite is one bit about one hash: I like this. A list is a THING somebody made — it has a
 * name they chose, a reason they had, and its own answer to "who may see this". So it is two tables
 * of its own rather than a column on `user_favourites`: un-starring a torrent must never silently
 * empty somebody's pack, and a pack must be able to hold a hash its owner has not starred.
 *
 * ── the three switches, and which one wins ────────────────────────────────────────────────────
 *
 * A list is visible to a stranger only when EVERY one of these says yes:
 *   1. `lists_enabled`         — the operator switched the feature on at all
 *   2. `lists_public_enabled`  — the operator allows any list to be public
 *   3. `lists.public`          — the owner's group is allowed to publish
 *   4. `users.lists_public`    — the owner shows the section on their profile
 *   5. `user_lists.is_public`  — the owner published THIS list
 *
 * The site-wide switch (2) can only ever narrow. Turning it off takes every list back off the
 * public side without editing anybody's row, and their own choice is remembered — it takes effect
 * again when the operator turns it back on. That is the same rule favourites already follow, and
 * the reason the per-list flag is never rewritten by a settings change.
 */

/** The master switch. Off, every endpoint answers 404 and no page draws anything about lists. */
function listsEnabled(array $cfg): bool
{
    return usersEnabled($cfg) && (($cfg['lists_enabled'] ?? '0') === '1');
}

/** May any list be public at all? Site-wide, and it only narrows what a reader chose. */
function listsPublicEnabled(array $cfg): bool
{
    return listsEnabled($cfg) && (($cfg['lists_public_enabled'] ?? '0') === '1');
}

/** How many lists one account may keep. Clamped, so a hand-edited settings row cannot uncap it. */
function listsMaxPerUser(array $cfg): int
{
    return max(1, min(200, (int)($cfg['lists_max_per_user'] ?? 20) ?: 20));
}

/** How many torrents may sit in one list. Same reasoning as fav_max_per_user. */
function listsMaxItems(array $cfg): int
{
    return max(10, min(5000, (int)($cfg['lists_max_items'] ?? 500) ?: 500));
}

/**
 * A URL-safe name for a list, derived once and then LEFT ALONE.
 *
 * The slug is what a link somebody sent points at, so renaming a list must not break it. This runs
 * when a list is created and never again; two lists of one person cannot share one, which is what
 * the unique key enforces and what the numeric suffix below is for.
 */
function listSlugify(string $name): string
{
    $s = function_exists('transliterator_transliterate')
        ? (string)transliterator_transliterate('Any-Latin; Latin-ASCII', $name)
        : $name;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    $s = mb_substr($s, 0, 60);
    return $s === '' ? 'list' : $s;
}

/** The slug this person may actually use, with a number on the end if the plain one is taken. */
function listUniqueSlug(PDO $db, int $userId, string $name, int $exceptId = 0): string
{
    $base = listSlugify($name);
    $st = $db->prepare("SELECT 1 FROM user_lists WHERE user_id = ? AND slug = ? AND id <> ? LIMIT 1");
    for ($i = 0; $i < 50; $i++) {
        $try = $i === 0 ? $base : ($base . '-' . ($i + 1));
        $st->execute([$userId, $try, $exceptId]);
        if (!$st->fetchColumn()) return $try;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

/**
 * Everything a page needs to know about one reader and the lists feature, in one call.
 *
 * Mirrors favContext() deliberately: a page that has to reassemble five gates is a page that will
 * get one of them wrong, and the two features answer the same shape of question.
 */
function listsContext(PDO $db, array $cfg, ?array $viewer): array
{
    $on = listsEnabled($cfg);
    $mayUse = $on && $viewer !== null && userCan($db, $cfg, 'lists.use');
    $uid = $viewer !== null ? (int)$viewer['id'] : 0;
    // The GRANT, for the reason favContext() gives: publishing is consent, and every page that reads
    // a published list asks the grant. A control that asks a different question is a control that
    // saves an answer nothing acts on.
    $grant = $uid > 0 && userIdHasGrantedPermission($db, $cfg, $uid, 'lists.public');
    return [
        'enabled'     => $on,
        'public_ok'   => listsPublicEnabled($cfg),
        'may_use'     => $mayUse,
        // May THIS reader mark a list public — the site switch and their group's grant.
        'may_publish' => $mayUse && listsPublicEnabled($cfg) && $grant,
        'publish_blocked' => $mayUse && listsPublicEnabled($cfg) && !$grant,
        // May they read somebody else's? The same permission that opens profiles and favourites:
        // one decision about whether this install shows people to each other at all.
        'may_view'    => $on && $viewer !== null && userCan($db, $cfg, 'favourites.view_others'),
        'max_lists'   => listsMaxPerUser($cfg),
        'max_items'   => listsMaxItems($cfg),
    ];
}

/**
 * Are somebody ELSE's lists visible on their profile?
 *
 * userIdHasGrantedPermission(), not userIdHasPermission(): showing a collection to strangers is
 * consent, and the administrator's blanket is authority — see includes/favourites.php for the whole
 * argument. This is the same rule, applied to the same kind of question.
 */
function listsVisibleFor(PDO $db, array $cfg, array $owner): bool
{
    return listsPublicEnabled($cfg)
        && (int)($owner['lists_public'] ?? 0) === 1
        && userIdHasGrantedPermission($db, $cfg, (int)$owner['id'], 'lists.public');
}

/**
 * Does this tracker know this hash at all, and what does it call it?
 *
 * "Known" is deliberately weaker than "resolved": a registered whitelist row whose metadata the
 * worker has not fetched yet is a torrent this tracker has, and refusing it would mean somebody can
 * only collect a torrent after a background job catches up with it. What it excludes is forty hex
 * characters nobody here has ever seen — which is not a torrent, it is a string.
 */
function listHashKnown(PDO $db, string $hash): array
{
    $hash = strtolower(trim($hash));
    $out = ['known' => false, 'name' => null, 'source' => null];
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return $out;
    try {
        // The whitelist first, and it wins: a registered hash is deleted out of index_hashes, so
        // where both exist the whitelist row is the newer truth (the same order favRowsFor uses).
        $st = $db->prepare("SELECT name FROM whitelist WHERE info_hash = ? LIMIT 1");
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return ['known' => true, 'name' => $row['name'] ?: null, 'source' => 'whitelist'];
        $st = $db->prepare("SELECT name FROM index_hashes WHERE info_hash = ? LIMIT 1");
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return ['known' => true, 'name' => $row['name'] ?: null, 'source' => 'index'];
    } catch (Throwable $e) { /* a table this install does not have is not a failure */ }
    return $out;
}

/** How many torrents are in one list. One statement, so the ceiling and the reply agree. */
function listItemCount(PDO $db, int $listId): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM user_list_items WHERE list_id = ?");
    $st->execute([$listId]);
    return (int)$st->fetchColumn();
}

/**
 * One list's rows, with whatever the catalogue currently knows about each hash.
 *
 * The stored `name` is the fallback, not the truth: a hash the catalogue still holds is described
 * by the catalogue (it may have been resolved since), and one it has pruned is described by the
 * name that was recorded when the row was added. A list of forty-hex strings is unreadable to the
 * person who made it, which is the failure this avoids.
 */
function listItemsWithMeta(PDO $db, array $rows): array
{
    if (!$rows) return [];
    $hashes = array_values(array_unique(array_map(static fn($r) => (string)$r['info_hash'], $rows)));
    // favRowsFor() is the one place that knows how to describe a hash from the catalogue and the
    // whitelist together, with the whitelist winning. A second copy of that reasoning here would be
    // a second thing to keep in step.
    $meta = favRowsFor($db, $hashes);
    $byHash = [];
    foreach ($meta as $m) {
        $h = strtolower((string)($m['info_hash'] ?? ''));
        if ($h !== '' && ($m['name'] !== null || !empty($m['src']))) $byHash[$h] = $m;
    }
    $out = [];
    foreach ($rows as $r) {
        $h = strtolower((string)$r['info_hash']);
        $m = $byHash[$h] ?? [];
        $out[] = [
            'info_hash'  => $h,
            'name'       => $m['name'] ?? ($r['name'] ?? null),
            'total_size' => $m['total_size'] ?? null,
            'seeders'    => $m['seeders'] ?? null,
            'leechers'   => $m['leechers'] ?? null,
            'banned'     => (bool)($m['banned'] ?? false),
            'added_at'   => $r['added_at'] ?? null,
            // true when the catalogue has nothing: the row still works as a magnet, and the page
            // says where its name came from rather than pretending the catalogue knows it.
            'gone'       => !isset($byHash[$h]),
        ];
    }
    return $out;
}

/**
 * The PUBLIC lists a hash appears on — "who has this", for collections.
 *
 * Every gate the profile applies is applied here too, in SQL, for the reason
 * api/hash_favourites.php gives at length: filtering after the LIMIT makes the total a lie. A list
 * whose owner has since taken their section down, or whose group lost the permission, is not here.
 */
function listsContainingHash(PDO $db, array $cfg, string $hash, int $limit = 20): array
{
    if (!listsPublicEnabled($cfg)) return [];
    $groupIds = userGroupIdsWithPermission($db, 'lists.public');
    if (!$groupIds) return [];
    $in = implode(',', array_map('intval', $groupIds));
    $sql = "SELECT l.id, l.name, l.slug, u.username,
                   (SELECT COUNT(*) FROM user_list_items x WHERE x.list_id = l.id) AS items
              FROM user_list_items i
              JOIN user_lists l ON l.id = i.list_id
              JOIN users u ON u.id = l.user_id
             WHERE i.info_hash = ? AND l.is_public = 1 AND u.lists_public = 1 AND u.status = 'active'
               AND EXISTS (SELECT 1 FROM user_group_members m WHERE m.user_id = u.id AND m.group_id IN ($in)
                             AND (m.expires_at IS NULL OR m.expires_at > NOW()))";
    if (userEmailVerifyRequired($cfg)) $sql .= " AND u.email_verified = 1";
    $sql .= " ORDER BY l.updated_at DESC LIMIT " . max(1, min(100, $limit));
    try {
        $st = $db->prepare($sql);
        $st->execute([strtolower($hash)]);
        return array_map(static fn($r) => [
            'name' => (string)$r['name'], 'slug' => (string)$r['slug'],
            'username' => (string)$r['username'], 'items' => (int)$r['items'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (\Throwable $e) { return []; }
}
