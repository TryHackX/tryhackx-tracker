<?php
// Admin: send a custom in-app notification (optionally an email copy). Body: {id, title, body?, email?}
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$u = userFindById($db, $id);
if (!$u) jsonResponse(['error' => 'User not found'], 404);
$title = mb_substr(trim((string)($input['title'] ?? '')), 0, 190);
if ($title === '') jsonResponse(['error' => 'Title is required'], 400);
$body = mb_substr(trim((string)($input['body'] ?? '')), 0, 5000);
userNotify($db, $id, 'admin', $title, $body);
$mailed = false;
if (!empty($input['email']) && trim((string)$u['email']) !== '') {
    userNotifyMail($db, $cfg, $u, ($cfg['site_name'] ?? 'Tracker') . ' — ' . $title, $body !== '' ? $body : $title);
    $mailed = true;
}
jsonResponse(['success' => true, 'mailed' => $mailed]);
