<?php
/**
 * Scheduled tracker mode.
 *
 * The tracker can run in WHITELIST mode during configured hours and in BLACKLIST (open) mode
 * otherwise, per weekday, in a configured timezone. The schedule only *decides*; the actual switch
 * (swap the OpenTracker binary + config symlinks, restart the service) is done by a root helper
 * (`tracker_mode_switch_cmd`, default `sudo -n /usr/local/sbin/tracker-mode.sh`) that gets the
 * argument `white` or `black`, prints the current mode and exits 0 on success. On success the web
 * app flips its own `tracker_mode` setting and keeps bans consistent between the two list files.
 *
 * Settings:
 *   tracker_schedule_enabled  '0'|'1'
 *   tracker_schedule          JSON: {"mon":"all"|"none"|{"from":"HH:MM","to":"HH:MM"}, ... "sun":...}
 *                             "all"  = whitelist the whole day, "none" = open (blacklist) the whole day,
 *                             window = whitelist from `from` on that weekday; when to <= from the window
 *                             ends on the NEXT day at `to`. Everything outside a window is OPEN mode.
 *   tracker_schedule_tz       IANA timezone (validated against timezone_identifiers_list())
 *   tracker_mode_switch_cmd   command prefix (letters, digits, space, _ . / -); '' = only flip the setting
 *
 * scheduleApply() is called from tools/janitor.php (systemd timer, every minute) — never from a web
 * request. Pure functions (parse / desired mode / next change / describe) have no DB or file I/O and are
 * unit-tested in tests/schedule_test.php.
 */

const SCHEDULE_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const SCHEDULE_DAY_LABELS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
const SCHEDULE_DEFAULT_TZ  = 'Europe/Warsaw';
const SCHEDULE_DEFAULT_CMD = 'sudo -n /usr/local/sbin/tracker-mode.sh';
const SCHEDULE_MIN_ATTEMPT_INTERVAL = 60;   // seconds between switch attempts (flap guard)
const SCHEDULE_WEEK_MINUTES = 7 * 1440;

/** Default values of the schedule settings (also seeded by includes/schema.php). */
function scheduleSettingDefaults(): array {
    return [
        'tracker_schedule_enabled' => '0',
        'tracker_schedule'         => json_encode(array_fill_keys(SCHEDULE_DAYS, 'none')),
        'tracker_schedule_tz'      => SCHEDULE_DEFAULT_TZ,
        'tracker_mode_switch_cmd'  => SCHEDULE_DEFAULT_CMD,
    ];
}

function scheduleEnabled(array $cfg): bool {
    return (($cfg['tracker_schedule_enabled'] ?? '0') === '1');
}

function scheduleValidTimezone(string $tz): bool {
    return $tz !== '' && in_array($tz, timezone_identifiers_list(), true);
}

/** Configured timezone, falling back to the default when unset/invalid. */
function scheduleTimezone(array $cfg): string {
    $tz = trim((string)($cfg['tracker_schedule_tz'] ?? ''));
    return scheduleValidTimezone($tz) ? $tz : SCHEDULE_DEFAULT_TZ;
}

/**
 * The switch command may only consist of [A-Za-z0-9 _./-] (no shell metacharacters); the mode
 * argument is appended by the app through escapeshellarg(). Empty = allowed (setting flip only).
 */
function scheduleValidSwitchCommand(string $cmd): bool {
    return $cmd === '' || preg_match('#^[A-Za-z0-9 _./-]{1,255}$#', $cmd) === 1;
}

/** Configured switch command ('' when unset/invalid — an invalid command is never executed). */
function scheduleSwitchCommand(array $cfg): string {
    $cmd = trim((string)($cfg['tracker_mode_switch_cmd'] ?? SCHEDULE_DEFAULT_CMD));
    return scheduleValidSwitchCommand($cmd) ? $cmd : '';
}

