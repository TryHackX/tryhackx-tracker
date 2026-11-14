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

// ── 3b. containment: which manual entries a list actually covers ─────────────
//
// The question the card answers is "why is this host still getting through?", and the answer is
// almost never that two strings were equal — it is that a /32 sits inside a /16 somebody imported.
foreach ([
    [['5.188.1.7'],       ['5.188.0.0/16'],   1, 'a host inside a /16'],
    [['5.188.0.0/16'],    ['5.188.1.7'],      1, 'and the block inside the manual entry'],
    [['10.0.0.0/8'],      ['10.1.0.0/16'],    1, 'a /8 contains a /16'],
    [['10.1.0.0/16'],     ['10.0.0.0/8'],     1, 'and a /16 sits inside a /8'],
    [['1.2.3.4'],         ['1.2.3.5'],        0, 'two different hosts'],
    [['1.2.3.0/24'],      ['1.2.4.0/24'],     0, 'adjacent /24s'],
    [['203.0.113.7'],     ['0.0.0.0/0'],      1, '/0 covers everything of its family'],
    [['0.0.0.0/0'],       ['203.0.113.7'],    1, 'from either side'],
    [['1.2.3.4/32'],      ['1.2.3.4'],        1, '/32 and the bare host are the same address'],
    [['2001:db8::1'],     ['2001:db8::/32'],  1, 'IPv6 nests the same way'],
    [['2001:db8::/32'],   ['2001:db9::/32'],  0, 'two different v6 blocks'],
    [['1.2.3.4'],         ['2001:db8::1'],    0, 'a v4 entry can never meet a v6 one'],
    [['0.0.0.0/0'],       ['::/0'],           0, 'not even the two /0s'],
    [['1.2.3.128/25'],    ['1.2.3.0/25'],     0, 'the two halves of a /24 do not touch'],
    [['1.2.3.128/25'],    ['1.2.3.129'],      1, 'but the upper half holds its own host'],
    [['1.2.3.128/25'],    ['1.2.3.127'],      0, 'and not the address below it'],
    [['1.2.3.4', '9.9.9.9'], ['1.2.3.0/24', '8.8.8.0/24'], 1, 'only the one that actually overlaps'],
] as [$m, $b, $want, $why]) {
    check('overlap: ' . $why, count(ipListOverlapsWith($m, $b)) === $want,
          json_encode([$m, $b, ipListOverlapsWith($m, $b)]));
}
check('overlap: nothing on either side is not a conflict',
      ipListOverlapsWith([], ['1.2.3.0/24']) === [] && ipListOverlapsWith(['1.2.3.4'], []) === []);
check('overlap: an unparseable entry is skipped, not treated as a match',
      ipListOverlapsWith(['not-an-address'], ['0.0.0.0/0']) === []);
check('overlap: each manual entry is reported at most once',
      count(ipListOverlapsWith(['1.2.3.4'], ['1.2.3.0/24', '1.2.0.0/16', '1.0.0.0/8'])) === 1);

// The cost is the reason it is an index and not a product: 71 seconds became under a second, on a
// card that refreshes every few seconds.
$bigManual = [];
for ($i = 0; $i < 256; $i++) $bigManual[] = '203.0.' . ($i % 256) . '.7';
$bigBlock = [];
for ($i = 0; $i < 60000; $i++) $bigBlock[] = sprintf('%d.%d.%d.0/24', 10 + intdiv($i, 65536), intdiv($i, 256) % 256, $i % 256);
$t0 = microtime(true);
ipListOverlapsWith($bigManual, $bigBlock);
$ms = (microtime(true) - $t0) * 1000;
check('overlap: 256 manual entries against 60 000 blocks stays under a second', $ms < 1000, round($ms) . ' ms');

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
    // Ten since 1.29.0: trusted4/6, denied4/6 (the hand-typed block box), and the six list sets.
    check('helper: ten sets are emitted, empty ones included', substr_count($rules, 'flags interval') === 10, $rules);
    // The order IS the feature — a hand-typed block must outrank an allow list, or "allow a country
    // except these hosts" cannot be said at all.
    $pDenied = strpos($rules, '@denied4');
    check('helper: the hand-typed block is matched after trusted and before every list',
          $pTrust !== false && $pDenied !== false && $pa !== false && $pTrust < $pDenied && $pDenied < $pa, $rules);
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

