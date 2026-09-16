<?php

declare(strict_types=1);

namespace Logger\Handler;

use Logger\Contract\LogRecord;
use Logger\Formatter\JsonFormatter;
use Logger\Interfaces\Formatter\FormatterInterface;
use Logger\Interfaces\Handler\HandlerInterface;

/** Test destination: keeps records and their rendered lines in memory. */
final class InMemoryHandler implements HandlerInterface
{
    private FormatterInterface $formatter;
    /** @var LogRecord[] */
    private array $records = [];
    /** @var string[] */
    private array $lines = [];

    public function __construct(?FormatterInterface $formatter = null)
    {
        $this->formatter = $formatter ?? new JsonFormatter();
    }

    public function handle(LogRecord $record): void
    {
        $this->records[] = $record;
        $this->lines[] = $this->formatter->format($record);
    }

    public function close(): void
    {
    }

    /** @return LogRecord[] */
    public function records(): array
    {
        return $this->records;
    }

    /** @return string[] */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return array<string, mixed>|null */
    public function lastAsArray(): ?array
    {
        $last = end($this->lines);

        if ($last === false) {
            return null;
        }

        $decoded = json_decode($last, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function clear(): void
    {
        $this->records = [];
        $this->lines = [];
    }
}
