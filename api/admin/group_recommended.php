<?php
/**
 * Admin: a seeded group's RECOMMENDED permission set (1.72.0) — see what is missing and what a reset would take away,
 * then add what is missing or reset to it. The set is userGroupRecommended() (includes/users.php): a group's preset,
 * or for admin every registered id.
 *
 *   GET  ?id=N[&consent=1]                       the preview: {group, recommended, add, remove, consent, blanket}
 *   POST {id, mode: add|reset, consent, expect}   apply it. `expect` is what the preview showed ({add, remove}); a
 *        group that changed since then is not touched — 409 `changed` with the preview as it is now.
 *
 * Owner-only, as group editing is: this endpoint is absent from adminEndpointPermission() in api.php on purpose, and
 * a moderator's session gets that router's 403. Nothing is written but through userGroupApplyRecommended(), which
 * never lets the Admin group lose a capability and forgets the permission memo; the audit line
 * (`group.recommend`) is userGroupRecommendedAudit()'s, the same one tools/groups.php writes, and a request that
 * changes nothing writes none.
 */
$grAnswer = function (array $g, array $plan) use ($db): array {
    $st = $db->prepare("SELECT COUNT(*) FROM user_group_members WHERE group_id = ?");
    $st->execute([(int)$g['id']]);
    $slug = (string)$g['slug'];
    // The set's name and line in the reader's language (a.users.rec_name_* / rec_about_*: the presets' own English);
    // the preset's words where the dictionary has none.
    $label = userGroupRecommendedLabel($slug);
    foreach (['label' => 'a.users.rec_name_', 'about' => 'a.users.rec_about_'] as $k => $prefix) {
        $said = __($prefix . $slug);
        if ($said !== $prefix . $slug) $label[$k] = $said;
    }
    $isAdmin = $slug === 'admin';
    return [
        'group'       => ['id' => (int)$g['id'], 'slug' => (string)$g['slug'], 'name' => (string)$g['name'], 'members' => (int)$st->fetchColumn()],
        'recommended' => ['label' => $label['label'], 'about' => $label['about'], 'ids' => $plan['recommended']],
        'held'        => $plan['held'],
        'add'         => $plan['add'],
        'remove'      => $plan['remove'],
        // The Admin group's consent ids: what the tick would add, and whether this answer counted them in `add`.
        'consent'     => $isAdmin ? ['ids' => userConsentPermissions(), 'add' => $plan['consent_add'], 'applied' => (bool)$plan['consent']] : null,
        'blanket'     => $isAdmin,
    ];
};
$grLoad = function (int $id) use ($db): ?array {
    $st = $db->prepare("SELECT * FROM user_groups WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $g = $grLoad((int)($_GET['id'] ?? 0));
    if ($g === null) jsonResponse(['error' => __('api.groups.not_found')], 404);
    $plan = userGroupRecommendedPlan($g, (string)($_GET['consent'] ?? '') === '1');
    if ($plan === null) jsonResponse(['error' => __('api.groups.no_recommended')], 400);
    jsonResponse($grAnswer($g, $plan));
}

requirePost();
$input = readJsonBody(false);
$id = (int)($input['id'] ?? 0);
$mode = (string)($input['mode'] ?? '');
$consent = !empty($input['consent']);
if (!in_array($mode, ['add', 'reset'], true)) jsonResponse(['error' => __('api.groups.rec_bad_mode')], 400);
// The screen's own preview is part of the request: what it showed is what may be written.
$expect = $input['expect'] ?? null;
if (!is_array($expect) || !is_array($expect['add'] ?? null) || ($mode === 'reset' && !is_array($expect['remove'] ?? null))) {
    jsonResponse(['error' => __('api.groups.rec_expect')], 400);
}
$r = userGroupApplyRecommended($db, $id, $mode, $consent, $expect);
if (isset($r['error'])) {
    $g = $grLoad($id);
    switch ($r['error']) {
        case 'not_found':      jsonResponse(['error' => __('api.groups.not_found')], 404);
        case 'no_recommended': jsonResponse(['error' => __('api.groups.no_recommended')], 400);
        case 'bad_mode':       jsonResponse(['error' => __('api.groups.rec_bad_mode')], 400);
        case 'changed':
            jsonResponse(['error' => __('api.groups.rec_changed'), 'code' => 'changed',
                          'preview' => ($g && !empty($r['plan'])) ? $grAnswer($g, $r['plan']) : null], 409);
        default:               jsonResponse(['error' => __('api.groups.rec_admin_capability'), 'code' => (string)$r['error']], 409);
    }
}
$g = $r['group'];
if (!$r['changed']) {
    auditSuppress();   // nothing written, nothing to record (the 1.67.0 rule: a no-op leaves no line)
} else {
    auditNote(userGroupRecommendedAudit($g, $mode, $consent && (string)$g['slug'] === 'admin', $r['added'], $r['removed']));
}
jsonResponse(['success' => true, 'changed' => (bool)$r['changed'], 'added' => $r['added'], 'removed' => $r['removed'],
              'preview' => $grAnswer($g, $r['plan'])]);
