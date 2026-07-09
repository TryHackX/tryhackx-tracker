<?php

function sendEmail(string $to, string $subject, string $plainText, string $htmlBody, array $cfg, string $unsubscribeUrl = ''): bool {
    $siteName = str_replace(["\r", "\n"], '', $cfg['site_name'] ?? 'Tracker');
    $siteEmail = str_replace(["\r", "\n"], '', $cfg['site_email'] ?? 'noreply@localhost');
    $boundary = md5(uniqid(time()));

    $emailDomain = substr(strrchr($siteEmail, "@"), 1);
    if (!$emailDomain) {
        $emailDomain = 'localhost';
    }
    $msgId = "<" . bin2hex(random_bytes(16)) . "@" . $emailDomain . ">";

    $headers = "Date: " . date('r') . "\r\n";
    $headers .= "From: $siteName <$siteEmail>\r\n";
    $headers .= "Reply-To: $siteEmail\r\n";
    $headers .= "Message-ID: $msgId\r\n";
    $headers .= "X-Mailer: Tracker\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    if ($unsubscribeUrl) {
        $headers .= "List-Unsubscribe: <$unsubscribeUrl>, <mailto:$siteEmail>\r\n";
        $headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    }

    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plainText)) . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $body .= "--$boundary--";

    $envelopeEmail = filter_var($siteEmail, FILTER_VALIDATE_EMAIL) ? $siteEmail : 'noreply@localhost';
    return @mail($to, $subject, $body, $headers, "-f" . $envelopeEmail);
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

function getStatusHtml(string $status): string {
    $colors = [
        'Blocked' => '#ef4444',
        'Blocked — Action Taken' => '#ef4444',
        'Reviewed' => '#3b82f6',
        'Awaiting Review' => '#f59e0b',
        'Archived / Closed' => '#6b7280',
        'Reopened' => '#8b5cf6',
    ];
    $color = $colors[$status] ?? '#e0e0e0';
    return '<strong style="color:' . $color . '">' . sanitize($status) . '</strong>';
}

function getReportDetails(array $report): array {
    $statusParts = [];
    if ($report['blocked']) {
        $statusParts[] = 'Blocked';
    } elseif ($report['checked']) {
        $statusParts[] = 'Reviewed';
    } else {
        $statusParts[] = 'Awaiting Review';
    }
    $statusText = implode(', ', $statusParts);

    $details = [
        'Report ID' => "#{$report['id']}",
        'Reporter' => sanitize($report['name']),
        'Representative' => sanitize($report['representative'] ?? ''),
        'Company' => sanitize($report['company']),
        'Object' => sanitize($report['objectTitle']),
        'IP Address' => sanitize($report['ip'] ?? ''),
        'Date Filed' => $report['timestamp'],
        'Info Hash' => $report['infoHash'],
    ];

    // Add magnet link if available
    if (!empty($report['magnet_link'])) {
        $details['Magnet Link'] = '<code style="word-break:break-all;font-size:11px;">' . sanitize($report['magnet_link']) . '</code>';
    }

    // Add message if available
    if (!empty($report['add_message'])) {
        $details['Message'] = sanitize($report['add_message']);
    }

    $details['Status'] = getStatusHtml($statusText);

    // Remove empty fields
    return array_filter($details, fn($v) => $v !== '');
}

/**
 * Send confirmation email when a report is submitted
 */
