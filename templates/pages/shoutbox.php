<?php
/**
 * ?action=shoutbox — the shoutbox with room to scroll.
 *
 * The same partial the front page draws as a block, given `shout_page_rows` instead of
 * `shout_widget_rows`. There is nothing else to this page, and that is on purpose: two renderers
 * for one box would be two sets of ids for the one script that drives them.
 *
 * ── one shape of "no" ──────────────────────────────────────────────────────────────────────────
 * The feature switched off, the operator having put the box on the front page only, and this reader
 * not being allowed to read it all render the SAME page — the shape templates/pages/profile.php
 * uses. A visitor poking at the address learns nothing about which of the three it was, and an
 * address that suddenly starts answering differently is how somebody maps a site's settings.
 */
$shoutInc = __DIR__ . '/../../includes/shout.php';
if (is_file($shoutInc)) require_once $shoutInc;

$shoutHere = function_exists('shoutEnabled') && shoutEnabled($cfg)
    && shoutPlacement($cfg) !== 'home'          // 'home' means the block, and only the block
    && shoutMayView($db, $cfg);

if (!$shoutHere):
?>
<h1><?= _h('shout.h1') ?></h1>
<p><?= _h('shout.not_found') ?></p>
<p><a class="btn btn-secondary" href="<?= $baseUrl ?>"><?= _h('common.back_home') ?></a></p>
<?php
    return;
endif;

$shoutLimit = shoutPageRows($cfg);
$shoutOnPage = true;
?>
<h1><?= _h('shout.h1') ?></h1>
<?php include __DIR__ . '/../partials/shoutbox_widget.php'; ?>
