<?php
/**
 * The shoutbox: the last few lines people said, and the box to say another one in.
 *
 * ── one partial, two places ────────────────────────────────────────────────────────────────────
 * It is drawn as a block on the front page and as a page of its own, and those differ in exactly
 * one thing — how many rows they start with. So this file is included twice with a different
 * `$shoutLimit` rather than written twice; a second copy is a second set of ids for the one script
 * that drives them, and the ids are the contract between them.
 *
 * ── everything the script needs is already here ────────────────────────────────────────────────
 * The first draw carries the starting point (`data-newest`), the cadence (`data-live`), the limit
 * (`data-max`), the default syntax (`data-format`) and what this reader may do (`data-may-post`,
 * `data-may-moderate`). The script therefore never has to ask the server a question the render has
 * already answered, and — the reason this matters — it never has to REDRAW: it appends rows newer
 * than `data-newest` and leaves the half-written sentence in the box below alone. That is the
 * lesson of 1.48 and 1.50, written into the markup rather than remembered.
 *
 * ── who may write is decided here, once ────────────────────────────────────────────────────────
 * shoutMayPost() answers it, and the answer is rendered as either a composer or one sentence saying
 * why there is none. A composer that posts a 403 is a broken box; an empty space where a box would
 * be teaches nobody anything.
 *
 * Callers set, before including: $shoutLimit (rows), $shoutOnPage (true on ?action=shoutbox).
 * Needs $db, $cfg, $baseUrl in scope — includes/homeblocks.php and the page template both have them.
 */

// The backend half may not be installed yet, and a front page that fatals because one feature is
// mid-landing is worse than a front page without it.
if (!function_exists('shoutMayView') || !shoutMayView($db, $cfg)) return;

$shoutLimit  = isset($shoutLimit) ? max(1, min(500, (int)$shoutLimit)) : shoutWidgetRows($cfg);
$shoutOnPage = !empty($shoutOnPage);
$shoutFmt    = shoutFormat($cfg);
$shoutMax    = shoutMaxChars($cfg);
$shoutLive   = shoutLiveSeconds($cfg);
$shoutMod    = userCan($db, $cfg, 'shout.moderate');
// A page template has $csrfToken; homeBlocks() is a function and does not, so ask the session
// directly — it is the same token either way (see generateCsrfToken()).
$shoutCsrf   = $csrfToken ?? generateCsrfToken();

$shoutMe = usersEnabled($cfg) ? currentUser($db) : null;
$shoutMeRow = is_array($shoutMe) ? $shoutMe : [];
if (!isset($shoutMeRow['id'])) $shoutMeRow['id'] = 0;    // a guest who was granted shout.view

// One row MORE than is drawn. It is the whole of "is there anything older?", and it costs a row
// rather than a second query — which is what an extra COUNT over this table would be.
$shoutList = shoutRows($db, $cfg, $shoutMeRow, $shoutLimit + 1);
$shoutMore = count($shoutList) > $shoutLimit;
if ($shoutMore) array_shift($shoutList);                 // rows arrive newest LAST: the spare is the oldest
$shoutNewest = $shoutList ? (int)$shoutList[count($shoutList) - 1]['id'] : shoutNewestId($db);
$shoutOldest = $shoutList ? (int)$shoutList[0]['id'] : 0;

$shoutPost = shoutMayPost($db, $cfg, $shoutMe);
$shoutWhy  = '';
if (empty($shoutPost['ok'])) {
    $reason = (string)($shoutPost['reason'] ?? 'no_permission');
    if (!in_array($reason, ['disabled', 'login', 'no_permission', 'muted'], true)) $reason = 'no_permission';
    $shoutWhy = $reason === 'muted'
        ? __('shout.closed_muted', ['until' => (string)($shoutPost['until'] ?? '')])
        : __('shout.closed_' . $reason);
}
$shoutRules = shoutRules($cfg);
$shoutBoth  = shoutPlacement($cfg) === 'both';

