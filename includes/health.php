<?php
/**
 * ?action=health — one JSON answer for an uptime monitor.
 *
 * ── why an open port is not a health check ─────────────────────────────────────────────────────
 * A monitor pointed at the home page proves that Apache answers. It does not notice that the
 * database is a schema behind the code, that the accesslist has not been written since a failed
 * reload three hours ago, that the metadata worker died with a queue behind it, or that the panel
 * says WHITELIST while the tracker is actually running open. Every one of those is a tracker that
 * looks perfectly healthy from outside and is not doing its job.
 *
 * So this is the same set of facts the panel's status card reads — whitelistStatus() — plus the
 * schema version and the queue depths, in the shape a monitor can act on: a `status` of
 * ok / warn / fail, an HTTP 503 when it is `fail`, and a `problems` list a human can read in the
 * alert without opening anything.
 *
 * ── the token ──────────────────────────────────────────────────────────────────────────────────
 * `health_token` in Settings. Empty means the endpoint does not exist, and a WRONG token gets the
 * same answer as no token at all: the ordinary page the site would have shown. Nothing about the
 * reply distinguishes "no such feature" from "wrong secret", because a probe that can tell the
 * difference has been told there is a secret to find.
 *
 * Send it as `X-Health-Token` where the monitor allows a header — a query string is written to
 * every access log on the way — and `?token=` where it does not.
 */

const HEALTH_TOKEN_MIN = 16;

/** The configured token, or '' when the endpoint is switched off. */
function healthToken(array $cfg): string
{
    $t = trim((string)($cfg['health_token'] ?? ''));
    return strlen($t) >= HEALTH_TOKEN_MIN ? $t : '';
}

/**
 * Does this request carry the token? Constant-time, and both places are accepted.
 *
 * A too-short token is treated as no token at all rather than as a weak one: HEALTH_TOKEN_MIN is
 * what makes the address unguessable, and an operator who pastes "test" in there has not switched
 * the feature on — they have opened it to everybody.
 */
function healthAuthorised(array $cfg, ?string $header, ?string $query): bool
{
    $want = healthToken($cfg);
    if ($want === '') return false;
    foreach ([(string)$header, (string)$query] as $given) {
        if ($given !== '' && hash_equals($want, $given)) return true;
    }
    return false;
}

/**
 * Everything the monitor is told.
 *
 * Levels come from the panel's own warnings, so the endpoint and the dashboard cannot disagree
 * about what "healthy" means: 'danger' there is `fail` here, 'warn' is `warn`. The two facts the
 * card does not carry — the schema version and what is waiting in the queues — are added here.
 *
 * Returns ['status' => 'ok'|'warn'|'fail', 'http' => 200|503, …]; the caller prints it.
 */
function healthReport(PDO $db, array $cfg): array
{
    $problems = [];
    $level = 'ok';
    $raise = static function (string $to) use (&$level): void {
        if ($to === 'fail' || ($to === 'warn' && $level === 'ok')) $level = $to;
    };

    // 1. Is the code running against the database it expects? A schema behind the code is a site
    //    where some feature is silently answering as though it were switched off.
    $have = (int)($cfg['schema_version'] ?? 0);
    $want = (int)TRACKER_SCHEMA_VERSION;
    if ($have < $want) { $raise('fail'); $problems[] = "schema $have, expected $want"; }

    // 2. The accesslist and the tracker service, as the panel reads them.
    $st = null;
    try { $st = whitelistStatus($db, $cfg); } catch (\Throwable $e) { $raise('fail'); $problems[] = 'status unreadable: ' . $e->getMessage(); }
    foreach (($st['warnings'] ?? []) as $w) {
        $raise(($w['level'] ?? 'warn') === 'danger' ? 'fail' : 'warn');
        // The panel's warnings carry markup for the dashboard; a monitor's alert wants a sentence.
        $problems[] = trim(preg_replace('/\s+/', ' ', strip_tags((string)($w['text'] ?? ''))));
    }

    // 3. Panel mode versus what is actually running. The CACHED answer — the janitor refreshes it
    //    every minute, and forking sudo from a web request is how a monitor becomes a load source.
    $agree = ['known' => false, 'match' => null, 'panel' => trackerMode($cfg), 'actual' => null];
    if (function_exists('scheduleModeAgreement')) {
        $agree = scheduleModeAgreement($cfg, false);
        if ($agree['known'] && $agree['match'] === false) {
            $raise('fail');
            $problems[] = 'mode mismatch: the panel says ' . $agree['panel'] . ', the tracker is running ' . $agree['actual'];
        }
    }

    $file = $st['file'] ?? [];
    $state = $st['state'] ?? [];
    $queues = function_exists('digestCounts') ? digestCounts($db) : [];
    $queues['meta_pending'] = (int)($st['counts']['pending_meta'] ?? 0);

    return [
        'status' => $level,
        'http'   => $level === 'fail' ? 503 : 200,
        'body'   => [
            'ok'      => $level !== 'fail',
            'status'  => $level,
            'version' => TRACKER_VERSION,
            'schema'  => ['version' => $have, 'expected' => $want, 'ok' => $have >= $want],
            'mode'    => ['panel' => $agree['panel'], 'actual' => $agree['actual'], 'match' => $agree['match']],
            'accesslist' => [
                'entries'        => (int)($st['counts']['active'] ?? 0),
                'file_bytes'     => (int)($file['size'] ?? 0),
                'written_age'    => isset($file['mtime']) && $file['mtime'] ? time() - (int)$file['mtime'] : null,
                'regen_needed'   => (bool)($state['regen_needed'] ?? false),
                'pending_reload' => (bool)($state['pending_reload'] ?? false),
                'last_reload_ok' => $state['last_reload_ok'] ?? null,
                'fail_count'     => (int)($state['fail_count'] ?? 0),
            ],
            // null = no heartbeat file at all, which is only a problem when there is a queue behind
            // it — whitelistStatus() is the one that decides that, and it has already spoken above.
            'worker'   => ['heartbeat_age' => $st['worker_heartbeat_age'] ?? null],
            'queues'   => $queues,
            'problems' => array_values(array_filter($problems)),
            'server_time' => time(),
        ],
    ];
}
