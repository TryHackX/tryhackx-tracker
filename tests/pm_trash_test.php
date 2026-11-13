<?php
/**
 * The messages' Archive and Trash (1.73.0, schema 90 — includes/people.php), needs the local test database:
 *   php tests/pm_trash_test.php
 *
 * ── what this file exists for ───────────────────────────────────────────────────────────────────
 *
 * EVERY READ PATH × EVERY PLACE. A conversation is in the Inbox, the Archive or the Trash — or, after a new message
 * in a trashed one, in two of them at once — and each of the paths a member reads it by has to agree: the open
 * conversation, the poll, the three lists with their previews and unread numbers, the deep search, both unread
 * counters, the tabs' counts, the inbox stamp. A path that forgets the Trash shows somebody a message they threw
 * away, or counts it on a badge nothing can clear — the v70 watermark had the same seven places, and this walks
 * them all after each move, for BOTH sides (the other person's copy must never move).
 *
 * THE OPERATIONS ARE EXPLICIT. archive / unarchive / trash / restore / purge / empty: asking for what already is
 * changes nothing and is not an error, so an Undo is the opposite operation and a late one is a no-op.
 *
 * THE JANITOR, with a clock passed in: what has been in the Trash pm_trash_days is deleted for good, in bounded
 * batches — and nothing ever leaves `user_messages` (a reported message stays readable to the panel).
 *
 * Self-cleaning: its three accounts (and with them their threads, messages and reports), its group and its scratch
 * table go at the end; no setting is written (every rule is asked with a $cfg of its own).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/people.php';

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
$SCRATCH = 'pmt_old_threads';

/* ── 1. the schema ────────────────────────────────────────────────────────── */
check('schema is at least 90', (int)($cfg['schema_version'] ?? 0) >= 90, (string)($cfg['schema_version'] ?? 'none'));
foreach (['u_low_trash_upto', 'u_high_trash_upto', 'u_low_trashed_at', 'u_high_trashed_at'] as $c) {
    check("message_threads.$c exists", schemaColumnExists($db, 'message_threads', $c));
}
check('… and the janitor has its two keys', schemaIndexExists($db, 'message_threads', 'idx_thread_trash_low')
    && schemaIndexExists($db, 'message_threads', 'idx_thread_trash_high'));
$stmts = implode("\n", trackerSchemaStatements());
check('a fresh install gets the columns from the CREATE itself', str_contains($stmts, '`u_low_trash_upto` BIGINT UNSIGNED NOT NULL DEFAULT 0')
    && str_contains($stmts, '`u_high_trashed_at` DATETIME DEFAULT NULL') && str_contains($stmts, 'KEY `idx_thread_trash_high` (`u_high_trashed_at`)'));
// The upgrade, walked on a table of the OLD shape (v70's), with a row in it.
$db->exec("DROP TABLE IF EXISTS `$SCRATCH`");
$db->exec("CREATE TABLE `$SCRATCH` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `u_low` INT UNSIGNED NOT NULL, `u_high` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `last_message_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `u_low_hidden` TINYINT(1) NOT NULL DEFAULT 0, `u_high_hidden` TINYINT(1) NOT NULL DEFAULT 0,
    `u_low_cleared_id` BIGINT UNSIGNED NOT NULL DEFAULT 0, `u_high_cleared_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_thread_pair` (`u_low`, `u_high`)) ENGINE=InnoDB");
$db->exec("INSERT INTO `$SCRATCH` (u_low, u_high, u_low_hidden, u_high_cleared_id) VALUES (1, 2, 1, 7)");
$up = schemaPmTrashMigration($db, $SCRATCH);
check('the upgrade of a v70 table is ONE statement', count($up) === 1 && str_starts_with($up[0], "ALTER TABLE `$SCRATCH` "), json_encode($up));
foreach ($up as $q) $db->exec($q);
$row = $db->query("SELECT * FROM `$SCRATCH`")->fetch(PDO::FETCH_ASSOC);
check('… after which the row has an EMPTY trash on both sides and kept everything else',
    (int)$row['u_low_trash_upto'] === 0 && (int)$row['u_high_trash_upto'] === 0 && $row['u_low_trashed_at'] === null
    && $row['u_high_trashed_at'] === null && (int)$row['u_low_hidden'] === 1 && (int)$row['u_high_cleared_id'] === 7, json_encode($row));
check('… and a second run has nothing left to do', schemaPmTrashMigration($db, $SCRATCH) === []);
check('… and a name that is not a table name is refused', schemaPmTrashMigration($db, 'x; DROP TABLE users') === []);
$db->exec("DROP TABLE IF EXISTS `$SCRATCH`");

