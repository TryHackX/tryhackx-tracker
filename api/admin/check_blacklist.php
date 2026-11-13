<?php
$blacklistPath = '';

// Check if custom path was sent via POST (JSON or form data)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (isset($input['blacklist_path'])) {
        $blacklistPath = trim((string)$input['blacklist_path']);
    } elseif (isset($_POST['blacklist_path'])) {
        $blacklistPath = trim((string)$_POST['blacklist_path']);
    }
}

// Fallback to configured path if none provided or if it's GET
if ($blacklistPath === '') {
    $blacklistPath = $cfg['blacklist_path'] ?? '';
}

// Basic security sanitization: prevent null bytes & control chars
$blacklistPath = str_replace(["\r", "\n", "\0", "\x00"], '', $blacklistPath);

$result = checkBlacklistPermissions($blacklistPath);

$result['path'] = $blacklistPath;
$result['os'] = PHP_OS_FAMILY;
$result['php_user'] = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();

jsonResponse($result);
