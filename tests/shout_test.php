<?php
/**
 * The shoutbox (1.58.0, schema 63) — includes/shout.php against the migrated database:
 *     php tests/shout_test.php
 *
 * The schema and the grant (asked of the migration itself, not of what a previous suite left), the
 * clamps, who may write and why not, the flood interval, the three formats including the one that
 * formats nothing, mentions (parsed once, counted for ever), the three "new" numbers and the friend
 * split, seen, deleting your own line and somebody else's, retention by age and by count, and the
 * owner's purge.
 *
 * Self-cleaning: every row it makes is removed in the finally, and no setting is written at all —
 * the functions take $cfg, so the whole run happens against an array this file builds.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/people.php';
require_once $root . '/includes/audit.php';
require_once $root . '/includes/settings_catalog.php';
require_once $root . '/includes/db_clock.php';
require_once $root . '/includes/shout.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$cfg = getSettings($db, true);

// ── schema, registry, grant ──────────────────────────────────────────────────
check('schema version is at least 63', (int)($cfg['schema_version'] ?? 0) >= 63, (string)($cfg['schema_version'] ?? '?'));
check('the shouts table exists', (bool)$db->query("SHOW TABLES LIKE 'shouts'")->fetchColumn());
check('the shout_mentions table exists', (bool)$db->query("SHOW TABLES LIKE 'shout_mentions'")->fetchColumn());
check('users.shout_seen_id exists', schemaColumnExists($db, 'users', 'shout_seen_id'));
// v66. `user_id` being nullable is the load-bearing one: a line the SITE said has no author, and an
// inner join in any read path would have dropped exactly those rows out of every list in silence.
check('schema version is at least 66', (int)($cfg['schema_version'] ?? 0) >= 66, (string)($cfg['schema_version'] ?? '?'));
foreach (['is_system', 'pinned_at', 'pinned_by'] as $col) {
    check("shouts.$col exists", schemaColumnExists($db, 'shouts', $col));
}
check('shouts.user_id is nullable, because an announcement has nobody behind it',
      schemaColumnNullable($db, 'shouts', 'user_id'));
check('the pinned lookup has an index to answer from', schemaIndexExists($db, 'shouts', 'idx_shouts_pinned'));
$defaults = trackerSchemaDefaultSettings();
foreach (['shout_enabled' => '0', 'shout_placement' => 'home', 'shout_widget_rows' => '25', 'shout_page_rows' => '100',
          'shout_max_chars' => '500', 'shout_flood_seconds' => '5', 'shout_live_seconds' => '10',
          'shout_keep_rows' => '2000', 'shout_keep_days' => '30', 'shout_format' => 'bbcode', 'shout_rules' => '',
          'shout_nav' => '0', 'shout_live_seconds_guest' => '30', 'shout_system_lines' => '0'] as $k => $v) {
    check("the default for $k ships as '$v'", ($defaults[$k] ?? null) === $v, var_export($defaults[$k] ?? null, true));
}
foreach (['shout.view', 'shout.post', 'shout.delete_own', 'shout.moderate'] as $p) {
    check("$p is in the registry", isset(userPermissionList()[$p]));
}
check('the member preset carries the three reading/writing ids, never shout.moderate',
      in_array('shout.view', userGroupPresets()['member']['perms'], true)
      && in_array('shout.post', userGroupPresets()['member']['perms'], true)
      && in_array('shout.delete_own', userGroupPresets()['member']['perms'], true)
      && !in_array('shout.moderate', userGroupPresets()['member']['perms'], true));
check('the moderator preset carries shout.moderate', in_array('shout.moderate', userGroupPresets()['moderator']['perms'], true));
// The grant is a one-time migration on a database other suites edit: ask the migration itself
// (forget the marker, run the data migrations again — idempotent by design).
$db->exec("DELETE FROM settings WHERE `key` = 'schema_grant_v63_shout'");
trackerSchemaDataMigrations($db, getSettings($db, true));
$cfg = getSettings($db, true);
check('the migration records its grant', isset($cfg['schema_grant_v63_shout']));
$mp = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'member'")->fetchColumn(), true) ?: [];
check('… the member group reads, writes and removes its own', ($mp['shout.view'] ?? null) === true && ($mp['shout.post'] ?? null) === true
      && ($mp['shout.delete_own'] ?? null) === true && empty($mp['shout.moderate']), json_encode($mp));
$gp = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'guest'")->fetchColumn(), true) ?: [];
check('… and a guest reads nothing until the operator says otherwise', empty($gp['shout.view']));
$modp = json_decode((string)$db->query("SELECT permissions FROM user_groups WHERE slug = 'moderator'")->fetchColumn(), true) ?: [];
check('… and the seeded moderator group may moderate a room it can also see',
      ($modp['shout.moderate'] ?? null) === true && ($modp['shout.view'] ?? null) === true, json_encode(array_keys($modp)));
check('with accounts off the legacy fallback closes the whole feature',
      !userLegacyDefault('shout.view') && !userLegacyDefault('shout.post') && !userLegacyDefault('shout.moderate'));

// ── the switches and their clamps ────────────────────────────────────────────
check('the feature needs accounts AND its own switch',
      !shoutEnabled(['users_enabled' => '0', 'shout_enabled' => '1'])
      && !shoutEnabled(['users_enabled' => '1'])
      && shoutEnabled(['users_enabled' => '1', 'shout_enabled' => '1']));
check('placement falls back to home', shoutPlacement([]) === 'home' && shoutPlacement(['shout_placement' => 'both']) === 'both'
      && shoutPlacement(['shout_placement' => 'nonsense']) === 'home');
check('format falls back to bbcode', shoutFormat([]) === 'bbcode' && shoutFormat(['shout_format' => 'plain']) === 'plain'
      && shoutFormat(['shout_format' => 'rtf']) === 'bbcode');
check('plain offers no choice of format; the others offer two',
      shoutFormatChoices(['shout_format' => 'plain']) === ['plain'] && shoutFormatChoices([]) === ['bbcode', 'markdown']);
check('0 seconds means no polling, 1 and 2 are raised to 3, and 9999 is lowered to 120',
      shoutLiveSeconds(['shout_live_seconds' => '0']) === 0 && shoutLiveSeconds(['shout_live_seconds' => '1']) === 3
      && shoutLiveSeconds(['shout_live_seconds' => '9999']) === 120 && shoutLiveSeconds([]) === 10);
// v66: a guest is a different reader, so they get a different number — with a wider ceiling, because
// "every few minutes" is a sensible answer for somebody who is only watching.
check('the guest cadence is its own number, clamped on its own terms',
      shoutLiveSecondsGuest([]) === 30 && shoutLiveSecondsGuest(['shout_live_seconds_guest' => '0']) === 0
      && shoutLiveSecondsGuest(['shout_live_seconds_guest' => '1']) === 3
      && shoutLiveSecondsGuest(['shout_live_seconds_guest' => '9999']) === 300);
check('… and one function decides which of the two a reader is on',
      shoutLiveSecondsFor(['shout_live_seconds' => '10', 'shout_live_seconds_guest' => '0'], false) === 10
      && shoutLiveSecondsFor(['shout_live_seconds' => '10', 'shout_live_seconds_guest' => '0'], true) === 0
      && shoutLiveSecondsFor(['shout_live_seconds' => '0', 'shout_live_seconds_guest' => '60'], true) === 60);
check('the navigation link and the site\'s own lines both need the room to be on first',
      !shoutNav(['shout_nav' => '1']) && !shoutSystemLines(['shout_system_lines' => '1'])
      && shoutNav(['users_enabled' => '1', 'shout_enabled' => '1', 'shout_nav' => '1'])
      && shoutSystemLines(['users_enabled' => '1', 'shout_enabled' => '1', 'shout_system_lines' => '1']));
// The link has to land on a page that DRAWS a box, or the badge it carries can never be cleared:
// ?action=shoutbox answers "there is no shoutbox here" while the placement is the home block.
check('the nav link goes wherever the box actually is',
      shoutNavUrl(['shout_placement' => 'home'], '/') === '/'
      && shoutNavUrl(['shout_placement' => 'page'], '/') === '/?action=shoutbox'
      && shoutNavUrl(['shout_placement' => 'both'], '/') === '/?action=shoutbox');
// ── 1.61.0: the address the room answers on ──────────────────────────────────
// The validator reads the ROUTER'S OWN MAP, which is the whole point of it: a hand-written list of
// forbidden names would be wrong the first time somebody added a page, and wrong in the direction
// that costs an operator their sign-in page.
check('the room answers on `shoutbox` unless somebody says otherwise',
      shoutPageAction([]) === 'shoutbox' && shoutPageAction(['shout_page_action' => '']) === 'shoutbox'
      && shoutPageAction(['shout_page_action' => 'shoutbox']) === 'shoutbox');
check('… and on whatever legal name the operator chose',
      shoutPageAction(['shout_page_action' => 'chat']) === 'chat'
      && shoutPageAction(['shout_page_action' => 'CZAT']) === 'czat'          // folded, like $action is
      && shoutPageAction(['shout_page_action' => ' talk_2-0 ']) === 'talk_2-0');
check('a name that is already another page\'s is refused, read from siteRoutes() rather than a list here',
      shoutPageAction(['shout_page_action' => 'login']) === 'shoutbox'
      && shoutPageAction(['shout_page_action' => 'account']) === 'shoutbox'
      && shoutPageAction(['shout_page_action' => 'emotes']) === 'shoutbox'
      && shoutPageAction(['shout_page_action' => 'u']) === 'shoutbox');
check('… and so is anything that could not be an action at all',
      shoutPageAction(['shout_page_action' => 'a']) === 'shoutbox'                  // too short
      && shoutPageAction(['shout_page_action' => str_repeat('x', 33)]) === 'shoutbox'
      && shoutPageAction(['shout_page_action' => 'ch at']) === 'shoutbox'
      && shoutPageAction(['shout_page_action' => 'chat!']) === 'shoutbox'
      && shoutPageAction(['shout_page_action' => 'Chat.Room']) === 'shoutbox');
check('the map the validator asks is the one the router uses',
      isset(siteRoutes()['shoutbox']) && isset(siteRoutes()['login']) && isset(siteRoutes()['home'])
      && !isset(siteRoutes()['chat']));
check('the default ships as the literal it has always been',
      ($defaults['shout_page_action'] ?? null) === 'shoutbox', var_export($defaults['shout_page_action'] ?? null, true));
// The link follows the setting, which is what makes renaming the room one string rather than a hunt.
check('every link is built from the setting, never from the literal',
      shoutNavUrl(['shout_placement' => 'page', 'shout_page_action' => 'chat'], '/') === '/?action=chat'
      && shoutNavUrl(['shout_placement' => 'both', 'shout_page_action' => 'chat'], '/') === '/?action=chat'
      // …and the placement still wins: with the box on the front page there is no page to send anybody to.
      && shoutNavUrl(['shout_placement' => 'home', 'shout_page_action' => 'chat'], '/') === '/');
check('… and an invalid name leaves the address the site has always had',
      shoutNavUrl(['shout_placement' => 'page', 'shout_page_action' => 'login'], '/') === '/?action=shoutbox');

// ── 1.61.0: the permission that skips the approval queue ─────────────────────
foreach (['shout.upload_emote', 'shout.emote_auto'] as $p) {
    check("$p is in the registry", isset(userPermissionList()[$p]));
}
check('shout.emote_auto is granted to NOBODY by any preset',
      !in_array('shout.emote_auto', userGroupPresets()['member']['perms'], true)
      && !in_array('shout.emote_auto', userGroupPresets()['moderator']['perms'], true));

check('the row and length limits are clamped on read',
      shoutMaxChars(['shout_max_chars' => '99999']) === 2000 && shoutMaxChars(['shout_max_chars' => '0']) === 500
      && shoutWidgetRows(['shout_widget_rows' => '1']) === 5 && shoutPageRows(['shout_page_rows' => '99999']) === 500
      && shoutKeepRows(['shout_keep_rows' => '1']) === 100 && shoutKeepDays(['shout_keep_days' => '99999']) === 3650
      && shoutFloodSeconds(['shout_flood_seconds' => '-5']) === 0);

// ── the accounts this run needs ──────────────────────────────────────────────
//
// Each account's groups are final BEFORE anything asks what it may do: userEffectivePermissions()
// caches per account for the life of the process, so a group changed halfway through a test is a
// test that reads its own stale answer.
$names = ['shtuser', 'shtmod', 'shtmute', 'shtnone', 'shtfriend', 'shtauto'];
$in = implode(',', array_fill(0, count($names), '?'));
$db->prepare("DELETE FROM users WHERE username IN ($in)")->execute($names);
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'shout_enabled' => '1', 'users_require_email_verify' => '0',
                            'shout_max_chars' => '500', 'shout_flood_seconds' => '0', 'shout_format' => 'bbcode',
                            'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1']);
$uid = [];
foreach ($names as $name) {
    userCreate($db, $cfgOn, $name, $name . '@example.org', 'SmokePass123!', '127.0.0.1');
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$name]);
    $uid[$name] = (int)($st->fetchColumn() ?: 0);
}
$db->prepare("UPDATE users SET email_verified = 1 WHERE username IN ($in)")->execute($names);
check('the six test accounts exist', count(array_filter($uid)) === 6, json_encode($uid));

$modGroup = (int)($db->query("SELECT id FROM user_groups WHERE slug = 'moderator'")->fetchColumn() ?: 0);
userGrantGroup($db, $uid['shtmod'], $modGroup, null, 'shout_test', '', false);
// shtnone keeps no group at all: the closest thing to "a member whose group carries nothing".
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$uid['shtnone']]);
$db->prepare("UPDATE users SET pm_muted_until = NOW() + INTERVAL 1 DAY WHERE id = ?")->execute([$uid['shtmute']]);
// A group of this run's own carrying the two 1.61.0 uploading ids, because no preset hands either of
// them out — which is the thing being checked a few lines above. Made BEFORE anything asks what
// shtauto may do: userEffectivePermissions() caches per account for the life of the process.
$db->prepare("INSERT INTO user_groups (slug, name, description, priority, is_default, is_system, permissions)
              VALUES ('shtautog', 'Shout auto (test)', '', 50, 0, 0, ?)
              ON DUPLICATE KEY UPDATE permissions = VALUES(permissions)")
   ->execute([json_encode(['shout.view' => true, 'shout.post' => true,
                           'shout.upload_emote' => true, 'shout.emote_auto' => true], JSON_UNESCAPED_SLASHES)]);
$autoGroup = (int)($db->query("SELECT id FROM user_groups WHERE slug = 'shtautog'")->fetchColumn() ?: 0);
if ($autoGroup > 0) userGrantGroup($db, $uid['shtauto'], $autoGroup, null, 'shout_test', '', false);

$row = function (string $name) use ($db): array {
    $st = $db->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([$name]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};
$cleanup = function () use ($db, $uid, $in, $names) {
    $ids = array_values($uid);
    $q = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE m FROM shout_mentions m JOIN shouts s ON s.id = m.shout_id WHERE s.user_id IN ($q)")->execute($ids);
    $db->prepare("DELETE FROM shouts WHERE user_id IN ($q)")->execute($ids);
    $db->prepare("DELETE FROM user_friends WHERE user_id IN ($q) OR friend_id IN ($q)")->execute(array_merge($ids, $ids));
    // The pictures and the group this run made, so a second run starts where the first one did.
    $db->prepare("DELETE FROM shout_emotes WHERE uploaded_by IN ($q) OR code LIKE 'zzauto%'")->execute($ids);
    $db->prepare("DELETE FROM users WHERE username IN ($in)")->execute($names);
    $db->exec("DELETE FROM user_groups WHERE slug = 'shtautog'");
};

try {
    $me = $row('shtuser');
    $mod = $row('shtmod');
    $muted = $row('shtmute');
    $none = $row('shtnone');
    $friend = $row('shtfriend');
    // An EMPTY room to start from. The counts below are exact numbers rather than differences, and
    // the table is this feature's own — nothing else in the suite keeps a fixture in it.
    $db->exec("DELETE FROM shout_mentions");
    $db->exec("DELETE FROM shouts");

    // ── who may write ────────────────────────────────────────────────────────
    check('a member may write', shoutMayPost($db, $cfgOn, $me)['ok'] === true);
    check('a visitor is told to sign in', shoutMayPost($db, $cfgOn, null) === ['ok' => false, 'reason' => 'login', 'until' => null]);
    check('the feature being off beats everything else',
          shoutMayPost($db, array_merge($cfgOn, ['shout_enabled' => '0']), $me)['reason'] === 'disabled');
    check('a group without shout.post is refused', shoutMayPost($db, $cfgOn, $none)['reason'] === 'no_permission');
    $g = shoutMayPost($db, $cfgOn, $muted);
    check('a silenced account is refused and told until when', $g['reason'] === 'muted' && $g['until'] !== null, json_encode($g));
    check('… and silence is INHERITED from private messages, not a second column',
          pmMutedUntil($muted) !== null && (string)$muted['pm_muted_until'] === (string)$g['until']);
    check('reading is not writing: the silenced account still may view',
          userIdHasPermission($db, $cfgOn, (int)$muted['id'], 'shout.view'));

    // ── writing, and what comes back ─────────────────────────────────────────
    $r = shoutPost($db, $cfgOn, $me, '  hello [b]world[/b]  ', 'bbcode', '127.0.0.1');
    check('a shout is written and handed back rendered',
          !empty($r['ok']) && ($r['row']['user'] ?? '') === 'shtuser' && str_contains((string)($r['row']['html'] ?? ''), '<strong>world</strong>')
          && ($r['row']['own'] ?? null) === true && ($r['row']['deletable'] ?? null) === true, json_encode($r));
    $firstId = (int)$r['id'];
    check('… and its body was trimmed on the way in',
          (string)$db->query("SELECT body FROM shouts WHERE id = $firstId")->fetchColumn() === 'hello [b]world[/b]');
    check('an empty shout is refused', (shoutPost($db, $cfgOn, $me, "   \n ", 'bbcode', '')['error'] ?? '') === 'empty');
    $long = shoutPost($db, array_merge($cfgOn, ['shout_max_chars' => '10']), $me, str_repeat('x', 11), 'bbcode', '');
    check('too long is refused, with the limit it was judged against', ($long['error'] ?? '') === 'too_long' && ($long['limit'] ?? 0) === 10, json_encode($long));
    $bad = shoutPost($db, array_merge($cfgOn, ['desc_max_images' => '0']), $me, '[img]https://example.org/a.png[/img]', 'bbcode', '');
    check('the same validator the descriptions use refuses an image when images are off',
          ($bad['error'] ?? '') === 'invalid_body' && ($bad['detail'] ?? '') !== '', json_encode($bad));
    check('a refused shout writes nothing', (int)$db->query("SELECT COUNT(*) FROM shouts WHERE user_id = " . (int)$me['id'])->fetchColumn() === 1);

    // ── formats ──────────────────────────────────────────────────────────────
    $r = shoutPost($db, $cfgOn, $me, '**not markdown here**', 'markdown', '');
    check('markdown may be chosen per shout when the site default is bbcode',
          !empty($r['ok']) && str_contains((string)$r['row']['html'], '<strong>not markdown here</strong>'), json_encode($r['row'] ?? null));
    $r = shoutPost($db, $cfgOn, $me, 'still bbcode', 'rtf', '');
    check('an unknown format falls back to the site default',
          (string)$db->query("SELECT body_format FROM shouts WHERE id = " . (int)$r['id'])->fetchColumn() === 'bbcode');
    $cfgPlain = array_merge($cfgOn, ['shout_format' => 'plain']);
    $r = shoutPost($db, $cfgPlain, $me, "a < b\nand [b]not bold[/b]", 'markdown', '');
    check('with the site on plain, nothing is parsed whatever the writer asked for',
          (string)$db->query("SELECT body_format FROM shouts WHERE id = " . (int)$r['id'])->fetchColumn() === 'plain'
          && str_contains((string)$r['row']['html'], 'a &lt; b') && str_contains((string)$r['row']['html'], '<br>')
          && str_contains((string)$r['row']['html'], '[b]not bold[/b]'), (string)$r['row']['html']);

    // ── the flood interval ───────────────────────────────────────────────────
    $cfgFlood = array_merge($cfgOn, ['shout_flood_seconds' => '60']);
    $f = shoutPost($db, $cfgFlood, $me, 'too soon', 'bbcode', '');
    check('a second shout inside the interval is refused, with how long is left',
          ($f['error'] ?? '') === 'flood' && ($f['retry_after'] ?? 0) > 0 && ($f['retry_after'] ?? 0) <= 60, json_encode($f));
    check('… and the interval is per account, not per room', shoutPost($db, $cfgFlood, $friend, 'mine is the first', 'bbcode', '')['ok'] === true);
    check('0 seconds means no interval at all', shoutPost($db, $cfgOn, $me, 'and again', 'bbcode', '')['ok'] === true);

    // ── mentions ─────────────────────────────────────────────────────────────
    $got = shoutParseMentions($db, 'hi @shtfriend and @shtmod', (int)$me['id']);
    $want = [(int)$friend['id'], (int)$mod['id']];
    sort($got); sort($want);
    check('only real names, never the author', $got === $want, json_encode($got));
    check('a name nobody has is not a mention', shoutParseMentions($db, 'hi @nobodyhere', (int)$me['id']) === []);
    check('the author @-ing themselves is not a mention', shoutParseMentions($db, 'note to @shtuser', (int)$me['id']) === []);
    check('an e-mail address is not a mention', shoutParseMentions($db, 'write to bob@shtfriend now', (int)$me['id']) === []);
    check('the same name twice is one mention', count(shoutParseMentions($db, '@shtfriend @shtfriend', (int)$me['id'])) === 1);
    $r = shoutPost($db, $cfgOn, $me, 'oi @shtfriend look at this', 'bbcode', '');
    $mentionId = (int)$r['id'];
    check('a mention is recorded when the shout is written',
          (int)$db->query("SELECT COUNT(*) FROM shout_mentions WHERE shout_id = $mentionId AND user_id = " . (int)$friend['id'])->fetchColumn() === 1);
    check('… and the name is a link in the rendered line',
          str_contains((string)$r['row']['html'], 'class="shout-mention"') && str_contains((string)$r['row']['html'], '?action=u&amp;name=shtfriend'),
          (string)$r['row']['html']);
    $seen = shoutRows($db, $cfgOn, $friend, 50, $mentionId - 1);
    check('… and the person named sees it marked as theirs',
          ($seen[0]['mentions_me'] ?? null) === true && str_contains((string)$seen[0]['html'], 'shout-mention-me'), json_encode($seen[0] ?? null));
    check('… while everyone else sees an ordinary link',
          !str_contains((string)$r['row']['html'], 'shout-mention-me') && ($r['row']['mentions_me'] ?? null) === false);
    $r = shoutPost($db, $cfgOn, $me, '[url=https://example.org/]@shtfriend[/url]', 'bbcode', '');
    check('a mention written as a link label does not become a link inside a link',
          substr_count((string)$r['row']['html'], '<a ') === 1, (string)$r['row']['html']);

    // ── reading: after, before, newest last ──────────────────────────────────
    // A known room again: six lines by one other person, so "the newest three" and "everything
    // before that id" are numbers rather than guesses.
    $db->exec("DELETE FROM shout_mentions");
    $db->exec("DELETE FROM shouts");
    $mk = function (int $userId, string $body) use ($db): int {
        $db->prepare("INSERT INTO shouts (user_id, body, body_format) VALUES (?, ?, 'plain')")->execute([$userId, $body]);
        return (int)$db->lastInsertId();
    };
    $ids = [];
    foreach (range(1, 6) as $i) $ids[] = $mk((int)$friend['id'], 'line ' . $i);
    $rows = shoutRows($db, $cfgOn, $me, 3);
    check('with no cursor the NEWEST rows come back, oldest first',
          count($rows) === 3 && $rows[0]['id'] === $ids[3] && $rows[2]['id'] === $ids[5], json_encode(array_column($rows, 'id')));
    $rows = shoutRows($db, $cfgOn, $me, 10, $ids[3]);
    check('after: only what is newer', array_column($rows, 'id') === [$ids[4], $ids[5]], json_encode(array_column($rows, 'id')));
    $rows = shoutRows($db, $cfgOn, $me, 2, null, $ids[3]);
    check('before: only what is older, still oldest first', array_column($rows, 'id') === [$ids[1], $ids[2]], json_encode(array_column($rows, 'id')));
    check('the newest id is the poll baseline', shoutNewestId($db) >= $ids[5]);
    check('somebody else owns those lines, and a plain member may not remove them',
          $rows[0]['own'] === false && $rows[0]['deletable'] === false && $rows[0]['user'] === 'shtfriend');
    check('a moderator may remove any of them', shoutRows($db, $cfgOn, $mod, 1)[0]['deletable'] === true);
    check('a reader with no groups owns nothing and may remove nothing',
          shoutRows($db, $cfgOn, $none, 1)[0]['deletable'] === false);
    check('a guest reading the room is not "own" anything either', shoutRows($db, $cfgOn, [], 1)[0]['own'] === false);

    // ── what is new, and the friend split ────────────────────────────────────
    $db->prepare("UPDATE users SET shout_seen_id = 0 WHERE id = ?")->execute([(int)$me['id']]);
    $me['shout_seen_id'] = 0;
    $c = shoutUnreadCounts($db, $cfgOn, $me);
    check('everything somebody else said is new, and none of it is from a friend yet',
          $c['shout'] === 6 && $c['shout_friend'] === 0 && $c['mention'] === 0, json_encode($c));
    $db->prepare("INSERT INTO user_friends (user_id, friend_id, status, accepted_at) VALUES (?, ?, 'accepted', NOW())")
       ->execute([(int)$me['id'], (int)$friend['id']]);
    $c = shoutUnreadCounts($db, $cfgOn, $me);
    check('… and all of it is from a friend once the friendship is accepted',
          $c['shout'] === 6 && $c['shout_friend'] === 6, json_encode($c));
    $mentionAt = $mk((int)$friend['id'], 'and @shtuser too');
    $db->prepare("INSERT INTO shout_mentions (shout_id, user_id) VALUES (?, ?)")->execute([$mentionAt, (int)$me['id']]);
    $c = shoutUnreadCounts($db, $cfgOn, $me);
    check('a mention is its own number as well as part of the total',
          $c['shout'] === 7 && $c['shout_friend'] === 7 && $c['mention'] === 1, json_encode($c));
    // 1.61.0: the ROW says whether a friend said it, because the browser cannot work that out and
    // the sound player has to tell a friend's line from a stranger's. One query per batch, beside
    // the mentions one, and only while the feature that defines a friend is switched on.
    $cfgPals = array_merge($cfgOn, ['friends_enabled' => '1']);
    $palRows = shoutRows($db, $cfgPals, $me, 50, 0);
    $palOne = array_values(array_filter($palRows, fn($r) => ($r['user'] ?? '') === 'shtfriend'));
    check('a row says whether a friend said it',
          $palOne !== [] && ($palOne[0]['friend'] ?? null) === true, json_encode($palOne[0] ?? null));
    check('… a reader who is nobody\'s friend is told so about the same line',
          (shoutRows($db, $cfgPals, $none, 1, 0)[0]['friend'] ?? null) === false);
    // $cfgOn is merged from the LIVE settings of whatever database this runs against, and this one
    // has friends switched on — so asking $cfgOn for the switched-off behaviour asked for the exact
    // opposite of what the name says, and the newest row happens to be the friend's line. Say it
    // explicitly, the way $cfgPals above says the other half explicitly.
    $cfgNoPals = array_merge($cfgOn, ['friends_enabled' => '0']);
    check('… and with friends switched off the question is never asked',
          (shoutRows($db, $cfgNoPals, $me, 1, 0)[0]['friend'] ?? null) === false);

    $mine = $mk((int)$me['id'], 'my own line');
    $c = shoutUnreadCounts($db, $cfgOn, $me);
    check('my own line is never new to me', $c['shout'] === 7, json_encode($c));
    shoutSeen($db, (int)$me['id'], $mentionAt);
    $c = shoutUnreadCounts($db, $cfgOn, $row('shtuser'));
    check('reading up to an id clears everything up to it', $c['shout'] === 0 && $c['mention'] === 0, json_encode($c));
    shoutSeen($db, (int)$me['id'], 1);
    check('… and a slow tab cannot move the mark backwards',
          (int)$db->query("SELECT shout_seen_id FROM users WHERE id = " . (int)$me['id'])->fetchColumn() === $mentionAt);
    check('the counts are nothing at all while the feature is off',
          shoutUnreadCounts($db, array_merge($cfgOn, ['shout_enabled' => '0']), $row('shtuser')) === ['shout' => 0, 'shout_friend' => 0, 'mention' => 0]);

    // ── deleting ─────────────────────────────────────────────────────────────
    $theirs = $ids[0];
    check('a member may not delete somebody else\'s line', shoutDelete($db, $cfgOn, $me, $theirs)['error'] === 'no_permission');
    check('… and may delete their own', shoutDelete($db, $cfgOn, $me, $mine)['ok'] === true);
    check('a deleted line is gone from every read path',
          !in_array($mine, array_column(shoutRows($db, $cfgOn, $me, 50, 0), 'id'), true));
    check('… but the row is still there, with who removed it and when',
          (int)$db->query("SELECT deleted_by FROM shouts WHERE id = $mine")->fetchColumn() === (int)$me['id']
          && $db->query("SELECT deleted_at FROM shouts WHERE id = $mine")->fetchColumn() !== null);
    check('deleting it twice is not_found', shoutDelete($db, $cfgOn, $me, $mine)['error'] === 'not_found');
    check('an id that never existed is not_found', shoutDelete($db, $cfgOn, $me, 999999999)['error'] === 'not_found');
    check('a moderator may delete somebody else\'s', shoutDelete($db, $cfgOn, $mod, $theirs)['ok'] === true);
    check('… and that is written to the audit log',
          (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'shout.delete'")->fetchColumn() > 0);
    check('a visitor deletes nothing', shoutDelete($db, $cfgOn, [], $ids[1])['error'] === 'no_permission');
    check('a deleted line is not a new line for anybody',
          shoutUnreadCounts($db, $cfgOn, ['id' => (int)$friend['id'], 'shout_seen_id' => 0])['shout']
          === (int)$db->query("SELECT COUNT(*) FROM shouts WHERE deleted_at IS NULL AND user_id <> " . (int)$friend['id'])->fetchColumn());

    // ── the pinned line ──────────────────────────────────────────────────────
    $p1 = $ids[2];
    $p2 = $ids[3];
    check('nothing is pinned to begin with', shoutPinned($db, $cfgOn, $me) === null);
    check('a member may not pin', shoutPin($db, $cfgOn, $me, $p1, true)['error'] === 'no_permission');
    check('a visitor may not pin', shoutPin($db, $cfgOn, [], $p1, true)['error'] === 'no_permission');
    check('… and neither of them moved anything',
          (int)$db->query("SELECT COUNT(*) FROM shouts WHERE pinned_at IS NOT NULL")->fetchColumn() === 0);
    check('a moderator pins one', shoutPin($db, $cfgOn, $mod, $p1, true)['ok'] === true);
    $pinned = shoutPinned($db, $cfgOn, $me);
    check('… and it comes back shaped exactly like any other row',
          $pinned !== null && (int)$pinned['id'] === $p1 && ($pinned['pinned'] ?? null) === true
          && ($pinned['user'] ?? '') === 'shtfriend' && isset($pinned['html']), json_encode($pinned));
    // The whole rule: two announcements is nobody reading either.
    check('pinning a second one unpins the first — there is only ever one',
          shoutPin($db, $cfgOn, $mod, $p2, true)['ok'] === true
          && (int)$db->query("SELECT COUNT(*) FROM shouts WHERE pinned_at IS NOT NULL")->fetchColumn() === 1
          && (int)shoutPinned($db, $cfgOn, $me)['id'] === $p2);
    check('… and the row remembers who pinned it',
          (int)$db->query("SELECT pinned_by FROM shouts WHERE id = $p2")->fetchColumn() === (int)$mod['id']);
    check('pinning is written to the audit log',
          (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'shout.pin'")->fetchColumn() > 0);
    check('a pinned line is still an ordinary line in the list',
          in_array($p2, array_column(shoutRows($db, $cfgOn, $me, 50, 0), 'id'), true));
    // The reason it is not in the `after=` answer: that path APPENDS, so a pinned row handed to it
    // would land at the bottom of the room again on every tick until it was the only thing in it.
    check('… and the append path never hands it over a second time',
          !in_array($p2, array_column(shoutRows($db, $cfgOn, $me, 50, $p2), 'id'), true));
    check('an id that is not there cannot be pinned',
          shoutPin($db, $cfgOn, $mod, 999999999, true)['error'] === 'not_found');
    check('unpinning leaves nothing pinned',
          shoutPin($db, $cfgOn, $mod, $p2, false)['ok'] === true && shoutPinned($db, $cfgOn, $me) === null);
    shoutPin($db, $cfgOn, $mod, $p1, true);
    shoutDelete($db, $cfgOn, $mod, $p1);
    check('deleting the pinned line takes the strip with it', shoutPinned($db, $cfgOn, $me) === null);

    // ── the lines the site says ──────────────────────────────────────────────
    // An empty room again: every count below is an exact number rather than a difference.
    $db->exec("DELETE FROM shout_mentions");
    $db->exec("DELETE FROM shouts");
    $cfgSys = array_merge($cfgOn, ['shout_system_lines' => '1', 'site_name' => 'TestTracker',
                                   'default_language' => 'en', 'shout_flood_seconds' => '0']);
    check('off as shipped, the site says nothing at all',
          shoutSystemPost($db, $cfgOn, 'should not appear', null) === 0
          && (int)$db->query("SELECT COUNT(*) FROM shouts")->fetchColumn() === 0);
    $sysId = shoutSystemPost($db, $cfgSys, 'A new torrent has been registered.', null);
    check('switched on, it writes one', $sysId > 0, (string)$sysId);
    $sysRow = shoutRows($db, $cfgSys, $me, 1, $sysId - 1)[0] ?? [];
    check('… signed with the site, pointing at no profile, owned by nobody',
          ($sysRow['system'] ?? null) === true && ($sysRow['user'] ?? '') === 'TestTracker'
          && ($sysRow['user_id'] ?? null) === 0 && ($sysRow['own'] ?? null) === false, json_encode($sysRow));
    check('… stored as plain text, whatever the room\'s format is',
          (string)$db->query("SELECT body_format FROM shouts WHERE id = $sysId")->fetchColumn() === 'plain'
          && $db->query("SELECT user_id FROM shouts WHERE id = $sysId")->fetchColumn() === null);
    check('… and a member cannot delete it', shoutDelete($db, $cfgSys, $me, $sysId)['error'] === 'no_permission');
    // The one that matters for the sounds: an announcement must not make the room ping.
    $db->prepare("UPDATE users SET shout_seen_id = 0 WHERE id = ?")->execute([(int)$me['id']]);
    check('a line the site said is nobody\'s unread',
          shoutUnreadCounts($db, $cfgSys, $row('shtuser')) === ['shout' => 0, 'shout_friend' => 0, 'mention' => 0],
          json_encode(shoutUnreadCounts($db, $cfgSys, $row('shtuser'))));
    check('a moderator can still take it down', shoutDelete($db, $cfgSys, $mod, $sysId)['ok'] === true);

    // Composed server-side, in the SITE's language, with the count for the batch — and the submitter
    // named only where the caller says they are public (includes/whitelist.php passes NULL when
    // `wl_submitter_public` and the person's own choice do not both say yes).
    shoutSystemWhitelistAdded($db, $cfgSys, 3, null);
    $anon = shoutRows($db, $cfgSys, $me, 1)[0] ?? [];
    check('a batch nobody is named for is signed by the site and still counts what arrived',
          ($anon['user_id'] ?? null) === 0 && ($anon['system'] ?? null) === true
          && str_contains((string)$anon['html'], '3'), json_encode($anon));
    shoutSystemWhitelistAdded($db, $cfgSys, 1, (int)$friend['id']);
    $named = shoutRows($db, $cfgSys, $me, 1)[0] ?? [];
    check('a public submitter SIGNS the line, and their name is not inside the sentence',
          ($named['user'] ?? '') === 'shtfriend' && ($named['user_id'] ?? 0) === (int)$friend['id']
          && ($named['system'] ?? null) === true && !str_contains((string)$named['html'], 'shtfriend'),
          json_encode($named));
    check('… and it is still nobody\'s own: the person it names cannot delete it either',
          ($named['own'] ?? null) === false
          && shoutDelete($db, $cfgSys, $friend, (int)$named['id'])['error'] === 'no_permission');
    check('… nor does it count as that person having just spoken',
          shoutPost($db, array_merge($cfgSys, ['shout_flood_seconds' => '60']), $friend, 'still allowed', 'bbcode', '')['ok'] === true);
    $sysBefore = (int)$db->query("SELECT COUNT(*) FROM shouts WHERE is_system = 1")->fetchColumn();
    // Off because this says so, not because this database happens to have it off: $cfgOn is merged
    // from the LIVE settings, and inheriting the very switch under test is what made two other
    // checks in this file accuse working code.
    $cfgNoSys = array_merge($cfgOn, ['shout_system_lines' => '0']);
    shoutSystemWhitelistAdded($db, $cfgNoSys, 5, (int)$friend['id']);
    check('with the switch off again nothing is written, whoever it would have been about',
          (int)$db->query("SELECT COUNT(*) FROM shouts WHERE is_system = 1")->fetchColumn() === $sysBefore,
          (string)$sysBefore);

    // ── 1.61.0: an upload that does not wait ─────────────────────────────────
    // A 16×16 SVG with nothing executable in it — shoutEmoteSniff() decides from the bytes, so the
    // picture has to be real rather than a string that looks like one.
    $svg = fn(string $tag) => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">'
                            . $tag . '<rect width="16" height="16" fill="#4a9eff"/></svg>';
    $db->exec("DELETE FROM shout_emotes WHERE code LIKE 'zzauto%'");
    $cfgGate = array_merge($cfgOn, ['shout_emote_approval' => '1']);
    $held = shoutEmoteStore($db, $cfgGate, 'zzautoheld', 'Zz auto held', $svg('<title>held</title>'), (int)$me['id'], false);
    check('with the gate on, an ordinary member\'s upload still waits',
          // `?? 'x'` cannot ask this question: the coalescing operator answers with the fallback for
          // a key that exists and holds null, which is the exact state being asserted. Ask whether
          // the key is there, then whether it is empty.
          !empty($held['ok']) && ($held['pending'] ?? null) === true
          && ($held['row']['enabled'] ?? null) === false
          && array_key_exists('approved_at', $held['row'] ?? []) && $held['row']['approved_at'] === null,
          json_encode($held));
    $free = shoutEmoteStore($db, $cfgGate, 'zzautofree', 'Zz auto free', $svg('<title>free</title>'), $uid['shtauto'], false);
    check('… while somebody holding shout.emote_auto goes straight into the room, stamped',
          !empty($free['ok']) && ($free['pending'] ?? null) === false
          && ($free['row']['enabled'] ?? null) === true && ($free['row']['approved_at'] ?? null) !== null,
          json_encode($free));
    // The stamp is the load-bearing half: a row with no date is a question nobody has answered, and
    // without it this emote would queue up again the first time somebody switched it off.
    check('… which is what the table itself says',
          (int)$db->query("SELECT enabled FROM shout_emotes WHERE code = 'zzautofree'")->fetchColumn() === 1
          && $db->query("SELECT approved_at FROM shout_emotes WHERE code = 'zzautofree'")->fetchColumn() !== null);
    check('with the gate off the permission changes nothing — everything goes in either way',
          (shoutEmoteStore($db, array_merge($cfgOn, ['shout_emote_approval' => '0']), 'zzautooff', 'Zz auto off',
                           $svg('<title>off</title>'), (int)$me['id'], false)['pending'] ?? null) === false);
    check('… and the panel\'s own upload never waits, with the gate on or off',
          (shoutEmoteStore($db, $cfgGate, 'zzautopanel', 'Zz auto panel', $svg('<title>panel</title>'), null, false)['pending'] ?? null) === false);

    // ── 1.61.0: the shape the `@` suggestions are matched with ───────────────
    // The endpoint's own query, run against this run's accounts: a PREFIX matches and the middle of
    // a name does not, which is the difference between an index range and a scan of `users`.
    $mq = $db->prepare("SELECT u.username FROM users u WHERE u.status = 'active' AND u.username LIKE ?
                         ORDER BY u.username ASC LIMIT 8");
    $mq->execute(['sht%']);
    $mNames = $mq->fetchAll(PDO::FETCH_COLUMN);
    check('a prefix finds the accounts that start with it', count($mNames) >= 5 && in_array('shtuser', $mNames, true),
          json_encode($mNames));
    $mq->execute(['htuse%']);
    check('… and the middle of a name is not a prefix', $mq->fetchAll(PDO::FETCH_COLUMN) === []);
    $mq->execute(['%']);
    check('… and eight is the ceiling however much matches', count($mq->fetchAll(PDO::FETCH_COLUMN)) <= 8);

    // ── 1.62.0: the zone a reader sees times in ─────────────────────────────
    // Every switch this section leans on is SET here, never inherited: $cfgOn is merged from the
    // live settings of whatever database runs this, and two checks in this file once accused working
    // code by inheriting the very switch they claimed was off.
    $cfgTz = array_merge($cfgOn, ['site_timezone' => 'Europe/Warsaw', 'tracker_schedule_tz' => 'Europe/Warsaw',
                                  'friends_enabled' => '0', 'shout_emotes_enabled' => '0', 'shout_stickers_enabled' => '0']);
    check('schema 68: users.timezone exists, and site_timezone ships empty (= follow the schedule, then PHP)',
          (int)($cfg['schema_version'] ?? 0) >= 68 && schemaColumnExists($db, 'users', 'timezone')
          && array_key_exists('site_timezone', $defaults) && $defaults['site_timezone'] === '',
          var_export($defaults['site_timezone'] ?? null, true));
    check('a zone is an IANA name PHP knows — nothing else passes',
          tzValidName('Europe/Warsaw') && tzValidName('UTC') && tzValidName('America/Argentina/Buenos_Aires')
          && !tzValidName('') && !tzValidName('Mars/Olympus_Mons') && !tzValidName('europe/warsaw')
          && !tzValidName('+02:00') && !tzValidName('Europe/Warsaw ') && !tzValidName('../../etc/passwd'));
    $phpZone = date_default_timezone_get();
    check('the site zone: the operator\'s choice first…',
          siteTimezone(['site_timezone' => 'Asia/Tokyo', 'tracker_schedule_tz' => 'America/New_York']) === 'Asia/Tokyo');
    check('… then, until they choose, the zone the schedule already runs in…',
          siteTimezone(['site_timezone' => '', 'tracker_schedule_tz' => 'America/New_York']) === 'America/New_York'
          && siteTimezone(['site_timezone' => 'Nowhere/Land', 'tracker_schedule_tz' => 'America/New_York']) === 'America/New_York');
    check('… and only when that is missing or broken, PHP\'s own',
          siteTimezone(['site_timezone' => '', 'tracker_schedule_tz' => 'bogus']) === (tzValidName($phpZone) ? $phpZone : 'UTC')
          && siteTimezone([]) === (tzValidName($phpZone) ? $phpZone : 'UTC'), $phpZone);
    check('a reader\'s own zone wins; NULL, a broken name and a guest all read the site\'s',
          userDisplayTimezone(['timezone' => 'Asia/Tokyo'], $cfgTz)->getName() === 'Asia/Tokyo'
          && userDisplayTimezone(['timezone' => null], $cfgTz)->getName() === 'Europe/Warsaw'
          && userDisplayTimezone(['timezone' => 'Bogus/Zone'], $cfgTz)->getName() === 'Europe/Warsaw'
          && userDisplayTimezone(null, $cfgTz)->getName() === 'Europe/Warsaw');
    // Written through the account's own path, with the same test the Settings page uses.
    $tzOf = function (int $id) use ($db) {
        $st = $db->prepare("SELECT timezone FROM users WHERE id = ?");
        $st->execute([$id]);
        return $st->fetchColumn();
    };
    check('an account takes a real zone…', userSetTimezone($db, (int)$me['id'], 'Asia/Tokyo') === true
          && $tzOf((int)$me['id']) === 'Asia/Tokyo');
    check('… refuses one that is not, and writes nothing when it does',
          userSetTimezone($db, (int)$me['id'], 'Mars/Olympus_Mons') === false && $tzOf((int)$me['id']) === 'Asia/Tokyo'
          && userSetTimezone($db, 0, 'Asia/Tokyo') === false);
    check('… and an empty choice hands it back to the site (NULL, not a zone called "")',
          userSetTimezone($db, (int)$mod['id'], '') === true && $tzOf((int)$mod['id']) === null);
    userSetTimezone($db, (int)$mod['id'], 'America/New_York');

    // TWO READERS, TWO ZONES, ONE ROW. Noon UTC on 15 January, written while the database session
    // runs on an offset PHP does NOT — so a `new DateTime($row['created_at'])` in PHP's zone gets it
    // wrong, and the only way to the right hour is the database's own conversion.
    $db->exec("DELETE FROM shout_mentions");
    $db->exec("DELETE FROM shouts");
    $noon = (new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')))->getTimestamp();
    $sessZone = date('P') === '+03:00' ? '+05:00' : '+03:00';
    $written = (new DateTimeImmutable('@' . $noon))->setTimezone(new DateTimeZone($sessZone))->format('Y-m-d H:i:s');
    $db->exec("SET time_zone = '" . $sessZone . "'");
    try {
        $db->prepare("INSERT INTO shouts (user_id, body, body_format, created_at) VALUES (?, 'said at noon UTC', 'plain', ?)")
           ->execute([(int)$friend['id'], $written]);
        $noonId = (int)$db->lastInsertId();
        $tokyo = shoutRows($db, $cfgTz, $row('shtuser'), 5, $noonId - 1)[0] ?? [];
        $york  = shoutRows($db, $cfgTz, $row('shtmod'), 5, $noonId - 1)[0] ?? [];
        $guest = shoutRows($db, $cfgTz, [], 5, $noonId - 1)[0] ?? [];
        $firstPage = shoutRows($db, $cfgTz, $row('shtuser'), 5);
        // A caller holding only the account's id (the shape a hand-built array has) is asked about.
        $bare = shoutRows($db, $cfgTz, ['id' => (int)$me['id']], 5, $noonId - 1)[0] ?? [];
    } finally {
        $db->exec("SET time_zone = '" . date('P') . "'");
    }
    $naive = (new DateTime($written))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('H:i');
    check('the fixture is one a naive parse gets wrong (session ' . $sessZone . ', PHP ' . date('P') . ')',
          $naive !== '21:00', $naive);
    check('the same row, formatted on the server: 21:00 for a reader in Tokyo…',
          ($tokyo['time'] ?? '') === '21:00' && ($tokyo['at'] ?? '') === '2026-01-15 21:00:00 +09:00'
          && ($tokyo['ts'] ?? 0) === $noon, json_encode($tokyo));
    check('… 07:00 for a reader in New York, the same instant underneath',
          ($york['time'] ?? '') === '07:00' && ($york['at'] ?? '') === '2026-01-15 07:00:00 -05:00'
          && ($york['ts'] ?? 0) === $noon, json_encode($york));
    check('… and the site\'s zone for a guest (Warsaw in January: 13:00, +01:00)',
          ($guest['time'] ?? '') === '13:00' && ($guest['at'] ?? '') === '2026-01-15 13:00:00 +01:00', json_encode($guest));
    $fp = array_values(array_filter($firstPage, fn($r) => (int)$r['id'] === $noonId))[0] ?? [];
    check('the first page and the poll hand over the same hour for the same line',
          ($fp['time'] ?? '') === ($tokyo['time'] ?? 'x') && ($fp['at'] ?? '') === ($tokyo['at'] ?? 'x'), json_encode($fp));
    check('a reader row without the column loaded still gets its own zone, not a guess',
          ($bare['time'] ?? '') === '21:00', json_encode($bare));
    check('the raw database string does not travel any more', !array_key_exists('created_at', $tokyo)
          && !str_contains(json_encode($tokyo), $written));

    // ── 1.62.0: a picture is a link to itself; an emote and a sticker are not ──
    $cfgImg = array_merge($cfgOn, ['desc_max_images' => '5', 'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1',
                                   'shout_flood_seconds' => '0', 'shout_emotes_enabled' => '1', 'shout_stickers_enabled' => '1']);
    $r = shoutPost($db, $cfgImg, $me, 'look [img]https://example.org/pic.png[/img]', 'bbcode', '');
    $h = (string)($r['row']['html'] ?? '');
    check('a BBCode picture is wrapped in a link to its own address, opening in a new tab',
          !empty($r['ok']) && (bool)preg_match('#<a class="shout-img-link" href="https://example\.org/pic\.png" target="_blank" rel="noopener noreferrer"[^>]*><img class="rt-img"[^>]*src="https://example\.org/pic\.png"[^>]*></a>#', $h),
          $h);
    $r = shoutPost($db, $cfgImg, $me, '![a cat](https://example.org/cat.png)', 'markdown', '');
    $h = (string)($r['row']['html'] ?? '');
    check('… and so is the Markdown form, keeping its alt text',
          !empty($r['ok']) && str_contains($h, '<a class="shout-img-link" href="https://example.org/cat.png"')
          && str_contains($h, 'alt="a cat"'), $h);
    $amp = shoutLinkImages(richtextRender('[img]https://example.org/p.png?a=1&b=2[/img]', 'bbcode', $cfgImg, true), 't');
    check('the link carries the address the renderer validated, byte for byte',
          str_contains($amp, 'href="https://example.org/p.png?a=1&amp;b=2"') && str_contains($amp, 'src="https://example.org/p.png?a=1&amp;b=2"'), $amp);
    $inUrl = shoutLinkImages(richtextRender('[url=https://example.org/page][img]https://example.org/pic.png[/img][/url]', 'bbcode', $cfgImg, true), 't');
    check('a picture the author already made a link is left as their link — never a link inside a link',
          substr_count($inUrl, '<a ') === 1 && !str_contains($inUrl, 'shout-img-link'), $inUrl);
    $bad = shoutLinkImages(richtextRender('[img]javascript:alert(1)[/img]', 'bbcode', $cfgImg, true), 't');
    check('an address the renderer refused makes no picture and no link', !str_contains($bad, 'shout-img-link') && !str_contains($bad, '<img'), $bad);
    // Emotes and stickers, through the real pipeline with pictures made for this check.
    $fakeEmotes = [
        'zzsmile' => ['id' => 1, 'code' => 'zzsmile', 'name' => 'Smile', 'width' => 16, 'height' => 16, 'is_sticker' => false, 'sha1' => str_repeat('a', 40)],
        'zzstick' => ['id' => 2, 'code' => 'zzstick', 'name' => 'Stick', 'width' => 128, 'height' => 128, 'is_sticker' => true, 'sha1' => str_repeat('b', 40)],
    ];
    $inline = shoutRenderEmotes(shoutLinkImages(richtextRender('hi :zzsmile: there [img]https://example.org/x.png[/img]', 'bbcode', $cfgImg, true), 't'), $fakeEmotes, '/', true);
    check('an emote is a word in the sentence and stays one — only the real picture beside it opens',
          str_contains($inline, 'class="shout-emote"') && substr_count($inline, 'shout-img-link') === 1
          && !preg_match('#shout-img-link[^>]*><img class="shout-emote"#', $inline), $inline);
    $stick = shoutRenderEmotes(shoutLinkImages(richtextRender(':zzstick:', 'bbcode', $cfgImg, true), 't'), $fakeEmotes, '/', true);
    check('a sticker is already its full size, so it is not a link either',
          str_contains($stick, 'class="shout-sticker"') && !str_contains($stick, 'shout-img-link') && !str_contains($stick, '<a '), $stick);
    $wave = shoutPost($db, $cfgImg, $me, 'hello :wave: there', 'bbcode', '');
    $wh = (string)($wave['row']['html'] ?? '');
    check('… and a shipped emote through shoutPost() is drawn, and not wrapped',
          !empty($wave['ok']) && str_contains($wh, 'class="shout-emote"') && !str_contains($wh, 'shout-img-link'), $wh);

    // ── retention ────────────────────────────────────────────────────────────
    // A room of exactly known size, so both halves can be measured rather than estimated.
    $db->exec("DELETE m FROM shout_mentions m JOIN shouts s ON s.id = m.shout_id");
    $db->exec("DELETE FROM shouts");
    $old = [];
    foreach (range(1, 4) as $i) {
        $db->prepare("INSERT INTO shouts (user_id, body, body_format, created_at) VALUES (?, ?, 'plain', NOW() - INTERVAL 40 DAY)")
           ->execute([(int)$me['id'], 'old ' . $i]);
        $old[] = (int)$db->lastInsertId();
    }
    $db->prepare("INSERT INTO shout_mentions (shout_id, user_id) VALUES (?, ?)")->execute([$old[0], (int)$friend['id']]);
    $fresh = [];
    foreach (range(1, 6) as $i) $fresh[] = $mk((int)$me['id'], 'fresh ' . $i);
    $p = shoutPrune($db, array_merge($cfgOn, ['shout_keep_days' => '30', 'shout_keep_rows' => '100000']));
    check('retention by age removes what is older than shout_keep_days', $p['by_age'] === 4 && $p['by_count'] === 0, json_encode($p));
    check('… and takes the mentions with it',
          (int)$db->query("SELECT COUNT(*) FROM shout_mentions WHERE shout_id = " . $old[0])->fetchColumn() === 0);
    check('… and leaves everything younger alone', (int)$db->query("SELECT COUNT(*) FROM shouts")->fetchColumn() === 6);
    $p = shoutPrune($db, array_merge($cfgOn, ['shout_keep_days' => '3650', 'shout_keep_rows' => '100']));
    check('a room under both ceilings is left alone', $p === ['by_age' => 0, 'by_count' => 0], json_encode($p));
    // shout_keep_rows clamps at 100, so the count half is exercised with a room bigger than that.
    $many = [];
    foreach (range(1, 100) as $i) $many[] = $mk((int)$me['id'], 'bulk ' . $i);
    $p = shoutPrune($db, ['shout_keep_days' => '3650', 'shout_keep_rows' => '100']);
    check('retention by count keeps the newest rows and removes the oldest', $p['by_count'] === 6 && $p['by_age'] === 0, json_encode($p));
    check('… leaving exactly shout_keep_rows behind', (int)$db->query("SELECT COUNT(*) FROM shouts")->fetchColumn() === 100);
    check('… and the survivors are the NEWEST ones',
          (int)$db->query("SELECT MIN(id) FROM shouts")->fetchColumn() === $many[0]);

    // ── the owner's purge ────────────────────────────────────────────────────
    $db->prepare("UPDATE shouts SET created_at = NOW() - INTERVAL 10 DAY WHERE id <= ?")->execute([$many[49]]);
    $gone = shoutPurge($db, $cfgOn, 5);
    check('purge with a number of days removes only what is older', $gone === 50 && (int)$db->query("SELECT COUNT(*) FROM shouts")->fetchColumn() === 50, (string)$gone);
    $gone = shoutPurge($db, $cfgOn, null);
    check('purge with nothing removes the room', $gone === 50 && (int)$db->query("SELECT COUNT(*) FROM shouts")->fetchColumn() === 0, (string)$gone);
    check('… and the mentions with it', (int)$db->query("SELECT COUNT(*) FROM shout_mentions")->fetchColumn() === 0);
} finally {
    $cleanup();
}

// ── the wiring, by reading it ────────────────────────────────────────────────
$api = (string)file_get_contents($root . '/api.php');
check('the five endpoints are routed',
      str_contains($api, "'shout_list'") && str_contains($api, "'shout_post'") && str_contains($api, "'shout_delete'")
      && str_contains($api, "'shout_seen'") && str_contains($api, "'admin/shout_purge'"));
check('admin/shout_purge is owner-only (not in the permission map)', !preg_match("/'admin\\/shout_purge'\\s*=>\\s*'panel\\./", $api));
check('the module is loaded by both front doors',
      str_contains($api, "includes/shout.php") && str_contains((string)file_get_contents($root . '/index.php'), "includes/shout.php"));
$list = (string)file_get_contents($root . '/api/shout_list.php');
check('the poll lets go of the session before it reads, and is rate limited like the message poll',
      str_contains($list, 'session_write_close()') && str_contains($list, "'shoutpoll'")
      && strpos($list, 'session_write_close()') < strpos($list, 'shoutRows('));
$purge = (string)file_get_contents($root . '/api/admin/shout_purge.php');
check('the purge asks for the owner password and is audited',
      str_contains($purge, 'requireAdminReauth') && str_contains($purge, "'shout.purge'"));
$jan = (string)file_get_contents($root . '/tools/janitor.php');
check('the janitor prunes the room in its light tick', str_contains($jan, 'shoutPrune($db, $cfg)') && str_contains($jan, '[shout] pruned='));
$pulse = (string)file_get_contents($root . '/api/user_pulse.php');
check('the pulse carries the three counts', str_contains($pulse, 'unread_shout_friend') && str_contains($pulse, 'unread_shout_mention'));
$save = (string)file_get_contents($root . '/api/admin/save_settings.php');
check('every shout setting is saveable and clamped',
      str_contains($save, "'shout_enabled'") && str_contains($save, "'shout_keep_rows' => [100, 100000, 2000]")
      && str_contains($save, "'shout_placement'"));
$kw = settingsCatalogKeywords();
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
$missing = [];
foreach (['shout_enabled', 'shout_placement', 'shout_widget_rows', 'shout_page_rows', 'shout_max_chars',
          'shout_flood_seconds', 'shout_live_seconds', 'shout_keep_rows', 'shout_keep_days', 'shout_format', 'shout_rules',
          'shout_nav', 'shout_live_seconds_guest', 'shout_system_lines'] as $k) {
    if (!isset($kw[$k]) || !str_contains($tpl, 'name="' . $k . '"')) $missing[] = $k;
}
check('every setting is on the Settings page and in the search catalogue', $missing === [], implode(', ', $missing));

// ── 1.60.0, by reading the wiring ────────────────────────────────────────────
check('the pin endpoint is routed', str_contains($api, "'shout_pin'"));
$pinSrc = (string)file_get_contents($root . '/api/shout_pin.php');
check('pinning checks the CSRF token and answers with the finished row',
      str_contains($pinSrc, 'verifyCsrfToken') && str_contains($pinSrc, 'shoutPinned($db, $cfg, $me)'));
check('the pinned line rides with the first fill and never with the poll',
      str_contains($list, "if (\$after <= 0) \$out['pinned']"));
check('… and the endpoint answers each reader with their own cadence',
      str_contains($list, 'shoutLiveSecondsFor($cfg, $me === null)'));
$wl = (string)file_get_contents($root . '/includes/whitelist.php');
check('the whitelist says so once per batch, naming nobody unless the submitter is public',
      str_contains($wl, 'shoutSystemWhitelistAdded($db, $cfg, count($addedHashes), $submitterPublic === 1 ? $submitterId : null)'));
$navSrc = (string)file_get_contents($root . '/templates/nav.php');
check('the navigation carries a shoutbox link with a badge of its own',
      str_contains($navSrc, 'id="nav-shout-unread"') && str_contains($navSrc, 'shoutNav($cfg)')
      && str_contains($navSrc, 'shoutNavUrl($cfg, $baseUrl)'));
$appJs = (string)file_get_contents($root . '/assets/js/app.js');
check('… filled from the pulse, and kept OUT of the account badge\'s total',
      str_contains($appJs, "getElementById('nav-shout-unread')") && str_contains($appJs, 'unread_shout')
      && str_contains($appJs, 'var total = this.notif + this.pm;'));
$widget = (string)file_get_contents($root . '/templates/partials/shoutbox_widget.php');
$boxJs = (string)file_get_contents($root . '/assets/js/shoutbox.js');
check('the widget draws the pinned strip and asks for the reader\'s own cadence',
      str_contains($widget, 'id="shout-pinned"') && str_contains($widget, 'shoutLiveSecondsFor($cfg,'));
check('… and the script fills the same node rather than inventing a second shape of one',
      str_contains($boxJs, 'function renderPinned') && str_contains($boxJs, "getElementById('shout-pinned')")
      && str_contains($boxJs, 'function who(') && str_contains($boxJs, 'shout-row-system'));
$adminJs = (string)file_get_contents($root . '/assets/js/admin-shout.js');
check('Settings → Shoutbox shows who may read and who may write, from the groups endpoint',
      str_contains($tpl, 'id="shout-matrix"') && str_contains($adminJs, "apiCall('admin/fetch_groups')")
      && str_contains($adminJs, "'shout.upload_emote'"));
$gids = array_column(settingsCatalogGroups(), 'id');
check('Shoutbox and Sounds are chips of their own',
      in_array('shoutbox', $gids, true) && in_array('sounds', $gids, true), implode(',', $gids));
check('… and the two sections moved into them, keeping the ids every bookmark uses',
      str_contains($tpl, 'id="section-shout" data-group="shoutbox"')
      && str_contains($tpl, 'id="section-sounds" data-group="sounds"'));

// ── 1.61.0, by reading the wiring ────────────────────────────────────────────
$idx = (string)file_get_contents($root . '/index.php');
check('the map the router uses is the one the validator reads',
      str_contains($idx, 'siteRoutes()') && !str_contains($idx, "'home'         => 'templates/pages/home.php'"));
check('index.php teaches the router the chosen name and then folds it back to the canonical action',
      str_contains($idx, 'shoutPageAction($cfg)') && str_contains($idx, '$routes[$shoutAlias]')
      && str_contains($idx, "\$action = 'shoutbox'"));
check('the new setting is saveable, and coerced against the router\'s map rather than a list',
      str_contains($save, "'shout_page_action'") && str_contains($save, 'siteRoutes()'));
$missingNew = [];
foreach (['shout_page_action'] as $k) {
    if (!isset($kw[$k]) || !str_contains($tpl, 'name="' . $k . '"')) $missingNew[] = $k;
}
check('… and is on the Settings page and in the search catalogue', $missingNew === [], implode(', ', $missingNew));

check('the mention endpoint is routed', str_contains($api, "'shout_mentions'"));
$men = (string)file_get_contents($root . '/api/shout_mentions.php');
check('it answers only a signed-in reader who may WRITE here',
      str_contains($men, "userCan(\$db, \$cfg, 'shout.post')") && str_contains($men, "'login_required'"));
check('… matches a PREFIX, never a leading wildcard, so the index stays usable',
      str_contains($men, 'u.username LIKE ?') && str_contains($men, "\$q) . '%'")
      && !str_contains($men, "'%' . ") && !str_contains($men, "'%' ."));
check('… hands back eight names at most, and nothing but names',
      str_contains($men, 'LIMIT 8') && str_contains($men, 'SELECT u.username FROM users u')
      && !str_contains($men, 'u.created_at'));
check('… is rate limited, and lets go of the session before it reads',
      str_contains($men, "rateLimitAllow('shoutmention'") && str_contains($men, 'session_write_close()')
      && strpos($men, 'session_write_close()') < strpos($men, '$db->prepare'));
check('… refuses a one-character box outright rather than answering with the first eight accounts',
      str_contains($men, 'mb_strlen($q) < 2'));
check('… and leaves out banned accounts and profiles hidden from the reader',
      str_contains($men, "u.status = 'active'") && str_contains($men, 'hide_profile'));

$sndJs = (string)file_get_contents($root . '/assets/js/sounds.js');
check('the player listens for the event the box has always dispatched',
      str_contains($sndJs, "'shout:new'") && str_contains($sndJs, 'function shoutKind')
      && str_contains($boxJs, "new CustomEvent('shout:new'"));
check('… one sound per batch, strongest kind first, never for my own line or the site\'s',
      str_contains($sndJs, "return 'mention'") && str_contains($sndJs, 'r.own || r.system'));
check('… and a batch arriving down both roads is heard once',
      str_contains($sndJs, 'quietUntil') && str_contains($sndJs, 'SHOUT_KEYS[key]'));
check('the rows carry what the player needs, decided by the server',
      str_contains((string)file_get_contents($root . '/includes/shout.php'), "'friend'      => !\$system"));

$css = (string)file_get_contents($root . '/assets/css/style.css');
check('every shout row has the gutter a mention\'s bar needs, so nothing shifts when a line is one',
      str_contains($css, 'padding: 0.34rem 1.3rem 0.34rem 0.6rem'));
check('the Add-one box is no longer pinned to a narrow column', !str_contains($css, 'max-width: 44rem'));
check('the drop zones\' "choose" word keeps its colour and loses its underline',
      str_contains((string)file_get_contents($root . '/assets/css/admin.css'), '.ipl-drop-main u { text-decoration: none;')
      && str_contains($css, '.emote-drop-main u { text-decoration: none;'));
check('the Purge button is no longer the small one beside a full-height box',
      str_contains($tpl, '<button type="button" class="btn btn-outline-danger w-100" id="shout-purge-run"'));
// 1.62.0: the owner tried 0.15 and prefers 0.2 — the number this check holds moved with it.
check('the format select is less cramped', str_contains($css, '.pm-editor .rt-format { background: var(--bg); color: var(--text); border: 1px solid var(--control-border);
    border-radius: 4px; padding: 0.2rem 0.35rem;'));

check('the refresh button is the icon font\'s glyph with no box around it',
      str_contains($widget, 'bi bi-arrow-clockwise') && !str_contains($widget, '<svg viewBox="0 0 24 24"'));
check('… and answers in a tooltip on itself rather than a line under the box',
      str_contains($boxJs, "tip(refreshBtn, t('js.shout.nothing_new')")
      && str_contains($appJs, 'window.pubTip = pubTip'));
$tabsAt  = strpos($widget, '<div class="rt-tabs">');
$countAt = $tabsAt === false ? false : strpos($widget, 'id="shout-count"', $tabsAt);
$wrapAt  = $tabsAt === false ? false : strpos($widget, 'class="shout-input-wrap"', $tabsAt);
check('the character count rides on the tabs row rather than under the box',
      $tabsAt !== false && $countAt !== false && $wrapAt !== false && $countAt < $wrapAt);
check('Send and the picker handle are inside the field',
      str_contains($widget, 'class="shout-in-acts"') && str_contains($widget, 'id="shout-send"')
      && str_contains($widget, 'id="shout-emoji"')
      && str_contains($boxJs, 'function fitComposer'));
$actsAt = strpos($widget, '<div class="shout-acts">');
$actsBlock = $actsAt === false ? '' : substr($widget, $actsAt, 1200);
check('… so nothing that can be pressed is left loose under the box',
      $actsAt !== false && !str_contains($actsBlock, 'shout-send') && !str_contains($actsBlock, 'shout-emoji')
      && str_contains($actsBlock, 'rt-syntax-fold'));
check('the fold carries the sentence that used to have a line of its own',
      str_contains($widget, "_h('shout.syntax_help')") && !str_contains($widget, '<p class="shout-hint'));

$emTpl = (string)file_get_contents($root . '/templates/pages/emotes.php');
check('the Emotes page sends people wherever the box really is',
      str_contains($emTpl, 'shoutNavUrl($cfg, $baseUrl)'));
check('… and no template builds that address out of the literal any more',
      !str_contains($emTpl, '?action=shoutbox"') && !str_contains($widget, '?action=shoutbox"'));
check('… nor tells somebody holding shout.emote_auto that they will be waiting',
      str_contains($emTpl, 'shout.emote_auto') && str_contains($emTpl, '$emApproval && !$emAuto'));
check('the permission matrix in Settings lists the new id too',
      str_contains($adminJs, "'shout.emote_auto'"));

// ── 1.62.0, by reading the wiring ────────────────────────────────────────────
check('both renderers print the SERVER\'s hour and title, and neither slices a database string',
      str_contains($widget, "sanitize((string)(\$s['time'] ?? ''))") && !str_contains($widget, "substr((string)(\$s['at']")
      && str_contains($boxJs, "text: String(r.time || '')") && !str_contains($boxJs, 'at.slice(11, 16)'));
check('the rows are made from the instant the DATABASE converted',
      str_contains((string)file_get_contents($root . '/includes/shout.php'), 'UNIX_TIMESTAMP(s.created_at) AS created_ts'));
check('no colon after the name, in either renderer — it was a ::after, and it is gone',
      !str_contains($css, '.shout-who::after') && !preg_match("/shout-who[^\\n]*':'/", $boxJs));
check('a picture opens the lightbox on a PLAIN click only; every modifier is left to the browser',
      str_contains($boxJs, "closest('a.shout-img-link')")
      && str_contains($boxJs, 'e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey')
      && str_contains($boxJs, 'lightbox().open(a)') && str_contains($boxJs, "var href = link.getAttribute('href')"));
check('… and the lightbox has its way out, its link to the original and its focus handling',
      str_contains($boxJs, "'shout-lb-close'") && str_contains($boxJs, "'shout-lb-orig'")
      && str_contains($boxJs, "e.key === 'Escape'") && str_contains($boxJs, 'back.focus('));
check('the picker is placed against its button, above when it fits and clamped to the window',
      str_contains($boxJs, 'function place()') && str_contains($boxJs, 'Math.max(EDGE, Math.min(b.right - pw, vw - EDGE - pw))')
      && !str_contains($css, "position: absolute; left: 0; bottom: 100%; z-index: 40;"));
check('the format select drops its ring after a pointer, and keeps it for the keyboard',
      str_contains($appJs, "sel.classList.add('rt-pointer')") && str_contains($appJs, "s.classList.remove('rt-pointer')")
      && str_contains($css, '.rt-format.rt-pointer:focus-visible { outline: none; }'));
check('every link the widget draws keeps its colour after a visit — the pinned name and the Emotes link included',
      str_contains($css, '.shoutbox a:visited, .shout-body a:visited, .shout-picker a:visited, .shout-lightbox a:visited { color: var(--link); }')
      && str_contains($css, '.shoutbox .shout-who:visited, .shoutbox .shout-mention:visited { color: var(--accent); }'));
check('the tooltip is placed by pubTip() — centred, pushed only by the viewport, flipped below when it must',
      str_contains($appJs, 'let left = a.left + a.width / 2 - w / 2;') && str_contains($appJs, 'left = Math.max(EDGE, Math.min(left, vw - EDGE - w));')
      && str_contains($appJs, 'const below = top < EDGE;') && !str_contains($css, '.shout-refresh .pub-tip'));
check('the site time zone is saveable, validated with the account\'s own test, and on the Settings page',
      str_contains($save, "'site_timezone',") && str_contains($save, "tzValidName(\$data['site_timezone'])")
      && str_contains($tpl, 'name="site_timezone"') && isset($kw['site_timezone']));
$upd = (string)file_get_contents($root . '/api/user_update.php');
check('the account saves its zone through the account update path, with no password for a preference',
      str_contains($upd, "userSetTimezone(\$db, (int)\$u['id'], (string)\$input['timezone'])")
      && strpos($upd, 'userSetTimezone(') < strpos($upd, 'password_verify('));
$accTpl = (string)file_get_contents($root . '/templates/pages/account.php');
check('the account page offers "Site default (zone, offset)" first, then every zone',
      str_contains($accTpl, 'id="acc-timezone"') && str_contains($accTpl, "_h('account.tz_site', ['zone' =>")
      && str_contains($accTpl, 'tzChoices(null, true)'));
// 1.64.0: the list is written short, and the block sits with the interface language rather than
// among the facts about the account — outside the language block's `if`, which only renders at all
// where the site has more than one language.
$tzShort = tzChoices(null, true);
check('a zone is written without the region it is already filed under, and without underscores',
      ($tzShort['America']['America/Argentina/Buenos_Aires'] ?? '') === 'Argentina / Buenos Aires (UTC-03:00)'
      && str_starts_with(($tzShort['Europe']['Europe/Warsaw'] ?? ''), 'Warsaw (UTC')
      && str_starts_with((tzChoices()['Europe']['Europe/Warsaw'] ?? ''), 'Europe/Warsaw (UTC'));
check('… and the panel\'s Site select reads from the same short list',
      str_contains($tpl, 'tzChoices(null, true)') && !str_contains($tpl, 'foreach (tzChoices() as'));
check('the Time zone block is in the card with Interface language, and not inside its `if`',
      strpos($accTpl, 'acc-tz-block') > strpos($accTpl, 'acc-lang-block')
      && strpos($accTpl, 'acc-tz-block') > strpos($accTpl, '<?php endif; ?>', (int)strpos($accTpl, 'acc-lang-block')));
check('the volume slider moves in ones', str_contains($accTpl, 'id="snd-vol" min="0" max="100" step="1"') && !str_contains($accTpl, 'step="5"'));
$favJs = (string)file_get_contents($root . '/assets/js/favourites.js');
check('the favourites hash is a chip, and says "Copied!" in the site\'s tooltip over itself',
      !str_contains($css, 'text-decoration: underline dotted') && str_contains($css, '.pf-hash-copy.is-copied')
      && str_contains($favJs, "window.pubTip(hs, t('js.common.copied'))"));

$peopleJs = (string)file_get_contents($root . '/assets/js/people.js');
// ── 1.64.0 ───────────────────────────────────────────────────────────────────
check('the hash chip is the size of its own words, in the site\'s font, in the site\'s standard pill',
      str_contains($css, 'justify-self: end') && str_contains($css, '.pf-hash { font-family: inherit; }')
      && str_contains($css, '.pf-hash-copy:hover { border-color: var(--accent); color: var(--accent); }'));
check('both renderers give a row an id, which is what the live language switch matches on first',
      str_contains($widget, 'id="shout-<?= (int)$s[\'id\'] ?>"')
      && str_contains($boxJs, "row.id = 'shout-' + (Number(r.id) || 0);"));
check('the question in a row hides the row\'s other controls and takes itself away again',
      str_contains($css, '.shout-asking .shout-pin { display: none; }')
      && str_contains($appJs, "opts.host.classList.add('shout-asking')")
      && str_contains($appJs, "document.addEventListener('langswap:begin', onSwap)")
      && str_contains($appJs, 'timer = setTimeout(() => finish(true), Number(opts.life) > 0 ? Number(opts.life) : 5000);'));
check('… and every way out of it goes through the one finish() that clears the timer and the listeners',
      str_contains($appJs, "document.removeEventListener('pointerdown', onOutside, true);")
      && str_contains($appJs, "document.removeEventListener('keydown', onEsc, true);")
      && str_contains($appJs, "document.removeEventListener('langswap:begin', onSwap);"));
check('… and there is ONE of it: the shoutbox, the emote cards and the inbox all call app.js\'s',
      str_contains($appJs, 'window.askInPlace = askInPlace;')
      && str_contains($boxJs, 'var askInPlace = window.askInPlace;')
      && substr_count($boxJs, 'function askInPlace') === 0
      && str_contains($peopleJs, 'window.askInPlace'));
check('the lightbox\'s controls are inside the picture, revealed on hover, and always on without one',
      !str_contains($boxJs, 'shout-lb-bar') && !str_contains($css, '.shout-lb-bar')
      && str_contains($boxJs, "el('div', { className: 'shout-lb-frame' }, [img, shut, orig])")
      && str_contains($css, '.shout-lb-frame:focus-within .shout-lb-orig, .shout-lb-frame:focus-within .shout-lb-close')
      && str_contains($css, "@media (hover: none) {\n    .shout-lb-orig, .shout-lb-close { opacity: 1; pointer-events: auto; }"));
check('every overlay closes on the backdrop only when the press STARTED there',
      substr_count($appJs, 'closeOnBackdrop(') === 5
      && str_contains($appJs, "box.addEventListener('pointerdown', (e) => { fromBackdrop = e.target === box; });")
      && substr_count($favJs, 'closeOnBackdrop(') === 3
      && substr_count($peopleJs, 'box.onpointerdown = function (e) { fromBackdrop = e.target === box; };') === 2
      && str_contains($boxJs, 'root.addEventListener(\'pointerdown\', function (e) { lbFromBackdrop = onBackdrop(e.target); });')
      && str_contains((string)file_get_contents($root . '/assets/js/admin-common.js'), 'fromBackdrop = e.target === box;'));
check('the file tree carries the reader\'s open folders across a rebuild, and marks the ones holding a match',
      str_contains($appJs, 'function treeOpenState(holder)')
      && str_contains($appJs, "holder.addEventListener('toggle', (e) => {")
      && str_contains($appJs, 'det.dataset.path = here;')
      && str_contains($appJs, "dot.className = 'ftree-dot';")
      && str_contains($css, '.ftree-dot { animation: none;'));
check('… and the loader watches the window\'s own scrolling body, and looks again after every page',
      str_contains($appJs, '}, { root: body, rootMargin: \'200px\' });')
      && str_contains($appJs, 'filesObserver.unobserve(sentinel);')
      && str_contains($appJs, 'const infoScroller = holder.closest(\'.files-body\') || null;'));
check('… a reply for a window that was closed is dropped, and Back closes the window',
      str_contains($appJs, 'const mine = ++filesSeq;')
      && str_contains($appJs, 'const ours = () => mine === filesSeq && !overlay.hidden;')
      && str_contains($appJs, "history.pushState({ filesModal: 1 }, '', location.href); filesOwned++;")
      && str_contains($appJs, 'if (overlay && !overlay.hidden) { filesOwned = Math.max(0, filesOwned - 1); closeFiles(true); return; }'));
check('a name in the people lists does not go purple once it has been opened',
      str_contains($css, '#pe-list a.pf-name:visited, #dir-list a.pf-name:visited')
      && str_contains($css, '#who-overlay .who-name:visited, #who-overlay .who-list:visited')
      && str_contains($css, 'a.pm-head-name:visited, a.av-who:visited, .av-who a:visited { color: var(--link); }')
      && str_contains($css, 'a:visited { color: var(--link-visited); }'));
check('the polled lists a script builds carry stable ids too',
      str_contains($peopleJs, "row.id = 'pm-th-' + x.with;")
      && str_contains($peopleJs, "wrap.id = 'pm-msg-' + (Number(m.id) || 0);")
      && str_contains($peopleJs, "row.id = 'pe-row-' + p.username;")
      && str_contains($peopleJs, "row.id = 'dir-row-' + p.username;"));
$medJs = (string)file_get_contents($root . '/assets/js/media-editor.js');
check('the media editor\'s × arms itself instead of opening the footer\'s question',
      str_contains($medJs, "closeHint.textContent = T('js.media.close_again');")
      && str_contains($medJs, "close.addEventListener('click', closeX);")
      && str_contains($medJs, "cancel.addEventListener('click', tryClose);")
      && str_contains($medJs, 'closeArmed = setTimeout(disarmClose, 3000);')
      // The hint is built inside the dialog, because the panel runs this editor without app.js.
      && str_contains($medJs, "className: 'fe-close-hint', role: 'status', 'aria-live': 'polite'")
      && str_contains((string)file_get_contents($root . '/assets/css/media-editor.css'), '.fe-close-hint'));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
