<?php
/**
 * Tests for the editable Terms / Info pages:
 *   php tests/pagecontent_test.php
 *
 * The property that matters most here is not "can it save text" — it is that an override is only
 * ever an OVERRIDE: the shipped template stays the default, a draft cannot reach a visitor, and
 * restoring gives back a page written for how the tracker is configured at that moment rather than
 * a frozen copy from whenever the feature was built.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/lang.php';
require_once $root . '/includes/pagecontent.php';
// The default text is generated from the dictionary now, so a language has to be loaded.
// English, because the assertions below are written in it.
langInit(['default_language' => 'en']);

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$cfg = getSettings($db);

// ── the catalogue is the allow-list ─────────────────────────────────────────
check('only the two pages it claims are editable', PAGECONTENT_PAGES === ['tos', 'info']);
check('the catalogue names a route and a template for each',
      count(array_filter(pageContentCatalog(), fn($m) => isset($m['route'], $m['template'], $m['label']))) === 2);
foreach (pageContentCatalog() as $k => $m) {
    check("$k: the template it claims to replace exists", is_file($root . '/' . $m['template']), $m['template']);
}

// ── the defaults are generated from the configuration, not frozen ───────────
$wlCfg   = array_merge($cfg, ['tracker_mode' => 'whitelist', 'users_enabled' => '1']);
$openCfg = array_merge($cfg, ['tracker_mode' => 'blacklist', 'users_enabled' => '0']);

$tosWl = pageContentDefault($wlCfg, 'tos', 'markdown', '', 'en');
$tosOpen = pageContentDefault($openCfg, 'tos', 'markdown', '', 'en');
check('the whitelist clause is in the terms when the tracker is in whitelist mode',
      str_contains($tosWl, 'Whitelist registrations are free and anonymous'));
check('… and is absent when it is not', !str_contains($tosOpen, 'Whitelist registrations are free'));
check('the account terms appear only when accounts are on',
      str_contains($tosWl, 'User accounts') && !str_contains($tosOpen, 'User accounts'));
check('the numbering closes over the clause that came and went',
      str_contains($tosWl, '10. **Connecting to the tracker') && str_contains($tosOpen, '9. **Connecting to the tracker'),
      substr($tosOpen, -80));

$infoWl = pageContentDefault($wlCfg, 'info', 'markdown', '', 'en');
$infoOpen = pageContentDefault($openCfg, 'info', 'markdown', '', 'en');
check('the info page gains its whitelist section in whitelist mode',
      str_contains($infoWl, '## Whitelist mode') && !str_contains($infoOpen, '## Whitelist mode'));
check('the FAQ answer changes with the mode',
      str_contains($infoWl, 'in whitelist mode a hash can be removed')
      && str_contains($infoOpen, 'automatically removes swarms'));

// ── both formats, and the difference between them stated honestly ───────────
$md = pageContentDefault($wlCfg, 'tos', 'markdown', '', 'en');
$bb = pageContentDefault($wlCfg, 'tos', 'bbcode', '', 'en');
check('markdown uses real headings', str_starts_with($md, '# Terms of Service'));
check('bbcode has no heading tag, so it uses a large bold line instead',
      str_starts_with($bb, '[size=24][b]Terms of Service[/b][/size]'), substr($bb, 0, 60));
check('markdown numbers its list', str_contains($md, '1. The service is free'));
check('bbcode uses [list=1]', str_contains($bb, '[list=1]') && str_contains($bb, '[*] The service is free'));
check('an unknown format is treated as markdown rather than producing nothing',
      pageContentDefault($wlCfg, 'tos', 'nonsense', '', 'en') === $md);

// The default text is the SAME wording the templates render — one source, no second English copy
// living in the generator. That is what makes "Restore" hand back what a visitor would have read.
$plMd = pageContentDefault($wlCfg, 'tos', 'markdown', '', 'pl');
check('the default is generated in the language asked for', str_starts_with($plMd, '# Regulamin'), substr($plMd, 0, 40));
check('… and is not the English one', $plMd !== $md);
check('the generator has no English text of its own left in it',
      !str_contains((string)file_get_contents($root . '/includes/pagecontent.php'),
                    'The service is free for personal use'));
// The dictionary strings carry HTML; the generator has to turn it into the requested markup.
check('HTML in a string becomes markdown', str_contains($md, '**Connecting to the tracker'), '');
check('… and bbcode', str_contains($bb, '[b]Connecting to the tracker'), '');
check('no HTML tag survives into the page source', !preg_match('/<(strong|em|a |code)/', $md . $bb));

// ── both defaults survive the renderer, and produce a real page ─────────────
foreach (['markdown' => $md, 'bbcode' => $bb] as $fmt => $body) {
    check("$fmt: the default passes the same validator author text does",
          richtextValidate($body, $fmt, $cfg) === null, (string)richtextValidate($body, $fmt, $cfg));
    $html = richtextRender($body, $fmt, $cfg, true);
    check("$fmt: it renders to something with the terms in it",
          str_contains($html, 'Commercial organizations require written permission'));
    check("$fmt: and with a list", substr_count($html, '<li') >= 9, (string)substr_count($html, '<li'));
}
$mdHtml = richtextRender($md, 'markdown', $cfg, true);
check('markdown produces a heading element', (bool)preg_match('/<h[1-6]/', $mdHtml));

// ── storing: a draft is not a live page ─────────────────────────────────────
$db->exec("DELETE FROM page_content WHERE page IN ('tos','info')");
check('nothing stored means nothing active', pageContentActive($db, 'tos', $cfg, 'en') === null);

$r = pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "# Mine\n\nJust this.", false, 'test');
check('a draft saves', empty($r['error']), json_encode($r));
check('… and a draft is NOT what visitors get', pageContentActive($db, 'tos', $cfg, 'en') === null);
check('… but the panel can see it', (pageContentGet($db, 'tos', 'en')['body'] ?? '') === "# Mine\n\nJust this.");

$r = pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "# Mine\n\nJust this.", true, 'test');
check('publishing makes it active', empty($r['error']) && pageContentActive($db, 'tos', $cfg, 'en') !== null);
check('… and the active row carries its format', (pageContentActive($db, 'tos', $cfg, 'en')['format'] ?? '') === 'markdown');

// ── one version per language, and the order a visitor falls back through ────
//
// The rule this pins down: once ANYTHING is written, a visitor never drops back to the built-in
// boilerplate. Terms somebody actually wrote must not be silently replaced by the shipped text
// because one translation is missing.
$db->exec("DELETE FROM page_content WHERE page IN ('tos','info')");
pageContentSave($db, $cfg, 'tos', 'pl', 'markdown', "# Moj regulamin", true, 'test');
check('a visitor in that language gets that version', (pageContentActive($db, 'tos', $cfg, 'pl')['lang'] ?? '') === 'pl');
check('a visitor in ANOTHER language gets it too rather than the built-in page',
      (pageContentActive($db, 'tos', $cfg, 'en')['lang'] ?? '') === 'pl');
pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "# My terms", true, 'test');
check('… until that language has its own', (pageContentActive($db, 'tos', $cfg, 'en')['lang'] ?? '') === 'en');
check('… and the first one is untouched', (pageContentActive($db, 'tos', $cfg, 'pl')['lang'] ?? '') === 'pl');
check('the site default outranks English in the chain',
      pageContentLangChain(['default_language' => 'pl'], 'de') === ['de', 'pl', 'en'],
      implode(',', pageContentLangChain(['default_language' => 'pl'], 'de')));
check('"auto" is not a language and never enters the chain',
      !in_array('auto', pageContentLangChain(['default_language' => 'auto'], 'pl'), true));
// Restore is per language: it must not take an afternoon's work in another one with it.
pageContentReset($db, 'tos', 'pl');
check('restoring one language leaves the others alone',
      pageContentGet($db, 'tos', 'pl') === null && pageContentGet($db, 'tos', 'en') !== null);
check('… and that language now falls back to the one that is left',
      (pageContentActive($db, 'tos', $cfg, 'pl')['lang'] ?? '') === 'en');
check('a language that is not installed cannot be saved for',
      isset(pageContentSave($db, $cfg, 'tos', 'zz', 'markdown', 'x', true, 'test')['error']));
$db->exec("DELETE FROM page_content WHERE page IN ('tos','info')");
pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "# Mine\n\nJust this.", true, 'test');

// ── the guards ──────────────────────────────────────────────────────────────
check('an empty page cannot be published',
      isset(pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "   ", true, 'test')['error']));
check('an empty page CAN be saved as a draft',
      empty(pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "", false, 'test')['error']));
check('a page that is not in the catalogue is refused',
      isset(pageContentSave($db, $cfg, 'home', 'en', 'markdown', 'x', true, 'test')['error']));
check('an unknown format is refused rather than guessed at',
      isset(pageContentSave($db, $cfg, 'tos', 'en', 'html', 'x', true, 'test')['error']));
check('something longer than the cap is refused',
      isset(pageContentSave($db, $cfg, 'tos', 'en', 'markdown', str_repeat('a', PAGECONTENT_MAX + 1), true, 'test')['error']));
// The same validator as every other author-written text: a page is not a reason to skip it.
$bad = str_repeat("[img]https://example.com/a.png[/img]\n", 200);
check('text the renderer would refuse elsewhere is refused here too',
      isset(pageContentSave($db, $cfg, 'tos', 'en', 'bbcode', $bad, true, 'test')['error']),
      json_encode(pageContentSave($db, $cfg, 'tos', 'en', 'bbcode', $bad, true, 'test')));

// ── restoring is a delete, so the template comes back by itself ─────────────
pageContentSave($db, $cfg, 'tos', 'en', 'markdown', "# Mine", true, 'test');
check('restore removes the row', pageContentReset($db, 'tos', 'en') && pageContentGet($db, 'tos', 'en') === null);
check('… and nothing is active afterwards', pageContentActive($db, 'tos', $cfg, 'en') === null);
$db->exec("DELETE FROM page_content WHERE page IN ('tos','info')");

// ── registration ────────────────────────────────────────────────────────────
$schema = (string)file_get_contents($root . '/includes/schema.php');
check('the table is created', str_contains($schema, 'CREATE TABLE IF NOT EXISTS `page_content`'));
check('the table is keyed by page AND language', str_contains($schema, 'PRIMARY KEY (`page`, `lang`)'));
check('… and an older install is migrated to it',
      str_contains($schema, "schemaColumnExists(\$db, 'page_content', 'lang')"));
check('the schema version was bumped for it',
      (bool)preg_match('/TRACKER_SCHEMA_VERSION = (\d+)/', $schema, $m) && (int)$m[1] >= 41, $m[1] ?? '?');
$api = (string)file_get_contents($root . '/api.php');
check('the endpoint is routed', str_contains($api, "'admin/page_content'"));
check('changing a public page is owner-only (no permission entry)',
      !preg_match("/'admin\\/page_content'\\s*=>\\s*'panel\\./", $api));
$audit = (string)file_get_contents($root . '/includes/audit.php');
check('edits are audited', str_contains($audit, "'admin/page_content'          => 'page.edit'"));
// Matched loosely on purpose: the group keeps gaining members (page.layout, language.manage),
// and a test that pins the whole literal fails for every addition rather than for a regression.
check('… under the settings group',
      (bool)preg_match("/'settings' => \[[^\]]*'page\.edit'[^\]]*\]/", $audit));
$idx = (string)file_get_contents($root . '/index.php');
check('the router consults the override', str_contains($idx, 'pageContentActive($db, $action, $cfg)'));
check('… and only for the pages in the catalogue', str_contains($idx, 'in_array($action, PAGECONTENT_PAGES, true)'));
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
check('the settings section exists', str_contains($tpl, 'id="section-pages"'));
check('the editor modal exists', str_contains($tpl, 'id="pageEditModal"'));
check('the language rail exists', str_contains($tpl, 'id="pc-langs"'));
// THE BUG THIS CATCHES: apiCall() prepends `api.php?endpoint=`, so a second parameter joins
// with & — a `?` makes the whole string the endpoint name and every call 404s. It shipped.
$js = (string)file_get_contents($root . '/assets/js/admin-pagecontent.js');
check('the editor reaches its endpoint (& not ?, or the endpoint name is wrong)',
      str_contains($js, "admin/page_content&page=") && !str_contains($js, "admin/page_content?"));
check('no browser confirm() is used — the panel has its own dialog',
      !str_contains($js, 'window.confirm'));
// The bug this catches: admin-common.js is loaded AFTER admin-settings.js on this page, so an
// editor script placed with the others returns early and the dialog never opens.
check('the editor script is loaded after admin-common.js, which it needs',
      strpos($tpl, 'admin-pagecontent.js') > strpos($tpl, 'admin-common.js'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
