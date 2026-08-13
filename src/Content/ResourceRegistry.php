<?php

declare(strict_types=1);

namespace MightyPDF\Content;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\DocumentContext;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Content\Font\Font;
use MightyPDF\Content\Font\FontWriter;
use MightyPDF\Content\Image\GifImage;
use MightyPDF\Content\Image\JpegImage;
use MightyPDF\Content\Image\PngImage;
use MightyPDF\Content\Svg\SvgGradient;
use MightyPDF\Content\Svg\SvgPattern;
use MightyPDF\Content\Svg\SvgRasterImage;
use MightyPDF\Content\Svg\SvgResources;
use MightyPDF\Content\Svg\SvgShadingPattern;
use MightyPDF\Content\Svg\SvgSoftMask;
use MightyPDF\Content\Svg\SvgTilingPattern;

/**
 * One /Resources dictionary and everything that goes into it: the objects
 * a drawing needs allocated and registered with the document, and the
 * names they answer to here.
 *
 * A resource name means nothing on its own -- /F1 is whatever the
 * dictionary in scope says it is -- so the name, the counter that produced
 * it and the dictionary it resolves in are one thing, and this is that
 * thing. PageBuilder held all three as loose properties for as long as
 * there was only ever one of them: the page's.
 *
 * There is now a second. An SVG placed on a page is rendered into a form
 * XObject of its own so that placing it twice costs one drawing (see
 * PageBuilder::drawSvg()), and an XObject carries its own /Resources --
 * it has to, since the whole point is that it can be invoked from a page
 * that has never seen the fonts and gradients inside it. Rendering into
 * it is then just "name things in that registry instead of this one",
 * which is a swap of one object rather than a second copy of every naming
 * method.
 *
 * The objects being named stay document-scoped throughout. Only the names
 * are scoped here -- the same font may well be /F1 in one scope and /F3 in
 * another while both point at the one font object the document registered.
 *
 * Allocating, registering and naming live together on purpose. Each of
 * these methods owns every side effect of "paint something with this":
 * build the PDF object, hand it to the document, and put it in the
 * dictionary under a name the caller can emit. Splitting those steps
 * across call sites is what let form fields on different pages collide in
 * the shared AcroForm /DR, and scattering them is exactly what produced
 * the 2012 bugs this project is rebuilding away from.
 *
 * The one naming job that is deliberately *not* here is a form field's
 * font: the AcroForm's /DR is a single dictionary shared by every page
 * rather than a scope, so AcroForm owns both its naming and its dedupe.
 * See PageBuilder::formFontResourceName().
 */
final class ResourceRegistry implements SvgResources
{
    /** @var array<string, string> Font::cacheKey() => resource name (e.g. "F1"), for /Resources /Font */
    private array $fontResourceNames = [];

    private int $nextFontResourceNumber = 1;
    private int $nextImageResourceNumber = 1;
    private int $nextPatternResourceNumber = 1;

    /**
     * @var array<string, string> Paint::paintKey() => resource name (e.g.
     *      "CS1"), for the /Separation spaces a spot colour needs
     *      declared before its name means anything
     */
    private array $colorSpaceResourceNames = [];

    private int $nextColorSpaceResourceNumber = 1;

    /** @var array<string, string> "fillAlpha:strokeAlpha" => resource name (e.g. "GS1") */
    private array $extGStateResourceNames = [];

    private int $nextExtGStateResourceNumber = 1;

    /**
     * A tiling pattern is shared by every shape it paints the same way --
     * see tilingPatternResourceName() for what "the same way" is.
     *
     * @var array<string, string> tile, matrix and content => resource name
     */
    private array $tilingPatternResourceNames = [];

    public function __construct(
        private readonly DocumentContext $document,
        public readonly Dictionary $resources,
    ) {
    }

    /**
     * Names a font in this scope's /Resources /Font.
     *
     * The side effects of "start using this font here" are split in two:
     * Font::writerFor() owns allocating/registering/caching the one shared
     * font object per document, and this owns naming it here. They are
     * separate because the object is document-scoped while the name is
     * scope-scoped -- conflating the two is what let form fields on
     * different pages collide in the shared AcroForm /DR.
     */
    public function fontResourceName(Font $font, FontWriter $writer): string
    {
        $key = $font->cacheKey();
        if (isset($this->fontResourceNames[$key])) {
            return $this->fontResourceNames[$key];
        }

        $resourceName = 'F' . $this->nextFontResourceNumber++;
        $this->fontResourceNames[$key] = $resourceName;
        $this->category('Font')->set($resourceName, new PdfReference($writer->dictionary()->objectId()));

        return $resourceName;
    }

    /** A form or image XObject: /Im1, /Im2, ... in this scope. */
    public function nameXObject(int $objectId): string
    {
        $resourceName = 'Im' . $this->nextImageResourceNumber++;
        $this->category('XObject')->set($resourceName, new PdfReference($objectId));

        return $resourceName;
    }

