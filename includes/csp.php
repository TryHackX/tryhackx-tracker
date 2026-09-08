<?php
/**
 * Content-Security-Policy: one policy, built here, sent per request, carrying a per-request nonce.
 *
 * WHY THE POLICY MOVED INTO PHP AT ALL
 * ------------------------------------
 * `.htaccess` has shipped a CSP since the beginning and it is still there — but it is Apache-only
 * and PRODUCTION IS NGINX, which never reads that file. Unless the operator hand-copied the line
 * into their server block (README says to; it is a manual step nobody is reminded of), the site has
 * been serving no policy at all. Moving it into the application fixes that on every web server at
 * once, and it is also the only place a NONCE can come from: a nonce must change per response and a
 * static `add_header` cannot mint one.
 *
 * THE TWO-HEADERS TRAP, AND WHAT WAS DONE ABOUT IT
 * ------------------------------------------------
 * A browser INTERSECTS multiple Content-Security-Policy headers — the strictest of each directive
 * wins — so a second copy from the web server does not replace this one, it silently narrows it.
 * Worse, mod_headers' `Header set` REPLACES a header PHP already sent, so an Apache install would
 * have quietly served the old permissive policy and this whole file would have been decorative.
 * `.htaccess` therefore says `Header setifempty` now (Apache 2.4.7+): PHP's header wins whenever
 * PHP sends one, and the Apache copy remains as the fallback for csp_mode='off'. In report-only
 * mode the two headers have DIFFERENT NAMES, so an Apache install keeps its enforcing policy
 * throughout the report-only phase and loses no protection on the upgrade. nginx has no such
 * fallback and needs none: before this change it had nothing.
 *
 * WHAT THIS POLICY HONESTLY CLAIMS
 * --------------------------------
 *  * script-src has NO 'unsafe-inline' and NO 'unsafe-eval'. Every inline block carries the nonce
 *    (nonceAttr(), below) and there is not one eval()/new Function() in assets/js or in the
 *    vendored uPlot. That part is real.
 *  * style-src KEEPS 'unsafe-inline' and will keep it until includes/richtext.php stops building
 *    style="color:…" / style="font-size:…" from author BBCode (richtext.php:442-458) and the ~104
 *    style="" attributes in templates/ move into stylesheets. The panel's page-content preview is a
 *    second reason: assets/js/admin-pagecontent.js fills an iframe through srcdoc, and an
 *    about:srcdoc document INHERITS this policy while carrying its own <style> block. Anybody
 *    reading the shipped header should not come away believing style injection is blocked here.
 *  * img-src keeps `https:` for the reason .htaccess spells out: images in descriptions. Tightening
 *    it belongs with desc_max_images, not with this change.
 *
 * A NONCE DOES NOT RESCUE on*= ATTRIBUTES. `onclick="…"` is blocked by script-src whenever
 * 'unsafe-inline' is absent, nonce or no nonce. The twenty that existed in shipped code were
 * removed in the same release (delegated listeners in assets/js/app.js and assets/js/admin.js);
 * anything new must be delegated too, or an enforcing policy will simply not run it.
 *
 * This file is loaded from includes/functions.php (so nonceAttr() exists everywhere the page does,
 * including in captchaHeadTags() and pageRedirect()), and cspPolicy() calls captchaCspHosts() from
 * there. The REPORT half below touches nothing outside this file, which is what lets the public
 * endpoint csp-report.php include only config/database.php + includes/settings.php + this.
 */

/** off = send nothing; report = Content-Security-Policy-Report-Only; enforce = the real header. */
const CSP_MODES = ['off', 'report', 'enforce'];

/** Bodies larger than this are refused before they are read. A real report is a few hundred bytes. */
const CSP_REPORT_MAX_BYTES = 8192;

/** Ceiling and default for csp_report_keep_rows (rows are aggregated, so this bounds KINDS). */
const CSP_ROWS_MAX     = 5000;
const CSP_ROWS_DEFAULT = 500;

/** At most this many operator-supplied hosts reach the header. */
const CSP_EXTRA_HOSTS_MAX = 10;

