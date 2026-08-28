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

if (($cfg['wl_allow_description'] ?? '0') !== '1') {
    jsonResponse(['error' => 'Descriptions are not enabled on this tracker.'], 404);
}
if (empty($input['csrf_token']) || !verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}

// Same permission as writing one: a preview is a parser, and handing it to somebody who may not
// submit is handing out the parser for nothing.
if (!userCan($db, $cfg, 'content.submit')) {
    jsonResponse(['error' => 'Content access is required.'], 403);
}

$perMin = max(5, min(300, (int)($cfg['rate_limit_preview'] ?? 30) ?: 30));
if (!rateLimitAllow('rtpreview', ipBucket(getClientIp($cfg)), $perMin, 60)) {
    jsonResponse(['error' => 'Too many previews — wait a moment.'], 429);
}

$text = (string)($input['text'] ?? '');
$fmt  = (string)($input['format'] ?? 'bbcode');
if (!in_array($fmt, richtextFormats($cfg), true)) $fmt = richtextFormats($cfg)[0];

// Cap before parsing, not after. A megabyte of nested tags is a CPU bill, and refusing it is
// cheaper than rendering it and then deciding it was too long.
$max = richtextMaxChars($cfg);
if ($max > 0 && mb_strlen($text) > $max) {
    jsonResponse(['error' => 'That description is ' . mb_strlen($text) . ' characters; the limit is ' . $max . '.',
                  'too_long' => true, 'length' => mb_strlen($text), 'limit' => $max], 400);
}

// The limits are reported rather than enforced here: somebody still typing should see "3 of 3
// images" as they go, not have the preview refuse to draw.
$counts = richtextCount($text);
jsonResponse([
    'success' => true,
    'html'    => richtextRender($text, $fmt, $cfg),
    'format'  => $fmt,
    'length'  => mb_strlen($text),
    'limit'   => $max,
    'images'  => ['used' => $counts['images'], 'limit' => richtextMaxImages($cfg)],
    'links'   => ['used' => $counts['links'], 'limit' => richtextMaxLinks($cfg)],
    'problem' => richtextValidate($text, $fmt, $cfg),
]);
