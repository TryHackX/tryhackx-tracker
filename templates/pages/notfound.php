<?php
/**
 * 404 page (site look, 404 status). Used when a signed-out visitor asks for a panel URL and
 * `admin_hidden_behavior` is set to "404" — the panel then looks like it simply isn't there.
 */
?>
<h1>404 &mdash; Not found</h1>
<p>This page does not exist on <?= sanitize($cfg['site_name'] ?? 'this tracker') ?>.</p>
<p class="form-center"><a class="btn" href="<?= $baseUrl ?>">Back to the front page</a></p>
