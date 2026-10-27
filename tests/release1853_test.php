<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/favourites.php', 'includes/usermedia.php', 'includes/images.php', 'includes/auth.php',
          'includes/api_auth.php', 'includes/audit.php', 'includes/lang.php', 'includes/settings_catalog.php',
          'includes/forumdescs.php', 'includes/facts.php', 'includes/tags.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
$cfgF = array_merge($cfg, ['users_enabled' => '1', 'wl_allow_description' => '1', 'wl_allow_source_url' => '1',
    'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1', 'desc_allow_flarum' => '1', 'desc_max_chars' => '4000',
    'desc_max_images' => '10', 'desc_max_links' => '30', 'forum_desc_conflict' => 'proposal', 'images_remote_scan_at' => '0',
    'tags_enabled' => '1', 'tags_forum' => '1', 'tags_forum_review' => 'bypass']);

check('schema 108: the forum map, the forum tags of each torrent and an import switch for each key', TRACKER_SCHEMA_VERSION >= 108
      && schemaTableExists($db, 'tag_forum_map') && schemaTableExists($db, 'hash_forum_tags') && schemaColumnExists($db, 'api_clients', 'tags_import')
      && tagsForumReady($db));
check('settings: tags from the forum on, a tag the forum sends for the first time applies at once',
      ($cfg['tags_forum'] ?? null) === '1' && ($cfg['tags_forum_review'] ?? null) === 'bypass');

$H = fn(int $i): string => str_repeat('fe', 19) . sprintf('%02x', $i);
$keptCounts = $db->query("SELECT `value` FROM settings WHERE `key` = 'facts_counts'")->fetchColumn();
$tidy = function () use ($db, $H): void {
    foreach ([1, 2, 3] as $i) {
        foreach (['forum_descs', 'wl_content_edits', 'whitelist', 'hash_tags', 'hash_tag_blocks', 'hash_forum_tags'] as $tbl) {
            $db->prepare("DELETE FROM $tbl WHERE info_hash = ?")->execute([$H($i)]);
        }
    }
    $db->exec("DELETE m FROM tag_forum_map m JOIN api_clients c ON c.id = m.client_id WHERE c.label LIKE 'ft1853-%'");
    $db->exec("DELETE FROM api_clients WHERE label LIKE 'ft1853-%'");
    $db->exec("DELETE FROM tags WHERE slug IN ('ft-pl', 'ft-ivo', 'ft-nowy', 'ft-czeka')");
};
$tidy();
register_shutdown_function(function () use ($tidy, $db, $keptCounts): void {
    $tidy();
    if ($keptCounts === false) $db->exec("DELETE FROM settings WHERE `key` = 'facts_counts'");
    else $db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('facts_counts', ?)")->execute([$keptCounts]);
});
$db->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('facts_counts', ?)")->execute([json_encode(['at' => time(), 'n' => [],
    'g' => ['dramat' => 'Dramat', 'dramat-historyczny' => 'Dramat historyczny', 'komedia' => 'Komedia', 'akcja' => 'Akcja', 'animacja' => 'Animacja',
            'fantasy' => 'Fantasy', 'kryminal' => 'Kryminał', 'dla-dzieci' => 'Dla dzieci', 'komediodramat' => 'Komediodramat',
            'komedia-kryminalna' => 'Komedia kryminalna', 'animowany' => 'Animowany', 'romans' => 'Romans', 'romantyczne' => 'Romantyczne',
            'surrealistyczny' => 'Surrealistyczny', 'przygodowy' => 'Przygodowy']])]);

$prop = fn(string $name, string $slug): string => tagsForumTarget($db, $name, $slug);
$got = [$prop('Ultra HD (2160p)', 'ultra-hd-2160p'), $prop('Full HD (1080p)', 'full-hd-1080p'), $prop('HD (720p)', 'hd-720p'), $prop('VHS / SD', 'vhs-sd'),
        $prop('BluRay', 'bluray'), $prop('WEB-DL', 'web-dl'), $prop('WEBRip', 'webrip'), $prop('REMUX', 'remux'), $prop('HDR', 'hdr'), $prop('TS', 'ts'), $prop('CAM', 'cam')];
check('proposals: the qualities, sources, HDR and CAM/TS the tracker knows',
      $got === ['q:2160p', 'q:1080p', 'q:720p', 'q:sd', 's:bluray', 's:webdl', 's:webrip', 's:remux', 'h:hdr', 's:cam', 's:cam'], json_encode($got));
