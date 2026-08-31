<?php
/**
 * GET — everything the Backups page shows: whether this machine can make backups at all, what the
 * current or last run did, the archives on disk and what the schedule will do next.
 *
 * Auth is enforced by the router (admin/*); GET, so CSRF-exempt like the other admin read endpoints.
 * The page polls this while a run is in flight, so the router skips the heavy janitors for it and
 * the helper's status output is reused for a couple of seconds (includes/backup.php).
 *
 * Nothing here starts, deletes or restores anything — that is admin/backup_action, behind the
 * admin password.
 */

$now = time();
$dir = backupDir($cfg);
$cmdSet = backupCommand($cfg) !== '';

// Every profile the helper accepts, with what it means — so the run dialog can offer a choice
// without the page having to know the list. `items` is what the profile actually asks for, which is
// the only honest description of the difference between them.
$profiles = [];
foreach (BACKUP_PROFILES as $pid) {
    $profiles[] = [
        'id'    => $pid,
        'label' => backupProfileLabel($pid),
        'hint'  => backupProfileItems($pid, $cfg),
    ];
}

$out = [
    'ok'          => true,
    'server_time' => $now,
    'profiles'    => $profiles,
    'configured'  => [
        'enabled'      => backupEnabled($cfg),
        'dir'          => $dir,
        'profile'      => backupProfile($cfg),
        'profile_label' => backupProfileLabel(backupProfile($cfg)),
        'items'        => backupProfileItems(backupProfile($cfg), $cfg),
        'keep'         => backupKeep($cfg),
        'keep_days'    => backupKeepDays($cfg),
        'max_gb'       => backupMaxGb($cfg),
        'nice'         => backupNice($cfg),
        'verify_after' => backupVerifyAfter($cfg),
        'gpg'          => backupGpgRecipient($cfg),
        'db_name'      => backupDbName($cfg),
        'cmd'          => backupCommand($cfg),
        'cmd_set'      => $cmdSet,
        'tz'           => backupTimezone($cfg),
    ],
    'schedule' => [
        'raw'      => (string)($cfg['backup_schedule'] ?? ''),
        'valid'    => backupParseSchedule((string)($cfg['backup_schedule'] ?? '')) !== null,
        'describe' => backupScheduleDescribe((string)($cfg['backup_schedule'] ?? ''), backupTimezone($cfg)),
        'next'     => backupScheduleNext((string)($cfg['backup_schedule'] ?? ''), backupTimezone($cfg), $now),
    ],
    'exec_available' => trackerExecAvailable(),
    'check'    => null,
    'status'   => null,
    'archives' => [],
    'error'    => null,
];

if (!$cmdSet) {
    $out['error'] = 'No backup helper command is configured (Settings → Backups).';
} elseif (!trackerExecAvailable()) {
    $out['error'] = 'PHP exec() is disabled on this server — the panel cannot reach the backup helper.';
} else {
    $check = backupCheck($cfg);
    $out['check'] = $check;
    // A machine without the helper (or without a dump client) can still show the page and say why,
    // but there is nothing to list — do not spend two more forks proving it.
    if (!empty($check['root']) || !empty($check['mariadb_dump']) || !empty($check['script'])) {
        $out['status'] = backupStatus($cfg);
        $list = backupList($cfg);
        $out['archives'] = $list['archives'] ?? [];
        $out['total_bytes'] = (int)($list['total_bytes'] ?? 0);
        $out['free_bytes']  = (int)($list['free_bytes'] ?? 0);
        if (!empty($list['error'])) $out['error'] = $list['error'];
    } elseif (!empty($check['error'])) {
        $out['error'] = (string)$check['error'];
    }
}

$state = backupStateRead();
$out['last_run'] = (int)$state['last_run_at'] > 0
    ? ['at' => (int)$state['last_run_at'], 'id' => (string)$state['last_run_id'], 'source' => (string)$state['last_run_source']]
    : null;
$out['last_schedule_at'] = (int)$state['last_schedule_at'];
$out['last_error'] = $state['last_error'] ?? null;
$out['last_error_at'] = (int)$state['last_error_at'];

jsonResponse($out);
