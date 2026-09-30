<?php
/**
 * A list's Edit window (1.70.0): the name and the description, saved together in one request.
 *
 * The card's button was "Rename" — an inline box for the name — and is "Edit" now: the owner asked for
 * descriptions on lists, written with BBCode or Markdown and the emoji, in a window. So this is the site's
 * shared editor (window.RichText.mount(), assets/js/app.js: the Write / Preview tabs, the format, the
 * rail, the live Preview and the counter) with the picker on its last button (emojiPickerButton(), the
 * `list` context: emoji, emotes, stickers bounded), and WITHOUT a picture button — a list's description
 * shows no picture from elsewhere (includes/lists.php says why). assets/js/favourites.js fills it from the
 * card it was opened from, counts what a reader will see while it is typed (listDescStrip()'s twin), and
 * sends name, description and format as op `edit`; nothing is saved until Save.
 *
 * Drawn by the server, like the message composer and the Info panel's editor, so its words come from the
 * dictionary the rest of the page uses — and with an id on everything that carries a word, so the
 * in-place language switch (assets/js/lang-swap.js) finds each of them by name, not by position.
 *
 * Included where the reader's own cards are: the account page's Lists tab and the reader's own profile.
 * Expects $db, $cfg, $baseUrl.
 *
 * WHO SEES IT (1.72.0): the window's third question, under the name — private, friends, public — a group of
 * three buttons that behave as radio buttons (an icon and a word each; arrows move the choice), saved with the
 * name and the description. What THIS reader may choose is the server's answer (listsContext(): the same
 * functions the save and every reader ask), so an answer they may not give is drawn disabled, with the line
 * that says why; a list that already has it keeps it until its owner chooses another.
 */
