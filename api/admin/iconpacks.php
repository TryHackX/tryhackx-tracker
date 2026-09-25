<?php
/**
 * GET/POST admin/iconpacks — Font Awesome packages (Settings → Site → Font Awesome, 1.69.0).
 *
 * GET                                  the packages, the setup the site draws with, the map's
 *                                      coverage for it, and the limits an upload meets
 * GET  ?op=preview&source=&pack=&styles=&style=
 *                                      what a setup WOULD load and draw — the form's values before
 *                                      they are saved: its stylesheets, the classes of every mapped
 *                                      icon, the style select's choices, the package's styles
 * GET  ?op=verify&id=                  an installed package's files against its manifest
 * POST multipart {op: upload, file, confirm_password}
 * POST {op: install_path, path, confirm_password}   a zip or a directory ON THE SERVER
 * POST {op: activate, id, confirm_password}         the four Font Awesome settings, as the CLI writes them
 * POST {op: delete, id, confirm_password}           never the active one
 *
 * Owner only: not in adminEndpointPermission(), like every Settings endpoint — there is no panel
 * permission that edits settings, so "a permission that lets an operator edit settings" is the
 * owner's own session. Every write asks for the password again (requireAdminReauth()): installing
 * or activating a package decides what stylesheet and fonts every visitor's browser loads, which is a
 * decision about whom the site trusts, the same kind as csp_extra_hosts. CSRF is api.php's (every
 * non-GET admin call carries X-CSRF-Token; the upload's fetch sends it too).
 *
 * The three that change something write their own audit line — iconpack.install, iconpack.activate,
 * iconpack.delete — refusals included; the automatic line is suppressed so there is exactly one.
 */

