<?php

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Cache-busting query string for a local asset, based on its last-modified time.
 * Usage: <link href="...style.css<?= assetVer('assets/css/style.css') ?>">
 * Ensures browsers pull the new file after a deploy instead of a stale cached copy.
 */
function assetVer(string $relPath): string {
    $full = __DIR__ . '/../' . ltrim($relPath, '/');
    $mtime = @filemtime($full);
    return '?v=' . ($mtime !== false ? $mtime : date('Ymd'));
}

/**
 * Human-readable uptime from a raw second count. Computed straight from seconds — NOT via
 * DateTime::diff(), whose ->d field is the day-of-month remainder and silently drops the
 * month/year components, mangling any uptime of a month or more.
 */
function formatUptime(int $seconds): string {
    if ($seconds <= 0) return '0 sec';
    $days  = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $mins  = intdiv($seconds % 3600, 60);
    $secs  = $seconds % 60;
    $parts = [];
    if ($days  > 0) $parts[] = $days . ' d';
    if ($hours > 0) $parts[] = $hours . ' h';
    if ($mins  > 0) $parts[] = $mins . ' min';
    if ($secs  > 0 || empty($parts)) $parts[] = $secs . ' sec';
    return implode(', ', $parts);
}

/** Mask a person's name for public status output, keeping first/last letter of each word. */
function maskName(string $name): string {
    $words = preg_split('/\s+/', trim($name));
    $masked = [];
    foreach ($words as $w) {
        if (mb_strlen($w) <= 2) {
            $masked[] = mb_substr($w, 0, 1) . '...';
        } else {
            $masked[] = mb_substr($w, 0, 1) . '...' . mb_substr($w, -1);
        }
    }
    return implode(' ', $masked);
}

/** Mask an email address for public status output. */
function maskEmail(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return '***';
    $user = $parts[0];
    $domain = $parts[1];
    $dotPos = strrpos($domain, '.');
    if ($dotPos === false) return mb_substr($user, 0, 1) . '...@***';
    $domName = substr($domain, 0, $dotPos);
    $domExt = substr($domain, $dotPos);
    $mUser = mb_strlen($user) <= 2 ? mb_substr($user, 0, 1) . '...' : mb_substr($user, 0, 1) . '...' . mb_substr($user, -1);
    $mDom = mb_strlen($domName) <= 2 ? mb_substr($domName, 0, 1) . '...' : mb_substr($domName, 0, 1) . '...' . mb_substr($domName, -1);
    return $mUser . '@' . $mDom . $domExt;
}

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** Verify the CSRF token sent by admin AJAX calls in the X-CSRF-Token header. */
function verifyCsrfHeader(): bool {
    return verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
}

// ─────────────────────────────────────────────────────────────────────────────
// CAPTCHA (provider-agnostic: Google reCAPTCHA v2 or Cloudflare Turnstile)
// ─────────────────────────────────────────────────────────────────────────────
// Setting `captcha_provider` selects the widget/verifier; `recaptcha_enabled` remains the master
// "CAPTCHA enabled" switch and `recaptcha_on_<ctx>` the per-context toggles (legacy names kept so
// existing installations keep working unchanged). The old verifyRecaptcha()/isRecaptchaEnabled()
// names are preserved as thin wrappers.

/** Active provider: 'recaptcha' (default) or 'turnstile'. */
function captchaProvider(array $cfg): string {
    return (($cfg['captcha_provider'] ?? 'recaptcha') === 'turnstile') ? 'turnstile' : 'recaptcha';
}

/** Public site key of the active provider ('' when missing). */
function captchaSiteKey(array $cfg): string {
    return trim((string)($cfg[captchaProvider($cfg) === 'turnstile' ? 'turnstile_site_key' : 'recaptcha_site_key'] ?? ''));
}

/** Secret of the active provider ('' when missing). */
function captchaSecret(array $cfg): string {
    return trim((string)($cfg[captchaProvider($cfg) === 'turnstile' ? 'turnstile_secret' : 'recaptcha_secret'] ?? ''));
}

/** True when CAPTCHA is switched on AND the active provider has both a site key and a secret. */
function captchaConfigured(array $cfg): bool {
    if (($cfg['recaptcha_enabled'] ?? '0') !== '1') return false;
    return captchaSiteKey($cfg) !== '' && captchaSecret($cfg) !== '';
}

/**
 * One HTTP helper for the siteverify calls: form-encoded POST, hard timeouts (connect 3 s, total
 * 5 s), TLS verification on. Returns the decoded JSON object or null on any transport/parse error
 * so callers fail closed. cURL is preferred; falls back to a stream context with a timeout.
 */
function captchaHttpPost(string $url, array $fields): ?array {
    $body = http_build_query($fields);
    $result = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) return null;
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $result = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($result === false || $code < 200 || $code >= 300) return null;
    } else {
        $opts = ['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => 5,
            'ignore_errors' => false,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]];
        $result = @file_get_contents($url, false, stream_context_create($opts));
        if ($result === false) return null;
    }
    $json = json_decode((string)$result, true);
    return is_array($json) ? $json : null;
}

