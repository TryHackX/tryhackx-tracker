<?php
requirePost();

$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$field = $input['field'] ?? '';
$value = trim($input['value'] ?? '');
$table = $input['source'] ?? 'reports';

if ($id < 1) {
    jsonResponse(['error' => __('api.report.invalid_id')], 400);
}

// Whitelist editable fields
$allowed = ['company', 'representative'];
if (!in_array($field, $allowed, true)) {
    jsonResponse(['error' => __('api.report.field_not_editable')], 400);
}

// Whitelist tables
$allowedTables = ['reports', 'archives'];
if (!in_array($table, $allowedTables, true)) {
    jsonResponse(['error' => __('api.report.invalid_source')], 400);
}

if (mb_strlen($value) > 255 || $value === '') {
    jsonResponse(['error' => __('api.report.value_length')], 400);
}

$value = sanitize($value);

$stmt = $db->prepare("UPDATE $table SET $field = ? WHERE id = ?");
$stmt->execute([$value, $id]);

if ($stmt->rowCount() === 0) {
    jsonResponse(['error' => __('api.report.record_not_found')], 404);
}

jsonResponse(['success' => true, 'value' => $value]);
