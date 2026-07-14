<?php
/**
 * TryHackX Tracker - Installation Wizard
 * Delete this file after installation!
 */
session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'cookie_samesite' => 'Lax',
]);

// Whether installation has already completed. Gates the installer's AJAX + wizard steps
// so that, if install.php is left on the server, it can't be used as an open endpoint.
$installed = file_exists(__DIR__ . '/config/installed.lock');

// Handle blacklist path test (AJAX from step 3)
if (isset($_GET['test_blacklist']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    // Refuse to probe the filesystem once installed — otherwise a left-behind installer is an
    // unauthenticated path-enumeration / PHP-user disclosure endpoint.
    if ($installed) {
        echo json_encode(['ok' => false, 'msg' => 'Installer is locked (already installed). Delete install.php.']);
        exit;
    }
    $path = trim($_POST['path'] ?? '');
    $isWin = PHP_OS_FAMILY === 'Windows';
    $phpUser = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();

    if (empty($path)) {
        echo json_encode(['ok' => false, 'msg' => 'Path is empty.']);
        exit;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        $hint = $isWin ? 'Create the directory manually.' : "Create it: sudo mkdir -p $dir && sudo chown www-data:www-data $dir";
        echo json_encode(['ok' => false, 'msg' => "Directory does not exist: $dir", 'hint' => $hint, 'user' => $phpUser]);
        exit;
    }
    if (file_exists($path)) {
        $readable = is_readable($path);
        $writable = is_writable($path);
        if ($readable && $writable) {
            echo json_encode(['ok' => true, 'msg' => 'Path is accessible and writable.', 'user' => $phpUser]);
        } else {
            $errs = [];
            if (!$readable) $errs[] = 'not readable';
            if (!$writable) $errs[] = 'not writable';
            echo json_encode(['ok' => false, 'msg' => 'File exists but is ' . implode(' and ', $errs) . '.', 'user' => $phpUser]);
        }
    } else {
        if (is_writable($dir)) {
            echo json_encode(['ok' => true, 'msg' => "File does not exist yet, but the directory is writable. File will be created automatically.", 'user' => $phpUser]);
        } else {
            $hint = $isWin ? 'Ensure the Apache/WAMP user has write permission on the directory.' : "Fix: sudo chown www-data:www-data $dir";
            echo json_encode(['ok' => false, 'msg' => "Directory exists but is not writable: $dir", 'hint' => $hint, 'user' => $phpUser]);
        }
    }
    exit;
}

// Handle cleanup request (delete installer files)
if (isset($_GET['cleanup']) && $_GET['cleanup'] === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $deleted = [];
    $installFile = __DIR__ . '/install.php';
    // Self-delete: we use unlink then redirect
    if (file_exists($installFile)) {
        @unlink($installFile);
        $deleted[] = 'install.php';
    }
    // Redirect to homepage after deletion
    header('Location: ./');
    exit;
}

$step = (int)($_GET['step'] ?? 1);
$error = '';
$success = '';

// Check if already installed (the cleanup/self-delete handler above stays allowed on purpose)
if ($installed) {
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Already Installed</title>
    <style>body{background:#0a0a1a;color:#e0e0e0;font-family:monospace;display:flex;align-items:center;justify-content:center;min-height:100vh;}
    .box{background:#111;border:1px solid #333;padding:2rem;border-radius:8px;text-align:center;max-width:400px;}
    h2{color:#f44336;margin-bottom:1rem;}a{color:#4a9eff;}</style></head>
    <body><div class="box"><h2>Already Installed</h2><p>The tracker is already configured. Delete <code>install.php</code> for security.</p>
    <p><a href="./">Go to site</a></p></div></body></html>');
}

// Process Step 2 - Database
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    try {
        // Database name goes into `CREATE DATABASE ...` and the generated config, so restrict
        // it to a safe identifier charset.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            throw new PDOException('Database name may only contain letters, numbers and underscores.');
        }
        $pdo = new PDO(
            "mysql:host=$dbHost;charset=utf8mb4",
            $dbUser, $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbName`");

        // Create tables
        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `key` VARCHAR(100) PRIMARY KEY,
            `value` TEXT NOT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `reports` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `representative` VARCHAR(255) NOT NULL,
            `company` VARCHAR(255) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `objectTitle` VARCHAR(255) NOT NULL,
            `link` VARCHAR(500) NOT NULL,
            `infoHash` VARCHAR(40) NOT NULL,
            `magnet_link` TEXT DEFAULT NULL,
            `ip` VARCHAR(45) NOT NULL,
            `add_message` TEXT DEFAULT '',
            `checked` TINYINT(1) DEFAULT 0,
            `blocked` TINYINT(1) DEFAULT 0,
            `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_reports_infohash` (`infoHash`),
            INDEX `idx_reports_ip_time` (`ip`, `timestamp`),
            INDEX `idx_reports_status` (`checked`, `blocked`, `timestamp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `sent_emails` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `report_id` INT NOT NULL,
            `to_email` VARCHAR(255) NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `message` TEXT,
            `info_hash` VARCHAR(40),
            `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `archives` (
            `id` INT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `representative` VARCHAR(255) NOT NULL,
            `company` VARCHAR(255) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `objectTitle` VARCHAR(255) NOT NULL,
            `link` VARCHAR(500) NOT NULL,
            `infoHash` VARCHAR(40) NOT NULL,
            `magnet_link` TEXT DEFAULT NULL,
            `ip` VARCHAR(45) NOT NULL,
            `add_message` TEXT DEFAULT '',
            `checked` TINYINT(1) DEFAULT 0,
            `blocked` TINYINT(1) DEFAULT 0,
            `timestamp` DATETIME,
            INDEX `idx_archives_infohash` (`infoHash`),
            INDEX `idx_archives_status` (`checked`, `blocked`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `appeals` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `infoHash` VARCHAR(40) NOT NULL,
            `report_id` INT DEFAULT NULL,
            `name` VARCHAR(255) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `appeal_type` VARCHAR(20) DEFAULT 'unblock',
            `status` VARCHAR(20) DEFAULT 'pending',
            `admin_response` TEXT DEFAULT NULL,
            `ip` VARCHAR(45) NOT NULL,
            `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_appeals_infohash` (`infoHash`),
            INDEX `idx_appeals_status_time` (`status`, `timestamp`),
            INDEX `idx_appeals_ip_time` (`ip`, `timestamp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `appeal_archives` (
            `id` INT PRIMARY KEY,
            `infoHash` VARCHAR(40) NOT NULL,
            `report_id` INT DEFAULT NULL,
            `name` VARCHAR(255) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `appeal_type` VARCHAR(20) DEFAULT 'unblock',
            `status` VARCHAR(20) DEFAULT 'pending',
            `admin_response` TEXT DEFAULT NULL,
            `ip` VARCHAR(45) NOT NULL,
            `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `archived_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `unsubscribed_emails` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE,
            `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `email_preferences` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL,
            `type` VARCHAR(30) NOT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `email_type` (`email`, `type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Save DB config
        $_SESSION['install_db'] = [
            'host' => $dbHost,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass,
        ];
        header('Location: install.php?step=3');
        exit;

    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
        $step = 2;
    }
}

// Process Step 3 - Admin + Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminPass = $_POST['admin_pass'] ?? '';
    $adminPass2 = $_POST['admin_pass2'] ?? '';
    $siteUrl = rtrim(trim($_POST['site_url'] ?? ''), '/');
    $siteEmail = trim($_POST['site_email'] ?? '');
    $siteName = trim($_POST['site_name'] ?? 'My Tracker');
    $announceUrl = trim($_POST['announce_url'] ?? '');
    $announceUrlHttps = trim($_POST['announce_url_https'] ?? '');
    $recaptchaSite = trim($_POST['recaptcha_site'] ?? '');
    $recaptchaSecret = trim($_POST['recaptcha_secret'] ?? '');
    $blacklistPath = trim($_POST['blacklist_path'] ?? '/home/tracker/blacklist');

    if (strlen($adminUser) < 3) { $error = 'Username must be at least 3 characters.'; }
    elseif (strlen($adminPass) < 10) { $error = 'Password must be at least 10 characters long.'; }
    elseif (!preg_match('/[a-z]/', $adminPass)) { $error = 'Password must contain at least one lowercase letter.'; }
    elseif (!preg_match('/[A-Z]/', $adminPass)) { $error = 'Password must contain at least one uppercase letter.'; }
    elseif (!preg_match('/[0-9]/', $adminPass)) { $error = 'Password must contain at least one digit.'; }
    elseif (!preg_match('/[^a-zA-Z0-9]/', $adminPass)) { $error = 'Password must contain at least one special character.'; }
    elseif ($adminPass !== $adminPass2) { $error = 'Passwords do not match.'; }
    elseif (!$siteUrl) { $error = 'Site URL is required.'; }
    elseif (!$siteEmail) { $error = 'Contact email is required.'; }
    else {
        $db = $_SESSION['install_db'] ?? null;
        if (!$db) { header('Location: install.php?step=2'); exit; }

        try {
            $pdo = new PDO(
                "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
                $db['user'], $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Generate HMAC secret
            $hmacSecret = bin2hex(random_bytes(32));

            // Hash the admin password and write to file
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            file_put_contents(__DIR__ . '/config/hash.txt', $hash);

            // Write database config. Every dynamic value (DSN, user, pass) is emitted via
            // var_export so quotes/special chars can't break out of the generated PHP string.
            $dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
            $dbConfig = "<?php\n\nfunction getDb(): PDO {\n    static \$pdo = null;\n    if (\$pdo === null) {\n        \$pdo = new PDO(\n            " . var_export($dsn, true) . ",\n            " . var_export($db['user'], true) . ",\n            " . var_export($db['pass'], true) . ",\n            [\n                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n                PDO::ATTR_EMULATE_PREPARES => false,\n            ]\n        );\n    }\n    return \$pdo;\n}\n";
            file_put_contents(__DIR__ . '/config/database.php', $dbConfig);

            // Insert default settings into DB
            $defaults = [
                'site_name' => $siteName,
                'site_email' => $siteEmail,
                'site_url' => $siteUrl,
                'admin_username' => $adminUser,
                'recaptcha_site_key' => $recaptchaSite,
                'recaptcha_secret' => $recaptchaSecret,
                'recaptcha_enabled' => $recaptchaSite ? '1' : '0',
                'recaptcha_on_report' => '1',
                'recaptcha_on_login' => '1',
                'recaptcha_on_status' => '0',
                'recaptcha_on_appeal' => '0',
                'recaptcha_on_block_check' => '0',
                'captcha_threshold' => '6',
                'captcha_grace_minutes' => '5',
                'captcha_pts_report' => '2',
                'captcha_pts_status' => '1',
                'captcha_pts_appeal' => '3',
                'captcha_pts_block_check' => '1',
                'captcha_pts_login_fail' => '6',
                'delete_captcha_attempts' => '2',
                'delete_lockout_attempts' => '5',
                'delete_lockout_minutes' => '60',
                'login_lockout_attempts' => '5',
                'login_lockout_minutes' => '15',
                'rate_limit_status' => '20',
                'rate_limit_block_check' => '30',
                'rate_limit_appeal' => '5',
                'admin_session_idle_minutes' => '30',
                'admin_session_absolute_hours' => '12',
                'trusted_proxy_ips' => '',
                'client_ip_header' => '',
                'auto_archive_days' => '90',
                'auto_archive_appeal_days' => '90',
                'sent_emails_retention_days' => '0',
                'max_magnet_link_length' => '0',
                'blacklist_path' => $blacklistPath,
                'rate_limit' => '5',
                'items_per_page' => '15',
                'max_message_length' => '2000',
                'max_appeal_message_length' => '2000',
                'hmac_secret' => $hmacSecret,
                'announce_url' => $announceUrl,
                'announce_url_https' => $announceUrlHttps,
                'contact_visible' => '1',
                'contact_obfuscate' => '1',
                'donations_enabled' => '0',
                'donation_fields' => '[]',
                'transparency_enabled' => '1',
                'transparency_per_page' => '150',
                'github_url' => '',
                'tracker_stats_enabled' => '0',
                'tracker_stats_url' => '',
                'tracker_stats_interval' => '10',
                'tracker_stats_page_interval' => '5',
                'tracker_stats_cache_ttl' => '60',
                'tracker_stats_show_home' => '1',
                'tracker_stats_timeout' => '30',
                'tracker_stats_min_loading' => '1000',
                'tracker_stats_max_loading' => '1000',
                'tracker_stats_peer_label_style' => 'percent',
                'tracker_stats_livesync_mode' => 'upstream',
                'opentracker_service_name' => '',
                'opentracker_restart_use_sudo' => '1',
                'opentracker_auto_reload' => '1',
                'tracker_blacklist_warn_count' => '1',
                'tracker_blacklist_danger_count' => '5',
                'tracker_uptime_warn_days' => '14',
                'tracker_uptime_danger_days' => '30',
                'footer_start_year' => date('Y'),
                'footer_brand_name' => $siteName,
                'footer_brand_url' => $siteUrl,
                'footer_brand_enabled' => '1',
                'footer_tracker_name' => 'OpenTracker',
                'footer_tracker_url' => 'https://erdgeist.org/arts/software/opentracker/',
                'footer_tracker_author' => 'Dirk Engling',
                'footer_tracker_author_url' => 'https://erdgeist.org/',
                'footer_tracker_enabled' => '1',
                'footer_os_name' => 'Debian',
                'footer_os_url' => 'https://www.debian.org/',
                'footer_os_enabled' => '1',
                'footer_os_since_year' => date('Y'),
            ];

            $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            foreach ($defaults as $k => $v) {
                $stmt->execute([$k, $v]);
            }

            // Create lock file
            file_put_contents(__DIR__ . '/config/installed.lock', date('Y-m-d H:i:s'));

            $_SESSION = [];
            session_destroy();

            header('Location: install.php?step=4');
            exit;

        } catch (Exception $e) {
            $error = 'Setup error: ' . $e->getMessage();
        }
    }
    $step = 3;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - TryHackX Tracker</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0a0a1a; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .installer { background: #111; border: 1px solid #333; border-radius: 8px; padding: 2rem; max-width: 600px; width: 100%; }
        h1 { color: #4a9eff; margin-bottom: 0.5rem; font-size: 1.4rem; }
        h2 { color: #BAD7FF; margin-bottom: 1rem; font-size: 1.1rem; }
        .steps { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; }
        .step { padding: 0.3rem 0.8rem; border-radius: 4px; font-size: 0.8rem; background: #222; color: #666; }
        .step.active { background: #4a9eff; color: #000; }
        .step.done { background: #2a4a2a; color: #4caf50; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; color: #bbb; margin-bottom: 0.3rem; font-size: 0.85rem; }
        label small { color: #666; }
        input[type="text"], input[type="password"], input[type="email"], input[type="url"], input[type="number"] {
            width: 100%; padding: 0.5rem 0.7rem; background: #1a1a2e; border: 1px solid #333; border-radius: 4px;
            color: #e0e0e0; font-family: inherit; font-size: 0.85rem;
        }
        input:focus { outline: none; border-color: #4a9eff; }
        .btn { padding: 0.6rem 1.5rem; background: #4a9eff; color: #000; border: none; border-radius: 4px; cursor: pointer; font-family: inherit; font-size: 0.9rem; }
        .btn:hover { opacity: 0.85; }
        .error { background: rgba(244,67,54,0.15); border: 1px solid #f44336; color: #f44336; padding: 0.5rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.85rem; }
        .success { background: rgba(76,175,80,0.15); border: 1px solid #4caf50; color: #4caf50; padding: 0.5rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.85rem; }
        .info { background: rgba(74,158,255,0.1); border: 1px solid #4a9eff33; color: #8ab4f8; padding: 0.5rem; border-radius: 4px; margin-bottom: 1rem; font-size: 0.8rem; }
        hr { border: none; border-top: 1px solid #333; margin: 1.25rem 0; }
        a { color: #4a9eff; }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .pw-req { display: inline-block; width: 48%; color: #555; transition: color .2s; }
        .pw-req.ok { color: #4caf50; }
        .pw-req.fail { color: #f44336; }
        @media (max-width: 500px) { .two-col { grid-template-columns: 1fr; } .pw-req { width: 100%; } }
    </style>
</head>
<body>
<div class="installer">
    <h1>TryHackX Tracker</h1>
    <div class="steps">
        <span class="step <?= $step === 1 ? 'active' : ($step > 1 ? 'done' : '') ?>">1. Welcome</span>
        <span class="step <?= $step === 2 ? 'active' : ($step > 2 ? 'done' : '') ?>">2. Database</span>
        <span class="step <?= $step === 3 ? 'active' : ($step > 3 ? 'done' : '') ?>">3. Settings</span>
        <span class="step <?= $step === 4 ? 'active' : '' ?>">4. Done</span>
    </div>

    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($step === 1): ?>
        <h2>Installation Wizard</h2>
        <p style="margin-bottom:1rem;color:#aaa;">This wizard will configure your BitTorrent tracker information and reporting system.</p>
        <div class="info">
            <strong>Requirements:</strong><br>
            &bull; PHP 8.0+ with PDO MySQL<br>
            &bull; MySQL 5.7+ or MariaDB 10.3+<br>
            &bull; Apache with mod_rewrite (optional)
        </div>
        <p style="margin-bottom:1rem;">
            <?php
            $checks = [
                'PHP ' . PHP_VERSION => version_compare(PHP_VERSION, '8.0.0', '>='),
                'PDO MySQL' => extension_loaded('pdo_mysql'),
                'JSON' => extension_loaded('json'),
                'OpenSSL' => extension_loaded('openssl'),
                'Config writable' => is_writable(__DIR__ . '/config/'),
            ];
            foreach ($checks as $name => $ok): ?>
                <span style="color:<?= $ok ? '#4caf50' : '#f44336' ?>;"><?= $ok ? '&check;' : '&cross;' ?> <?= $name ?></span><br>
            <?php endforeach; ?>
        </p>
        <a href="install.php?step=2" class="btn">Start Installation</a>

    <?php elseif ($step === 2): ?>
        <h2>Database Configuration</h2>
        <form method="post" action="install.php?step=2">
            <div class="form-group">
                <label>Database Host</label>
                <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
            </div>
            <div class="form-group">
                <label>Database Name <small>(will be created if missing)</small></label>
                <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'tracker') ?>" required>
            </div>
            <div class="two-col">
                <div class="form-group">
                    <label>Database User</label>
                    <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>" required>
                </div>
                <div class="form-group">
                    <label>Database Password</label>
                    <input type="password" name="db_pass" value="">
                </div>
            </div>
            <button type="submit" class="btn">Test &amp; Create Database</button>
        </form>

    <?php elseif ($step === 3): ?>
        <h2>Site &amp; Admin Settings</h2>
        <form method="post" action="install.php?step=3">
            <h3 style="color:#4a9eff;margin-bottom:0.5rem;font-size:0.95rem;">Admin Account</h3>
            <div class="form-group">
                <label>Admin Username</label>
                <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" minlength="3" required>
            </div>
            <div class="two-col">
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="admin_pass" id="admin_pass" minlength="10" required>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="admin_pass2" id="admin_pass2" minlength="10" required>
                </div>
            </div>
            <div id="pw-reqs" style="margin:-0.5rem 0 1rem;font-size:0.78rem;line-height:1.7;">
                <span id="pw-len" class="pw-req">&#9679; At least 10 characters</span>
                <span id="pw-low" class="pw-req">&#9679; Lowercase letter (a-z)</span>
                <span id="pw-up" class="pw-req">&#9679; Uppercase letter (A-Z)</span>
                <span id="pw-dig" class="pw-req">&#9679; Digit (0-9)</span>
                <span id="pw-spc" class="pw-req">&#9679; Special character (!@#$...)</span>
                <span id="pw-match" class="pw-req" style="display:none;">&#9679; Passwords match</span>
            </div>
            <div id="pw-strength" style="margin:-0.5rem 0 1rem;">
                <div style="height:4px;background:#222;border-radius:2px;overflow:hidden;">
                    <div id="pw-bar" style="height:100%;width:0;transition:all .3s;border-radius:2px;"></div>
                </div>
                <small id="pw-label" style="color:#666;font-size:0.75rem;"></small>
            </div>

            <hr>
            <h3 style="color:#4a9eff;margin-bottom:0.5rem;font-size:0.95rem;">Site Configuration</h3>
            <div class="form-group">
                <label>Site Name</label>
                <input type="text" name="site_name" value="<?= htmlspecialchars($_POST['site_name'] ?? 'My Tracker') ?>" required>
            </div>
            <div class="two-col">
                <div class="form-group">
                    <label>Site URL <small>(no trailing slash)</small></label>
                    <input type="url" name="site_url" value="<?= htmlspecialchars($_POST['site_url'] ?? '') ?>" placeholder="https://tracker.example.com" required>
                </div>
                <div class="form-group">
                    <label>Contact Email</label>
                    <input type="email" name="site_email" value="<?= htmlspecialchars($_POST['site_email'] ?? '') ?>" placeholder="tracker@example.com" required>
                </div>
            </div>

            <hr>
            <h3 style="color:#4a9eff;margin-bottom:0.5rem;font-size:0.95rem;">Tracker Announce URLs</h3>
            <div class="two-col">
                <div class="form-group">
                    <label>Announce URL (HTTP/S)</label>
                    <input type="text" name="announce_url_https" value="<?= htmlspecialchars($_POST['announce_url_https'] ?? '') ?>" placeholder="https://tracker.example.com:6969/announce">
                </div>
                <div class="form-group">
                    <label>Announce URL (UDP)</label>
                    <input type="text" name="announce_url" value="<?= htmlspecialchars($_POST['announce_url'] ?? '') ?>" placeholder="udp://tracker.example.com:6969/announce">
                </div>
            </div>

            <hr>
            <h3 style="color:#4a9eff;margin-bottom:0.5rem;font-size:0.95rem;">reCAPTCHA v2 <small style="color:#666;">(optional)</small></h3>
            <div class="two-col">
                <div class="form-group">
                    <label>Site Key</label>
                    <input type="text" name="recaptcha_site" value="<?= htmlspecialchars($_POST['recaptcha_site'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Secret Key</label>
                    <input type="password" name="recaptcha_secret" value="<?= htmlspecialchars($_POST['recaptcha_secret'] ?? '') ?>" autocomplete="off">
                </div>
            </div>

            <hr>
            <h3 style="color:#4a9eff;margin-bottom:0.5rem;font-size:0.95rem;">Blacklist File <small style="color:#666;">(newline-separated hash list)</small></h3>
            <div class="form-group">
                <label>Blacklist File Path</label>
                <div style="display:flex;gap:0.5rem;">
                    <input type="text" name="blacklist_path" id="blacklist_path" value="<?= htmlspecialchars($_POST['blacklist_path'] ?? '/home/tracker/blacklist') ?>" style="flex:1;">
                    <button type="button" class="btn" style="background:#333;color:#4a9eff;padding:0.5rem 1rem;font-size:0.8rem;white-space:nowrap;" onclick="testBlacklist()">Test</button>
                </div>
                <div id="bl-result" style="margin-top:0.4rem;font-size:0.8rem;"></div>
            </div>

            <button type="submit" class="btn">Complete Installation</button>
        </form>

    <?php elseif ($step === 4): ?>
        <h2>Installation Complete!</h2>
        <div class="success">Your tracker has been configured successfully.</div>
        <div class="info" style="margin-top:1rem;">
            <strong>Important:</strong> Delete the installer file from your server for security!
        </div>
        <div style="margin:1.25rem 0;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;">
            <a href="./" class="btn">Open Tracker</a>
            <a href="./?action=admin" style="color:#aaa;">Go to Admin Panel</a>
        </div>
        <hr>
        <form method="post" action="install.php?step=4&cleanup=1" style="margin-top:1rem;" id="cleanup-form">
            <p style="color:#ff9800;font-size:0.85rem;margin-bottom:0.75rem;"><strong>&#9888; Security Cleanup</strong> — This will permanently delete the installer file so it cannot be accessed by anyone.</p>
            <button type="submit" class="btn" style="background:#f44336;color:#fff;" onclick="return confirm('Are you sure? This will permanently delete the install file.')">
                &#128465; Delete install.php
            </button>
        </form>
    <?php endif; ?>
</div>
<script>
async function testBlacklist() {
    const el = document.getElementById('bl-result');
    const path = document.getElementById('blacklist_path');
    if (!path) return;
    el.innerHTML = '<span style="color:#4a9eff;">Testing...</span>';
    try {
        const form = new FormData();
        form.append('path', path.value);
        const res = await fetch('install.php?test_blacklist=1', { method: 'POST', body: form });
        const json = await res.json();
        if (json.ok) {
            el.innerHTML = '<span style="color:#4caf50;">&#10003; ' + json.msg + '</span>' +
                (json.user ? '<br><small style="color:#a0a0b0;">PHP user: ' + json.user + '</small>' : '');
        } else {
            el.innerHTML = '<span style="color:#f44336;">&#10007; ' + json.msg + '</span>' +
                (json.hint ? '<br><small style="color:#ff9800;">' + json.hint + '</small>' : '') +
                (json.user ? '<br><small style="color:#a0a0b0;">PHP user: ' + json.user + '</small>' : '');
        }
    } catch {
        el.innerHTML = '<span style="color:#f44336;">Network error</span>';
    }
}

// Password validation
(function() {
    const p1 = document.getElementById('admin_pass');
    const p2 = document.getElementById('admin_pass2');
    if (!p1 || !p2) return;

    const rules = {
        'pw-len':   v => v.length >= 10,
        'pw-low':   v => /[a-z]/.test(v),
        'pw-up':    v => /[A-Z]/.test(v),
        'pw-dig':   v => /[0-9]/.test(v),
        'pw-spc':   v => /[^a-zA-Z0-9]/.test(v),
    };

    const bar = document.getElementById('pw-bar');
    const label = document.getElementById('pw-label');
    const matchEl = document.getElementById('pw-match');

    function check() {
        const v = p1.value;
        let score = 0;
        for (const [id, fn] of Object.entries(rules)) {
            const el = document.getElementById(id);
            const ok = fn(v);
            if (ok) score++;
            el.className = v.length === 0 ? 'pw-req' : ('pw-req ' + (ok ? 'ok' : 'fail'));
        }

        // Match check
        if (p2.value.length > 0) {
            matchEl.style.display = 'inline-block';
            const m = v === p2.value;
            matchEl.className = 'pw-req ' + (m ? 'ok' : 'fail');
        } else {
            matchEl.style.display = v.length > 0 ? 'inline-block' : 'none';
            matchEl.className = 'pw-req';
        }

        // Strength bar
        if (v.length === 0) {
            bar.style.width = '0';
            label.textContent = '';
            return;
        }
        // Bonus for length
        if (v.length >= 14) score += 0.5;
        if (v.length >= 18) score += 0.5;
        const pct = Math.min(100, (score / 6) * 100);
        bar.style.width = pct + '%';
        if (pct < 40) { bar.style.background = '#f44336'; label.textContent = 'Weak'; label.style.color = '#f44336'; }
        else if (pct < 70) { bar.style.background = '#ff9800'; label.textContent = 'Moderate'; label.style.color = '#ff9800'; }
        else if (pct < 100) { bar.style.background = '#8bc34a'; label.textContent = 'Strong'; label.style.color = '#8bc34a'; }
        else { bar.style.background = '#4caf50'; label.textContent = 'Excellent'; label.style.color = '#4caf50'; }
    }

    p1.addEventListener('input', check);
    p2.addEventListener('input', check);
})();
</script>
</body>
</html>
