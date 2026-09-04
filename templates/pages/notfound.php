<?php
/**
 * 404 page (site look, 404 status). Used when a signed-out visitor asks for a panel URL and
 * `admin_hidden_behavior` is set to "404" — the panel then looks like it simply isn't there.
 */
?>
<h1><?= _h('notfound.h1') ?></h1>
<p><?= _h('notfound.body', ['site' => $cfg['site_name'] ?? 'this tracker']) ?></p>
<p class="form-center"><a class="btn" href="<?= $baseUrl ?>"><?= _h('common.back_home') ?></a></p>
