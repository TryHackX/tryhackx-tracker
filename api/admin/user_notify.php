<?php
// Admin: send a custom in-app notification (optionally an email copy). Body: {id, title, body?, format?, email?}
//
// `format` (1.75.0): plain | bbcode | markdown, as the composer's format select offers (the site's switches). A formatted
// message is kept as its source in `rich` and drawn by the account page (api/user_notifications.php); the e-mail copy of
// it is rendered for a mail (mailRichParts()), its text half made from that HTML.
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$u = userFindById($db, $id);
if (!$u) jsonResponse(['error' => __('api.users.not_found')], 404);
$title = mb_substr(trim((string)($input['title'] ?? '')), 0, 190);
if ($title === '') jsonResponse(['error' => __('api.users.title_required')], 400);
$format = reportTextFormat($input['format'] ?? 'plain', $cfg);
if ($format === 'plain') {
    $body = mb_substr(trim((string)($input['body'] ?? '')), 0, 5000);
    userNotify($db, $id, 'admin', $title, $body);
    $mailHtml = '';
} else {
    // 5 000 characters, as before; a formatted one that is longer is refused rather than cut in the middle of a tag — and
    // held to the rules of every other text a moderator writes (reportTextValidate(): no pictures, five links at most). A
    // picture here would be fetched, from a host the moderator names, by the member's browser and their mail client.
    $body = reportTextClean($input['body'] ?? '', 5001);
    if (($why = reportTextValidate($body, $format, $cfg, 5000)) !== null) jsonResponse(['error' => $why], 400);
    userNotify($db, $id, 'admin', $title, '', null, null, $body !== '' ? $body : null, $body !== '' ? $format : null);
    [$mailHtml, $body] = mailRichParts($body, $format, $cfg);
}
$mailed = false;
if (!empty($input['email']) && trim((string)$u['email']) !== '') {
    userNotifyMail($db, $cfg, $u, ($cfg['site_name'] ?? 'Tracker') . ' — ' . $title, $body !== '' ? $body : $title,
                   $mailHtml !== '' ? ['body_html' => $mailHtml] : []);
    $mailed = true;
}
jsonResponse(['success' => true, 'mailed' => $mailed]);
