<?php
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
$me = usersEnabled($cfg) ? currentUser($db) : null;
if (!$me) jsonResponse(['error' => 'login_required'], 401);
if (!tagsEnabled($cfg) || !tagsReady($db) || !userCan($db, $cfg, 'tags.add')) {
    jsonResponse(['error' => 'forbidden', 'message' => __('api.tags.forbidden')], 403);
}
if (!rateLimitAllow('tags', 'u' . (int)$me['id'], 240, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'message' => __('api.tags.rate')], 429);
}
$hash = strtolower(trim((string)($input['hash'] ?? '')));
if (!tagsHashKnown($db, $hash)) jsonResponse(['error' => 'not_found', 'message' => __('api.tags.not_found')], 404);
$moderate = userCan($db, $cfg, 'tags.moderate');
$id = strtolower(trim((string)($input['tag'] ?? '')));
$created = null;
if ($id === '') {
    if (!userCan($db, $cfg, 'tags.create')) jsonResponse(['error' => 'forbidden', 'message' => __('api.tags.forbidden')], 403);
    $created = tagsCreate($db, $cfg, (string)($input['name'] ?? ''), (int)$me['id'], $moderate, $moderate ? 'moderator' : 'member');
    if (empty($created['ok'])) jsonResponse(['error' => $created['error'], 'message' => tagsMessage((string)$created['error'])], 400);
    $id = (string)$created['id'];
}
$r = tagsAttach($db, $cfg, $hash, $id, (int)$me['id'], $moderate ? TAG_ORIGIN_MODERATOR : TAG_ORIGIN_MEMBER, $moderate,
               !$moderate && ($cfg['tags_add_review'] ?? 'open') === 'approve');
if (empty($r['ok'])) jsonResponse(['error' => $r['error'], 'message' => tagsMessage((string)$r['error'])], 400);
tagsLog($db, $hash, $id, 'add', (int)$me['id']);
tagsCount($db, $id);
if (!empty($r['unblocked'])) {
    auditLog($db, 'tags.unblock', ['target_type' => 'torrent', 'target_id' => $hash, 'summary' => (string)($me['username'] ?? '#' . (int)$me['id']) . ' → ' . $id]);
}
$pending = ($created['status'] ?? '') === 'pending';
$waits = !$pending && !empty($r['pending']);
$canContent = userCan($db, $cfg, 'content.view') && (richtextViewerSignedIn($db) || !(function_exists('forumDescMembersOnly') && forumDescMembersOnly($db, $hash)));
jsonResponse(['success' => true, 'tag' => $id, 'pending' => $pending || $waits,
              'message' => __($pending ? 'api.tags.pending' : ($waits ? 'api.tags.add_pending' : 'api.tags.added')),
              'facts' => tagsHashChips($db, $cfg, $hash, (int)$me['id'], $moderate, true, $canContent)]);
