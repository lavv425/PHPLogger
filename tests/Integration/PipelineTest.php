<?php

declare(strict_types=1);

namespace Logger\Tests\Integration;

use Logger\Enum\Level;
use Logger\Enum\Outcome;
use Logger\Payload\DbQueryPayload;
use Logger\Payload\PhpLogPayload;
use Logger\Payload\ServiceCallPayload;
use Logger\Tests\Support\LoggerHarness;
use Logger\Tests\Support\PayloadStub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * End to end: a caller builds a payload, the record comes out of a destination
 * as one NDJSON line. These are the guarantees the README makes.
 */
final class PipelineTest extends TestCase
{
    /** @var LoggerHarness[] */
    private array $harnesses = [];

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->cleanUp();
        }

        $this->harnesses = [];
    }

    public function test_a_database_query_comes_out_as_a_fingerprinted_record(): void
    {
        $harness = $this->harness(['capture_fields' => ['db_query' => ['params' => ['tenant_id']]]]);

        $harness->logger()->log(DbQueryPayload::create('user_lookup', 'production_db')->withStatement("SELECT id FROM users WHERE email = 'marco@example.com' AND tenant = 7", ['tenant_id' => 7, 'email' => 'marco@example.com'])->withRowCount(1)->withDuration(0.0123)->withOutcome(Outcome::SUCCESS));

        $record = $harness->lastRecord();

        self::assertSame('db_query', $record['log_type']);
        self::assertSame('user_lookup', $record['event']);
        self::assertTrue($record['success']);
        self::assertSame(0.0123, $record['duration']);
        self::assertSame('SELECT id FROM users WHERE email = ? AND tenant = ?', $record['data']['statement']);
        self::assertSame('SELECT', $record['data']['operation']);
        self::assertSame(1, $record['data']['row_count']);
        self::assertSame(['tenant_id' => 7, '_omitted' => 1], $record['data']['params']);
    }

    public function test_an_interpolated_query_never_leaks_its_values(): void
    {
        $harness = $this->harness();

        $harness->logger()->log(DbQueryPayload::create('user_lookup', 'production_db')->withStatement("SELECT * FROM users WHERE email = 'marco@example.com' AND token = 'abc123secret'")->withSuccess());

        $line = $harness->lines()[0];

        self::assertStringNotContainsString('marco@example.com', $line);
        self::assertStringNotContainsString('abc123secret', $line);
    }

    public function test_an_outbound_call_comes_out_with_its_timing(): void
    {
        $harness = $this->harness(['capture_fields' => ['service_call' => ['timing' => ['dns', 'connect', 'ttfb', 'total']]]]);

        $harness->logger()->log(ServiceCallPayload::create('si_service_call', 'GET', 'https://api.example.com/v1/items?page=2')->withHttpCode(200)->withTiming(0.01, 0.02, 0.1, 0.25)->withDuration(0.25)->withSuccess());

        $record = $harness->lastRecord();

        self::assertSame('service_call', $record['log_type']);
        self::assertSame('GET', $record['data']['method']);
        self::assertSame(200, $record['data']['http_code']);
        self::assertSame('page=2', $record['data']['query_string']);
        self::assertSame(['dns' => 0.01, 'connect' => 0.02, 'ttfb' => 0.1, 'total' => 0.25], $record['data']['timing']);
    }

    public function test_a_secret_in_the_url_is_masked(): void
    {
        $harness = $this->harness();

        $harness->logger()->log(ServiceCallPayload::create('si_service_call', 'GET', 'https://api.example.com/v1/items?api_key=SUPERSECRET123&page=2')->withSuccess());

        self::assertSame('https://api.example.com/v1/items?api_key=***&page=2', $harness->lastRecord()['data']['url']);
    }

    /**
     * Pins a real leak. The query-string rule anchors on "?" or "&", but
     * ServiceCallPayload stores query_string as parse_url() returns it, with no
     * leading "?", so the FIRST parameter is never masked. The same secret is
     * correctly masked in "url" on the very same record.
     *
     * TODO(tech-debt): anchor the rule on a string start as well, or have the
     * payload keep the leading "?". Then flip this to assert the mask.
     */
    public function test_the_first_query_string_parameter_escapes_the_scrubber(): void
    {
        $harness = $this->harness();

        $harness->logger()->log(ServiceCallPayload::create('si_service_call', 'GET', 'https://api.example.com/v1/items?api_key=SUPERSECRET123&page=2')->withSuccess());

        self::assertSame('api_key=SUPERSECRET123&page=2', $harness->lastRecord()['data']['query_string']);
        self::assertStringContainsString('SUPERSECRET123', $harness->lines()[0]);
    }

    public function test_a_secret_after_the_first_query_parameter_is_masked(): void
    {
        $harness = $this->harness();

        $harness->logger()->log(ServiceCallPayload::create('si_service_call', 'GET', 'https://api.example.com/v1/items?page=2&api_key=SUPERSECRET123')->withSuccess());

        self::assertSame('page=2&api_key=***', $harness->lastRecord()['data']['query_string']);
    }

    public function test_an_uncaught_exception_comes_out_as_a_failure(): void
    {
        $harness = $this->harness(['routing' => []]);

        $harness->logger()->log(PhpLogPayload::fromThrowable('uncaught_exception', new RuntimeException('connection refused')));

        $record = $harness->lastRecord();

        self::assertSame('php_log', $record['log_type']);
        self::assertSame(Outcome::FAILURE, $record['outcome']);
        self::assertFalse($record['success']);
        self::assertSame('error', $record['level']);
        self::assertSame('connection refused', $record['error']['message']);
        self::assertSame(RuntimeException::class, $record['error']['class']);
        self::assertIsArray($record['error']['stack_trace']);
    }

    public function test_cookies_are_never_emitted_in_any_configuration(): void
    {
        $harness = $this->harness(['routing' => [], 'capture_fields' => ['php_log' => ['context' => ['cookie', 'route']]]]);

        $harness->logger()->log(PhpLogPayload::create('custom_log', 'checkout')->withContext(['cookie' => 'PHPSESSID=abcdef', 'route' => '/checkout']));

        $line = $harness->lines()[0];

        self::assertStringNotContainsString('PHPSESSID', $line);
        self::assertStringNotContainsString('abcdef', $line);
        self::assertSame(['route' => '/checkout', '_omitted' => 1], $harness->lastRecord()['data']['context']);
    }

    public function test_every_record_is_exactly_one_parseable_line(): void
    {
        $harness = $this->harness(['routing' => []]);

        $harness->logger()->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());
        $harness->logger()->log(PhpLogPayload::create('custom_log', "a message\nwith a newline"));
        $harness->logger()->log(ServiceCallPayload::create('call', 'GET', 'https://a.example')->withSuccess());

        $lines = $harness->lines();
        self::assertCount(3, $lines);

        foreach ($lines as $line) {
            self::assertSame(1, substr_count($line, "\n"), 'NDJSON means one record per line');
            self::assertIsArray(json_decode($line, true));
        }
    }

    public function test_the_level_floor_of_a_channel_is_applied(): void
    {
        $harness = $this->harness();

        // php_log is routed to "errors", whose floor is warning.
        $harness->logger()->log(PhpLogPayload::create('custom_log', 'chatty')->withLevel(Level::INFO));
        $harness->logger()->log(PhpLogPayload::create('custom_log', 'important')->withLevel(Level::ERROR));

        self::assertCount(1, $harness->lines('errors'));
        self::assertSame('important', $harness->lastRecord('errors')['data']['message']);
    }

    public function test_the_user_id_attached_after_authentication_reaches_the_records(): void
    {
        $harness = $this->harness(['pepper' => 's3cr3t']);

        $harness->logger()->log(DbQueryPayload::create('before_login', 'billing')->withSuccess());
        $harness->contextProvider()->setUserId('user-42');
        $harness->logger()->log(DbQueryPayload::create('after_login', 'billing')->withSuccess());

        $lines = $harness->lines();
        self::assertNull(json_decode($lines[0], true)['user_ref']);

        $userRef = json_decode($lines[1], true)['user_ref'];
        self::assertIsString($userRef);
        self::assertNotSame('user-42', $userRef, 'the raw identifier must never be emitted');
    }

    public function test_a_record_over_the_size_limit_is_cut_and_says_so(): void
    {
        $harness = $this->harness(['limits' => new \Logger\Config\LimitsConfig(700, 256, 20, 4), 'capture_fields' => ['service_call' => ['payload' => ['body']]]]);

        // Spaces matter: an unbroken alphanumeric run would be swallowed by the
        // scrubber base64 rule before the truncator ever saw it.
        $harness->logger()->log(ServiceCallPayload::create('call', 'POST', 'https://a.example')->withPayload(['body' => str_repeat('lorem ipsum ', 400)])->withSuccess());

        $record = $harness->lastRecord();

        self::assertTrue($record['_truncated']);
        self::assertLessThanOrEqual(700, strlen($harness->lines()[0]));
    }

    public function test_sampling_never_hides_a_failure(): void
    {
        $harness = $this->harness(['sampling' => ['db_query' => 0.0]]);

        $harness->logger()->log(DbQueryPayload::create('boring', 'billing')->withSuccess());
        $harness->logger()->log(DbQueryPayload::create('interesting', 'billing')->withOutcome(Outcome::FAILURE));

        $lines = $harness->lines();
        self::assertCount(1, $lines);
        self::assertSame('interesting', json_decode($lines[0], true)['event']);
    }

    public function test_an_unencodable_value_costs_its_field_not_the_whole_record(): void
    {
        $harness = $this->harness(['routing' => [], 'capture_fields' => ['php_log' => ['context' => ['ratio']]]]);

        // INF has no JSON representation. The truncator measures with the real
        // formatter, so it sees the record as oversized and sacrifices the
        // field that carries it.
        $harness->logger()->log(PhpLogPayload::create('custom_log', 'metrics')->withContext(['ratio' => INF]));

        $record = $harness->lastRecord();
        self::assertSame('metrics', $record['data']['message']);
        self::assertSame(['_omitted' => 1], $record['data']['context']);
        self::assertTrue($record['_truncated']);
        self::assertSame([], $harness->failSafeLines());
    }

    public function test_a_record_that_stays_unencodable_is_dropped_everywhere(): void
    {
        $harness = $this->harness(['routing' => []]);

        // Not one of the fields the truncator is allowed to sacrifice, so the
        // record can never be encoded. It must not reach any destination in a
        // degraded form: nothing partially encoded has been through the gate.
        $harness->logger()->log(new PayloadStub('php_log', 'custom_log', Outcome::UNKNOWN, Level::INFO, null, null, ['ratio' => INF]));

        self::assertSame([], $harness->lines());

        $failSafe = json_decode($harness->failSafeLines()[0], true);
        self::assertSame('pipeline_failure', $failSafe['reason']);
        self::assertSame('php_log', $failSafe['dropped_log_type']);
    }

    public function test_the_original_exception_survives_a_logging_failure(): void
    {
        $harness = $this->harness(['routing' => ['php_log' => 'dead']]);

        try {
            throw new RuntimeException('the real problem');
        } catch (RuntimeException $exception) {
            $harness->logger()->log(PhpLogPayload::fromThrowable('uncaught_exception', $exception));

            // The logger failed to write, and said so on its own channel, but
            // the caller still holds the exception it was reporting.
            self::assertSame('the real problem', $exception->getMessage());
        }

        self::assertNotSame([], $harness->failSafeLines());
    }

    /** @param array<string, mixed> $options */
    private function harness(array $options = []): LoggerHarness
    {
        $harness = LoggerHarness::create($options);
        $this->harnesses[] = $harness;

        return $harness;
    }
}
