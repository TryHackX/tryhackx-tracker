<?php
/**
 * Font Awesome packages from the shell (1.69.0) — the same pipeline as Settings → Site, for the
 * operator who has the files on the server already:
 *
 *   sudo -u www-data php tools/iconpack.php import <zip|dir>
 *   sudo -u www-data php tools/iconpack.php list
 *   sudo -u www-data php tools/iconpack.php activate <id> [--styles=a,b] [--style=NAME]
 *   sudo -u www-data php tools/iconpack.php styles <id> [<file|key>,…] [--style=NAME]
 *   sudo -u www-data php tools/iconpack.php verify <id>
 *   sudo -u www-data php tools/iconpack.php delete <id>
 *
 * AS THE WEB USER: a package is written into config/iconpacks/, which the web server owns and must be
 * able to read and later delete; installed as root it would be a directory the panel cannot remove.
 * `activate` and `styles` write the four Font Awesome settings the panel writes, through the same
 * checks (iconSettingsNormalise()), and leave the icon LIBRARY alone: Font Awesome is drawn only when
 * Settings → Site says so, and this says whether it does.
 *
 * Every install, activation and delete is a line in the panel's audit log, attributed to cli:<user>.
 * Exit status: 0 done, 1 refused or failed, 2 usage.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!file_exists($root . '/config/installed.lock')) { fwrite(STDERR, "not installed\n"); exit(1); }
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/audit.php';

$db  = getDb();
$cfg = getSettings($db);
ensureSchema($db, $cfg);
$GLOBALS['db'] = $db; $GLOBALS['cfg'] = $cfg;

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$opts = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opts[$m[1]] = $m[2] ?? true;
$cmd = $args[0] ?? '';

$user = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (string)(posix_getpwuid(posix_geteuid())['name'] ?? get_current_user()) : get_current_user();
$actor = ['type' => 'system', 'id' => null, 'name' => mb_substr('cli:' . $user, 0, 64)];
// Written by somebody the web server is not, a package is one the panel cannot delete later.
if (function_exists('posix_geteuid') && is_dir($root . '/config') && @fileowner($root . '/config') !== posix_geteuid()
    && in_array($cmd, ['import', 'delete'], true)) {
    $owner = function_exists('posix_getpwuid') ? (string)(posix_getpwuid((int)fileowner($root . '/config'))['name'] ?? '?') : '?';
    fwrite(STDERR, "warning: config/ belongs to $owner, this runs as $user — run it as the web user: sudo -u $owner php tools/iconpack.php …\n");
}

$out = fn(string $s) => print($s . "\n");
$fail = function (string $s) { fwrite(STDERR, $s . "\n"); exit(1); };
$bytes = fn(int $n) => $n >= 1048576 ? sprintf('%.1f MB', $n / 1048576) : ($n >= 1024 ? sprintf('%.1f KB', $n / 1024) : $n . ' B');

/** The state the site is drawing with, in one line. */
$state = function () use ($db, $out) {
    $c = getSettings($db, true);
    $s = iconSetup($c);
    $what = $s['source'] === 'pack' ? 'package ' . $s['id'] : ($s['source'] === 'cdn7' ? 'Free 7.3.1 (jsDelivr)' : 'Free 6.7.2 (jsDelivr)');
    $out('icon library: ' . iconLibrary($c) . ' | Font Awesome source: ' . $what . ($s['fallback'] ? ' (stored choice unusable: ' . $s['fallback'] . ')' : '')
        . ' | style: ' . $s['style'] . ' | loaded: ' . implode(', ', array_keys($s['styles'])));
    if (iconLibrary($c) !== 'fontawesome') $out('note: the site draws Bootstrap Icons until Settings → Site → Icon library is Font Awesome.');
};

