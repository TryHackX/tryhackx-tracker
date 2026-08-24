<?php
// Admin: delete a federation peer; its inbound API client (scope federation) goes with it.
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
if (!fedPeerDelete($db, $id)) jsonResponse(['error' => 'Peer not found'], 404);
jsonResponse(['success' => true]);
