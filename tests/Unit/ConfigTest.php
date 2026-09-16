<?php

declare(strict_types=1);

namespace PhpLoggerTests\Unit;

use PhpLogger\Config\Config;
use PhpLogger\Exception\InvalidConfigurationException;
use PhpLogger\Logger;
use PhpLogger\LoggerFactory;
use PhpLogger\Payload\DbQueryPayload;
use PhpLoggerTests\Support\Harness;
use PhpLoggerTests\TestCase;

final class ConfigTest extends TestCase
{
    public function testValidConfigurationBuildsALogger(): void
    {
        $logger = LoggerFactory::fromArray(Harness::baseConfigArray());

        $this->assertTrue($logger instanceof Logger);
    }

    public function testMissingServiceIsRejectedAtBoot(): void
    {
        $config = Harness::baseConfigArray();
        unset($config['service']);

        $this->assertThrows(InvalidConfigurationException::class, static function () use ($config): void {
            Config::fromArray($config);
        });
    }

    public function testRoutingToAnUnknownChannelIsRejected(): void
    {
        $config = Harness::baseConfigArray();
        $config['routing'] = ['db_query' => 'nowhere'];

        $exception = $this->assertThrows(InvalidConfigurationException::class, static function () use ($config): void {
            Config::fromArray($config);
        });

        $this->assertStringContains('routing.db_query', $exception->getMessage());
    }

    public function testUnknownHandlerTypeIsRejected(): void
    {
        $config = Harness::baseConfigArray();
        $config['channels']['memory']['handlers'] = [['type' => 'carrier-pigeon']];

        $this->assertThrows(InvalidConfigurationException::class, static function () use ($config): void {
            Config::fromArray($config);
        });
    }

    public function testStreamHandlerRequiresATarget(): void
    {
        $config = Harness::baseConfigArray();
        $config['channels']['memory']['handlers'] = [['type' => 'stream']];

        $this->assertThrows(InvalidConfigurationException::class, static function () use ($config): void {
            Config::fromArray($config);
        });
    }

    public function testSamplingRateMustBeARatio(): void
    {
        $config = Harness::baseConfigArray();
        $config['sampling'] = ['db_query' => 12];

        $this->assertThrows(InvalidConfigurationException::class, static function () use ($config): void {
            Config::fromArray($config);
        });
    }

    public function testCaptureAllowListIsValidated(): void
    {
        $config = Harness::baseConfigArray();
        $config['capture'] = ['fields' => ['db_query' => ['params' => 'everything']]];

        $this->assertThrows(InvalidConfigurationException::class, static function () use ($config): void {
            Config::fromArray($config);
        });
    }

    public function testDefaultsAreApplied(): void
    {
        $config = Config::fromArray(Harness::baseConfigArray());

        $this->assertSame(4096, $config->limits()->maxRecordBytes());
        $this->assertFalse($config->capture()->isRawStatementEnabled());
        $this->assertSame([], $config->capture()->allowedKeys('db_query', 'params'));
        $this->assertFalse($config->strictEvents());
        $this->assertSame('php://stderr', $config->failSafeTarget());
    }

    public function testFactoryWiringWritesToTheConfiguredTarget(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phplogger');

        $config = Harness::baseConfigArray();
        $config['channels'] = ['file' => ['handlers' => [['type' => 'stream', 'target' => $path]]]];
        $config['default_channel'] = 'file';
        $config['routing'] = ['db_query' => 'file'];

        $logger = LoggerFactory::fromArray($config);
        $logger->log(
            DbQueryPayload::create('user_lookup', 'production_db')
                ->withStatement("SELECT * FROM users WHERE email = 'mario@example.com'")
        );
        $logger->close();

        $written = (string) file_get_contents($path);
        unlink($path);

        $record = json_decode(trim($written), true);

        $this->assertSame('db_query', $record['log_type']);
        $this->assertSame(Harness::SERVICE, $record['service']);
        $this->assertSame('SELECT * FROM users WHERE email = ?', $record['data']['statement']);
        // No pepper configured: references are absent rather than raw.
        $this->assertNull($record['session_ref']);
        $this->assertStringNotContains('mario@example.com', $written);
    }

    public function testExampleConfigurationIsValid(): void
    {
        $config = require __DIR__ . '/../../config/logger.example.php';

        $this->assertDoesNotThrow(static function () use ($config): void {
            Config::fromArray($config);
        });
    }
}
