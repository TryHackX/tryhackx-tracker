<?php
requirePost();

$rawBody = apiReadRawBody(FORUM_DESC_MAX_BODY);
$client  = apiAuthenticate($db, $cfg, 'v1/whitelist/describe', $rawBody, FORUM_DESC_MAX_BODY);
apiRequireScope($client, 'whitelist');

$payload = json_decode((string)$rawBody, true);
if (is_array($payload)) $client = imagesConsentNote($db, $client, $payload['copy_images'] ?? null);
if (!forumDescEnabled($cfg, $client)) {
    jsonResponse(['ok' => false, 'error' => 'describe_off', 'mode' => 'off'] + imagesClientState($client));
}
if (!is_array($payload) || !isset($payload['topics']) || !is_array($payload['topics'])) {
    jsonResponse(['ok' => false, 'error' => 'invalid_json', 'message' => 'Body must be a JSON object with a "topics" array.'], 422);
}
$topics = array_values($payload['topics']);
if (count($topics) === 0) {
    jsonResponse(['ok' => false, 'error' => 'no_topics'], 422);
}
if (count($topics) > FORUM_DESC_MAX_TOPICS) {
    jsonResponse(['ok' => false, 'error' => 'too_many_topics', 'max' => FORUM_DESC_MAX_TOPICS], 422);
}

$limit = forumDescLimit($cfg, $payload['max_chars'] ?? null);
$results = [];
$summary = array_fill_keys(array_merge(FORUM_DESC_STATUSES, FORUM_DESC_HIDDEN, ['skipped', 'error']), 0);
foreach ($topics as $i => $raw) {
    $topic = forumDescTopic($raw);
    $hashes = $topic['hashes'] ?? [];
    if ($topic === null || !$hashes || count($hashes) > FORUM_DESC_MAX_HASHES) {
        $results[] = ['topic' => $i, 'discussion_id' => $topic['discussion_id'] ?? null, 'hash' => null, 'status' => 'invalid',
                      'detail' => $topic === null ? 'no discussion_id or post_id'
                                : ($hashes ? 'more than ' . FORUM_DESC_MAX_HASHES . ' hashes' : 'no hashes')];
        $summary['invalid']++;
        continue;
    }
    $memo = [];
    foreach ($hashes as $h) {
        try {
            $r = $topic['visible'] ? forumDescImport($db, $cfg, $client, $topic, $h, $limit, $memo) : forumDescHide($db, $client, $topic, $h);
        } catch (\Throwable $e) {
            error_log('[api v1] whitelist/describe: ' . $h . ' failed: ' . $e->getMessage());
            $r = ['status' => 'error', 'detail' => 'try again later'];
        }
        $results[] = ['topic' => $i, 'discussion_id' => $topic['discussion_id'], 'hash' => $h !== '' ? mb_substr($h, 0, 64) : null,
                      'status' => $r['status'], 'detail' => $r['detail']];
        $summary[in_array($r['status'], FORUM_DESC_SKIPPED, true) ? 'skipped' : $r['status']]++;
    }
}

jsonResponse([
    'ok' => true,
    'mode' => forumDescMode($client),
    'results' => $results,
    'summary' => $summary,
    'max_chars' => $limit,
    'max_topics' => FORUM_DESC_MAX_TOPICS,
    'max_hashes' => FORUM_DESC_MAX_HASHES,
    'max_bytes' => FORUM_DESC_MAX_BODY,
    'server_time' => time(),
] + imagesClientState($client));