/** Google reCAPTCHA v2 verification. Fail closed on missing secret / transport error. */
function verifyRecaptcha(string $response, array $cfg): bool {
    $secret = trim((string)($cfg['recaptcha_secret'] ?? ''));
    // Fail closed: if asked to verify but no secret is configured, we cannot prove the
    // token is valid, so reject. Whether CAPTCHA applies at all is decided upstream by
    // isCaptchaEnabled()/isCaptchaRequired(), both of which require a configured secret.
    if ($secret === '' || $response === '') return false;
    $json = captchaHttpPost('https://www.google.com/recaptcha/api/siteverify', [
        'secret'   => $secret,
        'response' => $response,
        'remoteip' => getClientIp($cfg),
    ]);
    return $json !== null && ($json['success'] ?? false) === true;
}

/** Cloudflare Turnstile verification. Fail closed on missing secret / transport error. */
function verifyTurnstile(string $token, array $cfg): bool {
    $secret = trim((string)($cfg['turnstile_secret'] ?? ''));
    if ($secret === '' || $token === '') return false;
    $json = captchaHttpPost('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => getClientIp($cfg),
    ]);
    return $json !== null && ($json['success'] ?? false) === true;
}

/** Verify a token with whichever provider is active. Empty token → false. */
function verifyCaptcha(string $token, array $cfg): bool {
    if ($token === '') return false;
    return captchaProvider($cfg) === 'turnstile' ? verifyTurnstile($token, $cfg) : verifyRecaptcha($token, $cfg);
}

/** Pull the CAPTCHA token out of a decoded request body (new generic name first, legacy names after). */
function captchaTokenFromInput(array $input): string {
    foreach (['captcha_token', 'g-recaptcha-response', 'cf-turnstile-response'] as $k) {
        if (isset($input[$k]) && is_string($input[$k]) && $input[$k] !== '') return $input[$k];
    }
    return '';
}

/** CAPTCHA switched on, provider configured, and enabled for this context (recaptcha_on_<ctx>). */
function isCaptchaEnabled(array $cfg, string $context = 'report'): bool {
    if (!captchaConfigured($cfg)) return false;
    $key = 'recaptcha_on_' . $context;
    return !empty($cfg[$key]) && $cfg[$key] === '1';
}

/** Legacy alias of isCaptchaEnabled(). */
function isRecaptchaEnabled(array $cfg, string $context = 'report'): bool {
    return isCaptchaEnabled($cfg, $context);
}

/**
 * <head> tags for the active provider: the widget script plus an inline script exposing
 * CAPTCHA_PROVIDER / CAPTCHA_SITEKEY (and RECAPTCHA_SITEKEY for backwards compatibility) to
 * assets/js/captcha.js. Emits nothing when CAPTCHA is not configured.
 */
function captchaHeadTags(array $cfg): string {
    if (!captchaConfigured($cfg)) return '';
    $provider = captchaProvider($cfg);
    $siteKey = captchaSiteKey($cfg);
    $src = $provider === 'turnstile'
        ? 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
        : 'https://www.google.com/recaptcha/api.js?onload=onRecaptchaLoad&render=explicit';
    $keyJs = json_encode($siteKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html  = "<script>\n";
    $html .= "    const CAPTCHA_PROVIDER = '" . $provider . "';\n";
    $html .= "    const CAPTCHA_SITEKEY = " . $keyJs . ";\n";
    $html .= "    const RECAPTCHA_SITEKEY = CAPTCHA_SITEKEY;\n";
    $html .= "    </script>\n";
    $html .= '    <script src="' . $src . '" async defer></script>' . "\n";
    return $html;
}

function isCaptchaRequired(array $cfg, string $context = 'report'): bool {
    if (!isCaptchaEnabled($cfg, $context)) return false;
    // First visit: CAPTCHA never solved in this session → always require
    if (!isset($_SESSION['captcha_solved_at'])) return true;
    // Grace period: if CAPTCHA was recently solved, skip
    $grace = (int)($cfg['captcha_grace_minutes'] ?? 5);
    if ((time() - $_SESSION['captcha_solved_at']) < $grace * 60) {
        return false;
    }
    // Grace expired: check point threshold
    $threshold = (int)($cfg['captcha_threshold'] ?? 6);
    $points = (int)($_SESSION['captcha_points'] ?? 0);
    return $points >= $threshold;
}

function addCaptchaPoints(array $cfg, string $context): void {
    $key = 'captcha_pts_' . $context;
    $pts = (int)($cfg[$key] ?? 0);
    if ($pts > 0) {
        $_SESSION['captcha_points'] = ($_SESSION['captcha_points'] ?? 0) + $pts;
    }
}

function onCaptchaSolved(): void {
    $_SESSION['captcha_solved_at'] = time();
    $_SESSION['captcha_points'] = 0;
    // Request-scoped flag so API response knows CAPTCHA was just solved
    $GLOBALS['captcha_just_solved'] = true;
}

function wasCaptchaJustSolved(): bool {
    return !empty($GLOBALS['captcha_just_solved']);
}

function resetCaptchaGrace(array $cfg): void {
    unset($_SESSION['captcha_solved_at']);
    $_SESSION['captcha_points'] = (int)($cfg['captcha_threshold'] ?? 6);
}

/**
 * Resolve the real client IP.
 *
 * By default this returns REMOTE_ADDR only — the TCP peer, which cannot be spoofed. Behind a
 * reverse proxy / CDN (Cloudflare, nginx) every request's REMOTE_ADDR is the proxy, so IP rate
 * limiting and login lockout would treat all visitors as one host. To fix that WITHOUT opening
 * a spoofing hole, an admin must explicitly:
 *   - list the proxy addresses in `trusted_proxy_ips` (comma separated), and
 *   - name the header the proxy sets in `client_ip_header` (e.g. CF-Connecting-IP or X-Forwarded-For).
 * The forwarded header is only trusted when REMOTE_ADDR is one of the configured proxies.
 */
/** "::ffff:1.2.3.4" (IPv4-mapped, seen behind v4v6 sockets) → "1.2.3.4"; anything else unchanged. */
function unmapIpv4(string $ip): string {
    $ip = trim($ip);
    if (str_contains($ip, ':') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $v4 = @inet_ntop(substr($bin, 12));
            if ($v4 !== false) return $v4;
        }
    }
    return $ip;
}

