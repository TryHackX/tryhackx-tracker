<?php
/**
 * The janitor's digest: "these things are waiting for a person."
 *
 * ── the problem this solves ────────────────────────────────────────────────────────────────────
 * Everything an operator has to DECIDE — a partner's submission held for review, a description
 * waiting to be read, an abuse report nobody has opened, a message somebody reported — waits
 * silently in the panel. The tracker runs itself; the queues do not. An operator who does not think
 * to look finds out weeks later, and the partner on the other end has been waiting all that time
 * for an answer that nobody knew was owed.
 *
 * So: one mail, on a schedule the operator sets, and only when there is something in it. It counts,
 * it does not act — nothing here approves, deletes or changes a queue.
 *
 * ── the shape of the schedule ──────────────────────────────────────────────────────────────────
 * `digest_hours` is a FLOOR BETWEEN MAILS, not an alarm clock. When nothing is waiting the clock is
 * not reset, so the first thing to arrive after a quiet week is reported at once rather than at the
 * end of an interval that started while there was nothing to say. `digest_min` is the other half of
 * that: an operator who does not want to hear about one held submission can ask for five.
 *
 * Settings (all seeded by includes/schema.php, all off by default):
 *   digest_enabled   '0'|'1'
 *   digest_to        where it goes; empty = the site contact address
 *   digest_hours     minimum hours between mails (1–168)
 *   digest_min       do not send while fewer than this many things are waiting (0–10000)
 *   digest_last_at   state, written here: the unix time of the last mail that actually went out
 *
 * Called from tools/janitor.php once a minute. Costs four COUNT(*)s on indexed columns when it is
 * due, and one settings read when it is not.
 */

function digestEnabled(array $cfg): bool { return (($cfg['digest_enabled'] ?? '0') === '1'); }
function digestHours(array $cfg): int    { return max(1, min(168, (int)($cfg['digest_hours'] ?? 24) ?: 24)); }
function digestMin(array $cfg): int      { return max(0, min(10000, (int)($cfg['digest_min'] ?? 1))); }

/**
 * Where it goes: the operator's own address when they gave one, otherwise the site contact.
 *
 * A `digest_to` that is set but not an address returns '' rather than falling through to the
 * contact — the operator typed a destination, and quietly sending their queue summary somewhere
 * else is worse than not sending it. The janitor prints 'no_address' and the mistake is visible.
 */
function digestAddress(array $cfg): string
{
    $clean = static fn(string $a): string => str_replace([chr(13), chr(10)], '', trim($a));
    $own = $clean((string)($cfg['digest_to'] ?? ''));
    if ($own !== '') return filter_var($own, FILTER_VALIDATE_EMAIL) ? $own : '';
    $site = $clean((string)($cfg['site_email'] ?? ''));
    return filter_var($site, FILTER_VALIDATE_EMAIL) ? $site : '';
}

/**
 * What is waiting, one COUNT per queue.
 *
 * Each is wrapped on its own: a table that does not exist on this install (an older schema, a
 * feature never migrated) must cost that ONE number, not the whole digest. A queue that cannot be
 * counted is reported as 0 rather than as a crash in a timer that also switches the tracker's mode.
 */
function digestCounts(PDO $db): array
{
    $one = static function (PDO $db, string $sql): int {
        try { return (int)$db->query($sql)->fetchColumn(); } catch (\Throwable $e) { return 0; }
    };
    return [
        'partners'     => $one($db, "SELECT COUNT(*) FROM whitelist WHERE review_status = 'pending'"),
        'descriptions' => $one($db, "SELECT COUNT(*) FROM whitelist WHERE content_status = 'pending'"),
        'abuse'        => $one($db, "SELECT COUNT(*) FROM reports WHERE checked = 0"),
        'messages'     => $one($db, "SELECT COUNT(*) FROM message_reports WHERE status = 'open'"),
    ];
}

/** Has the floor between mails passed? */
function digestDue(array $cfg, ?int $now = null): bool
{
    $now = $now ?? time();
    $last = (int)($cfg['digest_last_at'] ?? 0);
    return $last <= 0 || ($now - $last) >= digestHours($cfg) * 3600;
}

