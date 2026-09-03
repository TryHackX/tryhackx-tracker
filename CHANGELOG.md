# Changelog

All notable changes to this project are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/), and the project aims to follow
[Semantic Versioning](https://semver.org/).

## [1.27.1] — 2026-09-03

An audit across six lenses raised 32 candidates; 27 survived an adversarial verification pass. These
are the ones confirmed against the code by hand and fixed here.

### Security — two ways to take over the panel through a "user" permission

**A moderator could set any account's password.** `panel.users.edit` is a grantable permission whose
promise is status and email verification; `admin/user_update` also wrote `pass_hash` and `email` for
any user id. The panel owner is mirrored into `users` as a member of the admin group, and signing in
as an admin-group member opens a **full panel session** where `panelCan()` is unconditionally true.
So a moderator could set that account's password, sign in as it, and reach Settings, the backups
(whose archives carry every database password on the box) and every sudo-backed helper.
`api/admin/user_grant.php` already refused to let a non-owner grant a panel-carrying group — the same
sentence now guards password, email and verification changes, written against what the target
*holds* rather than against its name.

**A server-to-server API key could grant the admin group.** `v1/users/grant` and
`v1/users/provision` are outside the panel's permission map (it applies to `admin/` routes only) and
their only group filter was the `guest` slug. A `users`-scope key — the kind issued to a shop or a
forum — could put an account in the admin group, and that account gets a panel session at its next
sign-in. Both now refuse any group carrying a `panel.*` permission.

### Fixed — the stability probe drove the wrong port

`tools/tuner.py` and `api/admin/tuner.php` both read a setting called `tracker_port`. **There is no
such setting** — the port is `net_limit_port` — so the read always fell through to 6969. On a tracker
running anywhere else, a probe run would rebuild the firewall table for a port nothing uses: the real
limit torn down, every measurement taken on an empty port, and the restore putting the baseline back
on 6969 as well, while the panel reported the run finished and the settings were restored.

### Fixed — Stop did not stop

The panel's Stop button wrote a `cancel` key into the probe's state file. **Nothing read it.** The
run carried on stepping the firewall limit while the API answered that it was stopping — the one
control an operator reaches for when a probe is hurting the machine did nothing. It is now checked
between samples, so a stop takes effect within one sample interval and still leaves through the
restore path.

### Fixed — a long dwell was reaped as a dead run

Liveness is "the state file was touched recently" (300 s), and the file was written once per *step*.
A dwell over ~270 s therefore looked dead to the janitor, which reaped the run, consumed its restore
marker, and left the test limit in force. The heartbeat is now written with every sample.

### Fixed — saving the ruleset dropped the trusted addresses

A regression from 1.26.0, and on the normal path: php-fpm cannot write `/etc`, so an apply defers and
the janitor calls `persist` a minute later — which re-rendered the ruleset **without** the exemption
sets. Live rules kept them; the file that survives a reboot did not. `persist` now reads the sets
back out of the loaded table.

### Fixed — `persist_deferred` was always false

The closure that writes it never captured `$r`, so it read an undefined variable. The panel could
never say "the rule is live but the file was not written", which is exactly the state a reboot
silently undoes. Confirmed by running the same shape in isolation before changing it.

### Fixed — the Index status card cost 13.9 seconds

Measured on production, per poll, on a database shared with the mail, the forum and the file service:

```
in_grace  1 060 ms · by_status 1 287 ms · files 9 593 ms · expiring_24h 1 432 ms
resolved_24h 468 ms · protected 82 ms · promoted 1 ms      TOTAL 13 921 ms
```

Eight full-table aggregates over 2 M and 6.1 M rows, on every poll of the page — and two of them were
added yesterday by me. Cached for 30 s and dropped whenever a poll or a prune moves the numbers. The
prune's own row count stays uncached, deliberately: a number that decides a delete is not a number to
serve from a cache.

## [1.27.0] — 2026-09-03

### Fixed — the panel wrote a failed sign-in into its own audit log before every successful one

`login.fail — CAPTCHA verification failed`, four seconds before `login.ok`, almost every time. Not an
attacker and not the operator mistyping: the sign-in page posted the credentials **first, with no
CAPTCHA token**, waited for the server to demand one, and only then solved it. The rejection on that
first post was recorded as a failed login.

A security log that cries wolf before each correct sign-in is a log people learn to scroll past — and
it made a real CAPTCHA failure indistinguishable from the noise.

Both ends fixed. The page now knows whether a CAPTCHA is required (the server renders the flag) and
mints the token **before** the first post, so there is one round trip instead of two. And a post that
carried no token at all is treated as what it is — a challenge, before anyone has offered a
credential — and is not written to the log. A token that was supplied and *rejected* still is.

### Fixed — a Test button wired to nothing

**Live peer sync → Test** had existed for releases with no handler behind it: markup, a `data-test`
attribute, and no listener. Pressing it did nothing at all. There is now one delegated handler for
every `data-test` button, so a button cannot be added again without working.

### Changed — "not configured" is no longer reported as a fault

**OpenTracker instances → Test** answered a switched-off feature with a red *"✗ Something on the path
is missing"*. Nothing was missing; the feature was simply off. The test endpoints now report whether
the feature is **configured** separately from whether it **passed**, and an unconfigured one comes
back in neutral blue with *"Not set up — this feature is off, and nothing here is broken"*, its
outstanding steps as circles rather than crosses. Red that does not mean broken is red people stop
reading.

### Added — the Users page can create an account

**Users → Add user.** Same creation path as registration, so password rules and default groups are
identical, with the one decision an admin should not have to guess made explicit:

- **Already verified** — nothing emailed, can sign in now. For an account handed over in person.
- **Send a verification link** — the public flow, driven from the panel; a guest until it is clicked.
- **No email at all** — unverified and nothing sent, for an account with no address.

It matters because `users_require_email_verify` decides what an unverified account may *do*. A
generated password is offered and shown in clear, because it has to be passed to a person — a value
the admin cannot read is a value they replace with something weak.

### Added — search tells you where the setting lives

Searching lifts one field out of a page of a hundred and shows it alone. That answers *is it there*;
it never answered *where is it*, and clearing the search hid it again. Every section a search leaves
visible now carries its group and section name — `Index › Index (observed hashes)` — and a **Show me
where** button that clears the search, switches to that group and scrolls to the section in place with
the same highlight the anchor links use.

### Changed — the index grace window is 7 days

Was 3, against a queue that takes ~51 days to walk once. Measured cost of the change: the table holds
~1 551 bytes a row, so the extra rows are a few hundred MB against 44 GB free, and the longer window
means *less* write amplification — a hash that survives is a hash that is not deleted and re-inserted
every three days.

## [1.26.0] — 2026-09-03

### Added — addresses the rate limit never drops

**Settings → Network & limits → Trusted addresses.** IPv4 or IPv6, plain or CIDR. Packets from these
sources are counted but never dropped: they skip the budget entirely.

A rate limit cannot tell which packets matter. On a machine that also runs a game server, is
monitored from a fixed address, or is reached over SSH from one place, those sources should not be
collateral damage of a swarm shouting at the tracker.

Each entry becomes an element of an nftables set — one hash lookup whatever the size — placed after
the arrival counter and **before** the drop rule, so a trusted packet still shows up in the arrival
rate on the Traffic page and simply never meets the budget. The cap of 256 is a cap on judgement
rather than on performance: this is an exemption from the machine's own protection, and a list nobody
reviews is a hole.

Validated in the panel **and again** in the root helper, which is what actually writes them into the
firewall — the helper runs as root and a caller is not a reason to skip a check. Anything
unrecognised is dropped with a note on stderr rather than failing the whole apply, so one
fat-fingered address cannot leave the tracker unprotected. Verified against the real `nft` parser on
the production machine with `--dry-run`, including the rule order.

One subtlety worth naming: applying a limit normally swaps a single rule by handle, to keep the
counters running. That fast path leaves the rest of the table alone — including the trusted sets — so
it is now taken only when the exemptions are unchanged. Otherwise the panel would report a list the
firewall never received.

### Fixed — the worker and the panel were two hours apart

`config/database.php` sets the panel's MySQL session to PHP's time zone, so `NOW()` and `date()`
agree for every panel request. That is right on its own, and it makes the zone a property of PHP's
configuration that nothing else connecting to the same database can know. The metadata worker uses
pymysql and got the server's SYSTEM zone: **CEST while the panel was UTC**.

Everything still "worked", which is what made it nasty. `meta_fetched_at` written by the worker read
two hours in the future to the panel, and the panel's `meta_requested_at <= NOW()` gate — the one
that spreads an auto-queue over an hour — opened two hours early.

Neither side guesses now. The panel publishes the zone it is using (`db_time_zone`, written by the
janitor when it changes), and the worker adopts it on every connection and reconnection. An
unrecognised value is logged and ignored rather than interpolated into a `SET` statement.

### Added — why the index total is falling, said on the page

A row's grace window is set when it is first inserted and never extended: a hash whose metadata does
not resolve within `index_grace_days` is dropped. That is the designed lifecycle, and with a queue of
millions it can be wildly out of step with what the worker actually manages — measured here, 34 647
resolved in a day against 449 426 about to expire, with a **three day** grace window and a queue that
would take **fifty days** to walk once.

Nothing on the page said any of that. The only visible symptom was a total that fell for days, which
reads as data loss. The Index card now shows both rates side by side, with how long a full pass would
take against the grace window, and says plainly when the window is the shorter of the two: *"most
rows are dropped before the worker reaches them"*.

The lever, once you can see it, is one setting.

### Changed — "Tracker & whitelist" was fourteen sections

Half of them were about the machine rather than about the whitelist. Split three ways:

- **Tracker & whitelist** — what the tracker serves: mode and accesslist, the schedule, whitelist
  upkeep, submissions that must prove themselves.
- **OpenTracker service** — the unit that runs it: service, performance, extra instances, live peer
  sync.
- **Network & limits** — the network it runs on: UDP traffic and the rate limit, kernel buffers, the
  stability probe.

Ratings moved to *Descriptions & review* and the two metadata sections to *Index*, where they were
always looking for. The keywords went with them, so a search for "nftables" no longer lands on the
accesslist. Biggest group is now five sections instead of fourteen.

## [1.25.2] — 2026-09-01

### Fixed — "386 870 seen · 0 kept", and an index that stopped being refreshed

1.25.1 started keeping what a truncated transfer delivered. It did not stop the resume cursor from
applying to it, and that turned out to matter: the cursor counts entries into the **complete** scrape,
and a transfer that ends early cannot contain anything past where it stopped.

One poll's transfer died at 10 MiB and left the cursor at 483 691. The next died at 8 MiB — 386 870
entries, every one of them below the cursor. The poll skipped the entire download and kept nothing,
and because the tracker mis-frames every scrape at the moment, so did the one after it. Measured on
production before the fix: **not one row of 2 957 958 had been refreshed in two hours.**

A short file is now read from the start, and the cursor moves to the **further** of the two rather
than backwards, so a later complete transfer still resumes where the last one really got to. The
suite drives exactly the production sequence — partial, then a *shorter* partial, then a complete one
— and asserts the middle poll keeps its rows and the cursor does not walk back.

The test stub for the fetch now speaks the same language as the real one (`partial`), because a path
this easy to get wrong should not be the one path the tests cannot reach.

## [1.25.1] — 2026-09-01

### Fixed — every claim was filesorting three million rows

Adding two indexes gave a reason to run `EXPLAIN` against the real table, and the answer was worse
than the thing being added. `meta_priority` is `-1` for everything the daily budget queued and `0` or
higher for the rows somebody asked for by name, so *priority first, then whatever the mode says*
leaves `meta_priority` free under a fixed `meta_status` — and **no index supplies that order**.
MariaDB filesorted the whole table, on every claim:

```
ORDER BY meta_priority DESC, meta_requested_at ASC     3 722 ms    <- the historical default
ORDER BY meta_priority DESC, seen_count      DESC     23 689 ms
ORDER BY meta_priority DESC, last_seeders    DESC     54 448 ms
```

The first line is not new; it is what the worker has always done, several times a second, against a
shared database. The other two are what 1.25.0 would have done the moment somebody picked one of the
new modes.

Same question, two lanes:

- **asked-for** — `meta_priority > -1`, forty-three thousand rows, any ordering affordable: **89 ms**
- **bulk** — `meta_priority = -1`, which makes both leading index columns equalities, so the next
  column in the index provides the sequence: **66–99 ms, no filesort at all**

The meaning is identical — everything a person requested, then the rest in the chosen order — and a
third lane with no priority predicate runs only when the other two come back empty, so a row with an
unexpected priority is never stranded. The suite asserts the property that was worth the 54 seconds:
the bulk lane must **not** mention `meta_priority` in its `ORDER BY`.

Measured, not assumed: the `EXPLAIN` and the timings above are from the production table, before and
after.

## [1.25.0] — 2026-09-01

### Added — five more ways to choose which hash gets fetched next

**Settings → Metadata fetch order** is now its own section, and the list of orderings grew to six
plus the mix:

- **Queue order** — as they were added to pending. The default, and the order every earlier release
  used; renamed from "longest waiting" because that is what it actually means.
- **Seen most often** — the most persistent swarm, by how many polls a hash has appeared in.
- **Most completed** — the most downloaded of all time.
- …alongside newest, most seeders and random.

The two new ones needed indexes that did not exist, so schema v31 adds them
(`idx_index_meta_seen`, `idx_index_meta_completed`) through the deferred-heavy path — the janitor
builds them out of band rather than a page view rebuilding a two-gigabyte table. Until an index
exists the panel shows that mode as *building* and the worker refuses it, falling back to queue
order: a missing index would not look like a missing index, it would look like a dead worker
filesorting three million rows several times a second.

What is still not offered is now written down in the panel with the reason, because an absent option
with no explanation reads as an oversight: *last seen* would sort three million rows all stamped with
the same poll time; *peak seeders* gives nearly the same ranking as *most seeders* for the cost of
another index on a table rewritten every poll; and name, size and file count are not known until the
metadata has been fetched, which is the thing being ordered.

### Added — the whitelist can take a share of the mix

**Whitelist (registered)** is a share, not a mode. At **0 — the default — nothing changes**: the
whitelist drains completely before any index row, exactly as in every earlier release, because those
rows are there because a person asked for them by name. Give it a number and it becomes a guaranteed
slice of the rotation, which is what you want when a bulk import has put fifty thousand rows in front
of the index and both need to move. A slot whose queue turns out to be empty falls through to the
other queue, so the share is a floor for the whitelist and never a ceiling on throughput.

### Fixed — the Index page's cURL error, from both ends

A full scrape that dies part-way is no longer thrown away. The parser has always been built for
partial passes — the poll-time budget stops it mid-file whenever the scrape is big, records how far
it got and resumes there next time — so a transfer that ends early is the same situation arriving by
a different route. What arrived is now parsed, the resume cursor advances, and the panel reports it
as **Partial fetch — kept what arrived** rather than as an error. Below a megabyte, or a body that
does not start like a scrape, it is still a failure and still says so.

### Changed — the settings layout

One field with a paragraph of explanation under it stretched a Bootstrap row to 400 px and left three
short fields sitting beside a hole; the user's screenshot made that plain. The reasoning is worth
keeping — it is usually the answer to "why can I not just…" — so it moved behind a disclosure rather
than being cut. Measured after: the tallest and shortest cell in that row now differ by 72 px instead
of ~400, and the fetch-order controls have their own section with the seven mix shares in two tidy
rows.

The mode select is also now paired with **what the worker is actually doing**: the index and the
`ORDER BY` the chosen mode runs on, so a mode reads as a query plan rather than a preference.

## [1.24.0] — 2026-09-01

### Added — which hash gets fetched next

**Settings → Observed-hash index → Fetch order.** The metadata worker resolves a few hashes a second
against a queue three million rows deep, so the order of that queue decides what the tracker knows
anything about for months. "Longest waiting first" is fair, and it is also the reason a release added
yesterday sits behind a million hashes nobody has seeded since 2019.

Five modes: longest waiting (the default, and the order it has always used), newest, most seeders,
random, and a balanced mix of those.

Every mode runs on an index that **already exists**, and that constraint chose the list. A claim
happens on every fetch slot, several times a second, so an ordering the database would have to
compute — by file count, by name, by how often a hash has been seen — means a filesort over three
million rows at that rate. Those orderings are absent on purpose.

`ORDER BY RAND()` is the same trap, and is not what **random** does. Info hashes are SHA-1 digests
and therefore uniform across the key space, so a random 20-byte point plus "the first pending row at
or after it" is an index seek — and uniform for exactly the reason the digests are.

The **mix** repeats over 100 claims, **interleaved rather than blocked**. That is the part worth
explaining: the worker claims in waves the size of its parallel-fetch setting, so seventy of one kind
followed by fifteen of the next would make each wave a single kind, with the balance appearing only
over hours. Interleaved, one wave is already a proportional sample. One percentage point is one claim
in a hundred — small, but never zero — and under each field the panel says what that works out to at
the parallel-fetch setting above ("≈ 5 of every 32 fetches"), flagging a share too thin to make one
per wave. The shares always total 100: raise one and the others give up the difference in proportion.

The whitelist queue is never reordered. Those rows are there because a person asked for them by name.

The worker re-reads all of it about once a minute, and reports what it is **doing** in its heartbeat
— so a worker started from an older `worker.py`, which would ignore the setting entirely, produces a
warning instead of a panel that reads the operator's own choice back to them.

### Added — the tracker binaries, the patches, and how they were built

`tools/opentracker/bin/` now carries the two opentracker builds this panel is developed against, and
`tools/opentracker/README.md` carries everything needed to distrust them: the upstream commit
(`1c7fac4`, 2026-05-26), the exact feature flags, what each one is for, what was deliberately left
out, the two patches as unified diffs, and the build recipe.

Two binaries because white or black is a **compile-time** choice in opentracker — mutually exclusive
`#ifdef`s, no runtime switch — which is why changing mode moves a symlink and restarts the service.

The recipe was verified rather than written from memory: both patches were applied to a pristine
checkout of that commit and the result compared against the tree that produced the shipped binaries.
`opentracker.c`, `trackerlogic.c` and `trackerlogic.h` came out byte-identical, and the tree built.

INSTALL.md's build step is corrected accordingly — it was missing `-DWANT_RESTRICT_STATS`, without
which `/stats` serves the entire torrent list to anyone who guesses the path.

### Fixed — a yes/no question that cost 5.5 seconds of CPU, on every poll

`status` in the netlimit helper asked whether the egress table existed by **dumping it**. That table
holds a dynamic set of up to 262 144 client addresses, and `nft list table` serialises every one of
them: measured on production with the set full, **5 547 ms of one core** — on every poll of the
Traffic page. Listing the table *names* answers the same question in 26 ms.

The test asserts the absence of the dump, not the presence of the answer, so a future edit that
"just asks nft for the table" fails in the suite rather than on the live machine.

### Changed — the index page's chunked-transfer error says what it means

"cURL error: chunk hex-length char not a hex digit: 0x55" reads like a broken panel and is not one.
The full scrape is tens of megabytes of gzip sent with `Transfer-Encoding: chunked`; when a busy
tracker gets out of step with its own framing mid-transfer, the client lands in the middle of the
body and reads a data byte where a length should be. Nothing is imported and nothing is corrupt.
The message now says so — including why retrying immediately is worse than waiting for the next poll
(opentracker rate-limits full scrapes to one per client per five minutes and answers the rest with
HTTP 402).

### Cleaned up

- The operator's real server address is out of the test suite; the checks that needed a public IP use
  the documentation range instead.
- The binaries are excluded from the deploy: they belong in the package, not in a web root.
- `.gitattributes` marks them binary and pins patches to LF — a checkout that "helpfully" rewrote
  line endings would hand out an executable that does not run and patches that do not apply.
- Every tag before this one was removed at the maintainer's request; the history itself is untouched
  and each release is still described here.

## [1.23.0] — 2026-08-31

### Fixed — the chart dropped to hourly a month before it had to

At one month the chart fell from 2,382 points to 198, on a machine whose history was eight days old.
It was not truncating data — it was choosing the hourly table because a one-month window is 8,640
five-minute buckets, which is over the cap. The buckets did not exist yet.

The raw branch had always counted the rows that would actually be returned; the five-minute branch
decided from the nominal range alone. It now counts too, so the finest resolution the stored data
supports is the one you get: **1m went from 198 to 2,386 points**. When a real month of five-minute
history exists the count will exceed the cap and hourly takes over — at the moment that becomes true,
rather than a month early.

Three months and "all" still return 198 points, and that is the honest answer: the hourly table holds
198 rows because the history began 198 hours ago. It grows at 720 a month.

### Added — the probe can move the reply budget too

`--what inbound | outbound | both`, chosen on the card. `both` keeps the reply budget a fixed distance
above the receive limit, because capping what arrives without capping what is answered moves the
problem to the transmit path — the half that makes the whole machine unreachable.

Kernel buffers remain deliberately out of scope, and the file says why: a socket's buffer is fixed
when the socket is created, so ramping one means restarting the tracker at every step — six restarts
of a live tracker to answer a question that has one obvious answer.

The self-test grew from 23 checks to 38, covering what each mode actually touches, that a dry run
touches nothing whatever it was asked to move, that restoring puts back *both* limits, that restoring
twice is a no-op, and that a machine which had no limit before a run has none after it.

### Added — INSTALL.md

A linear path from a bare Debian box to a running tracker: the database, the panel, both opentracker
builds, the janitor timer, the root helpers and their sudoers lines, the metadata worker, the tuning
order, and backups. Written from the machine that runs it, with the traps marked — opentracker's help
text lying about its own build flags, a socket buffer that ignores a live sysctl until the service
restarts, one CPU core doing all of a VPS's packet processing, and a complete backup that looks far
too small.

### Fixed

- "Only failures" in the audit log sat against the card's edge.

## [1.22.1] — 2026-08-31

### Added — the fetched-hash history really can be rebuilt

`index_hashes.meta_fetched_at` records when each hash's metadata was resolved, so the value that
would have been sampled at any past moment is a fact the database still holds: how many rows have a
fetch time at or before it. `tools/backfill_fetched.php` walks both lists once with a pointer rather
than running ten thousand COUNTs, and fills only where the value is NULL — a real measurement is
never overwritten by a reconstruction.

Run on production: **12,346 points rebuilt**, the curve now running from 4,931 on 24 August to 57,002
on 31 August instead of a flat nothing. 95 points from before the first recorded fetch were left
empty on purpose: "nobody had fetched anything yet" is a claim that data cannot support either.

Stated where it is written down, and worth repeating: the rebuild counts hashes that are *still*
resolved today, so it is a **lower bound** on what the real curve was.

### Fixed — the stability probe was measuring the one thing it was not built for

Its first version invented the netlimit counter names (`arrived`/`served`/`dropped`); the helper calls
them `in_total`/`in_passed`/`in_capped`. Every traffic figure came back empty and the report contained
nothing but load. Caught by rehearsing it on the real machine rather than by reading it.

The busiest-core share was also being taken from the lifetime counters, so it read 0.95 even in the
minute *after* the work had been spread evenly across six cores. A number that cannot show a change is
not a measurement of the present; it is now the delta between two samples.

### Fixed — an archive that holds everything looked as though it did not

A "back up everything" run produced a 158 MB file on a machine whose database is 3.3 GB, and the
built-in dump names every archive `tracker-db-*` whatever profile made it — so the filename
contradicted the choice. The archive was complete (verified by reading it: `index_hashes` is in there,
716 MB of it uncompressed). The list now shows what each archive actually contains and how far it
compressed, so the size can be judged instead of doubted.

## [1.22.0] — 2026-08-31 (schema v27 + v28 + v29)

### Added — an audit log

The panel had none. Every risky action asked for a password and then left no trace of having
happened, which is workable with one administrator and stops being workable the moment the Moderator
group exists: "who approved this description" and "who changed that setting" become questions
somebody asks.

Written in **one place**. `jsonResponse()` is the single exit every endpoint takes, so the line is
recorded there rather than by thirty endpoints each remembering to — which means a new admin endpoint
is logged **by default** and only becomes invisible if somebody puts it on the quiet list. Endpoints
that know more (which settings moved, which mode was switched to) add detail through `auditNote()`.

Three rules: writing a line never breaks the action it describes; credentials never go in (the
settings diff matches on the key **name**, so one added later is covered without anybody remembering,
and a match is recorded as "changed" with no value either side); and the actor is resolved from the
session rather than passed in.

No delete and no edit, deliberately — a log the panel it records can rewrite is not evidence.
Retention is a setting and the janitor enforces it. Its own page behind `panel.audit.view`, which is
**not** in the moderator seed.

### Added — a stability probe

`worker/tuner.py`, off by default. The Traffic page can suggest a number from a formula over past
traffic; it cannot answer the question an operator on a shared box actually has — *if I raise the
inbound limit, does anything else here start to hurt?* This answers it by trying, briefly, and
watching everything else while it does.

It walks a plan of candidate limits, holds each for a few minutes, and samples the tracker's counters
**and the drop counters of every other UDP socket on the machine**, the softirq concentration and the
load. It stops the moment a neighbour starts dropping.

Built around three rules:

- **The way back is arranged before anything changes.** The original settings are written down and
  marked for restore before the first step, so the janitor puts them back if the run is killed,
  crashes, or the machine reboots. The revert does not depend on the program surviving.
- **Harm stops the run, not the operator.** Nothing needs watching.
- **It suggests, it does not apply.** A run ends exactly where it started. Applying anything from the
  report is a separate, password-confirmed decision, and only values the run actually held are
  offered — a suggestion the machine never ran at would be a guess wearing a measurement's clothes.

No new root path: it drives the netlimit helper through the sudoers entry the panel already has, and
the janitor starts it from a request file, the same shape the deferred sysctl writes use.

### Fixed — "Fetched hashes" was a flat zero

The sampler was recording it correctly all along; every row that existed **before** the column did was
given 0, and 0 is a claim — "at that moment nothing had been fetched" — when the truth was that
nothing had been *measured*. The column is now nullable, the payload passes null through, and the
line simply does not start until the data does.

### Added — two backup profiles, and honest names for the others

"Database only" was in fact the **full** database including the index tables, several GB, and nothing
in its name said so. The labels now name which database and what else. The genuinely missing
combination — the database *without* the two huge tables — is now there.

### Fixed

- Search cells are centred again; top-aligning them left the numbers floating beside a wrapped name.
- **Send…** now says who it is about to write to and how many.
- The buffer verdict's "restart the tracker" sentence has a **Restart** button next to it, password
  confirmed. Advice with the action attached is the difference between a page that explains and a page
  that works.
- The RPS advice carries the exact command for **this** machine — the mask built from its real core
  count, one line per receive queue — with a copy button. The panel still does not write it: `rps_cpus`
  is system-wide, like the sysctls beside it.
- The panel was marking up a custom checkbox whose every CSS rule lived in the **public** stylesheet,
  which no admin page loads. On screen that was the browser's own checkbox, an empty `<span>`, and the
  label with nothing between them.
- The message toolbar in *Write to members* carries the same nineteen buttons as the public editor.

## [1.21.1] — 2026-08-31

### Added — the Traffic page now notices when one core is doing all the work

A single-queue virtio NIC delivers every interrupt to the same core, and Receive Packet Steering
spreads that work in software. With it off, one core does all the receive processing however many the
machine has.

Measured on this server: **CPU2 has handled 99.9% of every packet the machine has ever received**,
across six cores, with `rps_cpus` at zero. That is invisible in every other number on the page — the
tracker's own load is about 15% of the box, the per-CPU queue has never overflowed — and it is the
one thing that explains the symptom the operator actually hit: raising the inbound limit made other
services on the same machine lose packets and made SSH sluggish, while nothing on the tracker looked
busy. They were all queued behind one core.

The panel reads `/proc/net/softnet_stat` and the receive queues' `rps_cpus` directly, says which core
and what share, and gives the one-line change. It does not write it: `rps_cpus` is system-wide, like
the sysctls next to it.

## [1.21.0] — 2026-08-31 (schema v26)

### Fixed — the second attack round found eight more, and they had one cause

The renderer attack was re-run to completion (the first pass lost five of fourteen agents to a usage
limit). Every new finding was the same mistake in a different costume: **`[hide]` ran too late**,
after passes that move, consume or rewrite the author's text.

- A Markdown **footnote defined inside a hidden block was lifted out** and re-parked at the end of the
  document, outside it — served to guests with the link live and, with an image footnote, fetched by
  their browser on load.
- A greedy `[^\]]*` parameter in `[color=…]` **swallowed the opener**, so the block never matched.
- A `[code]` fence around the whole thing **hid the tokens** from the rule meant to act on them.
- Markdown link syntax **consumed both tokens as a label**.
- `[img]`, `[table]` and `[list]` **deleted the marker with their own body**.
- A description containing only `[/hide]` skipped the check entirely, because the guard looked for an
  opener.

`[hide]` is now resolved on the **raw input, before any other rule exists**. A guest's bytes are
dropped there and never enter the pipeline; a member's block is rendered one level down so its markup
still works. The excerpt follows the same rule: unbalanced fences produce no excerpt at all, because
truncating at the token kept the secret that sat in front of a stray closer.

### Fixed — an image alt could break out of its attribute

`![<kbd>x</kbd>](url)` produced `alt="<kbd>x</kbd>"` with a raw `>` in it: the `<img>` closed early,
the rest of the attribute fell into the document, and the linkifier built an `<a>` inside what had
been the `src`. The alt is now re-escaped from scratch, and the pass that un-escapes allowed HTML tags
runs **outside tags only** — it was rewriting the inside of attributes other rules had already built.

### Fixed — paragraphs were built by guessing

The tidy-up inserted `</p>` before every block open and `<p>` after every close. That is wrong as soon
as a block opens inside another: a `</p>` appeared with no paragraph open and a later pass read it as
the closer of the `<details>` or `<blockquote>` around it. Reported symptoms — an unclosed `<details>`
swallowing the rest of the page, `</p>` closing a `<blockquote>`, inline tags reparenting the DOM —
were all that one bug.

Paragraphs are now built by a pass that walks the string and counts nesting, wrapping loose text at
the top level only. An inline run interrupted by a block is closed and resumed; an anchor is closed
and **not** resumed, because reopening it without its href makes a dead link and with it makes two.

### Fixed — the buffer verdict told you to leave it alone

The Traffic page said "This tracker asks for its own receive buffer size (208 KiB) and is not being
clamped", which steered the operator away from the only knob that helps. The socket was not asking:
it was **older than the setting**. A receive buffer is fixed when the socket is created, so raising
`rmem_default` reaches nothing already running. Measured here: 8 MiB set, the tracker socket still at
208 KiB two days later, 43.6 million packets discarded by that queue in the meantime. The verdict now
recognises a socket smaller than the current default and names the restart.

### Added

- **Fetched hashes** on the swarm timeline, beside Indexed hashes and off by default. The gap between
  the two lines is the metadata backlog.
- **The backup type is chosen when you start a manual backup**, instead of only in Settings — the
  schedule and a backup somebody runs by hand are usually different questions.

### Fixed — the panel was using a checkbox it did not have

`templates/admin/users.php` marks up `.search-check`, and every rule for it lived in the **public**
stylesheet, which no admin page loads. On screen: the browser's own checkbox (the rule hiding the real
input was missing too), an empty `<span>`, then the label with nothing between them.

