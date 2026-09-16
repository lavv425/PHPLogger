<?php

declare(strict_types=1);

namespace Logger\Context;

/**
 * Holds the context for the current request. Mutable on purpose: the user id is
 * only known after authentication, well after the logger is built.
 */
final class MutableContextProvider implements ContextProvider
{
    private RequestContext $context;
    private Pseudonymizer $pseudonymizer;

    public function __construct(RequestContext $context, Pseudonymizer $pseudonymizer)
    {
        $this->context = $context;
        $this->pseudonymizer = $pseudonymizer;
    }

    public function current(): RequestContext
    {
        return $this->context;
    }

    public function replace(RequestContext $context): void
    {
        $this->context = $context;
    }

    /** Stores the pseudonym of the user id, never the id itself. */
    public function setUserId(?string $userId): void
    {
        $this->context = $this->context->withUserRef($this->pseudonymizer->pseudonymize($userId));
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->context = $this->context->withSessionRef($this->pseudonymizer->pseudonymize($sessionId));
    }
}
