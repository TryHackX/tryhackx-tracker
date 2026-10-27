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

        <div class="admin-toolbar-card">
            <div class="toolbar-row crep-filters">
                <input type="search" class="form-control form-control-sm bg-dark text-light border-secondary crep-name-in" id="tags-f-q" maxlength="40" placeholder="<?= _h('a.tags.f_q') ?>" aria-label="<?= _h('a.tags.f_q') ?>">
                <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="tags-f-status" aria-label="<?= _h('a.tags.f_status') ?>">
                    <option value=""><?= _h('a.tags.f_all') ?></option>
                    <option value="pending"><?= _h('a.tags.f_pending') ?></option>
                    <option value="approved"><?= _h('a.tags.f_approved') ?></option>
                    <option value="rejected"><?= _h('a.tags.f_rejected') ?></option>
                    <option value="hidden"><?= _h('a.tags.f_hidden') ?></option>
                </select>
                <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="tags-f-sort" aria-label="<?= _h('a.tags.f_sort') ?>">
                    <option value="uses"><?= _h('a.tags.f_sort_uses') ?></option>
                    <option value="name"><?= _h('a.tags.f_sort_name') ?></option>
                    <option value="new"><?= _h('a.tags.f_sort_new') ?></option>
                </select>
                <span class="wl-small text-muted" id="tags-total"></span>
                <span class="wl-badge wl-b-pending d-hidden" id="tags-pending-badge" title="<?= _h('a.tags.pending_title') ?>"></span>
            </div>
            <small class="settings-hint d-block mt-2"><?= _h('a.tags.intro') ?></small>
        </div>

        <div class="table-responsive">
            <table class="table table-dark table-hover wl-table dash-table" id="tags-table">
                <thead><tr>
                    <th><?= _h('a.tags.col_tag') ?></th>
                    <th class="col-badge"><?= _h('a.tags.col_status') ?></th>
                    <th><?= _h('a.tags.col_uses') ?></th>
                    <th><?= _h('a.tags.col_created') ?></th>
                    <th class="th-actions"><?= _h('a.tags.col_actions') ?></th>
                </tr></thead>
                <tbody id="tags-body"></tbody>
            </table>
        </div>
        <div class="admin-pagination" id="tags-pagination"></div>

        <div class="admin-toolbar-card mt-4" id="tags-changes">
            <h2 class="tags-f-title"><i class="bi bi-clock-history"></i> <?= _h('a.tags.changes_title') ?></h2>
            <small class="settings-hint d-block mb-2"><?= _h('a.tags.changes_intro') ?></small>
            <div id="tags-changes-body"></div>
        </div>

        <div class="admin-toolbar-card mt-4" id="tags-forum">
            <h2 class="tags-f-title"><i class="bi bi-chat-square-text"></i> <?= _h('a.tags.forum_title') ?></h2>
            <small class="settings-hint d-block mb-2"><?= _h('a.tags.forum_intro') ?></small>
            <div id="tags-forum-body"></div>
        </div>
    </div>

    <?php $footerInPanel = true; include __DIR__ . '/../footer.php'; ?>

    <div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3"></div>
    <script<?= nonceAttr() ?>>if (window.LangSwap) window.LangSwap.mark();</script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-tags.js<?= assetVer('assets/js/admin-tags.js') ?>"></script>
</body>
</html>
