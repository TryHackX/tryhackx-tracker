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
$all = [$REG, $SEEN, $NONE, $BANNED];
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
    check('the hash check reports the words for it', (hashCheckLookup($db, $cfg, $SEEN)['content']['status'] ?? '') === 'pending');
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
    // A third account, created AFTER the grant is taken away: userEffectivePermissions() memoises per
    // user for the length of the process, so Alice would still answer from the permissions she had.
    $mp2 = $mp; $mp2['content.submit'] = false;
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([json_encode($mp2)]);
    userCreate($db, $cfg, 'ctcarol', 'ctcarol@example.org', 'SmokePass123!', '127.0.0.1');
    $db->exec("UPDATE users SET email_verified = 1 WHERE username = 'ctcarol'");
    $carol = $uid('ctcarol');
    $r = contentAttach($db, $cfg, $SEEN, ['description' => 'No permission.', 'description_format' => 'bbcode', 'source_url' => ''], $carol, '127.0.0.1');
    check('the permission asked is the submitter\'s own', empty($r['ok']) && $r['code'] === 403, json_encode($r));
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([json_encode($mp)]);
    $put(['wl_allow_description' => '0', 'wl_allow_source_url' => '0']);
    $r = contentAttach($db, $cfg, $SEEN, ['description' => 'Switched off.', 'description_format' => 'bbcode', 'source_url' => ''], $alice, '127.0.0.1');
    check('with both switches off there is nothing to attach', empty($r['ok']) && $r['code'] === 400 && !contentEnabled($cfg), json_encode($r));
    $put(['wl_allow_description' => '1', 'wl_allow_source_url' => '1', 'wl_content_autopublish' => '1']);
    $r = contentAttach($db, $cfg, $SEEN, ['description' => 'Straight out.', 'description_format' => 'bbcode', 'source_url' => ''], $bob, '127.0.0.2');
    check('autopublish publishes at once', !empty($r['ok']) && $r['saved'] && !$r['pending'] && richtextContentFor($db, $cfg, $SEEN)['author'] === 'ctbob', json_encode($r));
} finally {
    $db->prepare("UPDATE user_groups SET permissions = ? WHERE slug = 'member'")->execute([$memberBefore]);
    foreach ($saved as $k => $v) { if ($v !== null) setSetting($db, $k, $v); }
    $clean();
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