$leFormats = function_exists('richtextFormats') ? richtextFormats($cfg) : ['bbcode'];
$leCtx = listsContext($db, $cfg, currentUser($db));
$leVis = [
    'private' => ['icon' => 'bi-lock', 'ok' => true],
    'friends' => ['icon' => 'bi-people', 'ok' => !empty($leCtx['may_friends'])],
    'public'  => ['icon' => 'bi-globe2', 'ok' => !empty($leCtx['may_publish'])],
];
// Why an answer is not theirs: one line for the site keeping every list private, else one per answer.
$leWhy = [];
if (($leCtx['public_why'] ?? '') === 'sharing_off') {
    $leWhy['sharing'] = __('lists.vis_why_sharing_off');
} else {
    if (($leCtx['friends_why'] ?? '') !== '') $leWhy['friends'] = __('lists.vis_why_' . $leCtx['friends_why']);
    if (($leCtx['public_why'] ?? '') !== '') $leWhy['public'] = __('lists.vis_why_' . $leCtx['public_why'], ['perm' => 'lists.public']);
}
?>
<div class="files-overlay" id="le-overlay" hidden data-desc-max="<?= (int)listsDescMax($cfg) ?>">
    <div class="files-box le-box" id="le-box" role="dialog" aria-modal="true" aria-labelledby="le-title">
        <div class="files-head" id="le-head">
            <h3 id="le-title"><?= _h('lists.edit_title') ?></h3>
            <?php /* What a first press of × with something unsaved says, beside the ×: the second press closes. */ ?>
            <span class="le-close-hint" id="le-close-hint" role="status" aria-live="polite" hidden></span>
            <button type="button" class="files-close" id="le-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="files-body le-body" id="le-body">
            <div class="form-group" id="le-name-group">
                <label for="le-name" id="le-name-label"><?= _h('lists.edit_name') ?></label>
                <input type="text" id="le-name" maxlength="<?= (int)LIST_NAME_MAX ?>" autocomplete="off" aria-describedby="le-name-hint">
                <p class="form-hint" id="le-name-hint"><?= _h('lists.edit_name_hint') ?></p>
            </div>
            <div class="form-group" id="le-vis-group">
                <span class="le-vis-label" id="le-vis-label"><?= _h('lists.vis_label') ?></span>
                <div class="vis-seg" id="le-vis" role="radiogroup" aria-labelledby="le-vis-label">
                    <?php foreach ($leVis as $v => $o): ?>
                    <button type="button" class="btn btn-secondary btn-small vis-opt vis-opt-<?= $v ?>" id="le-vis-<?= $v ?>" role="radio"
                            aria-checked="false" tabindex="-1" data-vis="<?= $v ?>"<?= $o['ok'] ? '' : ' data-off="1" disabled' ?>><i class="bi <?= $o['icon'] ?>" aria-hidden="true"></i><span id="le-vis-<?= $v ?>-t"><?= _h('lists.vis_' . $v) ?></span></button>
                    <?php endforeach; ?>
                </div>
                <?php /* What the chosen answer means — the one shown is the checked one's (the script) — and why an
                         answer is not this reader's to give. */ ?>
                <?php foreach (array_keys($leVis) as $v): ?>
                <p class="form-hint le-vis-means" id="le-vis-means-<?= $v ?>" data-vis="<?= $v ?>" hidden><?= _h('lists.vis_' . $v . '_hint') ?></p>
                <?php endforeach; ?>
                <?php foreach ($leWhy as $k => $line): ?>
                <p class="form-hint le-vis-why" id="le-vis-why-<?= $k ?>"><?= $line ?></p>
                <?php endforeach; ?>
                <?php /* The owner's "Show my lists on my profile" off: shared or not, nobody else sees any of them —
                         shown by the script, from the shelf's answer (section_shown), for a list chosen to be shared. */ ?>
                <p class="form-hint le-vis-hidden" id="le-vis-hidden" hidden><?= _h('lists.vis_section_hidden') ?></p>
            </div>
            <div class="form-group" id="le-desc-group">
                <label for="le-desc" id="le-desc-label"><?= _h('lists.edit_desc') ?></label>
                <div class="rt-editor" id="le-desc-editor">
                    <div class="rt-tabs" id="le-desc-tabs">
                        <button type="button" class="rt-tab active" data-rt="write" id="le-desc-tab-write"><?= _h('whitelist.write') ?></button>
                        <button type="button" class="rt-tab" data-rt="preview" id="le-desc-tab-preview"><?= _h('whitelist.preview') ?></button>
                        <span class="rt-counter" id="le-desc-count"></span>
                        <?php if (count($leFormats) > 1): ?>
                        <select id="le-desc-format" class="rt-format" title="<?= _h('whitelist.format_title') ?>">
                            <option value="bbcode">BBCode</option>
                            <option value="markdown">Markdown</option>
                        </select>
                        <?php else: ?>
                        <input type="hidden" id="le-desc-format" value="<?= sanitize($leFormats[0]) ?>">
                        <span class="rt-format-fixed" id="le-desc-format-fixed"><?= $leFormats[0] === 'markdown' ? 'Markdown' : 'BBCode' ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="rt-tools" id="le-desc-tools" role="toolbar" aria-label="<?= _h('rt.toolbar') ?>">
                        <span class="rt-tool-group" id="le-desc-tg-text">
                            <button type="button" data-md="bold" title="<?= _h('rt.bold') ?>"><i class="bi bi-type-bold" aria-hidden="true"></i></button>
                            <button type="button" data-md="italic" title="<?= _h('rt.italic') ?>"><i class="bi bi-type-italic" aria-hidden="true"></i></button>
                            <button type="button" data-md="underline" title="<?= _h('rt.underline') ?>"><i class="bi bi-type-underline" aria-hidden="true"></i></button>
                            <button type="button" data-md="strike" title="<?= _h('rt.strike') ?>"><i class="bi bi-type-strikethrough" aria-hidden="true"></i></button>
                        </span>
                        <span class="rt-tool-group" id="le-desc-tg-style">
                            <button type="button" data-md="color" title="<?= _h('rt.color') ?>"><i class="bi bi-palette" aria-hidden="true"></i></button>
                            <button type="button" data-md="size" title="<?= _h('rt.size') ?>"><i class="bi bi-fonts" aria-hidden="true"></i></button>
                            <button type="button" data-md="highlight" title="<?= _h('rt.highlight') ?>"><i class="bi bi-highlighter" aria-hidden="true"></i></button>
                            <button type="button" data-md="sub" title="<?= _h('rt.sub') ?>"><i class="bi bi-subscript" aria-hidden="true"></i></button>
                            <button type="button" data-md="sup" title="<?= _h('rt.sup') ?>"><i class="bi bi-superscript" aria-hidden="true"></i></button>
                        </span>
                        <?php /* No picture button: nothing from elsewhere is drawn in a list's description. */ ?>
                        <span class="rt-tool-group" id="le-desc-tg-links">
                            <button type="button" data-md="link" title="<?= _h('rt.link') ?>"><i class="bi bi-link-45deg" aria-hidden="true"></i></button>
                            <button type="button" data-md="list" title="<?= _h('rt.list') ?>"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
                            <button type="button" data-md="olist" title="<?= _h('rt.olist') ?>"><i class="bi bi-list-ol" aria-hidden="true"></i></button>
                        </span>
                        <span class="rt-tool-group" id="le-desc-tg-blocks">
                            <button type="button" data-md="quote" title="<?= _h('rt.quote') ?>"><i class="bi bi-quote" aria-hidden="true"></i></button>
                            <button type="button" data-md="code" title="<?= _h('rt.code') ?>"><i class="bi bi-code-slash" aria-hidden="true"></i></button>
                            <button type="button" data-md="table" title="<?= _h('rt.table') ?>"><i class="bi bi-table" aria-hidden="true"></i></button>
                            <button type="button" data-md="spoiler" title="<?= _h('rt.spoiler') ?>"><i class="bi bi-eye-slash" aria-hidden="true"></i></button>
                            <button type="button" data-md="center" title="<?= _h('rt.center') ?>"><i class="bi bi-text-center" aria-hidden="true"></i></button>
                            <button type="button" data-md="hr" title="<?= _h('rt.hr') ?>"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
                        </span>
                        <?php /* The picker (1.70.0, C's hand-over): what THIS reader may use in a list's description. */ ?>
                        <?= function_exists('emojiPickerButton') ? emojiPickerButton($db, $cfg, $baseUrl, 'list', 'le-desc-emoji') : '' ?>
                    </div>
                    <?php /* maxlength is the text AS TYPED (listDescSourceCap()); the counter counts what a reader sees. */ ?>
                    <textarea id="le-desc" rows="7" maxlength="<?= (int)listDescSourceCap($cfg) ?>" placeholder="<?= _h('lists.edit_desc_ph') ?>"></textarea>
                    <div class="rt-preview rt-body" id="le-desc-preview" hidden></div>
                </div>
                <details class="rt-syntax-fold" id="le-desc-fold">
                    <summary id="le-desc-fold-summary"><i class="bi bi-chevron-right disc-chev" aria-hidden="true"></i><?= _h('rt.syntax_help') ?></summary>
                    <div class="form-hint" id="le-desc-syntax"></div>
                </details>
                <div class="form-hint" id="le-desc-help"></div>
                <p class="form-hint" id="le-desc-note"><?= _h('lists.edit_desc_note') ?></p>
            </div>
        </div>
        <div class="le-foot" id="le-foot">
            <span class="le-msg text-muted" id="le-msg" role="status" aria-live="polite"></span>
            <?php /* Cancel, Esc and the backdrop ask this when something is unsaved (the picture editor's rule, 1.64.0). */ ?>
            <span class="le-ask" id="le-ask" hidden>
                <span class="le-ask-q" id="le-ask-q"><?= _h('lists.edit_discard_q') ?></span>
                <button type="button" class="btn btn-secondary btn-small le-discard" id="le-discard"><?= _h('lists.edit_discard') ?></button>
                <button type="button" class="btn btn-secondary btn-small" id="le-keep"><?= _h('lists.edit_keep') ?></button>
            </span>
            <button type="button" class="btn btn-secondary btn-small" id="le-cancel"><?= _h('lists.edit_cancel') ?></button>
            <button type="button" class="btn btn-small" id="le-save"><?= _h('lists.edit_save') ?></button>
        </div>
    </div>
</div>
