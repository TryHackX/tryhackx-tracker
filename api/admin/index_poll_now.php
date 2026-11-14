<?php
// Force a full-scrape poll right now (admin button). Bounded by index_poll_budget.
requirePost();
if (!indexEnabled($cfg)) jsonResponse(['success' => false, 'error' => __('api.index.disabled')], 400);
// Long CPU-bound work (download + parse + upsert of a full scrape): release the session so other admin
// requests (status poll, other tabs) don't block behind us, keep running if the tab closes, and give PHP
// enough execution time for the fetch (min(90,budget)) + parse budget + the DELETE-JOIN overhead.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
ignore_user_abort(true);
@set_time_limit(indexPollBudget($cfg) * 2 + 120);
$p = indexPoll($db, $cfg, null, time());
// `partial` beside `truncated`: a poll the time budget cut and one whose download ended early are
// both "truncated", and the toast says which (the next poll continues either way).
jsonResponse(['success' => $p['ok'], 'entries' => $p['entries'], 'kept' => $p['kept'], 'truncated' => $p['truncated'],
    'partial' => $p['partial'] !== null,
    'removed_wl' => $p['removed_wl'], 'removed_ban' => $p['removed_ban'], 'bytes' => $p['bytes'], 'ms' => $p['ms'], 'error' => $p['error']]);
