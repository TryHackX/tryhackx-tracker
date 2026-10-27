<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
foreach (['config/app.php', 'config/database.php', 'includes/settings.php', 'includes/functions.php', 'includes/schema.php',
          'includes/whitelist.php', 'includes/index.php', 'includes/richtext.php', 'includes/content.php', 'includes/users.php',
          'includes/lang.php', 'includes/facts.php'] as $f) require_once $root . '/' . $f;

$db = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
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