switch ($cmd) {
    case 'import':
        $src = $args[1] ?? '';
        if ($src === '') { fwrite(STDERR, "usage: php tools/iconpack.php import <zip|dir>\n"); exit(2); }
        $real = realpath($src);
        if ($real === false) $fail('not found: ' . $src);
        $r = iconpackImport($real, ['kind' => 'cli', 'name' => $real, 'by' => $actor['name']]);
        foreach (iconpackReportLines($r) as $line) $out($line);
        if (!$r['ok']) {
            auditLog($db, 'iconpack.install', ['actor' => $actor, 'ok' => false, 'target_type' => 'iconpack', 'target_id' => '',
                'summary' => 'refused an import from the shell: ' . $r['error'], 'detail' => ['source' => $real, 'error' => $r['error'], 'detail' => $r['detail']]]);
            exit(1);
        }
        if (!$r['already']) {
            auditLog($db, 'iconpack.install', ['actor' => $actor, 'target_type' => 'iconpack', 'target_id' => $r['id'],
                'summary' => 'installed Font Awesome ' . ucfirst((string)$r['edition']) . ' ' . $r['version'] . ' from the shell',
                'detail' => ['source' => $real, 'root' => $r['root'], 'styles' => $r['styles'], 'bytes' => $r['bytes']]]);
        }
        exit(0);

    case 'list':
        $c = getSettings($db, true);
        $list = iconpackList();
        if (!$list) { $out('no packages installed in ' . iconpackDir()); $state(); exit(0); }
        foreach ($list as $p) {
            if (!empty($p['broken'])) { $out(sprintf('  %-26s BROKEN (no readable manifest) — delete it and import it again', $p['id'])); continue; }
            $out(sprintf('%s %-26s %-4s %-7s %2d styles (%d outside all.css)  %9s  %4d icons  installed %s by %s%s',
                iconpackIsActive($p['id'], $c) ? '*' : ' ', $p['id'], $p['edition'], $p['version'], $p['styles'], $p['outside_all'],
                $bytes((int)$p['bytes']), $p['icons'], $p['installed_at'], $p['installed_by'], $p['metadata'] !== 'none' ? '  metadata: ' . $p['metadata'] : ''));
        }
        $state();
        exit(0);

    case 'activate':
    case 'styles':
        $id = $args[1] ?? '';
        $m = iconpackValidId($id) ? iconpackManifest($id) : null;
        if ($m === null) $fail('no such package: ' . $id . ' (php tools/iconpack.php list)');
        $c = getSettings($db, true);
        if ($cmd === 'styles' && !iconpackIsActive($id, $c)) $fail('styles apply to the active package; activate ' . $id . ' first');
        $list = $cmd === 'styles' ? ($args[2] ?? null) : ($opts['styles'] ?? null);
        if ($cmd === 'styles' && $list === null && !isset($opts['style'])) {
            // No list: say what there is to choose from.
            $setup = iconSetup($c);
            foreach ($m['styles'] as $st) {
                $fixed = iconpackStyleFixed($m, $st);
                $out(sprintf('  %-24s %-26s %s%s', $st['key'], $st['label'] . ' (' . $st['weight'] . ')',
                    $fixed ? (($m['core']['kind'] ?? 'all') === 'all' ? 'in all.css' : 'always, with fontawesome.css') : ($st['file'] ?? '—'),
                    isset($setup['styles'][$st['key']]) ? '  [loaded]' : ''));
            }
            $state();
            exit(0);
        }
        $data = ['fa_source' => 'pack', 'fa_pack' => $id];
        if ($list !== null && $list !== true) {
            $keys = [];
            foreach (preg_split('/[\s,]+/', (string)$list) ?: [] as $k) {
                $k = (string)preg_replace('/(\.min)?\.css$/', '', basename(trim($k)));
                if ($k === '') continue;
                if (!in_array($k, array_column($m['styles'], 'key'), true)) $fail('the package has no style ' . $k);
                $keys[] = $k;
            }
            $data['fa_pack_styles'] = json_encode($keys);
        } elseif ((string)($c['fa_pack'] ?? '') !== $id) {
            $data['fa_pack_styles'] = '[]';   // another package's ticks mean nothing here
        }
        if (isset($opts['style']) && $opts['style'] !== true) $data['fa_style'] = (string)$opts['style'];
        $r = iconSettingsApply($db, $c, $data);
        if ($r['error'] !== null) $fail(__($r['error'], $r['vars']));
        $after = getSettings($db, true);
        auditLog($db, $cmd === 'activate' ? 'iconpack.activate' : 'iconpack.styles', ['actor' => $actor, 'target_type' => 'iconpack', 'target_id' => $id,
            'summary' => ($cmd === 'activate' ? 'activated ' : 'changed the styles of ') . iconpackTitle($m, $id) . ', style ' . ($after['fa_style'] ?? 'solid') . ', from the shell',
            'detail' => $r['diff']]);
        if (isset($opts['style']) && $opts['style'] !== true && (string)$after['fa_style'] !== (string)$opts['style']) {
            $out('note: style ' . $opts['style'] . ' does not load with these files; the site uses ' . $after['fa_style']);
        }
        $state();
        exit(0);

    case 'verify':
        $id = $args[1] ?? '';
        $v = iconpackVerify($id);
        if (!empty($v['error'])) $fail($v['error'] . ': ' . $id);
        $out(($v['ok'] ? 'ok' : 'FAILED') . ': ' . $v['files'] . ' files, hash ' . ($v['hash_ok'] ? 'matches' : 'DIFFERS'));
        foreach (['missing', 'changed', 'extra'] as $k) foreach ($v[$k] as $f) $out('  ' . $k . ': ' . $f);
        exit($v['ok'] ? 0 : 1);

    case 'delete':
        $id = $args[1] ?? '';
        $c = getSettings($db, true);
        $m = iconpackManifest($id);
        $r = iconpackDelete($id, $c);
        if (!$r['ok']) {
            $msg = $r['error'] === 'active_package' ? $id . ' is the active package — choose another source first (Settings → Site, or activate another)' : $r['error'] . ': ' . $id;
            auditLog($db, 'iconpack.delete', ['actor' => $actor, 'ok' => false, 'target_type' => 'iconpack', 'target_id' => $id, 'summary' => 'refused: ' . $r['error']]);
            $fail($msg);
        }
        auditLog($db, 'iconpack.delete', ['actor' => $actor, 'target_type' => 'iconpack', 'target_id' => $id,
            'summary' => 'deleted ' . iconpackTitle($m, $id) . ' from the shell']);
        $out('deleted ' . $id);
        exit(0);

    default:
        fwrite(STDERR, "usage: php tools/iconpack.php import <zip|dir> | list | activate <id> [--styles=a,b] [--style=NAME]"
            . " | styles <id> [<file|key>,…] [--style=NAME] | verify <id> | delete <id>\n");
        exit(2);
}
