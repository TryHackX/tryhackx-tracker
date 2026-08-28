<?php
/**
 * Making a submission prove itself before it is served.
 *
 * ── the idea ────────────────────────────────────────────────────────────────
 *
 * Anybody can paste forty hex characters. Most of what a public whitelist accumulates is not abuse,
 * it is *noise*: hashes nobody is seeding, hashes whose torrent never named this tracker, hashes
 * typed from a page somebody was reading. Every one of them becomes a row the accesslist carries and
 * the tracker answers for, for ever.
 *
 * So, optionally, a submission has to demonstrate two things before it counts:
 *
 *   1. **the metadata resolves** — somebody, somewhere, is actually sharing the torrent, and
 *   2. **the scrape shows at least one peer** — and they are announcing to THIS tracker.
 *
 * Together those mean: this torrent exists, it is alive, and its trackers include us. A hash that
 * cannot manage all three is not a registration, it is a wish.
 *
 * ── how, without a second queue ─────────────────────────────────────────────
 *
 * The row is inserted immediately, marked `probing`, and given priority in the metadata queue that
 * already exists. The worker resolves it because it is an ordinary whitelist row; the janitor
 * scrapes it and decides. The only thing that changes is that the accesslist generator SKIPS rows
 * that have not passed, so the tracker does not serve a swarm the panel has not confirmed.
 *
 * That is deliberately not a separate queue: a second queue would need its own worker, its own
 * failure modes and its own way of getting stuck, to do a job the first one already does. What it
 * does need is PRIORITY, so that a probe is not stuck behind a hundred thousand catalogue rows —
 * which is the whole reason somebody would give up on this feature.
 */

function wlProbeEnabled(array $cfg): bool { return ($cfg['wl_probe_required'] ?? '0') === '1'; }

/** How long a submission gets to prove itself before it is given up on. */
function wlProbeTimeoutMinutes(array $cfg): int {
    return max(1, min(1440, (int)($cfg['wl_probe_timeout_minutes'] ?? 10) ?: 10));
}

/** What happens to a submission that never proved itself: 'keep' (inactive) or 'delete'. */
function wlProbeOnFail(array $cfg): string {
    $v = (string)($cfg['wl_probe_on_fail'] ?? 'delete');
    return in_array($v, ['keep', 'delete'], true) ? $v : 'delete';
}

/**
 * How many may be probing at once, per submitter.
 *
 * The metadata worker's own ceiling is the real limit on parallel fetches; this is about not letting
 * one person fill the priority lane. Kept at or below the worker's concurrency by default, because
 * a queue of five hundred "probing" rows is not faster, it just makes everyone wait together.
 */
function wlProbeMaxPerSubmit(array $cfg): int {
    $worker = max(1, min(64, (int)($cfg['meta_worker_concurrency'] ?? 8) ?: 8));
    return max(1, min($worker, (int)($cfg['wl_probe_max_batch'] ?? $worker) ?: $worker));
}

