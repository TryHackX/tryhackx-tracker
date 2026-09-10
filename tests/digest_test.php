<?php
/**
 * The operator's digest: what it counts, when it sends, and — as much as the rest — when it does NOT
 * (needs the local test database):
 *   php tests/digest_test.php
 *
 * ── why the mail is stubbed rather than sent ───────────────────────────────────────────────────
 * includes/mail.php is deliberately NOT loaded here. digest.php asks `function_exists('sendEmail')`
 * before it sends, so this file can define its own and capture what would have gone out — which is
 * the only way to assert on the words in the mail without a mail server, and which also exercises
 * the degradation path: a build where buildEmailHtml() is absent must still send a plain message
 * rather than fail.
 *
 * ── the fact this file is really guarding ──────────────────────────────────────────────────────
 * `digest_hours` is a FLOOR BETWEEN MAILS, not an alarm clock: a tick that finds nothing waiting
 * must NOT stamp the clock, or the first thing to arrive after a quiet week would wait another whole
 * interval before anybody heard about it. That is one line in digestTick() and it is the line most
 * likely to be "tidied" into resetting the clock every tick.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }

/** The seam. Defined BEFORE digest.php is loaded, so it is the one the tick finds. */
$GLOBALS['sent_mail'] = [];
function sendEmail(string $to, string $subject, string $plainText, string $htmlBody, array $cfg, string $unsubscribeUrl = ''): bool {
    $GLOBALS['sent_mail'][] = ['to' => $to, 'subject' => $subject, 'plain' => $plainText];
    return $GLOBALS['mail_works'] ?? true;
}

require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/digest.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$db = getDb(); $cfg = getSettings($db); ensureSchema($db, $cfg);
$cfg = getSettings($db, true);

// ── 1. the helpers ────────────────────────────────────────────────────────────
check('off unless switched on', !digestEnabled([]) && !digestEnabled(['digest_enabled' => '0'])
    && digestEnabled(['digest_enabled' => '1']));
check('hours clamp to [1, 168], default 24',
    digestHours([]) === 24 && digestHours(['digest_hours' => '0']) === 24
    && digestHours(['digest_hours' => '9999']) === 168 && digestHours(['digest_hours' => '6']) === 6);
check('the threshold clamps but keeps 0 — it means the same as 1 to the tick',
    digestMin([]) === 1 && digestMin(['digest_min' => '0']) === 0
    && digestMin(['digest_min' => '99999']) === 10000);

check('the address is the operator\'s own when they gave one',
    digestAddress(['digest_to' => 'ops@example.org', 'site_email' => 'hello@example.org']) === 'ops@example.org');
check('… the site contact when they did not',
    digestAddress(['digest_to' => '', 'site_email' => 'hello@example.org']) === 'hello@example.org');
// The one that matters: a typo must not silently redirect the operator's queue summary elsewhere.
check('… and NOTHING when what they typed is not an address',
    digestAddress(['digest_to' => 'ops at example dot org', 'site_email' => 'hello@example.org']) === '');
check('… nor a header injected through it',
    digestAddress(['digest_to' => "ops@example.org\nBcc: somebody@else"]) === '');

$now = 1_800_000_000;
check('due when it has never run', digestDue(['digest_last_at' => '0'], $now));
check('not due an hour after a daily one',
    !digestDue(['digest_last_at' => (string)($now - 3600), 'digest_hours' => '24'], $now));
check('due again once the interval has passed',
    digestDue(['digest_last_at' => (string)($now - 25 * 3600), 'digest_hours' => '24'], $now));

