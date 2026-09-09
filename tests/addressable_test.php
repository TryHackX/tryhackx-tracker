<?php
/**
 * Tests for the addressable views added in 1.40.0 (needs the local test database):
 *   php tests/addressable_test.php
 *
 * Two features share one idea: a view somebody is looking at should have an address. The public
 * search page puts its state in the query string; the panel's two detail modals answer to
 * `?hash=<40 hex>`. This file covers the SERVER half of that — the endpoint that a shared link
 * lands on — plus the two settings that came with it.
 *
 * The browser half (history entries, the Share buttons, popstate) is driven by a real browser in
 * the scratchpad harness; nothing here pretends to cover it.
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

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$HASH = str_repeat('7e', 20);

/* ── 1. a link names the torrent, so the endpoint has to answer to the hash ───
 *
 * THE BUG THIS EXISTS FOR: api/admin/whitelist_item.php grew a `hash=` branch beside `id=`, but the
 * file-list query one screen below still bound the REQUEST's id — which on the hash path is the 0
 * that (int)($_GET['id'] ?? 0) produced, because that branch is entered precisely when id < 1. So
 * `WHERE whitelist_id = 0` matched nothing and every hash-addressed modal showed an empty list under
 * a stat strip saying "2 files", with the sentence "Single-file torrent or no file list stored".
 *
 * This checks the property directly against the database rather than by reading the endpoint: the
 * two lookups must return the same row AND the same files. A grep for "$row['id']" would have gone
 * green the moment somebody wrote that string anywhere in the file. */