/**
 * The lines of the mail, as [label, count] pairs — only the queues that have something in them.
 * Separate from the sending so tests can read what would be said without a mail server.
 */
function digestLines(array $counts): array
{
    $labels = [
        'partners'     => 'digest.line_partners',
        'descriptions' => 'digest.line_descriptions',
        'abuse'        => 'digest.line_abuse',
        'messages'     => 'digest.line_messages',
    ];
    $out = [];
    foreach ($labels as $k => $key) {
        if ((int)($counts[$k] ?? 0) > 0) $out[$k] = ['key' => $key, 'n' => (int)$counts[$k]];
    }
    return $out;
}

/**
 * One tick. Returns what it decided, so the janitor can print a line and a test can assert on it
 * without reading a mailbox: ['enabled','due','total','sent','skipped','counts'].
 *
 * `skipped` names the reason nothing went out — 'not_due', 'below_threshold', 'no_address',
 * 'send_failed' — because "no mail arrived" has four different causes and three of them are an
 * operator's setting rather than a fault.
 */
function digestTick(PDO $db, array $cfg, ?int $now = null): array
{
    $now = $now ?? time();
    $res = ['enabled' => digestEnabled($cfg), 'due' => false, 'total' => 0,
            'sent' => false, 'skipped' => null, 'counts' => []];
    if (!$res['enabled']) return $res;
    if (!digestDue($cfg, $now)) { $res['skipped'] = 'not_due'; return $res; }
    $res['due'] = true;

    $counts = digestCounts($db);
    $res['counts'] = $counts;
    $res['total'] = array_sum($counts);
    // Nothing waiting, or not enough of it. The clock is deliberately NOT reset — see the header.
    if ($res['total'] < max(1, digestMin($cfg))) { $res['skipped'] = 'below_threshold'; return $res; }

    $to = digestAddress($cfg);
    if ($to === '') { $res['skipped'] = 'no_address'; return $res; }

    $site    = (string)($cfg['site_name'] ?? 'Tracker');
    $subject = __('digest.subject', ['site' => $site, 'n' => $res['total']]);
    $panel   = function_exists('mailAbsoluteUrl') ? mailAbsoluteUrl($cfg, '?action=admin') : '';

    $lines = digestLines($counts);
    $plain = __('digest.intro', ['site' => $site]) . "\n\n";
    $html  = '';
    foreach ($lines as $l) {
        $plain .= '  * ' . __($l['key'], ['n' => $l['n']]) . "\n";
        $html  .= '<li style="margin:4px 0;">' . sanitize(__($l['key'], ['n' => $l['n']])) . '</li>';
    }
    $plain .= "\n" . __('digest.outro') . ($panel !== '' ? "\n" . $panel : '');

    $body = '<p>' . sanitize(__('digest.intro', ['site' => $site])) . '</p><ul style="padding-left:18px;">'
          . $html . '</ul><p>' . sanitize(__('digest.outro')) . '</p>';

    $sent = false;
    if (function_exists('sendEmail')) {
        $htmlBody = function_exists('buildEmailHtml')
            ? buildEmailHtml(['title' => $subject, 'greeting' => '', 'body' => $body,
                              'action_url' => $panel, 'action_label' => __('digest.open_panel'),
                              'details' => [], 'unsubscribe_url' => ''], $cfg)
            : $body;
        try { $sent = (bool)@sendEmail($to, $subject, $plain, $htmlBody, $cfg); } catch (\Throwable $e) { $sent = false; }
    }
    if (!$sent) { $res['skipped'] = 'send_failed'; return $res; }

    // Logged where every other mail this site sends is logged. report_id 0 because this one is not
    // about a report — the column predates any mail that is not.
    try {
        $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (0, ?, ?, ?, NULL)")
           ->execute([$to, mb_substr($subject, 0, 255), $plain]);
    } catch (\Throwable $e) { /* the mail went; the log is not worth failing the tick over */ }

    setSettings($db, ['digest_last_at' => (string)$now]);
    $res['sent'] = true;
    return $res;
}
