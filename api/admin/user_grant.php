<?php
/**
 * Admin: grant a group to a user.
 * Body: {id, group (slug or numeric id), duration: 1d|7d|14d|1m|3m|6m|1y|permanent|custom,
 *        from?: 'Y-m-d[ H:i]' (custom only, empty = now), to?: 'Y-m-d[ H:i]' (custom; empty = permanent),
 *        note?, email?: bool (also send an email if the user has one)}
 * Duration grants EXTEND an existing membership (from max(now, current expiry)); custom replaces.
 */
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$u = userFindById($db, $id);
if (!$u) jsonResponse(['error' => 'User not found'], 404);

$groupRef = trim((string)($input['group'] ?? ''));
$group = ctype_digit($groupRef)
    ? (function () use ($db, $groupRef) { $s = $db->prepare("SELECT * FROM user_groups WHERE id = ?"); $s->execute([(int)$groupRef]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; })()
    : userGroupBySlug($db, $groupRef);
if (!$group) jsonResponse(['error' => 'Group not found'], 404);
if ($group['slug'] === 'guest') jsonResponse(['error' => 'The guest group is the implicit baseline — it cannot be granted'], 400);

$parseDt = function (string $s, bool $endOfDay): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) $s .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
    elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    $t = strtotime($s);
    return $t === false ? '' : date('Y-m-d H:i:s', $t);
};

$duration = (string)($input['duration'] ?? 'permanent');
$grantedAt = null;
if ($duration === 'custom') {
    $from = $parseDt((string)($input['from'] ?? ''), false);
    $to = $parseDt((string)($input['to'] ?? ''), true);
    if ($from === '' || $to === '') jsonResponse(['error' => 'Invalid date — use YYYY-MM-DD or YYYY-MM-DD HH:MM'], 400);
    if ($from !== null && $to !== null && $to <= $from) jsonResponse(['error' => '"To" must be after "from"'], 400);
    // an already-past expiry would be reaped by the next janitor tick a minute later (with a bogus
    // "access expired" notification) — reject it like the v1 endpoint does
    if ($to !== null && strtotime($to) <= time()) jsonResponse(['error' => '"To" must be in the future'], 400);
    $grantedAt = $from;    // null = now
    $expiresAt = $to;      // null = permanent
} else {
    $expiresAt = userDurationExpiry($db, $id, (int)$group['id'], $duration);
    if ($expiresAt === '') jsonResponse(['error' => 'Invalid duration (1d | 7d | 14d | 1m | 3m | 6m | 1y | permanent | custom)'], 400);
}

$note = mb_substr(trim((string)($input['note'] ?? '')), 0, 255);
$res = userGrantGroup($db, $id, (int)$group['id'], $expiresAt, 'admin', $note, true, $grantedAt);
if (!empty($input['email'])) {
    $until = $expiresAt === null ? 'permanently' : 'until ' . $expiresAt;
    userNotifyMail($db, $cfg, $u, ($cfg['site_name'] ?? 'Tracker') . ' — you are now in the "' . $group['name'] . '" group',
        'Access granted ' . ($grantedAt !== null ? 'from ' . $grantedAt . ' ' : '') . $until . '.' . ($note !== '' ? "\nNote: " . $note : ''));
}
jsonResponse(['success' => true, 'group' => $group['slug'], 'granted_at' => $res['granted_at'], 'expires_at' => $res['expires_at']]);
