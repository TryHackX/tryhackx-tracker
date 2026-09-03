<?php
/**
 * Address lists for the inbound UDP limit — allow, block, and block-under-pressure.
 *
 * `net_limit_trusted` (1.26.0) is a handful of addresses typed into a box. This is the same idea at
 * the scale an operator actually needs: whole networks, whole countries, kept in files or fetched
 * from a URL and refreshed on a timer.
 *
 * THREE KINDS, AND THE ORDER THEY ARE APPLIED IN
 * ----------------------------------------------
 *   allow      never dropped, whatever else says. First in the chain, so it wins.
 *   block-hard dropped always. A permanent no.
 *   block-soft dropped only under pressure: given its own, much smaller budget, so these sources are
 *              the first to be refused when the machine is busy and are untouched when it is not.
 *
 * nftables cannot express "drop this if some OTHER rule is currently dropping" — there is no shared
 * state between rules. A tighter per-set budget is how that intent is actually written: at rest the
 * soft set is nowhere near its own limit, and as arrivals climb it is the first thing to hit one.
 *
 * PRECEDENCE, STATED ONCE
 * -----------------------
 * Manual entries beat lists. `net_limit_trusted` and every enabled allow list are matched before any
 * block set, so an address you typed yourself can never be shut out by a country file somebody
 * downloaded. Within blocks, hard beats soft.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 * ----------------------------------
 * It does not touch the tracker's accesslist (that is about torrents, not addresses), it does not
 * write anywhere except the panel's own nftables table, and a list that fails to fetch changes
 * nothing — the last good copy stays in force and the failure is shown, because a country file that
 * 404s must not silently open or close a door.
 */

const IPLIST_KINDS       = ['allow', 'block'];
const IPLIST_MODES       = ['hard', 'soft'];          // block lists only
const IPLIST_SOURCES     = ['manual', 'url'];
const IPLIST_TTL_MIN     = 15;                        // minutes
const IPLIST_TTL_MAX     = 43200;                     // 30 days
const IPLIST_TTL_DEFAULT = 720;                       // 12 h
/** Total entries across every enabled list. A ruleset is loaded as one transaction; this bounds it. */
const IPLIST_MAX_TOTAL   = 250000;
/** One list on its own. A country zone file is ~2-10k lines; this leaves room without being silly. */
const IPLIST_MAX_ENTRIES = 100000;
const IPLIST_FETCH_MAX_BYTES = 8 * 1024 * 1024;

/** One address or CIDR, v4 or v6 — the same test the firewall helper applies again. */
function ipListValidCidr(string $item): bool {
    $addr = $item; $bits = null;
    if (str_contains($item, '/')) {
        [$addr, $b] = explode('/', $item, 2);
        if ($b === '' || !ctype_digit($b)) return false;
        $bits = (int)$b;
    }
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $bits === null || ($bits >= 0 && $bits <= 32);
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) return $bits === null || ($bits >= 0 && $bits <= 128);
    return false;
}

function ipListFamily(string $cidr): int {
    return str_contains(explode('/', $cidr)[0], ':') ? 6 : 4;
}

/**
 * Text -> unique, valid entries. Accepts what the sources in the wild actually produce: one entry
 * per line, `#` and `;` comments, blank lines, trailing whitespace, and CRLF.
 *
 * @return array{entries: list<string>, skipped: int}
 */
function ipListParse(string $text, int $cap = IPLIST_MAX_ENTRIES): array {
    $out = [];
    $seen = [];
    $skipped = 0;
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if ($line[0] === '#' || $line[0] === ';') continue;
        // "1.2.3.0/24 # some note" and "1.2.3.0/24,CN" both appear in published lists
        $line = trim(preg_split('/[\s,;#]/', $line, 2)[0]);
        if ($line === '') continue;
        if (!ipListValidCidr($line)) { $skipped++; continue; }
        if (isset($seen[$line])) continue;
        $seen[$line] = true;
        $out[] = $line;
        if (count($out) >= $cap) break;
    }
    return ['entries' => $out, 'skipped' => $skipped];
}

// ─────────────────────────────────────────────────────────────────────────────
// Reading
// ─────────────────────────────────────────────────────────────────────────────

