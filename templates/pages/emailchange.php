<?php
// Email-change confirmation landing (?action=emailchange&token=…) — step 1 (old address) and
// step 2 (new address) both land here; the 64-hex token IS the secret.
$ecToken = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['token'] ?? '')));
$ecResult = strlen($ecToken) === 64 ? userEmailChangeConsume($db, $cfg, $ecToken) : ['error' => 'invalid'];
?>
<h1>Email change</h1>

<?php if (($ecResult['stage'] ?? '') === 'old_ok'): ?>
<div class="alert alert-success show">Step 1 of 2 confirmed.</div>
<p>A confirmation link was just sent to the <strong>new</strong> address (<strong><?= sanitize($ecResult['pending']) ?></strong>). Open it there to finish the change.</p>
<?php elseif (($ecResult['stage'] ?? '') === 'done'): ?>
<div class="alert alert-success show">Done — your account email is now <strong><?= sanitize($ecResult['email']) ?></strong> (verified).</div>
<p class="form-center"><a class="btn" href="<?= $baseUrl ?>?action=account">Go to your account</a></p>
<?php elseif (($ecResult['stage'] ?? '') === 'removed'): ?>
<div class="alert alert-success show">Confirmed — the email address was <strong>removed</strong> from your account.</div>
<p class="form-center"><a class="btn" href="<?= $baseUrl ?>?action=account">Go to your account</a></p>
<?php elseif (($ecResult['error'] ?? '') === 'email_taken'): ?>
<div class="alert alert-error show">Another account claimed this address in the meantime — the change was not applied.</div>
<?php else: ?>
<div class="alert alert-error show">This confirmation link is invalid, expired or was already used.</div>
<p>You can restart the change from your <a href="<?= $baseUrl ?>?action=account">account page</a> (each step link is valid for 24 hours).</p>
<?php endif; ?>
