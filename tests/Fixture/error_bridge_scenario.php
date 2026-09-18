<?php

declare(strict_types=1);

/**
 * Run in a child process by ErrorHandlerBridgeProcessTest.
 *
 * A fatal error, an out-of-memory and an uncaught exception all end the request
 * for good: the only honest way to check that the bridge still manages to write
 * its record is to let a real process die.
 *
 * Usage: php error_bridge_scenario.php <scenario> <target-file>
 */

require __DIR__ . '/../../autoload.php';

use Logger\Adapter\Error\ErrorHandlerBridge;
use Logger\LoggerFactory;

$scenario = $argv[1] ?? '';
$target = $argv[2] ?? '';

$logger = LoggerFactory::fromArray([
    'service' => 'svc',
    'env' => 'testing',
    'host' => 'test-host',
    'default_channel' => 'main',
    'channels' => ['main' => ['handlers' => [['type' => 'stream', 'target' => $target]]]],
]);

(new ErrorHandlerBridge($logger))->register();

switch ($scenario) {
    case 'fatal':
        /** @phpstan-ignore-next-line the undefined call is the point */
        questa_funzione_non_esiste();
        break;

    case 'oom':
        $blob = '';
        while (true) {
            $blob .= str_repeat('x', 1024 * 1024);
        }
        break;

    case 'exception':
        throw new RuntimeException('esplosione non catturata');

    case 'user_error':
        // Reaches the error handler AND ends the request, so it is visible to
        // both hooks: the bridge has to report it once, not twice.
        trigger_error('errore utente fatale', E_USER_ERROR);
        break;

    case 'notice':
        $empty = [];
        $value = $empty['mancante'];
        echo "sopravvissuto\n";
        break;

    default:
        fwrite(STDERR, "scenario sconosciuto\n");
        exit(2);
}
