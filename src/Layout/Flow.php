<?php

declare(strict_types=1);

namespace MightyPDF\Layout;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Assembler\Types\PdfRectangle;
use MightyPDF\Content\Barcode\QrEccLevel;
use MightyPDF\Content\Barcode\Symbology;
use MightyPDF\Content\Color;
use MightyPDF\Content\Dash;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Content\Paint;
use MightyPDF\Content\PathSink;
use MightyPDF\Content\Stroke;
use MightyPDF\Content\Text\GlyphFallback;
use MightyPDF\Content\Text\TextPlacement;
use MightyPDF\Content\Text\TextWrapper;

/**
 * A document laid out as flow rather than as arithmetic: a cursor, a
 * cell, a page that breaks itself, and coordinates in millimetres from
 * the top-left.
 *
 * Everything here is built *on* PageBuilder and nothing is built into
 * it. The content layer stays a pure writer with no cursor, no page
 * state and no idea this exists -- which is what lets a caller mix the
 * two freely: content() hands back the PageBuilder for the current page,
 * and toPointsX()/toPointsY() convert a coordinate so a chart drawn
 * through the primitives lands in the same space as the text around it.
 *
 * **Coordinates.** X runs right and Y runs *down* from the top-left
 * corner, in whatever Unit this was given (millimetres by default). That
 * is the opposite of PDF's own bottom-left, Y-up convention, and it is
 * deliberate: every page description a person writes -- a margin, a
 * header depth, a row height -- is measured from the top of the sheet.
 * The flip happens in toPointsY() and nowhere else.
 *
 * **Page breaks.** cell() and paragraph() start a new page when what
 * they are about to draw would cross the bottom margin. An element
 * taller than the page body is drawn anyway rather than looping: it
 * overflows visibly, which is a bug a person can see, where a silent
 * infinite break is one they cannot.
 *
 * **Per-page furniture.** onEachPage() registers a closure run against
 * every page at finish() -- see it for why running them at the end
 * rather than as each page closes is what makes "Page 3 of 7" possible
 * at all.
 *
 * Nothing here consults the clock, the filesystem or a random source, so
 * a document built twice from the same inputs is the same bytes twice.
 * That is a property of the whole library (there is deliberately no
 * automatic /CreationDate) and this layer keeps it.
 */
final class Flow
{
    /** @var list<PageBuilder> one per page, in page order */
    private array $pages = [];

    private int $current = -1;

    private float $x;

    private float $y;

    /** @var list<\Closure(self, int, int): void> */
    private array $hooks = [];

    private bool $finished = false;

    private bool $finishing = false;

    private readonly PdfRectangle $mediaBox;

    private readonly Style $defaultStyle;

    public function __construct(
        private readonly Document $document,
        PageSize|PdfRectangle $pageSize = PageSize::Letter,
        private readonly Margins $margins = new Margins(15.0, 15.0, 15.0, 15.0),
        private readonly Unit $unit = Unit::Millimetres,
        private readonly bool $autoPageBreak = true,
        ?Style $defaultStyle = null,
        private readonly MissingGlyphs $missingGlyphs = MissingGlyphs::Refuse,
    ) {
        // Normalized because everything below reads the corners rather
        // than the extent, and a media box written with its corners the
        // other way round -- which §7.9.5 permits and readers accept --
        // would otherwise put the whole layout off the sheet.
        $this->mediaBox = ($pageSize instanceof PageSize ? $pageSize->mediaBox() : $pageSize)->normalized();
        $this->defaultStyle = $defaultStyle ?? new Style();

        $this->x = $margins->left;
        $this->y = $margins->top;

        // So that the per-page hooks run however the document is saved,
        // including through document()->save(). finish() is idempotent
        // and does nothing when there are no hooks, so this costs a
        // closure call on documents that never register one.
        $document->onBeforeSave($this->finish(...));

        // The first page exists from the start rather than appearing on
        // the first draw: pageCount() and content() have to answer
        // something, and "none yet" is a state every caller would have
        // to handle for no benefit.
        $this->newPage();
    }

    // -- Geometry, all in this Flow's unit ------------------------------

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function margins(): Margins
    {
        return $this->margins;
    }

    public function pageWidth(): float
    {
        return $this->unit->fromPoints($this->mediaBox->width());
    }

    public function pageHeight(): float
    {
        return $this->unit->fromPoints($this->mediaBox->height());
    }

