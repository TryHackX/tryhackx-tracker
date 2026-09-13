<?php
/**
 * POST admin/wl_content — the descriptions and source links people attached, in both homes.
 *
 *   {"op":"list","page":1,"status":"pending|approved|rejected|all","search":"…"}   — what is waiting, and the record
 *   {"op":"approve","id":123,"kind":"wl|idx"}                                      — publish it
 *   {"op":"reject","id":123,"kind":"wl|idx","note":"…"}                            — do not, and remember why
 *   {"op":"clear","id":123,"kind":"wl|idx","password":"…"}                         — delete the words outright
 *   {"op":"edits"}                                                                 — proposed rewrites
 *   {"op":"edit_apply","id":45} / {"op":"edit_reject","id":45}                     — decide one
 *
 * `kind` says which home the row is in: 'wl' is a registered torrent's whitelist row, 'idx' a
 * hash_content row for a torrent the tracker has only seen (includes/content.php). Since 1.53.0 the
 * queue lists both, the author is on every card, and the author is told what was decided.
 *
 * The torrent itself stays registered whatever happens to the words attached to it — a description
 * nobody approved is a description nobody sees, not a reason to stop serving a swarm. Approve and
 * reject do not ask for the password: neither destroys anything, and a moderator working through a
 * queue should not type it forty times. Clear does.
 */
require_once __DIR__ . '/../../includes/content.php';
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
if (!in_array($op, ['list', 'approve', 'reject', 'clear', 'edits', 'edit_apply', 'edit_reject'], true)) {
    jsonResponse(['error' => __('api.content.unknown_op')], 400);
}

