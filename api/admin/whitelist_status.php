<?php
// Status card for the admin whitelist page (polled every ~30 s — the router skips the heavy janitors here).
$status = whitelistStatus($db, $cfg);
$status['server_time'] = time();

jsonResponse($status);