    public function contentWidth(): float
    {
        return $this->pageWidth() - $this->margins->left - $this->margins->right;
    }

    public function contentHeight(): float
    {
        return $this->pageHeight() - $this->margins->top - $this->margins->bottom;
    }

    /** The y of the last line a page can hold before its bottom margin. */
    public function bottomLimit(): float
    {
        return $this->pageHeight() - $this->margins->bottom;
    }

    // -- Cursor ---------------------------------------------------------

    public function x(): float
    {
        return $this->x;
    }

    public function y(): float
    {
        return $this->y;
    }

    public function moveTo(float $x, float $y): static
    {
        $this->x = $x;
        $this->y = $y;

        return $this;
    }

    /**
     * Ends the current line: back to the left margin, and $height
     * further down. This is what turns a run of cell() calls into a
     * table row -- the cells advance x, and this closes the row.
     */
    public function newLine(float $height = 0.0): static
    {
        $this->x = $this->margins->left;
        $this->y += $height;

        return $this;
    }

    // -- Flow content ---------------------------------------------------

    /**
     * The atom of a business document: a box, optionally filled,
     * optionally ruled, with text placed inside it. One call for what is
     * otherwise a fill, four rules, a width measurement and a piece of
     * vertical-centring arithmetic.
     *
     * Advances the cursor right by $width, so a row is a run of these
     * followed by newLine(). Breaks to a new page first if the row would
     * cross the bottom margin.
     */
    public function cell(float $width, float $height, string $text = '', ?Style $style = null): static
    {
        $this->breakIfNeeded($height);
        $this->cellAt($this->x, $this->y, $width, $height, $text, $style);
        $this->x += $width;

        return $this;
    }

