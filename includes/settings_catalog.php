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

/**
 * Sub-menu groups, in display order. `keywords` matches the WHOLE group when the query hits it.
 *
 * EACH GROUP ANSWERS ONE QUESTION AN ADMIN ASKS (reorganised in 1.69.0), and a section is filed where
 * that admin looks first — by its fields, never by its id or an old title. templates/admin/settings.php
 * lays the sections out in this order, group by group, because "All settings" is the page's own order.
 */
function settingsCatalogGroups(): array {
    return [
        // What the site is called and where it answers, and its pages: the front page, Terms & Info,
        // transparency, donations, the footer. The operator's digest (mail), the health check and
        // the archiving of old reports (maintenance) left in 1.69.0 — none of them is a page.
        ['id' => 'general',      'title' => 'Site & pages',       'icon' => 'bi-globe2',
         'keywords' => 'site name url branding announce address homepage front page public pages footer donations wallet transparency appearance'],
        // How mail goes out and where people write to you — the operator's digest included (1.69.0).
        ['id' => 'mail',         'title' => 'Contact & email',    'icon' => 'bi-envelope',
         'keywords' => 'mail email smtp sender from reply-to contact address unsubscribe hmac notifications messages postfix deliverability spf dkim digest summary operator queues waiting'],
        // How abuse is kept out: the CAPTCHA, the public forms' hourly limits, the panel's door,
        // transport and the content policy.
        ['id' => 'security',     'title' => 'Security & CAPTCHA', 'icon' => 'bi-shield-lock',
         'keywords' => 'security captcha recaptcha hcaptcha turnstile bot spam abuse rate limit throttle lockout brute force proxy ip admin session timeout hardening panel address hidden url'],
        // Who may have an account and how they sign in and reach each other: registration, the
        // members' second factor, messages / friends / the directory, the sign-in bridge. Favourites
        // and lists went to Profiles and the audit log to maintenance in 1.69.0.
        ['id' => 'users',        'title' => 'User accounts',      'icon' => 'bi-people',
         'keywords' => 'users accounts registration login members groups permissions verification terms email change cooldown search two factor 2fa messages friends directory bridge sso'],
        // Pictures and covers (1.63.0) got a chip of their own beside the accounts, the way Sounds
        // did: they are about how a person LOOKS on the site, and "User accounts" is already a long
        // list about how they sign in. Since 1.69.0 it is everything a member has and shows on a
        // profile: the profile page's own switch, favourites and registered torrents, the picture and
        // the cover, the description, likes / ratings, lists.
        ['id' => 'profiles',     'title' => 'Profiles',           'icon' => 'bi-person-badge',
         'keywords' => 'profile profiles avatar avatars picture photo portrait image cover banner header background hero awatar zdjecie okladka tlo public profile page favourites favorites bookmarks lists collections description about bio likes ratings'],
        // "Tracker & whitelist" had grown to fourteen sections -- half of them about the machine
        // rather than about the whitelist. Split three ways: what the tracker SERVES (here), the
        // SERVICE that runs it, and the NETWORK it runs on. The keywords are split with it, so a
        // search for "nftables" no longer lands on the accesslist.
        // The tracker mode and its accesslist files (the blacklist's too, since 1.69.0), what may be
        // registered and how it proves itself, upkeep, the schedule.
        ['id' => 'tracker',      'title' => 'Tracker & whitelist','icon' => 'bi-hdd-network',
         'keywords' => 'tracker mode blacklist whitelist accesslist file path schedule open hours scrape torrents hashes submissions prove magnet upkeep dead removal'],
        ['id' => 'opentracker',  'title' => 'OpenTracker service','icon' => 'bi-hdd-stack',
         'keywords' => 'opentracker service systemd unit restart reload sighup performance workers threads nice scheduling open files instances extra ports livesync live peer sync cluster second machine'],
        // The network the tracker runs on: the UDP firewall and its address lists (here since 1.69.0,
        // under the section they extend), kernel buffers, database memory, the stability probe.
        ['id' => 'network',      'title' => 'Network & limits',   'icon' => 'bi-speedometer2',
         'keywords' => 'database mariadb mysql memory ram buffer pool innodb firewall nftables rate limit throttle udp packets pps traffic flood inbound outbound egress budget trusted exempt kernel buffers rmem wmem sysctl backlog stability probe tuner address lists allow block countries zone ipdeny'],
        ['id' => 'stats',        'title' => 'Statistics',         'icon' => 'bi-graph-up',
         'keywords' => 'statistics stats numbers chart graph timeline history samples roll-up retention peers seeds leechers uptime live refresh ranges'],
        // What members add to a torrent: a description and a source link (and who reviews them), and a
        // rating. "Descriptions & review" until 1.69.0 — the ratings were in it and its name hid them.
        ['id' => 'content',      'title' => 'Descriptions & ratings', 'icon' => 'bi-card-text',
         'keywords' => 'description source link bbcode markdown review moderation queue rewrite proposal images links preview submitter words text ratings rating votes vote thumbs stars reputation likes score'],
        // The shoutbox and the sounds were a section each inside "Descriptions & review" and "User
        // accounts", which is where they landed rather than where anybody would look for them: one
        // is a room people talk in and the other is what the whole site plays. Two chips of their
        // own, and the sections keep their ids so every bookmark into them still opens.
        ['id' => 'shoutbox',     'title' => 'Shoutbox',           'icon' => 'bi-chat-left-dots',
         'keywords' => 'shoutbox shout chat chatbox room talk tagboard cbox czat emotes stickers emoji rules flood retention pinned announcement'],
        ['id' => 'sounds',       'title' => 'Sounds',             'icon' => 'bi-volume-up',
         'keywords' => 'sound sounds audio chime notification alert ping mute volume upload library dzwieki'],
        ['id' => 'index',        'title' => 'Index',              'icon' => 'bi-collection',
         'keywords' => 'index observed hashes catalogue metadata worker poll scrape names files search seeders prune'],
        ['id' => 'integrations', 'title' => 'API & federation',   'icon' => 'bi-plug',
         'keywords' => 'api server to server clients keys bearer bans federation cluster peers export import sync partners'],
        // Keeping the site running and its records: backups, the archiving of old reports, appeals and
        // sent mail, the health check an uptime monitor asks, the panel's audit log. "Backups" until
        // 1.69.0, when the other three joined it from Site & pages and User accounts.
        ['id' => 'maintenance',  'title' => 'Backups & maintenance', 'icon' => 'bi-archive',
         'keywords' => 'backup backups archive archives dump restore recovery disaster snapshot copy rotation retention schedule gpg encryption mariadb mysqldump kopia zapasowa maintenance upkeep housekeeping janitor auto-archive reports appeals email log prune health check uptime monitor kuma audit log who did what history utrzymanie'],
        ['id' => 'languages',    'title' => 'Languages',          'icon' => 'bi-translate',
         'keywords' => 'language languages translation translations locale localisation localization i18n polish english switcher default automatic accept-language install upload json coverage jezyk jezyki tlumaczenie'],
        ['id' => 'credentials',  'title' => 'Admin credentials',  'icon' => 'bi-key',
         'keywords' => 'admin username password change credentials login account panel'],
    ];
}

