<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('a.index.title') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl) ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/vendor/uplot/uPlot.min.css<?= assetVer('assets/vendor/uplot/uPlot.min.css') ?>">
    <!-- the same detail-panel primitives the public Info panel uses -->
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/detail-panel.css<?= assetVer('assets/css/detail-panel.css') ?>">
</head>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>" data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>" data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>" data-near-pages="<?= max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2))) ?>" data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>">
    <div class="admin-container admin-wide wl-page">
        <div class="admin-header">
            <h2><i class="bi bi-collection"></i> <?= _h('a.index.title') ?> <span class="idx-subtitle"><?= __('a.index.subtitle') ?></span></h2>
            <?php $current = 'admin-index'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <!-- The swarm timeline moved to its own page (Traffic): every chart in the panel is in one
             place now. This page keeps the index's OPERATIONAL status, which is not a traffic metric. -->
        <div class="wl-status-card" id="idx-status-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-collection"></i> <?= _h('a.index.status_head') ?> <span class="wl-status-updated" id="idx-status-updated"></span></h6>
                <div class="wl-status-actions">
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-idx-poll" title="<?= _h('a.index.poll_title') ?>"><i class="bi bi-cloud-download"></i> <?= _h('a.index.poll_now') ?></button>
                </div>
            </div>
            <div class="wl-status-grid" id="idx-status-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= __('a.index.loading_status') ?></div>
            </div>
            <p class="idx-note" id="idx-disabled-note" style="display:none"><?= __('a.index.disabled_note', ['url' => sanitize($baseUrl . '?action=settings#section-index')]) ?></p>
        </div>

        <!-- Scrape coverage. The index is built from one file the tracker hands over every half hour,
             and until now the only visible fact about that was the last poll's line of text. A poll
             that quietly started arriving truncated, or a tracker that grew past what a poll can
             carry, looked exactly like a healthy one. -->
        <div class="wl-status-card" id="idx-cov-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-graph-up"></i> <?= _h('a.index.cov_head') ?> <span class="wl-status-updated" id="idx-cov-updated"></span></h6>
                <div class="wl-status-actions">
                    <div class="btn-group btn-group-sm" role="group" aria-label="<?= _h('a.index.range') ?>" id="idx-cov-ranges">
                        <button type="button" class="btn btn-outline-secondary" data-range="6h"><?= _h('a.index.r_6h') ?></button>
                        <button type="button" class="btn btn-outline-secondary active" data-range="24h"><?= _h('a.index.r_24h') ?></button>
                        <button type="button" class="btn btn-outline-secondary" data-range="7d"><?= _h('a.index.r_7d') ?></button>
                        <button type="button" class="btn btn-outline-secondary" data-range="2w"><?= _h('a.index.r_2w') ?></button>
                        <button type="button" class="btn btn-outline-secondary" data-range="1m"><?= _h('a.index.r_1m') ?></button>
                        <button type="button" class="btn btn-outline-secondary" data-range="all"><?= _h('a.index.r_all') ?></button>
                    </div>
                </div>
            </div>
            <p class="wl-small text-muted mb-2">
                <?= __('a.index.cov_intro') ?>
            </p>
            <div id="idx-cov-summary" class="wl-status-grid"></div>
            <div id="idx-cov-chart" class="idx-cov-chart"></div>
            <div id="idx-cov-note"></div>
        </div>


        <div class="wl-view" id="view-index">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search">
                        <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                        <div class="search-input-wrap">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="idx-search" placeholder="<?= _h('a.index.search_ph') ?>">
                            <button type="button" class="search-clear-btn" id="idx-search-clear" title="<?= _h('search.clear') ?>"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="idx-filter-meta" title="<?= _h('a.index.f_meta_title') ?>">
                            <option value=""><?= _h('a.index.m_all') ?></option>
                            <option value="none"><?= _h('a.index.m_none') ?></option>
                            <option value="pending"><?= _h('a.index.m_pending') ?></option>
                            <option value="fetching"><?= _h('a.index.m_fetching') ?></option>
                            <option value="done"><?= _h('a.index.m_done') ?></option>
                            <option value="failed"><?= _h('a.index.m_failed') ?></option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="idx-filter-life" title="<?= _h('a.index.f_life_title') ?>">
                            <option value=""><?= _h('a.index.l_all') ?></option>
                            <option value="grace"><?= _h('a.index.l_grace') ?></option>
                            <option value="protected"><?= _h('a.index.l_protected') ?></option>
                            <option value="promoted"><?= _h('a.index.l_promoted') ?></option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="idx-perpage" title="<?= _h('a.index.perpage_title') ?>">
                            <option value="15"><?= _h('search.perpage', ['n' => 15]) ?></option>
                            <option value="25" selected><?= _h('search.perpage', ['n' => 25]) ?></option>
                            <option value="50"><?= _h('search.perpage', ['n' => 50]) ?></option>
                            <option value="100"><?= _h('search.perpage', ['n' => 100]) ?></option>
                            <option value="200"><?= _h('search.perpage', ['n' => 200]) ?></option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <div class="form-check form-check-inline wl-check m-0" title="<?= _h('a.index.files_title') ?>">
                            <input class="form-check-input" type="checkbox" id="idx-search-files">
                            <label class="form-check-label" for="idx-search-files"><?= _h('search.files') ?></label>
                        </div>
                        <span id="idx-total" class="text-muted wl-total"></span>
                        <div class="btn-group wl-tool-dd" id="idx-meta-bulk-group">
                            <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle" id="btn-idx-meta-bulk" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="<?= _h('a.index.meta_bulk_title') ?>"><i class="bi bi-cloud-download"></i> <?= _h('a.index.fetch_meta') ?></button>
                            <ul class="dropdown-menu dropdown-menu-end wl-dd-menu">
                                <li><h6 class="dropdown-header"><?= __('a.index.meta_hdr') ?></h6></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="missing"><i class="bi bi-dash-circle"></i> <?= _h('a.index.meta_missing') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="failed"><i class="bi bi-x-circle"></i> <?= _h('a.index.m_failed') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="missing_failed"><i class="bi bi-plus-slash-minus"></i> <?= _h('a.index.meta_missing_failed') ?></button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item" data-meta-page><i class="bi bi-file-earmark"></i> <?= _h('a.index.meta_page') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-near><i class="bi bi-files"></i> <?= __('a.index.meta_near', ['n' => max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2)))]) ?></button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="all"><i class="bi bi-arrow-repeat"></i> <?= _h('a.index.meta_all') ?></button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header"><?= __('a.index.meta_first_seen') ?></h6></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="24"><i class="bi bi-clock-history"></i> <?= _h('a.index.last_24h') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="168"><i class="bi bi-calendar-week"></i> <?= _h('a.index.last_7d') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="336"><i class="bi bi-calendar-range"></i> <?= _h('a.index.last_14d') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="custom"><i class="bi bi-calendar-event"></i> <?= __('a.index.custom_range') ?></button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item text-danger" data-meta-cancel><i class="bi bi-x-octagon"></i> <?= __('a.index.meta_cancel') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-restore title="<?= _h('a.index.meta_restore_title') ?>"><i class="bi bi-arrow-counterclockwise"></i> <?= _h('a.index.meta_restore') ?></button></li>
                            </ul>
                        </div>
                        <div class="btn-group wl-tool-dd" id="idx-scrape-bulk-group">
                            <button type="button" class="btn btn-sm btn-outline-info" id="btn-idx-scrape-bulk" title="<?= _h('a.index.scrape_title') ?>"><i class="bi bi-arrow-repeat"></i> <span id="idx-scrape-label"><?= _h('a.index.refresh_sl') ?></span></button>
                            <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle dropdown-toggle-split" id="btn-idx-scrape-caret" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"><span class="visually-hidden"><?= _h('a.index.more_scrape') ?></span></button>
                            <ul class="dropdown-menu dropdown-menu-end wl-dd-menu">
                                <li><h6 class="dropdown-header"><?= __('a.index.scrape_hdr') ?></h6></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="page"><i class="bi bi-file-earmark"></i> <?= _h('a.index.this_page') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-near><i class="bi bi-files"></i> <?= __('a.index.near_pages', ['n' => max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2)))]) ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="stale"><i class="bi bi-hourglass-split"></i> <?= _h('a.index.scrape_stale') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="all"><i class="bi bi-collection"></i> <?= _h('a.index.all_rows') ?></button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header"><?= __('a.index.first_seen') ?></h6></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="24"><i class="bi bi-clock-history"></i> <?= _h('a.index.last_24h') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="168"><i class="bi bi-calendar-week"></i> <?= _h('a.index.last_7d') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="336"><i class="bi bi-calendar-range"></i> <?= _h('a.index.last_14d') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="custom"><i class="bi bi-calendar-event"></i> <?= __('a.index.custom_range') ?></button></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="wl-bulkbar d-hidden" id="idx-bulkbar">
                    <span id="idx-sel-count"><?= _h('a.index.sel_count', ['n' => 0]) ?></span>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-idx-promote"><i class="bi bi-arrow-up-circle"></i> <?= __('a.index.promote') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-idx-meta-sel"><i class="bi bi-cloud-download"></i> <?= _h('a.index.fetch_meta') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-idx-delete"><i class="bi bi-trash"></i> <?= _h('common.delete') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-idx-clearsel"><?= _h('a.index.clear_sel') ?></button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-dark table-hover dash-table wl-table" id="idx-table">
                    <colgroup>
                        <col class="idx-c-check"><col class="idx-c-hash"><col class="idx-c-name"><col class="idx-c-size"><col class="idx-c-files"><col class="idx-c-sl"><col class="idx-c-seen"><col class="idx-c-dates"><col class="idx-c-meta"><col class="idx-c-actions">
                    </colgroup>
                    <thead><tr>
                        <th class="idx-th-check"><input type="checkbox" id="idx-check-all" title="<?= _h('a.index.check_all') ?>"></th>
                        <th class="sortable" data-sort="hash"><?= _h('a.index.col_hash') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="name"><?= _h('search.col_name') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="size"><?= _h('search.col_size') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="files" title="<?= _h('a.index.col_files_title') ?>"><?= _h('search.files_head') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="seeders"><?= _h('search.col_sl') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="seen"><?= _h('a.index.col_seen') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="last"><?= _h('a.index.col_first_last') ?> <i class="bi bi-arrow-down sort-icon active"></i></th>
                        <th class="sortable" data-sort="meta"><?= _h('a.index.col_meta') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="th-actions"><?= _h('a.index.col_actions') ?></th>
                    </tr></thead>
                    <tbody id="idx-body"></tbody>
                </table>
            </div>
            <div class="admin-pagination" id="idx-pagination"></div>
        </div>
    </div>

    <!-- Details modal -->
    <div class="modal fade" id="idxModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-collection"></i> <?= _h('a.index.modal_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="idx-modal-body"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/vendor/uplot/uPlot.iife.min.js<?= assetVer('assets/vendor/uplot/uPlot.iife.min.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-index.js<?= assetVer('assets/js/admin-index.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-index-coverage.js<?= assetVer('assets/js/admin-index-coverage.js') ?>"></script>
</body>
</html>