if ($op === 'list') {
    $page = max(1, (int)($input['page'] ?? 1));
    $per  = 25;
    $off  = ($page - 1) * $per;

    // What to show. 'pending' is the queue; the other two are the record of what was decided, which
    // is the only way to answer "why is this published" or "who rejected mine" without reading the
    // database by hand.
    $status = (string)($input['status'] ?? 'pending');
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
    $whereW = $status === 'all' ? "w.content_status <> 'none'" : "w.content_status = ?";
    $whereC = $status === 'all' ? "c.content_status <> 'none'" : "c.content_status = ?";
    $argsW  = $status === 'all' ? [] : [$status];
    $argsC  = $status === 'all' ? [] : [$status];

    // A hash prefix, a name, or a word from the text. The queue is small, so this is a LIKE and not
    // a full-text index; if it ever stops being small the index goes on `name`, not on `description`.
    $search = trim((string)($input['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
        $whereW .= " AND (w.info_hash LIKE ? OR w.name LIKE ? OR w.source_url LIKE ? OR w.description LIKE ?)";
        $whereC .= " AND (c.info_hash LIKE ? OR i.name LIKE ? OR c.source_url LIKE ? OR c.description LIKE ?)";
        array_push($argsW, $like, $like, $like, $like);
        array_push($argsC, $like, $like, $like, $like);
    }

    $cs = $db->prepare("SELECT (SELECT COUNT(*) FROM whitelist w WHERE $whereW)
                             + (SELECT COUNT(*) FROM hash_content c LEFT JOIN index_hashes i ON i.info_hash = c.info_hash WHERE $whereC)");
    $cs->execute(array_merge($argsW, $argsC));
    $total = (int)$cs->fetchColumn();
    // The tab badge counts what is WAITING, whatever the current filter shows. A badge that follows
    // the filter would read 0 while a queue sat behind it.
    $waiting = contentPendingCount($db);
    // Worst first. A moderator working through a queue should meet the things people have already
    // complained about before the things nobody has an opinion on — the queue is not a mailbox, and
    // oldest-first spends attention in the order the abuse arrived rather than where it matters.
    // Rows with no ratings sort as neutral rather than as terrible, or every new item would jump
    // the queue on the strength of nobody having voted. A hash_content row has no ratings of its
    // own (ratings belong to the hash and are shown in the Info panel) and sorts as neutral too.
    $st = $db->prepare(
        "SELECT * FROM (
            SELECT 'wl' AS kind, w.id, w.info_hash, w.name, w.source, w.source_url, w.description, w.description_format,
                   w.content_status, w.content_rejected_note, w.created_at, w.votes_up, w.votes_down,
                   w.votes_count, w.score_x100, w.content_user_id, u.username AS author
              FROM whitelist w LEFT JOIN users u ON u.id = w.content_user_id WHERE $whereW
            UNION ALL
            SELECT 'idx' AS kind, c.id, c.info_hash, i.name, 'index' AS source, c.source_url, c.description, c.description_format,
                   c.content_status, c.content_rejected_note, c.created_at, 0, 0, 0, 0, c.content_user_id, u.username AS author
              FROM hash_content c LEFT JOIN index_hashes i ON i.info_hash = c.info_hash
                                  LEFT JOIN users u ON u.id = c.content_user_id WHERE $whereC
         ) q
          ORDER BY CASE WHEN q.votes_count = 0 THEN 5000 ELSE q.score_x100 END ASC,
                   q.votes_count DESC, q.created_at ASC
          LIMIT $per OFFSET $off");
    $st->execute(array_merge($argsW, $argsC));
    $rows = $st->fetchAll();
    // The rendered HTML is built here, by the same renderer the public page uses, so a moderator is
    // looking at exactly what a visitor would see — not at the source, where a broken tag or an
    // image that only appears after rendering would be easy to wave through.
    foreach ($rows as &$r) {
        // A moderator must see what they are approving, hidden parts included.
        $r['description_html'] = richtextRender($r['description'] ?? '', (string)$r['description_format'], $cfg, true);
        $r['source_trusted'] = $r['source_url'] ? richtextIsTrusted((string)$r['source_url'], $cfg) : false;
        $r['id'] = (int)$r['id'];
    }
    unset($r);
    $edits = (int)$db->query("SELECT COUNT(*) FROM wl_content_edits WHERE status = 'pending'")->fetchColumn();
    jsonResponse(['success' => true, 'rows' => $rows, 'total' => $total, 'page' => $page,
                  'pages' => max(1, (int)ceil($total / $per)),
                  'status' => $status, 'waiting' => $waiting,
                  'edits_pending' => $edits,
                  'autopublish' => ($cfg['wl_content_autopublish'] ?? '0') === '1',
                  'review_on' => ($cfg['wl_content_review'] ?? '1') === '1']);
}

// ── proposed rewrites ───────────────────────────────────────────────────────

if ($op === 'edits') {
    $st = $db->query(
        "SELECT e.id, e.whitelist_id, e.hash_content_id, e.info_hash, e.source_url, e.description, e.description_format,
                e.created_at, e.ip, e.user_id, u.username AS author,
                COALESCE(w.name, i.name) AS name,
                COALESCE(w.source_url, c.source_url) AS cur_source_url,
                COALESCE(w.description, c.description) AS cur_description,
                COALESCE(w.description_format, c.description_format, 'bbcode') AS cur_format,
                CASE WHEN e.whitelist_id IS NOT NULL THEN 'wl' ELSE 'idx' END AS kind
           FROM wl_content_edits e
           LEFT JOIN whitelist w ON w.id = e.whitelist_id
           LEFT JOIN hash_content c ON c.id = e.hash_content_id
           LEFT JOIN index_hashes i ON i.info_hash = e.info_hash
           LEFT JOIN users u ON u.id = e.user_id
          WHERE e.status = 'pending' ORDER BY e.created_at ASC LIMIT 50");
    $rows = $st->fetchAll();
    // Both versions rendered, so the moderator compares what people will SEE rather than two blobs
    // of markup. A rewrite that looks tamer in source and worse on screen is the whole risk here.
    foreach ($rows as &$r) {
        $r['new_html'] = richtextRender($r['description'] ?? '', (string)$r['description_format'], $cfg, true);
        $r['cur_html'] = richtextRender($r['cur_description'] ?? '', (string)$r['cur_format'], $cfg, true);
        $r['new_trusted'] = $r['source_url'] ? richtextIsTrusted((string)$r['source_url'], $cfg) : false;
    }
    unset($r);
    jsonResponse(['success' => true, 'rows' => $rows, 'total' => count($rows)]);
}

if ($op === 'edit_apply' || $op === 'edit_reject') {
    $eid = (int)($input['id'] ?? 0);
    if ($eid < 1) jsonResponse(['error' => __('api.content.invalid_id')], 400);
    $e = contentEditById($db, $eid);
    if (!$e) jsonResponse(['error' => __('api.content.proposal_gone')], 404);
    if ($op === 'edit_reject') {
        contentEditReject($db, $cfg, $e);
        jsonResponse(['success' => true, 'message' => __('api.content.proposal_rejected')]);
    }
    if (!contentEditApply($db, $cfg, $e)) jsonResponse(['error' => __('api.content.proposal_gone')], 404);
    jsonResponse(['success' => true, 'message' => __('api.content.proposal_applied')]);
}

$id = (int)($input['id'] ?? 0);
if ($id < 1) jsonResponse(['error' => __('api.content.invalid_id')], 400);
$kind = (string)($input['kind'] ?? 'wl');
if (!in_array($kind, ['wl', 'idx'], true)) jsonResponse(['error' => __('api.content.invalid_id')], 400);

if ($op === 'approve') {
    if (!contentApprove($db, $cfg, $kind, $id)) jsonResponse(['error' => __('api.content.invalid_id')], 404);
    jsonResponse(['success' => true, 'message' => __('api.content.published')]);
}

if ($op === 'reject') {
    if (!contentReject($db, $cfg, $kind, $id, (string)($input['note'] ?? ''))) jsonResponse(['error' => __('api.content.invalid_id')], 404);
    jsonResponse(['success' => true, 'message' => __('api.content.rejected')]);
}

// clear — the one that cannot be undone
requireAdminReauth((string)($input['password'] ?? ''), $cfg);
contentClear($db, $kind, $id);
jsonResponse(['success' => true, 'message' => __('api.content.deleted')]);
