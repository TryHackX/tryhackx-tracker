<?php
/**
 * Descriptions in both homes (includes/content.php):
 *   php tests/content_test.php
 *
 * Against the local test database. Two accounts, a registered torrent, a torrent the tracker has only
 * seen, an unknown hash, a banned one — and every path the words can take: attached, queued,
 * published, proposed, applied, rejected, cleared; who is recorded as the author, what the public
 * reader gets, what the moderator gets, and who is told what. The registry side too: the permission,
 * the preset, the grant, the schema.
 *
 * 1.70.0 (schema 81), the second half of this file: a description can be DELETED (its author's own,
 * or anybody's published one by a moderator — refused otherwise), the author can HIDE their name
 * (every place that credits them says "a member"), an EDIT keeps the author and credits the editor
 * with the share of the text it changed (the formula on known texts), the credit CHAIN (started,
 * appended, merged, reset by a rewrite, capped, tombstoned when an account goes), the Edit prefill,
 * the list of descriptions a member wrote (the gate one flag at a time, the rows, the order, the
 * page), the schema on both paths, and the settings and permissions in their places — with the
 * endpoints run as requests in a child process. Self-cleaning: its accounts (cte_*), groups, rows
 * (c0e*), audit lines, the member / moderator / admin groups' JSON and the v81 marker are put back.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/content.php';
require_once $root . '/includes/hashcheck.php';
// 1.70.0: what the second half needs — the grant reader, the block, the list's excerpt, the pictures'
// addresses, the audit log, the Settings search words, and the list of descriptions itself.
require_once $root . '/includes/favourites.php';
require_once $root . '/includes/reputation.php';
require_once $root . '/includes/people.php';
require_once $root . '/includes/lists.php';
require_once $root . '/includes/usermedia.php';
require_once $root . '/includes/audit.php';
require_once $root . '/includes/settings_catalog.php';
require_once $root . '/includes/profiledescs.php';

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

// ── registry and schema ──────────────────────────────────────────────────────
check('content.view is in the registry', isset(userPermissionList()['content.view']));
check('the member preset carries it', in_array('content.view', userGroupPresets()['member']['perms'], true));
check('the migration recorded its grant', isset($cfg['schema_grant_v58_content_view']));
check('with accounts off the legacy fallback opens it, like the other content.* ids', userLegacyDefault('content.view') === true);
check('hash_content exists', (bool)$db->query("SHOW TABLES LIKE 'hash_content'")->fetchColumn());
check('whitelist.content_user_id exists', schemaColumnExists($db, 'whitelist', 'content_user_id'));
check('wl_content_edits.hash_content_id exists', schemaColumnExists($db, 'wl_content_edits', 'hash_content_id'));
$col = $db->query("SHOW COLUMNS FROM wl_content_edits LIKE 'whitelist_id'")->fetch(PDO::FETCH_ASSOC);
check('… and whitelist_id may be NULL there now', strtoupper((string)($col['Null'] ?? '')) === 'YES', json_encode($col));

// ── fixtures ─────────────────────────────────────────────────────────────────
$H = fn(string $tag) => str_repeat($tag, 10);
$REG = $H('c0a1'); $SEEN = $H('c0a2'); $NONE = $H('c0a3'); $BANNED = $H('c0a4');
$FMT = $H('c0a5'); $NOROW = $H('c0a6');
$all = [$REG, $SEEN, $NONE, $BANNED, $FMT, $NOROW];
$ph = implode(',', array_fill(0, count($all), '?'));
$clean = function () use ($db, $all, $ph) {
    foreach (['whitelist', 'index_hashes', 'hash_content', 'wl_content_edits', 'banned_hashes'] as $t) {
        $db->prepare("DELETE FROM `$t` WHERE info_hash IN ($ph)")->execute($all);
    }
    $db->prepare("DELETE FROM user_notifications WHERE user_id IN (SELECT id FROM users WHERE username IN ('ctalice','ctbob','ctcarol'))")->execute();
    $db->prepare("DELETE FROM users WHERE username IN ('ctalice','ctbob','ctcarol')")->execute();
};
$clean();
$saved = [];
foreach (['wl_allow_description', 'wl_allow_source_url', 'wl_content_review', 'wl_content_autopublish', 'wl_edit_max_pending', 'users_enabled'] as $k) $saved[$k] = $cfg[$k] ?? null;
$put = function (array $kv) use ($db, &$cfg) {
    foreach ($kv as $k => $v) setSetting($db, $k, $v);
    $cfg = getSettings($db, true);
};
$put(['wl_allow_description' => '1', 'wl_allow_source_url' => '1', 'wl_content_review' => '1', 'wl_content_autopublish' => '0', 'wl_edit_max_pending' => '3', 'users_enabled' => '1']);
// the member group must hold the three content permissions for the two accounts below
$st = $db->prepare("SELECT permissions FROM user_groups WHERE slug = 'member'");
$st->execute();
$memberBefore = (string)$st->fetchColumn();
$mp = json_decode($memberBefore, true) ?: [];
foreach (['content.submit', 'content.propose', 'content.view'] as $p) $mp[$p] = true;
$db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([json_encode($mp)]);

userCreate($db, $cfg, 'ctalice', 'ctalice@example.org', 'SmokePass123!', '127.0.0.1');
userCreate($db, $cfg, 'ctbob', 'ctbob@example.org', 'SmokePass123!', '127.0.0.1');
$db->exec("UPDATE users SET email_verified = 1 WHERE username IN ('ctalice','ctbob','ctcarol')");
$uid = function (string $name) use ($db): array {
    $st = $db->prepare("SELECT id, username FROM users WHERE username = ?"); $st->execute([$name]);
    $r = $st->fetch(PDO::FETCH_ASSOC); return ['id' => (int)$r['id'], 'username' => $r['username']];
};
$alice = $uid('ctalice'); $bob = $uid('ctbob');
$notes = function (int $userId) use ($db): array {
    $st = $db->prepare("SELECT title FROM user_notifications WHERE user_id = ? AND type = 'content' ORDER BY id");
    $st->execute([$userId]); return $st->fetchAll(PDO::FETCH_COLUMN);
};

$db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned, probe_status) VALUES (?, 'Registered one', 'admin', NOW(), 0, 'passed')")->execute([$REG]);
$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Seen one', 'done')")->execute([$SEEN]);
$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Banned one', 'done')")->execute([$BANNED]);
$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'Format one', 'done')")->execute([$FMT]);
$db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, 'No row one', 'done')")->execute([$NOROW]);
$db->prepare("INSERT INTO banned_hashes (info_hash, reason, source) VALUES (?, 'fixture', 'admin')")->execute([$BANNED]);

try {
    // ── the registered torrent: attach, queue, publish, author ─────────────
    $r = contentAttach($db, $cfg, strtoupper($REG), ['description' => 'Alice wrote [b]this[/b].', 'description_format' => 'bbcode', 'source_url' => ''], $alice, '127.0.0.1');
    check('an empty whitelist row takes the words', !empty($r['ok']) && $r['saved'] && $r['pending'] && !$r['proposed'] && $r['kind'] === 'wl', json_encode($r));
    $rec = contentRecordFor($db, $REG);
    check('… queued, with the author recorded on the row', $rec['content_status'] === 'pending' && $rec['content_user_id'] === $alice['id'], json_encode($rec));
    $pub = richtextContentFor($db, $cfg, $REG);
    check('a public reader gets nothing while it waits', $pub['description_html'] === '' && $pub['content_status'] === 'pending' && $pub['kind'] === 'wl', json_encode($pub));
    $adm = richtextContentFor($db, $cfg, $REG, true);
    check('a moderator sees the text and the author', str_contains($adm['description_html'], '<strong>this</strong>') && $adm['author'] === 'ctalice', json_encode($adm));
    check('the digest counts it', contentPendingCount($db) >= 1);

    check('publishing works by kind and id', contentApprove($db, $cfg, 'wl', $rec['id']));
    $pub = richtextContentFor($db, $cfg, $REG);
    check('… and the public reader now gets the words, and who wrote them', str_contains($pub['description_html'], 'this') && $pub['author'] === 'ctalice' && $pub['author_id'] === $alice['id'], json_encode($pub));
    check('… and Alice was told', count($notes($alice['id'])) === 1 && str_contains($notes($alice['id'])[0], 'Registered one'), json_encode($notes($alice['id'])));

    // ── a second person: a proposal, applied, and both authors told ────────
    $r = contentAttach($db, $cfg, $REG, ['description' => 'Bob says otherwise.', 'description_format' => 'bbcode', 'source_url' => ''], $bob, '127.0.0.2');
    check('an occupied row gets a proposal instead', !empty($r['ok']) && $r['proposed'] && !$r['saved'], json_encode($r));
    $st = $db->prepare("SELECT * FROM wl_content_edits WHERE info_hash = ? AND status = 'pending'"); $st->execute([$REG]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    check('… recorded against the whitelist row, by Bob', $e && (int)$e['whitelist_id'] === $rec['id'] && $e['hash_content_id'] === null && (int)$e['user_id'] === $bob['id'], json_encode($e));
    $edit = contentEditById($db, (int)$e['id']);
    check('the edit knows its home', $edit['kind'] === 'wl' && $edit['target_id'] === $rec['id']);
    check('applying it replaces the words', contentEditApply($db, $cfg, $edit));
    $pub = richtextContentFor($db, $cfg, $REG);
    check('… Bob is the author now', str_contains($pub['description_html'], 'Bob says') && $pub['author'] === 'ctbob', json_encode($pub));
    $st = $db->prepare("SELECT status, note, user_id FROM wl_content_edits WHERE info_hash = ? ORDER BY id"); $st->execute([$REG]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    check('… Alice\'s version is kept as a rejected proposal of its own, under her name', count($rows) === 2 && $rows[0]['status'] === 'applied'
          && $rows[1]['status'] === 'rejected' && (int)$rows[1]['user_id'] === $alice['id'], json_encode($rows));
    check('… Bob was told his rewrite went in, Alice that hers was replaced',
          count($notes($bob['id'])) === 1 && count($notes($alice['id'])) === 2, json_encode([$notes($alice['id']), $notes($bob['id'])]));

    // ── the cap on proposals, and rejection ────────────────────────────────
    $put(['wl_edit_max_pending' => '1']);
    $r1 = contentAttach($db, $cfg, $REG, ['description' => 'one', 'description_format' => 'bbcode', 'source_url' => ''], $alice, '127.0.0.1');
    $r2 = contentAttach($db, $cfg, $REG, ['description' => 'two', 'description_format' => 'bbcode', 'source_url' => ''], $alice, '127.0.0.1');
    check('the pending-proposals cap holds', !empty($r1['ok']) && empty($r2['ok']) && $r2['code'] === 429, json_encode($r2));
    $st = $db->prepare("SELECT * FROM wl_content_edits WHERE info_hash = ? AND status = 'pending'"); $st->execute([$REG]);
    $e = contentEditById($db, (int)$st->fetch(PDO::FETCH_ASSOC)['id']);
    check('rejecting a proposal leaves the words alone and tells the proposer', contentEditReject($db, $cfg, $e)
          && str_contains(richtextContentFor($db, $cfg, $REG)['description_html'], 'Bob says') && count($notes($alice['id'])) === 3);
    $put(['wl_edit_max_pending' => '3']);

    // ── a torrent the tracker has only seen: the second home ───────────────
    $r = contentAttach($db, $cfg, $SEEN, ['description' => 'Seen, and described.', 'description_format' => 'bbcode', 'source_url' => 'https://example.org/t/1'], $alice, '127.0.0.1');
    check('an index-only hash takes the words in hash_content', !empty($r['ok']) && $r['saved'] && $r['kind'] === 'idx', json_encode($r));
    $rec = contentRecordFor($db, $SEEN);
    check('… kind idx, queued, authored, named from the index row', $rec['kind'] === 'idx' && $rec['content_status'] === 'pending'
          && $rec['content_user_id'] === $alice['id'] && $rec['name'] === 'Seen one', json_encode($rec));
    $st = $db->prepare("SELECT COUNT(*) FROM whitelist WHERE info_hash = ?"); $st->execute([$SEEN]);
    check('… and NO whitelist row was created: describing is not registering', (int)$st->fetchColumn() === 0);
    // Gates passed explicitly: hashCheckLookup() asks the READER's permissions when they are not, and
    // a CLI run is an anonymous one. Who may be told which section is tests/hash_check_test.php.
    check('the hash check reports the words for it',
          (hashCheckLookup($db, $cfg, $SEEN, ['seen' => true, 'registered' => true, 'content' => true])['content']['status'] ?? '') === 'pending');
    contentReject($db, $cfg, 'idx', $rec['id'], 'too short');
    $rec = contentRecordFor($db, $SEEN);
    check('rejecting by kind idx works, with the note kept', $rec['content_status'] === 'rejected' && $rec['content_rejected_note'] === 'too short', json_encode($rec));
    check('… and the author was told, note included', str_contains(end($notes($alice['id'])) ?: '', 'Seen one'), json_encode($notes($alice['id'])));
    contentApprove($db, $cfg, 'idx', $rec['id']);
    $pub = richtextContentFor($db, $cfg, $SEEN);
    check('published from the second home, the public reader gets text, link and author', str_contains($pub['description_html'], 'described')
          && $pub['source_url'] === 'https://example.org/t/1' && $pub['author'] === 'ctalice' && $pub['kind'] === 'idx', json_encode($pub));
    $r = contentAttach($db, $cfg, $SEEN, ['description' => 'Bob again.', 'description_format' => 'bbcode', 'source_url' => ''], $bob, '127.0.0.2');
    $st = $db->prepare("SELECT whitelist_id, hash_content_id FROM wl_content_edits WHERE info_hash = ? AND status = 'pending'"); $st->execute([$SEEN]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    check('a proposal on an index-only hash points at the hash_content row', !empty($r['proposed']) && $e && $e['whitelist_id'] === null && (int)$e['hash_content_id'] === $rec['id'], json_encode($e));
    check('clearing by kind idx empties it', contentClear($db, 'idx', $rec['id']) && contentRecordFor($db, $SEEN)['content_status'] === 'none');

    // ── what is refused ────────────────────────────────────────────────────
    $r = contentAttach($db, $cfg, $NONE, ['description' => 'Nobody knows this.', 'description_format' => 'bbcode', 'source_url' => ''], $alice, '127.0.0.1');
    check('a hash the tracker has never met cannot be described', empty($r['ok']) && $r['code'] === 404, json_encode($r));
    $r = contentAttach($db, $cfg, $BANNED, ['description' => 'Banned.', 'description_format' => 'bbcode', 'source_url' => ''], $alice, '127.0.0.1');
    check('a banned hash cannot be described', empty($r['ok']) && $r['code'] === 403, json_encode($r));
    $r = contentAttach($db, $cfg, $SEEN, ['description' => '', 'description_format' => 'bbcode', 'source_url' => ''], $alice, '127.0.0.1');
    check('nothing to attach is refused', empty($r['ok']) && $r['code'] === 400, json_encode($r));

    // ── the format is normalised on every path, not only the one with words ──
    // Both homes store it in an ENUM('markdown','bbcode'). A submission carrying only a source link
    // never went past the description branch, so whatever the caller sent was written raw.
    $r = contentAttach($db, $cfg, $FMT, ['description' => '', 'description_format' => 'html; drop table',
                                         'source_url' => 'https://example.org/t/2'], $alice, '127.0.0.1');
    check('a source link alone is attached', !empty($r['ok']) && $r['saved'] && $r['kind'] === 'idx', json_encode($r));
    $rec = contentRecordFor($db, $FMT);
    check('… and its format was forced into the closed set the column allows',
          $rec['description_format'] === richtextFormats($cfg)[0] && in_array($rec['description_format'], ['markdown', 'bbcode'], true),
          json_encode($rec['description_format']));
    // A third account, created AFTER the grant is taken away: userEffectivePermissions() memoises per
    // user for the length of the process, so Alice would still answer from the permissions she had.
    $mp2 = $mp; $mp2['content.submit'] = false;
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([json_encode($mp2)]);
    userCreate($db, $cfg, 'ctcarol', 'ctcarol@example.org', 'SmokePass123!', '127.0.0.1');
    $db->exec("UPDATE users SET email_verified = 1 WHERE username = 'ctcarol'");
    $carol = $uid('ctcarol');
    $r = contentAttach($db, $cfg, $SEEN, ['description' => 'No permission.', 'description_format' => 'bbcode', 'source_url' => ''], $carol, '127.0.0.1');
    check('the permission asked is the submitter\'s own', empty($r['ok']) && $r['code'] === 403, json_encode($r));
    // …and the refusal leaves nothing behind. Carol keeps content.propose, so the old order —
    // create the row, then ask — handed her a 403 AND an empty hash_content row for a hash that had
    // none, which is a record made by somebody who was not allowed to make one (and which turns the
    // next person's "add" into a "propose" against a row that says nothing).
    $r = contentAttach($db, $cfg, $NOROW, ['description' => 'No permission either.', 'description_format' => 'bbcode', 'source_url' => ''], $carol, '127.0.0.1');
    $st = $db->prepare("SELECT COUNT(*) FROM hash_content WHERE info_hash = ?"); $st->execute([$NOROW]);
    check('a refused submitter leaves no hash_content row behind', empty($r['ok']) && $r['code'] === 403 && (int)$st->fetchColumn() === 0, json_encode($r));
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([json_encode($mp)]);
    $put(['wl_allow_description' => '0', 'wl_allow_source_url' => '0']);
    $r = contentAttach($db, $cfg, $SEEN, ['description' => 'Switched off.', 'description_format' => 'bbcode', 'source_url' => ''], $alice, '127.0.0.1');
    check('with both switches off there is nothing to attach', empty($r['ok']) && $r['code'] === 400 && !contentEnabled($cfg), json_encode($r));
    $put(['wl_allow_description' => '1', 'wl_allow_source_url' => '1', 'wl_content_autopublish' => '1']);
    $r = contentAttach($db, $cfg, $SEEN, ['description' => 'Straight out.', 'description_format' => 'bbcode', 'source_url' => ''], $bob, '127.0.0.2');
    check('autopublish publishes at once', !empty($r['ok']) && $r['saved'] && !$r['pending'] && richtextContentFor($db, $cfg, $SEEN)['author'] === 'ctbob', json_encode($r));

    // ── a ban reaches the second home too ──────────────────────────────────
    //
    // The whitelist home has a `banned` column on the row itself, so every reader of it can see the
    // ban. hash_content has nothing of the kind — the ban list is the only witness — and until the
    // audit nobody asked it: contentRecordFor() said banned => false for the idx home outright, so a
    // banned torrent kept its published description and could be given a new one.
    check('the words are public before the ban', str_contains(richtextContentFor($db, $cfg, $SEEN)['description_html'], 'Straight out'));
    $pending = contentAttach($db, $cfg, $SEEN, ['description' => 'A proposal that will not outlive the ban.',
                                                'description_format' => 'bbcode', 'source_url' => ''], $alice, '127.0.0.1');
    check('… and a proposal is waiting on it', !empty($pending['proposed']));
    whitelistBan($db, $cfg, [$SEEN], ['source' => 'admin', 'reason' => 'audit fixture']);
    $rec = contentRecordFor($db, $SEEN);
    check('the record of a banned hash says banned, in the second home as in the first', $rec['banned'] === true, json_encode($rec));
    check('… and whitelistBan took the words out of the queue with the ban', $rec['content_status'] === 'rejected'
          && $rec['content_rejected_note'] === 'the hash was banned', json_encode($rec));
    $pub = richtextContentFor($db, $cfg, $SEEN);
    check('… a public reader gets nothing for it any more', $pub['description_html'] === '' && $pub['source_url'] === null, json_encode($pub));
    check('… a moderator still sees what was written, which is the point of keeping it',
          str_contains(richtextContentFor($db, $cfg, $SEEN, true)['description_html'], 'Straight out'));
    $st = $db->prepare("SELECT COUNT(*) FROM wl_content_edits WHERE info_hash = ? AND status = 'pending'"); $st->execute([$SEEN]);
    check('… and no proposal about it is left waiting for a moderator', (int)$st->fetchColumn() === 0);
    $r = contentAttach($db, $cfg, $SEEN, ['description' => 'After the ban.', 'description_format' => 'bbcode', 'source_url' => ''], $bob, '127.0.0.2');
    check('… nor may new words be attached to it', empty($r['ok']) && $r['code'] === 403, json_encode($r));

    // ── a blank line inside an inline run (includes/richtext.php) ───────────
    // `[b]a\n\nb[/b]` used to render `<p><strong>a</p><p>b</strong></p>`: a <strong> opened in one
    // paragraph and a closer in another that opened nothing. Browsers "repair" that by moving the
    // rest of the document inside the unclosed tag.
    $html = richtextRender("[b]a\n\nb[/b]", 'bbcode', $cfg, false);
    check('a paragraph break inside [b] leaves two balanced paragraphs',
          $html === '<p><strong>a</strong></p><p><strong>b</strong></p>', $html);
    $balanced = function (string $h): bool {
        preg_match_all('#<(/?)([a-z][a-z0-9]*)\b[^>]*>#i', $h, $ms, PREG_SET_ORDER);
        $stack = [];
        foreach ($ms as $m) {
            if (in_array(strtolower($m[2]), ['br', 'img', 'hr'], true)) continue;
            if ($m[1] === '/') { if (array_pop($stack) !== strtolower($m[2])) return false; }
            else $stack[] = strtolower($m[2]);
        }
        return $stack === [];
    };
    foreach (["[i]one\n\ntwo\n\nthree[/i]", "[b][i]x\n\ny[/i][/b]", "[b]a\n\n[center]mid[/center]\n\nb[/b]",
              "[url=https://example.org]a\n\nb[/url]", "[b]a\n\nb[/b] tail"] as $src) {
        check('balanced across the break: ' . str_replace("\n", '\n', $src), $balanced(richtextRender($src, 'bbcode', $cfg, false)),
              richtextRender($src, 'bbcode', $cfg, false));
    }
    check('and the same in markdown', $balanced(richtextRender("**a\n\nb**", 'markdown', $cfg, false)),
          richtextRender("**a\n\nb**", 'markdown', $cfg, false));
} finally {
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([$memberBefore]);
    foreach ($saved as $k => $v) { if ($v !== null) setSetting($db, $k, $v); }
    $clean();
}

/* ══════════════════════════════════════════════════════════════════════════════════════════════════
 * 1.70.0 (schema 81): delete, the author's name, the credit chain, Edit, the list on a profile
 * ══════════════════════════════════════════════════════════════════════════════════════════════════ */
