<?php
/**
 * Writing to everybody at once — mail and in-app notifications.
 *
 * ── why there is a queue at all ─────────────────────────────────────────────
 *
 * This server sends through PHP's `mail()` with no relay in front of it. Handing that fifty messages
 * inside one web request means fifty synchronous SMTP conversations while a php-fpm child (there are
 * five) sits blocked, and it means fifty messages leaving in one burst from a domain that normally
 * sends a handful a day. The first costs the panel; the second costs the domain's reputation, and
 * the bill for that is paid by the password-reset mail that somebody actually needs.
 *
 * So the panel only ever WRITES ROWS. The janitor sends them, a few per minute, and stops for the
 * night if the server starts refusing. Same shape as the sysctl and backup work: the web request
 * records an intention, a CLI job carries it out.
 *
 * ── who gets it ────────────────────────────────────────────────────────────
 *
 * Three audiences: a list of ids the admin ticked, one group, or everyone. Whichever it is, the
 * recipient list is resolved ONCE, at queue time, into concrete rows. Resolving it at send time
 * would mean a group edited halfway through a send silently changes who the rest of the message
 * goes to, which is not a thing anybody would ever want to explain afterwards.
 *
 * Three kinds of person are dropped from every audience, and the panel says how many before the
 * admin commits: no email address, opted out of bulk mail, or unsubscribed at the address level.
 * Transactional mail — password resets, verification — does not come through here and is unaffected.
 */

/** Bulk mail is off until somebody turns it on, like everything else that reaches the outside. */
function bulkMailEnabled(array $cfg): bool { return ($cfg['bulk_mail_enabled'] ?? '0') === '1'; }

/** Messages per janitor tick. The janitor runs every minute, so this is per minute in practice. */
function bulkMailPerTick(array $cfg): int {
    return max(1, min(500, (int)($cfg['bulk_mail_per_minute'] ?? 20) ?: 20));
}

function bulkMailMaxAttempts(array $cfg): int {
    return max(1, min(10, (int)($cfg['bulk_mail_max_attempts'] ?? 3) ?: 3));
}

/**
 * Resolve an audience to user rows.
 *
 * $spec: ['mode' => 'selected'|'group'|'all', 'ids' => [int], 'group_id' => int]
 * Returns rows with id, username, email, bulk_optout.
 */
function bulkAudience(PDO $db, array $spec): array {
    $mode = (string)($spec['mode'] ?? '');
    if ($mode === 'selected') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($spec['ids'] ?? [])))));
        if (!$ids) return [];
        // Bounded: an admin ticking boxes cannot produce more than a page of them, and an id list
        // long enough to matter is a sign the request did not come from the page.
        $ids = array_slice($ids, 0, 5000);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $db->prepare("SELECT id, username, email, bulk_optout FROM users WHERE id IN ($in) ORDER BY id");
        $st->execute($ids);
        return $st->fetchAll();
    }
    if ($mode === 'group') {
        $gid = (int)($spec['group_id'] ?? 0);
        if ($gid < 1) return [];
        // Expired memberships are not memberships. A group whose access ran out last week is not an
        // audience, and mailing it would be mailing people who were told they no longer have access.
        $st = $db->prepare(
            "SELECT u.id, u.username, u.email, u.bulk_optout
               FROM users u
               JOIN user_group_members m ON m.user_id = u.id
              WHERE m.group_id = ? AND (m.expires_at IS NULL OR m.expires_at > NOW())
              ORDER BY u.id");
        $st->execute([$gid]);
        return $st->fetchAll();
    }
    if ($mode === 'all') {
        return $db->query("SELECT id, username, email, bulk_optout FROM users ORDER BY id")->fetchAll();
    }
    return [];
}

/**
 * Who would actually receive this, and who would not, and why.
 *
 * The panel shows this before anything is queued. "Send to everyone" that quietly means 41 of 53 is
 * the kind of surprise that gets discovered a week later when somebody asks why they never heard.
 */
