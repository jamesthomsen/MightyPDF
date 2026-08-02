<?php

declare(strict_types=1);

namespace MightyPDF\Content;

use MightyPDF\Assembler\Types\PdfHexString;
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
final class ContentStream implements PathSink
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

    /**
     * $bytes must already be encoded for the font in effect -- see
     * FontWriter::encode().
     *
     * $hex writes them as "<...>" instead of "(...)", which is what a
     * font with two-byte codes needs: half of every code is a high byte
     * that may collide with the literal-string delimiters, and while
     * escaping handles that correctly, a hex string cannot get it wrong
     * and stays readable in the raw file.
     */
    public function showTextAt(float $x, float $y, string $bytes, bool $hex = false): static
    {
        $this->buffer .= sprintf(
            "1 0 0 1 %s %s Tm\n%s Tj\n",
            self::num($x),
            self::num($y),
            self::string($bytes, $hex),
        );

        return $this;
    }

    /**
     * Shows text as a sequence of runs with explicit gaps between them:
     * the TJ operator, where a number in the array moves the pen back by
     * that many thousandths of the current font size.
     *
     * This is how justified text is spaced out for a font whose codes
     * are two bytes wide, because the word-spacing operator (Tw) does
     * not apply to those at all (ISO 32000-2 §9.3.3) -- it acts on the
     * single byte 32, and a two-byte encoding has no such byte. Setting
     * Tw there is not an error, which is the problem: the text simply
     * comes out unjustified.
     *
     * @param list<string|float> $runs already-encoded strings to show,
     *        interleaved with gaps in thousandths of the font size
     */
    public function showTextRunsAt(float $x, float $y, array $runs, bool $hex = false): static
    {
        $parts = [];

        foreach ($runs as $run) {
            $parts[] = is_string($run) ? self::string($run, $hex) : self::num(-$run);
        }

        $this->buffer .= sprintf(
            "1 0 0 1 %s %s Tm\n[%s] TJ\n",
            self::num($x),
            self::num($y),
            implode(' ', $parts),
        );

        return $this;
    }

    /**
     * Sets word spacing (extra space added after every ASCII space byte,
     * 0x20, in subsequent Tj calls) -- part of the graphics state, so it
     * persists across BT/ET until explicitly changed again. Used for
     * justified paragraph text; callers that set a non-zero value are
     * responsible for resetting it back to 0 once they're done, since
     * nothing here does that automatically.
     */
    public function setWordSpacing(float $spacingPt): static
    {
        $this->buffer .= sprintf("%s Tw\n", self::num($spacingPt));

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

    public function fill(bool $evenOdd = false): static
    {
        $this->buffer .= ($evenOdd ? "f*\n" : "f\n");

        return $this;
    }

    public function fillAndStroke(bool $evenOdd = false): static
    {
        $this->buffer .= ($evenOdd ? "B*\n" : "B\n");

        return $this;
    }

    /** Ends the current path without painting it (e.g. neither fill nor stroke apply). */
    public function endPathNoOp(): static
    {
        $this->buffer .= "n\n";

        return $this;
    }

    /**
     * Fills with a named /Pattern resource -- a gradient, here -- rather
     * than with a flat colour.
     *
     * Two operators, because a pattern is not a colour: the colour space
     * has to be switched to /Pattern first, and only then does the name
     * mean anything. The pair also has to be re-emitted for each shape,
     * since any later "rg" switches the space back to DeviceRGB.
     */
    public function setFillPattern(string $resourceName): static
    {
        $this->buffer .= sprintf("/Pattern cs\n/%s scn\n", $resourceName);

        return $this;
    }

    public function setStrokePattern(string $resourceName): static
    {
        $this->buffer .= sprintf("/Pattern CS\n/%s SCN\n", $resourceName);

        return $this;
    }

    /** Activates a named ExtGState resource (e.g. for /ca, /CA alpha). */
    public function setExtGState(string $resourceName): static
    {
        $this->buffer .= sprintf("/%s gs\n", $resourceName);

        return $this;
    }

    public function pushGraphicsState(): static
    {
        $this->buffer .= "q\n";

        return $this;
    }

    public function popGraphicsState(): static
    {
        $this->buffer .= "Q\n";

        return $this;
    }

    /** Concatenates a transformation matrix [a b c d e f] onto the CTM. */
    public function concatMatrix(float $a, float $b, float $c, float $d, float $e, float $f): static
    {
        $this->buffer .= sprintf(
            "%s %s %s %s %s %s cm\n",
            self::num($a), self::num($b), self::num($c), self::num($d), self::num($e), self::num($f),
        );

        return $this;
    }

    /**
     * Paints an XObject (image or, in principle, a form XObject) at unit
     * scale under whatever CTM is currently in effect -- callers scale
     * and position it via concatMatrix() first. Wrapped in its own
     * q/.../Q by drawImage() below so the placement matrix never leaks
     * into subsequent operators.
     */
    public function paintXObject(string $resourceName): static
    {
        $this->buffer .= sprintf("/%s Do\n", $resourceName);

        return $this;
    }

    /** Places an image (or other unit-square XObject) at (x, y) sized width x height, in points. */
    public function drawImage(string $resourceName, float $x, float $y, float $width, float $height): static
    {
        return $this->pushGraphicsState()
            ->concatMatrix($width, 0, 0, $height, $x, $y)
            ->paintXObject($resourceName)
            ->popGraphicsState();
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

    private static function string(string $bytes, bool $hex): string
    {
        return $hex
            ? (new PdfHexString($bytes))->format()
            : PdfString::latin1($bytes)->format();
    }
}
