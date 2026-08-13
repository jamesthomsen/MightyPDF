<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\ContentStream;

/**
 * Walks a parsed SVG element tree and emits the PDF operators that draw
 * it.
 *
 * Split out of SvgDocument, which now only parses: the walk carries four
 * pieces of state at once -- the inherited style, the transform in
 * effect, the stream, and the resources a drawing needs named -- and
 * threading those through a dozen static methods was turning every
 * signature into a parameter list.
 *
 * What this class is left with is the walk itself: which element becomes
 * which operators, and what each one inherits. The two jobs that are not
 * that have collaborators of their own -- SvgPaintServers decides what a
 * shape is painted with, SvgTextRenderer lays out <text> -- and both
 * share this renderer's stream and resources rather than owning any.
 *
 * The transform is tracked here even though PDF tracks its own CTM
 * perfectly well, because a gradient is painted through a pattern, and a
 * pattern's coordinates are measured from the page rather than from the
 * CTM. See SvgShadingPattern.
 */
final class SvgRenderer implements SvgTileSource
{
    private const array SKIPPED_TAGS = [
        'defs', 'title', 'desc', 'metadata', 'style', 'symbol',
        'clipPath', 'mask', 'linearGradient', 'radialGradient', 'pattern',
        'filter', 'use', 'animate', 'animateTransform', 'animateMotion',
    ];

    private readonly SvgTileCache $tiles;

    /**
     * The <text> elements are drawn by SvgTextRenderer, which shares
     * this renderer's stream and resources but almost none of its
     * concerns. Null where the caller supplies no font, in which case
     * text is skipped -- see SvgTextRenderer.
     */
    private readonly ?SvgTextRenderer $text;

    /**
     * What each shape is painted with. Shares this renderer's stream and
     * resources, and calls back into tileFor() for the one thing it
     * cannot do itself -- see SvgTileSource.
     */
    private readonly SvgPaintServers $paint;

