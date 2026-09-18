<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Adapter;

use Logger\Adapter\Curl\CurlRecorder;
use Logger\Adapter\Curl\StatusHttpOutcomePolicy;
use Logger\Enum\ErrorType;
use Logger\Enum\Outcome;
use Logger\Interfaces\Adapter\HttpOutcomePolicyInterface;
use Logger\Tests\Support\LoggerHarness;
use PHPUnit\Framework\TestCase;

final class CurlRecorderTest extends TestCase
{
    private LoggerHarness $harness;
    private string $file;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('ext-curl is required to exercise the adapter against real handles.');
        }
    }

    protected function setUp(): void
    {
        $this->harness = LoggerHarness::create(['capture_fields' => ['service_call' => ['timing' => ['dns', 'connect', 'ttfb', 'total']]]]);

        $path = tempnam(sys_get_temp_dir(), 'curl');
        self::assertIsString($path);
        file_put_contents($path, 'corpo della risposta');
        $this->file = $path;
    }

    protected function tearDown(): void
    {
        $this->harness->cleanUp();

        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function test_execute_returns_the_body_and_logs_the_call(): void
    {
        $recorder = new CurlRecorder($this->harness->logger());
        $handle = $this->localHandle();

        $body = $recorder->execute($handle, 'GET');
        curl_close($handle);

        self::assertSame('corpo della risposta', $body, 'execute() is a drop-in for curl_exec()');

        $record = $this->harness->lastRecord();
        self::assertSame('service_call', $record['log_type']);
        self::assertSame('service_call', $record['event']);
        self::assertSame('GET', $record['data']['method']);
        self::assertStringContainsString('file://', $record['data']['url']);
    }

    public function test_the_timing_breakdown_comes_from_curl_itself(): void
    {
        $recorder = new CurlRecorder($this->harness->logger());
        $handle = $this->localHandle();
        $recorder->execute($handle);
        curl_close($handle);

        // The caller measures nothing: curl_getinfo already carries every
        // field the envelope declares.
        $timing = $this->harness->lastRecord()['data']['timing'];
        self::assertArrayHasKey('total', $timing);
        self::assertIsFloat($timing['total']);
        self::assertGreaterThanOrEqual(0.0, $timing['total']);
        self::assertIsFloat($this->harness->lastRecord()['duration']);
    }

    public function test_a_transport_failure_is_described_as_such(): void
    {
        $recorder = new CurlRecorder($this->harness->logger());
        $handle = curl_init('http://127.0.0.1:9/nulla');
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 2);

        $recorder->execute($handle, 'GET');
        curl_close($handle);

        $record = $this->harness->lastRecord();
        self::assertSame(Outcome::FAILURE, $record['outcome']);
        self::assertFalse($record['success']);
        self::assertSame('error', $record['level']);
        self::assertContains($record['error']['type'], [ErrorType::CURL_ERROR, ErrorType::TIMEOUT]);
        self::assertNotSame('', (string) $record['error']['message']);
    }

    public function test_a_transfer_without_a_status_is_not_called_a_success(): void
    {
        // A file:// transfer works but reports no HTTP status; claiming success
        // would be asserting something that was never observed.
        $recorder = new CurlRecorder($this->harness->logger());
        $handle = $this->localHandle();
        $recorder->execute($handle);
        curl_close($handle);

        $record = $this->harness->lastRecord();
        self::assertSame(Outcome::UNKNOWN, $record['outcome']);
        self::assertNull($record['success']);
        self::assertNull($record['error']);
    }

    public function test_the_outcome_policy_is_injectable(): void
    {
        $always = new class () implements HttpOutcomePolicyInterface {
            public function decide(int $errno, int $httpCode): string
            {
                return Outcome::SUCCESS;
            }
        };

        $recorder = new CurlRecorder($this->harness->logger(), $always);
        $handle = curl_init('http://127.0.0.1:9/nulla');
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 2);
        $recorder->execute($handle);
        curl_close($handle);

        $record = $this->harness->lastRecord();
        self::assertSame(Outcome::SUCCESS, $record['outcome'], 'the policy decides, not the adapter');
        self::assertNotNull($record['error'], 'the transport error is still described');
    }

    public function test_the_event_can_be_named_per_call(): void
    {
        $recorder = new CurlRecorder($this->harness->logger(), new StatusHttpOutcomePolicy(), 'si_service_call');
        $handle = $this->localHandle();
        $recorder->execute($handle, 'GET', 'billing.invoice.fetch');
        curl_close($handle);

        self::assertSame('billing.invoice.fetch', $this->harness->lastRecord()['event']);
    }

    public function test_the_default_event_is_used_when_none_is_given(): void
    {
        $recorder = new CurlRecorder($this->harness->logger(), new StatusHttpOutcomePolicy(), 'si_service_call');
        $handle = $this->localHandle();
        $recorder->execute($handle);
        curl_close($handle);

        self::assertSame('si_service_call', $this->harness->lastRecord()['event']);
    }

    public function test_record_describes_a_transfer_the_caller_already_ran(): void
    {
        // The path for curl_multi, where the adapter cannot own the exec call.
        $recorder = new CurlRecorder($this->harness->logger());
        $handle = $this->localHandle();
        curl_exec($handle);

        $recorder->record($handle, 'POST');
        curl_close($handle);

        self::assertSame('POST', $this->harness->lastRecord()['data']['method']);
    }

    public function test_a_logging_failure_never_reaches_the_caller(): void
    {
        $harness = LoggerHarness::create(['routing' => ['service_call' => 'dead']]);
        $recorder = new CurlRecorder($harness->logger());
        $handle = $this->localHandle();

        $body = $recorder->execute($handle);
        curl_close($handle);

        self::assertSame('corpo della risposta', $body, 'the transfer result is returned regardless');
        $harness->cleanUp();
    }

    /** @return resource|\CurlHandle */
    private function localHandle()
    {
        $handle = curl_init('file://' . $this->file);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);

        return $handle;
    }
}
