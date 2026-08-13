<?php

declare(strict_types=1);

namespace MightyPDF\Content;

use MightyPDF\Assembler\Annotation\AttachmentIcon;
use MightyPDF\Assembler\Annotation\FileAttachmentAnnotation;
use MightyPDF\Assembler\Annotation\LinkAnnotation;
use MightyPDF\Assembler\Attachment\FileSpecification;
use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\DocumentContext;
use MightyPDF\Assembler\Form\CheckboxField;
use MightyPDF\Assembler\Form\ChoiceField;
use MightyPDF\Assembler\Form\RadioButtonWidget;
use MightyPDF\Assembler\Form\RadioGroupField;
use MightyPDF\Assembler\Form\SignatureField;
use MightyPDF\Assembler\Form\TextField;
use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\PageContext;
use MightyPDF\Assembler\Structure\StructureElement;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Content\Barcode\DataMatrix;
use MightyPDF\Content\Barcode\DataMatrixShape;
use MightyPDF\Content\Barcode\ModuleGrid;
use MightyPDF\Content\Barcode\QrCode;
use MightyPDF\Content\Barcode\QrEccLevel;
use MightyPDF\Content\Barcode\Symbology;
use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\Font\Font;
use MightyPDF\Content\Font\FontWriter;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\Image\GifImage;
use MightyPDF\Content\Image\JpegImage;
use MightyPDF\Content\Image\PngImage;
use MightyPDF\Content\Image\TiffImage;
use MightyPDF\Content\Svg\SvgDocument;
use MightyPDF\Content\Svg\SvgStyle;
use MightyPDF\Content\Svg\SvgTextFont;
use MightyPDF\Content\Text\HorizontalAlign;
use MightyPDF\Content\Text\TextPlacement;
use MightyPDF\Content\Text\TextWrapper;
use MightyPDF\Content\Text\VerticalAlign;
use MightyPDF\Exception\InvalidArgumentException;
use MightyPDF\Exception\RuntimeException;

/**
 * The content-layer entry point for drawing on a page: text now, shapes
 * and images in later milestones. Owns the page's single content Stream,
 * created lazily on first use and then appended to -- so any number of
 * draw calls still produce one combined content stream per page (not one
 * per call) -- plus the bookkeeping needed to reference supporting
 * resources (fonts, later images) from the page's /Resources dictionary.
 *
 * What this class does *not* own is the resources it draws through. A
 * font, an image, a gradient and a spot colour each need allocating,
 * registering with the document and naming in a /Resources dictionary,
 * and all of that lives on ResourceRegistry -- one object per scope,
 * swapped rather than duplicated when an SVG is rendered into a form
 * XObject of its own. Keeping those three steps together is the same
 * discipline as IndirectObjectRegistry centralizing xref bookkeeping:
 * scattering them across call sites is exactly what produced the 2012
 * bugs this project is rebuilding away from, and what let form fields on
 * different pages collide in the shared AcroForm /DR.
 */
final class PageBuilder
{
    private ?Stream $stream = null;

    /**
     * Where resources are allocated, registered and named, and what
     * those names resolve in -- the page's own /Resources, except while
     * an SVG is being rendered into a form XObject of its own. See
     * ResourceRegistry and drawSvg().
     */
    private ResourceRegistry $resources;

    /**
     * This page's key in the structure tree's parent tree, assigned on
     * the first tagged draw. Null until then, so a page that carries no
     * tagged content does not claim an index that nothing points at.
     */
    private ?int $structParents = null;

    /**
     * The next marked-content id for this page. Numbered per page rather
     * than per document -- see ContentStream::beginMarkedContent().
     */
    private int $nextMcid = 0;

    public function __construct(
        private readonly DocumentContext $document,
        private readonly PageContext $page,
    ) {
        $this->resources = new ResourceRegistry($document, $page->resources());
    }

    /**
     * $r/$g/$b (0.0-1.0, default black) are set explicitly on every call
     * rather than left to whatever the page's shared content stream last
     * had in effect -- fillRectangle()/fillRect() etc. also set fill
     * color, and every drawing call on a page shares one continuous
     * content stream (see the class doc comment), so an implicit "use
     * whatever color was last set" default would make text color depend
     * on unrelated drawing order elsewhere on the page.
     *
     * $paint is the way to set text in anything that is not RGB -- a
     * process colour, a named ink. Given, it wins over the triple; the
     * two are not merged, since a colour is one thing or the other.
     */
    public function drawText(
        Font $font,
        float $sizePt,
        float $x,
        float $y,
        string $text,
        float $r = 0.0,
        float $g = 0.0,
        float $b = 0.0,
        ?Paint $paint = null,
    ): static {
        $writer = $font->writerFor($this->document);

        // Encoded before the font is named in this page's resources, so
        // that text the font cannot draw leaves no half-finished trace
        // on a page a caller may still go on to use.
        $encoded = $writer->encode($text);
        $resourceName = $this->resources->fontResourceName($font, $writer);

        $operators = new ContentStream();
        ($paint ?? new Color($r, $g, $b))->applyFill($operators, $this->resources->separationColorSpaceName(...));

        $operators->beginText()
            ->setFont($resourceName, $sizePt)
            ->showTextAt($x, $y, $encoded, $writer->usesHexStrings())
            ->endText();

        $this->append($operators->bytes());

        return $this;
    }

    /**
     * Draws one line of text placed inside a (x, y, width, height) box --
     * (x, y) is the box's bottom-left corner, matching fillRectangle()
     * elsewhere in this class. The text is not wrapped and not clipped:
     * a string wider than the box overflows it, because silently
     * truncating a total or a name is worse than a visibly tight column.
     *
     * This is the call that makes "centre this in that box" a thing the
     * library does rather than a thing every caller re-derives. Doing it
     * by hand needs the font's ascent, descent and cap height, and
     * before those were on Font the only way through was a magic
     * fraction of the type size copied from FPDF -- correct to about a
     * point at body sizes and centimetres out at display sizes. See
     * TextPlacement, which owns the arithmetic, and VerticalAlign for
     * why there are two kinds of middle.
     *
     * Lines up with drawParagraph() by construction: the same box, font
     * and alignment put a single line on the same baseline through
     * either call.
     */
    public function drawTextInBox(
        Font $font,
        float $sizePt,
        float $x,
        float $y,
        float $width,
        float $height,
        string $text,
        HorizontalAlign $align = HorizontalAlign::Left,
        VerticalAlign $valign = VerticalAlign::Middle,
        ?Paint $color = null,
    ): static {
        return $this->drawText(
            $font,
            $sizePt,
            TextPlacement::lineX($align->forSingleLine(), $x, $width, $font->widthOfPt($text, $sizePt)),
            TextPlacement::baselineY($font, $sizePt, $y, $height, $valign),
            $text,
            paint: $color ?? Color::black(),
        );
    }

