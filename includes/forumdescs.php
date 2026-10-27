<?php
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/images.php';

const FORUM_DESC_MAX_BODY      = 4194304;
const FORUM_DESC_MAX_TOPICS    = 25;
const FORUM_DESC_MAX_HASHES    = 20;
const FORUM_DESC_CHARS_CAP     = 1000000;
const FORUM_DESC_MODES         = ['off', 'queue', 'publish'];
const FORUM_DESC_CONFLICTS     = ['proposal', 'keep', 'replace'];
const FORUM_DESC_STATUSES      = ['created', 'queued', 'updated', 'replaced', 'proposed', 'kept', 'unchanged', 'too_long', 'invalid'];
const FORUM_DESC_SKIPPED       = ['not_registered', 'registered_elsewhere', 'banned', 'other_topic', 'removed', 'declined', 'stale'];
const FORUM_DESC_HIDDEN        = ['withdrawn', 'released'];
const FORUM_DESC_SUPERSEDED    = 'superseded by a newer version from the forum';

function forumDescMode(array $client): string {
    $m = (string)($client['describe_mode'] ?? 'off');
    return in_array($m, FORUM_DESC_MODES, true) ? $m : 'off';
}

function forumDescConflict(array $cfg): string {
    $c = (string)($cfg['forum_desc_conflict'] ?? 'proposal');
    return in_array($c, FORUM_DESC_CONFLICTS, true) ? $c : 'proposal';
}

function forumDescLimit(array $cfg, $sent): int {
    $n = is_numeric($sent) ? (int)$sent : 0;
    return $n > 0 ? min($n, FORUM_DESC_CHARS_CAP) : richtextMaxChars($cfg);
}

function forumDescEnabled(array $cfg, array $client): bool {
    return forumDescMode($client) !== 'off' && ($cfg['wl_allow_description'] ?? '0') === '1';
}

function forumDescSha(string $format, string $text): string {
    return hash('sha256', $format . "\n" . $text);
}

function forumDescName(string $s): string {
    $s = preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $s) ?? '';
    return mb_substr(trim($s), 0, 191);
}

function forumDescTime($v): ?string {
    if (is_int($v) || (is_string($v) && ctype_digit($v))) {
        $ts = (int)$v;
    } elseif (is_string($v) && $v !== '') {
        $ts = strtotime($v);
        if ($ts === false) return null;
    } else {
        return null;
    }
    return $ts > 0 ? date('Y-m-d H:i:s', min($ts, time())) : null;
}

function forumDescId($v): int {
    if (is_string($v) && ctype_digit($v) && strlen($v) <= 10) $v = (int)$v;
    return is_int($v) && $v >= 1 && $v <= 4294967295 ? $v : 0;
}

function forumDescClearForResend(PDO $db, array $cfg, string $kind, int $id): bool
{
    $rec = contentRowById($db, $kind, $id);
    if ($rec === null) return false;
    $hash = strtolower((string)$rec['info_hash']);
    $st = $db->prepare("SELECT DISTINCT image_id FROM image_refs WHERE src = ? AND src_key = ?");
    $st->execute([$kind, (string)$id]);
    $images = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (contentOccupied($rec)) contentDelete($db, $cfg, $rec + ['kind' => $kind], ['id' => 0], 'any', ['notify' => false]);
    $db->prepare("DELETE FROM forum_descs WHERE info_hash = ?")->execute([$hash]);
    $left = $db->prepare("SELECT 1 FROM image_refs WHERE image_id = ? LIMIT 1");
    foreach ($images as $img) {
        $row = function_exists('imagesRow') ? imagesRow($db, $img) : null;
        if ($row === null || (string)$row['source'] !== 'copy') continue;
        $left->execute([$img]);
        if ($left->fetchColumn()) continue;
        imagesDelete($db, $img);
    }
    return true;
}

