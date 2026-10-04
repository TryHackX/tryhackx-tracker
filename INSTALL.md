# Installing on a Linux server, from nothing to a running tracker

This is the whole path: a bare Debian box to a working tracker with the panel, the whitelist, the
metadata worker, the firewall limits and the backups. It is written from a machine that is actually
running it — Debian 13, PHP 8.4/8.5, MariaDB 11.8, Apache 2.4 with php-fpm, nftables 1.1, Python 3.13
— and every command here is one that was run there. It is current for **version 1.73.0**.

The README covers what each feature *is* and which setting switches it on. This covers the order to
build it in, and the handful of things that will waste an afternoon if you meet them without warning.
Those are marked **⚠**.

**You do not need all of it.** Sections 1–8 give you a working tracker and panel. Everything after
that is optional and each part says what it buys you.

---

## 0. What you are building

```
                          ┌──────────────────────────────────────────────┐
   BitTorrent clients ───▶│ opentracker        UDP/TCP 6969              │
                          │   reads an accesslist file, nothing else     │
                          └───────────────▲──────────────────────────────┘
                                          │ file + SIGHUP, /stats, /scrape
                          ┌───────────────┴──────────────────────────────┐
   You, a browser ───────▶│ the panel (PHP)    /var/www/<site>           │
                          │   owns the database, generates the list      │
                          └───────┬───────────────────────┬──────────────┘
                                  │ every minute          │ narrow helpers, sudo
                          ┌───────▼─────────────────┐  ┌──▼────────────────────┐
                          │ janitor (PHP, timer)    │  │ /usr/local/sbin/      │
                          │  + its slow half as     │  │   tracker-*.sh (root) │
                          │  tracker-janitor-heavy  │  └───────────────────────┘
                          └─────────────────────────┘
                          ┌─────────────────┐  ┌───────────────────────┐
                          │ metadata worker │  │ federation importer   │  both optional
                          │ (Python, DHT)   │  │ (Python, timer)       │
                          └─────────────────┘  └───────────────────────┘
```

The panel never runs anything as root itself. Where it must change the machine it calls one of the
`tracker-*.sh` helpers through a single `sudoers` line each, and every helper refuses to do anything
it was not asked for. Undoing any of it is deleting one file.

---

## 1. The machine and what it needs

Debian 12 or 13, or Ubuntu 22.04+. A 2-core VPS with 2 GB of RAM runs a small tracker comfortably;
the box these instructions come from has 6 cores and 11 GB and also runs a game server, a forum and
mail.

| Needed | Version | Why |
|---|---|---|
| PHP | **8.0 or newer** (8.4 on Debian 13) | the code uses nothing newer than 8.0; `install.php` checks `>= 8.0` |
| PHP extensions, required | `pdo_mysql`, `mbstring`, `curl`, `gd` **with WebP**, `xml` (SimpleXML), `json`, `zlib`, `ctype` | `curl`: the index's full scrape and the address lists; `gd`+WebP: pictures and profile covers (a request that meets a picture without it is an error, not a fallback); SimpleXML: the tracker statistics |
| PHP extensions, optional | `zip` (Font Awesome package import), `exif` (turns uploaded photos upright), `intl`, `apcu` (a shared settings cache) | |
| PHP functions | `exec`, `proc_open`, `shell_exec` must **not** be in `disable_functions` | every root helper, the backups and the probe are started with them |
| Database | **MariaDB 10.6+** (tested on 11.4 and 11.8) | MySQL is not supported: a fresh install fails on it (`TEXT DEFAULT ''`), and the site sets MariaDB's `max_statement_time` |
| Web server | Apache 2.4 with `mod_rewrite` (required), `mod_headers`, `mod_setenvif`, `mod_proxy_fcgi` + php-fpm — or nginx (README → *Reverse proxy / Nginx notes*) | |
| Mail | a local MTA (`postfix`, `msmtp-mta`, `nullmailer`…) | mail leaves through PHP's `mail()` with `-f<From>`; without an MTA nothing is sent |
| Python | 3.7+ with `python3-libtorrent` (2.x) and `python3-pymysql` | only for the metadata worker and the federation importer |
| Tools the helpers call | `nft`, `systemd-run`, `systemctl`, `ss`, `ip`, `mariadb-dump`/`mariadb`, `gzip`, `tar`, `sha256sum`; optional `gpg`, `wg`, `tc` | and `php`/`python3` on root's PATH |

```bash
sudo apt update
sudo apt install -y apache2 mariadb-server mariadb-client sudo \
  php-cli php-fpm php-mysql php-mbstring php-curl php-gd php-xml php-zip php-intl php-apcu \
  postfix git curl unzip nftables python3-libtorrent python3-pymysql
php -v && php -m | grep -Ei 'pdo_mysql|mbstring|curl|gd|simplexml|zip|intl|exif'
php -r 'var_dump(gd_info()["WebP Support"] ?? false);'     # must print bool(true)
```

Do not install the `php` metapackage or `libapache2-mod-php`: the site runs under php-fpm, and the
Apache module would take `mpm_prefork` with it.

⚠ **`php-fpm` and `php-cli` can be different builds with different extension sets.** The panel runs
under both — the web pages under fpm, the janitor under cli — so an extension present in one and
missing from the other produces a feature that works in the browser and silently does nothing on the
timer. `php -m` and your fpm pool's `php.ini` should agree.

---

## 2. PHP settings

Set these in **both** `/etc/php/8.4/fpm/php.ini` and `/etc/php/8.4/cli/php.ini`, then
`sudo systemctl restart php8.4-fpm`:

```ini
upload_max_filesize = 20M     ; a picture may be up to avatar_max_kb (8 192 KB, at most 20 480); a Font Awesome package zip
post_max_size = 24M           ; must be larger than upload_max_filesize
memory_limit = 256M           ; decoding a picture takes width × height × 5 bytes + 24 MB — the 24 MP default is ~144 MB
max_execution_time = 60       ; the catalogue search sets its own budget (search_time_budget, 10–300 s)
date.timezone = Europe/Warsaw ; the SAME zone in both files — config/database.php sets the database session to PHP's offset
display_errors = Off          ; one byte of output before the session starts and nobody can sign in
log_errors = On
error_log = /var/log/php/tracker.log   ; create the directory, owned by www-data — "the reason is in the PHP error log" needs one
```

In the fpm pool (`/etc/php/8.4/fpm/pool.d/www.conf`): `request_terminate_timeout = 0`, and if you
raise *Settings → Index → poll budget*, give Apache a `Timeout` / `ProxyTimeout` at least as long.
The panel checks the effective upload limit and says when PHP's limits are smaller than its own.

---

## 3. The database

```bash
sudo mariadb -e "CREATE DATABASE tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mariadb -e "CREATE USER 'tracker'@'localhost' IDENTIFIED BY 'CHANGE-THIS';"
sudo mariadb -e "GRANT ALL PRIVILEGES ON tracker.* TO 'tracker'@'localhost'; FLUSH PRIVILEGES;"
```

`ALL ON tracker.*` is all the application needs. The backup and database-memory helpers use root's
socket authentication of their own.

⚠ **If this MariaDB is shared with anything else — mail, a forum, files — remember that a restart
takes all of them down together**, and that a full scan of the tracker's biggest table will flush the
InnoDB buffer pool the others are using. The index table on the reference machine is 2.8 GB. If you
share, give the pool room:

```ini
# /etc/mysql/mariadb.conf.d/60-tracker-innodb.cnf
[mysqld]
innodb_buffer_pool_size = 512M
# only if `mariadb -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size_max'"` returns a row
# (MariaDB 10.11.12 / 11.4.6 / 11.8.2 and later) — an unknown variable stops the server from starting:
innodb_buffer_pool_size_max = 2G
```

*Network & limits → Database memory* can do this for you later (section 12).

---

## 4. The files and the web server

```bash
sudo git clone https://github.com/TryHackX/tryhackx-tracker.git /var/www/tracker.example.org
cd /var/www/tracker.example.org
sudo chown -R www-data:www-data config/ lang/
sudo chmod 775 config/ lang/
```

The code itself can stay owned by root and read-only to the web user. Two directories must be
writable: `config/` (the database credentials, the admin password hash, every state file, cache and
lock, the installed icon packages under `config/iconpacks/`) and `lang/` (a language installed from
Settings → Languages is written there as `lang/<code>.php`). Pictures, covers, emotes and uploaded
sounds are kept in the database, not on disk.

**The subfolder line.** `.htaccess` ships with `RewriteBase /tracker/`. At a domain root change it to:

```apache
RewriteBase /
```

— otherwise every address that is not a real file answers 404. (Edited, it makes `git pull` refuse;
see *Upgrading*.)

Apache, talking to php-fpm, with `AllowOverride All` so the shipped `.htaccess` is read:

```bash
sudo a2dismod php8.4 mpm_prefork 2>/dev/null
sudo a2enmod mpm_event proxy_fcgi setenvif rewrite headers ssl
sudo a2enconf php8.4-fpm
```

```apache
<VirtualHost *:443>
    ServerName tracker.example.org
    DocumentRoot /var/www/tracker.example.org
    <Directory /var/www/tracker.example.org>
        AllowOverride All
        Require all granted
    </Directory>
    # .htaccess keeps dotfiles, *.sql|log|bak|old|ini|sh|env|lock, api/, config/, includes/ and
    # templates/ away from the web — but not these, so the vhost does:
    RedirectMatch 404 ^/(\.git|tests|worker|scratchpad)(/|$)
    # TLS lines from certbot go here
