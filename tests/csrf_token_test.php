<?php
/**
 * The session's CSRF token, published ONCE on every public page (1.71.0):
 *   php tests/csrf_token_test.php
 * The rendered half needs the local site on http://127.0.0.1:8089/ (VERIFY_BASE to point elsewhere) and says SKIP
 * without it.
 *
 * The owner: "Rating now answers Invalid CSRF token when we do it on a profile — check whether the same problem
 * shows up anywhere else, with other actions." One token per session, but every page published it under a name of
 * its own (#search-csrf, #account-csrf on the account page AND a profile, the shoutbox's #shout-csrf, a form's
 * hidden field, the front page nothing), and each script read the one it knew: castVote() read #search-csrf alone,
 * and the Info panel opens on a profile and on the account page too. Pinned here:
 *   1. the layout: one <meta name="csrf-token"> in <head>, only with a session — a meta, no script to allow;
 *   2. the one helper, assets/js/app.js csrfToken(): a widget's own (data-csrf naming its field), a form's own
 *      field, else the page's — exported on window for the other scripts;
 *   3. every public POST takes its token from it: no public script reads a page's own id or a page-wide field
 *      any more, and every csrf_token a script sends is the helper's answer (or a form's own, by FormData);
 *   4. every public page type rendered — as a guest, then signed in (the search page, a profile, one's own
 *      profile, the account page, the front page with the shoutbox): exactly one meta, in <head>, carrying the
 *      session's token — the same on every page of one session, another for another session, and the same value
 *      as every id a page still carries — and the server takes the token the page publishes, and nothing else.
 * Self-cleaning: the account it signs in with (and what signing in leaves), every settings row it touches put
 * back as it was, value AND time.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/settings.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/schema.php';
require_once $root . '/includes/whitelist.php';
require_once $root . '/includes/mail.php';
require_once $root . '/includes/users.php';
require_once $root . '/includes/favourites.php';
require_once $root . '/includes/people.php';
require_once $root . '/includes/usermedia.php';
require_once $root . '/includes/profilevotes.php';
require_once $root . '/includes/lists.php';
require_once $root . '/includes/who.php';
require_once $root . '/includes/lang.php';

$fails = 0; $n = 0; $skips = 0;
function check(string $name, bool $ok, string $info = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : '  -> ' . $info) . "\n";
    if (!$ok) $fails++;
}
function skip(string $name, string $why): void { global $skips; $skips++; echo 'SKIP ' . $name . '  -> ' . $why . "\n"; }
$src = fn(string $f): string => (string)@file_get_contents($root . '/' . $f);
langInit([], 'en');

/* ══ 1. the layout publishes it once ══════════════════════════════════════ */
$layout = $src('templates/layout.php');
$metaAt = strpos($layout, '<meta name="csrf-token" content="<?= sanitize((string)$csrfToken) ?>">');
check('the layout prints <meta name="csrf-token"> with the session\'s token, escaped', $metaAt !== false);
check('… only while a session exists and has a token',
      str_contains($layout, "<?php if (session_id() !== '' && !empty(\$csrfToken)): ?>\n    <meta name=\"csrf-token\""));
check('… in <head>, before any script and before the body', $metaAt !== false && $metaAt < strpos($layout, '</head>') && $metaAt < strpos($layout, '<script')
      && $metaAt < strpos($layout, '<body'));
check('… as a meta and nothing else: no inline script carries the token, so the policy\'s nonce has nothing to allow',
      substr_count($layout, '$csrfToken') === 2 && !preg_match('/<script[^>]*>[^<]*csrf/i', $layout));
$index = $src('index.php');
check('index.php starts the session and makes the token for every page before the layout is included',
      strpos($index, 'session_start(sessionCookieParams($cfg));') < strpos($index, '$csrfToken = generateCsrfToken();')
      && strpos($index, '$csrfToken = generateCsrfToken();') < strpos($index, "include __DIR__ . '/templates/layout.php';"));
foreach (['templates/pages/search.php' => 'id="search-csrf"', 'templates/pages/account.php' => 'id="account-csrf"',
          'templates/pages/profile.php' => 'id="account-csrf"', 'templates/partials/shoutbox_widget.php' => 'id="shout-csrf"',
          'templates/pages/emotes.php' => 'id="shout-csrf"'] as $f => $id) {
    check("the old id stays where it was — $id in $f (the checks and the tests read it)", str_contains($src($f), $id));
}

