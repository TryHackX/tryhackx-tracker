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
if (!in_array($op, ['list', 'item', 'ids', 'bulk', 'approve', 'reject', 'clear', 'edits', 'edit_apply', 'edit_reject'], true)) {
    jsonResponse(['error' => __('api.content.unknown_op')], 400);
}
// The Review tab's two reads — the queue and the proposed rewrites — write nothing to the audit log
// (1.69.0): the router logged each as `content.review`, one line per visit and per page of the queue.
// A decision is named for what it was (content.approve … content.edit_reject), which is what the log's
// Content group lists and what nothing wrote until now: every decision was filed under `content.review`.
if (in_array($op, ['list', 'item', 'ids', 'edits'], true)) auditSuppress();
else auditNote(['action' => 'content.' . $op]);

/**
 * The picture beside the author's name on a card (1.63.0): an ADDRESS built from the columns the
 * card's own join picked (`author`, `author_avatar_sha`), '' with no author or with pictures off.
 */
$wlcFace = static fn(array $r): string => (($r['author'] ?? null) !== null && function_exists('userAvatarField'))
    ? userAvatarField(['username' => (string)$r['author'], 'avatar_sha' => $r['author_avatar_sha'] ?? null], 20, getBaseUrl(), $cfg)
    : '';

$wlcFilter = static function (array $in, bool $stars = false): array {
    // What to show. 'pending' is the queue; the other two are the record of what was decided, which
    // is the only way to answer "why is this published" or "who rejected mine" without reading the
    // database by hand.
    $status = (string)($in['status'] ?? 'pending');
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all', 'forum'], true)) $status = 'pending';
    $whereW = $status === 'all' ? "w.content_status <> 'none'" : "w.content_status = ?";
    $whereC = $status === 'all' ? "c.content_status <> 'none'" : "c.content_status = ?";
    $argsW = $status === 'all' ? [] : [$status];
    $argsC = $status === 'all' ? [] : [$status];
    if ($status === 'forum') {
        $whereW = "f.state IN ('drift', 'too_long', 'invalid', 'removed', 'declined')";
        $whereC = "1 = 0";
        $argsW = [];
        $argsC = [];
    }
    $forumText = "f.info_hash IS NOT NULL AND f.import_sha = SHA2(CONCAT(w.description_format, CHAR(10), w.description), 256)";
    $source = (string)($in['source'] ?? '');
    if ($source === 'forum') {
        $whereW .= " AND " . $forumText;
        $whereC .= " AND 1 = 0";
    } elseif ($source === 'site') {
        $whereW .= " AND NOT (" . $forumText . ")";
    } else {
        $source = '';
    }
    $format = (string)($in['format'] ?? '');
    if (in_array($format, ['bbcode', 'markdown', 'flarum'], true)) {
        $whereW .= " AND w.description_format = ?";
        $whereC .= " AND c.description_format = ?";
        $argsW[] = $format;
        $argsC[] = $format;
    } else {
        $format = '';
    }
    $esc = static fn(string $s): string => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s) . '%';
    $author = trim(mb_substr((string)($in['author'] ?? ''), 0, 64));
    if ($author !== '') {
        $whereW .= " AND (u.username LIKE ? OR f.author_name LIKE ?)";
        $whereC .= " AND u.username LIKE ?";
        array_push($argsW, $esc($author), $esc($author));
        $argsC[] = $esc($author);
    }
    // A hash prefix, a name, or a word from the text. The queue is small, so this is a LIKE and not
    // a full-text index; if it ever stops being small the index goes on `name`, not on `description`.
    $search = trim(mb_substr((string)($in['search'] ?? ''), 0, 200));
    if ($search !== '') {
        $like = $esc($search);
        $whereW .= " AND (w.info_hash LIKE ? OR w.name LIKE ? OR w.source_url LIKE ? OR w.description LIKE ?)";
        $whereC .= " AND (c.info_hash LIKE ? OR i.name LIKE ? OR c.source_url LIKE ? OR c.description LIKE ?)";
        array_push($argsW, $like, $like, $like, $like);
        array_push($argsC, $like, $like, $like, $like);
    }
    $sort = (string)($in['sort'] ?? 'oldest');
    // Rows with no ratings sort as neutral rather than as terrible, or every new item would jump
    // the queue on the strength of nobody having voted. A hash_content row has no ratings of its
    // own (ratings belong to the hash and are shown in the Info panel) and sorts as neutral too.
    $orders = [
        'oldest' => 'q.created_at ASC, q.id ASC',
        'newest' => 'q.created_at DESC, q.id DESC',
        'long'   => 'q.chars DESC, q.id ASC',
        'short'  => 'q.chars ASC, q.id ASC',
        'score'  => match ($stars) {
            true    => 'CASE WHEN q.votes_count = 0 THEN 250 ELSE q.score_x100 END ASC, q.votes_count DESC, q.created_at ASC',
            default => 'CASE WHEN q.votes_count = 0 THEN 5000 ELSE q.score_x100 END ASC, q.votes_count DESC, q.created_at ASC',
        },
        'best'   => '(q.votes_count = 0) ASC, q.score_x100 DESC, q.votes_count DESC, q.created_at DESC',
        'pics'   => 'q.pics DESC, q.created_at DESC, q.id DESC',
    ];
    if (!isset($orders[$sort])) $sort = 'oldest';
    $fromW = "FROM whitelist w LEFT JOIN forum_descs f ON f.info_hash = w.info_hash LEFT JOIN users u ON u.id = w.content_user_id WHERE " . $whereW;
    $fromC = "FROM hash_content c LEFT JOIN index_hashes i ON i.info_hash = c.info_hash LEFT JOIN users u ON u.id = c.content_user_id WHERE " . $whereC;
    return ['status' => $status, 'source' => $source, 'format' => $format, 'sort' => $sort, 'order' => $orders[$sort],
            'fromW' => $fromW, 'fromC' => $fromC, 'args' => array_merge($argsW, $argsC)];
};

