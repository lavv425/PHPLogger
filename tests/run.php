<?php

declare(strict_types=1);

/**
 * Test runner. Usage: php tests/run.php [filter]
 *
 * Exits with 1 on the first failing suite so CI can use it directly.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/TestCase.php';
require __DIR__ . '/Support/Harness.php';
require __DIR__ . '/Support/FailingHandler.php';

use PhpLoggerTests\AssertionFailed;
use PhpLoggerTests\TestCase;

$filter = $argv[1] ?? null;
$files = glob(__DIR__ . '/Unit/*Test.php') ?: [];
sort($files);

$passed = 0;
$failed = 0;
$assertions = 0;
$failures = [];

foreach ($files as $file) {
    $declaredBefore = get_declared_classes();
    require $file;
    $declared = array_diff(get_declared_classes(), $declaredBefore);

    foreach ($declared as $class) {
        if (!is_subclass_of($class, TestCase::class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (strncmp($method->getName(), 'test', 4) !== 0) {
                continue;
            }

            $label = $reflection->getShortName() . '::' . $method->getName();

            if ($filter !== null && stripos($label, $filter) === false) {
                continue;
            }

            /** @var TestCase $instance */
            $instance = $reflection->newInstance();

            try {
                $method->invoke($instance);
                $assertions += $instance->assertionCount();
                ++$passed;
                echo '.';
            } catch (AssertionFailed $failure) {
                $assertions += $instance->assertionCount();
                ++$failed;
                $failures[] = $label . "\n  " . $failure->getMessage();
                echo 'F';
            } catch (Throwable $error) {
                $assertions += $instance->assertionCount();
                ++$failed;
                $failures[] = sprintf(
                    "%s\n  unexpected %s: %s\n  at %s:%d",
                    $label,
                    get_class($error),
                    $error->getMessage(),
                    $error->getFile(),
                    $error->getLine()
                );
                echo 'E';
            }
        }
    }
}

echo "\n\n";

foreach ($failures as $index => $failure) {
    echo sprintf("%d) %s\n\n", $index + 1, $failure);
}

echo sprintf(
    "%s: %d passed, %d failed, %d assertions\n",
    $failed === 0 ? 'OK' : 'FAILED',
    $passed,
    $failed,
    $assertions
);

exit($failed === 0 ? 0 : 1);
