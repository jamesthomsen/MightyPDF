<?php

declare(strict_types=1);

namespace MightyPDF\Content\Text;

/**
 * Splitting UTF-8 text into what a font is asked about: code points.
 *
 * Deliberately built on iconv rather than mbstring: this library already
 * requires ext-iconv (see composer.json) and requiring a second extension
 * for string splitting would be a poor trade. Converting to UTF-32BE and
 * unpacking is also the one approach that rejects invalid UTF-8 instead
 * of quietly producing replacement characters -- text that is not valid
 * UTF-8 is a caller mistake worth hearing about, since every code point
 * after the bad byte would otherwise be wrong.
 */
final class Utf8
{
    private function __construct()
    {
    }

    /** @return list<int> */
    public static function codePoints(string $utf8Text): array
    {
        if ($utf8Text === '') {
            return [];
        }

        $utf32 = @iconv('UTF-8', 'UTF-32BE', $utf8Text);

        if ($utf32 === false) {
            throw new \InvalidArgumentException('Text is not valid UTF-8.');
        }

        return array_values(unpack('N*', $utf32));
    }

    /**
     * The text as single-character strings, for reporting back to a
     * caller -- an error message saying a font has no "字" is worth more
     * than one naming U+5B57.
     *
     * @return list<string>
     */
    public static function characters(string $utf8Text): array
    {
        return array_map(self::fromCodePoint(...), self::codePoints($utf8Text));
    }

    public static function fromCodePoint(int $codePoint): string
    {
        $utf8 = @iconv('UTF-32BE', 'UTF-8', pack('N', $codePoint));

        return $utf8 === false ? '' : $utf8;
    }
}
