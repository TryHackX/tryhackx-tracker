<?php
/**
 * GET/POST — install, enable and remove interface languages (Settings → Languages).
 *
 * GET   the installed languages with their three visibility flags and how complete each one is,
 *       plus the codes the panel knows a name for.
 * POST  {"op": "toggle"|"duplicate"|"upload"|"delete"|"default"|"auto", …}
 *
 * Not in the permission map, so this is owner-only. It writes files under `lang/` that are
 * `require`d on every request; that is not a capability to hand out with a checkbox.
 *
 * UPLOADS ARE JSON, NEVER PHP, and that is the whole security design here. A `lang/<code>.php` is
 * included on every page load, so accepting one as an upload would be handing an admin form a way
 * to put arbitrary code on the include path. Instead the payload is parsed as data, every pair is
 * checked to be a flat string→string, and the file is written by var_export() — what lands on disk
 * is a literal array this code generated, never anything the uploader wrote.
 *
 * That keeps PHP out of the file; the VALUES are the other half. The templates print __() without
 * escaping, because the shipped strings carry <strong> and <a href> on purpose, so a value is also
 * HTML that reaches every page — and a stranger's <img onerror> would run in the owner's session
 * and, once the language is switched on, in every visitor's. Every value therefore goes through
 * langSanitizeValue() (includes/lang.php) and a key it refuses is dropped and named in the reply.
 */

require_once __DIR__ . '/../../includes/lang.php';

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? readJsonBody() : [];

/** The three lists an admin can edit, and the setting each is stored in. */
$LISTS = [
    'enabled'  => 'enabled_languages',
    'switcher' => 'switcher_languages',
    'users'    => 'user_languages',
];

/**
 * The sentence that names the keys langSanitizeValue() refused.
 *
 * Named, not counted: the count already comes back as `skipped` for entries of the wrong shape, and
 * "3 dropped" tells a translator nothing about where to look. Ten keys is enough to find the
 * pattern; the full list travels in the reply's `dropped` array for anyone who needs the rest.
 */
$droppedNote = function (array $dropped): string {
    $shown = array_slice($dropped, 0, 10);
    if (count($dropped) > count($shown)) $shown[] = '…';
    return __('settings.lang_upload_dropped', ['count' => count($dropped), 'keys' => implode(', ', $shown)]);
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Export is a GET so the browser can hand the result straight to a download.
    if (isset($_GET['export'])) {
        $code = strtolower(trim((string)$_GET['export']));
        if (!langInstalled($code)) jsonResponse(['error' => __('api.lang.no_such_language')], 404);
        jsonResponse(['success' => true, 'code' => $code, 'strings' => langLoad($code)]);
    }

    langInvalidate();
    $enabled  = langEnabled($cfg);
    $switcher = langForSwitcher($cfg);
    $forUsers = langForUsers($cfg);

    $langs = [];
    foreach (langAvailable() as $code => $name) {
        $cov = langCoverage($code);
        $langs[] = [
            'code'     => $code,
            'name'     => $name,
            'enabled'  => isset($enabled[$code]),
            'switcher' => isset($switcher[$code]),
            'users'    => isset($forUsers[$code]),
            'builtIn'  => in_array($code, LANG_BUILT_IN, true),
            'strings'  => $cov['strings'],
            'missing'  => $cov['missing'],
            'coverage' => $cov['percent'],
        ];
    }
    // Drives the "de → Deutsch" hint in the add-language form, and tells it which codes are taken.
    $known = [];
    foreach (LANG_NAMES as $code => $label) {
        $known[] = ['code' => $code, 'name' => $label, 'installed' => langInstalled($code)];
    }
    jsonResponse([
        'success'   => true,
        'languages' => $langs,
        'known'     => $known,
        'default'   => (string)($cfg['default_language'] ?? LANG_FALLBACK),
        'auto'      => ($cfg['language_auto'] ?? '0') === '1',
        'builtIn'   => LANG_BUILT_IN,
        'fallback'  => LANG_FALLBACK,
        'reference' => langCoverage(LANG_FALLBACK)['total'],
        'writable'  => is_writable(LANG_DIR),
    ]);
}

requirePost();
$op = strtolower(trim((string)($input['op'] ?? '')));
$code = strtolower(trim((string)($input['code'] ?? '')));

// ── the site default, and whether the browser may choose ────────────────────
if ($op === 'default') {
    // 'auto' is a real answer, not a missing one: it says "let Accept-Language decide", which is a
    // different intention from picking a language and is worth being able to express directly.
    if ($code !== 'auto' && !langSupported($cfg, $code)) {
        jsonResponse(['error' => __('api.lang.default_not_enabled')], 400);
    }
    setSetting($db, 'default_language', $code);
    langInvalidate();
    auditNote(['target_id' => $code, 'summary' => 'set the site language to ' . strtoupper($code)]);
    jsonResponse(['success' => true, 'message' => $code === 'auto'
        ? __('api.lang.default_auto')
        : __('api.lang.default_set', ['code' => strtoupper($code)])]);
}
if ($op === 'auto') {
    $on = !empty($input['enabled']);
    setSetting($db, 'language_auto', $on ? '1' : '0');
    auditNote(['summary' => 'turned browser language detection ' . ($on ? 'on' : 'off')]);
    jsonResponse(['success' => true, 'message' => $on
        ? __('api.lang.auto_on')
        : __('api.lang.auto_off')]);
}

