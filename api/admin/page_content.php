<?php
/**
 * GET/POST — the Terms and Info pages, as text the operator can edit, ONE VERSION PER LANGUAGE.
 *
 * GET  ?page=tos|info[&lang=xx][&format=bbcode|markdown]
 *      Returns the stored version for that language (if any), the shipped default in the requested
 *      format AND language, and which languages already have a version.
 *
 * POST {"op": "save"|"reset"|"preview", "page": …, "lang": …, "format": …, "body": …, "enabled": bool}
 *
 * Not in the permission map, so this is owner-only — the same class of control as Settings itself.
 * A page here is served to every visitor, signed in or not; that is not a moderator's decision.
 */

require_once __DIR__ . '/../../includes/pagecontent.php';
require_once __DIR__ . '/../../includes/homeblocks.php';

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? readJsonBody() : [];

$page = (string)($_GET['page'] ?? '');
if ($input) $page = (string)($input['page'] ?? $page);
if (!pageContentPageKnown($page, $cfg)) {
    jsonResponse(['error' => 'Unknown page.'], 400);
}
$isHome = str_starts_with($page, 'home:');

// Any INSTALLED language may be edited, not merely an enabled one: a translation is written before
// it is switched on, and an editor that refused would make that impossible.
$lang = strtolower(trim((string)($input['lang'] ?? $_GET['lang'] ?? '')));
if ($lang === '' || !langInstalled($lang)) {
    $lang = langInstalled((string)($cfg['default_language'] ?? '')) ? (string)$cfg['default_language'] : LANG_FALLBACK;
}

$catalog = pageContentCatalog();
$baseUrl = getBaseUrl();

