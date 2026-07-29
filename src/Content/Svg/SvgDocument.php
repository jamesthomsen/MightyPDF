<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\ContentStream;

/**
 * Parses an SVG document and can replay it as ContentStream operators.
 *
 * Scope (the "practical common subset" agreed with the user): paths
 * (lines/curves/arcs via SvgPathParser/SvgArc), basic shapes (rect,
 * circle, ellipse, line, polyline, polygon), solid fill/stroke colors,
 * opacity, and simple transforms (translate/scale/rotate/skew/matrix via
 * SvgTransform). No gradients, patterns, filters, embedded raster
 * images, text, CSS cascading beyond a flat "style" attribute, or
 * animation -- elements using those are skipped rather than
 * mis-rendered.
 */
final class SvgDocument
{
    private const array SKIPPED_TAGS = [
        'defs', 'title', 'desc', 'metadata', 'text', 'style', 'symbol',
        'clipPath', 'mask', 'linearGradient', 'radialGradient', 'pattern',
        'filter', 'image', 'use', 'animate', 'animateTransform', 'animateMotion',
    ];

    private function __construct(
        public readonly float $viewBoxX,
        public readonly float $viewBoxY,
        public readonly float $viewBoxWidth,
        public readonly float $viewBoxHeight,
        private readonly \SimpleXMLElement $root,
    ) {
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
        $root = simplexml_load_string($svg);
        libxml_use_internal_errors($previousSetting);

        if ($root === false) {
            throw new \InvalidArgumentException('Malformed SVG/XML.');
        }

        [$vx, $vy, $vw, $vh] = self::readViewBox($root);

        return new self($vx, $vy, $vw, $vh, $root);
    }

    /**
     * Emits this SVG's content as ContentStream operators. $extGStateResourceName
     * is called (fillAlpha, strokeAlpha) => resourceName whenever an
     * element needs partial opacity, so the caller (PageBuilder) can wire
     * the actual /ExtGState resource into the page -- this class has no
     * knowledge of Document/registry/resources itself.
     */
    public function render(ContentStream $stream, \Closure $extGStateResourceName): void
    {
        self::renderChildren($this->root, $stream, $extGStateResourceName, new SvgStyle());
    }

    private static function renderElement(
        \SimpleXMLElement $element,
        ContentStream $stream,
        \Closure $extGStateResourceName,
        SvgStyle $inheritedStyle,
    ): void {
        $tag = $element->getName();

        if (in_array($tag, self::SKIPPED_TAGS, true)) {
            return;
        }

        $style = $inheritedStyle->merge($element);
        $matrices = SvgTransform::parse(isset($element['transform']) ? (string) $element['transform'] : null);

        if ($matrices !== []) {
            $stream->pushGraphicsState();
            foreach ($matrices as $matrix) {
                $stream->concatMatrix(...$matrix);
            }
        }

        match ($tag) {
            'g', 'svg' => self::renderChildren($element, $stream, $extGStateResourceName, $style),
            'path' => self::renderPath($element, $stream, $style, $extGStateResourceName),
            'rect' => self::renderRect($element, $stream, $style, $extGStateResourceName),
            'circle' => self::renderCircle($element, $stream, $style, $extGStateResourceName),
            'ellipse' => self::renderEllipse($element, $stream, $style, $extGStateResourceName),
            'line' => self::renderLine($element, $stream, $style),
            'polyline' => self::renderPoly($element, $stream, $style, $extGStateResourceName, closed: false),
            'polygon' => self::renderPoly($element, $stream, $style, $extGStateResourceName, closed: true),
            default => null, // unrecognized element: skip, don't fail the whole document
        };

        if ($matrices !== []) {
            $stream->popGraphicsState();
        }
    }

    private static function renderChildren(
        \SimpleXMLElement $element,
        ContentStream $stream,
        \Closure $extGStateResourceName,
        SvgStyle $style,
    ): void {
        foreach ($element->children() as $child) {
            self::renderElement($child, $stream, $extGStateResourceName, $style);
        }
    }

    private static function renderPath(
        \SimpleXMLElement $element,
        ContentStream $stream,
        SvgStyle $style,
        \Closure $extGStateResourceName,
    ): void {
        if (!isset($element['d'])) {
            return;
        }

        self::applyStyle($stream, $style, $extGStateResourceName);
        SvgPathParser::apply((string) $element['d'], $stream);
        self::finishPainting($stream, $style);
    }

