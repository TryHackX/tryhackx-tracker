<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('a.tags.title') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <?= iconFontTag($cfg) ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/detail-panel.css<?= assetVer('assets/css/detail-panel.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl) ?>
</head>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>"
      data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>" data-site="<?= sanitize($baseUrl) ?>">
    <div class="admin-container admin-wide wl-page" role="main">
        <div class="admin-header">
            <h1 class="admin-title"><i class="bi bi-tags"></i> <?= _h('a.tags.title') ?> <span class="idx-subtitle"><?= _h('a.tags.subtitle') ?></span></h1>
            <?php $current = 'admin-tags'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <div class="source-tabs" id="tags-tabs">
            <button type="button" class="source-tab active" data-view="list"><i class="bi bi-tags"></i> <?= _h('a.tags.tab_list') ?> <span class="appeals-count-badge d-hidden" id="tags-n-list"></span></button>
            <button type="button" class="source-tab" data-view="cats"><i class="bi bi-folder2"></i> <?= _h('a.tags.tab_cats') ?> <span class="appeals-count-badge d-hidden" id="tags-n-cats"></span></button>
            <button type="button" class="source-tab" data-view="changes"><i class="bi bi-clock-history"></i> <?= _h('a.tags.tab_changes') ?> <span class="appeals-count-badge d-hidden" id="tags-n-changes"></span></button>
            <button type="button" class="source-tab" data-view="forum"><i class="bi bi-chat-square-text"></i> <?= _h('a.tags.tab_forum') ?> <span class="appeals-count-badge d-hidden" id="tags-n-forum"></span></button>
        </div>

        <div class="wl-view" id="tags-view-list">
            <div class="admin-toolbar-card">
                <div class="toolbar-row crep-filters">
                    <input type="search" class="form-control form-control-sm bg-dark text-light border-secondary crep-name-in" id="tags-f-q" maxlength="40" placeholder="<?= _h('a.tags.f_q') ?>" aria-label="<?= _h('a.tags.f_q') ?>">
                    <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="tags-f-status" aria-label="<?= _h('a.tags.f_status') ?>">
                        <option value=""><?= _h('a.tags.f_all') ?></option>
                        <option value="pending"><?= _h('a.tags.f_pending') ?></option>
                        <option value="approved"><?= _h('a.tags.f_approved') ?></option>
                        <option value="rejected"><?= _h('a.tags.f_rejected') ?></option>
                        <option value="hidden"><?= _h('a.tags.f_hidden') ?></option>
                        <option value="unused"><?= _h('a.tags.f_unused') ?></option>
                    </select>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="tags-f-cat" aria-label="<?= _h('a.tags.f_cat') ?>">
                        <option value=""><?= _h('a.tags.f_cat_all') ?></option>
                        <option value="none"><?= _h('a.tags.f_cat_none') ?></option>
                    </select>
                    <span class="wl-small text-muted" id="tags-total"></span>
                    <span class="wl-badge wl-b-pending d-hidden" id="tags-pending-badge" title="<?= _h('a.tags.pending_title') ?>"></span>
                </div>
                <div class="tags-bulk d-hidden" id="tags-bulk">
                    <span class="wl-small" id="tags-bulk-n"></span>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="tags-bulk-cat" aria-label="<?= _h('a.tags.bulk_cat') ?>"></select>
                    <button type="button" class="btn btn-sm btn-outline-info" id="tags-bulk-move"><i class="bi bi-folder2"></i> <?= _h('a.tags.bulk_move') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="tags-bulk-delete"><i class="bi bi-trash"></i> <?= _h('a.tags.bulk_delete') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="tags-bulk-clear"><?= _h('a.tags.bulk_clear') ?></button>
                </div>
                <small class="settings-hint d-block mt-2"><?= _h('a.tags.intro') ?></small>
            </div>

            <div class="table-responsive">
                <table class="table table-dark table-hover wl-table dash-table" id="tags-table">
                    <thead><tr>
                        <th class="tags-check"><input type="checkbox" class="form-check-input" id="tags-check-all" aria-label="<?= _h('a.tags.check_all') ?>"></th>
                        <th class="sortable" data-sort="name"><button type="button" class="th-sort"><?= _h('a.tags.col_tag') ?> <i class="bi bi-arrow-down-up sort-icon" aria-hidden="true"></i></button></th>
                        <th class="tags-cat-col sortable" data-sort="cat"><button type="button" class="th-sort"><?= _h('a.tags.col_cat') ?> <i class="bi bi-arrow-down-up sort-icon" aria-hidden="true"></i></button></th>
                        <th class="col-badge sortable" data-sort="status"><button type="button" class="th-sort"><?= _h('a.tags.col_status') ?> <i class="bi bi-arrow-down-up sort-icon" aria-hidden="true"></i></button></th>
                        <th class="sortable" data-sort="uses"><button type="button" class="th-sort"><?= _h('a.tags.col_uses') ?> <i class="bi bi-arrow-down-up sort-icon" aria-hidden="true"></i></button></th>
                        <th class="sortable" data-sort="created"><button type="button" class="th-sort"><?= _h('a.tags.col_created') ?> <i class="bi bi-arrow-down-up sort-icon" aria-hidden="true"></i></button></th>
                        <th class="th-actions"><?= _h('a.tags.col_actions') ?></th>
                    </tr></thead>
                    <tbody id="tags-body"></tbody>
                </table>
            </div>
            <div class="admin-pagination" id="tags-pagination"></div>
        </div>

        <div class="wl-view d-hidden" id="tags-view-cats">
            <div class="admin-toolbar-card">
                <h2 class="tags-f-title"><i class="bi bi-folder2"></i> <?= _h('a.tags.cats_title') ?></h2>
                <small class="settings-hint d-block mb-2"><?= _h('a.tags.cats_intro') ?></small>
                <div id="tags-cats-body"></div>
            </div>
        </div>

        <div class="wl-view d-hidden" id="tags-view-changes">
            <div class="admin-toolbar-card" id="tags-changes">
                <h2 class="tags-f-title"><i class="bi bi-clock-history"></i> <?= _h('a.tags.changes_title') ?></h2>
                <small class="settings-hint d-block mb-2"><?= _h('a.tags.changes_intro') ?></small>
                <div id="tags-changes-body"></div>
            </div>
        </div>

        <div class="wl-view d-hidden" id="tags-view-forum">
            <div class="admin-toolbar-card" id="tags-forum">
                <h2 class="tags-f-title"><i class="bi bi-chat-square-text"></i> <?= _h('a.tags.forum_title') ?></h2>
                <small class="settings-hint d-block mb-2"><?= _h('a.tags.forum_intro') ?></small>
                <div class="tags-f-tools">
                    <input type="search" class="form-control form-control-sm bg-dark text-light border-secondary tags-f-search" id="tags-f-search" maxlength="60" placeholder="<?= _h('a.tags.f_search_ph') ?>" aria-label="<?= _h('a.tags.f_search_ph') ?>">
                    <button type="button" class="btn btn-sm btn-outline-info" id="tags-f-refresh"><i class="bi bi-arrow-repeat"></i> <?= _h('a.tags.f_refresh') ?></button>
                </div>
                <small class="settings-hint d-block mb-2"><?= _h('a.tags.f_refresh_hint') ?></small>
                <div id="tags-forum-body"></div>
            </div>
        </div>
    </div>

    <?php $footerInPanel = true; include __DIR__ . '/../footer.php'; ?>

    <div class="modal fade" id="tagEditModal" tabindex="-1" aria-labelledby="te-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="te-title"><i class="bi bi-pencil"></i> <?= _h('a.tags.edit_title') ?> <span id="te-title-name" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= _h('common.close') ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label for="te-name" class="form-label wl-label"><?= _h('a.tags.edit_name') ?></label>
                            <input type="text" id="te-name" class="form-control form-control-sm bg-dark text-light border-secondary" maxlength="40">
                            <small class="settings-hint" id="te-slug"></small>
                        </div>
                        <div class="col-md-5">
                            <label for="te-cat" class="form-label wl-label"><?= _h('a.tags.edit_cat') ?></label>
                            <select id="te-cat" class="form-select form-select-sm bg-dark text-light border-secondary"></select>
                        </div>
                        <div class="col-12">
                            <span class="form-label wl-label d-block"><?= _h('a.tags.edit_color') ?></span>
                            <div class="te-color-row"><div id="te-swatches" class="tags-swatches" role="radiogroup" aria-label="<?= _h('a.tags.edit_color') ?>"></div><span id="te-preview" class="fchip fchip-x tags-chip tags-preview"></span></div>
                        </div>
                        <div class="col-12">
                            <label for="te-desc" class="form-label wl-label"><?= _h('a.tags.edit_desc') ?></label>
                            <input type="text" id="te-desc" class="form-control form-control-sm bg-dark text-light border-secondary" maxlength="200" placeholder="<?= _h('a.tags.edit_desc_ph') ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('a.tags.edit_cancel') ?></button>
                    <button type="button" class="btn btn-sm btn-success" id="te-save"><i class="bi bi-check-lg"></i> <?= _h('a.tags.edit_save') ?></button>
                </div>
            </div>
        </div>
    </div>

    <div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3"></div>
    <script<?= nonceAttr() ?>>if (window.LangSwap) window.LangSwap.mark();</script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-tags.js<?= assetVer('assets/js/admin-tags.js') ?>"></script>
</body>
</html>