function bulkPreview(PDO $db, array $cfg, array $spec): array {
    $rows = bulkAudience($db, $spec);
    $out = ['audience' => count($rows), 'no_email' => 0, 'opted_out' => 0, 'unsubscribed' => 0,
            'recipients' => 0, 'sample' => []];
    foreach ($rows as $r) {
        $email = trim((string)($r['email'] ?? ''));
        if ($email === '') { $out['no_email']++; continue; }
        if ((int)($r['bulk_optout'] ?? 0) === 1) { $out['opted_out']++; continue; }
        if (function_exists('isUnsubscribed') && isUnsubscribed($db, $email, 'bulk')) { $out['unsubscribed']++; continue; }
        $out['recipients']++;
        if (count($out['sample']) < 5) $out['sample'][] = $r['username'];
    }
    return $out;
}

/** In-app notifications for an audience. No queue: this is one insert per row, in one statement. */
function bulkNotify(PDO $db, array $spec, string $title, string $body): int {
    $rows = bulkAudience($db, $spec);
    if (!$rows) return 0;
    $title = mb_substr(trim($title), 0, 190);
    if ($title === '') return 0;
    $body = mb_substr(trim($body), 0, 5000);
    $sent = 0;
    // Chunked so one enormous audience does not build a single statement megabytes long.
    foreach (array_chunk($rows, 500) as $chunk) {
        $values = [];
        $params = [];
        foreach ($chunk as $r) {
            $values[] = '(?, ?, ?, ?)';
            $params[] = (int)$r['id'];
            $params[] = 'admin';
            $params[] = $title;
            $params[] = $body !== '' ? $body : null;
        }
        $db->prepare("INSERT INTO user_notifications (user_id, type, title, body) VALUES " . implode(', ', $values))
           ->execute($params);
        $sent += count($chunk);
    }
    return $sent;
}

/** A short, readable batch id. Groups the rows of one send so it can be watched and cancelled. */
function bulkNewBatchId(): string { return bin2hex(random_bytes(8)); }

/**
 * Queue a message for an audience. Returns [batch_id, queued, skipped…].
 *
 * Nothing is sent here. The rows sit in mail_queue until the janitor picks them up.
 */
function bulkQueue(PDO $db, array $cfg, array $spec, string $subject, string $body): array {
    $subject = mb_substr(trim($subject), 0, 200);
    $body    = trim($body);
    if ($subject === '' || $body === '') {
        return ['error' => 'A subject and a message are both required.'];
    }
    $rows = bulkAudience($db, $spec);
    if (!$rows) return ['error' => 'That audience is empty — nobody would receive this.'];

    $batch = bulkNewBatchId();
    $queued = 0; $skipped = 0;
    $st = $db->prepare(
        "INSERT INTO mail_queue (batch_id, user_id, email, subject, body) VALUES (?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $email = trim((string)($r['email'] ?? ''));
        if ($email === '' || (int)($r['bulk_optout'] ?? 0) === 1) { $skipped++; continue; }
        if (function_exists('isUnsubscribed') && isUnsubscribed($db, $email, 'bulk')) { $skipped++; continue; }
        $st->execute([$batch, (int)$r['id'], $email, $subject, $body]);
        $queued++;
    }
    return ['batch_id' => $batch, 'queued' => $queued, 'skipped' => $skipped];
}

