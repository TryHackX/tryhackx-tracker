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

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? readJsonBody() : [];

$page = (string)($_GET['page'] ?? '');
if ($input) $page = (string)($input['page'] ?? $page);
if (!in_array($page, PAGECONTENT_PAGES, true)) {
    jsonResponse(['error' => 'Unknown page.'], 400);
}

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
        'label'    => $catalog[$page]['label'],
        'route'    => $catalog[$page]['route'],
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
        // Stated once, here, because it is the one consequence of editing that is not obvious: the
        // shipped pages change themselves when the tracker mode does, and a saved one cannot.
        'note'     => 'The built-in pages rewrite themselves when the tracker mode or the account '
                    . 'system changes. A saved page does not — it is your text from then on. '
                    . 'Restore brings back the built-in one for this language, written for how the '
                    . 'tracker is configured right now.',
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
    jsonResponse(['success' => true, 'html' => richtextRender($body, $format, $cfg, true),
                  'warning' => $err, 'chars' => strlen($body)]);
}

if ($op === 'reset') {
    // This language only. "Restore" while editing Polish must not delete an English page somebody
    // spent an afternoon writing.
    if (!pageContentReset($db, $page, $lang)) jsonResponse(['error' => 'Could not restore the page.'], 500);
    auditNote(['target_id' => $page . '/' . $lang,
               'summary' => 'restored the built-in ' . $catalog[$page]['label'] . ' (' . strtoupper($lang) . ')']);
    jsonResponse(['success' => true, 'stored' => false, 'enabled' => false, 'lang' => $lang,
                  'body' => pageContentDefault($cfg, $page, $format, $baseUrl, $lang),
                  'message' => $catalog[$page]['label'] . ' (' . strtoupper($lang) . ') is the built-in page again.']);
}

$who = (string)($_SESSION['admin_user'] ?? $_SESSION['username'] ?? 'owner');
$r = pageContentSave($db, $cfg, $page, $lang, $format, $body, !empty($input['enabled']), $who);
if (isset($r['error'])) jsonResponse(['error' => $r['error']], 400);
auditNote(['target_id' => $page . '/' . $lang,
           'summary' => (!empty($input['enabled']) ? 'published' : 'saved a draft of')
                      . ' ' . $catalog[$page]['label'] . ' (' . strtoupper($lang) . ')']);
jsonResponse(['success' => true, 'stored' => true, 'enabled' => !empty($input['enabled']), 'lang' => $lang,
              'message' => !empty($input['enabled'])
                  ? $catalog[$page]['label'] . ' (' . strtoupper($lang) . ') is now your version — it is live.'
                  : 'Saved as a draft. The built-in page is still the one visitors see.']);
