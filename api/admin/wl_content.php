<?php
/**
 * POST admin/wl_content — the descriptions and source links people attached, in both homes.
 *
 *   {"op":"list","page":1,"status":"pending|approved|rejected|all","search":"…"}   — what is waiting, and the record
 *   {"op":"approve","id":123,"kind":"wl|idx"}                                      — publish it
 *   {"op":"reject","id":123,"kind":"wl|idx","note":"…"}                            — do not, and remember why
 *   {"op":"clear","id":123,"kind":"wl|idx","password":"…"}                         — delete the words outright
 *   {"op":"edits"}                                                                 — proposed rewrites and edits
 *   {"op":"edit_apply","id":45} / {"op":"edit_reject","id":45}                     — decide one
 *
 * 1.70.0: a proposal is a rewrite (applied, the proposer becomes the author) or an edit (applied, the
 * author stays and the proposer is credited with the share of the text it changed). The Rewrites tab
 * gets each one's kind, the author of record, and for an edit the share it would be credited with if
 * applied now (contentEditPreview(), measured against the text as it stands — what the apply measures).
 * A name the author hid publicly is still shown here, with `author_hidden`: moderating somebody is not
 * showing them to the public.
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
// The Review tab's two reads — the queue and the proposed rewrites — write nothing to the audit log
// (1.69.0): the router logged each as `content.review`, one line per visit and per page of the queue.
// A decision is named for what it was (content.approve … content.edit_reject), which is what the log's
// Content group lists and what nothing wrote until now: every decision was filed under `content.review`.
if ($op === 'list' || $op === 'edits') auditSuppress();
else auditNote(['action' => 'content.' . $op]);

/**
 * The picture beside the author's name on a card (1.63.0): an ADDRESS built from the columns the
 * card's own join picked (`author`, `author_avatar_sha`), '' with no author or with pictures off.
 */
