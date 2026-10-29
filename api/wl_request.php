<?php
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken((string)$input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
$me = usersEnabled($cfg) ? currentUser($db) : null;
if (!$me) jsonResponse(['error' => 'login_required'], 401);
if (!wlReqOn($cfg) || !wlReqReady($db) || !userCan($db, $cfg, 'whitelist.request')) {
    jsonResponse(['error' => __('api.wlreq.forbidden')], 403);
}
if (!rateLimitAllow('wlreq', 'u' . (int)$me['id'], 30, 3600)) {
    jsonResponse(['error' => __('api.wlreq.rate')], 429);
}
$hash = strtolower(trim(is_string($input['hash'] ?? null) ? $input['hash'] : ''));
$r = wlReqSubmit($db, $cfg, $hash, (int)$me['id'], is_string($input['note'] ?? null) ? $input['note'] : '');
if (empty($r['ok'])) {
    $words = ['listed' => 'api.wlreq.listed', 'not_found' => 'api.wlreq.not_found', 'cooldown' => 'api.wlreq.cooldown', 'limit' => 'api.wlreq.limit', 'off' => 'api.wlreq.forbidden'];
    jsonResponse(['success' => false, 'error' => __($words[$r['error']] ?? 'api.wlreq.invalid', ['n' => wlReqPerDay($cfg), 'days' => wlReqDays($cfg)])], 400);
}
$state = wlReqState($db, $cfg, $hash, $me);
jsonResponse(['success' => true, 'message' => __(!empty($r['already']) ? 'api.wlreq.already' : 'api.wlreq.sent'), 'state' => $state]);
