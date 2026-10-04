<?php
// Admin: list groups with member counts, plus the permission registry for the editor UI.
$rows = [];
$counts = [];
try {
    foreach ($db->query("SELECT group_id, COUNT(*) c FROM user_group_members GROUP BY group_id") as $c) {
        $counts[(int)$c['group_id']] = (int)$c['c'];
    }
} catch (\Throwable $e) {}
foreach ($db->query("SELECT * FROM user_groups ORDER BY priority DESC, name") as $g) {
    $rows[] = [
        'id' => (int)$g['id'], 'slug' => $g['slug'], 'name' => $g['name'], 'description' => $g['description'],
        'color' => $g['color'], 'priority' => (int)$g['priority'], 'is_default' => (int)$g['is_default'],
        'is_system' => (int)$g['is_system'], 'permissions' => userGroupPermissions($g['permissions']),
        'members' => $counts[(int)$g['id']] ?? 0, 'created_at' => $g['created_at'],
        // 1.72.0: a seeded group has a recommended set (the Groups tab's "Recommended" window, api/admin/group_recommended)
        'recommended' => userGroupRecommended((string)$g['slug']) !== null,
    ];
}
// The editor's "Start from" presets, named in the reader's language (1.73.0: English on every page) — each by the
// a.users.rec_name_* / rec_about_* words whose English is the preset's own (tests/groups_matrix_test.php); the page
// finds them back by key (its bundle carries a.users.rec_). The ids are the preset's.
$presets = userGroupPresets();
foreach ($presets as $slug => &$preset) {
    foreach (['label' => 'a.users.rec_name_', 'about' => 'a.users.rec_about_'] as $k => $prefix) {
        $said = __($prefix . $slug);
        if ($said !== $prefix . $slug) $preset[$k] = $said;
    }
}
unset($preset);
// `consent`: the ids that say what others may see of a member (userConsentPermissions()) — the matrix and the editor
// mark them, and on the Admin group they are the only boxes that are a choice: every other one is held by its blanket.
// `permission_list`: id => what it allows, in the reader's language (userPermissionList(); the scripts say it by the
// id, perm.<id>, so it follows the live language switch).
jsonResponse(['groups' => $rows, 'permission_list' => userPermissionList(), 'presets' => $presets,
              'consent' => userConsentPermissions(), 'enabled' => usersEnabled($cfg)]);
