<?php
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
if (!in_array($op, ['approve', 'reject', 'save', 'hide', 'unhide', 'delete', 'merge'], true)) jsonResponse(['error' => 'invalid'], 400);
$manage = panelCan($db, $cfg, 'panel.tags.manage');
$r = tagsAdminAction($db, $cfg, $op, $input, $manage);
if (empty($r['ok'])) jsonResponse(['success' => false, 'error' => $r['error'], 'message' => tagsMessage((string)$r['error'])], $r['error'] === 'forbidden' ? 403 : 400);
auditLog($db, 'tags.' . $op, ['target_type' => 'tag', 'target_id' => implode(',', array_map('intval', (array)($input['ids'] ?? [$input['id'] ?? 0]))),
    'summary' => $op . ($op === 'merge' ? ' → #' . (int)($input['into'] ?? 0) : '') . ($op === 'save' ? ' ' . mb_substr((string)($input['name'] ?? ''), 0, 40) : '')]);
jsonResponse(['success' => true, 'n' => (int)$r['n']]);
