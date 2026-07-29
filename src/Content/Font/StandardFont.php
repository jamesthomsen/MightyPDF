<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

/**
 * The 14 standard PDF fonts (ISO 32000-2 §9.6.2.2 / Annex D). Every
 * conforming reader has these built in, so using one only ever needs a
 * small Font dictionary declaring /BaseFont -- no font program embedding.
 *
 * Symbol and ZapfDingbats use their own built-in symbol encodings (not
 * WinAnsiEncoding, and not meaningful for ordinary prose text), so no
 * per-glyph width table is shipped for them here -- see
 * usesWinAnsiEncoding() and FontMetrics::fixedWidth()'s use below.
 */
enum StandardFont
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

    public function metrics(): FontMetrics
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
