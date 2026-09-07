<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('a.users.title') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl) ?>
</head>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>" data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>">
    <div class="admin-container admin-wide wl-page">
        <div class="admin-header">
            <h2><i class="bi bi-people"></i> <?= _h('a.users.title') ?> <span class="idx-subtitle"><?= __('a.users.subtitle') ?></span></h2>
            <?php $current = 'admin-users'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <div class="wl-status-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-people"></i> <?= _h('a.users.accounts_head') ?> <span class="wl-status-updated" id="us-status-note"></span></h6>
            </div>
            <div class="wl-status-grid" id="us-status-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= _h('common.loading') ?></div>
            </div>
            <p class="idx-note" id="us-disabled-note" style="display:none"><?= __('a.users.disabled_note', ['url' => $baseUrl . '?action=settings#section-users']) ?></p>
        </div>

        <div class="source-tabs" id="us-tabs">
            <button type="button" class="source-tab active" data-view="users"><i class="bi bi-person"></i> <?= _h('a.users.title') ?></button>
            <button type="button" class="source-tab" data-view="groups"><i class="bi bi-people-fill"></i> <?= _h('a.users.groups') ?></button>
            <button type="button" class="source-tab" data-view="write"><i class="bi bi-envelope-paper"></i> <?= _h('a.users.write_head') ?></button>
        </div>

        <!-- Users view -->
        <div class="wl-view" id="view-users">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search">
                        <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                        <div class="search-input-wrap">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="us-search" placeholder="<?= _h('a.users.search_ph') ?>">
                            <button type="button" class="search-clear-btn" id="us-search-clear" title="<?= _h('a.users.search_clear') ?>"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="us-filter-status" title="<?= _h('a.users.status') ?>">
                            <option value=""><?= _h('a.users.all') ?></option>
                            <option value="active"><?= _h('a.users.active') ?></option>
                            <option value="banned"><?= _h('a.users.banned') ?></option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="us-filter-group" title="<?= _h('a.users.group') ?>">
                            <option value=""><?= _h('a.users.all_groups') ?></option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <span id="us-total" class="text-muted wl-total"></span>
                        <button type="button" class="btn btn-sm btn-outline-success" id="us-add-btn">
                            <i class="bi bi-person-plus"></i> <?= _h('a.users.add') ?>
                        </button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover dash-table wl-table" id="us-table">
                    <thead><tr>
                        <th class="us-c-pick"><label class="search-check" title="<?= _h('a.users.pick_all_title') ?>"><input type="checkbox" id="us-pick-all"><span class="search-check-box" aria-hidden="true"></span></label></th>
                        <th class="sortable" data-sort="id">ID <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="username"><?= _h('a.users.username') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="email"><?= _h('a.users.email') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="status"><?= _h('a.users.status') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="group" title="<?= _h('a.users.sort_group_title') ?>"><?= _h('a.users.groups') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="created"><?= _h('a.users.created') ?> <i class="bi bi-arrow-down sort-icon active"></i></th>
                        <th class="sortable" data-sort="login"><?= _h('a.users.last_login') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="th-actions"><?= _h('a.users.actions') ?></th>
                    </tr></thead>
                    <tbody id="us-body"></tbody>
                </table>
            </div>
            <div class="admin-pagination" id="us-pagination"></div>
        </div>

        <!-- Write to members -->
        <div class="wl-view d-hidden" id="view-write">
            <div class="wl-status-card">
                <div class="wl-status-head"><h6><i class="bi bi-envelope-paper"></i> <?= _h('a.users.write_head') ?></h6></div>
                <p class="idx-note"><?= __('a.users.write_intro') ?></p>
                <p class="idx-note" id="bm-off-note" style="display:none"><?= __('a.users.email_off', ['url' => $baseUrl . '?action=settings#section-users']) ?></p>

                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('a.users.who') ?></label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="bm-mode">
                            <option value="selected"><?= _h('a.users.who_selected') ?></option>
                            <option value="group"><?= _h('a.users.who_group') ?></option>
                            <option value="all"><?= _h('a.users.who_all') ?></option>
                        </select>
                    </div>
                    <div class="col-md-4" id="bm-group-wrap" style="display:none">
                        <label class="form-label"><?= _h('a.users.group') ?></label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="bm-group"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= _h('a.users.send_as') ?></label>
                        <div class="d-flex gap-3 pt-1" id="bm-sendas">
                            <label class="search-check"><input type="checkbox" id="bm-notify" checked><span class="search-check-box" aria-hidden="true"></span> <?= _h('a.users.notification') ?></label>
                            <label class="search-check"><input type="checkbox" id="bm-email"><span class="search-check-box" aria-hidden="true"></span> <?= _h('a.users.email') ?></label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label"><?= _h('a.users.subject') ?></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="bm-subject" maxlength="200" placeholder="<?= _h('a.users.subject_ph') ?>">
                    </div>
                    <div class="col-12">
                        <div class="bm-msg-head">
                            <label class="form-label mb-0" for="bm-body"><?= _h('a.users.message') ?></label>
                            <!-- Same set as the public description editor, and the same rule: a button
                                 whose syntax the chosen format cannot express hides itself, so the bar
                                 never offers markup the renderer will ignore. -->
                            <div class="bm-tools d-hidden" id="bm-tools" role="toolbar" aria-label="<?= _h('rt.toolbar') ?>">
                                <span class="bm-tool-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="bold" title="<?= _h('rt.bold') ?>"><i class="bi bi-type-bold"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="italic" title="<?= _h('rt.italic') ?>"><i class="bi bi-type-italic"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="underline" title="<?= _h('rt.underline') ?>"><i class="bi bi-type-underline"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="strike" title="<?= _h('rt.strike') ?>"><i class="bi bi-type-strikethrough"></i></button>
                                </span>
                                <span class="bm-tool-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="color" title="<?= _h('rt.color') ?>"><i class="bi bi-palette"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="size" title="<?= _h('rt.size') ?>"><i class="bi bi-fonts"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="highlight" title="<?= _h('rt.highlight') ?>"><i class="bi bi-highlighter"></i></button>
                                </span>
                                <span class="bm-tool-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="link" title="<?= _h('rt.link') ?>"><i class="bi bi-link-45deg"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="image" title="<?= _h('rt.image') ?>"><i class="bi bi-image"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="list" title="<?= _h('rt.list') ?>"><i class="bi bi-list-ul"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="olist" title="<?= _h('rt.olist') ?>"><i class="bi bi-list-ol"></i></button>
                                </span>
                                <span class="bm-tool-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="quote" title="<?= _h('rt.quote') ?>"><i class="bi bi-quote"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="code" title="<?= _h('rt.code') ?>"><i class="bi bi-code-slash"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="table" title="<?= _h('rt.table') ?>"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="center" title="<?= _h('rt.center') ?>"><i class="bi bi-text-center"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="hr" title="<?= _h('rt.hr') ?>"><i class="bi bi-dash-lg"></i></button>
                                </span>
                            </div>
                            <select class="form-select form-select-sm bg-dark text-light border-secondary bm-fmt" id="bm-format" title="<?= _h('a.users.fmt_title') ?>">
                                <option value="plain"><?= _h('a.users.fmt_plain') ?></option>
                            </select>
                        </div>
                        <textarea class="form-control form-control-sm bg-dark text-light border-secondary" id="bm-body" rows="7" maxlength="5000" placeholder="<?= _h('a.users.body_ph') ?>"></textarea>
                        <div class="bm-preview-wrap d-hidden" id="bm-preview-wrap">
                            <div class="bm-preview-head"><?= _h('a.users.preview_head') ?></div>
                            <!-- Rendered by api/admin/bulk_send.php (op: render), which calls the same
                                 bulkBodyHtml() the janitor calls. A preview drawn in the browser would
                                 be a second renderer to keep in step, and the first one to drift. -->
                            <div class="bm-preview" id="bm-preview-html"></div>
                        </div>
                        <small class="settings-hint" id="bm-fmt-hint"><?= _h('a.users.fmt_hint_plain') ?></small>
                    </div>
                </div>

                <div class="nl-note mt-3" id="bm-preview"><span class="text-muted"><?= _h('a.users.audience_hint') ?></span></div>

                <div class="wl-status-actions mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="bm-refresh"><i class="bi bi-people"></i> <?= _h('a.users.recount') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-info" id="bm-test"><i class="bi bi-send-check"></i> <?= _h('a.users.send_test') ?></button>
                    <button type="button" class="btn btn-sm btn-primary" id="bm-send"><i class="bi bi-send"></i> <span id="bm-send-label"><?= __('a.users.send_dots') ?></span></button>
                </div>
            </div>

            <div class="wl-status-card mt-3">
                <div class="wl-status-head"><h6><i class="bi bi-clock-history"></i> <?= _h('a.users.recent_head') ?> <span class="wl-status-updated" id="bm-depth"></span></h6></div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover dash-table wl-table" id="bm-table">
                        <thead><tr><th><?= _h('a.users.col_started') ?></th><th><?= _h('a.users.subject') ?></th><th><?= _h('a.users.col_total') ?></th><th><?= _h('a.users.col_sent') ?></th><th><?= _h('a.users.col_failed') ?></th><th><?= _h('a.users.col_waiting') ?></th><th class="th-actions"><?= _h('a.users.actions') ?></th></tr></thead>
                        <tbody id="bm-batches"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Groups view -->
        <div class="wl-view d-hidden" id="view-groups">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search"><span class="text-muted wl-small"><?= __('a.users.groups_note') ?></span></div>
                    <div class="toolbar-right">
                        <button type="button" class="btn btn-sm btn-primary" id="btn-group-new"><i class="bi bi-plus-lg"></i> <?= _h('a.users.new_group') ?></button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover dash-table wl-table" id="gr-table">
                    <thead><tr>
                        <th><?= _h('a.users.name') ?></th><th><?= _h('a.users.slug') ?></th><th><?= _h('a.users.priority') ?></th><th><?= _h('a.users.default') ?></th><th><?= _h('a.users.members') ?></th><th><?= _h('a.users.permissions') ?></th><th class="th-actions"><?= _h('a.users.actions') ?></th>
                    </tr></thead>
                    <tbody id="gr-body"></tbody>
                </table>
            </div>
            <?php // Groups across, permissions down. Fifteen ids over five groups is a table a person reads
                  // in one glance; the comma-separated key list in the table above is not. ?>
            <details class="gr-matrix-wrap mt-2">
                <summary class="wl-small text-muted"><?= _h('a.users.matrix_title') ?></summary>
                <div class="table-responsive mt-2"><table class="table table-dark table-sm gr-matrix" id="gr-matrix"></table></div>
            </details>
        </div>
    </div>

    <!-- Add user -->
    <div class="modal fade" id="userAddModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> <?= _h('a.users.add') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= _h('common.close') ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger py-2 wl-small d-none" id="ua-error"></div>
                    <div class="mb-3">
                        <label class="form-label wl-label"><?= _h('a.users.username') ?></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ua-username" autocomplete="off" maxlength="32">
                        <div class="invalid-feedback ua-msg" id="ua-username-msg"></div>
                    <small class="text-muted wl-small"><?= __('a.users.username_hint') ?></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label wl-label"><?= _h('a.users.email') ?> <small class="text-muted wl-small" id="ua-email-req"><?= _h('a.users.required_paren') ?></small></label>
                        <input type="email" class="form-control form-control-sm bg-dark text-light border-secondary" id="ua-email" autocomplete="off" maxlength="190">
                    <div class="invalid-feedback ua-msg" id="ua-email-msg"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label wl-label"><?= _h('a.users.password') ?></label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ua-password" autocomplete="new-password">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="ua-gen" title="<?= _h('a.users.gen_title') ?>">
                                <i class="bi bi-shuffle"></i> <?= _h('a.users.generate') ?>
                            </button>
                        </div>
                        <div class="ua-reqs" id="ua-pw-reqs"></div>
                        <small class="text-muted wl-small"><?= __('a.users.pw_clear_note') ?></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label wl-label"><?= _h('a.users.verify_label') ?></label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ua-verify">
                            <option value="auto" selected><?= __('a.users.verify_auto') ?></option>
                            <option value="send"><?= __('a.users.verify_send') ?></option>
                            <option value="none"><?= __('a.users.verify_none') ?></option>
                        </select>
                        <small class="text-muted wl-small" id="ua-verify-hint"></small>
                    </div>
                    <div class="mb-1">
                        <label class="form-label wl-label"><?= _h('a.users.status') ?></label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ua-status">
                            <option value="active" selected><?= _h('a.users.active') ?></option>
                            <option value="banned"><?= _h('a.users.banned_created') ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                    <button type="button" class="btn btn-sm btn-success" id="ua-save"><i class="bi bi-person-plus"></i> <?= _h('a.users.create') ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- User edit modal -->
    <div class="modal fade" id="usEditModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">