function getClientIp(?array $cfg = null): string {
    $remote = unmapIpv4($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $cfg = $cfg ?? ($GLOBALS['cfg'] ?? null);
    if (!is_array($cfg)) return $remote;

    $trusted = array_filter(array_map('trim', explode(',', $cfg['trusted_proxy_ips'] ?? '')));
    $header  = trim($cfg['client_ip_header'] ?? '');
    if (empty($trusted) || $header === '' || !in_array($remote, $trusted, true)) {
        return $remote;
    }

    // Header name (X-Forwarded-For) -> $_SERVER key (HTTP_X_FORWARDED_FOR)
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
    $value = $_SERVER[$serverKey] ?? '';
    if ($value === '') return $remote;

    // X-Forwarded-For is a list "client, proxy1, proxy2". Proxies APPEND, so the left-most entry is
    // whatever the client sent (spoofable). Walk from the RIGHT and take the first hop that is not one
    // of our trusted proxies — that is the address the trusted proxy actually talked to.
    $hops = array_reverse(array_map('trim', explode(',', $value)));
    foreach ($hops as $hop) {
        if ($hop === '' || !filter_var($hop, FILTER_VALIDATE_IP)) return $remote;   // garbage → don't guess
        $hop = unmapIpv4($hop);
        if (in_array($hop, $trusted, true)) continue;
        return $hop;
    }
    return $remote;
}

function checkRateLimit(PDO $db, string $ip, int $maxPerHour): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM reports WHERE ip = ? AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$ip]);
    return $stmt->fetchColumn() < $maxPerHour;
}

/**
 * Generic sliding-window rate limiter, file-based (config/ is denied to the web). Returns true
 * if the request is ALLOWED (and records the hit), false if the per-IP limit for this action is
 * already reached. $max <= 0 disables the limit. Fails OPEN on any filesystem error so a disk
 * problem can never lock legitimate users out. Mirrors the login-throttle design in auth.php.
 */
