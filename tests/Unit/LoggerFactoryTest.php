<?php

declare(strict_types=1);

namespace Logger\Tests\Unit;

use DateTimeImmutable;
use Logger\Config\Config;
use Logger\Context\MutableContextProvider;
use Logger\Context\Pseudonymizer;
use Logger\Context\RequestContext;
use Logger\Exception\InvalidConfigurationException;
use Logger\Handler\CircuitBreakerHandler;
use Logger\Handler\NullHandler;
use Logger\Interfaces\Context\ContextProviderInterface;
use Logger\Logger;
use Logger\LoggerFactory;
use Logger\Payload\DbQueryPayload;
use Logger\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class LoggerFactoryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'factory');
        self::assertIsString($path);
        $this->file = $path;
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function test_builds_a_logger_from_an_array(): void
    {
        self::assertInstanceOf(Logger::class, LoggerFactory::fromArray($this->config(), null, $this->clock()));
    }

    public function test_the_built_logger_writes_to_the_configured_target(): void
    {
        $logger = LoggerFactory::fromArray($this->config(), null, $this->clock());

        $logger->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());
        $logger->close();

        self::assertSame('user_lookup', $this->written()['event']);
    }

    public function test_the_service_and_env_come_from_the_configuration(): void
    {
        $logger = LoggerFactory::fromArray($this->config(), null, $this->clock());

        $logger->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());
        $logger->close();

        $record = $this->written();
        self::assertSame('billing-api', $record['service']);
        self::assertSame('testing', $record['env']);
    }

    public function test_an_explicit_host_is_written(): void
    {
        $logger = LoggerFactory::fromArray($this->config(['host' => 'web-01']), null, $this->clock());

        $logger->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());
        $logger->close();

        self::assertSame('web-01', $this->written()['host']);
    }

    public function test_the_host_falls_back_to_the_machine_name(): void
    {
        $logger = LoggerFactory::fromArray($this->config(), null, $this->clock());

        $logger->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());
        $logger->close();

        self::assertSame(gethostname() ?: null, $this->written()['host']);
    }

    public function test_routing_from_the_configuration_is_honoured(): void
    {
        $logger = LoggerFactory::fromArray($this->config(['routing' => ['db_query' => 'off']]), null, $this->clock());

        $logger->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());
        $logger->close();

        self::assertSame('', (string) file_get_contents($this->file), 'the off channel discards everything');
    }

    public function test_every_real_destination_is_wrapped_in_a_circuit_breaker(): void
    {
        $factory = $this->factory();
        $factory->build();

        // A destination that keeps failing must not be hammered, whatever the
        // configuration asked for.
        self::assertInstanceOf(CircuitBreakerHandler::class, $factory->handlers()['stdout'][0]);
    }

    public function test_the_null_destination_needs_no_breaker(): void
    {
        $factory = $this->factory();
        $factory->build();

        // It discards everything and cannot fail, so wrapping it would only
        // add a layer with nothing to protect.
        self::assertInstanceOf(NullHandler::class, $factory->handlers()['off'][0]);
    }

    public function test_handlers_are_exposed_per_channel(): void
    {
        $factory = $this->factory();
        $factory->build();

        self::assertSame(['stdout', 'off'], array_keys($factory->handlers()));
        self::assertCount(1, $factory->handlers()['stdout']);
    }

    public function test_handlers_are_only_populated_once_the_logger_is_built(): void
    {
        self::assertSame([], $this->factory()->handlers());
    }

    public function test_exposes_a_context_provider_so_the_user_id_can_be_attached_later(): void
    {
        self::assertInstanceOf(ContextProviderInterface::class, $this->factory()->contextProvider());
    }

    public function test_the_default_context_provider_is_built_from_the_server_environment(): void
    {
        // A request id is always present so records can be correlated even
        // when the caller sent no header.
        self::assertNotNull($this->factory()->contextProvider()->current()->requestId());
    }

    public function test_an_injected_context_provider_is_used_as_is(): void
    {
        $provider = new MutableContextProvider(new RequestContext('req_injected'), new Pseudonymizer(''));

        $factory = new LoggerFactory(Config::fromArray($this->config()), $provider);

        self::assertSame($provider, $factory->contextProvider());
        self::assertSame('req_injected', $factory->contextProvider()->current()->requestId());
    }

    public function test_the_injected_context_reaches_the_record(): void
    {
        $provider = new MutableContextProvider(new RequestContext('req_injected'), new Pseudonymizer(''));

        $logger = (new LoggerFactory(Config::fromArray($this->config()), $provider, $this->clock()))->build();
        $logger->log(DbQueryPayload::create('user_lookup', 'billing')->withSuccess());
        $logger->close();

        self::assertSame('req_injected', $this->written()['request_id']);
    }

    public function test_an_unknown_handler_type_can_never_reach_the_factory(): void
    {
        // ChannelConfig refuses it first, which is what keeps the factory's
        // fallback branch unreachable from configuration.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('unknown handler type "syslog"');

        Config::fromArray($this->config(['channels' => ['stdout' => ['handlers' => [['type' => 'syslog']]]]]));
    }

    private function factory(): LoggerFactory
    {
        return new LoggerFactory(Config::fromArray($this->config()), null, $this->clock());
    }

    private function clock(): FixedClock
    {
        return new FixedClock(new DateTimeImmutable('2024-03-01T10:20:30+00:00'), 1000.0);
    }

    /** @return array<string, mixed> */
    private function written(): array
    {
        $contents = rtrim((string) file_get_contents($this->file), "\n");
        self::assertNotSame('', $contents, 'nothing was written to the target');

        $decoded = json_decode(explode("\n", $contents)[0], true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'service' => 'billing-api',
            'env' => 'testing',
            'default_channel' => 'stdout',
            'channels' => [
                'stdout' => ['handlers' => [['type' => 'stream', 'target' => $this->file]]],
                'off' => ['handlers' => [['type' => 'null']]],
            ],
        ], $overrides);
    }
}
