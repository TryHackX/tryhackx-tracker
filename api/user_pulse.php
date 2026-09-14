<?php
/**
 * GET user_pulse — two numbers for the navigation badge: notifications waiting, messages waiting.
 *
 * The cheap heartbeat of a signed-in reader anywhere on the site (assets/js/app.js, the pulse
 * loop). It exists because user_me — which the pages used to call once at load and never again —
 * answers with the whole account: groups, permissions, the email-change state, and the number on the
 * account link was therefore a snapshot of whenever the page was opened. This answers with counts
 * and nothing else, lets go of the session before the reads so the reader's other requests are not
 * queued behind it, and says how often to ask again (`live`, from Settings; 0 = do not).
 *
 * Numbers, never content: what arrived is read where it lives, on the account page.
 */
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled', 'live' => 0], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in', 'live' => 0], 401);
// Read-only from here: the file-backed session lock would otherwise serialise every other request
// this browser makes behind a poll that has nothing to write.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

jsonResponse([
    'success'   => true,
    'unread'    => userUnreadCount($db, (int)$u['id']),
    'unread_pm' => pmEnabled($cfg) ? pmUnreadCount($db, (int)$u['id']) : 0,
    // … of which from friends: the sounds tell a friend's message from a stranger's
    'unread_pm_friend' => pmEnabled($cfg) ? pmUnreadCountFriends($db, (int)$u['id']) : 0,
    'live'      => siteLiveSeconds($cfg),
]);
