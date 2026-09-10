<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('a.wl.title') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl) ?>
    <!-- the same detail-panel primitives the public Info panel uses -->
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/detail-panel.css<?= assetVer('assets/css/detail-panel.css') ?>">
</head>
<?php $svcName = trim($cfg['opentracker_service_name'] ?? ''); ?>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>" data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>" data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>" data-api-ban-days="<?= (int)($cfg['api_ban_days'] ?? 30) ?>" data-service="<?= sanitize($svcName) ?>" data-near-pages="<?= max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2))) ?>" data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>" data-files-mode="<?= sanitize(indexFilesAdminMode($cfg)) ?>" data-bridge-on="<?= (function_exists('authBridgeEnabled') && authBridgeEnabled($cfg)) ? '1' : '0' ?>">
    <div class="admin-container admin-wide wl-page">
        <div class="admin-header">
            <h2><i class="bi bi-list-check"></i> <?= _h('a.wl.title') ?></h2>
            <?php $current = 'admin-whitelist'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <!-- The swarm timeline and the UDP traffic card live on their own page now (Traffic,
             templates/admin/traffic.php): they answer "how much traffic", this page answers
             "which torrents", and mixing the two made both harder to find. -->

        <!-- Status card -->
        <div class="wl-status-card" id="wl-status-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-activity"></i> <?= _h('a.wl.status_head') ?> <span class="wl-status-updated" id="wl-status-updated"></span></h6>
                <div class="wl-status-actions">
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-wl-regen" title="<?= _h('a.wl.regen_title') ?>"><i class="bi bi-file-earmark-arrow-down"></i> <?= _h('a.wl.regen') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btn-wl-import" title="<?= _h('a.wl.import_title') ?>"><i class="bi bi-box-arrow-in-down"></i> <?= __('a.wl.import') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-wl-reload" title="<?= _h('a.wl.reload_title') ?>"><i class="bi bi-arrow-clockwise"></i> <?= _h('a.wl.reload') ?></button>
                    <a href="<?= $baseUrl ?>?action=admin" class="btn btn-sm btn-outline-secondary" title="<?= _h('a.wl.restart_title') ?>"><i class="bi bi-bootstrap-reboot"></i> <?= __('a.wl.restart') ?></a>
                </div>
            </div>
            <div class="wl-status-grid" id="wl-status-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= __('a.wl.status_loading') ?></div>
            </div>
            <ul class="wl-warnings d-hidden" id="wl-status-warnings"></ul>
        </div>


        <!-- Sub-view tabs -->
        <div class="source-tabs" id="wl-tabs">
            <button class="source-tab active" data-view="whitelist"><i class="bi bi-list-check"></i> <?= _h('a.wl.title') ?> <span id="tab-badge-whitelist" class="wl-tab-count"></span></button>
            <button class="source-tab" data-view="banned"><i class="bi bi-slash-circle"></i> <?= _h('a.wl.tab_banned') ?> <span id="tab-badge-banned" class="wl-tab-count"></span></button>
            <button class="source-tab" data-view="clients"><i class="bi bi-key"></i> <?= _h('a.wl.tab_clients') ?></button>
            <button class="source-tab" data-view="bans"><i class="bi bi-shield-x"></i> <?= _h('a.wl.tab_bans') ?></button>
            <button class="source-tab" data-view="review"><i class="bi bi-eye"></i> <?= _h('a.wl.tab_review') ?> <span id="tab-badge-review" class="wl-tab-count"></span></button>
        </div>

        <!-- ===== Review queue: source links and descriptions waiting to be published ===== -->
        <div class="wl-view d-hidden" id="view-review">
            <div class="rv-subtabs" id="rv-subtabs">
                <button type="button" class="source-tab active" data-sub="queue"><i class="bi bi-inbox"></i> <?= _h('a.wl.rv_submissions') ?> <span id="rv-sub-count" class="wl-tab-count"></span></button>
                <button type="button" class="source-tab" data-sub="edits"><i class="bi bi-pencil-square"></i> <?= _h('a.wl.rv_rewrites') ?> <span id="rv-edits-count" class="wl-tab-count"></span></button>
            </div>

            <div id="rv-pane-queue">
                <div class="admin-toolbar-card">
                    <div class="toolbar-row">
                        <div class="toolbar-search">
                            <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                            <div class="search-input-wrap">
                                <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="rv-search" placeholder="<?= _h('a.wl.rv_search_ph') ?>">
                                <button type="button" class="search-clear-btn" id="rv-search-clear" title="<?= _h('a.wl.clear_search') ?>"><i class="bi bi-x-lg"></i></button>
                            </div>
                            <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="rv-status" title="<?= _h('a.wl.rv_status_title') ?>">
                                <option value="pending"><?= _h('a.wl.rv_st_pending') ?></option>
                                <option value="approved"><?= _h('a.wl.rv_st_approved') ?></option>
                                <option value="rejected"><?= _h('a.wl.rv_st_rejected') ?></option>
                                <option value="all"><?= _h('a.wl.rv_st_all') ?></option>
                            </select>
                        </div>
                        <div class="toolbar-right"><span id="rv-total" class="text-muted wl-total"></span></div>
                    </div>
                    <div class="rv-explain wl-small text-muted"><?= __('a.wl.rv_explain') ?></div>
                </div>
                <div id="rv-list"></div>
                <div class="admin-pagination" id="rv-pagination"></div>
            </div>

            <div id="rv-pane-edits" class="d-hidden">
                <div class="rv-pane-intro">
                    <div class="rv-pane-intro-icon"><i class="bi bi-pencil-square"></i></div>
                    <div class="rv-pane-intro-text">
                        <strong><?= _h('a.wl.rv_edits_head') ?></strong>
                        <span><?= __('a.wl.rv_edits_note') ?></span>
                    </div>
                    <span id="rv-edits-total" class="rv-pane-intro-count"></span>
                </div>
                <div id="rv-edits-list"></div>
            </div>
        </div>

        <!-- ===== Whitelist view ===== -->
        <div class="wl-view" id="view-whitelist">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search">
                        <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                        <div class="search-input-wrap">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="wl-search" placeholder="<?= _h('a.wl.wl_search_ph') ?>">
                            <button type="button" class="search-clear-btn" id="wl-search-clear" title="<?= _h('a.wl.clear_search') ?>"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="wl-filter-source" title="<?= _h('a.wl.col_source') ?>">
                            <option value=""><?= _h('a.wl.src_all') ?></option>
                            <option value="web">Web</option>
                            <option value="api">API</option>
                            <option value="admin">Admin</option>
                            <option value="forum">Forum</option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="wl-filter-meta" title="<?= _h('a.wl.f_meta_title') ?>">
                            <option value=""><?= _h('a.wl.meta_all') ?></option>
                            <option value="none"><?= _h('a.wl.meta_none') ?></option>
                            <option value="pending"><?= _h('a.wl.meta_pending') ?></option>
                            <option value="fetching"><?= _h('a.wl.meta_fetching') ?></option>
                            <option value="done"><?= _h('a.wl.meta_done') ?></option>
                            <option value="failed"><?= _h('a.wl.meta_failed') ?></option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="wl-perpage" title="<?= _h('a.wl.f_perpage_title') ?>">
                            <option value="15"><?= _h('a.wl.per_page', ['n' => 15]) ?></option>
                            <option value="25" selected><?= _h('a.wl.per_page', ['n' => 25]) ?></option>
                            <option value="50"><?= _h('a.wl.per_page', ['n' => 50]) ?></option>
                            <option value="100"><?= _h('a.wl.per_page', ['n' => 100]) ?></option>
                            <option value="200"><?= _h('a.wl.per_page', ['n' => 200]) ?></option>
                        </select>
                        <?php /* The partner review queue. 'none' is a real answer rather than the
                                 absence of one: it means the row was published directly, which is a
                                 different thing from one nobody has looked at yet. */ ?>
                        <?php /* Shown only when something is waiting, and it is a BUTTON: the count
                                 and the way to act on it are the same control, so nobody has to
                                 work out which filter value corresponds to the number they read. */ ?>
                        <button type="button" class="btn btn-sm wl-waiting-chip" id="wl-waiting" hidden></button>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="wl-filter-review" title="<?= _h('a.wl.review_filter') ?>">
                            <option value=""><?= _h('a.wl.review_any') ?></option>
                            <option value="pending"><?= _h('a.wl.review_pending') ?></option>
                            <option value="approved"><?= _h('a.wl.review_approved') ?></option>
                            <option value="rejected"><?= _h('a.wl.review_rejected') ?></option>
                            <option value="none"><?= _h('a.wl.review_none') ?></option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="wl-filter-banned" title="<?= _h('a.wl.f_banned_title') ?>">
                            <option value="active"><?= _h('a.wl.st_active') ?></option>
                            <option value="banned"><?= _h('a.wl.st_banned') ?></option>
                            <option value="all"><?= _h('a.wl.st_both') ?></option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <div class="form-check form-check-inline wl-check m-0" title="<?= _h('a.wl.search_files_title') ?>">
                            <input class="form-check-input" type="checkbox" id="wl-search-files">
                            <label class="form-check-label" for="wl-search-files"><?= _h('a.wl.search_files') ?></label>
                        </div>
                        <div class="form-check form-switch wl-check m-0" title="<?= _h('a.wl.group_ip_title') ?>">
                            <input class="form-check-input" type="checkbox" role="switch" id="wl-group-ip">
                            <label class="form-check-label" for="wl-group-ip"><?= _h('a.wl.group_ip') ?></label>
                        </div>
                        <span id="wl-total" class="text-muted wl-total"></span>
                        <!-- Bulk metadata queue: one UPDATE server-side, the worker drains the queue one hash after another -->
                        <div class="btn-group wl-tool-dd" id="wl-meta-bulk-group">
                            <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle" id="btn-wl-meta-bulk" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="<?= _h('a.wl.meta_bulk_title') ?>"><i class="bi bi-cloud-download"></i> <?= _h('a.wl.fetch_meta') ?></button>
                            <ul class="dropdown-menu dropdown-menu-end wl-dd-menu">
                                <li><h6 class="dropdown-header"><?= __('a.wl.meta_dd_head') ?></h6></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="missing"><i class="bi bi-dash-circle"></i> <?= _h('a.wl.meta_missing') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="failed"><i class="bi bi-x-circle"></i> <?= _h('a.wl.meta_failed') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="missing_failed"><i class="bi bi-plus-slash-minus"></i> <?= _h('a.wl.meta_missing_failed') ?></button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item" data-meta-page><i class="bi bi-file-earmark"></i> <?= _h('a.wl.meta_page') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-near><i class="bi bi-files"></i> <?= __('a.wl.meta_near', ['n' => max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2)))]) ?></button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="all"><i class="bi bi-arrow-repeat"></i> <?= _h('a.wl.meta_all_refetch') ?></button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header"><?= __('a.wl.meta_within_head') ?></h6></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="24"><i class="bi bi-clock-history"></i> <?= _h('a.wl.last_24h') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="168"><i class="bi bi-calendar-week"></i> <?= _h('a.wl.last_7d') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="336"><i class="bi bi-calendar-range"></i> <?= _h('a.wl.last_14d') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="custom"><i class="bi bi-calendar-event"></i> <?= __('a.wl.custom_range') ?></button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item text-danger" data-meta-cancel><i class="bi bi-x-octagon"></i> <?= __('a.wl.meta_cancel') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-meta-restore title="<?= _h('a.wl.meta_restore_title') ?>"><i class="bi bi-arrow-counterclockwise"></i> <?= _h('a.wl.meta_restore') ?></button></li>
                                <li><div class="dropdown-item-text wl-dd-note"><?= __('a.wl.meta_dd_note') ?></div></li>
                            </ul>
                        </div>
                        <!-- Bulk scrape (split control): main button = this page, caret = stale / all -->
                        <div class="btn-group wl-tool-dd" id="wl-scrape-bulk-group">
                            <button type="button" class="btn btn-sm btn-outline-info" id="btn-wl-scrape-bulk" title="<?= _h('a.wl.scrape_title') ?>"><i class="bi bi-arrow-repeat"></i> <span id="wl-scrape-label"><?= _h('a.wl.scrape') ?></span></button>
                            <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle dropdown-toggle-split" id="btn-wl-scrape-caret" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="<?= _h('a.wl.scrape_more_title') ?>"><span class="visually-hidden"><?= _h('a.wl.scrape_toggle') ?></span></button>
                            <ul class="dropdown-menu dropdown-menu-end wl-dd-menu">
                                <li><h6 class="dropdown-header"><?= __('a.wl.scrape_dd_head') ?></h6></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="page"><i class="bi bi-file-earmark"></i> <?= _h('a.wl.scrape_page') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-near><i class="bi bi-files"></i> <?= __('a.wl.scrape_near', ['n' => max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2)))]) ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="stale"><i class="bi bi-hourglass-split"></i> <?= _h('a.wl.scrape_stale') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="all"><i class="bi bi-collection"></i> <?= _h('a.wl.scrape_all') ?></button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header"><?= __('a.wl.added_within') ?></h6></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="24"><i class="bi bi-clock-history"></i> <?= _h('a.wl.last_24h') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="168"><i class="bi bi-calendar-week"></i> <?= _h('a.wl.last_7d') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="336"><i class="bi bi-calendar-range"></i> <?= _h('a.wl.last_14d') ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="custom"><i class="bi bi-calendar-event"></i> <?= __('a.wl.custom_range') ?></button></li>
                                <li><div class="dropdown-item-text wl-dd-note"><?= _h('a.wl.scrape_dd_note') ?></div></li>
                            </ul>
                        </div>
                        <button class="btn btn-sm btn-primary" id="btn-wl-add"><i class="bi bi-plus-lg"></i> <?= _h('a.wl.add_hashes') ?></button>
                    </div>
                </div>
                <div class="wl-chips d-hidden" id="wl-chips"></div>
            </div>

            <div class="wl-bulk d-hidden" id="wl-bulk">
                <span class="wl-bulk-count" id="wl-bulk-count"><?= _h('a.wl.n_selected', ['n' => 0]) ?></span>
                <button type="button" class="btn btn-sm btn-outline-danger" id="btn-bulk-delete"><i class="bi bi-trash"></i> <?= _h('common.delete') ?></button>
                <button type="button" class="btn btn-sm btn-outline-warning" id="btn-bulk-ban"><i class="bi bi-slash-circle"></i> <?= _h('a.wl.ban') ?></button>
                <button type="button" class="btn btn-sm btn-outline-info" id="btn-bulk-meta" title="<?= _h('a.wl.bulk_meta_title') ?>"><i class="bi bi-cloud-download"></i> <?= _h('a.wl.fetch_meta') ?></button>
                <?php /* Only rows that are WAITING move; the endpoint filters on review_status =
                         'pending', so these two are safe to press over a mixed selection. */ ?>
                <button type="button" class="btn btn-sm btn-outline-success" id="wl-approve"><i class="bi bi-check-lg"></i> <?= _h('a.wl.approve') ?></button>
                <button type="button" class="btn btn-sm btn-outline-danger" id="wl-reject"><i class="bi bi-x-lg"></i> <?= _h('a.wl.reject') ?></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bulk-clear"><i class="bi bi-x-lg"></i> <?= _h('a.wl.clear') ?></button>
            </div>

            <div class="table-responsive">
                <table class="table table-dark table-hover wl-table" id="wl-table">
                    <colgroup>
                        <col class="wl-c-check"><col class="wl-c-id"><col class="wl-c-hash"><col class="wl-c-flex"><col class="wl-c-size"><col class="wl-c-files"><col class="wl-c-source"><col class="wl-c-ip"><col class="wl-c-meta"><col class="wl-c-sl"><col class="wl-c-date"><col class="wl-c-actions">
                    </colgroup>
                    <thead><tr>
                        <th class="wl-th-check"><input type="checkbox" class="form-check-input" id="wl-select-all" title="<?= _h('a.wl.select_all_title') ?>"></th>
                        <th class="sortable" data-sort="id"><?= _h('a.wl.col_id') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="hash"><?= _h('a.wl.col_hash') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="name"><?= _h('a.wl.col_name') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="size"><?= _h('a.wl.col_size') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="files" title="<?= _h('a.wl.col_files_title') ?>"><?= _h('a.wl.col_files') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable col-badge" data-sort="source"><?= _h('a.wl.col_source') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="ip"><?= _h('a.wl.col_ip') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable col-badge" data-sort="meta"><?= _h('a.wl.col_meta') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="seeders" title="<?= _h('a.wl.col_sl_title') ?>"><?= _h('a.wl.col_sl') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="date"><?= _h('a.wl.col_date') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="th-actions wl-th-actions"><?= _h('a.wl.col_actions') ?></th>
                    </tr></thead>
                    <tbody id="wl-body"></tbody>
                </table>
            </div>
            <div class="admin-pagination" id="wl-pagination"></div>
        </div>

        <!-- ===== Banned hashes view ===== -->
        <div class="wl-view d-hidden" id="view-banned">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search">
                        <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                        <div class="search-input-wrap">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="bn-search" placeholder="<?= _h('a.wl.bn_search_ph') ?>">
                            <button type="button" class="search-clear-btn" id="bn-search-clear" title="<?= _h('a.wl.clear_search') ?>"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                    <div class="toolbar-right">
                        <span id="bn-total" class="text-muted wl-total"></span>
                        <button class="btn btn-sm btn-outline-danger" id="btn-bn-add"><i class="bi bi-slash-circle"></i> <?= _h('a.wl.ban_hashes') ?></button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover wl-table" id="bn-table">
                    <colgroup>
                        <col class="wl-c-hash"><col class="wl-c-flex"><col class="wl-c-flex"><col class="wl-c-source"><col class="wl-c-date"><col class="wl-c-actions-2">
                    </colgroup>
                    <thead><tr>
                        <th class="sortable" data-sort="hash"><?= _h('a.wl.col_hash') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th><?= _h('a.wl.col_name') ?></th>
                        <th class="sortable" data-sort="reason"><?= _h('a.wl.col_reason') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable col-badge" data-sort="source"><?= _h('a.wl.col_source') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="date"><?= _h('a.wl.col_date') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="th-actions wl-th-actions"><?= _h('a.wl.col_actions') ?></th>
                    </tr></thead>
                    <tbody id="bn-body"></tbody>
                </table>
            </div>
            <div class="admin-pagination" id="bn-pagination"></div>
        </div>

        <!-- ===== API clients view ===== -->
        <div class="wl-view d-hidden" id="view-clients">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="wl-toolbar-note text-muted"><i class="bi bi-info-circle"></i> <?= __('a.wl.cl_note') ?></div>
                    <div class="toolbar-right">
                        <span id="cl-total" class="text-muted wl-total"></span>
                        <button class="btn btn-sm btn-primary" id="btn-cl-create"><i class="bi bi-plus-lg"></i> <?= _h('a.wl.cl_create') ?></button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover wl-table" id="cl-table">
                    <colgroup>
                        <col class="wl-c-flex"><col class="wl-c-enabled"><col class="wl-c-keyid"><col class="wl-c-secret"><col class="wl-c-enabled"><col class="wl-c-date"><col class="wl-c-date"><col class="wl-c-ip"><col class="wl-c-num"><col class="wl-c-actions-2">
                    </colgroup>
                    <thead><tr>
                        <th><?= _h('a.wl.col_label') ?></th>
                        <th title="<?= _h('a.wl.col_scope_title') ?>"><?= _h('a.wl.col_scope') ?></th>
                        <th><?= _h('a.wl.col_keyid') ?></th>
                        <th><?= _h('a.wl.col_secret') ?></th>
                        <th class="col-badge"><?= _h('a.wl.col_enabled') ?></th>
                        <th><?= _h('a.wl.col_created') ?></th>
                        <th><?= _h('a.wl.col_last_used') ?></th>
                        <th><?= _h('a.wl.col_last_ip') ?></th>
                        <th class="wl-num"><?= _h('a.wl.col_requests') ?></th>
                        <th class="th-actions wl-th-actions"><?= _h('a.wl.col_actions') ?></th>
                    </tr></thead>
                    <tbody id="cl-body"></tbody>
                </table>
            </div>
        </div>

        <!-- ===== API bans view ===== -->
        <div class="wl-view d-hidden" id="view-bans">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search">
                        <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                        <div class="search-input-wrap">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ab-search" placeholder="<?= _h('a.wl.ab_search_ph') ?>">
                            <button type="button" class="search-clear-btn" id="ab-search-clear" title="<?= _h('a.wl.clear_search') ?>"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="ab-status">
                            <option value="active"><?= _h('a.wl.ab_active') ?></option>
                            <option value="all"><?= _h('a.wl.ab_all') ?></option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <span id="ab-total" class="text-muted wl-total"></span>
                        <button class="btn btn-sm btn-outline-danger" id="btn-ab-add"><i class="bi bi-shield-x"></i> <?= _h('a.wl.ban_ip') ?></button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover wl-table" id="ab-table">
                    <colgroup>
                        <col class="wl-c-ipv6"><col class="wl-c-bucket"><col class="wl-c-flex"><col class="wl-c-keyid"><col class="wl-c-flex"><col class="wl-c-date"><col class="wl-c-date"><col class="wl-c-date-lg"><col class="wl-c-actions-2">
                    </colgroup>
                    <thead><tr>
                        <th class="sortable" data-sort="ip"><?= _h('a.wl.col_ip') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th><?= _h('a.wl.col_bucket') ?></th>
                        <th class="sortable" data-sort="reason"><?= _h('a.wl.col_reason') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th><?= _h('a.wl.col_keyid') ?></th>
                        <th><?= _h('a.wl.col_endpoint') ?></th>
                        <th class="sortable" data-sort="date"><?= _h('a.wl.col_created') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="expires"><?= _h('a.wl.col_expires') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th><?= _h('a.wl.col_lifted') ?></th>
                        <th class="th-actions wl-th-actions"><?= _h('a.wl.col_actions') ?></th>
                    </tr></thead>
                    <tbody id="ab-body"></tbody>
                </table>
            </div>
            <div class="admin-pagination" id="ab-pagination"></div>
        </div>
    </div>

    <!-- Add hashes modal -->
    <div class="modal fade" id="wlAddModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-plus-circle text-info"></i> <?= _h('a.wl.add_modal_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted wl-hint"><?= __('a.wl.add_modal_note') ?></p>
                    <textarea class="form-control bg-dark text-light border-secondary wl-textarea" id="wl-add-input" rows="6" placeholder="magnet:?xt=urn:btih:...&#10;0123456789abcdef0123456789abcdef01234567"></textarea>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('common.close') ?></button>
                        <button type="button" class="btn btn-primary btn-sm" id="wl-add-submit"><i class="bi bi-plus-lg"></i> <?= _h('a.wl.add') ?></button>
                    </div>
                    <div id="wl-add-results" class="wl-results mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ban hashes modal -->
    <div class="modal fade" id="bnAddModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-slash-circle text-danger"></i> <?= _h('a.wl.ban_hashes') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted wl-hint"><?= __('a.wl.bn_modal_note') ?></p>
                    <textarea class="form-control bg-dark text-light border-secondary wl-textarea" id="bn-add-input" rows="6" placeholder="magnet:?xt=urn:btih:...&#10;0123456789abcdef0123456789abcdef01234567"></textarea>
                    <div class="mt-2">
                        <label class="form-label wl-label" for="bn-add-reason"><?= _h('a.wl.col_reason') ?> <small class="text-muted"><?= _h('a.wl.optional') ?></small></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="bn-add-reason" maxlength="255" placeholder="<?= _h('a.wl.bn_reason_ph') ?>">
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('common.close') ?></button>
                        <button type="button" class="btn btn-danger btn-sm" id="bn-add-submit"><i class="bi bi-slash-circle"></i> <?= _h('a.wl.ban') ?></button>
                    </div>
                    <div id="bn-add-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Details modal -->
    <div class="modal fade" id="wlDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-text"></i> <?= _h('a.wl.details_title') ?> <span id="wd-title-id" class="text-muted"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="wd-body"></div>
            </div>
        </div>
    </div>

    <!-- Reload tracker (password) modal -->
    <div class="modal fade" id="wlReloadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-arrow-clockwise text-info"></i> <?= _h('a.wl.reload_modal_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-2" style="font-size:0.9rem;"><?= __('a.wl.reload_modal_note', ['svc' => sanitize($svcName !== '' ? $svcName : '<service>')]) ?></p>
                    <form id="wl-reload-form">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.wl.admin_password') ?></label>
                            <?php // For the browser's password manager: this form has a password field, so without a named
                                  // username it pairs the page's search box with it. Visually hidden, never submitted. ?>
                            <input type="text" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
                            <input type="password" class="form-control bg-dark text-light border-secondary" id="wl-reload-password" autocomplete="current-password" required>
                        </div>
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                            <button type="submit" class="btn btn-info btn-sm text-dark"><i class="bi bi-arrow-clockwise"></i> <?= _h('a.wl.reload_now') ?></button>
                        </div>
                    </form>
                    <div id="wl-reload-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- API client token modal (shown once) -->
    <div class="modal fade" id="tokenModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-key text-warning"></i> <?= _h('a.wl.token_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="wl-hint"><strong><?= _h('a.wl.col_label') ?>:</strong> <span id="token-label"></span> &nbsp; <strong><?= _h('a.wl.col_keyid') ?>:</strong> <code id="token-keyid"></code></p>
                    <p class="text-warning wl-hint"><i class="bi bi-exclamation-triangle-fill"></i> <?= __('a.wl.token_warn') ?></p>
                    <div class="wl-copybox">
                        <code id="token-value" class="wl-copybox-code"></code>
                        <button type="button" class="btn btn-sm wl-copybox-btn" id="token-copy" title="<?= _h('a.wl.copy_token') ?>" aria-label="<?= _h('a.wl.copy_token') ?>"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <div class="wl-hint mt-3"><?= _h('a.wl.cl_docs') ?></div>
                    <div class="wl-copybox">
                        <code id="token-docs" class="wl-copybox-code"></code>
                        <button type="button" class="btn btn-sm wl-copybox-btn" id="token-docs-copy" title="<?= _h('a.wl.cl_docs_copy') ?>" aria-label="<?= _h('a.wl.cl_docs_copy') ?>"><i class="bi bi-clipboard"></i></button>
                        <a class="btn btn-sm wl-copybox-btn" id="token-docs-open" href="#" target="_blank" rel="noopener noreferrer" title="<?= _h('a.wl.cl_docs_open') ?>" aria-label="<?= _h('a.wl.cl_docs_open') ?>"><i class="bi bi-box-arrow-up-right"></i></a>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('a.wl.token_done') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php /* One editor for a key, used both when it is made and afterwards. Making a key used to be
             a chain of one-question prompts, which is a fine way to ask a question and a poor way to
             ask four — you cannot see the answers together, and the guide link at the bottom only
             means anything if you can watch it change while you decide. */ ?>
    <div class="modal fade" id="clOptsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-key text-warning"></i> <span id="cl-opts-title"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body cl-opts">
                    <div class="cl-field">
                        <label class="cl-label" for="cl-opts-label"><?= _h('a.wl.cl_label') ?></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="cl-opts-label" maxlength="100" placeholder="<?= _h('a.wl.cl_label_ph') ?>">
                    </div>
                    <div class="cl-field">
                        <label class="cl-label" for="cl-opts-scope"><?= _h('a.wl.col_scope') ?></label>
                        <?php /* THE SCOPE DECIDES WHAT THE REST OF THIS DIALOG IS.
                                 Each option names the endpoints it opens, because "users" and
                                 "federation" mean nothing to somebody who has not read the guide —
                                 and everything below reacts to this choice rather than sitting there
                                 collecting answers the API will never look at. */ ?>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="cl-opts-scope">
                            <option value="whitelist"><?= _h('a.wl.cl_scope_wl') ?></option>
                            <option value="abuse"><?= _h('a.wl.cl_scope_abuse') ?></option>
                            <option value="users"><?= _h('a.wl.cl_scope_users') ?></option>
                            <option value="federation"><?= _h('a.wl.cl_scope_fed') ?></option>
                            <option value="all"><?= _h('a.wl.cl_scope_all') ?></option>
                        </select>
                        <div class="cl-hint" id="cl-opts-scope-hint"><?= __('a.wl.cl_scope_hint') ?></div>
                    </div>

                    <?php /* What this key can call, spelled out in endpoints. A scope is a word; this
                             is the thing the word buys. */ ?>
                    <div class="cl-can" id="cl-opts-can"></div>

                    <?php /* Only for a key that can SUBMIT. A federation key never creates a
                             whitelist row, so asking how its submissions should be approved is
                             asking about something that cannot happen. */ ?>
                    <div id="cl-opts-submit-block" hidden>
                        <div class="cl-field">
                            <label class="cl-label" for="cl-opts-approve"><?= _h('a.wl.cl_approve') ?></label>
                            <select class="form-select form-select-sm bg-dark text-light border-secondary" id="cl-opts-approve">
                                <option value="auto"><?= _h('a.wl.cl_approve_auto') ?></option>
                                <option value="review"><?= _h('a.wl.cl_approve_rev') ?></option>
                            </select>
                            <div class="cl-hint" id="cl-opts-approve-hint"><?= __('a.wl.cl_approve_hint') ?></div>
                        </div>
                        <div class="cl-field">
                            <div class="cl-label"><?= _h('a.wl.cl_fields') ?></div>
                            <?php /* The hash is not a choice. Showing it ticked and locked is the
                                     difference between "these three are what an item needs" and
                                     "these three are what YOU are adding on top". */ ?>
                            <div class="form-check cl-check cl-check-fixed">
                                <input class="form-check-input" type="checkbox" id="cl-opts-f-hash" checked disabled>
                                <label class="form-check-label" for="cl-opts-f-hash"><?= _h('a.wl.cl_f_hash') ?> <code>magnet</code> / <code>hash</code>
                                    <span class="cl-always"><?= _h('a.wl.cl_f_always') ?></span></label>
                            </div>
                            <div class="form-check cl-check"><input class="form-check-input cl-opts-field" type="checkbox" id="cl-opts-f-name" value="name"><label class="form-check-label" for="cl-opts-f-name"><?= _h('a.wl.cl_f_name') ?> <code>name</code></label></div>
                            <div class="form-check cl-check"><input class="form-check-input cl-opts-field" type="checkbox" id="cl-opts-f-url" value="url"><label class="form-check-label" for="cl-opts-f-url"><?= _h('a.wl.cl_f_url') ?> <code>ref.url</code></label></div>
                            <div class="form-check cl-check"><input class="form-check-input cl-opts-field" type="checkbox" id="cl-opts-f-source_id" value="source_id"><label class="form-check-label" for="cl-opts-f-source_id"><?= _h('a.wl.cl_f_source_id') ?> <code>ref.post_id</code></label></div>
                            <div class="cl-hint"><?= __('a.wl.cl_fields_hint') ?></div>
                        </div>
                    </div>

                    <?php /* Only for a key that can REPORT. The two questions look like the two above
                             them and mean something else: this one decides whether a partner's
                             message takes a torrent off the tracker without anybody reading it. That
                             is why its default is the opposite one, and why it says so out loud. */ ?>
                    <div id="cl-opts-abuse-block" hidden>
                        <div class="cl-field">
                            <label class="cl-label" for="cl-opts-abuse"><?= _h('a.wl.cl_abuse') ?></label>
                            <select class="form-select form-select-sm bg-dark text-light border-secondary" id="cl-opts-abuse">
                                <option value="review"><?= _h('a.wl.cl_abuse_rev') ?></option>
                                <option value="auto"><?= _h('a.wl.cl_abuse_auto') ?></option>
                            </select>
                            <div class="cl-hint" id="cl-opts-abuse-hint"><?= __('a.wl.cl_abuse_hint') ?></div>
                        </div>
                        <div class="cl-field">
                            <div class="cl-label"><?= _h('a.wl.cl_afields') ?></div>
                            <div class="form-check cl-check cl-check-fixed">
                                <input class="form-check-input" type="checkbox" id="cl-opts-af-hash" checked disabled>
                                <label class="form-check-label" for="cl-opts-af-hash"><?= _h('a.wl.cl_f_hash') ?> <code>magnet</code> / <code>hash</code>
                                    <span class="cl-always"><?= _h('a.wl.cl_f_always') ?></span></label>
                            </div>
                            <div class="form-check cl-check"><input class="form-check-input cl-opts-afield" type="checkbox" id="cl-opts-af-title" value="title"><label class="form-check-label" for="cl-opts-af-title"><?= _h('a.wl.cl_af_title') ?> <code>title</code></label></div>
                            <div class="form-check cl-check"><input class="form-check-input cl-opts-afield" type="checkbox" id="cl-opts-af-evidence" value="evidence_url"><label class="form-check-label" for="cl-opts-af-evidence"><?= _h('a.wl.cl_af_evidence') ?> <code>evidence_url</code></label></div>
                            <div class="form-check cl-check"><input class="form-check-input cl-opts-afield" type="checkbox" id="cl-opts-af-reason" value="reason"><label class="form-check-label" for="cl-opts-af-reason"><?= _h('a.wl.cl_af_reason') ?> <code>reason</code></label></div>
                            <div class="form-check cl-check"><input class="form-check-input cl-opts-afield" type="checkbox" id="cl-opts-af-reporter" value="reporter"><label class="form-check-label" for="cl-opts-af-reporter"><?= _h('a.wl.cl_af_reporter') ?> <code>reporter</code></label></div>
                            <div class="form-check cl-check"><input class="form-check-input cl-opts-afield" type="checkbox" id="cl-opts-af-statement" value="statement"><label class="form-check-label" for="cl-opts-af-statement"><?= _h('a.wl.cl_af_statement') ?> <code>statement</code></label></div>
                            <div class="cl-hint"><?= __('a.wl.cl_afields_hint') ?></div>
                        </div>
                    </div>

                    <?php /* Only for a key that can act on accounts. The sign-in bridge lives on the
                             `users` scope, and a key made for it is useless while the bridge is off —
                             which is a thing to say here, next to the choice, not to discover later. */ ?>
                    <div class="cl-note" id="cl-opts-bridge-note" hidden></div>

                    <div class="cl-field cl-field-last">
                        <div class="cl-label"><?= _h('a.wl.cl_docs') ?></div>
                        <div class="wl-copybox">
                            <code id="cl-opts-docs" class="wl-copybox-code"></code>
                            <button type="button" class="btn btn-sm wl-copybox-btn" id="cl-opts-docs-copy" title="<?= _h('a.wl.cl_docs_copy') ?>" aria-label="<?= _h('a.wl.cl_docs_copy') ?>"><i class="bi bi-clipboard"></i></button>
                            <a class="btn btn-sm wl-copybox-btn" id="cl-opts-docs-open" href="#" target="_blank" rel="noopener noreferrer" title="<?= _h('a.wl.cl_docs_open') ?>" aria-label="<?= _h('a.wl.cl_docs_open') ?>"><i class="bi bi-box-arrow-up-right"></i></a>
                        </div>
                        <div class="cl-hint"><?= __('a.wl.cl_docs_hint') ?></div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                        <button type="button" class="btn btn-primary btn-sm" id="cl-opts-save"><?= _h('a.wl.cl_save') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- API ban snapshot modal -->
    <div class="modal fade" id="snapshotModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-shield-x text-danger"></i> <?= _h('a.wl.snapshot_title') ?> <span id="snapshot-title-id" class="text-muted"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="snapshot-meta" class="wl-kv wl-kv-cols mb-3"></div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="wl-label"><?= _h('a.wl.snapshot_label') ?></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="snapshot-copy"><i class="bi bi-clipboard"></i> <?= _h('a.wl.copy_json') ?></button>
                    </div>
                    <pre class="wl-pre" id="snapshot-pre"></pre>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-outline-warning btn-sm d-hidden" id="snapshot-lift"><i class="bi bi-unlock"></i> <?= _h('a.wl.lift_ban') ?></button>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('common.close') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- API ban add modal -->
    <div class="modal fade" id="abAddModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-shield-x text-danger"></i> <?= _h('a.wl.ab_modal_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="ab-add-form">
                        <div class="mb-2">
                            <label class="form-label wl-label" for="ab-add-ip"><?= _h('a.wl.ab_ip') ?></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ab-add-ip" required placeholder="<?= _h('a.wl.ab_ip_ph') ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label wl-label" for="ab-add-days"><?= _h('a.wl.ab_days') ?></label>
                            <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary" id="ab-add-days" min="1" max="3650" placeholder="30">
                        </div>
                        <div class="mb-2">
                            <label class="form-label wl-label" for="ab-add-reason"><?= _h('a.wl.col_reason') ?> <small class="text-muted"><?= _h('a.wl.optional') ?></small></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ab-add-reason" maxlength="255">
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-shield-x"></i> <?= _h('a.wl.ban_ip') ?></button>
                        </div>
                    </form>
                    <div id="ab-add-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>
    <?php $footerInPanel = true; include __DIR__ . '/../footer.php'; ?>

    <!-- Toast container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-whitelist.js<?= assetVer('assets/js/admin-whitelist.js') ?>"></script>
</body>
</html>
