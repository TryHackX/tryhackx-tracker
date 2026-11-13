<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/mail.php',
          'includes/users.php', 'includes/reputation.php', 'includes/lang.php', 'includes/emoji.php', 'includes/shout.php',
          'includes/comments.php', 'includes/lists.php', 'includes/csp.php'] as $f) require_once $root . '/' . $f;

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . substr($info, 0, 700)) . "\n";
    if (!$ok) $fails++;
}
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg); $cfg = getSettings($db, true);
langInit([], 'en');
$base = ['desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1', 'desc_allow_flarum' => '1', 'desc_max_chars' => '4000',
         'desc_max_images' => '3', 'desc_max_links' => '10', 'site_url' => 'https://r182.test/', 'link_trusted_domains' => ''];
$H = fn(int $i): string => str_repeat('cd', 19) . sprintf('%02x', $i);
$tidy = function () use ($db): void {
    $db->exec("DELETE FROM hash_votes WHERE info_hash LIKE 'cdcdcd%'");
    $db->exec("DELETE FROM forum_descs WHERE info_hash LIKE 'cdcdcd%'");
    $db->exec("DELETE FROM whitelist WHERE info_hash LIKE 'cdcdcd%'");
    $db->exec("DELETE FROM render_cache");
};
$tidy();
$mag = 'magnet:?xt=urn:btih:0123456789ABCDEF0123456789abcdef01234567&dn=Big.Buck_Bunny.2008&xl=1363148800'
     . '&tr=udp%3A%2F%2Ftracker.example.org%3A6969%2Fannounce&tr=javascript%3Aalert(1)';

