<?php
if (!userCan($db, $cfg, 'whitelist.view')) {
    echo '<h1>' . _h('nav.whitelist') . '</h1><p>' . _h('whitelist.need_account') . '</p>';
    echo '<p><a class="btn" href="' . $baseUrl . '?action=login">' . _h('common.sign_in') . '</a></p>';
    return;
}
$wlMode     = trackerMode($cfg) === 'whitelist';
$wlSched    = function_exists('scheduleEnabled') && scheduleEnabled($cfg);   // whitelist hours → registration always open
$wlReg      = $wlMode || $wlSched;   // registration UI shown (whitelist mode now, or scheduled whitelist hours)
$wlPublic   = ($cfg['whitelist_public_enabled'] ?? '1') === '1';
$wlCaptcha  = captchaConfigured($cfg);
// audience: 'users' = signed-in accounts with the whitelist.add permission (no CAPTCHA)
$wlUsersMode = ($cfg['whitelist_submit_mode'] ?? 'public') === 'users' && usersEnabled($cfg);
$wlMe        = $wlUsersMode ? currentUser($db) : null;
$wlUserOk    = $wlUsersMode && $wlMe !== null && userCan($db, $cfg, 'whitelist.add');
$wlMax      = max(1, (int)($cfg['whitelist_max_per_submission'] ?? 20));
$wlUdp      = trim((string)($cfg['announce_url'] ?? ''));
$wlHttp     = trim((string)($cfg['announce_url_https'] ?? ''));
// Ports served by extra opentracker instances. A magnet naming only the first one never reaches
// them, so somebody registering a torrent here has to be given the whole set. Empty unless the
// cluster is on, which it is not on the overwhelming majority of installs.
$wlExtra    = array_values(array_diff(function_exists('announceUrls') ? announceUrls($cfg) : [],
                                      array_filter([$wlUdp, $wlHttp])));
