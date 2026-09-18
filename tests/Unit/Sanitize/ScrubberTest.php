<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Sanitize;

use Logger\Sanitize\Scrubber;
use PHPUnit\Framework\TestCase;

final class ScrubberTest extends TestCase
{
    private Scrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new Scrubber();
    }

    /** @dataProvider sensitiveKeys */
    public function test_recognises_sensitive_keys_regardless_of_case(string $key): void
    {
        self::assertTrue($this->scrubber->isSensitiveKey($key));
    }

    /** @return array<string, array{string}> */
    public function sensitiveKeys(): array
    {
        return [
            'password' => ['password'],
            'uppercase' => ['PASSWORD'],
            'mixed case' => ['Api_Key'],
            'token' => ['token'],
            'authorization' => ['authorization'],
            'cookie' => ['cookie'],
            'session id' => ['PHPSESSID'],
            'iban' => ['iban'],
            'card number' => ['card_number'],
            'italian fiscal code' => ['codice_fiscale'],
        ];
    }

    public function test_leaves_ordinary_keys_alone(): void
    {
        self::assertFalse($this->scrubber->isSensitiveKey('tenant_id'));
        self::assertFalse($this->scrubber->isSensitiveKey('route'));
        self::assertFalse($this->scrubber->isSensitiveKey('user_id'));
    }

    public function test_extra_sensitive_keys_can_be_configured(): void
    {
        $scrubber = new Scrubber([], ['Tenant_Secret']);

        self::assertTrue($scrubber->isSensitiveKey('tenant_secret'), 'extra keys are matched case-insensitively');
        self::assertFalse($this->scrubber->isSensitiveKey('tenant_secret'));
    }

    /** @dataProvider secretsInFreeText */
    public function test_masks_secrets_found_in_free_text(string $input, string $mustNotContain): void
    {
        $scrubbed = $this->scrubber->scrubString($input);

        self::assertStringNotContainsString($mustNotContain, $scrubbed);
        self::assertStringContainsString(Scrubber::MASK, $scrubbed);
    }

    /** @return array<string, array{string, string}> */
    public function secretsInFreeText(): array
    {
        return [
            'bearer token' => ['Authorization: Bearer abc123DEF456ghi789', 'abc123DEF456ghi789'],
            'lowercase bearer' => ['bearer abc123DEF456ghi789', 'abc123DEF456ghi789'],
            'basic auth' => ['Authorization: Basic dXNlcjpwYXNzd29yZA==', 'dXNlcjpwYXNzd29yZA=='],
            'jwt' => [
                'token=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk',
                'eyJhbGciOiJIUzI1NiJ9',
            ],
            'credentials in a url' => ['https://admin:s3cr3t@internal.example/api', 's3cr3t'],
            'password in a query string' => ['/login?user=marco&password=hunter2', 'hunter2'],
            'api key in a query string' => ['/v1/items?api_key=ABCDEF123456&page=1', 'ABCDEF123456'],
            'email address' => ['failed login for marco.rossi@example.com', 'marco.rossi@example.com'],
            'iban' => ['transfer to IT60X0542811101000000123456 rejected', 'IT60X0542811101000000123456'],
            'italian fiscal code' => ['taxpayer RSSMRA85T10A562S not found', 'RSSMRA85T10A562S'],
            'long hex blob' => ['digest 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08', '9f86d081884c'],
        ];
    }

    public function test_masks_card_numbers_that_pass_the_luhn_check(): void
    {
        $scrubbed = $this->scrubber->scrubString('payment with 4111111111111111 declined');

        self::assertStringNotContainsString('4111111111111111', $scrubbed);
        self::assertStringContainsString(Scrubber::MASK, $scrubbed);
    }

    public function test_leaves_long_digit_runs_that_are_not_card_numbers(): void
    {
        // A plain identifier must survive, otherwise every order number in the
        // logs turns into "***".
        self::assertStringContainsString('4111111111111112', $this->scrubber->scrubString('order 4111111111111112'));
    }

    /** @dataProvider filePaths */
    public function test_a_file_path_survives_the_base64_rule(string $path): void
    {
        // The rule used to treat "/" as a base64 character, which masked every
        // deployment path and left data.file and the stack trace useless.
        self::assertSame($path, $this->scrubber->scrubString($path));
    }

    /** @return array<string, array{string}> */
    public function filePaths(): array
    {
        return [
            'short' => ['/app/Auth.php'],
            'typical deployment path' => ['/var/www/html/app/Http/Controllers/CheckoutController.php'],
            'very deep path' => ['/srv/application/current/vendor/company/billing/src/Domain/Invoice/InvoiceRepository.php'],
            'stack frame' => ['#0 /var/www/html/app/Http/Controllers/CheckoutController.php(42): handle()'],
            'windows style' => ['C:\\inetpub\\wwwroot\\application\\src\\Controllers\\CheckoutController.php'],
        ];
    }

    public function test_a_long_url_keeps_its_path(): void
    {
        $url = 'https://api.example.com/v1/organisations/42/subscriptions/renewals';

        self::assertSame($url, $this->scrubber->scrubString($url));
    }

    public function test_an_opaque_token_is_still_masked(): void
    {
        // The rule still has to earn its place: a long run with no separator
        // is exactly the shape of an API key.
        $token = str_repeat('A1b2C3d4', 8);

        self::assertSame(Scrubber::MASK, $this->scrubber->scrubString($token));
    }

    public function test_keeps_harmless_text_intact(): void
    {
        $message = 'Connection to production_db refused after 3 retries';

        self::assertSame($message, $this->scrubber->scrubString($message));
    }

    public function test_removes_control_characters(): void
    {
        self::assertSame('abc', $this->scrubber->scrubString("a\x00b\x1Fc"));
    }

    public function test_repairs_invalid_utf8_so_the_record_stays_encodable(): void
    {
        $scrubbed = $this->scrubber->scrubString("broken \xC3\x28 sequence");

        self::assertTrue(
            preg_match('//u', $scrubbed) === 1,
            'invalid UTF-8 would make json_encode fail and silently lose the record'
        );
    }

    public function test_the_mask_is_configurable(): void
    {
        $scrubber = new Scrubber([], [], '[REDACTED]');

        self::assertSame('[REDACTED]', $scrubber->mask());
        self::assertStringContainsString('[REDACTED]', $scrubber->scrubString('write to a@b.com failed'));
    }

    public function test_extra_patterns_are_applied(): void
    {
        $scrubber = new Scrubber(['/CUST-\d{6}/' => '***']);

        self::assertSame('customer *** blocked', $scrubber->scrubString('customer CUST-123456 blocked'));
    }

    public function test_scrub_value_preserves_scalars(): void
    {
        self::assertSame(42, $this->scrubber->scrubValue(42, 4));
        self::assertSame(1.5, $this->scrubber->scrubValue(1.5, 4));
        self::assertTrue($this->scrubber->scrubValue(true, 4));
        self::assertNull($this->scrubber->scrubValue(null, 4));
    }

    public function test_scrub_value_masks_by_key_inside_nested_arrays(): void
    {
        $scrubbed = $this->scrubber->scrubValue(['user' => ['name' => 'marco', 'password' => 'hunter2']], 4);

        self::assertSame(['user' => ['name' => 'marco', 'password' => Scrubber::MASK]], $scrubbed);
    }

    public function test_scrub_value_stops_at_the_depth_limit(): void
    {
        $scrubbed = $this->scrubber->scrubValue(['a' => ['b' => ['c' => 'deep', 'd' => 'deeper']]], 2);

        self::assertSame(['a' => ['b' => ['_omitted' => 2]]], $scrubbed);
    }

    public function test_scrub_value_drops_values_with_no_safe_rendering(): void
    {
        self::assertNull($this->scrubber->scrubValue(new \stdClass(), 4));
        self::assertNull($this->scrubber->scrubValue(STDERR, 4));
    }

    public function test_scrub_value_normalises_integer_keys_to_strings(): void
    {
        $scrubbed = $this->scrubber->scrubValue(['first', 'second'], 4);

        self::assertSame(['0' => 'first', '1' => 'second'], $scrubbed);
    }
}
