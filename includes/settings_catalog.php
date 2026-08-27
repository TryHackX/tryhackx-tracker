<?php
/**
 * Search catalogue for the admin Settings page.
 *
 * Two things live here and NOTHING of it is printed into the page:
 *   1. the groups the Settings sections are filed under (the sub-menu above the form), and
 *   2. hidden keywords / synonyms per setting — the words an admin is likely to type when they do
 *      not remember the exact label ("bot", "spam", "2fa", "smtp", "cron", "seedbox"…).
 * assets/js/admin-settings.js pulls this through api/admin/settings_catalog.php (admin-only) and
 * merges it with the labels and hints it reads from the DOM, so a search matches both what is on
 * screen and these invisible aliases. Ranking is done client-side; see that file.
 *
 * The catalogue is code, not data: it ships with the settings it describes, so a new setting and
 * its search words land in the same commit (a settings table row would need its own migration and
 * would drift the moment someone edits a label).
 */

/** Sub-menu groups, in display order. `keywords` matches the WHOLE group when the query hits it. */
function settingsCatalogGroups(): array {
    return [
        ['id' => 'general',      'title' => 'Site & pages',       'icon' => 'bi-globe2',
         'keywords' => 'site name url branding announce address homepage front page public pages footer donations wallet transparency archive retention appearance'],
        ['id' => 'mail',         'title' => 'Contact & email',    'icon' => 'bi-envelope',
         'keywords' => 'mail email smtp sender from reply-to contact address unsubscribe hmac notifications messages postfix deliverability spf dkim'],
        ['id' => 'security',     'title' => 'Security & CAPTCHA', 'icon' => 'bi-shield-lock',
         'keywords' => 'security captcha recaptcha hcaptcha turnstile bot spam abuse rate limit throttle lockout brute force proxy ip admin session timeout hardening panel address hidden url'],
        ['id' => 'users',        'title' => 'User accounts',      'icon' => 'bi-people',
         'keywords' => 'users accounts registration login members groups permissions verification terms email change cooldown search'],
        ['id' => 'tracker',      'title' => 'Tracker & whitelist','icon' => 'bi-hdd-network',
         'keywords' => 'tracker opentracker mode blacklist whitelist accesslist schedule open hours service systemd restart reload scrape torrents hashes firewall nftables rate limit throttle udp packets pps traffic flood'],
        ['id' => 'stats',        'title' => 'Statistics',         'icon' => 'bi-graph-up',
         'keywords' => 'statistics stats numbers chart graph timeline history samples roll-up retention peers seeds leechers uptime live refresh ranges'],
        ['id' => 'index',        'title' => 'Index',              'icon' => 'bi-collection',
         'keywords' => 'index observed hashes catalogue metadata worker poll scrape names files search seeders prune'],
        ['id' => 'integrations', 'title' => 'API & federation',   'icon' => 'bi-plug',
         'keywords' => 'api server to server clients keys bearer bans federation cluster peers export import sync partners'],
        ['id' => 'credentials',  'title' => 'Admin credentials',  'icon' => 'bi-key',
         'keywords' => 'admin username password change credentials login account panel'],
    ];
}

/**
 * Hidden search words per setting key. Deliberately synonym-heavy: labels are already indexed from
 * the page, this is what the label does NOT say.
 */
