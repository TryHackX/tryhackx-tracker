<?php
/**
 * Where browsers post Content-Security-Policy violations. `report-uri` names this file.
 *
 * A PUBLIC, UNAUTHENTICATED WRITE into a MariaDB shared with a mail server, a forum and a file
 * host, reachable by every browser that loads any page of this site — including one being told
 * what to send by somebody who read this source. That sentence is the whole design brief, and it
 * is why this is a TOP-LEVEL FILE rather than an endpoint behind api.php:
 *
 *   * api.php starts a session, loads thirty includes, runs langInit() and the report/appeal/mail
 *     janitors, and takes the session lock. None of that is needed to store six short strings, and
 *     all of it would run on every violation from every visitor. This file includes three things.
 *   * It must NEVER fall through to index.php. `report-uri` pointing at a path that does not
 *     resolve is worse than no reporting at all: .htaccess routes an unmatched path to
 *     `index.php?action=…`, index.php sanitises an unknown action to 'home' and renders the ENTIRE
 *     FRONT PAGE — session, schema check, four janitors, layout — for what was meant to be a 204.
 *     So the file has to exist on the server, which means deploy/deploy.py's top-level include list
 *     has to name it (it does now: "csp-report.php", added in the same commit as this file).
 *
 * Everything cheap is checked BEFORE the database is touched — method, content type, length — so a
 * flood of junk costs a TCP connection and nothing else. The answer is always 204 with no body: a
 * browser ignores it, and there is nothing here worth telling a prober.
 */

// Fail closed rather than helpfully. A GET here is a person or a scanner, never a browser
// delivering a report; answering 405 says the path exists and does one thing.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

/** 204 and stop. Used for every outcome, including every refusal. */
function cspReportDone(): void {
    http_response_code(204);
    exit;
}

// Firefox sends application/csp-report, Chrome sends application/reports+json, and a hand-rolled
// test sends application/json. Anything else — a form post, a file upload — is not a report and is
// refused before a byte of it is read.
$ctype = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if (!in_array($ctype, ['application/csp-report', 'application/reports+json', 'application/json'], true)) {
    cspReportDone();
}

// The advertised length first (free), then the actual read capped one byte over the limit, because
// CONTENT_LENGTH is whatever the client typed. A real report is a few hundred bytes.
require_once __DIR__ . '/includes/csp.php';
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > CSP_REPORT_MAX_BYTES) cspReportDone();
$raw = (string)file_get_contents('php://input', false, null, 0, CSP_REPORT_MAX_BYTES + 1);
if ($raw === '' || strlen($raw) > CSP_REPORT_MAX_BYTES) cspReportDone();

$body = json_decode($raw, true);
if (!is_array($body)) cspReportDone();
$rep = cspReportNormalise($body);
if ($rep === null) cspReportDone();   // extension noise, or nothing worth a row

// Is this even about a page on this site? A report whose document-uri belongs to somebody else is
// either a mistake or an honest misdirection — a page on another host that names this one, or a stale report-uri left in a browser. It is NOT a boundary: Host arrives from the client, so anyone willing to send both fields can pass it. What actually bounds the table is the row cap and the janitor prune.
if (!cspDocIsOurs($rep['doc'], (string)($_SERVER['HTTP_HOST'] ?? ''))) cspReportDone();

// Only now does anything cost a database connection.
if (!file_exists(__DIR__ . '/config/installed.lock')) cspReportDone();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/settings.php';

try {
    $db  = getDb();
    $cfg = getSettings($db);
    // The switch is read here as well as in cspPolicy(): the header stops carrying report-uri the
    // moment collection is turned off, but a browser that already has the page open keeps posting
    // to the URL it was given, and a crawler keeps whatever it found in a log.
    if (!cspReportingOn($cfg)) cspReportDone();
    // ensureSchema() is deliberately NOT called — it takes an advisory lock and can run ALTERs, and
    // this request must never be the one paying for a migration. Until the first web request after
    // a deploy has run the schema, `csp_reports` may not exist and the catch below drops the report.
    // Reports are lost in that window; that is the correct trade for not letting an anonymous POST
    // start a schema run.
    cspReportStore($db, $cfg, $rep, ($_GET['s'] ?? '') === 'panel' ? 'panel' : 'public');
} catch (\Throwable $e) {
    // Never a 500: an error page here would tell a prober that the table is missing, and a browser
    // would keep retrying against it. One log line, and the same 204 as every other outcome.
    error_log('[tracker] csp-report: ' . $e->getMessage());
}
cspReportDone();