$wlcPics = static function (string $col): string {
    return "(CHAR_LENGTH(COALESCE($col, '')) - CHAR_LENGTH(REPLACE(LOWER(COALESCE($col, '')), '[img', ''))) DIV 4
            + (CHAR_LENGTH(COALESCE($col, '')) - CHAR_LENGTH(REPLACE(COALESCE($col, ''), '![', ''))) DIV 2
            + (CHAR_LENGTH(COALESCE($col, '')) - CHAR_LENGTH(REPLACE(LOWER(COALESCE($col, '')), 'upl-image-preview', ''))) DIV 17";
};

$wlcKeys = static function (PDO $db, array $f, int $limit, int $offset, bool $withSha = false) use ($wlcPics): array {
    $shaW = $withSha ? ", SHA2(CONCAT(w.description_format, CHAR(10), COALESCE(w.description, '')), 256) AS sha" : '';
    $shaC = $withSha ? ", SHA2(CONCAT(c.description_format, CHAR(10), COALESCE(c.description, '')), 256) AS sha" : '';
    $picsW = $f['sort'] === 'pics' ? $wlcPics('w.description') : '0';
    $picsC = $f['sort'] === 'pics' ? $wlcPics('c.description') : '0';
    $sql = "SELECT * FROM (
                SELECT 'wl' AS kind, w.id, w.created_at, CHAR_LENGTH(COALESCE(w.description, '')) AS chars, w.votes_count, w.score_x100, " . $picsW . " AS pics" . $shaW . " " . $f['fromW'] . "
                UNION ALL
                SELECT 'idx' AS kind, c.id, c.created_at, CHAR_LENGTH(COALESCE(c.description, '')) AS chars, COALESCE(i.votes_count, 0) AS votes_count, COALESCE(i.score_x100, 0) AS score_x100, " . $picsC . " AS pics" . $shaC . " " . $f['fromC'] . "
            ) q ORDER BY " . $f['order'] . " LIMIT " . $limit . " OFFSET " . $offset;
    $st = $db->prepare($sql);
    $st->execute($f['args']);
    return array_map(static fn($r) => ['kind' => (string)$r['kind'], 'id' => (int)$r['id']] + ($withSha ? ['sha' => (string)$r['sha']] : []), $st->fetchAll());
};

