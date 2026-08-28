<?php
/**
 * POST admin/bulk_send — write to an audience.
 *
 *   {"op":"preview","audience":{...}}
 *   {"op":"render","body":"…","format":"markdown"}         — what the HTML half will look like
 *   {"op":"test","subject":"…","body":"…"}                 — one copy, to the admin's own address
 *   {"op":"queue","password":"…","audience":{…},"subject":"…","body":"…","notify":bool,"email":bool}
 *   {"op":"status","batch_id":"…"} | {"op":"batches"} | {"op":"cancel","password":"…","batch_id":"…"}
 *
 * Nothing is sent from here. Queue writes rows; the janitor sends them a few a minute. That is not
 * politeness, it is the difference between a domain that delivers password resets and one that does
 * not: this server has no relay in front of `mail()`, and a burst from an address that normally
 * sends a handful a day is exactly what gets a domain filed under bulk.
 *
 * The password is required to queue, because a message to every account is not undoable. Cancelling
 * stops what has not left yet; it cannot recall what has.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
if (!in_array($op, ['preview', 'render', 'test', 'queue', 'status', 'batches', 'cancel'], true)) {
    jsonResponse(['error' => 'Unknown operation'], 400);
}

$audience = (array)($input['audience'] ?? []);
$subject  = (string)($input['subject'] ?? '');
$body     = (string)($input['body'] ?? '');

// 'plain' is always available; the two markup formats are the ones the site has switched on, so the
// composer cannot offer a syntax the renderer has been told not to accept.
$bulkFormats = array_merge(['plain'], richtextFormats($cfg));
$format = (string)($input['format'] ?? 'plain');
if (!in_array($format, $bulkFormats, true)) $format = 'plain';

if ($op === 'render') {
    // The preview goes through the SAME function the janitor will use. A preview drawn by different
    // code is a guess about the mail, and the whole point of previewing is not to guess.
    jsonResponse(['success' => true, 'format' => $format, 'formats' => $bulkFormats,
                  'html' => bulkBodyHtml($body, $format, $cfg)]);
}

if ($op === 'preview') {
    jsonResponse(['success' => true, 'enabled' => bulkMailEnabled($cfg), 'formats' => $bulkFormats]
                 + bulkPreview($db, $cfg, $audience));
}

if ($op === 'batches') {
    jsonResponse(['success' => true, 'batches' => bulkRecentBatches($db), 'depth' => bulkQueueDepth($db),
                  'enabled' => bulkMailEnabled($cfg), 'per_minute' => bulkMailPerTick($cfg)]);
}

if ($op === 'status') {
    $id = preg_replace('/[^a-f0-9]/', '', (string)($input['batch_id'] ?? ''));
    if ($id === '') jsonResponse(['error' => 'batch_id required'], 400);
    jsonResponse(['success' => true] + bulkBatchStatus($db, $id));
}

if ($op === 'cancel') {
    requireAdminReauth((string)($input['password'] ?? ''), $cfg);
    $id = preg_replace('/[^a-f0-9]/', '', (string)($input['batch_id'] ?? ''));
    if ($id === '') jsonResponse(['error' => 'batch_id required'], 400);
    $n = bulkCancelBatch($db, $id);
    jsonResponse(['success' => true, 'cancelled' => $n,
                  'message' => $n . ' message' . ($n === 1 ? '' : 's') . ' that had not gone out yet '
                             . 'will not be sent. Anything already delivered cannot be recalled.']);
}

if ($op === 'test') {
    // A copy to the site's own address, so the admin sees exactly what lands before anyone else does.
    if (!bulkMailEnabled($cfg)) jsonResponse(['error' => 'Bulk mail is off. Turn it on in Settings first.'], 409);
    $to = trim((string)($cfg['site_email'] ?? ''));
    if ($to === '') jsonResponse(['error' => 'No site email address is configured to send the test to.'], 400);
    if (trim($subject) === '' || trim($body) === '') jsonResponse(['error' => 'A subject and a message are both required.'], 400);
    $unsub = getUnsubscribeUrl($to, $cfg);
    $html = buildEmailHtml(['title' => $subject, 'greeting' => '',
                            'body' => bulkBodyHtml($body, $format, $cfg), 'unsubscribe_url' => $unsub], $cfg);
    $ok = sendEmail($to, $subject, $body, $html, $cfg, $unsub);
    jsonResponse(['success' => $ok, 'to' => $to,
                  'message' => $ok ? 'Sent one copy to ' . $to . '.' : 'The mailer refused it.']);
}

// ── queue ───────────────────────────────────────────────────────────────────
requireAdminReauth((string)($input['password'] ?? ''), $cfg);

$wantMail   = !empty($input['email']);
$wantNotify = !empty($input['notify']);
if (!$wantMail && !$wantNotify) {
    jsonResponse(['error' => 'Choose at least one: an email, an in-app notification, or both.'], 400);
}
if ($wantMail && !bulkMailEnabled($cfg)) {
    jsonResponse(['error' => 'Bulk mail is off. Turn it on in Settings, or send the notification only.'], 409);
}

$out = ['success' => true, 'notified' => 0, 'queued' => 0, 'skipped' => 0, 'batch_id' => null];

if ($wantNotify) {
    $title = mb_substr(trim($subject), 0, 190);
    if ($title === '') jsonResponse(['error' => 'A subject is required for the notification.'], 400);
    $out['notified'] = bulkNotify($db, $audience, $title, $body);
}

if ($wantMail) {
    $r = bulkQueue($db, $cfg, $audience, $subject, $body, $format);
    if (!empty($r['error'])) jsonResponse(['error' => $r['error']], 400);
    $out['queued']   = $r['queued'];
    $out['skipped']  = $r['skipped'];
    $out['batch_id'] = $r['batch_id'];
}

$parts = [];
if ($out['notified']) $parts[] = $out['notified'] . ' notification' . ($out['notified'] === 1 ? '' : 's') . ' delivered';
if ($out['queued']) {
    $mins = (int)ceil($out['queued'] / max(1, bulkMailPerTick($cfg)));
    $parts[] = $out['queued'] . ' email' . ($out['queued'] === 1 ? '' : 's') . ' queued — about '
             . ($mins <= 1 ? 'a minute' : $mins . ' minutes') . ' to go out';
}
if ($out['skipped']) $parts[] = $out['skipped'] . ' skipped (no address, opted out, or unsubscribed)';
$out['message'] = $parts ? ucfirst(implode(', ', $parts)) . '.' : 'Nothing to do.';
jsonResponse($out);