Also: the message toolbar in *Write to members* now carries the same nineteen buttons as the public
editor, and the actions column in search results no longer breaks when a long name wraps — a
`display:flex` on the `<td>` stopped it being a table cell, so it did not stretch with the row.

## [1.20.1] — 2026-08-28

### Fixed

- **A block element in the middle of a paragraph now splits it.** `<p>a <div>b</div> c</p>` is invalid
  and a browser does not render it as written — it closes the paragraph before the block and orphans
  the tail, which appears as a gap the author never typed. The tidy-up only ever handled a block at
  the start or the end, so any `[center]` or `[hr]` in mid-sentence produced one.
- The eight findings from the pre-release attack on the renderer, and the guards that came with them,
  landed here rather than in 1.20.0: `[hide]` failing open on nested or stray tags, `[hide]` missing
  from Markdown entirely, excerpts publishing hidden text, a link limit that could not see most
  links, and emoji rewriting URLs after they had been validated.

## [1.20.0] — 2026-08-28 (schema v24 + v25)

Six defects, four of them mine, three of them silently breaking things on a live tracker.

### Fixed — permissions that were registered and granted to nobody

v1.19.0 added `rating.vote`, `content.submit` and `content.propose` and never gave them to a single
group. With the users feature ON an absent key means **denied**, so the release notes said ratings
and descriptions were available and in practice only administrators could use either. Measured on
production: the `member` group carried 8 of the 11 registered permissions, and the three missing ones
were exactly the new ones.

