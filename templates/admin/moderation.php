<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('a.moderation.title') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <?= iconFontTag($cfg) ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl) ?>
</head>
<?php
require_once __DIR__ . '/../../includes/content.php';
$modView = panelCan($db, $cfg, 'panel.moderation.view');
$modReview = panelCan($db, $cfg, 'panel.whitelist.content');
$modWaiting = $modReview ? contentPendingCount($db) + (int)$db->query("SELECT COUNT(*) FROM wl_content_edits WHERE status = 'pending'")->fetchColumn() : 0;
?>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>"
      data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>" data-users-url="<?= sanitize($baseUrl . '?action=admin-users') ?>"
      data-may-view="<?= $modView ? '1' : '0' ?>" data-may-review="<?= $modReview ? '1' : '0' ?>" data-search-url="<?= sanitize($baseUrl . '?action=search&hash=') ?>">
    <div class="admin-container admin-wide wl-page" role="main">
        <div class="admin-header">
            <h1 class="admin-title"><i class="bi bi-shield-exclamation"></i> <?= _h('a.moderation.title') ?> <span class="idx-subtitle"><?= _h('a.moderation.subtitle') ?></span></h1>
            <?php $current = 'admin-moderation'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <?php /* Three views of one thing — what has been done to members and what can still be undone. The tab bar is this page's own
                 (assets/js/admin-moderation.js); the badge on the first carries the number of members restricted right now. */ ?>
        <div class="source-tabs" id="mod-tabs">
<?php if ($modReview): ?>
            <button type="button" class="source-tab" data-view="review"><i class="bi bi-inbox"></i> <?= _h('a.moderation.tab_review') ?> <span id="mod-review-badge" class="wl-tab-count"><?= $modWaiting > 0 ? (int)$modWaiting : '' ?></span></button>
<?php endif; ?>
<?php if ($modView): ?>
            <button type="button" class="source-tab" data-view="restricted"><i class="bi bi-person-lock"></i> <?= _h('a.moderation.tab_restricted') ?> <span id="mod-restricted-badge" class="appeals-count-badge d-hidden"></span></button>
            <button type="button" class="source-tab" data-view="warnings"><i class="bi bi-exclamation-triangle"></i> <?= _h('a.moderation.tab_warnings') ?></button>
            <button type="button" class="source-tab" data-view="auto"><i class="bi bi-robot"></i> <?= _h('a.moderation.tab_auto') ?></button>
<?php endif; ?>
        </div>
        <div id="mod-said" class="crep-said" aria-live="polite"></div>

