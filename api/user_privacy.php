<?php
/**
 * user_privacy — the two flags that decide what a reader's account says about them to other people.
 *
 *   fav_public   — may a stranger read my favourites on my profile?
 *   fav_listed   — may my name appear on somebody else's "who has this in favourites"?
 *   lists_public — may anybody else — a stranger, or (1.72.0) a friend — see that I have lists at all?
 *                  (each list still carries its own answer: private, friends or public)
 *   votes_public — may a stranger see what I liked or rated, thumbs down and low stars included?
 *                  (1.69.0, includes/profilevotes.php; the group still needs rating.public)
 *   content_credit_public — is my name shown on the descriptions I write and edit? (1.70.0, ON unless
 *                  switched off; off, every place that credits me says "a member")
 *   descriptions_public — may a stranger see the list of descriptions I wrote? (1.70.0,
 *                  includes/profiledescs.php; the group still needs content.public, and my name
 *                  has to be shown)
 *   votes_listed — may my name, with my vote beside it, appear in a torrent's "who has this"? (1.70.0,
 *                  includes/who.php — the likes' twin of fav_listed; votes_public is needed as well)
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
    // votes_public (1.69.0): may a stranger see my likes or ratings — see includes/profilevotes.php.
    // content_credit_public / descriptions_public (1.70.0): my name on my descriptions, and my list of them.
    // votes_listed (1.70.0): my name and my vote in a torrent's "who has this" — see includes/who.php.
    foreach (['fav_public', 'fav_listed', 'lists_public', 'content_credit_public', 'descriptions_public', 'votes_listed',
              'profile_listed', 'votes_public'] as $flag) {
        if (!array_key_exists($flag, $input)) continue;
        $sets[] = "`$flag` = ?";
        $args[] = !empty($input[$flag]) ? 1 : 0;
    }
    // Who may write to me. NULL is a real answer here — "whatever the site says" — so it is set by
    // sending an empty string, not by leaving the key out (which means "do not touch this").
    if (array_key_exists('pm_who', $input)) {
        $v = (string)$input['pm_who'];
        $sets[] = "`pm_who` = ?";
        $args[] = in_array($v, ['all', 'friends', 'nobody'], true) ? $v : null;
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
    'lists_public' => (int)($u['lists_public'] ?? 0) === 1,
    'profile_listed' => (int)($u['profile_listed'] ?? 0) === 1,
    'pm_who'       => $u['pm_who'] ?? null,
    'pm_who_default' => pmDefaultWho($cfg),
    // What the site currently does with them, so the page can say "this is off for everyone right
    // now" instead of showing a control that silently does nothing.
    // The GRANT, not userCan(): every page that READS a published list asks the grant, so a
    // "you may publish" drawn from the administrator's blanket is a promise nothing keeps.
    'may_publish' => favPublicEnabled($cfg) && userIdHasGrantedPermission($db, $cfg, (int)$u['id'], 'favourites.public'),
    'who_enabled' => favWhoEnabled($cfg),
    'lists_may_publish' => listsMayPublish($db, $cfg, (int)$u['id']),
    // …and share a list with my friends (1.72.0): the site, the friends feature, my account's friends.use.
    'lists_may_friends' => listsMayShareFriends($db, $cfg, (int)$u['id']),
    // Likes or ratings (1.69.0): the flag, and whether anything would show it — the same grant the
    // profile asks, never the administrator's blanket.
    'votes_public' => (int)($u['votes_public'] ?? 0) === 1,
    'votes_may_publish' => function_exists('profileVotesContext') && profileVotesContext($db, $cfg, $u)['may_publish'],
    // … and my name among them in "who has this" (1.70.0): the flag, and whether the section exists at all.
    'votes_listed' => (int)($u['votes_listed'] ?? 0) === 1,
    'votes_who_enabled' => function_exists('whoVotesEnabled') && whoVotesEnabled($cfg),
    // Descriptions (1.70.0): the name switch, the list switch, and whether anything would show the
    // list — the grant, never the administrator's blanket, and the name shown.
    'content_credit_public' => (int)($u['content_credit_public'] ?? 1) === 1,
    'descriptions_public' => (int)($u['descriptions_public'] ?? 0) === 1,
    'descriptions_may_publish' => function_exists('profileDescsContext')
        && (($dc = profileDescsContext($db, $cfg, $u))['may_publish']) && !$dc['name_hidden'],
]);
