<?php
/**
 * What the machine's own processes cost: CPU share and resident memory of MariaDB, the tracker,
 * php-fpm and the metadata worker, read from /proc.
 *
 * CPU is a rate, so it needs two readings. Rather than sleeping inside a request, the previous
 * reading is kept in config/proc_usage.json and the share is computed over the interval since it
 * — on the Traffic page that is the poll interval, which is exactly the window an operator means
 * by "what is it using". The first call after a restart reports RSS and no CPU figure.
 *
 * /proc/<pid>/stat and /status are world-readable, so php-fpm's sandbox (ProtectSystem,
 * ProtectKernelTunables) does not get in the way; a process this user may not read is skipped.
 * Percentages are of ONE core, like top: 250 % means two and a half cores.
 */

const PROC_USAGE_FILE = __DIR__ . '/../config/proc_usage.json';

/** Which comm names belong to which row on the page. The worker is python3 running worker.py. */
function procstatGroups(): array {
    return [
        'mariadb'     => ['label' => 'MariaDB',     'comm' => ['mariadbd', 'mysqld']],
        'opentracker' => ['label' => 'opentracker', 'comm' => ['opentracker']],
        'php-fpm'     => ['label' => 'php-fpm',     'comm' => ['php-fpm', 'php-fpm8.5', 'php-fpm8.4', 'php-fpm8.3']],
        'worker'      => ['label' => 'metadata worker', 'comm' => ['python3', 'python'], 'cmdline' => 'worker.py'],
    ];
}

/** One pass over /proc: per group, summed CPU ticks and RSS bytes, and how many processes. */
function procstatSnapshot(): array {
    $groups = procstatGroups();
    $out = [];
    foreach ($groups as $k => $g) $out[$k] = ['ticks' => 0, 'rss' => 0, 'procs' => 0];
    $dirs = @glob('/proc/[0-9]*', GLOB_NOSORT) ?: [];
    foreach ($dirs as $d) {
        $comm = trim((string)@file_get_contents($d . '/comm'));
        if ($comm === '') continue;
        foreach ($groups as $k => $g) {
            if (!in_array($comm, $g['comm'], true)) continue;
            if (!empty($g['cmdline'])) {
                $cl = (string)@file_get_contents($d . '/cmdline');
                if ($cl === '' || strpos($cl, $g['cmdline']) === false) continue;
            }
            $stat = (string)@file_get_contents($d . '/stat');
            // the command name is in parentheses and may contain spaces; fields start after ') '
            $p = strrpos($stat, ') ');
            if ($p === false) continue;
            $f = preg_split('/\s+/', trim(substr($stat, $p + 2)));
            // after the ')' the fields are: state(0) ppid(1) ... utime(11) stime(12)
            if (count($f) < 13) continue;
            $out[$k]['ticks'] += (int)$f[11] + (int)$f[12];
            $status = (string)@file_get_contents($d . '/status');
            if (preg_match('/^VmRSS:\s+(\d+) kB/m', $status, $m)) $out[$k]['rss'] += (int)$m[1] * 1024;
            $out[$k]['procs']++;
            break;
        }
    }
    return $out;
}

/**
 * The rows for the page: label, procs, rss_bytes, cpu_pct (null on the first reading). Stores the
 * reading for next time. $now is injectable for tests.
 */
function procstatUsage(?int $now = null): array {
    if (!is_dir('/proc') || !is_readable('/proc/self/stat')) return [];
    $now = $now ?? time();
    $snap = procstatSnapshot();
    $prev = null;
    if (is_file(PROC_USAGE_FILE)) {
        $raw = @file_get_contents(PROC_USAGE_FILE);
        $prev = $raw ? json_decode($raw, true) : null;
        if (!is_array($prev) || !isset($prev['ts'], $prev['groups'])) $prev = null;
    }
    $hz = 100;   // CLK_TCK on every Linux this runs on; getconf is not worth a shell for the page
    $rows = [];
    foreach (procstatGroups() as $k => $g) {
        $cur = $snap[$k];
        $cpu = null;
        if ($prev && isset($prev['groups'][$k]['ticks']) && $now - (int)$prev['ts'] >= 2 && $now - (int)$prev['ts'] <= 3600) {
            $dt = $now - (int)$prev['ts'];
            $dticks = $cur['ticks'] - (int)$prev['groups'][$k]['ticks'];
            // a restarted process has fewer ticks than before: no rate this round rather than a negative one
            if ($dticks >= 0) $cpu = round($dticks / $hz / $dt * 100, 1);
        }
        $rows[$k] = ['label' => $g['label'], 'procs' => $cur['procs'], 'rss_bytes' => $cur['rss'], 'cpu_pct' => $cpu];
    }
    $tmp = PROC_USAGE_FILE . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, json_encode(['ts' => $now, 'groups' => $snap])) !== false) @rename($tmp, PROC_USAGE_FILE);
    return $rows;
}
