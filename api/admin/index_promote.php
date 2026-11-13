<?php
requirePost();
$input = readJsonBody();
$hashes = $input['hashes'] ?? [];
if (!is_array($hashes)) $hashes = [$hashes];
if (!$hashes) jsonResponse(['error' => __('api.index.no_hashes')], 400);
if (count($hashes) > 500) jsonResponse(['error' => __('api.index.too_many_hashes')], 400);
$r = indexPromote($db, $cfg, $hashes);
if ($r['error'] !== null && $r['promoted'] === 0) jsonResponse(['success' => false, 'error' => $r['error']], 400);
jsonResponse(['success' => true, 'promoted' => $r['promoted'], 'summary' => $r['summary']]);
