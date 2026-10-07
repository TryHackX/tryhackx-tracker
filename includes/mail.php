<?php

/**
 * RFC 2047 encoded-word for header TEXT (Subject, From display name) containing non-ASCII.
 * Raw UTF-8 in a header makes postfix flag the message as needing SMTPUTF8 — and a dovecot
 * LMTP without SMTPUTF8 then BOUNCES it ("SMTPUTF8 is required, but was not offered"). That is
 * exactly what ate the password-reset mails whose subject contained an em-dash.
 */
function mailEncodeHeaderText(string $s): string {
    $s = str_replace(["\r", "\n"], '', $s);
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/**
 * Hosts the SENDER address may live on: the site_url host and each of its parent domains down to
 * the registrable one (site_url https://tracker.example.com → tracker.example.com, example.com).
 * Keeping the From on the site's own domain is what makes SPF/DKIM/DMARC align — a foreign domain
 * in From is the fastest way into the spam folder. Empty/IP site_url → no restriction ([]).
 */
function mailFromAllowedHosts(array $cfg): array {
    $host = strtolower((string)parse_url(trim((string)($cfg['site_url'] ?? '')), PHP_URL_HOST));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return [];
    $labels = explode('.', $host);
    $out = [];
    for ($i = 0; count($labels) - $i >= 2; $i++) $out[] = implode('.', array_slice($labels, $i));
    return $out;
}

/**
 * The address mails are SENT from (From: header + envelope sender): `mail_from_email` when set
 * and valid, otherwise the contact `site_email` (classic behaviour). Replies still go to the
 * contact address via Reply-To.
 */
function mailSenderAddress(array $cfg): string {
    $from = str_replace(["\r", "\n"], '', trim((string)($cfg['mail_from_email'] ?? '')));
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) return $from;
    $contact = str_replace(["\r", "\n"], '', (string)($cfg['site_email'] ?? 'noreply@localhost'));
    return filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : 'noreply@localhost';
}

/**
 * Absolute URL for use INSIDE an email. getBaseUrl() only knows the request PATH ("/"), which is
 * useless in a mail client — links must carry the scheme+host from the configured site URL.
 */
function mailAbsoluteUrl(array $cfg, string $pathAndQuery): string {
    $base = rtrim(trim((string)($cfg['site_url'] ?? '')), '/');
    if ($base === '') $base = rtrim((function_exists('getBaseUrl') ? getBaseUrl() : '/'), '/');
    return $base . '/' . ltrim($pathAndQuery, '/');
}

function sendEmail(string $to, string $subject, string $plainText, string $htmlBody, array $cfg, string $unsubscribeUrl = ''): bool {
    $siteName = mailEncodeHeaderText($cfg['site_name'] ?? 'Tracker');
    // sender (From + envelope) and contact (Reply-To) are separate: mails go out as e.g.
    // noreply@example.com while replies and the public contact stay on the site_email address
    $fromEmail = mailSenderAddress($cfg);
    $contactEmail = str_replace(["\r", "\n"], '', $cfg['site_email'] ?? '');
    if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) $contactEmail = $fromEmail;
    $subject = mailEncodeHeaderText($subject);
    $boundary = md5(uniqid(time()));

    $emailDomain = substr(strrchr($fromEmail, "@"), 1);
    if (!$emailDomain) {
        $emailDomain = 'localhost';
    }
    $msgId = "<" . bin2hex(random_bytes(16)) . "@" . $emailDomain . ">";

    $headers = "Date: " . date('r') . "\r\n";
    $headers .= "From: $siteName <$fromEmail>\r\n";
    $headers .= "Reply-To: $contactEmail\r\n";
    $headers .= "Message-ID: $msgId\r\n";
    $headers .= "X-Mailer: Tracker\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    if ($unsubscribeUrl) {
        $headers .= "List-Unsubscribe: <$unsubscribeUrl>, <mailto:$contactEmail>\r\n";
        $headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    }

    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plainText)) . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $body .= "--$boundary--";

    // envelope sender = the From address (SPF alignment; bounces go back to the sender mailbox)
    return @mail($to, $subject, $body, $headers, "-f" . $fromEmail);
}

/**
 * "Unsubscribe from everything" for one address — what a mail client's one-click Unsubscribe (RFC 8058) and
 * api/unsubscribe.php do: the report mails' five kinds off, and the address in `unsubscribed_emails`, which
 * isUnsubscribed() reads for EVERY kind — so the account notices a member may switch off and the
 * announcements stop as well. Never the transactional mail (the password reset, an e-mail change and its
 * confirmations, the verification): that never asks (userNotifyMail(), userVerifySend()).
 */