</VirtualHost>
```

```bash
sudo systemctl restart apache2
```

`mod_rewrite` is required — `.htaccess` starts with `RewriteEngine On` and answers every request
with a 500 without it. `mod_setenvif` passes the `Authorization` header through to php-fpm, which the
partner API needs.

⚠ **On Apache the `.htaccess` Content-Security-Policy is ENFORCED.** The application builds its own
policy per request (`includes/csp.php`, with a per-request nonce, no `'unsafe-inline'` scripts) and
ships it in **report-only** mode — Settings → *Security & CAPTCHA → Content-Security-Policy* is where
you read the violations and switch it to enforce. But `.htaccess` also sends a fallback,
`Header setifempty Content-Security-Policy …`, and under php-fpm Apache does not see the header PHP
sent, so the fallback is added to every page: in report-only mode it is the policy the browser
actually enforces, and in enforce mode the browser enforces the intersection of the two. It allows
`'self'`, jsDelivr, the CAPTCHA providers and images from `https:`/`data:` — **not `blob:`**, which is
why the picture editor reads files as `data:` URLs. If you add a third-party host, add it both to
*Content-Security-Policy → extra allowed hosts* and to that line. On nginx `.htaccess` is not read:
do **not** add a `Content-Security-Policy` of your own to the vhost there either.

**HSTS is deliberately NOT in `.htaccess`.** `Strict-Transport-Security` pins a hostname inside
other people's browsers for as long as its `max-age`, and a header set in a file the panel cannot
read is one the panel cannot switch off again. It lives in *Settings → Security & CAPTCHA → Transport
security (cookies & HSTS)*, it is **off** until you switch it on, and it is never sent over a
plain-HTTP request. Read the README section before enabling it: turning it back off does not release
browsers that already hold the pin. The same section decides the `Secure` flag on the session,
language and remember-me cookies (*auto* = when the request is HTTPS).

---

## 5. opentracker

The panel does not include a tracker; it drives one — and the package ships the two builds it is
developed against, so this step can be four commands or a full build from source. Either way,
**opentracker's accesslist mode is chosen at compile time**, which is the single most surprising
thing about it, and the reason there are two binaries.

```bash
sudo useradd -r -m -d /home/tracker -s /usr/sbin/nologin tracker
sudo chmod 0755 /home/tracker          # the panel reads the worker's heartbeat under it
sudo install -d -o tracker -g www-data -m 2770 /home/tracker/accesslist
sudo install -o tracker -g www-data -m 0664 /dev/null /home/tracker/accesslist/whitelist
sudo install -o tracker -g www-data -m 0664 /dev/null /home/tracker/accesslist/blacklist
```

The two list files must be writable by the web user (group `www-data`, mode 0664): the panel writes
the whitelist and appends banned hashes to the blacklist.

### Either: use the builds in this package

```bash
cd /var/www/tracker.example.org/tools/opentracker/bin
sha256sum -c <<'SUMS'
d1a319cd999812a98c4fa2d6fedfee7a8a259b1d0ca0816d58bf2dc5f264fe8d  opentracker.white
ef5e162e7cba1c3fd73b72c04f267c2699e5975d433c154699bc89cdc49da3cf  opentracker.black
SUMS
sudo install -o tracker -g tracker -m 0755 opentracker.white /home/tracker/opentracker.white
sudo install -o tracker -g tracker -m 0755 opentracker.black /home/tracker/opentracker.black
```

x86-64, dynamically linked against `libz` and `libc` only (glibc 2.34+: Debian 12/13, Ubuntu 22.04+),
built with all four patches below.

### Or: build them yourself

The full recipe — upstream commit, the four patch files, the feature flags and what each one is for —
is in **[tools/opentracker/README.md](tools/opentracker/README.md)**. In short:

```bash
sudo apt install -y build-essential git zlib1g-dev wget xz-utils
cd /usr/local/src
P=/var/www/tracker.example.org/tools/opentracker
sudo wget http://www.fefe.de/libowfat/libowfat-0.34.tar.xz && sudo tar -xf libowfat-0.34.tar.xz && sudo mv libowfat-0.34 libowfat
(cd libowfat && sudo patch -p1 --forward < $P/libowfat-no-zerocopy.patch)   # else chunked /scrape corrupts
sudo make -C libowfat
sudo git clone git://erdgeist.org/opentracker && cd opentracker
sudo git checkout 1c7fac4cc23801ac81a2abd7d3110683831c4811

sudo patch -p1 --forward < $P/sighup-udp-workers.patch     # else `systemctl reload` KILLS the tracker
sudo patch -p1 --forward < $P/udp-reject-interval.patch    # else rejected clients retry for ever
sudo patch -p1 --forward < $P/opentracker-review-fixes.patch  # seven review fixes (connid secret, accesslist reload, ...)

F="-DWANT_FULLSCRAPE -DWANT_COMPRESSION_GZIP -DWANT_RESTRICT_STATS -DWANT_MODEST_FULLSCRAPES"
O=/usr/local/src/libowfat
sudo make clean && sudo make FEATURES="$F -DWANT_ACCESSLIST_BLACK" LIBOWFAT_HEADERS=$O LIBOWFAT_LIBRARY=$O
sudo install -o tracker -g tracker -m 0755 opentracker /home/tracker/opentracker.black
sudo make clean && sudo make FEATURES="$F -DWANT_ACCESSLIST_WHITE" LIBOWFAT_HEADERS=$O LIBOWFAT_LIBRARY=$O
sudo install -o tracker -g tracker -m 0755 opentracker /home/tracker/opentracker.white
```

⚠ `make clean` between the two is **not** optional: the object files carry the accesslist flag, and
without it the second build silently keeps the first one's mode.

⚠ The libowfat patch is not optional either. Unpatched, `iob_send()` sends with `MSG_ZEROCOPY` and
frees the buffers before the kernel has read them; the panel's full-scrape polls then arrive with
heap pointers in the chunk headers and fail as "truncated". Details and the proof:
`tools/opentracker/UPSTREAM-REPORT.md`.

⚠ Do not drop `-DWANT_RESTRICT_STATS`. Without it `/stats` — the whole torrent list, with counts —
is served to anyone who asks, on a path they can guess. With it, `access.stats` limits it to named
addresses and `access.stats_path` moves it somewhere unguessable. Both are used below.

⚠ **`-h` and `/stats` lie about what is compiled in.** opentracker prints one fixed usage text
regardless of its build flags, and `/stats` shows a `<livesync>` section on a binary with no livesync
at all. The only way to know what a binary supports is to *run* it and see whether it accepts the
flag. The panel does exactly this rather than reading the help text.

Two config files, one per mode:

```bash
# /home/tracker/opentracker.conf.white
listen.udp.workers 4
access.whitelist /home/tracker/accesslist/whitelist
access.stats 127.0.0.1
access.stats_path pick-something-unguessable
tracker.redirect_url https://tracker.example.org/?action=whitelist
access.udp_reject_interval 86400

# /home/tracker/opentracker.conf.black   — identical but:
access.blacklist /home/tracker/accesslist/blacklist
```

`access.stats 127.0.0.1`: the panel fetches `/stats` from this machine itself, so the loopback
address is the one to allow (add your own only if you want to read it from outside).
`access.udp_reject_interval` comes from the second patch and is the single biggest traffic saving on
a whitelist tracker: a UDP announce for a hash the accesslist rejects gets a well-formed "0 peers,
come back in 86 400 s" reply instead of the 8-byte packet clients treat as a broken tracker and retry
for ever. With no `listen.*` line the tracker is dual-stack (IPv4 and IPv6) on port 6969.

Symlinks pick which pair is live, and the systemd unit never changes:

```bash
sudo ln -sf opentracker.black      /home/tracker/opentracker
sudo ln -sf opentracker.conf.black /home/tracker/opentracker.conf
```

```ini
# /etc/systemd/system/opentracker.service
[Unit]
Description=OpenTracker
After=network.target

