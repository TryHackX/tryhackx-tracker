<?php
/**
 * POST admin/rich_preview — the panel's one preview of the words a moderator writes (1.75.0).
 *
 *   {"text":"…","format":"plain|bbcode|markdown"}
 *     → {success, formats: ["plain", …the site's switched-on ones], format, html, problem}
 *
 * The component every panel window shares (assets/js/admin-common.js adminRichField()) asks this for two things: which
 * formats the site accepts (so the select never offers a syntax the renderer has been told to refuse) and what the text
 * will look like. `html` is the SITE'S OWN renderer on escaped input (reportTextHtml() → richtextRender(): every
 * character of the input is escaped before a rule runs), the same call the cards and the member's notification page make,
 * so it may go into the panel's DOM; `problem` is the sentence reportTextValidate() would refuse the text with, if any
 * (too long, a picture, too many links), or null. A pure function of its input: it reads nothing and writes nothing, so
 * it asks for the panel and no more — a moderator who may reply to a report may preview the reply — and it writes no
 * audit line. (The bulk composer previews a whole MAIL, frame and all, through admin/bulk_send's own op.)
 */
requirePost();
auditSuppress();
$input = readJsonBody();
$formats = array_merge(['plain'], richtextFormats($cfg, 'report'));
$format = is_string($input['format'] ?? null) ? (string)$input['format'] : 'plain';
if (!in_array($format, $formats, true)) $format = 'plain';
// One character past the limit is read in so that "too long" is said; the preview itself is bounded too.
$text = reportTextClean($input['text'] ?? '', REPORT_TEXT_MAX + 1);
jsonResponse([
    'success' => true,
    'formats' => $formats,
    'format'  => $format,
    'html'    => reportTextHtml($text, $format, $cfg),
    'problem' => reportTextValidate($text, $format, $cfg),
]);
