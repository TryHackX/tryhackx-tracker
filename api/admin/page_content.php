<?php
/**
 * GET/POST — the Terms and Info pages, as text the operator can edit.
 *
 * GET  ?page=tos|info[&format=bbcode|markdown]
 *      Returns the stored override (if any), the shipped default in the requested format, and a
 *      rendered preview of whichever is current.
 *
 * POST {"op": "save"|"reset"|"preview", "page": …, "format": …, "body": …, "enabled": bool}
 *
 * Not in the permission map, so this is owner-only — the same class of control as Settings itself.
 * A page here is served to every visitor, signed in or not; that is not a moderator's decision.
 */

require_once __DIR__ . '/../../includes/pagecontent.php';

$page = (string)($_GET['page'] ?? '');
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? readJsonBody() : [];
if ($input) $page = (string)($input['page'] ?? $page);
if (!in_array($page, PAGECONTENT_PAGES, true)) {
    jsonResponse(['error' => 'Unknown page.'], 400);
}

$catalog = pageContentCatalog();
$baseUrl = getBaseUrl();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stored = pageContentGet($db, $page);
    $format = (string)($_GET['format'] ?? ($stored['format'] ?? 'markdown'));
    if (!in_array($format, ['bbcode', 'markdown'], true)) $format = 'markdown';
    $body = $stored ? (string)$stored['body'] : pageContentDefault($cfg, $page, $format, $baseUrl);
    jsonResponse([
        'success'  => true,
        'page'     => $page,
        'label'    => $catalog[$page]['label'],
        'route'    => $catalog[$page]['route'],
        'stored'   => $stored !== null,
        'enabled'  => $stored['enabled'] ?? false,
        'format'   => $stored['format'] ?? $format,
        'body'     => $body,
        // The default in BOTH formats, so switching the picker in the editor does not need a
        // round-trip and cannot lose what the operator has typed by fetching over it.
        'default'  => [
            'markdown' => pageContentDefault($cfg, $page, 'markdown', $baseUrl),
            'bbcode'   => pageContentDefault($cfg, $page, 'bbcode', $baseUrl),
        ],
        'updated_at' => $stored['updated_at'] ?? null,
        'updated_by' => $stored['updated_by'] ?? null,
        'max'      => PAGECONTENT_MAX,
        'formats'  => richtextFormats($cfg),
        // Stated once, here, because it is the one consequence of editing that is not obvious: the
        // shipped pages change themselves when the tracker mode does, and a saved one cannot.
        'note'     => 'The built-in pages rewrite themselves when the tracker mode or the account '
                    . 'system changes. A saved page does not — it is your text from then on. '
                    . 'Restore brings back the built-in one, written for how the tracker is '
                    . 'configured right now.',
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
    if (!pageContentReset($db, $page)) jsonResponse(['error' => 'Could not restore the page.'], 500);
    auditNote(['target_id' => $page, 'summary' => 'restored the built-in ' . $catalog[$page]['label']]);
    jsonResponse(['success' => true, 'stored' => false, 'enabled' => false,
                  'body' => pageContentDefault($cfg, $page, $format, $baseUrl),
                  'message' => $catalog[$page]['label'] . ' is the built-in page again.']);
}

$who = (string)($_SESSION['admin_user'] ?? $_SESSION['username'] ?? 'owner');
$r = pageContentSave($db, $cfg, $page, $format, $body, !empty($input['enabled']), $who);
if (isset($r['error'])) jsonResponse(['error' => $r['error']], 400);
auditNote(['target_id' => $page, 'summary' => (!empty($input['enabled']) ? 'published' : 'saved a draft of')
                                            . ' ' . $catalog[$page]['label']]);
jsonResponse(['success' => true, 'stored' => true, 'enabled' => !empty($input['enabled']),
              'message' => !empty($input['enabled'])
                  ? $catalog[$page]['label'] . ' is now your version — it is live.'
                  : 'Saved as a draft. The built-in page is still the one visitors see.']);
