<?php
/**
 * GET — the address lists and what the firewall is currently carrying.
 *
 * Read-only and cheap: entry counts come from the database, never from asking nftables to serialise
 * a quarter of a million set elements. That mistake cost 5.5 seconds of a core per poll in 1.25.1
 * and is not being repeated on a card that refreshes every few seconds.
 */

$lists = ipListAll($db);
$counts = ['allow' => 0, 'hard' => 0, 'soft' => 0];
$cover = ['allow' => 0, 'hard' => 0, 'soft' => 0];
foreach ($lists as $l) {
    if (!$l['enabled']) continue;
    $k = $l['kind'] === 'allow' ? 'allow' : $l['mode'];
    $counts[$k] += $l['entries'];
    $cover[$k]  += $l['addr4'];
}

// An address that is BOTH manually trusted and covered by something that drops is not an error — the
// chain matches trusted first, so the manual entry wins, exactly as documented. But it is worth
// saying out loud: somebody who imported a country file and then wonders why one host in it still
// gets through deserves to be told why, instead of concluding the block does not work.
//
// CONTAINMENT, not string equality. 5.188.1.7 inside a blocked 5.188.0.0/16 is the case that
// actually happens; two identical strings almost never are.
$eff = ipListEffective($db, $cfg);
$dropping = array_merge($eff['hard4'], $eff['hard6'], $eff['soft4'], $eff['soft6'],
                        netlimitBlocked($cfg));
$conflicts = ipListOverlapsCached($db, netlimitTrusted($cfg), $dropping, 'trusted');

// And the reverse, which is the whole point of the manual block box: a hand-typed block that sits
// inside an allow list is doing something — it is the exception the list cannot express — so the
// page confirms it is taking effect rather than leaving it to look like a contradiction.
$exceptions = ipListOverlapsCached($db, netlimitBlocked($cfg),
                                   array_merge($eff['allow4'], $eff['allow6']), 'blocked');

$file = ipListSetsFile();
jsonResponse([
    'success'  => true,
    'enabled'  => (($cfg['net_lists_enabled'] ?? '0') === '1'),
    'lists'    => array_map(static function (array $l): array {
        return [
            'id'      => $l['id'],
            'name'    => $l['name'],
            'kind'    => $l['kind'],
            'mode'    => $l['mode'],
            'source'  => $l['source'],
            'url'     => $l['url'],
            'ttl_minutes'   => $l['ttl_minutes'],
            'enabled'       => $l['enabled'],
            'entries'       => $l['entries'],
            'addr4'         => $l['addr4'],
            'nets6'         => $l['nets6'],
            'last_fetch_at' => $l['last_fetch_at'],
            'last_error'    => $l['last_error'],
        ];
    }, $lists),
    'counts'   => $counts,
    'cover'    => $cover,
    'max'      => IPLIST_MAX_TOTAL,
    'manual'   => netlimitTrusted($cfg),
    'conflicts' => $conflicts,
    'exceptions' => $exceptions,
    'blocked'   => netlimitBlocked($cfg),
    // Whether what is on disk still matches the database — the firewall follows the FILE, so this is
    // the honest answer to "is what I see here what is actually loaded?".
    'file_at'  => is_file($file) ? filemtime($file) : null,
    'ttl_default' => (int)($cfg['net_lists_ttl_default'] ?? IPLIST_TTL_DEFAULT),
]);