/** How a batch is getting on. */
function bulkBatchStatus(PDO $db, string $batchId): array {
    $out = ['batch_id' => $batchId, 'queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    $st = $db->prepare("SELECT status, COUNT(*) c FROM mail_queue WHERE batch_id = ? GROUP BY status");
    $st->execute([$batchId]);
    foreach ($st as $r) $out[$r['status']] = (int)$r['c'];
    $out['total'] = array_sum([$out['queued'], $out['sending'], $out['sent'], $out['failed'], $out['skipped']]);
    return $out;
}

/** Recent batches, newest first, for the panel's own history. */
function bulkRecentBatches(PDO $db, int $limit = 10): array {
    $limit = max(1, min(50, $limit));
    $sql = "SELECT batch_id, MIN(created_at) started, MAX(sent_at) finished,
                   COUNT(*) total,
                   SUM(status = 'sent') sent,
                   SUM(status = 'failed') failed,
                   SUM(status IN ('queued','sending')) pending,
                   SUBSTRING_INDEX(GROUP_CONCAT(subject), ',', 1) subject
              FROM mail_queue GROUP BY batch_id ORDER BY started DESC LIMIT $limit";
    return $db->query($sql)->fetchAll();
}

/** Stop what has not gone out yet. Already-sent rows are history and are left alone. */
function bulkCancelBatch(PDO $db, string $batchId): int {
    $st = $db->prepare("UPDATE mail_queue SET status = 'skipped', last_error = 'cancelled by admin'
                         WHERE batch_id = ? AND status = 'queued'");
    $st->execute([$batchId]);
    return $st->rowCount();
}

/**
 * The janitor's half: send a few, then stop.
 *
 * CLI only, and not by convention — by refusal. A web request that reached this would hold a php-fpm
 * child through however many SMTP conversations the rate allows, which is the exact thing the queue
 * exists to prevent.
 */
function bulkTick(PDO $db, array $cfg): array {
    $out = ['sent' => 0, 'failed' => 0, 'left' => 0];
    if (PHP_SAPI !== 'cli') return $out;
    if (!bulkMailEnabled($cfg)) return $out;
    if (!function_exists('sendEmail')) return $out;

    $limit = bulkMailPerTick($cfg);
    $maxAttempts = bulkMailMaxAttempts($cfg);

    $due = $db->prepare(
        "SELECT id, user_id, email, subject, body, attempts FROM mail_queue
          WHERE status = 'queued' AND next_attempt_at <= NOW()
          ORDER BY id LIMIT $limit");
    $due->execute();
    $rows = $due->fetchAll();

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        // Claim it first. Two janitors overlapping (a slow tick and the next one) must not both send
        // the same message: being late is a nuisance, sending twice is the thing people complain about.
        $claim = $db->prepare("UPDATE mail_queue SET status = 'sending' WHERE id = ? AND status = 'queued'");
        $claim->execute([$id]);
        if ($claim->rowCount() !== 1) continue;

        $ok = false;
        $err = '';
        try {
            $unsub = function_exists('getUnsubscribeUrl') ? getUnsubscribeUrl((string)$r['email'], $cfg) : '';
            $html = function_exists('buildEmailHtml')
                ? buildEmailHtml([
                    'title'    => (string)$r['subject'],
                    'greeting' => '',
                    'body'     => nl2br(sanitize((string)$r['body'])),
                    'unsubscribe_url' => $unsub,
                  ], $cfg)
                : nl2br(sanitize((string)$r['body']));
            $ok = sendEmail((string)$r['email'], (string)$r['subject'], (string)$r['body'], $html, $cfg, $unsub);
        } catch (\Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 255);
        }

        if ($ok) {
            $db->prepare("UPDATE mail_queue SET status = 'sent', sent_at = NOW(), last_error = '' WHERE id = ?")
               ->execute([$id]);
            $out['sent']++;
            continue;
        }
        $attempts = (int)$r['attempts'] + 1;
        if ($attempts >= $maxAttempts) {
            $db->prepare("UPDATE mail_queue SET status = 'failed', attempts = ?, last_error = ? WHERE id = ?")
               ->execute([$attempts, $err !== '' ? $err : 'the mailer refused it', $id]);
            $out['failed']++;
        } else {
            // Back off rather than hammering: if the mailer is refusing, retrying immediately is how
            // a temporary problem turns into a reputation problem.
            $delay = 60 * (2 ** $attempts);
            $db->prepare("UPDATE mail_queue SET status = 'queued', attempts = ?, last_error = ?,
                                 next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
               ->execute([$attempts, $err !== '' ? $err : 'the mailer refused it', $delay, $id]);
        }
    }

    $out['left'] = (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'queued'")->fetchColumn();
    return $out;
}

/** Anything still waiting to go out, across all batches. */
function bulkQueueDepth(PDO $db): int {
    try { return (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE status IN ('queued','sending')")->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
}

/** Old rows are history nobody reads. Keep a fortnight so a complaint can still be traced. */
function bulkPrune(PDO $db, int $days = 14): int {
    if (PHP_SAPI !== 'cli') return 0;
    $st = $db->prepare("DELETE FROM mail_queue WHERE status IN ('sent','failed','skipped')
                          AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 5000");
    $st->execute([max(1, $days)]);
    return $st->rowCount();
}
