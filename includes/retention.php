<?php
/**
 * The retention of what nothing else ever comes back to (1.73.0) — one janitor step, in every tracker mode.
 *
 * Each of these had a retention rule that did not run, or ran only by accident:
 *
 *   - `api_bans` — rows that expired API_BAN_KEEP_DAYS (90) days ago, with the address, the user agent and
 *     the request as it arrived. Pruned only by whitelistJanitor(): in whitelist mode, on one request in fifty.
 *   - `auth_handoffs` — the sign-in bridge's one-time tickets, an hour after they expired or were spent.
 *     authHandoffPrune() said "called from the janitor"; nothing called it but the next ticket minted.
 *   - `config/login_attempts.json` — the panel's failed sign-ins, by raw address, for the lockout window
 *     (login_lockout_minutes). Only the failing address's own list was ever trimmed: an address that failed
 *     once and never came back stayed for ever.
 *   - `config/rate_limits.json` — the forms' limits, by address (or /64) and time, for at most an hour
 *     (RATE_LIMIT_KEEP_SECONDS). An action trims only its own keys when it is next called, so an action nobody
 *     called again kept its addresses for ever.
 *
 * Every step is bounded (a LIMIT, or one small file) and the files are rewritten under their own locks — the
 * same ones the request paths take — and only when something went. `$now` is the clock (the tests pass one in);
 * null is the real one (the database's NOW() for the tables).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api_auth.php';
require_once __DIR__ . '/authbridge.php';

/** Returns what each step removed: ['api_bans', 'bridge_tickets', 'login_attempts', 'rate_limits', 'errors' => []]. */
function retentionTick(PDO $db, array $cfg, ?int $now = null): array {
    $out = ['api_bans' => 0, 'bridge_tickets' => 0, 'login_attempts' => 0, 'rate_limits' => 0, 'errors' => []];
    $steps = [
        'api_bans'       => fn() => apiBansPrune($db, $now),
        'bridge_tickets' => fn() => authHandoffPrune($db, $now),
        'login_attempts' => fn() => loginAttemptsPrune($cfg, $now),
        'rate_limits'    => fn() => rateLimitPrune($now),
    ];
    // One failing step never stops the others: a database without the bridge's table still prunes the files.
    foreach ($steps as $name => $step) {
        try {
            $out[$name] = (int)$step();
        } catch (\Throwable $e) {
            $out['errors'][$name] = $e->getMessage();
        }
    }
    return $out;
}