function unsubscribeAll(PDO $db, string $email): void {
    $stmt = $db->prepare(
        "INSERT INTO email_preferences (email, type, enabled) VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE enabled = 0"
    );
    foreach (['submission', 'review', 'status', 'custom', 'appeal'] as $t) {
        $stmt->execute([$email, $t]);
    }
    $db->prepare("INSERT IGNORE INTO unsubscribed_emails (email) VALUES (?)")->execute([$email]);
}

function isUnsubscribed(PDO $db, string $email, string $type = ''): bool {
    // Check legacy table first (full unsubscribe)
    $stmt = $db->prepare("SELECT COUNT(*) FROM unsubscribed_emails WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() > 0) return true;

    // Check per-type preferences
    if ($type !== '') {
        $stmt = $db->prepare("SELECT enabled FROM email_preferences WHERE email = ? AND type = ?");
        $stmt->execute([$email, $type]);
        $row = $stmt->fetch();
        if ($row && (int)$row['enabled'] === 0) return true;
    }
    return false;
}

/* ── the words of a mail (1.74.0, QUAL-18) ────────────────────────────────────────────────────────────
 *
 * Every word below comes from the dictionary (`mail.*`, tools/lang_src.d/mail.py) in ONE language per mail,
 * chosen by who it goes to:
 *   - the confirmation of a report or an appeal — the language of the page the form was sent from (the
 *     request's: mailRequestLang()), the only thing known about a sender who has no account;
 *   - what the panel sends a reporter later (under review, a new state, a message, the report deleted) — the
 *     site's default (mailSiteLang()): the request is the moderator's, and its language says nothing about the
 *     person the mail is for;
 *   - an account's mail — the account's (recipientLang(), includes/users.php), passed in as `lang`.
 * Every send* function takes an optional last `$lang` that overrides the choice.
 */

/** One word of a mail: in $lang when it names one, else in the request's own language. */
function mailT(?string $lang, string $key, array $params = []): string {
    if ($lang !== null && $lang !== '' && function_exists('langFor')) return langFor($lang, $key, $params);
    return function_exists('__') ? __($key, $params) : $key;
}

/** The site's default language for a mail to somebody with no account (recipientLang() for nobody). */
function mailSiteLang(array $cfg): string {
    if (function_exists('recipientLang')) return recipientLang($cfg, null);
    $site = strtolower(trim((string)($cfg['default_language'] ?? '')));
    return ($site !== '' && $site !== 'auto' && function_exists('langSupported') && langSupported($cfg, $site)) ? $site
         : (defined('LANG_FALLBACK') ? LANG_FALLBACK : 'en');
}

/** The language THIS request is in (a form's page), when one was resolved; the site's otherwise (the CLI). */
function mailRequestLang(array $cfg): string {
    if (!empty($GLOBALS['__lang']['current']) && function_exists('langCurrent')) return langCurrent();
    return mailSiteLang($cfg);
}

/** Plain text out of one of the dictionary's HTML sentences (`<br>` as a new line, every other tag dropped). */
function mailPlain(string $html): string {
    return html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)), ENT_QUOTES, 'UTF-8');
}

/**
 * A report's state, as the mail shows it. $state: blocked | blocked_action | reviewed | awaiting | closed | reopened
 * | deleted — the word in $lang, the colour by the state (an unknown state is shown as it is, uncoloured).
 */
function getStatusHtml(string $state, ?string $lang = null): string {
    $colors = [
        'blocked'        => '#ef4444',
        'blocked_action' => '#ef4444',
        'deleted'        => '#ef4444',
        'reviewed'       => '#3b82f6',
        'awaiting'       => '#f59e0b',
        'closed'         => '#6b7280',
        'reopened'       => '#8b5cf6',
    ];
    $word = isset($colors[$state]) ? mailT($lang, 'mail.st_' . $state) : $state;
    $color = $colors[$state] ?? '#e0e0e0';
    return '<strong style="color:' . $color . '">' . sanitize($word) . '</strong>';
}

/**
 * The table of a report as the panel's mails show it to the person who SENT it (their own words, back to them):
 * label => value (HTML, every value escaped). Labels in $lang.
 */
function getReportDetails(array $report, ?string $lang = null): array {
    $state = !empty($report['blocked']) ? 'blocked' : (!empty($report['checked']) ? 'reviewed' : 'awaiting');
    $details = [
        mailT($lang, 'mail.r_report_id')      => '#' . (int)$report['id'],
        mailT($lang, 'mail.r_reporter')       => sanitize((string)($report['name'] ?? '')),
        mailT($lang, 'mail.r_representative') => sanitize((string)($report['representative'] ?? '')),
        mailT($lang, 'mail.r_company')        => sanitize((string)($report['company'] ?? '')),
        mailT($lang, 'mail.r_object')         => sanitize((string)($report['objectTitle'] ?? '')),
        mailT($lang, 'mail.r_ip')             => sanitize((string)($report['ip'] ?? '')),
        mailT($lang, 'mail.r_date_filed')     => sanitize((string)($report['timestamp'] ?? '')),
        mailT($lang, 'mail.r_info_hash')      => sanitize((string)($report['infoHash'] ?? '')),
    ];
    if (!empty($report['magnet_link'])) {
        $details[mailT($lang, 'mail.r_magnet')] = '<code style="word-break:break-all;font-size:11px;">' . sanitize((string)$report['magnet_link']) . '</code>';
    }
    if (!empty($report['add_message'])) {
        $details[mailT($lang, 'mail.r_message')] = sanitize((string)$report['add_message']);
    }
    $details[mailT($lang, 'mail.r_status')] = getStatusHtml($state, $lang);
    return array_filter($details, fn($v) => $v !== '');
}

