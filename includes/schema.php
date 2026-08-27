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

const TRACKER_SCHEMA_VERSION = 12;  // …, 8 = system admin group + panel-admin migration + submit mode + worker concurrency, 9 = two-step email change (users.pending_email/email_changed_at) + verification gate + terms + search toggles, 10 = settings only (hCaptcha provider, movable admin sign-in path, timeline range buttons), 11 = UDP traffic monitor + rate limit (net_samples + net_* settings) and panel-driven backups (backup_* settings), 12 = per-client rate limits on the server-to-server API (api_clients.rl_*)

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
            `scope` VARCHAR(32) NOT NULL DEFAULT 'whitelist',
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` DATETIME DEFAULT NULL,
            `last_used_ip` VARCHAR(45) DEFAULT NULL,
            `requests_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `rl_min_start` DATETIME DEFAULT NULL,
            `rl_min_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `rl_day` DATE DEFAULT NULL,
            `rl_day_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `rl_blocked_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
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

        // ── Statistics timeline (schema v5, includes/stats_timeline.php): raw samples + 5-minute / hourly roll-ups.
        //    `ts` = UNIX seconds (sample time / bucket start). Counters (completed, *_announces, connects,
        //    scrapes) are OpenTracker's cumulative values; the API derives per-second rates from them.
        "CREATE TABLE IF NOT EXISTS `stats_samples` (
            `ts` INT UNSIGNED NOT NULL PRIMARY KEY,
            `torrents` INT UNSIGNED NOT NULL DEFAULT 0,
            `peers` INT UNSIGNED NOT NULL DEFAULT 0,
            `seeds` INT UNSIGNED NOT NULL DEFAULT 0,
            `leechers` INT UNSIGNED NOT NULL DEFAULT 0,
            `completed` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `udp_announces` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `tcp_announces` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `connects` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `scrapes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `uptime` INT UNSIGNED NOT NULL DEFAULT 0,
            `mode` ENUM('whitelist','blacklist') NOT NULL DEFAULT 'blacklist',
            `whitelist_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `index_rows` INT UNSIGNED NOT NULL DEFAULT 0
        ) $engine",
        "CREATE TABLE IF NOT EXISTS `stats_samples_5m` (
            `ts` INT UNSIGNED NOT NULL PRIMARY KEY,
            `samples` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `torrents_avg` INT UNSIGNED NOT NULL DEFAULT 0, `torrents_min` INT UNSIGNED NOT NULL DEFAULT 0, `torrents_max` INT UNSIGNED NOT NULL DEFAULT 0,
            `peers_avg` INT UNSIGNED NOT NULL DEFAULT 0, `peers_min` INT UNSIGNED NOT NULL DEFAULT 0, `peers_max` INT UNSIGNED NOT NULL DEFAULT 0,
            `seeds_avg` INT UNSIGNED NOT NULL DEFAULT 0, `seeds_min` INT UNSIGNED NOT NULL DEFAULT 0, `seeds_max` INT UNSIGNED NOT NULL DEFAULT 0,
            `leechers_avg` INT UNSIGNED NOT NULL DEFAULT 0, `leechers_min` INT UNSIGNED NOT NULL DEFAULT 0, `leechers_max` INT UNSIGNED NOT NULL DEFAULT 0,
            `completed` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `udp_announces` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `tcp_announces` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `connects` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `scrapes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `uptime` INT UNSIGNED NOT NULL DEFAULT 0,
            `wl_share` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `whitelist_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `index_rows` INT UNSIGNED NOT NULL DEFAULT 0
        ) $engine",
        "CREATE TABLE IF NOT EXISTS `stats_samples_1h` (
            `ts` INT UNSIGNED NOT NULL PRIMARY KEY,
            `samples` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `torrents_avg` INT UNSIGNED NOT NULL DEFAULT 0, `torrents_min` INT UNSIGNED NOT NULL DEFAULT 0, `torrents_max` INT UNSIGNED NOT NULL DEFAULT 0,
            `peers_avg` INT UNSIGNED NOT NULL DEFAULT 0, `peers_min` INT UNSIGNED NOT NULL DEFAULT 0, `peers_max` INT UNSIGNED NOT NULL DEFAULT 0,
            `seeds_avg` INT UNSIGNED NOT NULL DEFAULT 0, `seeds_min` INT UNSIGNED NOT NULL DEFAULT 0, `seeds_max` INT UNSIGNED NOT NULL DEFAULT 0,
            `leechers_avg` INT UNSIGNED NOT NULL DEFAULT 0, `leechers_min` INT UNSIGNED NOT NULL DEFAULT 0, `leechers_max` INT UNSIGNED NOT NULL DEFAULT 0,
            `completed` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `udp_announces` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `tcp_announces` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `connects` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `scrapes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `uptime` INT UNSIGNED NOT NULL DEFAULT 0,
            `wl_share` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `whitelist_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `index_rows` INT UNSIGNED NOT NULL DEFAULT 0
        ) $engine",

        // ── UDP traffic samples (schema v11, includes/netlimit.php): nftables counters turned into
        //    packets/second, one row per sample. The cumulative columns are kept next to the derived
        //    rates so a gap (or a counter reset after "Apply") can always be told apart from a lull.
        //    Retention is net_keep_days; there is no roll-up — at one minute and 14 days that is
        //    ~20 000 rows, small enough to read raw.
        "CREATE TABLE IF NOT EXISTS `net_samples` (
            `ts` INT UNSIGNED NOT NULL PRIMARY KEY,
            `span` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `in_total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `in_passed` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `in_capped` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `out_ok` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `out_capped` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `pps_total` INT UNSIGNED NOT NULL DEFAULT 0,
            `pps_passed` INT UNSIGNED NOT NULL DEFAULT 0,
            `pps_capped` INT UNSIGNED NOT NULL DEFAULT 0,
            `epps_ok` INT UNSIGNED NOT NULL DEFAULT 0,
            `epps_capped` INT UNSIGNED NOT NULL DEFAULT 0,
            `limit_pps` INT UNSIGNED NOT NULL DEFAULT 0
        ) $engine",

        // ── Observed-hash index (schema v6, includes/index.php): a catalogue of info hashes seen on the
        //    tracker (mostly during OPEN hours via full scrape). NOT a whitelist — it is never served. Meta
        //    columns mirror `whitelist` so the metadata worker drains both queues with one code path.
        "CREATE TABLE IF NOT EXISTS `index_hashes` (
            `info_hash` CHAR(40) NOT NULL PRIMARY KEY,
            `name` VARCHAR(255) DEFAULT NULL,
            `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `seen_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `last_seeders` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_leechers` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_completed` INT UNSIGNED NOT NULL DEFAULT 0,
            `peak_seeders` INT UNSIGNED NOT NULL DEFAULT 0,
            `grace_until` DATETIME DEFAULT NULL,
            `protected_until` DATETIME DEFAULT NULL,
            `promoted_at` DATETIME DEFAULT NULL,
            `meta_status` ENUM('none','pending','fetching','done','failed') NOT NULL DEFAULT 'none',
            `meta_priority` TINYINT NOT NULL DEFAULT -1,
            `meta_requested_at` DATETIME DEFAULT NULL,
            `meta_claimed_at` DATETIME DEFAULT NULL,
            `meta_claim` CHAR(16) DEFAULT NULL,
            `meta_fetched_at` DATETIME DEFAULT NULL,
            `meta_error` VARCHAR(255) DEFAULT NULL,
            `meta_source` VARCHAR(24) DEFAULT NULL,
            `total_size` BIGINT UNSIGNED DEFAULT NULL,
            `files_count` INT UNSIGNED DEFAULT NULL,
            `piece_length` INT UNSIGNED DEFAULT NULL,
            `scrape_seeders` INT UNSIGNED DEFAULT NULL,
            `scrape_leechers` INT UNSIGNED DEFAULT NULL,
            `scrape_completed` INT UNSIGNED DEFAULT NULL,
            `scraped_at` DATETIME DEFAULT NULL,
            KEY `idx_index_meta_fetched` (`meta_fetched_at`),
            KEY `idx_index_last_seen` (`last_seen`),
            KEY `idx_index_grace` (`grace_until`),
            KEY `idx_index_protected` (`protected_until`),
            KEY `idx_index_meta` (`meta_status`, `meta_priority`, `meta_requested_at`),
            KEY `idx_index_seeders` (`last_seeders`),
            KEY `idx_index_promoted` (`promoted_at`),
            FULLTEXT KEY `ft_index_name` (`name`)
        ) $engine",
        "CREATE TABLE IF NOT EXISTS `index_files` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `info_hash` CHAR(40) NOT NULL,
            `path` VARCHAR(1000) NOT NULL,
            `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            KEY `idx_if_hash` (`info_hash`),
            FULLTEXT KEY `ft_if_path` (`path`)
        ) $engine",

        // ── User accounts (schema v7, includes/users.php): registration/login, groups with JSON
        //    permissions, timed memberships, in-app notifications and remember/reset tokens.
        //    All optional — nothing is used unless users_enabled=1.
        "CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(32) NOT NULL,
            `email` VARCHAR(190) DEFAULT NULL,
            `pass_hash` VARCHAR(255) NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'active',
            `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_ip` VARCHAR(45) DEFAULT NULL,
            `last_login_at` DATETIME DEFAULT NULL,
            `last_login_ip` VARCHAR(45) DEFAULT NULL,
            `pending_email` VARCHAR(190) DEFAULT NULL,
            `email_changed_at` DATETIME DEFAULT NULL,
            UNIQUE KEY `uq_users_username` (`username`),
            UNIQUE KEY `uq_users_email` (`email`),
            KEY `idx_users_status` (`status`),
            KEY `idx_users_created` (`created_at`)
        ) $engine",
        "CREATE TABLE IF NOT EXISTS `user_groups` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `slug` VARCHAR(64) NOT NULL,
            `name` VARCHAR(64) NOT NULL,
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `color` VARCHAR(16) NOT NULL DEFAULT '',
            `priority` INT NOT NULL DEFAULT 0,
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `is_system` TINYINT(1) NOT NULL DEFAULT 0,
            `permissions` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_user_groups_slug` (`slug`)
        ) $engine",
        "CREATE TABLE IF NOT EXISTS `user_group_members` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `group_id` INT UNSIGNED NOT NULL,
            `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME DEFAULT NULL,
            `granted_by` VARCHAR(64) NOT NULL DEFAULT '',
            `note` VARCHAR(255) NOT NULL DEFAULT '',
            `warned_at` DATETIME DEFAULT NULL,
            UNIQUE KEY `uq_ugm_user_group` (`user_id`, `group_id`),
            KEY `idx_ugm_group` (`group_id`),
            KEY `idx_ugm_expires` (`expires_at`)
        ) $engine",
        "CREATE TABLE IF NOT EXISTS `user_notifications` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `type` VARCHAR(32) NOT NULL DEFAULT 'info',
            `title` VARCHAR(190) NOT NULL,
            `body` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `read_at` DATETIME DEFAULT NULL,
            KEY `idx_un_user` (`user_id`, `read_at`),
            KEY `idx_un_created` (`created_at`)
        ) $engine",
        "CREATE TABLE IF NOT EXISTS `user_tokens` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `type` VARCHAR(16) NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME DEFAULT NULL,
            UNIQUE KEY `uq_ut_hash` (`token_hash`),
            KEY `idx_ut_user` (`user_id`, `type`),
            KEY `idx_ut_expires` (`expires_at`)
        ) $engine",

        // Baseline groups: `guest` = permissions of ANONYMOUS visitors only (signed-in users get the
        // union of their own groups instead); `member` = default group granted on registration;
        // `admin` = site administrators (its members pass every permission check, see
        // userEffectivePermissions). All editable but cannot be deleted (is_system). INSERT IGNORE
        // keeps admin edits; member is seeded with guest's classic permissions so a fresh registration
        // never sees LESS than an anonymous visitor by default.
        "INSERT IGNORE INTO `user_groups` (`slug`, `name`, `description`, `priority`, `is_default`, `is_system`, `permissions`) VALUES
            ('guest', 'Guest', 'Permissions of anonymous (not signed-in) visitors.', 0, 0, 1,
             '{\"whitelist.view\":true,\"stats.view\":true,\"stats.timeline\":true,\"home.stats\":true}'),
            ('member', 'Member', 'Default group for newly registered users.', 1, 1, 1,
             '{\"whitelist.view\":true,\"stats.view\":true,\"stats.timeline\":true,\"home.stats\":true}'),
            ('admin', 'Admin', 'Site administrators — members pass every permission check.', 1000, 0, 1,
             '{\"index.view\":true,\"index.files\":true,\"index.magnet\":true,\"whitelist.view\":true,\"whitelist.add\":true,\"stats.view\":true,\"stats.timeline\":true,\"home.stats\":true}')",

        // ── Federation peers (schema v7, includes/federation.php): other tracker nodes we exchange index
        //    metadata with. Inbound access = an api_clients row with scope 'federation' (api_client_id);
        //    outbound pull = their bearer stored here, consumed by worker/federation.py.
        "CREATE TABLE IF NOT EXISTS `fed_peers` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(64) NOT NULL,
            `base_url` VARCHAR(255) NOT NULL,
            `bearer` VARCHAR(100) NOT NULL DEFAULT '',
            `api_client_id` INT UNSIGNED DEFAULT NULL,
            `pull_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `pull_files` TINYINT(1) NOT NULL DEFAULT 1,
            `last_pull_at` DATETIME DEFAULT NULL,
            `last_pull_cursor` VARCHAR(64) NOT NULL DEFAULT '',
            `last_status` VARCHAR(255) NOT NULL DEFAULT '',
            `rows_imported` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_fed_peers_name` (`name`)
        ) $engine",
    ];
}

/**
 * Column/index additions for tables that may pre-date v7 (CREATE TABLE IF NOT EXISTS never touches an
 * existing table). Returns only the ALTERs that are actually missing; ensureSchema runs them after the
 * CREATE list. Fresh installs get these columns from the CREATE statements directly.
 */
function trackerSchemaGuardedStatements(PDO $db): array {
    $out = [];
    if (!schemaColumnExists($db, 'api_clients', 'scope')) {
        $out[] = "ALTER TABLE `api_clients` ADD COLUMN `scope` VARCHAR(32) NOT NULL DEFAULT 'whitelist'";
    }
    // v12: the rate-limit counters live on the client row, so the budget is per key rather than per
    // IP — a federation peer pulls from one address and would otherwise share a bucket with anyone
    // behind the same NAT. api_clients is a handful of rows, so this ALTER is instant.
    $aparts = [];
    foreach ([
        'rl_min_start'     => "ADD COLUMN `rl_min_start` DATETIME DEFAULT NULL",
        'rl_min_count'     => "ADD COLUMN `rl_min_count` INT UNSIGNED NOT NULL DEFAULT 0",
        'rl_day'           => "ADD COLUMN `rl_day` DATE DEFAULT NULL",
        'rl_day_bytes'     => "ADD COLUMN `rl_day_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0",
        'rl_blocked_count' => "ADD COLUMN `rl_blocked_count` BIGINT UNSIGNED NOT NULL DEFAULT 0",
    ] as $col => $sql) {
        if (!schemaColumnExists($db, 'api_clients', $col)) $aparts[] = $sql;
    }
    if ($aparts) $out[] = "ALTER TABLE `api_clients` " . implode(', ', $aparts);
    // index_hashes carries a FULLTEXT index, so ADD COLUMN cannot be INSTANT — it is a full table
    // rebuild on a live 200k-row table. Do BOTH changes in ONE ALTER (one rebuild, not two), no
    // AFTER clause (column position doesn't matter, and AFTER also blocks INSTANT on newer MySQL).
    // Tip for big deployments: run `sudo -u www-data php tools/janitor.php` right after upload so
    // the rebuild happens off the web request path.
    $parts = [];
    if (!schemaColumnExists($db, 'index_hashes', 'meta_source')) {
        $parts[] = "ADD COLUMN `meta_source` VARCHAR(24) DEFAULT NULL";
    }
    if (!schemaIndexExists($db, 'index_hashes', 'idx_index_meta_fetched')) {
        $parts[] = "ADD KEY `idx_index_meta_fetched` (`meta_fetched_at`)";
    }
    if ($parts) $out[] = "ALTER TABLE `index_hashes` " . implode(', ', $parts);
    // v9: two-step email change scratch columns (users is tiny — instant ALTER)
    $uparts = [];
    if (!schemaColumnExists($db, 'users', 'pending_email')) $uparts[] = "ADD COLUMN `pending_email` VARCHAR(190) DEFAULT NULL";
    if (!schemaColumnExists($db, 'users', 'email_changed_at')) $uparts[] = "ADD COLUMN `email_changed_at` DATETIME DEFAULT NULL";
    if ($uparts) $out[] = "ALTER TABLE `users` " . implode(', ', $uparts);
    return $out;
}

