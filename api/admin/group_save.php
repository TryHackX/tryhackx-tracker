<?php
/**
 * Admin: create/update a group. Body: {id?, slug, name, description?, color?, priority?, is_default?,
 * permissions: {perm: bool}}. System groups (guest/member) keep their slug.
 */
requirePost();
$input = readJsonBody();
$id = isset($input['id']) ? (int)$input['id'] : 0;
$slug = strtolower(trim((string)($input['slug'] ?? '')));
$name = trim((string)($input['name'] ?? ''));
if (!preg_match('/^[a-z0-9_-]{2,64}$/', $slug)) jsonResponse(['error' => 'Slug: 2-64 chars, a-z 0-9 _ -'], 400);
if ($name === '' || mb_strlen($name) > 64) jsonResponse(['error' => 'Name: 1-64 characters'], 400);
$desc = mb_substr(trim((string)($input['description'] ?? '')), 0, 255);
$color = trim((string)($input['color'] ?? ''));
if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) jsonResponse(['error' => 'Color must be a #hex value (or empty)'], 400);
$priority = max(-1000, min(1000, (int)($input['priority'] ?? 0)));
$isDefault = !empty($input['is_default']) ? 1 : 0;

$known = userPermissionList();
$perms = [];
foreach ((array)($input['permissions'] ?? []) as $k => $v) {
    if (isset($known[$k]) && $v) $perms[$k] = true;
}
$permJson = json_encode($perms, JSON_UNESCAPED_SLASHES);

try {
    if ($id > 0) {
        $st = $db->prepare("SELECT slug, is_system FROM user_groups WHERE id = ?");
        $st->execute([$id]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) jsonResponse(['error' => 'Group not found'], 404);
        if ((int)$cur['is_system'] === 1) $slug = $cur['slug'];   // guest/member keep their identity
        $db->prepare("UPDATE user_groups SET slug = ?, name = ?, description = ?, color = ?, priority = ?, is_default = ?, permissions = ? WHERE id = ?")
           ->execute([$slug, $name, $desc, $color, $priority, $isDefault, $permJson, $id]);
    } else {
        $db->prepare("INSERT INTO user_groups (slug, name, description, color, priority, is_default, permissions) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$slug, $name, $desc, $color, $priority, $isDefault, $permJson]);
        $id = (int)$db->lastInsertId();
    }
} catch (PDOException $e) {
    if ((int)$e->errorInfo[1] === 1062) jsonResponse(['error' => 'A group with this slug already exists'], 400);
    throw $e;
}
jsonResponse(['success' => true, 'id' => $id]);
