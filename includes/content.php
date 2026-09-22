<?php
/**
 * Descriptions and source links: what people write ABOUT a torrent, wherever the torrent lives.
 *
 * Two homes, one contract. A registered torrent keeps its words on its `whitelist` row, as it has
 * since 1.17.0. A torrent the tracker has only SEEN (an `index_hashes` row and nothing else — every
 * hash in blacklist mode, and every unregistered one in whitelist mode) keeps them in `hash_content`,
 * a table added in 1.53.0 so that describing such a torrent does not have to register it: a whitelist
 * row is a registration, the index poll drops a whitelisted hash out of the index, and in whitelist
 * mode the accesslist is built from those rows. Words are not a registration. Every reader of a
 * description goes through richtextContentFor(), which looks in both places, and every writer goes
 * through contentAttach() — the whitelist form, the Info panel and the review queue included — so
 * the two homes cannot drift apart in what they allow.
 *
 * Who wrote it is recorded (`content_user_id`) and shown with the text, and the author is told what
 * happened to it: a description sent into a queue that never answers is the last description that
 * person writes.
 */
require_once __DIR__ . '/richtext.php';

/** Descriptions or source links are switched on at all. */
function contentEnabled(array $cfg): bool {
    return (($cfg['wl_allow_description'] ?? '0') === '1') || (($cfg['wl_allow_source_url'] ?? '0') === '1');
}

function contentTableFor(string $kind): string {
    if ($kind === 'wl') return 'whitelist';
    if ($kind === 'idx') return 'hash_content';
    throw new InvalidArgumentException('unknown content kind: ' . $kind);
}

/**
 * The record that carries (or would carry) the words for one hash: the whitelist row when there is
 * one, otherwise the hash_content row. Null when neither exists.
 */
function contentRecordFor(PDO $db, string $hash): ?array {
    $hash = strtolower($hash);
    $st = $db->prepare("SELECT id, name, source_url, description, description_format, content_status,
                               content_user_id, content_rejected_note, banned
                          FROM whitelist WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        return ['kind' => 'wl', 'id' => (int)$r['id'], 'info_hash' => $hash, 'name' => $r['name'],
                'source_url' => $r['source_url'], 'description' => $r['description'],
                'description_format' => (string)$r['description_format'], 'content_status' => (string)$r['content_status'],
                'content_user_id' => $r['content_user_id'] !== null ? (int)$r['content_user_id'] : null,
                'content_rejected_note' => $r['content_rejected_note'], 'banned' => (int)$r['banned'] === 1];
    }
    $st = $db->prepare("SELECT c.id, c.source_url, c.description, c.description_format, c.content_status,
                               c.content_user_id, c.content_rejected_note, i.name
                          FROM hash_content c LEFT JOIN index_hashes i ON i.info_hash = c.info_hash
                         WHERE c.info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        // The second home has no `banned` column of its own — the ban list is the only witness, so
        // ask it. Hardcoding false here made every caller that trusts this flag (contentAttach, the
        // Info panel, richtextContentFor) treat a banned hash as an ordinary one, which is the whole
        // difference between the two homes disappearing at exactly the point it matters.
        return ['kind' => 'idx', 'id' => (int)$r['id'], 'info_hash' => $hash, 'name' => $r['name'],
                'source_url' => $r['source_url'], 'description' => $r['description'],
                'description_format' => (string)$r['description_format'], 'content_status' => (string)$r['content_status'],
                'content_user_id' => $r['content_user_id'] !== null ? (int)$r['content_user_id'] : null,
                'content_rejected_note' => $r['content_rejected_note'],
                'banned' => function_exists('isHashBanned') && isHashBanned($db, $hash)];
    }
    return null;
}

function contentOccupied(array $rec): bool {
    return ($rec['description'] !== null && $rec['description'] !== '')
        || ($rec['source_url'] !== null && $rec['source_url'] !== '');
}

/** Does the index know this hash? The only other place words may be attached to. */
function contentIndexKnows(PDO $db, string $hash): bool {
    $st = $db->prepare("SELECT 1 FROM index_hashes WHERE info_hash = ? LIMIT 1");
    $st->execute([strtolower($hash)]);
    return (bool)$st->fetchColumn();
}

/** The torrent's name for a notification, from whichever row has one; the hash when none does. */
function contentNameFor(PDO $db, string $hash): string {
    $rec = contentRecordFor($db, $hash);
    $name = $rec['name'] ?? null;
    if ($name === null || $name === '') {
        $st = $db->prepare("SELECT name FROM index_hashes WHERE info_hash = ? LIMIT 1");
        $st->execute([strtolower($hash)]);
        $name = $st->fetchColumn();
    }
    return is_string($name) && $name !== '' ? $name : strtolower($hash);
}

