<?php

declare(strict_types=1);

namespace Logger\Context;

use Exception;

/**
 * Builds the request context from the server environment.
 *
 * The incoming request id header is attacker-controlled: it is accepted only if
 * it matches a strict charset and length, otherwise a fresh one is generated.
 */
final class ServerContextFactory
{
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';
    private const MAX_USER_AGENT_LENGTH = 256;

    private Pseudonymizer $pseudonymizer;
    private string $requestIdHeader;

    public function __construct(Pseudonymizer $pseudonymizer, string $requestIdHeader = 'HTTP_X_REQUEST_ID')
    {
        $this->pseudonymizer = $pseudonymizer;
        $this->requestIdHeader = $requestIdHeader;
    }

    /**
     * @param array<string, mixed> $server usually $_SERVER
     */
    public function create(array $server, ?string $sessionId = null, ?string $userId = null): RequestContext
    {
        $userAgent = $server['HTTP_USER_AGENT'] ?? null;

        return new RequestContext(
            $this->resolveRequestId($server),
            $this->pseudonymizer->pseudonymize($sessionId),
            $this->pseudonymizer->pseudonymize($userId),
            is_string($userAgent) ? substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH) : null
        );
    }

    public static function generateRequestId(): string
    {
        try {
            return 'req_' . bin2hex(random_bytes(8));
        } catch (Exception $e) {
            // random_bytes can only fail when no entropy source exists; a weaker
            // id is still better than no correlation at all.
            return 'req_' . substr(hash('sha256', uniqid('', true)), 0, 16);
        }
    }

    /** @param array<string, mixed> $server */
    private function resolveRequestId(array $server): string
    {
        $incoming = $server[$this->requestIdHeader] ?? null;

        if (is_string($incoming) && preg_match(self::REQUEST_ID_PATTERN, $incoming) === 1) {
            return $incoming;
        }

        return self::generateRequestId();
    }
}
