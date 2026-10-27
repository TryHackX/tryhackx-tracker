<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('a.img.title') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <?= iconFontTag($cfg) ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl) ?>
</head>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>"
      data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>" data-site="<?= sanitize($baseUrl) ?>">
    <div class="admin-container admin-wide wl-page" role="main">
        <div class="admin-header">
            <h1 class="admin-title"><i class="bi bi-image"></i> <?= _h('a.img.title') ?> <span class="idx-subtitle"><?= _h('a.img.subtitle') ?></span></h1>
            <?php $current = 'admin-images'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <div class="source-tabs" id="img-tabs" role="tablist">
            <button type="button" class="source-tab active" data-view="list" role="tab" aria-selected="true"><i class="bi bi-image"></i> <?= _h('a.img.tab_list') ?></button>
            <button type="button" class="source-tab" data-view="queue" role="tab" aria-selected="false"><i class="bi bi-cloud-download"></i> <?= _h('a.img.tab_queue') ?> <span id="img-queue-badge" class="appeals-count-badge d-hidden"></span></button>
        </div>
        <div id="img-said" class="crep-said" aria-live="polite"></div>

        <div class="wl-view" id="img-view-list">
            <div class="img-stats" id="img-summary"></div>
            <div class="admin-toolbar-card">
                <div class="toolbar-row crep-filters">
                    <input type="search" class="form-control form-control-sm bg-dark text-light border-secondary crep-name-in" id="img-f-q" maxlength="120" placeholder="<?= _h('a.img.f_q') ?>" aria-label="<?= _h('a.img.f_q') ?>">
                    <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="img-f-state" aria-label="<?= _h('a.img.f_state') ?>">
                        <option value=""><?= _h('a.img.f_state_all') ?></option>
                        <option value="public"><?= _h('a.img.f_state_public') ?></option>
                        <option value="private"><?= _h('a.img.f_state_private') ?></option>
                        <option value="copied"><?= _h('a.img.f_state_copied') ?></option>
                    </select>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="img-f-sort" aria-label="<?= _h('a.img.f_sort') ?>">
                        <option value=""><?= _h('a.img.f_sort_new') ?></option>
                        <option value="old"><?= _h('a.img.f_sort_old') ?></option>
                        <option value="big"><?= _h('a.img.f_sort_big') ?></option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="img-f-clear"><i class="bi bi-x-lg"></i> <?= _h('a.creport.clear') ?></button>
                    <span id="img-total" class="text-muted wl-total"></span>
                </div>
            </div>
            <details class="img-help"><summary><i class="bi bi-info-circle" aria-hidden="true"></i> <?= _h('a.img.help') ?></summary><p class="settings-hint"><?= _h('a.img.note') ?></p></details>
            <div id="img-grid" class="img-grid"></div>
            <div class="admin-pagination" id="img-pagination"></div>
        </div>

        <div class="wl-view d-hidden" id="img-view-queue">
            <p class="idx-note"><?= _h('a.img.queue_note') ?></p>
            <p class="settings-hint" id="img-hosts"></p>
            <div class="admin-toolbar-card">
                <div class="toolbar-row crep-filters">
                    <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="img-q-status" aria-label="<?= _h('a.img.q_status') ?>">
                        <option value="pending"><?= _h('a.img.q_pending') ?></option>
                        <option value="failed"><?= _h('a.img.q_failed') ?></option>
                        <option value="done"><?= _h('a.img.q_done') ?></option>
                        <option value="ignored"><?= _h('a.img.q_ignored') ?></option>
                    </select>
                    <span id="img-q-total" class="text-muted wl-total"></span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-sm align-middle img-queue">
                    <thead><tr>
                        <th><?= _h('a.img.q_url') ?></th>
                        <th><?= _h('a.img.q_state') ?></th>
                        <th><?= _h('a.img.q_tries') ?></th>
                        <th><?= _h('a.img.q_when') ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody id="img-q-body"></tbody>
                </table>
            </div>
            <div class="admin-pagination" id="img-q-pagination"></div>
        </div>
    </div>

    <?php $footerInPanel = true; include __DIR__ . '/../footer.php'; ?>

    <div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3"></div>
    <script<?= nonceAttr() ?>>if (window.LangSwap) window.LangSwap.mark();</script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/lightbox.js<?= assetVer('assets/js/lightbox.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-images.js<?= assetVer('assets/js/admin-images.js') ?>"></script>
</body>
</html>
