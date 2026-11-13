<?php
/**
 * Reload / restart controls for the tracker service, shown only when one is configured.
 *
 * Included from inside the navigation bar (templates/admin/_header_actions.php via $navExtra) rather
 * than next to it: `.tracker-svc` is styled as a flex child of `.admin-header-actions`, and the
 * mobile breakpoint reorders it with `order: -1`, which only works from inside that container.
 */
?>
            <div class="tracker-svc" id="tracker-svc">
                <button type="button" class="tracker-warn-badge d-hidden" id="tracker-warn-badge" tabindex="0" aria-label="<?= _h('a.svc.warn_aria') ?>">
                    <i class="bi bi-exclamation-triangle-fill"></i><span id="tracker-warn-count" class="tracker-warn-count"></span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-info tracker-reload-btn" id="btn-reload-tracker" title="<?= _h('a.svc.reload_title', ['svc' => $svcName]) ?>">
                    <i class="bi bi-arrow-clockwise"></i> <?= _h('a.svc.reload') ?>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary tracker-restart-btn" id="btn-restart-tracker" title="<?= _h('a.svc.restart_title', ['svc' => $svcName]) ?>">
                    <i class="bi bi-bootstrap-reboot"></i> <?= _h('a.svc.restart') ?>
                </button>
            </div>
