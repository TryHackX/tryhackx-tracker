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
| `udp-reject-interval.patch` | adds `access.udp_reject_interval` |
| `egress-budget/ottrack.nft` | the reply-rate budget (see the Traffic page) |
| `tracker-*.sh` | the root helpers the panel calls (see [INSTALL.md](../../INSTALL.md)) |

```
sha256  399230797752f6d1e217a0b43d1d8ce3ea4451291664de6e79a2912fbd4259ac  opentracker.white
sha256  4c6dc5f693ac9b083d751f5b563c4f83c722a278df70236f4e3a99f00dcf9baa  opentracker.black
```

Both are 117 936 bytes, stripped, position-independent.

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
libowfat   0.34 (built from source alongside; Debian's libowfat-dev also works)
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

# libowfat, opentracker's own support library
git clone git://git.fefe.de/libowfat
make -C libowfat

# opentracker at the tested commit
git clone git://erdgeist.org/opentracker
cd opentracker
git checkout 1c7fac4cc23801ac81a2abd7d3110683831c4811

P=/path/to/tryhackx-tracker/tools/opentracker
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
