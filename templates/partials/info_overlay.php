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
     data-fav="<?= !empty($ioFav['may_use']) ? '1' : '0' ?>"
     data-fav-who="<?= $ioWho ? '1' : '0' ?>"
     data-lists="<?= !empty($ioLists['may_use']) ? '1' : '0' ?>">
    <div class="files-box info-box" role="dialog" aria-modal="true" aria-labelledby="info-title">
        <div class="files-head">
            <h3 id="info-title"><?= _h('search.details') ?></h3>
            <span class="info-acts" id="info-acts"></span>
<?php if ($ioShare): ?>
            <button type="button" class="search-share info-share" id="info-share"
                    title="<?= _h('search.share_one_title') ?>"><?= _h('search.share') ?></button>
<?php endif; ?>
            <button type="button" class="files-close" id="info-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>">&times;</button>
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
            <button type="button" class="files-close" id="lo-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>">&times;</button>
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
            <button type="button" class="files-close" id="lp-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>">&times;</button>
        </div>
        <div class="files-body">
            <div id="lp-body"></div>
            <div class="lp-new">
                <input type="text" class="profile-search" id="lp-new-name" maxlength="80" placeholder="<?= _h('lists.new_ph') ?>" autocomplete="off">
                <button type="button" class="btn btn-small" id="lp-new-go"><?= _h('lists.new') ?></button>
            </div>
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
            <button type="button" class="files-close" id="bk-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>">&times;</button>
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
            <button type="button" class="files-close" id="who-close" title="<?= _h('common.close') ?>" aria-label="<?= _h('common.close') ?>">&times;</button>
        </div>
        <div class="files-body">
            <input type="text" class="profile-search" id="who-search" maxlength="60" placeholder="<?= _h('profile.search_ph') ?>" autocomplete="off">
            <div id="who-body"></div>
            <div class="trans-pagination" id="who-pager"></div>
        </div>
    </div>
</div>
<?php endif; ?>
