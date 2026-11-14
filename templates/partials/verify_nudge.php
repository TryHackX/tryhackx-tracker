<?php
$vnUser = $navUser ?? null;
if ($vnUser !== null && userEmailVerifyRequired($cfg) && !userIsEmailTrusted($db, (int)$vnUser['id']) && !userIsAdminGroup($db, (int)$vnUser['id'])):
    $vnHasEmail = trim((string)($vnUser['email'] ?? '')) !== '';
?>
<div class="alert alert-warn show verify-nudge" role="status">
    <?php if ($vnHasEmail): ?>
    <span><?= _h('account.nudge_has') ?></span>
    <button type="button" class="btn btn-secondary btn-small" data-verify-send title="<?= _h('account.resend_title') ?>"><?= _h('account.resend') ?></button>
    <?php else: ?>
    <span><?= __('account.nudge_none', ['url' => sanitize($baseUrl . '?action=account')]) ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>
