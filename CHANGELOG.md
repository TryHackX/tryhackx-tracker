# Changelog

All notable changes to this project are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/), and the project aims to follow
[Semantic Versioning](https://semver.org/).

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
