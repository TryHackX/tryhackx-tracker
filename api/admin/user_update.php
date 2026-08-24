<?php
// Admin edit of one user: status (active|banned), email, password, email_verified.
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$u = userFindById($db, $id);
if (!$u) jsonResponse(['error' => 'User not found'], 404);

$changed = [];
if (isset($input['status'])) {
    $status = (string)$input['status'];
    if (!in_array($status, ['active', 'banned'], true)) jsonResponse(['error' => 'Invalid status'], 400);
    $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$status, $id]);
    if ($status === 'banned') {
        // a banned user must not ride an existing remember-me cookie back in
        $db->prepare("DELETE FROM user_tokens WHERE user_id = ?")->execute([$id]);
    }
    $changed[] = 'status';
}
if (array_key_exists('email', $input)) {
    $email = trim((string)$input['email']);
    if ($email !== '' && !userValidEmail($email)) jsonResponse(['error' => 'Invalid email'], 400);
    try {
        $db->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email !== '' ? $email : null, $id]);
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) jsonResponse(['error' => 'Another account already uses this email'], 400);
        throw $e;
    }
    $changed[] = 'email';
}
if (!empty($input['password'])) {
    $p = (string)$input['password'];
    if (strlen($p) < 8 || strlen($p) > 200) jsonResponse(['error' => 'Password must be at least 8 characters'], 400);
    $db->prepare("UPDATE users SET pass_hash = ? WHERE id = ?")->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
    $db->prepare("DELETE FROM user_tokens WHERE type = 'remember' AND user_id = ?")->execute([$id]);
    $changed[] = 'password';
}
if (isset($input['email_verified'])) {
    $db->prepare("UPDATE users SET email_verified = ? WHERE id = ?")->execute([!empty($input['email_verified']) ? 1 : 0, $id]);
    $changed[] = 'email_verified';
}
if (!$changed) jsonResponse(['error' => 'Nothing to change'], 400);
jsonResponse(['success' => true, 'changed' => $changed]);
