<?php
// User-facing search over the observed-hash catalogue. Server-side gate mirrors api/index_search.php.
$canSearch = indexEnabled($cfg) && userCan($db, $cfg, 'index.view');
$canFiles = $canSearch && userCan($db, $cfg, 'index.files');
$canMagnet = $canSearch && userCan($db, $cfg, 'index.magnet');
$canWl = $canSearch && userCan($db, $cfg, 'whitelist.view');
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
<p>Search everything this tracker has <em>seen</em> (resolved metadata only<?= $canWl ? ', registered torrents included' : '' ?>). This is a catalogue of hashes observed in the swarm &mdash; nothing is hosted here.</p>
<form id="search-form" class="search-panel" novalidate
      data-can-files="<?= $canFiles ? '1' : '0' ?>" data-can-magnet="<?= $canMagnet ? '1' : '0' ?>"
      data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>" data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>">
    <div class="search-toolbar">
        <div class="search-box">
            <span class="search-box-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
            <input type="text" id="search-input" maxlength="200" placeholder="Name<?= $canFiles ? ' or file name' : '' ?>&hellip;" autocomplete="off">
            <button type="button" class="search-clear" id="search-clear" title="Clear search" hidden><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <select id="search-sort" title="Sort order">
            <option value="relevance:desc">Best match</option>
            <option value="seeders:desc">Most seeders</option>
            <option value="last:desc">Recently seen</option>
            <option value="size:desc">Largest</option>
            <option value="size:asc">Smallest</option>
            <option value="name:asc">Name A&ndash;Z</option>
        </select>
        <?php if ($canFiles): ?>
        <label class="search-check" title="Also match torrent file names"><input type="checkbox" id="search-files"><span class="search-check-box" aria-hidden="true"></span> Also search file names</label>
        <?php endif; ?>
        <span class="search-total text-muted" id="search-total"></span>
    </div>
</form>
<div id="search-alert" class="alert"></div>
<div class="transparency-table-wrap">
    <table class="transparency-table search-table" id="search-table" hidden>
        <colgroup>
            <col class="search-c-name"><col class="search-c-size"><col class="search-c-sl"><col class="search-c-seen"><?= $canMagnet ? '<col class="search-c-actions">' : '' ?>
        </colgroup>
        <thead><tr>
            <th>Name</th><th>Size</th><th>S / L</th><th>Last seen</th><?= $canMagnet ? '<th></th>' : '' ?>
        </tr></thead>
        <tbody id="search-body"></tbody>
    </table>
</div>
<div class="trans-pagination search-pagination" id="search-pagination"></div>
<p class="text-muted search-note" id="search-note" hidden></p>
<?php if ($canFiles): ?>
<div class="files-overlay" id="files-overlay" hidden>
    <div class="files-box" role="dialog" aria-modal="true" aria-labelledby="files-title">
        <div class="files-head">
            <h3 id="files-title">Files</h3>
            <button type="button" class="files-close" id="files-close" title="Close">&times;</button>
        </div>
        <div class="files-body" id="files-body"></div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
