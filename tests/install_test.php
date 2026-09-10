<?php
/**
 * A FRESH INSTALL AND AN UPGRADED ONE MUST BE THE SAME DATABASE (needs the local test server):
 *
 *   php tests/install_test.php
 *
 * ── why this test exists ───────────────────────────────────────────────────────────────────────
 * install.php used to build the tables from trackerSchemaStatements() and then stamp
 * schema_version = TRACKER_SCHEMA_VERSION, on the reasoning that everything had just been created.
 * It had not. A column added after its table was written lives in trackerSchemaGuardedStatements();
 * a permission added later lives in trackerSchemaDataMigrations(); and ensureSchema() returns
 * immediately when the version already matches — so on a fresh install neither ever ran.
 *
 * Measured before the fix, a freshly installed tracker was short of fourteen columns on `whitelist`
 * (source_url, description, content_status, probe_status, dead_since, the four rating columns…),
 * four on `index_hashes`, `users.bulk_optout`, the whole `moderator` group and seven permissions on
 * guest/member. The whitelist page selects some of those by name, so the site did not come up — and
 * nothing in the suite noticed, because every test runs against a database that was UPGRADED.
 *
 * So this builds both, from empty, and compares them: tables, columns, indexes, settings keys and
 * group permissions. It is the only test here that can see that class of mistake at all.
 *
 * It creates and drops two scratch databases and touches nothing else.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/config/database.php')) { fwrite(STDERR, "config/database.php missing — run the local bootstrap first\n"); exit(2); }
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

/* ── the installer's own source, before anything is built ─────────────────── */
// The shape of the fix, not only its effect: somebody reverting to a version stamp would otherwise
// make every check below vacuous by building the two databases the same wrong way.
$inst = (string)@file_get_contents($root . '/install.php');
check('install.php runs the ordinary migration rather than stamping a version',
      str_contains($inst, 'ensureSchema($pdo, $installCfg)') && str_contains($inst, "\$defaults['schema_version'] = '0'"));
check('… and refuses to report a finished install on a half-built schema',
      str_contains($inst, 'the database schema stopped at version'));
check('… with the files those migrations need already loaded',
      str_contains($inst, "includes/users.php") && str_contains($inst, "includes/settings.php"));

