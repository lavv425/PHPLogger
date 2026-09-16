<?php

declare(strict_types=1);

namespace PhpLogger\Config;

use PhpLogger\Exception\InvalidConfigurationException;

/**
 * Allow-list for free-form structures.
 *
 * Policy: every array-valued field inside "data" is dropped unless its keys are
 * listed here. This is the only part of the sanitizer that is a structural
 * guarantee rather than best effort, so it is deny-by-default and explicit.
 */
final class CaptureConfig
{
    /** @var array<string, array<string, string[]>> logType => field => allowed keys */
    private array $fields;
    private bool $rawStatement;

    /**
     * @param array<string, array<string, string[]>> $fields
     */
    public function __construct(array $fields, bool $rawStatement)
    {
        $this->fields = $fields;
        $this->rawStatement = $rawStatement;
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $rawStatement = $config['raw_statement'] ?? false;
        if (!is_bool($rawStatement)) {
            throw InvalidConfigurationException::forKey('capture.raw_statement', 'must be a boolean');
        }

        $rawFields = $config['fields'] ?? [];
        if (!is_array($rawFields)) {
            throw InvalidConfigurationException::forKey('capture.fields', 'must be an array');
        }

        $fields = [];
        foreach ($rawFields as $logType => $definition) {
            if (!is_string($logType) || !is_array($definition)) {
                throw InvalidConfigurationException::forKey('capture.fields', 'must map log types to field lists');
            }

            foreach ($definition as $field => $keys) {
                if (!is_string($field) || !is_array($keys)) {
                    throw InvalidConfigurationException::forKey(
                        'capture.fields.' . $logType,
                        'each field must map to a list of allowed keys'
                    );
                }

                foreach ($keys as $key) {
                    if (!is_string($key)) {
                        throw InvalidConfigurationException::forKey(
                            'capture.fields.' . $logType . '.' . $field,
                            'allowed keys must be strings'
                        );
                    }
                }

                $fields[$logType][$field] = array_values($keys);
            }
        }

        return new self($fields, $rawStatement);
    }

    /** @return string[] */
    public function allowedKeys(string $logType, string $field): array
    {
        return $this->fields[$logType][$field] ?? [];
    }

    public function isRawStatementEnabled(): bool
    {
        return $this->rawStatement;
    }
}
