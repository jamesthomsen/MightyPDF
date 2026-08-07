<?php

declare(strict_types=1);

namespace MightyPDF\Content\Barcode;

/**
 * Which linear barcode to draw.
 *
 * An enum rather than the string PageBuilder::drawBarcode() originally
 * took, because "code39" is a value nothing can check until it reaches
 * the match that rejects it, and by then the caller has already been told
 * the wrong thing about which names exist. The strings still work.
 *
 * Every case answers elements() the same way -- a flat left-to-right list
 * of bar and space widths in abstract modules -- which is what lets one
 * drawing routine handle all of them. Nothing here knows about points or
 * PDF.
 */
enum Symbology: string
{
    /** The 43-character alphanumeric one: simple, verbose, still everywhere in logistics. */
    case Code39 = 'code39';

    /** Full ASCII, and half the width of Code 39 for digits. The default choice for anything new. */
    case Code128 = 'code128';

    /** Thirteen digits, fixed: retail packaging outside North America. */
    case Ean13 = 'ean13';

    /** Twelve digits: retail packaging inside it. The same symbol as EAN-13 with a leading zero. */
    case UpcA = 'upca';

    /**
     * @return list<array{isBar: bool, widthModules: float}>
     */
    public function elements(string $value): array
    {
        return match ($this) {
            self::Code39 => Code39::elements($value),
            self::Code128 => Code128::elements($value),
            self::Ean13 => Ean13::elements($value),
            self::UpcA => Ean13::upcAElements($value),
        };
    }

    /**
     * The clear space a scanner needs, in modules, as [left, right].
     *
     * Part of the symbology rather than of the drawing, because it is
     * specified per symbology and getting it wrong produces a barcode
     * that looks right and does not scan.
     *
     * @return array{int, int}
     */
    public function quietZoneModules(): array
    {
        return match ($this) {
            // Code 39's own standard states it as ten times the narrow
            // element, which is what a module is here.
            self::Code39, self::Code128 => [10, 10],
            self::Ean13, self::UpcA => [
                Ean13::QUIET_ZONE_LEFT_MODULES,
                Ean13::QUIET_ZONE_RIGHT_MODULES,
            ],
        };
    }

    /** Accepts a case, or one of the strings drawBarcode() has always taken. */
    public static function coerce(self|string $symbology): self
    {
        if ($symbology instanceof self) {
            return $symbology;
        }

        return self::tryFrom(strtolower($symbology)) ?? throw new \InvalidArgumentException(sprintf(
            'Unsupported barcode symbology "%s" -- expected one of: %s.',
            $symbology,
            implode(', ', array_column(self::cases(), 'value')),
        ));
    }
}
