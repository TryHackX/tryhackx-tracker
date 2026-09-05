<?php
/**
 * An operator's replacement for a shipped page (Settings → Site pages).
 *
 * The router points `$pageTemplate` here when `page_content` holds an enabled, non-empty override,
 * and leaves `$customPage` holding that row. The text goes through the SAME renderer as every other
 * author-written thing on the site, so an edited page cannot reach the browser through a weaker
 * sanitising path than a torrent description does.
 *
 * `rt-page` gives it the page's own type scale rather than the compact one a description gets inside
 * a card — this is a whole page, not a comment.
 */
// The [[if:…]] markers are decided HERE, on the raw text, against the settings as they are for
// this request — the renderer never sees them. This is what lets a saved page keep following the
// tracker mode the way the shipped one does.
$__pcBody = pageContentResolveMarkers((string)($customPage['body'] ?? ''), $cfg, $db);
$__pcFormat = (string)($customPage['format'] ?? 'markdown');
?>
<div class="rt rt-page">
<?= richtextRender($__pcBody, $__pcFormat, $cfg, function_exists('richtextViewerSignedIn') ? richtextViewerSignedIn($db) : false) ?>
</div>