// The two F2 switches. Through the helpers where they exist and from the setting where they do not,
// so this template draws the same either side of the emote half landing: an install that has never
// seen the setting behaves like one that has it on, which is what the shipped default says.
$shoutEmotesOn   = function_exists('shoutEmotesEnabled')
    ? shoutEmotesEnabled($cfg) : ((string)($cfg['shout_emotes_enabled'] ?? '1') === '1');
$shoutStickersOn = $shoutEmotesOn && (function_exists('shoutStickersEnabled')
    ? shoutStickersEnabled($cfg) : ((string)($cfg['shout_stickers_enabled'] ?? '1') === '1'));
?>
<div id="shoutbox" class="shoutbox<?= $shoutOnPage ? ' shoutbox-page' : '' ?>"
     data-newest="<?= $shoutNewest ?>"
     data-oldest="<?= $shoutOldest ?>"
     data-live="<?= $shoutLive ?>"
     data-max="<?= $shoutMax ?>"
     data-format="<?= sanitize($shoutFmt) ?>"
     data-may-post="<?= empty($shoutPost['ok']) ? '0' : '1' ?>"
     data-may-moderate="<?= $shoutMod ? '1' : '0' ?>"
     data-me="<?= (int)$shoutMeRow['id'] ?>"
     data-closed="<?= sanitize($shoutWhy) ?>"
     data-emotes="<?= $shoutEmotesOn ? '1' : '0' ?>"
     data-stickers="<?= $shoutStickersOn ? '1' : '0' ?>"
     data-page="<?= $shoutOnPage ? '1' : '0' ?>">
    <input type="hidden" id="shout-csrf" value="<?= $shoutCsrf ?>">
    <div class="shout-head">
        <?php /* Above the list, not inside it: prepending older rows into the list would otherwise
                 have to step over this button every time, and the button would scroll away just as
                 somebody reached the place they need it. */ ?>
        <button type="button" class="btn btn-secondary btn-small shout-older" id="shout-older"<?= $shoutMore ? '' : ' hidden' ?>><?= _h('shout.older') ?></button>
        <?php if ($shoutBoth && !$shoutOnPage): ?>
        <a class="shout-open" href="<?= $baseUrl ?>?action=shoutbox"><?= _h('shout.open_page') ?></a>
        <?php endif; ?>
        <?php /* The way to the emotes page for somebody who has no composer: a reader with
                 shout.view and nothing else never opens the picker, and the codes are no use to
                 them if there is nowhere that lists them. */ ?>
        <?php if ($shoutEmotesOn): ?>
        <a class="shout-emotes-link<?= ($shoutBoth && !$shoutOnPage) ? '' : ' shout-emotes-link-end' ?>" href="<?= $baseUrl ?>?action=emotes"><?= _h('shout.emotes_link') ?></a>
        <?php endif; ?>
    </div>
    <div class="shout-list" id="shout-list">
        <?php if (!$shoutList): ?>
        <div class="shout-empty text-muted"><?= _h('shout.empty') ?></div>
        <?php endif; ?>
        <?php foreach ($shoutList as $s): ?>
        <?php /* The body is HTML the SERVER rendered, through the same richtextRender() every
                 description and message goes through. There is exactly one place in this codebase
                 that decides what may be displayed, and it is not this template. */ ?>
        <div class="shout-row<?= !empty($s['own']) ? ' shout-row-own' : '' ?><?= !empty($s['mentions_me']) ? ' shout-row-mention' : '' ?>"
             data-id="<?= (int)$s['id'] ?>" data-user="<?= sanitize((string)($s['user'] ?? '')) ?>">
            <a class="shout-who" href="<?= $baseUrl ?>?action=u&amp;name=<?= urlencode((string)($s['user'] ?? '')) ?>"><?= sanitize((string)($s['user'] ?? '')) ?></a>
            <span class="shout-time" title="<?= sanitize((string)($s['at'] ?? '')) ?>"><?= sanitize(substr((string)($s['at'] ?? ''), 11, 5)) ?></span>
            <span class="shout-body rt-body"><?= $s['html'] ?? '' ?></span>
            <?php if (!empty($s['deletable'])): ?>
            <button type="button" class="shout-del" title="<?= _h('shout.delete_title') ?>" aria-label="<?= _h('shout.delete') ?>">&times;</button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($shoutRules !== ''): ?>
    <p class="shout-rules text-muted"><?= sanitize($shoutRules) ?></p>
    <?php endif; ?>

    <?php if (empty($shoutPost['ok'])): ?>
    <div class="shout-closed"><?= sanitize($shoutWhy) ?></div>
    <?php else: ?>
    <div class="shout-compose">
        <?php if ($shoutFmt === 'plain'): ?>
        <?php /* No formatting means no formatting: no tabs, no rail, no preview and no syntax to
                 explain. A composer that offers a Preview tab for text that is escaped verbatim is
                 a composer telling a lie about what will happen. */ ?>
        <textarea id="shout-body" class="pm-input shout-input" rows="2" maxlength="<?= $shoutMax ?>" placeholder="<?= _h('shout.write_ph') ?>"></textarea>
        <?php else: ?>
        <div class="rt-editor pm-editor shout-editor">
            <div class="rt-tabs">
                <button type="button" class="rt-tab active" data-rt="write"><?= _h('whitelist.write') ?></button>
                <button type="button" class="rt-tab" data-rt="preview"><?= _h('whitelist.preview') ?></button>
                <?php /* `shout_format` is the DEFAULT, not the law: one line is sometimes BBCode and
                         sometimes Markdown, and the server validates whichever was chosen. */ ?>
                <select id="shout-body-format" class="rt-format" title="<?= _h('whitelist.format_title') ?>">
                    <option value="bbcode"<?= $shoutFmt === 'bbcode' ? ' selected' : '' ?>>BBCode</option>
                    <option value="markdown"<?= $shoutFmt === 'markdown' ? ' selected' : '' ?>>Markdown</option>
                </select>
            </div>
            <textarea id="shout-body" class="pm-input shout-input" rows="2" maxlength="<?= $shoutMax ?>" placeholder="<?= _h('shout.write_ph') ?>"></textarea>
            <div class="rt-preview rt-body" id="shout-body-preview" hidden></div>
        </div>
        <?php /* Folded, like the message composer's: the wall of brackets is needed once, by the
                 person who goes looking for it. */ ?>
        <details class="rt-syntax-fold">
            <summary><?= _h('rt.syntax_help') ?></summary>
            <div class="form-hint" id="shout-body-syntax"></div>
        </details>
        <div class="form-hint" id="shout-body-help"></div>
        <?php endif; ?>
        <div class="shout-acts">
            <?php /* The picker's handle. One button, whatever the tracker has: the four pages of
                     Unicode characters are drawn by the device's own emoji font and need nothing
                     from the server, and the emotes and stickers appear beside them when there are
                     any. The glyph is written as an entity so this file stays ASCII — the picker's
                     own contents live in assets/js/shoutbox.js. */ ?>
            <button type="button" class="shout-emoji-btn" id="shout-emoji" aria-haspopup="dialog" aria-expanded="false"
                    title="<?= _h('shout.emoji_title') ?>" aria-label="<?= _h('shout.emoji_title') ?>">&#128512;</button>
            <button type="button" class="btn btn-small" id="shout-send"><?= _h('shout.send') ?></button>
            <span class="shout-count text-muted" id="shout-count"></span>
            <span class="shout-note" id="shout-note"></span>
        </div>
        <p class="shout-hint text-muted"><?= _h('shout.enter_hint') ?></p>
    </div>
    <?php endif; ?>
</div>
