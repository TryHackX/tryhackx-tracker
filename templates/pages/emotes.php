<?php
/**
 * ?action=emotes — the codes, the pictures behind them, and who put them there.
 *
 * ── why a page and not a panel section ─────────────────────────────────────────────────────────
 * The picker in the composer shows the images; it cannot show the CODES without becoming a table,
 * and `:flame:` typed by hand has to work for somebody who is writing on a phone with the picker
 * closed. So the list lives at an address, one tile per emote, and the picker links to it.
 *
 * ── one shape of "no" ──────────────────────────────────────────────────────────────────────────
 * The shoutbox switched off, emotes switched off and this reader not being allowed to read the
 * shoutbox render the SAME page — the shape templates/pages/shoutbox.php and profile.php use. A
 * visitor poking at the address learns nothing about which of the three it was.
 *
 * ── the lists are the SERVER's ─────────────────────────────────────────────────────────────────
 * Drawn here rather than fetched by the script, because they are public, identical for every reader
 * and already in the database the page is answering from. assets/js/shoutbox.js adds only what
 * cannot be server-rendered: the file somebody is about to upload, the row they are about to take
 * away, and the click that copies a code.
 *
 * ── what 1.59.1 changed ────────────────────────────────────────────────────────────────────────
 * The tiles are a real grid of equal cells, and the picture sits on a faintly inset panel of its
 * own: a white PNG and a dark SVG are both uploaded here, and against any single flat background
 * one of the two disappears. The `:code:` under it is a click-to-copy chip — the whole reason
 * somebody opens this page is to get a code into a shout, and selecting eight characters by hand on
 * a phone is not a way to do that.
 *
 * Needs $db, $cfg, $baseUrl, $csrfToken — index.php gives every page template all four.
 */
$emInc = __DIR__ . '/../../includes/shout.php';
if (is_file($emInc)) require_once $emInc;

$emOn = function_exists('shoutEmotesEnabled')
    ? shoutEmotesEnabled($cfg) : ((string)($cfg['shout_emotes_enabled'] ?? '1') === '1');
$emHere = function_exists('shoutEnabled') && shoutEnabled($cfg) && $emOn && shoutMayView($db, $cfg);

if (!$emHere):
?>
<h1><?= _h('shout.emotes_h1') ?></h1>
<p><?= _h('shout.not_found') ?></p>
<p><a class="btn btn-secondary" href="<?= $baseUrl ?>"><?= _h('common.back_home') ?></a></p>
<?php
    return;
endif;

$emStickersOn = function_exists('shoutStickersEnabled')
    ? shoutStickersEnabled($cfg) : ((string)($cfg['shout_stickers_enabled'] ?? '1') === '1');
// Whether a member's upload waits for somebody before the room can see it (v65). Only the sentence
// under the upload box asks this now: whether one of this reader's own cards is waiting is a fact of
// the ROW (`approved_at`), not of the setting, so an operator switching the gate off afterwards does
// not quietly turn somebody's unanswered upload into an ordinary switched-off one.
$emApproval = function_exists('shoutEmoteApproval')
    ? shoutEmoteApproval($cfg) : ((string)($cfg['shout_emote_approval'] ?? '1') === '1');
// …and whether THIS reader is one of the few the gate does not apply to (1.61.0). The sentence under
// the upload box says "what you add waits for a moderator", and saying that to somebody holding
// `shout.emote_auto` would be telling them the opposite of what is about to happen.
$emAuto = userCan($db, $cfg, 'shout.emote_auto');

// The reader, and whether they are one of the few who may add to this.
$emMe    = usersEnabled($cfg) ? currentUser($db) : null;
$emMeId  = (int)($emMe['id'] ?? 0);
$emMayUp = $emMeId > 0 && userCan($db, $cfg, 'shout.upload_emote');