/* ── 2. the settings ──────────────────────────────────────────────────────── */
$def = trackerSchemaDefaultSettings();
check("pm_archive_returns ships as '1' — what hiding always did", ($def['pm_archive_returns'] ?? null) === '1');
check("pm_trash_days ships as '30'", ($def['pm_trash_days'] ?? null) === '30');
check('the switch reads a missing or odd value as on, and only "0" as off',
    pmArchiveReturns([]) && pmArchiveReturns(['pm_archive_returns' => '1']) && pmArchiveReturns(['pm_archive_returns' => 'x'])
    && !pmArchiveReturns(['pm_archive_returns' => '0']));
check('the days: 30 when missing, 0 is a real answer, a year at most, never negative',
    pmTrashDays([]) === 30 && pmTrashDays(['pm_trash_days' => '0']) === 0 && pmTrashDays(['pm_trash_days' => '7']) === 7
    && pmTrashDays(['pm_trash_days' => '9999']) === 365 && pmTrashDays(['pm_trash_days' => '-5']) === 0 && pmTrashDays(['pm_trash_days' => 'abc']) === 30);
$saveSrc = (string)@file_get_contents($root . '/api/admin/save_settings.php');
$setTpl  = (string)@file_get_contents($root . '/templates/admin/settings.php');
$catSrc  = (string)@file_get_contents($root . '/includes/settings_catalog.php');
foreach (['pm_archive_returns', 'pm_trash_days'] as $k) {
    check("$k is in the save allow-list", substr_count($saveSrc, "'$k'") >= 2, (string)substr_count($saveSrc, "'$k'"));
    check("$k has a control on the Settings page", str_contains($setTpl, "name=\"$k\""));
    check("$k has search words in the catalogue", str_contains($catSrc, "'$k'"));
}
check('the days are clamped on save to 0-365 (30 for rubbish)', str_contains($saveSrc, "'pm_trash_days' => [0, 365, 30]"));
foreach (['settings.pm_archive_returns', 'settings.pm_trash_days_hint', 'pm.view_archive', 'pm.view_trash', 'pm.note_trash_many',
          'js.pm.q_trash', 'js.pm.q_purge', 'js.pm.q_empty', 'js.pm.t_trashed', 'js.pm.bar_trash', 'js.common.toast_undo'] as $k) {
    check("the words are there in both languages: $k", langHas($k) && __($k) !== $k);
}
// Every key the messages' script and the site's toast ask t() for is in the bundle a PUBLIC page carries — a key
// outside LANG_JS_PUBLIC's prefixes is not sent, and the page prints the key itself (the toast's first draft did:
// "js.toast.undo" on its button). Literal keys only; the `'js.pm.why_' + reason` family is the older code's.
$pjs = (string)@file_get_contents($root . '/assets/js/people.js');
$ajs = (string)@file_get_contents($root . '/assets/js/app.js');
$toastJs = ($p = strpos($ajs, 'const siteToast')) !== false ? substr($ajs, $p, 6000) : '';
preg_match_all("/'(js\\.[a-z]+\\.[a-z0-9_]*[a-z0-9])'/", $pjs . $toastJs, $mm);
$keys = array_values(array_unique($mm[1]));
$unsent = array_values(array_filter($keys, static function ($k) {
    foreach (LANG_JS_PUBLIC as $p) if (str_starts_with($k, $p)) return !langHas($k);
    return true;
}));
check('every key the messages\' script and the toast use is defined and in the public bundle (' . count($keys) . ' keys)',
    count($keys) > 40 && $unsent === [] && $toastJs !== '', implode(', ', $unsent));

/* ── 3. three accounts to walk it with ────────────────────────────────────── */
$names = ['pmt_anna', 'pmt_bart', 'pmt_cleo'];
foreach ($names as $nm) {
    $st = $db->prepare('SELECT id FROM users WHERE username = ?');
    $st->execute([$nm]);
    if ($old = (int)$st->fetchColumn()) userDeleteCascade($db, $old);
}
$mk = static function (PDO $db, array $cfg, string $name): int {
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'PmTrash123!', '127.0.0.1');
    $id = (int)($r['user']['id'] ?? $r['id'] ?? 0);
    $db->prepare("UPDATE users SET status = 'active', email_verified = 1 WHERE id = ?")->execute([$id]);
    return $id;
};
$A = $mk($db, $cfg, 'pmt_anna');
$B = $mk($db, $cfg, 'pmt_bart');
$C = $mk($db, $cfg, 'pmt_cleo');
check('three test accounts exist', $A > 0 && $B > 0 && $C > 0, "$A / $B / $C");