/** The plain-text lines of a details table. */
function mailDetailsPlain(array $details): string {
    $out = '';
    foreach ($details as $k => $v) $out .= $k . ': ' . html_entity_decode(strip_tags((string)$v), ENT_QUOTES, 'UTF-8') . "\n";
    return $out;
}

/* ── the two confirmations a form sends at once (PUB-1, 1.74.0) ───────────────────────────────────────
 *
 * A report and an appeal are confirmed to the address the FORM was given — an address nobody has verified,
 * so whoever fills the form in decides who gets the mail. Until 1.74.0 the confirmation carried what they
 * typed: the name, the representative, the company, the object's title, the message, the magnet; an appeal
 * its "Reason" (up to 2000 characters) and the address itself. That made the site's own domain a relay for
 * anybody's words to anybody's mailbox. Now a confirmation says only THAT a report or an appeal was received,
 * for which info hash, when, in which state — and, for a report, where to check it — and that whoever did not
 * send it may ignore it. Nothing in it was typed by the sender. (The daily ceiling on these mails and the
 * per-address limit are the forms' own, api/submit_report.php and api/submit_appeal.php.)
 *
 * Each is built by a function of its own that sends nothing, so a test can read every word that would go out.
 */

/**
 * The confirmation of a report, built and not sent: ['subject', 'plain', 'html', 'unsubscribe']. $report is
 * the `reports` row; only its id, infoHash, timestamp and email are read.
 */
function mailSubmissionConfirmationParts(array $report, array $cfg, string $lang): array {
    $id = (int)$report['id'];
    $unsubUrl = function_exists('getUnsubscribeUrl') ? getUnsubscribeUrl((string)$report['email'], $cfg) : '';
    $siteUrl = rtrim(trim((string)($cfg['site_url'] ?? '')), '/');
    $statusUrl = $siteUrl !== '' ? $siteUrl . '/?action=status' : '';
    $details = [
        mailT($lang, 'mail.r_report_id')  => '#' . $id,
        mailT($lang, 'mail.r_info_hash')  => '<code>' . sanitize((string)$report['infoHash']) . '</code>',
        mailT($lang, 'mail.r_date_filed') => sanitize((string)($report['timestamp'] ?? '')),
        mailT($lang, 'mail.r_status')     => getStatusHtml('awaiting', $lang),
    ];
    $intro  = mailT($lang, 'mail.sub_body', ['id' => $id]);
    $check  = $statusUrl !== '' ? mailT($lang, 'mail.check_status', ['url' => $statusUrl]) : mailT($lang, 'mail.check_status_nourl');
    $notYou = mailT($lang, 'mail.not_you');
    $footer = mailT($lang, 'mail.sub_footer');
    $subject = mailT($lang, 'mail.sub_subject', ['id' => $id]);

    $plain = mailT($lang, 'mail.hello_plain') . "\n\n" . $intro . "\n\n" . $check . "\n\n" . mailDetailsPlain($details)
           . "\n" . $notYou . "\n\n" . $footer . "\n\n"
           . ($unsubUrl !== '' ? mailT($lang, 'mail.unsubscribe_plain', ['url' => $unsubUrl]) . "\n" : '');
    // The status page as a link: the sentence escaped, then its (escaped) address replaced by the anchor.
    $checkHtml = sanitize($check);
    if ($statusUrl !== '') {
        $checkHtml = str_replace(sanitize($statusUrl), '<a href="' . sanitize($statusUrl) . '" style="color:#4a9eff;">' . sanitize($statusUrl) . '</a>', $checkHtml);
    }
    $html = buildEmailHtml([
        'title' => mailT($lang, 'mail.sub_title'),
        'greeting' => sanitize(mailT($lang, 'mail.hello_plain')),
        'body' => sanitize($intro) . '<br><br>' . $checkHtml . '<br><br>' . sanitize($notYou),
        'details' => $details,
        'footer_note' => $footer,
        'unsubscribe_url' => $unsubUrl,
        'lang' => $lang,
    ], $cfg);
    return ['subject' => $subject, 'plain' => $plain, 'html' => $html, 'unsubscribe' => $unsubUrl];
}