/* ── build both, from empty ───────────────────────────────────────────────── */
// The scratch databases hang off the test connection's own server and credentials, so this needs no
// configuration of its own and cannot reach a database anybody cares about.
$live = getDb();
$dsnHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
// The port comes from the connection the tests are already on, not from a constant that a config
// written as a bare DSN — which is how the development machine's is written — never defines. The
// fallback sent every run on a server listening anywhere but 3306 to a closed port, and the suite
// exited 2 with "cannot reach the database server": a red suite that says nothing about install.php.
$dsnPort = (string)($live->query('SELECT @@port')->fetchColumn() ?: (defined('DB_PORT') ? DB_PORT : '3306'));
$dsnUser = defined('DB_USER') ? DB_USER : 'root';
$dsnPass = defined('DB_PASS') ? DB_PASS : '';
$base = "mysql:host=$dsnHost;port=$dsnPort;charset=utf8mb4";
try {
    $adm = new PDO($base, $dsnUser, $dsnPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (\Throwable $e) {
    fwrite(STDERR, "cannot reach the database server to build a scratch schema: " . $e->getMessage() . "\n");
    exit(2);
}
$suffix = bin2hex(random_bytes(3));
$nameFresh = "tracker_it_f_$suffix";
$nameUp = "tracker_it_u_$suffix";

$make = function (string $name) use ($adm, $base, $dsnUser, $dsnPass) {
    $adm->exec("DROP DATABASE IF EXISTS `$name`");
    $adm->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new PDO("$base;dbname=$name", $dsnUser, $dsnPass,
                  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    // The one table install.php writes by hand before it reaches the shared definitions.
    $db->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(64) NOT NULL PRIMARY KEY, `value` TEXT)
               ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (trackerSchemaStatements() as $sql) $db->exec($sql);
    return $db;
};

try {
    // (1) the installer: base tables, defaults, version 0, then ensureSchema.
    $fresh = $make($nameFresh);
    $ins = $fresh->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    foreach (trackerSchemaDefaultSettings() as $k => $v) $ins->execute([$k, $v]);
    $ins->execute(['schema_version', '0']);
    $cfgF = [];
    foreach ($fresh->query("SELECT `key`,`value` FROM settings")->fetchAll() as $r) $cfgF[$r['key']] = $r['value'];
    ensureSchema($fresh, $cfgF);

    // (2) an upgrade from nothing: the same base tables, version 0, the same call. The difference
    //     under test is the INSTALLER's behaviour, so both sides run the migration and the fresh one
    //     must arrive at exactly what the migration produces.
    $up = $make($nameUp);
    $up->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','0')");
    $cfgU = [];
    foreach ($up->query("SELECT `key`,`value` FROM settings")->fetchAll() as $r) $cfgU[$r['key']] = $r['value'];
    ensureSchema($up, $cfgU);

    $ver = fn(PDO $db) => (int)$db->query("SELECT `value` FROM settings WHERE `key`='schema_version'")->fetchColumn();
    check('a fresh install lands on the current schema version', $ver($fresh) === TRACKER_SCHEMA_VERSION,
          $ver($fresh) . ' vs ' . TRACKER_SCHEMA_VERSION);
    check('… and so does an upgrade', $ver($up) === TRACKER_SCHEMA_VERSION, (string)$ver($up));

    /* ── tables ───────────────────────────────────────────────────────────── */
    $tables = function (PDO $db) {
        $t = array_column($db->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
        sort($t);
        return $t;
    };
    $tf = $tables($fresh); $tu = $tables($up);
    check('a fresh install has more than a handful of tables', count($tf) > 20, (string)count($tf));
    check('the two have exactly the same tables', $tf === $tu,
          'fresh missing: ' . implode(', ', array_diff($tu, $tf)) . ' | only on fresh: ' . implode(', ', array_diff($tf, $tu)));

    /* ── columns and indexes, table by table ──────────────────────────────── */
    $colDiffs = [];
    $idxDiffs = [];
    foreach (array_intersect($tf, $tu) as $t) {
        $cf = array_column($fresh->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field');
        $cu = array_column($up->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field');
        sort($cf); sort($cu);
        if ($cf !== $cu) {
            $colDiffs[] = $t . ' [fresh missing: ' . implode(',', array_diff($cu, $cf))
                        . '][only on fresh: ' . implode(',', array_diff($cf, $cu)) . ']';
        }
        $kf = array_unique(array_column($fresh->query("SHOW INDEX FROM `$t`")->fetchAll(), 'Key_name'));
        $ku = array_unique(array_column($up->query("SHOW INDEX FROM `$t`")->fetchAll(), 'Key_name'));
        sort($kf); sort($ku);
        if ($kf !== $ku) $idxDiffs[] = $t . ' [' . implode(',', array_diff($ku, $kf)) . ']';
    }
    check('every table has the same columns in both', $colDiffs === [], implode(' ; ', array_slice($colDiffs, 0, 4)));
    check('… and the same indexes', $idxDiffs === [], implode(' ; ', array_slice($idxDiffs, 0, 4)));

    /* ── settings ─────────────────────────────────────────────────────────── */
    // Keys, not values: the installer writes the operator's answers (site name, admin address) and
    // those are supposed to differ. What must not differ is which settings exist at all — a missing
    // key is a feature whose switch the operator will never find.
    $keys = function (PDO $db) {
        $k = $db->query("SELECT `key` FROM settings")->fetchAll(PDO::FETCH_COLUMN);
        sort($k);
        return $k;
    };
    $kf = $keys($fresh); $ku = $keys($up);
    check('a fresh install knows every setting an upgraded one does', array_diff($ku, $kf) === [],
          implode(', ', array_slice(array_diff($ku, $kf), 0, 8)));
    foreach (['auth_bridge_enabled', 'fav_enabled', 'version_display', 'search_time_budget', 'lang_swap_enabled'] as $k) {
        check("… including $k", in_array($k, $kf, true));
    }

    /* ── groups and permissions ───────────────────────────────────────────── */
    // The half nobody thinks of. A group is a row; a permission is a key inside a JSON column that
    // a MIGRATION writes, so it is precisely what a fresh install skipped.
    $perms = function (PDO $db) {
        $out = [];
        foreach ($db->query("SELECT slug, permissions FROM user_groups ORDER BY slug")->fetchAll() as $r) {
            $j = json_decode((string)$r['permissions'], true) ?: [];
            $k = array_keys(array_filter($j));
            sort($k);
            $out[$r['slug']] = $k;
        }
        return $out;
    };
    $pf = $perms($fresh); $pu = $perms($up);
    check('the same groups exist in both', array_keys($pf) === array_keys($pu),
          'fresh: ' . implode(',', array_keys($pf)) . ' | upgraded: ' . implode(',', array_keys($pu)));
    foreach ($pu as $slug => $want) {
        $have = $pf[$slug] ?? [];
        check("group '$slug' holds the same permissions on a fresh install",
              $have === $want, 'missing: ' . implode(', ', array_diff($want, $have)));
    }
    // And the ones this release added, by name — so a rename cannot make the comparison vacuous.
    foreach (['favourites.use', 'favourites.public', 'favourites.view_others', 'uploads.public'] as $perm) {
        check("member gets $perm out of the box", in_array($perm, $pf['member'] ?? [], true));
    }
    check('the seeded moderator group exists on a fresh install', isset($pf['moderator']));
    check('… and it is not empty', count($pf['moderator'] ?? []) > 10, (string)count($pf['moderator'] ?? []));

    /* ── the grant markers ────────────────────────────────────────────────── */
    // schemaGrantOnce() records that it has run. If the markers are absent on a fresh install, the
    // grants did not happen — and the next upgrade would apply them to a database that already had
    // them, which is harmless but means this test passed for the wrong reason.
    $marks = $fresh->query("SELECT `key` FROM settings WHERE `key` LIKE 'schema_grant_%'")->fetchAll(PDO::FETCH_COLUMN);
    check('the grant markers are recorded on a fresh install too', count($marks) >= 3, implode(', ', $marks));

} finally {
    try { $adm->exec("DROP DATABASE IF EXISTS `$nameFresh`"); } catch (\Throwable $e) { /* leave it for a person */ }
    try { $adm->exec("DROP DATABASE IF EXISTS `$nameUp`"); } catch (\Throwable $e) { /* same */ }
}

echo "\n$n checks, $fails failed\n";
exit($fails > 0 ? 1 : 0);