/* ══ 2. the one helper ════════════════════════════════════════════════════ */
$app = $src('assets/js/app.js');
$hFrom = (int)strpos($app, 'function csrfToken(el) {');
$helper = $hFrom > 0 ? substr($app, $hFrom, (int)strpos($app, "\n}\n", $hFrom) - $hFrom + 2) : '';
check('app.js has csrfToken(el) at file scope, and on window for the other scripts',
      $helper !== '' && str_contains($app, "\nfunction csrfToken(el) {") && str_contains($app, "\nwindow.csrfToken = csrfToken;"));
$pNamer = strpos($helper, "node.closest('[data-csrf]')");
$pForm  = strpos($helper, "node.closest('form')");
$pMeta  = strpos($helper, "document.querySelector('meta[name=\"csrf-token\"]')");
check('… its sources in order: a data-csrf on the element or an ancestor NAMING the field that holds a widget\'s own token, then the element\'s own form\'s field, then the page\'s meta',
      $pNamer !== false && $pForm !== false && $pMeta !== false && $pNamer < $pForm && $pForm < $pMeta
      && str_contains($helper, 'document.getElementById(namer.dataset.csrf)') && str_contains($helper, "form.querySelector('input[name=\"csrf_token\"]')"));
check('… a data-csrf that names nothing (or an empty field) falls through to the page\'s — never an empty token while the page has one',
      str_contains($helper, "if (named && typeof named.value === 'string' && named.value !== '') return named.value;")
      && str_contains($helper, "return meta ? (meta.getAttribute('content') || '') : '';"));
check('the shoutbox widget still names its own field with data-csrf', str_contains($src('templates/partials/shoutbox_widget.php'), 'data-csrf="shout-csrf">'));

/* ══ 3. every public POST takes its token from it ═════════════════════════ */
$public = array_values(array_filter(glob($root . '/assets/js/*.js'), fn($f) => !str_starts_with(basename($f), 'admin')));
$bad = []; $sites = 0; $wrappers = [];
foreach ($public as $f) {
    $js = (string)file_get_contents($f);
    $b = basename($f);
    // Reading a page's own id, or the first token field anywhere on the page: the shape of the bug.
    if (preg_match_all('/getElementById\(\s*[\'"](?:search|account|shout)-csrf[\'"]\s*\)|\$id\(\s*[\'"][a-z]+-csrf[\'"]\s*\)|\$\(\s*[\'"][a-z]+-csrf[\'"]\s*\)|document\.querySelector\(\s*[\'"]input\[name="csrf_token"\][\'"]\s*\)|form\.csrf_token\.value/', $js, $m)) {
        foreach ($m[0] as $hit) $bad[] = "$b reads $hit";
    }
    // Every token a script sends: a csrf_token property or assignment, a FormData field, an X-CSRF-Token header.
    if (preg_match_all('/(?:csrf_token\s*[:=]\s*|append\(\s*\'csrf_token\'\s*,\s*|\'X-CSRF-Token\'\s*:\s*)([^,;}\n]+)/', $js, $m)) {
        foreach ($m[1] as $expr) {
            $expr = trim($expr);
            $sites++;
            if (!preg_match('/^(?:csrfToken\([a-zA-Z]*\)|window\.csrfToken\(\)|csrf\(\)\)?|csrfOf\([a-zA-Z]+\)|csrfForContent\(\)|csrfOfEditor\(\))\)?$/', $expr)) $bad[] = "$b sends $expr";
        }
    }
    // A file's own csrf() is a wrapper round the helper and nothing else.
    if (preg_match('/function csrf\(\)\s*\{\s*(.*?)\s*\}\n|const csrf = \(\) => (.*?);\n/s', $js, $w)) {
        $body = trim(($w[1] ?? '') !== '' ? $w[1] : ($w[2] ?? ''));
        $wrappers[$b] = $body;
        if (!str_contains($body, 'window.csrfToken(')) $bad[] = "$b wraps something else: $body";
    }
}
check('no public script reads a page\'s own token id (#search-csrf, #account-csrf, #shout-csrf) or the first token field on the page', !array_filter($bad, fn($x) => str_contains($x, ' reads ')),
      implode(' | ', array_filter($bad, fn($x) => str_contains($x, ' reads '))));