function rateLimitAllow(string $action, string $ip, int $max, int $windowSec = 3600): bool {
    if ($max <= 0) return true;
    $file = __DIR__ . '/../config/rate_limits.json';
    $now  = time();
    $key  = $action . '|' . $ip;

    $data = [];
    if (is_file($file)) {
        $raw  = @file_get_contents($file);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    // Prune expired timestamps across all keys to keep the file bounded.
    foreach ($data as $k => $times) {
        $data[$k] = array_values(array_filter((array)$times, fn($t) => ($now - (int)$t) < $windowSec));
        if (empty($data[$k])) unset($data[$k]);
    }

    $hits = $data[$key] ?? [];
    if (count($hits) >= $max) {
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return false;
    }
    $hits[] = $now;
    $data[$key] = $hits;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

function jsonResponse(array $data, int $code = 200): void {
    // Clean any stray output that might have been generated (e.g. PHP warnings)
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Reject anything but POST with a 405. Call at the top of POST-only endpoints. */
function requirePost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Read and decode a request body. Endpoints are called with a JSON body by the frontend but some
 * also accept form-encoded input, so by default we fall back to $_POST when the body isn't valid
 * JSON. Always returns an array (never null) so callers can safely use `$input['x'] ?? default`.
 * Pass $fallbackToPost=false for endpoints that must reject anything that isn't a JSON object.
 */
function readJsonBody(bool $fallbackToPost = true): array {
    $raw  = file_get_contents('php://input');
    $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($data) || $data === []) {
        $data = $fallbackToPost ? $_POST : [];
    }
    return is_array($data) ? $data : [];
}

function generateUnsubscribeToken(string $email, string $secret): string {
    return hash_hmac('sha256', $email, $secret);
}

function verifyUnsubscribeToken(string $email, string $token, string $secret): bool {
    return hash_equals(generateUnsubscribeToken($email, $secret), $token);
}

function getUnsubscribeUrl(string $email, array $cfg): string {
    $token = generateUnsubscribeToken($email, $cfg['hmac_secret'] ?? '');
    return ($cfg['site_url'] ?? '') . '?action=unsubscribe&email=' . urlencode($email) . '&token=' . $token;
}

function isValidInfoHash(string $hash): bool {
    return preg_match('/^[a-fA-F0-9]{40}$/', $hash) === 1;
}

/**
 * Decode a 32-char Base32 BitTorrent info hash to 40-char hex. Intentionally mirrored in JS
 * (assets/js/app.js `base32ToHex`) so magnet links can be validated client-side before submit
 * AND authoritatively server-side. Keep the two implementations in sync if either changes.
 */
function base32ToHex(string $base32): ?string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32 = strtoupper($base32);
    $bits = '';
    for ($i = 0; $i < strlen($base32); $i++) {
        $val = strpos($alphabet, $base32[$i]);
        if ($val === false) return null;
        $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
    }
    $hex = '';
    for ($i = 0; $i + 4 <= strlen($bits); $i += 4) {
        $hex .= dechex(bindec(substr($bits, $i, 4)));
    }
    return $hex;
}

function getBaseUrl(): string {
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    return rtrim($script, '/') . '/';
}

function checkBlacklistPermissions(string $path): array {
    $result = ['ok' => false, 'errors' => [], 'suggestions' => []];
    $isWindows = PHP_OS_FAMILY === 'Windows';

    if (empty($path)) {
        $result['errors'][] = 'Blacklist path is not configured.';
        $result['suggestions'][] = 'Set the blacklist file path in Admin > Settings.';
        return $result;
    }

    $dir = dirname($path);

    if (!is_dir($dir)) {
        $result['errors'][] = "Directory does not exist: $dir";
        if ($isWindows) {
            $result['suggestions'][] = "Create the directory manually or choose a path under your WAMP www folder.";
        } else {
            $result['suggestions'][] = "Create the directory: sudo mkdir -p $dir";
            $result['suggestions'][] = "Set ownership: sudo chown www-data:www-data $dir";
        }
        return $result;
    }

    if (file_exists($path)) {
        if (!is_readable($path)) {
            $result['errors'][] = "Blacklist file exists but is not readable: $path";
            if ($isWindows) {
                $result['suggestions'][] = "Right-click the file > Properties > Security tab > ensure the Apache/WAMP user has Read permission.";
            } else {
                $result['suggestions'][] = "Fix permissions: sudo chmod 644 $path";
                $result['suggestions'][] = "Fix ownership: sudo chown www-data:www-data $path";
            }
        }
        if (!is_writable($path)) {
            $result['errors'][] = "Blacklist file exists but is not writable: $path";
            if ($isWindows) {
                $result['suggestions'][] = "Right-click the file > Properties > Security tab > ensure the Apache/WAMP user has Write permission.";
                $result['suggestions'][] = "Uncheck 'Read-only' in file Properties > General tab.";
            } else {
                $result['suggestions'][] = "Fix permissions: sudo chmod 664 $path";
                $result['suggestions'][] = "Fix ownership: sudo chown www-data:www-data $path";
                $result['suggestions'][] = "For Nginx: ensure the php-fpm user (usually www-data) owns the file.";
            }
        }
    } else {
        // File doesn't exist yet — check if directory is writable (to create the file)
        if (!is_writable($dir)) {
            $result['errors'][] = "Directory is not writable (cannot create blacklist file): $dir";
            if ($isWindows) {
                $result['suggestions'][] = "Right-click the folder > Properties > Security tab > ensure the Apache/WAMP user has Write permission.";
            } else {
                $result['suggestions'][] = "Fix directory permissions: sudo chmod 775 $dir";
                $result['suggestions'][] = "Fix directory ownership: sudo chown www-data:www-data $dir";
                $result['suggestions'][] = "For Nginx: ensure the php-fpm user (usually www-data) has write access.";
            }
        } else {
            // Directory writable, file will be created on first block — that's fine
            $result['suggestions'][] = "File does not exist yet but directory is writable. It will be created automatically on first hash block.";
        }
    }

    if (empty($result['errors'])) {
        $result['ok'] = true;
    }

    return $result;
}

/**
 * Normalize a blacklist file path: strip null bytes and control characters that could be
 * used for path trickery or to smuggle data. Applied by every blacklist file operation so
 * all call sites are protected consistently (previously only delete_permanently did this).
 */
function normalizeBlacklistPath(string $path): string {
    return preg_replace('/[\x00-\x1F\x7F]/', '', trim($path));
}

/**
 * Check if a hash exists in the blacklist file.
 */
function isHashInBlacklist(string $hash, string $blacklistPath): bool {
    $blacklistPath = normalizeBlacklistPath($blacklistPath);
    if (empty($blacklistPath) || !file_exists($blacklistPath) || !is_readable($blacklistPath)) {
        return false;
    }
    $hashLower = strtolower(trim($hash));
    $lines = file($blacklistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strtolower(trim($line)) === $hashLower) {
            return true;
        }
    }
    return false;
}

/**
 * Add a hash to the blacklist file (skip if already present).
 * Returns true if written, false if already existed or error.
 */
function addHashToBlacklist(string $hash, string $blacklistPath): bool {
    $blacklistPath = normalizeBlacklistPath($blacklistPath);
    if (empty($blacklistPath)) return false;
    $hashLower = strtolower(trim($hash));
    // Only ever write a valid 40-hex info hash — blocks line-injection via crafted input.
    if (!isValidInfoHash($hashLower)) return false;

    if (file_exists($blacklistPath)) {
        if (!is_writable($blacklistPath)) return false;
        $lines = file($blacklistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strtolower(trim($line)) === $hashLower) {
                return true; // already present — no duplicate
            }
        }
    } else {
        if (!is_writable(dirname($blacklistPath))) return false;
    }

    file_put_contents($blacklistPath, $hashLower . "\n", FILE_APPEND | LOCK_EX);
    // A brand-new hash actually reached the file. OpenTracker only reads the blacklist at startup,
    // so record the change — the dashboard uses this to recommend a tracker restart.
    recordBlacklistChange('add');
    return true;
}