/**
 * The policy for templates/maintenance.php, which is rendered at index.php:63 when getDb() throws —
 * BEFORE $cfg exists. Calling cspPolicy() there would be a fatal on top of a database outage, on
 * the one page whose entire job is to still work. The template is one <style> block and no script.
 */
const CSP_MAINTENANCE = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

/**
 * ONE value per request, minted lazily.
 *
 * 128 bits, base64url without padding (22 characters), which is what the CSP grammar accepts inside
 * 'nonce-…' without quoting surprises. Static, so the header and every <script> on the page agree —
 * a nonce that differed between the two would block every inline block on the site.
 */
function cspNonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
    return $nonce;
}

/**
 * ` nonce="…"`, ready to paste directly after `<script`.
 *
 * The attribute rather than the bare value on purpose: `<script<?= nonceAttr() ?>>` is one token to
 * add and impossible to half-do. tests/csp_headers_test.py then asks the SERVER for nineteen routes
 * and fails when a returned page holds a `<script` with neither a src= nor the nonce out of that
 * same response's own header — so "nothing can forget it" is a property of the rendered page rather
 * than of a source grep, which is what let the handlers in assets/js/ hide from the older scan.
 */
function nonceAttr(): string {
    return ' nonce="' . cspNonce() . '"';
}

/** 'off' | 'report' | 'enforce'; anything unknown reads as 'report', which cannot break a page. */
function cspMode(array $cfg): string {
    $m = strtolower(trim((string)($cfg['csp_mode'] ?? 'report')));
    return in_array($m, CSP_MODES, true) ? $m : 'report';
}

/** Header name for the current mode. '' when the mode is 'off'. */
function cspHeaderName(array $cfg): string {
    return match (cspMode($cfg)) {
        'enforce' => 'Content-Security-Policy',
        'report'  => 'Content-Security-Policy-Report-Only',
        default   => '',
    };
}

/**
 * Collecting reports is OFF by default, and that is deliberate rather than timid.
 *
 * Report-only still REPORTS: the day a policy is switched on, every page that trips it produces an
 * unauthenticated POST into a MariaDB shared with a mail server, a forum and a file host, on a pool
 * of five php-fpm children. Browser extensions alone generate most of the CSP noise on the web and
 * none of it is about this site. So the switch exists, the operator turns it on for a day when they
 * want evidence, and the default costs nothing.
 */
function cspReportingOn(array $cfg): bool {
    return cspMode($cfg) !== 'off' && (string)($cfg['csp_report_enabled'] ?? '0') === '1';
}

function cspReportKeepRows(array $cfg): int {
    $n = (int)($cfg['csp_report_keep_rows'] ?? CSP_ROWS_DEFAULT);
    return max(0, min(CSP_ROWS_MAX, $n));
}

/**
 * Is this a host expression this site is willing to put into a response header?
 *
 * The whole security boundary of csp_extra_hosts is this function, because the value goes verbatim
 * into header(). It is called BOTH by api/admin/save_settings.php (so a bad entry is refused at the
 * door) and by cspPolicy() (so a row written by any other path — the settings table lives in a
 * database three other applications can reach — cannot inject a directive or a header break).
 *
 * Allowed: an optional http:// or https:// scheme, an optional single leading `*.` wildcard label,
 * a dotted hostname, an optional port. Nothing else. In particular a bare `*`, a `*` in the middle
 * of a label, a path, a quote, a space, a semicolon, a control character and every CSP keyword
 * ('unsafe-inline', 'unsafe-eval', data:, blob:) all fail — which is the point: those are the
 * shapes that would turn "one more CDN" into "the policy no longer says anything".
 */
function cspHostOk(string $host): bool {
    if ($host === '' || strlen($host) > 128) return false;
    // \z, not $: PCRE's $ matches before a trailing newline, so `example.com\n` passed this and
    // the value goes verbatim into a response header. Both callers trim() today; the boundary does
    // not get to depend on its callers being careful.
    return (bool)preg_match(
        '#^(https?://)?(\*\.)?[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+(:\d{1,5})?\z#',
        $host
    );
}