$by = function_exists('auditActor') ? (string)(auditActor($db)['name'] ?? 'panel') : 'panel';
$iniBytes = function (string $v): int {
    if (function_exists('userMediaIniBytes')) return userMediaIniBytes($v);
    $n = (int)$v;
    switch (strtolower(substr(trim($v), -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return $n;
};
$uploadMax = (function () use ($iniBytes): int {
    $u = $iniBytes((string)ini_get('upload_max_filesize'));
    $p = $iniBytes((string)ini_get('post_max_size'));
    $lim = array_filter([$u, $p], fn($x) => $x > 0);
    return $lim ? (int)min($lim) : 0;
})();

/** Everything the table, the selects and the summary need, for the site as it is now. */
$state = function () use ($db, $uploadMax): array {
    $c = getSettings($db, true);
    $packs = iconpackList();
    foreach ($packs as &$p) $p['active'] = iconpackIsActive($p['id'], $c);
    unset($p);
    $store = iconpackDir();
    return [
        'success'  => true,
        'packages' => $packs,
        'setup'    => iconSetupSummary(iconSetup(['icon_library' => 'fontawesome'] + $c)),
        'library'  => iconLibrary($c),
        'limits'   => ['upload' => $uploadMax, 'total' => ICONPACK_MAX_TOTAL_BYTES, 'entries' => ICONPACK_ZIP_MAX_ENTRIES,
                       'files' => ICONPACK_MAX_KEPT_FILES, 'packages' => ICONPACK_MAX_PACKAGES],
        'store'    => ['writable' => is_dir($store) ? is_writable($store) : is_writable(dirname($store))],
        'preview'  => iconPreviewNames(),
    ];
};

/** One audit line for a write, and none from the router. */
$audit = function (string $action, bool $ok, string $id, string $summary, ?array $detail = null) use ($db): void {
    auditLog($db, $action, ['ok' => $ok, 'target_type' => 'iconpack', 'target_id' => $id, 'summary' => $summary, 'detail' => $detail]);
    auditSuppress();
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    $op = (string)($_GET['op'] ?? '');
    if ($op === 'preview') {
        // The form's values, unsaved, judged the way a save would judge them — so the preview shows
        // what the Save button would really give, a coerced style included.
        $want = [
            'fa_source'      => (string)($_GET['source'] ?? 'cdn6'),
            'fa_pack'        => (string)($_GET['pack'] ?? ''),
            'fa_pack_styles' => (string)($_GET['styles'] ?? '[]'),
            'fa_style'       => (string)($_GET['style'] ?? 'solid'),
        ];
        $n = iconSettingsNormalise($want, []);
        if ($n['error'] !== null) {
            jsonResponse(['success' => false, 'error' => __($n['error'], $n['vars']), 'code' => $n['error']], 400);
        }
        $setup = iconSetup(['icon_library' => 'fontawesome'] + $n['data']);
        jsonResponse(['success' => true, 'setup' => iconSetupSummary($setup), 'map' => iconFaMap($setup), 'applied' => $n['data']]);
    }
    if ($op === 'verify') {
        $v = iconpackVerify((string)($_GET['id'] ?? ''));
        if (!empty($v['error'])) jsonResponse(['success' => false, 'error' => iconpackMessage((string)$v['error'])], 404);
        jsonResponse(['success' => true, 'verify' => $v]);
    }
    jsonResponse($state());
}

// A body over post_max_size arrives with every field gone, the op and the password with it: say what
// happened in the upload's own words, not "wrong password".
$len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$post = $iniBytes((string)ini_get('post_max_size'));
if ($len > 0 && $post > 0 && $len > $post && empty($_POST) && empty($_FILES)) {
    auditSuppress();
    jsonResponse(['success' => false, 'error' => __('api.iconpack.upload_too_large', ['max' => (string)round($uploadMax / 1048576)])], 413);
}
$input = readJsonBody();
$op = (string)($input['op'] ?? '');
$password = (string)($input['confirm_password'] ?? '');

if ($op === 'upload' || $op === 'install_path') {
    if ($op === 'upload') {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            auditSuppress();
            jsonResponse(['success' => false, 'error' => __('api.iconpack.no_file')], 400);
        }
        $err = (int)($file['error'] ?? UPLOAD_ERR_OK);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            auditSuppress();
            jsonResponse(['success' => false, 'error' => __('api.iconpack.upload_too_large', ['max' => (string)round($uploadMax / 1048576)])], 413);
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            auditSuppress();
            jsonResponse(['success' => false, 'error' => __('api.iconpack.upload_failed')], 400);
        }
        $source = (string)$file['tmp_name'];
        $name = mb_substr(basename(str_replace('\\', '/', (string)($file['name'] ?? 'upload.zip'))), 0, 200);
    } else {
        $source = trim((string)($input['path'] ?? ''));
        // An absolute path on this server: /srv/… or C:\… — never a relative one, which would be
        // relative to whatever directory php-fpm happens to run in.
        if ($source === '' || !preg_match('#^(/|[A-Za-z]:[\\\\/])#', $source) || str_contains($source, "\0")) {
            auditSuppress();
            jsonResponse(['success' => false, 'error' => __('api.iconpack.path_absolute')], 400);
        }
        $name = mb_substr($source, 0, 200);
    }
    requireAdminReauth($password, $cfg);
    $r = iconpackImport($source, ['kind' => $op === 'upload' ? 'upload' : 'path', 'name' => $name, 'by' => $by]);
    if (!$r['ok']) {
        $msg = iconpackMessage((string)$r['error'], (string)$r['detail']);
        // The file's name is in the detail: a summary is one line of the audit's table, cut with "…".
        $audit('iconpack.install', false, '', 'refused ' . ($op === 'upload' ? 'an upload' : 'a server path') . ': ' . $r['error'],
               ['source' => $name, 'error' => $r['error'], 'detail' => $r['detail']]);
        jsonResponse(['success' => false, 'error' => $msg, 'report' => $r, 'lines' => iconpackReportLines($r)] + $state(), 422);
    }
    if (!$r['already']) {
        $audit('iconpack.install', true, (string)$r['id'], 'installed Font Awesome ' . ucfirst((string)$r['edition']) . ' ' . $r['version'] . ' from ' . ($op === 'upload' ? 'an upload' : 'a server path'),
               ['source' => $name, 'root' => $r['root'], 'styles' => $r['styles'], 'bytes' => $r['bytes']]);
    } else {
        auditSuppress();
    }
    $msg = $r['already'] ? __('api.iconpack.msg_already', ['id' => $r['id']]) : __('api.iconpack.msg_installed', ['id' => $r['id']]);
    jsonResponse(['success' => true, 'message' => $msg, 'report' => $r, 'lines' => iconpackReportLines($r)] + $state());
}

if ($op === 'activate') {
    $id = (string)($input['id'] ?? '');
    if (!iconpackValidId($id) || iconpackManifest($id) === null) {
        auditSuppress();
        jsonResponse(['success' => false, 'error' => iconpackMessage('unknown_package')], 404);
    }
    requireAdminReauth($password, $cfg);
    // The ticks belong to a package: another package's mean nothing here, and this one's are kept.
    $data = ['fa_source' => 'pack', 'fa_pack' => $id];
    if ((string)($cfg['fa_pack'] ?? '') !== $id) $data['fa_pack_styles'] = '[]';
    $r = iconSettingsApply($db, $cfg, $data);
    if ($r['error'] !== null) {
        $audit('iconpack.activate', false, $id, 'refused: ' . $r['error']);
        jsonResponse(['success' => false, 'error' => __($r['error'], $r['vars'])], 400);
    }
    $audit('iconpack.activate', true, $id, 'activated ' . iconpackTitle(iconpackManifest($id), $id), $r['diff']);
    $s = $state();
    $msg = $s['library'] === 'fontawesome' ? __('api.iconpack.msg_activated', ['id' => $id]) : __('api.iconpack.msg_activated_bi', ['id' => $id]);
    jsonResponse(['message' => $msg, 'reload' => true] + $s);
}

if ($op === 'delete') {
    $id = (string)($input['id'] ?? '');
    if (!iconpackValidId($id) || !in_array($id, iconpackInstalledIds(), true)) {
        auditSuppress();
        jsonResponse(['success' => false, 'error' => iconpackMessage('unknown_package')], 404);
    }
    if (iconpackIsActive($id, $cfg)) {
        $audit('iconpack.delete', false, $id, 'refused: it is the active package');
        jsonResponse(['success' => false, 'error' => iconpackMessage('active_package')], 409);
    }
    requireAdminReauth($password, $cfg);
    $m = iconpackManifest($id);
    $r = iconpackDelete($id, $cfg);
    if (!$r['ok']) {
        $audit('iconpack.delete', false, $id, 'refused: ' . $r['error']);
        jsonResponse(['success' => false, 'error' => iconpackMessage((string)$r['error'])], 500);
    }
    $audit('iconpack.delete', true, $id, 'deleted ' . iconpackTitle($m, $id));
    jsonResponse(['message' => __('api.iconpack.msg_deleted', ['id' => $id])] + $state());
}

auditSuppress();
jsonResponse(['success' => false, 'error' => __('api.iconpack.unknown_op')], 400);
