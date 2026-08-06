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

    public function testEmptyStringEncodesToEmptyString(): void
    {
        self::assertSame('', WinAnsiEncoding::encode(''));
        self::assertSame([], WinAnsiEncoding::unrepresentableCharacters(''));
    }
}