/** Every list, newest first, with its entry count. Never throws. */
function ipListAll(PDO $db): array {
    try {
        $rows = $db->query(
            "SELECT l.*, (SELECT COUNT(*) FROM ip_list_entries e WHERE e.list_id = l.id) AS entries
               FROM ip_lists l ORDER BY l.kind, l.name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        $r['id']       = (int)$r['id'];
        $r['enabled']  = (int)$r['enabled'] === 1;
        $r['entries']  = (int)$r['entries'];
        $r['ttl_minutes'] = (int)$r['ttl_minutes'];
    }
    return $rows;
}

function ipListFind(PDO $db, int $id): ?array {
    try {
        $st = $db->prepare("SELECT * FROM ip_lists WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * The entries the firewall should be carrying, grouped the way the chain uses them.
 *
 * @return array{allow4:list<string>,allow6:list<string>,hard4:list<string>,hard6:list<string>,soft4:list<string>,soft6:list<string>,total:int,truncated:bool}
 */
function ipListEffective(PDO $db, array $cfg): array {
    $out = ['allow4' => [], 'allow6' => [], 'hard4' => [], 'hard6' => [],
            'soft4' => [], 'soft6' => [], 'total' => 0, 'truncated' => false];

    // Manual entries first and always: an address typed into the box outranks anything a downloaded
    // file has to say, and it is included even when every list is disabled.
    if (function_exists('netlimitTrusted')) {
        foreach (netlimitTrusted($cfg) as $c) {
            $out[ipListFamily($c) === 6 ? 'allow6' : 'allow4'][] = $c;
        }
    }
    try {
        $rows = $db->query(
            "SELECT l.kind, l.mode, e.cidr, e.family
               FROM ip_lists l JOIN ip_list_entries e ON e.list_id = l.id
              WHERE l.enabled = 1
              ORDER BY l.kind ASC, l.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $r) {
        if ($out['total'] >= IPLIST_MAX_TOTAL) { $out['truncated'] = true; break; }
        $six = (int)$r['family'] === 6;
        if ($r['kind'] === 'allow')      $key = $six ? 'allow6' : 'allow4';
        elseif ($r['mode'] === 'hard')   $key = $six ? 'hard6'  : 'hard4';
        else                             $key = $six ? 'soft6'  : 'soft4';
        $out[$key][] = $r['cidr'];
        $out['total']++;
    }
    foreach (['allow4', 'allow6', 'hard4', 'hard6', 'soft4', 'soft6'] as $k) {
        $out[$k] = array_values(array_unique($out[$k]));
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Writing
// ─────────────────────────────────────────────────────────────────────────────

/** Create a list. Returns the id, or ['error' => …]. */
function ipListCreate(PDO $db, array $in): array {
    $name = trim((string)($in['name'] ?? ''));
    $kind = (string)($in['kind'] ?? 'block');
    $mode = (string)($in['mode'] ?? 'hard');
    $src  = (string)($in['source'] ?? 'manual');
    $url  = trim((string)($in['url'] ?? ''));
    $ttl  = (int)($in['ttl_minutes'] ?? IPLIST_TTL_DEFAULT);

    if ($name === '' || mb_strlen($name) > 64)      return ['error' => 'A name of 1–64 characters is required.'];
    if (!in_array($kind, IPLIST_KINDS, true))       return ['error' => 'Unknown list kind.'];
    if (!in_array($mode, IPLIST_MODES, true))       return ['error' => 'Unknown block mode.'];
    if (!in_array($src, IPLIST_SOURCES, true))      return ['error' => 'Unknown source.'];
    if ($src === 'url') {
        if (!preg_match('#^https?://#i', $url) || mb_strlen($url) > 500) {
            return ['error' => 'A URL list needs an http(s) address.'];
        }
    } else {
        $url = '';
    }
    $ttl = max(IPLIST_TTL_MIN, min(IPLIST_TTL_MAX, $ttl ?: IPLIST_TTL_DEFAULT));
    // An allow list has no mode: "hard" and "soft" are ways of refusing, and this one accepts.
    if ($kind === 'allow') $mode = 'hard';

    try {
        $st = $db->prepare("INSERT INTO ip_lists (name, kind, mode, source, url, ttl_minutes, enabled)
                            VALUES (?, ?, ?, ?, ?, ?, 1)");
        $st->execute([$name, $kind, $mode, $src, $url, $ttl]);
        return ['id' => (int)$db->lastInsertId()];
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) return ['error' => 'A list with that name already exists.'];
        return ['error' => 'Could not create the list.'];
    }
}

/** Replace a list's entries in one transaction. Returns ['added'=>int,'skipped'=>int] or ['error'=>…]. */
function ipListSetEntries(PDO $db, int $id, string $text): array {
    $p = ipListParse($text);
    if (!$p['entries']) return ['error' => 'Nothing in there looked like an address or a CIDR.'];
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM ip_list_entries WHERE list_id = ?")->execute([$id]);
        $ins = $db->prepare("INSERT IGNORE INTO ip_list_entries (list_id, cidr, family) VALUES (?, ?, ?)");
        foreach (array_chunk($p['entries'], 1000) as $chunk) {
            foreach ($chunk as $c) $ins->execute([$id, $c, ipListFamily($c)]);
        }
        $db->prepare("UPDATE ip_lists SET last_fetch_at = NOW(), last_error = NULL, updated_at = NOW() WHERE id = ?")
           ->execute([$id]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['error' => 'Could not store the entries.'];
    }
    return ['added' => count($p['entries']), 'skipped' => $p['skipped']];
}

function ipListToggle(PDO $db, int $id, bool $on): bool {
    try {
        $db->prepare("UPDATE ip_lists SET enabled = ?, updated_at = NOW() WHERE id = ?")->execute([$on ? 1 : 0, $id]);
        return true;
    } catch (\Throwable $e) { return false; }
}

function ipListDelete(PDO $db, int $id): bool {
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM ip_list_entries WHERE list_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM ip_lists WHERE id = ?")->execute([$id]);
        $db->commit();
        return true;
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Refreshing a URL list
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch one URL list if its cache has expired (or $force).
 *
 * A failure is recorded and CHANGES NOTHING. The previous entries stay in force, because a country
 * file that 404s must not silently open a door that was closed — or close one that was open.
 */
function ipListRefresh(PDO $db, int $id, bool $force = false): array {
    $l = ipListFind($db, $id);
    if (!$l) return ['error' => 'No such list.'];
    if ($l['source'] !== 'url') return ['error' => 'That list is not fetched from a URL.'];

    $ttl = max(IPLIST_TTL_MIN, (int)$l['ttl_minutes']);
    $age = $l['last_fetch_at'] ? (time() - strtotime((string)$l['last_fetch_at'])) : PHP_INT_MAX;
    if (!$force && $age < $ttl * 60) return ['skipped' => true, 'age_s' => $age];

    if (!function_exists('curl_init')) return ['error' => 'curl is required to fetch a list.'];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $l['url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'tryhackx-tracker/1.28 ip-list',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($c, $dt, $dn) { return $dn > IPLIST_FETCH_MAX_BYTES ? 1 : 0; },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $fail = null;
    if ($body === false)      $fail = 'fetch failed: ' . $err;
    elseif ($code !== 200)    $fail = 'HTTP ' . $code;
    elseif (trim((string)$body) === '') $fail = 'the reply was empty';

    if ($fail === null) {
        $r = ipListSetEntries($db, $id, (string)$body);
        if (isset($r['error'])) $fail = $r['error'];
        else return ['added' => $r['added'], 'skipped_lines' => $r['skipped']];
    }
    try {
        $db->prepare("UPDATE ip_lists SET last_error = ?, last_try_at = NOW() WHERE id = ?")
           ->execute([mb_substr($fail, 0, 190), $id]);
    } catch (\Throwable $e) {}
    return ['error' => $fail];
}

/** Janitor tick: refresh every URL list whose cache has expired. Returns what it did. */
function ipListTick(PDO $db): array {
    $out = ['checked' => 0, 'refreshed' => 0, 'failed' => 0];
    foreach (ipListAll($db) as $l) {
        if ($l['source'] !== 'url' || !$l['enabled']) continue;
        $out['checked']++;
        $r = ipListRefresh($db, (int)$l['id']);
        if (isset($r['error'])) $out['failed']++;
        elseif (empty($r['skipped'])) $out['refreshed']++;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Handing the lists to the firewall
// ─────────────────────────────────────────────────────────────────────────────

/** Where the panel leaves the file the root helper reads. Inside config/, which php-fpm can write. */
function ipListSetsFile(): string { return __DIR__ . '/../config/net_sets.txt'; }

/**
 * Write every enabled entry as "kind address" lines for `tracker-netlimit.sh --sets=`.
 *
 * Written even when the feature is switched off — as an EMPTY file. That is what tells the helper to
 * clear the sets: leaving the last file behind would mean the master switch turned the page's
 * controls off while the firewall quietly kept enforcing a country block.
 *
 * @return array{path:string,lines:int,written:bool}
 */
function ipListWriteSetsFile(PDO $db, array $cfg): array {
    $path = ipListSetsFile();
    $on   = (($cfg['net_lists_enabled'] ?? '0') === '1');
    $body = '';
    $n = 0;
    if ($on) {
        $eff = ipListEffective($db, $cfg);
        // The manual entries already travel as --trusted=; repeating them here would be harmless but
        // would inflate the fingerprint for no reason, so only list-sourced allows go in.
        $manual = function_exists('netlimitTrusted') ? netlimitTrusted($cfg) : [];
        $skip = array_flip($manual);
        $buf = [];
        foreach ([['allow4', 'allow'], ['allow6', 'allow'], ['hard4', 'block'],
                  ['hard6', 'block'], ['soft4', 'soft'], ['soft6', 'soft']] as [$k, $kind]) {
            foreach ($eff[$k] as $c) {
                if ($kind === 'allow' && isset($skip[$c])) continue;
                $buf[] = $kind . ' ' . $c;
                $n++;
            }
        }
        $body = $buf ? implode("\n", $buf) . "\n" : '';
    }
    // Written through a temp file and renamed: the helper may be reading it from the janitor at the
    // same moment, and half a list is worse than none.
    $tmp = $path . '.tmp';
    $ok = @file_put_contents($tmp, $body) !== false && @rename($tmp, $path);
    if (!$ok) @unlink($tmp);
    return ['path' => $path, 'lines' => $n, 'written' => (bool)$ok];
}
