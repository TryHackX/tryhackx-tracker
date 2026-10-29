<?php
$tagsCanDesc = userCan($db, $cfg, 'content.view') && ($cfg['wl_allow_description'] ?? '0') === '1';
$tagsCat = function_exists('factsCatalog') ? factsCatalog($cfg, $db) : [];
$tagsMe = usersEnabled($cfg) ? currentUser($db) : null;
$tagsFavOn = $tagsMe && function_exists('tagsFavList') && userCan($db, $cfg, 'tags.favourite');
$tagsFavs = $tagsFavOn ? array_flip(tagsFavList($db, (int)$tagsMe['id'])) : [];
$tagsCatOn = $tagsMe && function_exists('tagsCatReady') && tagsCatReady($db) && userCan($db, $cfg, 'tags.categorize');
$tagsGroups = ['q' => __('js.filters.t_q'), 'v' => __('js.filters.t_v'), 'h' => __('js.filters.t_h'), 'a' => __('js.filters.t_a'),
               's' => __('js.filters.t_s'), 'l' => __('js.filters.t_l'), 'g' => __('js.filters.t_g')];
$tagsItems = array_values(array_filter($tagsCat, fn($c) => ($c['g'] !== 'g' || $tagsCanDesc) && (!isset($c['n']) || $c['n'] > 0)));
$tagsSections = [];
$tagsPopMax = function_exists('tagsPopularMax') ? tagsPopularMax($cfg) : 0;
$tagsPop = array_values(array_filter($tagsItems, fn($c) => isset($c['n']) && $c['g'] !== 't'));
usort($tagsPop, fn($a, $b) => $b['n'] <=> $a['n']);
if ($tagsPopMax > 0 && $tagsPop) $tagsSections[] = ['popular', __('tags.popular_h2'), array_slice($tagsPop, 0, $tagsPopMax), 18];
$tagsOwn = [];
foreach ($tagsItems as $c) {
    if ($c['g'] !== 'x' || (isset($c['kg']) && ($c['kg'] !== 'g' || $tagsCanDesc))) continue;
    $k = $c['k'] ?? '';
    $tagsOwn[$k] ??= [$k === '' ? 200000 : (int)($c['ko'] ?? 0), $k === '' ? __('js.filters.t_x') : (string)($c['kl'] ?? $k), []];
    $tagsOwn[$k][2][] = $c;
}
uasort($tagsOwn, fn($a, $b) => $a[0] <=> $b[0]);
foreach ($tagsOwn as $k => [, $label, $items]) $tagsSections[] = [$k === '' ? 'x' : 'x-' . ltrim($k, '~'), $label, $items, 0];
foreach ($tagsGroups as $g => $label) {
    if ($g === 'g' && !$tagsCanDesc) continue;
    $items = array_values(array_filter($tagsItems, fn($c) => $c['g'] === $g || ($c['kg'] ?? '') === $g));
    if ($items) $tagsSections[] = [$g, $label, $items, 0];
}
?>
<h1><?= _h('tags.h1') ?></h1>
<p><?= _h('tags.intro') ?></p>
<div class="tags-page-search">
    <input type="search" id="tags-page-q" class="profile-search" maxlength="40" placeholder="<?= _h('tags.search_ph') ?>" aria-label="<?= _h('tags.search_label') ?>">
    <span class="text-muted tags-page-none" id="tags-page-none" hidden><?= _h('js.filters.tag_none') ?></span>
</div>
<?php if ($tagsCatOn): ?>
<div id="tags-cat-data" hidden data-cats="<?= sanitize(json_encode(array_map(fn($c) => ['k' => $c['slug'], 'l' => $c['l']], tagsCatList($db)))) ?>"></div>
<?php endif; ?>
<?php if ($tagsFavOn): $tagsMine = tagsFavChips($db, array_keys($tagsFavs)); ?>
<section class="tags-group tags-mine" data-g="mine">
    <h2><?= _h('tags.mine_h2') ?></h2>
    <p class="text-muted tags-mine-empty"<?= $tagsMine ? ' hidden' : '' ?>><?= _h('tags.mine_empty') ?></p>
    <div class="fchips tags-list">
        <?php foreach ($tagsMine as $c): ?>
        <a class="fchip fchip-<?= sanitize($c['g']) ?>" href="<?= sanitize($baseUrl) ?>?action=search&amp;tags=<?= rawurlencode($c['id']) ?>" data-id="<?= sanitize($c['id']) ?>" data-s="<?= sanitize(mb_strtolower($c['l'] . ' ' . $c['id'])) ?>"<?= !empty($c['c']) ? ' style="--fc: ' . sanitize($c['c']) . '"' : '' ?>><?= sanitize($c['l']) ?></a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php foreach ($tagsSections as [$key, $groupLabel, $items, $page]): ?>
<section class="tags-group" data-g="<?= sanitize($key) ?>"<?= $page ? ' data-page="' . (int)$page . '"' : '' ?>>
    <h2><?= sanitize($groupLabel) ?></h2>
    <div class="fchips tags-list">
        <?php foreach ($items as $c): $cOwn = $tagsCatOn && $c['g'] === 'x'; $cPair = $tagsFavOn || $cOwn; ?>
        <?php if ($cPair): ?><span class="tag-pair"><?php endif; ?><a class="fchip fchip-<?= sanitize($c['g']) ?>" href="<?= sanitize($baseUrl) ?>?action=search&amp;tags=<?= rawurlencode($c['id']) ?>" data-s="<?= sanitize(mb_strtolower($c['l'] . ' ' . ($c['a'] ?? '') . ' ' . $c['id'])) ?>"<?= !empty($c['c']) ? ' style="--fc: ' . sanitize($c['c']) . '"' : '' ?>><?= sanitize($c['l']) ?><?php if (isset($c['n'])): ?><span class="sf-n"><?= sanitize(langNumber($c['n'])) ?></span><?php endif; ?></a><?php if ($tagsFavOn): $fOn = isset($tagsFavs[$c['id']]); ?><button type="button" class="tag-fav" data-id="<?= sanitize($c['id']) ?>" aria-pressed="<?= $fOn ? 'true' : 'false' ?>" title="<?= _h($fOn ? 'js.tags.fav_off' : 'js.tags.fav_on') ?>" aria-label="<?= _h($fOn ? 'js.tags.fav_off' : 'js.tags.fav_on') ?>"><i class="bi <?= $fOn ? 'bi-star-fill' : 'bi-star' ?>" aria-hidden="true"></i></button><?php endif; ?><?php if ($cOwn): ?><button type="button" class="tag-cat" data-id="<?= sanitize($c['id']) ?>" data-name="<?= sanitize($c['l']) ?>" data-k="<?= sanitize(ltrim((string)($c['k'] ?? ''), '~')) ?>" title="<?= _h('tags.cat_suggest') ?>" aria-label="<?= _h('tags.cat_suggest_for', ['name' => $c['l']]) ?>"><i class="bi bi-folder2" aria-hidden="true"></i></button><?php endif; ?><?php if ($cPair): ?></span><?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
