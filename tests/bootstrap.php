<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Composer is dev-only tooling here: it brings in PHPUnit and autoloads the
 * Logger\Tests namespace, but the library itself is loaded through the
 * autoloader that actually ships with the package. That way every test runs
 * against the same loading path a consumer gets, and autoload.php cannot rot.
 */

$composerAutoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($composerAutoload)) {
    fwrite(STDERR, "Dependencies are missing. Run \"composer install\" first.\n");
    exit(1);
}

require $composerAutoload;
require __DIR__ . '/../autoload.php';
