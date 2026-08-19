<?php
/**
 * Idempotent schema bootstrap.
 *
 * The project has no migration runner: production is a plain file copy and the installer only runs
 * once. This module lets a deploy create/upgrade the tables it needs on the first request after the
 * upload. Every statement is idempotent (CREATE TABLE IF NOT EXISTS / guarded ALTER), the whole run is
 * serialised with a MySQL advisory lock, and the resulting version is stored in the `settings` table
 * (`schema_version`) so the fast path on every later request is a single array lookup.
 *
 * Bump TRACKER_SCHEMA_VERSION and append to trackerSchemaStatements() when adding tables/columns.
 */

const TRACKER_SCHEMA_VERSION = 4;   // 3 = scheduled tracker mode settings, 4 = reCAPTCHA v3 settings (no DDL change)

/**
 * All DDL, in order. Shared with install.php (fresh installs run exactly the same statements),
 * so there is one place that defines the schema of the whitelist / API tables.
 */
function trackerSchemaStatements(): array {
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        // ── Whitelist: DB is the source of truth; the accesslist file is generated from it ──
        "CREATE TABLE IF NOT EXISTS `whitelist` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `info_hash` CHAR(40) NOT NULL,
            `name` VARCHAR(255) DEFAULT NULL,
            `magnet_link` TEXT DEFAULT NULL,
            `source` ENUM('web','api','admin','forum') NOT NULL DEFAULT 'web',
            `source_ref` VARCHAR(512) DEFAULT NULL,
            `api_client_id` INT UNSIGNED DEFAULT NULL,
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `ip_bucket` VARCHAR(45) NOT NULL DEFAULT '',
            `banned` TINYINT(1) NOT NULL DEFAULT 0,
            `meta_status` ENUM('none','pending','fetching','done','failed') NOT NULL DEFAULT 'none',
            `meta_priority` TINYINT NOT NULL DEFAULT 0,
            `meta_requested_at` DATETIME DEFAULT NULL,
            `meta_claimed_at` DATETIME DEFAULT NULL,
            `meta_claim` CHAR(16) DEFAULT NULL,
            `meta_fetched_at` DATETIME DEFAULT NULL,
            `meta_error` VARCHAR(255) DEFAULT NULL,
            `total_size` BIGINT UNSIGNED DEFAULT NULL,
            `files_count` INT UNSIGNED DEFAULT NULL,
            `piece_length` INT UNSIGNED DEFAULT NULL,
            `scrape_seeders` INT UNSIGNED DEFAULT NULL,
            `scrape_leechers` INT UNSIGNED DEFAULT NULL,
            `scrape_completed` INT UNSIGNED DEFAULT NULL,
            `scraped_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_whitelist_hash` (`info_hash`),
            KEY `idx_whitelist_ip_bucket` (`ip_bucket`, `created_at`),
            KEY `idx_whitelist_created` (`created_at`),
            KEY `idx_whitelist_source` (`source`, `created_at`),
            KEY `idx_whitelist_meta` (`meta_status`, `meta_priority`, `meta_requested_at`),
            KEY `idx_whitelist_banned` (`banned`),
            FULLTEXT KEY `ft_whitelist_name` (`name`)
        ) $engine",

        // ── Torrent file lists (written by the metadata worker; searchable) ──
        "CREATE TABLE IF NOT EXISTS `whitelist_files` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `whitelist_id` INT UNSIGNED NOT NULL,
            `path` VARCHAR(1000) NOT NULL,
            `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            KEY `idx_wf_whitelist` (`whitelist_id`),
            FULLTEXT KEY `ft_wf_path` (`path`)
        ) $engine",

        // ── Hashes that must never be served (whitelist mode: block = ban) ──
        "CREATE TABLE IF NOT EXISTS `banned_hashes` (
            `info_hash` CHAR(40) NOT NULL PRIMARY KEY,
            `reason` VARCHAR(255) DEFAULT NULL,
            `source` ENUM('report','appeal','admin','import') NOT NULL DEFAULT 'admin',
            `source_id` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_banned_created` (`created_at`)
        ) $engine",

        // ── Server-to-server API clients (bearer key: <key_id>.<secret>; only the hash is stored) ──
        "CREATE TABLE IF NOT EXISTS `api_clients` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `label` VARCHAR(100) NOT NULL,
            `key_id` CHAR(16) NOT NULL,
            `secret_hash` CHAR(64) NOT NULL,
            `secret_hint` CHAR(4) NOT NULL DEFAULT '',
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` DATETIME DEFAULT NULL,
            `last_used_ip` VARCHAR(45) DEFAULT NULL,
            `requests_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY `uq_api_clients_key` (`key_id`)
        ) $engine",

        // ── API bans (30 days by default) with the full offending request for review ──
        "CREATE TABLE IF NOT EXISTS `api_bans` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `ip` VARCHAR(45) NOT NULL,
            `ip_bucket` VARCHAR(45) NOT NULL,
            `reason` VARCHAR(32) NOT NULL,
            `detail` VARCHAR(255) DEFAULT NULL,
            `key_id` VARCHAR(64) DEFAULT NULL,
            `endpoint` VARCHAR(64) DEFAULT NULL,
            `request_snapshot` MEDIUMTEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            `lifted_at` DATETIME DEFAULT NULL,
            `lifted_by` VARCHAR(32) DEFAULT NULL,
            KEY `idx_api_bans_active` (`ip_bucket`, `lifted_at`, `expires_at`),
            KEY `idx_api_bans_created` (`created_at`)
        ) $engine",
    ];
}