[Service]
Type=simple
User=tracker
WorkingDirectory=/home/tracker
Restart=always
RestartSec=5s
ExecStart=/home/tracker/opentracker -f /home/tracker/opentracker.conf
ExecReload=/bin/kill -HUP $MAINPID

[Install]
WantedBy=multi-user.target
```

```ini
# /etc/systemd/system/opentracker.service.d/limits.conf
[Service]
LimitNOFILE=65536
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now opentracker
```

⚠ **Keep these names exactly.** The helpers assume the unit is `opentracker`, the home is
`/home/tracker`, `ExecStart` is exactly `/home/tracker/opentracker -f /home/tracker/opentracker.conf`,
the unit is `Type=simple` (the mode helper reads the running binary from `/proc/<MainPID>/exe`) and
`ExecReload` sends HUP. The panel adds drop-ins of its own (`90-tracker-panel.conf`,
`91-tracker-livesync.conf`) — leave them to it.

---

## 6. The web installer

Open `https://tracker.example.org/install.php` and work through the four steps:

1. **Requirements.** PHP ≥ 8.0, `pdo_mysql`, `json`, `openssl`, and `config/` writable — shown, not
   enforced, and it does not check `mbstring`, `curl` or `gd`: section 1 did.
2. **Database.** Host, database name (letters, digits, `_`), user and password; the installer creates
   the database if needed (`utf8mb4_unicode_ci`) and its base tables.
