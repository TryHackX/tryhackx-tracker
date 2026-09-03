<?php
/**
 * The record of who did what in the panel.
 *
 * The panel had none. Every risky action asked for a password and then left no trace of having
 * happened, which is workable while there is exactly one administrator and stops being workable the
 * moment there are two — the whole point of the Moderator group is that somebody other than the
 * owner now acts here, and "who approved this description" and "who changed that setting" become
 * real questions.
 *
 * Three rules shape this file:
 *
 * 1. WRITING A LOG LINE MUST NEVER BREAK THE ACTION IT DESCRIBES. Every call is wrapped; a failure
 *    is swallowed and reported nowhere except the error log. A panel that refuses to ban a hash
 *    because its audit table is full would be worse than one with no audit table.
 *
 * 2. SECRETS DO NOT GO IN. Settings changes are recorded as key + before/after, and anything whose
 *    name looks like a credential is recorded as the fact that it CHANGED, never as its value. The
 *    log is read by more people than the settings page is.
 *
 * 3. THE ACTOR IS RESOLVED FROM THE SESSION, NOT PASSED IN. A caller that has to say who it is can
 *    say the wrong thing; here there is one answer and every endpoint gets it.
 */

const AUDIT_KEEP_DAYS_DEFAULT = 180;
const AUDIT_MAX_DETAIL        = 4000;

/** Actions worth a line, grouped for the filter in the UI. Anything not listed still logs. */
function auditActionGroups(): array {
    return [
        'auth'     => ['login.ok', 'login.fail', 'login.2fa_fail', 'logout', 'password.change', 'twofa.change'],
        'settings' => ['settings.save'],
        'content'  => ['content.approve', 'content.reject', 'content.clear', 'content.edit_apply', 'content.edit_reject'],
        'hashes'   => ['whitelist.add', 'whitelist.delete', 'whitelist.ban', 'whitelist.unban',
                       'index.delete', 'index.promote', 'blacklist.add', 'blacklist.delete'],
        'reports'  => ['report.status', 'report.delete', 'report.restore', 'report.email', 'appeal.resolve'],
        'users'    => ['user.update', 'user.delete', 'user.grant', 'user.revoke', 'user.notify', 'group.save', 'group.delete'],
        'machine'  => ['tracker.mode', 'tracker.restart', 'tracker.reload', 'netlimit.apply', 'sysctl.apply',
                       'ot.apply', 'ot.cluster', 'livesync.apply', 'backup.run', 'backup.restore',
                       'backup.delete', 'backup.download', 'tuner.run'],
        'mail'     => ['bulk.queue', 'bulk.cancel'],
        'api'      => ['api_client.create', 'api_client.update', 'api_client.delete', 'api_ban.add', 'api_ban.lift'],
    ];
}

/** Which group an action belongs to ('other' when it is not in the list above). */
function auditGroupOf(string $action): string {
    foreach (auditActionGroups() as $group => $actions) {
        if (in_array($action, $actions, true)) return $group;
    }
    return 'other';
}

/**
 * Does this settings key hold something that must never appear in a log?
 *
 * Matched on the NAME rather than on a list of keys, so a credential added later is covered without
 * anybody remembering to come back here. False positives cost nothing — the line still records that
 * the key changed.
 */
function auditIsSecretKey(string $key): bool {
    return (bool)preg_match('/(secret|password|passwd|token|api_key|_key$|hmac|salt|private)/i', $key);
}

/** Who is doing this. Resolved from the session; never taken from the caller. */
function auditActor(?PDO $db = null): array {
    if (function_exists('isLoggedIn') && isLoggedIn()) {
        $viaUser = (int)($_SESSION['admin_via_user'] ?? 0);
        if ($viaUser > 0 && $db instanceof PDO && function_exists('userFindById')) {
            $u = userFindById($db, $viaUser);
            if ($u) {
                $isAdmin = function_exists('userIsAdminGroup') && userIsAdminGroup($db, $viaUser);
                return ['type' => $isAdmin ? 'admin' : 'moderator', 'id' => $viaUser, 'name' => (string)$u['username']];
            }
        }
        return ['type' => 'owner', 'id' => null, 'name' => 'panel'];
    }
    if (!empty($GLOBALS['apiClient']['id'])) {
        return ['type' => 'api', 'id' => (int)$GLOBALS['apiClient']['id'],
                'name' => (string)($GLOBALS['apiClient']['name'] ?? 'api client')];
    }
    if ($db instanceof PDO && function_exists('currentUser')) {
        $u = currentUser($db);
        if ($u) return ['type' => 'user', 'id' => (int)$u['id'], 'name' => (string)$u['username']];
    }
    return ['type' => 'system', 'id' => null, 'name' => 'system'];
}

