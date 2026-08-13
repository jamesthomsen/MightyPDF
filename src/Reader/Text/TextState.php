<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Text;

/**
 * The graphics and text state a content stream builds up as it runs.
 *
 * Two matrices matter and they are easy to conflate. The **CTM** is the
 * page's current transform, changed by `cm` and saved and restored by
 * `q`/`Q`. The **text matrix** is where the pen is inside a BT/ET block,
 * changed by `Tm`, `Td` and by every character shown. Where a glyph lands
 * on the page is the product of the two, and a reader that tracks only one
 * of them puts text in the right place until the first page that scales or
 * translates its content -- which is every page produced by a tool that
 * imposes, stamps, or places anything as an XObject.
 *
 * Matrices are [a, b, c, d, e, f], the six numbers PDF writes them as
 * (§8.3.3), standing for
 *
 *     | a b 0 |
 *     | c d 0 |
 *     | e f 1 |
 */
final class TextState
{
    /** @var list<float> the current transformation matrix */
    public array $ctm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    /** @var list<float> */
    public array $textMatrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    /** @var list<float> where the current line began */
    public array $lineMatrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    public ?FontDecoder $font = null;

    public float $fontSize = 0.0;
    public float $characterSpacing = 0.0;
    public float $wordSpacing = 0.0;
    public float $horizontalScale = 1.0;
    public float $leading = 0.0;
    public float $rise = 0.0;
    public int $renderMode = 0;

    /** @var list<TextFragment> */
    public array $fragments = [];

    /** @var list<array{list<float>, ?FontDecoder, float, float, float, float, float, float, int}> */
    private array $stack = [];

    public function push(): void
    {
        $this->stack[] = [
            $this->ctm,
            $this->font,
            $this->fontSize,
            $this->characterSpacing,
            $this->wordSpacing,
            $this->horizontalScale,
            $this->leading,
            $this->rise,
            $this->renderMode,
        ];
    }

    public function pop(): void
    {
        $saved = array_pop($this->stack);

        if ($saved === null) {
            // More Q than q. Malformed, and common enough in generated
            // files that stopping would cost real text; the state simply
            // stays as it is.
            return;
        }

        [
            $this->ctm,
            $this->font,
            $this->fontSize,
            $this->characterSpacing,
            $this->wordSpacing,
            $this->horizontalScale,
            $this->leading,
            $this->rise,
            $this->renderMode,
        ] = $saved;
    }

    /** @param list<float> $matrix */
    public function concat(array $matrix): void
    {
        if (count($matrix) >= 6) {
            $this->ctm = self::multiply(array_slice($matrix, 0, 6), $this->ctm);
        }
    }

    public function beginText(): void
    {
        $this->textMatrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $this->lineMatrix = $this->textMatrix;
    }

    public function endText(): void
    {
        // Nothing to reset: BT sets both matrices, and a stray ET should
        // not disturb a state some producers keep using afterwards.
    }

    /** @param list<float> $matrix */
    public function setTextMatrix(array $matrix): void
    {
        if (count($matrix) >= 6) {
            $this->textMatrix = array_slice($matrix, 0, 6);
            $this->lineMatrix = $this->textMatrix;
        }
    }

    /** Td: a new line origin, relative to the current line's. */
    public function nextLine(float $dx, float $dy): void
    {
        $this->lineMatrix = self::multiply([1.0, 0.0, 0.0, 1.0, $dx, $dy], $this->lineMatrix);
        $this->textMatrix = $this->lineMatrix;
    }

    /** Moves the pen along the current line by a displacement in text space. */
    public function advance(float $distance): void
    {
        $this->textMatrix = self::multiply([1.0, 0.0, 0.0, 1.0, $distance, 0.0], $this->textMatrix);
    }

    /** Where the pen is now, in page space. */
    public function x(): float
    {
        return $this->deviceMatrix()[4];
    }

    public function y(): float
    {
        return $this->deviceMatrix()[5];
    }

    /**
     * The font size as it appears on the page, rather than as declared.
     *
     * A producer may set a size of 1 and scale by 12 in the matrix, which
     * is common enough that trusting Tf alone puts every line of such a
     * document in its own "line" when the fragments are grouped by size.
     */
    public function effectiveFontSize(): float
    {
        // The vertical scale that the text and page matrices between them
        // apply -- the length of the transformed unit y vector. The font
        // size itself is deliberately not in this product: it is the thing
        // being scaled.
        $matrix = self::multiply($this->textMatrix, $this->ctm);

        return $this->fontSize * sqrt($matrix[2] ** 2 + $matrix[3] ** 2);
    }

    /** @return list<float> text space through to page space */
    private function deviceMatrix(): array
    {
        return self::multiply(
            self::multiply(
                [$this->fontSize * $this->horizontalScale, 0.0, 0.0, $this->fontSize, 0.0, $this->rise],
                $this->textMatrix,
            ),
            $this->ctm,
        );
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     * @return list<float>
     */
    private static function multiply(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }
}