// Everything enabled, for the lists; everything at all when the reader has uploads of their own,
// because one of theirs may be switched off or still waiting, and a row that has vanished from a
// page without a word is the thing that makes somebody upload it a second time.
$emAll  = function_exists('shoutEmotes') ? shoutEmotes($db, $cfg, !$emMayUp) : [];
$emMine = [];
$emList = [];
$emSticks = [];
foreach ($emAll as $e) {
    $enabled = !isset($e['enabled']) || (int)$e['enabled'] === 1;
    if ($emMayUp && (int)($e['uploaded_by'] ?? 0) === $emMeId && $emMeId > 0) $emMine[] = $e;
    if (!$enabled) continue;
    // With stickers switched off a sticker is not gone — shoutRenderEmotes() still replaces its
    // token, just inline like any other emote. So it is listed with the others rather than hidden:
    // a code that works and is nowhere on the page is a code nobody can find out about.
    if (!empty($e['is_sticker']) && $emStickersOn) $emSticks[] = $e;
    else $emList[] = $e;
}

/**
 * The address of one emote's bytes. The helper where there is one, its documented shape where not.
 * Raw, with a bare `&`: every caller puts it through sanitize(), which is what makes the attribute
 * legal — and doing it twice would turn the query string into text.
 */
$emUrl = function (array $e) use ($baseUrl): string {
    if (function_exists('shoutEmoteUrl')) return shoutEmoteUrl($e, $baseUrl);
    return $baseUrl . 'api.php?endpoint=shout_emote&id=' . (int)($e['id'] ?? 0);
};

// Who uploaded what, asked once per PERSON rather than once per row: a set of emotes is very often
// one enthusiast's afternoon, and that would otherwise be twenty queries for one name. The row that
// question already reads is the whole account, so the picture beside the name (1.63.0) comes with it:
// kept here as the drawn element, and no second question is asked for it.
$emNames = [];
$emPics = [];
$emWho = function (array $e) use ($db, $cfg, $baseUrl, &$emNames, &$emPics): string {
    $uid = (int)($e['uploaded_by'] ?? 0);
    if ($uid <= 0) return '';
    if (!array_key_exists($uid, $emNames)) {
        $u = function_exists('userFindById') ? userFindById($db, $uid) : null;
        $emNames[$uid] = (string)($u['username'] ?? '');
        $emPics[$uid] = ($u && function_exists('userAvatarHtml')) ? userAvatarHtml($u, 20, $baseUrl, 'avatar emote-av', $cfg) : '';
    }
    return $emNames[$uid];
};
$emPic = function (array $e) use (&$emPics): string { return $emPics[(int)($e['uploaded_by'] ?? 0)] ?? ''; };

/**
 * The `:code:` under a picture, as a chip somebody can click.
 *
 * A <button> and not a <span>: it does something when pressed, so it is reachable by Tab and answers
 * to Enter without this file inventing either. assets/js/shoutbox.js is delegated from the grid and
 * swaps the label for "Copied" the way the short hash on a profile's torrent list does.
 */
$emChip = function (string $code) : void {
    ?><button type="button" class="emote-copy" data-emote-copy=":<?= sanitize($code) ?>:"
              title="<?= _h('shout.emote_copy_title') ?>">:<?= sanitize($code) ?>:</button><?php
};

/** One tile: the picture on its panel, the code to copy, the name, and where it came from. */
$emCard = function (array $e, bool $sticker) use ($baseUrl, $emUrl, $emWho, $emPic, $emChip): void {
    $code = (string)($e['code'] ?? '');
    $name = (string)($e['name'] ?? $code);
    $who  = $emWho($e);
    ?>
    <div class="emote-card" data-id="<?= (int)($e['id'] ?? 0) ?>" data-code="<?= sanitize($code) ?>">
        <div class="emote-shot"><img class="<?= $sticker ? 'shout-sticker' : 'shout-emote' ?>" src="<?= sanitize($emUrl($e)) ?>"
             alt="<?= sanitize(':' . $code . ':') ?>" title="<?= sanitize($name) ?>" loading="lazy"></div>
        <?php $emChip($code); ?>
        <span class="emote-name"><?= sanitize($name) ?></span>
        <?php if ($who !== ''): ?>
        <span class="emote-by text-muted"><?= __('shout.emote_by', ['name' =>
            '<a class="av-who" href="' . $baseUrl . '?action=u&amp;name=' . urlencode($who) . '">' . $emPic($e) . sanitize($who) . '</a>']) ?></span>
        <?php else: ?>
        <span class="emote-by text-muted"><?= _h('shout.emote_shipped') ?></span>
        <?php endif; ?>
    </div>
    <?php
};

