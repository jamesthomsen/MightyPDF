<?php

declare(strict_types=1);

namespace MightyPDF\Layout;

use MightyPDF\Assembler\Destination;
use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Page;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Assembler\Structure\StructureElement;
use MightyPDF\Assembler\Structure\StructureRole;
use MightyPDF\Assembler\Structure\StructureTree;
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
 * **Page breaks.** cell(), paragraph() and write() start a new page when
 * what they are about to draw would cross the bottom margin. An element
 * taller than the page body is drawn anyway rather than looping: it
 * overflows visibly, which is a bug a person can see, where a silent
 * infinite break is one they cannot. onPageBreak() takes the decision
 * over, which is how a page gets more than one column.
 *
 * **Page sizes.** Pages need not all be the same one: newPage() takes a
 * size, and every measurement here -- pageWidth(), contentWidth(),
 * bottomLimit(), the conversion to points -- answers for the page being
 * drawn on. A portrait report with one landscape table in it is a
 * document, not two.
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

    /**
     * The media box of each page, parallel to $pages, because pages need
     * not all be the same size and every coordinate here is measured
     * from the corner of the sheet being drawn on.
     *
     * @var list<PdfRectangle>
     */
    private array $mediaBoxes = [];

    /**
     * The Page objects behind $pages, parallel to it.
     *
     * PageBuilder keeps its PageContext to itself, and setBleed() has to
     * reach the page itself to declare boxes on it -- including on pages
     * that do not exist yet when it is called.
     *
     * @var list<Page>
     */
    private array $pageObjects = [];

    /**
     * Bleed in points, or null where this Flow was never told about any.
     * Kept so that newPage() can declare the boxes on each page as it is
     * made, rather than only on the pages that existed when setBleed()
     * was called.
     */
    private ?float $bleed = null;

    private int $current = -1;

    private float $x;

    private float $y;

    /** @var list<\Closure(self, int, int): void> */
    private array $hooks = [];

    private bool $finished = false;

    private bool $finishing = false;

    /** The structure tree, once tagged() has turned tagging on. */
    private ?StructureTree $structure = null;

    /** The element new content attaches to -- a section, or the document. */
    private ?StructureElement $element = null;

    /**
     * An element named by tag(), which content uses in place of the one
     * the layout would have inferred.
     */
    private ?StructureElement $forced = null;

    /**
     * Whether the drawing happening now is an artifact.
     *
     * Content inside an artifact must not also be tagged: it is either
     * part of the document or it is furniture, and a page number that is
     * both is one a reader announces in the middle of a sentence *and*
     * a checker reports as nested marked content.
     */
    private bool $inArtifact = false;

    /** @var null|\Closure(self, float): bool */
    private ?\Closure $onPageBreak = null;

    private bool $deciding = false;

    private readonly PdfRectangle $defaultMediaBox;

    /**
     * The margins the per-page hooks are drawn against (see finish()).
     * Not readonly only because setBleed() moves it in step with
     * $margins: page furniture measured from the sheet edge on a job
     * with bleed is furniture 3mm out of position on every page.
     */
    private Margins $defaultMargins;

    private readonly Style $defaultStyle;

    public function __construct(
        private readonly Document $document,
        PageSize|PdfRectangle $pageSize = PageSize::Letter,
        private Margins $margins = new Margins(15.0, 15.0, 15.0, 15.0),
        private readonly Unit $unit = Unit::Millimetres,
        private readonly bool $autoPageBreak = true,
        ?Style $defaultStyle = null,
        private readonly MissingGlyphs $missingGlyphs = MissingGlyphs::Refuse,
    ) {
        // Normalized because everything below reads the corners rather
        // than the extent, and a media box written with its corners the
        // other way round -- which §7.9.5 permits and readers accept --
        // would otherwise put the whole layout off the sheet.
        $this->defaultMediaBox = self::boxOf($pageSize);
        $this->defaultMargins = $margins;
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

    /**
     * Moves the edges everything after this is laid out against: where a
     * line starts and ends, what contentWidth() is, and how far down a
     * page runs before it breaks.
     *
     * Cursor state rather than configuration, which is why it is settable
     * at all when Style and Margins themselves are values -- a column is
     * a left edge and a right edge, and moving them is how a Flow is
     * pointed at one:
     *
     * ```php
     * $flow->setMargins($flow->margins()->with(left: 110.0));
     * ```
     *
     * Nothing already drawn moves, and the cursor stays where it is: this
     * says where the *next* line begins, and the caller that wants to
     * start there says so with moveTo() or newLine().
     */
    public function setMargins(Margins $margins): static
    {
        $this->margins = $margins;

        return $this;
    }

    /**
     * Declares that this document is going to a press, and that $bleed of
     * every sheet -- in this Flow's unit -- is bleed rather than finished
     * page.
     *
     * Every page gets a trim box that much inside its media box and a
     * bleed box of the whole sheet (see Assembler\Page::setBleed()), the
     * pages already made and the ones still to come. The sheets have to
     * be big enough to hold it, which is what PageSize::withBleed() is
     * for:
     *
     * ```php
     * $flow = new Flow($document, PageSize::A4->withBleed(Unit::Millimetres->toPoints(3.0)));
     * $flow->setBleed(3.0);   // millimetres, this Flow's unit
     * ```
     *
     * Margins move in by the same amount, which is the part that makes
     * this worth having at this layer: a page origin sits at the corner
     * of the *sheet*, so without the shift a 15mm margin measured from
     * there is 12mm from the finished edge, and every page of the job is
     * 3mm out. After this, margins mean what a designer means by them --
     * a distance from the cut.
     *
     * A document has one bleed, so this is settable once. Artwork that
     * runs off the edge is drawn at negative coordinates, or from
     * -$bleed, like anything else outside the margins.
     */
    public function setBleed(float $bleed): static
    {
        if ($this->bleed !== null) {
            throw new \LogicException(
                'This Flow already has a bleed. It is one number for the whole job -- '
                . 'the press trims every sheet the same -- so setting a second one would '
                . 'silently move the margins of the pages already laid out.',
            );
        }

        if ($bleed < 0.0) {
            throw new \InvalidArgumentException("Bleed is a margin outside the finished page, so it cannot be negative -- got $bleed.");
        }

        $points = $this->unit->toPoints($bleed);

        // The pages first: a sheet too small to trim throws, and it has to
        // throw before any of this Flow's own state has moved.
        foreach ($this->pageObjects as $page) {
            $page->setBleed($points);
        }

        $this->bleed = $points;
        $this->margins = self::insetBy($this->margins, $bleed);
        $this->defaultMargins = self::insetBy($this->defaultMargins, $bleed);

        // The cursor is sitting against the old margin -- on the first
        // page, which is the only one that can exist before anything is
        // drawn. Moving it is what makes the shift take effect for the
        // page already open rather than only from the next one.
        $this->x = $this->margins->left;
        $this->y = $this->margins->top;

        return $this;
    }

    private static function insetBy(Margins $margins, float $amount): Margins
    {
        return new Margins(
            $margins->top + $amount,
            $margins->right + $amount,
            $margins->bottom + $amount,
            $margins->left + $amount,
        );
    }

    /**
     * The media box of the page being drawn on, which is not necessarily
     * the one this Flow was built with -- see newPage().
     */
    private function mediaBox(): PdfRectangle
    {
        return $this->mediaBoxes[$this->current];
    }

    /**
     * Whatever was given as a page size, normalized. Everything below
     * reads the corners rather than the extent, and a media box written
     * with its corners the other way round -- which §7.9.5 permits and
     * readers accept -- would otherwise put the whole layout off the
     * sheet.
     */
    private static function boxOf(PageSize|PdfRectangle $pageSize): PdfRectangle
    {
        return ($pageSize instanceof PageSize ? $pageSize->mediaBox() : $pageSize)->normalized();
    }

    /** Of the page being drawn on. */
    public function pageWidth(): float
    {
        return $this->unit->fromPoints($this->mediaBox()->width());
    }

    /** Of the page being drawn on. */
    public function pageHeight(): float
    {
        return $this->unit->fromPoints($this->mediaBox()->height());
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
     *
     * $link and $destination make the whole box clickable, which is the
     * shape a link in a table or a list of references actually has: the
     * target is the row, not the eleven characters of blue text in it.
     */
    public function cell(
        float $width,
        float $height,
        string $text = '',
        ?Style $style = null,
        ?string $link = null,
        ?Destination $destination = null,
    ): static {
        $this->breakIfNeeded($height);
        $this->cellAt($this->x, $this->y, $width, $height, $text, $style, $link, $destination);
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
        ?string $link = null,
        ?Destination $destination = null,
    ): static {
        $style ??= $this->defaultStyle;

        $this->rect($x, $y, $width, $height, $style->fill, $style->border);

        if ($link !== null) {
            $this->link($x, $y, $width, $height, $link);
        }

        if ($destination !== null) {
            $this->linkTo($x, $y, $width, $height, $destination);
        }

        if ($text !== '') {
            [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($x, $y, $width, $height);

            $this->tagText(StructureRole::Paragraph, $text, fn () => $this->content()->drawTextInBox(
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
            ));
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

        $this->tagText(StructureRole::Paragraph, $text, fn () => $this->content()->drawParagraph(
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
        ));

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

        $this->tagText(StructureRole::Paragraph, $text, fn () => $this->content()->drawText(
            $style->font,
            $style->sizePt,
            $this->toPointsX($x),
            $this->toPointsY($y),
            $this->drawable($text, $style),
            paint: $style->color,
        ));

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
        $this->tagText(StructureRole::Paragraph, $drawable, fn () => $this->content()->drawParagraph(
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
        ));

        $this->y += $height;
        $this->x = $this->margins->left;

        return $this;
    }

    /**
     * Text that starts where the cursor is and runs on between the
     * margins, leaving the cursor at the end of the last line rather than
     * on the next one. Chaining is the point:
     *
     * ```php
     * $flow->write('Signed under the terms of the ')
     *      ->write('licence agreement', $blue, link: 'https://example.com/licence')
     *      ->write(', which is incorporated by reference.')
     *      ->newLine(6.0);
     * ```
     *
     * That is what paragraph() cannot do. A paragraph is a block: it
     * takes a width, starts a line of its own and ends one, so a phrase
     * in the middle of a sentence in a second font or a second colour has
     * to be placed by hand against measured widths. A run is the other
     * half of the pair, and between them there is no need for an inline
     * layout engine.
     *
     * A run is not a box, so the style's fill, border, padding and
     * horizontal alignment do not apply -- there is nothing to align a
     * fragment of a line within, and a background belongs to the block
     * that holds the run rather than to the run. Its font, size, colour
     * and vertical alignment do apply, and the last of those is what
     * makes a run and a cell() of the same height sit on one baseline.
     *
     * $link puts the text behind a URI and $destination behind a place in
     * this document; a run that wraps gets one rectangle per line, so a
     * link broken across a line break is clickable on both.
     *
     * Breaks the page between lines like everything else here. The wrap
     * is measured once, against the page the run starts on, which holds
     * because an automatic break continues at the same size.
     */
    public function write(
        string $text,
        ?Style $style = null,
        ?float $lineHeight = null,
        ?string $link = null,
        ?Destination $destination = null,
    ): static {
        $style ??= $this->defaultStyle;

        $drawable = $this->drawable($text, $style);
        $height = $lineHeight ?? $this->unit->fromPoints($style->lineHeightPt());

        // Measured in points and converted where the cursor is moved,
        // rather than the other way round: TextWrapper works in the
        // font's own units, and a wrap that agreed with paragraph()'s
        // only after two conversions would be a wrap that eventually
        // did not.
        $widthOfPt = fn (string $run): float => $style->font->widthOfPt($run, $style->sizePt);
        $spaceWidth = $this->unit->fromPoints($widthOfPt(' '));

        // preg_split() collapses runs of spaces and drops them at the
        // ends of a line, which is what wrapping a paragraph wants and
        // the opposite of what two runs want: write('Visit ') followed by
        // write('the site') is one sentence, and the space between them
        // is the caller's, not the wrapper's. So the ends are held back
        // and put in as width.
        $body = trim($drawable, ' ');

        if ($body === '') {
            $this->x += strlen($drawable) * $spaceWidth;

            return $this;
        }

        // A leading space at the start of a line is dropped rather than
        // indented past: that is a line beginning with a space, which no
        // wrap produces and no reader expects.
        if ($this->x > $this->margins->left) {
            $this->x += strspn($drawable, ' ') * $spaceWidth;
        }

        $trailing = strspn(strrev($drawable), ' ');

        $this->breakIfNeeded($height);

        $lines = TextWrapper::wrapRagged(
            $body,
            $widthOfPt,
            max(0.0, $this->unit->toPoints($this->remainingWidth())),
            $this->unit->toPoints($this->contentWidth()),
        );

        $last = count($lines) - 1;

        // One element for the whole run, not one per line. A run that
        // wraps is still one phrase, and a page break in the middle of it
        // is handled by attaching a second marked-content sequence to the
        // same element rather than by starting another -- which is what
        // StructureElement::addMarkedContent() is doing when it writes an
        // /MCR instead of a bare id.
        $tagging = $this->structure !== null && !$this->inArtifact;
        $element = null;

        foreach ($lines as $index => $line) {
            if ($line !== '') {
                $width = $this->unit->fromPoints($widthOfPt($line));

                $draw = fn () => $this->content()->drawText(
                    $style->font,
                    $style->sizePt,
                    $this->toPointsX($this->x),
                    TextPlacement::baselineY(
                        $style->font,
                        $style->sizePt,
                        $this->toPointsY($this->y + $height),
                        $this->unit->toPoints($height),
                        $style->valign,
                    ),
                    $line,
                    paint: $style->color,
                );

                // Created on the first line that has something on it, so
                // write('') leaves no element behind.
                if ($tagging) {
                    $element ??= $this->forced ?? ($this->element ?? $this->tagged())->child(StructureRole::Span);
                }

                if ($element === null) {
                    $draw();
                } else {
                    $this->content()->tagged($element, static function () use ($draw): void {
                        $draw();
                    });
                }

                if ($link !== null) {
                    $this->link($this->x, $this->y, $width, $height, $link);
                }

                if ($destination !== null) {
                    $this->linkTo($this->x, $this->y, $width, $height, $destination);
                }

                $this->x += $width;
            }

            if ($index !== $last) {
                $this->newLine($height);
                $this->breakIfNeeded($height);
            }
        }

        $this->x += $trailing * $spaceWidth;

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

    // -- Links ----------------------------------------------------------

    /**
     * A rectangle of the page that opens $uri when it is clicked, in this
     * Flow's coordinates. It draws nothing: the blue underline that makes
     * a link look like one is the caller's, which is what lets a link
     * cover an image, a table cell or a whole panel just as easily.
     *
     * The content layer has had this all along (PageBuilder::addLink) and
     * still does; the difference is only that this one takes millimetres
     * from the top-left like the rest of the layout layer, rather than
     * points from the bottom-left.
     */
    public function link(float $x, float $y, float $width, float $height, string $uri): static
    {
        [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($x, $y, $width, $height);

        $this->content()->addLink($xPt, $bottomYPt, $widthPt, $heightPt, $uri);

        return $this;
    }

    /**
     * The same rectangle going somewhere in this document rather than out
     * of it -- a table of contents, a footnote, a "back to the summary".
     */
    public function linkTo(
        float $x,
        float $y,
        float $width,
        float $height,
        Destination $destination,
    ): static {
        [$xPt, $bottomYPt, $widthPt, $heightPt] = $this->boxToPoints($x, $y, $width, $height);

        $this->content()->addInternalLink($xPt, $bottomYPt, $widthPt, $heightPt, $destination);

        return $this;
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
            $this->tagShape(fn () => $this->content()->drawRectangle(
                $xPt,
                $bottomYPt,
                $widthPt,
                $heightPt,
                $fill,
                $whole ? $border->stroke() : null,
            ));
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
                $this->tagShape(fn () => $this->content()->drawPolyline([[$x1, $y1], [$x2, $y2]], $stroke));
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

        $this->tagShape(fn () => $this->content()->drawRoundedRectangle(
            $xPt,
            $bottomYPt,
            $widthPt,
            $heightPt,
            $this->unit->toPoints($radius),
            $fill,
            $stroke,
        ));

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
        $this->tagShape(fn () => $this->content()->drawEllipse(
            $this->toPointsX($cx),
            $this->toPointsY($cy),
            $this->unit->toPoints($radiusX),
            $this->unit->toPoints($radiusY),
            $fill,
            $stroke,
        ));

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
        $this->tagShape(fn () => $this->content()->drawPolygon($this->pointsToPoints($points), $fill, $stroke, $evenOdd));

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
        $this->tagShape(fn () => $this->content()->drawPolyline($this->pointsToPoints($points), $stroke ?? Stroke::hairline()));

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
        $this->tagShape(fn () => $this->content()->drawPath(
            fn (PathSink $sink) => $path(new UnitPathSink($sink, $this)),
            $fill,
            $stroke,
            $evenOdd,
        ));

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

    /**
     * A new page, at the end of the document, with the cursor back at the
     * top-left of the content area.
     *
     * $pageSize makes it a different size from the rest -- the landscape
     * sheet a wide table goes on, an A5 insert, a card. Every coordinate
     * here is measured against the page being drawn on, so pageWidth(),
     * contentWidth(), bottomLimit() and toPointsY() all answer for that
     * page from the moment it starts, and margins hold against its edges
     * rather than the first page's:
     *
     * ```php
     * $flow->newPage(PageSize::A4->landscape());
     * $flow->table([90.0, 90.0, 87.0])->row([...]);   // 267mm of content
     * $flow->newPage();                               // back to A4 upright
     * ```
     *
     * Left out, the page is the size this Flow was built with rather than
     * the size of the page just finished: newPage() is a deliberate act,
     * and the document's own default is the predictable thing for it to
     * mean. An *automatic* break goes the other way and continues at the
     * current size, because a table that breaks mid-run was measured
     * against the page it started on -- see breakIfNeeded().
     */
    public function newPage(PageSize|PdfRectangle|null $pageSize = null): static
    {
        $mediaBox = $pageSize === null ? $this->defaultMediaBox : self::boxOf($pageSize);

        $page = $this->document->newPage($mediaBox);

        // Before the page is drawn on rather than at finish(): setBleed()
        // refuses a bleed the sheet cannot hold, and the caller wants to
        // hear that at the newPage() that caused it, not at save().
        if ($this->bleed !== null) {
            $page->setBleed($this->bleed);
        }

        $this->pages[] = new PageBuilder($this->document, $page);
        $this->pageObjects[] = $page;
        $this->mediaBoxes[] = $mediaBox;
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
     *
     * The new page is the size of the one being left rather than this
     * Flow's default: a run of rows that started on a landscape sheet was
     * measured against its width, and continuing it on a narrower page
     * would overflow columns that were correct when they were sized.
     * newPage() with no argument is the deliberate way back.
     *
     * Does nothing while the per-page hooks run either. A footer sits
     * below the bottom margin by definition, so cell() inside a hook
     * asks to break every time -- and a hook that adds a page is refused
     * by finish(), which turned a footer written in the flow-relative
     * calls rather than the absolute ones into a document that threw at
     * save(). Suppressed rather than refused for the same reason
     * decideBreak() suppresses it: the answer is already known. Hooks
     * run once the page count is final, which is what lets them print
     * it, so there is no page for this to break onto. An explicit
     * newPage() from a hook still adds one and finish() still refuses
     * it -- that is the mistake the check downstairs is there to catch.
     */
    public function breakIfNeeded(float $height): static
    {
        if (!$this->autoPageBreak || $this->finishing || $this->willFit($height) || $this->y <= $this->margins->top) {
            return $this;
        }

        if ($this->onPageBreak !== null && !$this->decideBreak($height)) {
            return $this;
        }

        return $this->newPage($this->mediaBox());
    }

    /**
     * Asks the onPageBreak() closure whether to go ahead, with automatic
     * breaks suppressed while it runs.
     *
     * A closure that moves the cursor is the point of the thing, and
     * moving it is done by drawing calls as often as by moveTo() -- a
     * column header, a rule. Those call back in here, and without the
     * guard a closure that draws near the bottom of the page asks itself
     * whether to break, forever. Suppressed rather than refused because
     * the answer is already known: this *is* the break being decided.
     */
    private function decideBreak(float $height): bool
    {
        if ($this->deciding) {
            return false;
        }

        $this->deciding = true;

        try {
            return ($this->onPageBreak)($this, $height) !== false;
        } finally {
            $this->deciding = false;
        }
    }

    /**
     * Takes over the decision breakIfNeeded() makes: the closure is
     * handed this Flow and the height that would not fit, and returns
     * true to let the page break or false to say it has dealt with it
     * itself.
     *
     * Which is what a multi-column page is. There is no column object
     * here -- a column is a left edge and a right edge, which is to say
     * it is a pair of margins, and moving them is the whole of it:
     *
     * ```php
     * $column = 0;
     *
     * $flow->onPageBreak(function (Flow $flow) use (&$column): bool {
     *     $column = 1 - $column;
     *     $left = $column === 0 ? 15.0 : 110.0;
     *
     *     $flow->setMargins($flow->margins()->with(left: $left));
     *
     *     if ($column === 0) {
     *         return true;              // second column full: turn the page
     *     }
     *
     *     $flow->moveTo($left, $flow->margins()->top);
     *
     *     return false;                 // first column full: move across
     * });
     * ```
     *
     * The margins are what make the rest of it follow: newLine() returns
     * to the column's left edge rather than the page's, wrapping stops at
     * its right, and contentWidth() is the column's. A closure that only
     * moves the cursor gets one correct line and then drifts back to the
     * page margin, which is the trap this note exists to close.
     *
     * Note what the closure does *not* have to do: a page that breaks
     * normally resets the cursor itself, so the hook only positions for
     * the cases it handles. Pass null to go back to breaking every time.
     *
     * This governs automatic breaks only. newPage() is a direct
     * instruction and is never put to the closure -- including the
     * closure's own call to it, which would otherwise be a loop.
     *
     * @param null|\Closure(self, float): bool $decide
     */
    public function onPageBreak(?\Closure $decide): static
    {
        $this->onPageBreak = $decide;

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
     * written in millimetres like everything else. The cursor, the
     * current page and the margins are restored afterwards.
     *
     * The margins hooks see are the ones this Flow was built with rather
     * than whatever the last page left in place, since a footer
     * describes the page: see finish(). Everything else is the page's
     * own -- pageWidth() and pageHeight() answer for the page being
     * decorated, so one expression centres a page number on an upright
     * sheet and a landscape one both.
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
    // -- Tagging ---------------------------------------------------------
    //
    // This is where a tagged PDF gets cheap. Tagging a document built out
    // of raw drawing calls means the caller restating, element by element,
    // what everything is -- because a canvas genuinely does not know. A
    // Flow does: paragraph() is a paragraph, a Table's cells are cells,
    // and onEachPage() furniture is page furniture. So turning tagging on
    // here tags the document, and the caller only says the things the
    // layout cannot infer: which paragraphs are headings, what a figure
    // depicts, where a section begins.

    /**
     * Turns tagging on and returns the document's root element.
     *
     * From here every paragraph(), cell() and write() attaches itself to
     * the structure, and everything drawn through onEachPage() becomes an
     * artifact. Nothing else has to change.
     *
     * @param string|null $language a BCP 47 tag ("en-GB"). Worth passing:
     *        it is what tells a screen reader which voice to read in, and
     *        a checker reports its absence.
     */
    public function tagged(?string $language = null): StructureElement
    {
        if ($language !== null) {
            $this->document->setLanguage($language);
        }

        $this->structure ??= $this->document->structure();

        return $this->element ??= $this->structure->document();
    }

    /**
     * Runs $body with a new grouping element open -- a section, a list, a
     * table of contents -- so everything drawn inside it belongs to it.
     *
     * ```php
     * $flow->inside(StructureRole::Section, function (Flow $flow) {
     *     $flow->tag(StructureRole::Heading1, fn ($f) => $f->paragraph(180, 'Results', $h1));
     *     $flow->paragraph(180, 'Revenue rose...', $body);
     * });
     * ```
     */
    public function inside(StructureRole $role, \Closure $body): static
    {
        if ($this->structure === null) {
            $body($this);

            return $this;
        }

        $parent = $this->element ?? $this->tagged();
        $this->element = $parent->child($role);

        try {
            $body($this);
        } finally {
            // Restored in a finally so a throw does not leave every later
            // paragraph nested inside a section that was abandoned.
            $this->element = $parent;
        }

        return $this;
    }

    /**
     * Draws with one element of the given role, for the things the layout
     * cannot infer: which paragraph is a heading, what is a caption.
     *
     * Everything drawn inside belongs to that one element, so a heading
     * that takes two calls is still one heading.
     */
    public function tag(StructureRole $role, \Closure $draw): static
    {
        if ($this->structure === null) {
            $draw($this);

            return $this;
        }

        $parent = $this->element ?? $this->tagged();
        $previous = $this->forced;
        $this->forced = $parent->child($role);

        try {
            $draw($this);
        } finally {
            $this->forced = $previous;
        }

        return $this;
    }

    /**
     * Draws something that is not part of the document's content: a
     * running header, a page number, a rule.
     *
     * onEachPage() furniture goes through this automatically, which is
     * the case that matters -- page numbers read aloud in the middle of a
     * sentence are the classic failure of a document tagged by hand.
     */
    public function artifact(\Closure $draw): static
    {
        if ($this->structure === null) {
            $draw($this);

            return $this;
        }

        $previous = $this->inArtifact;
        $this->inArtifact = true;

        try {
            $this->content()->artifact(function () use ($draw): void {
                $draw($this);
            });
        } finally {
            $this->inArtifact = $previous;
        }

        return $this;
    }

    /** The element content is currently being attached to, if tagging is on. */
    public function currentElement(): ?StructureElement
    {
        return $this->forced ?? $this->element;
    }

    /**
     * Moves the point new content attaches to.
     *
     * The open/close half of inside(), for a caller whose structure does
     * not nest inside one closure -- Table, whose rows arrive one call at
     * a time and whose element has to stay open between them. Prefer
     * inside(), which cannot be left unbalanced.
     */
    public function enterElement(StructureElement $element): static
    {
        $this->element = $element;

        return $this;
    }

    /**
     * Wraps one text-drawing call in its structure element.
     *
     * $default is what the layout knows the content to be -- a paragraph
     * for paragraph(), a span for a run -- and is used unless the caller
     * has said otherwise with tag().
     */
    private function tagText(StructureRole $default, string $text, \Closure $draw): void
    {
        // Nothing drawn, nothing to attach. Without this an empty string
        // still produces an element wrapping a marked-content sequence
        // with no marks in it, which is a structure element pointing at
        // nothing -- invisible on the page and reported by every checker.
        if ($this->structure === null || $this->inArtifact || $text === '') {
            $draw();

            return;
        }

        $element = $this->forced ?? ($this->element ?? $this->tagged())->child($default);

        $this->content()->tagged($element, static function () use ($draw): void {
            $draw();
        });
    }

    /**
     * Wraps one shape-drawing call.
     *
     * Shapes drawn by the layout are **decoration** -- a cell's fill, a
     * table's rules, a divider -- and decoration is an artifact: it says
     * nothing a reader should announce, and in a tagged document content
     * that is neither tagged nor an artifact is the first thing a checker
     * reports.
     *
     * Unless the caller has said otherwise. Inside tag() the shapes are
     * the content: a chart drawn into a Figure is the figure, and marking
     * it decoration would leave the figure empty.
     */
    private function tagShape(\Closure $draw): void
    {
        if ($this->structure === null || $this->inArtifact) {
            $draw();

            return;
        }

        if ($this->forced !== null) {
            $this->content()->tagged($this->forced, static function () use ($draw): void {
                $draw();
            });

            return;
        }

        $this->content()->artifact(static function () use ($draw): void {
            $draw();
        });
    }

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
        return $this->mediaBox()->x1 + $this->unit->toPoints($x);
    }

    /** Where the flip from top-left/Y-down to PDF's bottom-left/Y-up happens. */
    public function toPointsY(float $y): float
    {
        return $this->mediaBox()->y2 - $this->unit->toPoints($y);
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
        $margins = $this->margins;

        // Against the margins this Flow was built with, not whatever the
        // last page left in place. A footer describes the page, and a
        // document that ended halfway down a second column would
        // otherwise print every one of them against the column's left
        // edge -- on pages that never had a column on them.
        $this->margins = $this->defaultMargins;

        try {
            foreach (array_keys($this->pages) as $index) {
                $this->current = $index;

                foreach ($this->hooks as $hook) {
                    $this->x = $this->margins->left;
                    $this->y = $this->margins->top;

                    // Page furniture is an artifact, always: a header, a
                    // folio and a rule describe the page rather than the
                    // document, and "Page 3 of 7" read aloud between two
                    // sentences is the classic failure of a document
                    // tagged by hand. Nothing here has to be asked for --
                    // what onEachPage() draws is furniture by definition.
                    $this->artifact(static function (self $flow) use ($hook, $index, $total): void {
                        $hook($flow, $index + 1, $total);
                    });
                }
            }
        } finally {
            $this->finishing = false;
            $this->current = $page;
            $this->x = $x;
            $this->y = $y;
            $this->margins = $margins;
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