/**
 * Send confirmation email when a report is submitted (PUB-1: see above). $lang: the page's (default).
 */
function sendSubmissionConfirmation(PDO $db, int $reportId, array $cfg, ?string $lang = null): bool {
    $stmt = $db->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report || empty($report['email'])) return false;
    if (isUnsubscribed($db, $report['email'], 'submission')) return false;

    $m = mailSubmissionConfirmationParts($report, $cfg, $lang ?? mailRequestLang($cfg));
    $sent = sendEmail($report['email'], $m['subject'], $m['plain'], $m['html'], $cfg, $m['unsubscribe']);
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$reportId, $report['email'], $m['subject'], "Submission confirmation", $report['infoHash']]);
    }
    return $sent;
}

/**
 * Send notification when admin opens a report for review (first time). $lang: the site's (default).
 */
function sendUnderReviewNotification(PDO $db, int $reportId, array $cfg, ?string $lang = null): bool {
    $stmt = $db->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report || empty($report['email'])) return false;
    if (isUnsubscribed($db, $report['email'], 'review')) return false;

    $lang = $lang ?? mailSiteLang($cfg);
    $unsubUrl = getUnsubscribeUrl($report['email'], $cfg);
    $subject = mailT($lang, 'mail.rev_subject', ['id' => $reportId]);
    $thanks = mailT($lang, 'mail.rev_thanks', ['title' => (string)$report['objectTitle']]);
    $say = mailT($lang, 'mail.rev_body');
    $footer = mailT($lang, 'mail.rev_footer');
    $details = getReportDetails($report, $lang);

    $plain = mailT($lang, 'mail.dear', ['name' => (string)$report['name']]) . "\n\n" . $thanks . "\n\n" . $say . "\n\n"
           . mailDetailsPlain($details) . "\n" . $footer . "\n\n" . mailT($lang, 'mail.unsubscribe_plain', ['url' => $unsubUrl]) . "\n";

    $html = buildEmailHtml([
        'title' => mailT($lang, 'mail.rev_title'),
        'greeting' => sanitize(mailT($lang, 'mail.dear', ['name' => (string)$report['name']])),
        'body' => sanitize($thanks) . '<br><br>' . sanitize($say),
        'details' => $details,
        'footer_note' => $footer,
        'unsubscribe_url' => $unsubUrl,
        'lang' => $lang,
    ], $cfg);

    $sent = sendEmail($report['email'], $subject, $plain, $html, $cfg, $unsubUrl);
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$reportId, $report['email'], $subject, "Report is now under review.", $report['infoHash']]);
    }
    return $sent;
}

/** A report's new state, told to the person who sent it. $lang: the site's (default). */
function sendStatusNotification(PDO $db, int $reportId, string $newStatus, array $cfg, string $source = 'reports', ?string $lang = null): bool {
    $table = ($source === 'archives') ? 'archives' : 'reports';
    $stmt = $db->prepare("SELECT * FROM `$table` WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report || empty($report['email'])) return false;
    if (isUnsubscribed($db, $report['email'], 'status')) return false;

    $lang = $lang ?? mailSiteLang($cfg);
    $unsubUrl = getUnsubscribeUrl($report['email'], $cfg);
    // The state the mail names, and the colour it is drawn in.
    $states = ['checked' => 'reviewed', 'blocked' => 'blocked_action', 'pending' => 'awaiting',
               'archived' => 'closed', 'restored' => 'reopened'];
    $state = $states[$newStatus] ?? $newStatus;
    $stateWord = isset($states[$newStatus]) ? mailT($lang, 'mail.st_' . $state) : $newStatus;
    $kind = isset($states[$newStatus]) ? $newStatus : 'other';
    $subject = mailT($lang, 'mail.stn_subject_' . $kind, ['id' => $reportId]);
    // HTML sentences: every value escaped before it goes in.
    $bodyText = mailT($lang, 'mail.stn_' . $kind, ['title' => sanitize((string)$report['objectTitle']), 'status' => sanitize($stateWord)]);

    $details = getReportDetails($report, $lang);
    $details[mailT($lang, 'mail.r_status')] = getStatusHtml($state, $lang);

    $plain = mailT($lang, 'mail.dear', ['name' => (string)$report['name']]) . "\n\n" . mailPlain($bodyText) . "\n\n"
           . mailDetailsPlain($details) . "\n" . mailT($lang, 'mail.unsubscribe_plain', ['url' => $unsubUrl]) . "\n";

    $html = buildEmailHtml([
        'title' => $subject,
        'greeting' => sanitize(mailT($lang, 'mail.dear', ['name' => (string)$report['name']])),
        'body' => $bodyText,
        'details' => $details,
        'unsubscribe_url' => $unsubUrl,
        'lang' => $lang,
    ], $cfg);

    $sent = sendEmail($report['email'], $subject, $plain, $html, $cfg, $unsubUrl);
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$reportId, $report['email'], $subject, "Status changed to: $newStatus", $report['infoHash']]);
    }
    return $sent;
}

