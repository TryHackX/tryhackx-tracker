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
    ];
}
jsonResponse(['groups' => $rows, 'permission_list' => userPermissionList(), 'enabled' => usersEnabled($cfg)]);
