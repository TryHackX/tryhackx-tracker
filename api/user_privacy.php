<?php
/**
 * user_privacy — the two flags that decide what a reader's account says about them to other people.
 *
 *   fav_public — may a stranger read my favourites on my profile?
 *   fav_listed — may my name appear on somebody else's "who has this in favourites"?
 *
 * Two flags, not one, because they answer two different questions. Somebody may be happy to publish
 * a list on a page they chose to publish, and not happy to be enumerated from a torrent's page by
 * anyone who can type a hash. Saying yes to one has never been saying yes to the other.
 *
 * No password. These are preferences, like the mail ones next to them; demanding a password to tick
 * a box teaches people that the password prompt means nothing.
 *
 * A flag can be SET while the feature that reads it is off — and that is deliberate: turning the
 * site setting off and on again must not lose anybody's answer, exactly as usersEnabled() does not
 * throw away accounts. What the flag MEANS is decided where it is read, never here.
 *
 * GET  → {fav_public, fav_listed, may_publish}
 * POST {csrf_token, fav_public?:0|1, fav_listed?:0|1}
 */
if (!usersEnabled($cfg)) jsonResponse(['error' => 'accounts_disabled'], 400);
$u = currentUser($db);
if (!$u) jsonResponse(['error' => 'not_logged_in'], 401);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
        jsonResponse(['error' => __('api.csrf.invalid')], 403);
    }
    $sets = [];
    $args = [];
    foreach (['fav_public', 'fav_listed'] as $flag) {
        if (!array_key_exists($flag, $input)) continue;
        $sets[] = "`$flag` = ?";
        $args[] = !empty($input[$flag]) ? 1 : 0;
    }
    if (!$sets) jsonResponse(['error' => 'nothing_to_change'], 400);
    $args[] = (int)$u['id'];
    $db->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
    $u = userFindById($db, (int)$u['id']) ?: $u;
}

jsonResponse([
    'success'     => true,
    'fav_public'  => (int)($u['fav_public'] ?? 0) === 1,
    'fav_listed'  => (int)($u['fav_listed'] ?? 0) === 1,
    // What the site currently does with them, so the page can say "this is off for everyone right
    // now" instead of showing a control that silently does nothing.
    'may_publish' => favPublicEnabled($cfg) && userCan($db, $cfg, 'favourites.public'),
    'who_enabled' => favWhoEnabled($cfg),
]);
