<h1><?= _h('tos.h1') ?></h1>
<p><?= _h('tos.intro') ?></p>

<?php // The list items carry their own <strong> markup, so they are echoed raw rather than escaped
      // — they come from the dictionary, which is code that ships with the app, not from a visitor. ?>
<ol class="tos-list">
    <li><?= __('tos.r1') ?></li>
    <li><?= __('tos.r2') ?></li>
    <li><?= __('tos.r3') ?></li>
    <li><?= __('tos.r4') ?></li>
    <li><?= __('tos.r5') ?></li>
    <li><?= __('tos.r6') ?></li>
    <li><?= __('tos.r7') ?></li>
<?php if (trackerMode($cfg) === 'whitelist'): ?>
    <li><?= __('tos.r_wl') ?></li>
<?php endif; ?>
    <li><?= __('tos.r8') ?></li>
    <li><?= __('tos.r9') ?></li>
</ol>

<?php if (usersEnabled($cfg)): ?>
<h2><?= _h('tos.acc_head') ?></h2>
<ol class="tos-list">
    <li><?= __('tos.acc1') ?></li>
    <li><?= __('tos.acc2', ['email' => __(userEmailVerifyRequired($cfg) ? 'tos.acc2_req' : 'tos.acc2_opt')]) ?></li>
    <li><?= __('tos.acc3') ?></li>
    <li><?= __('tos.acc4') ?></li>
    <li><?= __('tos.acc5') ?></li>
    <li><?= __('tos.acc6') ?></li>
    <li><?= __('tos.acc7') ?></li>
</ol>
<?php endif; ?>
