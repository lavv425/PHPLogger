<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Config;

use Logger\Config\ChannelConfig;
use Logger\Enum\Level;
use Logger\Exception\InvalidConfigurationException;
use PHPUnit\Framework\TestCase;

final class ChannelConfigTest extends TestCase
{
    public function test_defaults_the_floor_to_debug(): void
    {
        $channel = ChannelConfig::fromArray('stdout', ['handlers' => [['type' => 'null']]]);

        self::assertSame('stdout', $channel->name());
        self::assertSame(Level::DEBUG, $channel->minLevel());
    }

    public function test_normalises_a_stream_handler_with_its_defaults(): void
    {
        $channel = ChannelConfig::fromArray('stdout', ['handlers' => [['type' => 'stream', 'target' => 'php://stdout']]]);

        self::assertSame([['type' => 'stream', 'target' => 'php://stdout', 'locking' => false]], $channel->handlers());
    }

    public function test_keeps_explicit_locking(): void
    {
        $channel = ChannelConfig::fromArray('errors', ['handlers' => [['type' => 'stream', 'target' => '/tmp/a.ndjson', 'locking' => true]]]);

        self::assertTrue($channel->handlers()[0]['locking']);
    }

    /** @dataProvider simpleHandlers */
    public function test_accepts_the_handlers_that_need_no_target(string $type): void
    {
        $channel = ChannelConfig::fromArray('c', ['handlers' => [['type' => $type]]]);

        self::assertSame([['type' => $type]], $channel->handlers());
    }

    /** @return array<string, array{string}> */
    public function simpleHandlers(): array
    {
        return [
            'error_log' => [ChannelConfig::HANDLER_ERROR_LOG],
            'null' => [ChannelConfig::HANDLER_NULL],
            'memory' => [ChannelConfig::HANDLER_MEMORY],
        ];
    }

    public function test_keeps_several_handlers_in_order(): void
    {
        $channel = ChannelConfig::fromArray('errors', ['handlers' => [['type' => 'stream', 'target' => 'php://stdout'], ['type' => 'null']]]);

        self::assertCount(2, $channel->handlers());
        self::assertSame('stream', $channel->handlers()[0]['type']);
        self::assertSame('null', $channel->handlers()[1]['type']);
    }

    /** @dataProvider brokenChannels */
    public function test_refuses_a_broken_channel(array $definition, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedMessage);

        ChannelConfig::fromArray('stdout', $definition);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public function brokenChannels(): array
    {
        return [
            'unknown level' => [['min_level' => 'verbose', 'handlers' => [['type' => 'null']]], 'channels.stdout.min_level'],
            'level is not a string' => [['min_level' => 400, 'handlers' => [['type' => 'null']]], 'channels.stdout.min_level'],
            'no handlers' => [['handlers' => []], 'channels.stdout.handlers'],
            'handlers missing' => [[], 'channels.stdout.handlers'],
            'handler is not an array' => [['handlers' => ['stream']], 'channels.stdout.handlers.0'],
            'handler without a type' => [['handlers' => [[]]], 'channels.stdout.handlers.0.type'],
            'unknown handler type' => [['handlers' => [['type' => 'syslog']]], 'unknown handler type "syslog"'],
            'stream without a target' => [['handlers' => [['type' => 'stream']]], 'channels.stdout.handlers.0.target'],
            'stream with an empty target' => [['handlers' => [['type' => 'stream', 'target' => '']]], 'channels.stdout.handlers.0.target'],
            'non boolean locking' => [['handlers' => [['type' => 'stream', 'target' => 'php://stdout', 'locking' => 1]]], 'channels.stdout.handlers.0.locking'],
        ];
    }
}