    private static function renderRect(
        \SimpleXMLElement $element,
        ContentStream $stream,
        SvgStyle $style,
        \Closure $extGStateResourceName,
    ): void {
        $width = (float) ($element['width'] ?? 0);
        $height = (float) ($element['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return;
        }

        self::applyStyle($stream, $style, $extGStateResourceName);
        $stream->rect((float) ($element['x'] ?? 0), (float) ($element['y'] ?? 0), $width, $height);
        self::finishPainting($stream, $style);
    }

    private static function renderCircle(
        \SimpleXMLElement $element,
        ContentStream $stream,
        SvgStyle $style,
        \Closure $extGStateResourceName,
    ): void {
        $r = (float) ($element['r'] ?? 0);
        if ($r <= 0) {
            return;
        }

        self::applyStyle($stream, $style, $extGStateResourceName);
        self::ellipsePath($stream, (float) ($element['cx'] ?? 0), (float) ($element['cy'] ?? 0), $r, $r);
        self::finishPainting($stream, $style);
    }

    private static function renderEllipse(
        \SimpleXMLElement $element,
        ContentStream $stream,
        SvgStyle $style,
        \Closure $extGStateResourceName,
    ): void {
        $rx = (float) ($element['rx'] ?? 0);
        $ry = (float) ($element['ry'] ?? 0);
        if ($rx <= 0 || $ry <= 0) {
            return;
        }

        self::applyStyle($stream, $style, $extGStateResourceName);
        self::ellipsePath($stream, (float) ($element['cx'] ?? 0), (float) ($element['cy'] ?? 0), $rx, $ry);
        self::finishPainting($stream, $style);
    }

    /** Lines only ever stroke -- fill is not meaningful for a zero-area path, per spec. */
    private static function renderLine(\SimpleXMLElement $element, ContentStream $stream, SvgStyle $style): void
    {
        if ($style->stroke === null) {
            return;
        }

        $stream->setStrokeColorRgb(...$style->stroke)->setLineWidth($style->strokeWidth);
        $stream->moveTo((float) ($element['x1'] ?? 0), (float) ($element['y1'] ?? 0));
        $stream->lineTo((float) ($element['x2'] ?? 0), (float) ($element['y2'] ?? 0));
        $stream->stroke();
    }

    private static function renderPoly(
        \SimpleXMLElement $element,
        ContentStream $stream,
        SvgStyle $style,
        \Closure $extGStateResourceName,
        bool $closed,
    ): void {
        $points = self::parsePoints(isset($element['points']) ? (string) $element['points'] : '');
        if (count($points) < 2) {
            return;
        }

        self::applyStyle($stream, $style, $extGStateResourceName);
        $stream->moveTo($points[0][0], $points[0][1]);
        for ($i = 1, $count = count($points); $i < $count; ++$i) {
            $stream->lineTo($points[$i][0], $points[$i][1]);
        }
        if ($closed) {
            $stream->closePath();
        }
        self::finishPainting($stream, $style, fillable: $closed);
    }

    private static function ellipsePath(ContentStream $stream, float $cx, float $cy, float $rx, float $ry): void
    {
        // Standard 4-Bezier-arc circle/ellipse approximation.
        $kx = 0.5522847498307936 * $rx;
        $ky = 0.5522847498307936 * $ry;

        $stream->moveTo($cx + $rx, $cy)
            ->curveTo($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry)
            ->curveTo($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy)
            ->curveTo($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry)
            ->curveTo($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy)
            ->closePath();
    }

    /** @return list<array{0: float, 1: float}> */
    private static function parsePoints(string $text): array
    {
        preg_match_all('/-?\d*\.?\d+(?:[eE][+-]?\d+)?/', $text, $matches);
        $numbers = array_map(floatval(...), $matches[0]);

        $points = [];
        for ($i = 0, $count = count($numbers); $i + 1 < $count; $i += 2) {
            $points[] = [$numbers[$i], $numbers[$i + 1]];
        }

        return $points;
    }

    private static function applyStyle(ContentStream $stream, SvgStyle $style, \Closure $extGStateResourceName): void
    {
        if ($style->fillOpacity < 1.0 || $style->strokeOpacity < 1.0) {
            $stream->setExtGState($extGStateResourceName($style->fillOpacity, $style->strokeOpacity));
        }
        if ($style->fill !== null) {
            $stream->setFillColorRgb(...$style->fill);
        }
        if ($style->stroke !== null) {
            $stream->setStrokeColorRgb(...$style->stroke)->setLineWidth($style->strokeWidth);
        }
    }

    private static function finishPainting(ContentStream $stream, SvgStyle $style, bool $fillable = true): void
    {
        $hasFill = $fillable && $style->fill !== null;
        $hasStroke = $style->stroke !== null;

        if ($hasFill && $hasStroke) {
            $stream->fillAndStroke($style->evenOdd);
        } elseif ($hasFill) {
            $stream->fill($style->evenOdd);
        } elseif ($hasStroke) {
            $stream->stroke();
        } else {
            $stream->endPathNoOp();
        }
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
