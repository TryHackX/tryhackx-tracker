<?php
/**
 * POST admin/whitelist_review — approve or turn down partner submissions that are waiting.
 *
 *   {op:'approve'|'reject', ids:[…]|hashes:[…], note?:'…'}
 *
 * ── what this actually changes ─────────────────────────────────────────────────────────────────
 * `review_status`, and nothing else. It is the one column the accesslist generator reads besides
 * `banned` and `probe_status`, so approving a row is what makes the tracker serve it and turning one
 * down is what keeps it from being served. Nothing here deletes a row: a submission that was turned
 * down is a decision somebody made, and a decision worth recording is worth being able to see later.
 *
 * Behind panel.whitelist.content — the same permission as approving a description. Both are "a
 * person decided whether this belongs on the site", and an operator who trusts somebody with one
 * has already answered the question about the other.
 */
requirePost();
if (!panelCan($db, $cfg, 'panel.whitelist.content')) {
    jsonResponse(['error' => __('api.common.forbidden')], 403);
}

$input = readJsonBody();
$op = (string)($input['op'] ?? '');
if (!in_array($op, ['approve', 'reject'], true)) jsonResponse(['error' => __('api.index.unknown_op')], 400);

$ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? [])), static fn($i) => $i > 0));
$hashes = [];
foreach ((array)($input['hashes'] ?? []) as $h) {
    $h = strtolower(trim((string)$h));
    if (preg_match('/^[0-9a-f]{40}$/', $h)) $hashes[] = $h;
}
if (!$ids && !$hashes) jsonResponse(['error' => __('api.common.invalid_id')], 400);

$note = mb_substr(trim((string)($input['note'] ?? '')), 0, 255) ?: null;
$status = $op === 'approve' ? 'approved' : 'rejected';

$changed = 0;
// In chunks, and by whichever key the caller had: the table can hold a partner's whole backlog and
// a native prepare is capped at 65 535 placeholders.
foreach ([['id', $ids], ['info_hash', $hashes]] as [$col, $keys]) {
    foreach (array_chunk($keys, WL_SQL_IN_CHUNK) as $chunk) {
        if (!$chunk) continue;
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        // Only rows that are WAITING. Re-approving something already approved would rewrite
        // reviewed_at for no reason, and approving something a colleague turned down a minute ago
        // should be a deliberate act, not the tail of a bulk selection.
        $st = $db->prepare("UPDATE whitelist SET review_status = ?, review_note = ?, reviewed_at = NOW()
                             WHERE review_status = 'pending' AND `$col` IN ($ph)");
        $st->execute(array_merge([$status, $note], $chunk));
        $changed += $st->rowCount();
    }
}

// Approving changes what the tracker serves, so the accesslist has to be written again. Turning one
// down does not — a pending row was never in the file — but regenerating is cheap next to being
// wrong about it, and the generator has its own guard against writing an empty list.
$regen = null;
if ($changed > 0 && $op === 'approve') {
    try { $regen = whitelistRegenerate($db, $cfg); } catch (\Throwable $e) { $regen = ['ok' => false, 'error' => $e->getMessage()]; }
}

auditLog($db, 'whitelist.review', ['target_type' => 'whitelist', 'summary' => $op . ' x' . $changed,
                                   'detail' => ['op' => $op, 'changed' => $changed, 'note' => $note]]);
jsonResponse(['success' => true, 'changed' => $changed, 'status' => $status, 'regen' => $regen]);
