<?php

declare(strict_types=1);

namespace Logger\Context;

/**
 * Correlation fields shared by every record.
 *
 * session_ref and user_ref are pseudonyms, never the real identifiers: a PHP
 * session id is a credential, and a log line is not the place for one.
 */
final class RequestContext
{
    private ?string $requestId;
    private ?string $sessionRef;
    private ?string $userRef;
    private ?string $userAgent;

    public function __construct(?string $requestId = null, ?string $sessionRef = null, ?string $userRef = null, ?string $userAgent = null)
    {
        $this->requestId = $requestId;
        $this->sessionRef = $sessionRef;
        $this->userRef = $userRef;
        $this->userAgent = $userAgent;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function sessionRef(): ?string
    {
        return $this->sessionRef;
    }

    public function userRef(): ?string
    {
        return $this->userRef;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function withUserRef(?string $userRef): self
    {
        $clone = clone $this;
        $clone->userRef = $userRef;

        return $clone;
    }

    public function withSessionRef(?string $sessionRef): self
    {
        $clone = clone $this;
        $clone->sessionRef = $sessionRef;

        return $clone;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'session_ref' => $this->sessionRef,
            'user_ref' => $this->userRef,
            'user_agent' => $this->userAgent,
        ];
    }
}
