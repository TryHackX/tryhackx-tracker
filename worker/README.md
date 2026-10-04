# tracker-metadata worker

Small daemon that fills in torrent **name / total size / file list** for hashes on the tracker
whitelist — and, optionally, for the observed-hash index. The tracker web app only ever knows the
info hash (and, for magnets, the `dn=` name); this worker resolves the real metadata through
**DHT + trackers** with libtorrent in `upload_mode` (it never downloads payload — only the few KB of
metadata) and stores it in the `whitelist` / `whitelist_files` tables (and `index_hashes` /
`index_files`), where the admin panel and the public pages show and search it.

Queue = rows with `meta_status = 'pending'` (set by the panel's *Fetch details*, by API/forum/admin
additions, by the *Fetch metadata* bulk action, or — for the index — by the janitor under
`index_meta_daily_budget`).

**Heartbeat** = one JSON line the worker rewrites every tick in `heartbeat_file` (default
`/home/tracker/metadata_worker/heartbeat`): the time, its pid and version, the concurrency and file
cap it is really running (and what its conf file alone would give), how many fetches are active and
which fetch order it follows. The panel reads its age and its contents from
`/home/tracker/metadata_worker/heartbeat` — keep that path, the panel has no field for another one.

## Requirements

Python 3.7+, the libtorrent 2.x bindings and PyMySQL:

```bash
sudo apt install -y python3-libtorrent python3-pymysql
```

## Install (Debian 12 / 13)

```bash
sudo install -d -o tracker -g tracker -m 0755 /home/tracker/metadata_worker
sudo install -o tracker -g tracker -m 0755 worker/worker.py /home/tracker/metadata_worker/worker.py
sudo install -o root -g tracker -m 0640 worker/tracker-metadata.conf.example /etc/tracker-metadata.conf
sudoedit /etc/tracker-metadata.conf     # the [db] password; put YOUR tracker first in trackers=
sudo install -m 0644 worker/tracker-metadata.service /etc/systemd/system/tracker-metadata.service
```

The web user must be able to reach the heartbeat: `/home/tracker` needs `o+x`
(`sudo chmod 0755 /home/tracker` — `useradd -m` makes it 0750 on Ubuntu). The shipped unit runs with
`UMask=0077`, so the heartbeat file comes out 0600 and the panel can see how old it is but not what
it says. It holds nothing secret; let the panel read it:

```bash
sudo systemctl edit tracker-metadata      # add the two lines below, save
# [Service]
# UMask=0022
```

Dedicated MariaDB user with **column-level** grants (the worker parses data from arbitrary peers —
keep its blast radius small; it must not be able to touch `info_hash`/`banned` or the list file):

```sql
CREATE USER 'tracker_meta'@'localhost' IDENTIFIED BY '<random>';
GRANT SELECT (id, info_hash, magnet_link, meta_status, meta_claim, meta_claimed_at, meta_priority, meta_requested_at),
      UPDATE (name, total_size, files_count, piece_length, meta_status, meta_claim, meta_claimed_at, meta_fetched_at, meta_error)
      ON tracker.whitelist TO 'tracker_meta'@'localhost';
GRANT SELECT, INSERT, DELETE ON tracker.whitelist_files TO 'tracker_meta'@'localhost';
-- Recommended: the worker re-reads its panel settings every ~60 s — `meta_worker_concurrency` and
-- `meta_max_files` (they override the conf's `concurrency` / `max_files`), the fetch order
-- (`meta_order_mode`, `meta_order_mix_*`) and `db_time_zone`. The grant is on the whole table, so a
-- new key needs no new grant. Without it the worker silently keeps its conf-file values, and the
-- Index page's status card shows what the worker is really running so the difference is visible.
GRANT SELECT ON tracker.settings TO 'tracker_meta'@'localhost';
FLUSH PRIVILEGES;
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now tracker-metadata
journalctl -u tracker-metadata -f
```

A restart is needed only after editing the conf file or replacing `worker.py`; the panel settings
above reach a running worker by themselves. Keep the unit's name: the panel's CPU card on the
Traffic page looks for `tracker-metadata.service`.

### Optional second queue — the observed-hash index (1.5.0)

To let the worker also resolve metadata for the **observed-hash index** (`includes/index.php` — the
catalogue of hashes seen on the tracker, never served), set `index_table = index_hashes` in the conf
and grant the same column-level access on the index tables. How the worker splits its fetches between
the two queues is **Settings → Index → Metadata fetch order** (read live, as above): the whitelist
can have the whole of it or a share, and the index's rows are taken only once their
`meta_requested_at` is due (the janitor spreads them across the day under `index_meta_daily_budget`).
Leave `index_table` empty to keep whitelist-only behaviour.

```sql
GRANT SELECT (info_hash, meta_status, meta_claim, meta_claimed_at, meta_priority, meta_requested_at,
              last_seeders, seen_count, last_completed),
      UPDATE (name, total_size, files_count, piece_length, meta_status, meta_claim, meta_claimed_at, meta_fetched_at, meta_error, meta_source)
      ON tracker.index_hashes TO 'tracker_meta'@'localhost';
GRANT SELECT, INSERT, DELETE ON tracker.index_files TO 'tracker_meta'@'localhost';
FLUSH PRIVILEGES;
```

