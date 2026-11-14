<?php
requirePost();
$input = readJsonBody();
$op = is_string($input['op'] ?? null) ? $input['op'] : '';
$r = indexBlockedAction($db, $cfg, $op, (array)($input['hashes'] ?? []));
if (empty($r['ok'])) jsonResponse(['success' => false, 'error' => $r['error']], 400);
auditLog($db, 'index.hidden_' . $op, ['target_type' => 'torrent', 'target_id' => (string)(((array)($input['hashes'] ?? []))[0] ?? ''), 'summary' => $op . ' ' . (int)$r['n']]);
jsonResponse(['success' => true, 'n' => (int)$r['n']]);
