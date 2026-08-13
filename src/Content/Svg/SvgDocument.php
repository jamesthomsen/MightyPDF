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
 * pattern paint servers, embedded raster images, text -- including text
 * laid along a path with <textPath> -- and CSS from a <style> block. No
 * filters or animation: elements using those are skipped rather than
 * mis-rendered.
 */
final class SvgDocument
{
    /** @var array<string, SvgGradient> */
    private readonly array $gradients;

    /** @var array<string, SvgPattern> */
    private readonly array $patterns;

    /** @var array<string, string> id => the "d" of a path anything may lay text along */
    private readonly array $paths;

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

        // Same reason again: a <textPath> may name a path defined after
        // it, and usually names one inside <defs> that is never drawn.
        $this->paths = self::collectPaths($root);

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

        // A coordinate system with no extent is not one, and every caller
        // divides the space it was given by these two to place the
        // drawing. Left alone, a zero here is a DivisionByZeroError out of
        // drawSvg() -- an \Error rather than an \Exception, so it goes
        // straight past a caller catching what the rest of this library
        // throws. A negative is the spec's own error case (§7.7: "a
        // negative value for <width> or <height> is an error"), and its
        // symptom is a drawing silently mirrored rather than a refusal.
        if ($vw <= 0.0 || $vh <= 0.0) {
            throw new \InvalidArgumentException(sprintf(
                'This SVG\'s coordinate system is %s x %s, so there is nothing to scale onto the page. '
                . 'A viewBox (or a width and height) has to have a positive extent in both directions.',
                self::describe($vw),
                self::describe($vh),
            ));
        }

        return new self($vx, $vy, $vw, $vh, $root);
    }

    /** A float in a message, without a tail of zeroes. */
    private static function describe(float $value): string
    {
        return is_finite($value)
            ? rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.')
            : (string) $value;
    }

    /**
     * Emits this SVG's content as ContentStream operators.
     *
     * $resources turns each resource this drawing needs into the name
     * the content stream refers to it by, so that this class needs no
     * knowledge of Document/registry/resources -- see SvgResources, whose
     * nulls are how a caller that cannot supply a given resource degrades
     * that element rather than failing the drawing.
     *
     * $baseMatrix is what the caller has already transformed this
     * drawing by, which a gradient has to know about; see
     * SvgShadingPattern for why a pattern cannot simply inherit it.
     *
     * $textFontResourceName is separate from $resources because it is
     * caller *policy* rather than bookkeeping: it decides which font a
     * piece of text is set in, which a caller may override per drawing.
     * Null skips text entirely, as does a null result for one run.
     *
     * Public only because PHP has no way to say "public to the content
     * layer". The supported way to put an SVG on a page is
     * PageBuilder::drawSvg(), which is what supplies every one of these
     * arguments; this signature answers to that caller and changes with
     * it.
     *
     * @internal
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $baseMatrix
     * @param (\Closure(SvgStyle): ?SvgTextFont)|null $textFontResourceName
     */
    public function render(
        ContentStream $stream,
        SvgResources $resources,
        array $baseMatrix = SvgTransform::IDENTITY,
        ?\Closure $textFontResourceName = null,
    ): void {
        $renderer = new SvgRenderer(
            $stream,
            $this->gradients,
            $this->stylesheet,
            $resources,
            $textFontResourceName,
            $this->patterns,
            paths: $this->paths,
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

    /** @return array<string, string> */
    private static function collectPaths(\SimpleXMLElement $root): array
    {
        $paths = [];

        foreach ($root->xpath("//*[local-name()='path']") ?: [] as $element) {
            $id = isset($element['id']) ? (string) $element['id'] : '';

            if ($id !== '' && isset($element['d'])) {
                $paths[$id] = (string) $element['d'];
            }
        }

        return $paths;
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
