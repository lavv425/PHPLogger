<?php

declare(strict_types=1);

namespace PhpLogger\Support;

/**
 * Shared by a logger and every channel-pinned clone of it, so an error raised
 * while logging cannot re-enter the pipeline through the error handler.
 */
final class ReentrancyGuard
{
    private int $depth = 0;

    public function isBusy(): bool
    {
        return $this->depth > 0;
    }

    public function enter(): void
    {
        ++$this->depth;
    }

    public function leave(): void
    {
        if ($this->depth > 0) {
            --$this->depth;
        }
    }
}