    /**
     * Draws word-wrapped text into a (x, y, width, height) box -- (x, y)
     * is the box's bottom-left corner, matching fillRectangle()/images
     * elsewhere in this class. $height is required: this method only
     * draws, it doesn't measure -- callers that want a box auto-sized to
     * its content should call TextWrapper::wrap() themselves first (same
     * font/size/width) and size the box from the returned line count,
     * then pass that height in here. That two-step split is simpler than
     * a built-in "auto" mode would be, and needs no dry-run/rollback
     * trick, since MightyPDF is a pure writer with no reader state to
     * undo (contrast this with TCPDF's startTransaction()/MultiCell()/
     * rollbackTransaction() measurement dance).
     *
     * $align: HorizontalAlign, or the strings 'L' (default), 'C', 'R',
     * 'J' this has always taken. Justified means every line except the
     * last gets extra inter-word spacing to fill the box's width; a line
     * with no spaces to stretch is left as-is.
     * $valign: VerticalAlign, or the strings 'T' (default), 'M', 'B'.
     * $r/$g/$b: see drawText()'s doc comment -- set explicitly per line
     * for the same reason.
     *
     * **Where the first baseline lands**, which is what a caller mixing
     * this with drawText() needs: TextPlacement::firstBaselineY() says,
     * for every alignment, and at VerticalAlign::Top that is exactly
     *
     *     $y + $height - $font->ascentPt($sizePt)
     *
     * -- the text's ascent hung from the box's top edge. A single-line
     * cell drawn with drawText() at that same y sits on the same
     * baseline as this method's first line, which is how the two are
     * lined up side by side. drawTextInBox() with the same box does it
     * without the caller restating the formula, and agrees with this
     * method line for line because both ask TextPlacement.
     *
     * Note that the ascent is the *font's*, not the size's, so two boxes
     * of identical geometry do not line up if they are set in different
     * kinds of font: Helvetica rises 0.718 of the nominal size and Times
     * 0.683, while an embedded font reports what its hhea table says,
     * commonly nearer 0.95. Align a row on one font, or place its
     * baselines from one ascentPt() call.
     */
    public function drawParagraph(
        Font $font,
        float $sizePt,
        float $x,
        float $y,
        float $width,
        float $height,
        string $text,
        HorizontalAlign|string $align = HorizontalAlign::Left,
        VerticalAlign|string $valign = VerticalAlign::Top,
        ?float $lineHeightPt = null,
        float $r = 0.0,
        float $g = 0.0,
        float $b = 0.0,
        ?Paint $paint = null,
    ): static {
        $paint ??= new Color($r, $g, $b);
        $align = $align instanceof HorizontalAlign ? $align : HorizontalAlign::fromLegacy($align);
        $valign = $valign instanceof VerticalAlign ? $valign : match (strtoupper($valign)) {
            'M' => VerticalAlign::Middle,
            'B' => VerticalAlign::Bottom,
            default => VerticalAlign::Top,
        };

        $lineHeightPt ??= $sizePt * 1.15;
        $writer = $font->writerFor($this->document);
        $lines = TextWrapper::wrapUtf8($text, $font, $sizePt, $width);
        $lastIndex = count($lines) - 1;

        $resourceName = $this->resources->fontResourceName($font, $writer);
        $operators = new ContentStream();
        $lineY = TextPlacement::firstBaselineY(
            $font,
            $sizePt,
            $y,
            $height,
            count($lines),
            $lineHeightPt,
            $valign,
        );

        foreach ($lines as $index => $line) {
            $lineWidth = $font->widthOfPt($line, $sizePt);
            $spaceCount = substr_count($line, ' ');

            $wordSpacing = 0.0;
            $lineX = TextPlacement::lineX($align, $x, $width, $lineWidth);

            if ($align === HorizontalAlign::Justify
                && $index !== $lastIndex && $spaceCount > 0 && $lineWidth < $width
            ) {
                $wordSpacing = ($width - $lineWidth) / $spaceCount;
            }

            $paint->applyFill($operators, $this->resources->separationColorSpaceName(...));

            $operators->beginText()
                ->setFont($resourceName, $sizePt);

            if ($writer->supportsWordSpacing()) {
                $operators->setWordSpacing($wordSpacing)
                    ->showTextAt($lineX, $lineY, $writer->encode($line), $writer->usesHexStrings());
            } else {
                // A font with two-byte codes cannot be justified with the
                // word-spacing operator -- see ContentStream::
                // showTextRunsAt(), which is what does it instead.
                $operators->showTextRunsAt(
                    $lineX,
                    $lineY,
                    self::spacedRuns($writer, $line, $wordSpacing / $sizePt * 1000.0),
                    $writer->usesHexStrings(),
                );
            }

            $operators->endText();

            $lineY -= $lineHeightPt;
        }

        $this->append($operators->bytes());

        return $this;
    }

    /**
     * One line of text as TJ runs: each word, with $gap thousandths of
     * the font size added after it. A gap of zero is one run and one
     * string, i.e. the same thing a plain Tj would have shown.
     *
     * The space itself stays attached to the word before it rather than
     * being dropped: it is a real glyph with a real width, and the gap
     * is extra space *on top of* it, exactly as the word-spacing
     * operator would have added.
     *
     * @return list<string|float>
     */
    private static function spacedRuns(FontWriter $writer, string $line, float $gap): array
    {
        if ($gap === 0.0) {
            return [$writer->encode($line)];
        }

        $words = explode(' ', $line);
        $last = count($words) - 1;
        $runs = [];

        foreach ($words as $index => $word) {
            if ($index === $last) {
                $runs[] = $writer->encode($word);

                break;
            }

            $runs[] = $writer->encode("$word ");
            $runs[] = $gap;
        }

        return $runs;
    }

    public function drawLine(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $lineWidthPt = 1.0,
        float $r = 0.0,
        float $g = 0.0,
        float $b = 0.0,
    ): static {
        $operators = (new ContentStream())
            ->setLineWidth($lineWidthPt)
            ->setStrokeColorRgb($r, $g, $b)
            ->moveTo($x1, $y1)
            ->lineTo($x2, $y2)
            ->stroke();

        $this->append($operators->bytes());

        return $this;
    }

    public function strokeRectangle(
        float $x,
        float $y,
        float $width,
        float $height,
        float $lineWidthPt = 1.0,
        float $r = 0.0,
        float $g = 0.0,
        float $b = 0.0,
    ): static {
        $operators = (new ContentStream())
            ->setLineWidth($lineWidthPt)
            ->setStrokeColorRgb($r, $g, $b)
            ->rect($x, $y, $width, $height)
            ->stroke();

        $this->append($operators->bytes());

        return $this;
    }

    public function fillRectangle(
        float $x,
        float $y,
        float $width,
        float $height,
        float $r = 0.0,
        float $g = 0.0,
        float $b = 0.0,
    ): static {
        $operators = (new ContentStream())
            ->setFillColorRgb($r, $g, $b)
            ->rect($x, $y, $width, $height)
            ->fill();

        $this->append($operators->bytes());

        return $this;
    }

