<?php
/**
 * The version constant, and the footer that shows it:
 *   php tests/version_test.php
 *
 * TRACKER_VERSION exists so the running code can answer "which build is this?" — a question that,
 * until 1.41.0, only CHANGELOG.md and a git tag could answer, and neither of those is on the server.
 * A constant that drifts from the changelog is worse than no constant at all, so the first check
 * here is that the two say the same thing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

check('TRACKER_VERSION looks like a version', (bool)preg_match('/^\d+\.\d+\.\d+$/', TRACKER_VERSION), TRACKER_VERSION);

$changelog = (string)@file_get_contents($root . '/CHANGELOG.md');
preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $m);
check('the changelog has a newest release heading', !empty($m[1]), substr($changelog, 0, 120));
check('… and it is the version the code reports', ($m[1] ?? '') === TRACKER_VERSION,
    ($m[1] ?? '(none)') . ' vs ' . TRACKER_VERSION);

// ── where it may appear ─────────────────────────────────────────────────────
// Four settings, four answers, and the default has to be one of them rather than a fifth thing.
foreach ([['none', false, false], ['public', false, true], ['panel', true, false], ['both', true, true]] as $case) {
    [$mode, $inPanel, $inPublic] = $case;
    check("version_display=$mode: panel " . ($inPanel ? 'yes' : 'no') . ", public " . ($inPublic ? 'yes' : 'no'),
        versionShown(['version_display' => $mode], true) === $inPanel
        && versionShown(['version_display' => $mode], false) === $inPublic);
}
// A value nobody recognises must not mean "show it everywhere". A settings row can also come from a
// restored backup or a MySQL client on a database three other applications share.
check('an unknown value falls back to the default rather than to "both"',
    versionShown(['version_display' => 'yes please'], true) === true
    && versionShown(['version_display' => 'yes please'], false) === false);
check('and so does a missing row', versionShown([], true) === true && versionShown([], false) === false);

require_once $root . '/includes/schema.php';
check('version_display ships as panel', (trackerSchemaDefaultSettings()['version_display'] ?? null) === 'panel',
    var_export(trackerSchemaDefaultSettings()['version_display'] ?? null, true));

// ── one footer, every page ──────────────────────────────────────────────────
// It used to be written inline in templates/layout.php, which no panel page includes — so the panel
// had no footer at all. Both facts are checked: the partial exists, and every page that renders a
// full document pulls it in.
$footer = (string)@file_get_contents($root . '/templates/footer.php');
check('the footer partial exists and carries the version line', str_contains($footer, 'versionShown($cfg, $footerInPanel)'));
check('… and it is not still duplicated in the layout',
    !preg_match('/footer_brand_enabled/', (string)@file_get_contents($root . '/templates/layout.php')));
check('the public layout includes it', str_contains((string)@file_get_contents($root . '/templates/layout.php'), "include __DIR__ . '/footer.php'"));

$missing = [];
foreach (glob($root . '/templates/admin/*.php') as $f) {
    $src = (string)@file_get_contents($f);
    if (!str_contains($src, '</html>')) continue;                 // partials render no document
    if (!str_contains($src, "include __DIR__ . '/../footer.php'")) $missing[] = basename($f);
}
check('every panel page includes it too', $missing === [], implode(', ', $missing));

// ── the search time budget ──────────────────────────────────────────────────
// php-fpm's own max_execution_time is 30 s on the live deployment, and a search killed by it came
// back as a bare 500 with nothing in any log. The endpoint asks for its own limit now.
$searchSrc = (string)@file_get_contents($root . '/api/index_search.php');
check('the search endpoint sets its own time limit', str_contains($searchSrc, 'set_time_limit($budget)'));
check('… clamped, because that row is editable from outside the panel',
    (bool)preg_match('/max\(10, min\(300, \(int\)\(\$cfg\[.search_time_budget.\]/', $searchSrc));
check('… and after session_write_close(), so a slow search does not hold the lock while it runs',
    strpos($searchSrc, 'session_write_close()') < strpos($searchSrc, 'set_time_limit($budget)'));
check('search_time_budget ships as 60', (trackerSchemaDefaultSettings()['search_time_budget'] ?? null) === '60',
    var_export(trackerSchemaDefaultSettings()['search_time_budget'] ?? null, true));

foreach (['version_display', 'search_time_budget'] as $key) {
    check("$key is in the save allow-list", str_contains((string)@file_get_contents($root . '/api/admin/save_settings.php'), "'$key'"));
    check("$key is in the search catalogue", str_contains((string)@file_get_contents($root . '/includes/settings_catalog.php'), "'$key'"));
    check("$key has a control on the Settings page", str_contains((string)@file_get_contents($root . '/templates/admin/settings.php'), 'name="' . $key . '"'));
}

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
