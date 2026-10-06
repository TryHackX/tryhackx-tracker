<?php
/**
 * Tests for the inbound UDP monitor / rate limit:
 *   php tests/netlimit_test.php
 *
 * Two halves:
 *   1. the pure PHP of includes/netlimit.php — clamps, command validation, counters → rates
 *      (including the counter-reset case), percentiles / recommendation, bucketing and the
 *      automatic mode's hysteresis. No database, no network, no root: safe anywhere.
 *   2. the root helper tools/opentracker/tracker-netlimit.sh, driven end to end against a stub
 *      `nft` (and a stub `id` so the root check passes) in a temporary directory — argument
 *      validation, the generated ruleset, apply / status / off / egress and the detection of a
 *      foreign rate-limit rule on the same port. Skipped with a visible SKIP line when the machine
 *      has no bash, so the suite still runs on a bare Windows checkout.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/netlimit.php';

// config/net_state.json exactly as it was, put back at the very end. The helper's half below isolates its
// own files in a temporary directory, but the state checks in it write the checkout's real state file
// (netlimitStateUpdate() has no other), and until 1.72.0 nothing put it back: every run cleared the
// panel's last error and its last clean answer. Section 8 keeps its own copy too — taken after that.
$stateFileAtStart = netlimitStateFile();
$stateAtStart = is_file($stateFileAtStart) ? file_get_contents($stateFileAtStart) : null;

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

// ── 1. settings clamps ───────────────────────────────────────────────────────
check('pps default', netlimitPps([]) === 30000);
check('pps clamped low', netlimitPps(['net_limit_pps' => '5']) === NET_PPS_MIN);
check('pps clamped high', netlimitPps(['net_limit_pps' => '99999999']) === NET_PPS_MAX);
check('pps garbage falls back', netlimitPps(['net_limit_pps' => 'abc']) === 30000);
check('burst default', netlimitBurst([]) === 100);
check('burst clamped', netlimitBurst(['net_limit_burst' => '0']) === NET_BURST_MIN && netlimitBurst(['net_limit_burst' => '999999']) === NET_BURST_MAX);
check('port default', netlimitPort([]) === 6969);
check('port clamped', netlimitPort(['net_limit_port' => '0']) === 1 && netlimitPort(['net_limit_port' => '70000']) === 65535);
check('sample seconds clamped', netlimitSampleSeconds(['net_sample_seconds' => '1']) === NET_SAMPLE_MIN && netlimitSampleSeconds(['net_sample_seconds' => '99999']) === NET_SAMPLE_MAX);
check('keep days clamped', netlimitKeepDays(['net_keep_days' => '0']) === NET_KEEP_MIN && netlimitKeepDays(['net_keep_days' => '9999']) === NET_KEEP_MAX);
check('everything off by default', !netlimitEnabled([]) && !netlimitMonitorEnabled([]) && !netlimitAutoEnabled([]));
check('enabled reads exactly "1"', netlimitEnabled(['net_limit_enabled' => '1']) && !netlimitEnabled(['net_limit_enabled' => 'yes']));
// an upside-down band must not be able to pin the automatic mode to one value
check('auto max never below auto min', netlimitAutoMax(['net_auto_min' => '50000', 'net_auto_max' => '10000']) === 50000);
check('auto band defaults', netlimitAutoMin([]) === 10000 && netlimitAutoMax([]) === 80000);
check('auto target defaults', netlimitAutoTarget([]) === 30000 && netlimitAutoTargetCpu([]) === 70);
check('cpu target clamped', netlimitAutoTargetCpu(['net_auto_target_cpu' => '5']) === 10 && netlimitAutoTargetCpu(['net_auto_target_cpu' => '500']) === 100);

// ── 2. helper command validation (it is handed to a shell) ───────────────────
check('default command valid', netlimitValidCommand(NET_DEFAULT_CMD));
check('empty command allowed (= feature off)', netlimitValidCommand(''));
foreach (['sudo -n /usr/local/sbin/x.sh; rm -rf /', 'x && y', 'x | y', 'x `id`', 'x $(id)', "x\ny", 'x > /etc/passwd', 'x & y'] as $bad) {
    check('metacharacters refused: ' . str_replace("\n", '\\n', $bad), !netlimitValidCommand($bad));
}
check('over-long command refused', !netlimitValidCommand(str_repeat('a', 256)));
check('command getter blanks an invalid value', netlimitCommand(['net_limit_cmd' => 'x; rm -rf /']) === '');
check('command getter keeps a valid value', netlimitCommand(['net_limit_cmd' => 'sudo -n /opt/t.sh']) === 'sudo -n /opt/t.sh');

// ── trusted addresses: the exemption list ────────────────────────────────────
// A rate limit cannot tell which packets matter, so a short allow-list is the difference between
// throttling a swarm and throttling the admin's own SSH. Validated in TWO places -- here and in the
// root helper -- because the helper runs as root and writes these strings into a firewall ruleset.
check('no trusted addresses by default', netlimitTrusted([]) === []);
check('blank is not an address', netlimitTrusted(['net_limit_trusted' => "  
 , ; "]) === []);
$mixed = ['net_limit_trusted' => "203.0.113.10, 198.51.100.0/24
2001:db8::/32 ::1"];
check('v4, v4 CIDR, v6 CIDR and bare v6 all survive',
      netlimitTrusted($mixed) === ['203.0.113.10', '198.51.100.0/24', '2001:db8::/32', '::1'],
      json_encode(netlimitTrusted($mixed)));
check('duplicates collapse', netlimitTrusted(['net_limit_trusted' => '1.2.3.4, 1.2.3.4']) === ['1.2.3.4']);
foreach (['999.1.1.1', '1.2.3', '1.2.3.4/33', '2001:db8::/129', 'example.com', '1.2.3.4/', '; nft flush ruleset',
          '$(id)', '1.2.3.4 -o /etc/passwd'] as $bad) {
    check('refused: ' . $bad, !netlimitValidAddress($bad));
}
foreach (['0.0.0.0/0', '10.0.0.1', '255.255.255.255', '::', 'fe80::1/64', '2001:db8:85a3::8a2e:370:7334'] as $good) {
    check('accepted: ' . $good, netlimitValidAddress($good));
}
// A caller who types nonsense should be told, not silently ignored.
check('the rejected entries are reported back',
      netlimitTrustedRejected(['net_limit_trusted' => '1.2.3.4, nope, 5.6.7.8']) === ['nope']);
check('a valid list reports nothing rejected',
      netlimitTrustedRejected(['net_limit_trusted' => '1.2.3.4, 5.6.7.8']) === []);
// The cap is on judgement, not on nftables: 0.0.0.0/0 would already exempt everything, so a list
// long enough that nobody reads it is the real risk.
$many = implode(',', array_map(fn($i) => '10.0.' . intdiv($i, 256) . '.' . ($i % 256), range(0, NET_TRUSTED_MAX + 50)));
check('the list is capped', count(netlimitTrusted(['net_limit_trusted' => $many])) === NET_TRUSTED_MAX,
      (string)count(netlimitTrusted(['net_limit_trusted' => $many])));

// ── 3. counters → rates ──────────────────────────────────────────────────────
$prev = ['in_total' => 1000, 'in_passed' => 900, 'in_capped' => 100];
$cur  = ['in_total' => 7000, 'in_passed' => 6300, 'in_capped' => 700];
$r = netlimitRates($prev, $cur, 60);
check('rate: simple division', $r === ['in_total' => 100, 'in_passed' => 90, 'in_capped' => 10], json_encode($r));
check('rate: span below the floor is refused', netlimitRates($prev, $cur, 1) === null);
check('rate: span above the ceiling is refused', netlimitRates($prev, $cur, NET_LIVE_MAX_SPAN + 1) === null);
// a full reload (a new table, or a changed port) restarts the counters at zero — a difference
// against the old reading would be a huge negative number rendered as a spike
check('rate: counter reset detected', netlimitRates(['in_total' => 5000], ['in_total' => 10], 60) === null);
check('rate: zero traffic is a real answer, not a reset', netlimitRates($prev, $prev, 60) === ['in_total' => 0, 'in_passed' => 0, 'in_capped' => 0]);
check('rate: rounding', netlimitRates(['a' => 0], ['a' => 100], 60) === ['a' => 2]);
check('counter map: missing counters become 0', netlimitCounterPackets(['in_total' => ['packets' => 5]], NET_IN_COUNTERS) === ['in_total' => 5, 'in_passed' => 0, 'in_capped' => 0]);

// ── 4. percentile / recommendation ───────────────────────────────────────────
$vals = range(1, 100);
check('p50 of 1..100', netlimitPercentile($vals, 50) === 50);
check('p95 of 1..100', netlimitPercentile($vals, 95) === 95);
check('p100 of 1..100', netlimitPercentile($vals, 100) === 100);
check('percentile of an empty list', netlimitPercentile([], 95) === 0);
check('percentile of one value', netlimitPercentile([7], 50) === 7 && netlimitPercentile([7], 95) === 7);
check('percentile does not care about order', netlimitPercentile([9, 1, 5, 3, 7], 50) === 5);
check('round step below 100k', netlimitRoundStep(22345) === 22000);
check('round step above 100k', netlimitRoundStep(123456) === 125000);
check('round step never leaves the range', netlimitRoundStep(1) === NET_PPS_MIN && netlimitRoundStep(99999999) === NET_PPS_MAX);

$rec = netlimitRecommendFrom([]);
check('recommendation: no samples', $rec['samples'] === 0 && $rec['suggested'] === 0 && $rec['enough'] === false);
// the plan's worked example: median 22 000, P95 38 000, peak 61 000 → suggest P95 + 5 %
$sample = array_merge(array_fill(0, 90, 22000), array_fill(0, 8, 38000), array_fill(0, 2, 61000));
$rec = netlimitRecommendFrom($sample);
check('recommendation: median', $rec['median'] === 22000, (string)$rec['median']);
check('recommendation: p95', $rec['p95'] === 38000, (string)$rec['p95']);
check('recommendation: peak', $rec['peak'] === 61000, (string)$rec['peak']);
check('recommendation: suggested is P95 + 5 %', $rec['suggested'] === 40000, (string)$rec['suggested']);
check('recommendation: floor is median + 10 %', $rec['floor'] === 24000, (string)$rec['floor']);
check('recommendation: 100 samples is enough', $rec['enough'] === true);
check('recommendation: 59 samples is not enough', netlimitRecommendFrom(array_fill(0, 59, 1000))['enough'] === false);
$rec['days'] = 7;
$text = netlimitRecommendText($rec);
check('recommendation text names the numbers', str_contains($text, '40,000') && str_contains($text, 'never trigger'), $text);
check('recommendation text says what the floor costs', str_contains($text, '24,000') && str_contains($text, 'currently arriving'), $text);
check('recommendation text without samples explains why', str_contains(netlimitRecommendText(netlimitRecommendFrom([])), 'No traffic has been recorded'));
check('recommendation text warns when there is too little data', str_contains(netlimitRecommendText(netlimitRecommendFrom(array_fill(0, 10, 5000)) + ['days' => 7]), 'first impression'));
// On a tracker whose stale swarm keeps calling, P95 of ARRIVALS is the flood, not demand — telling
// the admin to match it would mean "no limit at all", so that case has to say so out loud.
$flood = netlimitRecommendText($rec, true);
check('flood mode warns that arrivals are not demand', str_contains($flood, 'ARRIVALS, not demand'), $flood);
check('flood mode reframes the decision', str_contains($flood, 'willing to hand OpenTracker'));
check('flood mode says why dropping is free', str_contains($flood, 'before the') && str_contains($flood, 'ever sees them'));
check('normal mode does not carry the flood warning', !str_contains($text, 'ARRIVALS, not demand'));
// WHICH sentence comes first decides what gets acted on. Leading with "a limit at P95 + 5 % would
// never trigger" hands the admin a number that means NO LIMIT, and a caveat afterwards arrives too
// late. In a flood the caveat has to lead, and the P95 figure be demoted to a parenthesis.
$posCaveat = strpos($flood, 'ARRIVALS, not demand');
$posNumber = strpos($flood, number_format($rec['suggested']));
check('flood mode puts the caveat BEFORE the number it warns about',
      $posCaveat !== false && $posNumber !== false && $posCaveat < $posNumber, substr($flood, 0, 220));
check('flood mode does not present the arrivals figure as a recommendation',
      !str_contains($flood, 'would essentially never trigger'), $flood);
// …and when we know what is actually getting through, that is the number to pick from.
$floodP = netlimitRecommendText($rec, true, 39800);
check('flood mode quotes what is getting through', str_contains($floodP, '39,800'), $floodP);
check('flood mode names it as the number to choose from', str_contains($floodP, 'the number to pick from'), $floodP);
check('flood mode without a live rate still gives direction',
      !str_contains($flood, 'the number to pick from') && str_contains($flood, 'not one taken from the arrivals'), $flood);
// 1.73.0: the paragraph was English on every page. Its sentences are dictionary keys (api.net.rec_*) said in the reader's
// language — and answered as keys and numbers, so the card writes each as a word that keeps its key (the live switch).
$partsP = netlimitRecommendParts($rec, true, 39800);
check('the recommendation is its sentences as keys and numbers — the caveat first, what gets through, the reference last',
      array_column($partsP, 'key') === ['api.net.rec_stats', 'api.net.rec_flood', 'api.net.rec_flood_passed', 'api.net.rec_flood_ref']
      && $partsP[0]['vars'] === ['days' => 7, 'median' => 22000, 'p95' => 38000, 'peak' => 61000]
      && $partsP[2]['vars'] === ['passed' => 39800] && $partsP[3]['vars'] === ['n' => 40000] && netlimitSayParts($partsP) === $floodP, json_encode($partsP));
check('… one day has a sentence of its own (never "Last 1 days"), and no samples one more',
      netlimitRecommendParts(['days' => 1] + $rec)[0]['key'] === 'api.net.rec_stats_day'
      && array_column(netlimitRecommendParts(netlimitRecommendFrom([])), 'key') === ['api.net.rec_none']);
$GLOBALS['__lang']['current'] = null; langInvalidate(); langInit(['default_language' => 'pl'], null);
$textPl = netlimitRecommendText($rec);
$floodPl = netlimitRecommendText($rec, true, 39800);
$GLOBALS['__lang']['current'] = null; langInvalidate(); langInit(['default_language' => 'en'], null);
check('… said in the reader\'s language: Polish words and Polish numbers (40 000, a no-break space)',
      str_contains($textPl, 'Ostatnie 7 dni') && str_contains($textPl, "40\u{00A0}000 pps") && !str_contains($textPl, 'never trigger')
      && str_contains($floodPl, 'PRZYCHODZĄCE') && str_contains($floodPl, "39\u{00A0}800 pps") && netlimitRecommendText($rec) === $text, $textPl);
$nlJs = (string)file_get_contents($root . '/assets/js/admin-netlimit.js');
$nsSrc = (string)file_get_contents($root . '/api/admin/net_status.php');
check('… net_status answers the parts beside the text, the card writes them as keyed words, the traffic page\'s bundle carries api.net.',
      str_contains($nsSrc, "\$rec['parts'] = netlimitRecommendParts(\$rec, \$flood, \$passedNow);")
      && str_contains($nlJs, 'box.appendChild(el(\'span\', {}, adviceWords(r)));') && str_contains($nlJs, 'return t.key(p.key, vars);')
      && str_contains($nlJs, 'saidPart(lc2.why_part) || lc2.why')
      && str_contains((string)file_get_contents($root . '/templates/admin/traffic.php'), "langJsBridge(\$baseUrl, ['js.', 'api.helper.', 'api.net.'"));
check('… and the card\'s own failures are the dictionary\'s, not English (net_status)',
      str_contains($nsSrc, "__('api.net.no_helper_2')") && str_contains($nsSrc, "__('api.net.exec_disabled')") && str_contains($nsSrc, "__('api.net.helper_no_answer')")
      && !str_contains($nsSrc, "= 'No rate-limit helper") && !str_contains($nsSrc, "'The firewall helper did not answer.'"));

// ── 4b. a burst too small for the limit (1.72.0) ─────────────────────────────
// Production 2026-09-29: a limit of 90 000 with a burst of 100 passed ~80 000 on average while a third of
// the arrivals was dropped. ≈ 22 ms of the limit is the suggested bucket: 2 000 at 90 000.
check('burst: the suggestion for 90 000 pps is 2 000 (≈ 22 ms)', netlimitBurstSuggest(90000) === 2000, (string)netlimitBurstSuggest(90000));
check('burst: rounded to 100s from 1 000, to 10s from 100, to 1 below',
      netlimitBurstSuggest(120000) === 2600 && netlimitBurstSuggest(30000) === 660 && netlimitBurstSuggest(45000) === 990 && netlimitBurstSuggest(1000) === 22,
      json_encode([netlimitBurstSuggest(120000), netlimitBurstSuggest(30000), netlimitBurstSuggest(45000), netlimitBurstSuggest(1000)]));
check('burst: never outside what the helper accepts', netlimitBurstSuggest(5000000) === NET_BURST_MAX && netlimitBurstSuggest(0) === NET_BURST_MIN
      && netlimitBurstSuggest(10) === NET_BURST_MIN);
$hr = static fn(int $n, int $total, int $passed, int $capped, int $limit) => array_fill(0, $n, ['pps_total' => $total, 'pps_passed' => $passed, 'pps_capped' => $capped, 'limit_pps' => $limit]);
$bh = netlimitBurstHintFrom($hr(60, 121000, 80000, 41000, 90000), 100, true);
check('burst hint: production\'s hour — 80 000 of 90 000 (88.9 %) through, 33.9 % dropped, burst 100 → suggest 2 000',
      $bh !== null && $bh['limit'] === 90000 && $bh['served'] === 80000 && $bh['served_pct'] == 88.9 && $bh['dropped_pct'] == 33.9
      && $bh['burst'] === 100 && $bh['suggested'] === 2000 && $bh['ms'] === 22 && $bh['samples'] === 60, json_encode($bh));
check('burst hint: nothing while the limit is off', netlimitBurstHintFrom($hr(60, 121000, 80000, 41000, 90000), 100, false) === null);
// served just under 95 % with just over 5 % dropped is said; at either threshold it is not
check('burst hint: 94.9 % through and 5.1 % dropped is said', netlimitBurstHintFrom($hr(60, 100000, 85410, 5100, 90000), 100, true) !== null);
check('burst hint: 95 % through is not (the limit is full)', netlimitBurstHintFrom($hr(60, 100000, 85500, 14500, 90000), 100, true) === null);
check('burst hint: 5 % dropped or less is not (nothing to recover)', netlimitBurstHintFrom($hr(60, 84000, 79800, 4200, 90000), 100, true) === null);
check('burst hint: a burst already at the suggestion says nothing', netlimitBurstHintFrom($hr(60, 121000, 80000, 41000, 90000), 2000, true) === null
      && netlimitBurstHintFrom($hr(60, 121000, 80000, 41000, 90000), 1999, true) !== null);
check('burst hint: fewer than ' . NET_BURST_HINT_MIN_SAMPLES . ' samples say nothing',
      netlimitBurstHintFrom($hr(NET_BURST_HINT_MIN_SAMPLES - 1, 121000, 80000, 41000, 90000), 100, true) === null
      && netlimitBurstHintFrom($hr(NET_BURST_HINT_MIN_SAMPLES, 121000, 80000, 41000, 90000), 100, true) !== null);
// samples taken while only counting (no limit loaded: limit_pps 0) are not the limit's
$mixed = array_merge($hr(50, 121000, 121000, 0, 0), $hr(4, 121000, 80000, 41000, 90000));
check('burst hint: counting-only samples do not count (4 limited ones are too few)', netlimitBurstHintFrom($mixed, 100, true) === null);
check('burst hint: the thresholds are the brief\'s', NET_BURST_HINT_SERVED === 0.95 && NET_BURST_HINT_DROPPED === 0.05 && NET_BURST_HINT_FACTOR === 0.022 && NET_BURST_HINT_WINDOW === 3600);
// the words carry the numbers and the way there — Settings → Inbound limit → Burst, then Traffic → Apply limit
$enL = langLoad('en'); $plL = langLoad('pl');
check('burst hint: the words name the path in Settings and on Traffic (EN)',
      str_contains($enL['js.net.burst_hint'] ?? '', 'Settings → Inbound limit → Burst, then Traffic → Apply limit'), $enL['js.net.burst_hint'] ?? 'missing');
check('burst hint: … and in Polish, with the labels the pages carry',
      str_contains($plL['js.net.burst_hint'] ?? '', $plL['a.head.settings'] . ' → ' . $plL['settings.net_throttle_heading'] . ' → ' . $plL['settings.net_burst_label'])
      && str_contains($plL['js.net.burst_hint'] ?? '', $plL['a.traffic.title'] . ' → ' . $plL['a.traffic.apply_limit']), $plL['js.net.burst_hint'] ?? 'missing');
check('burst hint: the English path is the pages\' own labels too',
      $enL['a.head.settings'] === 'Settings' && $enL['settings.net_throttle_heading'] === 'Inbound limit' && $enL['settings.net_burst_label'] === 'Burst'
      && $enL['a.traffic.title'] === 'Traffic' && $enL['a.traffic.apply_limit'] === 'Apply limit');
foreach (['served', 'limit', 'served_pct', 'dropped_pct', 'burst', 'suggested', 'ms'] as $ph) {
    check("burst hint: the words carry :$ph", str_contains($enL['js.net.burst_hint'] ?? '', ':' . $ph));
}

// ── 4c. who loaded the limit, in words (1.72.0) ──────────────────────────────
// Every source the code passes is found by READING the calls — netlimitApply(…, $source) (default
// 'admin'), netlimitApplyMonitor(…, $source) + ':count', netlimitOff(…, $source) + ':off' — so a source a
// later release adds without words fails here, not on the owner's screen.
$callSources = [];
$srcFiles = array_merge(glob($root . '/api/*.php'), glob($root . '/api/admin/*.php'), glob($root . '/includes/*.php'), glob($root . '/tools/*.php'));
$funcs = ['netlimitApply' => [5, ''], 'netlimitApplyMonitor' => [3, ':count'], 'netlimitOff' => [2, ':off']];
foreach ($srcFiles as $file) {
    $tok = token_get_all((string)file_get_contents($file));
    $cnt = count($tok);
    for ($i = 0; $i < $cnt; $i++) {
        if (!is_array($tok[$i]) || $tok[$i][0] !== T_STRING || !isset($funcs[$tok[$i][1]])) continue;
        // not the definition itself
        $j = $i - 1; while ($j >= 0 && is_array($tok[$j]) && $tok[$j][0] === T_WHITESPACE) $j--;
        if ($j >= 0 && is_array($tok[$j]) && $tok[$j][0] === T_FUNCTION) continue;
        $k = $i + 1; while ($k < $cnt && is_array($tok[$k]) && $tok[$k][0] === T_WHITESPACE) $k++;
        if (($tok[$k] ?? null) !== '(') continue;
        [$pos, $suffix] = $funcs[$tok[$i][1]];
        $depth = 0; $args = [[]];
        for ($k++; $k < $cnt; $k++) {
            $t = $tok[$k];
            if ($t === '(' || $t === '[') $depth++;
            if ($t === ')' || $t === ']') { if ($depth === 0) break; $depth--; }
            if ($t === ',' && $depth === 0) { $args[] = []; continue; }
            if (!is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $args[count($args) - 1][] = $t;
        }
        $arg = $args[$pos] ?? null;
        if ($arg === null) { $code = 'admin'; }
        elseif (count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING) { $code = trim($arg[0][1], "'\""); }
        else { $code = null; }   // a variable: said below
        $callSources[] = ['file' => basename($file), 'fn' => $tok[$i][1], 'code' => $code === null ? null : $code . $suffix];
    }
}
$codes = array_values(array_unique(array_filter(array_column($callSources, 'code'), static fn($c) => $c !== null)));
sort($codes);
check('sources: the calls were found (every apply, counting and off in api/, includes/, tools/)', count($callSources) >= 12, (string)count($callSources));
check('sources: each passes a literal — no source the card cannot know',
      !array_filter($callSources, static fn($c) => $c['code'] === null),
      json_encode(array_values(array_filter($callSources, static fn($c) => $c['code'] === null))));
check('sources: the ones the brief named are among them (admin, auto, panic, lists, lists-off, the :count / :off suffixes)',
      !array_diff(['admin', 'admin:count', 'admin:off', 'auto', 'panic', 'panic-restore', 'panic-restore:off', 'lists', 'lists-off', 'probe'], $codes),
      implode(', ', $codes));
foreach ($codes as $code) {
    $key = 'api.net.src.' . str_replace(['-', ':'], ['_', '.'], $code);
    check("sources: \"$code\" has words in both languages", isset($enL[$key], $plL[$key]) && $enL[$key] !== $plL[$key], $key);
    check("sources: \"$code\" prints as its words, not as itself", netlimitSourceWords($code) === $enL[$key] && netlimitSourceWords($code) !== $code, netlimitSourceWords($code));
}
check('sources: "lists-off" is the janitor re-loading the same limit when the lists were switched off',
      str_contains(netlimitSourceWords('lists-off'), 'switched off') && str_contains(netlimitSourceWords('lists-off'), 'same limit'), netlimitSourceWords('lists-off'));
check('sources: the stability probe\'s apply says so (api/admin/tuner.php passes "probe")', in_array('probe', $codes, true));
check('sources: an unknown code prints as itself, never blank', netlimitSourceWords('schedule') === 'schedule'
      && netlimitSourceWords('auto:count') === 'auto:count' && netlimitSourceWords('x; rm -rf /') === 'x; rm -rf /' && netlimitSourceWords('Admin') === 'Admin');
check('sources: no source recorded (an old state file) stays empty — the card says nothing then', netlimitSourceWords('') === '' && netlimitSourceWords('  ') === '');
check('sources: "lists-off" and a "lists:off" could never share words', 'api.net.src.' . str_replace(['-', ':'], ['_', '.'], 'lists-off') !== 'api.net.src.' . str_replace(['-', ':'], ['_', '.'], 'lists:off'));
// in Polish, through the same function
$langWas = $GLOBALS['__lang'];
$GLOBALS['__lang']['strings'] = $plL; $GLOBALS['__lang']['current'] = 'pl';
check('sources: … and the function speaks the page\'s language', netlimitSourceWords('lists-off') === $plL['api.net.src.lists_off'] && str_contains(netlimitSourceWords('lists-off'), 'list IP'));
$GLOBALS['__lang'] = $langWas;
$ns = (string)file_get_contents($root . '/api/admin/net_status.php');
$nj = (string)file_get_contents($root . '/assets/js/admin-netlimit.js');
check('sources: net_status sends the words beside the code', str_contains($ns, "'words' => netlimitSourceWords(\$src)") && str_contains($ns, "'source' => \$src"));
// 1.73.0: the card writes its words as t.key() words — they keep their keys for the live language switch
check('sources: the card prints the words (and the code only when there are none)', str_contains($nj, 'la.words || la.source') && str_contains($nj, "t.key('js.net.set_by', {src: src"));

// ── 4d. handshakes per announce (1.72.0) ─────────────────────────────────────
$hrs = static function (int $n, int $t0, float $cps, float $aps, int $uptime0 = 100000): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) $out[] = ['ts' => $t0 + $i * 3600, 'connects' => (int)(5e9 + $i * 3600 * $cps), 'udp_announces' => (int)(3e9 + $i * 3600 * $aps), 'uptime' => $uptime0 + $i * 3600];
    return $out;
};
$day = $hrs(25, 1800000000, 48000, 32000);
$hs = netlimitHandshakesFrom($day, 86400);
check('handshakes: 48 000 connects a second against 32 000 announces = 1.50 over the day', $hs !== null && $hs['ratio'] == 1.5 && $hs['announces'] === 24 * 3600 * 32000, json_encode($hs));
check('handshakes: the last hour alone', netlimitHandshakesFrom(array_slice($day, -2), 3600)['ratio'] == 1.5);
check('handshakes: the congested state reads 2.5', netlimitHandshakesFrom($hrs(2, 1800000000, 60000, 24000), 3600)['ratio'] == 2.5);
// a restart inside the window: the counters and the uptime start again — the window says nothing
$restart = $day; for ($i = 10; $i < 25; $i++) { $restart[$i]['uptime'] = ($i - 10) * 3600 + 60; $restart[$i]['connects'] = (int)(($i - 9) * 3600 * 48000); $restart[$i]['udp_announces'] = (int)(($i - 9) * 3600 * 32000); }
check('handshakes: a restart inside the day hides the day', netlimitHandshakesFrom($restart, 86400) === null);
check('handshakes: … and not an hour after it', netlimitHandshakesFrom(array_slice($restart, -2), 3600) !== null);
$upOnly = $day; $upOnly[12]['uptime'] = 5;
check('handshakes: the uptime going back alone is a restart too', netlimitHandshakesFrom($upOnly, 86400) === null);
check('handshakes: too little history for a window says nothing (17 h is not a day)', netlimitHandshakesFrom(array_slice($day, 0, 18), 86400) === null
      && netlimitHandshakesFrom(array_slice($day, 0, 19), 86400) !== null);
check('handshakes: one row, or no announces, say nothing', netlimitHandshakesFrom(array_slice($day, 0, 1), 3600) === null
      && netlimitHandshakesFrom($hrs(2, 1800000000, 100, 0), 3600) === null);
check('handshakes: the card shows them with one sentence on what a high value means',
      str_contains($nj, "t.key('js.net.handshakes_note')") && str_contains($enL['js.net.handshakes_note'] ?? '', 'repeating the handshake')
      && str_contains($enL['js.net.handshakes_note'] ?? '', 'dropped') && str_contains($ns, 'netlimitHandshakes($db, $cfg, $now)'));
check('handshakes: … and the burst hint travels in the recommendation', str_contains($ns, "\$rec['burst_hint'] = netlimitBurstHint(\$db, \$cfg, \$now,")
      && str_contains($nj, 'const bh = r.burst_hint;') && str_contains($nj, "t.key('js.net.burst_hint'"));

// ── 4e. what the provider drops OUTSIDE the machine (1.73.3) ─────────────────
// Production, 2026-10-05: past ~50–80 k packets a second sent, the provider dropped 45–70 % of the whole machine's
// packets while every counter inside the guest said all was well; the share of TCP segments resent followed it (15.6 %
// at 90 000 pps out, 1.9 % with the tracker stopped). The card reads it, and the advice never points higher meanwhile.
$snmpText = static fn(int $out, int $re): string =>
    "Ip: Forwarding DefaultTTL InReceives\nIp: 1 64 123\nIcmp: InMsgs InErrors\nIcmp: 5 0\n"
    . "Tcp: RtoAlgorithm RtoMin RtoMax MaxConn ActiveOpens PassiveOpens AttemptFails EstabResets CurrEstab InSegs OutSegs RetransSegs InErrs OutRsts InCsumErrors\n"
    . "Tcp: 1 200 120000 -1 100 200 3 4 5 900000 $out $re 0 10 0\n"
    . "Udp: InDatagrams NoPorts InErrors OutDatagrams\nUdp: 912588 3 0 911609\n";
check('snmp: OutSegs and RetransSegs are read by name', netlimitSnmpTcp($snmpText(1000000, 19000)) === ['out' => 1000000, 'retrans' => 19000],
      json_encode(netlimitSnmpTcp($snmpText(1000000, 19000))));
check('snmp: a file without the Tcp pair, a broken row or a field missing is no reading — never zeros',
      netlimitSnmpTcp("Ip: a\nIp: 1\n") === null && netlimitSnmpTcp('') === null
      && netlimitSnmpTcp("Tcp: OutSegs RetransSegs\nTcp: 5\n") === null && netlimitSnmpTcp("Tcp: InSegs OutSegs\nTcp: 1 2\n") === null);
$snmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'netlimit_snmp_' . getmypid();
@mkdir($snmpDir, 0777, true);
$snmpFile = $snmpDir . DIRECTORY_SEPARATOR . 'snmp';
file_put_contents($snmpFile, $snmpText(2000000, 31000));
putenv('TRACKER_PROC_SNMP=' . $snmpFile);
check('snmp: TRACKER_PROC_SNMP points the reading at a fixture (tools/tuner.py honours the same)',
      netlimitProcSnmpFile() === $snmpFile && netlimitTcpRead() === ['out' => 2000000, 'retrans' => 31000], json_encode(netlimitTcpRead()));
putenv('TRACKER_PROC_SNMP=' . $snmpDir . DIRECTORY_SEPARATOR . 'missing');
check('snmp: a file that is not there gives no reading', netlimitTcpRead() === null);
putenv('TRACKER_PROC_SNMP');
check('snmp: without the variable it is /proc/net/snmp', netlimitProcSnmpFile() === '/proc/net/snmp');
$pA = ['out' => 1000000, 'retrans' => 10000];
check('ratio: 1 560 resent of 10 000 sent is 156 per 1 000 (15.6 %)', netlimitRetransX10($pA, ['out' => 1010000, 'retrans' => 11560], 60) === 156);
check('ratio: fewer than ' . NET_LOSS_MIN_SEGMENTS . ' segments is NULL, not a number', netlimitRetransX10($pA, ['out' => 1000299, 'retrans' => 10100], 60) === null
      && netlimitRetransX10($pA, ['out' => 1000300, 'retrans' => 10003], 60) === 10);
check('ratio: a counter that went backwards (a reboot), a missing reading or a span out of range is NULL',
      netlimitRetransX10($pA, ['out' => 5000, 'retrans' => 3], 60) === null && netlimitRetransX10(null, $pA, 60) === null
      && netlimitRetransX10($pA, null, 60) === null && netlimitRetransX10($pA, ['out' => 1010000, 'retrans' => 11560], 1) === null
      && netlimitRetransX10($pA, ['out' => 1010000, 'retrans' => 11560], NET_LIVE_MAX_SPAN + 1) === null);
check('levels: ok under 4 %, warn from 4 %, bad from 8 % (named constants)', NET_LOSS_WARN_PCT === 4.0 && NET_LOSS_BAD_PCT === 8.0
      && netlimitLossLevel(3.9) === 'ok' && netlimitLossLevel(4.0) === 'warn' && netlimitLossLevel(7.9) === 'warn'
      && netlimitLossLevel(8.0) === 'bad' && netlimitLossLevel(null) === null);
$tNow = 1800000000;
check('outside loss: nothing ever read is null', netlimitOutsideLoss(null, null, $tNow) === null
      && netlimitOutsideLoss(null, ['median_x10' => null, 'n' => 0, 'passed' => null], $tNow) === null);
$ol = netlimitOutsideLoss(['x10' => 156, 'at' => $tNow - 30, 'span' => 60, 'segs' => 10000, 'read' => true], null, $tNow);
check('outside loss: 15.6 % now is bad, one decimal, with its time — and the advice is guarded',
      $ol['pct'] === 15.6 && $ol['level'] === 'bad' && $ol['at'] === $tNow - 30 && $ol['guard'] === true && $ol['guard_pct'] === 15.6, json_encode($ol));
$ol = netlimitOutsideLoss(['x10' => 19, 'at' => $tNow - 30, 'read' => true], null, $tNow);
check('outside loss: 1.9 % is ok and guards nothing', $ol['level'] === 'ok' && $ol['guard'] === false, json_encode($ol));
$ol = netlimitOutsideLoss(['x10' => 19, 'at' => $tNow - 30, 'read' => true], ['median_x10' => 52, 'n' => 30, 'passed' => 80000], $tNow);
check('outside loss: ok now but a warn median over the last hour still guards the advice',
      $ol['level'] === 'ok' && $ol['hour_pct'] === 5.2 && $ol['hour_level'] === 'warn' && $ol['hour_n'] === 30 && $ol['guard'] === true && $ol['guard_pct'] === 5.2, json_encode($ol));
check('outside loss: … from ' . NET_LOSS_HOUR_MIN . ' readings at least',
      netlimitOutsideLoss(['x10' => 19, 'at' => $tNow - 30, 'read' => true], ['median_x10' => 90, 'n' => NET_LOSS_HOUR_MIN - 1, 'passed' => 1], $tNow)['guard'] === false);
check('outside loss: too few segments, no /proc/net/snmp, or a reading the janitor stopped renewing say so — pct null',
      netlimitOutsideLoss(['x10' => null, 'at' => $tNow - 30, 'read' => true], null, $tNow)['why'] === 'few'
      && netlimitOutsideLoss(['x10' => null, 'at' => $tNow - 30, 'read' => false], null, $tNow)['why'] === 'unreadable'
      && netlimitOutsideLoss(['x10' => 156, 'at' => $tNow - 3600, 'read' => true], null, $tNow)['why'] === 'stale'
      && netlimitOutsideLoss(['x10' => 156, 'at' => $tNow - 3600, 'read' => true], null, $tNow)['pct'] === null
      && netlimitOutsideLoss(['x10' => 156, 'at' => $tNow - 3600, 'read' => true], null, $tNow)['guard'] === false);
check('loss base: the lower of the limit in force and what gets through (a limit far above the traffic holds nothing)',
      netlimitLossBase(90000, 88000) === 88000 && netlimitLossBase(175000, 100000) === 100000
      && netlimitLossBase(0, 52000) === 52000 && netlimitLossBase(50000, 0) === 50000 && netlimitLossBase(0, 0) === 0);
// the advice, guarded: production's week — arrivals P95 ~105 000, so P95 + 5 % = 110 000, above a limit of 90 000
$recP = netlimitRecommendFrom(array_merge(array_fill(0, 90, 95000), array_fill(0, 10, 105000))) + ['days' => 7];
$lossOl = netlimitOutsideLoss(['x10' => 156, 'at' => $tNow - 30, 'read' => true], null, $tNow);
$g = netlimitRecommendGuard($recP, $lossOl, netlimitLossBase(90000, 88000), null);
check('guard: while losing outside, ~80 % of what the limit lets through (88 000 → 70 000), never above the limit in force',
      $recP['suggested'] === 110000 && $g['guard'] === 'loss' && $g['suggested'] === netlimitRoundStep((int)round(88000 * NET_LOSS_LOWER))
      && $g['suggested'] === 70000 && $g['suggested'] < 90000 && $g['suggested_was'] === 110000 && $g['loss_base'] === 88000, json_encode($g));
$g175 = netlimitRecommendGuard($recP, $lossOl, netlimitLossBase(175000, 100000), null);
check('guard: … at 175 000 with 100 000 getting through it is 80 000 — 80 % of the LIMIT (140 000) would lower nothing',
      $g175['suggested'] === 80000, json_encode($g175));
$partsL = netlimitRecommendParts($g);
$textL = netlimitSayParts($partsL);
check('guard: the words say the machine is losing packets OUTSIDE it and what to do — nothing points higher',
      array_column($partsL, 'key') === ['api.net.rec_stats', 'api.net.rec_loss', 'api.net.rec_loss_lower']
      && $partsL[1]['vars'] === ['pct' => '15.6', 'warn' => '4'] && $partsL[2]['vars'] === ['n' => 70000, 'base' => 88000]
      && str_contains($textL, 'losing packets OUTSIDE') && str_contains($textL, '70,000') && str_contains($textL, 'stability probe')
      && !str_contains($textL, '110,000') && !str_contains($textL, 'never trigger'), $textL);
$gNoBase = netlimitRecommendGuard($recP, $lossOl, 0, null);
check('guard: with no limit and no rate to go down from, no number — lower it by hand or run the probe',
      $gNoBase['suggested'] === 0 && array_column(netlimitRecommendParts($gNoBase), 'key')[2] === 'api.net.rec_loss_probe');
$recFew = netlimitRecommendFrom(array_fill(0, 10, 95000)) + ['days' => 7];
check('guard: the loss leads even before there are enough samples for a recommendation',
      array_column(netlimitRecommendParts(netlimitRecommendGuard($recFew, $lossOl, 88000, null)), 'key') === ['api.net.rec_stats', 'api.net.rec_loss', 'api.net.rec_loss_lower']);
check('guard: in a flood too — no "for reference … above the arrivals"',
      !in_array('api.net.rec_flood_ref', array_column(netlimitRecommendParts($g, true, 88000), 'key'), true));
$okOl = netlimitOutsideLoss(['x10' => 19, 'at' => $tNow - 30, 'read' => true], null, $tNow);
$gCap = netlimitRecommendGuard($recP, $okOl, 88000, 82600);
check('cap: when nothing is lost, the suggestion stops at the busiest hour seen coping (82 600 → 82 000, rounded DOWN)',
      $gCap['guard'] === 'cap' && $gCap['suggested'] === 82000 && $gCap['suggested_was'] === 110000 && $gCap['safe_cap'] === 82600, json_encode($gCap));
$partsC = netlimitRecommendParts($gCap);
check('cap: … the arrivals\' sentence keeps its own number and the cap is said after it',
      array_column($partsC, 'key') === ['api.net.rec_stats', 'api.net.rec_normal', 'api.net.rec_cap']
      && $partsC[1]['vars']['suggested'] === 110000 && $partsC[2]['vars'] === ['n' => 82000, 'safe' => 82600, 'days' => NET_LOSS_SAFE_DAYS, 'warn' => '4'],
      json_encode($partsC));
check('cap: … in a flood the reference keeps the arrivals\' number, the cap follows',
      array_slice(array_column(netlimitRecommendParts($gCap, true, 80000), 'key'), -2) === ['api.net.rec_flood_ref', 'api.net.rec_cap']
      && netlimitRecommendParts($gCap, true, 80000)[3]['vars'] === ['n' => 110000]);
check('cap: a safe hour above the suggestion changes nothing; no history, no cap; no samples, no guard',
      netlimitRecommendGuard($recP, $okOl, 88000, 150000)['guard'] === null && netlimitRecommendGuard($recP, $okOl, 88000, 150000)['suggested'] === 110000
      && netlimitRecommendGuard($recP, $okOl, 88000, null)['guard'] === null
      && netlimitRecommendGuard(netlimitRecommendFrom([]), $lossOl, 88000, 50000)['guard'] === null);
$GLOBALS['__lang']['current'] = null; langInvalidate(); langInit(['default_language' => 'pl'], null);
$textLpl = netlimitSayParts($partsL);
$GLOBALS['__lang']['current'] = null; langInvalidate(); langInit(['default_language' => 'en'], null);
check('guard: … in Polish, Polish numbers', str_contains($textLpl, 'POZA sobą') && str_contains($textLpl, "70\u{00A0}000 pps") && str_contains($textLpl, '15.6 %'), $textLpl);
// net_status and the card wire it: the tile, the guard, the blacklist budget
$ns2 = (string)file_get_contents($root . '/api/admin/net_status.php');
check('net_status: `outside_loss` from the state and the last hour, the guard between the recommendation and its words, the mode',
      str_contains($ns2, "\$out['outside_loss'] = netlimitOutsideLoss(") && str_contains($ns2, 'netlimitLossHour($db, $now)')
      && str_contains($ns2, "\$rec = netlimitRecommendGuard(\$rec, \$out['outside_loss'], \$lossBase, \$safeCap);")
      && strpos($ns2, 'netlimitRecommendGuard(') < strpos($ns2, "\$rec['parts'] = netlimitRecommendParts(")
      && str_contains($ns2, "'tracker_mode' =>"));
check('card: the tile has a stable id and a level, its words are keyed (ok / warn / bad and why there is none)',
      str_contains($nj, "tile.id = 'net-tile-outside';") && str_contains($nj, "tile.dataset.level = (ol && ol.level) || 'none';")
      && str_contains($nj, "t.key('js.net.outside_title')") && str_contains($nj, "t.key('js.net.outside_bad')") && str_contains($nj, "t.key('js.net.outside_few')"));
check('card: while losing outside, no red zone under what gets through and no "cutting into" warning',
      str_contains($nj, 'paintSlider(range, state.pps, lossGuard() ? 0 : inboundReference(), machineCeiling());')
      && str_contains($nj, "if (ref > 0 && lossGuard()) {"));
check('blacklist: the budget says it covers only connect / "not authorized" replies and the inbound limit is the lever …',
      str_contains($enL['js.net.egress_blacklist'] ?? '', 'only connect and “not authorized” replies')
      && str_contains($enL['js.net.egress_blacklist'] ?? '', 'every announce reply passes outside it')
      && str_contains($enL['js.net.egress_blacklist'] ?? '', 'inbound limit')
      && str_contains($plL['js.net.egress_blacklist'] ?? '', 'tylko odpowiedzi connect i „not authorized”')
      && str_contains($nj, "t.key('js.net.egress_blacklist')") && str_contains($nj, "isBlacklist(j) ? t.key('js.net.egress_note_blacklist') : t.key('js.net.egress_note')"));
$eAdv = substr($nj, (int)strpos($nj, 'function renderEgressAdvice'), 4000);
check('blacklist: … and neither it nor a loss outside gets the "too low" / "almost no headroom" push, the amber zone or "Use suggested"',
      (bool)preg_match("/if \\(blacklist\\) \\{.*?\\} else if \\(lossGuard\\(\\)\\) \\{.*?\\} else if \\(eState\\.pps < eState\\.ref\\) \\{.*?egress_too_low.*?egress_tight/s", $eAdv)
      && str_contains($nj, 'return (isBlacklist() || lossGuard()) ? 0 : eState.ref;')
      && substr_count($nj, 'egressZoneRef()') >= 3
      && str_contains($nj, "if (isBlacklist()) { showToast(t.key('js.net.toast_egress_blacklist'), 'warning'); return; }"));
@unlink($snmpFile); @rmdir($snmpDir);

// ── 5. bucketing ─────────────────────────────────────────────────────────────
check('bucket: 24 h of 60 s samples stays raw', netlimitBucketFor(86400, 60) === 0);
check('bucket: 30 d of 60 s samples is bucketed', netlimitBucketFor(2592000, 60) > 0);
check('bucket: 30 d fits under the point cap', (int)ceil(2592000 / netlimitBucketFor(2592000, 60)) <= NET_MAX_POINTS);
check('bucket: 14 d fits under the point cap', netlimitBucketFor(1209600, 60) === 0 || (int)ceil(1209600 / netlimitBucketFor(1209600, 60)) <= NET_MAX_POINTS);
check('bucket: a zero sample interval does not divide by zero', netlimitBucketFor(86400, 0) >= 0);

// ── 6. automatic mode ────────────────────────────────────────────────────────
$cfgAuto = ['net_auto_min' => '10000', 'net_auto_max' => '80000', 'net_auto_target' => '30000', 'net_auto_target_cpu' => '70'];
$st = ['over' => 0, 'under' => 0, 'last_move_at' => 0];
$now = 1800000000;

// one spike must not move anything — the whole point of the hysteresis
$d = netlimitAutoDecide($st, 50000, 40000, $cfgAuto, null, $now);
check('auto: first sample over target holds', $d['action'] === 'hold' && $d['state']['over'] === 1, $d['reason']);
$d = netlimitAutoDecide($d['state'], 50000, 40000, $cfgAuto, null, $now);
check('auto: second sample over target still holds', $d['action'] === 'hold' && $d['state']['over'] === 2);
$d3 = netlimitAutoDecide($d['state'], 50000, 40000, $cfgAuto, null, $now);
check('auto: third sample tightens', $d3['action'] === 'down' && $d3['pps'] === 36000, $d3['action'] . '/' . $d3['pps']);
check('auto: the counters reset after a move', $d3['state']['over'] === 0 && $d3['state']['under'] === 0);
check('auto: the move is recorded', $d3['state']['last_move_at'] === $now && $d3['state']['last_move'] === 'down');

// a sample on the other side clears the streak
$d = netlimitAutoDecide(['over' => 2, 'under' => 0, 'last_move_at' => 0], 10000, 40000, $cfgAuto, null, $now);
check('auto: a sample under target clears the over-streak', $d['state']['over'] === 0 && $d['state']['under'] === 1);
// inside the dead band (0.8×target … target) nothing accumulates at all
$d = netlimitAutoDecide(['over' => 2, 'under' => 0, 'last_move_at' => 0], 27000, 40000, $cfgAuto, null, $now);
check('auto: the dead band clears both streaks', $d['action'] === 'hold' && $d['state']['over'] === 0 && $d['state']['under'] === 0, $d['reason']);

$st3 = ['over' => 0, 'under' => 2, 'last_move_at' => 0];
$d = netlimitAutoDecide($st3, 10000, 40000, $cfgAuto, null, $now);
check('auto: third sample under target loosens', $d['action'] === 'up' && $d['pps'] === 44000, $d['action'] . '/' . $d['pps']);

// the band is a hard stop in both directions
$d = netlimitAutoDecide(['over' => 2, 'under' => 0, 'last_move_at' => 0], 50000, 10000, $cfgAuto, null, $now);
check('auto: never tightens below the band floor', $d['action'] === 'hold' && str_contains($d['reason'], 'floor'), $d['reason']);
$d = netlimitAutoDecide(['over' => 0, 'under' => 2, 'last_move_at' => 0], 10000, 80000, $cfgAuto, null, $now);
check('auto: never loosens above the band ceiling', $d['action'] === 'hold' && str_contains($d['reason'], 'ceiling'), $d['reason']);

// the cool-down stops it walking the limit down once a minute
$d = netlimitAutoDecide(['over' => 3, 'under' => 0, 'last_move_at' => $now - 10], 50000, 40000, $cfgAuto, null, $now);
check('auto: cool-down blocks a second move', $d['action'] === 'hold' && str_contains($d['reason'], 'cool-down'), $d['reason']);
$d = netlimitAutoDecide(['over' => 3, 'under' => 0, 'last_move_at' => $now - NET_AUTO_MIN_INTERVAL - 1], 50000, 40000, $cfgAuto, null, $now);
check('auto: the move is allowed once the cool-down is over', $d['action'] === 'down');

// the CPU guard tightens even while the packet rate is under target
$st = ['over' => 0, 'under' => 0, 'last_move_at' => 0];
for ($i = 0; $i < 2; $i++) $st = netlimitAutoDecide($st, 5000, 40000, $cfgAuto, 0.95, $now)['state'];
$d = netlimitAutoDecide($st, 5000, 40000, $cfgAuto, 0.95, $now);
check('auto: an overloaded machine tightens despite a low packet rate', $d['action'] === 'down' && str_contains($d['reason'], 'load'), $d['reason']);
$st = ['over' => 0, 'under' => 0, 'last_move_at' => 0];
$d = netlimitAutoDecide($st, 5000, 40000, $cfgAuto, 0.30, $now);
check('auto: a quiet machine does not trigger the CPU guard', $d['state']['under'] === 1 && $d['state']['over'] === 0);

// ── 7. the root helper, end to end against a stub nft ─────────────────────────
$helper = $root . '/tools/opentracker/tracker-netlimit.sh';
check('helper script is in the repo', is_file($helper));

$bash = null;
foreach (['bash', '/bin/bash', '/usr/bin/bash', 'C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe'] as $cand) {
    $probe = (str_contains($cand, ' ') ? '"' . $cand . '"' : $cand) . ' -c "echo ok" 2>&1';
    $out = []; $rc = null;
    @exec($probe, $out, $rc);
    if ($rc === 0 && trim(implode('', $out)) === 'ok') { $bash = $cand; break; }
}

if ($bash === null || !trackerExecAvailable()) {
    skip('helper: end-to-end against a stub nft', 'no usable bash (or exec() disabled) on this machine — run the suite on the server for this half');
} else {
    $tmp = sys_get_temp_dir() . '/netlimit_test_' . getmypid();
    // Start from an empty tree. The teardown at the bottom clears the directories with rmdir(),
    // which refuses one that still holds a file, and the name is only the pid — which this platform
    // hands out again soon enough. A leftover state/replaced then fails "a fresh table is loaded"
    // while the helper had in fact reloaded: a check that accuses working code once every few
    // batteries, and the only kind of failure worse than none is one nobody believes.
    foreach (['/bin', '/nftd', '/state', ''] as $stale) {
        foreach ((array)@glob($tmp . $stale . '/*') as $f) { if (is_file($f)) @unlink($f); }
    }
    @mkdir($tmp . '/bin', 0777, true);
    @mkdir($tmp . '/nftd', 0777, true);
    @mkdir($tmp . '/state', 0777, true);

    // stub nft: enough of the real command's surface for the helper's five actions
    file_put_contents($tmp . '/bin/nft', <<<'STUB'
#!/bin/bash
S="${STUB_STATE:?}"
# On request, write a line to stderr on every call. The helper is invoked with 2>&1, so this is how
# a stray shell/kernel message reaches the panel -- and it must never end up INSIDE the JSON.
[ -n "${STUB_NOISE:-}" ] && printf 'nft: warning: something on stderr\n' >&2
case "$1" in
  -c) shift; [ "$1" = "-f" ] && shift
      grep -q 'SYNTAX_ERROR' "$1" && { echo "syntax error" >&2; exit 1; }; exit 0 ;;
  -f) shift
      grep -q 'SYNTAX_ERROR' "$1" && { echo "load error" >&2; exit 1; }
      cp "$1" "$S/loaded"; touch "$S/t_in"; exit 0 ;;
  -a) shift
      if [ "$1$2" = "listchain" ] && [ "$4" = "ottrack" ]; then
        [ -f "$S/t_out" ] || exit 1
        printf '\t\tlimit rate over %s/second counter name capped drop # handle 12\n' "$(cat "$S/epps" 2>/dev/null || echo 50000)"; exit 0; fi
      if [ "$1$2" = "listchain" ] && [ "$4" = "ottrack_in" ]; then
        [ -f "$S/t_in" ] || exit 1
        sed -n '/chain input/,/^}/p' "$S/loaded" | sed 's/limit rate over.*drop$/& # handle 9/'; exit 0; fi
      if [ "$1$2" = "listtable" ] && [ "$3/$4" = "inet/filter" ] && [ -f "$S/manual" ]; then
        printf 'table inet filter {\n\tchain input {\n\t\tudp dport 6969 limit rate over 30000/second burst 5 packets counter packets 4360134031 bytes 248000000000 drop # handle 7\n\t}\n}\n'; exit 0; fi
      exit 0 ;;
  list)
      case "$2" in
        tables) [ -f "$S/t_in" ] && echo "table inet ottrack_in"
                [ -f "$S/t_out" ] && echo "table inet ottrack"
                [ -f "$S/manual" ] && echo "table inet filter"; exit 0 ;;
        counters)
                if [ "$5" = "ottrack_in" ]; then [ -f "$S/t_in" ] || exit 1
                  printf 'table inet ottrack_in {\n\tcounter in_total {\n\t\tpackets 1000 bytes 64000\n\t}\n\tcounter in_passed {\n\t\tpackets 900 bytes 57600\n\t}\n\tcounter in_capped {\n\t\tpackets 100 bytes 6400\n\t}\n}\n'; exit 0; fi
                if [ "$5" = "ottrack" ]; then [ -f "$S/t_out" ] || exit 1
                  printf 'table inet ottrack {\n\tcounter announce_ok {\n\t\tpackets 20 bytes 1\n\t}\n\tcounter passed_good {\n\t\tpackets 30 bytes 2\n\t}\n\tcounter capped {\n\t\tpackets 40 bytes 3\n\t}\n}\n'; exit 0; fi
                exit 1 ;;
        chain)  if [ "$4" = "ottrack_in" ]; then [ -f "$S/t_in" ] || exit 1; sed -n '/chain input/,/^}/p' "$S/loaded"; exit 0; fi
                if [ "$4" = "ottrack" ]; then [ -f "$S/t_out" ] || exit 1
                  printf '\t\tlimit rate over %s/second counter name capped drop\n' "$(cat "$S/epps" 2>/dev/null || echo 50000)"; exit 0; fi
                exit 1 ;;
        table)  touch "$S/dumped_$4"
                case "$4" in ottrack_in) [ -f "$S/t_in" ] && exit 0 || exit 1 ;;
                             ottrack) [ -f "$S/t_out" ] && exit 0 || exit 1 ;;
                             filter) [ -f "$S/manual" ] && exit 0 || exit 1 ;; esac; exit 1 ;;
      esac; exit 1 ;;
  delete) [ "$2" = "table" ] && [ "$4" = "ottrack_in" ] && { rm -f "$S/t_in"; exit 0; }; exit 1 ;;
  replace)
      p=""; for a in "$@"; do case "$a" in */second) p="${a%/second}" ;; esac; done
      b=""; prev=""; for a in "$@"; do [ "$prev" = "burst" ] && b="$a"; prev="$a"; done
      if [ "$4" = "ottrack_in" ]; then
        sed -i -E "s#limit rate over [0-9]+/second burst [0-9]+ packets#limit rate over $p/second burst $b packets#" "$S/loaded"
        touch "$S/replaced"; exit 0
      fi
      echo "$p" >"$S/epps"; exit 0 ;;
