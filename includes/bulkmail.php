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

// The HTML sanitizer and the text half of a mail (1.75.0).
require_once __DIR__ . '/htmlsafe.php';

/**
 * The longest message body, in BYTES (1.75.0). Measured in bytes because a copy is written to `mail_queue` for every
 * recipient (a MEDIUMTEXT) and the sanitizer reads no more than this — a limit in characters would have let a
 * message in Cyrillic or Polish be twice what the sanitizer and the composer counted. The composer's textarea says
 * the same number and the server holds it (bulkQueue(), bulkNotify()): before this `bulkQueue()` had no cap at all.
 */
const BULK_BODY_MAX_BYTES = 60000;

/**
 * The largest HTML DOCUMENT a message may come out as, in bytes (1.75.0) — the frame, the body AFTER it was rendered or
 * cleaned, the footer. The cap above is on what was typed, and a markup grows when it is written out (inline styles, one
 * <td> per cell: 59 KB of `<p><b>` became 344 KB). Gmail shows no more than ~102 KB of a message's HTML and hides the rest
 * behind "View entire message" — the footer with the unsubscribe link is the LAST thing in the document, so a clipped
 * message would hide exactly what the law asks every message to show. Under it with room for the subject and the address.
 */
const BULK_DOC_MAX_BYTES = 95000;

/** Bulk mail is off until somebody turns it on, like everything else that reaches the outside. */
function bulkMailEnabled(array $cfg): bool { return ($cfg['bulk_mail_enabled'] ?? '0') === '1'; }

/**
 * Messages the janitor may send in a day (1.75.0, `bulk_mail_daily_cap`): 0 = no cap, which is what it was. A
 * ceiling for a domain whose reputation is the thing at stake (see the head of this file): the per-minute rate keeps
 * a burst small, this keeps the day small. "A day" is the database's: today since its midnight.
 */
function bulkMailDailyCap(array $cfg): int {
    return max(0, min(1000000, (int)($cfg['bulk_mail_daily_cap'] ?? 0)));
}

/**
 * The formats the composer offers and the endpoint accepts: plain, the two the site has switched on, and — where the
 * server can parse HTML (ext-dom: htmlSafeAvailable()) — html.
 */
function bulkFormats(array $cfg): array {
    $f = array_merge(['plain'], function_exists('richtextFormats') ? richtextFormats($cfg, 'mail') : []);
    if (htmlSafeAvailable()) $f[] = 'html';
    return $f;
}

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

/**
 * In-app notifications for an audience. No queue: this is one insert per row, in one statement.
 *
 * $format (1.75.0): plain | bbcode | markdown | html. Plain is what it always was — the text in `body`, cut at 5 000
 * characters, shown as text. Anything else is kept as its SOURCE in `rich` with its `rich_format` (bytes, not
 * characters: BULK_BODY_MAX_BYTES; a cut at 5 000 characters used to land in the middle of a tag) and `body` stays
 * empty — the account page draws a rich notification from `rich` (api/user_notifications.php: our renderer for the
 * two markups, the sanitizer inside a frame with no script for HTML), so there is no second copy of the words to
 * keep in step with the first, and a renderer fixed later reaches the notifications already sent.
 */
function bulkNotify(PDO $db, array $spec, string $title, string $body, string $format = 'plain'): int {
    $rows = bulkAudience($db, $spec);
    if (!$rows) return 0;
    $title = mb_substr(trim($title), 0, 190);
    if ($title === '') return 0;
    $rich = in_array($format, ['bbcode', 'markdown', 'html'], true);
    if ($rich) {
        $body = trim($body);
        if (strlen($body) > BULK_BODY_MAX_BYTES) $body = rtrim(mb_strcut($body, 0, BULK_BODY_MAX_BYTES, 'UTF-8'));
    } else {
        $body = mb_substr(trim($body), 0, 5000);
    }
    $sent = 0;
    // Chunked so one enormous audience does not build a single statement megabytes long.
    foreach (array_chunk($rows, 500) as $chunk) {
        $values = [];
        $params = [];
        foreach ($chunk as $r) {
            $values[] = $rich ? '(?, ?, ?, NULL, ?, ?)' : '(?, ?, ?, ?, NULL, NULL)';
            $params[] = (int)$r['id'];
            $params[] = 'admin';
            $params[] = $title;
            if ($rich) { $params[] = $body !== '' ? $body : null; $params[] = $body !== '' ? $format : null; }
            else $params[] = $body !== '' ? $body : null;
        }
        $db->prepare("INSERT INTO user_notifications (user_id, type, title, body, rich, rich_format) VALUES " . implode(', ', $values))
           ->execute($params);
        $sent += count($chunk);
    }
    return $sent;
}