/**
 * Who wrote the words, as the Info panel draws them: ['username', 'avatar_sha'] or null. The one
 * query the name always cost (it was contentAuthorName() until 1.63.0) — the picture beside the name
 * rides along in it rather than being asked for a second time.
 */
function contentAuthorRow(PDO $db, ?int $userId): ?array {
    if ($userId === null || $userId < 1) return null;
    $st = $db->prepare("SELECT username, avatar_sha FROM users WHERE id = ? LIMIT 1");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return (is_array($u) && is_string($u['username'] ?? null) && $u['username'] !== '')
        ? ['username' => (string)$u['username'], 'avatar_sha' => $u['avatar_sha'] ?? null] : null;
}

/** What the digest and the tab badge count: everything waiting, in both homes. */
function contentPendingCount(PDO $db): int {
    return (int)$db->query("SELECT (SELECT COUNT(*) FROM whitelist WHERE content_status = 'pending')
                                 + (SELECT COUNT(*) FROM hash_content WHERE content_status = 'pending')")->fetchColumn();
}

/**
 * Attach words to a hash — or, when it already has some, PROPOSE replacing them.
 *
 * $in = ['description', 'description_format', 'source_url']; $user = the signed-in submitter or
 * null. The permission asked is the SUBMITTER's own (userIdHasPermission), not the session's, so
 * the same function answers the same way from a CLI test and from a web request; with no submitter
 * the session decides, which is how an anonymous visitor on an install without accounts gets the
 * legacy answer for content.*.
 *
 * Returns ['ok' => true, 'saved' => bool, 'pending' => bool, 'proposed' => bool, 'kind' => 'wl'|'idx']
 * or ['ok' => false, 'error' => sentence, 'code' => http status].
 */
function contentAttach(PDO $db, array $cfg, string $hash, array $in, ?array $user, string $ip): array {
    $hash = strtolower($hash);
    $descOn = ($cfg['wl_allow_description'] ?? '0') === '1';
    $srcOn  = ($cfg['wl_allow_source_url'] ?? '0') === '1';
    $desc = $descOn ? trim((string)($in['description'] ?? '')) : '';
    $src  = $srcOn ? trim((string)($in['source_url'] ?? '')) : '';
    // Normalised whatever else arrives: both homes store it in an ENUM('markdown','bbcode'), so a
    // format nobody asked for is a write that either fails or lands as ''. It used to be checked only
    // on the path that has a description, and a submission carrying just a source link wrote it raw.
    $fmt  = (string)($in['description_format'] ?? 'bbcode');
    if (!in_array($fmt, richtextFormats($cfg), true)) $fmt = richtextFormats($cfg)[0];
    if ($desc === '' && $src === '') {
        return ['ok' => false, 'error' => __('api.content.nothing_to_attach'), 'code' => 400];
    }
    $can = function (string $perm) use ($db, $cfg, $user): bool {
        return $user !== null
            ? userIdHasPermission($db, $cfg, (int)$user['id'], $perm)
            : userCan($db, $cfg, $perm);
    };
    if ($src !== '') {
        $e = richtextValidateSourceUrl($src, $cfg);
        if ($e !== null) return ['ok' => false, 'error' => $e, 'code' => 400];
    }
    if ($desc !== '') {
        $e = richtextValidate($desc, $fmt, $cfg);
        if ($e !== null) return ['ok' => false, 'error' => $e, 'code' => 400];
    }

    $rec = contentRecordFor($db, $hash);
    // Either home. A ban is about the hash, not about which table happens to hold its words.
    if ($rec !== null && $rec['banned']) {
        return ['ok' => false, 'error' => __('api.content.hash_banned'), 'code' => 403];
    }
    if ($rec === null) {
        // Not registered. Words may still be attached to a hash the index has SEEN — and to nothing
        // else: a description of a hash nobody has met would be a catalogue entry made of hearsay.
        if (function_exists('isHashBanned') && isHashBanned($db, $hash)) {
            return ['ok' => false, 'error' => __('api.content.hash_banned'), 'code' => 403];
        }
        if (!contentIndexKnows($db, $hash)) {
            return ['ok' => false, 'error' => __('api.content.unknown_hash'), 'code' => 404];
        }
        // Asked HERE, not only at the UPDATE below: the INSERT is what brings the record into
        // existence, and a submitter who is about to be refused must not leave one behind. An empty
        // hash_content row is not harmless — it is a record, and contentAttach then reads "an empty
        // record exists" for the next person, who gets the submit path rather than the proposal one.
        if (!$can('content.submit')) {
            return ['ok' => false, 'error' => __('api.wl.content_needs_access'), 'code' => 403];
        }
        $db->prepare("INSERT IGNORE INTO hash_content (info_hash) VALUES (?)")->execute([$hash]);
        $rec = contentRecordFor($db, $hash);
        if ($rec === null) return ['ok' => false, 'error' => __('api.content.unknown_hash'), 'code' => 404];
    }
    $userId = $user !== null ? (int)$user['id'] : null;

    if (contentOccupied($rec)) {
        // Anyone can describe anyone's torrent, so the first person to do it is not automatically the
        // right one — and "whoever submits last wins" would be an invitation. A later submission is a
        // proposal a moderator decides on; nothing changes for readers until somebody says so.
        if (!$can('content.propose')) {
            return ['ok' => false, 'error' => __('api.wl.propose_needs_access'), 'code' => 403];
        }
        $maxPending = max(0, min(50, (int)($cfg['wl_edit_max_pending'] ?? 3)));
        if ($maxPending === 0) {
            return ['ok' => false, 'error' => __('api.wl.proposals_not_accepted'), 'code' => 409];
        }
        $col = $rec['kind'] === 'wl' ? 'whitelist_id' : 'hash_content_id';
        $st = $db->prepare("SELECT COUNT(*) FROM wl_content_edits WHERE `$col` = ? AND status = 'pending'");
        $st->execute([$rec['id']]);
        if ((int)$st->fetchColumn() >= $maxPending) {
            return ['ok' => false, 'error' => __('api.wl.proposals_pending_limit', ['max' => $maxPending]), 'code' => 429];
        }
        $db->prepare("INSERT INTO wl_content_edits (whitelist_id, hash_content_id, info_hash, source_url, description,
                             description_format, ip, user_id)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$rec['kind'] === 'wl' ? $rec['id'] : null, $rec['kind'] === 'idx' ? $rec['id'] : null, $hash,
                      $src !== '' ? $src : null, $desc !== '' ? $desc : null, $fmt, $ip, $userId]);
        return ['ok' => true, 'saved' => false, 'pending' => false, 'proposed' => true, 'kind' => $rec['kind']];
    }

    if (!$can('content.submit')) {
        return ['ok' => false, 'error' => __('api.wl.content_needs_access'), 'code' => 403];
    }
    // An empty record. Published at once when the operator has said so, otherwise it waits — and a
    // registered torrent is registered and serving either way.
    $auto = ($cfg['wl_content_autopublish'] ?? '0') === '1';
    $status = ($auto || ($cfg['wl_content_review'] ?? '1') !== '1') ? 'approved' : 'pending';
    $table = contentTableFor($rec['kind']);
    $db->prepare("UPDATE `$table` SET source_url = ?, description = ?, description_format = ?, content_status = ?,
                         content_user_id = ?, content_reviewed_at = NULL, content_rejected_note = NULL
                   WHERE id = ?")
       ->execute([$src !== '' ? $src : null, $desc !== '' ? $desc : null, $fmt, $status, $userId, $rec['id']]);
    return ['ok' => true, 'saved' => true, 'pending' => $status === 'pending', 'proposed' => false, 'kind' => $rec['kind']];
}