/** The operator's extra hosts, parsed and re-validated. Never more than CSP_EXTRA_HOSTS_MAX. */
function cspExtraHosts(array $cfg): array {
    $raw = trim((string)($cfg['csp_extra_hosts'] ?? ''));
    if ($raw === '') return [];
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $h) {
        $h = trim($h);
        if ($h !== '' && cspHostOk($h) && !in_array($h, $out, true)) $out[] = $h;
        if (count($out) >= CSP_EXTRA_HOSTS_MAX) break;
    }
    return $out;
}

/**
 * The entries in csp_extra_hosts that cspExtraHosts() is THROWING AWAY, so the page can say so.
 *
 * The same lesson as trustedProxyRejected() in includes/functions.php: a value that is silently
 * dropped on read looks exactly like a value that is working. The save endpoint refuses a bad host
 * at the door, so the only way to get one in here is a row written by something other than this
 * panel — the settings table lives in a MariaDB three other applications can reach — or an entry
 * past the CSP_EXTRA_HOSTS_MAX-th, which is dropped for being over the limit and not for being bad.
 */
function cspExtraHostsRejected(array $cfg): array {
    $raw = trim((string)($cfg['csp_extra_hosts'] ?? ''));
    if ($raw === '') return [];
    $kept = cspExtraHosts($cfg);
    $bad  = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $h) {
        $h = trim($h);
        if ($h === '' || in_array($h, $kept, true) || in_array($h, $bad, true)) continue;
        $bad[] = $h;
    }
    return $bad;
}

/**
 * Where violation reports go. A PATH, not an absolute URL — report-uri resolves relative to the
 * document, and getBaseUrl() (includes/functions.php) returns '/' or '/tracker/'.
 *
 * Only `report-uri` is sent, and not Chrome's newer `Reporting-Endpoints` + `report-to` pair: that
 * header requires an ABSOLUTE url, which this application cannot build correctly behind a proxy
 * without trusting a Host header, and a named endpoint group that fails to resolve delivers nothing
 * while looking like it works. report-uri is deprecated in Chrome and still implemented in every
 * engine, including Firefox, which never implemented the other one. Saying that plainly beats
 * shipping a header that quietly reports to nowhere.
 */
function cspReportUri(string $scope): string {
    $base = function_exists('getBaseUrl') ? getBaseUrl() : '/';
    return $base . 'csp-report.php?s=' . ($scope === 'panel' ? 'panel' : 'public');
}

/**
 * The policy string for one scope: 'public', 'panel' or 'api'.
 *
 * PUBLIC and PANEL differ in exactly three places and each difference is paid for:
 *   * script-src gains https://cdn.jsdelivr.net in the panel only — every panel page loads the
 *     Bootstrap 5.3.8 bundle from there (with SRI). A public page loads Bootstrap ICONS (CSS) from
 *     jsDelivr on two actions, which is style-src and font-src, not script-src. Allowing a CDN to
 *     run scripts on the pages every anonymous visitor sees, when nothing there needs it, would be
 *     paying the whole cost of a third-party script origin for nothing.
 *   * frame-ancestors is 'none' in the panel and 'self' on public pages. The panel has no reason to
 *     be framed by anything, ever.
 *   * frame-src gains 'self' in the panel for the page-content preview iframe.
 * Returns '' when the mode is 'off'.
 */
