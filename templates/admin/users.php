<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
</head>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>" data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>">
    <div class="admin-container admin-wide wl-page">
        <div class="admin-header">
            <h2><i class="bi bi-people"></i> Users <span class="idx-subtitle">accounts, groups &amp; permissions</span></h2>
            <?php $current = 'admin-users'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <div class="wl-status-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-people"></i> User accounts <span class="wl-status-updated" id="us-status-note"></span></h6>
            </div>
            <div class="wl-status-grid" id="us-status-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading&hellip;</div>
            </div>
            <p class="idx-note" id="us-disabled-note" style="display:none">The user system is <strong>disabled</strong> — accounts exist but nobody can sign in. Enable it in <a href="<?= $baseUrl ?>?action=settings#section-users">Settings &rarr; User Accounts</a>.</p>
        </div>

        <div class="source-tabs" id="us-tabs">
            <button type="button" class="source-tab active" data-view="users"><i class="bi bi-person"></i> Users</button>
            <button type="button" class="source-tab" data-view="groups"><i class="bi bi-people-fill"></i> Groups</button>
            <button type="button" class="source-tab" data-view="write"><i class="bi bi-envelope-paper"></i> Write to members</button>
        </div>

        <!-- Users view -->
        <div class="wl-view" id="view-users">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search">
                        <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                        <div class="search-input-wrap">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="us-search" placeholder="Search username or email...">
                            <button type="button" class="search-clear-btn" id="us-search-clear" title="Clear search"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="us-filter-status" title="Status">
                            <option value="">All</option>
                            <option value="active">Active</option>
                            <option value="banned">Banned</option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="us-filter-group" title="Group">
                            <option value="">All groups</option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <span id="us-total" class="text-muted wl-total"></span>
                        <button type="button" class="btn btn-sm btn-outline-success" id="us-add-btn">
                            <i class="bi bi-person-plus"></i> Add user
                        </button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover dash-table wl-table" id="us-table">
                    <thead><tr>
                        <th class="us-c-pick"><label class="search-check" title="Select every user on this page"><input type="checkbox" id="us-pick-all"><span class="search-check-box" aria-hidden="true"></span></label></th>
                        <th class="sortable" data-sort="id">ID <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="username">Username <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="email">Email <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="status">Status <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="group" title="Sorts by the highest-priority active group">Groups <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sortable" data-sort="created">Created <i class="bi bi-arrow-down sort-icon active"></i></th>
                        <th class="sortable" data-sort="login">Last sign-in <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="th-actions">Actions</th>
                    </tr></thead>
                    <tbody id="us-body"></tbody>
                </table>
            </div>
            <div class="admin-pagination" id="us-pagination"></div>
        </div>

        <!-- Write to members -->
        <div class="wl-view d-hidden" id="view-write">
            <div class="wl-status-card">
                <div class="wl-status-head"><h6><i class="bi bi-envelope-paper"></i> Write to members</h6></div>
                <p class="idx-note">Two separate things, and you can send either or both. An <strong>in-app notification</strong> appears the next time somebody opens the site and costs nothing to send. An <strong>email</strong> leaves this machine, so it is queued and sent a few a minute &mdash; this server has no relay in front of <code>mail()</code>, and a burst from a domain that normally sends a handful a day is what gets password-reset mail filed as spam.</p>
                <p class="idx-note" id="bm-off-note" style="display:none">Email is <strong>off</strong>. Turn on <em>Write to everyone</em> in <a href="<?= $baseUrl ?>?action=settings#section-users">Settings &rarr; User Accounts</a>, or send the notification only.</p>

                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <label class="form-label">Who</label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="bm-mode">
                            <option value="selected">The users I ticked</option>
                            <option value="group">A group</option>
                            <option value="all">Everyone</option>
                        </select>
                    </div>
                    <div class="col-md-4" id="bm-group-wrap" style="display:none">
                        <label class="form-label">Group</label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="bm-group"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Send as</label>
                        <div class="d-flex gap-3 pt-1" id="bm-sendas">
                            <label class="search-check"><input type="checkbox" id="bm-notify" checked><span class="search-check-box" aria-hidden="true"></span> Notification</label>
                            <label class="search-check"><input type="checkbox" id="bm-email"><span class="search-check-box" aria-hidden="true"></span> Email</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Subject</label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="bm-subject" maxlength="200" placeholder="e.g. Scheduled maintenance on Sunday">
                    </div>
                    <div class="col-12">
                        <div class="bm-msg-head">
                            <label class="form-label mb-0" for="bm-body">Message</label>
                            <!-- Same set as the public description editor, and the same rule: a button
                                 whose syntax the chosen format cannot express hides itself, so the bar
                                 never offers markup the renderer will ignore. -->
                            <div class="bm-tools d-hidden" id="bm-tools" role="toolbar" aria-label="Formatting">
                                <span class="bm-tool-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="bold" title="Bold (Ctrl+B)"><i class="bi bi-type-bold"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="italic" title="Italic (Ctrl+I)"><i class="bi bi-type-italic"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="underline" title="Underline"><i class="bi bi-type-underline"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="strike" title="Strikethrough"><i class="bi bi-type-strikethrough"></i></button>
                                </span>
                                <span class="bm-tool-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="color" title="Colour"><i class="bi bi-palette"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="size" title="Font size"><i class="bi bi-fonts"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="highlight" title="Highlight"><i class="bi bi-highlighter"></i></button>
                                </span>
                                <span class="bm-tool-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="link" title="Link (Ctrl+K)"><i class="bi bi-link-45deg"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="image" title="Image"><i class="bi bi-image"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="list" title="Bulleted list"><i class="bi bi-list-ul"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="olist" title="Numbered list"><i class="bi bi-list-ol"></i></button>
                                </span>
                                <span class="bm-tool-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="quote" title="Quote"><i class="bi bi-quote"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="code" title="Code"><i class="bi bi-code-slash"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="table" title="Table"><i class="bi bi-table"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="center" title="Centre"><i class="bi bi-text-center"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-md="hr" title="Horizontal rule"><i class="bi bi-dash-lg"></i></button>
                                </span>
                            </div>
                            <select class="form-select form-select-sm bg-dark text-light border-secondary bm-fmt" id="bm-format" title="How the message is written">
                                <option value="plain">Plain text</option>
                            </select>
                        </div>
                        <textarea class="form-control form-control-sm bg-dark text-light border-secondary" id="bm-body" rows="7" maxlength="5000" placeholder="Line breaks are kept."></textarea>
                        <div class="bm-preview-wrap d-hidden" id="bm-preview-wrap">
                            <div class="bm-preview-head">Preview of the email</div>
                            <!-- Rendered by api/admin/bulk_send.php (op: render), which calls the same
                                 bulkBodyHtml() the janitor calls. A preview drawn in the browser would
                                 be a second renderer to keep in step, and the first one to drift. -->
                            <div class="bm-preview" id="bm-preview-html"></div>
                        </div>
                        <small class="settings-hint" id="bm-fmt-hint">Plain text: line breaks are kept and nothing else is interpreted.</small>
                    </div>
                </div>

                <div class="nl-note mt-3" id="bm-preview"><span class="text-muted">Choose an audience to see who would receive this.</span></div>

                <div class="wl-status-actions mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="bm-refresh"><i class="bi bi-people"></i> Recount</button>
                    <button type="button" class="btn btn-sm btn-outline-info" id="bm-test"><i class="bi bi-send-check"></i> Send one to me</button>
                    <button type="button" class="btn btn-sm btn-primary" id="bm-send"><i class="bi bi-send"></i> <span id="bm-send-label">Send&hellip;</span></button>
                </div>
            </div>

            <div class="wl-status-card mt-3">
                <div class="wl-status-head"><h6><i class="bi bi-clock-history"></i> Recent sends <span class="wl-status-updated" id="bm-depth"></span></h6></div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover dash-table wl-table" id="bm-table">
                        <thead><tr><th>Started</th><th>Subject</th><th>Total</th><th>Sent</th><th>Failed</th><th>Waiting</th><th class="th-actions">Actions</th></tr></thead>
                        <tbody id="bm-batches"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Groups view -->
        <div class="wl-view d-hidden" id="view-groups">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search"><span class="text-muted wl-small"><strong>guest</strong> = permissions of <em>anonymous</em> visitors only. A signed-in user has exactly the <em>union</em> of their own groups (guest is NOT inherited — a member can see less than a guest). <strong>admin</strong> members pass every check. Priority only orders badges, it never overrides permissions.</span></div>
                    <div class="toolbar-right">
                        <button type="button" class="btn btn-sm btn-primary" id="btn-group-new"><i class="bi bi-plus-lg"></i> New group</button>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover dash-table wl-table" id="gr-table">
                    <thead><tr>
                        <th>Name</th><th>Slug</th><th>Priority</th><th>Default</th><th>Members</th><th>Permissions</th><th class="th-actions">Actions</th>
                    </tr></thead>
                    <tbody id="gr-body"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add user -->
    <div class="modal fade" id="userAddModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> Add user</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger py-2 wl-small d-none" id="ua-error"></div>
                    <div class="mb-3">
                        <label class="form-label wl-label">Username</label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ua-username" autocomplete="off" maxlength="32">
                        <small class="text-muted wl-small">3&ndash;32 characters: letters, digits, dot, dash or underscore.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label wl-label">Email <small class="text-muted wl-small" id="ua-email-req">(required)</small></label>
                        <input type="email" class="form-control form-control-sm bg-dark text-light border-secondary" id="ua-email" autocomplete="off" maxlength="190">
                    </div>
                    <div class="mb-3">
                        <label class="form-label wl-label">Password</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ua-password" autocomplete="new-password">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="ua-gen" title="Generate a strong one">
                                <i class="bi bi-shuffle"></i> Generate
                            </button>
                        </div>
                        <small class="text-muted wl-small">At least 8 characters with a lowercase and an uppercase letter, a digit and a special character.
                            Shown in clear on purpose &mdash; you have to be able to pass it on.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label wl-label">Email verification</label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ua-verify">
                            <option value="auto" selected>Already verified &mdash; no email sent, can sign in now</option>
                            <option value="send">Send a verification link &mdash; acts as a guest until clicked</option>
                            <option value="none">No email at all &mdash; unverified, verify later</option>
                        </select>
                        <small class="text-muted wl-small" id="ua-verify-hint"></small>
                    </div>
                    <div class="mb-1">
                        <label class="form-label wl-label">Status</label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ua-status">
                            <option value="active" selected>Active</option>
                            <option value="banned">Banned (created, but cannot sign in)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-success" id="ua-save"><i class="bi bi-person-plus"></i> Create account</button>
                </div>
            </div>
        </div>
    </div>

    <!-- User edit modal -->
    <div class="modal fade" id="usEditModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                

