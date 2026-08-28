<?php
/**
 * Keeping the whitelist honest over time: refreshing swarm counts, and noticing what has died.
 *
 * A whitelist accumulates. Somebody registers a torrent, seeds it for a week and moves on, and five
 * years later the tracker is still serving a swarm with nobody in it. The row costs almost nothing,
 * but the LIST does: it is the file opentracker reloads, it is what the panel counts, and it is what
 * anybody looking at this tracker sees as its contents.
 *
 * Two jobs, both from the janitor, both off by default:
 *
 *   1. **Refresh** — re-scrape rows whose numbers are older than N hours, a batch at a time within a
 *      time budget. Without this the seeder counts on the whitelist are however old the last manual
 *      press of "Scrape now" happens to be.
 *   2. **Dead rows** — a row with no seeders and no leechers for N days. The DEFAULT ACTION IS TO
 *      MARK, NOT DELETE. An automation that quietly removes other people's registrations has to be
 *      something an operator chose, in as many words; it is not a reasonable default for anybody.
 *
 * A row that has never been scraped is never considered dead. No data is not the same as no peers,
 * and the difference matters most exactly when the scrape path is broken — which is when a
 * delete-on-zero rule would empty the whole list.
 */

function wlMaintRefreshHours(array $cfg): int {
    return max(0, min(8760, (int)($cfg['wl_scrape_every_hours'] ?? 0)));
}
function wlMaintBatch(array $cfg): int {
    return max(1, min(2000, (int)($cfg['wl_scrape_batch'] ?? 200) ?: 200));
}
function wlMaintDeadDays(array $cfg): int {
    return max(0, min(3650, (int)($cfg['wl_dead_after_days'] ?? 0)));
}
/** 'none' | 'mark' | 'delete' */
function wlMaintDeadAction(array $cfg): string {
    $a = (string)($cfg['wl_dead_action'] ?? 'mark');
    return in_array($a, ['none', 'mark', 'delete'], true) ? $a : 'mark';
}
function wlMaintDeadEveryDays(array $cfg): int {
    return max(1, min(365, (int)($cfg['wl_dead_every_days'] ?? 30) ?: 30));
}

function wlMaintState(): array {
    $s = netlimitStateRead();
    return is_array($s['wlmaint'] ?? null) ? $s['wlmaint'] : [];
}
function wlMaintStateSet(array $sub): void {
    netlimitStateUpdate(function (array &$s) use ($sub) { $s['wlmaint'] = $sub; return true; });
}

/**
 * How many rows would the dead-row rule touch right now.
 *
 * Used by the panel to say "this would affect 143 rows" BEFORE anything is switched on. An operator
 * turning on a delete rule should see the number first; finding out afterwards is not a workflow.
 */
