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
