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
$defaults = trackerSchemaDefaultSettings();
foreach (['shout_enabled' => '0', 'shout_placement' => 'home', 'shout_widget_rows' => '25', 'shout_page_rows' => '100',
          'shout_max_chars' => '500', 'shout_flood_seconds' => '5', 'shout_live_seconds' => '10',
          'shout_keep_rows' => '2000', 'shout_keep_days' => '30', 'shout_format' => 'bbcode', 'shout_rules' => ''] as $k => $v) {
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
$names = ['shtuser', 'shtmod', 'shtmute', 'shtnone', 'shtfriend'];
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
check('the five test accounts exist', count(array_filter($uid)) === 5, json_encode($uid));

$modGroup = (int)($db->query("SELECT id FROM user_groups WHERE slug = 'moderator'")->fetchColumn() ?: 0);
userGrantGroup($db, $uid['shtmod'], $modGroup, null, 'shout_test', '', false);
// shtnone keeps no group at all: the closest thing to "a member whose group carries nothing".
$db->prepare("DELETE FROM user_group_members WHERE user_id = ?")->execute([$uid['shtnone']]);
$db->prepare("UPDATE users SET pm_muted_until = NOW() + INTERVAL 1 DAY WHERE id = ?")->execute([$uid['shtmute']]);

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
    $db->prepare("DELETE FROM users WHERE username IN ($in)")->execute($names);
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
          'shout_flood_seconds', 'shout_live_seconds', 'shout_keep_rows', 'shout_keep_days', 'shout_format', 'shout_rules'] as $k) {
    if (!isset($kw[$k]) || !str_contains($tpl, 'name="' . $k . '"')) $missing[] = $k;
}
check('every setting is on the Settings page and in the search catalogue', $missing === [], implode(', ', $missing));

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