$on30 = array_merge($cfg, ['pm_enabled' => '1', 'pm_archive_returns' => '1', 'pm_trash_days' => '30']);
$stay = array_merge($on30, ['pm_archive_returns' => '0']);
$none = array_merge($on30, ['pm_trash_days' => '0']);

$thread = static fn(PDO $db, int $x, int $y): ?array => pmThreadFor($db, $x, $y, false);
/** A message as the endpoint writes one: the insert, and whose Archive it leaves (pmAfterMessage()). */
$send = static function (PDO $db, array $c, int $from, int $to, string $body): int {
    $th = pmThreadFor($db, $from, $to);
    $had = pmHasVisible($db, $th, $to);
    $db->prepare("INSERT INTO user_messages (thread_id, sender_id, body, body_format) VALUES (?, ?, ?, 'bbcode')")
       ->execute([(int)$th['id'], $from, $body]);
    $id = (int)$db->lastInsertId();
    pmAfterMessage($db, $c, $th, $from, $had);
    return $id;
};
/**
 * Everything one reader is shown of the conversation with $other, by every path there is.
 */
$paths = static function (PDO $db, array $c, int $me, int $other, string $word) use ($thread): array {
    $th = $thread($db, $me, $other);
    $ids = static fn(array $rows): array => array_map(static fn($r) => (int)$r['id'], $rows);
    $row = static function (array $list) use ($th): ?array {
        foreach ($list as $r) if ($th && (int)$r['id'] === (int)$th['id']) return $r;
        return null;
    };
    $out = [
        'live'  => $th ? $ids(pmThreadMessages($db, $th, $me, 'live')) : [],
        'poll'  => $th ? $ids(pmThreadMessages($db, $th, $me, 'live', 0, 50)) : [],
        'trash' => $th ? $ids(pmThreadMessages($db, $th, $me, 'trash')) : [],
        'unread' => pmUnreadCount($db, $me),
        'unread_friends' => pmUnreadCountFriends($db, $me),
        'counts' => pmBoxCounts($db, $me),
        'state' => $th ? pmThreadState($db, $c, $th, $me) : null,
    ];
    foreach (['inbox', 'archive', 'trash'] as $v) {
        $r = $row(pmListThreads($db, $me, $v));
        $out['list_' . $v] = $r ? ['last_id' => (int)$r['last_id'], 'body' => (string)$r['last_body'],
                                   'unread' => (int)($r['unread'] ?? 0), 'n' => (int)($r['n'] ?? 0)] : null;
        // The deep search as the endpoint runs it: the place's range searched, then that place's list narrowed to it.
        $out['deep_' . $v] = $row(pmListThreads($db, $me, $v, pmDeepSearchIds($db, $me, $v, $word))) !== null;
    }
    return $out;
};
$total = static function (PDO $db, array $th): int {
    $st = $db->prepare("SELECT COUNT(*) FROM user_messages WHERE thread_id = ?");
    $st->execute([(int)$th['id']]);
    return (int)$st->fetchColumn();
};

/* ── 4. a conversation, in the Inbox of both ──────────────────────────────── */
$m = [];
$m[1] = $send($db, $on30, $A, $B, 'anna one kumquat');
$m[2] = $send($db, $on30, $B, $A, 'bart one kumquat');
$m[3] = $send($db, $on30, $A, $B, 'anna two');
$m[4] = $send($db, $on30, $B, $A, 'bart two');
$m[5] = $send($db, $on30, $B, $A, 'bart three');
$pa = $paths($db, $on30, $A, $B, 'kumquat');
check('both in the Inbox: A reads all five, by the thread and by the poll', $pa['live'] === array_values($m) && $pa['poll'] === array_values($m), json_encode($pa['live']));
check('… the Inbox lists it with the last line, and nothing is in the Archive or the Trash',
    $pa['list_inbox'] && $pa['list_inbox']['body'] === 'bart three' && $pa['list_inbox']['last_id'] === $m[5]
    && $pa['list_archive'] === null && $pa['list_trash'] === null && $pa['trash'] === [], json_encode($pa));
check('… three unread for A (Bart\'s), counted everywhere the same', $pa['unread'] === 3 && $pa['list_inbox']['unread'] === 3
    && $pa['counts']['unread_inbox'] === 3 && $pa['counts']['inbox'] === 1 && $pa['counts']['archive'] === 0 && $pa['counts']['trash'] === 0,
    json_encode($pa['counts']));
check('… the deep search finds the word in the Inbox only', $pa['deep_inbox'] && !$pa['deep_archive'] && !$pa['deep_trash']);
check('… and the conversation says it is in the Inbox', $pa['state']['place'] === 'inbox' && $pa['state']['live'] && $pa['state']['trash'] === 0);

