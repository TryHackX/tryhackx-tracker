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

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now tracker-metadata
journalctl -u tracker-metadata -f
```

The panel's status card shows the heartbeat age; *Fetch details* on a live torrent should finish
within `timeout_seconds` (default 90 s). Torrents with no reachable peers end as `failed`
(timeout) and can be retried with *Refresh metadata*.

Privacy note: resolving metadata means announcing this server's IP + the hash to DHT and the
configured trackers — the hashes are already public (registered / posted on the forum). Incoming
port `listen_port` may stay firewalled; DHT replies pass through conntrack.