function cspPolicy(array $cfg, string $scope): string {
    if (cspMode($cfg) === 'off') return '';

    // A JSON body has no subresources at all, so the API gets the strictest policy there is and it
    // costs nothing. It also means an endpoint that ever starts returning HTML is loudly broken
    // rather than quietly exploitable.
    if ($scope === 'api') {
        return "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
    }

    $panel = ($scope === 'panel');
    $cap   = captchaCspHosts($cfg);
    $extra = cspExtraHosts($cfg);

    $script  = ["'self'", "'nonce-" . cspNonce() . "'"];
    $style   = ["'self'", "'unsafe-inline'", 'https://cdn.jsdelivr.net'];
    $connect = ["'self'"];
    $frame   = [];
    $font    = ["'self'", 'data:', 'https://cdn.jsdelivr.net'];

    if ($panel) $script[] = 'https://cdn.jsdelivr.net';
    if ($panel) $frame[]  = "'self'";

    foreach ($cap['script']  as $h) $script[]  = $h;
    foreach ($cap['style']   as $h) $style[]   = $h;
    foreach ($cap['connect'] as $h) $connect[] = $h;
    foreach ($cap['frame']   as $h) $frame[]   = $h;

    // The operator's own hosts go into the four fetch directives a third-party widget needs. Not
    // into img-src (already `https:`) and not into font-src (a font is fetched by a stylesheet from
    // the origin that served it, and that origin is in style-src).
    foreach ($extra as $h) { $script[] = $h; $style[] = $h; $connect[] = $h; $frame[] = $h; }

    // An empty source list is legal and means "block everything", but it prints as `frame-src ;`
    // and reads like a bug. Say 'none' out loud.
    if (!$frame) $frame[] = "'none'";

    $parts = [
        "default-src 'self'",
        'script-src ' . implode(' ', array_unique($script)),
        'style-src ' . implode(' ', array_unique($style)),
        "img-src 'self' data: https:",
        'font-src ' . implode(' ', array_unique($font)),
        'connect-src ' . implode(' ', array_unique($connect)),
        'frame-src ' . implode(' ', array_unique($frame)),
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        'frame-ancestors ' . ($panel ? "'none'" : "'self'"),
    ];
    if (cspReportingOn($cfg)) $parts[] = 'report-uri ' . cspReportUri($scope);

    // Last line of defence before header(): a CR or LF here would be a response-splitting bug, and
    // any other control character would be a policy nobody can read. Everything that can reach this
    // string has already been validated; this is the belt that survives the next editor of this file.
    return preg_replace('/[\x00-\x1F\x7F]+/', '', implode('; ', $parts));
}

/**
 * Send the policy for this scope. Safe to call twice in one request: index.php sends the public
 * policy from sendSecurityHeaders() before it knows which page it is about to render, and the panel
 * branch calls this again with 'panel' — header() replaces a same-named header, so the response
 * carries exactly one. Which is the property tests/csp_headers_test.py asserts.
 */
/**
 * The policy with this request's nonce replaced by a placeholder, for showing a human.
 *
 * The nonce is a per-request secret in every way that matters: a browser deliberately hides the
 * attribute from the DOM and from CSS attribute selectors so that an injection which can read the
 * page cannot read the nonce and mint a script tag that passes. Printing it as page text gives that
 * back — and the page where this diagnostic lives is the panel's own Settings page.
 */
function cspMaskNonce(string $policy): string {
    return (string)preg_replace("#'nonce-[A-Za-z0-9+/=_-]+'#", "'nonce-…'", $policy);
}

function cspSend(array $cfg, string $scope): void {
    if (headers_sent()) return;
    $name = cspHeaderName($cfg);
    if ($name === '') return;                 // mode 'off' sends nothing, including no report-uri
    $policy = cspPolicy($cfg, $scope);
    if ($policy === '') return;
    header($name . ': ' . $policy);
}

/* ── The report half ──────────────────────────────────────────────────────────
 *
 * Everything below is reachable from csp-report.php, which is a PUBLIC UNAUTHENTICATED WRITE into a
 * database shared with a mail server, a forum and a file host. That sentence is the whole design
 * brief. Nothing here starts a session, forks, calls a janitor, or touches rateLimitAllow()
 * (includes/functions.php:483 rewrites one JSON file under an exclusive flock — routing browser
 * traffic through that would serialise every rate-limited action on the site).
 */

