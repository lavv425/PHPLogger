<?php

declare(strict_types=1);

namespace PhpLoggerTests\Unit;

use PhpLogger\Enum\Outcome;
use PhpLogger\Payload\DbQueryPayload;
use PhpLogger\Payload\PhpLogPayload;
use PhpLogger\Payload\ServiceCallPayload;
use PhpLogger\Sanitize\Scrubber;
use PhpLogger\Sanitize\StatementNormalizer;
use PhpLoggerTests\Support\Harness;
use PhpLoggerTests\TestCase;
use RuntimeException;

final class SanitizeTest extends TestCase
{
    public function testNormalizerReplacesLiteralsOfAnInterpolatedStatement(): void
    {
        $normalizer = new StatementNormalizer();

        $normalized = $normalizer->normalize(
            "SELECT * FROM users WHERE email = 'mario.rossi@example.com' AND pin = 4213"
        );

        $this->assertSame('SELECT * FROM users WHERE email = ? AND pin = ?', $normalized);
        $this->assertStringNotContains('example.com', $normalized);
    }

    public function testNormalizerCollapsesPlaceholderListsAndComments(): void
    {
        $normalizer = new StatementNormalizer();

        $normalized = $normalizer->normalize("/* cached */ SELECT id\n FROM t WHERE id IN (1, 2, 3) -- trailing");

        $this->assertSame('SELECT id FROM t WHERE id IN (?)', $normalized);
    }

    public function testFingerprintIsStableAndShortEnoughToSurviveScrubbing(): void
    {
        $normalizer = new StatementNormalizer();
        $hash = $normalizer->fingerprint('SELECT ?');

        $this->assertSame(16, strlen($hash));
        $this->assertSame($hash, $normalizer->fingerprint('SELECT ?'));
        // The scrubber masks hex runs of 32+ characters; a 16 char hash is safe.
        $this->assertSame($hash, (new Scrubber())->scrubString($hash));
    }

    public function testScrubberMasksTokensAndPersonalData(): void
    {
        $scrubber = new Scrubber();

        $this->assertStringNotContains(
            'abcdef',
            $scrubber->scrubString('Authorization: Bearer abcdef.ghijkl')
        );
        $this->assertStringNotContains(
            'mario@example.com',
            $scrubber->scrubString('user mario@example.com not found')
        );
        $this->assertStringNotContains(
            '4111111111111111',
            $scrubber->scrubString('card 4111111111111111 declined')
        );
        $this->assertStringNotContains(
            'hunter2',
            $scrubber->scrubString('https://api.example.com/login?user=x&password=hunter2')
        );
        $this->assertStringNotContains(
            'swordfish',
            $scrubber->scrubString('https://admin:swordfish@internal.example.com/health')
        );
    }

    public function testScrubberLeavesPlainIdentifiersAlone(): void
    {
        $scrubber = new Scrubber();

        // Not a valid Luhn sequence: an order number must stay readable.
        $this->assertSame('order 1234567890123 shipped', $scrubber->scrubString('order 1234567890123 shipped'));
        $this->assertSame('req_0123456789abcdef', $scrubber->scrubString('req_0123456789abcdef'));
    }

    public function testQueryParametersAreDroppedUnlessAllowListed(): void
    {
        [$logger, $handler] = Harness::loggerWithMemory();

        $logger->log(
            DbQueryPayload::create('user_lookup', 'production_db')
                ->withStatement('SELECT * FROM users WHERE id = ? AND token = ?', ['id' => 7, 'token' => 'secret'])
                ->withOutcome(Outcome::SUCCESS)
        );

        $record = $handler->lastAsArray();

        $this->assertSame(['_omitted' => 2], $record['data']['params']);
        $this->assertStringNotContains('secret', $handler->lines()[0]);
    }

    public function testAllowListedParameterKeysSurvive(): void
    {
        $gate = Harness::gate(['db_query' => ['params' => ['id']]]);
        [$logger, $handler] = Harness::loggerWithMemory($gate);

        $logger->log(
            DbQueryPayload::create('user_lookup', 'production_db')
                ->withStatement('SELECT * FROM users WHERE id = ?', ['id' => 7, 'token' => 'secret'])
        );

        $record = $handler->lastAsArray();

        $this->assertSame(7, $record['data']['params']['id']);
        $this->assertSame(1, $record['data']['params']['_omitted']);
        $this->assertStringNotContains('secret', $handler->lines()[0]);
    }

    public function testRawStatementIsNeverEmittedByDefault(): void
    {
        [$logger, $handler] = Harness::loggerWithMemory();

        $logger->log(
            DbQueryPayload::create('user_lookup', 'production_db')
                ->withStatement("SELECT * FROM users WHERE email = 'mario@example.com'")
        );

        $record = $handler->lastAsArray();

        $this->assertArrayNotHasKey('statement_raw', $record['data']);
        $this->assertSame('SELECT * FROM users WHERE email = ?', $record['data']['statement']);
        $this->assertSame(16, strlen($record['data']['statement_hash']));
    }

    public function testRawStatementIsStillScrubbedWhenExplicitlyEnabled(): void
    {
        $gate = Harness::gate([], true);
        [$logger, $handler] = Harness::loggerWithMemory($gate);

        $logger->log(
            DbQueryPayload::create('user_lookup', 'production_db')
                ->withStatement("SELECT * FROM users WHERE email = 'mario@example.com'")
        );

        $line = $handler->lines()[0];

        $this->assertStringContains('statement_raw', $line);
        $this->assertStringNotContains('mario@example.com', $line);
    }

    public function testTimingSurvivesWhenAllowListed(): void
    {
        $gate = Harness::gate(['service_call' => ['timing' => ['dns', 'connect', 'ttfb', 'total']]]);
        [$logger, $handler] = Harness::loggerWithMemory($gate);

        $logger->log(
            ServiceCallPayload::create('si_service_call', 'get', 'https://api.example.com/v1/users?action=getUser')
                ->withHttpCode(200)
                ->withTiming(0.01, 0.03, 0.08, 0.15)
                ->withDuration(0.152)
                ->withSuccess()
        );

        $record = $handler->lastAsArray();

        $this->assertSame(0.01, $record['data']['timing']['dns']);
        $this->assertSame(0.15, $record['data']['timing']['total']);
        $this->assertSame('action=getUser', $record['data']['query_string']);
    }

    public function testStackTraceNeverCarriesCallArguments(): void
    {
        [$logger, $handler] = Harness::loggerWithMemory();

        $throwable = self::throwFrom('hunter2');

        $logger->log(PhpLogPayload::fromThrowable('uncaught_exception', $throwable));

        $line = $handler->lines()[0];

        $this->assertStringContains('stack_trace', $line);
        $this->assertStringNotContains('hunter2', $line);
    }

    public function testSensitiveKeysAreMaskedEvenWhenAllowListed(): void
    {
        $gate = Harness::gate(['php_log' => ['context' => ['token', 'route']]]);
        [$logger, $handler] = Harness::loggerWithMemory($gate);

        $logger->log(
            PhpLogPayload::create('custom_log', 'checkout started')
                ->withContext(['route' => '/checkout', 'token' => 'abc123'])
        );

        $record = $handler->lastAsArray();

        $this->assertSame('/checkout', $record['data']['context']['route']);
        $this->assertArrayNotHasKey('token', $record['data']['context']);
        $this->assertStringNotContains('abc123', $handler->lines()[0]);
    }

    private static function throwFrom(string $password): RuntimeException
    {
        return new RuntimeException('login failed');
    }
}