/* ── 5. the Archive ──────────────────────────────────────────────────────── */
$th = $thread($db, $A, $B);
check('archive: it changes something the first time', pmSetArchived($db, $th, $A, true) === true);
check('… and nothing the second (explicit, not a toggle)', pmSetArchived($db, $thread($db, $A, $B), $A, true) === false);
$pa = $paths($db, $on30, $A, $B, 'kumquat');
check('archived: gone from A\'s Inbox, in A\'s Archive with the same last line', $pa['list_inbox'] === null
    && $pa['list_archive'] && $pa['list_archive']['body'] === 'bart three', json_encode([$pa['list_inbox'], $pa['list_archive']]));
check('… the conversation itself is whole (nothing is deleted)', $pa['live'] === array_values($m));
check('… its three unread are STILL counted — the Archive tab carries them', $pa['unread'] === 3 && $pa['counts']['unread_archive'] === 3
    && $pa['counts']['unread_inbox'] === 0 && $pa['counts']['archive'] === 1 && $pa['counts']['inbox'] === 0, json_encode($pa['counts']));
check('… the deep search finds it in the Archive, not the Inbox', !$pa['deep_inbox'] && $pa['deep_archive']);
check('… and it says "archive"', $pa['state']['place'] === 'archive' && $pa['state']['archived']);
$pb = $paths($db, $on30, $B, $A, 'kumquat');
check('Bart\'s side did not move', $pb['list_inbox'] !== null && $pb['list_archive'] === null && $pb['live'] === array_values($m));
check('unarchive: back in the Inbox; asked again it changes nothing',
    pmSetArchived($db, $thread($db, $A, $B), $A, false) === true && pmSetArchived($db, $thread($db, $A, $B), $A, false) === false
    && $paths($db, $on30, $A, $B, 'kumquat')['list_inbox'] !== null);

// A new message in an archived conversation — the site's switch.
pmSetArchived($db, $thread($db, $A, $B), $A, true);
$m[6] = $send($db, $stay, $B, $A, 'bart four, while archived');
$pa = $paths($db, $stay, $A, $B, 'kumquat');
check('pm_archive_returns = 0: a new message leaves it in the Archive, marked unread THERE',
    $pa['list_inbox'] === null && $pa['list_archive'] && $pa['list_archive']['body'] === 'bart four, while archived'
    && $pa['list_archive']['unread'] === 4, json_encode($pa['list_archive']));
check('… and counted on the badge and on the Archive tab', $pa['unread'] === 4 && $pa['counts']['unread_archive'] === 4, json_encode($pa['counts']));
check('… while the writer — Bart — has it in his Inbox', $paths($db, $stay, $B, $A, 'kumquat')['list_inbox'] !== null);
$m[7] = $send($db, $on30, $B, $A, 'bart five');
$pa = $paths($db, $on30, $A, $B, 'kumquat');
check('pm_archive_returns = 1: a new message brings it back to the Inbox (what hiding did)', $pa['list_inbox'] !== null && $pa['list_archive'] === null);
pmSetArchived($db, $thread($db, $A, $B), $A, true);
$m[8] = $send($db, $stay, $A, $B, 'anna writes from the Archive');
check('writing in an archived conversation brings it back for the WRITER, whatever the switch says',
    $paths($db, $stay, $A, $B, 'kumquat')['list_inbox'] !== null);
// Opening marks read — only what is shown.
pmMarkRead($db, $thread($db, $A, $B), $A, 'live');
check('opening it reads what is shown: nothing waits any more', pmUnreadCount($db, $A) === 0);

/* ── 6. the Trash ────────────────────────────────────────────────────────── */
$m[9] = $send($db, $on30, $B, $A, 'bart six, unread when trashed kumquat');
$th = $thread($db, $A, $B);
$tr = pmTrash($db, $on30, $th, $A);
check('trash: into the Trash, everything up to the last message there is', $tr['changed'] && !$tr['final'] && $tr['was'] === 0 && $tr['upto'] === $m[9], json_encode($tr));
$at1 = $thread($db, $A, $B)['u_' . ((int)$th['u_low'] === $A ? 'low' : 'high') . '_trashed_at'];
check('… with the moment it happened', $at1 !== null && strtotime((string)$at1) > time() - 120, (string)$at1);
$again = pmTrash($db, $on30, $thread($db, $A, $B), $A);
check('… and asked again it changes nothing, not even the moment', !$again['changed']
    && $thread($db, $A, $B)['u_' . ((int)$th['u_low'] === $A ? 'low' : 'high') . '_trashed_at'] === $at1, json_encode($again));