/** Mark freshly inserted rows as needing to prove themselves, and put them at the front of the queue. */
function wlProbeStart(PDO $db, array $cfg, array $hashes): int {
    $hashes = array_values(array_filter(array_map('strtolower', $hashes), 'isValidInfoHash'));
    if (!$hashes) return 0;
    $n = 0;
    foreach (array_chunk($hashes, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        // priority 10: ahead of ordinary whitelist work (5) and far ahead of the catalogue (-1).
        // A person is waiting on this one, watching a spinner; nothing else in the queue is.
        $st = $db->prepare(
            "UPDATE whitelist
                SET probe_status = 'probing', probe_started_at = NOW(), probe_error = NULL,
                    meta_status = CASE WHEN meta_status = 'done' THEN 'done' ELSE 'pending' END,
                    meta_priority = 10, meta_requested_at = NOW(), meta_error = NULL, meta_claim = NULL
              WHERE info_hash IN ($ph) AND banned = 0");
        $st->execute($chunk);
        $n += $st->rowCount();
    }
    return $n;
}

/**
 * Decide the fate of everything currently probing.
 *
 * Called from the janitor. Scrapes the rows whose metadata has arrived, and gives up on the ones
 * that ran out of time — with a reason that says WHICH half failed, because "we could not find
 * anybody sharing this" and "we could not read the torrent" send somebody to completely different
 * places.
 */
function wlProbeTick(PDO $db, array $cfg): array {
    $out = ['checked' => 0, 'passed' => 0, 'failed' => 0, 'deleted' => 0];
    if (PHP_SAPI !== 'cli') return $out;
    if (!wlProbeEnabled($cfg)) return $out;

    $rows = $db->query(
        "SELECT id, info_hash, meta_status, files_count, scrape_seeders, scrape_leechers,
                scrape_completed, scraped_at, probe_started_at
           FROM whitelist WHERE probe_status = 'probing' ORDER BY probe_started_at ASC LIMIT 200")
        ->fetchAll();
    if (!$rows) return $out;
    $out['checked'] = count($rows);

    $timeout = wlProbeTimeoutMinutes($cfg) * 60;
    $now = time();
    $pass = [];
    $fail = [];

    foreach ($rows as $r) {
        $age = $now - strtotime((string)$r['probe_started_at']);
        $haveMeta = ($r['meta_status'] === 'done');

        if ($haveMeta) {
            // The metadata is in. Now: is anybody actually there, and announcing to us?
            $sl = scrapeOpenTracker($db, $cfg, $r, true);
            $peers = is_array($sl) ? ((int)$sl['seeders'] + (int)$sl['leechers']) : -1;
            if ($peers > 0) { $pass[] = (int)$r['id']; continue; }
            if ($age >= $timeout) {
                $fail[(int)$r['id']] = $peers === 0
                    ? 'nobody is sharing this with our tracker in the torrent'
                    : 'the tracker did not answer a scrape for it';
            }
            continue;
        }

        if ($r['meta_status'] === 'failed') {
            $fail[(int)$r['id']] = 'the torrent metadata could not be fetched — nobody is sharing it';
            continue;
        }
        if ($age >= $timeout) {
            $fail[(int)$r['id']] = 'nobody answered in time — the torrent metadata never arrived';
        }
    }

    foreach (array_chunk($pass, 200) as $chunk) {
        if (!$chunk) continue;
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $db->prepare("UPDATE whitelist SET probe_status = 'passed', probe_error = NULL WHERE id IN ($ph)")
           ->execute($chunk);
        $out['passed'] += count($chunk);
    }

    foreach ($fail as $id => $why) {
        $db->prepare("UPDATE whitelist SET probe_status = 'failed', probe_error = ? WHERE id = ?")
           ->execute([mb_substr($why, 0, 255), $id]);
        $out['failed']++;
    }

    if ($out['failed'] > 0 && wlProbeOnFail($cfg) === 'delete') {
        $ids = array_keys($fail);
        foreach (array_chunk($ids, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $db->prepare("DELETE FROM whitelist_files WHERE whitelist_id IN ($ph)")->execute($chunk);
            $d = $db->prepare("DELETE FROM whitelist WHERE id IN ($ph) AND probe_status = 'failed'");
            $d->execute($chunk);
            $out['deleted'] += $d->rowCount();
        }
    }

    // A row that just passed belongs in the accesslist, and until it is regenerated the tracker is
    // not serving something it has now accepted.
    if ($out['passed'] > 0) {
        whitelistMarkDirty(true);
        whitelistRegenerate($db, $cfg);
        whitelistMaybeReload($cfg);
    }
    return $out;
}

/**
 * The state of a set of submissions, for the form that is watching them.
 *
 * Returns one entry per hash with a state the page can show without translating: `probing`,
 * `passed`, `failed` (with the reason), or `unknown` for a hash that is not there at all.
 */
function wlProbeStatus(PDO $db, array $hashes): array {
    $hashes = array_values(array_filter(array_map('strtolower', $hashes), 'isValidInfoHash'));
    if (!$hashes) return [];
    $out = [];
    foreach (array_chunk($hashes, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare(
            "SELECT info_hash, name, files_count, total_size, meta_status, probe_status, probe_error,
                    scrape_seeders, scrape_leechers
               FROM whitelist WHERE info_hash IN ($ph)");
        $st->execute($chunk);
        foreach ($st as $r) {
            $out[$r['info_hash']] = [
                'state'    => (string)($r['probe_status'] ?: 'none'),
                'name'     => $r['name'],
                'files'    => $r['files_count'] === null ? null : (int)$r['files_count'],
                'size'     => $r['total_size'] === null ? null : (int)$r['total_size'],
                'seeders'  => $r['scrape_seeders'] === null ? null : (int)$r['scrape_seeders'],
                'leechers' => $r['scrape_leechers'] === null ? null : (int)$r['scrape_leechers'],
                'meta'     => (string)$r['meta_status'],
                'error'    => $r['probe_error'],
            ];
        }
    }
    foreach ($hashes as $h) {
        if (!isset($out[$h])) $out[$h] = ['state' => 'unknown', 'error' => 'not registered'];
    }
    return $out;
}
