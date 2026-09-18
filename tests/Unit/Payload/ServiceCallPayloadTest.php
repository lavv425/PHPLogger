<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Payload;

use Logger\Enum\LogType;
use Logger\Enum\Outcome;
use Logger\Exception\InvalidLogEventException;
use Logger\Payload\ServiceCallPayload;
use PHPUnit\Framework\TestCase;

final class ServiceCallPayloadTest extends TestCase
{
    public function test_renders_the_type_specific_fields(): void
    {
        $payload = ServiceCallPayload::create('si_service_call', 'GET', 'https://api.example.com/v1/items?page=2');

        self::assertSame(LogType::SERVICE_CALL, $payload->logType());
        self::assertSame([
            'method' => 'GET',
            'url' => 'https://api.example.com/v1/items?page=2',
            'query_string' => 'page=2',
            'http_code' => null,
            'payload' => null,
            'timing' => null,
        ], $payload->data());
    }

    public function test_the_method_is_normalised(): void
    {
        $payload = ServiceCallPayload::create('call', ' post ', 'https://api.example.com');

        self::assertSame('POST', $payload->data()['method']);
    }

    public function test_an_unsupported_method_is_refused(): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('unknown HTTP method "TRACE"');

        ServiceCallPayload::create('call', 'TRACE', 'https://api.example.com');
    }

    public function test_a_url_without_a_query_string_reports_null(): void
    {
        self::assertNull(ServiceCallPayload::create('call', 'GET', 'https://api.example.com/v1/items')->data()['query_string']);
    }

    public function test_an_empty_query_string_reports_null(): void
    {
        self::assertNull(ServiceCallPayload::create('call', 'GET', 'https://api.example.com/v1?')->data()['query_string']);
    }

    public function test_an_empty_url_is_refused(): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('"url"');

        ServiceCallPayload::create('call', 'GET', '  ');
    }

    public function test_records_the_http_code(): void
    {
        self::assertSame(503, ServiceCallPayload::create('call', 'GET', 'https://a.example')->withHttpCode(503)->data()['http_code']);
    }

    public function test_records_the_timing_breakdown(): void
    {
        $payload = ServiceCallPayload::create('call', 'GET', 'https://a.example')->withTiming(0.01, 0.02, 0.1, 0.25);

        self::assertSame(['dns' => 0.01, 'connect' => 0.02, 'ttfb' => 0.1, 'total' => 0.25], $payload->data()['timing']);
    }

    public function test_a_partial_timing_breakdown_is_allowed(): void
    {
        $payload = ServiceCallPayload::create('call', 'GET', 'https://a.example')->withTiming(null, null, null, 0.25);

        self::assertSame(['dns' => null, 'connect' => null, 'ttfb' => null, 'total' => 0.25], $payload->data()['timing']);
    }

    /** @dataProvider acceptablePayloads */
    public function test_the_body_can_be_an_array_a_string_or_nothing($body): void
    {
        self::assertSame($body, ServiceCallPayload::create('call', 'POST', 'https://a.example')->withPayload($body)->data()['payload']);
    }

    /** @return array<string, array{mixed}> */
    public function acceptablePayloads(): array
    {
        return [
            'array' => [['id' => 7]],
            'string' => ['{"id":7}'],
            'null' => [null],
        ];
    }

    /** @dataProvider unacceptablePayloads */
    public function test_any_other_body_type_is_refused($body): void
    {
        $this->expectException(InvalidLogEventException::class);
        $this->expectExceptionMessage('must be an array, a string or null');

        ServiceCallPayload::create('call', 'POST', 'https://a.example')->withPayload($body);
    }

    /** @return array<string, array{mixed}> */
    public function unacceptablePayloads(): array
    {
        return [
            'integer' => [42],
            'object' => [new \stdClass()],
            'boolean' => [true],
        ];
    }

    public function test_the_success_shortcut_sets_the_outcome(): void
    {
        self::assertSame(Outcome::SUCCESS, ServiceCallPayload::create('call', 'GET', 'https://a.example')->withSuccess()->outcome());
    }

    public function test_with_methods_return_copies(): void
    {
        $original = ServiceCallPayload::create('call', 'GET', 'https://a.example');

        $modified = $original->withHttpCode(200)->withPayload('body')->withTiming(1.0, 1.0, 1.0, 1.0);

        self::assertNull($original->data()['http_code']);
        self::assertNull($original->data()['payload']);
        self::assertNull($original->data()['timing']);
        self::assertSame(200, $modified->data()['http_code']);
    }
}
