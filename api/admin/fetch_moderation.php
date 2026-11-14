<?php
/**
 * The Moderation page's reads (1.75.0, includes/moderation.php) — one endpoint, one tab at a time.
 *
 *   GET admin/fetch_moderation&tab=restricted
 *       every member silenced or banned right now, newest penalty first, with what imposed it
 *   GET admin/fetch_moderation&tab=warnings&member=&moderator=&action=&state=&from=&to=&user=&after=
 *       the log of every warning (and every silent action, and every automatic penalty), newest first, keyset-paged:
 *       `after` is the `next` of the page before. action: warn | remove | mute | ban | auto; state: active | revoked
 *   GET admin/fetch_moderation&tab=auto&after=
 *       the automatic penalties' rules, the switch, and the log of what they did
 *
 * The router asks panel.moderation.view (api.php). What the session may DO with the answer is told with it
 * (`may_handle`, `may_rules`, `may_dateless`), so the page draws only the buttons that work — the acting endpoints ask again.
 */
$tab = (string)($_GET['tab'] ?? 'restricted');
if (!in_array($tab, ['restricted', 'warnings', 'auto'], true)) $tab = 'restricted';

$out = [
    'tab' => $tab,
    'may_handle' => panelCan($db, $cfg, 'panel.moderation.handle'),
    'may_rules' => panelCan($db, $cfg, 'panel.moderation.rules'),
    // a ban with no end date is the owner's, from the Users page: lifting it here needs the Users page's right as well
    'may_dateless' => panelCan($db, $cfg, 'panel.users.edit'),
    'enabled' => ($cfg['auto_penalties_enabled'] ?? '0') === '1',
    'formats' => function_exists('richtextFormats') ? richtextFormats($cfg, 'report') : [],
];

try {
    if ($tab === 'restricted') {
        $r = moderationRestricted($db, $cfg);
        $out += $r;
    } elseif ($tab === 'warnings') {
        $f = moderationFilters($_GET);
        $r = moderationWarningsPage($db, $cfg, $f, max(0, (int)($_GET['after'] ?? 0)));
        $out += $r + ['filters' => $f];
    } else {
        $r = moderationWarningsPage($db, $cfg, ['member' => '', 'moderator' => '', 'action' => 'auto', 'state' => '', 'from' => '', 'to' => '', 'user' => 0],
                                    max(0, (int)($_GET['after'] ?? 0)));
        $out += $r + ['rules' => moderationRules($db)];
    }
} catch (\Throwable $e) {
    error_log('[admin] fetch_moderation: ' . $e->getMessage());
    jsonResponse(['error' => __('api.db_unavailable')], 503);
}
jsonResponse($out);
