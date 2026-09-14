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

$out = [
    'success'   => true,
    'unread'    => userUnreadCount($db, (int)$u['id']),
    'unread_pm' => pmEnabled($cfg) ? pmUnreadCount($db, (int)$u['id']) : 0,
    // … of which from friends: the sounds tell a friend's message from a stranger's
    'unread_pm_friend' => pmEnabled($cfg) ? pmUnreadCountFriends($db, (int)$u['id']) : 0,
    'live'      => siteLiveSeconds($cfg),
];
// The shoutbox, when there is one and this reader may see it. THREE numbers because the sounds
// answer three events — a friend said something, somebody else said something, somebody said my
// name — and `unread_shout` is the total the other two are subsets of. Absent entirely when the
// feature is off, so a site without a shoutbox pays nothing and its pulse reply is what it was.
if (function_exists('shoutEnabled') && shoutEnabled($cfg) && userCan($db, $cfg, 'shout.view')) {
    $sc = shoutUnreadCounts($db, $cfg, $u);
    $out['unread_shout'] = $sc['shout'];
    $out['unread_shout_friend'] = $sc['shout_friend'];
    $out['unread_shout_mention'] = $sc['mention'];
}
jsonResponse($out);
