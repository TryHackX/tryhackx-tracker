<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <?php if (isRecaptchaEnabled($cfg, 'login')): ?>
    <style>
    .captcha-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.75); z-index:9999; justify-content:center; align-items:center; }
    .captcha-overlay.show { display:flex; }
    .captcha-box { background:#1e1e1e; border:1px solid #333; border-radius:8px; padding:1.5rem; text-align:center; max-width:340px; }
    .captcha-box p { color:#888; font-size:.85rem; margin-bottom:1rem; }
    .captcha-box .captcha-widget { display:inline-block; }
    </style>
    <script>
    const RECAPTCHA_SITEKEY = '<?= sanitize($cfg['recaptcha_site_key'] ?? '') ?>';
    </script>
    <script src="https://www.google.com/recaptcha/api.js?onload=onRecaptchaLoad&render=explicit" async defer></script>
    <?php endif; ?>
</head>
<body class="admin-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>">
    <div class="admin-container">
        <?php $svcName = trim($cfg['opentracker_service_name'] ?? ''); ?>
        <div class="admin-header">
            <h2><i class="bi bi-shield-lock"></i> Admin Panel</h2>
            <div class="admin-header-actions">
                <?php if ($svcName !== ''): ?>
                <div class="tracker-svc" id="tracker-svc">
                    <button type="button" class="tracker-warn-badge d-hidden" id="tracker-warn-badge" tabindex="0" aria-label="Tracker restart recommendations">
                        <i class="bi bi-exclamation-triangle-fill"></i><span id="tracker-warn-count" class="tracker-warn-count"></span>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info tracker-reload-btn" id="btn-reload-tracker" title="Reload the tracker blacklist (SIGHUP, no downtime) — <?= sanitize($svcName) ?>">
                        <i class="bi bi-arrow-clockwise"></i> Reload
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary tracker-restart-btn" id="btn-restart-tracker" title="Restart the tracker service (<?= sanitize($svcName) ?>)">
                        <i class="bi bi-bootstrap-reboot"></i> Restart tracker
                    </button>
                </div>
                <?php endif; ?>
                <a href="<?= $baseUrl ?>?action=settings" class="btn btn-sm btn-outline-info"><i class="bi bi-gear"></i> Settings</a>
                <button class="btn btn-sm btn-outline-danger" id="btn-logout"><i class="bi bi-box-arrow-right"></i> Logout</button>
            </div>
        </div>

        <!-- Source Tabs -->
        <div class="source-tabs">
            <button class="source-tab active" data-source="reports"><i class="bi bi-inbox"></i> Active Reports <span id="reports-badge" class="appeals-count-badge d-hidden"></span></button>
            <button class="source-tab" data-source="archives"><i class="bi bi-archive"></i> Archives <span id="archives-badge" class="appeals-count-badge d-hidden"></span></button>
            <button class="source-tab" data-source="appeals"><i class="bi bi-megaphone"></i> Appeals <span id="appeals-badge" class="appeals-count-badge d-hidden"></span></button>
            <button class="source-tab" data-source="appeal_archives"><i class="bi bi-archive"></i> Appeal Archives</button>
        </div>

        <!-- Toolbar -->
        <div class="admin-toolbar-card">
            <div class="toolbar-row">
                <div class="toolbar-search">
                    <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                    <div class="search-input-wrap">
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="search-input" placeholder="Search name, company, entity, email, hash, link...">
                        <button type="button" class="search-clear-btn" id="search-clear" title="Clear search"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="filter-status">
                        <option value="all">All statuses</option>
                        <option value="pending">Awaiting Review</option>
                        <option value="reviewed">Reviewed</option>
                        <option value="blocked">Blocked</option>
                    </select>
                </div>
                <div class="toolbar-right">
                    <span id="total-count" class="text-muted"></span>
                    <button class="btn btn-sm btn-outline-warning" id="btn-archive-all"><i class="bi bi-archive"></i> Archive reviewed</button>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-dark table-hover" id="reports-table">
                <thead><tr>
                    <th class="sortable" data-sort="id">ID <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="name">Name <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="email">Email <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="company">Company <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="representative">Entity <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="object">Object <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="hash">Info Hash <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="ip">IP <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable col-badge" data-sort="blocked">Status <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="date">Date <i class="bi bi-arrow-down sort-icon active"></i></th>
                    <th class="th-actions">Actions</th>
                </tr></thead>
                <tbody id="reports-body"></tbody>
            </table>
        </div>
        <div class="admin-pagination" id="pagination"></div>
    </div>

    <!-- Action Modal -->
    <div class="modal fade" id="actionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-text"></i> Report Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="modal-report-info" class="mb-3"></div>
                    <div class="d-flex flex-wrap gap-2 mb-3 justify-content-center" id="modal-actions">
                        <button class="btn btn-outline-danger btn-sm" id="modal-block"><i class="bi bi-slash-circle"></i> Block hash</button>
                        <button class="btn btn-outline-info btn-sm" id="modal-unblock" style="display:none"><i class="bi bi-unlock"></i> Unblock hash</button>
                        <button class="btn btn-outline-warning btn-sm" id="modal-archive"><i class="bi bi-archive"></i> Archive (no action)</button>
                        <button class="btn btn-outline-success btn-sm" id="modal-restore" style="display:none"><i class="bi bi-arrow-counterclockwise"></i> Restore to active</button>
                        <button class="btn btn-outline-danger btn-sm" id="modal-delete-perm"><i class="bi bi-trash"></i> Delete permanently</button>
                    </div>
                    <div id="modal-blacklist-warning" class="mb-2" style="display:none"></div>
                    <hr class="border-secondary">
                    <div class="text-center" id="modal-email-section">
                        <h6><i class="bi bi-envelope"></i> Send custom message to reporter</h6>
                        <p class="email-hint">Your message will be sent in a professional email template along with the full report details.</p>
                    </div>
                    <textarea class="form-control bg-dark text-light border-secondary" id="modal-email-msg" rows="3" placeholder="e.g. We have reviewed your report and would like to request additional documentation..."></textarea>
                    <div class="text-center mt-2">
                        <button class="btn btn-primary btn-sm" id="modal-send-email"><i class="bi bi-send"></i> Send Message</button>
                    </div>
                    <div id="modal-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Appeal Modal -->
    <div class="modal fade" id="appealModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-megaphone"></i> Appeal Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="appeal-modal-info" class="mb-3"></div>
                    <div id="appeal-modal-actions" class="d-flex flex-wrap gap-2 mb-3 justify-content-center">
                        <button class="btn btn-outline-success btn-sm" id="appeal-accept"><i class="bi bi-check-circle"></i> Accept Appeal</button>
                        <button class="btn btn-outline-danger btn-sm" id="appeal-reject"><i class="bi bi-x-circle"></i> Reject Appeal</button>
                        <button class="btn btn-outline-warning btn-sm" id="appeal-restore" style="display:none"><i class="bi bi-arrow-counterclockwise"></i> Restore to Active</button>
                    </div>
                    <hr class="border-secondary" id="appeal-response-hr">
                    <div class="text-center" id="appeal-response-header">
                        <h6><i class="bi bi-reply"></i> Admin Response (sent to appellant)</h6>
                    </div>
                    <textarea class="form-control bg-dark text-light border-secondary" id="appeal-response-msg" rows="3" placeholder="Optional response message to the appellant..."></textarea>
                    <div id="appeal-modal-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Modal -->
    <div class="modal confirm-modal" id="confirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content confirm-modal-content">
                <div class="modal-body text-center py-4">
                    <p id="confirmModal-msg" class="text-light mb-3 confirm-msg"></p>
                    <div class="d-flex justify-content-center gap-2">
                        <button class="btn btn-sm btn-outline-danger" id="confirmModal-cancel"><i class="bi bi-x-lg"></i> Cancel</button>
                        <button class="btn btn-sm btn-success" id="confirmModal-ok"><i class="bi bi-check-lg"></i> Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Permanent Delete Modal -->
    <div class="modal fade" id="deletePermModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-trash text-danger"></i> Permanent Deletion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-warning mb-3" style="font-size:0.9rem;"><strong>Warning:</strong> This will permanently delete the report and all its dependencies (sent emails, appeals) from the database. It will not be archived and will not appear in the transparency report.</p>
                    <form id="delete-perm-form">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;">Admin Password *</label>
                            <input type="password" class="form-control bg-dark text-light border-secondary" id="del-password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;">Reason for deletion (Sent to reporter) <small style="color: #a0a0b0;">(Optional)</small></label>
                            <textarea class="form-control bg-dark text-light border-secondary" id="del-reason" rows="3" placeholder="e.g. This was a duplicate test report."></textarea>
                        </div>
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Confirm Deletion</button>
                        </div>
                    </form>
                    <div id="del-modal-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <?php if (isRecaptchaEnabled($cfg, 'login')): ?>
    <div class="captcha-overlay" id="captcha-overlay">
        <div class="captcha-box">
            <p>Please verify you are human</p>
            <div id="captcha-widget" class="captcha-widget"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($svcName !== ''): ?>
    <!-- Restart Tracker Modal -->
    <div class="modal fade" id="restartTrackerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-arrow-clockwise text-warning"></i> Restart Tracker Service</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-2" style="font-size:0.9rem;">This runs <code>systemctl restart <?= sanitize($svcName) ?></code> on the server. The tracker will be briefly unavailable while it reloads (picking up the latest blacklist). Enter your admin password to confirm.</p>
                    <div id="restart-warn-list" class="mb-2"></div>
                    <form id="restart-tracker-form">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;">Admin Password *</label>
                            <input type="password" class="form-control bg-dark text-light border-secondary" id="restart-password" required>
                        </div>
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-warning btn-sm text-dark"><i class="bi bi-arrow-clockwise"></i> Restart now</button>
                        </div>
                    </form>
                    <div id="restart-modal-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reload Tracker Modal -->
    <div class="modal fade" id="reloadTrackerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-arrow-clockwise text-info"></i> Reload Tracker Blacklist</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-2" style="font-size:0.9rem;">This runs <code>systemctl reload <?= sanitize($svcName) ?></code> on the server, sending it a <strong>SIGHUP</strong> so it re-reads its white/blacklist <strong>without downtime</strong> (no dropped connections). Enter your admin password to confirm.</p>
                    <div id="reload-warn-list" class="mb-2"></div>
                    <form id="reload-tracker-form">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;">Admin Password *</label>
                            <input type="password" class="form-control bg-dark text-light border-secondary" id="reload-password" required>
                        </div>
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-info btn-sm text-dark"><i class="bi bi-arrow-clockwise"></i> Reload now</button>
                        </div>
                    </form>
                    <div id="reload-modal-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Toast container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" integrity="sha384-G/EV+4j2dNv+tEPo3++6LCgdCROaejBqfUeNjuKAiuXbjrxilcCdDz6ZAVfHWe1Y" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin.js<?= assetVer('assets/js/admin.js') ?>"></script>
</body>
</html>
