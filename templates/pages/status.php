<h1>Check Report Status</h1>
<p>Enter your report number, info hash, or magnet link to check the current status.</p>

<div id="status-alert" class="alert"></div>

<form id="status-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

    <div class="form-group">
        <label for="search_query">Report Number, Info Hash or Magnet Link *</label>
        <input type="text" id="search_query" name="search_query" placeholder="e.g. 42, a1b2c3d4e5f6... or magnet:?xt=urn:btih:..." required>
        <div class="error-msg">Enter a report number, 40-character info hash, or magnet link</div>
    </div>

    <div class="form-group">
        <label for="status_email">Email address *</label>
        <input type="email" id="status_email" name="email" placeholder="Email used in the report" required>
        <div class="error-msg">Valid email address is required</div>
    </div>

    <div class="form-center">
        <button type="submit" class="btn">Check Status</button>
    </div>
</form>

<div id="status-result" class="status-result" style="display:none">
    <h2>Your Report Status</h2>
    <div class="card">
        <div class="transparency-table-wrap">
            <table>
                <tr><td>Number</td><td id="res-id"></td></tr>
                <tr><td>Reporter</td><td id="res-reporter"></td></tr>
                <tr><td>Email</td><td id="res-email"></td></tr>
                <tr><td>Company</td><td id="res-company"></td></tr>
                <tr><td>Representative</td><td id="res-representative"></td></tr>
                <tr><td>Object</td><td id="res-object"></td></tr>
                <tr><td>Link</td><td id="res-link"></td></tr>
                <tr><td>Info Hash</td><td id="res-hash" class="status-hash-cell"></td></tr>
                <tr id="res-magnet-row" style="display:none"><td>Magnet Link</td><td id="res-magnet" class="status-magnet-cell"></td></tr>
                <tr><td>Status</td><td id="res-status"></td></tr>
                <tr><td>Submission date</td><td id="res-date"></td></tr>
            </table>
        </div>
    </div>
    <div class="card status-guide">
        <p class="status-guide-title"><strong>Status Guide:</strong></p>
        <p><span class="status-badge pending status-badge-sm">Awaiting Review</span> &mdash; Your report has been received and is waiting for an administrator to review it.</p>
        <p><span class="status-badge checked status-badge-sm">Reviewed</span> &mdash; An administrator has reviewed your report.</p>
        <p><span class="status-badge blocked status-badge-sm">Blocked</span> &mdash; The reported info hash has been permanently added to the tracker blacklist.</p>
        <p><span class="status-badge archived status-badge-sm">Archived / Closed</span> &mdash; The report has been processed and archived. No further action will be taken unless a new report is submitted.</p>
    </div>

    <div id="status-appeal-section" class="appeal-section" style="display:none">
        <h3>Submit an Appeal</h3>
        <p id="status-appeal-desc" class="appeal-desc"></p>

        <div id="status-appeal-alert" class="alert"></div>

        <form id="status-appeal-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" id="status-appeal-hash" name="infoHash" value="">
            <input type="hidden" id="status-appeal-type" name="appeal_type" value="block">
            <input type="hidden" id="status-appeal-report-id" name="report_id" value="">

            <div class="form-group">
                <label for="status-appeal-name">Full Name *</label>
                <input type="text" id="status-appeal-name" name="name" maxlength="255" placeholder="Your full name" required>
                <div class="error-msg">This field is required</div>
            </div>

            <div class="form-group">
                <label for="status-appeal-email">Email Address *</label>
                <input type="email" id="status-appeal-email" name="email" maxlength="255" placeholder="your@email.com" required>
                <div class="error-msg">Invalid email address</div>
            </div>

            <?php $maxAppeal2 = (int)($cfg['max_appeal_message_length'] ?? $cfg['max_message_length'] ?? 2000); ?>
            <div class="form-group">
                <label for="status-appeal-message">Reason * <small class="form-hint">— <span id="status-appeal-counter">0/<?= $maxAppeal2 ?></span></small></label>
                <textarea id="status-appeal-message" name="message" maxlength="<?= $maxAppeal2 ?>" rows="4" placeholder="Explain why you believe this hash should be blocked / re-examined..." data-maxlength="<?= $maxAppeal2 ?>" required></textarea>
                <div class="error-msg">This field is required</div>
            </div>

            <div class="form-center">
                <button type="submit" class="btn" id="status-appeal-submit">Submit Appeal</button>
            </div>
        </form>
    </div>
</div>

<h1 class="section-heading-spaced">Block Check</h1>
<p>Check if an info hash or magnet link is currently blocked on our tracker.</p>

<div id="block-check-alert" class="alert"></div>

<form id="block-check-form" novalidate>
    <div class="form-group">
        <label for="block_query">Info Hash or Magnet Link *</label>
        <input type="text" id="block_query" name="block_query" placeholder="40-char hex hash or magnet:?xt=urn:btih:..." required>
        <div class="error-msg">Enter a valid 40-character hex hash or a magnet link</div>
    </div>

    <div class="form-center">
        <button type="submit" class="btn">Check Block Status</button>
    </div>
</form>

<div id="block-check-result" class="status-result" style="display:none">
    <h2>Block Check Result</h2>
    <div class="card">
        <div class="transparency-table-wrap">
            <table>
                <tr><td>Info Hash</td><td id="bc-hash" class="status-hash-cell"></td></tr>
                <tr><td>Status</td><td id="bc-status"></td></tr>
                <tr id="bc-row-company" style="display:none"><td>Company / Organization</td><td id="bc-company"></td></tr>
                <tr id="bc-row-entity" style="display:none"><td>Represented Entity</td><td id="bc-entity"></td></tr>
            </table>
        </div>
    </div>

    <div id="appeal-section" class="appeal-section" style="display:none">
        <h3>Submit an Appeal</h3>
        <p class="appeal-desc">If you believe this hash was blocked in error, you can submit an appeal for review.</p>

        <div id="appeal-alert" class="alert"></div>

        <form id="appeal-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" id="appeal-hash" name="infoHash" value="">
            <input type="hidden" name="appeal_type" value="unblock">

            <div class="form-group">
                <label for="appeal-name">Full Name *</label>
                <input type="text" id="appeal-name" name="name" maxlength="255" placeholder="Your full name" required>
                <div class="error-msg">This field is required</div>
            </div>

            <div class="form-group">
                <label for="appeal-email">Email Address *</label>
                <input type="email" id="appeal-email" name="email" maxlength="255" placeholder="your@email.com" required>
                <div class="error-msg">Invalid email address</div>
            </div>

            <?php $maxAppeal = (int)($cfg['max_appeal_message_length'] ?? $cfg['max_message_length'] ?? 2000); ?>
            <div class="form-group">
                <label for="appeal-message">Reason for Appeal * <small class="form-hint">— <span id="appeal-counter">0/<?= $maxAppeal ?></span></small></label>
                <textarea id="appeal-message" name="message" maxlength="<?= $maxAppeal ?>" rows="4" placeholder="Explain why you believe this block should be reconsidered..." data-maxlength="<?= $maxAppeal ?>" required></textarea>
                <div class="error-msg">This field is required</div>
            </div>

            <div class="form-center">
                <button type="submit" class="btn" id="appeal-submit">Submit Appeal</button>
            </div>
        </form>
    </div>
</div>