<div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-person-gear"></i> <?= _h('a.users.edit_title') ?> <span id="ue-name" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label wl-label"><?= _h('a.users.status') ?></label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ue-status">
                            <option value="active"><?= _h('a.users.active') ?></option>
                            <option value="banned"><?= _h('a.users.banned_nologin') ?></option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label wl-label"><?= _h('a.users.email') ?> <small class="text-muted"><?= _h('a.users.empty_none') ?></small></label>
                        <input type="email" class="form-control form-control-sm bg-dark text-light border-secondary" id="ue-email" maxlength="190">
                        <div class="invalid-feedback"><?= _h('a.users.email_invalid') ?></div>
                    </div>
                    <div class="mb-2 d-hidden" id="ue-email2-wrap">
                        <label class="form-label wl-label"><?= _h('a.users.email2') ?></label>
                        <input type="email" class="form-control form-control-sm bg-dark text-light border-secondary" id="ue-email2" maxlength="190" autocomplete="off">
                        <div class="invalid-feedback"><?= _h('a.users.email2_err') ?></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label wl-label"><?= _h('a.users.new_pass') ?> <small class="text-muted"><?= _h('a.users.new_pass_note') ?></small></label>
                        <input type="password" class="form-control form-control-sm bg-dark text-light border-secondary font-mono" id="ue-password" maxlength="200" autocomplete="new-password">
                        <div class="invalid-feedback"><?= _h('a.users.pw_rule') ?></div>
                    </div>
                    <div class="mb-2 d-hidden" id="ue-password2-wrap">
                        <label class="form-label wl-label"><?= _h('a.users.new_pass2') ?></label>
                        <input type="password" class="form-control form-control-sm bg-dark text-light border-secondary font-mono" id="ue-password2" maxlength="200" autocomplete="new-password">
                        <div class="invalid-feedback"><?= _h('a.users.pass2_err') ?></div>
                    </div>
                    <div id="ue-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                        <button type="button" class="btn btn-sm btn-primary" id="ue-save"><i class="bi bi-check-lg"></i> <?= _h('common.save') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Grant group modal -->
    <div class="modal fade" id="usGrantModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-award"></i> <?= _h('a.users.grant_title') ?> <span id="ug-name" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label wl-label"><?= _h('a.users.group') ?></label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ug-group"></select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label wl-label"><?= _h('a.users.duration') ?> <small class="text-muted"><?= _h('a.users.duration_note') ?></small></label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ug-duration">
                            <option value="1d"><?= _h('a.users.d_1d') ?></option>
                            <option value="7d"><?= _h('a.users.d_7d') ?></option>
                            <option value="14d"><?= _h('a.users.d_14d') ?></option>
                            <option value="1m"><?= _h('a.users.d_1m') ?></option>
                            <option value="3m"><?= _h('a.users.d_3m') ?></option>
                            <option value="6m"><?= _h('a.users.d_6m') ?></option>
                            <option value="1y"><?= _h('a.users.d_1y') ?></option>
                            <option value="permanent" selected><?= _h('a.users.d_permanent') ?></option>
                            <option value="custom"><?= __('a.users.d_custom') ?></option>
                        </select>
                    </div>
                    <div class="row g-2 d-hidden" id="ug-custom">
                        <div class="col-6"><label class="form-label wl-label"><?= _h('a.users.from') ?> <small class="text-muted"><?= _h('a.users.from_note') ?></small></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ug-from" placeholder="YYYY-MM-DD [HH:MM]"></div>
                        <div class="col-6"><label class="form-label wl-label"><?= _h('a.users.to') ?> <small class="text-muted"><?= _h('a.users.to_note') ?></small></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ug-to" placeholder="YYYY-MM-DD [HH:MM]"></div>
                    </div>
                    <div class="mb-2 mt-2">
                        <label class="form-label wl-label"><?= _h('a.users.note') ?> <small class="text-muted"><?= _h('a.users.note_hint') ?></small></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ug-note" maxlength="255" placeholder="<?= _h('a.users.note_ph') ?>">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ug-email">
                        <label class="form-check-label wl-small" for="ug-email"><?= _h('a.users.also_email') ?></label>
                    </div>
                    <div id="ug-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                        <button type="button" class="btn btn-sm btn-primary" id="ug-save"><i class="bi bi-check-lg"></i> <?= _h('a.users.grant') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notify modal -->
    <div class="modal fade" id="usNotifyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-bell"></i> <?= _h('a.users.notify_title') ?> <span id="un-name" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label wl-label"><?= _h('a.users.n_title') ?></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="un-title" maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label wl-label"><?= _h('a.users.message') ?> <small class="text-muted"><?= _h('a.users.optional') ?></small></label>
                        <textarea class="form-control form-control-sm bg-dark text-light border-secondary" id="un-body" rows="4" maxlength="5000"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="un-email">
                        <label class="form-check-label wl-small" for="un-email"><?= _h('a.users.also_email_as') ?></label>
                    </div>
                    <div id="un-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                        <button type="button" class="btn btn-sm btn-primary" id="un-send"><i class="bi bi-send"></i> <?= _h('a.users.send_btn') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Group editor modal -->
    <div class="modal fade" id="grEditModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-people-fill"></i> <span id="ge-title"><?= _h('a.users.group') ?></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-4"><label class="form-label wl-label"><?= _h('a.users.name') ?></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ge-name" maxlength="64"></div>
                        <div class="col-md-4"><label class="form-label wl-label"><?= _h('a.users.slug') ?> <small class="text-muted">(a-z 0-9 _ -)</small></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary font-mono" id="ge-slug" maxlength="64"></div>
                        <div class="col-md-2"><label class="form-label wl-label"><?= _h('a.users.color') ?></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary font-mono" id="ge-color" maxlength="9" placeholder="#4a9eff"></div>
                        <div class="col-md-2"><label class="form-label wl-label"><?= _h('a.users.priority') ?></label>
                            <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary" id="ge-priority" min="-1000" max="1000" value="0"></div>
                    </div>
                    <div class="mb-2 mt-2"><label class="form-label wl-label"><?= _h('a.users.description') ?> <small class="text-muted"><?= _h('a.users.desc_note') ?></small></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ge-desc" maxlength="255"></div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="ge-default">
                        <label class="form-check-label wl-small" for="ge-default"><?= _h('a.users.default_group') ?></label>
                    </div>
                    <label class="form-label wl-label"><?= _h('a.users.permissions') ?></label>
                    <?php // Presets: a starting point, never a lock. Each fills the checkboxes below and the
                          // operator still sees — and can change — every one of them before saving. ?>
                    <div class="ge-presets" id="ge-presets"></div>
                    <div id="ge-perms" class="ge-perms"></div>
                    <div id="ge-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                        <button type="button" class="btn btn-sm btn-primary" id="ge-save"><i class="bi bi-check-lg"></i> <?= _h('a.users.save_group') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-users.js<?= assetVer('assets/js/admin-users.js') ?>"></script>
</body>
</html>
