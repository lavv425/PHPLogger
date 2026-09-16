<?php

declare(strict_types=1);

namespace PhpLoggerTests\Unit;

use PhpLogger\Contract\LogPayload;
use PhpLogger\Payload\DbQueryPayload;
use PhpLogger\Payload\PhpLogPayload;
use PhpLogger\Payload\ServiceCallPayload;
use PhpLogger\Sanitize\SanitizingGate;
use PhpLoggerTests\Support\Harness;
use PhpLoggerTests\TestCase;

/**
 * The JSON output is a public contract consumed by dashboards. schema_version
 * alone does not protect it: these files do. A renamed, retyped or removed field
 * fails here, and any such change requires a compatibility decision documented
 * in SCHEMA.md.
 */
final class SchemaGoldenTest extends TestCase
{
    public function testDbQueryRecordMatchesTheContract(): void
    {
        $this->assertMatchesGolden('db_query', self::dbQueryPayload(), Harness::gate([
            'db_query' => ['params' => ['id']],
        ]));
    }

    public function testServiceCallRecordMatchesTheContract(): void
    {
        $this->assertMatchesGolden('service_call', self::serviceCallPayload(), Harness::gate([
            'service_call' => ['timing' => ['dns', 'connect', 'ttfb', 'total']],
        ]));
    }

    public function testPhpLogRecordMatchesTheContract(): void
    {
        $this->assertMatchesGolden('php_log', self::phpLogPayload(), Harness::gate([
            'php_log' => ['context' => ['route']],
        ]));
    }

    public static function dbQueryPayload(): LogPayload
    {
        return DbQueryPayload::create('user_lookup', 'production_db')
            ->withStatement('SELECT * FROM users WHERE id = ?', ['id' => 1, 'token' => 'secret'])
            ->withRowCount(1)
            ->withDuration(0.045)
            ->withSuccess();
    }

    public static function serviceCallPayload(): LogPayload
    {
        return ServiceCallPayload::create('si_service_call', 'GET', 'https://api.example.com/v1/users?action=getUser')
            ->withHttpCode(200)
            ->withTiming(0.01, 0.03, 0.08, 0.15)
            ->withDuration(0.152)
            ->withSuccess();
    }

    public static function phpLogPayload(): LogPayload
    {
        return PhpLogPayload::fromPhpError(
            'error_handler',
            2,
            'Undefined array key "total"',
            '/var/www/app/services/OrderService.php',
            127,
            ['#0 /var/www/app/controllers/OrderController.php(45): OrderService->processOrder()']
        )
            ->withCallSite('App\\Services\\OrderService', 'processOrder')
            ->withMemoryUsage(2097152)
            ->withDuration(0.003)
            ->withContext(['route' => '/orders', 'password' => 'hunter2']);
    }

    public static function render(LogPayload $payload, SanitizingGate $gate): string
    {
        [$logger, $handler] = Harness::loggerWithMemory($gate);
        $logger->log($payload);

        return $handler->lines()[0];
    }

    private function assertMatchesGolden(string $name, LogPayload $payload, SanitizingGate $gate): void
    {
        $path = __DIR__ . '/../golden/' . $name . '.json';
        $expected = file_get_contents($path);

        $this->assertTrue(is_string($expected), 'missing golden file ' . $path);
        $this->assertSame(
            self::normalize((string) $expected),
            self::normalize(self::render($payload, $gate)),
            'schema drift in ' . $name . '.json — see the compatibility policy in SCHEMA.md'
        );
    }

    /**
     * Re-encodes so an editor reformatting the golden file does not fail the
     * suite. Key order, types and the object/array distinction are preserved,
     * which is what the contract is actually about.
     */
    private static function normalize(string $json): string
    {
        $decoded = json_decode(trim($json));

        if ($decoded === null) {
            return trim($json);
        }

        // Same precision handling as the formatter, so a failure message is
        // readable instead of showing 0.04499999999999999833...
        $previous = ini_set('serialize_precision', '-1');

        try {
            return (string) json_encode(
                $decoded,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
        } finally {
            if (is_string($previous)) {
                ini_set('serialize_precision', $previous);
            }
        }
    }
}
