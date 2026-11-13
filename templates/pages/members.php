<?php
/**
 * ?action=members — the fallback body behind the redirect in index.php.
 *
 * The directory moved into the account page's tabs: it is one more list of people beside the
 * reader's own friends and blocks, and a page of its own put it a navigation apart from them. The
 * address stays because links to it are out in the world, and a bookmark that stops working is a
 * worse answer than a redirect.
 *
 * This is only ever reached if that redirect could not be sent — an operator's output buffer, a
 * plugin that printed early — so it says the same thing in HTML rather than leaving a blank page.
 */
?>
<h1><?= _h('people.dir_h1') ?></h1>
<p><a class="btn btn-secondary" href="<?= $baseUrl ?>?action=account#members"><?= _h('people.dir_h1') ?></a></p>
