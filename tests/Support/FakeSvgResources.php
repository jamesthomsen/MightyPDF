<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Support;

use MightyPDF\Content\Svg\SvgGradient;
use MightyPDF\Content\Svg\SvgPattern;
use MightyPDF\Content\Svg\SvgRasterImage;
use MightyPDF\Content\Svg\SvgResources;

/**
 * The caller side of a drawing, for tests about what the renderer emits
 * rather than about what a real document does with it.
 *
 * Every resource is unsupported unless a test says otherwise, which is
 * the interesting default twice over: it is what SvgResources documents
 * a null answer degrading to, and it makes a test that quietly starts
 * needing a resource it never asked for say so.
 *
 * Opacity is the exception -- it throws rather than returning a name --
 * because a shape drawn at full opacity must not ask for an ExtGState at
 * all. Returning a name there would let that regression through, and
 * returning null would make it indistinguishable from a caller that
 * cannot resource one.
 */
final class FakeSvgResources implements SvgResources
{
    /**
     * @param (\Closure(float, float): string)|null $extGState
     * @param (\Closure(SvgGradient, array, array): ?string)|null $shadingPattern
     * @param (\Closure(SvgPattern, string, array, array): ?string)|null $tilingPattern
     * @param (\Closure(SvgGradient, array, float): ?string)|null $softMask
     * @param (\Closure(string): ?SvgRasterImage)|null $image
     */
    public function __construct(
        private readonly ?\Closure $extGState = null,
        private readonly ?\Closure $shadingPattern = null,
        private readonly ?\Closure $tilingPattern = null,
        private readonly ?\Closure $softMask = null,
        private readonly ?\Closure $image = null,
    ) {
    }

    public function extGStateResourceName(float $fillAlpha, float $strokeAlpha): string
    {
        if ($this->extGState === null) {
            throw new \LogicException('No opacity < 1 expected in this test.');
        }

        return ($this->extGState)($fillAlpha, $strokeAlpha);
    }

    public function shadingPatternResourceName(SvgGradient $gradient, array $matrix, array $boundingBox): ?string
    {
        return $this->shadingPattern === null
            ? null
            : ($this->shadingPattern)($gradient, $matrix, $boundingBox);
    }

    public function tilingPatternResourceName(SvgPattern $pattern, string $content, array $matrix, array $boundingBox): ?string
    {
        return $this->tilingPattern === null
            ? null
            : ($this->tilingPattern)($pattern, $content, $matrix, $boundingBox);
    }

    public function softMaskResourceName(SvgGradient $gradient, array $boundingBox, float $strokeWidth): ?string
    {
        return $this->softMask === null
            ? null
            : ($this->softMask)($gradient, $boundingBox, $strokeWidth);
    }

    public function svgImageResource(string $bytes): ?SvgRasterImage
    {
        return $this->image === null ? null : ($this->image)($bytes);
    }
}