    /**
     * Draws a 1D barcode into a (x, y, width, height) box -- (x, y) is the
     * bottom-left corner, matching fillRectangle()/images elsewhere in
     * this class. Only the bars are drawn; a human-readable caption (if
     * wanted) is the caller's job via drawText()/drawParagraph() below
     * the barcode, same as every other composite element in this class
     * stays a single-responsibility primitive.
     *
     * Bar width is uniform-narrow-element-scaled to exactly fill $width
     * (Symbology::elements() reports the barcode's width in abstract
     * "module" units; this divides $width by the total module count to
     * get the actual point width of one narrow module).
     *
     * **$quietZone reserves the clear space a scanner needs** inside the
     * box, rather than adding it outside: the bars shrink and the box
     * stays the size the caller asked for, which is the behaviour that
     * composes with a layout. It is off by default because that is what
     * this method has always done -- but a barcode printed with no clear
     * space around it does not scan, so a caller leaving it off is
     * undertaking to leave the space itself. The amount differs by
     * symbology and is asymmetric for EAN-13; Symbology::quietZoneModules()
     * says what it is.
     *
     * $paint is for the rare case of a barcode that is not black. Be
     * careful with it: scanners read contrast, and the usual advice is
     * black bars on white and nothing else.
     */
    public function drawBarcode(
        string $value,
        float $x,
        float $y,
        float $width,
        float $height,
        Symbology|string $symbology = Symbology::Code39,
        bool $quietZone = false,
        ?Paint $paint = null,
    ): static {
        $symbology = Symbology::coerce($symbology);
        $elements = $symbology->elements($value);

        $totalModules = array_sum(array_column($elements, 'widthModules'));
        [$leftQuiet, $rightQuiet] = $quietZone ? $symbology->quietZoneModules() : [0, 0];

        $moduleWidthPt = $width / ($totalModules + $leftQuiet + $rightQuiet);

        // One path with every bar in it rather than one fill per bar: a
        // Code 128 symbol is a few dozen of them, and they all take the
        // same colour by construction.
        $cursor = $x + $leftQuiet * $moduleWidthPt;
        $bars = [];

        foreach ($elements as $element) {
            $elementWidthPt = $element['widthModules'] * $moduleWidthPt;

            if ($element['isBar']) {
                $bars[] = [$cursor, $elementWidthPt];
            }

            $cursor += $elementWidthPt;
        }

        return $this->paintPath(
            static function (ContentStream $path) use ($bars, $y, $height): void {
                foreach ($bars as [$barX, $barWidth]) {
                    $path->rect($barX, $y, $barWidth, $height);
                }
            },
            $paint ?? Color::black(),
        );
    }

    /**
     * Draws a QR code as a square of $size points, with ($x, $y) its
     * bottom-left corner.
     *
     * Square because a QR code is: it is a grid of equal modules, and a
     * box of some other shape would either distort it or leave the caller
     * guessing where the code actually ended up. $size is the whole
     * thing, quiet zone included.
     *
     * **The quiet zone is on by default here**, unlike the 1D barcodes.
     * A QR code is normally placed against other content rather than in a
     * label's white space, so leaving four modules of clear border to the
     * caller is leaving it to be forgotten -- and the symbol then fails to
     * scan for a reason that is invisible on the page.
     *
     * The module count is chosen from the data and the error-correction
     * level, so two codes of different lengths come out at different
     * densities in the same box. $minVersion pins the density where that
     * matters -- a sheet of tickets, a run of labels.
     */
    public function drawQrCode(
        string $value,
        float $x,
        float $y,
        float $size,
        QrEccLevel $level = QrEccLevel::Medium,
        bool $quietZone = true,
        int $minVersion = 1,
        ?Paint $paint = null,
    ): static {
        $code = QrCode::encode($value, $level, $minVersion);

        $margin = $quietZone ? QrCode::QUIET_ZONE_MODULES : 0;
        $modules = $code->size();
        $moduleSize = $size / ($modules + 2 * $margin);

        // Each dark module is a square of exactly $moduleSize, placed on
        // an exact grid. Rounding them to device pixels is the reader's
        // business and it does it better than arithmetic here could --
        // what matters is that the geometry is exact, since a module a
        // fraction narrow makes a symbol a scanner has to work at.
        return $this->paintPath(
            static function (ContentStream $path) use ($code, $modules, $moduleSize, $margin, $x, $y, $size): void {
                for ($row = 0; $row < $modules; ++$row) {
                    for ($column = 0; $column < $modules; ++$column) {
                        if (!$code->isDark($column, $row)) {
                            continue;
                        }

                        $path->rect(
                            $x + ($margin + $column) * $moduleSize,
                            // Row 0 is the top of the symbol and PDF's y
                            // runs up, so the rows are laid bottom-first.
                            $y + $size - ($margin + $row + 1) * $moduleSize,
                            $moduleSize,
                            $moduleSize,
                        );
                    }
                }
            },
            $paint ?? Color::black(),
        );
    }

    /**
     * A Data Matrix (ECC200), the 2D symbology for marking small things.
     *
     * Square by default; pass DataMatrixShape::Rectangular for one of the
     * six long-and-thin sizes, which exist for marking objects that are
     * themselves long and thin.
     *
     * $size is the whole symbol including its quiet zone, so that two
     * symbols asked for at the same size occupy the same space whatever
     * they encode -- the module count varies with the payload and the
     * footprint should not.
     */
    public function drawDataMatrix(
        string $value,
        float $x,
        float $y,
        float $size,
        DataMatrixShape $shape = DataMatrixShape::Square,
        bool $quietZone = true,
        ?Paint $paint = null,
    ): static {
        return $this->drawModuleGrid(
            DataMatrix::encode($value, $shape),
            $x,
            $y,
            $size,
            $quietZone ? DataMatrix::QUIET_ZONE_MODULES : 0,
            $paint,
        );
    }

    /**
     * Paints a 2D symbol's dark modules as one path.
     *
     * The grid is fitted into the box preserving its own aspect ratio and
     * centred in whatever is left over: a rectangular Data Matrix asked
     * for in a square box is a rectangle in the middle of that square,
     * rather than a square symbol nothing can read. One module is one
     * exact rectangle on an exact grid -- rounding to device pixels is the
     * reader's business and it does it better than arithmetic here could.
     */
    private function drawModuleGrid(
        ModuleGrid $grid,
        float $x,
        float $y,
        float $size,
        int $margin,
        ?Paint $paint,
    ): static {
        $columns = $grid->width() + 2 * $margin;
        $rows = $grid->height() + 2 * $margin;

        $moduleSize = min($size / $columns, $size / $rows);
        $offsetX = ($size - $columns * $moduleSize) / 2;
        $offsetY = ($size - $rows * $moduleSize) / 2;

        return $this->paintPath(
            static function (ContentStream $path) use ($grid, $moduleSize, $margin, $x, $y, $size, $offsetX, $offsetY): void {
                for ($row = 0; $row < $grid->height(); ++$row) {
                    for ($column = 0; $column < $grid->width(); ++$column) {
                        if (!$grid->isDark($column, $row)) {
                            continue;
                        }

                        $path->rect(
                            $x + $offsetX + ($margin + $column) * $moduleSize,
                            // Row 0 is the top of the symbol and PDF's y
                            // runs up, so the rows are laid bottom-first.
                            $y + $size - $offsetY - ($margin + $row + 1) * $moduleSize,
                            $moduleSize,
                            $moduleSize,
                        );
                    }
                }
            },
            $paint ?? Color::black(),
        );
    }

    // -- Tagging --------------------------------------------------------

