<?php
/**
 * The Info panel, and the "who has this in favourites" overlay that opens from it.
 *
 * ── why this is a partial ──────────────────────────────────────────────────────────────────────
 * It began on the search page, because that is where a row is. It belongs anywhere a torrent is
 * NAMED: the favourites list on the account page, the two lists on a public profile. Those pages
 * used to be able to hand over a magnet and nothing else — a reader could add something to their
 * favourites and then had no way to ask what it was without going back to the search page and
 * typing the name in again.
 *
 * The markup carries its own answers as data attributes (the star, the "who has this" button, how
 * the file list fills up), because the script that drives it now runs on pages that have no search
 * form to read them from.
 *
 * Expects, from the including page: $cfg, $db, $baseUrl, and $favCtx from favContext().
 */
$ioViewer = currentUser($db);
$ioFav    = $favCtx ?? favContext($db, $cfg, $ioViewer);
$ioWho    = !empty($ioFav['who_ok']) && !empty($ioFav['may_view']);
$ioShare  = ($cfg['search_share_enabled'] ?? '1') === '1';
$ioLists  = listsContext($db, $cfg, $ioViewer);
?>
<!-- Info panel: what this torrent is, where it came from, and how the swarm looks. The file list
     lives at the bottom of it; the "N files" chip beside a search result opens the tree on its own. -->
<div class="files-overlay" id="info-overlay" hidden
     data-files-mode="<?= sanitize(indexFilesMode($cfg)) ?>"
     <?php /* The same permission the search page asks before offering "search inside file names".
              The lists on these pages carry the same checkbox and it is gated the same way — and
              gated AGAIN in the endpoints, which are the ones that answer. */ ?>
     data-can-files="<?= userCan($db, $cfg, 'index.files') ? '1' : '0' ?>"
     data-fav="<?= !empty($ioFav['may_use']) ? '1' : '0' ?>"
     data-fav-who="<?= $ioWho ? '1' : '0' ?>"
     data-lists="<?= !empty($ioLists['may_use']) ? '1' : '0' ?>">
    <div class="files-box info-box" role="dialog" aria-modal="true" aria-labelledby="info-title">
        <div class="files-head">
            <h3 id="info-title"><?= _h('search.details') ?></h3>
            <span class="info-acts" id="info-acts"></span>
<?php if ($ioShare): ?>
            <button type="button" class="search-share share-btn info-share" id="info-share"
                    title="<?= _h('search.share_one_title') ?>"><?= _h('search.share') ?></button>
<?php endif; ?>
            <button type="button" class="files-close" id="info-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="files-body" id="info-body"></div>
    </div>
</div>

<?php if (!empty($ioLists['enabled'])): ?>
<?php /* One list, in a window of its own. Inside its card it had to fit a search box, an add box and
         twenty-five rows into a tile sized for a name and a count. */ ?>
<div class="files-overlay" id="list-overlay" hidden>
    <div class="files-box lo-box" role="dialog" aria-modal="true" aria-labelledby="lo-title">
        <div class="files-head">
            <h3 id="lo-title"></h3>
            <button type="button" class="files-close" id="lo-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="files-body" id="lo-body"></div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($ioLists['may_use'])): ?>
<?php /* "Put this in a list" — the picker. A checkbox per list, because a torrent can be in
         several, and a name box at the bottom so a new list can be made without leaving the
         torrent that prompted it. */ ?>
<div class="files-overlay" id="lp-overlay" hidden>
    <div class="files-box lp-box" role="dialog" aria-modal="true" aria-labelledby="lp-title">
        <div class="files-head">
            <h3 id="lp-title"><?= _h('lists.pick_title') ?></h3>
            <button type="button" class="files-close" id="lp-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="files-body">
            <?php /* ONE box, at the top, doing both jobs. It filters the lists while somebody types,
                     and when what they have typed is not the name of a list they already have, the
                     button beside it offers to make one — which is the same keystrokes either way,
                     and no decision to make before starting to type. */ ?>
            <div class="lp-find">
                <input type="text" class="profile-search" id="lp-new-name" maxlength="80" placeholder="<?= _h('lists.find_or_new_ph') ?>" autocomplete="off">
                <button type="button" class="btn btn-small" id="lp-new-go" hidden><?= _h('lists.new') ?></button>
            </div>
            <div id="lp-body"></div>
            <div class="trans-pagination lp-pager" id="lp-pager"></div>
            <div class="lp-msg text-muted" id="lp-msg"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (pmEnabled($cfg) || friendsEnabled($cfg)): ?>
