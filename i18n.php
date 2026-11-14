<?php
/**
 * The scripts' dictionary, one file per language and set of prefixes (1.74.0):
 *
 *   i18n.php?l=<language>&p=<prefixes, comma-separated>&v=<content hash>
 *
 * answers `(window.I18N_DICT=window.I18N_DICT||{})["<language>.<hash>"]=<the strings>;` — a year's immutable caching
 * while `v` is that content's hash, none when it is not. Every page named its strings inline until now (52 KB of the
 * home page's 75, on every view, never cached); the page now carries only the address (langJsBridge()) and loads
 * this before assets/js/i18n.js. The decision is langJsServeRequest() in includes/lang.php; this file only sends it.
 *
 * A root file rather than an api.php endpoint on purpose, as iconpack.php: that one starts a session, opens the
 * database and runs the janitors. This reads two language files. It must be in deploy/deploy.py's top-level include
 * list, or a page names a file the server does not have (tests/lang_plural_test.php checks the list).
 */
require_once __DIR__ . '/includes/lang.php';

$r = langJsServeRequest($_GET, $_SERVER);
http_response_code($r['status']);
foreach ($r['headers'] as $h) header($h);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') exit;
echo $r['body'];
