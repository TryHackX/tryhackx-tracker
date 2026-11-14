<?php
// POST user_logout — end the user session (the admin panel session, if any, is untouched).
requirePost();
$input = readJsonBody();
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}
userSessionLogout($db);
jsonResponse(['success' => true]);
