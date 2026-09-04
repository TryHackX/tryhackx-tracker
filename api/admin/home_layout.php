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
                return [false, 'Tracker statistics are switched off (Statistics → Live statistics).'];
            }
            if (($cfg['tracker_stats_show_home'] ?? '1') !== '1') {
                return [false, 'The widget is switched off for the home page (Statistics → Show on home page).'];
            }
            if (!userCan($db, $cfg, 'home.stats')) {
                return [false, 'Visitors do not have the home.stats permission, so they never see it.'];
            }
            return [true, ''];
        case 'announce':
            if (empty($cfg['announce_url']) && empty($cfg['announce_url_https'])) {
                return [false, 'No announce URL is configured (Site & pages → Announce address).'];
            }
            return [true, ''];
        case 'donations':
            if (($cfg['donations_enabled'] ?? '0') !== '1') {
                return [false, 'Donations are switched off (Site & pages → Donations).'];
            }
            $f = json_decode((string)($cfg['donation_fields'] ?? '[]'), true);
            $legacy = !empty($cfg['wallet_xmr']) || !empty($cfg['wallet_btc']) || !empty($cfg['wallet_eth']);
            if ((!is_array($f) || !$f) && !$legacy) {
                return [false, 'Donations are on but no wallet or link is filled in.'];
            }
            return [true, ''];
        case 'contact':
            if (($cfg['contact_visible'] ?? '1') !== '1') {
                return [false, 'The contact block is switched off (Contact & email → Show contact section).'];
            }
            return [true, ''];
    }
    return [true, ''];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $layout = homeLayout($cfg);
    $sections = [];
    foreach ($layout['order'] as $key) {
        $meta = $catalog[$key];
        [$live, $why] = homeSectionAvailable($cfg, $db, $key);
        $sections[] = [
            'key'      => $key,
            'label'    => $meta['label'],
            'about'    => $meta['about'],
            'fixed'    => !empty($meta['fixed']),
            'hidden'   => in_array($key, $layout['hidden'], true),
            // The RENDERED built-in wording, not its key: the editor shows it as the
            // placeholder, and `home.about_head` in a text box would be gibberish.
            'heading'  => $meta['heading'] === null ? null : homeHeadingDefault($key),
            'value'    => $layout['headings'][$key] ?? ($meta['heading'] === null ? '' : homeHeadingDefault($key)),
            'custom'   => isset($layout['headings'][$key]),
            'live'     => $live,
            'why'      => $why,
        ];
    }
    jsonResponse([
        'success'    => true,
        'sections'   => $sections,
        'tagline'    => homeTagline($cfg),
        'tagline_default' => homeTaglineDefault(),
        'is_default' => homeLayoutIsDefault($cfg),
        'max'        => HOME_HEADING_MAX,
        'note'       => 'Hiding a section here removes it from the page. It does not switch the '
                      . 'feature off, and dragging one back does not switch it on — a section whose '
                      . 'own setting is off says so on its row.',
    ]);
}

requirePost();
$input = readJsonBody();
$op = strtolower(trim((string)($input['op'] ?? 'save')));

if ($op === 'reset') {
    setSetting($db, 'home_layout', '');
    auditNote(['summary' => 'restored the built-in home page layout']);
    jsonResponse(['success' => true, 'message' => 'The home page is back to its built-in layout.']);
}
if ($op !== 'save') jsonResponse(['error' => 'Unknown operation. Use save or reset.'], 400);

$order    = is_array($input['order'] ?? null) ? $input['order'] : [];
$hidden   = is_array($input['hidden'] ?? null) ? $input['hidden'] : [];
$headings = is_array($input['headings'] ?? null) ? $input['headings'] : [];
$tagline  = (string)($input['tagline'] ?? '');

$r = homeLayoutValidate($order, $hidden, $headings, $tagline);
if (isset($r['error'])) jsonResponse(['error' => $r['error']], 400);

setSetting($db, 'home_layout', $r['json']);

// What was actually changed, in words — an audit line reading "saved the home page layout" tells a
// later reader nothing they could not have guessed.
$parts = [];
if ($order !== homeSectionKeys()) $parts[] = 'order: ' . implode(' → ', $order);
$stored = json_decode($r['json'], true);
if (!empty($stored['hidden'])) $parts[] = 'hidden: ' . implode(', ', $stored['hidden']);
if (!empty($stored['headings'])) $parts[] = 'renamed: ' . implode(', ', array_keys($stored['headings']));
if (isset($stored['tagline'])) $parts[] = 'new tagline';
auditNote(['summary' => 'changed the home page layout' . ($parts ? ' (' . implode('; ', $parts) . ')' : '')]);

jsonResponse(['success' => true, 'message' => 'Home page saved.',
              'is_default' => homeLayoutIsDefault(array_merge($cfg, ['home_layout' => $r['json']]))]);
