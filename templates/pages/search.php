<?php
// User-facing search over the observed-hash catalogue. Server-side gate mirrors api/index_search.php.
$searchOn = indexEnabled($cfg) && ($cfg['index_search_enabled'] ?? '1') === '1';
$canSearch = $searchOn && userCan($db, $cfg, 'index.view');
$canFiles = $canSearch && userCan($db, $cfg, 'index.files');
$canMagnet = $canSearch && userCan($db, $cfg, 'index.magnet');
$canWl = $canSearch && userCan($db, $cfg, 'whitelist.view') && ($cfg['index_search_include_whitelist'] ?? '1') === '1';
// The Share buttons. Nothing about the view is secret — the address is built from controls the
// reader can see — so this switch is about whether the operator wants the site handing out links,
// not about access. The state is written into the address either way; only the buttons go.
$canShare = $canSearch && ($cfg['search_share_enabled'] ?? '1') === '1';
$meUser = currentUser($db);
?>
<h1><?= _h('search.h1') ?></h1>

<?php if (!$searchOn): ?>
<p><?= __('search.disabled') ?></p>
<?php elseif (!$canSearch): ?>
<?php if ($meUser === null): ?>
<p><?= _h('search.need_account') ?></p>
<p><a class="btn" href="<?= $baseUrl ?>?action=login"><?= _h('common.sign_in') ?></a>
<?php if (usersRegistrationEnabled($cfg)): ?> <a class="btn btn-secondary" href="<?= $baseUrl ?>?action=register"><?= _h('common.register') ?></a><?php endif; ?></p>
<?php else: ?>
<p><?= __('search.no_access', ['url' => sanitize($baseUrl . '?action=account')]) ?></p>
<?php endif; ?>
<?php else: ?>
<p><?= __('search.intro', ['wl' => $canWl ? __('search.intro_wl') : '']) ?></p>
<input type="hidden" id="search-csrf" value="<?= $csrfToken ?>">
<form id="search-form" class="search-panel" novalidate
      data-can-files="<?= $canFiles ? '1' : '0' ?>" data-can-magnet="<?= $canMagnet ? '1' : '0' ?>"
      <?php /* Only the mode travels. The batch and the total are server rules — api/index_files.php
               applies them and reports `capped` — and a number the browser never sees is a number
               the browser can never be talked into ignoring. Both overlays live in this file and
               read this attribute inside initSearch()'s closure, so they cannot drift apart. */ ?>
      data-files-mode="<?= sanitize(indexFilesMode($cfg)) ?>"
      data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>" data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>"
      <?php $sExtra = array_values(array_diff(function_exists('announceUrls') ? announceUrls($cfg) : [],
                                              array_filter([(string)($cfg['announce_url'] ?? ''), (string)($cfg['announce_url_https'] ?? '')]))); ?>
      data-announce-extra="<?= sanitize(implode(' ', $sExtra)) ?>">
    <div class="search-toolbar">
        <div class="search-box">
            <span class="search-box-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
            <input type="text" id="search-input" maxlength="200" placeholder="<?= _h($canFiles ? 'search.placeholder_files' : 'search.placeholder') ?>" autocomplete="off">
            <button type="button" class="search-clear" id="search-clear" title="<?= _h('search.clear') ?>" hidden><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <label class="search-check" title="<?= _h('search.best_title') ?>"><input type="checkbox" id="search-best" checked><span class="search-check-box" aria-hidden="true"></span> <?= _h('search.best') ?></label>
        <?php if ($canFiles): ?>
        <label class="search-check" title="<?= _h('search.files_title') ?>"><input type="checkbox" id="search-files"><span class="search-check-box" aria-hidden="true"></span> <?= _h('search.files') ?></label>
        <?php endif; ?>
        <?php if (($cfg['wl_allow_description'] ?? '0') === '1' || ($cfg['wl_allow_source_url'] ?? '0') === '1'): ?>
        <select id="search-content" title="<?= _h('search.content_title') ?>">
            <option value="not_rejected"><?= _h('search.c_not_rejected') ?></option>
            <option value="approved"><?= _h('search.c_approved') ?></option>
            <option value="approved_or_none"><?= _h('search.c_approved_or_none') ?></option>
            <option value="pending"><?= _h('search.c_pending') ?></option>
            <option value="none"><?= _h('search.c_none') ?></option>
            <option value="rejected"><?= _h('search.c_rejected') ?></option>
        </select>
        <?php endif; ?>
        <select id="search-perpage" title="<?= _h('search.perpage_title') ?>">
            <?php foreach ([15, 25, 50, 100, 200] as $sPer): ?>
            <option value="<?= $sPer ?>"<?= $sPer === 25 ? ' selected' : '' ?>><?= _h('search.perpage', ['n' => $sPer]) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="search-total text-muted" id="search-total"></span>
<?php if ($canShare): ?>
        <button type="button" class="search-share" id="search-share" hidden
                title="<?= _h('search.share_view_title') ?>"><?= _h('search.share') ?></button>
<?php endif; ?>
        <p class="search-hint text-muted" id="search-hint" hidden></p>
    </div>
</form>
<div id="search-alert" class="alert"></div>
<div class="transparency-table-wrap">
    <table class="transparency-table search-table" id="search-table" hidden>
        <colgroup>
            <col class="search-c-name"><col class="search-c-size"><col class="search-c-sl"><col class="search-c-seen"><?= $canMagnet ? '<col class="search-c-actions">' : '' ?>
        </colgroup>
        <thead><tr>
            <th class="search-sortable" data-sort="name"><?= _h('search.col_name') ?> <span class="search-sort-icon" aria-hidden="true"></span></th>
            <th class="search-sortable" data-sort="size"><?= _h('search.col_size') ?> <span class="search-sort-icon" aria-hidden="true"></span></th>
            <th class="search-sortable" data-sort="seeders" title="<?= _h('search.col_sl_title') ?>"><?= _h('search.col_sl') ?> <span class="search-sort-icon" aria-hidden="true"></span></th>
            <?php if (function_exists('repEnabled') && repEnabled($cfg) && repShowInResults($cfg)): ?>
            <th title="<?= _h('search.col_rating_title') ?>"><?= _h('search.col_rating') ?></th>
            <?php endif; ?>
            <th class="search-sortable" data-sort="last"><?= _h('search.col_last') ?> <span class="search-sort-icon" aria-hidden="true"></span></th><?= $canMagnet ? '<th></th>' : '' ?>
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
            <h3 id="info-title"><?= _h('search.details') ?></h3>
<?php if ($canShare): ?>
            <button type="button" class="search-share info-share" id="info-share"
                    title="<?= _h('search.share_one_title') ?>"><?= _h('search.share') ?></button>
<?php endif; ?>
            <button type="button" class="files-close" id="info-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>">&times;</button>
        </div>
        <div class="files-body" id="info-body"></div>
    </div>
</div>

<div class="files-overlay" id="files-overlay" hidden>
    <div class="files-box" role="dialog" aria-modal="true" aria-labelledby="files-title">
        <div class="files-head">
            <h3 id="files-title"><?= _h('search.files_head') ?></h3>
            <button type="button" class="files-close" id="files-close" title="<?= _h('common.close') ?>">&times;</button>
        </div>
        <div class="files-body" id="files-body"></div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
