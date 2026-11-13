<?php
/**
 * The automatic penalties' rules and their switch (1.75.0, includes/moderation.php, Part D) — `panel.moderation.rules`.
 *
 *   POST admin/moderation_rules {rules: [{id?, warnings, window_days, action: mute|ban, duration_days, enabled}, …], enabled}
 *       the whole ladder as the editor shows it, in the order shown; a rule with an id is updated in place (the penalties it
 *       gave stay pointing at it), one without is new, one left out is deleted. Each is judged: warnings 1-100, window 1-365
 *       days, duration 0-365 days (0 = until somebody lifts it). `enabled` is the switch (setting `auto_penalties_enabled`).
 *
 * One audit line, autopenalty.rules — before and after — written here, so the router's generic one is suppressed.
 * The ladder itself is read through admin/fetch_moderation (tab auto); the owner can also flip the switch on Settings.
 */
requirePost();
$input = readJsonBody();
$clean = moderationRulesClean($input['rules'] ?? null);
if (isset($clean['error'])) {
    jsonResponse(['success' => false, 'error' => $clean['error'], 'row' => (int)$clean['row'], 'message' => __('api.moderation.' . $clean['error'], ['n' => (int)$clean['row'] + 1, 'max' => MODERATION_RULES_MAX])], 400);
}
$enabled = array_key_exists('enabled', $input) ? ($input['enabled'] === true || $input['enabled'] === 1 || $input['enabled'] === '1') : null;
$before = moderationRules($db);
$wasOn = ($cfg['auto_penalties_enabled'] ?? '0') === '1';
$saved = moderationRulesSave($db, $clean['rows'], $enabled);
$strip = static fn(array $rs): array => array_map(static fn($r) => [(int)$r['warnings'], (int)$r['window_days'], (string)$r['action'], (int)$r['duration_days'], (int)$r['enabled']], $rs);
auditLog($db, 'autopenalty.rules', ['target_type' => 'setting', 'target_id' => 'auto_penalties_enabled',
    'summary' => count($saved['rules']) . ' rule(s); the ladder is ' . (($enabled ?? $wasOn) ? 'ON' : 'OFF') . ($enabled !== null && $enabled !== $wasOn ? ' (switched)' : ''),
    'detail' => ['before' => $strip($before), 'after' => $strip($saved['rules']), 'was_enabled' => $wasOn, 'enabled' => $enabled ?? $wasOn,
                 'columns' => ['warnings', 'window_days', 'action', 'duration_days', 'enabled']]]);
auditSuppress();
jsonResponse(['success' => true, 'rules' => $saved['rules'], 'enabled' => ($enabled ?? $wasOn)]);