function forumDescTopic($t): ?array {
    if (!is_array($t)) return null;
    $disc = forumDescId($t['discussion_id'] ?? null);
    $post = forumDescId($t['post_id'] ?? null);
    if ($disc < 1 || $post < 1) return null;
    $author = is_array($t['author'] ?? null) ? $t['author'] : [];
    $extId = isset($author['id']) && (is_string($author['id']) || is_int($author['id'])) ? mb_substr(trim((string)$author['id']), 0, 191) : '';
    $content = is_string($t['content'] ?? null) ? (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace(["\r\n", "\r"], "\n", $t['content'])) : null;
    $hashes = [];
    foreach (is_array($t['hashes'] ?? null) ? $t['hashes'] : [] as $h) {
        $h = is_string($h) ? strtolower(trim($h)) : '';
        if (!in_array($h, $hashes, true)) $hashes[] = $h;
    }
    return [
        'discussion_id' => $disc,
        'post_id'       => $post,
        'url'           => is_string($t['url'] ?? null) ? safeHttpUrl($t['url'], 500) : '',
        'author_id'     => $extId,
        'author_name'   => isset($author['name']) && is_string($author['name']) ? forumDescName($author['name']) : '',
        'edited_at'     => forumDescTime($t['edited_at'] ?? null),
        'format'        => is_string($t['format'] ?? null) && $t['format'] !== '' ? mb_substr($t['format'], 0, 16) : 'flarum',
        'content'       => $content,
        'chars'         => $content !== null ? mb_strlen($content) : (isset($t['chars']) && is_numeric($t['chars']) ? max(0, (int)$t['chars']) : 0),
        'hashes'        => $hashes,
        'visible'       => ($t['visible'] ?? true) !== false,
        'gone'          => ($t['gone'] ?? false) === true,
        'audience'      => ($t['audience'] ?? 'guests') === 'members' ? 'members' : 'guests',
        'restore'       => ($t['restore'] ?? false) === true,
        'tags'          => function_exists('tagsForumList') ? tagsForumList($t['tags'] ?? null) : null,
    ];
}

