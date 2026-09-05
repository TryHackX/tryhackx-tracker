<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('a.audit.title') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
</head>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>"
      data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>">
    <div class="admin-container admin-wide wl-page">
        <div class="admin-header">
            <h2><i class="bi bi-journal-text"></i> <?= _h('a.audit.title') ?> <span class="idx-subtitle"><?= _h('a.audit.subtitle') ?></span></h2>
            <?php $current = 'admin-audit'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <div class="admin-toolbar-card">
            <div class="toolbar-row">
                <div class="toolbar-search">
                    <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                    <div class="search-input-wrap">
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary"
                               id="au-search" placeholder="<?= _h('a.audit.search_ph') ?>">
                        <button type="button" class="search-clear-btn" id="au-search-clear" title="<?= _h('search.clear') ?>"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="au-group" title="<?= _h('a.audit.area') ?>">
                        <option value="all"><?= _h('a.audit.area_all') ?></option>
                    </select>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="au-actor" title="<?= _h('a.audit.who') ?>">
                        <option value=""><?= _h('a.audit.actor_any') ?></option>
                    </select>
                    <label class="search-check au-filters-end" title="<?= _h('a.audit.failed_title') ?>">
                        <input type="checkbox" id="au-failed"><span class="search-check-box" aria-hidden="true"></span> <?= _h('a.audit.failed') ?>
                    </label>
                </div>
                <div class="toolbar-right"><span id="au-total" class="text-muted wl-total"></span></div>
            </div>
            <div class="rv-explain wl-small text-muted" id="au-note"></div>
        </div>

        <div class="table-responsive">
            <table class="table table-dark table-hover dash-table wl-table" id="au-table">
                <thead><tr>
                    <th class="au-c-when"><?= _h('a.audit.c_when') ?></th>
                    <th class="au-c-who"><?= _h('a.audit.who') ?></th>
                    <th class="au-c-what"><?= _h('a.audit.c_what') ?></th>
                    <th><?= _h('a.audit.c_summary') ?></th>
                    <th class="au-c-ip"><?= _h('a.audit.c_from') ?></th>
                </tr></thead>
                <tbody id="au-rows"></tbody>
            </table>
        </div>
        <div class="admin-pagination" id="au-pagination"></div>
    </div>

    <div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3"></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-audit.js<?= assetVer('assets/js/admin-audit.js') ?>"></script>
</body>
</html>
