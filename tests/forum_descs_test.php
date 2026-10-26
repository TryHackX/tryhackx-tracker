<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/auth.php',
          'includes/api_auth.php', 'includes/audit.php', 'includes/lang.php', 'includes/settings_catalog.php',
          'includes/forumdescs.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 600)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
$cfgF = array_merge($cfg, ['users_enabled' => '1', 'wl_allow_description' => '1', 'wl_allow_source_url' => '1',
    'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1', 'desc_allow_flarum' => '1', 'desc_max_chars' => '4000',
    'desc_max_images' => '10', 'desc_max_links' => '30', 'forum_desc_conflict' => 'proposal',
    'images_remote_scan_at' => '0']);

$H = fn(int $i): string => str_repeat('fd', 19) . sprintf('%02x', $i);
$tidy = function () use ($db): void {
    $db->exec("DELETE FROM forum_descs WHERE info_hash LIKE 'fdfdfd%'");
    $db->exec("DELETE FROM wl_content_edits WHERE info_hash LIKE 'fdfdfd%'");
    $db->exec("DELETE FROM whitelist WHERE info_hash LIKE 'fdfdfd%'");
    $db->exec("DELETE FROM user_identities WHERE external_id LIKE 'fdtest-%'");
    $db->exec("DELETE FROM api_clients WHERE label LIKE 'fdtest%'");
    foreach ($db->query("SELECT id FROM users WHERE username = 'fdlinked'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
};
$tidy();

try {
    $cols = array_column($db->query("SHOW COLUMNS FROM api_clients")->fetchAll(PDO::FETCH_ASSOC), 'Default', 'Field');
    check('api_clients.describe_mode exists and is off unless somebody says otherwise', ($cols['describe_mode'] ?? null) === 'off',
          var_export($cols['describe_mode'] ?? 'missing', true));
    check('api_clients.describe_accounts exists', array_key_exists('describe_accounts', $cols));
    $fcols = array_column($db->query("SHOW COLUMNS FROM forum_descs")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    check('forum_descs has the columns the import reads', !array_diff(['info_hash', 'client_id', 'discussion_id', 'post_id', 'url',
          'author_ext_id', 'author_name', 'author_user_id', 'forum_at', 'forum_sha', 'forum_chars', 'import_sha', 'state', 'note', 'edit_id'], $fcols),
          implode(',', $fcols));
    $schemaSrc = $src('includes/schema.php');
    check('describe_mode is in the schema twice (fresh install and upgrade)', substr_count($schemaSrc, 'describe_mode') >= 2);
    check('the schema is at least 97', TRACKER_SCHEMA_VERSION >= 97 && (int)($cfg['schema_version'] ?? 0) >= 97, (string)($cfg['schema_version'] ?? ''));
    check('the conflict policy is seeded as a proposal, and no length setting of the tracker\'s own is',
          str_contains($schemaSrc, "'forum_desc_conflict'         => 'proposal',") && !str_contains($schemaSrc, 'forum_desc_max_chars'));
    check('the route is v1/whitelist/describe and its file exists',
          str_contains($src('api.php'), "'v1/whitelist/describe'      => 'api/v1/whitelist_describe.php'") && is_file($root . '/api/v1/whitelist_describe.php'));
    check('the endpoint asks for the whitelist scope and its own body cap',
          str_contains($src('api/v1/whitelist_describe.php'), "apiRequireScope(\$client, 'whitelist')")
          && str_contains($src('api/v1/whitelist_describe.php'), 'apiReadRawBody(FORUM_DESC_MAX_BODY)'));
    check('the ping says the key\'s mode and the limits', str_contains($src('api/v1/whitelist_ping.php'), "'describe' =>")
          && str_contains($src('api/v1/whitelist_ping.php'), "'describe_max_chars' =>"));
    check('settings save the policy and check the choice', str_contains($src('api/admin/save_settings.php'), "'forum_desc_conflict',")
          && str_contains($src('api/admin/save_settings.php'), 'FORUM_DESC_CONFLICTS')
          && !str_contains($src('api/admin/save_settings.php') . $src('templates/admin/settings.php'), 'forum_desc_max_chars'));
    check('the policy has search words', isset(settingsCatalogKeywords()['forum_desc_conflict']));
    check('the endpoint takes the forum\'s own limit from the request', str_contains($src('api/v1/whitelist_describe.php'), "forumDescLimit(\$cfg, \$payload['max_chars'] ?? null)"));

    check('a guide for a key that sends descriptions says so', str_contains(apiClientDocsUrl('whitelist', true, [], false, 'queue'), '&describe=queue')
          && str_contains(apiClientDocsUrl('all', false, [], false, 'publish'), '&describe=publish'));
    check('… and a key that cannot submit, or sends none, gets no such parameter',
          !str_contains(apiClientDocsUrl('abuse', true, [], false, 'queue'), 'describe=') && !str_contains(apiClientDocsUrl('whitelist', true, []), 'describe='));

    check('a mode nobody chose is off', forumDescMode([]) === 'off' && forumDescMode(['describe_mode' => 'publish ']) === 'off'
          && forumDescMode(['describe_mode' => 'queue']) === 'queue');
    check('descriptions switched off on the site switch the endpoint off too',
          !forumDescEnabled(array_merge($cfgF, ['wl_allow_description' => '0']), ['describe_mode' => 'publish'])
          && forumDescEnabled($cfgF, ['describe_mode' => 'publish']) && !forumDescEnabled($cfgF, ['describe_mode' => 'off']));
    check('the conflict policy defaults to a proposal', forumDescConflict([]) === 'proposal' && forumDescConflict(['forum_desc_conflict' => 'x']) === 'proposal'
          && forumDescConflict(['forum_desc_conflict' => 'keep']) === 'keep');
    check('the limit is the forum\'s own, up to the most the tracker takes', forumDescLimit($cfgF, 500000) === 500000
          && forumDescLimit($cfgF, '63000') === 63000 && forumDescLimit($cfgF, 16000000) === FORUM_DESC_CHARS_CAP && FORUM_DESC_CHARS_CAP === 1000000);
    check('… and without one, the limit of descriptions written here', forumDescLimit($cfgF, null) === 4000 && forumDescLimit($cfgF, 0) === 4000
          && forumDescLimit($cfgF, 'x') === 4000 && forumDescLimit(array_merge($cfgF, ['desc_max_chars' => '400000']), null) === 400000);

    check('a topic without a discussion or a post is no topic', forumDescTopic(null) === null && forumDescTopic(['discussion_id' => 5]) === null
          && forumDescTopic(['discussion_id' => 0, 'post_id' => 3]) === null);
    $t = forumDescTopic(['discussion_id' => '12', 'post_id' => 34, 'url' => 'javascript:alert(1)',
        'author' => ['id' => 9, 'name' => "Ka\x07sia\u{202E}x  "], 'edited_at' => 1790000000, 'content' => "a\r\nb\rc",
        'hashes' => [strtoupper($H(1)), $H(1), 'nothex', 7]]);
    check('a bad address is dropped, the name loses its control characters', $t !== null && $t['url'] === '' && $t['author_name'] === 'Ka sia x'
          && $t['author_id'] === '9', json_encode($t));
    check('line breaks are made one kind and counted', $t['content'] === "a\nb\nc" && $t['chars'] === 5);
    check('hashes are lower-cased and given once; what is not one is kept to be answered', $t['hashes'] === [$H(1), 'nothex', ''], json_encode($t['hashes']));
    check('a unix time and an ISO time both read; a future one is now at most',
          $t['edited_at'] === date('Y-m-d H:i:s', 1790000000)
          && forumDescTopic(['discussion_id' => 1, 'post_id' => 1, 'edited_at' => '2026-10-20T10:00:00+00:00'])['edited_at'] === date('Y-m-d H:i:s', strtotime('2026-10-20T10:00:00+00:00'))
          && strtotime((string)forumDescTopic(['discussion_id' => 1, 'post_id' => 1, 'edited_at' => '2999-01-01T00:00:00Z'])['edited_at']) <= time());
    check('a topic sent without its text is measured by its declared length',
          forumDescTopic(['discussion_id' => 1, 'post_id' => 1, 'content' => null, 'chars' => 600000])['chars'] === 600000);

    $mk = function (string $label, string $scope = 'whitelist', string $mode = 'publish') use ($db): array {
        $c = apiClientCreate($db, $label, $scope);
        $db->prepare("UPDATE api_clients SET describe_mode = ? WHERE id = ?")->execute([$mode, (int)$c['id']]);
        return ['id' => (int)$c['id']];
    };
    $client = function (int $id) use ($db): array {
        $st = $db->prepare("SELECT id, label, key_id, scope, enabled, auto_approve, abuse_auto_block, required_fields, describe_mode, describe_accounts
                              FROM api_clients WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    };
    $k1 = $mk('fdtest-one')['id'];
    $k2 = $mk('fdtest-two')['id'];
    $kb = $mk('fdtest-bridge', 'users', 'off')['id'];
    $reg = function (int $i, ?int $by, string $source = 'forum', int $banned = 0) use ($db, $H): int {
        $db->prepare("INSERT INTO whitelist (info_hash, name, source, api_client_id, created_at, banned, probe_status) VALUES (?, ?, ?, ?, NOW(), ?, 'passed')")
           ->execute([$H($i), 'Forum release ' . $i, $source, $by, $banned]);
        return (int)$db->lastInsertId();
    };
    $topic = function (int $disc, ?string $content, array $hashes, array $over = []): array {
        return forumDescTopic(array_merge(['discussion_id' => $disc, 'post_id' => $disc + 1000, 'url' => 'https://forum.fd.test/d/' . $disc . '-release',
            'author' => ['id' => 'fdtest-42', 'name' => 'Kasia'], 'edited_at' => '2026-10-20T10:00:00+00:00', 'format' => 'flarum',
            'content' => $content, 'chars' => $content !== null ? mb_strlen($content) : 0, 'hashes' => $hashes], $over));
    };
    $imp = fn(int $key, array $t, string $hash, ?array $c = null): array => forumDescImport($db, $c ?? $cfgF, $client($key), $t, $hash, 5000);
    $wl = function (string $hash) use ($db): array {
        $st = $db->prepare("SELECT id, description, description_format, content_status, content_user_id, content_credits, source_url FROM whitelist WHERE info_hash = ?");
        $st->execute([$hash]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $archives = function (int $id) use ($db): int {
        $st = $db->prepare("SELECT COUNT(*) FROM wl_content_edits WHERE whitelist_id = ? AND status = 'rejected' AND note = ?");
        $st->execute([$id, CONTENT_ARCHIVE_NOTE]);
        return (int)$st->fetchColumn();
    };
    $pending = function (string $hash) use ($db): array {
        $st = $db->prepare("SELECT * FROM wl_content_edits WHERE info_hash = ? AND status = 'pending' ORDER BY id");
        $st->execute([$hash]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $setMode = fn(int $key, string $mode) => $db->prepare("UPDATE api_clients SET describe_mode = ? WHERE id = ?")->execute([$mode, $key]);

    check('a hash nobody registered is not_registered', $imp($k1, $topic(10, 'Text', [$H(10)]), $H(10))['status'] === 'not_registered');
    $reg(11, $k2);
    check('a hash another key registered is not_registered', $imp($k1, $topic(11, 'Text', [$H(11)]), $H(11))['status'] === 'not_registered');
    $reg(12, null, 'web');
    check('a hash somebody registered on the site is not_registered', $imp($k1, $topic(12, 'Text', [$H(12)]), $H(12))['status'] === 'not_registered');
    $reg(13, $k1, 'forum', 1);
    check('a banned hash is banned', $imp($k1, $topic(13, 'Text', [$H(13)]), $H(13))['status'] === 'banned');
    check('an entry that is not a hash is invalid', $imp($k1, $topic(13, 'Text', ['nothex']), 'nothex')['status'] === 'invalid');

    $id1 = $reg(1, null, 'forum');
    $text1 = "[b]Release one[/b]\n\nA first description.";
    $r = $imp($k1, $topic(1, "  " . $text1 . "\n", [$H(1)]), $H(1));
    $w = $wl($H(1));
    $f = forumDescFor($db, $H(1));
    check('a row the forum registered before keys recorded themselves takes a description: created', $r['status'] === 'created', json_encode($r));
    check('… the text as the author wrote it, in the flarum format, published', ($w['description'] ?? '') === $text1
          && $w['description_format'] === 'flarum' && $w['content_status'] === 'approved', json_encode($w));
    check('… with no account to credit, no author of record and no chain', $w['content_user_id'] === null && $w['content_credits'] === null);
    check('… and the topic remembered: link, name, what was written', $f !== null && $f['url'] === 'https://forum.fd.test/d/1-release'
          && $f['author_name'] === 'Kasia' && $f['state'] === 'imported' && $f['import_sha'] === forumDescSha('flarum', $text1)
          && (int)$f['discussion_id'] === 1 && (int)$f['post_id'] === 1001, json_encode($f));
    check('the same text again writes nothing: unchanged', $imp($k1, $topic(1, "  " . $text1 . "\n", [$H(1)]), $H(1))['status'] === 'unchanged'
          && $wl($H(1))['description'] === $text1 && $archives($id1) === 0);
    $text1b = $text1 . "\n\nEdited on the forum.";
    $r = $imp($k1, $topic(1, $text1b, [$H(1)]), $H(1));
    check('a new version over a description nobody changed here: updated, published, the old one kept in the history',
          $r['status'] === 'updated' && $wl($H(1))['description'] === $text1b && $wl($H(1))['content_status'] === 'approved' && $archives($id1) === 1,
          json_encode([$r, $archives($id1)]));
    $info = forumDescInfo($db, $cfgF, $H(1), contentRecordFor($db, $H(1)), false);
    check('the Info panel shows it as the forum\'s, with the forum name and the topic',
          $info !== null && $info['shown'] && $info['author'] === 'Kasia' && $info['url'] === 'https://forum.fd.test/d/1-release'
          && $info['state'] === null, json_encode($info));

    $setMode($k1, 'queue');
    $id2 = $reg(2, $k1);
    $r = $imp($k1, $topic(2, 'Queued text', [$H(2)]), $H(2));
    check('in the queue mode a first description waits: queued', $r['status'] === 'queued' && $wl($H(2))['content_status'] === 'pending', json_encode($r));
    check('… and while it waits a new version just takes its place: updated, still waiting',
          $imp($k1, $topic(2, 'Queued text v2', [$H(2)]), $H(2))['status'] === 'updated'
          && $wl($H(2))['description'] === 'Queued text v2' && $wl($H(2))['content_status'] === 'pending');
    contentReject($db, $cfgF, 'wl', $id2, 'not yet');
    check('a turned-down description comes back to the queue with the next version: queued',
          $imp($k1, $topic(2, 'Queued text v3', [$H(2)]), $H(2))['status'] === 'queued' && $wl($H(2))['content_status'] === 'pending');
    contentApprove($db, $cfgF, 'wl', $id2);
    $r = $imp($k1, $topic(2, 'Queued text v4', [$H(2)]), $H(2));
    $p = $pending($H(2));
    check('a new version of a published description, in the queue mode, is a proposal; the description stays',
          $r['status'] === 'proposed' && count($p) === 1 && $p[0]['description'] === 'Queued text v4' && $p[0]['kind'] === 'rewrite'
          && $p[0]['user_id'] === null && $wl($H(2))['description'] === 'Queued text v3', json_encode([$r, $p]));
    $r = $imp($k1, $topic(2, 'Queued text v5', [$H(2)]), $H(2));
    $p2 = $pending($H(2));
    check('… and a newer one takes the same proposal\'s place rather than filing another',
          $r['status'] === 'proposed' && count($p2) === 1 && (int)$p2[0]['id'] === (int)$p[0]['id'] && $p2[0]['description'] === 'Queued text v5');
    check('the moderator\'s note on the Info panel says a version waits', (forumDescInfo($db, $cfgF, $H(2), contentRecordFor($db, $H(2)), true)['state'] ?? null) === 'proposed');
    contentEditApply($db, $cfgF, contentEditById($db, (int)$p2[0]['id']));
    $f = forumDescFor($db, $H(2));
    check('applied, the proposal is the forum\'s own text again', $wl($H(2))['description'] === 'Queued text v5' && $f['state'] === 'imported'
          && $f['edit_id'] === null && $f['import_sha'] === forumDescSha('flarum', 'Queued text v5'), json_encode($f));
    check('… so the same text answers unchanged', $imp($k1, $topic(2, 'Queued text v5', [$H(2)]), $H(2))['status'] === 'unchanged');

    $setMode($k1, 'publish');
    $id3 = $reg(3, $k1);
    $imp($k1, $topic(3, 'Third v1', [$H(3)]), $H(3));
    $db->prepare("UPDATE whitelist SET description = 'Changed here' WHERE id = ?")->execute([$id3]);
    $r = $imp($k1, $topic(3, 'Third v2', [$H(3)]), $H(3));
    check('a description changed here is never overwritten by default: proposed', $r['status'] === 'proposed'
          && $wl($H(3))['description'] === 'Changed here' && count($pending($H(3))) === 1, json_encode($r));
    $cfgK = array_merge($cfgF, ['forum_desc_conflict' => 'keep']);
    $r = $imp($k1, $topic(3, 'Third v3', [$H(3)]), $H(3), $cfgK);
    check('the keep policy leaves it and marks the difference', $r['status'] === 'kept' && $wl($H(3))['description'] === 'Changed here'
          && forumDescFor($db, $H(3))['state'] === 'drift');
    $mod = forumDescInfo($db, $cfgK, $H(3), contentRecordFor($db, $H(3)), true);
    $pub = forumDescInfo($db, $cfgK, $H(3), contentRecordFor($db, $H(3)), false);
    check('a moderator is told of the difference, a reader is not', ($mod['state'] ?? null) === 'drift' && $pub !== null && $pub['state'] === null,
          json_encode([$mod, $pub]));
    $cfgR = array_merge($cfgF, ['forum_desc_conflict' => 'replace']);
    $before = $archives($id3);
    $r = $imp($k1, $topic(3, 'Third v4', [$H(3)]), $H(3), $cfgR);
    check('the replace policy replaces it, the changed version kept in the history, the waiting proposal withdrawn',
          $r['status'] === 'replaced' && $wl($H(3))['description'] === 'Third v4' && $archives($id3) === $before + 1 && !$pending($H(3))
          && forumDescFor($db, $H(3))['state'] === 'imported', json_encode([$r, $archives($id3), $before]));
    $st = $db->prepare("SELECT COUNT(*) FROM wl_content_edits WHERE info_hash = ? AND note = ?");
    $st->execute([$H(3), FORUM_DESC_SUPERSEDED]);
    check('… the withdrawn proposal says why', (int)$st->fetchColumn() === 1);
    $db->prepare("UPDATE whitelist SET description = 'Changed here again' WHERE id = ?")->execute([$id3]);
    $setMode($k1, 'queue');
    check('with the queue mode, replacing goes through a moderator: proposed',
          $imp($k1, $topic(3, 'Third v5', [$H(3)]), $H(3), $cfgR)['status'] === 'proposed' && $wl($H(3))['description'] === 'Changed here again');
    $setMode($k1, 'publish');

    $id4 = $reg(4, $k1);
    $db->prepare("UPDATE whitelist SET description = 'Written here first', description_format = 'bbcode', content_status = 'approved' WHERE id = ?")->execute([$id4]);
    $r = $imp($k1, $topic(4, 'Forum text', [$H(4)]), $H(4), $cfgR);
    check('a description written here is never replaced, whatever the policy: proposed', $r['status'] === 'proposed'
          && $wl($H(4))['description'] === 'Written here first', json_encode($r));
    check('… and the keep policy keeps it', $imp($k1, $topic(4, 'Forum text v2', [$H(4)]), $H(4), $cfgK)['status'] === 'kept');

    $id5 = $reg(5, $k1);
    $r = $imp($k1, $topic(5, str_repeat('x', 6000), [$H(5)]), $H(5));
    check('a first post over the limit is not cut: too_long, nothing written', $r['status'] === 'too_long' && $r['detail'] === '6000/5000'
          && ($wl($H(5))['description'] ?? null) === null && forumDescFor($db, $H(5))['state'] === 'too_long', json_encode($r));
    check('… and one sent without its text is too_long as well', $imp($k1, $topic(5, null, [$H(5)], ['chars' => 700000]), $H(5))['status'] === 'too_long');
    $pubTl = forumDescInfo($db, $cfgF, $H(5), contentRecordFor($db, $H(5)), false);
    check('a reader is shown that the description is on the forum, with the numbers', ($pubTl['state'] ?? null) === 'too_long'
          && $pubTl['chars'] === 700000 && $pubTl['max'] === 5000 && $pubTl['url'] !== null, json_encode($pubTl));
    $r = $imp($k1, $topic(5, 'Short enough now', [$H(5)]), $H(5));
    check('a shorter version later goes in as the first description', $r['status'] === 'created' && $wl($H(5))['description'] === 'Short enough now');

    $id6 = $reg(6, $k1);
    $r = $imp($k1, $topic(6, str_repeat("[img]https://pics.fd.test/a.png[/img]\n", 12), [$H(6)]), $H(6));
    check('the forum caps no pictures, so neither does its import: more than the site\'s own descriptions allow is taken',
          $r['status'] === 'created' && substr_count((string)$wl($H(6))['description'], '[img]') === 12, json_encode($r));
    check('an empty first post is invalid', $imp($k1, $topic(6, "  \n ", [$H(6)]), $H(6))['status'] === 'invalid');
    check('a format switched off is invalid', $imp($k1, $topic(6, 'Plain', [$H(6)]), $H(6), array_merge($cfgF, ['desc_allow_flarum' => '0']))['status'] === 'invalid');
    $modInv = forumDescInfo($db, $cfgF, $H(6), contentRecordFor($db, $H(6)), true);
    check('a moderator reads why it was not brought over', ($modInv['state'] ?? null) === 'invalid' && $modInv['note'] !== null, json_encode($modInv));

    $id7 = $reg(7, $k1);
    $imp($k1, $topic(7, 'Seventh', [$H(7)]), $H(7));
    contentClear($db, 'wl', $id7);
    check('a description deleted here does not come back with the same text: removed',
          $imp($k1, $topic(7, 'Seventh', [$H(7)]), $H(7))['status'] === 'removed' && ($wl($H(7))['description'] ?? null) === null
          && forumDescFor($db, $H(7))['state'] === 'removed');
    $r = $imp($k1, $topic(7, 'Seventh, edited', [$H(7)]), $H(7));
    check('… an edited post does, and only into the queue', $r['status'] === 'queued' && $wl($H(7))['content_status'] === 'pending', json_encode($r));

    $setMode($k1, 'queue');
    $id8 = $reg(8, $k1);
    $imp($k1, $topic(8, 'Eighth', [$H(8)]), $H(8));
    contentApprove($db, $cfgF, 'wl', $id8);
    $imp($k1, $topic(8, 'Eighth v2', [$H(8)]), $H(8));
    $p8 = $pending($H(8));
    contentEditReject($db, $cfgF, contentEditById($db, (int)$p8[0]['id']));
    check('a proposal a moderator turned down is not filed again with the same text: declined',
          forumDescFor($db, $H(8))['state'] === 'declined' && $imp($k1, $topic(8, 'Eighth v2', [$H(8)]), $H(8))['status'] === 'declined');
    $r = $imp($k1, $topic(8, 'Eighth v3', [$H(8)]), $H(8));
    check('… a newer version is', $r['status'] === 'proposed' && count($pending($H(8))) === 1 && (int)$pending($H(8))[0]['id'] !== (int)$p8[0]['id']);
    $setMode($k1, 'publish');

    $reg(9, null, 'forum');
    $imp($k1, $topic(90, 'Ninth', [$H(9)]), $H(9));
    check('a hash already described from another topic is other_topic', $imp($k1, $topic(91, 'Ninth elsewhere', [$H(9)]), $H(9))['status'] === 'other_topic');
    check('… and from another key too', $imp($k2, $topic(90, 'Ninth again', [$H(9)]), $H(9))['status'] === 'other_topic');
    $reg(14, $k1);
    $imp($k1, $topic(140, str_repeat('y', 6000), [$H(14)]), $H(14));
    check('a topic that never brought anything over leaves the hash to another topic',
          $imp($k1, $topic(141, 'Fourteenth', [$H(14)]), $H(14))['status'] === 'created' && (int)forumDescFor($db, $H(14))['discussion_id'] === 141);

    $uid = (int)(userCreate($db, $cfgF, 'fdlinked', 'fdlinked@example.org', bin2hex(random_bytes(8)) . 'Aa1!', '127.0.0.1')['user']['id'] ?? 0);
    $db->prepare("INSERT INTO user_identities (user_id, client_id, provider, external_id) VALUES (?, ?, 'fd', 'fdtest-77')")->execute([$uid, $kb]);
    check('the accounts\' key must be one that runs the bridge', forumDescAccountsClean($db, $kb, $k1) === $kb && forumDescAccountsClean($db, $k2, $k1) === null
          && forumDescAccountsClean($db, $k1, $k1) === null && forumDescAccountsClean($db, 'x', $k1) === null);
    $reg(15, $k1);
    $imp($k1, $topic(15, 'Fifteenth', [$H(15)], ['author' => ['id' => 'fdtest-77', 'name' => 'Linked']]), $H(15));
    check('without the bridge\'s key chosen, a linked author is still the forum name', $wl($H(15))['content_user_id'] === null);
    $db->prepare("UPDATE api_clients SET describe_accounts = ? WHERE id = ?")->execute([$kb, $k1]);
    $reg(16, $k1);
    $imp($k1, $topic(16, 'Sixteenth', [$H(16)], ['author' => ['id' => 'fdtest-77', 'name' => 'Linked']]), $H(16));
    $w16 = $wl($H(16));
    check('with it, the linked account is the author of record and starts the chain', (int)$w16['content_user_id'] === $uid
          && str_contains((string)$w16['content_credits'], '{"u":' . $uid . ',"k":"f"'), json_encode($w16));
    $i16 = forumDescInfo($db, $cfgF, $H(16), contentRecordFor($db, $H(16)), false);
    check('… and the Info panel names the account rather than the forum name', $i16['shown'] && $i16['author'] === null, json_encode($i16));

    $id17 = $reg(17, $k1);
    $remote = 'https://forum.fd.test/assets/files/fd17.png';
    $text17 = "Pictures:\n[img]" . $remote . "[/img]";
    $imp($k1, $topic(17, $text17, [$H(17)]), $H(17));
    $moved = imagesRewrite($db, $remote, '/i/917-10x10.webp');
    $f17 = forumDescFor($db, $H(17));
    check('a picture moved to the site\'s store leaves the description the forum\'s own', $moved >= 1
          && $f17['import_sha'] === forumDescSha('flarum', str_replace($remote, '/i/917-10x10.webp', $text17)), json_encode([$moved, $f17['import_sha']]));
    check('… the same post is unchanged, and a new version is an update, not a conflict',
          $imp($k1, $topic(17, $text17, [$H(17)]), $H(17))['status'] === 'unchanged'
          && $imp($k1, $topic(17, $text17 . "\nMore.", [$H(17)]), $H(17))['status'] === 'updated');

    $fakeSummary = array_fill_keys(array_merge(FORUM_DESC_STATUSES, ['skipped']), 0);
    check('every status the import answers has a place in the summary', count($fakeSummary) === 10
          && !array_diff(['created', 'queued', 'updated', 'replaced', 'proposed', 'kept', 'unchanged', 'too_long', 'invalid'], array_keys($fakeSummary))
          && FORUM_DESC_SKIPPED === ['not_registered', 'banned', 'other_topic', 'removed', 'declined']);
} finally {
    $tidy();
}

echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
