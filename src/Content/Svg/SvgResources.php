<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * What a drawing asks its caller for: the PDF resources an SVG needs
 * before its operators mean anything.
 *
 * Every one of these turns something the SVG describes -- a gradient, a
 * pattern, an opacity, a transparent stop, an embedded image -- into a
 * name the content stream can emit, having allocated and registered
 * whatever PDF objects that took. The renderer knows what a drawing
 * needs; it deliberately knows nothing about where those objects live or
 * what they end up called, because the answer differs between a page's
 * /Resources and the form XObject a placed SVG becomes.
 *
 * MightyPDF\Content\ResourceRegistry is the implementation that does all
 * of that for real. This was five separate closures threaded through
 * SvgDocument::render() into SvgRenderer and on into every nested
 * renderer, which is what a collaborator looks like before it has a name.
 *
 * Returning null means "not painted", not "failed": a caller that cannot
 * supply a given resource degrades that one element rather than failing
 * the document, matching what the renderer already does with a reference
 * it cannot resolve. A broken decorative fill should not take a document
 * down with it.
 *
 * A null is found out by asking, so the work that leads up to the ask
 * has already been done by the time it comes back: a tile is drawn --
 * and spends a slot of the drawing's tile budget -- before
 * tilingPatternResourceName() gets the chance to decline it, and a
 * shape's bounding box is measured before shadingPatternResourceName()
 * does. An implementation that declines a whole *category* of resource
 * for every drawing it is given therefore pays for what it refuses.
 * That is the price of asking rather than advertising, and it is the
 * right way round: the implementation this library ships supplies
 * everything, and a capability check on the hot path would cost every
 * drawing something to spare a caller that does not exist.
 */
interface SvgResources
{
    /**
     * The ExtGState setting fill and stroke alpha, for an element drawn
     * with opacity below 1.
     */
    public function extGStateResourceName(float $fillAlpha, float $strokeAlpha): string;

    /**
     * The pattern a gradient is painted through, positioned for the
     * shape that asked.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     * @return ?string null where gradients cannot be resourced here, in
     *         which case the fill degrades to no paint
     */
    public function shadingPatternResourceName(SvgGradient $gradient, array $matrix, array $boundingBox): ?string;

    /**
     * The tiling pattern for a <pattern> whose content the renderer has
     * already drawn -- $content is the operators filling one tile.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     * @return ?string null where patterns cannot be resourced here
     */
    public function tilingPatternResourceName(SvgPattern $pattern, string $content, array $matrix, array $boundingBox): ?string;

    /**
     * The ExtGState carrying the soft mask a gradient with transparent
     * stops is painted under.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     * @return ?string null where soft masks cannot be resourced here,
     *         which leaves stop-opacity unhonoured rather than skipping
     *         the shape
     */
    public function softMaskResourceName(SvgGradient $gradient, array $boundingBox, float $strokeWidth): ?string;

    /**
     * The XObject for a raster image carried inside the drawing, or null
     * for bytes that are not an image this library decodes -- in which
     * case the <image> element is skipped.
     */
    public function svgImageResource(string $bytes): ?SvgRasterImage;
}
