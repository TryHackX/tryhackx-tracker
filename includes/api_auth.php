<?php
/**
 * Server-to-server API: bearer-key authentication + strict ban policy.
 *
 *   Authorization: Bearer <key_id>.<secret>
 *
 * key_id = 16 hex chars, secret = 64 hex chars. Only sha256(secret) is stored (api_clients.secret_hash);
 * the plain secret is shown once when the client is created. Rotation = create a new client, paste
 * it into the consumer, disable the old one.
 *
 * Ban policy ("very strict"): any FAILED authentication attempt that carries an Authorization header
 * bans the source for `api_ban_days` (default 30) — malformed header, unknown key, wrong secret.
 * Requests WITHOUT an Authorization header get 401 and are NOT banned (scanner noise; the whole
 * point is to punish attempts to guess/abuse keys, not to fill the table with crawler hits).
 * A disabled key answers 403 without a ban (that is an admin action, not an attack), and IPs listed
 * in `api_ban_exempt_ips` (seeded with this server's own addresses) are never banned nor blocked.
 * Bans are keyed by IP bucket (IPv4 exact, IPv6 /64) so rotating inside a /64 does not help.
 * The full offending request (headers minus secrets, body up to 256 KB) is stored for review.
 */

const API_MAX_ITEMS          = 500;        // items per submit call
const API_MAX_BODY_BYTES     = 524288;     // 512 KB request body cap
const API_SNAPSHOT_MAX_BODY  = 262144;     // body bytes stored in a ban snapshot
const API_BAN_INSERTS_PER_MIN = 30;        // global throttle on new ban rows (snapshot dropped above it)

/** The per-minute budget is a fixed window: cheap to count, and its end is what Retry-After names. */
const API_RATE_WINDOW = 60;

function apiParseIpList(string $list): array {
    return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $list) ?: []), fn($v) => $v !== ''));
}

/** Is the IP (or its bucket) in the exempt list? Accepts exact IPs and IPv4/IPv6 CIDRs. */
function apiIpExempt(string $ip, array $cfg): bool {
    $list = apiParseIpList((string)($cfg['api_ban_exempt_ips'] ?? ''));
    if (!$list) return false;
    $bin = @inet_pton($ip);
    foreach ($list as $entry) {
        if ($entry === $ip) return true;
        if (str_contains($entry, '/')) {
            [$net, $bits] = explode('/', $entry, 2);
            $nb = @inet_pton(trim($net)); $bits = (int)$bits;
            if ($bin === false || $nb === false || strlen($bin) !== strlen($nb)) continue;
            $bytes = intdiv($bits, 8); $rem = $bits % 8;
            if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) continue;
            if ($rem > 0) {
                $mask = (0xFF << (8 - $rem)) & 0xFF;
                if ((ord($bin[$bytes]) & $mask) !== (ord($nb[$bytes]) & $mask)) continue;
            }
            return true;
        }
    }
    // the server's own address is always exempt even if the setting was edited
    $self = $_SERVER['SERVER_ADDR'] ?? '';
    return $self !== '' && $self === $ip;
}

function apiIsBanned(PDO $db, array $cfg, string $ip): bool {
    if (apiIpExempt($ip, $cfg)) return false;
    $st = $db->prepare("SELECT 1 FROM api_bans WHERE ip_bucket = ? AND lifted_at IS NULL AND expires_at > NOW() LIMIT 1");
    $st->execute([ipBucket($ip)]);
    return (bool)$st->fetchColumn();
}