`userLegacyDefault()` was supposed to cover this and does not — it is reached only when accounts are
switched OFF, which is the one case where there are no groups to grant anything to. The fix is a
one-shot grant in the schema migration, and a test that fails the next time a permission is
registered without deciding who has it. One-shot matters: the data migration runs on every version
bump, so a plain grant would resurrect a permission an operator had deliberately removed.

### Fixed — the description preview and the submission progress never ran at all

`postJson` and `getJson` were declared inside the accounts IIFE. Two later features call them from
their own IIFEs, so both threw `ReferenceError` on their first call — silently, because neither call
site was awaited. The preview box opened empty and stayed empty; the "checking your submission" list
never appeared. Both are now file-scope.

The preview also poisoned its own retry cache: it recorded the request key *before* asking and never
cleared it on failure, so one 403 or one dropped connection wedged that exact text for good. It now
records only what it actually managed to display.

### Added — the description editor has an editor

`.rt-tabs`, `.rt-tab`, `.rt-counter` and `.rt-preview` were emitted by the template and had **no CSS
rule anywhere**, so the tabs rendered as raw browser buttons. The format dropdown was worse than
unstyled: it inherited `.form-group select { width: 100% }` and stretched across the form.

There is now a real toolbar — bold, italic, link, list, quote, code — with **Ctrl+B / Ctrl+I /
Ctrl+K**, inserting whichever syntax the selected format uses, a live character counter, and a
one-line reminder of the syntax. Same mechanism as the admin bulk-mail composer.

### Fixed — the tracker mode was a database row that told the tracker nothing

Changing **Tracker mode** in Settings wrote a row. It did not move the symlinks and it did not
restart the service: the only code that ever ran the mode helper was the schedule, and only while the
schedule was switched on. So an operator could select "whitelist", watch a whitelist file appear —
159 hashes, written and correct — and be served by the blacklist build the entire time, with every
status card agreeing with them because every status card was reading the row they had just written.

Found on production, in exactly that state.

Now: the Whitelist status card asks the helper what is **actually** running and says plainly when the
two disagree; **Switch the tracker now** does the real thing (prepare the list, run the helper,
restart, and only then flip the setting) behind a password like every other action that changes the
machine; **Test** reports what the helper says; saving a mode change that has not reached the tracker
returns a warning instead of a success; and the janitor logs a mismatch within a minute of it
appearing.

### Fixed — 32 parallel fetches became 4

The metadata worker's ceiling was raised from 16 to 64 and `worker.py` on the server was updated —
but the process was never restarted, so it kept running the old code with the old limit. It read the
admin's 32, decided a number above 16 was garbage, and fell back to the **config file's 4**. Asking
for more parallelism produced less than before, and every number in the panel looked right.

An out-of-range value is now clamped rather than discarded, so a version-skewed worker degrades to
its own ceiling instead of to the config default, and it says which happened. The ceiling is one
named constant instead of a literal in two places. The heartbeat file carries what the worker is
actually doing — version, effective concurrency, active fetches — so the panel can report reality
rather than reading the setting back to the operator, and warns when the two differ. The panel's
clamp also had no lower bound: 0 was silently rewritten to "use the worker's config".

### Fixed — saving Settings while filtered made the whole page flash

Also mine. A capture-phase click listener lifted the group/search filter before every submit — to
stop Chrome refusing to focus an invalid control inside a `display:none` block — and restored it in a
`setTimeout`. Two tasks, so the browser had a rendering opportunity in between and painted all 27
sections at once. Worse, when validation actually failed the submit event never fired, so the
restore never ran and the filter was lost for good.

It now touches nothing unless the form is genuinely invalid, and then unhides only the ancestors of
the offending field. Measured after the change: with one section showing, a `MutationObserver`
watching class changes across the whole save never saw a second one appear.

### Added — the review state is visible from search

The approved/rejected filter existed; **pending** did not. "Unreviewed only" folded *waiting for a
moderator* together with *nobody has written anything*, which are different facts, and no badge was
drawn for pending at all — so a moderator could not ask "what is waiting?" anywhere in search. Both
now exist.

