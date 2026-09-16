<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Context;

use Logger\Context\MutableContextProvider;
use Logger\Context\Pseudonymizer;
use Logger\Context\RequestContext;
use Logger\Interfaces\Context\ContextProviderInterface;
use PHPUnit\Framework\TestCase;

final class RequestContextTest extends TestCase
{
    public function test_renders_the_four_correlation_fields(): void
    {
        $context = new RequestContext('req_abc', 'sess_ref', 'user_ref', 'Mozilla/5.0');

        self::assertSame([
            'request_id' => 'req_abc',
            'session_ref' => 'sess_ref',
            'user_ref' => 'user_ref',
            'user_agent' => 'Mozilla/5.0',
        ], $context->toArray());
    }

    public function test_every_field_is_optional(): void
    {
        $context = new RequestContext();

        self::assertNull($context->requestId());
        self::assertNull($context->sessionRef());
        self::assertNull($context->userRef());
        self::assertNull($context->userAgent());
    }

    public function test_with_methods_return_copies(): void
    {
        $original = new RequestContext('req_abc');

        $modified = $original->withUserRef('u1')->withSessionRef('s1');

        self::assertNull($original->userRef());
        self::assertNull($original->sessionRef());
        self::assertSame('u1', $modified->userRef());
        self::assertSame('s1', $modified->sessionRef());
        self::assertSame('req_abc', $modified->requestId(), 'the rest of the context is carried over');
    }

    public function test_the_provider_returns_the_current_context(): void
    {
        $provider = new MutableContextProvider(new RequestContext('req_abc'), new Pseudonymizer('s3cr3t'));

        self::assertInstanceOf(ContextProviderInterface::class, $provider);
        self::assertSame('req_abc', $provider->current()->requestId());
    }

    public function test_the_user_id_is_stored_as_a_pseudonym_never_as_itself(): void
    {
        $provider = new MutableContextProvider(new RequestContext('req_abc'), new Pseudonymizer('s3cr3t'));

        $provider->setUserId('user-42');

        $userRef = $provider->current()->userRef();
        self::assertIsString($userRef);
        self::assertNotSame('user-42', $userRef);
        self::assertSame((new Pseudonymizer('s3cr3t'))->pseudonymize('user-42'), $userRef);
    }

    public function test_the_session_id_is_stored_as_a_pseudonym_never_as_itself(): void
    {
        $provider = new MutableContextProvider(new RequestContext('req_abc'), new Pseudonymizer('s3cr3t'));

        $provider->setSessionId('abcdef0123456789');

        // A PHP session id is a credential, and a log line is not the place
        // for one.
        self::assertNotSame('abcdef0123456789', $provider->current()->sessionRef());
        self::assertIsString($provider->current()->sessionRef());
    }

    public function test_without_a_pepper_the_identifiers_are_simply_absent(): void
    {
        $provider = new MutableContextProvider(new RequestContext('req_abc'), new Pseudonymizer(''));

        $provider->setUserId('user-42');
        $provider->setSessionId('abcdef0123456789');

        self::assertNull($provider->current()->userRef());
        self::assertNull($provider->current()->sessionRef());
    }

    public function test_the_whole_context_can_be_replaced(): void
    {
        $provider = new MutableContextProvider(new RequestContext('req_abc'), new Pseudonymizer('s3cr3t'));

        $provider->replace(new RequestContext('req_xyz', null, null, 'curl/8'));

        self::assertSame('req_xyz', $provider->current()->requestId());
        self::assertSame('curl/8', $provider->current()->userAgent());
    }
}