3. **The site.** The admin name (3+ characters; it is also mirrored into the member list as the root
   administrator when it is a valid username) and password (10+ characters with lower case, upper
   case, a digit and a symbol); the site name, its URL (no trailing slash) and contact address; an
   optional From address (on the site's own domain or a parent of it); the announce URLs; the
   CAPTCHA provider (reCAPTCHA v2 or v3, Turnstile or hCaptcha) and its two keys — CAPTCHA is only
   switched on when both are given; and the blacklist path — enter
   `/home/tracker/accesslist/blacklist`, the file section 5 made.
   It writes `config/hash.txt` (bcrypt), `config/database.php` (with a 3-second connect timeout and
   the session time zone), the settings, and then builds the schema by running the ordinary
   migration from version zero — the same path an upgrade takes. If the last step reports that the
   schema *stopped at version N*, the install is not finished: the reason is in the PHP error log,
   and reloading the page after fixing it resumes from where it stopped. `config/installed.lock` is
   written only when the schema is complete.
4. **Done.** It offers to delete `install.php`, which silently does nothing when the code is owned by
   root — delete it yourself:

```bash
sudo rm /var/www/tracker.example.org/install.php
sudo chmod 0600 config/database.php config/hash.txt     # they are written 0644
```

Once `config/installed.lock` exists the installer refuses to run anyway. `tests/install_test.php`
builds a fresh and an upgraded database and diffs them, so the two paths cannot drift apart.

⚠ **Never run `tests/` on a production box.** The tests use `config/database.php`, and some of them
empty tables.

---

## 7. First things in the panel

Sign in at `https://tracker.example.org/?action=admin`.

- **Settings → Tracker & whitelist → Tracker mode & the accesslist file**: set **both** paths
  (`/home/tracker/accesslist/whitelist` and `…/blacklist`) and press the Test button beside each.
- **Settings → OpenTracker service → OpenTracker Service**: *Service name* `opentracker` (empty as
  installed, which disables Reload and Restart), *Run via sudo* on, *Auto-reload* on. Save, then
  press both permission tests — they need section 9's sudoers lines.
- **Settings → Statistics → Tracker Statistics**: *Stats Source URL*
  `http://127.0.0.1:6969/<access.stats_path>?mode=everything` (empty as installed), then switch the
  statistics on. The index and the whitelist upkeep scrape `http://127.0.0.1:6969/scrape` by default.
- **The tracker's mode.** The *Tracker mode* select only records what the panel should believe. The
  switch itself is on the **Whitelist** page: **Switch the tracker now** (it needs the mode helper,
  section 9). The page shows both the panel's setting and what the tracker is really running, and
  says so loudly when they differ.
- **Settings → Contact & email**: the From address and the reply address; send yourself a test.
- **Owner-specific defaults to change**: `link_trusted_domains` ships as `tryhackx.org` (Settings →
  *Descriptions, comments & ratings → Descriptions & source links*), the schedule's and the backups'
  time zones are `Europe/Warsaw`.

Everything that touches privacy or trust ships **off**: the account system, favourites and public
profiles, messages, friends, the shoutbox, the sign-in bridge, the API, address lists, HSTS and CSP
enforcement. A default that switched any of those on would be a decision taken on your behalf. The
built-in **Info** and **Terms** pages follow the switches by themselves: a feature that is off is not
described there (Settings → *Site & pages → Site pages* can replace them with your own words).

---

## 8. The janitor — one timer, and most features depend on it

```ini
# /etc/systemd/system/tracker-whitelist-janitor.service
[Unit]
Description=Tracker janitor
After=network.target mariadb.service

[Service]
Type=oneshot
User=www-data
Group=www-data
Nice=10
ExecStart=/usr/bin/php /var/www/tracker.example.org/tools/janitor.php
```

```ini
# /etc/systemd/system/tracker-whitelist-janitor.timer
[Unit]
Description=Run the tracker janitor every minute

[Timer]
OnBootSec=2min
OnUnitActiveSec=60s
AccuracySec=10s

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now tracker-whitelist-janitor.timer
```

The unit's name is yours to choose — nothing in the code looks for it — but it must run as the owner
of `config/` (the web user). **If one thing on this page must be running, it is this timer.** Every
minute it: applies the schedule's mode switch and checks the running mode; prunes the audit log and
the CSP reports; reaps or starts the stability probe; regenerates and reloads the whitelist, cleans
up after it and prunes old API bans; samples the statistics timeline and rolls it up; samples the
inbound traffic, moves the automatic limit, undoes an expired throttle; refreshes the address lists
and the firewall; keeps the opentracker, sysctl and database-memory drop-ins in step; reloads extra
instances; starts a due backup; runs the accounts' tick (expired groups, notifications, tokens); sends
the bulk mail queue; prunes the typing line, the anti-spam state, the shoutbox and the messages'
trash; sends the operator's digest; and refreshes the live-sync cache.

**The slow half.** The index's full-scrape poll and prune, the whitelist upkeep and the submission
probes run as a transient unit of their own, `tracker-janitor-heavy.service`, started through the
netlimit helper (`tracker-netlimit.sh janitor-heavy-start …/tools/janitor.php`) so a slow poll never
holds up the minute. Without that helper — or its sudoers line, or `systemd-run` — the slow half runs
inside the minute's own process, and the janitor tries (and fails) a `sudo` every minute: install the
helper (section 9) even if you never use the firewall limit.

⚠ **php-fpm runs with `ProtectSystem=full` and `ProtectKernelTunables=yes` on most distributions**,
so `/etc/` and `/proc/sys` are read-only *inside the web process even for root through sudo* — it is
a mount namespace, not a permission bit. Several features therefore work in two halves: the helper
changes the kernel immediately and reports `deferred`, and the janitor (which has no such sandbox)
writes the file a moment later. Without the timer running, those features appear to half-work.

A oneshot service **kills every process it leaves behind** when `ExecStart` returns, so nothing the
janitor starts may simply be backgrounded — that is why the slow half, the stability probe
(`tracker-probe.service`) and each backup (`tracker-backup-<stamp>.service`) run as transient units of
their own.

```bash
sudo -u www-data php /var/www/tracker.example.org/tools/janitor.php -v     # one verbose pass by hand
journalctl -u tracker-whitelist-janitor -u tracker-janitor-heavy -u tracker-probe -n 50
```

---

## 9. Root helpers

Each helper is a single script with a narrow job, allowed for the web user by one `sudoers` line.
The panel calls them as `sudo -n /usr/local/sbin/tracker-<name>.sh …` and restarts or reloads the
tracker with `sudo -n systemctl restart|reload <service name>`.

| Helper | What it does | Its panel card (field, as installed) |
|---|---|---|
| `tracker-mode.sh` | switches the symlinks white↔black and restarts; the schedule; the running-mode check | Tracker & whitelist → Scheduled tracker mode (`tracker_mode_switch_cmd`, set) |
| `tracker-netlimit.sh` | the inbound UDP limit, address lists, the egress rate, the stability probe, **the janitor's slow half** | Network & limits → UDP traffic & rate limit (`net_limit_cmd`, set) |
| `tracker-instance.sh` | opentracker's workers, open files, CPU scheduling, restart | OpenTracker service → OpenTracker — performance (`ot_perf_cmd`, set) |
| `tracker-backup.sh` | backups: run, list, verify, prune, delete, restore | Backups & maintenance → Backups (`backup_cmd`, set) |
| `tracker-sysctl.sh` | the kernel's network buffers, armed and confirmed | Network & limits → Kernel network buffers (`sysctl_cmd`, **empty**) |
| `tracker-cluster.sh` | extra opentracker instances on ports of their own | OpenTracker service → OpenTracker instances (`ot_cluster_cmd`, **empty**) |
| `tracker-dbmem.sh` | MariaDB's buffer pool, from the panel | Network & limits → Database memory (`dbmem_cmd`, **empty**) |
| `tracker-livesync.sh` | live peer sync between two machines (needs a `-DWANT_SYNC_LIVE` build — the shipped ones are not — and WireGuard) | OpenTracker service → Live peer sync (`livesync_cmd`, **empty**) |

```bash
cd /var/www/tracker.example.org
for h in mode netlimit sysctl instance backup cluster dbmem livesync; do
  sudo install -o root -g root -m 0755 tools/opentracker/tracker-$h.sh /usr/local/sbin/
  echo "www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-$h.sh" \
    | sudo tee /etc/sudoers.d/tracker-$h > /dev/null
  sudo chmod 0440 /etc/sudoers.d/tracker-$h
  sudo visudo -cf /etc/sudoers.d/tracker-$h
done

echo 'www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart opentracker, /usr/bin/systemctl reload opentracker' \
  | sudo tee /etc/sudoers.d/tracker-restart > /dev/null
sudo chmod 0440 /etc/sudoers.d/tracker-restart && sudo visudo -cf /etc/sudoers.d/tracker-restart
```

Install only the helpers you want — a card whose helper is missing says so. The unit name in the last
line must be the panel's *Service name* exactly. Never point a sudoers line into the web root: the
copies in `/usr/local/sbin` are root's, the ones in `tools/` are the web user's to read.

For the four cards whose field ships empty, type the command into it —
`sudo -n /usr/local/sbin/tracker-sysctl.sh` and so on; saving a helper's field asks for the owner's
password. Every card that uses a helper has a **Test** button. Press it before pressing Apply — the
test says what the helper can and cannot do on this machine, and why. (The hints under some Test
buttons print the reference machine's path, `/var/www/tracker.tryhackx.org`; read your own.)

---

## 10. nftables (the inbound limit and the address lists)

The netlimit helper keeps its rules in the table `inet ottrack_in` and persists them to
`/etc/nftables.d/`, which nftables must include at boot:

```bash
sudo install -d -m 0755 /etc/nftables.d
grep -q '/etc/nftables.d/' /etc/nftables.conf || echo 'include "/etc/nftables.d/*.nft"' | sudo tee -a /etc/nftables.conf
sudo systemctl enable --now nftables
```

Without the include line, a limit that works today is gone after a reboot.

**Optional — the egress budget** (`tools/opentracker/egress-budget/`): kernel sets that keep, for three
hours and in memory only, the addresses of clients that got a real answer, so replies to them go out
first when the uplink is full. Port 6969 and the 50 000/s budget are written into the file — edit them
before loading it. The panel's egress-rate control needs it.

```bash
sudo install -m 0644 tools/opentracker/egress-budget/ottrack.nft /etc/nftables.d/
sudo nft -f /etc/nftables.d/ottrack.nft
# tracker-egress-prio.sh / .service (traffic control on the uplink) are installed by hand, if at all
```

---

## 11. The metadata worker and federation (optional)

Resolves torrent names and file lists over DHT, so the whitelist and the index show something more
useful than a hash. The full guide — the database user and its column-level grants, the conf keys,
the heartbeat, the federation importer — is **[worker/README.md](worker/README.md)**. In short:

```bash
sudo install -d -o tracker -g tracker -m 0755 /home/tracker/metadata_worker
sudo install -o tracker -g tracker -m 0755 worker/worker.py worker/federation.py /home/tracker/metadata_worker/
sudo install -o root -g tracker -m 0640 worker/tracker-metadata.conf.example /etc/tracker-metadata.conf
sudoedit /etc/tracker-metadata.conf        # [db] password; replace the example tracker in trackers=
sudo install -m 0644 worker/tracker-metadata.service /etc/systemd/system/
# the grants for tracker_meta: worker/README.md
sudo systemctl daemon-reload && sudo systemctl enable --now tracker-metadata
```

The worker re-reads *Worker parallel fetches*, the file cap, the fetch order and the time zone from
the panel every minute (that needs `SELECT` on `tracker.settings` for its user) — a restart is needed
only after editing the conf file or replacing `worker.py`. Its heartbeat
(`/home/tracker/metadata_worker/heartbeat`, one JSON line) is what the Index page's status card reads;
see worker/README.md for the `UMask` drop-in that lets the panel read what it says.

Federation — pulling resolved metadata from other trackers — is `federation.py` on a timer
(`worker/tracker-federation.service` / `.timer`), switched on in *Settings → API & federation →
Federation / Cluster*; worker/README.md has its grants and options.

---

## 12. Tuning, in the order that pays

Do these in order. Each one's evidence is on **Traffic**, and the page will tell you if a step is not
worth taking on your machine.

**a) Give the tracker's socket a real receive buffer.** Traffic → Kernel network buffers → *Use
suggested* → *Apply* (then *Confirm* within two minutes, or it reverts by itself).

⚠ **A socket's receive buffer is fixed when the socket is CREATED.** Raising `rmem_default` reaches
nothing already running, so the tracker keeps the buffer it was born with until it restarts. On the
reference machine the setting sat unapplied for two days while 43.6 million packets were discarded by
a 208 KiB queue; after the restart the same counter reads 47. The card detects this and offers the
restart button.

**b) Spread packet processing across cores.** A VPS NIC usually has one receive queue, so every
inbound packet is processed by the one core its interrupt lands on — measured at 99.9% on a single
core here, while the tracker itself used 15% of the box. Everything else on the machine queues behind
that core, which is why raising the tracker's limit made a game server on the same box lose packets.