### Changed — the three detail panels are one design again

The Info panel's primitives (the stat strip, the chips, the banded sections) lived in the public-only
stylesheet, so the admin panels could not use them however much anybody wanted them to. They now sit
in `assets/css/detail-panel.css`, which both layouts load.

The admin Whitelist panel was not smaller because data was missing — `SELECT *` fetched all of it and
the renderer read a third of it. It now shows the swarm strip, the rating (which the row listing
already showed, so clicking a row for *more* detail showed less), the "prove it" state, the dead-row
mark, and — by joining the catalogue — first seen, last seen, times seen and peak seeders. A
registered hash the tracker has never been asked for now says so.

## [1.19.1] — 2026-08-28

### Fixed

The OpenTracker performance card still left a hole: five tiles in a three-column grid fill two rows
and leave the sixth slot empty. The drop-in tile — the only one carrying a sentence — now spans two
columns, so the grid is full: three tiles, then one plus a wide one, then the load chart across the
whole row. Measured on production: 334 / 334 / 334, then 334 / 678, then 1021.

## [1.19.0] — 2026-08-28 (schema v22 + v23)

### Added — five stars, in half steps

A second rating mode beside up/down. Five stars on screen, **ten steps underneath**, because that is
what "half a star" actually means: 3.5 is a value somebody can cast, not one inferred from a
percentage. Hovering previews what a click would set and leaving puts back what is really stored —
a widget that keeps the last hovered value is telling you something you never did.

`votes_count` is a new column rather than a reuse of `votes_up`. In star mode there is no "up" and no
"down"; there is a count and an average. Reusing one column to mean "the count here, up-votes there"
is exactly the overload that produces a wrong number two releases later, in whichever branch nobody
re-read.

Ratings apply to **any hash the catalogue knows**, whitelisted or not, and the operator can let
anonymous visitors vote. An opinion about a torrent is not a statement about whether this tracker
serves it.

Fixed while adding it: `SUM(vote * weight)` overflowed. `weight` is `SMALLINT UNSIGNED`, so MySQL
promoted the product to unsigned and `-1 * 100` became a number the size of the universe. The first
down-vote on a live tracker would have taken the ratings down with it. Now `CAST(weight AS SIGNED)`,
with a test that casts one.

### Added — formatting in bulk messages

Markdown or BBCode in a message to every member, with a toolbar and **Ctrl+B / Ctrl+I / Ctrl+K**
working in the box. A `<textarea>` gets none of those shortcuts for free, so the keys and the buttons
run the same wrapper; a `contenteditable` box would have given Ctrl+B for nothing and cost a second
renderer, a paste sanitiser and an HTML whitelist to police.

The preview is a round trip to `bulkBodyHtml()` — the same function the janitor calls when it builds
the mail. A preview drawn by different code is a guess about what will arrive.

Mail clients drop `<style>` and usually drop `class` too, so the site's renderer output is run
through an inliner: the markup is produced exactly as on the site, then the handful of classes are
swapped for the styles they stand for. Change a rule on the site and the mail follows.

The format is stored **per queued row**, not read from settings at send time. A batch written in
Markdown and sent an hour after somebody switched Markdown off still arrives as its author saw it.

### Added — the review queue's missing half

`admin/wl_content` has had `edits`, `edit_apply` and `edit_reject` since rewrite proposals shipped,
and Settings has pointed at "Whitelist → To review → Rewrites" ever since. **That screen did not
exist.** It does now: the published text and the proposed one side by side, both rendered, because a
rewrite that reads tamer in source and worse on screen is the entire risk of accepting one.

The queue itself gained a search (hash, name, link, or a word from the text) and a filter for
published and rejected items, so "why is this public" and "who rejected mine" can be answered
without reading the database by hand. The tab badge keeps counting what is **waiting** whatever the
filter shows — a badge that followed the filter would read zero with a full queue behind it.

### Added — the link an importer recorded now reaches the public page

When the forum posts a magnet it records the thread it came from in `whitelist.source_ref`. That
answers the same question as a source link typed into the form, and it was visible only to admins.

It is now shown in the same place, marked as automatic — but only when it points at **this
operator's own site**. It never passed the review queue, and an API client is not necessarily run by
the operator; anybody else's importer can still record a link, and that one waits for a moderator
like any other.

### Fixed — a single isolated sample turned on markers for a whole chart

`points.show` in uPlot returns a **boolean**; `points.filter` returns the index array. The previous
fix returned the array from `show`, and an array is truthy — so one isolated sample switched markers
on for every point in the series. That is why 7d and 2w were speckled and 24h was clean: those
ranges happen to contain exactly one isolated sample (measured on the live database: 14 gaps in the
rate series over a week, one of them isolated).

There is now a test that reproduces uPlot's own call site — `(show || filter) && paint(filter)` —
rather than checking that the right indices come back, because the first version returned the right
indices and still painted everything.

### Fixed — the settings search drew sections on top of each other

Bootstrap builds its gutter from a negative margin on `.row` plus matching padding on the cells.
Hide every cell and the padding goes with them; the negative margin stays. So a row the search
emptied did not collapse — it pulled everything after it a gutter's width **upwards**. Three emptied
rows in one section (Backups managed that on most queries) dragged the next row 48 px up, over the
rows above it.

Measured on production before and after: six of fifteen sample queries produced a real overlap;
after the fix, none did, in either place.

### Fixed — the Traffic page said Apply would fail, and it does not

"That directory is read-only … so Apply would fail from here" described something that does not
happen: the helper reports `deferred`, the panel records the change, and the janitor writes the file
within a minute. The message made a working feature look broken. The load-per-thread chart also
moved to its own full-width tile — five short tiles and one tall one in the same auto-fill grid left
a hole the height of the tall one, which is the "5 on top, 1 below" that never looked right.

### Changed — appearance

- **The Info panel** is now four bands: the swarm numbers first and largest, then the chips that
  qualify them, then prose and rating, then the record. Seeders and leechers used to be a sentence
  three quarters of the way down a flat list, below "Times seen". Reading order is a claim about
  importance, and that one was wrong.
- **The search results page** is wider (86em) and the actions column fits its three buttons.
  Measured before: the Info button's right edge sat 22 px outside its cell.
- **Email management** on the account page: the two mail switches were rows in the Profile table,
  where a two-line explanation had to fit a 272 px cell and made one row 137 px tall. They are
  choices, not facts about the account, and now they have the card's width and read as a short list
  of decisions.
- **Review cards** lead with their state — a coloured edge *and* an icon and a word, because a
  colour alone is not a label — with the identifying facts on their own line and the parts of a
  submission separated.
- Every `<option>` in Settings now says what it means. A menu of bare "On" / "Off" is what Chrome's
  auto-translate turned into meaningless two-letter particles, and no `<option>` in the form is a
  bare word any more.

### Changed — permissions

`rating.vote`, `content.submit` and `content.propose` exist as real permissions rather than being
implied by whatever else an account could do. Legacy installs keep the behaviour they had.

## [1.18.0] — 2026-08-28 (schema v21)

### Added — ratings, and the four things that stop them being worthless

Up or down on a torrent, in the Info panel and optionally as a column in the search results.
**Off by default.**

Counting two numbers is trivial. What is not trivial is that a public voting button is the easiest
thing on a site to automate — one loop, a thousand negatives, and the score means nothing for ever.
So almost all of this is about who may press it:

- **One vote per identity, enforced by a UNIQUE key in the database.** Not by a `SELECT` in PHP: two
  requests arriving together must collide somewhere real, and a check-then-insert is a race with a
  comfortable window. Voting again changes your mind rather than adding a second vote.
- **Rate limited** per address bucket and per account, on the limiter already here.
- **CAPTCHA through the existing points scheme.** A vote adds points; the challenge appears at the
  threshold. Steady use is never interrupted, fifty votes in a minute meets a CAPTCHA, and nobody had
  to write a bot detector.
- **Weight**: an anonymous vote counts for a quarter of an account's by default.
- Below a configurable number of votes there is **no percentage at all**, because "100% from one
  vote" and "100% from four hundred" are different facts and a bar that draws them the same is lying.

**On attributing votes to an IP address** — the question was whether PHP is broken here. It is not.
`REMOTE_ADDR` comes from the TCP connection: forging it means completing a handshake from the forged
address, which over the internet means it is not forged. Headers *are* forgeable, and `getClientIp()`
already refuses to read one unless the request genuinely arrived from an address in
`trusted_proxy_ips`. What none of that fixes is one person with a VPN and a phone, so IPv6 is
bucketed to a /64 and the panel says plainly that anonymous votes are a weak signal rather than
implying a precision it does not have.

### Added — a submission can be made to prove itself

**Settings → Make submissions prove themselves.** With it on, a new registration must show that the
metadata resolves *and* that a scrape finds at least one peer — meaning the torrent exists, is alive,
and names this tracker. Until then the accesslist generator skips it, so nothing is served on the
strength of somebody having typed forty hex characters.

It reuses the metadata worker instead of adding a second queue (a second queue means a second thing
that can get stuck, to do a job the first one already does) but **jumps the queue**: somebody is
watching this one and nothing else in it is. The submission form shows a line per hash with its own
state, and a failure says *which half* failed — "nobody is sharing this" and "we could not read the
torrent" send somebody to completely different places.

Existing rows count as already accepted, so switching this on never unpublishes anything. Verified on
production: 159 rows before, 159 after.

### Added — descriptions can be rewritten, by proposal

Anyone can register anyone's hash, so the first person to describe a torrent is not automatically the
right one — and "whoever submits last wins" would be an invitation. A later submission with a
description now **proposes a replacement**, visible to a moderator with the old and new versions
rendered side by side. Applying keeps the version it replaced, so an accepted rewrite can be undone.

### Added — the whitelist keeps itself honest

Two janitor jobs, both off by default: **refresh** re-scrapes rows whose numbers are older than N
hours, stalest first; the **dead-row rule** finds rows with no seeders and no leechers for N days.
Its default action is to **mark, not delete** — an automation that quietly removes other people's
registrations is something an operator chooses in as many words. A row that has never been scraped is
never called dead: no data is not the same as no peers, and the difference matters most when the
scrape path is broken, which is exactly when a delete-on-zero rule would empty the list.

### Added — a preview for descriptions, and the reviewed states in search

- **Write / Preview** on the description field. The renderer stays on the server — it is the only
  place that can guarantee what comes out, and moving it into the browser would move the guarantee to
  the least trustworthy place in the system — so the preview is a round trip. Slower, and correct.
- The search gains a filter for reviewed state (hide rejected by default, or approved only, or
  unreviewed only) and a badge beside the name. **Hiding rejected is the default and hiding the
  torrent is not**: a judgement about somebody's words is not a judgement about a swarm.
- **Always publish** as a separate switch from *Review before publishing*, because "I do not
  moderate" and "I moderate, but let this through" are different decisions.
- Banning a hash now clears its pending description, its pending rewrites and its ratings — in
  `whitelistBan()`, the one function every ban path goes through, rather than copied into each.

### Fixed — the public search held the session lock

Clicking Stats a second after clicking Search waited for the search: the same exclusive session lock
that made the admin Index feel jammed, from the public side. The three read-only search endpoints
release it.

**Twice, because the first attempt was wrong in an instructive way.** Releasing it in the router —
before the endpoint ran — meant `userCan()` and `currentUser()` saw nothing, and a signed-in member
got `login_required` on their own search. The smoke suite caught it. The release now sits *after* the
permission checks, in each endpoint, where it is provable rather than plausible.

### Fixed — white dots all over the charts on a wide screen

uPlot turns point markers on once the average pixel gap between samples passes a threshold, so the
same chart was clean on a laptop and speckled on a wide monitor — a decision about the window, not
about the data. Markers are now drawn only where the line cannot show a value on its own: a sample
with a gap on both sides, which with `spanGaps: false` would otherwise be invisible. The hover point
is drawn separately by uPlot and is untouched.

### Changed — the metadata worker can now run 64 fetches at once

Was capped at 16 in four places. Each fetch is one libtorrent handle holding a small set of DHT and
peer connections, so the ceiling is file descriptors and memory rather than anything in libtorrent —
at the top end, a few hundred sockets and a few hundred MB. The setting hint says so, because a
number available is not a number free.

### Security — an audit of every query, with a mechanism to keep it true

