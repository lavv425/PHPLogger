<?php

declare(strict_types=1);

namespace PhpLogger\Config;

use PhpLogger\Exception\InvalidConfigurationException;

/**
 * Size limits.
 *
 * max_record_bytes defaults to 4096 because a write to a POSIX pipe is atomic
 * only up to PIPE_BUF: above that, concurrent PHP-FPM workers writing to the
 * same stdout can interleave and produce unparseable lines.
 */
final class LimitsConfig
{
    private int $maxRecordBytes;
    private int $maxFieldBytes;
    private int $maxStackFrames;
    private int $maxDepth;

    public function __construct(int $maxRecordBytes, int $maxFieldBytes, int $maxStackFrames, int $maxDepth)
    {
        $this->maxRecordBytes = $maxRecordBytes;
        $this->maxFieldBytes = $maxFieldBytes;
        $this->maxStackFrames = $maxStackFrames;
        $this->maxDepth = $maxDepth;
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $limits = new self(
            self::positiveInt($config, 'max_record_bytes', 4096),
            self::positiveInt($config, 'max_field_bytes', 1024),
            self::positiveInt($config, 'max_stack_frames', 20),
            self::positiveInt($config, 'max_depth', 4)
        );

        if ($limits->maxFieldBytes > $limits->maxRecordBytes) {
            throw InvalidConfigurationException::forKey(
                'limits.max_field_bytes',
                'must not exceed limits.max_record_bytes'
            );
        }

        return $limits;
    }

    public function maxRecordBytes(): int
    {
        return $this->maxRecordBytes;
    }

    public function maxFieldBytes(): int
    {
        return $this->maxFieldBytes;
    }

    public function maxStackFrames(): int
    {
        return $this->maxStackFrames;
    }

    public function maxDepth(): int
    {
        return $this->maxDepth;
    }

    /** @param array<string, mixed> $config */
    private static function positiveInt(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        if (!is_int($value) || $value <= 0) {
            throw InvalidConfigurationException::forKey('limits.' . $key, 'must be a positive integer');
        }

        return $value;
    }
}
