<?php

declare(strict_types=1);

namespace Logger;

use Logger\Contract\LogError;
use Logger\Enum\Level;
use Logger\Interfaces\Logger\LoggerInterface;
use Logger\Payload\PhpLogPayload;
use Logger\Support\Assert;
use Throwable;

/**
 * PSR-3 shaped adapter, so third party libraries can log through this package.
 *
 * The interface itself is not declared: psr/log cannot be installed without
 * Composer. When the host application already provides it, a subclass adding
 * "implements \Psr\Log\LoggerInterface" is enough, the signatures match.
 */
class PsrStyleLogger
{
    private const DEFAULT_EVENT = 'custom_log';

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $event = self::DEFAULT_EVENT;
        if (isset($context['event']) && is_string($context['event'])
            && preg_match(Assert::NAME_PATTERN, $context['event']) === 1) {
            $event = $context['event'];
        }

        $throwable = $context['exception'] ?? null;
        unset($context['event'], $context['exception']);

        $payload = PhpLogPayload::create($event, $message)->withContext($context === [] ? null : $context);

        if ($throwable instanceof Throwable) {
            $payload = $payload
                ->withFailure(LogError::fromThrowable($throwable))
                ->withLocation($throwable->getFile(), $throwable->getLine());
        }

        $this->logger->log($payload, Level::isValid($level) ? $level : Level::INFO);
    }

    /** @param array<string, mixed> $context */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(Level::EMERGENCY, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function alert(string $message, array $context = []): void
    {
        $this->log(Level::ALERT, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log(Level::CRITICAL, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log(Level::ERROR, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log(Level::WARNING, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->log(Level::NOTICE, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log(Level::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log(Level::DEBUG, $message, $context);
    }
}