$pa = $paths($db, $on30, $A, $B, 'kumquat');
check('trashed: nothing of it is shown to A — not by the thread, not by the poll', $pa['live'] === [] && $pa['poll'] === [], json_encode($pa['live']));
check('… it is in no list but the Trash, which previews ITS last line and counts nine', $pa['list_inbox'] === null && $pa['list_archive'] === null
    && $pa['list_trash'] && $pa['list_trash']['body'] === 'bart six, unread when trashed kumquat' && $pa['list_trash']['n'] === 9, json_encode($pa['list_trash']));
check('… opened in the Trash it shows all nine', $pa['trash'] === array_values($m), json_encode($pa['trash']));
check('… its unread message is NOT counted (like the watermark): no badge nothing can clear', $pa['unread'] === 0 && $pa['unread_friends'] === 0
    && $pa['counts']['unread_inbox'] === 0 && $pa['counts']['unread_archive'] === 0 && $pa['counts']['trash'] === 1 && $pa['counts']['inbox'] === 0, json_encode($pa['counts']));
check('… the deep search finds the word in the Trash only', !$pa['deep_inbox'] && !$pa['deep_archive'] && $pa['deep_trash']);
check('… and it says "trash", with when it runs out', $pa['state']['place'] === 'trash' && $pa['state']['trash'] === 9
    && $pa['state']['until'] !== null && abs(strtotime($pa['state']['until']) - strtotime((string)$at1) - 30 * 86400) < 2, json_encode($pa['state']));
$pb = $paths($db, $on30, $B, $A, 'kumquat');
check('Bart\'s copy did not move by one message, by any path', $pb['live'] === array_values($m) && $pb['list_inbox'] && $pb['list_trash'] === null
    && $pb['trash'] === [] && $pb['deep_inbox'] && !$pb['deep_trash'], json_encode($pb));
check('nothing left user_messages', $total($db, $th) === 9);
$opened = pmMarkRead($db, $thread($db, $A, $B), $A, 'live');
check('opening what is shown (nothing) marks nothing read — no receipt for a message in the Trash', $opened === 0
    && $db->query("SELECT read_at FROM user_messages WHERE id = " . $m[9])->fetchColumn() === null);

// A new message in a trashed conversation.
$m[10] = $send($db, $on30, $B, $A, 'bart seven, after the trash');
$pa = $paths($db, $on30, $A, $B, 'kumquat');
check('a new message: the conversation is back in the Inbox with ONLY that message', $pa['live'] === [$m[10]] && $pa['poll'] === [$m[10]]
    && $pa['list_inbox'] && $pa['list_inbox']['body'] === 'bart seven, after the trash' && $pa['list_inbox']['unread'] === 1, json_encode([$pa['live'], $pa['list_inbox']]));
check('… while the older part waits in the Trash, still nine, still restorable', $pa['list_trash'] && $pa['list_trash']['n'] === 9
    && $pa['trash'] === array_slice(array_values($m), 0, 9), json_encode($pa['list_trash']));
check('… two places at once, the tabs say so, and the badge is the one new message',
    $pa['counts']['inbox'] === 1 && $pa['counts']['trash'] === 1 && $pa['unread'] === 1 && $pa['state']['place'] === 'inbox' && $pa['state']['trash'] === 9,
    json_encode([$pa['counts'], $pa['state']]));
check('… an old word is a hit in the Trash, the new message\'s in the Inbox', $pa['deep_trash'] && !$pa['deep_inbox']
    && in_array((int)$thread($db, $A, $B)['id'], pmDeepSearchIds($db, $A, 'inbox', 'after the trash'), true));
// Archived AND trashed, then a new message with the switch off: it comes back as a new conversation, in the Inbox.
$th2 = pmThreadFor($db, $A, $C);
$send($db, $on30, $C, $A, 'cleo one');
pmSetArchived($db, $thread($db, $A, $C), $A, true);
pmTrash($db, $on30, $thread($db, $A, $C), $A);
$send($db, $stay, $C, $A, 'cleo two, after Anna threw it away');
$pc = $paths($db, $stay, $A, $C, 'cleo');
check('trashed from the Archive, then a new message (switch off): back in the INBOX, never the Archive',
    $pc['list_inbox'] && $pc['list_inbox']['body'] === 'cleo two, after Anna threw it away' && $pc['list_archive'] === null && $pc['list_trash'] !== null,
    json_encode([$pc['list_inbox'], $pc['list_archive']]));
pmPurge($db, $thread($db, $A, $C), $A);