esac
exit 1
STUB);
    // stub id: the helper refuses to touch the firewall unless it is root, which no test runner is
    file_put_contents($tmp . '/bin/id', "#!/bin/bash\n[ \"\$1\" = \"-u\" ] && { echo 0; exit 0; }\nexec /usr/bin/id \"\$@\"\n");
    @chmod($tmp . '/bin/nft', 0755);
    @chmod($tmp . '/bin/id', 0755);
    // the include line must carry the same spelling of the directory the helper is given (the helper
    // greps for it literally), so use the forward-slash form on every platform
    $nftDirPosix = str_replace('\\', '/', $tmp) . '/nftd';
    file_put_contents($tmp . '/nftables.conf', "flush ruleset\ninclude \"$nftDirPosix/*.nft\"\n");

    // The helper's test hooks travel through the environment rather than the command line: quoting a
    // `VAR=x bash -c "…"` prologue portably across cmd.exe and sh is not worth the bugs.
    $posix = static function (string $p): string { return str_replace('\\', '/', $p); };
    $pathBefore = (string)getenv('PATH');
    putenv('STUB_STATE=' . $posix($tmp . '/state'));
    putenv('NFT_BIN=' . $posix($tmp . '/bin/nft'));
    putenv('NFT_DIR=' . $posix($tmp . '/nftd'));
    putenv('NFT_CONF=' . $posix($tmp . '/nftables.conf'));
    putenv('PATH=' . $tmp . DIRECTORY_SEPARATOR . 'bin' . PATH_SEPARATOR . $pathBefore);
    $bashCmd = str_contains($bash, ' ') ? '"' . $bash . '"' : $bash;
    $run = static function (string $args) use ($bashCmd, $helper, $posix): array {
        $cmd = $bashCmd . ' ' . escapeshellarg($posix($helper)) . ' ' . $args . ' 2>&1';
        $out = []; $rc = null;
        @exec($cmd, $out, $rc);
        $txt = implode("\n", $out);
        $json = null;
        foreach (array_reverse($out) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '{') { $json = json_decode($line, true); if (is_array($json)) break; $json = null; }
        }
        return ['rc' => (int)$rc, 'out' => $txt, 'json' => $json];
    };

    $r = $run('-h');
    check('helper: bash can run it', $r['rc'] === 0, $r['out']);

    // argument validation — nothing is created and the exit code is non-zero
    foreach ([['set 10', 'rate below the floor'], ['set 99999999', 'rate above the ceiling'], ['set abc', 'non-numeric rate'],
              ['set 30000 0', 'burst below the floor'], ['set 30000 100 70000', 'port out of range'], ['bogus', 'unknown action']] as [$args, $what]) {
        $r = $run($args);
        check("helper: refuses $what", $r['rc'] !== 0 && is_array($r['json']) && $r['json']['ok'] === false, $r['out']);
    }
    check('helper: nothing was written while validating', !is_file($tmp . '/nftd/ottrack-in.nft'));

    // dry-run: valid JSON with the ruleset inside, still nothing on disk
    $r = $run('set 30000 100 6969 --dry-run');
    check('helper: dry-run succeeds', $r['rc'] === 0 && is_array($r['json']) && !empty($r['json']['dry_run']), $r['out']);
    $ruleset = (string)($r['json']['ruleset'] ?? '');
    check('helper: dry-run output is valid JSON with a multi-line ruleset', str_contains($ruleset, "\n") && str_contains($ruleset, 'table inet ottrack_in'));
    check('helper: the ruleset carries the requested rate', str_contains($ruleset, 'limit rate over 30000/second burst 100 packets'));
    check('helper: the ruleset only touches the configured port', str_contains($ruleset, 'udp dport != 6969 accept'));
    // without this line a non-UDP packet would fall past the port match into the drop budget
    check('helper: the ruleset lets non-UDP traffic out of the chain first', str_contains($ruleset, 'meta l4proto != udp accept'));
    check('helper: the chain accepts by default', str_contains($ruleset, 'policy accept'));
    check('helper: the chain runs before the default filter hook', str_contains($ruleset, 'priority filter - 5'));
    // create-if-missing → delete → recreate in ONE nft -f transaction: no unprotected gap
    check('helper: the ruleset replaces itself atomically', str_contains($ruleset, "table inet ottrack_in {}\ndelete table inet ottrack_in"));
    check('helper: it says how to undo itself', str_contains($ruleset, 'nft delete table inet ottrack_in'));
    check('helper: dry-run wrote nothing', !is_file($tmp . '/nftd/ottrack-in.nft'));

    // apply
    touch($tmp . '/state/t_out');     // the egress budget exists on this machine
    touch($tmp . '/state/manual');    // ... and so does a hand-made rule on the same port
    $r = $run('set 40000 200 6969');
    check('helper: apply succeeds', $r['rc'] === 0 && !empty($r['json']['applied']), $r['out']);
    check('helper: the file was persisted', is_file($tmp . '/nftd/ottrack-in.nft'));
    check('helper: it reports persistence correctly', ($r['json']['persistent'] ?? null) === true);

    check('helper: a fresh table is loaded, not replaced', ($r['json']['mode'] ?? '') === 'reload' && !is_file($tmp . '/state/replaced'), (string)($r['json']['mode'] ?? ''));

    $r = $run('status');
    $s = $r['json'];
    check('helper: status is valid JSON', is_array($s), $r['out']);
    check('helper: status sees the table', ($s['table'] ?? null) === true);
    check('helper: status reads the rate back', (int)($s['pps'] ?? 0) === 40000, json_encode($s['pps'] ?? null));
    check('helper: status reads the burst back', (int)($s['burst'] ?? 0) === 200);
    check('helper: status reads the port back', (int)($s['port'] ?? 0) === 6969);
    check('helper: status returns the three counters', isset($s['counters']['in_total']['packets'], $s['counters']['in_passed']['packets'], $s['counters']['in_capped']['packets']));
    check('helper: counters parse as numbers', (int)$s['counters']['in_total']['packets'] === 1000 && (int)$s['counters']['in_capped']['packets'] === 100);
    check('helper: the egress budget is reported', ($s['egress']['table'] ?? null) === true && (int)($s['egress']['pps'] ?? 0) === 50000);
    check('helper: egress counters are reported', (int)($s['egress']['counters']['capped']['packets'] ?? -1) === 40);
    // the admin's own rule in inet filter: reported, never touched
    check('helper: a foreign rule on the same port is reported', count($s['manual_rules'] ?? []) === 1, json_encode($s['manual_rules'] ?? []));
    check('helper: the foreign rule names its table', ($s['manual_rules'][0]['table'] ?? '') === 'filter' && ($s['manual_rules'][0]['family'] ?? '') === 'inet');
    check('helper: the foreign rule comes with a copy-paste undo', ($s['manual_rules'][0]['undo'] ?? '') === 'nft delete rule inet filter input handle 7', $s['manual_rules'][0]['undo'] ?? '');
    check('helper: a full status says so', ($s['brief'] ?? null) === false);

    // The foreign-rule scan dumps every table in the ruleset — on a box with fail2ban that is
    // thousands of lines — so the panel polls with --brief and only rescans every couple of minutes.
    $r = $run('status --brief');
    $b = $r['json'];
    check('helper: --brief still answers with the essentials', is_array($b) && ($b['table'] ?? null) === true && (int)($b['pps'] ?? 0) === 40000, $r['out']);
    check('helper: --brief keeps the counters', (int)($b['counters']['in_total']['packets'] ?? -1) === 1000);
    check('helper: --brief keeps the egress budget', (int)($b['egress']['pps'] ?? 0) === 50000);
    check('helper: --brief skips the expensive scan', ($b['brief'] ?? null) === true && !array_key_exists('manual_rules', $b), json_encode(array_keys($b ?: [])));

    // …and it must not dump a table to find out whether that table exists. `nft list table` prints
    // every element of every set in it, and the egress budget holds a dynamic set of up to 262 144
    // client addresses: measured on production with the set full, the existence check alone cost
    // 5.5 s of one core, on every poll. The name list costs 26 ms. The stub records any dump, so a
    // future edit that "just asks nft for the table" fails here rather than on the live machine.
    @unlink($tmp . '/state/dumped_ottrack');
    @unlink($tmp . '/state/dumped_ottrack_in');
    $run('status');
    check('helper: status does not dump the egress table to test that it exists',
          !is_file($tmp . '/state/dumped_ottrack'));
    check('helper: nor the inbound one', !is_file($tmp . '/state/dumped_ottrack_in'));

    // ── counting-only mode ──
    // The counters live in the firewall, so "measure first, then pick a threshold" needs a table
    // with no drop rule in it at all. Anything else would record zeros and make the suggestion a lie.
    $r = $run('monitor 6969 --dry-run');
    check('helper: monitor --dry-run renders a ruleset', ($r['json']['dry_run'] ?? null) === true && ($r['json']['mode'] ?? '') === 'count', $r['out']);
    $mon = (string)($r['json']['ruleset'] ?? '');
    // the header comment explains the intent in prose, so assert against the RULES only
    $monRules = implode("
", array_filter(explode("
", $mon), fn($l) => !str_starts_with(ltrim($l), '#')));
    check('helper: the counting ruleset has the three counters', str_contains($monRules, 'counter name in_total') && str_contains($monRules, 'counter name in_passed'));
    check('helper: the counting ruleset contains NO drop at all', !str_contains($monRules, 'drop'), $monRules);
    check('helper: … and no rate limit', !str_contains($monRules, 'limit rate'));
    check('helper: it still only looks at the tracker port', str_contains($monRules, 'udp dport != 6969 accept') && str_contains($monRules, 'meta l4proto != udp accept'));
    check('helper: it still accepts by default', str_contains($monRules, 'policy accept'));
    check('helper: its header says it is a counter', str_contains($mon, 'mode=count') && str_contains($mon, 'pps=0'));

    $r = $run('monitor 6969');
    check('helper: monitor applies', ($r['json']['applied'] ?? null) === true && ($r['json']['mode'] ?? '') === 'count', $r['out']);
    $r = $run('status');
    check('helper: status reports the counting mode', ($r['json']['mode'] ?? '') === 'count', json_encode($r['json']['mode'] ?? null));
    check('helper: counting mode reports no rate', (int)($r['json']['pps'] ?? -1) === 0, json_encode($r['json']['pps'] ?? null));
    check('helper: counting mode still returns the counters', isset($r['json']['counters']['in_total']['packets']));
    check('helper: an invalid port is refused for monitor too', $run('monitor 99999')['rc'] !== 0);

    // back to a real limit, and the mode flips
    $r = $run('set 40000 200 6969');
    check('helper: going from counting to limiting reloads the table', ($r['json']['mode'] ?? '') === 'reload', (string)($r['json']['mode'] ?? ''));
    $r = $run('status');
    check('helper: status reports the limiting mode', ($r['json']['mode'] ?? '') === 'limit' && (int)($r['json']['pps'] ?? 0) === 40000, json_encode([$r['json']['mode'] ?? null, $r['json']['pps'] ?? null]));

    // Changing only the rate on a table that is already there must NOT rebuild it: the three counters
    // would restart and the monitor, which reads rates as differences, would lose a chart sample on
    // every automatic ±10 % move.
    $r = $run('set 50000 200 6969');
    check('helper: an existing table is edited in place', ($r['json']['mode'] ?? '') === 'replace' && is_file($tmp . '/state/replaced'), (string)($r['json']['mode'] ?? '') . ' ' . $r['out']);
    $r = $run('status');
    check('helper: the in-place edit took effect', (int)($r['json']['pps'] ?? 0) === 50000, json_encode($r['json']['pps'] ?? null));
    check('helper: the in-place edit is persisted to the file too', str_contains((string)@file_get_contents($tmp . '/nftd/ottrack-in.nft'), 'limit rate over 50000/second'));
    // a different port is a different rule, so that one does go through a full reload
    @unlink($tmp . '/state/replaced');
    $r = $run('set 50000 200 6970');
    check('helper: changing the port reloads the table', ($r['json']['mode'] ?? '') === 'reload', (string)($r['json']['mode'] ?? ''));
    $r = $run('set 40000 200 6969');   // back to the values the rest of the test expects
    check('helper: back on the original port', ($r['json']['mode'] ?? '') === 'reload' && (int)($r['json']['pps'] ?? 0) === 40000);

    // nft omits `burst N packets` from its output when the burst is its own default, so the values
    // are also read back from the generated file's header. Simulate a flushed ruleset: file kept,
    // table gone.
    @unlink($tmp . '/state/t_in');
    $r = $run('status');
    check('helper: values survive a flushed ruleset via the file header',
          ($r['json']['table'] ?? null) === false && (int)($r['json']['pps'] ?? 0) === 40000
          && (int)($r['json']['burst'] ?? 0) === 200 && (int)($r['json']['port'] ?? 0) === 6969,
          json_encode([$r['json']['table'] ?? null, $r['json']['pps'] ?? null, $r['json']['burst'] ?? null]));
    touch($tmp . '/state/t_in');
    $r = $run('status');
    $s = $r['json'];

    // PHP reads exactly what the helper wrote
    $in = netlimitCounterPackets((array)$s['counters'], NET_IN_COUNTERS);
    check('PHP reads the helper counters', $in === ['in_total' => 1000, 'in_passed' => 900, 'in_capped' => 100], json_encode($in));

    // ── the outbound budget has to survive a reboot too ──────────────────────
    // Reported from production: the budget set from the panel was back at its old value after a
    // restart. The old code guarded the file write with `[ -w "$EGRESS_FILE" ]`, which is false
    // inside php-fpm's read-only /etc — so the write was skipped in silence and the live rule and
    // the file drifted apart, discoverable only by rebooting.
    file_put_contents($tmp . '/state/t_out', '');   // the egress table exists in the stub
    file_put_contents($tmp . '/nftd/ottrack.nft',
        "#!/usr/sbin/nft -f\ntable inet ottrack {\n  chain output {\n" .
        "    limit rate over 50000/second counter name capped drop\n  }\n}\n");
    file_put_contents($tmp . '/state/epps', '50000');

    $r = $run('status');
    check('egress: status reports what the FILE says, not just the live rule',
          (int)($r['json']['egress']['file_pps'] ?? -1) === 50000, json_encode($r['json']['egress'] ?? null));
    check('egress: … and that the two agree', ($r['json']['egress']['file_matches'] ?? null) === true);

    $r = $run('egress 90000');
    check('egress: the change applies', $r['rc'] === 0 && (int)($r['json']['pps'] ?? 0) === 90000, $r['out']);
    check('egress: … and the file is written, not skipped', ($r['json']['file_updated'] ?? null) === true, $r['out']);
    check('egress: … so the file now says the new number',
          str_contains((string)@file_get_contents($tmp . '/nftd/ottrack.nft'), 'limit rate over 90000/second'),
          (string)@file_get_contents($tmp . '/nftd/ottrack.nft'));
    $r = $run('status');
    check('egress: live and saved agree again', ($r['json']['egress']['file_matches'] ?? null) === true
          && (int)($r['json']['egress']['file_pps'] ?? 0) === 90000, json_encode($r['json']['egress'] ?? null));

    // a file left behind at the old value is exactly the reported bug, and `persist` must fix it
    file_put_contents($tmp . '/nftd/ottrack.nft',
        str_replace('90000', '50000', (string)@file_get_contents($tmp . '/nftd/ottrack.nft')));
    $r = $run('status');
    check('egress: a drifted file is reported as a mismatch', ($r['json']['egress']['file_matches'] ?? null) === false,
          json_encode($r['json']['egress'] ?? null));
    $r = $run('persist');
    check('egress: persist rewrites the budget file too', $r['rc'] === 0
          && str_contains((string)@file_get_contents($tmp . '/nftd/ottrack.nft'), 'limit rate over 90000/second'),
          (string)@file_get_contents($tmp . '/nftd/ottrack.nft'));
    $r = $run('status');
    check('egress: … and the mismatch is gone', ($r['json']['egress']['file_matches'] ?? null) === true);
    check('egress: the inbound file was not disturbed by any of this',
          str_contains((string)@file_get_contents($tmp . '/nftd/ottrack-in.nft'), 'ottrack_in'));

    // egress rate change (handle-targeted, so the dynamic client sets survive)
    $r = $run('egress 60000');
    check('helper: egress rate applied', $r['rc'] === 0 && (int)($r['json']['pps'] ?? 0) === 60000, $r['out']);
    $r = $run('status');
    check('helper: the new egress rate reads back', (int)($r['json']['egress']['pps'] ?? 0) === 60000);
    $r = $run('egress 10');
    check('helper: egress rate is validated too', $r['rc'] !== 0);

    // ── a failure the helper has recovered from must stop being news ─────────
    // A one-off used to sit on the card in red for ever: nothing ever cleared last_error, so a
    // fault that was fixed minutes ago still looked live.
    netlimitStateUpdate(function (array &$st) {
        $st['last_error'] = 'something went wrong once';
        $st['last_error_at'] = time() - 60;
        $st['last_ok_at'] = 0;
        return true;
    });
    $st = netlimitStateRead();
    check('state: a failure newer than the last clean answer is still shown',
          (int)$st['last_error_at'] > (int)($st['last_ok_at'] ?? 0));
    netlimitStateUpdate(function (array &$st) { $st['last_ok_at'] = time(); return true; });
    $st = netlimitStateRead();
    check('state: … and is hidden once the helper answers cleanly again',
          (int)$st['last_error_at'] <= (int)$st['last_ok_at']);
    check('state: … but the record itself is kept, not erased',
          $st['last_error'] === 'something went wrong once');
    netlimitStateUpdate(function (array &$st) {
        $st['last_error'] = null; $st['last_error_at'] = 0; $st['last_ok_at'] = 0; return true;
    });

    // ── stray stderr must never land inside the JSON ─────────────────────────
    // This is what broke a healthy firewall in production: dir_writable() probed the directory with
    // `: >"$probe" 2>/dev/null`, bash applies redirections left to right, so the "Read-only file
    // system" message went to the REAL stderr before 2>/dev/null existed -- from inside a command
    // substitution in the middle of building the reply. The line was spliced into the JSON, PHP
    // could not parse it, and the card reported the firewall as unavailable while it was fine.
    putenv('STUB_NOISE=1');
    $r = $run('status');
    check('helper: a line on stderr does not corrupt the status JSON',
          is_array($r['json']) && ($r['json']['ok'] ?? null) === true, $r['out']);
    // The helper silences nft's own stderr per command, so it never reaches the reply in the first
    // place. Worth pinning down: that is a deliberate property, not an accident.
    check('helper: … and nft stderr never reaches the reply', !str_contains($r['out'], 'something on stderr'), $r['out']);
    check('helper: … and the JSON itself is one unbroken line',
          count(array_filter(preg_split('/\R/', $r['out']), static fn($l) => str_starts_with(trim($l), '{'))) === 1, $r['out']);
    check('helper: check survives stderr noise too', is_array($r['json']) && isset($r['json']['nft']), $r['out']);
    putenv('STUB_NOISE');
    $r = $run('status');
    check('helper: back to a clean run', is_array($r['json']) && !str_contains($r['out'], 'something on stderr'), $r['out']);

    // ── does the file on disk actually describe what is loaded? ──────────────
    // This is the production failure that motivated the check: the rule reached the kernel, the
    // file never got written, and `persistent` still said true because a file happened to exist.
    // A reboot would then have quietly restored a completely different ruleset.
    $r = $run('status');
    check('helper: a file matching what is loaded counts as persistent',
          ($r['json']['persistent'] ?? null) === true && ($r['json']['file_matches'] ?? null) === true, $r['out']);

    $goodFile = (string)@file_get_contents($tmp . '/nftd/ottrack-in.nft');
    file_put_contents($tmp . '/nftd/ottrack-in.nft',
        "#!/usr/sbin/nft -f
# tracker-netlimit: pps=0 burst=0 port=6969 mode=count generated=x
table inet ottrack_in {}
");
    $r = $run('status');
    check('helper: a saved ruleset that differs from the loaded one is NOT persistent',
          ($r['json']['persistent'] ?? null) === false, json_encode($r['json']['persistent'] ?? null));
    check('helper: … the file is still reported as present', ($r['json']['file_present'] ?? null) === true);
    check('helper: … and the mismatch is named', ($r['json']['file_matches'] ?? null) === false && ($r['json']['file_mode'] ?? '') === 'count',
          json_encode([$r['json']['file_matches'] ?? null, $r['json']['file_mode'] ?? null]));

    $r = $run('persist');
    check('helper: persist rewrites the file from what is loaded', $r['rc'] === 0 && ($r['json']['saved'] ?? null) === true, $r['out']);
    check('helper: … with the rate that is actually in force',
          str_contains((string)@file_get_contents($tmp . '/nftd/ottrack-in.nft'), 'limit rate over 40000/second burst 200 packets'),
          (string)@file_get_contents($tmp . '/nftd/ottrack-in.nft'));
    $r = $run('status');
    check('helper: persistence reads true again', ($r['json']['persistent'] ?? null) === true, $r['out']);
    $r = $run('persist');
    check('helper: persist is a no-op when the file already matches',
          $r['rc'] === 0 && ($r['json']['saved'] ?? null) === false && ($r['json']['in_sync'] ?? null) === true, $r['out']);

    // a different RATE on disk is a mismatch too, not just a different mode
    file_put_contents($tmp . '/nftd/ottrack-in.nft',
        str_replace('pps=40000', 'pps=12345', (string)@file_get_contents($tmp . '/nftd/ottrack-in.nft')));
    $r = $run('status');
    check('helper: a saved rate that differs from the loaded one is a mismatch',
          ($r['json']['file_matches'] ?? null) === false && (int)($r['json']['file_pps'] ?? 0) === 12345, $r['out']);
    $run('persist');

    // a save that fails for a REAL reason is still an error — the deferred path below is only for a
    // directory this process cannot write at all
    file_put_contents($tmp . '/bin/install', "#!/bin/bash
exit 1
");
    @chmod($tmp . '/bin/install', 0755);
    $r = $run('set 41000 200 6969');
    check('helper: a save that fails for a real reason is reported as an error',
          $r['rc'] !== 0 && str_contains((string)($r['json']['error'] ?? ''), 'could not be saved'), $r['out']);
    @unlink($tmp . '/bin/install');

    // The panel's PHP runs under systemd ProtectSystem on a hardened box: /etc is read-only inside
    // that mount namespace, root included. That is not a failed apply and must not be reported as one.
    @chmod($tmp . '/nftd', 0555);
    clearstatcache();
    $writable = @file_put_contents($tmp . '/nftd/.probe', 'x');
    if ($writable === false) {
        $r = $run('set 42000 200 6969');
        check('helper: a read-only directory defers the save instead of failing the apply',
              $r['rc'] === 0 && ($r['json']['applied'] ?? null) === true && ($r['json']['persist_deferred'] ?? null) === true, $r['out']);
        check('helper: … and says plainly that it is not saved yet',
              ($r['json']['saved'] ?? null) === false && ($r['json']['persistent'] ?? null) === false
              && str_contains((string)($r['json']['persist_hint'] ?? ''), 'janitor'), $r['out']);
        $c = $run('check');
        check('helper: check flags the directory before anyone applies anything',
              ($c['json']['dir_writable'] ?? null) === false && str_contains((string)($c['json']['hint'] ?? ''), 'read-only'), $c['out']);
        check('helper: … but does not call it a failure', ($c['json']['ok'] ?? null) === true, $c['out']);
        // THE production failure: probing an unwritable directory made the SHELL print "Read-only
        // file system", from inside a command substitution in the middle of building the reply. The
        // line was spliced into the JSON and the card reported the firewall as unavailable.
        $st = $run('status');
        check('helper: probing an unwritable directory does not corrupt the status JSON',
              is_array($st['json']) && ($st['json']['ok'] ?? null) === true, $st['out']);
        check('helper: … the reply is still exactly one JSON line',
              count(array_filter(preg_split('/\R/', $st['out']), static fn($l) => str_starts_with(trim($l), '{'))) === 1, $st['out']);
        check('helper: … and nothing leaked about the write probe',
              !str_contains($st['out'], '.wtest') && !str_contains($st['out'], 'Read-only file system'), $st['out']);
        @chmod($tmp . '/nftd', 0777);
        $r = $run('persist');
        check('helper: the janitor finishes the save afterwards', $r['rc'] === 0 && ($r['json']['saved'] ?? null) === true, $r['out']);
        check('helper: … with the rate that was actually applied',
              str_contains((string)@file_get_contents($tmp . '/nftd/ottrack-in.nft'), 'limit rate over 42000/second'));
    } else {
        @unlink($tmp . '/nftd/.probe');
        @chmod($tmp . '/nftd', 0777);
        skip('helper: a read-only directory defers the save',
             'this filesystem ignores chmod on directories (Windows, or running as root) — run the suite on the server for this half');
    }
    $run('set 40000 200 6969');
    if (!is_file($tmp . '/nftd/ottrack-in.nft')) file_put_contents($tmp . '/nftd/ottrack-in.nft', $goodFile);

    // off — table and file both gone, nothing else disturbed
    $r = $run('off --dry-run');
    check('helper: off --dry-run changes nothing', $r['rc'] === 0 && is_file($tmp . '/nftd/ottrack-in.nft') && is_file($tmp . '/state/t_in'));
    $r = $run('off');
    check('helper: off succeeds', $r['rc'] === 0 && ($r['json']['table_deleted'] ?? null) === true && ($r['json']['file_removed'] ?? null) === true, $r['out']);
    check('helper: the file is gone', !is_file($tmp . '/nftd/ottrack-in.nft'));
    check('helper: the table is gone', !is_file($tmp . '/state/t_in'));
    check('helper: the egress budget survived the undo', is_file($tmp . '/state/t_out'));
    check('helper: the admin\'s own rule survived the undo', is_file($tmp . '/state/manual'));

    // nft rejecting the ruleset must not leave anything applied
    $r = $run('check');
    check('helper: check answers with JSON', is_array($r['json']) && isset($r['json']['nft']), $r['out']);
    check('helper: check finds the include line', ($r['json']['include_ok'] ?? null) === true);

    // and with a nftables.conf that does NOT include the drop-in dir, persistence is reported false
    file_put_contents($tmp . '/nftables.conf', "flush ruleset\n");
    $r = $run('check');
    check('helper: a missing include is reported', ($r['json']['include_ok'] ?? null) === false && ($r['json']['ok'] ?? null) === false);
    check('helper: and it says what to add', str_contains((string)($r['json']['hint'] ?? ''), 'include'));

    // ── probe-start: the probe as a unit of its own ─────────────────────────
    // The janitor is a oneshot service; a background child of it is killed when it exits. So the
    // helper starts the probe through systemd-run, as the caller, with arguments it has checked.
    // Stubs: systemd-run records its argv, systemctl answers is-active from STUB_ACTIVE, and an
    // interpreter that does nothing stands in for python.
    file_put_contents($tmp . '/bin/systemd-run', "#!/bin/bash\nfor a in \"\$@\"; do printf '%s\\n' \"\$a\"; done >\"\$STUB_STATE/sdrun\"\nexit 0\n");
    file_put_contents($tmp . '/bin/systemctl', "#!/bin/bash\ncase \"\$1\" in is-active) [ \"\${STUB_ACTIVE:-0}\" = 1 ] && echo active || echo inactive; exit 0 ;; reset-failed) touch \"\$STUB_STATE/reset\"; exit 0 ;; esac\nexit 1\n");
    file_put_contents($tmp . '/bin/pystub', "#!/bin/bash\nexit 0\n");
    foreach (['systemd-run', 'systemctl', 'pystub'] as $b) @chmod($tmp . '/bin/' . $b, 0755);
    // the script path the helper accepts is POSIX-absolute and ends in /tools/tuner.py; on Git Bash
    // that is /c/Users/… rather than C:/Users/…
    $script = preg_replace_callback('#^([A-Za-z]):/#', fn($m) => '/' . strtolower($m[1]) . '/', $posix($root . '/tools/tuner.py'));
    putenv('SYSTEMCTL_BIN=' . $posix($tmp . '/bin/systemctl'));
    putenv('SYSTEMD_RUN=' . $posix($tmp . '/bin/systemd-run'));
    putenv('STUB_ACTIVE=0');

    foreach ([['probe-start', 'no script'], ['probe-start /etc/passwd --run', 'a script that is not tools/tuner.py'],
              ['probe-start tools/tuner.py --run', 'a relative script path'],
              ["probe-start $script --python pystub", 'neither --run nor --dry-run'],
              ["probe-start $script --python pystub --run --steps 99", 'steps out of range'],
              ["probe-start $script --python pystub --run --dwell 5", 'dwell out of range'],
              ["probe-start $script --python pystub --run --what sideways", 'an unknown limit to move'],
              ["probe-start $script --python pystub --run --bogus", 'an argument it does not know'],
              ["probe-start $script --python 'py;rm' --run", 'an interpreter with shell characters'],
              ["probe-start $script --python no-such-interpreter-here --run", 'an interpreter that is not there']] as [$args, $what]) {
        @unlink($tmp . '/state/sdrun');
        $r = $run($args);
        check("probe-start: refuses $what", $r['rc'] !== 0 && is_array($r['json']) && $r['json']['ok'] === false && !is_file($tmp . '/state/sdrun'), $r['out']);
    }

    $r = $run("probe-start $script --python pystub --dry-run --steps 3 --dwell 45 --what outbound");
    $argv = is_file($tmp . '/state/sdrun') ? file($tmp . '/state/sdrun', FILE_IGNORE_NEW_LINES) : [];
    check('probe-start: starts it through systemd-run', $r['rc'] === 0 && ($r['json']['via'] ?? '') === 'unit' && ($r['json']['unit'] ?? '') === 'tracker-probe.service', $r['out']);
    check('probe-start: as a named, collected unit', in_array('--unit=tracker-probe', $argv, true) && in_array('--collect', $argv, true), implode(' ', $argv));
    $uidArg = array_values(array_filter($argv, fn($a) => str_starts_with($a, '--uid=')));
    check('probe-start: as the caller, never root', $uidArg !== [] && $uidArg[0] !== '--uid=0' && ($r['json']['uid'] ?? 0) !== 0, implode(' ', $argv));
    $sep = array_search('--', $argv, true);
    $cmd = $sep === false ? [] : array_slice($argv, $sep + 1);
    check('probe-start: the command is interpreter, script, mode and the checked numbers — nothing else',
          count($cmd) === 9 && str_ends_with($cmd[0], 'pystub') && $cmd[1] === $script
          && array_slice($cmd, 2) === ['--dry-run', '--steps', '3', '--dwell', '45', '--what', 'outbound'], implode(' ', $cmd));
    check('probe-start: with a lowered priority, like the janitor itself', in_array('--property=Nice=10', $argv, true));

    // ── janitor-heavy-start: the janitor's slow half as a unit of its own ─────
    $jscript = preg_replace_callback('#^([A-Za-z]):/#', fn($m) => '/' . strtolower($m[1]) . '/', $posix($root . '/tools/janitor.php'));
    putenv('STUB_ACTIVE=0');
    foreach ([['janitor-heavy-start', 'no script'], ['janitor-heavy-start /etc/passwd', 'a script that is not tools/janitor.php'],
              ['janitor-heavy-start tools/janitor.php', 'a relative script path'],
              ["janitor-heavy-start $jscript --verbose", 'an argument it does not know']] as [$args, $what]) {
        @unlink($tmp . '/state/sdrun');
        $r = $run($args);
        check("janitor-heavy-start: refuses $what", $r['rc'] !== 0 && is_array($r['json']) && $r['json']['ok'] === false && !is_file($tmp . '/state/sdrun'), $r['out']);
    }
    @unlink($tmp . '/state/sdrun');
    $r = $run("janitor-heavy-start $jscript");
    $argv = is_file($tmp . '/state/sdrun') ? file($tmp . '/state/sdrun', FILE_IGNORE_NEW_LINES) : [];
    check('janitor-heavy-start: starts it through systemd-run', $r['rc'] === 0 && ($r['json']['via'] ?? '') === 'unit' && ($r['json']['unit'] ?? '') === 'tracker-janitor-heavy.service', $r['out']);
    check('janitor-heavy-start: as a named, collected unit at a lowered priority',
          in_array('--unit=tracker-janitor-heavy', $argv, true) && in_array('--collect', $argv, true) && in_array('--property=Nice=10', $argv, true), implode(' ', $argv));
    $uidArg = array_values(array_filter($argv, fn($a) => str_starts_with($a, '--uid=')));
    check('janitor-heavy-start: as the caller, never root', $uidArg !== [] && $uidArg[0] !== '--uid=0', implode(' ', $argv));
    $sep = array_search('--', $argv, true);
    $cmd = $sep === false ? [] : array_slice($argv, $sep + 1);
    check('janitor-heavy-start: the command is php, the script and --heavy — nothing else',
          count($cmd) === 3 && str_contains($cmd[0], 'php') && $cmd[1] === $jscript && $cmd[2] === '--heavy', implode(' ', $cmd));
    putenv('STUB_ACTIVE=1');
    @unlink($tmp . '/state/sdrun');
    $r = $run("janitor-heavy-start $jscript");
    check('janitor-heavy-start: one at a time — refuses with active:true while the previous run is going',
          $r['rc'] === 5 && ($r['json']['active'] ?? false) === true && !is_file($tmp . '/state/sdrun'), $r['out']);
    putenv('STUB_ACTIVE=0');

    putenv('STUB_ACTIVE=1');
    @unlink($tmp . '/state/sdrun');
    $r = $run("probe-start $script --python pystub --run");
    check('probe-start: refuses while a probe unit is active', $r['rc'] === 5 && str_contains((string)($r['json']['error'] ?? ''), 'already running') && !is_file($tmp . '/state/sdrun'), $r['out']);
    putenv('STUB_ACTIVE=0');

    putenv('SYSTEMD_RUN=/nonexistent/systemd-run');
    $r = $run("probe-start $script --python pystub --run");
    check('probe-start: without systemd-run it says so by name, so the panel can fall back', $r['rc'] === 6 && ($r['json']['no_systemd'] ?? null) === true && ($r['json']['ok'] ?? null) === false, $r['out']);
    check('probe-start: … and starts nothing itself', !is_file($tmp . '/state/sdrun'));

    // clean up
    foreach (['/bin/nft', '/bin/id', '/bin/systemd-run', '/bin/systemctl', '/bin/pystub', '/nftables.conf', '/nftd/ottrack-in.nft', '/state/loaded', '/state/t_in', '/state/t_out', '/state/manual', '/state/epps', '/state/sdrun', '/state/reset'] as $f) @unlink($tmp . $f);
    foreach (['/bin', '/nftd', '/state', ''] as $d) @rmdir($tmp . $d);
    putenv('PATH=' . $pathBefore);
    foreach (['STUB_STATE', 'STUB_NOISE', 'STUB_ACTIVE', 'NFT_BIN', 'NFT_DIR', 'NFT_CONF', 'SYSTEMD_RUN', 'SYSTEMCTL_BIN'] as $v) putenv($v);
}

// ── 8. storage: sampling, retention and the series the chart reads ───────────
// Needs the local test database (deploy/local_bootstrap.php); skipped with a visible line otherwise,
// so the pure half above still runs on a bare checkout.
$db = null;
if (is_file($root . '/config/database.php')) {
    require_once $root . '/config/database.php';
    require_once $root . '/includes/settings.php';
    require_once $root . '/includes/schema.php';
    try {
        $db = getDb();
        $cfgDb = getSettings($db);
        ensureSchema($db, $cfgDb);
    } catch (\Throwable $e) {
        $db = null;
        skip('storage: net_samples round-trip', 'no test database: ' . $e->getMessage());
    }
} else {
    skip('storage: net_samples round-trip', 'config/database.php missing — run deploy/local_bootstrap.php first');
}

if ($db !== null) {
    check('schema version >= 11', (int)($cfgDb['schema_version'] ?? 0) >= 11, (string)($cfgDb['schema_version'] ?? 'none'));
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $stateFile = netlimitStateFile();
    $stateBackup = is_file($stateFile) ? file_get_contents($stateFile) : null;
    @unlink($stateFile);

    $cfgS = ['net_sample_seconds' => '60', 'net_keep_days' => '14'];
    $t0 = 1800000000;
    $mkStatus = static function (int $total, int $passed, int $capped, int $limit = 30000, int $eOk = 0, int $eGood = 0, int $eCap = 0): array {
        return [
            'ok' => true, 'pps' => $limit, 'table' => true,
            'counters' => ['in_total' => ['packets' => $total], 'in_passed' => ['packets' => $passed], 'in_capped' => ['packets' => $capped]],
            'egress' => ['counters' => ['announce_ok' => ['packets' => $eOk], 'passed_good' => ['packets' => $eGood], 'capped' => ['packets' => $eCap]]],
        ];
    };

    // the first reading has nothing to subtract from: remembered, but never stored as a data point
    $r = netlimitStoreSample($db, $cfgS, $mkStatus(0, 0, 0), $t0);
    check('storage: the first reading stores no row', $r['stored'] === false && $r['reason'] === 'first reading', $r['reason']);
    check('storage: … and the table is still empty', (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn() === 0);

    // 60 s later: 1 200 000 total / 1 080 000 served / 120 000 dropped → 20 000 / 18 000 / 2 000 pps
    $r = netlimitStoreSample($db, $cfgS, $mkStatus(1200000, 1080000, 120000, 40000, 300000, 300000, 60000), $t0 + 60);
    check('storage: the second reading stores a row', $r['stored'] === true, $r['reason']);
    $row = $db->query('SELECT * FROM `' . NET_SAMPLE_TABLE . '` ORDER BY ts DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    check('storage: rate arithmetic', (int)$row['pps_total'] === 20000 && (int)$row['pps_passed'] === 18000 && (int)$row['pps_capped'] === 2000,
          json_encode([$row['pps_total'], $row['pps_passed'], $row['pps_capped']]));
    check('storage: the span is recorded', (int)$row['span'] === 60);
    check('storage: the cumulative counters are kept too', (int)$row['in_total'] === 1200000 && (int)$row['in_capped'] === 120000);
    check('storage: the limit in force is recorded', (int)$row['limit_pps'] === 40000);
    check('storage: the egress rates are derived as well', (int)$row['epps_ok'] === 10000 && (int)$row['epps_capped'] === 1000,
          json_encode([$row['epps_ok'], $row['epps_capped']]));

    // an "Apply" recreates the table, so the counters restart at zero — that must never become a row
    $r = netlimitStoreSample($db, $cfgS, $mkStatus(5, 4, 1), $t0 + 120);
    check('storage: a counter reset stores nothing', $r['stored'] === false && $r['reason'] === 'counters restarted', $r['reason']);
    check('storage: … and leaves the earlier row alone', (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn() === 1);
    // …but the reading after the reset measures normally again
    $r = netlimitStoreSample($db, $cfgS, $mkStatus(600005, 600004, 1), $t0 + 180);
    check('storage: measuring resumes after a reset', $r['stored'] === true && (int)$r['pps']['in_total'] === 10000, json_encode($r['pps'] ?? null));

    // Nothing loaded means nothing is being measured. Storing the zeros it would otherwise read is
    // how a week of "median 0 pps" happens, and the suggestion built on it is worthless. It also
    // drops the cursor, because a table that comes back has counters that restarted.
    $noTable = ['ok' => true, 'pps' => 0, 'table' => false, 'counters' => [], 'egress' => ['counters' => []]];
    $before = (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn();
    $r = netlimitStoreSample($db, $cfgS, $noTable, $t0 + 240);
    check('storage: no table loaded means no sample at all', $r['stored'] === false && str_contains($r['reason'], 'nothing is counting'), $r['reason']);
    check('storage: … and no zero row was written', (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn() === $before
          && (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '` WHERE pps_total = 0')->fetchColumn() === 0);
    check('storage: … and the next reading starts from scratch',
          netlimitStoreSample($db, $cfgS, $mkStatus(10, 10, 0), $t0 + 300)['reason'] === 'first reading');

    // the series the chart reads
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $ins = $db->prepare('INSERT INTO `' . NET_SAMPLE_TABLE . '` (ts, span, pps_total, pps_passed, pps_capped, limit_pps) VALUES (?,60,?,?,?,30000)');
    for ($i = 0; $i < 240; $i++) $ins->execute([$t0 + $i * 60, 20000 + $i, 19000 + $i, $i]);
    $s = netlimitSeries($db, $cfgS, $t0, $t0 + 240 * 60);
    check('series: every raw point is returned', $s['points'] === 240 && $s['bucket'] === 0, json_encode([$s['points'], $s['bucket']]));
    check('series: the values come back in order', $s['series']['pps_total'][0] === 20000 && $s['series']['pps_total'][239] === 20239);
    check('series: a window returns only what it covers', netlimitSeries($db, $cfgS, $t0 + 60 * 100, $t0 + 60 * 110)['points'] === 11);

    // a wide range is bucketed; a burst that got clipped has to stay visible after bucketing
    $wide = netlimitSeries($db, $cfgS, $t0 - 2592000, $t0 + 240 * 60);
    check('series: a 30-day window is bucketed', $wide['bucket'] > 0);
    check('series: bucketing keeps the point count sane', $wide['points'] <= NET_MAX_POINTS);
    check('series: capped is bucketed by MAX, not by average', max($wide['series']['pps_capped']) === 239, (string)max($wide['series']['pps_capped']));

    // recommendation over the stored rows
    $rec = netlimitRecommend($db, $cfgS, 7, $t0 + 240 * 60);
    check('recommend: reads the samples back', $rec['samples'] === 240 && $rec['peak'] === 20239, json_encode([$rec['samples'], $rec['peak']]));
    check('recommend: names the window it used', $rec['days'] === 7);
    check('recommend: a window longer than the retention is clamped', netlimitRecommend($db, ['net_keep_days' => '3'], 30, $t0)['days'] === 3);

    // retention
    $db->prepare('INSERT INTO `' . NET_SAMPLE_TABLE . '` (ts, span, pps_total) VALUES (?,60,1)')->execute([$t0 - 30 * 86400]);
    $before = (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn();
    $pruned = netlimitPrune($db, $cfgS, $t0 + 240 * 60);
    $after = (int)$db->query('SELECT COUNT(*) FROM `' . NET_SAMPLE_TABLE . '`')->fetchColumn();
    check('prune: only the row past the retention is dropped', $pruned === 1 && $after === $before - 1, "$pruned / $before → $after");

    // ── the load study: where does THIS machine start to struggle ────────────
    // A packets-per-second number means nothing on its own. The value of this feature is entirely in
    // when it REFUSES to answer, so that is most of what is checked here: a threshold nobody
    // measured would send an admin to throttle a tracker that was coping fine.
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $seedLoad = static function (PDO $db, int $t0, int $n, callable $pps, callable $load) {
        $ins = $db->prepare("INSERT INTO `" . NET_SAMPLE_TABLE . "`
            (ts,span,in_total,in_passed,in_capped,out_ok,out_capped,pps_total,pps_passed,pps_capped,epps_ok,epps_capped,limit_pps,load_x100)
            VALUES (?,60,0,0,0,0,0,?,?,0,0,0,0,?)");
        for ($i = 0; $i < $n; $i++) {
            $p = (int)$pps($i);
            $l = $load($i, $p);
            $ins->execute([$t0 + $i * 60, $p + 1000, $p, $l === null ? null : (int)round($l * 100)]);
        }
    };
    $t0 = time() - 400 * 60;

    // too few readings
    $seedLoad($db, $t0, 30, static fn($i) => 10000 + $i * 1000, static fn($i, $p) => 0.2 + $i * 0.05);
    $lc = netlimitLoadCurve($db, $cfgS, 7);
    check('load study: refuses with too few samples', $lc['busy_pps'] === null && $lc['confident'] === false);
    check('load study: … and says why in words', str_contains($lc['why'], 'not enough readings'), $lc['why']);
    check('load study: … and names that sentence by its key and numbers (the card writes it so — 1.73.0)',
          ($lc['why_part']['key'] ?? '') === 'api.net.load_few' && ($lc['why_part']['vars']['n'] ?? null) === 30
          && $lc['why'] === netlimitSayParts([$lc['why_part']]), json_encode($lc['why_part'] ?? null));

    // plenty of readings but the rate never varied — a busy hour is not a busy machine
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $seedLoad($db, $t0, 200, static fn($i) => 40000 + ($i % 3) * 10, static fn($i, $p) => 1.4);
    $lc = netlimitLoadCurve($db, $cfgS, 7);
    check('load study: refuses when the traffic barely varied', $lc['busy_pps'] === null, json_encode($lc['busy_pps']));
    check('load study: … and names the range it saw', str_contains($lc['why'], 'barely varied'), $lc['why']);

    // a machine that never breaks a sweat gets no ceiling invented for it
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $seedLoad($db, $t0, 200, static fn($i) => 10000 + $i * 400, static fn($i, $p) => 0.10 + $p / 900000);
    $lc = netlimitLoadCurve($db, $cfgS, 7);
    check('load study: an idle machine gets no invented ceiling', $lc['busy_pps'] === null, json_encode($lc['busy_pps']));
    check('load study: … and says the box never got busy', str_contains($lc['why'], 'never reached'), $lc['why']);

    // and the case it exists for: load that climbs with traffic
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $seedLoad($db, $t0, 200, static fn($i) => 10000 + $i * 400,
              static fn($i, $p) => $p < 50000 ? 0.30 + $p / 200000 : 0.60 + ($p - 50000) / 70000);
    $lc = netlimitLoadCurve($db, $cfgS, 7);
    check('load study: finds the rate where the box got busy', is_int($lc['busy_pps']) && $lc['busy_pps'] > 50000 && $lc['busy_pps'] < 90000, json_encode($lc['busy_pps']));
    check('load study: … is confident about it', $lc['confident'] === true);
    check('load study: … and the curve rises with the traffic',
          count($lc['buckets']) >= 8 && $lc['buckets'][0]['load'] < $lc['buckets'][count($lc['buckets']) - 1]['load'],
          json_encode(array_column($lc['buckets'], 'load')));
    check('load study: every bucket says how many readings are behind it',
          count(array_filter($lc['buckets'], static fn($b) => $b['n'] >= 1)) === count($lc['buckets']));
    check('load study: peak and quiet load are reported', $lc['peak_load'] > $lc['quiet_load']);

    // one unlucky minute must not become a ceiling
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $seedLoad($db, $t0, 200, static fn($i) => 10000 + $i * 400,
              static fn($i, $p) => $i === 7 ? 4.0 : 0.20);   // a single spike, low everywhere else
    $lc = netlimitLoadCurve($db, $cfgS, 7);
    check('load study: a single spike is not a ceiling', $lc['busy_pps'] === null, json_encode($lc['busy_pps']));

    // rows without a load reading are skipped, not counted as zero
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $seedLoad($db, $t0, 200, static fn($i) => 10000 + $i * 400, static fn($i, $p) => $i % 2 ? null : 1.5);
    $lc = netlimitLoadCurve($db, $cfgS, 7);
    check('load study: readings with no load recorded are skipped', $lc['samples'] === 100, (string)$lc['samples']);
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');

    // MAIN-2 (1.73.3): a hot bucket of three readings is not "the busiest median". Before, the sentence said "never reached
    // a load of 0.85 … (busiest median 1.20)" — the max was read from every bucket, the ceiling only from the counted ones.
    $seedLoad($db, $t0, 200, static fn($i) => $i < 197 ? 10000 + $i * 350 : 90000, static fn($i, $p) => $i < 197 ? 0.30 : 1.20);
    $lc = netlimitLoadCurve($db, $cfgS, 7);
    preg_match('/busiest median ([0-9.]+)/', $lc['why'], $mMax);
    check('load study (MAIN-2): three hot readings are no ceiling, and "the busiest median" is read from the rates that count',
          $lc['busy_pps'] === null && isset($mMax[1]) && (float)$mMax[1] < NET_LOAD_BUSY && ($lc['why_part']['vars']['max'] ?? '') === '0.30'
          && !str_contains($lc['why'], 'busiest median 1.2'), $lc['why']);
    check('load study (MAIN-2): … the hotter rate is a sentence of its own — its rate, its load, its readings',
          ($lc['thin_part']['key'] ?? '') === 'api.net.load_thin' && ($lc['thin_part']['vars']['n'] ?? null) === 3
          && ($lc['thin_part']['vars']['load'] ?? '') === '1.20' && ($lc['thin_part']['vars']['min'] ?? null) === NET_LOAD_MIN_BUCKET
          && $lc['why'] === netlimitSayParts([$lc['why_part'], $lc['thin_part']]) && str_contains($lc['why'], 'too few readings'), json_encode($lc['thin_part']));
    check('load study (MAIN-2): … and the card writes it after the first, as a keyed word',
          str_contains($nlJs, 'const thin = saidPart(lc2.why_part) ? saidPart(lc2.thin_part) : null;')
          && str_contains($nlJs, "[t.key('js.net.load_study', {why: saidPart(lc2.why_part) || lc2.why}), thin ? ' ' : null, thin]"));
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $seedLoad($db, $t0, 200, static fn($i) => 10000 + $i * 400, static fn($i, $p) => 0.10 + $p / 900000);
    check('load study (MAIN-2): with no thin hot rate there is no second sentence', netlimitLoadCurve($db, $cfgS, 7)['thin_part'] === null);
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');

    // ── the loss outside, stored (1.73.3): /proc/net/snmp through TRACKER_PROC_SNMP, a fixture file ──
    $snmpDir2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'netlimit_snmp_db_' . getmypid();
    @mkdir($snmpDir2, 0777, true);
    $snmpFix = $snmpDir2 . DIRECTORY_SEPARATOR . 'snmp';
    putenv('TRACKER_PROC_SNMP=' . $snmpFix);
    @unlink($stateFile);
    $tS = 1800000000;
    file_put_contents($snmpFix, $snmpText(1000000, 10000));
    $r = netlimitStoreSample($db, $cfgS, $mkStatus(0, 0, 0), $tS);
    $stL = netlimitStateRead();
    check('stored loss: the first reading keeps the counters (tcp_prev) and has no share yet',
          $r['stored'] === false && ($stL['tcp_prev'] ?? null) === ['out' => 1000000, 'retrans' => 10000, 'at' => $tS]
          && is_array($stL['tcp_retrans_now'] ?? null) && array_key_exists('x10', $stL['tcp_retrans_now']) && $stL['tcp_retrans_now']['x10'] === null
          && ($stL['tcp_retrans_now']['read'] ?? null) === true, json_encode($stL['tcp_retrans_now'] ?? null));
    file_put_contents($snmpFix, $snmpText(1010000, 11560));
    $r = netlimitStoreSample($db, $cfgS, $mkStatus(1200000, 1080000, 120000, 90000), $tS + 60);
    $row = $db->query('SELECT tcp_retrans_x10 FROM `' . NET_SAMPLE_TABLE . '` WHERE ts = ' . ($tS + 60))->fetch(PDO::FETCH_ASSOC);
    $stL = netlimitStateRead();
    check('stored loss: 1 560 resent of 10 000 is written into the row as 156 and into the state for the card',
          $r['stored'] === true && $r['retrans_x10'] === 156 && $row !== false && (int)$row['tcp_retrans_x10'] === 156
          && ($stL['tcp_retrans_now']['x10'] ?? null) === 156 && ($stL['tcp_retrans_now']['segs'] ?? null) === 10000
          && ($stL['tcp_retrans_now']['at'] ?? null) === $tS + 60 && ($stL['tcp_prev']['out'] ?? null) === 1010000, json_encode([$row, $stL['tcp_retrans_now'] ?? null]));
    $olS = netlimitOutsideLoss($stL['tcp_retrans_now'], null, $tS + 70, 60);
    check('stored loss: … which admin/net_status reads as 15.6 %, bad', $olS['pct'] === 15.6 && $olS['level'] === 'bad' && $olS['at'] === $tS + 60, json_encode($olS));
    file_put_contents($snmpFix, $snmpText(1010200, 11570));
    netlimitStoreSample($db, $cfgS, $mkStatus(2400000, 2160000, 240000, 90000), $tS + 120);
    $row = $db->query('SELECT tcp_retrans_x10 FROM `' . NET_SAMPLE_TABLE . '` WHERE ts = ' . ($tS + 120))->fetch(PDO::FETCH_ASSOC);
    check('stored loss: fewer than ' . NET_LOSS_MIN_SEGMENTS . ' segments over the span is NULL in the row — not 50 per 1 000',
          $row !== false && $row['tcp_retrans_x10'] === null && netlimitOutsideLoss(netlimitStateRead()['tcp_retrans_now'], null, $tS + 130, 60)['why'] === 'few', json_encode($row));
    putenv('TRACKER_PROC_SNMP=' . $snmpDir2 . DIRECTORY_SEPARATOR . 'missing');
    netlimitStoreSample($db, $cfgS, $mkStatus(3600000, 3240000, 360000, 90000), $tS + 180);
    $row = $db->query('SELECT tcp_retrans_x10 FROM `' . NET_SAMPLE_TABLE . '` WHERE ts = ' . ($tS + 180))->fetch(PDO::FETCH_ASSOC);
    $stL = netlimitStateRead();
    check('stored loss: a machine without /proc/net/snmp stores NULL, keeps no counters, and says it cannot read them',
          $row !== false && $row['tcp_retrans_x10'] === null && array_key_exists('tcp_prev', $stL) && $stL['tcp_prev'] === null
          && netlimitOutsideLoss($stL['tcp_retrans_now'], null, $tS + 190, 60)['why'] === 'unreadable', json_encode($stL['tcp_retrans_now'] ?? null));
    putenv('TRACKER_PROC_SNMP=' . $snmpFix);
    file_put_contents($snmpFix, $snmpText(1100000, 12000));
    $r = netlimitStoreSample($db, $cfgS, ['ok' => true, 'pps' => 0, 'table' => false, 'counters' => [], 'egress' => ['counters' => []]], $tS + 240);
    file_put_contents($snmpFix, $snmpText(1200000, 13000));
    $r = netlimitStoreSample($db, $cfgS, ['ok' => true, 'pps' => 0, 'table' => false, 'counters' => [], 'egress' => ['counters' => []]], $tS + 300);
    check('stored loss: with no table of ours loaded no row is written, but the share is still read for the card (1 000 of 100 000)',
          $r['stored'] === false && $r['retrans_x10'] === 10 && (netlimitStateRead()['tcp_retrans_now']['x10'] ?? null) === 10, json_encode($r));
    putenv('TRACKER_PROC_SNMP');
    @unlink($snmpFix); @rmdir($snmpDir2);

    // the last hour's median, and the busiest hour this machine is known to have coped with
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $insL = $db->prepare('INSERT INTO `' . NET_SAMPLE_TABLE . '` (ts, span, pps_total, pps_passed, pps_capped, limit_pps, tcp_retrans_x10) VALUES (?,60,?,?,0,90000,?)');
    $tH0 = intdiv($tS, 3600) * 3600;                       // a whole hour, so each fixture hour is one hour of the GROUP BY
    for ($i = 0; $i < 60; $i++) $insL->execute([$tH0 - 3 * 3600 + 60 * $i, 82000, 82000, 19]);                  // coped: 82 000 at 1.9 %
    for ($i = 0; $i < 60; $i++) $insL->execute([$tH0 - 2 * 3600 + 60 * $i, 88000, 88000, $i < 50 ? 156 : 160]); // busier, but losing
    for ($i = 0; $i < 4; $i++)  $insL->execute([$tH0 - 1 * 3600 + 60 * $i, 99000, 99000, 10]);                 // calm but four readings
    for ($i = 4; $i < 60; $i++) $insL->execute([$tH0 - 1 * 3600 + 60 * $i, 99000, 99000, null]);               // … the rest unread
    check('safe hour: the busiest hour with an ok share (82 000 at 1.9 %) — not the busier one losing 15.6 %, not one of four readings',
          netlimitSafeHistoryCap($db, $tH0) === 82000, (string)netlimitSafeHistoryCap($db, $tH0));
    check('safe hour: none in the window → no cap', netlimitSafeHistoryCap($db, $tH0 + 30 * 86400) === null);
    $lh = netlimitLossHour($db, $tH0 - 3600);
    check('last hour: the median of its readings (15.6 %), how many, and what got through',
          $lh['median_x10'] === 156 && $lh['n'] === 60 && $lh['passed'] === 88000, json_encode($lh));
    $olH = netlimitOutsideLoss(['x10' => 19, 'at' => $tH0 - 3600 - 10, 'read' => true], $lh, $tH0 - 3600, 60);
    check('last hour: ok now, but a bad hour behind it keeps the advice guarded', $olH['level'] === 'ok' && $olH['hour_level'] === 'bad' && $olH['guard'] === true, json_encode($olH));
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');

    // the janitor tick must not fork anything at all while everything is off
    $offCfg = ['net_monitor_enabled' => '0', 'net_auto_enabled' => '0', 'net_limit_cmd' => '/nonexistent/should-never-run.sh'];
    $tick = netlimitTick($db, $offCfg, $t0);
    check('tick: inert while the feature is off', $tick['enabled'] === false && $tick['sampled'] === false && $tick['error'] === null, json_encode($tick));

    // A deferred save must survive a monitor-less install. The early return above is what keeps a
    // disabled feature from forking a process every minute — and the deferred save used to sit
    // AFTER it, so a limit applied on such an install stayed live with a stale file for ever:
    // exactly the failure the deferred save exists to close, reappearing wherever nobody switched
    // the monitor on.
    netlimitStateUpdate(function (array &$s) { $s['persist_deferred'] = false; return true; });
    $tick = netlimitTick($db, $offCfg, $t0);
    check('tick: still inert when there is nothing pending to save',
          $tick['enabled'] === false && $tick['persisted'] === false, json_encode($tick));
    netlimitStateUpdate(function (array &$s) { $s['persist_deferred'] = true; return true; });
    $tick = netlimitTick($db, $offCfg, $t0);
    check('tick: a pending save wakes it up even with the monitor off', $tick['enabled'] === true, json_encode($tick));
    // The helper path here does not exist, so the save cannot succeed. What matters is that it was
    // ATTEMPTED and reported rather than silently skipped by the early return.
    check('tick: … and the failure is reported, not swallowed',
          $tick['persisted'] === false && $tick['error'] !== null, json_encode($tick));
    netlimitStateUpdate(function (array &$s) { $s['persist_deferred'] = false; $s['last_error'] = null; return true; });

    // ── the burst hint over the stored samples (1.72.0) ──────────────────────
    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    $tH = 1800000000;
    $insH = $db->prepare('INSERT INTO `' . NET_SAMPLE_TABLE . '` (ts, span, pps_total, pps_passed, pps_capped, limit_pps) VALUES (?,60,?,?,?,?)');
    for ($i = 0; $i < 60; $i++) $insH->execute([$tH - 3600 + 60 * ($i + 1), 121000, 80000, 41000, 90000]);
    $insH->execute([$tH - 7200, 121000, 10000, 111000, 90000]);        // older than the hour: not read
    $cfgOn = ['net_limit_enabled' => '1', 'net_limit_burst' => '100', 'net_limit_pps' => '90000'];
    $bh = netlimitBurstHint($db, $cfgOn, $tH);
    check('burst hint: the last hour of net_samples, the burst from Settings', $bh !== null && $bh['samples'] === 60 && $bh['served'] === 80000 && $bh['burst'] === 100 && $bh['suggested'] === 2000,
          json_encode($bh));
    check('burst hint: the burst the firewall reports wins over Settings (2 000 loaded: nothing to say)', netlimitBurstHint($db, $cfgOn, $tH, 2000) === null
          && netlimitBurstHint($db, ['net_limit_burst' => '2000'] + $cfgOn, $tH, 100) !== null);
    check('burst hint: the limit off in Settings says nothing', netlimitBurstHint($db, ['net_limit_enabled' => '0'] + $cfgOn, $tH) === null);
    check('burst hint: an hour later the samples are gone from its window', netlimitBurstHint($db, $cfgOn, $tH + 3601) === null);

    // ── handshakes over the timeline's hourly rows (1.72.0) ──────────────────
    // Fixture rows far from anything real (2033), deleted exactly; the table is otherwise left alone.
    require_once $root . '/includes/stats_timeline.php';
    $tT = intdiv(2000000000, 3600) * 3600;
    $tsT = [];
    $clashT = (int)$db->query('SELECT COUNT(*) FROM `stats_samples_1h` WHERE ts >= ' . ($tT - 200000))->fetchColumn();
    check('handshakes (db): nothing of the timeline sits in the fixtures\' time', $clashT === 0, (string)$clashT);
    $before1h = $db->query('SELECT COUNT(*), COALESCE(SUM(ts), 0) FROM `stats_samples_1h`')->fetch(PDO::FETCH_NUM);
    if ($clashT === 0) {
        try {
            $insT = $db->prepare('INSERT INTO `stats_samples_1h` (ts, samples, connects, udp_announces, uptime) VALUES (?,60,?,?,?)');
            foreach ($hrs(26, $tT - 25 * 3600, 48000, 32000) as $r) { $insT->execute([$r['ts'], $r['connects'], $r['udp_announces'], $r['uptime']]); $tsT[] = $r['ts']; }
            $cfgT = ['stats_timeline_enabled' => '1'];
            $hs = netlimitHandshakes($db, $cfgT, $tT + 1800);
            check('handshakes (db): the last hour and the last day, 1.50 each', $hs !== null && $hs['hour']['ratio'] == 1.5 && $hs['day']['ratio'] == 1.5
                  && $hs['hour']['to'] === $tT && $hs['day']['from'] === $tT - 86400, json_encode($hs));
            check('handshakes (db): hidden while the timeline is off', netlimitHandshakes($db, ['stats_timeline_enabled' => '0'], $tT + 1800) === null);
            check('handshakes (db): hidden when the newest hour is three hours old (the timeline stopped)', netlimitHandshakes($db, $cfgT, $tT + 3 * 3600 + 1) === null);
            // a restart six hours ago: the day says nothing, the last hour still does
            $db->exec('UPDATE `stats_samples_1h` SET uptime = uptime - 90000, connects = connects - 4000000000, udp_announces = udp_announces - 2900000000 WHERE ts >= ' . ($tT - 6 * 3600)
                      . ' AND ts <= ' . $tT);
            $hs = netlimitHandshakes($db, $cfgT, $tT + 1800);
            check('handshakes (db): a restart inside the day hides the day and keeps the hour', $hs !== null && $hs['day'] === null && $hs['hour']['ratio'] == 1.5, json_encode($hs));
        } finally {
            if ($tsT) $db->exec('DELETE FROM `stats_samples_1h` WHERE ts IN (' . implode(',', $tsT) . ')');
            $after1h = $db->query('SELECT COUNT(*), COALESCE(SUM(ts), 0) FROM `stats_samples_1h`')->fetch(PDO::FETCH_NUM);
            check('handshakes (db): the timeline table is exactly as it was', $after1h == $before1h, json_encode([$before1h, $after1h]));
        }
    }

    $db->exec('TRUNCATE TABLE `' . NET_SAMPLE_TABLE . '`');
    @unlink($stateFile);
    if ($stateBackup !== null) file_put_contents($stateFile, $stateBackup);
}

@unlink($stateFileAtStart);
if ($stateAtStart !== null) file_put_contents($stateFileAtStart, $stateAtStart);
check('the state file is exactly as the run found it', $stateAtStart === null ? !is_file($stateFileAtStart)
      : (string)@file_get_contents($stateFileAtStart) === $stateAtStart);

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails ? 1 : 0);
