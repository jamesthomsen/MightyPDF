<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Content\Text;

use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Text\GlyphFallback;
use MightyPDF\Tests\Support\SyntheticTrueTypeFont;
use PHPUnit\Framework\TestCase;

final class GlyphFallbackTest extends TestCase
{
    /** A font with glyphs for "A", "B" and a space, and nothing else. */
    private static function narrowFont(): EmbeddedFont
    {
        return EmbeddedFont::fromBytes(SyntheticTrueTypeFont::build([
            0x41 => SyntheticTrueTypeFont::GLYPH_A,
            0x42 => SyntheticTrueTypeFont::GLYPH_B,
            0x20 => SyntheticTrueTypeFont::GLYPH_SPACE,
            0x3F => SyntheticTrueTypeFont::GLYPH_A,
        ]), subset: false);
    }

    public function testTextTheFontCanAlreadySetComesBackUntouched(): void
    {
        self::assertSame('AB BA', GlyphFallback::apply('AB BA', self::narrowFont()));

        // Helvetica's repertoire is CP1252, which has all of these.
        self::assertSame('café — €50', GlyphFallback::apply('café — €50', StandardFont::Helvetica));
    }

    public function testTheEmptyStringIsLeftAlone(): void
    {
        self::assertSame('', GlyphFallback::apply('', self::narrowFont()));
    }

    /**
     * The characters a font can set survive next to the ones it cannot,
     * so a name is degraded rather than replaced.
     */
    public function testOnlyTheUnsettableCharactersAreReplaced(): void
    {
        self::assertSame('AB?', GlyphFallback::apply('ABZ', self::narrowFont()));
    }

    /**
     * The whole reason this exists: an embedded font throws on the first
     * character it has no glyph for, which turns an unexpected name into
     * a failed request rather than an imperfect document.
     */
    public function testItPreventsTheDrawTimeRefusalItExistsFor(): void
    {
        $font = self::narrowFont();

        self::assertFalse($font->supports('Zoë'));
        self::assertTrue($font->supports(GlyphFallback::apply('Zoë', $font)));
    }

    /**
     * Never blank for non-empty input: a company name that could not be
     * set has to be visibly approximate, because a missing one shows
     * nothing at all on the page and nobody notices it happened.
     */
    public function testNonEmptyTextNeverBecomesNothing(): void
    {
        foreach (['日本語', 'Ταβέρνα', 'Łódź', "\u{1F600}", 'Zoë Böhm'] as $text) {
            self::assertNotSame('', GlyphFallback::apply($text, self::narrowFont()), $text);
        }
    }

    /**
     * A transliteration is used when the font can set it, so a name
     * degrades to something readable rather than to a row of question
     * marks -- and only the characters that actually needed it change.
     * CP1252 has no "Ł" or "ź" but does have "ó", so that one survives
     * as itself: "Lódz", not "Lodz".
     */
    public function testATransliterationIsPreferredToAQuestionMark(): void
    {
        self::assertSame('Lódz', GlyphFallback::apply('Łódź', StandardFont::Helvetica));
    }

    /**
     * The answer cannot depend on which iconv PHP was built against.
     * Transliteration is asked for one character at a time and anything
     * that comes back non-ASCII or empty is refused, so the GNU libiconv
     * habit of failing a whole string, and the Apple build's habit of
     * transliterating unasked, both change nothing.
     */
    public function testAStringWithNoTransliterationAtAllStillComesBackDrawable(): void
    {
        $font = self::narrowFont();

        foreach (['日本語', 'Ταβέρνα', 'Phở Việt Nam'] as $text) {
            $result = GlyphFallback::apply($text, $font);

            self::assertTrue($font->supports($result), $text);
            self::assertNotSame('', $result, $text);
        }
    }

    public function testCharacterCountIsPreservedWhereEachOneSubstitutesSingly(): void
    {
        self::assertSame('???', GlyphFallback::apply('日本語', self::narrowFont()));
    }

    /**
     * A substitution keeps the character *as a character* where the font
     * allows it, rather than dropping straight to ASCII: "ǽ" becomes "æ"
     * and not "ae", "Ǿ" becomes "Ø" and not "O".
     *
     * Going to ASCII first would also expand -- "€" to "EUR", "½" to
     * " 1/2 " with spaces -- which changes the width of the cell the text
     * lands in and offers a line break in the middle of a word. So the
     * ladder tries CP1252 first and ASCII only after.
     */
    public function testASubstitutionKeepsTheDiacriticWhereTheFontHasOne(): void
    {
        $font = StandardFont::Helvetica;

        self::assertSame('æ', GlyphFallback::apply('ǽ', $font));
        self::assertSame('Ø', GlyphFallback::apply('Ǿ', $font));
    }

    /**
     * The rung is only taken if the *font* can set it -- the limit is
     * which glyphs this font has, not which characters CP1252 contains.
     * A font without "æ" has to fall through to the ASCII rung.
     */
    public function testTheFontDecidesAtEveryRungNotTheCharset(): void
    {
        $font = self::narrowFont();

        self::assertFalse($font->supports('æ'));
        self::assertSame('?', GlyphFallback::apply('ǽ', $font));
    }
}
