<?php
/**
 * The home page.
 *
 * Every section below still renders EXACTLY where it always did, with its own conditions intact —
 * the difference is that each one renders into a buffer, and includes/homelayout.php decides the
 * order the buffers are emitted in and which are dropped. Reordering by moving markup would mean
 * duplicating the conditions (tracker mode rewrites two of these sections, three more are gated by
 * their own settings), and a duplicated condition is one that goes stale.
 *
 * Capture order is source order, so a variable assigned in one block is still in scope for the
 * next — `$homeSched` is computed in "about" and used again in "features" whatever order they end
 * up being shown in.
 */
// The built-in bodies come from includes/homeblocks.php — a function, because the editor's preview
// has to build the very same bodies to paste them into a custom section. See that file.
require_once __DIR__ . '/../../includes/homeblocks.php';
require_once __DIR__ . '/../../includes/pagecontent.php';
$homeBlocks = homeBlocks($db, $cfg, $baseUrl);
$homePh = null;   // placeholders are built only if some section actually uses them

// The one place the page is assembled. A hidden section is not rendered at all rather than being
// rendered and then styled away — a display:none block is still in the source, still costs the
// queries its conditions ran, and still turns up in a text-mode browser.
//
// For every section: the operator's own text for this language if there is one (with the live
// pieces pasted in where it says {{…}}), the built-in body otherwise. The heading is drawn here,
// and only over a body that is not empty, so a section whose feature is off leaves no heading.
foreach (homeLayoutOrder($cfg) as $homeKey) {
    if (homeSectionHidden($cfg, $homeKey)) continue;
    $body = $homeBlocks[$homeKey] ?? '';
    $custom = pageContentActive($db, 'home:' . $homeKey, $cfg);
    if ($custom) {
        $homePh = $homePh ?? homePlaceholders($db, $cfg, $baseUrl, $homeBlocks);
        $text = pageContentResolveMarkers((string)$custom['body'], $cfg, $db);
        $html = richtextRender($text, (string)$custom['format'], $cfg,
                               function_exists('richtextViewerSignedIn') ? richtextViewerSignedIn($db) : false);
        $body = '<div class="rt rt-home">' . homeApplyPlaceholders($html, $homePh) . '</div>';
    }
    if (trim(strip_tags($body)) === '' && !str_contains($body, '<img') && !str_contains($body, 'home-stats-widget')) continue;
    if ($homeKey !== 'header') {
        $h = homeHeading($cfg, $homeKey);
        if ($h !== '') echo '<h2>' . sanitize($h) . '</h2>' . "\n";
    }
    echo $body;
}
?>