<?php if ($modReview): ?>
        <div class="wl-view d-hidden" id="mod-view-review">
            <div class="source-tabs rq-subtabs" id="rq-subtabs">
                <button type="button" class="source-tab active" data-sub="queue"><i class="bi bi-file-earmark-text"></i> <?= _h('a.moderation.rv_descs') ?> <span id="rq-q-count" class="wl-tab-count"></span></button>
                <button type="button" class="source-tab" data-sub="edits"><i class="bi bi-pencil-square"></i> <?= _h('a.moderation.rv_edits') ?> <span id="rq-e-count" class="wl-tab-count"></span></button>
            </div>
            <div id="rq-pane-queue">
                <div class="admin-toolbar-card rq-toolbar">
                    <div class="toolbar-row rq-filters">
                        <input type="search" class="form-control form-control-sm bg-dark text-light border-secondary rq-search" id="rq-search" maxlength="200" placeholder="<?= _h('a.moderation.rv_search_ph') ?>" aria-label="<?= _h('a.moderation.rv_search_ph') ?>">
                        <input type="search" class="form-control form-control-sm bg-dark text-light border-secondary rq-author" id="rq-author" maxlength="64" placeholder="<?= _h('a.moderation.rv_author_ph') ?>" aria-label="<?= _h('a.moderation.rv_author_ph') ?>">
                        <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="rq-status" aria-label="<?= _h('a.moderation.rv_status') ?>">
                            <option value="pending"><?= _h('a.moderation.rv_status_pending') ?></option>
                            <option value="approved"><?= _h('a.moderation.rv_status_approved') ?></option>
                            <option value="rejected"><?= _h('a.moderation.rv_status_rejected') ?></option>
                            <option value="all"><?= _h('a.moderation.rv_status_all') ?></option>
                            <option value="forum"><?= _h('a.moderation.rv_status_forum') ?></option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="rq-source" aria-label="<?= _h('a.moderation.rv_source') ?>">
                            <option value=""><?= _h('a.moderation.rv_source_all') ?></option>
                            <option value="forum"><?= _h('a.moderation.rv_source_forum') ?></option>
                            <option value="site"><?= _h('a.moderation.rv_source_site') ?></option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="rq-format" aria-label="<?= _h('a.moderation.rv_format') ?>">
                            <option value=""><?= _h('a.moderation.rv_format_all') ?></option>
                            <option value="bbcode">BBCode</option>
                            <option value="markdown">Markdown</option>
                            <option value="flarum">Flarum</option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="rq-sort" aria-label="<?= _h('a.moderation.rv_sort') ?>">
                            <option value="oldest"><?= _h('a.moderation.rv_sort_oldest') ?></option>
                            <option value="newest"><?= _h('a.moderation.rv_sort_newest') ?></option>
                            <option value="long"><?= _h('a.moderation.rv_sort_long') ?></option>
                            <option value="short"><?= _h('a.moderation.rv_sort_short') ?></option>
                            <option value="score"><?= _h('a.moderation.rv_sort_score') ?></option>
                        </select>
                        <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="rq-per" aria-label="<?= _h('a.moderation.rv_per', ['n' => 25]) ?>">
                            <option value="25"><?= _h('a.moderation.rv_per', ['n' => 25]) ?></option>
                            <option value="50"><?= _h('a.moderation.rv_per', ['n' => 50]) ?></option>
                            <option value="100"><?= _h('a.moderation.rv_per', ['n' => 100]) ?></option>
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="rq-clear"><i class="bi bi-x-lg"></i> <?= _h('a.creport.clear') ?></button>
                        <span id="rq-total" class="text-muted wl-total"></span>
                    </div>
                    <div class="toolbar-row rq-bulk" id="rq-bulk">
                        <label class="search-check rq-page-all"><input type="checkbox" id="rq-page-all"><span class="search-check-box" aria-hidden="true"></span> <?= _h('a.moderation.rv_select_page') ?></label>
                        <span id="rq-selected" class="rq-selected"></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="rq-select-matching" hidden></button>
                        <button type="button" class="btn btn-sm btn-outline-success" id="rq-approve-sel" disabled><i class="bi bi-check-lg"></i> <?= _h('js.review.approve_selected') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="rq-reject-sel" disabled><i class="bi bi-x-lg"></i> <?= _h('js.review.reject_selected') ?></button>
                        <button type="button" class="btn btn-sm btn-link rq-clear-sel" id="rq-clear-sel" hidden><?= _h('js.review.clear_selection') ?></button>
                        <span id="rq-progress" class="text-muted wl-small" aria-live="polite"></span>
                    </div>
                </div>
                <p class="settings-hint rq-explain"><?= _h('a.moderation.rv_explain') ?></p>
                <div id="rq-list" class="rq-list"></div>
                <div class="admin-pagination" id="rq-pagination"></div>
            </div>
            <div id="rq-pane-edits" class="d-hidden">
                <p class="text-muted wl-small" id="rq-edits-total"></p>
                <div id="rq-edits-list"></div>
            </div>
        </div>
