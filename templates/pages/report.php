<h1>Abuse Report Form</h1>
<p>Use this form to report a copyright infringement regarding a torrent tracked by our tracker.</p>

<div id="report-alert" class="alert"></div>

<form id="report-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

    <div class="form-group"><label for="name">Full name *</label>
    <input type="text" id="name" name="name" maxlength="255" placeholder="John Smith" required>
    <div class="error-msg">This field is required</div></div>

    <div class="form-group"><label for="representative">Represented entity *</label>
    <input type="text" id="representative" name="representative" maxlength="255" placeholder="Name of the entity whose rights were infringed" required>
    <div class="error-msg">This field is required</div></div>

    <div class="form-group"><label for="company">Company / Organization *</label>
    <input type="text" id="company" name="company" maxlength="255" placeholder="Your company name" required>
    <div class="error-msg">This field is required</div></div>

    <div class="form-group"><label for="email">Email address *</label>
    <input type="email" id="email" name="email" maxlength="255" placeholder="contact@company.com" required>
    <div class="error-msg">Invalid email address</div></div>

    <div class="form-group"><label for="objectTitle">Object title *</label>
    <input type="text" id="objectTitle" name="objectTitle" maxlength="255" placeholder="Title of the infringing work" required>
    <div class="error-msg">This field is required</div></div>

    <div class="form-group"><label for="link">Link to the torrent page *</label>
    <input type="url" id="link" name="link" maxlength="500" placeholder="https://example.com/torrent/12345" required>
    <div class="error-msg">Invalid URL</div></div>

    <div class="form-group"><label for="infoHash">Info Hash (SHA1, 40 hex characters) * <small id="hash-hint" class="form-hint"></small></label>
    <input type="text" id="infoHash" name="infoHash" maxlength="40" placeholder="a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2" required pattern="[a-fA-F0-9]{40}">
    <div class="error-msg">Info Hash must be exactly 40 hexadecimal characters (0-9, a-f)</div></div>

    <div class="form-group"><label for="magnetLink">Magnet Link (optional) <small id="magnet-hint" class="form-hint"></small></label>
    <input type="text" id="magnetLink" name="magnet_link" maxlength="2000" placeholder="magnet:?xt=urn:btih:a1b2c3d4e5f6...">
    <div class="error-msg">Invalid magnet link or hash mismatch with Info Hash field</div></div>

    <?php $maxMsg = (int)($cfg['max_message_length'] ?? 2000); ?>
    <div class="form-group"><label for="add_message">Additional information (optional) <small class="form-hint">— <span id="msg-counter">0/<?= $maxMsg ?></span></small></label>
    <textarea id="add_message" name="add_message" maxlength="<?= $maxMsg ?>" rows="4" placeholder="Additional details about the report..." data-maxlength="<?= $maxMsg ?>"></textarea>
    <div class="error-msg">Message exceeds the maximum allowed length</div></div>

    <div class="form-center">
        <button type="submit" class="btn" id="report-submit">Submit Report</button>
    </div>
</form>
