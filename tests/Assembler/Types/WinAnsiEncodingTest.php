<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler\Types;

use MightyPDF\Assembler\Types\WinAnsiEncoding;
use PHPUnit\Framework\TestCase;

/**
 * Assertions here are deliberately kept to what holds on any iconv
 * build. Exactly what a character transliterates *to* is iconv's
 * business and differs between glibc and GNU libiconv (glibc renders
 * U+1F642 as ":-)"), so what is pinned down is the contract this library
 * makes: valid text always encodes, never to nothing, and characters
 * CP1252 does have are carried through untouched.
 */
final class WinAnsiEncodingTest extends TestCase
{
    public function testAsciiPassesThroughUnchanged(): void
    {
        self::assertSame('Hello, world!', WinAnsiEncoding::encode('Hello, world!'));
    }

    public function testLatin1SupplementCharactersEncodeToSingleBytes(): void
    {
        // é is U+00E9, which is byte 0xE9 in both Latin-1 and CP1252/WinAnsi.
        $encoded = WinAnsiEncoding::encode('café');

        self::assertSame(4, strlen($encoded));
        self::assertSame("caf\xE9", $encoded);
    }

    /**
     * CP1252 puts the typographic punctuation Latin-1 lacks in 0x80-0x9F,
     * so none of this is transliterated -- worth pinning, since the euro
     * and curly quotes are the characters most often assumed to be lost.
     */
    public function testCp1252HighRangePunctuationSurvivesAsItself(): void
    {
        self::assertSame("\x80 \x97 \x93x\x94", WinAnsiEncoding::encode('€ — “x”'));
        self::assertSame([], WinAnsiEncoding::unrepresentableCharacters('€ — “x”'));
    }

    public function testCharacterWithNoTransliterationBecomesAQuestionMark(): void
    {
        self::assertSame('?', WinAnsiEncoding::encode('日'));
    }

    /**
     * The case that used to throw on GNU libiconv: not one character of
     * these has a CP1252 code, so the whole-string conversion is the one
     * that fails there rather than partially succeeding.
     */
    public function testTextWithNoCp1252CharacterAtAllStillEncodes(): void
    {
        foreach (['Phở Việt Nam', 'Ταβέρνα', 'Łódź', '日本語'] as $text) {
            $encoded = WinAnsiEncoding::encode($text);

            self::assertNotSame('', $encoded, "encoding $text produced nothing");
        }
    }

    public function testRepresentableCharactersSurviveAlongsideUnrepresentableOnes(): void
    {
        self::assertSame("caf\xE9 ?", WinAnsiEncoding::encode('café 日'));
    }

    /**
     * A conversion reporting success is not evidence that anything came
     * out of it: glibc converts the Unicode tag block to the empty
     * string and returns success, so the whole-string fast path used to
     * hand back text with these quietly missing from it -- the one
     * outcome this class promises never to produce. Other builds refuse
     * the conversion, and reach the same "?" the long way round.
     */
    public function testCharactersIconvConvertsToNothingBecomeAQuestionMark(): void
    {
        // U+E0041, TAG LATIN CAPITAL LETTER A.
        self::assertSame('?', WinAnsiEncoding::encode("\u{E0041}"));
        self::assertSame('a?b', WinAnsiEncoding::encode("a\u{E0041}b"));
        self::assertSame(["\u{E0041}"], WinAnsiEncoding::unrepresentableCharacters("a\u{E0041}b"));
    }

    /**
     * Malformed UTF-8 is a caller mistake rather than a limit of the
     * repertoire, so it is still refused -- substituting for it would
     * turn a bad byte into plausible-looking text.
     */
    public function testMalformedUtf8IsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not valid UTF-8');

