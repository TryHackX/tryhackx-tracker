<?php
/**
 * The panel half of the metadata fetch order: includes/meta_order.php.
 *
 * The worker's half is covered by tests/meta_order_test.py, which also checks the two halves agree
 * on the list of selectors. This suite is about the one thing the panel is responsible for: what
 * comes out of the form always adds up to exactly 100, whatever went in. The worker re-normalises
 * too, but a queue running on shares that total 97 would be a silent 3 % of nothing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/meta_order.php';

$fails = 0; $n = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n;
    $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}

// ── the mode ─────────────────────────────────────────────────────────────────
check('a known mode survives', metaOrderNormalise('seeders', [])[0] === 'seeders');
check('case does not matter', metaOrderNormalise('SEEDERS', [])[0] === 'seeders');
check('surrounding space does not matter', metaOrderNormalise('  mix ', [])[0] === 'mix');
check('an unknown mode becomes the default', metaOrderNormalise('sideways', [])[0] === 'oldest');
check('an empty mode becomes the default', metaOrderNormalise('', [])[0] === 'oldest');
foreach (metaOrderModes() as $m) {
    check('mode "' . $m . '" is accepted', metaOrderNormalise($m, [])[0] === $m);
}

// ── the shares always total 100 ──────────────────────────────────────────────
// The property, checked over every shape a form can produce rather than over a handful of examples.
$cases = [
    'already correct'      => ['oldest' => 0, 'newest' => 15, 'seeders' => 70, 'random' => 15],
    'short of 100'         => ['oldest' => 10, 'newest' => 10, 'seeders' => 10, 'random' => 10],
    'over 100'             => ['oldest' => 90, 'newest' => 90, 'seeders' => 90, 'random' => 90],
    'one field only'       => ['seeders' => 40],
    'one field at 100'     => ['seeders' => 100],
    'awkward thirds'       => ['oldest' => 1, 'newest' => 1, 'seeders' => 1],
    'huge and tiny'        => ['seeders' => 999, 'random' => 1],
    'strings from a form'  => ['oldest' => '0', 'newest' => '15', 'seeders' => '70', 'random' => '15'],
    'a stray negative'     => ['seeders' => -20, 'newest' => 50],
    'nonsense beside real' => ['seeders' => 'lots', 'newest' => 30],
    'nothing at all'       => [],
];
foreach ($cases as $what => $in) {
    [, $out] = metaOrderNormalise('mix', $in);
    check('shares total exactly 100 (' . $what . ')', array_sum($out) === 100, json_encode($out));
    check('no share is out of range (' . $what . ')',
          count(array_filter($out, fn($v) => $v < 0 || $v > 100)) === 0, json_encode($out));
    check('every selector is present (' . $what . ')',
          array_keys($out) === metaOrderSelectors(), json_encode(array_keys($out)));
}

// Empty is not a configuration — it would leave the worker with nothing to rotate through, so it
// falls back to the shipped mix rather than silently becoming "longest waiting for ever".
check('every share at zero falls back to the shipped mix',
      metaOrderNormalise('mix', ['seeders' => 0])[1] === metaOrderDefaultMix());
check('the shipped mix itself totals 100', array_sum(metaOrderDefaultMix()) === 100);

// Normalising twice must not drift: the panel saves, reloads and saves again all the time.
[, $once] = metaOrderNormalise('mix', ['oldest' => 10, 'newest' => 10, 'seeders' => 10, 'random' => 10]);
[, $twice] = metaOrderNormalise('mix', $once);
check('normalising an already-normalised mix changes nothing', $once === $twice, json_encode([$once, $twice]));

// Proportions are kept, not flattened: 3:1 in must still be about 3:1 out.
[, $prop] = metaOrderNormalise('mix', ['seeders' => 30, 'newest' => 10]);
check('proportions survive rescaling', $prop['seeders'] === 75 && $prop['newest'] === 25, json_encode($prop));

// The rounding remainder goes to the largest share, where a point either way is least visible —
// and, more to the point, it never goes to a share the operator set to zero.
[, $thirds] = metaOrderNormalise('mix', ['oldest' => 1, 'newest' => 1, 'seeders' => 1]);
check('a share left at zero stays at zero after rescaling', $thirds['random'] === 0, json_encode($thirds));
check('the remainder lands on the largest share', max($thirds) === 34, json_encode($thirds));

// ── the definitions the rest of the panel builds on ──────────────────────────
check('there are four selectors', count(metaOrderSelectors()) === 4, json_encode(metaOrderSelectors()));
check('the modes are the selectors plus "mix"',
      metaOrderModes() === array_merge(metaOrderSelectors(), ['mix']), json_encode(metaOrderModes()));
check('every mode has a label',
      array_keys(metaOrderModeLabels()) === metaOrderModes(), json_encode(array_keys(metaOrderModeLabels())));
check('every selector has a share label',
      count(array_diff(metaOrderSelectors(), array_keys(metaOrderShareLabels()))) === 0);
check('the default mix names exactly the selectors',
      array_keys(metaOrderDefaultMix()) === metaOrderSelectors(), json_encode(array_keys(metaOrderDefaultMix())));
check('the rotation is 100 claims, so one point is one claim', META_ORDER_ROTATION === 100);

// ── every key is registered where it has to be ───────────────────────────────
// Four places, or the setting saves cleanly and does nothing. This project has shipped that bug.
$root = dirname(__DIR__);
$schema = (string)file_get_contents($root . '/includes/schema.php');
$save   = (string)file_get_contents($root . '/api/admin/save_settings.php');
$cat    = (string)file_get_contents($root . '/includes/settings_catalog.php');
$tpl    = (string)file_get_contents($root . '/templates/admin/settings.php');
$keys = ['meta_order_mode'];
foreach (metaOrderSelectors() as $sel) $keys[] = 'meta_order_mix_' . $sel;
foreach ($keys as $k) {
    check($k . ': has a schema default', str_contains($schema, "'" . $k . "'"));
    check($k . ': is in the save allow-list', str_contains($save, "'" . $k . "'"));
    check($k . ': is searchable', str_contains($cat, "'" . $k . "'"));
}
check('the form is built from this file, not a second copy of the list',
      str_contains($tpl, 'metaOrderModeLabels()') && str_contains($tpl, 'metaOrderShareLabels()'));
check('the save path normalises through this file', str_contains($save, 'metaOrderNormalise('));
// A settings-only change still needs a schema bump: the default rows are written by the migration
// block, which only runs when the VERSION moves.
check('the schema version was bumped for these defaults',
      preg_match('/TRACKER_SCHEMA_VERSION = (\d+)/', $schema, $m) && (int)$m[1] >= 30, $m[1] ?? '?');

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
