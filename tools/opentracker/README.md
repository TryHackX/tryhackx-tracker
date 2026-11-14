# The tracker itself — the two opentracker builds

The panel is an admin interface for [opentracker](https://erdgeist.org/arts/software/opentracker/).
This directory holds the tracker binaries this project is developed and tested against, the root
helpers the panel calls, and everything needed to rebuild the binaries from source instead of trusting
them.

Since 1.94.2 the binaries are **opentracker 2.2.1** (released 2026-11-10), **unmodified**: no patches,
only the build flags below. The sections from *A full scrape that loses its own framing* on — all but
the short *After the first start* — are the project's **internal lab notes** of 2026-09 and 2026-10
about the builds before 2.0.0 (upstream commit 1c7fac4 with libowfat 0.34): the paths are the
reference VPS's, the scripts lived in `/tmp`. They are kept because they are the evidence behind fixes
that are now upstream. They are not instructions; the guide is [INSTALL.md](../../INSTALL.md) §5 and §9.

**Tested on Debian 13 (trixie)**, x86-64, gcc 14.2.0, glibc 2.41, kernel 6.12. They are plain
dynamically linked ELF executables needing only `libz` and `libc`. They use glibc's `arc4random`, so
they need **glibc 2.36 or newer** (Debian 12/13, Ubuntu 24.04+).

---

## What is here

| File | What it is |
| --- | --- |
| `bin/opentracker.white` | whitelist build — serves **only** hashes in the accesslist file |
| `bin/opentracker.black` | blacklist build — serves everything **except** the hashes in the file |
| `UPSTREAM-REPORT.md` | **internal**: the bug report sent to the author on 2026-09-12 about the 1.0-era code; 2.0.0 fixes what it describes |
| `egress-budget/ottrack.nft` | the reply-rate budget: kernel sets of the clients answered in the last 3 h, replies to them first (see the Traffic page); installed by hand |
| `egress-budget/tracker-egress-prio.sh` / `.service` | traffic control on the uplink for the same budget; installed by hand, if at all |
| `tracker-mode.sh` | switches the `opentracker` / `opentracker.conf` symlinks white↔black and restarts (`[--all\|--instance N] white\|black\|status`); the schedule and the running-mode check |
| `tracker-netlimit.sh` | the inbound UDP limit in its own nftables table, address lists, the egress rate, the stability probe (`probe-start`) and the janitor's slow half (`janitor-heavy-start`) |
| `tracker-instance.sh` | opentracker's workers, open files, CPU scheduling and restart (*OpenTracker — performance*) |
| `tracker-backup.sh` | backups: run, list, verify, prune, delete, restore, `restore-db` |
| `tracker-sysctl.sh` | the kernel's network buffers, armed and then confirmed (or reverted after 120 s) |
| `tracker-cluster.sh` | extra opentracker instances on ports of their own (IPv4 only) |
| `tracker-dbmem.sh` | (1.37.0) the database's memory — seven MariaDB/MySQL keys, live where the engine allows, a drop-in for the rest |
| `tracker-livesync.sh` | live peer sync with a second machine (needs a `-DWANT_SYNC_LIVE` build — not these — and WireGuard) |

```
sha256  bbd33ecdab8c1f1b1019e683a12473220a5a4d69eb6e8be6a2ff9431ada0d6dc  opentracker.white
sha256  1091a5f8c93985c1d9290a9ab6e61639b30154e15058c81a5ab96d4bddbef1fa  opentracker.black
```

Both are 113 024 bytes, stripped, built with upstream's hardening (PIE, full RELRO,
`-fstack-protector-strong`, `_FORTIFY_SOURCE=2`) — the 2026-11-13 builds of opentracker 2.2.1 with the
flags below and no patches. They replace the 2.2.0 builds of 1.94.0, which followed the 2.1.1 builds of
1.93.1, the 2.1.0 builds of 1.93.0 and the 2026-11-04 builds of 2.0.0 with three patches of ours, which
opentracker carries its own way since 2.1.0 (the table below). Before they were shipped they passed
upstream's whole suite (`make check-all CC_sanitize=gcc`): 701 unit checks, again under AddressSanitizer
and UBSan — two cleaner tests step a torrent back 45 minutes and fail on a machine that has been up for
less than that; ours had been up for four days — 86 for the poll() event loop, and 136 integration tests
plain, with live sync and under ThreadSanitizer (20 skipped by the suite itself where live sync is not
built in) — a lab of 28 checks on a spare port: the empty-swarm reply for a refused hash in both modes
and its interval, the first announce after a start against a 300 000-line list in both modes, the
full-scrape restriction (200 for the address in `access.fullscrape`, 403 for any other, gzipped too, and
for the `tpbs` and `statedump` dumps of `/stats`), the process uptime in `/stats`, a SIGHUP reload under
a changed list, a leftover `access.udp_reject_interval` line (a warning, nothing else) and a clean
shutdown — and 19 more for the single peer list of 2.2.0: one torrent with IPv4 and IPv6 peers over UDP
and HTTP (a UDP reply carries only the asker's family, HTTP splits `peers` and `peers6`), scrape and
full-scrape counts over both families, stopped and completed events, the state dump, the retired `s24s`
report refused with 400, an IPv6-only torrent in the `top100`, `tpbs` and `everything` reports,
`tracker.empty_torrent_timeout`, and the same `/stats` XML element paths as 2.2.0. With 150 000 torrents
of one IPv4 peer each, 2.2.1 used 155 bytes per torrent and 2.2.0 154; 2.1.1 used 218.

### Why two binaries and not one switch

**White or black is a compile-time decision in opentracker.** `WANT_ACCESSLIST_WHITE` and
`WANT_ACCESSLIST_BLACK` are mutually exclusive `#ifdef`s: the same source builds either a tracker
that serves only what is listed or a tracker that serves everything but what is listed, and there is
no runtime flag that switches between them. That is why switching modes here means switching which
binary a symlink points at, and why the panel restarts the service to do it.

---

## Base version

```
upstream   git://erdgeist.org/opentracker   (web: https://erdgeist.org/gitweb/opentracker)
release    2.2.1 — tag v2.2.1 = commit 530d3ee20a06f2a128b1eddaf2398011d3f1d119 (2026-11-10)
tarball    https://erdgeist.org/arts/software/opentracker/opentracker-2.2.1.tar.bz2
sha256     be4318e50affa65000e80963a6d05245d32cd4b73875df19aeec2aeee81c8fc7
```

The tarball is identical to the tag; it only adds `.tarball-version`. Since 2.0.0 opentracker needs no
libowfat, and a default build links only zlib. A binary states its version and build flags itself —
and nothing is reported over HTTP any more, so the Stats page hides its *Tracker ID* and *Version* rows
when the tracker does not send them:

```bash
./opentracker.black -v
```

```
opentracker 2.2.1
https://erdgeist.org/gitweb/opentracker/tag/?h=v2.2.1
events: epoll
features: WANT_ACCESSLIST_BLACK WANT_COMPRESSION_GZIP WANT_FULLSCRAPE WANT_MODEST_FULLSCRAPES WANT_RESTRICT_STATS WANT_RESTRICT_FULLSCRAPE
```

---

## Feature flags

```
-DWANT_FULLSCRAPE            /scrape returns the whole torrent list (the panel's index is built from it)
-DWANT_COMPRESSION_GZIP      …gzipped, which is what makes a 30 MB scrape a 1.5 MB transfer
-DWANT_RESTRICT_STATS        access.stats / access.stats_path — /stats limited to named IPs and moved
                             off the default path
-DWANT_RESTRICT_FULLSCRAPE   a full scrape — /scrape without an info_hash, and the tpbs and statedump
                             dumps of /stats — only for addresses named in access.fullscrape; anyone
                             else gets 403 before the limiter has counted them
-DWANT_MODEST_FULLSCRAPES    one full scrape per client per 5 minutes; the rest get HTTP 402
-DOT_BUCKET_COUNT_BITS=14    16 384 torrent buckets instead of 1 024: with millions of torrents an insert
                             moves far fewer bytes, for about 2 % more memory (upstream's DESIGN file)
-DWANT_ACCESSLIST_WHITE      ── white build only ──
-DWANT_ACCESSLIST_BLACK      ── black build only ──
```

Deliberately **not** enabled:

- `WANT_SYNC_LIVE` — live peer sync between two trackers. There is only one machine, and the protocol
  has no authentication whatsoever, so it has to run inside a tunnel before it is worth having.
  The panel's Live peer sync section detects its absence and says so.
- `WANT_IP_FROM_PROXY` — the tracker is reached directly, so trusting a header would only let a
  client claim any address it liked (and 2.0.0 has taken the proxy out of service anyway).
- `WANT_V4_ONLY` — the default build is dual-stack.
- `WANT_SYSLOGS` — per-announce logging on a tracker serving ~100k packets a second is a
  disk-filling machine, not a diagnostic.
- `WANT_SPOT_WOODPECKER` — counting the networks that re-announce within 20 seconds.

`-v` is the way to check a binary's feature set. `-h` prints one fixed usage text whatever the build,
and `/stats` renders sections for features that are not compiled in. The config keywords a build
understands are in the binary:

```bash
strings opentracker.white | grep -x 'access\.\(whitelist\|blacklist\|stats_path\|fullscrape\)'
```

---

## What 2.1.0 does in place of our patches

Up to 1.92.x three patches of ours were applied. The author took each of them into 2.1.0 his own way,
so the builds are plain upstream now:

| Retired patch | In 2.1.0 |
| --- | --- |
| `udp-reject-interval` — a refused UDP announce got a 72-byte BEP 15 error, so clients retried on their own schedule | a refused UDP announce gets a well-formed announce reply for an empty swarm (20 bytes) whose interval is a constant **two hours plus or minus three minutes** (7 020–7 379 s); upstream measured it against libtorrent and Transmission (DESIGN). Our configurable `access.udp_reject_interval` (a day here) is gone with the patch: a day also kept away the clients of a torrent that had just been whitelisted. The accesslist is now read before any thread starts, so the first announce after a start is judged against the whole list |
| `opentracker-fullscrape-gate` — a full scrape only for loopback and `access.stats` | `-DWANT_RESTRICT_FULLSCRAPE` with `access.fullscrape` lines. Nothing is allowed implicitly, loopback included, so the config names `127.0.0.1` and `::1` |
| `opentracker-2.0.0-fixes` — a backward clock step, pipelined `/stats`, a failed allocation | the cheap clock is monotonic now, and every elapsed time is clamped at zero in all its consumers, with tests; the `/stats` branch drops its read interest while its task is pending; peer lists are allocated zeroed |

HTTP announces for a refused hash still get the explicit "not authorized" failure reason.
`egress-budget/ottrack.nft` tells a refused client's reply from a real announce by that interval
(7 020–7 379 s, or 86 400 from the old builds) and does not count it as a whitelisted client's.

### What 2.2.1 changed

2.2.1 (2026-11-10) is a small release on top of 2.2.0:

- the completed event — which writes a syslog line in a `-DWANT_SYSLOGS` build — is issued after the
  torrent's bucket lock is released, so a slow syslog cannot stall the bucket; these builds have no
  syslog, so for them only the moment a counter is incremented moves;
- the man page is fixed where mandoc's linter complained, and the DESIGN document gained an overview of
  the memory access pattern.

### What 2.2.0 changed

2.2.0 (2026-11-08) is about memory, plus the findings of the reviews after 2.1.1:

- a torrent keeps one peer list for both address families, created when its first peer arrives; the
  peer count is no longer cached, the last-seen minute and the download count live on the torrent, and
  the remaining counters are 32 bit — with one IPv4 peer per torrent our lab measured 156 bytes a
  torrent against 218 for 2.1.1;
- `tracker.empty_torrent_timeout` (minutes, at most and by default 1 440) sets how long a torrent with
  no peers left is kept for its download count; the configs here keep the default;
- the `s24s` busy-network report is gone (on the way there it crashed on an IPv6-only torrent, which we
  reported); `/stats?mode=s24s` now gets 400;
- the tracker refuses to start when an essential worker thread cannot be created, and a full scrape
  stops when its gzip stream could not be initialised;
- config parsing casts bytes before `isspace()` and bounds the listen-option token; a worker that fails
  to start no longer leaks its buffer; an unchecked error-counter index, a torrent-count underflow at
  shutdown and some format strings are fixed.

### What 2.1.1 fixed

2.1.1 (2026-11-06) is a fix release, and part of it answers our reports about 2.1.0:

- on a `-DWANT_KEEPALIVE` build, a request the stats or full-scrape worker had taken over was read
  again and again by the main thread (one core at 100 %, no other client served, SIGINT ignored);
  the read loop now stops once a worker owns the connection, and also when a handler closed the
  connection inline, which was a use-after-free;
- the state file holds the wall-clock minute a torrent was last touched again — the format
  opentracker always wrote, which 2.1.0 had turned into an age — and `load_state` bounds what it
  accepts;
- URL decoding is bounded by its destination, and a request shorter than `GET /` or `scrape HTTP/`
  is no longer compared past its end;
- a failed shrinking `realloc` keeps the old block; an empty but present changeset after a reload no
  longer walks a null array (dynamic accesslists); the live-sync watermark is a whole IPv6 peer;
- a tracker started as root also drops its supplementary groups;
- the default build is hardened — PIE, full RELRO, `-fstack-protector-strong`, `_FORTIFY_SOURCE=2` —
  for under 2 % on the announce path by upstream's measurement.

### What 2.0.0 fixed upstream

The six patches of the 1.0-era builds were retired with 2.0.0, which fixes each of them its own way
(commits in `git://erdgeist.org/opentracker`):

| Retired patch | In 2.0.0 |
| --- | --- |
| `sighup-udp-workers` — `systemctl reload` killed the tracker | the signals are blocked before the first thread exists (2c7659e) |
| `opentracker-review-fixes` — seven fixes | connection-id secret from `arc4random` (e4b6dec, ecb1f1e); the replaced accesslist kept for its grace period (65e3cb6, 231f8c9); a `/stats` task cancelled with its connection (6815f7a, 79f4110, 37f21ef); atomic torrent count (fea25ce); the one-byte write in the tpbs path (656be1f); UDP workers started after init (a0363c2, 2c7659e); the parser's mmap bound (ba54aca). The synchronous first load followed in 2.1.0 |
| `opentracker-batch-realloc` — the 2026-10 crash | fixed the same way (3f7d4f4), then the batching was rewritten (0e3a218) |
| `libowfat-no-zerocopy` — corrupted chunked `/scrape` | libowfat is gone; replies go out with a plain `sendmsg()` |

---

## Building it yourself

Nothing here needs the panel; this is the whole recipe.

```bash
sudo apt install -y build-essential zlib1g-dev bzip2 wget python3
mkdir -p ~/build && cd ~/build
wget https://erdgeist.org/arts/software/opentracker/opentracker-2.2.1.tar.bz2
echo "be4318e50affa65000e80963a6d05245d32cd4b73875df19aeec2aeee81c8fc7  opentracker-2.2.1.tar.bz2" | sha256sum -c -
tar -xjf opentracker-2.2.1.tar.bz2 && cd opentracker-2.2.1
make check
make check-integration
```

`make check` runs upstream's unit tests and `make check-integration` drives a test build over the
wire (Python 3, standard library only, free ports on 127.0.0.1). `make check-all CC_sanitize=gcc`
runs every suite: the poll() loop, AddressSanitizer/UBSan, live sync and ThreadSanitizer too. Run the
test targets **without** our flags: they bring their own (the blacklist build among them).

Then build each mode into its own binary:

```bash
F="-DWANT_FULLSCRAPE -DWANT_COMPRESSION_GZIP -DWANT_RESTRICT_STATS -DWANT_RESTRICT_FULLSCRAPE -DWANT_MODEST_FULLSCRAPES -DOT_BUCKET_COUNT_BITS=14"
make FEATURES="$F -DWANT_ACCESSLIST_WHITE" opentracker && mv opentracker opentracker.white
make FEATURES="$F -DWANT_ACCESSLIST_BLACK" opentracker && mv opentracker opentracker.black
./opentracker.white -v
./opentracker.black -v
```

`FEATURES` on the command line replaces the Makefile's own list, which is why `-DWANT_FULLSCRAPE` and
`-DWANT_COMPRESSION_GZIP` are in `F`; zlib is linked either way. No `make clean` is needed between the
two: opentracker compiles in one step, and a stamp file notices when the flags change.

The build is **not** byte-reproducible — the build paths are compiled in — so a rebuild will not match
the sha256 above. What must match is the feature set, and `-v` is how you check it.

---

## Installing them

Both binaries live side by side and a symlink picks the mode. The panel's mode switch moves that
symlink through `tracker-mode.sh` and restarts the service; nothing else on the machine changes.

```bash
sudo install -o tracker -g tracker -m 0755 bin/opentracker.white /home/tracker/opentracker.white
sudo install -o tracker -g tracker -m 0755 bin/opentracker.black /home/tracker/opentracker.black
sudo ln -sfn /home/tracker/opentracker.black      /home/tracker/opentracker
sudo ln -sfn /home/tracker/opentracker.conf.black /home/tracker/opentracker.conf
```

Replacing the binaries of a running tracker takes effect at the next restart of the service (and of
every extra instance); until then `/proc/<pid>/exe` reads "(deleted)" and the mode check reports
"unknown". A restart empties the swarm — opentracker keeps everything in memory — and it refills as
the clients announce again. Keep the previous binaries next to the new ones for an undo.

The two config files, the systemd unit, the accesslist directory and the sudoers line that lets the
panel switch modes are all in **[INSTALL.md](../../INSTALL.md) §5 and §9** — that is the guide to
follow; this file is the part about the binaries themselves.

A minimal config for either mode (opentracker does not strip a comment at the end of a line, so there
are none):

```conf
listen.udp.workers 4
access.whitelist /home/tracker/accesslist/whitelist
access.stats 127.0.0.1
access.stats_path stats-pick-something-unguessable
access.fullscrape 127.0.0.1
access.fullscrape ::1
tracker.redirect_url https://tracker.example.org/?action=whitelist
```

Swap `access.whitelist` for `access.blacklist` in the black one. Everything else is identical, which
is the point: the panel writes one accesslist file and the tracker's mode decides what it means.

Since 2.0.0 every UDP worker has a socket of its own on the port and the kernel spreads the datagrams
over them; without `listen.udp.workers` there is one. `listen.tcp.workers` stays unset (0): HTTP is
answered on the main thread, which upstream measures as faster than one TCP worker.

> ⚠ `access.stats_path` matters more than it looks. Without it `/stats` sits on a guessable path, and
> `WANT_RESTRICT_STATS` limits it by IP — so anyone who can reach the tracker from a listed address
> can read the whole torrent list. Pick something unguessable and keep the IP list short — the panel
reads `/stats` from this machine itself, so `127.0.0.1` is the address it needs.

> ⚠ `access.fullscrape` is not optional with these builds. Without it every full scrape is refused
> with 403, loopback included, and the panel's index and whitelist polls — which read
> `http://127.0.0.1:6969/scrape` — stop. A poll from another machine needs that machine's address too.

> ⚠ Since 2.0.0 an **empty** accesslist file is loaded as an empty list (1.0 ignored it and kept the
> old one). On a whitelist build that means nothing is served — and every client is told to stay away
> for two hours — so never publish an empty whitelist by accident.

> A config that still has `access.udp_reject_interval` from the 1.92 builds only prints
> `Unhandled line in config file` at the start; delete the line.

---

## A full scrape that loses its own framing

The panel's Index page reads `/scrape` — tens of megabytes of gzip, sent with
`Transfer-Encoding: chunked` — and intermittently the transfer dies with:

```
cURL error: chunk hex-length char not a hex digit: 0x55
```

**It is not the panel and it is not the data.** The bytes that arrive where a chunk length should be
are a heap pointer: `70 0c aa 56 6d 7f` is `0x7f6d56aa0c70`, and what follows it is valid bencode
again. That is freed memory being transmitted — the buffer went back to the allocator, its first
bytes became an allocator link, and the socket sent it anyway.

It is reproducible on demand, on a tracker of your own, without waiting for a 29 MB scrape or the
five-minute full-scrape limiter. Two constants set the scale, and scaling **both** keeps the
production ratio while making the trigger reachable with a few thousand torrents:

```c
ot_fullscrape.c:  #define OT_SCRAPE_CHUNK_SIZE  (16 * 1024)     /* was 1 MiB   */
ot_http.c:        #define OT_BATCH_LIMIT        (256 * 1024)    /* was 16 MiB  */
```

Build without `-DWANT_COMPRESSION_GZIP` so the scrape stays large, announce a few thousand hashes,
then read `/scrape` slowly (a small `SO_RCVBUF` and a sleep in the read loop) and walk the chunk
headers. On the unpatched build that corrupts every time.

⚠ Scaling only `OT_BATCH_LIMIT` reproduces **nothing** — the whole scrape arrives as one chunk and
the interesting path never runs. That cost an hour: a lab that does not reproduce is not evidence of
a fix.

### What has been ruled out, and where the evidence points

Worth writing down, because each of these looked convincing enough to spend an hour on:

| Suspect | Verdict |
| --- | --- |
| The `io_batch` split in `http_sendiovecdata` (a stale pointer across `realloc`, and the wrong batch initialised) | **Not it** — for the framing bug. Both defects are real, but instrumentation shows the split path never runs during a corrupted transfer. (They were a different failure, and it came in October: patch 3, *Round seven*.) |
| libowfat freeing buffers as it sends them (`iob_addbuf_free` + autofree) | **Not it.** A build with `-DWANT_NO_AUTO_FREE`, where nothing is freed at all, corrupts identically. |
| `MSG_ZEROCOPY` — the kernel still reading pages the application has freed | **Not it** — so this round concluded; **round four proved it was** (`strace`: 63 of 63 `sendmsg` calls flagged `MSG_ZEROCOPY`, clean once the option is refused), and `libowfat-no-zerocopy.patch` is the fix. A standalone program driving `iob_send` the same way got **zero** `SO_EE_ORIGIN_ZEROCOPY` completions on `MSG_ERRQUEUE`; the sends are ordinary copies. Patching libowfat to skip zerocopy for autofree batches changes nothing. |

Where it does point: the corrupted run is **exactly six bytes** long and sits **exactly** where the
next chunk's length header belongs — the scrape data immediately after it is intact bencode. Six
bytes is the size of a chunk header like `3f2a
`, and the bytes found there are a heap pointer,
which is what glibc writes into a freed block of that size. `MALLOC_PERTURB_` cannot show it: in a
block that small the tcache link overwrites the poison.

So the buffer being clobbered is the little `asprintf`'d chunk header, not the 16 KiB payload — and
the payload buffers are visibly recycled between chunks (the same two addresses alternate through a
whole scrape).

Two one-line builds confirm it and separate the two explanations:

| Variant | Result |
| --- | --- |
| **D** — the chunk header queued with `iob_addbuf` (no cleanup registered, so it is never freed) | **0 of 5 corrupted** |
| **E** — the header copied into a 4 KiB block, still queued with `iob_addbuf_free` | **5 of 5 corrupted** |

D fixes it; E does not. So it is **the free itself, not the size class** — the header's cleanup runs
before the bytes have left, and the block comes back with an allocator link written over it.

Note that the free survives `-DWANT_NO_AUTO_FREE`, which is why that build was not clean either:
`iob_reset()` calls every entry's `cleanup` regardless of the `autofree` flag, so registering a
cleanup at all is enough. Only D, which registers none, avoids it.

**What is not yet known** is why the cleanup is premature — the sends are ordinary copies, and a
buffer whose bytes `sendmsg` has accepted should be safe to free. That is the remaining thread. It is
one function: libowfat's `iob_send` accounting, and its interaction with `iob_reset`. Nothing here
ships a fix for it: D leaks a few bytes per chunk, which is the wrong trade for a tracker, and
guessing at the accounting without understanding it is how a framing bug becomes a crash.

The panel no longer depends on the outcome either way: a transfer that ends early is parsed for what
arrived and the poll resumes at the tail, the same as when the poll-time budget stops it mid-file.

## After the first start

Three things this project learned the hard way, in the order they bite:

1. **A socket's receive buffer is fixed when the socket is created.** Raising `net.core.rmem_default`
   does nothing to a running tracker — it applies at the next start. Measured here: 8 MiB configured,
   208 KiB actually in use two days later, 43.6 million packets dropped. After the restart: 47.
2. **One CPU core may be doing all the packet work.** A single-queue virtio NIC delivers every packet
   to one core; that core hits 100 % long before the machine does, and the symptom is other services
   losing packets. RPS spreads it in software — the panel's Traffic page measures this and prints the
   exact command.
3. **Full scrapes are rate-limited to one per client per 5 minutes** (`WANT_MODEST_FULLSCRAPES`), and
   the answer to an early one is HTTP 402. That is not a payment and not a firewall; poll less often.
   (With `-DWANT_RESTRICT_FULLSCRAPE` a client that is not named in `access.fullscrape` gets 403
   instead, and is never counted.)

## The chunked-framing bug: what is now known (2026-09-04)

A full scrape large enough to be sent in more than one chunk arrives corrupted: the client hits a
chunk-size header that is not hexadecimal, part-way through the body. Reproduced 8/8 and then 3/3 in
a lab by scaling `OT_SCRAPE_CHUNK_SIZE` and `OT_BATCH_LIMIT` down together, so a small tracker
crosses the same boundaries a real one does.

### What the bytes on the wire actually are

Not scrambled payload — a **pointer**. `0x00007f1e75980c44`, in the same place on every run. glibc
writes the tcache free-list `fd` pointer into the first eight bytes of a chunk when it is freed, so a
buffer that reads back as a heap address is a buffer that has been **freed and not yet reused**.

The question is therefore not "what writes garbage" but "who frees this while it is still queued".

### What has been ruled out, with evidence

| Theory | How it was killed |
|---|---|
| The `io_batch` split at `OT_BATCH_LIMIT` | Instrumented: the split never runs at the offset where framing breaks. |
| libowfat's autofree | `-DWANT_NO_AUTO_FREE` corrupts identically — `iob_reset` runs cleanups regardless of the flag. |
| `MSG_ZEROCOPY` | Zero `SO_EE_ORIGIN_ZEROCOPY` completions. *(Superseded: round four found it was the cause.)* |
| A thread race on the batch | Thread ids logged at queue, send and reset: **one thread** does all three. |
| Undefined behaviour exposed by optimisation | Built at `-O3` and at `-O0`, fixed and unfixed: 3/3 bad in all four. |

An earlier round concluded "`iob_send` is never called". That was wrong, and the reason is worth
recording: the instrumentation had landed on the `#ifdef __MINGW32__` copy of `iob_send`, which does
not compile on Linux. Every conclusion drawn from that line was void.

### A real bug found on the way, not this one

`ot_http.c`, the batch-splitting path:

```c
if (current->bytesleft > OT_BATCH_LIMIT) {
  io_batch *new_batch = realloc(cookie->batch, (cookie->batches + 1) * sizeof(io_batch));
  if (new_batch) {
    cookie->batch = new_batch;          /* the array may have MOVED */
    if (OT_IOB_INIT(current) != -1)     /* `current` still points into the OLD allocation, and this */
      current = cookie->batch + cookie->batches++;   /* initialises the FULL batch, not the new one */
  }
}
```

`current` is a pointer **into** `cookie->batch`. `realloc` is free to move that array, after which
every use of `current` is a use of freed memory — and `OT_IOB_INIT(current)` wipes the batch that
already holds the chunk header and everything queued so far, instead of preparing the new slot.

The fix is to rebase before touching anything and to initialise the new slot:

```c
cookie->batch = new_batch;
current = cookie->batch + cookie->batches;
if (OT_IOB_INIT(current) != -1)
  cookie->batches++;
else
  current = cookie->batch + cookie->batches - 1;
```

**It does not fix the framing corruption** — tested, 3/3 still bad — but it is a genuine
use-after-free that fires whenever one batch passes 16 MiB, and it was noted here as one that should
go in whenever the binaries are next rebuilt. It never made it into a patch, and on 2026-10-08 it
started taking the tracker down: it is now `opentracker-batch-realloc.patch` (*Round seven*).

### Round two (2026-09-04): the lifetime is sound, and that makes it stranger

A minimal in-memory ring — one record per queue, free and writev, no I/O on the hot path — traced a
whole corrupted transfer. Everything it measured says the server is right:

| Measured | Result |
|---|---|
| Buffers queued vs handed to `writev` | **73 / 73** |
| Buffers sent AFTER their cleanup ran | **0** |
| Chunk headers whose declared size matched the bytes queued behind them | **all of them** (`3f2a` = 16 170, every time) |
| Buffers whose first bytes changed between queue and send | **0** |
| Total handed to `writev` vs total the client received | **420 271 / 420 271, exactly** |

And the transfer is still corrupt — at offset 48 630, which is exactly a chunk-header boundary:
93 (HTTP head) + 16 179 + 2 + 6 + 16 170 + 2 + 6 + 16 170 + 2. The 32 bytes before it are a perfectly
formed chunk end (`…incompletei1ee
`); the six bytes that should be the next header are a heap
pointer followed by NULs, and valid bencode resumes a few bytes later.

So the process hands the kernel a correctly framed stream, byte for byte, and the client receives the
same number of bytes with a freed pointer sitting exactly where a header should be. Those two
statements cannot both be true of the same bytes, which means one of the probes is measuring
something subtly different from what it claims — the next round has to close that gap before adding
any more theories.

**Also ruled out this round:** re-entrancy. `iob_send` was bracketed with enter/leave records to see
whether the fullscrape callback appends to a batch mid-send; the run that produced a usable trace
showed no queue inside a send and no unsent buffer. (One later run produced an empty trace — the
destructor did not fire — and its "all clean" reading is void; a harness that can report success
without data is a harness to fix before it is a result to believe.)

### The next experiment

Close the gap between "the trace says the stream is correct" and "the wire says it is not". The
probe fires when the **iovec is built**, not when `writev` returns, so it cannot see anything that
happens to a buffer between those two moments. Capture the iovec contents again immediately AFTER
`writev` returns, and hash each buffer rather than sampling eight bytes — a corruption further into a
16 KiB payload is invisible to the current probe, and the header at a boundary may be collateral
rather than the cause.

Worth pairing with: run the same lab under `valgrind --tool=memcheck` (the address sanitiser build
did not link here), which reports a write to freed memory at the moment it happens rather than
leaving it to be inferred from what came out of the socket.

**Nothing has been swapped on production.** The backup taken before any of this is at
`/home/debian/opentracker-backup-20260903-185859` with `SHA256SUMS` and `ACTIVE-BINARY.txt`.

## Round three: valgrind and helgrind (2026-09-05)

Both tools installed on the VPS (`valgrind 3.24.0`), run against a scaled lab in `/tmp/vg`
(`OT_BATCH_LIMIT` 256 KiB, `OT_SCRAPE_CHUNK_SIZE` 16 KiB, 1200 torrents), built with
`OPTS_production="-g -O1" STRIP=true`.

**`STRIP=true` is not optional.** The Makefile runs `strip` on the binary after linking
(`STRIP?=strip`, line 19), so the first run produced `???` for every frame and told us nothing.
`CFLAGS` must also be *appended* to, never replaced — replacing it drops libowfat's own `-I.` and
the build dies on the generated `entities.h`.

### Confirmed: a use-after-free — but at SHUTDOWN, not on the scrape path

    Invalid read of size 8
       at clean_single_peer_list (ot_clean.c:56)
       by clean_single_torrent   (ot_clean.c:106)
       by clean_worker           (ot_clean.c:122)          <- the cleanup THREAD
     Address is 0 bytes inside a block of size 56 free'd
       at free_peerlist          (trackerlogic.c:43)
       by trackerlogic_deinit    (trackerlogic.c:588)
       by signal_handler         (opentracker.c:67)        <- the MAIN thread

Four of these, two blocks (56 B `malloc`, 80 B `realloc`). Real, and worth fixing upstream: SIGINT
frees the torrent and peer structures while the cleanup thread is still walking them. It is a
crash-on-shutdown, not the framing bug.

### Helgrind: 20 possible races, none on the send path

Mostly `g_now_seconds` — written by `time_caching_worker` (opentracker.c:650), read unlocked by
`handle_accept`, `udp_generate_rijndael_round_key`, `stats_init`. An unsynchronised clock cache;
formally a race, benign on x86-64 for an aligned 8-byte word. One substantive: `clean_worker`
against `add_peer_to_torrent_and_return_peers` (trackerlogic.c:126-127).

### TWO HYPOTHESES ELIMINATED

**The cleanup thread cannot compact a vector under the fullscrape.** Both take the same lock —
`ot_fullscrape.c:170/240/367` and `ot_clean.c:116` each call `mutex_bucket_lock(bucket)` and hold
it for the whole bucket.

**The sender is not walking a reallocated iovec array.** `mutex_workqueue_popresult`
(ot_mutex.c:236-247) sets `ptask->iovec = NULL` and `iovec_entries = 0` on the PARTIAL path, under
`tasklist_mutex` — so ownership passes outright and a later `iovec_append` starts a fresh array.
This was the best hypothesis going in and it is wrong.

### NEW SUSPECT: three exits that abandon a task without terminating it

`fullscrape_iterate_database` (ot_fullscrape.c, the loop at ~168-200) leaves three ways:

```c
if (mutex_workqueue_pushchunked(taskid, &iovector)) {
    free(iovector.iov_base);
    return mutex_bucket_unlock(bucket, 0);      /* push failed */
}
r = iovector.iov_base = malloc(OT_SCRAPE_CHUNK_SIZE);
if (!r)
    return mutex_bucket_unlock(bucket, 0);      /* malloc failed */
...
if (!g_opentracker_running)
    return;                                     /* bare return — buffer leaked too */
```

None of them ever sends the terminating `mutex_workqueue_pushchunked(taskid, NULL)` that sets
`TASK_DONE`. Chunks already pushed have been written to the socket; the connection is then left
waiting for a terminator that never arrives. That is the "truncated" failure mode exactly, and it is
reachable under memory pressure — which is when a 16 MiB batch allocation is most likely to fail.

### What did NOT happen

**The framing bug did not reproduce under either tool.** The scrape came back clean (84 119 bytes,
1200 torrents). Both tools are 30-100x slower and the scale that triggers it natively (6000
torrents, 420 KB) does not finish under instrumentation in one run. So the clean result is NOT
evidence that the send path is sound — it is evidence that the reproducer needs to get cheaper
before valgrind can be pointed at it.

**That was the wrong next step, and round four found out why.**

## Round four: the cause (2026-09-05)

**`MSG_ZEROCOPY`, and nobody waiting for the completion.** libowfat 0.34's `io/iob_send.c` sends
with `sendmsg(MSG_MORE | MSG_ZEROCOPY)` once a batch is ≥ 8 KiB, then frees the buffers as soon as
the call returns (`cleanup()` per completed entry, `iob_reset()` at the end of the batch).
`MSG_ZEROCOPY` means the kernel reads the pages *later* — at transmit, or on loopback when the
receiver `recv()`s — and reports completion on the error queue. libowfat never reads that queue. So:
`sendmsg` returns → `free(header)` → glibc writes a tcache pointer over the first 16 bytes → the
receiver reads → the kernel hands over the page as it is now. A heap pointer where `%zx\r\n` was.

This is why three rounds of in-process probes were all *correct and all useless*: the bytes really
were right at queue time and at send time. It is why valgrind saw nothing: no instruction in the
process ever read freed memory. And it is why the small-`SO_RCVBUF` reader was needed to reproduce:
the receiver has to be behind the sender for the freed page to be reused before it is read.

**The A/B** (`/tmp/zclab.sh`; same binary, 6000 torrents, scaled chunk sizes, slow reader):

| | 1 | 2 | 3 |
|---|---|---|---|
| as built | BAD FRAMING @64705, bytes `\x00e\xe3[\xadU` → `0x55ad…` | BAD FRAMING @16187, `\xc9\x7f` → `0x7fc9…` | BAD FRAMING @48535 |
| `SO_ZEROCOPY` refused (LD_PRELOAD, one option, `ENOPROTOOPT`) | CLEAN | CLEAN | CLEAN |

`strace` on run A: 3 × `setsockopt(SO_ZEROCOPY)`, and **63 of 63** `sendmsg` calls flagged
`MSG_ZEROCOPY`. The corruption followed the flag.

**The fix** is to compile the zerocopy block out of `iob_send.c` (first tried as `#undef MSG_ZEROCOPY` /
`#undef SO_ZEROCOPY` before the `#ifdef MSG_MORE` section; the shipped patch turns the guard into
`#if 0`, below) so the copying `sendmsg(MSG_MORE)` path is
taken — the path run B used. Handling the completion queue properly would be the "right" fix
upstream; for a tracker whose scrape is read by one panel on loopback, copying 30 MB is nothing.
Built as `/tmp/fix/out/opentracker.{white,black}` with the production feature set (`/tmp/fixbuild.sh`),
verified with `strings` for the accesslist symbol and with `strace` for the absence of the flag.
Installed the same afternoon — round five.

The recipe above gains one step, and it is not optional: apply
`libowfat-no-zerocopy.patch` to `libowfat/io/iob_send.c` **before** `make -C libowfat`, or the bug
comes back with the next rebuild. The patch is the `#if defined(MSG_ZEROCOPY) && defined(SO_ZEROCOPY)`
guard turned into `#if 0`, with a comment saying why.

## Round five: production (2026-09-05)

Both binaries swapped at 12:48:03 — `opentracker.white` and `opentracker.black`, built from the
patched libowfat with the production feature set (`/home/debian/build-fixed/` on the VPS; the
originals are in `/home/debian/backup-opentracker-20260905/`). The service restarted once and the
swarm rebuilt from empty: 1.56 M torrents and 3.8 M peers four hours later.

**Every full scrape since has arrived intact.** The 16:55 poll read 1 561 725 of 1 562 173 hashes
with `truncated=0`; the polls before the swap had failed 10 times in 13. The truncated rows that
still appear are a different thing entirely: the panel stops reading when its own
`index_poll_budget` (45 s by default; capped at 120 s then, 5–300 s since 1.72.0) runs out, and on a swarm this size a poll takes
56–96 s. Those rows have no `partial` marker and no framing error — raising the budget is a
settings decision, not a tracker bug.

**A review of the rest of the source** ran in parallel and is written up as an addendum to
`UPSTREAM-REPORT.md`: seven findings traced by a second reader (the UDP connection-id secret from
`srandom(time(NULL))`, the accesslist reload use-after-free, the `/stats` task that outlives its
client, and four low ones), six raised but not confirmed, three refuted. Nothing from it is
patched here at that point: the zerocopy fix was the only change those binaries carried beyond the
two patches above. Round six patched seven of the findings.

## Round six: the review fixes (2026-09-06)

The source review's findings went through a second pass: every unconfirmed one got two
independent refuters, every confirmed one a patch writer and then an adversarial reviewer who had
to apply the diff on a copy and find what was wrong with it. Seven patches survived and are in
`opentracker-review-fixes.patch`; two were rejected by their reviewers as incomplete (the NULL
`free_peerlist()` and the `iovec_increase` leak — both on `malloc`-failure paths) and are not
shipped. Three findings are report-only by design because a fix would change behaviour or cost
memory: the missing access gate on the full `/scrape` (high — an unauthenticated client can park
~114 MB per connection for fifteen minutes; the panel is the only intended client. The port is
**not** loopback-only, as this note first claimed: TCP 6969 is public, the HTTP announce URL is advertised
there. The gate shipped in 1.75.3 — patch 4 above), the kernel-default UDP receive buffer (the host sets
`rmem_default`), and the per-packet `recvfrom`/`sendto` without batching.

**What the patches change.** The UDP connection-id secret is read from `getrandom(2)` instead of
`srandom(time(NULL))`, so a client can no longer forge connection ids for other addresses. An
accesslist reload keeps the superseded list alive for the grace period whatever its age, instead
of freeing a list the announce threads may still be searching (the SIGHUP use-after-free). A
`/stats` task is cancelled when its client disconnects, like the fullscrape tasks already were, so
a reused fd never receives someone else's stats. `g_torrent_count` is an atomic add instead of a
read-modify-write after the bucket lock is dropped. The tpbs stats path no longer writes a NUL one
byte past a full request buffer. UDP worker threads start after `trackerlogic_init()` and the
first accesslist load, not during option parsing (the process serves nothing until the list is
in). A whitelist whose last line has no trailing newline no longer reads one byte past the mmap.

**The lab** (`/tmp/otlab2.sh` on the VPS, whitelist build on 127.0.0.1:16969 under valgrind):
announce for a listed hash returns the peer, an unlisted one gets the 300-second reject reply,
`/stats` and `/scrape` answer 200, twenty `/stats` requests aborted mid-flight and sixty SIGHUP
reloads with the list rewritten each time under concurrent announces leave the process serving,
and valgrind reports zero invalid reads, writes or frees across the whole run. The only entries in
its summary are twenty `realloc(ptr, 0)` notes from upstream's `iovec_fix_increase_or_free()` on
the stats path — a deprecated-pattern warning, not a memory error, and present before the patches.
Compiler warnings went from 13 to 11.

Built as `/home/debian/build-fixed2/opentracker.{white,black}` (the sources with every patch in
`/home/debian/build-p2/`); shipped in `bin/`. The swap on production is the operator's decision:
it restarts the tracker and the swarm rebuilds from empty.

## Round seven: the 2026-10 crash (2026-10-13)

**Symptom.** Four aborts or crashes between 2026-10-08 and 10-12 — `malloc_consolidate(): unaligned
fastbin chunk detected` twice, `malloc(): unaligned tcache chunk detected` once, one SIGSEGV — each
three to five seconds after a client had pulled the full `/scrape`.

**Cause (a use-after-free, confidence about 85 %).** The defect round one described and set aside as
"not the framing bug": `http_sendiovecdata()` grows its array of send batches with `realloc()` when
more than 16 MiB is queued and unsent. It then called `OT_IOB_INIT(current)` with `current` still
pointing into the **old** array. If `realloc` moved the array, that is a 72-byte `memset` into a freed
chunk; glibc keeps its free-list link in the chunk's first bytes, safe-linking turns the zeroed link
into a misaligned pointer, and the next `malloc` or consolidation of that size class aborts. The new
slot was also used without being initialised (a wild pointer: SIGSEGV or more corruption), and when the
array grew in place the live batch was wiped instead, silently dropping its queued bytes. The batch
array and the per-connection cookie are both 72 bytes, so every HTTP accept and close churns the very
bin the freed chunk sits in.

**Trigger.** Not a change in the panel. The panel reads the scrape over loopback faster than it is
produced, so its backlog never reaches 16 MiB and the branch had never run in five weeks. From
2026-10-08 six public addresses began pulling the full scrape every two hours or so, because TCP 6969 is
public (the HTTP announce URL is advertised there) and the full scrape was open to everybody. An Internet
client cannot keep up: ~45 MB of gzip arrives within seconds, the backlog passes 16 MiB, and the split
branch runs. The first remote pull crashed the tracker five seconds later; four crashes in 67 remote
pulls fits one dice roll per transfer (only the first split corrupts a free-list link). About three
hundred loopback polls in the same window were never followed by a crash.

**Fix.** `opentracker-batch-realloc.patch` (the use-after-free) and `opentracker-fullscrape-gate.patch`
(a full scrape only for the operator; everybody else gets 403 and costs nothing). Neither depends on the
other, but the gate is written against the file the first leaves, so apply them in that order.

**Ruled out:** the SIGHUP accesslist reload (no reload in the window), `MSG_ZEROCOPY` (compiled out in
this libowfat), the `/stats` task path (polled every minute for weeks with no correlation) and the
fullscrape worker's early exits (it always pushes the terminator).

**Not proven by a core.** The logs cannot show that the split branch ran in those four transfers; the
mechanism was derived from the production source and its disassembly, not caught. If anything still
crashes after the swap, enable cores for this unit only (`LimitCORE=infinity` in a drop-in; the file
lands in `/home/tracker`, about 0.6 GB — delete it after use) and read it with `gdb` against an unstripped
twin of the binary.