Traffic tells you when this is happening and gives the exact command. To make it survive a reboot:

```ini
# /etc/systemd/system/tracker-rps.service
[Unit]
Description=Spread packet processing across cores (RPS)
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
# 3f = six cores. Use a mask matching your core count, and your interface name.
ExecStart=/bin/sh -c 'for q in /sys/class/net/ens3/queues/rx-*; do echo 3f > "$q/rps_cpus"; done'
ExecStop=/bin/sh -c 'for q in /sys/class/net/ens3/queues/rx-*; do echo 0 > "$q/rps_cpus"; done'

[Install]
WantedBy=multi-user.target
```

**c) Only then set the firewall limits.** Traffic → UDP traffic. The suggestion is computed from your
own last seven days, and the page says what it is based on. Give the limit a burst of about 22 ms of
itself (2 000 at 90 000 packets a second): with a burst of 100 the limiter passes only about two
thirds of what it is set to, and the card says so.

**d) If the machine shares with something else, run the stability probe.** Settings → Network & limits
→ Stability probe → Enabled, then the card at the bottom of Traffic. It moves the limit through a few
steps, holds each for a few minutes, and watches the drop counters of **every other UDP socket on the
machine** — the question a formula cannot answer. It stops itself the moment a neighbour starts
dropping, always puts the settings back, and never applies anything on its own.

Kernel buffers are deliberately *not* something it ramps: a buffer is fixed at socket creation, so
testing one means restarting the tracker at every step.

**e) Database memory.** Network & limits → Database memory reads what MariaDB is doing with its buffer
pool and can size it (needs `tracker-dbmem.sh`).

---

## 13. Backups and restoring (optional)

```bash
sudo install -d -m 0700 /var/backups/tracker
```

Settings → *Backups & maintenance → Backups*: choose an absolute directory (not a system one) and a
profile, a schedule if you want one, and the retention (as installed: keep 7 archives, 30 days,
20 GB — the newest is never deleted). Then **Backups → Make a backup now**, which lets you pick a
profile for that run without touching the schedule.

What a run is: `mariadb-dump --single-transaction --routines --triggers --quick | gzip` into
`<dir>/tracker-db-<stamp>.sql.gz`, mode 0600, as a transient unit of its own. ⚠ **The default profile,
`tracker-lekki`, leaves out `index_hashes` and `index_files`** — the catalogue rebuilds itself from the
tracker; choose `tracker-pelny` or `tracker-baza` for a complete dump. Archives are unencrypted unless
you set a GPG recipient, and they hold everything else: accounts, e-mail addresses, messages, the
addresses the site keeps, two-factor secrets.

