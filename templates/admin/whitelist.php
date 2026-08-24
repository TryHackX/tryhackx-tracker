<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Whitelist &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <?php if (statsTimelineEnabled($cfg)): ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/vendor/uplot/uPlot.min.css<?= assetVer('assets/vendor/uplot/uPlot.min.css') ?>">
    <?php endif; ?>
</head>
<?php $svcName = trim($cfg['opentracker_service_name'] ?? ''); ?>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>" data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>" data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>" data-api-ban-days="<?= (int)($cfg['api_ban_days'] ?? 30) ?>" data-service="<?= sanitize($svcName) ?>" data-near-pages="<?= max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2))) ?>">
    <div class="admin-container admin-wide wl-page">
        <div class="admin-header">
            <h2><i class="bi bi-list-check"></i> Whitelist</h2>
            <div class="admin-header-actions">
                <a href="<?= $baseUrl ?>?action=admin" class="btn btn-sm btn-outline-info"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <a href="<?= $baseUrl ?>?action=admin-index" class="btn btn-sm btn-outline-info"><i class="bi bi-collection"></i> Index</a>
                <a href="<?= $baseUrl ?>?action=settings" class="btn btn-sm btn-outline-info"><i class="bi bi-gear"></i> Settings</a>
                <button class="btn btn-sm btn-outline-danger" id="btn-logout"><i class="bi bi-box-arrow-right"></i> Logout</button>
            </div>
        </div>

        <!-- Status card -->
        <div class="wl-status-card" id="wl-status-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-activity"></i> Tracker whitelist status <span class="wl-status-updated" id="wl-status-updated"></span></h6>
                <div class="wl-status-actions">
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-wl-regen" title="Rewrite the whitelist file from the database and ask the tracker to reload it"><i class="bi bi-file-earmark-arrow-down"></i> Regenerate file</button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btn-wl-import" title="Import every hash from the legacy blacklist file into the banned hashes"><i class="bi bi-box-arrow-in-down"></i> Import blacklist &rarr; bans</button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-wl-reload" title="Send SIGHUP to the tracker service so it re-reads the whitelist (requires the admin password)"><i class="bi bi-arrow-clockwise"></i> Reload tracker now</button>
                    <a href="<?= $baseUrl ?>?action=admin" class="btn btn-sm btn-outline-secondary" title="The tracker restart button lives on the dashboard"><i class="bi bi-bootstrap-reboot"></i> Restart&hellip;</a>
                </div>
            </div>
            <div class="wl-status-grid" id="wl-status-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading status&hellip;</div>
            </div>
            <ul class="wl-warnings d-hidden" id="wl-status-warnings"></ul>
        </div>

        <?php if (statsTimelineEnabled($cfg)): ?>
        <!-- Swarm timeline (assets/js/stats-timeline.js + vendored uPlot; samples by tools/janitor.php) -->
        <div class="wl-status-card tl-card" id="wl-timeline-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-graph-up"></i> Swarm timeline <span class="wl-status-updated">one sample / <?= (int)statsTimelineInterval($cfg) ?> s · raw <?= (int)statsTimelineRawDays($cfg) ?> d · 5-min <?= (int)statsTimelineKeepDays($cfg) ?> d · <?= statsTimelinePublic($cfg) ? 'public' : 'admins only' ?></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-timeline" class="btn btn-sm btn-outline-secondary" title="Timeline settings"><i class="bi bi-gear"></i> Settings</a>
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-tl-toggle" data-tl-collapse="wl-timeline" aria-expanded="true" aria-controls="wl-timeline"><i class="bi bi-chevron-up"></i> <span>Collapse</span></button>
                </div>
            </div>
            <div id="wl-timeline" data-timeline data-range="24h" data-compact="1"></div>
        </div>
        <?php endif; ?>

        <!-- Sub-view tabs -->
        <div class="source-tabs" id="wl-tabs">
            <button class="source-tab active" data-view="whitelist"><i class="bi bi-list-check"></i> Whitelist <span id="tab-badge-whitelist" class="wl-tab-count"></span></button>
            <button class="source-tab" data-view="banned"><i class="bi bi-slash-circle"></i> Banned hashes <span id="tab-badge-banned" class="wl-tab-count"></span></button>
            <button class="source-tab" data-view="clients"><i class="bi bi-key"></i> API clients</button>
            <button class="source-tab" data-view="bans"><i class="bi bi-shield-x"></i> API bans</button>
        </div>

        <!-- ===== Whitelist view ===== -->
        <div class="wl-view" id="view-whitelist">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search">
                        <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                        <div class="search-input-wrap">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="wl-search" placeholder="Search hash prefix, IP, name...">
                            <button type="button" class="search-clear-btn" id="wl-search-clear" title="Clear search"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="wl-filter-source" title="Source">
                            <option value="">All sources</option>
                            <option value="web">Web</option>
                            <option value="api">API</option>
                            <option value="admin">Admin</option>
                            <option value="forum">Forum</option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="wl-filter-meta" title="Metadata status">
                            <option value="">All meta</option>
                            <option value="none">No metadata</option>
                            <option value="pending">Pending</option>
                            <option value="fetching">Fetching</option>
                            <option value="done">Done</option>
                            <option value="failed">Failed</option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="wl-filter-banned" title="Banned state">
                            <option value="active">Active</option>
                            <option value="banned">Banned</option>
                            <option value="all">Active + banned</option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <div class="form-check form-check-inline wl-check m-0" title="Also match torrent file names (full-text)">
                            <input class="form-check-input" type="checkbox" id="wl-search-files">
                            <label class="form-check-label" for="wl-search-files">Also search file names</label>
                        </div>
                        <div class="form-check form-switch wl-check m-0" title="Sort by IP and colour rows per submitter">
                            <input class="form-check-input" type="checkbox" role="switch" id="wl-group-ip">
                            <label class="form-check-label" for="wl-group-ip">Group by IP</label>
                        </div>
                        <span id="wl-total" class="text-muted wl-total"></span>
                        <!-- Bulk metadata queue: one UPDATE server-side, the worker drains the queue one hash after another -->
                        <div class="btn-group wl-tool-dd" id="wl-meta-bulk-group">
                            <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle" id="btn-wl-meta-bulk" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="Queue metadata fetching for many rows at once. The metadata worker then fetches them in the background, one hash after another."><i class="bi bi-cloud-download"></i> Fetch metadata</button>
                            <ul class="dropdown-menu dropdown-menu-end wl-dd-menu">
                                <li><h6 class="dropdown-header">Queue metadata for&hellip;</h6></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="missing"><i class="bi bi-dash-circle"></i> Missing (never fetched)</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="failed"><i class="bi bi-x-circle"></i> Failed</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="missing_failed"><i class="bi bi-plus-slash-minus"></i> Missing + failed</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item" data-meta-page><i class="bi bi-file-earmark"></i> This page (missing + failed)</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-near><i class="bi bi-files"></i> Near pages &plusmn;<?= max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2))) ?> (missing + failed)</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item" data-meta-scope="all"><i class="bi bi-arrow-repeat"></i> All active hashes (re-fetch)</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Added within&hellip; (missing + failed)</h6></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="24"><i class="bi bi-clock-history"></i> Last 24 hours</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="168"><i class="bi bi-calendar-week"></i> Last 7 days</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="336"><i class="bi bi-calendar-range"></i> Last 14 days</button></li>
                                <li><button type="button" class="dropdown-item" data-meta-date="custom"><i class="bi bi-calendar-event"></i> Custom range&hellip;</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button type="button" class="dropdown-item text-danger" data-meta-cancel><i class="bi bi-x-octagon"></i> Cancel queued (pending &rarr; none)</button></li>
                                <li><div class="dropdown-item-text wl-dd-note">Only queues the rows — the metadata worker fetches them in the background, one hash after another. Large queues take a while; <em>Cancel queued</em> stops the backlog (rows being fetched right now still finish).</div></li>
                            </ul>
                        </div>
                        <!-- Bulk scrape (split control): main button = this page, caret = stale / all -->
                        <div class="btn-group wl-tool-dd" id="wl-scrape-bulk-group">
                            <button type="button" class="btn btn-sm btn-outline-info" id="btn-wl-scrape-bulk" title="Refresh seeders / leechers of the rows on this page from the tracker (50 hashes per scrape request)"><i class="bi bi-arrow-repeat"></i> <span id="wl-scrape-label">Refresh S/L</span></button>
                            <button type="button" class="btn btn-sm btn-outline-info dropdown-toggle dropdown-toggle-split" id="btn-wl-scrape-caret" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="More scrape options"><span class="visually-hidden">Toggle scrape options</span></button>
                            <ul class="dropdown-menu dropdown-menu-end wl-dd-menu">
                                <li><h6 class="dropdown-header">Scrape seeders / leechers for&hellip;</h6></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="page"><i class="bi bi-file-earmark"></i> This page</button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-near><i class="bi bi-files"></i> Near pages &plusmn;<?= max(1, min(20, (int)($cfg['admin_near_pages'] ?? 2))) ?></button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="stale"><i class="bi bi-hourglass-split"></i> Stale (not scraped in 10 min)</button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-scope="all"><i class="bi bi-collection"></i> All active hashes</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Added within&hellip;</h6></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="24"><i class="bi bi-clock-history"></i> Last 24 hours</button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="168"><i class="bi bi-calendar-week"></i> Last 7 days</button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="336"><i class="bi bi-calendar-range"></i> Last 14 days</button></li>
                                <li><button type="button" class="dropdown-item" data-scrape-date="custom"><i class="bi bi-calendar-event"></i> Custom range&hellip;</button></li>
                                <li><div class="dropdown-item-text wl-dd-note">Batches of 50 hashes per tracker request; long runs continue automatically until everything is scraped.</div></li>
                            </ul>
                        </div>
                        <button class="btn btn-sm btn-primary" id="btn-wl-add"><i class="bi bi-plus-lg"></i> Add hashes</button>
                    </div>
                </div>
                <div class="wl-chips d-hidden" id="wl-chips"></div>
            </div>

            <div class="wl-bulk d-hidden" id="wl-bulk">
                <span class="wl-bulk-count" id="wl-bulk-count">0 selected</span>
                <button type="button" class="btn btn-sm btn-outline-danger" id="btn-bulk-delete"><i class="bi bi-trash"></i> Delete</button>
                <button type="button" class="btn btn-sm btn-outline-warning" id="btn-bulk-ban"><i class="bi bi-slash-circle"></i> Ban</button>
                <button type="button" class="btn btn-sm btn-outline-info" id="btn-bulk-meta" title="Queue metadata fetch for the selected rows (max 500 per request)"><i class="bi bi-cloud-download"></i> Fetch metadata</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bulk-clear"><i class="bi bi-x-lg"></i> Clear</button>
            </div>

            <div class="table-responsive">
                <table class="table table-dark table-hover wl-table" id="wl-table">
                    <colgroup>
                        <col class="wl-c-check"><col class="wl-c-id"><col class="wl-c-hash"><col class="wl-c-flex"><col class="wl-c-size"><col class="wl-c-source"><col class="wl-c-ip"><col class="wl-c-meta"><col class="wl-c-sl"><col class="wl-c-date"><col class="wl-c-actions">
                    </colgroup>
                    <thead><tr>
                        <th class="wl-th-check"><input type="checkbox" class="form-check-input" id="wl-select-all" title="Select all on this page"></th>
                        <th class="sortable" data-sort="id">ID <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="hash">Info Hash <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="name">Name <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="size">Size <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable col-badge" data-sort="source">Source <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="ip">IP <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable col-badge" data-sort="meta">Meta <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="seeders" title="Seeders / leechers (last scrape)">S / L <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="date">Date <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="th-actions wl-th-actions">Actions</th>
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
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="bn-search" placeholder="Search hash prefix or reason...">
                            <button type="button" class="search-clear-btn" id="bn-search-clear" title="Clear search"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                    <div class="toolbar-right">
                        <span id="bn-total" class="text-muted wl-total"></span>
                        <button class="btn btn-sm btn-outline-danger" id="btn-bn-add"><i class="bi bi-slash-circle"></i> Ban hashes</button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover wl-table" id="bn-table">
                    <colgroup>
                        <col class="wl-c-hash"><col class="wl-c-flex"><col class="wl-c-flex"><col class="wl-c-source"><col class="wl-c-date"><col class="wl-c-actions-2">
                    </colgroup>
                    <thead><tr>
                        <th class="sortable" data-sort="hash">Info Hash <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th>Name</th>
                        <th class="sortable" data-sort="reason">Reason <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable col-badge" data-sort="source">Source <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="date">Date <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="th-actions wl-th-actions">Actions</th>
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
                    <div class="wl-toolbar-note text-muted"><i class="bi bi-info-circle"></i> Server-to-server clients authenticate with <code>Authorization: Bearer key_id.secret</code>. The secret is shown once, at creation.</div>
                    <div class="toolbar-right">
                        <span id="cl-total" class="text-muted wl-total"></span>
                        <button class="btn btn-sm btn-primary" id="btn-cl-create"><i class="bi bi-plus-lg"></i> Create client</button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover wl-table" id="cl-table">
                    <colgroup>
                        <col class="wl-c-flex"><col class="wl-c-keyid"><col class="wl-c-secret"><col class="wl-c-enabled"><col class="wl-c-date"><col class="wl-c-date"><col class="wl-c-ip"><col class="wl-c-num"><col class="wl-c-actions-2">
                    </colgroup>
                    <thead><tr>
                        <th>Label</th>
                        <th>Key ID</th>
                        <th>Secret</th>
                        <th class="col-badge">Enabled</th>
                        <th>Created</th>
                        <th>Last used</th>
                        <th>Last IP</th>
                        <th class="wl-num">Requests</th>
                        <th class="th-actions wl-th-actions">Actions</th>
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
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ab-search" placeholder="Search IP prefix, key id or reason...">
                            <button type="button" class="search-clear-btn" id="ab-search-clear" title="Clear search"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="ab-status">
                            <option value="active">Active bans</option>
                            <option value="all">All bans</option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <span id="ab-total" class="text-muted wl-total"></span>
                        <button class="btn btn-sm btn-outline-danger" id="btn-ab-add"><i class="bi bi-shield-x"></i> Ban IP</button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover wl-table" id="ab-table">
                    <colgroup>
                        <col class="wl-c-ipv6"><col class="wl-c-bucket"><col class="wl-c-flex"><col class="wl-c-keyid"><col class="wl-c-flex"><col class="wl-c-date"><col class="wl-c-date"><col class="wl-c-date-lg"><col class="wl-c-actions-2">
                    </colgroup>
                    <thead><tr>
                        <th class="sortable" data-sort="ip">IP <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th>Bucket</th>
                        <th class="sortable" data-sort="reason">Reason <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th>Key ID</th>
                        <th>Endpoint</th>
                        <th class="sortable" data-sort="date">Created <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="expires">Expires <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th>Lifted</th>
                        <th class="th-actions wl-th-actions">Actions</th>
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
                    <h5 class="modal-title"><i class="bi bi-plus-circle text-info"></i> Add hashes to the whitelist</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted wl-hint">One magnet link or 40-character info hash per line (max 500). Added hashes get source <code>admin</code> and metadata is fetched automatically by the worker.</p>
                    <textarea class="form-control bg-dark text-light border-secondary wl-textarea" id="wl-add-input" rows="6" placeholder="magnet:?xt=urn:btih:...&#10;0123456789abcdef0123456789abcdef01234567"></textarea>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary btn-sm" id="wl-add-submit"><i class="bi bi-plus-lg"></i> Add</button>
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
                    <h5 class="modal-title"><i class="bi bi-slash-circle text-danger"></i> Ban hashes</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted wl-hint">One magnet link or info hash per line (max 500). Banned hashes are removed from the whitelist file and can never be registered again until unbanned.</p>
                    <textarea class="form-control bg-dark text-light border-secondary wl-textarea" id="bn-add-input" rows="6" placeholder="magnet:?xt=urn:btih:...&#10;0123456789abcdef0123456789abcdef01234567"></textarea>
                    <div class="mt-2">
                        <label class="form-label wl-label" for="bn-add-reason">Reason <small class="text-muted">(optional)</small></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="bn-add-reason" maxlength="255" placeholder="e.g. DMCA notice #1234">
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-danger btn-sm" id="bn-add-submit"><i class="bi bi-slash-circle"></i> Ban</button>
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
                    <h5 class="modal-title"><i class="bi bi-file-earmark-text"></i> Whitelist entry <span id="wd-title-id" class="text-muted"></span></h5>
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
                    <h5 class="modal-title"><i class="bi bi-arrow-clockwise text-info"></i> Reload Tracker Whitelist</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-2" style="font-size:0.9rem;">This runs <code>systemctl reload <?= sanitize($svcName !== '' ? $svcName : '<service>') ?></code> on the server, sending it a <strong>SIGHUP</strong> so it re-reads its white/blacklist <strong>without downtime</strong>. Enter your admin password to confirm.</p>
                    <form id="wl-reload-form">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;">Admin Password *</label>
                            <input type="password" class="form-control bg-dark text-light border-secondary" id="wl-reload-password" autocomplete="current-password" required>
                        </div>
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-info btn-sm text-dark"><i class="bi bi-arrow-clockwise"></i> Reload now</button>
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
                    <h5 class="modal-title"><i class="bi bi-key text-warning"></i> API client created</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="wl-hint"><strong>Label:</strong> <span id="token-label"></span> &nbsp; <strong>Key ID:</strong> <code id="token-keyid"></code></p>
                    <p class="text-warning wl-hint"><i class="bi bi-exclamation-triangle-fill"></i> Copy the bearer token now &mdash; it is <strong>shown only once</strong> and cannot be recovered. Only a hash of the secret is stored.</p>
                    <div class="wl-copybox">
                        <code id="token-value" class="wl-copybox-code"></code>
                        <button type="button" class="btn btn-sm wl-copybox-btn" id="token-copy" title="Copy token" aria-label="Copy token"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Done</button>
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
                    <h5 class="modal-title"><i class="bi bi-shield-x text-danger"></i> API ban <span id="snapshot-title-id" class="text-muted"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="snapshot-meta" class="wl-kv wl-kv-cols mb-3"></div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="wl-label">Request snapshot</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="snapshot-copy"><i class="bi bi-clipboard"></i> Copy JSON</button>
                    </div>
                    <pre class="wl-pre" id="snapshot-pre"></pre>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-outline-warning btn-sm d-hidden" id="snapshot-lift"><i class="bi bi-unlock"></i> Lift ban</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
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
                    <h5 class="modal-title"><i class="bi bi-shield-x text-danger"></i> Ban an IP from the API</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="ab-add-form">
                        <div class="mb-2">
                            <label class="form-label wl-label" for="ab-add-ip">IP address *</label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ab-add-ip" required placeholder="203.0.113.7 or 2001:db8::1">
                        </div>
                        <div class="mb-2">
                            <label class="form-label wl-label" for="ab-add-days">Days</label>
                            <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary" id="ab-add-days" min="1" max="3650" placeholder="30">
                        </div>
                        <div class="mb-2">
                            <label class="form-label wl-label" for="ab-add-reason">Reason <small class="text-muted">(optional)</small></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ab-add-reason" maxlength="255">
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-shield-x"></i> Ban IP</button>
                        </div>
                    </form>
                    <div id="ab-add-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-whitelist.js<?= assetVer('assets/js/admin-whitelist.js') ?>"></script>
    <?php if (statsTimelineEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/vendor/uplot/uPlot.iife.min.js<?= assetVer('assets/vendor/uplot/uPlot.iife.min.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/stats-timeline.js<?= assetVer('assets/js/stats-timeline.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
