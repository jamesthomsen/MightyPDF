<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\ContentStream;

/**
 * Parses an SVG document: its coordinate system, its element tree, and
 * the gradients anything in it may be painted with. Drawing it is
 * SvgRenderer's job.
 *
 * Scope (the "practical common subset" agreed with the user): paths
 * (lines/curves/arcs via SvgPathParser/SvgArc), basic shapes (rect,
 * circle, ellipse, line, polyline, polygon), fill/stroke in flat colours
 * or linear/radial gradients, opacity, and simple transforms
 * (translate/scale/rotate/skew/matrix via SvgTransform), gradient and
 * pattern paint servers, embedded raster images, text, and CSS from a
 * <style> block. No filters, <textPath>, or animation -- elements using
 * those are skipped rather than mis-rendered.
 */
final class SvgDocument
{
    /** @var array<string, SvgGradient> */
    private readonly array $gradients;

    /** @var array<string, SvgPattern> */
    private readonly array $patterns;

    private readonly SvgStylesheet $stylesheet;

    private function __construct(
        public readonly float $viewBoxX,
        public readonly float $viewBoxY,
        public readonly float $viewBoxWidth,
        public readonly float $viewBoxHeight,
        private readonly \SimpleXMLElement $root,
    ) {
        // Collected up front rather than on demand: a shape may be
        // painted with a gradient defined later in the file, and one
        // gradient may inherit from another. Neither can be resolved
        // while walking the tree in order.
        $this->gradients = SvgGradientParser::collect($root, $viewBoxWidth, $viewBoxHeight);
        $this->patterns = SvgPatternParser::collect($root);

        // Same reason, one step earlier: a rule in a <style> block
        // applies to elements anywhere in the document, including ones
        // already walked past by the time the block is reached.
        $this->stylesheet = SvgStylesheet::parse($root);
    }

    public static function fromFile(string $path): self
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read SVG file: $path");
        }

        return self::fromString($contents);
    }

    public static function fromString(string $svg): self
    {
        $previousSetting = libxml_use_internal_errors(true);
        // LIBXML_NONET: this parses untrusted SVG uploads, so external
        // entity/DTD resolution must never touch the network or
        // filesystem, regardless of libxml's own default for the PHP
        // build this runs under.
        $root = simplexml_load_string($svg, options: LIBXML_NONET);
        libxml_use_internal_errors($previousSetting);

        if ($root === false) {
            throw new \InvalidArgumentException('Malformed SVG/XML.');
        }

        [$vx, $vy, $vw, $vh] = self::readViewBox($root);

        return new self($vx, $vy, $vw, $vh, $root);
    }

    /**
     * Emits this SVG's content as ContentStream operators.
     *
     * The two callbacks turn a resource this drawing needs into the name
     * the content stream refers to it by, so that this class needs no
     * knowledge of Document/registry/resources: $extGStateResourceName
     * is called (fillAlpha, strokeAlpha) => name for partial opacity,
     * and $shadingPatternResourceName (gradient, matrix, boundingBox) =>
     * name for a gradient fill.
     *
     * The last two are optional, and a caller that omits them gets the
     * behaviour from before those features existed: gradient fills and
     * <image> elements are skipped rather than failing.
     * $baseMatrix is what the caller has already transformed this
     * drawing by, which a gradient has to know about; see
     * SvgShadingPattern for why a pattern cannot simply inherit it.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $baseMatrix
     */
    public function render(
        ContentStream $stream,
        \Closure $extGStateResourceName,
        ?\Closure $shadingPatternResourceName = null,
        array $baseMatrix = SvgTransform::IDENTITY,
        ?\Closure $imageResourceName = null,
        ?\Closure $textFontResourceName = null,
        ?\Closure $tilingPatternResourceName = null,
    ): void {
        $renderer = new SvgRenderer(
            $stream,
            $this->gradients,
            $this->stylesheet,
            $extGStateResourceName,
            $shadingPatternResourceName,
            $imageResourceName,
            $textFontResourceName,
            $this->patterns,
            $tilingPatternResourceName,
        );

        $renderer->render($this->root, $baseMatrix);
    }

    /** @return array<string, SvgGradient> the gradients this document defines, by id */
    public function gradients(): array
    {
        return $this->gradients;
    }

    /** @return array<string, SvgPattern> the patterns this document defines, by id */
    public function patterns(): array
    {
        return $this->patterns;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} minX, minY, width, height */
    private static function readViewBox(\SimpleXMLElement $root): array
    {
        if (isset($root['viewBox'])) {
            $parts = preg_split('/[\s,]+/', trim((string) $root['viewBox']));
            if ($parts !== false && count($parts) === 4) {
                return array_map(floatval(...), $parts);
            }
        }

        if (isset($root['width'], $root['height'])) {
            $width = self::parseLength((string) $root['width']);
            $height = self::parseLength((string) $root['height']);

            return [0.0, 0.0, $width, $height];
        }

        throw new \InvalidArgumentException('SVG has neither a viewBox nor width/height; cannot establish its coordinate system.');
    }

    private static function parseLength(string $value): float
    {
        // Strips common unit suffixes (px, pt, mm, etc.) and treats the
        // numeric part as user units -- a practical simplification, not
        // real CSS unit conversion.
        if (!preg_match('/-?\d*\.?\d+/', $value, $m)) {
            throw new \InvalidArgumentException("Malformed SVG length: \"$value\"");
        }

        return (float) $m[0];
    }
}
