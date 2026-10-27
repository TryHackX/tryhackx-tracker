<?php
if (!rateLimitAllow('tagsearch', ipBucket(getClientIp($cfg)), 900, 3600)) {
    jsonResponse(['error' => 'rate_limit', 'retry_after' => 3600], 429);
}
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 40));
jsonResponse(['success' => true, 'tags' => tagsSearch($db, $cfg, $q, 12)]);
