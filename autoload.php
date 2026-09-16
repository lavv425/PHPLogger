<?php

declare(strict_types=1);

/**
 * PSR-4 style autoloader for environments without Composer.
 *
 * Change NAMESPACE_PREFIX to the company namespace; the directory layout
 * already matches PSR-4, so a future Composer setup needs no code change.
 */

const PHP_LOGGER_NAMESPACE_PREFIX = 'Logger\\';

spl_autoload_register(static function (string $class): void {
    $prefix = PHP_LOGGER_NAMESPACE_PREFIX;
    $prefixLength = strlen($prefix);

    if (strncmp($class, $prefix, $prefixLength) !== 0) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, $prefixLength));
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});
