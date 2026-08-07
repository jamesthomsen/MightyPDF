<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\DocumentContext;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\WinAnsiEncoding;

/**
 * The 14 standard PDF fonts (ISO 32000-2 §9.6.2.2 / Annex D). Every
 * conforming reader has these built in, so using one only ever needs a
 * small Font dictionary declaring /BaseFont -- no font program embedding.
 *
 * Symbol and ZapfDingbats use their own built-in symbol encodings (not
 * WinAnsiEncoding, and not meaningful for ordinary prose text), so no
 * per-glyph width table is shipped for them here -- see
 * usesWinAnsiEncoding() and FontMetrics::fixedWidth()'s use below.
 *
 * Text drawn with one of these is limited to what WinAnsiEncoding can
 * represent, and anything outside that is transliterated or substituted
 * rather than drawn (see WinAnsiEncoding). Drawing never fails over it;
 * supports() and missingCharacters() are how a caller finds out first. A
 * document that needs the rest of Unicode needs a font that contains it:
 * EmbeddedFont.
 */
enum StandardFont implements Font
{
    case Helvetica;
    case HelveticaBold;
    case HelveticaOblique;
    case HelveticaBoldOblique;
    case TimesRoman;
    case TimesBold;
    case TimesItalic;
    case TimesBoldItalic;
    case Courier;
    case CourierBold;
    case CourierOblique;
    case CourierBoldOblique;
    case Symbol;
    case ZapfDingbats;

    public function baseFontName(): string
    {
        return match ($this) {
            self::Helvetica => 'Helvetica',
            self::HelveticaBold => 'Helvetica-Bold',
            self::HelveticaOblique => 'Helvetica-Oblique',
            self::HelveticaBoldOblique => 'Helvetica-BoldOblique',
            self::TimesRoman => 'Times-Roman',
            self::TimesBold => 'Times-Bold',
            self::TimesItalic => 'Times-Italic',
            self::TimesBoldItalic => 'Times-BoldItalic',
            self::Courier => 'Courier',
            self::CourierBold => 'Courier-Bold',
            self::CourierOblique => 'Courier-Oblique',
            self::CourierBoldOblique => 'Courier-BoldOblique',
            self::Symbol => 'Symbol',
            self::ZapfDingbats => 'ZapfDingbats',
        };
    }

    /**
     * The cut of a family matching a name and a weight/slope, for code
     * holding a font as data rather than as a case: a style sheet, a
     * config file, an SVG's font-family, a report ported from a library
     * whose API was setFont('Arial', 'B').
     *
     * $family is a CSS-style list of preferences, and the first name
     * that can be honoured wins -- which is what such a list means.
     * Anything Times-like becomes Times and anything monospaced Courier;
     * everything else, including an unrecognised name and none at all,
     * becomes Helvetica.
     *
     * "Arial" landing on Helvetica is the useful case and not an
     * accident: Arial is not one of the standard 14, the two share
     * metrics, and every PDF toolchain has substituted one for the other
     * for thirty years.
     */
    public static function matching(?string $family, bool $bold = false, bool $italic = false): self
    {
        foreach (preg_split('/\s*,\s*/', strtolower($family ?? '')) ?: [] as $name) {
            $name = trim($name, " \t'\"");

            if ($name === 'monospace' || str_contains($name, 'courier') || str_contains($name, 'mono')) {
                return match (true) {
                    $bold && $italic => self::CourierBoldOblique,
                    $bold => self::CourierBold,
                    $italic => self::CourierOblique,
                    default => self::Courier,
                };
            }

            if ($name === 'serif' || str_contains($name, 'times') || str_contains($name, 'georgia')
                || str_contains($name, 'garamond') || str_contains($name, 'roman')
            ) {
                return match (true) {
                    $bold && $italic => self::TimesBoldItalic,
                    $bold => self::TimesBold,
                    $italic => self::TimesItalic,
                    default => self::TimesRoman,
                };
            }
        }

        return match (true) {
            $bold && $italic => self::HelveticaBoldOblique,
            $bold => self::HelveticaBold,
            $italic => self::HelveticaOblique,
            default => self::Helvetica,
        };
    }

    public function usesWinAnsiEncoding(): bool
    {
        return $this !== self::Symbol && $this !== self::ZapfDingbats;
    }

    public function cacheKey(): string
    {
        return 'standard:' . $this->name;
    }

    /**
     * Measured on the encoded form, not the text as given: what a
     * standard font actually draws for "café" is whatever CP1252 makes
     * of it, and a character that transliterates to two ("ﬁ" to "fi")
     * takes the width of two.
     */
    public function widthOfPt(string $utf8Text, float $sizePt): float
    {
        return $this->metrics()->widthOf(WinAnsiEncoding::encode($utf8Text), $sizePt);
    }

    /** Whether every character of $utf8Text has a WinAnsi code of its own. */
    public function supports(string $utf8Text): bool
    {
        return $this->missingCharacters($utf8Text) === [];
    }

