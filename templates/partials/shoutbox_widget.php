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
    <?php /* ── the head: one line, in reading order ──────────────────────────────────────────────
             What it is, then the two places to go from here, then the two buttons — and the refresh
             on the right, where a control that acts on the whole block belongs. It used to be
             "Older" first with the links pushed to the far edge by a margin, which at any width
             narrower than the box read as three things that had nothing to do with each other.

             The title is drawn HERE only on ?action=shoutbox, where this template owns the page. In
             the home block the heading above the widget is the operator's own (Settings → Home
             layout), printed by templates/pages/home.php, and a second copy of it inside the box
             would be the same word twice. */ ?>
    <div class="shout-head">
        <?php if ($shoutOnPage): ?>
        <h1 class="shout-title"><?= _h('shout.h1') ?></h1>
        <?php endif; ?>
        <?php /* The way to the emotes page for somebody who has no composer: a reader with
                 shout.view and nothing else never opens the picker, and the codes are no use to
                 them if there is nowhere that lists them. */ ?>
        <?php if ($shoutEmotesOn): ?>
        <a class="shout-emotes-link" href="<?= $baseUrl ?>?action=emotes"><?= _h('shout.emotes_link') ?></a>
        <?php endif; ?>
        <?php if ($shoutBoth && !$shoutOnPage): ?>
        <a class="shout-open" href="<?= $baseUrl ?>?action=shoutbox"><?= _h('shout.open_page') ?></a>
        <?php endif; ?>
        <?php /* Above the list, not inside it: prepending older rows into the list would otherwise
                 have to step over this button every time, and the button would scroll away just as
                 somebody reached the place they need it. */ ?>
        <button type="button" class="btn btn-secondary btn-small shout-older" id="shout-older"<?= $shoutMore ? '' : ' hidden' ?>><?= _h('shout.older') ?></button>
        <?php /* "Is there anything I have not seen?", asked on demand. The poll answers that every
                 few seconds, but only while the tab is in front and the box is on the screen — so a
                 reader coming back to a window that has been behind another one has no way to ask
                 except reloading the page, which throws away whatever they were typing. It runs the
                 SAME fetch the poll runs (assets/js/shoutbox.js, window.Shout.refresh). */ ?>
        <button type="button" class="shout-refresh" id="shout-refresh"
                title="<?= _h('shout.refresh') ?>" aria-label="<?= _h('shout.refresh') ?>">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/>
            </svg>
        </button>
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
            <?php /* `title` because the name column is a fixed width from 1.59.1: a name longer than
                     it is cut with an ellipsis rather than pushing the words out of line, and a cut
                     name with no way to read the whole of it would be the worse of the two. */ ?>
            <a class="shout-who" title="<?= sanitize((string)($s['user'] ?? '')) ?>" href="<?= $baseUrl ?>?action=u&amp;name=<?= urlencode((string)($s['user'] ?? '')) ?>"><?= sanitize((string)($s['user'] ?? '')) ?></a>
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
                <?php /* The formatting rail the description and message editors have, on the SAME
                         line as the select rather than on a row of its own: a shout is two lines
                         tall and a toolbar above it would be half the composer.

                         Wired by assets/js/app.js purely by convention around the textarea's id —
                         `shout-body-tools`, buttons carrying `data-md`, groups in `.rt-tool-group` —
                         so there is no second implementation of the insert here, and a button whose
                         syntax the chosen format cannot express hides itself when the select moves.
                         [u] is the one of these BBCode has and Markdown does not, which is what
                         makes the swap visible rather than a claim in a comment.

                         A short set on purpose: the eight marks somebody reaches for mid-sentence.
                         Colour, size, tables and images are for a description, and the person who
                         wants them is not writing a one-liner. */ ?>
                <div class="rt-tools shout-tools" id="shout-body-tools" role="toolbar" aria-label="<?= _h('rt.toolbar') ?>">
                    <span class="rt-tool-group">
                        <button type="button" data-md="bold" title="<?= _h('rt.bold') ?>"><strong>B</strong></button>
                        <button type="button" data-md="italic" title="<?= _h('rt.italic') ?>"><em>I</em></button>
                        <button type="button" data-md="underline" title="<?= _h('rt.underline') ?>"><u>U</u></button>
                        <button type="button" data-md="strike" title="<?= _h('rt.strike') ?>"><s>S</s></button>
                    </span>
                    <span class="rt-tool-group">
                        <button type="button" data-md="code" title="<?= _h('rt.code') ?>">&lt;/&gt;</button>
                        <button type="button" data-md="link" title="<?= _h('rt.link') ?>">&#128279;</button>
                        <button type="button" data-md="quote" title="<?= _h('rt.quote') ?>">&rdquo;</button>
                        <button type="button" data-md="spoiler" title="<?= _h('rt.spoiler') ?>">&#128065;</button>
                    </span>
                </div>
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
