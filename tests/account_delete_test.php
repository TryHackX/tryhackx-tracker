<?php
/**
 * Deleting an account removes everything that is its own (needs the local test database):
 *   php tests/account_delete_test.php
 *
 * ── what this file holds down (1.73.0) ──────────────────────────────────────────────────────────
 *
 * userDeleteCascade() is "one function, one place to add the next table" — and three tables had not been
 * added: the second factor (`user_twofa`: the TOTP secret and the recovery hashes stayed behind), the
 * "…is typing" rows, and a bulk mail still queued for the account (the janitor would have mailed somebody
 * whose account was gone). Its votes went, but the totals stored on the catalogue rows — what a search
 * listing shows — went on counting them. So, with a FRESH account (never tests/users_test.php, which empties
 * the table — battery only):
 *
 *   1. a row in each of those tables, the cascade, and each one gone (the mail skipped, the totals recounted);
 *   2. THE REGISTRY: every table in the live schema with a column that names an account is either named in
 *      the cascade's code (or the two functions it hands over to) or on the list of tables that keep the id
 *      on purpose, with the reason — so the next table nobody remembers fails here, not in production;
 *   3. after the cascade, no such column holds the account's id, but for those kept on purpose — and but for what
 *      held that id BEFORE the account existed (below);
 *   4. the v91 migration's step (schemaAccountOrphans()): rows a deleted account left behind before the cascade
 *      knew their tables go, and a live account's identical rows stay.
 *
 * What held the id before the account did (1.73.0 part G). A fresh account can be given an id an account of an
 * earlier run had: deploy/smoke_users.py — which the battery runs first — TRUNCATEs `users` (the counter starts
 * again at 1) and deletes every conversation with a plain DELETE that leaves their message reports behind; and
 * tests that remove accounts or conversations with raw SQL leave rows the same way. The battery of 2026-10-01 met
 * exactly that: message report 466, filed by smokepeer (id 6 that day) in a browser check's conversation that the
 * smoke's next run deleted, the account truncated away with it — and deltest_a was given id 6: "…reporter_id=1",
 * a row this file never wrote, about an account that was not this one. (The product cannot leave such a row: a
 * message report is filed by one of its conversation's two people, and userDeleteCascade() deletes a conversation's
 * reports with it — the only path that deletes a conversation.) So step 3 judges only what THIS run put there: just
 * before the accounts are made, every account column's rows that name an id no account has are counted per id, and
 * what the deleted account's id had then is subtracted. Counted before the account exists, not after, so what its
 * own creation writes (the default group's membership) is judged with everything else the run added.
 *
 * Self-cleaning: its accounts, threads, rows and hashes are removed at the end.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/reputation.php';
require_once $root . '/includes/content.php';
require_once $root . '/includes/comments.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$cfg = getSettings($db, true);
check('schema 91 or later (the v91 step is part of this release)', (int)($cfg['schema_version'] ?? 0) >= 91,
      (string)($cfg['schema_version'] ?? 'none'));

$NAMES = ['deltest_a', 'deltest_b', 'deltest_c', 'deltest_live'];
$mk = static fn(string $tag) => substr(hash('sha1', 'account-delete-test-' . $tag), 0, 40);
$H1 = $mk('voted'); $H2 = $mk('orphan-voted');
$cleanup = function () use ($db, $cfg, $NAMES, $H1, $H2) {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    foreach ($NAMES as $nm) { $st->execute([$nm]); if ($id = (int)$st->fetchColumn()) userDeleteCascade($db, $id, $cfg); }
    foreach ([$H1, $H2] as $h) {
        $db->prepare("DELETE FROM hash_votes WHERE info_hash = ?")->execute([$h]);
        $db->prepare("DELETE FROM whitelist WHERE info_hash = ?")->execute([$h]);
    }
    $db->exec("DELETE FROM mail_queue WHERE batch_id = 'deltest0000test0'");
    $db->exec("DELETE FROM user_twofa WHERE secret = 'DELTESTDELTESTDE'");
    $db->exec("DELETE FROM message_typing WHERE thread_id = 4294967000");
};
$cleanup();

// The account columns of the live schema (steps 2 and 3), read before the accounts are made.
// Kept on purpose — the same list the cascade's comment gives, with the same reasons.
$KEEP = [
    'audit_log'         => 'a record of what happened',
    'user_group_orders' => 'a partner shop\'s ledger and its replay guard: an order id must never grant twice',
    'shout_emotes'      => 'uploaded_by: NULL means "the panel\'s", so a gone uploader stays a raw id',
];
$ACCOUNT_COLS = ['user_id', 'sender_id', 'reporter_id', 'reported_user_id', 'author_id', 'submitter_id', 'content_user_id',
                 'friend_id', 'blocked_id', 'by_id', 'edited_by', 'approved_by', 'deleted_by', 'pinned_by', 'uploaded_by',
                 'actor_id', 'u_low', 'u_high', 'voter_key'];
$in = implode(',', array_fill(0, count($ACCOUNT_COLS), '?'));
$cols = $db->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ($in) AND TABLE_NAME NOT LIKE 'audit_scratch%'
                       ORDER BY TABLE_NAME, COLUMN_NAME");
$cols->execute($ACCOUNT_COLS);
$accountCols = $cols->fetchAll(PDO::FETCH_NUM);
// What already names an id that no account has — per column, per id — before this run makes any (the head of the
// file): the rows a fresh account would find under its id without having written one of them.
$orphansBefore = [];
foreach ($accountCols as [$table, $col]) {
    if (isset($KEEP[$table]) || $table === 'mail_queue') continue;   // the columns step 3 judges
    // (a member's vote is keyed by the id's digits: compared as a number, as a string against an id cast to one would
    // meet two collations)
    $q = $col === 'voter_key'
        ? "SELECT voter_key, COUNT(*) FROM `$table` WHERE voter_type = 'user' AND CAST(voter_key AS UNSIGNED) NOT IN (SELECT id FROM users) GROUP BY voter_key"
        : "SELECT `$col`, COUNT(*) FROM `$table` WHERE `$col` IS NOT NULL AND `$col` NOT IN (SELECT id FROM users) GROUP BY `$col`";
    foreach ($db->query($q)->fetchAll(PDO::FETCH_NUM) as [$v, $c]) $orphansBefore["$table.$col"][(string)$v] = (int)$c;
}

$mkUser = function (string $name) use ($db, $cfg): int {
    $r = userCreate($db, $cfg, $name, $name . '@example.org', 'DelPass123!', '127.0.0.1');
    return (int)($r['user']['id'] ?? 0);
};
$A = $mkUser('deltest_a'); $B = $mkUser('deltest_b'); $C = $mkUser('deltest_c');
check('three fresh accounts', $A > 0 && $B > 0 && $C > 0, "$A $B $C");
// …and what of that was under deltest_a's id: an earlier account's rows, not this one's (step 3 leaves them out).
$heldBefore = [];
foreach ($orphansBefore as $k => $byId) if (!empty($byId[(string)$A])) $heldBefore[$k] = $byId[(string)$A];

// ── 1. a row in each table the cascade had missed, and the votes' stored totals ─────────────────────
$db->prepare("INSERT INTO user_twofa (user_id, secret, enabled, recovery) VALUES (?, 'DELTESTDELTESTDE', 1, '[]')")->execute([$A]);
$db->prepare("INSERT INTO message_threads (u_low, u_high) VALUES (?, ?)")->execute([min($A, $B), max($A, $B)]);
$thread = (int)$db->lastInsertId();
$db->prepare("INSERT INTO message_typing (thread_id, user_id, until) VALUES (?, ?, NOW() + INTERVAL 1 MINUTE), (?, ?, NOW() + INTERVAL 1 MINUTE)")
   ->execute([$thread, $A, $thread, $B]);
$db->prepare("INSERT INTO mail_queue (batch_id, user_id, email, subject, body) VALUES ('deltest0000test0', ?, 'deltest_a@example.org', 's', 'b')")
   ->execute([$A]);
$mailId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO whitelist (info_hash, name, source, banned) VALUES (?, 'Delete test', 'admin', 0)")->execute([$H1]);
$vote = $db->prepare("INSERT INTO hash_votes (info_hash, voter_type, voter_key, vote, weight) VALUES (?, 'user', ?, ?, 100)");
$vote->execute([$H1, (string)$A, 1]);
$vote->execute([$H1, (string)$C, -1]);
repRecount($db, $H1, $cfg);
$tot = fn(string $h) => $db->query("SELECT votes_up, votes_down, votes_count FROM whitelist WHERE info_hash = " . $db->quote($h))->fetch(PDO::FETCH_ASSOC);
check('before: the stored totals count both votes', ($tot($H1)['votes_count'] ?? null) == 2, json_encode($tot($H1)));

$gone = userDeleteCascade($db, $A, $cfg);
$one = function (string $sql, array $args) use ($db) { $st = $db->prepare($sql); $st->execute($args); return $st->fetchColumn(); };
check('the account is gone', $one("SELECT COUNT(*) FROM users WHERE id = ?", [$A]) == 0);
check('its second factor is gone (the TOTP secret, the recovery hashes)', $one("SELECT COUNT(*) FROM user_twofa WHERE user_id = ?", [$A]) == 0,
      json_encode($gone));
check('… and the cascade says so', ($gone['second_factor'] ?? 0) === 1, json_encode($gone));
check('the "…is typing" rows of its conversation are gone, both sides', $one("SELECT COUNT(*) FROM message_typing WHERE thread_id = ?", [$thread]) == 0);
check('the conversation itself is gone (as before)', $one("SELECT COUNT(*) FROM message_threads WHERE id = ?", [$thread]) == 0);
$mq = $db->query("SELECT status, last_error FROM mail_queue WHERE id = $mailId")->fetch(PDO::FETCH_ASSOC);
check('a bulk mail still queued for it is skipped, never sent', ($mq['status'] ?? '') === 'skipped' && ($mq['last_error'] ?? '') === 'account deleted',
      json_encode($mq));
check('its vote is gone', $one("SELECT COUNT(*) FROM hash_votes WHERE voter_type = 'user' AND voter_key = ?", [(string)$A]) == 0);
$t = $tot($H1);
check('… and the stored totals are counted again: only the other vote is left', (int)($t['votes_count'] ?? -1) === 1
      && (int)$t['votes_up'] === 0 && (int)$t['votes_down'] === 1, json_encode($t));
check('the other side of the conversation is untouched', $one("SELECT COUNT(*) FROM users WHERE id = ?", [$B]) == 1);

// ── 2. the registry: every account column is the cascade's business or kept on purpose ──────────────
$fnSrc = function (string $file, string $fn) use ($root): string {
    $s = (string)@file_get_contents($root . '/' . $file);
    return preg_match('/function ' . preg_quote($fn, '/') . '\b.*?\n}\n/s', $s, $m) ? $m[0] : '';
};
$covered = $fnSrc('includes/users.php', 'userDeleteCascade') . $fnSrc('includes/content.php', 'contentForgetAccount')
         . $fnSrc('includes/comments.php', 'commentForgetAccount');
check('the cascade\'s code was read', strlen($covered) > 2000, (string)strlen($covered));
// $KEEP and $accountCols: read before the accounts were made (the top of the file).
check('the schema has account columns to check', count($accountCols) > 25, (string)count($accountCols));
$unknown = [];
foreach ($accountCols as [$table, $col]) {
    if (isset($KEEP[$table])) continue;
    if (!preg_match('/\b' . preg_quote($table, '/') . '\b/', $covered)) $unknown[] = "$table.$col";
}
check('every table that names an account is in the cascade or kept on purpose', $unknown === [], implode(', ', $unknown));
check('… user_twofa included (missed until 1.73.0)', str_contains($covered, 'user_twofa'));

// ── 3. nothing left that names the deleted account, but what is kept on purpose ─────────────────────
// What this run put there: the rows under the id now, less what was under it before the account was made.
$left = [];
foreach ($accountCols as [$table, $col]) {
    if (isset($KEEP[$table])) continue;
    if ($table === 'mail_queue') continue;   // the skipped row stays as history, checked above
    if ($col === 'voter_key') {
        $c = $one("SELECT COUNT(*) FROM `$table` WHERE voter_type = 'user' AND voter_key = ?", [(string)$A]);
    } else {
        $c = $one("SELECT COUNT(*) FROM `$table` WHERE `$col` = ?", [$A]);
    }
    $c = (int)$c - ($heldBefore["$table.$col"] ?? 0);
    if ($c > 0) $left[] = "$table.$col=$c";
}
check('no table holds the deleted account\'s id any more (but the kept ones)', $left === [], implode(', ', $left));
if ($heldBefore) {
    echo '     (left out: rows under id ' . $A . ' from before it was given to deltest_a — '
       . implode(', ', array_map(static fn($k, $n) => "$k=$n", array_keys($heldBefore), $heldBefore)) . ")\n";
}
check('the anti-spam layer\'s row (keyed "u:<id>") is gone too', $one("SELECT COUNT(*) FROM antispam_state WHERE subject = ?", ['u:' . $A]) == 0);

// ── 4. the v91 step: what a deletion left behind before the cascade knew the table ──────────────────
$X = (int)$db->query("SELECT COALESCE(MAX(id), 0) + 100000 FROM users")->fetchColumn();   // an id nobody has
$LIVE = $mkUser('deltest_live');
$db->prepare("INSERT INTO user_twofa (user_id, secret, enabled, recovery) VALUES (?, 'DELTESTDELTESTDE', 1, '[]'), (?, 'DELTESTDELTESTDE', 1, '[]')")
   ->execute([$X, $LIVE]);
$db->prepare("INSERT INTO message_typing (thread_id, user_id, until) VALUES (4294967000, ?, NOW() + INTERVAL 1 MINUTE), (4294967000, ?, NOW() + INTERVAL 1 MINUTE)")
   ->execute([$X, $LIVE]);
$db->prepare("INSERT INTO mail_queue (batch_id, user_id, email, subject, body) VALUES ('deltest0000test0', ?, 'x@example.org', 's', 'b'), ('deltest0000test0', ?, 'live@example.org', 's', 'b')")
   ->execute([$X, $LIVE]);
$db->prepare("INSERT INTO whitelist (info_hash, name, source, banned) VALUES (?, 'Orphan vote test', 'admin', 0)")->execute([$H2]);
$vote->execute([$H2, (string)$X, 1]);
$vote->execute([$H2, (string)$LIVE, 1]);
repRecount($db, $H2, $cfg);
check('before: the orphan vote counts in the stored totals', (int)($tot($H2)['votes_count'] ?? 0) === 2, json_encode($tot($H2)));
$r = schemaAccountOrphans($db, $cfg);
check('the orphan second factor is gone', $one("SELECT COUNT(*) FROM user_twofa WHERE user_id = ?", [$X]) == 0, json_encode($r));
check('… and a live account\'s is kept', $one("SELECT COUNT(*) FROM user_twofa WHERE user_id = ?", [$LIVE]) == 1);
check('the orphan "…is typing" row is gone, a live one kept',
      $one("SELECT COUNT(*) FROM message_typing WHERE thread_id = 4294967000 AND user_id = ?", [$X]) == 0
      && $one("SELECT COUNT(*) FROM message_typing WHERE thread_id = 4294967000 AND user_id = ?", [$LIVE]) == 1);
check('a mail queued for a gone account is skipped, a live one\'s still queued',
      $one("SELECT status FROM mail_queue WHERE batch_id = 'deltest0000test0' AND user_id = ?", [$X]) === 'skipped'
      && $one("SELECT status FROM mail_queue WHERE batch_id = 'deltest0000test0' AND user_id = ?", [$LIVE]) === 'queued');
check('the orphan vote is gone and the live one kept',
      $one("SELECT COUNT(*) FROM hash_votes WHERE info_hash = ? AND voter_key = ?", [$H2, (string)$X]) == 0
      && $one("SELECT COUNT(*) FROM hash_votes WHERE info_hash = ? AND voter_key = ?", [$H2, (string)$LIVE]) == 1);
check('… and the stored totals counted again', (int)($tot($H2)['votes_count'] ?? 0) === 1, json_encode($tot($H2)));
check('the step reports what it did', ($r['user_twofa'] ?? 0) >= 1 && ($r['hash_votes'] ?? 0) >= 1 && ($r['recounted'] ?? 0) >= 1, json_encode($r));
$r2 = schemaAccountOrphans($db, $cfg);
check('met twice, it finds nothing more to do', ($r2['user_twofa'] + $r2['message_typing'] + $r2['mail_queue'] + $r2['hash_votes']) === 0, json_encode($r2));
$schemaSrc = (string)@file_get_contents($root . '/includes/schema.php');
check('the migration runs it once, under its marker', str_contains($schemaSrc, "schemaOnce(\$db, 'v91_account_orphans')")
      && str_contains($schemaSrc, 'schemaAccountOrphans($db, $cfg);'));

$cleanup();
$st = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
$leftUsers = 0;
foreach ($NAMES as $nm) { $st->execute([$nm]); $leftUsers += (int)$st->fetchColumn(); }
check('the test accounts are gone again', $leftUsers === 0);

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