/**
 * Record one action. Returns nothing and throws nothing — see rule 1 at the top of this file.
 *
 * $detail is anything the reader would need to understand the line later; it is stored as JSON and
 * truncated, and it is the caller's job not to put a secret in it.
 */
function auditLog(?PDO $db, string $action, array $opts = []): void {
    try {
        if (!($db instanceof PDO) || $action === '') return;
        $actor = $opts['actor'] ?? auditActor($db);
        $detail = $opts['detail'] ?? null;
        $json = null;
        if ($detail !== null) {
            $json = json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($json) && strlen($json) > AUDIT_MAX_DETAIL) {
                $json = json_encode(['truncated' => true, 'bytes' => strlen($json)]);
            }
        }
        $ip = function_exists('getClientIp') ? getClientIp($GLOBALS['cfg'] ?? []) : '';
        $st = $db->prepare(
            "INSERT INTO audit_log (actor_type, actor_id, actor_name, action, action_group,
                                    target_type, target_id, summary, detail, ip, ok)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([
            (string)$actor['type'],
            $actor['id'] !== null ? (int)$actor['id'] : null,
            mb_substr((string)$actor['name'], 0, 64),
            mb_substr($action, 0, 40),
            auditGroupOf($action),
            mb_substr((string)($opts['target_type'] ?? ''), 0, 24),
            mb_substr((string)($opts['target_id'] ?? ''), 0, 80),
            mb_substr((string)($opts['summary'] ?? ''), 0, 255),
            $json,
            mb_substr((string)$ip, 0, 45),
            array_key_exists('ok', $opts) ? (int)(bool)$opts['ok'] : 1,
        ]);
    } catch (\Throwable $e) {
        error_log('[audit] ' . $e->getMessage());
    }
}

/**
 * What changed in a settings save, as key => [before, after], with credentials reduced to the fact
 * that they changed.
 *
 * Only keys whose value actually MOVED are returned: a save posts the whole form, so recording
 * everything submitted would bury the one line that matters under two hundred that did not change.
 */
function auditSettingsDiff(array $before, array $after): array {
    $diff = [];
    foreach ($after as $k => $v) {
        $old = $before[$k] ?? null;
        if ((string)$old === (string)$v) continue;
        if (auditIsSecretKey($k)) {
            $diff[$k] = ['changed' => true, 'hidden' => 'this value is a credential and is not recorded'];
            continue;
        }
        $diff[$k] = ['from' => mb_substr((string)$old, 0, 120), 'to' => mb_substr((string)$v, 0, 120)];
    }
    return $diff;
}