<?php endif; ?>

        <!-- Restricted now -->
        <div class="wl-view d-hidden" id="mod-view-restricted">
            <p class="idx-note"><?= _h('a.moderation.restricted_note') ?></p>
            <div id="mod-restricted-list"></div>
        </div>

        <!-- Warnings -->
        <div class="wl-view d-hidden" id="mod-view-warnings">
            <div class="admin-toolbar-card">
                <div class="toolbar-row crep-filters">
                    <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary crep-name-in" id="mod-f-member" maxlength="64" placeholder="<?= _h('a.moderation.f_member') ?>" aria-label="<?= _h('a.moderation.f_member') ?>">
                    <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary crep-name-in" id="mod-f-moderator" maxlength="64" placeholder="<?= _h('a.moderation.f_moderator') ?>" aria-label="<?= _h('a.moderation.f_moderator') ?>">
                    <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="mod-f-action" aria-label="<?= _h('a.moderation.f_action') ?>">
                        <option value=""><?= _h('a.moderation.f_action_all') ?></option>
                        <option value="warn"><?= _h('js.moderation.act_warn') ?></option>
                        <option value="remove"><?= _h('js.moderation.act_remove') ?></option>
                        <option value="mute"><?= _h('js.moderation.act_mute') ?></option>
                        <option value="ban"><?= _h('js.moderation.act_ban') ?></option>
                        <option value="auto"><?= _h('a.moderation.f_action_auto') ?></option>
                    </select>
                    <select class="form-select form-select-sm bg-dark text-light border-secondary w-auto" id="mod-f-state" aria-label="<?= _h('a.moderation.f_state') ?>">
                        <option value=""><?= _h('a.moderation.f_state_all') ?></option>
                        <option value="active"><?= _h('a.moderation.f_state_active') ?></option>
                        <option value="revoked"><?= _h('a.moderation.f_state_revoked') ?></option>
                    </select>
                    <label class="crep-date"><span><?= _h('a.creport.from') ?></span> <input type="date" class="form-control form-control-sm bg-dark text-light border-secondary" id="mod-f-from"></label>
                    <label class="crep-date"><span><?= _h('a.creport.to') ?></span> <input type="date" class="form-control form-control-sm bg-dark text-light border-secondary" id="mod-f-to"></label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mod-f-clear"><i class="bi bi-x-lg"></i> <?= _h('a.creport.clear') ?></button>
                    <span id="mod-warnings-total" class="text-muted wl-total"></span>
                </div>
            </div>
            <div class="msgrep-note settings-hint crep-note"><?= __('a.moderation.warnings_note') ?></div>
            <div id="mod-warnings-list"></div>
            <div class="trans-pagination"><button type="button" class="btn btn-sm btn-outline-secondary d-hidden" id="mod-warnings-more"><i class="bi bi-chevron-down"></i> <?= _h('a.moderation.more') ?></button></div>
        </div>

        <!-- Automatic penalties -->
        <div class="wl-view d-hidden" id="mod-view-auto">
            <div class="wl-status-card">
                <div class="wl-status-head"><h6><i class="bi bi-robot"></i> <?= _h('a.moderation.auto_head') ?></h6></div>
                <p class="idx-note"><?= __('a.moderation.auto_intro') ?></p>
                <ul class="mod-auto-rules-list wl-small text-muted">
                    <li><?= _h('a.moderation.auto_counts') ?></li>
                    <li><?= _h('a.moderation.auto_never') ?></li>
                    <li><?= _h('a.moderation.auto_strongest') ?></li>
                    <li><?= _h('a.moderation.auto_staff') ?></li>
                </ul>
                <div class="mod-auto-switch">
                    <label class="search-check"><input type="checkbox" id="mod-auto-on"><span class="search-check-box" aria-hidden="true"></span> <strong><?= _h('a.moderation.auto_on') ?></strong></label>
                    <span class="text-muted wl-small" id="mod-auto-state"></span>
                </div>
                <div class="table-responsive mt-2">
                    <?php /* A plain table, not the panel's pinned-column one: its last cell is a delete button and nothing in it needs to stay on screen. */ ?>
                    <table class="table table-dark table-sm align-middle mod-rules" id="mod-rules">
                        <thead><tr>
                            <th class="mod-r-on"><?= _h('a.moderation.r_enabled') ?></th>
                            <th><?= _h('a.moderation.r_warnings') ?></th>
                            <th><?= _h('a.moderation.r_window') ?></th>
                            <th><?= _h('a.moderation.r_action') ?></th>
                            <th><?= _h('a.moderation.r_duration') ?></th>
                            <th></th>
                        </tr></thead>
                        <tbody id="mod-rules-body"></tbody>
                    </table>
                </div>
                <div class="wl-status-actions mt-2" id="mod-rules-acts">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="mod-rule-add"><i class="bi bi-plus-lg"></i> <?= _h('a.moderation.r_add') ?></button>
                    <button type="button" class="btn btn-sm btn-primary" id="mod-rules-save"><i class="bi bi-check-lg"></i> <?= _h('a.moderation.r_save') ?></button>
                    <span class="text-muted wl-small" id="mod-rules-said" aria-live="polite"></span>
                </div>
                <p class="settings-hint mt-2" id="mod-rules-readonly" hidden><?= _h('a.moderation.r_readonly') ?></p>
            </div>
            <h6 class="mt-3"><i class="bi bi-clock-history"></i> <?= _h('a.moderation.auto_log') ?> <span id="mod-auto-total" class="text-muted wl-total"></span></h6>
            <div id="mod-auto-list"></div>
            <div class="trans-pagination"><button type="button" class="btn btn-sm btn-outline-secondary d-hidden" id="mod-auto-more"><i class="bi bi-chevron-down"></i> <?= _h('a.moderation.more') ?></button></div>
        </div>
    </div>

    <!-- Lift a silence or a ban -->
    <div class="modal fade" id="modLiftModal" tabindex="-1" aria-labelledby="mod-lift-title">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-unlock"></i> <span id="mod-lift-title"></span> <span id="mod-lift-name" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= _h('common.close') ?>"></button>
                </div>
                <div class="modal-body">
                    <p class="wl-small text-muted" id="mod-lift-lead"></p>
                    <label class="search-check mb-2"><input type="checkbox" id="mod-lift-tell"><span class="search-check-box" aria-hidden="true"></span> <?= _h('a.moderation.lift_tell') ?></label>
                    <label class="form-label wl-label"><?= _h('a.moderation.lift_note') ?> <small class="text-muted"><?= _h('a.users.optional') ?></small></label>
                    <div id="mod-lift-rich"></div>
                    <div id="mod-lift-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                        <button type="button" class="btn btn-sm btn-success" id="mod-lift-go"><i class="bi bi-unlock"></i> <?= _h('a.moderation.lift_go') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Revoke a warning -->
    <div class="modal fade" id="modRevokeModal" tabindex="-1" aria-labelledby="mod-revoke-title">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise"></i> <span id="mod-revoke-title"><?= _h('a.moderation.revoke_title') ?></span> <span id="mod-revoke-name" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= _h('common.close') ?>"></button>
                </div>
                <div class="modal-body">
                    <p class="wl-small text-muted"><?= _h('a.moderation.revoke_lead') ?></p>
                    <div class="mod-revoke-what wl-small" id="mod-revoke-what"></div>
                    <label class="form-label wl-label mt-2"><?= _h('a.moderation.revoke_note') ?> <small class="text-muted"><?= _h('a.users.optional') ?></small></label>
                    <div id="mod-revoke-rich"></div>
                    <label class="search-check mt-2" id="mod-revoke-tell-wrap"><input type="checkbox" id="mod-revoke-tell"><span class="search-check-box" aria-hidden="true"></span> <?= _h('a.moderation.revoke_tell') ?></label>
                    <label class="search-check mt-1" id="mod-revoke-lift-wrap"><input type="checkbox" id="mod-revoke-lift"><span class="search-check-box" aria-hidden="true"></span> <span id="mod-revoke-lift-label"><?= _h('a.moderation.revoke_lift') ?></span></label>
                    <div id="mod-revoke-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="mod-revoke-go"><i class="bi bi-arrow-counterclockwise"></i> <?= _h('a.moderation.revoke_go') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php if ($modReview): ?>
    <div class="modal fade" id="rqPunishModal" tabindex="-1" aria-labelledby="rq-pn-title">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-person-exclamation"></i> <span id="rq-pn-title"><?= _h('a.moderation.pn_title') ?></span> <span id="rq-pn-name" class="text-info"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= _h('common.close') ?>"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label wl-label" for="rq-pn-action"><?= _h('a.moderation.pn_action') ?></label>
                    <select id="rq-pn-action" class="form-select form-select-sm bg-dark text-light border-secondary w-auto">
                        <option value="warn"><?= _h('a.moderation.pn_warn') ?></option>
                        <option value="mute"><?= _h('a.moderation.pn_mute') ?></option>
                        <option value="ban"><?= _h('a.moderation.pn_ban') ?></option>
                    </select>
                    <div id="rq-pn-days-wrap" class="mt-2">
                        <label class="form-label wl-label" for="rq-pn-days"><?= _h('a.moderation.pn_days') ?></label>
                        <input id="rq-pn-days" type="number" min="0" max="3650" value="7" class="form-control form-control-sm bg-dark text-light border-secondary w-auto">
                        <small class="settings-hint"><?= _h('a.moderation.pn_days_hint') ?></small>
                    </div>
                    <label class="search-check mt-2" id="rq-pn-tell-wrap"><input type="checkbox" id="rq-pn-tell" checked><span class="search-check-box" aria-hidden="true"></span> <?= _h('a.moderation.pn_tell') ?></label>
                    <label class="form-label wl-label mt-2"><?= _h('a.moderation.pn_reason') ?></label>
                    <div id="rq-pn-rich"></div>
                    <label class="search-check mt-2" id="rq-pn-reject-wrap"><input type="checkbox" id="rq-pn-reject" checked><span class="search-check-box" aria-hidden="true"></span> <?= _h('a.moderation.pn_reject') ?></label>
                    <div id="rq-pn-alert"></div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                        <button type="button" class="btn btn-sm btn-danger" id="rq-pn-go"><i class="bi bi-check-lg"></i> <?= _h('a.moderation.pn_go') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

    <?php $footerInPanel = true; include __DIR__ . '/../footer.php'; ?>

    <div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3"></div>
    <?php /* What the server wrote, marked before any script of the page runs (1.73.0, assets/js/lang-swap.js). */ ?>
    <script<?= nonceAttr() ?>>if (window.LangSwap) window.LangSwap.mark();</script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <?php /* The picture beside every member's name (1.63.0), the same file the public pages draw with. */ ?>
    <?= function_exists('userAvatarScriptTag') ? userAvatarScriptTag($baseUrl, $cfg) : '' ?>
<?php if ($modReview): ?>
    <script src="<?= $baseUrl ?>assets/js/lightbox.js<?= assetVer('assets/js/lightbox.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-review.js<?= assetVer('assets/js/admin-review.js') ?>"></script>
<?php endif; ?>
    <script src="<?= $baseUrl ?>assets/js/admin-moderation.js<?= assetVer('assets/js/admin-moderation.js') ?>"></script>
</body>
</html>