<div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-person-gear"></i> Edit user <span id="ue-name" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label wl-label">Status</label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ue-status">
                            <option value="active">Active</option>
                            <option value="banned">Banned (cannot sign in)</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label wl-label">Email <small class="text-muted">(empty = none)</small></label>
                        <input type="email" class="form-control form-control-sm bg-dark text-light border-secondary" id="ue-email" maxlength="190">
                        <div class="invalid-feedback">That email address does not look valid.</div>
                    </div>
                    <div class="mb-2 d-hidden" id="ue-email2-wrap">
                        <label class="form-label wl-label">Repeat new email</label>
                        <input type="email" class="form-control form-control-sm bg-dark text-light border-secondary" id="ue-email2" maxlength="190" autocomplete="off">
                        <div class="invalid-feedback">Email addresses do not match.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label wl-label">New password <small class="text-muted">(empty = unchanged; signs the user out of remembered devices)</small></label>
                        <input type="password" class="form-control form-control-sm bg-dark text-light border-secondary font-mono" id="ue-password" maxlength="200" autocomplete="new-password">
                        <div class="invalid-feedback">Min 8 characters with a lowercase, an uppercase, a digit and a special character.</div>
                    </div>
                    <div class="mb-2 d-hidden" id="ue-password2-wrap">
                        <label class="form-label wl-label">Repeat new password</label>
                        <input type="password" class="form-control form-control-sm bg-dark text-light border-secondary font-mono" id="ue-password2" maxlength="200" autocomplete="new-password">
                        <div class="invalid-feedback">Passwords do not match.</div>
                    </div>
                    <div id="ue-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="ue-save"><i class="bi bi-check-lg"></i> Save</button>
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
                    <h5 class="modal-title"><i class="bi bi-award"></i> Grant group to <span id="ug-name" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label wl-label">Group</label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ug-group"></select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label wl-label">Duration <small class="text-muted">(durations extend an existing membership)</small></label>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ug-duration">
                            <option value="1d">1 day</option>
                            <option value="7d">1 week</option>
                            <option value="14d">2 weeks</option>
                            <option value="1m">1 month</option>
                            <option value="3m">3 months</option>
                            <option value="6m">6 months</option>
                            <option value="1y">1 year</option>
                            <option value="permanent" selected>Permanent</option>
                            <option value="custom">Custom from&ndash;to&hellip;</option>
                        </select>
                    </div>
                    <div class="row g-2 d-hidden" id="ug-custom">
                        <div class="col-6"><label class="form-label wl-label">From <small class="text-muted">(empty = now)</small></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ug-from" placeholder="YYYY-MM-DD [HH:MM]"></div>
                        <div class="col-6"><label class="form-label wl-label">To <small class="text-muted">(empty = permanent)</small></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ug-to" placeholder="YYYY-MM-DD [HH:MM]"></div>
                    </div>
                    <div class="mb-2 mt-2">
                        <label class="form-label wl-label">Note <small class="text-muted">(shown to the user in the notification)</small></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ug-note" maxlength="255" placeholder="e.g. order #123">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="ug-email">
                        <label class="form-check-label wl-small" for="ug-email">Also send an email (if the user has an address)</label>
                    </div>
                    <div id="ug-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="ug-save"><i class="bi bi-check-lg"></i> Grant</button>
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
                    <h5 class="modal-title"><i class="bi bi-bell"></i> Message <span id="un-name" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label wl-label">Title</label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="un-title" maxlength="190">
                    </div>
                    <div class="mb-2">
                        <label class="form-label wl-label">Message <small class="text-muted">(optional)</small></label>
                        <textarea class="form-control form-control-sm bg-dark text-light border-secondary" id="un-body" rows="4" maxlength="5000"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="un-email">
                        <label class="form-check-label wl-small" for="un-email">Also send as an email (if the user has an address)</label>
                    </div>
                    <div id="un-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="un-send"><i class="bi bi-send"></i> Send</button>
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
                    <h5 class="modal-title"><i class="bi bi-people-fill"></i> <span id="ge-title">Group</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-4"><label class="form-label wl-label">Name</label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ge-name" maxlength="64"></div>
                        <div class="col-md-4"><label class="form-label wl-label">Slug <small class="text-muted">(a-z 0-9 _ -)</small></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary font-mono" id="ge-slug" maxlength="64"></div>
                        <div class="col-md-2"><label class="form-label wl-label">Color</label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary font-mono" id="ge-color" maxlength="9" placeholder="#4a9eff"></div>
                        <div class="col-md-2"><label class="form-label wl-label">Priority</label>
                            <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary" id="ge-priority" min="-1000" max="1000" value="0"></div>
                    </div>
                    <div class="mb-2 mt-2"><label class="form-label wl-label">Description <small class="text-muted">(shown to members on their account page)</small></label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ge-desc" maxlength="255"></div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="ge-default">
                        <label class="form-check-label wl-small" for="ge-default">Default group — granted automatically to every new account</label>
                    </div>
                    <label class="form-label wl-label">Permissions</label>
                    <div id="ge-perms" class="ge-perms"></div>
                    <div id="ge-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="ge-save"><i class="bi bi-check-lg"></i> Save group</button>
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
