<?php
// Force a full-scrape poll right now (admin button). Bounded by index_poll_budget.
requirePost();
if (!indexEnabled($cfg)) jsonResponse(['success' => false, 'error' => 'Index is disabled'], 400);
$p = indexPoll($db, $cfg, null, time());
jsonResponse(['success' => $p['ok'], 'entries' => $p['entries'], 'kept' => $p['kept'], 'truncated' => $p['truncated'],
    'removed_wl' => $p['removed_wl'], 'removed_ban' => $p['removed_ban'], 'bytes' => $p['bytes'], 'ms' => $p['ms'], 'error' => $p['error']]);
