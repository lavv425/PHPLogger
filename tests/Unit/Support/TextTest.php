<?php

declare(strict_types=1);

namespace Logger\Tests\Unit\Support;

use Logger\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function test_leaves_a_value_within_the_budget_untouched(): void
    {
        self::assertSame('short', Text::truncateBytes('short', 32));
        self::assertSame('exactly8', Text::truncateBytes('exactly8', 8), 'the limit itself is allowed');
    }

    public function test_a_non_positive_budget_disables_truncation(): void
    {
        self::assertSame('anything', Text::truncateBytes('anything', 0));
        self::assertSame('anything', Text::truncateBytes('anything', -1));
    }

    public function test_appends_a_marker_so_the_cut_is_visible(): void
    {
        self::assertSame('abcde…', Text::truncateBytes('abcdefghij', 5));
        self::assertSame('abcde...', Text::truncateBytes('abcdefghij', 5, '...'));
    }

    public function test_never_splits_a_multibyte_sequence(): void
    {
        // "è" is two bytes: cutting at 3 must drop it whole, not leave half of
        // it behind, which would make the whole record unencodable.
        $truncated = Text::truncateBytes('abè', 3, '');

        self::assertSame('ab', $truncated);
        self::assertTrue(Text::isValidUtf8($truncated));
    }

    /** @dataProvider multibyteCuts */
    public function test_every_cut_position_leaves_valid_utf8(int $maxBytes): void
    {
        $value = 'aàèìòù€😀bcd';

        self::assertTrue(Text::isValidUtf8(Text::truncateBytes($value, $maxBytes, '')));
    }

    /** @return array<string, array{int}> */
    public function multibyteCuts(): array
    {
        $cases = [];
        for ($i = 1; $i <= 20; ++$i) {
            $cases['cut at ' . $i . ' bytes'] = [$i];
        }

        return $cases;
    }

    public function test_strips_control_characters_that_would_break_an_ndjson_line(): void
    {
        self::assertSame('ab', Text::stripControlCharacters("a\x00b"));
        self::assertSame('ab', Text::stripControlCharacters("a\x1Fb"));
        self::assertSame('ab', Text::stripControlCharacters("a\x7Fb"));
    }

    public function test_keeps_the_whitespace_json_can_escape(): void
    {
        // Tab, newline and carriage return have a JSON escape, so they survive.
        self::assertSame("a\tb", Text::stripControlCharacters("a\tb"));
        self::assertSame("a\nb", Text::stripControlCharacters("a\nb"));
        self::assertSame("a\rb", Text::stripControlCharacters("a\rb"));
    }

    public function test_detects_invalid_utf8(): void
    {
        self::assertTrue(Text::isValidUtf8('regular text'));
        self::assertTrue(Text::isValidUtf8('caffè €'));
        self::assertFalse(Text::isValidUtf8("\xC3\x28"), 'a truncated sequence is not valid UTF-8');
        self::assertFalse(Text::isValidUtf8("\xFF"));
    }
}