check("every token a public script sends ($sites places) is the helper's answer — directly, or through a file's own csrf(), csrfOf(form), the Info panel's or the editor's",
      $sites >= 30 && !array_filter($bad, fn($x) => str_contains($x, ' sends ')), implode(' | ', array_filter($bad, fn($x) => str_contains($x, ' sends '))));
check('the scripts with a csrf() of their own — favourites, people, shoutbox, account security, the profile\'s description, the picture editor — all wrap window.csrfToken()',
      count($wrappers) === 6 && !array_filter($bad, fn($x) => str_contains($x, ' wraps ')), json_encode(array_keys($wrappers)));
check('… the shoutbox asks with its widget (whose data-csrf names #shout-csrf); the profile\'s description with its own block',
      str_contains($wrappers['shoutbox.js'] ?? '', "window.csrfToken(document.getElementById('shoutbox'))") && str_contains($wrappers['profile-bio.js'] ?? '', 'window.csrfToken(root)'));
check('the vote reads the page\'s token — not #search-csrf — and so does the Info panel\'s Refresh',
      preg_match('/async function castVote\(hash, value, holder, pressed\) \{.*?csrf_token: csrfToken\(holder\) \}\s*: \{ hash, vote: value, csrf_token: csrfToken\(holder\) \};/s', $app) === 1
      && str_contains($app, "op: 'refresh', csrf_token: csrfToken(btn) });"));
// The three form posts that send FormData(form): each form carries its own hidden field.
check('the forms that post everything they hold (report, the two appeals) carry their own token field',
      substr_count($app, 'new FormData(form).forEach((v, k) => data[k] = v);') === 3
      && str_contains($src('templates/pages/report.php'), '<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">')
      && substr_count($src('templates/pages/status.php'), '<input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">') === 3);
check('the public scripts that post are loaded after app.js on every page (the helper exists before they run)',
      ($pa = strpos($layout, 'assets/js/app.js')) !== false && !array_filter(['favourites', 'people', 'sounds', 'shoutbox', 'account-security', 'media-editor', 'profile-bio'],
          fn($s) => strpos($layout, 'assets/js/' . $s . '.js') < $pa));

