<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backups &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
</head>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>"
      data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>"
      data-backup-db="<?= sanitize(backupDbName($cfg)) ?>"
      data-backup-enabled="<?= backupEnabled($cfg) ? '1' : '0' ?>">
    <div class="admin-container admin-wide wl-page">
        <div class="admin-header">
            <h2><i class="bi bi-archive"></i> Backups <span class="idx-subtitle">database &amp; configuration archives</span></h2>
            <?php $current = 'admin-backups'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <!-- What this machine can do, what the last run did, and what the schedule will do next -->
        <div class="wl-status-card" id="bk-status-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-hdd"></i> Backup status <span class="wl-status-updated" id="bk-status-updated"></span></h6>
                <div class="wl-status-actions">
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-bk-run" disabled title="Make a backup now, with the profile from Settings"><i class="bi bi-play-circle"></i> Back up now&hellip;</button>
                    <button type="button" class="btn btn-sm btn-outline-warning d-hidden" id="btn-bk-cancel" title="Stop the backup that is running"><i class="bi bi-stop-circle"></i> Cancel run&hellip;</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bk-prune" disabled title="Apply the retention rules from Settings right now"><i class="bi bi-scissors"></i> Rotate now&hellip;</button>
                </div>
            </div>
            <div class="wl-status-grid" id="bk-status-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Asking the server&hellip;</div>
            </div>
            <div id="bk-notes"></div>
            <!-- live progress while a run is in flight -->
            <div class="bk-progress d-hidden" id="bk-progress">
                <div class="bk-progress-head">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <strong id="bk-progress-step">Working…</strong>
                    <span class="wl-small text-muted" id="bk-progress-meta"></span>
                </div>
                <pre class="bk-log" id="bk-progress-log"></pre>
            </div>
        </div>

        <!-- The archives themselves -->
        <div class="admin-toolbar-card">
            <div class="toolbar-row">
                <div class="toolbar-search">
                    <span class="text-muted wl-small" id="bk-dir-label"></span>
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
                        <th class="sortable" data-sort="when">When <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="profile">Profile <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="size">Size <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th>Contents</th>
                        <th class="sortable" data-sort="integrity">Integrity <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="th-actions">Actions</th>
                    </tr>
                </thead>
                <tbody id="bk-rows">
                    <tr><td colspan="6" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading&hellip;</td></tr>
                </tbody>
            </table>
        </div>

        <p class="idx-note" id="bk-help">
            An archive holds every database password on this machine. They live in a directory only
            <code>root</code> can enter, the panel reads them through the root helper, and a download link is
            single-use and expires after <?= (int)BACKUP_TOKEN_TTL ?> seconds. Keep a copy <strong>off this server</strong> —
            a backup on the same disk as the thing it protects is not a backup.
        </p>
    </div>

    <!-- One password modal for every action; the text and the button change per operation. -->
    <div class="modal fade" id="bkConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-shield-lock text-warning"></i> <span id="bk-modal-title">Confirm</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-2" style="font-size:0.9rem;" id="bk-modal-text"></p>
                    <div id="bk-modal-extra"></div>
                    <form id="bk-confirm-form">
                        <!-- shown only for the database restore: the exact name has to be typed -->
                        <div class="mb-3 d-hidden" id="bk-confirm-name-row">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;">Type the database name to confirm *</label>
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="bk-confirm-name" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;">Admin Password *</label>
                            <input type="password" class="form-control bg-dark text-light border-secondary" id="bk-confirm-password" autocomplete="current-password" required>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancel</button>
                            <button type="submit" class="btn btn-sm btn-outline-success" id="bk-confirm-ok"><i class="bi bi-check-lg"></i> Confirm</button>
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
                    <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise text-warning"></i> Restore from <span id="bk-restore-id" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-2" style="font-size:0.88rem;">
                        Pick exactly what should come back. Every file that gets overwritten keeps a
                        <code>.bak-&lt;stamp&gt;</code> copy next to it, so a restore is itself reversible.
                        Start with <strong>Dry run</strong> — it lists what would happen and changes nothing.
                    </p>
                    <div id="bk-restore-items" class="bk-items"></div>
                    <div class="bk-db-restore" id="bk-db-restore-box">
                        <div class="wl-small text-warning mb-1"><i class="bi bi-exclamation-triangle"></i> <strong>The database is separate.</strong></div>
                        <div class="wl-small text-muted mb-2">
                            Restoring it overwrites live data, so it is its own button: it asks you to type the database name,
                            and the server dumps the database as it is right now <em>before</em> importing anything.
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btn-bk-restore-db"><i class="bi bi-database-down"></i> Restore the database&hellip;</button>
                    </div>
                    <div id="bk-restore-alert" class="mt-2"></div>
                    <pre class="bk-log d-hidden" id="bk-restore-output"></pre>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-sm btn-outline-info" id="btn-bk-restore-dry"><i class="bi bi-eye"></i> Dry run</button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="btn-bk-restore-go"><i class="bi bi-arrow-counterclockwise"></i> Restore files&hellip;</button>
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
