<?php
/**
 * The status page's "what does this tracker know about this hash?" (includes/hashcheck.php):
 *   php tests/hash_check_test.php
 *
 * Runs against the local test database (deploy/local_bootstrap.php first). Six fixture hashes, one
 * per state the answer can be in, inserted and removed here; and the registry side of the feature:
 * the permission exists, the member preset carries it, the migration granted it, the limit has a
 * default. The endpoint's gate and rate limit are tests/hash_check_test.py (they need HTTP).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/hashcheck.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$cfg = getSettings($db, true);

// ── the registry side ────────────────────────────────────────────────────────
check('the permission exists in the registry', isset(userPermissionList()['status.hash_check']));
check('the member preset carries it', in_array('status.hash_check', userGroupPresets()['member']['perms'], true));
check('the migration recorded its grant', isset($cfg['schema_grant_v57_hash_check']));
// The grant is a one-time migration and this is a shared database other suites edit (users_test
// resets the system groups to their seed for its own checks). Ask the migration itself rather than
// the leftovers: forget the marker, run the data migrations again — idempotent by design — and read
// what they wrote.
$db->exec("DELETE FROM settings WHERE `key` = 'schema_grant_v57_hash_check'");
trackerSchemaDataMigrations($db, getSettings($db, true));
$cfg = getSettings($db, true);
check('… and records it again when asked to', isset($cfg['schema_grant_v57_hash_check']));
$st = $db->prepare("SELECT permissions FROM user_groups WHERE slug = 'member'");
$st->execute();
$memberPerms = json_decode((string)$st->fetchColumn(), true) ?: [];
check('… and the member group has it on this (migrated) database', ($memberPerms['status.hash_check'] ?? null) === true, json_encode($memberPerms));
$st = $db->prepare("SELECT permissions FROM user_groups WHERE slug = 'guest'");
$st->execute();
$guestPerms = json_decode((string)$st->fetchColumn(), true) ?: [];
check('… and the guest group does not', empty($guestPerms['status.hash_check']), json_encode($guestPerms));
check('the limit ships with a default', (trackerSchemaDefaultSettings()['rate_limit_hash_check'] ?? null) === '120');
check('with accounts switched off the legacy fallback opens it (like content.*), not closes it', userLegacyDefault('status.hash_check') === true);

// ── fixtures: one hash per state ─────────────────────────────────────────────
$H = fn(string $tag) => str_repeat($tag, 10);   // 4 hex chars × 10 = 40
$live = $H('1c01'); $review = $H('1c02'); $probing = $H('1c03'); $rejected = $H('1c04');
$failed = $H('1c05'); $wlBanned = $H('1c06'); $seenOnly = $H('1c07'); $bannedOnly = $H('1c08');
$both = $H('1c09'); $unknown = $H('1c0a');
$all = [$live, $review, $probing, $rejected, $failed, $wlBanned, $seenOnly, $bannedOnly, $both, $unknown];
$clean = function () use ($db, $all) {
    $ph = implode(',', array_fill(0, count($all), '?'));
    foreach (['whitelist', 'index_hashes', 'index_files', 'banned_hashes'] as $t) {
        $db->prepare("DELETE FROM `$t` WHERE info_hash IN ($ph)")->execute($all);
    }
};
$clean();
$wl = $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned, review_status, probe_status, content_status, meta_status, files_count)
                    VALUES (?, ?, 'admin', '2026-09-01 10:00:00', ?, ?, ?, ?, ?, ?)");
$wl->execute([$live,     'Live fixture',     0, 'none',     'passed',  'approved', 'done',   3]);
$wl->execute([$review,   'Review fixture',   0, 'pending',  'none',    'none',     'none',   null]);
$wl->execute([$probing,  'Probing fixture',  0, 'none',     'probing', 'none',     'none',   null]);
$wl->execute([$rejected, 'Rejected fixture', 0, 'rejected', 'none',    'none',     'none',   null]);
$wl->execute([$failed,   'Failed fixture',   0, 'none',     'failed',  'none',     'failed', null]);
$wl->execute([$wlBanned, 'Banned fixture',   1, 'none',     'passed',  'none',     'done',   1]);
$wl->execute([$both,     'Both fixture',     0, 'none',     'passed',  'none',     'pending', 9000]);
$ix = $db->prepare("INSERT INTO index_hashes (info_hash, name, first_seen, last_seen, seen_count, last_seeders, last_leechers, meta_status, files_count)
                    VALUES (?, ?, '2026-08-01 08:00:00', '2026-09-10 20:30:00', ?, ?, ?, ?, ?)");
$ix->execute([$seenOnly, 'Seen fixture', 17, 120, 4, 'done', 2]);
$ix->execute([$both,     'Both fixture (index)', 3, 5, 1, 'done', 9000]);
$if = $db->prepare("INSERT INTO index_files (info_hash, path, size) VALUES (?, ?, ?)");
$if->execute([$seenOnly, 'a/one.bin', 10]); $if->execute([$seenOnly, 'a/two.bin', 20]);
foreach (range(1, 5) as $k) $if->execute([$both, "part-$k.bin", 100]);
$db->prepare("INSERT INTO banned_hashes (info_hash, reason, source, created_at) VALUES (?, 'fixture', 'admin', '2026-09-05 12:00:00')")->execute([$bannedOnly]);
$db->prepare("INSERT INTO banned_hashes (info_hash, reason, source, created_at) VALUES (?, 'fixture', 'admin', '2026-09-06 12:00:00')")->execute([$wlBanned]);

try {
    $r = hashCheckLookup($db, $cfg, strtoupper($live));
    check('the hash comes back lower-case whatever was given', $r['hash'] === $live, $r['hash']);
    check('a live whitelist row: registered, state live, since its date', ($r['registered']['state'] ?? '') === 'live' && ($r['registered']['since'] ?? '') === '2026-09-01 10:00:00', json_encode($r['registered']));
    check('… carries the published description flag and the name', ($r['registered']['content'] ?? '') === 'approved' && ($r['registered']['name'] ?? '') === 'Live fixture');
    check('… is not banned, never seen, known', $r['banned'] === null && $r['seen'] === null && $r['known'] === true, json_encode($r));
    check('… metadata from the whitelist row, files: 0 stored of 3', $r['meta']['status'] === 'done' && $r['meta']['source'] === 'whitelist'
          && $r['files']['fetched'] === 0 && $r['files']['total'] === 3, json_encode($r['files']));

    check('a row waiting for review says so', hashCheckLookup($db, $cfg, $review)['registered']['state'] === 'review');
    check('a row waiting for its first peer says so', hashCheckLookup($db, $cfg, $probing)['registered']['state'] === 'probing');
    check('a rejected submission says so', hashCheckLookup($db, $cfg, $rejected)['registered']['state'] === 'rejected');
    check('a row whose probe found nothing says so', hashCheckLookup($db, $cfg, $failed)['registered']['state'] === 'failed');
    $r = hashCheckLookup($db, $cfg, $wlBanned);
    check('a banned whitelist row is "banned" whatever its probe says, and the ban list agrees',
          $r['registered']['state'] === 'banned' && $r['banned'] !== null && $r['banned']['since'] === '2026-09-06 12:00:00', json_encode($r));

    $r = hashCheckLookup($db, $cfg, $seenOnly);
    check('a hash only the index knows: not registered, seen', $r['registered'] === null && $r['seen'] !== null && $r['known'] === true);
    check('… with when, how often and how big', $r['seen']['first'] === '2026-08-01 08:00:00' && $r['seen']['last'] === '2026-09-10 20:30:00'
          && $r['seen']['times'] === 17 && $r['seen']['seeders'] === 120 && $r['seen']['leechers'] === 4, json_encode($r['seen']));
    check('… metadata from the index, and both file numbers', $r['meta']['source'] === 'index' && $r['meta']['name'] === 'Seen fixture'
          && $r['files']['fetched'] === 2 && $r['files']['total'] === 2, json_encode($r));

    $r = hashCheckLookup($db, $cfg, $bannedOnly);
    check('a hash only the ban list knows: banned, nothing else, known', $r['banned'] !== null && $r['registered'] === null && $r['seen'] === null && $r['known']);

    $r = hashCheckLookup($db, $cfg, $both);
    check('a hash in both tables: the index speaks for the metadata', $r['meta']['source'] === 'index' && $r['meta']['name'] === 'Both fixture (index)', json_encode($r['meta']));
    check('… and the stored file count is the real one, beside the torrent\'s own', $r['files']['fetched'] === 5 && $r['files']['total'] === 9000, json_encode($r['files']));

    $r = hashCheckLookup($db, $cfg, $unknown);
    check('a hash nobody has met: unknown in every column', !$r['known'] && $r['banned'] === null && $r['registered'] === null && $r['seen'] === null
          && $r['meta']['status'] === 'none' && $r['files']['fetched'] === 0 && $r['files']['total'] === null, json_encode($r));
    check('the answer names the mode, so the page can say "blacklisted" where that is the word', in_array($r['mode'], ['whitelist', 'blacklist'], true), $r['mode']);
} finally {
    $clean();
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
