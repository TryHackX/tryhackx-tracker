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
$shoutMod    = userCan($db, $cfg, 'shout.moderate');
// A page template has $csrfToken; homeBlocks() is a function and does not, so ask the session
// directly — it is the same token either way (see generateCsrfToken()).
$shoutCsrf   = $csrfToken ?? generateCsrfToken();

$shoutMe = usersEnabled($cfg) ? currentUser($db) : null;
$shoutMeRow = is_array($shoutMe) ? $shoutMe : [];
if (!isset($shoutMeRow['id'])) $shoutMeRow['id'] = 0;    // a guest who was granted shout.view

// The cadence THIS reader is on (1.60.0). A guest reads and never writes, and on a public tracker
// there are far more of them than there are members, so they have a number of their own — which may
// be 0, meaning "do not poll at all, read what the page was drawn with".
$shoutLive   = shoutLiveSecondsFor($cfg, (int)$shoutMeRow['id'] <= 0);

/**
 * The name a line is signed with: a link to the profile, or plain text when nobody wrote it.
 *
 * A line the SITE said (1.60.0) has no profile to open, and a row whose account has since been
 * deleted is the same case — `user_id` 0 is how shoutShape() says so. renderRow() in
 * assets/js/shoutbox.js builds exactly this, and the two being identical is the contract that lets
 * the list be appended to instead of redrawn.
 */
$shoutWho = function (array $s) use ($baseUrl): string {
    $name = (string)($s['user'] ?? '');
    // `title` because the name column is a fixed width from 1.59.1: a name longer than it is cut
    // with an ellipsis rather than pushing the words out of line, and a cut name with no way to
    // read the whole of it would be the worse of the two.
    $attrs = 'class="shout-who" title="' . sanitize($name) . '"';
    return (int)($s['user_id'] ?? 0) > 0
        ? '<a ' . $attrs . ' href="' . $baseUrl . '?action=u&amp;name=' . urlencode($name) . '">' . sanitize($name) . '</a>'
        : '<span ' . $attrs . '>' . sanitize($name) . '</span>';
};

// One row MORE than is drawn. It is the whole of "is there anything older?", and it costs a row
// rather than a second query — which is what an extra COUNT over this table would be.
$shoutList = shoutRows($db, $cfg, $shoutMeRow, $shoutLimit + 1);
$shoutMore = count($shoutList) > $shoutLimit;
if ($shoutMore) array_shift($shoutList);                 // rows arrive newest LAST: the spare is the oldest
$shoutNewest = $shoutList ? (int)$shoutList[count($shoutList) - 1]['id'] : shoutNewestId($db);
$shoutOldest = $shoutList ? (int)$shoutList[0]['id'] : 0;
// The one pinned announcement, or null. Fetched with the first draw and never by the poll — see
// shoutPinned(), and api/shout_list.php, which carries it on every answer except the append one.
$shoutPin = shoutPinned($db, $cfg, $shoutMeRow);

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

/**
 * The two controls that live INSIDE the text field (1.61.0), built once and drawn in whichever box
 * this reader gets.
 *
 * With `shout_format` on 'plain' there is no editor around the textarea, so the markup below has two
 * branches — and two copies of these would be two elements carrying one id each, which is the kind
 * of thing that works until somebody switches a setting.
 *
 * PINNED TO THE TOP RIGHT OF THE FIELD, emoji first and then Send, small and quiet. Nothing loose
 * is left in a row under the box: what used to be down there was a button, a picker handle, a count
 * and a sentence about the Enter key, which is four things competing to be the thing you look at
 * after writing one line. They are semi-transparent until the pointer or the keyboard reaches them,
 * and assets/js/shoutbox.js keeps the field's right padding equal to their real width — measured
 * rather than guessed, because "Send" and "Wyślij" are not the same number of pixels and a phone is
 * not a desktop.
 *
 * Both are real buttons with a title and a label, so Tab still reaches them; Enter in the field
 * still sends and Shift+Enter still starts a line.
 */
