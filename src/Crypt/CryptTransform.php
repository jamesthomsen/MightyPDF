<?php

declare(strict_types=1);

namespace MightyPDF\Crypt;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;

/**
 * Rebuilds an object with every string and stream body passed through a
 * pair of transforms -- the one operation both decryption and encryption
 * need, run in opposite directions.
 *
 * Encryption in PDF reaches the leaves of an object, not the object as a
 * whole: a dictionary's structure stays in the clear and only the strings
 * and stream data inside it are enciphered. So this has to walk, and walk
 * everywhere -- a field's value nested three dictionaries deep inside an
 * annotation is as encrypted as anything else.
 *
 * It rebuilds rather than mutating. The caller may still be holding the
 * object it passed in, and decryption in particular happens behind the
 * caller's back during parsing; quietly rewriting the bytes of something
 * someone else has a reference to is how a document ends up
 * double-decrypted.
 */
final class CryptTransform
{
    /**
     * @param \Closure(string): string $forStrings
     * @param \Closure(string): string $forStreams applied to a stream's
     *        *encoded* bytes: encryption wraps the filter chain rather
     *        than sitting inside it, so a Flate stream is deflated first
     *        and enciphered second, and must be deciphered first and
     *        inflated second.
     */
    public static function apply(PdfValue $value, \Closure $forStrings, \Closure $forStreams): PdfValue
    {
        if ($value instanceof PdfString) {
            return PdfString::raw($forStrings($value->bytes()));
        }

        if ($value instanceof PdfHexString) {
            return new PdfHexString($forStrings($value->bytes()));
        }

        if ($value instanceof PdfArray) {
            return new PdfArray(...array_map(
                static fn (PdfValue $item): PdfValue => self::apply($item, $forStrings, $forStreams),
                $value->items(),
            ));
        }

        if ($value instanceof Stream) {
            return self::rebuild(
                new Stream(
                    $value->objectId(),
                    $forStreams($value->encodedBytes()),
                    // Already in final encoded form; compressing again
                    // would both corrupt it and contradict its /Filter.
                    compress: false,
                    generation: $value->generation(),
                ),
                $value,
                $forStrings,
                $forStreams,
            );
        }

        if ($value instanceof Dictionary) {
            return self::rebuild(
                $value->hasObjectId() ? new Dictionary($value->objectId(), $value->generation()) : new Dictionary(),
                $value,
                $forStrings,
                $forStreams,
            );
        }

        // Numbers, names, booleans, nulls and references carry nothing
        // that is ever enciphered.
        return $value;
    }

    /** Whether a stream is one the spec leaves in the clear. */
    public static function isNeverEncrypted(Dictionary $object, bool $encryptMetadata): bool
    {
        $type = $object->get('Type');

        if (!$type instanceof PdfName) {
            return false;
        }

        return match ($type->value()) {
            // A cross-reference stream has to be readable before any key
            // can be derived, since it is what leads to /Encrypt.
            'XRef' => true,
            'Metadata' => !$encryptMetadata,
            default => false,
        };
    }

    private static function rebuild(
        Dictionary $target,
        Dictionary $source,
        \Closure $forStrings,
        \Closure $forStreams,
    ): Dictionary {
        foreach ($source->entries() as $key => $entry) {
            $target->set((string) $key, self::apply($entry, $forStrings, $forStreams));
        }

        return $target;
    }
}