/** The team's own words to the person who sent a report. $lang (of the mail's frame): the site's (default). */
function sendCustomEmail(PDO $db, int $reportId, string $customMessage, array $cfg, ?string $lang = null): bool {
    $stmt = $db->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report || empty($report['email'])) return false;
    if (isUnsubscribed($db, $report['email'], 'custom')) return false;

    $lang = $lang ?? mailSiteLang($cfg);
    $unsubUrl = getUnsubscribeUrl($report['email'], $cfg);
    $details = getReportDetails($report, $lang);
    $subject = mailT($lang, 'mail.cus_subject', ['id' => $reportId]);
    $footer = mailT($lang, 'mail.cus_footer');

    $plain = mailT($lang, 'mail.dear', ['name' => (string)$report['name']]) . "\n\n";
    $plain .= mailT($lang, 'mail.cus_intro', ['id' => $reportId]) . "\n\n";
    $plain .= '--- ' . mailT($lang, 'mail.team_message') . " ---\n\n";
    $plain .= "$customMessage\n\n";
    $plain .= '--- ' . mailT($lang, 'mail.cus_details') . " ---\n";
    $plain .= mailDetailsPlain($details);
    $plain .= "\n" . $footer . "\n\n";
    $plain .= mailT($lang, 'mail.unsubscribe_plain', ['url' => $unsubUrl]) . "\n";

    $html = buildEmailHtml([
        'title' => mailT($lang, 'mail.cus_title', ['id' => $reportId]),
        'greeting' => sanitize(mailT($lang, 'mail.dear', ['name' => (string)$report['name']])),
        'body' => sanitize(mailT($lang, 'mail.cus_body')),
        'custom_message' => $customMessage,
        'details' => $details,
        'footer_note' => $footer,
        'unsubscribe_url' => $unsubUrl,
        'lang' => $lang,
    ], $cfg);

    $sent = sendEmail($report['email'], $subject, $plain, $html, $cfg, $unsubUrl);
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$reportId, $report['email'], $subject, sanitize($customMessage), $report['infoHash']]);
    }
    return $sent;
}

/**
 * The confirmation of an appeal or a block request, built and not sent (PUB-1: see above):
 * ['subject', 'plain', 'html', 'unsubscribe']. $appeal is the `appeals` row; only its infoHash, appeal_type,
 * timestamp and email are read — never its name, its message (the "Reason") or the title of the report it is about.
 */
function mailAppealConfirmationParts(array $appeal, array $cfg, string $lang): array {
    $isBlock = ($appeal['appeal_type'] ?? 'unblock') === 'block';
    $type = mailT($lang, $isBlock ? 'mail.app_type_block' : 'mail.app_type_unblock');
    $site = (string)($cfg['site_name'] ?? 'Tracker');
    $unsubUrl = function_exists('getUnsubscribeUrl') ? getUnsubscribeUrl((string)$appeal['email'], $cfg) : '';
    $details = [
        mailT($lang, 'mail.r_info_hash')    => '<code>' . sanitize((string)$appeal['infoHash']) . '</code>',
        mailT($lang, 'mail.r_request_type') => sanitize($type),
        mailT($lang, 'mail.r_date')         => sanitize((string)($appeal['timestamp'] ?? '')),
        mailT($lang, 'mail.r_status')       => getStatusHtml('awaiting', $lang),
    ];
    $say = mailT($lang, $isBlock ? 'mail.app_body_block' : 'mail.app_body_unblock');
    $notYou = mailT($lang, 'mail.not_you');
    $footer = mailT($lang, 'mail.app_footer');
    $subject = mailT($lang, 'mail.app_subject', ['type' => $type, 'site' => $site]);

    $plain = mailT($lang, 'mail.hello_plain') . "\n\n" . $say . "\n\n" . mailDetailsPlain($details)
           . "\n" . $notYou . "\n\n" . $footer . "\n\n"
           . ($unsubUrl !== '' ? mailT($lang, 'mail.unsubscribe_plain', ['url' => $unsubUrl]) . "\n" : '');
    $html = buildEmailHtml([
        'title' => mailT($lang, 'mail.app_title', ['type' => $type]),
        'greeting' => sanitize(mailT($lang, 'mail.hello_plain')),
        'body' => sanitize($say) . '<br><br>' . sanitize($notYou),
        'details' => $details,
        'footer_note' => $footer,
        'unsubscribe_url' => $unsubUrl,
        'lang' => $lang,
    ], $cfg);
    return ['subject' => $subject, 'plain' => $plain, 'html' => $html, 'unsubscribe' => $unsubUrl];
}