function wlMaintDeadCount(PDO $db, array $cfg): int {
    $days = wlMaintDeadDays($cfg);
    if ($days <= 0) return 0;
    $st = $db->prepare(
        "SELECT COUNT(*) FROM whitelist
          WHERE banned = 0
            AND scraped_at IS NOT NULL
            AND scraped_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            AND COALESCE(scrape_seeders, 0) = 0
            AND COALESCE(scrape_leechers, 0) = 0");
    $st->execute([$days]);
    return (int)$st->fetchColumn();
}

/**
 * Refresh the stalest rows, within a time budget.
 *
 * Ordered by how old the numbers are, so a list larger than one batch is covered evenly instead of
 * the same first two hundred rows being refreshed for ever.
 */
function wlMaintRefreshTick(PDO $db, array $cfg): array {
    $out = ['ran' => false, 'scraped' => 0, 'failed' => 0, 'due' => 0];
    if (PHP_SAPI !== 'cli') return $out;
    $hours = wlMaintRefreshHours($cfg);
    if ($hours <= 0) return $out;

    $limit = wlMaintBatch($cfg);
    $st = $db->prepare(
        "SELECT id, info_hash, scrape_seeders, scrape_leechers, scrape_completed, scraped_at
           FROM whitelist
          WHERE banned = 0 AND (scraped_at IS NULL OR scraped_at < DATE_SUB(NOW(), INTERVAL ? HOUR))
          ORDER BY scraped_at IS NOT NULL, scraped_at ASC
          LIMIT $limit");
    $st->execute([$hours]);
    $rows = $st->fetchAll();
    if (!$rows) return $out;

    $out['ran'] = true;
    $out['due'] = count($rows);
    $r = scrapeOpenTrackerMany($db, $cfg, $rows);
    $out['scraped'] = (int)($r['scraped'] ?? 0);
    $out['failed'] = (int)($r['failed'] ?? 0);

    $state = wlMaintState();
    $state['last_refresh_at'] = time();
    $state['last_refresh'] = $out;
    wlMaintStateSet($state);
    return $out;
}

/**
 * The dead-row pass. Runs at most every `wl_dead_every_days`.
 *
 * `mark` sets a flag and nothing else — the row stays, stays served, and shows up in the panel as
 * something to look at. `delete` removes it. Both write what they did to the state file, because an
 * automation that removes registrations without leaving a record is one nobody can audit afterwards.
 */
function wlMaintDeadTick(PDO $db, array $cfg): array {
    $out = ['ran' => false, 'action' => wlMaintDeadAction($cfg), 'matched' => 0, 'marked' => 0, 'deleted' => 0];
    if (PHP_SAPI !== 'cli') return $out;
    $days = wlMaintDeadDays($cfg);
    if ($days <= 0 || $out['action'] === 'none') return $out;

    $state = wlMaintState();
    $every = wlMaintDeadEveryDays($cfg) * 86400;
    if (time() - (int)($state['last_dead_at'] ?? 0) < $every) return $out;

    $out['ran'] = true;
    $out['matched'] = wlMaintDeadCount($db, $cfg);

    if ($out['matched'] > 0 && $out['action'] === 'mark') {
        $st = $db->prepare(
            "UPDATE whitelist SET dead_since = COALESCE(dead_since, NOW())
              WHERE banned = 0 AND scraped_at IS NOT NULL
                AND scraped_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                AND COALESCE(scrape_seeders, 0) = 0 AND COALESCE(scrape_leechers, 0) = 0
                AND dead_since IS NULL");
        $st->execute([$days]);
        $out['marked'] = $st->rowCount();
    } elseif ($out['matched'] > 0 && $out['action'] === 'delete') {
        // In chunks, and by id, so a long-running delete cannot lock the table for the whole pass.
        $ids = $db->prepare(
            "SELECT id FROM whitelist
              WHERE banned = 0 AND scraped_at IS NOT NULL
                AND scraped_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                AND COALESCE(scrape_seeders, 0) = 0 AND COALESCE(scrape_leechers, 0) = 0
              LIMIT 5000");
        $ids->execute([$days]);
        $list = $ids->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_chunk($list, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $db->prepare("DELETE FROM whitelist_files WHERE whitelist_id IN ($ph)")->execute($chunk);
            $d = $db->prepare("DELETE FROM whitelist WHERE id IN ($ph)");
            $d->execute($chunk);
            $out['deleted'] += $d->rowCount();
        }
        if ($out['deleted'] > 0) {
            whitelistMarkDirty(true);
            whitelistRegenerate($db, $cfg);
            whitelistMaybeReload($cfg);
        }
    }

    $state['last_dead_at'] = time();
    $state['last_dead'] = $out;
    wlMaintStateSet($state);
    return $out;
}

/** Both jobs, for the janitor. Never throws: a maintenance pass must not take the tick with it. */
function wlMaintTick(PDO $db, array $cfg): array {
    $out = ['refresh' => null, 'dead' => null, 'error' => null];
    if (PHP_SAPI !== 'cli') return $out;
    try { $out['refresh'] = wlMaintRefreshTick($db, $cfg); }
    catch (\Throwable $e) { $out['error'] = $e->getMessage(); }
    try { $out['dead'] = wlMaintDeadTick($db, $cfg); }
    catch (\Throwable $e) { $out['error'] = ($out['error'] ? $out['error'] . '; ' : '') . $e->getMessage(); }
    return $out;
}

/** What the panel shows about all of this. Reads the cached state; never scrapes. */
function wlMaintStatus(PDO $db, array $cfg): array {
    $s = wlMaintState();
    return [
        'refresh_hours' => wlMaintRefreshHours($cfg),
        'batch' => wlMaintBatch($cfg),
        'last_refresh_at' => (int)($s['last_refresh_at'] ?? 0),
        'last_refresh' => $s['last_refresh'] ?? null,
        'dead_days' => wlMaintDeadDays($cfg),
        'dead_action' => wlMaintDeadAction($cfg),
        'dead_every_days' => wlMaintDeadEveryDays($cfg),
        'last_dead_at' => (int)($s['last_dead_at'] ?? 0),
        'last_dead' => $s['last_dead'] ?? null,
        // The number an operator needs BEFORE switching a delete rule on.
        'would_match' => wlMaintDeadCount($db, $cfg),
    ];
}