$shoutInActs = '<div class="shout-in-acts">'
    . '<button type="button" class="shout-emoji-btn" id="shout-emoji" aria-haspopup="dialog"'
    . ' aria-expanded="false" title="' . _h('shout.emoji_title') . '"'
    . ' aria-label="' . _h('shout.emoji_title') . '">&#128512;</button>'
    . '<button type="button" class="shout-send-btn" id="shout-send" title="' . _h('shout.send') . '">'
    . _h('shout.send') . '</button>'
    . '</div>';

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
        <a class="shout-open" href="<?= sanitize(shoutNavUrl($cfg, $baseUrl)) ?>"><?= _h('shout.open_page') ?></a>
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
        <?php /* A bare glyph from the icon font the page already carries (Bootstrap Icons, pulled in
                 by templates/layout.php for the pages that draw a box). No circle and no border: it
                 acts on the whole block and sits at the end of a row of words, and a ring around it
                 made it the loudest thing in the head. It darkens on hover and spins while it waits,
                 and there is nothing else to it. */ ?>
        <button type="button" class="shout-refresh" id="shout-refresh"
                title="<?= _h('shout.refresh') ?>" aria-label="<?= _h('shout.refresh') ?>">
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
        </button>
    </div>
    <?php /* ── the pinned line ──────────────────────────────────────────────────────────────────
             One announcement, above the list rather than inside it: the rows the poll appends never
             have to step over it, and it cannot scroll away just as somebody needs it. At most one
             row is ever pinned — shoutPin() keeps that true in a transaction — which is why this is
             a strip and not a second list.

             The element is always drawn and carries `hidden` when there is nothing pinned, because
             assets/js/shoutbox.js refills this same node after a moderator pins something. One
             shape, whichever side built it, so one stylesheet rule describes both. */ ?>
    <div class="shout-pinned" id="shout-pinned"<?= $shoutPin ? ' data-id="' . (int)$shoutPin['id'] . '"' : ' hidden' ?>>
        <?php if ($shoutPin): ?>
        <span class="shout-pin-icon" aria-hidden="true">&#128204;</span>
        <?= $shoutWho($shoutPin) ?>
        <span class="shout-body rt-body"><?= $shoutPin['html'] ?? '' ?></span>
        <?php if ($shoutMod): ?>
        <button type="button" class="shout-unpin" title="<?= _h('shout.unpin_title') ?>" aria-label="<?= _h('shout.unpin') ?>">&times;</button>
        <?php endif; ?>
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
        <div class="shout-row<?= !empty($s['own']) ? ' shout-row-own' : '' ?><?= !empty($s['mentions_me']) ? ' shout-row-mention' : '' ?><?= !empty($s['system']) ? ' shout-row-system' : '' ?>"
             data-id="<?= (int)$s['id'] ?>" data-user="<?= sanitize((string)($s['user'] ?? '')) ?>">
            <?= $shoutWho($s) ?>
            <span class="shout-time" title="<?= sanitize((string)($s['at'] ?? '')) ?>"><?= sanitize(substr((string)($s['at'] ?? ''), 11, 5)) ?></span>
            <span class="shout-body rt-body"><?= $s['html'] ?? '' ?></span>
            <?php /* Pinning is `shout.moderate`, which the box already knows about — so the button
                     is drawn from that and needs nothing per row. Hidden until the line is hovered,
                     like the delete cross beside it. */ ?>
            <?php if ($shoutMod): ?>
            <button type="button" class="shout-pin" title="<?= _h('shout.pin_title') ?>" aria-label="<?= _h('shout.pin') ?>">&#128204;</button>
            <?php endif; ?>
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
        <div class="shout-input-wrap">
            <textarea id="shout-body" class="pm-input shout-input" rows="2" maxlength="<?= $shoutMax ?>" placeholder="<?= _h('shout.write_ph') ?>"></textarea>
            <?= $shoutInActs ?>
        </div>
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
                <?php /* The count belongs on THIS line, at its right-hand end (1.61.0): it is a fact
                         about what is in the box, and under the box it was a third row of small grey
                         text arguing with the status line beside it for the same corner. */ ?>
                <span class="shout-count text-muted" id="shout-count"></span>
            </div>
            <div class="shout-input-wrap">
                <textarea id="shout-body" class="pm-input shout-input" rows="2" maxlength="<?= $shoutMax ?>" placeholder="<?= _h('shout.write_ph') ?>"></textarea>
                <?= $shoutInActs ?>
            </div>
            <div class="rt-preview rt-body" id="shout-body-preview" hidden></div>
        </div>
        <?php endif; ?>
        <?php /* ── what is left under the box ───────────────────────────────────────────────────
                 The folded syntax help, and nothing else that can be pressed. Send and the picker
                 handle are inside the field now, so there is no row of loose controls down here for
                 the eye to sort through after writing one line.

                 The fold's label carries the Enter/Shift+Enter sentence in brackets. It used to have
                 a line of its own, which is a whole row of the block spent on one fact about the
                 keyboard that somebody needs exactly once. */ ?>
        <div class="shout-acts">
            <?php if ($shoutFmt !== 'plain'): ?>
            <details class="rt-syntax-fold">
                <summary><?= _h('shout.syntax_help') ?></summary>
                <div class="form-hint" id="shout-body-syntax"></div>
            </details>
            <?php else: ?>
            <?php /* With formatting off there is no fold to hang it on, so the sentence keeps a place
                     of its own — it is the only thing left that says what Enter does. The count comes
                     with it, because there is no tabs row up there to hold it either. */ ?>
            <span class="shout-hint text-muted"><?= _h('shout.enter_hint') ?></span>
            <span class="shout-count text-muted" id="shout-count"></span>
            <?php endif; ?>
            <?php /* Kept: this is where a refusal from the server lands (flood, too long, muted).
                     What left it in 1.61.0 is "nothing new", which is an answer to pressing the
                     refresh button and now appears on that button as a tooltip. */ ?>
            <span class="shout-note" id="shout-note"></span>
        </div>
        <?php if ($shoutFmt !== 'plain'): ?>
        <div class="form-hint" id="shout-body-help"></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
