<?php
/**
 * Two-factor authentication from the shell:  php tools/twofa_cli.php status|off
 *
 * The escape hatch. An administrator who has lost their authenticator app and used up their recovery
 * codes can still reach this machine over SSH -- so the way back exists here, where reaching it
 * already proves more than a six-digit code ever could.
 *
 * Deliberately only reads and turns OFF. Turning it ON from a shell would mean printing a secret into
 * a terminal and a shell history, and the panel does that job with a confirmation step this cannot
 * have.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/app.php';
require_once $root . '/includes/twofa.php';

$op = $argv[1] ?? 'status';
switch ($op) {
    case 'status':
        $s = twofaState();
        echo 'two-factor authentication: ', twofaEnabled() ? "ON\n" : "off\n";
        if (twofaEnabled()) {
            echo '  confirmed at : ', $s['confirmed_at'] ? date('Y-m-d H:i:s', (int)$s['confirmed_at']) : '?', "\n";
            echo '  recovery left: ', twofaRecoveryLeft(), "\n";
        }
        echo '  state file   : ', twofaFile(), is_file(twofaFile()) ? "\n" : " (absent)\n";
        exit(0);
    case 'off':
        if (!twofaEnabled()) { echo "It is already off.\n"; exit(0); }
        if (!twofaDisable()) { fwrite(STDERR, "Could not write " . twofaFile() . "\n"); exit(1); }
        echo "Two-factor authentication is off. The secret and every recovery code are gone.\n";
        echo "The settings row is corrected on the next panel request.\n";
        exit(0);
    default:
        fwrite(STDERR, "usage: php tools/twofa_cli.php status|off\n");
        exit(2);
}
