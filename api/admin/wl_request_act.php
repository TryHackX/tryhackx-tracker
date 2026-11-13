<?php
requirePost();
$input = readJsonBody();
$op = is_string($input['op'] ?? null) ? $input['op'] : '';
$id = (int)($input['id'] ?? 0);
$actor = usersEnabled($cfg) ? currentUser($db) : null;
$r = wlReqDecide($db, $cfg, $id, $op, is_string($input['reply'] ?? null) ? $input['reply'] : '', $actor ? (int)$actor['id'] : null);
if (empty($r['ok'])) jsonResponse(['success' => false, 'error' => __('api.wlreq.e_' . $r['error'])], 400);
auditLog($db, 'whitelist.request_' . $op, ['target_type' => 'wl_request', 'target_id' => (string)$id, 'summary' => $op]);
jsonResponse(['success' => true]);