/**
 * Normalise either report shape to ['directive' => …, 'blocked' => …, 'doc' => …], or null.
 *
 * Two shapes because two browsers: Firefox posts `{"csp-report": {...}}` as application/csp-report,
 * Chrome posts `[{"type":"csp-violation","body":{...}}]` as application/reports+json. They MUST
 * normalise to the same triple for the same violation or the panel's count would be a lie about how
 * many distinct things are wrong — one problem seen in two browsers is one problem.
 *
 * Firefox also puts the whole directive VALUE in `violated-directive`
 * ("script-src-elem 'self' 'nonce-…'"), so the first token is taken and `effective-directive` is
 * preferred where it exists.
 */
function cspReportNormalise(array $body): ?array {
    // Chrome's Reporting API batch: take the first csp-violation in it.
    if (isset($body[0]) && is_array($body[0])) {
        foreach ($body as $item) {
            if (!is_array($item)) continue;
            $type = (string)($item['type'] ?? '');
            if ($type !== '' && $type !== 'csp-violation') continue;
            $b = $item['body'] ?? null;
            if (is_array($b)) return cspReportFields($b);
        }
        return null;
    }
    if (isset($body['csp-report']) && is_array($body['csp-report'])) return cspReportFields($body['csp-report']);
    if (isset($body['body']) && is_array($body['body']))             return cspReportFields($body['body']);
    return cspReportFields($body);
}

/** The field-name soup of both shapes, reduced to three values. */
function cspReportFields(array $r): ?array {
    $directive = (string)($r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? $r['violatedDirective'] ?? '');
    $directive = strtolower(trim(explode(' ', trim($directive))[0]));
    if ($directive === '' || !preg_match('/^[a-z-]{1,48}$/', $directive)) $directive = 'other';

    $blocked = (string)($r['blocked-uri'] ?? $r['blockedURL'] ?? $r['blockedUri'] ?? '');
    $doc     = (string)($r['document-uri'] ?? $r['documentURL'] ?? $r['documentUri'] ?? '');

    $origin = cspBlockedOrigin($blocked);
    if ($origin === null) return null;          // extension noise, or nothing worth a row

    return ['directive' => $directive, 'blocked' => $origin, 'doc' => $doc];
}

/**
 * The ORIGIN of a blocked URL, never its path or query — or null when the report is noise.
 *
 * A blocked-uri routinely carries a full URL with a token in it, and this row lands in a database
 * that three other applications can read and that every backup dumps. `https://x.example/cb?tok=…`
 * becomes `https://x.example` and nothing else is kept. The literals the spec uses instead of a URL
 * ('inline', 'eval', 'self', 'wasm-eval'…) are kept as they are, because they are the most useful
 * answers this table can hold.
 *
 * Extension schemes are dropped at the door: browser add-ons injecting their own scripts are the
 * single largest source of CSP reports on any site and not one of them is about this site.
 */
function cspBlockedOrigin(string $blocked): ?string {
    $b = trim($blocked);
    if ($b === '') return null;
    if (strlen($b) > 2048) $b = substr($b, 0, 2048);
    $lower = strtolower($b);
    foreach (['chrome-extension:', 'moz-extension:', 'safari-extension:', 'safari-web-extension:',
              'webkit-masked-url:', 'resource:', 'about:', 'data:', 'blob:', 'filesystem:'] as $bad) {
        if (str_starts_with($lower, $bad)) return null;
    }
    if (!str_contains($b, '://')) {
        // 'inline', 'eval', 'self', 'wasm-eval', 'trusted-types-sink' …
        return preg_match('/^[a-z-]{1,32}$/', $lower) ? $lower : null;
    }
    $scheme = parse_url($b, PHP_URL_SCHEME);
    $host   = parse_url($b, PHP_URL_HOST);
    $port   = parse_url($b, PHP_URL_PORT);
    if (!$scheme || !$host) return null;
    $scheme = strtolower((string)$scheme);
    if (!in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) return null;
    $origin = $scheme . '://' . strtolower((string)$host) . ($port ? ':' . (int)$port : '');
    return strlen($origin) <= 255 ? $origin : substr($origin, 0, 255);
}

/**
 * The `?action=` of the page the violation happened on, and NOTHING else from that URL.
 *
 * Same privacy rule as the blocked origin, pointed the other way: document-uri carries whatever the
 * visitor was reading, including any query they typed. 'home' is what index.php calls the front
 * page, so an absent action reads the way the router reads it.
 */