/**
 * Send confirmation email when an appeal is submitted (PUB-1: see above). $lang: the page's (default).
 */
function sendAppealConfirmation(PDO $db, int $appealId, array $cfg, ?string $lang = null): bool {
    $stmt = $db->prepare("SELECT * FROM appeals WHERE id = ?");
    $stmt->execute([$appealId]);
    $appeal = $stmt->fetch();
    if (!$appeal || empty($appeal['email'])) return false;
    if (isUnsubscribed($db, $appeal['email'], 'appeal')) return false;

    $m = mailAppealConfirmationParts($appeal, $cfg, $lang ?? mailRequestLang($cfg));
    return sendEmail($appeal['email'], $m['subject'], $m['plain'], $m['html'], $cfg, $m['unsubscribe']);
}

/* ── an appeal's later mails (1.74.0, QUAL-18 — part E) ───────────────────────────────────────────────
 *
 * What the panel tells an appellant after a moderator acted: the decision (api/admin/resolve_appeal.php), an
 * appeal reopened (api/admin/restore_appeal.php), an appeal closed because another one for the same hash was
 * resolved (autoCloseRelatedAppeals(), includes/functions.php). Until 1.74.0 three blocks of English literals in
 * those files; now one builder through the dictionary (`mail.apd_*`). The language is the site's default
 * (mailSiteLang()): an appeal has no account and stores no language, and the request is the moderator's.
 */

/**
 * One of them, built and not sent: ['subject', 'plain', 'html', 'unsubscribe']. $kind: accepted | rejected |
 * reopened | auto_closed. $appeal: the `appeals` (or `appeal_archives`) row — its infoHash, appeal_type, name
 * and email are read. $opts: 'listed' (bool: the decision changed the tracker's list — blocked or unblocked),
 * 'title' (the reported object's title, text), 'response' (the moderator's words to the appellant, text).
 */
function mailAppealDecisionParts(array $appeal, string $kind, array $cfg, string $lang, array $opts = []): array {
    $isBlock = ($appeal['appeal_type'] ?? 'unblock') === 'block';
    $type = mailT($lang, $isBlock ? 'mail.app_type_block' : 'mail.app_type_unblock');
    $site = (string)($cfg['site_name'] ?? 'Tracker');
    $colors = ['accepted' => '#22c55e', 'rejected' => '#ef4444'];
    if (!in_array($kind, ['accepted', 'rejected', 'reopened', 'auto_closed'], true)) $kind = 'rejected';
    $decision = mailT($lang, 'mail.apd_dec_' . $kind);
    $decisionHtml = isset($colors[$kind])
        ? '<span style="color:' . $colors[$kind] . '">' . sanitize($decision) . '</span>'
        : sanitize($decision);
    $unsubUrl = function_exists('getUnsubscribeUrl') ? getUnsubscribeUrl((string)$appeal['email'], $cfg) : '';

    if ($kind === 'reopened') {
        $subject = mailT($lang, 'mail.apd_subject_reopened', ['type' => $type, 'site' => $site]);
        $bodyHtml = sanitize(mailT($lang, $isBlock ? 'mail.apd_reopened_block' : 'mail.apd_reopened_unblock'));
        $statusLabel = mailT($lang, 'mail.r_status');
    } elseif ($kind === 'auto_closed') {
        $subject = mailT($lang, 'mail.apd_subject_auto_closed', ['type' => $type, 'site' => $site]);
        $bodyHtml = sanitize(mailT($lang, $isBlock ? 'mail.apd_auto_closed_block' : 'mail.apd_auto_closed_unblock'));
        $statusLabel = mailT($lang, 'mail.r_decision');
    } else {
        $subject = mailT($lang, 'mail.apd_subject_decided', ['type' => $type, 'decision' => $decision, 'site' => $site]);
        // An HTML sentence: the decision goes in coloured and escaped.
        $bodyHtml = mailT($lang, $isBlock ? 'mail.apd_body_block' : 'mail.apd_body_unblock', ['decision' => $decisionHtml]);
        if (!empty($opts['listed'])) {
            $bodyHtml .= ' ' . sanitize(mailT($lang, $isBlock ? 'mail.apd_listed_block' : 'mail.apd_listed_unblock'));
        }
        $statusLabel = mailT($lang, 'mail.r_decision');
    }
    $details = [];
    $title = trim((string)($opts['title'] ?? ''));
    if ($title !== '' && in_array($kind, ['accepted', 'rejected'], true)) $details[mailT($lang, 'mail.r_object')] = sanitize($title);
    $details[mailT($lang, 'mail.r_info_hash')] = '<code>' . sanitize((string)($appeal['infoHash'] ?? '')) . '</code>';
    $details[mailT($lang, 'mail.r_request_type')] = sanitize($type);
    $details[$statusLabel] = '<strong>' . $decisionHtml . '</strong>';
    $response = trim((string)($opts['response'] ?? ''));
    $greeting = mailT($lang, 'mail.hello', ['name' => (string)($appeal['name'] ?? '')]);

    $plain = $greeting . "\n\n" . mailPlain($bodyHtml) . "\n\n"
           . ($response !== '' ? '--- ' . mailT($lang, 'mail.team_message') . " ---\n" . $response . "\n\n" : '')
           . mailDetailsPlain($details)
           . ($unsubUrl !== '' ? "\n" . mailT($lang, 'mail.unsubscribe_plain', ['url' => $unsubUrl]) . "\n" : '');
    $html = buildEmailHtml([
        'title' => $subject,
        'greeting' => sanitize($greeting),
        'body' => $bodyHtml,
        'details' => $details,
        'custom_message' => $response,
        'unsubscribe_url' => $unsubUrl,
        'lang' => $lang,
    ], $cfg);
    return ['subject' => $subject, 'plain' => $plain, 'html' => $html, 'unsubscribe' => $unsubUrl];
}

