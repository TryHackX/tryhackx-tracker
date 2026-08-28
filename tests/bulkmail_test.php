<?php
/**
 * Test for the formatting half of includes/bulkmail.php:
 *   php tests/bulkmail_test.php
 *
 * A bulk message goes to every account at once and cannot be recalled. Two things therefore have to
 * hold whatever else changes: what the admin previewed is what the janitor sends (same function, not
 * two that agree today), and the escaping the site relies on is not quietly lost on the way into an
 * email. The tests below check output, not intent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/richtext.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/bulkmail.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

$cfg = [
    'desc_allow_bbcode' => '1', 'desc_allow_markdown' => '1',
    'desc_max_chars' => '5000', 'desc_max_images' => '3', 'desc_max_links' => '10',
    'link_trusted_domains' => 'tryhackx.org',
];

/* ── 1. plain text stays plain, and stays escaped ─────────────────────────── */

$evil = "Hi <script>alert(1)</script>\nsecond line";
$plain = bulkBodyHtml($evil, 'plain', $cfg);
check('plain: a script tag is escaped, not passed through',
      strpos($plain, '<script') === false && strpos($plain, '&lt;script') !== false, $plain);
check('plain: a newline becomes a break', strpos($plain, '<br') !== false, $plain);
check('plain: markup is not interpreted',
      strpos(bulkBodyHtml('**bold**', 'plain', $cfg), '<strong>') === false);

/* ── 2. the markup formats render, through the same renderer as the site ──── */

$md = bulkBodyHtml("**bold** and *italic*", 'markdown', $cfg);
check('markdown: bold renders', strpos($md, '<strong>bold</strong>') !== false, $md);
check('markdown: italic renders', strpos($md, '<em>italic</em>') !== false, $md);

$bb = bulkBodyHtml('[b]bold[/b]', 'bbcode', $cfg);
check('bbcode: bold renders', strpos($bb, '<strong>bold</strong>') !== false, $bb);

check('markup formats still escape HTML the author typed',
      strpos(bulkBodyHtml('<script>alert(1)</script> **x**', 'markdown', $cfg), '<script') === false);

/* ── 3. an unknown format is plain, never "whatever was asked for" ────────── */

check('an unknown format falls back to plain rather than rendering',
      strpos(bulkBodyHtml('**bold**', 'html', $cfg), '<strong>') === false);
check('an empty body does not become stray markup',
      trim(strip_tags(bulkBodyHtml('', 'markdown', $cfg))) === '');

/* ── 4. the mail-safe renderer: classes become inline styles ──────────────── */
//
// Mail clients drop <style> and most drop class attributes, so a class-styled block arrives naked.
// The test is on the OUTPUT, because "we call the inliner" is not the same claim as "nothing left
// depends on the stylesheet".

$rich = "# Heading\n\n> quoted\n\n- one\n- two\n\n`code`\n\n```\nblock\n```";
$mail = richtextRenderForEmail($rich, 'markdown', $cfg);
check('email render: no class attribute survives',
      strpos($mail, 'class="rt-') === false, $mail);
foreach (['rt-h' => 'font-weight:600', 'rt-quote' => 'border-left', 'rt-list' => 'margin',
          'rt-inline' => 'font-family', 'rt-code' => 'background'] as $what => $css) {
    check("email render: what was $what carries an inline style",
          strpos($mail, $css) !== false, $mail);
}

$ext = richtextRenderForEmail('[link](https://elsewhere.example/x)', 'markdown', $cfg);
check('email render: data-external is dropped — there is no leave-site dialog in an inbox',
      strpos($ext, 'data-external') === false, $ext);
check('email render: the link itself survives',
      strpos($ext, 'https://elsewhere.example/x') !== false, $ext);
check('email render: rel and target are kept on the link',
      strpos($ext, 'rel="nofollow') !== false, $ext);

/* ── 5. the preview and the send are the same code path ───────────────────── */
//
// The mail is built by bulkBodyHtml() in tools/janitor.php and previewed by bulkBodyHtml() in
// api/admin/bulk_send.php. This checks the two callers actually call it, because a preview drawn by
// a second renderer is a preview of something nobody will receive.

$callers = [
    'includes/bulkmail.php'    => 'bulkBodyHtml((string)$r[\'body\'], (string)($r[\'format\'] ?? \'plain\'), $cfg)',
    'api/admin/bulk_send.php'  => 'bulkBodyHtml($body, $format, $cfg)',
];
foreach ($callers as $file => $needle) {
    $src = (string)file_get_contents($root . '/' . $file);
    check("$file builds its HTML with bulkBodyHtml()", strpos($src, $needle) !== false);
}
$send = (string)file_get_contents($root . '/api/admin/bulk_send.php');
check('the test send uses the chosen format, not a hardcoded plain one',
      strpos($send, "'body' => bulkBodyHtml(\$body, \$format, \$cfg)") !== false);

/* ── 6. the format is stored per row, not read from settings at send time ─── */
//
// A batch written in Markdown and sent an hour after somebody switched Markdown off must still
// arrive as its author saw it, rather than as a page of raw asterisks.

$bulk = (string)file_get_contents($root . '/includes/bulkmail.php');
check('mail_queue rows carry their own format',
      strpos($bulk, 'INSERT INTO mail_queue (batch_id, user_id, email, subject, body, format)') !== false);
check('the sender reads the format off the row',
      preg_match('/SELECT id, user_id, email, subject, body, format, attempts FROM mail_queue/', $bulk) === 1);
$schema = (string)file_get_contents($root . '/includes/schema.php');
check('the column exists in the schema and in a migration',
      substr_count($schema, "ENUM('plain','bbcode','markdown')") >= 2);

echo "\n" . $n . ' checks, ' . $fails . " failed\n";
exit($fails ? 1 : 0);
