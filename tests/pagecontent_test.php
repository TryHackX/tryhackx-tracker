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
require_once $root . '/includes/pagecontent.php';

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

$tosWl = pageContentDefault($wlCfg, 'tos', 'markdown');
$tosOpen = pageContentDefault($openCfg, 'tos', 'markdown');
check('the whitelist clause is in the terms when the tracker is in whitelist mode',
      str_contains($tosWl, 'Whitelist registrations are free and anonymous'));
check('… and is absent when it is not', !str_contains($tosOpen, 'Whitelist registrations are free'));
check('the account terms appear only when accounts are on',
      str_contains($tosWl, 'User accounts') && !str_contains($tosOpen, 'User accounts'));
check('the numbering closes over the clause that came and went',
      str_contains($tosWl, '10. **Connecting to the tracker') && str_contains($tosOpen, '9. **Connecting to the tracker'),
      substr($tosOpen, -80));

$infoWl = pageContentDefault($wlCfg, 'info', 'markdown');
$infoOpen = pageContentDefault($openCfg, 'info', 'markdown');
check('the info page gains its whitelist section in whitelist mode',
      str_contains($infoWl, '## Whitelist mode') && !str_contains($infoOpen, '## Whitelist mode'));
check('the FAQ answer changes with the mode',
      str_contains($infoWl, 'in whitelist mode a hash can be removed')
      && str_contains($infoOpen, 'automatically removes swarms'));

// ── both formats, and the difference between them stated honestly ───────────
$md = pageContentDefault($wlCfg, 'tos', 'markdown');
$bb = pageContentDefault($wlCfg, 'tos', 'bbcode');
check('markdown uses real headings', str_starts_with($md, '# Terms of Service'));
check('bbcode has no heading tag, so it uses a large bold line instead',
      str_starts_with($bb, '[size=24][b]Terms of Service[/b][/size]'), substr($bb, 0, 60));
check('markdown numbers its list', str_contains($md, '1. The service is free'));
check('bbcode uses [list=1]', str_contains($bb, '[list=1]') && str_contains($bb, '[*] The service is free'));
check('an unknown format is treated as markdown rather than producing nothing',
      pageContentDefault($wlCfg, 'tos', 'nonsense') === $md);

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
check('nothing stored means nothing active', pageContentActive($db, 'tos') === null);

$r = pageContentSave($db, $cfg, 'tos', 'markdown', "# Mine\n\nJust this.", false, 'test');
check('a draft saves', empty($r['error']), json_encode($r));
check('… and a draft is NOT what visitors get', pageContentActive($db, 'tos') === null);
check('… but the panel can see it', (pageContentGet($db, 'tos')['body'] ?? '') === "# Mine\n\nJust this.");

$r = pageContentSave($db, $cfg, 'tos', 'markdown', "# Mine\n\nJust this.", true, 'test');
check('publishing makes it active', empty($r['error']) && pageContentActive($db, 'tos') !== null);
check('… and the active row carries its format', (pageContentActive($db, 'tos')['format'] ?? '') === 'markdown');

// ── the guards ──────────────────────────────────────────────────────────────
check('an empty page cannot be published',
      isset(pageContentSave($db, $cfg, 'tos', 'markdown', "   ", true, 'test')['error']));
check('an empty page CAN be saved as a draft',
      empty(pageContentSave($db, $cfg, 'tos', 'markdown', "", false, 'test')['error']));
check('a page that is not in the catalogue is refused',
      isset(pageContentSave($db, $cfg, 'home', 'markdown', 'x', true, 'test')['error']));
check('an unknown format is refused rather than guessed at',
      isset(pageContentSave($db, $cfg, 'tos', 'html', 'x', true, 'test')['error']));
check('something longer than the cap is refused',
      isset(pageContentSave($db, $cfg, 'tos', 'markdown', str_repeat('a', PAGECONTENT_MAX + 1), true, 'test')['error']));
// The same validator as every other author-written text: a page is not a reason to skip it.
$bad = str_repeat("[img]https://example.com/a.png[/img]\n", 200);
check('text the renderer would refuse elsewhere is refused here too',
      isset(pageContentSave($db, $cfg, 'tos', 'bbcode', $bad, true, 'test')['error']),
      json_encode(pageContentSave($db, $cfg, 'tos', 'bbcode', $bad, true, 'test')));

// ── restoring is a delete, so the template comes back by itself ─────────────
pageContentSave($db, $cfg, 'tos', 'markdown', "# Mine", true, 'test');
check('restore removes the row', pageContentReset($db, 'tos') && pageContentGet($db, 'tos') === null);
check('… and nothing is active afterwards', pageContentActive($db, 'tos') === null);
$db->exec("DELETE FROM page_content WHERE page IN ('tos','info')");

// ── registration ────────────────────────────────────────────────────────────
$schema = (string)file_get_contents($root . '/includes/schema.php');
check('the table is created', str_contains($schema, 'CREATE TABLE IF NOT EXISTS `page_content`'));
check('the schema version was bumped for it',
      (bool)preg_match('/TRACKER_SCHEMA_VERSION = (\d+)/', $schema, $m) && (int)$m[1] >= 39, $m[1] ?? '?');
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
check('the router consults the override', str_contains($idx, 'pageContentActive($db, $action)'));
check('… and only for the pages in the catalogue', str_contains($idx, 'in_array($action, PAGECONTENT_PAGES, true)'));
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
check('the settings section exists', str_contains($tpl, 'id="section-pages"'));
check('the editor modal exists', str_contains($tpl, 'id="pageEditModal"'));
// The bug this catches: admin-common.js is loaded AFTER admin-settings.js on this page, so an
// editor script placed with the others returns early and the dialog never opens.
check('the editor script is loaded after admin-common.js, which it needs',
      strpos($tpl, 'admin-pagecontent.js') > strpos($tpl, 'admin-common.js'));

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
