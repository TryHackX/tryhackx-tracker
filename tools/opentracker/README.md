# The tracker itself — the two opentracker builds

The panel is an admin interface for [opentracker](https://erdgeist.org/arts/software/opentracker/).
This directory holds the tracker binaries this project is developed and tested against, the two
patches applied to them, and everything needed to rebuild them from source instead of trusting the
binaries.

**Tested on Debian 13 (trixie)**, x86-64, gcc 14.2.0, kernel 6.12. They are plain dynamically linked
ELF executables needing only `libz` and `libc`, so any glibc distribution of that vintage will run
them; older glibc will not.

---

## What is here

| File | What it is |
| --- | --- |
| `bin/opentracker.white` | whitelist build — serves **only** hashes in the accesslist file |
| `bin/opentracker.black` | blacklist build — serves everything **except** the hashes in the file |
| `sighup-udp-workers.patch` | fixes `systemctl reload` killing the tracker |
| `libowfat-no-zerocopy.patch` | libowfat: compiles out `MSG_ZEROCOPY` in `iob_send()` — the chunked `/scrape` corruption (round four) |
| `opentracker-review-fixes.patch` | seven fixes from the 2026-09 source review (connid secret, accesslist reload UAF, /stats task on a dead fd, atomic torrent count, tpbs one-byte write, worker start order, mmap bound) |
| `UPSTREAM-REPORT.md` | the bug report for libowfat/opentracker, ready to send |
| `udp-reject-interval.patch` | adds `access.udp_reject_interval` |
| `egress-budget/ottrack.nft` | the reply-rate budget (see the Traffic page) |
| `tracker-*.sh` | the root helpers the panel calls (see [INSTALL.md](../../INSTALL.md)); `tracker-dbmem.sh` (1.37.0) is the database-memory one — seven MariaDB/MySQL keys, live where the engine allows, a drop-in for the rest |

```
sha256  d1a319cd999812a98c4fa2d6fedfee7a8a259b1d0ca0816d58bf2dc5f264fe8d  opentracker.white
sha256  ef5e162e7cba1c3fd73b72c04f267c2699e5975d433c154699bc89cdc49da3cf  opentracker.black
```

Both are 113 840 bytes, stripped, position-independent — the 2026-09-06 builds with the libowfat
zerocopy patch (round five) and the seven review fixes (round six); the unpatched 117 936-byte
builds of 2026-08-18 are retired.

### Why two binaries and not one switch

**White or black is a compile-time decision in opentracker.** `WANT_ACCESSLIST_WHITE` and
`WANT_ACCESSLIST_BLACK` are mutually exclusive `#ifdef`s: the same source builds either a tracker
that serves only what is listed or a tracker that serves everything but what is listed, and there is
no runtime flag that switches between them. That is why switching modes here means switching which
binary a symlink points at, and why the panel restarts the service to do it.

---

## Base version

```
upstream   git://erdgeist.org/opentracker
commit     1c7fac4cc23801ac81a2abd7d3110683831c4811   ("Reduce chance of collisions")
dated      2026-05-26
libowfat   0.34, built from source with libowfat-no-zerocopy.patch applied (Debian's libowfat-dev
           is the unpatched library and reproduces the /scrape corruption — do not link it)
```

The commit is baked into the binary as `GIT_VERSION`, so a build can always be traced back:

```bash
strings opentracker.white | grep -o '1c7fac4[0-9a-f]*'
```

---

## Feature flags

```
-DWANT_FULLSCRAPE            /scrape returns the whole torrent list (the panel's index is built from it)
-DWANT_COMPRESSION_GZIP      …gzipped, which is what makes a 30 MB scrape a 1.5 MB transfer
-DWANT_RESTRICT_STATS        access.stats / access.stats_path — /stats limited to named IPs and moved
                             off the default path
-DWANT_MODEST_FULLSCRAPES    one full scrape per client per 5 minutes; the rest get HTTP 402
-DWANT_ACCESSLIST_WHITE      ── white build only ──
-DWANT_ACCESSLIST_BLACK      ── black build only ──
```

Deliberately **not** enabled:

- `WANT_SYNC_LIVE` — live peer sync between two trackers. There is only one machine, and the protocol
  has no authentication whatsoever, so it has to run inside a tunnel before it is worth having.
  The panel's Live peer sync section detects its absence and says so.
- `WANT_IP_FROM_PROXY` — the tracker is reached directly, so trusting a header would only let a
  client claim any address it liked.
- `WANT_V4_ONLY` — the default build is dual-stack.
- `WANT_SYSLOGS`, `WANT_LOG_NETWORKS`, `WANT_FULLLOG_NETWORKS` — per-announce logging on a tracker
  serving ~100k packets a second is a disk-filling machine, not a diagnostic.

> ⚠ **`-h` and `/stats` lie about which features are compiled in.** opentracker prints one fixed
> usage string regardless of build flags, so `-h` advertises `-s livesyncport` on a binary that
> rejects it, and `/stats` renders a `<livesync>` section on the same binary. **Probe by running,
> never by reading.** This cost an afternoon here; the panel now tests by executing.

Verify a binary's real feature set the way that works — by the config keywords it accepts:

```bash
strings opentracker.white | grep -x 'access\.\(whitelist\|blacklist\|stats_path\|proxy\|udp_reject_interval\)'
```

---

## The two patches

Both are small, both are here as unified diffs, and both fix something that bites in production.

### 1. `sighup-udp-workers.patch` — `systemctl reload` killed the tracker

The accesslist is re-read on `SIGHUP`, which is how the panel applies a whitelist change with no
downtime. opentracker handles that signal in a dedicated thread with `sigwait`, which only works if
the signal is **blocked in every thread**. It blocks them in `defaul_signal_handlers()` — called
after the UDP worker threads have already been created while parsing `-p` / `listen.*`. Those threads
inherit an *unblocked* SIGHUP, and the default action for SIGHUP is to terminate the process.

So `systemctl reload opentracker` — the safe, no-downtime operation — stopped the tracker, and
systemd logged "Deactivated successfully", because SIGHUP counts as a clean exit. The patch moves the
call to the top of `main()`, before any thread exists.

Without `listen.udp.workers` in the config there are no worker threads and the bug is invisible,
which is why upstream has not tripped over it.

### 2. `udp-reject-interval.patch` — telling rejected clients when to come back

When a hash is not allowed (not on the whitelist, or on the blacklist), upstream answers a UDP
announce with an 8-byte truncated packet. Clients read that as a broken tracker and keep retrying;
libtorrent backs off to at most an hour. On a whitelist tracker that inherited a large open swarm,
that is tens of thousands of clients re-asking for ever — the majority of all inbound traffic, none
of it useful to anybody.

With `access.udp_reject_interval N` the tracker sends a **well-formed** announce reply instead:
interval = N, seeders 0, leechers 0, no peers. Compliant clients then wait N seconds. Set to 86400
here, and the traffic it removes is the single biggest saving on this machine.

```conf
access.udp_reject_interval 86400
```

HTTP announces are unchanged: they still get the explicit "not authorized" failure reason, because an
HTTP client is generally a person looking at an error message.

---

## Building it yourself

Nothing here needs the panel; this is the whole recipe.

```bash
sudo apt install -y build-essential git zlib1g-dev
mkdir -p ~/build && cd ~/build

# libowfat, opentracker's own support library — WITH the zerocopy patch, or the /scrape framing
# bug comes back (see "Round four" below: iob_send() frees MSG_ZEROCOPY buffers before the kernel
# has read them). The patch is small and applies to 0.34 as shipped.
git clone git://git.fefe.de/libowfat
(cd libowfat && patch -p1 --forward < $P/libowfat-no-zerocopy.patch)
make -C libowfat

# opentracker at the tested commit
git clone git://erdgeist.org/opentracker
cd opentracker
git checkout 1c7fac4cc23801ac81a2abd7d3110683831c4811

P=/path/to/tryhackx-tracker/tools/opentracker   # (set this before the libowfat step above)
patch -p1 --forward < $P/sighup-udp-workers.patch
patch -p1 --forward < $P/udp-reject-interval.patch
```

`--forward`, and `patch` rather than `git apply`: the second patch applies with a little fuzz (its
hunk headers were written by hand), which `patch` accepts and `git apply` refuses.

**This recipe was verified, not assumed.** The two patches were applied to a pristine checkout of the
commit above and the result compared against the working tree that produced the shipped binaries:
`opentracker.c`, `trackerlogic.c` and `trackerlogic.h` came out **byte-identical**, and the tree
built cleanly. So what is published here is exactly what is running — there is no fourth change
sitting in somebody's editor.

Then build each mode into its own binary — `make clean` between them is not optional, because the
object files carry the accesslist flag:

```bash
COMMON="-DWANT_FULLSCRAPE -DWANT_COMPRESSION_GZIP -DWANT_RESTRICT_STATS -DWANT_MODEST_FULLSCRAPES"
OWF="$HOME/build/libowfat"

make clean
make FEATURES="$COMMON -DWANT_ACCESSLIST_WHITE" LIBOWFAT_HEADERS=$OWF LIBOWFAT_LIBRARY=$OWF
mv opentracker opentracker.white

make clean
make FEATURES="$COMMON -DWANT_ACCESSLIST_BLACK" LIBOWFAT_HEADERS=$OWF LIBOWFAT_LIBRARY=$OWF
mv opentracker opentracker.black
```

Check what you built, by running it rather than by reading its help:

```bash
./opentracker.white -s 9999 -f /dev/null    # prints usage and exits => no livesync, as intended
strings opentracker.white | grep -x access.whitelist   # => the white build
strings opentracker.black | grep -x access.blacklist   # => the black build
```

The build is **not** byte-reproducible — the commit hash and the build paths are compiled in — so a
rebuild will not match the sha256 above. What must match is the feature set, and the commands above
are how you check it.

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

The two config files, the systemd unit, the accesslist directory and the sudoers line that lets the
panel switch modes are all in **[INSTALL.md](../../INSTALL.md) § The tracker** — that is the guide to
follow; this file is the part about the binaries themselves.

A minimal config for either mode:

```conf
# opentracker.conf.white
listen.udp.workers 4
access.whitelist /home/tracker/accesslist/whitelist
access.stats 203.0.113.10
access.stats_path stats-pick-something-unguessable
tracker.redirect_url https://tracker.example.org/?action=whitelist
access.udp_reject_interval 86400
```

Swap `access.whitelist` for `access.blacklist` in the black one. Everything else is identical, which
is the point: the panel writes one accesslist file and the tracker's mode decides what it means.

> ⚠ `access.stats_path` matters more than it looks. Without it `/stats` sits on a guessable path, and
> `WANT_RESTRICT_STATS` limits it by IP — so anyone who can reach the tracker from a listed address
> can read the whole torrent list. Pick something unguessable and keep the IP list short.

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
| The `io_batch` split in `http_sendiovecdata` (a stale pointer across `realloc`, and the wrong batch initialised) | **Not it.** Both defects are real and worth fixing, but instrumentation shows the split path never runs during a corrupted transfer. |
| libowfat freeing buffers as it sends them (`iob_addbuf_free` + autofree) | **Not it.** A build with `-DWANT_NO_AUTO_FREE`, where nothing is freed at all, corrupts identically. |
| `MSG_ZEROCOPY` — the kernel still reading pages the application has freed | **Not it.** A standalone program driving `iob_send` the same way got **zero** `SO_EE_ORIGIN_ZEROCOPY` completions on `MSG_ERRQUEUE`; the sends are ordinary copies. Patching libowfat to skip zerocopy for autofree batches changes nothing. |

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
| `MSG_ZEROCOPY` | Zero `SO_EE_ORIGIN_ZEROCOPY` completions. |
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
use-after-free that fires whenever one batch passes 16 MiB, and it should go in whenever the binaries
are next rebuilt.

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

**The fix** is to compile the zerocopy block out of `iob_send.c` (`#undef MSG_ZEROCOPY`,
`#undef SO_ZEROCOPY` before the `#ifdef MSG_MORE` section) so the copying `sendmsg(MSG_MORE)` path is
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
`index_poll_budget` (45 s by default, 120 s cap) runs out, and on a swarm this size a poll takes
56–96 s. Those rows have no `partial` marker and no framing error — raising the budget is a
settings decision, not a tracker bug.

**A review of the rest of the source** ran in parallel and is written up as an addendum to
`UPSTREAM-REPORT.md`: seven findings traced by a second reader (the UDP connection-id secret from
`srandom(time(NULL))`, the accesslist reload use-after-free, the `/stats` task that outlives its
client, and four low ones), six raised but not confirmed, three refuted. Nothing from it is
patched here; the zerocopy fix is the only change these binaries carry beyond the two patches
above.

## Round six: the review fixes (2026-09-06)

The source review's findings went through a second pass: every unconfirmed one got two
independent refuters, every confirmed one a patch writer and then an adversarial reviewer who had
to apply the diff on a copy and find what was wrong with it. Seven patches survived and are in
`opentracker-review-fixes.patch`; two were rejected by their reviewers as incomplete (the NULL
`free_peerlist()` and the `iovec_increase` leak — both on `malloc`-failure paths) and are not
shipped. Three findings are report-only by design because a fix would change behaviour or cost
memory: the missing access gate on the full `/scrape` (high — an unauthenticated client can park
~114 MB per connection for fifteen minutes; the panel is the only intended client and the port is
loopback-only here), the kernel-default UDP receive buffer (the host sets `rmem_default`), and the
per-packet `recvfrom`/`sendto` without batching.

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
