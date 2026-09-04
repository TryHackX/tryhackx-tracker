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
 */

require_once __DIR__ . '/../../includes/lang.php';

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? readJsonBody() : [];

/** The three lists an admin can edit, and the setting each is stored in. */
$LISTS = [
    'enabled'  => 'enabled_languages',
    'switcher' => 'switcher_languages',
    'users'    => 'user_languages',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Export is a GET so the browser can hand the result straight to a download.
    if (isset($_GET['export'])) {
        $code = strtolower(trim((string)$_GET['export']));
        if (!langInstalled($code)) jsonResponse(['error' => 'No such language.'], 404);
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
        jsonResponse(['error' => 'That language is not enabled, so it cannot be the site default.'], 400);
    }
    setSetting($db, 'default_language', $code);
    langInvalidate();
    auditNote(['target_id' => $code, 'summary' => 'set the site language to ' . strtoupper($code)]);
    jsonResponse(['success' => true, 'message' => $code === 'auto'
        ? 'Visitors now get the language their browser asks for.'
        : strtoupper($code) . ' is now the site default.']);
}
if ($op === 'auto') {
    $on = !empty($input['enabled']);
    setSetting($db, 'language_auto', $on ? '1' : '0');
    auditNote(['summary' => 'turned browser language detection ' . ($on ? 'on' : 'off')]);
    jsonResponse(['success' => true, 'message' => $on
        ? 'A visitor with no saved choice now gets the language their browser asks for.'
        : 'Visitors with no saved choice now get the site default.']);
}

// ── everything below is about one language ──────────────────────────────────
if ($op === 'toggle') {
    if (!langInstalled($code)) jsonResponse(['error' => 'No such language.'], 400);
    $scope = (string)($input['scope'] ?? 'enabled');
    if (!isset($LISTS[$scope])) jsonResponse(['error' => 'Unknown list.'], 400);
    $on = !empty($input['enabled']);

    if ($scope === 'enabled' && !$on && in_array($code, LANG_BUILT_IN, true)) {
        jsonResponse(['error' => strtoupper($code) . ' ships with the panel and is the end of every '
                               . 'fallback chain — it cannot be switched off.'], 400);
    }

    $current = array_keys($scope === 'enabled' ? langEnabled($cfg)
                        : ($scope === 'switcher' ? langForSwitcher($cfg) : langForUsers($cfg)));
    $next = $on ? array_values(array_unique(array_merge($current, [$code])))
                : array_values(array_diff($current, [$code]));
    if ($scope === 'enabled') $next = array_values(array_unique(array_merge($next, LANG_BUILT_IN)));
    if (!$next) {
        // An empty list reads as "no restriction", which would silently re-show everything —
        // the exact opposite of what was asked for. Refuse rather than do the opposite.
        jsonResponse(['error' => 'That would empty the list, which means "no restriction" — '
                               . 'leave at least one language on.'], 400);
    }
    setSetting($db, $LISTS[$scope], implode(',', $next));
    langInvalidate();
    auditNote(['target_id' => $code,
               'summary' => 'turned ' . strtoupper($code) . ' ' . ($on ? 'on' : 'off') . ' for ' . $scope]);
    jsonResponse(['success' => true]);
}

if ($op === 'duplicate') {
    $source = strtolower(trim((string)($input['source'] ?? '')));
    if (!langInstalled($source)) jsonResponse(['error' => 'No such language to copy.'], 400);
    if (!preg_match('/^[a-z]{2,3}$/', $code)) {
        jsonResponse(['error' => 'A language code is two or three letters, like "de" or "ast".'], 400);
    }
    if (langInstalled($code)) jsonResponse(['error' => strtoupper($code) . ' is already installed.'], 400);
    $strings = langLoad($source);
    if (!$strings) jsonResponse(['error' => 'That language file is empty.'], 400);

    // Freeze the current allow-list FIRST, so the copy does not go live the moment it exists — an
    // empty `enabled_languages` means "everything installed", and a half-translated copy appearing
    // in the switcher unannounced is not what "duplicate" means.
    setSetting($db, 'enabled_languages', implode(',', array_keys(langEnabled($cfg))));
    if (!langWriteFile($code, $strings, 'Copied from ' . strtoupper($source) . '.')) {
        jsonResponse(['error' => 'Could not write the language file. Is lang/ writable by the web user?'], 500);
    }
    langInvalidate();
    auditNote(['target_id' => $code, 'summary' => 'copied ' . strtoupper($source) . ' to ' . strtoupper($code)]);
    jsonResponse(['success' => true, 'code' => $code, 'strings' => count($strings),
                  'message' => strtoupper($code) . ' created from ' . strtoupper($source)
                             . ' with ' . number_format(count($strings)) . ' strings. It starts switched off.']);
}