`tests/sql_safety_test.php` walks every PHP file with PHP's own tokeniser, finds every variable that
reaches the text of a `query()`, `exec()` or `prepare()`, and requires each to be either a shape that
is safe by construction or an entry in a **reviewed baseline with a written reason**.

**Result: zero injection findings.** Every site is an allow-listed identifier, an integer already
cast, or a fragment assembled from literals with its values bound.

Two earlier versions of the test are worth recording, because they are the reason this one is
trustworthy. The first scanned lines for SQL keywords and reported eighty-eight false positives —
including `->execute([$id])` next to a parameterised query. The second looked inside string literals
and reported the `From:` header of an email and an error message containing the word "limit". Prose
is full of SQL keywords; only a query is a query. A test nobody believes gets switched off, and then
it protects nothing.

It has already earned its place: it caught a new interpolation in `includes/reputation.php` the same
day it was written.

### Corrected — livesync is not compiled into the binary in service, and the panel believed otherwise

E7 shipped in 1.17.0 saying livesync was "verified against the shipped binary". **That verification
was wrong**, and running it is what showed it: the opentracker on this server **rejects `-s`
outright**. It was built without `-DWANT_SYNC_LIVE` — confirmed against the build documentation,
whose FEATURES line does not contain it.

Two things lie about this, and both were believed:

- opentracker prints **one static usage string** whatever it was built with, so `-h` advertises
  `-s livesyncport` on a build that refuses it;
- `/stats` carries a `<livesync>` section on the same build.

The panel's Test read the first of those. It now **probes the flag** — runs the binary with `-s` for
a moment and sees whether it is taken — and when it is not, says so and names `-DWANT_SYNC_LIVE`.

A second finding from the same run: **livesync is MULTICAST.** A livesync-enabled build joins
224.0.23.5 and binds two sockets on the sync port; the `-A <peer>/32` is an *admin blessing*, not a
destination. A WireGuard tunnel therefore needs a multicast route on both ends, which the setup hints
now print.

**What is still unproven:** whether peers actually propagate. In a rig of two opentrackers in
separate network namespaces on a veth pair, an announce to one never reached the other and the
sending side emitted zero packets in twenty-five seconds — consistent with opentracker's own batching
or with multicast in that rig, and not distinguished between. The code and the docs now say so
plainly: **"on" is not proof that peers are flowing** — the `<livesync><count>` counter is, and it
must climb. Nothing on the production tracker was touched by any of this; the throwaway binary,
namespaces and sources were removed afterwards.

### Testing

- `tests/reputation_test.php` (34 checks) proves the one-vote-per-identity rule **against the live
  schema** — two inserts for the same identity, one row out — rather than against the PHP that
  intends it.
- `tests/sql_safety_test.php` (7 checks) as above.
- 20 PHP suites, ~1560 checks; 3 smoke suites; 2 HTTP suites; the UI test. All green.

## [1.17.0] — 2026-08-28 (schema v19 + v20)

### Added — a source link and a description on a registered torrent (schema v19)

Two optional fields on the registration form: **where this came from**, and **what it is**. They
appear on the Whitelist and Index detail panels and in the public search. **Both off by default**,
because this is text an anonymous stranger types and the site then publishes under its own domain.

- **The description renderer is written here, not imported** (`includes/richtext.php`). Every
  general-purpose Markdown and BBCode parser is built to be permissive — they pass raw HTML through
  by design, and the ones that filter it use a blacklist that has to be kept ahead of whoever is
  trying. That is the wrong shape for text from a public form. So the input is **escaped in full
  before a single rule runs**, and the only tags in the output are the ones this file writes. There
  is no path — not a nesting, not a broken tag, not an encoding trick — by which a `<script>` in
  becomes a `<script>` out, because by the time any rule sees it the brackets are already `&lt;`.
- **Both syntaxes, and the writer picks.** `[b] [i] [u] [s] [code] [quote] [list] [url] [img]` and
  the useful half of Markdown. `[code]` is pulled out first and put back last, so a description
  explaining BBCode does not get its own example rendered.
- **A review queue.** New links and descriptions wait under **Whitelist → To review** until somebody
  publishes them; the torrent itself registers immediately and is never held up by its words. The
  moderator sees the description **rendered**, exactly as a visitor would — reviewing the source is
  how an image tag gets waved through because nobody saw what it pointed at. Can be switched off.
- **Off-site links ask first.** Any link a submitter wrote opens a confirmation naming the URL and
  saying plainly that the site has not checked it. Domains on `link_trusted_domains` skip it
  (default: `tryhackx.org`) — warning about your own site only teaches people to click through. One
  delegated listener on the document, in the panel and on the public pages, so the next place that
  renders a description gets it without having to remember.
- **The source link must be https.** Plain HTTP is refused rather than upgraded: the page is served
  over TLS and must not hand anyone a downgrade. Credentials in the URL, private addresses and hosts
  with no domain are refused too.
- **An Info panel in the public search**, beside Copy: the source link, the description, the numbers
  (first seen, last seen, swarm, peak seeders, size), and the file list at the bottom. The existing
  "N files" chip still opens the plain tree on its own — somebody who only wants file names should
  not have to read an essay to reach them. Optionally a **Refresh seeders** button, off by default
  and rate-limited per hash across all visitors, because it turns a stranger's click into a request
  to the tracker.

### Added — writing to members: bulk mail and notifications

**Users → Write to members.** A message to the accounts you ticked, to one group, or to everyone.

- **Nothing is sent from a web request.** The panel writes rows into `mail_queue`; the janitor sends
  them a few a minute. This server sends through `mail()` with no relay in front of it, and a burst
  from a domain that normally sends a handful a day is what gets the *password-reset* mail filed as
  spam. Rate, retries and back-off are settings.
- **The real number, before and at the moment of committing.** "Everyone" that quietly means 41 of
  53 is the kind of surprise that surfaces a week later as "why did I never hear about it", so the
  panel shows who is excluded and why: no address, opted out, unsubscribed.
- **Members can opt out** of announcements from their account page, and every bulk message carries an
  unsubscribe link. Transactional mail — password resets, verification — is unaffected: somebody who
  wants no newsletter still needs to get back into their account.
- In-app notifications go through the same form and need no queue.
- A send can be **stopped** while it is still going out. What has already left cannot be recalled,
  and the panel says so rather than implying otherwise.

### Added — live peer sync between two trackers (E7, the last stage of PLAN-federation)

opentracker can gossip **live peers** to another opentracker: who is in which swarm, right now. It
is not federation — federation moves metadata between panels over HTTPS with a key; this moves the
swarm itself between trackers.

- **It has no authentication and no encryption**, so the helper **refuses** to arm unless the port is
  bound to a tunnel interface. There is no override flag, on purpose: an override is the only feature
  anybody would regret adding here. A public bind address, a public peer, or an address on an
  ordinary interface are all refusals with an explanation, not warnings.
- **The panel does not configure WireGuard.** Generating a private key and writing it into `/etc` is
  a larger claim on the machine than anything else here makes, and it would be doing it half-blind —
  it cannot see the other end of a tunnel. Test prints the commands instead.
- Verified against the shipped binary rather than assumed: this build takes livesync **only from the
  command line**, its config parser knowing `listen.*`, `access.*` and `tracker.*` and nothing else.
  So the helper overrides `ExecStart` in its own drop-in — the most invasive thing the panel does
  anywhere — and therefore **records the command line it copied** and reports when the unit's own has
  changed underneath it. A stale copied ExecStart is the failure mode of that technique, and it must
  be visible rather than mysterious.
- After arming, the helper **checks the port is actually listening, and on the tunnel address only**.
  If it is not, it undoes its own change before answering, so a failure leaves nothing armed.

### Fixed — the Index page's own first page was a full scan of 2.7 million rows

Measured on the live table: `ORDER BY last_seeders DESC, last_seen DESC LIMIT 50` ran as
`type=ALL … Using filesort` — every row, every time, **1 747 ms**. There was an index on
`last_seeders` alone, and a single-column index cannot satisfy a two-column sort, so the optimiser
discarded it. Two composite indexes later (v19, applied by the janitor because a FULLTEXT table
rebuild must never run in a page view): **0.8 ms**, a covering read of exactly fifty rows. The public
search, the same shape with a filter in front, went to 2.6 ms.

This is also the answer to "should the tables be cached". Whitelist, users and banned hashes were
measured at **0.3–1.0 ms** — a cache there buys nothing and costs correctness. The catalogue was
slow for a reason a cache would have hidden, and hidden expensively: that scan also evicts a 512 MB
InnoDB buffer pool with 2 GB of table, on a database shared with the mail, the forum and the file
service. **One** query was worth caching: the unfiltered `COUNT(*)` behind the pager, which no index
can help (557 ms, InnoDB keeps no row counter) and which draws a number that does not need to be
exact. Thirty seconds, dropped the moment a poll, prune or delete changes the count — and never used
where the number decides something, because a pager may be approximate and a delete may not.

### Fixed — wrong password confirmations could be guessed for ever

Every dangerous action asks for the password again. That check sat inline at **fourteen call sites**
as a bare `password_verify()` with no counter between them, so somebody who already had a session —
a borrowed laptop, an unlocked screen, a stolen cookie — could try as long as they liked. The session
gate stops a stranger; it does nothing about the person already inside, which is the case the
password prompt exists for.

There is now one function and it is the only way to check that password. Every wrong answer costs
progressively more time starting at once; after `admin_reauth_max_attempts` (default 5) **the session
is destroyed**, so getting back in means the sign-in page with its CAPTCHA and address lockout; and
failures count against that same lockout, so guessing here poisons the way back in rather than being
a quiet side door around it. A test asserts the *property* — no endpoint may verify the admin
password on its own — so an endpoint written next year gets the throttle because there is nowhere
else to get the check from.

### Fixed — smaller things that were reported

- **The "Actions" column header was cut off** on the reports table. Measured: 64 px of content box
  for a label needing 72, on a fixed-layout table with `overflow: hidden`. A column heading that
  cannot show its own name is the one place a width may not save space.
- **The Unban confirmation was unreadable.** A 40-character hash is one unbreakable token; dropped
  into a centred sentence in a small dialog it either overflowed or broke at whatever letter the line
  ended on. Identifiers now get their own line in a monospace box — something you can actually
  compare against what you meant to unban.
- **"HTTP 402" from the index poll now says what it is.** It is opentracker refusing a *full scrape*
  asked for too soon, it clears itself on the next poll, and it has nothing to do with the UDP
  throttle — which is UDP-only, while that request is HTTP. A bare status code sent whoever read it
  hunting through rate limits that were not involved.
- **The Test button said "Empty" about a field that looked filled.** The cluster card's *Helper
  command* shows a grey `sudo -n …` placeholder; the field was empty and the Test was correct, and
  still misleading enough that the person who knows this panel best read it as configured and
  reported the Test as broken. Six fields whose placeholder is a ready-to-paste command now read
  `e.g. …`, matching the convention the same file already used and applied unevenly, and the message
  names the grey text rather than describing a field the reader cannot see.
- The admin password prompt is a proper dialog now, not `window.prompt()` — unstyled, suppressible by
  some browsers, and showing the password in clear.

### Testing

- `tests/richtext_test.php` (36 checks) is mostly attacks: fourteen injection techniques, eight
  dangerous URL forms, and each checked against **output** rather than against the rule meant to stop
  it — a rule can be right and still be reached too late.
- `tests/livesync_test.php` (51 checks) is mostly refusals, driven against stub `ip`/`ss`/`systemctl`
  so the helper's own address logic runs rather than being read.
- Schema v20 is settings only, and needed its own number for the reason every "settings only" bump in
  this project needed one: default rows are inserted by the migration block, and that block runs when
  the version moves.

## [1.16.0] — 2026-08-28

### Added — extra opentracker instances finally receive traffic (E6, completed)

The instance machinery shipped in 1.14.0 could create a second opentracker, bind it, switch its mode
and remove it cleanly. What it could not do was give it a single announce to answer.

**Separate ports do not share traffic.** The kernel does not split one UDP port across processes, and
opentracker spreads load across THREADS on one socket (`listen.udp.workers`) rather than across
processes. So an extra instance on port 6970 receives exactly the announces whose magnet names 6970
— and while every status card reported it healthy, it sat at zero. The public pages only ever
advertised the primary's port.

- **`announceUrls()`** (`includes/whitelist.php`) is now the single source of announce URLs, and
  `buildMagnet()` builds from it. Every active extra port lands in the magnet the panel hands out.