⚠ **A complete archive looks far too small.** The database on the reference machine is 3.3 GB and its
full archive is 158 MB — hex hashes and repetitive names compress about twentyfold. The built-in dump
also names every file `tracker-db-*` whatever profile made it; the panel shows what each archive
actually contains and how far it compressed.

**Restoring**, from the panel: *Backups → Restore the database*, with the owner's password and the
database name typed out. The helper first writes a `before-restore-<db>-<stamp>.sql.gz` safety dump
(never rotated — delete old ones yourself) and refuses to import without it. From the shell:

```bash
sudo /usr/local/sbin/tracker-backup.sh restore-db /var/backups/tracker <id> --db tracker --confirm tracker --dry-run
sudo /usr/local/sbin/tracker-backup.sh restore-db /var/backups/tracker <id> --db tracker --confirm tracker
```

A database backup contains no files: keep copies of `config/database.php`, `config/hash.txt`,
`config/admin_2fa.json`, `config/iconpacks/` and any `lang/*.php` you installed yourself.

---

## 14. Telling something else that the tracker is well (optional)

An uptime monitor pointed at the home page proves that Apache answers. **Settings → Backups &
maintenance → Health check** turns on `?action=health`, which answers one JSON document — schema
version, whether the panel and the running tracker agree about the mode, the state of the accesslist,
the metadata worker's heartbeat and what is waiting in the queues — and **HTTP 503** when something is
actually wrong.

Set a token of at least 16 characters (a shorter one counts as empty, and the endpoint stays off),
then point the monitor at the address the panel prints beside the field. Send the token as the
`X-Health-Token` header where your monitor allows one; a query string is written to every access log
on the way. Without the token — and with a *wrong* one — the address answers with the ordinary page,
so a probe cannot learn there is a secret to find.

---

## 15. Icon packages, groups and the other command-line tools

Run them as the web user, from the site's directory:

```bash
cd /var/www/tracker.example.org
# Font Awesome Pro (or any 6.x / 7.x package), from its zip or unpacked folder — up to 20 packages, 160 MB:
sudo -u www-data php tools/iconpack.php import /path/to/fontawesome-pro-7.3.1-web.zip
sudo -u www-data php tools/iconpack.php list
#   also: activate <id> [--styles=a,b] [--style=NAME] · styles · verify · reindex <id>|--all · delete <id>

# the groups' recommended permission sets:
sudo -u www-data php tools/groups.php diff --group=moderator
#   also: list · apply --group=<slug>|--all --mode=add|reset [--consent] [--dry-run] · user <id|name>

# the panel's second factor, when the phone is gone:
sudo -u www-data php tools/twofa_cli.php off            # or: status

# the whitelist from the shell:
sudo -u www-data php tools/whitelist_cli.php status
#   also: add [--source=admin|api|forum|web] < hashes.txt · regen [--reload] · import-blacklist · reload ·
#         mode [--apply] · timeline [--tick] · index [--tick] [--poll]
```

A package is unpacked into `config/iconpacks/<id>/` and served by `iconpack.php`. **Pro packages are
licensed to you: they never go into the repository, a fork or a release** — back them up yourself.
`tools/backfill_fetched.php --dry-run|--apply` repairs old metadata timestamps once;
`tools/tuner.py` is the stability probe's engine (the panel starts it); `tools/api_client_example.py`
is a partner's example client (`TRACKER_API_URL`, `TRACKER_API_KEY`). `tools/lang_src.py` and
`tools/emoji_data.php` are developer tools: `lang/en.php` and `lang/pl.php` are generated — do not
edit them on the server.

---

## 16. Before you call it done

- [ ] `install.php` is deleted, `config/database.php` and `config/hash.txt` are 0600.
- [ ] `.git`, `tests`, `worker` answer 404 from the web.
- [ ] CAPTCHA is on (Settings → Security & CAPTCHA), and you have tested a sign-in.
- [ ] The janitor timer is active: `systemctl list-timers | grep tracker`.
- [ ] The service name is set and Reload works; the statistics show numbers.
- [ ] Every card on Traffic that you enabled has had its **Test** pressed and is green.
- [ ] Tracker mode agrees with reality — the Whitelist page shows both the panel's setting and what
      the tracker is actually running, and says so loudly when they differ.
- [ ] A backup has been made *and verified* at least once.
- [ ] Mail arrives (a password reset to yourself).
- [ ] You have signed in once as an ordinary member and checked the public pages look right —
      permissions are per group, and the owner sees things a member does not.
- [ ] You have read the Info and Terms pages as a visitor: they describe the features you switched on.

---

## Upgrading

```bash
cd /var/www/tracker.example.org
sudo git pull --ff-only                  # `sudo git stash` first if you edited .htaccess (RewriteBase), `sudo git stash pop` after
sudo -u www-data php tools/janitor.php -v     # runs any pending migration once, immediately — deferred ones included
# the helpers and the worker are COPIES — git pull does not update them:
for h in mode netlimit sysctl instance backup cluster dbmem livesync; do
  [ -f /usr/local/sbin/tracker-$h.sh ] && sudo install -o root -g root -m 0755 tools/opentracker/tracker-$h.sh /usr/local/sbin/
done
sudo install -o tracker -g tracker -m 0755 worker/worker.py worker/federation.py /home/tracker/metadata_worker/ \
  && sudo systemctl restart tracker-metadata
```

The schema migrates itself on the first request after an upgrade, under a database lock
(`GET_LOCK('tracker_schema')`: a web request never waits for it, the CLI waits five seconds). While one
request migrates, the others keep being served on the old schema — there is no maintenance page and no
maintenance mode. Migrations that would hold a lock on the big index table for minutes are
**deferred** and run only from the CLI, and the schema version is not recorded until they have run —
which is why running the janitor by hand after an upgrade is worth the ten seconds.

⚠ **A settings-only release still bumps the schema number.** Default rows are inserted by the
migration block, and that block only runs when the version moves.