        WinAnsiEncoding::encode("caf\xC3\x28");
    }

    public function testUnrepresentableCharactersAreListedOnceInOrder(): void
    {
        self::assertSame(
            ['Ł', 'ź'],
            WinAnsiEncoding::unrepresentableCharacters('Łódź Łódź'),
        );
    }

    public function testUnrepresentableCharactersIgnoresWhatOnlyTransliterationWouldSalvage(): void
    {
        // "Ł" encodes to "L", but it is still a character WinAnsi has no
        // code for -- the caller asking is asking what it will lose.
        self::assertSame('L', WinAnsiEncoding::encode('Ł'));
        self::assertSame(['Ł'], WinAnsiEncoding::unrepresentableCharacters('Ł'));
    }

    /**
     * The case an iconv-based repertoire probe gets wrong on Apple's
     * bundled libiconv 1.11, which transliterates on a plain conversion
     * it was never asked to transliterate: every character here comes
     * back as its ASCII approximation rather than false there, so a
     * probe reads them as present and the whole set comes back empty.
     * The answer has to be the same on every build, so it comes from the
     * repertoire table and not from a conversion.
     */
    public function testCharactersWithAnAsciiApproximationAreStillMissing(): void
    {
        self::assertSame(['Ł'], WinAnsiEncoding::unrepresentableCharacters('Ł'));
        self::assertSame(['ﬁ'], WinAnsiEncoding::unrepresentableCharacters('ﬁ'));
        self::assertSame(['Ł', 'ź'], WinAnsiEncoding::unrepresentableCharacters('Łódź'));
        self::assertSame(['ā', 'ļ', 'č'], WinAnsiEncoding::unrepresentableCharacters('āboļčs'));
    }

    /**
     * The whole repertoire, since it is a fixed 251 entries and worth
     * having pinned once: the two contiguous runs CP1252 shares with
     * Latin-1, and the punctuation it puts in 0x80-0x9F.
     */
    public function testEveryCp1252CharacterIsRepresentable(): void
    {
        $representable = '';

        for ($code = 0x00; $code <= 0x7F; $code++) {
            $representable .= chr($code);
        }

        for ($code = 0xA0; $code <= 0xFF; $code++) {
            $representable .= chr(0xC0 | $code >> 6) . chr(0x80 | $code & 0x3F);
        }

        $representable .= '€‚ƒ„…†‡ˆ‰Š‹ŒŽ‘’“”•–—˜™š›œžŸ';

        self::assertSame([], WinAnsiEncoding::unrepresentableCharacters($representable));
        self::assertSame(251, \count(preg_split('//u', $representable, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /**
     * The other half of that range: CP1252 spends 0x80-0x9F on
     * punctuation, so none of the C1 controls Latin-1 puts at
     * U+0080-U+009F is in the repertoire -- 0x80 is the euro sign, not
     * U+0080 -- and the five codes CP1252 leaves undefined have nothing
     * at all.
     */
    public function testC1ControlsAreNotRepresentable(): void
    {
        for ($codePoint = 0x80; $codePoint <= 0x9F; $codePoint++) {
            $control = chr(0xC0 | $codePoint >> 6) . chr(0x80 | $codePoint & 0x3F);

            self::assertSame(
                [$control],
                WinAnsiEncoding::unrepresentableCharacters($control),
                sprintf('U+%04X', $codePoint),
            );
        }
    }

    /**
     * decode() is the same table read backwards, and unlike encode() it
     * is total: every assigned byte has exactly one character, so it can
     * neither approximate nor fail.
     */
    public function testEveryAssignedByteRoundTripsThroughDecodeAndBack(): void
    {
        $assigned = 0;

        for ($byte = 0x00; $byte <= 0xFF; $byte++) {
            $character = WinAnsiEncoding::decode(chr($byte));

            if ($character === '') {
                continue;
            }

            $assigned++;
            self::assertSame(chr($byte), WinAnsiEncoding::encode($character), sprintf('byte 0x%02X', $byte));
        }

        self::assertSame(251, $assigned);
    }

    public function testDecodeReadsTheCp1252PunctuationRatherThanLatin1Controls(): void
    {
        self::assertSame('€ — “x”', WinAnsiEncoding::decode("\x80 \x97 \x93x\x94"));
        self::assertSame('café', WinAnsiEncoding::decode("caf\xE9"));
    }

    /**
     * The five codes CP1252 leaves undefined are dropped rather than
     * guessed at: they cannot come out of encode(), and Latin-1's C1
     * control is not what a byte in a CP1252 string was meant to be.
     */
    public function testDecodeDropsTheBytesCp1252LeavesUndefined(): void
    {
        self::assertSame('ab', WinAnsiEncoding::decode("a\x81\x8D\x8F\x90\x9Db"));
    }

    public function testEmptyStringEncodesToEmptyString(): void
    {
        self::assertSame('', WinAnsiEncoding::encode(''));
        self::assertSame([], WinAnsiEncoding::unrepresentableCharacters(''));
    }
    /**
     * decode() flips its table once per process rather than once per
     * call. GlyphFallback calls this once per character it substitutes,
     * and rebuilding 251 entries each time was about half the cost of
     * substituting a name.
     *
     * Correctness is what is actually pinned here: a table built once
     * and kept has to keep answering the way a freshly built one would,
     * for every byte, however many times it is asked.
     */
    public function testDecodingIsStableAcrossRepeatedCalls(): void
    {
        $first = [];

        for ($byte = 0; $byte <= 0xFF; $byte++) {
            $first[$byte] = WinAnsiEncoding::decode(chr($byte));
        }

        for ($round = 0; $round < 3; $round++) {
            for ($byte = 0; $byte <= 0xFF; $byte++) {
                self::assertSame($first[$byte], WinAnsiEncoding::decode(chr($byte)), "byte $byte on round $round");
            }
        }
    }

    /** Every assigned byte still survives a round trip through both directions. */
    public function testEveryAssignedByteRoundTrips(): void
    {
        for ($byte = 0; $byte <= 0xFF; $byte++) {
            $character = WinAnsiEncoding::decode(chr($byte));

            if ($character === '') {
                self::assertContains($byte, [0x81, 0x8D, 0x8F, 0x90, 0x9D], 'only CP1252 undefined bytes decode to nothing');

                continue;
            }

            self::assertSame(chr($byte), WinAnsiEncoding::encode($character), sprintf('byte 0x%02X', $byte));
        }
    }
}
