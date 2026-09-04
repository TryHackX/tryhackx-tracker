<h1><?= _h('status.h1') ?></h1>
<p><?= _h('status.intro') ?></p>

<div id="status-alert" class="alert"></div>

<form id="status-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

    <div class="form-group">
        <label for="search_query"><?= _h('status.query') ?> *</label>
        <input type="text" id="search_query" name="search_query" placeholder="<?= _h('status.query_ph') ?>" required>
        <div class="error-msg"><?= _h('status.query_err') ?></div>
    </div>

    <div class="form-group">
        <label for="status_email"><?= _h('status.email') ?> *</label>
        <input type="email" id="status_email" name="email" placeholder="<?= _h('status.email_ph') ?>" required>
        <div class="error-msg"><?= _h('status.email_err') ?></div>
    </div>

    <div class="form-center">
        <button type="submit" class="btn"><?= _h('status.submit') ?></button>
    </div>
</form>

<div id="status-result" class="status-result" style="display:none">
    <h2><?= _h('status.result_head') ?></h2>
    <div class="card">
        <div class="transparency-table-wrap">
            <table>
                <tr><td><?= _h('status.f_number') ?></td><td id="res-id"></td></tr>
                <tr><td><?= _h('status.f_reporter') ?></td><td id="res-reporter"></td></tr>
                <tr><td><?= _h('status.f_email') ?></td><td id="res-email"></td></tr>
                <tr><td><?= _h('status.f_company') ?></td><td id="res-company"></td></tr>
                <tr><td><?= _h('status.f_rep') ?></td><td id="res-representative"></td></tr>
                <tr><td><?= _h('status.f_object') ?></td><td id="res-object"></td></tr>
                <tr><td><?= _h('status.f_link') ?></td><td id="res-link"></td></tr>
                <tr><td><?= _h('status.f_hash') ?></td><td id="res-hash" class="status-hash-cell"></td></tr>
                <tr id="res-magnet-row" style="display:none"><td><?= _h('status.f_magnet') ?></td><td id="res-magnet" class="status-magnet-cell"></td></tr>
                <tr><td><?= _h('status.f_status') ?></td><td id="res-status"></td></tr>
                <tr><td><?= _h('status.f_date') ?></td><td id="res-date"></td></tr>
            </table>
        </div>
    </div>
    <div class="card status-guide">
        <p class="status-guide-title"><strong><?= _h('status.guide') ?></strong></p>
        <p><span class="status-badge pending status-badge-sm"><?= _h('status.b_pending') ?></span> &mdash; <?= _h('status.b_pending_note') ?></p>
        <p><span class="status-badge checked status-badge-sm"><?= _h('status.b_checked') ?></span> &mdash; <?= _h('status.b_checked_note') ?></p>
        <?php // What "blocked" means depends on the tracker mode, and it is a different fact in each. ?>
        <p><span class="status-badge blocked status-badge-sm"><?= _h('status.b_blocked') ?></span> &mdash; <?= _h(trackerMode($cfg) === 'whitelist' ? 'status.b_blocked_wl' : 'status.b_blocked_bl') ?></p>
        <p><span class="status-badge archived status-badge-sm"><?= _h('status.b_archived') ?></span> &mdash; <?= _h('status.b_archived_note') ?></p>
    </div>

    <div id="status-appeal-section" class="appeal-section" style="display:none">
        <h3><?= _h('status.appeal_head') ?></h3>
        <p id="status-appeal-desc" class="appeal-desc"></p>

        <div id="status-appeal-alert" class="alert"></div>

        <form id="status-appeal-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" id="status-appeal-hash" name="infoHash" value="">
            <input type="hidden" id="status-appeal-type" name="appeal_type" value="block">
            <input type="hidden" id="status-appeal-report-id" name="report_id" value="">

            <div class="form-group">
                <label for="status-appeal-name"><?= _h('status.a_name') ?> *</label>
                <input type="text" id="status-appeal-name" name="name" maxlength="255" placeholder="<?= _h('status.a_name_ph') ?>" required>
                <div class="error-msg"><?= _h('status.required') ?></div>
            </div>

            <div class="form-group">
                <label for="status-appeal-email"><?= _h('status.a_email') ?> *</label>
                <input type="email" id="status-appeal-email" name="email" maxlength="255" placeholder="your@email.com" required>
                <div class="error-msg"><?= _h('status.a_email_err') ?></div>
            </div>

            <?php $maxAppeal2 = (int)($cfg['max_appeal_message_length'] ?? $cfg['max_message_length'] ?? 2000); ?>
            <div class="form-group">
                <label for="status-appeal-message"><?= _h('status.a_reason') ?> * <small class="form-hint">&mdash; <span id="status-appeal-counter">0/<?= $maxAppeal2 ?></span></small></label>
                <textarea id="status-appeal-message" name="message" maxlength="<?= $maxAppeal2 ?>" rows="4" placeholder="<?= _h('status.a_reason_block_ph') ?>" data-maxlength="<?= $maxAppeal2 ?>" required></textarea>
                <div class="error-msg"><?= _h('status.required') ?></div>
            </div>

            <div class="form-center">
                <button type="submit" class="btn" id="status-appeal-submit"><?= _h('status.a_submit') ?></button>
            </div>
        </form>
    </div>