$GLOBALS['db'] = $db;
langInit([], 'en');
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);
$cfgE = array_merge(getSettings($db, true), [
    'users_enabled' => '1', 'users_require_email_verify' => '1', 'profiles_enabled' => '1',
    'profile_descriptions_enabled' => '1', 'wl_allow_description' => '1', 'wl_allow_source_url' => '1',
    'wl_content_review' => '1', 'wl_content_autopublish' => '0', 'wl_edit_max_pending' => '5',
    'index_enabled' => '1', 'index_search_enabled' => '1', 'index_search_include_whitelist' => '1',
    'rep_enabled' => '0', 'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1', 'desc_max_chars' => '4000',
]);
$GLOBALS['cfg'] = $cfgE;
$gpE = fn(string $slug) => (string)$db->query("SELECT permissions FROM user_groups WHERE slug = " . $db->quote($slug))->fetchColumn();
$eBefore = ['member' => $gpE('member'), 'moderator' => $gpE('moderator'), 'admin' => $gpE('admin'),
            'marker' => $db->query("SELECT `value` FROM settings WHERE `key` = 'schema_grant_v81_content'")->fetchColumn(),
            'audit' => (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn()];
$eHash = fn(int $i): string => str_pad('c0e7' . sprintf('%04x', $i), 40, '0');
$eClean = function () use ($db): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'cte\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
    $db->exec("DELETE FROM user_groups WHERE slug LIKE 'cte\\_%'");
    foreach (['whitelist', 'index_hashes', 'hash_content', 'wl_content_edits', 'banned_hashes'] as $t) {
        $db->exec("DELETE FROM `$t` WHERE info_hash LIKE 'c0e%'");
    }
};
$eClean();
$tmpE = [];
/** A verified member, made fresh (userEffectivePermissions() memoises per account for the whole process). */
$eUser = function (string $name) use ($db, $cfgE): int {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id);
    $r = userCreate($db, $cfgE, $name, $name . '@example.org', 'Password123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? 0);
    if ($id > 0) $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?")->execute([$id]);
    return $id;
};
$eOnlyIn = function (int $uid, array $slugs) use ($db): void {
    $db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$uid]);
    foreach ($slugs as $slug) {
        $gid = (int)$db->query("SELECT id FROM user_groups WHERE slug = " . $db->quote($slug))->fetchColumn();
        userGrantGroup($db, $uid, $gid, null, 'test', 'content_test', false);
    }
    userPermissionsForget($uid);
};
$eRow = function (int $id) use ($db): array {
    $st = $db->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$eNotes = function (int $uid) use ($db): array {
    $st = $db->prepare("SELECT title, body FROM user_notifications WHERE user_id = ? AND type = 'content' ORDER BY id");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$eEdit = function (string $hash) use ($db): ?array {
    $st = $db->prepare("SELECT id FROM wl_content_edits WHERE info_hash = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $st->execute([$hash]);
    $id = (int)$st->fetchColumn();
    return $id > 0 ? contentEditById($db, $id) : null;
};
$words = fn(int $n, string $p = 'w'): string => implode(' ', array_map(fn($i) => $p . $i, range(1, $n)));
$T = fn(string $s, string $f = 'bbcode', ?string $u = null): array => ['description' => $s, 'description_format' => $f, 'source_url' => $u];

try {
    /* ── 1. the registry, the presets, the legacy answer, the grant ───────────────────────────── */
    $reg = userPermissionList();
    check('1.70.0: content.delete_own, content.delete_any and content.public are registered',
          isset($reg['content.delete_own'], $reg['content.delete_any'], $reg['content.public']));
    $pre = userGroupPresets();
    check('… the member preset carries delete_own and public, never delete_any; the moderator preset delete_any',
          in_array('content.delete_own', $pre['member']['perms'], true) && in_array('content.public', $pre['member']['perms'], true)
          && !in_array('content.delete_any', $pre['member']['perms'], true) && in_array('content.delete_any', $pre['moderator']['perms'], true)
          && !in_array('content.delete_own', $pre['premium']['perms'], true));
    check('… and with accounts off nobody has any of the three (content.view still opens, as before)',
          !userLegacyDefault('content.delete_own') && !userLegacyDefault('content.delete_any') && !userLegacyDefault('content.public')
          && userLegacyDefault('content.view') && userLegacyDefault('content.propose'));
    $db->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"content.delete_own\"', '$.\"content.public\"') WHERE slug = 'member'");
    $db->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"content.delete_any\"') WHERE slug = 'moderator'");
    $db->exec("DELETE FROM settings WHERE `key` = 'schema_grant_v81_content'");
    trackerSchemaDataMigrations($db, $cfgE);
    $pm = json_decode($gpE('member'), true) ?: []; $pmod = json_decode($gpE('moderator'), true) ?: []; $pg = json_decode($gpE('guest'), true) ?: [];
    check('the v81 migration grants delete_own + public to members, delete_any to moderators, nothing to guests, and stamps its marker',
          !empty($pm['content.delete_own']) && !empty($pm['content.public']) && empty($pm['content.delete_any'])
          && !empty($pmod['content.delete_any']) && empty($pg['content.delete_own']) && empty($pg['content.public'])
          && (int)$db->query("SELECT COUNT(*) FROM settings WHERE `key` = 'schema_grant_v81_content'")->fetchColumn() === 1);
    $db->exec("UPDATE user_groups SET permissions = JSON_REMOVE(permissions, '$.\"content.public\"') WHERE slug = 'member'");
    trackerSchemaDataMigrations($db, $cfgE);
    check('… ONCE: an operator who takes one away afterwards keeps it away', empty((json_decode($gpE('member'), true) ?: [])['content.public']));

    /* ── 2. the schema, on both paths ─────────────────────────────────────────────────────────── */
    check('the schema is at 81 or later', TRACKER_SCHEMA_VERSION >= 81, (string)TRACKER_SCHEMA_VERSION);
    $creates = implode("\n", array_filter(trackerSchemaStatements(), 'is_string'));
    check('a fresh install: users carries content_credit_public DEFAULT 1 and descriptions_public DEFAULT 0',
          str_contains($creates, '`content_credit_public` TINYINT(1) NOT NULL DEFAULT 1') && str_contains($creates, '`descriptions_public` TINYINT(1) NOT NULL DEFAULT 0'));
    check('… both homes the chain column, and a proposal its kind (rewrite by default) and the by-member key',
          substr_count($creates, '`content_credits` TEXT DEFAULT NULL') === 2
          && str_contains($creates, "`kind` ENUM('rewrite','edit') NOT NULL DEFAULT 'rewrite'") && str_contains($creates, 'KEY `idx_edits_user` (`user_id`, `created_at`)'));
    $scratch = 'tracker_ct_' . bin2hex(random_bytes(3));
    $dsnPort = (string)($db->query('SELECT @@port')->fetchColumn() ?: '3306');
    $base = 'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';port=' . $dsnPort . ';charset=utf8mb4';
    try {
        $adm = new PDO($base, defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $adm->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $sdb = new PDO("$base;dbname=$scratch", defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '',
                           [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $sdb->exec("SET time_zone = '" . date('P') . "'");
            $sdb->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");
            $runAll = function () use ($sdb): void {
                foreach (trackerSchemaGuardedStatements($sdb) as $s) $sdb->exec(is_array($s) ? end($s) : $s);
            };
            foreach (trackerSchemaStatements() as $s) $sdb->exec($s);
            $runAll();
            $col = fn(string $t, string $c) => $sdb->query("SHOW COLUMNS FROM `$t` LIKE " . $sdb->quote($c))->fetch() ?: [];
            $cc = $col('users', 'content_credit_public'); $dp = $col('users', 'descriptions_public'); $kd = $col('wl_content_edits', 'kind');
            check('fresh path: the columns are there with their types and defaults',
                  strtolower((string)($cc['Type'] ?? '')) === 'tinyint(1)' && (string)($cc['Default'] ?? '') === '1' && (string)($dp['Default'] ?? '') === '0'
                  && strtolower((string)($col('whitelist', 'content_credits')['Type'] ?? '')) === 'text' && strtolower((string)($col('hash_content', 'content_credits')['Type'] ?? '')) === 'text'
                  && str_starts_with(strtolower((string)($kd['Type'] ?? '')), "enum('rewrite','edit')") && (string)($kd['Default'] ?? '') === 'rewrite',
                  json_encode([$cc, $dp, $kd]));
            // The upgrade path: a v80-shaped install with a described row whose author is alive, one whose author is gone.
            $sdb->exec("ALTER TABLE users DROP COLUMN content_credit_public, DROP COLUMN descriptions_public");
            $sdb->exec("ALTER TABLE whitelist DROP COLUMN content_credits");
            $sdb->exec("ALTER TABLE hash_content DROP COLUMN content_credits");
            $sdb->exec("ALTER TABLE wl_content_edits DROP KEY idx_edits_user, DROP COLUMN kind");
            $sdb->exec("INSERT INTO users (id, username, pass_hash) VALUES (5, 'alive', 'x')");
            $sdb->exec("INSERT INTO whitelist (info_hash, name, description, content_status, content_user_id, content_reviewed_at, created_at) VALUES
                        ('" . str_repeat('a1', 20) . "', 'A', 'words', 'approved', 5, '2026-09-01 10:00:00', '2026-08-01 00:00:00'),
                        ('" . str_repeat('a2', 20) . "', 'B', 'words', 'pending', 99, NULL, '2026-08-02 00:00:00'),
                        ('" . str_repeat('a3', 20) . "', 'C', NULL, 'none', NULL, NULL, '2026-08-03 00:00:00')");
            $sdb->exec("INSERT INTO hash_content (info_hash, description, content_status, content_user_id, created_at) VALUES ('" . str_repeat('b1', 20) . "', 'x', 'approved', 5, '2026-08-05 00:00:00')");
            $sdb->exec("INSERT INTO wl_content_edits (whitelist_id, info_hash, description, user_id) VALUES (1, '" . str_repeat('a1', 20) . "', 'p', 5)");
            $runAll();
            $wl = $sdb->query("SELECT content_user_id, content_credits FROM whitelist ORDER BY id")->fetchAll();
            $tA = (int)$sdb->query("SELECT UNIX_TIMESTAMP('2026-09-01 10:00:00')")->fetchColumn();
            check('upgrade path: the columns come back, a live author starts the chain as the first, at the review time',
                  $col('users', 'content_credit_public') && $col('wl_content_edits', 'kind') && $wl[0]['content_user_id'] == 5
                  && $wl[0]['content_credits'] === '[{"u":5,"k":"f","t":' . $tA . '}]'
                  && $sdb->query("SELECT content_credits FROM hash_content")->fetchColumn() === '[{"u":5,"k":"f","t":' . (int)$sdb->query("SELECT UNIX_TIMESTAMP('2026-08-05 00:00:00')")->fetchColumn() . '}]',
                  json_encode($wl));
            check('… an author whose account is gone is "a deleted account" (u = 0) and stops being the author of record; an empty row gets nothing',
                  $wl[1]['content_user_id'] === null && str_starts_with((string)$wl[1]['content_credits'], '[{"u":0,"k":"f",') && $wl[2]['content_credits'] === null, json_encode($wl));
            check('… a proposal that predates the kind is a rewrite; existing accounts show their name (1) and list nothing (0)',
                  $sdb->query("SELECT kind FROM wl_content_edits")->fetchColumn() === 'rewrite'
                  && json_encode($sdb->query("SELECT content_credit_public, descriptions_public FROM users")->fetch()) === '{"content_credit_public":1,"descriptions_public":0}');
            $before = json_encode($sdb->query("SELECT content_user_id, content_credits FROM whitelist ORDER BY id")->fetchAll());
            $again = array_filter(trackerSchemaGuardedStatements($sdb), fn($s) => is_string($s) && (str_contains($s, 'ADD COLUMN `content_cred') || str_contains($s, 'ADD COLUMN `descriptions_public') || str_contains($s, 'ADD COLUMN `kind`')));
            $runAll();
            check('… and once it is there nothing is asked again, and the data step changes nothing the second time',
                  !$again && $before === json_encode($sdb->query("SELECT content_user_id, content_credits FROM whitelist ORDER BY id")->fetchAll()));
        } finally {
            $adm->exec("DROP DATABASE IF EXISTS `$scratch`");
        }
    } catch (\Throwable $ex) {
        check('a scratch database could be built for the two schema paths', false, $ex->getMessage());
    }
    check('this database has the v81 columns', schemaColumnExists($db, 'users', 'content_credit_public') && schemaColumnExists($db, 'users', 'descriptions_public')
          && schemaColumnExists($db, 'whitelist', 'content_credits') && schemaColumnExists($db, 'hash_content', 'content_credits') && schemaColumnExists($db, 'wl_content_edits', 'kind'));

    /* ── 3. the setting, in its four places ───────────────────────────────────────────────────── */
    check('profile_descriptions_enabled ships ON', (trackerSchemaDefaultSettings()['profile_descriptions_enabled'] ?? null) === '1');
    $save = $src('api/admin/save_settings.php');
    check('… in the save allow-list and coerced to 0/1', str_contains($save, "    'profile_descriptions_enabled',\n") && str_contains($save, "'profile_descriptions_enabled', 'shout_nav', 'shout_system_lines',"));
    $tpl = $src('templates/admin/settings.php');
    $sp = strpos($tpl, 'id="section-profile-descs"');
    $sec = $sp === false ? '' : substr($tpl, $sp, (int)strpos($tpl, 'class="settings-section"', $sp + 20) - $sp);
    check('… a section of its own in Settings → Profiles, right after the likes\', with the control, its words and the "descriptions are off" note',
          str_contains($tpl, 'id="section-profile-descs" data-group="profiles"') && $sp > (int)strpos($tpl, 'id="section-profile-votes"')
          && str_contains($sec, 'name="profile_descriptions_enabled"') && str_contains($sec, "__('settings.profile_descs_intro')")
          && str_contains($sec, "__('settings.profile_descs_content_off')") && str_contains($sec, 'contentEnabled($cfg)'));
    check('… with search words', !empty(settingsCatalogKeywords()['profile_descriptions_enabled']));
    $enL = include $root . '/lang/en.php'; $plL = include $root . '/lang/pl.php';
    $langOk = true;
    foreach (['settings.profile_descs_intro', 'account.credit_public_hint', 'account.descs_public_hint', 'descs.public_named', 'descs.public_hidden',
              'notify.content_edit_applied_body', 'notify.content_deleted', 'js.app.credit_edit', 'js.descs.role_coauthor', 'js.wl.edit_share'] as $k) {
        if (trim((string)($enL[$k] ?? '')) === '' || trim((string)($plL[$k] ?? '')) === '' || ($enL[$k] ?? '') === ($plL[$k] ?? '')) $langOk = false;
    }
    check('the words exist in both languages, the Polish is Polish, and the credit line reads "Autor opisu:"',
          $langOk && $plL['js.app.desc_by'] === 'Autor opisu:' && $plL['js.app.credit_hidden'] === 'użytkownik' && $enL['js.app.credit_hidden'] === 'a member'
          && $enL['js.app.credit_deleted'] === 'a deleted account' && str_contains((string)$plL['settings.profile_descs_intro'], 'content.public'));
    check('the list feature needs accounts, its switch AND descriptions or source links',
          profileDescsEnabled($cfgE) && !profileDescsEnabled(array_merge($cfgE, ['profile_descriptions_enabled' => '0']))
          && !profileDescsEnabled(array_merge($cfgE, ['users_enabled' => '0']))
          && !profileDescsEnabled(array_merge($cfgE, ['wl_allow_description' => '0', 'wl_allow_source_url' => '0']))
          && profileDescsEnabled(array_merge($cfgE, ['wl_allow_description' => '0'])));

    /* ── 4. the share formula, on known texts ─────────────────────────────────────────────────── */
    $w100 = $words(100);
    $share = fn(string $a, string $b) => contentEditShare($T($a), $T($b));
    check('the share: 25 words of 100 replaced is 25%', $share($w100, implode(' ', array_map(fn($i) => ($i <= 25 ? 'x' : 'w') . $i, range(1, 100)))) === 25);
    check('… 100 words added to 100 is 50% (half the text is theirs)', $share($w100, $w100 . ' ' . $words(100, 'n')) === 50);
    check('… 6 of 100 taken out is 6%', $share($w100, implode(' ', array_map(fn($i) => 'w' . $i, range(7, 100)))) === 6);
    check('… a text replaced whole is 100%, and so is the first text written into an empty one', $share('one two three', 'four five six seven') === 100 && $share('', 'fresh words') === 100);
    check('… identical is 0% — and an edit that changes anything at all is at least 1%: a comma, a re-cased word, white space, the format, the link',
          $share($w100, $w100) === 0 && $share($w100, str_replace('w50', 'w50,', $w100)) === 1 && $share('Hello World', 'hello world') === 1
          && $share("a b\r\nc", "a b\nc") === 1 && contentEditShare($T('x'), $T('x', 'markdown')) === 1 && contentEditShare($T('x'), $T('x', 'bbcode', 'https://e.org/')) === 1);
    check('… markup a person added is part of what they wrote (a word made bold is a word changed)', $share('make this bold', 'make [b]this[/b] bold') === 33);
    check('… rounded half up and never above 100', $share('a b', 'a c') === 50 && $share('a', 'b c d e f g h') === 100);
    // Exact, and cheap where little changed however long the text: three words fixed across a thousand —
    // the first, one in the middle, the last, so nothing at either end can be set aside first.
    $k1000 = $words(1000);
    $spread = str_replace(['w1 ', ' w500 ', ' w1000'], ['v1 ', ' v500 ', ' v1000'], $k1000);
    $t0 = microtime(true);
    $pSpread = $share($k1000, $spread);
    $tSpread = microtime(true) - $t0;
    check('… three words fixed across a thousand-word text (first, middle, last): 1%, and in a few milliseconds',
          $pSpread === 1 && $tSpread < 0.2, $pSpread . ' in ' . round($tSpread * 1000, 1) . ' ms');
    // Past the budget (the texts differ by more than 631 words): the words both have, never more than proven.
    $t0 = microtime(true);
    $pBig = $share($words(800, 'a'), $words(800, 'b'));
    $every2 = implode(' ', array_map(fn($i) => ($i % 2 ? 'w' : 'q') . $i, range(1, 1200)));
    $pHalf = $share($words(1200), $every2);
    check('… past the budget the documented bound answers — a text replaced whole is still 100%, every second word of 1,200 replaced 50% — the same way every time, fast',
          $pBig === 100 && $pBig === $share($words(800, 'a'), $words(800, 'b')) && $pHalf === 50 && (microtime(true) - $t0) < 1.0, $pBig . ' / ' . $pHalf);
    // The claim that it is EXACT, held to the textbook dynamic programme on 300 random pairs of short texts
    // drawn from a small vocabulary (so words repeat, which is where a shortcut would go wrong).
    $lcsRef = function (array $a, array $b): int {
        $prev = array_fill(0, count($b) + 1, 0);
        foreach ($a as $x) {
            $cur = [0];
            foreach ($b as $j => $y) $cur[$j + 1] = $x === $y ? $prev[$j] + 1 : max($prev[$j + 1], $cur[$j]);
            $prev = $cur;
        }
        return $prev[count($b)];
    };
    mt_srand(81);
    $bad = [];
    for ($i = 0; $i < 300; $i++) {
        $mk = fn() => array_map(fn() => ['a', 'b', 'c', 'd', 'e', 'x'][mt_rand(0, 5)], range(1, mt_rand(0, 40)));
        $a = $mk(); $b = $mk();
        if (contentShareKept($a, $b) !== $lcsRef($a, $b)) $bad[] = implode(' ', $a) . ' | ' . implode(' ', $b);
    }
    check('… exact: the words kept agree with the textbook longest common subsequence on 300 random pairs', $bad === [], implode(' || ', array_slice($bad, 0, 3)));

    /* ── 5. the chain: encoded, appended, merged, capped, decoded ─────────────────────────────── */
    check('a chain starts with its author as the first, in the fixed compact text; no account, no chain',
          contentCreditsStart(5, 100) === '[{"u":5,"k":"f","t":100}]' && contentCreditsStart(null) === null && contentCreditsStart(0) === null);
    $c = contentCreditsDecode(contentCreditsStart(5, 100));
    $c = contentCreditsAppend($c, 5, 10, 200);
    check('the author editing their own first text stays the first — nothing added', contentCreditsEncode($c) === '[{"u":5,"k":"f","t":100}]');
    $c = contentCreditsAppend($c, 7, 25, 300);
    check('another member\'s edit adds {e, share, time}', contentCreditsEncode($c) === '[{"u":5,"k":"f","t":100},{"u":7,"k":"e","p":25,"t":300}]');
    $c = contentCreditsAppend($c, 7, 10, 400);
    check('the same member again, straight after: one edit, the shares added, the newer time', contentCreditsEncode($c) === '[{"u":5,"k":"f","t":100},{"u":7,"k":"e","p":35,"t":400}]');
    $c = contentCreditsAppend(contentCreditsAppend($c, 9, 6, 500), 7, 95, 600);
    $c = contentCreditsAppend($c, 7, 50, 700);
    check('not straight after: a new entry; merged shares never pass 100', count($c) === 4 && $c[2]['u'] === 9 && $c[3]['p'] === 100 && $c[3]['t'] === 700, contentCreditsEncode($c));
    check('0% and a proposer who is no account add nothing', count(contentCreditsAppend($c, 11, 0, 800)) === 4 && count(contentCreditsAppend($c, null, 30, 800)) === 4);
    check('what a chain says of one member: author, share added up, newest time; nothing of a stranger',
          contentCreditsFor($c, 7) === ['author' => false, 'share' => 100, 'at' => 700] && contentCreditsFor($c, 5) === ['author' => true, 'share' => 0, 'at' => 100]
          && contentCreditsFor($c, 99) === null);
    $long = contentCreditsDecode(contentCreditsStart(1, 1));
    for ($i = 2; $i < 130; $i++) $long = contentCreditsAppend($long, $i, 1, $i);
    check('at most 100 entries: the first, and the newest after it', count($long) === CONTENT_CREDITS_MAX && $long[0]['u'] === 1 && $long[1]['u'] === 31 && end($long)['u'] === 129);
    check('a stored chain is checked field by field (a string id, an unknown kind, a 0% edit dropped; a share clamped)',
          contentCreditsDecode('[{"u":"5","k":"f"},{"u":3,"k":"x"},{"u":4,"k":"e","p":0},{"u":6,"k":"e","p":250,"t":9}]') === [['u' => 6, 'k' => 'e', 'p' => 100, 't' => 9]]);
    check('… and a row with no usable chain but an author reads as that author, first (a row written before v81)',
          contentCreditsDecode(null, 12) === [['u' => 12, 'k' => 'f', 't' => 0]] && contentCreditsDecode('not json', 12)[0]['u'] === 12 && contentCreditsDecode(null, null) === []);
    check('the member search matches that id and no longer one ({"u":7, not {"u":70,)',
          contentCreditsLikeFor(7) === '%{"u":7,%' && str_contains(contentCreditsEncode($c), '{"u":7,') && !str_contains('[{"u":70,"k":"e","p":1,"t":1}]', '{"u":7,'));

    /* ── 6. accounts, groups, torrents ────────────────────────────────────────────────────────── */
    $mj = json_decode($gpE('member'), true) ?: [];
    foreach (['content.submit', 'content.propose', 'content.view', 'content.delete_own', 'content.public', 'favourites.view_others',
              'whitelist.view', 'index.view', 'index.magnet'] as $p) $mj[$p] = true;
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([json_encode($mj, JSON_UNESCAPED_SLASHES)]);
    foreach (['cte_nopub' => 'content.public', 'cte_noview' => 'content.view', 'cte_noothers' => 'favourites.view_others', 'cte_nodel' => 'content.delete_own'] as $slug => $without) {
        $j = $mj; unset($j[$without]);
        $db->prepare("INSERT INTO user_groups (slug, name, description, color, priority, is_default, is_system, permissions) VALUES (?, ?, '', '', 2, 0, 0, ?)")
           ->execute([$slug, $slug, json_encode($j, JSON_UNESCAPED_SLASHES)]);
    }
    userPermissionsForget(0);
    $aId = $eUser('cte_author'); $bId = $eUser('cte_editor'); $cId = $eUser('cte_other'); $mId = $eUser('cte_mod');
    $eOnlyIn($mId, ['member', 'moderator']);
    $A = $eRow($aId); $B = $eRow($bId); $C = $eRow($cId); $Mo = $eRow($mId);
    $H1 = str_repeat('c0e1', 10); $H2 = str_repeat('c0e2', 10); $H3 = str_repeat('c0e3', 10); $H4 = str_repeat('c0e4', 10);
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned, probe_status) VALUES (?, 'Chain one', 'admin', NOW(), 0, 'passed')")->execute([$H1]);
    foreach ([$H2 => 'Delete one', $H3 => 'Pending one', $H4 => 'Cleared one'] as $h => $nm) {
        $db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, ?, 'done')")->execute([$h, $nm]);
    }
    check('fixtures: four members (one of them a moderator) and four torrents', $aId && $bId && $cId && $mId && userIdHasPermission($db, $cfgE, $mId, 'content.delete_any')
          && !userIdHasPermission($db, $cfgE, $bId, 'content.delete_any'));

    /* ── 7. the flow: a first description, edits, the credits, a rewrite ──────────────────────── */
    $text0 = $words(100) . ' [hide]members only[/hide]';
    $r = contentAttach($db, $cfgE, $H1, ['description' => $text0, 'description_format' => 'bbcode', 'source_url' => 'https://example.org/one'], $A, '127.0.0.1');
    $rec = contentRecordFor($db, $H1);
    check('a first description starts the chain with its author as the first',
          !empty($r['saved']) && $rec['content_user_id'] === $aId && str_starts_with((string)$rec['content_credits'], '[{"u":' . $aId . ',"k":"f","t":'), json_encode($rec));
    contentApprove($db, $cfgE, 'wl', (int)$rec['id']);
    $pub = richtextContentFor($db, $cfgE, $H1);
    check('published: the reader is shown the chain — one entry, the author, by name',
          $pub['author'] === 'cte_author' && count($pub['credits']) === 1 && $pub['credits'][0]['state'] === 'shown' && $pub['credits'][0]['name'] === 'cte_author'
          && $pub['credits'][0]['kind'] === 'first', json_encode($pub['credits']));
    $r = contentAttach($db, $cfgE, $H1, ['description' => $text0, 'description_format' => 'bbcode', 'source_url' => 'https://example.org/one', 'kind' => 'edit'], $B, '127.0.0.2');
    check('an EDIT that changes nothing is refused (400)', empty($r['ok']) && ($r['code'] ?? 0) === 400 && $r['error'] === __('api.content.edit_unchanged'), json_encode($r));
    // With accounts off every visitor may propose (the legacy answer), and an edit still is not theirs.
    $r = contentAttach($db, array_merge($cfgE, ['users_enabled' => '0']), $H1, ['description' => 'x', 'description_format' => 'bbcode', 'kind' => 'edit'], null, '127.0.0.9');
    check('… and so is an edit by nobody signed in, even where anybody may propose a rewrite (the prefill is the source, [hide] included)',
          empty($r['ok']) && ($r['code'] ?? 0) === 400 && $r['error'] === __('api.content.edit_not_allowed'), json_encode($r));
    $text1 = implode(' ', array_map(fn($i) => ($i <= 25 ? 'x' : 'w') . $i, range(1, 100))) . ' [hide]members only[/hide]';
    $r = contentAttach($db, $cfgE, $H1, ['description' => $text1, 'description_format' => 'bbcode', 'source_url' => 'https://example.org/one', 'kind' => 'edit'], $B, '127.0.0.2');
    $e = $eEdit($H1);
    check('an edit is filed as a proposal of kind edit, the home still called `kind`', !empty($r['proposed']) && ($r['edit_kind'] ?? '') === 'edit'
          && $e && $e['edit_kind'] === 'edit' && $e['kind'] === 'wl', json_encode([$r, $e['edit_kind'] ?? null]));
    $pv = contentEditPreview(contentRowById($db, 'wl', (int)$rec['id']), $e);
    check('the panel\'s preview: 25 words of 102 changed = 25%', $pv['kind'] === 'edit' && $pv['share'] === 25 && !$pv['as_rewrite'], json_encode($pv));
    check('applied', contentEditApply($db, $cfgE, $e));
    $rec = contentRecordFor($db, $H1);
    $ch = contentCreditsDecode($rec['content_credits']);
    check('… the author of record stays, the text is the edited one, and the editor is credited with 25%',
          $rec['content_user_id'] === $aId && $rec['description'] === $text1 && count($ch) === 2 && $ch[1]['u'] === $bId && $ch[1]['k'] === 'e' && $ch[1]['p'] === 25, (string)$rec['content_credits']);
    $nb = $eNotes($bId); $na = $eNotes($aId);
    check('… the editor is told their edit went in, with the share; the author that their description was edited — not replaced',
          count($nb) === 1 && $nb[0]['title'] === __('notify.content_edit_applied', ['name' => 'Chain one']) && str_contains($nb[0]['body'], '25%')
          && end($na)['title'] === __('notify.content_edited', ['name' => 'Chain one']) && str_contains(end($na)['body'], '25%'), json_encode([$nb, $na]));
    $st = $db->prepare("SELECT COUNT(*) FROM wl_content_edits WHERE info_hash = ? AND status = 'rejected' AND note = ? AND user_id = ?");
    $st->execute([$H1, CONTENT_ARCHIVE_NOTE, $aId]);
    check('… and the version it replaced is kept, as before (the newest ten of each description — §16)', (int)$st->fetchColumn() === 1);
    // The editor again, straight after (merged), then a third member.
    $text2 = str_replace('w30 w31 w32 w33 w34 w35 w36 w37 w38 w39', 'y30 y31 y32 y33 y34 y35 y36 y37 y38 y39', $text1);
    contentAttach($db, $cfgE, $H1, ['description' => $text2, 'description_format' => 'bbcode', 'source_url' => 'https://example.org/one', 'kind' => 'edit'], $B, '127.0.0.2');
    contentEditApply($db, $cfgE, $eEdit($H1));
    $text3 = str_replace('w90 w91 w92 w93 w94 w95', 'z90 z91 z92 z93 z94 z95', $text2);
    contentAttach($db, $cfgE, $H1, ['description' => $text3, 'description_format' => 'bbcode', 'source_url' => 'https://example.org/one', 'kind' => 'edit'], $C, '127.0.0.3');
    contentEditApply($db, $cfgE, $eEdit($H1));
    $ch = contentCreditsDecode(contentRecordFor($db, $H1)['content_credits']);
    check('the same editor twice in a row is ONE edit (25% + 10% = 35%); a third member adds their own (6%)',
          count($ch) === 3 && $ch[1]['u'] === $bId && $ch[1]['p'] === 35 && $ch[2]['u'] === $cId && $ch[2]['p'] === 6, json_encode($ch));
    $pub = richtextContentFor($db, $cfgE, $H1);
    check('… and the reader is shown all three: the first, 35% edit, 6% edit',
          array_map(fn($x) => [$x['kind'], $x['pct'], $x['name']], $pub['credits']) === [['first', null, 'cte_author'], ['edit', 35, 'cte_editor'], ['edit', 6, 'cte_other']],
          json_encode($pub['credits']));

    /* ── 8. the author's name, everywhere it is shown ─────────────────────────────────────────── */
    $db->prepare("UPDATE users SET content_credit_public = 0 WHERE id = ?")->execute([$bId]);
    $pub = richtextContentFor($db, $cfgE, $H1);
    check('the editor hides their name: their entry is "a member" — no name, no picture, no link — and the others are unchanged',
          $pub['credits'][1]['state'] === 'hidden' && $pub['credits'][1]['name'] === null && $pub['credits'][1]['avatar'] === '' && !$pub['credits'][1]['profile']
          && $pub['credits'][0]['name'] === 'cte_author' && !str_contains(json_encode($pub), 'cte_editor'), json_encode($pub['credits']));
    $db->prepare("UPDATE users SET content_credit_public = 0 WHERE id = ?")->execute([$aId]);
    $pub = richtextContentFor($db, $cfgE, $H1);
    $adm = richtextContentFor($db, $cfgE, $H1, true);
    check('the author hides theirs: the public answer has no author name and no picture at all',
          $pub['author'] === null && $pub['author_avatar'] === '' && $pub['credits'][0]['state'] === 'hidden' && !str_contains(json_encode($pub), 'cte_author'), json_encode($pub));
    check('… while a moderator (the panel\'s detail panels) still sees who wrote it, and that it is hidden', $adm['author'] === 'cte_author' && $adm['author_hidden'] === true);
    check('the editor\'s own line: "you", only their own reader is told', contentCreditsForDisplay($db, $cfgE, $ch, $bId)[1]['you'] === true
          && contentCreditsForDisplay($db, $cfgE, $ch, $cId)[1]['you'] === false);
    check('the line the editors show: public, with the name — or "a member" with the switch off — or, signed out, public alone',
          str_contains(contentPublicLine($C, '/'), 'cte_other') && str_contains(contentPublicLine($C, '/'), '?action=account#acc-privacy')
          && contentPublicLine($eRow($aId), '/') === __('descs.public_hidden', ['url' => '/?action=account#acc-privacy'])
          && contentPublicLine(null, '/') === __('descs.public_anon'));
    check('… drawn in the Info panel\'s editor and in the whitelist form, from the reader\'s own row',
          str_contains($src('templates/partials/info_overlay.php'), '<p class="form-hint info-desc-public" id="info-desc-public"><?= contentPublicLine($ioViewer, $baseUrl) ?></p>')
          && str_contains($src('templates/pages/whitelist.php'), '<?= contentPublicLine($wlMe, $baseUrl) ?>'));
    $db->prepare("UPDATE users SET content_credit_public = 1 WHERE id IN (?, ?)")->execute([$aId, $bId]);

    /* ── 9. a rewrite resets the chain; an edit of a cleared record is a rewrite ──────────────── */
    contentAttach($db, $cfgE, $H1, ['description' => 'All new words by the other member.', 'description_format' => 'bbcode', 'source_url' => ''], $C, '127.0.0.3');
    $e = $eEdit($H1);
    check('a proposal without a kind is a rewrite', $e && $e['edit_kind'] === 'rewrite');
    contentEditApply($db, $cfgE, $e);
    $rec = contentRecordFor($db, $H1);
    check('an applied REWRITE: the proposer is the author, and the chain starts again with them as the first',
          $rec['content_user_id'] === $cId && count(contentCreditsDecode($rec['content_credits'])) === 1 && contentCreditsDecode($rec['content_credits'])[0]['u'] === $cId);
    $naR = $eNotes($aId);
    check('… the author it replaced is told "replaced", as before', $naR && end($naR)['title'] === __('notify.content_replaced', ['name' => 'Chain one']));
    contentAttach($db, $cfgE, $H4, ['description' => 'Words to be cleared.', 'description_format' => 'bbcode'], $A, '127.0.0.1');
    $r4 = contentRecordFor($db, $H4);
    contentApprove($db, $cfgE, 'idx', (int)$r4['id']);
    contentAttach($db, $cfgE, $H4, ['description' => 'Words to be cleared, edited.', 'description_format' => 'bbcode', 'kind' => 'edit'], $B, '127.0.0.2');
    $e4 = $eEdit($H4);
    contentClear($db, 'idx', (int)$r4['id']);
    $pv = contentEditPreview(contentRowById($db, 'idx', (int)$r4['id']), $e4);
    check('the panel\'s Clear takes the chain with the words; an edit still waiting on a cleared record goes in as a rewrite',
          contentRecordFor($db, $H4)['content_credits'] === null && $pv['as_rewrite'] === true && $pv['share'] === 0);
    contentEditApply($db, $cfgE, $e4);
    $r4 = contentRecordFor($db, $H4);
    check('… applied: its proposer is the author and the first', $r4['content_user_id'] === $bId && contentCreditsDecode($r4['content_credits'])[0]['u'] === $bId);

    /* ── 10. delete: own, any, refused ────────────────────────────────────────────────────────── */
    contentAttach($db, $cfgE, $H2, ['description' => 'The author\'s own words.', 'description_format' => 'bbcode', 'source_url' => 'https://example.org/two'], $A, '127.0.0.1');
    $r2 = contentRecordFor($db, $H2);
    check('the author may take their own down while it waits ("own"); a moderator may not touch what waits (null)',
          contentDeleteRight($db, $cfgE, $r2, $A) === 'own' && contentDeleteRight($db, $cfgE, $r2, $Mo) === null && contentDeleteRight($db, $cfgE, $r2, $B) === null);
    contentApprove($db, $cfgE, 'idx', (int)$r2['id']);
    $r2 = contentRecordFor($db, $H2);
    check('published: the author "own", the moderator "any", anybody else nobody — and nobody signed in, and nobody with accounts off',
          contentDeleteRight($db, $cfgE, $r2, $A) === 'own' && contentDeleteRight($db, $cfgE, $r2, $Mo) === 'any' && contentDeleteRight($db, $cfgE, $r2, $B) === null
          && contentDeleteRight($db, $cfgE, $r2, null) === null && contentDeleteRight($db, array_merge($cfgE, ['users_enabled' => '0']), $r2, $A) === null);
    $nodelId = $eUser('cte_nodel_author');
    $eOnlyIn($nodelId, ['cte_nodel']);
    $db->prepare("UPDATE hash_content SET content_user_id = ? WHERE id = ?")->execute([$nodelId, (int)$r2['id']]);
    check('an author whose groups lack content.delete_own may not', contentDeleteRight($db, $cfgE, contentRecordFor($db, $H2), $eRow($nodelId)) === null);
    $db->prepare("UPDATE hash_content SET content_user_id = ? WHERE id = ?")->execute([$aId, (int)$r2['id']]);
    contentAttach($db, $cfgE, $H2, ['description' => 'A rewrite waiting.', 'description_format' => 'bbcode'], $B, '127.0.0.2');
    contentAttach($db, $cfgE, $H2, ['description' => 'An edit waiting.', 'description_format' => 'bbcode', 'kind' => 'edit'], $C, '127.0.0.3');
    $auditMax = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM audit_log")->fetchColumn();
    $notesB = count($eNotes($bId)); $notesA = count($eNotes($aId));
    $d = contentDelete($db, $cfgE, contentRecordFor($db, $H2), $A, 'own');
    $r2 = contentRecordFor($db, $H2);
    check('deleted, own: the text, the link, the author and the chain go — the way the panel\'s Clear takes them',
          $d['ok'] && $r2['content_status'] === 'none' && $r2['description'] === null && $r2['source_url'] === null && $r2['content_user_id'] === null && $r2['content_credits'] === null, json_encode($r2));
    $st = $db->prepare("SELECT status, note FROM wl_content_edits WHERE info_hash = ? ORDER BY id");
    $st->execute([$H2]);
    $w = $st->fetchAll(PDO::FETCH_ASSOC);
    check('… both waiting proposals withdrawn, with the note that says why', $d['withdrawn'] === 2 && count($w) === 2
          && array_unique(array_column($w, 'note')) === [CONTENT_WITHDRAWN_NOTE] && array_unique(array_column($w, 'status')) === ['rejected'], json_encode($w));
    $nbW = $eNotes($bId);
    check('… their proposers told; the author, who did it, not', count($nbW) === $notesB + 1 && end($nbW)['title'] === __('notify.content_proposal_withdrawn', ['name' => 'Delete one'])
          && count($eNotes($aId)) === $notesA);
    $au = $db->query("SELECT action, action_group, actor_type, target_id, summary, detail FROM audit_log WHERE id > $auditMax ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('… one audit line, content.delete in the Content group, the hash and "their own"',
          count($au) === 1 && $au[0]['action'] === 'content.delete' && $au[0]['action_group'] === 'content' && $au[0]['target_id'] === $H2
          && str_contains((string)$au[0]['summary'], '(their own)') && (json_decode((string)$au[0]['detail'], true)['right'] ?? '') === 'own', json_encode($au));
    contentAttach($db, $cfgE, $H2, ['description' => 'Written again.', 'description_format' => 'bbcode'], $A, '127.0.0.1');
    contentApprove($db, $cfgE, 'idx', (int)contentRecordFor($db, $H2)['id']);
    $notesA = count($eNotes($aId));
    $d = contentDelete($db, $cfgE, contentRecordFor($db, $H2), $Mo, 'any');
    $naD = $eNotes($aId);
    check('deleted by a moderator ("any"): the author is told', $d['ok'] && count($naD) === $notesA + 1
          && end($naD)['title'] === __('notify.content_deleted', ['name' => 'Delete one']));

    /* ── 11. an account deleted: its credits become "a deleted account" ───────────────────────── */
    $goneId = $eUser('cte_gone');
    contentAttach($db, $cfgE, $H3, ['description' => 'By somebody who will leave.', 'description_format' => 'bbcode'], $eRow($goneId), '127.0.0.1');
    $r3 = contentRecordFor($db, $H3);
    contentApprove($db, $cfgE, 'idx', (int)$r3['id']);
    contentAttach($db, $cfgE, $H3, ['description' => 'By somebody who will leave, edited.', 'description_format' => 'bbcode', 'kind' => 'edit'], $B, '127.0.0.2');
    contentEditApply($db, $cfgE, $eEdit($H3));
    contentAttach($db, $cfgE, $H3, ['description' => 'A last proposal.', 'description_format' => 'bbcode'], $eRow($goneId), '127.0.0.1');
    userDeleteCascade($db, $goneId);
    $r3 = contentRecordFor($db, $H3);
    $ch3 = contentCreditsDecode($r3['content_credits']);
    $disp = contentCreditsForDisplay($db, $cfgE, $ch3, null);
    $st = $db->prepare("SELECT COUNT(*) FROM wl_content_edits WHERE info_hash = ? AND user_id IS NOT NULL AND user_id = ?");
    $st->execute([$H3, $goneId]);
    check('an account deleted: it is no longer the author of record, its credit is "a deleted account" (u = 0), its proposals name nobody',
          $r3['content_user_id'] === null && $ch3[0]['u'] === 0 && $ch3[1]['u'] === $bId && $disp[0]['state'] === 'deleted' && $disp[1]['state'] === 'shown'
          && (int)$st->fetchColumn() === 0, json_encode([$r3['content_credits'], $disp]));
    check('… so a new account that gets the same id inherits nothing (no "own" right, no credit)', contentDeleteRight($db, $cfgE, $r3, ['id' => $goneId, 'username' => 'x']) === null);

    /* ── 12. the list: who may see it, one flag at a time ─────────────────────────────────────── */
    $shownE = fn(array $c, int $o, ?int $v): bool => profileDescsShownTo($db, $c, $eRow($o), $v === null ? null : $eRow($v));
    $db->prepare("UPDATE users SET descriptions_public = 1 WHERE id = ?")->execute([$aId]);
    check('the list, baseline: an owner who said yes, whose group grants it, name shown, read by another member', $shownE($cfgE, $aId, $cId));
    check('… and their own, by themselves', $shownE($cfgE, $aId, $aId));
    check('the setting off: nobody\'s, not even your own', !$shownE(array_merge($cfgE, ['profile_descriptions_enabled' => '0']), $aId, $cId)
          && !$shownE(array_merge($cfgE, ['profile_descriptions_enabled' => '0']), $aId, $aId));
    check('descriptions and source links both off (contentEnabled()): nobody\'s', !$shownE(array_merge($cfgE, ['wl_allow_description' => '0', 'wl_allow_source_url' => '0']), $aId, $aId));
    check('accounts off: nobody\'s', !$shownE(array_merge($cfgE, ['users_enabled' => '0']), $aId, $cId));
    check('profiles off: not another\'s — your own still is (the account page\'s tab)',
          !$shownE(array_merge($cfgE, ['profiles_enabled' => '0']), $aId, $cId) && $shownE(array_merge($cfgE, ['profiles_enabled' => '0']), $aId, $aId));
    $db->prepare("UPDATE users SET descriptions_public = 0 WHERE id = ?")->execute([$aId]);
    check('their own list switch off (the default): not for another — for themselves, yes', !$shownE($cfgE, $aId, $cId) && $shownE($cfgE, $aId, $aId));
    $db->prepare("UPDATE users SET descriptions_public = 1, content_credit_public = 0 WHERE id = ?")->execute([$aId]);
    check('their NAME hidden: not for another either — a list of what they wrote would say what the switch keeps quiet', !$shownE($cfgE, $aId, $cId) && $shownE($cfgE, $aId, $aId));
    $db->prepare("UPDATE users SET content_credit_public = 1 WHERE id = ?")->execute([$aId]);
    $noPub = $eUser('cte_nopub_owner'); $eOnlyIn($noPub, ['cte_nopub']);
    $db->prepare("UPDATE users SET descriptions_public = 1 WHERE id = ?")->execute([$noPub]);
    check('an owner whose groups do not grant content.public: not for another, whatever their flag says', !$shownE($cfgE, $noPub, $cId) && $shownE($cfgE, $noPub, $noPub));
    $admOwner = $eUser('cte_admin_owner'); $eOnlyIn($admOwner, ['admin']);
    $db->prepare("UPDATE users SET descriptions_public = 1 WHERE id = ?")->execute([$admOwner]);
    check('an owner only in the admin group does NOT pass: the blanket is power, not consent',
          userIdHasPermission($db, $cfgE, $admOwner, 'content.public') && !$shownE($cfgE, $admOwner, $cId));
    $db->exec("UPDATE user_groups SET permissions = JSON_MERGE_PATCH(permissions, '{\"content.public\":true}') WHERE slug = 'admin'");
    check('… until the admin group is GRANTED it', $shownE($cfgE, $admOwner, $cId));
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'admin'")->execute([$eBefore['admin']]);
    $noView = $eUser('cte_noview_reader'); $eOnlyIn($noView, ['cte_noview']);
    $noOthers = $eUser('cte_noothers_reader'); $eOnlyIn($noOthers, ['cte_noothers']);
    check('a reader whose account may not read descriptions (content.view), or open profiles (favourites.view_others): nothing',
          !$shownE($cfgE, $aId, $noView) && !$shownE($cfgE, $aId, $noOthers));
    $db->prepare("INSERT INTO user_blocks (user_id, blocked_id, hide_profile) VALUES (?, ?, 1)")->execute([$aId, $cId]);
    check('an owner who blocked this reader with "hide my profile": not for them', !$shownE($cfgE, $aId, $cId));
    $db->prepare("DELETE FROM user_blocks WHERE user_id = ?")->execute([$aId]);
    $db->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$aId]);
    check('a suspended owner: not for anybody else; nobody signed in: nothing', !$shownE($cfgE, $aId, $cId) && !$shownE($cfgE, $aId, null));
    $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$aId]);
    $ctx = profileDescsContext($db, $cfgE, $eRow($aId));
    check('the account page\'s context: the tab, the list switch with the grant, the name switch, the name shown',
          $ctx['enabled'] && $ctx['public_ok'] && $ctx['may_publish'] && !$ctx['publish_blocked'] && $ctx['credit_ok'] && !$ctx['name_hidden'], json_encode($ctx));
    $ctx = profileDescsContext($db, $cfgE, $eRow($noPub));
    check('… without the grant the list switch is still drawn, with the sentence that says why', $ctx['public_ok'] && !$ctx['may_publish'] && $ctx['publish_blocked'], json_encode($ctx));
    $ctx = profileDescsContext($db, array_merge($cfgE, ['profile_descriptions_enabled' => '0']), $eRow($aId));
    check('… with the list switched off the name switch stays (it is about every credit)', !$ctx['enabled'] && $ctx['credit_ok'], json_encode($ctx));

    /* ── 13. the list: the rows, the order, the page ──────────────────────────────────────────── */
    $L = [];
    $ins = function (int $i, string $home, string $name, string $status, ?int $author, ?string $chain, bool $banned = false, string $desc = 'Some words.') use ($db, $eHash, &$L): void {
        $h = $eHash($i);
        $L[$i] = $h;
        if ($home === 'wl') {
            $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned, probe_status, description, description_format, content_status, content_user_id, content_credits, content_reviewed_at)
                          VALUES (?, ?, 'admin', '2026-09-01 00:00:00', ?, 'passed', ?, 'bbcode', ?, ?, ?, '2026-09-01 00:00:00')")
               ->execute([$h, $name, $banned ? 1 : 0, $desc, $status, $author, $chain]);
        } else {
            $db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, ?, 'done')")->execute([$h, $name]);
            $db->prepare("INSERT INTO hash_content (info_hash, description, description_format, content_status, content_user_id, content_credits, content_reviewed_at, created_at)
                          VALUES (?, ?, 'bbcode', ?, ?, ?, '2026-09-01 00:00:00', '2026-09-01 00:00:00')")
               ->execute([$h, $desc, $status, $author, $chain]);
            if ($banned) $db->prepare("INSERT INTO banned_hashes (info_hash, reason, source) VALUES (?, 'fixture', 'admin')")->execute([$h]);
        }
    };
    $f = fn(int $u, int $t) => '{"u":' . $u . ',"k":"f","t":' . $t . '}';
    $ed = fn(int $u, int $p, int $t) => '{"u":' . $u . ',"k":"e","p":' . $p . ',"t":' . $t . '}';
    $ins(1, 'wl', 'Desc Alpha', 'approved', $aId, '[' . $f($aId, 1790000100) . ']', false, 'Alpha [hide]secret words[/hide] said [b]boldly[/b].');
    $ins(2, 'idx', 'Desc Bravo', 'approved', $bId, '[' . $f($bId, 1790000200) . ',' . $ed($aId, 25, 1790000250) . ']');
    $ins(3, 'idx', 'Desc Charlie', 'pending', $aId, '[' . $f($aId, 1790000300) . ']');
    $ins(4, 'idx', 'Desc Delta', 'rejected', $aId, '[' . $f($aId, 1790000400) . ']');
    $ins(5, 'idx', 'Desc Echo', 'approved', $aId, '[' . $f($aId, 1790000500) . ']', true);
    $ins(6, 'wl', 'Desc Foxtrot', 'approved', $aId, '[' . $f($aId, 1790000600) . ']', true);
    $ins(7, 'idx', 'Desc Golf', 'approved', $bId, '[' . $f($bId, 1790000700) . ',' . $ed($aId * 10 + 7, 40, 1790000750) . ']');   // an id that STARTS like theirs
    $ins(8, 'idx', 'Desc Hotel', 'approved', $aId, null, false, '');   // a source link alone, a row from before v81
    $db->prepare("UPDATE hash_content SET source_url = 'https://example.org/hotel' WHERE info_hash = ?")->execute([$L[8]]);
    $prop = function (string $hash, string $kind, string $status, ?string $note, string $at) use ($db, $aId): void {
        $db->prepare("INSERT INTO wl_content_edits (whitelist_id, hash_content_id, info_hash, description, description_format, kind, status, note, user_id, created_at, reviewed_at)
                      VALUES (NULL, 1, ?, 'A proposal.', 'bbcode', ?, ?, ?, ?, ?, ?)")
           ->execute([$hash, $kind, $status, $note, $aId, $at, $status === 'pending' ? null : $at]);
    };
    $prop($L[2], 'edit', 'pending', null, '2026-09-20 00:00:00');
    $prop($L[7], 'rewrite', 'rejected', null, '2026-09-21 00:00:00');
    $prop($L[7], 'edit', 'rejected', CONTENT_WITHDRAWN_NOTE, '2026-09-22 00:00:00');
    $prop($L[1], 'rewrite', 'rejected', CONTENT_ARCHIVE_NOTE, '2026-09-23 00:00:00');   // the kept version, not a proposal
    $tz = new DateTimeZone('UTC');
    $mineR = ['is_owner' => true, 'can_wl' => true, 'can_hash' => true];
    $theirs = ['is_owner' => false, 'can_wl' => true, 'can_hash' => true];
    $own = profileDescsList($db, $cfgE, $aId, profileDescsParams(['per_page' => '50']), $mineR, $tz);
    $byName = []; foreach ($own['rows'] as $row) $byName[$row['name'] . '|' . $row['role'] . '|' . $row['status']] = $row;
    $mineKeys = array_filter(array_keys($byName), fn($k) => str_starts_with($k, 'Desc '));
    sort($mineKeys);
    check('their own list: authored rows in every state, the co-authored one with its share, their proposals — not the kept archive, not the lookalike id',
          $mineKeys === ['Desc Alpha|author|published', 'Desc Bravo|coauthor|published', 'Desc Bravo|edit|pending', 'Desc Charlie|author|pending',
                         'Desc Delta|author|rejected', 'Desc Echo|author|published', 'Desc Foxtrot|author|published', 'Desc Golf|edit|withdrawn',
                         'Desc Golf|rewrite|declined', 'Desc Hotel|author|published'], json_encode($mineKeys));
    check('… the co-author\'s share and date from the chain, the banned ones marked, the old row read as its author',
          ($byName['Desc Bravo|coauthor|published']['share'] ?? 0) === 25 && ($byName['Desc Bravo|coauthor|published']['at'] ?? '') === userDisplayTime(1790000250, $tz, 'Y-m-d H:i')
          && !empty($byName['Desc Echo|author|published']['banned']) && !empty($byName['Desc Foxtrot|author|published']['banned'])
          && ($byName['Desc Hotel|author|published']['source_url'] ?? '') === 'https://example.org/hotel' && ($byName['Desc Hotel|author|published']['excerpt'] ?? 'x') === '');
    check('… the excerpt is plain, and what [hide] hides is not in it', ($byName['Desc Alpha|author|published']['excerpt'] ?? '') === 'Alpha said boldly.', json_encode($byName['Desc Alpha|author|published'] ?? null));
    $others = profileDescsList($db, $cfgE, $aId, profileDescsParams([]), $theirs, $tz);
    // Dates: Bravo's credit 1790000250, Alpha's 1790000100, Hotel (no chain) its review time, 2026-09-01.
    check('somebody else\'s view: only what is published and not banned — the authored, the co-authored — and no proposal, newest first',
          array_map(fn($r) => $r['name'] . '|' . $r['role'], $others['rows']) === ['Desc Bravo|coauthor', 'Desc Alpha|author', 'Desc Hotel|author'] && $others['total'] === 3,
          json_encode(array_map(fn($r) => $r['name'] . '|' . $r['role'] . '|' . $r['status'], $others['rows'])));
    $noWl = profileDescsList($db, $cfgE, $aId, profileDescsParams([]), ['is_owner' => false, 'can_wl' => false, 'can_hash' => false], $tz);
    check('… without whitelist.view no registered torrent, and without index.magnet no hash',
          array_map(fn($r) => $r['name'], $noWl['rows']) === ['Desc Bravo', 'Desc Hotel'] && $noWl['rows'][0]['info_hash'] === null, json_encode($noWl['rows']));
    $names = fn(array $q) => array_map(fn($r) => $r['name'] . '|' . $r['role'], profileDescsList($db, $cfgE, $aId, profileDescsParams($q), $theirs, $tz)['rows']);
    check('sorting: by name A to Z and back, by role (authors, then co-authors by share) and back, by date both ways',
          $names(['sort' => 'name', 'dir' => 'asc']) === ['Desc Alpha|author', 'Desc Bravo|coauthor', 'Desc Hotel|author']
          && $names(['sort' => 'name', 'dir' => 'desc']) === ['Desc Hotel|author', 'Desc Bravo|coauthor', 'Desc Alpha|author']
          && $names(['sort' => 'role', 'dir' => 'desc']) === ['Desc Alpha|author', 'Desc Hotel|author', 'Desc Bravo|coauthor']
          && $names(['sort' => 'role', 'dir' => 'asc'])[0] === 'Desc Bravo|coauthor'
          && $names(['sort' => 'date', 'dir' => 'asc']) === ['Desc Hotel|author', 'Desc Alpha|author', 'Desc Bravo|coauthor'],
          json_encode([$names(['sort' => 'role', 'dir' => 'desc']), $names(['sort' => 'date', 'dir' => 'asc'])]));
    check('the search: a name (either case), and a hash prefix for a reader the hash is shown to',
          $names(['search' => 'bravo']) === ['Desc Bravo|coauthor'] && $names(['search' => substr($L[1], 0, 10)]) === ['Desc Alpha|author']
          && profileDescsList($db, $cfgE, $aId, profileDescsParams(['search' => substr($L[1], 0, 10)]), ['is_owner' => false, 'can_wl' => true, 'can_hash' => false], $tz)['total'] === 0);
    $pg = profileDescsList($db, $cfgE, $aId, profileDescsParams(['per_page' => '4', 'page' => '99']), $mineR, $tz);
    check('the pager: their 10 at 4 a page is 3 pages, and a page past the end is the last one (2 rows)',
          $pg['total'] === 10 && $pg['pages'] === 3 && $pg['page'] === 3 && count($pg['rows']) === 2, json_encode([$pg['total'], $pg['pages'], $pg['page'], count($pg['rows'])]));
    $pp = profileDescsParams(['page' => 'x', 'per_page' => '9999', 'sort' => 'nonsense', 'dir' => 'up', 'search' => "a\x01b"]);
    check('parameters clamped or defaulted, never an error', $pp === ['page' => 1, 'per_page' => 100, 'search' => 'a b', 'sort' => 'date', 'dir' => 'desc'], json_encode($pp));
    $t0 = microtime(true);
    for ($k = 0; $k < 5; $k++) profileDescsList($db, $cfgE, $aId, profileDescsParams(['sort' => 'name']), $mineR, $tz);
    check('… and a page is read in well under a tenth of a second', (microtime(true) - $t0) / 5 < 0.1, round((microtime(true) - $t0) / 5 * 1000, 1) . ' ms');

    /* ── 14. the endpoints, as requests ───────────────────────────────────────────────────────── */
    $runner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct_runner_' . bin2hex(random_bytes(4)) . '.php';
    $tmpE[] = $runner;
    file_put_contents($runner, '<?php
$a = json_decode((string)file_get_contents($argv[1]), true);
chdir($a["root"]);
$_SERVER["REQUEST_METHOD"] = $a["method"];
$_SERVER["REMOTE_ADDR"] = $a["ip"];
$_GET = $a["get"];
$_POST = $a["post"];
foreach (["config/app.php", "config/database.php", "includes/settings.php", "includes/functions.php", "includes/schema.php",
          "includes/whitelist.php", "includes/schedule.php", "includes/stats_timeline.php", "includes/index.php", "includes/api_auth.php",
          "includes/auth.php", "includes/richtext.php", "includes/reputation.php", "includes/mail.php", "includes/users.php",
          "includes/favourites.php", "includes/sounds.php", "includes/shout.php", "includes/usermedia.php", "includes/profilebio.php",
          "includes/profilevotes.php", "includes/profiledescs.php", "includes/lists.php", "includes/people.php", "includes/audit.php",
          "includes/cluster.php", "includes/opentracker.php", "includes/lang.php"] as $f) require_once $f;
$db = getDb();
$cfg = array_merge(getSettings($db), $a["cfg"]);
$GLOBALS["db"] = $db; $GLOBALS["cfg"] = $cfg;
session_id($a["sid"]);
session_start();
foreach ($a["session"] as $k => $v) $_SESSION[$k] = $v;
register_shutdown_function(function () { if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); });
langInit($cfg, null);
$GLOBALS["__audit_endpoint"] = $a["endpoint"];
require $a["file"];
');
    // An address of its own: the browser checks share 127.0.0.1's hourly buckets and must not find them spent.
    $runE = function (string $endpoint, string $method, array $get, array $post, array $session, string $ip = '127.0.0.77') use ($root, $runner, &$tmpE, $cfgE): array {
        $argFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct_args_' . bin2hex(random_bytes(4)) . '.json';
        $tmpE[] = $argFile;
        $file = str_starts_with($endpoint, 'admin/') ? 'api/admin/' . substr($endpoint, 6) . '.php' : 'api/' . $endpoint . '.php';
        file_put_contents($argFile, json_encode(['root' => $root, 'endpoint' => $endpoint, 'file' => $root . '/' . $file, 'method' => $method, 'ip' => $ip,
            'get' => $get, 'post' => $post, 'session' => $session, 'cfg' => $cfgE, 'sid' => 'cttest' . bin2hex(random_bytes(8))]));
        $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d xdebug.mode=off ' . escapeshellarg($runner) . ' ' . escapeshellarg($argFile) . ' 2>&1');
        $j = json_decode(trim($out), true);
        return is_array($j) ? $j : ['__raw' => substr($out, 0, 400)];
    };
    $sessE = fn(int $uid) => ['user_id' => $uid, 'user_login_time' => time(), 'csrf_token' => 'ct-child-token'];
    // The chain on H1 again, three long: a first by the author, the editor's, the other's.
    $db->prepare("UPDATE whitelist SET content_user_id = ?, content_credits = ?, description = ?, source_url = 'https://example.org/one', content_status = 'approved' WHERE info_hash = ?")
       ->execute([$aId, '[' . $f($aId, 1790001000) . ',' . $ed($bId, 35, 1790001100) . ',' . $ed($cId, 6, 1790001200) . ']', $text3, $H1]);
    $j = $runE('index_info', 'GET', ['hash' => $H1], [], $sessE($bId));
    check('the Info panel as a request: the chain, the editor may Edit — the prefill is the text as it stands — and may not delete',
          !empty($j['success']) && count($j['content_credits'] ?? []) === 3 && ($j['content_credits'][1]['pct'] ?? 0) === 35 && ($j['content_credits'][1]['you'] ?? false) === true
          && ($j['can_content_edit'] ?? false) === true && ($j['content_edit']['description'] ?? '') === $text3 && ($j['content_edit']['source_url'] ?? null) === 'https://example.org/one'
          && ($j['content_edit']['description_format'] ?? '') === 'bbcode' && array_key_exists('can_content_delete', $j) && $j['can_content_delete'] === null, json_encode($j));
    $j = $runE('index_info', 'GET', ['hash' => $H1], [], $sessE($aId));
    check('… the author may delete it ("own"), the moderator ("any")', ($j['can_content_delete'] ?? '') === 'own'
          && ($runE('index_info', 'GET', ['hash' => $H1], [], $sessE($mId))['can_content_delete'] ?? '') === 'any', json_encode($j['can_content_delete'] ?? $j));
    $j = $runE('index_info', 'GET', ['hash' => $H1], [], ['csrf_token' => 'ct-child-token']);
    check('… nobody signed in: no Edit and no source text in the answer, whether the guest group may open the panel or not',
          empty($j['can_content_edit']) && (empty($j['success']) || ($j['content_edit'] ?? 'x') === null) && !str_contains(json_encode($j), 'members only'), json_encode($j));
    $db->prepare("UPDATE users SET content_credit_public = 0 WHERE id = ?")->execute([$bId]);
    $j = $runE('index_info', 'GET', ['hash' => $H1], [], $sessE($cId));
    check('… with the editor\'s name hidden, their name is nowhere in the answer another reader gets',
          ($j['content_credits'][1]['state'] ?? '') === 'hidden' && !str_contains(json_encode($j), 'cte_editor'), json_encode($j['content_credits'] ?? $j));
    $db->prepare("UPDATE users SET content_credit_public = 1 WHERE id = ?")->execute([$bId]);
    $j = $runE('content_submit', 'POST', [], ['csrf_token' => 'ct-child-token', 'hash' => $H1, 'description' => $text3, 'description_format' => 'bbcode',
                                              'source_url' => 'https://example.org/one', 'kind' => 'edit'], $sessE($bId));
    check('content_submit with kind "edit" and nothing changed: 400', ($j['error'] ?? '') === __('api.content.edit_unchanged'), json_encode($j));
    $j = $runE('content_submit', 'POST', [], ['csrf_token' => 'ct-child-token', 'hash' => $H1, 'description' => $text3 . ' more', 'description_format' => 'bbcode',
                                              'source_url' => 'https://example.org/one', 'kind' => 'edit'], $sessE($bId));
    check('… changed: proposed', !empty($j['success']) && !empty($j['proposed']), json_encode($j));
    $j = $runE('admin/wl_content', 'POST', [], ['op' => 'edits'], ['loggedin' => true, 'csrf_token' => 'ct-child-token']);
    $row = null; foreach ((array)($j['rows'] ?? []) as $x) if (($x['info_hash'] ?? '') === $H1) $row = $x;
    check('the panel\'s Rewrites tab: the kind, the share it would credit now, the author of record who stays',
          $row && $row['edit_kind'] === 'edit' && $row['share'] === 1 && $row['cur_author'] === 'cte_author' && $row['author'] === 'cte_editor' && $row['as_rewrite'] === false,
          json_encode($row));
    $j = $runE('admin/wl_content', 'POST', [], ['op' => 'edit_apply', 'id' => (int)$row['id']], ['loggedin' => true, 'csrf_token' => 'ct-child-token']);
    check('… applied from the panel: the answer says the share; the other member edited last, so the editor gets a new entry (four in all)',
          !empty($j['success']) && ($j['kind'] ?? '') === 'edit' && ($j['share'] ?? 0) === 1 && ($j['message'] ?? '') === __('api.content.edit_applied', ['pct' => 1])
          && count(contentCreditsDecode(contentRecordFor($db, $H1)['content_credits'])) === 4, json_encode($j));
    $d0 = $runE('content_delete', 'POST', [], ['csrf_token' => 'not-it', 'hash' => $H1], $sessE($aId));
    $d1 = $runE('content_delete', 'POST', [], ['csrf_token' => 'ct-child-token', 'hash' => $H1], ['csrf_token' => 'ct-child-token']);
    $d2 = $runE('content_delete', 'POST', [], ['csrf_token' => 'ct-child-token', 'hash' => $H1], $sessE($bId));
    $d3 = $runE('content_delete', 'GET', ['hash' => $H1], [], $sessE($aId));
    check('content_delete: without the token 403, signed out 401, somebody else\'s 403, and POST only',
          ($d0['error'] ?? '') === __('api.csrf.invalid') && ($d1['error'] ?? '') === __('api.content.delete_denied') && ($d2['error'] ?? '') === __('api.content.delete_denied')
          && ($d3['error'] ?? '') === 'POST required', json_encode([$d0, $d1, $d2, $d3]));
    $d4 = $runE('content_delete', 'POST', [], ['csrf_token' => 'ct-child-token', 'hash' => $H1], $sessE($aId));
    $d5 = $runE('content_delete', 'POST', [], ['csrf_token' => 'ct-child-token', 'hash' => $H1], $sessE($aId));
    check('… the author\'s own: 200, "own", the words gone; again: 404, nothing to delete',
          !empty($d4['success']) && ($d4['right'] ?? '') === 'own' && contentRecordFor($db, $H1)['content_status'] === 'none'
          && ($d5['error'] ?? '') === __('api.content.nothing_to_delete'), json_encode([$d4, $d5]));
    check('… it is rate limited per account, in its own bucket', str_contains($src('api/content_delete.php'), "rateLimitAllow('contentdel', 'u' . (int)\$me['id'], 30, 3600)"));
    $db->prepare("UPDATE users SET descriptions_public = 0 WHERE id = ?")->execute([$aId]);
    $j = $runE('user_descriptions', 'GET', [], [], $sessE($aId));
    check('user_descriptions: your own list, every state (the 10 above; the delete just now took Chain one away)',
          !empty($j['success']) && ($j['own'] ?? false) === true && ($j['total'] ?? 0) === 10, json_encode([$j['total'] ?? $j, array_map(fn($r) => $r['name'] . '|' . $r['status'], $j['rows'] ?? [])]));
    $j = $runE('user_descriptions', 'GET', ['user' => 'cte_author'], [], $sessE($cId));
    $jn = $runE('user_descriptions', 'GET', ['user' => 'cte_nobody_at_all'], [], $sessE($cId));
    check('… another member while it is not public: the same 404 as a name nobody has', ($j['error'] ?? '') === 'not_found' && json_encode($j) === json_encode($jn), json_encode([$j, $jn]));
    $db->prepare("UPDATE users SET descriptions_public = 1 WHERE id = ?")->execute([$aId]);
    $j = $runE('user_descriptions', 'GET', ['user' => 'cte_author', 'sort' => 'name', 'dir' => 'asc'], [], $sessE($cId));
    check('… once they say yes: the published ones, sorted as asked', !empty($j['success']) && ($j['own'] ?? true) === false && ($j['total'] ?? 0) === 3
          && ($j['params']['sort'] ?? '') === 'name' && ($j['rows'][0]['name'] ?? '') === 'Desc Alpha', json_encode($j));
    $db->prepare("UPDATE users SET content_credit_public = 0 WHERE id = ?")->execute([$aId]);
    $j = $runE('user_descriptions', 'GET', ['user' => 'cte_author'], [], $sessE($cId));
    check('… and with their name hidden: 404 again', ($j['error'] ?? '') === 'not_found', json_encode($j));
    $j = $runE('user_privacy', 'POST', [], ['csrf_token' => 'ct-child-token', 'content_credit_public' => 1, 'descriptions_public' => 0], $sessE($aId));
    check('user_privacy saves both flags and says whether anything would show the list',
          !empty($j['success']) && ($j['content_credit_public'] ?? false) === true && ($j['descriptions_public'] ?? true) === false
          && ($j['descriptions_may_publish'] ?? false) === true && (int)$eRow($aId)['content_credit_public'] === 1 && (int)$eRow($aId)['descriptions_public'] === 0, json_encode($j));

    /* ── 15. the pages and the scripts that carry it ──────────────────────────────────────────── */
    $api = $src('api.php');
    check('the endpoints are routed; api.php and index.php load the list\'s library',
          str_contains($api, "'content_delete'             => 'api/content_delete.php'") && str_contains($api, "'user_descriptions'          => 'api/user_descriptions.php'")
          && str_contains($api, "require_once __DIR__ . '/includes/profiledescs.php';") && str_contains($src('index.php'), "require_once __DIR__ . '/includes/profiledescs.php';"));
    $acc = $src('templates/pages/account.php');
    $aV = strpos($acc, 'data-pane="votes"'); $aD = strpos($acc, 'data-pane="descriptions"'); $aU = strpos($acc, 'data-pane="uploads"');
    $pV = strpos($acc, 'id="acc-votes-public"'); $pD = strpos($acc, 'id="acc-descs-public"'); $pC = strpos($acc, 'id="acc-credit-public"'); $pL = strpos($acc, 'id="acc-lists-public"');
    check('the account page: the tab right after Likes / Ratings, its pane, the list switch right after the likes one, then the name switch, with the grant warning',
          $aV !== false && $aD > $aV && $aU > $aD && str_contains($acc, 'id="acc-pane-descriptions"') && str_contains($acc, "include __DIR__ . '/../partials/descs_section.php'")
          && $pV !== false && $pD > $pV && $pC > $pD && $pL > $pC && str_contains($acc, "['perm' => 'content.public']") && str_contains($acc, 'id="acc-descs-name-hidden"'));
    $prof = $src('templates/pages/profile.php');
    $qV = strpos($prof, 'id="profile-votes"'); $qD = strpos($prof, 'id="profile-descs"'); $qU = strpos($prof, 'id="profile-uploads"');
    check('the profile: the section right after Likes / Ratings, before Uploads, behind the one gate',
          $qV !== false && $qD > $qV && $qU > $qD && str_contains($prof, '$showDescs = function_exists(\'profileDescsShownTo\') && profileDescsShownTo($db, $cfg, $profile, $viewer);')
          && str_contains($prof, '!$showVotes && !$showDescs'));
    $part = $src('templates/partials/descs_section.php');
    check('the partial: the likes table\'s look, three sortable headers (name, role, date — the date the default), the ids the script reads',
          str_contains($part, 'class="transparency-table pv-table pd-table" id="pd-table"') && substr_count($part, "\$pdHead('pd-h-") === 3
          && str_contains($part, "\$pdHead('pd-h-date', 'date', 'date', __('descs.col_date'), __('descs.col_date_title'), true)")
          && str_contains($part, 'id="pd-search"') && str_contains($part, 'id="pd-pager"'));
    $fj = $src('assets/js/favourites.js');
    $djFrom = (int)strpos($fj, 'function initDescs(');
    $dj = $djFrom > 0 ? substr($fj, $djFrom, (int)strpos($fj, '"who has this in favourites"', $djFrom) - $djFrom) : '';
    // 1.73.0: the live language switch no longer needs a listener here — every word the rows say is a t.key() word that
    // keeps its key on the element (assets/js/i18n.js), and nothing else in a row is the page language's (ISO dates, the
    // browser's digits). The listener it had redrew the rows under the reader and, holding no answer, asked again.
    check('the list\'s script: built with textContent only, its words t.key() words (they follow a live language switch), started with the others; the router knows the pane; the switches post their flags',
          $dj !== '' && !str_contains($dj, 'innerHTML') && str_contains($dj, "t.key('js.descs.") && str_contains($fj, "        initDescs();\n")
          && str_contains($fj, "'sounds', 'descriptions'];") && str_contains($fj, "['acc-descs-public', 'descriptions_public'], ['acc-credit-public', 'content_credit_public']"));
    $aj = $src('assets/js/app.js');
    check('the Info panel\'s script: the chain with the icon library\'s arrow, Edit and Delete beside Propose (classes of their own), the kind sent',
          str_contains($aj, "i.className = 'bi bi-arrow-right';") && str_contains($aj, "button('info-desc-edit'") && str_contains($aj, "button('info-desc-delete'")
          && str_contains($aj, "button('info-desc-open'") && str_contains($aj, "kind: mode === 'edit' ? 'edit' : 'rewrite'") && str_contains($aj, "postJson('content_delete'"));
    check('its words reach the public pages (js.descs. in LANG_JS_PUBLIC); the delete is in the audit log\'s Content group',
          in_array('js.descs.', LANG_JS_PUBLIC, true) && auditGroupOf('content.delete') === 'content');
    check('the layout loads the script with accounts and descriptions on (the name switch lives there)',
          str_contains($src('templates/layout.php'), "|| (usersEnabled(\$cfg) && function_exists('contentEnabled') && contentEnabled(\$cfg))"));

    /* ── 16. the replaced versions: the newest ten of each description, nothing else (1.70.0) ──────── */
    // "We do not keep every edit" — the owner. Every applied proposal keeps a copy of the text it replaced (a
    // `rejected` row with CONTENT_ARCHIVE_NOTE), and nothing ever read them back. contentArchivePrune(), run by
    // contentEditApply() right after that copy goes in, keeps the newest CONTENT_ARCHIVE_KEEP of THAT description
    // and deletes its older ones — never another description's, never a proposal waiting, applied, turned down or
    // withdrawn, and never by the migration (who_test.php §1 counts fifteen before and after it).
    check('ten are kept per description', CONTENT_ARCHIVE_KEEP === 10);
    $A1 = str_repeat('c0e9', 10); $A2 = str_repeat('c0ea', 10); $A3 = str_repeat('c0eb', 10); $A4 = str_repeat('c0ec', 10);
    foreach ([$A1 => 'Archive one', $A3 => 'Archive three'] as $h => $nm) {
        $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, banned, probe_status) VALUES (?, ?, 'admin', NOW(), 0, 'passed')")->execute([$h, $nm]);
    }
    foreach ([$A2 => 'Archive two', $A4 => 'Archive four'] as $h => $nm) {
        $db->prepare("INSERT INTO index_hashes (info_hash, name, meta_status) VALUES (?, ?, 'done')")->execute([$h, $nm]);
    }
    $home = [];
    foreach ([$A1, $A2, $A3, $A4] as $h) {
        contentAttach($db, $cfgE, $h, ['description' => 'The first words of ' . $h, 'description_format' => 'bbcode'], $A, '127.0.0.1');
        $rec = contentRecordFor($db, $h);
        contentApprove($db, $cfgE, $rec['kind'], (int)$rec['id']);
        $home[$h] = contentRecordFor($db, $h);
    }
    $col = fn(string $h): string => $home[$h]['kind'] === 'wl' ? 'whitelist_id' : 'hash_content_id';
    /** Replaced versions as an older install would hold them: $n rows, a day apart, oldest first. */
    $seed = function (string $h, int $n, string $status = 'rejected', ?string $note = CONTENT_ARCHIVE_NOTE) use ($db, $home): array {
        $ids = [];
        $ins = $db->prepare("INSERT INTO wl_content_edits (whitelist_id, hash_content_id, info_hash, description, description_format, status, note, ip, user_id, created_at, reviewed_at)
                             VALUES (?, ?, ?, ?, 'bbcode', ?, ?, '', NULL, ?, ?)");
        for ($i = 0; $i < $n; $i++) {
            $at = date('Y-m-d H:i:s', strtotime('2025-01-01 00:00:00') + $i * 86400);
            $ins->execute([$home[$h]['kind'] === 'wl' ? (int)$home[$h]['id'] : null, $home[$h]['kind'] === 'idx' ? (int)$home[$h]['id'] : null,
                           $h, 'version ' . $i, $status, $note, $at, $at]);
            $ids[] = (int)$db->lastInsertId();
        }
        return $ids;
    };
    $arch = function (string $h) use ($db, $col, $home): array {
        $st = $db->prepare("SELECT id FROM wl_content_edits WHERE `" . $col($h) . "` = ? AND status = 'rejected' AND note = ? ORDER BY id");
        $st->execute([(int)$home[$h]['id'], CONTENT_ARCHIVE_NOTE]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };
    /** Every row of the description that is NOT a kept version, as "status|note" => how many. */
    $others = function (string $h) use ($db, $col, $home): array {
        $st = $db->prepare("SELECT status, COALESCE(note, '-') AS note, COUNT(*) AS n FROM wl_content_edits WHERE `" . $col($h) . "` = ?
                              AND NOT (status = 'rejected' AND note <=> ?) GROUP BY status, note");
        $st->execute([(int)$home[$h]['id'], CONTENT_ARCHIVE_NOTE]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['status'] . '|' . $r['note']] = (int)$r['n'];
        ksort($out);
        return $out;
    };
    $old1 = $seed($A1, 14);
    $seed($A1, 1, 'rejected', null);                          // a proposal a moderator turned down
    $seed($A1, 1, 'rejected', CONTENT_WITHDRAWN_NOTE);        // one a delete withdrew
    $seed($A1, 1, 'applied', null);                           // one applied long ago
    $waiting = $seed($A1, 1, 'pending', null);                // one still waiting
    $old3 = $seed($A3, 12);
    $old2 = $seed($A2, 11);
    $old4 = $seed($A4, 2);
    $others1 = $others($A1);
    // What an install could lose at most on its next applies — the query the release notes give, here held to
    // the fixtures (this run's hashes only): every description's archive beyond its newest nine, because the next
    // apply adds one and keeps ten.
    $lose = $db->query("SELECT COUNT(*) AS descriptions, COALESCE(SUM(n - 9), 0) AS rows_at_most FROM (
                          SELECT COUNT(*) AS n FROM wl_content_edits WHERE status = 'rejected' AND note = 'replaced by a later proposal'
                             AND info_hash LIKE 'c0e%' GROUP BY whitelist_id, hash_content_id HAVING COUNT(*) > 9) t")->fetch(PDO::FETCH_ASSOC);
    check('the count of what the next applies could delete, on these fixtures: 3 descriptions over nine (14, 12, 11), 5 + 3 + 2 = 10 rows at most',
          (int)$lose['descriptions'] === 3 && (int)$lose['rows_at_most'] === 10, json_encode($lose));
    contentAttach($db, $cfgE, $A1, ['description' => 'A rewrite of archive one.', 'description_format' => 'bbcode'], $B, '127.0.0.2');
    check('fixtures: fourteen kept versions, a turned-down, a withdrawn, an applied and a waiting proposal, and a new one to apply',
          count($arch($A1)) === 14 && count($old1) === 14 && $eEdit($A1) !== null);
    contentEditApply($db, $cfgE, $eEdit($A1));
    $now1 = $arch($A1);
    check('applied: the replaced text goes in, and the description keeps its newest ten versions — the five oldest are gone',
          count($now1) === 10 && array_slice($now1, 0, 9) === array_slice($old1, 5) && !array_intersect($now1, array_slice($old1, 0, 5)) && end($now1) > end($old1),
          json_encode(['kept' => $now1, 'old' => $old1]));
    $expect = $others1;
    $expect['applied|-'] = ($expect['applied|-'] ?? 0) + 1;   // the one just applied
    check('… every other row of that description as it was — the turned-down, the withdrawn, the applied, the waiting (and one more applied: this one)',
          $others($A1) === $expect && count($others1) === 4, json_encode([$others($A1), $expect]));
    $st = $db->prepare("SELECT status FROM wl_content_edits WHERE id = ?");
    $st->execute([$waiting[0]]);
    check('… the proposal still waiting is still waiting', $st->fetchColumn() === 'pending');
    check('… and another description\'s twelve kept versions are not touched by this one\'s apply', $arch($A3) === $old3);
    contentAttach($db, $cfgE, $A1, ['description' => 'A second rewrite of archive one.', 'description_format' => 'bbcode'], $C, '127.0.0.3');
    contentEditApply($db, $cfgE, $eEdit($A1));
    $now1b = $arch($A1);
    check('applied again: still ten, the oldest of them gone and the newest in', count($now1b) === 10 && $now1b[0] === $now1[1] && !in_array($now1[0], $now1b, true));
    check('contentArchivePrune() with nothing over the ten deletes nothing (and says so)', contentArchivePrune($db, 'wl', (int)$home[$A1]['id']) === 0 && count($arch($A1)) === 10);
    contentAttach($db, $cfgE, $A2, ['description' => 'A rewrite of archive two.', 'description_format' => 'bbcode'], $B, '127.0.0.2');
    contentEditApply($db, $cfgE, $eEdit($A2));
    $now2 = $arch($A2);
    check('the other home (a torrent only seen, hash_content): eleven + the new one → the newest ten, the two oldest gone',
          count($now2) === 10 && array_slice($now2, 0, 9) === array_slice($old2, 2), json_encode($now2));
    contentAttach($db, $cfgE, $A4, ['description' => 'A rewrite of archive four.', 'description_format' => 'bbcode'], $B, '127.0.0.2');
    contentEditApply($db, $cfgE, $eEdit($A4));
    check('a description with fewer than ten kept versions loses none (two + the new one = three)', count($arch($A4)) === 3 && array_slice($arch($A4), 0, 2) === $old4);
    // The pending count, on both homes, through the v82 keys.
    $plan = function (string $colName, int $id) use ($db): array {
        $st = $db->prepare("EXPLAIN SELECT COUNT(*) FROM wl_content_edits WHERE `$colName` = ? AND status = 'pending'");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['type' => (string)($r['type'] ?? ''), 'possible' => (string)($r['possible_keys'] ?? ''), 'key' => (string)($r['key'] ?? '')];
    };
    $pw = $plan('whitelist_id', (int)$home[$A1]['id']); $ph = $plan('hash_content_id', (int)$home[$A2]['id']);
    check('the pending count of one description can use its home\'s key — whitelist_id and status, hash_content_id and status — and reads no whole table',
          str_contains($pw['possible'], 'idx_edits_wl_status') && str_contains($ph['possible'], 'idx_edits_hc_status') && $pw['type'] !== 'ALL' && $ph['type'] !== 'ALL',
          json_encode([$pw, $ph]));
    $readme = $src('README.md');
    check('the README says what is true: the replaced version is kept (the newest ten), and nothing on the site puts one back',
          !str_contains($readme, 'Applying keeps the version it replaces, so it can be undone.') && str_contains($readme, 'the newest ten per description')
          && str_contains($readme, 'nothing in the panel reads them back or puts one back'));
    $enL = include $root . '/lang/en.php'; $plL = include $root . '/lang/pl.php';
    check('… and so do the panel\'s words: no "can be undone", no "put it back by accepting it later", in either language',
          !preg_grep('/can be undone|put it back by accepting|można to cofnąć|możesz ją przywrócić/u', [$enL['js.wl.apply_title'], $enL['js.wl.apply_rewrite_after'], $enL['a.wl.rv_edits_note'],
                                                                                              $plL['js.wl.apply_title'], $plL['js.wl.apply_rewrite_after'], $plL['a.wl.rv_edits_note']])
          && str_contains($enL['js.wl.apply_title'], 'newest ten') && str_contains($plL['js.wl.apply_title'], 'dziesięć najnowszych'));
    check('… and contentEditApply() calls the prune right after the kept copy goes in',
          preg_match('/CONTENT_ARCHIVE_NOTE, \$cur\[.content_user_id.\]\]\);\s*contentArchivePrune\(\$db, \$kind, \(int\)\$e\[.target_id.\]\);/', $src('includes/content.php')) === 1);
} finally {
    $eClean();
    foreach (['member', 'moderator', 'admin'] as $slug) {
        if ($eBefore[$slug] !== '') $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = ?")->execute([$eBefore[$slug], $slug]);
    }
    if ($eBefore['marker'] === false) $db->exec("DELETE FROM settings WHERE `key` = 'schema_grant_v81_content'");
    else $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('schema_grant_v81_content', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([(string)$eBefore['marker']]);
    $db->exec("DELETE FROM audit_log WHERE id > " . (int)$eBefore['audit']);
    foreach ($tmpE as $tf) @unlink($tf);
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