// Restore, and its Undo.
$th = $thread($db, $A, $B);
$rs = pmRestore($db, $th, $A);
check('restore: all of it back — to the Inbox, where the conversation is', $rs['changed'] && $rs['upto'] === 0 && $rs['was'] === $m[9] && $rs['place'] === 'inbox', json_encode($rs));
$pa = $paths($db, $on30, $A, $B, 'kumquat');
check('… the thread and the poll show all ten again, the Trash is empty', $pa['live'] === array_values($m) && $pa['list_trash'] === null
    && $pa['counts']['trash'] === 0 && $pa['state']['trash'] === 0 && $pa['state']['trashed_at'] === null, json_encode([$pa['live'], $pa['counts']]));
check('… and the message that was unread in the Trash is unread again (it was never opened)', $pa['list_inbox'] && $pa['list_inbox']['unread'] === 2,
    json_encode($pa['list_inbox']));
check('restore with nothing in the Trash: nothing changes, no error', pmRestore($db, $thread($db, $A, $B), $A)['changed'] === false);
// The Undo of a Trash names what it undoes.
$t1 = pmTrash($db, $on30, $thread($db, $A, $B), $A, $m[4]);
check('trash up to the last line the PAGE had: a message after it stays shown', $t1['changed'] && $t1['upto'] === $m[4]
    && $paths($db, $on30, $A, $B, 'kumquat')['live'] === array_slice(array_values($m), 4), json_encode($t1));
$t2 = pmTrash($db, $on30, $thread($db, $A, $B), $A);
check('… and a second Delete takes the rest, remembering the edge before it', $t2['changed'] && $t2['was'] === $m[4] && $t2['upto'] === $m[10], json_encode($t2));
$stale = pmRestore($db, $thread($db, $A, $B), $A, $t1['was'], $t1['upto']);
check('the first Delete\'s Undo, now that a second one followed it, is stale: nothing moves', !$stale['changed'] && $stale['stale']
    && pmTrashUpto($thread($db, $A, $B), $A) === $m[10], json_encode($stale));
$u2 = pmRestore($db, $thread($db, $A, $B), $A, $t2['was'], $t2['upto']);
check('the second Delete\'s Undo puts back exactly what it took — the older part stays in the Trash', $u2['changed'] && $u2['upto'] === $m[4]
    && $paths($db, $on30, $A, $B, 'kumquat')['live'] === array_slice(array_values($m), 4)
    && $paths($db, $on30, $A, $B, 'kumquat')['trash'] === array_slice(array_values($m), 0, 4), json_encode($u2));
check('… and the same Undo again is a no-op, not an error', pmRestore($db, $thread($db, $A, $B), $A, $t2['was'], $t2['upto'])['changed'] === false);
// The Undo of a Restore is a Trash up to the edge the Restore took away.
$r3 = pmRestore($db, $thread($db, $A, $B), $A);
$m[11] = $send($db, $on30, $B, $A, 'bart eight, between the restore and its undo');
$u3 = pmTrash($db, $on30, $thread($db, $A, $B), $A, $r3['was']);
check('a Restore\'s Undo returns what it restored to the Trash, and a message that came since stays shown',
    $u3['changed'] && $u3['upto'] === $m[4] && $paths($db, $on30, $A, $B, 'kumquat')['live'] === array_slice(array_values($m), 4), json_encode($u3));

/* ── 7. deleting for good ────────────────────────────────────────────────── */
$th = $thread($db, $A, $B);
$side = (int)$th['u_low'] === $A ? 'u_low' : 'u_high';
check('purge: the Trash under the watermark', pmPurge($db, $th, $A) === true);
$th = $thread($db, $A, $B);
check('… the watermark stands where the Trash ended, the Trash is empty (0 and NULL together)',
    (int)$th[$side . '_cleared_id'] === $m[4] && (int)$th[$side . '_trash_upto'] === 0 && $th[$side . '_trashed_at'] === null, json_encode($th));
$pa = $paths($db, $on30, $A, $B, 'kumquat');
check('… and nothing under it is shown anywhere, nor ever restorable', $pa['trash'] === [] && $pa['list_trash'] === null
    && $pa['live'] === array_slice(array_values($m), 4) && !pmRestore($db, $thread($db, $A, $B), $A)['changed'] && !$pa['deep_trash']);
check('… the deep search: "kumquat" of the deleted part is no hit, of the part still shown (bart six) one in the Inbox',
    $pa['deep_inbox'] && !in_array((int)$thread($db, $A, $B)['id'], pmDeepSearchIds($db, $A, 'inbox', 'anna one'), true)
    && !in_array((int)$thread($db, $A, $B)['id'], pmDeepSearchIds($db, $A, 'trash', 'anna one'), true));