    /**
     * @param array<string, SvgGradient> $gradients
     * @param SvgResources $resources every PDF resource this drawing
     *        needs, by name -- see SvgResources for what a null answer
     *        degrades to
     * @param (\Closure(SvgStyle): ?SvgTextFont)|null $textFontResourceName
     *        chooses and registers a font for a piece of text; null
     *        itself, or a null result, skips the text
     * @param array<string, SvgPattern> $patterns
     * @param list<string> $patternsBeingDrawn the ids whose content this
     *        renderer is already inside, so that a pattern painted with
     *        itself stops rather than recurring forever
     * @param array<string, string> $paths the "d" of every path with an
     *        id, for <textPath> to lay text along
     * @param ?SvgTileCache $tiles the tiles this document has drawn and
     *        how much drawing is left; shared with every nested
     *        renderer, and made fresh where a caller starts a drawing of
     *        its own
     */
    public function __construct(
        private readonly ContentStream $stream,
        private readonly array $gradients,
        private readonly SvgStylesheet $stylesheet,
        private readonly SvgResources $resources,
        private readonly ?\Closure $textFontResourceName = null,
        private readonly array $patterns = [],
        private readonly array $patternsBeingDrawn = [],
        private readonly array $paths = [],
        ?SvgTileCache $tiles = null,
    ) {
        $this->tiles = $tiles ?? new SvgTileCache();
        $this->text = $textFontResourceName === null
            ? null
            : new SvgTextRenderer($stream, $resources, $textFontResourceName, $stylesheet, $paths);
        $this->paint = new SvgPaintServers($stream, $resources, $this, $gradients, $patterns);
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $baseMatrix
     *        what the caller has already transformed this drawing by --
     *        the placement of the whole SVG on the page
     */
    public function render(\SimpleXMLElement $root, array $baseMatrix): void
    {
        $this->renderElement($root, new SvgStyle(), $baseMatrix, null);
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     */
    private function renderElement(
        \SimpleXMLElement $element,
        SvgStyle $inheritedStyle,
        array $matrix,
        ?SvgElementPath $path,
    ): void {
        $tag = $element->getName();

        if (in_array($tag, self::SKIPPED_TAGS, true)) {
            return;
        }

        $path ??= SvgElementPath::of($tag, self::attributesOfElement($element));
        $style = $inheritedStyle->mergeAttributes($this->stylesheet->cascade($path, self::attributesOfElement($element)));
        $matrices = SvgTransform::parse(isset($element['transform']) ? (string) $element['transform'] : null);

        if ($matrices !== []) {
            $this->stream->pushGraphicsState();

            foreach ($matrices as $step) {
                $this->stream->concatMatrix(...$step);

                // The written order is the order they are concatenated
                // in, and each applies before the ones already in
                // effect -- see SvgTransform.
                $matrix = SvgTransform::compose($step, $matrix);
            }
        }

        match ($tag) {
            'g', 'svg' => $this->renderChildren($element, $style, $matrix, $path),
            'path' => $this->renderPath($element, $style, $matrix),
            'rect' => $this->renderRect($element, $style, $matrix),
            'circle' => $this->renderCircle($element, $style, $matrix),
            'ellipse' => $this->renderEllipse($element, $style, $matrix),
            'line' => $this->renderLine($element, $style, $matrix),
            'polyline' => $this->renderPoly($element, $style, $matrix, closed: false),
            'polygon' => $this->renderPoly($element, $style, $matrix, closed: true),
            'image' => $this->renderImage($element, $style),
            'text' => $this->text?->render($element, $style, $path),
            default => null, // unrecognized element: skip, don't fail the whole document
        };

        if ($matrices !== []) {
            $this->stream->popGraphicsState();
        }
    }

    /**
     * Every child in turn, each told where it sits: a CSS combinator
     * asks what contains an element and what came before it, and this
     * walk is the only place both are known.
     *
     * The paths are built even where no stylesheet uses them, which is
     * cheap -- a tag, an id and a class list per element -- and keeps
     * the walk from having two shapes depending on the document.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     */
    private function renderChildren(\SimpleXMLElement $element, SvgStyle $style, array $matrix, ?SvgElementPath $path = null): void
    {
        $previous = null;

        foreach ($element->children() as $child) {
            $childPath = SvgElementPath::of($child->getName(), self::attributesOfElement($child), $path, $previous);
            $previous = $childPath;

            $this->renderElement($child, $style, $matrix, $childPath);
        }
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderPath(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        if (!isset($element['d'])) {
            return;
        }

        $d = (string) $element['d'];

        $painted = $this->paint->applyStyle($style, $matrix, static fn (): array => self::pathBounds($d));
        SvgPathParser::apply($d, $this->stream);
        $this->paint->finishPainting($painted, $style);
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderRect(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        $width = (float) ($element['width'] ?? 0);
        $height = (float) ($element['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return;
        }

        $x = (float) ($element['x'] ?? 0);
        $y = (float) ($element['y'] ?? 0);

        $painted = $this->paint->applyStyle($style, $matrix, static fn (): array => [$x, $y, $width, $height]);
        $this->stream->rect($x, $y, $width, $height);
        $this->paint->finishPainting($painted, $style);
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderCircle(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        $r = (float) ($element['r'] ?? 0);

        if ($r <= 0) {
            return;
        }

        $this->renderEllipseAt(
            (float) ($element['cx'] ?? 0),
            (float) ($element['cy'] ?? 0),
            $r,
            $r,
            $style,
            $matrix,
        );
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderEllipse(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        $rx = (float) ($element['rx'] ?? 0);
        $ry = (float) ($element['ry'] ?? 0);

        if ($rx <= 0 || $ry <= 0) {
            return;
        }

        $this->renderEllipseAt(
            (float) ($element['cx'] ?? 0),
            (float) ($element['cy'] ?? 0),
            $rx,
            $ry,
            $style,
            $matrix,
        );
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderEllipseAt(
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        SvgStyle $style,
        array $matrix,
    ): void {
        $painted = $this->paint->applyStyle($style, $matrix, static fn (): array => [$cx - $rx, $cy - $ry, $rx * 2, $ry * 2]);
        $this->ellipsePath($cx, $cy, $rx, $ry);
        $this->paint->finishPainting($painted, $style);
    }

    /**
     * Lines only ever stroke -- fill is not meaningful for a zero-area
     * path, per spec.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     */
    private function renderLine(\SimpleXMLElement $element, SvgStyle $style, array $matrix): void
    {
        $x1 = (float) ($element['x1'] ?? 0);
        $y1 = (float) ($element['y1'] ?? 0);
        $x2 = (float) ($element['x2'] ?? 0);
        $y2 = (float) ($element['y2'] ?? 0);

        // Through applyStyle() like every other shape, rather than
        // straight to applyStroke(): a line with stroke-opacity needs
        // the same graphics state, and skipping it left the line drawn
        // under whatever state the shape before it had set.
        $painted = $this->paint->applyStyle($style, $matrix, static fn (): array => [
            min($x1, $x2),
            min($y1, $y2),
            abs($x2 - $x1),
            abs($y2 - $y1),
        ]);

        $this->stream->moveTo($x1, $y1)->lineTo($x2, $y2);
        $this->paint->finishPainting($painted, $style, fillable: false);
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderPoly(\SimpleXMLElement $element, SvgStyle $style, array $matrix, bool $closed): void
    {
        $points = self::parsePoints(isset($element['points']) ? (string) $element['points'] : '');

        if (count($points) < 2) {
            return;
        }

        $painted = $this->paint->applyStyle($style, $matrix, static fn (): array => self::pointBounds($points));

        $this->stream->moveTo($points[0][0], $points[0][1]);

        for ($i = 1, $count = count($points); $i < $count; ++$i) {
            $this->stream->lineTo($points[$i][0], $points[$i][1]);
        }

        if ($closed) {
            $this->stream->closePath();
        }

        $this->paint->finishPainting($painted, $style, fillable: $closed);
    }

    /**
     * Draws a raster image embedded in the drawing.
     *
     * Only images carried inline as data: URIs are drawn -- see
     * SvgImageSource for why a file path or URL is not followed -- and
     * bytes that are not an image this library can decode are skipped
     * rather than raised, the same way an unsupported element is. A
     * broken decoration in a drawing should not fail the document it
     * decorates.
     */
    private function renderImage(\SimpleXMLElement $element, SvgStyle $style): void
    {
        $width = (float) ($element['width'] ?? 0);
        $height = (float) ($element['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return;
        }

        $bytes = SvgImageSource::bytes(self::href($element));

        if ($bytes === null) {
            return;
        }

        $image = $this->resources->svgImageResource($bytes);

        if ($image === null) {
            return;
        }

        $x = (float) ($element['x'] ?? 0);
        $y = (float) ($element['y'] ?? 0);

        $placement = SvgAspectRatio::place(
            isset($element['preserveAspectRatio']) ? (string) $element['preserveAspectRatio'] : null,
            $x,
            $y,
            $width,
            $height,
            $image->width,
            $image->height,
        );

        $this->stream->pushGraphicsState();

        if ($style->fillOpacity < 1.0) {
            $this->stream->setExtGState($this->resources->extGStateResourceName($style->fillOpacity, $style->strokeOpacity));
        }

        if ($placement['clip']) {
            // "slice" scales the image to cover its rectangle, which
            // means the parts that overflow have to be cut off rather
            // than drawn over whatever else is on the page.
            $this->stream->clipToRectangle($x, $y, $width, $height);
        }

        $this->stream->concatMatrix(...$placement['matrix'])
            ->paintXObject($image->resourceName)
            ->popGraphicsState();
    }

    /** @return array<string, string> */
    private static function attributesOfElement(\SimpleXMLElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes() as $name => $value) {
            $attributes[(string) $name] = (string) $value;
        }

        return $attributes;
    }

    /**
     * An element's image reference, written as SVG 2's "href" or the
     * older "xlink:href" -- both are current in the wild.
     */
    private static function href(\SimpleXMLElement $element): string
    {
        $href = (string) ($element->attributes()['href'] ?? '');

        if ($href !== '') {
            return $href;
        }

        return (string) ($element->attributes('http://www.w3.org/1999/xlink')['href'] ?? '');
    }

    private function ellipsePath(float $cx, float $cy, float $rx, float $ry): void
    {
        // Standard 4-Bezier-arc circle/ellipse approximation.
        $kx = 0.5522847498307936 * $rx;
        $ky = 0.5522847498307936 * $ry;

        $this->stream->moveTo($cx + $rx, $cy)
            ->curveTo($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry)
            ->curveTo($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy)
            ->curveTo($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry)
            ->curveTo($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy)
            ->closePath();
    }

    /**
     * The operators filling one tile of $pattern, drawing them if this
     * drawing has not already.
     *
     * A pattern is painted per *shape*, and what a tile looks like
     * depends on the pattern and on the matrix its contents are drawn
     * under and on nothing else -- so a drawing where five hundred
     * shapes share one pattern draws one tile, not five hundred. Only a
     * tile actually drawn is counted against the budget, which is what
     * keeps a repeated pattern from exhausting it.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}|null $contentMatrix
     * @return ?string null where the tile must not be drawn: a pattern
     *         painted with itself, or no budget left
     */
    public function tileFor(string $reference, SvgPattern $pattern, ?array $contentMatrix): ?string
    {
        // A pattern whose own content is painted with it would otherwise
        // draw a tile to draw a tile to draw a tile. The budget below
        // covers what this check does not: a chain of patterns is not
        // circular, and doubles the work at every link -- see
        // SvgTileCache.
        if (in_array($reference, $this->patternsBeingDrawn, true)) {
            return null;
        }

        // The matrix as it is written, so that "no matrix at all" -- which
        // emits no "cm" -- is a different tile from an identity one.
        $key = $reference . '|' . ($contentMatrix === null ? 'none' : implode(',', $contentMatrix));
        $drawn = $this->tiles->drawn($key);

        if ($drawn !== null) {
            return $drawn;
        }

        if (!$this->tiles->take(count($this->patternsBeingDrawn))) {
            return null;
        }

        $tile = new ContentStream();

        if ($contentMatrix !== null) {
            $tile->concatMatrix(...$contentMatrix);
        }

        $nested = new SvgRenderer(
            $tile,
            $this->gradients,
            $this->stylesheet,
            $this->resources,
            $this->textFontResourceName,
            $this->patterns,
            [...$this->patternsBeingDrawn, $reference],
            $this->paths,
            $this->tiles,
        );

        $nested->renderChildren($pattern->content, new SvgStyle(), $contentMatrix ?? SvgTransform::IDENTITY);

        $bytes = $tile->bytes();
        $this->tiles->remember($key, $bytes);

        return $bytes;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function pathBounds(string $d): array
    {
        $bounds = new PathBounds();
        SvgPathParser::apply($d, $bounds);

        return $bounds->box();
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private static function pointBounds(array $points): array
    {
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);

        return [min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)];
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
}
