<?php
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
$me = usersEnabled($cfg) ? currentUser($db) : null;
if (!$me) jsonResponse(['error' => 'login_required'], 401);
$moderate = userCan($db, $cfg, 'tags.moderate');
if (!tagsEnabled($cfg) || !tagsReady($db) || (!$moderate && !userCan($db, $cfg, 'tags.add'))) {
    jsonResponse(['error' => 'forbidden', 'message' => __('api.tags.forbidden')], 403);
}
if (!rateLimitAllow('tags', 'u' . (int)$me['id'], 240, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'message' => __('api.tags.rate')], 429);
}
$hash = strtolower(trim((string)($input['hash'] ?? '')));
if (!tagsHashKnown($db, $hash)) jsonResponse(['error' => 'not_found', 'message' => __('api.tags.not_found')], 404);
$id = strtolower(trim((string)($input['tag'] ?? '')));
$r = tagsDetach($db, $hash, $id, (int)$me['id'], $moderate);
if (empty($r['ok'])) jsonResponse(['error' => $r['error'], 'message' => tagsMessage((string)$r['error'])], 400);
tagsLog($db, $hash, $id, $r['removed'] === 'blocked' ? 'block' : 'remove', (int)$me['id']);
tagsCount($db, $id);
if ($moderate && ($r['removed'] === 'blocked' || !empty($r['others']))) {
    auditLog($db, $r['removed'] === 'blocked' ? 'tags.block' : 'tags.remove', ['target_type' => 'torrent', 'target_id' => $hash,
        'summary' => (string)($me['username'] ?? '#' . (int)$me['id']) . ' → ' . $id]);
}
$canContent = userCan($db, $cfg, 'content.view') && (richtextViewerSignedIn($db) || !(function_exists('forumDescMembersOnly') && forumDescMembersOnly($db, $hash)));
jsonResponse(['success' => true, 'tag' => $id, 'message' => __($r['removed'] === 'blocked' ? 'api.tags.blocked_done' : 'api.tags.removed'),
              'facts' => tagsHashChips($db, $cfg, $hash, (int)$me['id'], $moderate, true, $canContent)]);
