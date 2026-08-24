<?php
// Email verification landing page (?action=verify&token=…). The 64-hex token IS the secret —
// consuming it on GET is fine (single use, 72 h lifetime).
$verifyToken = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['token'] ?? '')));
$verifiedId = strlen($verifyToken) === 64 ? userVerifyConsume($db, $verifyToken) : null;
?>
<h1>Email verification</h1>

<?php if ($verifiedId !== null): ?>
<div class="alert alert-success show">Your email address is now <strong>verified</strong>. Thank you!</div>
<p class="form-center"><a class="btn" href="<?= $baseUrl ?>?action=account">Go to your account</a></p>
<?php else: ?>
<div class="alert alert-error show">This verification link is invalid, expired or was already used.</div>
<p>You can request a fresh link from your <a href="<?= $baseUrl ?>?action=account">account page</a> (links are valid for 72 hours).</p>
<?php endif; ?>
