<?php
/**
 * The built-in Terms of Service (1.73.0).
 *
 * Written as data in includes/pagecontent.php (pageContentSpec('tos')) and printed here by
 * pageContentHtml() — the same list the page editor's "Restore built-in" writes out with its
 * conditions as markers; see templates/pages/info.php. The clauses carry their own <strong>/<a>
 * markup, which comes from the dictionary (tools/lang_src.d/terms_of_service.py) — code that ships
 * with the app, not text from a visitor — and is echoed as it is.
 */
echo pageContentHtml($db, $cfg, 'tos', $baseUrl);
