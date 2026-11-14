<?php
/**
 * POST admin/bulk_send — write to an audience.
 *
 *   {"op":"preview","audience":{...}}
 *   {"op":"render","subject":"…","body":"…","format":"markdown|html|…","frame":"site|none"}
 *                                                          — the WHOLE mail document, as it will be sent (1.75.0: for the
 *                                                            composer's sandboxed preview frame)
 *   {"op":"test","subject":"…","body":"…","format":"…","frame":"…"} — one copy, to the site's own address
 *   {"op":"queue","password":"…","audience":{…},"subject":"…","body":"…","format":"…","frame":"…","notify":bool,"email":bool}
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

// The audit log records what queues, cancels or sends mail — not a read (1.69.0). The router logs
// every POST to an admin endpoint, and this one answers four reads by POST as well (the preview of an
// audience, the rendered body, a batch's progress, the recent batches): opening the Users page's
// bulk-mail tab wrote two `bulk.queue` lines with nothing queued. Cancelling and the test copy are
// lines of their own names, so a filter on bulk.queue finds only what was queued.
if (in_array($op, ['render', 'preview', 'batches', 'status'], true)) auditSuppress();
elseif ($op === 'cancel') auditNote(['action' => 'bulk.cancel']);
elseif ($op === 'test') auditNote(['action' => 'bulk.test']);

// 'plain' is always available; the two markup formats are the ones the site has switched on, so the
// composer cannot offer a syntax the renderer has been told not to accept. 'html' (1.75.0) is a campaign, offered
// where the server can parse HTML at all (bulkFormats()).
$bulkFormats = bulkFormats($cfg);
$format = (string)($input['format'] ?? 'plain');
if (!in_array($format, $bulkFormats, true)) $format = 'plain';
// Whether the site's frame wraps the message (1.75.0): a campaign usually brings its own design.
$frame = ($input['frame'] ?? 'site') === 'none' ? 'none' : 'site';
// The address in a preview's and a test copy's footer: the site's own, so nothing of a member's is ever used for it.
$ownAddress = trim((string)($cfg['site_email'] ?? '')) !== '' ? trim((string)$cfg['site_email']) : 'preview@example.com';

if ($op === 'render') {
    // The preview is the REAL mail document — the frame (or none), the body, the footer — made by the SAME function the
    // janitor and the test copy use (bulkMailParts()). A preview drawn by different code is a guess about the mail, and
    // the whole point of previewing is not to guess. The composer puts it in a sandboxed <iframe srcdoc>, never in the
    // panel's own DOM: a campaign's HTML is the one thing here that is not text the site wrote.
    $parts = bulkMailParts($subject !== '' ? $subject : '…', $body, $format, $frame, $cfg, getUnsubscribeUrl($ownAddress, $cfg));
    // `problem` is what the composer shows under the box: a refusal (queueing would say the same), else — 1.75.0 — the
    // note that a deeply nested campaign came out flattened, which does not stop it being sent.
    jsonResponse(['success' => true, 'format' => $format, 'formats' => $bulkFormats, 'frame' => $frame,
                  'html' => $parts['html'], 'text' => $parts['text'],
                  'problem' => bulkBodyProblem(trim($body), $format, $cfg, $frame, $parts) ?? bulkBodyWarning($parts)]);
}

if ($op === 'preview') {
    jsonResponse(['success' => true, 'enabled' => bulkMailEnabled($cfg), 'formats' => $bulkFormats]
                 + bulkPreview($db, $cfg, $audience));
}

if ($op === 'batches') {
    jsonResponse(['success' => true, 'batches' => bulkRecentBatches($db), 'depth' => bulkQueueDepth($db),
                  'enabled' => bulkMailEnabled($cfg), 'per_minute' => bulkMailPerTick($cfg), 'daily_cap' => bulkMailDailyCap($cfg)]);
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
    if (($problem = bulkBodyProblem(trim($body), $format, $cfg, $frame)) !== null) jsonResponse(['error' => $problem], 400);
    $unsub = getUnsubscribeUrl($to, $cfg);
    // The same two halves the janitor sends (bulkMailParts()), frame and all.
    $parts = bulkMailParts($subject, trim($body), $format, $frame, $cfg, $unsub);
    $ok = sendEmail($to, $subject, $parts['text'], $parts['html'], $cfg, $unsub);
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

// The cap on the body, an HTML with nothing in it and a finished document past the size Gmail shows: asked before ANYTHING
// is written, so a refusal leaves no half of it — and for every channel, since an in-app notification of the same message
// is bounded by the same check (its cleaned HTML is never larger than the mail's).
if (($problem = bulkBodyProblem(trim($body), $format, $cfg, $frame)) !== null) jsonResponse(['error' => $problem], 400);

if ($wantNotify) {
    $title = mb_substr(trim($subject), 0, 190);
    if ($title === '') jsonResponse(['error' => __('api.bulk.subject_required')], 400);
    $out['notified'] = bulkNotify($db, $audience, $title, $body, $format);
}

if ($wantMail) {
    $r = bulkQueue($db, $cfg, $audience, $subject, $body, $format, $frame);
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
