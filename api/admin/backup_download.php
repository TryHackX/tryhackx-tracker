<?php
/**
 * GET — stream one archive to the browser.
 *
 * Auth is enforced by the router (admin/*), so this is never reachable without an admin session.
 * On top of that the link carries a token minted by admin/backup_action (which asks for the admin
 * password): bound to this one archive, valid for BACKUP_TOKEN_TTL seconds and burned on first use,
 * so a URL that ends up in a proxy log or a browser history cannot be replayed.
 *
 * The archives are 0600 root inside a 0700 root directory — the web user cannot read them at all.
 * The bytes come out of the root helper's stdout and are copied to the client in chunks, so the
 * memory cost does not depend on the size of the archive.
 *
 * Query: ?endpoint=admin/backup_download&id=<archive id>&token=<token>
 */

$id    = trim((string)($_GET['id'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

$deny = function (string $msg, int $code = 403) {
    // No JSON here: the browser navigated to this URL, so a plain sentence is what a person sees.
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $msg . "\n";
    exit;
};

if (!backupValidId($id)) $deny('That is not an archive this panel made.', 400);

$secret = (string)($cfg['hmac_secret'] ?? '');
if ($secret === '') $deny('The site has no HMAC secret configured, so download links cannot be verified.', 500);
if (!backupVerifyToken($token, $id, $secret)) {
    $deny('This download link is not valid any more. Links last ' . BACKUP_TOKEN_TTL . ' seconds and work once — ask for a new one on the Backups page.', 410);
}
if (!backupBurnToken($token)) {
    $deny('This download link has already been used. Ask for a new one on the Backups page.', 410);
}

if (backupCommand($cfg) === '' || !trackerExecAvailable()) {
    $deny('The backup helper is not available on this server.', 500);
}

// Find the archive so the browser gets the real name and, when we know it, the real size.
$file = $id . '.tar.gz';
$size = 0;
$list = backupList($cfg);
foreach ((array)($list['archives'] ?? []) as $a) {
    if (($a['id'] ?? '') === $id) { $file = (string)($a['file'] ?? $file); $size = (int)($a['size'] ?? 0); break; }
}

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $file) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
if ($size > 0) header('Content-Length: ' . $size);
@set_time_limit(0);

if (!backupStream($cfg, $id)) {
    // Nothing has been written yet at this point, so a plain message is still possible.
    $deny('Could not read the archive from the server.', 500);
}
exit;
