<?php
/**
 * The likes / ratings table (1.69.0, includes/profilevotes.php): its toolbar, its header and its pager.
 *
 * One partial for the account page's tab and a profile's section, so the two cannot drift apart — the
 * same shell the favourites sections are: server-rendered labels (the in-place language switch pairs
 * them by id), rows and pager drawn by assets/js/favourites.js from api/user_votes.php. Whichever mode
 * the rating system is in decides the whole vocabulary: thumbs are "likes", filtered by the member's
 * own vote and by how many votes a torrent has; stars are "ratings", filtered by a range of the
 * member's own rating and a range of the overall average, both in half-star steps.
 *
 * Expects, from the including page: $cfg, $db, $baseUrl, $mayFileSearch, and
 *   $pvUser  — the profile's name, or '' for the reader's own list (the account page);
 *   $pvSelf  — whether it is the reader's own list ("your" labels) or somebody else's ("their");
 *   $pvExtra — the cluster's extra announce URLs, as the page's other lists carry them.
 */
$pvMode  = repMode($cfg);
$pvStars = $pvMode === 'stars';
$pvWho   = $pvSelf ? 'self' : 'other';
$pvOwnLabel = __('votes.col_own_' . $pvMode . '_' . $pvWho);
// A header cell: its words, then the search table's arrow, joined by a no-break space so a header that
// has to wrap wraps between its WORDS and the arrow stays with the last one — never alone on a line.
// The two arrows are written out whole: every icon is `class="bi bi-…"` from its first character, which
// is what the Font Awesome filter (and tests/icons_test.php) reads.
$pvHead = static fn(string $id, string $col, string $sort, string $label, string $title = '', bool $on = false): string =>
    '<th id="' . $id . '" data-col="' . $col . '" aria-sort="' . ($on ? 'descending' : 'none') . '">'
    . '<button type="button" class="pv-sort" data-sort="' . $sort . '"' . ($title !== '' ? ' title="' . sanitize($title) . '"' : '') . '>'
    . sanitize($label) . '&nbsp;'
    . ($on ? '<i class="bi bi-arrow-down search-sort-icon active" aria-hidden="true"></i>'
           : '<i class="bi bi-arrow-down-up search-sort-icon" aria-hidden="true"></i>')
    . '</button></th>';
