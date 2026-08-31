# Installing on a Linux server, from nothing to a running tracker

This is the whole path: a bare Debian box to a working tracker with the panel, the whitelist, the
metadata worker, the firewall limits and the backups. It is written from a machine that is actually
running it — Debian 13, PHP 8.5, MariaDB 11.8, nftables 1.1.3, Python 3.13 — and every command here
is one that was run there.

The README covers what each feature *is*. This covers the order to build it in, and the handful of
things that will waste an afternoon if you meet them without warning. Those are marked **⚠**.

**You do not need all of it.** Sections 1–5 give you a working tracker and panel. Everything after
that is optional and each part says what it buys you.

---

## 0. What you are building

```
                          ┌──────────────────────────────────────────────┐
   BitTorrent clients ───▶│ opentracker        UDP/TCP 6969              │
                          │   reads an accesslist file, nothing else     │
                          └───────────────▲──────────────────────────────┘
                                          │ file + SIGHUP
                          ┌───────────────┴──────────────────────────────┐
   You, a browser ───────▶│ the panel (PHP)    /var/www/<site>           │
                          │   owns the database, generates the list      │
                          └───────┬───────────────────────┬──────────────┘
                                  │ every minute          │ narrow helpers, sudo
                          ┌───────▼─────────┐    ┌────────▼──────────────┐
                          │ janitor (PHP)   │    │ /usr/local/sbin/      │
                          │  timer, 60 s    │    │   tracker-*.sh (root) │
                          └─────────────────┘    └───────────────────────┘
                          ┌─────────────────┐
                          │ metadata worker │  libtorrent, DHT — optional
                          │  (Python)       │
                          └─────────────────┘
```

The panel never runs anything as root itself. Where it must change the machine it calls one of the
`tracker-*.sh` helpers through a single `sudoers` line each, and every helper refuses to do anything
it was not asked for. Undoing any of it is deleting one file.

---

## 1. The machine

Debian 12 or 13, or Ubuntu 22.04+. A 2-core VPS with 2 GB of RAM runs a small tracker comfortably;
the box these instructions come from has 6 cores and 11 GB and also runs a game server, a forum and
mail.

```bash
sudo apt update
sudo apt install -y apache2 mariadb-server \
  php php-cli php-fpm php-mysql php-mbstring php-curl php-gd php-intl php-xml php-zip \
  git curl unzip nftables
```

PHP 8.1 or newer. Check with `php -v`.

⚠ **`php-fpm` and `php-cli` can be different builds with different extension sets.** The panel runs
under both — the web pages under fpm, the janitor under cli — so an extension present in one and
missing from the other produces a feature that works in the browser and silently does nothing on the
timer. `php -m` and your fpm pool's `php.ini` should agree.

---

## 2. The database

```bash
sudo mariadb -e "CREATE DATABASE tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mariadb -e "CREATE USER 'tracker'@'localhost' IDENTIFIED BY 'CHANGE-THIS';"
sudo mariadb -e "GRANT ALL PRIVILEGES ON tracker.* TO 'tracker'@'localhost'; FLUSH PRIVILEGES;"
```

⚠ **If this MariaDB is shared with anything else — mail, a forum, files — remember that a restart
takes all of them down together**, and that a full scan of the tracker's biggest table will flush the
InnoDB buffer pool the others are using. The index table on the reference machine is 2.8 GB. If you
share, give the pool room:

```ini
# /etc/mysql/mariadb.conf.d/60-tracker-innodb.cnf
[mysqld]
innodb_buffer_pool_size = 512M
innodb_buffer_pool_size_max = 2G
```

---

## 3. The panel

```bash
sudo mkdir -p /var/www/tracker.example.org
sudo git clone https://github.com/TryHackX/tryhackx-tracker.git /var/www/tracker.example.org
cd /var/www/tracker.example.org
sudo chown -R www-data:www-data config/
sudo chmod 775 config/
```

Only `config/` needs to be writable. Everything else can be owned by root and read-only to the web
user.

Apache vhost, with `AllowOverride All` so the shipped `.htaccess` is read:

```apache
<VirtualHost *:443>
    ServerName tracker.example.org
    DocumentRoot /var/www/tracker.example.org
    <Directory /var/www/tracker.example.org>
        AllowOverride All
        Require all granted
    </Directory>
    # TLS lines from certbot go here
</VirtualHost>
```

```bash
sudo a2enmod rewrite headers
sudo systemctl reload apache2
```

Then open `https://tracker.example.org/install.php`, work through the four steps, and **delete
`install.php`** at the end — the last step offers to do it for you.

⚠ **The `.htaccess` ships a Content-Security-Policy.** It is deliberately tight. Two things you may
want to change are called out in comments inside it: `img-src` carries `https:` so that images in
descriptions work at all (delete it if you would rather not allow remote images, and set the image
limit to 0 in Settings), and each CAPTCHA provider needs its own host listed.

---

## 4. opentracker

The panel does not include a tracker; it drives one — and the package ships the two builds it is
developed against, so this step can be four commands or a full build from source. Either way,
**opentracker's accesslist mode is chosen at compile time**, which is the single most surprising
thing about it, and the reason there are two binaries.

```bash
sudo useradd -r -m -d /home/tracker -s /usr/sbin/nologin tracker
sudo mkdir -p /home/tracker/accesslist && sudo chown tracker:www-data /home/tracker/accesslist
sudo chmod 2770 /home/tracker/accesslist
```

### Either: use the builds in this package

```bash
cd /var/www/tracker.example.org/tools/opentracker/bin   # or wherever you unpacked the release
sha256sum -c <<'SUMS'
399230797752f6d1e217a0b43d1d8ce3ea4451291664de6e79a2912fbd4259ac  opentracker.white
4c6dc5f693ac9b083d751f5b563c4f83c722a278df70236f4e3a99f00dcf9baa  opentracker.black
SUMS
sudo install -o tracker -g tracker -m 0755 opentracker.white /home/tracker/opentracker.white
sudo install -o tracker -g tracker -m 0755 opentracker.black /home/tracker/opentracker.black
```

Debian 13 / x86-64, dynamically linked against `libz` and `libc` only.

### Or: build them yourself

The full recipe — upstream commit, the two patches, the feature flags and what each one is for —
is in **[tools/opentracker/README.md](tools/opentracker/README.md)**. In short:

```bash
cd /usr/local/src
sudo git clone git://git.fefe.de/libowfat && sudo make -C libowfat
sudo git clone git://erdgeist.org/opentracker && cd opentracker
sudo git checkout 1c7fac4cc23801ac81a2abd7d3110683831c4811

P=/var/www/tracker.example.org/tools/opentracker
sudo patch -p1 --forward < $P/sighup-udp-workers.patch     # else `systemctl reload` KILLS the tracker
sudo patch -p1 --forward < $P/udp-reject-interval.patch    # else rejected clients retry for ever

F="-DWANT_FULLSCRAPE -DWANT_COMPRESSION_GZIP -DWANT_RESTRICT_STATS -DWANT_MODEST_FULLSCRAPES"
O=/usr/local/src/libowfat
sudo make clean && sudo make FEATURES="$F -DWANT_ACCESSLIST_BLACK" LIBOWFAT_HEADERS=$O LIBOWFAT_LIBRARY=$O
sudo install -o tracker -g tracker -m 0755 opentracker /home/tracker/opentracker.black
sudo make clean && sudo make FEATURES="$F -DWANT_ACCESSLIST_WHITE" LIBOWFAT_HEADERS=$O LIBOWFAT_LIBRARY=$O
sudo install -o tracker -g tracker -m 0755 opentracker /home/tracker/opentracker.white
```

⚠ `make clean` between the two is **not** optional: the object files carry the accesslist flag, and
without it the second build silently keeps the first one's mode.

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
access.stats <your-ip>
access.stats_path pick-something-unguessable
tracker.redirect_url https://tracker.example.org/?action=whitelist
access.udp_reject_interval 86400