// ── everything below is about one language ──────────────────────────────────
if ($op === 'toggle') {
    if (!langInstalled($code)) jsonResponse(['error' => __('api.lang.no_such_language')], 400);
    $scope = (string)($input['scope'] ?? 'enabled');
    if (!isset($LISTS[$scope])) jsonResponse(['error' => __('api.lang.unknown_list')], 400);
    $on = !empty($input['enabled']);

    if ($scope === 'enabled' && !$on && in_array($code, LANG_BUILT_IN, true)) {
        jsonResponse(['error' => __('api.lang.builtin_cannot_disable', ['code' => strtoupper($code)])], 400);
    }

    $current = array_keys($scope === 'enabled' ? langEnabled($cfg)
                        : ($scope === 'switcher' ? langForSwitcher($cfg) : langForUsers($cfg)));
    $next = $on ? array_values(array_unique(array_merge($current, [$code])))
                : array_values(array_diff($current, [$code]));
    if ($scope === 'enabled') $next = array_values(array_unique(array_merge($next, LANG_BUILT_IN)));
    if (!$next) {
        // An empty list reads as "no restriction", which would silently re-show everything —
        // the exact opposite of what was asked for. Refuse rather than do the opposite.
        jsonResponse(['error' => __('api.lang.list_would_empty')], 400);
    }
    setSetting($db, $LISTS[$scope], implode(',', $next));
    langInvalidate();
    auditNote(['target_id' => $code,
               'summary' => 'turned ' . strtoupper($code) . ' ' . ($on ? 'on' : 'off') . ' for ' . $scope]);
    jsonResponse(['success' => true]);
}

if ($op === 'duplicate') {
    $source = strtolower(trim((string)($input['source'] ?? '')));
    if (!langInstalled($source)) jsonResponse(['error' => __('api.lang.no_such_source')], 400);
    if (!preg_match('/^[a-z]{2,3}$/', $code)) {
        jsonResponse(['error' => __('api.lang.code_format')], 400);
    }
    if (langInstalled($code)) jsonResponse(['error' => __('api.lang.already_installed', ['code' => strtoupper($code)])], 400);
    $strings = langLoad($source);
    if (!$strings) jsonResponse(['error' => __('api.lang.file_empty')], 400);

    // A copy is held to the same rule as an upload, because the source may be an upload that a
    // version before this one wrote without ever looking at a value — and copying it must not be
    // the way an old payload gets a fresh file. The shipped dictionaries are the exception: they are
    // vetted with the code and carry a handful of attributes (a `class=`, an `id=`) the rule does
    // not allow, and a copy of English with those keys missing would be a copy nobody asked for.
    $dropped = [];
    if (!in_array($source, LANG_BUILT_IN, true)) {
        foreach ($strings as $k => $v) {
            if (!is_string($v) || langSanitizeValue($v) === null) { $dropped[] = $k; unset($strings[$k]); }
        }
        if (!$strings) jsonResponse(['error' => $droppedNote($dropped), 'dropped' => $dropped], 400);
    }

    // Freeze the current allow-list FIRST, so the copy does not go live the moment it exists — an
    // empty `enabled_languages` means "everything installed", and a half-translated copy appearing
    // in the switcher unannounced is not what "duplicate" means.
    setSetting($db, 'enabled_languages', implode(',', array_keys(langEnabled($cfg))));
    if (!langWriteFile($code, $strings, 'Copied from ' . strtoupper($source) . '.')) {
        jsonResponse(['error' => __('api.lang.write_failed')], 500);
    }
    langInvalidate();
    auditNote(['target_id' => $code, 'summary' => 'copied ' . strtoupper($source) . ' to ' . strtoupper($code)
                                                . ($dropped ? ' (' . count($dropped) . ' strings dropped)' : '')]);
    jsonResponse(['success' => true, 'code' => $code, 'strings' => count($strings), 'dropped' => $dropped,
                  'message' => __('api.lang.duplicated', ['code' => strtoupper($code), 'source' => strtoupper($source),
                                                         'count' => number_format(count($strings))])
                             . ($dropped ? ' ' . $droppedNote($dropped) : '')]);
}

