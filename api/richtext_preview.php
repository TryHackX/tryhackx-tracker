<?php
/**
 * POST richtext_preview — what a description will look like, before it is submitted.
 *
 * The renderer stays on the SERVER. It is the only place that can guarantee what comes out, and
 * shipping the raw text to the browser to be turned into HTML there would move that guarantee to
 * the least trustworthy place in the system. So the preview is a round trip: the same function, the
 * same settings, the same output the visitor would see.
 *
 * It is also a free parser for anyone who asks, so: rate-limited per address, length-capped before
 * anything is parsed, and it writes nothing anywhere.
 */
requirePost();
$input = readJsonBody();

if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => __('api.csrf.invalid')], 403);
}

/**
 * WHAT is being previewed decides who may preview it.
 *
 * The same markup is written in two places now — a torrent's description and a private message —
 * and they are not the same permission. Gating a message on "may submit content" told a member who
 * may write to people but not upload that their own message could not be shown to them; gating it
 * on the whitelist's description switch answered 404 on a tracker that has descriptions off and
 * messages on. One renderer, one rate limit, and the gate that matches the text.
 */
$for = in_array($input['for'] ?? '', ['message', 'shout'], true) ? (string)$input['for'] : 'description';
if ($for === 'message') {
    if (!pmEnabled($cfg)) jsonResponse(['error' => 'pm_disabled'], 404);
    if (!userCan($db, $cfg, 'pm.send')) jsonResponse(['error' => __('api.content.access_required')], 403);
} elseif ($for === 'shout') {
    // A third writer, a third gate (1.58.0). Same reasoning as the message one: the permission that
    // matches the text, not the one that happens to be nearby.
    if (!function_exists('shoutEnabled') || !shoutEnabled($cfg)) jsonResponse(['error' => 'disabled'], 404);
    if (!userCan($db, $cfg, 'shout.post')) jsonResponse(['error' => __('api.content.access_required')], 403);
} else {
    if (($cfg['wl_allow_description'] ?? '0') !== '1') {
        jsonResponse(['error' => __('api.content.descriptions_disabled')], 404);
    }
    // Same permission as writing one: a preview is a parser, and handing it to somebody who may not
    // submit is handing out the parser for nothing.
    if (!userCan($db, $cfg, 'content.submit')) {
        jsonResponse(['error' => __('api.content.access_required')], 403);
    }
}

$perMin = max(5, min(300, (int)($cfg['rate_limit_preview'] ?? 30) ?: 30));
if (!rateLimitAllow('rtpreview', ipBucket(getClientIp($cfg)), $perMin, 60)) {
    jsonResponse(['error' => __('api.content.too_many_previews')], 429);
}

$text = (string)($input['text'] ?? '');
$fmt  = (string)($input['format'] ?? 'bbcode');
// The syntaxes THIS writer may choose from. A shout's are the room's (shoutFormatChoices(): both, or
// 'plain' when the room formats nothing) — not the description switches, which used to turn a
// Markdown shout into BBCode here on a site that allows Markdown only in the room (1.67.0).
$fmtChoices = $for === 'shout' ? shoutFormatChoices($cfg) : richtextFormats($cfg);
if (!in_array($fmt, $fmtChoices, true)) $fmt = $fmtChoices[0];

// Cap before parsing, not after. A megabyte of nested tags is a CPU bill, and refusing it is
// cheaper than rendering it and then deciding it was too long.
// A message is capped by the message setting, a description by the description one — the counter
// under the box has to say the number the send would actually be judged against.
$max = $for === 'message' ? pmMaxChars($cfg)
     : ($for === 'shout' ? shoutMaxChars($cfg) : richtextMaxChars($cfg));
if ($max > 0 && mb_strlen($text) > $max) {
    jsonResponse(['error' => __('api.content.description_too_long', ['length' => mb_strlen($text), 'limit' => $max]),
                  'too_long' => true, 'length' => mb_strlen($text), 'limit' => $max], 400);
}

// The limits are reported rather than enforced here: somebody still typing should see "3 of 3
// images" as they go, not have the preview refuse to draw. Counted in the syntax the text is written
// in — counted as BBCode, a Markdown link was "0 links" (1.67.0).
$counts = $fmt === 'plain' ? ['images' => 0, 'links' => 0] : richtextCount($text, $fmt, $cfg);
if ($for === 'shout') {
    // A SHOUT previews through the room's own pipeline and its own rules (1.67.0): shoutBodyHtml() is
    // what every line in the room is drawn with — hidden blocks shown, pictures openable, mentions and
    // emotes — and shoutBodyProblem() is exactly what shoutPost() would refuse it for. So whatever the
    // room does not support comes out here the way it would come out once said, and the sentence
    // under the box is the one pressing Enter would get, with the room's length limit in it.
    $me = currentUser($db);
    $html = shoutBodyHtml($text, $fmt, $cfg, shoutRenderContext($db, $cfg, is_array($me) ? $me : [], [$text]));
    $problem = shoutBodyProblem($cfg, $text, $fmt);
} else {
    // The author previewing their own text sees their own hidden block; that is the only way to
    // check it before submitting. A message is judged against the message limit, as its send is.
    $html = richtextRender($text, $fmt, $cfg, richtextViewerSignedIn($db));
    $problem = richtextValidate($text, $fmt, $for === 'message' ? array_merge($cfg, ['desc_max_chars' => (string)$max]) : $cfg);
}
jsonResponse([
    'success' => true,
    'html'    => $html,
    'format'  => $fmt,
    'length'  => mb_strlen($text),
    'limit'   => $max,
    'images'  => ['used' => $counts['images'], 'limit' => richtextMaxImages($cfg)],
    'links'   => ['used' => $counts['links'], 'limit' => richtextMaxLinks($cfg)],
    'problem' => $problem,
]);
