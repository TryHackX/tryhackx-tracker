<?php
// Email verification landing page (?action=verify&token=…). The 64-hex token IS the secret —
// consuming it on GET is fine (single use, 72 h lifetime).
$verifyToken = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['token'] ?? '')));
$verifiedId = strlen($verifyToken) === 64 ? userVerifyConsume($db, $verifyToken) : null;
?>
<h1><?= _h('verify.h1') ?></h1>

<?php if ($verifiedId !== null): ?>
<div class="alert alert-success show"><?= __('verify.ok') ?></div>
<p class="form-center"><a class="btn" href="<?= $baseUrl ?>?action=account"><?= _h('common.go_account') ?></a></p>
<?php else: ?>
<div class="alert alert-error show"><?= _h('verify.bad') ?></div>
<p><?= __('verify.bad_hint', ['url' => sanitize($baseUrl . '?action=account')]) ?></p>
<?php endif; ?>
