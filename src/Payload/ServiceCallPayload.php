<?php

declare(strict_types=1);

namespace PhpLogger\Payload;

use PhpLogger\Enum\HttpMethod;
use PhpLogger\Enum\LogType;
use PhpLogger\Exception\InvalidLogEventException;
use PhpLogger\Support\Assert;

/**
 * Outbound HTTP call.
 */
final class ServiceCallPayload extends AbstractPayload
{
    private string $method;
    private string $url;
    private ?string $queryString;
    private ?int $httpCode = null;
    /** @var array<string, mixed>|string|null */
    private $payload = null;
    /** @var array<string, float|null>|null */
    private ?array $timing = null;

    private function __construct(string $event, string $method, string $url)
    {
        parent::__construct($event);

        $normalized = HttpMethod::normalize($method);
        if (!HttpMethod::isValid($normalized)) {
            throw InvalidLogEventException::forField('method', 'unknown HTTP method "' . $method . '"');
        }

        $this->method = $normalized;
        $this->url = Assert::nonEmpty($url, 'url');

        $query = parse_url($url, PHP_URL_QUERY);
        $this->queryString = is_string($query) && $query !== '' ? $query : null;
    }

    public static function create(string $event, string $method, string $url): self
    {
        return new self($event, $method, $url);
    }

    public function withHttpCode(int $httpCode): self
    {
        $clone = clone $this;
        $clone->httpCode = $httpCode;

        return $clone;
    }

    /**
     * @param array<string, mixed>|string|null $payload
     */
    public function withPayload($payload): self
    {
        if ($payload !== null && !is_array($payload) && !is_string($payload)) {
            throw InvalidLogEventException::forField('payload', 'must be an array, a string or null');
        }

        $clone = clone $this;
        $clone->payload = $payload;

        return $clone;
    }

    public function withTiming(?float $dns, ?float $connect, ?float $ttfb, ?float $total): self
    {
        $clone = clone $this;
        $clone->timing = [
            'dns' => $dns,
            'connect' => $connect,
            'ttfb' => $ttfb,
            'total' => $total,
        ];

        return $clone;
    }

    public function logType(): string
    {
        return LogType::SERVICE_CALL;
    }

    public function data(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'query_string' => $this->queryString,
            'http_code' => $this->httpCode,
            'payload' => $this->payload,
            'timing' => $this->timing,
        ];
    }
}