function sendSubmissionConfirmation(PDO $db, int $reportId, array $cfg): bool {
    $stmt = $db->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report || empty($report['email'])) return false;
    if (isUnsubscribed($db, $report['email'], 'submission')) return false;

    $subject = "Report #{$reportId} — Submission Confirmed";

    $details = getReportDetails($report);
    $details['Status'] = getStatusHtml('Awaiting Review');

    $unsubUrl = getUnsubscribeUrl($report['email'], $cfg);

    $plain = "Dear {$report['name']},\n\n";
    $plain .= "Thank you for submitting your report. We have received your submission and it has been logged in our system.\n\n";
    $plain .= "Your report reference number is #{$reportId}. Please keep this number for your records — you can use it to check the status of your report at any time.\n\n";
    foreach ($details as $k => $v) {
        $plain .= "$k: " . strip_tags($v) . "\n";
    }
    $plain .= "\nOur team will review your report and take appropriate action. You will receive email notifications as the status of your report changes.\n\n";
    $plain .= "Unsubscribe: {$unsubUrl}\n";

    $siteUrl = rtrim($cfg['site_url'] ?? '', '/');
    $statusUrl = $siteUrl ? $siteUrl . '/?action=status' : '';
    $statusNote = $statusUrl
        ? 'You can check the current status of your report at any time by visiting <a href="' . sanitize($statusUrl) . '" style="color:#4a9eff;">' . sanitize($statusUrl) . '</a> using your report number and email address.'
        : 'You can check the current status of your report at any time using your report number and email address.';

    $html = buildEmailHtml([
        'title' => 'Report Submission Confirmed',
        'greeting' => "Dear {$report['name']},",
        'body' => "Thank you for submitting your report. We have received your submission and it has been logged in our system.<br><br>" .
                  "Your report reference number is <strong>#{$reportId}</strong>. Please keep this number for your records.<br><br>" .
                  $statusNote,
        'details' => $details,
        'footer_note' => 'Our team will review your report and take appropriate action. You will receive email notifications as the status changes.',
        'unsubscribe_url' => $unsubUrl,
    ], $cfg);

    $sent = sendEmail($report['email'], $subject, $plain, $html, $cfg, $unsubUrl);
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$reportId, $report['email'], $subject, "Submission confirmation", $report['infoHash']]);
    }
    return $sent;
}

/**
 * Send notification when admin opens a report for review (first time)
 */
function sendUnderReviewNotification(PDO $db, int $reportId, array $cfg): bool {
    $stmt = $db->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report || empty($report['email'])) return false;
    if (isUnsubscribed($db, $report['email'], 'review')) return false;

    $unsubUrl = getUnsubscribeUrl($report['email'], $cfg);
    $subject = "Report #{$reportId} — Under Review";

    $plain = "Dear {$report['name']},\n\n";
    $plain .= "Thank you for submitting your report regarding \"{$report['objectTitle']}\".\n\n";
    $plain .= "We would like to inform you that your report has been received and is currently under review by our team. ";
    $plain .= "If any action is taken as a result of your report, you will receive a follow-up notification.\n\n";
    $plain .= "Report ID: #{$reportId}\n";
    $plain .= "Object: {$report['objectTitle']}\n";
    $plain .= "Info Hash: {$report['infoHash']}\n\n";
    $plain .= "Please do not reply to this email. If you have additional information to provide, you may submit a new report.\n\n";
    $plain .= "Unsubscribe: {$unsubUrl}\n";

    $html = buildEmailHtml([
        'title' => 'Report Under Review',
        'greeting' => "Dear {$report['name']},",
        'body' => "Thank you for submitting your report regarding <strong>" . sanitize($report['objectTitle']) . "</strong>.<br><br>" .
                  "We would like to inform you that your report has been received and is currently under review by our team. " .
                  "If any action is taken as a result of your report, you will receive a follow-up notification.",
        'details' => getReportDetails($report),
        'footer_note' => 'Please do not reply to this email. If you have additional information to provide, you may submit a new report.',
        'unsubscribe_url' => $unsubUrl,
    ], $cfg);

    $sent = sendEmail($report['email'], $subject, $plain, $html, $cfg, $unsubUrl);
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$reportId, $report['email'], $subject, "Report is now under review.", $report['infoHash']]);
    }
    return $sent;
}

