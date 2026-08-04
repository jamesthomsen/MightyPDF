<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\ContentStream;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\Text\Utf8;

/**
 * Walks a parsed SVG element tree and emits the PDF operators that draw
 * it.
 *
 * Split out of SvgDocument, which now only parses: the walk carries four
 * pieces of state at once -- the inherited style, the transform in
 * effect, the stream, and the callbacks that turn a needed resource into
 * a name -- and threading those through a dozen static methods was
 * turning every signature into a parameter list.
 *
 * The transform is tracked here even though PDF tracks its own CTM
 * perfectly well, because a gradient is painted through a pattern, and a
 * pattern's coordinates are measured from the page rather than from the
 * CTM. See SvgShadingPattern.
 */
final class SvgRenderer
{
    private const array SKIPPED_TAGS = [
        'defs', 'title', 'desc', 'metadata', 'style', 'symbol',
        'clipPath', 'mask', 'linearGradient', 'radialGradient', 'pattern',
        'filter', 'use', 'animate', 'animateTransform', 'animateMotion',
    ];

    private readonly SvgTileCache $tiles;

    /**
     * @param array<string, SvgGradient> $gradients
     * @param array<string, SvgPattern> $patterns
     * @param \Closure(float, float): string $extGStateResourceName
     * @param (\Closure(SvgGradient, array, array): string)|null $shadingPatternResourceName
     *        null where the caller cannot provide pattern resources, in
     *        which case a gradient fill degrades to no paint -- the same
     *        fallback as a reference that cannot be resolved at all
     * @param (\Closure(SvgStyle): ?SvgTextFont)|null $textFontResourceName
     *        chooses and registers a font for a piece of text; null
     *        itself, or a null result, skips the text
     * @param (\Closure(string): ?SvgRasterImage)|null $imageResourceName
     *        turns the bytes of an embedded raster image into a page
     *        resource, or returns null for bytes it cannot decode; null
     *        itself where the caller cannot embed images at all, in
     *        which case <image> elements are skipped
     * @param (\Closure(SvgPattern, string, array, array): ?string)|null $tilingPatternResourceName
     *        turns a pattern's drawn content into a tiling pattern
     *        resource; called with the pattern, the operators filling one
     *        tile, the shape's transform and its bounding box
     * @param (\Closure(SvgGradient, array, float): string)|null $softMaskResourceName
     *        turns a gradient with transparent stops into the ExtGState
     *        resource carrying its soft mask; null itself leaves
     *        stop-opacity unhonoured, as before it was supported
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
        private readonly \Closure $extGStateResourceName,
        private readonly ?\Closure $shadingPatternResourceName,
        private readonly ?\Closure $imageResourceName = null,
        private readonly ?\Closure $textFontResourceName = null,
        private readonly array $patterns = [],
        private readonly ?\Closure $tilingPatternResourceName = null,
        private readonly ?\Closure $softMaskResourceName = null,
        private readonly array $patternsBeingDrawn = [],
        private readonly array $paths = [],
        ?SvgTileCache $tiles = null,
    ) {
        $this->tiles = $tiles ?? new SvgTileCache();
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
        $style = $inheritedStyle->mergeAttributes($this->cascade($path, self::attributesOfElement($element)));
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
            'text' => $this->renderText($element, $style, $path),
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

        $painted = $this->applyStyle($style, $matrix, static fn (): array => self::pathBounds($d));
        SvgPathParser::apply($d, $this->stream);
        $this->finishPainting($painted, $style);
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

        $painted = $this->applyStyle($style, $matrix, static fn (): array => [$x, $y, $width, $height]);
        $this->stream->rect($x, $y, $width, $height);
        $this->finishPainting($painted, $style);
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
        $painted = $this->applyStyle($style, $matrix, static fn (): array => [$cx - $rx, $cy - $ry, $rx * 2, $ry * 2]);
        $this->ellipsePath($cx, $cy, $rx, $ry);
        $this->finishPainting($painted, $style);
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
        $painted = $this->applyStyle($style, $matrix, static fn (): array => [
            min($x1, $x2),
            min($y1, $y2),
            abs($x2 - $x1),
            abs($y2 - $y1),
        ]);

        $this->stream->moveTo($x1, $y1)->lineTo($x2, $y2);
        $this->finishPainting($painted, $style, fillable: false);
    }

    /** @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix */
    private function renderPoly(\SimpleXMLElement $element, SvgStyle $style, array $matrix, bool $closed): void
    {
        $points = self::parsePoints(isset($element['points']) ? (string) $element['points'] : '');

        if (count($points) < 2) {
            return;
        }

        $painted = $this->applyStyle($style, $matrix, static fn (): array => self::pointBounds($points));

        $this->stream->moveTo($points[0][0], $points[0][1]);

        for ($i = 1, $count = count($points); $i < $count; ++$i) {
            $this->stream->lineTo($points[$i][0], $points[$i][1]);
        }

        if ($closed) {
            $this->stream->closePath();
        }

        $this->finishPainting($painted, $style, fillable: $closed);
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

        if ($this->imageResourceName === null || $width <= 0 || $height <= 0) {
            return;
        }

        $bytes = SvgImageSource::bytes(self::href($element));

        if ($bytes === null) {
            return;
        }

        $image = ($this->imageResourceName)($bytes);

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
            $this->stream->setExtGState(($this->extGStateResourceName)($style->fillOpacity, $style->strokeOpacity));
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

    /**
     * Draws a <text> element and the <tspan>s inside it.
     *
     * Two things make this more than "set a font and show a string".
     *
     * Text is *mixed content* -- characters and tspans interleaved, in
     * order -- which SimpleXML cannot walk (it presents children and
     * text separately), so this drops to DOM for the walk. And
     * text-anchor is not a property of a run but of a *chunk*: a run of
     * text uninterrupted by an absolute position. Centring means
     * measuring a whole chunk before drawing any of it, which is why
     * the runs are collected first and emitted second.
     */
    private function renderText(\SimpleXMLElement $element, SvgStyle $style, SvgElementPath $path): void
    {
        if ($this->textFontResourceName === null) {
            return;
        }

        $node = dom_import_simplexml($element);

        $runs = [];
        $this->collectTextRuns($node, $style, $runs, $path);
        $runs = self::trimEnds($runs);

        $pen = [
            'x' => (float) ($element['x'] ?? 0),
            'y' => (float) ($element['y'] ?? 0),
        ];

        foreach (self::chunk($runs) as $chunk) {
            $this->drawTextChunk($chunk, $pen);
        }

        // Text on a path is laid out separately, and collectTextRuns()
        // has already left it alone: a <textPath> holds text placed by a
        // road rather than by a pen, and mixing the two into one list of
        // runs would mean carrying a position that means two things.
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'textPath') {
                $this->renderTextPath($child, $style, $path);
            }
        }
    }

    /**
     * Draws a <textPath>: its text laid along the path it names, glyph
     * by glyph.
     *
     * Glyph by glyph is the whole of it. A run of text on a straight
     * line is one operator with one matrix; on a curve every glyph sits
     * at its own point, turned to face its own direction, so each needs
     * a matrix of its own and the text has to be measured a character at
     * a time to know where that point is.
     */
    private function renderTextPath(\DOMElement $element, SvgStyle $style, SvgElementPath $path): void
    {
        $walk = $this->pathWalk(self::hrefOf($element));

        if ($walk === null) {
            return;
        }

        $elementPath = SvgElementPath::of('textPath', self::attributesOf($element), $path);
        $style = $style->mergeAttributes($this->cascade($elementPath, self::attributesOf($element)));

        $runs = [];
        $this->collectTextRuns($element, $style, $runs, $elementPath);
        $runs = self::trimEnds($runs);

        $distance = self::startOffset($element, $walk->length())
            + self::anchorOffset($style->textAnchor, $this->textWidth($runs));

        foreach ($runs as $run) {
            $this->drawRunAlong($run, $walk, $distance);
        }
    }

    /**
     * @param array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float} $run
     * @param float $distance how far along the path this run starts --
     *        carried on to where it ends, for the run after it
     */
    private function drawRunAlong(array $run, SvgPathWalk $walk, float &$distance): void
    {
        $font = ($this->textFontResourceName)($run['style']);

        if ($font === null) {
            return;
        }

        $style = $run['style'];

        foreach (Utf8::characters($run['text']) as $character) {
            $width = $font->font->widthOfPt($character, $style->fontSizePt) + $style->letterSpacing;

            // A glyph is placed by its middle, not by its left edge: on a
            // curve the two disagree, and placing by the edge leans every
            // glyph away from the direction it is about to turn.
            $at = $walk->at($distance + $width / 2);
            $distance += $width;

            if ($at === null) {
                // Off the end of the path. SVG does not render these
                // rather than piling them up where the path stops.
                continue;
            }

            $this->drawGlyphAt($font, $character, $style, $at, $width);
        }
    }

    /**
     * @param array{0: float, 1: float, 2: float} $at x, y and the angle of travel
     */
    private function drawGlyphAt(SvgTextFont $font, string $character, SvgStyle $style, array $at, float $width): void
    {
        try {
            $encoded = $font->writer->encode($character);
        } catch (FontException) {
            return;
        }

        [$x, $y, $angle] = $at;
        $cos = cos($angle);
        $sin = sin($angle);

        $this->stream->pushGraphicsState();

        if ($style->fillOpacity < 1.0) {
            $this->stream->setExtGState(($this->extGStateResourceName)($style->fillOpacity, $style->strokeOpacity));
        }

        $this->stream->setFillColorRgb(...($style->fill ?? [0.0, 0.0, 0.0]))
            ->beginText()
            ->setFont($font->resourceName, $style->fontSizePt);

        // The glyph's own x-axis runs along the path and its y-axis
        // stands off to the left of it. On a horizontal path that is
        // [1 0 0 -1 x y] -- the same flip drawTextRun() uses against the
        // placement, which is the check that this is the right matrix
        // and not its mirror.
        $this->stream->showTextWithMatrix(
            [$cos, $sin, $sin, -$cos, $x - $cos * $width / 2, $y - $sin * $width / 2],
            $encoded,
            $font->writer->usesHexStrings(),
        )->endText()->popGraphicsState();
    }

    private function pathWalk(?string $reference): ?SvgPathWalk
    {
        $d = $reference === null ? null : ($this->paths[$reference] ?? null);

        if ($d === null) {
            return null;
        }

        $walk = new SvgPathWalk();
        SvgPathParser::apply($d, $walk);

        return $walk->isEmpty() ? null : $walk;
    }

    /** The id in an href/xlink:href, or null where there is none to follow. */
    private static function hrefOf(\DOMElement $element): ?string
    {
        $href = $element->getAttribute('href');

        if ($href === '') {
            $href = $element->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
        }

        return str_starts_with($href, '#') ? substr($href, 1) : null;
    }

    /** startOffset, as a length or as a percentage of the path. */
    private static function startOffset(\DOMElement $element, float $length): float
    {
        $offset = trim($element->getAttribute('startOffset'));

        if ($offset === '') {
            return 0.0;
        }

        return str_ends_with($offset, '%')
            ? (float) substr($offset, 0, -1) / 100.0 * $length
            : (float) $offset;
    }

    private static function anchorOffset(string $anchor, float $width): float
    {
        return match ($anchor) {
            'middle' => -$width / 2,
            'end' => -$width,
            default => 0.0,
        };
    }

    /**
     * @param list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}> $runs
     */
    private function textWidth(array $runs): float
    {
        $width = 0.0;

        foreach ($runs as $run) {
            $font = ($this->textFontResourceName)($run['style']);

            if ($font !== null) {
                $width += self::runWidth($font, $run);
            }
        }

        return $width;
    }

    /**
     * Walks the mixed content of a text element into a flat list of
     * runs, each with the style and any positioning that applies to it.
     *
     * @param list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}> $runs
     */
    private function collectTextRuns(\DOMElement $element, SvgStyle $style, array &$runs, SvgElementPath $path): void
    {
        $previous = null;

        foreach ($element->childNodes as $node) {
            if ($node instanceof \DOMText) {
                $text = self::collapseWhitespace($node->textContent);

                if ($text !== '') {
                    $runs[] = ['text' => $text, 'style' => $style, 'x' => null, 'y' => null, 'dx' => 0.0, 'dy' => 0.0];
                }

                continue;
            }

            if (!$node instanceof \DOMElement || $node->localName !== 'tspan') {
                continue;
            }

            $childPath = SvgElementPath::of($node->localName, self::attributesOf($node), $path, $previous);
            $previous = $childPath;

            $childStyle = $style->mergeAttributes($this->cascade($childPath, self::attributesOf($node)));
            $before = count($runs);

            $this->collectTextRuns($node, $childStyle, $runs, $childPath);

            // The tspan's own positioning belongs to the first run it
            // produced -- a tspan that contains only another tspan
            // produces none of its own, and the position still has to
            // land on whatever text comes first inside it.
            if (isset($runs[$before])) {
                $runs[$before]['x'] = $node->hasAttribute('x') ? (float) $node->getAttribute('x') : $runs[$before]['x'];
                $runs[$before]['y'] = $node->hasAttribute('y') ? (float) $node->getAttribute('y') : $runs[$before]['y'];
                $runs[$before]['dx'] += (float) $node->getAttribute('dx');
                $runs[$before]['dy'] += (float) $node->getAttribute('dy');
            }
        }
    }

    /**
     * Splits runs into chunks: a chunk begins wherever a run gives an
     * absolute position, and text-anchor measures and aligns one chunk
     * at a time.
     *
     * @param list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}> $runs
     * @return list<list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}>>
     */
    private static function chunk(array $runs): array
    {
        $chunks = [];
        $current = [];

        foreach ($runs as $run) {
            if ($current !== [] && ($run['x'] !== null || $run['y'] !== null)) {
                $chunks[] = $current;
                $current = [];
            }

            $current[] = $run;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}> $chunk
     * @param array{x: float, y: float} $pen
     */
    private function drawTextChunk(array $chunk, array &$pen): void
    {
        $fonts = [];
        $width = 0.0;

        foreach ($chunk as $index => $run) {
            $font = ($this->textFontResourceName)($run['style']);
            $fonts[$index] = $font;

            if ($font !== null) {
                $width += self::runWidth($font, $run);
            }
        }

        $first = $chunk[0];
        $pen['x'] = ($first['x'] ?? $pen['x']) + $first['dx'];
        $pen['y'] = ($first['y'] ?? $pen['y']) + $first['dy'];

        // text-anchor shifts the whole chunk against the point it was
        // given: "middle" centres it there, "end" finishes there.
        $pen['x'] -= match ($first['style']->textAnchor) {
            'middle' => $width / 2,
            'end' => $width,
            default => 0.0,
        };

        foreach ($chunk as $index => $run) {
            $font = $fonts[$index];

            if ($font === null) {
                continue;
            }

            if ($index > 0) {
                $pen['x'] = ($run['x'] ?? $pen['x']) + $run['dx'];
                $pen['y'] = ($run['y'] ?? $pen['y']) + $run['dy'];
            }

            $this->drawTextRun($font, $run, $pen);
        }
    }

    /**
     * @param array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float} $run
     * @param array{x: float, y: float} $pen
     */
    private function drawTextRun(SvgTextFont $font, array $run, array &$pen): void
    {
        $style = $run['style'];

        try {
            $encoded = $font->writer->encode($run['text']);
        } catch (FontException) {
            // The font has no glyph for something in this run. Skipping
            // it matches how everything else here handles what it
            // cannot draw, and beats drawing empty boxes.
            return;
        }

        $this->stream->pushGraphicsState();

        if ($style->fillOpacity < 1.0) {
            $this->stream->setExtGState(($this->extGStateResourceName)($style->fillOpacity, $style->strokeOpacity));
        }

        $this->stream->setFillColorRgb(...($style->fill ?? [0.0, 0.0, 0.0]))
            ->beginText()
            ->setFont($font->resourceName, $style->fontSizePt);

        if ($style->letterSpacing !== 0.0) {
            $this->stream->setCharacterSpacing($style->letterSpacing);
        }

        // The vertical flip counteracts the one the whole drawing is
        // placed under -- see ContentStream::showTextWithMatrix().
        $this->stream->showTextWithMatrix(
            [1.0, 0.0, 0.0, -1.0, $pen['x'], $pen['y']],
            $encoded,
            $font->writer->usesHexStrings(),
        )->endText()->popGraphicsState();

        $pen['x'] += self::runWidth($font, $run);
    }

    /**
     * @param array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float} $run
     */
    private static function runWidth(SvgTextFont $font, array $run): float
    {
        $style = $run['style'];

        return $font->font->widthOfPt($run['text'], $style->fontSizePt)
            + $style->letterSpacing * count(Utf8::codePoints($run['text']));
    }

    /**
     * An element's own attributes with any matching CSS rules laid over
     * them.
     *
     * That order is the cascade's, not a convenience: a presentation
     * attribute is the weakest kind of styling there is, and a rule in
     * a <style> block beats it. The inline style attribute beats both,
     * which SvgStyle::mergeAttributes() applies last of all.
     *
     * @param array<string, string> $attributes
     * @return array<string, string>
     */
    private function cascade(SvgElementPath $path, array $attributes): array
    {
        if ($this->stylesheet->isEmpty()) {
            return $attributes;
        }

        return array_merge($attributes, $this->stylesheet->declarationsFor($path));
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

    /** @return array<string, string> */
    private static function attributesOf(\DOMElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }

        return $attributes;
    }

    /**
     * SVG collapses runs of whitespace -- including the newlines and
     * indentation that pretty-printed markup is full of -- into single
     * spaces.
     *
     * Deliberately not trimmed: the space in "Runs: <tspan>one</tspan>
     * and <tspan>two</tspan>" belongs to the text, and trimming each
     * piece as it is read runs the words together. Only the very start
     * and end of a text element are trimmed, once all of it has been
     * collected -- see trimEnds().
     */
    private static function collapseWhitespace(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * Drops the whitespace at the two ends of a text element, which is
     * indentation rather than content, and with it any run that was
     * nothing else.
     *
     * @param list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}> $runs
     * @return list<array{text: string, style: SvgStyle, x: float|null, y: float|null, dx: float, dy: float}>
     */
    private static function trimEnds(array $runs): array
    {
        if ($runs === []) {
            return [];
        }

        $last = count($runs) - 1;
        $runs[0]['text'] = ltrim($runs[0]['text']);
        $runs[$last]['text'] = rtrim($runs[$last]['text']);

        return array_values(array_filter($runs, static fn (array $run): bool => $run['text'] !== ''));
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
     * Sets up colour, opacity and line width for the shape about to be
     * drawn, and reports what will actually be painted.
     *
     * The report matters because a paint is not always the one the
     * style asked for: a gradient reference that leads nowhere paints
     * nothing, and a shape that paints nothing has to end its path with
     * "n" rather than with a fill of the last colour that happened to
     * be set.
     *
     * $bounds is a closure rather than a value because working out a
     * path's box means parsing it a second time, and only a gradient in
     * objectBoundingBox units ever asks.
     *
     * A shape needing partial opacity is wrapped in its own graphics
     * state, and finishPainting() closes it. Otherwise the state outlives
     * the shape that asked for it: PDF has no "back to opaque" operator,
     * so one half-transparent shape would leave every shape drawn after
     * it half-transparent too -- and only where the drawing happened to
     * be written in that order.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @return array{fill: bool, stroke: bool, state: bool}
     */
    private function applyStyle(SvgStyle $style, array $matrix, \Closure $bounds): array
    {
        $fading = $this->fadingGradient($style);
        $translucent = $style->fillOpacity < 1.0 || $style->strokeOpacity < 1.0;
        $state = $translucent || $fading !== null;

        if ($state) {
            $this->stream->pushGraphicsState();
        }

        if ($translucent) {
            $this->stream->setExtGState(($this->extGStateResourceName)($style->fillOpacity, $style->strokeOpacity));
        }

        if ($fading !== null) {
            $this->stream->setExtGState(($this->softMaskResourceName)($fading, $bounds(), $style->strokeWidth));
        }

        return [
            'fill' => $this->applyFill($style, $matrix, $bounds),
            'stroke' => $this->applyStroke($style, $matrix, $bounds),
            'state' => $state,
        ];
    }

    /**
     * The gradient this shape is painted with that fades, if there is
     * one -- a gradient with a transparent stop needs a soft mask, and
     * the mask belongs to the whole shape rather than to one of its
     * paints.
     *
     * A shape whose fill and stroke *both* fade is painted under the
     * fill's mask: the graphics state has room for one soft mask, and
     * drawing the two paints separately so each could have its own is a
     * different rendering model than the one here. Rare enough to be
     * worth naming rather than working around.
     */
    private function fadingGradient(SvgStyle $style): ?SvgGradient
    {
        if ($this->softMaskResourceName === null) {
            return null;
        }

        foreach ([$style->fillReference, $style->strokeReference] as $reference) {
            $gradient = $reference === null ? null : ($this->gradients[$reference] ?? null);

            if ($gradient !== null && $gradient->hasTransparency() && $gradient->isPaintable()) {
                return $gradient;
            }
        }

        return null;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     */
    private function applyFill(SvgStyle $style, array $matrix, \Closure $bounds): bool
    {
        if ($style->fillReference !== null) {
            return $this->applyPaintServer(
                $style->fillReference,
                $matrix,
                $bounds,
                $this->stream->setFillColorRgb(...),
                $this->stream->setFillPattern(...),
            );
        }

        if ($style->fill === null) {
            return false;
        }

        $this->stream->setFillColorRgb(...$style->fill);

        return true;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     */
    private function applyStroke(SvgStyle $style, array $matrix, \Closure $bounds): bool
    {
        if ($style->strokeReference !== null) {
            $painted = $this->applyPaintServer(
                $style->strokeReference,
                $matrix,
                $bounds,
                $this->stream->setStrokeColorRgb(...),
                $this->stream->setStrokePattern(...),
            );

            if ($painted) {
                $this->stream->setLineWidth($style->strokeWidth);
            }

            return $painted;
        }

        if ($style->stroke === null) {
            return false;
        }

        $this->stream->setStrokeColorRgb(...$style->stroke)->setLineWidth($style->strokeWidth);

        return true;
    }

    /**
     * Paints with a url(#id) reference, whichever kind of paint server
     * it names, reporting whether anything was painted at all.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @param \Closure(float, float, float): mixed $setColor
     * @param \Closure(string): mixed $setPattern
     */
    private function applyPaintServer(
        string $reference,
        array $matrix,
        \Closure $bounds,
        \Closure $setColor,
        \Closure $setPattern,
    ): bool {
        if (isset($this->patterns[$reference])) {
            return $this->applyPattern($reference, $matrix, $bounds, $setPattern);
        }

        return $this->applyGradient($reference, $matrix, $bounds, $setColor, $setPattern);
    }

    /**
     * Paints with a <pattern>, drawing one tile's worth of its content
     * and handing that to the caller to make a resource of.
     *
     * The content is rendered here rather than by the caller because
     * everything it takes to render is here: the pattern's children are
     * ordinary elements, drawn with the same gradients, stylesheet and
     * resource callbacks as the rest of the drawing.
     *
     * The base matrix for that nested drawing is the identity, not the
     * shape's: a pattern's contents are measured in the pattern's own
     * space, and PDF composes that with the pattern matrix itself. A
     * gradient inside a pattern would otherwise be positioned as though
     * the pattern matrix applied to it twice.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @param \Closure(string): mixed $setPattern
     */
    private function applyPattern(
        string $reference,
        array $matrix,
        \Closure $bounds,
        \Closure $setPattern,
    ): bool {
        $pattern = $this->patterns[$reference];

        // A pattern whose own content is painted with it would otherwise
        // draw a tile to draw a tile to draw a tile. The budget covers
        // what that check does not: a chain of patterns is not circular,
        // and doubles the work at every link -- see SvgTileCache.
        if ($this->tilingPatternResourceName === null || in_array($reference, $this->patternsBeingDrawn, true)) {
            return false;
        }

        $box = $bounds();

        if (!$pattern->canPaint($box)) {
            return false;
        }

        $contentMatrix = $pattern->contentMatrix($box);
        $tile = $this->tileFor($reference, $pattern, $contentMatrix);

        if ($tile === null) {
            return false;
        }

        $name = ($this->tilingPatternResourceName)($pattern, $tile, $matrix, $box);

        if ($name === null) {
            return false;
        }

        $setPattern($name);

        return true;
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
     * @return ?string null where there was no budget left to draw it
     */
    private function tileFor(string $reference, SvgPattern $pattern, ?array $contentMatrix): ?string
    {
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
            $this->extGStateResourceName,
            $this->shadingPatternResourceName,
            $this->imageResourceName,
            $this->textFontResourceName,
            $this->patterns,
            $this->tilingPatternResourceName,
            $this->softMaskResourceName,
            [...$this->patternsBeingDrawn, $reference],
            $this->paths,
            $this->tiles,
        );

        $nested->renderChildren($pattern->content, new SvgStyle(), $contentMatrix ?? SvgTransform::IDENTITY);

        $bytes = $tile->bytes();
        $this->tiles->remember($key, $bytes);

        return $bytes;
    }

    /**
     * Paints with a url(#id) gradient reference, reporting whether
     * anything was painted at all.
     *
     * Every false here is a deliberate degradation rather than an
     * error: an unresolvable paint server, a gradient with no stops, a
     * shape with no area to measure a gradient against, or a caller
     * that cannot supply pattern resources. A broken decorative fill
     * should not take the whole document down with it -- the same call
     * SvgColor already makes for url() references it cannot follow.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param \Closure(): array{0: float, 1: float, 2: float, 3: float} $bounds
     * @param \Closure(float, float, float): mixed $setColor
     * @param \Closure(string): mixed $setPattern
     */
    private function applyGradient(
        string $reference,
        array $matrix,
        \Closure $bounds,
        \Closure $setColor,
        \Closure $setPattern,
    ): bool {
        $gradient = $this->gradients[$reference] ?? null;

        if ($gradient === null || $this->shadingPatternResourceName === null) {
            return false;
        }

        // A gradient whose stops are all one colour is a flat fill
        // written the long way -- and a PDF shading that interpolates a
        // colour to itself is a shading readers are entitled to reject.
        $solid = $gradient->solidColor();

        if ($solid !== null) {
            $setColor(...$solid);

            return true;
        }

        $box = $bounds();

        if (!SvgShadingPattern::canPaint($gradient, $box)) {
            return false;
        }

        $setPattern(($this->shadingPatternResourceName)($gradient, $matrix, $box));

        return true;
    }

    /** @param array{fill: bool, stroke: bool, state: bool} $painted */
    private function finishPainting(array $painted, SvgStyle $style, bool $fillable = true): void
    {
        $hasFill = $fillable && $painted['fill'];

        if ($hasFill && $painted['stroke']) {
            $this->stream->fillAndStroke($style->evenOdd);
        } elseif ($hasFill) {
            $this->stream->fill($style->evenOdd);
        } elseif ($painted['stroke']) {
            $this->stream->stroke();
        } else {
            $this->stream->endPathNoOp();
        }

        if ($painted['state']) {
            $this->stream->popGraphicsState();
        }
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
