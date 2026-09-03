<?php
/**
 * POST — create, fill, refresh, enable, delete and push address lists.
 *
 * Auth + CSRF come from the router. No entry in the permission map, so this is owner-only: it is the
 * same class of control as the rate limit itself, and the Traffic page's read permission deliberately
 * does not carry the controls with it.
 *
 * Body: {"op": "create"|"entries"|"refresh"|"toggle"|"delete"|"push",
 *        "id": int, "name": "...", "kind": "allow"|"block", "mode": "hard"|"soft",
 *        "source": "manual"|"url", "url": "...", "ttl_minutes": int, "text": "...",
 *        "enabled": bool, "password": "..."}
 *
 * WHY ONLY `push` ASKS FOR THE PASSWORD
 * -------------------------------------
 * Everything else writes to the database and to a file under config/. Nothing reaches the firewall
 * until a push — so an admin can build a list, look at what it parsed to, and decide afterwards.
 * The push is the moment packets start being dropped, and that is the moment worth a password.
 */

requirePost();

$input = readJsonBody();
$op = strtolower(trim((string)($input['op'] ?? '')));
$known = ['create', 'entries', 'refresh', 'toggle', 'delete', 'push'];
if (!in_array($op, $known, true)) {
    jsonResponse(['error' => 'Unknown operation. Use one of: ' . implode(', ', $known) . '.'], 400);
}

$id = (int)($input['id'] ?? 0);
$needId = ['entries', 'refresh', 'toggle', 'delete'];
if (in_array($op, $needId, true)) {
    if ($id <= 0 || !ipListFind($db, $id)) jsonResponse(['error' => 'No such list.'], 404);
}

/** Re-write the file the firewall helper reads. Called after anything that changes what is enabled. */
$sync = static function () use ($db, $cfg): array {
    return ipListWriteSetsFile($db, $cfg);
};

switch ($op) {
    case 'create': {
        $r = ipListCreate($db, $input);
        if (isset($r['error'])) jsonResponse(['error' => $r['error']], 400);
        $newId = (int)$r['id'];

        // A list is worth nothing empty, so a create that carries content fills it in one go: pasted
        // text for a manual list, the first download for a URL one.
        $added = 0; $note = '';
        if (($input['source'] ?? 'manual') === 'url') {
            $f = ipListRefresh($db, $newId, true);
            if (isset($f['error'])) {
                $note = 'The list was created but the first download failed: ' . $f['error'];
            } else {
                $added = (int)($f['added'] ?? 0);
            }
        } elseif (trim((string)($input['text'] ?? '')) !== '') {
            $e = ipListSetEntries($db, $newId, (string)$input['text']);
            if (isset($e['error'])) $note = $e['error'];
            else $added = (int)$e['added'];
        }
        $s = $sync();
        jsonResponse(['success' => true, 'id' => $newId, 'added' => $added, 'note' => $note,
                      'lines' => $s['lines'],
                      'message' => $added
                          ? 'Created with ' . number_format($added) . ($added === 1 ? ' entry' : ' entries')
                            . '. Press "Push to firewall" to load it.'
                          : ($note !== '' ? $note : 'Created. It is empty until you add entries.')]);
    }

    case 'entries': {
        $text = (string)($input['text'] ?? '');
        if (strlen($text) > IPLIST_FETCH_MAX_BYTES) {
            jsonResponse(['error' => 'That file is larger than ' . (IPLIST_FETCH_MAX_BYTES >> 20) . ' MiB.'], 400);
        }
        $e = ipListSetEntries($db, $id, $text);
        if (isset($e['error'])) jsonResponse(['error' => $e['error']], 400);
        $s = $sync();
        jsonResponse(['success' => true, 'added' => $e['added'], 'skipped' => $e['skipped'], 'lines' => $s['lines'],
                      'message' => number_format($e['added']) . ($e['added'] === 1 ? ' entry' : ' entries') . ' stored'
                                   . ($e['skipped'] ? ', ' . number_format($e['skipped'])
                                       . ($e['skipped'] === 1 ? ' line ignored' : ' lines ignored') : '')
                                   . '. Press "Push to firewall" to load them.']);
    }

    case 'refresh': {
        $r = ipListRefresh($db, $id, true);
        if (isset($r['error'])) jsonResponse(['error' => $r['error']], 502);
        $s = $sync();
        jsonResponse(['success' => true, 'added' => (int)($r['added'] ?? 0), 'lines' => $s['lines'],
                      'message' => 'Downloaded ' . number_format((int)($r['added'] ?? 0))
                                   . ((int)($r['added'] ?? 0) === 1 ? ' entry.' : ' entries.')]);
    }

    case 'toggle': {
        $on = !empty($input['enabled']);
        if (!ipListToggle($db, $id, $on)) jsonResponse(['error' => 'Could not change the list.'], 500);
        $s = $sync();
        jsonResponse(['success' => true, 'enabled' => $on, 'lines' => $s['lines'],
                      'message' => ($on ? 'Enabled' : 'Disabled') . '. Press "Push to firewall" to make it so.']);
    }

    case 'delete': {
        if (!ipListDelete($db, $id)) jsonResponse(['error' => 'Could not delete the list.'], 500);
        $s = $sync();
        jsonResponse(['success' => true, 'lines' => $s['lines'],
                      'message' => 'Deleted. Press "Push to firewall" to stop enforcing it.']);
    }

    case 'push': {
        requireAdminReauth((string)($input['password'] ?? ''), $cfg);
        if (netlimitCommand($cfg) === '') {
            jsonResponse(['error' => 'No rate-limit helper command is configured. Set it in Settings → UDP traffic & rate limit first.'], 400);
        }
        if (!trackerExecAvailable()) {
            jsonResponse(['error' => 'PHP exec() is disabled on this server — the panel cannot reach the firewall helper.'], 500);
        }
        // The master switch travels with the push, so "off" genuinely clears the sets rather than
        // leaving the last ruleset enforcing a list the page says is not in use.
        if (array_key_exists('enabled', $input)) {
            setSettings($db, ['net_lists_enabled' => !empty($input['enabled']) ? '1' : '0']);
            $cfg = getSettings($db);
        }
        $s = ipListWriteSetsFile($db, $cfg);
        if (!$s['written']) {
            jsonResponse(['error' => 'Could not write ' . basename($s['path']) . ' — check that config/ is writable.'], 500);
        }
        if (!netlimitEnabled($cfg)) {
            jsonResponse(['error' => 'The inbound limit is not running, and the lists live inside its table. '
                                   . 'Start the limit (or the counters) first, then push.'], 400);
        }
        $r = netlimitApply($cfg, netlimitPps($cfg), netlimitBurst($cfg), netlimitPort($cfg), false, 'lists');
        if (!$r['ok']) jsonResponse(['error' => $r['error'] ?? 'Could not load the lists.', 'output' => $r['output']], 500);
        $loaded = (array)($r['json']['lists'] ?? []);
        jsonResponse(['success' => true, 'applied' => $r['json'], 'lines' => $s['lines'], 'loaded' => $loaded,
                      'message' => 'Loaded: ' . number_format((int)($loaded['allow4'] ?? 0) + (int)($loaded['allow6'] ?? 0)) . ' allowed, '
                                   . number_format((int)($loaded['block4'] ?? 0) + (int)($loaded['block6'] ?? 0)) . ' blocked, '
                                   . number_format((int)($loaded['soft4'] ?? 0) + (int)($loaded['soft6'] ?? 0)) . ' throttled first.']);
    }
}