// ── 5b. what a list covers, not merely how many lines it has ────────────────
$cov = ipListCoverage(['1.0.1.0/24', '1.0.2.0/23', '2001:db8::/32', '5.5.5.5']);
check('coverage: a /24 is 256 addresses and a /23 is 512', $cov['addr4'] === 256 + 512 + 1, json_encode($cov));
check('coverage: IPv6 entries are counted as ranges, not as addresses', $cov['nets6'] === 1, json_encode($cov));
check('coverage: /0 is the whole of IPv4', ipListCoverage(['0.0.0.0/0'])['addr4'] === 4294967296);
check('coverage: a bare host is one address', ipListCoverage(['1.2.3.4'])['addr4'] === 1);
check('coverage: nothing covers nothing', ipListCoverage([]) === ['addr4' => 0, 'nets6' => 0]);
// The number that answers the operator's actual question — "is 250 000 enough to block China?"
$cnLike = [];
for ($i = 0; $i < 8810; $i++) $cnLike[] = sprintf('%d.%d.%d.0/24', 1 + intdiv($i, 65536), intdiv($i, 256) % 256, $i % 256);
check('coverage: 8 810 /24s are 2.25 million addresses, so entries and addresses are not the same number',
      ipListCoverage($cnLike)['addr4'] === 8810 * 256);

// ── 6. registration: four places, or it does not exist ───────────────────────
// Asked of the code where it can answer (1.74.0, QUAL-7: these were greps of the files): the schema's defaults and
// statements, the settings search's catalogue, the router's table and map, the audit's names. The save allow-list
// and the page's controls are read as the lists they are.
require_once $root . '/includes/schema.php';
require_once $root . '/includes/settings_catalog.php';
require_once $root . '/includes/audit.php';
$defaults = trackerSchemaDefaultSettings();
$catalog  = settingsCatalogKeywords();
$save     = (string)file_get_contents($root . '/api/admin/save_settings.php');
$allowed  = [];
if (preg_match('~\$allowed\s*=\s*\[(.*?)\n\];~s', $save, $am)) {
    foreach (token_get_all('<?php ' . $am[1]) as $t) if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) $allowed[] = trim($t[1], "'\"");
}
$tpl = (string)file_get_contents($root . '/templates/admin/settings.php');
foreach (['net_lists_enabled', 'net_lists_ttl_default', 'net_limit_blocked'] as $key) {
    check("$key: has a schema default", array_key_exists($key, $defaults));
    check("$key: is saveable", in_array($key, $allowed, true));
    check("$key: is findable in the settings search", isset($catalog[$key]) && trim((string)$catalog[$key]) !== '');
    check("$key: has a control on the settings page", str_contains($tpl, 'name="' . $key . '"'));
}
check('net_lists_stamp is internal — a default, but not in the save allow-list',
      array_key_exists('net_lists_stamp', $defaults) && !in_array('net_lists_stamp', $allowed, true));
check('the schema version was bumped for the new tables', TRACKER_SCHEMA_VERSION >= 34, (string)TRACKER_SCHEMA_VERSION);
$stmts = implode("\n", array_map(fn($s) => is_array($s) ? implode("\n", $s) : (string)$s, trackerSchemaStatements()));
check('both tables are created', str_contains($stmts, 'CREATE TABLE IF NOT EXISTS `ip_lists`')
    && str_contains($stmts, 'CREATE TABLE IF NOT EXISTS `ip_list_entries`'));
check('… and the text a manual list was pasted as has a table of its own, kept off the card\'s poll',
      str_contains($stmts, 'CREATE TABLE IF NOT EXISTS `ip_list_sources`') && TRACKER_SCHEMA_VERSION >= 95);
$helper = (string)file_get_contents($root . '/tools/opentracker/tracker-netlimit.sh');
check('the helper accepts --blocked', str_contains($helper, '--blocked=*)'));
check('… and forwards all four optional arguments',
      str_contains($helper, 'action_set "${1-}" "${2-100}" "${3-6969}" "${4-}" "${5-}" "${6-}" "${7-}"'));
check('the hand-typed block is its own set, not mixed into the lists',
      str_contains($helper, 'set denied4') && str_contains($helper, 'set denied6'));

// The router's table and its permission map, read as tables (the same reading as tests/routes_ratchet_test.php).
$api = (string)file_get_contents($root . '/api.php');
$routes = []; $permMap = [];
if (preg_match('~\$apiRoutes\s*=\s*\[(.*?)\n\];~s', $api, $rm)) {
    preg_match_all("~^\s*'([a-z0-9_/]+)'\s*=>\s*'(api/[a-z0-9_/]+\.php)'\s*,~m", $rm[1], $rr, PREG_SET_ORDER);
    foreach ($rr as $x) $routes[$x[1]] = $x[2];
}
if (preg_match('~function adminEndpointPermission\(string \$endpoint\): \?string \{(.*?)\n\}~s', $api, $pm)) {
    preg_match_all("~'(admin/[a-z0-9_]+)'\s*=>\s*'([a-z0-9_.]+)'~", $pm[1], $mm, PREG_SET_ORDER);
    foreach ($mm as $x) $permMap[$x[1]] = $x[2];
}
check('the read endpoint is routed, to its file', ($routes['admin/ip_lists'] ?? '') === 'api/admin/ip_lists.php' && is_file($root . '/api/admin/ip_lists.php'));
check('the write endpoint is routed, to its file', ($routes['admin/ip_list_action'] ?? '') === 'api/admin/ip_list_action.php' && is_file($root . '/api/admin/ip_list_action.php'));
check('reading is part of reading the Traffic page', ($permMap['admin/ip_lists'] ?? '') === 'panel.traffic.view', (string)($permMap['admin/ip_lists'] ?? 'none'));
// The controls stay with the owner. An endpoint absent from the permission map is owner-only, which
// is how every other control on that page behaves; an entry here would be a quiet promotion.
check('writing is owner-only (no permission entry)', count($permMap) > 40 && !isset($permMap['admin/ip_list_action']));

