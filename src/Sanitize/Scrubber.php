<?php

declare(strict_types=1);

namespace Logger\Sanitize;

use Logger\Support\Text;

/**
 * Best-effort masking of secrets and personal data found in free text.
 *
 * This is a mitigation, not a guarantee: a secret in an unexpected shape gets
 * through. The structural protection is the allow-list in CaptureConfig, which
 * drops whole structures instead of trying to recognise their content.
 */
final class Scrubber
{
    public const MASK = '***';

    /** Keys whose value is replaced regardless of its content. */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'access_token', 'refresh_token',
        'api_key', 'apikey', 'authorization', 'auth', 'credentials', 'cookie', 'set-cookie',
        'session_id', 'sessionid', 'phpsessid', 'signature', 'private_key', 'pin', 'otp',
        'iban', 'cvv', 'card_number', 'pan', 'codice_fiscale', 'fiscal_code',
    ];

    /** @var array<string, string> pattern => replacement */
    private array $patterns;
    /** @var string[] */
    private array $sensitiveKeys;
    private string $mask;

    /**
     * @param array<string, string> $extraPatterns
     * @param string[] $extraSensitiveKeys
     */
    public function __construct(array $extraPatterns = [], array $extraSensitiveKeys = [], string $mask = self::MASK)
    {
        $this->mask = $mask;
        $this->patterns = self::defaultPatterns($mask) + $extraPatterns;
        $this->sensitiveKeys = array_merge(
            self::SENSITIVE_KEYS,
            array_map('strtolower', $extraSensitiveKeys)
        );
    }

    public function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), $this->sensitiveKeys, true);
    }

    public function mask(): string
    {
        return $this->mask;
    }

    public function scrubString(string $value): string
    {
        $value = $this->enforceUtf8($value);
        $value = Text::stripControlCharacters($value);
        $value = $this->maskPrimaryAccountNumbers($value);

        foreach ($this->patterns as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $value);
            if ($result !== null) {
                $value = $result;
            }
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function scrubValue($value, int $maxDepth, int $depth = 0)
    {
        if (is_string($value)) {
            return $this->scrubString($value);
        }

        if (is_array($value)) {
            if ($depth >= $maxDepth) {
                return ['_omitted' => count($value)];
            }

            $scrubbed = [];
            foreach ($value as $key => $item) {
                $stringKey = (string) $key;
                $scrubbed[$stringKey] = $this->isSensitiveKey($stringKey)
                    ? $this->mask
                    : $this->scrubValue($item, $maxDepth, $depth + 1);
            }

            return $scrubbed;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        // Objects and resources have no safe generic rendering.
        return null;
    }

    /** @return array<string, string> */
    private static function defaultPatterns(string $mask): array
    {
        return [
            // Credentials embedded in a URL.
            '#//[^:/\s@]+:[^@/\s]+@#' => '//' . $mask . ':' . $mask . '@',
            // Authorization headers and bearer tokens.
            '/\b[Bb]earer\s+[A-Za-z0-9\-._~+\/]+=*/' => 'Bearer ' . $mask,
            '/\b[Bb]asic\s+[A-Za-z0-9+\/]+=*/' => 'Basic ' . $mask,
            // JSON Web Tokens.
            '/\beyJ[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}/' => $mask,
            // Secrets passed in a query string. The separator is optional on
            // the left: a bare query string, which is what parse_url() returns
            // and what ServiceCallPayload stores, starts its first parameter
            // with no "?" or "&" in front of it.
            '/((?:^|[?&;\s])(?:password|passwd|pwd|token|access_token|api_key|apikey|secret|signature|sig|auth)=)[^&\s]*/i'
                => '$1' . $mask,
            // Personal data.
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => $mask,
            '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/' => $mask,
            '/\b[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]\b/i' => $mask,
            // Long opaque strings: api keys, hashes, base64 blobs.
            //
            // "/" is deliberately NOT part of the base64 run. It is a valid
            // base64 character, but including it made the rule swallow every
            // filesystem path longer than the threshold: a deployment path such
            // as /var/www/html/app/Http/Controllers/CheckoutController.php came
            // out as /***.php, which destroyed data.file and every stack frame.
            // Treating "/" as a separator keeps paths readable, because their
            // individual segments are far below the threshold. The cost is that
            // a standard-base64 blob whose slashes fall close together can slip
            // through; the hex and JWT rules above still cover the common
            // shapes, and the allow-list is the actual guarantee.
            '/\b[0-9a-fA-F]{32,}\b/' => $mask,
            '/\b[A-Za-z0-9+]{40,}={0,2}\b/' => $mask,
        ];
    }

    /** Masks digit runs that pass the Luhn check, to avoid hitting plain ids. */
    private function maskPrimaryAccountNumbers(string $value): string
    {
        $mask = $this->mask;

        $result = preg_replace_callback(
            '/\b(?:\d[ -]?){12,18}\d\b/',
            static function (array $matches) use ($mask): string {
                $digits = preg_replace('/\D/', '', $matches[0]);

                if ($digits === null || strlen($digits) < 13 || strlen($digits) > 19) {
                    return $matches[0];
                }

                return self::passesLuhn($digits) ? $mask : $matches[0];
            },
            $value
        );

        return $result ?? $value;
    }

    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; --$i) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

    /**
     * Invalid UTF-8 makes json_encode fail and silently loses the record, so it
     * is repaired here rather than at encoding time.
     */
    private function enforceUtf8(string $value): string
    {
        if (Text::isValidUtf8($value)) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                return $converted;
            }
        }

        return (string) preg_replace('/[\x80-\xFF]/', '?', $value);
    }
}
