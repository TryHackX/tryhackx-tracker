<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/users.php',
          'includes/lang.php', 'includes/facts.php'] as $f) require_once $root . '/' . $f;

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$cfg = getSettings($db, true);
$GLOBALS['cfg'] = $cfg;
langInit([], 'en');
$all = in_array('--all', $argv, true);
if ($all && factsTableReady($db)) $db->exec("UPDATE desc_facts SET ver = 0");
$total = 0;
do {
    $n = factsTick($db, 200);
    $total += $n;
    if ($n > 0) echo "read $n descriptions\n";
} while ($n > 0);
echo "done: $total descriptions read, " . (int)$db->query("SELECT COUNT(*) FROM desc_facts")->fetchColumn() . " with facts\n";

if (in_array('--names', $argv, true)) {
    if (factsMode($cfg) !== 'desc_name') { echo "names: the setting reads descriptions only — nothing to do\n"; exit(0); }
    if ($all) {
        $db->exec("REPLACE INTO settings (`key`, `value`) VALUES ('name_facts_cursor', '')");
        $cfg['name_facts_cursor'] = '';
    }
    $names = 0;
    do {
        $r = factsNameTick($db, $cfg, 60.0);
        $names += $r['read'];
        $cfg = getSettings($db, true);
        echo "names with facts so far: $names\n";
    } while (!$r['done']);
    echo "names done: " . (int)$db->query("SELECT COUNT(*) FROM name_facts")->fetchColumn() . " rows\n";
}
