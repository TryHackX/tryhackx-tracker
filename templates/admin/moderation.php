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
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>"
      data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>" data-users-url="<?= sanitize($baseUrl . '?action=admin-users') ?>">
    <div class="admin-container admin-wide wl-page" role="main">
        <div class="admin-header">
            <h1 class="admin-title"><i class="bi bi-shield-exclamation"></i> <?= _h('a.moderation.title') ?> <span class="idx-subtitle"><?= _h('a.moderation.subtitle') ?></span></h1>
            <?php $current = 'admin-moderation'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <?php /* Three views of one thing — what has been done to members and what can still be undone. The tab bar is this page's own
                 (assets/js/admin-moderation.js); the badge on the first carries the number of members restricted right now. */ ?>
        <div class="source-tabs" id="mod-tabs">
            <button type="button" class="source-tab active" data-view="restricted"><i class="bi bi-person-lock"></i> <?= _h('a.moderation.tab_restricted') ?> <span id="mod-restricted-badge" class="appeals-count-badge d-hidden"></span></button>
            <button type="button" class="source-tab" data-view="warnings"><i class="bi bi-exclamation-triangle"></i> <?= _h('a.moderation.tab_warnings') ?></button>
            <button type="button" class="source-tab" data-view="auto"><i class="bi bi-robot"></i> <?= _h('a.moderation.tab_auto') ?></button>
        </div>
        <div id="mod-said" class="crep-said" aria-live="polite"></div>

        <!-- Restricted now -->
        <div class="wl-view" id="mod-view-restricted">
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

    <?php $footerInPanel = true; include __DIR__ . '/../footer.php'; ?>

    <div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3"></div>
    <?php /* What the server wrote, marked before any script of the page runs (1.73.0, assets/js/lang-swap.js). */ ?>
    <script<?= nonceAttr() ?>>if (window.LangSwap) window.LangSwap.mark();</script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <?php /* The picture beside every member's name (1.63.0), the same file the public pages draw with. */ ?>
    <?= function_exists('userAvatarScriptTag') ? userAvatarScriptTag($baseUrl, $cfg) : '' ?>
    <script src="<?= $baseUrl ?>assets/js/admin-moderation.js<?= assetVer('assets/js/admin-moderation.js') ?>"></script>
</body>
</html>