/* ══ 4. every page type, rendered ═════════════════════════════════════════ */
$site = rtrim(getenv('VERIFY_BASE') ?: 'http://127.0.0.1:8089/', '/') . '/';
$probe = @file_get_contents($site . '?action=tos', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
if ($probe === false || !function_exists('curl_init')) {
    skip('the rendered pages', 'the local site does not answer at ' . $site . ' (or no curl)');
    echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
    exit($fails > 0 ? 1 : 0);
}
$db = getDb();
$cfg = getSettings($db);
// Every settings row this part changes, as it was — value AND time — and put back so on the way out.
// `users_enabled` too: the member half signs in over HTTP, and in the battery the tests before this one leave
// accounts switched off — a test sets every switch it depends on and puts it back, never inherits one.
// `index_enabled` and `profiles_enabled` too (1.72.0): the search page prints #search-csrf only with the catalogue's
// search on, and a profile its #account-csrf only with profiles on. Until 1.72.0 the battery happened to leave both
// on before this test; once the tests before it cleaned up after themselves, the check read "no ids" on those pages.
$keep = ['shout_enabled', 'shout_placement', 'users_enabled', 'index_enabled', 'index_search_enabled', 'profiles_enabled'];
$was = [];
foreach ($keep as $k) {
    $st = $db->prepare("SELECT `value`, updated_at FROM settings WHERE `key` = ?");
    $st->execute([$k]);
    $was[$k] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
// The sign-in below spends a hit of api/user_login.php's own limit — rateLimitAllow('user_login', <address bucket>,
// rate_limit_user_login = 10, 900 s), a map in a FILE (config/rate_limits.json) that no table snapshot covers, and
// it counts every sign-in, the good ones too. In the battery the smokes sign in more than ten times from this
// machine a few minutes earlier, so inheriting the file meant "Too many login attempts" instead of a sign-in
// (1.72.0's first battery) — four checks reporting a broken token for a reason that had nothing to do with tokens.
// The `user_login|…` hits are taken out just before the sign-in, under the lock the site itself uses, and the whole
// file is put back as it was on the way out.
$throttleFile = $root . '/config/rate_limits.json';
$throttleWas = is_file($throttleFile) ? (string)file_get_contents($throttleFile) : null;
$throttleClearHere = function () use ($throttleFile): void {
    $h = @fopen($throttleFile . '.lock', 'c');
    if ($h) @flock($h, LOCK_EX);
    try {
        if (!is_file($throttleFile)) return;
        $d = json_decode((string)file_get_contents($throttleFile), true) ?: [];
        foreach (array_keys($d) as $k) if (str_starts_with((string)$k, 'user_login|')) unset($d[$k]);
        file_put_contents($throttleFile, json_encode($d));
    } finally {
        if ($h) { @flock($h, LOCK_UN); @fclose($h); }
    }
};
$tmp = [];
$cleanup = function () use ($db, $was, &$tmp, $throttleFile, $throttleWas): void {
    foreach ($db->query("SELECT id FROM users WHERE username LIKE 'csrft\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $id) userDeleteCascade($db, (int)$id);
    foreach ($was as $k => $row) {
        if ($row === null) $db->prepare("DELETE FROM settings WHERE `key` = ?")->execute([$k]);
        else $db->prepare("INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)")
                ->execute([$k, $row['value'], $row['updated_at']]);
    }
    foreach ($tmp as $f) @unlink($f);
    if ($throttleWas === null) @unlink($throttleFile); else @file_put_contents($throttleFile, $throttleWas);
};
$cleanup();
register_shutdown_function($cleanup);

/** A browser with its own cookie jar: GET a page (redirects followed) or POST JSON to an endpoint. */
$browser = function () use ($site, &$tmp): array {
    $jar = tempnam(sys_get_temp_dir(), 'csrft');
    $tmp[] = $jar;
    $req = function (string $path, ?array $json = null) use ($site, $jar): array {
        $c = curl_init($site . $path);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 20,
                               CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_HTTPHEADER => ['Accept-Language: en']]);
        if ($json !== null) {
            curl_setopt($c, CURLOPT_POST, true);
            curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($json));
            curl_setopt($c, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        }
        $body = (string)curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $url = (string)curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
        curl_close($c);
        return ['code' => $code, 'body' => $body, 'url' => $url];
    };
    return ['get' => fn(string $p) => $req($p), 'post' => fn(string $endpoint, array $body) => $req('api.php?endpoint=' . $endpoint, $body)];
};
/** What a page publishes: its metas (and where), and every token field it still carries by id. */
$read = function (string $html): array {
    $head = (string)strstr($html, '</head>', true);
    preg_match_all('/<meta name="csrf-token" content="([^"]*)">/', $html, $all);
    preg_match_all('/<meta name="csrf-token" content="([^"]*)">/', $head, $inHead);
    preg_match_all('/<input type="hidden" id="([a-z]+-csrf)" value="([^"]*)">/', $html, $ids, PREG_SET_ORDER);
    return ['metas' => $all[1], 'head' => $inHead[1], 'ids' => array_column($ids, 2, 1),
            'layout' => str_contains($html, 'assets/js/app.js'), 'title' => preg_match('/<title>([^<]*)<\/title>/', $html, $t) ? trim(html_entity_decode($t[1])) : ''];
};
$okPage = function (array $p, string $token): bool {
    return count($p['metas']) === 1 && count($p['head']) === 1 && $p['metas'][0] === $token && preg_match('/^[0-9a-f]{64}$/', $token) === 1
        && !array_filter($p['ids'], fn($v) => $v !== $token);
};

// ── a guest: every page type the router has ──
$g = $browser();
$first = $read(($g['get'])('?action=home&lang=en')['body']);
$tok = $first['metas'][0] ?? '';
$guestPages = ['home', 'search', 'login', 'register', 'reset', 'report', 'status', 'whitelist', 'info', 'tos', 'transparency', 'stats',
               'shoutbox', 'emotes', 'unsubscribe', 'verify', 'emailchange', 'apidocs', 'account', 'members', 'u&name=smokeuser', 'no-such-page', 'admin'];
$seen = [];
$wrong = [];
foreach ($guestPages as $a) {
    $r = ($g['get'])('?action=' . $a . '&lang=en');
    $p = $read($r['body']);
    $seen[$a] = $p;
    if (!$p['layout'] || !$okPage($p, $tok)) $wrong[$a] = ['code' => $r['code'], 'url' => $r['url'], 'metas' => count($p['metas']), 'head' => count($p['head']), 'ids' => $p['ids'], 'same' => ($p['metas'][0] ?? '') === $tok];
}
check('a guest: every page type (' . count($guestPages) . ' addresses, redirects followed) carries exactly one csrf-token meta, in <head>, with this session\'s token',
      $tok !== '' && !$wrong, json_encode($wrong));
check('… the front page too, which carried no token at all before', ($seen['home']['metas'][0] ?? '') === $tok && $first['ids'] === []);
check('… and where a page still carries an id of its own (the sign-in forms\' field aside), it holds the same token', ($seen['search']['ids']['search-csrf'] ?? $tok) === $tok);
$g2 = $browser();
$tok2 = $read(($g2['get'])('?action=home&lang=en')['body'])['metas'][0] ?? '';
check('another browser (another session) is published another token', $tok2 !== '' && $tok2 !== $tok);

// ── a member, signed in ──
$db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('users_enabled', '1'), ('index_enabled', '1'), ('index_search_enabled', '1'), ('profiles_enabled', '1')
               ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute();
$cfgOn = array_merge($cfg, ['users_enabled' => '1', 'users_require_email_verify' => '0']);
$made = userCreate($db, $cfgOn, 'csrft_member', 'csrft_member@example.org', 'CsrfPass123!', '127.0.0.1');
$uid = (int)($made['user']['id'] ?? 0);
$db->prepare("UPDATE users SET email_verified = 1, status = 'active' WHERE id = ?")->execute([$uid]);
$m = $browser();
$throttleClearHere();
$loginPage = $read(($m['get'])('?action=login&lang=en')['body']);
$lt = $loginPage['metas'][0] ?? '';
$in = ($m['post'])('user_login', ['csrf_token' => $lt, 'login' => 'csrft_member', 'password' => 'CsrfPass123!', 'session' => '1h']);
$inJ = json_decode($in['body'], true) ?: [];
check('the member signs in, with the token the sign-in page\'s meta published', $uid > 0 && !empty($inJ['success']), $in['body']);
// The front page with the shoutbox on it: a widget that carries a token of its own.
$db->prepare("INSERT INTO settings (`key`, `value`) VALUES ('shout_enabled', '1'), ('shout_placement', 'home') ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute();
$memberPages = ['search' => 'search-csrf', 'u&name=csrft_member' => 'account-csrf', 'u&name=smokeuser' => 'account-csrf', 'account' => 'account-csrf', 'home' => 'shout-csrf'];
$mt = null; $wrongM = [];
foreach ($memberPages as $a => $id) {
    $r = ($m['get'])('?action=' . $a . '&lang=en');
    $p = $read($r['body']);
    $mt = $mt ?? ($p['metas'][0] ?? '');
    if (!$okPage($p, $mt) || !isset($p['ids'][$id]) || str_contains($r['url'], 'action=login')) $wrongM[$a] = ['code' => $r['code'], 'url' => $r['url'], 'metas' => count($p['metas']), 'ids' => $p['ids']];
}
check('signed in: the search page, one\'s own profile, somebody else\'s, the account page, the front page with the shoutbox — one meta each, the session\'s token, and every old id on them (#search-csrf, #account-csrf, #shout-csrf) the same value',
      $mt !== null && $mt !== '' && !$wrongM, json_encode($wrongM));
$okPost = ($m['post'])('user_notifications', ['csrf_token' => $mt, 'all' => 1]);
$badPost = ($m['post'])('user_notifications', ['csrf_token' => $tok, 'all' => 1]);   // a real token — another session's
$noPost = ($m['post'])('user_notifications', ['all' => 1]);
$okJ = json_decode($okPost['body'], true) ?: []; $badJ = json_decode($badPost['body'], true) ?: []; $noJ = json_decode($noPost['body'], true) ?: [];
check('the server takes the token the page publishes', !empty($okJ['success']), $okPost['body']);
check('… and refuses another session\'s token and none at all: 403, "Invalid CSRF token"',
      $badPost['code'] === 403 && ($badJ['error'] ?? '') === __('api.csrf.invalid') && $noPost['code'] === 403 && ($noJ['error'] ?? '') === __('api.csrf.invalid'),
      json_encode([$badPost['code'], $badJ, $noPost['code'], $noJ]));

echo "\n$n checks, $fails failed" . ($skips ? ", $skips skipped" : '') . "\n";
exit($fails > 0 ? 1 : 0);
