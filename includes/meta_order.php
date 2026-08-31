<?php
/**
 * Which pending hash the metadata worker takes next.
 *
 * ONE definition of the selectors, for a reason. The list has to agree across four places — the
 * schema defaults, the save allow-list, the form, and `worker/worker.py` — and a list repeated four
 * times is a list that will disagree with itself. It has happened here before: a setting registered
 * in three of the four places saves cleanly, reads back correctly in the panel, and does nothing.
 * So the panel side is defined here and the test suite compares it against the worker's own tuple.
 *
 * The worker holds the matching half (ORDER_SELECTORS / order_normalise) and re-normalises whatever
 * it finds in the settings table, because a value edited straight into the database never passes
 * through this file.
 */

if (!defined('META_ORDER_ROTATION')) {
    /** The mix repeats over this many claims, so one percentage point is one claim in a hundred —
     *  a share can be small, but it can never round down to "never". Mirrors ORDER_ROTATION. */
    define('META_ORDER_ROTATION', 100);
}

/** The selectors, in the order ties are broken (mirrors ORDER_SELECTORS in worker/worker.py). */
function metaOrderSelectors(): array {
    return ['oldest', 'newest', 'seeders', 'random'];
}

/** Everything the `meta_order_mode` setting may hold. */
function metaOrderModes(): array {
    return array_merge(metaOrderSelectors(), ['mix']);
}

/** The shipped mix: mostly the biggest swarms, with enough of the other two to stay current. */
function metaOrderDefaultMix(): array {
    return ['oldest' => 0, 'newest' => 15, 'seeders' => 70, 'random' => 15];
}

/**
 * Labels for the form. Kept next to the list rather than in the template so a selector cannot be
 * added to one and forgotten in the other.
 */
function metaOrderModeLabels(): array {
    return [
        'oldest'  => 'Longest waiting first',
        'newest'  => 'Newest first',
        'seeders' => 'Most seeders first',
        'random'  => 'Random',
        'mix'     => 'Balanced mix (shares below)',
    ];
}

/** Field labels for the four shares, biggest default first — that is the order they are edited in. */
function metaOrderShareLabels(): array {
    return [
        'seeders' => 'Most seeders',
        'newest'  => 'Newest',
        'random'  => 'Random',
        'oldest'  => 'Longest waiting',
    ];
}

/**
 * Whatever came out of the form -> a mode and four shares that add up to exactly 100.
 *
 * Normalised rather than merely validated, because the worker acts on these every few seconds and a
 * queue is not a good place to discover that four numbers add up to 97. The rounding remainder goes
 * to the largest share, where a point either way is least visible.
 *
 * Every share at zero is not a configuration, it is an empty one: it would leave the worker with
 * nothing to rotate through, so it falls back to the shipped mix rather than silently becoming
 * "longest waiting, for ever".
 *
 * @return array{0:string,1:array<string,int>}
 */
function metaOrderNormalise(string $mode, array $shares): array {
    $mode = strtolower(trim($mode));
    if (!in_array($mode, metaOrderModes(), true)) $mode = 'oldest';

    $names = metaOrderSelectors();
    $sh = [];
    foreach ($names as $nm) {
        $raw = $shares[$nm] ?? 0;
        $sh[$nm] = is_numeric($raw) ? max(0, min(100, (int)$raw)) : 0;
    }
    $sum = array_sum($sh);
    if ($sum <= 0) return [$mode, metaOrderDefaultMix()];
    if ($sum === 100) return [$mode, $sh];

    $scaled = [];
    foreach ($names as $nm) $scaled[$nm] = (int)floor($sh[$nm] * 100 / $sum);
    $rest = 100 - array_sum($scaled);
    $biggest = $names[0];
    foreach ($names as $nm) if ($scaled[$nm] > $scaled[$biggest]) $biggest = $nm;
    $scaled[$biggest] += $rest;
    // Keep the declared order of the keys: callers write them back as four separate settings rows
    // and a reordered array would make a diff out of a no-op.
    $out = [];
    foreach ($names as $nm) $out[$nm] = $scaled[$nm];
    return [$mode, $out];
}