/** Snapshot of the offending request (no cookies, secret in Authorization masked). */
function apiRequestSnapshot(string $rawBody, string $endpoint): array {
    $keep = ['HTTP_USER_AGENT' => 'User-Agent', 'CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length',
             'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For', 'HTTP_X_REAL_IP' => 'X-Real-IP', 'HTTP_ACCEPT' => 'Accept',
             'HTTP_AUTHORIZATION' => 'Authorization', 'HTTP_X_TRACKER_KEY' => 'X-Tracker-Key'];
    $headers = [];
    foreach ($keep as $k => $name) {
        if (!isset($_SERVER[$k])) continue;
        $v = (string)$_SERVER[$k];
        if ($k === 'HTTP_AUTHORIZATION' || $k === 'HTTP_X_TRACKER_KEY') {
            // never store a credential: keep the scheme and, for a well-formed bearer token, the key id only
            if (preg_match('/^\s*(Bearer)\s+([a-f0-9]{16})\./i', $v, $mm)) {
                $v = $mm[1] . ' ' . strtolower($mm[2]) . '.***';
            } elseif (preg_match('/^\s*([A-Za-z][A-Za-z0-9_-]{0,20})\s+\S/', $v, $mm)) {
                $v = $mm[1] . ' ***';   // Basic / Digest / unknown scheme — value dropped
            } else {
                $v = '***';             // raw token without a scheme — dropped entirely
            }
        }
        $headers[$name] = mb_substr($v, 0, 512);
    }
    $bodyLen = strlen($rawBody);
    $body = $bodyLen > API_SNAPSHOT_MAX_BODY ? substr($rawBody, 0, API_SNAPSHOT_MAX_BODY) : $rawBody;
    return [
        'ts' => date('c'), 'ip' => getClientIp(), 'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? '', 'endpoint' => $endpoint, 'uri' => mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 512),
        'headers' => $headers, 'body' => $body, 'body_len' => $bodyLen, 'body_truncated' => $bodyLen > API_SNAPSHOT_MAX_BODY,
    ];
}

/** Insert a ban row (unless exempt / already banned). Never throws — the caller always answers 403. */
function apiBan(PDO $db, array $cfg, string $ip, string $reason, string $detail, ?array $snapshot, ?string $keyId, string $endpoint): void {
    try {
        if (apiIpExempt($ip, $cfg)) return;
        $bucket = ipBucket($ip);
        $st = $db->prepare("SELECT 1 FROM api_bans WHERE ip_bucket = ? AND lifted_at IS NULL AND expires_at > NOW() LIMIT 1");
        $st->execute([$bucket]);
        if ($st->fetchColumn()) return;
        $days = max(1, min(3650, (int)($cfg['api_ban_days'] ?? 30)));
        // global throttle: keep the snapshot only while the insert rate is sane
        $recent = (int)$db->query("SELECT COUNT(*) FROM api_bans WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)")->fetchColumn();
        $snapJson = null;
        if ($snapshot !== null && $recent < API_BAN_INSERTS_PER_MIN) {
            $snapJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($snapJson === false) $snapJson = null;
        }
        $ins = $db->prepare("INSERT INTO api_bans (ip, ip_bucket, reason, detail, key_id, endpoint, request_snapshot, expires_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))");
        try {
            $ins->execute([$ip, $bucket, mb_substr($reason, 0, 32), mb_substr($detail, 0, 255), $keyId !== null ? mb_substr($keyId, 0, 64) : null, mb_substr($endpoint, 0, 64), $snapJson, $days]);
        } catch (\Throwable $e) {
            // snapshot may be unstorable (encoding) — ban without it
            $ins->execute([$ip, $bucket, mb_substr($reason, 0, 32), mb_substr($detail, 0, 255), $keyId !== null ? mb_substr($keyId, 0, 64) : null, mb_substr($endpoint, 0, 64), null, $days]);
        }
    } catch (\Throwable $e) {
        error_log('[api ban] ' . $e->getMessage());
    }
}

/** Read the raw request body with a hard size cap. Returns null when the cap is exceeded. */
function apiReadRawBody(int $max = API_MAX_BODY_BYTES): ?string {
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > $max) return null;
    $body = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($body === false) $body = '';
    if (strlen($body) > $max) return null;
    return $body;
}

/** The Authorization header (Apache may hide it behind REDIRECT_ prefixes / mod_rewrite). */
function apiAuthorizationHeader(): ?string {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_TRACKER_KEY'] as $k) {
        if (!empty($_SERVER[$k])) return (string)$_SERVER[$k];
    }
    if (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        foreach ($h as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0 || strcasecmp($k, 'X-Tracker-Key') === 0) return (string)$v;
        }
    }
    return null;
}

function apiRateLimitPerMin(array $cfg): int { return max(0, min(100000, (int)($cfg['api_rate_limit_per_min'] ?? 60))); }
function apiRateLimitBytesDay(array $cfg): int { return max(0, (int)($cfg['api_rate_limit_bytes_day'] ?? 5368709120)); }

