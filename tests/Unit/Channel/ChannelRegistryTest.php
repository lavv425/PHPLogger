<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Channel;

use Logger\Channel\Channel;
use Logger\Channel\ChannelRegistry;
use Logger\Enum\Level;
use Logger\Exception\InvalidConfigurationException;
use Logger\Tests\Support\FailingHandler;
use PHPUnit\Framework\TestCase;

final class ChannelRegistryTest extends TestCase
{
    public function test_resolves_a_log_type_through_the_routing_table(): void
    {
        $registry = $this->registry();

        self::assertSame('errors', $registry->resolve('php_log')->name());
        self::assertSame('stdout', $registry->resolve('db_query')->name());
    }

    public function test_an_unrouted_log_type_falls_back_to_the_default_channel(): void
    {
        self::assertSame('stdout', $this->registry()->resolve('queue_job')->name());
    }

    public function test_reports_which_channels_exist(): void
    {
        $registry = $this->registry();

        self::assertTrue($registry->has('errors'));
        self::assertFalse($registry->has('audit'));
    }

    public function test_getting_an_unknown_channel_is_a_configuration_error(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('unknown channel "audit"');

        $this->registry()->get('audit');
    }

    public function test_a_default_channel_that_does_not_exist_is_refused_at_construction(): void
    {
        // A misconfigured logger must stop the application before it starts
        // serving traffic, not on the first record.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('default_channel');

        new ChannelRegistry(['stdout' => $this->channel('stdout')], [], 'missing');
    }

    public function test_close_all_closes_every_channel(): void
    {
        $first = new FailingHandler();
        $second = new FailingHandler();

        $registry = new ChannelRegistry([
            'stdout' => new Channel('stdout', Level::DEBUG, [], [$first]),
            'errors' => new Channel('errors', Level::DEBUG, [], [$second]),
        ], [], 'stdout');

        $registry->closeAll();

        self::assertTrue($first->isClosed());
        self::assertTrue($second->isClosed());
    }

    private function registry(): ChannelRegistry
    {
        return new ChannelRegistry([
            'stdout' => $this->channel('stdout'),
            'errors' => $this->channel('errors'),
        ], ['php_log' => 'errors', 'db_query' => 'stdout'], 'stdout');
    }

    private function channel(string $name): Channel
    {
        return new Channel($name, Level::DEBUG, [], []);
    }
}
