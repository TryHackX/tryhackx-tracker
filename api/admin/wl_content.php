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
if (!in_array($op, ['list', 'approve', 'reject', 'clear'], true)) {
    jsonResponse(['error' => 'Unknown operation'], 400);
}

if ($op === 'list') {
    $page = max(1, (int)($input['page'] ?? 1));
    $per  = 25;
    $off  = ($page - 1) * $per;
    $total = (int)$db->query("SELECT COUNT(*) FROM whitelist WHERE content_status = 'pending'")->fetchColumn();
    $st = $db->prepare(
        "SELECT id, info_hash, name, source, source_url, description, description_format,
                content_status, created_at
           FROM whitelist WHERE content_status = 'pending'
          ORDER BY created_at ASC LIMIT $per OFFSET $off");
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
    jsonResponse(['success' => true, 'rows' => $rows, 'total' => $total, 'page' => $page,
                  'pages' => max(1, (int)ceil($total / $per)),
                  'review_on' => ($cfg['wl_content_review'] ?? '1') === '1']);
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