/** "HH:MM" (00:00–23:59) → minutes since midnight, or null. */
function scheduleParseTime(string $t): ?int {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($t), $m)) return null;
    $h = (int)$m[1]; $mi = (int)$m[2];
    if ($h > 23 || $mi > 59) return null;
    return $h * 60 + $mi;
}

/**
 * Decode + normalise the schedule JSON. Returns [day => 'all'|'none'|['from'=>'HH:MM','to'=>'HH:MM']]
 * for all 7 days (missing days = 'none'), or null when the JSON is not valid (unknown keys, bad
 * values, bad times). Independent of the enabled flag.
 */
function scheduleParseJson(?string $json): ?array {
    $raw = json_decode((string)$json, true);
    if (!is_array($raw)) return null;
    foreach (array_keys($raw) as $k) {
        if (!in_array($k, SCHEDULE_DAYS, true)) return null;
    }
    $out = [];
    foreach (SCHEDULE_DAYS as $d) {
        $v = $raw[$d] ?? 'none';
        if ($v === 'all' || $v === 'none') { $out[$d] = $v; continue; }
        if (is_array($v) && isset($v['from'], $v['to']) && is_string($v['from']) && is_string($v['to'])) {
            $f = scheduleParseTime($v['from']); $t = scheduleParseTime($v['to']);
            if ($f === null || $t === null) return null;
            $out[$d] = ['from' => sprintf('%02d:%02d', intdiv($f, 60), $f % 60), 'to' => sprintf('%02d:%02d', intdiv($t, 60), $t % 60)];
            continue;
        }
        return null;
    }
    return $out;
}

/**
 * Normalised schedule structure from the settings array, or null when disabled (unless
 * $requireEnabled is false) or invalid:
 *   ['tz' => string, 'days' => [...scheduleParseJson...], 'windows' => [[start, end], ...], 'cmd' => string]
 * `windows` are half-open [start, end) intervals in minutes since Monday 00:00 of the schedule
 * timezone; `end` may exceed a week (Sunday spilling into Monday) — callers wrap modulo 10080.
 */
function scheduleParse(array $cfg, bool $requireEnabled = true): ?array {
    if ($requireEnabled && !scheduleEnabled($cfg)) return null;
    $days = scheduleParseJson((string)($cfg['tracker_schedule'] ?? ''));
    if ($days === null) return null;
    $windows = [];
    foreach (SCHEDULE_DAYS as $i => $d) {
        $v = $days[$d];
        if ($v === 'none') continue;
        $base = $i * 1440;
        if ($v === 'all') { $windows[] = [$base, $base + 1440]; continue; }
        $f = scheduleParseTime($v['from']); $t = scheduleParseTime($v['to']);
        $end = $t > $f ? $base + $t : $base + 1440 + $t;   // to <= from → ends the next day
        $windows[] = [$base + $f, $end];
    }
    return ['tz' => scheduleTimezone($cfg), 'days' => $days, 'windows' => $windows, 'cmd' => scheduleSwitchCommand($cfg)];
}

/** Whitelist at a given minute-of-week (0..10079)? Includes windows of the previous week that spill over. */
function scheduleModeAtMinute(array $parsed, int $minute): string {
    $minute = (($minute % SCHEDULE_WEEK_MINUTES) + SCHEDULE_WEEK_MINUTES) % SCHEDULE_WEEK_MINUTES;
    foreach ($parsed['windows'] as [$s, $e]) {
        if ($minute >= $s && $minute < $e) return 'whitelist';
        $m2 = $minute + SCHEDULE_WEEK_MINUTES;                 // spill from the last window of the week
        if ($m2 >= $s && $m2 < $e) return 'whitelist';
    }
    return 'blacklist';
}

/** Local wall-clock time in the schedule timezone plus its minute-of-week. */
function scheduleLocalNow(array $parsed, ?DateTimeImmutable $now = null): array {
    $now = $now ?? new DateTimeImmutable('now');
    $local = $now->setTimezone(new DateTimeZone($parsed['tz']));
    $dow = (int)$local->format('N') - 1;   // 0 = Monday
    $minute = $dow * 1440 + (int)$local->format('G') * 60 + (int)$local->format('i');
    return [$local, $minute];
}

