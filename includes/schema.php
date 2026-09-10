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

const TRACKER_SCHEMA_VERSION = 52;  // 52 = message_threads/user_messages/message_reports + user_friends + user_blocks + users.pm_who/profile_listed — people reaching each other
                                    // 47 = user_favourites  // 47 = user_favourites + users.fav_public/fav_listed + whitelist.submitter_id/submitter_public — favourites, public profiles and "my uploads"
                                    // 46 = csp_reports (one row per KIND of violation, hits counts occurrences) + the four csp_* settings — the policy moved out of .htaccess and into PHP, where a nonce can exist
// 45 = settings only (transport: cookie_secure_mode, client_proto_header, hsts_*) — every reader carries its own `?? default`, so the rows only make them visible in Settings
// 44 = settings only (index_files_*: how a file list loads — mode, batch, total, once for the search page and once for the panel)
// 43 = settings only (meta_max_files: the worker's stored-files-per-torrent cap, live from the panel)
// 42 = settings only (dbmem_cmd, dbmem_enabled: the database-memory helper). 41 = page_content.lang — Terms and Info are written per language
// 40 = users.language — the interface language follows the account
// 39 = page_content — Terms and Info editable through the panel's own editor
// 38 = net_limit_blocked — hand-typed addresses that beat an allow list
// 37 = index_polls — what each scrape poll actually delivered, kept as a series
// 36 = ip_lists.addr4 — an "entry" is a network, not an address, and the page has to say which
// 35 = index_hashes.eff_seeders (virtual) + its index — the catalogue's own first page was a 2 890 ms scan
// 34 = address lists for the inbound limit (ip_lists, ip_list_entries)
// 33 = settings only (probe load headroom / hard stop)
// 32 = settings only (db_time_zone, net_limit_trusted)  // 32 = settings only (db_time_zone, net_limit_trusted)
// 31 = two index_hashes indexes (seen_count, last_completed)
// so the fetch order can offer "seen most often" and "most completed" without a filesort,
// plus the three settings that go with them. HEAVY: the ALTER is deferred to the janitor.
// 30 = settings only (metadata fetch order + mix shares)
// 29 = settings only (the stability probe, off by default) —
                                    // and settings-only STILL needs the number to move, because the
                                    // default rows are inserted by the migration block.
                                    // 28 = the audit log: who did what in the panel. It had none,
                                    // which was survivable with one administrator and stopped being
                                    // so the moment the Moderator group existed.
                                    // 27 = index_fetched becomes NULLABLE, so a sample taken before
                                    // the column existed reads as "not measured" instead of as a
                                    // confident zero the chart then drew as a flat line.
                                    // 26 = the timeline also records how many indexed hashes have
                                    // their metadata resolved, so "fetched" can be drawn beside
                                    // "indexed" instead of inferred from the queue depth.
                                    // 25 = panel permissions and a system `moderator` group: until
                                    // now the admin panel had no permissions at all, so the only two
                                    // states were owner and stranger.
                                    // 24 = grant the three permissions v1.19.0 registered but never
                                    // gave to anybody: with the users feature ON, an absent key means
                                    // DENIED, so ratings and descriptions were admin-only in practice.
                                    // 23 = bulk messages carry their markup format, so the HTML part
                                    // of the mail is rendered rather than escaped line by line.
                                    // 22 = a star rating mode beside the up/down one (rep_mode,
                                    // votes_count on both rating tables) and the permissions the
                                    // descriptions, ratings and rewrite proposals should have
                                    // shipped with. 21 = ratings (hash_votes + the totals kept on the row), the
                                    // whitelist refresh/cleanup schedule, description edit
                                    // proposals, and the "prove it" probe state on a submission.
                                    // 20 = settings only, and it needs its own number for the
                                    // reason every "settings only" bump in this list needed one:
                                    // default rows are inserted by the migration block, and that
                                    // block runs when the VERSION moves. Adding keys without moving
                                    // it leaves them absent from the table -- harmless, since every
                                    // reader has a fallback, but the Settings page then shows values
                                    // nothing saved. The keys: the whitelist source link and
                                    // description (with their limits and the trusted-domain list),
                                    // bulk mail, the re-authentication throttle, and live sync.
                                    // 19 = source link + description on whitelist rows (with a review queue), the bulk mail queue, users.bulk_optout, and the two composite indexes the catalogue always needed — its own default first page was a full scan of 2.7 M rows, measured at 1 747 ms. Earlier: // …, 8 = system admin group + panel-admin migration + submit mode + worker concurrency, 9 = two-step email change (users.pending_email/email_changed_at) + verification gate + terms + search toggles, 10 = settings only (hCaptcha provider, movable admin sign-in path, timeline range buttons), 11 = UDP traffic monitor + rate limit (net_samples + net_* settings) and panel-driven backups (backup_* settings), 12 = per-client rate limits on the server-to-server API (api_clients.rl_*), 13 = machine load recorded alongside each traffic sample (net_samples.load_x100) so the panel can say where this box starts struggling, 14 = settings only (OpenTracker performance knobs: ot_*), 15 = federation P1: index_hashes.meta_origin_at (when the metadata was FIRST resolved anywhere, so it stops circulating between three or more nodes) + the fed_review quarantine table, 16 = settings only (curated kernel network buffers: sysctl_*), 17 = settings only (extra opentracker instances: ot_cluster_*), 18 = settings only (admin_2fa_enabled: a MIRROR of config/admin_2fa.json, which is authoritative)

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
            -- v47: who registered it while signed in (NULL = nobody's: anonymous, the API, the
            -- panel, or a row older than this column), and whether they want it on their profile.
            -- `submitter_public` decides whose PROFILE a row appears on and nothing else — never
            -- what the tracker serves and never what the search finds.
            `submitter_id` INT UNSIGNED DEFAULT NULL,
            `submitter_public` TINYINT(1) NOT NULL DEFAULT 0,
            -- v48: THE ONE COLUMN THAT GATES WHAT THE TRACKER SERVES.
            -- 'none' is every row that predates this and every row from a partner allowed to publish
            -- directly, so switching the feature on never retroactively unpublishes anything. Only
            -- 'pending' and 'rejected' are withheld, and the accesslist generator is the only place
            -- that reads it that way — tests/sql_safety_test.php pins that query.
            `review_status` ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
            `review_note` VARCHAR(255) DEFAULT NULL,
            `reviewed_at` DATETIME DEFAULT NULL,
            UNIQUE KEY `uq_whitelist_hash` (`info_hash`),
            KEY `idx_wl_submitter` (`submitter_id`, `created_at`),
            KEY `idx_wl_review` (`review_status`, `created_at`),
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
            -- v48. auto_approve = 1 means this partner publishes straight to the tracker; 0 puts
            -- everything they send into the review queue on the Whitelist page. required_fields is a
            -- comma list of names this partner's items must carry ('name', 'url', 'source_id'), so a
            -- feed that arrives without a title is refused at the door rather than becoming a row
            -- nobody can identify.
            `auto_approve` TINYINT(1) NOT NULL DEFAULT 1,
            `required_fields` VARCHAR(255) NOT NULL DEFAULT '',
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
            `index_rows` INT UNSIGNED NOT NULL DEFAULT 0,
            `index_fetched` INT UNSIGNED DEFAULT NULL
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
            `index_rows` INT UNSIGNED NOT NULL DEFAULT 0,
            `index_fetched` INT UNSIGNED DEFAULT NULL
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
            `index_rows` INT UNSIGNED NOT NULL DEFAULT 0,
            `index_fetched` INT UNSIGNED DEFAULT NULL
        ) $engine",

        // ── UDP traffic samples (schema v11, includes/netlimit.php): nftables counters turned into
        //    packets/second, one row per sample. The cumulative columns are kept next to the derived
        //    rates so a gap (or a counter reset after "Apply") can always be told apart from a lull.
        //    Retention is net_keep_days; there is no roll-up — at one minute and 14 days that is
        //    ~20 000 rows, small enough to read raw.
        // schema v37: one row per scrape poll, so "how much of the tracker did we actually see" can be
        // asked about last week and not only about the last half hour.
        //
        // Everything in here was already being computed — indexPoll() returns all of it — and thrown
        // away: the state file keeps a single `last_poll` key and the next poll overwrites it. At a
        // poll every 30 minutes this is 48 rows a day.
        //
        // The distinction that makes the whole table worth having: `entries` is a FILE POSITION, not
        // a delivery count. A pass that resumes at a cursor counts every entry it walks past,
        // including the ones a previous pass already handled, so `entries` alone reads as though a
        // resumed poll delivered the whole file again. `skip_from` is the cursor this pass started
        // at, and `entries - skip_from` is what it actually contributed.
        "CREATE TABLE IF NOT EXISTS `index_polls` (
            `ts` INT UNSIGNED NOT NULL PRIMARY KEY,
            `entries` INT UNSIGNED NOT NULL DEFAULT 0,
            `skip_from` INT UNSIGNED NOT NULL DEFAULT 0,
            `kept` INT UNSIGNED NOT NULL DEFAULT 0,
            `bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `ms` INT UNSIGNED NOT NULL DEFAULT 0,
            `truncated` TINYINT(1) NOT NULL DEFAULT 0,
            `partial` VARCHAR(64) DEFAULT NULL,
            `removed_wl` INT UNSIGNED NOT NULL DEFAULT 0,
            `removed_ban` INT UNSIGNED NOT NULL DEFAULT 0,
            -- How many torrents the TRACKER said it had at that moment, from the same stats fetch the
            -- swarm timeline uses. NULL when that number was not available: a coverage ratio with an
            -- invented denominator is worse than no ratio, so the chart leaves a gap instead.
            `rows_total` INT UNSIGNED DEFAULT NULL,
            -- What the index held afterwards, so the chart can show the catalogue growing next to
            -- what each poll delivered.
            `index_rows` INT UNSIGNED DEFAULT NULL,
            `error` VARCHAR(190) DEFAULT NULL,
            KEY `idx_ipoll_ts` (`ts`)
        ) $engine",

        // Content-Security-Policy violations (includes/csp.php, csp-report.php).
        //
        // THE PRIMARY KEY IS THE AGGREGATION KEY: sha1(scope|directive|blocked_origin). One row per
        // KIND of violation and a counter, which is the difference between a table that grows with
        // page views and one that grows with the number of real problems — this is written by a
        // PUBLIC unauthenticated endpoint that every browser on the site can reach, into a MariaDB
        // shared with a mail server, a forum and a file host.
        //
        // `blocked` holds the ORIGIN of a blocked URL and never its path or query (a blocked-uri
        // routinely carries a token), and `sample_doc` holds the `?action=` of the page and nothing
        // else from that URL. Everything a backup dumps from here is meant to be dumpable.
        "CREATE TABLE IF NOT EXISTS `csp_reports` (
            `sig`        CHAR(40) NOT NULL PRIMARY KEY,
            `scope`      VARCHAR(8)   NOT NULL DEFAULT 'public',
            `directive`  VARCHAR(48)  NOT NULL DEFAULT '',
            `blocked`    VARCHAR(255) NOT NULL DEFAULT '',
            `sample_doc` VARCHAR(64)  NOT NULL DEFAULT '',
            `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
            `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_seen`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `k_csp_last` (`last_seen`)
        ) $engine",

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
            `limit_pps` INT UNSIGNED NOT NULL DEFAULT 0,
            -- Load average per core when the sample was taken, x100 (1.25 -> 125). NULL when the
            -- platform cannot report it: the study skips those rows rather than inventing a zero,
            -- because a load of nothing and an unknown load are different claims.
            `load_x100` SMALLINT UNSIGNED DEFAULT NULL
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
            -- When this metadata was first resolved ANYWHERE, as opposed to when it arrived here.
            -- With two nodes the difference is cosmetic; with three it is the whole game. A row
            -- imported from B and re-stamped NOW() looks brand new to C, which sends it back to A,
            -- which sends it on again: the same rows circulate for ever and every pass rewrites
            -- them. Carrying the original time means the second cycle recognises the row, changes
            -- nothing, and therefore never re-enters anyone's export window.
            `meta_origin_at` DATETIME DEFAULT NULL,
            `meta_error` VARCHAR(255) DEFAULT NULL,
            `meta_source` VARCHAR(24) DEFAULT NULL,
            `total_size` BIGINT UNSIGNED DEFAULT NULL,
            `files_count` INT UNSIGNED DEFAULT NULL,
            `piece_length` INT UNSIGNED DEFAULT NULL,
            `scrape_seeders` INT UNSIGNED DEFAULT NULL,
            `scrape_leechers` INT UNSIGNED DEFAULT NULL,
            `scrape_completed` INT UNSIGNED DEFAULT NULL,
            `scraped_at` DATETIME DEFAULT NULL,
            -- The seeder count the catalogue actually sorts by: the scrape figure when there is one,
            -- otherwise the last announce. VIRTUAL, so it costs no row space and no write — it exists
            -- purely so the expression can be INDEXED. Sorting by the COALESCE directly cannot use an
            -- index, and the catalogue's own default first page was therefore a full scan and a
            -- filesort of the whole table: measured at 2 890 ms on 1.9 M rows, on a page any member
            -- can open. With the index below the same page is 1 ms.
            `eff_seeders` INT UNSIGNED GENERATED ALWAYS AS (COALESCE(`scrape_seeders`, `last_seeders`)) VIRTUAL,
            KEY `idx_index_eff_seed` (`eff_seeders`, `info_hash`),
            KEY `idx_index_seen` (`seen_count`),
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
            `language` VARCHAR(3) DEFAULT NULL,
            -- v47. Two flags, not one, because they answer two different questions and a reader who
            -- says yes to one has not said yes to the other:
            --   fav_public — may a stranger read MY list of favourites on my profile?
            --   fav_listed — may my name appear on somebody else's list of who favourited a hash?
            -- Both default to 0. A privacy flag that ships on is not a choice anybody made.
            `fav_public` TINYINT(1) NOT NULL DEFAULT 0,
            `fav_listed` TINYINT(1) NOT NULL DEFAULT 0,
            -- v51, and a third question again: may a stranger see that I HAVE lists at all? It is
            -- not covered by fav_public — somebody may be happy to show what they starred and not
            -- what they collected — and each list carries its own is_public underneath it.
            `lists_public` TINYINT(1) NOT NULL DEFAULT 0,
            -- v52. NULL is not a fourth value: it means 'whatever the site says', so an operator who
            -- changes the default changes it for everybody who never expressed a preference, and
            -- nobody who did is overruled.
            `pm_who` ENUM('all','friends','nobody') DEFAULT NULL,
            -- 'my profile is public' and 'put me in the directory' are different sentences: one is
            -- about a page somebody may be sent, the other about being FOUND by strangers browsing.
            `profile_listed` TINYINT(1) NOT NULL DEFAULT 0,
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
             '{\"index.view\":true,\"index.files\":true,\"index.files_all\":true,\"index.magnet\":true,\"whitelist.view\":true,\"whitelist.add\":true,\"stats.view\":true,\"stats.timeline\":true,\"home.stats\":true}')",

        // ── Federation peers (schema v7, includes/federation.php): other tracker nodes we exchange index
        //    metadata with. Inbound access = an api_clients row with scope 'federation' (api_client_id);
        //    outbound pull = their bearer stored here, consumed by worker/federation.py.
        // Quarantine for `fed_import_mode = review`. A peer you do not fully trust must not be able to
        // put names into the public catalogue merely by answering an HTTP request, so in review mode
        // nothing it sends reaches index_hashes at all -- it lands here until an admin accepts it.
        //
        // Deliberately a separate table rather than an extra meta_status value: index_hashes carries a
        // FULLTEXT index and millions of rows, so widening its ENUM means a full rebuild, and every
        // query that lists the catalogue would have to learn the new state or start leaking unreviewed
        // names. A holding pen has neither problem, and dropping it undoes the feature completely.
        //
        // `files_json` is the file list as it arrived, capped (FED_REVIEW_FILES_MAX) -- past the cap the
        // row is still reviewable and still acceptable, just without per-file paths.
        "CREATE TABLE IF NOT EXISTS `fed_review` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `info_hash` CHAR(40) NOT NULL,
            `peer_id` INT UNSIGNED DEFAULT NULL,
            `peer_name` VARCHAR(64) NOT NULL DEFAULT '',
            `name` VARCHAR(512) DEFAULT NULL,
            `total_size` BIGINT UNSIGNED DEFAULT NULL,
            `files_count` INT UNSIGNED DEFAULT NULL,
            `piece_length` INT UNSIGNED DEFAULT NULL,
            `origin_at` DATETIME DEFAULT NULL,
            `files_json` MEDIUMTEXT DEFAULT NULL,
            `files_truncated` TINYINT(1) NOT NULL DEFAULT 0,
            `state` ENUM('pending','rejected') NOT NULL DEFAULT 'pending',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `decided_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_fed_review` (`info_hash`, `peer_name`),
            KEY `idx_fed_review_state` (`state`, `id`),
            KEY `idx_fed_review_peer` (`peer_name`, `state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

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

        // ── Proposed rewrites of somebody else's description ─────────────
        // Anyone can register a hash, including somebody else's, and attach an ugly description to
        // it. The answer is not to lock the fields — the first submitter is not necessarily the
        // right one either — but to let a later submission PROPOSE a replacement that a moderator
        // decides on. Nothing here changes what is public until somebody says so.
        "CREATE TABLE IF NOT EXISTS `wl_content_edits` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `whitelist_id` INT UNSIGNED NOT NULL,
            `info_hash` CHAR(40) NOT NULL,
            `source_url` VARCHAR(500) DEFAULT NULL,
            `description` MEDIUMTEXT DEFAULT NULL,
            `description_format` ENUM('markdown','bbcode') NOT NULL DEFAULT 'bbcode',
            `status` ENUM('pending','applied','rejected') NOT NULL DEFAULT 'pending',
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `user_id` INT UNSIGNED DEFAULT NULL,
            `note` VARCHAR(255) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `reviewed_at` DATETIME DEFAULT NULL,
            KEY `idx_edits_status` (`status`, `created_at`),
            KEY `idx_edits_hash` (`info_hash`)
        ) $engine",

        // ── Ratings: one row per hash per identity ───────────────────────
        // The UNIQUE key is the point. One vote per identity is enforced HERE, where two requests
        // arriving together actually collide — a check-then-insert in PHP is a race with a
        // comfortable window, and a voting button is the most automated thing on any public site.
        // ── Favourites (v47) ─────────────────────────────────────────────
        //
        // A REAL user_id, not the (type, key) pair hash_votes uses. That pair exists because a vote's
        // identity is not uniform — an account OR a bucket of IP addresses. A favourite is only ever
        // an account's: an anonymous one has nowhere to live (a household shares one IP bucket and it
        // rotates), nowhere to be shown (there is no anonymous profile) and no way to honour a
        // privacy choice. The gain is concrete: joining `users` is an eq_ref on the primary key
        // instead of a string-to-int comparison, and the unique key is 44 bytes instead of 105 —
        // which matters because it is copied into both of the other indexes.
        //
        // No FOREIGN KEY: this schema has never had one, and the first would change what
        // includes/backup.php has to know about restore order. userDeleteCascade() is what keeps
        // this table honest when an account goes.
        "CREATE TABLE IF NOT EXISTS `user_favourites` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `info_hash` CHAR(40) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            -- the toggle is idempotent without a read-modify-write, so two taps cannot race
            UNIQUE KEY `uq_fav_once` (`user_id`, `info_hash`),
            -- the profile's own list, newest first, with no filesort
            KEY `idx_fav_user` (`user_id`, `created_at`),
            -- the list of who favourited one hash, and the count that goes with it
            KEY `idx_fav_hash` (`info_hash`, `user_id`)
        ) $engine",

        // ── Lists (v51) ──────────────────────────────────────────────────────────────────────
        //
        // A favourite is one bit about one hash. A LIST is something somebody made: a name, an
        // order they chose, and a decision about who may see it. They are separate tables and not a
        // "list_id" on user_favourites, because starring something and putting it in a collection
        // are different acts — un-starring a torrent must not silently empty somebody's pack.
        //
        // No FOREIGN KEY, for the reason user_favourites gives above; userDeleteCascade() is what
        // keeps both tables honest when an account goes.
        "CREATE TABLE IF NOT EXISTS `user_lists` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(80) NOT NULL,
            -- the address a public list is reachable at, chosen from the name and kept stable when
            -- the name is edited: a link somebody sent must not stop working because of a rename
            `slug` VARCHAR(90) NOT NULL,
            `description` VARCHAR(500) NOT NULL DEFAULT '',
            -- 0, like every other privacy flag in this schema. A list that ships public is not a
            -- choice anybody made.
            `is_public` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_list_slug` (`user_id`, `slug`),
            KEY `idx_list_user` (`user_id`, `updated_at`),
            KEY `idx_list_public` (`is_public`, `updated_at`)
        ) $engine",

        "CREATE TABLE IF NOT EXISTS `user_list_items` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `list_id` INT UNSIGNED NOT NULL,
            `info_hash` CHAR(40) NOT NULL,
            -- the name as it was when the row was added. The catalogue prunes hashes nobody has
            -- announced for a while, and a list that then says nothing but forty hex characters is
            -- a list the person who made it cannot read either.
            `name` VARCHAR(255) DEFAULT NULL,
            `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            -- adding twice is the same list, not a longer one
            UNIQUE KEY `uq_list_item` (`list_id`, `info_hash`),
            KEY `idx_list_added` (`list_id`, `added_at`),
            -- which public lists is this hash on, for the Info panel
            KEY `idx_item_hash` (`info_hash`)
        ) $engine",

        // ── People reaching each other (v52) ─────────────────────────────────────────────────
        //
        // A THREAD is a pair of accounts, not a subject line. Two people have one conversation here,
        // the way they do in a chat window: `u_low`/`u_high` are the two ids sorted, so the unique
        // key finds the same row whoever opens it and there can never be two threads for one pair.
        //
        // Hiding is per side (`u_low_hidden` / `u_high_hidden`) and is not deletion. A conversation
        // that one person cleared out of their inbox still exists for the other, and a message that
        // has been REPORTED must still be readable by the moderator who has to decide about it —
        // letting either side erase evidence of what they sent is a feature nobody asked for.
        "CREATE TABLE IF NOT EXISTS `message_threads` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `u_low` INT UNSIGNED NOT NULL,
            `u_high` INT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_message_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `u_low_hidden` TINYINT(1) NOT NULL DEFAULT 0,
            `u_high_hidden` TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY `uq_thread_pair` (`u_low`, `u_high`),
            KEY `idx_thread_low` (`u_low`, `last_message_at`),
            KEY `idx_thread_high` (`u_high`, `last_message_at`)
        ) $engine",

        // `read_at` belongs to the MESSAGE and not to a per-person table: a thread has exactly two
        // people in it, so "read" can only mean "the other one read it".
        "CREATE TABLE IF NOT EXISTS `user_messages` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `thread_id` INT UNSIGNED NOT NULL,
            `sender_id` INT UNSIGNED NOT NULL,
            `body` TEXT NOT NULL,
            `body_format` ENUM('bbcode','markdown') NOT NULL DEFAULT 'bbcode',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `read_at` DATETIME DEFAULT NULL,
            `reported` TINYINT(1) NOT NULL DEFAULT 0,
            KEY `idx_msg_thread` (`thread_id`, `id`),
            KEY `idx_msg_unread` (`thread_id`, `sender_id`, `read_at`),
            KEY `idx_msg_sender` (`sender_id`, `created_at`)
        ) $engine",

        // A report carries the reported message and the one before it, and NOTHING else. The
        // moderator has to judge one exchange, not read a correspondence — that is a privacy
        // decision and it lives in the schema so no query can quietly widen it later.
        "CREATE TABLE IF NOT EXISTS `message_reports` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `message_id` BIGINT UNSIGNED NOT NULL,
            `context_id` BIGINT UNSIGNED DEFAULT NULL,
            `thread_id` INT UNSIGNED NOT NULL,
            `reporter_id` INT UNSIGNED NOT NULL,
            `reported_user_id` INT UNSIGNED NOT NULL,
            `reason` VARCHAR(500) NOT NULL DEFAULT '',
            `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
            `handled_by` VARCHAR(64) NOT NULL DEFAULT '',
            `handled_at` DATETIME DEFAULT NULL,
            `note` VARCHAR(500) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_report_once` (`message_id`, `reporter_id`),
            KEY `idx_mreport_status` (`status`, `created_at`),
            KEY `idx_mreport_user` (`reported_user_id`)
        ) $engine",

        // FOLLOWING and FRIENDSHIP are the same table read two ways, which is the operator's own
        // decision recorded in code: one row is "A asked B", and until B answers it means A follows
        // B. Accepted, the pair are friends — one row, two directions, so a friendship cannot exist
        // in one place and not in the other.
        "CREATE TABLE IF NOT EXISTS `user_friends` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `friend_id` INT UNSIGNED NOT NULL,
            `status` ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `accepted_at` DATETIME DEFAULT NULL,
            UNIQUE KEY `uq_friend_once` (`user_id`, `friend_id`),
            KEY `idx_friend_of` (`friend_id`, `status`),
            KEY `idx_friend_mine` (`user_id`, `status`)
        ) $engine",

        // A block is one-directional and says WHAT it blocks. `hide_profile` is the second half the
        // operator asked for: some people want to stop the messages, some want to disappear.
        "CREATE TABLE IF NOT EXISTS `user_blocks` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `blocked_id` INT UNSIGNED NOT NULL,
            `hide_profile` TINYINT(1) NOT NULL DEFAULT 0,
            `note` VARCHAR(200) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_block_once` (`user_id`, `blocked_id`),
            KEY `idx_block_target` (`blocked_id`)
        ) $engine",

        "CREATE TABLE IF NOT EXISTS `hash_votes` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `info_hash` CHAR(40) NOT NULL,
            `voter_type` ENUM('ip','user') NOT NULL,
            `voter_key` VARCHAR(64) NOT NULL,
            `vote` TINYINT NOT NULL,
            `weight` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_vote_once` (`info_hash`, `voter_type`, `voter_key`),
            KEY `idx_votes_hash` (`info_hash`),
            KEY `idx_votes_voter` (`voter_type`, `voter_key`, `created_at`)
        ) $engine",

        // ── Outgoing bulk mail, one row per recipient ────────────────────
        // Nothing is sent from a web request. The panel writes rows here and the janitor drains them
        // at a configured rate, because this server sends through PHP's mail() with no relay in front
        // of it: fire fifty at once and the domain's reputation is what pays, which then costs the
        // password-reset messages that actually matter.
        "CREATE TABLE IF NOT EXISTS `audit_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            -- owner = the panel password; admin/moderator = a user holding a panel session;
            -- user = the public site; api = a server-to-server client; system = the janitor.
            `actor_type` ENUM('owner','admin','moderator','user','api','system') NOT NULL DEFAULT 'system',
            `actor_id` INT UNSIGNED DEFAULT NULL,
            `actor_name` VARCHAR(64) NOT NULL DEFAULT '',
            `action` VARCHAR(40) NOT NULL,
            `action_group` VARCHAR(16) NOT NULL DEFAULT 'other',
            `target_type` VARCHAR(24) NOT NULL DEFAULT '',
            `target_id` VARCHAR(80) NOT NULL DEFAULT '',
            `summary` VARCHAR(255) NOT NULL DEFAULT '',
            -- JSON, capped by the writer. Credentials are recorded as \"changed\", never as values.
            `detail` TEXT DEFAULT NULL,
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `ok` TINYINT(1) NOT NULL DEFAULT 1,
            KEY `idx_audit_at` (`at`),
            KEY `idx_audit_group` (`action_group`, `id`),
            KEY `idx_audit_actor` (`actor_name`, `id`)
        ) $engine",

        "CREATE TABLE IF NOT EXISTS `mail_queue` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `batch_id` CHAR(16) NOT NULL,
            `user_id` INT UNSIGNED DEFAULT NULL,
            `email` VARCHAR(190) NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `body` MEDIUMTEXT NOT NULL,
            -- The format travels WITH the row rather than being read from settings at send time: a
            -- batch written in Markdown and sent an hour after somebody switched Markdown off must
            -- still arrive as the sender saw it, not as a page of raw asterisks.
            `format` ENUM('plain','bbcode','markdown') NOT NULL DEFAULT 'plain',
            `status` ENUM('queued','sending','sent','failed','skipped') NOT NULL DEFAULT 'queued',
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `last_error` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `next_attempt_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `sent_at` DATETIME DEFAULT NULL,
            KEY `idx_mq_due` (`status`, `next_attempt_at`),
            KEY `idx_mq_batch` (`batch_id`, `status`)
        ) $engine",

        // schema v34: address lists for the inbound UDP limit — allow, block, block-under-pressure.
        // See includes/iplist.php for what the three kinds mean and the order they are applied in.
        // schema v39: an operator's replacement for a shipped page.
        //
        // Only ever an OVERRIDE. The templates stay in the package and remain the default, so
        // restoring is a DELETE rather than a copy that has to be kept in step with them — and a
        // page nobody edited costs a row that does not exist.
        "CREATE TABLE IF NOT EXISTS `page_content` (
            `page` VARCHAR(32) NOT NULL,
            -- v41: one version per language. A single text served to everybody was fine while the
            -- site had one language; with two it means a Polish visitor reads English terms.
            `lang` VARCHAR(3) NOT NULL DEFAULT 'en',
            `format` ENUM('bbcode','markdown') NOT NULL DEFAULT 'markdown',
            `body` MEDIUMTEXT NOT NULL,
            -- Stored but off is a DRAFT. The router checks this as well as emptiness, so a half
            -- written page cannot replace a live one merely by having been saved.
            `enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by` VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY (`page`, `lang`)
        ) $engine",

        "CREATE TABLE IF NOT EXISTS `ip_lists` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(64) NOT NULL,
            `kind` ENUM('allow','block') NOT NULL DEFAULT 'block',
            -- Only meaningful for a block list. 'hard' drops always; 'soft' gives the addresses a
            -- budget a fifth the size of everyone else's, so they are refused first under load.
            `mode` ENUM('hard','soft') NOT NULL DEFAULT 'hard',
            `source` ENUM('manual','url') NOT NULL DEFAULT 'manual',
            `url` VARCHAR(500) NOT NULL DEFAULT '',
            -- How long a fetched copy stays fresh. A country zone changes a few times a year;
            -- re-downloading it every janitor tick would be rude to the people hosting it.
            `ttl_minutes` INT UNSIGNED NOT NULL DEFAULT 720,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            -- How many IPv4 ADDRESSES the entries cover, and how many of the entries are IPv6.
            --
            -- An entry is a NETWORK, not an address: China's zone file is 8 810 entries and covers
            -- 342 983 424 addresses. Somebody reading 8 810 and wondering whether that is enough to
            -- block a country is asking a reasonable question about the wrong number, so both are
            -- kept. Computed once when the entries are stored — summing 250 000 rows on every poll of
            -- a page that refreshes every few seconds is not a thing to do.
            `addr4` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `nets6` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_fetch_at` DATETIME DEFAULT NULL,
            `last_try_at` DATETIME DEFAULT NULL,
            -- A failed refresh changes no entries: the last good copy stays in force and this says
            -- why, because a 404 must not silently open a door that was closed.
            `last_error` VARCHAR(190) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_ipl_name` (`name`)
        ) $engine",

        // ── The sign-in bridge (v49) ────────────────────────────────────────────────────────────
        //
        // One row per (partner key, their user id). The tracker account is ours and stays ours: the
        // bridge says "the person behind forum user 412 is tracker user 7", and everything else about
        // user 7 — their group, their favourites, their uploads — belongs to the tracker and survives
        // the partner going away.
        //
        // client_id is part of the identity, not a note beside it. Two forums both numbering their
        // users from 1 are two different people, and a UNIQUE on external_id alone would quietly hand
        // the second forum's user 1 the first forum's account.
        "CREATE TABLE IF NOT EXISTS `user_identities` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `client_id` INT UNSIGNED NOT NULL,
            -- What to SHOW the person: 'the forum', 'Flarum', whatever the operator called the key.
            -- Copied from api_clients.label when the link is made rather than joined at read time,
            -- so a profile still says where an account came from after the key is deleted.
            `provider` VARCHAR(64) NOT NULL DEFAULT '',
            `external_id` VARCHAR(191) NOT NULL,
            `external_name` VARCHAR(191) DEFAULT NULL,
            `external_email` VARCHAR(191) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_login_at` DATETIME DEFAULT NULL,
            -- Set when the partner says the person signed out over there. The tracker reads it in
            -- currentUser(): a session opened through the bridge dies when the far side ends it,
            -- which is the half of two-way sign-out that cannot be done by deleting a session file.
            `logout_at` DATETIME DEFAULT NULL,
            UNIQUE KEY `uq_ident_ext` (`client_id`, `external_id`),
            UNIQUE KEY `uq_ident_user` (`user_id`, `client_id`),
            KEY `idx_ident_user` (`user_id`)
        ) $engine",

        // The one-time ticket that carries a sign-in across the redirect between the two sites.
        //
        // Only the HASH is stored, for the same reason as a password: this row is enough to become
        // somebody if the plain token is in it. It is single-use (used_at), short-lived (expires_at),
        // and bound to the key that minted it.
        "CREATE TABLE IF NOT EXISTS `auth_handoffs` (
            `token_hash` CHAR(64) NOT NULL PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `client_id` INT UNSIGNED NOT NULL,
            -- 'in'  — minted by the partner's API call, redeemed by the browser at ?action=bridge
            -- 'out' — minted for a signed-in tracker user, redeemed by the partner at v1/auth/verify
            `direction` ENUM('in','out') NOT NULL DEFAULT 'in',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME DEFAULT NULL,
            `created_ip` VARCHAR(45) DEFAULT NULL,
            KEY `idx_handoff_expiry` (`expires_at`),
            KEY `idx_handoff_user` (`user_id`)
        ) $engine",

        "CREATE TABLE IF NOT EXISTS `ip_list_entries` (
            `list_id` INT UNSIGNED NOT NULL,
            `cidr` VARCHAR(64) NOT NULL,
            `family` TINYINT UNSIGNED NOT NULL DEFAULT 4,
            PRIMARY KEY (`list_id`, `cidr`),
            KEY `idx_ipe_list` (`list_id`, `family`)
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
    // The same clause trackerSchemaStatements() uses. Two CREATE TABLEs live down here as well —
    // a table that has to appear on an EXISTING install is a migration, and a migration that left
    // this off would create it with the server's default charset instead of the site's.
    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    if (!schemaColumnExists($db, 'api_clients', 'scope')) {
        $out[] = "ALTER TABLE `api_clients` ADD COLUMN `scope` VARCHAR(32) NOT NULL DEFAULT 'whitelist'";
    }
    // v13: machine load next to each traffic sample. net_samples is small (one row a minute,
    // 14 days by default) so this ALTER is quick even on a busy box.
    // net_samples is created by the CREATE list above, so by the time the guarded ALTERs run it
    // always exists — schemaColumnExists() answers false for a missing table anyway.
    // The literal name, deliberately: NET_SAMPLE_TABLE lives in includes/netlimit.php, and schema.php
    // is loaded on its own by callers that do not need it. Referencing the constant here made
    // ensureSchema() throw for them — and since ensureSchema is what writes schema_version, the whole
    // migration silently stopped happening. The CREATE statement above uses the literal for the same
    // reason; this must match it.
    if (!schemaColumnExists($db, 'net_samples', 'load_x100')) {
        $out[] = "ALTER TABLE `net_samples` ADD COLUMN `load_x100` SMALLINT UNSIGNED DEFAULT NULL";
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
    if (!schemaColumnExists($db, 'index_hashes', 'meta_origin_at')) {
        $parts[] = "ADD COLUMN `meta_origin_at` DATETIME DEFAULT NULL";
    }
    if (!schemaIndexExists($db, 'index_hashes', 'idx_index_meta_fetched')) {
        $parts[] = "ADD KEY `idx_index_meta_fetched` (`meta_fetched_at`)";
    }
    if ($parts) {
        // ...and it must not happen inside a page view. On a real catalogue that rebuild runs for
        // minutes and holds a SHARED lock (InnoDB does not permit concurrent DML while it rebuilds a
        // FULLTEXT table), so a browser request would occupy one of five php-fpm children while every
        // write on the site queued behind it. The janitor is an ordinary CLI job running every minute,
        // so the web declines and the next tick performs it -- the version simply stays put until
        // then. Fresh installs never reach here: the columns are already in CREATE TABLE.
        if (schemaHeavyAllowed()) {
            // Candidates, cheapest first. INSTANT is metadata only — sub-second on any size, and on
            // this table it is usually available because the columns go on the end. It is offered
            // rather than assumed: the restrictions vary by server version and row format, and the
            // wrong guess would abort the whole upgrade over a column that could have been added
            // the slow way. The bare statement last is the one that always works.
            $alter = "ALTER TABLE `index_hashes` " . implode(', ', $parts);
            $out[] = [$alter . ", ALGORITHM=INSTANT", $alter . ", ALGORITHM=INPLACE", $alter];
        } else {
            schemaDeferHeavy('index_hashes: ' . implode(', ', $parts));
        }
    }
    // v41: page_content gains a language and a composite key. The table holds at most a handful of
    // rows, so this is instant — but the ORDER matters: the column has to exist before the old
    // single-column primary key can be replaced, and MariaDB will not drop a PRIMARY KEY and add
    // one in separate statements without a moment where the table has none. One ALTER does both.
    // No table-exists guard needed: schemaColumnExists() reads information_schema and answers
    // false for a table that is not there — and the CREATE list above has already run.
    if (!schemaColumnExists($db, 'page_content', 'lang')) {
        // Existing rows were written when there was one language; they belong to whatever the site
        // default was then, and 'en' is what that column defaults to for a fresh install.
        $out[] = "ALTER TABLE `page_content` ADD COLUMN `lang` VARCHAR(3) NOT NULL DEFAULT 'en',
                  DROP PRIMARY KEY, ADD PRIMARY KEY (`page`, `lang`)";
    }

    // v9: two-step email change scratch columns (users is tiny — instant ALTER)
    $uparts = [];
    if (!schemaColumnExists($db, 'users', 'pending_email')) $uparts[] = "ADD COLUMN `pending_email` VARCHAR(190) DEFAULT NULL";
    if (!schemaColumnExists($db, 'users', 'email_changed_at')) $uparts[] = "ADD COLUMN `email_changed_at` DATETIME DEFAULT NULL";
    // v19: whoever does not want the newsletter must be able to say so once and be believed.
    if (!schemaColumnExists($db, 'users', 'bulk_optout')) $uparts[] = "ADD COLUMN `bulk_optout` TINYINT(1) NOT NULL DEFAULT 0";
    // v40: the interface language, so it follows the account to another browser rather than living
    // in a cookie that a different machine does not have. NULL means "whatever the site default is",
    // which is not the same as any particular language -- change the site default and these accounts
    // follow it, while an account that picked English stays English.
    if (!schemaColumnExists($db, 'users', 'language')) $uparts[] = "ADD COLUMN `language` VARCHAR(3) DEFAULT NULL";
    // v47: the two favourites privacy flags. In BOTH places on purpose — `bulk_optout` above is only
    // in this list and not in the CREATE, so a fresh install and an upgraded one disagree about the
    // shape of `users`. One such split is a bug waiting; two would be a habit.
    if (!schemaColumnExists($db, 'users', 'fav_public')) $uparts[] = "ADD COLUMN `fav_public` TINYINT(1) NOT NULL DEFAULT 0";
    if (!schemaColumnExists($db, 'users', 'fav_listed')) $uparts[] = "ADD COLUMN `fav_listed` TINYINT(1) NOT NULL DEFAULT 0";
    // v51: the same rule, one flag lower — see the CREATE above.
    if (!schemaColumnExists($db, 'users', 'lists_public')) $uparts[] = "ADD COLUMN `lists_public` TINYINT(1) NOT NULL DEFAULT 0";
    // v52: who may write to me, and may strangers find me by browsing.
    if (!schemaColumnExists($db, 'users', 'pm_who')) $uparts[] = "ADD COLUMN `pm_who` ENUM('all','friends','nobody') DEFAULT NULL";
    if (!schemaColumnExists($db, 'users', 'profile_listed')) $uparts[] = "ADD COLUMN `profile_listed` TINYINT(1) NOT NULL DEFAULT 0";
    if ($uparts) $out[] = "ALTER TABLE `users` " . implode(', ', $uparts);

    // v47: who registered a whitelist row, and whether they want it shown on their profile.
    //
    // `submitter_public` is deliberately NOT called `public` or `visible`: somebody reading the
    // accesslist generator must not be able to mistake it for "served". It decides ONE thing —
    // whose profile a row appears on. The generator, indexSearchCatalogue() and api/index_info.php
    // never read it, and tests/sql_safety_test.php fails if the generator's SQL ever mentions it.
    $wparts = [];
    if (!schemaColumnExists($db, 'whitelist', 'submitter_id')) $wparts[] = "ADD COLUMN `submitter_id` INT UNSIGNED DEFAULT NULL";
    if (!schemaColumnExists($db, 'whitelist', 'submitter_public')) $wparts[] = "ADD COLUMN `submitter_public` TINYINT(1) NOT NULL DEFAULT 0";
    if (!schemaColumnExists($db, 'whitelist', 'review_status')) {
        $wparts[] = "ADD COLUMN `review_status` ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'";
    }
    if (!schemaColumnExists($db, 'whitelist', 'review_note')) $wparts[] = "ADD COLUMN `review_note` VARCHAR(255) DEFAULT NULL";
    if (!schemaColumnExists($db, 'whitelist', 'reviewed_at')) $wparts[] = "ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL";
    if ($wparts) $out[] = "ALTER TABLE `whitelist` " . implode(', ', $wparts);
    if (!schemaIndexExists($db, 'whitelist', 'idx_wl_submitter')) {
        $out[] = "ALTER TABLE `whitelist` ADD KEY `idx_wl_submitter` (`submitter_id`, `created_at`)";
    }
    if (!schemaIndexExists($db, 'whitelist', 'idx_wl_review')) {
        $out[] = "ALTER TABLE `whitelist` ADD KEY `idx_wl_review` (`review_status`, `created_at`)";
    }

    // v48: what a partner's key is allowed to do on its own.
    $aparts = [];
    if (!schemaColumnExists($db, 'api_clients', 'auto_approve')) {
        // 1, not 0: every key that exists today publishes directly, and a migration that silently
        // put every partner's traffic into a review queue nobody is watching would take the tracker
        // off the air for those hashes.
        $aparts[] = "ADD COLUMN `auto_approve` TINYINT(1) NOT NULL DEFAULT 1";
    }
    if (!schemaColumnExists($db, 'api_clients', 'required_fields')) {
        $aparts[] = "ADD COLUMN `required_fields` VARCHAR(255) NOT NULL DEFAULT ''";
    }
    if ($aparts) $out[] = "ALTER TABLE `api_clients` " . implode(', ', $aparts);

    // v52: people reaching each other. Five new tables, so CREATE TABLE IF NOT EXISTS carries the
    // whole migration — the definitions are the ones above, kept identical on purpose.
    $out[] = "CREATE TABLE IF NOT EXISTS `message_threads` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `u_low` INT UNSIGNED NOT NULL,
        `u_high` INT UNSIGNED NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_message_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `u_low_hidden` TINYINT(1) NOT NULL DEFAULT 0,
        `u_high_hidden` TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY `uq_thread_pair` (`u_low`, `u_high`),
        KEY `idx_thread_low` (`u_low`, `last_message_at`),
        KEY `idx_thread_high` (`u_high`, `last_message_at`)
    ) $engine";
    $out[] = "CREATE TABLE IF NOT EXISTS `user_messages` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `thread_id` INT UNSIGNED NOT NULL,
        `sender_id` INT UNSIGNED NOT NULL,
        `body` TEXT NOT NULL,
        `body_format` ENUM('bbcode','markdown') NOT NULL DEFAULT 'bbcode',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `read_at` DATETIME DEFAULT NULL,
        `reported` TINYINT(1) NOT NULL DEFAULT 0,
        KEY `idx_msg_thread` (`thread_id`, `id`),
        KEY `idx_msg_unread` (`thread_id`, `sender_id`, `read_at`),
        KEY `idx_msg_sender` (`sender_id`, `created_at`)
    ) $engine";
    $out[] = "CREATE TABLE IF NOT EXISTS `message_reports` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `message_id` BIGINT UNSIGNED NOT NULL,
        `context_id` BIGINT UNSIGNED DEFAULT NULL,
        `thread_id` INT UNSIGNED NOT NULL,
        `reporter_id` INT UNSIGNED NOT NULL,
        `reported_user_id` INT UNSIGNED NOT NULL,
        `reason` VARCHAR(500) NOT NULL DEFAULT '',
        `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
        `handled_by` VARCHAR(64) NOT NULL DEFAULT '',
        `handled_at` DATETIME DEFAULT NULL,
        `note` VARCHAR(500) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_report_once` (`message_id`, `reporter_id`),
        KEY `idx_mreport_status` (`status`, `created_at`),
        KEY `idx_mreport_user` (`reported_user_id`)
    ) $engine";
    $out[] = "CREATE TABLE IF NOT EXISTS `user_friends` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `friend_id` INT UNSIGNED NOT NULL,
        `status` ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `accepted_at` DATETIME DEFAULT NULL,
        UNIQUE KEY `uq_friend_once` (`user_id`, `friend_id`),
        KEY `idx_friend_of` (`friend_id`, `status`),
        KEY `idx_friend_mine` (`user_id`, `status`)
    ) $engine";
    $out[] = "CREATE TABLE IF NOT EXISTS `user_blocks` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `blocked_id` INT UNSIGNED NOT NULL,
        `hide_profile` TINYINT(1) NOT NULL DEFAULT 0,
        `note` VARCHAR(200) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_block_once` (`user_id`, `blocked_id`),
        KEY `idx_block_target` (`blocked_id`)
    ) $engine";

    // v51: lists. Two new tables, so the definitions above carry the migration too — kept identical
    // on purpose, the way the v49 tables are.
    $out[] = "CREATE TABLE IF NOT EXISTS `user_lists` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `name` VARCHAR(80) NOT NULL,
        `slug` VARCHAR(90) NOT NULL,
        `description` VARCHAR(500) NOT NULL DEFAULT '',
        `is_public` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_list_slug` (`user_id`, `slug`),
        KEY `idx_list_user` (`user_id`, `updated_at`),
        KEY `idx_list_public` (`is_public`, `updated_at`)
    ) $engine";
    $out[] = "CREATE TABLE IF NOT EXISTS `user_list_items` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `list_id` INT UNSIGNED NOT NULL,
        `info_hash` CHAR(40) NOT NULL,
        `name` VARCHAR(255) DEFAULT NULL,
        `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_list_item` (`list_id`, `info_hash`),
        KEY `idx_list_added` (`list_id`, `added_at`),
        KEY `idx_item_hash` (`info_hash`)
    ) $engine";

    // v50: a report can now arrive from a partner's key instead of from the public form, and the
    // queue has to say which. The column is NULLABLE and has no default: every report that exists
    // today came from a person on the Report page, and writing a partner id into those rows — or
    // inventing one — would be the migration making a claim about where they came from.
    //
    // `archives` gets the same column because archiveReport() copies a row across verbatim when a
    // report is resolved; without it the partner's name would be lost at exactly the moment the
    // record becomes the permanent one.
    foreach (['reports', 'archives'] as $rt) {
        if (!schemaTableExists($db, $rt)) continue;   // install.php owns these two; see the helper
        if (!schemaColumnExists($db, $rt, 'api_client_id')) {
            $out[] = "ALTER TABLE `$rt` ADD COLUMN `api_client_id` INT UNSIGNED DEFAULT NULL";
        }
        if (!schemaIndexExists($db, $rt, 'idx_' . $rt . '_api_client')) {
            $out[] = "ALTER TABLE `$rt` ADD INDEX `idx_" . $rt . "_api_client` (`api_client_id`)";
        }
    }
    // DEFAULT 0, and that is the decision this release is about: `auto_approve` above defaults to 1
    // because registering a hash a partner already published is not an escalation. Blocking one is.
    // A key that can file abuse reports gets them REVIEWED unless somebody deliberately says
    // otherwise, so the two settings are separate columns rather than one column read two ways.
    if (!schemaColumnExists($db, 'api_clients', 'abuse_auto_block')) {
        $out[] = "ALTER TABLE `api_clients` ADD COLUMN `abuse_auto_block` TINYINT(1) NOT NULL DEFAULT 0";
    }

    // v49: the sign-in bridge. Two new tables, so CREATE TABLE IF NOT EXISTS carries the whole
    // migration — the definitions are the ones above, kept identical on purpose.
    $out[] = "CREATE TABLE IF NOT EXISTS `user_identities` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `client_id` INT UNSIGNED NOT NULL,
        `provider` VARCHAR(64) NOT NULL DEFAULT '',
        `external_id` VARCHAR(191) NOT NULL,
        `external_name` VARCHAR(191) DEFAULT NULL,
        `external_email` VARCHAR(191) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_login_at` DATETIME DEFAULT NULL,
        `logout_at` DATETIME DEFAULT NULL,
        UNIQUE KEY `uq_ident_ext` (`client_id`, `external_id`),
        UNIQUE KEY `uq_ident_user` (`user_id`, `client_id`),
        KEY `idx_ident_user` (`user_id`)
    ) $engine";
    $out[] = "CREATE TABLE IF NOT EXISTS `auth_handoffs` (
        `token_hash` CHAR(64) NOT NULL PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `client_id` INT UNSIGNED NOT NULL,
        `direction` ENUM('in','out') NOT NULL DEFAULT 'in',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `expires_at` DATETIME NOT NULL,
        `used_at` DATETIME DEFAULT NULL,
        `created_ip` VARCHAR(45) DEFAULT NULL,
        KEY `idx_handoff_expiry` (`expires_at`),
        KEY `idx_handoff_user` (`user_id`)
    ) $engine";

    // v19: a source link and a description on a whitelist row. The table is small (hundreds of rows),
    // so this is an ordinary ALTER — nothing here needs the deferral machinery below.
    $wparts = [];
    if (!schemaColumnExists($db, 'whitelist', 'source_url')) {
        $wparts[] = "ADD COLUMN `source_url` VARCHAR(500) DEFAULT NULL";
    }
    if (!schemaColumnExists($db, 'whitelist', 'description')) {
        $wparts[] = "ADD COLUMN `description` MEDIUMTEXT DEFAULT NULL";
    }
    if (!schemaColumnExists($db, 'whitelist', 'description_format')) {
        $wparts[] = "ADD COLUMN `description_format` ENUM('markdown','bbcode') NOT NULL DEFAULT 'bbcode'";
    }
    if (!schemaColumnExists($db, 'whitelist', 'content_status')) {
        $wparts[] = "ADD COLUMN `content_status` ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'";
    }
    if (!schemaColumnExists($db, 'whitelist', 'content_reviewed_at')) {
        $wparts[] = "ADD COLUMN `content_reviewed_at` DATETIME DEFAULT NULL";
    }
    if (!schemaColumnExists($db, 'whitelist', 'content_rejected_note')) {
        $wparts[] = "ADD COLUMN `content_rejected_note` VARCHAR(255) DEFAULT NULL";
    }
    if (!schemaColumnExists($db, 'whitelist', 'probe_status')) {
        // 'none' for everything that already exists: rows registered before this feature were never
        // asked to prove anything, and retroactively marking them unproven would empty the tracker.
        $wparts[] = "ADD COLUMN `probe_status` ENUM('none','probing','passed','failed') NOT NULL DEFAULT 'none'";
    }
    if (!schemaColumnExists($db, 'whitelist', 'probe_started_at')) {
        $wparts[] = "ADD COLUMN `probe_started_at` DATETIME DEFAULT NULL";
    }
    if (!schemaColumnExists($db, 'whitelist', 'probe_error')) {
        $wparts[] = "ADD COLUMN `probe_error` VARCHAR(255) DEFAULT NULL";
    }
    if (!schemaIndexExists($db, 'whitelist', 'idx_whitelist_probe')) {
        $wparts[] = "ADD KEY `idx_whitelist_probe` (`probe_status`, `probe_started_at`)";
    }
    if (!schemaColumnExists($db, 'whitelist', 'dead_since')) {
        // When the maintenance pass first found no seeders and no leechers on this row. NULL means
        // "not dead as far as anybody knows", which is also what a row that has never been scraped
        // says — no data is not the same as no peers.
        $wparts[] = "ADD COLUMN `dead_since` DATETIME DEFAULT NULL";
    }
    if (!schemaIndexExists($db, 'whitelist', 'idx_whitelist_dead')) {
        $wparts[] = "ADD KEY `idx_whitelist_dead` (`dead_since`)";
    }
    if (!schemaIndexExists($db, 'whitelist', 'idx_whitelist_content')) {
        $wparts[] = "ADD KEY `idx_whitelist_content` (`content_status`, `created_at`)";
    }
    if ($wparts) $out[] = "ALTER TABLE `whitelist` " . implode(', ', $wparts);

    // v21: the rating totals, kept on the row. A listing that showed a score for fifty rows would
    // otherwise be fifty aggregate queries, and this project has already been bitten once by a
    // listing doing per-row work over a large table.
    foreach (['index_hashes', 'whitelist'] as $rt) {
        $rparts = [];
        if (!schemaColumnExists($db, $rt, 'votes_up'))   $rparts[] = "ADD COLUMN `votes_up` INT UNSIGNED NOT NULL DEFAULT 0";
        if (!schemaColumnExists($db, $rt, 'votes_down')) $rparts[] = "ADD COLUMN `votes_down` INT UNSIGNED NOT NULL DEFAULT 0";
        if (!schemaColumnExists($db, $rt, 'score_x100')) $rparts[] = "ADD COLUMN `score_x100` SMALLINT UNSIGNED NOT NULL DEFAULT 0";
        // v22: how many votes, as its own column rather than votes_up + votes_down.
        //
        // With stars there is no "up" and no "down" — there is a count and an average — and reusing
        // votes_up to mean "count in one mode and up-votes in the other" is exactly the overload that
        // produces a wrong number two releases later, in whichever branch nobody re-read.
        if (!schemaColumnExists($db, $rt, 'votes_count')) $rparts[] = "ADD COLUMN `votes_count` INT UNSIGNED NOT NULL DEFAULT 0";
        if (!$rparts) continue;
        if ($rt === 'whitelist') {
            $out[] = "ALTER TABLE `whitelist` " . implode(', ', $rparts);
        } elseif (schemaHeavyAllowed()) {
            // index_hashes carries a FULLTEXT index, so this is a rebuild — the janitor, never a page.
            $alter = "ALTER TABLE `index_hashes` " . implode(', ', $rparts);
            $out[] = [$alter . ", ALGORITHM=INSTANT", $alter . ", ALGORITHM=INPLACE", $alter];
        } else {
            schemaDeferHeavy('index_hashes: ' . implode(', ', $rparts));
        }
    }

    // v26: how many indexed hashes have their metadata resolved. Three small-to-medium tables; the
    // raw one is the only sizeable one and an INT column is an instant add on it.
    //
    // v27 makes it NULLABLE, and that is the whole point of the change: every row that existed before
    // the column did was given 0, and 0 is a claim — "at that moment nothing had been fetched" — which
    // was not true, nothing had been MEASURED. The chart drew that as a flat line at zero across all
    // of history, which is exactly the shape of a broken feature. NULL says "no reading", the payload
    // passes it through, and the line simply does not start until the data does.
    foreach (['stats_samples', 'stats_samples_5m', 'stats_samples_1h'] as $stTable) {
        if (!schemaColumnExists($db, $stTable, 'index_fetched')) {
            $out[] = "ALTER TABLE `$stTable` ADD COLUMN `index_fetched` INT UNSIGNED DEFAULT NULL";
        } elseif (!schemaColumnNullable($db, $stTable, 'index_fetched')) {
            $out[] = "ALTER TABLE `$stTable` MODIFY COLUMN `index_fetched` INT UNSIGNED DEFAULT NULL";
            // the zeros already written were never a measurement; say so
            $out[] = "UPDATE `$stTable` SET `index_fetched` = NULL WHERE `index_fetched` = 0";
        }
    }

    // v23: the markup format of a queued bulk message. Small table, ordinary ALTER.
    if (!schemaColumnExists($db, 'mail_queue', 'format')) {
        $out[] = "ALTER TABLE `mail_queue` ADD COLUMN `format` ENUM('plain','bbcode','markdown') NOT NULL DEFAULT 'plain'";
    }

    // v19: the two composite indexes the catalogue has always needed.
    //
    // Measured on the live table (2 746 616 rows, 2 GB): the Index page's own default first page ran
    // `ORDER BY last_seeders DESC, last_seen DESC LIMIT 50` as `type=ALL … Using filesort` — a full
    // scan of every row, 1 747 ms, on every single load. There WAS an index on `last_seeders` alone,
    // and a single-column index cannot satisfy a two-column sort, so the optimiser discarded it.
    //
    // The public search is the same shape with `WHERE meta_status='done'` in front.
    //
    // This is worth more than any cache in front of it: a cache would have hidden a 1.7-second scan
    // that also evicts a 512 MB InnoDB buffer pool with 2 GB of table on every miss — and that pool
    // is shared with the mail, the forum and the file service on this machine. The fix belongs here.
    $iparts = [];
    if (!schemaIndexExists($db, 'index_hashes', 'idx_index_seed_seen')) {
        $iparts[] = "ADD KEY `idx_index_seed_seen` (`last_seeders`, `last_seen`)";
    }
    if (!schemaIndexExists($db, 'index_hashes', 'idx_index_meta_seed')) {
        $iparts[] = "ADD KEY `idx_index_meta_seed` (`meta_status`, `last_seeders`, `last_seen`)";
    }
    // schema v31: two more, for the fetch-order selectors "seen most often" and "most completed".
    //
    // These exist so that choosing an order in the panel stays a query plan rather than a wish. A
    // claim runs on every fetch slot — several times a second on a queue of three million rows — so
    // `ORDER BY seen_count DESC` without an index is a filesort of the whole table at that rate,
    // which is not a slow setting but a stopped machine.
    //
    // The cost is honest and worth stating: two more secondary indexes on a table the index poll
    // rewrites wholesale every 30 minutes. That is the reason the list of selectors stops here
    // rather than offering every column — see metaOrderRejected() for the ones left out.
    if (!schemaIndexExists($db, 'index_hashes', 'idx_index_meta_seen')) {
        $iparts[] = "ADD KEY `idx_index_meta_seen` (`meta_status`, `seen_count`)";
    }
    if (!schemaIndexExists($db, 'index_hashes', 'idx_index_meta_completed')) {
        $iparts[] = "ADD KEY `idx_index_meta_completed` (`meta_status`, `last_completed`)";
    }
    // schema v36: what a list covers, not merely how many lines it has.
    foreach (['addr4' => "ADD COLUMN `addr4` BIGINT UNSIGNED NOT NULL DEFAULT 0",
              'nets6' => "ADD COLUMN `nets6` INT UNSIGNED NOT NULL DEFAULT 0"] as $col => $sql) {
        if (!schemaColumnExists($db, 'ip_lists', $col)) {
            // ip_lists is tens of rows; this is instant and needs no janitor.
            $out[] = "ALTER TABLE `ip_lists` " . $sql;
        }
    }

    // schema v35: the column the catalogue sorts by, so that it can be indexed.
    //
    // The public catalogue orders by COALESCE(scrape_seeders, last_seeders) — an expression, which no
    // index can serve. Its own default first page, with no search typed at all, was therefore a full
    // scan plus a filesort of the whole table: 2 890 ms measured on 1.9 M rows, on a page any member
    // can open and reload. Not a cache problem; a plan problem.
    //
    // A VIRTUAL column is stored nowhere and written never — it is computed when read — so adding it
    // is instant and costs no row space. What it buys is the right to put an INDEX on the expression,
    // which turns the sort into an index walk that stops after 25 rows: 1 ms for the same page, and
    // 11 ms at page 40. The index build itself took 8.8 s online on production.
    if (!schemaColumnExists($db, 'index_hashes', 'eff_seeders')) {
        // Adding a VIRTUAL column touches no rows, so this one is safe outside the janitor even on a
        // table this size — unlike the stored columns above, which mean a rebuild.
        $out[] = "ALTER TABLE `index_hashes` ADD COLUMN `eff_seeders` INT UNSIGNED "
               . "GENERATED ALWAYS AS (COALESCE(`scrape_seeders`, `last_seeders`)) VIRTUAL";
    }
    if (!schemaIndexExists($db, 'index_hashes', 'idx_index_eff_seed')) {
        $iparts[] = "ADD KEY `idx_index_eff_seed` (`eff_seeders`, `info_hash`)";
    }
    // schema v35: "times seen" on the admin index listing.
    //
    // ONE of the nine unindexed sorts, not all of them, and the choice is deliberate. Measured on the
    // 1.9 M-row production table, every sort without an index costs about the same:
    //
    //     seen_count      2 311 ms        name             2 186 ms
    //     peak_seeders    1 986 ms        scrape_seeders   1 966 ms
    //     files_count     1 951 ms        total_size       1 930 ms
    //     first_seen      1 870 ms        last_leechers    1 876 ms
    //     meta_status     1 846 ms        (last_seen and last_seeders are already 1 ms)
    //
    // Indexing all of them would fix all of them — and this table already carries 2 738 MiB of index
    // against 584 MiB of data, and the index poll rewrites hundreds of thousands of rows every thirty
    // minutes, paying for every one of those indexes on every row. So the line is drawn at the column
    // the fetch-order work made worth looking at: "times seen" is how an operator decides what the
    // swarm actually cares about. The rest stay a filesort on a deliberate click, and that is a
    // choice recorded here rather than an oversight.
    if (!schemaIndexExists($db, 'index_hashes', 'idx_index_seen')) {
        $iparts[] = "ADD KEY `idx_index_seen` (`seen_count`)";
    }
    if ($iparts) {
        // Same reasoning as the ALTER above: FULLTEXT on this table means a rebuild, minutes long,
        // holding a shared lock — never inside a page view. The janitor picks it up on its next tick.
        if (schemaHeavyAllowed()) {
            $alter = "ALTER TABLE `index_hashes` " . implode(', ', $iparts);
            $out[] = [$alter . ", ALGORITHM=INPLACE, LOCK=NONE", $alter . ", ALGORITHM=INPLACE", $alter];
        } else {
            schemaDeferHeavy('index_hashes: ' . implode(', ', $iparts));
        }
    }
    return $out;
}

/**
 * Data migrations that need PHP logic (not plain idempotent SQL). Runs after the DDL, inside the
 * schema lock. v8: make sure the `admin` system group exists (older installs pre-date the seed
 * above) and mirror the PANEL admin into the `users` table so the site owner shows up in the user
 * list with the admin group. The panel password hash is copied once — later panel password changes
 * do NOT sync (the two logins stay independent).
 */
/**
 * Grant permissions to a seeded group ONCE, without disturbing what the operator has decided since.
 *
 * The problem this solves: a newly registered permission defaults to DENIED for every existing group,
 * because userEffectivePermissions() reads each group's stored JSON and an absent key is absent. So
 * shipping a permission for a feature that previously had no permission check SILENTLY TAKES THE
 * FEATURE AWAY from everyone but admins — which is exactly what v1.19.0 did to ratings, descriptions
 * and rewrite proposals. The fix is not to make absent keys default to allowed (that would make the
 * whole permission system advisory); it is to grant, deliberately, at the moment the permission is
 * introduced.
 *
 * ONCE is the important word. trackerSchemaDataMigrations() runs on every version bump, so a plain
 * grant would resurrect a permission an operator had removed on purpose, every time anything else in
 * the schema changed. The marker row makes it fire exactly once per install; INSERT IGNORE returning
 * a row is the atomic test-and-set, so two workers migrating together cannot both do it.
 *
 * Returns the number of groups actually changed.
 */
function schemaGrantOnce(PDO $db, string $marker, array $bySlug): int {
    $ins = $db->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
    $ins->execute(['schema_grant_' . $marker, (string)time()]);
    if ($ins->rowCount() !== 1) return 0;   // somebody already did it on this install

    $changed = 0;
    foreach ($bySlug as $slug => $perms) {
        $st = $db->prepare("SELECT id, permissions FROM user_groups WHERE slug = ?");
        $st->execute([$slug]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g) continue;
        $cur = json_decode((string)$g['permissions'], true);
        if (!is_array($cur)) $cur = [];
        $before = count($cur);
        foreach ($perms as $perm) if (!array_key_exists($perm, $cur)) $cur[$perm] = true;
        if (count($cur) === $before) continue;
        $db->prepare("UPDATE user_groups SET permissions = ? WHERE id = ?")
           ->execute([json_encode($cur, JSON_UNESCAPED_SLASHES), (int)$g['id']]);
        $changed++;
    }
    return $changed;
}

function trackerSchemaDataMigrations(PDO $db, array $cfg): void {
    // admin group (INSERT IGNORE above only fires on fresh CREATE; existing installs need it too)
    $db->exec("INSERT IGNORE INTO `user_groups` (`slug`, `name`, `description`, `priority`, `is_default`, `is_system`, `permissions`) VALUES
        ('admin', 'Admin', 'Site administrators — members pass every permission check.', 1000, 0, 1,
         '{\"index.view\":true,\"index.files\":true,\"index.files_all\":true,\"index.magnet\":true,\"whitelist.view\":true,\"whitelist.add\":true,\"stats.view\":true,\"stats.timeline\":true,\"home.stats\":true}')");
    // flag legacy guest/member rows as system if an old install lost the flag
    $db->exec("UPDATE `user_groups` SET is_system = 1 WHERE slug IN ('guest','member','admin')");
    // refresh the guest seed description ONLY if the admin never touched it (old wording implied
    // guest was a baseline for signed-in users too, which is no longer true)
    $db->prepare("UPDATE `user_groups` SET description = ? WHERE slug = 'guest' AND description = ?")
       ->execute(['Permissions of anonymous (not signed-in) visitors.', 'Baseline permissions for every visitor (anonymous included).']);
    // v25: a system `moderator` group.
    //
    // The starting set is the LIMITED reading of the word: everything needed to work a queue —
    // reports, appeals, the whitelist, submitted descriptions — plus seeing the user list and being
    // able to message somebody. It deliberately does NOT include deleting whitelist rows, editing
    // users, changing group membership, or the Traffic and Backups pages. A "full" moderator is the
    // same group with more boxes ticked in Users → Groups; the panel has always rendered its
    // checkbox list straight from userPermissionList(), so every panel permission appears there with
    // no further work. That is the answer to "a mod can be limited or full": one group, and the
    // operator decides how far it reaches.
    //
    // is_default is 0. Nobody becomes a moderator by signing up.
    $db->exec("INSERT IGNORE INTO `user_groups` (`slug`, `name`, `description`, `color`, `priority`, `is_default`, `is_system`, `permissions`) VALUES
        ('moderator', 'Moderator', 'Works the queues: reports, appeals, the whitelist and submitted descriptions. Settings, backups, the machine controls and anything needing the owner password stay out of reach.', '#4a9eff', 500, 0, 1,
         '{\"panel.access\":true,\"panel.reports.view\":true,\"panel.reports.status\":true,\"panel.reports.block\":true,\"panel.reports.email\":true,\"panel.reports.archive\":true,\"panel.appeals.resolve\":true,\"panel.whitelist.view\":true,\"panel.whitelist.add\":true,\"panel.whitelist.ban\":true,\"panel.whitelist.meta\":true,\"panel.whitelist.content\":true,\"panel.users.view\":true,\"panel.users.notify\":true,\"index.view\":true,\"index.files\":true,\"index.files_all\":true,\"index.magnet\":true,\"whitelist.view\":true,\"whitelist.add\":true,\"stats.view\":true,\"stats.timeline\":true,\"home.stats\":true,\"rating.vote\":true,\"content.submit\":true,\"content.propose\":true,\"favourites.use\":true,\"favourites.public\":true,\"favourites.view_others\":true,\"uploads.public\":true}')");
    $db->exec("UPDATE `user_groups` SET is_system = 1 WHERE slug = 'moderator'");

    // v24: the permissions v1.19.0 registered and never granted.
    //
    // Before they existed, whether somebody could rate or attach a description was decided entirely by
    // the feature settings (rep_who_can_vote, whitelist_submit_mode, wl_allow_description). Adding a
    // permission put a second gate in front of those, and every existing group failed it. Granting the
    // three here restores exactly the previous behaviour: the feature settings remain the policy, and
    // the permissions become the narrowing tool an operator can now reach for — rather than a silent
    // change of policy nobody asked for.
    //
    // Guest gets them too, for the same reason: an anonymous visitor's ability to rate was governed by
    // rep_who_can_vote (which defaults to signed-in accounts anyway), not by a permission. Withholding
    // the grant here would not be caution, it would be a behaviour change disguised as one.
    // index.files_all (v42): the first page of a file list came with index.files, and until now so did
    // every page after it. The grant keeps that for the seeded member group; a group the operator
    // made by hand with index.files gets the new id only if the operator hands it out, which is the
    // point of having it.
    schemaGrantOnce($db, 'v42_index_files_all', [
        'member' => ['index.files_all'],
    ]);

    // v47: the favourites and uploads permissions.
    //
    // GUEST GETS NOTHING. That is the operator's decision recorded in code: profiles and favourite
    // lists are for signed-in readers, so an anonymous visitor sees exactly what they see when the
    // account system is switched off. It is also why userLegacyDefault() answers false for both
    // prefixes — unlike rating.* and content.*, which work without accounts and would be switched
    // OFF by a false there.
    schemaGrantOnce($db, 'v47_favourites', [
        'member' => ['favourites.use', 'favourites.public', 'favourites.view_others', 'uploads.public'],
    ]);

    // v51: the list permissions, to the same group and for the same reason as v47 — a member who
    // may keep favourites may keep a collection. GUEST GETS NOTHING, again: a list belongs to an
    // account.
    schemaGrantOnce($db, 'v51_lists', [
        'member' => ['lists.use', 'lists.public'],
    ]);

    // v52: the account-to-account permissions, to the same group and for the same reason.
    // GUEST GETS NOTHING: writing to somebody needs an account by definition.
    schemaGrantOnce($db, 'v52_people', [
        'member' => ['pm.send', 'pm.report', 'friends.use', 'directory.view'],
    ]);

    schemaGrantOnce($db, 'v24_content_rating', [
        'guest'  => ['rating.vote', 'content.submit', 'content.propose'],
        'member' => ['rating.vote', 'content.submit', 'content.propose'],
    ]);

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
        'tuner_enabled'               => '0',
        'tuner_python'                => 'python3',
        'audit_enabled'               => '1',
        'audit_keep_days'             => '180',
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
        // schema v37: how long the per-poll history is kept. 90 days of a 30-minute poll is 4 320
        // rows — small enough that there is no roll-up and never will be.
        'index_poll_keep_days'        => '90',
        'index_poll_budget'           => '45',
        // schema v44: how a file list LOADS, once for the public search page and once for the panel
        // modals. Mode is scroll|button|all and decides only WHEN the browser asks again; the two
        // numbers are server rules (includes/index.php clamps them again on read). The defaults are
        // what 1.37.0 did: the search page scrolls and takes 2 000 a request, the modals show 5 000
        // and offer a button for the rest. index_files_max is the one new rule — before it, a member
        // with index.files_all could page a 500 000-file torrent to its end one OFFSET at a time.
        'index_files_mode'            => 'scroll',
        'index_files_batch'           => '2000',
        'index_files_max'             => '20000',
        'index_files_admin_mode'      => 'button',
        'index_files_admin_batch'     => '5000',
        'index_files_admin_max'       => '1000000',
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
        // schema v43: how many file paths the worker stores for ONE torrent, in both queues
        // (finish() is shared). Empty = keep the worker's own `max_files`; 1-META_MAX_FILES_MAX
        // overrides it live on the same ~60 s re-read. Empty is the default and must stay a real
        // value: an upgrade may not silently rewrite the length of the lists a running worker
        // produces. Imported peer lists keep federation's own limits — see effective_max_files().
        'meta_max_files'              => '',
        // schema v30: which pending hash the metadata worker takes next. The index queue is
        // millions of rows deep, so this decides what the tracker knows anything about for months.
        // 'oldest' is the order it has always used, and stays the default: an upgrade must not
        // silently reorder a running queue.
        //   oldest | newest | seeders | random | mix
        'meta_order_mode'             => 'oldest',
        // The mix, in percent, always summing to 100 (the panel keeps them there and the worker
        // re-normalises whatever it finds). Only consulted when the mode is 'mix'.
        'meta_order_mix_oldest'       => '0',
        'meta_order_mix_newest'       => '15',
        'meta_order_mix_seeders'      => '70',
        'meta_order_mix_random'       => '15',
        // schema v31: three more shares. `whitelist` at 0 does NOT mean "never" — it means the
        // whitelist keeps ABSOLUTE priority, exactly as in every non-mix mode, so an upgrade
        // changes nothing. Give it a number and it becomes a guaranteed share of the rotation.
        'meta_order_mix_whitelist'    => '0',
        'meta_order_mix_seen'         => '0',
        'meta_order_mix_completed'    => '0',
        // schema v32: the time zone the panel's own database session runs in, published so that
        // OTHER processes on the same database can match it. config/database.php sets the session
        // to PHP's zone, so every DATETIME the panel writes is in that zone -- and anything reading
        // those rows in a different one is silently hours out. Written by the janitor, read by the
        // metadata worker. Empty = never published yet; a reader must then leave its clock alone.
        'db_time_zone'                => '',
        // schema v32: addresses the inbound UDP rate limit must never drop. Comma or newline
        // separated, IPv4/IPv6, plain or CIDR. Empty = nobody is exempt (the shipped default).
        'net_limit_trusted'           => '',
        // schema v38: the mirror image of the box above. Addresses typed here are ALWAYS dropped, and
        // they beat an allow list — which is the whole point: "allow all of Poland, except these
        // three hosts" cannot be said with lists alone, because a list has no way to be more specific
        // than another list. Only trusted addresses beat these.
        'net_limit_blocked'           => '',
        // schema v33: how the stability probe judges load. The first number is what a run is allowed
        // to ADD to the load it found when it started -- an absolute ceiling is meaningless on a
        // machine whose normal working load is already near it, which is how every run on this box
        // stopped on its first step. The second is a hard stop for a machine genuinely being driven
        // into the ground, and is deliberately far above anything normal.
        'tuner_load_headroom'         => '0.35',
        'tuner_load_hard'             => '2.0',
        // sender address for outgoing mail (empty = use site_email); domain-validated on save
        'mail_from_email'             => '',
        // schema v9: registration requires an email + only verified accounts get their groups
        // (unverified sign-ins act as guests until the link is clicked); terms checkbox content
        // (empty = link to ?action=tos, otherwise shown in a modal); email-change cooldown; member
        // search master switches
        'users_require_email_verify'  => '1',
        // How many wrong password confirmations sign the session out. Registered here as well as in
        // the allow-list, the catalogue and the form: three of four is a setting the page shows and
        // the table has never heard of.
        'admin_reauth_max_attempts'   => '5',
        'bulk_mail_enabled'           => '0',
        'bulk_mail_per_minute'        => '20',
        'bulk_mail_max_attempts'      => '3',
        // Source links and descriptions on whitelist rows. Everything OFF, because these are fields
        // anonymous strangers type into, and an operator should have to decide to want them.
        'wl_allow_source_url'         => '0',
        'wl_allow_description'        => '0',
        'wl_content_review'           => '1',
        'desc_allow_bbcode'           => '1',
        'desc_allow_markdown'         => '1',
        'desc_max_chars'              => '4000',
        'desc_max_images'             => '3',
        'desc_max_links'              => '10',
        'link_trusted_domains'        => 'tryhackx.org',
        'search_allow_sl_refresh'     => '0',
        'search_sl_refresh_seconds'   => '120',
        'rate_limit_preview'          => '30',
        // Ratings. Off, and read-only for anonymous visitors by default: a public voting button is
        // the easiest thing on a site to automate, and the operator should choose to open it.
        'rep_enabled'                 => '0',
        'rep_mode'                    => 'thumbs',
        'rep_who_can_vote'            => 'users',
        'rep_show_in_results'         => '0',
        'rep_min_votes'               => '3',
        'rep_anon_weight'             => '25',
        'rep_rate_per_hour'           => '30',
        'captcha_pts_vote'            => '2',
        // Publish descriptions without review. Separate from wl_content_review because "I do not
        // moderate" and "I moderate, but let this through" are different decisions.
        'wl_content_autopublish'      => '0',
        'wl_edit_max_pending'         => '3',
        // Whitelist upkeep. Both off: refreshing costs tracker requests, and a rule that removes
        // other people's registrations must be switched on deliberately, never inherited.
        'wl_scrape_every_hours'       => '0',
        'wl_scrape_batch'             => '200',
        'wl_dead_after_days'          => '0',
        'wl_dead_action'              => 'mark',
        'wl_dead_every_days'          => '30',
        // Make a submission prove itself before the tracker serves it. Off: it changes what
        // registering MEANS, and that is not a thing to inherit from an upgrade.
        'wl_probe_required'           => '0',
        'wl_probe_timeout_minutes'    => '10',
        'wl_probe_on_fail'            => 'delete',
        // Live peer sync between two trackers. Off, with no command, on every install: the protocol
        // has no authentication, so this is the last thing that should ever default to on.
        'livesync_enabled'            => '0',
        'livesync_cmd'                => '',
        'livesync_bind_ip'            => '',
        'livesync_peer_ip'            => '',
        'livesync_port'               => '9696',
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
        // OpenTracker performance knobs. These describe what the admin WANTS; nothing is written
        // to the unit until Apply is pressed, so a fresh install has no drop-in and behaves as before.
        'ot_perf_cmd'                 => 'sudo -n /usr/local/sbin/tracker-instance.sh',
        'ot_nice'                     => '-2',
        'ot_cpu_weight'               => '100',
        'ot_cpu_affinity'             => '',
        'ot_limit_nofile'             => '65536',
        'ot_udp_workers'              => '',      // empty = leave opentracker's own config alone
        // Kernel network buffers (v16). The command is EMPTY by default and the feature is off:
        // defaulting it to a path would make every existing install start polling an endpoint that
        // shells out to a script nobody installed. The eight values are empty too — an empty value
        // means "the panel does not manage this key", and an unmanaged key never appears in the file
        // it writes. A form with eight boxes that writes all eight is how the expensive one gets
        // raised by accident.
        'sysctl_cmd'                  => '',
        'sysctl_enabled'              => '0',
        'sysctl_confirm_seconds'      => '120',   // clamped to whole minutes, 60-900
        // Database memory (includes/dbmem.php): the root helper that reads and sets the engine's memory knobs
        'dbmem_cmd'                   => '',
        'dbmem_enabled'               => '0',

        // Extra opentracker instances (v17). Three rows is the ENTIRE database cost of the
        // feature: systemd and the filesystem already hold the roster, and a second copy in the
        // panel would be a thing that drifts and survives teardown. Off, and with no command, so
        // an install that never wanted this never renders a card or forks a helper.
        'ot_cluster_enabled'          => '0',
        'ot_cluster_cmd'              => '',
        'ot_cluster_port_base'        => '',      // empty = derive from the primary's own port

        // Two-factor authentication (v18). This row is a MIRROR so the settings search can find the
        // section and a cheap check does not have to read a file; config/admin_2fa.json is
        // authoritative and holds the secret, because a TOTP secret is a credential and the settings
        // table is dumped by every backup. Deliberately absent from the save allow-list: no form post
        // may switch this, only the endpoint that also demands a password and a code.
        'admin_2fa_enabled'           => '0',
        'fed_enabled'                 => '0',
        'fed_node_name'               => '',
        'fed_export_enabled'          => '0',
        'fed_export_files'            => '1',
        'fed_export_max_batch'        => '2000',
        // rows alone never bounded a page: 20 000 torrents can carry millions of file records
        'fed_export_max_bytes'        => '8388608',    // 8 MB on the wire
        'fed_export_max_files'        => '200000',
        'fed_import_new'              => '0',
        'fed_import_mode'             => 'fill',       // fill = merge straight in; review = quarantine first
        // The importer's own ceilings. It runs as a separate process precisely so a big exchange
        // cannot burn web-request time; these keep it from burning the machine's RAM either.
        'fed_import_batch_rows'       => '500',
        'fed_import_batch_bytes'      => '33554432',   // 32 MB held at most, then commit
        'fed_import_max_seconds'      => '600',        // one pass; the cursor continues next time
        'fed_worker_mem_mb'           => '256',        // hard RLIMIT_AS — it dies rather than the box
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
        // schema v34: address lists. `net_lists_enabled` is the master switch — with it off the
        // firewall carries no list sets at all, whatever the individual lists say, so one click
        // undoes the whole feature without deleting anything anybody spent time importing.
        'net_lists_enabled'           => '0',
        'net_lists_ttl_default'       => '720',
        // A digest of the file the firewall was last loaded from, so the janitor can tell whether
        // anything actually changed instead of re-applying the ruleset every minute for nothing.
        'net_lists_stamp'             => '',
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
        // schema v45: transport security (includes/functions.php).
        //
        // `auto` is what every existing installation gets on upgrade and it changes nothing an
        // operator has to react to: it asks THIS request whether it was HTTPS, so a plain-HTTP LAN
        // panel keeps setting cookies without Secure and an HTTPS one starts setting it. Nobody is
        // logged out either way — Secure is a rule about SENDING a cookie, not about storing one,
        // and PHP only re-emits the session cookie when it creates or regenerates an id.
        // `always` is the one value that can lock the owner out, so save_settings refuses to store
        // it unless the saving request itself proves it is HTTPS.
        'cookie_secure_mode'          => 'auto',
        'client_proto_header'         => 'X-Forwarded-Proto',
        // HSTS is off, and stays off until somebody reads what it does. The pin lives in other
        // people's browsers and outlives any switch here; max-age starts at ONE DAY rather than the
        // year every guide recommends, because the default is what an operator gets the moment they
        // first flip the switch, and a mistake at one day costs one day.
        'hsts_enabled'                => '0',
        'hsts_max_age'                => '86400',
        'hsts_include_subdomains'     => '0',
        'hsts_preload'                => '0',
        // schema v46: Content-Security-Policy (includes/csp.php).
        //
        // 'report' on every installation, new and upgraded. A Content-Security-Policy-Report-Only
        // header cannot break a page on any browser: it is a different header name from the
        // enforcing one, so an Apache install whose .htaccess still ships the old policy keeps that
        // protection untouched while this one only observes. Enforcing is the operator's own move,
        // after they have looked at the violations list and cleared their server config.
        'csp_mode'                    => 'report',
        // Collection is OFF. Report-only still REPORTS, and browser extensions are the largest
        // source of CSP noise on the web — switching this on on upgrade day would point a firehose
        // of unauthenticated POSTs at a database three other applications share. The operator turns
        // it on for a day when they want evidence.
        // On, because the shipped mode is report-only and the two together are the whole point:
        // report-only blocks nothing, so with collection off it would be a header that does not
        // protect and does not tell anyone what it would have blocked. The store is capped at
        // csp_report_keep_rows and pruned by the janitor, so switching it on costs a bounded table.
        'csp_report_enabled'          => '1',
        'csp_report_keep_rows'        => '500',
        'csp_extra_hosts'             => '',
        // ── Favourites, public profiles and "my uploads" (v47) ──────────────────────────────
        // Every one of these ships OFF except the per-user limit. A feature that appears on an
        // operator's site without them switching it on is a decision taken on their behalf, and
        // these carry privacy: a list of what somebody likes is a list about them.
        'fav_enabled'                 => '0',   // the master switch; off, everything below answers 404/400
        'fav_max_per_user'            => '500', // what makes the query strategy safe; clamped [10, 5000]
        'fav_public_enabled'          => '0',   // may a list be public at all
        'fav_who_enabled'             => '0',   // does "who has this in favourites" exist
        'profiles_enabled'            => '0',   // is ?action=u reachable (uploads use it too, hence separate)
        // ── Lists (v51) ─────────────────────────────────────────────────────────────────────
        // Off, like everything above it. `lists_public_enabled` is the site-wide half of "may a
        // list be public": a reader's own per-list switch cannot outrank it, and turning it off
        // takes every list back off the public side without editing anybody's list for them.
        'lists_enabled'               => '0',   // the master switch; off, the endpoints answer 404
        'lists_public_enabled'        => '0',   // may any list be public at all
        'lists_max_per_user'          => '20',  // clamped [1, 200]
        'lists_max_items'             => '500', // rows in one list; clamped [10, 5000]
        // ── People reaching each other (v52) ─────────────────────────────────────────────────
        // Off, like everything above. `pm_who` is the DEFAULT a reader inherits until they choose
        // for themselves; 'friends' rather than 'all', because an inbox anybody may write to is a
        // decision to make deliberately rather than one to arrive at by not thinking about it.
        'pm_enabled'                  => '0',   // private messages exist at all
        'pm_who'                      => 'friends', // all | friends | nobody — the site default
        'pm_max_per_day'              => '50',  // clamped [1, 1000]
        'pm_max_chars'                => '4000',// clamped [200, 20000]
        'friends_enabled'             => '0',   // following and friendship
        'directory_enabled'           => '0',   // a browsable list of the people who asked to be on it
        'wl_submitter_public'         => '0',   // does the per-row visibility flag apply anywhere
        // Where the version line may appear: none | public | panel | both. The panel, by default:
        // an operator needs to know which build is answering, and a version number on a public page
        // mostly tells a visitor which published bugs to try.
        'version_display'             => 'panel',
        // How long the catalogue search may run before PHP stops it, in seconds. The point is that
        // it be a number somebody can see and change: php-fpm's own max_execution_time is 30 on this
        // deployment, and a search that crossed it came back as a bare 500 with nothing in any log.
        'search_time_budget'          => '60',
        // The Share buttons on the search page. The VIEW is addressable either way — the state is
        // in the address whether or not anybody is offered a button for it — so this switch is
        // about whether the site hands out links, not about what may be reached.
        'search_share_enabled'        => '1',
        // The language switcher rewrites the page instead of reloading it (assets/js/lang-swap.js).
        // Shipped off in 1.40.0 and on from 1.41.0: it had a release to be wrong in, every failure
        // path still falls back to the plain navigation it replaces, and the reload it replaces is
        // the thing people actually complained about.
        'lang_swap_enabled'           => '1',
        // ── The sign-in bridge (v49) ────────────────────────────────────────────────────────
        // Off. It lets somebody holding a key say "this is user 412 and I vouch for them", which is
        // the strongest thing any credential on this site can say — it must be a switch an operator
        // deliberately threw, never a default.
        'auth_bridge_enabled'         => '0',
        // May the bridge CREATE accounts, or only sign in ones that already exist and are linked?
        // On: a forum that is already the front door of the community should not need a second
        // registration step. Off is for an operator who wants to hand out accounts themselves.
        'auth_bridge_create'          => '1',
        // Whether an unlinked forum identity may take over an existing local account that shares its
        // address: 'none' (the default) or 'email_verified'.
        //
        // 'none' because email matching is account takeover wearing a helpful face: a partner who can
        // assert any address can assert an administrator's. 'email_verified' is still only "the
        // TRACKER verified this address", so it is offered for the case an operator actually has —
        // one community, two logins, everyone's address already confirmed here.
        'auth_bridge_merge'           => 'none',
        // How long the one-time handoff ticket lives, in seconds. Clamped [30, 900]. It only has to
        // survive one redirect.
        'auth_bridge_ttl'             => '120',
        // Where "continue to the forum" sends a signed-in tracker user. Must be an absolute http(s)
        // address; empty switches the outbound half off.
        'auth_bridge_return_url'      => '',
        // The forum's own sign-in page, offered on the tracker's login form as "sign in with …".
        'auth_bridge_login_url'       => '',
        // Does a sign-out on one side end the session on the other.
        'auth_bridge_logout'          => '1',
    ];
}

function schemaColumnExists(PDO $db, string $table, string $column): bool {
    $st = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

/** Is this column nullable? Used when a column's MEANING changes from "zero" to "no reading". */
function schemaColumnNullable(PDO $db, string $table, string $column): bool {
    $st = $db->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $column]);
    return strtoupper((string)$st->fetchColumn()) === 'YES';
}

/**
 * Does this table exist at all?
 *
 * `reports`, `archives` and the rest of the pre-schema tables are written by install.php, not by
 * trackerSchemaStatements() — so a database built from the statement list alone (which is what
 * tests/install_test.php builds, deliberately) does not have them. An ALTER against a table that is
 * not there throws, ensureSchema() stops on the spot, and every migration after it silently never
 * runs: the schema version stays where it was and nothing says why.
 */
function schemaTableExists(PDO $db, string $table): bool {
    $st = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function schemaIndexExists(PDO $db, string $table, string $index): bool {
    $st = $db->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
    $st->execute([$table, $index]);
    return (bool)$st->fetchColumn();
}

/**
 * Is this a context that may spend minutes rebuilding a large table? Only the CLI is.
 *
 * Every migration in this file is cheap except the ones that rebuild index_hashes, and those are
 * worth deferring by a minute rather than risking the site for them. Define
 * TRACKER_SCHEMA_FORCE_HEAVY before bootstrapping to override.
 */
function schemaHeavyAllowed(): bool {
    return PHP_SAPI === 'cli' || defined('TRACKER_SCHEMA_FORCE_HEAVY');
}

/**
 * Remember that a migration was declined, so ensureSchema does NOT record the new version -- the
 * schema really is not at that version, and writing the number anyway would strand the missing
 * column for ever. Static rather than a stored flag: it lasts exactly one process, which is exactly
 * as long as the question is open.
 */
function schemaDeferHeavy(?string $what = null): array {
    static $deferred = [];
    if ($what !== null) $deferred[] = $what;
    return $deferred;
}

/**
 * Bring the database up to TRACKER_SCHEMA_VERSION. Cheap when current (one array lookup).
 * Never throws — a failure leaves the version untouched so the next request retries, and the
 * error is logged. $cfg is refreshed in place so callers see the seeded defaults immediately.
 */
function ensureSchema(PDO $db, array &$cfg): void {
    if ((int)($cfg['schema_version'] ?? 0) >= TRACKER_SCHEMA_VERSION) return;
    try {
        // The web never waits for this lock. While a deferred heavy migration is running (the janitor
        // rebuilding index_hashes, which takes minutes) schema_version stays stale, so EVERY request
        // came here and sat in GET_LOCK for five seconds -- with a five-child php-fpm pool that was the
        // whole site, gone for the length of the rebuild. A request that finds the lock taken simply
        // runs on the schema it already has (it did before too, it just paid five seconds first) and
        // the holder records the version when it is done. The CLI keeps its wait: two janitors meeting
        // at the same migration is exactly the case the lock exists for, and there the wait is cheap.
        $lockWait = PHP_SAPI === 'cli' ? 5 : 0;
        $st = $db->prepare("SELECT GET_LOCK('tracker_schema', ?)");
        $st->execute([$lockWait]);
        $lock = $st->fetchColumn();
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
                // A guarded statement may be a list of equivalent candidates, cheapest first (see the
                // index_hashes ALTER). Only the last failure is fatal — the earlier ones are a server
                // saying "not that way", which is information, not an error.
                $tries = is_array($sql) ? $sql : [$sql];
                $lastErr = null;
                foreach ($tries as $i => $one) {
                    try { $db->exec($one); $lastErr = null; break; }
                    catch (PDOException $e) {
                        $lastErr = $e;
                        if ($i < count($tries) - 1) error_log('[tracker schema] retrying without: ' . $e->getMessage());
                    }
                }
                if ($lastErr !== null) { error_log('[tracker schema] ' . $lastErr->getMessage()); throw $lastErr; }
            }
            try { trackerSchemaDataMigrations($db, $cfg); } catch (\Throwable $e) { error_log('[tracker schema] data migration: ' . $e->getMessage()); }
            $ins = $db->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
            foreach (trackerSchemaDefaultSettings() as $k => $v) {
                $ins->execute([$k, $v]);
            }
            // Only claim the version if everything actually ran. A deferred rebuild means the schema
            // is not at this version, and recording the number anyway would strand the missing column.
            if (!schemaDeferHeavy()) {
                $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
                   ->execute([(string)TRACKER_SCHEMA_VERSION]);
            }
            $cfg = getSettings($db, true);
        } finally {
            $db->query("SELECT RELEASE_LOCK('tracker_schema')");
        }
    } catch (\Throwable $e) {
        error_log('[tracker schema] upgrade failed: ' . $e->getMessage());
    }
}
