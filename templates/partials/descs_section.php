<?php
/**
 * The table of descriptions a member wrote (1.70.0, includes/profiledescs.php): its toolbar, its header
 * and its pager.
 *
 * One partial for the account page's tab and a profile's section, as the likes table has
 * (templates/partials/votes_section.php): server-rendered labels (the in-place language switch pairs them
 * by id), rows and pager drawn by assets/js/favourites.js (initDescs()) from api/user_descriptions.php.
 * The torrent's name with a line of what the description says under it, the member's part in it — its
 * author, or a co-author with the share of the text their edits changed — the date, and Magnet / Info.
 *
 * Expects, from the including page: $cfg, $db, and
 *   $pdUser  — the profile's name, or '' for the reader's own list (the account page);
 *   $pdSelf  — whether it is the reader's own list;
 *   $pdExtra — the cluster's extra announce URLs, as the page's other lists carry them.
 */
// A header cell: the likes table's (its words, a no-break space, then the arrow, so the arrow never
// sits alone on a line). Every icon is `class="bi bi-…"` from its first character.
$pdHead = static fn(string $id, string $col, string $sort, string $label, string $title = '', bool $on = false): string =>
    '<th id="' . $id . '" data-col="' . $col . '" aria-sort="' . ($on ? 'descending' : 'none') . '">'
    . '<button type="button" class="pv-sort" data-sort="' . $sort . '"' . ($title !== '' ? ' title="' . sanitize($title) . '"' : '') . '>'
    . sanitize($label) . '&nbsp;'
    . ($on ? '<i class="bi bi-arrow-down search-sort-icon active" aria-hidden="true"></i>'
           : '<i class="bi bi-arrow-down-up search-sort-icon" aria-hidden="true"></i>')
    . '</button></th>';
?>
<div class="pv-section pd-section" id="descs-section"
     data-user="<?= sanitize((string)$pdUser) ?>"
     data-self="<?= $pdSelf ? '1' : '0' ?>"
     data-magnet="<?= userCan($db, $cfg, 'index.magnet') ? '1' : '0' ?>"
     data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>"
     data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>"
     data-announce-extra="<?= sanitize(implode(' ', (array)($pdExtra ?? []))) ?>">
    <div class="profile-toolbar pv-toolbar">
        <input type="text" class="profile-search" id="pd-search" maxlength="<?= (int)PROFILE_DESCS_SEARCH_MAX ?>" placeholder="<?= _h('profile.search_ph') ?>" autocomplete="off">
        <span class="profile-total" id="pd-total"></span>
    </div>
    <div class="pf-empty pv-msg" id="pd-msg"><?= _h('common.loading') ?></div>
    <?php /* A table on a wide screen, a card per row on a narrow one — the likes table's rules
             (.pv-table in assets/css/style.css) with columns of its own (.pd-table). */ ?>
    <div class="pv-wrap" id="pd-wrap" hidden>
        <table class="transparency-table pv-table pd-table" id="pd-table">
            <colgroup>
                <col class="pd-c-name"><col class="pd-c-role"><col class="pd-c-date"><col class="pd-c-acts">
            </colgroup>
            <thead><tr>
                <?= $pdHead('pd-h-name', 'name', 'name', __('search.col_name')) ?>
                <?= $pdHead('pd-h-role', 'role', 'role', __('descs.col_role'), __('descs.col_role_title')) ?>
                <?= $pdHead('pd-h-date', 'date', 'date', __('descs.col_date'), __('descs.col_date_title'), true) ?>
                <th id="pd-h-acts" data-col="acts"><span class="pv-sr"><?= _h('votes.col_actions') ?></span></th>
            </tr></thead>
            <tbody id="pd-body"></tbody>
        </table>
    </div>
    <div class="trans-pagination" id="pd-pager"></div>
</div>
