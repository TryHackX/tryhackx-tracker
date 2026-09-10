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
$ioFav    = $favCtx ?? favContext($db, $cfg, currentUser($db));
$ioWho    = !empty($ioFav['who_ok']) && !empty($ioFav['may_view']);
$ioShare  = ($cfg['search_share_enabled'] ?? '1') === '1';
?>
<!-- Info panel: what this torrent is, where it came from, and how the swarm looks. The file list
     lives at the bottom of it; the "N files" chip beside a search result opens the tree on its own. -->
<div class="files-overlay" id="info-overlay" hidden
     data-files-mode="<?= sanitize(indexFilesMode($cfg)) ?>"
     data-fav="<?= !empty($ioFav['may_use']) ? '1' : '0' ?>"
     data-fav-who="<?= $ioWho ? '1' : '0' ?>">
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
