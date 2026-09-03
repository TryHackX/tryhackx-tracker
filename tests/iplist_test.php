<?php
/**
 * Tests for the address lists — allow / block / block-under-pressure:
 *   php tests/iplist_test.php
 *
 * Three halves:
 *   1. the pure PHP of includes/iplist.php — parsing what published lists actually look like,
 *      validation, and the precedence rule that manual entries beat everything.
 *   2. the file handed to the root helper, and the awk parser on the other side of it, driven
 *      through bash exactly as tests/netlimit_test.php drives the rest of the helper.
 *   3. registration: a setting nobody can find, save or see is a setting that does not exist.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/netlimit.php';
require_once $root . '/includes/iplist.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void {
    global $skips;
    $skips++;
    echo 'SKIP ' . $name . '  -> ' . $why . "\n";
}

// ── 1. what counts as an address ─────────────────────────────────────────────
foreach (['1.2.3.4', '1.2.3.0/24', '0.0.0.0/0', '10.0.0.1/32', '2001:db8::1', '2a02:c207::/32', '::/0'] as $good) {
    check("valid: $good", ipListValidCidr($good));
}
foreach (['', 'hello', '1.2.3', '1.2.3.4.5', '999.1.2.3', '1.2.3.0/33', '2001:db8::/129', '1.2.3.0/', '1.2.3.0/x',
          '1.2.3.4 ', 'ftp://x'] as $bad) {
    check("rejected: '" . $bad . "'", !ipListValidCidr($bad));
}
check('family is read from the address, not guessed', ipListFamily('1.2.3.0/24') === 4 && ipListFamily('2001:db8::/32') === 6);

// ── 2. parsing a file as published ───────────────────────────────────────────
// These shapes are not hypothetical: ipdeny ships bare CIDRs with LF, some mirrors ship CRLF, and
// several public lists append a country code or a note after the address.
$raw = "# China\r\n1.0.1.0/24\r\n1.0.2.0/23\r\n\r\n; a semicolon comment\n1.0.8.0/21 # note\n"
     . "2.2.2.0/24,CN\n1.0.1.0/24\nnot-an-address\n999.9.9.9\n  5.5.5.5  \n";
$p = ipListParse($raw);
check('comments and blank lines are skipped', !in_array('#', $p['entries'], true) && count($p['entries']) === 5, json_encode($p));
check('CRLF does not corrupt the entry', in_array('1.0.1.0/24', $p['entries'], true), json_encode($p['entries']));
check('a trailing note is cut off', in_array('1.0.8.0/21', $p['entries'], true), json_encode($p['entries']));
check('a trailing country code is cut off', in_array('2.2.2.0/24', $p['entries'], true), json_encode($p['entries']));
check('surrounding whitespace is trimmed', in_array('5.5.5.5', $p['entries'], true), json_encode($p['entries']));
check('a repeat is stored once', count(array_keys($p['entries'], '1.0.1.0/24', true)) === 1);
check('bad lines are counted, not silently dropped', $p['skipped'] === 2, (string)$p['skipped']);

$cap = ipListParse(implode("\n", array_map(fn($i) => "10.0.$i.0/24", range(0, 200))), 50);
check('the cap is honoured', count($cap['entries']) === 50, (string)count($cap['entries']));

check('an empty file yields nothing rather than an error', ipListParse('')['entries'] === []);
check('a file of only comments yields nothing', ipListParse("# a\n; b\n\n")['entries'] === []);

// ── 3. precedence: a manual entry beats every list ───────────────────────────
// The whole point of the feature is that a country file somebody downloaded can never shut out an
// address the operator typed in themselves. With no database here, the manual half is what is
// testable directly — and it is the half that matters.
$cfgManual = ['net_limit_trusted' => "203.0.113.10, 198.51.100.0/24\n2001:db8::5"];
$manual = netlimitTrusted($cfgManual);
check('manual entries parse into the allow side', count($manual) === 3, json_encode($manual));

// ── 4. the file handed to the helper ─────────────────────────────────────────
check('the sets file lives under config/', str_ends_with(str_replace('\\', '/', ipListSetsFile()), '/config/net_sets.txt'),
      ipListSetsFile());

// ── 5. the awk parser on the other side ──────────────────────────────────────
$helper = $root . '/tools/opentracker/tracker-netlimit.sh';
$bash = null;
foreach (['bash', '/bin/bash', '/usr/bin/bash', 'C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $cand) {
    $cmd = (str_contains($cand, ' ') ? '"' . $cand . '"' : $cand) . ' -c "echo ok" 2>&1';
    $out = []; $rc = null;
    @exec($cmd, $out, $rc);
    if ($rc === 0 && trim(implode('', $out)) === 'ok') { $bash = $cand; break; }
}
if ($bash === null || !trackerExecAvailable()) {
    skip('helper: the sets parser', 'no usable bash (or exec() disabled) on this machine');
} else {
    $tmp = sys_get_temp_dir() . '/iplist_test_' . getmypid();
    @mkdir($tmp, 0777, true);
    $posix = static fn(string $p): string => str_replace('\\', '/', $p);
    // A stub nft that only has to accept the syntax check: what is under test is the file the helper
    // GENERATES, which --dry-run prints verbatim.
    file_put_contents($tmp . '/nft', "#!/bin/bash\nexit 0\n");
    @chmod($tmp . '/nft', 0755);
    file_put_contents($tmp . '/sets.txt', implode("\n", [
        'allow 203.0.113.0/24',
        'allow 2001:db8::/32',
        'block 5.188.0.0/16',
        'block 45.9.148.0/24',
        'soft 91.240.118.0/24',
        'soft 2a02:c207::/32',
        'block not-an-address',
        'block 999.1.2.3',
        'block 1.2.3.0/33',
        'weird 8.8.8.8',
    ]) . "\n");

    putenv('NFT_BIN=' . $posix($tmp) . '/nft');
    putenv('NETLIMIT_SPOOL=' . $posix($tmp) . '/spool');
    $bashCmd = str_contains($bash, ' ') ? '"' . $bash . '"' : $bash;
    $out = []; $rc = null;
    @exec($bashCmd . ' ' . escapeshellarg($posix($helper))
          . ' set 30000 100 6969 --sets=' . escapeshellarg($posix($tmp) . '/sets.txt') . ' --dry-run 2>&1', $out, $rc);
    $txt = implode("\n", $out);
    $json = null;
    foreach (array_reverse($out) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] === '{') { $json = json_decode($line, true); if (is_array($json)) break; $json = null; }
    }
    $rules = is_array($json) ? (string)($json['ruleset'] ?? '') : '';
    $counts = is_array($json) ? (array)($json['lists'] ?? []) : [];

    check('helper: a sets file is accepted', $rc === 0 && is_array($json) && !empty($json['ok']), $txt);
    check('helper: v4 and v6 go to different sets',
          ($counts['allow4'] ?? null) === 1 && ($counts['allow6'] ?? null) === 1, json_encode($counts));
    check('helper: block and soft are kept apart',
          ($counts['block4'] ?? null) === 2 && ($counts['soft4'] ?? null) === 1 && ($counts['soft6'] ?? null) === 1,
          json_encode($counts));
    // Three bad addresses and one unknown kind. None may become a firewall rule, and the apply must
    // not fail because of them either: one fat-fingered line cannot leave the tracker unprotected.
    check('helper: a bad address never becomes an element',
          !str_contains($rules, '999.1.2.3') && !str_contains($rules, '1.2.3.0/33'), $rules);
    check('helper: an unknown kind is ignored', !str_contains($rules, '8.8.8.8'), $rules);
    check('helper: what was dropped is reported', str_contains($txt, 'dropped 4 list entries'), $txt);

    // Order is the feature. Allow before block before soft before the general limit, or "an address
    // you typed in yourself is never dropped" is a sentence in a comment rather than a property of
    // the ruleset that is loaded.
    $pTrust = strpos($rules, '@trusted4');
    $pa = strpos($rules, '@allow4'); $pb = strpos($rules, '@block4'); $ps = strpos($rules, '@soft4');
    $pg = strpos($rules, "\n        limit rate over 30000/second");
    check('helper: the manual list is matched first of all', $pTrust !== false && $pa !== false && $pTrust < $pa);
    check('helper: allow is matched before block', $pa !== false && $pb !== false && $pa < $pb);
    check('helper: block is matched before the soft budget', $pb !== false && $ps !== false && $pb < $ps);
    check('helper: … and every list is matched before the general limit',
          $ps !== false && $pg !== false && $ps < $pg, substr($rules, -400));
    check('helper: allow accepts, block drops', str_contains($rules, '@allow4 counter name in_passed accept')
        && str_contains($rules, '@block4 counter name in_blocked drop'), $rules);
    // A fifth of the general limit: at rest these sources are nowhere near it, and as arrivals climb
    // they are the first to hit a budget. That is what "only when the machine is busy" means here.
    check('helper: the soft rule carries its own, smaller budget',
          str_contains($rules, '@soft4 limit rate over 6000/second'), $rules);
    check('helper: the general limit is unchanged by any of it',
          str_contains($rules, 'limit rate over 30000/second burst 100 packets counter name in_capped drop'), $rules);
    check('helper: the two new counters exist',
          str_contains($rules, 'counter in_blocked') && str_contains($rules, 'counter in_softcap'), $rules);
    check('helper: eight sets are emitted, empty ones included', substr_count($rules, 'flags interval') === 8, $rules);
    check('helper: the header carries a list fingerprint',
          (bool)preg_match('/# tracker-netlimit:.*lists=\d+/', $rules), $rules);

    // No --sets at all must NOT clear the lists: the automatic mode changes the rate every ten
    // minutes, and that must not quietly drop the country blocks somebody imported.
    $out2 = []; $rc2 = null;
    @exec($bashCmd . ' ' . escapeshellarg($posix($helper)) . ' set 40000 100 6969 --dry-run 2>&1', $out2, $rc2);
    $j2 = null;
    foreach (array_reverse($out2) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] === '{') { $j2 = json_decode($line, true); if (is_array($j2)) break; $j2 = null; }
    }
    check('helper: omitting --sets keeps whatever the spool holds', $rc2 === 0 && is_array($j2) && !empty($j2['ok']),
          implode("\n", $out2));

    // An EMPTY file is how the master switch clears them — the panel writes one when the feature is
    // switched off, and the sets have to actually go away rather than being left loaded.
    file_put_contents($tmp . '/empty.txt', '');
    $out3 = []; $rc3 = null;
    @exec($bashCmd . ' ' . escapeshellarg($posix($helper))
          . ' set 30000 100 6969 --sets=' . escapeshellarg($posix($tmp) . '/empty.txt') . ' --dry-run 2>&1', $out3, $rc3);
    $j3 = null;
    foreach (array_reverse($out3) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] === '{') { $j3 = json_decode($line, true); if (is_array($j3)) break; $j3 = null; }
    }
    check('helper: an empty file clears every set',
          is_array($j3) && array_sum(array_map('intval', (array)($j3['lists'] ?? [1]))) === 0, implode("\n", $out3));
    check('helper: … and the ruleset still loads without them',
          is_array($j3) && !empty($j3['ok']) && str_contains((string)($j3['ruleset'] ?? ''), 'set block4'),
          implode("\n", $out3));

    putenv('NFT_BIN');
    putenv('NETLIMIT_SPOOL');
    foreach (['sets.txt', 'empty.txt', 'nft'] as $f) @unlink($tmp . '/' . $f);
    @unlink($tmp . '/spool/sets.txt'); @rmdir($tmp . '/spool'); @rmdir($tmp);

    // The regression that the netlimit suite caught: three `limit rate over` rules now live in the
    // chain, and reading the FIRST one reported a fifth of the real limit. Worse, the targeted
    // replace would have overwritten a soft budget with the general one.
    $src = (string)file_get_contents($helper);
    check('helper: read_limit ignores the per-address budgets',
          (bool)preg_match('/read_limit\(\).{0,400}?!\/saddr\//s', $src));
    check('helper: read_burst ignores them too',
          (bool)preg_match('/read_burst\(\).{0,400}?!\/saddr\//s', $src));
    check('helper: the in-place replace targets the general rule',
          (bool)preg_match('/read_rule_handle\(\).{0,600}?!\/saddr\//s', $src));
}

// ── 6. registration: four places, or it does not exist ───────────────────────
$schema  = (string)file_get_contents($root . '/includes/schema.php');
$save    = (string)file_get_contents($root . '/api/admin/save_settings.php');
$catalog = (string)file_get_contents($root . '/includes/settings_catalog.php');
$tpl     = (string)file_get_contents($root . '/templates/admin/settings.php');
foreach (['net_lists_enabled', 'net_lists_ttl_default'] as $key) {
    check("$key: has a schema default", str_contains($schema, "'$key'"));
    check("$key: is saveable", str_contains($save, "'$key'"));
    check("$key: is findable in the settings search", str_contains($catalog, "'$key'"));
    check("$key: has a control on the settings page", str_contains($tpl, 'name="' . $key . '"'));
}
check('net_lists_stamp is internal — not exposed to the save allow-list',
      str_contains($schema, "'net_lists_stamp'") && !str_contains($save, "'net_lists_stamp'"));
check('the schema version was bumped for the new tables',
      (bool)preg_match('/TRACKER_SCHEMA_VERSION = (\d+)/', $schema, $m) && (int)$m[1] >= 34, $m[1] ?? '?');
check('both tables are created', str_contains($schema, 'CREATE TABLE IF NOT EXISTS `ip_lists`')
    && str_contains($schema, 'CREATE TABLE IF NOT EXISTS `ip_list_entries`'));

$api = (string)file_get_contents($root . '/api.php');
check('the read endpoint is routed', str_contains($api, "'admin/ip_lists'"));
check('the write endpoint is routed', str_contains($api, "'admin/ip_list_action'"));
check('reading is part of reading the Traffic page', str_contains($api, "'admin/ip_lists'           => 'panel.traffic.view'"));
// The controls stay with the owner. An endpoint absent from the permission map is owner-only, which
// is how every other control on that page behaves; an entry here would be a quiet promotion.
check('writing is owner-only (no permission entry)',
      !preg_match("/'admin\\/ip_list_action'\\s*=>\\s*'panel\\./", $api));

$audit = (string)file_get_contents($root . '/includes/audit.php');
check('changes are audited', str_contains($audit, "'admin/ip_list_action'        => 'iplist.change'"));
check('… under the machine group', str_contains($audit, "'iplist.change'"));

$janitor = (string)file_get_contents($root . '/tools/janitor.php');
check('the janitor refreshes URL lists', str_contains($janitor, 'ipListTick('));
check('… and only reloads the firewall when the content moved', str_contains($janitor, 'net_lists_stamp'));

$card = (string)file_get_contents($root . '/templates/admin/traffic.php');
check('the card is on the Traffic page', str_contains($card, 'id="iplists-card"'));
check('… and its script is loaded', str_contains($card, 'admin-iplists.js'));
$js = (string)file_get_contents($root . '/assets/js/admin-iplists.js');
check('the push is the only action that asks for the password',
      substr_count($js, 'promptPassword(') === 1 && str_contains($js, "op: 'push'"));

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails ? 1 : 0);
