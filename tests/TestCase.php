<?php

declare(strict_types=1);

namespace PhpLoggerTests;

use Throwable;

final class AssertionFailed extends \RuntimeException
{
}

/**
 * Minimal test base class.
 *
 * PHPUnit cannot be installed without Composer, so the project ships its own
 * runner. Assertions throw; the runner turns them into a report.
 */
abstract class TestCase
{
    private int $assertions = 0;

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    /** @param mixed $condition */
    protected function assertTrue($condition, string $message = ''): void
    {
        ++$this->assertions;

        if ($condition !== true) {
            throw new AssertionFailed($message !== '' ? $message : 'expected true, got ' . self::export($condition));
        }
    }

    /** @param mixed $condition */
    protected function assertFalse($condition, string $message = ''): void
    {
        ++$this->assertions;

        if ($condition !== false) {
            throw new AssertionFailed($message !== '' ? $message : 'expected false, got ' . self::export($condition));
        }
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    protected function assertSame($expected, $actual, string $message = ''): void
    {
        ++$this->assertions;

        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                "%sexpected %s\n     got %s",
                $message !== '' ? $message . ': ' : '',
                self::export($expected),
                self::export($actual)
            ));
        }
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        ++$this->assertions;

        if ($expected != $actual) {
            throw new AssertionFailed(sprintf(
                "%sexpected %s\n     got %s",
                $message !== '' ? $message . ': ' : '',
                self::export($expected),
                self::export($actual)
            ));
        }
    }

    /** @param mixed $value */
    protected function assertNull($value, string $message = ''): void
    {
        ++$this->assertions;

        if ($value !== null) {
            throw new AssertionFailed($message !== '' ? $message : 'expected null, got ' . self::export($value));
        }
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        ++$this->assertions;

        if (strpos($haystack, $needle) === false) {
            throw new AssertionFailed(sprintf(
                '%sexpected "%s" to contain "%s"',
                $message !== '' ? $message . ': ' : '',
                $haystack,
                $needle
            ));
        }
    }

    protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        ++$this->assertions;

        if (strpos($haystack, $needle) !== false) {
            throw new AssertionFailed(sprintf(
                '%sexpected "%s" not to contain "%s"',
                $message !== '' ? $message . ': ' : '',
                $haystack,
                $needle
            ));
        }
    }

    /** @param array<mixed> $array */
    protected function assertArrayHasKey(string $key, array $array, string $message = ''): void
    {
        ++$this->assertions;

        if (!array_key_exists($key, $array)) {
            throw new AssertionFailed(sprintf(
                '%sexpected key "%s" in [%s]',
                $message !== '' ? $message . ': ' : '',
                $key,
                implode(', ', array_map('strval', array_keys($array)))
            ));
        }
    }

    /** @param array<mixed> $array */
    protected function assertArrayNotHasKey(string $key, array $array, string $message = ''): void
    {
        ++$this->assertions;

        if (array_key_exists($key, $array)) {
            throw new AssertionFailed(sprintf(
                '%sunexpected key "%s"',
                $message !== '' ? $message . ': ' : '',
                $key
            ));
        }
    }

    /** @param array<mixed> $array */
    protected function assertCount(int $expected, array $array, string $message = ''): void
    {
        $this->assertSame($expected, count($array), $message !== '' ? $message : 'unexpected element count');
    }

    /**
     * @param class-string<Throwable> $expectedClass
     */
    protected function assertThrows(string $expectedClass, callable $operation, string $message = ''): Throwable
    {
        ++$this->assertions;

        try {
            $operation();
        } catch (Throwable $thrown) {
            if (!$thrown instanceof $expectedClass) {
                throw new AssertionFailed(sprintf(
                    '%sexpected %s, got %s: %s',
                    $message !== '' ? $message . ': ' : '',
                    $expectedClass,
                    get_class($thrown),
                    $thrown->getMessage()
                ));
            }

            return $thrown;
        }

        throw new AssertionFailed(sprintf(
            '%sexpected %s, nothing was thrown',
            $message !== '' ? $message . ': ' : '',
            $expectedClass
        ));
    }

    protected function assertDoesNotThrow(callable $operation, string $message = ''): void
    {
        ++$this->assertions;

        try {
            $operation();
        } catch (Throwable $thrown) {
            throw new AssertionFailed(sprintf(
                '%sunexpected %s: %s',
                $message !== '' ? $message . ': ' : '',
                get_class($thrown),
                $thrown->getMessage()
            ));
        }
    }

    /** @param mixed $value */
    private static function export($value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return (string) var_export($value, true);
    }
}
