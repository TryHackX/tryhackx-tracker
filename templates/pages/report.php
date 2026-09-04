<h1><?= _h('report.h1') ?></h1>
<p><?= _h('report.intro') ?></p>

<div id="report-alert" class="alert"></div>

<form id="report-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

    <div class="form-group"><label for="name"><?= _h('report.name') ?> *</label>
    <input type="text" id="name" name="name" maxlength="255" placeholder="<?= _h('report.name_ph') ?>" required>
    <div class="error-msg"><?= _h('report.required') ?></div></div>

    <div class="form-group"><label for="representative"><?= _h('report.rep') ?> *</label>
    <input type="text" id="representative" name="representative" maxlength="255" placeholder="<?= _h('report.rep_ph') ?>" required>
    <div class="error-msg"><?= _h('report.required') ?></div></div>

    <div class="form-group"><label for="company"><?= _h('report.company') ?> *</label>
    <input type="text" id="company" name="company" maxlength="255" placeholder="<?= _h('report.company_ph') ?>" required>
    <div class="error-msg"><?= _h('report.required') ?></div></div>

    <div class="form-group"><label for="email"><?= _h('report.email') ?> *</label>
    <input type="email" id="email" name="email" maxlength="255" placeholder="contact@company.com" required>
    <div class="error-msg"><?= _h('report.email_err') ?></div></div>

    <div class="form-group"><label for="objectTitle"><?= _h('report.object') ?> *</label>
    <input type="text" id="objectTitle" name="objectTitle" maxlength="255" placeholder="<?= _h('report.object_ph') ?>" required>
    <div class="error-msg"><?= _h('report.required') ?></div></div>

    <div class="form-group"><label for="link"><?= _h('report.link') ?> *</label>
    <input type="url" id="link" name="link" maxlength="500" placeholder="https://example.com/torrent/12345" required>
    <div class="error-msg"><?= _h('report.link_err') ?></div></div>

    <div class="form-group"><label for="infoHash"><?= _h('report.hash') ?> * <small id="hash-hint" class="form-hint"></small></label>
    <input type="text" id="infoHash" name="infoHash" maxlength="40" placeholder="a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2" required pattern="[a-fA-F0-9]{40}">
    <div class="error-msg"><?= _h('report.hash_err') ?></div></div>

    <div class="form-group"><label for="magnetLink"><?= _h('report.magnet') ?> <small id="magnet-hint" class="form-hint"></small></label>
    <input type="text" id="magnetLink" name="magnet_link" maxlength="2000" placeholder="magnet:?xt=urn:btih:a1b2c3d4e5f6...">
    <div class="error-msg"><?= _h('report.magnet_err') ?></div></div>

    <?php $maxMsg = (int)($cfg['max_message_length'] ?? 2000); ?>
    <div class="form-group"><label for="add_message"><?= _h('report.message') ?> <small class="form-hint">— <span id="msg-counter">0/<?= $maxMsg ?></span></small></label>
    <textarea id="add_message" name="add_message" maxlength="<?= $maxMsg ?>" rows="4" placeholder="<?= _h('report.message_ph') ?>" data-maxlength="<?= $maxMsg ?>"></textarea>
    <div class="error-msg"><?= _h('report.message_err') ?></div></div>

    <div class="form-center">
        <button type="submit" class="btn" id="report-submit"><?= _h('report.submit') ?></button>
    </div>
</form>
