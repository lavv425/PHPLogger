<?php

declare(strict_types=1);

namespace PhpLogger\Channel;

use PhpLogger\Contract\LogRecord;
use PhpLogger\Enum\Level;
use PhpLogger\Exception\HandlerFailure;
use PhpLogger\Handler\HandlerInterface;
use PhpLogger\Processor\ProcessorInterface;

/**
 * A named destination group: level floor, processors, handlers.
 */
final class Channel
{
    private string $name;
    private string $minLevel;
    /** @var ProcessorInterface[] */
    private array $processors;
    /** @var HandlerInterface[] */
    private array $handlers;

    /**
     * @param ProcessorInterface[] $processors
     * @param HandlerInterface[] $handlers
     */
    public function __construct(string $name, string $minLevel, array $processors, array $handlers)
    {
        $this->name = $name;
        $this->minLevel = $minLevel;
        $this->processors = $processors;
        $this->handlers = $handlers;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @throws HandlerFailure when every handler failed
     */
    public function handle(LogRecord $record): void
    {
        if (!Level::isAtLeast($record->level(), $this->minLevel)) {
            return;
        }

        foreach ($this->processors as $processor) {
            $processed = $processor->process($record);

            if ($processed === null) {
                return;
            }

            $record = $processed;
        }

        $failures = 0;
        $lastFailure = null;

        foreach ($this->handlers as $handler) {
            try {
                $handler->handle($record);
            } catch (HandlerFailure $failure) {
                ++$failures;
                $lastFailure = $failure;
            }
        }

        // One surviving destination is enough; report only a total blackout.
        if ($lastFailure !== null && $failures === count($this->handlers)) {
            throw $lastFailure;
        }
    }

    public function close(): void
    {
        foreach ($this->handlers as $handler) {
            $handler->close();
        }
    }
}