function sendStatusNotification(PDO $db, int $reportId, string $newStatus, array $cfg, string $source = 'reports'): bool {
    $table = ($source === 'archives') ? 'archives' : 'reports';
    $stmt = $db->prepare("SELECT * FROM `$table` WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report || empty($report['email'])) return false;
    if (isUnsubscribed($db, $report['email'], 'status')) return false;

    $unsubUrl = getUnsubscribeUrl($report['email'], $cfg);
    $statusLabels = [
        'checked' => 'Reviewed',
        'blocked' => 'Blocked — Action Taken',
        'pending' => 'Awaiting Review',
        'archived' => 'Archived / Closed',
        'restored' => 'Reopened',
    ];
    $statusText = $statusLabels[$newStatus] ?? $newStatus;

    // Build contextual body text
    switch ($newStatus) {
        case 'blocked':
            $subject = "Report #{$reportId} — Action Taken";
            $bodyText = "Following a review of your report regarding <strong>" . sanitize($report['objectTitle']) . "</strong>, " .
                        "we have determined that the reported content violates our policies.<br><br>" .
                        "The associated info hash has been <strong>blocked</strong> on our tracker. " .
                        "The content will no longer be trackable through our services.";
            break;
        case 'checked':
            $subject = "Report #{$reportId} — Reviewed";
            $bodyText = "Your report regarding <strong>" . sanitize($report['objectTitle']) . "</strong> has been reviewed by our team.<br><br>" .
                        "The report has been marked as reviewed. If further action is required, you will be notified separately.";
            break;
        case 'pending':
            $subject = "Report #{$reportId} — Status Updated";
            $bodyText = "The status of your report regarding <strong>" . sanitize($report['objectTitle']) . "</strong> has been updated to <strong>Awaiting Review</strong>.<br><br>" .
                        "Our team will review your report and take appropriate action if necessary.";
            break;
        case 'archived':
            $subject = "Report #{$reportId} — Closed";
            $bodyText = "Your report regarding <strong>" . sanitize($report['objectTitle']) . "</strong> has been processed and archived.<br><br>" .
                        "This report is now closed. No further action will be taken unless a new report is submitted.";
            break;
        case 'restored':
            $subject = "Report #{$reportId} — Reopened";
            $bodyText = "Your report regarding <strong>" . sanitize($report['objectTitle']) . "</strong> has been restored to active status.<br><br>" .
                        "Our team will continue reviewing your report. You will receive further notifications as the status changes.";
            break;
        default:
            $subject = "Report #{$reportId} — Status Changed";
            $bodyText = "The status of your report has been updated to <strong>" . sanitize($statusText) . "</strong>.";
    }

    // Update report details with new status
    $details = getReportDetails($report);
    $details['Status'] = getStatusHtml($statusText);

    $plain = "Dear {$report['name']},\n\n";
    $plain .= strip_tags(str_replace(['<br>', '<br/>','<br />'], "\n", $bodyText)) . "\n\n";
    $plain .= "Report ID: #{$reportId}\nObject: {$report['objectTitle']}\n";
    $plain .= "Info Hash: {$report['infoHash']}\nStatus: {$statusText}\n\n";
    $plain .= "Unsubscribe: {$unsubUrl}\n";

    $html = buildEmailHtml([
        'title' => $subject,
        'greeting' => "Dear {$report['name']},",
        'body' => $bodyText,
        'details' => $details,
        'unsubscribe_url' => $unsubUrl,
    ], $cfg);

    $sent = sendEmail($report['email'], $subject, $plain, $html, $cfg, $unsubUrl);
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$reportId, $report['email'], $subject, "Status changed to: $newStatus", $report['infoHash']]);
    }
    return $sent;
}

function sendCustomEmail(PDO $db, int $reportId, string $customMessage, array $cfg): bool {
    $stmt = $db->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report || empty($report['email'])) return false;
    if (isUnsubscribed($db, $report['email'], 'custom')) return false;

    $unsubUrl = getUnsubscribeUrl($report['email'], $cfg);
    $details = getReportDetails($report);

    $subject = "Report #{$reportId} — Message From Our Team";

    $plain = "Dear {$report['name']},\n\n";
    $plain .= "You are receiving this message regarding your report #{$reportId}.\n\n";
    $plain .= "--- Message from our team ---\n\n";
    $plain .= "$customMessage\n\n";
    $plain .= "--- Report Details ---\n";
    foreach ($details as $label => $value) {
        $plain .= "$label: " . strip_tags($value) . "\n";
    }
    $plain .= "\nIf you have any questions, please submit a new report or contact us via the provided channels.\n\n";
    $plain .= "Unsubscribe: {$unsubUrl}\n";

    $html = buildEmailHtml([
        'title' => "Message Regarding Report #{$reportId}",
        'greeting' => "Dear {$report['name']},",
        'body' => "You are receiving this message regarding your report. Our team has the following update for you:",
        'custom_message' => $customMessage,
        'details' => $details,
        'footer_note' => 'If you have any questions, please submit a new report or contact us via the provided channels.',
        'unsubscribe_url' => $unsubUrl,
    ], $cfg);

    $sent = sendEmail($report['email'], $subject, $plain, $html, $cfg, $unsubUrl);
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$reportId, $report['email'], $subject, sanitize($customMessage), $report['infoHash']]);
    }
    return $sent;
}

/**
 * Send confirmation email when an appeal is submitted
 */
