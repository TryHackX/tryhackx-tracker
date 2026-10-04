<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('a.reports.title') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <?= iconFontTag($cfg) ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl) ?>
    <?php if (isCaptchaEnabled($cfg, 'login')): ?>
    <!-- CAPTCHA modal styles (.captcha-overlay / .captcha-box) are shared in assets/css/admin.css -->
    <?= captchaHeadTags($cfg) ?>
    <?php endif; ?>
</head>
<body class="admin-body admin-hc" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>" data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>">
    <div class="admin-container admin-wide">
        <?php $svcName = trim($cfg['opentracker_service_name'] ?? ''); ?>
        <div class="admin-header">
            <h2><i class="bi bi-flag"></i> <?= _h('a.reports.title') ?> <span class="idx-subtitle"><?= __('a.reports.subtitle') ?></span></h2>
            <?php $current = 'admin'; $navExtra = $svcName !== '' ? __DIR__ . '/_tracker_service.php' : null; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <?php
        /* WHICH QUEUES this session may see (1.71.0). The page opens for any of their view permissions
           (adminNavItems()' `any`), and draws only the tabs the session holds: the torrent reports and their
           appeals (panel.reports.view), the reported messages (panel.messages.view), and the reported comments,
           descriptions and shouts (panel.reports.<kind>.view, each while its feature is on). The first tab drawn
           is the one the page opens on. */
        $torrentTabs = panelCan($db, $cfg, 'panel.reports.view');
        $msgTab = pmEnabled($cfg) && panelCan($db, $cfg, 'panel.messages.view');
        $crKinds = function_exists('contentReportPanelKinds')
            ? array_keys(array_filter(contentReportPanelKinds($db, $cfg), fn($k) => $k['on'])) : [];
        $firstTab = $torrentTabs ? 'reports' : ($msgTab ? 'messages' : ($crKinds ? $crKinds[0] . 's' : ''));
        // Each kind's own glyph, as Settings draws it: the comments' bubble, a description's card, the room's.
        $crTabs = ['comment' => ['comments', 'bi-chat-left-text', 'a.reports.tab_comments'],
                   'description' => ['descriptions', 'bi-card-text', 'a.reports.tab_descriptions'],
                   'shout' => ['shouts', 'bi-chat-left-dots', 'a.reports.tab_shouts']];
        ?>
        <!-- Source Tabs -->
        <div class="source-tabs">
            <?php if ($torrentTabs): ?>
            <button class="source-tab active" data-source="reports"><i class="bi bi-inbox"></i> <?= _h('a.reports.tab_active') ?> <span id="reports-badge" class="appeals-count-badge d-hidden"></span></button>
            <button class="source-tab" data-source="archives"><i class="bi bi-archive"></i> <?= _h('a.reports.tab_archives') ?> <span id="archives-badge" class="appeals-count-badge d-hidden"></span></button>
            <button class="source-tab" data-source="appeals"><i class="bi bi-megaphone"></i> <?= _h('a.reports.tab_appeals') ?> <span id="appeals-badge" class="appeals-count-badge d-hidden"></span></button>
            <button class="source-tab" data-source="appeal_archives"><i class="bi bi-archive"></i> <?= _h('a.reports.tab_appeal_archives') ?></button>
            <?php endif; ?>
            <?php /* Reported private messages. Behind its own permission, because reading one is a
                     different kind of access from working the torrent queue — and the tab is not
                     drawn at all for somebody who does not hold it, rather than drawn and refused. */ ?>
            <?php if ($msgTab): ?>
            <button class="source-tab<?= $firstTab === 'messages' ? ' active' : '' ?>" data-source="messages"><i class="bi bi-chat-left-text"></i> <?= _h('a.reports.tab_messages') ?> <span id="msgrep-badge" class="appeals-count-badge d-hidden"></span></button>
            <?php endif; ?>
            <?php /* Reported comments, descriptions and shouts (1.71.0, includes/reports.php): a tab each, each with
                     its own count of the things waiting — drawn while the feature is on and the session holds that
                     kind's view. One view below serves all three (assets/js/admin-contentreports.js). */ ?>
            <?php foreach ($crKinds as $k): [$src, $icon, $label] = $crTabs[$k]; ?>
            <button class="source-tab<?= $firstTab === $src ? ' active' : '' ?>" data-source="<?= $src ?>" data-crep-kind="<?= $k ?>"><i class="bi <?= $icon ?>"></i> <?= _h($label) ?> <span id="crep-badge-<?= $k ?>" class="appeals-count-badge d-hidden"></span></button>
            <?php endforeach; ?>
            <!-- The page links that used to sit here are gone. They predate the shared header bar, which
                 now lists every page from the same adminNavItems() list; keeping both meant Reports was
                 the only page showing its navigation twice, once in each row. Every other page's tab bar
                 switches VIEWS and nothing else, and this one now matches them. -->
        </div>

        <?php /* The reported-messages view. A list of cards rather than a row in the reports table:
                 what a moderator reads here is two lines of somebody's conversation, and that does
                 not fit a table of names and hashes. Drawn by assets/js/admin-messages.js. */ ?>
        <div id="msgrep-view" class="d-hidden">
            <div class="admin-toolbar-card">
                <div class="toolbar-row">
                    <div class="toolbar-search">
                        <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                        <div class="search-input-wrap">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="msgrep-search" placeholder="<?= _h('a.reports.search_ph') ?>">
                        </div>
                    </div>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="msgrep-status">
                        <option value="open"><?= _h('status.b_pending') ?></option>
                        <option value="closed"><?= _h('status.b_checked') ?></option>
                        <option value="all"><?= _h('a.reports.f_all') ?></option>
                    </select>
                </div>
            </div>
            <div class="msgrep-note settings-hint"><?= __('js.msgrep.only_two') ?></div>
            <div id="msgrep-list"></div>
            <div class="trans-pagination" id="msgrep-pagination"></div>
        </div>

        <?php /* Reported comments, descriptions and shouts (1.71.0): one view for the three tabs, drawn by
                 assets/js/admin-contentreports.js — a card per reported THING (its words, all its reports, its
                 author), found by status, the reported member, the reporter, a date range and a text. */ ?>
        <?php if ($crKinds): ?>
        <div id="crep-view" class="d-hidden">
            <div class="admin-toolbar-card">
                <div class="toolbar-row crep-filters">
                    <div class="toolbar-search">
                        <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                        <div class="search-input-wrap">
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="crep-search" maxlength="100" placeholder="<?= _h('a.creport.search_ph') ?>" aria-label="<?= _h('a.creport.search_ph') ?>">
                        </div>
                    </div>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="crep-status" aria-label="<?= _h('js.reports.col_status') ?>">
                        <option value="open"><?= _h('status.b_pending') ?></option>
                        <option value="closed"><?= _h('status.b_checked') ?></option>
                        <option value="all"><?= _h('a.reports.f_all') ?></option>
                    </select>
                    <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary crep-name-in" id="crep-author" maxlength="64" placeholder="<?= _h('a.creport.author_ph') ?>" aria-label="<?= _h('a.creport.author_ph') ?>">
                    <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary crep-name-in" id="crep-reporter" maxlength="64" placeholder="<?= _h('a.creport.reporter_ph') ?>" aria-label="<?= _h('a.creport.reporter_ph') ?>">
                    <label class="crep-date"><span><?= _h('a.creport.from') ?></span> <input type="date" class="form-control form-control-sm bg-dark text-light border-secondary" id="crep-from"></label>
                    <label class="crep-date"><span><?= _h('a.creport.to') ?></span> <input type="date" class="form-control form-control-sm bg-dark text-light border-secondary" id="crep-to"></label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="crep-clear"><i class="bi bi-x-lg"></i> <?= _h('a.creport.clear') ?></button>
                </div>
            </div>
            <div class="msgrep-note settings-hint crep-note"><?= _h('a.creport.note') ?></div>
            <div id="crep-said" class="crep-said" aria-live="polite"></div>
            <div id="crep-list"></div>
            <div class="trans-pagination" id="crep-pagination"></div>
        </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <?php /* The torrent reports' own toolbar, table and pages (`data-torrent-part`): shown on their four tabs
                 only, and never drawn visible for a session that may not read them (1.71.0). */ ?>
        <div class="admin-toolbar-card<?= $torrentTabs ? '' : ' d-hidden' ?>" data-torrent-part>
            <div class="toolbar-row">
                <div class="toolbar-search">
                    <span class="toolbar-search-icon"><i class="bi bi-search"></i></span>
                    <div class="search-input-wrap">
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="search-input" placeholder="<?= _h('a.reports.search_ph') ?>">
                        <button type="button" class="search-clear-btn" id="search-clear" title="<?= _h('search.clear') ?>"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary toolbar-status-filter" id="filter-status">
                        <option value="all"><?= _h('a.reports.f_all') ?></option>
                        <option value="pending"><?= _h('status.b_pending') ?></option>
                        <option value="reviewed"><?= _h('status.b_checked') ?></option>
                        <option value="blocked"><?= _h('status.b_blocked') ?></option>
                    </select>
                </div>
                <div class="toolbar-right">
                    <span id="total-count" class="text-muted"></span>
                    <button class="btn btn-sm btn-outline-warning" id="btn-archive-all"><i class="bi bi-archive"></i> <?= _h('a.reports.archive_reviewed') ?></button>
                </div>
            </div>
        </div>

        <div class="table-responsive<?= $torrentTabs ? '' : ' d-hidden' ?>" id="reports-table-card" data-torrent-part>
            <table class="table table-dark table-hover dash-table" id="reports-table">
                <colgroup id="reports-colgroup">
                    <col class="dash-c-id"><col class="dash-c-name"><col class="dash-c-flex"><col class="dash-c-flex"><col class="dash-c-flex"><col class="dash-c-flex"><col class="dash-c-hash"><col class="dash-c-ip"><col class="dash-c-status"><col class="dash-c-date"><col class="dash-c-actions">
                </colgroup>
                <?php /* The same keys assets/js/admin.js redraws this row with when the tab changes
                         (1.69.0). Two dictionaries for one header had drifted apart in Polish — the first
                         column said "Zgłaszający" until a tab was clicked and "Imię i nazwisko" after, the
                         object column "Utwór" and then "Obiekt" — and one set cannot drift from itself. */ ?>
                <thead><tr>
                    <th class="sortable" data-sort="id"><?= _h('js.reports.col_id') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="name"><?= _h('js.reports.col_name') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="email"><?= _h('js.reports.col_email') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="company"><?= _h('js.reports.col_company') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="representative"><?= _h('js.reports.col_entity') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="object"><?= _h('js.reports.col_object') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="hash"><?= _h('js.reports.col_hash') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="ip"><?= _h('js.reports.col_ip') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable col-badge" data-sort="blocked"><?= _h('js.reports.col_status') ?> <i class="bi bi-arrow-down-up sort-icon"></i></th>
                    <th class="sortable" data-sort="date"><?= _h('js.reports.col_date') ?> <i class="bi bi-arrow-down sort-icon active"></i></th>
                    <th class="th-actions"><?= _h('js.reports.col_actions') ?></th>
                </tr></thead>
                <tbody id="reports-body"></tbody>
            </table>
        </div>
        <div class="admin-pagination<?= $torrentTabs ? '' : ' d-hidden' ?>" id="pagination" data-torrent-part></div>
    </div>
    <?php $footerInPanel = true; include __DIR__ . '/../footer.php'; ?>

    <!-- Action Modal -->
    <div class="modal fade" id="actionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-text"></i> <?= _h('a.reports.m_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="modal-report-info" class="mb-3"></div>
                    <div class="d-flex flex-wrap gap-2 mb-3 justify-content-center" id="modal-actions">
                        <button class="btn btn-outline-danger btn-sm" id="modal-block"><i class="bi bi-slash-circle"></i> <?= _h('a.reports.m_block') ?></button>
                        <button class="btn btn-outline-info btn-sm" id="modal-unblock" style="display:none"><i class="bi bi-unlock"></i> <?= _h('a.reports.m_unblock') ?></button>
                        <button class="btn btn-outline-warning btn-sm" id="modal-archive"><i class="bi bi-archive"></i> <?= _h('a.reports.m_archive') ?></button>
                        <button class="btn btn-outline-success btn-sm" id="modal-restore" style="display:none"><i class="bi bi-arrow-counterclockwise"></i> <?= _h('a.reports.m_restore') ?></button>
                        <button class="btn btn-outline-danger btn-sm" id="modal-delete-perm"><i class="bi bi-trash"></i> <?= _h('a.reports.m_delete_perm') ?></button>
                    </div>
                    <div id="modal-blacklist-warning" class="mb-2" style="display:none"></div>
                    <hr class="border-secondary">
                    <div class="text-center" id="modal-email-section">
                        <h6><i class="bi bi-envelope"></i> <?= _h('a.reports.m_email_head') ?></h6>
                        <p class="email-hint"><?= _h('a.reports.m_email_hint') ?></p>
                    </div>
                    <textarea class="form-control bg-dark text-light border-secondary" id="modal-email-msg" rows="3" placeholder="<?= _h('a.reports.m_email_ph') ?>"></textarea>
                    <div class="text-center mt-2">
                        <button class="btn btn-primary btn-sm" id="modal-send-email"><i class="bi bi-send"></i> <?= _h('a.reports.m_send') ?></button>
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
                    <h5 class="modal-title"><i class="bi bi-megaphone"></i> <?= _h('a.reports.ap_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="appeal-modal-info" class="mb-3"></div>
                    <div id="appeal-modal-actions" class="d-flex flex-wrap gap-2 mb-3 justify-content-center">
                        <button class="btn btn-outline-success btn-sm" id="appeal-accept"><i class="bi bi-check-circle"></i> <?= _h('a.reports.ap_accept') ?></button>
                        <button class="btn btn-outline-danger btn-sm" id="appeal-reject"><i class="bi bi-x-circle"></i> <?= _h('a.reports.ap_reject') ?></button>
                        <button class="btn btn-outline-warning btn-sm" id="appeal-restore" style="display:none"><i class="bi bi-arrow-counterclockwise"></i> <?= _h('a.reports.ap_restore') ?></button>
                    </div>
                    <hr class="border-secondary" id="appeal-response-hr">
                    <div class="text-center" id="appeal-response-header">
                        <h6><i class="bi bi-reply"></i> <?= _h('a.reports.ap_resp_head') ?></h6>
                    </div>
                    <textarea class="form-control bg-dark text-light border-secondary" id="appeal-response-msg" rows="3" placeholder="<?= _h('a.reports.ap_resp_ph') ?>"></textarea>
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
                        <button class="btn btn-sm btn-outline-danger" id="confirmModal-cancel"><i class="bi bi-x-lg"></i> <?= _h('common.cancel') ?></button>
                        <button class="btn btn-sm btn-success" id="confirmModal-ok"><i class="bi bi-check-lg"></i> <?= _h('a.reports.confirm') ?></button>
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
                    <h5 class="modal-title"><i class="bi bi-trash text-danger"></i> <?= _h('a.reports.del_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-warning mb-3" style="font-size:0.9rem;"><?= __('a.reports.del_warn') ?></p>
                    <form id="delete-perm-form">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.reports.admin_pass') ?></label>
                            <?php // For the browser's password manager: this form has a password field, so without a named
                                  // username it pairs the page's search box with it. Visually hidden, never submitted. ?>
                            <input type="text" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
                            <input type="password" autocomplete="current-password" class="form-control bg-dark text-light border-secondary" id="del-password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= __('a.reports.del_reason') ?></label>
                            <textarea class="form-control bg-dark text-light border-secondary" id="del-reason" rows="3" placeholder="<?= _h('a.reports.del_reason_ph') ?>"></textarea>
                        </div>
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                            <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> <?= _h('a.reports.del_confirm') ?></button>
                        </div>
                    </form>
                    <div id="del-modal-alert" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <?php if (isCaptchaEnabled($cfg, 'login')): ?>
    <div class="captcha-overlay" id="captcha-overlay">
        <div class="captcha-box">
            <p><?= _h('captcha.verify_human') ?></p>
            <div id="captcha-widget" class="captcha-widget"></div>
            <div class="captcha-actions"><button type="button" class="btn btn-secondary captcha-cancel" id="captcha-cancel"><?= _h('common.cancel') ?></button></div>
        </div>
    </div>
    <?php endif; ?>
    <!-- reCAPTCHA v3 has no widget and admin.css hides the floating badge, so Google's terms require
         this notice wherever a token is minted (the report-deletion dialog does). Empty for the
         other providers. -->
    <?= captchaNoticeHtml($cfg, 'captcha-notice text-secondary text-center') ?>

    <?php if ($svcName !== ''): ?>
    <!-- Restart Tracker Modal -->
    <div class="modal fade" id="restartTrackerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-arrow-clockwise text-warning"></i> <?= _h('a.reports.restart_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-2" style="font-size:0.9rem;"><?= __('a.reports.restart_body', ['svc' => sanitize($svcName)]) ?></p>
                    <div id="restart-warn-list" class="mb-2"></div>
                    <form id="restart-tracker-form">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.reports.admin_pass') ?></label>
                            <?php // For the browser's password manager: this form has a password field, so without a named
                                  // username it pairs the page's search box with it. Visually hidden, never submitted. ?>
                            <input type="text" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
                            <input type="password" autocomplete="current-password" class="form-control bg-dark text-light border-secondary" id="restart-password" required>
                        </div>
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                            <button type="submit" class="btn btn-warning btn-sm text-dark"><i class="bi bi-arrow-clockwise"></i> <?= _h('a.reports.restart_now') ?></button>
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
                    <h5 class="modal-title"><i class="bi bi-arrow-clockwise text-info"></i> <?= _h('a.reports.reload_title') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light mb-2" style="font-size:0.9rem;"><?= __('a.reports.reload_body', ['svc' => sanitize($svcName)]) ?></p>
                    <div id="reload-warn-list" class="mb-2"></div>
                    <form id="reload-tracker-form">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.reports.admin_pass') ?></label>
                            <?php // For the browser's password manager: this form has a password field, so without a named
                                  // username it pairs the page's search box with it. Visually hidden, never submitted. ?>
                            <input type="text" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
                            <input type="password" autocomplete="current-password" class="form-control bg-dark text-light border-secondary" id="reload-password" required>
                        </div>
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                            <button type="submit" class="btn btn-info btn-sm text-dark"><i class="bi bi-arrow-clockwise"></i> <?= _h('a.reports.reload_now') ?></button>
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

    <?php /* What the server wrote, marked before any script of the page runs (1.73.0, assets/js/lang-swap.js). */ ?>
    <script<?= nonceAttr() ?>>if (window.LangSwap) window.LangSwap.mark();</script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/captcha.js<?= assetVer('assets/js/captcha.js') ?>"></script>
    <!-- admin-common.js only defines window.AdminCommon (shared pagination renderer); admin.js keeps its own globals -->
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin.js<?= assetVer('assets/js/admin.js') ?>"></script>
    <?php /* The reported-messages view. Loaded only where the permission is held, because the tab
             that opens it is not drawn otherwise and the endpoint behind it answers 403. */ ?>
    <?php if ($msgTab || $crKinds): ?>
    <?php /* …and the picture beside the names on each card (1.63.0) — the message cards' and (1.71.0) the
             reported comments', descriptions' and shouts'. */ ?>
    <?= function_exists('userAvatarScriptTag') ? userAvatarScriptTag($baseUrl, $cfg) : '' ?>
    <?php endif; ?>
    <?php if ($msgTab): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-messages.js<?= assetVer('assets/js/admin-messages.js') ?>"></script>
    <?php endif; ?>
    <?php /* Reported comments, descriptions and shouts (1.71.0): only where a tab for one is drawn. */ ?>
    <?php if ($crKinds): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-contentreports.js<?= assetVer('assets/js/admin-contentreports.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