/** One page of the log, newest first, with the filters the UI offers. */
function auditFetch(PDO $db, array $q = []): array {
    $per   = max(10, min(200, (int)($q['per_page'] ?? 50)));
    $page  = max(1, (int)($q['page'] ?? 1));
    $where = [];
    $args  = [];

    $group = (string)($q['group'] ?? '');
    if ($group !== '' && $group !== 'all') { $where[] = 'action_group = ?'; $args[] = $group; }

    $actor = trim((string)($q['actor'] ?? ''));
    if ($actor !== '') { $where[] = 'actor_name = ?'; $args[] = $actor; }

    $search = trim((string)($q['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
        $where[] = '(action LIKE ? OR summary LIKE ? OR target_id LIKE ? OR actor_name LIKE ? OR ip LIKE ?)';
        array_push($args, $like, $like, $like, $like, $like);
    }
    if (!empty($q['failed_only'])) $where[] = 'ok = 0';

    $sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $cs = $db->prepare("SELECT COUNT(*) FROM audit_log $sql");
    $cs->execute($args);
    $total = (int)$cs->fetchColumn();

    $off = ($page - 1) * $per;
    $st = $db->prepare("SELECT * FROM audit_log $sql ORDER BY id DESC LIMIT $per OFFSET $off");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['ok'] = (bool)$r['ok'];
        $r['detail'] = $r['detail'] !== null ? json_decode((string)$r['detail'], true) : null;
    }
    unset($r);

    return ['rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int)ceil($total / $per)), 'per_page' => $per];
}

/** Distinct actors, for the filter. Cheap: the table is small and the column is indexed. */
function auditActors(PDO $db): array {
    try {
        return $db->query("SELECT actor_name, COUNT(*) n FROM audit_log
                            GROUP BY actor_name ORDER BY n DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { return []; }
}

function auditKeepDays(array $cfg): int {
    return max(7, min(3650, (int)($cfg['audit_keep_days'] ?? AUDIT_KEEP_DAYS_DEFAULT) ?: AUDIT_KEEP_DAYS_DEFAULT));
}

/** Drop what is older than the retention window. Called by the janitor. Returns rows removed. */
function auditPrune(PDO $db, array $cfg): int {
    try {
        $st = $db->prepare("DELETE FROM audit_log WHERE at < (NOW() - INTERVAL ? DAY) LIMIT 5000");
        $st->execute([auditKeepDays($cfg)]);
        return $st->rowCount();
    } catch (\Throwable $e) {
        error_log('[audit] prune: ' . $e->getMessage());
        return 0;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// The automatic half
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Endpoint name → the action to record. Only WRITES appear here: a listing is not an action, and a
 * log that records every poll of every status card is a log nobody reads.
 *
 * An admin write that is NOT in this map is still recorded, under its endpoint name. That is the
 * important direction: a new endpoint is logged by default and only becomes invisible if somebody
 * deliberately says so, rather than the other way round.
 */
function auditEndpointAction(string $endpoint): ?string {
    static $map = [
        'admin/login'                 => 'login.ok',
        'admin/logout'                => 'logout',
        'admin/change_password'       => 'password.change',
        'admin/account_email'         => 'account.email',
        'admin/twofa'                 => 'twofa.change',
        'admin/save_settings'         => 'settings.save',
        'admin/wl_content'            => 'content.review',
        'admin/whitelist_add'         => 'whitelist.add',
        'admin/whitelist_delete'      => 'whitelist.delete',
        'admin/whitelist_ban'         => 'whitelist.ban',
        'admin/whitelist_unban'       => 'whitelist.unban',
        'admin/banned_add'            => 'blacklist.add',
        'admin/index_delete'          => 'index.delete',
        'admin/index_promote'         => 'index.promote',
        'admin/block_hash'            => 'report.block',
        'admin/unblock_hash'          => 'report.unblock',
        'admin/change_status'         => 'report.status',
        'admin/delete_report'         => 'report.delete',
        'admin/restore_report'        => 'report.restore',
        'admin/delete_all'            => 'report.delete_all',
        'admin/send_email'            => 'report.email',
        'admin/resolve_appeal'        => 'appeal.resolve',
        'admin/user_create'           => 'user.create',
        'admin/user_update'           => 'user.update',
        'admin/user_delete'           => 'user.delete',
        'admin/user_grant'            => 'user.grant',
        'admin/user_revoke'           => 'user.revoke',
        'admin/user_notify'           => 'user.notify',
        'admin/group_save'            => 'group.save',
        'admin/group_delete'          => 'group.delete',
        'admin/tracker_mode'          => 'tracker.mode',
        'admin/restart_tracker'       => 'tracker.restart',
        'admin/reload_tracker'        => 'tracker.reload',
        'admin/whitelist_regenerate'  => 'tracker.regenerate',
        'admin/net_apply'             => 'netlimit.apply',
        'admin/sysctl_apply'          => 'sysctl.apply',
        'admin/ot_apply'              => 'ot.apply',
        'admin/ot_cluster_apply'      => 'ot.cluster',
        'admin/livesync_apply'        => 'livesync.apply',
        'admin/backup_action'         => 'backup.action',
        'admin/bulk_send'             => 'bulk.queue',
        'admin/api_client_create'     => 'api_client.create',
        'admin/api_client_update'     => 'api_client.update',
        'admin/api_client_delete'     => 'api_client.delete',
        'admin/api_ban_add'           => 'api_ban.add',
        'admin/api_ban_lift'          => 'api_ban.lift',
        'admin/tuner'                 => 'tuner.run',
        'admin/fed_purge'             => 'fed.purge',
        'admin/fed_peer_save'         => 'fed.peer_save',
        'admin/fed_peer_delete'       => 'fed.peer_delete',
    ];
    return $map[$endpoint] ?? null;
}

/** Endpoints that write but are not worth a line: polls, tests, previews. */
function auditIsNoise(string $endpoint): bool {
    static $quiet = [
        'admin/settings_catalog', 'admin/net_samples', 'admin/net_test', 'admin/sysctl_test',
        'admin/ot_test', 'admin/ot_cluster_test', 'admin/livesync_test', 'admin/backup_test_path',
        'admin/check_whitelist_path', 'admin/test_tracker_permission', 'admin/fed_peer_test',
        'admin/check_blacklist', 'admin/whitelist_scrape', 'admin/whitelist_scrape_bulk',
        'admin/index_scrape', 'admin/index_scrape_bulk', 'admin/whitelist_fetch_meta',
        'admin/index_fetch_meta', 'admin/whitelist_meta_queue', 'admin/index_poll_now',
    ];
    return in_array($endpoint, $quiet, true) || str_ends_with($endpoint, '_status');
}

/**
 * Extra facts the endpoint knows and the router does not. Call it before jsonResponse(); the last
 * call wins for each key, so an endpoint can refine what it said as it learns more.
 */
function auditNote(array $note): void {
    $GLOBALS['__audit_note'] = array_merge($GLOBALS['__audit_note'] ?? [], $note);
}

/** Suppress the automatic line for this request (the endpoint has written its own). */
function auditSuppress(): void { $GLOBALS['__audit_off'] = true; }

/**
 * Called from jsonResponse(), once, at the moment the answer is known.
 *
 * Deliberately tolerant: no database, no session, an unknown endpoint or a thrown exception all end
 * the same way — nothing is logged and the response goes out regardless. The action is what matters;
 * the record of it is not allowed to interfere.
 */
function auditFinish(array $data, int $code): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!empty($GLOBALS['__audit_off'])) return;
        $endpoint = (string)($GLOBALS['__audit_endpoint'] ?? '');
        if ($endpoint === '' || !str_starts_with($endpoint, 'admin/')) return;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') return;
        if (auditIsNoise($endpoint)) return;
        $db = $GLOBALS['db'] ?? null;
        if (!($db instanceof PDO)) return;
        $cfg = $GLOBALS['cfg'] ?? [];
        if (($cfg['audit_enabled'] ?? '1') !== '1') return;

        $ok = $code < 400 && empty($data['error']);
        // A failed sign-in is the one thing worth recording under a different name: it is the line
        // somebody looks for after the fact, and calling it "login.ok that failed" hides it.
        $action = auditEndpointAction($endpoint) ?? str_replace('admin/', 'panel.', $endpoint);
        if ($action === 'login.ok' && !$ok) $action = 'login.fail';

        $note = (array)($GLOBALS['__audit_note'] ?? []);
        $summary = (string)($note['summary'] ?? '');
        if ($summary === '') {
            $summary = $ok
                ? (string)($data['message'] ?? '')
                : (string)($data['error'] ?? 'failed');
        }
        auditLog($db, $action, [
            'ok'          => $ok,
            'summary'     => $summary !== '' ? $summary : $endpoint,
            'target_type' => (string)($note['target_type'] ?? ''),
            'target_id'   => (string)($note['target_id'] ?? ''),
            'detail'      => $note['detail'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('[audit] finish: ' . $e->getMessage());
    }
}