function sendAppealConfirmation(PDO $db, int $appealId, array $cfg): bool {
    $stmt = $db->prepare("SELECT * FROM appeals WHERE id = ?");
    $stmt->execute([$appealId]);
    $appeal = $stmt->fetch();
    if (!$appeal || empty($appeal['email'])) return false;
    if (isUnsubscribed($db, $appeal['email'], 'appeal')) return false;

    $appealType = $appeal['appeal_type'] ?? 'unblock';
    $typeLabel = $appealType === 'block' ? 'Block Request' : 'Unblock Appeal';

    // Fetch objectTitle from reports or archives
    $objectTitle = '';
    $stmt = $db->prepare("SELECT objectTitle FROM reports WHERE infoHash = ? LIMIT 1");
    $stmt->execute([$appeal['infoHash']]);
    $row = $stmt->fetch();
    if ($row) {
        $objectTitle = $row['objectTitle'];
    } else {
        $stmt = $db->prepare("SELECT objectTitle FROM archives WHERE infoHash = ? LIMIT 1");
        $stmt->execute([$appeal['infoHash']]);
        $row = $stmt->fetch();
        if ($row) $objectTitle = $row['objectTitle'];
    }

    $subject = $typeLabel . ' Received — ' . ($cfg['site_name'] ?? 'Tracker');
    $unsubUrl = getUnsubscribeUrl($appeal['email'], $cfg);

    $details = [];
    if (!empty($objectTitle)) {
        $details['Object'] = sanitize($objectTitle);
    }
    $details['Appellant'] = sanitize($appeal['name']);
    $details['Email'] = sanitize($appeal['email']);
    $details['Info Hash'] = '<code>' . sanitize($appeal['infoHash']) . '</code>';
    $details['Request Type'] = $typeLabel;
    $details['Date Filed'] = $appeal['timestamp'];
    if (!empty($appeal['message'])) {
        $details['Reason'] = sanitize($appeal['message']);
    }
    $details['Status'] = getStatusHtml('Awaiting Review');

    if ($appealType === 'block') {
        $bodyText = "Your <strong>Block Request</strong> has been received. You are requesting that the info hash below be blocked on our tracker.<br><br>" .
                    "Our team will review your request and determine whether the content meets the criteria for blocking. " .
                    "You will be notified by email once a decision has been made.";
    } else {
        $bodyText = "Your <strong>Unblock Appeal</strong> has been received and is pending review by our team.<br><br>" .
                    "You will be notified by email once a decision has been made.";
    }

    $plain = "Dear {$appeal['name']},\n\n";
    $plain .= strip_tags(str_replace(['<br>', '<br/>','<br />'], "\n", $bodyText)) . "\n\n";
    if (!empty($objectTitle)) $plain .= "Object: {$objectTitle}\n";
    $plain .= "Info Hash: {$appeal['infoHash']}\n";
    $plain .= "Request Type: {$typeLabel}\n";
    $plain .= "Date: {$appeal['timestamp']}\n";
    if (!empty($appeal['message'])) $plain .= "Reason: {$appeal['message']}\n";
    $plain .= "Status: Awaiting Review\n\n";
    $plain .= "Our team will review your request and you will be notified of the decision by email.\n\n";
    $plain .= "Unsubscribe: {$unsubUrl}\n";

    $html = buildEmailHtml([
        'title' => $typeLabel . ' Received',
        'greeting' => 'Dear ' . sanitize($appeal['name']) . ',',
        'body' => $bodyText,
        'details' => $details,
        'footer_note' => 'Please do not reply to this email. If you have additional information to provide, you may submit a new appeal.',
        'unsubscribe_url' => $unsubUrl,
    ], $cfg);

    return sendEmail($appeal['email'], $subject, $plain, $html, $cfg, $unsubUrl);
}

