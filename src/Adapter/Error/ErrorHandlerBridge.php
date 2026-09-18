<?php

declare(strict_types=1);

namespace Logger\Adapter\Error;

use Logger\Interfaces\Logger\LoggerInterface;
use Logger\Payload\PhpLogPayload;
use Logger\Support\Assert;
use Throwable;

/**
 * Routes PHP's own errors into the logger.
 *
 * Three hooks, because PHP reports failures in three unrelated ways: recoverable
 * errors through set_error_handler, uncaught exceptions through
 * set_exception_handler, and fatals only as a leftover readable at shutdown.
 *
 * Nothing is swallowed: handleError() returns false so the normal PHP machinery
 * still runs, and the previous handlers are chained rather than replaced.
 */
final class ErrorHandlerBridge
{
    /** Severities that end the request and can only be seen at shutdown. */
    private const FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    private LoggerInterface $logger;
    private string $errorEvent;
    private string $exceptionEvent;
    private string $shutdownEvent;
    private int $maxFrames;
    private bool $captureTraces;

    /** Freed at shutdown so there is room to log an out-of-memory fatal. */
    private ?string $reserve = null;
    private int $reserveBytes;

    private bool $registered = false;
    /** Set once an uncaught exception has been reported, see handleShutdown(). */
    private bool $exceptionReported = false;
    /** Signature of the last error handleError() reported, see handleShutdown(). */
    private ?string $lastReported = null;
    /** @var callable|null */
    private $previousErrorHandler = null;
    /** @var callable|null */
    private $previousExceptionHandler = null;

    public function __construct(LoggerInterface $logger, int $reserveBytes = 262144, bool $captureTraces = false, int $maxFrames = 20, string $errorEvent = 'error_handler', string $exceptionEvent = 'uncaught_exception', string $shutdownEvent = 'shutdown_error')
    {
        $this->logger = $logger;
        $this->reserveBytes = max(0, $reserveBytes);
        $this->captureTraces = $captureTraces;
        $this->maxFrames = max(1, $maxFrames);
        $this->errorEvent = Assert::name($errorEvent, 'event');
        $this->exceptionEvent = Assert::name($exceptionEvent, 'event');
        $this->shutdownEvent = Assert::name($shutdownEvent, 'event');
    }

    /**
     * Installs the three hooks. The shutdown function cannot be removed once
     * registered, which is why unregister() only restores the other two.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        // Claimed while memory is still available: an out-of-memory fatal
        // leaves nothing to allocate, not even the record describing it.
        $this->reserve = $this->reserveBytes > 0 ? str_repeat(' ', $this->reserveBytes) : null;

        $this->previousErrorHandler = set_error_handler([$this, 'handleError']);
        $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);

        $this->registered = true;
    }

    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }

        restore_error_handler();
        restore_exception_handler();
        $this->registered = false;
        $this->reserve = null;
    }

    /**
     * @return bool false, so PHP keeps handling the error as configured
     */
    public function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // Covers both the @ operator and severities switched off in php.ini.
        // Logging them anyway would fill the collector with noise the operator
        // has explicitly asked not to see.
        if ((error_reporting() & $severity) === 0) {
            return $this->delegateError($severity, $message, $file, $line);
        }

        try {
            $this->logger->log(PhpLogPayload::fromPhpError($this->errorEvent, $severity, $message, $file, $line, $this->trace()));
            $this->lastReported = self::signature($severity, $message, $file, $line);
        } catch (Throwable $ignored) {
            // Never let the logger turn a notice into a fatal.
        }

        return $this->delegateError($severity, $message, $file, $line);
    }

    public function handleException(Throwable $exception): void
    {
        $this->exceptionReported = true;

        try {
            $this->logger->log(PhpLogPayload::fromThrowable($this->exceptionEvent, $exception, $this->maxFrames));
        } catch (Throwable $ignored) {
            // The exception below matters more than its log line.
        }

        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($exception);

            return;
        }

        // No previous handler: reproduce what PHP would have done, otherwise
        // installing the bridge would silently swallow uncaught exceptions.
        throw $exception;
    }

    /** Last chance to see a fatal: it never reaches the error handler. */
    public function handleShutdown(): void
    {
        $this->reserve = null;

        // An uncaught exception is rethrown so the process still dies the way
        // PHP intended, which leaves an E_ERROR behind. Reporting it again here
        // would file the same failure twice, once with a usable stack trace and
        // once without.
        if ($this->exceptionReported) {
            return;
        }

        $error = error_get_last();
        if ($error === null || !in_array($error['type'], self::FATAL, true)) {
            return;
        }

        // E_USER_ERROR reaches the error handler first and only then ends the
        // request, so without this it would be filed twice.
        if ($this->lastReported !== null
            && $this->lastReported === self::signature((int) $error['type'], (string) $error['message'], (string) $error['file'], (int) $error['line'])) {
            return;
        }

        try {
            $this->logger->log(PhpLogPayload::fromPhpError($this->shutdownEvent, (int) $error['type'], (string) $error['message'], (string) $error['file'], (int) $error['line']));
        } catch (Throwable $ignored) {
            // Nothing left to try at this point in the request.
        }
    }

    /** @return string[]|null */
    private function trace(): ?array
    {
        if (!$this->captureTraces) {
            return null;
        }

        // IGNORE_ARGS is not optional: the arguments are what leaks a password
        // into a log line.
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->maxFrames + 2);
        $rendered = [];
        $index = 0;

        foreach ($frames as $frame) {
            if (($frame['class'] ?? null) === self::class) {
                continue;
            }

            $callable = '';
            if (isset($frame['class'], $frame['type'], $frame['function'])) {
                $callable = $frame['class'] . $frame['type'] . $frame['function'] . '()';
            } elseif (isset($frame['function'])) {
                $callable = $frame['function'] . '()';
            }

            $rendered[] = sprintf('#%d %s(%s): %s', $index, $frame['file'] ?? '[internal]', isset($frame['line']) ? (string) $frame['line'] : '0', $callable);

            if (++$index >= $this->maxFrames) {
                break;
            }
        }

        return $rendered === [] ? null : $rendered;
    }

    private static function signature(int $severity, string $message, string $file, int $line): string
    {
        return $severity . '|' . $file . '|' . $line . '|' . $message;
    }

    private function delegateError(int $severity, string $message, string $file, int $line): bool
    {
        if ($this->previousErrorHandler !== null) {
            return (bool) ($this->previousErrorHandler)($severity, $message, $file, $line);
        }

        return false;
    }
}