    /**
     * Draws, and attaches what was drawn to a structure element.
     *
     * ```php
     * $body = $document->structure()->document();
     * $content->tagged($body->child(StructureRole::Heading1), fn ($c) =>
     *     $c->drawText(StandardFont::HelveticaBold, 18, 60, 700, 'Results'));
     * ```
     *
     * The closure form is not decoration. A marked-content sequence has to
     * be closed, and closed on the same content stream it was opened on;
     * a begin/end pair left to the caller is a pair somebody eventually
     * fails to match, and an unmatched BDC makes every mark after it
     * belong to the wrong element -- silently, and only for the people who
     * cannot see the page.
     *
     * Does nothing but draw if the document is not tagged, so the same
     * code path serves both.
     */
    public function tagged(StructureElement $element, \Closure $draw): static
    {
        $structure = $this->document->activeStructure();

        if ($structure === null || !$this->page instanceof Page) {
            $draw($this);

            return $this;
        }

        $mcid = $this->nextMarkedContentId();

        $this->append((new ContentStream())->beginMarkedContent($element->role->value, $mcid)->bytes());

        try {
            $draw($this);
        } finally {
            // In a finally so that a closure that throws still closes its
            // sequence: the alternative is a content stream that is
            // malformed from that point to the end of the page.
            $this->append((new ContentStream())->endMarkedContent()->bytes());
        }

        $element->addMarkedContent($mcid, $this->page->objectId());
        $structure->recordMark($this->structParents(), $mcid, $element);

        return $this;
    }

    /**
     * Draws something that is not part of the document's content: a
     * running header, a page number, a rule, a watermark.
     *
     * See ContentStream::beginArtifact() for why this is a positive
     * statement rather than an omission. In a tagged document everything
     * on a page must be either tagged content or an artifact -- untagged
     * content is what a checker reports, and marking the page furniture
     * is how a document stops having any.
     *
     * @param string|null $type /Pagination, /Layout, /Page or /Background,
     *        where the kind is worth stating
     */
    public function artifact(\Closure $draw, ?string $type = null): static
    {
        if ($this->document->activeStructure() === null) {
            $draw($this);

            return $this;
        }

        $this->append((new ContentStream())->beginArtifact($type)->bytes());

        try {
            $draw($this);
        } finally {
            $this->append((new ContentStream())->endMarkedContent()->bytes());
        }

        return $this;
    }

    /**
     * The page's /StructParents index, assigned on first use.
     *
     * Assigned lazily and per page, from a counter on the structure tree,
     * so that a document's pages are numbered 0, 1, 2 in the parent tree
     * whether or not every page has tagged content on it.
     */
    private function structParents(): int
    {
        $structure = $this->document->activeStructure();

        if ($this->structParents === null && $structure !== null && $this->page instanceof Page) {
            $this->structParents = $structure->nextStructParents();
            $this->page->setStructParents($this->structParents);
        }

        return $this->structParents ?? 0;
    }

    private function nextMarkedContentId(): int
    {
        // Per page, because that is the scope the structure tree looks
        // them up in -- see ContentStream::beginMarkedContent().
        return $this->nextMcid++;
    }

    // -- General shapes -------------------------------------------------
    //
    // Everything below takes a Paint and a Stroke rather than the float
    // triples above. That is the difference between the two families: the
    // originals are the convenience calls this library started with and
    // they still mean exactly what they did, while these are the general
    // form -- any colour space, any dash, filled or stroked or both.

    /**
     * A rectangle, filled and/or stroked. Both are optional, and a call
     * with neither draws nothing rather than raising: that is what lets a
     * caller pass a style straight through without first asking whether
     * there is anything to paint.
     *
     * ($x, $y) is the bottom-left corner, matching every other box in
     * this class.
     */
    public function drawRectangle(
        float $x,
        float $y,
        float $width,
        float $height,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
    ): static {
        // The one shape PDF has an operator for, so it goes out as that
        // rather than as four lines: same marks, a third of the bytes.
        return $this->paintPath(
            static fn (ContentStream $path) => $path->rect($x, $y, $width, $height),
            $fill,
            $stroke,
        );
    }

    /** The same with its corners rounded to $radius -- see Shapes. */
    public function drawRoundedRectangle(
        float $x,
        float $y,
        float $width,
        float $height,
        float $radius,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
    ): static {
        return $this->drawPath(
            static fn (PathSink $path) => Shapes::roundedRectangle($path, $x, $y, $width, $height, $radius),
            $fill,
            $stroke,
        );
    }

    /** An ellipse centred on ($cx, $cy), with the given semi-axes. */
    public function drawEllipse(
        float $cx,
        float $cy,
        float $radiusX,
        float $radiusY,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
    ): static {
        return $this->drawPath(
            static fn (PathSink $path) => Shapes::ellipse($path, $cx, $cy, $radiusX, $radiusY),
            $fill,
            $stroke,
        );
    }

    public function drawCircle(
        float $cx,
        float $cy,
        float $radius,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
    ): static {
        return $this->drawEllipse($cx, $cy, $radius, $radius, $fill, $stroke);
    }

    /**
     * A closed polygon through $points, each a [x, y] pair.
     *
     * @param list<array{float, float}> $points
     */
    public function drawPolygon(
        array $points,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
        bool $evenOdd = false,
    ): static {
        return $this->drawPath(
            static fn (PathSink $path) => Shapes::polygon($path, $points, close: true),
            $fill,
            $stroke,
            $evenOdd,
        );
    }

    /**
     * The open form: a run of connected segments, stroked and never
     * filled. What a line chart's series is.
     *
     * @param list<array{float, float}> $points
     */
    public function drawPolyline(array $points, ?Stroke $stroke = null): static
    {
        return $this->drawPath(
            static fn (PathSink $path) => Shapes::polygon($path, $points, close: false),
            null,
            $stroke ?? new Stroke(),
        );
    }

    /**
     * A regular polygon inscribed in a circle -- a triangle, a hexagon, a
     * stop sign. $rotationDegrees turns it; 90 puts a vertex at the top.
     */
    public function drawRegularPolygon(
        float $cx,
        float $cy,
        float $radius,
        int $sides,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
        float $rotationDegrees = 90.0,
    ): static {
        return $this->drawPolygon(
            Shapes::regularPolygonPoints($cx, $cy, $radius, $sides, $rotationDegrees),
            $fill,
            $stroke,
        );
    }

    /**
     * An arbitrary path, built by the closure and then painted.
     *
     * The closure is handed a PathSink -- moveTo, lineTo, curveTo,
     * closePath, which is everything PDF can draw -- and its return value
     * is ignored:
     *
     * ```php
     * $content->drawPath(
     *     fn (PathSink $path) => $path->moveTo(72, 72)->lineTo(172, 172)
     *         ->curveTo(200, 200, 240, 160, 272, 172)->closePath(),
     *     fill: Color::fromHex('#334155'),
     *     stroke: Stroke::hairline(),
     * );
     * ```
     *
     * $evenOdd picks the fill rule. The default nonzero rule fills a
     * subpath drawn inside another one in the same direction, while the
     * even-odd rule leaves it as a hole -- which is the whole difference
     * between a washer and a disc.
     *
     * The graphics state every Paint and Stroke sets is confined to a
     * q/Q pair, so a dash pattern or a separation colour space set for
     * one shape cannot leak into the next thing drawn on the page.
     *
     * @param \Closure(PathSink): mixed $path
     */
    public function drawPath(
        \Closure $path,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
        bool $evenOdd = false,
    ): static {
        return $this->paintPath($path, $fill, $stroke, $evenOdd);
    }

