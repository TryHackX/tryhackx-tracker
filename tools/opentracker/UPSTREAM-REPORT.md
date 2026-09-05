# Bug report for upstream — libowfat `iob_send()`: MSG_ZEROCOPY without completion handling

*A draft, ready to send as-is to Felix von Leitner (libowfat, git.fefe.de) and to cc erdgeist for
opentracker. Not sent by the panel or by anyone on the operator's behalf — sending is the
operator's decision.*

---

**Subject:** iob_send(): MSG_ZEROCOPY buffers are freed before the kernel is done with them
(corrupts chunked HTTP responses on a slow receiver)

Hi,

libowfat 0.34, `io/iob_send.c`, Linux path. Once a batch is ≥ 8 KiB, `iob_send()` sets
`SO_ZEROCOPY` on the socket and sends with `sendmsg(…, MSG_MORE | MSG_ZEROCOPY)`. It then frees the
completed entries immediately (`cleanup()` on partial progress, `iob_reset()` when the batch is
done).

`MSG_ZEROCOPY` is a promise that the referenced pages will not change until the kernel reports
completion on the socket's error queue (`MSG_ERRQUEUE`, `SO_EE_ORIGIN_ZEROCOPY`). Nothing in
libowfat reads that queue. So on any receiver slower than the sender — a loopback client, a
retransmit — the kernel hands over memory that glibc has already reused. With small entries the
first sixteen bytes of a freed block are the tcache `fd`/`key` pointers, so what arrives on the
wire is a heap pointer where the data was.

**How it shows up in opentracker.** `/scrape` with `WANT_FULLSCRAPE` is sent chunked; every
chunk-size header (`"%zx\r\n"`, an `asprintf` allocation) is a separate small entry. A client that
does not read as fast as the tracker writes receives, at a chunk boundary, bytes like
`\x00e\xe3[\xadU` (0x55ad…) or `\xc9\x7f` (0x7fc9…) instead of the hex length, and the transfer
dies with "chunk hex-length char not a hex digit". On our machine, with a panel fetching the
40 MB gzip scrape over loopback every 30 minutes, 10 of 13 polls failed this way.

**Reproduction** (opentracker 1c7fac4, libowfat 0.34, Debian 13, kernel 6.x, x86-64):

1. Build opentracker with `-DWANT_FULLSCRAPE`. To reproduce quickly, scale `OT_BATCH_LIMIT` to
   256 KiB and `OT_SCRAPE_CHUNK_SIZE` to 16 KiB; the bug needs chunk boundaries, not size.
2. Announce ~6000 distinct info hashes.
3. Fetch `/scrape` with a deliberately slow reader: `SO_RCVBUF = 2048` and a 10 ms sleep between
   2 KiB `recv()`s. Validate the chunked framing.

Result, three runs each, same binary:

| | run 1 | run 2 | run 3 |
|---|---|---|---|
| as built | corrupt @64705 (`0x55ad…` at the chunk header) | corrupt @16187 (`0x7fc9…`) | corrupt @48535 |
| `setsockopt(SO_ZEROCOPY)` refused via `LD_PRELOAD` (returns `ENOPROTOOPT`, nothing else changed) | clean | clean | clean |

`strace` on the first: 3 × `setsockopt(SO_ZEROCOPY)`, and all 63 `sendmsg` calls flagged
`MSG_ZEROCOPY`. Valgrind memcheck and helgrind report nothing on this path, which is consistent —
no instruction in the process touches freed memory; the kernel does.