    /**
     * Decodes an image an SVG carries inline or references, registers it
     * once per distinct content, and names it here.
     *
     * The format is sniffed from the bytes rather than taken from the
     * media type: the type is written by whatever produced the SVG and
     * is wrong often enough that trusting it would mean handing PNG
     * bytes to the JPEG decoder on someone else's typo. Bytes that are
     * not an image this library decodes -- or that are a broken one --
     * return null, and the element is skipped rather than failing the
     * whole document, matching how SVG handles everything it cannot
     * draw.
     */
    public function svgImageResource(string $bytes): ?SvgRasterImage
    {
        $format = match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'gif',
            default => null,
        };

        if ($format === null) {
            return null;
        }

        $contentHash = "svg-$format:" . hash('xxh128', $bytes);
        $image = $this->document->cachedImage($contentHash);

        if ($image === null) {
            try {
                $image = match ($format) {
                    'png' => PngImage::fromBytes($this->document, $bytes),
                    'jpeg' => JpegImage::fromBytes($this->document, $bytes),
                    'gif' => GifImage::fromBytes($this->document, $bytes),
                };
            } catch (\RuntimeException | \InvalidArgumentException) {
                return null;
            }

            $this->document->register($image);
            $this->document->cacheImage($contentHash, $image);
        }