function settingsCatalogKeywords(): array {
    return [
        // ── Site & pages ──
        'site_name'                => 'brand title header name of the tracker',
        'site_url'                 => 'base address domain canonical link https hostname',
        'announce_url'             => 'udp announce tracker address client torrent',
        'announce_url_https'       => 'http https announce tracker address client torrent tcp',
        'github_url'               => 'source code repository git project link footer',
        'items_per_page'           => 'pagination page size rows per page listing admin lists',
        'max_magnet_link_length'   => 'magnet link length limit report form input',
        'max_message_length'       => 'report message length limit characters textarea',
        'max_appeal_message_length'=> 'appeal message length limit characters textarea',
        'auto_archive_days'        => 'reports archive cleanup housekeeping retention old',
        'auto_archive_appeal_days' => 'appeals archive cleanup housekeeping retention old',
        'sent_emails_retention_days' => 'email log prune delete history sent mail retention gdpr',
        'transparency_enabled'     => 'transparency report public statistics takedowns page visibility',
        'transparency_per_page'    => 'transparency pagination rows page size',
        'donations_enabled'        => 'donate donations bitcoin btc eth monero xmr wallet support funding',
        'donation_fields'          => 'donate wallet address crypto bitcoin monero paypal label value list',
        'footer_start_year'        => 'copyright year footer since',
        'footer_brand_name'        => 'footer brand owner name copyright',
        'footer_brand_url'         => 'footer brand link owner website',
        'footer_brand_enabled'     => 'footer brand show hide copyright',
        'footer_tracker_name'      => 'footer powered by software name',
        'footer_tracker_url'       => 'footer powered by software link',
        'footer_tracker_author'    => 'footer author credit developer',
        'footer_tracker_author_url'=> 'footer author credit developer link',
        'footer_tracker_enabled'   => 'footer powered by show hide',
        'footer_os_name'           => 'footer operating system debian linux credit',
        'footer_os_url'            => 'footer operating system link debian linux',
        'footer_os_enabled'        => 'footer operating system show hide',
        'footer_os_since_year'     => 'footer operating system year since',

        // ── Contact & email ──
        'site_email'               => 'contact address support abuse reply-to public email',
        'mail_from_email'          => 'sender from envelope noreply outgoing mail dkim spf dmarc alignment header',
        'contact_visible'          => 'show hide contact email public page',
        'contact_obfuscate'        => 'hide email scraping spam harvesting javascript obfuscation',
        'hmac_secret'              => 'signing key unsubscribe token secret hmac links tamper',

        // ── Security & CAPTCHA ──
        'recaptcha_enabled'        => 'captcha master switch bot spam human verification challenge on off',
        'captcha_provider'         => 'recaptcha hcaptcha turnstile cloudflare google provider vendor switch which captcha',
        'recaptcha_site_key'       => 'google recaptcha v2 checkbox public key site key',
        'recaptcha_secret'         => 'google recaptcha v2 checkbox private secret key server',
        'recaptcha_v3_site_key'    => 'google recaptcha v3 invisible score public key site key',
        'recaptcha_v3_secret'      => 'google recaptcha v3 invisible score private secret key server',
        'recaptcha_v3_min_score'    => 'recaptcha v3 threshold score sensitivity strictness 0.5 bots',
        'turnstile_site_key'       => 'cloudflare turnstile public key site key privacy friendly',
        'turnstile_secret'         => 'cloudflare turnstile private secret key server',
        'hcaptcha_site_key'        => 'hcaptcha public key site key privacy accessibility',
        'hcaptcha_secret'          => 'hcaptcha private secret key server siteverify',
        'recaptcha_on_report'      => 'captcha report form protection abuse',
        'recaptcha_on_login'       => 'captcha admin login panel sign in protection brute force',
        'recaptcha_on_status'      => 'captcha status check form protection',
        'recaptcha_on_appeal'      => 'captcha appeal form protection',
        'recaptcha_on_block_check' => 'captcha block check hash lookup form protection',
        'captcha_threshold'        => 'smart captcha points score when to ask trigger activity',
        'captcha_grace_minutes'    => 'smart captcha grace period skip after solving remember',
        'captcha_pts_report'       => 'smart captcha points weight report action',
        'captcha_pts_status'       => 'smart captcha points weight status action',
        'captcha_pts_block_check'  => 'smart captcha points weight block check action',
        'captcha_pts_appeal'       => 'smart captcha points weight appeal action',
        'captcha_pts_login_fail'   => 'smart captcha points weight failed login attempt',
        'delete_captcha_attempts'  => 'report deletion password mistakes before captcha protection',
        'delete_lockout_attempts'  => 'report deletion password mistakes before lockout protection',
        'delete_lockout_minutes'   => 'report deletion lockout duration cooldown minutes',
        'rate_limit'               => 'reports per hour ip flood throttle limit abuse',
        'rate_limit_status'        => 'status checks per hour ip throttle limit',
        'rate_limit_block_check'   => 'block checks per hour ip throttle limit',
        'rate_limit_appeal'        => 'appeals per hour ip throttle limit',
        'blacklist_path'           => 'opentracker blacklist file accesslist path blocked hashes disk',
        'login_lockout_attempts'   => 'brute force failed logins before lock admin panel',
        'login_lockout_minutes'    => 'brute force lock window duration admin panel',
        'admin_login_path'         => 'hidden admin url secret panel address action path move rename obscure security by obscurity backend wp-admin',
        'admin_hidden_behavior'    => 'admin urls signed out redirect 404 hide login form leak panel existence',
        'admin_session_idle_minutes' => 'admin session idle timeout auto logout inactivity',
        'admin_session_absolute_hours' => 'admin session maximum lifetime hard logout cap hours',
        'trusted_proxy_ips'        => 'reverse proxy cloudflare nginx real ip forwarded trust',
        'client_ip_header'         => 'x-forwarded-for cf-connecting-ip real ip header proxy',

        // ── User accounts ──
        'users_enabled'            => 'accounts registration login members system on off',
        'users_registration_enabled' => 'sign up register new accounts open closed invite',
        'users_links_visible'      => 'account links navigation menu show hide sign in',
        'users_default_group'      => 'default group new accounts member permissions role',
        'users_notify_expiry_days' => 'expiring access warning email notice days before',
        'users_require_email_verify' => 'email verification gate confirm address unverified guest activation link',
        'users_terms_text'         => 'terms of service tos rules agreement checkbox registration modal',
        'users_email_change_cooldown_days' => 'email change cooldown wait between changes abuse',
        'rate_limit_user_login'    => 'login attempts per hour ip account throttle',
        'rate_limit_user_register' => 'registrations per hour ip throttle spam accounts',
        'rate_limit_index_search'  => 'search queries per hour throttle members index',
        'index_search_enabled'     => 'member search page kill switch disable searching catalogue',
        'index_search_include_whitelist' => 'search whitelist rows included results registered torrents',

        // ── Tracker & whitelist ──
        'tracker_mode'             => 'blacklist whitelist open closed accesslist which torrents served',
        'whitelist_path'           => 'opentracker whitelist file accesslist path disk generated',
        'whitelist_public_enabled' => 'public registration form add torrent whitelist page visible',
        'whitelist_submit_mode'    => 'who can register torrents public visitors accounts members captcha',
        'whitelist_max_per_submission' => 'hashes per form submission batch limit',
        'rate_limit_whitelist'     => 'registrations per hour ip whitelist throttle',
        'whitelist_ip_daily_max'   => 'per ip daily cap whitelist registrations abuse',
        'whitelist_daily_cap'      => 'global daily cap whitelist registrations total',
        'whitelist_reload_min_interval' => 'debounce tracker reload sighup interval accesslist regenerate',
        'whitelist_scrape_url'     => 'scrape endpoint seeders leechers refresh source url',
        'whitelist_require_tracker'=> 'magnet must contain this tracker announce check registration',
        'whitelist_tracker_hosts'  => 'accepted announce hosts magnet validation domains',
        'tracker_schedule_enabled' => 'schedule automatic mode switching open hours timetable cron',
        'tracker_schedule'         => 'weekly plan open hours mon tue wed thu fri sat sun times',
        'tracker_schedule_tz'      => 'timezone schedule iana europe warsaw utc clock',
        'tracker_mode_switch_cmd'  => 'command script sudo switch mode systemd shell hook',
        'opentracker_service_name' => 'systemd unit service name restart reload daemon',
        'opentracker_restart_use_sudo' => 'sudo privileges restart service systemctl permission',
        'opentracker_auto_reload'  => 'automatic reload sighup after whitelist change',
        'tracker_uptime_warn_days' => 'uptime warning threshold days status card',
        'tracker_uptime_danger_days' => 'uptime danger threshold days status card restart reminder',
        'tracker_blacklist_warn_count' => 'blacklist size warning threshold blocked hashes',
        'tracker_blacklist_danger_count' => 'blacklist size danger threshold blocked hashes',
        'admin_near_pages'         => 'near pages radius bulk actions this page neighbours metadata',

        // ── Tracker & whitelist: inbound UDP throttle (includes/netlimit.php) ──
        'net_monitor_enabled'      => 'udp traffic monitor packets per second pps counters measure record firewall nftables graph chart bandwidth flood',
        'net_sample_seconds'       => 'sample interval seconds resolution pps recording granularity udp traffic',
        'net_keep_days'            => 'udp traffic samples retention days keep history prune pps',
        'net_limit_enabled'        => 'udp rate limit throttle dlawik cap flood ddos drop packets nftables firewall ingress inbound on off',
        'net_limit_pps'            => 'packets per second pps budget threshold limit rate udp throttle cap drop swarm flood',
        'net_limit_burst'          => 'burst packets allowance spike tolerance rate limiter token bucket',
        'net_limit_port'           => 'tracker udp port 6969 announce which port is limited',
        'net_limit_cmd'            => 'helper script sudo root nft nftables command tracker-netlimit path privileged',
        'net_auto_enabled'         => 'automatic adaptive limit self tuning auto adjust throttle hysteresis',
        'net_auto_min'             => 'automatic mode lower bound floor minimum pps band',
        'net_auto_max'             => 'automatic mode upper bound ceiling maximum pps band',
        'net_auto_target'          => 'automatic mode target packets per second goal setpoint how much traffic to accept',
        'net_auto_target_cpu'      => 'automatic mode cpu load per core percentage guard overload tighten',

        // ── Statistics ──
        'tracker_stats_enabled'    => 'statistics page numbers swarm live counters on off',
        'tracker_stats_url'        => 'stats source xml opentracker mode everything endpoint',
        'tracker_stats_interval'   => 'refresh seconds browser poll live update frequency',
        'tracker_stats_page_interval' => 'stats page refresh seconds poll frequency',
        'tracker_stats_cache_ttl'  => 'server cache seconds shared upstream fetch throttle',
        'tracker_stats_show_home'  => 'home page widget statistics show hide front page',
        'tracker_stats_timeout'    => 'fetch timeout seconds upstream stats slow',
        'tracker_stats_min_loading'=> 'loading animation minimum duration spinner',
        'tracker_stats_max_loading'=> 'loading animation maximum duration spinner give up',
        'tracker_stats_peer_label_style' => 'peers label percent share style display',
        'tracker_stats_livesync_mode' => 'live sync countdown refresh alignment browser',
        'stats_timeline_enabled'   => 'timeline chart history graph samples recording on off',
        'stats_timeline_interval'  => 'sample every seconds resolution granularity recording',
        'stats_timeline_raw_days'  => 'raw samples retention days keep detailed history',
        'stats_timeline_keep_days' => 'roll-up retention days 5 minute buckets keep history',
        'stats_timeline_public'    => 'public chart visitors admins only visibility timeline',
        'stats_timeline_ranges'    => 'range buttons 24h 7d 2w 1m 3m all which buttons offered zoom periods',
        'stats_timeline_default_range' => 'default range opens first view 24h all preselected period',
        'stats_timeline_custom_range' => 'custom span slider free range arbitrary period continuous',

        // ── Index ──
        'index_enabled'            => 'observed hashes catalogue index on off collect',
        'index_source_url'         => 'full scrape url source endpoint opentracker',
        'index_poll_minutes'       => 'poll interval minutes full scrape frequency janitor',
        'index_min_seeders'        => 'minimum seeders keep threshold prune noise',
        'index_max_rows'           => 'maximum rows cap size database growth limit',
        'index_grace_days'         => 'grace days before pruning unseen hashes',
        'index_protect_days'       => 'protect new rows days from pruning',
        'index_meta_daily_budget'  => 'metadata fetches per day budget worker limit',
        'index_keep_files'         => 'store file lists names torrent contents disk space',
        'index_poll_budget'        => 'seconds per poll run time budget truncated resume',
        'index_meta_auto_queue'    => 'automatic metadata queue names resolve background',
        'meta_worker_concurrency'  => 'worker parallel fetches threads concurrency metadata speed',

        // ── API & federation ──
        'api_enabled'              => 'server to server api endpoints clients integration on off',
        'api_ban_days'             => 'api ban duration days abuse blocked clients',
        'api_ban_exempt_ips'       => 'api ban whitelist exempt addresses never ban own server',
        'fed_enabled'              => 'federation cluster partners sharing network on off',
        'fed_node_name'            => 'federation node identity name peers display',
        'fed_export_enabled'       => 'federation export share our whitelist outgoing',
        'fed_export_files'         => 'federation export file lists metadata included',
        'fed_export_max_batch'     => 'federation export batch size rows per request',
        'fed_import_new'           => 'federation import add new hashes from peers incoming',
        'fed_pull_minutes'         => 'federation pull interval minutes sync frequency',

        // ── Admin credentials ──
        'admin_username'           => 'admin login name panel user rename',
        'admin_email'              => 'admin account email address own mailbox notices verification password reset two-step change confirm',
        'current_password'         => 'current password confirm verify identity',
        'new_password'             => 'change admin password new set strong',
        'confirm_password'         => 'repeat new password confirmation match',
    ];
}

/** Catalogue payload for api/admin/settings_catalog.php (and any future consumer). */
function settingsCatalogPayload(): array {
    return ['success' => true, 'groups' => settingsCatalogGroups(), 'keywords' => settingsCatalogKeywords()];
}