**Fix options.** Either read `MSG_ERRQUEUE` and defer `cleanup()` until the matching completion
arrives (the correct fix, but it changes iob's lifetime model), or do not opt into zerocopy at all.
We are running the second: the guarded block compiled out with

```c
#if 0   /* was: #if defined(MSG_ZEROCOPY) && defined(SO_ZEROCOPY) */
```

which leaves the plain `sendmsg(MSG_MORE)` path — patch attached as `libowfat-no-zerocopy.patch`.
With it the same reproducer is clean, and in production the first full scrape after the swap
arrived intact where the previous ten had not.

**Also noticed, opentracker side, unrelated:** on SIGINT `trackerlogic_deinit()` frees the torrent
and peer structures from `signal_handler` while `clean_worker` is still walking them
(`ot_clean.c:56/106/122` reading blocks freed at `trackerlogic.c:588/592`) — valgrind reports four
invalid reads at shutdown. A crash-on-exit, not the framing bug; probably `pthread_join` the
workers before `trackerlogic_deinit()`.

Happy to provide the full reproducer scripts.

Thanks for both projects.

---

## Addendum — what a source review found after the fix (2026-09-05)

*Written after the patched binaries went into production (12:48 UTC+2, both modes). Every full
scrape since has arrived intact; the only truncated polls left are the ones the panel itself cuts
off on its time budget, and those carry no framing error. The review below covered opentracker
1c7fac4 as built here (Linux, `WANT_FULLSCRAPE`, `WANT_COMPRESSION_GZIP`, accesslist white/black,
no `WANT_ARC4RANDOM`, no `WANT_DEV_RANDOM`). Findings are grouped by how far they were checked:
"stands" means a second reader traced the code path and agreed; "unverified" means one reader
raised it and nobody got to confirm it before the session ended; "refuted" means the second reader
showed it does not happen. Nothing here has been patched — the zerocopy fix above is the only
change we run.*

### Stands (traced by a second reader)

1. **UDP connection-id secret comes from `srandom(time(NULL))`** — `ot_udp.c:36`, high. With
   neither `WANT_ARC4RANDOM` nor `WANT_DEV_RANDOM` (both commented out in the shipped Makefile),
   `udp_init()` seeds libc `random()` with the start time, so the two 32-bit secret words are a
   function of a value an attacker can bracket to the second. A forged connection id for any source
   address lets a client announce as that address, which is exactly what the connect handshake is
   meant to prevent. Fix: read the secret from `getrandom(2)` (or enable the `WANT_DEV_RANDOM`
   path by default on Linux).

2. **Accesslist reload frees the superseded list while readers may still be in `bsearch`** —
   `ot_accesslist.c:96`, high. The lock-free design keeps the previous list alive for five minutes
   so readers that fetched the old head finish safely. But when the list being replaced is itself
   already older than five minutes, the reload frees it at once — and announce threads that read
   the head a microsecond earlier are still walking it. On a whitelist tracker with a SIGHUP every
   few minutes (ours reloads when the panel changes the list) this is a use-after-free on the hot
   path. Fix: always keep the immediately-superseded list on the deferred-free chain, whatever its
   age.

3. **A stats task outlives the client that asked for it** — `ot_http.c:337`, medium. The fullscrape
   branches cancel their worker task on disconnect; the `TASK_STATS` path (default `mode=peer`,
   also `torr`, `s24s`, `top10`, `top100`, `everything`) does not. The task is keyed only by the
   socket number, so when the client goes away and the fd number is reused for a new connection,
   the stats output is written to whoever now holds that fd. Fix: the same `mutex_workqueue_cancel`
   the fullscrape branches already call.

4. **`g_torrent_count` is updated after the bucket mutex is released; the stats counters are
   unlocked `++` from five threads** — `ot_mutex.c:44`, low. Lost updates: the counts drift and
   never come back. Cosmetic for us (the panel counts torrents itself), but the numbers on
   `/stats` are wrong by design under load.

5. **One-byte write past the request buffer in the tpbs stats path** — `ot_http.c:309`, low.
   `ws->request[ws->request_size] = 0` when the request fills the buffer exactly. `G_INBUF_SIZE`
   is 8192; a request of exactly that length lands the zero on the byte after it.

6. **`free_peerlist()` dereferences NULL on the allocation-failure path** — `trackerlogic.c:37`,
   low. If the peer-list `malloc` for a new torrent fails, cleanup runs `free_peerlist` on the
   NULL pointer it just failed to allocate.

7. **`iovec_increase` leaves `*iovector` dangling when the data `malloc` fails after a successful
   `realloc`** — `ot_iovec.c:27`, low. The `realloc` may have moved the array; on the failure path
   the caller still holds the old pointer, and the new array is never freed.

### Unverified (raised once, not yet traced by a second reader)

- Full `/scrape` never checks `OT_PERMISSION_MAY_FULLSCRAPE` even though the permission exists;
  the per-IP limiter is bypassable and each request buffers the whole response (`ot_http.c:372`).
- The UDP socket keeps the kernel-default ~208 KB receive buffer: under one millisecond of headroom
  at 200 kpps, so any stall of the worker pool becomes packet loss (`opentracker.c:367`). We
  compensate with `net.core.rmem_default`/`rmem_max` on the host, which is why this did not bite.
- One `recvfrom` + one `sendto` per UDP packet on a single shared socket, no `recvmmsg`/`sendmmsg`
  batching (`ot_udp.c:94`) — the largest single CPU cost in the process at our packet rate.
- UDP worker threads are started from `ot_try_bind()` during option parsing, before
  `mutex_init`/`trackerlogic_init` and before the accesslist is loaded (`opentracker.c:369`).
- Gzip fullscrape runs `deflate()` for a whole bucket inside the bucket's critical section
  (`ot_fullscrape.c:496`) — the longest lock hold in the process.
- `accesslist_readfile` reads one byte past the mmap when the last line is a hash without a
  trailing newline (`ot_accesslist.c:145`).

### Refuted

- "MAP_SHARED mmap of the whitelist raises SIGBUS if the writer truncates it in place" — the panel
  writes the file with rename, so the mapped inode is never truncated.
- "`server_mainloop`'s `ot_workstruct` is used before being zeroed" — every field read is set by
  the request path first.
- "`handle_udp6` stores `-1` in a `size_t` and processes a stale datagram" — the length check
  rejects the wrapped value before anything reads the buffer.

### Also seen, at shutdown only

Four invalid reads from `clean_worker` (`ot_clean.c:56/106/122`) into blocks freed by
`trackerlogic_deinit()` (`trackerlogic.c:588/592`) — the signal handler frees the torrent tables
while the cleaner is still walking them. Harmless for a service that is being stopped, but it is
the reason valgrind's exit summary is never clean.