check('… purge again: nothing to do', pmPurge($db, $thread($db, $A, $B), $A) === false);
check('… Bart still has all eleven, and user_messages all eleven', count($paths($db, $on30, $B, $A, 'kumquat')['live']) === 11 && $total($db, $th) === 11);
// No Trash on the site: Delete is v70's delete, final.
$fin = pmTrash($db, $none, $thread($db, $A, $B), $A);
$th = $thread($db, $A, $B);
check('pm_trash_days = 0: Delete deletes at once — the watermark moves, nothing waits in a Trash',
    $fin['changed'] && $fin['final'] && (int)$th[$side . '_cleared_id'] === $m[11] && (int)$th[$side . '_trash_upto'] === 0
    && $paths($db, $none, $A, $B, 'kumquat')['live'] === [], json_encode($fin));
// Empty the Trash: several conversations at once.
$send($db, $on30, $C, $A, 'cleo three');
$send($db, $on30, $B, $A, 'bart nine');
pmTrash($db, $on30, $thread($db, $A, $B), $A);
pmTrash($db, $on30, $thread($db, $A, $C), $A);
check('two conversations in the Trash', pmBoxCounts($db, $A)['trash'] === 2, json_encode(pmBoxCounts($db, $A)));
check('empty the Trash: both, for good, in one go', pmEmptyTrash($db, $A) === 2 && pmBoxCounts($db, $A)['trash'] === 0
    && pmListThreads($db, $A, 'trash') === []);
check('… and again: nothing', pmEmptyTrash($db, $A) === 0);

/* ── 8. the janitor, with a clock ────────────────────────────────────────── */
// The clock is in 2001 and this file's Trashes are dated against it: the purge reads every Trash in the database,
// and only rows this file dated that far back can ever be old enough to go — nobody else's is touched by the test.
$send($db, $on30, $B, $A, 'bart ten');
$send($db, $on30, $C, $A, 'cleo four');
$send($db, $on30, $C, $B, 'cleo to bart');
pmTrash($db, $on30, $thread($db, $A, $B), $A);
pmTrash($db, $on30, $thread($db, $A, $C), $A);
pmTrash($db, $on30, $thread($db, $B, $C), $B);
$now = strtotime('2001-06-01 12:00:00');
$setAt = static function (PDO $db, array $th, int $who, string $at): void {
    $col = ((int)$th['u_low'] === $who ? 'u_low' : 'u_high') . '_trashed_at';
    $db->prepare("UPDATE message_threads SET `$col` = ? WHERE id = ?")->execute([$at, (int)$th['id']]);
};
$setAt($db, $thread($db, $A, $B), $A, date('Y-m-d H:i:s', $now - 31 * 86400));
$setAt($db, $thread($db, $A, $C), $A, date('Y-m-d H:i:s', $now - 29 * 86400));
$setAt($db, $thread($db, $B, $C), $B, date('Y-m-d H:i:s', $now - 40 * 86400));
$j1 = pmTrashPurgeExpired($db, $on30, $now);
check('the janitor deletes for good what has been in the Trash 30 days — and only that',
    $j1['purged'] === 2 && pmTrashUpto($thread($db, $A, $B), $A) === 0 && pmTrashUpto($thread($db, $B, $C), $B) === 0
    && pmTrashUpto($thread($db, $A, $C), $A) > 0, json_encode($j1));
check('… the 29-day one goes a day later, by the same clock', pmTrashPurgeExpired($db, $on30, $now + 86400 + 1)['purged'] === 1
    && pmTrashUpto($thread($db, $A, $C), $A) === 0);
check('… and what it deleted is deleted: the watermark moved, the rows are all still there',
    pmClearedId($thread($db, $A, $B), $A) > 0 && pmThreadMessages($db, $thread($db, $A, $B), $A, 'trash') === []
    && $total($db, $thread($db, $A, $B)) === 13, (string)$total($db, $thread($db, $A, $B)));
