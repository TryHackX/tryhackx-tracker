<?php
/**
 * The Reports page's queues of reported PUBLIC words — comments, torrent descriptions, shouts (1.71.0,
 * includes/reports.php).
 *
 *   GET admin/content_reports&counts=1
 *       → {kinds: {comment|description|shout: {open, handle}}} — the kinds this session may see and whose feature
 *         is on, with the open TARGETS each (the tab badges)
 *   GET admin/content_reports&kind=K[&status=open|closed|all][&author=][&reporter=][&from=Y-m-d][&to=Y-m-d][&q=][&page=]
 *       → {groups: [a target once: its words now (and as reported, when they changed or went), where it lives,
 *          its author's state, ALL its reports], total, page, pages, open, may_handle}
 *
 * The router asks only panel.access (api.php): which queue is being read decides the permission, so this asks
 * `panel.reports.<kind>.view` itself — the way admin/backup_action asks for its op. A kind whose feature is
 * switched off is not listed (its tab is not drawn either); its reports wait for it.
 */
$kinds = contentReportPanelKinds($db, $cfg);
$on = array_keys(array_filter($kinds, fn($k) => $k['on']));
if (!empty($_GET['counts'])) {
    $counts = contentReportOpenCounts($db, $on);
    $out = [];
    foreach ($on as $k) $out[$k] = ['open' => (int)($counts[$k] ?? 0), 'handle' => (bool)$kinds[$k]['handle']];
    jsonResponse(['kinds' => $out]);
}
$kind = (string)($_GET['kind'] ?? '');
if (!in_array($kind, CONTENT_REPORT_KINDS, true)) jsonResponse(['error' => 'bad_kind'], 400);
if (!isset($kinds[$kind])) jsonResponse(['error' => 'no_permission'], 403);
if (!$kinds[$kind]['on']) jsonResponse(['error' => 'disabled'], 404);
$f = contentReportFilters($_GET);
$perPage = max(1, min(100, (int)($cfg['items_per_page'] ?? 25)));
$list = contentReportList($db, $cfg, $kind, $f, max(1, (int)($_GET['page'] ?? 1)), $perPage);
jsonResponse($list + [
    'open'       => (int)(contentReportOpenCounts($db, [$kind])[$kind] ?? 0),
    'may_handle' => (bool)$kinds[$kind]['handle'],
    'filters'    => $f,
]);