    /**
     * The characters of $utf8Text with no WinAnsi code -- the ones that
     * will be drawn as an approximation ("Ł" as "L") or as "?" rather
     * than as themselves. Unlike an embedded font's, this is advisory:
     * drawing them succeeds, it just does not draw what was asked for.
     *
     * Answered against WinAnsi for Symbol and ZapfDingbats too, which is
     * an approximation in the same way their widths are (see
     * loadMetrics()): those two are read through their own built-in
     * encodings, so what they draw for ordinary prose is not the prose
     * either way.
     *
     * @return list<string>
     */
    public function missingCharacters(string $utf8Text): array
    {
        return WinAnsiEncoding::unrepresentableCharacters($utf8Text);
    }

    public function ascentPt(float $sizePt): float
    {
        return $this->verticalMetrics()->ascentPt($sizePt);
    }

    public function descentPt(float $sizePt): float
    {
        return $this->verticalMetrics()->descentPt($sizePt);
    }

    public function capHeightPt(float $sizePt): float
    {
        return $this->verticalMetrics()->capHeightPt($sizePt);
    }

    /**
     * The vertical extents from Adobe's Core 14 AFM files, transcribed
     * the same way and from the same source as the width tables in
     * Data/ -- Ascender, Descender and CapHeight, with the descender's
     * sign flipped to the positive distance Font::descentPt() reports.
     *
     * These replace the flat 0.8-of-nominal-size ascent this enum used
     * to return, which was a guess standing in for numbers nothing here
     * had. Helvetica actually rises 0.718, so the guess sat text 0.082
     * of the type size off: under a point in body copy, and most of a
     * centimetre at the sizes a cover letter or a scorecard grade is
     * set in. That error scaling with type size is exactly what makes
     * this kind of approximation survive review and fail in print.
     *
     * Symbol and ZapfDingbats state no Ascender, Descender or CapHeight
     * at all -- their AFMs describe glyphs that are not letters -- so
     * their font bounding box stands in, and cap height follows
     * FontFileMetrics's 0.7-of-ascent estimate for the same reason it
     * does there: a plausible value is worth more than an absent one.
     */
    private function verticalMetrics(): VerticalMetrics
    {
        static $memo = [];

        return $memo[$this->name] ??= match ($this) {
            self::Helvetica, self::HelveticaOblique,
            self::HelveticaBold, self::HelveticaBoldOblique => new VerticalMetrics(718, 207, 718),
            self::TimesRoman => new VerticalMetrics(683, 217, 662),
            self::TimesBold => new VerticalMetrics(683, 217, 676),
            self::TimesItalic => new VerticalMetrics(683, 217, 653),
            self::TimesBoldItalic => new VerticalMetrics(683, 217, 669),
            self::Courier, self::CourierOblique,
            self::CourierBold, self::CourierBoldOblique => new VerticalMetrics(629, 157, 562),
            self::Symbol => new VerticalMetrics(1010, 293, 707),
            self::ZapfDingbats => new VerticalMetrics(820, 143, 574),
        };
    }

    public function writerFor(DocumentContext $document): FontWriter
    {
        $cached = $document->cachedFont($this->cacheKey());

        if ($cached !== null) {
            return new StandardFontWriter($cached);
        }

        $dictionary = new Dictionary($document->allocate());
        $dictionary->set('Type', new PdfName('Font'));
        $dictionary->set('Subtype', new PdfName('Type1'));
        $dictionary->set('BaseFont', new PdfName($this->baseFontName()));

        if ($this->usesWinAnsiEncoding()) {
            $dictionary->set('Encoding', new PdfName('WinAnsiEncoding'));
        }

        $document->register($dictionary);
        $document->cacheFont($this->cacheKey(), $dictionary);

        return new StandardFontWriter($dictionary);
    }

    /**
     * The width table is loaded through a memo rather than on every
     * call: these live in plain PHP files pulled in with require, which
     * -- unlike require_once -- re-reads and re-evaluates the file every
     * time. Wrapping a paragraph asks for a width per word, and without
     * this that is a file read per word.
     */
    public function metrics(): FontMetrics
    {
        static $memo = [];

        return $memo[$this->name] ??= $this->loadMetrics();
    }

    private function loadMetrics(): FontMetrics
    {
        return match ($this) {
            self::Helvetica, self::HelveticaOblique => FontMetrics::forWinAnsi(require __DIR__ . '/Data/Helvetica.php'),
            self::HelveticaBold, self::HelveticaBoldOblique => FontMetrics::forWinAnsi(require __DIR__ . '/Data/HelveticaBold.php'),
            self::TimesRoman => FontMetrics::forWinAnsi(require __DIR__ . '/Data/TimesRoman.php'),
            self::TimesBold => FontMetrics::forWinAnsi(require __DIR__ . '/Data/TimesBold.php'),
            self::TimesItalic => FontMetrics::forWinAnsi(require __DIR__ . '/Data/TimesItalic.php'),
            self::TimesBoldItalic => FontMetrics::forWinAnsi(require __DIR__ . '/Data/TimesBoldItalic.php'),
            self::Courier, self::CourierBold, self::CourierOblique, self::CourierBoldOblique => FontMetrics::fixedWidth(600),
            // No per-glyph table shipped -- see class doc comment.
            self::Symbol, self::ZapfDingbats => FontMetrics::fixedWidth(500),
        };
    }
}