/**
 * Send one of an appeal's later mails (see above) — unless the address switched appeal mails off. $lang: the
 * site's (default). False when nothing was sent.
 */
function sendAppealDecision(PDO $db, array $appeal, string $kind, array $cfg, array $opts = [], ?string $lang = null): bool {
    $to = trim((string)($appeal['email'] ?? ''));
    if ($to === '' || isUnsubscribed($db, $to, 'appeal')) return false;
    $m = mailAppealDecisionParts($appeal, $kind, $cfg, $lang ?? mailSiteLang($cfg), $opts);
    return sendEmail($to, $m['subject'], $m['plain'], $m['html'], $cfg, $m['unsubscribe']);
}

/**
 * The HTML of every mail. `title`, the site's name and its address are text (escaped here); `greeting`, `body`
 * and the values of `details` are HTML the caller escaped; `custom_message` and `footer_note` are text. `lang`
 * (1.74.0): the language of the frame's own words — the button's fallback line, the preferences link, the
 * team's heading — the caller's mail is written in; without it, the request's.
 */
function buildEmailHtml(array $data, array $cfg): string {
    $lang = isset($data['lang']) && is_string($data['lang']) && $data['lang'] !== '' ? $data['lang'] : null;
    $title = htmlspecialchars((string)($data['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $greeting = $data['greeting'] ?? '';
    $body = $data['body'] ?? '';
    // Don't double-sanitize — body may contain intentional HTML (like <strong>)
    $bodyHtml = $body;
    $details = $data['details'] ?? [];
    $customMessage = $data['custom_message'] ?? '';
    $footerNote = $data['footer_note'] ?? '';
    $unsubUrl = $data['unsubscribe_url'] ?? '';
    $siteEmail = htmlspecialchars((string)($cfg['site_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $siteName = htmlspecialchars((string)($cfg['site_name'] ?? 'Tracker'), ENT_QUOTES, 'UTF-8');

    $rows = '';
    foreach ($details as $label => $value) {
        $label = htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
        $rows .= "<tr><td style='padding:8px 14px;color:#8899aa;white-space:nowrap;font-size:13px;border-bottom:1px solid #1a1a2e;'>$label</td>" .
                 "<td style='padding:8px 14px;color:#e0e0e0;font-size:13px;border-bottom:1px solid #1a1a2e;'>$value</td></tr>";
    }

    $customBlock = '';
    if ($customMessage) {
        $escapedMsg = nl2br(sanitize($customMessage));
        $teamWord = sanitize(mailT($lang, 'mail.team_message'));
        $customBlock = <<<HTML
        <div style="margin:16px 0;padding:14px 18px;background:#0d1117;border-left:3px solid #4a9eff;border-radius:4px;">
            <p style="color:#8899aa;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;margin:0 0 8px;">{$teamWord}</p>
            <p style="color:#e0e0e0;margin:0;line-height:1.6;">{$escapedMsg}</p>
        </div>
HTML;
    }

    $footerNoteHtml = '';
    if ($footerNote) {
        $footerNoteHtml = '<p style="color:#8899aa;font-size:12px;margin:16px 0 0;font-style:italic;">' . sanitize($footerNote) . '</p>';
    }

    // Call-to-action: a real button plus the raw link underneath ("if the button does not work,
    // copy this link") — mail clients mangle bare-text links, so both forms are always given.
    $actionBlock = '';
    $actionUrl = trim((string)($data['action_url'] ?? ''));
    if ($actionUrl !== '' && preg_match('#^https?://#i', $actionUrl)) {
        $actionLabel = sanitize((string)(($data['action_label'] ?? '') !== '' ? $data['action_label'] : mailT($lang, 'mail.open_link')));
        $safeUrl = sanitize($actionUrl);
        $fallback = sanitize(mailT($lang, 'mail.button_fallback'));
        $actionBlock = <<<HTML
        <div style="text-align:center;margin:22px 0 10px;">
            <a href="{$safeUrl}" style="display:inline-block;background:#4a9eff;color:#04121f;font-weight:600;font-size:14px;padding:11px 26px;border-radius:6px;text-decoration:none;">{$actionLabel}</a>
        </div>
        <p style="color:#8899aa;font-size:11px;margin:0 0 14px;text-align:center;">{$fallback}<br>
        <a href="{$safeUrl}" style="color:#4a9eff;font-size:11px;word-break:break-all;">{$safeUrl}</a></p>
HTML;
    }

    // The preferences link only renders when a real URL was provided — an empty href looked like
    // a dead link in the reset mails.
    $unsubBlock = $unsubUrl !== ''
        ? '<a href="' . sanitize($unsubUrl) . '" style="color:#5a6a7a;font-size:11px;text-decoration:underline;">' . sanitize(mailT($lang, 'mail.manage_prefs')) . '</a>'
        : '';
    $htmlLang = sanitize($lang ?? (function_exists('langCurrent') ? langCurrent() : 'en'));

    return <<<HTML
<!DOCTYPE html><html lang="{$htmlLang}"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#0a0a1a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,monospace,sans-serif;">
<div style="max-width:600px;margin:20px auto;background:#111;border:1px solid #2a2a3e;border-radius:8px;overflow:hidden;">
<div style="background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);padding:24px;text-align:center;border-bottom:1px solid #2a2a3e;">
<h2 style="color:#4a9eff;margin:0;font-size:18px;font-weight:600;">{$title}</h2>
<p style="color:#5a6a7a;margin:6px 0 0;font-size:12px;">{$siteName}</p>
</div>
<div style="padding:24px;color:#e0e0e0;">
<p style="color:#ccc;margin:0 0 16px;">{$greeting}</p>
<p style="line-height:1.7;margin:0 0 16px;">{$bodyHtml}</p>
{$actionBlock}
{$customBlock}
<table style="width:100%;border-collapse:collapse;margin:20px 0;background:#0a0a1a;border-radius:6px;overflow:hidden;">{$rows}</table>
{$footerNoteHtml}
</div>
<div style="padding:16px 24px;background:#0a0a14;text-align:center;border-top:1px solid #2a2a3e;">
{$unsubBlock}
<p style="color:#3a3a4a;font-size:11px;margin:8px 0 0;">{$siteName} &bull; {$siteEmail}</p>
</div>
</div></body></html>
HTML;
}

/**
 * Send notification when a report is permanently deleted. $lang: the site's (default).
 */
function sendDeletionNotification(PDO $db, array $report, string $reason, array $cfg, ?string $lang = null): bool {
    if (empty($report['email'])) return false;
    if (isUnsubscribed($db, $report['email'], 'status')) return false;

    $lang = $lang ?? mailSiteLang($cfg);
    $id = (int)$report['id'];
    $unsubUrl = getUnsubscribeUrl($report['email'], $cfg);
    $subject = mailT($lang, 'mail.del_subject', ['id' => $id]);

    $details = getReportDetails($report, $lang);
    $details[mailT($lang, 'mail.r_status')] = getStatusHtml('deleted', $lang);
    $bodyHtml = mailT($lang, 'mail.del_body', ['id' => $id, 'title' => sanitize((string)$report['objectTitle'])]);
    $footer = mailT($lang, 'mail.del_footer');

    $plain = mailT($lang, 'mail.dear', ['name' => (string)$report['name']]) . "\n\n";
    $plain .= mailPlain($bodyHtml) . "\n\n";
    if (!empty($reason)) {
        $plain .= mailT($lang, 'mail.del_reason') . "\n$reason\n\n";
    }
    $plain .= '--- ' . mailT($lang, 'mail.cus_details') . " ---\n";
    $plain .= mailDetailsPlain($details);
    $plain .= "\n" . mailT($lang, 'mail.unsubscribe_plain', ['url' => $unsubUrl]) . "\n";

    $html = buildEmailHtml([
        'title' => $subject,
        'greeting' => sanitize(mailT($lang, 'mail.dear', ['name' => (string)$report['name']])),
        'body' => $bodyHtml,
        'custom_message' => $reason ?: null,
        'details' => $details,
        'footer_note' => $footer,
        'unsubscribe_url' => $unsubUrl,
        'lang' => $lang,
    ], $cfg);

    $sent = sendEmail($report['email'], $subject, $plain, $html, $cfg, $unsubUrl);
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$report['id'], $report['email'], $subject, "Report permanently deleted. Reason: " . ($reason ?: 'None'), $report['infoHash']]);
    }
    return $sent;
}
