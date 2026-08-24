<?php
// User-facing search over the observed-hash index. Server-side gate mirrors api/index_search.php.
$canSearch = indexEnabled($cfg) && userCan($db, $cfg, 'index.view');
$canFiles = $canSearch && userCan($db, $cfg, 'index.files');
$canMagnet = $canSearch && userCan($db, $cfg, 'index.magnet');
$meUser = currentUser($db);
?>
<h1>Search</h1>

<?php if (!indexEnabled($cfg)): ?>
<p>The search index is currently <strong>disabled</strong> on this tracker.</p>
<?php elseif (!$canSearch): ?>
<?php if ($meUser === null): ?>
<p>Searching the tracker index requires an account with search access.</p>
<p><a class="btn" href="<?= $baseUrl ?>?action=login">Sign in</a>
<?php if (usersRegistrationEnabled($cfg)): ?> <a class="btn btn-secondary" href="<?= $baseUrl ?>?action=register">Register</a><?php endif; ?></p>
<?php else: ?>
<p>Your account does not have search access. Check <a href="<?= $baseUrl ?>?action=account">your groups</a> or contact the site admin.</p>
<?php endif; ?>
<?php else: ?>
<p>Search everything this tracker has <em>seen</em> (resolved metadata only). This is a catalogue of hashes observed in the swarm &mdash; nothing is hosted here.</p>
<form id="search-form" class="search-form" novalidate
      data-can-files="<?= $canFiles ? '1' : '0' ?>" data-can-magnet="<?= $canMagnet ? '1' : '0' ?>"
      data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>" data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>">
    <div class="search-row">
        <input type="text" id="search-input" maxlength="200" placeholder="Name<?= $canFiles ? ' or file name' : '' ?>&hellip;" autocomplete="off">
        <select id="search-sort">
            <option value="seeders:desc">Most seeders</option>
            <option value="last:desc">Recently seen</option>
            <option value="size:desc">Largest</option>
            <option value="size:asc">Smallest</option>
            <option value="name:asc">Name A&ndash;Z</option>
        </select>
        <button type="submit" class="btn" id="search-submit">Search</button>
    </div>
    <?php if ($canFiles): ?>
    <label class="user-remember"><input type="checkbox" id="search-files"> Also search file names</label>
    <?php endif; ?>
</form>
<div id="search-alert" class="alert"></div>
<div class="transparency-table-wrap">
    <table class="transparency-table" id="search-table" hidden>
        <thead><tr>
            <th>Name</th><th>Size</th><th>S / L</th><th>Last seen</th><?= $canMagnet ? '<th></th>' : '' ?>
        </tr></thead>
        <tbody id="search-body"></tbody>
    </table>
</div>
<div class="trans-pagination" id="search-pagination"></div>
<p class="text-muted search-note" id="search-note" hidden></p>
<?php endif; ?>