function forumDescAccount(PDO $db, array $client, string $extId): ?int {
    if ($extId === '') return null;
    $via = (int)($client['describe_accounts'] ?? 0);
    $st = $db->prepare("SELECT i.user_id FROM user_identities i JOIN users u ON u.id = i.user_id
                         WHERE i.client_id = ? AND i.external_id = ? LIMIT 1");
    $st->execute([$via > 0 ? $via : (int)$client['id'], $extId]);
    $id = $st->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function forumDescAccountKeys(PDO $db): array {
    $st = $db->query("SELECT id, label FROM api_clients WHERE scope IN ('users', 'all') ORDER BY id");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['id' => (int)$r['id'], 'label' => (string)$r['label']];
    return $out;
}

function forumDescAccountsClean(PDO $db, $raw, int $selfId): ?int {
    $id = is_numeric($raw) ? (int)$raw : 0;
    if ($id < 1 || $id === $selfId) return null;
    foreach (forumDescAccountKeys($db) as $k) if ($k['id'] === $id) return $id;
    return null;
}

function forumDescOwns(array $w, int $clientId): bool {
    $by = $w['api_client_id'] !== null ? (int)$w['api_client_id'] : null;
    return $by === $clientId || ($by === null && (string)$w['source'] === 'forum');
}

function forumDescBound(array $w, bool $firstOnly = true): ?int {
    $ref = is_string($w['source_ref'] ?? null) ? json_decode((string)$w['source_ref'], true) : null;
    if (!is_array($ref) || ($firstOnly && ($ref['first'] ?? false) !== true)) return null;
    $id = forumDescId($ref['discussion_id'] ?? null);
    return $id > 0 ? $id : null;
}

function forumDescRef(array $topic): string {
    $ref = ['post_id' => $topic['post_id'], 'discussion_id' => $topic['discussion_id'], 'first' => true];
    if ($topic['url'] !== '') $ref['url'] = mb_substr($topic['url'], 0, 300);
    return (string)json_encode($ref, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function forumDescFor(PDO $db, string $hash): ?array {
    $st = $db->prepare("SELECT * FROM forum_descs WHERE info_hash = ? LIMIT 1");
    $st->execute([strtolower($hash)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function forumDescLimits(PDO $db, string $hash): ?array {
    try {
        $st = $db->prepare("SELECT forum_chars, forum_images, forum_links FROM forum_descs WHERE info_hash = ? AND import_sha IS NOT NULL LIMIT 1");
        $st->execute([strtolower($hash)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null;
    }
    return $r ? ['chars' => (int)$r['forum_chars'], 'images' => (int)$r['forum_images'], 'links' => (int)$r['forum_links']] : null;
}

function forumDescMembersOnly(PDO $db, string $hash): bool {
    try {
        $st = $db->prepare("SELECT 1 FROM forum_descs WHERE info_hash = ? AND audience = 'members' AND import_sha IS NOT NULL LIMIT 1");
        $st->execute([strtolower($hash)]);
        return $st->fetchColumn() !== false;
    } catch (\Throwable $e) {
        return false;
    }
}

function forumDescLimitsBackfill(PDO $db, array $cfg): int {
    try {
        if ($db->query("SELECT 1 FROM settings WHERE `key` = 'schema_once_v101_forum_limits'")->fetchColumn() !== false) return 0;
        $rows = $db->query("SELECT f.info_hash, w.description, w.description_format FROM forum_descs f
                              JOIN whitelist w ON w.info_hash = f.info_hash
                             WHERE f.import_sha IS NOT NULL AND w.description IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $up = $db->prepare("UPDATE forum_descs SET forum_images = GREATEST(forum_images, ?), forum_links = GREATEST(forum_links, ?) WHERE info_hash = ?");
        foreach ($rows as $r) {
            $c = richtextCount((string)$r['description'], (string)$r['description_format'], $cfg);
            $up->execute([min(65535, $c['images']), min(65535, $c['links']), $r['info_hash']]);
        }
        if (function_exists('schemaOnce')) schemaOnce($db, 'v101_forum_limits');
        return count($rows);
    } catch (\Throwable $e) {
        error_log('[forumdescs] the record limits were not filled in: ' . $e->getMessage());
        return 0;
    }
}

function forumDescSave(PDO $db, array $r): void {
    $db->prepare("INSERT INTO forum_descs (info_hash, client_id, discussion_id, post_id, url, author_ext_id, author_name,
                         author_user_id, forum_at, forum_sha, forum_chars, forum_images, forum_links, audience, import_sha, state, note, edit_id, imported_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), discussion_id = VALUES(discussion_id),
                         post_id = VALUES(post_id), url = VALUES(url), author_ext_id = VALUES(author_ext_id),
                         author_name = VALUES(author_name), author_user_id = VALUES(author_user_id), forum_at = VALUES(forum_at),
                         forum_sha = VALUES(forum_sha), forum_chars = VALUES(forum_chars), forum_images = VALUES(forum_images),
                         forum_links = VALUES(forum_links), audience = VALUES(audience), import_sha = VALUES(import_sha),
                         state = VALUES(state), note = VALUES(note), edit_id = VALUES(edit_id), imported_at = VALUES(imported_at)")
       ->execute([$r['info_hash'], $r['client_id'], $r['discussion_id'], $r['post_id'], $r['url'], $r['author_ext_id'],
                  $r['author_name'], $r['author_user_id'], $r['forum_at'], $r['forum_sha'], $r['forum_chars'],
                  min(65535, (int)($r['forum_images'] ?? 0)), min(65535, (int)($r['forum_links'] ?? 0)),
                  ($r['audience'] ?? 'guests') === 'members' ? 'members' : 'guests', $r['import_sha'], $r['state'], $r['note'] !== null ? mb_substr((string)$r['note'], 0, 255) : null, $r['edit_id'],
                  $r['imported_at'] ?? null]);
}

function forumDescWrite(PDO $db, array $w, string $fmt, string $text, string $status, ?int $acct, bool $keepCredits): void {
    $prev = $w['content_user_id'] !== null ? (int)$w['content_user_id'] : null;
    $credits = ($keepCredits && $prev === $acct) ? $w['content_credits'] : contentCreditsStart($acct);
    $db->prepare("UPDATE whitelist SET description = ?, description_format = ?, content_status = ?, content_user_id = ?,
                         content_reviewed_at = NULL, content_rejected_note = NULL, content_credits = ? WHERE id = ?")
       ->execute([$text, $fmt, $status, $acct, $credits, (int)$w['id']]);
    if (function_exists('imagesRefsTouch')) imagesRefsTouch($db, null, 'wl', (string)$w['id']);
}

function forumDescWithdraw(PDO $db, ?int $editId): void {
    if ($editId === null || $editId < 1) return;
    $db->prepare("UPDATE wl_content_edits SET status = 'rejected', reviewed_at = NOW(), note = ? WHERE id = ? AND status = 'pending'")
       ->execute([FORUM_DESC_SUPERSEDED, $editId]);
    if (function_exists('imagesRefsTouch')) imagesRefsTouch($db, null, 'edit', (string)$editId);
}

function forumDescPropose(PDO $db, array $cfg, array $w, string $hash, string $fmt, string $text, ?int $acct, ?int $editId): int {
    if ($editId !== null && $editId > 0) {
        $st = $db->prepare("SELECT id FROM wl_content_edits WHERE id = ? AND status = 'pending' AND whitelist_id = ? LIMIT 1 FOR UPDATE");
        $st->execute([$editId, (int)$w['id']]);
        if ($st->fetchColumn() !== false) {
            $db->prepare("UPDATE wl_content_edits SET description = ?, description_format = ?, source_url = ?, kind = 'rewrite', user_id = ?
                           WHERE id = ?")
               ->execute([$text, $fmt, $w['source_url'], $acct, $editId]);
            if (function_exists('imagesRefsTouch')) imagesRefsTouch($db, $cfg, 'edit', (string)$editId);
            return $editId;
        }
    }
    $db->prepare("INSERT INTO wl_content_edits (whitelist_id, hash_content_id, info_hash, source_url, description, description_format,
                         kind, ip, user_id)
                  VALUES (?, NULL, ?, ?, ?, ?, 'rewrite', ?, ?)")
       ->execute([(int)$w['id'], $hash, $w['source_url'], $text, $fmt, mb_substr(getClientIp($cfg), 0, 45), $acct]);
    $id = (int)$db->lastInsertId();
    if (function_exists('imagesRefsTouch')) imagesRefsTouch($db, $cfg, 'edit', (string)$id);
    return $id;
}

function forumDescImport(PDO $db, array $cfg, array $client, array $topic, string $hash, int $max, array &$memo = []): array {
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return ['status' => 'invalid', 'detail' => 'not an info hash'];
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $r = forumDescImportLocked($db, $cfg, $client, $topic, $hash, $max, $memo);
        if ($own) $db->commit();
        return $r;
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        $memo = [];
        throw $e;
    }
}

function forumDescImportLocked(PDO $db, array $cfg, array $client, array $topic, string $hash, int $max, array &$memo = []): array {
    $cid = (int)$client['id'];
    $st = $db->prepare("SELECT id, banned, review_status, api_client_id, source, source_ref, source_url, description, description_format,
                               content_status, content_user_id, content_credits
                          FROM whitelist WHERE info_hash = ? LIMIT 1 FOR UPDATE");
    $st->execute([$hash]);
    $w = $st->fetch(PDO::FETCH_ASSOC);
    if (!$w) return ['status' => 'not_registered', 'detail' => null];
    if (!forumDescOwns($w, $cid)) return ['status' => 'registered_elsewhere', 'detail' => null];
    if ((int)$w['banned'] === 1) return ['status' => 'banned', 'detail' => null];
    if (in_array((string)$w['review_status'], ['pending', 'rejected'], true)) {
        return ['status' => 'not_registered', 'detail' => 'registration ' . $w['review_status']];
    }
    $st = $db->prepare("SELECT * FROM forum_descs WHERE info_hash = ? LIMIT 1");
    $st->execute([$hash]);
    $f = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($f !== null && ((int)$f['client_id'] !== $cid
                        || ((int)$f['discussion_id'] !== $topic['discussion_id'] && $f['import_sha'] !== null))) {
        return ['status' => 'other_topic', 'detail' => null];
    }
    $bound = forumDescBound($w);
    if ($bound !== null && $bound !== $topic['discussion_id'] && ($f === null || (int)$f['discussion_id'] !== $topic['discussion_id'])) {
        return ['status' => 'other_topic', 'detail' => null];
    }
    if ($f !== null && (int)$f['discussion_id'] === $topic['discussion_id'] && $f['forum_at'] !== null
        && $topic['edited_at'] !== null && strcmp($topic['edited_at'], (string)$f['forum_at']) < 0) {
        return ['status' => 'stale', 'detail' => null];
    }
    if ($w['api_client_id'] === null) {
        $db->prepare("UPDATE whitelist SET api_client_id = ?, source_ref = COALESCE(source_ref, ?) WHERE id = ? AND api_client_id IS NULL")
           ->execute([$cid, forumDescRef($topic), (int)$w['id']]);
    }
    if (($topic['tags'] ?? null) !== null && function_exists('tagsForumSet')) tagsForumSet($db, $cfg, $cid, $hash, $topic['tags']);

    $who = 'account:' . $cid . ':' . $topic['author_id'];
    if (!array_key_exists($who, $memo)) $memo[$who] = forumDescAccount($db, $client, $topic['author_id']);
    $fmt = $topic['format'];
    $sha = $topic['content'] !== null ? forumDescSha($fmt, $topic['content']) : null;
    $counts = $f !== null && $sha !== null && $f['forum_sha'] !== null && hash_equals((string)$f['forum_sha'], $sha)
        ? ['images' => (int)($f['forum_images'] ?? 0), 'links' => (int)($f['forum_links'] ?? 0)]
        : ($topic['content'] !== null && $topic['chars'] <= $max ? richtextCount($topic['content'], $fmt, $cfg) : ['images' => 0, 'links' => 0]);
    $row = [
        'info_hash'      => $hash,
        'client_id'      => $cid,
        'discussion_id'  => $topic['discussion_id'],
        'post_id'        => $topic['post_id'],
        'url'            => $topic['url'] !== '' ? $topic['url'] : ($f['url'] ?? null),
        'author_ext_id'  => $topic['author_id'] !== '' ? $topic['author_id'] : null,
        'author_name'    => $topic['author_name'] !== '' ? $topic['author_name'] : null,
        'author_user_id' => $memo[$who],
        'forum_at'       => $topic['edited_at'],
        'forum_sha'      => $sha,
        'forum_chars'    => $topic['chars'],
        'forum_images'   => $counts['images'],
        'forum_links'    => $counts['links'],
        'audience'       => $topic['audience'],
        'import_sha'     => $f['import_sha'] ?? null,
        'state'          => $f['state'] ?? 'imported',
        'note'           => $f['note'] ?? null,
        'edit_id'        => isset($f['edit_id']) ? (int)$f['edit_id'] : null,
        'imported_at'    => $f['imported_at'] ?? null,
    ];
    $done = static function (string $status, array $set = [], ?string $detail = null) use ($db, $row): array {
        forumDescSave($db, array_merge($row, $set));
        return ['status' => $status, 'detail' => $detail];
    };

    if ($topic['content'] === null || $topic['chars'] > $max) {
        $d = $topic['chars'] . '/' . $max;
        return $done('too_long', ['state' => 'too_long', 'note' => $d], $d);
    }
    $text = trim($topic['content']);
    if ($text === '') return $done('invalid', ['state' => 'invalid', 'note' => 'empty'], 'the first post is empty');

    $occupied = contentOccupied(['description' => $w['description'], 'source_url' => $w['source_url']]);
    $mode = forumDescMode($client);
    $status = (string)$w['content_status'];
    $fromForum = $f !== null && $f['import_sha'] !== null;
    $untouched = $fromForum && $w['description'] !== null
              && hash_equals((string)$f['import_sha'], forumDescSha((string)$w['description_format'], (string)$w['description']));
    $removed = $fromForum && !$occupied;
    $cleared = $f !== null && !$fromForum && !$occupied && in_array($f['state'], ['proposed', 'drift'], true);
    $lost = false;
    if ($occupied && $f !== null && $f['state'] === 'proposed') {
        $st = $db->prepare("SELECT 1 FROM wl_content_edits WHERE id = ? AND status = 'pending' LIMIT 1");
        $st->execute([(int)$f['edit_id']]);
        $lost = $st->fetchColumn() === false;
    }
    $held = ($f['note'] ?? null) === 'review';
    $release = $untouched && $status === 'pending' && $mode === 'publish' && !$held;
    $publish = static function () use ($db, $cfg, $w): void {
        $db->prepare("UPDATE whitelist SET content_status = 'approved' WHERE id = ? AND content_status = 'pending'")->execute([(int)$w['id']]);
        if (function_exists('imagesRefsTouch')) imagesRefsTouch($db, $cfg, 'wl', (string)$w['id']);
    };
    $bringBack = !empty($topic['restore']) && ($removed || in_array((string)($f['state'] ?? ''), ['removed', 'declined'], true));
    if (!$bringBack && !$cleared && !$lost && $f !== null && $f['forum_sha'] !== null && hash_equals((string)$f['forum_sha'], (string)$row['forum_sha'])) {
        if ($removed || $f['state'] === 'removed') return $done('removed', ['state' => 'removed']);
        if ($f['state'] === 'declined') return $done('declined');
        if ($f['state'] === 'imported' && $release) {
            $publish();
            return $done('updated');
        }
        if (in_array($f['state'], ['imported', 'proposed', 'drift'], true)) return $done('unchanged');
    }

    if (!in_array($fmt, richtextFormats($cfg), true)) {
        return $done('invalid', ['state' => 'invalid', 'note' => 'That format is not accepted here.'], 'That format is not accepted here.');
    }

    if ($f !== null && function_exists('imagesRemoteRecheckSoon')) imagesRemoteRecheckSoon($db, $cfg, $text);
    $acct = $row['author_user_id'];
    $store = static function (bool $queue) use (&$memo, $db, $cfg, $text): string {
        $k = ($queue ? 'store:' : 'view:') . sha1($text);
        return $memo[$k] ??= trim(imagesRemoteSettle($db, $cfg, $text, $queue));
    };
    $imported = static fn(string $s, bool $review = false): array
        => ['import_sha' => forumDescSha($fmt, $s), 'state' => 'imported', 'note' => $review ? 'review' : null, 'edit_id' => null,
            'imported_at' => $topic['edited_at'] ?? date('Y-m-d H:i:s')];

    if (!$occupied || !in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $review = empty($topic['restore']) && ($removed || $cleared || ($f !== null && $f['state'] === 'removed'));
        $pending = $mode === 'queue' || $review;
        $s = $store(true);
        forumDescWithdraw($db, $row['edit_id']);
        forumDescWrite($db, $w, $fmt, $s, $pending ? 'pending' : 'approved', $acct, false);
        return $done($pending ? 'queued' : 'created', $imported($s, $review));
    }

    $view = $store(false);
    if ((string)$w['description_format'] === $fmt && (string)$w['description'] === $view) {
        forumDescWithdraw($db, $row['edit_id']);
        if ($status === 'rejected') {
            if (empty($topic['restore'])) {
                return $done('declined', ['import_sha' => forumDescSha($fmt, $view), 'state' => 'declined', 'note' => null, 'edit_id' => null]);
            }
            $next = $mode === 'publish' ? 'approved' : 'pending';
            $db->prepare("UPDATE whitelist SET content_status = ?, content_reviewed_at = NULL, content_rejected_note = NULL WHERE id = ?")
               ->execute([$next, (int)$w['id']]);
            if (function_exists('imagesRefsTouch')) imagesRefsTouch($db, $cfg, 'wl', (string)$w['id']);
            return $done($next === 'approved' ? 'updated' : 'queued', $imported($view));
        }
        if ($release) $publish();
        return $done($release ? 'updated' : 'unchanged', $imported($view, $held));
    }

    if ($untouched && !($status === 'approved' && $mode === 'queue')) {
        $back = $status === 'rejected' && !empty($topic['restore']);
        $review = ($status === 'rejected' && !$back) || ($status === 'pending' && $held);
        $next = ($status === 'approved' || ($status === 'pending' && $mode === 'publish' && !$review) || ($back && $mode === 'publish')) ? 'approved' : 'pending';
        $s = $store(true);
        if ($status === 'approved') contentArchive($db, 'wl', (int)$w['id'], $hash, $w);
        forumDescWithdraw($db, $row['edit_id']);
        forumDescWrite($db, $w, $fmt, $s, $next, $acct, true);
        return $done($status === 'rejected' && $next !== 'approved' ? 'queued' : 'updated', $imported($s, $review));
    }

    $policy = $untouched ? 'proposal' : forumDescConflict($cfg);
    if (!$fromForum && $policy === 'replace') $policy = 'proposal';
    if ($policy === 'proposal' && max(0, min(50, (int)($cfg['wl_edit_max_pending'] ?? 3))) === 0) $policy = 'keep';
    if ($policy === 'keep') {
        forumDescWithdraw($db, $row['edit_id']);
        return $done('kept', ['state' => 'drift', 'note' => null, 'edit_id' => null]);
    }
    $s = $store(true);
    if ($policy === 'replace' && $mode === 'publish') {
        contentArchive($db, 'wl', (int)$w['id'], $hash, $w);
        forumDescWithdraw($db, $row['edit_id']);
        forumDescWrite($db, $w, $fmt, $s, 'approved', $acct, false);
        return $done('replaced', $imported($s));
    }
    $editId = forumDescPropose($db, $cfg, $w, $hash, $fmt, $s, $acct, $row['edit_id']);
    return $done('proposed', ['state' => 'proposed', 'note' => null, 'edit_id' => $editId]);
}

function forumDescTakeDown(PDO $db, array $w): void {
    if ($w['source_url'] === null) {
        contentClear($db, 'wl', (int)$w['id']);
        return;
    }
    $db->prepare("UPDATE whitelist SET description = NULL WHERE id = ?")->execute([(int)$w['id']]);
    if (function_exists('imagesRefsTouch')) imagesRefsTouch($db, null, 'wl', (string)$w['id']);
}

function forumDescHide(PDO $db, array $client, array $topic, string $hash): array {
    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return ['status' => 'invalid', 'detail' => 'not an info hash'];
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $r = forumDescHideLocked($db, $client, $topic, $hash);
        if ($own) $db->commit();
        return $r;
    } catch (\Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function forumDescHideLocked(PDO $db, array $client, array $topic, string $hash): array {
    $cid = (int)$client['id'];
    $st = $db->prepare("SELECT id, api_client_id, source, source_ref, source_url, description, description_format
                          FROM whitelist WHERE info_hash = ? LIMIT 1 FOR UPDATE");
    $st->execute([$hash]);
    $w = $st->fetch(PDO::FETCH_ASSOC);
    if (!$w) return ['status' => 'not_registered', 'detail' => null];
    if (!forumDescOwns($w, $cid)) return ['status' => 'registered_elsewhere', 'detail' => null];
    $disc = $topic['discussion_id'];
    $f = forumDescFor($db, $hash);
    $bound = forumDescBound($w);
    if ($f !== null ? ((int)$f['client_id'] !== $cid || (int)$f['discussion_id'] !== $disc) : ($bound !== null && $bound !== $disc)) {
        return ['status' => 'other_topic', 'detail' => null];
    }
    if (function_exists('tagsForumClear')) tagsForumClear($db, $cid, $hash);
    if ($f !== null && !in_array((string)$f['state'], ['declined', 'removed'], true)) {
        if ($f['import_sha'] !== null && $w['description'] !== null
            && hash_equals((string)$f['import_sha'], forumDescSha((string)$w['description_format'], (string)$w['description']))) {
            forumDescTakeDown($db, $w);
        }
        forumDescWithdraw($db, $f['edit_id'] !== null ? (int)$f['edit_id'] : null);
        $db->prepare("UPDATE forum_descs SET import_sha = NULL, imported_at = NULL, state = 'withdrawn', note = NULL, edit_id = NULL WHERE info_hash = ?")
           ->execute([$hash]);
    }
    if (!$topic['gone']) return ['status' => 'withdrawn', 'detail' => null];
    $db->prepare("DELETE FROM forum_descs WHERE info_hash = ?")->execute([$hash]);
    if (forumDescBound($w, false) === $disc) $db->prepare("UPDATE whitelist SET source_ref = NULL WHERE id = ?")->execute([(int)$w['id']]);
    return ['status' => 'released', 'detail' => null];
}

function forumDescProposalDone(PDO $db, array $e, bool $applied): void {
    if ($applied) {
        $db->prepare("UPDATE forum_descs SET import_sha = ?, state = 'imported', note = NULL, edit_id = NULL, imported_at = forum_at WHERE edit_id = ?")
           ->execute([forumDescSha((string)$e['description_format'], (string)($e['description'] ?? '')), (int)$e['id']]);
        return;
    }
    $db->prepare("UPDATE forum_descs SET state = 'declined', edit_id = NULL WHERE edit_id = ?")->execute([(int)$e['id']]);
}

function forumDescDecided(PDO $db, string $hash, string $format, string $text, bool $approved): void {
    if ($approved) {
        $db->prepare("UPDATE forum_descs SET state = 'imported' WHERE info_hash = ? AND state = 'declined' AND import_sha = ?")
           ->execute([strtolower($hash), forumDescSha($format, $text)]);
        return;
    }
    $db->prepare("UPDATE forum_descs SET state = 'declined' WHERE info_hash = ? AND state = 'imported' AND import_sha = ?")
       ->execute([strtolower($hash), forumDescSha($format, $text)]);
}

function forumDescRewritten(PDO $db, int $whitelistId, string $old, string $new): void {
    $st = $db->prepare("SELECT f.info_hash, f.import_sha, w.description_format FROM forum_descs f
                          JOIN whitelist w ON w.info_hash = f.info_hash WHERE w.id = ? LIMIT 1");
    $st->execute([$whitelistId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['import_sha'] === null) return;
    $fmt = (string)$r['description_format'];
    if (!hash_equals((string)$r['import_sha'], forumDescSha($fmt, $old))) return;
    $db->prepare("UPDATE forum_descs SET import_sha = ? WHERE info_hash = ? AND import_sha = ?")
       ->execute([forumDescSha($fmt, $new), $r['info_hash'], $r['import_sha']]);
}

function forumDescInfo(PDO $db, array $cfg, string $hash, ?array $rec, bool $moderator): ?array {
    $f = forumDescFor($db, $hash);
    if ($f === null) return null;
    $published = $rec !== null && ($rec['content_status'] ?? '') === 'approved' && contentOccupied($rec);
    $author = ($rec['content_user_id'] ?? null) !== null ? (int)$rec['content_user_id'] : null;
    $linked = $f['author_user_id'] !== null ? (int)$f['author_user_id'] : null;
    $shown = $published && $f['import_sha'] !== null && $author === $linked;
    $state = (string)$f['state'];
    $tell = $moderator ? $state !== 'imported' : ($state === 'too_long' && !$published);
    if (!$shown && !$tell) return null;
    $url = $f['url'] !== null ? safeHttpUrl((string)$f['url'], 500) : '';
    return [
        'url'     => $url !== '' ? $url : null,
        'trusted' => $url !== '' && richtextIsTrusted($url, $cfg),
        'shown'   => $shown,
        'author'  => $shown && $author === null && $f['author_name'] !== null ? (string)$f['author_name'] : null,
        'at'      => $f['forum_at'],
        'shown_at' => $shown ? ($f['imported_at'] ?? $f['forum_at']) : null,
        'state'   => $tell ? $state : null,
        'note'    => $tell && $moderator && $state === 'invalid' ? $f['note'] : null,
        'chars'   => $tell && $state === 'too_long' ? (int)$f['forum_chars'] : null,
        'max'     => $tell && $state === 'too_long' ? (int)(explode('/', (string)$f['note'])[1] ?? 0) : null,
    ];
}
