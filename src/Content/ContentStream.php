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

    public function setLineWidth(float $widthPt): static
    {
        $this->buffer .= sprintf("%s w\n", self::num($widthPt));

        return $this;
    }

    public function setStrokeColorRgb(float $r, float $g, float $b): static
    {
        $this->buffer .= sprintf("%s %s %s RG\n", self::num($r), self::num($g), self::num($b));

        return $this;
    }

    public function setFillColorRgb(float $r, float $g, float $b): static
    {
        $this->buffer .= sprintf("%s %s %s rg\n", self::num($r), self::num($g), self::num($b));

        return $this;
    }

    public function moveTo(float $x, float $y): static
    {
        $this->buffer .= sprintf("%s %s m\n", self::num($x), self::num($y));

        return $this;
    }

    public function lineTo(float $x, float $y): static
    {
        $this->buffer .= sprintf("%s %s l\n", self::num($x), self::num($y));

        return $this;
    }

    /** Cubic Bezier curve from the current point, via two control points, to (x3, y3). */
    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): static
    {
        $this->buffer .= sprintf(
            "%s %s %s %s %s %s c\n",
            self::num($x1), self::num($y1), self::num($x2), self::num($y2), self::num($x3), self::num($y3),
        );

        return $this;
    }

    public function rect(float $x, float $y, float $width, float $height): static
    {
        $this->buffer .= sprintf("%s %s %s %s re\n", self::num($x), self::num($y), self::num($width), self::num($height));

        return $this;
    }

    public function closePath(): static
    {
        $this->buffer .= "h\n";

        return $this;
    }

    public function stroke(): static
    {
        $this->buffer .= "S\n";

        return $this;
    }

    public function fill(): static
    {
        $this->buffer .= "f\n";

        return $this;
    }

    public function fillAndStroke(): static
    {
        $this->buffer .= "B\n";

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