$wlcShaOk = static function (?array $row, $sha): bool {
    if (!is_string($sha) || $sha === '') return true;
    return $row !== null && hash_equals(hash('sha256', (string)$row['description_format'] . "\n" . (string)($row['description'] ?? '')), strtolower($sha));
};

$wlcCount = static function (string $col): string {
    return "CHAR_LENGTH(COALESCE($col, '')) AS chars,
            (CHAR_LENGTH(COALESCE($col, '')) - CHAR_LENGTH(REPLACE(LOWER(COALESCE($col, '')), '[img', ''))) DIV 4
            + (CHAR_LENGTH(COALESCE($col, '')) - CHAR_LENGTH(REPLACE(COALESCE($col, ''), '![', ''))) DIV 2
            + (CHAR_LENGTH(COALESCE($col, '')) - CHAR_LENGTH(REPLACE(LOWER(COALESCE($col, '')), 'upl-image-preview', ''))) DIV 17 AS pics,
            (CHAR_LENGTH(COALESCE($col, '')) - CHAR_LENGTH(REPLACE(LOWER(COALESCE($col, '')), 'youtu', ''))) DIV 5 AS vids";
};

$wlcRows = static function (PDO $db, array $cfg, array $keys) use ($wlcFace, $wlcCount): array {
    $byKind = ['wl' => [], 'idx' => []];
    foreach ($keys as $k) $byKind[$k['kind']][] = $k['id'];
    $found = [];
    if ($byKind['wl']) {
        $in = implode(',', array_fill(0, count($byKind['wl']), '?'));
        $st = $db->prepare("SELECT 'wl' AS kind, w.id, w.info_hash, w.name, w.source, w.source_url, w.description_format, w.content_status,
                                   w.content_rejected_note, w.content_reviewed_at, w.created_at, w.votes_up, w.votes_down, w.votes_count, w.score_x100,
                                   w.content_user_id AS author_id, u.username AS author, u.avatar_sha AS author_avatar_sha,
                                   u.content_credit_public AS author_credit_public, f.url AS forum_url, f.author_name AS forum_author,
                                   f.state AS forum_state, f.note AS forum_note, f.forum_at,
                                   (f.info_hash IS NOT NULL AND f.import_sha = SHA2(CONCAT(w.description_format, CHAR(10), w.description), 256)) AS from_forum,
                                   SHA2(CONCAT(w.description_format, CHAR(10), COALESCE(w.description, '')), 256) AS sha, "
                                   . $wlcCount('w.description') . "
                              FROM whitelist w LEFT JOIN users u ON u.id = w.content_user_id LEFT JOIN forum_descs f ON f.info_hash = w.info_hash
                             WHERE w.id IN ($in)");
        $st->execute($byKind['wl']);
        foreach ($st->fetchAll() as $r) $found['wl:' . $r['id']] = $r;
    }
    if ($byKind['idx']) {
        $in = implode(',', array_fill(0, count($byKind['idx']), '?'));
        $st = $db->prepare("SELECT 'idx' AS kind, c.id, c.info_hash, i.name, 'index' AS source, c.source_url, c.description_format, c.content_status,
                                   c.content_rejected_note, c.content_reviewed_at, c.created_at, COALESCE(i.votes_up, 0) AS votes_up,
                                   COALESCE(i.votes_down, 0) AS votes_down, COALESCE(i.votes_count, 0) AS votes_count,
                                   COALESCE(i.score_x100, 0) AS score_x100, c.content_user_id AS author_id, u.username AS author, u.avatar_sha AS author_avatar_sha,
                                   u.content_credit_public AS author_credit_public, NULL AS forum_url, NULL AS forum_author, NULL AS forum_state,
                                   NULL AS forum_note, NULL AS forum_at, 0 AS from_forum,
                                   SHA2(CONCAT(c.description_format, CHAR(10), COALESCE(c.description, '')), 256) AS sha, "
                                   . $wlcCount('c.description') . "
                              FROM hash_content c LEFT JOIN index_hashes i ON i.info_hash = c.info_hash LEFT JOIN users u ON u.id = c.content_user_id
                             WHERE c.id IN ($in)");
        $st->execute($byKind['idx']);
        foreach ($st->fetchAll() as $r) $found['idx:' . $r['id']] = $r;
    }
    $rows = [];
    foreach ($keys as $k) {
        $r = $found[$k['kind'] . ':' . $k['id']] ?? null;
        if ($r === null) continue;
        foreach (['id', 'votes_up', 'votes_down', 'votes_count', 'score_x100', 'chars', 'pics', 'vids'] as $n) $r[$n] = (int)$r[$n];
        $r['author_id'] = $r['author_id'] !== null ? (int)$r['author_id'] : null;
        $r['from_forum'] = (int)$r['from_forum'] === 1;
        $r['source_trusted'] = $r['source_url'] ? richtextIsTrusted((string)$r['source_url'], $cfg) : false;
        $r['author_avatar'] = $wlcFace($r);
        $r['author_hidden'] = $r['author'] !== null && (int)($r['author_credit_public'] ?? 1) !== 1;
        unset($r['author_avatar_sha'], $r['author_credit_public']);
        $rows[] = $r;
    }
    return $rows;
};

if ($op === 'list') {
    $f = $wlcFilter($input, repMode($cfg) === 'stars');
    $per = (int)($input['per'] ?? 25);
    if (!in_array($per, [25, 50, 100], true)) $per = 25;
    $cs = $db->prepare("SELECT (SELECT COUNT(*) " . $f['fromW'] . ") + (SELECT COUNT(*) " . $f['fromC'] . ")");
    $cs->execute($f['args']);
    $total = (int)$cs->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = max(1, min((int)($input['page'] ?? 1), $pages));
    $rows = $wlcRows($db, $cfg, $wlcKeys($db, $f, $per, ($page - 1) * $per));
    $edits = (int)$db->query("SELECT COUNT(*) FROM wl_content_edits WHERE status = 'pending'")->fetchColumn();
    // The tab badge counts what is WAITING, whatever the current filter shows. A badge that follows
    // the filter would read 0 while a queue sat behind it.
    jsonResponse(['success' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per,
                  'status' => $f['status'], 'source' => $f['source'], 'format' => $f['format'], 'sort' => $f['sort'],
                  'waiting' => contentPendingCount($db), 'edits_pending' => $edits, 'rep_mode' => repMode($cfg),
                  'may_punish' => panelCan($db, $cfg, 'panel.moderation.handle'),
                  'autopublish' => ($cfg['wl_content_autopublish'] ?? '0') === '1',
                  'review_on' => ($cfg['wl_content_review'] ?? '1') === '1']);
}

if ($op === 'ids') {
    $f = $wlcFilter($input, repMode($cfg) === 'stars');
    jsonResponse(['success' => true, 'items' => $wlcKeys($db, $f, 2000, 0, true)]);
}

if ($op === 'item') {
    $id = (int)($input['id'] ?? 0);
    $kind = (string)($input['kind'] ?? 'wl');
    if ($id < 1 || !in_array($kind, ['wl', 'idx'], true)) jsonResponse(['error' => __('api.content.invalid_id')], 400);
    $row = contentRowById($db, $kind, $id);
    if (!$row) jsonResponse(['error' => __('api.content.invalid_id')], 404);
    // The rendered HTML is built here, by the same renderer the public page uses, so a moderator is
    // looking at exactly what a visitor would see — not at the source, where a broken tag or an
    // image that only appears after rendering would be easy to wave through.
    // A moderator must see what they are approving, hidden parts included.
    jsonResponse(['success' => true, 'id' => $id, 'kind' => $kind,
                  'sha' => hash('sha256', (string)$row['description_format'] . "\n" . (string)($row['description'] ?? '')),
                  'description_html' => richtextRenderIn('description', $db, (string)($row['description'] ?? ''), (string)$row['description_format'],
                                                         ['rt_magnet_self' => (string)$row['info_hash']] + $cfg, true)]);
}

if ($op === 'bulk') {
    $action = (string)($input['action'] ?? '');
    if (!in_array($action, ['approve', 'reject'], true)) jsonResponse(['error' => __('api.content.unknown_op')], 400);
    $items = is_array($input['items'] ?? null) ? array_slice($input['items'], 0, 200) : [];
    $note = (string)($input['note'] ?? '');
    $done = 0;
    $failed = 0;
    $changed = 0;
    $seen = [];
    foreach ($items as $it) {
        $kind = is_array($it) ? (string)($it['kind'] ?? '') : '';
        $id = is_array($it) ? (int)($it['id'] ?? 0) : 0;
        if ($id < 1 || !in_array($kind, ['wl', 'idx'], true) || isset($seen[$kind . $id])) { $failed++; continue; }
        $seen[$kind . $id] = true;
        if (!$wlcShaOk(contentRowById($db, $kind, $id), $it['sha'] ?? null)) { $changed++; continue; }
        $ok = $action === 'approve' ? contentApprove($db, $cfg, $kind, $id) : contentReject($db, $cfg, $kind, $id, $note);
        if ($ok) $done++; else $failed++;
    }
    auditNote(['action' => 'content.bulk_' . $action, 'detail' => ['done' => $done, 'failed' => $failed, 'changed' => $changed]]);
    jsonResponse(['success' => true, 'done' => $done, 'failed' => $failed, 'changed' => $changed, 'waiting' => contentPendingCount($db)]);
}

// ── proposed rewrites ───────────────────────────────────────────────────────

if ($op === 'edits') {
    $st = $db->query(
        "SELECT e.id, e.whitelist_id, e.hash_content_id, e.info_hash, e.source_url, e.description, e.description_format,
                e.kind AS edit_kind, e.created_at, e.ip, e.user_id, e.note, u.username AS author, u.avatar_sha AS author_avatar_sha,
                u.content_credit_public AS author_credit_public,
                COALESCE(w.name, i.name) AS name,
                COALESCE(w.source_url, c.source_url) AS cur_source_url,
                COALESCE(w.description, c.description) AS cur_description,
                COALESCE(w.description_format, c.description_format, 'bbcode') AS cur_format,
                COALESCE(w.content_status, c.content_status, 'none') AS cur_status,
                ua.username AS cur_author, f.url AS forum_url, f.author_name AS forum_author, f.forum_at,
                CASE WHEN e.whitelist_id IS NOT NULL THEN 'wl' ELSE 'idx' END AS kind
           FROM wl_content_edits e
           LEFT JOIN whitelist w ON w.id = e.whitelist_id
           LEFT JOIN hash_content c ON c.id = e.hash_content_id
           LEFT JOIN index_hashes i ON i.info_hash = e.info_hash
           LEFT JOIN users u ON u.id = e.user_id
           LEFT JOIN users ua ON ua.id = COALESCE(w.content_user_id, c.content_user_id)
           LEFT JOIN forum_descs f ON f.edit_id = e.id
          WHERE e.status = 'pending' ORDER BY e.created_at ASC LIMIT 50");
    $rows = $st->fetchAll();
    // Both versions rendered, so the moderator compares what people will SEE rather than two blobs
    // of markup. A rewrite that looks tamer in source and worse on screen is the whole risk here.
    foreach ($rows as &$r) {
        $r['new_html'] = richtextRenderIn('description', $db, $r['description'] ?? '', (string)$r['description_format'], ['rt_magnet_self' => (string)$r['info_hash']] + $cfg, true);
        $r['cur_html'] = richtextRenderIn('description', $db, $r['cur_description'] ?? '', (string)$r['cur_format'], ['rt_magnet_self' => (string)$r['info_hash']] + $cfg, true);
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
        $r['sha'] = hash('sha256', (string)$r['description_format'] . "\n" . (string)($r['description'] ?? ''));
        unset($r['author_avatar_sha'], $r['author_credit_public'], $r['cur_status'], $r['description'], $r['cur_description']);
    }
    unset($r);
    jsonResponse(['success' => true, 'rows' => $rows, 'total' => count($rows)]);
}

if ($op === 'edit_apply' || $op === 'edit_reject') {
    $eid = (int)($input['id'] ?? 0);
    if ($eid < 1) jsonResponse(['error' => __('api.content.invalid_id')], 400);
    $e = contentEditById($db, $eid);
    if (!$e) jsonResponse(['error' => __('api.content.proposal_gone')], 404);
    if ($op === 'edit_apply' && !$wlcShaOk($e, $input['sha'] ?? null)) jsonResponse(['error' => __('api.content.changed'), 'changed' => true], 409);
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
    $removal = defined('IMAGES_REMOVAL_NOTE') && (string)($e['note'] ?? '') === IMAGES_REMOVAL_NOTE;
    $asEdit = $removal || ($pv['kind'] === 'edit' && !$pv['as_rewrite']);
    auditNote(['detail' => ['proposal' => $eid, 'kind' => $asEdit ? 'edit' : 'rewrite', 'share' => $asEdit ? $pv['share'] : null]]);
    jsonResponse(['success' => true, 'kind' => $asEdit ? 'edit' : 'rewrite', 'share' => $asEdit ? $pv['share'] : null,
                  'message' => $removal ? __('api.content.removal_applied', ['pct' => $pv['share']])
                      : ($asEdit ? __('api.content.edit_applied', ['pct' => $pv['share']]) : __('api.content.proposal_applied'))]);
}

$id = (int)($input['id'] ?? 0);
if ($id < 1) jsonResponse(['error' => __('api.content.invalid_id')], 400);
$kind = (string)($input['kind'] ?? 'wl');
if (!in_array($kind, ['wl', 'idx'], true)) jsonResponse(['error' => __('api.content.invalid_id')], 400);

if (($op === 'approve' || $op === 'reject') && !$wlcShaOk(contentRowById($db, $kind, $id), $input['sha'] ?? null)) {
    jsonResponse(['error' => __('api.content.changed'), 'changed' => true], 409);
}

if ($op === 'approve') {
    if (!contentApprove($db, $cfg, $kind, $id)) jsonResponse(['error' => __('api.content.invalid_id')], 404);
    jsonResponse(['success' => true, 'message' => __('api.content.published'), 'waiting' => contentPendingCount($db)]);
}

if ($op === 'reject') {
    if (!contentReject($db, $cfg, $kind, $id, (string)($input['note'] ?? ''))) jsonResponse(['error' => __('api.content.invalid_id')], 404);
    jsonResponse(['success' => true, 'message' => __('api.content.rejected'), 'waiting' => contentPendingCount($db)]);
}

// clear — the one that cannot be undone
$viaUser = (int)($_SESSION['admin_via_user'] ?? 0);
if ($viaUser <= 0 || !userIdHasPermission($db, $cfg, $viaUser, 'content.delete_any')) requireAdminReauth((string)($input['password'] ?? ''), $cfg);
$rec = contentRowById($db, $kind, $id);
if ($rec !== null) contentDelete($db, $cfg, $rec + ['kind' => $kind], ['id' => 0], 'any', ['notify' => false]);
jsonResponse(['success' => true, 'message' => __('api.content.deleted'), 'waiting' => contentPendingCount($db)]);
