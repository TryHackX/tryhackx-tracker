# Changelog

All notable changes to this project are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/), and the project aims to follow
[Semantic Versioning](https://semver.org/).

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
