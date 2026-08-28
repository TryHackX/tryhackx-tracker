<?php
/**
 * POST admin/wl_content — the review queue for source links and descriptions.
 *
 *   {"op":"list","page":1}                  — what is waiting
 *   {"op":"approve","id":123}               — publish it
 *   {"op":"reject","id":123,"note":"…"}     — do not, and remember why
 *   {"op":"clear","id":123,"password":"…"}  — delete the link and the description outright
 *
 * The torrent itself is never touched here. It registered when it was submitted and it stays
 * registered whatever happens to the words attached to it — a description nobody approved is a
 * description nobody sees, not a reason to stop serving a swarm.
 *
 * Approve and reject do not ask for the password: neither destroys anything, and a moderator working
 * through a queue would be typing it fifty times. Deleting the text outright does ask, because that
 * one cannot be undone.
 */
requirePost();
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
if (!in_array($op, ['list', 'approve', 'reject', 'clear', 'edits', 'edit_apply', 'edit_reject'], true)) {
    jsonResponse(['error' => 'Unknown operation'], 400);
}

if ($op === 'list') {
    $page = max(1, (int)($input['page'] ?? 1));
    $per  = 25;
    $off  = ($page - 1) * $per;
    $total = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE content_status = 'pending'")->fetchColumn();
    // Worst first. A moderator working through a queue should meet the things people have already
    // complained about before the things nobody has an opinion on — the queue is not a mailbox, and
    // oldest-first spends attention in the order the abuse arrived rather than where it matters.
    // Rows with no ratings sort as neutral rather than as terrible, or every new item would jump
    // the queue on the strength of nobody having voted.
    $st = $db->prepare(
        "SELECT id, info_hash, name, source, source_url, description, description_format,
                content_status, created_at, votes_up, votes_down, score_x100
           FROM whitelist WHERE content_status = 'pending'
          ORDER BY CASE WHEN (votes_up + votes_down) = 0 THEN 5000 ELSE score_x100 END ASC,
                   (votes_up + votes_down) DESC, created_at ASC
          LIMIT $per OFFSET $off");
    $st->execute();
    $rows = $st->fetchAll();
    // The rendered HTML is built here, by the same renderer the public page uses, so a moderator is
    // looking at exactly what a visitor would see — not at the source, where a broken tag or an
    // image that only appears after rendering would be easy to wave through.
    foreach ($rows as &$r) {
        $r['description_html'] = richtextRender($r['description'] ?? '', (string)$r['description_format'], $cfg);
        $r['source_trusted'] = $r['source_url'] ? richtextIsTrusted((string)$r['source_url'], $cfg) : false;
    }
    unset($r);
    $edits = (int)$db->query("SELECT COUNT(*) FROM wl_content_edits WHERE status = 'pending'")->fetchColumn();
    jsonResponse(['success' => true, 'rows' => $rows, 'total' => $total, 'page' => $page,
                  'pages' => max(1, (int)ceil($total / $per)),
                  'edits_pending' => $edits,
                  'autopublish' => ($cfg['wl_content_autopublish'] ?? '0') === '1',
                  'review_on' => ($cfg['wl_content_review'] ?? '1') === '1']);
}

// ── proposed rewrites ───────────────────────────────────────────────────────
if ($op === 'edits') {
    $st = $db->query(
        "SELECT e.id, e.whitelist_id, e.info_hash, e.source_url, e.description, e.description_format,
                e.created_at, e.ip, w.name,
                w.source_url AS cur_source_url, w.description AS cur_description,
                w.description_format AS cur_format
           FROM wl_content_edits e JOIN whitelist w ON w.id = e.whitelist_id
          WHERE e.status = 'pending' ORDER BY e.created_at ASC LIMIT 50");
    $rows = $st->fetchAll();
    // Both versions rendered, so the moderator compares what people will SEE rather than two blobs
    // of markup. A rewrite that looks tamer in source and worse on screen is the whole risk here.
    foreach ($rows as &$r) {
        $r['new_html'] = richtextRender($r['description'] ?? '', (string)$r['description_format'], $cfg);
        $r['cur_html'] = richtextRender($r['cur_description'] ?? '', (string)$r['cur_format'], $cfg);
        $r['new_trusted'] = $r['source_url'] ? richtextIsTrusted((string)$r['source_url'], $cfg) : false;
    }
    unset($r);
    jsonResponse(['success' => true, 'rows' => $rows, 'total' => count($rows)]);
}

if ($op === 'edit_apply' || $op === 'edit_reject') {
    $eid = (int)($input['id'] ?? 0);
    if ($eid < 1) jsonResponse(['error' => 'Invalid id'], 400);
    $st = $db->prepare("SELECT * FROM wl_content_edits WHERE id = ? AND status = 'pending' LIMIT 1");
    $st->execute([$eid]);
    $e = $st->fetch();
    if (!$e) jsonResponse(['error' => 'That proposal is not waiting any more.'], 404);

    if ($op === 'edit_reject') {
        $db->prepare("UPDATE wl_content_edits SET status = 'rejected', reviewed_at = NOW() WHERE id = ?")->execute([$eid]);
        jsonResponse(['success' => true, 'message' => 'Proposal rejected. What is published is unchanged.']);
    }

    // Applying keeps the version it replaces, as a rejected proposal of its own, so an accepted
    // rewrite can be undone by accepting the old one back.
    $st = $db->prepare("SELECT source_url, description, description_format FROM whitelist WHERE id = ? LIMIT 1");
    $st->execute([(int)$e['whitelist_id']]);
    $cur = $st->fetch();
    if ($cur && ($cur['description'] !== null || $cur['source_url'] !== null)) {
        $db->prepare("INSERT INTO wl_content_edits (whitelist_id, info_hash, source_url, description,
                             description_format, status, note, reviewed_at)
                      VALUES (?, ?, ?, ?, ?, 'rejected', 'replaced by a later proposal', NOW())")
           ->execute([(int)$e['whitelist_id'], (string)$e['info_hash'], $cur['source_url'],
                      $cur['description'], (string)$cur['description_format']]);
    }
    $db->prepare("UPDATE whitelist SET source_url = ?, description = ?, description_format = ?,
                         content_status = 'approved', content_reviewed_at = NOW() WHERE id = ?")
       ->execute([$e['source_url'], $e['description'], (string)$e['description_format'], (int)$e['whitelist_id']]);
    $db->prepare("UPDATE wl_content_edits SET status = 'applied', reviewed_at = NOW() WHERE id = ?")->execute([$eid]);
    jsonResponse(['success' => true, 'message' => 'Applied and published. The version it replaced is kept.']);
}

$id = (int)($input['id'] ?? 0);
if ($id < 1) jsonResponse(['error' => 'Invalid id'], 400);

if ($op === 'approve') {
    $db->prepare("UPDATE whitelist SET content_status = 'approved', content_reviewed_at = NOW(),
                         content_rejected_note = NULL WHERE id = ?")->execute([$id]);
    jsonResponse(['success' => true, 'message' => 'Published. It is on the public pages now.']);
}

if ($op === 'reject') {
    $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 255);
    // Kept, not deleted. If the same submitter argues, the text they actually sent is still here.
    $db->prepare("UPDATE whitelist SET content_status = 'rejected', content_reviewed_at = NOW(),
                         content_rejected_note = ? WHERE id = ?")->execute([$note !== '' ? $note : null, $id]);
    jsonResponse(['success' => true, 'message' => 'Rejected. Nothing is shown publicly; the text is kept for the record.']);
}

// clear — the one that cannot be undone
requireAdminReauth((string)($input['password'] ?? ''), $cfg);
$db->prepare("UPDATE whitelist SET source_url = NULL, description = NULL, content_status = 'none',
                     content_reviewed_at = NOW(), content_rejected_note = NULL WHERE id = ?")->execute([$id]);
jsonResponse(['success' => true, 'message' => 'Deleted. The torrent stays registered.']);
