<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Index &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
</head>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>" data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>" data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>" data-near-pages="<?= max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2))) ?>" data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>">
    <div class="admin-container admin-wide wl-page">
        <div class="admin-header">
            <h2><i class="bi bi-collection"></i> Index <span class="idx-subtitle">observed hashes &mdash; not a whitelist</span></h2>
            <?php $current = 'admin-index'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <!-- The swarm timeline moved to its own page (Traffic): every chart in the panel is in one
             place now. This page keeps the index's OPERATIONAL status, which is not a traffic metric. -->
        <div class="wl-status-card" id="idx-status-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-collection"></i> Observed-hash index <span class="wl-status-updated" id="idx-status-updated"></span></h6>
                <div class="wl-status-actions">
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-idx-poll" title="Fetch a full scrape from the tracker now and refresh the index (bounded by the poll budget)"><i class="bi bi-cloud-download"></i> Poll now</button>
                </div>
            </div>
            <div class="wl-status-grid" id="idx-status-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading status&hellip;</div>
            </div>
            <p class="idx-note" id="idx-disabled-note" style="display:none">The index is <strong>disabled</strong>. Enable it in <a href="<?= $baseUrl ?>?action=settings#section-index">Settings &rarr; Index</a> (measure the full-scrape cost during OPEN hours first).</p>
        </div>


        <div class="wl-view" id="view-index">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search">
                        <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                        <div class="search-input-wrap">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="idx-search" placeholder="Search hash prefix or name...">
                            <button type="button" class="search-clear-btn" id="idx-search-clear" title="Clear search"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="idx-filter-meta" title="Metadata status">
                            <option value="">All meta</option>
                            <option value="none">No metadata</option>
                            <option value="pending">Pending</option>
                            <option value="fetching">Fetching</option>
                            <option value="done">Done</option>
                            <option value="failed">Failed</option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="idx-filter-life" title="Lifecycle">
                            <option value="">All</option>
                            <option value="grace">In grace</option>
                            <option value="protected">Protected</option>
                            <option value="promoted">Promoted</option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="idx-perpage" title="Rows per page">
                            <option value="15">15 / page</option>
                            <option value="25" selected>25 / page</option>
                            <option value="50">50 / page</option>
                            <option value="100">100 / page</option>
                            <option value="200">200 / page</option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <div class="form-check form-check-inline wl-check m-0" title="Also match torrent file names (full-text)">
                            <input class="form-check-input" type="checkbox" id="idx-search-files">
                            <label class="form-check-label" for="idx-search-files">Also search file names</label>
                        </div>
                        <span id="idx-total" class="text-muted wl-total"></span>
                        <div class="btn-group wl-tool-dd" id="idx-meta-bulk-group">
                            <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle" id="btn-idx-meta-bulk" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="Queue metadata for many index rows; the worker fetches them after the whitelist queue is empty"><i class="bi bi-cloud-download"></i> Fetch metadata</button>
                            <ul class="dropdown-menu dropdown-menu-end wl-dd-menu">
                                <li><h6 class="dropdown-header">Queue metadata for&hellip;</h6></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="missing"><i class="bi bi-dash-circle"></i> Missing (never fetched)</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="failed"><i class="bi bi-x-circle"></i> Failed</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="missing_failed"><i class="bi bi-plus-slash-minus"></i> Missing + failed</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item" data-meta-page><i class="bi bi-file-earmark"></i> This page (missing + failed)</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-near><i class="bi bi-files"></i> Near pages &plusmn;<?= max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2))) ?> (missing + failed)</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="all"><i class="bi bi-arrow-repeat"></i> All rows (re-fetch)</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">First seen within&hellip; (missing + failed)</h6></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="24"><i class="bi bi-clock-history"></i> Last 24 hours</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="168"><i class="bi bi-calendar-week"></i> Last 7 days</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="336"><i class="bi bi-calendar-range"></i> Last 14 days</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="custom"><i class="bi bi-calendar-event"></i> Custom range&hellip;</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item text-danger" data-meta-cancel><i class="bi bi-x-octagon"></i> Cancel queued (resolved &rarr; done)</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-restore title="Every row that already has a name/size but lost its done status (bulk re-fetch, cancel) goes straight back to done — nothing is fetched or deleted"><i class="bi bi-arrow-counterclockwise"></i> Rebuild done (restore resolved rows)</button></li>
                            </ul>
                        </div>
                        <div class="btn-group wl-tool-dd" id="idx-scrape-bulk-group">
                            <button type="button" class="btn btn-sm btn-outline-info" id="btn-idx-scrape-bulk" title="Refresh seeders / leechers of the rows on this page (50 hashes per scrape request)"><i class="bi bi-arrow-repeat"></i> <span id="idx-scrape-label">Refresh S/L</span></button>
                            <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle dropdown-toggle-split" id="btn-idx-scrape-caret" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"><span class="visually-hidden">More scrape options</span></button>
                            <ul class="dropdown-menu dropdown-menu-end wl-dd-menu">
                                <li><h6 class="dropdown-header">Scrape seeders / leechers for&hellip;</h6></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="page"><i class="bi bi-file-earmark"></i> This page</button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-near><i class="bi bi-files"></i> Near pages &plusmn;<?= max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2))) ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="stale"><i class="bi bi-hourglass-split"></i> Stale (not scraped in 10 min)</button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="all"><i class="bi bi-collection"></i> All rows</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">First seen within&hellip;</h6></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="24"><i class="bi bi-clock-history"></i> Last 24 hours</button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="168"><i class="bi bi-calendar-week"></i> Last 7 days</button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="336"><i class="bi bi-calendar-range"></i> Last 14 days</button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="custom"><i class="bi bi-calendar-event"></i> Custom range&hellip;</button></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="wl-bulkbar d-hidden" id="idx-bulkbar">
                    <span id="idx-sel-count">0 selected</span>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-idx-promote"><i class="bi bi-arrow-up-circle"></i> Promote &rarr; whitelist</button>
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-idx-meta-sel"><i class="bi bi-cloud-download"></i> Fetch metadata</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-idx-delete"><i class="bi bi-trash"></i> Delete</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-idx-clearsel">Clear</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-dark table-hover dash-table wl-table" id="idx-table">
                    <colgroup>
                        <col class="idx-c-check"><col class="idx-c-hash"><col class="idx-c-name"><col class="idx-c-size"><col class="idx-c-files"><col class="idx-c-sl"><col class="idx-c-seen"><col class="idx-c-dates"><col class="idx-c-meta"><col class="idx-c-actions">
                    </colgroup>
                    <thead><tr>
                        <th class="idx-th-check"><input type="checkbox" id="idx-check-all" title="Select all on this page"></th>
                        <th class="sortable" data-sort="hash">Info hash <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="name">Name <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="size">Size <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="files" title="Resolved file count">Files <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="seeders">S / L <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="seen">Seen <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="last">First / last <i class="bi bi-arrow-down sort-icon active"></i></th>
                        <th class="sortable" data-sort="meta">Meta <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="th-actions">Actions</th>
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
                    <h5 class="modal-title"><i class="bi bi-collection"></i> Index entry</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="idx-modal-body"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-index.js<?= assetVer('assets/js/admin-index.js') ?>"></script>
</body>
</html>