/** Desired mode for $now ('whitelist'|'blacklist'), null when the schedule is disabled/invalid. */
function scheduleDesiredMode(array $cfg, ?DateTimeImmutable $now = null): ?string {
    $p = scheduleParse($cfg);
    if ($p === null) return null;
    [, $minute] = scheduleLocalNow($p, $now);
    return scheduleModeAtMinute($p, $minute);
}

/**
 * Next moment (strictly after $now) at which the desired mode changes, or null when the schedule
 * is disabled/invalid or constant (whole week whitelist / whole week open).
 */
function scheduleNextChange(array $cfg, ?DateTimeImmutable $now = null): ?DateTimeImmutable {
    $p = scheduleParse($cfg);
    if ($p === null) return null;
    [$local, $minute] = scheduleLocalNow($p, $now);
    $current = scheduleModeAtMinute($p, $minute);
    $bounds = [];
    foreach ($p['windows'] as [$s, $e]) { $bounds[$s % SCHEDULE_WEEK_MINUTES] = true; $bounds[$e % SCHEDULE_WEEK_MINUTES] = true; }
    if (!$bounds) return null;
    $best = null;
    foreach (array_keys($bounds) as $b) {
        $delta = (($b - $minute) % SCHEDULE_WEEK_MINUTES + SCHEDULE_WEEK_MINUTES) % SCHEDULE_WEEK_MINUTES;
        if ($delta === 0) $delta = SCHEDULE_WEEK_MINUTES;     // a boundary at exactly "now" already applies
        if (scheduleModeAtMinute($p, $b) === $current) continue;
        if ($best === null || $delta < $best) $best = $delta;
    }
    if ($best === null) return null;
    $target = $minute + $best;
    $dayOffset = intdiv($target, 1440) - intdiv($minute, 1440);
    $tod = $target % 1440;
    // wall-clock arithmetic in the schedule zone (DST-safe: dates and times, not epoch seconds)
    return $local->setTime(0, 0)->modify(sprintf('%+d days', $dayOffset))->setTime(intdiv($tod, 60), $tod % 60);
}

/**
 * Human description, grouping consecutive days with the same rule, e.g.
 * "Mon–Fri 10:00–02:30 (next day), Sat–Sun all day (Europe/Warsaw)". Days that are open all day are
 * left out (the tracker is OPEN outside every window). Works whether or not the schedule is enabled.
 */
function scheduleDescribe(array $cfg): string {
    $p = scheduleParse($cfg, false);
    if ($p === null) return 'invalid schedule';
    $ruleOf = function ($v): string {
        if ($v === 'all') return 'all day';
        if ($v === 'none') return '';
        $f = scheduleParseTime($v['from']); $t = scheduleParseTime($v['to']);
        return $v['from'] . '–' . $v['to'] . ($t <= $f ? ' (next day)' : '');
    };
    $groups = [];  // [firstDay, lastDay, rule]
    foreach (SCHEDULE_DAYS as $d) {
        $rule = $ruleOf($p['days'][$d]);
        if ($rule === '') continue;
        $n = count($groups);
        $prevIdx = $n ? array_search($groups[$n - 1][1], SCHEDULE_DAYS, true) : -2;
        if ($n && $groups[$n - 1][2] === $rule && $prevIdx === array_search($d, SCHEDULE_DAYS, true) - 1) {
            $groups[$n - 1][1] = $d;
        } else {
            $groups[] = [$d, $d, $rule];
        }
    }
    if (!$groups) return 'no whitelist hours — open (blacklist) mode all week (' . $p['tz'] . ')';
    $parts = [];
    foreach ($groups as [$a, $b, $rule]) {
        $parts[] = ($a === $b ? SCHEDULE_DAY_LABELS[$a] : SCHEDULE_DAY_LABELS[$a] . '–' . SCHEDULE_DAY_LABELS[$b]) . ' ' . $rule;
    }
    return implode(', ', $parts) . ' (' . $p['tz'] . ')';
}

