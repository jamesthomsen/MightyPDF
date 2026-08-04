<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Font\TrueType;

use MightyPDF\Content\Font\TrueType\CffHeader;
use PHPUnit\Framework\TestCase;

/**
 * The one question a 'CFF ' table is asked here: are its glyphs
 * addressed by index, or by character id through a charset of its own?
 *
 * TrueTypeFile refuses a table that lies outside the file, so what
 * reaches this is always inside one -- but a table can end wherever its
 * directory entry says, and the offsets *within* it are the font's own
 * to get wrong.
 */
final class CffHeaderTest extends TestCase
{
    /** header, name INDEX, then a Top DICT INDEX holding one dictionary. */
    private const string HEADER = "\x01\x00\x04\x02";

    public function testFindsTheRosOperatorOfACidKeyedFont(): void
    {
        // "391 391 0 ROS": two operands, then the two-byte operator.
        $topDict = "\xF8\x1B\xF8\x1B\x8B\x0C\x1E";

        self::assertTrue(CffHeader::isCidKeyed(self::table($topDict)));
    }

    public function testAFontWithoutRosIsAddressedByGlyphIndex(): void
    {
        // "0 -100 500 900 FontBBox", an operator that is not ROS.
        $topDict = "\x8B\x1C\xFF\x9C\x1C\x01\xF4\x1C\x03\x84\x05";

        self::assertFalse(CffHeader::isCidKeyed(self::table($topDict)));
    }

    /**
     * Every prefix of a real table, none of which may read past its own
     * end. PHP answers an out-of-range string offset with a warning, and
     * an application that turns warnings into exceptions -- which both
     * major frameworks do -- would see a malformed font throw something
     * that is not a FontException. The boundary that used to do it is a
     * table ending exactly where an INDEX's offset size would be.
     */
    public function testATruncatedTableIsReadWithoutRunningPastItsEnd(): void
    {
        $table = self::table("\xF8\x1B\xF8\x1B\x8B\x0C\x1E");
        $raised = null;

        set_error_handler(static function (int $number, string $message) use (&$raised): bool {
            $raised = $message;

            return true;
        });

        try {
            for ($length = 0, $whole = strlen($table); $length <= $whole; ++$length) {
                self::assertIsBool(CffHeader::isCidKeyed(substr($table, 0, $length)));
            }
        } finally {
            restore_error_handler();
        }

        self::assertNull($raised, "reading a truncated CFF raised: $raised");
    }

    /** A table whose header claims a size past everything in it. */
    public function testAHeaderSizePastTheEndOfTheTableIsNotCidKeyed(): void
    {
        self::assertFalse(CffHeader::isCidKeyed("\x01\x00\xFF\x02\x00\x01\x01\x01\x02"));
    }

    /** The whole table: header, an empty name INDEX, and one Top DICT. */
    private static function table(string $topDict): string
    {
        return self::HEADER . self::index('Test') . self::index($topDict);
    }

    /** A CFF INDEX holding one entry, with one-byte offsets. */
    private static function index(string $entry): string
    {
        return pack('n', 1) . "\x01" . "\x01" . chr(1 + strlen($entry)) . $entry;
    }
}
