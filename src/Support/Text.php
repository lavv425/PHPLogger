<?php

declare(strict_types=1);

namespace Logger\Support;

final class Text
{
    private function __construct()
    {
    }

    /**
     * Byte-safe truncation that never leaves a half UTF-8 sequence behind,
     * which would make the whole record unencodable.
     */
    public static function truncateBytes(string $value, int $maxBytes, string $suffix = '…'): string
    {
        if ($maxBytes <= 0 || strlen($value) <= $maxBytes) {
            return $value;
        }

        $cut = substr($value, 0, $maxBytes);
        $length = strlen($cut);

        // Walk back over continuation bytes, then drop the lead byte too.
        $index = $length - 1;
        while ($index >= 0 && (ord($cut[$index]) & 0xC0) === 0x80) {
            --$index;
        }

        if ($index >= 0 && (ord($cut[$index]) & 0x80) !== 0) {
            $expected = self::sequenceLength(ord($cut[$index]));
            if ($expected > $length - $index) {
                $cut = substr($cut, 0, $index);
            }
        }

        return $cut . $suffix;
    }

    /** Strips control characters that would make the NDJSON line unreadable. */
    public static function stripControlCharacters(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    public static function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    private static function sequenceLength(int $leadByte): int
    {
        if (($leadByte & 0xE0) === 0xC0) {
            return 2;
        }

        if (($leadByte & 0xF0) === 0xE0) {
            return 3;
        }

        if (($leadByte & 0xF8) === 0xF0) {
            return 4;
        }

        return 1;
    }
}
