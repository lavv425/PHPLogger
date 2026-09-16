<?php

declare(strict_types=1);

namespace PhpLogger\Payload;

use PhpLogger\Enum\DbOperation;
use PhpLogger\Enum\LogType;
use PhpLogger\Exception\InvalidLogEventException;
use PhpLogger\Support\Assert;

/**
 * Database operation.
 *
 * The statement is stored as given; the sanitizing gate replaces it with a
 * normalized fingerprint, so a statement built by string interpolation cannot
 * leak its values even though it should never have been built that way.
 */
final class DbQueryPayload extends AbstractPayload
{
    private string $database;
    private ?string $statement = null;
    /** @var array<string, mixed>|null */
    private ?array $params = null;
    private string $operation = DbOperation::OTHER;
    private ?int $rowCount = null;

    private function __construct(string $event, string $database)
    {
        parent::__construct($event);
        $this->database = Assert::nonEmpty($database, 'database');
    }

    public static function create(string $event, string $database): self
    {
        return new self($event, $database);
    }

    /**
     * @param array<string, mixed>|null $params
     */
    public function withStatement(string $statement, ?array $params = null): self
    {
        $clone = clone $this;
        $clone->statement = Assert::nonEmpty($statement, 'statement');
        $clone->params = $params;
        $clone->operation = DbOperation::fromStatement($statement);

        return $clone;
    }

    public function withOperation(string $operation): self
    {
        if (!DbOperation::isValid($operation)) {
            throw InvalidLogEventException::forField('operation', 'unknown operation "' . $operation . '"');
        }

        $clone = clone $this;
        $clone->operation = $operation;

        return $clone;
    }

    public function withRowCount(int $rowCount): self
    {
        $clone = clone $this;
        $clone->rowCount = $rowCount;

        return $clone;
    }

    public function logType(): string
    {
        return LogType::DB_QUERY;
    }

    public function data(): array
    {
        return [
            'database' => $this->database,
            'operation' => $this->operation,
            'statement' => $this->statement,
            'params' => $this->params,
            'row_count' => $this->rowCount,
        ];
    }
}