$wlOpen     = $wlReg && $wlPublic && $wlCaptcha;
// Official number of registered (active, non-banned) hashes. Cheap path: the state file
// (config/whitelist_state.json) — `count` is the row count of the last regeneration plus every append since
// (bans/removals regenerate). Falls back to a COUNT(*) (small table) when the file was never generated
// (fresh install) or the state is flagged as out of date (regen pending / last regen failed).
$wlCount = null;
if ($wlReg) {
    $wlState = function_exists('whitelistStateRead') ? whitelistStateRead() : [];
    if (!empty($wlState['generated_at']) && empty($wlState['regen_needed'])) {
        $wlCount = max(0, (int)($wlState['count'] ?? 0));
    } elseif (isset($db)) {
        try { $wlCount = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE banned = 0")->fetchColumn(); } catch (Throwable $e) { $wlCount = null; }
    }
}
// Scheduled mode notice: hours + what the tracker does right now + next change (schedule timezone)
$wlSchedNotice = '';
if ($wlSched) {
    $wlNext = scheduleNextChange($cfg);
    $wlSchedNotice = __('whitelist.sched_notice', [
        'hours'   => sanitize(scheduleDescribe($cfg)),
        'mode'    => __($wlMode ? 'home.mode_whitelist' : 'home.mode_open'),
        'next'    => $wlNext ? __('whitelist.sched_next', [
                        'at' => sanitize(scheduleFormatLocal($cfg, $wlNext)),
                        'tz' => sanitize(scheduleTimezone($cfg)),
                     ]) : '.',
        'pending' => $wlMode ? '' : __('whitelist.sched_pending'),
    ]);
}
?>
<h1><?= _h('whitelist.h1') ?></h1>

<?php if (!$wlReg): ?>
<p><?= __('whitelist.open_mode') ?></p>
<p><?= __('whitelist.open_see', ['url' => sanitize($baseUrl . '?action=info')]) ?></p>
<?php elseif ($wlPublic && $wlUsersMode && !$wlUserOk): ?>
<?php if ($wlSchedNotice !== ''): ?><div class="wl-schedule-notice"><?= $wlSchedNotice ?></div><?php endif; ?>
<p><?= __('whitelist.users_only', ['hours' => $wlSched ? __('whitelist.only_hours') : '']) ?></p>
<?php if ($wlMe === null): ?>
<p><a class="btn" href="<?= $baseUrl ?>?action=login"><?= _h('common.sign_in') ?></a>
<?php if (usersRegistrationEnabled($cfg)): ?> <a class="btn btn-secondary" href="<?= $baseUrl ?>?action=register"><?= _h('common.create_account') ?></a><?php endif; ?></p>
<?php else: ?>
<p><?= __('whitelist.no_access', ['url' => sanitize($baseUrl . '?action=account')]) ?></p>
<?php endif; ?>
<?php if ($wlCount !== null): ?>
<p class="wl-count"><?= __($wlCount === 1 ? 'whitelist.count_one' : 'whitelist.count_many',
                             ['n' => number_format($wlCount)]) ?></p>
<?php endif; ?>
<div id="wl-check-block">
    <h2 class="section-heading-spaced"><?= _h('whitelist.check_head') ?></h2>
    <form id="wl-check-form" novalidate>
        <div class="form-group"><label for="wl-check-input"><?= _h('whitelist.check_label') ?></label>
        <input type="text" id="wl-check-input" name="hash" maxlength="2048" placeholder="<?= _h('whitelist.check_ph') ?>"></div>
        <div class="form-center"><button type="submit" class="btn" id="wl-check-submit"><?= _h('common.check') ?></button></div>
    </form>
    <div id="wl-check-alert" class="alert"></div>
</div>
<?php elseif (!$wlPublic || (!$wlUsersMode && !$wlCaptcha)): ?>
<?php if ($wlSchedNotice !== ''): ?><div class="wl-schedule-notice"><?= $wlSchedNotice ?></div><?php endif; ?>
<p><?= __('whitelist.closed', [
    'hours' => $wlSched ? __('whitelist.only_hours') : '',
    'why'   => !$wlCaptcha ? __('whitelist.no_captcha') : '',
]) ?></p>
<?php if ($wlCount !== null): ?>
<p class="wl-count"><?= __($wlCount === 1 ? 'whitelist.count_one' : 'whitelist.count_many',
                             ['n' => number_format($wlCount)]) ?></p>
<?php endif; ?>
<div id="wl-check-block">
    <h2 class="section-heading-spaced"><?= _h('whitelist.check_head') ?></h2>
    <form id="wl-check-form" novalidate>
        <div class="form-group"><label for="wl-check-input"><?= _h('whitelist.check_label') ?></label>
        <input type="text" id="wl-check-input" name="hash" maxlength="2048" placeholder="<?= _h('whitelist.check_ph') ?>"></div>
        <div class="form-center"><button type="submit" class="btn" id="wl-check-submit"><?= _h('common.check') ?></button></div>
    </form>
    <div id="wl-check-alert" class="alert"></div>
</div>
<?php else: ?>
<?php if ($wlSchedNotice !== ''): ?><div class="wl-schedule-notice"><?= $wlSchedNotice ?></div><?php endif; ?>
<?php if ($wlUsersMode): ?>
<p><?= __('whitelist.signed_in_as', [
    'hours' => $wlSched ? __('whitelist.only_hours') : '',
    'user'  => sanitize($wlMe['username']),
]) ?></p>
<?php else: ?>
<p><?= __('whitelist.anon', [
    'hours'   => $wlSched ? __('whitelist.only_hours') : '',
    'captcha' => __(captchaProvider($cfg) === 'recaptcha_v3' ? 'whitelist.captcha_v3' : 'whitelist.captcha_std'),
]) ?></p>
<?php endif; ?>
<?php if ($wlCount !== null): ?>
<p class="wl-count"><?= __($wlCount === 1 ? 'whitelist.count_one' : 'whitelist.count_many',
                             ['n' => number_format($wlCount)]) ?></p>
<?php endif; ?>
<ul class="wl-rules">
    <?php if (($cfg['whitelist_require_tracker'] ?? '0') === '1'): ?>
    <li><?= __('whitelist.rule_tracker', [
        'url' => sanitize($wlUdp ?: $wlHttp),
        'alt' => ($wlUdp && $wlHttp) ? __('whitelist.rule_tracker_alt') : '',
    ]) ?></li>
    <?php endif; ?>
    <li><?= __('whitelist.rule_max', ['n' => $wlMax]) ?></li>
    <li><?= __('whitelist.rule_ip', [
        'who'  => __($wlUsersMode ? 'whitelist.rule_ip_user' : 'whitelist.rule_ip_anon'),
        'what' => __($wlUsersMode ? 'whitelist.rule_ip_what_user' : 'whitelist.rule_ip_what_anon'),
    ]) ?></li>
    <li><?= __('whitelist.rule_remove') ?></li>
    <li><?= __('whitelist.rule_serve') ?></li>
</ul>

<div id="wl-alert" class="alert"></div>

<form id="wl-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <div class="form-group">
        <label for="wl-input"><?= _h('whitelist.input_label') ?> <small class="form-hint">— <span id="wl-counter">0 valid</span></small></label>
        <textarea id="wl-input" name="input" rows="6" maxlength="<?= $wlMax * 2100 ?>" data-max="<?= $wlMax ?>" placeholder="magnet:?xt=urn:btih:a1b2c3d4e5f6…&#10;a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2"></textarea>
        <div class="error-msg"><?= _h('whitelist.input_err') ?></div>
    </div>
<?php
    $wlSrcOn  = ($cfg['wl_allow_source_url'] ?? '0') === '1';
    $wlDescOn = ($cfg['wl_allow_description'] ?? '0') === '1';
    $wlFormats = function_exists('richtextFormats') ? richtextFormats($cfg) : ['bbcode'];
    $wlReview = ($cfg['wl_content_review'] ?? '1') === '1';
    // The per-torrent visibility choice, offered only where all three gates agree: the site allows
    // attribution to be shown, this reader's group holds `uploads.public`, and they are signed in.
    // It applies to the rows THIS submission creates — a hash somebody already registered belongs to
    // whoever registered it first, and the reply says so.
    $wlMe = usersEnabled($cfg) ? currentUser($db) : null;
    $wlPubOn = $wlMe !== null && uploadsPublicEnabled($cfg) && userCan($db, $cfg, 'uploads.public');
?>
<?php if ($wlPubOn): ?>
    <div class="form-group wl-visibility">
        <label class="search-check acc-check"><input type="checkbox" id="wl-public" name="submitter_public"><span class="search-check-box" aria-hidden="true"></span>
            <span><?= _h('whitelist.public_label') ?></span></label>
        <div class="form-hint"><?= __('whitelist.public_hint', ['name' => sanitize($wlMe['username'])]) ?></div>
    </div>
<?php endif; ?>
<?php if ($wlSrcOn || $wlDescOn): ?>
    <div class="wl-extra">
        <p class="wl-extra-head"><?= __('whitelist.extra_head') ?></p>
        <?php if ($wlSrcOn): ?>
        <div class="form-group">
            <label for="wl-source"><?= _h('whitelist.source') ?> <small class="form-hint"><?= _h('whitelist.source_hint') ?></small></label>
            <input type="url" id="wl-source" name="source_url" maxlength="500" placeholder="https://example.org/torrents/12345">
            <div class="form-hint"><?= __('whitelist.source_note') ?></div>
        </div>
        <?php endif; ?>
        <?php if ($wlDescOn): ?>
        <div class="form-group">
            <label for="wl-desc"><?= _h('whitelist.desc') ?></label>
            <!-- The editor is one card: tabs and format on the top rail, the formatting buttons on the
                 second, then the box itself. The buttons insert the syntax of whichever format is
                 selected, so the writer never has to remember whether this site wants [b] or **. -->
            <div class="rt-editor">
                <div class="rt-tabs">
                    <button type="button" class="rt-tab active" data-rt="write"><?= _h('whitelist.write') ?></button>
                    <button type="button" class="rt-tab" data-rt="preview"><?= _h('whitelist.preview') ?></button>
                    <span class="rt-counter" id="wl-desc-count"></span>
                    <?php if (count($wlFormats) > 1): ?>
                    <select id="wl-desc-format" name="description_format" class="rt-format" title="<?= _h('whitelist.format_title') ?>">
                        <option value="bbcode">BBCode</option>
                        <option value="markdown">Markdown</option>
                    </select>
                    <?php else: ?>
                    <input type="hidden" id="wl-desc-format" name="description_format" value="<?= sanitize($wlFormats[0]) ?>">
                    <span class="rt-format-fixed"><?= $wlFormats[0] === 'markdown' ? 'Markdown' : 'BBCode' ?></span>
                    <?php endif; ?>
                </div>
                <!-- A button whose syntax the chosen format cannot express hides itself (see
                     syncFormat in assets/js/app.js) — BBCode has [color] and Markdown does not, and
                     a button that inserts markup the renderer will not honour is worse than no
                     button at all. -->
                <div class="rt-tools" id="wl-desc-tools" role="toolbar" aria-label="<?= _h('rt.toolbar') ?>">
                    <span class="rt-tool-group">
                        <button type="button" data-md="bold" title="<?= _h('rt.bold') ?>"><strong>B</strong></button>
                        <button type="button" data-md="italic" title="<?= _h('rt.italic') ?>"><em>I</em></button>
                        <button type="button" data-md="underline" title="<?= _h('rt.underline') ?>"><u>U</u></button>
                        <button type="button" data-md="strike" title="<?= _h('rt.strike') ?>"><s>S</s></button>
                    </span>
                    <span class="rt-tool-group">
                        <button type="button" data-md="color" title="<?= _h('rt.color') ?>">&#127912;</button>
                        <button type="button" data-md="size" title="<?= _h('rt.size') ?>">A&#8593;</button>
                        <button type="button" data-md="highlight" title="<?= _h('rt.highlight') ?>">&#9635;</button>
                        <button type="button" data-md="sub" title="<?= _h('rt.sub') ?>">X&#8322;</button>
                        <button type="button" data-md="sup" title="<?= _h('rt.sup') ?>">X&#178;</button>
                    </span>
                    <span class="rt-tool-group">
                        <button type="button" data-md="link" title="<?= _h('rt.link') ?>">&#128279;</button>
                        <button type="button" data-md="image" title="<?= _h('rt.image') ?>">&#128444;</button>
                        <button type="button" data-md="list" title="<?= _h('rt.list') ?>">&#8226;&nbsp;<?= _h('rt.list_word') ?></button>
                        <button type="button" data-md="olist" title="<?= _h('rt.olist') ?>">1.&nbsp;<?= _h('rt.list_word') ?></button>
                    </span>
                    <span class="rt-tool-group">
                        <button type="button" data-md="quote" title="<?= _h('rt.quote') ?>">&rdquo;</button>
                        <button type="button" data-md="code" title="<?= _h('rt.code') ?>">&lt;/&gt;</button>
                        <button type="button" data-md="table" title="<?= _h('rt.table') ?>">&#9636;</button>
                        <button type="button" data-md="spoiler" title="<?= _h('rt.spoiler') ?>">&#128065;</button>
                        <button type="button" data-md="center" title="<?= _h('rt.center') ?>">&#8801;</button>
                        <button type="button" data-md="hr" title="<?= _h('rt.hr') ?>">&mdash;</button>
                    </span>
                </div>
                <textarea id="wl-desc" name="description" rows="6" maxlength="<?= (int)richtextMaxChars($cfg) ?>" placeholder="<?= _h('whitelist.desc_ph') ?>"></textarea>
                <div class="rt-preview rt-body" id="wl-desc-preview" hidden></div>
            </div>
            <div class="form-hint" id="wl-desc-syntax"></div>
            <div class="form-hint" id="wl-desc-help"></div>
        </div>
        <?php endif; ?>
        <?php if ($wlReview): ?>
        <p class="form-hint"><?= __('whitelist.review_note') ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
    <div class="form-center">
        <button type="submit" class="btn" id="wl-submit"><?= _h('whitelist.submit') ?></button>
    </div>
</form>

<div id="wl-probe" class="wl-probe" hidden>
    <h2><?= _h('whitelist.probe_head') ?></h2>
    <p class="form-hint" id="wl-probe-note"></p>
    <ul class="wl-probe-list" id="wl-probe-list"></ul>
</div>

<div id="wl-results" class="wl-results" hidden>
    <h2><?= _h('common.result') ?></h2>
    <p id="wl-results-summary" class="wl-summary"></p>
    <div id="wl-results-list"></div>
</div>

<?php if ($wlUdp !== '' || $wlHttp !== ''): ?>
<h2 class="section-heading-spaced"><?= _h('whitelist.announce_head') ?></h2>
<p><?= __('whitelist.announce_note') ?></p>
<div class="code-block pos-relative">
    <button class="copy-btn" data-copy="wl-announce-copy" title="<?= _h('home.announce_copy') ?>"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
    <?php if ($wlHttp !== ''): ?><div class="label"><?= stripos($wlHttp, 'https://') === 0 ? 'HTTPS' : 'HTTP' ?></div><code><?= sanitize($wlHttp) ?></code><?php endif; ?>
    <?php if ($wlUdp !== ''): ?><div class="label label-top">UDP</div><code><?= sanitize($wlUdp) ?></code><?php endif; ?>
    <?php foreach ($wlExtra as $eu): ?><div class="label label-top">EXTRA</div><code><?= sanitize($eu) ?></code><?php endforeach; ?>
    <span id="wl-announce-copy" class="announce-hidden"><?= trim(implode(PHP_EOL, array_filter(array_merge([sanitize($wlHttp), sanitize($wlUdp)], array_map('sanitize', $wlExtra))))) ?></span>
</div>
<?php endif; ?>

<div id="wl-check-block">
    <h2 class="section-heading-spaced"><?= _h('whitelist.check_head') ?></h2>
    <form id="wl-check-form" novalidate>
        <div class="form-group"><label for="wl-check-input"><?= _h('whitelist.check_label') ?></label>
        <input type="text" id="wl-check-input" name="hash" maxlength="2048" placeholder="<?= _h('whitelist.check_ph') ?>"></div>
        <div class="form-center"><button type="submit" class="btn" id="wl-check-submit"><?= _h('common.check') ?></button></div>
    </form>
    <div id="wl-check-alert" class="alert"></div>
</div>
<?php endif; ?>
