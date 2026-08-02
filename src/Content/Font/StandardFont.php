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
 * represent, and anything outside that is transliterated rather than
 * drawn (see WinAnsiEncoding). A document that needs the rest of Unicode
 * needs a font that contains it: EmbeddedFont.
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
     * of it, and a character that transliterates to two ("œ" to "oe")
     * takes the width of two.
     */
    public function widthOfPt(string $utf8Text, float $sizePt): float
    {
        return $this->metrics()->widthOf(WinAnsiEncoding::encode($utf8Text), $sizePt);
    }

    /**
     * The standard-14 metrics shipped here are advance widths only, with
     * no ascent among them, so this is the conventional approximation
     * (~0.8 of the nominal size) rather than a measurement. An embedded
     * font states its own; see EmbeddedFont.
     */
    public function ascentPt(float $sizePt): float
    {
        return $sizePt * 0.8;
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
            self::Helvetica, self::HelveticaOblique => new FontMetrics(require __DIR__ . '/Data/Helvetica.php'),
            self::HelveticaBold, self::HelveticaBoldOblique => new FontMetrics(require __DIR__ . '/Data/HelveticaBold.php'),
            self::TimesRoman => new FontMetrics(require __DIR__ . '/Data/TimesRoman.php'),
            self::TimesBold => new FontMetrics(require __DIR__ . '/Data/TimesBold.php'),
            self::TimesItalic => new FontMetrics(require __DIR__ . '/Data/TimesItalic.php'),
            self::TimesBoldItalic => new FontMetrics(require __DIR__ . '/Data/TimesBoldItalic.php'),
            self::Courier, self::CourierBold, self::CourierOblique, self::CourierBoldOblique => FontMetrics::fixedWidth(600),
            // No per-glyph table shipped -- see class doc comment.
            self::Symbol, self::ZapfDingbats => FontMetrics::fixedWidth(500),
        };
    }
}
