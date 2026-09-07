<!DOCTYPE html>
<html lang="<?= sanitize(langCurrent()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _h('a.traffic.title') ?> &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= $baseUrl ?>assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?= $baseUrl ?>assets/img/favicon.ico">
    <?= langJsBridge($baseUrl) ?>
    <?php
    // Both charts are drawn by the same vendored uPlot; either one on is enough to need it.
    $tlOn  = statsTimelineEnabled($cfg);
    $netOn = netlimitMonitorEnabled($cfg) || netlimitEnabled($cfg);
    ?>
    <?php if ($tlOn || $netOn): ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/vendor/uplot/uPlot.min.css<?= assetVer('assets/vendor/uplot/uPlot.min.css') ?>">
    <?php endif; ?>
</head>
<body class="admin-body admin-hc wl-body" data-api-base="<?= $baseUrl ?>api.php?endpoint=" data-csrf="<?= $csrfToken ?>" data-login-path="<?= sanitize(adminLoginPath($cfg)) ?>">
    <div class="admin-container admin-wide wl-page">
        <div class="admin-header">
            <h2><i class="bi bi-speedometer2"></i> <?= _h('a.traffic.title') ?> <span class="idx-subtitle"><?= _h('a.traffic.subtitle') ?></span></h2>
            <?php $current = 'admin-traffic'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <?php if ($tlOn): ?>
        <!-- Swarm timeline (assets/js/stats-timeline.js + vendored uPlot; samples by tools/janitor.php) -->
        <div class="wl-status-card tl-card" id="wl-timeline-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-graph-up"></i> <?= _h('a.traffic.tl_head') ?> <span class="wl-status-updated"><?= _h('a.traffic.tl_meta', [
                    'sec'  => (int)statsTimelineInterval($cfg),
                    'raw'  => (int)statsTimelineRawDays($cfg),
                    'keep' => (int)statsTimelineKeepDays($cfg),
                    'vis'  => statsTimelinePublic($cfg) ? __('a.traffic.tl_public') : __('a.traffic.tl_admins'),
                ]) ?></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-timeline" class="btn btn-sm btn-outline-secondary" title="<?= _h('a.traffic.tl_settings_title') ?>"><i class="bi bi-gear"></i> <?= _h('a.head.settings') ?></a>
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-tl-toggle" data-tl-collapse="wl-timeline" aria-expanded="true" aria-controls="wl-timeline"><i class="bi bi-chevron-up"></i> <span><?= _h('a.traffic.collapse') ?></span></button>
                </div>
            </div>
            <div id="wl-timeline"<?= statsTimelineMountAttrs($cfg, true) ?>></div>
        </div>
        <?php endif; ?>

        <?php if ($netOn): ?>
        <!-- Inbound UDP traffic + rate limit (assets/js/admin-netlimit.js; samples by tools/janitor.php) -->
        <div class="wl-status-card nl-card" id="net-card"
             data-net
             data-monitor="<?= netlimitMonitorEnabled($cfg) ? '1' : '0' ?>"
             data-limit="<?= netlimitEnabled($cfg) ? '1' : '0' ?>"
             data-auto="<?= netlimitAutoEnabled($cfg) ? '1' : '0' ?>"
             data-pps="<?= (int)netlimitPps($cfg) ?>"
             data-burst="<?= (int)netlimitBurst($cfg) ?>"
             data-port="<?= (int)netlimitPort($cfg) ?>"
             data-min="<?= NET_PPS_MIN ?>" data-max="<?= NET_PPS_MAX ?>"
             data-sample="<?= (int)netlimitSampleSeconds($cfg) ?>">
            <div class="wl-status-head">
                <h6><i class="bi bi-speedometer2"></i> <?= _h('a.traffic.net_head') ?> <span class="wl-status-updated" id="net-updated"><?= _h('a.traffic.net_meta', [
                    'port' => (int)netlimitPort($cfg),
                    'sec'  => (int)netlimitSampleSeconds($cfg),
                    'keep' => (int)netlimitKeepDays($cfg),
                ]) ?></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-netlimit" class="btn btn-sm btn-outline-secondary" title="<?= _h('a.traffic.net_settings_title') ?>"><i class="bi bi-gear"></i> <?= _h('a.head.settings') ?></a>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-net-panic" title="<?= _h('a.traffic.panic_title') ?>"><i class="bi bi-exclamation-octagon"></i> <?= __('a.traffic.panic') ?></button>
                    <!-- bound by admin-netlimit.js, NOT by the timeline's [data-tl-collapse] handler:
                         that file is only loaded when the swarm timeline is on, and two handlers on
                         one button would cancel each other out when it is -->
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-net-toggle" aria-expanded="true" aria-controls="net-body"><i class="bi bi-chevron-up"></i> <span><?= _h('a.traffic.collapse') ?></span></button>
                </div>
            </div>
            <div id="net-body">
                <div class="wl-status-grid" id="net-grid">
                    <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= __('a.traffic.net_reading') ?></div>
                </div>
                <div id="net-notes"></div>

                <!-- The throttle itself. The slider is logarithmic (1 000 … 1 000 000 pps) and carries the
                     measured median / P95 / peak as reference marks, so the number is a decision, not a guess. -->
                <div class="nl-tune" id="net-tune">
                    <div class="nl-tune-head">
                        <span class="nl-tune-title"><?= _h('a.traffic.in_limit') ?></span>
                        <span class="nl-tune-value"><input type="number" id="net-pps-input" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= (int)netlimitPps($cfg) ?>" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000" aria-label="<?= _h('a.traffic.pps_aria') ?>"> <span class="nl-unit">pps</span></span>
                    </div>
                    <div class="nl-slider-wrap">
                        <input type="range" class="form-range nl-slider" id="net-pps-range" min="0" max="1000" value="0" aria-label="<?= _h('a.traffic.in_slider_aria') ?>">
                        <div class="nl-scale" id="net-scale" aria-hidden="true"></div>
                        <div class="nl-marks" id="net-marks" aria-hidden="true"></div>
                    </div>
                    <div class="nl-advice" id="net-advice"></div>
                    <div class="nl-tune-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-net-suggest" title="<?= _h('a.traffic.suggest_title') ?>"><i class="bi bi-magic"></i> <?= _h('a.traffic.suggest') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-net-preview" title="<?= _h('a.traffic.preview_rules_title') ?>"><i class="bi bi-eye"></i> <?= _h('a.traffic.preview_rules') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btn-net-apply"><i class="bi bi-check2-circle"></i> <?= __('a.traffic.apply_limit') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="btn-net-off"><i class="bi bi-x-circle"></i> <?= __('a.traffic.remove_limit') ?></button>
                    </div>
                </div>

                <!-- The other half of the same decision. A tracker answers what it accepts, so a
                     budget on the way out matters exactly as much as the one on the way in — and it
                     is the one that decides whether the rest of the machine stays reachable. It was
                     read-only here while the helper could already set it. -->
                <!-- Present from the first paint, unlike before. The inbound half above is rendered by
                     PHP from a saved setting, so it is complete the moment the page arrives; this one's
                     only source of truth is the live nft rule, which takes a helper call to read. Hiding
                     it until then made the page grow a whole section under the reader's cursor a second
                     after they got there, and made the outbound budget look like a feature that comes
                     and goes. It now occupies its space immediately, disabled and saying so.
                     The input starts EMPTY on purpose: it used to be hardcoded to 50000, which is a real
                     number that was simply not true, and it was read as the setting for as long as it
                     took the helper to answer. -->
                <div class="nl-tune nl-tune-pending" id="net-egress-tune">
                    <div class="nl-tune-head">
                        <span class="nl-tune-title"><?= __('a.traffic.eg_title') ?></span>
                        <span class="nl-tune-value"><input type="number" id="net-epps-input" class="form-control form-control-sm bg-dark text-light border-secondary" value="" placeholder="&mdash;" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000" aria-label="<?= _h('a.traffic.eg_pps_aria') ?>" disabled> <span class="nl-unit">pps</span></span>
                    </div>
                    <div class="nl-slider-wrap">
                        <input type="range" class="form-range nl-slider" id="net-epps-range" min="0" max="1000" value="0" aria-label="<?= _h('a.traffic.eg_slider_aria') ?>" disabled>
                        <div class="nl-scale" id="net-escale" aria-hidden="true"></div>
                        <div class="nl-marks" id="net-emarks" aria-hidden="true"></div>
                    </div>
                    <div class="nl-advice" id="net-eadvice"><span class="nl-tune-waiting"><?= __('a.traffic.eg_waiting') ?></span></div>
                    <div class="nl-tune-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-net-esuggest" title="<?= _h('a.traffic.eg_suggest_title') ?>" disabled><i class="bi bi-magic"></i> <?= _h('a.traffic.suggest') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btn-net-eapply" disabled><i class="bi bi-check2-circle"></i> <?= __('a.traffic.apply_budget') ?></button>
                    </div>
                </div>

                <div class="nl-chart-head">
                    <span class="nl-chart-title"><?= _h('a.traffic.chart_title') ?></span>
                    <div class="nl-ranges" id="net-ranges" role="group" aria-label="<?= _h('a.traffic.chart_range_aria') ?>"></div>
                </div>
                <div class="nl-chart" id="net-chart"></div>
                <div class="nl-egress" id="net-egress"></div>
            </div>
        </div>

        <!-- Apply / remove / throttle-hard confirmation (admin password, like the reload modal above) -->
        <div class="modal fade" id="netConfirmModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-shield-lock text-warning"></i> <span id="net-modal-title"><?= _h('a.traffic.net_modal_title') ?></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-light mb-2" style="font-size:0.9rem;" id="net-modal-text"></p>
                        <div class="nl-undo" id="net-modal-undo"></div>
                        <form id="net-confirm-form">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.traffic.admin_password') ?></label>
                                <?php // For the browser's password manager: this form has a password field, so without a named
                                      // username it pairs the page's search box with it. Visually hidden, never submitted. ?>
                                <input type="text" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
                                <input type="password" class="form-control bg-dark text-light border-secondary" id="net-confirm-password" autocomplete="current-password" required>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> <?= _h('common.cancel') ?></button>
                                <button type="submit" class="btn btn-sm btn-outline-success" id="net-confirm-ok"><i class="bi bi-check-lg"></i> <?= _h('a.traffic.confirm') ?></button>
                            </div>
                        </form>
                        <div id="net-confirm-alert" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ruleset preview (read-only; what the helper WOULD write) -->
        <div class="modal fade" id="netPreviewModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-eye text-info"></i> <?= _h('a.traffic.rules_preview') ?></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-light mb-2" style="font-size:0.85rem;"><?= __('a.traffic.rules_preview_note', ['file' => '<span id="net-preview-file" class="text-info"></span>']) ?></p>
                        <pre class="nl-preview" id="net-preview-body"></pre>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (netlimitCommand($cfg) !== ''): ?>
        <!-- Address lists. Directly under the limit card because they live INSIDE that table: the
             sets are part of the same ruleset, and with no limit (or counters) loaded there is
             nowhere for them to go. -->
        <div class="wl-status-card nl-card" id="iplists-card" data-iplists
             data-enabled="<?= ($cfg['net_lists_enabled'] ?? '0') === '1' ? '1' : '0' ?>"
             data-ttl="<?= (int)($cfg['net_lists_ttl_default'] ?? IPLIST_TTL_DEFAULT) ?>"
             data-max="<?= IPLIST_MAX_TOTAL ?>">
            <div class="wl-status-head">
                <h6><i class="bi bi-shield-slash"></i> <?= _h('a.traffic.ipl_head') ?> <span class="wl-status-updated" id="ipl-updated"></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-iplists" class="btn btn-sm btn-outline-secondary" title="<?= _h('a.traffic.ipl_settings_title') ?>"><i class="bi bi-gear"></i> <?= _h('a.head.settings') ?></a>
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-ipl-add"><i class="bi bi-plus-lg"></i> <?= _h('a.traffic.ipl_add') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btn-ipl-push" title="<?= _h('a.traffic.ipl_push_title') ?>"><i class="bi bi-upload"></i> <?= __('a.traffic.ipl_push') ?></button>
                </div>
            </div>
            <div id="iplists-body">
                <p class="wl-small text-muted mb-2"><?= __('a.traffic.ipl_intro', [
                    'url' => sanitize($baseUrl . '?action=settings#section-netlimit'),
                ]) ?></p>
                <div id="ipl-list"><div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= __('a.traffic.ipl_reading') ?></div></div>
                <div id="ipl-notes"></div>
            </div>
        </div>

        <!-- add / edit a list -->
        <div class="modal fade" id="iplAddModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content bg-dark text-light">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-shield-plus text-info"></i> <?= _h('a.traffic.ipl_modal_title') ?></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label wl-small"><?= _h('a.traffic.name') ?></label>
                            <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="ipl-name" maxlength="64" placeholder="<?= _h('a.traffic.ipl_name_ph') ?>">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label wl-small"><?= _h('a.traffic.ipl_kind') ?></label>
                                <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ipl-kind">
                                    <option value="block"><?= _h('a.traffic.ipl_block') ?></option>
                                    <option value="allow"><?= __('a.traffic.ipl_allow') ?></option>
                                </select>
                            </div>
                            <div class="col-6" id="ipl-mode-wrap">
                                <label class="form-label wl-small"><?= _h('a.traffic.ipl_when') ?></label>
                                <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ipl-mode">
                                    <option value="hard"><?= _h('a.traffic.ipl_always') ?></option>
                                    <option value="soft"><?= _h('a.traffic.ipl_soft') ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label wl-small"><?= _h('a.traffic.ipl_source') ?></label>
                            <select class="form-select form-select-sm bg-dark text-light border-secondary" id="ipl-source">
                                <option value="url"><?= _h('a.traffic.ipl_src_url') ?></option>
                                <option value="manual"><?= _h('a.traffic.ipl_src_manual') ?></option>
                            </select>
                        </div>
                        <div class="mb-2" id="ipl-url-wrap">
                            <label class="form-label wl-small"><?= _h('a.traffic.ipl_url') ?></label>
                            <input type="url" class="form-control form-control-sm bg-dark text-light border-secondary" id="ipl-url"
                                   maxlength="500" placeholder="https://www.ipdeny.com/ipblocks/data/countries/cn.zone">
                            <div class="mt-2">
                                <label class="form-label wl-small"><?= _h('a.traffic.ipl_ttl') ?></label>
                                <input type="number" class="form-control form-control-sm bg-dark text-light border-secondary" id="ipl-ttl"
                                       min="<?= IPLIST_TTL_MIN ?>" max="<?= IPLIST_TTL_MAX ?>" step="15" value="<?= (int)($cfg['net_lists_ttl_default'] ?? IPLIST_TTL_DEFAULT) ?>">
                            </div>
                        </div>
                        <div class="mb-2 d-none" id="ipl-text-wrap">
                            <!-- The browser's own file input is a grey box with a Polish system label
                                 that does not belong to this page. This is the same control, dressed
                                 to match, and it takes a dropped file as well as a chosen one. -->
                            <label class="form-label wl-small"><?= __('a.traffic.ipl_upload') ?></label>
                            <div class="ipl-drop" id="ipl-drop" tabindex="0" role="button"
                                 aria-label="<?= _h('a.traffic.ipl_drop_aria') ?>">
                                <i class="bi bi-file-earmark-arrow-up ipl-drop-icon"></i>
                                <span class="ipl-drop-main"><?= __('a.traffic.ipl_drop_main') ?></span>
                                <span class="ipl-drop-sub"><?= __('a.traffic.ipl_drop_sub') ?></span>
                                <input type="file" id="ipl-file" class="ipl-drop-input"
                                       accept=".txt,.zone,.list,.cidr,text/plain">
                            </div>
                            <label class="form-label wl-small mt-2"><?= __('a.traffic.ipl_paste') ?></label>
                            <textarea class="form-control form-control-sm bg-dark text-light border-secondary" id="ipl-text" rows="6"
                                      placeholder="1.2.3.0/24&#10;2001:db8::/32&#10;<?= _h('a.traffic.ipl_text_ph') ?>"></textarea>
                            <!-- What was actually understood, before anything is stored. The whole
                                 point of a file nobody wrote by hand is that you cannot see what is
                                 in it; this says what the panel read out of it. -->
                            <div class="ipl-preview d-none" id="ipl-preview"></div>
                        </div>
                        <div class="alert alert-danger py-2 wl-small d-none" id="ipl-error"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><?= _h('common.cancel') ?></button>
                        <button type="button" class="btn btn-sm btn-info" id="ipl-save"><?= _h('a.traffic.ipl_save') ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (otPerfCommand($cfg) !== ''): ?>
        <!-- How the tracker itself is tuned to handle what the two cards above measure. The knobs
             live in Settings; this card shows what is IN FORCE, which is not the same thing, and is
             where the difference between the two becomes visible. -->
        <div class="wl-status-card nl-card" id="ot-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-cpu"></i> <?= __('a.traffic.ot_head') ?> <span class="wl-status-updated" id="ot-updated"></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-ot-perf" class="btn btn-sm btn-outline-secondary" title="<?= _h('a.traffic.ot_settings_title') ?>"><i class="bi bi-gear"></i> <?= _h('a.head.settings') ?></a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-ot-preview" title="<?= _h('a.traffic.ot_preview_title') ?>"><i class="bi bi-eye"></i> <?= _h('a.traffic.ot_preview') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-ot-apply"><i class="bi bi-check2-circle"></i> <?= __('a.traffic.ot_apply') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btn-ot-workers"><i class="bi bi-diagram-3"></i> <?= __('a.traffic.ot_workers') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-ot-restart" title="<?= _h('a.traffic.ot_restart_title') ?>"><i class="bi bi-bootstrap-reboot"></i> <?= __('a.traffic.ot_restart') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-ot-reset" title="<?= _h('a.traffic.ot_reset_title') ?>"><i class="bi bi-arrow-counterclockwise"></i> <?= __('a.traffic.ot_reset') ?></button>
                </div>
            </div>
            <div class="wl-status-grid" id="ot-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= __('a.traffic.ot_reading') ?></div>
            </div>
            <div id="ot-notes"></div>
        </div>

        <!-- One password gate for every operation here, exactly like the firewall's -->
        <div class="modal fade" id="otConfirmModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-shield-lock text-warning"></i> <span id="ot-modal-title"><?= _h('a.traffic.ot_modal_title') ?></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-light mb-2" style="font-size:0.9rem;" id="ot-modal-text"></p>
                        <div class="nl-undo" id="ot-modal-undo"></div>
                        <form id="ot-confirm-form">
                            <div class="mb-2 d-hidden" id="ot-workers-row">
                                <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.traffic.ot_workers_label') ?></label>
                                <input type="number" class="form-control bg-dark text-light border-secondary" id="ot-workers-input" min="1" max="64" value="4">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.traffic.admin_password') ?></label>
                                <?php // For the browser's password manager: this form has a password field, so without a named
                                      // username it pairs the page's search box with it. Visually hidden, never submitted. ?>
                                <input type="text" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
                                <input type="password" class="form-control bg-dark text-light border-secondary" id="ot-confirm-password" autocomplete="current-password" required>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> <?= _h('common.cancel') ?></button>
                                <button type="submit" class="btn btn-sm btn-outline-success" id="ot-confirm-ok"><i class="bi bi-check-lg"></i> <?= _h('a.traffic.confirm') ?></button>
                            </div>
                        </form>
                        <div id="ot-confirm-alert" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- What would be written, before anyone types a password -->
        <div class="modal fade" id="otPreviewModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-file-earmark-code"></i> <span id="ot-preview-title"><?= _h('a.traffic.ot_dropin_preview') ?></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <pre class="nl-preview" id="ot-preview-body"></pre>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$tlOn && !$netOn && otPerfCommand($cfg) === ''): ?>
        <!-- Both halves are off. An empty page would just look broken, so say which switch turns
             each one on — same shape as the "index disabled" note on the Index page. -->
        <div class="wl-status-card">
            <div class="wl-status-head"><h6><i class="bi bi-speedometer2"></i> <?= _h('a.traffic.nothing_head') ?></h6></div>
            <div class="wl-status-grid">
                <div class="wl-kv-item">
                    <div class="wl-kv-k"><?= _h('a.traffic.tl_head') ?></div>
                    <div class="wl-kv-v"><span class="wl-small text-muted"><?= __('a.traffic.off_tl', [
                        'url' => sanitize($baseUrl . '?action=settings#section-timeline'),
                    ]) ?></span></div>
                </div>
                <div class="wl-kv-item">
                    <div class="wl-kv-k"><?= _h('a.traffic.net_head') ?></div>
                    <div class="wl-kv-v"><span class="wl-small text-muted"><?= __('a.traffic.off_net', [
                        'url' => sanitize($baseUrl . '?action=settings#section-netlimit'),
                    ]) ?></span></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (sysctlEnabled($cfg)): ?>
        <!-- Kernel network buffers. Deliberately the LAST card on this page: the two above measure
             the loss, this one is the only thing that can do something about it, and it is the only
             thing here that changes a setting belonging to the whole machine rather than to the
             tracker. Gated on the feature being both configured and enabled, so an install that
             never turned it on never renders it and never polls it. -->
        <div class="wl-status-card nl-card" id="sysctl-card"
             data-confirm-seconds="<?= (int)sysctlConfirmSeconds($cfg) ?>">
            <div class="wl-status-head">
                <h6><i class="bi bi-sliders2"></i> <?= _h('a.traffic.sy_head') ?> <span class="wl-status-updated" id="sy-updated"></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-sysctl" class="btn btn-sm btn-outline-secondary" title="<?= _h('a.traffic.sy_settings_title') ?>"><i class="bi bi-gear"></i> <?= _h('a.head.settings') ?></a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-sy-suggest" title="<?= _h('a.traffic.sy_suggest_title') ?>"><i class="bi bi-magic"></i> <?= _h('a.traffic.suggest') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-sy-preview" title="<?= _h('a.traffic.sy_preview_title') ?>"><i class="bi bi-eye"></i> <?= _h('a.traffic.sy_preview') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-sy-arm"><i class="bi bi-stopwatch"></i> <?= __('a.traffic.sy_arm') ?></button>
                    <!-- Always here, not only inside the armed banner. Once a change is confirmed the
                         banner is gone, and the way back went with it. -->
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btn-sy-restore" disabled><i class="bi bi-arrow-counterclockwise"></i><span class="sy-restore-label"> <?= _h('a.traffic.sy_restore') ?></span></button>
                </div>
            </div>

            <!-- Shown only while a change is in force and unconfirmed. Confirm and Revert are kept
                 apart on purpose: one of them makes the change survive a reboot, and it would be read
                 on a page that may be stuttering at the time. -->
            <div class="sy-armed d-hidden" id="sy-armed">
                <div class="sy-armed-head">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span id="sy-armed-text"><?= _h('a.traffic.sy_armed_text') ?></span>
                </div>
                <div class="sy-countdown" id="sy-countdown"></div>
                <div class="sy-armed-keys" id="sy-armed-keys"></div>
                <div class="sy-armed-actions">
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-sy-revert"><i class="bi bi-arrow-counterclockwise"></i> <?= _h('a.traffic.sy_revert') ?></button>
                    <span class="sy-armed-gap"></span>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-sy-confirm"><i class="bi bi-check2-circle"></i> <?= __('a.traffic.sy_keep') ?></button>
                </div>
            </div>

            <!-- NOT .wl-status-grid: that is repeat(auto-fill, minmax(250px, 1fr)), so every row here
                 landed inside one 250-pixel column and every sentence wrapped one word per line. These
                 rows want the full width of the card and lay themselves out. -->
            <div class="sy-body" id="sy-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= __('a.traffic.sy_reading') ?></div>
            </div>
            <div id="sy-notes"></div>
        </div>

        <!-- One password gate, one acknowledgement per dangerous key -->
        <div class="modal fade" id="syConfirmModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-shield-lock text-warning"></i> <span id="sy-modal-title"><?= _h('a.traffic.sy_modal_title') ?></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-light mb-2" style="font-size:0.9rem;" id="sy-modal-text"></p>
                        <div class="nl-undo" id="sy-modal-undo"></div>
                        <div id="sy-modal-warnings"></div>
                        <form id="sy-confirm-form">
                            <div id="sy-modal-acks"></div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.traffic.admin_password') ?></label>
                                <?php // For the browser's password manager: this form has a password field, so without a named
                                      // username it pairs the page's search box with it. Visually hidden, never submitted. ?>
                                <input type="text" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
                                <input type="password" class="form-control bg-dark text-light border-secondary" id="sy-confirm-password" autocomplete="current-password" required>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> <?= _h('common.cancel') ?></button>
                                <button type="submit" class="btn btn-sm btn-outline-success" id="sy-confirm-ok"><i class="bi bi-check-lg"></i> <?= _h('a.traffic.confirm') ?></button>
                            </div>
                        </form>
                        <div id="sy-confirm-alert" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="syPreviewModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-file-earmark-code"></i> <span id="sy-preview-title"><?= _h('a.traffic.sy_file_preview') ?></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body"><pre class="nl-preview" id="sy-preview-body"></pre></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (otClusterEnabled($cfg)): ?>
        <!-- Extra opentracker instances. Last card on the page on purpose: everything above measures
             whether this is needed, and on most machines the answer is no. The installer's own unit is
             shown here but is never managed from here -- it is listed so the roster is the whole truth
             rather than only the part the panel created. -->
        <div class="wl-status-card nl-card" id="cluster-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-diagram-3"></i> <?= _h('a.traffic.cl_head') ?> <span class="wl-status-updated" id="cl-updated"></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-cluster" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear"></i> <?= _h('a.head.settings') ?></a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-cl-reload" title="<?= _h('a.traffic.cl_reload_title') ?>"><i class="bi bi-arrow-repeat"></i> <?= _h('a.traffic.cl_reload') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-cl-add"><i class="bi bi-plus-lg"></i> <?= __('a.traffic.cl_add') ?></button>
                </div>
            </div>
            <div class="sy-body" id="cl-body">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= __('a.traffic.cl_reading') ?></div>
            </div>
            <div id="cl-notes"></div>
        </div>

        <div class="modal fade" id="clAddModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-plus-circle text-success"></i> <?= _h('a.traffic.cl_modal_title') ?></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-light mb-2" style="font-size:0.9rem;"><?= __('a.traffic.cl_note') ?></p>
                        <form id="cl-add-form">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.traffic.name') ?></label>
                                    <input type="text" class="form-control bg-dark text-light border-secondary" id="cl-name" maxlength="16" placeholder="edge-a" required>
                                    <small class="settings-hint"><?= __('a.traffic.cl_name_hint') ?></small>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.traffic.cl_udp') ?></label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary" id="cl-udp" min="1024" max="65535" required>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.traffic.cl_tcp') ?></label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary" id="cl-tcp" min="1024" max="65535" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= __('a.traffic.cl_affinity') ?></label>
                                    <input type="text" class="form-control bg-dark text-light border-secondary" id="cl-affinity" placeholder="<?= _h('a.traffic.cl_affinity_ph') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= __('a.traffic.cl_workers') ?></label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary" id="cl-workers" min="0" max="64" value="0">
                                </div>
                            </div>
                            <div id="cl-plan" class="mt-2"></div>
                            <div class="mb-3 mt-2">
                                <label class="form-label" style="font-size:0.85rem;color:#bbb;"><?= _h('a.traffic.admin_password') ?></label>
                                <?php // For the browser's password manager: this form has a password field, so without a named
                                      // username it pairs the page's search box with it. Visually hidden, never submitted. ?>
                                <input type="text" value="<?= sanitize($cfg['admin_username'] ?? 'admin') ?>" autocomplete="username" class="visually-hidden" tabindex="-1" aria-hidden="true" readonly>
                                <input type="password" class="form-control bg-dark text-light border-secondary" id="cl-password" autocomplete="current-password" required>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> <?= _h('common.cancel') ?></button>
                                <button type="button" class="btn btn-sm btn-outline-info" id="btn-cl-plan"><i class="bi bi-search"></i> <?= _h('a.traffic.cl_check') ?></button>
                                <button type="submit" class="btn btn-sm btn-outline-success" id="cl-add-ok"><i class="bi bi-check-lg"></i> <?= _h('a.traffic.cl_create') ?></button>
                            </div>
                        </form>
                        <div id="cl-add-alert" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (function_exists('livesyncEnabled') && livesyncEnabled($cfg)): ?>
        <div class="wl-status-card nl-card" id="livesync-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-arrow-left-right"></i> <?= _h('a.traffic.ls_head') ?> <span class="wl-status-updated" id="ls-updated"></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-livesync" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear"></i> <?= _h('a.head.settings') ?></a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-ls-plan"><i class="bi bi-eye"></i> <?= _h('whitelist.preview') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-ls-arm"><i class="bi bi-play-circle"></i> <?= __('a.traffic.ls_on') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btn-ls-off"><i class="bi bi-stop-circle"></i> <?= __('a.traffic.ls_off') ?></button>
                </div>
            </div>
            <div class="sy-body" id="ls-body">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <?= __('a.traffic.ls_reading') ?></div>
            </div>
            <div id="ls-notes"></div>
        </div>
        <?php endif; ?>
        <!-- The stability probe. Hidden entirely until it is switched on in Settings: a control that
             moves the firewall limit on a live machine should not sit there looking clickable on an
             install that has never heard of it. -->
        <div class="wl-status-card mt-3 d-hidden" id="tn-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-activity"></i> <?= _h('a.traffic.tn_head') ?>
                    <span class="wl-status-updated" id="tn-updated"></span></h6>
                <div class="wl-status-actions">
                    <select class="form-select form-select-sm bg-dark text-light border-secondary tn-what" id="tn-what" title="<?= _h('a.traffic.tn_what_title') ?>">
                        <option value="inbound"><?= _h('a.traffic.tn_inbound') ?></option>
                        <option value="outbound"><?= _h('a.traffic.tn_outbound') ?></option>
                        <option value="both"><?= _h('a.traffic.tn_both') ?></option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="tn-dry" title="<?= __('a.traffic.tn_dry_title') ?>"><i class="bi bi-check2-circle"></i> <?= _h('a.traffic.tn_dry') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="tn-start"><?= __('a.traffic.tn_start') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-danger d-hidden" id="tn-cancel"><?= _h('a.traffic.tn_cancel') ?></button>
                </div>
            </div>
            <div class="wl-status-grid" id="tn-grid"></div>
            <div id="tn-progress" class="tn-progress d-hidden"></div>
            <div id="tn-report" class="tn-report d-hidden"></div>
            <div class="nl-note nl-note-info" id="tn-note"></div>
        </div>

    </div>

    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-traffic.js<?= assetVer('assets/js/admin-traffic.js') ?>"></script>
    <?php if (function_exists('tunerEnabled') && tunerEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-tuner.js<?= assetVer('assets/js/admin-tuner.js') ?>"></script>
    <?php endif; ?>
    <?php if ($tlOn || $netOn): ?>
    <script src="<?= $baseUrl ?>assets/vendor/uplot/uPlot.iife.min.js<?= assetVer('assets/vendor/uplot/uPlot.iife.min.js') ?>"></script>
    <?php endif; ?>
    <?php if ($tlOn): ?>
    <script src="<?= $baseUrl ?>assets/js/stats-timeline.js<?= assetVer('assets/js/stats-timeline.js') ?>"></script>
    <?php endif; ?>
    <?php if ($netOn): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-netlimit.js<?= assetVer('assets/js/admin-netlimit.js') ?>"></script>
    <?php endif; ?>
    <?php if (netlimitCommand($cfg) !== ''): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-iplists.js<?= assetVer('assets/js/admin-iplists.js') ?>"></script>
    <?php endif; ?>
    <?php if (otPerfCommand($cfg) !== ''): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-otperf.js<?= assetVer('assets/js/admin-otperf.js') ?>"></script>
    <?php if (sysctlEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-sysctl.js<?= assetVer('assets/js/admin-sysctl.js') ?>"></script>
    <?php endif; ?>
    <?php if (otClusterEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-cluster.js<?= assetVer('assets/js/admin-cluster.js') ?>"></script>
    <?php if (function_exists('livesyncEnabled') && livesyncEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-livesync.js<?= assetVer('assets/js/admin-livesync.js') ?>"></script>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</body>
</html>
