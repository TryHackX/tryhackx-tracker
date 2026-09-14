<?php
/**
 * POST admin/shout_purge {password, older_than_days|null} — empty the room from Settings.
 *
 * Not in adminEndpointPermission(), so it is owner-only like the rest of Settings, and it asks for
 * the owner's password on top of that: this is the one control on the page that destroys something
 * no backup of the settings would bring back. `older_than_days` null means everything.
 *
 * Hard deletes, mentions first. A moderator clearing one line uses the button on the line itself —
 * this is the operator wiping a room, and it is written to the audit log as such.
 */
require_once __DIR__ . '/../../includes/shout.php';
requirePost();
$input = readJsonBody();
requireAdminReauth((string)($input['password'] ?? ''), $cfg);

// null (or nothing at all) is "everything"; a number is a floor in days. Clamped rather than
// refused — a typo in a number field must not be the difference between a purge and an error.
$raw = $input['older_than_days'] ?? null;
$days = ($raw === null || $raw === '' || !is_numeric($raw)) ? null : max(0, min(3650, (int)$raw));

$gone = shoutPurge($db, $cfg, $days);
auditLog($db, 'shout.purge', ['target_type' => 'shout', 'target_id' => '',
    'summary' => $days === null ? 'the whole shoutbox (' . $gone . ' rows)' : 'shouts older than ' . $days . ' days (' . $gone . ' rows)']);

jsonResponse(['success' => true, 'deleted' => $gone, 'message' => __('api.shout.purged', ['n' => $gone])]);