# /home/tracker/opentracker.conf.black   — identical but:
access.blacklist /home/tracker/accesslist/blacklist
```

`access.udp_reject_interval` comes from the second patch and is the single biggest traffic saving on
this machine: a UDP announce for a hash the accesslist rejects gets a well-formed "0 peers, come back
in 86 400 s" reply instead of the 8-byte packet clients treat as a broken tracker and retry for ever.
Leave it out and a whitelist tracker that inherited an open swarm spends most of its inbound
bandwidth on clients asking again.

Symlinks pick which pair is live, and the systemd unit never changes:

```bash
sudo ln -sf /home/tracker/opentracker.black      /home/tracker/opentracker
sudo ln -sf /home/tracker/opentracker.conf.black /home/tracker/opentracker.conf
sudo mkdir -p /home/tracker/accesslist
sudo touch /home/tracker/accesslist/{whitelist,blacklist}
sudo chown -R tracker:www-data /home/tracker/accesslist
sudo chmod 2770 /home/tracker/accesslist
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

In the panel: **Settings → Tracker mode & the accesslist file**, point it at
`/home/tracker/accesslist/whitelist` (or the blacklist), and press the Test button beside the path.

---

## 5. The janitor — one timer, and most features depend on it

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

⚠ **php-fpm runs with `ProtectSystem=full` and `ProtectKernelTunables=yes` on most distributions**,
so `/etc/` and `/proc/sys` are read-only *inside the web process even for root through sudo* — it is
a mount namespace, not a permission bit. Several features therefore work in two halves: the helper
changes the kernel immediately and reports `deferred`, and the janitor (which has no such sandbox)
writes the file a moment later. Without the timer running, those features appear to half-work.

The janitor also does: scheduled mode switching, whitelist regeneration and dead-row cleanup,
statistics sampling and roll-up, the mail queue, audit-log retention, and starting the stability
probe. **If one thing on this page must be running, it is this timer.**

---

## 6. Root helpers (optional, but this is where the panel earns its keep)

Each helper is a single script with a narrow job, allowed for the web user by one `sudoers` line.

```bash
cd /var/www/tracker.example.org
sudo install -m 0755 tools/opentracker/tracker-mode.sh     /usr/local/sbin/
sudo install -m 0755 tools/opentracker/tracker-netlimit.sh /usr/local/sbin/
sudo install -m 0755 tools/opentracker/tracker-sysctl.sh   /usr/local/sbin/
sudo install -m 0755 tools/opentracker/tracker-instance.sh /usr/local/sbin/
sudo install -m 0755 tools/opentracker/tracker-backup.sh   /usr/local/sbin/
sudo install -m 0755 tools/opentracker/tracker-cluster.sh  /usr/local/sbin/
```

One file per helper, mode `0440`:

```bash
for h in mode netlimit sysctl instance backup cluster; do
  echo "www-data ALL=(root) NOPASSWD: /usr/local/sbin/tracker-$h.sh" \
    | sudo tee /etc/sudoers.d/tracker-$h > /dev/null
  sudo chmod 0440 /etc/sudoers.d/tracker-$h
done

printf '%s\n%s\n' \
  'www-data ALL=(root) NOPASSWD: /bin/systemctl restart opentracker' \
  'www-data ALL=(root) NOPASSWD: /bin/systemctl reload opentracker' \
  | sudo tee /etc/sudoers.d/tracker-restart > /dev/null
sudo chmod 0440 /etc/sudoers.d/tracker-restart
```

Every card in the panel that uses one has a **Test** button. Press it before pressing Apply — the
test says what the helper can and cannot do on this machine, and why.

---

## 7. The metadata worker (optional)

Resolves torrent names and file lists over DHT, so the whitelist and index show something more
useful than a hash.

```bash
sudo apt install -y python3-libtorrent
sudo mkdir -p /home/tracker/metadata_worker
sudo cp worker/worker.py /home/tracker/metadata_worker/
sudo cp worker/tracker-metadata.conf.example /etc/tracker-metadata.conf
sudo chown -R tracker:tracker /home/tracker/metadata_worker
```

Edit `/etc/tracker-metadata.conf` with the database credentials, then:

