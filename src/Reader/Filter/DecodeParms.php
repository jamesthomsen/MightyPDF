<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Filter;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * One filter's /DecodeParms, reduced to plain numbers with the spec's
 * defaults already applied (ISO 32000-2 Tables 8 and 10).
 *
 * Exists so that the filters themselves know nothing about PDF
 * dictionaries or indirect references: StreamDecoder does the resolving
 * and the defaulting once, here, and hands the filters a value object.
 * A filter that reached back into the document to look up its own
 * parameters would be a filter that cannot be tested without one.
 */
final readonly class DecodeParms
{
    public function __construct(
        public int $predictor = 1,
        public int $colors = 1,
        public int $bitsPerComponent = 8,
        public int $columns = 1,
        public int $earlyChange = 1,
    ) {
    }

    /**
     * @param (\Closure(?PdfValue): ?PdfValue)|null $resolve
     */
    public static function fromDictionary(?Dictionary $parms, ?\Closure $resolve = null): self
    {
        if ($parms === null) {
            return new self();
        }

        $integer = static function (string $key, int $default) use ($parms, $resolve): int {
            $value = $parms->get($key);

            if ($resolve !== null) {
                $value = $resolve($value);
            }

            return $value instanceof PdfInteger ? $value->value() : $default;
        };

        return new self(
            predictor: $integer('Predictor', 1),
            colors: $integer('Colors', 1),
            bitsPerComponent: $integer('BitsPerComponent', 8),
            columns: $integer('Columns', 1),
            earlyChange: $integer('EarlyChange', 1),
        );
    }
}
