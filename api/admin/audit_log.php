<?php
/**
 * POST admin/audit_log — read the record of who did what.
 *
 *   {"op":"list","page":1,"group":"settings","actor":"…","search":"…","failed_only":bool}
 *   {"op":"actors"}   — the names to offer in the filter
 *
 * Read-only by construction: there is no operation here that writes, and there is deliberately none
 * that deletes. A log somebody can edit from the same panel it records is not a log — retention is
 * the janitor's job (auditPrune) and the window is a setting.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? 'list');
if (!in_array($op, ['list', 'actors'], true)) jsonResponse(['error' => 'Unknown operation'], 400);

// Reading the log is not itself an event worth a line; otherwise every page load of this screen
// would appear in the thing it is showing.
auditSuppress();

if ($op === 'actors') {
    jsonResponse(['success' => true, 'actors' => auditActors($db),
                  'groups' => array_keys(auditActionGroups())]);
}

$r = auditFetch($db, [
    'page'        => (int)($input['page'] ?? 1),
    'per_page'    => (int)($input['per_page'] ?? 50),
    'group'       => (string)($input['group'] ?? ''),
    'actor'       => (string)($input['actor'] ?? ''),
    'search'      => (string)($input['search'] ?? ''),
    'failed_only' => !empty($input['failed_only']),
]);

jsonResponse(['success' => true, 'enabled' => ($cfg['audit_enabled'] ?? '1') === '1',
              'keep_days' => auditKeepDays($cfg), 'groups' => array_keys(auditActionGroups())] + $r);
