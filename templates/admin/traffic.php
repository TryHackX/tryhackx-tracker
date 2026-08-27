<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traffic &mdash; <?= sanitize($cfg['site_name'] ?? 'Tracker') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= $baseUrl ?>assets/css/admin.css<?= assetVer('assets/css/admin.css') ?>">
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
            <h2><i class="bi bi-speedometer2"></i> Traffic <span class="idx-subtitle">what the swarm sends and what the firewall lets through</span></h2>
            <?php $current = 'admin-traffic'; include __DIR__ . '/_header_actions.php'; ?>
        </div>

        <?php if ($tlOn): ?>
        <!-- Swarm timeline (assets/js/stats-timeline.js + vendored uPlot; samples by tools/janitor.php) -->
        <div class="wl-status-card tl-card" id="wl-timeline-card">
            <div class="wl-status-head">
                <h6><i class="bi bi-graph-up"></i> Swarm timeline <span class="wl-status-updated">one sample / <?= (int)statsTimelineInterval($cfg) ?> s · raw <?= (int)statsTimelineRawDays($cfg) ?> d · 5-min <?= (int)statsTimelineKeepDays($cfg) ?> d · <?= statsTimelinePublic($cfg) ? 'public' : 'admins only' ?></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-timeline" class="btn btn-sm btn-outline-secondary" title="Timeline settings"><i class="bi bi-gear"></i> Settings</a>
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-tl-toggle" data-tl-collapse="wl-timeline" aria-expanded="true" aria-controls="wl-timeline"><i class="bi bi-chevron-up"></i> <span>Collapse</span></button>
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
                <h6><i class="bi bi-speedometer2"></i> UDP traffic <span class="wl-status-updated" id="net-updated">port <?= (int)netlimitPort($cfg) ?> · sample <?= (int)netlimitSampleSeconds($cfg) ?> s · keep <?= (int)netlimitKeepDays($cfg) ?> d</span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-netlimit" class="btn btn-sm btn-outline-secondary" title="UDP traffic settings"><i class="bi bi-gear"></i> Settings</a>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-net-panic" title="Clamp the port to 10 000 packets/second for 15 minutes; the janitor puts the previous setting back automatically"><i class="bi bi-exclamation-octagon"></i> Throttle hard&hellip;</button>
                    <!-- bound by admin-netlimit.js, NOT by the timeline's [data-tl-collapse] handler:
                         that file is only loaded when the swarm timeline is on, and two handlers on
                         one button would cancel each other out when it is -->
                    <button type="button" class="btn btn-sm btn-outline-info" id="btn-net-toggle" aria-expanded="true" aria-controls="net-body"><i class="bi bi-chevron-up"></i> <span>Collapse</span></button>
                </div>
            </div>
            <div id="net-body">
                <div class="wl-status-grid" id="net-grid">
                    <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Reading the firewall&hellip;</div>
                </div>
                <div id="net-notes"></div>

                <!-- The throttle itself. The slider is logarithmic (1 000 … 1 000 000 pps) and carries the
                     measured median / P95 / peak as reference marks, so the number is a decision, not a guess. -->
                <div class="nl-tune" id="net-tune">
                    <div class="nl-tune-head">
                        <span class="nl-tune-title">Inbound limit</span>
                        <span class="nl-tune-value"><input type="number" id="net-pps-input" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= (int)netlimitPps($cfg) ?>" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000" aria-label="Packets per second"> <span class="nl-unit">pps</span></span>
                    </div>
                    <div class="nl-slider-wrap">
                        <input type="range" class="form-range nl-slider" id="net-pps-range" min="0" max="1000" value="0" aria-label="Inbound packets/second limit">
                        <div class="nl-scale" id="net-scale" aria-hidden="true"></div>
                        <div class="nl-marks" id="net-marks" aria-hidden="true"></div>
                    </div>
                    <div class="nl-advice" id="net-advice"></div>
                    <div class="nl-tune-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-net-suggest" title="Set the slider to the suggested value"><i class="bi bi-magic"></i> Use suggested</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-net-preview" title="Render and syntax-check the ruleset without touching the firewall"><i class="bi bi-eye"></i> Preview ruleset</button>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btn-net-apply"><i class="bi bi-check2-circle"></i> Apply limit&hellip;</button>
                        <button type="button" class="btn btn-sm btn-outline-warning" id="btn-net-off"><i class="bi bi-x-circle"></i> Remove limit&hellip;</button>
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
                        <span class="nl-tune-title">Outbound budget <span class="nl-unit">(replies the tracker sends)</span></span>
                        <span class="nl-tune-value"><input type="number" id="net-epps-input" class="form-control form-control-sm bg-dark text-light border-secondary" value="" placeholder="&mdash;" min="<?= NET_PPS_MIN ?>" max="<?= NET_PPS_MAX ?>" step="1000" aria-label="Outbound packets per second" disabled> <span class="nl-unit">pps</span></span>
                    </div>
                    <div class="nl-slider-wrap">
                        <input type="range" class="form-range nl-slider" id="net-epps-range" min="0" max="1000" value="0" aria-label="Outbound packets/second budget" disabled>
                        <div class="nl-scale" id="net-escale" aria-hidden="true"></div>
                        <div class="nl-marks" id="net-emarks" aria-hidden="true"></div>
                    </div>
                    <div class="nl-advice" id="net-eadvice"><span class="nl-tune-waiting">Reading the outbound rule from the firewall&hellip;</span></div>
                    <div class="nl-tune-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-net-esuggest" title="Set the slider to a value with headroom over what was measured" disabled><i class="bi bi-magic"></i> Use suggested</button>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btn-net-eapply" disabled><i class="bi bi-check2-circle"></i> Apply budget&hellip;</button>
                    </div>
                </div>

                <div class="nl-chart-head">
                    <span class="nl-chart-title">Packets / second</span>
                    <div class="nl-ranges" id="net-ranges" role="group" aria-label="Chart range"></div>
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
                        <h5 class="modal-title"><i class="bi bi-shield-lock text-warning"></i> <span id="net-modal-title">Change the inbound limit</span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-light mb-2" style="font-size:0.9rem;" id="net-modal-text"></p>
                        <div class="nl-undo" id="net-modal-undo"></div>
                        <form id="net-confirm-form">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.85rem;color:#bbb;">Admin Password *</label>
                                <input type="password" class="form-control bg-dark text-light border-secondary" id="net-confirm-password" autocomplete="current-password" required>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancel</button>
                                <button type="submit" class="btn btn-sm btn-outline-success" id="net-confirm-ok"><i class="bi bi-check-lg"></i> Confirm</button>
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
                        <h5 class="modal-title"><i class="bi bi-eye text-info"></i> Ruleset preview</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-light mb-2" style="font-size:0.85rem;">Syntax-checked by <code>nft -c</code> on the server. Nothing has been loaded &mdash; this is exactly what <span id="net-preview-file" class="text-info"></span> would contain.</p>
                        <pre class="nl-preview" id="net-preview-body"></pre>
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
                <h6><i class="bi bi-cpu"></i> OpenTracker &mdash; performance <span class="wl-status-updated" id="ot-updated"></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-ot-perf" class="btn btn-sm btn-outline-secondary" title="Performance settings"><i class="bi bi-gear"></i> Settings</a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-ot-preview" title="Render the drop-in without writing it"><i class="bi bi-eye"></i> Preview drop-in</button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-ot-apply"><i class="bi bi-check2-circle"></i> Apply&hellip;</button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btn-ot-workers"><i class="bi bi-diagram-3"></i> Set workers&hellip;</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-ot-restart" title="Restart the tracker service"><i class="bi bi-bootstrap-reboot"></i> Restart&hellip;</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-ot-reset" title="Delete the panel's drop-in"><i class="bi bi-arrow-counterclockwise"></i> Reset&hellip;</button>
                </div>
            </div>
            <div class="wl-status-grid" id="ot-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Reading the service&hellip;</div>
            </div>
            <div id="ot-notes"></div>
        </div>

        <!-- One password gate for every operation here, exactly like the firewall's -->
        <div class="modal fade" id="otConfirmModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-shield-lock text-warning"></i> <span id="ot-modal-title">Change how the tracker runs</span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-light mb-2" style="font-size:0.9rem;" id="ot-modal-text"></p>
                        <div class="nl-undo" id="ot-modal-undo"></div>
                        <form id="ot-confirm-form">
                            <div class="mb-2 d-hidden" id="ot-workers-row">
                                <label class="form-label" style="font-size:0.85rem;color:#bbb;">UDP worker threads</label>
                                <input type="number" class="form-control bg-dark text-light border-secondary" id="ot-workers-input" min="1" max="64" value="4">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.85rem;color:#bbb;">Admin Password *</label>
                                <input type="password" class="form-control bg-dark text-light border-secondary" id="ot-confirm-password" autocomplete="current-password" required>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancel</button>
                                <button type="submit" class="btn btn-sm btn-outline-success" id="ot-confirm-ok"><i class="bi bi-check-lg"></i> Confirm</button>
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
                        <h5 class="modal-title"><i class="bi bi-file-earmark-code"></i> <span id="ot-preview-title">Drop-in preview</span></h5>
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
            <div class="wl-status-head"><h6><i class="bi bi-speedometer2"></i> Nothing is being measured</h6></div>
            <div class="wl-status-grid">
                <div class="wl-kv-item">
                    <div class="wl-kv-k">Swarm timeline</div>
                    <div class="wl-kv-v"><span class="wl-small text-muted">off &mdash; <a href="<?= $baseUrl ?>?action=settings#section-timeline">Settings &rarr; Swarm timeline</a></span></div>
                </div>
                <div class="wl-kv-item">
                    <div class="wl-kv-k">UDP traffic</div>
                    <div class="wl-kv-v"><span class="wl-small text-muted">off &mdash; <a href="<?= $baseUrl ?>?action=settings#section-netlimit">Settings &rarr; UDP traffic &amp; rate limit</a></span></div>
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
                <h6><i class="bi bi-sliders2"></i> Kernel network buffers <span class="wl-status-updated" id="sy-updated"></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-sysctl" class="btn btn-sm btn-outline-secondary" title="Helper and confirmation window"><i class="bi bi-gear"></i> Settings</a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-sy-suggest" title="Fill in what the measurements above actually support"><i class="bi bi-magic"></i> Use suggested</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-sy-preview" title="Render the file without writing it"><i class="bi bi-eye"></i> Preview file</button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-sy-arm"><i class="bi bi-stopwatch"></i> Apply for a while&hellip;</button>
                    <!-- Always here, not only inside the armed banner. Once a change is confirmed the
                         banner is gone, and the way back went with it. -->
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btn-sy-restore" disabled><i class="bi bi-arrow-counterclockwise"></i> Restore defaults</button>
                </div>
            </div>

            <!-- Shown only while a change is in force and unconfirmed. Confirm and Revert are kept
                 apart on purpose: one of them makes the change survive a reboot, and it would be read
                 on a page that may be stuttering at the time. -->
            <div class="sy-armed d-hidden" id="sy-armed">
                <div class="sy-armed-head">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span id="sy-armed-text">A change is in force and will undo itself.</span>
                </div>
                <div class="sy-countdown" id="sy-countdown"></div>
                <div class="sy-armed-keys" id="sy-armed-keys"></div>
                <div class="sy-armed-actions">
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btn-sy-revert"><i class="bi bi-arrow-counterclockwise"></i> Put it back now</button>
                    <span class="sy-armed-gap"></span>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-sy-confirm"><i class="bi bi-check2-circle"></i> Keep it (survives a reboot)&hellip;</button>
                </div>
            </div>

            <!-- NOT .wl-status-grid: that is repeat(auto-fill, minmax(250px, 1fr)), so every row here
                 landed inside one 250-pixel column and every sentence wrapped one word per line. These
                 rows want the full width of the card and lay themselves out. -->
            <div class="sy-body" id="sy-grid">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Reading the kernel&hellip;</div>
            </div>
            <div id="sy-notes"></div>
        </div>

        <!-- One password gate, one acknowledgement per dangerous key -->
        <div class="modal fade" id="syConfirmModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-shield-lock text-warning"></i> <span id="sy-modal-title">Apply the kernel buffers</span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-light mb-2" style="font-size:0.9rem;" id="sy-modal-text"></p>
                        <div class="nl-undo" id="sy-modal-undo"></div>
                        <div id="sy-modal-warnings"></div>
                        <form id="sy-confirm-form">
                            <div id="sy-modal-acks"></div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:0.85rem;color:#bbb;">Admin Password *</label>
                                <input type="password" class="form-control bg-dark text-light border-secondary" id="sy-confirm-password" autocomplete="current-password" required>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancel</button>
                                <button type="submit" class="btn btn-sm btn-outline-success" id="sy-confirm-ok"><i class="bi bi-check-lg"></i> Confirm</button>
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
                        <h5 class="modal-title"><i class="bi bi-file-earmark-code"></i> <span id="sy-preview-title">File preview</span></h5>
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
                <h6><i class="bi bi-diagram-3"></i> OpenTracker instances <span class="wl-status-updated" id="cl-updated"></span></h6>
                <div class="wl-status-actions">
                    <a href="<?= $baseUrl ?>?action=settings#section-cluster" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear"></i> Settings</a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-cl-reload" title="SIGHUP every instance so it re-reads the shared accesslist"><i class="bi bi-arrow-repeat"></i> Reload all</button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btn-cl-add"><i class="bi bi-plus-lg"></i> Add instance&hellip;</button>
                </div>
            </div>
            <div class="sy-body" id="cl-body">
                <div class="wl-status-loading"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Reading the roster&hellip;</div>
            </div>
            <div id="cl-notes"></div>
        </div>

        <div class="modal fade" id="clAddModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content bg-dark">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-plus-circle text-success"></i> Add a tracker instance</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-light mb-2" style="font-size:0.9rem;">A new instance starts answering announces as soon as it exists. It shares this tracker&rsquo;s accesslist and its white/black mode, and runs the same binary &mdash; only the ports differ. Clients reach it only if you publish the extra announce URL below.</p>
                        <form id="cl-add-form">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label" style="font-size:0.85rem;color:#bbb;">Name</label>
                                    <input type="text" class="form-control bg-dark text-light border-secondary" id="cl-name" maxlength="16" placeholder="edge-a" required>
                                    <small class="settings-hint">a&ndash;z, 0&ndash;9 and -, up to 16.</small>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="font-size:0.85rem;color:#bbb;">UDP port</label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary" id="cl-udp" min="1024" max="65535" required>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="font-size:0.85rem;color:#bbb;">TCP port</label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary" id="cl-tcp" min="1024" max="65535" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-size:0.85rem;color:#bbb;">CPU affinity <small class="settings-hint">(optional)</small></label>
                                    <input type="text" class="form-control bg-dark text-light border-secondary" id="cl-affinity" placeholder="e.g. 2-3">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-size:0.85rem;color:#bbb;">UDP workers <small class="settings-hint">(0 = copy the primary&rsquo;s)</small></label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary" id="cl-workers" min="0" max="64" value="0">
                                </div>
                            </div>
                            <div id="cl-plan" class="mt-2"></div>
                            <div class="mb-3 mt-2">
                                <label class="form-label" style="font-size:0.85rem;color:#bbb;">Admin Password *</label>
                                <input type="password" class="form-control bg-dark text-light border-secondary" id="cl-password" autocomplete="current-password" required>
                            </div>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancel</button>
                                <button type="button" class="btn btn-sm btn-outline-info" id="btn-cl-plan"><i class="bi bi-search"></i> Check the ports</button>
                                <button type="submit" class="btn btn-sm btn-outline-success" id="cl-add-ok"><i class="bi bi-check-lg"></i> Create</button>
                            </div>
                        </form>
                        <div id="cl-add-alert" class="mt-2"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-common.js<?= assetVer('assets/js/admin-common.js') ?>"></script>
    <script src="<?= $baseUrl ?>assets/js/admin-traffic.js<?= assetVer('assets/js/admin-traffic.js') ?>"></script>
    <?php if ($tlOn || $netOn): ?>
    <script src="<?= $baseUrl ?>assets/vendor/uplot/uPlot.iife.min.js<?= assetVer('assets/vendor/uplot/uPlot.iife.min.js') ?>"></script>
    <?php endif; ?>
    <?php if ($tlOn): ?>
    <script src="<?= $baseUrl ?>assets/js/stats-timeline.js<?= assetVer('assets/js/stats-timeline.js') ?>"></script>
    <?php endif; ?>
    <?php if ($netOn): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-netlimit.js<?= assetVer('assets/js/admin-netlimit.js') ?>"></script>
    <?php endif; ?>
    <?php if (otPerfCommand($cfg) !== ''): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-otperf.js<?= assetVer('assets/js/admin-otperf.js') ?>"></script>
    <?php if (sysctlEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-sysctl.js<?= assetVer('assets/js/admin-sysctl.js') ?>"></script>
    <?php endif; ?>
    <?php if (otClusterEnabled($cfg)): ?>
    <script src="<?= $baseUrl ?>assets/js/admin-cluster.js<?= assetVer('assets/js/admin-cluster.js') ?>"></script>
    <?php endif; ?>
    <?php endif; ?>
</body>
</html>