<?php /* Blocking asks two questions at once, because they are two decisions: stop the messages,
         and (if they want it) disappear from that person's view of the site. */ ?>
<div class="files-overlay" id="block-overlay" hidden>
    <div class="files-box bk-box" role="dialog" aria-modal="true" aria-labelledby="bk-title">
        <div class="files-head">
            <h3 id="bk-title"><?= _h('people.block_title') ?> <span class="text-muted" id="bk-who"></span></h3>
            <button type="button" class="files-close" id="bk-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="files-body">
            <p class="text-muted"><?= __('people.block_body') ?></p>
            <label class="search-check acc-check"><input type="checkbox" id="bk-hide"><span class="search-check-box" aria-hidden="true"></span>
                <span><?= _h('people.block_hide_label') ?></span></label>
            <p class="text-muted acc-verify-note"><?= __('people.block_hide_hint') ?></p>
            <button type="button" class="btn btn-small" id="bk-go"><?= _h('people.block_go') ?></button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($ioWho): ?>
<?php /* The fourth instance of this shell (search results, the file list, the Info panel, this).
         Its pager and its search box are its own; the list inside is usernames, and every one of
         them is somebody who said yes to being here — see api/hash_favourites.php. */ ?>
<div class="files-overlay" id="who-overlay" hidden>
    <div class="files-box" role="dialog" aria-modal="true" aria-labelledby="who-title">
        <div class="files-head">
            <h3 id="who-title"><?= _h('js.fav.who') ?> <span class="text-muted" id="who-total"></span></h3>
            <button type="button" class="files-close" id="who-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="files-body">
            <input type="text" class="profile-search" id="who-search" maxlength="60" placeholder="<?= _h('profile.search_ph') ?>" autocomplete="off">
            <div id="who-body"></div>
            <div class="trans-pagination" id="who-pager"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php /* The editor the Info panel clones when a reader may add or propose a description (1.53.0):
         the whitelist form's rail, buttons and preview, with ids under `info-desc` so
         window.RichText.mount() finds its parts. Present only while descriptions or source links are
         switched on at all; whether THIS reader may write about THIS hash is the endpoint's answer
         (api/index_info.php), and the panel draws the button only when it says so. */ ?>
<?php if (function_exists('contentEnabled') && contentEnabled($cfg)): ?>
<?php $ioFormats = function_exists('richtextFormats') ? richtextFormats($cfg) : ['bbcode']; ?>
<template id="info-desc-tpl">
<div class="info-desc-editor" id="info-desc-editor">
<?php if (($cfg['wl_allow_source_url'] ?? '0') === '1'): ?>
    <div class="form-group">
        <label for="info-desc-source"><?= _h('search.desc_source') ?></label>
        <input type="url" id="info-desc-source" maxlength="500" placeholder="https://example.org/torrents/12345">
    </div>