/**
 * Per-client budgets for the server-to-server API: requests per minute and bytes per day.
 *
 * Until now a valid bearer was a licence to hammer the database as fast as the network allowed. The
 * ban machinery only ever reacted to BAD authorisation, so a partner whose key was correct — or
 * whose key had leaked — could not be slowed down at all short of disabling them by hand. A
 * federation peer pulling pages is exactly the shape of traffic that needs a ceiling.
 *
 * The counters live on the client row, not on the IP: one partner pulls from one address, and an
 * IP bucket would be shared by everyone behind the same NAT. Both budgets are windows that reset
 * rather than rolling averages — cheap, and the reset is what Retry-After can point at honestly.
 *
 * Refusal is 429 with Retry-After, never a ban: going too fast is a configuration mistake between
 * partners, not an attack, and banning would take the whole node offline for a mis-set interval.
 * Either budget set to 0 switches that half off.
 */
function apiRateLimit(PDO $db, array $cfg, array $client, int $requestBytes): void {
    $perMin   = apiRateLimitPerMin($cfg);
    $bytesDay = apiRateLimitBytesDay($cfg);
    if ($perMin <= 0 && $bytesDay <= 0) return;
    // The same list that exempts an address from bans exempts it here: it exists for the operator's
    // own integrations (the forum on this very machine), and throttling ourselves helps nobody.
    if (apiIpExempt(getClientIp($cfg), $cfg)) return;

    $id = (int)($client['id'] ?? 0);
    if ($id <= 0) return;
    try {
        // One statement, so two requests arriving together cannot both read a stale count. MariaDB
        // evaluates SET assignments left to right, so each counter is written BEFORE the column its
        // own reset test reads — hence count before start, and bytes before day. Swapping either
        // pair would make the window reset on every request.
        $db->prepare(
            "UPDATE api_clients SET
                rl_min_count = IF(rl_min_start IS NULL OR rl_min_start <= NOW() - INTERVAL " . API_RATE_WINDOW . " SECOND, 1, rl_min_count + 1),
                rl_min_start = IF(rl_min_start IS NULL OR rl_min_start <= NOW() - INTERVAL " . API_RATE_WINDOW . " SECOND, NOW(), rl_min_start),
                rl_day_bytes = IF(rl_day IS NULL OR rl_day <> CURDATE(), ?, rl_day_bytes + ?),
                rl_day = CURDATE()
             WHERE id = ?")
           ->execute([$requestBytes, $requestBytes, $id]);
        $st = $db->prepare("SELECT rl_min_count, UNIX_TIMESTAMP(rl_min_start) AS rl_start, rl_day_bytes FROM api_clients WHERE id = ?");
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return;   // a counter that cannot be written must never take the API down
    }
    if (!$c) return;

    $retry = null;
    $why = '';
    if ($perMin > 0 && (int)$c['rl_min_count'] > $perMin) {
        $retry = max(1, API_RATE_WINDOW - (time() - (int)$c['rl_start']));
        $why = 'rate';
    } elseif ($bytesDay > 0 && (int)$c['rl_day_bytes'] > $bytesDay) {
        $retry = max(1, strtotime('tomorrow midnight') - time());
        $why = 'bytes';
    }
    if ($retry === null) return;

    try { $db->prepare("UPDATE api_clients SET rl_blocked_count = rl_blocked_count + 1 WHERE id = ?")->execute([$id]); } catch (\Throwable $e) {}
    header('Retry-After: ' . (int)$retry);
    jsonResponse([
        'error' => 'rate_limited',
        'detail' => $why === 'rate'
            ? 'more than ' . $perMin . ' requests in a minute for this key'
            : 'the daily byte budget for this key is spent',
        'retry_after' => (int)$retry,
    ], 429);
}

/**
 * Charge bytes we SENT to the client's daily budget. Called by endpoints that answer with a large
 * body (the federation export), because the request that asks for a page is tiny and the reply is
 * the part that actually costs bandwidth. Best effort — never worth failing a served response over.
 */
function apiChargeBytes(PDO $db, array $client, int $bytes): void {
    $id = (int)($client['id'] ?? 0);
    if ($id <= 0 || $bytes <= 0) return;
    try {
        $db->prepare("UPDATE api_clients SET rl_day_bytes = IF(rl_day IS NULL OR rl_day <> CURDATE(), ?, rl_day_bytes + ?), rl_day = CURDATE() WHERE id = ?")
           ->execute([$bytes, $bytes, $id]);
    } catch (\Throwable $e) {}
}

/**
 * Authenticate the request or exit with the appropriate JSON error. On success returns the client
 * row (id, label, key_id). All failures respond with the same generic 403 body.
 */
function apiAuthenticate(PDO $db, array $cfg, string $endpoint, ?string $rawBody): array {
    $ip = getClientIp($cfg);
    if (($cfg['api_enabled'] ?? '0') !== '1') jsonResponse(['error' => 'api_disabled'], 503);
    if (apiIsBanned($db, $cfg, $ip)) jsonResponse(['error' => 'forbidden'], 403);

    $auth = apiAuthorizationHeader();
    if ($auth === null || trim($auth) === '') {
        header('WWW-Authenticate: Bearer realm="tracker-api"');
        jsonResponse(['error' => 'unauthorized'], 401);
    }
    $fail = function (string $reason, string $detail, ?string $keyId = null) use ($db, $cfg, $ip, $endpoint, $rawBody) {
        apiBan($db, $cfg, $ip, $reason, $detail, apiRequestSnapshot((string)$rawBody, $endpoint), $keyId, $endpoint);
        jsonResponse(['error' => 'forbidden'], 403);
    };
    if (!preg_match('/^\s*Bearer\s+([a-f0-9]{16})\.([a-f0-9]{64})\s*$/i', $auth, $m)) {
        $fail('malformed', 'Authorization header is not "Bearer <key_id>.<secret>"');
    }
    $keyId = strtolower($m[1]); $secret = strtolower($m[2]);
    $st = $db->prepare("SELECT id, label, key_id, secret_hash, scope, enabled FROM api_clients WHERE key_id = ? LIMIT 1");
    $st->execute([$keyId]);
    $client = $st->fetch();
    if (!$client) $fail('unknown_key', 'no client with this key id', $keyId);
    if (!hash_equals((string)$client['secret_hash'], hash('sha256', $secret))) $fail('bad_secret', 'secret does not match', $keyId);
    if ((int)$client['enabled'] !== 1) jsonResponse(['error' => 'forbidden'], 403); // admin action, no ban
    // authenticated client, oversized payload → 413, never a ban (a big forum scan batch is not an attack)
    if ($rawBody === null) jsonResponse(['error' => 'payload_too_large', 'max_bytes' => API_MAX_BODY_BYTES], 413);
    try {
        $db->prepare("UPDATE api_clients SET last_used_at = NOW(), last_used_ip = ?, requests_count = requests_count + 1 WHERE id = ?")
           ->execute([$ip, (int)$client['id']]);
    } catch (\Throwable $e) {}
    unset($client['secret_hash']);
    apiRateLimit($db, $cfg, $client, strlen((string)$rawBody));
    return $client;
}

/** Valid client scopes: what family of v1 endpoints a key may call ('all' = everything). */
function apiClientScopes(): array { return ['whitelist', 'users', 'federation', 'all']; }

/**
 * Enforce the client's scope AFTER apiAuthenticate(). A wrong scope is an admin-side configuration
 * matter, not an attack — 403 without a ban (mirrors the disabled-key branch).
 */
function apiRequireScope(array $client, string $scope): void {
    $s = (string)($client['scope'] ?? 'whitelist');
    if ($s === 'all' || $s === $scope) return;
    jsonResponse(['error' => 'forbidden', 'detail' => 'this key does not have the "' . $scope . '" scope'], 403);
}

/** Create a client; returns ['id','key_id','secret'] — the secret is never retrievable again. */
function apiClientCreate(PDO $db, string $label, string $scope = 'whitelist'): array {
    $label = mb_substr(trim($label), 0, 100) ?: 'client';
    if (!in_array($scope, apiClientScopes(), true)) $scope = 'whitelist';
    $keyId = bin2hex(random_bytes(8));
    $secret = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO api_clients (label, key_id, secret_hash, secret_hint, scope) VALUES (?, ?, ?, ?, ?)")
       ->execute([$label, $keyId, hash('sha256', $secret), substr($secret, -4), $scope]);
    return ['id' => (int)$db->lastInsertId(), 'key_id' => $keyId, 'secret' => $secret, 'label' => $label, 'scope' => $scope];
}
