<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

/**
 * Per-character advance widths (in 1/1000 em units, per ISO 32000-2
 * §9.2.4) for a single font, keyed by WinAnsi code point.
 */
final class FontMetrics
{
    private const int DEFAULT_WIDTH = 500;

    /** @param array<int, int> $widths WinAnsi code point => advance width */
    public function __construct(
        private readonly array $widths,
        private readonly int $defaultWidth = self::DEFAULT_WIDTH,
    ) {
    }

    public static function fixedWidth(int $width): self
    {
        return new self([], $width);
    }

    public function widthOfCode(int $code): int
    {
        return $this->widths[$code] ?? $this->defaultWidth;
    }

    /**
     * The rendered width, in points, of $winAnsiBytes at $sizePt -- the
     * bytes must already be WinAnsi-encoded (see WinAnsiEncoding::encode()).
     */
    public function widthOf(string $winAnsiBytes, float $sizePt): float
    {
        $total = 0;
        for ($i = 0, $length = strlen($winAnsiBytes); $i < $length; ++$i) {
            $total += $this->widthOfCode(ord($winAnsiBytes[$i]));
        }

        return $total / 1000.0 * $sizePt;
    }
}
