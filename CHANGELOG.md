# Changelog

All notable changes to this project are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/), and the project aims to follow
[Semantic Versioning](https://semver.org/).

## [1.11.2] — 2026-08-27

### Fixed
- **A healthy firewall reported itself as unavailable.** The new directory-writability probe used
  `: >"$probe" 2>/dev/null`, and bash applies redirections left to right: the redirection fails
  first, so "Read-only file system" went to the *real* stderr before `2>/dev/null` existed. The
  probe runs inside a command substitution while the status JSON is being assembled, so that line
  was spliced into the middle of the reply — right after `"file_pps":40000` — and nothing could
  parse it. The probe is silenced properly now, and each reply is written in a single `printf`, so a
  stray line can only ever land on a line of its own, which the panel already skips.
- **The median / P95 / peak marks under the slider collapsed into a smudge.** On a saturated port
  they sit within a fraction of a percent of each other, which on the logarithmic track is the same
  pixel. Colliding labels now take a row of their own, with their tick extended down to meet them —
  no value is dropped, all three still read cleanly.

## [1.11.1] — 2026-08-27

### Fixed
- **The UDP traffic card could sit on "Reading the firewall…" for ever.** Three separate paths led
  there and none of them could recover. The poll discarded any answer that arrived after a newer
  request had *started* — so with a status call slower than the five-second poll, every single answer
  was thrown away and the card never painted although the server was replying normally. A rejected
  fetch returned silently, leaving the loading state with nothing on screen to say anything had gone
  wrong. And a request that never settles ran neither branch at all. Now: one request in flight at a
  time, the repaint guard compares against what is actually **on screen**, failures render an
  explanation with a **Try again** button, and a 15-second watchdog replaces the loading state when
  nothing comes back. Opening the page also stopped firing two identical status requests at once.
- **A limit applied from the panel was live but never saved.** `/etc` is mounted read-only inside
  php-fpm's mount namespace (systemd `ProtectSystem=full`) — for root too, because it is a namespace
  and not a permission bit — so the rule reached the kernel and the file that restores it after a
  reboot did not. Worse, the card still reported it as persistent, because `persistent` only meant
  "a file exists": on the production box the loaded ruleset was a 40 000 pps limit while the saved
  copy was the old counting-only one. `persistent` now means *what is loaded will still be here after
  a reboot* and compares the file with the live table; a save the web server cannot perform is
  reported as deferred rather than as a failed apply, and the janitor — an ordinary unit without that
  sandbox — finishes it within a minute through the helper's new `persist` action. The availability
  test says so up front.
- Read-only admin pollers release the PHP session lock (`session_write_close()`) once past the auth
  check. They only read, and holding the lock made every poll on a page queue behind the slowest one.

### Changed
- **New panel page: Traffic** (`?action=admin-traffic`). The swarm timeline and the UDP traffic card
  moved there from Whitelist and Index. Every chart in the panel is now in one place, and neither
  list page loads uPlot any more.
- **The navigation bar shows every page on every page**, current one included and marked active. Each
  template used to carry its own hand-edited copy of the bar with its own entry deleted, so the bar
  changed shape from page to page and nothing told you where you were. It now comes from one list
  (`adminNavItems()`) through one partial (`templates/admin/_header_actions.php`).
- **Sorting waits 900 ms instead of 450 ms** before it fetches — long enough to pick a column *and*
  its direction, since the direction cycles through three states. The tabs that had no delay at all
  (Banned, API bans, and the whole dashboard) now use the same wait.
- The backups table's row buttons use the panel's standard action-button class, so they are spaced
  and sized like every other table instead of being glued edge to edge.
- The inbound-limit slider is drawn in the panel's own palette instead of Bootstrap's light default,
  and carries a **logarithmic ruler** (1k / 10k / 100k / 1M with minor ticks), so a value can be read
  off the track rather than guessed.

## [1.11.0] — 2026-08-27 (schema v11)

### Added
- **UDP traffic monitor + inbound rate limit** (Admin → Whitelist → *UDP traffic*, configured under
  Settings → Tracker & whitelist → *UDP traffic & rate limit*). The egress budget shipped in
  `tools/opentracker/egress-budget/` keeps the machine reachable; this is the other half of the same
  problem — the CPU the tracker burns answering a swarm whose torrents it will refuse anyway. A packet
  dropped by the firewall costs nothing at all.
  - **Measure before you decide** — and the panel can measure *without throttling anything*. The
    counters live in the firewall, so with no table of ours loaded every sample would be a zero;
    **"Start counting"** loads the same table with the three counters and **no drop rule at all** (the
    chain accepts by default and contains nothing that can discard a packet). Measure with it for a
    day, then press Apply limit to add the rule. The card always says which of the two is in force.
    With the monitor on, the janitor samples the nftables counters once
    a minute into the new `net_samples` table: arriving / served / dropped packets per second, plus the
    egress counters, plus the limit in force. The card charts them (1 h … 30 d, bucketed server-side)
    and — the point of the whole thing — turns them into a sentence: *"median 22 000 pps, P95 38 000
    pps, peak 61 000 pps. A limit at 40 000 pps (P95 + 5 %) would essentially never trigger; below
    roughly 24 000 pps you start dropping packets that are currently arriving."* The same three values
    are drawn as marks on the (logarithmic) slider, so the number being chosen has context instead of
    being a guess. It refuses to pretend arrivals are demand: when nothing is dropping them (or
    somebody else's rule is doing it downstream) it says so, because on a tracker whose old swarm keeps
    calling, matching the measured peak would mean no limit at all.
  - **Non-invasive by construction.** Everything the panel writes is **one file**
    (`/etc/nftables.d/ottrack-in.nft`) in **its own table** (`inet ottrack_in`, hook `input`,
    `priority filter - 5`, `policy accept`). The distribution's `inet filter` table is never written
    and never flushed, so a rule an admin added there by hand keeps working — the card lists any such
    rule it finds on the same port together with the exact `nft delete rule …` line to remove it.
    The ruleset loads as a single `nft -f` transaction (create-if-missing → delete → recreate), so the
    port is never unprotected while the limit changes. Undo is one button.
  - **Automatic mode** (off by default) moves the limit ±10 % inside a configurable band once a
    minute, but only after three consecutive samples on the same side and with a two-minute cool-down,
    so a single spike changes nothing; a load-per-core guard tightens even when the packet rate is
    under target. **"Throttle hard"** clamps the port to 10 000 pps for 15 minutes and the janitor
    restores the previous setting by itself — including switching the limit back *off* if it was off —
    so the panic button cannot be left on by accident.
  - **Root stays behind one narrow door**: `tools/opentracker/tracker-netlimit.sh`, allowed through
    `sudoers` with NOPASSWD, validating every argument itself; PHP never calls `nft` directly. Applying,
    removing, throttling and restoring need the admin password. **Preview ruleset** does not — it
    renders and `nft -c`-checks the file without loading it, which is what you want to read *before*
    committing. The **Test** button is read-only as well and checks `exec()`, the sudoers rule
    (`sudo -n -l`, which lists the permission without running anything), `nft`, `/etc/nftables.d/` and
    the `include` line that makes the rule survive a reboot, with copy-paste fixes for whatever is
    missing. Where `nft` is absent the card says so; nothing errors.
  - The card also **shows** the egress budget's counters next to the inbound ones and can change its
    rate with a handle-targeted `nft replace`, so that table's 262 144-entry "good client" sets are not
    flushed. It never installs or removes `ottrack.nft` — that stays a manual, documented step.
  - New: `includes/netlimit.php`, `tools/opentracker/tracker-netlimit.sh`, `assets/js/admin-netlimit.js`,
    `api/admin/net_status.php` / `net_samples.php` / `net_apply.php` / `net_test.php`,
    `tests/netlimit_test.php` (172 checks, including the helper driven end to end against a stub `nft`).
  - Settings: `net_monitor_enabled`, `net_sample_seconds`, `net_keep_days`, `net_limit_enabled`,
    `net_limit_pps`, `net_limit_burst`, `net_limit_port`, `net_limit_cmd`, `net_auto_enabled`,
    `net_auto_min`, `net_auto_max`, `net_auto_target`, `net_auto_target_cpu`. **All off by default:**
    a fresh install never calls the helper, never writes a firewall rule and renders no extra card.

- **Backups from the panel** — a new page (**Admin → Backups**, `?action=admin-backups`) and a
  Settings section, driven by a second root helper `tools/opentracker/tracker-backup.sh`.
  - **It backs up the tracker, not the machine.** By default that means the tracker database, via
    `mariadb-dump`. Where `Backup-serwera.sh` (the server toolkit) is installed, the panel *steers
    that* rather than duplicating it — one backup program on the machine, not two — and the profiles
    gain its configuration, list, unit and firewall items. Whole-server backups stay that tool's job;
    the page states what an archive covers instead of nagging about what it is not.
  - **Nothing heavy in a web request**: a run is started detached (systemd-run when available, with
    `Nice=` and idle I/O priority) and reports through a JSON state file the page polls — including
    the live log tail, so a backup shows what it is doing instead of spinning silently. A worker that
    is killed or lost to a reboot is reported as failed rather than "running" for ever.
  - Profiles (**light** — everything except the two huge index tables, which rebuild themselves from
    the swarm — **full**, **database only**, **custom**), a weekday+time schedule fired by the janitor
    (a slot missed while the machine was off still runs later the same day, never twice), rotation by
    count / age / total size (oldest first, and the last archive standing is never deleted), and a
    checksum + read-back after every run, because an archive nobody has ever read back is a guess.
  - **Restoring** is split in two on purpose. Files and configuration go through the toolkit, which
    leaves a `.bak-<stamp>` of everything it overwrites; the **database** is its own action, because
    `Backup-serwera.sh` deliberately refuses to overwrite one without a person typing its name at a
    terminal. We do not fake a terminal for it — the panel asks for the same thing (the admin password
    *and* the exact database name), and the helper dumps the database it is about to overwrite before
    importing a single byte, refusing outright if that dump fails.
  - **Encryption is public-key** (`gpg --encrypt --recipient`), not the toolkit's interactive
    `--symmetric`, which needs a terminal for its passphrase and silently skips itself without one.
  - Archives are `0600` in a `0700 root` directory — the web user cannot read them at all. Downloads
    stream through the helper (constant memory regardless of size) behind a token that is bound to one
    archive, expires in five minutes and is burned on first use.
  - New: `includes/backup.php`, `tools/opentracker/tracker-backup.sh`, `assets/js/admin-backups.js`,
    `templates/admin/backups.php`, `api/admin/backup_{status,action,test_path,download}.php`,
    `tests/backup_test.php` (134 checks, the helper driven end to end against a stub toolkit).
  - Settings (group **Backups**): `backup_enabled`, `backup_dir`, `backup_profile`, `backup_items`,
    `backup_schedule`, `backup_schedule_tz`, `backup_keep`, `backup_keep_days`, `backup_max_size_gb`,
    `backup_gpg_recipient`, `backup_nice`, `backup_verify_after`, `backup_cmd`, `backup_script_path`,
    `backup_db_name`. Off by default, and the directory is refused if it is anywhere the web server
    could serve it.

## [1.10.1] — 2026-08-26

### Added
- **The admin's own email address is managed in Settings → Security & Credentials.** The panel login
  is mirrored into `users`, so this is the same address a member has — and it changes the same way:
  confirm from the current mailbox first, then from the new one (nothing is written until both links
  are opened), with the verified badge, the pending-change banner and a Cancel button in the same
  block. The panel password is the gate. Clearing the box removes the address; a login with no linked
  account row says so instead of offering a field.

### Changed
- **Settings → CAPTCHA shows only the selected provider's keys.** The other providers' fields stay in
  the form (their values are never lost when switching back) but are out of the way — and a search for
  e.g. "turnstile" still reveals them.
- **The sender address is no longer free text**: a local part plus a domain picked from the Site-URL
  host and its parent domains, which is exactly the set that can align with SPF/DKIM/DMARC. Pasting a
  whole address keeps only its local part; an empty box still means "send from Site Email". A Site URL
  without a domain (an IP) falls back to the old free-text field with a hint.
- The **GitHub URL** field says what it is for: the footer link should point at the project
  repository, not at an account page.
- A test fixture no longer carries a real server IP address.

## [1.10.0] — 2026-08-26 (schema v10)

### Added
- **hCaptcha** as a fourth CAPTCHA provider (`hcaptcha_site_key` / `hcaptcha_secret`, Settings →
  CAPTCHA), verified against `api.hcaptcha.com/siteverify` with the site key sent along; the shared
  modal renders its widget exactly like reCAPTCHA v2 / Turnstile and the installer now asks which
  provider to set up instead of assuming reCAPTCHA v2. **The CSP in `.htaccess` gained the hCaptcha
  hosts** — a provider whose script host is not allow-listed there can never load (Turnstile's and
  reCAPTCHA's XHR hosts were missing from `connect-src` too, and are now listed).
- **Movable admin sign-in address** (`admin_login_path`, Settings → Admin Access & Sessions): the
  form that used to answer on every panel URL now lives at exactly one `?action=` value — leave it at
  `admin` or move it to something unguessable. What a signed-out visitor gets on the *other* panel
  URLs is a second setting (`admin_hidden_behavior`): **redirect to the front page** (new default),
  the sign-in form (the old behaviour) or a **404** page. Signed in, the panel keeps its classic
  addresses, so links, bookmarks and Logout are unaffected (the Logout buttons now return to the
  configured address instead of reloading a URL that no longer shows a form). While a custom address
  is set, `admin/login` also refuses sign-ins from sessions that never opened the form, since the API
  endpoint itself cannot move.
- **Admin sign-in screen restyled** to the public site's look (`templates/pages/adminlogin.php`,
  rendered through the normal layout: same nav, footer, fonts, CAPTCHA overlay and notice).
- **Timeline range controls** (Settings → Statistics Timeline): choose **which range buttons** the
  chart offers (`stats_timeline_ranges`), **which one opens by default** (`stats_timeline_default_range`,
  default 24h — a visitor's own last pick still wins on their next visit) and an optional free
  **Custom span slider** from 1 h to 5 years (`stats_timeline_custom_range`, off by default). A custom
  span is a first-class range for the API (`&range=custom&span=…`, snapped to the slider's stops) so it
  takes the same 30 s file cache and the same server-side clock as the named ranges.
- **Settings page: group sub-menu + ranked search.** The ~150 settings are filed under nine groups
  (Site & pages, Contact & email, Security & CAPTCHA, User accounts, Tracker & whitelist, Statistics,
  Index, API & federation, Admin credentials) with a chip per group, and a search box that shows the
  best-matching settings first (their section reduced to the matching fields), then whole sections
  whose name matches, then the rest of any matching group under a divider. Matching also uses hidden
  synonyms per setting (`includes/settings_catalog.php`, served by `admin/settings_catalog`) that are
  never rendered into the page — searching "bot", "smtp", "cron" or "hidden url" finds the right
  switch. `/` or `Ctrl+K` focuses the box; `#section-…` deep links from the other admin pages still
  work and now open the right group.

### Fixed
- **Swarm timeline legend wrapping**: entries that fall onto a second line (e.g. *Indexed hashes*)
  now start under the first series instead of the far left, on any window width — the indent is
  measured from the live layout and the "Time" value keeps a fixed width so nothing shifts on hover.
  Applies everywhere the chart is mounted (public stats, admin Index, admin Whitelist).
- **CAPTCHA modal robustness**: the widget is rendered only after the box is open (rendering into a
  `display:none` overlay produced a zero-sized, invisible checkbox), a click that beats the async
  provider script now waits for it instead of failing instantly with "CAPTCHA cancelled", a second
  prompt can no longer leave the first caller's promise hanging, and a widget that keeps erroring
  (wrong site key, host not on the key's allowed-domain list — Turnstile `110200`) is retried once and
  then reported as "CAPTCHA could not load" instead of looping forever with the box stuck open.
- reCAPTCHA v3 tokens for the admin sign-in are now verified against their `admin_login` action, and
  the admin dashboard prints the required "protected by reCAPTCHA" notice (the badge is hidden there).
- A zoom window that reaches beyond the raw-sample retention no longer promises raw resolution it
  cannot deliver (the same row-count confirmation the fixed ranges already made), and the chart stops
  re-requesting a finer step the server can never serve for an old window.
- Three `pattern=` attributes in Settings were invalid under the stricter regex mode browsers now
  compile them with, so their client-side validation silently did nothing.
- Dead CSS from the removed admin login template (`.login-container`, `.alert-box`) dropped.

## [1.9.4] — 2026-08-25

### Changed
- The sender-address hint in Settings no longer hardcodes claims about "this server's" DKIM/SPF —
  it now gives universal guidance (pick the domain your mail server signs DKIM for and has an SPF
  record on, usually the root domain). The allowed-domain list was always computed dynamically
  from the Site URL.

## [1.9.3] — 2026-08-25

### Added
- **Separate sender address** (`mail_from_email`, Settings → Contact & Email, also in the
  installer): all outgoing mail (resets, verifications, notices) is sent FROM this address (From:
  header, envelope sender, Message-ID domain) while replies and the public contact stay on
  **Site Email** (Reply-To). The sender domain is validated to be the Site-URL host or one of its
  parent domains (e.g. site https://tracker.example.com allows `…@tracker.example.com` and
  `…@example.com`) — anything else would break SPF/DKIM/DMARC alignment and land in spam. Empty =
  classic behaviour (send from Site Email).

## [1.9.2] — 2026-08-24

### Added
- **Rebuild done** button in the Fetch-metadata dropdown on both Index and Whitelist: every row
  that still carries resolved metadata (name+size) but lost its `done` status to a bulk re-fetch
  or a queue cancel goes straight back to `done` — nothing is fetched or deleted. The whitelist
  "Cancel queued" is restore-aware now too (resolved rows → done, like the Index one).
- **List loading feedback**: user actions (sort/filter/page/search) dim the table for the duration
  of the request; a small pulsing dot next to the row counter lights on EVERY refresh, including
  the silent 5-second live updates while metadata is being fetched (which are unchanged). The
  whitelist keeps its old rows on screen while loading instead of swapping in a spinner row.

## [1.9.1] — 2026-08-24

### Fixed
- **Named index rows stay searchable regardless of the queue state** — a bulk "All rows
  (re-fetch)" used to make thousands of resolved entries vanish from the member search until the
  worker re-resolved them; now anything with a stored name remains findable, "Cancel queued"
  restores resolved rows to `done` (reported in the toast), and the All-rows re-fetch asks for
  confirmation and explains what will happen.
- Sort-click debounce raised to 450 ms (rapid header clicking fired a request per click);
  password-checklist order (special character before digit, digit centred); verification-mail
  wording; after a completed email change the NEW mailbox also receives a written confirmation;
  Terms of Service gained a **User accounts** section (stored data, mails, cookies, cool-down,
  account removal).

## [1.9.0] — 2026-08-24 (schema v9)

### Added — accounts
- **Proper transactional emails**: a real CTA button plus the raw link underneath ("if the button
  does not work, copy this link"), absolute URLs (reset links used to arrive as a relative,
  unclickable `/?action=reset…` path), and the footer "Manage notification preferences" link now
  actually points at the recipient's signed preferences page (or is omitted). Applies to password
  reset, email verification and every account notice. The account page gained an **Account
  emails** toggle (same `email_preferences` store as the unsubscribe page, type `account`) via the
  new `user_email_prefs` endpoint.
- **Email verification gate** (`users_require_email_verify`, default ON): registration requires an
  email address and group permissions only apply after the confirmation link is clicked — an
  unverified sign-in runs at **guest level** (the account page stays reachable, `admin`-group
  members are exempt). The account page shows a banner while restricted.
- **Terms checkbox at registration** (always required): the link opens `?action=tos` by default,
  or — when the admin pastes text into Settings → *Registration terms* (`users_terms_text`) — a
  modal with that text.
- **Two-step email change** (`users.pending_email`/`email_changed_at`): changing (or removing) the
  address is confirmed from the **old mailbox first**, then — for a change — from the **new one**
  (`?action=emailchange`, 24 h single-use links); only the second click writes anything, the new
  address arrives already verified, and a **cooldown** (`users_email_change_cooldown_days`,
  default 30, 0 = off) blocks the next change so a hijacked session cannot quietly steal the
  mailbox and cover its tracks. The account page shows the pending step with a Cancel button;
  accounts without an old address keep the direct path (standard verification covers the new one).
- **Client-side CAPTCHA retry**: after a solved CAPTCHA is submitted, a "verification failed"
  reply is retried up to 3× at 1 s intervals before the user sees an error — on a lossy uplink the
  server's verifier call often just needs a second attempt (doubles as the usual anti-bruteforce
  processing delay).

### Added — lists & search
- **Rows per page** (15/25/50/100/200, remembered per browser) on the admin Whitelist and Index
  tables and the public search.
- **Search master switches**: `index_search_enabled` (kill-switch that overrides `index.view`
  grants) and `index_search_include_whitelist` (whether registered torrents may appear in member
  search results).
- The search page shows a **loading state** (dimmed table + "Searching…") so a slow reply is
  distinguishable from a hang; the password checklist renders as a **two-column grid**; the
  cut-off "File…" header on Index/Whitelist is fixed (wider column).
- The admin "Dashboard" is now called **Reports** (that page manages abuse reports & appeals; the
  tracker-status card just lives there).

## [1.8.0] — 2026-08-24

### Fixed — the two production bugs behind "first CAPTCHA always fails" and "no reset mail"
- **Outgoing mail bounced on non-ASCII subjects**: `sendEmail()` sent raw UTF-8 headers (the
  em-dash in "Site — password reset"); postfix then required SMTPUTF8, dovecot-LMTP does not offer
  it, and the message was **bounced** before ever reaching the mailbox. Subject and From name are
  now RFC 2047 encoded — password resets / verification links / notification mails deliver.
- **CAPTCHA verifier retry**: this host shares its uplink with the tracker swarm (measured 30-40 %
  packet loss during OPEN hours) — a single 3 s/5 s attempt to reach the Google/Turnstile verifier
  often timed out, failing a correctly solved CAPTCHA on the first try. The verify call now retries
  once with longer timeouts (connect 5 s, total 8 s) and logs the transport error / rejection codes
  to the PHP error log, so a real failure is diagnosable.

### Added
- **Panel session from the site sign-in**: signing in on the public site as a member of the
  `admin` group also opens the ADMIN PANEL session — no second login. The panel keeps its own
  idle/absolute limits (`admin_session_idle_minutes` / `admin_session_absolute_hours`), so a
  "forever" site sign-in does **not** keep the panel open forever: after the idle window the panel
  asks for its login again while the site session stays. Panel logout / revoking the admin group /
  banning the user drops the piggy-backed panel session (checked on every panel request).
- **Password policy** (new passwords only): min 8 chars with a lowercase, an uppercase, a digit
  and a special character — enforced server-side everywhere a password is set (register, account
  change, reset, admin edit, `v1/users/provision` generated passwords) and mirrored as a live
  requirement **checklist** on the register / account / reset forms.
- **Root-admin protection**: the mirrored panel-admin account cannot be deleted, banned or
  stripped of the `admin` group (API guards + greyed-out controls with a shield badge); system
  groups (guest/member/admin) stay undeletable.
- **Search page**: multi-column sorting on the table headers with priority badges (desc → asc →
  off, like the admin tables) plus a **Best match first** toggle; matched words are highlighted in
  result names; the file-list modal shows a **collapsible folder tree** (matches highlighted and
  the file-count chip glows when the query matched file names); pagination shows "· N rows".
- **Admin lists**: sortable **Files** column on Index and Whitelist; whitelist rows open their
  details on click (like Index); ban icon is a lock (the squeezed "…" overflow is gone — wider
  action columns); the Index details modal uses the same folder tree as the whitelist; **live
  view** — while rows on screen are pending/fetching (or that filter is active) the page silently
  refreshes every 5 s without resetting sort/filters/selection; sort clicks are debounced (250 ms)
  and stale responses can no longer overwrite newer ones; the red-glow clear × now actually shows
  on Index/Whitelist/Users (it was permanently invisible outside the dashboard) and empties the box
  with an accelerating "held backspace" animation (public search too).
- **Account / users**: changing the email asks for it twice (public account + admin edit modal)
  and the OLD address gets a heads-up mail; admin-entered addresses count as verified; notification
  buttons show a tiny result tooltip ("Marked 23 read" / "Nothing to delete"); the admin user list
  marks verified emails and the site owner.
- **Timeline legend**: values align to the label text BASELINE again (the value/label line boxes
  had different line-heights, so middle-aligning them left the digits 1-2 px low).

## [1.7.0] — 2026-08-24

### Changed — permission semantics (schema v8)
- **Groups**: the `guest` group now holds the permissions of **anonymous visitors only**. A
  signed-in user gets **exactly the union of their own active groups** — guest is no longer
  inherited, so a group can be *narrower* than guest (previously guest's permissions leaked to
  every account, which made `stats.view` / `stats.timeline` / `home.stats` look broken on member
  groups). Group *priority* only orders badges — it never overrides permissions. The admin Users
  page explains this inline. Fresh installs seed `member` with guest's classic permissions so a
  new registration never sees less than an anonymous visitor; **existing installs keep their
  configured groups as-is** (review them after upgrading if you relied on guest inheritance).
- **System `admin` group + panel-admin migration**: a new seeded system group `admin` whose
  members pass **every** permission check (current and future). The migration mirrors the panel
  admin (settings `admin_username`, panel password hash) into the `users` table once and grants it
  the admin group — the site owner now shows up in the user list. Panel and user passwords do
  **not** stay in sync afterwards.

### Added
- **Search relevance + whitelist arm** (`?action=search`): results are ordered by a real
  **Best match** score (fulltext BOOLEAN MODE; rarer/longer words weigh more, a row matching more
  words ranks higher) with seeders as tie-break; sort keys `relevance | seeders | last | size |
  name`. With `whitelist.view` the search also folds in the **live whitelist** (whitelisted hashes
  are removed from the index, so they were previously unfindable) — rows carry a `WL` badge.
  With `index.files` the file-count chip opens a **file-list modal** (`index_files` endpoint).
- **Search page rework**: toolbar attached to the table (admin style) with a search-icon input,
  red-glow clear ×, restyled sort select and custom checkbox; **live search** (300 ms debounce, no
  button), admin-style pagination (First/Prev/page box/Next/Last), fixed column widths (no layout
  jump when Copy flips to ✓), IEC byte units (KiB/MiB/GiB — torrent sizes are powers of 1024)
  everywhere incl. the admin panels.
- **Sign-in duration** (`?action=login`): "Stay signed in for" — **forever** (default; ~10-year
  remember cookie) / 1 hour (session-only, server-side deadline) / 1 day / 30 days (remember
  cookie with that absolute expiry; token rotation keeps the original deadline).
- **Email verification**: registration with an address (and every email change) sends a
  confirmation link (`?action=verify`, 72 h, single use); the account page shows a
  verified/unverified badge with resend (`user_verify_send`, 3/h/IP), the admin user list marks
  verified addresses. Accounts work without confirming.
- **Notifications**: paginated (10/page) with a **Delete read** button; auto-prune note (read
  > 90 d, everything > 365 d — unchanged janitor behaviour, now documented in the UI).
- **Whitelist registration audience** (`whitelist_submit_mode`): `public` (anyone + CAPTCHA, as
  before) or **`users`** — only signed-in accounts holding the new **`whitelist.add`** permission
  may register hashes (no CAPTCHA; per-account *and* per-IP rate limits; submissions carry
  `source_ref = {"user":…,"id":…}`). Falls back to public while the account system is off.
- **Metadata worker concurrency** (`meta_worker_concurrency`): how many hashes the worker resolves
  in parallel (1–16), editable in Settings → Index; the worker re-reads it every ~60 s (no restart;
  requires a `SELECT` grant on `settings` for the worker DB user — falls back to its config file
  value otherwise, whose cap was raised 8 → 16).
- **Timeline zoom = finer resolution**: zooming/panning the swarm timeline refetches the visible
  span at the finest table that covers it (raw 60 s → 5 min → 1 h; `stats_timeline&from=&to=`),
  so "All"/90 d charts no longer stay hourly when zoomed into a day. Zoom-out restores the cached
  full-range payload; the status line shows "(raw · zoom)" while a window is active.
- **Admin actions**: an **open-magnet icon button** (🧲 anchor) next to Details on the Index and
  Whitelist tables (the whitelist copy button icon moved to a clipboard); user list is sortable by
  **group** (highest-priority active membership).

### Fixed / polished
- Real-time validation: register (username/email/password/repeat), account (email format, new
  password ≥ 8 + repeat box that appears when needed), admin user-edit modal (email format,
  password ≥ 8 + repeat, shown as password fields). The confusing "Remove my email address"
  checkbox is gone — clearing the email box removes the address.
- Nav: **Sign in + Register collapsed into one "Account" link**; base page width 52 → 62 em so the
  full menu stays on one line; mobile pass over the whole public site (nav without separators,
  stacking search toolbar, scrollable tables, compact pagination, no horizontal page scroll at
  375 px). Stats page `<title>` fixed (showed "Home").

## [1.6.0] — 2026-08-24

### Added
- **User accounts** (`users_enabled`, **off by default** — with it off everything behaves exactly as
  before): public registration + sign-in (CAPTCHA-protected; registration always requires a
  configured CAPTCHA, login uses the smart `login` context), per-IP rate limits, remember-me
  cookies (hashed, rotated on every use, invalidated on password change/ban), email password
  reset, an account page (profile, groups with expiry dates, in-app notifications) and hideable
  menu links (`users_links_visible`). Pages: `?action=login / register / account / reset`.
- **Groups & permissions**: groups carry JSON permissions (`index.view` / `index.files` /
  `index.magnet` / `whitelist.view` / `stats.view` / `stats.timeline` / `home.stats`). The seeded
  **guest** group is the baseline every visitor gets (its defaults preserve the classic public
  behaviour); the seeded **member** group is granted on registration. Gates cover the stats page +
  API, the timeline, the home stats widget and the public whitelist page; the admin panel always
  passes. Memberships can be permanent or timed (**1 d / 1 w / 2 w / 1 m / 3 m / 6 m / 1 y /
  custom from–to**); duration grants **extend** an existing membership, the janitor expires them,
  warns `users_notify_expiry_days` before the end (in-app + optional email) and notifies on grant,
  revoke and expiry.
- **Admin → Users page** (`?action=admin-users`): user browser (search, status/group filters,
  sortable), edit (status/email/password), delete, grant/revoke groups, custom notifications
  (optional email copy), and a Groups tab with a permission-matrix editor.
- **Member search** (`?action=search`): a user-facing search over the resolved observed-hash index
  — name (and, with `index.files`, file-name) search, seeders/size/recency sort, magnet links
  (with `index.magnet`) built client-side; `rate_limit_index_search` per IP.
- **Sales / shop API** (`v1/users/lookup | grant | revoke | provision`): automate selling timed
  group access from an external shop. API keys now carry a **scope** (`whitelist` | `users` |
  `federation` | `all`; existing keys keep `whitelist`) enforced on every v1 endpoint —
  `tools/api_client_example.py` shows every call.
- **Federation / cluster** (`fed_enabled`, **off by default**): tracker nodes exchange resolved
  index **metadata** so every operator gets a bigger search catalogue without re-fetching from the
  DHT. Pull-based: `v1/federation/export` serves cursor-paged, optionally gzip-compressed JSON
  (only `meta done` rows; optional file lists) to peers authenticated with a federation-scope key;
  `worker/federation.py` (systemd **timer**, not PHP) pulls from configured peers, validates
  everything and merges — filling metadata for locally observed hashes (`meta_source
  'fed:<peer>'`), and inserting unknown hashes only when `fed_import_new` is on (under the index
  row cap). Peer management (add peer, one-shown inbound bearer, outbound bearer, pull toggle,
  connection test) lives in Settings → Federation; `v1/federation/ping` verifies a link.
- **"This page" / "Near pages ±N"** scopes for *Fetch metadata* and *Refresh S/L* on both the
  Whitelist and Index pages: the near radius comes from the new `admin_near_pages` setting (1–20,
  default 2) and follows the current search/filters/sort; metadata scopes skip rows that already
  have (or are fetching) metadata; requests are chunked at 500 rows.
- **Index metadata auto-queue** (`index_meta_auto_queue`): every observed hash without metadata is
  queued automatically (spread over ~1 h, best-seeded first, 5000/tick) — the daily budget is
  ignored while on; the Index status card shows the active mode.
- `worker.py` stamps `meta_source='dht'` on index rows it resolves (schema v7 column).

### Changed
- Schema **v7**: `users`, `user_groups`, `user_group_members`, `user_notifications`,
  `user_tokens`, `fed_peers`; `api_clients.scope`; `index_hashes.meta_source` +
  `idx_index_meta_fetched` (guarded ALTERs on existing DBs); seeded `guest`/`member` groups.
- The admin Index list query moved into `indexListSelect()` (`includes/index.php`), shared with
  the member search endpoint. `whitelist_fetch_meta` accepts up to 500 ids (was 50).

### Fixed
- **Index page sorting** crashed with `state.sort.map is not a function` on any header click —
  `makeSortStack`'s `onChange` now passes the sort stack (array) instead of a serialized string.

### Security / correctness (adversarial pre-release audit — 14 findings, all fixed)
- **Permanent membership downgrade (high)**: a duration grant (shop API / admin) on a PERMANENT
  membership silently converted it to a timed one that later expired — `userDurationExpiry()` could
  not distinguish "no row" from "NULL expiry"; permanent now stays permanent.
- **Federation export cursor race (high)**: the cursor could land inside the still-open current
  second while the worker/importer was committing rows into it — later same-second commits were
  skipped forever. The export now serves only rows older than the current second; it also excludes
  locally banned/whitelisted hashes immediately (previously only the next index poll purged them).
- Remember-me auto-login now regenerates the session id (fixation hardening, same as the password
  path); the password-reset endpoint answers before doing account-dependent work
  (`fastcgi_finish_request`) so response timing no longer reveals whether an account exists.
- Admin/user profile edits are validated up front and applied in one transaction — a later
  validation failure can no longer leave an earlier field (e.g. a ban + token wipe) committed.
- Admin custom grants reject an already-past "to" date (previously: instant bogus
  grant + expired notifications); `fetch_users` no longer 500s on an array `sort` parameter.
- `fed_peers` INSERT lowercases pasted bearers (a mixed-case bearer passed the connection test but
  was silently skipped by `federation.py` forever); the importer also normalises old rows,
  decompresses peer responses in bounded chunks (gzip-bomb cap: 64 MB wire / 512 MB expanded).
- `worker.py` falls back to storing metadata without `meta_source` when the column/grant is not
  there yet (a mid-deploy fetch was previously discarded as `failed`); the two v7 ALTERs on
  `index_hashes` merged into one statement = one FULLTEXT-table rebuild instead of two.
- Near-pages bulk flows: a click during the collection window can no longer be misread as a stop
  request for another scrape, and the collection snapshots its search/filters/sort/page scope so
  mid-flight UI changes cannot mix two result sets into one bulk operation.

## [1.5.2] — 2026-08-23

### Added
- **Date-scoped bulk actions** on the Whitelist and Index pages: *Fetch metadata* and *Refresh S/L*
  for rows added / first seen in the **last 24 h / 7 d / 14 d** or a **custom from–to** window
  (`scope=date` with `since_hours` or `from`/`to` on `whitelist_meta_queue`, `index_fetch_meta`,
  `whitelist_scrape_bulk`, `index_scrape_bulk`; shared `parseDateRangeInput()`).
- **Cancel queued** (`scope=cancel`): resets every queued (`pending`) metadata fetch back to `none` —
  the stop button for a backlog that would take days; rows being fetched right now still finish.
- **Stop** for the bulk *Refresh S/L* loops (the main button becomes *Stop* while scraping).
- Timeline: **All** range (whole recorded history; hourly rows are thinned to ≤ ~5000 points), the
  Index page shows the shared timeline card, "Indexed hashes" series.

### Changed
- Index status card restyled like the Whitelist card (badges, grouped counters, over-cap warning).
- Index table: wider *Seen* column, action icons spaced; dashboard tab row keeps Whitelist + Index
  together on the right.

### Security (adversarial audit)
- **Rate-limit window eviction (high)**: `rateLimitAllow()` pruned EVERY key in the shared state file
  with the *caller's* window; the new public 60-s `stats_timeline` limiter therefore silently reset the
  hourly limits of appeals / public whitelist submissions / status / block checks (an attacker — or just
  a visitor with the stats page open — could turn "5 appeals/h" into "5/minute"). The prune now only
  touches the caller's own action namespace; `tests/rate_limit_test.php` guards the regression.
- `admin/index_poll_now` releases the session, survives a closed tab and gets an execution-time budget
  (a long poll no longer blocks other admin requests or dies mid-upsert); the temp scrape file is also
  removed on a fatal via a shutdown hook.
- **PHP ↔ MySQL clock alignment**: the generated `config/database.php` now issues
  `SET time_zone = date('P')` on connect, so `NOW()`/`CURRENT_TIMESTAMP` agree with PHP `date()` —
  date-scoped queues/scrapes and the samplers no longer shift when the two zones differ (existing
  installs: re-run the installer template change by adding the line to `config/database.php`).
- Worker claim is now two-step (plain SELECT candidate → UPDATE by primary key with a status recheck):
  the old `UPDATE … ORDER BY … LIMIT 1` filesorted *and* X-locked the whole pending set on a big queue.
- Cap-prune is skipped while a truncated poll awaits its resume (the un-reached tail carried stale
  `last_seen` and was evicted first, only to be re-inserted as brand-new rows by the resume pass).
- Timeline x-axis shows `mm.yyyy` once the visible span exceeds ~4 months ("All" after a year of
  history no longer repeats month labels with no year); Stop button keeps its tooltip after a run.

### Fixed
- **Index prune race**: two prunes (janitor tick + forced/manual) could run concurrently, each computing
  the over-cap excess from the same snapshot and together over-deleting — prune now takes a non-blocking
  lock (`config/index_prune.lock`).
- **Freshly resolved rows were unprotected**: a row whose metadata arrived between polls had
  `protected_until = NULL` until the next poll and could be cap-pruned. Prune now backfills the protection
  window for every `done` row first.
- A big OPEN-hours poll that overshoots the cap triggers a prune right away (not only hourly).

## [1.5.1] — 2026-08-23

### Added
- **Timeline ranger** — a Binance-style mini overview chart under the request-rate pane with a
  draggable / resizable window: pan and freely narrow the visible range; drag-zoom on either chart and
  the window stay in sync, double-click resets. Range buttons now go 24h / 7d / 2w / 1m / **3m**
  (API accepts `90d`/`3m`; `60d`/`2m` still work).
- The **Index page** shows the shared swarm-timeline card too (same data as the stats page and the
  Whitelist card), and the chart gained an **"Indexed hashes"** series (off by default — toggle it in
  the legend to watch the observed-hash catalogue grow during OPEN hours).

### Fixed
- Legend rows are vertically aligned (marker / label / value baselines matched).
- The brush window now positions correctly on the first data load (deferred until the ranger scale is
  committed; `setTimeout`, not rAF, so background tabs work too).
- Settings → Tracker mode explains that **Scheduled mode overrides a manually saved mode** (the
  janitor re-applies the schedule within a minute) and that the binary swap is done by
  `tracker-mode.sh`, not by the setting alone — saving `tracker_mode` used to look like it
  "didn't stick" with the schedule on.

## [1.5.0] — 2026-08-23

### Added
- **Observed-hash index** — `includes/index.php` (schema v6: `index_hashes`, `index_files`): a browsable
  catalogue of info hashes *seen* on the tracker (mostly during OPEN hours via full scrape) — **not a
  whitelist**, nothing here is served. The janitor polls `GET /scrape` (full scrape, gzip) with a
  **streaming bencode parser** (bounded memory: 1.7 M entries parse in ~3 s / 30 MB), keeps
  `complete >= index_min_seeders`, upserts in batches under a wall-clock budget, and drops rows that are
  whitelisted or banned. Lifecycle: a new row lives to `grace_until` unless its metadata resolves; a
  resolved row lives to `protected_until`, extended on every poll where it still has ≥ 1 seeder; the
  hourly pruner drops expired rows and caps the table at `index_max_rows`. The metadata worker gains a
  **second queue** (`index_table` in its conf; drained only after the whitelist queue, needs column
  grants — see `worker/README.md`), fed by a daily budget (`index_meta_daily_budget`) the janitor spreads
  across 24 h. Admin page `?action=admin-index` (`assets/js/admin-index.js`): search (hash/name/files),
  meta + lifecycle filters, S/L, details modal with file list + magnet, status card, **Poll now**, and
  bulk **Fetch metadata / Refresh S/L / Promote → whitelist / Delete**. Endpoints `admin/fetch_index`,
  `index_item`, `index_delete`, `index_promote`, `index_fetch_meta`, `index_scrape(_bulk)`,
  `index_status`, `index_poll_now`. Settings section "Index (observed hashes)"; CLI
  `whitelist_cli.php index [--tick|--poll]`; `tests/index_test.php` (36 checks). Off by default.
- **Statistics timeline** — `includes/stats_timeline.php` (schema v5: `stats_samples`,
  `stats_samples_5m`, `stats_samples_1h`, UNIX `ts`): one sample per `stats_timeline_interval`
  (30–600 s) taken by the janitor timer (reusing the shared stats cache when fresh) *and* by every
  upstream fetch of the stats page, 5-minute / hourly roll-ups and retention from the same tick,
  `GET stats_timeline&range=24h|7d|14d|30d|60d` (table picked per range, 30 s cache per range, public
  or admins-only), a shared `parseTrackerStatsXml()` / `fetchTrackerStatsXml()` used by
  `api/tracker_stats.php` too. Stock-style chart (vendored **uPlot** 1.6.32, MIT, no CDN —
  `assets/vendor/uplot/`, `assets/js/stats-timeline.js`) on the public stats page and on the admin
  Whitelist page: seeds / leechers / peers / torrents / whitelisted torrents + a synced request-rate
  panel (UDP & HTTP announces, connects, scrapes per second derived from the cumulative counters),
  OPEN-hours shading, range buttons, legend toggles, drag-zoom, auto-refresh. Settings section
  "Statistics Timeline"; CLI `whitelist_cli.php timeline [--tick]`; `tests/stats_timeline_test.php`
  (63 checks: parser, due/slack logic, roll-ups, series/rates/gaps, prune, cache reuse).

## [1.4.0] — 2026-08-19

### Added
- **Scheduled mode (whitelist hours)** — `includes/schedule.php`: per-weekday windows in a timezone
  (`tracker_schedule_enabled`, `tracker_schedule` JSON, `tracker_schedule_tz`,
  `tracker_mode_switch_cmd`); the janitor timer switches the OpenTracker build via
  `tools/opentracker/tracker-mode.sh` (binary + config symlinks, restart; sudoers snippet in README),
  flips `tracker_mode` and keeps bans consistent both ways (banned hashes → blacklist file /
  blacklist file → bans + whitelist regeneration). Settings editor with a 7-day grid, status-card
  item, CLI `whitelist_cli.php mode [--apply]`, public whitelist page + nav stay available under a
  schedule with a notice about the hours and the next change. `tests/schedule_test.php` (71 checks).
- **reCAPTCHA v3** as a third CAPTCHA provider (`captcha_provider=recaptcha_v3`,
  `recaptcha_v3_site_key`, `recaptcha_v3_secret`, `recaptcha_v3_min_score`): silent, score-based —
  no modal; the required "protected by reCAPTCHA" notice replaces the floating badge.

## [1.3.1] — 2026-08-19

### Fixed
- **`egress-budget/ottrack.nft`**: the budget chain let every non-`udp sport 6969` packet fall through
  to the rate limit — i.e. ALL outbound TCP (web, SSH, HTTP announces) shared the tracker's UDP budget
  and was dropped whenever it was exhausted. The chain now accepts `meta l4proto != udp`, loopback and
  other UDP first; only OpenTracker's UDP replies are metered. If you deployed the earlier file, reload it.
- OpenTracker HTTP side dead under load: with systemd's default `LimitNOFILE=1024` the accept queue
  fills, `accept()` fails, the main thread spins at 100 % CPU and every HTTP announce/scrape times out
  ("Tracker did not answer" in the panel, empty S/L). README now says to set `LimitNOFILE=65536` in the
  unit (drop-in) — the whole HTTP path (announce, scrape, stats) depends on it.
- Admin login / dashboard CAPTCHA modal now uses the shared placement (above centre, top on phones)
  and has a Cancel button; Settings → "Security & Credentials" is capped to the form width.

### Added
- Public whitelist page shows the official number of registered torrents.

## [1.3.0] — 2026-08-18

### Added
- **OpenTracker `udp-reject-interval` patch** (`tools/opentracker/udp-reject-interval.patch`, config
  `access.udp_reject_interval 86400`): a UDP announce for a hash the accesslist rejects gets a
  well-formed "0 peers, interval N" reply instead of upstream's truncated 8-byte packet, so compliant
  clients stop hammering the tracker for N seconds. `egress-budget/ottrack.nft` recognises that reply
  and does not treat it as a whitelisted-client reply. README documents building both binaries
  (WHITE + BLACK) and how a mode switch really works.
- Admin Whitelist: **Fetch metadata** for *missing / failed / missing+failed / all* (one UPDATE →
  the worker drains the queue at its own pace) and **Refresh S/L** for *this page / stale / all*
  (`scrapeOpenTrackerMany()`: up to 50 hashes per OpenTracker `/scrape` request, binary-safe bencode
  parser, 20 s budget with a cursor so the UI loops); endpoints `admin/whitelist_meta_queue`,
  `admin/whitelist_scrape_bulk`.
- Local blacklist-mode smoke test (`deploy/smoke_blacklist.py` in the workspace): switches the test
  install to `tracker_mode=blacklist`, report → block (file) → check → restore (unblock) → back.

### Changed
- Dashboard restyled to match the Whitelist page (wide container, fixed column widths, shared
  First/Prev/page/Next/Last pagination, contrast); wider search box; Settings header consistent.
- Public CAPTCHA modal sits above centre / near the top on phones and has a Cancel button; home-page
  "Register your torrent" CTA uses the same muted button style as the registration page.
- Admin login page shows the real error (stale CSRF → auto-reload for a fresh token, CAPTCHA
  re-shown when the first token expired, lockout message) instead of "Invalid username or password"
  for everything.

## [1.2.2] — 2026-08-18

### Changed
- Admin UX: every text input that used `window.prompt` (bulk ban reason, API client label / rename)
  is now a proper modal (`promptModal()` in `admin-common.js`); modals sit slightly above centre on
  desktop and at the top on phones; copy actions show a Popper/Bootstrap tooltip on the button
  ("Copied!") instead of a corner toast (`bootstrap.bundle.min.js` is loaded now); Whitelist page
  is ~80 vw wide on desktop (100 % on tablets/phones), details-modal key/value area and status card
  tiles restyled; hash/magnet in monospace copy boxes.
- CSP: `connect-src` also allows `https://cdn.jsdelivr.net` (DevTools source-map fetches no longer
  spam the console).

## [1.2.1] — 2026-08-18

### Added
- **Require our tracker** for public registration (`whitelist_require_tracker`, `whitelist_tracker_hosts`):
  only magnet links whose `tr=` list points at one of the configured hosts (announce-URL hosts always
  count) are accepted; bare hashes are refused with an explanatory message. Off by default; admin adds
  and the S2S API are unaffected.
- Status page → *Block check* shows a second badge in whitelist mode: **Whitelisted / Not whitelisted**
  next to Blocked / Not Blocked.
- Admin: the *Whitelist* link moved from the Reload/Restart button group into the dashboard tabs row
  (no more accidental clicks next to Restart); Whitelist page uses the full width, fixed column widths
  (no wrapping / horizontal scrollbar), readable muted labels, pagination with First/Last and a
  page-number input.

## [1.2.0] — 2026-08-18

> **Whitelist mode.** Run OpenTracker with `-DWANT_ACCESSLIST_WHITE` and let the app own the
> accesslist: public registration, server-to-server API, admin Whitelist page, metadata worker.
> Upgrade = copy the files; the database upgrades itself on the first request
> (`settings.schema_version = 2`). Existing installations keep working unchanged in blacklist mode
> (`tracker_mode` defaults to `blacklist`). See README → *Whitelist mode* for the switch-over runbook.

### Added
- **Tracker mode** setting (`blacklist` | `whitelist`). Block/unblock from reports, appeals,
  restore and permanent delete now go through mode-aware helpers (`trackerBlockHash()` /
  `trackerUnblockHash()` / `isHashBlocked()` in `includes/whitelist.php`): in whitelist mode
  "block" = **ban** (hash removed from the served list and refused on registration), "unblock" =
  lift the ban.
- **Whitelist service** (`includes/whitelist.php`): DB is the source of truth (`whitelist`,
  `banned_hashes`), the accesslist file is appended for additions and **regenerated atomically**
  (temp file + `rename`, keyset pagination) for removals; refuses to write an EMPTY file
  (OpenTracker whitelist mode is fail-closed); SIGHUP reload is **debounced** (additions ≥ 45 s
  apart, removals/bans within 15 s, ≤ 12 reloads / 5 min because OpenTracker keeps superseded
  list generations for 5 minutes) with failure backoff; append and regeneration are serialised
  on one lock; a per-request janitor plus `tools/janitor.php` (systemd timer) fire pending work
  even without web traffic. Path safety: absolute, outside the web root, no `.php`/`.htaccess`
  names, no symlinks. `tools/whitelist_cli.php` for status / bulk add / regen / import.
- **Public registration page** `?action=whitelist`: one magnet link or 40-hex hash per line,
  CAPTCHA always required (registration is disabled — fail closed — when no CAPTCHA provider is
  configured), per-IP hourly submissions, per-IP and global daily caps (IPv6 counted per /64),
  duplicate / banned checks, registrant IP stored, per-item results with a generated magnet link
  and "active in ~N s", "check status" form (`whitelist_check`). Mode-aware public copy (home,
  info, terms, status page), nav link, `tracker.redirect_url` friendly.
- **Server-to-server API** `v1/whitelist/submit` / `v1/whitelist/ping` with
  `Authorization: Bearer key_id.secret` (only `sha256(secret)` is stored, shown once at creation);
  additive-only and idempotent; **any failed authentication with an Authorization header bans the
  source IP (v4 exact / v6 /64) for `api_ban_days` = 30** and stores the whole offending request
  (headers minus secrets, body up to 256 KB) for review; no-header requests get 401 without a
  ban; disabled keys 403 without a ban; `api_ban_exempt_ips` (seeded with the server's own
  addresses) are never banned; global insert throttle on ban rows. `tools/api_client_example.py`.
- **Admin Whitelist page** (`?action=admin-whitelist`, `assets/js/admin-whitelist.js` +
  shared `assets/js/admin-common.js`): status card (mode, file health, DB counts, pending reload,
  last reload, worker heartbeat, warnings; Regenerate / Reload / Import blacklist → bans), table
  with the multi-column sort stack, hash-prefix / IP / name / file-name search (FULLTEXT with LIKE
  fallback), source / metadata / banned filters, **Group by IP** with per-IP counts, bulk delete /
  ban / fetch-metadata, details modal (magnet generator with the tracker's announce URLs, name,
  size, collapsible file tree, seeders/leechers/completed via live OpenTracker scrape, source and
  forum reference, ban reason), Banned hashes view, API clients view (create → token shown once,
  enable/disable, rename, delete), API bans view (pretty-printed request snapshot, lift, manual
  ban). All dynamic DOM is built with `textContent`/`createElement` — torrent names, file paths
  and snapshots are attacker-controlled.
- **Metadata worker** (`worker/`): `python3-libtorrent` daemon under systemd (unprivileged
  `tracker` user, hardened unit, column-level MySQL grants) that resolves torrent name / size /
  file list through DHT + trackers in upload mode into `whitelist` / `whitelist_files`;
  heartbeat file shown in the panel; rows are queued by the panel or automatically for
  API/forum/admin additions.
- **CAPTCHA provider**: Google reCAPTCHA v2 or **Cloudflare Turnstile** (`captcha_provider`,
  `turnstile_site_key`, `turnstile_secret`), one shared modal `assets/js/captcha.js` (removed the
  three duplicated copies), generic `verifyCaptcha()` / `captchaTokenFromInput()` (accepts
  `captcha_token` and the legacy `g-recaptcha-response`), siteverify calls with hard timeouts,
  CSP updated for `challenges.cloudflare.com`.
- **Schema bootstrap** `includes/schema.php` (`ensureSchema()`, advisory-locked, idempotent,
  shared with `install.php`).
- `tools/opentracker/egress-budget/` — nftables egress budget for OpenTracker replies (whitelisted
  clients always pass, the unregistered swarm shares a packet-rate cap) + `tc prio` qdisc unit that
  sends everything except tracker replies first. Measured on a VPS whose hypervisor dropped ~50 % of
  all inbound packets while the tracker answered ~90k pps; README documents the measurements and why
  per-source-IP limits do not help against a diffuse swarm.
- `tools/opentracker/sighup-udp-workers.patch` — upstream OpenTracker spawns
  `listen.udp.workers` threads before it blocks SIGHUP, so `systemctl reload` could kill the
  process instead of reloading the list; the patch blocks the signals first.

### Changed
- `api.php` resolves the endpoint before `session_start()`; `v1/*` calls are stateless (no session
  cookie) and skip the report janitors. The whitelist janitor runs on every request (pollers
  included) — it is a single small state-file read.
- `getTrackerServiceWarnings()` reports whitelist health (empty/unwritable file, pending
  regeneration or reload, failed reloads, stale worker) instead of blacklist-change counts when in
  whitelist mode; `check_block` / `submit_report` answer with `whitelisted` in whitelist mode.
- Settings page: new **CAPTCHA** (provider + Turnstile keys), **Tracker Mode & Whitelist** and
  **Server-to-server API** sections; `save_settings.php` validates the new keys.
- Dashboard / Settings headers link to the Whitelist page.

### Fixed
- Metadata worker: torrents were added with libtorrent's default `paused` flag while being taken out
  of the queue manager (`auto_managed` cleared), so they never connected to any peer and every fetch
  timed out; both flags are cleared now (86/86 forum hashes failed before, 116/160 resolve within
  seconds after). DHT bootstrap moved to the `dht_bootstrap_nodes` session setting.
- `removeHashFromBlacklist()` now writes a temp file and `rename()`s it — the previous in-place
  truncate could let OpenTracker observe an empty blacklist during a reload.

### Security
- Review fixes before release: API ban snapshots never store a credential value (only the scheme and
  the 16-hex key id of a well-formed bearer token); an authenticated client with an oversized body
  gets 413 instead of a 30-day ban; `getClientIp()` walks `X-Forwarded-For` from the right and skips
  trusted proxies (the left-most, client-supplied hop was trusted before — spoofable exempt IPs /
  rate-limit keys); IPv4-mapped IPv6 addresses (`::ffff:a.b.c.d`) are unmapped before bucketing so
  they no longer all share one `::/64` ban / rate-limit bucket; `.htaccess` passes the Authorization
  header through to php-fpm/CGI; whitelist regeneration verifies every write and the final size and
  refuses to install a truncated file; `validateWhitelistPath()` no longer treats the CLI's cwd as the
  document root; IN(...) lookups are chunked (65 535-placeholder limit on bulk CLI adds); plain names
  from the API are no longer URL-decoded twice; the Settings page gained the missing
  **Server-to-server API** section (`api_enabled`, `api_ban_days`, `api_ban_exempt_ips`).
- Bearer secrets stored hashed; API bans keyed per IPv6 /64; JSON responses/snapshots use
  `JSON_INVALID_UTF8_SUBSTITUTE`; whitelist path restrictions; admin whitelist UI free of
  `innerHTML` interpolation; forum reference links only for `http(s)` URLs with `rel="noopener"`.

## [1.1.0] — 2026-07-14

### Added
- **Automatic blacklist reload (SIGHUP).** After every panel action that changes the blacklist file
  (accept report → block, accept appeal, unblock, restore report to active, permanent delete) the app
  now runs `systemctl reload <service>` — a SIGHUP that makes OpenTracker re-read its white/blacklist
  **without downtime**. Best-effort and non-fatal; on success it clears the pending-change tracking.
  Toggle with the new **Auto-reload blacklist** setting (`opentracker_auto_reload`, default on).
- **Reload button** in the Dashboard header (password-confirmed, with a confirm modal like Restart)
  and a matching `admin/reload_tracker` endpoint.
- **Permission Test buttons** in Settings for both restart and reload (`admin/test_tracker_permission`).
  They run a read-only `sudo -n -l` check — never restarting or reloading anything — and print
  copy-paste sudoers fixes when a rule is missing.

### Fixed
- **Restore to active now really unblocks.** Restoring an archived, blocked report to active set the
  database to unblocked but left the info hash in the blacklist file, so the tracker kept blocking it.
  It is now removed from the blacklist file (when nothing else keeps that hash blocked) and the tracker
  is reloaded.

### Notes
- New re-runnable, data-only migration: `sql/2026-07-14_opentracker_reload.sql`.
- For reload, add a `systemctl reload` sudoers rule and an `ExecReload=/bin/kill -HUP $MAINPID` line to
  the unit — see [OpenTracker service reload & restart](README.md#opentracker-service-reload--restart).

## [1.0.0] — 2026-07-09

First public release.

### Features
- Public site: abuse/DMCA report submission (magnet → info-hash extraction), report-status
  lookup, block check, appeal system, transparency page, configurable ToS.
- Admin panel: sortable/searchable/paginated dashboard, report workflow (pending → reviewed →
  blocked/archived), inline editing, appeal management with auto-close, auto-archiving.
- Blacklist integration with a newline-separated hash file, with path/permission testing.
- **OpenTracker service control**: optional one-click `systemctl restart` of the tracker
  service (password-confirmed) plus smart, stacking restart recommendations (orange/red) driven
  by pending blacklist changes since boot and by uptime thresholds.
- Tracker statistics with a shared, TTL'd server-side cache and configurable Live Syncs counter.
- Email system: submission/under-review/status/appeal notifications, per-type preferences,
  RFC 8058 one-click unsubscribe.
- Donations with up to 15 custom fields (backward-compatible with legacy BTC/ETH/XMR settings).

### Security
- CSRF on all writes, per-IP login lockout + rate limiting, admin session idle/absolute timeouts,
  bcrypt password hashing, HMAC-signed unsubscribe tokens, prepared statements throughout,
  strict output escaping, per-directory `.htaccess` protection (Nginx equivalents documented),
  reverse-proxy-aware client IP resolution, and no secrets committed to source.

### Notes
- All configuration lives in the database `settings` table and is managed from the web UI.
- Database changes ship as re-runnable, data-only migrations under `sql/` — see the
  [Updating](README.md#updating) section.
