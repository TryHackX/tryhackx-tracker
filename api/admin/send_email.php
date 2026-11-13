<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
// The team's words to the reporter (1.75.0): lines and a format — plain, or BBCode / Markdown where the site has them
// on —, up to REPORT_TEXT_MAX characters. One character past the limit is read in so that "too long" is said.
$message = reportTextClean($input['message'] ?? '', REPORT_TEXT_MAX + 1);
$format = reportTextFormat($input['format'] ?? 'plain', $cfg);

if ($id < 1) {
    jsonResponse(['error' => __('api.report.invalid_id')], 400);
}
if ($message === '') {
    jsonResponse(['error' => __('api.report_text.required')], 400);
}
if (($why = reportTextValidate($message, $format, $cfg)) !== null) {
    jsonResponse(['error' => $why], 400);
}

$result = sendCustomEmail($db, $id, $message, $cfg, null, $format);

if ($result) {
    jsonResponse(['success' => true]);
} else {
    jsonResponse(['error' => __('api.report.email_send_failed')], 500);
}
