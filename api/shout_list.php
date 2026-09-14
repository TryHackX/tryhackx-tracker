<?php
/**
 * GET shout_list — the room, in the only three shapes anybody asks for.
 *
 *   (nothing)      the newest `shout_widget_rows` lines, oldest first
 *   &after=<id>    only what is NEWER than an id — the poll, up to `shout_page_rows`
 *   &before=<id>   only what is OLDER than an id — the "older" button, up to `shout_widget_rows`
 *
 * The poll is the whole design: a drawn widget carries its newest id in the markup and asks from
 * there, so the usual answer is an empty list and four small numbers, and NOTHING is ever redrawn.
 * A list that repaints itself eats the half-typed line under it — that lesson cost 1.48 and 1.50.
 *
 * Reads only, so the session lock goes before the first query: PHP serialises one browser's
 * requests on the session file, and a page polling every ten seconds would queue everything else
 * that browser asks for behind it.
 */
if (!shoutEnabled($cfg)) jsonResponse(['error' => 'disabled'], 403);
$me = currentUser($db);
// A visitor is told to sign in; a signed-in reader whose groups do not carry `shout.view` is told
// the truth. The two are different answers because they have different remedies — and a guest the
// operator HAS granted shout.view passes here like anybody else.
if (!shoutMayView($db, $cfg)) {
    jsonResponse(['error' => $me ? 'no_permission' : 'login_required'], $me ? 403 : 401);
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Cache-Control: private, no-store');

// The same ceiling an open conversation's poll gets (api/user_messages.php): generous enough for a
// three-second cadence in several tabs, small enough that a loop costs nothing.
if (!rateLimitAllow('shoutpoll', ipBucket(getClientIp($cfg)), 600, 60)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 60], 429);
}

$after  = max(0, (int)($_GET['after'] ?? 0));
$before = max(0, (int)($_GET['before'] ?? 0));
$reader = $me ?? [];

if ($after > 0) {
    // The page's limit, not the widget's: a tab left open over lunch catches up in one request
    // instead of in twenty.
    $rows = shoutRows($db, $cfg, $reader, shoutPageRows($cfg), $after);
    $hasMore = false;
} else {
    $rows = shoutRows($db, $cfg, $reader, shoutWidgetRows($cfg), null, $before > 0 ? $before : null);
    // Is there anything ABOVE what was just handed over? The same helper the first render of the
    // widget uses, so the page and the poll cannot disagree about whether there is more to read.
    $hasMore = shoutHasOlder($db, $rows ? (int)$rows[0]['id'] : 0);
}

$out = [
    'success'  => true,
    'rows'     => $rows,
    'newest'   => shoutNewestId($db),
    'has_more' => $hasMore,
    // The cadence this reader is on, which is not the same number for everybody from 1.60.0: a
    // guest reads and never writes, so `shout_live_seconds_guest` is what answers them.
    'live'     => shoutLiveSecondsFor($cfg, $me === null),
];
// The pinned line rides with the two answers that FILL the list — the first draw and the "older"
// button — and never with the poll. `after=` is the append path: a pinned row handed to it would be
// appended to the bottom of the room again every few seconds until it was the only thing in it.
if ($after <= 0) $out['pinned'] = shoutPinned($db, $cfg, $me);
jsonResponse($out);
