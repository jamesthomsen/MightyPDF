<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Filter;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Reader\ParseException;

/**
 * Runs a stream's /Filter chain to get at the bytes it actually holds.
 *
 * /Filter may be one name or an array of them applied in order, each with
 * its own entry in a parallel /DecodeParms -- so "ASCII85 then Flate" is
 * an ordinary thing to find, and the parameters have to line up with the
 * right stage.
 *
 * Image filters are named and refused rather than quietly returning their
 * encoded bytes. A caller asking for the content of a DCTDecode stream
 * has misunderstood something, and handing back JPEG bytes labelled as
 * decoded content would let that misunderstanding travel.
 */
final class StreamDecoder
{
    /**
     * Including the abbreviations, which belong to inline images rather
     * than stream dictionaries -- but cost nothing to accept and appear
     * in files whose writers were not fussy about the distinction.
     *
     * @var array<string, class-string<StreamFilter>>
     */
    private const array FILTERS = [
        'FlateDecode' => FlateDecode::class,
        'Fl' => FlateDecode::class,
        'LZWDecode' => LzwDecode::class,
        'LZW' => LzwDecode::class,
        'ASCII85Decode' => Ascii85Decode::class,
        'A85' => Ascii85Decode::class,
        'ASCIIHexDecode' => AsciiHexDecode::class,
        'AHx' => AsciiHexDecode::class,
        'RunLengthDecode' => RunLengthDecode::class,
        'RL' => RunLengthDecode::class,
    ];

    /** @var list<string> */
    private const array IMAGE_FILTERS = [
        'DCTDecode', 'DCT', 'JPXDecode', 'CCITTFaxDecode', 'CCF', 'JBIG2Decode',
    ];

    /**
     * @param (\Closure(?PdfValue): ?PdfValue)|null $resolve dereferences
     *        indirect values. Optional so that a cross-reference stream --
     *        which has to be decoded *before* any object can be looked up,
     *        it being the thing that says where objects are -- can be
     *        handled with no document behind it.
     */
    public function __construct(private readonly ?\Closure $resolve = null)
    {
    }

    public function decode(Stream $stream): string
    {
        $data = $stream->rawBytes();
        $filters = $this->filterNames($stream);
        $parms = $this->decodeParms($stream, count($filters));

        foreach ($filters as $index => $name) {
            if (in_array($name, self::IMAGE_FILTERS, true)) {
                throw new ParseException("Stream uses the image filter /$name, which this reader does not decode.");
            }

            $filter = self::FILTERS[$name] ?? throw new ParseException("Unknown stream filter /$name.");

            $data = (new $filter())->decode($data, $parms[$index] ?? new DecodeParms());
        }

        return $data;
    }

    /** Whether decode() can handle this stream without throwing. */
    public function canDecode(Stream $stream): bool
    {
        foreach ($this->filterNames($stream) as $name) {
            if (!isset(self::FILTERS[$name])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function filterNames(Stream $stream): array
    {
        $filter = $this->dereference($stream->get('Filter') ?? $stream->get('F'));

        if ($filter instanceof PdfName) {
            return [$filter->value()];
        }

        if (!$filter instanceof PdfArray) {
            return [];
        }

        $names = [];

        foreach ($filter->items() as $item) {
            $item = $this->dereference($item);

            if ($item instanceof PdfName) {
                $names[] = $item->value();
            }
        }

        return $names;
    }

    /** @return list<DecodeParms> */
    private function decodeParms(Stream $stream, int $filterCount): array
    {
        $parms = $this->dereference($stream->get('DecodeParms') ?? $stream->get('DP'));

        if ($parms instanceof PdfArray) {
            $out = [];

            foreach ($parms->items() as $item) {
                $item = $this->dereference($item);
                $out[] = DecodeParms::fromDictionary($item instanceof Dictionary ? $item : null, $this->resolve);
            }

            return $out;
        }

        // A single dictionary belongs to the single filter. Where there
        // are several filters it belongs to the first, and the rest take
        // their defaults -- which is what the padding below produces.
        $single = DecodeParms::fromDictionary($parms instanceof Dictionary ? $parms : null, $this->resolve);

        return array_map(
            static fn (int $index): DecodeParms => $index === 0 ? $single : new DecodeParms(),
            range(0, max(0, $filterCount - 1)),
        );
    }

    private function dereference(?PdfValue $value): ?PdfValue
    {
        return $this->resolve === null ? $value : ($this->resolve)($value);
    }
}
