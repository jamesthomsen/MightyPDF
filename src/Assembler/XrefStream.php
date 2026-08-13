<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;

/**
 * A cross-reference stream (ISO 32000-2 §7.5.8): the same information a
 * classic table carries, as compressed binary rows, in an object that is
 * its own trailer.
 *
 * Written when the file being updated already uses this format, and for
 * one hard reason: a classic table whose /Prev points at a cross-reference
 * stream is not a conforming chain. Such a file *appears* to work --
 * poppler reads it happily -- but Ghostscript reports "xref table was
 * repaired", meaning it threw the cross-reference information away and
 * rebuilt it by scanning. A file that opens only because readers rescue it
 * is a file that will eventually meet one that does not.
 *
 * Rows come in two of the three kinds §7.5.8.3 defines. Type 1 is an
 * object at a byte offset, which is what an incremental update produces
 * throughout. Type 2 is an object packed inside an object stream, which a
 * document saved with Document::compressObjects() produces for most of its
 * objects (see ObjectStream). Type 0 is a free entry, and nothing here ever
 * writes one: this library appends and rewrites, and never frees.
 */
final class XrefStream
{
    /**
     * Field widths for /W. Fixed rather than computed from the actual
     * values: four bytes of offset covers any file up to 4 GiB, and the
     * few bytes a tighter fit would save are not worth a width that
     * changes with the input.
     */
    private const int TYPE_WIDTH = 1;
    private const int OFFSET_WIDTH = 4;
    private const int GENERATION_WIDTH = 2;

    /**
     * A type-2 row reuses the same two fields for a different pair of
     * numbers: the object stream holding the object, and its index within
     * it. Both fit comfortably -- an object number in four bytes, and an
     * index in two, ObjectStream::CAPACITY being a few hundred.
     */

    public static function build(int $objectId, Xref $xref, Trailer $trailer): Stream
    {
        $index = [];
        $rows = '';

        foreach ($xref->contiguousRuns() as $run) {
            $index[] = new PdfInteger($run[0]);
            $index[] = new PdfInteger(count($run));

            foreach ($run as $id) {
                $compressed = $xref->compressedLocationOf($id);

                $rows .= $compressed === null
                    ? self::bigEndian(1, self::TYPE_WIDTH)
                        . self::bigEndian($xref->offsetOf($id), self::OFFSET_WIDTH)
                        . self::bigEndian($xref->generationOf($id), self::GENERATION_WIDTH)
                    : self::bigEndian(2, self::TYPE_WIDTH)
                        . self::bigEndian($compressed[0], self::OFFSET_WIDTH)
                        . self::bigEndian($compressed[1], self::GENERATION_WIDTH);
            }
        }

        $stream = new Stream($objectId, $rows);

        // The trailer's keys go in this dictionary, because a
        // cross-reference stream *is* the trailer -- there is no separate
        // "trailer <<...>>" for a reader to find.
        foreach ($trailer->entries()->entries() as $key => $value) {
            $stream->set((string) $key, $value);
        }

        $stream->set('Type', new PdfName('XRef'));
        $stream->set('W', new PdfArray(
            new PdfInteger(self::TYPE_WIDTH),
            new PdfInteger(self::OFFSET_WIDTH),
            new PdfInteger(self::GENERATION_WIDTH),
        ));
        $stream->set('Index', new PdfArray(...$index));

        return $stream;
    }

    private static function bigEndian(int $value, int $width): string
    {
        $out = '';

        for ($shift = ($width - 1) * 8; $shift >= 0; $shift -= 8) {
            $out .= chr(($value >> $shift) & 0xFF);
        }

        return $out;
    }
}