// ── 2. the counts, against real rows ─────────────────────────────────────────
foreach (['whitelist', 'reports', 'message_reports', 'user_messages', 'message_threads'] as $t) {
    try { $db->exec("DELETE FROM `$t` WHERE 1"); } catch (\Throwable $e) { /* not on this schema */ }
}
$db->exec("INSERT INTO whitelist (info_hash, name, review_status) VALUES ('" . str_repeat('a1', 20) . "', 'held', 'pending')");
$db->exec("INSERT INTO whitelist (info_hash, name, review_status) VALUES ('" . str_repeat('a2', 20) . "', 'live', 'approved')");
$db->exec("INSERT INTO whitelist (info_hash, name, content_status) VALUES ('" . str_repeat('b1', 20) . "', 'desc', 'pending')");
$db->exec("INSERT INTO reports (name, representative, company, email, objectTitle, link, infoHash, ip, checked)
           VALUES ('A', 'B', 'C', 'a@example.org', 'T', 'https://x', '" . str_repeat('c1', 20) . "', '127.0.0.1', 0)");
$db->exec("INSERT INTO reports (name, representative, company, email, objectTitle, link, infoHash, ip, checked)
           VALUES ('A', 'B', 'C', 'a@example.org', 'T', 'https://x', '" . str_repeat('c2', 20) . "', '127.0.0.1', 1)");
$db->exec("INSERT INTO message_threads (id, u_low, u_high) VALUES (5001, 1, 2)");
$db->exec("INSERT INTO user_messages (id, thread_id, sender_id, body, body_format) VALUES (7001, 5001, 1, 'x', 'bbcode')");
$db->exec("INSERT INTO message_reports (message_id, thread_id, reporter_id, reported_user_id, reason, status)
           VALUES (7001, 5001, 2, 1, 'why', 'open')");

$counts = digestCounts($db);
check('it counts partner submissions that are waiting, and not the ones that are not',
    $counts['partners'] === 1, json_encode($counts));
check('… descriptions waiting to be read', $counts['descriptions'] === 1, json_encode($counts));
check('… abuse reports nobody has opened, and not the opened one', $counts['abuse'] === 1, json_encode($counts));
check('… and reported messages that are still open', $counts['messages'] === 1, json_encode($counts));

$lines = digestLines(['partners' => 2, 'descriptions' => 0, 'abuse' => 0, 'messages' => 3]);
check('the mail names only the queues that have something in them',
    array_keys($lines) === ['partners', 'messages'], json_encode(array_keys($lines)));

// ── 3. the tick ──────────────────────────────────────────────────────────────
$base = ['site_name' => 'Test Tracker', 'site_email' => 'ops@example.org', 'digest_last_at' => '0'];
$GLOBALS['sent_mail'] = [];

$r = digestTick($db, $base + ['digest_enabled' => '0'], $now);
check('switched off, it does nothing at all', !$r['enabled'] && !$r['sent'] && $GLOBALS['sent_mail'] === []);

// Threshold above what is waiting: no mail, and — the point of this file — no stamp on the clock.
setSettings($db, ['digest_last_at' => '0']);
$r = digestTick($db, $base + ['digest_enabled' => '1', 'digest_min' => '99'], $now);
check('under the threshold, nothing is sent', $r['skipped'] === 'below_threshold' && !$r['sent'], json_encode($r));
check('… and the clock is NOT stamped, so the next thing to arrive is reported at once',
    (string)(getSettings($db, true)['digest_last_at'] ?? '') === '0');

$r = digestTick($db, $base + ['digest_enabled' => '1', 'digest_to' => 'not an address'], $now);
check('a destination that is not an address stops it, and says so', $r['skipped'] === 'no_address' && !$r['sent'], json_encode($r));

$GLOBALS['mail_works'] = false;
$r = digestTick($db, $base + ['digest_enabled' => '1'], $now);
check('a mail that could not be sent is reported as such', $r['skipped'] === 'send_failed' && !$r['sent'], json_encode($r));
check('… and it does not stamp the clock either, so the next tick tries again',
    (string)(getSettings($db, true)['digest_last_at'] ?? '') === '0');

$GLOBALS['mail_works'] = true;
$GLOBALS['sent_mail'] = [];
$before = (int)$db->query("SELECT COUNT(*) FROM sent_emails")->fetchColumn();
$r = digestTick($db, $base + ['digest_enabled' => '1'], $now);
check('with something waiting and somewhere to send it, it sends', $r['sent'] && $r['total'] === 4, json_encode($r));
check('… to the address that was configured', ($GLOBALS['sent_mail'][0]['to'] ?? '') === 'ops@example.org');
check('… with the site and the number in the subject',
    str_contains($GLOBALS['sent_mail'][0]['subject'] ?? '', 'Test Tracker')
    && str_contains($GLOBALS['sent_mail'][0]['subject'] ?? '', '4'), $GLOBALS['sent_mail'][0]['subject'] ?? '');
$plain = $GLOBALS['sent_mail'][0]['plain'] ?? '';
check('… and every waiting queue named in the body', substr_count($plain, '  * ') === 4, $plain);
check('it is logged where every other mail this site sends is logged',
    (int)$db->query("SELECT COUNT(*) FROM sent_emails")->fetchColumn() === $before + 1);
check('and the clock is stamped by the mail that actually went out',
    (int)(getSettings($db, true)['digest_last_at'] ?? 0) === $now);

// Immediately again: the floor holds.
$cfgNow = getSettings($db, true);
$r = digestTick($db, array_merge($base, $cfgNow, ['digest_enabled' => '1']), $now + 60);
check('a minute later it is not due, and costs no counting', $r['skipped'] === 'not_due' && $r['counts'] === [], json_encode($r));
$r = digestTick($db, array_merge($base, $cfgNow, ['digest_enabled' => '1']), $now + 25 * 3600);
check('a day later it is due again', $r['sent'], json_encode($r));

// ── tidy up ──────────────────────────────────────────────────────────────────
setSettings($db, ['digest_last_at' => '0']);
$db->exec("DELETE FROM sent_emails WHERE report_id = 0");
foreach (['whitelist', 'reports', 'message_reports', 'user_messages', 'message_threads'] as $t) {
    try { $db->exec("DELETE FROM `$t` WHERE 1"); } catch (\Throwable $e) { /* not on this schema */ }
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