    /**
     * drawPath()'s body, with the closure typed against ContentStream
     * rather than PathSink so that drawRectangle() can reach the "re"
     * operator -- which is a path all by itself and so is not one of the
     * four operations PathSink describes.
     *
     * @param \Closure(ContentStream): mixed $path
     */
    private function paintPath(
        \Closure $path,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
        bool $evenOdd = false,
    ): static {
        if ($fill === null && $stroke === null) {
            return $this;
        }

        $operators = new ContentStream();
        $operators->pushGraphicsState();

        $fill?->applyFill($operators, $this->resources->separationColorSpaceName(...));
        $stroke?->apply($operators, $this->resources->separationColorSpaceName(...));

        $path($operators);

        match (true) {
            $fill !== null && $stroke !== null => $operators->fillAndStroke($evenOdd),
            $fill !== null => $operators->fill($evenOdd),
            default => $operators->stroke(),
        };

        $operators->popGraphicsState();

        $this->append($operators->bytes());

        return $this;
    }

    // -- Scoped graphics state ------------------------------------------
    //
    // Each of these draws whatever the closure draws, under some change
    // to the graphics state, and puts the state back afterwards. The
    // closure is what makes that a guarantee rather than a convention:
    // there is no way to leave a transform, a clip or an alpha in effect
    // by forgetting to close it, which is exactly what TCPDF's paired
    // StartTransform()/StopTransform() lets you do.
    //
    // They nest, and the closure is handed this same PageBuilder, so
    // anything drawable is drawable inside one -- text, images, an SVG,
    // another transform.

    /**
     * Draws under an arbitrary transformation matrix [a b c d e f],
     * concatenated onto whatever is already in effect.
     *
     * @param array{float, float, float, float, float, float} $matrix
     * @param \Closure(self): mixed $draw
     */
    public function transformed(array $matrix, \Closure $draw): static
    {
        $this->append((new ContentStream())->pushGraphicsState()->concatMatrix(...$matrix)->bytes());

        try {
            $draw($this);
        } finally {
            // In a finally so that a closure that throws does not leave
            // the page's content stream with an unbalanced q. That would
            // corrupt everything drawn afterwards, on a page a caller may
            // well go on to use after catching.
            $this->append((new ContentStream())->popGraphicsState()->bytes());
        }

        return $this;
    }

    /**
     * Draws rotated by $degrees about ($originX, $originY).
     *
     * Positive is counter-clockwise, following PDF's Y-up axes: 90
     * degrees reads bottom-to-top, which is the direction a chart's
     * Y-axis label runs.
     *
     * @param \Closure(self): mixed $draw
     */
    public function rotated(float $degrees, float $originX, float $originY, \Closure $draw): static
    {
        return $this->transformed(Shapes::rotationMatrix($degrees, $originX, $originY), $draw);
    }

    /**
     * Draws scaled about ($originX, $originY). A negative factor mirrors
     * across that axis.
     *
     * @param \Closure(self): mixed $draw
     */
    public function scaled(
        float $scaleX,
        float $scaleY,
        float $originX,
        float $originY,
        \Closure $draw,
    ): static {
        return $this->transformed(Shapes::scaleMatrix($scaleX, $scaleY, $originX, $originY), $draw);
    }

    /** @param \Closure(self): mixed $draw */
    public function translated(float $dx, float $dy, \Closure $draw): static
    {
        return $this->transformed([1.0, 0.0, 0.0, 1.0, $dx, $dy], $draw);
    }

    /**
     * Draws with everything outside the rectangle clipped away -- the
     * common case, and the one that needs no path built.
     *
     * @param \Closure(self): mixed $draw
     */
    public function clippedToRectangle(
        float $x,
        float $y,
        float $width,
        float $height,
        \Closure $draw,
    ): static {
        return $this->clippedToPath(
            static fn (ContentStream $path) => $path->rect($x, $y, $width, $height),
            $draw,
        );
    }

    /**
     * The general form: clips to an arbitrary path, built exactly as
     * drawPath() builds one. The path itself is not painted.
     *
     * @param \Closure(PathSink): mixed $path
     * @param \Closure(self): mixed $draw
     */
    public function clippedToPath(\Closure $path, \Closure $draw, bool $evenOdd = false): static
    {
        $operators = new ContentStream();
        $operators->pushGraphicsState();
        $path($operators);
        $operators->clipToPath($evenOdd);

        $this->append($operators->bytes());

        try {
            $draw($this);
        } finally {
            $this->append((new ContentStream())->popGraphicsState()->bytes());
        }

        return $this;
    }

    /**
     * Draws at less than full opacity: $fillAlpha applies to fills and
     * text, $strokeAlpha to outlines, each 0.0 (invisible) to 1.0
     * (opaque). Passing one sets both.
     *
     * This is constant alpha, which is what a watermark or a tint panel
     * wants. A gradient that fades is a soft mask and a different
     * mechanism -- see SvgSoftMask, which is how the SVG renderer does
     * it.
     *
     * @param \Closure(self): mixed $draw
     */
    public function faded(float $fillAlpha, \Closure $draw, ?float $strokeAlpha = null): static
    {
        $strokeAlpha ??= $fillAlpha;

        foreach (['fill' => $fillAlpha, 'stroke' => $strokeAlpha] as $which => $alpha) {
            if ($alpha < 0.0 || $alpha > 1.0) {
                throw new InvalidArgumentException("The $which alpha must be between 0.0 and 1.0, got $alpha.");
            }
        }

        $resourceName = $this->resources->extGStateResourceName($fillAlpha, $strokeAlpha);

        $this->append(
            (new ContentStream())->pushGraphicsState()->setExtGState($resourceName)->bytes(),
        );

        try {
            $draw($this);
        } finally {
            $this->append((new ContentStream())->popGraphicsState()->bytes());
        }

        return $this;
    }

    /**
     * One line of text turned about its own anchor point -- a rotated
     * column heading, a vertical axis label, a "DRAFT" across the page.
     *
     * ($x, $y) is the baseline origin before rotation and stays fixed:
     * the text turns about the point it would otherwise have started at,
     * so a caller placing one does not also have to work out where the
     * rotation moved it to.
     */
    public function drawTextRotated(
        Font $font,
        float $sizePt,
        float $x,
        float $y,
        float $degrees,
        string $text,
        ?Paint $paint = null,
    ): static {
        return $this->rotated(
            $degrees,
            $x,
            $y,
            fn (self $content) => $content->drawText($font, $sizePt, $x, $y, $text, paint: $paint),
        );
    }

    /**
     * Escape hatch for anything not covered by the convenience methods
     * above (used by, e.g., SVG rendering to inject an arbitrary path):
     * appends a caller-built ContentStream's operators to this page's
     * content stream directly.
     */
    public function drawCustom(ContentStream $operators): static
    {
        $this->append($operators->bytes());

        return $this;
    }

