<?php
/**
 * POST profile_bio {csrf_token, bio} — a member's own description (1.69.0, includes/profilebio.php).
 *
 *   → 200 {success, html, text, chars, max, message}
 *   → 4xx {success: false, error: <code>, message: <in the reader's language>}
 *
 * The whole decision is profileBioSaveRequest(): the order of the gates, the text rules and the write
 * live there, so tests/profile_bio_test.php can put every refusal to it without a web server. This
 * file only hands it the request — the session's account, the body, the address — and sends back
 * what it answers. `html` is the server's rendering of what was stored: the page puts exactly that in
 * place and never renders markup itself.
 */
requirePost();
$r = profileBioSaveRequest($db, $cfg, currentUser($db), readJsonBody(), getClientIp($cfg));
jsonResponse($r['body'], (int)$r['status']);
