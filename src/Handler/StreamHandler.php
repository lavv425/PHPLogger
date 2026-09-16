<?php

declare(strict_types=1);

namespace Logger\Handler;

use Logger\Contract\LogRecord;
use Logger\Exception\HandlerFailure;
use Logger\Formatter\FormatterInterface;
use Logger\Support\ErrorTrap;

/**
 * Writes to a stream target, php://stdout by default.
 *
 * fwrite can write only part of the buffer and can return false: both are
 * checked, because a half-written line is a corrupted record for the collector.
 */
final class StreamHandler implements HandlerInterface
{
    private const MAX_EMPTY_WRITES = 3;

    private string $target;
    private FormatterInterface $formatter;
    private bool $useLocking;
    /** @var resource|null */
    private $stream;
    private bool $ownsStream = true;

    public function __construct(string $target, FormatterInterface $formatter, bool $useLocking = false)
    {
        $this->target = $target;
        $this->formatter = $formatter;
        $this->useLocking = $useLocking;
        $this->stream = null;
    }

    /**
     * @param resource $stream
     */
    public static function fromResource($stream, FormatterInterface $formatter): self
    {
        $handler = new self('php://memory', $formatter, false);
        $handler->stream = $stream;
        $handler->ownsStream = false;

        return $handler;
    }

    public function handle(LogRecord $record): void
    {
        $line = $this->formatter->format($record);

        $this->open();
        $this->write($line);
    }

    public function close(): void
    {
        if ($this->stream !== null && $this->ownsStream) {
            ErrorTrap::run(function (): void {
                fclose($this->stream);
            });
        }

        if ($this->ownsStream) {
            $this->stream = null;
        }
    }

    private function open(): void
    {
        if (is_resource($this->stream)) {
            return;
        }

        $target = $this->target;
        $error = null;
        $stream = ErrorTrap::run(static function () use ($target) {
            return fopen($target, 'ab');
        }, $error);

        if (!is_resource($stream)) {
            throw new HandlerFailure(sprintf('cannot open log target "%s": %s', $this->target, $error ?? 'unknown'));
        }

        $this->stream = $stream;
    }

    private function write(string $line): void
    {
        $stream = $this->stream;
        $length = strlen($line);
        $written = 0;
        $emptyWrites = 0;

        if ($this->useLocking) {
            ErrorTrap::run(static function () use ($stream): void {
                flock($stream, LOCK_EX);
            });
        }

        try {
            while ($written < $length) {
                $chunk = substr($line, $written);
                $error = null;
                $result = ErrorTrap::run(static function () use ($stream, $chunk) {
                    return fwrite($stream, $chunk);
                }, $error);

                if ($result === false) {
                    throw new HandlerFailure(sprintf(
                        'write to "%s" failed after %d of %d bytes: %s',
                        $this->target,
                        $written,
                        $length,
                        $error ?? 'unknown'
                    ));
                }

                if ($result === 0 && ++$emptyWrites >= self::MAX_EMPTY_WRITES) {
                    throw new HandlerFailure(sprintf(
                        'write to "%s" stalled after %d of %d bytes',
                        $this->target,
                        $written,
                        $length
                    ));
                }

                $written += $result;
            }
        } finally {
            if ($this->useLocking) {
                ErrorTrap::run(static function () use ($stream): void {
                    flock($stream, LOCK_UN);
                });
            }
        }
    }
}
