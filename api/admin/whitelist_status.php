<?php
// Status card for the admin whitelist page (polled every ~30 s — the router skips the heavy janitors here).
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // polled: never hold the session lock across the read
$status = whitelistStatus($db, $cfg);
$status['server_time'] = time();

jsonResponse($status);
