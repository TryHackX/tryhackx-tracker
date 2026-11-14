<?php
$whitelistPath = '';

// Custom path may be sent via POST (JSON or form data) — the settings "Test" button
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = readJsonBody();
    if (isset($input['whitelist_path'])) {
        $whitelistPath = trim((string)$input['whitelist_path']);
    } elseif (isset($_POST['whitelist_path'])) {
        $whitelistPath = trim((string)$_POST['whitelist_path']);
    }
}

// Fallback to configured path if none provided or if it's GET
if ($whitelistPath === '') {
    $whitelistPath = $cfg['whitelist_path'] ?? '';
}

// Basic security sanitization: prevent null bytes & control chars
$whitelistPath = str_replace(["\r", "\n", "\0", "\x00"], '', $whitelistPath);

$result = checkListFilePermissions($whitelistPath, 'whitelist', true);

$result['path'] = $whitelistPath;
$result['os'] = PHP_OS_FAMILY;
$result['php_user'] = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
$result['file'] = null;
if ($whitelistPath !== '' && is_file($whitelistPath)) {
    $size = (int)@filesize($whitelistPath);
    $stat = @stat($whitelistPath);
    $owner = null;
    if ($stat && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid((int)$stat['uid']);
        $owner = $pw['name'] ?? (string)$stat['uid'];
    } elseif ($stat) {
        $owner = (string)$stat['uid'];
    }
    $result['file'] = [
        'exists' => true,
        'lines'  => (int)floor($size / 41),
        'size'   => $size,
        'mode'   => $stat ? substr(sprintf('%o', $stat['mode']), -4) : null,
        'owner'  => $owner,
    ];
}

jsonResponse($result);
