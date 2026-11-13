<?php

/**
 * Database-driven settings manager.
 * All settings stored in `settings` table, cached in memory per request.
 */

const SETTINGS_APCU_KEY = 'tracker_settings_v1';
const SETTINGS_APCU_TTL = 10; // seconds — bounds staleness; writes invalidate immediately anyway

function getSettings(PDO $db, bool $forceReload = false): array {
    static $cache = null;
    if ($cache !== null && !$forceReload) return $cache;

    $useApcu = function_exists('apcu_fetch');

    // Cross-request cache (shared by every php-fpm worker) so a page with 50 pollers doesn't run
    // 50 identical settings queries. Skipped on forceReload (i.e. right after a write) so admin
    // changes are never served stale.
    if ($useApcu && !$forceReload) {
        $ok = false;
        $cached = apcu_fetch(SETTINGS_APCU_KEY, $ok);
        if ($ok && is_array($cached)) {
            $cache = $cached;
            return $cache;
        }
    }

    $stmt = $db->query("SELECT `key`, `value` FROM settings");
    $cache = [];
    while ($row = $stmt->fetch()) {
        $cache[$row['key']] = $row['value'];
    }

    if ($useApcu) {
        // Re-storing here (including on forceReload) also propagates a fresh copy to other workers.
        apcu_store(SETTINGS_APCU_KEY, $cache, SETTINGS_APCU_TTL);
    }
    return $cache;
}

function getSetting(PDO $db, string $key, string $default = ''): string {
    $s = getSettings($db);
    return $s[$key] ?? $default;
}

function setSetting(PDO $db, string $key, string $value): void {
    $stmt = $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $stmt->execute([$key, $value]);
    getSettings($db, true); // refresh the per-request cache
}

function setSettings(PDO $db, array $data): void {
    $stmt = $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    foreach ($data as $k => $v) {
        $stmt->execute([$k, (string)$v]);
    }
    getSettings($db, true); // refresh the per-request cache
}

function s(PDO $db, string $key, string $default = ''): string {
    return getSetting($db, $key, $default);
}
