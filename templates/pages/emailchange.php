<?php
// Email-change confirmation landing (?action=emailchange&token=…) — step 1 (old address) and
// step 2 (new address) both land here; the 64-hex token IS the secret.
$ecToken = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['token'] ?? '')));
$ecResult = strlen($ecToken) === 64 ? userEmailChangeConsume($db, $cfg, $ecToken) : ['error' => 'invalid'];
?>
<h1><?= _h('emailchange.h1') ?></h1>

<?php if (($ecResult['stage'] ?? '') === 'old_ok'): ?>
<div class="alert alert-success show"><?= _h('emailchange.step1') ?></div>
<p><?= __('emailchange.step1_note', ['email' => sanitize($ecResult['pending'])]) ?></p>
<?php elseif (($ecResult['stage'] ?? '') === 'done'): ?>
<div class="alert alert-success show"><?= __('emailchange.done', ['email' => sanitize($ecResult['email'])]) ?></div>
<p class="form-center"><a class="btn" href="<?= $baseUrl ?>?action=account"><?= _h('common.go_account') ?></a></p>
<?php elseif (($ecResult['stage'] ?? '') === 'removed'): ?>
<div class="alert alert-success show"><?= __('emailchange.removed') ?></div>
<p class="form-center"><a class="btn" href="<?= $baseUrl ?>?action=account"><?= _h('common.go_account') ?></a></p>
<?php elseif (($ecResult['error'] ?? '') === 'email_taken'): ?>
<div class="alert alert-error show"><?= _h('emailchange.taken') ?></div>
<?php else: ?>
<div class="alert alert-error show"><?= _h('emailchange.bad') ?></div>
<p><?= __('emailchange.bad_hint', ['url' => sanitize($baseUrl . '?action=account')]) ?></p>
<?php endif; ?>
