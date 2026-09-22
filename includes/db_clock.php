<?php
/**
 * Publishing the clock the panel's database session runs in.
 *
 * `config/database.php` does `SET time_zone = date('P')`, so MySQL's NOW() agrees with PHP's date()
 * for every panel request. That is the right call on its own — but it makes the zone a property of
 * PHP's configuration rather than of the database, and NOTHING ELSE connecting to the same database
 * can know what it is. The metadata worker connects with pymysql and gets the server's SYSTEM zone;
 * on this machine that is CEST while PHP is UTC, so the worker wrote `meta_fetched_at` two hours
 * ahead of everything the panel wrote, and the panel's `meta_requested_at <= NOW()` gate — the one
 * that spreads an auto-queue over an hour — opened two hours early.
 *
 * The fix is not to guess on either side. The panel writes down which zone it is using; anything
 * else that touches these tables reads that and matches it.
 */

/** Does this string look like something safe to hand to `SET time_zone`? */
function dbClockValidZone(string $tz): bool {
    // Either a numeric offset (+00:00, -05:30) or a named zone. Named zones are allowed because an
    // operator may have configured one, but the character set is kept narrow: this value ends up in
    // a SET statement on two different clients.
    return (bool)preg_match('/^[+-](?:[01]\d|2[0-3]):[0-5]\d$/', $tz)
        || (bool)preg_match('%^[A-Za-z][A-Za-z0-9_+-]*(?:/[A-Za-z0-9_+-]+){0,2}$%', $tz);
}

/**
 * Keep `db_time_zone` equal to the zone this process is actually using.
 *
 * Called from the janitor, so it costs one cheap read a minute and one write on the rare occasion
 * the value moves (a PHP timezone change, or a DST step where `date('P')` is an offset). Returns the
 * value in force.
 */
function dbClockPublish(PDO $db): string {
    $tz = (string)date('P');
    if (!dbClockValidZone($tz)) return '';
    try {
        $st = $db->prepare("SELECT `value` FROM settings WHERE `key` = 'db_time_zone' LIMIT 1");
        $st->execute();
        $have = (string)($st->fetchColumn() ?: '');
        if ($have !== $tz) {
            $db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('db_time_zone', ?)
                          ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$tz]);
        }
    } catch (\Throwable $e) {
        return '';
    }
    return $tz;
}

/* ── the zone a READER sees times in (1.62.0, schema 68) ─────────────────────────────────────────
 *
 * Everything above is about the clock the DATABASE keeps. This half is about the clock a PERSON
 * reads, and the two must never be confused: a DATETIME column holds a wall-clock string in the
 * database session's zone, which getDb() sets to PHP's own offset — `+00:00` on the development
 * machine, whatever php.ini says on the server. Neither of those is where anybody reading the page
 * lives. The shoutbox used to slice the hour straight out of that string, so every reader on the
 * site saw PHP's hour, and a room full of people in Warsaw read a conversation two hours in the past.
 *
 * So a time travels as an INSTANT, never as a string: the database converts its own DATETIME with
 * UNIX_TIMESTAMP(), which reads the value in the same session zone it was written in, and only then
 * does PHP put the instant into a reader's zone. `new DateTime($row['created_at'])` would read the
 * string in PHP's zone instead — right on a machine where the two happen to agree, and hours off on
 * the first one where they do not, which is exactly the class of bug the top of this file records.
 *
 * One caveat that belongs to the storage, not to this code: the session zone is an OFFSET taken when
 * the row was written, so on a server whose PHP runs in a zone with summer time a row written before
 * the change is read back an hour out after it. A server whose PHP runs in UTC (the Debian default,
 * and this machine) has no such hour.
 */

/** Is this an IANA zone name this PHP knows? The one test `site_timezone` and `users.timezone` pass. */
function tzValidName(string $tz): bool {
    static $known = null;
    if ($tz === '') return false;
    // A set rather than in_array() over 419 names: the shoutbox asks this once per request, the
    // Settings page and the account page once per <option>.
    if ($known === null) $known = array_fill_keys(DateTimeZone::listIdentifiers(), true);
    return isset($known[$tz]);
}