    public function drawJpeg(string $path, float $x, float $y, float $width, float $height): static
    {
        $image = $this->loadImage($path, 'jpeg', fn (string $bytes) => JpegImage::fromBytes($this->document, $bytes));

        return $this->placeImage($image, $x, $y, $width, $height);
    }

    public function drawPng(string $path, float $x, float $y, float $width, float $height): static
    {
        $image = $this->loadImage($path, 'png', fn (string $bytes) => PngImage::fromBytes($this->document, $bytes));

        return $this->placeImage($image, $x, $y, $width, $height);
    }

    public function drawGif(string $path, float $x, float $y, float $width, float $height): static
    {
        $image = $this->loadImage($path, 'gif', fn (string $bytes) => GifImage::fromBytes($this->document, $bytes));

        return $this->placeImage($image, $x, $y, $width, $height);
    }

    /**
     * Places a TIFF, which is what a scanner or a fax gateway produces.
     *
     * A fax-coded (CCITT G3/G4) TIFF is relayed into the page without
     * being decoded, so a scanned batch embeds at the size it arrived
     * rather than as bitmaps -- see TiffImage.
     *
     * @param int $page which image in the file; a TIFF may hold many, and
     *        a multi-page fax does
     */
    public function drawTiff(
        string $path,
        float $x,
        float $y,
        float $width,
        float $height,
        int $page = 0,
    ): static {
        $image = $this->loadImage(
            $path,
            "tiff#$page",
            fn (string $bytes) => TiffImage::fromBytes($this->document, $bytes, $page),
        );

        return $this->placeImage($image, $x, $y, $width, $height);
    }

    /** How many images a TIFF file holds. */
    public static function tiffPageCount(string $path): int
    {
        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw new RuntimeException("Unable to read image file: $path");
        }

