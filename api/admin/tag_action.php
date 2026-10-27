<?php
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
$forumOps = ['forum_approve', 'forum_ignore', 'forum_target', 'forum_import', 'forum_all'];
if (!in_array($op, ['approve', 'reject', 'save', 'hide', 'unhide', 'delete', 'merge'], true) && !in_array($op, $forumOps, true)) jsonResponse(['error' => 'invalid'], 400);
$manage = panelCan($db, $cfg, 'panel.tags.manage');
if (in_array($op, $forumOps, true)) {
    if (!$manage) jsonResponse(['success' => false, 'error' => 'forbidden', 'message' => tagsMessage('forbidden')], 403);
    $r = tagsForumDecide($db, $cfg, $op, $input);
    if (empty($r['ok'])) jsonResponse(['success' => false, 'error' => $r['error'], 'message' => tagsMessage((string)$r['error'])], 400);
    $slugs = array_map(fn($s) => is_string($s) ? $s : '', (array)($input['slugs'] ?? [$input['slug'] ?? '']));
    auditLog($db, 'tags.' . $op, ['target_type' => 'api_client', 'target_id' => (string)(int)($input['client'] ?? 0),
        'summary' => mb_substr($op . ' ' . implode(',', $slugs) . (is_string($input['target'] ?? null) ? ' → ' . $input['target'] : '')
                     . ($op === 'forum_import' ? (!empty($input['on']) ? ' on' : ' off') : ''), 0, 200)]);
    jsonResponse(['success' => true, 'n' => (int)$r['n'], 'added' => (int)($r['added'] ?? 0), 'removed' => (int)($r['removed'] ?? 0)]);
}
$r = tagsAdminAction($db, $cfg, $op, $input, $manage);
if (empty($r['ok'])) jsonResponse(['success' => false, 'error' => $r['error'], 'message' => tagsMessage((string)$r['error'])], $r['error'] === 'forbidden' ? 403 : 400);
auditLog($db, 'tags.' . $op, ['target_type' => 'tag', 'target_id' => implode(',', array_map('intval', (array)($input['ids'] ?? [$input['id'] ?? 0]))),
    'summary' => $op . ($op === 'merge' ? ' → #' . (int)($input['into'] ?? 0) : '') . ($op === 'save' ? ' ' . mb_substr((string)($input['name'] ?? ''), 0, 40) : '')]);
jsonResponse(['success' => true, 'n' => (int)$r['n']]);
