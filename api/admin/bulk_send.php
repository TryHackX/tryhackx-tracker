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
    jsonResponse(['error' => __('api.bulk.unknown_op')], 400);
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
                  'message' => $n === 1 ? __('api.bulk.cancelled_one', ['n' => $n]) : __('api.bulk.cancelled_many', ['n' => $n])]);
}

if ($op === 'test') {
    // A copy to the site's own address, so the admin sees exactly what lands before anyone else does.
    if (!bulkMailEnabled($cfg)) jsonResponse(['error' => __('api.bulk.mail_off_first')], 409);
    $to = trim((string)($cfg['site_email'] ?? ''));
    if ($to === '') jsonResponse(['error' => __('api.bulk.no_site_email')], 400);
    if (trim($subject) === '' || trim($body) === '') jsonResponse(['error' => __('api.bulk.subject_body_required')], 400);
    $unsub = getUnsubscribeUrl($to, $cfg);
    $html = buildEmailHtml(['title' => $subject, 'greeting' => '',
                            'body' => bulkBodyHtml($body, $format, $cfg), 'unsubscribe_url' => $unsub], $cfg);
    $ok = sendEmail($to, $subject, $body, $html, $cfg, $unsub);
    jsonResponse(['success' => $ok, 'to' => $to,
                  'message' => $ok ? __('api.bulk.test_sent', ['to' => $to]) : __('api.bulk.mailer_refused')]);
}

// ── queue ───────────────────────────────────────────────────────────────────
requireAdminReauth((string)($input['password'] ?? ''), $cfg);

$wantMail   = !empty($input['email']);
$wantNotify = !empty($input['notify']);
if (!$wantMail && !$wantNotify) {
    jsonResponse(['error' => __('api.bulk.choose_channel')], 400);
}
if ($wantMail && !bulkMailEnabled($cfg)) {
    jsonResponse(['error' => __('api.bulk.mail_off_notify_only')], 409);
}

$out = ['success' => true, 'notified' => 0, 'queued' => 0, 'skipped' => 0, 'batch_id' => null];

if ($wantNotify) {
    $title = mb_substr(trim($subject), 0, 190);
    if ($title === '') jsonResponse(['error' => __('api.bulk.subject_required')], 400);
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
if ($out['notified']) $parts[] = $out['notified'] === 1 ? __('api.bulk.notified_one', ['n' => $out['notified']]) : __('api.bulk.notified_many', ['n' => $out['notified']]);
if ($out['queued']) {
    $mins = (int)ceil($out['queued'] / max(1, bulkMailPerTick($cfg)));
    $eta = $mins <= 1 ? __('api.bulk.eta_minute') : __('api.bulk.eta_minutes', ['m' => $mins]);
    $parts[] = $out['queued'] === 1 ? __('api.bulk.queued_one', ['n' => $out['queued'], 'eta' => $eta]) : __('api.bulk.queued_many', ['n' => $out['queued'], 'eta' => $eta]);
}
if ($out['skipped']) $parts[] = __('api.bulk.skipped', ['n' => $out['skipped']]);
$out['message'] = $parts ? ucfirst(implode(', ', $parts)) . '.' : __('api.bulk.nothing_to_do');
jsonResponse($out);
