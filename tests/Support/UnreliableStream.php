<?php

declare(strict_types=1);

namespace Logger\Tests\Support;

/**
 * A stream wrapper that misbehaves on demand.
 *
 * fwrite() is allowed to write only part of the buffer, and a real pipe does
 * exactly that under pressure. There is no portable way to provoke it with a
 * normal file, so the destination is simulated instead.
 */
final class UnreliableStream
{
    public const PROTOCOL = 'unreliable';

    public const MODE_PARTIAL = 'partial';
    public const MODE_STALLED = 'stalled';

    public static string $mode = self::MODE_PARTIAL;
    public static int $chunkSize = 7;
    public static string $written = '';

    /** @var resource|null set by PHP for stream contexts */
    public $context;

    public static function enable(string $mode, int $chunkSize = 7): void
    {
        self::$mode = $mode;
        self::$chunkSize = $chunkSize;
        self::$written = '';

        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }

        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    public static function disable(): void
    {
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::PROTOCOL);
        }

        self::$written = '';
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$mode === self::MODE_STALLED) {
            return 0;
        }

        $accepted = substr($data, 0, self::$chunkSize);
        self::$written .= $accepted;

        return strlen($accepted);
    }

    public function stream_close(): void
    {
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
