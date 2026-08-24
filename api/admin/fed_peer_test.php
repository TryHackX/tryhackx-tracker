<?php
/**
 * Admin: test the OUTBOUND connection to a peer — calls their v1/federation/ping with our stored
 * bearer. Body: {id}. Never throws; reports the peer's answer or the transport error.
 */
requirePost();
$input = readJsonBody();
$id = (int)($input['id'] ?? 0);
$st = $db->prepare("SELECT name, base_url, bearer FROM fed_peers WHERE id = ?");
$st->execute([$id]);
$peer = $st->fetch(PDO::FETCH_ASSOC);
if (!$peer) jsonResponse(['error' => 'Peer not found'], 404);
if (trim((string)$peer['bearer']) === '') jsonResponse(['error' => 'No outbound bearer stored for this peer — paste the key their admin generated for you.'], 400);
if (!function_exists('curl_init')) jsonResponse(['error' => 'curl is not available'], 500);

$url = rtrim($peer['base_url'], '/') . '/api.php?endpoint=v1/federation/ping';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'tryhackx-tracker/1.6 fed-test',
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . trim($peer['bearer']), 'Accept: application/json'],
    CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
if ($body === false) jsonResponse(['success' => false, 'error' => 'Connection failed: ' . $err]);
$json = json_decode((string)$body, true);
if (!is_array($json)) jsonResponse(['success' => false, 'error' => 'HTTP ' . $code . ' — the reply is not JSON (wrong base URL?)']);
if ($code !== 200 || empty($json['ok'])) jsonResponse(['success' => false, 'error' => 'HTTP ' . $code . ': ' . (string)($json['error'] ?? 'unexpected reply'), 'reply' => $json]);
jsonResponse(['success' => true, 'reply' => $json]);
