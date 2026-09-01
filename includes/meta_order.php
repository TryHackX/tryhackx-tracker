<?php
/**
 * Which pending hash the metadata worker takes next.
 *
 * ONE definition of the selectors, for a reason. The list has to agree across five places — the
 * schema defaults, the save allow-list, the form, the settings catalogue, and `worker/worker.py` —
 * and a list repeated five times is a list that will disagree with itself. It has happened here
 * before: a setting registered in three of the four places saves cleanly, reads back correctly in
 * the panel, and does nothing. So the panel side is defined here and the test suite compares it
 * against the worker's own tuple.
 *
 * The worker holds the matching half (ORDER_SELECTORS / order_normalise) and re-normalises whatever
 * it finds in the settings table, because a value edited straight into the database never passes
 * through this file.
 *
 * WHY THE LIST IS THE LENGTH IT IS
 * --------------------------------
 * A claim happens on every fetch slot — several times a second — so every selector has to be a sort
 * an EXISTING index can serve. Anything else is a filesort over three million rows at that rate. Two
 * indexes were added (schema v31) to make "seen most often" and "most completed" possible; the ones
 * that are still absent are absent on purpose, and metaOrderRejected() says which and why.
 */

if (!defined('META_ORDER_ROTATION')) {
    /** The mix repeats over this many claims, so one percentage point is one claim in a hundred —
     *  a share can be small, but it can never round down to "never". Mirrors ORDER_ROTATION. */
    define('META_ORDER_ROTATION', 100);
}

/** The orderings for the INDEX queue, in the order ties are broken (mirrors ORDER_SELECTORS). */
function metaOrderSelectors(): array {
    return ['oldest', 'newest', 'seeders', 'seen', 'completed', 'random'];
}

/** Everything the `meta_order_mode` setting may hold. */
function metaOrderModes(): array {
    return array_merge(metaOrderSelectors(), ['mix']);
}

/**
 * The keys the mix distributes over. `whitelist` is a mix-only share and deliberately not a mode:
 * outside the mix the whitelist always drains first, and "sort the whole index by whether it is on
 * the whitelist" is not a thing — a whitelisted hash is DELETED from the index on every poll
 * (see indexPoll), so there are none there to sort.
 */
function metaOrderMixKeys(): array {
    return array_merge(['whitelist'], metaOrderSelectors());
}

/**
 * The shipped mix. `whitelist => 0` is not "never": zero means the whitelist keeps ABSOLUTE
 * priority, exactly as it does in every non-mix mode, so upgrading changes nothing for anybody.
 * Give it a number and it becomes a guaranteed share of the rotation instead — which is what an
 * operator wants when a bulk import has put fifty thousand rows in front of the index.
 */
function metaOrderDefaultMix(): array {
    return ['whitelist' => 0, 'oldest' => 0, 'newest' => 15, 'seeders' => 70,
            'seen' => 0, 'completed' => 0, 'random' => 15];
}

/** Labels for the mode select. Next to the list so a mode cannot be added to one and not the other. */
function metaOrderModeLabels(): array {
    return [
        'oldest'    => 'Queue order — as added to pending (default)',
        'newest'    => 'Newest first',
        'seeders'   => 'Most seeders first',
        'seen'      => 'Seen most often first',
        'completed' => 'Most completed downloads first',
        'random'    => 'Random',
        'mix'       => 'Balanced mix (shares below)',
    ];
}

/** Field labels for the shares, in the order they are edited. */
function metaOrderShareLabels(): array {
    return [
        'whitelist' => 'Whitelist (registered)',
        'seeders'   => 'Most seeders',
        'newest'    => 'Newest',
        'seen'      => 'Seen most often',
        'completed' => 'Most completed',
        'random'    => 'Random',
        'oldest'    => 'Queue order',
    ];
}

/**
 * The index each ordering rides on. The panel shows this so an operator can see that a mode is not
 * a preference but a query plan; the worker checks it and refuses a selector whose index is missing
 * rather than starting a filesort over three million rows several times a second.
 *
 * null = needs no secondary index (the primary key, or a different table entirely).
 */
function metaOrderIndexes(): array {
    return [
        'oldest'    => 'idx_index_meta',
        'newest'    => 'idx_index_meta',
        'seeders'   => 'idx_index_meta_seed',
        'seen'      => 'idx_index_meta_seen',
        'completed' => 'idx_index_meta_completed',
        'random'    => null,
        'whitelist' => null,
    ];
}

/**
 * Orderings that were considered and left out, with the reason. Written down because "why can I not
 * sort by X" is the first question this screen invites, and because an absent option with no stated
 * reason reads as an oversight.
 */
function metaOrderRejected(): array {
    return [
        'Last seen'    => 'every hash in a poll is stamped with the same time, so "most recently seen" '
                        . 'sorts three million rows that are all equal — it would order by nothing.',
        'Peak seeders' => 'nearly the same ranking as "most seeders" on this data, for the cost of '
                        . 'another index on a table that is rewritten on every poll.',
        'Name / size / file count'
                       => 'not known until the metadata has been fetched, which is the thing being '
                        . 'ordered. Sorting the queue by the answer needs the answer.',
    ];
}

/**
 * Whatever came out of the form -> a mode and shares that add up to exactly 100.
 *
 * Normalised rather than merely validated, because the worker acts on these every few seconds and a
 * queue is not a good place to discover that the numbers add up to 97. The rounding remainder goes
 * to the largest share, where a point either way is least visible.
 *
 * Every share at zero is not a configuration, it is an empty one: it would leave the worker with
 * nothing to rotate through, so it falls back to the shipped mix rather than silently becoming
 * "queue order, for ever".
 *
 * @return array{0:string,1:array<string,int>}
 */
function metaOrderNormalise(string $mode, array $shares): array {
    $mode = strtolower(trim($mode));
    if (!in_array($mode, metaOrderModes(), true)) $mode = 'oldest';

    $names = metaOrderMixKeys();
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
    // Keep the declared order of the keys: callers write them back as separate settings rows and a
    // reordered array would make a diff out of a no-op.
    $out = [];
    foreach ($names as $nm) $out[$nm] = $scaled[$nm];
    return [$mode, $out];
}

/**
 * Which of the orderings this database can actually serve right now.
 *
 * The two indexes added in v31 are built by a heavy ALTER that the janitor runs out of band, so
 * there is a window — minutes on a big table — where the setting exists and the index does not.
 * The panel asks rather than assumes, and the worker does the same check for itself.
 *
 * @return array<string,bool> selector => usable
 */
function metaOrderAvailable(PDO $db): array {
    $have = [];
    try {
        $st = $db->prepare("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'index_hashes'");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) $have[(string)$n] = true;
    } catch (\Throwable $e) {
        // An unreadable information_schema says nothing about the indexes; claim nothing is missing
        // rather than disabling every selector on a permissions error.
        return array_fill_keys(metaOrderMixKeys(), true);
    }
    $out = [];
    foreach (metaOrderIndexes() as $sel => $idx) $out[$sel] = ($idx === null) || isset($have[$idx]);
    return $out;
}