check('changes are audited', auditEndpointAction('admin/ip_list_action') === 'iplist.change', (string)auditEndpointAction('admin/ip_list_action'));
check('… under the machine group', auditGroupOf('iplist.change') === 'machine', auditGroupOf('iplist.change'));

$janitor = (string)file_get_contents($root . '/tools/janitor.php');
check('the janitor refreshes URL lists', str_contains($janitor, 'ipListTick('));
check('… and only reloads the firewall when the content moved', str_contains($janitor, 'net_lists_stamp'));

$card = (string)file_get_contents($root . '/templates/admin/traffic.php');
check('the card is on the Traffic page', str_contains($card, 'id="iplists-card"'));
check('… and its script is loaded', str_contains($card, 'admin-iplists.js'));
$js = (string)file_get_contents($root . '/assets/js/admin-iplists.js');
check('the push is the only action that asks for the password',
      substr_count($js, 'promptPassword(') === 1 && str_contains($js, "op: 'push'"));

$action = (string)file_get_contents($root . '/api/admin/ip_list_action.php');
check('a list can be edited: the write endpoint knows update and wants an existing list',
      preg_match('~\$known = \[[^\]]*\'update\'~', $action) === 1 && preg_match('~\$needId = \[[^\]]*\'update\'~', $action) === 1
      && str_contains($action, 'ipListUpdate('));
check('… a manual list\'s entries are read back as text for the dialog',
      str_contains((string)file_get_contents($root . '/api/admin/ip_lists.php'), 'ipListEntriesText('));
check('… and every row opens the same dialog, which saves through update',
      str_contains($js, 'function openEdit(') && str_contains($js, "op: 'update'") && str_contains($js, "apiCall('admin/ip_lists&id='")
      && str_contains($card, 'id="ipl-modal-title"'));

