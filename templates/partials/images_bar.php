<?php $imgId = sanitize((string)($imgFor ?? '')); ?>
<div class="rt-images" id="<?= $imgId ?>-images">
    <div class="rt-images-bar" role="group" aria-labelledby="<?= $imgId ?>-images-head">
        <span class="rt-images-head" id="<?= $imgId ?>-images-head"><i class="bi bi-image" aria-hidden="true"></i> <?= _h('rt.images') ?></span>
        <button type="button" class="rt-images-drop" id="<?= $imgId ?>-images-add" aria-describedby="<?= $imgId ?>-images-hint"><i class="bi bi-cloud-arrow-up rt-images-drop-icon" aria-hidden="true"></i> <span class="rt-images-drop-text"><?= _h('rt.images_drop') ?></span></button>
        <button type="button" class="btn btn-secondary btn-small rt-images-toggle" id="<?= $imgId ?>-images-mine" aria-expanded="false" aria-controls="<?= $imgId ?>-images-panel"><i class="bi bi-collection" aria-hidden="true"></i> <?= _h('rt.images_mine') ?></button>
        <input type="file" id="<?= $imgId ?>-images-file" accept="image/*" multiple hidden>
        <span class="rt-images-hint" id="<?= $imgId ?>-images-hint"><?= _h('rt.images_hint') ?></span>
    </div>
    <ul class="rt-images-ups" id="<?= $imgId ?>-images-ups" hidden></ul>
    <div class="rt-images-msg" id="<?= $imgId ?>-images-msg" role="alert"></div>
    <span class="rt-images-sr" id="<?= $imgId ?>-images-say" aria-live="polite"></span>
    <div class="rt-images-panel" id="<?= $imgId ?>-images-panel" role="region" aria-labelledby="<?= $imgId ?>-images-mine" data-esc-layer hidden></div>
</div>