/** Tell the person, when there is a person: type 'content', one line and a body. */
function contentNotify(PDO $db, ?int $userId, string $title, string $body): void {
    if ($userId === null || $userId < 1 || !function_exists('userNotify')) return;
    userNotify($db, $userId, 'content', $title, $body);
}

/** The record behind a review action, or null. */
function contentRowById(PDO $db, string $kind, int $id): ?array {
    $table = contentTableFor($kind);
    $st = $db->prepare("SELECT id, info_hash, source_url, description, description_format, content_status, content_user_id
                          FROM `$table` WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function contentApprove(PDO $db, array $cfg, string $kind, int $id): bool {
    $row = contentRowById($db, $kind, $id);
    if (!$row) return false;
    $table = contentTableFor($kind);
    $db->prepare("UPDATE `$table` SET content_status = 'approved', content_reviewed_at = NOW(),
                         content_rejected_note = NULL WHERE id = ?")->execute([$id]);
    $name = contentNameFor($db, (string)$row['info_hash']);
    contentNotify($db, $row['content_user_id'] !== null ? (int)$row['content_user_id'] : null,
        __('notify.content_published', ['name' => $name]),
        __('notify.content_published_body', ['name' => $name, 'hash' => $row['info_hash']]));
    return true;
}

function contentReject(PDO $db, array $cfg, string $kind, int $id, ?string $note): bool {
    $row = contentRowById($db, $kind, $id);
    if (!$row) return false;
    $note = $note !== null ? mb_substr(trim($note), 0, 255) : '';
    $table = contentTableFor($kind);
    // Kept, not deleted. If the same submitter argues, the text they actually sent is still here.
    $db->prepare("UPDATE `$table` SET content_status = 'rejected', content_reviewed_at = NOW(),
                         content_rejected_note = ? WHERE id = ?")->execute([$note !== '' ? $note : null, $id]);
    $name = contentNameFor($db, (string)$row['info_hash']);
    contentNotify($db, $row['content_user_id'] !== null ? (int)$row['content_user_id'] : null,
        __('notify.content_rejected', ['name' => $name]),
        $note !== '' ? __('notify.content_rejected_body_note', ['name' => $name, 'note' => $note])
                     : __('notify.content_rejected_body', ['name' => $name]));
    return true;
}

/** Delete the words outright. The torrent stays whatever it was. */
function contentClear(PDO $db, string $kind, int $id): bool {
    $table = contentTableFor($kind);
    $st = $db->prepare("UPDATE `$table` SET source_url = NULL, description = NULL, content_status = 'none',
                               content_user_id = NULL, content_reviewed_at = NULL, content_rejected_note = NULL
                         WHERE id = ?");
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

/** A pending proposal row, with the kind it belongs to. */
function contentEditById(PDO $db, int $editId): ?array {
    $st = $db->prepare("SELECT * FROM wl_content_edits WHERE id = ? AND status = 'pending' LIMIT 1");
    $st->execute([$editId]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) return null;
    $e['kind'] = !empty($e['whitelist_id']) ? 'wl' : 'idx';
    $e['target_id'] = !empty($e['whitelist_id']) ? (int)$e['whitelist_id'] : (int)$e['hash_content_id'];
    return $e;
}

/**
 * Apply a proposal: the words it carries replace what is published, the replaced version is kept as
 * a rejected proposal of its own (so an accepted rewrite can be undone by accepting the old one
 * back), the proposer becomes the author, and both the proposer and the author they replaced hear.
 */
function contentEditApply(PDO $db, array $cfg, array $e): bool {
    $kind = (string)$e['kind'];
    $table = contentTableFor($kind);
    $cur = contentRowById($db, $kind, (int)$e['target_id']);
    if (!$cur) return false;
    if ($cur['description'] !== null || $cur['source_url'] !== null) {
        $db->prepare("INSERT INTO wl_content_edits (whitelist_id, hash_content_id, info_hash, source_url, description,
                             description_format, status, note, user_id, reviewed_at)
                      VALUES (?, ?, ?, ?, ?, ?, 'rejected', 'replaced by a later proposal', ?, NOW())")
           ->execute([$kind === 'wl' ? (int)$e['target_id'] : null, $kind === 'idx' ? (int)$e['target_id'] : null,
                      (string)$e['info_hash'], $cur['source_url'], $cur['description'],
                      (string)$cur['description_format'], $cur['content_user_id']]);
    }
    $db->prepare("UPDATE `$table` SET source_url = ?, description = ?, description_format = ?,
                         content_status = 'approved', content_user_id = ?, content_reviewed_at = NOW(),
                         content_rejected_note = NULL WHERE id = ?")
       ->execute([$e['source_url'], $e['description'], (string)$e['description_format'],
                  $e['user_id'] !== null ? (int)$e['user_id'] : null, (int)$e['target_id']]);
    $db->prepare("UPDATE wl_content_edits SET status = 'applied', reviewed_at = NOW() WHERE id = ?")->execute([(int)$e['id']]);
    $name = contentNameFor($db, (string)$e['info_hash']);
    $proposer = $e['user_id'] !== null ? (int)$e['user_id'] : null;
    $previous = $cur['content_user_id'] !== null ? (int)$cur['content_user_id'] : null;
    contentNotify($db, $proposer, __('notify.content_proposal_applied', ['name' => $name]),
                  __('notify.content_proposal_applied_body', ['name' => $name]));
    if ($previous !== null && $previous !== $proposer) {
        contentNotify($db, $previous, __('notify.content_replaced', ['name' => $name]),
                      __('notify.content_replaced_body', ['name' => $name]));
    }
    return true;
}

function contentEditReject(PDO $db, array $cfg, array $e): bool {
    $db->prepare("UPDATE wl_content_edits SET status = 'rejected', reviewed_at = NOW() WHERE id = ?")->execute([(int)$e['id']]);
    $name = contentNameFor($db, (string)$e['info_hash']);
    contentNotify($db, $e['user_id'] !== null ? (int)$e['user_id'] : null,
                  __('notify.content_proposal_rejected', ['name' => $name]),
                  __('notify.content_proposal_rejected_body', ['name' => $name]));
    return true;
}
