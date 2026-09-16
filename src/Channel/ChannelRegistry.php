<?php

declare(strict_types=1);

namespace Logger\Channel;

use Logger\Exception\InvalidConfigurationException;

final class ChannelRegistry
{
    /** @var array<string, Channel> */
    private array $channels;
    /** @var array<string, string> log type => channel name */
    private array $routing;
    private string $defaultChannel;

    /**
     * @param array<string, Channel> $channels
     * @param array<string, string> $routing
     */
    public function __construct(array $channels, array $routing, string $defaultChannel)
    {
        if (!isset($channels[$defaultChannel])) {
            throw InvalidConfigurationException::forKey('default_channel', 'unknown channel "' . $defaultChannel . '"');
        }

        $this->channels = $channels;
        $this->routing = $routing;
        $this->defaultChannel = $defaultChannel;
    }

    public function has(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    public function get(string $name): Channel
    {
        if (!isset($this->channels[$name])) {
            throw InvalidConfigurationException::forKey('channels', 'unknown channel "' . $name . '"');
        }

        return $this->channels[$name];
    }

    public function resolve(string $logType): Channel
    {
        return $this->get($this->routing[$logType] ?? $this->defaultChannel);
    }

    public function closeAll(): void
    {
        foreach ($this->channels as $channel) {
            $channel->close();
        }
    }
}