$got = [$prop('Dramatyczne', 'dramatyczne'), $prop('Komediowe', 'komediowe'), $prop('Akcji', 'akcji'), $prop('Animowane', 'animowane'),
        $prop('Fantastyczne', 'fantastyczne'), $prop('Kryminalne', 'kryminalne'), $prop('Romantyczne', 'romantyczne'), $prop('Surrealistyczne', 'surrealistyczne'),
        $prop('Przygodowe', 'przygodowe')];
check('… a genre read from the descriptions, whose whole name the word begins with (Dramatyczne is Dramat, Komediowe Komedia — not Dramat historyczny or Komediodramat)',
      $got === ['g:dramat', 'g:komedia', 'g:akcja', 'g:animacja', 'g:fantasy', 'g:kryminal', 'g:romantyczne', 'g:surrealistyczny', 'g:przygodowy'], json_encode($got));
$got = [$prop('PL', 'pl'), $prop('IVO', 'ivo'), $prop('3D / SBS / OU', '3d-sbs-ou'), $prop('Dla młodzieży', 'dla-mlodziezy')];
check('… and anything else a new tag', $got === ['x:pl', 'x:ivo', 'x:3d-sbs-ou', 'x:dla-mlodziezy'], json_encode($got));

$ft = [['slug' => 'full-hd-1080p', 'name' => 'Full HD (1080p)', 'color' => '', 'parent' => null],
       ['slug' => 'dramatyczne', 'name' => 'Dramatyczne', 'color' => '#34495e'],
       ['slug' => 'ft-pl', 'name' => 'FT PL', 'color' => '#ABC'],
       ['slug' => 'ft-ivo', 'name' => 'FT IVO', 'color' => null]];
$tp = forumDescTopic(['discussion_id' => 1, 'post_id' => 2, 'hashes' => [$H(1)],
                      'tags' => array_merge($ft, [['slug' => 'ft-pl', 'name' => 'twice'], ['slug' => ''], 'x', ['slug' => "a\x07b", 'name' => '<b>', 'color' => 'red', 'parent' => 'gatunki']])]);
check('a topic\'s tags: each once, none empty or broken, the colour checked, control characters gone',
      count($tp['tags']) === 5 && $tp['tags'][2]['color'] === '#aabbcc' && $tp['tags'][2]['name'] === 'FT PL'
      && $tp['tags'][4] === ['slug' => 'ab', 'name' => 'b', 'color' => null, 'parent' => 'gatunki'], json_encode($tp['tags']));
check('… a topic without the key changes nothing, an empty list clears, at most 30 a topic',
      forumDescTopic(['discussion_id' => 1, 'post_id' => 2, 'hashes' => [$H(1)]])['tags'] === null
      && forumDescTopic(['discussion_id' => 1, 'post_id' => 2, 'hashes' => [$H(1)], 'tags' => []])['tags'] === []
      && count(tagsForumList(array_map(fn($i) => ['slug' => 's' . $i, 'name' => 'S' . $i], range(1, 40)))) === TAGS_FORUM_MAX && TAGS_FORUM_MAX === 30);

$c = apiClientCreate($db, 'ft1853-one', 'whitelist');
$k = (int)$c['id'];
$db->prepare("UPDATE api_clients SET describe_mode = 'publish' WHERE id = ?")->execute([$k]);
$client = function (int $id) use ($db): array {
    $st = $db->prepare("SELECT id, label, key_id, scope, enabled, auto_approve, abuse_auto_block, required_fields, describe_mode, describe_accounts
                          FROM api_clients WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
};
foreach ([1, 2] as $i) {
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, api_client_id, created_at, banned, probe_status) VALUES (?, ?, 'forum', ?, NOW(), 0, 'passed')")
       ->execute([$H($i), 'Forum tags release ' . $i, $k]);
}
$topic = fn(int $disc, string $hash, ?array $tags, array $over = []): array => forumDescTopic(array_merge(['discussion_id' => $disc, 'post_id' => $disc + 1000,
    'url' => 'https://forum.ft.test/d/' . $disc, 'author' => ['id' => 'ft-1', 'name' => 'Ola'], 'edited_at' => '2026-10-27T10:00:00+00:00',
    'format' => 'flarum', 'content' => 'Opis ' . $disc, 'chars' => mb_strlen('Opis ' . $disc), 'hashes' => [$hash]], $tags !== null ? ['tags' => $tags] : [], $over));