        return TiffImage::pageCount($bytes);
    }

    /**
     * Reads $path once and hashes its bytes to dedupe against the
     * document-wide image cache (see Document::cachedImage()) -- the same
     * image embedded on multiple pages (e.g. a logo/letterhead) is
     * decoded and registered exactly once, and every later draw call just
     * reuses the same XObject stream. $build only runs, and only
     * allocates/registers a new object, on a cache miss.
     *
     * $format is part of the cache key so that a hit still implies the
     * caller asked for the format the bytes were actually decoded as --
     * otherwise drawPng() on a file already embedded via drawGif() would
     * short-circuit to the cached XObject and never check the PNG
     * signature, silently accepting a call that should have been rejected.
     */
    private function loadImage(string $path, string $format, \Closure $build): Stream
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Unable to read image file: $path");
        }

        $contentHash = $format . ':' . hash('xxh128', $bytes);

        $cached = $this->document->cachedImage($contentHash);
        if ($cached !== null) {
            return $cached;
        }

        $image = $build($bytes);
        $this->document->register($image);
        $this->document->cacheImage($contentHash, $image);

        return $image;
    }

    /**
     * Owns every side effect of "place this already-built image XObject
     * on this page": wiring it into /Resources /XObject and appending the
     * placement operators. Same "one method, every side effect"
     * discipline as fontResourceName(). Registration with the document is
     * loadImage()'s responsibility, not this method's, since a cache hit
     * must not re-register an object that's already registered.
     */
    private function placeImage(Stream $image, float $x, float $y, float $width, float $height): static
    {
        $resourceName = $this->resources->nameXObject($image->objectId());

        $operators = (new ContentStream())->drawImage($resourceName, $x, $y, $width, $height);
        $this->append($operators->bytes());

        return $this;
    }

    /**
     * Places an SVG image, scaled/positioned to fill (x, y, width, height)
     * in points, flipping SVG's top-left/Y-down coordinate convention to
     * PDF's bottom-left/Y-up one via a single placement matrix -- every
     * coordinate inside the SVG itself is used exactly as authored, with
     * no per-shape sign-flipping needed.
     *
     * The drawing becomes a form XObject rather than operators appended
     * to the page, so that placing the same one twice costs one drawing.
     * That is the difference between a linear cost and a fixed one for
     * the case that prompted it -- a logo on every page of a report --
     * where each placement used to re-read the file, re-parse it,
     * re-render every shape into the page's content stream, and register
     * a fresh set of gradient and pattern objects for it. Measured on a
     * 179 KB drawing placed on twenty pages: 755 ms and 280 KB before,
     * against 41 ms and 14 KB for the one placement it now does.
     *
     * A cached drawing is reused only where the *placement* matches as
     * well as the file, which is what makes reuse safe rather than
     * merely cheap. A gradient is painted through a pattern, and pattern
     * space is fixed to the page rather than to the CTM in effect (see
     * SvgShadingPattern), so the placement is folded into the pattern
     * matrices inside -- two placements that differ have genuinely
     * different contents. Keying on both means a hit is a drawing that
     * would have come out byte for byte the same.
     *
     * A caller-supplied $fontResolver is an arbitrary closure whose
     * answers cannot be part of a cache key, so a drawing placed through
     * one is built fresh every time. It is still a form XObject: what
     * varies is whether it is shared, not what it is.
     */
    public function drawSvg(
        string $path,
        float $x,
        float $y,
        float $width,
        float $height,
        ?\Closure $fontResolver = null,
    ): static {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Unable to read SVG file: $path");
        }

        // Keyed on what the caller asked for rather than on the
        // placement matrix derived from it, though the two say the same
        // thing: the matrix follows from the file's viewBox and these
        // four numbers, and the file is already part of the key. Asking
        // the question in the caller's terms is what lets a hit skip the
        // parse as well as the drawing -- deriving the matrix first
        // would mean parsing every placement just to look up the answer,
        // which on a 179 KB drawing is 5 ms a page for nothing.
        $key = 'svg:' . hash('xxh128', $bytes) . ':' . implode(',', [$x, $y, $width, $height]);
        $form = $fontResolver === null ? $this->document->cachedImage($key) : null;

        if ($form === null) {
            $svg = SvgDocument::fromString($bytes);

            $scaleX = $width / $svg->viewBoxWidth;
            $scaleY = $height / $svg->viewBoxHeight;

            $placement = [
                $scaleX,
                0.0,
                0.0,
                -$scaleY,
                $x - $svg->viewBoxX * $scaleX,
                $y + $height + $svg->viewBoxY * $scaleY,
            ];

            $form = $this->svgForm($svg, $placement, $x, $y, $width, $height, $fontResolver);

            if ($fontResolver === null) {
                $this->document->cacheImage($key, $form);
            }
        }

        $operators = (new ContentStream())->paintXObject($this->resources->nameXObject($form->objectId()));
        $this->append($operators->bytes());

        return $this;
    }

    /**
     * Renders a drawing into a form XObject of its own: its content, and
     * the /Resources naming every font, gradient, pattern and image it
     * reached for.
     *
     * Those resources are named in a scope of the XObject's own rather
     * than the page's, which is the whole reason it can be placed on a
     * page that has never seen them -- see ResourceRegistry. The swap is
     * undone in a finally, so a drawing that throws part-way through
     * leaves the page naming things in its own resources again rather
     * than into an XObject nobody will place.
     *
     * The /BBox is the rectangle the caller asked the drawing to fill,
     * which a reader clips to. That is what the drawing itself says its
     * extent is: an SVG's root viewport is `overflow: hidden` by
     * default, so a shape reaching outside the viewBox is clipped by
     * every browser too.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $placement
     */
    private function svgForm(
        SvgDocument $svg,
        array $placement,
        float $x,
        float $y,
        float $width,
        float $height,
        ?\Closure $fontResolver,
    ): Stream {
        $resources = new Dictionary();
        $outer = $this->resources;
        $this->resources = new ResourceRegistry($this->document, $resources);

        $operators = new ContentStream();
        $operators->pushGraphicsState()->concatMatrix(...$placement);

        try {
            // The placement matrix is handed over as well as emitted: a
            // gradient is painted through a pattern, and a pattern is
            // positioned relative to the page rather than to the CTM this
            // "cm" just set. See SvgShadingPattern.
            $svg->render(
                $operators,
                $this->resources,
                $placement,
                fn (SvgStyle $style): ?SvgTextFont => $this->svgTextFont($style, $fontResolver),
            );
        } finally {
            $this->resources = $outer;
        }

        $operators->popGraphicsState();

        $form = new Stream($this->document->allocate(), $operators->bytes());
        $form->set('Type', new PdfName('XObject'));
        $form->set('Subtype', new PdfName('Form'));
        $form->set('BBox', new PdfRectangle($x, $y, $x + $width, $y + $height));
        $form->set('Resources', $resources);

        $this->document->register($form);

        return $form;
    }

    /**
     * Chooses the font a piece of SVG text is drawn with, and names it
     * in this page's resources.
     *
     * The default mapping is to the standard 14: an SVG names a font
     * family the way CSS does -- a list of preferences ending in a
     * generic name -- and there is no font on the machine to look those
     * up in, nor should embedding one be decided behind the caller's
     * back. So "serif" and anything Times-like becomes Times, monospace
     * becomes Courier, and everything else Helvetica, with the bold and
     * italic cuts chosen from font-weight and font-style.
     *
     * A caller who wants the drawing's own typeface passes a resolver
     * to drawSvg(), which is handed the same three facts and may return
     * any Font -- an EmbeddedFont included.
     */
    private function svgTextFont(SvgStyle $style, ?\Closure $resolver): ?SvgTextFont
    {
        $font = $resolver === null
            ? StandardFont::matching($style->fontFamily, $style->bold, $style->italic)
            : $resolver($style->fontFamily ?? '', $style->bold, $style->italic);

        if (!$font instanceof Font) {
            return null;
        }

        $writer = $font->writerFor($this->document);

        return new SvgTextFont($this->resources->fontResourceName($font, $writer), $font, $writer);
    }

    /**
     * A single- or multi-line text field.
     *
     * $font may be one of the standard 14 or an embedded TrueType font
     * loaded with subset: false -- see formFontResourceName() for why a
     * subset is refused.
     */
    public function addTextField(
        string $name,
        float $x,
        float $y,
        float $width,
        float $height,
        ?string $value = null,
        Font $font = StandardFont::Helvetica,
        float $fontSizePt = 10.0,
        ?int $maxLength = null,
        ?int $align = null,
        bool $multiline = false,
        bool $readonly = false,
    ): static {
        $resourceName = $this->formFontResourceName($font);

        $field = new TextField(
            $this->document->allocate(),
            $name,
            new PdfRectangle($x, $y, $x + $width, $y + $height),
            $resourceName,
            $fontSizePt,
            $value,
            $maxLength,
            $align,
            $multiline,
            $readonly,
        );

        $this->registerField($field);

        return $this;
    }

    public function addCheckbox(
        string $name,
        float $x,
        float $y,
        float $size,
        bool $checked = false,
        string $exportValue = 'Yes',
        MarkStyle $mark = MarkStyle::Check,
    ): static {
        $onAppearance = $this->buildMarkAppearance($size, $mark);
        $offAppearance = $this->buildMarkAppearance($size, null);
        $this->document->register($onAppearance);
        $this->document->register($offAppearance);

        $field = new CheckboxField(
            $this->document->allocate(),
            $name,
            new PdfRectangle($x, $y, $x + $size, $y + $size),
            $checked,
            $onAppearance,
            $offAppearance,
            $exportValue,
            $mark->captionCharacter(),
            $this->dingbatResourceName(),
        );

        $this->registerField($field);

        return $this;
    }

    /**
     * The appearance stream for one state of a button widget: $mark's
     * glyph for "on", or null for "off", which is intentionally blank (an
     * empty box). Shared by checkboxes and radio options -- the two
     * differ only in which MarkStyle they default to.
     */
    private function buildMarkAppearance(float $size, ?MarkStyle $mark): Stream
    {
        $operators = new ContentStream();
        $mark?->draw($operators, $size);

        $stream = new Stream($this->document->allocate(), $operators->bytes(), compress: false);
        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Form'));
        $stream->set('BBox', new PdfRectangle(0, 0, $size, $size));

        return $stream;
    }

    /**
     * A group of mutually-exclusive radio buttons sharing one field name.
     * Unlike addCheckbox(), the parent RadioGroupField is never itself a
     * page annotation -- only its per-option RadioButtonWidget kids are,
     * so registerField() (which assumes a single widget IS the field)
     * doesn't apply here; this method does the equivalent wiring itself.
     *
     * @param list<array{exportValue: string, x: float, y: float, size: float}> $options
     */
    public function addRadioGroup(string $name, array $options, ?string $checkedExportValue = null, MarkStyle $mark = MarkStyle::Dot): static
    {
        $group = new RadioGroupField($this->document->allocate(), $name, $checkedExportValue);
        $this->document->register($group);

        foreach ($options as $option) {
            $onAppearance = $this->buildMarkAppearance($option['size'], $mark);
            $offAppearance = $this->buildMarkAppearance($option['size'], null);
            $this->document->register($onAppearance);
            $this->document->register($offAppearance);

            $widget = new RadioButtonWidget(
                $this->document->allocate(),
                $group->objectId(),
                new PdfRectangle($option['x'], $option['y'], $option['x'] + $option['size'], $option['y'] + $option['size']),
                $option['exportValue'],
                $checkedExportValue !== null && $checkedExportValue === $option['exportValue'],
                $onAppearance,
                $offAppearance,
                $mark->captionCharacter(),
                $this->dingbatResourceName(),
            );
            $this->document->register($widget);

            $group->addKid($widget->objectId());
            $this->page->addAnnotation($widget->objectId());
        }

        $this->document->acroForm()->addField($group->objectId());

        return $this;
    }

    /**
     * A scrollable list of options, zero or one of which is selected.
     * Same underlying /FT /Ch as addDropdown() -- see ChoiceField.
     *
     * @param list<string> $options
     */
    public function addListBox(
        string $name,
        array $options,
        float $x,
        float $y,
        float $width,
        float $height,
        ?string $value = null,
        Font $font = StandardFont::Helvetica,
        float $fontSizePt = 10.0,
    ): static {
        return $this->addChoiceField($name, $options, $x, $y, $width, $height, $value, $font, $fontSizePt, combo: false);
    }

    /**
     * A dropdown ("combo box" in spec terms) with one selected option.
     * Same underlying /FT /Ch as addListBox() -- see ChoiceField.
     *
     * @param list<string> $options
     */
    public function addDropdown(
        string $name,
        array $options,
        float $x,
        float $y,
        float $width,
        float $height,
        ?string $value = null,
        Font $font = StandardFont::Helvetica,
        float $fontSizePt = 10.0,
    ): static {
        return $this->addChoiceField($name, $options, $x, $y, $width, $height, $value, $font, $fontSizePt, combo: true);
    }

    /**
     * @param list<string> $options
     */
    private function addChoiceField(
        string $name,
        array $options,
        float $x,
        float $y,
        float $width,
        float $height,
        ?string $value,
        Font $font,
        float $fontSizePt,
        bool $combo,
    ): static {
        $resourceName = $this->formFontResourceName($font);

        $field = new ChoiceField(
            $this->document->allocate(),
            $name,
            new PdfRectangle($x, $y, $x + $width, $y + $height),
            $resourceName,
            $fontSizePt,
            $options,
            $value,
            $combo,
        );

        $this->registerField($field);

        return $this;
    }

    /**
     * A clickable region of the page that opens $uri.
     *
     * A link draws nothing: it is laid over whatever is already there, so
     * the underlined blue text that makes a link *look* like one is drawn
     * separately. That is the right way round -- a link over an image, a
     * button or a whole table cell is just as ordinary as one over text.
     *
     * ```php
     * $content->drawText($font, 12.0, 72, 700, 'mightypdf.dev', r: 0.1, g: 0.3, b: 0.8);
     * $content->addLink(72, 697, $font->widthOfPt('mightypdf.dev', 12.0), 14, 'https://mightypdf.dev');
     * ```
     */
    public function addLink(float $x, float $y, float $width, float $height, string $uri): static
    {
        return $this->addAnnotation(LinkAnnotation::toUri(
            $this->document->allocate(),
            new PdfRectangle($x, $y, $x + $width, $y + $height),
            $uri,
        ));
    }

    /**
     * The same, going somewhere in this document rather than out of it --
     * a table of contents, a footnote, a "back to the top".
     */
    public function addInternalLink(
        float $x,
        float $y,
        float $width,
        float $height,
        Destination $destination,
    ): static {
        return $this->addAnnotation(LinkAnnotation::toDestination(
            $this->document->allocate(),
            new PdfRectangle($x, $y, $x + $width, $y + $height),
            $destination,
        ));
    }

    private function addAnnotation(Dictionary $annotation): static
    {
        $this->document->register($annotation);
        $this->page->addAnnotation($annotation->objectId());

        return $this;
    }

    /**
     * Puts an already-attached file on the page as an icon that opens it.
     *
     * $file is what Document::attach() returned, so the icon and the
     * reader's attachments panel point at one embedded file rather than
     * at two copies of it:
     *
     * ```php
     * $workings = $document->attach('workings.xlsx', $bytes, mediaType: '...');
     * $content->addFileAttachment($workings, x: 500, y: 640, size: 20);
     * ```
     *
     * The icon is drawn by the reader from $icon and the rectangle is a
     * hint at its size rather than a frame it is fitted to -- see
     * AttachmentIcon.
     */
    public function addFileAttachment(
        FileSpecification $file,
        float $x,
        float $y,
        float $size = 20.0,
        AttachmentIcon $icon = AttachmentIcon::PushPin,
        ?string $note = null,
    ): static {
        return $this->addAnnotation(new FileAttachmentAnnotation(
            $this->document->allocate(),
            new PdfRectangle($x, $y, $x + $size, $y + $size),
            $file,
            $icon,
            $note,
        ));
    }

    /**
     * An unsigned signature field placeholder -- reserves a spot on the
     * page and in /AcroForm for a signature to be added later by some
     * other process. This library does not itself sign documents; see
     * SignatureField's docblock.
     */
    public function addSignatureField(string $name, float $x, float $y, float $width, float $height): static
    {
        // Blank, but present -- see SignatureField, and buildMarkAppearance()
        // for the same shape of object one field type over.
        $appearance = $this->buildEmptyAppearance($width, $height);
        $this->document->register($appearance);

        $field = new SignatureField(
            $this->document->allocate(),
            $name,
            new PdfRectangle($x, $y, $x + $width, $y + $height),
            $appearance,
        );

        $this->registerField($field);

        return $this;
    }

    /** A form XObject the size of a widget with nothing drawn in it. */
    private function buildEmptyAppearance(float $width, float $height): Stream
    {
        $stream = new Stream($this->document->allocate(), '', compress: false);
        $stream->set('Type', new PdfName('XObject'));
        $stream->set('Subtype', new PdfName('Form'));
        $stream->set('BBox', new PdfRectangle(0, 0, $width, $height));

        return $stream;
    }

    /**
     * Owns every side effect of "add this field to this page": register
     * it with the document, list it in the page's /Annots, and list it
     * in the document's single shared AcroForm /Fields. Same discipline
     * as fontResourceName()/placeImage().
     */
    private function registerField(TextField|CheckboxField|ChoiceField|SignatureField $field): void
    {
        $this->document->register($field);
        $this->page->addAnnotation($field->objectId());
        $this->document->acroForm()->addField($field->objectId());
    }

    /**
     * Unlike page /Resources, the AcroForm's /DR is one dictionary shared
     * by every page, so AcroForm owns both the naming and the dedupe --
     * see AcroForm::fontResourceName().
     *
     * A field's font is not used the way a drawn font is. Its /DA names
     * the font a *reader* lays out what someone types with, and what
     * they will type is not known here -- so a subset, which holds only
     * the glyphs this document already drew, is the one thing it must
     * not be. Hence the refusal below rather than a silent substitution:
     * a subset points at a font whose missing characters only show up
     * when someone fills the form in.
     */
    /**
     * The /DR name of the ZapfDingbats a reader draws button captions
     * with. Registered under its conventional name, which is not a
     * cosmetic choice -- see AcroForm::fontResourceName().
     */
    private function dingbatResourceName(): string
    {
        return $this->document->acroForm()->fontResourceName(
            StandardFont::ZapfDingbats->cacheKey(),
            StandardFont::ZapfDingbats->writerFor($this->document)->dictionary(),
            'ZaDb',
        );
    }

    private function formFontResourceName(Font $font): string
    {
        if ($font instanceof EmbeddedFont && $font->isSubset()) {
            throw new FontException(sprintf(
                'The font "%s" is subset, so it holds only the characters this document draws -- a form field needs '
                . 'every character someone might type into it. Load it with EmbeddedFont::load($path, subset: false) '
                . 'to embed it whole.',
                $font->name(),
            ));
        }

        return $this->document->acroForm()->fontResourceName(
            $font->cacheKey(),
            $font->writerFor($this->document)->dictionary(),
        );
    }

    private function append(string $bytes): void
    {
        if ($this->stream === null) {
            $this->stream = new Stream($this->document->allocate(), '');
            $this->document->register($this->stream);
            $this->page->addContentStream($this->stream);
        }

        $this->stream->appendBytes($bytes);
    }
}
