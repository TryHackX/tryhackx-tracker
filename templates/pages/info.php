<h1><?= _h('info.h1') ?></h1>

<h2><?= _h('info.q_what') ?></h2>
<p><?= _h('info.a_what') ?></p>

<h2><?= _h('info.q_ot') ?></h2>
<p><?= _h('info.a_ot') ?></p>

<h2><?= _h('info.q_how') ?></h2>
<p><?= _h('info.a_how') ?></p>

<?php if (trackerMode($cfg) === 'whitelist'): ?>
<h2><?= _h('info.q_wl') ?></h2>
<?php // The strong/link markup is part of the sentence, so it lives in the string and is echoed raw.
      // The one thing interpolated into it is our own URL, escaped here. ?>
<p><?= __('info.a_wl', ['url' => sanitize($baseUrl . '?action=whitelist')]) ?></p>
<?php endif; ?>

<?php $infoIndex = function_exists('indexEnabled') && indexEnabled($cfg);
      $infoSearch = $infoIndex && usersEnabled($cfg) && ($cfg['index_search_enabled'] ?? '1') === '1'; ?>
<?php if ($infoIndex): ?>
<h2><?= _h('info.q_index') ?></h2>
<p><?= _h('info.a_index') ?><?= $infoSearch ? ' ' . __('info.a_index_search', ['url' => sanitize($baseUrl . '?action=search')]) : '' ?></p>
<?php endif; ?>
<?php if (usersEnabled($cfg)): ?>
<h2><?= _h('info.q_accounts') ?></h2>
<p><?= _h('info.a_accounts') ?></p>
<?php endif; ?>

<h2><?= _h('info.q_data') ?></h2>
<p><?= _h('info.a_data') ?><?= $infoIndex ? ' ' . _h('info.a_data_index') : '' ?></p>

<h2><?= _h('info.faq_head') ?></h2>
<div class="faq-item"><div class="faq-q"><?= _h('info.faq_q1') ?></div><div class="faq-a"><?= _h(trackerMode($cfg) === 'whitelist' ? 'info.faq_a1_wl' : 'info.faq_a1_open') ?></div></div>
<div class="faq-item"><div class="faq-q"><?= _h('info.faq_q2') ?></div><div class="faq-a"><?= _h('info.faq_a2') ?></div></div>
<div class="faq-item"><div class="faq-q"><?= _h('info.faq_q3') ?></div><div class="faq-a"><?= _h('info.faq_a3') ?></div></div>
<div class="faq-item"><div class="faq-q"><?= _h('info.faq_q4') ?></div><div class="faq-a"><?= _h('info.faq_a4') ?></div></div>
<div class="faq-item"><div class="faq-q"><?= _h('info.faq_q5') ?></div><div class="faq-a"><?= _h('info.faq_a5') ?></div></div>
<?php if (function_exists('langEnabled') && count(langEnabled($cfg)) > 1): ?>
<div class="faq-item"><div class="faq-q"><?= _h('info.faq_q6') ?></div><div class="faq-a"><?= _h('info.faq_a6') ?></div></div>
<?php endif; ?>