$wlcFace = static fn(array $r): string => (($r['author'] ?? null) !== null && function_exists('userAvatarField'))
    ? userAvatarField(['username' => (string)$r['author'], 'avatar_sha' => $r['author_avatar_sha'] ?? null], 20, getBaseUrl(), $cfg)
    : '';

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
                   w.votes_count, w.score_x100, w.content_user_id, u.username AS author, u.avatar_sha AS author_avatar_sha,
                   u.content_credit_public AS author_credit_public
              FROM whitelist w LEFT JOIN users u ON u.id = w.content_user_id WHERE $whereW
            UNION ALL
            SELECT 'idx' AS kind, c.id, c.info_hash, i.name, 'index' AS source, c.source_url, c.description, c.description_format,
                   c.content_status, c.content_rejected_note, c.created_at, 0, 0, 0, 0, c.content_user_id, u.username AS author,
                   u.avatar_sha AS author_avatar_sha, u.content_credit_public AS author_credit_public
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
        $r['description_html'] = richtextRenderIn('description', $db, $r['description'] ?? '', (string)$r['description_format'], $cfg, true);
        $r['source_trusted'] = $r['source_url'] ? richtextIsTrusted((string)$r['source_url'], $cfg) : false;
        $r['id'] = (int)$r['id'];
        $r['author_avatar'] = $wlcFace($r);
        $r['author_hidden'] = $r['author'] !== null && (int)($r['author_credit_public'] ?? 1) !== 1;
        unset($r['author_avatar_sha'], $r['author_credit_public']);
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
                e.kind AS edit_kind, e.created_at, e.ip, e.user_id, u.username AS author, u.avatar_sha AS author_avatar_sha,
                u.content_credit_public AS author_credit_public,
                COALESCE(w.name, i.name) AS name,
                COALESCE(w.source_url, c.source_url) AS cur_source_url,
                COALESCE(w.description, c.description) AS cur_description,
                COALESCE(w.description_format, c.description_format, 'bbcode') AS cur_format,
                COALESCE(w.content_status, c.content_status, 'none') AS cur_status,
                ua.username AS cur_author,
                CASE WHEN e.whitelist_id IS NOT NULL THEN 'wl' ELSE 'idx' END AS kind
           FROM wl_content_edits e
           LEFT JOIN whitelist w ON w.id = e.whitelist_id
           LEFT JOIN hash_content c ON c.id = e.hash_content_id
           LEFT JOIN index_hashes i ON i.info_hash = e.info_hash
           LEFT JOIN users u ON u.id = e.user_id
           LEFT JOIN users ua ON ua.id = COALESCE(w.content_user_id, c.content_user_id)
          WHERE e.status = 'pending' ORDER BY e.created_at ASC LIMIT 50");
    $rows = $st->fetchAll();
    // Both versions rendered, so the moderator compares what people will SEE rather than two blobs
    // of markup. A rewrite that looks tamer in source and worse on screen is the whole risk here.
    foreach ($rows as &$r) {
        $r['new_html'] = richtextRenderIn('description', $db, $r['description'] ?? '', (string)$r['description_format'], $cfg, true);
        $r['cur_html'] = richtextRenderIn('description', $db, $r['cur_description'] ?? '', (string)$r['cur_format'], $cfg, true);
        $r['new_trusted'] = $r['source_url'] ? richtextIsTrusted((string)$r['source_url'], $cfg) : false;
        $r['author_avatar'] = $wlcFace($r);
        $r['author_hidden'] = $r['author'] !== null && (int)($r['author_credit_public'] ?? 1) !== 1;
        // What applying it now would do: the kind, and an edit's share against the text as it stands.
        $pv = contentEditPreview(['description' => $r['cur_description'], 'description_format' => $r['cur_format'],
                                  'source_url' => $r['cur_source_url'], 'content_status' => $r['cur_status']],
                                 ['edit_kind' => $r['edit_kind'], 'description' => $r['description'],
                                  'description_format' => $r['description_format'], 'source_url' => $r['source_url']]);
        $r['edit_kind'] = $pv['kind'];
        $r['share'] = $pv['share'];
        $r['as_rewrite'] = $pv['as_rewrite'];
        unset($r['author_avatar_sha'], $r['author_credit_public'], $r['cur_status']);
    }
    unset($r);
    jsonResponse(['success' => true, 'rows' => $rows, 'total' => count($rows)]);
}

if ($op === 'edit_apply' || $op === 'edit_reject') {
    $eid = (int)($input['id'] ?? 0);
    if ($eid < 1) jsonResponse(['error' => __('api.content.invalid_id')], 400);
    $e = contentEditById($db, $eid);
    if (!$e) jsonResponse(['error' => __('api.content.proposal_gone')], 404);
    auditNote(['target_type' => $e['kind'] === 'wl' ? 'whitelist' : 'hash', 'target_id' => (string)$e['info_hash']]);
    if ($op === 'edit_reject') {
        contentEditReject($db, $cfg, $e);
        auditNote(['detail' => ['proposal' => $eid, 'kind' => $e['edit_kind']]]);
        jsonResponse(['success' => true, 'message' => __('api.content.proposal_rejected')]);
    }
    // Measured before it goes in, against the text as it stands — the share the apply itself credits.
    $cur = contentRowById($db, (string)$e['kind'], (int)$e['target_id']);
    $pv = $cur ? contentEditPreview($cur, $e) : ['kind' => $e['edit_kind'], 'share' => 0, 'as_rewrite' => false];
    if (!contentEditApply($db, $cfg, $e)) jsonResponse(['error' => __('api.content.proposal_gone')], 404);
    $asEdit = $pv['kind'] === 'edit' && !$pv['as_rewrite'];
    auditNote(['detail' => ['proposal' => $eid, 'kind' => $asEdit ? 'edit' : 'rewrite', 'share' => $asEdit ? $pv['share'] : null]]);
    jsonResponse(['success' => true, 'kind' => $asEdit ? 'edit' : 'rewrite', 'share' => $asEdit ? $pv['share'] : null,
                  'message' => $asEdit ? __('api.content.edit_applied', ['pct' => $pv['share']]) : __('api.content.proposal_applied')]);
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