/**
 * Default settings introduced by schema version 2. Only inserted when the key is absent, so an
 * admin's later edits are never overwritten. Also seeded by install.php.
 */
function trackerSchemaDefaultSettings(): array {
    $selfIps = ['127.0.0.1', '::1'];
    $srv = $_SERVER['SERVER_ADDR'] ?? '';
    if ($srv !== '' && filter_var($srv, FILTER_VALIDATE_IP)) $selfIps[] = $srv;
    return [
        'tracker_mode'                => 'blacklist',
        'whitelist_path'              => '',
        'whitelist_public_enabled'    => '1',
        'whitelist_max_per_submission'=> '20',
        'rate_limit_whitelist'        => '10',
        'whitelist_ip_daily_max'      => '50',
        'whitelist_daily_cap'         => '2000',
        'whitelist_reload_min_interval' => '45',
        'whitelist_scrape_url'        => 'http://127.0.0.1:6969/scrape',
        'captcha_provider'            => 'recaptcha',
        'turnstile_site_key'          => '',
        'turnstile_secret'            => '',
        // schema v4: Google reCAPTCHA v3 (invisible, score based) as a third provider
        'recaptcha_v3_site_key'       => '',
        'recaptcha_v3_secret'         => '',
        'recaptcha_v3_min_score'      => '0.5',
        'api_enabled'                 => '0',
        'api_ban_days'                => '30',
        'api_ban_exempt_ips'          => implode(', ', array_unique($selfIps)),
        // schema v3: scheduled tracker mode (includes/schedule.php)
        'tracker_schedule_enabled'    => '0',
        'tracker_schedule'            => '{"mon":"none","tue":"none","wed":"none","thu":"none","fri":"none","sat":"none","sun":"none"}',
        'tracker_schedule_tz'         => 'Europe/Warsaw',
        'tracker_mode_switch_cmd'     => 'sudo -n /usr/local/sbin/tracker-mode.sh',
    ];
}

function schemaColumnExists(PDO $db, string $table, string $column): bool {
    $st = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

function schemaIndexExists(PDO $db, string $table, string $index): bool {
    $st = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
    $st->execute([$table, $index]);
    return (bool)$st->fetchColumn();
}

/**
 * Bring the database up to TRACKER_SCHEMA_VERSION. Cheap when current (one array lookup).
 * Never throws — a failure leaves the version untouched so the next request retries, and the
 * error is logged. $cfg is refreshed in place so callers see the seeded defaults immediately.
 */
function ensureSchema(PDO $db, array &$cfg): void {
    if ((int)($cfg['schema_version'] ?? 0) >= TRACKER_SCHEMA_VERSION) return;
    try {
        $lock = $db->query("SELECT GET_LOCK('tracker_schema', 5)")->fetchColumn();
        if ((int)$lock !== 1) return; // someone else is migrating — they will set the version
        try {
            // Re-check under the lock: another worker may have just finished.
            $cur = (int)($db->query("SELECT `value` FROM settings WHERE `key` = 'schema_version'")->fetchColumn() ?: 0);
            if ($cur >= TRACKER_SCHEMA_VERSION) {
                $cfg = getSettings($db, true);
                return;
            }
            foreach (trackerSchemaStatements() as $sql) {
                try { $db->exec($sql); } catch (PDOException $e) { error_log('[tracker schema] ' . $e->getMessage()); throw $e; }
            }
            $ins = $db->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
            foreach (trackerSchemaDefaultSettings() as $k => $v) {
                $ins->execute([$k, $v]);
            }
            $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
               ->execute([(string)TRACKER_SCHEMA_VERSION]);
            $cfg = getSettings($db, true);
        } finally {
            $db->query("SELECT RELEASE_LOCK('tracker_schema')");
        }
    } catch (\Throwable $e) {
        error_log('[tracker schema] upgrade failed: ' . $e->getMessage());
    }
}
