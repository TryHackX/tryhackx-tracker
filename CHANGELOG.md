# Changelog

All notable changes to this project are documented here. The format is loosely based on
[Keep a Changelog](https://keepachangelog.com/), and the project aims to follow
[Semantic Versioning](https://semver.org/).

## [1.73.1] — 2026-10-05

Corrections the README's new screenshots showed. Numbers are written in the language of the page — the server and the
script alike, so a refresh never changes how a count looks — and a million or more in a torrent's swarm is written
short ("520.22M" / "520,22 mln"), the exact count in its title. A torrent's row on a phone is two rows, not three,
its facts kept apart; the Stats page says its countdown and its tooltips the same way before and after a refresh;
the audit log's settings diff stays in its cell; the whitelist page no longer calls registration anonymous. The
README's pictures are new: the public pages from the live site, the panel and the whitelist from a simulated one.

### Fixed — numbers in the language of the page, not of the browser

* **Production, 2026-10-05**: the home page's strip and the Stats page's counters came from the server grouped the
  English way on both languages ("2,251,367"), and app.js then refreshed them with `toLocaleString()` and no locale —
  the BROWSER's grouping. A Polish browser showed "2 251 367" on the English page; the Polish page kept the English
  commas until the refresh, and then took whatever the visitor's browser spoke.
* **One rule, the same characters on both sides**: `langNumber()` beside the language code (includes/lang.php) and
  `t.num()` in the scripts (assets/js/i18n.js, `Intl.NumberFormat` of the page's language) — Intl's rules, not
  `number_format()`'s: English groups with a comma from four digits on; Polish leaves four digits whole ("1234",
  "12 345") and groups with a no-break space. Another installed language asks ICU (PHP's intl, the data a browser
  carries) with the same four-digit rule where CLDR gives it, the English rule without intl. A refresh never changes
  how a number looks, and a number a script wrote keeps its value on the element, so the **live language switch**
  writes it again in the new language's grouping (the cards, the protocol counts, the error table, the heat map's
  tooltips — nothing redrawn, nothing asked again).
* **Every count the site writes** goes through them: the home strip and its placeholders, the Stats page (cards,
  protocol counts, HTTP errors, heat map, the timeline's legend and status line), the Info panel (seeders,
  leechers, completed, files, peak, times seen), the search's and the lists' totals and pagers, the files window,
  the hash check, the whitelist page's count, the account's picture limit, the API guide's numbers; and the panel —
  the Index (status, coverage, rows, toasts), Traffic (limits, rates, advice, the tuner, the sysctl and DB-memory
  cards), Users, Audit, IP lists, Languages, the page editor's counter, Settings' hints and the poll estimate, the
  federation queue — and the server's own sentences (the federation's review and purge, the IP lists, uploaded
  languages, limits applied, the sysctl / DB-memory / opentracker advice). Dates, sizes with decimals ("13.3 GiB")
  and units are not counts and stay as they were ("54 sec" too — the owner: "w Polsce też używa się s / sec").
* **Found on the way**: a Stats card counted up from 2 on a Polish browser — the count-up read its start value with
  only the commas taken out ("2 251 367" → 2); every non-digit goes now. Settings' poll estimate carried its numbers
  ready-formatted in the element (a live switch kept the old grouping) — raw numbers now, formatted by the script. The
  files window's "1,234+ files" is a sentence of its own (`js.app.files_n_more`), not a number with a "+" glued on.

### Fixed — the Stats page's countdown and heat-map tooltip changed their words on the first refresh

* The server wrote "Next update in 12s" and "Interval 05m: 98,765 renews", app.js's first pass "Next update in 12 s"
  and "Interval 05 min: …" — two dictionary sentences each. The page renders app.js's own now
  (`js.app.next_update_in`, `js.app.interval_tooltip`); `stats.next_update` and `stats.heat_tip` are gone.

### Changed — a torrent's row on a phone: two rows, not three

* **The owner's screenshots**: up to 600px a favourites row was the name, then size / swarm / hash chip, then the
  actions alone on a third line at the right — and below 360px the facts' desktop columns were wider than the row:
  the hash chip stood outside every row and the page was 347px wide on a 320px phone. His proposal, built: a grid —
  the name across the top (one line, its ellipsis and its title), the facts and the actions sharing the second row.
* **What he saw fixed**: the size and the swarm read as one run ("13.3 GiB 16 / 3"): 1.25em between facts and a
  drawn dot in the middle of the gap (Courier New's own "·" stands 1.5px right of its cell's middle — measured). The
  facts wrap inside their cell — a nine-digit swarm goes under the size, the chip under them, a fact starting a line
  takes no dot — and the actions keep their width at the row's right edge. Every user of the row — favourites,
  uploads, a list's window, on the account page and the profile — 320 / 360 / 390 / 414 / 600 px, English and
  Polish, Bootstrap Icons and Font Awesome; the favourites star at its 1.72.1 gap, no frame. The account's Uploads,
  whose visibility switch is words (153px, 226px in Polish), keep their actions on a line of their own. People's rows,
  which share the class, are untouched.

### Fixed — a row's swarm ran over the hash chip on a desktop

* **The owner**: "jak 100 mln i 100 mln robi problem … może konwertować wtedy na 100.82 mln". The swarm's column held
  13 characters and the busiest torrents' pair is 15 since the grouping ("23,456 / 12,345"). Now a count of a million
  or more is written compact in the page's language — `t.num(n, 'compact')`, Intl's compact notation, two decimals:
  "520.22M", "520,22 mln" (Polish compact thousands, "23,46 tys.", are no shorter than "23 456", so nothing below a
  million is shortened) — with the exact pair in the cell's title; the column fits a pair of six-digit counts in
  either language (17 characters, 10.25em); a pair that still does not fit ("520,22 mln / 172,22 mln") breaks after its
  slash, inside its column, each count whole — never onto the chip, at any width.

### Fixed — the audit log's settings diff ran out of its cell

* The panel's Audit page, "What happened": a settings save's details kept every value on one line — the tables'
  one-line rule (1.68.1) reached the diff's own cells too, so the `overflow-wrap` meant for them never applied — and a
  long value, an address such as `http://127.0.0.1:8091/scrape?n=300`, ran up to 200px out of the column. The diff's
  cells wrap now and a long value breaks inside its cell (its `code` says `anywhere` itself: Bootstrap's
  `word-wrap: break-word` on a code breaks the line but keeps the word's whole width in the table's sizing).

### Documentation — the README's whitelist and panel pictures from a simulated tracker

* Production runs in blacklist mode (its public Whitelist page is a sign-in prompt) and its panel cannot be captured
  at full size from here, so `whitelist.png` and every `admin-*.png` are taken from the local instance: whitelist
  mode with public registration open, a catalogue of Linux and BSD images, public-domain and Creative Commons films,
  open datasets, Creative Commons music and free software; reports of made-up works by the documentation's
  fictitious organisations (Contoso, Fabrikam, Northwind…) at example.org addresses and documentation-range IPs;
  members with made-up names (scratchpad/shots/readme_local_shots.js, readme_timeline.php). The five public pictures
  stay production's.

### Tests

* **New**: `scratchpad/shots/number_lang_check.js` — the home page and Stats in each language opened by a browser of
  the other (`--lang`, Accept-Language, navigator.language, its Intl default), the numbers as the server renders them
  and after the script's refresh: identical, the page's grouping, then a live switch; the two Stats sentences.
  `scratchpad/shots/pfrow_check.js` — the row's shape at every phone width and the swarm at 601 / 1280 / 1440 / 1920,
  both languages, both icon sets, with before / after pictures. `tests/lang_test.php` — `langNumber()` against Intl's
  output for English and Polish (and ICU for another language), `t.num()` and its compact form, no `toLocaleString()`
  left on a count, no one-argument `number_format()` left on a page or an answer, the Stats sentences.
* **Fixed checks**: `icons_align_check.js` asked a style-only mode (Pro light, Jelly) for a favourites star on the
  account page, which it never opens beyond the Sounds tab, and `ALIGN_ONLY=member:u` matched no profile (they are
  `u&name=…`); `emoji_picker_check.js` and `lists_check.js` expected the old grouping. `comments_check.js` and
  `descriptions_check.js` signed smokepeer in as a moderator only when `fav_check.js` had run before them and left it
  in the admin group — a set without it found a plain member (Reply / Report only) and failed; each now puts
  smokepeer in the admin group itself (made when absent, asked of `userGroups()`) and its memberships back row by
  row. `table_fit_check.js` lists every request the browser could not make or that came back an error, beside its
  "no script errors" line: a script lost on one load (once, "closeOnBackdrop is not defined" — app.js did not run on
  one member search page, with no error of its own) now names its cause; and its catalogue rows are stamped just after
  the newest row the catalogue holds — the panel's Index lists the most recently seen first, and seed_catalogue.js's
  rows (stamped "now" by the checks that search for "ubuntu") had pushed all ten off its first page whenever one of
  those checks had run before it.

### Fixed — the whitelist page called registration anonymous, and said nothing is indexed

* "Registration is **free and anonymous**" stood two lines above "Your IP address is stored with each registration"
  — the address is kept, as 1.73.0's Info and home page say. It reads "Registration is **free** and needs no account"
  / "Rejestracja jest **darmowa** i nie wymaga konta" now (`whitelist.anon`).
* "we do not host, index or download any content" was not true while the observed-hash index is on, and the metadata
  worker does fetch a torrent's name and file list. It says what happens: no files are hosted and no file content is
  ever downloaded — at most the torrent's name and file list, from the swarm itself (`whitelist.rule_serve`).

## [1.73.0] — 2026-10-05

The owner's last list before the final audit. Private messages get an **Archive** (what "hide" did, now a place
you can open and bring things back from) and a **Trash** that keeps a deleted conversation restorable for a set
number of days, with a confirmation before anything goes and an **Undo** after every move. The live language switch
swaps every word on every page and in every state — tooltips, labels, toasts, dialogs, the panel — proven by a
check over 74 states in both directions — and the words that had no Polish at all (the permissions' descriptions,
the traffic card's advice, the time-zone groups) have it now. Messages, friends and notifications tell time in the
reader's zone. No badge, button or control in any table is cut any more (4 036 cuts in 68
places, Polish's longer words first). Older bugs found while documenting are fixed: unsubscribing stopped password
resets, a deleted account left its 2FA secret behind, a submission waiting for a person's review was already
served by the tracker. The Info and Terms pages, the home page's features and every document are rewritten against
the code — nothing promised that the code does not do — and the footer states the project's licence: MIT.
Schema 91 (90: the messages' trash; 91: orphans cleared once, the transparency switch's default).

### Added — messages get an Archive and a Trash, a question before and an Undo after

* **The owner** (Polish, from his note): "the messages could be improved, e.g. a message bin. I clicked … pm-hide and
  the messages were gone, but in the members list I can press Write and see them … hiding needs somebody to write to
  us to bring them back, or some global setting … a confirmation is missing too, a message disappears at once and I
  cannot restore it — and after confirming, 3–5 seconds to restore it".
* **Three places per side of a conversation**, as tabs above the list on the account page's Messages tab —
  `Inbox · Archive (n) · Trash (n)`, an icon and the word each, the number of conversations beside Archive and Trash
  and the unread pill (the tab bar's own) beside the two places the badge counts; walked with the arrow keys; they
  wrap on a phone. Each place lists, previews, counts and searches ITS part of a conversation only.
* **The Archive** is `u_*_hidden` — every conversation somebody hid until today is in it, nothing moved. A row's (and
  the head's) archive box puts a conversation there; "Move to inbox" — on the Archive's rows and on the conversation's
  bar — brings it back. Whether a NEW message brings an archived conversation back to the Inbox is a setting,
  **pm_archive_returns** (Settings → People: messages, friends, directory; on by default = what hiding always did).
  Off, the conversation stays in the Archive, marked unread there, and it IS counted on the badge: the Archive tab's
  pill says where the number is, and opening it there reads it. Writing in an archived conversation always brings it
  back for the writer.
* **The Trash** (the owner's "kosz"): Delete moves what a member is shown of a conversation into their Trash — up to
  the last message the page had (`u_*_trash_upto`) and when (`u_*_trashed_at`). Restore brings all of it back, to the
  Inbox or to the Archive, wherever the conversation was; **Delete forever** and **Empty the Trash** delete for good at
  once — v70's watermark over it; the janitor does the same for whatever has been in a Trash **pm_trash_days** (30 as
  shipped, 1–365; **0 = no Trash**: Delete deletes at once, as it did from 1.64.0, and the Trash tab goes), in bounded
  batches — per side one statement a batch, oldest first by its own key — and says so in its journal
  (`[pm] trash purged=N`). Deleting is always FOR YOU: the other person's copy never moves, and no row leaves
  `user_messages`.
* **A new message in a trashed conversation**: the conversation is back in the Inbox showing only what came after the
  Trash's edge; the older part stays in the Trash until it is restored or runs out ("Earlier messages of this
  conversation are in your Trash (n) — Restore them"). A conversation somebody trashed or deleted whole comes back as a
  new one in the Inbox — never into the Archive, whatever pm_archive_returns says.
* **Opened from anywhere** — the members list's or a profile's Message, an address, a notification — a conversation
  in the Archive or the Trash says so, in a bar under its head, with its way back: "This conversation is in your
  Archive — Move to inbox"; "This conversation is in your Trash — it will be deleted for good on <date> — Restore ·
  Delete forever", showing what lies there (dashed), with the composer: a message written there starts the
  conversation again after the Trash.
* **A question before, an Undo after.** Delete asks first, in place — the site's own question (the shoutbox's, the
  bin's since 1.64.0), never `window.confirm` — "Delete it? It goes to the Trash."; Delete forever and Empty the Trash
  ask with "This cannot be undone" (and Delete does too where there is no Trash). After Archive, Move to inbox,
  Delete and Restore a **toast** at the foot of the window says what happened, with **Undo**, for five seconds —
  held while the pointer rests on it or the reader's focus is in it; the keyboard's focus lands on Undo when the row
  that was pressed has gone; Esc dismisses it; three at most, the newest lowest. The Undo is the explicit opposite
  operation, and one that comes too late — the Trash emptied, restored or grown since — changes nothing and says so.
  The toast is the site's (`siteToast()` in assets/js/app.js) for any page that wants one.
* **Icons and words**: the archive box, the inbox, the bin (filled for Delete forever) and the counter-clockwise arrow
  for Restore — through the icon map, so Font Awesome and its Pro twins draw them —, every button named for a screen
  reader and explained in the site's tooltip (no browser `title`). They follow the live language switch — the old
  head's tooltip did not (see Fixed).

### Changed

* **The unread counters count the Archive** (`pmUnreadCount`, `pmUnreadCountFriends`): above the member's floor —
  neither deleted nor in the Trash — in the Inbox or the Archive. The Trash is never counted. Until 1.72.x a hidden
  conversation could not hold anything unread (every message un-hid it); with pm_archive_returns off it can, and a
  message nobody is told about is the one they miss.
* **Every read path has the Trash's twin of the watermark** — the open conversation, its poll, the three lists and
  both previews, the deep search, both counters, the tabs' counts — and a read mark is written only for the part on
  the screen: opening a conversation never says "read" of a message in the reader's Trash.
* **The inbox's stamp** (what the list is watched by) covers every conversation of the reader's, archived too.
* **The endpoint** (`api/user_messages.php`): `view=inbox|archive|trash`; `with=…&part=trash`; the explicit, idempotent
  operations `archive`, `unarchive`, `trash {upto}`, `restore {to, from}`, `purge`, `empty_trash` — asking for what
  already is answers `changed: false`, never an error; every answer carries where the conversation is now, the three
  places' counts and the badge's number. `hide` and `delete`, the old names, mean `archive` and `trash`. None of it is
  written to the audit log — it is a member's own mailbox, and the panel reads that log; the panel has no view of
  anybody's Archive or Trash.
* **Schema 90**: `message_threads`.u_low_/u_high_trash_upto (0 = an empty trash), u_low_/u_high_trashed_at (NULL exactly
  when it is empty) + two keys for the janitor; the settings pm_archive_returns (1) and pm_trash_days (30). One ALTER
  of only what is missing; every existing row an empty Trash.

### Fixed

* **A conversation's head followed not the live language switch**: its tooltips were written once, in the language
  the page loaded in (people.js had no `langswap` listener). Every word the messages' new parts write keeps its
  dictionary key and is said again on a switch; the list is redrawn from its last answer. (The composer and the older
  lines of a conversation are part B's — see its notes.)
* The messages' own tests deleted their accounts with a bare `DELETE FROM users` and left each one's group
  membership behind (tests/people_test.php five a run, message_report_test.py, pulse_test.py, msgreport_check.js) —
  `userDeleteCascade()` now, checked.

### Tests

* `tests/pm_trash_test.php` (new, 105 checks): the upgrade walked on a v70-shaped table (one ALTER, an empty Trash,
  a second run nothing); the settings everywhere a setting has to be; then every read path — the thread, the poll,
  the three lists and their previews and unread numbers, the deep search, both counters, the tabs' counts, the place —
  for BOTH sides after each move: archive (and its switch both ways, the writer's return), trash (the edge, the moment,
  idempotent), a new message after it (two places at once), trashed from the Archive then a message (back in the
  Inbox), restore and the exact Undo of a Trash (a stale Undo is a no-op), purge, pm_trash_days 0, empty the Trash;
  the janitor with a clock passed in (30 days, 29 days, bounded batches, days 0); read marks only for what is shown;
  a reported message both sides deleted for good still readable to the panel; no panel file touching the columns; no
  audit line; account deletion; every key the script and the toast use in the public bundle.
* `tests/pm_delete_test.py` (34 checks): Delete is the Trash over HTTP — A's Trash lists and opens it, B's copy whole,
  a new message shows alone with the five waiting in the Trash, the deep search per place, a stale Undo, purge (the
  watermark), `delete` = `trash`, `empty_trash`, the CSRF token, an unknown operation refused.
* `tests/people_test.php`: the stamp now counts an archived conversation; its accounts leave nothing behind.
* `scratchpad/shots/pm_trash_check.js` (new, in the sweep after people_check; it reads as an account of its own): the
  tabs and counts, the rows' actions and tooltips, Archive with its toast and Undo within 5 s, the toast going by
  itself and staying under the pointer, by the keyboard (focus on Undo, Enter, the focus back on the row), toasts
  stacking (three at most, the same key replacing), Delete asked (No changes nothing) then Undo, Restore and its Undo, Delete
  forever and Empty the Trash asked "cannot be undone", the bar from the members list, from a profile's Message and
  from the Trash, writing in a trashed conversation and "Restore them", the live switch to Polish and back (tabs,
  rows, head, bar, an open toast), pm_archive_returns 0 and pm_trash_days 0, and a phone at 360 and 390 px (nothing
  wider than the screen, the actions drawn, the toast inside) — each step read back from the database; screenshots.
* `people_check.js` (Delete moves one side's Trash edge), `csrf_everywhere_check.js` (archive, unarchive, trash,
  restore, purge, empty_trash through their buttons, each with the page's token), `icons_align_check.js` (a
  conversation of smokeuser's with an older part in the Trash: the three tabs, the head's icons, the bar's button,
  the Trash's rows' actions), `icons_test.php`, `avatar_names_test.py` (the new keys are a message id, places, dates
  and counts — still no account id), `sql_safety_test.php` (the side prefix reviewed).

### Changed — the live language switch swaps everything, on every page

* **The owner**: "some elements in the messages section do not change language dynamically when the switcher is
  clicked — e.g. the tooltip on the archive button … check the WHOLE site" (after a reload it was fine). Measured
  first, by a new check that walks every page type and state — as a guest, a member and in the panel, both
  directions, open-then-swap and swap-then-open, desktop and a 390 px phone — and reads every word back (text,
  title, aria-label, placeholder, alt, label, data-tip and the other data-* words, option labels, document.title,
  the tooltip bubble while it is shown, toasts): 880 distinct words stayed in the old language in 282 desktop runs,
  in 58 areas of the dictionary; every state with words a script draws had some. Besides, the search page asked the
  server again on every switch (1–4 requests) and threw away an open description editor with the words typed in
  it, the settings page asked for its search catalogue again, the panel's hidden 404 fell back to a reload, and the
  emoji picker and the "Are you sure?" question closed.
* **What a script writes into the page keeps its key there** (assets/js/i18n.js): t() stays a plain string — for
  comparisons, switches, payloads — and where a script writes a word into the page it writes `t.key(…)`, a word that
  leaves its key on the element: through textContent, title, placeholder, setAttribute() (data-tip, aria-label…) or
  append() and its kin (data-i18n, data-i18n-a, data-i18n-p on the element, so a clone or a moved node keeps it), and
  in markup through the templates' escapers (t.html / t.ah). The switch notes which keyed words still say what the
  old dictionary says, loads the new bundle and says them again — in place: nothing is redrawn, nothing open closes,
  nothing typed is touched, and the switch is one request. Every script's writes were converted (some 3 200), and
  where words were GLUED into one string the lines now hand over pieces (pagers, counters, "Description by …", the
  stars line, the coverage card, the backups card, the DB memory card, toasts…); a sentence with people in it
  (":reporter reported :reported") keeps its key round its people; a word kept since the page loaded (the password
  checklist, the stats loading texts) is never stale.
* **Words the page wrote from stored values or English helpers** are words of the dictionary now: the panel's Users
  table said "ACTIVE" / "BANNED" on a Polish page; the whitelist's add results, its bans' sources and its source
  names, the index detail's metadata state, the sign-in form's "Stay signed in for" choices, and on Settings the
  metadata fetch order's modes, shares and "left out" list, the timeline's range buttons, the backup profiles and
  the schedules' day names were English on every page. The check now flags a word of the other language standing
  in a label's place (a badge, a status, a heading cell, a button, an option), whatever its case.
* **The walk rewrites only what the server wrote** (assets/js/lang-swap.js): the page marks what the server sent
  before its first script runs (LangSwap.mark()), and a node a script made — or wrote into since — takes no part in
  the walk. That ends "Loading…" written back over a script's rows, a torrent's name turned into "Details" (the
  Info panel's title) and the reports' column headers written over the appeals'. Templates are walked too, and a
  copy of one follows its template (the description editor, the message composer). Attributes the walk never
  swapped are swapped (data-empty-text, data-snd-label, data-ok-text, optgroup labels…), and an error page swaps
  like any other (the panel's hidden 404). A button a script borrows for a moment — "Working…", a bulk scrape's
  count in its label — and puts back from a copy is the server's again (after any bulk scrape, the Refresh S/L
  label used to stay in the language it was in), and one borrowed while the language changes comes back in the
  new language.
* **No word is frozen into a page any more**: Settings, the admin sign-in and the unsubscribe page read theirs from
  the page's dictionary; a server's own sentence a script shows is found back by its key (the sign-in's answers,
  why you may not rate, where an importer's link came from), and the anti-spam layer's countdown speaks the
  dictionary's sentence by the key its answer names. The reCAPTCHA notice and the whitelist form's counter, English
  on every page, are translated.
* **What redrew itself, or asked the server again, no longer does**: the search page, its Info panel and comments,
  the favourites / likes / descriptions lists, the messages' rows; Settings re-indexes its search from the
  catalogue it already has. The "Are you sure?" question stays open, its button waiting beside it; the emoji picker
  stays open on its page with the search typed in it (the one request a switch may add: an open picker's emoji
  names, which exist only in a file per language); the switcher's own press no longer closes a popover; the
  tooltip bubble showing at that moment reads its button again (and goes, when its button was drawn anew). The
  "who has this" window still draws itself again from the rows it holds — its counts are written in the page
  language's number format.
* **The favourites star says when it cannot**: a full list ("Your favourites list is full (N)…") or a failure is said
  in the site's toast — favourites.js called a showToastPub that never existed, and said nothing.
* **Found on the way**: the panel's IP lists "replace from a file" called its file's text `t` and threw on every
  word it tried to say (before 1.73.0 too).
* **The check**: scratchpad/shots/langswap_all_check.js (+ _states.js, _fixtures.js), in browsersweep.sh — 74 states,
  0 words left in the old language (both directions, both orders, desktop and phone); tests/lang_test.php pins the
  wiring (t() a plain string, t.key() never compared or glued, the escapers, the el() helpers, the marks, no frozen
  inline word, a borrowed button given back).

### Changed — the panel's words that were English in every language are translated

* **What part B's live-switch check found English on the Polish page even after a full reload** — the words had no
  Polish at all: the 75 permission descriptions (the Groups matrix's tooltips, the group editor's boxes, the
  Recommended window, Settings' shoutbox / emote / comment matrices), the Traffic card's recommendation under the
  slider, the time-zone selects' region groups, the page editor's page names, the home page's live-sync beacon.
* **Permissions** (`includes/users.php`): every id has its words in the dictionary (`perm.<id>`,
  `tools/lang_src.d/permissions.py`, written for the operator ticking the boxes), and `userPermissionList()` answers
  in the reader's language — the ids, their order and their number never change; `userPermissionList(true)` is the
  English the registry keeps (a shell tool). The panel says the words by the id (`t.key('perm.' + id)`; the Users and
  Settings pages carry `perm.`), so they follow the live language switch; the editor's box no longer glues them to
  the id. The group editor's "Start from" presets (Moderator, Content reviewer, Whitelist curator, Read-only
  auditor…) are named in the reader's language too. `panel.messages.view` / `.handle` were the only panel
  permissions whose words lacked the "PANEL — " mark: they have it. `tests/groups_matrix_test.php` holds the
  dictionary's English to the registry's word for word, both ways.
* **Traffic** (`includes/netlimit.php`): the paragraph under the slider is its sentences now —
  `netlimitRecommendParts()`, each a dictionary key and its numbers — said in the reader's language with the
  reader's numbers (40,000 / 40 000), and admin/net_status answers the parts beside the text, so the card writes
  every sentence as a word that keeps its key (the live switch; the anti-spam layer's way). The load study's
  reason for having no answer the same way; the card's three failures through the dictionary. "Last 1 day" is
  "Last day". The OpenTracker card beside it said its advice (workers against cores, the kernel's receive-buffer
  cap and the packets it discarded, the two config files disagreeing, foreign drop-ins) and its failures in English
  while the DB memory and sysctl cards' were the dictionary's: now they are too.
* **Time zones** (`includes/db_clock.php` `tzRegionLabel()`): the region groups of the four zone selects (the account
  page; Settings → Site, the schedule's zone, the backups' zone) are words — Europa, Ameryka, Ocean Indyjski, Inne…;
  the zone names stay IANA's ids, which are what is stored.
* **Pages** (`includes/pagecontent.php`, `includes/homelayout.php`): Settings → Site pages and the page editor name the
  two pages by their own headings (Regulamin, Informacje o trackerze), a home section "Strona główna — …"; Settings →
  Home page layout names its sections and says what each is in the reader's language, and the page editor's
  placeholder buttons say what each {{placeholder}} puts on the page. The audit log keeps English, whoever reads it
  (`pageContentLabel(…, true)`).
* **The home page's live-sync beacon** (`includes/homeblocks.php`): its tooltip is the dictionary's — the same words
  app.js writes when it takes the title over, so it no longer changes language halfway.

### Fixed — `traffic_cards_check.js` passed only on the day production's rows were taken

* It loads production's real `index_polls` (2026-09-04 → 09-30) and checks every range; from 2026-10-01 the 6 h range
  held none of them, the coverage chart was never drawn and the check timed out waiting for it. The rows now move
  by one constant — their spacing (the passes, the short downloads, the row with no count) exactly as recorded —
  and the times the assertions name move with them. The constant is a whole number of six hours of the local clock:
  the chart's buckets of 1, 2 and 3 hours start on the local clock's whole hours, and a shift that put the newest
  row five minutes before the run regrouped the rows (the 2-hour bucket of the row with no count lost its bar). So
  the newest row stands within the last six hours — as on 2026-09-30, when it stood an hour and a half before the
  run — and every bucket holds the rows it held then, across a clock change too.

### Fixed — nothing in a table is cut any more: badges, buttons, icons, in both languages

* **The owner's screenshot**: the Reports page's Archive in Polish, about 1660px wide — the STATUS column's red
  "Zablokowane" wider than its cell, the cell's own "…" standing in the badge's right corner ("Sprawdzone" fitted;
  the English "Blocked" is short enough never to show it). "Nie wiem czy w innych tabelkach cos podobnego sie nie
  dzieje" — it did, in most of them.
* **Measured everywhere first** (`scratchpad/shots/table_fit_check.js`, new, in the sweep): every table of the panel
  — the Reports' eight tabs (the four card tabs too), Whitelist's five views, Index, Users (users, groups and their
  matrix, the sends), Audit, Backups, Traffic's tables and tiles, Settings' tables — and of the public site — the
  search results, the account's favourites, uploads, likes, descriptions, lists, devices, notifications, people and
  members, the profile, transparency, the status page's two answers, stats, the API guide —, in English (an en-US
  browser) and Polish (pl-PL), at 1920, 1660, 1440 and 1280 and on a 390px phone, with Bootstrap Icons and Font Awesome
  6, filled with rows of every state a table shows (the longest word of each language: "Zaakceptowane", "Wstrzymaj do
  przeglądu", "POBIERANIE", "Opis przejrzany i opublikowany"…) and the longest values a column gets (32-character
  names, IPv6 addresses, five-digit swarms, a 32-character audit action). Every cell asks: is each badge, button,
  icon, checkbox, select and input inside the box the cell shows it in (±0.5px), with no ellipsis on it; does a
  select show its chosen option whole; does text that is cut end in "…" with its full value in a title? Before the
  fixes: 844 views, 4 036 hits in 68 places — screenshots `scratchpad/shots1730/table-before-*.png`.
* **One rule for a cell of badges** (`admin.css`, `td.col-badge` — the class the badge columns' headers already had,
  now on their cells across the panel: Reports' status and type, Whitelist's source and metadata, the banned list's
  source, an API key's scope, an API ban's reason, Index's metadata, Users' status and groups, Groups' default,
  Backups' integrity, Audit's action): never ellipsised; its badges wrap between them and a badge of several words
  between its words, never inside one; the column is as wide as its longest word in the longer language, measured,
  and takes the width from the columns that may ellipsise (the tables' floors raised where those were already at
  their headers' minimum). The status column of the Reports and the appeals: 118px for "Zaakceptowane" (97px) —
  the reports' floor 1224, the appeals' 1244 (their "DESCRIPTION" header was cut too).
* **A badge after a name** became the line under it: the partner a report came through ("via …", the Whitelist's own
  line since 1.63.1, `.cell-sub`, ellipsised with its full text in its title) — the label badge after the name stood
  89–379px under the name cell's "…"; Users' name row (the name ellipsises, the owner's shield never) with the bridges
  on a line under it; an upload's two badges go under its name when they do not fit beside it (they ran up to 94px
  over the row's facts).
* **Widths that were measured for a Polish browser** hold an en-US one: "09/30/2026, 03:03 PM" (130px) in Whitelist's,
  Users' and the search results' date columns; five-digit swarms ("23456 / 12345") in Whitelist, Index, the search
  results and the likes; Whitelist's metadata column holds "POBIERANIE" (its wide set now from 1910px, where the name
  keeps 150px); Users has a colgroup (nine equal shares before); an API key's scope a column of its own.
* **Text cut with nothing to read it in** has its title: Audit's actor and address, Backups' profile and contents,
  Users' address (the cell's title was the verification state), Groups' name, slug and permissions, a send's subject,
  an API ban's "lifted (by …)"; Index's metadata state is the Whitelist's badge (its classes existed only in the public
  stylesheet: plain words); on a phone the tracker week's rule selects keep their width (they were 50px), a reported
  comment's head breaks a 40-character hash, a member's name in People breaks instead of an ellipsis without a title.
* **After**: 0 hits in all 840 views, both libraries; the same 77 places before and after
  (`scratchpad/shots1730/table-before-*.png` / `table-after-*.png`, the before ones taken with the last release's
  stylesheets and panel scripts served in place of the tree's — the check's TFC_OVERLAY — which gave the BEFORE run's
  hits back one for one), the owner's own view among them (`table-*-owner-reports-archive-pl-1660.png`).

### Tests — tables that fit

* `scratchpad/shots/table_fit_check.js` (in the sweep, after panel_fixes_check): the views above; the fixtures —
  reports, archives, appeals and their archive of every state, API keys of every scope, whitelist rows of every
  source and metadata state, banned hashes of every source, API bans of every reason, observed hashes, members with
  every group, warnings and a bridge, a group, audit lines of every area, failed, with the longest action, reported
  messages, comments, descriptions and shouts open and closed, sends, address lists, CSP reports, federation peers
  and their review queue, sounds used as every default, emotes waiting and approved, a member's favourites, likes,
  descriptions, proposals, uploads, lists, devices, notifications and a friend request — and what a page reads from
  the machine (the backups' archives, the database-memory card, a third language, the icon packages, the stats'
  HTTP errors) answered in the page, never on the server; every table, row, counter and state file put back exactly
  (dumped before, loaded after). TFC_ONLY / TFC_LANGS / TFC_WIDTHS / TFC_LIBS / TFC_LOCALE / TFC_DUMP /
  TFC_SHOTS / TFC_PLACES / TFC_OVERLAY.
* Run with it: admin_access_test, authbridge, content_reports, emoji, groups_matrix, icons, partner_api, addressable,
  antispam, audit_fixes, index_polls, lists, profile_bio, schedule, shout_emotes (0 failed), lang_test;
  panel_fixes_check (its parts 1–3), settings_groups_check, settings_hit_check, account_width_check, reports_check,
  msgreport_check, polish_check, icons_align_check on the pages changed.

### Fixed — a member could switch off the password reset and lock themselves out

* **What happened**: every account mail asked the address's preferences, the password reset included. Switching
  off *Account mail* on the account page, or the master switch of the unsubscribe page that EVERY mail's footer
  leads to (`unsubscribed_emails`, which `isUnsubscribed()` reads for every kind), silently stopped the reset, every
  step of an e-mail change and its confirmations — only the verification mail still went out. Somebody who had done
  that and then forgot their password could not get back in.
* **Transactional mail is always sent and carries no unsubscribe**: the password reset (now `userResetSend()`, the
  endpoint's code moved where it can be tested), the four mails of an e-mail change and the verification mail never
  ask the preferences, and carry neither the footer's preferences link nor a `List-Unsubscribe` header — there is
  nothing in them to unsubscribe from (`userNotifyMail(…, ['transactional' => true])`).
* **The switch governs what it says, in its own words**: *Account mail* is "Your groups — access granted, access
  about to end — and e-mail copies of the operator's notices. Password resets, e-mail address changes and their
  confirmations always reach you, whatever you choose here." (it said "expiry warnings, security notices and
  anything else about this account"). Those mails — group granted from the panel or a shop, the expiry warning,
  the copy of an operator's notice — still ask it and still carry the header.
* **A mail client's one-click Unsubscribe works**: RFC 8058 clients POST `List-Unsubscribe=One-Click` to the
  `List-Unsubscribe` address — the unsubscribe page — which read no POST: the client told the reader
  "unsubscribed" and nothing was recorded. The page now unsubscribes the address from everything that may be
  switched off (`unsubscribeAll()`, shared with `api/unsubscribe.php`); the transactional mail is not among it.

### Fixed — deleting an account left its second factor behind (schema 91)

* `userDeleteCascade()` never touched `user_twofa`: a deleted account's TOTP secret and recovery hashes stayed. Every
  account column in the schema was checked against the cascade: it also missed the "…is typing" rows and a bulk mail
  still queued for the account (the janitor would have mailed somebody whose account was gone — now skipped, "account
  deleted", the way a cancelled batch is). And its votes went but the totals stored on the catalogue rows — what a
  search listing shows beside a torrent — went on counting them until somebody voted on that hash again: the hashes
  it voted on are counted again (`repRecount()`, in the site's mode). Kept on purpose, and said so in the code: the
  audit log, a partner shop's order rows (the ledger and its replay guard), a moderator's stamps on others' shouts and
  an emote's uploader (raw ids), the address-keyed mail preferences.
* **Schema 91** removes, once, what the cascade left behind before it knew those tables
  (`schemaAccountOrphans()`): second-factor and typing rows of accounts that no longer exist, a queued mail to one
  (skipped), and a gone account's votes from before 1.42.0 put `hash_votes` in the cascade, with the totals of
  exactly the hashes they touched counted again. A live account's rows are never touched.

### Fixed — a partner's held submission was served while it waited for a person

* 1.42.0's review queue promises that a partner key which does not publish directly has nothing served until somebody
  approves it, and the accesslist generator honours that (`review_status IN ('none','approved')`). But the add path
  in whitelist mode APPENDS what it added to the live file — and it appended a held row too, so a waiting submission
  was served from the second it arrived until the next full regeneration. It appends only what the generator would
  write now. `tests/partner_api_test.php` proved the generator; it now proves the add path too, on a real file.
* **A registration proving itself is served while it does — on purpose, and now consistently.** Its proof is a peer
  announcing to THIS tracker, and a tracker in whitelist mode refuses the announces of a hash its list does not
  carry, so a probe whose hash is not served can never pass. The add path always served it; the generator dropped
  `probing` rows, so any full regeneration in the probe's minutes (another probe passing, a ban) withdrew it and the
  probe then failed for a reason that was not the torrent's; and a failed probe stayed served until some unrelated
  regeneration came along. The generator keeps `probing` rows, a failure is withdrawn at once, and the panel's words
  say what happens ("to stay on the tracker… served while it tries… leaves the list the moment it fails"); Info says
  it in one sentence where the feature is on.

### Fixed — the whitelist hours in English on the Polish page

The whitelist page printed `scheduleDescribe()` — "Mon–Fri 22:00–06:00 (next day), … (Europe/Warsaw)" — and PHP's
English day ("next change at 02:30 Tue") on its Polish page; the panel's Whitelist card and Settings did the same.
One function, `scheduleDescribeText()`, now says the week in the reader's language for every page and the panel
("pon–pt 22:00–06:00 następnego dnia, sob–niedz cały dzień, czas Europe/Warsaw"), the next change is "wt 02:30",
and Info's and the home page's hours are the same function (part C's words, moved where the schedule lives). The
CLI and the API's `describe` keep their English.

### Fixed — the partner API: an `all` key with a report field refused every registration, and the guide said the wrong thing

* `v1/whitelist/submit` read the key's required fields raw. An `all` key's list holds both halves — `reporter`,
  `statement`… beside `name`, `url` — and a registration can never carry `reporter`, so every item came back
  `invalid`, `missing_reporter`. It reads them through the registrations' vocabulary, as the report endpoint does its own.
* **The guide's address says each chapter's own answer**: `approve=` what happens to a registration, `block=` what
  happens to a report. A new abuse key's address carried its creator's *approval* answer — a key that holds reports
  for review was handed a guide saying they block on arrival — and an `all` key's reporting chapter had no answer of
  its own. The key list, the new-key dialog and the editor's live preview all write both now; a key that sends
  nothing (users, shop, federation) carries neither.

### Fixed — retention that never ran

The janitor runs one retention step in every tracker mode (`includes/retention.php`): API bans 90 days after they
ran out (pruned only in whitelist mode, by one request in fifty), the sign-in bridge's spent and expired tickets
(its comment said the janitor pruned them; nothing called it but the next ticket), the panel's failed sign-ins
older than the lockout window for every address (only the failing address's own list was ever trimmed), and the
forms' limits older than an hour for every action (an action trimmed only its own keys, when it was next called).
Bounded; the two files under their own locks, rewritten only when something went. So what Info says — "for as long
as the limit lasts" — is true without anybody coming back.

### Fixed — the Transparency page's switch had no default (schema 91)

`transparency_enabled` was written by the installer but not by the migration. Without the row the menu link and the
page's text read it as on and the page's data as off (403): a link to a page that could not load its table, on
every install whose settings came from the migration. It is a default row now ('1'), never overwriting a stored one.

### Fixed — message times said the database's clock, not the reader's

The messages' times — a conversation's lines (also as they arrive through the poll), the inbox's and the Archive's
rows, the Trash's rows and its "deleted for good on" date — were the database session's wall clock, cut to sixteen
characters by the page; so were the friends' and blocks' dates and the account page's notifications (read by the
browser as if they were its own zone). The shoutbox has said its times in the reader's zone since 1.62.0; now these do
too: every moment travels as an instant (`UNIX_TIMESTAMP()` of the column, from the database, which knows the zone it
wrote it in) and the endpoints send it as 'Y-m-d H:i' on the reader's clock — the zone chosen on the account page,
the site's otherwise (`pmReaderTime()`, `userDisplayTimezone()`). Digits only, so the live language switch has nothing
to say again in them; the raw fields stay for anything that reads them. Found on the way: `people_check.js`'s
"a line after the deletion" was written with the mysql client's clock (`DEFAULT`, `NOW()`) — two hours ahead of the
app locally; it is written on the app's clock now. Tests: `tests/people_test.php` (the instants of a message, an
inbox row and the Trash; a reader in Tokyo, one in the site's Warsaw; the endpoints and the page),
`people_check.js` (a peer reading in Tokyo: the message and its inbox row in English and Polish, a notification);
`pm_trash_check.js`, `pulse_check.js`, `msgreport_check.js` and `langswap_all_check.js` on the messages' states.
Not changed, the same class elsewhere: the account page's "Member since" and the bridge's "linked since" still print
the database session's clock (server-side, templates/pages/account.php).

### Documentation

`tools/opentracker/README.md` names `WANT_SPOT_WOODPECKER` among the flags deliberately left out — the first
install's recipe, and the main README until part C's rewrite, listed it. Checked against the shipped binaries
rather than the notes: the word `woodpeckers` is in them only because upstream's `/stats` mode table carries it
whatever the flags; the flag's code — a `stats_issue_event(EVENT_WOODPECKER, …)` call and its case — is not (in both
builds no call passes that event, and the event switch sends it to the no-op case).

### Tests

New: `tests/mail_test.php` (50 checks — PHP's `mail.log` records every mail the real `sendEmail()` builds: the
transactional mails to an address unsubscribed from everything, their missing header, the governed ones off and on,
the expiry warning through the janitor's tick, the one-click on the real page), `tests/account_delete_test.php` (29
— a fresh account's row in every table the cascade had missed; a registry that fails on the next table naming an
account that is neither in the cascade nor kept on purpose; nothing left with the id; the v91 step on orphans beside
a live account's rows), `tests/retention_test.php` (23 — each prune with a clock passed in, both files put back byte
for byte, every limiter call's window read out of the code with PHP's tokeniser). In existing suites:
`partner_api_test.php` (88 — the add path on a real file, the probe's rows in and out of it, the guide's two
answers, an `all` key over HTTP), `schedule_test.php` (90 — the hours EN/PL, and the whitelist page rendered in both
languages), `install_test.php` (39 — the v91 default on fresh and upgraded installs, an operator's "off" surviving),
`reputation_test.php` (81 — the generator's filter as it is now), `partner_bridge_check.js` (`block=` in the key
editor's live link, an `all` key's two answers), `texts_check.js` (the probe on in its "everything on" pass). And
every suite that runs the cascade or the changed code, all green: antispam, authbridge, comments, content_reports,
content, csrf_token, favourites, groups_cli, groups_matrix, lists, people, pm_trash, profile_bio, profile_votes,
sounds, usermedia, who, rate_limit, cluster, hash_check, iconpack, user2fa, audit_fixes, pagecontent; guest_pages,
shop_api, audit_lists, avatar_names, message_report, pm_delete, pulse, usermedia and hash_check over HTTP.

### Changed — Info, Terms and the Features list say what this version does, and only what is switched on

* **The owner**: "make sure info and terms and features are written for the newest version." Production serves
  the built-in pages (its `page_content` is empty), so what the code ships is what every visitor reads — and what
  it shipped was written at 1.34.0. It promised two things nothing in the code does: "random IP addresses are
  inserted into peer lists" (no patch, no build flag, no line of PHP does that — opentracker has a test macro of
  that name and nothing else) and "we do not keep IP address logs" beside a site that keeps a registrant's, a
  reporter's, an account's and a guest commenter's address. It said "the tracker only stores active swarms… no
  torrent names" on a site with a catalogue of names and file lists, "registrations are anonymous" where the
  address is kept, "abusive accounts are deleted together with their registrations" where deletion keeps them,
  and nothing at all about accounts' second factor, devices, profiles, lists, friends, messages, comments, the
  shoutbox, reports, warnings or the anti-spam layer.
* **Info, rewritten against the code**, every sentence with its file and line in the release's claims table: what
  an announce carries and what opentracker keeps (in memory only, dropped 45 minutes after the last announce — its
  `OT_PEER_TIMEOUT` —, never logged, never in the site's database; the one request it writes down is a full scrape,
  with the address that asked; the egress budget's three hours, in memory, where it is installed); the whitelist,
  its hours and how to register (a CAPTCHA every time in public mode, the address kept for as long as the
  registration exists); the catalogue — what it records, that a name arrives for a limited number a day, how long
  an entry lives (`index_grace_days`, `index_protect_days`, kept longer when somebody saved it); accounts, the
  second factor, devices and the "until you sign out" default, groups that end on a date, notifications (read ones
  deleted after 90 days, any after a year); profiles (signed-in members only, everything on them off until the
  member says yes), pictures re-encoded and stripped of their metadata; favourites and lists; friends, blocks and
  private messages — the Archive, the Trash and its days, and that deleting only ever deletes for you, the messages
  themselves staying until an account is deleted; comments (soft removal kept with who, when and why; guests and
  their address group), descriptions with their credits, ratings; the shoutbox and its retention; reports and what
  moderation can do; the anti-spam layer (fingerprints, never the words, forgotten after two days). Then **what this
  site keeps and for how long**, one line per kind of record, and **the cookies and what the browser loads from
  elsewhere** (`PHPSESSID` on every visit, `thx_remember` and `lang` only when chosen, jsDelivr's icon font, the
  CAPTCHA provider, hot-linked pictures). The questions answer truthfully: a hash can be banned in either mode, the
  tracker sees only the hash while the index may know names, the tracker keeps no announce log while the site keeps
  addresses in the places listed.
* **Terms, rewritten**: the owner's general clauses stay (personal use, the commercial fee, no uptime promise); the
  registration and index clauses say what is kept; a report clause; accounts (your password is yours to keep, the
  second factor, what partners connected through the API receive, groups that end, the bridge, abuse, deletion by
  writing to the operator); **what you write** — comments, guests, descriptions and their credits, lists, messages,
  the shoutbox, the profile; **reports and moderation** — the flag, nobody told who reported, the reporter told the
  outcome; remove, warn, silence (what a silence stops) for a day, a week, a month or until lifted, suspend for a
  week, a month or until lifted, each silently or as a warning that stays with the account; never against staff or
  oneself; no formal appeal, but the operator can be written to; **anti-spam** and getting around it. Nothing in it
  promises an appeal, a deletion button or a notice the code does not have.
* **Every optional paragraph stands under its feature's condition.** Forty conditions joined the sixteen —
  `twofa`, `email_cooldown`, `profiles`, `pictures`, `bio`, `favourites`, `lists`, `lists_public`, `lists_friends`, `saved`,
  `friends`, `directory`, `messages`, `trash`, `archive_returns`, `people`, `comments`, `guest_comments`,
  `writing`, `shoutbox`, `sounds`, `community`, `reportable`, `report_words`, `antispam`, `antispam_new`, `api`,
  `bridge`, `federation`, `audit`, `audit_members`, `backups`, `backup_days`, `csp_reports`, `captcha`,
  `icons_cdn`, `images`, `index_kept`, `registration_members`, `whitelist_or_schedule` — each asking the feature's
  OWN gate (`commentsEnabled()`, `shoutEnabled()` …), never a second reading of its switch. That is how one was
  found broken: `[[if:ratings]]` read `rating_enabled`, a setting that has never existed (the switch is
  `rep_enabled`), so its paragraph had never appeared.
* **`[[value:name]]`**: a retention period that is a setting is written as the setting's name and read when the page
  is shown — `index_grace_days`, `index_protect_days`, `shout_keep_days`, `shout_keep_rows`, `pm_trash_days`,
  `antispam_new_days`, `audit_keep_days`, `backup_keep_days`, `email_change_days`, `schedule_hours` — so a saved
  page does not go on saying 30 after the operator chose 14. A number of days comes with its noun in the reader's
  language ("30 days", "1 day"; "30 dni", "1 dzień"): the noun has to agree with a number only the code knows, and
  "przez :days dni" would have said "przez 1 dni" the day somebody chose 1. The whitelist hours are words in the
  reader's language too — they were English on the Polish home page ("Mon–Fri 22:00–06:00 (next day)").
  Terms say the e-mail cool-down and the new accounts' slower pace only while they exist (0 switches either off),
  and Info no longer mentions a Trash on a site that has none.
* **One description of each page, two renderings.** The conditions used to be written twice — PHP in
  `templates/pages/info.php` / `tos.php`, markers in `pageContentDefault()` — and with forty optional paragraphs
  that is forty chances to drift. Both pages are now data (`pageContentSpec()`); the templates print it
  (`pageContentHtml()`) and *Restore built-in* writes the same list out with the markers. The home page's
  Features list asks the same conditions: a bullet for each feature that is on (accounts, the second factor,
  profiles, favourites and lists, friends, messages, comments, descriptions, ratings, the shoutbox, the anti-spam
  layer), the transparency bullet saying what that page counts ("of every removal request" was more than it
  shows), a link to what is stored, and the two bullets nothing backed gone. About's "running on Linux Debian since
  2020" — the owner's own server, shipped to every install — is now the footer's two settings (`footer_os_name`,
  `footer_os_since_year`), and absent when the footer does not show them.

### Fixed — a hidden list item split the editor's Terms in two

`pageContentResolveMarkers()` left a hidden block's line break behind — a blank line, which ends a Markdown list:
the built-in Terms restored and saved in blacklist mode came out as two lists, the second numbered from 1 again (the
CHANGELOG of 1.34.0 said the numbering closed over the gap; the test counted items, not lists). A hidden block that
stands on lines of its own now takes its line break with it, and a shown block left empty by the hidden ones inside
it goes the same way; a marker inside a sentence still removes only itself. The editor's default text also links to
the site's own pages with absolute addresses (`site_url`): the renderer keeps only `http(s)` links, so *Restore* used
to give back a page whose every link to the site was plain words. And a page is no longer held to a description's
length when it is saved or previewed (`pageContentValidate()` — the renderer's link rules, the image limit, the
page's own 60 000-byte cap): the built-in Info is about 19 000 characters, Terms 7 000, and with `desc_max_chars`
(4 000) neither could have been restored and saved again.

### Changed — the integration guide says what the API does

* The rules: the per-key limits are a minute's requests and a day's **bytes** (the numbers this tracker uses, read
  from its settings), answered with `429` and `Retry-After` — **never a ban** (the page said the key would be banned
  for sending on). What does ban is a malformed, unknown or wrong key: the address it came from, for `api_ban_days`,
  the right key refused from there too — said beside the header, where an integrator reads it first. The error list
  names every status the endpoints answer.
* The check-it-works commands change nothing any more: the whitelist one used to POST the example to
  `v1/whitelist/submit` — registering the placeholder hash for good, on a page that says nothing can be taken back —
  and the federation one pulled a page of the catalogue. Each scope now has a call that only answers (the pings, a
  lookup, an empty report batch refused as `no_items` after the key was accepted).
* `v1/whitelist/ping` and the status endpoint's `invalid` row are documented; an item's forms (a magnet, a 40-hex or
  base32 hash, a `ref`); `added` means served from the next reload (`active_in_seconds`) and only in whitelist mode;
  `exists` also for a row waiting or turned down. The reporting chapter reads its own answer (`block=`, review by
  default — an `all` key's page used to borrow the whitelist half's "publish immediately" and tell a partner its
  reports block on arrival), names the batch-level `reporter` / `statement`, and `blocked_in_database_only`. The shop
  chapter: the durations, that a grant with neither duration nor date is permanent, the order id's limits, that a
  refunded order never grants again, and that `external_id` only works for a key that runs the bridge (a shop key
  uses `login` or `user_id`). The bridge: the ticket's real lifetime, `&next=`, `create_failed:email_taken`. The
  federation chapter says what the export is (resolved metadata, page by page, from a cursor) instead of "exchanges
  hash lists". The browser tab is called "Integration guide" (it said "Home"). Numbers are written the reader's way
  ("1,000" read as one in Polish) and the Polish text was proofread.

### Documentation

* **INSTALL.md**, rewritten so a stranger can install from it alone: PHP 8.0+ and every extension the code calls
  (with GD's WebP check), MariaDB 10.6+ (a fresh install fails on MySQL), an MTA, the php.ini and php-fpm limits, the
  files and their two writable directories (`config/`, `lang/`), Apache with php-fpm, the subfolder `RewriteBase`,
  what `.htaccess` does not deny (`.git`, `tests`, `worker` — the vhost does), the **enforced `.htaccess` CSP** under
  php-fpm, opentracker (the accesslist files 0664, `access.stats 127.0.0.1`, the unit exactly as the helpers expect),
  the installer step by step, the first settings (the service name, the statistics URL, *Switch the tracker now*),
  the janitor and its slow half (`tracker-janitor-heavy` through the netlimit helper, inline without it), all eight
  root helpers with their sudoers lines and the four fields that ship empty, nftables persistence and the egress
  budget, the worker, tuning, backups **and restoring**, the health check, icon packages and the CLI tools, a
  checklist, upgrading (the copies `git pull` does not update, the schema lock, what each release asked for by
  hand), twenty-five symptoms with their causes, and the security notes.
* **README.md**: the Features list rewritten — every feature of this version, each with what it needs (the setting
  and its default, the permission and the group that holds it, the helper or extension); Requirements; a table of
  all eighteen Settings groups and their sections; the API reference names every endpoint `api.php` routes (a hundred
  were missing); Project structure and Database schema complete (28 tables were missing); the Site pages section for
  the spec, every marker and the values; the nginx deny rules (`^~ /api/`, `tools|worker|lang`, dotfiles) and headers;
  every Settings path that had been renamed; the build recipe with all four patches and the pinned commit; no more
  `DB_*` environment variables, `sql/` directory, `[[/ifnot]]` closer or `deploy/` files that do not exist.
* **worker/README.md** and the example conf: the heartbeat is a JSON line (and the `UMask` that hid it from the
  panel), the grants the fetch order and the federation review need, every conf key with its default, every
  `federation.py` option; the example names no real tracker. **tools/opentracker/README.md**: four patches, every
  helper, the recipe in order, the garbled commit string in the shipped builds, the lab notes marked internal and the
  conclusions they later overturned marked superseded; **UPSTREAM-REPORT.md** carries an *internal draft* banner and
  no longer says nothing was patched. `.htaccess`'s comments say what its policy does under php-fpm.
  `assets/sounds/README.md`, `tools/api_client_example.py` (the `shop` scope, `order_id`, `premium`) follow the code.

### Tests

`tests/pagecontent_test.php` (112 checks): the HTML page and the editor's default have every item the spec shows,
in as many lists — the split list caught —, no marker or key; every condition, value and key the spec names exists in
both languages; the resolver's line-break rules; values — a number of days with its noun in English and Polish, and
no shipped sentence writing "days" after one; the whitelist hours in both languages; absolute links; the page
validator. New
`scratchpad/shots/texts_check.js` (88 checks, with `texts_expected.php`): Info, Terms and the home page in English
and Polish with every feature on and as much as possible off — each page exactly what the spec renders for those
settings, no marker, no untranslated key, no heading over nothing, none of the off features' words; every cookie,
route and host the texts name exists in the code; no sideways scroll at 390 px; screenshots
`scratchpad/shots1730/texts-*.png`.

### Changed — the footer states the project's licence: MIT

* The footer's second line said "Content rights waived via CC0" on every page — a waiver the repository's `LICENSE`
  (MIT) never made, and one no site can make for what its members write. The owner: "Robimy pełen MIT." The line is
  now "Released under the MIT License" / "Udostępnione na licencji MIT" (`footer.license_mit`, `footer.cc0` gone), a
  link to the repository's `LICENSE` when Settings has a GitHub address, plain words when it has none.

### Fixed — `tests/audit_test.php` failed once the checkout's own audit lines turned 31 days old

* The retention check ages its three lines by 400 days and prunes with a 30-day window, then expected exactly 3 removed.
  The prune removes every line past the window, and from 2026-10-01 the local checkout had 390 of its own (the first
  September runs), so it read 393 — and deleted them. The older lines now wait in a temporary table of the test's
  connection while the prune runs and go back with their own ids; the check counts 3 plus them, and a new one proves
  they are all back.

### Fixed — the statistics page's "Git commit" link took the visited colour

* The owner: the Stats page's version value (`#val-version`, opentracker's build as a link) showed in the visited pink
  once opened. `.status-link` set the link colour with a bare class (0,1,0), which the site-wide `a:visited` (0,1,1)
  outranks — the same trap 1.71.0 closed for the sign-in's links. `.status-link:visited` now keeps the link colour, for
  every link of that class (the status page's report link too).

### Fixed — the Whitelist's Source column holds the Polish "ADMINISTRATOR"

* The release's own final check (`table_fit_check.js`) found the panel's Whitelist and banned list showing a row's
  source as a badge sized before the sources became words of the dictionary: the Polish "ADMINISTRATOR" (110 px)
  stood 22–33 px past its cell, in both views of the Whitelist and in the banned list. The column is now as wide as
  that word plus the cell's padding and the 4 px for a wider font — 128 px in the Whitelist's narrow set of widths,
  132 in its wide set and the banned list — and the tables' floors grew by as much (1240, 936), so the name and the
  reason keep their room. The Whitelist's wide set (the whole 40-character hash) waits until 1955 px, where it still
  leaves the name 150 px; at 1920 the hash is shortened (full in its title, a click copies it) and the name gets 354.
  Measured again: every table, both icon libraries, both languages, 1920 to a phone — 840 views, nothing cut; the
  Whitelist also at 1955 and 2560.

### Tests — what the final battery found

* `tests/shout_test.php`: four pins quoted the scripts as they were before their words became `t.key()` words, and
  the in-place "Are you sure?" from when a language switch closed it. They read today's code — the question's own
  function: hidden controls, its timer, the button waiting hidden beside it, one finish() that takes down the timer
  and both listeners, every way out through it — and fail if the old language-switch exit comes back. The browser
  shows each behaviour (shout_check, people_check; the favourites chip's "Copied!" pressed in both languages).
* `tests/avatar_names_test.py`: the inbox rows, a conversation's state and the people rows carry the reader's-clock
  fields 1.73.0 added (`last_time`; `trashed_ts`, `until_ts`, `trashed_time`, `until_time`; `since_time`). Read in real
  answers — times and instants, never an id — they are pinned with their shapes.
* `tests/account_delete_test.php` failed in the battery only: a message report left behind by the smoke run (it
  deletes conversations without their reports and empties `users`, so ids are handed out again) carried the id the
  test's fresh account was then given. Not a gap in the deletion — a report is filed by one of its conversation's two
  people and goes with the conversation. The test counts, before it makes its accounts, what already names an id no
  account has, and judges only what it put there itself; a copy that leaves its own rows behind still fails.

## [1.72.1] — 2026-09-30

Corrections from the owner's screenshots of 1.72.0 on his server. The scrape-coverage chart reads over a week, a
month and All: one bar per bucket of time where one per pass no longer fits, a pass's bar never taller than the
ground it walked (the spikes of 04–05.09 were short downloads stacked on top of each other), and short downloads
of three weeks ago said as old, not as a warning. The Index catalogue's protected mark stands apart from the name.
The favourites star stands wherever a torrent's actions stand — a list's rows, the likes and descriptions tables,
somebody else's favourites and uploads — in the reader's own state, and as far from its neighbour as the framed
buttons stand from each other, still without a frame. And on a phone the search results' "Last seen" takes two
lines instead of running under the Magnet.

### Changed — the scrape-coverage chart reads over a week, a month and All

* **The owner's screenshots** (production, 2026-09-30): six hours and a day read well; a week's bars were 3 px,
  two weeks' hairlines, and on the month and All the bars were gone — only the dashed line was left — with tall
  spikes around 04.09 – 08.09 stretching the y axis and a vertical white dashed stroke near 08.09; the month's
  "Download ended early 52" and its warning ("If it keeps happening, look at the tracker's side of it") were
  about 04.09 – 05.09, before opentracker's MSG_ZEROCOPY fix of 05.09 12:48 — none since.
* **Measured on production's own rows** (all 1 274 polls, 04.09 → 30.09, loaded locally): one bar per pass is
  2.1 px for a week at 1 440 px, 1.1 for two weeks, less than a pixel for a month — and a bar that narrow is its
  own 1-px dark outline, the background's colour: the bars were drawn, invisibly. The spikes were eleven passes
  of 04.09 – 05.09 made of short downloads: each restarted at entry 0 and joined the open pass, and the bar
  stacked what every poll delivered past its own start — the 04.09 09:29 pass, thirteen short downloads and the
  poll that finished it, stood at 5 017 793 over a scrape of 1 468 888, and the month's axis ran to 6M. The
  coverage numbers were right all along (a pass's walked is the furthest entry). The vertical stroke was not the
  one row without a tracker count (05.09 12:51 — the line already broke there): it was the tracker's real count
  of 07.09 12:45, 289 419 for the one poll after a restart, between 1 579 527 and 1 367 400 — a V an hour wide,
  drawn across 26 days.
* **One bar per bucket of time when one per pass does not fit**: when the passes' median spacing at the chart's
  actual width is under 4 px, the passes are grouped into the smallest of 1 h, 2 h, 3 h, 6 h, 12 h, a day, two
  days or a week (on the local clock; weeks from a Monday) that is 4 px wide — worked out on every redraw, so a
  resize or a phone re-buckets. A pass counts in the bucket of its first poll; the bar is the **mean** coverage of
  its finished passes (the statistic of the "Average coverage per pass" tile) of the bucket's tracker count, the
  darker part what was kept of the ground walked; a light tick overhangs the bar where its worst pass stood when
  that was below 95 %; a download that ended early or a failed poll is a strip at the bar's foot in the legend's
  amber or red; a bucket holding only the pass in progress is dimmed, what is left outlined. A bucket's column
  answers a pointer or a tap with its time, passes and polls, average and worst coverage, cuts, short downloads,
  failed polls and the tracker's count — the tiles' own words — and the legend says what one bar is ("One bar =
  3 h, the average of its passes"). The per-pass chart of 1.72.0 stays wherever it fits: six hours and a day on a
  desktop (and on a phone), a week at 1 920 px. Production's month at 1 440 px: a week in 1-hour buckets, two
  weeks 2 h, the month and All 3 h; on a 360 px phone 3 h, 6 h, 12 h, 12 h.
* **The stack is the ground covered, never more**: each poll's part is the NEW ground it walked — from the further
  of its own start and what its pass had reached before it, to its end (`new_from` / `new` / `again` /
  `kept_new` per poll and `ground` per pass in the reply, from `indexPollPasses()`). A poll that walked old
  ground again — a short download restarting inside an open pass — adds nothing to the bar's height: it is a
  2-px sliver inside it, and says "read again from the start: 432 703 entries, none of them new" (or "…, 47 907 of
  them new"). The y axis is the larger of the tracker's count and the furthest entry, with a little headroom —
  never a sum: the month's is 2.5M.
* **The dashed line**: one bar per pass, it breaks where a poll has no count (as it did; now tested); one bar per
  bucket, it is the largest count the tracker reported in the bucket — a restart only ever lowers the count, for
  a poll or two, so the line no longer dives with it — the bucket's words giving "lo – hi" when they differ, and it
  breaks only where a bucket has no count at all.
* **When, not only how many**: the reply gives the window's newest short download and failed poll, and how many
  fell in its last day. The tile and the note say "the last on 05.09 12:22 — none since" — muted, no warning, no
  "if it keeps happening" — or, within the last 24 hours, "the last 2 h 5 min ago" with the warning as before. The
  failed-polls tile is red only while one is recent and says when the last was; the worst pass says when it began
  and takes the warning colour only while it is recent. Dates in the card's own dd.mm hh:mm (the axis's format).
* **Also**: All starts at the oldest row, not at the retention's start (production's All was 64 empty days beside
  26 drawn ones); the x axis steps up to 2, 4, 8 and 13 weeks and keeps daily and weekly ticks on midnight across a
  change of the clocks — a phone's All had printed thirteen dates over one another in 244 px.

### Changed — the Index catalogue's protected and promoted marks stand apart from the name

* **The owner**: on the panel's Index page a protected row's shield stood against the name's last letter ("…[FLAC]
  [rjk]🛡"). Measured: 1.5 – 2.0 px from where the letter stands to the shield's ink, in every icon mode — and when
  the name filled its column, the shield was what the cell's ellipsis cut (such a name ended in two "…").
* **One row** (`.idx-name-row`, admin-index.js and admin.css): the name shrinks and takes the ellipsis, a mark never
  does and never wraps alone, and the space between them is the panel's own for an icon beside words ("An icon and
  its words" in admin.css — a space of the face from the glyph's ink). Measured the same way as the toolbar's
  buttons above the table (their icon's ink to the first letter's box: Bootstrap 3.4 – 4.5 px, Free 6 3.7 – 4.2,
  Free 7 4.2 – 4.3): the name's last letter to the shield's ink Bootstrap 4.1 – 4.2 px, Font Awesome Free and Pro 6.7.2
  / 7.3.1 4.0 px; level with the name within 0.6 px (Font Awesome 7's star in the promoted mark was 1.1 px high and
  is 0.1 now — it is measured by assets/js/icons.js as a glyph beside words); English and Polish, 1 440 px and a
  360 px phone. Each mark carries its name ("Protected", "Promoted") as its title and label.
* **The faint square after the shortened hash** is the copy button's clipboard — the right glyph in every mode
  (Bootstrap's clipboard, Font Awesome's regular clipboard, drawn by the chosen face) — in Bootstrap's light-theme
  text colour (#212529) on the panel's #1e1e1e cells, about 1.1 : 1. Left as it is, as asked; a colour for
  `.wl-copy` in admin.css would bring it out.

### Tests — the coverage chart and the Index marks

* `tests/index_polls_test.php` (112 → 149 checks): the new ground — a short download inside an open pass,
  one that got further than the pass, production's 04.09 09:29 pass (5 017 793 delivered, 1 468 888 of ground,
  every part standing where the one before it stopped), the 1.29.0 cursor beyond the reach, the shrunk scrape,
  a continuation without its start; production's NULL count of 05.09 12:51 (pure, and through the database: null
  in the reply, never 0; its pass out of the average and the worst); the reply's `now` and when — recent, and two
  days on not; the words in both languages.
* `scratchpad/shots/traffic_cards_check.js` (61 → 129 PASS): a short download inside an open pass (a sliver, the bar
  ending at the ground walked, its words) and a poll without a count (the line breaks); production's month, every
  range at 1 440, 1 920 and 1 280 px and on a phone — bars at least 3 px, none above the axis, nothing sideways,
  no date printed over another; the month and All bucketed with the axis at 2.5M, no vertical stroke, the NULL
  row's bucket taking the count beside it, the strip of 04.09's short downloads, the worst pass's tick; a day one
  bar per pass, a week too at 1 920 px; the legend; a resize re-bucketing without a reload; a bucket's words on
  hover and on a tap; the 52 old short downloads muted with their date and a plain note, the worst pass's date,
  Polish; then a fresh short download an hour ago — a warning again. Run on the local copy of production's rows,
  which never enter the repository (skipped, and said, without them).

### Added — the favourites star wherever a torrent's actions stand

* **The owner**: "the icons to add to favourites are missing when we browse a list, on the descriptions too — and on
  the profile (the public view) add the stars on the hash actions, the likes are missing them, the descriptions and so
  on". The star stood in the search results, the Info panel's head and your own favourites only. It stands after Info
  now in every row that has Magnet and Info: a list's window (your own, before the row's ✕, and anybody else's you may
  read), the favourites and the uploads on somebody else's profile and your own (before an upload's "Shown on your
  profile" switch), and the likes / ratings and the descriptions tables — the account page's tabs and a profile's
  sections. For a reader who may keep favourites (`favourites.use`, and `index.view`, which the star's own request
  asks); nobody else sees one.
* **It is the reader's own**: filled when YOU keep that torrent, whoever's list it is on — what the owner keeps never
  shows through. The state comes with the rows: each endpoint that serves them (user_favourites for somebody else's
  list, user_uploads, user_list_items, user_votes, user_descriptions) marks every row whose hash the reader is shown
  with `fav`, from one question for the page (`favMarkRows()`, `includes/favourites.php` — never one per row); a row
  whose hash is withheld has no star, and a reader given no star gets no `fav` at all. A press goes the search results'
  own way — the same request, the page's token, the same hourly limit and the same messages — both ways, and every
  other star of that torrent on the page (the search results', the Info panel's head, the other rows') follows it at
  once; a table the live language switch draws again keeps it.
* Your own favourites keep their star as it was (always on; un-starring takes the row away). Left without one, on
  purpose: the whitelist form's result (a receipt for what was just registered, a guest's too), "Who has this"
  (people), the list picker (lists). The empty Favourites tab says where the star is: "The star beside a torrent puts
  it here — in the search results, a list or on a profile."

### Fixed — the star stood further from its neighbour than the buttons from each other

* **The owner's screenshot**: `[Magnet] [Copy] [Info] ☆` — "aligned, but as if it had that box on it too, and it does
  not, so it looks further away" — "but do not put a frame round it". The star is the one icon button drawn without a
  frame, in a button's 26px box that draws nothing but the glyph: beside Info its empty half read as more room — its
  ink 10.7px from Info's edge where two framed buttons stand 5.6px apart (Bootstrap; 10.3 with Font Awesome 6, 10.0
  with 7 and Pro 7.3.1), and 12.2px from the "+" in the Info panel's head, whose buttons stand 6.4px apart.
* **A framed neighbour stands the row's gap from the star's INK now**: the box reaches that far into the gap on each
  side that has a neighbour — after Info in a row, before the "+" (or Share) in the head, both sides where a row's
  own control follows it (a list's ✕, an upload's switch) — and its undrawn room stays on the side with none, the
  row's end or the head's title side. How far a star's ink reaches is its glyph's: half an em for Bootstrap's two
  stars, which fill their square; a Font Awesome star's measured in the face it is drawn in by the observer
  (`assets/js/icons.js`, set on the button — 0.53em for Free 6, 0.54 / 0.56em for Free 7 and Pro 7). Still no
  frame, still a 26px target (28px in the head), its glyph in its box's middle.
* **Measured** (the ink, at two device pixels a CSS pixel, `icons_align_check.js` over the search results, the Info
  panel, a list's window, the account's tabs and the profile, English and Polish, desktop and phone): from a framed
  neighbour to the star's ink within 0.3px of the row's gap between two framed buttons in every mode — Bootstrap
  −0.26 to +0.08, Free 6 +0.04 to +0.30, Free 7 and Pro 7.3.1 (solid, light) −0.03 to +0.16, Pro 7 Jelly −0.02 to
  +0.08 — where it stood 4.3–5.1px off; its glyph up/down within 0.74px of its neighbours' middle. The columns that
  hold it take the pull off (0.3rem, between the libraries' 4.4 and 5px): the search results' 140px with the star
  (145 before), the likes and descriptions tables' 100px for Magnet, Info and the star (74 before — the name column
  gives back 27px), a row's actions 84px (111px in your own list's window, with its ✕).

### Tests — the favourites star

* `tests/favourites_test.php` §13 (20 checks, 128 in all): favourites, uploads, a list, the likes and the descriptions, each
  answered as a request — `fav` the reader's own, none for a reader without `favourites.use` or `index.view`, none on
  a row whose hash is withheld, none on your own favourites; `favMarkRows()` asks `favMarkFor()` once and each endpoint
  asks it once. `tests/icons_test.php` §9 pins the star's place and the observer's measure.
* `icons_align_check.js` holds the star's ink against its framed neighbours (±1px) in every row and mode (a new judge;
  a mode that measures no star fails), with a list's window and a like of smokeuser's among its states; `fav_check`,
  `lists_check`, `descriptions_check` and `profile_votes_check` press the new stars both ways as another member and as
  the owner (the reader's state, the database, the Info panel's head following), `csrf_everywhere_check` presses one on
  the Likes tab with the page's token. `star_shots.js` photographs every place before and after
  (`scratchpad/shots1721/star-*.png`).
* Run for this part (targeted, the owner's "no full battery"): fav_check "0 failed" (60), lists_check (109),
  descriptions_check (56), profile_votes_check (126), csrf_everywhere_check (45; one run's `ERR_NO_BUFFER_SPACE`
  re-run alone), icons_align_check limited to the search, the account and the own profile in Bootstrap, Free 6 and 7
  and Pro 7.3.1 solid, light and Jelly (421); PHP favourites 128/0, lists 177/0, profile_votes 150/0, content 199/0,
  csrf_token 29/0, sql_safety 8/0, lang 226/0, icons 137/0.

### Fixed — on a phone, the search results' "Last seen" ran under the Magnet

* Seen in this release's own phone screenshots, and older than them: at 480px and below the column is 7.5em, a date
  with its time ("30.09.2026, 13:56") is wider, and `.search-num` kept it on one line, so the time ran on under the
  Magnet beside it. The cell (`search-seen`) may break there now where the locale puts a space — between the date
  and the time — and takes two lines; a desktop is unchanged.

### Fixed — the security card stood 7.6px lower when the account Overview's two columns came out even

* 1.72.0 put the card under the Overview's columns 1.25rem below the lowest card, and measured it so — with the
  columns uneven. When they come out nearly equal (Polish with ratings on: 1 379 / 1 378px), the browser's column
  balancing leaves a strip of empty column under both, and the card stood 27.6px below instead of 20. CSS cannot
  shorten a column box, so `trimAccountFlow()` (assets/js/app.js) takes the strip back as the flow's negative bottom
  margin, measured again whenever the flow or one of its cards changes size (a tab shown, a picture loaded, the
  language swapped, the window resized); one column has no strip and gets nothing. `account_width_check` holds 20px
  in every language, with ratings on and off, at every width.

### Tests — the phone, and what the release touched last

* The phone lanes of `icons_align_check` (`ALIGN_WIDTHS=phone`, English and Polish, every icon mode — Bootstrap,
  Font Awesome Free 6 and 7, Pro 6.7.2 and 7.3.1 in their styles): 0 failed, 783 checks, the worst offset under 0.9px
  in every mode and the star's ink within 0.3px of the row's gap. `account_width_check` 0 failed (320 – 1 920px),
  `fav_check` and `langswap_check` 0 failed on the final scripts, `csrf_token_test` 29/0.

## [1.72.0] — 2026-09-30

The owner's next list, as one release. It opens with eight corrections to how things look, each from his own
screenshots: the icon buttons of 1.71.0 left behind the room the words they replaced had needed, so every
row and table that names a torrent — the search results, the favourites, uploads and list rows, the likes and
descriptions tables — gives that room back to the torrent's name (and the rows a longer piece of the hash),
its icons at its right edge; with a Font Awesome Pro package the Magnet is Pro's duotone magnet; the Info
panel's Copy buttons stand on the line of what they copy, at its right; the comments move to the end of the
Info panel and open folded, and Settings says where they stand and whether they open; and the account page's
security card no longer touches the cards above it (1.25rem, on every width). Schema 86: the two comment
settings.

Then lists. On somebody else's profile a public list stood out — a green edge and the word "public" — which told
a visitor nothing (a visitor only ever sees the lists they may see) and made the page a traffic light. A list's
state is its owner's business now: the owner's own cards say it, with a lock, people or a globe, and nobody else's
page says anything about it. And a list has a third answer beside private and public — **friends**: its owner and
the members they are friends with see it, on the profile, through its link, in its window and in a torrent's "Who
has this", and nobody else learns that it exists; an unfriending or a block takes it away on the very next request.
The Edit window asks it as its third question. Schema 87: the boolean `is_public` becomes `visibility`.

Then replies. The owner asked for a permission to reply to comments and a setting for how deep the tree of replies
may go — "Reddit-like, three rows". A comment can be answered in place now, and the answers answered, each a step
further in, as deep as **Reply depth** says (three levels of replies as shipped; 0 is no replies at all, the flat
thread of 1.71.0); the server refuses anything deeper. A comment that goes while it is answered keeps its place as
"[deleted]", the one a reply answers is told of it (a switch of its own, and a sound of its own), and a page stays as
short as it was however long a thread grows. Schema 88: where a reply stands, the permission, the fifth switch.

Then the groups. The owner: "prepare a basic default set of permissions, and set it on my server too — there are new
ones, and the admin still does not have everything". Every seeded group has one **recommended set** now — guest, member,
premium, moderator and admin — shown on the Users page beside the group (what is missing, what a reset would take away)
and applied from there or from the shell (`tools/groups.php`): "Add what is missing" never removes anything; "Reset to
recommended" asks a second time. The Admin group's matrix stops showing empty boxes for permissions its members hold by
the blanket anyway: a migration writes every capability into its stored list (schema 89), and says so for every one
registered after it. What others may see of an administrator — the five consent permissions — stays a choice somebody
makes, and on the owner's server it is made by one command with `--consent`.

Then the traffic cards. The owner: "look at my server's traffic, something odd is happening again". Measured on the
server, read-only: nothing broke — the cards said it wrong. The full scrape grew to 1.95 M torrents and one poll no longer
walks it inside the time budget, so since 2026-09-28 every other poll is cut at the budget and the next one finishes the
rest: together they are the whole scrape, and not one download arrived short. The card judged each poll alone — "worst
poll 0.1 %" was the 1 453-entry tail of a pass that had walked everything, "21 arrived truncated" were those cuts.
Coverage is counted per **pass** now (a poll that starts at the beginning and the polls that continue it), a cut is
called a cut, the budget goes to 300 s with an estimate under it of what it means for this scrape, and the inbound
limiter says who loaded it in words, when its burst is too small for its limit, and how many handshakes each announce
costs — the repeats a dropped packet causes, which is why "arriving fell" meant fewer repeats, not fewer users.

### Changed — the room the icon buttons left behind goes to the torrent's name

* **The owner's screenshots**: the search results' actions stood in a column with empty space at the table's
  right edge, and so did the likes and the descriptions tables' on the account page and a profile; and the
  favourites rows (Favourites, Uploads, a list's window, on the profile and the account page) kept the width
  their Magnet and Info had needed as words. 1.71.0 made those words icons and left the columns as they were.
* **Every action column is exactly its icons now**, the icons at its right edge — the icons' 26px, the gaps
  between them and the cell's own padding, written as that sum (`calc()`), so a column cannot drift from its
  buttons again: the search results 145px with the star (156 before, 11px empty right of it) and 113px for a
  reader without favourites (43px empty before), the `<col>` told which (`search-c-actions-fav`, from the
  question the rows ask before they draw a star); the likes and the descriptions tables 74px where they were
  152 — 78px empty right of Magnet and Info — which the name column has now (216px at 1280, where names broke
  onto a second line, 294 now). On a phone the search's table scrolls and has the same column, the four icons
  on one line at every width (the phone rule that let them wrap is gone, with the three rules that sized the
  old word buttons and no longer applied to anything: `.search-act-btn`'s and `.pf-info`'s minimum widths,
  `.list-remove`'s).
* **A torrent row's actions** (`.pf-acts`) are as wide as their three icons (89px; 200 before, 170 in a list's
  window) — still a minimum, so a row without one of them (a banned torrent has no Magnet) keeps its columns
  under the other rows'. The name has most of the room — and the account's favourites stay one line a row down
  to an 805px window (895 before; measured) — and the hash chip a little of it: sixteen characters of the hash
  from 600px wide, twelve on a phone, where the facts have a line of their own and sixteen would not fit it.
* **Uploads**: the rows' switch said "Shown on your profile" or "Not on your profile", and the two made two
  rows of one list 15px apart (54px in Polish, "Pokazywane na twoim profilu") — every column after it with
  them. Its button holds both words now, one showing, so every upload row is one width; the uploads keep
  twelve characters of the hash (their words gave nothing back, the name would have paid for four more).

### Changed — Font Awesome Pro draws the Magnet in duotone

* With a Pro package whose duotone style is loaded, the Magnet buttons — the search results, the favourites,
  uploads and list rows, the likes and the descriptions tables, and the panel's Whitelist and Index — are
  Pro's duotone magnet, the owner's `fad fa-magnet` (`fa-duotone fa-solid fa-magnet`). Through the map, not the
  markup: an entry may name its own Pro style (`prostyle`, `includes/icons.php`), a chain tried in order — the
  duotone solid weight first, then the family's lighter files — and the first that loads and has the glyph
  draws it; with no duotone file loaded (Free, or a package where it was not ticked) the role decides, as
  before: Pro's outline, Free's solid. Only the magnet has one.
* **Its middle.** A duotone glyph is drawn twice, and the observer (`assets/js/icons.js`) measured only the
  first layer to put the glyph in the middle of its button: the duotone magnet's body without its caps, which
  stood it 2.2px high in every button (measured). A canvas cannot draw the second layer (Font Awesome 7 asks for
  it with a font feature), so a duotone glyph — of the duotone and the sharp duotone families, which split the
  classic drawings — is measured as what its layers split between them: the same name in the classic family at
  the same weight, 0.07–0.82em for 6's magnet, which is its two layers' own extent to the hundredth (6's second
  layer can be drawn, through its ligature). Now within a pixel of its button's middle, like every icon button
  (−0.33 to +0.65px, Pro 6 and 7, desktop and phone), and 26px square like the others.

### Changed — the Info panel's Copy buttons on the lines of what they copy

* **Info hash** and **Magnet link**: each Copy stands on its label's line, at the right (`.info-kv-head` — the
  owner's markup, `display: flex; justify-content: space-between`, as a class), the value under both, in both
  languages and on a phone. The hash's Copy stood after its forty characters, the magnet's under its three
  lines, where it was the last thing found. On plain HTTP the box the Copy opens takes a line of its own under
  the label.

### Changed — comments at the end of the Info panel, folded; Settings says where, and how they open

* **The owner**: with many comments the thread pushed the torrent's own facts down. The **Comments** section
  stands at the panel's very end now — after the files — and opens **folded**: its heading and its count,
  and a click opens it and asks for the thread. Two settings in Settings → Descriptions, comments & ratings →
  Comments (schema 86): **Where the comments stand** (`comments_position`: after the files — the default —,
  before them, or after the rating, where 1.71.0 put it) and **Comments when the panel opens**
  (`comments_expanded`: folded — the default — or unfolded). 1.71.0's section opened unfolded; the first setting's third answer and
  the second's "Unfolded" are exactly 1.71.0's panel. After the files, a torrent's comments are below its file
  list, which folds away by its own heading; "Before the files" keeps them above it. A notification's **Show** opens the section wherever it
  stands, and a panel drawn again for the same torrent — the live language switch — keeps it open if the reader
  had opened it.
* **How a thread loads** (the owner asked): the Info panel's own answer carries only the count; the thread is
  asked for when the section is open — a click, or at once when it opens unfolded. It comes a page at a time,
  `comments_per_page` to a page (20 as shipped, 5–100), the NEWEST page first, read oldest to newest, with
  **Show earlier comments** above it — a button, not a scroll that loads by itself — for the page before; a new
  comment lands at the end, beside the composer. A notification's link loads up to ten earlier pages to find its
  comment. Measured with 25 comments: folded, no request even in view; the click, one — the newest 20; the
  button, one more — the other 5; unfolded, the first request at once, the section still out of view.

### Fixed — the account page's security card touched the cards above it

* The owner's rule: the security card has `margin: 1.25rem 0 1.25rem` (it was `0 0 1.25rem`). Measured, it
  touched the columns above it on a desktop (0px — the columns drop their last card's margin at a column's end)
  and stood 16px below them on a phone (one column keeps it); the columns' last card now has none, so the card
  stands 1.25rem below them on every width.

### Changed — a list's state is its owner's business

* **The owner**: on somebody else's profile the public lists were tinted green and carried the word "public" —
  which belongs on your own lists only, when you look at them yourself. A visitor only ever gets the lists they
  may see, so the badge said nothing to them; between a friend's view and a stranger's it would even say which
  lists the owner shares with whom. So **nobody else's page says anything about who sees a list**: no tint (the
  green edge is gone from the stylesheet), no badge, no word — on a profile, in a list's window, in "Who has this",
  behind a share link. The endpoints leave the state out of every answer but the owner's (`visibility`, below).
* **The owner** sees each list's state on their own cards — the account page's Lists tab and their own profile —
  and in a list's window: **a lock "Private", people "Friends", a globe "Public"** (`bi-lock`, `bi-people`,
  `bi-globe2`, through the site's icon map: Font Awesome's and Pro's twins where the site draws with them; laid out
  as a button's icon and words are, one gap, level with the words). On the card it is a button — named "Who can see
  it: …" — that opens the Edit window on that question. The card's Public / Private switch is gone: the choice lives
  in the Edit window now, with the rest of what a list is.
* **Share** stands on the owner's public and friends lists (their address opens for whoever may see them), never on
  a private one — and, on somebody else's shelf, on every list the reader sees: a button on the public ones only
  would have told a friend which lists are theirs alone.
* The panel shows no member's lists anywhere (its Users view carries their groups, their picture and their
  description), so there was nothing there to change; a panel session reading the site is the account it is signed
  in with — every `userCan()` says yes to it, and none of that is a friendship.

### Added — lists for friends only

* **A third answer**, beside private and public: **friends** — the list is seen by its owner and by the members
  they are friends with (an accepted friendship, either way round, the friends feature's own), and by nobody else.
* **What it needs** (decided from the code, in one place — `includes/lists.php`, asked by the Edit window, the save
  and every reader alike, so a choice offered is a choice honoured): no new permission — a friend is somebody the
  owner chose, and `lists.public` is consent to be seen by *strangers*, which a friends list is not. It needs lists
  and the site's **Public lists** switch (`lists_public_enabled` — "may a list be shared at all": off, every list is
  private, a friends list too, as its hint always said), **Friends and following** on (`friends_enabled`), and the
  owner's account holding **`friends.use`** — a feature, not consent, so asked as the friends page asks it (the
  administrator's blanket counts, of the named account, never of whoever holds the session). And, like a public
  list, the owner's **Show my lists on my profile** (that switch now says it covers friends too). Losing one of them
  later makes the list private in effect, the choice remembered — the grant's rule. The panel's list of permissions
  says it: `friends.use` — "Follow other members, accept friend requests and share lists with friends".
* **Who is a friend**: the accepted friendship, and **no block between the two in either direction** — blocking
  somebody ends the friendship already, and a friendship row that outlived a block (a restored backup, a request
  answered in the same moment) is still none. The reader's own `friends.use` is not asked (the messages' "friends
  only" is the friendship alone). Nothing is cached: every read asks the friendship table, so an **unfriending or a
  block takes the list away on the very next request** — the page, the list's rows, "Who has this".
* **Every path**: the profile's Lists section (there for a friend even when the owner shares nothing publicly,
  and for everybody else exactly as before), the share link (`?action=u&name=…#list:<slug>` — for anybody else it
  does exactly what a private list's link does: nothing, the same page, and the list's rows by id are the very same
  404), the list endpoints, and **"Who has this"**: a friends list counts in the Lists section — its rows and its
  count — for a friend of its owner only (never for the owner themselves, as their private lists never did). No
  e-mail and no notification speaks of lists, and no `api/v1` endpoint serves them.
* **The Edit window's third question, "Who can see it"**: three buttons that behave as radio buttons — a lock
  Private, people Friends, a globe Public, an icon and a word each, in both languages ("Prywatna", "Dla znajomych",
  "Publiczna") — a click or an arrow key chooses, one Tab stop; under them what the chosen answer means. An answer
  the owner may not give is drawn disabled with the line that says why (the site keeps every list private; the
  friends feature is off; no `friends.use`; no `lists.public`); a list that already has it keeps it until its owner
  chooses another, and is not judged again when only its name or description changes. With "Show my lists on my
  profile" off, a shared answer says that nobody else sees any of the owner's lists. Saved with the name and the
  description in the window's one request — and a Save that changes only who sees the list is not writing: the
  anti-spam layer is asked only when the name or the description changes (showing and hiding a list never was).
* **The account's privacy card**: without `lists.public` in their groups, "Show my lists on my profile" said that
  nothing acts on it — true until now; for a member who may share with friends it now says what it does: it shows the
  lists shared with friends to their friends, and no list of theirs is public. Its hint names the three answers.
* **The endpoints**: `user_lists` `op: 'visibility'` takes `private` | `friends` | `public` (a 1.44.0 page's 1 / 0
  still mean public / private; anything else is 400 `bad_visibility`, an answer the owner may not give 403
  `no_permission`); `op: 'edit'` takes `visibility` beside the words; a new list answers `visibility: 'private'`
  (`is_public` is gone from every answer). The owner's shelf carries each list's `visibility`, `may_friends` beside
  `may_publish`, and `section_shown`; `user_privacy` answers `lists_may_friends` beside `lists_may_publish`.
* **Schema 87**: `user_lists.visibility` ENUM('private','friends','public') NOT NULL DEFAULT 'private' in place of
  the boolean `is_public`, and its key in place of `idx_list_public`. A public list stays public, every other one is
  private; `updated_at` held, so no list moves on anybody's shelf. Guarded on the old column's presence, never on a
  marker (`schemaListVisibilityMigration()`, the pattern of 1.70.0's description migration): the column arrives
  NULLABLE (NULL — "not converted yet" — reads as private everywhere, for the moment the migration takes), the rows
  still NULL take their old answer (only those: a choice made meanwhile is never taken back), then one ALTER finishes
  it. A run stopped anywhere does the rest again.
* **"Who has this", measured**: under the OR of its public and friends arms the planner stopped folding the group
  check into the join and began from EVERY list (a scan of `user_lists`, tests/who_test.php §8), and one subquery
  asking the friendship both ways round could use no key. The friendship and the blocks are now one keyed lookup per
  direction (`uq_friend_once` / `idx_friend_of`, `uq_block_once` / `idx_block_target`), and the section's FROM is
  written as the hash's own items, then the list, then its owner (`STRAIGHT_JOIN`) — the driving set the file
  always promised.

### Added — replies to comments, as a tree with a depth the operator sets

* **The owner**: "a permission to reply to comments … and a setting for how deep the tree of replies may be —
  Reddit-like, e.g. three rows". Both are here.
* **Who may reply**: `comment.reply`, "Reply to a comment", granted once by the migration (v88_replies) to the member
  and the moderator groups; the guest group nothing — an operator who grants it there (with `comment.view` and
  `comment.post`) gets a guest's reply held to every guest rule: "Guest #tag", a CAPTCHA every time, no link, held
  for a moderator until let through. The administrators' blanket passes it as every check; with accounts off it is
  no, as every comment id. Replying needs everything writing a comment needs first — comments on, the torrent there
  for this reader, not silenced, the anti-spam layer (the same one call, the same `comment` ladder).
* **How deep — Reply depth** (`comments_reply_depth`, Settings → Descriptions, comments & ratings → Comments; 0–8):
  the deepest REPLY level. 1 is a reply to a comment, 2 a reply to that too, **3 as shipped** — a comment, a reply, a
  reply to it and a reply to THAT: three rows of the reply tree, the owner's words (a count that took the comment for
  its first row would make 0 and 1 the same — both no replies —, where this way every number means something of its
  own). 0 is no replies at all. **The server
  refuses** a reply past it (409 `too_deep`, saying the limit) — the Reply button's absence at the limit is a courtesy,
  not the gate — and the line under the field says what happens there: the deepest replies have no Reply button, and
  the conversation goes on under the comment above them.
* **Lowered later**, the setting refuses only NEW replies: the ones written deeper stay, and are drawn at the deepest
  level allowed — after what they answer, each saying "in reply to @name" (or "…to a comment that is gone") — the way
  Reddit's "continue this thread" flattens a long branch, without leaving the page; nothing past the limit can be
  answered. At 0 the whole thread is drawn flat under its comment, every reply saying whom it answers, and nothing
  has Reply. Raised again, the tree is drawn as it was written.
* **Where a reply stands** (schema 88): `hash_comments.parent_id` (the comment it answers; NULL at the top level),
  `root_id` (the thread's top-level comment) and `depth` (0 for the top level, a reply its parent's + 1), and one
  key, `idx_hc_thread` (info_hash, root_id, id, status), that answers both of a page's questions — its top-level
  comments in order, and one thread's replies in order — each one keyed range, the status and the counts read from
  the key itself (EXPLAIN: `ref` / `range`, "Using index", no sort). A thread by its top-level id rather than by
  parent: MySQL 5.7 has no recursive query, and one keyed query per thread beats one per level. The comments written
  before 1.72.0 are what the empty columns say of a row: top level. Nothing was rewritten.
* **A page stays bounded**, however long a thread is: the top-level comments are paged exactly as before
  (`comments_per_page`, the newest page first, "Show earlier comments"); each brings its **first ten replies**, oldest
  first across all its levels — a reply is always younger than what it answers, so any such prefix is a whole tree —
  and **"Show N more replies"** at the thread's end brings 25 more at a time. A page is at most `comments_per_page` ×
  11 comments (220 as shipped), and a thread of any size costs it one index-only count, one query for all the small
  threads together and two per long one (its first ten, and a count of what is left). Inside a thread: a comment, then
  its replies oldest first, each followed by its own. A notification's link into a long thread brings that thread as
  far as its reply (at most 200 in one go). **The count** in the section's heading is every visible comment, replies
  included.
* **In the Info panel**: every comment the reader may answer has a **Reply** icon button, first among its actions
  (the map's `bi-reply` — Font Awesome's `reply` where the site draws with it —, "Reply" for a screen reader, "Reply to
  this comment" in the site's tooltip); pressed, the composer opens **under the comment** — "Replying to @name" and an x
  to cancel, over the very editor of the foot's composer: its toolbar, the picker for the comment context, the
  counter, the @ list, the anti-spam layer's countdown on its button, a guest's CAPTCHA and a guest's line. One is
  open at a time; putting it away (the x, or Esc) keeps its words for when Reply is pressed again, and a live language
  switch keeps it open with them. Sent, the reply lands in its place, lit for a moment. Each level stands a step
  further in with a thin rule down its left edge (22px a level on a desktop, 13 on a phone, so the third level keeps
  248px of a 360px screen's words — measured); each thread has one fold, **Hide replies** / **Show N replies**,
  between the comment and its replies. Every comment, container and composer has an id of its own (`comment-N`,
  `cm-kids-N`, `cm-thread-N`, `cm-rp-N`…), which is what the live language switch and the place-keeper pair by.
* **Esc, one layer at a time**: the @ list, then the composer under a comment, then the Info panel. An Info panel may
  hold layers of its own now (`escLayer()`, `assets/js/app.js`: an element marked `data-esc-layer` inside the window
  is closed first; a window opened over the panel still closes before either) — and a comment's correction in place
  is one too: Esc there closed the whole panel until now.
* **A comment that goes while it is answered keeps its place**: taken back by its author — or its author's account
  deleted — it is **"[deleted]"**; taken down by a moderator, **"[removed by a moderator]"**. No author, no words, no
  time, nothing to do: its place in the tree, so the replies under it keep their sense. It stays for as long as
  something under it is shown to that reader (a held guest reply counts for a moderator), and goes with the last
  reply under it; without replies a comment goes exactly as before. It is not counted, and cannot be answered. (What
  tells the two apart is the reason a moderator must give: an author's own deletion now keeps none, even one an API
  caller sent.) A guest's comment held for a moderator cannot be answered until it is let through.
* **An account deleted** (`userDeleteCascade()`): its comments go with it as before — except where OTHER people
  replied under one, which stays as a "[deleted]" tombstone, its words, author, guest tag, address group and edit
  stamps gone. Their own replies under it go too, unless somebody else's hangs below those.
* **Who is told**: the author of the comment a reply answers — "@name replied to your comment on …", the reply's
  words, **Show** landing on the reply in the Info panel, lit. It is the strongest reason there is: a reply that also
  names its parent's author is one notification, the reply's; and like a mention it is always news (the "one per
  unread thread" rule is for the thread's other readers). Never the replier themselves, never across a block either
  way — a reply from somebody a member blocked, or who blocked them, tells that member nothing at all — and a guest's
  reply only once it is let through. A fifth switch on the account page, **"…that replies to one of my comments"**
  (`users.comment_notify` bit 16), on for every account: the migration handed it out once, guarded on the column's
  own default (15 → 31), never on a marker.
* **A sound of its own**, `comment_reply` ("Somebody replies to my comment"; the site default **none**, the project's
  rule — Settings → Sounds has its select, the account's Sounds tab its choice). Decided by how the two work: the
  pulse counts unread notifications per TYPE (`unread_comment_reply`, one more sum in the same query) and
  `assets/js/sounds.js` plays each kind whose count rose, taking the specific kinds out of the general one — so a
  reply is one sound, its own; `soundEventKinds()` is the list the Sounds tab, the resolver and Settings all read, so a
  new kind appears in all three. Folding replies into the mention's slot would have counted two notification types
  under one number: a member who chose a sound — or silence — for being named would have got it for every answer
  too, with no way to tell the two apart or part them.
* **Reports** (1.71.0) work on replies unchanged — a reply is a comment: the flag, the Reports page, removing it.
* **The endpoints**: `comment_post` takes `parent` (403 `replies_off` / `no_reply`, 404 / 409 `parent_gone`, 409
  `parent_pending`, 409 `too_deep`); `comment_list` answers each top-level comment with its `thread` {rows, last,
  more} and the site's `reply_depth`, takes `find` (a notification's link) and answers `thread=R&after=ID` with a
  thread's next replies; every row carries `parent`, `root`, `depth`, `can_reply` and `tomb`; `comment_delete`
  answers `tomb`; `comment_prefs` takes `reply`; the pulse and `user_me` carry `unread_comment_reply`.

### Fixed — a comment's emoji picker asked for the room's emotes

* The Info panel's comment composer opened its picker as the SHOUTBOX's: the picker's script did not list the
  comment context the server has had since 1.71.0 (`EMOJI_PICKER_CONTEXTS`), so it asked for the room's emotes —
  behind `shout.view`, the room's own permission — instead of the comment's. It asks as a comment now
  (`for=comment`, the same for a reply's composer).

### Added — a recommended permission set for every seeded group, and one way to apply it

* **One definition, beside the presets** (`includes/users.php`): `userGroupRecommended(slug)` is the group's PRESET for
  guest, member, premium and moderator — the editor's "Start from" and the panel's "Recommended" can never say two
  things — and for admin **every registered id**, computed from the registry, never typed out. A group an operator made
  has none. Every set is contained in what a new install ships, so "add" changes nothing on one.
* **What each is**: *guest* — the public statistics (`stats.view`, `stats.timeline`, `home.stats`) and nothing else: the
  whitelist page and the search are the operator's catalogue to open (a new install's guest has the whitelist page, the
  owner's server does not — "add" must not open it again behind his back), the descriptions and the comments are the
  members' words (`content.view`, `comment.view`: opened to passers-by on purpose, if at all, v58 and v83), and anything
  that WRITES — `rating.vote`, `content.submit`, `content.propose`, the v24 grants that kept 1.18's behaviour on upgrade
  — needs an account (the anti-spam layer puts a guest behind a CAPTCHA every time anyway, and `rating.vote` does nothing
  for guests while `rep_who_can_vote` is "users"). A guest group that has them keeps them: only a reset takes them.
  *member* — its 40 (1.71.0's matrix and v88's `comment.reply`); *premium* — the two paid extras; *moderator* — the 42
  every install's moderator holds (below); *admin* — all 75.
* **Consent and capabilities**, one list: `userConsentPermissions()` — `content.public`, `favourites.public`,
  `lists.public`, `rating.public`, `uploads.public`, the ids that say what OTHERS may see of an account and that the code
  reads through the consent check, where the Admin group's blanket does not count (`userIdHasGrantedPermission()`,
  includes/favourites.php says why). Every other registered id is a capability (`userCapabilityPermissions()`).
  `profile.cover` is read from the stored grants too ("which groups give a cover") and is a paid extra, not consent.
* **Users → Groups**: a **Recommended** button (a magic wand) on every seeded group's row. Its window shows exactly the
  server's plan — the set's name and line in the reader's language, **"Add what is missing (N)"**, **"A reset would also
  remove (M)"**, each id with its description on hover — and two ways to apply it: *Add what is missing* (never
  removes), and *Reset to recommended* (only when something would go; it asks a second time, naming the ids and how many
  members lose them). What was shown is sent back and has to still be true: a group changed since the window opened is
  not touched — the window says so and shows the difference as it is now (409, nothing written). One audit line per
  change, `group.recommend` under Users (what was added and removed); none when nothing changed; a refused request is
  recorded as refused, as every panel write is. Owner-only, as group editing is (`admin/group_recommended` is absent from
  the endpoint map on purpose).
* **The Admin group, shown as it is**: in the matrix every capability of the Admin column is the blanket's tick (a colour
  of its own, "held by the blanket" on hover), consent rows carry an amber *consent* tag, and a legend under the matrix
  says what the three marks mean; in the group editor its 70 capability boxes are ticked and disabled (the reason on
  hover), the five consent boxes are live, and one line above the list says which is which — a preset never touches a
  disabled box, and a save that arrives without a capability keeps it on the server (`api/admin/group_save.php`). The
  groups table's cell for it reads "every capability (the blanket) · consent: …" instead of seventy ids. In the
  Recommended window the Admin group's consent is a tick of its own, off, with its reason; ticking it asks the plan again.
* **The groups table fits its page**: it borrowed the panel's shared 1180px floor — seven equal columns in a 1062px page
  at 1280, 1114 at 1440 — so its actions stood behind a sideways scroll (Edit included, at 1280), and the new button
  beside Edit went out of view altogether. Pinned columns now (`.gr-c-*`), the name and the permission list taking the
  rest: no sideways scroll at 1280, 1440 or 1920, every header whole in both languages ("CZŁONKOWIE", "PRIORYTET"), the
  row's buttons on one line in both icon libraries (measured).
* **From the shell** — `tools/groups.php` (the same functions, the same audit line, attributed to `cli:<user>`):
  `list` (every group, what it holds, and whether the Admin group stores every registered id), `diff [--group=slug]
  [--consent]`, `apply --group=slug|--all --mode=add|reset [--consent] [--dry-run]` — idempotent, prints exactly what it
  changed, forgets the permission memo — and `user <id|name>` (read-only: an account's groups, and each consent id it
  holds BY A GRANT). `--consent` also gives the Admin group the consent ids: the owner's server's case, "everything".
  Refuses to run anywhere but the command line; exit 0 done, 1 refused, 2 usage.

### Changed — the Admin group's stored list says what its blanket holds; the moderator's seed is every install's

* **Schema 89**: `schemaAdminGrant()` writes every registered CAPABILITY into the Admin group's permissions — on every
  data-migration pass (idempotent, a documented exception to "once": on the Admin group a capability is never a choice,
  so writing it again restores nothing anybody decided) and for every id a `schemaGrantOnce()` introduces — never a
  consent id, never a removal. An id a later release registers and grants to nobody (as `shout.emote_auto` and
  `panel.messages.*` are) reaches the stored list at the next migration too. Nothing anybody may do changes: its members
  pass every check by the blanket. On the owner's server it fills the fifteen empty boxes the matrix showed (the ids
  registered since v81, and v88's `comment.reply`).
* **The moderator**: its preset and its seed disagreed again — and this time the seed disagreed with itself. The seed's
  TEXT gained `index.files_all` at v42 and `favourites.use`, `favourites.public`, `favourites.view_others` and
  `uploads.public` at v47, but an `INSERT IGNORE` reaches only a NEW install, and those releases granted the five to
  member alone: no install that already had a moderator — the owner's included — ever got them. The recommended
  moderator is the one every install has (42), so the preset and the new-install seed lose the five. They are
  membership's — a moderator is a member as well, through the default group — and two of them are consent, which a job
  does not give. A group that has them keeps them ("add" never removes); a reset would take them.
* **Found on the way**: `tests/favourites_test.php` deleted the account it makes for the consent checks with a bare
  `DELETE FROM users`, leaving that account's ADMIN membership behind on every run — a row that would hand the Admin
  group to whichever account is given the id next. It deletes the whole account now (`userDeleteCascade()`).

### Changed — scrape coverage is counted per pass, and a poll the budget cut is called cut

* **What the server showed**: since 2026-09-28 13:54 the polls alternate — one cut at the budget (~126 s, e.g. 1 586 043
  entries walked), the next resuming at the cursor and finishing the rest in 10–40 s (82 062) — with `partial` NULL on
  every row. The card divided each poll's delivery by the tracker's count (`coverage = delivered / rows_total` per poll),
  so the resuming half read 4–20 % and the smallest tail 0.1 %, and it counted the cuts as "arrived truncated".
* **A pass** (`indexPollPasses()` in `includes/index.php`): a poll that starts at the first entry — `skip_from` 0, or a
  download that ended early, which is always read from 0 — plus the polls that continue it from the cursor, until one
  ends un-cut. A failed poll ends its pass (the cursor is reset); a fresh start while a pass was open leaves it
  "stopped"; a short download while a pass is open belongs to it (the cursor stays where the pass had got). A pass whose
  newest poll was cut is **in progress** — never a number in the worst or the average. A window's first poll that
  continues a pass begun before it reads that pass's earlier polls back (up to 200 rows, `lead` in the reply: drawn,
  never counted); a continuation whose start is gone is flagged and not counted. Per pass: polls, walked (the furthest
  entry reached), delivered, coverage against the last poll's tracker count, duration (first start → last end).
* **The card**: average and worst **per pass**, passes from how many polls, **Cut by the time budget** (muted — the
  budget doing its job — "the next poll continued where each one stopped"), **Download ended early** only when one did,
  failed polls, and the last pass ("1 421 325 so far — in progress, the next poll continues from entry 1 421 325").
  When every finished pass took more than one poll the card says so — nothing is lost, coverage is per pass — with the
  measured estimate: "with the budget at 120 s it takes 2 polls. At 300 s one poll would walk it."
* **The chart**: one bar per pass, its polls stacked in it bottom-up — from the start, continued, a download that ended
  early, a failed poll, each its colour — the darker part what each kept, the tracker's count as a dashed line, a pass
  in progress dimmed with what is left outlined. Every part says what it did on hover or tap: "continues the previous
  poll from entry 1 586 043 — together 100 %"; a tap keeps the words open, across the minute's reload too. Drawn as
  SVG, so the Index page no longer loads uPlot; readable on a 360px phone.
* **The words**: `all_truncated_alert`, `one_poll_truncated`, the tiles, the intro and their friends say *cut by the
  time budget — the next poll continues where this one stopped*; a real short download keeps its own words (*the
  download ended early*) — in the chart, the Index status card's badge and "Poll now"'s toast (`index_poll_now` now
  answers `partial` beside `truncated`). Polish says *przycięte* and *przebieg*, never *obcięte*.
* **An old rule, kept and corrected**: a cursor above the entries walked was read as a restart (1.29.1). That is what
  1.29.0's rows need — a short download recorded the stored cursor while it read from 0 — but a poll that resumed on a
  scrape which had SHRUNK below the cursor has the same shape and delivered nothing; it was credited with the whole
  file. `partial` tells them apart: every short download reads from 0, and nothing else restarts.
* **Found on the way**: the card's first load and a click on another range raced, and whichever answered last was drawn
  — the chart could show a day under the "6h" button and redraw under the reader's pointer. Only the newest request
  paints now, as the traffic chart's has since it met the same race.

### Changed — the poll's time budget goes to 300 s, and says what it means for this scrape

* **120 → 300**: `IDX_POLL_BUDGET_MIN` / `IDX_POLL_BUDGET_MAX` in `includes/index.php` are the clamp, the save's clamp
  and the field's `min=` / `max=` — one number, never retyped — and the search finds it by *300*, *cut*, *continues*,
  *pass*. The default stays 45; the download keeps its own `min(90, …)`. The poll runs in its own unit
  (`tracker-janitor-heavy`), so a longer budget holds up nothing else. "Poll now" waits as long as the budget allows, as
  before — with a budget above a few minutes a web server whose own timeout is shorter may answer the button with an
  error while the poll finishes behind it.
* **Under the field**, measured: "The last full pass walked 12 873 entries/s; the current scrape (1 789 254 torrents)
  needs about 139 s — with the budget at 120 s it takes 2 polls." — the newest complete pass's pace against the newest
  tracker count (`indexPollEstimate()`), the last clause redone as the field changes. On the owner's server (1.95 M
  torrents at ~12 800 a second): about 153 s — two polls at 120, one at 300. The hint says what a cut means.

### Added — the inbound limiter says who loaded it, when its burst is too small, and what the drops cost

* **Who loaded the limit, in words**: "set by lists-off 24 d 11 h ago" read like a limit somebody had named; it meant
  the janitor loaded the same limit again when the IP lists were switched off. The card now says "set 24 d 11 h ago,
  when the IP lists were switched off — the janitor loaded the same limit without them", in both languages, for every
  source the code passes (`netlimitSourceWords()`: admin, its counting-only and removal, auto, the emergency throttle
  and its end either way, the lists changing or switched off, a stability-probe step, a preview); a code without words
  prints as itself. The stability probe's Apply records `probe` now — it recorded `admin`.
* **A burst too small for the limit**: `limit rate over N/second burst B packets` is a bucket B packets deep, and the
  network card hands packets over in bursts — at 90 000 pps a bucket of 100 holds 1.1 ms, so bursts are dropped inside
  seconds that were under the limit. On the owner's server the limit of 90 000 passed ~80 000 on average while a third
  was dropped. When the limit is on and over the last hour what got through stayed under 95 % of it while more than 5 %
  was dropped, and the burst in force (the firewall's, else Settings') is below the suggestion, the card says so with
  the numbers and suggests ≈ 22 ms of the limit (`netlimitBurstSuggest()`: 2 000 at 90 000, rounded, within 1–65 535):
  "… would let the full limit through without raising it — Settings → Inbound limit → Burst, then Traffic → Apply
  limit", with a link to that section. The limit itself is not touched.
* **Handshakes per announce**, from the statistics timeline's hourly rows: UDP connects against announces over the last
  hour and over 24 h (`netlimitHandshakes()`), with one sentence — about one per announce is normal, well above one is
  clients repeating the handshake because their packets or the replies were dropped. On the owner's server ~1.5 in the
  efficient state and 2.2–2.8 in the congested one, at the same limit. Hidden while the timeline is off or stopped, and
  a window with a tracker restart inside it says nothing.
* **Found on the way**: `tests/netlimit_test.php`'s helper half isolates its own files in a temporary directory, but its
  state checks wrote the checkout's real `config/net_state.json` and nothing put it back — every run cleared the
  panel's last error and last clean answer. It keeps the file from the start and puts it back byte for byte.

### Production note — the inbound limiter's burst, 100 → 2 000 (the limit stays 90 000 pps)

Written down at the owner's request, so that if the server ever behaves oddly afterwards, this change is the first
place to look.

* **What the burst is**: the limiter (`limit rate over 90000/second burst N packets`) lets 90 000 packets a second
  through on average; the burst is how many of them may pass AT ONCE, out of one bunch the network card hands over.
  Packets arrive in bunches of a few hundred, so with a burst of 100 a bunch of 300 loses 200 inside a second that
  stayed under the limit. That is why production served about 80 000 of the 90 000 it allows while dropping a third —
  measured 2026-09-30 ~15:05 over 60 s: 120 771 packets arriving, 80 051 served, 40 720 dropped (33.7 %).
* **The change**: burst 100 → 2 000 packets (≈ 22 ms of the limit), recommended after the traffic analysis above and
  agreed by the owner on 2026-09-30. The limit itself does not move: no second can pass more than 90 000 on average.
  Expected: served close to 90 000, fewer drops, fewer repeated handshakes. Why it cannot overload the machine: 2 000
  packets is what the server receives every ~1/60 s anyway, the tracker's socket buffer holds tens of thousands, and
  on the day opentracker used ~27 % CPU per worker thread with a load of ~0.5 per core; the stability probe's safe level
  on this machine was 293 000 pps.
* **How it is applied** (the panel, with the owner's password): Settings → Inbound limit → Burst **2000** → Save, then
  Traffic → Apply limit. The audit log records it as `netlimit.apply`. The release itself changes nothing on the
  firewall: at release time production still ran burst 100.
* **How to undo it**: the same two steps with Burst **100**. It is in force at once — the helper swaps the one rule in
  place, and the counters keep running.

### Tests

* `tests/icons_test.php` — the magnet's entry and its `prostyle` chain (a well-formed key of an entry); on Pro
  setups built as `iconSetup()` returns them: duotone loaded → `fa-duotone fa-solid fa-magnet`, only a lighter
  duotone file → that one, none → Pro's outline, a light site → light, Free untouched; every other entry drawn
  exactly as without the duotone style, the magnet counted its own style's and never a fallback; the observer
  measuring a duotone glyph as the classic glyph at its weight. 134 checks.
* `tests/comments_test.php` — the two settings in their four places (default, the save's allow-list and its
  coercion, the search catalogue, the Settings page), their readers (`commentsPosition()` / `commentsExpanded()`:
  the closed set, the default for anything else), the Info panel's answer carrying them, the schema line for 86.
  148 checks.
* Browser checks: `comments_check.js` walks the thread as shipped — at the panel's end after the files, folded,
  no thread asked for until a click opens it — and Settings' five combinations of place and opening (folded asks
  for nothing, unfolded opens and loads), a notification's Show landing through a folded section, the live
  language switch keeping it open; `fav_check.js` — the search's actions column exactly its icons (no room right
  of the star or left of Magnet), a favourites row's actions exactly its three icons and its chip sixteen
  characters; `profile_votes_check.js` and `descriptions_check.js` — the actions cell exactly Magnet and Info at
  its right edge (both rating modes, both languages, Font Awesome; the first now switches the anti-spam layer off
  for its votes); `account_width_check.js` — the security card 1.25rem below the columns at 1280, 1440, 1920 and
  on five phone widths; `polish_check.js` — each Copy on its label's line at the row's right edge, desktop and
  phone; `settings_groups_check.js` — the two new settings found by their names in both languages;
  `icons_check.js` and `icons_align_check.js` — the Pro solid modes load the duotone style, and every page of
  them measures the duotone Magnet: `icons_check` 0 failed (1,024 passes in its seven modes, the Magnet duotone
  in exactly Pro 6 and Pro 7 and every icon button Bootstrap's size), `icons_align_check` 0 failed in all eight
  modes — worst per mode (offset / gap / icon button) bootstrap +0.87 / 0 / +0.59, cdn6 +0.78 / 0 / −0.84, cdn7
  +0.71 / 0 / −0.84, pro6 +0.71 / 0 / −0.84, pro6-light +0.70 / 0 / −0.84, pro7 +0.78 / 0 / −0.84, pro7-light
  +0.77 / 0 / −0.84, pro7-jelly +0.78 / 0 / −1.00, the same as 1.71.0's — and the 440 duotone Magnets measured
  −0.33 to +0.65px from their buttons' middle, 26px square (2.2px high before the observer measured both layers).
  `reports_check.js` and `antispam_check.js` open the folded section as a reader does.
* `tests/lists_test.php` — schema 87 on both paths (the fresh CREATE and the upgrade list's copy: the ENUM, its
  default, its key, no `is_public`; the live, upgraded table) and the upgrade walked on a table of 1.70.0's shape: the
  three statements in their order, a stop after each (every row NULL — read as private — after the first; a choice
  made meanwhile kept), public stays public and 0 or a stray 2 private, `updated_at` held, the result the same as a
  table today's CREATE makes, column for column and key for key; the rules as functions (the three answers and the
  1.44.0 booleans, what the friends side needs, the administrator's blanket — `friends.use` yes, `lists.public` no —,
  a friendship either way round, which answers each reader may see, the profile's section for a friend of an owner who
  shares nothing publicly); then every path as a request for the owner, a friend, a member who is no friend, a guest,
  a panel session alone and one over a member's account: the shelf (what a profile section and a share link's list
  are drawn from), a list's rows — each "no" the very same 404, a friends list never a hint —, "who has this", the
  state told to the owner alone; the writes (`visibility` three ways and the old 1 / 0, 400 for anything else, 403
  for an answer not the owner's to give with nothing written, the Edit window's one request, an unchanged answer not
  judged again, the anti-spam layer asked for a rename and not for a change of who sees it); an unfriending and a
  block through `user_people` itself, gone on the very next request, a friendship row that outlived a block still
  none, a pending request none; the pages' markup, the icons in the map, the words in both languages and the dead
  ones gone. 177 checks. `tests/who_test.php` §5c — a friends list in the Lists section: counted for the owner's
  friend only (26 for him — the 25 public ones and it —, 25 for anybody else), either way round, and out of the
  rows and the count for a pending request, the friends feature off, the owner's `friends.use` gone, the section
  hidden, a block either way, the owner themselves; an owner in the admin group alone counted (the blanket is
  power); the pages agreeing with the count; §8's EXPLAIN, which caught the planner reading every list, holds the
  new plan. 120 checks.
  `tests/audit_lists_test.php` (and `.py`, `favourites_test.php`, `avatar_names_test.py`) write the new column; the
  blanket's publish refused by its new name too.
* Browser checks: `lists_check.js` — a visitor's card says nothing of who sees it (no green edge — the border of any
  card —, no badge, no chip, no word); the owner's cards: a lock "Private", people "Friends", a globe "Public", each a
  button named "Who can see it: …", Share on the public and the friends one; the chip opens Edit on the question —
  a radiogroup, one Tab stop, arrows move the choice, its meaning under it —, Save one request carrying it, the chip
  and the list's window following; the friends answer disabled with its line while the friends feature is off, the
  hidden section's line (following the privacy card's switch on the same page, no reload); Polish (the chips and, in
  place, the window's words) and a phone; smokeuser's friend reading the friends list (the profile, its link, its
  rows, "who has this") and a member who is not seeing the public one only — the friends list's link doing for him
  exactly what a private one's does, its rows the very 404 —, and an unfriending taking it away on the next page.
  `who_check.js` §4b — a friends list counted for smokeuser as the owner's friend (25), not before and not after (24).
  `polish_check.js` publishes through the chip and the window; `icons_check.js` and `icons_align_check.js` open the
  Edit window from a chip on the Lists tab (three lists of smokeuser's in the three states for the run):
  `icons_check` 0 failed (1,044 passes in its seven modes; the chips and the window's three answers draw a real
  glyph in each full mode); `icons_align_check` over the account page and the
  own profile, in all eight modes, both languages, desktop and phone — which caught the chips at their first size,
  0.75rem, standing 1.03–1.07px under their words with Bootstrap Icons (the chip is 0.8rem now) — 0 failed: the chips
  +0.53..+0.57 (Bootstrap), +0.11..+0.16 (Free 6, Pro 6), +0.10..+0.74 (Free 7, Pro 7), the window's three answers
  +0.54..+0.58, +0.12..+0.18 and +0.09..+0.75. Screenshots: scratchpad/shots1720/lists-*.png.
* `tests/comments_test.php` §13, replies — schema 88 on both paths (the columns and the key in a fresh CREATE, and an
  upgrade from 87 walked on a scratch database: one shape, the old comments top-level, every account's fifth bit on
  — 15 → 31, 7 → 23 — and the default 31, a second run asking nothing), the grant once to member and moderator and
  not to guest, `comments_reply_depth` in its four places and clamped (0 a real answer); then the tree to the limit:
  a reply one level down, a reply to it, a third level, and the fourth refused by the server with nothing written;
  the page — the top-level comment with its replies in order, parent, thread and depth each, `can_reply` false at the
  limit only, the count every comment; who is told — the parent's author (`comment_reply`, the link to the reply),
  never the replier, one notification for a reply that also names them, nothing across a block either way, the fifth
  switch off falling back to the thread; the refusals in their order (another torrent's parent, none, not a number,
  replies off, no `comment.reply` with a comment of its own still allowed, silenced); a guest's reply — no grant, then
  a CAPTCHA every time, no link, held, not answerable while held, a moderator's thread showing it, the parent's author
  told only when it is let through; the depth lowered to 1 and to 0 (the replies stay on the page, none answerable,
  a new one too deep); tombstones — an author's own, a chain taken back to its last reply and gone with it, a top-level
  comment removed by a moderator in its place with its thread, an author's deletion keeping no reason; a thread of
  fifteen: ten on the page, "5 more", the next five, a notification's link bringing fourteen; an account leaving two
  tombstones under other people's replies and three comments deleted; a reply through the endpoint file. Its earlier
  pins follow the fifth switch, the third sound kind and the pulse's third number. 199 checks.
  `tests/content_reports_test.php` §11 — a reply is reported as a comment is, its row flagged for the reporter.
  `tests/groups_matrix_test.php` (the member and moderator rows, the moderator seed + grants, the upgrade's markers),
  `tests/sounds_test.php` (nine sound selects), `tests/sounds_test.py` and `tests/pulse_test.py` (the third comment
  kind; the pulse's `unread_comment_reply`) follow it.
* Browser checks: `comments_check.js` §13 — on a torrent of their own, ccheck_member's thread and smokeuser's Reply
  (first among the actions, the map's glyph, its tooltip on hover), the composer under the comment ("Replying to
  @ccheck_member", the same toolbar, the picker for the comment context, the counter, the x), Esc one layer at a time
  (the @ list, the composer — the focus back on Reply —, the panel), the x keeping the words; the reply landing nested,
  ccheck_member's next pulse playing the REPLY sound once and nothing else, the notification's Show opening the panel
  on the reply; nested to level 3 — each a step further in —, no Reply at the limit and a reply sent by hand refused
  (409); the fold; smokeuser's reply taken back staying "[deleted]" over the two under it; a 360px phone (level 3's
  words 248px wide, nothing sideways); the live switch to Polish redrawing the thread with the same ids and the open
  composer still open with its words; the depth lowered to 1 (the deeper replies at level 1, "in reply to …", only
  the comment answerable) and 0 (flat, nothing answerable); a long thread — ten replies and "Show 4 more replies" —
  where a reply sent before the four are fetched lands in its place and leaves them in reach (Show brings each once,
  in order); and Esc in a comment's correction putting it away with the panel still open. Its earlier pins follow
  Reply among the author's actions, the five switches and the Sounds tab's third event. 99 passes, 0 failed (and
  the long thread's check fails, as it should, with the "Show more" cursor moved past the replies still to come).
  Screenshots: scratchpad/shots1720/replies-*.png. `icons_align_check.js` and `icons_check.js` open a thread of
  replies to the limit in the Info panel (smokepeer's and smokeuser's in turn, made for the run) — the Reply buttons,
  Report, Edit, Delete, the fold — and then the composer under a comment with its x: `icons_align_check` over the
  search page and its Info panel in all eight modes, both languages, desktop and phone, 0 failed (257 passes) — which
  caught the fold's chevron 0.81px under its words with Bootstrap Icons at the size first chosen (0.78rem; at the
  small buttons' 0.8rem it is +0.57) —, the thread's icon buttons −0.69..+0.58px from their boxes' middle, its icons
  beside words −0.19..+0.57; worst per mode (offset / gap / icon button) bootstrap +0.57 / 0 / +0.59, cdn6 +0.63 / 0 /
  −0.84, cdn7 +0.63 / 0 / −0.84, pro6 +0.63 / 0 / −0.84, pro6-light +0.61 / 0 / −0.84, pro7 +0.63 / 0 / −0.84,
  pro7-light −0.25 / 0 / −0.84, pro7-jelly −0.29 / 0 / −1.00 (the +0.6 of the Free and solid modes is the file
  list's chevron, as before). `icons_check` 0 failed (1,113 passes in its seven modes; Reply drawn on the three
  comments below the limit, every icon a real glyph, the thread's icon buttons Bootstrap's size in every Font Awesome
  mode). `settings_groups_check.js` finds **Reply depth** by its name in both languages. The neighbours, on this
  code: `reports_check`, `layers_check`, `antispam_check`, `picker_everywhere_check`, `emoji_picker_check`,
  `sounds_check`, `pulse_check`, `langswap_check`, `people_check`, `csrf_everywhere_check`, `descriptions_check`,
  `content_check`, `polish_check`, `settings_hit_check` 0 failed; `verify_all` 52 renders, 0 problems;
  `tests/sounds_test.py` 45 checks and `tests/pulse_test.py` 14, 0 failed.
* `tests/groups_matrix_test.php` — the moderator row is the moderator every install has (its comment put right: the
  five were never "in it since v25"); a new install's Admin group stores every capability and no consent id; the
  owner's Admin group imitated (the 59 ids it held) gains every capability it lacked and not the consent it never gave,
  keeping the four it had; a capability missing from the Admin group's stored list comes back at the next pass (an id
  granted to nobody included), a consent id taken off it does not; a grant gives the Admin group its capability, never
  the consent id beside it, and still counts only the group it names; the Admin cover check takes `profile.cover` off
  the stored list for its moment. §8, the recommended sets: each registered, the Admin group's every id computed from
  the registry, the others their presets, none for a group the operator made, the guest's the public statistics, each
  contained in what a new install ships; each set's name and line in both languages, the English the preset's word for
  word; the consent list = the code's — every id read through `userIdHasGrantedPermission()`, and nothing else, the only
  other stored-grant read `profile.cover` in the account page, the who-has-this sections' map, the power read never
  asked a consent id — read by the tokeniser with the comments taken out (with `rating.public` taken out of the list,
  five checks fail, three of them this one); the plans on the rows the database holds (the owner's guest: nothing to add,
  a reset would take the legacy three; a pre-1.72.0 new install's moderator: a reset would take the five; the owner's
  Admin group: its capabilities to add, its missing consent apart); the audit line's shape; the code the release rests on
  by its shape. 103 checks. `tests/profile_bio_test.php` takes `profile.bio` off the Admin group's stored list for its
  check in the same way (114); `tests/favourites_test.php` §10 — the moderator's seed and its recommended set hold none
  of the favourites / uploads ids (108); `tests/admin_access_test.php` — `admin/group_recommended` among the owner-only
  endpoints (312).
* `tests/groups_cli_test.php` (new) — the tool as the operator runs it, a process of its own, on the test database:
  `list`; the usage answers (2) and the refusals (1); a dry run that changes nothing and writes no line; `add` printing
  exactly what it added, never removing the operator's own id, one audit line — the panel's own detail, `cli:<user>`,
  ", from the shell" — and nothing the second time; `reset` taking exactly the extra, then nothing; the Admin group: the
  capabilities it lacks back, its consent never taken away and never given without `--consent`, then with it every
  registered id stored and `list` saying "yes"; `--all` = the five seeded groups; in the process itself, the permission
  memo forgotten by an apply (an integer key too), a stale preview refused with the plan as it is now and the current
  one accepted, a reset whose preview did not show its removal refused, the Admin group's reset changing nothing; the
  file's guard and what it loads; over the web the file answers 404 and says nothing. 42 checks.
* Browser checks: `groups_check.js` (new, in the sweep) — the Groups tab as the owner meets it: the Recommended button on
  the five seeded rows only; the Admin group's cell; a member group two ids short with one extra — its window, Add (the
  two, the extra kept, one line), Reset's second question (Cancel writes nothing), Reset (the extra only), "held
  exactly"; a stale preview (nothing written, the window redrawn, the refusal recorded as one); the Admin group's window
  (the blanket's line, its two missing capabilities, the consent tick asking again, Reset off); its editor (70 boxes
  ticked and disabled with the reason, five live consent boxes, the line, a preset leaving them, a forced-open save
  keeping the capability and the consent tick saved); the matrix (the blanket's cells, the tagged consent rows, the
  legend); Polish; both icon libraries — the table fitting its page at 1280 and 1440 in both languages (no sideways
  scroll, no header cut, the buttons in view on one line: it caught the table's borrowed 1180px floor) and the row
  button's glyph where its neighbour Edit's is (0.00px); a 390px phone; no script error; every group put back byte for
  byte. 55 passes, 0 failed. Screenshots: scratchpad/shots1720/groups-*.png. `icons_align_check.js` measures the Groups
  tab with its matrix open and the Recommended window filled (the Admin group's and a member group's): over the Users
  page in all eight modes, both languages, desktop and phone, 0 failed (167 passes) — the new states' icons −0.44..+0.42px
  from their words' middle (Bootstrap −0.14..+0.42, Free 6 and Pro 6 −0.44..+0.30, Free 7 and Pro 7 −0.33..+0.33), the
  page's worst per mode +0.85 (Bootstrap, the VIP row's hourglass), +0.78, +0.69, +0.69, +0.69, +0.78, +0.77, +0.78 —
  its existing icons, within the pixel. The neighbours, on this code: `panel_fixes_check` and `settings_groups_check`
  0 failed, `verify_all` 52 renders and 0 problems, `csrf_everywhere_check` 0 failed and `icons_check` 0 failed (1,113
  passes in its seven modes) — each run once more on its own, the first time having failed only on the machine's
  `net::ERR_NO_BUFFER_SPACE` (945 sockets waiting to close); and the PHP suites the groups touch —
  `install_test` 36 (a new install and an upgraded one, the same groups), `version_test` 24, `profile_votes_test` 150,
  `content_test` 199, `who_test` 120, `lists_test` 177, `audit_lists_test` 41 (the blanket still not consent),
  `people_test` 100, `lang_test` 226, `sql_safety_test` 8, `icons_test` 134, `comments_test` 199 — 0 failed.
* `tests/index_polls_test.php` (new) — one row read in every shape the table holds (a cut, a continuation, a short
  download, a failed poll, 1.29.0's stored cursor above and below the entries, a continuation on a scrape that shrank,
  an unknown count); passes on the server's own rows of 2026-09-28/29 (cut at 1 586 043 + 82 062 = one pass at 100 %,
  the 1 453-entry tail the end of a pass at 100 %, the duration first start → last end, the newest cut in progress and
  never counted), a short download starting a pass and in the middle of one, a failed poll ending one, a fresh start
  leaving one stopped, a continuation without its start, the old cursor rule inside an open pass; the reply on the
  database with rows at a moment no real row is near (2033), deleted exactly — the window's first pass read back from
  before it, seven passes with the failed one the worst (54.88 %), cuts / short downloads / failed polls counted apart,
  the estimate from the newest complete pass, a continuation whose start is gone flagged and not counted, a window
  opening on a short download, the window bounded both ways; the clamp 5–300 (default 45, garbage, 0), the constants
  in the save, the field and the search words, the download's own `min(90, …)`; the estimate (12 866 entries/s → 153 s
  → 2 polls at 120, 1 at 300, 4 at 45; none without a complete pass); the words — cut / continues and never
  "truncated" in both languages, the short download's own, every key the three scripts ask for. 112 checks. With every
  poll made a pass of its own, 21 of them fail.
* `tests/netlimit_test.php` — the burst hint: the suggestion (2 000 at 90 000, rounded, within 1–65 535), the
  server's hour said with its numbers, the thresholds either side (94.9 % / 5.1 % said, 95 % through or 5 % dropped
  not), a burst already at the suggestion silent (with that guard taken out, two checks fail), counting-only samples
  not counted, the path in both languages built from the pages' own labels; the sources — every `netlimitApply()`,
  `netlimitApplyMonitor()` and `netlimitOff()` call in `api/`, `includes/` and `tools/` found by the tokeniser, each a
  literal with words in both languages, "lists-off" said as the lists switched off, an unknown code as itself;
  handshakes per announce (1.50 over the day and the hour, 2.5 congested, a restart or the uptime alone going back
  hiding the day and not the hour after it, too little history, no announces); over the database: the hint from the
  last hour of `net_samples` against the burst the firewall reports or Settings', the timeline's hourly rows at 2033
  (the hour and the day, off, stopped, a restart six hours back), the table exactly as found; and the state file
  exactly as the run found it. 356 checks (the one Windows skip as before). `tests/audit_fixes_test.php` — the
  coverage chart's pin follows it off uPlot (no instance, its window listeners bound once). 56 checks.
* Browser checks: `traffic_cards_check.js` (new, in the sweep) — fixture rows shaped like the server's in `index_polls`,
  `net_samples` and `stats_samples_1h` with their own times, and the Traffic card's firewall a stand-in
  (`scratchpad/shots/fake_netlimit.php`: 90 000 pps, burst 100, counters at the server's rate; `lists-off` 24 d 11 h
  ago in `net_state.json`): the coverage tiles per pass (93.6 % over seven, the worst the failed pass 54.9 %, eight
  passes from thirteen polls, five cuts muted, the short download apart, the last pass "1 421 325 so far"), nothing
  saying "truncated" or 0.1 %, one bar per pass with its polls stacked and touching, each part's words ("continues the
  previous poll from entry 1 586 043 — together 100 %", the tail's 100 %, the short download's, the failed poll's, the
  pass in progress dimmed and outlined), hover and click (kept open across a redraw — which caught the range race), the
  legend, the notes (and on rows where every pass took two polls: "… it takes 2 polls. At 300 s one poll would walk
  it."), Polish, a 360px phone; Settings' field 5–300 with the estimate redone at 300 and 45 and nothing saved;
  Traffic's "set 24 d 11 h ago, when the IP lists were switched off — …", the handshakes line, the burst hint with its
  numbers, path and link (landing on Inbound limit and its Burst field), a restart hiding the day, the timeline off and
  the limit off hiding theirs, Polish, a 360px phone; no script error; every row, setting and state file put back.
  61 passes, 0 failed. Screenshots: scratchpad/shots1720/traffic-*.png. The neighbours, on this code: `verify_all`
  52 renders and 0 problems; `lang_test` 226, `sql_safety_test` 8, `icons_test` 134, `tuner_test` 43, `iplist_test` 110,
  `sysctl_test` 116, `dbmem_test` 70, `cluster_test` 91, `version_test` 24, `meta_order_test` 100,
  `homelayout_test` 92, `tests/worker_settings_test.py` 41 — 0 failed. `tests/addressable_test.php` left its fixture's two
  `whitelist_files` rows behind on every run (it deleted the whitelist row, and the files table has no cascade): it
  deletes them first now and checks that nothing is left. 26 checks.

## [1.71.0] — 2026-09-29

The owner's next list, as one release. It opens with how a few places look and what their buttons say:
where a word stood only because the public pages had no icon font until 1.68.0, an icon now stands — the
Info panel's description actions, its head, its Copy buttons, the search results' Magnet, Copy and Info,
a conversation's Back and Clear, the home page's copy buttons — each named for a screen reader and
explained in the site's own tooltip, in every icon library the site can draw with; the panel's links take
a colour of the panel's own; the account page's Overview comes out in two columns of about one height,
each part under a heading that says what it is for, with the signed-in devices at the bottom; the links
under the sign-in form stop turning pink; and the CAPTCHA widget in "Please verify you are human" loses
the white edges 1.70.0's dark form controls gave it. No schema change in this part.

Then the emoji picker: the page of every Font Awesome icon was navigated by seventy written words on a
strip, and each is Font Awesome's own icon for its category now — the alphabet as A B C — its name in the
tooltip; and the picker's settings and the emote manager leave Shoutbox for a group of their own in
Settings, **Emoji & emotes**, since they serve every text on the site that has the picker. No schema
change either, and no setting changed its key.

And two things about voting. A vote from the Info panel opened on a profile or on the account page was
answered "Invalid CSRF token": the vote read a token only the search page carried — and so did the panel's
Refresh. The session's token is published once now, on every page, and every public script asks one helper
for it, so no button can turn up on a page that lacks it again. And the vote you already cast — the same
thumb, the same half star — pressed a second time, is taken back. No schema change here either.

Then **comments on a torrent** (schema 83), planned first, as the owner asked: in the Info panel, after the
rating, **Comments (N)** — a thread, and a composer with the emoji and the emotes, a comment's own few BBCode
tags and never a picture, a link only while the operator allows one, a length the operator sets; five
permissions; the people a comment is news to told, with two sounds of their own; and guests only where the
operator lets them in — behind a CAPTCHA every time, and a moderator.

Then **reports of what people write in public** (schema 84), planned first as well: a comment, a torrent's
description and a shout can be reported from where they stand — a flag beside them, a reason, "Reported" after
— and are worked on the Reports page in three tabs of their own, one card for each reported thing with every
report about it; and whatever a moderator does that reaches the author is the moderator's choice, **silent or
loud**: nothing said, or a **warning** in the author's own language, with the reason, kept on the account.

And last, **one anti-spam layer for everything people write** (schema 85), planned first too. A line in the
shoutbox and its correction, a message, a comment, a torrent's description, a list's name and description, the
profile's description, a report, an emote and a vote all ask one place before they are written, and each gets one
of three answers: go, wait this long — and Send counts it down on itself and comes back by itself —, or prove you
are a person — and the site's CAPTCHA box opens there and then, and the same words go again. The room has the
ladder the owner sketched: three lines free, then 5, 15, 30 and 60 seconds, and two quiet minutes forget it all;
every other place has its own, in Settings. Guests solve a CAPTCHA every time; a new account starts fewer
conversations and its links stay words for its first days; the same words twice are refused everywhere; and a
database that cannot answer is a refusal, never a pass.

### Changed — icons where words stood

* **The owner's list**: the Info panel's description actions; a conversation's Back and Clear; the search
  results' Magnet, Copy and Info; the home page's copy buttons, which were a drawing of their own that no
  icon library reached; the Info panel's hash Copy and Share — "and look for the other places where a word
  was used only because there was no icon font". The public pages had no icon font until 1.68.0, and
  these were the words standing in for one. Each is an **icon button** now (`.ic-btn`): one glyph of the
  library the site draws with — the markup is Bootstrap's `bi bi-*` as everywhere, which the map hands to
  Font Awesome Free or Pro — its name for a screen reader in `aria-label`, and what it does in the site's
  own tooltip (`data-tip`), shown by `pubTip()` while a mouse or a pen rests on the button (after a short
  pause, so a pointer crossing a row does not flash one tip per button) and while the keyboard's focus is
  on it, never on touch, gone when the button is pressed — whose own answer ("Copied!") takes its place.
  No `title` on them: the browser drew its own box a second later. The live language switch swaps
  `data-tip` as it swaps `title` and `aria-label`.
* **The glyphs.** The description actions: a pen for the first words (Add a description), a nib for a
  new version (Propose a rewrite), the pen on a square for Edit, the bin for Delete — whose first
  press turns it into the filled bin, in the error colour, with "Click again to delete" held in the
  tooltip and in its name until the second press or four seconds. The head: the star, the "+", the people
  for "Who has this" (its three words took the room the torrent's name needs: on a phone the name stood
  three letters to a line), the nodes of Share, the close. The rows: the magnet — still the primary one,
  the filled accent box — the copy squares and the "i", and the star; the same Magnet and Info on every
  torrent row the site draws (a profile's and the account's favourites, a list's window, the likes and the
  descriptions tables), and the list window's remove. A conversation: the arrow back, and the archive box
  for Clear (nothing is deleted; the next message brings the conversation back — the tooltip says so). The
  copy buttons: the announce and donation boxes on the home page, the whitelist page's announce box and
  its results' magnet, the Info panel's hash and magnet; a copy turns the glyph into the library's tick
  for a second and a half. The search box's magnifier and its clearing cross were drawings too, and are
  the library's glyphs now.
* **The map** (`includes/icons.php`) has the six new names: `copy` is Font Awesome's `clone` — Bootstrap's
  two overlapping squares line for line, in outline in Free and Pro alike (Font Awesome's own `copy` is two
  sheets of paper; `clone` joins the names Free has in regular, drawn by both Free webfonts); `share` is
  `share-nodes`, `pen` is `pen`, `vector-pen` is `pen-nib`, `trash-fill` the filled `trash-can`,
  `arrow-left` itself. Where Pro has Bootstrap's own outline of the drawing and Free has only the solid,
  Pro draws the outline (`prorole`), as the megaphone already did: share, the pen and the nib, and the
  archive, the magnet, the "i" and the people — the last two the outline halves of pairs whose `-fill`
  twin is the solid, which Pro can now draw as a pair. Drawn beside Bootstrap's glyph from Free 6.7.2 and
  7.3.1 and the owner's Pro 6.7.2 and 7.3.1 before they went in.
* **One box, the glyph in its middle, in every library.** An icon button is a square as tall as its row
  (26px, a small button's height; 28px in the Info panel's head, whose controls were that tall), the glyph
  at 1rem — a whole pixel, the star's too: at its old 1.05rem the glyph's box stood a fraction of a pixel
  into its button and the star came out up to 1.3px above the middle in every library (its drawing is in
  the middle of its em). It has no words to be level with, so it is not placed the way 1.69.0 places an
  icon beside words (on the words' cap middle): its `<i>` and its glyph are blocks of line-height 1 —
  which lays either font's em box exactly over the `<i>`, Bootstrap's and Font Awesome's alike — the `<i>`
  is centred in the button, and the ink is moved by the glyph's own middle in its em: Bootstrap's from
  the table 1.69.0 wrote (the nib and the filled bin added), Font Awesome's measured in the face it is
  drawn in by the observer, as it measures a glyph beside words (Font Awesome 7's star stands 0.05em high
  and stood 1.6px above the middle of its button until it was). The display is set at one class's weight
  before `.d-hidden`, as 1.69.0's rows are, so a hidden button stays hidden. Measured in every icon mode —
  see Tests.
* **Left as words**, after going through every public page and every panel page and tab: what decides
  something or sends a form (Sign in, Save, Send, Check, Mark all read, the panel's Test and Apply), the
  people lists' Message / Add as friend / Block, the header buttons of the account page and a profile
  (Share, Open my profile, Sign out — a header has the room), the list cards' Share / Edit / Delete and
  their Public switch, the search toolbar's Share beside the count, the Info panel's Refresh (it answers
  in words: "Asking…", "Refreshed"), and the panel's time ranges. The panel had its icon font all along:
  none of its words stood in for one. The stats page's large pictograms and the footer's GitHub logo stay
  drawings — pictures, not buttons.

### Fixed — the Info panel's head: its star stood out of line

* The star sat 1.3–1.6px above the "+" beside it (Bootstrap, and Font Awesome 7's star higher still), the
  "+" and "Who has this" were bordered boxes of one size and Share of another, and the head's own 1rem gap
  stood between them and Share and the close where 0.4rem stood between the rest. They are one row of
  28px icon buttons now, one gap between each, every glyph within a pixel of its box's middle and every
  box on one line (measured, see Tests) — the star keeps its bare look and its colours, in the same box.
  On a phone the torrent's name has the room the words took.

### Fixed — a sorted column's arrow stood apart from its name

* With Bootstrap Icons, the sorted column's arrow in the likes and the descriptions tables' headers (and
  the search results') stood 2.5px further from its name than the two-way arrows of the other columns:
  Bootstrap draws the two-way arrow edge to edge in its square and a one-way arrow with 0.21em of room on
  each side. The one-way arrow gives that room back; Font Awesome draws each glyph in a box of its own
  width and needed nothing. 1.70.0's, found by the alignment check once its fixture gave the test account
  a description of its own — the descriptions table on his profile and his account page.

### Changed — the panel's links in the panel's own colour

* **The owner**: the strong blue of Bootstrap's reboot, underlined, in the Settings help texts. Every
  panel link took Bootstrap's LIGHT theme's link colour (#0d6efd) and its underline, which on the panel's
  dark ground shouted out of the middle of every help text. The panel's links take its own accent now,
  calmer — the hue of #4a9eff, lighter and less saturated: #81b1e4, #a6cbf2 under the pointer — through
  Bootstrap's own variables, so the reboot rule, its hover and every visited link follow it (there is no
  other visited colour in the panel). The underline stays only where it is needed: a plain link inside
  words (a help text, a note, an alert, a list item, a folded "more") keeps one, thin and a little below
  the letters, half-strength until pointed at; a link standing on its own has none. A link with a class of
  its own is a component and keeps what its class says (the buttons, the menus, the tabs, the rows' own
  link colours).

### Changed — the account page's Overview: two columns of about one height, every part under a heading

* **The owner's screenshot**: the Picture alone at the foot of the left column with empty space under it,
  Privacy and the Profile cover running on in the right one. The Overview was two cards, and the left one
  held the list of every browser the account had asked to be remembered: on the test account (24 of them)
  it was 2045px against the right card's 1900, on one with two it would have ended some 600px short.
* **Each group is a card of its own** now, under a heading and a line that says what it is for — Profile,
  Picture, Profile cover, What we may send you, Your groups, Interface language, Time zone, Privacy, and
  a linked sign-in — and the cards FLOW into two columns the browser balances (CSS columns, no card split
  across them), in that order, which is also the order on a phone. Two columns wherever the Overview is
  656px wide or more, one below. Measured at 1280, 1440 and 1920 (the page is 976px wide at all three): 1318 / 1376px in English
  and 1379 / 1357 in Polish with the ratings off, 1505 / 1373 and 1379 / 1562 with them on — as even as
  whole cards allow (no card could change sides and make them more even).
* **The part that grows is at the bottom**: Account security — the second factor, the signed-in browsers
  and "Sign out everywhere else" — is the last card, the page's full width, under both columns.
* **Your groups** are drawn by the page as it is written, and by the script again as before: a card that
  grew from "Loading…" once the page was laid out moved the cards after it into the other column as the
  page opened.
* **The Picture and Cover settings** name a column now: "Left column, under Profile" (the picture's
  default) and "Right column, under Privacy" (the cover's), in the panel's words and hints; the blocks
  stand exactly there. Their notes lost the sentence that is the card's line now.
* **Adjust position and Remove** stand centred under the drop zone they act on, in both blocks; they hung
  from its left corner (point 7).

### Fixed — the links under the sign-in form turned pink once followed

* "Register" and "Reset it" under the sign-in form — and the same links under the registration and reset
  forms and the panel's sign-in — are the page's own way on, not something read and left behind, and
  took the site's visited colour (#F6BAFF) once followed. They keep the link colour (#BAD7FF) now; a
  link in the page's content still turns.

### Fixed — the CAPTCHA widget's white edges

* **The owner's screenshot**: white edges on the right and the bottom of the reCAPTCHA widget in "Please
  verify you are human". 1.70.0 set `color-scheme: dark` on the root of both stylesheets, so the browser's
  own parts of a form control are drawn for a dark page — and every element inherits it, the widget's
  iframe included. The widget's own page declares no scheme (light), and where a frame and its page
  disagree Chrome paints the page on an opaque canvas of the page's scheme: white, behind reCAPTCHA's dark
  box, which does not cover its frame (302x76 in 304x78) — the rim, and the four corners round it.
* The widget's box and the providers' challenge frames are in the "normal" scheme again (both
  stylesheets): the frame is transparent, the dialog shows through, and nothing else changes — the page and
  its fields stay dark. On the box, because Turnstile puts its frame inside a shadow root that only
  inheritance reaches. Measured with each provider's published test key, on the registration form and on
  the panel's sign-in, in the dark theme the site asks for and in the light one: reCAPTCHA's corners and its
  unpainted strip were 245–253 of 255 in brightness and are 11 now; hCaptcha's and Turnstile's pixels were
  not visibly touched (hCaptcha's light border is its own design), and they are in the same scheme now.

### Changed — the picker's Font Awesome categories are icons

* **The owner**: with every icon on offer (`shout_emoji_fa_scope` at "Every icon, by category"), the
  picker's page of every Font Awesome icon is navigated by Font Awesome's own categories, and its strip was
  seventy written words — "there are plenty of fitting icons", and for the alphabet "even three icons in
  the button, A B C". Each chip is its category's icon now: one glyph, or a short run where one says less
  than a few — the alphabet **A B C**, the numbers **1 2 3**, text formatting **B I U**, punctuation
  **? ! &**, fruit and vegetables an apple and a carrot. The paw for the animals, the ringed planet for
  astronomy, the tent for camping, the pie for the charts, the pram for childhood, the clapperboard for
  film, the robot for science fiction, ™ for the brands, the ellipsis for everything else.
* **Chosen by looking.** Every candidate was drawn from the owner's two Pro packages where they lie (read,
  never copied), in solid, regular, light, thin and sharp solid of 6.7.2 and 7.3.1, at the chip's size and
  larger; each of the 79 names chosen is an icon of both packages, declared by both stylesheets. Some went
  on sight: 7.3.1 draws its light praying hands as a multicoloured glyph, a pumpkin's face stood beside the
  Emoji chip's smile, two trucks would have been two categories that look alike, and Share's nodes are the
  Share buttons' glyph.
* **Names, committed**: `assets/emoji/fa-categories.json` — one entry for each of the 68 categories of the
  owner's indexes and the catalogue's two pages of its own (Brands, Other), names only, nothing of Font
  Awesome's files. The faces' answer carries each category's icons beside its name (and the file's date in
  its address, so an edit is not a day of the old answer), and the picker draws a chip from the catalogue it
  already holds: the icons named, when this package has every one of them, each in its own default style —
  the site's emoji style where the icon is drawn in it, else the classic family at that weight, as a click
  would insert it. A category whose icon the package lacks, or one the list does not name (a category a
  later version adds), shows a generic glyph, the tag — with that missing too, its own first icon; with
  nothing at all, its name, as every chip did.
* **Its name is still there**, where it is needed: in the site's tooltip, under a resting pointer and on the
  keyboard's focus ("Animals — 72 icons", "Zwierzęta — ikon: 72"), as the chip's name for a screen reader
  (`aria-label`, with `aria-pressed`), and in the line under the grid, which names the category on show in
  words — on a phone too, where no tooltip shows. No `title`: the browser drew its own box as well. The
  chips keep the reader's alphabet (Brands and Other last), the category remembered, one Tab stop and the
  arrows, Home and End.
* **The chosen one reads at a glance**: FILLED with the accent, its icon in the card's ground — an accent
  edge alone was one pill among seventy — and the keyboard's ring stands off it. A pill a little wider than
  tall, a run a longer one, the glyphs in its middle; on a phone about a cell's height, the strip scrolling
  inside the picker. Font Awesome 7 draws every icon in a box 1.25em wide, which set a run's letters a space
  apart; a run's glyphs take their own width, as 6.7.2 draws them anyway.

### Changed — Settings: Emoji & emotes, a group of its own

* **The owner**: the emoji were mixed in with the shoutbox's settings, "and they are elaborate enough by now
  to deserve a section of their own". They have a chip of their own, **Emoji & emotes** ("Emoji i
  emotki"), right after Shoutbox, and two sections: **Emoji in the picker** — Font Awesome's faces, their
  style, how much of the package the picker offers — and **Emotes and stickers** — the switches (the
  emotes, "Beyond the shoutbox", the stickers, the approval), the upload limits, the waiting queue, the
  table and the form that adds one. The group's first line says what it is for: every text that has the
  picker, across the whole site — the shoutbox, private messages, torrent descriptions and the proposals
  that rewrite them, list descriptions, the profile's description — and the one thing the pictures need
  that the emoji do not: the shoutbox switched on, since the emotes are pictures it keeps (with a link).
* **Nothing else moved.** The setting keys, their values and how they are saved are what they were — no
  migration — and so are the ids the panel's script finds the two blocks by. The search finds them by their
  labels and by their hidden words, filed under the new group now, in either language; Shoutbox's words no
  longer claim the emotes. A link to either old block (`#admin-emotes`, `#admin-shout-emoji`) opens its new
  section under its chip, scrolled to the block: a link to anything inside a section does now — a
  sub-heading as well — clear of the sticky toolbar. No `#section-…` id moved.
* **Shoutbox keeps the room**: its settings, who may read and write — six permissions now — and Purge, and
  under its first line a second that says where the emoji and the emotes went, with the link. The two emote
  permissions (`shout.upload_emote`, `shout.emote_auto`) left the room's matrix for one of their own under
  the emote manager, "Who may upload emotes": the same read-only matrix, one request for both folds.
* **Words that were the room's**: the table of emotes was "In the room" and is "On the site"; the section's
  line says an emote is written as its code "in a shout — and, while 'Beyond the shoutbox' is on, in every
  other text that has the picker"; the emotes' switch says off takes them out of every picker (it said off
  "hides the picker", which has held the emoji as well since 1.69.0) and no longer repeats what they are,
  which the line above it says; a face's token is written so "in a text", not "in a shout".

### Fixed — "Invalid CSRF token" on a vote from a profile or the account page, and on any page a button meets

* **The owner**: rating on a profile answered "Invalid CSRF token" — "check whether the same problem shows up
  anywhere else, with other actions". There is one token per session, but every page published it under a
  name of its own — `#search-csrf` on the search page, `#account-csrf` on the account page and on a profile,
  `#shout-csrf` in the shoutbox, a hidden field in a form, nothing at all on the front page — and each script
  read the one it knew. The Info panel's vote read `#search-csrf` alone, and the panel opens on a profile and
  on the account page as well, where there is no such element: the vote went out with an empty token, from
  both pages, in both rating modes. The panel's **Refresh** read the same one and was refused the same way.
  It was the kind of bug 1.67.0 met once already (the shoutbox's Preview on the front page); four more
  scripts — the second factor and the signed-in devices, the profile's description, the picture editor, the
  sounds — read `#account-csrf` alone and worked only because their buttons have so far lived where that id is.
* **Published once, asked in one place.** The layout puts the session's token on every page, once, as
  `<meta name="csrf-token">` in the head — a meta, nothing for the policy's nonce to allow, and only where
  there is a session for it to belong to — and every public script asks one helper, `csrfToken()`
  (`assets/js/app.js`, on `window` for the other files): a widget that carries a token of its own says so with
  `data-csrf` naming its field (the shoutbox), a form sends its own hidden field, everything else sends the
  page's; a `data-csrf` naming a field that is not there falls through to the page's rather than sending
  nothing. All thirty-two places a public script sends a token ask it: the votes and Refresh, the
  description's panel and every editor's Preview, the account page's own requests (notifications, mail,
  language, time zone, the account form, sign-out…), favourites, lists and privacy, people and messages, the
  shoutbox, the second factor and the devices, the profile's description, the picture editor, the sounds. No
  public script reads a page's own id any more; the ids stay in the pages, holding the same token, because
  the tests read them.

### Changed — the vote you cast, pressed again, is taken back

* **The owner**: "a second click on the same element — a like, say — when we have already rated should remove
  the rating, and it does not." It does now: the thumb you pressed, or the half star at your rating, pressed
  a second time takes your vote back — out of the panel's numbers, the torrent's score, your likes or ratings
  on your profile and in the account page's tab (at once: the panel says so with `rating:changed`, and the
  table asks for its page again), and "Who has this" (asked afresh each time it opens, and while it is open
  on that torrent).
* **The pressed one shows it.** The thumb you cast is filled — the glyph the likes table draws a cast vote with
  — in the colours of the bar above it, green or red, its words white, with `aria-pressed`; it was a faint
  outline. The half star at your rating has a gold mark under it — the five stars show your rating or, before
  you rate, the average, and the mark says which — and `aria-pressed`. Both say in their tooltips what a
  second press does: "Your vote — press again to take it back", "3.5 stars — your rating; press again to take
  it back". While a vote is on its way the buttons stand still, and a vote that did not go through says so
  under them instead of doing nothing.
* **An operation of its own, not a toggle.** The button knows it is pressed and asks `rate_hash` for
  `{op: 'remove'}` — `repRemoveVote()` — instead of sending the same value again for the server to guess about
  (the house rule since 1.67.0): a double click asks the same thing twice and cannot cast and take back in one
  go, and taking back a vote that is not there succeeds and changes nothing. It deletes one row at most, the
  caller's own — the identity a vote is cast with: the account, or for an anonymous voter the address (IPv6 by
  its /64) — so an address never takes back the vote an account cast from it. It passes every gate a vote
  passes — ratings on, who may vote, `rating.vote`, the hour's budget — and pays a vote's CAPTCHA points.
  Votes are not in the audit log, and neither is taking one back. The totals are counted again in the site's
  mode.
* `includes/reputation.php` said there would be no "unvote", because a button that can be un-pressed "doubles
  the surface for automation"; it says now why taking back costs exactly what voting costs, so the surface
  stays the size it was. The ban's `repClear()` counted the totals again without the configuration — harmless
  while every vote goes, which is what a ban does — and takes it now, required, so it cannot be left out again.

### Fixed — opening the Info panel spent the reader's votes

* Found on the way: the panel asks twice, every time it opens, whether its reader may vote — and each asking
  spent a vote from the hour's budget (`rep_rate_per_hour`, 30 by default). Fifteen panels opened in an hour
  left a member no vote at all, and each vote cost three, its own and the two of the panel it redraws: a thumb
  pressed on and off a few times would soon have been refused. Asking only looks now (`rateLimitPeek()`);
  a vote and a vote taken back spend.

### Fixed — a group changed in a request kept its old permissions there

* Found by the tests: an account's permissions are remembered for the request and forgotten when its groups
  change — except that with e-mail verification off the memo's key is the bare id, which PHP keeps as a
  number, and the forgetting compared it with a string: nothing was forgotten, and a grant followed by a page
  in the same request (what `v1/users/grant` and the panel's grant do) answered with the permissions from
  before. Compared as strings now (`userPermissionsForget()`).

### Added — comments on a torrent

* **The owner**: "add comments to hashes — plan it well, it has to be safe: some emoji, simple BBCode without
  pictures, a link perhaps, a length to configure of course; a new notification sound for somebody having
  commented; permissions and the rest."
* **Where.** A section of the Info panel right after the rating block, **Comments (N)**. The count is all the
  panel's one answer carries (`index_info` → `comments`); the thread is asked for (`comment_list`) when the
  section comes into view in the panel, or is opened. It reads oldest to newest and opens on its newest page
  (`comments_per_page`, 20) with *Show earlier comments* above it: a new comment lands at the end, beside the
  composer, and a notification's link (`#comment-N`) lands on its comment, lit for a moment, loading earlier
  pages until it is there. A row: the author's picture and name (a link to the profile where it opens for this
  reader), the time in the READER's zone (the moment with its offset in the tooltip), "edited" — "edited by a
  moderator" when it was one —, and the actions as this release's icon buttons: **Edit** (in place, the same
  editor, the words as stored) and **Delete** (your own: the first press arms it, as the description's does;
  somebody else's: a reason first). A comment by a member you blocked is folded away ("Hidden — this member is
  blocked by you.", Show). In English and Polish, drawn again by the live language switch; on a phone the
  buttons take a line of their own.
* **The composer** is the site's editor with a comment's toolbar — B I U S, the link only for a writer who may
  link, quote, spoiler, code — and the emoji picker in a new `comment` context: the emoji, Font Awesome's icons
  as the site offers them and the emotes; a sticker is drawn as an emote, so there is no Stickers page. The
  counter counts what a reader will see, as the server does; the Preview is the server's (`richtext_preview`
  for `comment`); **Ctrl+Enter** sends; **@** offers members' names (the room's list, `shout_mentions` with
  `for=comment`, gated by the right to comment). Somebody who may not write is told why instead — sign in, not
  allowed, silenced by a moderator until a date (a mute stops writing and correcting, never taking your own
  words back), or no CAPTCHA for guests.
* **Safe by construction.** Not the shared renderer with a switch: a comment's list of tags is short, so it has
  a walker of its own over the raw text, the profile description's kind (`commentParse()`,
  `includes/comments.php`) — every run of text escaped on its way out, and the only tags in the output the ones
  it writes: `[b] [i] [u] [s]`, `[spoiler]` / `[spoiler=title]`, `[quote]` / `[quote=name]` ONE level deep (a
  quote inside a quote is its text), `[code]` (all of it literal), and `[url]` / `[url=…]` only while Settings
  allows links and the writer is a member — through `richtextSafeUrl()`, with `rel="nofollow noopener
  noreferrer ugc"`, a new tab and the "you are leaving" question; otherwise the tag is text, and a save refuses
  it, so nobody is surprised by it later. No pictures, tables, sizes or colours: whatever else was typed is
  shown as typed. Then, on the finished text only, what every text with the picker gets: `:fa-…:`, the emotes
  at the size of the words, `@name` as a link to the profile. Cleaned first — control characters, the bidi
  embeddings, overrides and isolates (how a link's words are made to lie) and the characters that draw nothing
  go —, at most two line breaks in a row and 20 lines, 3 links, tags 8 deep.
* **The length** is counted in characters a READER sees — the words, not the tags round them, in code points
  (`comment_max_chars`, 500, clamped to 20–5000). The text as typed may be four times that (at most 20 000
  characters and 60 000 bytes), and a text over 256 KB is refused before anything is done with it.
* **Who.** Five permissions, asked of the ACCOUNT, never of a panel session's blanket: `comment.view`,
  `comment.post`, `comment.edit_own` and `comment.delete_own` (members, granted once by the migration) and
  `comment.moderate` (moderators, with view and post: edit anybody's — marked, audited, its author told — and
  remove it with a reason its author is shown). A thread is exactly as visible as the Info panel it is in: a
  hash the reader may not open has no comments to read or write. With accounts off there are no comments (the
  legacy default says no to every `comment.*`). Correcting your own: for `comment_edit_minutes` (15; 0 never);
  taking it back: for `comment_delete_own_minutes` (60; 0 without a limit). A comment is taken down SOFTLY:
  gone for every reader, kept with who removed it, when and why.
* **Guests**, only where the operator grants the guest group `comment.post` (nobody does as shipped): signed
  "Guest #4f2a" — four characters of a keyed hash of the day and the address group, computed as it is
  written: two guests on one day almost always differ, the same one tomorrow is somebody else, and nothing in
  it leads back to an address, which is never shown. A CAPTCHA every time — with no provider set up there are no
  guest comments, and the composer says so —, never a link, and held for a moderator while
  `comments_guest_review` is on (as shipped): a moderator sees it in its place, marked, with *Let it through*,
  and the people it is news to are told only then. A guest cannot correct or take back a comment, is told
  nothing and cannot be @-mentioned. Settings says all of this beside the switches.
* **Who is told** (`commentNotifyNew()`): the member who registered the torrent, the author of its published
  description, the members who commented before (the latest fifty) and the members it @-mentions — once each,
  by the strongest reason they have not switched off, in THEIR language; never its author, never across a
  block either way, never somebody who may not read comments; a thread already unread in somebody's
  notifications is not announced to them again (a mention always is). Four switches on the account page, under
  the notifications — a torrent I registered, a description I wrote, a thread I commented in, a mention
  (`users.comment_notify`, all on). A notification can say where it happened now (`user_notifications.link`,
  only ever an address of the site's own), and the account page draws it as a **Show** button that marks it
  read on the way.
* **Two new sounds**: *somebody comments where I am told of it* (`comment`) and *a comment mentions me*
  (`comment_mention`), with site defaults in Settings → Sounds (none as shipped, like every other) and a choice
  on the account's Sounds tab. The pulse and `user_me` carry `unread_comment` and, of those,
  `unread_comment_mention`; `sounds.js` plays the comment's sound and takes those out of the notification's
  count — one comment, one sound.
* **Settings → Descriptions, comments & ratings** (the group's new name; "Opisy, komentarze i oceny") →
  **Comments**, between the descriptions and the ratings: `comments_enabled` (on), `comment_max_chars` (500),
  `comment_links` (on), `comment_edit_minutes` (15), `comment_delete_own_minutes` (60), `comments_per_page` (20),
  `comment_rate_per_hour` (30 an account — a guest's address group for guests; a correction counts too),
  `captcha_pts_comment` (1, the smart CAPTCHA's points), `comments_guest_review` (on), and every group's comment
  permissions, read-only; Settings → Sounds: `sound_default_comment`, `sound_default_comment_mention`. Each in
  its four places: the default, the save's list and clamp, the form, the search's words.
* **Data** (schema 83): `hash_comments` — the hash, the author (or a guest's tag and address group), the text as
  written, `status` (visible, pending, deleted), when it was written, edited and by whom, let through, deleted
  and by whom and why — indexed for a thread (`info_hash, status, id`), a member's comments and the queue;
  `users.comment_notify`; `user_notifications.link`. No counter column: a torrent's count is a range of the
  thread's index, and `index_hashes`, where one would go, is the table of millions of rows. Deleting an account
  deletes its comments and forgets its moderation stamps on other people's (the audit log keeps the name).
  Endpoints `comment_list`, `comment_post`, `comment_edit` (GET the words as stored, POST the change),
  `comment_delete`, `comment_approve`, `comment_prefs` — thin: each is a request function in
  `includes/comments.php` that answers a status and a body, so the tests put every refusal to it without a
  server. Every POST sends `csrfToken()`.
* **For what comes next.** Reports: `commentDelete()` takes `['notify' => false]` — the silent removal,
  audited as silent; every row the page is handed carries `can_report` (false for now), and
  `window.Comments.onActions(fn)` puts a button beside Edit and Delete in every row drawn; the held guest
  comments are `commentPendingList()` and `commentApprove()`. One anti-spam layer: `commentFloodCheck()` is the
  ONE gate every comment write passes — today a guest's CAPTCHA, a member's smart-CAPTCHA points and the hourly
  limit — and its body is the only thing to replace.

### Added — reports of comments, descriptions and shouts; a warning, loud or silent

* **The owner**: "we already have a well-made system for reported messages. I would add reporting comments,
  descriptions and even shouts from the shoutbox — so a tab for where each came from, some good filtering, and
  permissions for it. Notifications too, like when an admin deletes our comment. And a choice whether it is
  silent or loud (as a warning). Plan it, then do it."
* **Reporting, from where the words are.** A **flag** beside somebody else's comment (among its actions in the
  Info panel), under a torrent's description (beside Propose, Edit and Delete — the words published now) and
  on a line of the shoutbox (among the line's controls): an icon button of this release's kind — its name for
  a screen reader, what it does in the tooltip —, or a room control like its neighbours. Pressed, a small box
  opens in place: *What is wrong with it?*, a reason of up to 300 characters, **Send** (or Enter), Cancel (or
  Esc — which puts the box away and leaves the Info panel open), and the promise that only the moderators read
  it and the author never learns who reported. Sent, the flag is filled, in the warning colour, and says
  **Reported** — at once, and on every page after, from the server's own word on each row (`can_report`,
  `reported`); pressed again it only says so. Never on your own words, a line the site said, or anything the
  reader cannot see; one OPEN report per member per thing (the database's key — after it is closed, the same
  member may report it again); a member's reports an hour are limited. `content.report`, members as shipped.
* **Kept beside the message reports, not in them.** A new table, `content_reports`, and not `message_reports`
  made generic: a message report is a privacy rule written into a schema — the reported line and the one before
  it, never the conversation —, every query and test of that queue is built on its shape, and it is untouched.
  The new kinds share one table, keyed by the thing reported (the kind, a comment's or a shout's id, the
  torrent's hash), and each report keeps the words **as reported**: a shout is pruned by retention, and a
  comment can be corrected or a description replaced after somebody reported it.
* **The Reports page: a tab for where it came from.** **Comments**, **Descriptions** and **Shouts** beside the
  torrent reports and the messages, each with its count of things waiting (a thing three people reported is
  one to decide), each drawn while its feature is on and the session may read that queue. The page opens for
  any of its queues and shows only the tabs the session holds — somebody given the comments alone opens it on
  Comments; the torrent reports' own tabs, toolbar and table stay with `panel.reports.view`. No "All" tab: it
  would either mix the torrent queue and the messages — other cards, other permissions — or be an "all" that is
  not one. **One card for each reported thing**: its words once, drawn by their own renderer (a comment's
  allow-list, a description's with its hidden parts shown, the room's for a shout — the markup a reader is
  handed), and beside them the words as reported when they changed or went; a link to where it lives; its
  author, and what the account already is — banned, silenced, staff, reported how often in all, warned how often
  and the latest warnings; and every report about it, newest first, with its reason and what became of it.
  **Filters**: the status, the reported member, the reporter, a date range and a text (reasons, the words,
  notes, names); *Clear the filters*.
* **What a moderator may do** (`panel.reports.<kind>.handle`), all the target's reports at once: **Close** (no
  action) or **Reopen**; **Remove** the words through each kind's own function — a comment through
  `commentDelete()` (the Reports page's authority; the removal still leaves who, when and why on the row), a
  description through 1.70.0's `contentDelete()`, a shout through `shoutDelete()` —, refused if the words
  changed since the card was drawn; **Warn** the author; **silence** the author (messages, shouts, comments…)
  for a day, a week, a month or until lifted, or **ban** the account for a week, a month or until lifted — by
  the database's clock — and lift either.
  An answer to the reporters and a note for the log, as on the message card. The message card's rules for an
  account: never one that can open the panel, never your own, never a ban without a date (the owner's, from
  Users). A guest's comment has no account to act on: its words can be removed, nobody warned.
* **Silent or loud.** Every action that reaches the author carries the choice, a pair of radio buttons on the
  card. **Silently**: the author is told nothing — the words are simply gone, the silence simply applies.
  **As a warning**: ONE notification of a new type, `warning`, in the AUTHOR's language — "Warning: your comment
  on “…” was removed", "Warning about your description of “…”", "Warning: you are silenced until …" — with the
  moderator's reason (a warning needs one: without it the card says so and nothing is done) and which warning
  this is on the account; and a row in `user_warnings`. **Warn** is loud by what it is. Lifting a silence or a
  ban is good news, not a warning: said plainly when loud, not at all when silent. The removal functions are
  told not to tell the author themselves (`commentDelete()`'s and now `contentDelete()`'s `notify: false`), so a
  loud removal is one notification, not two.
* **Warnings** (`user_warnings`: the member, the reason, what it was about, what came with it, who gave it,
  when): the count and the latest three on every reported author's card — the new ones and the message card —,
  the count beside the status in **Users** and the latest five in the member's window there. The member sees
  each warning among their notifications, marked with the library's warning sign and a bar in the warning
  colour — and nowhere else, nothing public. An automatic silence after so many warnings was left out: it is a
  setting of its own with its own questions (how many, over what time, never shortening a longer silence), and
  the count is now on every card for a moderator to act on.
* **The message card gets the same choice**: silent or loud for Delete the message, the silences and the bans,
  a reason for the author, and **Warn**. A request without a choice — what every caller sent before this
  release — is answered as it always was: the author told the plain fact.
* **Who hears what.** The reporters hear the outcome, as on the message card — closed, removed, handled —, each
  once, in THEIR language, with the moderator's answer when there is one; they learn nothing about the other
  account beyond what the moderator writes. The author hears only a loud action. Nobody is ever told who
  reported, and no reporter learns of another. Every action is an audit line in the log's Reports group
  (`creport.file` for a member's report, `creport.close` / `reopen` / `remove` / `warn` / `mute` / `unmute` /
  `ban` / `unban`), a warning one in Users (`user.warn`, and `pm.user.warn` from the message card), besides the
  lines the removal functions write themselves.
* **Permissions** (Users → Groups): `content.report` (members, granted once by the migration; no with accounts
  off; guests nothing), and six for the Reports page — `panel.reports.comments.view` / `.handle`,
  `panel.reports.descriptions.view` / `.handle`, `panel.reports.shouts.view` / `.handle` — granted once to the
  moderator group and in its preset: the words were public already. The message queue (`panel.messages.*`)
  stays with nobody, as before.
* **Data** (schema 84): `content_reports` and `user_warnings`; an account deleted takes its reports and warnings
  with it, and the reports about its comments and shouts (which went with it); a report about its description
  stays with the torrent's words, without an author. Endpoints: `content_report` (a member's report),
  `admin/content_reports` (the tabs' counts, a filtered page of cards) and `admin/content_report_action` — the
  router asks only for the panel, and each asks the kind's own permission. Thin, each a request function in
  `includes/reports.php`. For part F, one anti-spam layer: `contentReportFloodCheck()` is the ONE limit call a
  report passes (today a plain hourly limit per account), and its body is the only thing to replace.

### Added — one anti-spam layer for everything people write

* **The owner**: "writing comments and descriptions is protected against spam, I take it — if somebody is too
  quick it fires a CAPTCHA or something? And a guest could always have one? Are messages protected the same way,
  so nobody starts sending spam? And the other sensitive places, the shoutbox, so nobody floods it? There a
  growing interval would do: the first 2-3 messages without a limit, then a 5 second limiter, then 15, then 30,
  and the whole limit expiring after about 2 minutes — or a CAPTCHA; plan it."
* **What there was**: a file-based window per address (`rateLimitAllow()`, which lets everything through when its
  file cannot be read and knows nothing about who is writing), a fixed five-second wall in the room, and no CAPTCHA
  on anything a member writes. **What there is**: `includes/antispam.php`, asked by every place people write — a
  line in the room and its correction, a sticker and an emote upload; a message and a message report; a comment and
  its correction; a torrent's description (the Info panel's submission, proposal and Edit, and the whitelist
  form's); a list's name and description; the profile's description; a report of a comment, a description or a
  shout; and a vote — with one question and one answer: `antispamCheck($db, $cfg, $context,
  antispamSubject($me, $ip), $words, $opts)` gives a reservation or a refusal; the caller writes, then
  `antispamRecord()`, or hands the reservation back with `antispamRelease()` when the words are refused after the
  check (too long, a bad tag) — so a refused write never costs a step. The **subject** is the account for a member
  and the address group for a guest (an IPv4 address, an IPv6 /64); a member who changes address is the same member.
* **A ladder per place.** A free burst, then growing pauses, all forgotten after a quiet spell; a write refused for
  coming early does not climb it. As shipped (Settings can change every number):
  the **room** 3 free, then 5 s, 15 s, 30 s, 60 s, reset after 2 minutes — the owner's sketch;
  **messages** 3 new conversations free, then 30 s, 1, 2, 5 min, reset after 10 minutes — counted in
  conversations STARTED, not lines: a conversation both people are in is a chat, and the other person can block;
  **comments** 2 free, then 15 s, 30 s, 1, 2 min, 5 minutes — a comment is longer than a line, and the pattern of
  comment spam is the same words under many torrents; **descriptions** 2 free, then 1, 5, 15, 30 min, an hour — a
  considered text that goes to a moderator; **reports** 3 free, then 30 s, 2, 5, 15 min, 30 minutes — three reports
  at once is somebody tidying up, thirty a campaign; **lists** 3 free, then 10 s, 30 s, 1, 2 min, 10 minutes —
  renaming while sorting; the **profile's description** 3 free, then 30 s, 1, 2, 5 min, 15 minutes; **emote
  uploads** 3 free, then 30 s, 1, 2, 5 min, 30 minutes; **votes** 10 free, then 2, 5, 10, 30 s, 2 minutes —
  rating as one browses (the hourly budget of 1.18.0 stays). The pause is worked out when it is asked for, from
  the last write and the numbers as Settings has them NOW, so a changed setting applies at once.
* **Decided and reserved in one step, and never open.** A row per place and subject in `antispam_state`, taken
  under `SELECT … FOR UPDATE` (the row made first by an upsert: an `INSERT IGNORE` takes a shared lock two racers
  would deadlock on), the times unix seconds by the DATABASE's clock. Two requests at once are served one after the
  other and the second sees the first's write: a double click cannot post twice, and ten connections cannot take
  ten free lines. The message send holds a transaction of its own; the layer works inside it, the send's rollback
  is the release, and a refusal is committed first so it counts. Any failure — no table, a lost connection, a
  lookup before the lock — is a refusal, 503 "could not be checked, try again in a moment".
* **A CAPTCHA at the top of a ladder**, not a wall on every form: whoever keeps hitting a ladder's LAST step — a
  write made there, or an attempt refused while waiting there — `antispam_captcha_after` times (3) is asked for one
  on the next write; solving it eases the level back to the edge of the burst, so the next pause is the first step
  again. **A guest solves one every time** they write (a comment, a description; `antispam_guest_captcha`, on). The
  order a person meets things in: how long to wait, then how many, then whether they said it already, and only then
  the CAPTCHA — nobody solves a puzzle to be told to wait. The token is verified before the row is locked (a
  verifier is a network call); a token the same request already verified — the whitelist form asks for its own —
  counts.
  **No provider set up**: nobody can be asked, so the layer paces alone, and Settings says so above the section (a
  guest's comment is still impossible without one — the comments' own rule).
* **Messages**: new conversations an hour and a day (8 and 20; a new account 2 and 4), counted from the messages
  themselves; the same words to more than `antispam_pm_spread` people (2) within the duplicate window refused as
  spam. The address ceiling on sending is the messages' own now, `rate_limit_pm` (240 an hour, Settings → People):
  the send read `rate_limit_favourites`, a key no setting ever defined, so it was always its fallback.
* **New accounts** — younger than `antispam_new_days` (3): every pause and the quiet spell × `antispam_new_factor`
  (2), their own conversation limits, and the links they write in the room, a comment, a list's description and the
  profile's description drawn as **words** (`antispam_new_links`). Decided when the words are drawn, from when they
  were WRITTEN: a link written on the first day stays words on the fourth — nobody's spam goes live by itself three
  days later — and one written once the account is trusted is a link; a moderator's correction re-dates the words.
  The Preview and the comment composer say so before anything is sent.
* **Staff** — an account holding `panel.access` — skip the ladders, the CAPTCHA escalation, the new-account rules
  and the conversation limits while `antispam_staff_exempt` is on (as shipped): a moderator clearing a queue is not
  paced, and the escalation is the ladder's, which staff do not climb. The plain duplicate rule is everybody's.
* **The same words twice** — case and spacing aside — within `antispam_dup_seconds` (600) are refused in the room,
  a message (to the same person), a comment and a description: 409 with its sentence. Under eight characters never
  counts ("hi", "+1").
* **Corrections** — a line's, a comment's — are not laddered and never asked a CAPTCHA or the duplicate rule: a
  gap between two (the room's `shout_flood_seconds`, a comment's five seconds), no more.
* **`shout_flood_seconds` is kept and folded in, not migrated**: with the layer on it is the floor under the room's
  steps (the first lines stay free) and the gap between two corrections; with the layer switched off it is the
  room's old wall between every two lines, as before. Its help says so in both languages.
* **What the reader sees.** Every refusal is one shape — `error`, a sentence in the reader's language with the time
  in it ("Too fast for the room — you can write again in 4 s."), `retry_after`, and `antispam` {kind, context,
  seconds, and the same sentence with `{time}` where the time goes}; a CAPTCHA request also names the provider and
  its PUBLIC key. `assets/js/antispam.js` serves every composer from it — the room (a line, a sticker, a
  correction, an emote upload), a conversation and its report, a comment and its correction, the description
  editor, the whitelist form, the lists' windows and the picker's "new list", the profile's description, the report
  box, the vote buttons: on a wait, **Send counts down on itself** — disabled, the time drawn by the stylesheet from
  `data-as-wait` (never a text node: the live language switch pairs a button's text nodes by position), the
  sentence beside it ticking — and comes back by itself at zero with the words still in the box; a CAPTCHA request
  opens **the site's CAPTCHA box** and the same request goes again with the token. A page that had no box (the room,
  a conversation, a list) draws it and loads the provider's script the first time one is needed — the policy
  already allows the chosen provider's hosts on every page (`captchaCspHosts()`, and `.htaccess`), which is what
  lets a script be added then. A guest's comment composer has both from the start: a guest is always asked.
* **Settings → Security & CAPTCHA → Anti-spam**: the switch, the CAPTCHA after how many hits, the guests' CAPTCHA,
  the staff exemption; a grid of ladders — burst, pauses as a list ("5, 15, 30, 60"), quiet spell — one row per
  place; new accounts (days, factor, links as words); messages (the four conversation limits, the spread); the
  duplicate window. Every setting in its four places (defaults, the save's allow-list and clamps, the form, the
  search's words), help in English and Polish.
* **The log** hears of a subject refused three times in a row — `antispam.refuse`, in the Reports group — at most
  once an hour per subject.
* **Data** (schema 85): `antispam_state` — the ladder's level, the last write and the earliest next one, the hits
  at the top and whether a CAPTCHA is due, the refusals in a row, the last correction, fingerprints of the last
  words, a revision — pruned by the janitor after two days untouched; an account deleted takes its rows. 46 new
  defaults: the layer's 40, `rate_limit_pm` and the five switches below (a database that already has one of them
  keeps its own row).

### Fixed — a vote could never be asked for a CAPTCHA; the per-form CAPTCHA switches had no defaults

* `rate_hash` asked `isCaptchaRequired('vote')`, which reads a `recaptcha_on_vote` switch no setting ever defined:
  the gate was dead code, and a script could vote as fast as the hourly budget allowed. A vote is on the anti-spam
  layer now (context `vote`, above), and the refusal it can meet is peeked at first, spending nothing.
* The CAPTCHA's per-form switches — the abuse report form, the panel's sign-in, the status check, the appeal and
  the block check — were on the Settings page, in the save list and in the installer, but never among the defaults:
  an install whose settings came from the migration was shown "Yes" for the report form (the form's fallback) while
  the code read the missing key as no. The defaults are what the page shows for a missing key: the report form 1,
  the other four 0 — so on such an install the report form now asks for a CAPTCHA (with a provider set up), as
  Settings always said it did. An install that saved these switches keeps what it saved.

### Fixed — "@name." at the end of a sentence was not a mention

* A name followed by a full stop ("thanks, @smokeuser.") or a dash was looked up with them — a name may contain
  both —, found nobody, and stayed text. Both parsers (the room's and the comments', one function:
  `shoutMentionCandidates()`) try the token, then the token without its trailing dots and dashes — the longest
  that is an account wins, so a name that really ends in a dot still does — and the punctuation stays text after
  the link. Both suggestion lists (the room's and the comments') close at that punctuation instead of offering
  names for "smokeuser.", and the next letter asks again.

### Tests

* `scratchpad/shots/icons_align_check.js`: an icon button is measured as what it is — a glyph with no words
  to be level with: every shown `.ic-btn`, its glyph's ink middle against the middle of the box inside its
  border, across and down, within 1px; the buttons of one row (the Info panel's head, the search results'
  actions, a torrent row's, the description actions, a conversation's head, the copy boxes, the search
  box) of one size and on one line within half a pixel, the line read inside the row. In every mode it
  runs — Bootstrap Icons, Free 6.7.2 and 7.3.1, the owner's Pro 6.7.2 and 7.3.1 in solid and light and 7's
  Jelly — in English and Polish, on a desktop and a phone, the Info panel in each of its states (no
  description, somebody else's with Propose a rewrite, your own with Edit and Delete, and Delete armed —
  held armed while it is measured) from two catalogue rows and a grant to the member group that it makes
  and takes back; the tooltips hidden while it photographs. Its first full run found the star's 1.05rem
  (up to 1.3px off) and 1.70.0's sort arrows (both above). And two things of its own: the search's hourly
  budget (`rate_limit_index_search`, one bucket for the search, "Who has this" and the likes) ran out
  about half way through a run, so the last modes' search pages drew no rows and measured nothing,
  silently — the budget is cleared for each mode and a search that draws no rows fails; and a request the
  browser could not make is listed beside the script errors (Windows' `ERR_NO_BUFFER_SPACE` on a long run
  cost one run a stylesheet, and its page was measured in the browser's defaults). The last run: 0 failed;
  892 icon buttons measured in each full mode and 804 in each style mode, the worst glyph +0.59px from its
  box's middle with Bootstrap and −0.84px with Font Awesome (−1.00 in Jelly: the search box's 12px cross);
  words beside icons as before (worst +0.87px, every gap within a pixel of its kind).
* `scratchpad/shots/icons_check.js`: every icon button on the pages it visits, in every mode — one glyph, no
  words, a name (`aria-label`) and a tooltip (`data-tip`), no `title`, no inline drawing in any button —
  and the Info panel's sizes; a row of buttons is read by its buttons' names where they have no words.
  972 checks, 0 failed, in seven modes (Bootstrap Icons, Free 6.7.2 and 7.3.1, the owner's Pro 6.7.2 and
  7.3.1, and Pro in Sharp Solid and Duotone Light), 326 icon buttons on eight pages in each.
* `scratchpad/shots/account_width_check.js`: the Overview at 1280, 1440 and 1920 in English and Polish, with
  the ratings off and on — two columns, as even as whole cards allow (no card could change sides and make
  them more even), Account security the last card and the full width — and one column on a phone.
* `scratchpad/shots/media_check.js`: the picture and the cover in the column their setting names; Adjust
  position and Remove centred under their drop zone within a pixel, on a desktop and a phone.
* `scratchpad/shots/polish_check.js`: the Copy buttons are icons with names; the tooltip — not at once under
  a pointer that crosses a row, shown after a rest, gone when the pointer leaves, shown on keyboard focus,
  and "Copied!" in its place on a press; the links under the sign-in, registration and reset forms keep
  the link colour once visited (a link in the content still turns); the panel's links on six pages — none in
  Bootstrap's #0d6efd, a plain link in words #81b1e4 and underlined, a visited one the same.
* `scratchpad/shots/captcha_check.js` (new; in the sweep after `security_check`): reCAPTCHA v2, hCaptcha and
  Turnstile with their published test keys, on the registration form and on the panel's sign-in, with the
  widget's dark and its light theme — twelve states: the widget's box and frame in the "normal" scheme while
  the page and its fields stay dark, and no light pixel at the frame's corners and its unpainted strip.
  Against 1.70.0's stylesheets (served in their place by request interception, `CAPTCHA_CSS_FROM`) all twelve
  fail.
* `fav_check.js`, `lists_check.js`, `share_check.js`, `content_check.js`, `descriptions_check.js`,
  `picker_everywhere_check.js`, `who_check.js` and `panel_fixes_check.js` find and read the buttons that are
  icons now by their names and tooltips; `descriptions_check.js` also the armed Delete (its name, the filled
  bin, the tooltip held).
* `tests/icons_test.php` (130 checks), a new section 7: the six new names and Pro's outlines; the places
  drawing icons and not words; no inline drawing for the copy buttons or the search box; the tooltip's
  listener, its options and the language switch's `data-tip`; the icon button's CSS (its display before
  `.d-hidden`, the glyph a block, the middle) and the observer fitting an icon button's Font Awesome glyph.
* `tests/usermedia_test.php` (159 checks): the Overview a flow of cards, the picture's and the cover's places
  in it, Account security printed after it.
* Before and after screenshots of every place, and the CAPTCHA check's, in the untracked
  `scratchpad/shots1710/`.
* `scratchpad/shots/emoji_picker_check.js`, the category chips in BOTH of the owner's packages — 6.7.2 and
  7.3.1, each installed through the CLI for the run and deleted after: in English and Polish every chip is
  the icon or the run the committed list names (so every name is in that package), drawn by its font, in
  the site's style; no words, no `title`, its name as `aria-label` and with its count as the tooltip; one
  chosen, pressed, the one Tab stop, filled with the accent. The keyboard (End, Home, Right: each chip it
  reaches chosen and its tooltip shown), a resting mouse (no tip at once, the tip after a moment, gone when
  the pointer leaves), a phone (the chips 35px, the strip scrolling, a tap choosing without a tooltip). The
  style rule in an emoji style not every chosen icon has — 7.3.1's Jelly Regular: 32 glyphs in it, 47 in
  Regular — and in 6.7.2's Sharp Solid (all 78). The fallback, the answer's names rewritten on their way to
  the page: a missing name, a run with one missing and an empty list give the tag, the rest untouched; with
  the tag missing too, the category's own first icon. The Font Awesome settings read in Settings → Emoji &
  emotes. 112 checks, 0 failed, twice (the second with each page cleared of the category remembered before
  it loads: the room's picker reads it as the page mounts it). The sheets of every chip, idle and chosen,
  with both languages' tooltips, and the pickers: `scratchpad/shots1710/emoji-chips-*.png` (untracked —
  Pro glyphs).
* `scratchpad/shots/settings_groups_check.js` (49 checks, 0 failed): the Emoji & emotes chip in both
  languages and first by its own section; every one of the ten settings in its section there and none left
  in Shoutbox; found by a search in English and in Polish under the new chip; `#section-emoji`,
  `#section-emotes` and the two old blocks' ids opening under it, in view; Shoutbox's line leading on.
  `settings_hit_check.js` (61 checks, 0 failed): "Emotes and stickers" is a section's name now, marked
  whole and clear of the icon its heading starts with; the icon-led block of the "icon" search is
  Shoutbox's line. `picker_everywhere_check.js` (66 checks, 0 failed) reads "Beyond the shoutbox" in its new
  section, under the new chip. `icons_align_check.js` on the page whose icons moved (Settings, in Bootstrap
  Icons, Free 6.7.2 and 7.3.1 and the owner's Pro 6.7.2 and 7.3.1, English and Polish, desktop and phone):
  0 failed, every icon beside words within 0.71px of level — the new headings' and Shoutbox's new line's
  among them. `langswap_check.js`, `polish_check.js` and `iconpack_check.js`: 0 failed.
* `tests/emoji_test.php` (95 checks with the owner's 7.3.1 installed, 90 without it), a new section 5b: the
  file (an entry for every category and no other, runs of at most three, names only, a generic fallback),
  its reader, the answer carrying each category's icons (with the package: every category's, the
  alphabet's A B C, the fallback), the version, the picker's rule and the chip's name and tooltip, the
  filled chip; the three Font Awesome controls in Settings → Emoji & emotes and none in Shoutbox.
  `tests/admin_access_test.php` (312 checks): the group right after Shoutbox, its two sections, every
  emoji and emote setting in them, the link to any id inside a section. `tests/iconpack_test.php` (201):
  the picker's payload with the icons. `tests/shout_test.php` and `tests/shout_emotes_test.php`: the two
  permission folds, the manager in its new section (their changed checks run on their own here — the
  tests empty the room's tables, which this environment keeps). `tests/lang_test.php` 218 checks.
* `scratchpad/shots/csrf_everywhere_check.js` (new; in the sweep after `profile_votes_check`), signed in as a
  member with **the page's own token source only**: every hidden token field a template still carries is
  taken out of the page as it is parsed, before any script can read it, so the layout's meta is the one thing
  left. A guest on nineteen page types: one meta, in the head, the session's token. Votes, thumbs and stars,
  from the Info panel on the search page, on the profile (opened from a favourites row) and on the account
  page (from its favourites tab): cast — pressed, filled or marked, the tooltip — pressed again — taken back,
  gone from the database, nothing pressed — the other thumb, the other values, each step read back from the
  database; the front page has no Info panel (said, not skipped). Then every other POST each page type offers,
  through its own buttons: the search page's star, "+" (a list made, the torrent put in and taken out),
  Refresh, a description's Preview and submission; the profile's star and its description; somebody else's
  profile — befriend and take it back, block and unblock; the account page's notifications, mail preference,
  time zone, a privacy switch, the second factor, "sign out everywhere else" and the account form (each with a
  wrong password: the server checks the token first, and "wrong password" is its word that the token was
  right), a picture upload (multipart), a list made and deleted, a message's Preview and send, the sounds,
  the interface language and back, sign-out; the front page's shoutbox — the Preview (the widget's
  `data-csrf` names a field that is gone, and the page's token goes instead), a shout posted, edited and
  deleted. A witness reads every POST — the token in its body, JSON or multipart, and its `X-CSRF-Token`
  header — and wants the page's meta in each, and no CSRF refusal anywhere: 69 POSTs to twenty-five
  endpoint-and-page pairs, 44 checks, 0 failed (twice). What must not happen for real — sign-out, a
  picture's files, a message to smokepeer, deleting the read notifications — is answered by the check once its
  token is read. Against the scripts from before this change (`CSRF_JS_FROM`, the pages' own fields kept with
  `CSRF_STRIP=0`) it fails as the owner saw: the votes on the profile and on the account page refused
  "Invalid CSRF token" in both modes, and on the search page a second press that does not take the vote back.
* `scratchpad/shots/profile_votes_check.js`: in both modes, the Info panel opened from the account page's own
  likes or ratings — the page whose token was `#account-csrf` — shows the vote pressed (the thumb filled in its
  colour, or the half star marked; `aria-pressed`; the tooltip); pressed again, the vote is gone from the
  database and the list is one row shorter at once, without a reload — and on the profile, for another member.
  `scratchpad/shots/who_check.js`: the reader presses Good — pressed, with its tooltip — and "Who has this"
  lists him with his thumb (26 people); Good again, and he is gone from the database and from the likes' rows
  and count.
* `tests/reputation_test.php` (81 checks), sections 7–9: the new rule's code; taking back against the live
  schema in both modes — only the caller's own row, an address never the vote an account cast from it, an IPv6
  /64 one voter, idempotent, refused wherever a vote is and in a vote's own words, counted again in stars (2.60
  where a thumbs count would have written 0), `repClear()` with the configuration; the budget — ten looks spend
  nothing, three actions spend three and the fourth is refused, leaving the vote; and `rate_hash` as requests
  with a session and its token: the old shape still casts, `op: remove` takes back and pays a vote's CAPTCHA
  points, asked again it changes nothing, a wrong or missing token is refused, an unknown operation is a 400.
* `tests/csrf_token_test.php` (new, 29 checks): the meta in the layout — in the head, only with a session, no
  script; the helper's three sources in their order; every token the public scripts send (32 places) is the
  helper's answer, and no public script reads a page's own id; the pages as the local site renders them — a
  guest on 23 addresses, a member on the search page, two profiles, the account page and the front page with
  the shoutbox — one meta each with the session's token, the old ids holding the same value; another session,
  another token; the server takes the token the page publishes and refuses another session's and none.
* `tests/profile_votes_test.php` (150 checks) and `tests/who_test.php` (101): a vote taken back leaves the likes
  or ratings list and "Who has this" at once, for the owner and for a reader, in both modes. The check in
  `tests/shout_test.php` on the editor's token follows it to `csrfToken()` (evaluated on its own: the test
  empties the room).
* `tests/comments_test.php` (new, 146 checks): the schema both ways on a scratch database — fresh, and an upgrade
  from 82 (the table, the two columns, the grant once, the two statements of the table identical); the settings
  in their four places, clamped; the permissions, the presets and accounts off; the renderer on twelve hostile
  inputs, read back as a DOM (only the tags and attributes it writes, no `javascript:`, a quote one level, code
  literal, a link only where one may be); the limits (visible characters, the source, lines, links, depth); a
  post's refusals in their order (the token, the feature, the hash, the reader's view of it, the permission, the
  mute, the CAPTCHA, the hour, the words); guests — the tag, a CAPTCHA every time (through an injected verifier),
  held, let through and only then told; the thread — its pages and "earlier", the reader's zone, blocked authors
  folded; correcting and taking back — the windows, the mute, a moderator with a reason (audited, the author told)
  and the silent removal; who is told — every reason once, by the strongest one switched on (mentions off, yet
  in the thread: told as a participant), never across a block, in the recipient's language, never somebody who
  may not read; the pulse's counts and the two sounds; a notification's link (an off-site one dropped); an
  account going; the six endpoints through a child process. It leaves nothing behind — its accounts, group,
  sticker, rows, audit lines and rate keys removed, the table's counter back.
* `scratchpad/shots/comments_check.js` (new; in the sweep after `descriptions_check`), three people in a real
  browser under an enforced policy: smokeuser writes the first comment — bold, an emoji and an emote from the
  picker (no Stickers page; Esc closes the picker, not the panel), `@ccheck_m` offering the name (Enter takes it;
  Esc on the list closes the list and leaves the panel open), the counter's visible characters, the server's
  Preview — and Ctrl+Enter sends it: the row as described, the emote at the size of the words; a member whose
  page is open with sounds on hears the MENTION sound on one pulse (the badge 1, the notification's chime not as
  well), follows **Show** to the comment, lit and in view, the notification read; answers, and is told of the
  answer back in Polish, their language, with the COMMENT sound; the author corrects one ("edited") and takes one
  back with two presses; a moderator removes one — a reason asked (Esc puts the box away and leaves the panel
  open), given; the author told with it, one audit line;
  a block folds the blocked author's comments and silences a mention across it; the account page's four switches
  (mentions off: told as a participant; on again) and the two sounds on the Sounds tab, then the live switch to
  Polish; a mute (why and until when, Delete kept and Edit gone, 403 from the endpoint); a link (its `rel`, the
  "you are leaving" question, Esc closing only it), then links off (no button, Ctrl+K putting none in, the old
  link its text, a new one refused with the reason); a phone in Polish; the live switch redrawing the section;
  a guest told the comments are for members. 64 checks, 0 failed; no script error, no policy violation.
* Pinned things that moved with it: `tests/emoji_test.php` (the picker's contexts), `tests/admin_access_test.php`
  (the group's name, its Comments section), `tests/groups_matrix_test.php` (the presets, the v83 grant),
  `tests/pulse_test.py` (the pulse's two new numbers — numbers still, never content), `tests/sounds_test.py` (the
  five events and their defaults, comments switched on by the test itself) and
  `scratchpad/shots/settings_groups_check.js` (the chip's name in both languages).
* `tests/content_reports_test.php` (new, 119 checks): the schema both ways on a scratch database — fresh, and an
  upgrade from 83 from the same definitions — the one-open-report key (a second open report refused, a new one
  after the first is closed allowed) and the grant once (members report, moderators the three queues, never the
  message queue, guests nothing; taken away, it stays away); the permissions, the presets, accounts off, the
  Reports page opening for any of its queues and each tab only its own; a report's refusals in their order (the
  token, the kind, an account, the switch, the permission, nothing there for this reader — a hidden torrent, a
  removed comment, the site's line, an unpublished description —, your own, the reason) and what is kept (the
  words as reported, the author found); "already" and the hourly limit (the one call, and "already" spending
  nothing); the flags on the rows the pages are drawn from; the queue — the kinds per session, the open targets,
  GROUPING, the five filters, the renderers escaping, the words as reported beside changed ones —; every action on
  every kind, silent and loud: the reporters told in THEIR languages with the answer, the author nothing or ONE
  warning with the reason and its number, the removal through each kind's own function, the audit lines; the
  guards (staff, yourself, a ban without a date, a reason for a warning, changed words, no account, gone); the
  warnings counted and shown and nowhere public; an account going; the endpoint files as requests in a child
  process — the report, the tabs' counts, a filtered page, an action, and the message card's silent, loud, Warn
  and no-mode answers. It leaves nothing behind.
* `scratchpad/shots/reports_check.js` (new; in the sweep after `msgreport_check`), under an enforced policy:
  a member reports a comment (the flag named and explained, the box in the row, Esc leaving the panel open, an
  empty reason refused, Enter sending, the filled flag, the database row), the description and a shout on the
  front page; reloaded, still "Reported"; a second member in Polish; no flag on the author's own words. A
  moderator's panel — through the public sign-in — has Comments, Descriptions and Shouts with their counts and no
  Messages; one card with two reports; the filters narrow and let back; Close with an answer (both reporters told,
  each in their language; the author nothing), Reopen; Remove as a warning (refused without a reason, then the
  author's ONE warning in Polish with its number, nobody who reported named); Warn about the description (the
  second warning); a silent silence and a silent removal of the shout. The author's account page shows the two
  warnings marked; the owner's message card has the choice and Warn (refused without a reason, then the third
  warning); a phone: the box and the panel's card inside the screen. 53 checks, 0 failed.
* Pinned things that moved with it: `tests/groups_matrix_test.php` (the member's `content.report`, the moderator's
  six, the v84 marker), `tests/avatar_names_test.py` (a shout row's exact set of fields: + `can_report`,
  `reported`) and `scratchpad/shots/descriptions_check.js` (the description's actions end with the flag for a
  reader who may report).
* Found by the neighbours' checks on the way: `scratchpad/shots/shout_check.js` compares a row the poll appends with
  the one the page drew — with the flag, a plain member's view of somebody else's line has a controls group too,
  and the server wrote that group with the template's indentation between its buttons where the script writes
  none (whitespace a flex row never shows, but the live language switch pairs a row's nodes by position): the
  widget now writes the group as the script does, nothing between the buttons. And `polish_check.js` hovered the
  Info panel's hash Copy where it had been measured — below the fold of its 900-pixel window since the comments
  section stands between the rating and the technical record — so the pointer landed outside the page: it scrolls
  the button into view first.
* `tests/antispam_test.php` (new, 103 checks): the table on both schema paths from one definition (a fresh install,
  and an upgrade on a scratch database — with no table at all first, where the layer must refuse); the layer's 40
  settings and `rate_limit_pm` in their four places, clamped, and the five `recaptcha_on_*` defaults; the ladders
  as numbers (the room exactly as sketched, the floor, a new account's factor) and live — the burst, each step, the
  top repeated, the quiet reset, the layer off leaving the room's old wall and nothing else — time moved by
  rewriting the row's instants, never slept; the CAPTCHA — three hits at the top, refused attempts counted, a bad
  token and a good one, the ease back to the first step, guests every time, no provider and the pauses alone; new
  accounts — stricter pauses, links drawn as words in the room, a comment, a list and the profile by when they
  were written, staff never new; duplicates and the messages' spread; the new-conversation limits (hour, day, a
  reply never counted); staff exempt, and not when the site says so; corrections (and one made with no gap still
  remembered); a reservation handed back; failing CLOSED on every error; the audit once an hour; **two requests
  racing** in two child processes — one line through, never two; the endpoint files as requests; every writer
  endpoint in `api/` asking the layer (read from the files); the leftovers ("@name.", the votes' CAPTCHA, the
  sounds test's cascade); the pages' half. It leaves nothing behind.
* `scratchpad/shots/antispam_check.js` (new; in the sweep after `reports_check`), in a real browser under an
  enforced policy, with accounts of its own: **the room's ladder** for a month-old account — three lines through,
  the fourth refused (429, the first step, the time in the sentence), Send counting down on itself (disabled, the
  seconds drawn by the stylesheet and no digit in the button's text, the sentence beside it, the words kept), a
  second and a half later less, Enter in the box asking nothing, Send back by itself at zero with the words still
  there, sent then, the next pause the second step (15 s), and — the row's clock moved back two minutes — a line
  going at once as the first of a new streak; **a guest's comment** with Cloudflare Turnstile's published test keys
  — the page carrying the provider, Send opening the site's box BEFORE anything is posted, the widget passing and
  the SAME comment going once with its token, held for review, and a POST with no token answered 428 with the
  sentence, the provider and its public key (never the secret); **a new account's burst** — two conversations
  started, the third stopped with the new-account sentence and Send counting the hour down, nothing written to the
  third person, a reply in a going conversation not paced; **links as words** — the new account's line drawn with
  its link as words for its author and for another reader, the month-old account's a link with the site's rel and
  target. No script error, no policy violation; everything put back. 29 checks, 0 failed.
* **Every test and check that writes faster than a person now switches the layer OFF explicitly** (the project's
  rule: a switch a check leans on is stated, never inherited) — it is on as shipped, and the smoke accounts the
  checks sign in as are new accounts (links as words): `tests/comments_test.php`, `content_reports_test.php`,
  `lists_test.php`, `profile_bio_test.php`, `shout_test.php` and their child runners' settings;
  `tests/shout_test.py`, `shout_emotes_test.py`, `content_test.py` (saved and put back);
  `scratchpad/shots/shout_check.js`, `comments_check.js`, `lists_check.js`, `polish_check.js`,
  `profile_bio_check.js`, `csrf_everywhere_check.js`, `descriptions_check.js` and `content_check.js` — the eight
  that failed with the layer on (a link drawn as words, a comment or a description paused, a list or a vote
  refused), each put back as it found it. A guest's CAPTCHA is a switch of its own and still asks in
  `comments_test.php`.
* Pinned things that moved with it: `tests/content_reports_test.php` (`contentReportFloodCheck()` takes the request
  and hands back a reservation), `tests/reputation_test.php` (the vote's CAPTCHA is the layer's; `rate_hash` asks the
  refusal three times without spending; its runner loads the layer as `api.php` does), `tests/profile_bio_test.php`
  (both renderings say whether the links are words); `includes/lists.php`, `people.php`, `reputation.php` and
  `content.php` load the layer themselves, as the room's, the comments' and the reports' modules do, so an endpoint
  on it works wherever its module is loaded from (`tests/audit_lists_test.php`'s runner stopped on it).
  `tests/shout_emotes_test.php` counted `approved_at` in the whole schema file — the comments' table has one of its
  own — and counts the emotes' two CREATEs and their ALTER now. The anti-spam switches sit before the "who has this"
  pair in the save's list, where neither `who_test.php`'s pin nor `comments_test.php`'s is cut.
* `tests/shout_test.php`: emptying the room also forgets this run's accounts in the layer's table — WHEN somebody
  last spoke is the layer's to know now, not the newest line in `shouts` — and its accounts' rows go at the end.
  The test empties `shouts`, so it was run whole on a scratch copy of the local database (a dump of it, and a copy
  of the tree pointed there): 358 checks, 0 failed. `tests/sounds_test.php` deletes its accounts through
  `userDeleteCascade()` in all four places (93 checks): it left group memberships pointing at nobody.
* The neighbours, run after it: comments 145, content_reports 119, lists 114, profile_bio 114, reputation 81,
  admin_access 312, lang 226, favourites 108, who 101, profile_votes 150, usermedia 159, install 36, audit 42,
  audit_fixes 56, audit_lists 40, shout_emotes 184, emoji 90, people 100, content 199, csrf_token 29, sql_safety 8,
  richtext 121, sounds 93, groups_matrix 61, icons 130, iconpack 202, version 24 and fifteen more — 0 failed each;
  Python: content 18, pm_delete 21, audit_lists 19, avatar_names 44, message_report 17, pulse 14, sounds 45,
  usermedia 61, guest_pages 42, csp_headers 8, ui_traffic_nav — 0 failed each. In the browser: shout, comments,
  lists, layers, who, people, msgreport, reports, pulse, polish, emoji_picker, picker_everywhere, profile_bio,
  profile_head, profile_votes, csrf_everywhere, account_width, descriptions, content, fav, settings_hit,
  settings_groups, langswap, captcha, security, sounds and icons — 0 failed each; verify_all 52 renders, 0 problems.

## [1.70.0] — 2026-09-28

The owner's list after a few days with 1.69.0, as one release. It opens with three small things he
found while using it: ticking a search option — "Search inside messages", "Also search file names" —
with nothing typed reloaded the list for nothing, and the list flashed; the browser drew its own parts
of the site's form controls for a light page, so the Likes filter's spinner was two white boxes on the
dark one; and after a language change that reloaded the page, the page glided for almost two seconds to
the place it was keeping for the reader instead of simply being there. Schema 77, unchanged by these
three. Then Font Awesome in the shoutbox's picker as the owner expected it to be: his site runs on his
Pro 7.3.1 with the picker's Font Awesome on "mixed", he took that to mean any icon in any style, and
found only the faces — so the operator now chooses how much of the package the picker offers: the
faces alone, as before; the faces, and a search that finds every icon; or every icon, on the pages of
Font Awesome's own categories. Schema 78 is that choice. And the picker is no longer the shoutbox's
alone: every editor on the site has it — the message composer, a torrent's description (the first one
and a proposed rewrite), the whitelist form, the profile's description — and the room's emotes and
stickers are drawn where those texts are read. Schema 79 is the switch for that, on by default. And a
list gets a description: the card's "Rename" is "Edit" now, a window with the name and the description —
BBCode or Markdown, with the emoji, the emotes and the stickers — saved in one request, and drawn under
the list's name when it is opened. Schema 80 is that description's column, its format and its length.
And a torrent's description gets what the owner asked for next: it can be deleted — its author's own, or
anybody's published one by a moderator — its author can keep their name off it (the page says "a
member"), "Edit" opens the editor with the text as it stands, and an applied edit keeps the author and
credits the editor with the share of the text it changed: "Description by TryHackX (first) → dominikk26
(25% edit) → Majkel (6% edit)". The descriptions a member wrote are listed on their account page and, if
they say so, on their profile. Schema 81 is the credit chain, a proposal's kind, the two switches, the
setting and the three permissions. And last, "Who has this" answers the whole question the owner asked
of it: beside who keeps a torrent in their favourites, who liked or rated it — with the vote — and the
public lists it is on, three sections that load twenty each the moment it opens, each with its own
search and "Show more". Schema 82 is the consent that puts a member's name among the likes, the two
sections' switches, and two keys for the proposals waiting on a description — whose replaced versions
are now kept ten to a description, where every applied proposal used to keep one more for ever. One Esc
now closes one window, the top one; and the picker check's clock compares the picker with itself. And a
picker asks for the emotes only when it first opens — once a page for each kind of text, the room's
included — where every picker used to ask the moment it was put on the page, opened or not (the account
page asked twice as it loaded).

### Fixed — a search option no longer reloads a list when there is nothing to search

* **What the owner saw.** In the inbox, ticking "Search inside messages" with the search box empty
  refreshed the list; the same on Favourites. Nothing listened to the box itself: what reloaded was the
  checkbox beside it, whose change fetched the list again — the very same list, because an option only
  changes how WORDS are matched, and with no words it has nothing to change. Measured in the browser
  before the fix: one request for every tick and untick with the box empty (and, in the inbox, with a
  single letter, where a search inside the messages needs two), none for clicking into the box.
* **The rule now, wherever a list has such an option**: the option is remembered, and a tick reloads
  only when it changes what is actually asked. Each list keeps the question it last asked — the words,
  and the options that apply to them — and a tick that asks the same question asks nothing; one that
  asks a new one asks at once, taking over a keystroke still waiting to ask rather than letting both go.
  The inbox (`assets/js/people.js`); the account page's and a profile's Favourites, a list's window,
  the account's shelf of lists ("…what is on them", "Also search file names") and the likes and ratings
  table (`assets/js/favourites.js`); the search page's "Also search file names" and "Best match"
  (`assets/js/app.js`), which with an empty box still write themselves into the address — replaced, not
  pushed — so a reload or a shared link keeps the tick; and the panel's Index (`assets/js/admin-index.js`),
  which now does what the Whitelist's box has always done. Filters and sorts — a status, a minimum
  number of votes, an order — narrow or reorder the rows whatever the box says, and still ask at once.
  Uploads, People and the members directory have no search option and are unchanged.
* **The search page sends the file flag only with a query.** Without one the answer is the same rows,
  and the flag alone kept the server from using its cached count of the whole catalogue — a reader who
  had ticked the box and browsed without a query paid for a full count on every page.

### Fixed — the browser's own parts of a control were drawn for a light page

* **The Likes filter's "Min. votes" spinner was two white boxes on the dark page**, and in the panel
  the clock of every time field (Settings: the schedules, the backup time) was black on a black field.
  Both stylesheets left `color-scheme` at `normal`, so everything the browser draws itself — a number
  field's spinner, a date or time picker and its popup, the list a `<select>` drops down, the scrollbar
  inside a field, an autofilled field's tint — was drawn for a light page. Only the account page's
  language select said otherwise.
* `color-scheme: dark` on the root of `assets/css/style.css` (every page it styles is dark; the language
  select's own line is gone) and of `assets/css/admin.css` (every panel page is dark, and Bootstrap's
  light theme sets nothing). Checked on every public page and every panel page with its tabs open: all
  of the thousand-odd controls drawn there are dark now, and forcing the old scheme back changes the
  size of none of them — the scheme is colours only. The panel's number fields (153 in Settings alone)
  and the pagers' page fields keep their spinners hidden as before; the Likes filter's is the one spinner
  the site draws, and it is dark. The Index's row checkboxes — the browser's own, white squares on the
  dark rows until now — are drawn dark as well; every other checkbox on the site draws its own box.

### Fixed — the likes and ratings table's headers can no longer run into each other

* Measured here, they did not touch: from 1920 down to 960px the table is 944–976px wide (the page's
  62em), and in both languages and both modes, on the account tab and on a profile, with Bootstrap Icons
  and with Font Awesome, the closest two headers stand 18–19.5px apart, ink to ink. What does make them
  touch is text drawn larger than the columns were measured for: the columns are fixed, and a header
  that did not fit ran on into the next one. With the header drawn at 16px instead of 12.8 (which a
  browser's minimum font size does) VOTES / GŁOSY came within 8px of the date's header, AVERAGE /
  ŚREDNIA within 4.8px of VOTES, ROZMIAR ran into S / L's side of the gap, and "GŁOS UŻYTKOWNIKA" lay
  over "WYNIK".
* A header that does not fit its column now wraps inside it, where its line runs out — before its arrow
  while the word still fits, inside the word once even that does not (`overflow-wrap: anywhere` on
  `.pv-sort`) — instead of running into the next one. Where it fits, which is everywhere the widths were
  measured, nothing wraps that did not already; at 16px every header keeps to its own column, 16px from
  the next.

### Changed — "keep your place" after a language change lands at once

* With the in-place switch off (`lang_swap_enabled`, the default everywhere but the owner's site) every
  language link is a plain navigation, and the switch itself falls back to one when its request fails,
  runs out of time or finds nothing to change; either way the next page puts the reader back where they
  were. Bootstrap's reboot gives the panel `scroll-behavior: smooth`, so that restore — a scroll of up to
  the whole length of Settings, corrected again on every change of height while the page fills in — was
  an animation, and the page glided for about 1.8 seconds. It jumps now (`behavior: 'instant'` on the
  restore's own scrolls, `assets/js/lang-swap.js`), the settle loop's corrections and the short settle
  after an in-place switch included; smooth scrolling everywhere else is left as it was. With a Settings
  category chosen — whose button scrolls smoothly to the top — the restore still lands in its first frame.

### Added — Font Awesome in the picker: the faces, the faces and a search of every icon, or every icon by category

* **What the owner asked.** "I thought we would be able to use any icon in any style from Font Awesome":
  his production draws with his Pro 7.3.1 and has the picker's Font Awesome on "mixed", and the picker
  had only the `emoji` category — the faces. He asked for three modes: every Font Awesome category a page
  of the picker, its icons in every style; the faces only, as now; and as now, but with a search that
  finds any Font Awesome icon.
* **The setting**: `shout_emoji_fa_scope` — `faces` (as shipped: the picker of 1.69.0), `search`, `all` —
  in Settings → Shoutbox → Emoji in the picker, beside the mode and the style, and like them offered only
  while a Font Awesome Pro package is the icon source; shown only while the faces are on at all
  (`assets/js/admin-shout.js`; hidden, it still saves what it holds). Its hint counts the package's
  catalogue — 4,349 icons in 70 categories with 7.3.1 — and says how the icons beyond the faces are found:
  by their English names and words, or by their category's name in either language. A package without an
  index to read offers the faces only, and the hint says that instead. Schema 78 (the default before the
  bump), the save's allow-list (anything else is `faces`), the Settings search's words, the control; the
  block's own line now says that what can be chosen is Font Awesome — its faces, and how much more of it.
* **The catalogue** (`includes/iconpack.php`, `iconpackCatalogBuild()`): every icon of the package, read
  from the index the package already carries, kept beside its manifest as `catalog.json` — `{format, kind,
  file, count, cats, sets, icons: [[name, label, words, [categories], set], …]}`, one row per icon in name
  order, only names the package's CSS declares. Compact on purpose (the owner's 7.3.1 index gives its
  4,349 icons 57,278 search terms): a label that is only the name read as words is `0` (every label in
  7.3.1, all but 170 in 6.7.2); the terms are one string of their distinct lower-case words without the
  name's and the label's and without a word that only begins another ("arrow" beside "arrows") — the
  search asks whether each word typed begins some word of an icon, and these answer exactly as the full
  list would; categories are indexes into `cats` (Font Awesome's own ids); the set is index.json's (the
  families and styles the icon is drawn in). An icon the index files under no category is put under
  `brands` (a brand logo — Font Awesome files none of them) or `other` (in 7.3.1, `padel`), so the pages
  hold every icon. Pro 7.3.1: 4,349 icons, 68 categories + Brands + Other, 468 KB, **133 KB gzipped**;
  Pro 6.7.2: 3,814 icons, 68 + Brands, 255 KB, **71 KB gzipped**.
* **For the packages already installed** — production has both, installed by 1.69.0 without a catalogue —
  nothing needs installing again: the first request that needs a catalogue (the picker, a token, the
  Settings page) builds it from the metadata the package keeps and writes it (`iconpackCatalogOf()`; built
  in memory for that request when the store cannot be written, and the error log says how to fix it),
  and it can be done on purpose: `php tools/iconpack.php reindex <id>|--all`, and the new "Read the index
  again" button in the package table (Settings → Site → Font Awesome; no password — it changes nothing the
  site loads — and one `iconpack.reindex` line in the audit log's Settings group). All three write the
  same bytes, leave a sidecar that would come out the same alone, and leave the package's files, id and
  hash as they were. The table and `list` say what each catalogue holds.
* **Served** at `api.php?endpoint=shout_emoji&part=catalog&v=<package hash>-c1` to whoever may read the
  room, the file as stored, compressed when the browser takes gzip, and kept by the browser for a year
  (`immutable`: the address changes with the package and with the catalogue's format); 404 with the scope
  at `faces` or the faces off. The faces' answer says where it is, how many icons it holds, and the
  categories' names in the reader's language — seventy names in English and Polish, this project's own
  words (`emoji.facat.*`), sent with that answer rather than carried by every page.
* **Asked for only when the scope needs it**: with `all`, once the picker has drawn its first page (in
  idle time), so the picker opens as fast as it did; with `search`, by the first search; with either, at
  once when Recent holds an icon beyond the faces (drawn from its token until the catalogue says more).
  Measured with 7.3.1 in headless Chrome here, over three runs: the first opening takes 103–127 ms in
  every scope, the faces alone as much as the other two (most of it the faces' answer and the emoji
  file), every one after it 5–7 ms; the page's JS heap is 1.9 MB with the picker open on the faces,
  3.0 MB with the catalogue, 3.2 MB after drawing all seventy categories.
* **Every icon, category by category (`all`)**: one more tab after the faces' pages, "Font Awesome — every
  icon" (the package's own `icons` glyph); its page has Font Awesome's categories as a strip of chips over
  the grid — seventy do not fit a row of tabs — in the reader's alphabet, Brands and Other last, the one
  looked at last remembered by the browser. The chips are the site's pill (as the search page's), the strip
  scrolls sideways and a mouse wheel scrolls it; one Tab stop, Left/Right/Home/End to choose, Down into the
  icons, Up back. A category is drawn in slices like any long page (Brands' 517 icons: 120 at once, the
  rest on the next frames); the `emoji` category's icons are the faces, with their words; the line under
  the grid names the category and counts it.
* **The search finds any icon (`search` and `all`)**: after the emoji, faces and emotes, a "Font Awesome"
  group — every icon that every word typed begins a word of: the exact name first, then a label that
  begins with what was typed, then the label's words, then Font Awesome's English terms, then the name of
  a category the icon is in, in the reader's language or Font Awesome's own id — which is how a Polish
  reader finds the icons beyond the faces ("zwierz" brings the 72 animals); shorter labels first within a
  rank, up to 300, the faces not repeated. The line under the grid gives both counts ("Found: 4 · Font
  Awesome: 7"). The arrows walk over the group's heading: up and down now go to the nearest cell of the
  next row, measured, not counted.
* **Every icon has variants**: held down (a finger or the mouse), right-clicked, Shift+Enter or the menu
  key — the styles the icon is drawn in among those the site loads, the default first by the server's own
  rule: the chosen emoji style when the icon has it, else the classic family at that weight, else the
  first loaded style that has it (`faVariants()` in `assets/js/shoutbox.js`, `emojiFaVariants()` in
  `includes/emoji.php`); a brand logo's is Brands, its only style.
* **The token** (`includes/emoji.php`): `:fa-NAME:` and `:fa-NAME/STYLE:` now name any icon of the active
  package — the allow-list is its catalogue, so a name its CSS declares that its index does not describe
  (an old alias) stays the text it was typed as, as does any name the package does not have — and
  whatever the scope: the scope is how the picker offers icons, not what a stored message may show. A
  style counts only among the icon's families and the styles that load. Such an icon is drawn as
  `<i class="fai …">` in the colour of the words round it (a face stays `fae`, an emoji's yellow), with
  `role="img"` and its label. Where it cannot be drawn — the faces off, another icon source or Bootstrap
  Icons while Settings still names the package, a style it lacks or that does not load, every e-mail —
  it is its label in brackets, `[Rocket]`; a face keeps its ordinary emoji. The grammar takes up to ten
  parts (Font Awesome's longest names have nine), in the script's twin too, and Recent keeps entries of
  up to 96 characters.
* **Found on the way**: a line of nothing but such icons was dropped by the paragraph pass as empty —
  1.69.0 had taught it the faces only (`includes/richtext.php`).

### Added — the emoji, the emotes and the stickers in every editor, not only the shoutbox

* **What the owner asked.** "I would add the emotes and stickers to messages too, and to descriptions,
  and to the proposals that edit those descriptions, and to the description editor." So: the picker in
  every editor a member writes in, and the room's `:code:` emotes and stickers drawn wherever those texts
  are read — a private message's thread, the Info panel, the panel's review queue, a profile.
* **One picker** (`assets/js/emoji-picker.js`, `window.EmojiPicker`). The shoutbox's, moved out of
  `assets/js/shoutbox.js` byte for byte and taught four things: which context it serves, an id of its own
  (`<textarea id>-picker`; the room keeps `shout-picker`), a panel that floats where an editor needs it
  to, and a sticker that goes in as its code where there is nothing to send it with. The room mounts it
  exactly as it did — its host, its ids, its sticker sender, its requests — and looks and behaves as it
  did. `templates/layout.php` loads it on every public page, before `app.js`.
  * `EmojiPicker.mount(opts)` — the picker on any button: `{button, host?, insert(text) → bool,
    sticker(code)?, emotes, stickers, emotesPage?, files, fa, faVer, for?, id?}`; the handle has
    `close()`, `say(text)`, `destroy()` and the checks' `state()`.
  * `EmojiPicker.attach({textarea, button, data?})` — what an editor calls: the settings from the
    button's `data-emoji-*`, whatever is picked put in at the caret. `window.RichText.mount()` calls it
    for a `<id>-emoji` button in the editor's toolbar, so the whitelist form, the Info panel's
    description editor and the message composer needed only the button; the profile's editor
    (`assets/js/profile-bio.js`) builds a sixth button of its own. A list's description editor (1.70.0 D)
    draws `emojiPickerButton($db, $cfg, $baseUrl, 'list', '<id>-emoji')` in its toolbar, or calls
    `attach()` with `emojiPickerAttrs(emojiPickerData(…, 'list'))`.
  * `EmojiPicker.isToken(text)` (the spacing rule the room's insert and every editor's share) and
    `EmojiPicker.forgetEmotes()` (the emotes page, after an upload or a delete).
* **The button** is the last group of each toolbar, the site's smiling-face icon, lit while its picker is
  open, and titled with what it offers there — "Emoji, emotes and stickers", "Emoji and emotes" (a
  profile), "Emoji" (the emotes kept to the room); `emojiPickerButton()` in `includes/emoji.php` draws it
  with what THIS reader may use in THIS context. The format select keeps it: the toolbar's group-hiding
  now hides only groups of syntax buttons (`assets/js/app.js`).
* **What a pick does**: the character, `:code:` or `:fa-NAME:` at the caret, a token spaced from the words
  round it, nothing past the box's own maxlength (the picker says why instead of cutting the text), and an
  `input` event — the counter, the Preview, the profile's live count follow a pick as they follow a
  keystroke. The picker stays open for the next one. A sticker in an editor is its code (the room sends
  one the moment it is picked) and Recent, which is the room's row too, never holds one.
* **Where it opens.** The room's panel is absolute in the field's wrapper, as it was. An editor's box
  clips what is inside it (`.rt-editor` rounds its corners with overflow hidden; the Info panel's body
  scrolls), so there the panel FLOATS: `position: fixed` against its button by the room's own rules
  (right edges together, above when it fits, inside the window), in the button's dialog — the Info panel
  keeps its picker and its `aria-modal` — or on the page, above every overlay (z-index 1300), placed again
  as the page or the panel scrolls, and closed when its button leaves the window, is not drawn (the editor
  on Preview) or is gone (an editor drawn again replaces its picker: one panel per id). Esc is the
  picker's alone: its capture listener keeps it, so the Info panel no longer closed with the picker.
* **Its data by context**: `for` on `api/shout_emoji.php` and `api/shout_emotes.php` — absent, the room's
  answer and gate as before; `message` (`pm.send`), `description` (`content.submit` or `content.propose`
  — a guest by the guest group's own grant, the whitelist form being public), `bio` (`profile.bio`),
  `list` (`lists.use`) — `emojiPickerGate()` in `includes/emoji.php`. A feature switched off is 403
  `disabled`, somebody signed out where an account is needed 401, any other `for` 400. The caching is the
  room's: the faces' answer a day under its fingerprint, the catalogue a year, the emote list never. A
  context's emote list is what that context draws (`emotePickerRows()`): approved, switched on, the
  stickers only where they are drawn as stickers, no upload limits. A reader a context refuses is offered
  the ordinary emoji alone, and the picker then asks the server nothing. Font Awesome's faces and icons
  are offered in every editor whether the room is on or not — their tokens were already drawn everywhere.
* **Drawn where they are read** (`emoteRenderHtml()`, `includes/emoji.php`): the third token on the
  walk the other two take — the text between tags only, so an address, a class or any attribute keeps its
  bytes and text in `<code>`, `<pre>`, `<kbd>`, `<textarea>`, `<script>` or `<style>` stays as typed;
  only a code the context's map names; every attribute escaped; the address held to the room's image
  endpoint (`emoteSafeUrl()` — anything else and the token stays text), which is unchanged. The map
  (`emoteMapFor()`, `includes/shout.php`) is shoutEmotes()'s rows, cached per request — ONE query however
  many messages a thread shows — switched on and approved: a row somebody switched on by hand without an
  approval is not drawn outside the room (the room keeps its own rule). `richtextRenderIn($for, $db, …)`
  is the renderer and then the stage — after it, on purpose: a description's picture limit counts the
  renderer's `<img>`s and an emote is a word, not one of them, and the mail renderer calls the renderer
  alone, so in an e-mail a token stays its code. Through it: a message (the thread, the panel's reported
  messages — `pmRenderBody(…, $db)`), a description (the Info panel and the panel's detail panels —
  `richtextContentFor()` — and both sides of a proposal in the review queue), the Preview of either.
* **At what size, per context, and why**: a message — the emote inline, the sticker at the room's size
  (128 px): a conversation with one person is what a sticker is for; a description and a list's — the
  sticker bounded (96 px, `.rt-sticker-small`): a page about a torrent, read in the Info panel down to a
  phone's width, where a picture belongs beside the words; the profile's description — a sticker drawn as
  an emote, and none offered: a line or two under a name; an e-mail — the code. With stickers switched off
  a sticker is an emote everywhere, as in the room. `.rt-emote` / `.rt-sticker` in `assets/css/style.css`
  and in `assets/css/admin.css`, for what the panel reviews.
* **The profile's description** (`includes/profilebio.php`): its allow-list gets the token stage and
  nothing else — a Font Awesome icon's `:fa-NAME:` and the emotes, drawn on the finished text
  (`profileBioTokens()`); the five tags, the link rules and the count are what they were (a token counts
  as the characters it is typed as). The descriptions' `:shortcode:` emoji stay out — the picker never
  writes them.
* **The switch**: `emotes_everywhere` (schema 79, on) — Settings → Shoutbox → Emotes, "Beyond the
  shoutbox", beside the emotes' own switch (the four switches share a row now, the three numbers the
  next). The emotes are the room's — its store, its manager, its page — so they reach the other texts only
  while its emotes are on; off, a `:code:` there is the text it was typed as and those pickers offer the
  emoji alone. The room is not affected by it.
* **Found on the way**: the Preview of a description answered 403 to a reader who may propose a rewrite
  and not submit one, in the very editor they were given for it — either permission is enough now
  (`api/richtext_preview.php`).

### Added — a list's description, and "Edit" where the card said "Rename"

* **What the owner asked.** "Add descriptions to lists — the Rename button I would rename to Edit, and
  there a window for editing the description, with BBCode, Markdown and of course the emoji." A list
  has had a `description` column since 1.44.0 (500 characters, plain text, an API op nothing on the
  page ever called); now it is written, and read.
* **Edit** (Edytuj) on every list card of your own — the account page's Lists tab and your own
  profile — where Rename was. It opens a window in the site's style (`templates/partials/list_edit.php`,
  drawn by the server with an id on everything that carries a word, so the in-place language switch
  swaps each by name): the name (80 characters, the slug never rebuilt — a link somebody was given
  keeps working) and the description in the site's shared editor — Write and Preview, the format
  select, the rail, the counter (`window.RichText.mount()`, `assets/js/app.js`) — with the picker on its
  last button (`emojiPickerButton(…, 'list', …)`: emoji, Font Awesome's faces, the room's emotes and
  stickers) and no picture button. Save sends name, description and format as ONE request; Ctrl+Enter
  saves, Enter in the name does. Leaving it follows the picture editor's rules (1.64.0): with nothing
  changed, Cancel, Esc and the backdrop — a press that started on it — close at once; with something
  changed they ask "Discard the changes?" in the footer (Esc again is "keep editing"), the × arms
  itself for three seconds and says so beside itself, and leaving the page asks the browser's own
  question. Esc is the window's last: the picker open in it and the "you are leaving" dialog a link in
  the Preview opens take theirs first (one listener on the window, in the capture phase, that stands
  aside for both). The card's other buttons are as they were; `renameInline()` is gone.
* **Schema 80** (`includes/schema.php`): `user_lists.description` TEXT (NULL or '' is none; MySQL 5.7
  cannot default a TEXT), `description_format` ENUM('bbcode', 'markdown') DEFAULT 'bbcode' — the site's
  two syntaxes, `richtextFormats()` — and `lists_desc_max` (1000) in Settings → Profiles → Lists, a row
  of its own under the four the section had: the longest description in the characters a READER sees,
  clamped 50–5000.
* **What counts** (`includes/lists.php`): the profile description's rule — the words, not the tags that
  format them; an emoji is one character; a token (`:fire:`, `:fa-rocket:`, an emote's `:code:`) counts
  as it is typed; a run of white space is one. It is counted by a strip of the syntax
  (`listDescStrip()`, `listDescVisible()`) rather than a render, because the browser has to count it
  the same way while somebody types: `assets/js/favourites.js` carries the twin, pattern for pattern,
  and the shared editor takes it as a new `measure(text, format)` option — so the counter under the box,
  the Preview's (`richtext_preview` with `for: 'list'`) and the save give one number. The text as typed
  has a ceiling of its own, four times the limit and never more than 16 000 characters (the box's
  maxlength, and the save's), so tags cannot store a novel in a field of a thousand.
* **The descriptions already written stay what they said.** They were plain text, shown with
  textContent; handed to the renderer as BBCode, `[b]` would have become bold, `:fire:` an emoji,
  `:fa-face-grin-tears:` a face, `:flame:` an emote and a pasted address a link. The site's BBCode has
  no escape, so the migration uses the one it can have — U+2060 WORD JOINER, which draws nothing, put
  where each of those rules would begin: after a `[` that opens something tag-shaped, after the `:`
  that opens something token-shaped, between `http(s)` and `://` (`schemaListDescPlainToBbcode()`; the C0
  controls go, being no text and, three of them, the renderer's own placeholders). Rendered, such a text
  is exactly its words; it counts as its words; a second rewrite changes nothing. The step is guarded on
  the ABSENCE of the format column — while that is missing every description is known to be plain,
  and a marker would be wiped by the local bootstrap and run the escape over real markup — and ordered
  so that it can stop anywhere: the column made TEXT (a rewrite can outgrow 500), the rewrites (holding
  `updated_at`, so no list moves on its owner's shelf), the format column last (`schemaListDescMigration()`).
* **One request** — `op: 'edit'` on `api/user_lists.php`, `{id, name, description, format}`, judged by
  `listEditRequest()`: after the gates every list write has (the token, a session, `lists.use`, the
  hourly `listedit` limit, the row found by id AND owner) — the name (required, 80 at most: refused, not
  cut as the old rename cut it), the text's size before anything walks it (64 KiB a request, 413),
  UTF-8, the format among the site's, and then only if the description CHANGED: a moderator's mute (the
  profile description's rule — clearing never waits), the visible limit, the typed ceiling, no picture,
  the description's link limit. What is not changed is not judged again, so a rename of a list written
  under a longer limit, or in a syntax since switched off, is still a rename. Kept cleaned: one kind of
  line break, no bidi override, no control character; U+2060 stays (it is the escape above). A request
  that says nothing about the description does not wipe it. `describe` is the same request without the
  name — no second path writes a description any more; `rename` is untouched.
* **Where it is read.** The card shows a line or two of it (`.list-desc`, two lines by the stylesheet):
  a plain excerpt of what a reader sees, [hide] and spoiler bodies left out, the shortcodes as their
  emoji, a Font Awesome token as a mail shows it, an emote as its code (`listDescExcerpt()`, in the
  shelf's answer). The list's window draws the whole of it under the name, above the rows
  (`description_html` in `api/user_list_items.php`: `listDescRender()` — the description renderer, the
  room's emotes inline and its stickers bounded to 96 px, as C made it for a list). A public list shows
  it to another member exactly where the list's five gates already show the list; a private one's words
  are in no answer anybody else gets. Nothing new is exposed: the text rides the two answers that were
  already gated.
* **No pictures from elsewhere, and why stricter than a torrent's description**: a torrent's description
  waits in the review queue before anybody reads it; a list's is published by its owner the moment it is
  saved, and a picture from another host is a request from every reader's browser to whoever runs that
  host. So `[img]` and `![](…)` are refused by the save, the editor offers no picture button, and a row
  that has one anyway (a database client, an old backup) draws none. The room's emotes and stickers are
  the pictures a list may have: the site's own, approved, from its own endpoint. Links keep the
  description's rules — http and https, `rel="nofollow noopener noreferrer ugc"`, a new tab, the leaving
  warning, `desc_max_links`.

### Added — a torrent's description: delete, the author's name, Edit, co-authors, and "Descriptions" on the profile

* **What the owner asked.** Once a description was added it could not be taken down: a permission and a
  way to delete it. A privacy switch for "Description by" — the member decides whether their name shows.
  Like the likes and the favourites, a list of the descriptions a member added, on their profile, with a
  privacy switch of its own and the setting above everything, paged, in the site's style; and a line
  telling the writer that a description makes them publicly visible when their switch says so. Beside
  "Propose a rewrite", an "Edit" that puts the text into the editor: a rewrite changes the author, an edit
  shows the editor beside the author as a co-author with the percentage they changed — no history of
  versions kept, the comparison made when a moderator accepts it, each later edit compared with the text
  as it then stands: "TryHackX (first) --> dominikk26 (25% edit) --> Majkel (6% edit)".
* **Delete** (Usuń opis), in the Info panel beside Propose and Edit, for whoever may: its author of record
  with `content.delete_own` — whatever the state, so a description still waiting or turned down can be
  withdrawn too — or a holder of `content.delete_any`, for a PUBLISHED description of a hash that is not
  banned (what waits is the review queue's business; the admin group's blanket passes it). Both are asked
  of the signed-in ACCOUNT (`contentDeleteRight()`, `includes/content.php`), are no with accounts off
  (where the legacy answer for `content.*` would otherwise have let every passer-by delete), and are
  granted once by v81 — `content.delete_own` to members, `content.delete_any` to moderators only; a
  co-author is credited, not an owner, and gets neither. Two clicks, no dialog, as a list's Delete: the
  first arms the button ("Click again to delete", red) for four seconds, the second posts
  `api/content_delete.php` (the session's token, 30 an hour per account), and the panel is drawn again.
  The words go the way the panel's Clear takes them — text, source link, author and credits
  (`contentDelete()`); every proposal still waiting on them is withdrawn (`rejected`, note "withdrawn: the
  description was deleted") and its proposer told; one line goes to the audit log, `content.delete` in the
  Content group, with the right used and the author; and the author is told when it was somebody else. It
  does not wait for descriptions being switched on: taking your own words down must not depend on new
  ones being accepted.
* **The author's name** (`users.content_credit_public`, ON — names have always been shown there, and an
  upgrade hides nobody's): "Show my name on the descriptions I write and edit" in Privacy. Off, every
  place that credits the member says "a member" — no name, no picture, no link — in the credit line under
  the description, in the Info panel's answer itself (the name is nowhere in it), and their list of
  descriptions is shown to nobody else (the switch beside it says so while the name is off). Their own
  reader is told "a member (you)". The panel's review queue, Rewrites tab and detail panels keep the real
  name, marked "name hidden publicly": moderating somebody is not showing them. Before anything is sent —
  a first description, an edit, a rewrite, in the Info panel and on the whitelist form — the editor says
  so: "A description is public: whoever can open this torrent reads it, with your name beside it —
  'Description by X'. You can hide your name in Privacy." (or that the name is hidden, or, signed out, the
  first sentence alone; `contentPublicLine()`).
* **Edit** (Edytuj), beside Propose a rewrite: the same editor, opened with the text, its format and its
  source link as they stand (`content_edit` in `api/index_info.php`), the other buttons stepping aside until
  Cancel; filed as a proposal of kind `edit` (`wl_content_edits.kind`, `rewrite` by default). Offered for a
  PUBLISHED description to a signed-in member whose account may propose and read it — the prefill is the
  source, and a `[hide]` block's words are in it — and `contentAttach()` asks the same again; an edit that
  changes nothing is refused. The panel's Rewrites tab says which each proposal is — an EDIT pill of its
  own colour, or the REWRITE one — and for an edit "Changes 25% of the text as it stands now. The author (X)
  stays; the editor is credited beside them."; the confirmation and the toast say the share, and the audit
  line records it. An edit of a description cleared since it was written goes in as a rewrite, and the card
  says so.
* **Co-authors: the credit chain** (`whitelist.content_credits`, `hash_content.content_credits`): who wrote
  it first and who edited it since, as compact JSON on the home row (`{"u": id, "k": "f"|"e", "p": share,
  "t": time}`, `contentCreditsEncode()`). A first description starts it with its author; an applied REWRITE
  starts it again with the proposer (who becomes the author, as before); an applied EDIT keeps the author
  of record and adds the editor with the share. Merging: an edit by the member who is LAST in the chain is
  not a new entry — after their own first text nothing is added (the author refining their own words stays
  its first), after their own edit the two shares add up (at most 100); 0% adds nothing; only accounts are
  credited. At most 100 entries (the first and the newest). v81 starts a chain for every described row from
  `content_user_id` — an author whose account is gone as "a deleted account" (u = 0), and that dangling id
  let go — and deleting an account now does the same (`contentForgetAccount()` from `userDeleteCascade()`):
  MySQL 5.7 hands the highest deleted id out again after a restart, and with Delete that account would
  otherwise inherit somebody's words.
* **Under the description**: "Description by TryHackX (first) → dominikk26 (25% edit) → Majkel (6% edit)"
  — "(first)" only when somebody edited it after; the arrow the icon library's (`bi-arrow-right`, Font
  Awesome's in that mode), which a screen reader hears as a comma; each name with its picture, linking to
  the profile where profiles are on; "a member", "a deleted account". More than four fold to the first, a
  "+N" and the last three, and open in place. In Polish "Autorzy opisu: … (pierwsza wersja) → … (edycja
  25%)"; the single line reads "Autor opisu:" now (it was "Opis dodał(a)", which cannot stand before
  "użytkownik" or "usunięte konto").
* **The share** (`contentEditShare()`): the words of the text as it stands (A) and of the edited text (B) —
  the source as typed (formatting a person added is part of what they wrote), line breaks made one kind,
  NFC, lower case, zero-width characters out, split on white space; L = their longest common subsequence;
  **share = round(100 × max(|A| − L, |B| − L) / max(|A|, |B|))**, at least 1% whenever the text, the format
  or the link differ at all, 0% (and no credit) for the same text. 25 words of 100 replaced is 25%; 100
  added to 100 is 50%; 6 of 100 removed is 6%. L is exact: the common beginning and end set aside, then
  Myers' O(ND) difference algorithm, whose cost grows with what changed, not with the text — three words
  fixed across a thousand take milliseconds — within 200 000 steps (texts that differ by up to 631 words);
  past that, which only a text replaced nearly whole reaches, the words both texts have, held under what
  the search proved (at most ~70 ms). Measured again at the moment it is applied, against the text as it
  stands then — the figure the Rewrites tab previews.
* **Who is told**: the editor, that their edit went in and its share; the author, that their description
  was edited — not replaced — and that it is still theirs; an edit turned down says "edit"; the author of a
  description somebody else deleted; the proposers whose proposals a delete withdrew.
* **"Descriptions" — the list** (`includes/profiledescs.php`, `api/user_descriptions.php`,
  `templates/partials/descs_section.php`, `initDescs()` in `assets/js/favourites.js`): a tab of the
  account page right after Likes / Ratings ("Your descriptions") and a section of the profile right after
  Likes / Ratings — the likes table's look and rules: the name with a line of what the description says
  under it (`listDescExcerpt()`: [hide] and spoilers left out), the member's role ("Author", "Co-author,
  25%"), the date, Magnet and Info; headers that sort (name, role, date), a search by name (and a hash
  prefix where the hash is shown), the pager, cards on a phone. The owner sees every state of theirs and,
  beside them, their own proposals still waiting or turned down (the version a rewrite replaced is not a
  proposal and is left out); anybody else only what is published, of a hash that is not banned, a
  registered torrent's only with `whitelist.view`, the hash only with `index.magnet`. Who may see
  somebody's list is `profileDescsShownTo()` — asked by the page and the endpoint alike, one 404 for every
  no: the setting `profile_descriptions_enabled` (Settings → Profiles, a section of its own, on), descriptions
  or source links on at all, profiles on, the reader's account holding `favourites.view_others` and
  `content.view`, no block, an active owner who ticked "Show the descriptions I wrote on my profile"
  (`users.descriptions_public`, off until then, in Privacy right after the likes switch), whose name is
  shown, and whose group GRANTS `content.public` (members, once, by v81; the admin blanket is not
  consent). The chain is JSON and this project reads JSON in PHP: each home is asked for the rows whose
  author is the member or whose chain's text names them (a LIKE on the fixed form, then decoded), at most
  2,000 of each, newest first; the order and the page are then one array's, so the count and the page
  agree.

### Added — "Who has this": the favourites, the likes and ratings, and the lists — twenty at a time each

* **What the owner asked.** "Who has this" should show a person's likes or ratings and their values as
  well, and the favourites and the lists: three sections, loaded by themselves, twenty or so, then paging
  with a search — "all of it according to the users' preferences, the settings and the permissions, of
  course".
* **Three sections, in this order** (`templates/partials/info_overlay.php`, `initWho()` in
  `assets/js/favourites.js`): **Favourites**, as before; **Likes** or **Ratings** — the rating mode's
  word, as the account's tab is named — each name with its thumb up or down, or with its stars, half
  stars included (the likes table's own marks: `thumbFor()`, `starsReadOnly()`, read out as "Thumbs up",
  "3.5 of 5 stars"), the highest first; **Lists** — a list's name, "by" its owner with their picture,
  opening the list on the owner's profile (`#list:<slug>`, the address its Share button hands out), the
  most recently changed first. Each section has its count ("25 people", "3 lists"), asks for its first
  twenty the moment the overlay opens — all three requests at once, each drawn as it lands — and then has
  a search of its own by name (a person's; a list's or its owner's), shown once there is more than one page
  to search, and "Show 20 more" for the next page. An empty section says so in its own words ("Nobody who
  shows their name has liked or disliked this.", "It is not on any public list."), a search that finds
  nobody says that, and the line under the sections says why a section can be empty while many people
  have the torrent. The page carries only the sections this reader may open, and the Info panel draws the
  button whenever ANY of them can show them something — it needed the favourites' list until now.
* **The gates — all of them SQL, so the count obeys them as the rows do** (`includes/who.php`,
  `whoSql()`): the rule 1.69.0 wrote for the favourites (api/hash_favourites.php) — somebody appears only
  when every gate says yes, and any single no takes them out of the rows AND the count ("14 people have
  this, 3 shown" is a leak with a delay). For every section: a group of theirs GRANTS the section's
  permission (userGroupIdsWithPermission(); the administrator's blanket is power, not consent), the
  membership is in force today, the account is active, verified where the site asks for it, and has not
  hidden its profile from this reader (`hide_profile`). **Favourites**: as in 1.69.0 — public favourites
  and the list on, `favourites.public`, the member's `fav_public` and `fav_listed`. **Likes / ratings**:
  ratings and the likes on profiles on (`profileVotesEnabled()`) and the section's switch;
  `rating.public`; the member's `votes_public` (their likes on their profile) AND the new `votes_listed`;
  only a vote cast from an ACCOUNT — one cast from an address is nobody's and never listed — and only a
  value the current mode has: ±1 for thumbs, 1–10 half stars for stars (a mode switch clears nothing;
  a 1 is in both, read as the mode reads it). No score is made of them: each is its owner's vote, shown as
  their own profile shows it — whatever the torrent's number of votes, as there — and the count beside the
  section is of the people named, not a score.
  **Lists**: public lists on and the section's switch, then the five gates a public list has everywhere
  (`tests/lists_test.php`): the owner's group grants `lists.public`, the owner shows the section
  (`lists_public`), the list is public (`is_public`). **The reader**, for every section: signed in, with an
  ACCOUNT holding `index.view` and `favourites.view_others` (asked of the account, as the likes' profile
  section asks it — a panel session in the same browser is not the member's grant).
* **The consent** (`users.votes_listed`, schema 82, 0 for everybody): "Let my name, with my thumb, appear
  in a torrent's "who has this"" ("… with my rating …" in star mode) — Privacy, right under "Show my likes
  on my profile", drawn while the section exists at all; the likes' twin of the favourites' "Let my name
  appear". Both are needed, and the hint says so. Saved without a password like its neighbours
  (`api/user_privacy.php`, which also says whether the section exists).
* **Settings → Profiles**: `who_votes_enabled` — "Likes and ratings in "Who has this"", in the likes'
  section — and `who_lists_enabled` — "Lists in "Who has this"", in the lists' — both on (each shows nothing
  until the feature under it is on); the favourites' `fav_who_enabled` stays where it was. Schema 82 (the
  defaults before the bump), the save's allow-list and 0/1, the Settings search's words in both languages.
* **The endpoint** (`api/hash_who.php`, `hash_who&hash=…&section=fav|votes|lists[&page=][&per_page=][&search=]`):
  GET only; twenty a page, at most fifty, a page past the end empty with the true total (so "Show more"
  never repeats a row); the search's `%`, `_` and `\` are characters, not wildcards. One 404 for every no
  — a section switched off, nobody signed in, an account that may not, a section that does not exist —
  before the hash is even read. An hourly bucket of its own (`hashwho`, three times
  `rate_limit_index_search`: three sections to one look, as many looks as the 1.69.0 overlay allowed), so
  opening the overlay does not spend the reader's catalogue searches. Rows carry a name and a picture's
  address (a vote's value, a list's name, slug and size) — never an id, never a time.
  `api/hash_favourites.php` keeps the 1.69.0 answer, read from the same functions (its lists now obey
  `who_lists_enabled` too), and `listsContainingHash()` is the lists section's first page — one query each,
  not two to keep in step.
* **Measured**: each section drives through its hash's own index — `uq_vote_once` / `idx_votes_hash`,
  `idx_fav_hash`, `idx_item_hash` — and reaches the account by its key (EXPLAIN in `tests/who_test.php`,
  among three thousand other votes and list rows).
* **A live language switch** swaps the server's words by their ids (the headings, the boxes' placeholders,
  the line under them); what the script writes — the rows, the counts, "Show more" — is `data-lang-keep`
  and drawn again from what each section holds, the names already loaded kept. Polish counts sit where
  Polish need not agree with them: "Liczba osób: 25", "Liczba list: 3", "Pokaż kolejne (20)".

### Fixed — one Esc closes one window: the top one

* **What part D found**: over every window but a list's Edit window, one Esc closed the "you are leaving"
  dialog AND the window under it — the dialog heard the key first (in the capture phase), closed, and let
  it go on to the window's own listener. It was the general case: one Esc closed every window of a stack —
  "Who has this" or "Put this in a list" together with the Info panel under it, the Info panel together
  with the list's window it was opened from.
* **The dialog keeps its Esc** (`assets/js/app.js`, and the panel's own copy in `assets/js/admin-common.js`):
  it closes and takes the key, so the Info panel, a list's window — and in the panel the Index's and the
  Whitelist's detail windows, Bootstrap's, which close on any Esc that reaches them — stay open, and the
  next Esc closes them.
* **The windows take turns** (`escLayer()`, `assets/js/app.js` — the Edit window's approach, made one
  helper): an open window listens on the WINDOW, in the capture phase, and acts only while it is the top
  layer — no "you are leaving" dialog and no emoji picker open (each keeps its own Esc), no other window
  drawn over it (a higher z-index, or the same one later in the page) — and the one that acts takes the
  key. The Info panel, a list's window, "Put this in a list" and "Who has this" use it; the Edit window
  keeps its own listener, which did this first. Closing "Who has this" hands the focus back to its button.

### Changed — a description keeps its ten newest replaced versions, and its waiting proposals have a key

* **What part E found**: every applied proposal copies the text it replaced into `wl_content_edits`, as a
  `rejected` row noted "replaced by a later proposal" — a full copy, for ever, and nothing ever reads one
  back; and the count of the proposals still waiting on one description, asked before every new one is
  taken (`wl_edit_max_pending`) and again by a delete, had no key for it, so it read every row the
  description ever had, the copies included. The owner: "we do not keep every edit".
* **Ten** (`contentArchivePrune()`, `includes/content.php`): right after an applied proposal's copy goes in,
  the newest ten copies of THAT description are kept and its older ones deleted — two statements through
  the new key. Only those copies: a proposal still waiting, one applied, one a moderator turned down and one
  a delete withdrew are never touched, nor any other description's copies, and the migration deletes
  nothing — an install's history shrinks one description at a time, as each is edited again.
* **Keys** (schema 82, both schema paths, guarded): `idx_edits_wl_status (whitelist_id, status)` and
  `idx_edits_hc_status (hash_content_id, status)`.
* **What was said and was not true**: "Applying keeps the version it replaces, so it can be undone" (the
  README) and, in the panel, "… so this can be undone", "… you can put it back by accepting it later",
  "… can be undone by accepting the old text back". Nothing reads a kept version back
  (`contentEditById()` takes waiting proposals only); the words now say what is true, in both languages.
* **What an install can lose, at most, on its next applies**: a description with N kept copies loses
  N − 9 at its next apply (the apply adds one and keeps ten); one that is never edited again loses
  nothing. Counted, before upgrading, by:

  ```sql
  SELECT COUNT(*) AS descriptions, COALESCE(SUM(n - 9), 0) AS rows_at_most
    FROM (SELECT COUNT(*) AS n FROM wl_content_edits
           WHERE status = 'rejected' AND note = 'replaced by a later proposal'
           GROUP BY whitelist_id, hash_content_id HAVING COUNT(*) > 9) t;
  ```

### Tests

* `scratchpad/shots/people_check.js`: the inbox's option counted in requests — none for clicking into
  the empty field, none for ticking and unticking it empty or with one letter, exactly one (inside the
  messages, for "th") with two.
* `scratchpad/shots/fav_check.js`, `lists_check.js`, `profile_votes_check.js`, `share_check.js` and
  `panel_hash_check.js`: the same count for every other option — the account's and a profile's
  Favourites, the shelf's two boxes and a list window's, the likes table, the search page's two (and the
  address that still records them, and no file flag without a query), the panel Index's (as the dimming
  of its table, which the live refresh of pending rows does not do).
* `profile_votes_check.js`: the header row at nine widths from 1920 to 960, English and Polish, own and
  somebody else's, both modes, both icon libraries — every pair of neighbours 12px or more apart, none
  past its column, no arrow alone — and again at 1180 with the header drawn at 16px; the "Min. votes"
  field is drawn in the dark scheme.
* `scratchpad/shots/langswap_check.js`: the fallback reload is watched from its first frame — the section
  must already be in its place in its first frames and all of the first 300ms, not on its way there
  (1.69.0's restore, run against it: 575 of the 25 060 pixels travelled 215ms in).
* `tests/iconpack_test.php` (200 checks), a new section 13: the catalogue a synthetic package gets at
  import — its shape, index.json's sets, the words and labels made compact, Brands and Other — the
  package list's counts read off the file's head; built on the first need for a package without one (the
  very same bytes), not read when of another format, `iconpackReindex()`, a package without metadata; the
  CLI's and the panel's re-index with their audit lines; never served by iconpack.php. The token for an
  icon that is no face: its styles among those that load with the default first, `fai`, `[Label]` for a
  style it lacks or that does not load, the allow-list (a name in the CSS but not the index is text),
  whatever the scope, the faces off, Bootstrap Icons, another source, a mail, the package gone, hostile
  input, a line of nothing but icons; what the picker is told per scope. And with the owner's Pro 6.7.2
  and 7.3.1 (section 11): each catalogue held to his own index — every icon, his 68 categories and no
  other, none empty, every icon's styles — with its size raw and gzipped, and a rocket and a brand logo as
  tokens.
* `tests/emoji_test.php` (62 checks): the grammar with Font Awesome's nine-part names; the third setting
  in its four places and its words in both languages; the seventy category names, sent with the answer
  and not in the pages' bundle; the script asking for the catalogue only as the scope needs it; the
  endpoint's `part=catalog` — the file as stored, 404 with the faces alone or off, the room's own gate.
* `scratchpad/shots/emoji_picker_check.js` (72 checks) with the owner's Pro 7.3.1: `faces` never asks for
  the catalogue; `search` asks by its first search, finds a "Font Awesome" group with the rocket first,
  gzip and `immutable` on the answer, the arrows over the heading, the rocket's five styles held down,
  Sharp Solid inserted, posted and drawn by the Sharp font in the words' colour, Recent keeping it, and
  "zwierz" finding the 72 animals in Polish; `all` asks after its first page, every one of the 70
  categories counted against the catalogue (7,032 cells for 4,349 icons, the emoji as faces), Brands in
  slices, the chips in the English and the Polish alphabet, the keyboard, a variant inserted, a phone (the
  chips scroll, the page does not, a finger opens the styles); the open times and the JS heap in each
  scope; Settings → Shoutbox in both languages; then the faces off and the rocket's shout reading
  `[Rocket] [Rocket]`. Screenshots in the untracked `scratchpad/shots1700/`.
* `tests/richtext_test.php` (121 checks): the emote stage on its own, fed a map — an emote and the two
  sticker sizes, a code nobody has, upper case and one letter left alone, a hostile name only ever a
  title, an address that is not the image endpoint (or would leave its attribute) no picture at all,
  nothing in a link's href or a picture's src, `[code]`, inline code and `<kbd>` kept, a line of nothing
  but an emote still a paragraph, the `:shortcode:` emoji first, beside a Font Awesome face, no database
  no stage, none counted against the picture limit, an e-mail its code; the whole hostile set read back
  through a DOM.
* `tests/shout_emotes_test.php` (184 checks): the switch (and the room's emotes it needs), the sticker
  per context, the map — approved and switched on only, a row switched on and never approved kept out,
  a row as the browser may see it — one query (a second context reads the cached rows), what each
  picker offers, and a message, a description, a list's description and a mail drawn with the store's
  own rows; the two token rules one.
* `tests/profile_bio_test.php` (114 checks): the token stage — an emote, a sticker as an emote, a waiting
  one as text, a face with Font Awesome off, `:fire:` still text, without the database, with the switch
  off, inside a link and its address, the count unchanged, the DOM of the result; the editor's sixth
  button and the page's data.
* `tests/emoji_test.php` (82 checks) §8: `emotes_everywhere` in its four places and its words in both
  languages; the module and every editor wired to it (the room's requests unchanged, the floating panel,
  Esc, the sticker as its code, the maxlength); `for`; the button and its escaped data; and the endpoints
  run as requests by context — refused `for`, a message's answer with the room off, a message's emotes
  with the sticker marked, a profile's without it, nothing with the switch off, the room's own answer
  untouched, each context's own gate. `tests/icons_test.php` and `tests/shout_test.php` read the picker
  where it lives now.
* `scratchpad/shots/picker_everywhere_check.js` (new, in the sweep; 54 checks) under an enforced policy:
  the message composer, the Info panel's editor (a first description, approved; a proposed rewrite, in
  the panel's review queue and applied), the whitelist form, the profile's description — in each the
  button, the picker against it and never clipped (inside the Info panel's dialog, following its body's
  scroll), an emoji, an emote and a sticker where the context has them, the Preview, and the text sent
  or saved and read where it is read: the thread's sticker at the room's size, the description's bounded,
  the profile's emote inline; Esc closing only the picker; a phone; Polish; the switch off; Settings →
  Shoutbox → Emotes; the room's own picker as it was. `scratchpad/shots/profile_bio_check.js` counts six
  toolbar buttons. Screenshots `scratchpad/shots1700/picker-*.png`.
* `tests/lists_test.php` (114 checks): schema 80 on the fresh path and on the upgrade path — a scratch
  table of 1.69.0's shape with a hostile text, a 500-character one and line breaks walked through
  `schemaListDescMigration()` (the column first, one rewrite per text, `updated_at` held, the format
  column last; stopped before the last and run again: no second rewrite), then held to a table today's
  CREATE makes, column by column; `lists_desc_max` in its four places and the dictionary, clamped; the
  rewrite's promise over nine hostile plain texts — only paragraphs and breaks, exactly their words,
  their count — against the same texts not rewritten, which do become markup; `listEditRequest()`: every
  refusal and its code, the visible limit (tags not counted), the typed ceiling, no picture, the links,
  the mute, what is kept and cleaned, an absent description kept, `describe`, the unchanged not judged
  again, the write keyed by id and owner and blind to the slug; the endpoints run as requests —
  without the token 403, signed out 401, without `lists.use` 403, somebody else's list 404, the owner's
  edit, the hourly limit's 429, the shelf's excerpt and format, the window's drawing, another member
  seeing the public list's words and never the private one's, public lists switched off, the Preview as
  a list's; hostile BBCode and Markdown through `listDescRender()`, read back through a DOM; and the
  counter's twin in `assets/js/favourites.js`, run by node, giving the server's strip and count.
* `scratchpad/shots/lists_check.js` (82 checks): the card's Edit (Edytuj); the window — the name
  focused, 0 of 1000, no picture button, the picker as a list's, Esc and the backdrop's press rule; a new
  name and a description with bold, italic, an emote and a sticker from the picker (asked for as
  `for=list`), the counter at 19 and 38 while typing and 38 in the Preview, which draws the sticker at
  most 96 px; Esc closing the picker alone; the unsaved guard (Esc, the backdrop, the armed ×,
  beforeunload); Save as ONE request, stored as typed, the slug kept; the card's plain excerpt folded to
  two lines; the list's window drawing it under the name; Markdown (the rail, the count, the save);
  another member reading the public list's description on the profile, with the link's rel, target and
  warning, and not a word of a private one — by page or by id; a phone; Polish switched in place (the
  window's words swapped by their ids); each card's Edit opening its own list; the Settings row. It now
  puts the settings and the groups back row by row whichever way it ends. Screenshots
  `scratchpad/shots1700/lists-edit-*.png`.
* `tests/content_test.php` (184 checks), a second half for 1.70.0: the three permissions registered, in
  their presets, no with accounts off, granted once by v81 (and not again once taken away); schema 81 on
  a scratch database on both paths — a fresh install's columns and defaults, and a v80-shaped install with
  a live author, a gone one (u = 0, the id let go) and an empty row, run twice with nothing changing the
  second time; the setting in its four places and the words in both languages; the share on known texts
  (25, 50, 6, 100, the 1% rule, markup, rounding), three words fixed across a thousand in milliseconds, the
  bound past the budget, and the exact claim held to the textbook longest common subsequence on 300 random
  pairs; the chain — started, the author's own edit, a merge, a new entry, the cap, a stored chain checked
  field by field, a row from before v81, the member search's text; the whole flow — a first description,
  an unchanged edit and an anonymous one refused, an edit of 25% applied (the author stays, both told, the
  replaced version kept), a merge (35%), a third member (6%); the name hidden everywhere it is shown (the
  public answer carries it nowhere, the moderator's does, "you"), the editor's line; a rewrite resetting the
  chain and an edit of a cleared record going in as one; delete own / any / refused (a waiting description,
  a moderator, a member, nobody, accounts off, an author without the permission), withdrawn proposals and
  their notices, the audit line; an account deleted leaving "a deleted account" and nothing to a new owner of
  its id; the list's gate one flag at a time (the setting, descriptions off, accounts, profiles, the list
  switch, the name, the grant, the admin blanket, the reader's content.view and favourites.view_others, a
  block, a suspended owner, nobody signed in) and the privacy context; the rows (every state, proposals,
  not the kept archive, not an id that only starts like theirs), the others' view, whitelist.view and
  index.magnet, every sort both ways, the search, the pager, the clamping, the time; and the endpoints as
  requests in a child process — the Info panel's chain, Edit's prefill and Delete's right for each reader,
  content_submit's kind, the panel's Rewrites preview and apply, content_delete's refusals and its 200 and
  404, the list for its owner, a stranger (404 like a name nobody has), after the yes, with the name hidden,
  and the privacy save; then the pages and scripts that carry it.
* `scratchpad/shots/descriptions_check.js` (new, in the sweep; 53 checks) under an enforced policy, as
  smokeuser, smokepeer, a plain member and the panel: the editor's public line with the name and Privacy; a
  first description; Edit opening FILLED (text, link, format, counter) with the other buttons aside, and who
  is offered what (a plain member no Delete; the admin group's smokepeer Delete as a moderator); the Rewrites
  tab's EDIT pill, "Changes 25% …", "The author (smokeuser) stays", the confirmation, applied with the share;
  the chain under the description with the icon's arrow and both names linked, in Polish, six credits
  folded to "+2" and opened; the name hidden ("a member", no link, not in the answer; "a member (you)") and
  shown again; the Descriptions tab right after Likes (Author, "Co-author, 25%", the excerpt, sorting,
  search, a phone's cards); the profile section only after the list switch, gone while the name is hidden
  (the sentence beside the switch), back again; a rewrite starting the chain again; Delete's two clicks, the
  panel redrawn empty, the record empty, one audit line. Screenshots `scratchpad/shots1700/descs-*.png`.
* `tests/groups_matrix_test.php`: the member row gains `content.delete_own` and `content.public`, the
  moderator row and its seed-plus-grants `content.delete_any`, and the simulated upgrade forgets the v81
  marker too.
* `tests/who_test.php` (new, 97 checks): schema 82 on both paths on a scratch database — `votes_listed`
  and the two keys, an upgraded account named nowhere, fifteen kept versions still fifteen after the
  migration, nothing asked twice; the two settings in their four places and every word in both languages;
  who may open which section — each switch closing its own and no other, the reader's account, nobody
  signed in; the likes' truth table ONE flag at a time, each taking the member out of the rows AND the
  count — `votes_listed`, `votes_public`, a group without the grant, the administrator's blanket alone (and
  the grant given to that group putting them back), suspended, unverified (and counted where the site does
  not ask), a membership not yet and no longer in force, a block that hides the profile (and one that does
  not); anonymous votes never, the mode's values only (stars: 10, 7, then the thumbs up as half stars, no
  thumb down); the lists against the five gates and the account's, their search by a list's name or its
  owner's, `listsContainingHash()` the same query's first page; the favourites' gates; paging (20, 5, an
  empty page past the end with the true total), clamping, the search's wildcards taken literally, totals
  equal to the rows; EXPLAIN among three thousand other votes and list rows; and the endpoints as requests
  in a child process — each section's shape, the same 404 for every no, 400, 405, the hourly limit (the 4th
  of three), `hash_favourites`'s 1.69.0 answer, the privacy save — then the pages and scripts that carry it.
* `tests/content_test.php` (199 checks) §16: the newest ten of a description's kept versions — fourteen
  become ten after an apply (the five oldest gone), then still ten; the turned-down, withdrawn, applied and
  waiting proposals and another description's copies untouched; the other home the same; fewer than ten
  lose none; the query the notes give counts 10 rows over 3 descriptions on its fixtures; the waiting count
  can use each home's key; the README and the panel's words no longer promise an undo.
* `scratchpad/shots/who_check.js` (new, in the sweep; 48 checks) under an enforced policy, as smokeuser with
  twenty-five members who said yes and one who has not yet: the button and its title; the three sections in
  order, their three requests in the air at once; 20 of 25 each, "Show 5 more" asking for page 2 alone; each
  section's search (a name, a list's name, its owner's, nobody) leaving the others as they were; Esc closing
  the overlay and not the Info panel, the focus back on its button; the new Privacy switch under the likes'
  own, ticked (the member appears, 26 people) and unticked (gone, 25); star mode (5, 3½ read as "3.5 of 5
  stars", 2, ½; no thumb down); Polish on a Polish page and switched in place with the overlay open; a phone;
  the button with only some sections on, gone with none; Settings → Profiles in both languages. Screenshots
  `scratchpad/shots1700/who-*.png`. `scratchpad/shots/fav_check.js` reads the Favourites section by its ids.
* `scratchpad/shots/layers_check.js` (new, in the sweep; 33 checks): one Esc, one layer — the Info panel's
  description link and source link (the dialog, then the panel), "Put this in a list" over the panel, a
  list's window (its description's link; the Info panel from its row: three layers, three Escs), and the
  panel's Index and Whitelist detail windows (Bootstrap's) under their source links.
* `scratchpad/shots/emoji_picker_check.js`: "as fast in every scope" no longer measured against a fixed
  margin, which a busy machine broke (288 ms in a batch run, 121 ms alone) — five rounds, the faces first
  and the other scopes in turned order, each scope's median held to the faces' median of the same run
  (at most 1.5 times it plus 20 ms); and the rule is proved able to fail in every run: an opening of `all`
  slowed on purpose inside its click (twice the faces' time, +100 ms reopening) must fail it, and does.
* `scratchpad/shots/picker_everywhere_check.js`: the message composer's emote request is counted by its
  context — with lists on (as `polish_check.js`, earlier in the sweep, leaves them), a list's Edit window on
  the same account page asks for its own (`for=list`), and the check read that as the composer's second.

## [1.69.0] — 2026-09-25

Schema 77 (74 is the description's, 75 the list's, 76 the icons' source, 77 the picker's Font Awesome
faces). The owner asked for a description on the
profile — a few lines of a member's own under their name, written where they are read, the way the
owner's Flarum does it — behind a switch and a permission that are both on by default, with a maximum
the operator sets and nothing but basic BBCode. And for what a member liked or rated, listed right
after their favourites — on the account page and, if they say so, on their profile — in a table that
sorts, filters and pages, under the site's switch, a group permission and the member's own yes. And,
with those settings in place, for Settings' categories to be reorganised wherever they still needed it:
each group now answers one question, the page reads group by group, and two groups have names that say
what they hold. And for Font Awesome 6.7.2 or 7.3.1, Free or the owner's own Pro, chosen in the panel —
the Pro copies installed from a zip or a folder, however they were packed, checked file by file, served
by the site itself and never anywhere near the repository — with the styles that load and the one the
site's icons use chosen too, because Font Awesome no longer puts everything in all.css. And, with Font
Awesome on in production, for every icon to stand level with its words and at one distance from them:
they hung low in some buttons and high in others, and "Fetch metadata"'s cloud all but touched its
word. And for the shoutbox's picker to hold every emoji, the way a phone's keyboard does — Unicode's own
pages, a search in the reader's language, the ones used last, the skin tones on an emoji held down —
with Font Awesome's faces beside them or in their place once the owner's Pro draws the site, and for the
site's own icons to take the closer glyphs Pro has. Alongside, what the 1.68.1 audit and this release's
own work found: a hash a reader is not shown could be read back through a search, the account page
scrolled sideways on phones, the name row of a profile was unreadable over a bright cover, the verified
badge was a starburst in Font Awesome Free, opening the bulk-mail tab, Settings or the Whitelist's
Review tab wrote to the audit log, and four small things in the panel.

### Added — a description on the profile ("about me")

* **Where it is.** Under the name on a public profile, in the head's text column beside the picture.
  With a cover it sits over the photograph on a translucent backing of its own, and the band grows to
  hold it instead of clipping it; without one the picture stands at the top of the column. Long words
  break rather than widen the page, and a phone gets the same column. Another member's empty
  description draws nothing at all.
* **Written in place.** On your own profile an empty description is a dashed "Write something about
  yourself…" box. A click on it — or on the text, or Enter on either: to the keyboard it is a button,
  with a focus ring for the keyboard only — opens the editor where the text was: B, I, U, S and link
  above a textarea (the same icons and titles as every other toolbar on the site), a counter, Save and
  Cancel. Esc cancels and puts the old text back, Ctrl/Cmd+Enter saves, Ctrl+B / I / K wrap the
  selection; the link button wraps an address as `[url]…[/url]` and anything else as
  `[url=https://]…[/url]` with the caret where the address goes. Save puts the SERVER's rendering in
  place — the page never turns BBCode into markup itself — and a click on a link inside the text is the
  link's, not the editor's.
* **Five tags and not one more**: `[b] [i] [u] [s] [url]address[/url] [url=address]words[/url]`, in any
  case. Every other tag — `[img]`, `[color]`, `[quote]`, `[table]`, `[size]`, `[spoiler]`, a Markdown
  `**` — is shown as the text it is; a bare address is not made into a link and a `:shortcode:` is not
  an emoji; HTML is always text. A tag left open is closed at the end, a closer with nothing to close is
  text, a closer that crosses another tag closes it and opens it again after itself (a link is never
  reopened: one link must not become two), a link inside a link is text. At most three links, each
  through the checks and the `rel="nofollow noopener noreferrer ugc"` every link on the site gets (http
  or https with a host; the leave-the-site question for a domain not on the trusted list); at most eight
  lines, at most two line breaks in a row.
* **Its own renderer** (`includes/profilebio.php`), not an allow-list switch on the shared one. The
  shared renderer is thirty-odd regex passes that also link bare addresses, turn shortcodes into emoji,
  build paragraphs and pair each opener with the next closer, so a switch would have had to reach into
  every pass, the description would still have inherited the passes that are not tags, and "an unclosed
  tag is closed" is not something a pair of regexes can do. This one walks the tags with a stack over
  the raw text and escapes every run of text on its way out. Descriptions, messages and shouts render
  exactly as before: nothing they use was touched.
* **Characters are what a reader sees** — the words, not the tags round them; an emoji is one
  character and so is a Polish letter; a line break is one. The counter under the box (amber near the
  limit, red past it) runs a twin of the server's rules, and a browser check holds the two to each
  other string by string. The source as typed has a ceiling of its own — four times the visible limit,
  never more than 4000 characters or 8 KB — so tags cannot be used to store a novel.
* **The save** (`api/profile_bio.php`, POST `{csrf_token, bio}` → `{success, html, text, chars}`) asks,
  in order: the token, a session, the switch, `profile.bio` of the ACCOUNT (a panel session in the same
  browser is not the member's grant), a moderator's mute (the messages and the room already obey it),
  twenty saves an hour per account — and only then the text: valid UTF-8; control characters, bidi
  overrides and isolates and characters that draw nothing taken out (the zero-width joiners an emoji
  family and some scripts need stay, and the block is `dir="auto"`); line breaks made one kind; the
  visible limit, the source limit, the lines, the links; and an address that is not one is refused
  rather than left to be found on the page. An empty text clears it, and clearing your own words is
  never behind the permission or the mute. Every refusal is in the reader's language. There is no word
  filter to apply — shouts and messages have none either.
* **Settings → Profiles → Profile description**: `profile_bio_enabled` (on) and `profile_bio_max` (300,
  20–1000), in a section of their own so the categories can be rearranged round it, explained in both
  languages.
* **The permission `profile.bio`** ("Write a description on their profile"), in the member preset and
  granted once to the member group (`v74_profile_bio`: an operator who takes it away keeps it taken
  away). What a profile shows is asked of the account the words belong to, the way a cover is: a member
  whose groups lose it has the text hidden, not deleted, and back the moment the grant is; the admin
  group's blanket counts, so the owner's own profile keeps its text.
* **Stored as typed**: `users.bio` (TEXT) and `users.bio_updated_at`, in the CREATE and in a guarded
  ALTER. The source is rendered when a page is drawn, so a fix to the renderer reaches every text
  already written.
* **The account page**: the Profile card has a Description row — the text, or "Not written yet" — and
  "Edit on your profile", which opens the profile with the editor already open (`#bio`).
* **The panel**: a member's edit window (Users) shows their description as the profile draws it, says
  so when the profile is not showing it, and has **Clear description**: asked first, done at once, the
  text deleted for good, the member told in a notification, one `user.bio` line in the audit log (Users
  group) with the first few hundred characters of what was there. Clearing what is already gone changes
  nothing and writes nothing. Gated like editing a user (`panel.users.edit`, `admin/user_bio`).
* With a description (and on your own profile, where the dashed box stands in for one) the head is the
  picture and a text column — the name row, then the words. A head without one is laid out exactly as
  it was, on a phone too: its wrappers step aside (`display: contents`) and nothing moves.
* The in-place language switch leaves the words and an unsaved draft alone and turns every label of
  an open editor, which rebuilds its own from the new dictionary.

### Added — a member's likes or ratings, on the account page and the profile

* **What it lists.** The torrents a member voted on, in whichever mode the rating system is in: with
  thumbs they are "Likes" / "Polubienia" (up and down alike), with stars "Ratings" / "Oceny" — the tab,
  the section and every label follow the mode. Only the votes that mean something in that mode are
  listed, ±1 or 1–10 half stars: a switch of mode leaves the other mode's votes in the table, and a
  star rating is not a thumb. One value belongs to both (a 1 is a thumb up and half a star, and nothing
  records which mode it was cast in), so it is read the way the current mode reads it. A vote cast
  without an account is never on anybody's list.
* **Where.** A tab on the account page right after Favourites, and a section on a public profile right
  after Favourites, before Registered torrents and Lists. One partial draws both
  (`templates/partials/votes_section.php`) and one component in `assets/js/favourites.js` fills both
  from one endpoint, with the Magnet and Info a favourites row has (now one function for both).
* **A table that sorts.** Name, size, seeders / leechers, the member's own vote — a thumb, or the Info
  panel's read-only stars in half steps — the score (a percentage, or the average and a star), the
  number of votes, and the date of the vote. Every header is a button, so the keyboard reaches it, with
  the search table's arrows; the first click sorts the way a reader asks first (names A to Z, everything
  else the most, the best or the newest first), the second turns it round. Newest vote first to begin
  with. A header too long for its column wraps between its words and keeps its arrow on the last one.
* **A score below the minimum is not a score here either.** It is a dash, with the count still missing
  in its tooltip, and the row carries no number for it. Sorted by the score, those torrents come after
  every shown one in BOTH directions, and among themselves they are ordered by nothing the page hides,
  so their order cannot be read back as the number.
* **Filters.** A search by name or hash — and inside file names, through the favourites list's own
  helper, over the member's newest votes up to the favourites limit (bounded like a favourites list, and
  charged to the search page's hourly bucket). In star mode: their (your) rating from – to, and the
  average from – to, both in half stars; a range on the average leaves out a torrent that has no
  average. In thumbs mode: their (your) vote — up and down, up, down — and a minimum number of votes.
  A count, and the favourites pager; the filters and the sort survive paging, and changing either goes
  back to page 1. A range picked backwards is swapped, and the selects show what was used. An empty
  table says which kind of empty it is: nothing matches these filters, or nothing yet (and how to fill
  it, on your own).
* **The date** is the vote's own last change, read as an instant and written in the reader's own zone,
  like every other time on the site: the day in the cell, the whole moment with its offset in the tooltip.
* **Who may see it.** Your own list, always, while the feature is on — on the account page and on your
  own profile, public or not, as your favourites are. Somebody else's needs every one of: the site's
  switch, ratings on, their profile open to you at all (profiles on, `favourites.view_others` on your
  account, no block that hides it), their account active, a group of theirs that GRANTS `rating.public`
  (the admin group's blanket is about what somebody may fix, not what may be shown of them: an
  administrator who wants theirs shown grants it to their group like anybody else), and their own yes.
  Every no is the same "not found" a favourites list gives. One function answers it for the page and the
  endpoint alike (`profileVotesShownTo()`, `includes/profilevotes.php`).
* **Their own yes**: a switch in Privacy on the account page, right after the favourites one and shown
  exactly where that one is — "Show my likes on my profile" / "Show my ratings on my profile", saying
  that thumbs down / low ratings are shown too. Off for everybody to begin with (`users.votes_public`,
  0, in the CREATE and a guarded ALTER), saved with the other privacy flags. Without the grant it stays
  on the page with the sentence that names the missing permission.
* **Settings → Profiles**, a section of its own: `profile_votes_enabled`, on. It shows nothing anywhere
  while ratings are off (`rep_enabled`), and says so beside the control.
* **The permission `rating.public`** ("Let their likes/ratings be shown on their public profile"), in
  the member preset and granted once to members (`v75_rating_public`). With accounts switched off it is
  no: unlike `rating.vote` it is consent to a list on a profile, and there is no profile to consent to.
* **The endpoint** `api.php?endpoint=user_votes` (GET): `page`, `per_page` (25, at most 100), `search`,
  `files`, `sort` (date, own, score, votes, name, size, seeders), `dir`, `own_min` / `own_max` (1–10),
  `avg_min` / `avg_max` (0–500 hundredths of a star), `vote`, `min_votes`. A number outside its range is
  clamped, anything that is not one of the allowed words is the default, and the answer says what was
  used. One statement for the page and one for the count, from a fixed map of orders: the member's votes
  through `idx_votes_voter`, each joined to its catalogue row by that row's own unique key (the whitelist
  row winning where both exist, as a favourites row reads it; nothing of a whitelisted torrent for a
  reader without `whitelist.view`; a banned one on your own list only), no query per row. Three thousand
  votes of one member: 8–32 ms a page.
* **In place.** The live language switch redraws the rows from the last answer — the switch cannot reach
  rows a script drew — and a vote changed in the Info panel opened from a row reloads the list's page
  (`assets/js/app.js` now says `rating:changed` when a vote lands).
* **An old link to a tab that is gone** (`?action=account#votes` after the feature was switched off, or
  `#sounds`, `#messages` on a site without them) opens the overview. The tab bar knew the name, found no
  pane by it, and hid every pane it had: an empty page.
* **The account page's tabs wrap at every width**, not only on a phone: with this one there are nine,
  and between 641px and about 900px the Polish row ran 100px past the page's edge (820px of tabs in a
  720px bar, measured at 768). Where they fit, they stay one row.
* **On a phone** (under 960px) a row is a card: the name, then the facts in fixed columns, each with its
  header's word in front of it and never broken inside, the buttons last; the header becomes a row of
  sort buttons. Nothing scrolls sideways at 375px. The icons are written once and drawn by either
  library; the filled thumbs are new names in the Font Awesome map.

### Added — Font Awesome 6 or 7, Free or the owner's Pro, and the packages Pro comes in

* **Where it is chosen.** Settings → Site, under the icon library, a Font Awesome block: the source —
  Free 6.7.2 from jsDelivr (1.68's, and the default), Free 7.3.1 from jsDelivr (SRI from jsDelivr's
  metadata, the policy unchanged), or an installed package; the package; the style the site's icons
  are drawn in; for a package, which of its style files load; a live preview; the packages themselves.
  Four settings in their four places — `fa_source` (`cdn6` | `cdn7` | `pack`), `fa_pack`,
  `fa_pack_styles` (a JSON list) and `fa_style` — shipped as cdn6 / solid, so nothing looks different
  until somebody chooses. A save is judged against what is installed: a package that is not there is
  refused, a tick that means nothing is dropped, a style that does not load with the chosen files
  becomes solid; a package that disappears from the disk under a stored choice makes the site draw
  Free 6.7.2 and the panel say so, never draw nothing. Moving the site onto a package, or to another
  one, asks for the password. Nothing here is drawn until Icon library says Font Awesome.
* **One import, three doors** (`includes/iconpack.php`): a zip dropped or chosen in the panel, a zip or
  a folder named by its path on the server, and `php tools/iconpack.php import | list | activate |
  styles | verify | delete` for the operator with a shell (as the web user, so the panel can delete
  what the shell installed; it warns otherwise). However the download was packed — its contents, the
  folder, the folder inside two more — the root is the one directory, at any depth, whose `css/` holds
  a Font Awesome stylesheet beside a `webfonts/`; two such directories are refused, not guessed
  between. Kept: `css/`, `webfonts/`, `metadata/` (JSON and YAML, without the sponsors and shims
  lists) and a licence text. The SVGs, scripts and sources of a full download are listed and never
  extracted, and the report says what was skipped and from where.
* **Nothing in a package is trusted for where it came from.** Every entry name is read from the zip's
  own central directory (libzip turns a NUL into a space) and checked — no `..`, absolute path, drive
  letter, backslash, control character, symlink, duplicate or two names one case apart; counts, sizes,
  the total and the compression ratio are bounded before a byte is inflated, and the bytes actually read
  are counted again; a font must begin with its format's signature; a stylesheet must be UTF-8 and say
  it is Font Awesome, of one edition and version, and may reference nothing but its own package's
  fonts — no `@import`, no other URL, no `expression(`, `behavior:`, `javascript:` or escaped
  identifier; a style file may set nothing for everybody that the core does not already say, and may
  not redefine another family or claim its face. Any of it refuses the whole package. It is unpacked
  beside the store, checked, given its manifest (every file's size and SHA-256, the styles, the
  names), and renamed into place: a package appears whole or not at all. Twenty at most.
* **A full download, as the owner's two Pro copies are, is understood as one.** Its core is
  `fontawesome.css` where there is one (else `all.css`); a style is every file that declares a face;
  the compatibility sheets — `v4-shims`, `v4-font-face`, `v5-font-face` and 6.7.2's `svg-with-js` —
  are checked like the rest and then left out, the report saying why (for sites moving up from 4 or 5,
  and for the SVG + JS build, which this site never loads). Pro 6.7.2: 23 stylesheets, 17 styles, 4
  left out, 55 files kept. Pro 7.3.1: 41 stylesheets, 37 styles — Sharp, Duotone, Jelly, Slab, Notdog,
  Thumbprint and the rest — 2 left out, 77 files, 7.5 MB. Each installs in under a second.
* **What a page loads**: Bootstrap; or Free 6 / 7 from jsDelivr; or a package's core and exactly the
  style files chosen. With `fontawesome.css` as the core Solid, Regular and Brands always come too —
  every style's last resort, the site's outlines (an empty star beside a full one) and its one brand —
  and anything else when ticked. The output filter and the observer draw every icon in `fa_style`,
  with outlines kept outlines, fills kept filled and brands brands.
* **Served by the site** (`iconpack.php`, beside index.php: no session, no database, one manifest
  read): only the css and font files a manifest lists, with their exact type, `nosniff`,
  `Cross-Origin-Resource-Policy: same-origin` (another site cannot hotlink the licensed fonts), an
  ETag, 304s and a year's immutable cache keyed by the package's hash. A stylesheet's
  `url(../webfonts/…)` is rewritten on the way out into the endpoint's own address — a query string,
  where PATH_INFO would depend on the web server (php -S, Apache and nginx each treat it their own
  way). Metadata and the licence are never served.
* **The map is names now** (`iconFaEntries()`, 183 Bootstrap names): an icon's Font Awesome NAME and
  its part — follows the chosen style, stays an outline, stays filled, a brand — with a Pro twin where
  the Pro set has the very icon (`patch-check` → `badge-check`, the octagons, the shields,
  `collection`, `person-square`, `activity`, the sort pair, the server stack; Pro 7's angled pin and
  dot; the megaphone, the bubbles and the calendar range below), and a name per version where 6 and 7
  differ. An entry whose icon the loaded set lacks falls back to its Free name. The panel counts it:
  exact, Pro twins, approximations, fallbacks — Free 157 / 0 / 26 / 0, Pro 6 173 / 20 / 10 / 0, Pro 7
  175 / 23 / 8 / 0 in solid. A Pro 7 family that is a subset (Jelly, Utility, Slab…) draws what it has
  and the classic family the rest at the same weight — known from the package's index where it came
  with one (Jelly Regular: 90 of the 183, below), measured by the preview where it did not.
* **Every style draws, and stays the size 1.68.1 made it.** The glyph's face is read from Font
  Awesome's own variables (7.x works the family out on the icon, 6.x names each on `:root`), with the
  classic family after it; each style class pins its weight on both layers. A two-layer icon (Duotone,
  Sharp Duotone, the 7.x "duo" families, Thumbprint, Vellum) is drawn in one grid cell, where its
  layers had come out at two heights, and without Font Awesome 7's 1.25em fixed width — inert on the
  site's inline icons, it made every two-layer icon-only button 0.25em wider than its Bootstrap twin
  (32 → 35.5 px). Measured: every icon-only button of five pages as wide as with Bootstrap and as tall
  as with Free 6, in Free 6 and 7, Pro 6 solid and Sharp Solid, Pro 7 solid and Duotone Light.
* **The preview** draws a row of the site's icons with the unsaved choice, in a frame of its own
  (6 and 7 cannot share a document: both define `.fa-solid`), and says which entries fall back.
* **The packages table**: edition and version (and which is in use), styles, size and icons, when and
  by whom it was installed; Use, Verify (every file against its manifest's hash) and Delete — never the
  package in use. Each install (or refusal), activation and delete, and a change of styles from the
  shell, is one line in the audit log's Settings group, by the panel's user or `cli:<user>`.
* **Kept out of git and out of backups.** `config/iconpacks/` is in `.gitignore` (`git check-ignore`
  agrees for every file under it), refused over HTTP with the rest of `config/`, and outside every
  deploy. The panel's Backups archive the database only; the server's backup toolkit (outside this
  repository) copies `config/` whole and would take the packages with it — they are re-installable,
  so leave them out there.

### Fixed — every icon level with its words, and at one distance from them, in either library

* **What the owner saw, measured.** With Font Awesome on, the icons stood lower than their words in
  some buttons and higher in others, "Fetch metadata"'s cloud all but touched its word while the bin of
  "Delete" beside it stood back, and the verified badge was a starburst. Measured on the ink, not on the
  boxes — every icon that has words beside it, on every public page, panel page and tab, account tab,
  menu, bulk bar and dialog, in English and Polish, on a desktop and a phone, photographed at twice the
  pixels with only the icons, only the words and neither, nothing moved in between — the middle of each
  icon's ink against the middle of its words' capitals: half the cap height above their baseline, the
  height both icon sets are drawn to centre on, and a property of the font rather than of which letters
  a label happens to have. Before, English on a desktop: Bootstrap +0.08px on the median with 88 of 727
  icons more than a pixel off, Free 6 +1.84 with 657 of 724, Free 7 +1.81 with 649 — and in either
  library a button a pixel off its twin a few rows up.
* **Font Awesome hung 0.125em low.** Its em starts 0.125em below the baseline — its own stylesheet
  draws the webfont at vertical-align 0 — and 1.68.0 lowered it by 0.125em more, the way Bootstrap's
  stylesheet lowers Bootstrap's glyphs (the 1.68.0 comment that a glyph "sits at the same height whichever
  library draws it" was off by exactly that). Its box now stands on its own baseline, which is where
  Bootstrap's box is, so both libraries lay every line out alike: a button whose line-height is 1 (the
  panel's search-clear crosses) is Bootstrap's 20×20.5 in every mode, where any Font Awesome, 1.68.1's
  stylesheets included, made it 20×22.5 — the Font Awesome block above measured those at Free 6's
  height. Free and Pro, 6 and 7, centre their glyphs at the same 0.375em once their box is 1em high, so
  one correction serves every version; 7's fixed 1.25em width, inert on an inline icon, is switched off
  where the rows below would make it live.
* **A fraction of a pixel came out as a whole one, either way.** At 14px a glyph stood 1.75px from its
  words' baseline, and Chrome puts each run of text on a whole pixel — the label's and the glyph's
  separately — so the same button showed its icon a pixel higher or lower depending only on where it sat:
  the Index page's Fetch metadata stood a pixel higher in the bulk bar than in the toolbar above it, in
  Bootstrap too. Moved down 1/16px at a time, a button's offset jumped by exactly 1.00px; placed a whole
  number of pixels from the baseline, it did not move at all (at 1x and 2x, in both libraries). The glyph
  is now painted the whole number of pixels from its words that brings its middle nearest their cap
  middle, read in THEIR font and size (`cap` on the icon's parent: a sort arrow is smaller than its
  header), and its box stays where it was, so no line and no button changes size. Bootstrap's own box is
  kept to 1/64px, the unit Chrome lays boxes out in, so box and move add up to exactly a whole pixel — a
  1.8px box and a 0.2px move still came apart for one position in sixteen. On the public site the cap
  middle is Courier New's, whose capitals are short: Bootstrap's icons sat a pixel high there as well. The
  Settings page's drop zones (a picture, an emote, a sound, a zip) set their icon, a size larger than
  its words, in a row centred by the boxes — the same fraction, one zone a pixel off the next; they
  stand on the baseline too, in their words' size. An icon laid out as a box of its own — a star of a
  rating, the shoutbox's and the messages' small round controls — stands on no line of words and keeps
  its place (moved, a star came out 2px low and its layer cut it off). A browser without `cap` or
  `round()` moves nothing.
* **A glyph drawn off the middle of its square** is centred by its ink. Bootstrap's list-check stands
  high in its square and the person with a gear low (1.3px at the page titles' 22px): 25 glyphs, read off
  the pinned font's own glyph bounds into the stylesheets. Font Awesome's differ by version and, in Pro 7,
  by family — Jelly, Slab and the rest draw the same names their own way — so assets/js/icons.js
  measures each glyph that stands beside words, once per face, on a canvas when that face has loaded,
  and the icon carries its own middle (chevron-up and -down are drawn 0.06em off the middle, 7's star
  0.05em high; 30 of 7's glyphs are 0.03em or more off).
* **One distance from the words.** The space was whatever the markup had: a space; a space AND 0.2rem
  when the words were in a `<span>` (`.btn i` had a margin that `.btn i:only-child` took away again, and
  `:only-child` counts elements, not text — "Refresh S/L" stood 8.4px from its icon, "Fetch metadata"
  4.8px beside it); 0.3rem more in the tabs, 0.35rem in the Whitelist's menus; nothing where a script
  built a button without a space. A button, link button, tab, menu item or pager button that holds an
  icon is now a row, baseline-aligned, with one gap: a space at either end of the words is not drawn,
  words that wrap start right after their icon, and a button a script builds — the bulk bars,
  admin-common's pagers, iconBtn / mkBtn, the account and profile scripts — comes out as the page's own
  do, because the rule is on the control, not in the builder. Each kind keeps the gap it had: a space in
  the panel's face (0.275em) and in Courier New on the public site (0.6em), the tabs' 0.3rem and the
  menus' 0.35rem on top. With Font Awesome an icon-only button is not a row — as one, a two-layer icon
  (a grid of line-height 1) left its button 6px shorter than its Bootstrap twin — and is exactly as it
  was; Bootstrap's icons are all inline, and its icon-only buttons are the size they were either way.
* **"Fetch metadata" hugging its word.** The fixed box that keeps an icon-only button one size in Font
  Awesome (1.68.1: 1.25em wide, taking 1em of the line) was chosen by `:only-child`, so every icon
  before bare text got it too: the cloud, 1.25em wide, reached 0.125em into the space before its word;
  the bin, 0.875em, stood 0.19em back — 3.1px against 5.9 at 14px. An icon with words beside it now
  carries `data-label` — the server's output filter marks what it sends, assets/js/icons.js what scripts
  build, and follows words set later — and keeps its glyph's own width. The box is for icon-only
  buttons, which are exactly as they were.
* **The room inside a glyph's square** came on top of the gap: a Bootstrap glyph stands in a 1em square
  with room of its own on either side — none for the cloud, 0.12em for the cross and the plus, 0.21em for
  the arrows, 0.27em for a single chevron — so one row of buttons ran from 3.7px to 7.3px. The room on
  the words' side (for the pagers' Next and Last, the side before them) is taken off the gap:
  Bootstrap's from its glyph bounds, in the stylesheets; Font Awesome's measured with its middle. Only
  there, so an icon-only button is not touched. A word counts from where its first letter stands: an
  "I" sits further into its box than a "W", and that is how the font spaces the letter everywhere.
* **An open disclosure's chevron dropped below its words.** It was the same chevron turned a quarter,
  and a turned glyph is not drawn the way text is: every glyph of text stands on a whole pixel — which is
  what lets the placement above put an icon level with its words — and a turned one exactly where its
  box's geometry says, which Chrome lays out rounded its own way (the turned <i> of 1.68 turned about a box
  as high as the line, as well). An open folder's chevron in the Info panel's file list stood 1–2.5px
  under its name, 0.6–1px apart from the same chevron shut, depending on the size and the face. An open
  <details> now draws the chevron that points down, the library's own glyph (Bootstrap Icons \f282,
  Font Awesome 6 and 7 \f078, through the variables Font Awesome draws both duotone layers from), placed
  like every other icon; assets/js/icons.js measures it again when a <details> opens or shuts. The
  quarter-turn animation is gone with the turn.
* **After**, in every mode, in both languages, on a desktop and a phone — 1,176 to 2,666 icons beside
  words per mode (a mode that changes only the style takes the busiest pages): none more than 0.87px
  from its words' cap middle — Bootstrap 0.87, Free 6 0.78, Free 7 0.71, Pro 6 0.71 and 0.70 in light,
  Pro 7 0.78, 0.77 in light and 0.78 in Jelly, the medians between -0.13 and +0.19 — and in each of the
  170 kinds of button every gap within 0.9px of the kind's middle. The same check over the stylesheets
  this part started from: the medians +0.08 in Bootstrap and +1.68 to +1.81 in every Font Awesome mode;
  344 of 2,668 icons more than a pixel off in Bootstrap and 1,038 to 2,403 in each Font Awesome mode
  (of 1,179 to 2,661), the worst 1.93px in Bootstrap and 2.84 to 3.30 in Font Awesome; 115 to 531
  button icons outside their kind's two-pixel band, the worst 2.2 to 3.1px out.
* **The map, by eye.** A sheet of all 179 entries — Bootstrap beside Free 6 and 7 and Pro 6 and 7 in
  solid and light (`scratchpad/shots1690/icons-map-*.png`) — and the one that read wrong: `patch-check`
  (verified) was Font Awesome Free's `certificate`, the badge WITHOUT its tick, a starburst. It is the
  tick in a circle now (`circle-check`, outlined and filled as Bootstrap's pair are); Pro keeps
  `badge-check`. The other approximations read as what they stand for, each with its reason in the map.
* **The audit log's summaries wrap** instead of stopping at "…": the summary is what a line is for, and
  `.au-summary { overflow-wrap: anywhere }` could never apply while 1.68.1's one-line cells cut it off.
* **Opening the bulk-mail tab writes nothing to the audit log.** The tab asks admin/bulk_send for the
  audience's preview and the recent batches by POST, and the router logged every POST to an admin
  endpoint under the endpoint's action, `bulk.queue` — two lines a visit, nothing queued. The four reads
  (preview, render, batches, status) write none; a cancel is `bulk.cancel` and a test copy `bulk.test`
  (the Mail group already listed bulk.cancel, which nothing wrote), and an endpoint may name the action
  it performed (`auditNote(['action' => …])`).

### Added — every emoji in the shoutbox's picker, a search, the variants a phone offers, and Font Awesome's faces

* **Every emoji Unicode has, up to Emoji 16.0**: 1,906, on Unicode's own nine pages — Smileys & emotion
  169, People & body 386, Animals & nature 159, Food & drink 131, Travel & places 218, Activities 85,
  Objects 264, Symbols 224, Flags 270 — where the picker had 170 on four pages of its own. The cap is the
  newest set Windows 11, Android and iOS all draw: Windows 11 since its September 2025 update, Android and
  iOS since March 2025. 17.0 reached iOS and Android in March 2026 but Windows 11 only in a Release
  Preview, so on a stable Windows its new characters would be empty boxes — and so would the skin tones
  17.0 gave the people with bunny ears and the wrestlers, who are offered without tones. Windows draws no
  country flag at all, here or anywhere (two letters, by Microsoft's choice); the page stays for
  everybody else.
* **The data is a file per language, fetched once.** No page carries it; the shoutbox widget says only
  where it is. The picker asks for the reader's language the first time it opens (English 179 KB, 51 KB
  compressed; Polish 183 KB, 52 KB), once per page, at an address carrying the file's version, so a
  regenerated file is a new address — and with Font Awesome's faces in place of the ordinary emoji it is
  not fetched at all. A
  long page is drawn a slice at a time, 120 cells at once and 200 a frame after that, so a phone never
  waits on People & body. Generated by `tools/emoji_data.php` from emojibase-data 17.0.0 (MIT), whose
  names and keywords are Unicode's CLDR 48 annotations (Unicode License v3): both licences in full in
  `assets/emoji/LICENSE.txt`, the attribution in each file's first key and in the README; regenerated
  from the same package, the files come out byte for byte as committed. One emoji per line, both files in
  one order (row N is the same emoji in both; the search's English fallback reads it so). Each emoji is
  written as a shout should carry it: one that is an emoji by default without the presentation selector
  emojibase puts after it (👍 alone), one that is text by default with it (☺ is a black glyph without);
  the parts — the tone swatches, hair on its own, the regional letters — are left out; GitHub's
  shortcodes join the English keywords (":tada:" and ":joy:" are what people coming from chat type).
* **Recently used** comes first: the last 36 picks in this browser — emoji in the tone chosen, faces,
  emotes — newest first, and the picker opens on it once it holds anything. The tabs are icons of the
  site's icon library, named on hover and to a screen reader, and scroll sideways where they do not fit;
  five of them are new names in the map (`tree`, `cup-hot`, `car-front`, `lightbulb`, `trophy`), so with
  Font Awesome they are drawn in the site's style.
* **A search at the top**, over every page: the reader's language first — CLDR's names and keywords,
  accents optional ("usmiech" finds the smiles, ł read as l) — then what only English finds, from the
  English file fetched the first time somebody searches in another language. Every word typed must
  start a word of the emoji's; a name that starts with the query comes first, then a word of the name,
  then a keyword; the faces are found by their words in both languages and the emotes by code and name;
  up to 300, and how many. The keyboard: type, the arrows walk into the grid, Enter inserts, Esc clears
  the search and then closes the picker, the focus back on its button.
* **Variants, as a phone offers them.** Hold an emoji down — a finger or the mouse, 450 ms, a move of
  more than 8px cancelling — or right-click it, or press Shift+Enter or the menu key on it: a bubble over
  it offers no tone and the five tones. A cell with variants carries a small mark in its corner. The tone
  chosen is remembered by the browser and from then on the grid draws every emoji that has tones in it,
  so a plain click inserts it at once; the hold itself inserts nothing. On a touch screen the search box
  does not take the focus when the picker opens, so no keyboard comes up over it.
* **Font Awesome's faces** — Settings → Shoutbox → *Emoji in the picker*, offered only while a Font
  Awesome Pro package is the site's icon source (a note says so otherwise): `shout_emoji_fa` off (as
  shipped) / fa, the faces instead of the ordinary emoji / mixed, both; and `shout_emoji_fa_style`, the
  style a click inserts (empty: the site's own `fa_style`; a save turns a style that does not load, or
  brands, back to empty). The faces are the `emoji` category of the ACTIVE package's own index — 113 in
  7.3.1, 110 in 6.7.2 — on four pages of this project's grouping (smiles and laughter; calm and
  thoughtful; worried and sad; angry and unwell), each tab one of its faces. Held down, a face offers
  every style it is drawn in among those the site loads — the index says which families have it; Jelly,
  Slab and the other 7.x families draw only some — the default first: the chosen style where the face
  has it, else the classic family at that weight. The English labels and keywords are the index's; the
  Polish ones, and the ordinary emoji each face stands for, this project wrote
  (`assets/emoji/fa-faces.json`: names and words, nothing of Font Awesome's files). The picker asks
  `api.php?endpoint=shout_emoji` for them — a reader who may see the room, in the language asked for —
  cached for a day under a fingerprint of the mode, the style, the package and what loads, so any change
  is a new address.
* **A face travels as a token**: `:fa-face-grin-tears:` in the default style, or
  `:fa-face-grin-tears/duotone-light:` in one of its own — the style key being the package's style file
  (solid, sharp-light, jelly-regular…). It can never be an emote code (those have no hyphen) or one of
  the `:shortcode:` emoji (a fixed list, none starting with `fa-`; the `/` is outside their characters),
  and `~` is not the separator because Markdown makes `~x~` a subscript. The server draws it in the TEXT
  of the finished HTML — never in an attribute, a link's address or a code block — wherever the rich
  text draws `:shortcode:` emoji (shouts in every format, plain included; descriptions; messages): an
  `<i>` with the style's classes from the package's manifest, `role="img"` and a label in the reader's
  language, only when the package has the face and that style loads. Anywhere else — the faces switched
  off, another icon source, the package deleted, the style unticked, and in every e-mail — it is the
  ordinary emoji the face stands for, or the face's name in brackets where there is none: never nothing.
  A token whose name is no face stays the text that was typed. A face stands on the line as an emoji
  does, in an emoji's yellow (a two-layer style with a dark line over it).
* **The package importer reads the index a download carries** — Font Awesome's own `icon-families.json`
  (with `categories.yml` for the emoji category), the compact `metadata/icons-search-vX.Y.Z.json` the
  owner's download script writes, or the older `icons.json` — and keeps what the site needs beside the
  package: `index.json` (each icon's label, search words and the styles that have it) and `emoji.json`.
  The owner's 6.7.2: 3,814 icons in 4 families, 110 faces; 7.3.1: 4,349 icons in 24 families, 113 faces
  (its ten digit icons were nearly lost: PHP turns the key "0" into a number). The report says so on a
  line of its own; an install takes about 1.4 s from the shell with the index read. An index is trusted
  only when it describes the package's own edition: Free metadata inside a Pro download says nothing
  about Pro's styles.
* **Found on the way**: `assets/js/icons.js` stripped every `fa-` class from any element it met — a face
  appended to a live grid lost its classes the moment it arrived; it leaves alone anything without `bi`
  now. And a line holding nothing but a face was dropped as empty by the paragraph splitter.

### Changed — the site's icons with the owner's Pro: closer glyphs, and what a family lacks known in advance

* **Searched in the owner's own indexes** — both Pro versions' `icons-search` files, every icon's name,
  label and search words — for the eight entries that stayed approximate even with Pro
  (`bootstrap-reboot`, `database-down`, `database-gear`, `envelope-paper`, `grid-1x2`, `hdd-network`,
  `send-check`, `ui-checks-grid`) and for every entry whose Pro choice might be bettered, the candidates
  drawn beside Bootstrap in solid and light and judged by eye where the site uses them; the result is one
  sheet, `scratchpad/shots1690/icons-pro-map.png` (untracked: it shows Pro's glyphs). Taken:

  | Bootstrap | Free (unchanged) | Pro now | |
  |-----------|------------------|---------|---|
  | `ui-checks-grid` | `table-cells-large` | `grid-2`, outlined | still approximate: its four boxes, without their ticks |
  | `grid-1x2` | `table-columns` | `rectangles-mixed`, outlined | still approximate: the very tiles, mirrored |
  | `megaphone` (the Appeals tab) | `bullhorn` | `megaphone`, outlined | Bootstrap's cone |
  | `chat-left-text` | `message` | `message-lines` | the square bubble with its lines of text |
  | `chat-left-dots` | `comment-dots` | `message-dots` | the square bubble, not the round one |
  | `calendar-range` (the menus' "last 14 days") | `calendar-days` | `calendar-range` | the range itself |

  A Pro glyph that is closer and still not the very icon is marked `proapprox` and counted as the
  approximation it is, never as a twin. Left as they were, the reason beside each in the map:
  `bootstrap-reboot` (Pro has no power sign with an arrow either; its circular arrows are the site's
  Refresh), `database-down` and `database-gear` (no database with an arrow or a gear in 6 or 7),
  `envelope-paper` (Pro already draws it as the open envelope's outline), `hdd-network` (7's `nas` is a
  drive box without a network), `send-check` (no paper plane with a tick). Looked at and refused:
  `box-arrow-in-down` → `inbox-in` (6's is the download glyph), `files` → Pro's `files` (`copy` says the
  same), `people` → Pro's `people` (whole figures, not Bootstrap's busts).
* **183 entries** — the picker's tabs added five and `balloon`, which only the old picker used, is gone:
  Free 157 exact and 26 approximations, Pro 6 173 exact (20 of them Pro twins) and 10, Pro 7 175 (23)
  and 8, in solid, no fallback.
* **What a Pro family lacks is known before it is drawn.** Pro 7's Jelly, Slab, Notdog, Utility and the
  rest draw a few hundred icons each. An icon the chosen family lacked was handed to the browser's font
  chain, which drew the classic glyph — a two-layer family drew it twice — and nothing on the server knew.
  With the package's index the map draws such an icon in the classic family at the same weight itself
  (an outline stays an outline) and the panel's coverage lists it: Jelly Regular 90 of the 183, Slab
  Regular 99, Notdog Solid 69. The preview's measurement, which found this out by drawing, now speaks
  only of what the index got wrong (for Jelly and Slab, nothing); without an index, all is as before.

### Changed — Settings: one question per group, and the page reads group by group

* **A group answers one question an admin asks, and a section lives where that admin looks first** —
  judged by its fields, not its id or an old title. What moved, and why:

  | What | Before | After | Why |
  |------|--------|-------|-----|
  | Operator digest (`#section-digest`) | Site & pages | **Contact & email** | It is an e-mail — a recipient, an interval, a threshold — and that group is how mail goes out. |
  | Health check (`#section-health`) | Site & pages | **Backups & maintenance** | One JSON answer for an uptime monitor: keeping the site running, not one of its pages. |
  | "Public Pages" (`#section-public-pages`), retitled **Archiving & the e-mail log** | Site & pages | **Backups & maintenance** | Its three fields are what the janitor archives or deletes after so many days — old reports, old appeals, the sent-mail log. Nothing in it was about pages. |
  | Audit log (`#section-audit`) | User accounts | **Backups & maintenance** | The panel's own log — who did what in the panel — and how long it is kept; nothing a member does to an account. |
  | Favourites and profiles (`#section-favourites`) | User accounts | **Profiles**, first | It holds the switch for the profile page itself, and the two lists a profile shows (favourites, registered torrents). |
  | Lists (`#section-lists`) | User accounts | **Profiles**, last | A member's collections, shown on their profile if they say so. |
  | Address lists (`#section-iplists`) | Tracker & whitelist | **Network & limits**, right under UDP traffic & rate limit | Its first words are "Trusted addresses above" — a field of that section — and the lists feed the same firewall. |
  | The blacklist file path (a field, with its Test) | Security → Rate Limits & Blacklist | **Tracker & whitelist** → Tracker mode & the accesslist file, under the whitelist file | It is the other mode's accesslist; the section's own intro already names both config lines. |
  | "Rate Limits & Blacklist" (`#section-limits`), retitled **Rate & length limits** | Security & CAPTCHA | Security & CAPTCHA (kept) | Per-address hourly limits of the report, status, block-check and appeal forms — the same four forms the CAPTCHA sections above guard — and the lengths they accept. Network & limits is the tracker's UDP traffic and the machine it runs on: another layer, another question. |
  | Ratings (`#section-reputation`) | Descriptions & review | **Descriptions & ratings**, after the descriptions | Nothing in the old group's name said ratings. What members add to a torrent — words, links, a vote — is one question. |
  | Group "Backups" | | **Backups & maintenance** / "Kopie zapasowe i utrzymanie" | It holds the health check, the audit log and the archiving now. |
  | Group "Descriptions & review" | | **Descriptions & ratings** / "Opisy i oceny" | So the ratings are found by the group's name. |

* **Kept or refused, with the reason.** *Pictures and profile covers* stays one section: every field
  in it is a picture/cover pair or shared by the two (one upload-size and one megapixel limit for
  both), and a split would strand the shared limits in one half. No new group: once two groups were
  renamed, every section had an honest home. *User Accounts* keeps its member-search and bulk e-mail
  fields — the search is the member system's own feature (its intro says so) and exists only with
  accounts on, and the bulk mail tool lives on the Users page, whose "switched off" note links straight
  to this section; moving either would need a section of its own, which is a decision for another
  release, as are the panel's two page-size fields in Rate & length limits and the Info panel's S/L
  refresh among the descriptions.
* **"All settings" follows the group order.** It is the page's own order, and the template had grown by
  accretion (site, mail, CAPTCHA, public pages, digest, health, ratings, live peer sync, …). The
  sections are laid out group by group now, and inside a group the one with the group's own switch
  comes first (User Accounts before the members' 2FA, OpenTracker Service before its tuning, UDP
  traffic before the address lists, Backups before the rest). A pure move: every section rendered
  before and after it byte for byte the same (comments aside), with no PHP warning — the schedule's
  state, which was computed at the end of the descriptions' section and read two sections later, is
  read in front of the schedule now, and four "next section" comments that had been left inside the
  previous one moved with the sections they describe. The section ids did not change: every
  `#section-…` link and bookmark still opens, now in its new group.
* The chip counts, the breadcrumbs of All settings and of a search, "Show me where" and the group
  keywords all follow the page, and the keyword catalogue (`settingsCatalogKeywords()`) is laid out the
  way the page is — group by group, `// #section-…` by section — with the same 375 key → words pairs:
  its headers had drifted (the "User accounts" block held the ratings, live peer sync and the
  description rules).

### Fixed — a hash a reader is not shown could be read back through a search

* **A profile's favourites** leave out a row's hash for a reader without `index.magnet` — and matched a
  search against that hash first. Searching somebody's public favourites for a prefix and reading the
  count spelt a hidden hash out sixteen answers per digit. The same order of operations was in **the
  registered torrents** (whose hash also goes out for no banned row, and whose hash half turned a `%`
  typed into the box into a wildcard) and **a list** (a substring match, even), and in **the catalogue
  search** for a group given `index.view` without `index.magnet`. The rule the likes / ratings list was
  built with, everywhere: the hash half of a search only where the hash is shown to this reader — your
  own favourites always, otherwise `index.magnet` and not a banned row — asked before anything is
  counted; the name half for everybody. In the catalogue a hex term is searched as a name for such a
  reader (`indexSearchCatalogue()` takes `hash_search`; absent means yes, so nothing else changes).
  None of the shipped groups is affected: guest has neither permission and member has both.

### Fixed — the panel

* **The reports table's header** was two dictionaries: the page drew it with `a.reports.c_*` and a tab
  switch redrew it with `js.reports.col_*`, and in Polish they disagreed — the first column said
  "Zgłaszający" until a tab was clicked and "Imię i nazwisko" after, the object column "Utwór" then
  "Obiekt". One set now, for both paths (`js.reports.col_*`; the nine `a.reports.c_*` keys are gone):
  "Imię i nazwisko", because the column holds an appellant's name as well as a reporter's and is the
  form's own word for it, and "Utwór", the word the report form and the status page use for the work
  reported. The English pair was one wording already.
* **The appeals' Type badge** fits its column: the Polish "Odblokowanie" badge measures 91.3px and the
  cell had 68 of content box. The column is 112px — the longest badge in either language plus the
  cell's padding, and a few pixels for a system font a little wider than the one it was measured in.
* **A select looks like a select.** The toolbars' filters (Reports, Whitelist, Index, Users, Log) had no
  arrow at all — a `background` shorthand with `!important` reset the image Bootstrap draws it with —
  and every other select in the panel had Bootstrap's arrow for a LIGHT field, #343a40 on #212529:
  1.3:1, there and not there. The filters set the colour alone now, and the panel's selects carry
  Bootstrap's own dark-theme arrow (#dee2e6, a `data:` image the policy allows): 11.8:1 against the
  field, measured, in both icon libraries (it is not an icon, so it is the same in either).
* **Favourites rows** said "Magnet" as a literal; they use the dictionary's word (`js.app.magnet`), as
  the search results do, so a translation reaches them too.

### Fixed — opening Settings, or the Whitelist's Review tab, wrote to the audit log

* **Found by walking the panel** — every page and every tab of each, the log read after every step.
  Settings wrote `twofa.change` (the members' 2FA section asks for its status) and `panel.fed_review`
  (the federation queue's list) on every visit, and the Whitelist's Review and Rewrites tabs
  `content.review` (the queue, and a row's edits): the router logs each POST to an admin endpoint under
  the endpoint's action, and these reads are POSTs. A `twofa.change` line on a mere visit would alarm
  anybody reading the log. The reads write nothing now (`auditSuppress()`, as the bulk-mail tab's
  reads above), and a content decision is logged as what it was — `content.approve`, `content.reject`,
  `content.clear`, `content.edit_apply`, `content.edit_reject`, the names the log's Content filter
  already listed and nothing wrote.
* **The same kind of read, found by going through every panel POST that only asks**: the tracker mode's
  status, live peer sync's status and plan, the network, sysctl and OpenTracker previews, the cluster's
  plan and the federation purge's count. Each is silent from the moment the endpoint knows it is a read,
  so one refused because its feature is off is silent too.
* **Seven garbled dashes in the stylesheets' comments** — an em dash whose three bytes were once read as
  Windows-1252 and written back, four in `assets/css/style.css` and three in `assets/css/admin.css` —
  are dashes again; no other file this release changed carries such a sequence or a stray control
  character.

### Fixed — the account page scrolled sideways on a phone

* **Four causes, found by measuring every tab** at every width from 320 to 1440 in both languages
  (with the ratings on and off): the messages default is a select, and a select is as wide as its
  longest option — 392px, 433 in Polish, in a card as narrow as 260, from 320 to ~900px wide; the
  account table (a label column that never wraps beside the e-mail address and an "Edit on your
  profile" link that could not break) was 369px wide where its card had about 300, at 375 in Polish;
  a list's card grid asked for a 320px column at 320; and the People tab's four sub-tabs never wrapped
  (98px too wide in Polish at 320). The select is at most its card's width, a value in the table breaks
  before it widens the card and on a phone the labels and the link wrap, the grid's column is never
  wider than its space, and the sub-tabs wrap. Nothing scrolls sideways at any width from 320 up.

### Changed — a profile's name row over a cover, and the line on your own profile

* **Readable over any cover.** Over a bright cover the name row was grey on light stripes — the name,
  "member since", "this is your profile" and the outlined Message / Add as friend / Block / Share. The
  row now has the description's translucent backing (the same geometry, one shade for both, 0.55: the
  description's was 0.42 and a shadow was the row's only help), the name is white, the date and the line
  a light grey, and the buttons (and a friendship badge) are filled, so no label meets the photograph.
  Measured on the striped test cover the description's check paints, text against the worst pixel
  behind it: 1.1–1.8:1 at 1280 and down to 1.0:1 on a phone before, 5.76:1 at the least now, on your own
  profile and somebody else's, with a description and without, desktop and phone, English and Polish,
  in both icon libraries. Without a cover nothing changed. Over one, the row's wrappers stay boxes even
  with no description (the picture is out of the flow there, so nothing moves) — they have to, to
  carry the backing.
* **"this is your profile, as others see it"** had stopped being true twice over: your own profile draws
  the description's edit box, which nobody else gets — and it always showed your favourites, likes,
  registered torrents and lists whether or not you had made them public. It now says **"this is your
  profile — others see only what you show"** / **"to twój profil — inni widzą tylko to, co
  pokazujesz"**: true in every case, and two lines on a phone as before.

### Tests

* `tests/profile_bio_test.php` (new): both schema paths on a scratch database, the two settings in their
  four places, the permission and its one-time grant, the renderer (each tag, every other tag literal,
  nesting, crossed, unclosed and stray tags, `javascript:`/`data:`/`vbscript:`/`//host`, a quote and an
  event handler inside an address, `<script>`, `&lt;`, bidi/zero-width/control characters, a four-byte
  emoji, and a hostile mix read back through a DOM: only strong/em/u/s/a/br, an `<a>` only
  href/rel/target/data-external), the four caps, every refusal of the save and its success, the
  endpoint file run as a request in a child process (session, token), hidden-when-the-grant-is-lost and
  back, the admin blanket, and the panel's clear run the same way: text gone, member told, one audit
  line, a second clear that does nothing.
* `scratchpad/shots/profile_bio_check.js` (new, in `browsersweep.sh`), under an enforced policy: the
  placeholder, the toolbar, the counter (and its twin against the server on 23 strings), Save, reload,
  another member's view, Esc / Cancel / Enter / Ctrl+Enter, a click on a link, the account row and
  `#bio`, the in-place language switch, the head at 1280 and 375 with and without a cover, both icon
  libraries, the setting off and the permission taken away (nothing shows, the endpoint refuses), the
  panel's Clear. Screenshots in `scratchpad/shots1690/`.
* `tests/groups_matrix_test.php`: the member row of the matrix carries `profile.bio`, and the
  existing-install pass predates the v74 grant as well.
* `scratchpad/shots/media_check.js`: on a phone the band's FLOOR is `cover_height_mobile` (its computed
  min-height), no longer "under 220 px" — the head it holds may grow it, and on your own profile it now
  holds the description's box.
* `tests/profile_votes_test.php` (new): `users.votes_public` on both schema paths (a scratch database);
  the setting in its four places; `rating.public` registered, in the member preset, granted once, no
  with accounts off; the gate one flag at a time (the switch, ratings, accounts, profiles, the owner's
  flag, a group without the grant, an owner only in the admin group — not shown, until that group is
  granted it — a reader who may not open profiles, a block that hides the profile and one that does not,
  a suspended owner, nobody signed in) and the account page's context; the list: an anonymous vote
  carrying the owner's id as its key never listed, each mode's values only, every sort both ways with
  the too-few after every shown score in both, each filter, the whitelist arm, a banned row, the hash
  withheld, pagination (a page past the end is the last one), the reader's zone, every parameter clamped
  and defaulted; EXPLAIN and a stopwatch on three thousand votes; the endpoint and the privacy save, each
  run as a request in a child process with a session and its token.
* `scratchpad/shots/profile_votes_check.js` (new, in `browsersweep.sh`), in both modes under an enforced
  policy: the tab after Favourites; header sorting against the order the database implies; each filter,
  the pager keeping it, a filter change going back to page 1; another member seeing nothing (and a 404)
  until the privacy switch is ticked, then the section after Favourites in "their" words; the live
  switch to Polish redrawing the rows; 375px (cards aligned, no value broken, nothing sideways) and
  768px (three columns, the tab bar wrapping);
  no header spilling out of its column or leaving its arrow alone, at 1280 in both languages; Font
  Awesome; the switches above it (and an old link to `#votes` landing on the overview); the Settings
  section. Screenshots `scratchpad/shots1690/votes-*.png`.
* `tests/groups_matrix_test.php`: the member row carries `rating.public`, and the existing-install pass
  predates the v75 grant as well.
* `tests/admin_access_test.php`: the template lays its sections out group by group in the sub-menu's
  order (so a section added at the end of the file under an early group fails), each section 1.69.0
  moved is under its group, the blacklist file path is in the accesslist section beside the whitelist
  file (and nowhere else), and the two renamed groups carry their names.
* `tests/favourites_test.php` §12: the favourites, registered-torrents, list and catalogue-search
  endpoint FILES run as requests in a child process, with the sessions of three scratch accounts each in
  one scratch group (no panel session): a hash prefix / piece finds nothing for a reader without
  `index.magnet`, the name still does (without the hash), a reader with it finds the row, a banned row is
  found by no one's hash, a `%` is no wildcard, your own favourites keep theirs. Against the endpoints as
  they were, seven of these fail.
* `scratchpad/shots/settings_groups_check.js` (new, in `browsersweep.sh`), in English and Polish: every
  chip's count is what it shows and it shows exactly its own sections, its switch first; All settings
  reads group by group in the chip order; every breadcrumb names its section's group; a search finds each
  moved setting under its new group and the new group names find their sections; "Show me where" and
  every moved section's `#section-…` link open the new group; the two accesslist files sit together.
  Screenshots `scratchpad/shots1690/settings-*.png`.
* `scratchpad/shots/panel_fixes_check.js` (new, in `browsersweep.sh`): all 156 visible selects on eight
  panel pages carry the light arrow, and it measures 11.8:1 on a toolbar filter, a Whitelist filter and a
  Settings select, in both icon libraries; the reports header is the same words drawn by PHP and redrawn
  by admin.js, in both languages; both appeal badges fit their column at 1440 and 1280, in both languages;
  a favourites row's Magnet is `js.app.magnet`.
* `scratchpad/shots/account_width_check.js` (new, in `browsersweep.sh`): every tab of the account page,
  English and Polish, ratings off and on, at 37 widths from 320 to 1440 and on five emulated phones —
  `scrollWidth` never above `clientWidth`; it names what sticks out when it is. Its first run named the
  four causes above.
* `scratchpad/shots/profile_head_check.js` (new, in `browsersweep.sh`): the contrast measurement above —
  a screenshot with the row's text made transparent, decoded, each text's colour against the worst pixel
  under its line boxes — in 32 combinations, the line on your own profile in two lines at 375, and
  without a cover nothing changed. `scratchpad/shots/profile_bio_check.js` expects the description's
  backing at 0.55.
* `tests/iconpack_test.php` (new), on synthetic packages made in the temp directory (fonts that are a
  signature and padding — never a Font Awesome file): the names; every way of nesting a download and the
  two-root refusal; hostile archives (traversal, absolute, drive letter, backslash, a NUL, control
  characters, a symlink, duplicates, a case collision, a bomb by ratio, sizes that lie, too many files);
  hostile stylesheets and fonts; detection and the manifest; a full download with and without
  `metadata/` (`fontawesome.css` as the core, the compatibility sheets left out and a tampered one
  refusing the package, Solid / Regular / Brands always loaded); the served files and their headers over
  HTTP against the local site; setups, maps and the settings' judgement; the store; `.gitignore` and
  `git check-ignore`; the CLI and the panel endpoint run as child processes, with their audit lines.
  Where `TRACKER_FA_PRO_DIR` (default the owner's Fonts folder) holds Pro folders, each is found by
  looking, zipped into the temp directory and installed, and what detection makes of it is held to
  what the folder holds — every face-declaring sheet a style, the core, the left-out sheets and their
  reasons, which styles all.css covers (read off the faces: Pro 6.7.2 11 of its 17 outside, Pro 7.3.1 31
  of 37, every family 7.x added among them) — the names counted a second way, the sheets a page links,
  the coverage; then deleted.
  Without them: a SKIP line that says why.
* `tests/icons_test.php`: the four settings in their four places, Free 7's tag and SRI, a missing
  package's fallback, a package's stylesheets, every map entry's shape, the approximations (26 now) each
  with its reason, the per-setup maps, the pins (the grid's width too), the filter drawing the page's
  setup.
* `scratchpad/shots/icons_check.js`, in seven modes: Bootstrap, Free 6, Free 7 and — where the owner's
  folders are — Pro 6 and Pro 7 installed through the CLI, each in solid and in one style of a file of
  its own (Sharp Solid, Duotone Light); per mode the glyph test, the fonts, the sheets, the icon-only
  buttons against Bootstrap's widths and heights, and every map entry drawn. The glyph test
  reads the FIRST string of the computed content: 7.x writes `var(--fa) / ""`, which computes to
  `"X" / ""`, and the old way of stripping the quotes drew three extra characters beside a missing
  glyph's box — every 7.x glyph would have passed (shown with a code point no font has). The panel's
  preview reads it the same way.
* `scratchpad/shots/iconpack_check.js` (new, in `browsersweep.sh`): a synthetic Pro package zipped two
  folders deep, through the panel — a refused upload, the install and its report, Use with the password,
  the style ticks, the preview, the save, the served headers, Verify, Delete refused while in use, back
  to Free 6, Delete — and the audit lines it leaves. A Save writes every field of the form (a row for
  each setting that had none, the time zone the select shows), so the check puts the whole settings
  table back, row for row. Screenshots `scratchpad/shots1690/iconpack-*.png`.
* `scratchpad/shots/icons_align_check.js` (new, in `browsersweep.sh` after icons_check): the measurement
  above as a check — every icon beside words on every public page, panel page and tab, account tab,
  menu, bulk bar and dialog, in eight modes (Bootstrap; Free 6 and 7; and, where the owner's folders
  are, Pro 6 and 7 solid and light and Pro 7 Jelly, installed through the CLI for the run and deleted
  after), in English and Polish, at 1280/1440 and 390: each icon's ink middle within a pixel of its
  words' cap middle, and the gaps of one kind of button within a pixel of one another, the worst
  offenders named. A pair it cannot see whole — cut by a scroller's edge or the window's, behind a
  dialog, moved between being found and being photographed — is scrolled into view on its own or left
  out and counted, never measured as a sliver. With 1.68.1's stylesheets served in place of the
  repository's (request interception; the repository untouched) it fails 32 of the 44 states of the
  owner's places (the Whitelist page with its tabs, menus, dialogs and bulk bar; the Index page with its
  menus and bulk bar; the Transparency table) in Bootstrap and Free 6 — in Bootstrap Refresh S/L 1.8px
  further from its word than its kind's middle and Fetch metadata 1.5px nearer, the Index bulk bar's
  Delete 1.4px high; in Free 6 the icons 0.8 to 2.7px low, the Transparency headers' arrows 1.5px, and
  the bulk bar's Fetch metadata 5.1px nearer its word than Clear.
  `scratchpad/shots/align_shots.js` (new) photographs the owner's three places — the Whitelist's
  navigation, status, tabs, toolbar, bulk bar and rows; the Index toolbar and bulk bar; the public
  Transparency table's sort headers — in every mode, before and after:
  `scratchpad/shots1690/align-<mode>-{before,after}.png`.
* `scratchpad/shots/icons_check.js`: the icon-only buttons are held to Bootstrap's heights as well as its
  widths — Free 6's ruler is gone, Font Awesome's box being Bootstrap's now — and `data-label` is not a
  difference in the markup.
* `tests/icons_test.php`: the placement (the cap-middle rule on the parent's `cap`, Bootstrap's box to
  1/64px, Font Awesome's at vertical-align 0 and width auto), the fixed box only without `data-label`,
  the rows and their one gap — their display at one class's weight and before `.d-hidden`, so a hidden
  icon button stays hidden (a row that outweighed it showed Backups' "Cancel run" and, on a phone, the
  pagers' First and Last; a probe comparing every control's display with the old stylesheets' on every
  page found them) — the output filter's `data-label` (bare text, a `<span>`, icon-only, words
  after, twice, an unknown name), the observer following words and measuring Font Awesome's glyphs, the
  open disclosure's chevron that points down and nothing turned; `patch-check` is the circled tick.
* `tests/audit_test.php` §4b: admin/bulk_send run as a request in a child process — its four reads write
  no line; a queue, a cancel and a test copy write bulk.queue, bulk.cancel and bulk.test.
* `tests/iconpack_test.php`: a folder as the owner's download script leaves it — a root `manifest.json`
  of its own, `metadata/icons-search-*.json`, 7.3.1's `svg.css` — installs: the manifest skipped, the
  index kept (and, since the importer reads it — the emoji picker below — described as the Pro index it
  is), svg.css left out as the SVG build; the verified badge's Free fallback.
* `tests/emoji_test.php` (new): the data files — the same 1,906 emoji in the same order in both
  languages, each group's count, every name and keyword list filled in both (the Polish in Polish), the
  keywords one lower-case word list, each toned variant the emoji with one tone's modifier and nothing
  else changed, nothing newer than 16.0 by Unicode's own ages (ICU) and by the lists of what 16.0 and 17.0
  added, no part on its own, the presentation selector only where it belongs, the attribution and the
  licences, the generator; the faces' file (113, four pages, an emoji each, names and words only); the
  token's grammar, that no emote code or shortcode can be one, and the picker's twin of it; the fallback
  through every renderer (descriptions, messages, a shout in each format, a mail), a face in `[code]` and
  in an address, hostile input; the two settings in their four places and their words in both languages;
  the script and the widget (no emoji in the script, the data asked for when the picker opens); and
  api/shout_emoji.php run as a request in a child process — refused with the room off, off for Bootstrap,
  and, where a Pro package is installed, the faces in the language asked for.
* `tests/iconpack_test.php` §12 (new), on synthetic packages: an index in the owner's shape and in Font
  Awesome's own (`icon-families.json` with `categories.yml`) recognised, `index.json` and `emoji.json`
  beside the package and Verify still clean, a family lacking icons drawing them in classic by knowledge,
  Free metadata inside a Pro package not trusted; the faces' context and every token case (the default
  style, a style of its own, a style not loaded, a name that is no face, the faces off, brands, hostile),
  a line of faces only, the shared renderer and a mail, the picker's data. §11, with the owner's folders:
  the index read from his own file — 110 and 113 faces, the style sets, Jelly Regular's knowledge held to
  the file itself.
* `tests/icons_test.php`: the 183 entries' shapes, `proapprox` only where a Pro twin is drawn and the
  entry stays `approx`, the item-by-item choices above; the picker's tabs are names in the map; the
  shoutbox script is no longer the exception to the no-emoji rule — its emoji are data now.
* `tests/audit_test.php` §4b: the child-process runner takes any admin endpoint; thirteen reads (2FA
  status, the federation queue's counts and list, the content queue and a row's edits, the tracker mode's
  status, live peer sync's status and plan, the three previews, the cluster's plan, the purge's count)
  write no line, and four decisions write theirs — a 2FA change, a federation decision, a content
  approval in the Content group, a mode change — refused here, as the test sends no password, and logged
  all the same. `api/admin/ot_apply.php` reads its body with `readJsonBody()`, as its neighbours do, so the
  runner can reach it.
* `scratchpad/shots/emoji_picker_check.js` (new, in `browsersweep.sh` after shout_check), under an
  enforced policy: nothing fetched before the picker opens; Recent, then Unicode's nine pages, every emoji
  of each (the data file counted, not trusted) and the long ones drawn in slices; the search in English
  and Polish, without accents, English as the fallback asked for only by a search; the keyboard; the
  variants held with a mouse and with a finger (touch emulation), a right click and Shift+Enter, the tone
  remembered and drawn in the grid, a plain click inserting at once, exactly what lands in the box; a
  posted shout drawing it; on a phone nothing sideways and no keyboard over the picker. Where the owner's
  Pro folders are: the package installed through the CLI unless it already is, `mixed` and `fa`, a face
  held for its styles, one posted in Light drawn by that style's font beside one in the default style,
  the Polish names, then the faces switched off and the same shout showing the emoji each stands for.
  Settings and groups put back row by row, its shouts deleted, a package it installed deleted with its
  audit lines. Screenshots `scratchpad/shots1690/emoji-*.png` — those with Pro glyphs untracked with the
  rest of the scratchpad.
* `scratchpad/shots/shout_check.js`: the picker loads its data when it opens — the check waits for it,
  starts from an empty Recent, and expects Recent and Unicode's pages, in order, before the emotes.
* `scratchpad/shots/icons_check.js` removes its audit lines by id above the log's last one before the
  run, as `icons_align_check.js` does, not by a window of time, which the mysql client's clock two hours
  ahead of the app's broke.

## [1.68.1] — 2026-09-24

No schema change (still 73). Three things the owner found with Font Awesome switched on in production,
fixed at their causes in both icon libraries — and what a systematic look for the second one turned up
in either.

### Fixed — Magnet stood a pixel above Copy and Info

* **In the search results the text of Magnet sat 1px higher than the text of the buttons beside it.**
  Measured in the browser, button box against text box: the `<a>` and the `<button>`s share padding,
  line height, font and display, and differ in one thing — the edge. The public `.btn` (the blue
  primary: Magnet) had `border: none`, `.btn-secondary` (Copy, Info) a 1px border. The results row
  stretches its items to one height, so Magnet got the height but not the edge, and its text began at
  the padding instead of a pixel lower. Where a row centres its items instead — the favourites on the
  profile and the account page, a list's rows — Magnet stood 2px shorter than Info. Every `.btn` now
  carries a 1px border, transparent on the primary (its fill runs under it), so primary and secondary
  buttons are one size wherever they meet: Save and Cancel in the shoutbox and the account's security
  forms, a list's Save and Create, the picture editor's footer (whose stylesheet carried this fix for
  itself alone; that nudge is gone).
* The same pairing, checked wherever buttons stand in a row: the visibility toggle on an upload's row
  and on a list's card (0.4px shorter, its word 0.8px lower) has the small button's box; the Info
  panel's "+" (21.6px tall between two 28px buttons) has its neighbours' box; the favourite star is as
  tall as the buttons it stands with.

### Fixed — the Whitelist lost its delete button with Font Awesome chosen

* **The actions column showed eye, magnet, clipboard, lock — and "…".** 1.68.0 gave the icon of an
  icon-only button a fixed 1.25em box under Font Awesome (its glyphs are 0.625–1.25em wide, Bootstrap's
  all 1em), so every such button was a quarter of its font size wider than the one the column had been
  measured with: five buttons needed 176px in a 168px column that ellipsises. The box stays 1.25em, so
  every glyph is still centred on one axis, but it takes 1em of the line; and the icon element keeps the
  text's font family — Font Awesome's class gave it its own, whose ascent made every icon button a pixel
  taller too. An icon button is now the same size in either library (27.28 × 25.5 against
  27.27 × 25.5), so a column sized for one fits the other.
* **An actions cell in the panel's tables never clips its controls**: should its column ever be too
  narrow, the buttons wrap onto a second line instead of one of them vanishing behind "…".
* **What an audit found beside it** — every public page and every panel page and tab, both libraries,
  English and Polish, 390 to 1920px wide (the toolbars to 2560), looking for anything that cuts off a
  button, a form field or an icon:
  * Whitelist → API clients: three buttons in the two-button column; delete was cut off in either
    library. The column holds three.
  * Whitelist: the Name column was 0–4px wide from 1280 to 1440 (every name invisible, its header's
    sort arrow cut off) and 20px just above 1700. The pinned columns had grown to 1110px — Files arrived
    in 1.8.0 and nothing was tightened for it — over a table of 1040–1114. The narrow set is re-balanced
    from measured needs (the hash ellipsizes a little sooner, Size fits "ROZMIAR"), a floor keeps Name at
    96px or more, and the full-hash set waits until 1830px, where it leaves Name 150.
  * The toolbars' filters: the Whitelist's five selects were pinned at 170px in a cluster capped at
    1100, so its last filter was cut at every width and at 1920 three were off screen; the Index lost its
    per-page select at 1680. The cluster asks for what its contents need now (the right-hand controls
    move down first), each filter is as wide as its longest option, and a filter that does not fit wraps
    onto a second line under a divider.
  * Users: a long e-mail address pushed its "verified" badge out of the cell. The address ellipsizes on
    its own; the badge stays.
  * Sortable headers that lost their arrow and then their own name: the reports' "Company" at
    1280–1440 and the appeals' "Report", and in Polish half the panel ("NAZWA UŻYTKOWNIKA", "OSTATNIE
    LOGOWANIE", "PIERWSZE / OSTATNIE", "ZGŁOSZENIE", "ROZMIAR", "WIDZIANE") and the public search's
    "Ostatnio widziany". A header wraps between its words now instead of ellipsising, and the columns
    whose longest word did not fit are wider: the reports' name pinned at 130, the appeals' report
    column 112, the Index's size and seen, the search's last-seen.

### Fixed — the Settings search's highlight ran over the text

* **Searching "icon" drew three blue bars at two x positions, one of them through the first letter of
  every line of a block** ("Custom emotes" — the C under the bar). Each mark was an inset shadow on the
  matched element's own left edge, and those edges are not in one place: a field is a grid column whose
  edge sits half a gutter left of its text, while a block inside a section (Shoutbox → Emotes and
  stickers) has its edge at its text. "Show me where" and a #section link drew a third thing, a 1px
  outline round the section.
* One mark for all of them: a soft blue tint under the text with a 3px accent on its left, measured
  from the text edge rather than the box edge, so every accent stands on one x, 9px clear of the text,
  inside the section's own padding. A field, a block, a whole section matched by name and the section
  "Show me where" lands on carry the same mark; a field that matched inside a block that matched reads
  as one bar, the field a shade stronger. The categories are unchanged (1.69.0 reorganises them).

### Tests

* `scratchpad/shots/icons_check.js`, in both libraries, on every page and panel tab it visits: no
  control or icon is cut off (an ancestor that clips, following a positioned box's containing blocks, or
  a table cell wider inside than out — the rating's half star is the one clip by design); the buttons of
  every search result's row and every favourites row on the profile are one height with their text on
  one line (±0.5px). It makes the rows it needs — two favourites, a report, an appeal, an API key — and
  removes them again, and it now opens the panel's tabs as well.
* `scratchpad/shots/settings_hit_check.js` (new, in `browsersweep.sh`): searches "icon" and reads every
  mark's accent against every text box and icon of its block — none touched, every accent on one x, the
  tint under the text — then a section matched by name, the "Show me where" jump and a #section link:
  the same mark on the same x. Both libraries.
* `tests/icons_test.php`: the fixed icon box takes 1em of the line and the icon element keeps the text's
  family, in both stylesheets.

## [1.68.0] — 2026-09-23

Schema 73, one new setting. The owner asked for a choice, in the panel, between Bootstrap Icons and
Font Awesome for the icons of the whole site — and for no standard emoji or symbol character doing an
icon's job anywhere. Both are here, and the choice reaches the public pages and the panel alike.

### Added — the icon library, one setting for the whole site

* **`icon_library`** in Settings → Site: `bootstrap` (the default) or `fontawesome`, in its four
  places and coerced to `bootstrap` on save like `csp_mode`. The hint says it is the whole site.
* **One head helper, `iconFontTag()`** (includes/icons.php), prints the font in all nine page heads and
  in the installer — and on EVERY public page. Until now the layout asked for it on a handful of
  actions, and an icon drawn anywhere else (the inbox's bin with pictures and covers off) was an empty
  box. Bootstrap: the same tag as before. Font Awesome: Free 6.7.2, the CSS webfont build, from
  jsDelivr with Subresource Integrity (the SHA-256 jsDelivr publishes for the file). Never the JS/SVG
  build, which would need jsDelivr in the public `script-src`; the policy does not change.
* **Every icon stays written one way**, in Bootstrap's markup, and Font Awesome is laid over it through
  one name map (`iconFaMap()`, 177 names, sent to the browser as JSON). With Font Awesome chosen the
  server adds the mapped classes to every `class="bi bi-NAME…"` attribute of a page (an output buffer
  started in index.php), and `assets/js/icons.js` does the same for what scripts build — added nodes
  and `className` reassignments, looking only at the added subtrees. The `bi` and `bi-NAME` classes
  stay, so every rule, selector and test that names `.bi` or `.bi-trash` keeps working, and the
  progress bar in the Languages table (an `<i>`) is left alone. **With Bootstrap chosen neither the
  buffer nor the script exists: a page is byte for byte what the templates wrote.**
* **Font Awesome sits where Bootstrap's glyphs sit**: its box is put back as Bootstrap keeps it (an
  inline element, the glyph an inline-block lowered 0.125em), its face is pinned on the glyph the way
  Bootstrap pins its own (a bold rule must not pick the regular face and draw a box), and icon-only
  buttons get a fixed 1.25em box, because Font Awesome's glyphs are 0.625–1.25em wide where
  Bootstrap's are all 1em. The public layout loads the icon font before its own stylesheets now, as
  the panel always did, so a site rule that places an icon wins over either library.
* Free has no direct twin for 27 Bootstrap names (the octagons, the shield variants, the database and
  drive stacks, the reboot mark, the dot, the balloon, an outline pin, a few more); each map entry
  names the closest honest glyph and says why. Every entry was drawn on a live page against the font's
  own missing-glyph box before it went in.
* The page-content preview (an iframe filled through `srcdoc`) carries the icon font and, with Font
  Awesome, the mapped classes, so a YouTube mark or a task box looks there as it will on the page.

### Changed — no emoji or symbol character doing an icon's job

Everything below is Bootstrap Icons markup now, so it follows the setting like every other icon.
Content stays content: the emoji the picker offers, emoji in messages, the `:shortcode:` map, emote
images. Prose stays prose: an arrow between two values, a middle dot between facts, "3×", a dash.

* The muted-sound note in the navigation and the sentence that describes it (`bi-volume-mute`); the
  close buttons of the Info, list, shelf, bookmark, "who has this", report, terms and file windows and
  of the picture editor (`bi-x-lg`, drawn at three quarters of the old size so the cross weighs what
  the multiplication sign did); the list window's remove and the Info panel's "put in a list".
* The four rich-text toolbars (messages, the whitelist form, the Info description editor, the
  shoutbox) draw the same icons as the panel's copy: bold, italic, underline and strike, colour,
  size, highlight, sub- and superscript, link, image, both lists, quote, code, table, spoiler,
  centre, rule. Titles and labels are unchanged; the now unused `rt.list_word` is gone.
* The sound test's play mark; the YouTube mark on video links and the task-list boxes in rendered
  descriptions (an e-mail, where no icon font loads, gets the brackets the author typed instead); the
  emoji picker's TABS (its grid is content and stays emoji); the favourite star; the message report
  flag; the password checklists (public and panel); the vote buttons (thumbs, not triangles); the
  search table's sort arrows; the star beside a rating in the results; the magnet copy's tick; the
  "Sent" tick on the verification button; the search and notification pagers (the words are plain
  words in the dictionary now, the chevrons elements beside them).
* The five-star rating is built from two copies of the library's filled star — a dim one and a lit one
  in a clipped box — instead of a star character drawn by CSS; half stars stay, centred in either
  library.
* Every `<details>` marker is a chevron in the markup that turns a quarter when open, as the
  permission matrix's already did: descriptions, spoilers, the audit log's details, the settings'
  "more" folds, the syntax help, and both file trees (these had the browser's own triangle).
* The panel: the Settings tests' marks (tick, cross, info, circle, dot), the group expiry hourglass,
  the Index's protected and promoted badges, a list's last-error warning, the sysctl results, the
  message reports' pager, the home layout's grip, the language upload's arrow, the settings
  breadcrumb, the permission matrices' empty cells, and the database memory's pending mark.
* The installer loads Bootstrap Icons through the same helper (it has no settings to read) and marks
  its checks, password rules, warning and delete button with icons.

### Fixed — the shoutbox composer's scrollbar ran under Send

* A long message made the field scroll, and its bar was drawn under Send and beside the picker
  handle pinned in the field's corner. The bar's width is measured (what the field is wide beyond its
  client area, less its borders) and handed to the stylesheet as `--shout-sb`; the two buttons stand
  that much further in. Re-measured on input, on the field's own drag-resize and on a window resize
  (a ResizeObserver on the field), and after a live language switch. `scrollbar-gutter: stable` keeps
  the text from re-wrapping when the bar appears. Where scrollbars overlay the text the width is 0 and
  the buttons keep their place.

### Documentation

* The README opens with a **Support the project** section — the Monero, Bitcoin and Ethereum
  addresses the public tracker's home page lists, each with the network to send it over — and a
  donate badge beside the others that links to it. The table puts each address on a row of its own,
  so the 95-character Monero one fits a repository page without a scroll bar. The addresses were
  compared byte for byte with the live home page and checked against their own checksums (bech32,
  EIP-55, Monero's Keccak) before they went in.

### Tests

* `tests/icons_test.php` (new): the setting in its four places; the helper as the only place a font is
  named; every `bi-*` name used anywhere has a map entry, and no entry is unused; with Bootstrap no
  filter is installed and a page goes out byte for byte, with Font Awesome the filter adds and never
  alters (strip the additions and the Bootstrap page is left); a scan of every template, include,
  script, stylesheet and dictionary source, comments aside, for an emoji or symbol character standing
  in for an icon.

## [1.67.0] — 2026-09-23

No schema change (still 72). Nine things from the owner's fifth pass over the live site: two real
bugs, the shoutbox's row controls, and four small visual defects. Every icon touched here is a
Bootstrap Icons glyph — no emoji and no Unicode symbol — so the icon-library switch that comes next
has one kind of thing to map.

### Fixed — the shoutbox Preview said "Invalid CSRF token"

* **The Preview tab posted an empty token on the front page.** The shared editor (assets/js/app.js)
  looked for its token in a list of page-wide places — the whitelist form's field, `#account-csrf`,
  `#search-csrf` — and the shoutbox keeps its own as `#shout-csrf`, which the list did not know. Now
  an editor finds the token that belongs to IT: an element named by `data-csrf` on the textarea or an
  ancestor (the shoutbox names `shout-csrf` on its root, covering the composer and every line's
  in-place editor), otherwise the nearest token field walking out from the textarea — its form, its
  widget, then the page. The whitelist form, the Info panel's description editor and the message
  composer find theirs exactly as before.
* **A shout previews by the room's rules and through the room's renderer.** `for: 'shout'` now takes
  the room's syntaxes (`shout_format`, not the description switches), draws the words through the one
  pipeline every line in the room is drawn with — mentions and emotes included — and reports exactly
  the problem pressing Enter would be refused with. `shoutBodyHtml()` / `shoutBodyProblem()` in
  includes/shout.php are shared by the list, posting, editing and the Preview, so the four cannot
  drift apart. Whatever the renderer does not support comes out as text, as it would once said.
* The Preview's image and link counts are taken in the syntax the text is written in (a Markdown link
  was counted as BBCode, i.e. as nothing), and a message's Preview is judged against the message
  length limit its send uses.

### Fixed — closing a list reloaded the shelf behind it every time

* The list window reloaded the whole shelf on every close, so that a card's count agreed with what
  was changed inside. Now it reloads only when something WAS changed: every write to a list (an item
  added or removed from its own box or rows, or through the "Put this in a list" picker opened over
  it) marks the window, marked when the request is sent so a close during an add still counts, and
  the reload waits for that write to land. A list opened, read and closed fetches nothing.

### Changed — the shoutbox's row controls

* **Pencil, pin and bin are one group**: one flex container at the right end of the row, one gap,
  one icon size, one 1.5rem hit box each, centred on the row, in the order the row always read —
  correct it, pin it, take it away. They were three separately placed buttons at three heights with
  an uneven gap before the cross. The group's width is reserved on the rows that have one, so the
  controls never cover the end of a line and nothing moves when they appear; the delete question
  still stands at the end of the line, and on a touch screen the group is always shown.
* **Bootstrap Icons throughout**: `bi-pencil`, `bi-pin-angle` / `bi-pin-angle-fill`, and `bi-trash`
  for delete — the inbox's bin, one meaning and one glyph. The pinned strip's pushpin and cross and
  the composer's emoji-picker handle (`bi-emoji-smile`) went the same way; the picker's grid of emoji
  is content and stays emoji.
* **The pin toggles.** On the line that is pinned it is drawn filled and says "Unpin", and pressing it
  takes the line down. The toggle lives in the button; the server does exactly what it is asked. A
  first build toggled on the server too, and a browser check showed what that meant: a moderator
  whose page had not yet seen a colleague pin a line would press "pin" and take the colleague's
  announcement down. So `pin` on a line already pinned changes nothing, and `unpin` takes down that
  line only — before, it cleared whatever was pinned, so a stale "Unpin" could remove a different
  announcement altogether. Decided on a locked read inside the transaction; a request that changes
  nothing writes no audit line.
* **No highlight left behind after Cancel.** A click on Cancel or Save left the focus on the pencil,
  and the row's controls stayed up (and the pencil lit) until somebody clicked elsewhere. As with the
  format select in 1.62.0, a pointer press is now remembered and any key forgets it: after a pointer
  Cancel or Save nothing is focused, while Esc or Enter still hands the keyboard its pencil back with
  a visible ring. The controls show on hover and on `:focus-visible`, never on a bare `:focus`.

### Changed — four small things

* **The picture lightbox's close** is the bare `bi-x-lg`: no circle, no border, no fill, legible on any
  picture through a soft shadow under the glyph, with a 2.75rem invisible hit area. It still appears
  on hover and focus, and is always there without a hover.
* **The hash chip is centred.** Its 1.5 line-height built a taller line box that the grid then
  stretched, and the characters rode high in the pill; now one-em line, padding, `inline-flex` and
  centring — measured to within 0.1px on every list that draws the chip.
* **The inbox bin no longer sits on the date.** Its width is kept free on the right of the row's
  top line at all times, and it is centred on that line; nothing moves when it appears.
* **"Who has this" names do not underline.** The site-wide link hover underlined (and dimmed) the
  words inside the chips; hover is the accent colour on the name and its edge now, and the keyboard
  gets a visible `:focus-visible` ring.

## [1.66.0] — 2026-09-23

Schema 72: `shouts.edited_at` / `edited_by` and `shout_mentions.late`, two permissions
(`shout.edit_own`, `shout.edit_any`), four settings in (`shout_edit_minutes`,
`shout_delete_own_minutes`, `account_picture_side`, `account_cover_side`) and one out
(`account_media_side`).

### Changed — the room is the right way up again, and scrolls like a chat

* **Chat order is the default again** (`shout_order = bottom`): the composer at the bottom, the lines
  above it oldest first and newest last, "Older" back in the head. `top` was the shipped default for
  one afternoon in 1.64.0 and the owner rejected it, so the v72 migration moves a stored `top` back to
  `bottom` once; an operator who picks `top` again from now on keeps it, and it still works, mirrored.
* **The scrolling is what was actually wrong, and it is fixed.** The list opens at its newest line —
  including a box that was hidden when the page loaded, which scrolls the first time it gets a height —
  and follows new lines while the reader is at the end (within 40 px). While they are reading further
  up it leaves them exactly where they are and shows a small "New lines" button at the end of the list,
  counting what arrived; pressing it (or scrolling back down) goes to the newest line and it goes away.
  The reader's own line always goes to the end: pressing Send is asking to see it. Pictures that load
  late no longer push a reader who was at the end away from it. Only the list scrolls — never the
  window around it — and the glide is instant for anybody who asked for reduced motion.
* **It does not "always scroll down", which is what was literally asked for:** being dragged to the
  bottom while reading history is the thing people hate most about chat windows, so a new line never
  moves a reader who has scrolled up — the button tells them instead.

### Added — a weekday beside the hour

* Every line shows the short day before its time — "pon 21:43", "Mon 21:43" — in the reader's own zone
  and language, from the dictionary (`common.dow_1…7`, never a PHP locale); the full moment with its
  offset stays in the title. It is shown on today's lines as well, as asked; they could show just the
  hour if the owner would rather.
* The live language switch carries it. A row it cannot reach (anything "Older" loaded) is re-worded from
  the day's number, with its "(edited)" and its buttons; nothing is polled while a switch is under way;
  and every list, post and edit answer now says which language it was written for, so a poll that set
  off before the switch and landed after it is thrown away and asked again, in the new language.

### Added — a line can be corrected, for a while

* **A pencil beside the pin** — or in its place for somebody who may not pin. A member corrects their
  own line for `shout_edit_minutes` (10 by default; 0 = no window at all, and only `shout.edit_any`
  edits anything); a moderator holding `shout.edit_any` edits any line at any time. Two permissions on
  purpose: `shout.edit_own` goes to `member` beside `shout.delete_own`, and `shout.edit_any` to the
  `moderator` group ONLY — deleting somebody's line is visible and honest, rewriting it leaves words
  under their name that they did not write, so that authority never rides along with `shout.moderate`.
* **The line becomes a small editor in place** — the composer's tabs and rail for a line with markup, a
  plain box for a plain one — with Save and Cancel; Enter saves, Shift+Enter starts a line, Esc cancels.
  While it is open the row's other controls are hidden exactly as they are while the delete question
  is up, and the two can never be open on one row. The button is drawn only where it will work, and is
  taken away when the window runs out while the page is open.
* **The server decides.** The window is the database's own arithmetic on `created_at`; the request
  carries the id and the words and nothing else is read, so a client claiming a different author or a
  younger line is refused like any other. Editing goes through the same door as posting: the line's
  stored format, the same validator, length limit, renderer, emote and mention passes; the flood
  interval applies between edits (a post does not count against it) and edits have an address ceiling
  of their own. Mentions follow the words: somebody newly named gets their mention and it counts as
  unread even on a line they had already read past, somebody dropped loses it, and nobody already
  told is told twice.
* **A correction leaves a mark.** "(edited)" beside the time, with the exact moment in its title, and
  "(edited by a moderator)" when somebody other than the author changed the words — a reader must be
  able to tell the difference. Every edit of somebody else's line is written to the audit log (the
  shout, who, when). A line the site said is nobody's to rewrite; a moderator takes it down instead.
* **Taking your own line back has a window now too:** `shout_delete_own_minutes`, 10 by default and 0
  for no limit, which is how it behaved until now — shipping 10 is the owner's own request.
  `shout.moderate` is held by no window.
* Corrections and deletions reach other open tabs on their next page load, as deletions always have:
  the poll carries new lines, not changed ones.

### Changed — the picture and the cover are placed separately

* `account_media_side` moved both blocks together; it is two settings now, each with its own control in
  Settings → Profiles. `account_picture_side` ships `left` — at the end of the left card, after Account
  security, where the owner wants it — and `account_cover_side` ships `right`, under Privacy, where both
  used to sit. Each block is rendered once and echoed in its own column with its ids and classes
  unchanged; the media editor finds either one by its id.
* The migration seeds both new settings from a stored `left` and then removes the old key. A stored
  `right` is the shipped default rather than a choice — it is where both blocks sit on the live site,
  which is exactly what the owner asked to change — so it seeds nothing and the new defaults apply.

## [1.65.0] — 2026-09-22

Schema 71: one table (`user_group_orders`), one new seeded group (`premium`), and the first
migration in this project that takes a permission AWAY from a group.

### Added — a sensible default permission matrix, and a group to sell

* **The `member` group finally holds what the member preset always said it did.** A fresh install
  granted `index.files_all` — the right to page past the first batch of a file list — and not
  `index.view`, `index.files`, `index.magnet` or `whitelist.add`, so the grant governed a page the
  group could not open. The owner's own server had them because the owner had added them by hand.
  The v71 migration adds whatever of the shipped matrix a group is missing, on fresh installs and on
  existing ones alike.
* **A `premium` group**, seeded, undeletable, never default, carrying only the extras: `profile.cover`
  and `shout.upload_emote`. A premium account is an ordinary member as well, so a lapse takes away
  the extras rather than the site. It carries no `panel.*` id, which is what makes it sellable —
  `v1/users/grant` refuses any group that does.
* **`shout.emote_auto` is deliberately NOT in it**: paying for pictures is not paying past
  moderation, and a premium upload waits in the approval queue like anybody else's. The group's own
  description says so.
* **The moderator can read what it approves.** `content.view` became a separate grant in 1.53.0 and
  the v25 moderator seed never received it, so an account that was only a moderator could approve
  descriptions it could not see. Its preset and its seed also disagreed — the preset had
  `panel.whitelist.delete` and no `panel.users.*`, the seed the exact reverse — so "apply the
  moderator preset" quietly rewrote a seeded moderator into a different job. They agree now.

### Changed — the profile cover is a premium extra

* **`profile.cover` moves from `member` to `premium`**, and that is the one thing the migration
  removes; everything an operator added by hand is left exactly where it is.
* **A cover already uploaded is kept.** `userCoverFor()` asks, at the moment of drawing, whether the
  account it belongs to still holds `profile.cover`; if not the band is simply not painted, the row
  and the image stay in the database, and renewing brings the cover back on the next page load with
  its framing intact. The account page says the picture is still there rather than leaving somebody
  to conclude the site lost it.
* **A member is told where a cover comes from, not warned.** Not holding `profile.cover` is now the
  ordinary state of every member, so the account page says "A profile cover comes with: Premium." —
  naming whichever groups carry it, admin and guest aside — instead of the warning a missing grant
  earns elsewhere, which reads as a misconfiguration. When no group carries it and there is no cover
  being kept, the cover block is left out altogether rather than saying "no" with nothing to do.
* **Emotes and stickers that were already approved stay**, and no permission is re-checked for them:
  they are in other people's shouts, and pulling them would tear holes in the room's history. Only a
  NEW upload needs the grant.

### Added — a purchase API a shop can trust

* **`order_id` makes `v1/users/grant` idempotent.** A payment webhook retries; an endpoint that
  extends by a month on every call hands out three months for one payment. `user_group_orders` has a
  UNIQUE `(client_id, order_id)` and INSERT IGNORE is the test-and-set, so the first call does the
  work and every retry gets the stored answer back with `replayed: true`, having changed nothing.
* **Three ways to name the buyer**, exactly one per call: `login` (as before), `user_id`, or
  `external_id` resolved through the sign-in bridge's identity table for that key.
* **`duration` extends, `until` replaces**, and an `until` that would cut short a permanent
  membership is refused with `would_shorten_permanent` unless the caller sends `"force": true`.
  Every reply carries `previous_expires_at` beside `expires_at`.
* **Refund by order.** `v1/users/revoke` with an `order_id` takes back only that order's time,
  recomputed from the order book: a customer who charges back January keeps the March purchase. The
  refunded order is marked in the same row, so a retried refund takes nothing away twice.
* **`effective: false` with a `reason`** (`email_unverified`, `banned`) so the shop can tell its
  customer why nothing happened yet. The grant is recorded regardless and starts working the moment
  the obstacle goes.
* **A narrow `shop` scope.** `users` also opens account creation and the whole sign-in bridge,
  including `v1/auth/merge` — which attaches an outside identity to an EXISTING account, and is far
  too much for a payment webhook. `shop` opens lookup, grant and revoke, and nothing else.
* **Grant, revoke and provision write a real audit line** (target account, group, order id, the new
  expiry) instead of the fallback `api.users/grant`. `user.create` is in the Users filter group now
  too — it had been mapped since the log was written and named in no group, so every account the
  panel made was filed under "other".
* The integration guide documents the shop flow end to end, and no longer offers `auth` as though it
  were a scope — `apiClientScopes()` has never had one; the bridge lives in `users`. An old
  `?scope=auth` link still opens the bridge chapter, as the `users` page it always really was, so a
  link in somebody's forum post or notes does not fall through to the whitelist page.

## [1.64.0] — 2026-09-22

Schema 70: two columns on `message_threads` (`u_low_cleared_id`, `u_high_cleared_id`) and two
settings (`shout_order`, `account_media_side`).

### Fixed — three faults nobody had reported yet

* **A shout row could end up wearing another row's words.** The live language switch
  (`assets/js/lang-swap.js`) walks the page against a fresh render of it and pairs children up by
  `id` first and by POSITION second. Shout rows carried a `data-id` and no `id`, so the moment the
  live list stopped being the list the server would draw right now — a line polled in, or "Older"
  pressed — the rows were paired off by their places in two lists of different lengths and a row was
  given a different row's text while keeping its own `data-id`. The delete cross beside it deletes by
  `data-id`, so the line that went was not the line on the screen. Both renderers now write
  `id="shout-<id>"`; the polled lists a script builds (the inbox, an open conversation, the friends
  list, the directory) carry stable ids too.
* **An overlay closed when a press that began inside it was released on the backdrop.** A `click`
  goes to the nearest common ancestor of the press and the release, so dragging a long file list's
  own scrollbar and letting go past the edge of the window closed the window — after thirty pages of
  loading. Every overlay now closes on the backdrop only when the press ALSO started there: the file
  list, the Info panel, the "who has this" and list overlays, the two people dialogs, the picture
  lightbox, the terms box, the leaving-the-site box and the panel's confirm box.
* **The search file list folded the reader's folders back up, and stopped loading by itself.** The
  tree was rebuilt with `replaceChildren` on every page AND at the start of every load — the second
  one only to write "Loading…" on a button — and a `<details>` remembered nothing, so a folder could
  be open for about a second. Each folder now carries a `data-path` and the open/closed answers are
  carried across the rebuilds; the chrome and the tree are drawn separately, and a load start no
  longer touches the tree. The loader watches the window's own scrolling body instead of the page
  (and re-checks after every page: an observer reports changes, so a page that landed with the end of
  the list still on screen ended the chain). The Info panel's own file tree gets the same treatment.
  A reply for a list that has been closed can no longer draw itself into the one opened since, and
  opening the list adds a history entry, so Back closes it instead of re-running the search behind it.

### Added

* **Delete a conversation.** Each side of a thread now has a watermark (`u_*_cleared_id`): deleting
  moves your own to the last message there is, and every read path — the open conversation, the poll,
  the inbox and its previews, the deep search and both unread counts — shows only what is above it.
  Nothing is removed: the other person's copy is untouched, a new message brings the thread back
  showing only what arrived after you deleted it, and a reported message stays readable to the panel.
  The cross sits on the inbox row and asks the same in-place question the shoutbox asks — which is now
  one helper in `assets/js/app.js` rather than a copy per file.
* **Newest lines at the top** (`shout_order`, Settings → Shoutbox, the new default). The room used to
  be drawn oldest-first with nothing ever scrolling it, so opening it put the reader in front of its
  oldest lines. At the top, the composer sits above the list and "Older" below it; the chat order is
  still there, and now scrolls to the end when it opens — including when a hidden tab or a collapsed
  account tab first becomes visible, where a box with no height could not be scrolled at all.
* **Picture and cover on either side of the account page** (`account_media_side`, Settings →
  Profiles). The same markup, rendered once, at one of two positions.
* A folder that holds a search match is marked with a dot in the file tree, so a reader can steer
  towards it with the folder shut.

### Changed

* The picture lightbox keeps its controls INSIDE the picture: the close in the top right corner, the
  link to the original centred along the bottom, both on a dark pill, out of the way until the
  picture is hovered or something in it has the focus — and always on where there is no hover to
  have. The bar under the picture is gone, and the picture has its height back.
* The question in a shout row hides the row's other controls while it is open (the pin button was
  drawn exactly where "No" lands, so declining to delete a line pinned it), and takes itself away
  after five seconds, on a press outside it, on Esc, or when the language starts changing.
* The media editor's × has its own behaviour: nothing unsaved and it closes at once; something
  unsaved and it says what a second press will do, beside itself, for three seconds. Cancel, Esc and
  the backdrop still ask the footer's question.
* The time zone list is written without the region each zone is already filed under and without the
  underscores ("Argentina / Buenos Aires (UTC-03:00)"), which is six characters off the longest line
  in a list no stylesheet can narrow; the Time zone block moved to the card that holds Interface
  language, outside that block's own condition.
* Names in the friends list, the directory, the "who has this" overlay and a conversation's head no
  longer turn purple once they have been opened.
* The copyable short hash is a pill the size of its own words in the site's own font, instead of a
  bordered box stretched across its whole column with the text pushed to the right of it.

## [1.63.1] — 2026-09-22

No schema change.

### Fixed — a new picture or cover could not be chosen on the live site

* **The editor showed "The image could not be loaded" for every picked file**, on the account page
  and in Settings → Profiles alike, and its preview circles were broken images. The preview was an
  object URL (`URL.createObjectURL`, a `blob:` address), and the policy production *enforces* — the
  fallback header in `.htaccess`, which Apache adds while the application's own policy only reports
  — allows images from `'self' data: https:` and not from `blob:`. Locally it worked, because
  `php -S` reads no `.htaccess` and `includes/csp.php` runs in report-only mode, which blocks
  nothing. A picked file is now read with `FileReader.readAsDataURL()` into a `data:` URL, which the
  policy already allows; the policy itself is not widened. The file is handed to the editor and read
  there, once for both sides, while the frame shows its spinner — and a file that cannot be read ends
  in the same message as one that cannot be decoded. Framing, zoom, the three live sizes and the one
  multipart Save are unchanged.
* The editor's buttons are each side's standard ones at one size: on the account page Save was the
  full-size button between two small ones, and the "Discard the changes?" and "Remove?" questions
  used the shoutbox's own tiny buttons. The desktop/phone switch, the zoom readout and Re-centre are
  standard buttons too, and Adjust position and Remove on the account page carry the same icons as in
  the panel.
* `scratchpad/shots/media_check.js` now runs the editor under an ENFORCED policy with production's
  `img-src`, and fails on any policy message: run against the old preview it failed exactly as the live
  site did, which is the point — the check that let this through could not have caught it.

### Fixed — two tests that could not fail

Both had an escape turned into a control byte by a patch that went through a shell here-document,
which eats backslashes.

* `tests/lang_test.php`: the `<html lang>` check matched `\blang=` with a BACKSPACE byte where `\b` was
  meant, found no template, and skipped every one of them while reporting a pass. It visits all of them
  now, and they all pass.
* `tests/announce_multiport_test.py`: the CSRF-masking substitution put the byte 0x01 where the
  back-reference `\1` was meant, so it replaced the whole attribute instead of masking only its value.

A scan of every tracked source for control bytes finds none left. The same fault in a browser check
that is not under version control — its "no real API key is printed" assertion could never fail — is
repaired too.

### Fixed — the panel

* **Whitelist: newer forum entries read "FORUM …" in the Source column.** The cell drew a link to the
  forum post and "via <partner>" after the badge — and a review badge when there was one — in a
  column 84–96 px wide whose cells are one line that ellipsises, so on every row the forum bridge sent
  through its key only the badge and three dots could be seen. The cell holds the badge alone now,
  and the partner who sent the row is a small line under its name, where the column has room — a
  moderator going through the waiting queue reads it at a glance instead of hovering every row. The
  review state and the discussion and post numbers are the badge's tooltip, and the details panel
  still shows all of it, the post link included.
* **Users → Groups: "Permission matrix — who holds what" had a missing-glyph box and "B8" in front of
  it** (and so did "Who may read and who may write" in Settings → Shoutbox). The marker was a CSS
  escape for ▸ that had lost its backslash, which left the control character U+0015 — no font draws
  one — followed by the letters. It is a Bootstrap Icons chevron in the markup now, turned when the
  section opens. The panel's other collapsibles carry the real character and were not affected.
* **Users → Groups: the explanation above the groups table touched the edges of its box** — it sat in
  the frame of a search field, which has no padding of its own. It is drawn as the page's other note
  is (Write to members).

### Tests

* `scratchpad/shots/media_check.js` switches `csp_mode` to **enforce** for its run and puts it back,
  so the page it drives refuses what production refuses; it checks the header it was served, that a
  picked file's preview is a `data:` URL that really loaded (on the account page and in Settings →
  Profiles), and that no page it visited reported a policy violation. Run against 1.63.0's
  `blob:` preview it fails exactly where the live site failed. The account and panel halves no longer
  stop each other, and the panel half stores its own cover instead of relying on the first half.
* `tests/usermedia_test.php`: neither editor script may create an object URL or name a `blob:`
  address, the editor must read the file with `readAsDataURL` and both callers must hand it the file,
  the only object URL left in `assets/js` is the language export's download link (a download is not
  an image load), and it reads both policies — the application's and the `.htaccess` fallback — to
  confirm neither allows `blob:` images, which is the reason for the rest.

## [1.63.0] — 2026-09-22

Schema **69** — a picture and a profile cover for every account: the core. The images live in a
table of their own, `user_media`, and never on `users`, which is read with `SELECT *` on almost every
request; what `users` gains is eight small columns (the picture's crop id, the cover's hash and each
one's focal point and zoom), which is enough to build their addresses without another query.
`profile.avatar` and `profile.cover` go to the member group; both features ship on. The standard to
meet was the owner's own Flarum extension, flarum-cover-studio — its experience, without the things
it got wrong. And then the picture beside every name across the site — the shoutbox, messages,
the people lists, the navigation, the panel.

### Added — one door for every image

`includes/usermedia.php` is the only way an image gets in, and it asks its questions in the order
that keeps the server alive. The size first (the setting, or what PHP will really take, whichever is
lower — the forms quote that number rather than promising 8 MB on a PHP that stops at 2), then the
magic bytes (JPEG, PNG, WebP or GIF; an SVG, a BMP, a phone's HEIC are refused by name), then the
header: its type has to agree with the magic bytes and width × height has to fit
`avatar_max_mp` — a 50 000 × 50 000 PNG is sixty bytes on the wire and ten gigabytes decoded, and it is
refused here in a third of a millisecond. Only then is anything decoded. JPEGs are turned upright from
their EXIF orientation, everything is bounded (1 600 px for a picture's source, 2 400 for a cover
plus a 1 000 px copy for small surfaces), and everything is saved again as WebP with its alpha — which
drops every byte of EXIF and GPS and leaves nothing of a file that was also something else. An
animated GIF keeps its first frame, and the account page says so. The extension checked the size
after decoding and stored WebP uploads byte for byte, location and all.

A picture keeps its uncropped source and is cut into 64, 128 and 256 px squares on the server with
the extension's own formula — CSS object-position semantics, so the saved picture matches what the
editor showed to the pixel, which the tests prove by reading the pixels. Nothing is enlarged: a
window too small for the larger sizes simply does not have them, and the stream answers with the next
size down. Below 1× the picture floats on a blurred, darkened copy of itself. The first crop is cut
from the stored source, the same bytes every later reframe reads, so the first and the tenth agree.

Replacing or removing deletes the old rows in the same transaction. Nothing a member replaced or a
moderator took down is left answering at an old address — the extension never deleted a file.

### Added — the streams

`api.php?endpoint=user_media&h=…&s=…` is content-addressed: sixteen characters of a hash and a size,
no account id and no file name in it, so a new picture, a new framing or a new cover is a new address
and every address may be cached for a year (`immutable`, an ETag, nosniff, a policy that forbids
everything). The uncropped source is its owner's and the panel's alone — it may show exactly what
somebody cropped away — and is never stored by a browser. `user_avatar_default&l=…&c=…` is the
picture somebody without one gets: their initial on one of twelve colours taken from a hash of their
name, so a person keeps theirs; thirty-six letters by twelve colours is the whole space.

### Added — the editor on the account page

The Profile card has a Picture and a Cover. A picked or dropped file opens as a local preview in the
position editor, and nothing is sent until Save, which carries the file and its framing in one
multipart request — the extension uploaded the moment a file was picked, centred and public. The
editor is the extension's drag surface rebuilt: the image under the pointer stays under it, the
thirds brighten while dragging, the arrows move the focus by 2 % (Shift 10 %), plus and minus zoom,
the zoom readout resets to 1×, and there is a Re-centre. What is new: two fingers pinch-zoom, the
wheel zooms in proportion to how far it turned, the slider runs the whole 0.5–4, the hint speaks of
pinching on a touch screen, an image that will not load says so, Save waits for a change, and closing
or leaving the page with one asks first. A cover is framed in the header's real shape — 976 × 220 on
a wide screen, and one click shows the 342 × 160 band a phone keeps. A picture shows itself live at
the three sizes it is really drawn at. Remove asks in place and says the image is deleted for good.
Uploads are limited to six a minute per account and per address, reframes to twenty.

### Added — the profile page

A cover fills a band behind the head, painted the way the extension paints it — a clipped layer of
its own (so no menu in the head is ever cut off), the blurred fill below 1×, the image at its focal
point and zoom, and the readability overlay Settings chooses over the full height — with no z-index on
anything that holds the content. The picture sits left of the name at 64 px, over the band's lower
edge. With no cover of their own a profile gets the site's default cover, and without that the plain
head it always had. The per-profile numbers arrive in a `<style>` that carries the request's nonce —
on the first frame, before any script, and never as a `style=""` attribute — and the editor updates
them through the CSSOM.

### Added — the panel

Settings has a Profiles chip: both switches, the upload ceiling, the megapixel ceiling, the two band
heights, the overlay, and what somebody without a picture gets — their initial, or the site's default
picture, set, framed and removed in the same editor, beside the default cover. The user edit modal
offers Remove picture and Remove cover when there is one; it deletes, the member gets a notification,
and it is in the audit log.

### Added — the picture beside every name

Wherever a person's name is drawn, their picture is drawn beside it now, on its left: the shoutbox
(its rows and the pinned line, 20 px), the inbox and the head of a conversation (32), friends, requests
and blocks and the member directory (32), the reader's own name in the navigation (20) and at the top
of the account page (32), the "who has this" overlay — the people and the owners of the lists (20) —
the "by …" under an emote, the author under a description in the Info panel (20), and in the panel the
user list (24), the edit, grant and notify windows (48), the reported-message cards, the content
review cards and the emote manager (20). A line the site said itself carries the site's own mark
instead of a person's picture, even when it is signed with the name of the person it is about.

It is one element everywhere — `userAvatarHtml()` on the server and `window.userAvatarImg()` in the
browser, which moved out of `assets/js/app.js` into `assets/js/avatar.js` so the panel can load it
too: round, with a hairline drawn one pixel inside the circle so a dark photo has an edge on a dark
page, an explicit width and height so nothing moves while it loads, `alt=""` because the name beside
it is the text, lazy, and a `srcset` naming the square each screen density should take where they
differ (the 20 px pictures are the 64 square on every screen). A picture that fails to load becomes
the person's initial.

**No endpoint that withheld an account id sends one now.** The inbox, the people lists, the
directory, "who has this" and the Info panel always sent a name and deliberately not the id behind
it, even where their SQL had it; what they send beside the name now is the picture's address, built on
the server from one more column of the join each of them already made — `avatar_sha` — so no list
costs a query per row, and the address names a picture rather than a person. The browser draws only
addresses of the three shapes the server writes. The shoutbox's first page and every row the poll
appends are drawn from the same field by the two renderers and serialise to the same markup; the
picture sits inside the fixed-width name column, which grows by exactly its width so a name keeps the
8 rem it had and a long one still ends in an ellipsis, top-aligned in the line so no row is a pixel
taller and the name, the time and the words stay on one baseline. A picture and its name are one unit
wherever a line can wrap, so a narrow line never leaves a picture behind.

Where the name is shown today the picture is shown too, and nowhere else: a hidden profile or a
blocked person is exactly as visible as before. With pictures switched off (`avatars_enabled`) nothing
is drawn anywhere — not a column of initials nobody asked for — and every surface is the one it was
before this release, pixel for pixel. The account page's picture editor now also updates the
reader's own picture in the navigation and the heading the moment a new one is saved.

### Not in this release — notifications

A notification's name is part of its sentence, written once into the title when it was sent, and
`user_notifications` has no column that says who it was about. A picture there needs that column
first, and a schema change of its own; it is not guessed from the text.

## [1.62.0] — 2026-09-22

Schema **68** — one setting and one column. `site_timezone` is the zone this site shows times in,
and `users.timezone` is a reader's own, where NULL means "the site's" rather than a zone of its own.
The shoutbox is the first thing that reads either. This is the owner's third pass over the live
room, plus three things outside it that the same pass turned up.

### Added — a clock for the site, and one for each reader

There was no display time zone at all: only `tracker_schedule_tz` and `backup_schedule_tz`, each
for its own schedule. The shoutbox printed an hour by slicing it out of the database's own string —
and `getDb()` sets the database session to PHP's offset, so every reader on the site saw PHP's hour,
whoever and wherever they were. In a room full of people in Warsaw on a server running UTC, every
line was two hours in the past.

Settings → Site now has a **Time zone** (every zone PHP knows, grouped by region, with today's
offset beside each). Until somebody saves a choice it follows the zone the tracker schedule already
runs in, and failing that the one PHP runs in — decided when it is read, not frozen by a migration,
so an empty or broken row falls back to the next-best fact instead of to a page that cannot tell
the time. The account page's Profile card has its own **Time zone**, "Site default (Europe/Warsaw,
UTC+02:00)" first, then every zone; it saves the moment it is chosen, through the account's own
update endpoint and with the same test Settings uses — no password, because which clock somebody
reads the room in says nothing about who they are.

The times are formatted **on the server**, for the first page and for every polled row alike, so
the two can never disagree: each row carries the hour for the list and the full local timestamp
with its offset for the title over it. They are made from an instant the DATABASE converts
(`UNIX_TIMESTAMP(created_at)`), never from the column's string — PHP reading that string would
assume its own zone, which is right on a machine where the two agree and hours off on the first one
where they do not. A guest reads the site's zone. One caveat belongs to the storage rather than to
this code: the session zone is an offset taken when a row is written, so on a server whose PHP runs
in a zone with summer time a row written before the change reads an hour out after it. A server on
UTC, like this one, has no such hour.

**Only the shoutbox has been converted.** The other places that print a time still print the
database's string, or read it in the visitor's browser as if it were local: "Member since" and "Last
sign-in" on the account page and "member since" on a profile, the notification list and a group's
expiry on the account page, the times beside messages, the signed-in devices list (which prints
UTC), "last seen" in the search results and "first seen" / "last seen" in the Info panel, the
hash-check page, and every table in the panel. Each of those can take `userDisplayTimezone()` and
`userDisplayTime()` (includes/db_clock.php) the way the shoutbox now does; none of them was touched
here.

### Added — a picture in a shout opens when it is clicked

Pictures in a shout stay small in the line, and they are links now: a plain click opens the picture
fitted to the window on a dark backdrop, with a close button and a link to the original, and Esc, the
button and a click on the backdrop all close it. The focus goes into it and comes back to the picture
afterwards, and Tab stays inside it while it is open. Ctrl or Cmd+click, a middle click and the
context menu open the original in a new tab, natively — the picture is a real
`<a target="_blank" rel="noopener noreferrer">` and only the unmodified primary click is
intercepted, so nothing a browser user expects of a link had to be written again. The address is
the one the renderer already validated, reused byte for byte; the lightbox takes it from the link
and parses nothing. Emotes are words and stay words; a sticker is already its own full size; and a
picture the author already made into a link keeps being the author's link.

### Fixed — the emoji picker opens beside its button

The button moved into the text field's top right in 1.61.0 and the picker kept opening from the
field's left edge, a whole field away from what was pressed. It is placed against the button each
time it opens: its right edge on the button's, above it when it fits there and below when it does
not, and clamped so no part of it leaves the window — on a phone it takes the width of the screen.

### Fixed — four small things in the room

* No colon after the name. It was a `::after` in the stylesheet rather than a character in either
  renderer, so it came off in one line.
* The format select no longer keeps its ring after a mouse choice. Chrome counts a `<select>` as a
  keyboard control and matches `:focus-visible` after a click too, and picking the option already
  chosen fires no `change` at all — so the select is marked on pointerdown, the mark is cleared by
  any key, and there is no ring while it is marked. A mouse user never sees it; somebody arriving
  with Tab still does. A change made with the pointer gives the focus back to the page; one made with
  the arrow keys does not, because that would leave a keyboard user nowhere.
* `.pm-editor .rt-format` is `padding: 0.2rem 0.35rem` — tried at 0.15, preferred at 0.2.
* The visited colour leaked from the name in the pinned strip and from the Emotes link, and from the
  "Open the shoutbox" link beside it. The rule now covers every link the widget draws, as one scope
  rather than a list of parts, plus the lightbox's link, which lives outside the box.

### Fixed — the tooltip sits over what was pressed

`pubTip()` drew "Nothing new" off to the left of the refresh button on every desktop: one rule hung it
from the button's right edge so it could not leave the box. Every tooltip on the site goes through
that one function, so it now places itself the way Popper.js does — centred over what was pressed,
pushed sideways only by as much as centring would put outside the window (which is right on a
phone), and below it only when there is no room above. It lives on the page rather than inside the
element it is about, so nothing can clip it and a `<select>` can have one, and it is announced to a
screen reader.

### Fixed — a file list opened from a search hit shows the file that matched

With "Also search file names" on, a hit is shown as a chip, and opening the file list of a torrent
with thousands of files showed a capped page that need not contain the matching file at all. The
first page now carries the files that match the same term, with the same clause the search itself
used (`indexFilePathClause()` is shared by both, so they cannot disagree about what matched): up to
fifty, the ones BEYOND the page first, from the file index's own fulltext rather than a walk of the
torrent's list. The tree shows every match with its folder chain from the root opened, the file
marked, and an explicit `…` row in each folder on the way where the other files were cut; the folder
counts say "at least", and a line under the tree says how many matches came from outside the loaded
part. A match already on the page is simply found and opened up to, and one that a later page brings
in is drawn once. Everything else stays capped exactly as before.

### Changed — the copyable hash on favourites and lists

The short hash that copies itself wears the dress of the site's other click-to-copy text — the code
chip on the Emotes page — instead of a dotted underline, which read as a link to somewhere. "Copied!"
is the site's tooltip, centred over the chip; it used to be the chip's own label swapped for the
word, which in a right-aligned column left the shorter word against the right edge rather than over
what was clicked. It can be reached and pressed from the keyboard as well.

### Changed — the volume moves in ones

The volume slider on the account's Sounds tab steps by one percent, so the arrow keys move it by one
and the number beside it is the exact volume that will play. It is the only volume slider on the
site; the panel sets default sounds, not a volume.

## [1.61.0] — 2026-09-15

Schema **67** — no tables, one setting and one permission. `shout_page_action` is the action name
the room answers on, so an operator who calls the thing a chat can have `?action=chat`; the name is
refused when it already belongs to another page, and the list it is checked against is the router's
own map rather than a copy that would rot. `shout.emote_auto` lets a trusted member's emote go
straight into the room instead of into the approval queue. Like `shout.upload_emote` before it, the
migration grants it to nobody.

### Fixed — the shoutbox made no sound, and the reason was structural

`assets/js/shoutbox.js` has dispatched a `shout:new` event since the room landed, and nothing has
ever listened to it. `assets/js/sounds.js` watches the pulse counts instead, and for a reader with
the box actually on screen those counts can never rise: the widget stamps `users.shout_seen_id` the
moment it draws. So the one reader most likely to want a chime — the person sitting and watching the
room — was the one reader who could never get one. The player listens for the event now and picks
the strongest kind in the batch (a mention of me, else a friend's line, else anybody's), once per
batch rather than once per row, never for a line I just sent myself. The pulse path is untouched for
readers who are not looking at the room, and a batch that arrives through both paths sounds once.

Telling a friend's line from a stranger's is a fact only the server has, so `shoutShape()` now says
so per row — one query for the batch, beside the mentions query that was already there, and only
while friends are switched on at all.

### Fixed — "Open the shoutbox" pointed at a page that need not exist

`?action=shoutbox` was hardcoded on the Emotes page. With `shout_placement` on the home block there
is nothing at that address, so the button at the bottom of the page a reader was sent to in order to
find emote codes led to "there is no shoutbox here". `shoutNavUrl()` has answered this correctly
since 1.60.0 and every link is built from it now — which is also what makes the renaming above one
string rather than a hunt.

### Fixed — six things the owner listed after using the room

The "Choose a picture" label in every drop zone was underlined by a literal `<u>`; the underline is
gone and the colour cue stays, in one rule for all three. The Sticker checkbox in Settings → Shoutbox
was Bootstrap's light default on a dark panel and now matches the controls beside it. The Purge
button was `btn-sm` next to a full-height day box and the two now share a height and a baseline. The
Add-one box on the Emotes page was pinned to `44rem` in a wide page and now uses the width it has,
like the card grid above it. `.pm-editor .rt-format` is less cramped. And a mention's blue bar sat
against the name: every row carries the left padding now, so an ordinary line and a mention start at
the same place and nothing shifts when a line turns out to be about you.

### Added — `@` suggests names as you type

Two characters after an `@` and the composer offers matching account names. The guards are the
feature: nothing before two characters, a 250 ms debounce, one request in the air at a time, at most
eight names, an answer cached for the life of the page so backspacing re-asks nothing, and a flight
abandoned the moment the caret leaves the token. The endpoint answers only signed-in readers holding
`shout.post`, matches a prefix so the index is usable, limits to eight, returns names and nothing
else, and sits behind its own rate limit. Hidden profiles and banned accounts do not appear.

### Changed — the composer, rearranged

The refresh control is a plain icon that darkens on hover and spins while it waits, rather than a
circled button. "Nothing new" stopped being a line of text pasted under the box and became a tooltip
on the refresh button itself, in the shape the rest of the site already uses. The character count
moved up onto the tabs row. The emoji button sits at the bottom right of the text area, raised and
semi-transparent, clear of typed text at every width. Send moved to the left of the formatting fold,
and the sentence about Enter and Shift+Enter now rides in brackets after "Formatting help" instead
of taking a line of its own.

### Fixed — an icon font the public pages never loaded

Bootstrap Icons was pulled in for `?action=transparency` and `?action=stats` and nowhere else, so the
`bi bi-file-earmark-image` on the Emotes drop zone has been drawing an empty box since 1.59.0. The
stylesheet is now requested by the pages that actually use an icon, the front page included whenever
the shoutbox is really drawn on it for that reader.

## [1.60.0] — 2026-09-14

Schema **66** — the shoutbox grows a pinned line (`shouts.pinned_at` / `pinned_by`, at most one of
them at a time) and lines the site says itself (`shouts.is_system`), which is why `shouts.user_id`
is nullable from here on: an announcement has no author, and a row pointing at account 0 would be a
lie rather than an absence. Three settings arrive with it, every one of them off or conservative.

A correction to 1.59.1 while this is being written. That entry said an emote's response leaves with
exactly one policy; measured on the live site, it does not. PHP sends one and takes its own
report-only header off, which is real and visible — an ordinary page carries that header and an
emote no longer does. Apache then appends the site-wide fallback from `.htaccess`, after PHP has
finished and where no PHP code can reach it. A client handed both enforces the intersection, and
nothing intersected with `default-src 'none'` is permission to load anything, so the guarantee was
never in doubt and only the sentence was wrong. The fallback stays exactly where it is: it is the
last line of defence for every page on the site, and tidiness on one endpoint is not worth spending
it.

### Fixed — a counter that filled and then blanked, and it is older than the room

Building the third badge found the other two lying. The pulse keeps to one request per reader by
leaving its answer in the browser's storage, and any tab whose turn comes inside that window uses
the stored answer instead of asking again. That storage survives a reload — so the first tick after
a page load adopted the numbers the SAME tab had left behind *before* the reload, and the badge
showed what the page had just fetched and then went blank, staying blank until the lease aged out.
Notifications and messages have done this since the counter left the account page in 1.55.0; a third
number beside them only made it easy to see. A stored answer is now taken only when it is newer than
what this tab has already heard from the server, and an answer straight from the server marks the
moment it arrived.

A reader the operator granted `shout.view` without an account is served properly too. The Shoutbox
badge now carries the cadence and the account id the way the account badge does, and the page asks
for a baseline when either badge is present rather than only the account one — that reader used to
get a counter that could never fill, and then, once it could, one that never moved again.

### Added — the shoutbox in the navigation, with a number of its own

`shout_nav` puts a **Shoutbox** link in the bar, carrying its own counter, for anybody who may read
the room. It is deliberately NOT folded into the badge beside the account name: that one counts
notifications and waiting messages, both of which are read on the account page, and a reader who
sees 5 on it has two tabs to go and look in — a third meaning would leave it answering nothing. The
numbers were already on the wire (`api/user_pulse.php` has sent `unread_shout` since 1.58.0), so
nothing new is asked of the server. The badge clears when the reader opens the room, because opening
it is what moves `users.shout_seen_id`, and the link's title says exactly that.

### Added — one pinned line

A moderator pins a line from the row itself (`shout.moderate` — no new permission: it already means
room-wide authority), and it is drawn as a strip above the list and outside its scroll, so it cannot
slide away just as somebody needs it. **At most one is ever pinned**: pinning a second unpins the
first, in one transaction, because two announcements is nobody reading either. The pinned row rides
with the first fill and with the "older" button and never with the poll — that path appends, and a
pinned row handed to it would be appended to the bottom of the room every few seconds for ever.

### Added — a separate refresh interval for guests

`shout_live_seconds_guest` (30 s) is the cadence for a reader with no account, and 0 means a guest
does not poll at all and reads whatever the page was drawn with. A guest reads and never writes, and
on a public tracker there are far more of them than there are members, so the number that is right
for somebody waiting for an answer to what they just said buys nothing at all here.

### Added — optional lines from the site

`shout_system_lines` (off) lets the tracker say so in the room when a torrent is registered: **one
line per batch**, never one per hash, because somebody registering forty torrents is one thing that
happened and forty lines is the room emptied of everything people said. It respects
`wl_submitter_public` — a submitter who is not public is named nowhere else on the site and is not
named here either, and the line then says a torrent arrived without naming anybody. The name rides
as the row's author rather than as a word inside the sentence, which also settles a grammar problem:
a name dropped into a Polish sentence has to agree with the verb after it. These lines belong to
nobody — members cannot delete them, a moderator can, they are nobody's unread and they make no
sound. A room that pinged every reader each time a torrent was registered is the one thing an
announcement must not turn into.

### Added — who may read and who may write, in one view

Settings → Shoutbox grows a folded, read-only matrix of the five `shout.*` permissions across your
groups: the same one Users → Groups draws, scoped, and fed by the same endpoint. Granting is still
done where every other grant is made, so one place decides and one place shows.

### Changed — Settings has two more chips

**Shoutbox** and **Sounds** are groups of their own rather than sections buried inside "Descriptions
& review" and "User accounts", which is where they had landed rather than where anybody would look
for them. The section ids are untouched, so every bookmark and deep link into them still opens.

## [1.59.1] — 2026-09-14

Schema **65** — the `shout_emote_approval` setting and an `approved_at` stamp on `shout_emotes`,
with every emote that already exists marked approved as the column arrives, so no site wakes up
from the upgrade with a queue of things it had already accepted.

### Added — a member's emote waits for you

A picture uploaded by an ACCOUNT (`shout.upload_emote`, which nobody holds until you grant it) is
stored switched off and shows in a waiting queue at the top of the emote table in
Settings → Shoutbox, with **Approve** and **Delete**. Nobody else sees it anywhere until it is
approved — not in the picker, not on the Emotes page, not in a rendered shout — while its uploader
sees their own marked as waiting and can take it back. The panel's own uploads never wait. Off, the
behaviour is exactly what 1.59.0 did. Approving stamps the emote and is written to the audit log,
and because the stamp is what the queue reads, an emote a moderator switches off afterwards stays
switched off instead of coming back to ask a second time.

### Added — emote names are checked, and can be changed

`shoutEmoteNameProblem()` is to emotes what `soundNameProblem()` is to sounds: a name has to survive
trimming, be at least two characters and not already belong to another emote (case-insensitively).
It is enforced on upload — including on the prettified-code fallback an empty box falls back to, so
a second "Wave" cannot arrive by nobody typing anything — and on the new **Rename**, which changes
the display name and never the code, because the code is what people type into a sentence.

### Changed — Settings → Shoutbox, after the first day of use

The emote manager is rebuilt in the shape Settings → Sounds got in 1.59.0: the six switches in one
grid with every hint under its own field, a real table (preview, code and name, type · size ·
dimensions, who uploaded it, added, actions) with column headings and a count above it, a state chip
per row saying `Enabled`/`Off` and `Emote`/`Sticker`, and buttons that say what they do —
`Disable`, `Make a sticker`, `Rename`, `Delete`. The drop zone spans the block and Code, Name, the
sticker box and Add sit on one line beneath it.

### Changed — the Emotes page and the shoutbox itself

`?action=emotes` is a card grid of equal tiles: the picture centred on an inset panel so a white PNG
and a dark SVG both read, the `:code:` under it as a click-to-copy chip, the name, and the uploader
line muted. The intro and the Code placeholder said `:fire:`, which is a built-in shortcode and not
an emote anybody can upload — they say `:flame:` now, which is one the tracker ships.

In the shoutbox: the formatting toolbar the description and message editors already have, beside the
format select and swapping with it; a refresh button top right that asks for what is newer even while
polling is paused and says so when there is nothing; a dashed rule between rows with the name and
time columns aligned so a long name no longer pushes the text out of line; links inside a shout keep
the link colour after a click, because it is a conversation and not a document; and the format select
no longer keeps a focus ring after a mouse click.

### Fixed

`api/shout_emote.php` set its own strict policy on top of the site's, so a client received two
`Content-Security-Policy` headers and enforced the intersection. Safe, untidy — it removes the page
policy (and the report-only one) before setting its own.

## [1.59.0] — 2026-09-14

Schema **64** — `shout_emotes` (custom emotes and stickers, as rows), the `shout.upload_emote`
permission (nobody's by default), five `shout_emote*` settings, and a few shipped example emotes
seeded once from `assets/emotes/`.

### Added — emoji, emotes and stickers in the shoutbox

A 😀 in the composer opens a picker: some hundred and fifty common Unicode emoji (drawn by the
device's own emoji font — Android's on Android, Windows' on Windows), the site's custom emotes as
images, and stickers on a tab of their own. An emoji or emote lands at the caret (`:code:` typed by
hand works too); a sticker is sent straight away and shows large on its own line. Custom emotes are
SVG, PNG, GIF or WebP up to 64 KB and 128 px (the owner may change the caps), kept in the database
like the sounds are, sniffed by their bytes — an SVG carrying a script, an event attribute, a
`javascript:` or a foreign object is refused, and every emote is served with `nosniff` and a
policy of its own so nothing in it could run anyway. The owner manages them in Settings → Shoutbox
(enable, disable, mark as sticker, delete, upload); members whose group carries
`shout.upload_emote` add their own (twenty each) on the new **Emotes** page (`?action=emotes`),
which lists every enabled emote with its code, preview and who uploaded it.

### Changed — Settings → Sounds, after the first day of use

The uploads are a table (name, file, added, which site defaults use it, play / rename / delete),
with a count of forty; the drop zone spans the block and the name and Add sit on one line beneath
it; a name is required to be unique across the whole library — shipped clips included — so two
"Email notification" entries can no longer meet in a select; uploads can be renamed; the selects
group shipped clips and your own; an empty name falls back to a prettified file name.

### Tests

`tests/shout_emotes_test.php` / `.py` (sniffing, the SVG refusals, caps, per-member cap, dedup,
rendering outside tags, the sticker rule, the endpoints and the page), the extended
`scratchpad/shots/shout_check.js`, and the extended `tests/sounds_test.php` / `.py` (name rules,
rename).

## [1.58.0] — 2026-09-14

Schema **63** — `shouts`, `shout_mentions`, `users.shout_seen_id`, the `shout.*` permissions (view,
post, delete_own to the member group; moderate to the moderator group), eleven `shout_*` settings and
three more sound defaults. **Off as shipped** (`shout_enabled = 0`).

### Added — a shoutbox

A line of talk on the front page (a block of the home layout, `{{block:shoutbox}}`) and/or its own
page (`?action=shoutbox`; Settings → Shoutbox picks home, page or both). Members with `shout.view`
read, `shout.post` write, `shout.delete_own` take their own line back; a moderator (`shout.moderate`)
removes anyone's line — the row keeps who removed it and when for the retention period, and the audit
log a line — and the owner purges from Settings (everything, or older than N days, behind the owner
password). Guests read nothing unless the operator grants `shout.view` to their group. A silenced
account (`pm_muted_until`) reads and cannot write, and is told until when.

The widget is drawn by the server with the newest rows and a `data-newest` mark, then asks only for
what is newer (`shout_list&after=…`, every `shout_live_seconds`, never from a hidden tab or an
off-screen box, one flight at a time), appends without redrawing — a half-typed line survives — and
scrolls only when the reader was already at the bottom; "older" prepends without moving what is on
screen. Enter sends, Shift+Enter breaks a line; the format is BBCode by default with Markdown a
switch away (or plain text when the operator says so), through the same renderer, validator and
preview every description and message uses. Flood (`shout_flood_seconds`) and length
(`shout_max_chars`) are refused with a sentence, and the reply to a send carries the new row so it
appears at once. `@name` in a shout links the profile and counts as a mention for that person.

How many shouts are new to a reader is one column (`users.shout_seen_id`) and travels with the
pulse — `unread_shout`, the friends' share and the mentions — so the sounds have three more events
(a shout from a friend, a shout from anyone else, an @-mention), offered only while the shoutbox is
on. Retention by count and by age (`shout_keep_rows`, `shout_keep_days`) runs in the janitor's
minute tick, in batches by id.

Emoji and stickers (an emoji picker, custom images uploaded in the panel) and the navigation
counter are the next two releases, as the design document says.

### Tests

`tests/shout_test.php`, `tests/shout_test.py` and `scratchpad/shots/shout_check.js` (the widget
in a real browser: a shout that arrives while the box holds a half-typed line, "older", Enter,
delete with its question, a hidden tab that asks nothing).

## [1.57.1] — 2026-09-14

No schema change (one new optional setting, `sound_default_message_friend`, read with a fallback).

### Changed — sounds, after the first day of listening

* A message from a **friend** is its own event, beside a notification and a message from anyone
  else: the pulse, the account's own answer and the inbox poll say how many of the waiting messages
  are from friends (`pmUnreadCountFriends`), and the player tells the two apart. Settings gains a
  default for it; the account's tab a third row. (The shoutbox will add its own kinds — a shout from a
  friend, from a stranger, and an @-mention.)
* The wake-up before a sound is **silence** by default — opening the stream is what wakes an HDMI
  link, and the low tone was heard as a buzz before every chime. The tone stays as an option for an
  amplifier that stands by until it senses a signal, four times quieter than before.
* The "Saved." note under the tab goes after a few seconds instead of sitting there; the tab's
  selects and slider wear the site's own styling instead of the browser's.
* Settings → Sounds: the upload is the same drop zone the language install uses (choose or drop the
  file, the box says what it holds), and the buttons match the rest of the panel.

### Fixed — four small things on the pages

* The syntax help under a description editor folds away and stays folded, exactly as it does under a
  message.
* The shortened hash on favourites and lists says it is shortened (an ellipsis) and copies the whole
  hash on a click or a tap.
* On a phone the account page's tab bar wraps instead of escaping the layout, and the metric cards
  centre their contents.

## [1.57.0] — 2026-09-14

Schema **62** — `index_hashes.idx_index_meta_done_fetched` (a heavy index; the janitor's CLI run
builds it), the owner's mirror row takes the panel's current password hash (once), the old
pm-notification delete runs once, `csp_report_enabled` ships off, and every column and index a
migration adds is now in the base `CREATE TABLE` too. No new feature: this release is the audit of
1.45.0–1.56.0 (`deploy/AUDIT-1.45-1.56.md`) closed, and the gaps in every chart explained.

### Fixed — the charts had gaps, twice an hour, growing with the catalogue

Every long run of the janitor was a gap: the timer does not start a second instance while one is
running, and the index poll (a hundred seconds on production) and the index prune (four minutes —
a protection backfill that scanned the whole catalogue, then the cap deletes) sat inside the same
minute tick as the timeline and traffic samplers. Two gaps each half hour since the catalogue passed
a million rows. The slow half of the janitor — the index poll and prune, the whitelist upkeep and
the submission probes — now runs as a transient unit of its own (`tracker-janitor-heavy`, started
through the root helper's new `janitor-heavy-start` verb, as the same user, at the same lowered
priority, one at a time), and inline as before on a machine without systemd. The backfill itself is
bounded: it looks only at rows resolved since its last pass (a day of margin; the first pass looks
at everything once), on the new index — a tight range instead of a scan.

### Fixed — accounts and the panel

* "Sign out everywhere else", a password change and a reset ended the account's sessions but not a
  panel session opened through that account; it lived on until the idle limit. The panel session
  now ends with the account's (`adminSessionValid()` checks the stamp; `currentUser()` drops the
  panel keys with the user keys). The browser that asked keeps both — its login times are
  re-stamped and its remember cookie is reissued rather than left to be refused at the next restart.
* Signing in through the account opened the panel without the panel's own second factor. With the
  panel TOTP on, an account without an armed member factor now stays out of the panel until it arms
  one (the account page says so). The owner's mirror row in `users` also follows the panel password
  from now on — it carried the hash from install day, so the old password kept working at
  `?action=login`; schema 62 syncs it once.
* One answer to "does this account hold `panel.access`": the e-mail verification gate applies in
  `userHasPanelAccess()` and `panelCan()` as it does everywhere else.
* A timed ban ends when its date says so (`userIsActive()`), not when the janitor next runs; the
  Users page clears `banned_until` on every status change (a stale date from the report card used
  to make the janitor undo a later permanent ban); the report card stores "for ever" as a far date
  like a mute, may lift only bans it made, and refuses to shorten the owner's; a moderator with
  `panel.users.edit` cannot ban staff from the Users page either. Reopening a report keeps the note
  and the reply the first moderator left.
* `user_update` (the password-checking account endpoint) has the same throttle as its siblings;
  the timing dummy for a nonexistent login costs what a real hash costs; arming 2FA on an
  already-armed account is refused instead of silently disarming it; a TOTP code is spent
  atomically.

### Fixed — messages, friends, lists, descriptions

* The badge no longer counts messages from an account that is no longer active (the inbox never
  listed them). `follow` notifies once per request made, not once per click, and answers "friends"
  when the friendship already exists through the other row. A block that hides the profile answers
  not-found to `user_messages` (`can=`, `with=`) as the profile does. The daily send limit is
  counted under a lock. An empty inbox draws its first message.
* Lists and favourites: a hide-profile block hides the data endpoints too; publishing a list asks
  the GRANT like every reader does; list items carry no hash without `index.magnet` (a row id
  removes instead); the whitelist arm of the row loader honours `whitelist.view` and drops banned
  rows for strangers; uploads' publish toggle asks the grant; magnets carry the cluster's extra
  announces; the caps are counted under a lock and a duplicate name is a 409, not a 500; lists 404
  without profiles; a membership scheduled for later does not count yet; the file-name search on
  lists is throttled like the search page; an owner keeps the hash of their own rows.
* Descriptions: with accounts off the hash check is closed, as its own comment promised; the hash
  check answers with catalogue facts only to readers `index_info` would answer (`index.view`,
  `whitelist.view`, `content.view`); a banned hash stops accepting descriptions in both homes and a
  ban clears its `hash_content` row; `description_format` is always normalised; a refused
  submission leaves no row behind; an inline tag split by a blank line renders balanced.

### Fixed — scripts and settings

* `people.js`: the friends/blocks tabs no longer draw one view's rows with another's buttons; the
  polls stop when the session has ended. `app.js`: the pulse lease is per account and cleared on
  sign-out; the Info panel's scroll observer is one and let go on close; the submission probe gives
  up at its own timeout and asks less from a hidden tab. `admin-sounds.js` says so when the
  connection drops.
* The eight polled panel status endpoints release the session before their read; `fav_max_per_user`
  and `auth_bridge_ttl` are clamped on save; the browser installer finishes (the heavy `index_hashes`
  additions are in the base CREATE and `install.php` never defers).

### Tests

`tests/audit_core_test.php` / `.py`, `tests/audit_lists_test.php` / `.py`, the extended
`hash_check_test`, `content_test`, `index_test`, `netlimit_test` (the new verb through the stubs)
and `tests/install_web_test.py` (a fresh install driven through the browser installer under a
non-CLI SAPI).

## [1.56.0] — 2026-09-13

Schema **61** — `sounds` (the owner's uploads, as rows), `users.sound_prefs`, `sounds.use` granted to
the member group, and three settings (`sounds_enabled`, `sound_default_notification`,
`sound_default_message`).

### Added — a sound when something arrives

A member can pick a short sound for a notification and another for a message, on a new **Sounds**
tab of the account page. Everyone starts muted: sounds play only for a reader who switched them on
there. The tab sets the volume, which sound answers which event (the site default, silence, or a
pick from the library), and a **wake-up before the sound**: the stream is opened 0–3 s earlier with
silence or a quiet low tone, for amplifiers and HDMI receivers that wake when a stream starts and
swallow its first second, or that stand by until they sense a signal. A ▶ beside each choice plays
it exactly as it will sound, wake-up included.

The library is the thirteen clips shipped under `assets/sounds/` plus what the owner adds from
**Settings → Sounds** (MP3, Ogg or WAV; up to 512 KB and 15 s; at most 40). An upload is kept in the
database (`sounds`) and streamed by `api/sound.php` with a sha1-versioned URL and a month of caching;
what it is gets decided from its bytes — MPEG frames, an Ogg page, a RIFF/WAVE header — never from
its name or declared type, and the browser is handed the sniffed type with `nosniff`. Settings also
picks the site default per event; deleting an upload clears a default that named it.

The tab that fetched a count is the tab that plays (`window.Sounds.observe`, fed by the pulse loop
and the inbox poll), so a reader with six tabs hears one chime; a hidden tab keeps asking while its
reader wants to hear — the tab they are not looking at is the point. Browsers allow a sound only
after a click on the page: when the context cannot start, a small 🔇 note appears beside the account
link and the first click anywhere plays what was waiting. `sounds.use` is a permission (members by
default); with the feature off in Settings the tab, the endpoints, the script and the note are gone.

### Tests

`tests/sounds_test.php` (the schema and the grant asked of the migration itself, sniffing real and
fake files, the caps, storing and deleting, the clamps, resolution, the page's config),
`tests/sounds_test.py` (the four endpoints end to end: visitor, member, the feature switch and the
permission, the owner's upload, stream, 304, default, delete) and `scratchpad/shots/sounds_check.js`
(the tab, saving, the badge's config, the autoplay note and the click that lifts it, the pulse
handing a count to the player, a hidden tab that keeps asking — in a real browser; headless Chrome
never gates Web Audio, so the check reproduces the gate with the semantics browsers implement).

## [1.55.0] — 2026-09-13

Schema **60** — a setting only: `site_live_seconds` gets its default row on upgrade.

### Fixed — the number on the account link is no longer a snapshot

Outside the account page the navigation badge was filled once, from `user_me` at load, and never
again: a reader on the front page saw the count they had when they opened it. Now every page a
signed-in reader has open asks **`api/user_pulse.php`** — two counts and nothing else, the session
released before the reads — at the cadence of a new setting, **Account badge refresh**
(`site_live_seconds`, 60 s as shipped, 0 = never), separate from the conversation refresh. A hidden
tab asks nothing and asks the moment it is shown again; one flight at a time; and **one request per
reader, not per tab** — the tab that asked leaves the answer in `localStorage` with its time, and
any other tab whose turn comes inside that window takes it from there, told at once by the
`storage` event. Signed out meanwhile, the loop stops.

### Tests

* `tests/pulse_test.py` (the endpoint: who gets what, the clamps, the session released before the
  reads) and `scratchpad/shots/pulse_check.js` (a notification lands with the front page open and
  shows on the badge without a reload; a second tab takes the stored answer; a hidden tab asks nothing).
* `tests/users_test.php` now gives the system groups back when it ends — it resets them to their seed
  for its own checks and left the member group stripped for whatever ran next — and
  `tests/hash_check_test.php` asks the migration itself for the grant instead of trusting the
  previous suite's leftovers.

## [1.54.0] — 2026-09-13

Schema **59** — `message_reports.reply`.

### Fixed — a reported message's reporter hears back

The card for a reported message had one text box, labelled *a note for the log*. A moderator who
typed an answer into it and pressed **Close** had answered nobody: the note stayed in the panel, and
the person who reported got nothing — not on close, not when the message was removed, not when the
account was silenced. Somebody who reports and never hears back stops reporting.

* **A second box: an answer to the reporter.** They receive it as a notification with the outcome:
  *closed*, *the message you reported was removed*, or *handled* (a mute or a ban — the other
  account's punishment is not the reporter's to know unless the moderator writes it). Without an
  answer they still hear that a moderator looked. Reopening says nothing yet; lifting a mute or a
  ban answers no report and says nothing to the reporter.
* **The author of a removed message is told** that one of their messages was removed after a report
  — the fact and nothing else. (A silenced or banned account was already told, since 1.49.0.)
* The card shows the answer given and the note kept, so a second moderator sees what the first did.

Reports of content and abuse (the email form) already had this loop by mail — reviewed, blocked,
archived, a custom message — and are unchanged.

### Tests

* `tests/message_report_test.py` (as the panel: close with an answer, reopen, remove, silence — and
  who was told what) and `scratchpad/shots/msgreport_check.js` (the card, in a real browser).

## [1.53.0] — 2026-09-13

Schema **58** — a new table `hash_content`, `whitelist.content_user_id`, a nullable
`wl_content_edits.whitelist_id` beside a new `hash_content_id`, and the new `content.view`
permission granted to the `member` group.

### Added — a description for any torrent the tracker knows, from the Info panel, in either mode

Until now a description could only be attached from the whitelist form — which exists in whitelist
mode, and registers. A torrent the tracker had only *seen* (every torrent in blacklist mode) could
not be described by anybody.

* **The Info panel offers *Add a description*** to a reader with `content.submit`, and ***Propose a
  rewrite*** when there already is one and the reader holds `content.propose`. The editor is the
  whitelist form's own — same rail, same preview, same limits — cloned into the panel. Whatever is
  sent goes through the same door as the form (`contentAttach()` in `includes/content.php`), lands
  in the same review queue, and obeys the same switches (descriptions on, review on, autopublish,
  the pending-proposals cap).
* **Words about a torrent that is not registered live in `hash_content`**, not on a whitelist row:
  a whitelist row is a registration — the index poll drops such a hash out of the index, and in
  whitelist mode the accesslist is built from those rows. Describing a torrent does not register it,
  and approving the words does not either. The review queue lists both homes, marks an index-only
  row as such, and the *Rewrites* tab handles proposals for either.
* **The author is recorded and shown.** *Description by <name>* under the text, linked to the
  profile where profiles are on; `content_user_id` on both homes, set when words are attached and
  moved to the proposer when a rewrite is accepted. The review cards carry the author too.
* **The author hears what happened**: a notification when their description is published or turned
  down (with the moderator's note when there is one), when their rewrite is accepted or not, and when
  somebody else's rewrite replaced their text. The Info panel tells the author when their own words
  are waiting or were turned down.
* **Reading descriptions is a permission of its own, `content.view`** — members as shipped, guests
  only if the operator says so. A reader without it is told that there *is* a description shown to
  members, rather than shown an empty space. With accounts switched off nothing changes: the legacy
  fallback answers yes for every `content.*` id, as it always has.
* The status page's hash check says what became of the words for either home: published, waiting
  for review, or turned down.

What was already there and is unchanged: writing needs `content.submit`, proposing needs
`content.propose`, the global switches (`wl_allow_description`, `wl_allow_source_url`,
`wl_content_review`, `wl_content_autopublish`, `wl_edit_max_pending`) apply to every door.

### Tests

* `tests/content_test.php` (every path the words can take, in both homes, and who is told),
  `tests/content_test.py` (the `content.view` gate on the Info endpoint and the submit door over
  HTTP, in both tracker modes), `scratchpad/shots/content_check.js` (the editor inside the panel,
  in a real browser). `tests/sql_safety_test.php` reviews the queue's two UNIONed WHEREs.

## [1.52.0] — 2026-09-13

Schema **57** — a grant only: the new `status.hash_check` permission goes to the `member` group.

### Added — the status page answers "what does this tracker know about this hash?"

A second form on **Status**, for a reader with the new **`status.hash_check`** permission (members,
as shipped; the guest group gets nothing unless the operator hands it out). Paste a 40-hex hash, a
base32 hash or a whole magnet link — parsed by the same function the whitelist form uses — and the
answer says, each on its own line:

* **Registered here**, and in what state: live on the accesslist, submitted and waiting for review,
  registered and waiting for its first peer, rejected, registered but never seen, or since banned;
  and whether a description is published.
* **Banned** (in blacklist mode: *blacklisted* — there the ban list is the accesslist).
* **Seen in the swarm**: first and last time, how many times, seeders and leechers at the last look.
* **Metadata**: fetched, queued, being fetched, failed, or not fetched. **Files**: how many are stored,
  and how many the torrent has when the stored list is the capped first part.
* Or, for a hash the tracker has never met, one sentence saying so — which is an answer too, and the
  reason for the gate and for the new **Hash checks / hour (per IP)** limit under Settings →
  Accounts (`rate_limit_hash_check`, 120 as shipped, 0 = none): unbounded, this is an oracle for
  walking the catalogue one hash at a time.

`includes/hashcheck.php` holds the lookup; `api/hash_check.php` is the endpoint. Two new tests:
`tests/hash_check_test.php` (the lookup against fixtures in every state) and
`tests/hash_check_test.py` (the gate, the limit, and one hash spelled three ways).

### Fixed

* The two password boxes on the account page ("Your password, first", "Sign out everywhere else")
  are centred blocks in a centred column; their placeholder was pinned to the left edge. Centred.

## [1.51.1] — 2026-09-13

No schema change.

### Fixed

* **A visitor opening the account page produced eight PHP warnings per view** (since 1.44.0): the
  lists, messages and people sections sit after the signed-in branch of the template closes and
  read variables only that branch defines. The guest's page now ends at the sign-in link. Found in
  the production error log during the 1.51.0 check — the warnings never reached the page there,
  only the log.
* `tests/guest_pages_test.py`: every public page fetched as a visitor must answer 200 with no PHP
  warning or notice in the body, so the next leak of this kind fails the battery.

## [1.51.0] — 2026-09-13

No schema change.

### Added — a public list can be handed to somebody, and the Info panel copies

* **Share on a public list.** A list that is public has an address — its owner's profile, opened on
  that list (`?action=u&name=…#list:<slug>`) — and the card and the list's own window now carry the
  same *Share* button the profile and the search use, through the same clipboard routine and the
  same fallback box. A private list has no address anybody else could open, so it has no button.
  Hidden with the rest of sharing when `search_share_enabled` is off.
* **Copy the info hash, and the magnet, from the Info panel.** A *Copy* beside the hash; under it the
  magnet link, as a link and with its own *Copy*. The magnet is built by the server with
  `buildMagnet()` — the function the panel's rows already use, so it names every announce URL this
  tracker answers on — and is sent only to a reader with `index.magnet`, the same gate the search
  rows obey. Without that permission the row is simply not there.

### Fixed — the account page

* **`[hidden]` loses to a class that sets `display`.** The user agent's `[hidden] { display: none }`
  has zero specificity, so `.nav-unread { display: inline-block }` beat it and an empty badge drew
  as a pill after the account name whenever nothing was waiting — the link was *wider* with a count
  of zero than with a count of one. The same trap sat under the Notifications heading and behind the
  password checklist. One `[hidden] { display: none !important }` for the public stylesheet, the
  way Bootstrap's reboot already does it for the panel; the site-wide scan that found the other two
  is what this rule closes for good.
* **The password box under "where am I signed in" was fourteen rem tall.** `.profile-search` carries
  `flex: 1 1 14rem` for the toolbars it usually sits in; that column is a flex container too, and in
  a column the basis is the height.
* **The buttons under a notification** ("Open People", "Mark read") are one row with a gap; they
  were two inline elements touching.
* **The count on the Messages tab** sits with the word: the markup already leaves a space before the
  badge, and a margin on top of it put them a word apart.

### Tests

* `tests/announce_multiport_test.py` looked for the down instance's port as bare digits (`"6971" not
  in page`), so a swarm count or a size carrying those digits anywhere on the page failed the suite
  by chance. It now looks for the port as part of an address.
* New `scratchpad/shots/polish_check.js` covers everything above in a real browser; `lists_check.js`
  counts the controls that would change a list, not the row they sit in, since Share now sits there too.

## [1.50.1] — 2026-09-13

No schema change.

### Fixed — the stability probe died one second after it started

On every machine that runs the janitor from a systemd timer — which is every machine that follows
INSTALL.md — a run of the stability probe ended within a second of starting, and the card then said
*"A run stopped without finishing. The janitor puts the settings back within a minute."* for as
long as anybody looked. It was not a regression in the probe: the janitor's service is `oneshot`,
and a oneshot service kills every process left in its control group the moment the janitor exits.
The probe was that process. `setsid` and `nohup` would not have helped; systemd tracks by cgroup.

* **The probe is started as a unit of its own.** The janitor asks the netlimit helper (already in
  sudoers) for the new `probe-start` verb, which runs `tools/tuner.py` through `systemd-run` as
  `tracker-probe.service` — as the web user, never root, with the arguments checked one by one and
  the script path pinned to `…/tools/tuner.py`. `systemctl stop tracker-probe` is now a valid Stop:
  the probe turns SIGTERM into the same exit Ctrl-C takes, so it restores on its way out.
  Where there is no `systemd-run`, the helper says so by name and the janitor falls back to the old
  background job — but only when it is not itself under systemd. Under a systemd janitor with an
  older helper it records an honest failure naming the file to install, instead of a corpse.
  **Reinstall the helper:** `sudo install -m 0755 tools/opentracker/tracker-netlimit.sh /usr/local/sbin/`.
* **A dead run is closed, not left as a flag.** The reap restores the settings *and* marks the run
  aborted; a new request or a Stop on a dead run does the same. Before, `running:true` outlived the
  process by a week.
* **The panel's writes no longer revive a dead run.** A request or a Stop stamps `panel_at`; only the
  process writes `updated_at`, the heartbeat liveness is judged by. A request written over a dead
  run used to refresh that heartbeat, so the janitor refused to start the new run for five minutes.
* **A Stop no longer stops the next run too.** The `cancel` flag was never cleared, and the run loop
  honours it at its first sample — so after one Stop every later run would have ended at step one
  saying "stopped from the panel". A new run drops what the last one left behind.
* The card says how the process was started (**Runs as**: its own unit, or a background job), and
  says plainly when the firewall helper is not configured, since the probe both measures and starts
  through it.

### Fixed — 369 strings that existed only in the generated dictionaries

`lang/en.php` and `lang/pl.php` are generated from `tools/lang_src.d/`. Between 1.43 and 1.50, 369
strings (account security, lists, people and messages, the digest, the health endpoint, the abuse
API docs, notifications, and their Settings sections) were added to the generated files by hand and
never to the sources, so the first regeneration since then dropped every one of them. They are
written back into the sources, and `tools/lang_src.py --check` — run by `tests/lang_test.php` —
now fails the battery the day a string reaches the generated file without its pair in the sources.

## [1.50.0] — 2026-09-11

Schema **56** — data only: the `pm` notifications are removed.

### Changed — one message is one record

A message used to leave **two** records of one event: an unread message, and a notification saying
somebody had sent one. Reading the message cleared one of them, so the number on the account link
outlived the conversation it was about and nothing on the page could explain it.

The unread message is now the only record. It is counted on the **Messages** tab, and the number on
the account link is the **sum** of the two things a reader actually has waiting — notifications plus
unread messages — so 2 notifications and 3 messages read as *5* in the navigation, *2* on
Notifications and *3* on Messages. The migration deletes the `pm` notifications already written,
because they describe messages that have long since been read and nothing will write another.

### Changed — the inbox keeps up, and opening a conversation reads it

* **The list is polled too**, wherever live refresh is switched on: two facts and no rows — the
  moment of the newest line anywhere in the inbox, and how many are unread — and the list is redrawn
  only when one of them has moved. A message can now arrive with the inbox on screen and be seen.
  The baseline is handed out **with the list itself**, because a first tick that sets its own
  baseline silently swallows everything that arrived while the list was being drawn.
* **Opening a conversation marks its row read in the list**, immediately. The list stands beside the
  conversation rather than being replaced by it, so a row still saying "2 waiting" next to the
  conversation those two are in was simply wrong until the next reload.
* **The waiting count is a badge beside the time**, not a bar across the row: as two children of a
  two-column row it was landing in the column that holds the name, and being stretched to fill it.

### Fixed — the account page

* The **QR square for two-factor** is drawn at its own size again. The server draws one rectangle per
  module at whole-pixel coordinates with `shape-rendering="crispEdges"`; the page was forcing that
  onto 190 px, which no version of the code divides into, so the browser rounded each edge on its own
  and rows of modules came out a pixel wider than their neighbours. The white behind it is now as
  wide as the code rather than as wide as the card, and the sentence about typing the key by hand
  sits with the key instead of against the square.
* **The password field is a line of its own**, centred, with the two answers under it — in the
  two-factor card and in "signed in on N devices", where the field and its button are now one column
  of one width with room between them and the paragraph that explains them.
* The three buttons under the account heading are sized like buttons under a heading.
* The Settings label for the "…is writing" switch was written in HTML entities and printed through
  `_h()`, which escapes — so it read `&ldquo;…is writing&rdquo;`. `tests/lang_test.php` now fails if
  any escaped label is written that way.

## [1.49.0] — 2026-09-10

Schema **55** — `users.pm_muted_until`, `users.banned_until`.

### Added — an answer to the person, not only to the message

A reported message could be deleted, and that was the whole vocabulary: the line went, the account
that wrote it carried on. The report card now also carries **silence their messages** (1, 7, 30 days
or until somebody lifts it) and **ban the account** (7, 30 days or until somebody lifts it), each
asked about before it happens and each written to the audit log with both names.

**Both are dates, not flags.** A punishment with an end needs nobody to remember to end it — and the
person who would have to remember is the one who was angry a week ago, which is how accounts nobody
thinks about stay punished for ever. Every reader compares the column with `NOW()`, so a moment that
has passed is already not in force; the janitor tidies the columns afterwards because a row saying
"banned until last Tuesday" is a row somebody will misread, not because anything depends on it.

**A silenced account is told.** The composer says a moderator has silenced them and that they can
still read what they have, rather than swallowing what they type — the same rule the blocked-sender
message has followed since 1.45.0.

**Nobody who can open the panel can be punished from this card**, and neither can you yourself.
Removing a moderator is a decision that belongs on the Users page, where it is visible as what it
is.

### Changed — the reported-messages card

* **Deleting asks a question that stays until it is answered.** It used to arm itself on the first
  click and disarm four seconds later, which is a confirmation somebody has to win — and it looked,
  correctly, like a button that did not work.
* **The card says what the account already is**: how many times it has been reported in all, whether
  its messages are silenced and until when, whether it is banned. Without those, every report reads
  like a first offence whether it is the first or the fifth.
* The two rows of buttons are separated: answering the message and answering the person are not the
  same decision and should not share a reflex.

## [1.48.0] — 2026-09-10

Schema **54** — `message_typing`.

### Added — a conversation that keeps up with itself

An open conversation can now ask, every few seconds, whether anything arrived — and say so when the
other person is writing. Both are **off by default** and both are the operator's decision:
`pm_live_seconds` (0 = off, otherwise 2–60) and `pm_typing_enabled` in Settings → Messages.

It is a poll, and it is a small one. The request carries the id of the last line the page already
has and the reply carries **new rows only**, so the usual answer is three numbers and an empty list.
A tab in the background asks nothing at all and catches up the moment somebody comes back to it. New
lines are appended rather than the thread being redrawn — redrawing would take away the half-written
sentence in the box underneath. And it never scrolls somebody away from the line they are reading:
the conversation follows the bottom only if they were already at the bottom.

The same reply carries two courtesies that used to need a reload: the unread badge, and the **read**
mark on your own side of the conversation.

**"…is writing" expires by itself.** One row per person per conversation, with a moment a few
seconds ahead of the last keystroke — not a "started" event waiting for a "stopped" that may never
arrive from a closed tab, a slept phone or a dropped connection. A keyboard produces at most one
tiny write every four seconds; the janitor sweeps rows whose moment has passed, and losing the whole
table would cost one refresh of one line.

## [1.47.1] — 2026-09-10

Schema **53** (unchanged).

### Changed — the round of small things that were wrong

* **A control now has an edge you can see.** `#222` is right for a rule between two paragraphs and
  wrong for the border of something you are supposed to click or type into: the password box under
  "signed in on N devices", the select under "who may write to me" and the buttons inside a
  notification all disappeared into the card they sat on. Dividers keep that colour; anything
  interactive gets `--control-border` and a faint ground.
* **Share is visible without looking pressed.** It was drawn in the accent colour, which is what the
  pointer says when it hovers — so the button arrived already lit and had nothing left to say.
* **"New list" opens one form**, and pressing it again closes it. The guard looked for the form
  inside the toolbar while the form was inserted after it, so every press added another one. It also
  stands apart from its neighbours now instead of being pressed against them.
* **The list shelf can be searched by what is ON the lists** — the torrents, and (behind the same
  `index.files` permission as everywhere else) the file names inside them. "Which of my lists has
  that episode in it?" is not a question a list's name can answer.
* **The member directory sorts** by name or by the date people joined, both ways — and it no longer
  offers you a message to yourself or a friend request to yourself. You are in your own directory
  because you asked to be listed; you are marked as *you* and offered nothing to do about it.
* **The nav entry for the directory is gone.** It is a tab of the account page, and the account is
  already in that bar; two links to one page, highlighting differently, was one too many.
* **The message composer is the whole editor**: the formatting rail the description form has, a box
  worth writing in, a format selector that looks like a control, and the wall of BBCode tags folded
  behind one line instead of sitting under every conversation.
* **Reporting a message opens a window of this site's own** rather than `window.prompt()` — which
  could not be styled and, more to the point, could not say that a moderator sees the reported line
  and the one before it and nothing else. That sentence is the reason somebody is willing to press
  the button. The button itself now carries a flag and stops vanishing under the text.
* **The inbox filter can look inside the messages**, with a checkbox beside it. Names are filtered in
  the browser because the list is already there; the bodies are a `LIKE` over this reader's own
  conversations, so it is opt-in, waits 600 ms for typing to stop, and holds its own rate limit.
* **The account's second factor says what it is not.** An account that can open the panel has two of
  these and they are not the same one: separate secrets, separate recovery codes, and turning one on
  does not turn the other on. The setup box also stacks and centres instead of being squeezed into a
  column corner.

## [1.47.0] — 2026-09-10

Schema **53** — `user_twofa`, `users.sessions_valid_from`, `user_tokens.ip` / `.ua`.

### Added — a second factor for member accounts

The panel has had two-factor authentication since 1.14.0; an account has had a password and nothing
else. `user_2fa_enabled` (off by default) lets members turn one on for themselves from the account
page: the password, then a QR **drawn by this server's own encoder**, then one code to prove the
phone received the secret, then ten recovery codes shown once. Signing in afterwards asks for the
password and then the code, in a second request — a form that always showed a code box would tell
every visitor that this site wants one, and would be asking before it knew whether the account had
one.

A code works **once**: the accepted TOTP step is stored, and anything not newer is refused however
correct the digits are. Without that the same six digits are good for up to ninety seconds. Recovery
codes are stored hashed, work once, and the list shrinks by exactly one when one is used.

`user_2fa_required` — `off`, `panel`, or `all` — **never refuses a sign-in**. What it withholds is
the admin panel: an account that can open the panel and has no second factor keeps working as an
account, and the account page says why the panel will not open. A requirement that can lock somebody
out of their own account is one an operator turns off again.

### Added — "signed in on N devices", and signing out everywhere else

The account page lists the browsers that asked to be remembered, with the address and user agent each
token was handed to, and marks the one you are reading it on. It says that is what it is listing: a
plain sign-in leaves no row anywhere, so the number is what the site can prove rather than every open
tab in the world.

Ending them is not limited that way. `users.sessions_valid_from` is a unix stamp on the account, and
`currentUser()` drops any session that began before it — which reaches the sessions no cookie points
at. A **password change** now sweeps them too (it already cleared the remember tokens, which was half
of it), and a password **reset** sweeps everything including the browser doing the resetting.

The column is a `BIGINT` of unix seconds rather than a `DATETIME`, deliberately: it is compared
against a time PHP wrote into the session, and a comparison between a clock the database keeps and a
clock PHP keeps is one this project has already got wrong once.

## [1.46.0] — 2026-09-10

Schema **52** (unchanged).

### Added — the partner loop closes

`v1/whitelist/status` answers what happened to the hashes a partner sent. A key set to *hold for
review* got `pending` back from `v1/whitelist/submit` and then never heard another word: a person
here approved the row or turned it down **with a note written for that partner**, and the note went
nowhere. Ask with one hash in the query string or a batch in the body; each answer says the state
(`live`, `pending`, `rejected`, `banned`, `unknown`, `invalid`), whether the tracker is serving it,
and when it was submitted and reviewed.

**The note goes only to the key that submitted the row.** Any key with the `whitelist` scope may ask
about any hash — `v1/whitelist/submit` already answers `exists`, so nothing new is disclosed — but
somebody else's row comes back with `mine: false` and no note. The status is a fact about the
tracker; the note is a message to one partner. The integration guide at `?action=apidocs` grew a
chapter about it, which says different things for a key that publishes directly and one that does
not, because they need it for different reasons.

### Added — the operator's digest

Everything a person has to *decide* waits silently in the panel: a partner's submission held for
review, a description waiting to be read, an abuse report nobody has opened, a message somebody
reported. The tracker runs itself; the queues do not. **Settings → Operator digest** sends one mail
saying what is waiting, from the janitor timer, through the existing mail configuration, logged in
`sent_emails` like everything else.

`digest_hours` is a **floor between mails, not a timetable**: a tick that finds nothing waiting does
not stamp the clock, so the first thing to arrive after a quiet week is reported at once rather than
at the end of an interval that started while there was nothing to say. `digest_min` is the other
half. `digest_to` falls back to the site contact when empty — but a value that is not an address
stops the mail rather than quietly sending the operator's queue summary somewhere else.

### Added — a health check an uptime monitor can act on

A monitor pointed at the home page proves that Apache answers. It does not notice that the database
is a schema behind the code, that the accesslist has not been written since a failed reload three
hours ago, that the metadata worker died with a queue behind it, or that the panel says WHITELIST
while the tracker is running open. `?action=health` answers all of that as one JSON document:
version, schema, mode agreement, accesslist state, worker heartbeat, queue depths, and a `problems`
list a human can read in the alert.

The levels come from the panel's own status card, so the endpoint and the dashboard cannot disagree
about what healthy means — a `danger` warning there is `fail` here, which answers **HTTP 503** so
that a monitor understanding only up/down still tells you. Without `health_token` the address does
not exist, and a **wrong** token gets exactly what no token gets: the ordinary page. A probe cannot
learn there is a secret to find. A token under 16 characters counts as empty — that is not a smaller
secret, it is a public endpoint.

## [1.45.1] — 2026-09-10

Schema **52** (unchanged).

### Added — "keep what people kept"

The janitor prunes the catalogue on its own schedule: a hash nobody has announced for a while loses
its grace, a hash whose metadata never arrived loses its window, and the row cap evicts oldest-first.
None of that knew anything about the people using the site, so a torrent somebody had starred or put
on a list could quietly disappear out from under the list that named it.

**Settings → Index** now carries `index_keep_saved`, with three answers:

* **off** — nothing changes; the lifecycle an install already has is not something to alter under an
  operator who has not asked. This is the default.
* **forever** — while anybody has it starred or on a list, it stays.
* **extend** — it gets `index_keep_saved_days` on top of whatever protection it already had.

The extra window is measured from `protected_until`, the same column everything else is measured
from — which is pushed forward every time the swarm is seen — so a hash that comes back has both
clocks restarted at once. There is no second timestamp to keep in step and none to forget to update.
The clause is two `EXISTS` over two indexed columns and disappears entirely when the setting is off,
so a prune on an install that never touched this runs the query it always ran.

### Changed — the polish pass over favourites, lists and people

* **The list picker is one box.** It filters the lists while somebody types and offers *New list
  "name"* when nothing they have answers to what they typed — the same keystrokes either way, and no
  decision to make before starting to type. It paginates, so a shelf of thirty lists is a window
  rather than a scroll.
* **The message composer is the editor the rest of the site writes in**: the tabs, the formatting
  rail, the live preview and the counter that descriptions have had since 1.21. The editor was
  hard-wired to one textarea, which is why a message got a bare box; it takes an id now.
* **Messages can be started from the inbox.** Every route into a conversation used to begin
  somewhere else — a profile, the directory, a notification — so an inbox with nobody in it was a
  page with nothing to do on it. A friend can also be written to from the row that says they are one.
* **A friend request in the notifications links to the tab where it can be answered**, and the
  People tab carries the number of people waiting.
* **The member directory moved into the account page's tabs.** It is one more list of people beside
  the reader's own friends and blocks; `?action=members` redirects there, because links to it are
  out in the world.
* **Searching a favourites list or a list matches file names**, with the same *Also search file
  names* checkbox the search page carries — gated on the same `index.files` permission, in the
  endpoint as well as in the page.
* **The Info panel opens on top of the list it was opened from.** Every overlay shared one
  `z-index`, so opening the panel from a row inside a list window showed the reader the window they
  had just left.
* The info hash gets a row of its own in the Info panel instead of breaking across three lines in
  half a grid column; Share is drawn to be seen rather than in muted grey on a muted border; and a
  link styled as a button is now the same height as the buttons beside it.

### Fixed

* **`t()` replaced `:page` inside `:pages`**, so every paginated list in the browser said "Page 1 of
  1s" whatever the count was. Placeholders are replaced longest-name-first now, which is the rule
  PHP's `strtr()` has always used.
* **The rich-text preview posted the whitelist form's CSRF token and nothing else**, so the same
  editor on any other page was answered 403. It now finds the token the page it is on carries.
* **The preview endpoint asked the wrong question about a message**: gated on "may submit content"
  and on the whitelist's description switch, it refused to show a member their own message on a
  tracker with descriptions off. It takes `for: 'message'` and asks whether they may send one.

## [1.45.0] — 2026-09-10

Schema **52**.

### Added — people: messages, following and friendship, blocks, and a directory

Accounts could see each other's public pages and nothing else. They can reach each other now, and
every part of that is off until an operator turns it on.

**Private messages.** An inbox with unread counts, a conversation with the person rather than a
subject line, and the same BBCode/Markdown the public descriptions use — one sanitizer decides what
may be displayed on this site, and a message goes through it like everything else. Two ceilings, and
they answer different questions: an IP rate limit is about a script, `pm_max_per_day` is about an
account.

**Following and friendship are one row, read two ways.** `user_friends` holds "A asked B"; until B
answers, that *is* a follow, and accepted it is a friendship. That is the operator's own decision:
a one-sided follow is useful on its own, and a friendship that exists in one direction and not the
other is a bug waiting to be written. Following back somebody who already asked you *is* accepting —
one row, and the two end up friends rather than each holding a request the other cannot see.

**Who may write to me** is the reader's own setting, and `NULL` is not a fourth value — it means
"whatever the site says". So an operator changing `pm_who` moves everybody who never expressed a
preference and overrules nobody who did. The site ships with **friends**, not **all**: an inbox
anybody may write to is a decision to make deliberately rather than one to arrive at by not thinking
about it.

**A block stops the messages, and optionally hides the profile** — two decisions, asked in the same
breath, because some people want the messages to stop and some want to disappear. Blocking somebody
also stops *you* writing to *them*: a one-way conversation with somebody you have blocked is not
something to offer. **The blocked sender is told.** Silently swallowing a message teaches somebody
that they are being ignored, which is both false and slower to find out than the truth. A hidden
profile answers with the same not-found page a name nobody has answers with.

**A member directory** (`?action=members`), listing the people who ticked *list me in the member
directory* and nobody else. "My profile is public" and "put me on a list strangers browse" are
different sentences, and reading one as the other would put somebody on a page they never asked to
be on. It leaves out anybody who has hidden their profile from this reader, and anybody this reader
has blocked.

### Added — a queue for reported messages, and a rule about how much of one it shows

Reporting a message puts it in front of a moderator on the Reports page, behind **its own
permission** (`panel.messages.view` / `panel.messages.handle`) that the migration grants to
**nobody** — not even to the seeded moderator group. Reading somebody's private message is a
different kind of access from working the torrent-report queue, and an operator hands it out on
purpose.

**A report carries the reported line and the one before it. Never the conversation.** Two people's
correspondence is not evidence in bulk, and somebody deciding about one line does not need the rest
of it. The rule is in the schema (`message_reports.context_id`), in the endpoint (two LEFT JOINs by
id, and no query anywhere in the panel that takes a thread and returns its contents) and in
`tests/people_test.php`, which asserts that the panel source has no thread-wide read in it. Three
actions and no fourth: close, reopen, delete the reported message — every one of them audited with
both accounts named.

Deleting an account takes the whole correspondence with it: the reports first, then the messages,
then the threads, then the friendships and blocks — the rows are keyed by thread, not by user, so
the order matters.

### Fixed — a profile page posted an empty CSRF token

Every write from `?action=u` — following somebody, blocking them, un-starring a row of your own list
— answered 403, because the page carried no token for the scripts to find. That reads exactly like a
permission problem and is not one.

### Fixed — the inbox showed what was in it when the page loaded

Switching to the Messages tab is a hash change or no navigation at all, so nothing re-fetched — and
coming back to an inbox is precisely when somebody wants to know what arrived. The tab bar tells
`people.js` it is on screen, and the inbox reloads.

### Changed — pmCanWrite() asks about the SENDER

It took a sender and then checked `userCan()`, which answers about whoever is making the request.
Behind the endpoint those are the same person; anywhere else they are not — a CLI test has no session
and a panel session answers yes to everything. A gate that cannot be asked about a named account is a
gate that cannot be tested.

## [1.44.1] — 2026-09-10

### Fixed — a privacy switch that saved an answer nothing acted on

Somebody ticked *Show my favourites on my profile*, watched it save, opened their own profile from
another account and read **"This account does not share anything publicly."** The switch was real and
the thing it controls was not: `favContext()` decided whether to offer it with `userCan()`, which
answers yes to any administrator, while every page that READS such a list asks the grant on the group
row (1.43.1, and rightly — publishing is consent, not authority).

Both halves ask the grant now, and where the site allows publishing and the reader's groups do not,
the switch is **still shown** — hiding it would leave them with no way to find out why their profile
is empty — under a line that says which permission is missing and who grants it. `api/user_uploads.php`
and `api/user_favourites.php` were still asking the blanket too; they ask the grant.

### Added — the Info panel says when a torrent is registered here

The search row has carried the **WL** badge since the catalogue and the whitelist were folded into one
list. The panel that opens from that row did not, so the one place with room to explain it said
nothing.

### Changed — a list opens in a window of its own, from anywhere on its card

Unfolding inside the card put a filter, an add box and twenty-five rows into a tile sized for a name
and a count. The rows are the same rows the search results use and they need the width. The whole
card opens it now — a name that happens to be a link is a target somebody has to aim at.

### Changed — adding to a list only takes hashes this tracker knows

**Not "resolved"**: a registered row whose metadata the worker has not fetched yet is still a torrent
this tracker has, and waiting for a background job is not a reason to refuse it. What is refused is
forty hex characters nobody here has ever seen — that is not a torrent, it is a string, and a list is
not a place to keep strings on somebody else's server. The box says so **while somebody types**: the
format is decided in the browser (so a typo costs no request at all) and the "do you know this?"
question is debounced and sits behind the same rate limit as adding, so live feedback cannot become a
faster way to probe the catalogue than adding would be. A known hash comes back named.

### Fixed — three counts that went stale under the reader's cursor

The list picker's per-list count after ticking a box, the count in the list window after adding or
removing, and the card behind that window after it closes.

### Added — favourites and lists search file names too

Somebody looking for a track or an episode inside a pack knows the file, not the release name. The
search page has offered that for a while; a list that could not do it sent them back there to look
the hash up. Bounded by the hashes the list already holds — never a scan of a files table.

### Changed — Share and Open my profile moved to the top of the account page

They were a quiet grey pair under a paragraph in Privacy, and the first person to look for them did
not find them. They sit beside *Sign out* now, in that order: Share, Open, Sign out.

## [1.44.0] — 2026-09-10

Schema **51**.

### Added — lists: a collection somebody makes on purpose

A favourite is one bit about one hash: I like this. A **list** is a thing somebody made — a name they
chose, the torrents they put in it, and their own answer to who may see it. Two tables of its own
rather than a column on `user_favourites`, because starring something and putting it in a collection
are different acts: un-starring a torrent must never silently empty somebody's pack, and a pack must
be able to hold a hash its owner never starred.

It earns its place most in **blacklist mode**, where there is no whitelist to group anything by. A
reader can still gather their own uploads, or a set of files that belong together, and hand the
collection to somebody.

- **Cards on the account page** (`#lists`): make one, rename it, publish it, delete it (two clicks,
  and the button says so in between — a list is somebody's work), and open it to manage what is
  inside. A filter over the shelf, and a search, a sort and a pager inside each list.
- **Adding by info hash or magnet link**, pasted straight in. The catalogue is asked what it knows,
  and the answer decides the NAME, never whether the row may be added: a hash this tracker has never
  seen still builds a working magnet, and refusing it would break the feature for exactly the case it
  exists for. A hash the tracker **refuses** is the one thing turned down.
- **“Put this in a list”** beside the star in the Info panel: a checkbox per list (a torrent can be in
  several), and a name box at the bottom, so a new list can be made without leaving the torrent that
  prompted it.
- **A section on the public profile**, and the **“who has this”** overlay now also says which public
  lists a hash is on — often the more useful answer, because it says what somebody keeps it *with*.
- Each row offers **Info**, like a search result, and a name recorded when the row was added: the
  janitor prunes hashes nobody announces, and a list that then says nothing but forty hex characters
  is unreadable to the person who made it.

**Five answers, and any single no hides a list from strangers**: `lists_enabled`,
`lists_public_enabled`, the owner's group holding `lists.public`, the owner's own “show my lists on my
profile”, and the list's own switch. The site-wide switch can only ever narrow — turning it off takes
every list off the public side without editing anybody's row, and their choice comes back when it is
turned on again. `tests/lists_test.php` walks that table one flag at a time against the real query,
in both languages it is written in (PHP for the profile, SQL for the overlay), because the two
disagreeing is invisible from either side alone.

Everything ships **off**. Permissions `lists.use` and `lists.public` go to the seeded `member` group
and to the *Site member* preset; guest gets nothing, as with favourites. Deleting an account takes
its lists and their rows with it — the rows are keyed by list, not by user, so they go first.

## [1.43.1] — 2026-09-10

### Changed — appearing on a public list is consent, and consent is the grant

1.42.3 made `userGroupIdsWithPermission()` answer yes for the system `admin` group whatever its JSON
said, so the SQL half of "who has this in favourites" would agree with the PHP half. That was the
wrong half to move.

`userEffectivePermissions()` hands an administrator every registered permission because they have to
be able to work the site. That is a rule about **power**. `favourites.public` and `uploads.public`
are not power: they do not say what somebody may do, they say what other people may see of them.
Reading the administrator's blanket as consent puts their favourites on strangers' screens because of
a rule about what they are allowed to fix — without the box that appears to control it ever being
ticked by anybody.

So the grant is what counts, on both sides, for everybody. `userIdHasGrantedPermission()` asks the
ordinary question first (accounts on, groups active, the e-mail gate satisfied) and then asks whether
a group actually **grants** it; the public profile uses it, and the overlay's SQL reads the same
stored JSON. An administrator who wants to appear grants it to their group, exactly like anybody
else — and the group editor's *Site member* preset has carried all four favourites permissions since
they existed, so a group made from it already has them.

### Added — Info on a favourites row, the same panel the search results open

A row on the account page's Favourites tab, or on either list on a public profile, could hand over a
magnet and nothing else: to ask what a torrent actually **is**, the reader had to go back to the
search page and type the name in again. The panel's markup is a partial now
(`templates/partials/info_overlay.php`), included by all three pages, and its script moved out of
`initSearch()` — which returns on its first line anywhere but the search page — into an
`initInfoPanel()` that wires up wherever the markup is. The panel reads its own answers (the star,
"who has this", how the file list fills up) off its own markup, because it now runs on pages that
have no search form to read them from. `window.TorrentInfo.open(hash, name)` is what a list calls;
the search page keeps its address in step through a hook it sets itself.

### Fixed — the Share button on a profile was a thousand pixels from the profile

`margin-left: auto` put it hard against the right edge of a full-width row, quiet grey on near-black.
It sits beside the name now. The account page grew the pair that belongs there too: the Privacy
section said "your profile is at `?action=u&name=…`" as plain text, and now offers **Open my
profile** and the same Share beside it — that page is where somebody is standing when they wonder
where their profile is.

### Changed — the settings page offers the groups that exist

*Default group* was a free-text field with a slug pattern. It accepted any well-formed word,
including one nobody ever created — and a default group that does not exist is a setting that looks
saved and grants nothing to every account made afterwards. It is a select of the real groups now, and
the save endpoint refuses a slug with no group behind it.

### Changed — the fifth password rule is centred again

Tried as the first item of a third row, put back: in a block that narrow the centred line closes the
shape and a left-aligned one leaves the corner hanging. The browser check pins the centring so a
later tidy-up cannot quietly undo the decision.

## [1.43.0] — 2026-09-10

Schema **50**.

### Added — abuse reports over the partner API (`v1/blacklist/submit`, scope `abuse`)

A company that finds its work on this tracker had one way in: the public *Report* page, one form at a
time, by hand. A partner with a catalogue to defend now has the same claim as an API call, in
batches, from their own system — and it lands in the same queue, in front of the same person.

**It is a scope of its own, not a flag on the whitelist key.** Registering a hash says "this exists";
reporting one says "this infringes something of mine, and I am the one who may say so". A forum
trusted with the first is not thereby trusted with the second, so a key needs `abuse` even if it
already holds `whitelist`.

**And its default is the opposite one.** `api_clients.auto_approve` defaults to 1: a partner's
registrations publish straight through, because the cost of being wrong there is a row in a
catalogue. `api_clients.abuse_auto_block` defaults to **0**, because the cost of being wrong here is
a torrent that stops working for everybody who has it, and nobody outside this server can undo it.
An operator who trusts a rights holder that far has to say so, per key, in the panel — where the
choice now sits under a sentence that says exactly what it does.

Per key, the operator can demand a title, an evidence URL, a reason, the reporter's identity
(name, representative, company, e-mail) and a good-faith declaration. An item missing one is refused
on its own with the field named; the rest of the batch goes through. The guide to send a partner is
`?action=apidocs&scope=abuse&…`, and it is built by the panel while the operator is still choosing.

Each report carries the key that filed it (`reports.api_client_id`), so the queue says who is asking
rather than showing a company name anybody could type. That label survives being archived and
restored — both of those copy their columns by name, and a column nobody names is dropped at exactly
the moment the record becomes the permanent one.

`deploy/smoke_blacklist.py` proves the part that matters against the real endpoint, a real key and
the real blacklist file: **a report waiting for review has not blocked anything**, the tracker still
serves the hash, and the row is in the panel queue with the partner named. Then the same file, with a
key that was given the power: `blocked`, and the hash is in the file the tracker reads.

### Fixed — a key's own settings never reached the endpoint that was supposed to obey them

`apiAuthenticate()` selected six columns from `api_clients`, and `auto_approve` and `required_fields`
were not among them. `api/v1/whitelist_submit.php` read both — `(int)($client['auto_approve'] ?? 1)`
and `$client['required_fields'] ?? ''` — off a row that did not contain them, so both fallbacks
applied to every key ever used: **publish immediately, demand nothing.**

So "hold this partner's submissions for review" published them, and a key told to require a title
accepted items without one. The queue worked, the generator withheld pending rows exactly as
`tests/partner_api_test.php` proves, the panel showed the operator's choice back to them — and
nothing on the way in ever looked at it. The gap was invisible to every test, because the tests that
know about the setting write the rows themselves and the test that goes over HTTP had a key with
default settings.

Three checks in `deploy/smoke_admin.py` now create a key that holds submissions and demands a title,
send two items through the real endpoint and read what comes back. Reverted against the old
`SELECT`, they fail with `status: added` on both items.

### Fixed — a migration that assumed a table the schema does not own

The v50 columns hang off `reports` and `archives`, which install.php writes by hand — they are not in
`trackerSchemaStatements()`. An `ALTER` against a table that is not there throws, `ensureSchema()`
stops on that line, and every migration after it never runs: the version stays where it was and
nothing says why. `tests/install_test.php` builds its scratch databases from the statement list alone
and caught it immediately (nine checks red, schema stuck at 0). There is a `schemaTableExists()` now,
and the two ALTERs ask before they speak.

## [1.42.3] — 2026-09-10

### Fixed — an administrator was invisible to "who has this in favourites"

The overlay decides `favourites.public` **in SQL**, deliberately: filtering in PHP after the LIMIT
would make the total a lie, and paginating a lie is a leak. That decision reads the permission JSON
on each group row — and the system `admin` group's JSON does not list most permissions.
`userEffectivePermissions()` grants them in code instead: *"membership in the system admin group
grants every registered permission"*, a sentence that has been in `includes/users.php` since accounts
existed.

So the two halves of one rule disagreed, in the direction nobody checks. An administrator who had
ticked both privacy boxes and starred a torrent was absent from the list **and** from its count — on
their own tracker, looking exactly like a feature that does not work. `userGroupIdsWithPermission()`
now answers for the admin group whatever its JSON says, and the e-mail-verification clause beside it
carries the same exception the PHP does ("verification gate included").

`tests/favourites_test.php` pins it from both ends: the admin group's stored JSON really does not
list `favourites.public` (so the special case is load-bearing rather than decorative), the helper
answers yes for **every** registered permission, and a group that holds nothing is still left out.
The browser check drives the whole thing through a real overlay with an account whose only group is
`admin`.

### Added — a Share button on a public profile

A profile is the page people hand to each other and it was the one such page without the button. It
copies `?action=u&name=…` built from the name rather than from the address bar, for the same reason
the Info panel's Share does: an address carries a tab, an anchor and whatever else the reader arrived
with, and none of that is part of "here is somebody's profile". The clipboard fallback for plain HTTP
comes with it — those helpers moved out of `initSearch()`, which returns on its first line anywhere
but the search page.

### Fixed — the fifth password rule stood on an axis of its own

Five rules in a two-column grid: the last one spanned both columns and centred itself, which reads as
a different *kind* of rule rather than as the fifth of five. It is the first item of a third row now.

## [1.42.2] — 2026-09-10

### Fixed — the star had been on a line of its own since the day it was added

`.search-c-actions` was 13.5 em, and its comment said what it had been measured against: three
buttons. The star arrived later and nobody re-measured. The set needs 204 px in English and 211 px
with the Polish "Kopiuj"; the cell spends 24 px of its own on padding, which left 192 px, so the
star wrapped under Info on **every** screen width, not only on small ones. The column is 15 em now
and the row is `flex-wrap: nowrap`, so a label that grows shrinks the buttons rather than dropping
the star to a second line. Phone widths keep the wrap: there the table already scrolls sideways, and
a second line inside the column beats a star pushed off the edge of the screen.

`scratchpad/shots/fav_check.js` now asserts the geometry — same line as Info, after it, inside its
own column — because this is exactly the class of bug that a green suite of API tests cannot see.

### Fixed — the results table was one `<col>` short whenever ratings were shown

The `<thead>` added a rating column under `repEnabled() && repShowInResults()`. The `<colgroup>`
never did. Every declared width after the third column therefore landed on the column to its left —
"last seen" got the rating's, the actions column got "last seen"'s — and the last column, which had
no `<col>` left to claim, was sized from whatever space happened to be over. The question is asked
once now, into `$repCol`, and both the colgroup and the header read that one answer.

### Fixed — the name column could be rendered zero pixels wide on a phone

With `table-layout: fixed` the unspecified column gets the leftovers, and under 768 px the specified
ones already added up to more than the table's own `min-width`. The leftovers were nothing: the
torrent name — the one column a reader is looking for — came out 0 px wide and invisible. The mobile
rules now name every column, including the name, and let the wrap scroll the difference.

### Fixed — the Info panel's star sat marooned in the middle of the panel head

Two `margin-left: auto` in one flex row — one on `.info-acts`, one on `.info-share` — do not both
push right; they split the free space between them, which is why the star and "who has this" sat in
the middle of the head with a gap on either side. One auto margin now, and the star, "who has this",
Share and the close button travel together to the right edge.

### Fixed — the favourites and "my torrents" lists were ragged text, not columns

Every row sized its own three facts to their own content, so the size, the swarm and the hash landed
at a different x on every line of the list. `.pf-meta` is a three-column grid now, right-aligned with
tabular figures, and the swarm cell is always drawn — an unknown swarm is a dash, because a missing
cell would slide the hash into the column beside it. The action buttons get a floor of their own so
Magnet and the star line up down the list. The same lists, on the profile page and on both account
tabs, read like the search results they sit next to.

### Fixed — the filter box on those lists was a white browser default

`.profile-search` carried a width and nothing else. The dark styling on this site lives on
`.form-group input`, and none of these three lists is a form — so the box was white on the profile
page, white on both account tabs and white inside the "who has this" overlay. The class styles
itself now, and so does the sort `<select>` beside it.

### Fixed — a privacy tick that did not line up with the sentence it belongs to

The two boxes under Account → Privacy were bare `<input type="checkbox">` while the mail preferences
directly above them use the site's own drawn box, and the native one sat 7 px above the centre of
the line it belongs to. They are the same control now, aligned to the **first** line of the label, so
a two-line sentence keeps its tick beside its first word instead of beside its second. The whitelist
page's "show this on my profile" box, the third of the three, changed with them.

### Changed — an empty "who has this" says why it is empty

The list only ever names people who agreed to be named, and the count deliberately does not
differentiate — so an empty list under a non-zero count is correct and used to look like a fault.
It now says so in as many words, and points at where the reader decides for themselves.

### Fixed — `tests/install_test.php` could not reach a database server that is not on 3306

It read the port from a `DB_PORT` constant, which a config written as a bare DSN never defines, and
fell back to 3306. On a development machine whose server listens on 3307 the suite exited 2 with
"cannot reach the database server" — a red suite that says nothing at all about `install.php`. The
port now comes from the connection the tests are already using.

### Fixed — "&middot;" printed as itself in the account page's email hint

One half of that line went through the escaping helper and the other through the plain one, so the
first separator arrived as literal text and the second as a dot.

## [1.42.1] — 2026-09-10

### Fixed — a fresh install came up missing nineteen columns and a whole group

`install.php` created the tables from `trackerSchemaStatements()` and then stamped
`schema_version = TRACKER_SCHEMA_VERSION`. The reasoning was that everything had just been created.
It had not: a column added after its table was written lives in `trackerSchemaGuardedStatements()`,
a permission added later lives in `trackerSchemaDataMigrations()`, and `ensureSchema()` returns
immediately when the version already matches — so on a fresh install neither ever ran.

Measured against an upgraded database, a freshly installed tracker was short of **fourteen columns
on `whitelist`** (`source_url`, `description`, `content_status`, `probe_status`, `dead_since`, the
four rating columns…), four on `index_hashes`, `users.bulk_optout`, three indexes, the entire
**`moderator` group**, and seven permissions on `guest`/`member` including all four of this
release's favourites permissions. The whitelist page selects some of those columns by name, so the
site did not come up. Nothing in the suite could see it, because every test runs against a database
that was *upgraded*.

The installer now writes `schema_version = 0` and calls the ordinary migration, which is the path
every upgrade takes and the one the whole battery exercises on each run — one path, so there is
nothing left to diverge. It also refuses to report a finished install if the schema stopped short.
`tests/install_test.php` builds both databases from empty and diffs tables, columns, indexes,
settings keys and group permissions; reverted against the old installer it fails on eleven checks.

This is not a regression from 1.42.0 — the oldest missing column dates to 1.17.0. It is the answer
to "does a fresh install come up", and until now the honest answer was no.

### Fixed — the API key editor asked questions the scope could not answer

A key scoped `federation` was still asked how its submissions should be approved and which fields
they must carry. A federation key never creates a whitelist row, so those were questions about
something that cannot happen — and a dialog that asks them teaches an operator that the answers
there do not mean much.

The scope now drives the dialog. `whitelist` (or `all`) is asked about approval and required
fields; `users` and `federation` are not, and are told *why* in a sentence. Every scope now lists
the endpoints it actually opens, because a scope is a word and the list is the thing the word buys.
A `users` key — the one the sign-in bridge runs on — says whether the bridge is on, so nobody makes
a key and then discovers every `v1/auth/*` call answering 503.

The two submission settings are not merely hidden for the other scopes: they are left out of the
request, and `api_client_update` treats a missing field as "leave it alone", so editing a federation
key cannot silently rewrite settings its dialog never showed. The required-fields list also grew a
row that is not a choice — the magnet or hash, ticked and locked — because "these three are what an
item needs" and "these three are what you are adding on top" are different sentences.

**Readability.** The hints under those controls were Bootstrap's own `.form-text` grey on a
near-black dialog: about 2.4:1, which is legible on the machine it was written on and nowhere else.
Each one is the sentence that says what the control above it does to a partner's traffic, so they
now clear 4.5:1 — and the browser check computes the ratio rather than trusting the eye.

### Fixed — the review queue never said it had anything in it

1.42.0 added a Review filter to the whitelist toolbar and nothing anywhere that said there was
something to filter for. A queue nobody is told about is a queue nobody works. The toolbar now
carries **"N waiting for review"** when N is not zero — counted over the whole table rather than the
current page or filter, served by `idx_wl_review` — and pressing it applies the filter, so the count
and the way to act on it are one control. It is hidden at zero rather than showing a reassuring
"0 waiting".

### Fixed — a test suite could report a failure with nothing failing

`config/login_attempts.json` is the panel's brute-force lockout and it is a *file*: it survives
every `TRUNCATE` and every bootstrap. `deploy/smoke_admin.py` fails a few sign-ins on purpose, so by
the time the battery reached `tests/twofa_login_test.py` that suite could not sign in at all — it
printed `31 checks, 0 failed` and exited 1, and the battery counted a failed suite with not one FAIL
line in the log. The two Python suites that sign in now clear the file at their start, the way they
already clear the 2FA state; the lockout's own behaviour keeps its own tests.

## [1.42.0] — 2026-09-10

### Added — partner submissions a person approves (schema 48)

A key that registers hashes used to be all or nothing: whatever the partner sent, the tracker
served, the moment it arrived. Two things per key now say otherwise.

**Approval.** `api_clients.auto_approve` — on, the partner publishes straight to the tracker, which
is what every key that exists today does and why the column defaults to `1`; off, everything they
send lands in the whitelist as `review_status = 'pending'` and **the accesslist generator does not
write it**. That last clause is the feature: "hold this partner's submissions for review" means
nothing at all if the tracker is already serving them while somebody decides. `tests/partner_api_test.php`
proves it against the real generator and a real file on disk rather than by grepping the query.

The column defaults to `'none'`, not `'approved'`. Every row that existed before this release was
published directly, and calling that "approved" would claim a person looked at 159 rows nobody ever
looked at. The generator's filter is `review_status IN ('none','approved')`, so switching the
feature on unpublishes nothing.

**Required fields.** `api_clients.required_fields` — a comma list drawn from a fixed vocabulary
(`name`, `url`, `source_id`). An item missing one is refused **on its own**, as `invalid` with
`missing_<field>`; the rest of the batch still goes through. A feed that arrives without a title
becomes a row nobody can identify, and refusing it at the door is cheaper than deleting it later.

**The queue.** The Whitelist page gained a review filter (waiting / approved / turned down / not
reviewed), Approve and Turn-down buttons over a selection, and — the thing the queue is useless
without — **the partner's name against every row they sent**, resolved in one query for the page.
Approving regenerates the accesslist; turning something down never deletes it, because a decision
worth recording is worth being able to see later. Behind `panel.whitelist.content`, the same
permission as approving a description.

**The guide is the configuration.** `?action=apidocs` renders from its query string: scope, approval
mode, required fields. The panel builds that address when the key is made, beside the key itself, and
the address changes as the choices change so an operator can watch what they are about to send. It
carries no secret — only which answers were chosen — so it travels in the same mail as the key
without being the key. It is unlisted (`noindex`) rather than locked: a partner cannot read how to
use the thing until they have already worked out how to use it.

The key editor replaced a chain of one-question prompts. Four answers about one key belong on one
screen, and the live guide link at the bottom only means anything if you can watch it change while
you decide.

### Added — the sign-in bridge: one community, two sites, one account (schema 49)

A tracker usually sits next to a forum, and the forum is where the community already is. Five
endpoints on the `users` scope let the two sides share their people, **both ways**:

| | |
|---|---|
| `v1/auth/login` | find, link or create the account behind one of their users, and mint the ticket that signs them in |
| `v1/auth/logout` | they signed out over there — end the bridged session here |
| `v1/auth/verify` | redeem a ticket **this** tracker minted, and learn whose it is |
| `v1/auth/merge` | attach one of their users to an account that already exists here, or detach them |
| `v1/auth/status` | linked or not, when they last came through, whether they signed out here |

**Why a ticket and not a session.** Nothing about a session can be done from a server-to-server
call: the cookie belongs to the browser, and the browser has to visit us to receive one. So
`v1/auth/login` returns a one-time `handoff.url`; the partner redirects the browser to it, and
`?action=bridge` spends the ticket and sets a real cookie. Only the SHA-256 of a ticket is stored,
for the same reason as a password, and redemption is a single `UPDATE … WHERE used_at IS NULL`, so
two browsers racing the same ticket cannot both win. It lives 120 seconds by default.

The outbound half is the mirror image: `?action=bridge_out` mints a ticket for somebody already
signed in here and sends them to `auth_bridge_return_url` with `?thx_token=…`, which the partner's
**server** posts to `v1/auth/verify`. A ticket minted for one key is not another key's to spend.

**It does not match people by email address.** A key that can assert an address can assert the
administrator's, and "sign me in as whoever owns this mailbox" is account takeover with a helpful
face. `auth_bridge_merge` ships `none`; `email_verified` is offered for the install that actually
has that shape — one community, two logins, every address already confirmed *here* — and even then a
second identity reaching for an account somebody already holds is refused as `merge_ambiguous`
rather than resolved by guessing.

**It does not open the admin panel.** The ordinary login form opens a panel session for an
admin-group member because it has just checked their password; this has checked a partner's key. A
forum that can say "this browser is user 7" would otherwise be a forum that can open the
administrator's panel, and the operator who handed out that key was agreeing to let it sign members
in. An admin arriving through the bridge is signed in to the site and signs in to the panel the
usual way.

**Two-way sign-out without an outbound call.** A webhook to an address out of a settings field is
this server fetching whatever that field points at. Instead the far side's sign-out marks the link,
and `currentUser()` reads that — only for sessions that were opened through the bridge, so nobody
else's request grows a query. Signing out here marks it the other way, and the partner sees it on
their next `v1/auth/status`.

**It shows the source.** The account page tells the person themselves where their account signs in
from, and says plainly that an account with its own password signs in here only. The panel's user
list carries the same answer beside each name, dimmed when the link is dormant. The label is copied
at link time, so a profile still says where an account came from after the key is deleted.

The bridge never accepts or hands out a password. An account it creates keeps an unusable hash until
the person sets one through the ordinary reset flow — which is why they can still sign in here if
the forum disappears. Seven settings, all under Settings → Sign-in bridge, and the master switch
ships **off**: it lets a key holder say who a visitor is, which is the strongest sentence any
credential on this site can utter.

### Added — favourites, public profiles and "my torrents" (schema 47)

A member can keep a list of favourite torrents, show it on a public profile at
`?action=u&name=…`, and — where the tracker takes submissions — show the torrents they registered
there too. Everything ships **off**: a list of what somebody likes is a list about them.

**The privacy rule, once.** Somebody appears on a list about themselves only when every gate above
them says yes: the site allows public favourites, the site allows the "who has this" list, their
group holds `favourites.public`, their own list is public, and they have not asked to be left off
other people's lists. Any single no removes them from the rows **and from the count** — never
"14 people have this, 3 shown". That difference is stable and cumulative, so anyone polling the
counter would learn the exact moment a hidden person favourited something. A count that can be
differenced is a list of hidden names, written slowly.

**Two flags, not one.** `fav_public` answers "may a stranger read my list on my profile"; `fav_listed`
answers "may my name appear on somebody else's torrent page". Saying yes to one has never been
saying yes to the other.

**One shape of 404 for every no** — a wrong name, no such account, a suspended one, a hidden list,
the feature off, the permission missing. Registration already tells anybody that a name is taken, so
this is not about keeping that secret; it is about not handing out a cheap, scriptable way to tell
*hidden* from *nonexistent*.

Deliberately absent: no denormalised counter on catalogue rows (a vote carries no privacy and a
favourite does, so an honest count would have to be recomputed on every checkbox and every group
edit — a counter that lags is a leak that lags), no background job, and nothing new in the
per-request janitor, which already runs four.

The queries are the small-side-first pattern this file has been burned by ignoring: the user's own
hashes first, bounded by `fav_max_per_user`, then one literal `IN()` per chunk for the metadata.
Never `FROM index_hashes JOIN user_favourites` with a `MATCH()` on it — that is the plan behind the
twenty-four-minute outage.

A favourite **outlives** the catalogue row. The janitor prunes `index_hashes`; deleting favourites
with it would quietly empty people's lists, and a hash alone still builds a working magnet. A row
with no catalogue entry renders as "no longer in the catalogue", with its hash and a way to remove
it. A banned hash renders without a magnet.

Six settings (`fav_enabled`, `fav_max_per_user`, `fav_public_enabled`, `fav_who_enabled`,
`profiles_enabled`, `wl_submitter_public`) and four permissions (`favourites.use`,
`favourites.public`, `favourites.view_others`, `uploads.public`). **Guest gets none of them**:
profiles are for signed-in readers, so an anonymous visitor sees what they see with the account
system switched off.

### Fixed — a deleted account's votes went on counting

`api/admin/user_delete.php` listed its tables inline, so every new table holding a `user_id` had to
be remembered *there*. It was not: `hash_votes` keys its voter as `(voter_type='user',
voter_key=<id>)`, and a deleted account's votes stayed in the table and kept counting towards every
score they had touched. One `userDeleteCascade()` now, with one place to add the next table — and a
test that fails on the code that shipped before it. Submissions are **not** deleted (a whitelist row
is a torrent the tracker serves) but they stop being attributed.

## [1.41.0] — 2026-09-09

### Added — the panel has a footer, and the build has a name

Until now the version of a running tracker existed in exactly two places, neither of which the
running code could see: a heading in `CHANGELOG.md` and a git tag. "Which build is this server on?"
had no answer from the server. There is a `TRACKER_VERSION` constant now, and
`tests/version_test.php` fails if it and the changelog heading ever disagree — a constant that
drifts from the changelog is worse than no constant at all.

The footer was written inline in `templates/layout.php`, which no panel page includes, so the panel
had none at all. It moved to `templates/footer.php` and both sides include it: the public layout
inside `.container`, every panel page after `.admin-container` closes. Same lines, panel spacing.

New setting **`version_display`** (Settings → Footer): *in the panel only* (the default), *on the
public pages only*, *everywhere*, or *nowhere*. The panel by default because that is who needs it —
a version number on a public page mostly tells a visitor which published bugs to try. An
unrecognised value falls back to the default rather than to "everywhere", which is the direction a
row restored from a backup should fail in.

### Added — the catalogue search has a time limit somebody can see

php-fpm's `max_execution_time` is 30 s on the live deployment. A search that crossed it was killed
mid-query and came back as a bare 500 — nothing in the Apache error log, nothing in the fpm log,
because `error_log` is unset there and `catch_workers_output` is off. Finding it at all meant
reading `$9 == 500` out of the **access** log.

`api/index_search.php` now asks for its own limit — **`search_time_budget`**, 60 s by default,
clamped to 10–300 — set after `session_write_close()` so a long search never holds the session lock
while it runs. It is a setting rather than a constant because a catalogue of three million rows is
a different machine from one of three thousand.

### Fixed — the same OR, in the panel's own listing

1.40.0 split `MATCH(name) AGAINST(…) OR info_hash IN (…)` into two UNIONed branches for the public
search, measured 32.6 s → 4.3 s, and left the identical clause in `indexListSelect()` — the Index
page's own "search inside file lists". Same trap, same rewrite, and now its own tests: four sort
orders, a row that matches by name, one by file, one by both and one by neither, each returned
exactly once.

### Fixed — the description filter appeared where its own workflow was shut

Narrower than 1.40.0 made it. Every state `#search-content` offers is `content_status`, which lives
only on the whitelist table — and `api/whitelist_submit.php` refuses every submission outside
whitelist mode and the schedule, so on a blacklist-mode tracker that column can never leave `none`
and the control is decoration in front of a filter that can only empty the page. Measured on the
live site while fixing it: 159 whitelist rows, all `none`, zero descriptions, zero source links. It
now needs all three — whitelist rows in this reader's results, descriptions or source links allowed,
and the submission path actually open.

## [1.40.0] — 2026-09-09

### Fixed — "search inside file lists" without "best match first" returned a 500

Reported from the live site: search `shrek`, untick **Best match first**, tick **also search file
names** — and the request often failed. Measured on the live catalogue rather than guessed at:

| shape | time |
|---|---|
| name only, best match first | 7.7 s |
| name only, sorted by seeders | 2.3 s |
| **+ file names, best match first** | **16.0 s** |
| **+ file names, sorted by seeders** | **32.6 s** |

php-fpm's `max_execution_time` on that machine is **30 s**. That is the whole story of the
intermittency: with relevance the query came in under the limit, without it, it did not.

The cause was a clause this file had already been burned by once. Resolving the file half into a
bounded `IN` list fixed the twenty-four-minute correlated subquery, but what was left —
`MATCH(name) AGAINST(…) OR info_hash IN (…)` — is still a disjunction across two different indexes,
and MariaDB cannot serve one from either. With `ORDER BY eff_seeders` it then chose to walk
`idx_index_eff_seed` in order and filter as it went: `EXPLAIN` said `type=index
key=idx_index_eff_seed`, i.e. an ordered scan of three and a half million rows to return
eighty-eight.

So the OR is split into two branches and `UNION`ed. Each branch is served from exactly one index —
the fulltext one, or the primary key — and the sort happens over what they returned. Same rows,
same order, same totals; **32.6 s → 4.3 s** on the same live data. `UNION` and not `UNION ALL`,
because a torrent whose name matches *and* whose files match is one result, which is what the OR
said. The arm comes back already wrapped in a derived table, so its caller can go on appending one
`ORDER BY` and one `LIMIT` whichever shape it turned out to be — and the "prefer the whitelist row"
exclusion moved inside the arm builder, because appending it to finished SQL would have attached it
to the last branch of a union only.

`tests/index_test.php` now seeds a row that matches by name, one that matches by file, one that
matches both and one that matches neither, and asserts across five sort orders that exactly three
come back, once each, with the total agreeing — and, with both arms in play, that a hash sitting in
both tables is still returned once and still as the whitelist row.

The same `OR` shape remains in the panel's own index listing (`indexListRows`). It is not a
regression and it is behind the admin login, but it is the same trap and it is written down.

### Fixed — the description filter appeared where it could only empty the page

`#search-content` was rendered whenever the operator allowed descriptions or source links, without
asking whether this reader sees whitelist rows at all. `content_status` exists only on the whitelist
table, so with no whitelist arm the control was not merely decorative: picking "Reviewed and
published" makes `indexSearchCatalogue()` add `1 = 0` to the index arm and the results go blank. It
now needs both — somewhere for a description to live, and whitelist rows in this reader's results.

### Fixed — a second review pass over 1.40.0's own changes

Eighteen more findings, all from the same adversarial method applied to the fixes themselves:

* **The Info panel was rendered only for readers with `index.files`**, which made
  `?action=search&hash=…` a silent no-op for everybody else — the element is missing, so the link
  did nothing at all. It needs what `api/index_info.php` needs, which is `index.view`.
* **A `?page=` past the end** rendered an empty table under a result count, with no message and an
  address that never corrected itself. It now lands on the last page that exists.
* **The public search page never listened for `langswap`**, so after an in-place language switch the
  result count, the file chips, the pager and an open Info panel all stayed in the old language —
  every one of them a node this page drew itself, which the swap skips by design. Same for the
  settings breadcrumb's own button.
* **Back and Forward move the address too**, and the revealed link box is cleared on those as well —
  it was only cleared by the code that writes an address, which history navigation never goes
  through.
* **The switcher link is refreshed on more than `click`** — a middle click, "Open link in a new tab"
  and Enter on a focused link all follow the `href` without ever firing one.
* **`?sort=` now round-trips exactly.** "Best match first" off with no column sort wrote
  `seeders:desc`, the request-level fallback, which read back as an explicit Seeders sort with an
  arrow on the column. The address has its own word for that state now.
* Toggling "Best match first" pushes a history entry like the column sorts it belongs with; a
  successful swap no longer leaves a stale scroll position in `sessionStorage` for the next reload to
  restore; the Share button's flash no longer writes back a label captured before a language switch.
* The smoke test for the whitelist visibility gate **took the permission from the wrong group** — it
  edited `guest` while the account under test holds `member` and `vip`, so it was passing on whatever
  a previous run had left behind. It now strips and restores the groups the user actually holds.

### Added — the search view has an address, and a button that hands it to you

The whole state of the search page lived in one JavaScript closure: the query, the sort stack, the
page number, whether the Info panel was open. Reloading lost all of it, and there was no way to send
anybody what you were looking at. It now lives in the address — `search`, `search_files`, `content`,
`sort`, `page`, `per_page`, `hash` — under the API's own parameter names, so an address from the
page can be pasted straight onto `api.php` when something needs debugging.

A parameter equal to its default is left out, which is what keeps an ordinary search short enough to
read. **`per_page` is the one exception: whenever `page` is written, `per_page` goes with it.**
Without that, a link to "page 3" shows a different set of rows to a reader whose page size is not the
sender's — one address meaning two things. A `per_page` that arrives in the address governs that
load only and never overwrites the reader's saved preference.

Turning a page, changing the sort, and opening or closing the Info panel are `pushState`, so Back
walks the views you actually moved between; typing is `replaceState`, so it does not fill the history
with every keystroke. A sort the page does not recognise is refused **whole** rather than
half-applied: an address typed by hand, or written by an older version of this page, either means
what it says or falls back to the default.

`?action=search&hash=<40 hex>` opens the Info panel for one torrent on a cold load, over the results
it belongs to. A hash that is not in the catalogue — or that the reader may not see — gets a reason
in the panel rather than an empty box.

Two **Share** buttons (setting `search_share_enabled`, on by default): one on the toolbar for the
view as it stands, one in the Info panel's head for that single torrent. The panel's link
deliberately does **not** carry the sender's query and page number. `navigator.clipboard` does not
exist on plain HTTP — it is a secure-context API — so where there is nothing to copy with, the link
appears in a read-only box, already selected, rather than the button doing nothing and saying
nothing.

In the panel, the Index and Whitelist detail modals answer to `?action=…&hash=<40 hex>` too, with a
**Copy link** button in the modal header. Those use `replaceState`, not `pushState`: the panel is a
workplace with polls and forms, and a history entry per row glanced at would turn Back into a tour of
the last thirty rows. `api/admin/whitelist_item.php` takes `hash=` beside `id=`, because a link should
name the torrent and not the row number it happens to have in one installation's table.

### Fixed — the Info panel described whitelist rows to readers who could not see them

`api/index_info.php` gated only the `whitelisted` flag and then served the row's name, size, file
count, swarm counts, source link and description to anybody who could type the hash. A whitelisted
hash is removed from `index_hashes`, so for such a hash that row is the whole answer — while
`indexSearchCatalogue()` leaves those rows out of the results and `api/index_files.php` refuses the
file list. Three endpoints reaching the same row, and one of them disagreeing.

Two more holes in the same file came out of the review that followed:

* **The refresh arm never asked the question at all.** `POST {op:"refresh"}` sat above the row lookup,
  so it answered 200 with live seeders and leechers for a hash whose `GET` answered 404 — and ran
  `UPDATE whitelist SET scrape_* …` against a row the caller was not allowed to read. A write with no
  read permission, on columns that are load-bearing for the readers who do have it: `scrape_seeders`
  is the whitelist arm's sort key and `scraped_at` is its `last_seen`. The gate now sits above both
  methods, and the refresh writes nothing into a row it would not have shown.
* **Banned rows were served.** The whitelist lookup had no `banned = 0`, which every other reader of
  that table applies. A banned hash is deleted out of `index_hashes` on the next poll, so the banned
  row was, again, the whole answer — a full description of a torrent this tracker refuses to serve.

On the live install it was **latent rather than exploited**: reaching it needs `index.view` without
`whitelist.view`, and no group there has that pair — `guest` holds neither and `member` holds both,
so anonymous callers were refused at the first gate and members were entitled to what they saw. One
group edit, or turning `index_search_include_whitelist` off, would have opened it for everyone.
(`search_allow_sl_refresh` is `0` there, so the refresh arm was unreachable too.)

Proven where it has to be proven: `deploy/smoke_users.py` exercises all of it over HTTP with a real
signed-in member, because `userCan()` returns true for any panel session, so the same request made as
the owner would have passed whatever the code said. The test also puts the permission back and
repeats the request, so a green line means the gate works rather than that the fixture was broken.

### Fixed — findings from the review of the two features above

An adversarial pass over this release's own diff raised eighty findings; fifteen survived
verification. Beyond the three above:

* `api/admin/whitelist_item.php` grew a `hash=` branch, but the file-list query one screen below
  still bound the **request's** `id` — which on that path is the `0` that `(int)($_GET['id'] ?? 0)`
  produced, because the branch is entered precisely when `id < 1`. `WHERE whitelist_id = 0` matches
  nothing, so every hash-addressed modal showed an empty list and the sentence "Single-file torrent
  or no file list stored" under a stat strip reading "3 files". It binds the id of the row that was
  **found** now. Reached without anyone using Share: the modal writes `?hash=` into the address on
  every open, so a plain refresh went through that path.
* The language switcher's `href` is rendered from `$_GET` — the address as it was when the page was
  **built**. Once the search page started keeping its state there, clicking EN/PL threw the view
  away: a reader who had typed a query, sorted it and turned to page three landed on a blank search.
  `lang-swap.js` now rebuilds that link at click time, which fixes both the in-place swap and the
  plain navigation it falls back to.
* `.share-url` carried its own `display`, and an author-origin `display` beats the user agent's
  `[hidden] { display: none }` — so `box.hidden = true` was a statement that did nothing, and a
  pre-selected link to a page the reader had left stayed on screen, in the Info panel over a
  different torrent. The stylesheet already warns about exactly this six lines further down.
* The revealed link box is now dropped whenever the address moves — including Back and Forward,
  which do not go through the code that writes one — the Share button is hidden on
  every path that leaves no results (not only the one that draws rows), and the button's label is
  read fresh on each flash instead of being cached — a cached "Share" written back a second and a
  half later put an English word on an otherwise Polish page.
* `openDetails()` wrote `?hash=` back into the address **after** its request returned, with no check
  that the modal was still open. Closing it during the live scrape left a hash nothing would ever
  remove, so the next refresh reopened a modal nobody asked for. The guard is an explicit flag and
  not `classList.contains('show')`: Bootstrap adds that class a backdrop transition after
  `modal.show()` returns, so a fast reply would have found it absent and concluded the modal was gone.
* The Copy-link button's `ms-auto` fought the close button's own auto margin — two auto margins on a
  flex line split the free space between them, parking it mid-header — and it kept its old label
  after an in-place language switch, being a script-made node the swap deliberately skips.

### Added — the language switcher rewrites the page instead of reloading it

Clicking EN/PL fetched the same address again and the browser threw the page away and built it back,
which is why the switch felt like a jolt: a white flash, a scroll position that landed somewhere near
where you had been, and every open panel, filter and half-typed field gone. The switcher now fetches
the same page in the other language and rewrites the text where it stands. Nothing is thrown away,
so nothing has to be rebuilt.

One request buys all three things the swap needs: the language cookie `langInit()` sets, the new
`js.*` bundle `t()` reads, and a render that a reload would have cost anyway.

The rules it works by are each a bug that happened or would have:

* **The whole plan is built before a single node is touched.** A plan that could not be built is a
  plan that was not applied — the click falls back to a plain navigation, which always works.
* **Children are matched by `id` first, and only then by position.** The Settings search physically
  moves sections with `insertBefore`, so while a query is active the live order is a permutation of
  the render's; position means nothing there and `id` means everything.
* **The positional pass is a longest-common-subsequence alignment, not "the next free node of the
  same tag".** The first version was the latter and it was wrong in a way that mattered: the search's
  breadcrumb `<div>`, which only exists in the live page, claimed the first `<div>` of the render and
  every cell after it took its neighbour's text. A field ended up labelled with the label of the field
  below it. Wrong text under the right control is worse than no swap at all, so the alignment has to
  be able to say "this live node has no counterpart" — and a subsequence match is what says it.
* **Nothing the reader typed is touched.** No `<textarea>` contents, no `<input>` value except the
  label on a button. `<option>` labels are translated while the `<select>`'s choice is left alone.
* **Attributes count as text**: `title`, `placeholder`, `aria-label`, `alt` and the `data-title` the
  Settings search reads.

The Settings search is **re-indexed, not restarted**, afterwards. `makeItem()` copies every label and
hint into strings when the page starts, so after a swap those copies held the old language and a
Polish word found nothing while the Polish words were on the screen. Restarting the module instead
would leave a second set of its document anchors behind and bind every listener twice.

Known and deliberate: the ~54 messages that `templates/admin/settings.php`, `adminlogin.php` and
`unsubscribe.php` freeze into an inline `<script>` with `json_encode(__(...))` are not in the `js.*`
bundle and are not swapped. They stay in the language they were rendered in until the next full load.

New setting **`lang_swap_enabled`** (Settings → Interface languages), shipping **off** for one
release. Off, the switcher does exactly what it did before — which is also what happens with
JavaScript disabled, on a network error, and when the panel session has expired.

### Fixed — keeping your place across a language switch, by anchor instead of by pixel

The place-keeper added in 1.37.0 stored `window.scrollY` and put it back at `DOMContentLoaded`, then
again after 350 ms and 900 ms. The page is still growing at those moments — the Settings search has
not filtered yet, the panel's tables are still empty — so the pixel it restored was no longer the
pixel you had been looking at. That was the "shift" left over from the last release.

It now remembers the **element** nearest the top of the window and how far below the top edge it sat,
and keeps nudging the scroll until that element is back there and the page has stopped changing
height (a `ResizeObserver` on `<body>`, with a 2.5 s deadline).

The bug that made the first attempt look like it did nothing at all: **a sticky element can never say
where you were.** The Settings toolbar is `position: sticky` at the very top, so it was the "nearest
the top" candidate on every single capture — and putting a pinned element back where it already is
scrolls nowhere. Candidates whose own position, or any ancestor's, is `sticky` or `fixed` are now
skipped.

This half is **not** conditional on the new setting: a full reload is still what happens on every
fallback path, so the floor has to hold on its own.

The code moved from `assets/js/admin-common.js` — which public pages never load, though they have a
switcher too — into the new `assets/js/lang-swap.js`, loaded by `langJsBridge()` on every page that
carries the i18n bundle.

## [1.39.0] — 2026-09-08

### Fixed — this deployment is Apache, and three comments said nginx

Found by checking the live response instead of trusting the tree: `Server: Apache/2.4.68 (Debian)`,
`apache2` owning port 443, `nginx` **inactive**, `mod_headers` loaded and `AllowOverride All` on the
vhost. So `.htaccess` **is read here** — and the security headers it ships, which two comments in
this repository described as doing nothing on this deployment, have been in force all along.

Nothing behaves differently as a result; the reasoning in `.htaccess` was right even though its
premise was wrong, and `Header setifempty` does exactly what it says. What changes is what the code
claims: the comments now say Apache, the diagnostic on the Settings page asks `SERVER_SOFTWARE`
rather than assuming, and the "the server is not telling PHP about the TLS" warning no longer offers
nginx-only advice for a symptom that on Apache means something else entirely — `mod_ssl` sets
`HTTPS=on` itself, so seeing that warning on Apache means TLS is being terminated somewhere in front.

On the live site the two policy headers this produces are the documented pair and not a mistake: the
enforcing fallback from `.htaccess`, and PHP's report-only policy carrying the per-request nonce.
They have different names and different jobs, and switching `csp_mode` to *enforce* replaces the
fallback rather than adding to it.


### Fixed — two tests that could not fail, and a suite that had been dying unnoticed

`deploy/smoke_admin.py` had been **crashing part way through for two releases** and reading as a
pass. The battery ran the smoke suites *after* the PHP suites, and those truncate the catalogue
tables the smoke opens against, so it died on an `IndexError` before printing its result line — and
because the runner grepped for a `FAILS: n` line that a crash never prints, the *next* suite's line
slid up into its column. Behind that, two of its saves had been failing since the re-auth work in
1.36.0: `tracker_mode_switch_cmd` is a re-auth key, and clearing it is a change, so those saves had
been answering 403 and nobody saw it. The suites now run before the stateful tests, a crash is
reported as a crash with its exit code, and the two saves carry the owner password.

`tests/index_test.php`'s cost checks — the ones that guard the counting fix in 1.38.0 — **inherited
three settings from whatever ran before them**. With a shorter poll interval left in the database,
"an ordinary minute with no poll" became a tick that polled, and the poll's own reads were charged
to the count under test. Found with a probe rather than by reading: `indexPollDue()` answered DUE and
the tick came back having pruned 132 rows. The scenario now pins every branch of the tick that can
walk the table, and the suite passes both immediately after a bootstrap and after the smokes.



### Added — a Content-Security-Policy with a per-request nonce, sent by the application

The policy lived in one line of `.htaccess`, **and production is nginx, which never reads that
file.** Unless the operator had hand-copied it into the server block — the README said to; nothing
reminded anyone — the live site was serving no policy at all. It is built per request in
`includes/csp.php` now and sent by PHP, which fixes that on every web server at once and is also the
only place a **nonce** can come from: a nonce must change on every response, and a static
`add_header` cannot mint one.

Every inline `<script>` the application emits carries that request's nonce — `nonceAttr()` returns
the whole attribute, so `<script<?= nonceAttr() ?>>` is one token to add and impossible to half-do —
and `script-src` has **no `'unsafe-inline'` and no `'unsafe-eval'`**. An injected `<script>` has no
nonce, so it is inert text.

Four settings under *Settings → Security → Content-Security-Policy*: the mode
(**report-only by default**, enforce, off), whether to collect violation reports (**off by
default**), how many kinds of violation to keep, and extra allowed hosts. The section prints the
policy this very request would send, for both scopes, byte for byte — four dropdowns do not tell
anybody which hosts the CAPTCHA provider dragged in.

What is deliberately NOT claimed: `style-src` keeps `'unsafe-inline'` and will until
`includes/richtext.php` stops building `style="…"` out of author BBCode and the ~100 `style=""`
attributes in the templates move into stylesheets, and `img-src` keeps `https:` so images in
descriptions still load. This blocks injected *scripts*, not injected styling, and the settings page
says so where the switch is.

The panel and the public site get **different** policies. The panel adds jsDelivr to `script-src`
(Bootstrap's bundle) and `frame-ancestors 'none'`; a public page gets neither, because it loads only
icons and fonts from that CDN — allowing a third-party origin to run scripts for every anonymous
visitor when nothing there needs it is a cost with no purchase. A JSON response gets
`default-src 'none'`. And the CAPTCHA hosts are now **only the provider that is configured**: the
static list allowed all four on every install, because a file cannot read a setting.

**On upgrade, nothing changes for a visitor.** The shipped mode is report-only, whose header name is
different from the enforcing one, so an Apache install keeps its old enforcing policy throughout —
`.htaccess` says `Header setifempty` now rather than `Header set`, which is what stops mod_headers
from *replacing* the header PHP just sent and quietly making this whole feature decorative. nginx had
nothing to lose and now has a policy.

### Added — a bounded, public violation-report sink, and a panel view of it

*Collect violation reports* adds `report-uri` to the policy; `csp-report.php` stores what browsers
send. It is **off by default**, because report-only still reports, and the day a policy is switched
on every browser extension that injects a script starts POSTing about it into a MariaDB shared with
a mail server, a forum and a file host.

The endpoint is a top-level file rather than an api.php endpoint, on purpose: `api.php` starts a
session, loads thirty includes and runs four janitors, and none of that is needed to store six short
strings. `deploy/deploy.py`'s top-level include list names it in the same commit — that manifest is
an allow-list, and a `report-uri` pointing at a path with no file behind it is worse than no
reporting at all, because the rewrite then falls through to `index.php`, which renders the whole
front page for every violation POST from every visitor.

Method, content type and length are checked before the database is touched. Firefox's
`{"csp-report":…}` and Chrome's `[{"type":"csp-violation","body":…}]` normalise to the same row, so
one problem seen in two browsers is one problem. Only the **origin** of a blocked URL is kept (a
blocked-uri routinely carries a token in its query) and only the `?action=` of the page it happened
on. Extension schemes and reports about other people's sites are dropped before storage.

The row count is bounded **against a hostile client**, not merely against an honest browser: the
blocked origin comes out of the POST body, so aggregation alone would let anyone create rows at
will. The counter of an existing row always moves; a *new* kind is refused once the table holds
`csp_report_keep_rows` rows. The janitor trims to the same ceiling once a minute. Clearing the list
is `csp.clear` in the audit log.

**Schema 46** adds `csp_reports` and the four settings.

### Changed — one answer to "was this request HTTPS", instead of five copies of a guess

`session_start()` was the first statement in `index.php`, `api.php` and `install.php`, and each
computed its own copy of `!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'` — five copies of
one expression, in five files, none of them aware of a proxy.

**On this deployment they were producing the right answer, and that was checked before any of this
was written.** Measured on the production host on 2026-09-08: PHP sees `HTTPS='on'` and
`REQUEST_SCHEME='https'` (nginx's stock `fastcgi_params` passes both), and the live response already
carried `Set-Cookie: PHPSESSID=…; secure; HttpOnly; SameSite=Lax`. So this is not a fix for an
outage that happened; it is the removal of a condition that was true here by luck of a stock include
file and false the moment anything sits in front of the web server. The expression sees only the
connection nginx made, so on any deployment where TLS is terminated a hop earlier — Cloudflare, a
load balancer, a tunnel — the same five copies quietly compute `false` for a site that is entirely
HTTPS, and every cookie loses its `Secure` flag with nothing to show for it in the panel.

There is now **one** function that answers "was this request HTTPS", and one that decides the flag
for all four cookies. It reads, in order: TLS to this server, `REQUEST_SCHEME`, and then —
**only when the request arrived from an address inside Trusted proxy IPs** — `X-Forwarded-Proto`,
`X-Forwarded-SSL` and RFC 7239 `Forwarded`. The local connection is asked first, so a proxy header
can only ever *add* HTTPS and never strip it from a connection that really is encrypted. A
`SERVER_PORT === '443'` heuristic was considered and deliberately left out: it is the one signal
that could claim HTTPS for a plain-HTTP request, and a false positive here locks the operator out.

`session_start()` therefore moved **down** in both entry points, to immediately after
`ensureSchema()`, because the new `cookie_secure_mode` setting lives in the database. Verified before
moving it: nothing under `includes/` touches `$_SESSION` at file scope, and both database-down
branches (the maintenance page, the API's 503) use neither a session nor a CSRF token. Nothing
inserted above that line may read `$_SESSION`, and a `headers_sent()` check beside it logs the one
new failure this creates — a byte of output before the session starts.

Two smaller disagreements went with it: the remember-me **delete** cookie carried no `secure` key
while the issue set one, and `logout()` used the seven-argument `setcookie()`, whose signature has
no SameSite parameter, so the deletion cookie silently lost the `SameSite=Lax` the session was
issued with.

### Added — CIDR ranges in Trusted proxy IPs

`trusted_proxy_ips` was compared with `in_array()`, so a CIDR typed into that box **matched nothing**
and every visitor kept being counted as the proxy. Cloudflare publishes about thirty ranges and no
single addresses, so the feature could not be configured for the deployment it exists for. There is
now one matcher and one list parser in `includes/functions.php` — IPv4 and IPv6, families that never
cross, `::ffff:` addresses unmapped on both sides, and an out-of-range prefix (`10.0.0.0/33`)
matching nothing rather than reading past the end of a four-byte address. Commas, spaces and new
lines all separate entries; a list pasted one per line used to produce entries with embedded
newlines that matched nothing while looking correct in the field.

`includes/api_auth.php`'s never-ban list now goes through the same matcher, which fixes a real bug
rather than merely removing a duplicate: its inline copy did `$bits = (int)$bits` with no range
check, so an entry of `10.0.0.0/999` reached `ord($bin[124])` on four bytes.

**On upgrade:** an installation that already holds a CIDR in that box has been running with it
inert. Two rules are now applied on **every request**, not only when Settings is saved, because a
save-time check would arrive after the first request of the deploy: a malformed entry is ignored,
and so is a block wider than `/8` (IPv4) or `/16` (IPv6) — `0.0.0.0/0` there would make every rate
limit, login lockout, API ban and reputation bucket spoofable with one request header. Settings
names the entries it is ignoring. A *narrow* stored range starts being honoured, which is the fix:
that installation stops treating all visitors as one host, so rate-limit and lockout counters
change shape once, from shared to per-visitor.

### Added — Secure cookie policy and HSTS, in Settings (schema 44 → 45)

Six settings, all in *Security → Transport security*:

* **`cookie_secure_mode`** — `auto` (the shipped default and what every upgrade gets), `always`,
  `never`. `always` is a total lockout on a panel really served over plain HTTP: the browser refuses
  the session cookie, so every sign-in succeeds and the next click returns to the form, because the
  CSRF token minted at sign-in is never the one checked at submit. It can only be saved from a
  request that already is HTTPS, it is on the owner-password list, and the recovery — a single
  `UPDATE settings SET value = 'auto' WHERE \`key\` = 'cookie_secure_mode';` — is printed in the
  field's own help text, because whoever needs it cannot open the panel to go looking for it.
* **`client_proto_header`** — which header a remote proxy uses (default `X-Forwarded-Proto`), read
  only from a trusted peer. Empty switches the header path off entirely.
* **`hsts_enabled` / `hsts_max_age` / `hsts_include_subdomains` / `hsts_preload`** — off, and never
  sent over a plain-HTTP request. `max-age` ships at **one day**, not the recommended year, because
  that is what an operator gets the moment they flip the switch. `max-age=0` with the switch still
  on is the **retraction**; switching the setting off only stops sending the header and leaves
  existing pins running to their own expiry. `preload` is refused unless *includeSubDomains* is on
  and the age is at least a year, and it is on the owner-password list.

The section prints, live for the request rendering it, whether PHP sees this request as HTTPS and
**which** signal said so — and, when detection says plain HTTP while the site address is `https://`,
the two `fastcgi_param` lines that are missing from the vhost. Every refusal added here fires on an
actual **change** and is judged against `$data + $cfg`: the page posts every control on every save,
so a guard written against the value alone would make one bad state block the whole Settings page,
and one written against `$cfg` would refuse the very save that fixes detection.

**Nobody is logged out by any of this.** `Secure` is a rule about sending a cookie, not about
keeping one, and PHP only re-emits the session cookie when it creates or regenerates an id. The
cookies were deliberately **not** renamed to `__Secure-`/`__Host-`: that is the one change here that
really would end every session on deploy, and it buys nothing.

## [1.38.0] — 2026-09-08

### Changed — the catalogue is no longer counted twice a minute

Measured on production on **2026-09-08**, not estimated. `EXPLAIN SELECT COUNT(*) FROM index_hashes`
is an index scan over `idx_index_seeders`, **3 368 887 entries**, *Using index*; one execution takes
**939 ms** (the comment in `includes/index.php` still quoted 557 ms, which was the same query on a
2.7 M-row table — the query did not get slower, the catalogue got bigger). And
`information_schema.INDEX_STATISTICS` showed **6 737 774 rows read on that index in a clean 60 s
window**: exactly two full scans a minute, all day, or about **1.9 s of every minute — 46 minutes a
day** — on a database shared with the mail server, the forum and the file host.

Two call sites were paying it, and neither was deciding anything with the answer most of the time.

The **statistics timeline** counted the catalogue once per stored sample — every 60 s from the
janitor, and inside a php-fpm child whenever the public stats endpoint happened to take the sample
first. It now reads the count cache that `includes/index.php` has had all along, with a five-minute
TTL, so the count runs about twelve times an hour instead of sixty. A cached number is admissible on
a chart here for a specific reason rather than a general one: the total is **constant between
polls** — rows arrive in half-hourly batches and leave in the hourly prune, and every one of those
sites drops the cache — so a repeated value is the same true reading twice, and the first sample
after a change counts afresh and records the change exactly. A TTL at or *below* the sample interval
would have expired before every single sample and saved nothing at all; the suite now asserts that
it is above it.

The **janitor's over-the-cap gate** ran the 939 ms count first and unconditionally, because PHP's
`&&` is left to right and the count sat on the left of the cheap test. It ran during the whole hour
of the stand-down that the prune sets *precisely* to stop repeating fruitless work, and threw the
answer away. The cheap tests come first now, and one more was added: only a **poll** (the only
inserter on this side) or an **operator lowering `index_max_rows`** can put the table over its cap,
and both are visible in the state file for the price of a file read, so the count runs on the tick
after one of those and not otherwise — roughly twice an hour instead of sixty times. It stays the
**exact** count, deliberately: nothing that stands next to a `DELETE` in this file reads a cache.

Three numbers are exact and stay exact, and the suite fails if a future edit routes any of them
through the cache — twice over, once behaviourally (a cache poisoned with an absurd total cannot
inflate a delete or reach the poll history) and once structurally (`indexPoll`, `indexPrune` and
`indexTick` must not so much as name the cached reader, checked through the tokenizer with a
positive control proving the check can still see one where it belongs):

* the prune's own count, which sizes a `DELETE` — a pager may be approximate, a delete may not;
* the tick's gate, one call away from that delete;
* `index_polls.index_rows`, which is *defined* as what the catalogue held **after** the poll. That
  one was cached before this change and is now exact, because the timeline warms the very same cache
  a second or two earlier in the same janitor tick: a cached read there would have written the
  pre-poll total into the poll history and the coverage chart for ever.

**What this costs, stated rather than buried.** Rows written straight into `index_hashes` by the
Python side (`worker/federation.py` cannot call a PHP cache-drop) can be up to five minutes late on
the chart's catalogue line, and they no longer buy an early trim — the hourly prune still enforces
the cap, so what is lost is the promptness of the trim, not the trim. Federation bounds its own
batches, which is why that is acceptable.

No settings, no schema change, no user-visible strings. **No database index was dropped**: whether
`idx_index_seed_seen` still has a consumer is a separate question that needs `userstat` evidence over
a longer window than one reading, and accounting has been turned on for it. One thing was measured
and *not* acted on: the sampler's `WHERE meta_status = 'done'` count is now the last aggregate on
that path that still runs once a sample, and the comment above it claiming it is "a counted lookup,
not a scan" has never been measured. The comment now says so, and says which two queries settle it.

### Added — how a file list loads is now six settings

*"Is there a setting for whether it keeps loading in batches of, say, 2 000, or waits for a button —
and for how big a batch is and how many files at most?"* There is now, in Settings → **File list
loading**: three questions asked twice, once for the public search page and once for the panel's
detail windows. `index_files_mode` / `index_files_batch` / `index_files_max` and
`index_files_admin_mode` / `index_files_admin_batch` / `index_files_admin_max`. Schema 43 → 44
(settings only).

Two audiences and not one, because they are not the same visitor. A reader arrives over the internet
and every page they ask for spends a token from the `idxsearch` bucket the search box uses — a
bucket keyed by **IP address**, so everyone behind one connection shares it, and one whose
accounting rewrites the whole of `config/rate_limits.json` under an exclusive lock on every call.
The operator is past `panel.whitelist.view` on a page that is theirs to wait on and is not
rate-limited at all. One shared number would mean an operator raising the panel's batch raising it
for every stranger at the same moment.

The **mode** is a client behaviour and the **sizes** are server rules. Mode decides only *when* the
browser asks again: keep loading while scrolling (what the search page has always done), wait for a
click, or chain requests up to the maximum. A reply is bounded by the batch whatever the mode says.
The numbers are clamped three times — in `save_settings.php`, again on read in `includes/index.php`
(the clamp that actually holds: a settings row can also arrive from `install.php`, a restored backup
or a MySQL client on a database shared with three other applications), and once more at the
endpoint, which now clamps a caller's own `?limit=` against the operator's batch instead of against
a literal. The defaults reproduce 1.37.0 exactly, so an existing install changes nothing until it is
asked to.

**One rule is new and visible on upgrade:** `index_files_max` ends a public list where nothing ended
it before — a member with `index.files_all` could page a 500 000-file torrent to its last row. The
default and the ceiling are both 20 000, so this box can be lowered and not raised. That is
deliberate: paging stays `LIMIT`/`OFFSET`, because **both** gates on `api/index_files.php` are tests
on `$offset` (the `index.files_all` 403 and the ceiling itself), and a keyset cursor that made the
offset irrelevant would have walked past the permission as well — `after=<id>&offset=0` reads the
whole table. The price of keeping the offset is that the deepest allowed page fetches ~20 000 rows
to answer one request, which is where 20 000 comes from; raising it is a code change, after a
measurement rather than a number typed into a box.

A capped reply says `capped: true` and **`truncated: false`**. The two must never travel together:
the page's loop condition is `truncated && can_more` and its `IntersectionObserver` re-fires
whenever the sentinel is on screen, so a reply claiming both would leave an open tab asking for the
same empty page for ever — against that per-IP bucket and its exclusive lock. The predicate lives in
one place (`indexFilesCapped()`) and all three file-list endpoints call it. In the panel the same
pair had a quieter failure: *Load the whole file list* would have appeared on a list no request can
extend, returning exactly what was already on screen. It is hidden there now and replaced by a line
saying the panel stops at that many.

Two more things this change had to fix rather than merely avoid. The **public file tree had no leaf
cap**: `buildTreePub` created one DOM node per file and the tree was rebuilt from scratch on every
page that arrived, which was survivable only while a stored list could not be longer than a few
thousand paths. It now draws at most 5 000 leaves — the per-torrent storage cap the worker ships
with, so every list that drew in full still does — and says how many were loaded but not painted.
And a **failed page no longer leaves the button stuck on "Loading…"**: the reader can press it
again, while the automatic asking (the scroll observer, and the *everything at once* chain) stops on
the first failure instead of answering a 429 with another request.

Nothing here spends more of the rate limit than before at the defaults: the same batch, the same
number of requests. *Everything at once* is the mode that can, which is why it is not the default and
why its hint says so in both languages.

### Added — how many files a torrent stores is now a panel setting

`meta_max_files` (Settings → **Index file lists**) sets how many file paths the metadata worker
writes for one torrent. Empty — the default, and what every existing install keeps — means "use the
worker's own `max_files`", exactly the contract `meta_worker_concurrency` has had since 1.7.0; a
number between 1 and 50 000 overrides it and reaches the worker within about a minute, no restart
and no root. It rides in the settings query `effective_order()` already runs on a timer, so it costs
no extra round trip. Out of range is **clamped**, never discarded — asking for more must not
silently produce less than the config file was already giving; a non-numeric value is logged and
ignored; an unreadable settings table leaves the last known cap standing, because a database blip is
not an instruction to start truncating file lists.

One number, both queues: `finish()` is shared, so it governs `whitelist_files` and `index_files`
alike, and the torrent's real `files_count` is stored unclipped whatever it says — which is what
lets the pages keep saying *5 000 of 18 000*. It does nothing for index rows while *Keep File Lists*
is No.

**It does not reach lists imported from a federation peer**, and the field says so rather than
leaving it to be discovered. Those keep federation's own limits (5 000 per row at validation in
`worker/federation.py`, 2 000 for a `fed_review` package): honouring the cap there could only ever
make a peer's list *shorter* — their export is already bounded by `fed_export_max_files` and the
wire budgets — and a partial list is worse than none, because search would then answer from half a
torrent.

The worker reports `max_files`, `max_files_config` and `max_files_max` in its heartbeat, and the
**Index** status card shows what it is really storing per torrent. That card, not the Whitelist one:
the equivalent warnings for parallel fetches and fetch order live inside `whitelistStatus()`'s
`if ($mode === 'whitelist')` branch and therefore never render on an open-mode tracker, which is the
only mode where `index_files` exists at all. A heartbeat carrying no `max_files` key is a worker
still running a pre-1.38.0 `worker.py`; it ignores the setting completely and the card says so.

Raising the cap changes nothing about torrents already stored — no backfill, deliberately: flipping
resolved rows back to *pending* would hand the hourly pruner rows whose `grace_until` is already in
the past, and it would delete them. Schema 42 → 43 (settings only). The 50 000 ceiling is written
twice and cannot be written once, because one half enforces it in Python (`MAX_FILES_MAX` in
`worker/worker.py`) and the other offers it in a PHP form (`META_MAX_FILES_MAX` in
`includes/index.php`, which the save clamp and the field's `max=` both read);
`tests/worker_settings_test.py` fails if they stop agreeing. That suite is new and drives the whole
bridge — both settings — against fake objects: the panel value winning over the config file,
clamping rather than discarding, empty meaning the config file, the read-error contracts (which
differ between the two, on purpose), and `finish()` writing exactly the capped number of rows while
recording the uncapped count.

### Fixed — a file list now says how much of it was ever stored

*"Why does an 18 000-file torrent load only 5 000?"* Because 5 000 is all there has ever been. The
metadata worker writes the first `max_files` paths of a torrent (`[worker] max_files`, set to 5 000
in `/etc/tracker-metadata.conf`) and records libtorrent's real count beside them, so the catalogue
holds a 5 000-row list under an 18 000-file number. 620 entries on the production database are in
that state and every one of them has exactly 5 000 rows; the largest claims 27 260 files. Nothing
was being truncated on the way out — the panel was showing everything it had, under a heading
printing something else.

Every surface now prints both numbers and one calm line under the tree: **Files (5 000 of 18 000)**
and *"The catalogue stores at most 5 000 paths per torrent — the rest were never recorded."* That is
the public info overlay and the file modal — which, with `index.files_all` granted (the *Site member*
preset has it), simply stopped paging at 5 000 and said nothing whatsoever — and the Index and
Whitelist detail modals, where the Size row printed 18 000 three lines above a heading that said
5 000. `api/index_files.php` returns the entry's own `files_count` for both arms plus `stored_total`
(filled in only on the page that ends the stored list) and `stored_short`; the two admin item
endpoints return `files_short` next to the existing `files_truncated`. Those two are deliberately
separate: *Load the whole file list* is offered only when rows really are waiting in the table,
because on a list the worker wrote short it would fetch the same 5 000 again.

Two smaller things fell out of the same reading. The info overlay's *list truncated* note was
appended to the panel one line before the panel was emptied to make room for the tree, so the single
message a reader without `index.files_all` needed never survived to the screen. And the Whitelist
modal printed *"Single file or no file list stored"* underneath complete file trees, because that
sentence hung on the reply not being truncated instead of on there being no files.

Storage is untouched. Raising the cap is still a worker config edit and a service restart, and it
applies only to torrents fetched afterwards: already-stored lists keep the length they were written
with, and the file-name search can only ever match a path that was stored.

## [1.37.0] — 2026-09-08

### Added — the database engine's memory, from the panel (MariaDB and MySQL)

The buffer pool and the other memory limits used to be set in two places by hand — `SET GLOBAL`
on the running server and a drop-in under `/etc/mysql` for the next restart — and the two
drifted. A new card on the **Traffic** page (with its switch and helper command under
Settings → **Database memory**) shows what runs, what the drop-in says and what the counters say
about it: buffer-pool fill, reads from disk, temporary tables on disk, connections against the
limit. Seven keys are managed — `innodb_buffer_pool_size`, its MariaDB 11 ceiling
`innodb_buffer_pool_size_max`, `innodb_log_file_size`, `max_connections`, `tmp_table_size`,
`max_heap_table_size`, `table_open_cache` — each with a badge saying whether **this** engine and
version changes it live or waits for a restart (the helper decides that per engine: MariaDB ≥ 10.2
and MySQL ≥ 5.7 grow the pool live, MariaDB ≥ 10.9 resizes the redo log live, MySQL does not,
the ceiling is startup-only). *Apply* changes what can be changed live and reads it back, then
writes one drop-in (`70-tracker-panel.cnf`, in bytes) so a restart keeps it; *Restart the
database* is a separate button behind its own acknowledgement and password, because the database
on this class of machine is shared with the mail, the forum and the files. The root helper is
`tools/opentracker/tracker-dbmem.sh` — seven literal keys with floors and ceilings (the pool may
never take the machine's last 512 MiB), integers only, every statement assembled from a validated
name and a number; when php-fpm cannot write `/etc` the write is deferred to the janitor, the same
protocol as the kernel buffers. Sibling `.cnf` files that set the same keys are reported as
conflicts. `tests/dbmem_test.php` drives the helper against a stub client for MariaDB 10.6,
11.8 and MySQL 8. Schema **42** (two settings).

The helper went through an adversarial review before its first install, and the review changed
it. Whether a key exists is asked of the running server, never read off a version table: the pool
ceiling was backported to MariaDB 10.11.12 / 11.4.6 / 11.8.2 (MDEV-29445), and a drop-in that names
a variable the engine lacks stops the engine from starting — at the next restart, whoever does it,
weeks later. The ceiling key is written with the `loose-` prefix on top of that. Every call to the
client and the `systemctl restart` are bounded by `timeout`, so a wedged server costs the panel
seconds rather than a hung worker; the helper sets its own `PATH` and refuses its test hooks when
it runs as root; two restarts within two minutes are refused; the redo log may not exceed a quarter
of the free space on the data disk; conflicts are looked for in every directory the engine reads,
in every spelling the engine accepts (`loose-`, dashes, `1GiB`); and the reply is built whole and
printed once. Seventy checks in the suite, twenty of them from the review.

### Added — `index.files_all`

The first 2 000 files of a torrent come with `index.files`; loading the rest — page after page,
by scrolling or by button, on the search page's file modals — is its own grant now, so a group can
be shown a list without being handed 40 000 rows of it. Without the grant the list stops at the
first page and says so. The *Site member* preset includes it.

### Changed

- **Switching the language keeps your place.** The switcher has to reload the page (the strings
  are rendered server-side), and a reload landed at the top. A click on the switcher now stores the
  scroll position — and on the Settings page the search text and the active group — for a few
  seconds, and the next load of the same page puts them back before anything is visible. The
  public header does the same for Info and Terms.
- Every button whose whole label ended in an ellipsis (*Back up now…*, *Apply limit…*, *Add
  instance…* and twenty more) reads as a clipped label; the ellipsis is gone from buttons and kept
  for progress states, placeholders and dropdown headings, where it means something.

### Fixed

- **The Settings page declared itself English** whatever language it was rendered in. The switcher
  worked and the words changed; the document said `lang="en"`, so a screen reader read Polish in an
  English voice and the browser offered to translate a page already in the reader's language. It now
  declares the language it was rendered in, like every other template, and a check in
  `tests/lang_test.php` fails if any template ever hard-codes it again.
- The runtime state the code writes under `config/` (process-cost readings, the tuner's state, the
  database-memory helper's pending set, every lock file) and translations installed through the
  Languages panel are ignored by git now; the tuner state and two lock files that had been
  committed are removed from the repository.

## [1.36.0] — 2026-09-07

### OpenTracker — round six is in production

Both binaries swapped at 12:42 (the round-five builds are kept in
`/home/debian/backup-opentracker-20260907/`); the service restarted once and the swarm is
rebuilding. The seven review fixes from 1.35.0 are what runs now.

### Changed — every poll gets its whole file

The conditional upsert made a full pass take 67–92 s against a 90-second budget, so three morning
polls still ran out of time. Production runs `index_poll_budget = 120` now (the setting's cap);
the default stays 45 for a fresh install, where the catalogue is small.

### Added

- **The file list on the public search page is paged.** `index_files` takes `offset=` and
  answers 2 000 files at a time; the page loads the next slice when the reader scrolls to the end
  of the list (an IntersectionObserver on a sentinel) or presses *Load more files*. The admin
  modals on the Index and Whitelist pages, which cap at 5 000, gained a *Load the whole file
  list* button (`files_all=1`).
- **What the machine's own processes cost** on the Traffic page: CPU of one core and resident
  memory for MariaDB, the tracker, php-fpm and the metadata worker, read from `/proc`
  (`includes/procstat.php`). CPU is a rate, so it is measured over the interval since the previous
  poll — the previous reading lives in `config/proc_usage.json` — and the first reading after a
  restart shows memory only.
- **The language switcher follows you down the Settings page.** A copy of the header's switcher
  sits at the right end of the sticky toolbar and fades in when the header has scrolled away
  (an IntersectionObserver on the header; one 280 ms transition, no layout shift; hidden on phones
  where the toolbar is not sticky).

### Added — the API answers in the visitor's language

The last untranslated layer: the sentences the PHP side sends to the browser — every
`jsonResponse(['error' => …])` and `message`, the whitelist status warnings, the poll's error
texts, the federation and page-editor replies — go through `__('api.*')` now, about 750 keys in
`tools/lang_src.d/api.py`. The English values are byte-identical to the former literals, and
`__()` returns English in CLI code without `langInit()` (`langEnsure()`), so the tests that
compare messages and the janitor's log lines are unchanged. Machine codes the client or the tests
compare (`not_found`, `rate_limit`, `login_required` …) and the one sentence the public script
matches by content stay as they are; the one sentence left in English is a server-to-server
reply (`api/v1/whitelist_submit.php`) read by machines. Dictionary: 3 936 → 4 697 strings.

### Security — four of the review's "do now" items, each written and adversarially reviewed

- **The owner's password before changing what the server executes or whom it trusts.** Every
  dangerous action re-asked the password, but the settings that define the commands those
  actions run (`tracker_mode_switch_cmd`, `backup_cmd`, `sysctl_cmd`, `ot_cluster_cmd`, the
  interpreter and script paths, the service name), the proxy trust pair (`trusted_proxy_ips`,
  `client_ip_header`) and `hmac_secret` saved on a session cookie alone, and admin-group accounts
  reach that endpoint. `save_settings` keeps one `$reauthKeys` list, compared against the same
  fallbacks the form prints; a changed value answers `reauth_required` and the settings page
  opens its confirm-password modal with a body saying why. The never-called `attemptLogin()`,
  which granted a session without 2FA, is gone.
- **A public search of one or two characters is refused** (HTTP 400, "Type at least 3 characters
  (or a hex prefix of an info hash)"; the page says the same under the box and does not fire the
  request). Such a term skipped fulltext and fell to `name LIKE '%x%'` over 1.5 M rows, twice —
  the shape of a recorded 24-minute outage. Hex prefixes keep working; they are indexed.
- **Translation uploads are sanitised.** Values printed by `__()` are HTML by design, so a
  contributed JSON was site-wide stored XSS. `langSanitizeValue()` allows `a strong em b i code
  kbd br span small sup` with no attributes except `href` on `a` limited to http, https and
  relative paths — decoded first, so `&#106avascript:` and `/\host` do not slip through — and
  refuses anything else; dropped keys are listed in the reply. Hostile fixtures in `tests/lang_test.php`.
- **The web bootstrap fails fast.** A deferred heavy migration no longer makes every request wait
  five seconds on the schema lock (0-second wait outside the CLI, the request runs on the current
  schema); a database that is down answers 503 with `Retry-After: 60` — a static maintenance page
  for the site, JSON for the API — instead of a blank 500; the installer writes
  `PDO::ATTR_TIMEOUT => 3` into `config/database.php` (existing installs add the line by hand,
  see INSTALL).

### Fixed

- **Chrome offered a saved e-mail and password in the search boxes of Reports, Whitelist, Users
  and Settings.** Every one of those pages has a confirm-password modal, and a form with a password
  field and no named username is a sign-in form to the password manager, which then picks the
  nearest text box on the page — the search box — as the username. Each confirm-password input now
  has a visually hidden username sibling (`autocomplete="username"`, the admin login) and its own
  `autocomplete="current-password"` role, so the manager has its pairing and leaves the search
  boxes alone.
- Two buttons whose whole label was *Restart…* read as clipped; they say *Restart (on the
  dashboard)* and *Restart the tracker…* now.
- `harness.c`, a 38-line lab harness from the accesslist review, had been committed to the
  repository root by mistake; removed.

## [1.35.0] — 2026-09-06

### Changed — a poll no longer rewrites what did not change

Measured on production before the change (1.5 M rows, 621 000 kept per poll): the download took
6 s, the parse 3 s, and the upsert **82 s** — 96 % of the poll, and the reason every other poll ran
out of its budget and the coverage chart sat at 44 %. Of the kept rows 99 % already existed and
78 % carried exactly the same seeders, leechers and completed count as the poll before; the old
statement rewrote every one of them (`last_seen = NOW()`, `seen_count + 1`) and four secondary
indexes with each. `indexUpsertBatch()` now leaves an unchanged row alone: the counters only move
when the tracker's numbers moved, `last_seen` / `seen_count` advance at most once per six hours
(`IDX_SEEN_WINDOW_SEC`), and the protection window is pushed forward once a day. Same rows, same
machine, rolled back after measuring: **43 s** for the full pass — inside the 90-second budget with
room. Consequences: `seen_count` counts six-hour windows rather than polls, and *Last seen* on the
catalogue can lag by up to six hours for a swarm nobody joined or left. `tests/index_test.php`
encodes the rule (a poll inside the window updates seeders and leaves the stamp; one outside
advances it).
First production poll after the deploy: 1 506 386 entries in one pass, 75 s, not truncated —
the alternating truncated/resumed pattern is gone and every poll covers the whole file.

### Added — the browser scripts speak the site's language

Every string a script shows — toasts, confirmations, table headers built in JS, empty states,
button captions, the public search page, the CAPTCHA overlay, the home beacon — now comes from the
dictionary. `langJsBridge()` writes a JSON bundle of the `js.*` keys for the active language into
the page head and `assets/js/i18n.js` defines `t(key, {n: 5})`, the client-side twin of `__()`.
Only the `js.` prefix is sent, and a public page only the four areas its scripts read. The settings page, which
was translated in part, is translated in full (about 900 strings), and its sub-menu group names
come from the catalogue through `settingsGroupTitle()` so the search index and the tests keep the
English source. Dictionary: 1 130 → 3 936 strings, EN and PL, one source under `tools/lang_src.d/`. A public
page carries only the four `js.` areas its own scripts read; the panel gets the whole set.

### Changed — one wait for every list

`AdminCommon.DEBOUNCE = { sort: 1200, search: 400 }` is the one place the numbers live. A header
click redraws the arrows at once and fetches when the decision is made (desc → asc → off is two or
three clicks); the Index page had this, Users and the Whitelist tabs had a shorter one, Reports
another, Backups re-sorted on every click, and the public search page fetched on every click.
Now every list in the panel and the search page wait the same, and the two scripts that do not
load `admin-common.js` carry the numbers with a comment saying where they come from.

### Fixed

- **Security — the backup helper ran any `--script` path as root.** `tracker-backup.sh` accepted
  every absolute path after the flag and executed it under its NOPASSWD sudoers line, so the web
  user (or anything that had become the web user) had a password-free root shell one argument
  away — the one property every other root helper was built to deny. Found by the review pass
  behind the suggestions list. The helper now trusts a path only when the file and its directory
  are owned by root and writable by neither group nor others; a file that exists and fails that
  test is refused with an explicit JSON error, a path that does not exist falls through to the
  candidate list and builtin mode as before. Reinstall the helper:
  `sudo install -m 0755 tools/opentracker/tracker-backup.sh /usr/local/sbin/`.
- **The settings search box offered a saved e-mail and password.** Not autofill: Chrome's password
  manager pairs the nearest text box before a password field as the username, and the credentials
  form on the same page has three. The form now names its own username field
  (`autocomplete="username"`) and the password roles (`current-password`, `new-password`), so the
  search box is no longer the candidate; the other managers get their `data-*` opt-outs.
- **The page editor's preview looked nothing like the page.** It was a `<div>` inside the panel's
  stylesheet, which knows nothing about the stats widget or the announce box. It is an `<iframe>`
  carrying the public stylesheet and nothing else; the markup is still the same `richtextRender()`
  call the public page makes.
- **"Nothing is waiting." as a bare sentence in a table cell.** One empty-state block for the panel
  (`AdminCommon.emptyState(text, icon, hint)`, `.empty-state`): icon, sentence, an optional hint
  saying what would fill the list. Used in the review queue and the federation queue; the
  translation pass put the other empty states through the same words.
- The public site's text colour is `#cdd1da` (was the neutral `#d0d0d0`, which read warm against
  the blue-black ground).

### OpenTracker — the review findings, verified and seven of them patched

The six findings the earlier review had left unverified got two independent refuters each; all
six stand (the missing access gate on the full `/scrape` was confirmed high by both — an
unauthenticated client can park ~114 MB per connection for fifteen minutes; report-only here
because the port is loopback-only and a gate would change behaviour for every client). Nine
patches were written and each was reviewed by an agent that had to apply it on a copy and find
what was wrong with it: seven survived, two were rejected as incomplete and are not shipped.
`tools/opentracker/opentracker-review-fixes.patch`: the UDP connection-id secret from
`getrandom(2)` instead of `srandom(time(NULL))` (forgeable ids), the accesslist reload that freed
a list announce threads were still searching, the `/stats` task that survived its client and
wrote to a reused fd, an atomic `g_torrent_count`, the tpbs one-byte overrun, UDP workers started
after init and the first accesslist load, and the mmap bound on a whitelist without a trailing
newline. Built on the VPS on a copy of the sources and put through a lab on a private port under
valgrind — announces, scrapes, twenty aborted `/stats` clients, sixty SIGHUP reloads under load:
zero invalid memory accesses, the process serving throughout. The binaries in
`tools/opentracker/bin` are these builds; **production still runs the round-five build** — the
swap restarts the tracker and empties the swarm, so it waits for the operator's word.

## [1.34.0] — 2026-09-05

### OpenTracker — the fix is in production, and a review of what else is there

**Both binaries swapped** (whitelist and blacklist mode, 12:48) for the builds with libowfat's
zerocopy block compiled out — `tools/opentracker/libowfat-no-zerocopy.patch`, applied before
`make -C libowfat`. The originals are kept in `/home/debian/backup-opentracker-20260905/`. The swarm
rebuilt from empty as expected (1.56 M torrents and 3.8 M peers within four hours), and **every full
scrape since has arrived intact**: the framing error that truncated 10 of 13 polls is gone. The
truncated rows that still appear are the panel's own doing — the poll runs out of its
`index_poll_budget` (default 45 s, cap 120 s) on a swarm this size, and those rows carry no
`partial` marker. Raising the budget is the operator's call; the panel now reads the whole file
within it.

**What a source review found** — written up as an addendum to
`tools/opentracker/UPSTREAM-REPORT.md`, none of it patched here. Traced by a second reader: the UDP
connection-id secret comes from `srandom(time(NULL))` on the shipped Makefile (forgeable, high);
an accesslist reload frees the superseded list at once when it is older than five minutes while
announce threads may still be searching it (use-after-free on SIGHUP, high); a `/stats` task
outlives its client and writes to whoever reuses the fd (medium); `g_torrent_count` and the stats
counters are updated without the lock, a one-byte write past the request buffer in the tpbs path,
`free_peerlist(NULL)` on allocation failure, and a dangling pointer in `iovec_increase` (low). Six
more were raised by one reader and not confirmed before the session ended; three were refuted.

### Changed — the language you pick lasts as long as the tab

The switcher's cookie no longer carries an expiry, so it lives exactly as long as the browser
session — close the tab, and the next visit is back to the account's *Interface language* (or the
site default). While it lives it outranks the account setting, because a person who clicks **PL**
means *now*, not *until I find the account page*. The order is: `?lang=` → session cookie → account
language → site default → browser → English. `tests/lang_test.php` encodes the new order.

### Added — the switcher where it was missing

The admin header (before *Logout*) and the account page (inside *Your groups*) now carry the same
switcher the public pages have; every admin template links the favicon, so the tab icon no longer
depends on which page was cached first.

### Changed — Swarm Timeline 3 m / All use the fine table when it still has the data

`statsTimelineChooseTable()` used to pick the hourly table for anything beyond a week, which capped
3 m and All at about 300 points while 1 m had thousands. It now takes the five-minute table whenever
the range fits inside its retention and the point count stays under 4 500 — which on this install is
both 3 m and All — and only falls back to the hourly rows past that.

### Changed — Terms, Info and the home page grew with the project

The built-in Terms and Info now describe the index, the member search, accounts, languages,
ratings and the transparency features — each paragraph wrapped in a **conditional marker** so it
appears only under the setting that makes it true: `[[if:whitelist]] … [[/if]]`,
`[[ifnot:users]] … [[/ifnot]]`, and so on for `open`, `schedule`, `registration`, `signup`,
`email_verify`, `index`, `search`, `stats`, `donations`, `contact`, `transparency`, `languages`,
`ratings`, `descriptions`. The markers are part of the text the editor shows, so a page you save
keeps following the tracker mode exactly as the built-in one does — that was the one thing an
edited page used to lose. Unknown marker names are reported by the preview rather than swallowed.

### Added — the home page is yours to write

**Home page layout** can now add up to six **custom sections** (own heading, own text), and every
built-in section has a **Text** button that opens the page editor for that section alone. The text
is Markdown, BBCode or HTML, per language like Terms and Info, and it can use **placeholders**
resolved at render time: `{{block:announce}}` (any built-in section's live body), `{{torrent_count}}`,
`{{register_button}}`, `{{announce_http}}`, `{{site_name}}` and the rest listed as chips under the
editor. A custom text replaces the section's built-in body; leave it empty and the built-in body
returns. `includes/homeblocks.php` now builds every section into a string so the page, the editor
preview and the placeholder engine all read the same thing.

### Added — permission presets and the matrix

The group editor offers **Start from:** Moderator, Content reviewer, Whitelist curator, Read-only
auditor, Site member — one click fills the checkboxes, and every one of them stays visible and
editable before saving. Under the groups table a **permission matrix** (groups across, permissions
down) shows who holds what in one glance. No new permission id: Settings stays owner-only by design,
so a "pages" permission would promise something no page could keep.

### Fixed

- Settings search no longer triggers the browser's email autosuggest (the field starts read-only and
  unlocks on focus).
- `apiCall()` regression guard: the second parameter joins with `&`, never `?` — the mistake that
  gave *Unknown endpoint* on every Terms/Info open in 1.32.0 now fails a test.

### Docs

README (screenshot gallery of fifteen local-instance shots, home sections, placeholders, markers,
the new language order, presets), HANDOFF, INSTALL (the third opentracker patch in the recipe),
`tools/opentracker/README.md` (round five: production) and the upstream report addendum.

## [1.33.0] — 2026-09-05

### Found — the OpenTracker framing bug, with the syscall that causes it

Three rounds of instrumentation had established that every buffer was freed after it was sent, that
every chunk header declared exactly what followed it, that the byte count on the wire matched the
byte count handed to the kernel — and that the stream was corrupt anyway, with a heap pointer sitting
where a chunk-size header belonged. Valgrind and helgrind saw nothing on the send path.

They saw nothing because **no instruction in the process ever touched freed memory. The kernel
did.** libowfat 0.34's `iob_send()` sends with `sendmsg(MSG_MORE | MSG_ZEROCOPY)` once a batch is
8 KiB or larger, and then frees the buffers the moment the call returns. `MSG_ZEROCOPY` is a promise
that the pages will not change until the kernel reports, on the socket's error queue, that it is done
with them — and libowfat never reads that queue. So the sequence is: `sendmsg` returns → `free()` →
glibc writes a tcache pointer into the first sixteen bytes of the freed block → the receiver reads the
socket → the kernel hands it the page **as it is now**. A pointer where `%zx\r\n` used to be. On
loopback, with a slow reader (the panel decoding tens of megabytes of gzip), the window is wide open;
that is why 10 of 13 production polls in the last six hours arrived truncated.

**Proved, not argued.** The same binary, the same 6000-torrent reproducer, twice:

| | run 1 | run 2 | run 3 |
|---|---|---|---|
| as built | BAD FRAMING @ 64 705 — bytes `\xad\x55…` (0x55ad…, a heap pointer) | BAD FRAMING @ 16 187 — `\xc9\x7f` (0x7fc9…) | BAD FRAMING @ 48 535 |
| `SO_ZEROCOPY` refused via `LD_PRELOAD` | CLEAN 420 311 B | CLEAN 420 112 B | CLEAN 420 311 B |

`strace` on the first: **3 × `setsockopt(SO_ZEROCOPY)`, and all 63 `sendmsg` calls carried
`MSG_ZEROCOPY`.** The shim did one thing — return `ENOPROTOOPT` for that one option — and the
corruption followed the flag.

**The fix is built and NOT installed.** libowfat's `iob_send.c` with the zerocopy block compiled
out (`#undef MSG_ZEROCOPY` / `#undef SO_ZEROCOPY` — the plain copying `sendmsg` path is what run B
used), both modes with the production feature set, at `/tmp/fix/out/opentracker.{white,black}` on the
VPS; the shipped binaries are copied to `/home/debian/backup-opentracker-<date>/`. Swapping them
restarts the tracker and the swarm rebuilds from empty, so that is the operator's call, not a deploy
step. **Nothing in production changed and nothing is slower**: valgrind and helgrind only ever ran on
copies in `/tmp`.

Also confirmed along the way, upstream-worthy but unrelated: a use-after-free at **shutdown** —
`clean_worker` (ot_clean.c:56/106/122) walking peer lists that `trackerlogic_deinit`
(trackerlogic.c:588) frees from `signal_handler`. A crash-on-exit, not the framing bug.

### Fixed — the last fifteen audit findings

Every finding that survived adversarial review is now closed. The ones with a judgment in them:

- **`rateLimitAllow()`** read-modify-wrote a shared JSON file with the lock covering only the write,
  so parallel requests erased each other's hits — including hits for other actions and other IPs,
  because every writer rewrote the whole file. One lock around read and write, on a separate path.
- **`v1/users/revoke`** could strip the admin group from the site owner and remove panel-carrying
  groups — the two guards both of its siblings enforce were missing.
- **`backup_action.php`** shadowed the global PDO `$db` with the database *name* in the restore-db
  branch, so the panel's most destructive operation was the one never written to the audit log.
- **v1 server-to-server calls were never audited** — the guard admitted only `admin/*`, and the actor
  branch that would name the API client read a global nothing ever set.
- **`clearLoginFailures()`** rewrote the map from an unlocked read while failures were recorded under
  a lock; a failure landing in the gap was a lockout that never tripped.
- **`scheduleSyncBansToBlacklist()`** locked the blacklist file's own inode — the inode
  `removeHashFromBlacklist()` throws away with `rename()`. The two excluded nothing.
- **`[quote=]` / `[spoiler=]`** backtracked quadratically; past ~15 600 characters PCRE gave up,
  returned NULL, and the next pattern turned that into an empty description. Anchored, and each call
  now falls back to its input if the engine ever gives up again.
- **`admin/logout`** mutated session state on GET, the one method the router exempts from CSRF.
- **The worker's `finish()`** cleared the claim before writing the file list, so a failed store left
  the row `done` with its files deleted. One transaction; the claim is released last.
- **Three full-table `COUNT(*)`** on endpoints the index page re-polls every 5 s are cached (15–20 s
  — they are labels), and the bulk scrape counts what is left **once** per run instead of on each of
  up to 500 calls.
- **A table stuck over its cap** forced the full prune — including an unbounded orphan sweep over
  6.1 M `index_files` rows — every 60 s. The sweep runs only when a prune deleted something, in
  batches; a forced prune that trimmed nothing stands down for the normal interval.
- **The whitelist probe** made up to 200 sequential scrapes with nothing to stop it when the tracker
  went quiet. A 20 s budget and a five-in-a-row abort; rows not reached are first in line next tick.
- The overlap cache key now includes the content of the hand-typed blocked addresses.

### Changed — the account page, and installing a language

The interface language is a sub-section under *Your groups* rather than a third card that held one
`<select>`, and the select is styled like the rest of the site instead of being the browser's own
white control. Installing a language: the file step is the same drop zone the address-list import
uses; the language list has 76 entries; and a typed code the table does not know is still
**recognised** — the browser's own ISO table (`Intl.DisplayNames`) names it, and on the server PHP's
`intl` does the same, so a switcher never shows a bare code for a language somebody could name.



### Fixed — the Terms/Info editor could not reach its own endpoint

`apiCall()` prepends `api.php?endpoint=`, so a second parameter has to join with `&`. Both new
editors used `?`, which made the whole string the endpoint name — `admin/page_content?page=tos` is
not in the route map, so every open answered 404 and the dialog showed nothing. The same mistake
was in the language export. Both fixed, and `pagecontent_test.php` now fails if either comes back:
this is a trap the project has hit before and a comment alone had not been enough.

### Added — Terms and Info are written per language

`page_content` is keyed by (page, language) instead of by page alone. The editor grows a language
rail with a dot per language — live, draft, or nothing written — and *Restore* is per language, so
restoring Polish cannot take an English page with it.

**What a visitor gets, in order:** their language → the site's default language → English → any
other version that exists → and only if *nothing* is written, the built-in page. That order is
deliberate. Terms somebody actually wrote must never be quietly replaced by the shipped boilerplate
because one translation is missing.

The default text is no longer a second English copy living in the generator: it is built from the
same dictionary keys the templates render, converting the little HTML those strings carry into
Markdown or BBCode. So *Restore* while editing Polish gives back Polish, and there is one source
for the wording rather than two that drift.

Schema 41.

### Changed — no browser dialogs left in the panel

`window.confirm()` and `window.prompt()` are unstyled, some browsers suppress them after the first
one on a page, and `window.prompt` shows a typed password in clear. Six of them were still in the
panel; they now use the panel's own dialog.

Stacking one dialog over another needed a fix of its own: Bootstrap gives every modal the same
z-index and appends each new backdrop to the end of `<body>`, so a confirm opened over an open
modal got a backdrop **above its own dialog** — buttons visible, greyed out, unclickable. Measured
after the fix: dialog at 1075, its backdrop at 1070, the one underneath at 1050, and the OK button
is the topmost element at its own centre.

The close question is asked asynchronously while Bootstrap wants a synchronous answer, so the close
is cancelled, the question asked, and the modal closed again once the answer is in.

### Fixed — the navigation fell apart in Polish

`.main-nav` was `space-between` with the links and the switcher as two columns. That works only
while the links fit on one line; with longer labels the link row took the full width, wrapped, and
the switcher landed alone on a third line at the left edge. The switcher is now the last item
*inside* the link row, after the same separator everything else uses, so it wraps with them and
stays centred however long the labels get.

### Changed — the Languages panel, and a real dropdown to install one

The table sits in its own panel, the three switch columns are separated so they read as three
different questions rather than one repeated control, the padlock on a built-in looks like a
deliberately fixed control instead of a rendering failure, and the action buttons are inside the
row. "Which language is it" is now the panel's own dropdown (`.wl-dd-menu`) instead of a wall of
chips — ordered, scrollable, keyboard-navigable, with a free-text box beside it for a code the
panel has no name for.

### Added — the admin panel speaks Polish

The remaining public pages (statistics, unsubscribe, admin sign-in) and the admin panel templates
are translated. The dictionary source is split into one module per area under `tools/lang_src.d/`,
so adding an area is adding a file — a single source file could only ever have one writer.

### Fixed — seven audit findings, each confirmed by five or six independent refuters

- **`includes/api_auth.php`** — a failed bearer auth stored the request BODY verbatim, so a
  provisioning call that failed to authenticate left the buyer's **cleartext account password** in
  `api_bans`, and the panel read it back in a JSON response. The headers were already redacted; the
  body was not. Redacted now by field NAME, on the same rule the audit log uses. A body that is not
  a JSON object is dropped rather than guessed at.
- **`api/index_info.php`** — gated on `index.search`, a permission id that does not exist in the
  registry, so it denied every account. It is `index.view`, which is what the search itself uses.
- **`includes/users.php`** — a moderator could never obtain a panel session at all: the only writer
  of `admin_via_user` required admin-group membership, so the entire moderator permission map was
  unreachable by the audience it was written for. A remember-me return now opens it too.
- **`tools/tuner.py`** — a **dry run** left the restore marker armed with `running` already false,
  which is exactly what the janitor fires on: a minute later it ran a real firewall write for it. A
  dry run that changes the machine is the one thing a dry run must not do.
- **`tools/janitor.php`** — turning the address lists off never wrote the empty file that clears
  them, because the write sat inside the "switch is on" guard. The panel said off; the firewall went
  on dropping. The write is unconditional now and the clear is pushed.
- **`assets/js/admin-netlimit.js`** — the 5-second poll wrote over the pps field while it was being
  typed into. Typing "4" toward 45000 clamped to the minimum, the poll stamped "1000" over it with
  the caret at the end, and the rest of the digits appended to the wrong number. Also: a slow answer
  for an old chart range could overwrite a newer one.
- **`assets/js/admin-sysctl.js`** — the 15-second poll rebuilt every input in the grid, so a value
  being typed was replaced and the caret went with it. A repaint is a refresh, not an instruction.

Also: `admin-index.js` rendered HTTP error bodies as real data (a transient 500 drew a live 1.9 M-row
index as empty) — a failed poll now keeps the last good numbers and says they are stale; the
transparency page numbered rows from the size of the page it was on, so the short last page started
again from a lower number; and the coverage chart dropped its uPlot instance without destroying it.

### Changed — MariaDB's buffer pool is 1.5 GiB

It was 1 GiB with a 2 GiB ceiling already configured, so this was live and needed no restart.
Measured before: 0.73 % of page reads coming from disk, 1217 free pages — the pool was full. The
two index tables are 5.4 GB together, so this moves coverage from about 19 % to 28 %; worth having,
not a cure. Persisted, so a restart does not hand the memory back.

## [1.31.0] — 2026-09-04

Two features that both come down to the same thing: the front page and the words on it stop being
something only a code change can alter.

### Added — the home page can be rearranged from the panel

**Settings → Home page layout.** The front page is built from seven sections; they can now be
dragged into any order, hidden, and their headings renamed, with the line under the site name
editable too.

The interesting part is what did *not* change. Every section still renders exactly where it always
did, with its own conditions intact — the statistics widget still needs a setting, a permission and
a cache file, and tracker mode still rewrites two of the sections three ways. The template captures
each one into a buffer and `includes/homelayout.php` only decides the order those buffers are
emitted in. Reordering by moving markup would have meant duplicating every one of those conditions,
and a duplicated condition is one that goes stale.

**Hiding a section here is not the same as switching its feature off**, and dragging one back does
not switch it on. A section whose own setting is off says so on its row, with the setting named —
without that, an operator drags a block into view and then wonders why nothing appeared.

Reordering is offered three ways: drag, and ↑ / ↓ buttons on every row. Not belt-and-braces —
native HTML5 drag-and-drop fires no events at all on a touch screen and cannot be driven from a
keyboard, so a list that only drags is a list some people cannot use.

The stored layout is **repaired against the catalogue** on every read: every known section appears
exactly once, unknown keys are dropped, and a section added in a later version lands where the
catalogue puts it rather than at the end. That is what stops an upgrade from quietly losing a
section off the front page. `home_layout` is written only by its own endpoint and is deliberately
absent from the settings allow-list, so an unrelated settings save cannot blank it.

### Added — the interface speaks Polish

Ported from **TryHackX-Files**, which has been running this design for a while, and kept
behaviourally identical — the shape is different because that project has a `Lang` class and this
one has procedural includes.

**Settings → Languages** installs, copies, exports and removes translations. English and Polish
ship; anything dropped into `lang/` is a language without a code change. A **switcher** sits at the
end of the nav bar (only when there is more than one language to choose, and it keeps you on the
page you were reading), an account can **pin its own language** so the choice follows it to another
browser, and the site default can be a language or **Automatic**, which hands the choice to
`Accept-Language`.

Resolution order, highest first: `?lang=` → the signed-in account → the cookie → the site default →
the browser → English.

**Three lists, and they are not the same question.** *Offered* decides which languages exist for
visitors at all; *in the switcher* decides what the header control shows; *for accounts* decides
what a user may pin — and bounds automatic browser matching. A language kept out of the last two is
still reachable by an explicit `?lang=` link: hiding a control is not withdrawing a translation.
Each list is stored as a positive allow-list and an **empty list means "no restriction"**, so a bug
that empties one would not show as a site with no languages but as a site quietly offering all of
them. Every guard in `tests/lang_test.php` exists because that failure is invisible.

**Uploads are JSON, never PHP**, and that is the security design: a `lang/*.php` is `require`d on
every request, so accepting one as an upload would hand an admin form a way to put code on the
include path. The payload is parsed as data, every pair is checked to be a flat string→string, and
the file is written from `var_export()` — what lands on disk is a literal array this code generated.
The two shipped languages are never replaced by an upload (every other translation falls back to
them, and a partial file would hollow that out) — copy one to a free code and edit that.

The two dictionaries are generated from **one source** (`tools/lang_src.py`) holding each string as
an (English, Polish) pair, because a key added to one file and forgotten in the other is the normal
way a translation rots. The test checks they still agree: same keys, same `:name` placeholders,
nothing blank.

Translated so far: the navigation and footer, the home page, Info, Terms, the whitelist page, the
report form, search, sign-in, registration, the account page, password reset, email verification
and change, the transparency report, and the 404. **Stats, Unsubscribe, the admin sign-in page and
the admin panel are still English** — the mechanism is in place for them, the strings are not
written yet. A missing key falls back to English, so a partial translation reads as English rather
than as blanks.

Schema 40 (`users.language`).

### Changed — the built-in home page headings follow the language

A consequence of shipping both features together: `homeSectionCatalog()` stores heading *keys*
rather than literal English, so "the built-in heading" means "whatever this language calls it".
A heading an operator renamed is shown verbatim in every language — they said what it should say,
and translating that would be overruling them.

### Fixed — the account page decided you had an email address by reading a word

`app.js` worked out whether an account had an address by comparing the visible text to the literal
string `none`. Translating that one word would have made every address-less account look like it
had an address called "brak". The signal moved to `data-has-email`, which is not prose anybody may
reword.

## [1.30.0] — 2026-09-04

### Added — Terms and Info are editable from the panel

**Settings → Site pages.** The two written pages the tracker serves (`?action=tos`, `?action=info`)
can now be replaced with your own text, using the editor the whitelist descriptions and the bulk
mail already use — Markdown or BBCode, with a **live preview rendered by the server**, through the
very `richtextRender()` call the public page makes. A preview produced by a second renderer in
JavaScript is a preview of a different page; this one cannot diverge.

The part that took the work is that both shipped pages contain conditionals: `trackerMode()`
decides whether the whitelist paragraphs appear, `usersEnabled()` whether the account terms do. So
the default text is **generated from the configuration the tracker is running under**, not stored
as a frozen copy. Press *Restore built-in* while the tracker is in whitelist mode and you get the
page with the whitelist clause and the list renumbered to close over it; press it in blacklist mode
and you get the page without. The dialog says out loud that a saved page stops following mode
changes, because that is a real consequence of editing and better said than discovered.

Markdown is offered first for these two, and not by taste: the renderer has real headings in
Markdown and **no heading tag at all** in BBCode, where a heading can only be a larger bold line. A
Terms page is mostly headings and numbered lists. BBCode is still offered for the toolbar.

Saving goes through `richtextValidate()` — the same link rules and image limits as every other
author-written text. A page written by the owner is not a reason to have a second, weaker path into
a public page. A stored page can be kept as a **draft**: visitors keep seeing the built-in one until
*Use my version* is switched on, and an empty page can never be published. *Restore* is a delete, so
the shipped template comes back by itself rather than by being copied over the top.

Schema 39 (`page_content`). Owner-only — there is no permission id for it, so an existing admin
does not silently gain the ability to rewrite the terms.

### Changed — the panel's alerts and status colours stopped shouting

The notes on the new cards used Bootstrap's stock alert colours, which are built for a white page.
Measured on ours they were **14 to 17 times brighter than the background they sat on** — a pale
orange slab in the middle of a dark panel. They now use the panel's own palette: an 8 % tint of the
hue, a 35 % border, and light text, which puts the slab at **1.08–1.14×** the page and the text at
**10.6–13.1:1** contrast.

Two semantic colours were also below the readability floor rather than merely loud: `.text-danger`
measured **4.02:1** and `.text-success` **4.01:1** against the card background, both under the 4.5:1
minimum, and both are used for numbers that matter (failed polls, coverage). They are now **7.59:1**
and **8.50:1**. The candidates were picked by computing the ratio for six options per hue, not by
eye. Buttons that carry a semantic class keep their own colour.

## [1.29.1] — 2026-09-04

Four things about the 1.29.0 cards, from looking at them on the real machine.

### Fixed — the coverage chart drew a three-year axis for one poll

The first live poll produced an x axis running from October 2026 to May 2029 with a single dot
pinned to the left edge. uPlot given one x value has no range and invents one. Two changes: the axis
is now anchored to **the window that was asked for** rather than to the spread of what came back, so
24h is drawn as a day whatever lands in it; and below two points there is no chart at all, just the
one poll written out in words — a line needs two points, and saying so is better than drawing
something that looks broken. Switching range rebuilds the chart rather than re-feeding it, because
uPlot fixes the x scale at construction.

The tiles no longer colour an "average" taken over a single poll, and the two figures that read as
alarms when they are not — 0 % coverage, 0 delivered — now say why underneath: *nothing new, it
resumed past what an earlier poll had*.

### Fixed — the backups directory was wearing an empty search box

`/var/backups/tracker` sat welded to the left edge of a rounded box. It was inside
`.toolbar-search`, the input-shaped shell every other page puts a search field in — border, radius,
`overflow: hidden`, and all of its padding on the icon and the input, neither of which a bare span
has. It is now what it always was: a labelled path, with an icon and its own padding.

### Fixed — "Show me where" was missing from All settings

It was made search-only, which was half the ask. Inside a category the heading already says which
category you are in, so the breadcrumb is noise; in **All settings** every section of the panel is on
one page in one column and "which group is this in" has no other answer. It shows in All settings and
during a search, and nowhere else. A plain page load now goes through the same pass as clicking All
settings — it did not before, so the breadcrumbs appeared when you clicked the button and not when
you arrived on the view it selects.

### Fixed — a poll that delivered 432 703 entries was charted as delivering nothing

Visible only once real rows existed: `delivered 0` sitting beside `kept 189 434`, and 0 % coverage
on the chart. `delivered` was `entries − skip_from`, and `skip_from` recorded the cursor that was
**stored** rather than the one that was **applied** — a truncated download resets the cursor to zero
and reads the short file from the start, while the stored value stays where a longer earlier pass
left it. The poll is now recorded with the cursor it actually used, and the endpoint reads a cursor
above the entries walked as the restart it can only be, so the two rows written before this are
readable too: **31.2 % and 59 %**, not 0 %.

### Fixed — the worker CPU tile blanked itself every few seconds

A share of a CPU needs two readings a few seconds apart, and between them the tile was replacing the
number with "measuring…" — taking away the thing the reader was looking at, on every refresh. It now
keeps the last figure it measured and swaps in the new one when it is ready. Only a worker that has
never been measured says anything else, and a restart (new pid, new start time) clears the carried
value rather than showing a number that belongs to a dead process.

## [1.29.0] — 2026-09-04

The scrape-coverage chart, the worker's CPU, addresses blocked by hand, and a probe that can tell
when it measured nothing.

### Fixed — the settings breadcrumb was never a JavaScript bug

Three rounds of "fix the condition" chased a bug that was not in the JavaScript. `.d-hidden
{ display: none }` sits about 1 400 lines earlier in `admin.css` than `.settings-where
{ display: flex }`, and the two have identical specificity — so the later rule won and
`classList.toggle('d-hidden')` had no effect at all. `showWhere(sec, false)` was running perfectly
and doing nothing. The guard is now `.settings-where:not(.d-hidden)`, which five other components in
that file already carry for exactly this reason. Verified: 0 breadcrumbs in All settings, 0 inside a
category, 6 while a search is running, 0 after clearing it.

### Fixed — the add-user dialog said an address was fine when the server would refuse it

`dsaddsas@wp\/.pl` lit up green. The client's test was `/^[^@\s]+@[^@\s.]+\.[^@\s]+$/`, which
describes the domain only by what it is *not* — so a backslash and a forward slash were, to it,
ordinary domain characters. The server rejects that string, so nothing bad could be stored; the
defect was a form that told the admin the opposite of what would happen. The domain is now checked as
a hostname, and the same regex replaced two more copies of the weak one — the user-edit dialog and
`app.js`, which is the public registration and account forms. Verified against `filter_var` over 37
addresses: **no input the client accepts is refused by the server.** It is stricter in five places,
all of them things nobody can receive mail at.

### Fixed — the password checklist had no stylesheet at all

The markup and the logic shipped in 1.28.0 and the CSS block did not, so the five requirements
rendered as an unstyled column and the `.ok` class toggled nothing visible. It now uses the public
registration form's own shape — two columns with the odd fifth requirement spanning both and centred
— and the same class names, so they cannot drift apart. The order was also wrong: the admin dialog
had the digit and the special character the other way round from the form an admin had just used.

### Added — the scrape coverage chart

**Index → Scrape coverage.** What each poll actually delivered, against how many torrents the tracker
said it had, over six hours to a month. The numbers were already being computed and thrown away: the
state file kept one `last_poll` key and the next poll overwrote it. `index_polls` keeps one row per
poll, pruned to `index_poll_keep_days` (90 by default — 48 rows a day).

The distinction the whole table exists for: **`entries` is a file position, not a delivery count.**
A pass that resumes at a cursor counts every entry it walks past, including the ones an earlier pass
already handled, so plotting raw entries shows a resumed poll as a triumph and the fresh one after it
as a collapse. `skip_from` is recorded with every row and `delivered = entries − skip_from`. Where the
tracker's own count was not available the coverage line has a gap rather than a zero.

### Added — the metadata worker's CPU on the Traffic page

Machine load says the box is busy; it never said who. The worker is one process under
`tracker-metadata.service`, and the panel now shows its share of a core beside the load. It follows
the pattern the OpenTracker card already established: the server returns **raw cumulative counters**
and refuses to compute a percentage, because the second reading would mean sleeping inside a web
request; the browser subtracts two polls. The process is found by its systemd unit in
`/proc/<pid>/cgroup`, never by the string "worker.py" in a command line — an editor with the file
open carries that too. Measured against `top` on production: 60 % of a core against its 54.5 %,
which is the difference between a ten-second average and an instant.

### Added — addresses blocked by hand, which beat an allow list

**Settings → UDP traffic → Blocked addresses.** The mirror image of Trusted addresses, and it exists
for the one case lists cannot express: *allow all of Poland, except these three hosts*. An allow list
is matched before a block list, so a host inside an allowed range can never be stopped by another
list. The chain is now, in order: trusted → **blocked by hand** → allow lists → block lists →
under-pressure lists → the general limit. Verified on production with `nft -c`.

### Added — the address lists say what they cover, not only how many lines they have

"8 810 entries" is a true and useless answer to *is this enough to block China*. Each list now
carries the number of IPv4 addresses its networks cover, computed once at import: China's zone file
is 8 810 networks and **342 983 424 addresses**. The ceiling is on networks and it holds about 28
countries that size, which is what the setting now says instead of "250,000 entries".

### Added — the upload says what it understood before anything is stored

A drop zone that takes a dragged file, and a read-back under it: how many entries were recognised,
how many addresses they cover, and which lines were ignored, with examples. Files with a NUL byte are
refused as binary. None of this is the security boundary — the text is parsed for addresses and
everything else discarded, on the server, and validated again in awk inside the root helper — it is
so that a file which is not what you think it is says so before it is saved.

### Fixed — conflicting addresses were compared as strings

The card only noticed a conflict when a trusted address and a blocked one were the *same string*,
which almost never happens. What happens is `5.188.1.7` sitting inside a blocked `5.188.0.0/16`. It
now tests containment, for IPv4 and IPv6, with the /0, /32 and /128 cases and mixed families covered
by 17 tests. The naive version was measured at **71 seconds** for 256 manual entries against 250 000
blocks, on a card that polls every few seconds; indexing the manual side by prefix length makes it
flat at under a second, and the answer is cached against a stamp of both inputs.

### Fixed — three things about the stability probe

**A run that changes nothing is no longer reported as a success.** After each step the probe reads the
limit back and stops if the kernel does not have the value it asked for — and it now requires the
firewall to be in `limit` mode, because `status` falls back to the saved file's header, and `set`
writes that file with the value it was asked for, so the number could confirm itself out of a file.

**The flatness verdict no longer fires on a good run.** It branded a run inconclusive when served was
flat, which is also what a plan sitting entirely *above* the arrival rate looks like — the best
possible outcome reported as a broken one. It now requires every step to have been dropping something.

**Apply writes to the limit the run was actually moving.** An outbound run's steps are anchored on the
reply budget; the button wrote them into the *receive* limit while the label said "the inbound
firewall limit". Outbound values now go through the egress path, `both` is refused as having no single
value to apply, and a run marked inconclusive refuses to be applied at all.

The dry run is called **Test**, like every other card on this page, and its badge no longer says
"rehearsal".

### Fixed — the firewall helper dropped arguments

`set` forwards four optional tails now (`--dry-run`, `--trusted=`, `--blocked=`, `--sets=`) and was
still forwarding two. Whichever came last was silently ignored — including `--dry-run`, which means a
preview could have touched the real firewall.

### Added — byte settings carry a unit

`5368709120` is a true statement about a limit and an unreadable one. The three byte-valued settings
(the API's daily budget and the two federation page sizes) are now a number plus a unit, defaulting to
the largest that divides the value exactly. The stored value stays bytes and the field carrying the
setting's name stays in the form as a hidden input holding exactly that — so a JavaScript failure
posts what it was rendered with, rather than turning 5 GiB into 5 bytes.

## [1.28.0] — 2026-09-03

Address lists, live validation where an admin creates an account, and four things the audit found
that were slow for reasons only the real table could show.

### Added — address lists: whole networks and whole countries

**Admin → Traffic → Address lists.** `net_limit_trusted` (1.26.0) is a box for a handful of
addresses. This is the same idea at the scale an operator needs: a country zone file from ipdeny, an
uploaded blocklist, a pasted range — with three behaviours whose order *is* the feature.

- **Allow** — never dropped, whatever else says.
- **Block** — dropped always.
- **Block under pressure** — dropped only when the machine is busy.

nftables rules share no state, so "only when busy" cannot be written as a condition on another rule.
It is a **tighter budget**: a fifth of the general limit. At rest those sources are nowhere near it;
as arrivals climb they are the first to hit one. The card says exactly that on the row rather than
implying something cleverer is going on.

Manual trusted addresses beat every list — matched first, so an imported country file can never shut
out a host you typed in yourself. When an address is on both sides the card says so, because the
alternative is somebody concluding the block is broken.

A URL list refreshes on its own timer (12 h by default). **A failed download changes nothing**: the
last good copy stays in force and the failure is shown. A 404 must not silently open a door that was
closed — or close one that was open.

Nothing reaches the firewall until **Push to firewall**, which is the one action that asks for the
password; everything before it touches the panel only. Lists travel to the root helper as a file, not
as arguments — a country zone does not fit in argv — validated line by line on both sides, in one awk
pass rather than a shell function per line: 60 000 entries parse and syntax-check in 1.2 s.

Bounded on purpose: 250 000 entries enabled at once, 100 000 per list, 8 MiB per upload. Schema 34.

### Added — the add-user dialog validates while you type

The public registration form has had live validation since 1.8.0; the admin dialog was posting blind
and finding out from a 400. The password rules in particular are not guessable from a sentence — you
found out which of the five you had missed only after pressing the button. Username, address and all
five password requirements are now checked as you type, against the same rules the server enforces,
and Save stays disabled until they pass. The generated password satisfies them on open.

### Fixed — the public catalogue's own first page was a 2 890 ms full scan

No search typed, no filter, the state a visitor arrives in: a full scan and a filesort of 1.9 M rows.
Two causes, both only visible on the real table.

`seeders` is the alias of `COALESCE(scrape_seeders, last_seeders)` — an expression, and no index can
serve an ORDER BY over one. `index_hashes` now carries `eff_seeders`, a **VIRTUAL** column holding
exactly that expression, with an index on it: virtual means stored nowhere and written never, so
adding it touched no rows and cost no space, and what it buys is the right to index the expression.
The catalogue names the column instead of the expression, and the same page is **1 ms** (11 ms at
page 40). The index build took 8.8 s, online.

Two more things went with it. The single-arm query no longer wraps itself in `SELECT * FROM (…) cat`,
which was forcing 184 000 rows into a temporary table to return 25; when the whitelist arm is
included, each arm is now ordered and cut to `offset + per_page` **before** the merge, since no arm
can contribute a row past that position. And with no search the relevance column is the literal `0` —
leading an ORDER BY with a constant sorts nothing and stops the optimiser recognising the rest as an
index order, so it is dropped rather than carried along.

The unfiltered total is cached for two minutes (it was 1 102 ms of full scan per page view for an
answer that changes when the index poll runs, not when the reader clicks).

### Fixed — "times seen" on the admin index listing, 2 311 ms → 1 ms

One index, `(seen_count)`. Deliberately one and not nine: every unindexed sort on that table costs
about the same (name 2 186 ms, peak_seeders 1 986 ms, files_count 1 951 ms, scrape_seeders 1 966 ms,
total_size 1 930 ms, last_leechers 1 876 ms, first_seen 1 870 ms, meta_status 1 846 ms), and the
table already carries **2 738 MiB of index against 584 MiB of data** while the index poll rewrites
hundreds of thousands of rows every thirty minutes and pays for every index on every row. The line is
drawn at the column the fetch-order work made worth looking at. The rest stay a filesort on a
deliberate click — recorded in `includes/schema.php` as a choice, with the numbers, rather than left
as an oversight. Schema 35.

### Fixed — banning a list of hashes was one round-trip per hash

`whitelistBan()` serves a moderator banning one hash and the import path banning tens of thousands
through the same code. It ran one INSERT per hash and then called `repClear()` per hash — a DELETE
that finds nothing for almost every one of them, because `hash_votes` holds the handful of things
people actually rated. Both are now chunked: measured over 2 000 hashes on a local database,
**1 276 ms → 27 ms** for the insert and **316 ms → 57 ms** for the vote clean-up, and the count of
genuinely new bans is asked for rather than inferred from `rowCount()` (a multi-row
`INSERT … ON DUPLICATE KEY` reports 1 per insert and 2 per update, which does not separate).

### Fixed — the blacklist file's read-modify-write had no lock across the read

`addHashToBlacklist()` checked whether the hash was present and then appended with `LOCK_EX`; the
check itself held nothing, so two requests banning the same hash could both read "absent" and both
append it. `removeHashFromBlacklist()` rewrites the whole file, so a delete could equally throw away
an append that landed between its read and its rename. Both now hold an exclusive lock across the
read **and** the write. It cannot live on the file being replaced — `rename()` swaps the inode out
from under it — so it is a sibling `.lock` file, and where the directory cannot hold one the work
still runs unlocked, exactly as before: a missing lock must not turn a ban into a failure.

### Fixed — the firewall helper read the wrong rate back

Found by the existing suite the moment the soft budgets were added, which is what the suite is for.
The chain now holds three `limit rate over` rules — two per-address budgets and the general one — and
`read_limit()` took the first, so the panel reported 8 000 pps on a port limited to 40 000. Worse,
`read_rule_handle()` had the same bug, and it drives the targeted `nft replace` used when only the
rate changes: it would have overwritten a soft set's tighter budget with the general one, quietly
turning "dropped first under pressure" into "treated like everybody else". Both now match on the
absence of `saddr` — the general limit is the one that applies to everyone and therefore names no
address.

## [1.27.3] — 2026-09-03

Three things I had shipped that did not work.

### Fixed — the stability probe was invisible on the Traffic page

`includes/tuner.php` was required by `api.php` and **not** by `index.php`. The Traffic template
decides whether to load `admin-tuner.js` with `function_exists('tunerEnabled')` — which is false on
every page render without that include — so the `<script>` was never emitted and the card stayed
hidden for ever. Meanwhile the endpoint answered `enabled: true` to anyone who asked it directly,
and Settings said "Enabled — the button appears on Traffic".

A feature can be switched on, reachable, correctly configured, and still invisible. One missing
`require_once`.

### Fixed — the search breadcrumb appeared under categories and not under All settings

The first attempt gated it on `group === 'all'`, which is wrong twice over. A search **ignores the
group filter entirely** — it always ranks every section — so `group` says nothing about what is on
screen. And `openHash()` sets `group` behind the reader's back whenever the page is opened at a
`#section` anchor, which is how the Traffic and Index pages link into Settings. The result was
exactly the inversion reported.

It is now tied to the only thing that matters: whether a search is running. Verified across every
entry path — fresh load, after a group click, after a `#anchor` entry — breadcrumbs appear only with
a query typed and disappear the moment it is cleared.

### Fixed — the Add user dialog did not match the panel

`border-secondary` on `.modal-content` overrode the panel's own `#2a2a2a` border with Bootstrap's
light grey — the pale line across the dialog — and the same class on the header and footer overrode
`--bs-modal-header-border-color`. The controls were also full-size where every other dialog here uses
`-sm`. Measured after: all three borders are `rgb(42,42,42)`, the panel's own tone.

## [1.27.2] — 2026-09-03

The rest of the audit, plus three things from the last release that were wrong on arrival.

### Fixed — the Add user modal never appeared

It was inserted **inside** another modal's `modal-content`. Bootstrap drew the backdrop and nothing
else. Moved out to be a sibling; all three user modals now sit at the same depth and the dialog
renders (500 × 682, seven fields, one backdrop).

### Fixed — a tie-break that cost 2.3 seconds a page

The admin index listing ordered by `last_seen DESC, info_hash ASC`. InnoDB carries the primary key
inside every secondary index, so `idx_index_last_seen` is really `(last_seen, info_hash)` — ordering
both the same way is a backward scan, and mixing the directions is a sort no index can serve.
Measured on the 2 M-row table:

```
last_seen DESC, info_hash ASC     2 327 ms   filesort
last_seen DESC, info_hash DESC      112 ms   no filesort
```

The tie-break now takes the direction of the sort it is breaking ties for. Determinism is what it is
for and is unchanged.

### Fixed — the cap-prune materialised its whole work list

`SELECT` every excess hash into one PHP array, then delete in chunks. With the cap at 3 500 000 and
the table having run at 2.9 M, a lowered cap makes that array arbitrarily large — a million hashes is
~100 MB inside php-fpm, whose memory limit decides whether the prune finishes or the request dies
half-way. Now deleted in bounded passes; identical work, bounded peak.

### Fixed — parallel sign-in failures collapsed into one

`recordLoginFailure` read the attempt file, appended, and wrote it back with `LOCK_EX` on the *write*
only. Two failures arriving together both read the same array and the second write won — so a burst
of parallel guesses counted as one, and the lockout never tripped. That is precisely the case the
lockout exists for. Now one lock across the whole read-modify-write, like every other state file
here. Verified: eight parallel failures used to collapse, now all eight are recorded.

### Fixed — "Throttle hard" could become permanent

`netlimitPanicRestore` cleared the panic record **before** checking whether the restore worked. A
failed restore therefore left the emergency 10 000 pps clamp in force with nothing left for the
janitor to retry — while it went on reporting `panic=restored`. The record is now cleared only on
success, and a failure is recorded so the next tick tries again.

### Fixed — reporting a failure as success, and a success as failure

- `tracker-netlimit.sh off` deleted the table and *then* failed on the file removal, reporting the
  whole operation as failed — while the firewall had in fact been opened. The limit being off is not
  a failure; failing to make that permanent is a different thing, and it is the normal deferred case
  on this machine. Now said separately.
- `tracker-instance.sh reset` reported `ok:true, removed:false` when the removal failed, which the
  panel renders as "nothing to remove" about a file still overriding the unit.
- `tracker-cluster.sh create` left the per-instance systemd drop-in behind when it rolled back a
  failed instance — invisible until the same name is created again, and then yesterday's CPU
  affinity silently applies to a fresh one.

### Security — a systemd drop-in written from an unvalidated argument

`tracker-cluster.sh create` wrote its affinity argument verbatim into a root-owned drop-in. A newline
in it is a new directive, and `ExecStartPre=` in a unit is a root shell. `CPUAffinity` takes CPU
numbers, commas and ranges; that is now all it accepts. Verified both ways: an injected
`[Service]
ExecStartPre=…` is refused and `0-3,5` still passes.

### Fixed — a moderation decision the audit log was dropping

`auditIsNoise()` suppressed anything ending in `_status`, which is right for the pollers a page runs
every few seconds and wrong for `admin/change_status` — a moderation write that is listed in the
action map. Named as an exception rather than widening the rule, because a rule matching by name will
do this again to the next endpoint somebody names that way.

### Fixed — `whitelist_cli add` recorded the wrong source

`in_array($opts['source'] ?? 'admin', …) ? $opts['source'] : 'admin'` tests the default and then
returns the *undefined* key, so `add` without `--source` stored an empty source instead of `admin`.

### Changed — where a search hit lives, and what an empty queue looks like

The **Show me where** line now appears only while the group filter is *All settings* — once a group
is chosen the reader knows where they are, and a breadcrumb on every section is noise.

The **Rewrites** tab was a sentence squeezed into a search toolbar it does not use, above a bordered
box reading "Nobody has proposed a rewrite" — which looks like something failed to load. An empty
moderation queue is the normal state and now looks like one.

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