$sdb = null; $adm = null;
$scratch = 'tracker_ipl_' . bin2hex(random_bytes(3));
if (is_file($root . '/config/database.php')) {
    require_once $root . '/config/database.php';
    try {
        $port = (string)(getDb()->query('SELECT @@port')->fetchColumn() ?: '3306');
        $base = 'mysql:host=' . (defined('DB_HOST') ? DB_HOST : '127.0.0.1') . ';port=' . $port . ';charset=utf8mb4';
        $adm = new PDO($base, defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $adm->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $sdb = new PDO("$base;dbname=$scratch", defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '',
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        foreach (trackerSchemaStatements() as $s) {
            foreach ((array)$s as $sql) {
                if (preg_match('~CREATE TABLE IF NOT EXISTS `ip_list(s|_entries|_sources)`~', $sql)) $sdb->exec($sql);
            }
        }
    } catch (\Throwable $e) {
        $sdb = null;
    }
}
if ($sdb instanceof PDO) {
    $a = ipListCreate($sdb, ['name' => 'Alpha', 'kind' => 'block', 'mode' => 'hard', 'source' => 'manual', 'ttl_minutes' => 60]);
    $b = ipListCreate($sdb, ['name' => 'Beta', 'kind' => 'block', 'mode' => 'soft', 'source' => 'url',
                             'url' => 'https://example.org/b.zone', 'ttl_minutes' => 60]);
    $aId = (int)($a['id'] ?? 0); $bId = (int)($b['id'] ?? 0);
    ipListSetEntries($sdb, $aId, "10.0.0.0/8\n2001:db8::/32\n9.9.9.0/24\n1.2.3.4");
    $txt = ipListEntriesText($sdb, $aId);
    check('db: a list saved without its text reads back as its entries, one per line, IPv4 in address order, then IPv6',
          $txt === ['text' => "1.2.3.4\n9.9.9.0/24\n10.0.0.0/8\n2001:db8::/32", 'original' => false], json_encode($txt));
    check('db: a list with no entries reads back as nothing', ipListEntriesText($sdb, $bId) === ['text' => '', 'original' => false]);
    $pasted = "# flood, October\r\n103.160.130.0/24\r\n113.103.0.0/16 # a note\r\n\r\n; done\r\n1.2.3.4";
    $e = ipListSetEntries($sdb, $aId, $pasted, true);
    $txt = ipListEntriesText($sdb, $aId);
    check('db: a pasted list reads back exactly as it was written, comments and order kept, line ends made plain',
          ($e['added'] ?? 0) === 3 && $txt === ['text' => str_replace("\r\n", "\n", $pasted), 'original' => true], json_encode([$e, $txt]));
    check('db: … while the firewall still gets only the three ranges, as they were typed',
          (int)$sdb->query("SELECT COUNT(*) FROM ip_list_entries WHERE list_id = $aId")->fetchColumn() === 3
          && (int)$sdb->query("SELECT COUNT(*) FROM ip_list_entries WHERE list_id = $aId AND cidr = '113.103.0.0/16'")->fetchColumn() === 1);
    $c = ipListCreate($sdb, ['name' => 'Gamma', 'kind' => 'block', 'mode' => 'hard', 'source' => 'manual']);
    $cId = (int)($c['id'] ?? 0);
    ipListSetEntries($sdb, $cId, "# x\n5.5.5.0/24", true);
    check('db: deleting a list takes its pasted text with it',
          ipListDelete($sdb, $cId) && (int)$sdb->query("SELECT COUNT(*) FROM ip_list_sources WHERE list_id = $cId")->fetchColumn() === 0);

    $u = ipListUpdate($sdb, $aId, ['name' => 'Alpha', 'kind' => 'block', 'mode' => 'hard']);
    check('db: saving the same values changes nothing', $u === ['changed' => false, 'enforce' => false, 'refetch' => false], json_encode($u));
    $u = ipListUpdate($sdb, $aId, ['name' => 'Beta']);
    check('db: a name another list already has is refused, and the list keeps its own',
          isset($u['error']) && ipListFind($sdb, $aId)['name'] === 'Alpha', json_encode($u));
    $u = ipListUpdate($sdb, $aId, ['name' => 'Alpha renamed', 'kind' => 'allow', 'mode' => 'soft']);
    $row = ipListFind($sdb, $aId);
    check('db: a new name and kind are stored, and an allow list has no "under pressure"',
          !empty($u['changed']) && !empty($u['enforce']) && empty($u['refetch'])
          && $row['name'] === 'Alpha renamed' && $row['kind'] === 'allow' && $row['mode'] === 'hard', json_encode([$u, $row]));
    $u = ipListUpdate($sdb, $aId, ['url' => 'https://example.org/x.zone', 'ttl_minutes' => 30]);
    $row = ipListFind($sdb, $aId);
    check('db: a manual list takes no address and no refresh interval',
          empty($u['changed']) && $row['url'] === '' && (int)$row['ttl_minutes'] === 60, json_encode([$u, $row]));
    check('db: a kind or a mode that does not exist is refused',
          isset(ipListUpdate($sdb, $aId, ['kind' => 'maybe'])['error']) && isset(ipListUpdate($sdb, $aId, ['mode' => 'loud'])['error']));
    check('db: an empty or too long name is refused',
          isset(ipListUpdate($sdb, $aId, ['name' => '  '])['error']) && isset(ipListUpdate($sdb, $aId, ['name' => str_repeat('x', 65)])['error']));
    check('db: a URL list keeps needing an http(s) address',
          isset(ipListUpdate($sdb, $bId, ['url' => 'ftp://example.org/b.zone'])['error'])
          && ipListFind($sdb, $bId)['url'] === 'https://example.org/b.zone');
    $u = ipListUpdate($sdb, $bId, ['ttl_minutes' => 5]);
    check('db: the refresh interval is clamped, and a new interval alone downloads nothing',
          !empty($u['changed']) && empty($u['refetch']) && empty($u['enforce'])
          && (int)ipListFind($sdb, $bId)['ttl_minutes'] === IPLIST_TTL_MIN, json_encode($u));
    $u = ipListUpdate($sdb, $bId, ['url' => 'https://example.org/c.zone']);
    check('db: a new address asks for a fresh download',
          !empty($u['refetch']) && ipListFind($sdb, $bId)['url'] === 'https://example.org/c.zone', json_encode($u));
    check('db: a list that does not exist is refused', isset(ipListUpdate($sdb, 999999, ['name' => 'x'])['error']));
} else {
    skip('editing a list against a database', 'no local database to make a scratch copy in');
}
if ($adm instanceof PDO) {
    try { $adm->exec("DROP DATABASE IF EXISTS `$scratch`"); } catch (\Throwable $e) { echo "could not drop $scratch\n"; }
}

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails ? 1 : 0);