$emMaxKb = max(8, min(512, (int)($cfg['shout_emote_max_kb'] ?? 64) ?: 64));
$emMaxPx = max(32, min(512, (int)($cfg['shout_emote_max_px'] ?? 128) ?: 128));
$emPer   = max(1, min(200, (int)($cfg['shout_emote_per_user'] ?? 20) ?: 20));
?>
<h1><?= _h('shout.emotes_h1') ?></h1>
<p class="emote-intro"><?= __('shout.emotes_intro') ?></p>
<?php /* The script posts as this reader, and a public page carries its own token — the same hidden
         input the widget does, under the id assets/js/shoutbox.js looks for first. */ ?>
<input type="hidden" id="shout-csrf" value="<?= $csrfToken ?>">

<div id="emotes-page" class="emotes-page">

    <h2 class="section-heading-spaced"><?= _h('shout.emotes_head') ?> <span class="text-muted emote-count">(<?= count($emList) ?>)</span></h2>
    <div class="emote-grid" id="emote-list">
        <?php if (!$emList): ?>
        <p class="emote-empty text-muted"><?= _h('shout.emotes_none') ?></p>
        <?php endif; ?>
        <?php foreach ($emList as $e) $emCard($e, false); ?>
    </div>

    <?php if ($emStickersOn): ?>
    <h2 class="section-heading-spaced"><?= _h('shout.stickers_head') ?> <span class="text-muted emote-count">(<?= count($emSticks) ?>)</span></h2>
    <p class="form-hint"><?= _h('shout.stickers_hint') ?></p>
    <div class="emote-grid emote-grid-big" id="emote-stickers">
        <?php if (!$emSticks): ?>
        <p class="emote-empty text-muted"><?= _h('shout.emotes_none') ?></p>
        <?php endif; ?>
        <?php foreach ($emSticks as $e) $emCard($e, true); ?>
    </div>
    <?php endif; ?>

    <?php if ($emMayUp): ?>
    <h2 class="section-heading-spaced"><?= _h('shout.emote_add_head') ?></h2>
    <div class="emote-upload" id="emote-upload" data-max-kb="<?= $emMaxKb ?>" data-max-px="<?= $emMaxPx ?>"
         data-per-user="<?= $emPer ?>" data-approval="<?= $emApproval ? '1' : '0' ?>">
        <?php /* The same zone the panel's uploads use, in the public palette: the real file input
                 is kept in the DOM — it is what opens the picker and what carries the file — and a
                 label-shaped box is laid over it, rather than a button that fakes one. Full width,
                 with the two boxes side by side underneath and the rest on one line below them. */ ?>
        <div class="emote-drop" id="emote-drop" tabindex="0" role="button" aria-label="<?= _h('shout.emote_drop_aria') ?>">
            <i class="bi bi-file-earmark-image emote-drop-icon" aria-hidden="true"></i>
            <span class="emote-drop-main"><u><?= _h('shout.emote_drop_choose') ?></u> <?= _h('shout.emote_drop_or') ?></span>
            <span class="emote-drop-sub"><?= __('shout.emote_drop_sub', ['kb' => $emMaxKb, 'px' => $emMaxPx]) ?></span>
            <input type="file" id="emote-file" class="emote-drop-input" accept=".svg,.png,.gif,.webp,image/svg+xml,image/png,image/gif,image/webp">
        </div>
        <div class="emote-fields">
            <div class="form-group">
                <label for="emote-code"><?= _h('shout.emote_code') ?></label>
                <?php /* `flame` and not `fire`: `fire` is one of richtextEmoji()'s built-in shortcodes,
                         so an emote could never be stored under it — an example nobody can follow is
                         worse than no example. `flame` is one the tracker actually ships. */ ?>
                <input type="text" id="emote-code" maxlength="32" autocomplete="off" spellcheck="false" placeholder="flame">
                <div class="form-hint"><?= _h('shout.emote_code_hint') ?></div>
            </div>
            <div class="form-group">
                <label for="emote-name"><?= _h('shout.emote_name') ?></label>
                <input type="text" id="emote-name" maxlength="60" autocomplete="off" placeholder="<?= _h('shout.emote_name_ph') ?>">
                <div class="form-hint"><?= _h('shout.emote_name_hint') ?></div>
            </div>
        </div>
        <?php /* The sticker box and the button on one line, with the answer beside them. */ ?>
        <div class="emote-acts">
            <?php if ($emStickersOn): ?>
            <label class="search-check emote-stick-check"><input type="checkbox" id="emote-sticker"><span class="search-check-box" aria-hidden="true"></span>
                <span><?= _h('shout.emote_is_sticker') ?></span></label>
            <?php endif; ?>
            <button type="button" class="btn btn-small" id="emote-send"><?= _h('shout.emote_upload') ?></button>
            <span class="emote-note" id="emote-note"></span>
        </div>
        <p class="form-hint emote-limits"><?= __('shout.emote_limits', ['kb' => $emMaxKb, 'px' => $emMaxPx, 'n' => $emPer]) ?><?php
            if ($emApproval && !$emAuto): ?> <?= _h('shout.emote_approval_note') ?><?php
            elseif ($emApproval): ?> <?= _h('shout.emote_approval_skip') ?><?php endif; ?></p>
    </div>

    <h2 class="section-heading-spaced"><?= _h('shout.emote_mine_head') ?> <span class="text-muted emote-count">(<?= count($emMine) ?>/<?= $emPer ?>)</span></h2>
    <div class="emote-grid" id="emote-mine">
        <?php if (!$emMine): ?>
        <p class="emote-empty text-muted"><?= _h('shout.emote_mine_none') ?></p>
        <?php endif; ?>
        <?php foreach ($emMine as $e): ?>
        <?php $emOff = isset($e['enabled']) && (int)$e['enabled'] !== 1;
              // Waiting means nobody has answered yet (`approved_at` NULL) — never "switched off".
              // A moderator who let this one through and later took it down HAS answered, and
              // telling its uploader they are still in a queue would be a lie about that answer.
              $emWait = !empty($e['waiting']); ?>
        <div class="emote-card" data-id="<?= (int)($e['id'] ?? 0) ?>" data-code="<?= sanitize((string)($e['code'] ?? '')) ?>">
            <div class="emote-shot"><img class="<?= !empty($e['is_sticker']) ? 'shout-sticker' : 'shout-emote' ?>" src="<?= sanitize($emUrl($e)) ?>"
                 alt="<?= sanitize(':' . (string)($e['code'] ?? '') . ':') ?>" loading="lazy"></div>
            <?php $emChip((string)($e['code'] ?? '')); ?>
            <span class="emote-name"><?= sanitize((string)($e['name'] ?? '')) ?></span>
            <?php /* Two different facts, and the reader is owed the difference: one of these is a
                     queue they are in, the other is a decision somebody made about them. */ ?>
            <?php if ($emWait): ?>
            <span class="emote-wait" title="<?= _h('shout.emote_waiting_hint') ?>"><?= _h('shout.emote_waiting') ?></span>
            <?php elseif ($emOff): ?>
            <span class="emote-off"><?= _h('shout.emote_disabled') ?></span>
            <?php endif; ?>
            <button type="button" class="emote-del" data-id="<?= (int)($e['id'] ?? 0) ?>"><?= _h('shout.delete') ?></button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php /* Through the helper, never the literal: with `shout_placement` on the home block there is
             nothing at ?action=shoutbox, so this button — on the page a reader was sent to in order
             to find the codes — used to land them on "there is no shoutbox here". shoutNavUrl() has
             known where the box really is since 1.60.0, and from 1.61.0 it also knows what the
             operator renamed the address to. */ ?>
    <p class="emote-back"><a class="btn btn-secondary btn-small" href="<?= sanitize(shoutNavUrl($cfg, $baseUrl)) ?>"><?= _h('shout.open_page') ?></a></p>
</div>