if ($op === 'upload') {
    if (!preg_match('/^[a-z]{2,3}$/', $code)) {
        jsonResponse(['error' => 'A language code is two or three letters, like "de" or "ast".'], 400);
    }
    // The shipped languages are the end of every fallback chain and the reference the coverage
    // figure is measured against. A partial upload over one would hollow out the fallback for every
    // other translation without saying so. Duplicate to a free code and switch to that instead.
    if (in_array($code, LANG_BUILT_IN, true)) {
        jsonResponse(['error' => strtoupper($code) . ' ships with the panel and is never replaced by an '
                               . 'upload — every other language falls back to it. Duplicate it to a '
                               . 'free code and edit that.'], 400);
    }
    $strings = $input['strings'] ?? null;
    if (!is_array($strings) || !$strings) jsonResponse(['error' => 'That file has no strings in it.'], 400);
    if (count($strings) > 10000) jsonResponse(['error' => 'That file has more than 10 000 strings.'], 400);

    $clean = [];
    $bytes = 0;
    $skipped = 0;
    foreach ($strings as $k => $v) {
        // Flat "some.dotted.key" => "text" only. Anything else is DROPPED rather than written out,
        // and the count comes back so a file full of the wrong shape does not look like a success.
        if (!is_string($k) || !is_string($v) || strlen($k) > 160 || strlen($v) > 10000
            || !preg_match('/^[a-z0-9_.]+$/i', $k)) { $skipped++; continue; }
        $bytes += strlen($k) + strlen($v);
        if ($bytes > 2 * 1024 * 1024) jsonResponse(['error' => 'That file is larger than 2 MB of text.'], 400);
        $clean[$k] = $v;
    }
    if (!$clean) jsonResponse(['error' => 'Nothing in that file looked like a translation.'], 400);

    $isNew = !langInstalled($code);
    if ($isNew) setSetting($db, 'enabled_languages', implode(',', array_keys(langEnabled($cfg))));
    if (!langWriteFile($code, $clean, 'Installed from an uploaded JSON file.')) {
        jsonResponse(['error' => 'Could not write the language file. Is lang/ writable by the web user?'], 500);
    }
    langInvalidate();
    auditNote(['target_id' => $code,
               'summary' => ($isNew ? 'installed ' : 'replaced ') . strtoupper($code)
                          . ' (' . count($clean) . ' strings)']);
    jsonResponse(['success' => true, 'code' => $code, 'strings' => count($clean),
                  'skipped' => $skipped, 'enabled' => !$isNew,
                  'message' => strtoupper($code) . ': ' . number_format(count($clean)) . ' strings'
                             . ($skipped ? ', ' . number_format($skipped) . ' entries ignored' : '')
                             . ($isNew ? '. It starts switched off — turn it on when you are happy with it.' : '.')]);
}

if ($op === 'delete') {
    if (!langInstalled($code)) jsonResponse(['error' => 'No such language.'], 400);
    if (in_array($code, LANG_BUILT_IN, true)) {
        jsonResponse(['error' => strtoupper($code) . ' ships with the panel and cannot be removed.'], 400);
    }
    if (!langDeleteFile($code)) jsonResponse(['error' => 'Could not remove the language file.'], 500);

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
    jsonResponse(['success' => true, 'message' => strtoupper($code) . ' removed.']);
}

jsonResponse(['error' => 'Unknown operation.'], 400);