```ini
# /etc/systemd/system/tracker-metadata.service
[Unit]
Description=Tracker metadata worker
Wants=network-online.target
After=network-online.target mariadb.service

[Service]
Type=simple
User=tracker
Group=tracker
ExecStart=/usr/bin/python3 /home/tracker/metadata_worker/worker.py /etc/tracker-metadata.conf
Restart=always
RestartSec=5
Nice=10
# it parses torrent metadata from arbitrary strangers
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=read-only
ReadWritePaths=/home/tracker/metadata_worker
PrivateTmp=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes

[Install]
WantedBy=multi-user.target
```

⚠ **The worker reads its concurrency at start-up.** Changing *Worker parallel fetches* in Settings
does nothing until the service restarts. Its heartbeat file says what is actually in force:

```bash
sudo cat /home/tracker/metadata_worker/heartbeat
```

---

## 8. Tuning, in the order that pays

Do these in order. Each one's evidence is on **Traffic**, and the page will tell you if a step is not
worth taking on your machine.

**a) Give the tracker's socket a real receive buffer.** Traffic → Kernel network buffers → *Use
suggested* → *Apply*.

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
own last seven days, and the page says what it is based on.

**d) If the machine shares with something else, run the stability probe.** Settings → Stability probe
→ Enabled, then the card at the bottom of Traffic. It moves the limit through a few steps, holds each
for a few minutes, and watches the drop counters of **every other UDP socket on the machine** — the
question a formula cannot answer. It stops itself the moment a neighbour starts dropping, always puts
the settings back, and never applies anything on its own.

Kernel buffers are deliberately *not* something it ramps: a buffer is fixed at socket creation, so
testing one means restarting the tracker at every step.

---

## 9. Backups (optional)

```bash
sudo mkdir -p /var/backups/tracker && sudo chmod 700 /var/backups/tracker
```

Settings → Backups: choose a directory and a profile. Then **Backups → Make a backup now**, which
lets you pick a profile for that run without touching the schedule.

⚠ **A complete archive looks far too small.** The database on the reference machine is 3.3 GB and its
full archive is 158 MB — hex hashes and repetitive names compress about twentyfold. The built-in dump
also names every file `tracker-db-*` whatever profile made it, so the filename contradicts the choice;
the panel shows what each archive actually contains and how far it compressed.

---

## 10. Before you call it done

- [ ] `install.php` is deleted.
- [ ] CAPTCHA is on (Settings → Security & CAPTCHA), and you have tested a sign-in.
- [ ] The janitor timer is active: `systemctl list-timers | grep tracker`.
- [ ] Every card on Traffic that you enabled has had its **Test** pressed and is green.
- [ ] Tracker mode agrees with reality — the Whitelist page shows both the panel's setting and what
      the tracker is actually running, and says so loudly when they differ.
- [ ] A backup has been made *and verified* at least once.
- [ ] You have signed in once as an ordinary member and checked the public pages look right —
      permissions are per-group, and the owner sees things a member does not.

---

## Upgrading

```bash
cd /var/www/tracker.example.org
sudo -u www-data git pull
sudo -u www-data php tools/janitor.php     # runs any pending migration once, immediately
```

The schema migrates itself on the first request after an upgrade. Migrations that would hold a lock
on the big index table for minutes are **deferred** and run only from the CLI, so a web request never
stalls behind one — which is why running the janitor by hand after an upgrade is worth the ten
seconds.

⚠ **A settings-only release still bumps the schema number.** Default rows are inserted by the
migration block, and that block only runs when the version moves.

---

## When something looks wrong

| What you see | What it usually is |
|---|---|
| Stats stuck on "Syncing swarms…" | a stale `config/stats_fetch.lock` copied from another machine |
| A card says "not written" | nothing has been applied yet — it is not an error |
| Panel and tracker disagree about the mode | the mode helper is not installed, or the schedule is off; the Whitelist page says which |
| A setting changed but nothing happened | the janitor timer is not running, or the worker needs a restart |
| A chart is a flat zero | the column existed before the data did — check whether the series is new |
| The probe reports nothing | it needs the netlimit helper and its sudoers line |

`journalctl -u tracker-whitelist-janitor -n 50` and the panel's own **Log** page answer most of the
rest between them.