<?php endif; ?>
<?php if (($cfg['wl_allow_description'] ?? '0') === '1'): ?>
    <div class="form-group">
        <label for="info-desc"><?= _h('whitelist.desc') ?></label>
        <div class="rt-editor">
            <div class="rt-tabs">
                <button type="button" class="rt-tab active" data-rt="write"><?= _h('whitelist.write') ?></button>
                <button type="button" class="rt-tab" data-rt="preview"><?= _h('whitelist.preview') ?></button>
                <span class="rt-counter" id="info-desc-count"></span>
                <?php if (count($ioFormats) > 1): ?>
                <select id="info-desc-format" class="rt-format" title="<?= _h('whitelist.format_title') ?>">
                    <option value="bbcode">BBCode</option>
                    <option value="markdown">Markdown</option>
                </select>
                <?php else: ?>
                <input type="hidden" id="info-desc-format" value="<?= sanitize($ioFormats[0]) ?>">
                <span class="rt-format-fixed"><?= $ioFormats[0] === 'markdown' ? 'Markdown' : 'BBCode' ?></span>
                <?php endif; ?>
            </div>
            <div class="rt-tools" id="info-desc-tools" role="toolbar" aria-label="<?= _h('rt.toolbar') ?>">
                <span class="rt-tool-group">
                    <button type="button" data-md="bold" title="<?= _h('rt.bold') ?>"><i class="bi bi-type-bold" aria-hidden="true"></i></button>
                    <button type="button" data-md="italic" title="<?= _h('rt.italic') ?>"><i class="bi bi-type-italic" aria-hidden="true"></i></button>
                    <button type="button" data-md="underline" title="<?= _h('rt.underline') ?>"><i class="bi bi-type-underline" aria-hidden="true"></i></button>
                    <button type="button" data-md="strike" title="<?= _h('rt.strike') ?>"><i class="bi bi-type-strikethrough" aria-hidden="true"></i></button>
                </span>
                <span class="rt-tool-group">
                    <button type="button" data-md="color" title="<?= _h('rt.color') ?>"><i class="bi bi-palette" aria-hidden="true"></i></button>
                    <button type="button" data-md="size" title="<?= _h('rt.size') ?>"><i class="bi bi-fonts" aria-hidden="true"></i></button>
                    <button type="button" data-md="highlight" title="<?= _h('rt.highlight') ?>"><i class="bi bi-highlighter" aria-hidden="true"></i></button>
                    <button type="button" data-md="sub" title="<?= _h('rt.sub') ?>"><i class="bi bi-subscript" aria-hidden="true"></i></button>
                    <button type="button" data-md="sup" title="<?= _h('rt.sup') ?>"><i class="bi bi-superscript" aria-hidden="true"></i></button>
                </span>
                <span class="rt-tool-group">
                    <button type="button" data-md="link" title="<?= _h('rt.link') ?>"><i class="bi bi-link-45deg" aria-hidden="true"></i></button>
                    <button type="button" data-md="image" title="<?= _h('rt.image') ?>"><i class="bi bi-image" aria-hidden="true"></i></button>
                    <button type="button" data-md="list" title="<?= _h('rt.list') ?>"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
                    <button type="button" data-md="olist" title="<?= _h('rt.olist') ?>"><i class="bi bi-list-ol" aria-hidden="true"></i></button>
                </span>
                <span class="rt-tool-group">
                    <button type="button" data-md="quote" title="<?= _h('rt.quote') ?>"><i class="bi bi-quote" aria-hidden="true"></i></button>
                    <button type="button" data-md="code" title="<?= _h('rt.code') ?>"><i class="bi bi-code-slash" aria-hidden="true"></i></button>
                    <button type="button" data-md="table" title="<?= _h('rt.table') ?>"><i class="bi bi-table" aria-hidden="true"></i></button>
                    <button type="button" data-md="spoiler" title="<?= _h('rt.spoiler') ?>"><i class="bi bi-eye-slash" aria-hidden="true"></i></button>
                    <button type="button" data-md="center" title="<?= _h('rt.center') ?>"><i class="bi bi-text-center" aria-hidden="true"></i></button>
                    <button type="button" data-md="hr" title="<?= _h('rt.hr') ?>"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
                </span>
            </div>
            <textarea id="info-desc" rows="6" maxlength="<?= (int)richtextMaxChars($cfg) ?>" placeholder="<?= _h('whitelist.desc_ph') ?>"></textarea>
            <div class="rt-preview rt-body" id="info-desc-preview" hidden></div>
        </div>
        <details class="rt-syntax-fold">
    <summary><i class="bi bi-chevron-right disc-chev" aria-hidden="true"></i><?= _h('rt.syntax_help') ?></summary>
    <div class="form-hint" id="info-desc-syntax"></div>
</details>
        <div class="form-hint" id="info-desc-help"></div>
    </div>
<?php endif; ?>
<?php if (($cfg['wl_content_review'] ?? '1') === '1' && ($cfg['wl_content_autopublish'] ?? '0') !== '1'): ?>
    <p class="form-hint"><?= __('whitelist.review_note') ?></p>
<?php endif; ?>
    <div class="info-desc-foot">
        <button type="button" class="btn btn-small" id="info-desc-send"><?= _h('search.desc_send') ?></button>
        <button type="button" class="btn btn-secondary btn-small" id="info-desc-cancel"><?= _h('common.cancel') ?></button>
        <span class="text-muted info-desc-msg" id="info-desc-msg" aria-live="polite"></span>
    </div>
</div>
</template>
<?php endif; ?>