/** Which languages are editable here, with what each already has. */
function pageContentLangs(PDO $db, array $cfg, string $page): array {
    $all = pageContentAll($db)[$page] ?? [];
    $out = [];
    foreach (langAvailable() as $code => $name) {
        $r = $all[$code] ?? null;
        $out[] = [
            'code'    => $code,
            'name'    => $name,
            'stored'  => $r !== null,
            'enabled' => $r !== null && $r['enabled'],
            'updated_at' => $r['updated_at'] ?? null,
        ];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stored = pageContentGet($db, $page, $lang);
    $format = (string)($_GET['format'] ?? ($stored['format'] ?? 'markdown'));
    if (!in_array($format, ['bbcode', 'markdown'], true)) $format = 'markdown';
    $body = $stored ? (string)$stored['body'] : pageContentDefault($cfg, $page, $format, $baseUrl, $lang);
    // Which version a visitor in THIS language is served right now — the chain in
    // pageContentLangChain() means it may well be a version written for another language.
    $active = pageContentActive($db, $page, $cfg, $lang);
    jsonResponse([
        'success'  => true,
        'page'     => $page,
        'lang'     => $lang,
        'label'    => pageContentLabel($page, $cfg),
        'route'    => $isHome ? 'home' : $catalog[$page]['route'],
        // What a home section may paste in. Terms and Info have none: they are prose.
        'placeholders' => $isHome ? array_map(fn($k, $v) => ['name' => $k, 'what' => $v],
                                              array_keys(homePlaceholderList()), homePlaceholderList()) : [],
        'stored'   => $stored !== null,
        'enabled'  => $stored['enabled'] ?? false,
        'format'   => $stored['format'] ?? $format,
        'body'     => $body,
        // The default in BOTH formats, so switching the picker in the editor does not need a
        // round-trip and cannot lose what the operator has typed by fetching over it.
        'default'  => [
            'markdown' => pageContentDefault($cfg, $page, 'markdown', $baseUrl, $lang),
            'bbcode'   => pageContentDefault($cfg, $page, 'bbcode', $baseUrl, $lang),
        ],
        'updated_at' => $stored['updated_at'] ?? null,
        'updated_by' => $stored['updated_by'] ?? null,
        'languages'  => pageContentLangs($db, $cfg, $page),
        'serving'    => $active ? $active['lang'] : null,
        'max'      => PAGECONTENT_MAX,
        'formats'  => richtextFormats($cfg),
        // Every condition a page may test, with its state right now — the editor lists them.
        'markers'  => array_map(fn($k, $v) => ['name' => $k, 'on' => (bool)$v[0], 'what' => $v[1]],
                                array_keys(pageContentConditions($cfg, $db)), pageContentConditions($cfg, $db)),
        'note'     => 'Blocks wrapped in [[if:name]] … [[/if]] (or [[ifnot:name]]) appear only while '
                    . 'that setting is on, so a page you save keeps following the tracker mode and the '
                    . 'account system exactly as the built-in one does. Restore brings back the built-in '
                    . 'text for this language, markers included.',
    ]);
}

requirePost();
$op = strtolower(trim((string)($input['op'] ?? '')));
if (!in_array($op, ['save', 'reset', 'preview'], true)) {
    jsonResponse(['error' => 'Unknown operation. Use save, reset or preview.'], 400);
}

$format = (string)($input['format'] ?? 'markdown');
if (!in_array($format, ['bbcode', 'markdown'], true)) $format = 'markdown';
$body = (string)($input['body'] ?? '');

if ($op === 'preview') {
    // Rendered exactly as the page will be, including the signed-in state of whoever is looking —
    // a preview that hides what a [hide] block does would be a preview of a different page.
    if (strlen($body) > PAGECONTENT_MAX) {
        jsonResponse(['error' => 'That is longer than ' . number_format(PAGECONTENT_MAX) . ' characters.'], 400);
    }
    $err = trim($body) === '' ? null : richtextValidate($body, $format, $cfg);
    // Resolved the way the public page resolves them, so the preview is the page. A marker nobody
    // knows is worth saying out loud: it is far more likely a typo than a request to show nothing.
    $unknown = [];
    $resolved = pageContentResolveMarkers($body, $cfg, $db, $unknown);
    if ($unknown && $err === null) {
        $err = 'Unknown marker' . (count($unknown) === 1 ? '' : 's') . ': ' . implode(', ', $unknown)
             . ' — the block is hidden. Known: ' . implode(', ', array_keys(pageContentConditions($cfg, $db))) . '.';
    }
    $html = richtextRender($resolved, $format, $cfg, true);
    if ($isHome) {
        // The same bodies the page builds, pasted the same way — the preview IS the page.
        $unknownPh = [];
        $html = homeApplyPlaceholders($html, homePlaceholders($db, $cfg, $baseUrl), $unknownPh);
        if ($unknownPh && $err === null) {
            $err = 'Unknown placeholder' . (count($unknownPh) === 1 ? '' : 's') . ': ' . implode(', ', $unknownPh)
                 . ' — removed. Known: ' . implode(', ', array_keys(homePlaceholderList())) . '.';
        }
        $html = '<div class="rt-home">' . $html . '</div>';
    }
    jsonResponse(['success' => true, 'html' => $html, 'warning' => $err, 'chars' => strlen($body)]);
}

if ($op === 'reset') {
    // This language only. "Restore" while editing Polish must not delete an English page somebody
    // spent an afternoon writing.
    if (!pageContentReset($db, $page, $lang)) jsonResponse(['error' => 'Could not restore the page.'], 500);
    auditNote(['target_id' => $page . '/' . $lang,
               'summary' => 'restored the built-in ' . pageContentLabel($page, $cfg) . ' (' . strtoupper($lang) . ')']);
    jsonResponse(['success' => true, 'stored' => false, 'enabled' => false, 'lang' => $lang,
                  'body' => pageContentDefault($cfg, $page, $format, $baseUrl, $lang),
                  'message' => pageContentLabel($page, $cfg) . ' (' . strtoupper($lang) . ') is the built-in page again.']);
}

$who = (string)($_SESSION['admin_user'] ?? $_SESSION['username'] ?? 'owner');
$r = pageContentSave($db, $cfg, $page, $lang, $format, $body, !empty($input['enabled']), $who);
if (isset($r['error'])) jsonResponse(['error' => $r['error']], 400);
auditNote(['target_id' => $page . '/' . $lang,
           'summary' => (!empty($input['enabled']) ? 'published' : 'saved a draft of')
                      . ' ' . pageContentLabel($page, $cfg) . ' (' . strtoupper($lang) . ')']);
jsonResponse(['success' => true, 'stored' => true, 'enabled' => !empty($input['enabled']), 'lang' => $lang,
              'message' => !empty($input['enabled'])
                  ? pageContentLabel($page, $cfg) . ' (' . strtoupper($lang) . ') is now your version — it is live.'
                  : 'Saved as a draft. The built-in ' . ($isHome ? 'section' : 'page') . ' is still the one visitors see.']);
