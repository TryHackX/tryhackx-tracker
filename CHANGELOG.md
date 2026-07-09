# Changelog

All notable changes to this project are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/), and the project aims to follow
[Semantic Versioning](https://semver.org/).

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