/** A short, readable batch id. Groups the rows of one send so it can be watched and cancelled. */
function bulkNewBatchId(): string { return bin2hex(random_bytes(8)); }

/**
 * The HTML half of a bulk message — the body only, in a <div> (block HTML in a <p> is closed by the browser at its
 * first <div>, 1.75.0).
 *
 * 'plain' keeps what was always there: escape, then turn newlines into breaks. 'bbcode' and 'markdown' go through
 * the site's own renderer with the mail-safe styles inlined (richtextRenderForEmail()). 'html' is a campaign: the
 * administrator's HTML through the whitelist sanitizer (htmlSafeClean(), includes/htmlsafe.php). What the admin
 * previewed and what lands in the inbox come out of the same function. The text half is made from this HTML
 * (bulkMailParts()), not from the raw body.
 *
 * $report (out): htmlSafeClean()'s — how many subtrees of a campaign were nested too deeply and came out as their words.
 */
function bulkBodyHtml(string $body, string $format, array $cfg, ?array &$report = null): string {
    $report = ['flattened' => 0, 'cut' => false];
    if ($format === 'html') {
        return '<div>' . htmlSafeClean($body, 'mail', $report) . '</div>';
    }
    if ($format === 'bbcode' || $format === 'markdown') {
        if (function_exists('richtextRenderForEmail')) {
            $html = richtextRenderForEmail($body, $format, $cfg);
            if ($html !== '') return '<div>' . $html . '</div>';
        }
    }
    return '<div>' . (function_exists('sanitize') ? nl2br(sanitize($body)) : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'))) . '</div>';
}

/**
 * The whole mail of a bulk message — the one place both halves are made, for the janitor, the test copy and the
 * composer's preview alike (so what is previewed is the document that is sent, frame or none):
 *   ['html' => the document, 'text' => the text/plain half, 'inner' => the body's HTML alone, 'flattened' => the
 *    subtrees of a campaign that were nested past the sanitizer's bound and came out as their words (bulkBodyWarning())].
 *
 * $frame 'site' puts the body in the site's dark frame (buildEmailHtml()); 'none' sends it on a bare white document
 * with ONLY the footer (buildEmailHtmlBare()) — a campaign brings its own design. Either way the footer is there: the
 * preferences / unsubscribe link and the sender's identity are not the author's to leave out.
 * The text half is htmlToText() of the body's HTML for every format that is markup (a client that shows text used
 * to get the markup — `[b]`, `**`, `<table>` — raw); plain text is its own text half. Then the footer's text, which
 * carries the unsubscribe line the text half never had.
 */
function bulkMailParts(string $subject, string $body, string $format, string $frame, array $cfg, string $unsubUrl, ?string $lang = null): array {
    $inner = bulkBodyHtml($body, $format, $cfg, $report);
    // (includes/mail.php is what builds the frames; a caller that has not loaded it — a test with its own sendEmail() —
    // gets the bare body, as it always did.)
    if ($frame === 'none' && function_exists('buildEmailHtmlBare')) {
        $html = buildEmailHtmlBare($subject, $inner, $cfg, $unsubUrl, $lang);
    } elseif (function_exists('buildEmailHtml')) {
        $html = buildEmailHtml(['title' => $subject, 'greeting' => '', 'body' => $inner, 'unsubscribe_url' => $unsubUrl, 'lang' => $lang], $cfg);
    } else {
        $html = $inner;
    }
    $text = ($format === 'plain' || !in_array($format, ['bbcode', 'markdown', 'html'], true)) ? trim($body) : htmlToText($inner);
    $foot = function_exists('mailFooterText') ? mailFooterText($cfg, $lang, $unsubUrl) : '';
    return ['html' => $html, 'text' => $text . ($foot !== '' ? "\n\n--\n" . $foot : '') . "\n",
            'inner' => $inner, 'flattened' => (int)($report['flattened'] ?? 0)];
}

/**
 * Why a body cannot be queued, as a sentence in the reader's language — or null. (Formats, the byte cap, an HTML with
 * nothing in it, and — 1.75.0 — a message whose finished HTML DOCUMENT is past BULK_DOC_MAX_BYTES.)
 *
 * $frame is the one the message will go out in; $parts, when the caller has just made them (the composer's preview), is
 * bulkMailParts()'s answer so the message is not cleaned a second time. The size is judged on the document with a
 * placeholder subject and address: the real ones add a few hundred bytes the cap leaves room for.
 */
function bulkBodyProblem(string $body, string $format, array $cfg, string $frame = 'site', ?array $parts = null): ?string {
    if (strlen($body) > BULK_BODY_MAX_BYTES) {
        return __('api.bulk.too_long', ['max' => number_format(BULK_BODY_MAX_BYTES, 0, '.', ' ')]);
    }
    if ($format === 'html' && !htmlSafeAvailable()) return __('api.bulk.html_unavailable');
    if ($parts === null) {
        $unsub = function_exists('getUnsubscribeUrl') ? getUnsubscribeUrl('preview@example.com', $cfg) : '';
        $parts = bulkMailParts('…', $body, $format, $frame === 'none' ? 'none' : 'site', $cfg, $unsub);
    }
    // Nothing but tags we drop (a script, a style, a comment) is not a message: say so rather than send an empty mail.
    if ($format === 'html' && trim(htmlToText((string)$parts['inner'])) === '' && stripos((string)$parts['inner'], '<img') === false) {
        return __('api.bulk.html_empty');
    }
    // The whole document: a Markdown message grows by its inline styles, an HTML one by the way a parser writes it out.
    $size = strlen((string)$parts['html']);
    if ($size > BULK_DOC_MAX_BYTES) {
        return __('api.bulk.doc_too_big', ['kb' => number_format($size / 1000, 0, '.', ' '), 'max' => number_format(BULK_DOC_MAX_BYTES / 1000, 0, '.', ' ')]);
    }
    return null;
}

/**
 * Something the author should know that does not stop the message (the composer's preview shows it; sending is still
 * allowed): part of a campaign was nested more deeply than HTMLSAFE_MAX_DEPTH levels, so its words are in the message
 * but its tags — the layout — are not. Before 1.75.0 that content vanished without a word.
 */
function bulkBodyWarning(array $parts): ?string {
    $n = (int)($parts['flattened'] ?? 0);
    return $n > 0 ? __('api.bulk.html_flattened', ['n' => $n, 'max' => HTMLSAFE_MAX_DEPTH]) : null;
}

/**
 * Queue a message for an audience. Returns [batch_id, queued, skipped…].
 *
 * Nothing is sent here. The rows sit in mail_queue until the janitor picks them up.
 */
function bulkQueue(PDO $db, array $cfg, array $spec, string $subject, string $body, string $format = 'plain', string $frame = 'site'): array {
    $subject = mb_substr(trim($subject), 0, 200);
    $body    = trim($body);
    if (!in_array($format, ['plain', 'bbcode', 'markdown', 'html'], true)) $format = 'plain';
    // 'none' is the campaign's own design; it only means something for HTML, but a Markdown message may want the
    // bare page too, so it is not refused for the others.
    if ($frame !== 'none') $frame = 'site';
    if ($subject === '' || $body === '') {
        return ['error' => __('api.bulk.subject_body_required')];
    }
    if (($problem = bulkBodyProblem($body, $format, $cfg, $frame)) !== null) return ['error' => $problem];
    $rows = bulkAudience($db, $spec);
    if (!$rows) return ['error' => __('api.bulk.audience_empty')];

    $batch = bulkNewBatchId();
    $queued = 0; $skipped = 0;
    $st = $db->prepare(
        "INSERT INTO mail_queue (batch_id, user_id, email, subject, body, format, frame) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $email = trim((string)($r['email'] ?? ''));
        if ($email === '' || (int)($r['bulk_optout'] ?? 0) === 1) { $skipped++; continue; }
        if (function_exists('isUnsubscribed') && isUnsubscribed($db, $email, 'bulk')) { $skipped++; continue; }
        $st->execute([$batch, (int)$r['id'], $email, $subject, $body, $format, $frame]);
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

    // The day's ceiling (1.75.0, bulk_mail_daily_cap): what has gone out since the database's midnight is counted
    // from the rows themselves, so a restart, a second janitor or a batch queued in the evening all see the same
    // number. At the cap the tick sends nothing and the rows wait for tomorrow — `left` still says how many.
    $cap = bulkMailDailyCap($cfg);
    if ($cap > 0) {
        $today = (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'sent' AND sent_at >= CURDATE()")->fetchColumn();
        $limit = min($limit, $cap - $today);
        if ($limit <= 0) {
            $out['capped'] = true;
            $out['left'] = (int)$db->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'queued'")->fetchColumn();
            return $out;
        }
    }

    $due = $db->prepare(
        "SELECT id, user_id, email, subject, body, format, frame, attempts FROM mail_queue
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
            // The frame's own words (the preferences link) in the member's language (1.74.0, QUAL-18) — the
            // janitor that sends this has none of its own.
            $lang = function_exists('recipientLangOf') ? recipientLangOf($db, $cfg, (int)($r['user_id'] ?? 0)) : null;
            // Both halves from the one function the preview and the test copy use (1.75.0).
            $parts = bulkMailParts((string)$r['subject'], (string)$r['body'], (string)($r['format'] ?? 'plain'),
                                   (string)($r['frame'] ?? 'site'), $cfg, $unsub, $lang);
            $ok = sendEmail((string)$r['email'], (string)$r['subject'], $parts['text'], $parts['html'], $cfg, $unsub);
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
