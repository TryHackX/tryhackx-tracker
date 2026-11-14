<?php
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
$me = usersEnabled($cfg) ? currentUser($db) : null;
if (!$me) jsonResponse(['error' => 'login_required'], 401);
if (!tagsEnabled($cfg) || !tagsCatReady($db) || !userCan($db, $cfg, 'tags.categorize')) {
    jsonResponse(['error' => 'forbidden', 'message' => __('api.tags.forbidden')], 403);
}
if (!rateLimitAllow('tags', 'u' . (int)$me['id'], 240, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'message' => __('api.tags.rate')], 429);
}
$str = static fn($v): string => is_string($v) ? $v : '';
$r = tagsCatSuggest($db, strtolower(trim($str($input['tag'] ?? null))), strtolower(trim($str($input['category'] ?? null))), $str($input['name'] ?? null),
                    (int)$me['id'], userCan($db, $cfg, 'tags.moderate'));
if (empty($r['ok'])) jsonResponse(['success' => false, 'error' => $r['error'], 'message' => tagsMessage((string)$r['error'])], 400);
jsonResponse(['success' => true, 'direct' => !empty($r['direct']), 'message' => __(!empty($r['direct']) || !empty($r['same']) ? 'api.tags.cat_set' : 'api.tags.cat_sent')]);