/**
 * Remove ALL occurrences of a hash from the blacklist file (case-insensitive).
 * Returns true if file was updated, false on error.
 */
function removeHashFromBlacklist(string $hash, string $blacklistPath): bool {
    $blacklistPath = normalizeBlacklistPath($blacklistPath);
    if (empty($blacklistPath) || !file_exists($blacklistPath) || !is_writable($blacklistPath)) {
        return false;
    }
    $hashLower = strtolower(trim($hash));
    $lines = file($blacklistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $filtered = array_filter($lines, fn($line) => strtolower(trim($line)) !== $hashLower);

    // Only write if something changed. Write a temp file in the same directory and rename() over the
    // original: OpenTracker re-reads the file on SIGHUP and would treat a half-written or momentarily
    // empty file as "no entries" — never let it observe an intermediate state.
    if (count($filtered) !== count($lines)) {
        $payload = $filtered ? implode("\n", $filtered) . "\n" : '';
        $tmp = $blacklistPath . '.tmp.' . getmypid();
        $written = false;
        if (is_writable(dirname($blacklistPath)) && @file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @chmod($tmp, 0664);
            $written = @rename($tmp, $blacklistPath);
            if (!$written) @unlink($tmp);
        }
        if (!$written) {
            // Directory not writable (legacy layout: file writable, dir not) — fall back to in-place rewrite.
            file_put_contents($blacklistPath, $payload, LOCK_EX);
        }
        // The file really changed — the tracker still holds the old copy until it restarts.
        recordBlacklistChange('del');
    }
    return true;
}

// --- Tracker service: restart recommendations -------------------------------
// OpenTracker (and similar) load the blacklist file only at startup and keep growing their uptime.
// The helpers below let the admin dashboard surface "smart" restart recommendations: they track how
// many blacklist changes have happened since the tracker last (re)started and how long it has been
// up, then classify that into orange/red advice. All state is derived from files under config/
// (web-denied) and the shared stats cache — no DB schema changes.

/**
 * Path to the append-only log of blacklist file changes (adds/removals). Lives under config/
 * (web-denied) alongside the other runtime state files.
 */
function blacklistChangesFile(): string {
    return __DIR__ . '/../config/blacklist_changes.json';
}

/** Record a single blacklist mutation ('add' or 'del') with a timestamp. Pruned + bounded. */
function recordBlacklistChange(string $op): void {
    $op   = ($op === 'del') ? 'del' : 'add';
    $file = blacklistChangesFile();
    $data = [];
    if (is_file($file)) {
        $raw  = @file_get_contents($file);
        $data = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    if (!is_array($data)) $data = [];
    $data[] = ['t' => time(), 'op' => $op];
    // Keep the log bounded: drop entries older than 90 days, then cap at the newest 1000.
    $cutoff = time() - 90 * 86400;
    $data = array_values(array_filter($data, fn($e) => is_array($e) && (int)($e['t'] ?? 0) >= $cutoff));
    if (count($data) > 1000) $data = array_slice($data, -1000);
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

/** Clear the blacklist change log — called once the tracker is (re)started from the panel. */
function resetBlacklistChanges(): void {
    $file = blacklistChangesFile();
    if (is_file($file)) @unlink($file);
}

/** Read the shared tracker stats cache. Returns the decoded array or null when unavailable. */
function readTrackerStatsCache(): ?array {
    $file = __DIR__ . '/../config/stats_cache.json';
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Best-effort tracker boot time (unix ts), derived from the cached stats as "when the cache was
 * written minus the uptime reported then". It stays stable across refreshes and jumps forward the
 * moment the tracker restarts — exactly the reference we need to tell whether pending blacklist
 * changes and the current uptime still warrant a restart. Returns null when stats are unavailable.
 */
function trackerBootTime(): ?int {
    $cache = readTrackerStatsCache();
    if (!$cache || empty($cache['success'])) return null;
    $fetchedAt = (int)($cache['fetched_at'] ?? 0);
    $uptime    = (int)($cache['uptime_seconds'] ?? 0);
    if ($fetchedAt <= 0 || $uptime <= 0) return null;
    return $fetchedAt - $uptime;
}

/** Is a string a safe systemd unit name we are willing to hand to systemctl? */
function isServiceNameValid(string $name): bool {
    return preg_match('/^[A-Za-z0-9._@-]{1,128}$/', $name) === 1;
}

/**
 * Pure classifier (no I/O, unit-tested): given the pending blacklist adds/removals since the
 * tracker booted and the current uptime in seconds (or null if unknown), plus the configured
 * thresholds, decide the overall severity ('none'|'warn'|'danger') and the list of individual
 * recommendation items. Warnings can stack, so the returned level is the highest of them all.
 */
function classifyTrackerWarnings(int $pendingAdds, int $pendingDels, ?int $uptimeSeconds, array $cfg): array {
    $warnCount   = max(1, (int)($cfg['tracker_blacklist_warn_count'] ?? 1));
    $dangerCount = max($warnCount, (int)($cfg['tracker_blacklist_danger_count'] ?? 5));
    $warnDays    = max(1, (int)($cfg['tracker_uptime_warn_days'] ?? 14));
    $dangerDays  = max($warnDays, (int)($cfg['tracker_uptime_danger_days'] ?? 30));

    $rank  = ['none' => 0, 'warn' => 1, 'danger' => 2];
    $level = 'none';
    $items = [];
    $bump  = function (string $l) use (&$level, $rank) {
        if ($rank[$l] > $rank[$level]) $level = $l;
    };

    $pendingTotal = $pendingAdds + $pendingDels;
    if ($pendingTotal > 0) {
        $blLevel = $pendingTotal >= $dangerCount ? 'danger' : ($pendingTotal >= $warnCount ? 'warn' : 'none');
        if ($blLevel !== 'none') {
            $parts = [];
            if ($pendingAdds > 0) $parts[] = $pendingAdds . ' added';
            if ($pendingDels > 0) $parts[] = $pendingDels . ' removed';
            $detail = implode(' · ', $parts);
            $text = $blLevel === 'danger'
                ? $pendingTotal . ' blacklist changes since last start (' . $detail . ') — restart required to apply them'
                : 'Blacklist changed since last start (' . $detail . ') — restart recommended to load it';
            $items[] = ['level' => $blLevel, 'text' => $text];
            $bump($blLevel);
        }
    }

    if ($uptimeSeconds !== null && $uptimeSeconds > 0) {
        $days = intdiv($uptimeSeconds, 86400);
        if ($days >= $dangerDays) {
            $items[] = ['level' => 'danger', 'text' => 'Tracker up for ' . $days . ' days — a restart is overdue'];
            $bump('danger');
        } elseif ($days >= $warnDays) {
            $items[] = ['level' => 'warn', 'text' => 'Tracker up for ' . $days . ' days — consider a periodic restart'];
            $bump('warn');
        }
    }

    return ['level' => $level, 'count' => count($items), 'items' => $items];
}

/**
 * Gather the live inputs (pending blacklist changes since boot, current uptime from the stats
 * cache) and classify them into restart recommendations for the admin dashboard. Read-only: it
 * self-corrects on any tracker restart because entries older than the boot reference are ignored.
 */
function getTrackerServiceWarnings(array $cfg): array {
    $boot   = trackerBootTime();
    $ref    = $boot ?? 0;                                   // 0 = no stats → count everything logged
    $uptime = $boot !== null ? max(0, time() - $boot) : null;

    $adds = 0;
    $dels = 0;
    $file = blacklistChangesFile();
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $log = $raw ? (json_decode($raw, true) ?: []) : [];
        if (is_array($log)) {
            foreach ($log as $e) {
                if (!is_array($e) || (int)($e['t'] ?? 0) <= $ref) continue;
                if (($e['op'] ?? 'add') === 'del') $dels++; else $adds++;
            }
        }
    }

    // In whitelist mode the file-change log is irrelevant (the whitelist service reloads by itself);
    // instead surface the whitelist file / reload / worker health as warnings.
    if (function_exists('trackerMode') && trackerMode($cfg) === 'whitelist') {
        $res = classifyTrackerWarnings(0, 0, $uptime, $cfg);
        $db = $GLOBALS['db'] ?? null;
        if ($db instanceof PDO && function_exists('whitelistStatus')) {
            try {
                $ws = whitelistStatus($db, $cfg);
                foreach ($ws['warnings'] as $w) {
                    $res['items'][] = $w;
                    if ($w['level'] === 'danger') $res['level'] = 'danger';
                    elseif ($w['level'] === 'warn' && $res['level'] === 'none') $res['level'] = 'warn';
                }
                $res['count'] = count($res['items']);
                $res['whitelist'] = ['active' => $ws['counts']['active'], 'pending_reload' => (bool)$ws['state']['pending_reload'], 'regen_needed' => (bool)$ws['state']['regen_needed']];
            } catch (\Throwable $e) {}
        }
        $adds = 0; $dels = 0;
    } else {
        $res = classifyTrackerWarnings($adds, $dels, $uptime, $cfg);
    }
    $res['mode']           = function_exists('trackerMode') ? trackerMode($cfg) : 'blacklist';
    $res['pending_adds']   = $adds;
    $res['pending_dels']   = $dels;
    $res['uptime_seconds'] = $uptime;
    $res['uptime_string']  = $uptime !== null ? formatUptime($uptime) : null;
    return $res;
}

// --- Tracker service: execute restart / reload ------------------------------
// Shared by the Restart/Reload endpoints, the permission Test, and the automatic post-change reload.
// A "restart" bounces the whole service (brief downtime); a "reload" asks systemd to run the unit's
// ExecReload — for a standard OpenTracker unit that is `/bin/kill -HUP $MAINPID`, i.e. the SIGHUP that
// makes OpenTracker re-read its white/blacklist WITHOUT dropping connections. Both clear the pending
// blacklist-change log on success, because the tracker then holds the current blacklist file.

/** Is PHP's exec() usable (present and not listed in disable_functions)? System actions need it. */
function trackerExecAvailable(): bool {
    $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
    return function_exists('exec') && !in_array('exec', $disabled, true);
}

/**
 * Build the shell command for `systemctl <verb> <service>` (verb = restart|reload) against the
 * configured unit, honouring the "Run via sudo" setting. Returns null when the feature can't run
 * (unknown verb, no/invalid service name, or exec() disabled). The service name is whitelisted AND
 * passed through escapeshellarg, so it can never be used to inject a second command.
 */
function trackerServiceCommand(string $verb, array $cfg): ?string {
    if ($verb !== 'restart' && $verb !== 'reload') return null;
    $service = trim((string)($cfg['opentracker_service_name'] ?? ''));
    if ($service === '' || !isServiceNameValid($service)) return null;
    if (!trackerExecAvailable()) return null;
    $useSudo = (($cfg['opentracker_restart_use_sudo'] ?? '1') === '1');
    return ($useSudo ? 'sudo -n ' : '') . 'systemctl ' . $verb . ' ' . escapeshellarg($service) . ' 2>&1';
}

/**
 * Run `systemctl <verb> <service>` and return ['ok','output','code','cmd']. ok is true only on a
 * zero exit code. When the command cannot be built (see trackerServiceCommand) ok is false, code -1.
 */
function runTrackerServiceCommand(string $verb, array $cfg): array {
    $cmd = trackerServiceCommand($verb, $cfg);
    if ($cmd === null) {
        return ['ok' => false, 'output' => '', 'code' => -1, 'cmd' => ''];
    }
    $output = [];
    $ret    = null;
    @exec($cmd, $output, $ret);
    return ['ok' => $ret === 0, 'output' => trim(implode("\n", $output)), 'code' => (int)$ret, 'cmd' => $cmd];
}

/**
 * Best-effort: after the blacklist file has changed, ask the tracker to reload it (SIGHUP via
 * `systemctl reload`) so the change applies immediately — no downtime, no manual restart. Only runs
 * when auto-reload is enabled AND a valid service is configured AND exec() is available; otherwise it
 * quietly does nothing and the dashboard's "restart recommended" hint remains as the fallback. On a
 * successful reload the pending-change log is cleared. Never throws. Returns a small status array for
 * the API response, or null when no reload was attempted.
 */
function autoReloadTrackerBlacklist(array $cfg): ?array {
    if (($cfg['opentracker_auto_reload'] ?? '1') !== '1') return null;
    $service = trim((string)($cfg['opentracker_service_name'] ?? ''));
    if ($service === '' || !isServiceNameValid($service) || !trackerExecAvailable()) return null;

    $res = runTrackerServiceCommand('reload', $cfg);
    if (function_exists('whitelistNoteReloaded')) whitelistNoteReloaded($res['ok'], $res['output']);
    if ($res['ok']) {
        resetBlacklistChanges();
        return ['attempted' => true, 'ok' => true];
    }
    return ['attempted' => true, 'ok' => false, 'output' => $res['output']];
}

/**
 * Archive an appeal: move from appeals to appeal_archives.
 */
function archiveAppeal(PDO $db, array $appeal): bool {
    try {
        $stmt = $db->prepare(
            "INSERT INTO appeal_archives (id, infoHash, report_id, name, email, message, appeal_type, status, admin_response, ip, timestamp, archived_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $appeal['id'], $appeal['infoHash'], $appeal['report_id'], $appeal['name'],
            $appeal['email'], $appeal['message'], $appeal['appeal_type'] ?? 'unblock',
            $appeal['status'], $appeal['admin_response'], $appeal['ip'], $appeal['timestamp']
        ]);
        $db->prepare("DELETE FROM appeals WHERE id = ?")->execute([$appeal['id']]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Auto-close and archive all other pending appeals for the same hash and appeal type.
 * Returns the count of closed appeals.
 */
function autoCloseRelatedAppeals(PDO $db, string $infoHash, string $appealType, int $excludeId, array $cfg): int {
    $stmt = $db->prepare("SELECT * FROM appeals WHERE infoHash = ? AND appeal_type = ? AND status = 'pending' AND id != ?");
    $stmt->execute([$infoHash, $appealType, $excludeId]);
    $related = $stmt->fetchAll();
    $count = 0;

    foreach ($related as $rel) {
        // Mark as closed with auto-response
        $db->prepare("UPDATE appeals SET status = 'rejected', admin_response = ? WHERE id = ?")
           ->execute(['Automatically closed: another appeal for this hash has been resolved.', $rel['id']]);

        // Re-fetch updated record
        $stmt2 = $db->prepare("SELECT * FROM appeals WHERE id = ?");
        $stmt2->execute([$rel['id']]);
        $updated = $stmt2->fetch();
        if ($updated) {
            archiveAppeal($db, $updated);
        }

        // Notify appellant
        if (!empty($rel['email']) && !isUnsubscribed($db, $rel['email'], 'appeal')) {
            try {
                ob_start();
                $subject = 'Appeal Closed — ' . ($cfg['site_name'] ?? 'Tracker');
                $body = "Your " . ($appealType === 'block' ? 'block request' : 'unblock appeal') .
                        " for the info hash below has been automatically closed because another appeal for the same hash has been resolved.";
                $details = [
                    'Info Hash' => '<code>' . sanitize($rel['infoHash']) . '</code>',
                    'Request Type' => $appealType === 'block' ? 'Block Request' : 'Unblock Appeal',
                    'Decision' => '<strong>Automatically Closed</strong>',
                ];
                $unsubUrl = getUnsubscribeUrl($rel['email'], $cfg);
                $htmlBody = buildEmailHtml([
                    'title' => $subject,
                    'greeting' => 'Hello ' . sanitize($rel['name']),
                    'body' => $body,
                    'details' => $details,
                    'unsubscribe_url' => $unsubUrl,
                ], $cfg);
                $plainText = 'Your appeal for hash ' . $rel['infoHash'] . ' has been automatically closed.';
                @sendEmail($rel['email'], $subject, $plainText, $htmlBody, $cfg, $unsubUrl);
                ob_end_clean();
            } catch (\Throwable $e) {
                if (ob_get_level()) ob_end_clean();
            }
        }
        $count++;
    }
    return $count;
}

/**
 * Move a single report row into the archives table and delete it from the reports table,
 * atomically (transaction). Idempotent: if the id is already archived, the archive row is
 * updated in place and the source row is still removed — a duplicate id can never abort the
 * caller or leave a report half-processed. Returns true on success.
 *
 * Shared by block_hash, delete_all and autoArchiveOldReports so the archive logic lives in
 * exactly one place.
 */
function archiveReport(PDO $db, array $r): bool {
    try {
        $db->beginTransaction();
        $ins = $db->prepare(
            "INSERT INTO archives (id, name, representative, company, email, objectTitle, link, infoHash, magnet_link, ip, add_message, checked, blocked, timestamp)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE checked = VALUES(checked), blocked = VALUES(blocked)"
        );
        $ins->execute([
            $r['id'], $r['name'], $r['representative'], $r['company'], $r['email'],
            $r['objectTitle'], $r['link'], $r['infoHash'], $r['magnet_link'] ?? null,
            $r['ip'], $r['add_message'], $r['checked'], $r['blocked'], $r['timestamp']
        ]);
        $db->prepare("DELETE FROM reports WHERE id = ?")->execute([$r['id']]);
        $db->commit();
        return true;
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        return false;
    }
}

/**
 * Throttle background janitor tasks that would otherwise run on every single request.
 * Uses a small timestamp marker under config/ (that dir is web-denied). Returns true at
 * most once per $intervalSec; the marker is updated up-front so concurrent requests only
 * let one through.
 */
function autoArchiveDue(string $marker, array $cfg): bool {
    $interval = max(30, (int)($cfg['auto_archive_throttle_seconds'] ?? 300));
    $file = __DIR__ . '/../config/archive_' . preg_replace('/[^a-z0-9_]/', '', $marker) . '.marker';
    $now = time();
    if (is_file($file)) {
        $last = (int)@file_get_contents($file);
        if ($last > 0 && ($now - $last) < $interval) return false;
    }
    @file_put_contents($file, (string)$now, LOCK_EX);
    return true;
}

/**
 * Auto-archive reviewed (checked, not blocked) reports older than X days.
 * Throttled so it runs at most once per interval, not on every request.
 */
function autoArchiveOldReports(PDO $db, array $cfg): int {
    $days = (int)($cfg['auto_archive_days'] ?? 90);
    if ($days <= 0) return 0;
    if (!autoArchiveDue('reports', $cfg)) return 0;

    $stmt = $db->prepare(
        "SELECT * FROM reports WHERE checked = 1 AND blocked = 0 AND timestamp < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 50"
    );
    $stmt->execute([$days]);
    $old = $stmt->fetchAll();
    $count = 0;

    foreach ($old as $r) {
        if (archiveReport($db, $r)) $count++;
    }
    return $count;
}

/**
 * Auto-archive resolved (accepted/rejected) appeals older than X days.
 * Called on each request — lightweight query with LIMIT.
 */
function autoArchiveOldAppeals(PDO $db, array $cfg): int {
    $days = (int)($cfg['auto_archive_appeal_days'] ?? 90);
    if ($days <= 0) return 0;
    if (!autoArchiveDue('appeals', $cfg)) return 0;

    $stmt = $db->prepare(
        "SELECT * FROM appeals WHERE status IN ('accepted', 'rejected') AND timestamp < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 50"
    );
    $stmt->execute([$days]);
    $old = $stmt->fetchAll();
    $count = 0;

    foreach ($old as $a) {
        if (archiveAppeal($db, $a)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Optional retention: delete sent_emails rows older than N days. Disabled by default
 * (sent_emails_retention_days = 0) so existing behavior is unchanged. Throttled like the
 * other janitors; $days is cast to int and inlined (never user-injectable).
 */
function pruneOldSentEmails(PDO $db, array $cfg): int {
    $days = (int)($cfg['sent_emails_retention_days'] ?? 0);
    if ($days <= 0) return 0;
    if (!autoArchiveDue('sentemails', $cfg)) return 0;

    $stmt = $db->query("DELETE FROM sent_emails WHERE timestamp < DATE_SUB(NOW(), INTERVAL $days DAY) LIMIT 500");
    return $stmt ? $stmt->rowCount() : 0;
}

function obfuscateEmail(string $email): string {
    $codes = [];
    for ($i = 0; $i < strlen($email); $i++) {
        $codes[] = ord($email[$i]);
    }
    return json_encode($codes);
}
