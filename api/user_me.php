<?php
/**
 * GET user_me — the logged-in user's profile: groups (with expiry), permissions, unread count.
 * 401 when not logged in (the account page redirects to login).
 */
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);

$groups = [];
foreach (userGroups($db, (int)$u['id']) as $g) {
    $groups[] = [
        'slug' => $g['slug'], 'name' => $g['name'], 'color' => $g['color'], 'description' => $g['description'],
        'granted_at' => $g['granted_at'], 'expires_at' => $g['expires_at'], 'note' => $g['note'],
    ];
}
$verifyGate = userEmailVerifyRequired($cfg);
$trusted = userIsEmailTrusted($db, (int)$u['id']);
jsonResponse([
    'success' => true,
    'user' => [
        'id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'],
        'email_verified' => (int)$u['email_verified'] === 1,
        'created_at' => $u['created_at'], 'last_login_at' => $u['last_login_at'],
    ],
    'groups' => $groups,
    'permissions' => array_keys(userEffectivePermissions($db, (int)$u['id'], $cfg)),
    // verification gate: with it on, an unverified account runs at guest level (admins exempt)
    'verify_required' => $verifyGate,
    'verify_restricted' => $verifyGate && !$trusted && !userIsAdminGroup($db, (int)$u['id']),
    'email_change' => userEmailChangeState($db, $u),
    'unread' => userUnreadCount($db, (int)$u['id']),
]);
