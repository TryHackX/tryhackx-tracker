<?php
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // polled: never hold the session lock across the read
jsonResponse(indexStatus($db, $cfg));