// Bounded: one statement a batch, a few batches a tick.
foreach ([[$A, $B, 'bart eleven'], [$A, $C, 'cleo five'], [$B, $C, 'cleo to bart again']] as [$x, $y, $w]) {
    $send($db, $on30, $y, $x, $w);
    pmTrash($db, $on30, $thread($db, $x, $y), $x);
    $setAt($db, $thread($db, $x, $y), $x, date('Y-m-d H:i:s', $now - 60 * 86400));
}
$j2 = pmTrashPurgeExpired($db, $on30, $now, 1, 1);
check('a pass is bounded: batch 1, one batch a side — at most two rows, one statement each', $j2['purged'] <= 2 && $j2['purged'] >= 1 && $j2['batches'] === 2, json_encode($j2));
$j3 = pmTrashPurgeExpired($db, $on30, $now, 1, 5);
check('… and the next tick takes the rest', $j2['purged'] + $j3['purged'] === 3 && pmTrashPurgeExpired($db, $on30, $now)['purged'] === 0, json_encode([$j2, $j3]));
$send($db, $on30, $B, $A, 'bart twelve');
pmTrash($db, $on30, $thread($db, $A, $B), $A);
$setAt($db, $thread($db, $A, $B), $A, date('Y-m-d H:i:s', $now - 10));
check('pm_trash_days = 0 at the janitor: whatever is in a Trash goes at once, ten seconds old or not',
    ($x0 = pmTrashPurgeExpired($db, $none, $now))['purged'] === 1 && pmTrashUpto($thread($db, $A, $B), $A) === 0, json_encode($x0));
$jan = (string)@file_get_contents($root . '/tools/janitor.php');
check('the janitor calls it every tick and logs what it did', str_contains($jan, 'pmTrashPurgeExpired($db, $cfg)') && str_contains($jan, '[pm] trash purged='));

/* ── 9. reports, the panel, and an account's deletion ────────────────────── */
$mr = $send($db, $on30, $B, $A, 'a line anna reports, then throws away');
$mctx = $send($db, $on30, $A, $B, 'her answer');
$db->prepare("INSERT INTO message_reports (message_id, context_id, thread_id, reporter_id, reported_user_id, reason) VALUES (?, NULL, ?, ?, ?, 'fixture')")
   ->execute([$mr, (int)$thread($db, $A, $B)['id'], $A, $B]);
$db->prepare("UPDATE user_messages SET reported = 1 WHERE id = ?")->execute([$mr]);
pmTrash($db, $on30, $thread($db, $A, $B), $A);
pmPurge($db, $thread($db, $A, $B), $A);
pmTrash($db, $none, $thread($db, $B, $A), $B);
$q = $db->prepare("SELECT m.body FROM message_reports r LEFT JOIN user_messages m ON m.id = r.message_id WHERE r.message_id = ?");
$q->execute([$mr]);
check('a reported message both sides deleted for good is still readable to the panel, with its report', (string)$q->fetchColumn() === 'a line anna reports, then throws away');
$adminSrc = '';
foreach (glob($root . '/api/admin/*.php') as $f) $adminSrc .= (string)file_get_contents($f);
$adminSrc .= (string)@file_get_contents($root . '/assets/js/admin-messages.js');
check('the panel reads no member\'s Archive or Trash (no panel endpoint touches those columns or the lists)',
    !preg_match('/u_(low|high)_(hidden|trash_upto|trashed_at|cleared_id)|pmListThreads|pmBoxCounts|pmThreadMessages|pmThreadState/', $adminSrc));
$apiSrc = (string)@file_get_contents($root . '/api/user_messages.php');
check('… and no mailbox operation writes the audit log the panel reads', substr_count($apiSrc, 'auditLog(') === 1 && str_contains($apiSrc, "'pm.report'"));
check('the endpoint offers the six explicit operations and the two old names as aliases',
    str_contains($apiSrc, "['read', 'archive', 'unarchive', 'trash', 'restore', 'purge']") && str_contains($apiSrc, "\$op === 'empty_trash'")
    && str_contains($apiSrc, "if (\$op === 'hide') \$op = 'archive';") && str_contains($apiSrc, "if (\$op === 'delete') \$op = 'trash';"));
$thAB = $thread($db, $A, $B);
$gone = userDeleteCascade($db, $B);
$left = static function (string $sql, array $args) use ($db): int { $st = $db->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); };
check('deleting an account still takes everything with it — the thread with both sides\' Trash, its messages, its report',
    $left("SELECT COUNT(*) FROM message_threads WHERE id = ?", [(int)$thAB['id']]) === 0
    && $left("SELECT COUNT(*) FROM user_messages WHERE thread_id = ?", [(int)$thAB['id']]) === 0
    && $left("SELECT COUNT(*) FROM message_reports WHERE thread_id = ?", [(int)$thAB['id']]) === 0, json_encode($gone));

/* ── the end: nothing of this file is left ───────────────────────────────── */
foreach ([$A, $C] as $id) userDeleteCascade($db, $id);
check('the test accounts are gone', $left("SELECT COUNT(*) FROM users WHERE username IN ('pmt_anna','pmt_bart','pmt_cleo')", []) === 0);

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
