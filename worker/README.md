# tracker-metadata worker

Small daemon that fills in torrent **name / total size / file list** for hashes on the tracker
whitelist. The tracker web app only ever knows the info hash (and, for magnets, the `dn=` name);
this worker resolves the real metadata through **DHT + trackers** with libtorrent in
`upload_mode` (it never downloads payload — only the few KB of metadata) and stores it in the
`whitelist` / `whitelist_files` tables, where the admin panel shows and searches it.

Queue = rows with `meta_status = 'pending'` (set by the panel's *Fetch details*, by API/forum/admin
additions, or by the *Fetch metadata* bulk action). Heartbeat = mtime of a file the panel stats.

## Install (Debian 13)

```bash
sudo apt install -y python3-libtorrent python3-pymysql
sudo install -d -o tracker -g tracker -m 0755 /home/tracker/metadata_worker
sudo install -o tracker -g tracker -m 0755 worker/worker.py /home/tracker/metadata_worker/worker.py
sudo install -o root -g tracker -m 0640 worker/tracker-metadata.conf.example /etc/tracker-metadata.conf   # edit db password
sudo install -m 0644 worker/tracker-metadata.service /etc/systemd/system/tracker-metadata.service
```

Dedicated MySQL user with **column-level** grants (the worker parses data from arbitrary peers —
keep its blast radius small; it must not be able to touch `info_hash`/`banned` or the list file):

```sql
CREATE USER 'tracker_meta'@'localhost' IDENTIFIED BY '<random>';
GRANT SELECT (id, info_hash, magnet_link, meta_status, meta_claim, meta_claimed_at, meta_priority, meta_requested_at),
      UPDATE (name, total_size, files_count, piece_length, meta_status, meta_claim, meta_claimed_at, meta_fetched_at, meta_error)
      ON tracker.whitelist TO 'tracker_meta'@'localhost';
GRANT SELECT, INSERT, DELETE ON tracker.whitelist_files TO 'tracker_meta'@'localhost';
FLUSH PRIVILEGES;
```

### Optional second queue — the observed-hash index (1.5.0)

To let the worker also resolve metadata for the **observed-hash index** (`includes/index.php` — the
catalogue of hashes seen on the tracker, never served), set `index_table = index_hashes` in the conf
and grant the same column-level access on the index tables. The worker drains this queue only after the
whitelist queue is empty, and only rows whose `meta_requested_at` is due (the janitor spreads them
across the day under `index_meta_daily_budget`). Leave `index_table` empty to keep whitelist-only behaviour.

```sql
GRANT SELECT (info_hash, meta_status, meta_claim, meta_claimed_at, meta_priority, meta_requested_at),
      UPDATE (name, total_size, files_count, piece_length, meta_status, meta_claim, meta_claimed_at, meta_fetched_at, meta_error, meta_source)
      ON tracker.index_hashes TO 'tracker_meta'@'localhost';
GRANT SELECT, INSERT, DELETE ON tracker.index_files TO 'tracker_meta'@'localhost';
FLUSH PRIVILEGES;
```

Note: `index_hashes` has no `magnet_link` column (the worker only selects `info_hash` from the index and
builds the magnet from the hash), so the grant above lists exactly the columns it reads — a column-level
`GRANT` naming a non-existent column is rejected by MariaDB, so do not add `magnet_link` here.
`meta_source` exists from schema v7 (1.6.0) — on an older DB let the web app migrate first (any page
view runs `ensureSchema`), then apply the grant.

### Optional — federation importer (1.6.0)

`federation.py` pulls **resolved index metadata from peer trackers** (Settings → *Federation /
Cluster* in the panel; endpoint `v1/federation/export` on the peer side) and merges it into
`index_hashes` / `index_files`, so hashes a peer already resolved never hit the DHT again. It runs
as a **one-shot systemd timer** (not a daemon) and reads all its knobs (`fed_enabled`, peers,
cursors) live from the web app's database:

```bash
sudo install -o tracker -g tracker -m 0755 worker/federation.py /home/tracker/metadata_worker/federation.py
sudo install -m 0644 worker/tracker-federation.service /etc/systemd/system/tracker-federation.service
sudo install -m 0644 worker/tracker-federation.timer   /etc/systemd/system/tracker-federation.timer
sudo systemctl daemon-reload
sudo systemctl enable --now tracker-federation.timer
# one manual pass with logs:
sudo -u tracker python3 /home/tracker/metadata_worker/federation.py /etc/tracker-metadata.conf
```

Extra grants for `tracker_meta` (full-row INSERT on `index_hashes` is needed because imported
hashes may be brand-new rows when *accept new hashes* is on):

```sql
GRANT SELECT ON tracker.settings TO 'tracker_meta'@'localhost';
GRANT SELECT (info_hash) ON tracker.whitelist TO 'tracker_meta'@'localhost';
GRANT SELECT (info_hash) ON tracker.banned_hashes TO 'tracker_meta'@'localhost';
GRANT SELECT, UPDATE ON tracker.fed_peers TO 'tracker_meta'@'localhost';
GRANT SELECT, INSERT, UPDATE ON tracker.index_hashes TO 'tracker_meta'@'localhost';
FLUSH PRIVILEGES;
```

(The `index_files` grant from the second-queue section already covers the importer's file writes.
`python3 federation.py --self-test` runs its offline validation tests.)

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now tracker-metadata
journalctl -u tracker-metadata -f
```

The panel's status card shows the heartbeat age; *Fetch details* on a live torrent should finish
within `timeout_seconds` (default 90 s; the example conf uses 180 s) — a live torrent usually resolves in 2–10 s. Torrents with no reachable peers end as `failed`
(timeout) and can be retried with *Refresh metadata*.

Privacy note: resolving metadata means announcing this server's IP + the hash to DHT and the
configured trackers — the hashes are already public (registered / posted on the forum). Incoming
port `listen_port` may stay firewalled; DHT replies pass through conntrack.