- The **home page**, the **whitelist page**, the **search form** (which builds magnets in the
  browser) and the **submit response** all carry the full set. The home page also says, in the
  visitor's own terms, why there is more than one and that all of them should be added.
- **An instance that is not listening is never advertised.** The roster's `state` gates it, so a
  stopped extra silently drops out of the magnets instead of sending clients at a dead port.
- **Nothing changes on an install without the cluster** — verified by test, not by inspection: the
  same two URLs, in the same order, and not one file read from disk.

### Fixed — the Test button said "Empty" about a field that looked filled

The cluster card's **Helper command** shows `sudo -n /usr/local/sbin/tracker-cluster.sh` in grey.
That is a placeholder, the field is empty, and the Test button was correct — and still misleading
enough that the person who knows this panel best read it as configured and reported the Test as
broken. Being right is not the same as being understood.

- Six fields whose placeholder is a ready-to-paste command now read **`e.g. …`**, matching the
  `e.g. 2-5` convention the same file already used elsewhere and applied unevenly.
- The Test message names the thing the reader is actually looking at: *"Nothing is saved here … the
  grey text in the field is a suggestion, not a value — type it in and press Save."*

### Testing

- **`tests/announce_multiport_test.py`** (17 checks) asks the running server for the real pages with a
  roster injected, and reads what a visitor would see. It exists because a unit test did not catch a
  real break: that one asserted a template *contained* `$extraUrls`, which stayed true after an edit
  dropped the line that *assigns* it — the page rendered with no extra port and the check went on
  passing. A test that greps for a name proves the name is present, nothing more.
- The unit check now requires the assignment as well as the render, so it fails earlier and cheaper.
- `tests/cluster_test.php` grew to 91 checks: an active instance is advertised, an inactive one never
  is, the primary's URLs stay first, and the magnet carries four `tr=` entries instead of two.
- The new test sets up its own preconditions (whitelist mode, catalogue on, a CAPTCHA that is never
  called, an admin session for the permission-gated search page) and restores every one of them,
  including on failure — proven by running it twice in a row against an unchanged tracker.

### Measured, not assumed

On production: one opentracker process, **10 threads, one UDP socket** (`fd=5`) on 6969 — no
`SO_REUSEPORT`, no second socket. The panel's own performance card agrees: *"One instance is using
97% of the 600% this machine has … A second instance would add tracker capacity, which is not what
is short."* The packets being lost here are lost to the socket receive buffer, not to CPU.

## [1.15.0] — 2026-08-28

### Added — a QR code for the two-factor setup, drawn on this machine

- **Settings → Two-factor authentication now shows a QR code.** 1.14.0 deliberately shipped without
  one, on the grounds that drawing a QR means sending the secret somewhere outside the server. That
  reasoning was right about the risk and wrong about the options: the panel now carries its own
  encoder (`includes/qr.php`), so nothing is sent anywhere. No QR service, no CDN library, no network
  call — the SVG is built in PHP from the URI the server has just generated. The typed key and the
  `otpauth://` URI stay on screen underneath, for anyone who cannot scan or would rather not.
- Reed-Solomon over GF(256), byte mode, error correction level M, versions 1 to 10, and the
  standard's own mask-penalty rules. Loaded only on the one request that needs it.
- If the drawing fails for any reason the setup still works and says so: the key underneath is the
  real payload, and a shortcut that breaks must not take the whole page with it.

### Fixed — two encoder bugs that only a decoder could see

Both were found by `tests/qr_test.php`, and neither could have been found by the encoder checking its
own work — that is the point of testing against something that did not write the code.

- **The format-information bits were written in reverse order.** Position 0 took bit 0 instead of bit
  14. This file's own reader agreed with its own writer, so a round-trip passed perfectly; no scanner
  on earth would have read the symbol. Caught by comparing against an independent encoder.
- **The dark module was being blanked.** The second copy of the format information is eight modules
  wide but only *seven* tall; reserving eight in both directions overwrote the dark module that the
  standard requires to be set. The reversed writer above then happened to write something back over
  it, so the two bugs hid each other and the symbol still scanned. Fixing one exposed the other.

### Testing

- `tests/qr_test.php` (18 checks) verifies the encoder three independent ways: module for module
  against `python-qrcode`; read back as exactly the codewords that went in, using the mask the symbol
  itself declares; and — the one that matters — through a real decoder (`zxing-cpp`), required to
  return the exact input string. The reference libraries are development-only and are never shipped;
  if they are absent those checks skip visibly rather than passing quietly.
- One test case is pinned deliberately: an `otpauth://` URI whose lowest-penalty mask is 3. OpenCV's
  detector cannot find the resulting symbol — for this encoder and for `python-qrcode` alike, since
  both produce the identical matrix — while `zxing-cpp` reads it without trouble. The case is kept so
  that nobody later "fixes" the mask selection to please a weak detector.
- `tests/twofa_login_test.py` (31 checks) now rasterises the QR the server actually returns over HTTP
  and decodes it, asserting it carries exactly the setup URI. A QR encoding the wrong secret would
  set an app up against a key the server does not have, and every code it produced would be refused —
  a lockout discovered at the worst possible moment.
- Verified on production: identical matrix on PHP 8.5.8 and 8.4.15, and the QR rendered in the live
  panel decoded back to its own setup URI. The pending secret used for that check was cancelled.

### Fixed — the Index page appeared to jam the whole panel

- **Clicking away from Index during a fetch did nothing until the fetch finished.** It looked like
  PHP or MariaDB struggling under the catalogue query, and it was neither: PHP's file session handler
  holds an **exclusive lock for the whole request**, so a three-to-nine-second catalogue search held
  the session while every other page of the panel queued behind it. The listing endpoints now release
  the session with `session_write_close()` once they are past the authentication check — they only
  read, so nothing after that point needs it open. Measured on production afterwards: a 5.5-second
  catalogue search running while another admin page loaded **in 106 ms**.

### Changed — Restore defaults also undoes edits made in the form

- The button had exactly one meaning — *put the machine back* — and said "nothing to undo" to somebody
  who had filled three fields in and thought better of it. That is answering a question nobody asked.
  It now covers both undos, because from the reader's side they are one idea: with a change the panel
  actually applied, it restores the machine; with only unsaved edits, it discards them locally and
  says so plainly. The label and tooltip change to name whichever it would do, so the button is never
  a surprise, and it greys out only when there is genuinely nothing to put back.

### Security

- `config/admin_2fa.json` is now in `.gitignore`. It holds the TOTP secret and the recovery-code
  hashes; committing it would put a working second factor into the repository, where every clone and
  every fork would carry it. (Checked: it had never been committed, and `config/` is excluded from
  deploys.)

## [1.14.0] — 2026-08-28 (schema v17 + v18)

### Added — two-factor authentication for the admin panel (schema v18)

- **Settings → Two-factor authentication.** A six-digit TOTP code (RFC 6238) on top of the password,
  from any authenticator app. **Off by default.** Verified against RFC 6238's own published test
  vectors, because a one-byte slip in the dynamic truncation produces codes that are wrong in a way
  nothing notices until somebody cannot sign in.
- **The secret does not live in `settings`.** It is a credential — anyone holding it can mint valid
  codes for ever — and that table is dumped by every backup and read by half the panel. It lives in
  `config/admin_2fa.json` beside the password hash, in a directory the web server is denied (verified
  on production: `403` for both) and that no deploy overwrites. One settings row mirrors the on/off
  state so the settings search can find the section; the file decides.
- **Setup is two-step.** The secret is *pending* until a code generated from it verifies, so a
  mistyped key cannot lock an administrator out of their own panel — which is what happens when a
  secret is stored the moment it is generated and the mistake is discovered at the next sign-in.
- **Ten single-use recovery codes**, shown once, stored as SHA-256, with **regeneration** behind the
  password and a code. Every previous code stops working the moment new ones are issued, and the
  panel says so unprompted when fewer than three are left.
- **A code cannot be used twice.** It is valid for its whole 30-second step plus one either side —
  long enough for one read over a shoulder or out of a log — so the last accepted step is recorded and
  never accepted again, including the code that confirmed the setup.
- **Turning it off needs the password AND a current code.** The whole point is the case where somebody
  else has the password; if that alone could disable it, it would protect nothing against precisely
  the person it exists for. Same for regenerating codes.
- **The password step now grants nothing on its own.** No session exists until the second factor is
  done, so a stolen password reaches an "enter your code" box. The failure counter is deliberately
  *not* cleared after a correct password either: clearing it there would let someone holding the
  password reset the lockout at will and then take unlimited guesses at six digits.
- **No QR image, and the panel says why**: drawing one means sending the secret to something outside
  this machine, and the secret is as good as the password. The key is shown in groups with the full
  `otpauth://` URI beside it.
- **`tools/twofa_cli.php`** is the escape hatch. An administrator who has lost the app and spent every
  recovery code still has SSH, and reaching that shell already proves more than six digits could. It
  reads and disables only — turning it on from a terminal would print a secret into a shell history.

### Added — extra opentracker instances (E6, schema v17)

- **Settings → OpenTracker instances** and a roster card on the Traffic page. For a machine whose UDP
  workers are genuinely saturated. **Off by default**, and the performance card above it says outright
  whether it would help: on the reference deployment one instance uses a sixth of the machine with its
  busiest worker at a quarter of a core, so the honest answer there is no.
- **The installer's `opentracker.service` is never touched.** Extras are added beside it. Adopting it
  would mean stopping the one unit whose failure takes the tracker down and migrating the stats URL,
  the announce URL, the firewall port and the performance drop-in at once, on a working box.
- **One mode, one binary.** Every instance executes the same shared symlink and reads the same
  accesslist, so they cannot disagree about which build they are running and there is still exactly
  one `tracker_mode`. The panel keeps **no roster**: systemd and the filesystem hold the truth, and
  three settings rows are the entire database cost.
- **The roster comes from the filesystem**, not `systemctl list-units` — without `--all` that lists
  loaded units only, so a stopped, unloaded instance would vanish from the roster, never be switched
  with the others, and come back weeks later serving whatever mode it was left in.
- **The reload fan-out never runs in a web request.** `whitelistJanitor()` is called on every API
  request by design; a loop of `systemctl reload` there would let one visitor stall five php-fpm
  children. It lives in the janitor, refuses to run under any SAPI but the CLI, and is driven by the
  accesslist file's mtime — which also makes it work in **blacklist mode**, where `whitelistJanitor()`
  returns immediately and an extra would otherwise keep serving a hash banned an hour ago.
- **`tracker-mode.sh` gained `--all` and `--instance`** without touching its output contract: detail
  above, a bare mode word last, which is all `includes/schedule.php` reads — so the schedule needed no
  change at all. An instance that cannot be switched is **stopped**, because serving the blacklist
  build while the panel says "whitelist only" is not a degraded state but a wrong one. The aggregate
  word follows the **primary** even when a secondary failed: that one row gates whitelist regeneration
  for everyone.
- **Creating an instance is refused while the automatic inbound limiter is on.** Its counters only see
  the primary's port, so a second instance hides most of the traffic from it while leaving the load —
  and it answers by throttling the primary, repeatedly, while the chart shows a rate saying it should
  not be.

### Fixed

- **An instance that came up "active" while sharing the primary's port.** Found by rehearsing on the
  live server, which is the only place it could have been found: the primary's config there names no
  listen port at all — opentracker has its own default — so a copy of it had none either, the new
  instance fell back to the same default, and it bound the port the primary was already on. systemd
  called it active and the panel called it created. `create` now appends the listen lines when the
  source has none, reads back what it wrote, checks the instance is actually **listening** on the port
  it was given and removes itself again if it is not, and the panel reports whether the primary's port
  was **read or assumed**.
- **The mode switch's fast path asked the symlink, not the process.** With several instances the gap
  between flipping the link and the last restart is seconds, and an interrupted switch could leave the
  link saying white while every process still ran black from its open inode — after which the old code
  printed success, restarted nothing, and the panel recorded a mode the tracker was not serving,
  permanently. It reads `/proc/<pid>/exe` now.
- **`systemctl reload` returning 0 meant nothing**, and this predates the cluster work: the exit code
  says the signal was delivered, and on a build without the SIGHUP patch that signal can kill the
  process a moment later — so a reload that emptied the swarm reported success and cleared the
  pending-reload bookkeeping. One `is-active` check closes it.