        return new SvgRasterImage(
            $this->nameXObject($image->objectId()),
            (int) ($image->get('Width')?->format() ?? 0),
            (int) ($image->get('Height')?->format() ?? 0),
        );
    }

    /**
     * Owns every side effect of "paint something with this gradient":
     * build the pattern, register it, and name it in /Resources /Pattern.
     *
     * Not cached the way fonts and images are. A shading pattern carries
     * the matrix of the shape it paints, so two shapes with the same
     * gradient need two patterns -- which is also why the resource name
     * is simply the next free one rather than something derived from
     * the gradient's id.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     */
    public function shadingPatternResourceName(SvgGradient $gradient, array $matrix, array $boundingBox): string
    {
        $pattern = SvgShadingPattern::build($this->document->allocate(), $gradient, $matrix, $boundingBox);
        $this->document->register($pattern);

        return $this->namePattern($pattern->objectId());
    }

    /**
     * The same for a <pattern>, whose content the SVG layer has already
     * drawn -- this owns only the PDF object it becomes.
     *
     * The pattern's /Resources is a copy of this scope's own, taken now.
     * The tile's content was drawn through the same registry as the
     * page's, so every font, image and gradient it names is already
     * registered there under the name it used, and copying is what makes
     * those names resolve inside a stream that is not the page's. It
     * lists more than the tile uses, which costs a few entries and no
     * objects; the alternative is a second set of resource bookkeeping
     * for every nested scope. The copy is taken *before* this pattern is
     * named on the page, so a pattern can never list itself.
     *
     * Named once per distinct pattern rather than once per shape. What a
     * tiling pattern object says is its tile rectangle, its placement
     * matrix and its content -- so where two shapes agree on all three,
     * the second wants the object the first already has, and building it
     * again costs an object and a resource snapshot per shape. A drawing
     * of a thousand pattern-filled shapes was a seven-megabyte document
     * from fifty-seven kilobytes of SVG.
     *
     * Reusing the first object also reuses the snapshot taken with it,
     * which is safe for the same reason the sharing is: identical
     * content names identical resources, and those were registered
     * before the first snapshot was taken because the first tile named
     * them.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     */
    public function tilingPatternResourceName(SvgPattern $pattern, string $content, array $matrix, array $boundingBox): string
    {
        $key = implode('|', [...$pattern->tile($boundingBox), ...$matrix, $content]);

        if (isset($this->tilingPatternResourceNames[$key])) {
            return $this->tilingPatternResourceNames[$key];
        }

        $resources = $this->snapshotResources();

        $tiling = SvgTilingPattern::build(
            $this->document->allocate(),
            $pattern,
            $content,
            $resources,
            $matrix,
            $boundingBox,
        );
        $this->document->register($tiling);

        return $this->tilingPatternResourceNames[$key] = $this->namePattern($tiling->objectId());
    }

    /**
     * The ExtGState carrying a fading gradient's soft mask, named in
     * /Resources /ExtGState.
     *
     * Not cached the way a plain alpha state is: a mask is drawn in the
     * coordinates of the shape it masks, so two shapes with the same
     * gradient need two of them -- the same reason a shading pattern is
     * built per shape.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $boundingBox
     */
    public function softMaskResourceName(SvgGradient $gradient, array $boundingBox, float $strokeWidth): string
    {
        [$group, $state] = SvgSoftMask::build(
            $this->document->allocate(),
            $this->document->allocate(),
            $gradient,
            $boundingBox,
            $strokeWidth,
        );

        $this->document->register($group);
        $this->document->register($state);

        return $this->nameExtGState(new PdfReference($state->objectId()));
    }

    /**
     * The /Separation colour space a spot colour is painted through,
     * declared in the /ColorSpace of whatever scope is being drawn into
     * and named there.
     *
     * Written inline rather than as an indirect object: the whole thing
     * is an array of four short values, and the tint transform is a Type
     * 2 (exponential interpolation) function, which is an ordinary
     * dictionary and not a stream. Nothing here needs a number of its
     * own, and a page that uses one ink would otherwise cost two extra
     * objects to say so.
     *
     * The function is the linear ramp SpotColor documents: at tint 0 the
     * alternate is all zeros, i.e. bare paper, and at tint 1 it is the
     * colour in full. /N 1 makes the interpolation between them linear.
     *
     * Keyed on Paint::paintKey(), which deliberately excludes the tint --
     * every tint of one ink is the same plate and shares this one space.
     */
    public function separationColorSpaceName(SpotColor $spot): string
    {
        $key = $spot->paintKey();

        if (isset($this->colorSpaceResourceNames[$key])) {
            return $this->colorSpaceResourceNames[$key];
        }

        $tintTransform = new Dictionary();
        $tintTransform->set('FunctionType', new PdfInteger(2));
        $tintTransform->set('Domain', new PdfArray(new PdfReal(0.0), new PdfReal(1.0)));
        $tintTransform->set('C0', new PdfArray(...array_map(
            static fn (float $channel): PdfReal => new PdfReal(0.0),
            $spot->alternate->components(),
        )));
        $tintTransform->set('C1', new PdfArray(...array_map(
            static fn (float $channel): PdfReal => new PdfReal($channel),
            $spot->alternate->components(),
        )));
        $tintTransform->set('N', new PdfInteger(1));

        $colorSpace = new PdfArray(
            new PdfName('Separation'),
            new PdfName($spot->name),
            new PdfName('DeviceCMYK'),
            $tintTransform,
        );

        $resourceName = 'CS' . $this->nextColorSpaceResourceNumber++;
        $this->category('ColorSpace')->set($resourceName, $colorSpace);

        return $this->colorSpaceResourceNames[$key] = $resourceName;
    }

    public function extGStateResourceName(float $fillAlpha, float $strokeAlpha): string
    {
        $key = "$fillAlpha:$strokeAlpha";
        if (isset($this->extGStateResourceNames[$key])) {
            return $this->extGStateResourceNames[$key];
        }

        $gsDict = new Dictionary($this->document->allocate());
        $gsDict->set('Type', new PdfName('ExtGState'));
        $gsDict->set('ca', new PdfReal($fillAlpha));
        $gsDict->set('CA', new PdfReal($strokeAlpha));
        $this->document->register($gsDict);

        return $this->extGStateResourceNames[$key] = $this->nameExtGState(new PdfReference($gsDict->objectId()));
    }

    /**
     * The dictionary of one resource category, created on first use.
     *
     * Created lazily because an empty category is worse than an absent
     * one: a page that draws nothing but text has no business declaring
     * an empty /Pattern.
     */
    private function category(string $name): Dictionary
    {
        $category = $this->resources->get($name);

        if (!$category instanceof Dictionary) {
            $category = new Dictionary();
            $this->resources->set($name, $category);
        }

        return $category;
    }

    private function namePattern(int $objectId): string
    {
        $resourceName = 'P' . $this->nextPatternResourceNumber++;
        $this->category('Pattern')->set($resourceName, new PdfReference($objectId));

        return $resourceName;
    }

    private function nameExtGState(PdfReference $state): string
    {
        $resourceName = 'GS' . $this->nextExtGStateResourceNumber++;
        $this->category('ExtGState')->set($resourceName, $state);

        return $resourceName;
    }

    /**
     * The resources of whatever is being drawn into as they stand, two
     * levels deep.
     *
     * Deep enough matters: /Resources holds a dictionary per category,
     * and copying only the outer one would leave the copy sharing the
     * page's /Pattern dictionary -- which this pattern is about to be
     * added to. The pattern would then contain itself, and a reader
     * following it reports a circular reference (Ghostscript does;
     * poppler renders it and says nothing).
     */
    private function snapshotResources(): Dictionary
    {
        $snapshot = new Dictionary();

        foreach ($this->resources->entries() as $key => $value) {
            if ($value instanceof Dictionary) {
                $category = new Dictionary();

                foreach ($value->entries() as $name => $resource) {
                    $category->set((string) $name, $resource);
                }

                $value = $category;
            }

            $snapshot->set((string) $key, $value);
        }

        return $snapshot;
    }
}