/**
 * The site's display zone, as a name that is always valid.
 *
 * `site_timezone` when the operator chose one. Until they have, the zone the operator already told
 * the SCHEDULE they run in (`tracker_schedule_tz`), because somebody who said "the tracker switches
 * mode at 22:00 Warsaw time" has said where they are — and only when that is missing or broken, the
 * zone PHP itself runs in. Decided on READ rather than written once by a migration, like every clamp
 * in this codebase: a settings row can arrive from a restored backup or a MySQL client, and the
 * answer to an empty or broken one is the next-best fact rather than a page that cannot tell the time.
 */
function siteTimezone(array $cfg): string {
    $own = trim((string)($cfg['site_timezone'] ?? ''));
    if (tzValidName($own)) return $own;
    $sched = trim((string)($cfg['tracker_schedule_tz'] ?? ''));
    if (tzValidName($sched)) return $sched;
    $php = date_default_timezone_get();
    return tzValidName($php) ? $php : 'UTC';
}

/**
 * The zone THIS reader sees times in: their own when they chose one, the site's otherwise.
 *
 * NULL in `users.timezone` means "the site's", not a zone of its own — so an operator who moves the
 * site's zone moves everybody who never chose, and nobody who did is overruled. A name this PHP does
 * not know (a zone removed from the tz database, a hand-edited row) is treated the same way: the
 * reader gets the site's clock rather than an exception or UTC. A guest has no row and gets the site's.
 */
function userDisplayTimezone(?array $user, array $cfg): DateTimeZone {
    $own = trim((string)($user['timezone'] ?? ''));
    return new DateTimeZone(tzValidName($own) ? $own : siteTimezone($cfg));
}

/**
 * An instant, written for a reader in their zone. The formatter that goes with the helper above.
 *
 * Takes a UNIX TIME and nothing else, on purpose: a DATETIME string has already lost the one fact
 * this needs (which zone it was written in), so the caller asks the database for UNIX_TIMESTAMP()
 * of the column and hands that over. `$format` is PHP's date() vocabulary — 'H:i' for a list,
 * 'Y-m-d H:i:s P' for a title that says which offset it is in.
 */
function userDisplayTime(int $unix, DateTimeZone $tz, string $format = 'Y-m-d H:i:s P'): string {
    return (new DateTimeImmutable('@' . $unix))->setTimezone($tz)->format($format);
}

/** "UTC+02:00" — a zone's offset NOW (or at `$at`), the way both selects print it beside the name. */
function tzOffsetLabel(DateTimeZone $tz, ?int $at = null): string {
    $off = $tz->getOffset(new DateTimeImmutable('@' . ($at ?? time())));
    $abs = abs($off);
    return 'UTC' . ($off < 0 ? '-' : '+') . sprintf('%02d:%02d', intdiv($abs, 3600), intdiv($abs % 3600, 60));
}

/**
 * Every zone, grouped by its region, each with its current offset: [region => [name => label]].
 *
 * One list for the two selects that offer it — Settings → Site and the account page — so the two can
 * never disagree about which zones exist or how an offset is written. "Current" because that is what
 * a person choosing is asking ("which of these is my clock right now"); the name is what is stored,
 * so a zone that changes its offset in winter still means the right thing in winter.
 *
 * `$short` (1.64.0) writes the label WITHOUT the region it is already filed under, because a native
 * select's open list is as wide as its longest option and nothing in CSS can narrow it: one
 * "America/Argentina/Buenos_Aires (UTC-03:00)" made the whole list wider than the control it drops
 * out of, on every screen, for every reader. The prefix is not information at that point — the
 * optgroup above the option is already saying "America" — and the underscores are a filename
 * convention rather than a place name. So: "Argentina / Buenos Aires (UTC-03:00)", filed under
 * America. A zone with no region ("UTC") has nothing to strip and is written as it is.
 */
function tzChoices(?int $at = null, bool $short = false): array {
    $at = $at ?? time();
    $out = [];
    foreach (DateTimeZone::listIdentifiers() as $id) {
        $slash = strpos($id, '/');
        $region = $slash === false ? 'Other' : substr($id, 0, $slash);
        $name = ($short && $slash !== false)
            ? str_replace('_', ' ', str_replace('/', ' / ', substr($id, $slash + 1)))
            : $id;
        $out[$region][$id] = $name . ' (' . tzOffsetLabel(new DateTimeZone($id), $at) . ')';
    }
    return $out;
}
