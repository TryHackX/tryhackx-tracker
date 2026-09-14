<?php
/**
 * ?action=emotes — the codes, the pictures behind them, and who put them there.
 *
 * ── why a page and not a panel section ─────────────────────────────────────────────────────────
 * The picker in the composer shows the images; it cannot show the CODES without becoming a table,
 * and `:fire:` typed by hand has to work for somebody who is writing on a phone with the picker
 * closed. So the list lives at an address, one line per emote, and the picker links to it.
 *
 * ── one shape of "no" ──────────────────────────────────────────────────────────────────────────
 * The shoutbox switched off, emotes switched off and this reader not being allowed to read the
 * shoutbox render the SAME page — the shape templates/pages/shoutbox.php and profile.php use. A
 * visitor poking at the address learns nothing about which of the three it was.
 *
 * ── the lists are the SERVER's ─────────────────────────────────────────────────────────────────
 * Drawn here rather than fetched by the script, because they are public, identical for every reader
 * and already in the database the page is answering from. assets/js/shoutbox.js adds only what
 * cannot be server-rendered: the file somebody is about to upload, and the row they are about to
 * take away.
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

// The reader, and whether they are one of the few who may add to this.
$emMe    = usersEnabled($cfg) ? currentUser($db) : null;
$emMeId  = (int)($emMe['id'] ?? 0);
$emMayUp = $emMeId > 0 && userCan($db, $cfg, 'shout.upload_emote');

// Everything enabled, for the lists; everything at all when the reader has uploads of their own,
// because one of theirs may be switched off and a row that has vanished from a page without a word
// is the thing that makes somebody upload it a second time.
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
// one enthusiast's afternoon, and that would otherwise be twenty queries for one name.
$emNames = [];
$emWho = function (array $e) use ($db, &$emNames): string {
    $uid = (int)($e['uploaded_by'] ?? 0);
    if ($uid <= 0) return '';
    if (!array_key_exists($uid, $emNames)) {
        $u = function_exists('userFindById') ? userFindById($db, $uid) : null;
        $emNames[$uid] = (string)($u['username'] ?? '');
    }
    return $emNames[$uid];
};

/** One card: the picture at the size it will be used at, its code, its name and where it came from. */
$emCard = function (array $e, bool $sticker) use ($baseUrl, $emUrl, $emWho): void {
    $code = (string)($e['code'] ?? '');
    $name = (string)($e['name'] ?? $code);
    $who  = $emWho($e);
    ?>
    <div class="emote-card" data-id="<?= (int)($e['id'] ?? 0) ?>" data-code="<?= sanitize($code) ?>">
        <div class="emote-shot"><img class="<?= $sticker ? 'shout-sticker' : 'shout-emote' ?>" src="<?= sanitize($emUrl($e)) ?>"
             alt="<?= sanitize(':' . $code . ':') ?>" title="<?= sanitize($name) ?>" loading="lazy"></div>
        <code class="emote-code">:<?= sanitize($code) ?>:</code>
        <span class="emote-name"><?= sanitize($name) ?></span>
        <?php if ($who !== ''): ?>
        <span class="emote-by text-muted"><?= __('shout.emote_by', ['name' =>
            '<a href="' . $baseUrl . '?action=u&amp;name=' . urlencode($who) . '">' . sanitize($who) . '</a>']) ?></span>
        <?php else: ?>
        <span class="emote-by text-muted"><?= _h('shout.emote_shipped') ?></span>
        <?php endif; ?>
        <?php if (isset($e['enabled']) && (int)$e['enabled'] !== 1): ?>
        <span class="emote-off"><?= _h('shout.emote_disabled') ?></span>
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
    <div class="emote-upload" id="emote-upload" data-max-kb="<?= $emMaxKb ?>" data-max-px="<?= $emMaxPx ?>" data-per-user="<?= $emPer ?>">
        <?php /* The same zone the panel's uploads use, in the public palette: the real file input
                 is kept in the DOM — it is what opens the picker and what carries the file — and a
                 label-shaped box is laid over it, rather than a button that fakes one. */ ?>
        <div class="emote-drop" id="emote-drop" tabindex="0" role="button" aria-label="<?= _h('shout.emote_drop_aria') ?>">
            <i class="bi bi-file-earmark-image emote-drop-icon" aria-hidden="true"></i>
            <span class="emote-drop-main"><u><?= _h('shout.emote_drop_choose') ?></u> <?= _h('shout.emote_drop_or') ?></span>
            <span class="emote-drop-sub"><?= __('shout.emote_drop_sub', ['kb' => $emMaxKb, 'px' => $emMaxPx]) ?></span>
            <input type="file" id="emote-file" class="emote-drop-input" accept=".svg,.png,.gif,.webp,image/svg+xml,image/png,image/gif,image/webp">
        </div>
        <div class="emote-fields">
            <div class="form-group">
                <label for="emote-code"><?= _h('shout.emote_code') ?></label>
                <input type="text" id="emote-code" maxlength="32" autocomplete="off" spellcheck="false" placeholder="fire">
                <div class="form-hint"><?= _h('shout.emote_code_hint') ?></div>
            </div>
            <div class="form-group">
                <label for="emote-name"><?= _h('shout.emote_name') ?></label>
                <input type="text" id="emote-name" maxlength="60" autocomplete="off" placeholder="<?= _h('shout.emote_name_ph') ?>">
                <div class="form-hint"><?= _h('shout.emote_name_hint') ?></div>
            </div>
        </div>
        <?php if ($emStickersOn): ?>
        <div class="form-group">
            <label class="search-check acc-check"><input type="checkbox" id="emote-sticker"><span class="search-check-box" aria-hidden="true"></span>
                <span><?= _h('shout.emote_is_sticker') ?></span></label>
            <div class="form-hint"><?= _h('shout.emote_is_sticker_hint') ?></div>
        </div>
        <?php endif; ?>
        <div class="emote-acts">
            <button type="button" class="btn btn-small" id="emote-send"><?= _h('shout.emote_upload') ?></button>
            <span class="emote-note" id="emote-note"></span>
        </div>
        <p class="form-hint"><?= __('shout.emote_limits', ['kb' => $emMaxKb, 'px' => $emMaxPx, 'n' => $emPer]) ?></p>
    </div>

    <h2 class="section-heading-spaced"><?= _h('shout.emote_mine_head') ?> <span class="text-muted emote-count">(<?= count($emMine) ?>/<?= $emPer ?>)</span></h2>
    <div class="emote-grid" id="emote-mine">
        <?php if (!$emMine): ?>
        <p class="emote-empty text-muted"><?= _h('shout.emote_mine_none') ?></p>
        <?php endif; ?>
        <?php foreach ($emMine as $e): ?>
        <div class="emote-card" data-id="<?= (int)($e['id'] ?? 0) ?>" data-code="<?= sanitize((string)($e['code'] ?? '')) ?>">
            <div class="emote-shot"><img class="<?= !empty($e['is_sticker']) ? 'shout-sticker' : 'shout-emote' ?>" src="<?= sanitize($emUrl($e)) ?>"
                 alt="<?= sanitize(':' . (string)($e['code'] ?? '') . ':') ?>" loading="lazy"></div>
            <code class="emote-code">:<?= sanitize((string)($e['code'] ?? '')) ?>:</code>
            <span class="emote-name"><?= sanitize((string)($e['name'] ?? '')) ?></span>
            <?php if (isset($e['enabled']) && (int)$e['enabled'] !== 1): ?>
            <span class="emote-off"><?= _h('shout.emote_disabled') ?></span>
            <?php endif; ?>
            <button type="button" class="emote-del" data-id="<?= (int)($e['id'] ?? 0) ?>"><?= _h('shout.delete') ?></button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="emote-back"><a class="btn btn-secondary btn-small" href="<?= $baseUrl ?>?action=shoutbox"><?= _h('shout.open_page') ?></a></p>
</div>