    /**
     * The same box at an explicit position, leaving the cursor alone and
     * never breaking the page -- for furniture placed against the sheet
     * rather than flowed into it: a footer, a chart's axis labels, a
     * figure caption.
     */
    public function cellAt(
        float $x,
        float $y,
        float $width,
        float $height,
        string $text = '',
        ?Style $style = null,
    ): static {
        $style ??= $this->defaultStyle;

        $this->rect($x, $y, $width, $height, $style->fill, $style->border);

        if ($text !== '') {
            [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($x, $y, $width, $height);

            $this->content()->drawTextInBox(
                $style->font,
                $style->sizePt,
                $xPt + $style->paddingPt,
                $bottomYPt,
                self::inset($widthPt, $style->paddingPt),
                $heightPt,
                $this->drawable($text, $style),
                $style->align,
                $style->valign,
                $style->color,
            );
        }

        return $this;
    }

    /**
     * cellAt() with the text wrapped: a box at an explicit position whose
     * contents run to as many lines as they need, leaving the cursor
     * alone and never breaking the page.
     *
     * This is what a table cell is, and the reason it is a separate call
     * from cellAt() rather than a flag on it: a single-line cell is
     * measured once and a wrapped one is measured per line, and the two
     * agree on where a one-line string lands only because both end up in
     * TextPlacement.
     */
    public function paragraphAt(
        float $x,
        float $y,
        float $width,
        float $height,
        string $text = '',
        ?Style $style = null,
        ?float $lineHeight = null,
    ): static {
        $style ??= $this->defaultStyle;

        $this->rect($x, $y, $width, $height, $style->fill, $style->border);

        if ($text === '') {
            return $this;
        }

        [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($x, $y, $width, $height);

        $this->content()->drawParagraph(
            $style->font,
            $style->sizePt,
            $xPt + $style->paddingPt,
            $bottomYPt,
            self::inset($widthPt, $style->paddingPt),
            $heightPt,
            $this->drawable($text, $style),
            $style->align,
            $style->valign,
            $lineHeight === null ? null : $this->unit->toPoints($lineHeight),
            paint: $style->color,
        );

        return $this;
    }

    /**
     * A table: fixed column widths, cells that wrap, rows that size
     * themselves, and a header that comes back at the top of every page
     * the table runs onto. See Table.
     *
     * @param list<float> $columnWidths in this Flow's unit
     */
    public function table(
        array $columnWidths,
        ?Style $bodyStyle = null,
        ?Style $headerStyle = null,
        float $minRowHeight = 0.0,
        float $verticalPaddingPt = 2.0,
    ): Table {
        return new Table(
            $this,
            $columnWidths,
            $bodyStyle ?? $this->defaultStyle,
            $headerStyle,
            $minRowHeight,
            $verticalPaddingPt,
        );
    }

    /**
     * One line with its baseline at (x, y), for the cases a box does not
     * describe: a value pinned to a rule, a label on a chart axis, a
     * signature line. The style's alignment and vertical alignment do
     * not apply -- the baseline is the placement.
     */
    public function textAt(float $x, float $y, string $text, ?Style $style = null): static
    {
        $style ??= $this->defaultStyle;

        $this->content()->drawText(
            $style->font,
            $style->sizePt,
            $this->toPointsX($x),
            $this->toPointsY($y),
            $this->drawable($text, $style),
            paint: $style->color,
        );

        return $this;
    }

    /**
     * Word-wrapped text in a box $width wide, placed by exactly the same
     * rule as cell() -- both end up in TextPlacement, so a wrapped block
     * and a single-line cell of the same height sit on the same
     * baselines rather than nearly.
     *
     * $height defaults to whatever the text needs (see paragraphHeight())
     * and may be given to fix the box instead, in which case the style's
     * VerticalAlign places the block inside it.
     *
     * Advances the cursor down by the box's height and back to the left
     * margin: a paragraph is a block, not something the next cell
     * continues alongside.
     */
    public function paragraph(
        float $width,
        string $text,
        ?Style $style = null,
        ?float $height = null,
        ?float $lineHeight = null,
    ): static {
        $style ??= $this->defaultStyle;

        // Substituted once, not once per pass: under
        // MissingGlyphs::Substitute drawable() rewrites the whole string,
        // and measuring one rewrite while drawing another is how the two
        // would come to disagree about what is on the page.
        $drawable = $this->drawable($text, $style);
        $height ??= $this->heightOfDrawable($width, $drawable, $style, $lineHeight);

        $this->breakIfNeeded($height);

        $this->rect($this->x, $this->y, $width, $height, $style->fill, $style->border);

        [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($this->x, $this->y, $width, $height);

        // drawParagraph() wraps this again, which TextWrapper answers
        // from the wrap heightOfDrawable() just asked for -- same text,
        // font, size and width, so it is a lookup rather than a second
        // pass. See TextWrapper::wrapUtf8().
        $this->content()->drawParagraph(
            $style->font,
            $style->sizePt,
            $xPt + $style->paddingPt,
            $bottomYPt,
            self::inset($widthPt, $style->paddingPt),
            $heightPt,
            $drawable,
            $style->align,
            $style->valign,
            $lineHeight === null ? null : $this->unit->toPoints($lineHeight),
            paint: $style->color,
        );

        $this->y += $height;
        $this->x = $this->margins->left;

        return $this;
    }

    /**
     * What paragraph() would need for that text, in this Flow's unit --
     * for a caller sizing a box, deciding whether a section fits, or
     * levelling two columns of different lengths.
     *
     * Measured ink to ink, first ascent to last descent, rather than as
     * lines x line height: see TextPlacement::blockHeightPt().
     */
    public function paragraphHeight(
        float $width,
        string $text,
        ?Style $style = null,
        ?float $lineHeight = null,
    ): float {
        $style ??= $this->defaultStyle;

        return $this->heightOfDrawable($width, $this->drawable($text, $style), $style, $lineHeight);
    }

    /**
     * paragraphHeight() once the substitution has already been done, so
     * that paragraph() can measure and draw the same string without
     * rewriting it twice.
     */
    private function heightOfDrawable(
        float $width,
        string $drawable,
        Style $style,
        ?float $lineHeight,
    ): float {
        $lines = TextWrapper::wrapUtf8(
            $drawable,
            $style->font,
            $style->sizePt,
            self::inset($this->unit->toPoints($width), $style->paddingPt),
        );

        return $this->unit->fromPoints(TextPlacement::blockHeightPt(
            $style->font,
            $style->sizePt,
            count($lines),
            $lineHeight === null ? $style->lineHeightPt() : $this->unit->toPoints($lineHeight),
        ));
    }

    /**
     * How wide $text is in this Flow's unit, so a caller can size a
     * column to its widest entry or right-align something by hand.
     *
     * Measures what will actually be drawn rather than what was passed
     * in: under MissingGlyphs::Substitute the two differ, and a width
     * taken from the original would place text by the size of characters
     * that were replaced.
     */
    public function widthOf(string $text, ?Style $style = null): float
    {
        $style ??= $this->defaultStyle;

        return $this->unit->fromPoints(
            $style->font->widthOfPt($this->drawable($text, $style), $style->sizePt),
        );
    }

    /**
     * The width left between the cursor and the right margin -- the
     * "rest of this line", for a cell that should fill it.
     */
    public function remainingWidth(): float
    {
        return $this->pageWidth() - $this->margins->right - $this->x;
    }

    /**
     * $text as this Flow will actually draw it: unchanged unless it was
     * built with MissingGlyphs::Substitute, in which case characters the
     * font has no glyph for are replaced rather than refused. Every
     * drawing and measuring path goes through here, so the two cannot
     * disagree about what is on the page.
     */
    private function drawable(string $text, Style $style): string
    {
        return $this->missingGlyphs === MissingGlyphs::Substitute
            ? GlyphFallback::apply($text, $style->font)
            : $text;
    }

    // -- Primitives, in the same coordinate space -----------------------

    public function line(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        float $widthPt = 0.2,
        ?Paint $color = null,
        ?Dash $dash = null,
    ): static {
        return $this->polyline(
            [[$x1, $y1], [$x2, $y2]],
            new Stroke($color ?? Color::black(), $widthPt, $dash ?? new Dash([])),
        );
    }

    /**
     * A filled and/or ruled rectangle. Both are optional and a call with
     * neither draws nothing at all, which is what lets cell() hand its
     * style straight through without asking whether there is anything to
     * paint.
     */
    public function rect(
        float $x,
        float $y,
        float $width,
        float $height,
        ?Paint $fill = null,
        ?Border $border = null,
    ): static {
        [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($x, $y, $width, $height);

        $whole = $border !== null && $border->top && $border->right && $border->bottom && $border->left;

        // A full box goes out as one path rather than a fill plus four
        // strokes: same marks, a fraction of the operators, and the
        // corners join rather than butting against each other -- visible
        // at the rule weights a heavy table border uses.
        if ($fill !== null || $whole) {
            $this->content()->drawRectangle(
                $xPt,
                $bottomYPt,
                $widthPt,
                $heightPt,
                $fill,
                $whole ? $border->stroke() : null,
            );
        }

        if ($border === null || $border->isEmpty() || $whole) {
            return $this;
        }

        $topYPt = $bottomYPt + $heightPt;
        $rightXPt = $xPt + $widthPt;
        $stroke = $border->stroke();

        foreach ([
            [$border->top, $xPt, $topYPt, $rightXPt, $topYPt],
            [$border->bottom, $xPt, $bottomYPt, $rightXPt, $bottomYPt],
            [$border->left, $xPt, $bottomYPt, $xPt, $topYPt],
            [$border->right, $rightXPt, $bottomYPt, $rightXPt, $topYPt],
        ] as [$enabled, $x1, $y1, $x2, $y2]) {
            if ($enabled) {
                $this->content()->drawPolyline([[$x1, $y1], [$x2, $y2]], $stroke);
            }
        }

        return $this;
    }

    /**
     * A rectangle with rounded corners -- a callout box, a pill, a key.
     * $radius is in this Flow's unit like the rest of the geometry.
     */
    public function roundedRect(
        float $x,
        float $y,
        float $width,
        float $height,
        float $radius,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
    ): static {
        [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($x, $y, $width, $height);

        $this->content()->drawRoundedRectangle(
            $xPt,
            $bottomYPt,
            $widthPt,
            $heightPt,
            $this->unit->toPoints($radius),
            $fill,
            $stroke,
        );

        return $this;
    }

    /** An ellipse centred on ($cx, $cy), with radii in this Flow's unit. */
    public function ellipse(
        float $cx,
        float $cy,
        float $radiusX,
        float $radiusY,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
    ): static {
        $this->content()->drawEllipse(
            $this->toPointsX($cx),
            $this->toPointsY($cy),
            $this->unit->toPoints($radiusX),
            $this->unit->toPoints($radiusY),
            $fill,
            $stroke,
        );

        return $this;
    }

    public function circle(
        float $cx,
        float $cy,
        float $radius,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
    ): static {
        return $this->ellipse($cx, $cy, $radius, $radius, $fill, $stroke);
    }

    /**
     * A closed polygon through $points, each a [x, y] pair in this Flow's
     * coordinates.
     *
     * @param list<array{float, float}> $points
     */
    public function polygon(
        array $points,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
        bool $evenOdd = false,
    ): static {
        $this->content()->drawPolygon($this->pointsToPoints($points), $fill, $stroke, $evenOdd);

        return $this;
    }

    /**
     * The open form: connected segments, stroked and never filled. What a
     * line chart's series is.
     *
     * @param list<array{float, float}> $points
     */
    public function polyline(array $points, ?Stroke $stroke = null): static
    {
        $this->content()->drawPolyline($this->pointsToPoints($points), $stroke ?? Stroke::hairline());

        return $this;
    }

    /**
     * An arbitrary path in this Flow's coordinates -- the curves a chart
     * needs, drawn in millimetres from the top-left like everything else.
     *
     * The closure is handed a PathSink that converts as it goes, so the
     * numbers passed to moveTo()/lineTo()/curveTo() are the same ones
     * every other call here takes:
     *
     * ```php
     * $flow->path(
     *     fn (PathSink $path) => $path->moveTo(20, 100)->curveTo(60, 60, 100, 140, 140, 100),
     *     stroke: new Stroke(Color::fromHex('#2563eb'), 1.2),
     * );
     * ```
     *
     * @param \Closure(PathSink): mixed $path
     */
    public function path(
        \Closure $path,
        ?Paint $fill = null,
        ?Stroke $stroke = null,
        bool $evenOdd = false,
    ): static {
        $this->content()->drawPath(
            fn (PathSink $sink) => $path(new UnitPathSink($sink, $this)),
            $fill,
            $stroke,
            $evenOdd,
        );

        return $this;
    }

    // -- Scoped graphics state ------------------------------------------

    /**
     * Draws everything the closure draws turned by $degrees about
     * ($x, $y), then puts the page back as it was.
     *
     * **Positive turns clockwise**, which is the opposite of the content
     * layer's convention and deliberate for the same reason the Y axis
     * is: this layer measures down from the top-left the way a screen
     * does, and in that space a positive angle turns clockwise -- as it
     * does in CSS and SVG. So a footer rotated -90 reads bottom-to-top up
     * the left edge of the sheet.
     *
     * @param \Closure(self): mixed $draw
     */
    public function rotated(float $degrees, float $x, float $y, \Closure $draw): static
    {
        $this->content()->rotated(
            -$degrees,
            $this->toPointsX($x),
            $this->toPointsY($y),
            function () use ($draw): void {
                $draw($this);
            },
        );

        return $this;
    }

    /**
     * Draws at less than full opacity -- a watermark, a tint panel, a
     * greyed-out row. $alpha runs 0.0 (invisible) to 1.0 (opaque).
     *
     * @param \Closure(self): mixed $draw
     */
    public function faded(float $alpha, \Closure $draw, ?float $strokeAlpha = null): static
    {
        $this->content()->faded(
            $alpha,
            function () use ($draw): void {
                $draw($this);
            },
            $strokeAlpha,
        );

        return $this;
    }

    /**
     * Draws with everything outside the box clipped away -- for content
     * that must not escape a panel, such as a long label in a fixed
     * column or an oversized image in a frame.
     *
     * @param \Closure(self): mixed $draw
     */
    public function clippedToBox(
        float $x,
        float $y,
        float $width,
        float $height,
        \Closure $draw,
    ): static {
        [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($x, $y, $width, $height);

        $this->content()->clippedToRectangle(
            $xPt,
            $bottomYPt,
            $widthPt,
            $heightPt,
            function () use ($draw): void {
                $draw($this);
            },
        );

        return $this;
    }

    /**
     * One line of text turned about its own baseline origin, in the same
     * clockwise-positive sense as rotated(): -90 is the bottom-to-top run
     * a chart's Y-axis label takes.
     */
    public function rotatedTextAt(
        float $x,
        float $y,
        float $degrees,
        string $text,
        ?Style $style = null,
    ): static {
        $style ??= $this->defaultStyle;

        $this->content()->drawTextRotated(
            $style->font,
            $style->sizePt,
            $this->toPointsX($x),
            $this->toPointsY($y),
            -$degrees,
            $this->drawable($text, $style),
            $style->color,
        );

        return $this;
    }

    /**
     * @param list<array{float, float}> $points
     *
     * @return list<array{float, float}>
     */
    private function pointsToPoints(array $points): array
    {
        return array_map(
            fn (array $point): array => [$this->toPointsX($point[0]), $this->toPointsY($point[1])],
            $points,
        );
    }

    /**
     * A linear barcode in a box, in this Flow's coordinates.
     *
     * **The default here is Code 128**, not the Code 39 that
     * PageBuilder::drawBarcode() defaults to for compatibility. Code 128
     * carries the whole of ASCII in two-thirds the width and packs digits
     * two to a symbol, so it is what new code should be printing; the
     * older default is kept only where it already was.
     *
     * The quiet zone is on by default too, for the reason it is on for QR
     * codes: a barcode flowed into a document sits against other content
     * rather than in a label's white space, and a symbol with no clear
     * border does not scan.
     */
    public function barcode(
        string $value,
        float $x,
        float $y,
        float $width,
        float $height,
        Symbology|string $symbology = Symbology::Code128,
        bool $quietZone = true,
        ?Paint $paint = null,
    ): static {
        [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($x, $y, $width, $height);

        $this->content()->drawBarcode(
            $value,
            $xPt,
            $bottomYPt,
            $widthPt,
            $heightPt,
            $symbology,
            $quietZone,
            $paint,
        );

        return $this;
    }

    /**
     * A QR code, square, with ($x, $y) its top-left corner in this Flow's
     * coordinates and $size its whole side including the quiet zone.
     */
    public function qrCode(
        string $value,
        float $x,
        float $y,
        float $size,
        QrEccLevel $level = QrEccLevel::Medium,
        bool $quietZone = true,
        int $minVersion = 1,
        ?Paint $paint = null,
    ): static {
        [$xPt, $bottomYPt, $sizePt] = $this->boxToPoints($x, $y, $size, $size);

        $this->content()->drawQrCode($value, $xPt, $bottomYPt, $sizePt, $level, $quietZone, $minVersion, $paint);

        return $this;
    }

    public function image(string $path, float $x, float $y, float $width, float $height): static
    {
        [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($x, $y, $width, $height);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $content = $this->content();

        match ($extension) {
            'jpg', 'jpeg' => $content->drawJpeg($path, $xPt, $bottomYPt, $widthPt, $heightPt),
            'png' => $content->drawPng($path, $xPt, $bottomYPt, $widthPt, $heightPt),
            'gif' => $content->drawGif($path, $xPt, $bottomYPt, $widthPt, $heightPt),
            'svg' => $content->drawSvg($path, $xPt, $bottomYPt, $widthPt, $heightPt),
            default => throw new \InvalidArgumentException(
                "Cannot tell the format of \"$path\" from its extension -- expected .jpg, .png, .gif or .svg. "
                . 'Call content()->drawJpeg() and friends directly to say which it is.',
            ),
        };

        return $this;
    }

    // -- Pages ----------------------------------------------------------

    public function newPage(): static
    {
        $page = $this->document->newPage($this->mediaBox);

        $this->pages[] = new PageBuilder($this->document, $page);
        $this->current = count($this->pages) - 1;
        $this->x = $this->margins->left;
        $this->y = $this->margins->top;

        return $this;
    }

    /** 1-based, like the number a reader shows. */
    public function pageNumber(): int
    {
        return $this->current + 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function willFit(float $height): bool
    {
        return $this->y + $height <= $this->bottomLimit();
    }

    /**
     * Starts a new page if $height would not fit on this one -- what
     * cell() and paragraph() call, exposed because a caller drawing
     * something this layer knows nothing about (a chart, a signature
     * block) needs to take part in the same decision.
     *
     * Does nothing at the top of a page, so an element taller than the
     * body overflows one page instead of breaking forever.
     */
    public function breakIfNeeded(float $height): static
    {
        if ($this->autoPageBreak && !$this->willFit($height) && $this->y > $this->margins->top) {
            $this->newPage();
        }

        return $this;
    }

    /**
     * Registers a closure drawn onto every page of the finished
     * document, as `fn (Flow $flow, int $pageNumber, int $pageCount)`.
     * Register more than one and they run in registration order, per
     * page, in page order.
     *
     * This is the guarantee that a legal disclaimer, a page number or a
     * confidentiality notice is on *every* page, including pages an
     * automatic break added in the middle of a table. The alternative --
     * every drawing routine remembering to place one -- is a rule that
     * holds until someone adds a routine, which is to say it is not a
     * guarantee.
     *
     * They run at finish(), not as each page is closed, and that is what
     * makes "Page 3 of 7" work: the total is not known while page three
     * is being drawn. FPDF substitutes a placeholder string afterwards
     * and TCPDF rewrites the page, both of which are workarounds for
     * being a streaming writer. MightyPDF appends to any page's content
     * stream right up until save(), so waiting costs nothing and the
     * count is simply true.
     *
     * The closure is handed this same Flow, repositioned onto the page
     * in question with its cursor at the top margin, so a footer is
     * written in millimetres like everything else. The cursor and
     * current page are restored afterwards.
     *
     * @param \Closure(self, int, int): void $decorate
     */
    public function onEachPage(\Closure $decorate): static
    {
        $this->hooks[] = $decorate;

        return $this;
    }

    // -- Escape hatches to the content layer ----------------------------

    /** The PageBuilder for the page being drawn on. */
    public function content(): PageBuilder
    {
        return $this->pages[$this->current];
    }

    /**
     * The Document being built, for the things that are its business
     * rather than this layer's: metadata, bookmarks, encryption, form
     * fields.
     *
     * Saving through it is safe. The per-page hooks are registered with
     * the Document itself (see the constructor), so
     * `$flow->document()->save()` decorates the pages exactly as
     * `$flow->save()` does.
     */
    public function document(): Document
    {
        return $this->document;
    }

    public function toPointsX(float $x): float
    {
        return $this->mediaBox->x1 + $this->unit->toPoints($x);
    }

    /** Where the flip from top-left/Y-down to PDF's bottom-left/Y-up happens. */
    public function toPointsY(float $y): float
    {
        return $this->mediaBox->y2 - $this->unit->toPoints($y);
    }

    // -- Output ---------------------------------------------------------

    /**
     * Runs every per-page hook and hands back the document. Idempotent:
     * calling it again, or calling save() after it, does not draw a
     * second set of footers.
     */
    public function finish(): Document
    {
        if ($this->finished || $this->finishing) {
            return $this->document;
        }

        // Two flags rather than one. $finishing stops the re-entry that
        // a hook calling save() -- or document()->save(), now that
        // saving is what runs these -- would otherwise cause.
        // $finished is set only once the hooks have all returned, so a
        // hook that throws leaves this resumable: the alternative marks
        // the document decorated when it is half-decorated, and the
        // caller's retry silently produces the file it was trying to
        // avoid.
        $this->finishing = true;

        $total = count($this->pages);
        $page = $this->current;
        $x = $this->x;
        $y = $this->y;

        try {
            foreach (array_keys($this->pages) as $index) {
                $this->current = $index;

                foreach ($this->hooks as $hook) {
                    $this->x = $this->margins->left;
                    $this->y = $this->margins->top;

                    $hook($this, $index + 1, $total);
                }
            }
        } finally {
            $this->finishing = false;
            $this->current = $page;
            $this->x = $x;
            $this->y = $y;
        }

        // A hook that adds a page would leave that page undecorated and
        // every "of N" already drawn wrong, and neither shows up until
        // someone reads the last page of a long document. Refused rather
        // than half-done -- and refused before the document is marked
        // finished, so that catching this and saving anyway throws again
        // rather than quietly handing back the file it was refusing.
        if (count($this->pages) !== $total) {
            throw new \LogicException(
                'A per-page hook added a page. Hooks draw onto the pages that already exist -- '
                . 'they run once the page count is final, which is what lets them print it.',
            );
        }

        $this->finished = true;

        return $this->document;
    }

    public function save(): string
    {
        return $this->finish()->save();
    }

    public function saveToFile(string $path): void
    {
        $this->finish()->saveToFile($path);
    }

    /**
     * A box width less the padding on both sides, floored at zero: a
     * cell narrower than its own padding would otherwise ask for text in
     * a negative-width box, which right-aligns to the left of where it
     * started rather than doing nothing visible.
     */
    private static function inset(float $widthPt, float $paddingPt): float
    {
        return max(0.0, $widthPt - 2 * $paddingPt);
    }

    /**
     * A (x, y, width, height) box in this Flow's top-left space as the
     * (x, bottom y, width, height) the content layer draws in.
     *
     * @return array{float, float, float, float}
     */
    private function boxToPoints(float $x, float $y, float $width, float $height): array
    {
        return [
            $this->toPointsX($x),
            $this->toPointsY($y + $height),
            $this->unit->toPoints($width),
            $this->unit->toPoints($height),
        ];
    }
}
