<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('a.backups.h1') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl) ?>
</head>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>"
      data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>"
      data-backup-db="<?= sanitize(backupDbName($cfg)) ?>"
      data-backup-enabled="<?= backupEnabled($cfg) ? '1' : '0' ?>">
    <div class="admin-container admin-wide wl-page">
        <div class="admin-header">
            <h2><i class="bi bi-archive"></i> <?= _h('a.backups.h1') ?> <span class="idx-subtitle"><?= __('a.backups.subtitle') ?></span></h2>
            <?php $current = 'admin-backups'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <!-- What this machine can do, what the last run did, and what the schedule will do next -->
        <div class="wl-status-card" id="bk-status-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-hdd"></i> <?= _h('a.backups.status_head') ?> <span class="wl-status-updated" id="bk-status-updated"></span></h6>
                <div class="wl-status-actions">
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-bk-run" disabled title="<?= _h('a.backups.run_title') ?>"><i class="bi bi-play-circle"></i> <?= __('a.backups.run_btn') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-warning d-hidden" id="btn-bk-cancel" title="<?= _h('a.backups.cancel_title') ?>"><i class="bi bi-stop-circle"></i> <?= __('a.backups.cancel_btn') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bk-prune" disabled title="<?= _h('a.backups.prune_title') ?>"><i class="bi bi-scissors"></i> <?= __('a.backups.prune_btn') ?></button>
                </div>
            </div>
            <div class="wl-status-grid" id="bk-status-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= __('a.backups.asking') ?></div>
            </div>
            <div id="bk-notes"></div>
            <!-- live progress while a run is in flight -->
            <div class="bk-progress d-hidden" id="bk-progress">
                <div class="bk-progress-head">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <strong id="bk-progress-step"><?= _h('a.backups.working') ?></strong>
                    <span class="wl-small text-muted" id="bk-progress-meta"></span>
                </div>
                <pre class="bk-log" id="bk-progress-log"></pre>
            </div>
        </div>

        <!-- The archives themselves -->
        <div class="admin-toolbar-card">
            <div class="toolbar-row">
                <!-- This is where the archives LIVE, not a search box. It used to sit inside
                     .toolbar-search, which is the input-shaped shell every other page puts a search
                     field in — border, radius, overflow hidden, and all of its padding on the icon
                     and the input. A bare span in there ends up welded to the left edge. -->
                <div class="bk-dir">
                    <i class="bi bi-folder2-open bk-dir-icon"></i>
                    <span class="bk-dir-label"><?= _h('a.backups.archives') ?></span>
                    <code class="bk-dir-path" id="bk-dir-label"></code>
                </div>
                <div class="toolbar-right">
                    <span id="bk-total" class="text-muted wl-total"></span>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle wl-table" id="bk-table">
                <!-- Every other table on a .wl-page carries a colgroup, and this one did not: with
                     `table-layout: fixed` the browser then splits the width equally between six
                     columns and `overflow: hidden` clips whatever does not fit — which is why the
                     four action buttons ran off the edge and the header read "ACTIO…". -->
                <colgroup>
                    <col class="bk-c-when"><col class="bk-c-profile"><col class="bk-c-size">
                    <col class="bk-c-flex"><col class="bk-c-integrity"><col class="bk-c-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th class="sortable" data-sort="when"><?= _h('a.backups.col_when') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="profile"><?= _h('a.backups.col_profile') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="size"><?= _h('a.backups.col_size') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th><?= _h('a.backups.col_contents') ?></th>
                        <th class="sortable" data-sort="integrity"><?= _h('a.backups.col_integrity') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="th-actions"><?= _h('a.backups.col_actions') ?></th>
                    </tr>
                </thead>
                <tbody id="bk-rows">
                    <tr><td colspan="6" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= _h('common.loading') ?></td></tr>
                </tbody>
            </table>
        </div>

        <p class="idx-note" id="bk-help">
            <?= _h('a.backups.help_secret') ?>
            <?= __('a.backups.help_where', ['seconds' => (int)BACKUP_TOKEN_TTL]) ?>
            <?= __('a.backups.help_offsite') ?>
        </p>
    </div>

    <!-- One password modal for every action; the text and the button change per operation. -->
    <div class="modal fade" id="bkConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-shield-lock text-warning"></i> <span id="bk-modal-title"><?= _h('a.backups.modal_title') ?></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-2" style="font-size:0.9rem;" id="bk-modal-text"></p>
                    <div id="bk-modal-extra"></div>
                    <form id="bk-confirm-form">
                        <!-- shown only for the database restore: the exact name has to be typed -->
                        <!-- Which profile this one run uses. Settings decides what the SCHEDULE does;
                             a backup somebody starts by hand is usually a different question ("dump
                             the database before I touch it"), and making them edit the schedule to
                             ask it is the wrong shape. Defaults to the configured profile, changes
                             nothing in Settings. -->
                        <div class="mb-3 d-hidden" id="bk-confirm-profile-row">
                            <label class="form-label wl-small" for="bk-confirm-profile"><?= _h('a.backups.what_to_back_up') ?></label>
                            <select class="form-select form-select-sm bg-dark text-light border-secondary" id="bk-confirm-profile"></select>
                            <div class="wl-small text-muted mt-1" id="bk-confirm-profile-hint"></div>
                        </div>
                        <div class="mb-3 d-hidden" id="bk-confirm-name-row">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.backups.type_db_name') ?> *</label>
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="bk-confirm-name" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.backups.admin_password') ?> *</label>
                            <?php // For the browser's password manager: this form has a password field, so without a named
                                  // username it pairs the page's search box with it. Visually hidden, never submitted. ?>
                            <input type="text" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
                            <input type="password" class="form-control bg-dark text-light border-secondary" id="bk-confirm-password" autocomplete="current-password" required>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> <?= _h('common.cancel') ?></button>
                            <button type="submit" class="btn btn-sm btn-outline-success" id="bk-confirm-ok"><i class="bi bi-check-lg"></i> <?= _h('a.backups.confirm_btn') ?></button>
                        </div>
                    </form>
                    <div id="bk-confirm-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Restore: pick what comes back, dry-run first -->
    <div class="modal fade" id="bkRestoreModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise text-warning"></i> <?= _h('a.backups.restore_from') ?> <span id="bk-restore-id" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-2" style="font-size:0.88rem;">
                        <?= _h('a.backups.restore_pick') ?>
                        <?= __('a.backups.restore_bak') ?>
                        <?= __('a.backups.restore_dry_first') ?>
                    </p>
                    <div id="bk-restore-items" class="bk-items"></div>
                    <div class="bk-db-restore" id="bk-db-restore-box">
                        <div class="wl-small text-warning mb-1"><i class="bi bi-exclamation-triangle"></i> <strong><?= _h('a.backups.db_separate') ?></strong></div>
                        <div class="wl-small text-muted mb-2">
                            <?= __('a.backups.db_separate_note') ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btn-bk-restore-db"><i class="bi bi-database-down"></i> <?= __('a.backups.restore_db_btn') ?></button>
                    </div>
                    <div id="bk-restore-alert" class="mt-2"></div>
                    <pre class="bk-log d-hidden" id="bk-restore-output"></pre>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('common.close') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-info" id="btn-bk-restore-dry"><i class="bi bi-eye"></i> <?= _h('a.backups.dry_run') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="btn-bk-restore-go"><i class="bi bi-arrow-counterclockwise"></i> <?= __('a.backups.restore_files_btn') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-backups.js<?= assetVer('assets/js/admin-backups.js') ?>"></script>
</body>
</html>
