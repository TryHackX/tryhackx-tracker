<?php
// User-facing search over the observed-hash catalogue. Server-side gate mirrors api/index_search.php.
$searchOn = indexEnabled($cfg) && ($cfg['index_search_enabled'] ?? '1') === '1';
$canSearch = $searchOn && userCan($db, $cfg, 'index.view');
$canFiles = $canSearch && userCan($db, $cfg, 'index.files');
$canMagnet = $canSearch && userCan($db, $cfg, 'index.magnet');
$canWl = $canSearch && userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1';
$meUser = currentUser($db);
?>
<h1>Search</h1>

<?php if (!$searchOn): ?>
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
<input type="hidden" id="search-csrf" value="<?= $csrfToken ?>">
<form id="search-form" class="search-panel" novalidate
      data-can-files="<?= $canFiles ? '1' : '0' ?>" data-can-magnet="<?= $canMagnet ? '1' : '0' ?>"
      data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>" data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>"
      <?php $sExtra = array_values(array_diff(function_exists('announceUrls') ? announceUrls($cfg) : [],
                                              array_filter([(string)($cfg['announce_url'] ?? ''), (string)($cfg['announce_url_https'] ?? '')]))); ?>
      data-announce-extra="<?= sanitize(implode(' ', $sExtra)) ?>">
    <div class="search-toolbar">
        <div class="search-box">
            <span class="search-box-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
            <input type="text" id="search-input" maxlength="200" placeholder="Name<?= $canFiles ? ' or file name' : '' ?>&hellip;" autocomplete="off">
            <button type="button" class="search-clear" id="search-clear" title="Clear search" hidden><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <label class="search-check" title="Order by how well the name matches your query (rarer and longer words weigh more); column sorts break ties"><input type="checkbox" id="search-best" checked><span class="search-check-box" aria-hidden="true"></span> Best match first</label>
        <?php if ($canFiles): ?>
        <label class="search-check" title="Also match torrent file names"><input type="checkbox" id="search-files"><span class="search-check-box" aria-hidden="true"></span> Also search file names</label>
        <?php endif; ?>
        <select id="search-perpage" title="Results per page">
            <option value="15">15 / page</option>
            <option value="25" selected>25 / page</option>
            <option value="50">50 / page</option>
            <option value="100">100 / page</option>
            <option value="200">200 / page</option>
        </select>
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
            <th class="search-sortable" data-sort="name">Name <span class="search-sort-icon" aria-hidden="true"></span></th>
            <th class="search-sortable" data-sort="size">Size <span class="search-sort-icon" aria-hidden="true"></span></th>
            <th class="search-sortable" data-sort="seeders" title="Seeders / leechers">S / L <span class="search-sort-icon" aria-hidden="true"></span></th>
            <th class="search-sortable" data-sort="last">Last seen <span class="search-sort-icon" aria-hidden="true"></span></th><?= $canMagnet ? '<th></th>' : '' ?>
        </tr></thead>
        <tbody id="search-body"></tbody>
    </table>
</div>
<div class="trans-pagination search-pagination" id="search-pagination"></div>
<p class="text-muted search-note" id="search-note" hidden></p>
<?php if ($canFiles): ?>
<!-- Info panel: what this torrent is, where it came from, and how the swarm looks. The file list
     lives at the bottom of it; the "N files" chip beside a result still opens the tree on its own. -->
<div class="files-overlay" id="info-overlay" hidden>
    <div class="files-box info-box" role="dialog" aria-modal="true" aria-labelledby="info-title">
        <div class="files-head">
            <h3 id="info-title">Details</h3>
            <button type="button" class="files-close" id="info-close" title="Close" aria-label="Close">&times;</button>
        </div>
        <div class="files-body" id="info-body"></div>
    </div>
</div>

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
