<?php

declare(strict_types=1);

namespace PhpLogger\Config;

use PhpLogger\Enum\Level;
use PhpLogger\Exception\InvalidConfigurationException;

final class ChannelConfig
{
    public const HANDLER_STREAM = 'stream';
    public const HANDLER_ERROR_LOG = 'error_log';
    public const HANDLER_NULL = 'null';
    public const HANDLER_MEMORY = 'memory';

    private string $name;
    private string $minLevel;
    /** @var array<int, array<string, mixed>> */
    private array $handlers;

    /**
     * @param array<int, array<string, mixed>> $handlers
     */
    public function __construct(string $name, string $minLevel, array $handlers)
    {
        $this->name = $name;
        $this->minLevel = $minLevel;
        $this->handlers = $handlers;
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(string $name, array $config): self
    {
        $path = 'channels.' . $name;

        $minLevel = $config['min_level'] ?? Level::DEBUG;
        if (!is_string($minLevel) || !Level::isValid($minLevel)) {
            throw InvalidConfigurationException::forKey($path . '.min_level', 'must be a valid level');
        }

        $handlers = $config['handlers'] ?? [];
        if (!is_array($handlers) || $handlers === []) {
            throw InvalidConfigurationException::forKey($path . '.handlers', 'must list at least one handler');
        }

        $validated = [];
        foreach (array_values($handlers) as $index => $handler) {
            $validated[] = self::validateHandler($path . '.handlers.' . $index, $handler);
        }

        return new self($name, $minLevel, $validated);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function minLevel(): string
    {
        return $this->minLevel;
    }

    /** @return array<int, array<string, mixed>> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    /**
     * @param mixed $handler
     * @return array<string, mixed>
     */
    private static function validateHandler(string $path, $handler): array
    {
        if (!is_array($handler)) {
            throw InvalidConfigurationException::forKey($path, 'must be an array');
        }

        $type = $handler['type'] ?? null;
        if (!is_string($type)) {
            throw InvalidConfigurationException::forKey($path . '.type', 'must be a string');
        }

        switch ($type) {
            case self::HANDLER_STREAM:
                $target = $handler['target'] ?? null;
                if (!is_string($target) || $target === '') {
                    throw InvalidConfigurationException::forKey($path . '.target', 'must be a non-empty stream target');
                }

                $locking = $handler['locking'] ?? false;
                if (!is_bool($locking)) {
                    throw InvalidConfigurationException::forKey($path . '.locking', 'must be a boolean');
                }

                return ['type' => $type, 'target' => $target, 'locking' => $locking];

            case self::HANDLER_ERROR_LOG:
            case self::HANDLER_NULL:
            case self::HANDLER_MEMORY:
                return ['type' => $type];

            default:
                throw InvalidConfigurationException::forKey($path . '.type', 'unknown handler type "' . $type . '"');
        }
    }
}