$imp = fn(array $t, string $hash, array $over = []): array => forumDescImport($db, $over + $cfgF, $client($k), $t, $hash, 5000);
$map = function () use ($db, $k): array {
    $st = $db->prepare("SELECT forum_slug, target, state FROM tag_forum_map WHERE client_id = ? ORDER BY forum_slug");
    $st->execute([$k]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$forumTags = function (string $hash) use ($db): array {
    $st = $db->prepare("SELECT tag, origin FROM hash_tags WHERE info_hash = ? AND (origin & 4) <> 0 ORDER BY tag");
    $st->execute([$hash]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
};
$hft = function (string $hash) use ($db): array {
    $st = $db->prepare("SELECT forum_slug FROM hash_forum_tags WHERE info_hash = ? ORDER BY forum_slug");
    $st->execute([$hash]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};

$r1 = $imp($topic(501, $H(1), $ft), $H(1));
check('the description comes in as before, and with the import off the forum\'s tags are only proposals',
      in_array($r1['status'], ['created', 'queued'], true) && $hft($H(1)) === ['dramatyczne', 'ft-ivo', 'ft-pl', 'full-hd-1080p'] && $forumTags($H(1)) === []
      && array_column($map(), 'state', 'forum_slug') === ['dramatyczne' => 'proposed', 'ft-ivo' => 'proposed', 'ft-pl' => 'proposed', 'full-hd-1080p' => 'proposed'],
      json_encode([$r1, $map(), $hft($H(1))]));
check('… each with its match', array_column($map(), 'target', 'forum_slug') === ['dramatyczne' => 'g:dramat', 'ft-ivo' => 'x:ft-ivo', 'ft-pl' => 'x:ft-pl', 'full-hd-1080p' => 'q:1080p']
      && tagsRow($db, 'x:ft-pl') === null, json_encode($map()));

$on = tagsForumDecide($db, $cfgF, 'forum_import', ['client' => $k, 'on' => true]);
check('turning the import on applies nothing that is not approved', $on['ok'] && $forumTags($H(1)) === [], json_encode($on));
$all = tagsForumDecide($db, $cfgF, 'forum_all', ['client' => $k]);
$pl = tagsRow($db, 'x:ft-pl');
check('approving every proposal puts the tags on the torrent, the new ones made in the forum\'s colour',
      $all['ok'] && $all['n'] === 4 && $forumTags($H(1)) === ['g:dramat' => 4, 'q:1080p' => 4, 'x:ft-ivo' => 4, 'x:ft-pl' => 4]
      && ($pl['color'] ?? '') === '#aabbcc' && ($pl['status'] ?? '') === 'approved' && ($pl['origin'] ?? '') === 'forum' && ($pl['name'] ?? '') === 'FT PL',
      json_encode([$all, $forumTags($H(1)), $pl]));
$chips = array_column(tagsForHashes($db, [$H(1)], null, false)[$H(1)]['add'] ?? [], 'l', 'id');
check('the chips: the genre named as in the descriptions, the quality, the new tags', $chips == ['q:1080p' => '1080p', 'g:dramat' => 'Dramat', 'x:ft-pl' => 'FT PL', 'x:ft-ivo' => 'FT IVO'],
      json_encode($chips));
check('the filters find the torrent by the forum\'s genre and by the new tag', in_array($H(1), tagsFilterExplicit($db, ['g:dramat'])['hashes'] ?? [], true)
      && in_array($H(1), tagsFilterExplicit($db, ['x:ft-ivo'])['hashes'] ?? [], true));

$imp($topic(502, $H(2), [['slug' => 'full-hd-1080p', 'name' => 'Full HD (1080p)'], ['slug' => 'ft-nowy', 'name' => 'FT Nowy', 'color' => '#123456']]), $H(2));
check('with the import on, a tag the forum sends for the first time applies at once',
      $forumTags($H(2)) === ['q:1080p' => 4, 'x:ft-nowy' => 4] && (array_column($map(), 'state', 'forum_slug')['ft-nowy'] ?? '') === 'approved',
      json_encode([$forumTags($H(2)), $map()]));
$imp($topic(502, $H(2), [['slug' => 'full-hd-1080p', 'name' => 'Full HD (1080p)'], ['slug' => 'ft-nowy', 'name' => 'FT Nowy'], ['slug' => 'ft-czeka', 'name' => 'FT Czeka']],
            ['edited_at' => '2026-10-27T11:00:00+00:00']), $H(2), ['tags_forum_review' => 'queue']);
check('… and with "waits" it is a proposal and stays off the torrent', (array_column($map(), 'state', 'forum_slug')['ft-czeka'] ?? '') === 'proposed'
      && !isset($forumTags($H(2))['x:ft-czeka']) && tagsRow($db, 'x:ft-czeka') === null, json_encode([$forumTags($H(2)), $map()]));

$r3 = $imp($topic(501, $H(1), [['slug' => 'dramatyczne', 'name' => 'Dramatyczne']]), $H(1));
check('the same text with fewer tags: the description as it was, the tags as the forum has them',
      in_array($r3['status'], ['unchanged', 'updated'], true) && $forumTags($H(1)) === ['g:dramat' => 4], json_encode([$r3, $forumTags($H(1))]));
$imp($topic(501, $H(1), null), $H(1));
check('a forum that sends no tags at all (before 2.13.0) changes none', $forumTags($H(1)) === ['g:dramat' => 4]);

$mem = tagsAttach($db, $cfgF, $H(1), 'x:ft-pl', 77001, TAG_ORIGIN_MEMBER, false);
$det = tagsDetach($db, $H(1), 'g:dramat', 77002, true);
$imp($topic(501, $H(1), [['slug' => 'dramatyczne', 'name' => 'Dramatyczne'], ['slug' => 'ft-pl', 'name' => 'FT PL']]), $H(1));
tagsForumReconcile($db, $cfgF);
check('a forum tag a moderator took off stays off; one a member added as well carries both marks',
      $mem['ok'] && $det['ok'] && !isset($forumTags($H(1))['g:dramat']) && ($forumTags($H(1))['x:ft-pl'] ?? 0) === 5, json_encode([$mem, $det, $forumTags($H(1))]));

$hide = forumDescHide($db, $client($k), forumDescTopic(['discussion_id' => 501, 'post_id' => 1501, 'hashes' => [$H(1)], 'visible' => false]), $H(1));
$st = $db->prepare("SELECT origin FROM hash_tags WHERE info_hash = ? AND tag = 'x:ft-pl'");
$st->execute([$H(1)]);
check('a withdrawn topic takes its forum tags off and leaves the member\'s', $hide['status'] === 'withdrawn' && $hft($H(1)) === [] && $forumTags($H(1)) === []
      && (int)$st->fetchColumn() === TAG_ORIGIN_MEMBER, json_encode([$hide, $hft($H(1)), $forumTags($H(1))]));

$ig = tagsForumDecide($db, $cfgF, 'forum_ignore', ['client' => $k, 'slug' => 'full-hd-1080p']);
check('an ignored forum tag comes off every torrent', $ig['ok'] && !isset($forumTags($H(2))['q:1080p']) && isset($forumTags($H(2))['x:ft-nowy']), json_encode($forumTags($H(2))));
$rt = tagsForumDecide($db, $cfgF, 'forum_target', ['client' => $k, 'slug' => 'full-hd-1080p', 'target' => 'q:2160p']);
check('choosing another match approves it and moves the torrents to it', $rt['ok'] && isset($forumTags($H(2))['q:2160p'])
      && (array_column($map(), 'state', 'forum_slug')['full-hd-1080p'] ?? '') === 'approved', json_encode([$rt, $forumTags($H(2))]));
check('… a match that is no tag is refused', tagsForumDecide($db, $cfgF, 'forum_target', ['client' => $k, 'slug' => 'full-hd-1080p', 'target' => 'nonsense']) === ['ok' => false, 'error' => 'unknown']
      && tagsForumDecide($db, $cfgF, 'forum_approve', ['client' => 0, 'slug' => 'x'])['error'] === 'invalid');
tagsForumReconcile($db, ['tags_forum' => '0'] + $cfgF);
$offAll = $forumTags($H(2));
tagsForumReconcile($db, $cfgF);
check('with tags from the forum off in Settings none stays on a torrent, and they come back when it is on', $offAll === [] && isset($forumTags($H(2))['x:ft-nowy']),
      json_encode([$offAll, $forumTags($H(2))]));
tagsForumDecide($db, $cfgF, 'forum_import', ['client' => $k, 'on' => false]);
check('turning the forum\'s import off takes all its tags off; the matches stay', $forumTags($H(2)) === [] && count($map()) === 6, json_encode($map()));

$adm = tagsForumAdmin($db, $cfgF);
$mine = array_values(array_filter($adm['clients'], fn($x) => $x['id'] === $k))[0] ?? null;
$ids = array_column($adm['targets'], 'id');
check('the panel lists the forum with its tags, torrents and matches, and every tag one can match them to',
      $mine !== null && count($mine['rows']) === 6 && $mine['proposed'] === 1 && $mine['on'] === false
      && in_array('q:2160p', $ids, true) && in_array('g:dramat', $ids, true) && in_array('x:ft-pl', $ids, true) && !in_array('x:ft-czeka', $ids, true)
      && (array_column($mine['rows'], 'to', 'slug')['ft-czeka']['new'] ?? null) === true, json_encode($mine));

$k2 = (int)apiClientCreate($db, 'ft1853-two', 'whitelist')['id'];
$db->prepare("INSERT INTO tag_forum_map (client_id, forum_slug, name, target, state, seen_at) VALUES (?, 'gone', 'Gone', 'x:ft-pl', 'approved', UTC_TIMESTAMP())")->execute([$k2]);
$db->prepare("INSERT INTO hash_forum_tags (info_hash, client_id, forum_slug) VALUES (?, ?, 'gone')")->execute([$H(2), $k2]);
$db->prepare("DELETE FROM api_clients WHERE id = ?")->execute([$k2]);
tagsUsesTick($db);
$st = $db->prepare("SELECT (SELECT COUNT(*) FROM tag_forum_map WHERE client_id = ?) + (SELECT COUNT(*) FROM hash_forum_tags WHERE client_id = ?)");
$st->execute([$k2, $k2]);
check('a deleted key leaves no matches or forum tags behind', (int)$st->fetchColumn() === 0);

$api = $src('api.php');
check('the ping tells a forum the tracker takes tags', str_contains($src('api/v1/whitelist_ping.php'), "'describe_tags' => function_exists('tagsForumOn') && tagsForumOn(\$cfg)"));
check('the describe import reads the tags after the owner, ban and topic checks, and before the text\'s early exits',
      preg_match('/other_topic.*stale.*tagsForumSet\(\$db, \$cfg, \$cid, \$hash, \$topic\[.tags.\]\).*too_long/s', $src('includes/forumdescs.php')) === 1
      && str_contains($src('includes/forumdescs.php'), "if (function_exists('tagsForumClear')) tagsForumClear(\$db, \$cid, \$hash);"));
check('the route admin/tag_forum and who may see it; the decisions need panel.tags.manage',
      str_contains($api, "'admin/tag_forum'             => 'api/admin/tag_forum.php'") && str_contains($api, "'admin/tag_forum'             => 'panel.tags.view'")
      && str_contains($src('api/admin/tag_forum.php'), 'tagsForumAdmin($db, $cfg)') && str_contains($src('api/admin/tag_action.php'), 'tagsForumDecide($db, $cfg, $op, $input)')
      && str_contains($src('api/admin/tag_action.php'), "if (!\$manage) jsonResponse(['success' => false, 'error' => 'forbidden'"));
check('the janitor puts right every 10 minutes what an interrupted request left', str_contains($src('tools/janitor.php'), "tagsUsesTick(\$db);\n        tagsForumReconcile(\$db, \$cfg);"));
check('the Tags page has the forum section and its script', str_contains($src('templates/admin/tags.php'), 'id="tags-forum-body"')
      && str_contains($src('assets/js/admin-tags.js'), "apiCall('admin/tag_forum')") && str_contains($src('assets/js/admin-tags.js'), "forumAct('forum_all', c.id)"));
check('the two settings: on the page, saved and checked, with search words', str_contains($src('templates/admin/settings.php'), 'data-setting="tags_forum"')
      && str_contains($src('templates/admin/settings.php'), 'data-setting="tags_forum_review"') && str_contains($src('api/admin/save_settings.php'), "'tags_forum', 'tags_forum_review',")
      && str_contains($src('api/admin/save_settings.php'), "\$data['tags_forum_review'] === 'queue' ? 'queue' : 'bypass'")
      && isset(settingsCatalogKeywords()['tags_forum'], settingsCatalogKeywords()['tags_forum_review']));
$plL = include $root . '/lang/pl.php';
$enL = include $root . '/lang/en.php';
$words = ['a.tags.forum_title', 'a.tags.forum_intro', 'js.tags.f_empty', 'js.tags.f_turn_on', 'js.tags.f_approve_all', 'js.tags.f_new', 'js.tags.f_done',
          'settings.tags_forum', 'settings.tags_forum_review', 'settings.tags_forum_review_hint'];
check('the words in both languages', !array_diff($words, array_keys($plL)) && !array_diff($words, array_keys($enL))
      && $plL['js.tags.f_turn_on'] === 'Włącz import' && __('a.tags.forum_title') === 'Tags from the forum');
check('an empty waiting badge stays hidden on the Tags page', str_contains($src('assets/css/admin.css'), '.wl-badge.d-hidden { display: none; }')
      && str_contains($src('templates/admin/tags.php'), 'class="wl-badge wl-b-pending d-hidden" id="tags-pending-badge"'));
check('version and changelog', TRACKER_VERSION === '1.85.5' && str_contains($src('CHANGELOG.md'), '## [1.85.5]') && str_contains($src('CHANGELOG.md'), '## [1.85.3]'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