- **The kernel-buffer card was unreadable.** Its rows sat inside `.wl-status-grid`, which is
  `repeat(auto-fill, minmax(250px, 1fr))`, so the whole card body landed in one 250-pixel column and
  every sentence wrapped a word per line.
- **The way back existed only while a change was armed.** Once it was confirmed the banner went and
  "Put it back" went with it. There is now a **Restore defaults** button in the card's own action bar,
  careful about the word: it restores what *this machine* had before the panel first touched these
  settings, not the distribution's defaults — and with nothing captured it is disabled and says why
  rather than doing nothing quietly.
- **A disabled action in a status card looked enabled.** Bootstrap removes `pointer-events` from a
  disabled button, which takes the not-allowed cursor and the tooltip with it — so the reason the
  button cannot be used became unreachable exactly when it was needed.

## [1.13.0] — 2026-08-27 (schema v16)

### Added — the kernel's network buffers, from the panel

- **Admin → Traffic → Kernel network buffers.** Eight keys, off by default. The page could already
  prove that announces were being discarded because the UDP socket's queue was full, and could only
  print "run this sysctl yourself" — correct, unexplained, and handing an operator a machine-wide
  change with no measurement behind it and no way back.
  - **Units are chosen in the panel, never typed as the kernel counts them.** Bytes with a
    `B/KiB/MiB` selector, packets *per CPU* with the multiplication shown, and `udp_mem` typed in MiB
    while pages, bytes and share of RAM are displayed at once. The value that started this — a
    `net.ipv4.udp_mem = 3145728 4194304 6291456` from a tuning guide — is **12/16/24 GiB on a machine
    with 11.4 GiB of memory**, and it is refused with that arithmetic quoted back.
  - **Armed, not applied.** The change takes effect, and puts itself back unless a human confirms.
    Nothing reaches `/etc` until they do, so until then a reboot is also a complete undo. The undo is
    scheduled through systemd *before* the change is made: it needs neither the panel, nor PHP, nor
    MariaDB, nor an administrator who can still log in. The janitor is a second layer behind it, and
    the countdown shows the worst case rather than the nominal window.
  - **The panel writes nothing.** php-fpm runs with `ProtectKernelTunables=yes`, so `/proc/sys` is
    read-only inside its mount namespace — for root as well, because it is a namespace and not a
    permission bit. The endpoint records the request; the janitor performs it. Which also means the
    process that will undo a change is the one that made it.
  - **Nothing is suggested that a counter does not support.** The queue is not offered while
    softnet's dropped column is flat — on the reference machine it has never moved, and lengthening
    that queue is the change most likely to make an interactive SSH session stutter, which is exactly
    what the operator had been bitten by. `udp_mem` is not offered while the pool sits at a few
    hundred pages of 277,407. The send side states plainly that no local measurement points at it.
  - **The comparison nobody makes by hand:** the kernel stores `sk_rcvbuf = 2 × min(request,
    rmem_max)`, so a socket at exactly `rmem_default` never called `setsockopt(SO_RCVBUF)`.
    opentracker does not — measured, `rb = 212992 = rmem_default`, where twice the ceiling would be
    425984 — so **raising the ceiling alone changes nothing on this machine**, and the card says so
    instead of letting an operator conclude the cap was the problem.
  - Confirm is password-gated because it destroys the escape hatch; revert is not, because demanding
    a password over a session that is already stuttering is the failure being guarded against. The
    baseline is captured once, before the first write, and re-validated key by key on the way back
    rather than replayed as a root-owned file. `udp_mem`'s bounds are relative to what the kernel
    itself chose, because this machine's factory setting is already 9% of RAM and a flat rule would
    have refused it.

### Fixed

- **The automatic undo was killing itself before it undid anything.** Found by running it on the
  live server rather than by reading it: the scheduled unit fired on time, the journal recorded it
  starting and deactivating successfully, and the value was still changed. `action_revert` begins by
  cancelling every pending revert unit — right when a human presses the button, fatal when systemd is
  the caller, because the transient unit's own name matches the pattern and stopping it kills the
  process mid-flight. Proven with a probe unit that stops itself and then tries to write a file: the
  file is never written. The scheduled command now carries `--watchdog`, and that path does not
  cancel, because it is one of the units it would cancel.
- **`jesc()` did not escape newlines**, so any multi-line content in a helper's JSON reply was
  invalid JSON — the file preview among them. `tracker-instance.sh` already had the two-pass form.
- **Reports listed its navigation twice.** The page links in its tab bar predate the shared header
  bar, which now carries every page; Reports was the only page showing both rows. Every other tab bar
  switches views and nothing else, and this one now matches.
- **The outbound budget appeared a second after the page did**, because the block stayed hidden until
  the firewall answered — so a whole section grew under the reader's cursor, and a budget that had
  merely not loaded looked like a feature that comes and goes. It now holds its place from the first
  paint, disabled, saying what it waits for. Its input was also hardcoded to `value="50000"`: a
  real-looking number that was never read from anything, and the same 50k once reported as stuck.

## [1.12.0] — 2026-08-27 (schema v15)

Federation you can undo, hold back and stop paying for twice; the tracker's own threads measured
rather than guessed; and the knobs that come before extra instances.

### Added — federation P1 (E5)

- **The origin time travels with the metadata.** Every import used to stamp `meta_fetched_at = NOW()`,
  which is when a row reached *us*. After three hops the panel called month-old metadata fresh, and
  no node could tell a genuinely newer resolve from the same description coming round again.
  `index_hashes.meta_origin_at` carries when it was first resolved *anywhere*; the export sends it as
  `mo` beside the cursor's `mf`, and the importer compares origins before writing anything.
  - A peer with a wrong clock cannot mint permanently-newest rows: an origin in the future is clamped
    to now.
  - A node that sends no `mo` falls back to `mf`, which is what it has always meant on a two-node
    exchange — so an older partner keeps working without knowing anything changed.
- **Split horizon on the export.** Importing re-stamps the arrival time, which put every borrowed row
  into our own export window: two nodes spent every cycle shipping each other's catalogue back —
  megabytes of transfer for zero writes, indefinitely. The export now leaves out whatever the asking
  peer contributed. It knows who is asking because the bearer key belongs to a peer row. Verified on
  production: with the node as its own peer, five staged rows go out to a stranger and exactly the
  two it did not contribute go out to the peer, in both the buffered and the streaming exporter.
- **Quarantine — `fed_import_mode = review`.** A peer's answer lands in `fed_review` and reaches the
  catalogue only when an admin accepts it. Deliberately a holding table rather than a new
  `meta_status`: widening that ENUM means rebuilding a FULLTEXT table of millions of rows, and every
  query that lists the catalogue would have had to learn the new state or start leaking unreviewed
  names. Accepting runs the same merge the fill path does, so review mode changes *when* a row is
  trusted and never *how* it is stored.
  - Rejecting leaves a mark rather than deleting the row. A peer offers its whole catalogue on every
    pull, and a decision that does not persist is not a decision. "Allow again" withdraws it.
  - The queue is bulk-operable per peer — a first sync can park tens of thousands of packages, and
    accepting a whole peer's backlog publishes descriptions nobody has read, so that one asks for the
    admin password while per-row decisions do not.
  - Names are rendered as text, never as markup, in the queue exactly as in the catalogue. Review
    mode is about *what you publish*, not about script injection.
- **Undo import (F7).** One button per peer returns everything it contributed to unresolved. The
  hashes and their local history stay — `first_seen`, `seen_count`, the seeder peaks were observed by
  this tracker's own swarm and were never the peer's to take; only the borrowed description goes.
  Sliced at 2000 rows per request, because a peer that has fed a node for a month can own a million
  rows and one statement over a million rows on a MariaDB shared with mail and a forum takes
  something else down as a side effect. `worker/federation.py --purge NAME` does the same work
  without a browser tab having to stay open, and `--dry-run` counts first.

### Added — the tracker's own load, measured (E6)

- **Per-thread load on the OpenTracker card**, and a verdict on the question the plan gates extra
  instances on. The helper reports raw counters — `utime+stime` per tid, plus machine-wide busy and
  idle ticks from the same clock — and never a percentage, because a percentage needs two samples and
  taking the second would mean the helper sleeping inside a web request. The card subtracts
  consecutive polls instead.
  - Threads rather than the process, because they are not the same question: four UDP workers at 25%
    each and one worker pinned at 100% are the same 100% in `top` and mean opposite things.
  - The verdict says the one case that justifies a second instance — busiest worker at the ceiling
    with one thread per core — and otherwise says so and points at what is actually limiting the
    tracker.
  - **Measured on production while writing this: 89–104% of 600%, busiest UDP worker 23%, at the
    60 000 pps inbound budget.** One sixth of the machine. So E6's build half — the systemd template,
    `tracker-mode.sh --all`, per-instance SIGHUP, per-node stats, multi-port announce URLs — is
    deliberately **not built**, and the panel now says why on its own rather than leaving it to a
    feeling about `top`.

### Fixed

- **A measurement that lied.** `socket_drops` pulled the per-socket counter out of `ss`'s skmem blob
  with a `sed` backreference that had been mangled into a literal `0x01` byte: the pattern matched,
  the substitution produced rubbish, the unsigned-integer guard rejected it, and the helper returned
  a confident **0** every time. It is `awk` now, and a test drives the real helper against a stub
  `ss`. With it fixed, production immediately showed what had been hidden: **~51 packets a second**
  discarded because the socket queue was full, and a lifetime counter past 630 000. A broken
  measurement that reads "healthy" is worse than one that fails out loud.
- **The card could sit on "measuring" for ever.** The baseline advanced on every poll, so a tab whose
  visibility flaps — a window manager, a screen recorder, a user moving between tabs — reset it
  before the window ever reached the three seconds a reading needs. The baseline now moves only when
  it was actually used, or when it has aged past ten minutes. A poll that lands too soon keeps
  showing the last good reading rather than blanking a working display.
- **A reading that was arithmetically impossible.** Thread time is counted in 10 ms ticks, so over a
  one-second window one tick of rounding is a large percentage — two forced refreshes in quick
  succession produced threads apparently running at 648%. Windows under three seconds are discarded,
  and a thread that still appears to exceed one core is dropped rather than drawn.
- **Each poll runs `pgrep`, several `systemctl` reads and `ss` on the server.** Forced reloads are now
  spaced, so a flapping tab cannot ask for that thirty times a minute.

### Changed

- **A migration that would rebuild `index_hashes` no longer runs inside a page view.** That rebuild
  holds a shared lock for minutes — InnoDB does not permit concurrent DML while rebuilding a FULLTEXT
  table — and there are five php-fpm children, so a browser request doing it takes the site down for
  the duration. The janitor is an ordinary CLI job and performs it a minute later; the schema version
  is not recorded until it has, so a deferred migration cannot be mistaken for a finished one. The
  ALTER offers `ALGORITHM=INSTANT`, then `INPLACE`, then the plain form — on production INSTANT was
  refused and INPLACE succeeded, which is exactly the case the fallback exists for.

### Also in this release — E4: OpenTracker's performance knobs, from the panel (schema v14)

### Added
- **Settings → OpenTracker performance** and a card on the Traffic page: UDP worker threads, `Nice`,
  `CPUWeight`, `CPUAffinity` and `LimitNOFILE`. These are the knobs that already exist on any systemd
  box, and they are worth nearly all of the available gain at nearly none of the risk — which is
  precisely why they come before extra tracker instances rather than after.
  - Everything the panel writes goes into **one file it owns**, `90-tracker-panel.conf`.
    `override.conf` and `limits.conf` were put there by the installer or by hand and are never
    touched; **Reset** deletes the panel's one file and nothing else. The suite asserts that.
  - The worker count is different: it lives in opentracker's own config, so it is written to **both**
    mode files — otherwise the thread count would change when the tracker switched white/black — and
    the card says plainly that opentracker only reads it at start-up.
  - A `CPUAffinity` systemd cannot parse makes the unit refuse to *start*. It is therefore rejected
    when it is typed, not at the next restart.
  - **Saving a setting changes nothing.** These values say what the admin wants; a password-gated
    Apply puts them in force, with a preview of the exact file first. A fresh install writes no
    drop-in at all.
  - The card shows what is **in force**, read from `systemctl show` and the config files — not the
    saved settings read back. Where the two differ it says so.
