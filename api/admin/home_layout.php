<?php
/**
 * GET/POST — the home page's section order, visibility and headings.
 *
 * GET   the catalogue, the current layout, and for each section whether its own feature switch is
 *       currently letting it render at all.
 * POST  {"op": "save"|"reset", "order": [...], "hidden": [...], "headings": {...}, "tagline": "..."}
 *
 * Not in the permission map, so this is owner-only, like Settings itself: the home page is what
 * every visitor sees first, and rearranging it is not a moderator's decision.
 *
 * `home_layout` is written HERE and nowhere else — it is deliberately absent from the
 * save_settings.php allow-list, so a settings form that has never heard of it cannot blank it on
 * the next unrelated save.
 */

require_once __DIR__ . '/../../includes/homelayout.php';
require_once __DIR__ . '/../../includes/pagecontent.php';

$catalog = homeSectionCatalog();

/**
 * Is this section's own feature switch currently letting it render?
 *
 * The point of reporting this is to keep two different kinds of "not on the page" apart. Dragging a
 * section into the layout cannot switch its feature on, and an editor that did not say so would
 * leave an operator moving a block around wondering why nothing changed.
 */
function homeSectionAvailable(array $cfg, PDO $db, string $key): array {
    switch ($key) {
        case 'stats':
            if (($cfg['tracker_stats_enabled'] ?? '0') !== '1') {
                return [false, __('api.pages.why_stats_off')];
            }
            if (($cfg['tracker_stats_show_home'] ?? '1') !== '1') {
                return [false, __('api.pages.why_stats_home_off')];
            }
            if (!userCan($db, $cfg, 'home.stats')) {
                return [false, __('api.pages.why_stats_no_permission')];
            }
            return [true, ''];
        case 'announce':
            if (empty($cfg['announce_url']) && empty($cfg['announce_url_https'])) {
                return [false, __('api.pages.why_no_announce')];
            }
            return [true, ''];
        case 'donations':
            if (($cfg['donations_enabled'] ?? '0') !== '1') {
                return [false, __('api.pages.why_donations_off')];
            }
            $f = json_decode((string)($cfg['donation_fields'] ?? '[]'), true);
            $legacy = !empty($cfg['wallet_xmr']) || !empty($cfg['wallet_btc']) || !empty($cfg['wallet_eth']);
            if ((!is_array($f) || !$f) && !$legacy) {
                return [false, __('api.pages.why_donations_empty')];
            }
            return [true, ''];
        case 'contact':
            if (($cfg['contact_visible'] ?? '1') !== '1') {
                return [false, __('api.pages.why_contact_off')];
            }
            return [true, ''];
    }
    return [true, ''];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $layout = homeLayout($cfg);
    // What text exists for each section, per language: the row shows a dot for it.
    $content = pageContentAll($db);
    $sections = [];
    foreach ($layout['order'] as $key) {
        $isCustom = homeSectionIsCustom($key);
        $meta = $isCustom
            ? ['label' => homeSectionLabel($cfg, $key), 'about' => __('api.pages.custom_about'),
               'fixed' => false, 'heading' => 'custom']
            : $catalog[$key];
        [$live, $why] = $isCustom ? [true, ''] : homeSectionAvailable($cfg, $db, $key);
        $rows = $content['home:' . $key] ?? [];
        $sections[] = [
            'key'      => $key,
            'label'    => $meta['label'],
            'about'    => $meta['about'],
            'fixed'    => !empty($meta['fixed']),
            'is_custom' => $isCustom,
            'hidden'   => in_array($key, $layout['hidden'], true),
            // The RENDERED built-in wording, not its key; a custom section's heading defaults to its label.
            'heading'  => $isCustom ? $meta['label'] : ($meta['heading'] === null ? null : homeHeadingDefault($key)),
            'value'    => $layout['headings'][$key] ?? ($isCustom ? $meta['label'] : ($meta['heading'] === null ? '' : homeHeadingDefault($key))),
            'custom'   => isset($layout['headings'][$key]),
            'live'     => $live,
            'why'      => $why,
            // 'live' when any language has a published version, 'draft' when something is stored,
            // 'none' otherwise — the same three states the Site pages card shows.
            'content'  => $rows ? (count(array_filter($rows, fn($r) => $r['enabled'])) ? 'live' : 'draft') : 'none',
            'content_langs' => array_keys($rows),
        ];
    }
    jsonResponse([
        'success'    => true,
        'sections'   => $sections,
        'tagline'    => homeTagline($cfg),
        'tagline_default' => homeTaglineDefault(),
        'is_default' => homeLayoutIsDefault($cfg),
        'max'        => HOME_HEADING_MAX,
        'custom_max' => HOME_CUSTOM_MAX,
        'custom'     => $layout['custom'],
        'note'       => __('api.pages.layout_note'),
    ]);
}

