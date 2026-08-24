<?php
// Admin edit of one user: status (active|banned), email, password, email_verified.
// EVERY field is validated up front and the writes run in one transaction — a later validation
// failure must never leave an earlier field (e.g. a ban + token wipe) silently committed.
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$u = userFindById($db, $id);
if (!$u) jsonResponse(['error' => 'User not found'], 404);

// ── validate everything first ──
$status = null;
if (isset($input['status'])) {
    $status = (string)$input['status'];
    if (!in_array($status, ['active', 'banned'], true)) jsonResponse(['error' => 'Invalid status'], 400);
    if ($status === 'banned' && userIsRootAdmin($u, $cfg)) jsonResponse(['error' => 'The site owner account cannot be banned.'], 400);
}
$email = null; $emailSet = false;
if (array_key_exists('email', $input)) {
    $emailSet = true;
    $email = trim((string)$input['email']);
    if ($email !== '' && !userValidEmail($email)) jsonResponse(['error' => 'Invalid email'], 400);
}
$password = (string)($input['password'] ?? '');
if ($password !== '' && !userValidPassword($password)) {
    jsonResponse(['error' => 'Password: ' . USER_PASSWORD_RULES], 400);
}
if ($status === null && !$emailSet && $password === '' && !isset($input['email_verified'])) {
    jsonResponse(['error' => 'Nothing to change'], 400);
}

// ── apply atomically ──
$changed = [];
$db->beginTransaction();
try {
    if ($status !== null) {
        $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$status, $id]);
        if ($status === 'banned') {
            // a banned user must not ride an existing remember-me cookie back in
            $db->prepare("DELETE FROM user_tokens WHERE user_id = ?")->execute([$id]);
        }
        $changed[] = 'status';
    }
    if ($emailSet) {
        // an admin-entered address counts as verified (the admin vouches for it)
        $db->prepare("UPDATE users SET email = ?, email_verified = ? WHERE id = ?")
           ->execute([$email !== '' ? $email : null, $email !== '' ? 1 : 0, $id]);
        $changed[] = 'email';
    }
    if ($password !== '') {
        $db->prepare("UPDATE users SET pass_hash = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        $db->prepare("DELETE FROM user_tokens WHERE type = 'remember' AND user_id = ?")->execute([$id]);
        $changed[] = 'password';
    }
    if (isset($input['email_verified'])) {
        $db->prepare("UPDATE users SET email_verified = ? WHERE id = ?")->execute([!empty($input['email_verified']) ? 1 : 0, $id]);
        $changed[] = 'email_verified';
    }
    $db->commit();
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ((int)$e->errorInfo[1] === 1062) jsonResponse(['error' => 'Another account already uses this email'], 400);
    throw $e;
}
jsonResponse(['success' => true, 'changed' => $changed]);
