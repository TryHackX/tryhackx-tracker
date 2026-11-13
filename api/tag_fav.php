<?php
$me = usersEnabled($cfg) ? currentUser($db) : null;
if (!$me) jsonResponse(['error' => 'login_required'], 401);
if (!tagsEnabled($cfg) || !tagsFavReady($db) || !userCan($db, $cfg, 'tags.favourite')) {
    jsonResponse(['error' => 'forbidden', 'message' => __('api.tags.forbidden')], 403);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse(['success' => true, 'tags' => tagsFavChips($db, tagsFavList($db, (int)$me['id']))]);
}
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
if (!rateLimitAllow('tags', 'u' . (int)$me['id'], 240, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'message' => __('api.tags.rate')], 429);
}
$id = strtolower(trim(is_string($input['tag'] ?? null) ? $input['tag'] : ''));
$r = tagsFavSet($db, (int)$me['id'], $id, !empty($input['on']));
if (empty($r['ok'])) jsonResponse(['success' => false, 'error' => $r['error'], 'message' => tagsMessage((string)$r['error'])], 400);
jsonResponse(['success' => true, 'tag' => $id, 'on' => $r['on']]);