function cspDocAction(string $docUri): string {
    $q = parse_url($docUri, PHP_URL_QUERY);
    if (!$q) return 'home';
    parse_str((string)$q, $qs);
    $a = strtolower(trim((string)($qs['action'] ?? '')));
    $a = preg_replace('/[^a-z0-9_-]/', '', $a);
    if ($a === '' || $a === null) return 'home';
    return substr($a, 0, 64);
}

/** Does this report describe a page on THIS site? Reports about anything else are not ours. */
function cspDocIsOurs(string $docUri, string $host): bool {
    if ($host === '') return true;                        // cannot tell — do not throw the report away
    $h = parse_url($docUri, PHP_URL_HOST);
    if (!$h) return false;
    $host = strtolower(explode(':', $host)[0]);
    return strtolower((string)$h) === $host;
}

/**
 * One report into the table. Returns true when a row was written or a counter moved.
 *
 * THE ROW COUNT IS BOUNDED AGAINST A HOSTILE CLIENT, not merely against an honest browser.
 * blocked_origin comes out of the POST body, so N distinct origins would be N rows if aggregation
 * were the only bound — and aggregation is a bound on real traffic, not on somebody who chooses the
 * key. The UPDATE runs first and is always allowed (an existing kind of violation costs no new
 * storage, however many times it is seen); the INSERT is refused once the table already holds
 * csp_report_keep_rows rows. So the worst a flood can do is keep the counters of the rows that are
 * already there moving.
 */
function cspReportStore(PDO $db, array $cfg, array $rep, string $scope): bool {
    $keep = cspReportKeepRows($cfg);
    if ($keep <= 0) return false;
    $scope = ($scope === 'panel') ? 'panel' : 'public';
    $sig = sha1($scope . '|' . $rep['directive'] . '|' . $rep['blocked']);

    $up = $db->prepare("UPDATE csp_reports SET hits = hits + 1, last_seen = NOW() WHERE sig = ?");
    $up->execute([$sig]);
    if ($up->rowCount() > 0) return true;

    $n = (int)$db->query("SELECT COUNT(*) FROM csp_reports")->fetchColumn();
    if ($n >= $keep) return false;

    // hits = 1, not the column default of 0: the first sighting IS one occurrence, and a row that
    // reads "0 times" next to a date is a number nobody can act on.
    $in = $db->prepare("INSERT INTO csp_reports (sig, scope, directive, blocked, sample_doc, hits, first_seen, last_seen)
                        VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = NOW()");
    $in->execute([$sig, $scope, $rep['directive'], $rep['blocked'], cspDocAction($rep['doc'])]);
    return true;
}

/**
 * Trim the table to csp_report_keep_rows newest by last_seen. Called once a minute by the janitor.
 *
 * Two statements and not one: MariaDB does not accept OFFSET in DELETE, and a `DELETE … WHERE sig IN
 * (SELECT … LIMIT …)` is the shape MySQL refuses outright. Selecting the doomed signatures first is
 * exact (no tie-at-the-cutoff over-keep), cheap on a table this size, and readable.
 */
function cspPrune(PDO $db, array $cfg): int {
    try {
        $keep = cspReportKeepRows($cfg);
        if ($keep <= 0) return (int)$db->exec("DELETE FROM csp_reports");
        // LIMIT/OFFSET are interpolated because a bound parameter is a string there under emulated
        // prepares; both values are integers this function produced.
        $st = $db->query("SELECT sig FROM csp_reports ORDER BY last_seen DESC, sig ASC LIMIT 10000 OFFSET " . $keep);
        $doomed = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$doomed) return 0;
        $del = $db->prepare("DELETE FROM csp_reports WHERE sig IN (" . implode(',', array_fill(0, count($doomed), '?')) . ")");
        $del->execute($doomed);
        return $del->rowCount();
    } catch (\Throwable $e) {
        return 0;   // table not created yet (a deploy between schema runs), or the database is busy
    }
}
