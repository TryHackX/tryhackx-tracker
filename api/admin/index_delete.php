<?php
requirePost();
$input = readJsonBody();
$hashes = $input['hashes'] ?? [];
if (!is_array($hashes)) $hashes = [$hashes];
if (!$hashes) jsonResponse(['error' => __('api.index.no_hashes')], 400);
if (count($hashes) > 5000) jsonResponse(['error' => __('api.index.too_many_hashes_5000')], 400);
$removed = indexDelete($db, $hashes);
jsonResponse(['success' => true, 'removed' => $removed]);