/** Format a moment as "HH:MM Day" (optionally with date) in the schedule timezone, for notices. */
function scheduleFormatLocal(array $cfg, ?DateTimeImmutable $t, bool $withDate = false): string {
    if ($t === null) return '';
    $l = $t->setTimezone(new DateTimeZone(scheduleTimezone($cfg)));
    return $l->format($withDate ? 'D Y-m-d H:i' : 'H:i D');
}

/**
 * Everything the admin status card / settings page needs. No side effects.
 */
function scheduleStatus(array $cfg, ?DateTimeImmutable $now = null): array {
    $now = $now ?? new DateTimeImmutable('now');
    $enabled = scheduleEnabled($cfg);
    $valid = scheduleParseJson((string)($cfg['tracker_schedule'] ?? '')) !== null;
    $desired = $enabled ? scheduleDesiredMode($cfg, $now) : null;
    $next = $enabled ? scheduleNextChange($cfg, $now) : null;
    $state = function_exists('whitelistStateRead') ? whitelistStateRead() : [];
    return [
        'enabled'        => $enabled,
        'valid'          => $valid,
        'tz'             => scheduleTimezone($cfg),
        'describe'       => scheduleDescribe($cfg),
        'current'        => function_exists('trackerMode') ? trackerMode($cfg) : ((($cfg['tracker_mode'] ?? 'blacklist') === 'whitelist') ? 'whitelist' : 'blacklist'),
        'desired'        => $desired,
        'next_change'    => $next ? $next->getTimestamp() : null,
        'next_change_local' => $next ? scheduleFormatLocal($cfg, $next, true) : null,
        'cmd'            => scheduleSwitchCommand($cfg),
        'cmd_set'        => scheduleSwitchCommand($cfg) !== '',
        'last_check_at'  => (int)($state['schedule_last_check_at'] ?? 0),
        'last_attempt_at' => (int)($state['schedule_last_attempt_at'] ?? 0),
        'last_switch_at' => (int)($state['schedule_last_switch_at'] ?? 0),
        'last_result'    => $state['schedule_last_result'] ?? null,
        'last_error'     => $state['schedule_last_error'] ?? null,
        'last_from'      => $state['schedule_last_from'] ?? null,
        'last_to'        => $state['schedule_last_to'] ?? null,
        'last_output'    => $state['schedule_last_output'] ?? '',
        'last_notes'     => $state['schedule_last_notes'] ?? '',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Switching
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Make sure every hash in banned_hashes is present in the blacklist file (append the missing ones,
 * lowercase, under LOCK_EX; other entries are never touched). Returns ['ok','added','error'].
 */
function scheduleSyncBansToBlacklist(PDO $db, array $cfg): array {
    $out = ['ok' => true, 'added' => 0, 'error' => null];
    $path = normalizeListPath((string)($cfg['blacklist_path'] ?? ''));
    if ($path === '') { $out['ok'] = false; $out['error'] = 'Blacklist path is not configured.'; return $out; }
    try {
        $banned = $db->query("SELECT info_hash FROM banned_hashes")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) {
        return ['ok' => false, 'added' => 0, 'error' => 'DB: ' . $e->getMessage()];
    }
    if (!$banned) return $out;
    $present = [];
    if (is_file($path)) {
        if (!is_readable($path)) return ['ok' => false, 'added' => 0, 'error' => "Blacklist file is not readable: $path"];
        foreach ((array)@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) $present[strtolower(trim($l))] = true;
        if (!is_writable($path)) return ['ok' => false, 'added' => 0, 'error' => "Blacklist file is not writable: $path"];
    } elseif (!is_writable(dirname($path))) {
        return ['ok' => false, 'added' => 0, 'error' => 'Blacklist directory is not writable: ' . dirname($path)];
    }
    $missing = [];
    foreach ($banned as $h) {
        $h = strtolower(trim((string)$h));
        if (isValidInfoHash($h) && !isset($present[$h])) { $missing[] = $h; $present[$h] = true; }
    }
    if (!$missing) return $out;
    $fh = @fopen($path, 'ab');
    if (!$fh) return ['ok' => false, 'added' => 0, 'error' => "Cannot open blacklist file for append: $path"];
    @flock($fh, LOCK_EX);
    $ok = @fwrite($fh, implode("\n", $missing) . "\n") !== false;
    @fflush($fh); @flock($fh, LOCK_UN); @fclose($fh);
    if (!$ok) return ['ok' => false, 'added' => 0, 'error' => "Write to blacklist file failed: $path"];
    $out['added'] = count($missing);
    if (function_exists('recordBlacklistChange')) recordBlacklistChange('add');
    return $out;
}

/** Persist the outcome of a switch attempt in the whitelist state file (never throws). */
function scheduleRecordResult(array $r): void {
    if (!function_exists('whitelistStateUpdate')) return;
    try {
        whitelistStateUpdate(function (&$s) use ($r) {
            $s['schedule_last_result'] = $r['ok'] ? 'ok' : 'failed';
            $s['schedule_last_error']  = $r['ok'] ? null : (string)($r['error'] ?? 'unknown error');
            $s['schedule_last_from']   = $r['from'] ?? null;
            $s['schedule_last_to']     = $r['to'] ?? null;
            $s['schedule_last_output'] = mb_substr((string)($r['output'] ?? ''), 0, 500);
            $s['schedule_last_notes']  = mb_substr(implode(' | ', (array)($r['notes'] ?? [])), 0, 500);
            if ($r['ok']) $s['schedule_last_switch_at'] = time();
        });
    } catch (\Throwable $e) {
        error_log('[schedule] state write failed: ' . $e->getMessage());
    }
}

/**
 * The switch routine. Disabled schedule or desired == current → no-op. Otherwise prepare the list
 * files (so the restarted tracker loads a consistent list), run the switch command with 'white' /
 * 'black', and on rc == 0 (or when the command is empty) flip `tracker_mode` (settings table + $cfg).
 * At most one attempt per SCHEDULE_MIN_ATTEMPT_INTERVAL seconds unless $force. Never throws.
 * Returns ['ok','changed','from','to','error','output','notes','skipped'].
 */
function scheduleApply(PDO $db, array &$cfg, bool $force = false): array {
    $from = trackerMode($cfg);
    $out = ['ok' => true, 'changed' => false, 'from' => $from, 'to' => null, 'error' => null, 'output' => '', 'notes' => [], 'skipped' => null];
    try {
        if (!scheduleEnabled($cfg)) { $out['skipped'] = 'disabled'; return $out; }
        $desired = scheduleDesiredMode($cfg);
        // heartbeat for the status card ("was the schedule evaluated recently?" → janitor timer alive)
        $state = function_exists('whitelistStateRead') ? whitelistStateRead() : [];
        if (function_exists('whitelistStateUpdate') && (time() - (int)($state['schedule_last_check_at'] ?? 0)) >= 30) {
            $state = whitelistStateUpdate(function (&$s) { $s['schedule_last_check_at'] = time(); });
        }
        if ($desired === null) { $out['ok'] = false; $out['error'] = 'Schedule JSON is invalid — fix it in Settings.'; $out['skipped'] = 'invalid'; return $out; }
        $out['to'] = $desired;
        if ($desired === $from) { $out['skipped'] = 'in-sync'; return $out; }

        // flap guard: one attempt per minute
        $lastAttempt = (int)($state['schedule_last_attempt_at'] ?? 0);
        if (!$force && (time() - $lastAttempt) < SCHEDULE_MIN_ATTEMPT_INTERVAL) { $out['skipped'] = 'throttled'; return $out; }
        if (function_exists('whitelistStateUpdate')) whitelistStateUpdate(function (&$s) { $s['schedule_last_attempt_at'] = time(); });

        $cmd = scheduleSwitchCommand($cfg);

        // 1) prepare the list the restarted tracker will load
        if ($desired === 'blacklist') {
            $sync = scheduleSyncBansToBlacklist($db, $cfg);
            if (!$sync['ok']) $out['notes'][] = 'blacklist sync: ' . $sync['error'];
            elseif ($sync['added'] > 0) $out['notes'][] = 'blacklist sync: +' . $sync['added'] . ' banned hashes appended';
        } else {
            $imp = whitelistImportBlacklist($db, $cfg);   // blacklist entries become bans (DB-only while still in blacklist mode)
            if ($imp['error']) $out['notes'][] = 'blacklist import: ' . $imp['error'];
            elseif ($imp['imported'] > 0) $out['notes'][] = 'blacklist import: ' . $imp['imported'] . ' new bans';
            $rg = whitelistRegenerate($db, $cfg);        // fresh served file BEFORE the service restarts
            if (!$rg['ok']) $out['notes'][] = 'whitelist regen: ' . ($rg['error'] ?? 'failed');
        }

        // 2) switch the service
        if ($cmd !== '') {
            if (!trackerExecAvailable()) {
                $out['ok'] = false; $out['error'] = 'exec() is disabled — cannot run the mode switch command.';
                error_log('[schedule] ' . $out['error']);
                scheduleRecordResult($out);
                return $out;
            }
            $arg = $desired === 'whitelist' ? 'white' : 'black';
            $full = $cmd . ' ' . escapeshellarg($arg) . ' 2>&1';
            $lines = []; $rc = null;
            @exec($full, $lines, $rc);
            $output = trim(implode("\n", $lines));
            $out['output'] = $output;
            $lastLine = strtolower(trim((string)end($lines)));
            if ($rc !== 0) {
                $out['ok'] = false;
                $out['error'] = "Switch command failed (exit $rc): " . ($output !== '' ? mb_substr($output, 0, 300) : 'no output');
                error_log('[schedule] ' . $out['error']);
                scheduleRecordResult($out);
                return $out;
            }
            if (($lastLine === 'white' || $lastLine === 'black') && $lastLine !== $arg) {
                $out['ok'] = false;
                $out['error'] = "Switch command exited 0 but reports mode '$lastLine' (wanted '$arg').";
                error_log('[schedule] ' . $out['error']);
                scheduleRecordResult($out);
                return $out;
            }
        }

        // 3) flip the web setting
        setSetting($db, 'tracker_mode', $desired);
        $cfg['tracker_mode'] = $desired;
        if (isset($GLOBALS['cfg']) && is_array($GLOBALS['cfg'])) $GLOBALS['cfg']['tracker_mode'] = $desired;
        $out['changed'] = true;

        // 4) post-switch bookkeeping
        if ($cmd !== '') {
            // the service was restarted by the helper: it holds the current files now
            if (function_exists('whitelistNoteReloaded')) whitelistNoteReloaded(true, 'mode switch → ' . $desired);
            if (function_exists('resetBlacklistChanges')) resetBlacklistChanges();
        } elseif ($desired === 'blacklist') {
            $rl = autoReloadTrackerBlacklist($cfg);
            if ($rl && empty($rl['ok'])) $out['notes'][] = 'blacklist reload failed: ' . ($rl['output'] ?? '');
        } else {
            whitelistMarkDirty(true);
            $rl = whitelistMaybeReload($cfg, true);
            if ($rl && empty($rl['ok'])) $out['notes'][] = 'whitelist reload failed: ' . ($rl['output'] ?? '');
        }
        scheduleRecordResult($out);
        return $out;
    } catch (\Throwable $e) {
        $out['ok'] = false;
        $out['error'] = 'Exception: ' . $e->getMessage();
        error_log('[schedule] ' . $out['error']);
        scheduleRecordResult($out);
        return $out;
    }
}
