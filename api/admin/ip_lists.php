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
foreach ($lists as $l) {
    if (!$l['enabled']) continue;
    $counts[$l['kind'] === 'allow' ? 'allow' : $l['mode']] += $l['entries'];
}

// An address that is BOTH manually trusted and on a block list is not an error — the chain matches
// the trusted set first, so the manual entry wins, exactly as documented. But it is worth saying out
// loud: somebody who imported a country file and then wonders why one host in it still gets through
// deserves to be told why, instead of concluding the block does not work.
$eff = ipListEffective($db, $cfg);
$blocked = array_flip(array_merge($eff['hard4'], $eff['hard6'], $eff['soft4'], $eff['soft6']));
$conflicts = [];
foreach (netlimitTrusted($cfg) as $m) {
    if (isset($blocked[$m])) $conflicts[] = $m;
}

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
            'last_fetch_at' => $l['last_fetch_at'],
            'last_error'    => $l['last_error'],
        ];
    }, $lists),
    'counts'   => $counts,
    'max'      => IPLIST_MAX_TOTAL,
    'manual'   => netlimitTrusted($cfg),
    'conflicts' => $conflicts,
    // Whether what is on disk still matches the database — the firewall follows the FILE, so this is
    // the honest answer to "is what I see here what is actually loaded?".
    'file_at'  => is_file($file) ? filemtime($file) : null,
    'ttl_default' => (int)($cfg['net_lists_ttl_default'] ?? IPLIST_TTL_DEFAULT),
]);
