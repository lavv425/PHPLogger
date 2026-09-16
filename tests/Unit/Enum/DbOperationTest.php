<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Enum;

use Logger\Enum\DbOperation;
use PHPUnit\Framework\TestCase;

final class DbOperationTest extends TestCase
{
    /** @dataProvider statements */
    public function test_derives_the_operation_from_the_leading_keyword(string $statement, string $expected): void
    {
        self::assertSame($expected, DbOperation::fromStatement($statement));
    }

    /** @return array<string, array{string, string}> */
    public function statements(): array
    {
        return [
            'select' => ['SELECT id FROM users', DbOperation::SELECT],
            'lowercase select' => ['select id from users', DbOperation::SELECT],
            'insert' => ['INSERT INTO users (id) VALUES (1)', DbOperation::INSERT],
            'update' => ['UPDATE users SET name = ?', DbOperation::UPDATE],
            'delete' => ['DELETE FROM users WHERE id = ?', DbOperation::DELETE],
            'replace' => ['REPLACE INTO users VALUES (1)', DbOperation::REPLACE],
            'truncate' => ['TRUNCATE TABLE users', DbOperation::TRUNCATE],
            'show' => ['SHOW TABLES', DbOperation::SHOW],
            'call' => ['CALL do_something()', DbOperation::CALL],

            'CTE counts as a select' => ['WITH recent AS (SELECT 1) SELECT * FROM recent', DbOperation::SELECT],
            'EXEC is a call' => ['EXEC sp_do_it', DbOperation::CALL],
            'EXECUTE is a call' => ['EXECUTE sp_do_it', DbOperation::CALL],
            'DESCRIBE is a show' => ['DESCRIBE users', DbOperation::SHOW],
            'DESC is a show' => ['DESC users', DbOperation::SHOW],
            'EXPLAIN is a show' => ['EXPLAIN SELECT 1', DbOperation::SHOW],

            'leading block comment is skipped' => ['/* index hint */ SELECT 1', DbOperation::SELECT],
            'leading line comment is skipped' => ["-- audit\nSELECT 1", DbOperation::SELECT],
            'leading parenthesis is skipped' => ['(SELECT 1) UNION (SELECT 2)', DbOperation::SELECT],
            'leading whitespace is skipped' => ["\n\t  SELECT 1", DbOperation::SELECT],

            'unsupported ddl' => ['CREATE TABLE users (id INT)', DbOperation::OTHER],
            'the literal OTHER keyword' => ['OTHER something', DbOperation::OTHER],
            'no keyword at all' => ['12345', DbOperation::OTHER],
            'empty statement' => ['', DbOperation::OTHER],
        ];
    }

    public function test_validates_known_operations(): void
    {
        foreach (DbOperation::all() as $operation) {
            self::assertTrue(DbOperation::isValid($operation), $operation . ' should be valid');
        }

        self::assertFalse(DbOperation::isValid('MERGE'));
        self::assertFalse(DbOperation::isValid('select'), 'operations are stored uppercase');
    }
}