try {
    // ── votes remember the scale they were cast in ──
    $h = $H(1);
    $ins = $db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote, scale, weight) VALUES (?, 'user', ?, ?, ?, 100)");
    $ins->execute([$h, 'r1', 1, 'thumbs']);
    $ins->execute([$h, 'r2', -1, 'thumbs']);
    $ins->execute([$h, 'r3', 10, 'stars']);
    $thumbs = repFor($db, ['rep_mode' => 'thumbs', 'rep_min_votes' => '1'], $h);
    $stars = repFor($db, ['rep_mode' => 'stars', 'rep_min_votes' => '1'], $h);
    check('votes: thumbs mode counts only the thumbs, stars mode only the stars',
          $thumbs['total'] === 2 && $thumbs['up'] === 1 && $thumbs['down'] === 1 && $stars['total'] === 1 && (float)$stars['stars'] === 5.0,
          json_encode([$thumbs, $stars]));
    check('votes: the scale is a column of its own', schemaColumnExists($db, 'hash_votes', 'scale'));
    check('votes: changing the mode counts every hash again', str_contains($src('api/admin/save_settings.php'), 'repRecountAll'));

    // ── the magnet card ──
    $p = richtextMagnetParse($mag);
    check('magnet: a btih is read in either case, dn and xl kept, a javascript: tracker dropped',
          $p !== null && $p['hash'] === '0123456789abcdef0123456789abcdef01234567' && $p['size'] === 1363148800
          && str_contains($p['uri'], 'tr=udp%3A%2F%2Ftracker.example.org') && !str_contains($p['uri'], 'javascript'), json_encode($p));
    check('magnet: a base32 hash is turned into hex', (richtextMagnetParse('magnet:?xt=urn:btih:AERUKZ4JVPG66AJDIVSYVK3PPMRUNXXX')['hash'] ?? '') !== ''
          && strlen(richtextMagnetParse('magnet:?xt=urn:btih:AERUKZ4JVPG66AJDIVSYVK3PPMRUNXXX')['hash']) === 40);
    check('magnet: no btih, a short hash, a hash with a newline after it, and a 12 001-character link are no magnet',
          richtextMagnetParse('magnet:?dn=x') === null && richtextMagnetParse('magnet:?xt=urn:btih:abc') === null
          && richtextMagnetParse('magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567%0A') === null
          && richtextMagnetParse('magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=' . str_repeat('a', 12000)) === null);
    $can = richtextRender("[magnet]{$mag}[/magnet]", 'bbcode', ['rt_magnet_uri' => '1'] + $base, true);
    check('magnet: a permitted reader gets the card — the name from dn, the size, one link to open, a copy button, the hash for the swarm numbers',
          str_contains($can, 'class="rt-magnet"') && str_contains($can, '>Big Buck Bunny 2008<') && str_contains($can, '1.27 GiB')
          && substr_count($can, '<a ') === 1 && str_contains($can, 'href="magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567')
          && str_contains($can, 'class="rt-magnet-copy"') && str_contains($can, 'data-mh="0123456789abcdef0123456789abcdef01234567"'), $can);
    $locked = richtextRender("[magnet=Alt]{$mag}[/magnet]", 'bbcode', ['rt_magnet_uri' => '0'] + $base, true);
    check('magnet: a reader without index.magnet gets the name and the size, and no link, no hash, no copy button',
          str_contains($locked, 'rt-magnet-locked') && str_contains($locked, '>Alt<') && !str_contains($locked, 'magnet:')
          && !str_contains($locked, '0123456789abcdef') && !str_contains($locked, '<a ') && !str_contains($locked, 'rt-magnet-copy'), $locked);
    check('magnet: the torrent\'s own magnet is left out of its own description',
          !str_contains(richtextRender("a\n[magnet]{$mag}[/magnet]\nb", 'bbcode',
              ['rt_magnet_uri' => '1', 'rt_magnet_self' => '0123456789abcdef0123456789abcdef01234567'] + $base, true), 'rt-magnet'));
    check('magnet: the shoutbox gets the one-line card', str_contains(shoutBodyHtml("x [magnet]{$mag}[/magnet] y", 'bbcode', ['rt_magnet_uri' => '1'] + $base, []),
          'class="rt-magnet rt-magnet-sm"'));
    check('magnet: the forum format draws it instead of cutting it out, and Markdown too',
          str_contains(richtextRender("[magnet]{$mag}[/magnet]", 'flarum', ['rt_magnet_uri' => '1'] + $base, true), 'rt-magnet')
          && str_contains(richtextRender("[magnet]{$mag}[/magnet]", 'markdown', ['rt_magnet_uri' => '1'] + $base, true), 'rt-magnet'));
    check('magnet: the card is one link in the description\'s count, whoever counts',
          richtextCount("[magnet]{$mag}[/magnet]", 'bbcode', ['rt_magnet_uri' => '0'] + $base) === ['images' => 0, 'links' => 1]);
    check('magnet: an excerpt says [magnet] and a mail carries no link',
          richtextExcerpt("Hi [magnet]{$mag}[/magnet] bye") === 'Hi [magnet] bye'
          && !str_contains(richtextRenderForEmail("[magnet]{$mag}[/magnet]", 'bbcode', $base), 'magnet:'));
    $evil = 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=%3Cscript%3Ealert(1)%3C%2Fscript%3E%22%20onmouseover%3D%22x';
    $ev = richtextRender("[magnet=\"<img src=x onerror=alert(1)>\"]{$evil}[/magnet][magnet]{$evil}[/magnet]", 'bbcode', ['rt_magnet_uri' => '1'] + $base, true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8"><div>' . $ev . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $bad = [];
    foreach ((new DOMXPath($dom))->query('//*') as $node) {
        if (in_array($node->nodeName, ['script', 'img', 'iframe'], true)) $bad[] = $node->nodeName;
        foreach ($node->attributes as $at) if (str_starts_with(strtolower($at->name), 'on')) $bad[] = $node->nodeName . '@' . $at->name;
    }
    check('magnet: a name or a label made of markup stays words', $bad === [] && str_contains($ev, '&lt;script&gt;'), implode(',', $bad));
    $cm = commentParse("Alt [magnet=4K]{$mag}[/magnet]", ['rt_magnet_uri' => '1'] + $base, true);
    check('comments: a member\'s [magnet] is a card and one link', str_contains($cm['html'], 'rt-magnet') && $cm['links'] === 1 && $cm['chars'] === 6, json_encode($cm));
    check('comments: a guest may not, four are too many, a broken one is a bad link',
          (commentProblem("[magnet]{$mag}[/magnet]", $base, false)['code'] ?? '') === 'no_links'
          && (commentProblem(str_repeat("[magnet]{$mag}[/magnet]", 4), $base, true)['code'] ?? '') === 'too_many_links'
          && (commentProblem('[magnet]magnet:?xt=urn:btih:zz[/magnet]', $base, true)['code'] ?? '') === 'bad_link');
    check('lists: a magnet is no visible text in a list\'s description, in every format',
          trim(listDescStrip("[magnet]{$mag}[/magnet]", 'bbcode')) === '' && trim(listDescStrip("[magnet]{$mag}[/magnet]", 'markdown')) === '');
    $ms = $src('api/magnet_stats.php');
    check('the swarm numbers of the cards ask index.view and index.magnet, take at most fifty hashes and never a banned row',
          str_contains($ms, "userCan(\$db, \$cfg, 'index.magnet')") && str_contains($ms, "userCan(\$db, \$cfg, 'index.view')")
          && str_contains($ms, '>= 50') && str_contains($ms, 'banned = 0') && str_contains($src('api.php'), "'magnet_stats'"));
    check('the card script is on every page that draws descriptions',
          str_contains($src('templates/layout.php'), 'magnetcard.js') && str_contains($src('templates/admin/moderation.php'), 'magnetcard.js')
          && str_contains($src('templates/admin/index_page.php'), 'magnetcard.js') && str_contains($src('templates/admin/whitelist.php'), 'magnetcard.js'));

    // ── the editor and the renderer ──
    $md = richtextRender("one\n\n---\n\ntwo\n* * *\nthree", 'markdown', $base, true);
    check('markdown: --- and * * * draw a line', substr_count($md, '<hr class="rt-hr">') === 2, $md);
    check('the forum format reads pipe tables', str_contains(richtextRender("| a | b |\n|---|---|\n| 1 | 2 |", 'flarum', $base, true), '<table class="rt-table">'));
    $forumTags = ['[b]x[/b]', '[i]x[/i]', '[u]x[/u]', '[s]x[/s]', '[del]x[/del]', '[url=https://e.org]x[/url]', '[url]https://e.org[/url]',
        '[img]https://e.org/a.png[/img]', '[email]a@e.org[/email]', '[code]x[/code]', '[code=php]x[/code]', '[quote]x[/quote]', '[quote=Ann]x[/quote]',
        '[quote author=Ann]x[/quote]', "[list]\n[*]a\n[/list]", "[list=1]\n[*]a\n[/list]", '[color=red]x[/color]', '[center]x[/center]', '[size=20]x[/size]',
        '[spoiler]x[/spoiler]', '[spoiler=T]x[/spoiler]', '[spoiler title=T]x[/spoiler]', '[table][tr][th]a[/th][/tr][tr][td]b[/td][/tr][/table]',
        "[magnet]{$mag}[/magnet]", '[thxlabels=2][thxrow][img]https://e.org/a.png[/img][/thxrow][/thxlabels]', '[upl-image-preview uuid=1 url=https://e.org/u.png alt=up]',
        '[upl-image uuid=1 url=https://e.org/u.png]alt[/upl-image]', '[upl-file uuid=2 size=10KB]notes.txt[/upl-file]',
        '[upl-text-preview uuid=3 url=https://e.org/t.txt]t.txt[/upl-text-preview]', '[hide]x[/hide]', '[youtube]aqz-KE-bpKQ[/youtube]', '[left]x[/left]',
        '[right]x[/right]', '[hr]', '[sub]x[/sub]', '[sup]x[/sup]', "| a | b |\n|---|---|\n| 1 | 2 |", "- [ ] task\n- [x] done", '@"Some One"#p12',
        "# H1", "x\n\n---\n\ny", '>!sp!<', '||sp||', '~~x~~', '[font=Arial]x[/font]', '[highlight]x[/highlight]'];
    $raw = [];
    foreach ($forumTags as $t) {
        $out = strip_tags(richtextRender($t, 'flarum', ['rt_magnet_uri' => '1'] + $base, true));
        if (preg_match('/\[\/?(?:b|i|u|s|del|url|img|email|code|quote|list|\*|color|center|size|spoiler|table|tr|th|td|magnet|thxlabels|thxrow|upl-[a-z-]+|hide|youtube|left|right|hr|sub|sup|font|highlight)\b[^\]]*\]/i', $out)
            || str_contains($out, '|---|') || str_contains($out, '#p12') || str_contains($out, '>!') || str_contains($out, '||')) $raw[] = $t;
    }
    check('parity: every tag of the forum\'s extensions renders and leaves no raw text (' . count($forumTags) . ' tags)', $raw === [], implode(' | ', $raw));
    $links = richtextRender("https://vimeo.com/123456\nhttps://www.twitch.tv/chan\nhttps://soundcloud.com/a/b", 'bbcode', $base, true);
    check('Vimeo, Twitch and SoundCloud are named links with their mark, nothing embedded',
          str_contains($links, 'bi-vimeo') && str_contains($links, 'Vimeo: 123456') && str_contains($links, 'Twitch: chan')
          && str_contains($links, 'SoundCloud: a/b') && !str_contains($links, '<iframe'), $links);
    $vOff = richtextRender('https://cdn.example.org/clip.mp4', 'bbcode', richtextVideoCfg($base), true);
    $vOn = richtextRender('https://cdn.example.org/clip.mp4', 'bbcode', richtextVideoCfg(['rt_video_files' => '1'] + $base), true);
    check('a video file is a link, and a click-to-play player only with rt_video_files on', !str_contains($vOff, 'data-vfile') && str_contains($vOn, 'data-vfile="1"'));
    check('… and the YouTube player stays with its own setting', !str_contains(richtextRender('[youtube]aqz-KE-bpKQ[/youtube]', 'bbcode',
          richtextVideoCfg(['rt_video_files' => '1', 'rt_youtube' => 'link'] + $base), true), 'data-yt='));
    check('CSP: media from https only while video files play', !str_contains(cspPolicy(['csp_mode' => 'enforce'], 'public'), 'media-src')
          && str_contains(cspPolicy(['csp_mode' => 'enforce', 'rt_video_files' => '1'], 'public'), "media-src 'self' https:"));
    $app = $src('assets/js/app.js');
    check('the editor\'s Insert and Paragraph menus have their syntax',
          str_contains($app, "magnet: ['[magnet]', '[/magnet]']") && str_contains($app, "quote_author: ['[quote=Name]', '[/quote]']")
          && str_contains($app, "alist: ['[list=a]") && str_contains($app, "h1: ['# ', '']") && str_contains($app, "insert: ['bi-plus-lg'"));
    check('… on the description, message and list editors', substr_count($src('templates/partials/info_overlay.php') . $src('templates/pages/whitelist.php')
          . $src('templates/pages/account.php') . $src('templates/partials/list_edit.php'), 'data-rt-menus="insert para"') === 4);
    check('pasting [magnet] no longer switches the format to the forum\'s', !str_contains($app, 'thxlabels|thxrow|magnet|'));

    // ── the render cache ──
    $long = str_repeat("[b]Long[/b] description line with words.\n", 600);
    $c1 = richtextRenderCached($db, $long, 'bbcode', ['rt_magnet_uri' => '1'] + $base, true);
    $rows1 = (int)$db->query("SELECT COUNT(*) FROM render_cache")->fetchColumn();
    $c2 = richtextRenderCached($db, $long, 'bbcode', ['rt_magnet_uri' => '1'] + $base, true);
    $c3 = richtextRenderCached($db, $long, 'bbcode', ['rt_magnet_uri' => '1'] + $base, false);
    $c4 = richtextRenderCached($db, $long, 'bbcode', ['rt_magnet_uri' => '0'] + $base, true);
    check('render cache: a long description is kept once and read back the same',
          $rows1 === 1 && $c1 === $c2 && $c1 === richtextRender($long, 'bbcode', ['rt_magnet_uri' => '1'] + $base, true));
    check('… a guest, and a reader without magnets, are kept apart', (int)$db->query("SELECT COUNT(*) FROM render_cache")->fetchColumn() === 3);
    richtextRenderCached($db, 'short', 'bbcode', $base, true);
    check('… a short one is never kept', (int)$db->query("SELECT COUNT(*) FROM render_cache")->fetchColumn() === 3);
    $db->exec("UPDATE render_cache SET used_at = NOW() - INTERVAL 30 DAY");
    check('… and the janitor drops what nobody read for two weeks', richtextCachePrune($db) === 3);

    // ── record limits for the forum's descriptions ──
    $hf = $H(2);
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, probe_status, description, description_format, content_status)
                  VALUES (?, 'Forum release', 'api', NOW(), 'passed', 'x', 'flarum', 'approved')")->execute([$hf]);
    $db->prepare("INSERT INTO forum_descs (info_hash, client_id, discussion_id, post_id, forum_chars, forum_images, forum_links, audience, import_sha, state)
                  VALUES (?, 1, 1, 1, 9000, 12, 15, 'members', ?, 'imported')")->execute([$hf, str_repeat('0', 64)]);
    $lim = forumDescLimits($db, $hf);
    $rl = richtextRecordLimits(['rt_record_limits' => $lim] + $base);
    check('record limits: the larger of the site\'s and the forum record\'s', $rl === ['chars' => 9000, 'images' => 12, 'links' => 15], json_encode($rl));
    $longText = str_repeat('word ', 1500);
    check('… a 7500-character edit of a forum record passes, the same text on another hash does not',
          richtextValidate($longText, 'bbcode', ['rt_record_limits' => $lim] + $base) === null && richtextValidate($longText, 'bbcode', $base) !== null);
    check('… and the renderer\'s 500 000 becomes up to a million for such a record',
          richtextRecordLimits(['rt_record_limits' => ['chars' => 2000000]] + $base)['chars'] === 1000000);
    check('… the submit, the preview and the Info panel all ask for them',
          str_contains($src('includes/content.php'), 'forumDescLimits($db, $hash)') && str_contains($src('api/richtext_preview.php'), 'forumDescLimits(')
          && str_contains($src('api/index_info.php'), 'forumDescLimits($db, $hash)'));

    // ── the forum's audience ──
    check('audience: members is read from a topic, anything else is guests',
          forumDescTopic(['discussion_id' => 1, 'post_id' => 1, 'audience' => 'members'])['audience'] === 'members'
          && forumDescTopic(['discussion_id' => 1, 'post_id' => 1, 'audience' => 'everyone'])['audience'] === 'guests'
          && forumDescTopic(['discussion_id' => 1, 'post_id' => 1])['audience'] === 'guests');
    check('… a members-only record is known as one', forumDescMembersOnly($db, $hf) && !forumDescMembersOnly($db, $H(3)));
    check('… the Info panel shows its words to signed-in people only, and the search in descriptions leaves it out for guests',
          str_contains($src('api/index_info.php'), '(richtextViewerSignedIn($db) || !forumDescMembersOnly($db, $hash))')
          && str_contains($src('api/index_search.php'), "'desc_guest'") && str_contains($src('includes/index.php'), "f.audience = 'members'"));

    // ── the Index counts ──
    @unlink(indexStatusCacheFile('counts'));
    [$cv, $stale] = indexSharedCounts($db, 'counts', 'indexCountsCompute');
    check('index counts: a small index counts at once and keeps one shared copy', $stale === false && isset($cv['total']) && is_file(indexStatusCacheFile('counts')));
    indexStatusCacheDrop();
    $kept = json_decode((string)file_get_contents(indexStatusCacheFile('counts')), true);
    check('… a change marks the copy stale and keeps its numbers', !empty($kept['stale']) && isset($kept['v']['total']));
    @unlink(indexCountsSeenFile());
    check('… the janitor refreshes it only when somebody looked recently', indexCountsTick($db) === false);
    touch(indexCountsSeenFile());
    check('… and then it does', indexCountsTick($db) === true && empty(json_decode((string)file_get_contents(indexStatusCacheFile('counts')), true)['stale']));
    check('… the covering key is asked for, outside a page view', str_contains($src('includes/schema.php'), "ADD KEY `idx_index_meta_grace` (`meta_status`, `grace_until`)"));
    check('… and the list reads the same copy', str_contains($src('api/admin/fetch_index.php'), "indexSharedCounts(\$db, 'counts'")
          && str_contains($src('includes/index.php'), 'indexSharedCount($db, $snapKey)'));

    // ── descriptions: the published view and the filter ──
    $hd = $H(4);
    $db->prepare("INSERT INTO whitelist (info_hash, name, source, created_at, probe_status, description, description_format, content_status)
                  VALUES (?, 'Described', 'web', NOW(), 'passed', ?, 'bbcode', 'approved')")
       ->execute([$hd, "[img]https://e.org/a.png[/img] https://www.youtube.com/watch?v=aqz-KE-bpKQ"]);
    check('description flags: words, pictures and a video in one number', (indexDescFlags($db, [$hd], true)[$hd] ?? 0) === 7);
    check('Moderation offers the published descriptions, newest first, sortable by rating and by pictures',
          str_contains($src('templates/admin/moderation.php'), 'data-sub="published"') && str_contains($src('templates/admin/moderation.php'), 'rv_sort_pics')
          && str_contains($src('assets/js/admin-review.js'), "'published'"));
} catch (\Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    $tidy();
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
