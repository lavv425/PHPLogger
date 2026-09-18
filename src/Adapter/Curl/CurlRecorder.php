<?php

declare(strict_types=1);

namespace Logger\Adapter\Curl;

use Logger\Contract\LogError;
use Logger\Enum\Outcome;
use Logger\Interfaces\Adapter\HttpOutcomePolicyInterface;
use Logger\Interfaces\Logger\LoggerInterface;
use Logger\Payload\ServiceCallPayload;
use Logger\Support\Assert;
use Throwable;

/**
 * Logs outbound cURL calls.
 *
 * There is nothing to subclass here: a handle is a resource on PHP 7.4 and a
 * CurlHandle on 8.0+, so the adapter is a thin collaborator around curl_exec()
 * instead of a wrapper type.
 *
 * Everything the envelope needs is already in curl_getinfo(), including the
 * timing breakdown, so the caller does not have to measure anything.
 */
final class CurlRecorder
{
    private LoggerInterface $logger;
    private HttpOutcomePolicyInterface $policy;
    private string $event;

    public function __construct(LoggerInterface $logger, ?HttpOutcomePolicyInterface $policy = null, string $event = 'service_call')
    {
        $this->logger = $logger;
        $this->policy = $policy ?? new StatusHttpOutcomePolicy();
        $this->event = Assert::name($event, 'event');
    }

    /**
     * Runs the transfer and logs it. Drop-in replacement for curl_exec().
     *
     * @param resource|\CurlHandle $handle
     * @return string|bool whatever curl_exec() returned
     */
    public function execute($handle, string $method = 'GET', ?string $event = null)
    {
        $body = curl_exec($handle);
        $this->record($handle, $method, $event);

        return $body;
    }

    /**
     * Logs a transfer the caller already ran, for curl_multi or for code that
     * cannot hand over the curl_exec() call.
     *
     * @param resource|\CurlHandle $handle
     */
    public function record($handle, string $method = 'GET', ?string $event = null): void
    {
        try {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            $info = curl_getinfo($handle);
            $info = is_array($info) ? $info : [];

            $this->write($info, $errno, $error, $method, $event);
        } catch (Throwable $ignored) {
            // The call already happened; failing to describe it must not turn
            // into an exception the caller never expected.
        }
    }

    /**
     * @param array<string, mixed> $info
     */
    private function write(array $info, int $errno, string $error, string $method, ?string $event): void
    {
        $url = isset($info['url']) && is_string($info['url']) && $info['url'] !== '' ? $info['url'] : 'unknown:';
        $httpCode = (int) ($info['http_code'] ?? 0);
        $outcome = $this->policy->decide($errno, $httpCode);

        $payload = ServiceCallPayload::create($event === null ? $this->event : Assert::name($event, 'event'), $method, $url)
            ->withHttpCode($httpCode)
            ->withTiming($this->time($info, 'namelookup_time'), $this->time($info, 'connect_time'), $this->time($info, 'starttransfer_time'), $this->time($info, 'total_time'))
            ->withOutcome($outcome);

        $total = $this->time($info, 'total_time');
        if ($total !== null) {
            $payload = $payload->withDuration($total);
        }

        $failure = $this->describe($errno, $error, $httpCode, $outcome);
        if ($failure !== null) {
            $payload = $payload->withError($failure);
        }

        $this->logger->log($payload);
    }

    private function describe(int $errno, string $error, int $httpCode, string $outcome): ?LogError
    {
        if ($errno !== 0) {
            // fromCurl() promotes errno 28 to a timeout, which deserves its own
            // bucket in a dashboard.
            return LogError::fromCurl($errno, $error === '' ? 'cURL error ' . $errno : $error);
        }

        if ($outcome === Outcome::FAILURE && $httpCode !== 0) {
            return LogError::fromHttpStatus($httpCode);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function time(array $info, string $key): ?float
    {
        if (!isset($info[$key]) || !is_numeric($info[$key])) {
            return null;
        }

        $value = (float) $info[$key];

        // cURL reports -1 for a phase that never happened.
        return $value < 0.0 ? null : $value;
    }
}