⚠ **`config/database.php` is generated once and never touched by an upgrade.** Installs made
with 1.35.0 or earlier do not have the connect timeout that stops a downed MariaDB from holding
every php-fpm child until the TCP timeout. Add this line to the options array in
`config/database.php`, next to `PDO::ATTR_EMULATE_PREPARES => false,`:

```php
                PDO::ATTR_TIMEOUT => 3,
```

With it, a database that is down answers as a `503` with `Retry-After: 60` (a small maintenance page
for the site, `{"success":false,"retry_after":60}` for the API) within three seconds instead of a
blank error after thirty.

**Things a release asked for by hand** (the CHANGELOG says why):

| From | Do |
|---|---|
| 1.50.1, 1.57.0 | reinstall `tracker-netlimit.sh` (the probe as `tracker-probe.service`, then the heavy half) |
| 1.57.0 | run the janitor from the CLI once: it builds a heavy index a web request never does |
| 1.63.0 | raise PHP's upload limits for pictures and covers (section 2) |
| 1.65.0 | review Users → Groups: the profile cover moved to the new `premium` group |
| 1.69.0 | install icon packages as the web user (section 15) |
| 1.72.0 | raise the inbound limiter's burst (section 12c) |

---

## When something looks wrong

| What you see | What it usually is | Fix |
|---|---|---|
| Blocking a hash fails | the accesslist files are 0644 | `chmod 0664`, group `www-data` (section 5) |
| No Reload / Restart buttons, "auto-reload" does nothing | *Service name* is empty | set `opentracker` + the sudoers line (sections 7, 9) |
| Stats stuck on "Syncing swarms…", empty timeline | *Stats Source URL* unset, `access.stats` lacks 127.0.0.1, or a stale `config/stats_fetch.lock` copied from another machine | section 7; delete the lock file |
| `.php` files download instead of running | `proxy_fcgi` / `a2enconf php8.4-fpm` missing | section 4 |
| Every address but the home page is 404 | `RewriteBase /tracker/` at a domain root | section 4 |
| The worker exits: "python3-pymysql is required" | the package is missing | `apt install python3-pymysql` |
| The worker ignores a setting | its user has no `SELECT` on `settings` | worker/README.md |
| The Index card shows the worker's age but not what it runs | the heartbeat is 0600 (`UMask=0077`) | the `UMask=0022` drop-in, worker/README.md |
| Picture editor: "The image could not be loaded" | a `blob:` URL under the enforced `.htaccess` policy (fixed in 1.63.1 — an old copy of the page) | reload; keep `.htaccess` current |
| Uploads refused below the setting, or "too large to decode" | PHP's `upload_max_filesize` / `post_max_size` / `memory_limit` | section 2 |
| Settings → Languages: "could not write" | `lang/` is root's | `chown www-data lang` |
| A card says "not written" | nothing has been applied yet — it is not an error | |
| Panel and tracker disagree about the mode | only the setting was saved, the mode helper is not installed, or the schedule is off | Whitelist → **Switch the tracker now**; the page says which |
| A setting changed but nothing happened | the janitor timer is not running, or the worker needs a restart | section 8 |
| Chart gaps, a failing `sudo` every minute in the log | the janitor's slow half runs inline — the netlimit helper or its sudoers line is missing | section 9 |
| The probe stops "without finishing" a second after it starts | the helper predates 1.50.1 | reinstall `tracker-netlimit.sh` |
| A limit is gone after a reboot | no include line in `/etc/nftables.conf` | section 10 |
| Buffers "applied", still dropping | not confirmed (it reverts after 120 s), or the socket predates the change | Confirm, then restart the tracker |
| The limiter passes about ⅔ of its limit | its burst is too small | burst 2 000 at 90 000 pps (section 12c) |
| MariaDB will not start after editing its config | `innodb_buffer_pool_size_max` on a MariaDB that does not know it | remove the line (section 3) |
| A bare 500 on a long search | `max_execution_time` too low and no PHP error log | section 2 |
| "Poll now" errors | the web server's timeout is shorter than the poll budget | Apache `Timeout` (section 2) |
| Signed in, the next click shows the sign-in form again | *Secure cookies: always* over plain HTTP | set `cookie_secure_mode` back to `auto` in *Transport security*, or ``UPDATE settings SET value='auto' WHERE `key`='cookie_secure_mode';`` |
| `?action=health` shows the home page | the token is shorter than 16 characters | a longer token |
| Mail never arrives | no MTA, or a From address the MTA refuses | section 1; Settings → Contact & email |
| A chart is a flat zero | the column existed before the data did — check whether the series is new | |

`journalctl -u tracker-whitelist-janitor -u tracker-janitor-heavy -n 50`, the PHP error log and the
panel's own **Log** page answer most of the rest between them.

---

## Security notes

- The admin sign-in is at `?action=admin`; *Security & CAPTCHA → Admin Access & Sessions* can move it
  to an address of your own (`admin_login_path`) and decide what a stranger on a panel address sees
  (home page, the sign-in form, or a 404). Write the new address down before saving it.
- The panel's second factor: *Admin credentials → Two-factor authentication*; its secret lives in
  `config/admin_2fa.json`, and `tools/twofa_cli.php off` is the way back in without the phone.
- The actions that change the machine, restore a backup or rewrite the site ask for the owner's
  password again; five wrong answers sign you out, and they count toward the sign-in lockout (5 failures
  in 15 minutes per address).
- Code owned by root, `config/` and `lang/` by the web user, `database.php` and `hash.txt` 0600,
  sudoers only for the copies in `/usr/local/sbin`, `.git` and `tests/` unreachable from the web.
- Behind a proxy or a CDN, set *Trusted proxy IPs* and the client-IP header (README → *Reverse proxy /
  Nginx notes*), or every visitor has the proxy's address — and the rate limits, bans and anti-spam
  treat them all as one.