if ($op === 'upload') {
    if (!preg_match('/^[a-z]{2,3}$/', $code)) {
        jsonResponse(['error' => __('api.lang.code_format')], 400);
    }
    // The shipped languages are the end of every fallback chain and the reference the coverage
    // figure is measured against. A partial upload over one would hollow out the fallback for every
    // other translation without saying so. Duplicate to a free code and switch to that instead.
    if (in_array($code, LANG_BUILT_IN, true)) {
        jsonResponse(['error' => __('api.lang.builtin_no_upload', ['code' => strtoupper($code)])], 400);
    }
    $strings = $input['strings'] ?? null;
    if (!is_array($strings) || !$strings) jsonResponse(['error' => __('api.lang.upload_no_strings')], 400);
    if (count($strings) > 10000) jsonResponse(['error' => __('api.lang.upload_too_many')], 400);

    $clean = [];
    $bytes = 0;
    $skipped = 0;
    $dropped = [];
    foreach ($strings as $k => $v) {
        // Flat "some.dotted.key" => "text" only. Anything else is DROPPED rather than written out,
        // and the count comes back so a file full of the wrong shape does not look like a success.
        if (!is_string($k) || !is_string($v) || strlen($k) > 160 || strlen($v) > 10000
            || !preg_match('/^[a-z0-9_.]+$/i', $k)) { $skipped++; continue; }
        // The value is checked too, because the templates print it unescaped and the shape checks
        // above would wave an <img onerror> through as a perfectly flat string. A refused key is
        // named in the reply rather than counted with the malformed ones: it is a string a
        // translator wrote and can fix, not a line of the wrong shape.
        if (langSanitizeValue($v) === null) { $dropped[] = $k; continue; }
        $bytes += strlen($k) + strlen($v);
        if ($bytes > 2 * 1024 * 1024) jsonResponse(['error' => __('api.lang.upload_too_large')], 400);
        $clean[$k] = $v;
    }
    if (!$clean) {
        jsonResponse(['error' => $dropped ? $droppedNote($dropped) : __('api.lang.upload_nothing_usable'),
                      'dropped' => $dropped], 400);
    }

    $isNew = !langInstalled($code);
    if ($isNew) setSetting($db, 'enabled_languages', implode(',', array_keys(langEnabled($cfg))));
    if (!langWriteFile($code, $clean, 'Installed from an uploaded JSON file.')) {
        jsonResponse(['error' => __('api.lang.write_failed')], 500);
    }
    langInvalidate();
    auditNote(['target_id' => $code,
               'summary' => ($isNew ? 'installed ' : 'replaced ') . strtoupper($code)
                          . ' (' . count($clean) . ' strings' . ($dropped ? ', ' . count($dropped) . ' dropped' : '') . ')']);
    jsonResponse(['success' => true, 'code' => $code, 'strings' => count($clean),
                  'skipped' => $skipped, 'dropped' => $dropped, 'enabled' => !$isNew,
                  'message' => __('api.lang.uploaded_count', ['code' => strtoupper($code), 'count' => number_format(count($clean))])
                             . ($skipped ? __('api.lang.uploaded_skipped', ['skipped' => number_format($skipped)]) : '')
                             . ($isNew ? __('api.lang.uploaded_new_tail') : '.')
                             . ($dropped ? ' ' . $droppedNote($dropped) : '')]);
}

if ($op === 'delete') {
    if (!langInstalled($code)) jsonResponse(['error' => __('api.lang.no_such_language')], 400);
    if (in_array($code, LANG_BUILT_IN, true)) {
        jsonResponse(['error' => __('api.lang.builtin_cannot_remove', ['code' => strtoupper($code)])], 400);
    }
    if (!langDeleteFile($code)) jsonResponse(['error' => __('api.lang.remove_failed')], 500);

    // A deleted language must not stay the site default or linger in an allow-list. Only lists that
    // were ACTUALLY set are rewritten: an untouched one is stored empty, meaning "everything
    // enabled", and writing a list into it here would silently freeze the set.
    if ((string)($cfg['default_language'] ?? '') === $code) setSetting($db, 'default_language', LANG_FALLBACK);
    langInvalidate();
    foreach ($LISTS as $scope => $setting) {
        if (trim((string)($cfg[$setting] ?? '')) === '') continue;
        $list = array_keys($scope === 'enabled' ? langEnabled($cfg)
                         : ($scope === 'switcher' ? langForSwitcher($cfg) : langForUsers($cfg)));
        setSetting($db, $setting, implode(',', array_values(array_diff($list, [$code]))));
    }
    // Accounts that had chosen it fall back to the site default rather than to a language that is
    // no longer there — NULL is exactly "follow the site".
    try { $db->prepare("UPDATE users SET language = NULL WHERE language = ?")->execute([$code]); }
    catch (\Throwable $e) { /* the column may pre-date this version */ }
    langInvalidate();
    auditNote(['target_id' => $code, 'summary' => 'removed the ' . strtoupper($code) . ' translation']);
    jsonResponse(['success' => true, 'message' => __('api.lang.removed', ['code' => strtoupper($code)])]);
}

jsonResponse(['error' => __('api.lang.unknown_op')], 400);
