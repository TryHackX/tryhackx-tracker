<?php
/**
 * GET — tracker service status + smart restart recommendations for the dashboard.
 *
 * Auth is enforced by the router (admin/*). This is a GET (read-only) so it is CSRF-exempt, like
 * the other admin read endpoints. Returns the current warning level, the individual recommendation
 * items and whether an in-panel restart is even possible on this host.
 */

$service = trim((string)($cfg['opentracker_service_name'] ?? ''));

if ($service === '') {
    // Feature disabled — the dashboard hides the whole control in this case.
    jsonResponse(['enabled' => false]);
}

$disabled      = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
$execAvailable = function_exists('exec') && !in_array('exec', $disabled, true);

$warn = getTrackerServiceWarnings($cfg);
$warn['enabled']        = true;
$warn['service']        = $service;
$warn['exec_available'] = $execAvailable;

// If restarts can't run here, say so as a red note so the greyed-out button is explained.
if (!$execAvailable) {
    $warn['items'][] = ['level' => 'danger', 'text' => 'Restart unavailable — PHP exec() is disabled on the server'];
    $warn['count']   = count($warn['items']);
    if (($warn['level'] ?? 'none') === 'none') $warn['level'] = 'danger';
}

jsonResponse($warn);