`last_seeders`, `seen_count` and `last_completed` are what the *seeders*, *seen* and *completed* fetch
orders sort by: without them MariaDB refuses those claims. `index_hashes` has no `magnet_link` column
(the worker builds the magnet from the hash), and a column-level `GRANT` naming a column that does not
exist is rejected, so do not add it here. `meta_source` exists from schema v7 (1.6.0) — on an older
database let the web app migrate first (the first page view runs `ensureSchema`), then apply the grant.

**How long a stored file list may be** is one number for both queues — `finish()` is shared, so
`max_files` (conf) / `meta_max_files` (panel, 1.38.0) governs `whitelist_files` and `index_files`
alike. The conf's own `index_keep_files = 0` skips file rows for **index** rows entirely (the panel's
*Keep file lists* switch is read by `federation.py` and the panel, not by this worker); whitelist rows
always keep their list. The torrent's real `files_count` is stored unclipped either way, which is what
lets the panel and the public pages say *5 000 of 18 000*.

### Optional — federation importer (1.6.0)

`federation.py` pulls **resolved index metadata from peer trackers** (Settings → *API & federation →
Federation / Cluster*; endpoint `v1/federation/export` on the peer side) and merges it into
`index_hashes` / `index_files`, so hashes a peer already resolved never hit the DHT again. It runs as a
**one-shot systemd timer** (not a daemon) and reads all its knobs (`fed_enabled`, the peers, their
cursors, whether new hashes are accepted or reviewed) live from the web app's database:

```bash
sudo install -o tracker -g tracker -m 0755 worker/federation.py /home/tracker/metadata_worker/federation.py
sudo install -m 0644 worker/tracker-federation.service /etc/systemd/system/tracker-federation.service
sudo install -m 0644 worker/tracker-federation.timer   /etc/systemd/system/tracker-federation.timer
sudo systemctl daemon-reload
sudo systemctl enable --now tracker-federation.timer
# one manual pass with logs:
sudo -u tracker python3 /home/tracker/metadata_worker/federation.py /etc/tracker-metadata.conf
```

Extra grants for `tracker_meta` (full-row INSERT on `index_hashes` is needed because imported hashes
may be brand-new rows when *accept new hashes* is on; `fed_review` holds them when a person reviews
them first, and `--purge` empties a peer's share of it):

```sql
GRANT SELECT ON tracker.settings TO 'tracker_meta'@'localhost';
GRANT SELECT (info_hash) ON tracker.whitelist TO 'tracker_meta'@'localhost';
GRANT SELECT (info_hash) ON tracker.banned_hashes TO 'tracker_meta'@'localhost';
GRANT SELECT, UPDATE ON tracker.fed_peers TO 'tracker_meta'@'localhost';
GRANT SELECT, INSERT, UPDATE ON tracker.index_hashes TO 'tracker_meta'@'localhost';
GRANT SELECT, INSERT, DELETE ON tracker.fed_review TO 'tracker_meta'@'localhost';
FLUSH PRIVILEGES;
```

(The `index_files` grant from the second-queue section already covers the importer's file writes.)

`federation.py` options: `--self-test` (offline validation tests, then exit), `--loop` (run for ever,
sleeping `fed_pull_minutes` between passes — the timer is the usual way), `--peer NAME` (one peer
only), `--force` (ignore `fed_enabled` and the peer's pull switch), `--purge PEER` (remove what that
peer gave, with `--dry-run` to count first), `--max-seconds N` and `--mem-mb N` (bounds for one pass).
The timer's 60 minutes match the panel's default `fed_pull_minutes`; with the timer, the timer decides.

## The conf file

`/etc/tracker-metadata.conf`, owned `root:tracker`, mode 0640 (it holds the database password).
`[db]`: `host`, `name`, `user`, `password`. `[worker]` (the code's defaults in brackets; the example
file sets some differently):

| key | meaning |
|---|---|
| `concurrency` [3] | parallel fetches, 1–64 (the panel's `meta_worker_concurrency` overrides it live) |
| `timeout_seconds` [90] | how long one fetch may take, 20–600 |
| `poll_interval` [3] | seconds between looks at the queue |
| `stale_claim_minutes` [10] | a claim older than this is taken back |
| `heartbeat_file` | keep the default — see above |
| `listen_port` [6881] | may stay firewalled; DHT replies pass through conntrack |
| `trackers` | announce URLs to ask — your own tracker first |
| `max_files` [5000] | file paths stored per torrent, 1–50000 (the panel's `meta_max_files` overrides it live) |
| `index_table` / `index_files_table` / `index_keep_files` | the second queue, above |
| `dht_routers` | DHT bootstrap nodes |
| `download_rate_limit` [262144] | libtorrent's download limit, bytes a second |
| `connections_limit` [200] | libtorrent's connection limit |
| `tmp_dir` | libtorrent's scratch directory |
| `log_level` [INFO] | |

The panel's status card shows the heartbeat; *Fetch details* on a live torrent should finish within
`timeout_seconds` — a live torrent usually resolves in 2–10 s. Torrents with no reachable peers end as
`failed` (timeout) and can be retried with *Refresh metadata*.

Privacy note: resolving metadata means announcing this server's IP + the hash to DHT and the
configured trackers — the hashes are already public (registered, or seen in this tracker's own
swarms). The worker stores no peer addresses: only the name, size, piece length and file list.
