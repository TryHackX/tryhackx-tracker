<?php
$tagsCanDesc = userCan($db, $cfg, 'content.view') && ($cfg['wl_allow_description'] ?? '0') === '1';
$tagsCat = function_exists('factsCatalog') ? factsCatalog($cfg, $db) : [];
$tagsGroups = ['x' => __('js.filters.t_x'), 'q' => __('js.filters.t_q'), 'v' => __('js.filters.t_v'), 'h' => __('js.filters.t_h'), 'a' => __('js.filters.t_a'),
               's' => __('js.filters.t_s'), 'l' => __('js.filters.t_l'), 'g' => __('js.filters.t_g')];
?>
<h1><?= _h('tags.h1') ?></h1>
<p><?= _h('tags.intro') ?></p>
<div class="tags-page-search">
    <input type="search" id="tags-page-q" class="profile-search" maxlength="40" placeholder="<?= _h('tags.search_ph') ?>" aria-label="<?= _h('tags.search_label') ?>">
    <span class="text-muted tags-page-none" id="tags-page-none" hidden><?= _h('js.filters.tag_none') ?></span>
</div>
<?php foreach ($tagsGroups as $g => $groupLabel):
    if ($g === 'g' && !$tagsCanDesc) continue;
    $items = array_values(array_filter($tagsCat, fn($c) => $c['g'] === $g && (!isset($c['n']) || $c['n'] > 0)));
    if (!$items) continue; ?>
<section class="tags-group" data-g="<?= sanitize($g) ?>">
    <h2><?= sanitize($groupLabel) ?></h2>
    <div class="fchips tags-list">
        <?php foreach ($items as $c): ?>
        <a class="fchip fchip-<?= sanitize($g) ?>" href="<?= sanitize($baseUrl) ?>?action=search&amp;tags=<?= rawurlencode($c['id']) ?>" data-s="<?= sanitize(mb_strtolower($c['l'] . ' ' . ($c['a'] ?? '') . ' ' . $c['id'])) ?>"<?= !empty($c['c']) ? ' style="--fc: ' . sanitize($c['c']) . '"' : '' ?>><?= sanitize($c['l']) ?><?php if (isset($c['n'])): ?><span class="sf-n"><?= sanitize(langNumber($c['n'])) ?></span><?php endif; ?></a>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