- **The receive-buffer diagnosis**, which is not a knob and is the thing that actually explains lost
  announces. opentracker asks the kernel for a socket buffer; the kernel clamps it to
  `net.core.rmem_max`, and when that fills the packet is discarded *after* the machine has paid to
  receive it — the worst place to lose it, unlike a firewall drop, which costs nothing. Measured on
  this server: a 208 KB cap and 555 378 packets already discarded there. The panel reports the number
  and gives the command; it does not write sysctls, because those are system-wide and belong to
  whoever owns the machine.

### Also in this release — E3c: the federation importer stops doing four queries per row

### Changed
- **`worker/federation.py` reads NDJSON and merges in micro-batches.** The old importer issued two
  to four queries *per row* — on a full 2.17-million-row exchange that is about 6.5 million round
  trips, which is hours. Rows now accumulate into a batch (500 rows or 32 MB, whichever fills first)
  and each batch is **one transaction with four bulk statements**: what we already know, what is off
  limits, one `INSERT … ON DUPLICATE KEY UPDATE` for the lot, and the file lists.
  - The **cursor moves inside that transaction**, so an interrupted run — a kill, a reboot, a
    dropped connection — costs at most one batch and leaves nothing to repair.
  - The upsert repeats the "never overwrite a locally resolved row" policy in its `ON DUPLICATE`
    guard, because the local worker runs at the same time and its result must win. The assignments
    are ordered so `meta_status` is written **last**: MariaDB evaluates them left to right, and any
    other order would have every guard read the value the same statement had just set.
  - A **hard `RLIMIT_AS`** (`fed_worker_mem_mb`, default 256 MB) sits under all of it. Every other
    guard is a promise about arithmetic; this one is what makes the process die instead of the
    machine, and the timer restarts it from the last committed batch.
  - `--max-seconds` bounds a pass so a one-minute timer cannot stack copies of itself on a slow peer.
  - A peer whose export predates NDJSON is detected from its `Content-Type` and served by the old
    buffered path — through the same merge, so only our memory profile differs.
  - Measured against the live catalogue (the server importing from itself): **36 741 rows, 19 pages,
    35 s, 50 MB peak RSS**, and a second run took 0 s because the cursor had nothing left to fetch.

### Fixed
- `valid_row()` dropped the `mf` field, so a committed batch could not say what it had covered and
  the cursor never advanced — every run would have re-fetched the same page for ever. Caught by
  running it against the real catalogue rather than a fixture.

### Also in this release — the outbound budget did not survive a reboot

### Fixed
- **A budget set from the panel reverted at the next restart.** The egress action guarded its file
  write with `[ -w "$EGRESS_FILE" ]`, and inside php-fpm's mount namespace `/etc` is read-only — so
  the test simply failed, the write was skipped in silence, and the live rule and the file drifted
  apart. The only way to find out was to reboot, which is exactly how it was found. The write now
  goes through the same three-way answer as the inbound ruleset (saved / deferred / failed), the
  janitor reconciles the budget file on the same visit as the inbound one, and the status reports
  `file_pps` and `file_matches` so the card can say **"live, but the saved copy still says N pps"**
  instead of leaving it to be discovered by accident.

### Also in this release — the panel measures where THIS machine starts to struggle (schema v13)

### Added
- **A load study.** A packets-per-second number means nothing on its own — 40 000 is trivial on one
  box and fatal on another. The janitor now records the load average per core beside every traffic
  sample, and the card turns the pair into the only question worth asking: *at what traffic level did
  this particular machine stop coping?* Samples are bucketed by the rate that got through, each
  bucket keeps the **median** load (so one backup run cannot move it), and the answer is the lowest
  bucket at or above 0.85 per core. It appears as a **`busy` mark on the slider** and as a sentence
  when the chosen limit sits above it: *a limit the box will never reach is not protection.*
  What it refuses to do matters more: with fewer than 120 readings, with traffic that barely varied,
  or with a single unlucky spike, it says **"I do not know"** and explains why in words — a threshold
  nobody measured would send an admin to throttle a tracker that was coping fine. It also never
  claims causation: this box runs mail, a forum and a file host too, and the wording says so.
- **The slider's danger zones now colour both ends.** Red below the rate that is genuinely flowing
  (a budget under it cuts real traffic), amber for the 30 % of headroom above, and — once the load
  study has something to say — amber and then red past the point where the machine was already
  struggling. Both sliders share the ceiling, because they share the machine.

### Fixed
- **The measurement labels were drawn through by their own tick marks.** Centring each label on its
  mark put the text directly under the coloured bar; once the bar was lengthened to reach a stacked
  row it ran straight through the words. Labels now sit beside their tick and flip to the other side
  near the right-hand end of the track.
- **A migration referenced a constant from a file it does not load.** The v13 ALTER used
  `NET_SAMPLE_TABLE`, which lives in `includes/netlimit.php`; any caller loading `schema.php` on its
  own threw, and since `ensureSchema()` is what writes `schema_version`, the whole migration stopped
  happening silently. Caught by a test that had been passing only because another suite migrated the
  database first — that check no longer depends on the order suites run in.

### Also in this release — "search inside file lists" could take the whole site off the air

### Fixed
- **One search held every PHP worker for 24 minutes.** Searching inside file lists built the clause
  `MATCH(name) AGAINST(?) OR info_hash IN (SELECT info_hash FROM index_files WHERE MATCH(path) …)`.
  MariaDB cannot serve an OR of a fulltext match and a subquery from indexes, so it scans
  `index_hashes` end to end — 2.5 million rows here — evaluating the subquery as it goes. Each such
  request occupies a php-fpm child, the pool has five, and every retry started another: the tracker
  stopped answering entirely while the database sat at 100 % CPU. The file half is now resolved
  first, on its own FULLTEXT index and with a hard cap, and handed to the main query as a literal
  key list — two cheap indexed reads instead of one impossible plan. Both the public catalogue
  search and the admin index search carried the same clause; both are fixed.
- **A safety net for the next bad plan.** Web requests now set a session `max_statement_time`, so a
  pathological query costs one visitor an error instead of the site. CLI stays untouched — the
  janitor, the metadata worker and `mariadb-dump` all run legitimately long statements.

### Also in this release — federation exported nothing at all on MariaDB 11.8

### Fixed
- **A peer starting from a cold cursor received an empty page, every time, for ever.** The export
  cursor compared `meta_fetched_at` against `FROM_UNIXTIME(?)`, and MariaDB **11.8 returns NULL** for
  `FROM_UNIXTIME(0)` — unix time 0 is outside the TIMESTAMP range. NULL poisons the comparison, the
  whole clause becomes unknown, and not a single row matches. Found by pointing the export at the
  real catalogue: 36 862 exportable rows, 0 exported. MariaDB **11.4** — the local test database —
  returns a value instead, which is exactly why every test passed while production federated
  nothing. The queries now clamp the cursor away from the epoch, and the suite carries a check that
  runs for real on a database exhibiting the NULL and skips with a reason on one that does not.

### Also in this release — the panel stops giving advice it cannot back up

### Added
- **The outbound budget is now adjustable from the panel**, not just displayed. A tracker answers
  what it accepts, so the reply budget (table `inet ottrack`) is the other half of the same decision
  — and the half that decides whether the rest of the machine stays reachable while a swarm shouts.
  The helper could always set it; only the UI was missing. Same password gate as the inbound limit,
  and one rule is swapped by handle so the counters keep running.
- **Risk zones painted on both sliders.** The track is red below the rate that is actually happening
  and amber for the 30 % of headroom above it; the thumb takes the colour of the zone it is in. The
  reference is always *measured* — with nothing measured the zones simply do not appear, because a
  threshold nobody measured would be worse than none.
- **The backups table sorts** (when / profile / size / integrity), client-side: the list is a handful
  of files the helper already handed over, so there is nothing to wait for.

### Fixed
- **The backups table clipped its own action buttons.** It was the only table on a `.wl-page` without
  a `<colgroup>`, and with `table-layout: fixed` the browser then splits the width equally between
  six columns while `overflow: hidden` cuts off whatever does not fit — which is why the header read
  "ACTIO…" and the buttons ran off the edge.
- **The recommendation led with a number that meant "no limit".** In a flood it said "a limit at
  180,000 pps would essentially never trigger" and only *then* warned that arrivals are not demand —
  by which point the number had already been read. The caveat now leads, the P95 figure is demoted
  to a parenthesis, and the sentence quotes what is actually getting through as the number to choose
  from. The per-value warning was worse: it judged the slider against the *arrivals* floor, so at
  48 000 pps it claimed you would be "dropping traffic the tracker normally serves" while only
  39 800 pps was getting through. It now judges against the live rate.

### Also in this release — E3a/E3b: the federation export stops building pages in memory (schema v12)

### Added
- **Streaming NDJSON export** (`"format": "ndjson"` on `v1/federation/export`). The buffered reply
  assembles the whole page — rows *and* every file record of every row — in a PHP array before a
  byte leaves. `fed_export_max_batch` counts **torrents**, not what a torrent contains, so it never
  bounded the work: measured here, 3 000 torrents of 120 files (360 000 file records) **exhausts a
  128 MB limit and dies**. The same page streamed peaks at **32 MB** and takes 1.4 s. Header line,
  one row per line, trailer line carrying the cursor — so a truncated transfer is detectable.
  Compressed incrementally with `deflate_add()` rather than `gzencode()` on a finished string, which
  keeps memory flat *and* lets the byte budget count what actually goes on the wire.
- **Two budgets that actually bound a page**: `fed_export_max_bytes` (8 MB) and
  `fed_export_max_files` (200 000). A page ends on whichever of rows / bytes / file-records runs out
  first and hands back the cursor, so a heavy catalogue produces smaller pages by itself instead of
  the peer having to guess. A budget smaller than a single row still sends that row, or a catalogue
  with one huge entry could never get past it.

### Fixed
- **A deferred ruleset save could never complete on an install with the monitor off.** The janitor
  tick returns early when the monitor and the automatic mode are both off — that early exit is what
  stops a disabled feature forking a process every minute — and the deferred save sat *after* it. A
  limit applied on such an install stayed live with a stale file for ever: precisely the failure the
  deferred save exists to close, reappearing wherever nobody switched the monitor on. The tick now
  checks the pending flag first, and forks nothing until there is genuinely something to save.

### Also in this release — E3a: the server-to-server API gets a ceiling (schema v12)

### Added
- **Per-key rate limits on `v1/*`** — requests per minute (`api_rate_limit_per_min`, default 60) and
  bytes per day (`api_rate_limit_bytes_day`, default 5 GB), under Settings → Server-to-server API.
  Until now the ban machinery only ever reacted to *bad* authentication, so a key that was perfectly
  valid — a federation peer pulling too eagerly, or a key that had leaked — could not be slowed down
  at all short of disabling it by hand. Counted **per key** rather than per IP, because one partner
  pulls from one address and an IP bucket would be shared with anyone behind the same NAT. Going
  over answers **429 with `Retry-After`**, never a ban: pulling too fast is a misconfiguration
  between partners, not an attack. Addresses on the never-ban list are exempt from both budgets, so
  the operator's own integrations (the forum on the same host) are untouched. **0 switches a budget
  off.** The federation export charges what it *sends*, since the request that asks for a page is a
  few bytes and the reply is what actually costs bandwidth.

### Changed
- **A federation peer's base URL must now be `https://`.** The bearer we hold for a partner travels
  in a header on every single pull; over http it is readable by anything on the path, and a leaked
  federation key is a licence to read the whole resolved index. Existing `http://` peers keep
  working until the row is next saved, and the message says exactly why.

## [1.11.2] — 2026-08-27

### Fixed
- **A healthy firewall reported itself as unavailable.** The new directory-writability probe used
  `: >"$probe" 2>/dev/null`, and bash applies redirections left to right: the redirection fails
  first, so "Read-only file system" went to the *real* stderr before `2>/dev/null` existed. The
  probe runs inside a command substitution while the status JSON is being assembled, so that line
  was spliced into the middle of the reply — right after `"file_pps":40000` — and nothing could
  parse it. The probe is silenced properly now, and each reply is written in a single `printf`, so a
  stray line can only ever land on a line of its own, which the panel already skips.
- **A failure the helper had recovered from sat on the card in red for ever.** Nothing ever cleared
  `last_error`, so a fault fixed minutes ago still read as live. The status now stamps `last_ok_at`
  on every clean answer and the card shows a failure only while it is newer than that — the record
  is kept, and an intermittent fault still surfaces, because its timestamp would be the newer one.
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
