<?php
/**
 * The built-in Info page (1.73.0).
 *
 * Written as data in includes/pagecontent.php (pageContentSpec('info')) and printed here by
 * pageContentHtml(): the same list is what Settings → Site pages hands back as the default text, with
 * each condition written into it as a marker, so the page a visitor reads and the one "Restore
 * built-in" gives the operator cannot say two different things. Every paragraph about an optional
 * feature stands under that feature's own condition (pageContentConditions()): an install with a
 * feature off never promises it. The words are tools/lang_src.d/info.py.
 */
echo pageContentHtml($db, $cfg, 'info', $baseUrl);
