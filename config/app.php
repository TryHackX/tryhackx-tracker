<?php
/**
 * Minimal bootstrap config.
 * All dynamic settings live in the database `settings` table.
 * This file only loads the password hash from file (avoids $ interpolation issues in PHP).
 */

define('ADMIN_PASSWORD_HASH', file_exists(__DIR__ . '/hash.txt') ? trim(file_get_contents(__DIR__ . '/hash.txt')) : '');