?>
<div class="pv-section" id="votes-section"
     data-user="<?= sanitize((string)$pvUser) ?>"
     data-self="<?= $pvSelf ? '1' : '0' ?>"
     data-mode="<?= sanitize($pvMode) ?>"
     data-min="<?= (int)repMinVotes($cfg) ?>"
     data-magnet="<?= userCan($db, $cfg, 'index.magnet') ? '1' : '0' ?>"
     data-announce="<?= sanitize($cfg['announce_url'] ?? '') ?>"
     data-announce-https="<?= sanitize($cfg['announce_url_https'] ?? '') ?>"
     data-announce-extra="<?= sanitize(implode(' ', (array)($pvExtra ?? []))) ?>">
    <?php /* Two rows: the favourites toolbar's own (the search, the file-name box, the count), then this
             list's filters — on one row they squeezed the search box down to half its placeholder. */ ?>
    <div class="profile-toolbar pv-toolbar">
        <input type="text" class="profile-search" id="pv-search" maxlength="<?= (int)PROFILE_VOTES_SEARCH_MAX ?>" placeholder="<?= _h('profile.search_ph') ?>" autocomplete="off">
        <?php if (!empty($mayFileSearch)): ?>
        <label class="search-check" title="<?= _h('search.files_title') ?>"><input type="checkbox" id="pv-files" checked><span class="search-check-box" aria-hidden="true"></span> <?= _h('search.files') ?></label>
        <?php endif; ?>
        <span class="profile-total" id="pv-total"></span>
    </div>
    <div class="profile-toolbar pv-filters" id="pv-filters">
        <?php if ($pvStars): ?>
        <?php /* Two ranges, each "from – to" in half stars: the member's own rating (½ to 5, the values a
                 rating can take) and everybody's average (0 to 5, a number that can be anything between). */ ?>
        <span class="pv-range" id="pv-own-range">
            <label class="pv-range-label" for="pv-own-min"><?= sanitize($pvOwnLabel) ?></label>
            <select id="pv-own-min" title="<?= _h('votes.range_from') ?>">
                <?php foreach (profileVotesStarSteps(1) as $pvV => $pvL): ?>
                <option value="<?= (int)$pvV ?>"<?= $pvV === 1 ? ' selected' : '' ?>><?= sanitize($pvL) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="pv-range-to" for="pv-own-max"><?= _h('votes.range_to') ?></label>
            <select id="pv-own-max" title="<?= _h('votes.range_to_title') ?>">
                <?php foreach (profileVotesStarSteps(1) as $pvV => $pvL): ?>
                <option value="<?= (int)$pvV ?>"<?= $pvV === 10 ? ' selected' : '' ?>><?= sanitize($pvL) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="pv-range" id="pv-avg-range">
            <label class="pv-range-label" for="pv-avg-min" title="<?= _h('votes.avg_title') ?>"><?= _h('votes.avg') ?></label>
            <select id="pv-avg-min" title="<?= _h('votes.range_from') ?>">
                <?php foreach (profileVotesStarSteps(0) as $pvV => $pvL): ?>
                <option value="<?= (int)$pvV * 50 ?>"<?= $pvV === 0 ? ' selected' : '' ?>><?= sanitize($pvL) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="pv-range-to" for="pv-avg-max"><?= _h('votes.range_to') ?></label>
            <select id="pv-avg-max" title="<?= _h('votes.range_to_title') ?>">
                <?php foreach (profileVotesStarSteps(0) as $pvV => $pvL): ?>
                <option value="<?= (int)$pvV * 50 ?>"<?= $pvV === 10 ? ' selected' : '' ?>><?= sanitize($pvL) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <?php else: ?>
        <span class="pv-range" id="pv-vote-range">
            <label class="pv-range-label" for="pv-vote"><?= sanitize($pvOwnLabel) ?></label>
            <select id="pv-vote">
                <option value="all" selected><?= _h('votes.vote_all') ?></option>
                <option value="up"><?= _h('votes.vote_up') ?></option>
                <option value="down"><?= _h('votes.vote_down') ?></option>
            </select>
        </span>
        <span class="pv-range" id="pv-count-range">
            <label class="pv-range-label" for="pv-min-votes" title="<?= _h('votes.min_votes_title') ?>"><?= _h('votes.min_votes') ?></label>
            <input type="number" class="profile-search pv-number" id="pv-min-votes" min="0" max="<?= (int)PROFILE_VOTES_MIN_VOTES_MAX ?>" step="1" value="0" inputmode="numeric" title="<?= _h('votes.min_votes_title') ?>">
        </span>
        <?php endif; ?>
    </div>
    <div class="pf-empty pv-msg" id="pv-msg"><?= _h('common.loading') ?></div>
    <?php /* A table on a wide screen, a card per row on a narrow one (the header becomes a row of sort
             buttons there) — see .pv-table in assets/css/style.css. The sort controls are BUTTONS inside
             the header cells, so the keyboard reaches them; the arrows are the search table's own. */ ?>
    <div class="pv-wrap" id="pv-wrap" hidden>
        <table class="transparency-table pv-table pv-mode-<?= sanitize($pvMode) ?>" id="pv-table">
            <colgroup>
                <col class="pv-c-name"><col class="pv-c-size"><col class="pv-c-sl"><col class="pv-c-own"><col class="pv-c-score"><col class="pv-c-votes"><col class="pv-c-date"><col class="pv-c-acts">
            </colgroup>
            <thead><tr>
                <?= $pvHead('pv-h-name', 'name', 'name', __('search.col_name')) ?>
                <?= $pvHead('pv-h-size', 'size', 'size', __('search.col_size')) ?>
                <?= $pvHead('pv-h-sl', 'sl', 'seeders', __('search.col_sl'), __('search.col_sl_title')) ?>
                <?= $pvHead('pv-h-own', 'own', 'own', $pvOwnLabel) ?>
                <?= $pvHead('pv-h-score', 'score', 'score', __('votes.col_score_' . $pvMode), __('votes.col_score_' . $pvMode . '_title')) ?>
                <?= $pvHead('pv-h-votes', 'votes', 'votes', __('votes.col_votes'), __('votes.col_votes_title')) ?>
                <?= $pvHead('pv-h-date', 'date', 'date', __('votes.col_date_' . $pvMode), __('votes.col_date_title'), true) ?>
                <th id="pv-h-acts" data-col="acts"><span class="pv-sr"><?= _h('votes.col_actions') ?></span></th>
            </tr></thead>
            <tbody id="pv-body"></tbody>
        </table>
    </div>
    <div class="trans-pagination" id="pv-pager"></div>
</div>