</div>

<h1 class="section-heading-spaced"><?= _h('status.bc_head') ?></h1>
<p><?= _h('status.bc_intro') ?></p>

<div id="block-check-alert" class="alert"></div>

<form id="block-check-form" novalidate>
    <div class="form-group">
        <label for="block_query"><?= _h('status.bc_query') ?> *</label>
        <input type="text" id="block_query" name="block_query" placeholder="<?= _h('status.bc_query_ph') ?>" required>
        <div class="error-msg"><?= _h('status.bc_query_err') ?></div>
    </div>

    <div class="form-center">
        <button type="submit" class="btn"><?= _h('status.bc_submit') ?></button>
    </div>
</form>

<div id="block-check-result" class="status-result" style="display:none">
    <h2><?= _h('status.bc_result') ?></h2>
    <div class="card">
        <div class="transparency-table-wrap">
            <table>
                <tr><td><?= _h('status.f_hash') ?></td><td id="bc-hash" class="status-hash-cell"></td></tr>
                <tr><td><?= _h('status.f_status') ?></td><td id="bc-status"></td></tr>
                <tr id="bc-row-whitelist" style="display:none"><td><?= _h('status.bc_whitelist') ?></td><td id="bc-whitelist"></td></tr>
                <tr id="bc-row-company" style="display:none"><td><?= _h('status.bc_company') ?></td><td id="bc-company"></td></tr>
                <tr id="bc-row-entity" style="display:none"><td><?= _h('status.bc_entity') ?></td><td id="bc-entity"></td></tr>
            </table>
        </div>
    </div>

    <div id="appeal-section" class="appeal-section" style="display:none">
        <h3><?= _h('status.appeal_head') ?></h3>
        <p class="appeal-desc"><?= _h('status.appeal_desc') ?></p>

        <div id="appeal-alert" class="alert"></div>

        <form id="appeal-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" id="appeal-hash" name="infoHash" value="">
            <input type="hidden" name="appeal_type" value="unblock">

            <div class="form-group">
                <label for="appeal-name"><?= _h('status.a_name') ?> *</label>
                <input type="text" id="appeal-name" name="name" maxlength="255" placeholder="<?= _h('status.a_name_ph') ?>" required>
                <div class="error-msg"><?= _h('status.required') ?></div>
            </div>

            <div class="form-group">
                <label for="appeal-email"><?= _h('status.a_email') ?> *</label>
                <input type="email" id="appeal-email" name="email" maxlength="255" placeholder="your@email.com" required>
                <div class="error-msg"><?= _h('status.a_email_err') ?></div>
            </div>

            <?php $maxAppeal = (int)($cfg['max_appeal_message_length'] ?? $cfg['max_message_length'] ?? 2000); ?>
            <div class="form-group">
                <label for="appeal-message"><?= _h('status.a_reason_appeal') ?> * <small class="form-hint">&mdash; <span id="appeal-counter">0/<?= $maxAppeal ?></span></small></label>
                <textarea id="appeal-message" name="message" maxlength="<?= $maxAppeal ?>" rows="4" placeholder="<?= _h('status.a_reason_appeal_ph') ?>" data-maxlength="<?= $maxAppeal ?>" required></textarea>
                <div class="error-msg"><?= _h('status.required') ?></div>
            </div>

            <div class="form-center">
                <button type="submit" class="btn" id="appeal-submit"><?= _h('status.a_submit') ?></button>
            </div>
        </form>
    </div>
</div>