$db->prepare('DELETE FROM whitelist WHERE info_hash = ?')->execute([$HASH]);
$db->prepare("INSERT INTO whitelist (info_hash, name, total_size, files_count, source, created_at, banned, content_status)
              VALUES (?, 'Addressable fixture', 4242, 2, 'admin', NOW(), 0, 'none')")->execute([$HASH]);
$wlId = (int)$db->lastInsertId();
$fi = $db->prepare('INSERT INTO whitelist_files (whitelist_id, path, size) VALUES (?, ?, ?)');
$fi->execute([$wlId, 'fixture/one.bin', 100]);
$fi->execute([$wlId, 'fixture/two.bin', 200]);

$byId = $db->prepare('SELECT * FROM whitelist WHERE id = ?');
$byId->execute([$wlId]);
$rowById = $byId->fetch();
$byHash = $db->prepare('SELECT * FROM whitelist WHERE info_hash = ? LIMIT 1');
$byHash->execute([$HASH]);
$rowByHash = $byHash->fetch();
check('the same row is reachable by id and by hash', $rowById && $rowByHash && (int)$rowById['id'] === (int)$rowByHash['id']);

// And the FILES have to follow the row, which is the half that was actually broken: a lookup that
// finds the right row and then reads files for a different id is exactly what shipped. Both lists
// are read the way the endpoint reads them, and both must be the same two paths.
$filesOf = function ($id) use ($db): array {
    $st = $db->prepare('SELECT path FROM whitelist_files WHERE whitelist_id = ? ORDER BY id');
    $st->execute([(int)$id]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$viaId   = $filesOf($rowById['id'] ?? 0);
$viaHash = $filesOf($rowByHash['id'] ?? 0);
check('… and the file list follows the row, whichever way it was found',
    count($viaId) === 2 && $viaId === $viaHash, implode(',', $viaId) . ' | ' . implode(',', $viaHash));
check('… while the id the request did NOT send finds nothing, which is what the bug bound',
    $filesOf(0) === [], implode(',', $filesOf(0)));

// The endpoint's own source, read for ONE property: the files query is keyed off the row that was
// found, never off the request. Anchored to the query it is about, so it cannot go green by
// accident once that query is deleted.
$src = (string)@file_get_contents($root . '/api/admin/whitelist_item.php');
$pos = strpos($src, 'FROM whitelist_files WHERE whitelist_id');
check('whitelist_item.php still has a file-list query to check', $pos !== false);
$after = $pos === false ? '' : substr($src, $pos, 900);
check('… and it binds the id of the row that was FOUND, not the one that was asked for',
    str_contains($after, "(int)\$row['id']") && !preg_match('/bindValue\(1,\s*\$id\s*,/', $after),
    substr($after, 0, 200));

/* ── 2. one hash, three endpoints, one answer ────────────────────────────────
 *
 * A whitelist-only hash is either visible to this reader or it is not, and the search, the file list
 * and the Info panel have to agree. api/index_info.php disagreed: it gated only the `whitelisted`
 * flag and served the whole row to anyone who could type the hash. The permission side is proven
 * over HTTP with a real user session in deploy/smoke_users.py — userCan() returns true for any panel
 * session, so nothing in a CLI test could prove it. What IS provable here is the OTHER half of the
 * rule, which needs no session at all: a banned row is invisible to everybody. */
$db->prepare('UPDATE whitelist SET banned = 1 WHERE info_hash = ?')->execute([$HASH]);
$infoSrc = (string)@file_get_contents($root . '/api/index_info.php');
check('index_info loads whitelist rows with the same banned filter as the file list',
    (bool)preg_match('/FROM whitelist WHERE info_hash = \?\s+AND banned = 0/s', $infoSrc));
$filesSrc = (string)@file_get_contents($root . '/api/index_files.php');
check('… which is the filter api/index_files.php uses', str_contains($filesSrc, 'AND banned = 0'));

// Behaviour, not grep: the catalogue must not list it either.
$res = indexSearchCatalogue($db, getSettings($db, true) + ['index_search_include_whitelist' => '1'],
    ['search' => 'Addressable fixture', 'include_whitelist' => true, 'per_page' => 25, 'page' => 1]);
$names = array_column($res['rows'] ?? [], 'name');
check('a banned row is not in the search results', !in_array('Addressable fixture', $names, true), implode(', ', $names));

$db->prepare('UPDATE whitelist SET banned = 0 WHERE info_hash = ?')->execute([$HASH]);
$res = indexSearchCatalogue($db, getSettings($db, true) + ['index_search_include_whitelist' => '1'],
    ['search' => 'Addressable fixture', 'include_whitelist' => true, 'per_page' => 25, 'page' => 1]);
$names = array_column($res['rows'] ?? [], 'name');
check('… and un-banning puts it back, so the test above is about `banned` and not about the fixture',
    in_array('Addressable fixture', $names, true), implode(', ', $names));

/* ── 3. the refresh arm is behind the same gate as the read ──────────────────
 * It used to sit ABOVE the row lookup, so it never asked the question at all: 200 with live swarm
 * counts for a hash whose GET answered 404, and an UPDATE on a row the caller could not read. */
$postAt = strpos($infoSrc, "REQUEST_METHOD'] === 'POST'");
$gateAt = strpos($infoSrc, "\$canWl = userCan(");
check('the whitelist gate is computed BEFORE the refresh branch, not after it',
    $gateAt !== false && $postAt !== false && $gateAt < $postAt, "gate@$gateAt post@$postAt");
check('… and the refresh writes the whitelist row only when the caller may see it',
    (bool)preg_match('/if \(\$wl\) \{\s*\$db->prepare\("UPDATE whitelist SET scrape_seeders/s', $infoSrc));

/* ── 4. the two new settings, in all four places ─────────────────────────────
 * tests/admin_access_test.php already proves the catalogue and the page agree with each other; what
 * it cannot see is the schema default and the save allow-list, which is where a setting silently
 * becomes unsavable. */
$schemaSrc  = (string)@file_get_contents($root . '/includes/schema.php');
$catalogSrc = (string)@file_get_contents($root . '/includes/settings_catalog.php');
$saveSrc    = (string)@file_get_contents($root . '/api/admin/save_settings.php');
$setTpl     = (string)@file_get_contents($root . '/templates/admin/settings.php');
foreach (['search_share_enabled' => '1', 'lang_swap_enabled' => '0'] as $key => $default) {
    check("$key has a schema default", str_contains($schemaSrc, "'$key'"));
    check("$key ships as '$default'", (trackerSchemaDefaultSettings()[$key] ?? null) === $default,
        var_export(trackerSchemaDefaultSettings()[$key] ?? null, true));
    check("$key is in the search catalogue", str_contains($catalogSrc, "'$key'"));
    check("$key is in the save allow-list", str_contains($saveSrc, "'$key'"));
    check("$key has a control on the Settings page", str_contains($setTpl, 'name="' . $key . '"'));
}

// The Share buttons are the only thing search_share_enabled turns off. The state is in the address
// whichever way it is set — say so out loud, because "off" reading as "not addressable" is exactly
// the misunderstanding that would make somebody remove the URL writing along with the buttons.
$searchTpl = (string)@file_get_contents($root . '/templates/pages/search.php');
check('the Share buttons are behind search_share_enabled', substr_count($searchTpl, '$canShare') >= 3, (string)substr_count($searchTpl, '$canShare'));
// Every `if ($canShare)` block, and nothing but the buttons inside them: the switch must not be
// able to take the search itself away.
preg_match_all('/<\?php if \(\$canShare\): \?>(.*?)<\?php endif; \?>/s', $searchTpl, $blocks);
$inside = implode("
", $blocks[1] ?? []);
check('… and every block it guards holds only a Share button',
    $inside !== '' && !preg_match('/search-(input|table|form|body|pagination)/', $inside), $inside);

/* ── 5. the fallback link box can actually be hidden ─────────────────────────
 * .share-url carries its own `display`, and an author-origin display beats the user agent's
 * `[hidden] { display: none }`. Without the explicit rule, `box.hidden = true` is a statement that
 * does nothing and a pre-selected link to a page the reader has left stays on screen. */
$css = (string)@file_get_contents($root . '/assets/css/style.css');
check('.share-url spells out its [hidden] rule, like every other class here that sets a display',
    (bool)preg_match('/\.share-url\[hidden\]\s*\{\s*display:\s*none/', $css));

$db->prepare('DELETE FROM whitelist WHERE info_hash = ?')->execute([$HASH]);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