/**
 * Data migrations that need PHP logic (not plain idempotent SQL). Runs after the DDL, inside the
 * schema lock. v8: make sure the `admin` system group exists (older installs pre-date the seed
 * above) and mirror the PANEL admin into the `users` table so the site owner shows up in the user
 * list with the admin group. The panel password hash is copied once — later panel password changes
 * do NOT sync (the two logins stay independent).
 */
function trackerSchemaDataMigrations(PDO $db, array $cfg): void {
    // admin group (INSERT IGNORE above only fires on fresh CREATE; existing installs need it too)
    $db->exec("INSERT IGNORE INTO `user_groups` (`slug`, `name`, `description`, `priority`, `is_default`, `is_system`, `permissions`) VALUES
        ('admin', 'Admin', 'Site administrators — members pass every permission check.', 1000, 0, 1,
         '{\"index.view\":true,\"index.files\":true,\"index.magnet\":true,\"whitelist.view\":true,\"whitelist.add\":true,\"stats.view\":true,\"stats.timeline\":true,\"home.stats\":true}')");
    // flag legacy guest/member rows as system if an old install lost the flag
    $db->exec("UPDATE `user_groups` SET is_system = 1 WHERE slug IN ('guest','member','admin')");
    // refresh the guest seed description ONLY if the admin never touched it (old wording implied
    // guest was a baseline for signed-in users too, which is no longer true)
    $db->prepare("UPDATE `user_groups` SET description = ? WHERE slug = 'guest' AND description = ?")
       ->execute(['Permissions of anonymous (not signed-in) visitors.', 'Baseline permissions for every visitor (anonymous included).']);
    // panel admin → users row (username from settings, hash from config/app.php, email = the
    // site contact address — verified, it is the owner's own)
    $adminUser = trim((string)($cfg['admin_username'] ?? ''));
    if ($adminUser !== '' && defined('ADMIN_PASSWORD_HASH') && preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $adminUser)) {
        $adminMail = trim((string)($cfg['site_email'] ?? ''));
        if ($adminMail === '' || strlen($adminMail) > 190 || filter_var($adminMail, FILTER_VALIDATE_EMAIL) === false) $adminMail = null;
        $st = $db->prepare("SELECT id FROM users WHERE username = ?");
        $st->execute([$adminUser]);
        $uid = (int)($st->fetchColumn() ?: 0);
        if ($uid === 0) {
            try {
                $db->prepare("INSERT INTO users (username, email, pass_hash, email_verified) VALUES (?, ?, ?, ?)")
                   ->execute([$adminUser, $adminMail, ADMIN_PASSWORD_HASH, $adminMail !== null ? 1 : 0]);
            } catch (PDOException $e) {   // site_email already used by another account
                $db->prepare("INSERT INTO users (username, email, pass_hash) VALUES (?, NULL, ?)")
                   ->execute([$adminUser, ADMIN_PASSWORD_HASH]);
            }
            $uid = (int)$db->lastInsertId();
        }
        $gid = (int)($db->query("SELECT id FROM user_groups WHERE slug = 'admin'")->fetchColumn() ?: 0);
        if ($uid > 0 && $gid > 0) {
            $db->prepare("INSERT IGNORE INTO user_group_members (user_id, group_id, granted_by, note) VALUES (?, ?, 'migration', 'panel admin')")
               ->execute([$uid, $gid]);
        }
    }
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
        // schema v10: hCaptcha as a fourth provider
        'hcaptcha_site_key'           => '',
        'hcaptcha_secret'             => '',
        'api_enabled'                 => '0',
        'api_ban_days'                => '30',
        'api_ban_exempt_ips'          => implode(', ', array_unique($selfIps)),
        // schema v3: scheduled tracker mode (includes/schedule.php)
        'tracker_schedule_enabled'    => '0',
        'tracker_schedule'            => '{"mon":"none","tue":"none","wed":"none","thu":"none","fri":"none","sat":"none","sun":"none"}',
        'tracker_schedule_tz'         => 'Europe/Warsaw',
        'tracker_mode_switch_cmd'     => 'sudo -n /usr/local/sbin/tracker-mode.sh',
        // schema v5: statistics timeline (includes/stats_timeline.php)
        'stats_timeline_enabled'      => '0',
        'stats_timeline_interval'     => '60',
        'stats_timeline_raw_days'     => '7',
        'stats_timeline_keep_days'    => '60',
        'stats_timeline_public'       => '1',
        // schema v10: which range buttons the chart offers, which one opens, free span slider
        'stats_timeline_ranges'       => '24h,7d,14d,30d,90d,all',
        'stats_timeline_default_range' => '24h',
        'stats_timeline_custom_range' => '0',
        // schema v6: observed-hash index (includes/index.php)
        'index_enabled'               => '0',
        'index_source_url'            => 'http://127.0.0.1:6969/scrape',
        'index_poll_minutes'          => '30',
        'index_min_seeders'           => '1',
        'index_max_rows'              => '200000',
        'index_grace_days'            => '3',
        'index_protect_days'          => '10',
        'index_meta_daily_budget'     => '500',
        'index_keep_files'            => '1',
        'index_poll_budget'           => '45',
        // schema v7: index metadata auto-queue + admin near-pages radius
        'index_meta_auto_queue'       => '0',
        'admin_near_pages'            => '2',
        // schema v7: user accounts (includes/users.php) — everything off/neutral by default
        'users_enabled'               => '0',
        'users_registration_enabled'  => '1',
        'users_links_visible'         => '1',
        'users_default_group'         => 'member',
        'users_notify_expiry_days'    => '3',
        'rate_limit_user_login'       => '10',
        'rate_limit_user_register'    => '5',
        'rate_limit_index_search'     => '120',
        // schema v8: whitelist registration audience ('public' = anyone with CAPTCHA,
        // 'users' = signed-in accounts with the whitelist.add permission, no CAPTCHA)
        'whitelist_submit_mode'       => 'public',
        // schema v8: metadata worker parallel fetches (empty = keep the worker's own config file
        // value; 1-16 overrides it live — the worker re-reads this setting every ~60 s)
        'meta_worker_concurrency'     => '',
        // sender address for outgoing mail (empty = use site_email); domain-validated on save
        'mail_from_email'             => '',
        // schema v9: registration requires an email + only verified accounts get their groups
        // (unverified sign-ins act as guests until the link is clicked); terms checkbox content
        // (empty = link to ?action=tos, otherwise shown in a modal); email-change cooldown; member
        // search master switches
        'users_require_email_verify'  => '1',
        'users_terms_text'            => '',
        'users_email_change_cooldown_days' => '30',
        'index_search_enabled'        => '1',
        'index_search_include_whitelist' => '1',
        // schema v7: federation (includes/federation.php + worker/federation.py)
        // Per-client budgets for the server-to-server API. A valid bearer used to be a licence to
        // hammer the database as fast as the network allowed; a federation peer pulling pages is
        // exactly the shape of traffic that needs a ceiling. 0 = no limit.
        'api_rate_limit_per_min'      => '60',
        'api_rate_limit_bytes_day'    => '5368709120',   // 5 GB
        'fed_enabled'                 => '0',
        'fed_node_name'               => '',
        'fed_export_enabled'          => '0',
        'fed_export_files'            => '1',
        'fed_export_max_batch'        => '2000',
        // rows alone never bounded a page: 20 000 torrents can carry millions of file records
        'fed_export_max_bytes'        => '8388608',    // 8 MB on the wire
        'fed_export_max_files'        => '200000',
        'fed_import_new'              => '0',
        'fed_pull_minutes'            => '60',
        // schema v10: the admin sign-in address and what other panel URLs answer when signed out
        // (defaults = the classic behaviour of ?action=admin, with the login form nowhere else)
        'admin_login_path'            => 'admin',
        'admin_hidden_behavior'       => 'home',
        // schema v11: inbound UDP monitor + rate limit (includes/netlimit.php +
        // tools/opentracker/tracker-netlimit.sh). Everything off: a fresh install never calls the
        // helper, never writes an nftables rule and behaves exactly as before.
        'net_monitor_enabled'         => '0',
        'net_sample_seconds'          => '60',
        'net_keep_days'               => '14',
        'net_limit_enabled'           => '0',
        'net_limit_pps'               => '30000',
        'net_limit_burst'             => '100',
        'net_limit_port'              => '6969',
        'net_limit_cmd'               => 'sudo -n /usr/local/sbin/tracker-netlimit.sh',
        'net_auto_enabled'            => '0',
        'net_auto_min'                => '10000',
        'net_auto_max'                => '80000',
        'net_auto_target'             => '30000',
        'net_auto_target_cpu'         => '70',
        // schema v11: panel-driven backups (includes/backup.php + tools/opentracker/tracker-backup.sh).
        // Off by default; the archives live outside the web root and are only ever read through the
        // root helper, so nothing here is reachable without the admin password.
        'backup_enabled'              => '0',
        'backup_dir'                  => '/var/backups/tracker',
        'backup_profile'              => 'tracker-lekki',
        'backup_items'                => '',
        'backup_schedule'             => '',
        'backup_schedule_tz'          => 'Europe/Warsaw',
        'backup_keep'                 => '7',
        'backup_keep_days'            => '30',
        'backup_max_size_gb'          => '20',
        'backup_gpg_recipient'        => '',
        'backup_nice'                 => '15',
        'backup_verify_after'         => '1',
        'backup_cmd'                  => 'sudo -n /usr/local/sbin/tracker-backup.sh',
        'backup_script_path'          => '/usr/local/sbin/Backup-serwera.sh',
        'backup_db_name'              => 'tracker',
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
            foreach (trackerSchemaGuardedStatements($db) as $sql) {
                try { $db->exec($sql); } catch (PDOException $e) { error_log('[tracker schema] ' . $e->getMessage()); throw $e; }
            }
            try { trackerSchemaDataMigrations($db, $cfg); } catch (\Throwable $e) { error_log('[tracker schema] data migration: ' . $e->getMessage()); }
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
