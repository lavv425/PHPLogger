<?php

declare(strict_types=1);

namespace PhpLoggerTests\Unit;

use PhpLogger\Contract\LogError;
use PhpLogger\Enum\DbOperation;
use PhpLogger\Enum\ErrorType;
use PhpLogger\Enum\Level;
use PhpLogger\Enum\Outcome;
use PhpLogger\Enum\PhpSeverity;
use PhpLogger\Exception\InvalidLogEventException;
use PhpLogger\Payload\DbQueryPayload;
use PhpLogger\Payload\PhpLogPayload;
use PhpLogger\Payload\ServiceCallPayload;
use PhpLoggerTests\TestCase;
use RuntimeException;

final class PayloadTest extends TestCase
{
    public function testOperationIsDerivedFromTheStatement(): void
    {
        $this->assertSame(DbOperation::SELECT, DbOperation::fromStatement('  select 1'));
        $this->assertSame(DbOperation::SELECT, DbOperation::fromStatement('WITH cte AS (SELECT 1) SELECT * FROM cte'));
        $this->assertSame(DbOperation::CALL, DbOperation::fromStatement('CALL do_things()'));
        $this->assertSame(DbOperation::SHOW, DbOperation::fromStatement('EXPLAIN SELECT 1'));
        $this->assertSame(DbOperation::OTHER, DbOperation::fromStatement('BEGIN'));
    }

    public function testPayloadsAreImmutable(): void
    {
        $original = DbQueryPayload::create('user_lookup', 'db');
        $modified = $original->withDuration(1.5);

        $this->assertNull($original->duration());
        $this->assertSame(1.5, $modified->duration());
    }

    public function testInvalidEventNameIsRejectedAtConstruction(): void
    {
        $this->assertThrows(InvalidLogEventException::class, static function (): void {
            DbQueryPayload::create('user lookup for mario@example.com', 'db');
        });
    }

    public function testUnknownHttpMethodIsRejected(): void
    {
        $this->assertThrows(InvalidLogEventException::class, static function (): void {
            ServiceCallPayload::create('call', 'FETCH', 'https://example.com');
        });
    }

    public function testNegativeDurationIsRejected(): void
    {
        $this->assertThrows(InvalidLogEventException::class, static function (): void {
            DbQueryPayload::create('user_lookup', 'db')->withDuration(-0.5);
        });
    }

    public function testLevelFollowsTheOutcomeUnlessOverridden(): void
    {
        $payload = DbQueryPayload::create('user_lookup', 'db');

        $this->assertSame(Level::INFO, $payload->defaultLevel());
        $this->assertSame(Level::ERROR, $payload->withOutcome(Outcome::FAILURE)->defaultLevel());
        $this->assertSame(Level::DEBUG, $payload->withOutcome(Outcome::FAILURE)->withLevel(Level::DEBUG)->defaultLevel());
    }

    public function testAttachingAnErrorDoesNotDecideTheOutcome(): void
    {
        $error = LogError::fromHttpStatus(500);

        $withError = ServiceCallPayload::create('call', 'GET', 'https://example.com')->withError($error);
        $withFailure = ServiceCallPayload::create('call', 'GET', 'https://example.com')->withFailure($error);

        $this->assertSame(Outcome::UNKNOWN, $withError->outcome());
        $this->assertSame(Outcome::FAILURE, $withFailure->outcome());
    }

    public function testWarningsAndDeprecationsHaveNoOutcome(): void
    {
        $this->assertSame(Outcome::UNKNOWN, PhpSeverity::outcome(2));
        $this->assertSame(Outcome::UNKNOWN, PhpSeverity::outcome(8192));
        $this->assertSame(Outcome::FAILURE, PhpSeverity::outcome(1));

        $this->assertSame(Level::WARNING, PhpSeverity::level(2));
        $this->assertSame(Level::CRITICAL, PhpSeverity::level(1));
        $this->assertSame('E_USER_DEPRECATED', PhpSeverity::name(16384));
    }

    public function testPhpErrorKeepsSeverityAndCodeSeparate(): void
    {
        $payload = PhpLogPayload::fromPhpError('error_handler', 2, 'undefined index', '/app/index.php', 10);
        $error = $payload->error();

        $this->assertSame(ErrorType::PHP_ERROR, $error->type());
        $this->assertSame('E_WARNING', $error->severity());
        $this->assertSame(2, $error->severityCode());
        $this->assertNull($error->code());
        $this->assertSame(Level::WARNING, $payload->defaultLevel());
    }

    public function testExceptionCodeIsNotConfusedWithPhpSeverity(): void
    {
        $error = LogError::fromThrowable(new RuntimeException('boom', 42));

        $this->assertSame(ErrorType::EXCEPTION, $error->type());
        $this->assertSame('42', $error->code());
        $this->assertNull($error->severity());
        $this->assertSame(RuntimeException::class, $error->class());
    }

    public function testCurlTimeoutGetsItsOwnErrorType(): void
    {
        $this->assertSame(ErrorType::TIMEOUT, LogError::fromCurl(28, 'timed out')->type());
        $this->assertSame(ErrorType::CURL_ERROR, LogError::fromCurl(7, 'connection refused')->type());
    }

    public function testSuccessFlagMirrorsTheOutcome(): void
    {
        $this->assertTrue(Outcome::toSuccessFlag(Outcome::SUCCESS));
        $this->assertFalse(Outcome::toSuccessFlag(Outcome::FAILURE));
        $this->assertNull(Outcome::toSuccessFlag(Outcome::UNKNOWN));
    }
}