requirePost();
$input = readJsonBody();
$op = strtolower(trim((string)($input['op'] ?? 'save')));

if ($op === 'reset') {
    setSetting($db, 'home_layout', '');
    // A full reset: every section's own text goes too — the dialog says so before asking.
    try { $db->prepare("DELETE FROM page_content WHERE page LIKE 'home:%'")->execute(); } catch (\Throwable $e) {}
    auditNote(['summary' => 'restored the built-in home page layout and removed every custom section text']);
    jsonResponse(['success' => true, 'message' => __('api.pages.layout_reset')]);
}
if ($op !== 'save') jsonResponse(['error' => __('api.pages.unknown_op_save_reset')], 400);

$order    = is_array($input['order'] ?? null) ? $input['order'] : [];
$hidden   = is_array($input['hidden'] ?? null) ? $input['hidden'] : [];
$headings = is_array($input['headings'] ?? null) ? $input['headings'] : [];
$tagline  = (string)($input['tagline'] ?? '');
$custom   = is_array($input['custom'] ?? null) ? $input['custom'] : [];

// New custom sections arrive without a key ("key": "" or "new"); they get the next free custom_N
// here, so two dialogs cannot hand out the same one.
$taken = array_map(fn($c) => (int)substr($c['key'], 7), homeLayout($cfg)['custom']);
foreach ($custom as $i => $c) {
    if (!is_array($c)) continue;
    if (!empty($c['key']) && preg_match('/^custom_[1-9][0-9]?$/', (string)$c['key'])) continue;
    $n = 1;
    while (in_array($n, $taken, true)) $n++;
    $taken[] = $n;
    $newKey = 'custom_' . $n;
    // The order and hidden lists still name the placeholder key the client used.
    $old = (string)($c['key'] ?? '');
    $order  = array_map(fn($k) => $k === $old ? $newKey : $k, $order);
    $hidden = array_map(fn($k) => $k === $old ? $newKey : $k, $hidden);
    if (isset($headings[$old])) { $headings[$newKey] = $headings[$old]; unset($headings[$old]); }
    $custom[$i]['key'] = $newKey;
}

$r = homeLayoutValidate($order, $hidden, $headings, $tagline, $custom);
if (isset($r['error'])) jsonResponse(['error' => $r['error']], 400);

// A custom section removed from the layout takes its text with it — a row nobody can reach is not
// a draft, it is a leak.
$keep = array_map(fn($c) => 'home:' . $c['key'], json_decode($r['json'], true)['custom'] ?? []);
try {
    $st = $db->query("SELECT DISTINCT page FROM page_content WHERE page LIKE 'home:custom_%'");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pg) {
        if (!in_array($pg, $keep, true)) $db->prepare("DELETE FROM page_content WHERE page = ?")->execute([$pg]);
    }
} catch (\Throwable $e) {}

setSetting($db, 'home_layout', $r['json']);

// What was actually changed, in words — an audit line reading "saved the home page layout" tells a
// later reader nothing they could not have guessed.
$parts = [];
if ($order !== homeSectionKeys()) $parts[] = 'order: ' . implode(' → ', $order);
$stored = json_decode($r['json'], true);
if (!empty($stored['hidden'])) $parts[] = 'hidden: ' . implode(', ', $stored['hidden']);
if (!empty($stored['headings'])) $parts[] = 'renamed: ' . implode(', ', array_keys($stored['headings']));
if (isset($stored['tagline'])) $parts[] = 'new tagline';
if (!empty($stored['custom'])) $parts[] = count($stored['custom']) . ' custom section' . (count($stored['custom']) === 1 ? '' : 's');
auditNote(['summary' => 'changed the home page layout' . ($parts ? ' (' . implode('; ', $parts) . ')' : '')]);

jsonResponse(['success' => true, 'message' => __('api.pages.layout_saved'),
              'is_default' => homeLayoutIsDefault(array_merge($cfg, ['home_layout' => $r['json']]))]);
