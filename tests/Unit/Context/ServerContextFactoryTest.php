<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Context;

use Logger\Context\Pseudonymizer;
use Logger\Context\ServerContextFactory;
use PHPUnit\Framework\TestCase;

final class ServerContextFactoryTest extends TestCase
{
    public function test_accepts_a_well_formed_incoming_request_id(): void
    {
        $context = $this->factory()->create(['HTTP_X_REQUEST_ID' => 'req_abc-123.45:67']);

        self::assertSame('req_abc-123.45:67', $context->requestId());
    }

    /** @dataProvider hostileRequestIds */
    public function test_rejects_an_attacker_controlled_request_id(string $incoming): void
    {
        // The header is attacker controlled: anything outside the strict
        // charset is replaced rather than propagated into the logs.
        $context = $this->factory()->create(['HTTP_X_REQUEST_ID' => $incoming]);

        self::assertNotSame($incoming, $context->requestId());
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{16}$/', (string) $context->requestId());
    }

    /** @return array<string, array{string}> */
    public function hostileRequestIds(): array
    {
        return [
            'newline injection' => ["req_abc\nlevel=critical"],
            'json injection' => ['{"level":"critical"}'],
            'whitespace' => ['req abc'],
            'too long' => [str_repeat('a', 65)],
            'empty' => [''],
            'slash' => ['req/abc'],
        ];
    }

    public function test_generates_a_request_id_when_the_header_is_absent(): void
    {
        self::assertMatchesRegularExpression('/^req_[0-9a-f]{16}$/', (string) $this->factory()->create([])->requestId());
    }

    public function test_generated_request_ids_are_unique(): void
    {
        self::assertNotSame(ServerContextFactory::generateRequestId(), ServerContextFactory::generateRequestId());
    }

    public function test_the_header_name_is_configurable(): void
    {
        $factory = new ServerContextFactory(new Pseudonymizer('s3cr3t'), 'HTTP_X_CORRELATION_ID');

        self::assertSame('corr-1', $factory->create(['HTTP_X_CORRELATION_ID' => 'corr-1'])->requestId());
    }

    public function test_keeps_the_user_agent(): void
    {
        $context = $this->factory()->create(['HTTP_USER_AGENT' => 'curl/8.4.0']);

        self::assertSame('curl/8.4.0', $context->userAgent());
    }

    public function test_caps_an_oversized_user_agent(): void
    {
        $context = $this->factory()->create(['HTTP_USER_AGENT' => str_repeat('a', 400)]);

        self::assertSame(256, strlen((string) $context->userAgent()));
    }

    public function test_a_missing_or_non_string_user_agent_is_null(): void
    {
        self::assertNull($this->factory()->create([])->userAgent());
        self::assertNull($this->factory()->create(['HTTP_USER_AGENT' => ['array']])->userAgent());
    }

    public function test_pseudonymizes_the_session_and_user_identifiers(): void
    {
        $context = $this->factory()->create([], 'abcdef0123456789', 'user-42');

        self::assertNotSame('abcdef0123456789', $context->sessionRef());
        self::assertNotSame('user-42', $context->userRef());
        self::assertIsString($context->sessionRef());
        self::assertIsString($context->userRef());
    }

    public function test_without_a_pepper_no_identifier_is_emitted(): void
    {
        $factory = new ServerContextFactory(new Pseudonymizer(''));

        $context = $factory->create([], 'abcdef0123456789', 'user-42');

        self::assertNull($context->sessionRef());
        self::assertNull($context->userRef());
    }

    private function factory(): ServerContextFactory
    {
        return new ServerContextFactory(new Pseudonymizer('s3cr3t'));
    }
}
