<?php
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // polled: never hold the session lock across the read
// A count that failed is "we could not ask", not an empty index (1.74.0, QUAL-26): 503, and the card keeps its last
// good numbers (assets/js/admin-index.js markStale()). indexStatus() logged the reason.
try { $status = indexStatus($db, $cfg); }
catch (\Throwable $e) { jsonResponse(['error' => __('api.db_unavailable')], 503); }
jsonResponse($status);
