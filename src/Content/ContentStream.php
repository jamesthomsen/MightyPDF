<?php

declare(strict_types=1);

namespace MightyPDF\Content;

use MightyPDF\Assembler\Types\PdfNumberFormat;
use MightyPDF\Assembler\Types\PdfString;

/**
 * Accumulates PDF content-stream operator bytes (ISO 32000-2 §9 for text,
 * §8 for graphics) as a flat, imperative sequence -- mirroring how a PDF
 * content stream itself is just a flat operator sequence, no AST needed.
 *
 * Has no knowledge of Document/registry/resources: it only knows how to
 * format operators. PageBuilder is the layer above that decides *when*
 * to call these methods and wires up whatever resources (fonts, images)
 * they reference by name.
 */
final class ContentStream
{
    private string $buffer = '';

    public function beginText(): static
    {
        $this->buffer .= "BT\n";

        return $this;
    }

    public function endText(): static
    {
        $this->buffer .= "ET\n";

        return $this;
    }

    public function setFont(string $resourceName, float $sizePt): static
    {
        $this->buffer .= sprintf("/%s %s Tf\n", $resourceName, self::num($sizePt));

        return $this;
    }

    /** $winAnsiBytes must already be encoded -- see WinAnsiEncoding::encode(). */
    public function showTextAt(float $x, float $y, string $winAnsiBytes): static
    {
        $this->buffer .= sprintf(
            "1 0 0 1 %s %s Tm\n%s Tj\n",
            self::num($x),
            self::num($y),
            PdfString::latin1($winAnsiBytes)->format(),
        );

        return $this;
    }

    public function bytes(): string
    {
        return $this->buffer;
    }

    public function isEmpty(): bool
    {
        return $this->buffer === '';
    }

    private static function num(float $value): string
    {
        return PdfNumberFormat::format($value);
    }
}