/**
 * Hidden search words per setting key. Deliberately synonym-heavy: labels are already indexed from
 * the page, this is what the label does NOT say.
 *
 * Laid out the way the page is (1.69.0): group by group, and under each group section by section
 * (`// #section-…` names the section a key's field is in). The headers had drifted — the "User
 * accounts" block held the ratings, live peer sync and the description rules — so a new key goes
 * under the section its field is in, and moves with it.
 */
function settingsCatalogKeywords(): array {
    return [
        // ── Site & pages ──
        // #section-site
        'site_name'                 => 'brand title header name of the tracker',
        'site_url'                  => 'base address domain canonical link https hostname',
        'announce_url_https'        => 'http https announce tracker address client torrent tcp',
        'announce_url'              => 'udp announce tracker address client torrent',
        'github_url'                => 'source code repository git project link footer',
        'site_timezone'             => 'time zone timezone tz clock hour hours local time display shoutbox utc offset gmt iana dst summer strefa czasowa godzina czas',
        'icon_library'              => 'icons icon font library glyph glyphs symbols font awesome fontawesome fa bootstrap icons bi appearance look style theme ikony ikona biblioteka czcionka wyglad',
        'fa_source'                 => 'font awesome fontawesome fa source version free pro 6 7 6.7.2 7.3.1 jsdelivr cdn package pack zip upload licence license zrodlo wersja paczka pakiet',
        'fa_pack'                   => 'font awesome pro package pack zip upload install server path directory folder licence license kit manage delete verify paczka pakiet wgraj zainstaluj sciezka',
        'fa_pack_styles'            => 'font awesome styles families style files sharp duotone light thin solid regular jelly chisel etch slab notdog utility whiteboard all.css load loaded style pliki rodziny',
        'fa_style'                  => 'font awesome default style family weight solid regular light thin duotone sharp preview icons look styl domyslny podglad',
        // #section-donations
        'donations_enabled'         => 'donate donations bitcoin btc eth monero xmr wallet support funding',
        'donation_fields'           => 'donate wallet address crypto bitcoin monero paypal label value list',
        // #section-transparency
        'transparency_enabled'      => 'transparency report public statistics takedowns page visibility',
        'transparency_per_page'     => 'transparency pagination rows page size',
        // #section-home-layout
        'home_layout'               => 'home page layout front page order sections arrange rearrange drag drop reorder hide headings titles tagline',
        // #section-footer
        'footer_start_year'         => 'copyright year footer since',
        'version_display'           => 'version number build release footer show where panel public visible',
        'footer_brand_enabled'      => 'footer brand show hide copyright',
        'footer_brand_name'         => 'footer brand owner name copyright',
        'footer_brand_url'          => 'footer brand link owner website',
        'footer_tracker_enabled'    => 'footer powered by show hide',
        'footer_tracker_name'       => 'footer powered by software name',
        'footer_tracker_url'        => 'footer powered by software link',
        'footer_tracker_author'     => 'footer author credit developer',
        'footer_tracker_author_url' => 'footer author credit developer link',
        'footer_os_enabled'         => 'footer operating system show hide',
        'footer_os_name'            => 'footer operating system debian linux credit',
        'footer_os_url'             => 'footer operating system link debian linux',
        'footer_os_since_year'      => 'footer operating system year since',

        // ── Contact & email ──
        // #section-mail
        'site_email'                => 'contact address support abuse reply-to public email',
        'mail_from_email'           => 'sender from envelope noreply outgoing mail dkim spf dmarc alignment header',
        'contact_visible'           => 'show hide contact email public page',
        'contact_obfuscate'         => 'hide email scraping spam harvesting javascript obfuscation',
        'hmac_secret'               => 'signing key unsubscribe token secret hmac links tamper',
        // #section-digest
        'digest_enabled'            => 'digest email summary queues waiting review operator janitor daily',
        'digest_to'                 => 'digest email address recipient operator summary',
        'digest_hours'              => 'digest email frequency hours interval how often summary',
        'digest_min'                => 'digest email threshold minimum waiting before sending summary',

        // ── Security & CAPTCHA ──
        // #section-captcha
        'recaptcha_enabled'         => 'captcha master switch bot spam human verification challenge on off',
        'captcha_provider'          => 'recaptcha hcaptcha turnstile cloudflare google provider vendor switch which captcha',
        'recaptcha_site_key'        => 'google recaptcha v2 checkbox public key site key',
        'recaptcha_secret'          => 'google recaptcha v2 checkbox private secret key server',
        'recaptcha_v3_site_key'     => 'google recaptcha v3 invisible score public key site key',
        'recaptcha_v3_secret'       => 'google recaptcha v3 invisible score private secret key server',
        'recaptcha_v3_min_score'    => 'recaptcha v3 threshold score sensitivity strictness 0.5 bots',
        'turnstile_site_key'        => 'cloudflare turnstile public key site key privacy friendly',
        'turnstile_secret'          => 'cloudflare turnstile private secret key server',
        'hcaptcha_site_key'         => 'hcaptcha public key site key privacy accessibility',
        'hcaptcha_secret'           => 'hcaptcha private secret key server siteverify',
        'recaptcha_on_report'       => 'captcha report form protection abuse',
        'recaptcha_on_login'        => 'captcha admin login panel sign in protection brute force',
        'recaptcha_on_status'       => 'captcha status check form protection',
        'recaptcha_on_appeal'       => 'captcha appeal form protection',
        'recaptcha_on_block_check'  => 'captcha block check hash lookup form protection',
        // #section-captcha-smart
        'captcha_threshold'         => 'smart captcha points score when to ask trigger activity',
        'captcha_grace_minutes'     => 'smart captcha grace period skip after solving remember',
        'captcha_pts_report'        => 'smart captcha points weight report action',
        'captcha_pts_status'        => 'smart captcha points weight status action',
        'captcha_pts_block_check'   => 'smart captcha points weight block check action',
        'captcha_pts_appeal'        => 'smart captcha points weight appeal action',
        'captcha_pts_login_fail'    => 'smart captcha points weight failed login attempt',
        'delete_captcha_attempts'   => 'report deletion password mistakes before captcha protection',
        'delete_lockout_attempts'   => 'report deletion password mistakes before lockout protection',
        'delete_lockout_minutes'    => 'report deletion lockout duration cooldown minutes',
        // #section-limits
        'rate_limit'                => 'reports per hour ip flood throttle limit abuse',
        'rate_limit_status'         => 'status checks per hour ip throttle limit',
        'rate_limit_block_check'    => 'block checks per hour ip throttle limit',
        'rate_limit_appeal'         => 'appeals per hour ip throttle limit',
        'items_per_page'            => 'pagination page size rows per page listing admin lists',
        'admin_near_pages'          => 'near pages radius bulk actions this page neighbours metadata',
        'max_message_length'        => 'report message length limit characters textarea',
        'max_appeal_message_length' => 'appeal message length limit characters textarea',
        'max_magnet_link_length'    => 'magnet link length limit report form input',
        // #section-admin-access
        'admin_login_path'          => 'hidden admin url secret panel address action path move rename obscure security by obscurity backend wp-admin',
        'admin_hidden_behavior'     => 'admin urls signed out redirect 404 hide login form leak panel existence',
        'admin_session_idle_minutes' => 'admin session idle timeout auto logout inactivity',
        'admin_session_absolute_hours' => 'admin session maximum lifetime hard logout cap hours',
        'login_lockout_attempts'    => 'brute force failed logins before lock admin panel',
        'login_lockout_minutes'     => 'brute force lock window duration admin panel',
        'admin_reauth_max_attempts' => 'wrong password confirm dangerous action sign out brute force reauth',
        'trusted_proxy_ips'         => 'reverse proxy cloudflare nginx real ip forwarded trust cidr range subnet block /24 /20 cloudflare ranges netmask prefix',
        'client_ip_header'          => 'x-forwarded-for cf-connecting-ip real ip header proxy',
        // #section-transport
        'cookie_secure_mode'        => 'secure cookie flag https tls session remember me language cookie transport lockout',
        'client_proto_header'       => 'x-forwarded-proto x-forwarded-ssl forwarded scheme https header proxy tls detection',
        'hsts_enabled'              => 'hsts strict transport security force https browser pin upgrade http',
        'hsts_max_age'              => 'hsts max-age duration seconds pin how long withdraw retract zero',
        'hsts_include_subdomains'   => 'hsts includesubdomains subdomains wildcard apex siblings mail forum',
        'hsts_preload'              => 'hsts preload list browser binary irreversible hstspreload submit',
        // #section-csp
        'csp_mode'                  => 'csp content security policy nonce inline script xss header report-only enforce violation naglowek polityka bezpieczenstwa tresci',
        'csp_extra_hosts'           => 'csp extra hosts cdn analytics allow script-src trusted origin dodatkowe zaufane hosty',
        'csp_report_enabled'        => 'csp report uri violation collect reports raporty naruszenia zbieraj',
        'csp_report_keep_rows'      => 'csp violations rows keep prune janitor limit wiersze naruszen',

        // ── User accounts ──
        // #section-users
        'users_enabled'             => 'accounts registration login members system on off',
        'users_registration_enabled' => 'sign up register new accounts open closed invite',
        'users_links_visible'       => 'account links navigation menu show hide sign in',
        'users_default_group'       => 'default group new accounts member permissions role',
        'users_notify_expiry_days'  => 'expiring access warning email notice days before',
        'bulk_mail_enabled'         => 'mass bulk email newsletter send everyone group broadcast',
        'bulk_mail_per_minute'      => 'bulk mass email rate per minute throttle queue spam reputation',
        'bulk_mail_max_attempts'    => 'bulk mass email retries attempts before giving up failed',
        'users_require_email_verify' => 'email verification gate confirm address unverified guest activation link',
        'users_email_change_cooldown_days' => 'email change cooldown wait between changes abuse',
        'users_terms_text'          => 'terms of service tos rules agreement checkbox registration modal',
        'rate_limit_user_login'     => 'login attempts per hour ip account throttle',
        'rate_limit_user_register'  => 'registrations per hour ip throttle spam accounts',
        'rate_limit_index_search'   => 'search queries per hour throttle members index',
        'rate_limit_hash_check'     => 'status page hash check lookups per hour throttle oracle',
        'rate_limit_preview'        => 'description preview rate limit per minute render bbcode markdown',
        'index_search_enabled'      => 'member search page kill switch disable searching catalogue',
        'search_time_budget'        => 'search timeout time limit budget seconds slow query 500 execution',
        'search_share_enabled'      => 'share link copy url permalink address search results torrent bookmark',
        'index_search_include_whitelist' => 'search whitelist rows included results registered torrents',
        // #section-user2fa
        'user_2fa_enabled'          => 'two factor authentication 2fa members accounts totp authenticator app',
        'user_2fa_required'         => 'two factor required force 2fa panel access moderators admins',
        // #section-people
        'pm_enabled'                => 'private messages pm inbox conversations members write dm',
        'pm_who'                    => 'private messages who can write default friends everybody nobody',
        'pm_max_per_day'            => 'private messages limit per day spam flood cap',
        'pm_max_chars'              => 'private message length limit characters maximum',
        'pm_live_seconds'           => 'messages live refresh poll seconds chat realtime conversation updates',
        'site_live_seconds'         => 'navigation badge unread count refresh pulse poll seconds notifications live site wide',
        'pm_typing_enabled'         => 'typing indicator is writing messages live chat',
        'friends_enabled'           => 'friends following follow requests accept members contacts',
        'directory_enabled'         => 'member directory list of users browse people search members',
        // #section-authbridge
        'auth_bridge_enabled'       => 'bridge sso single sign on forum flarum login register external account link',
        'auth_bridge_create'        => 'bridge sso register create account forum new user automatic',
        'auth_bridge_merge'         => 'bridge sso merge link existing account email match takeover',
        'auth_bridge_ttl'           => 'bridge sso ticket token handoff expiry seconds timeout',
        'auth_bridge_login_url'     => 'bridge sso forum url sign in login button',
        'auth_bridge_return_url'    => 'bridge sso forum url return continue redirect outbound',
        'auth_bridge_logout'        => 'bridge sso logout sign out both sides two-way',

        // ── Profiles ──
        // #section-favourites
        'fav_enabled'               => 'favourites favorites bookmarks starred saved list star member',
        'fav_max_per_user'          => 'favourites limit maximum per user cap how many starred',
        'fav_public_enabled'        => 'favourites public profile share list visible privacy',
        'fav_who_enabled'           => 'who has this favourites list people names watchers privacy',
        'profiles_enabled'          => 'profile public page user page member page ?action=u',
        'wl_submitter_public'       => 'submitter uploader attribution my torrents uploads credit profile who registered',
        // #section-profiles
        'avatars_enabled'           => 'avatar avatars picture profile photo portrait upload member face awatar zdjecie master switch',
        'covers_enabled'            => 'cover covers banner header background profile hero image photo okladka tlo master switch',
        'avatar_max_kb'             => 'avatar cover upload size limit kilobytes kb megabytes mb maximum file photo too large',
        'avatar_max_mp'             => 'avatar cover megapixels pixels resolution dimensions limit decode memory bomb header check',
        'cover_height'              => 'cover height pixels desktop profile band banner header tall',
        'cover_height_mobile'       => 'cover height pixels mobile phone profile band banner header small screen',
        'cover_overlay'             => 'cover overlay readability gradient darken shade dim text contrast name legible',
        'avatar_default'            => 'default avatar picture letter initials generated fallback no picture placeholder image',
        'account_picture_side'      => 'account page picture avatar photo block side left right column card position where drawn security strona konta zdjecie awatar lewa prawa',
        'account_cover_side'        => 'account page cover banner header block side left right column card position where drawn privacy strona konta okladka tlo lewa prawa',
        'avatar_default_image'      => 'default avatar picture image upload site fallback everybody without a picture position',
        'cover_default_image'       => 'default cover banner header image upload site fallback everybody without a cover position',
        // #section-profile-bio
        'profile_bio_enabled'       => 'profile description about me bio biography text signature tagline intro bbcode master switch opis profilu o mnie',
        'profile_bio_max'           => 'profile description about me bio length limit maximum characters letters short text opis dlugosc znaki limit',
        // #section-profile-votes
        'profile_votes_enabled'     => 'profile likes ratings votes thumbs stars rated liked list tab section public privacy polubienia oceny glosy lajki profil',
        // #section-lists
        'lists_enabled'             => 'lists collections packs playlist folders bundle own list member',
        'lists_public_enabled'      => 'lists public share profile visible privacy collection',
        'lists_max_per_user'        => 'lists limit maximum how many collections per user cap',
        'lists_max_items'           => 'list items limit maximum hashes in one list cap size',

        // ── Tracker & whitelist ──
        // #section-whitelist
        'tracker_mode'              => 'blacklist whitelist open closed accesslist which torrents served',
        'whitelist_path'            => 'opentracker whitelist file accesslist path disk generated',
        'blacklist_path'            => 'opentracker blacklist file accesslist path blocked hashes disk',
        'whitelist_public_enabled'  => 'public registration form add torrent whitelist page visible',
        'whitelist_submit_mode'     => 'who can register torrents public visitors accounts members captcha',
        'whitelist_max_per_submission' => 'hashes per form submission batch limit',
        'rate_limit_whitelist'      => 'registrations per hour ip whitelist throttle',
        'whitelist_ip_daily_max'    => 'per ip daily cap whitelist registrations abuse',
        'whitelist_daily_cap'       => 'global daily cap whitelist registrations total',
        'whitelist_reload_min_interval' => 'debounce tracker reload sighup interval accesslist regenerate',
        'whitelist_scrape_url'      => 'scrape endpoint seeders leechers refresh source url',
        'whitelist_require_tracker' => 'magnet must contain this tracker announce check registration',
        'whitelist_tracker_hosts'   => 'accepted announce hosts magnet validation domains',
        // #section-probe
        'wl_probe_required'         => 'magnet check verify probe test submission must prove itself fetch metadata seeders peers alive before registering dead link validation',
        'wl_probe_timeout_minutes'  => 'magnet probe verify check timeout how long a submission has to prove itself minutes wait',
        'wl_probe_on_fail'          => 'magnet probe verify check failed what happens to a submission that did not prove itself delete keep no peers',
        'wl_probe_max_batch'        => 'magnet probe verify check how many submissions at once per submission batch parallel concurrency',
        // #section-wlupkeep
        'wl_scrape_every_hours'     => 'whitelist refresh seeders leechers schedule periodic scrape hours',
        'wl_scrape_batch'           => 'whitelist refresh batch size per janitor run',
        'wl_dead_after_days'        => 'dead torrents cleanup no seeders leechers days remove prune',
        'wl_dead_action'            => 'dead torrents action mark delete nothing cleanup',
        'wl_dead_every_days'        => 'dead torrents cleanup how often run days',
        // #section-schedule
        'tracker_schedule_enabled'  => 'schedule automatic mode switching open hours timetable cron',
        'tracker_schedule_tz'       => 'timezone schedule iana europe warsaw utc clock',
        'tracker_mode_switch_cmd'   => 'command script sudo switch mode systemd shell hook',
        'tracker_schedule'          => 'weekly plan open hours mon tue wed thu fri sat sun times',

        // ── OpenTracker service ──
        // #section-service
        'opentracker_service_name'  => 'systemd unit service name restart reload daemon',
        'opentracker_restart_use_sudo' => 'sudo privileges restart service systemctl permission',
        'opentracker_auto_reload'   => 'automatic reload sighup after whitelist change',
        'tracker_blacklist_warn_count' => 'blacklist size warning threshold blocked hashes',
        'tracker_blacklist_danger_count' => 'blacklist size danger threshold blocked hashes',
        'tracker_uptime_warn_days'  => 'uptime warning threshold days status card',
        'tracker_uptime_danger_days' => 'uptime danger threshold days status card restart reminder',
        // #section-livesync
        'livesync_cmd'              => 'livesync helper command sudo root script peer sync',
        'livesync_enabled'          => 'livesync live peer sync second machine wireguard tunnel e7',
        'livesync_port'             => 'livesync udp port sync peers',
        'livesync_bind_ip'          => 'livesync bind address tunnel this machine wireguard ip',
        'livesync_peer_ip'          => 'livesync peer address other tracker tunnel ip',
        // #section-ot-perf
        'ot_perf_cmd'               => 'opentracker performance helper command sudo instance script',
        'ot_nice'                   => 'opentracker nice priority scheduler cpu drop-in systemd',
        'ot_cpu_weight'             => 'opentracker cpu weight share cgroup priority systemd drop-in',
        'ot_cpu_affinity'           => 'opentracker cpu affinity cores pinning taskset systemd drop-in',
        'ot_limit_nofile'           => 'opentracker open file limit descriptors nofile ulimit systemd',
        'ot_udp_workers'            => 'opentracker udp workers threads listen performance restart',
        // #section-cluster
        'ot_cluster_cmd'            => 'opentracker instances cluster multiple extra second instance helper command scale cores ports',
        'ot_cluster_enabled'        => 'opentracker instances cluster enable multiple extra second instance scale saturated workers',
        'ot_cluster_port_base'      => 'opentracker instance port base first extra port udp tcp allocation',

        // ── Network & limits ──
        // #section-netlimit
        'net_monitor_enabled'       => 'udp traffic monitor packets per second pps counters measure record firewall nftables graph chart bandwidth flood',
        'net_sample_seconds'        => 'sample interval seconds resolution pps recording granularity udp traffic',
        'net_keep_days'             => 'udp traffic samples retention days keep history prune pps',
        'net_limit_port'            => 'tracker udp port 6969 announce which port is limited',
        'net_limit_trusted'         => 'trusted ip addresses whitelist exempt bypass udp rate limit never dropped allow list cidr',
        'net_limit_blocked'         => 'blocked addresses always dropped deny ban manual beats allow list exception inside a country whitelist',
        'net_limit_enabled'         => 'udp rate limit throttle dlawik cap flood ddos drop packets nftables firewall ingress inbound on off',
        'net_limit_pps'             => 'packets per second pps budget threshold limit rate udp throttle cap drop swarm flood',
        'net_limit_burst'           => 'burst packets allowance spike tolerance rate limiter token bucket',
        'net_limit_cmd'             => 'helper script sudo root nft nftables command tracker-netlimit path privileged',
        'net_auto_enabled'          => 'automatic adaptive limit self tuning auto adjust throttle hysteresis',
        'net_auto_target'           => 'automatic mode target packets per second goal setpoint how much traffic to accept',
        'net_auto_min'              => 'automatic mode lower bound floor minimum pps band',
        'net_auto_max'              => 'automatic mode upper bound ceiling maximum pps band',
        'net_auto_target_cpu'       => 'automatic mode cpu load per core percentage guard overload tighten',
        // #section-iplists
        'net_lists_enabled'         => 'address lists whitelist blacklist allow block countries zone file url import ipdeny firewall sets master switch',
        'net_lists_ttl_default'     => 'address list cache refresh hours how often downloaded url list re-fetched country zone',
        // #section-tuner
        'tuner_enabled'             => 'stability probe tuner test limits ramp benchmark find maximum pps autotune experiment',
        'tuner_python'              => 'stability probe python interpreter path tuner command',
        'tuner_load_headroom'       => 'stability probe load headroom rise allowed per core stop ceiling',
        'tuner_load_hard'           => 'stability probe hard stop load per core absolute ceiling',
        // #section-sysctl
        'sysctl_cmd'                => 'sysctl kernel network buffers helper command rmem wmem udp_mem netdev_max_backlog socket receive buffer packet drops queue full pages backlog tuning',
        'sysctl_enabled'            => 'sysctl kernel buffers enable rmem_max rmem_default wmem_max wmem_default udp_rmem_min udp_wmem_min udp_mem netdev_max_backlog socket drops tuning',
        'sysctl_confirm_seconds'    => 'sysctl confirm window watchdog automatic revert undo countdown safety kernel buffers',
        // #section-dbmem
        'dbmem_cmd'                 => 'database mariadb mysql memory ram buffer pool innodb helper command drop-in restart max_connections tmp_table_size',
        'dbmem_enabled'             => 'database mariadb mysql memory ram buffer pool innodb enable card traffic page',

        // ── Statistics ──
        // #section-stats
        'tracker_stats_enabled'     => 'statistics page numbers swarm live counters on off',
        'tracker_stats_url'         => 'stats source xml opentracker mode everything endpoint',
        'tracker_stats_interval'    => 'refresh seconds browser poll live update frequency',
        'tracker_stats_page_interval' => 'stats page refresh seconds poll frequency',
        'tracker_stats_cache_ttl'   => 'server cache seconds shared upstream fetch throttle',
        'tracker_stats_show_home'   => 'home page widget statistics show hide front page',
        'tracker_stats_peer_label_style' => 'peers label percent share style display',
        'tracker_stats_livesync_mode' => 'live sync countdown refresh alignment browser',
        'tracker_stats_timeout'     => 'fetch timeout seconds upstream stats slow',
        'tracker_stats_min_loading' => 'loading animation minimum duration spinner',
        'tracker_stats_max_loading' => 'loading animation maximum duration spinner give up',
        // #section-timeline
        'stats_timeline_enabled'    => 'timeline chart history graph samples recording on off',
        'stats_timeline_interval'   => 'sample every seconds resolution granularity recording',
        'stats_timeline_raw_days'   => 'raw samples retention days keep detailed history',
        'stats_timeline_keep_days'  => 'roll-up retention days 5 minute buckets keep history',
        'stats_timeline_public'     => 'public chart visitors admins only visibility timeline',
        'stats_timeline_default_range' => 'default range opens first view 24h all preselected period',
        'stats_timeline_ranges'     => 'range buttons 24h 7d 2w 1m 3m all which buttons offered zoom periods',
        'stats_timeline_custom_range' => 'custom span slider free range arbitrary period continuous',

        // ── Descriptions & ratings ──
        // #section-content
        'wl_allow_source_url'       => 'whitelist submit source link url page where torrent came from',
        'wl_allow_description'      => 'whitelist submit description text markdown bbcode about torrent',
        'wl_content_review'         => 'moderate review queue approve reject description link before public',
        'wl_content_autopublish'    => 'always publish description without approval skip review',
        'wl_edit_max_pending'       => 'overwrite proposals pending limit per torrent edit description',
        'link_trusted_domains'      => 'trusted domains skip leaving site warning modal external link',
        'desc_allow_bbcode'         => 'description format bbcode tags code quote allowed',
        'desc_allow_markdown'       => 'description format markdown allowed',
        'desc_max_chars'            => 'description length limit characters maximum',
        'desc_max_images'           => 'description images limit how many img tags maximum',
        'desc_max_links'            => 'description links limit how many url maximum',
        'search_allow_sl_refresh'   => 'public search info modal refresh seeders leechers scrape on demand',
        'search_sl_refresh_seconds' => 'public search refresh seeders cooldown rate limit seconds',
        // #section-reputation
        'rep_enabled'               => 'reputation rating vote up down score percent thumbs',
        'rep_mode'                  => 'rating mode stars thumbs up down five star half star ten point',
        'rep_who_can_vote'          => 'who can rate vote anonymous logged in users only off',
        'rep_show_in_results'       => 'show rating score on search results list column',
        'rep_min_votes'             => 'minimum votes before showing a score percent threshold',
        'rep_anon_weight'           => 'anonymous vote weight versus account rating',
        'rep_rate_per_hour'         => 'rating votes per hour limit abuse bot',
        'captcha_pts_vote'          => 'captcha points added by a rating vote',

        // ── Shoutbox ──
        // #section-shout
        'shout_enabled'             => 'shoutbox shout chat chatbox room talk live wall tagboard cbox czat wolacz gadanie master switch',
        'shout_placement'           => 'shoutbox where home page front page widget own page both placement block',
        'shout_order'               => 'shoutbox order newest first top bottom chat oldest direction sort kolejnosc najnowsze na gorze',
        'shout_format'              => 'shoutbox format bbcode markdown plain text markup formatting default',
        'shout_live_seconds'        => 'shoutbox live refresh poll seconds new lines realtime updates cadence',
        'shout_widget_rows'         => 'shoutbox widget rows how many lines shown home block history',
        'shout_page_rows'           => 'shoutbox page rows how many lines shown own page history',
        'shout_max_chars'           => 'shoutbox length limit characters maximum one line shout',
        'shout_flood_seconds'       => 'shoutbox flood interval seconds between shouts spam cooldown same person',
        'shout_edit_minutes'        => 'shoutbox edit correct fix typo own line window minutes how long change mistake edytuj poprawka popraw wpis',
        'shout_delete_own_minutes'  => 'shoutbox delete remove take back own line window minutes how long limit usun wycofaj wpis',
        'shout_keep_rows'           => 'shoutbox retention rows keep how many lines prune janitor history',
        'shout_keep_days'           => 'shoutbox retention days keep age prune janitor old lines history',
        'shout_rules'               => 'shoutbox rules notice line above the box house rules regulamin',
        'shout_nav'                 => 'shoutbox navigation link menu counter badge unread number beside account nav',
        'shout_live_seconds_guest'  => 'shoutbox guest visitor anonymous refresh poll seconds cadence not logged in cheaper',
        'shout_system_lines'        => 'shoutbox system lines announcements automatic site says registered torrent whitelist bot notices',
        'shout_page_action'         => 'shoutbox address action name url link route chat czat adres nazwa strony rename where it lives',
        'shout_emotes_enabled'      => 'shoutbox emotes emoticons smileys custom images pictures svg png gif webp emotki obrazki master switch',
        'shout_stickers_enabled'    => 'shoutbox stickers big emote whole message naklejki large image sticker',
        'shout_emoji_fa'            => 'shoutbox emoji picker font awesome pro faces smileys icons mixed instead of ordinary emotikony buzki twarze mieszane',
        'shout_emoji_fa_style'      => 'shoutbox emoji font awesome faces style family duotone sharp light thin solid default styl rodzina',
        'shout_emote_approval'      => 'shoutbox emote approval approve waiting queue moderation review member upload hold pending zatwierdzanie kolejka',
        'shout_emote_max_kb'        => 'shoutbox emote size limit kilobytes kb upload maximum picture file',
        'shout_emote_max_px'        => 'shoutbox emote pixels width height dimensions limit maximum picture size',
        'shout_emote_per_user'      => 'shoutbox emote per user cap how many uploads each member limit',

        // ── Sounds ──
        // #section-sounds
        'sounds_enabled'            => 'sounds sound audio chime notification noise play mute alert ping wake pre-roll',
        'sound_default_notification' => 'sound default notification chime audio play alert',
        'sound_default_message_friend' => 'sound default message friend chime audio play ping inbox',
        'sound_default_message'     => 'sound default message chime audio play ping inbox stranger',
        'sound_default_shout_friend' => 'sound default shout shoutbox friend chime audio play',
        'sound_default_shout'       => 'sound default shout shoutbox chime audio play stranger',
        'sound_default_mention'     => 'sound default mention @ shoutbox chime audio play',

        // ── Index ──
        // #section-index
        'index_enabled'             => 'observed hashes catalogue index on off collect',
        'index_source_url'          => 'full scrape url source endpoint opentracker',
        'index_poll_minutes'        => 'poll interval minutes full scrape frequency janitor',
        'index_min_seeders'         => 'minimum seeders keep threshold prune noise',
        'index_max_rows'            => 'maximum rows cap size database growth limit',
        'index_poll_budget'         => 'seconds per poll run time budget truncated resume',
        'index_grace_days'          => 'grace days before pruning unseen hashes',
        'index_protect_days'        => 'protect new rows days from pruning',
        'index_keep_saved'          => 'keep favourites lists protect prune janitor never delete saved starred',
        'index_keep_saved_days'     => 'keep favourites extra days grace protection saved starred lists',
        'index_meta_daily_budget'   => 'metadata fetches per day budget worker limit',
        'index_meta_auto_queue'     => 'automatic metadata queue names resolve background',
        'meta_worker_concurrency'   => 'worker parallel fetches threads concurrency metadata speed',
        // #section-fetch-order
        'meta_order_mode'           => 'fetch order queue priority newest oldest seeders seen completed random mix balance whitelist registered metadata worker which first',
        'meta_order_mix_whitelist'  => 'fetch order mix share whitelist registered submitted first priority percent',
        'meta_order_mix_seeders'    => 'fetch order mix share seeders popular biggest swarm percent',
        'meta_order_mix_newest'     => 'fetch order mix share newest recent percent',
        'meta_order_mix_seen'       => 'fetch order mix share seen count most often persistent percent',
        'meta_order_mix_completed'  => 'fetch order mix share completed downloads popular all time percent',
        'meta_order_mix_random'     => 'fetch order mix share random sample percent',
        'meta_order_mix_oldest'     => 'fetch order mix share oldest longest waiting percent',
        // #section-index-files
        'index_keep_files'          => 'store file lists names torrent contents disk space',
        'meta_max_files'            => 'stored files per torrent cap max_files file list length paths truncated 5000 how many files stored',
        'index_poll_keep_days'      => 'scrape coverage chart history how long poll results kept retention delivered entries per poll',
        // #section-filelist
        'index_files_mode'          => 'file list loading mode public search infinite scroll lazy load button load more batches doładowywanie przewijanie przycisk',
        'index_files_batch'         => 'files per batch public search how many at once page size 2000 chunk slice load more',
        'index_files_max'           => 'maximum files loaded public search total ceiling stop cap how many files at most limit',
        'index_files_admin_mode'    => 'file list loading mode admin panel modal details scroll button load all batches',
        'index_files_admin_batch'   => 'files per batch admin panel modal details how many at once 5000 chunk slice',
        'index_files_admin_max'     => 'maximum files loaded admin panel modal load whole file list total ceiling million',

        // ── API & federation ──
        // #section-api
        'api_enabled'               => 'server to server api endpoints clients integration on off',
        'api_ban_days'              => 'api ban duration days abuse blocked clients',
        'api_ban_exempt_ips'        => 'api ban whitelist exempt addresses never ban own server',
        'api_rate_limit_per_min'    => 'api rate limit requests per minute throttle server to server bearer key 429 too many',
        'api_rate_limit_bytes_day'  => 'api rate limit bytes per day budget transfer quota bandwidth federation export',
        // #section-federation
        'fed_enabled'               => 'federation cluster partners sharing network on off',
        'fed_node_name'             => 'federation node identity name peers display',
        'fed_export_enabled'        => 'federation export share our whitelist outgoing',
        'fed_export_files'          => 'federation export file lists metadata included',
        'fed_export_max_batch'      => 'federation export batch size rows per request',
        'fed_export_max_bytes'      => 'federation export page byte budget size limit streaming ndjson memory',
        'fed_import_batch_rows'     => 'federation import micro batch rows per transaction memory streaming',
        'fed_import_batch_bytes'    => 'federation import micro batch bytes memory ceiling ram streaming',
        'fed_import_max_seconds'    => 'federation import time budget per pass worker timer seconds',
        'fed_worker_mem_mb'         => 'federation import worker memory limit rlimit ram megabytes hard cap',
        'fed_export_max_files'      => 'federation export page file records budget limit streaming ndjson memory',
        'fed_import_new'            => 'federation import add new hashes from peers incoming',
        'fed_import_mode'           => 'federation import mode review quarantine trust approve accept reject moderate incoming metadata',
        'fed_pull_minutes'          => 'federation pull interval minutes sync frequency',

        // ── Backups & maintenance ──
        // #section-backups
        'backup_enabled'            => 'backup backups on off master switch archive dump kopia zapasowa disaster recovery scheduled',
        'backup_dir'                => 'backup directory path where archives are stored disk destination folder /var/backups',
        'backup_db_name'            => 'backup database name tracker schema which database is dumped restored',
        'backup_profile'            => 'backup profile what is included light full database only index tables preset',
        'backup_items'              => 'backup custom items selection pieces which parts config lists units firewall',
        'backup_schedule'           => 'backup schedule automatic when weekly days time cron timer nightly',
        'backup_schedule_tz'        => 'backup timezone iana clock europe warsaw utc schedule',
        'backup_nice'               => 'backup nice ionice priority load io slow down during dump',
        'backup_verify_after'       => 'backup verify checksum sha256 integrity after run check corrupt',
        'backup_keep'               => 'backup rotation how many archives keep count retention prune delete old',
        'backup_keep_days'          => 'backup retention age days old delete prune rotation',
        'backup_max_size_gb'        => 'backup total size cap disk quota gigabytes rotation full disk',
        'backup_gpg_recipient'      => 'backup encryption gpg pgp key recipient encrypt secure off site passwords',
        'backup_cmd'                => 'backup helper script sudo root command tracker-backup path privileged',
        'backup_script_path'        => 'backup-serwera.sh toolkit path server backup script location',
        // #section-public-pages
        'auto_archive_days'         => 'reports archive cleanup housekeeping retention old',
        'auto_archive_appeal_days'  => 'appeals archive cleanup housekeeping retention old',
        'sent_emails_retention_days' => 'email log prune delete history sent mail retention gdpr',
        // #section-health
        'health_token'              => 'health check endpoint token uptime kuma monitoring json status probe',
        // #section-audit
        'audit_enabled'             => 'audit log history who did what actions record trail accountability moderator admin',
        'audit_keep_days'           => 'audit log retention keep days history how long prune delete old entries',

        // ── Languages ──
        // #section-languages
        'default_language'          => 'language default site locale automatic browser accept-language',
        'language_auto'             => 'language automatic browser accept-language detect detection',
        'lang_swap_enabled'         => 'language switcher instant in place no reload jump scroll position swap live',

        // ── Admin credentials ──
        // #section-credentials
        'admin_username'            => 'admin login name panel user rename',
        'current_password'          => 'current password confirm verify identity',
        'admin_email'               => 'admin account email address own mailbox notices verification password reset two-step change confirm',
        'new_password'              => 'change admin password new set strong',
        'confirm_password'          => 'repeat new password confirmation match',
        // #section-2fa
        'admin_2fa_enabled'         => 'two factor authentication 2fa totp authenticator app google authy aegis one time code recovery codes admin login security mfa',
    ];
}

/** Catalogue payload for api/admin/settings_catalog.php (and any future consumer). */
/**
 * A group's title in the visitor's language, or the English one when no language is loaded (CLI,
 * tests): the dictionary key is `settings.group_<id>` and the catalogue itself stays English so the
 * keyword index and the tests keep one source of truth.
 */
function settingsGroupTitle(array $g): string {
    $k = 'settings.group_' . $g['id'];
    return (function_exists('langHas') && langHas($k)) ? __($k) : (string)$g['title'];
}

function settingsCatalogPayload(): array {
    // The page gets translated titles; the English title joins the keywords so a search in either
    // language still finds the group.
    $groups = [];
    foreach (settingsCatalogGroups() as $g) {
        $g['keywords'] = strtolower((string)$g['title']) . ' ' . ($g['keywords'] ?? '');
        $g['title'] = settingsGroupTitle($g);
        $groups[] = $g;
    }
    return ['success' => true, 'groups' => $groups, 'keywords' => settingsCatalogKeywords()];
}
