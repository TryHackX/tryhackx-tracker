<?php
requirePost();
$input = readJsonBody();
$hashes = $input['hashes'] ?? [];
if (!is_array($hashes)) $hashes = [$hashes];
if (!$hashes) jsonResponse(['error' => 'No hashes provided'], 400);
if (count($hashes) > 5000) jsonResponse(['error' => 'Too many hashes (max 5000)'], 400);
$removed = indexDelete($db, $hashes);
jsonResponse(['success' => true, 'removed' => $removed]);