function buildEmailHtml(array $data, array $cfg): string {
    $title = $data['title'] ?? '';
    $greeting = $data['greeting'] ?? '';
    $body = $data['body'] ?? '';
    // Don't double-sanitize — body may contain intentional HTML (like <strong>)
    $bodyHtml = $body;
    $details = $data['details'] ?? [];
    $customMessage = $data['custom_message'] ?? '';
    $footerNote = $data['footer_note'] ?? '';
    $unsubUrl = $data['unsubscribe_url'] ?? '';
    $siteEmail = $cfg['site_email'] ?? '';
    $siteName = $cfg['site_name'] ?? 'Tracker';

    $rows = '';
    foreach ($details as $label => $value) {
        $rows .= "<tr><td style='padding:8px 14px;color:#8899aa;white-space:nowrap;font-size:13px;border-bottom:1px solid #1a1a2e;'>$label</td>" .
                 "<td style='padding:8px 14px;color:#e0e0e0;font-size:13px;border-bottom:1px solid #1a1a2e;'>$value</td></tr>";
    }

    $customBlock = '';
    if ($customMessage) {
        $escapedMsg = nl2br(sanitize($customMessage));
        $customBlock = <<<HTML
        <div style="margin:16px 0;padding:14px 18px;background:#0d1117;border-left:3px solid #4a9eff;border-radius:4px;">
            <p style="color:#8899aa;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;margin:0 0 8px;">Message from our team</p>
            <p style="color:#e0e0e0;margin:0;line-height:1.6;">{$escapedMsg}</p>
        </div>
HTML;
    }

    $footerNoteHtml = '';
    if ($footerNote) {
        $footerNoteHtml = '<p style="color:#8899aa;font-size:12px;margin:16px 0 0;font-style:italic;">' . sanitize($footerNote) . '</p>';
    }

    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#0a0a1a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,monospace,sans-serif;">
<div style="max-width:600px;margin:20px auto;background:#111;border:1px solid #2a2a3e;border-radius:8px;overflow:hidden;">
<div style="background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);padding:24px;text-align:center;border-bottom:1px solid #2a2a3e;">
<h2 style="color:#4a9eff;margin:0;font-size:18px;font-weight:600;">{$title}</h2>
<p style="color:#5a6a7a;margin:6px 0 0;font-size:12px;">{$siteName}</p>
</div>
<div style="padding:24px;color:#e0e0e0;">
<p style="color:#ccc;margin:0 0 16px;">{$greeting}</p>
<p style="line-height:1.7;margin:0 0 16px;">{$bodyHtml}</p>
{$customBlock}
<table style="width:100%;border-collapse:collapse;margin:20px 0;background:#0a0a1a;border-radius:6px;overflow:hidden;">{$rows}</table>
{$footerNoteHtml}
</div>
<div style="padding:16px 24px;background:#0a0a14;text-align:center;border-top:1px solid #2a2a3e;">
<a href="{$unsubUrl}" style="color:#5a6a7a;font-size:11px;text-decoration:underline;">Manage notification preferences</a>
<p style="color:#3a3a4a;font-size:11px;margin:8px 0 0;">{$siteName} &bull; {$siteEmail}</p>
</div>
</div></body></html>
HTML;
}

/**
 * Send notification when a report is permanently deleted
 */
function sendDeletionNotification(PDO $db, array $report, string $reason, array $cfg): bool {
    if (empty($report['email'])) return false;
    if (isUnsubscribed($db, $report['email'], 'status')) return false;

    $unsubUrl = getUnsubscribeUrl($report['email'], $cfg);
    $subject = "Report #{$report['id']} — Permanently Deleted";

    $details = getReportDetails($report);
    $details['Status'] = '<strong style="color:#ef4444">Permanently Deleted</strong>';

    $plain = "Dear {$report['name']},\n\n";
    $plain .= "We are writing to inform you that your report #{$report['id']} regarding \"{$report['objectTitle']}\" has been permanently deleted from our system.\n\n";
    if (!empty($reason)) {
        $plain .= "Reason for deletion:\n$reason\n\n";
    }
    $plain .= "This report, along with all associated database records (appeals, email history), has been removed. It will not be archived and will not appear in the transparency report.\n\n";
    $plain .= "--- Report Details ---\n";
    foreach ($details as $label => $value) {
        $plain .= "$label: " . strip_tags($value) . "\n";
    }
    $plain .= "\nUnsubscribe: {$unsubUrl}\n";

    $bodyHtml = "We are writing to inform you that your report #{$report['id']} regarding <strong>" . sanitize($report['objectTitle']) . "</strong> has been permanently deleted from our system.<br><br>" .
                "This report, along with all associated database records (appeals, email history), has been removed. It will not be archived and will not appear in the transparency report.";

    $html = buildEmailHtml([
        'title' => "Report #{$report['id']} — Permanently Deleted",
        'greeting' => "Dear {$report['name']},",
        'body' => $bodyHtml,
        'custom_message' => $reason ?: null,
        'details' => $details,
        'footer_note' => 'Please do not reply to this email. For any further inquiries, please submit a new report.',
        'unsubscribe_url' => $unsubUrl,
    ], $cfg);

    $sent = sendEmail($report['email'], $subject, $plain, $html, $cfg, $unsubUrl);
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_emails (report_id, to_email, subject, message, info_hash) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$report['id'], $report['email'], $subject, "Report permanently deleted. Reason: " . ($reason ?: 'None'), $report['infoHash']]);
    }
    return $sent;
}
