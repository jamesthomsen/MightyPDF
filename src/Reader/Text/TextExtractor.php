<?php

declare(strict_types=1);

namespace MightyPDF\Reader\Text;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Editor\PageTree;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Exception\InvalidArgumentException;

/**
 * Reads the text back out of an existing PDF.
 *
 * ```php
 * $editor = PdfEditor::open('report.pdf');
 * echo (new TextExtractor($editor))->page(0)->text();
 * ```
 *
 * **What this is and is not.** A PDF does not contain text in any form a
 * program can simply read; it contains instructions for putting marks on
 * paper. "Extracting text" means running those instructions far enough to
 * know what was drawn and where -- tracking the graphics and text
 * matrices, resolving each font's encoding, and accumulating positions --
 * and then inferring lines and words from geometry that never stated
 * either. It is reconstruction, and it is imperfect by construction. What
 * it is good for is searching, checking, indexing and testing; what it is
 * not is a faithful round trip.
 *
 * Two things it deliberately does not do:
 *
 * - **Rendering.** Text inside a form XObject is followed, because that is
 *   where a stamped or flattened page keeps its content and skipping it
 *   would silently lose text. Text drawn as vector outlines, or in a
 *   raster image, is not text and no amount of trying makes it so -- a
 *   scanned page yields nothing, which is the honest answer and the reason
 *   OCR exists.
 * - **Reading order beyond the obvious.** See PageText: lines and spaces
 *   are inferred from baselines and gaps, and multi-column layouts, tables
 *   and right-to-left scripts are not untangled. fragments() gives a
 *   caller everything needed to do better.
 *
 * **There are limits on how much work one page may cause**, because a page
 * can invoke a form XObject as often as it likes and a few hundred bytes of
 * file can otherwise ask for more work than there is time in the day. See
 * the three constants below. A page that reaches one of them returns the
 * text it did read and says so through PageText::isTruncated(), rather than
 * throwing -- extraction is forgiving everywhere else, and refusing a page
 * outright would be the one place it was not.
 */
final class TextExtractor
{
    /**
     * How deep a form XObject may invoke another before this stops
     * following. Deep nesting is legitimate (a stamp inside a stamp); a
     * cycle is not, and only one of the two terminates on its own.
     */
    private const int MAX_XOBJECT_DEPTH = 8;

    /**
     * How many form XObjects one page may invoke in total, counting each
     * invocation and not each XObject.
     *
     * A depth limit is not a work limit, and this is the bound that does
     * the work MAX_XOBJECT_DEPTH looks like it is doing. An XObject may
     * invoke another -- including itself -- as many times as it likes, so
     * the cost is the fan-out raised to the depth: thirty `Do` operators
     * in one self-referential stream is 30^8 runs out of a few hundred
     * bytes of file. The depth cap fires every time and stops nothing,
     * because the recursion was never deep, only wide.
     *
     * Sixty-odd thousand is past any real page by a wide margin -- a
     * thousand-field form flattens to a thousand of these -- and reaching
     * it costs a fraction of a second.
     */
    private const int MAX_XOBJECT_INVOCATIONS = 65_536;

    /**
     * How many bytes of XObject content one page may have run through it,
     * counting each XObject as often as it is invoked.
     *
     * The other half of the pair, and the one that bounds re-reading
     * something large rather than something small: the invocation limit
     * alone would permit sixty thousand runs of a stream that is itself
     * enormous.
     *
     * The page's *own* content is deliberately not charged against this.
     * A page is read once however big it is -- there is no amplification
     * in reading it, and a budget that could refuse it would turn a large
     * legitimate page into a silently half-extracted one, which is the
     * failure worth avoiding rather than the one worth adding. Only
     * expansion is bounded, because only expansion multiplies.
     *
     * Eight megabytes is the figure that decides the worst case, the
     * invocation limit above being reached long before it on any small
     * XObject: it is a second or two of lexing, against the seconds-times-
     * fan-out this would otherwise still cost a bounded but patient
     * attacker. Legitimate expansion is nowhere near it -- a flattened
     * thousand-field form is a couple of hundred kilobytes.
     */
    private const int MAX_XOBJECT_BYTES = 8 * 1024 * 1024;

    /**
     * How many bytes of XObject stream one page may *decode*, counting
     * each distinct XObject once.
     *
     * The third bound, and the one the other two do not imply, because
     * decoding happens before there is any way to know what it will cost.
     * A `Do` naming a stream that inflates to sixteen megabytes cannot be
     * turned down until it has been inflated -- so a page whose own
     * content invokes that stream eight hundred times inflates it eight
     * hundred times and declines to run it eight hundred times, which is
     * thirteen gigabytes of work out of a twenty-kilobyte file.
     *
     * Two things together stop it: the decoded stream is memoized, so
     * repeats cost nothing after the first, and this bounds the distinct
     * ones -- which also bounds the memo, that being the same set of
     * bytes. Set well above MAX_XOBJECT_BYTES because inflating is an
     * order of magnitude cheaper per byte than lexing, and one decode
     * cannot exceed what StreamFilter already caps it at.
     */
    private const int MAX_XOBJECT_DECODED_BYTES = 64 * 1024 * 1024;

    private readonly PageTree $tree;

    /** @var array<int, FontDecoder> font object id => its decoder */
    private array $decoders = [];

    /**
     * The decoded content of each form XObject met on this page, by object
     * id -- see MAX_XOBJECT_DECODED_BYTES.
     *
     * @var array<int, string>
     */
    private array $streams = [];

    /** What is left of MAX_XOBJECT_BYTES for the page being read. */
    private int $budget = 0;

    /** What is left of MAX_XOBJECT_INVOCATIONS for the page being read. */
    private int $invocations = 0;

    /** What is left of MAX_XOBJECT_DECODED_BYTES for the page being read. */
    private int $decodeBudget = 0;

    /**
     * Whether any of the three limits stopped this page short of content
     * it would otherwise have followed. Reported rather than thrown --
     * see PageText::isTruncated().
     */
    private bool $truncated = false;

    public function __construct(private readonly PdfEditor $editor)
    {
        $this->tree = new PageTree($editor);
    }

    public function pageCount(): int
    {
        return $this->tree->count();
    }

    /** @param int $index zero-based, as everywhere else in this library */
    public function page(int $index): PageText
    {
        $page = $this->tree->page($index);

        if ($page === null) {
            throw new InvalidArgumentException(sprintf(
                'This document has %d page%s, numbered 0 to %d; there is no page %d.',
                $this->tree->count(),
                $this->tree->count() === 1 ? '' : 's',
                $this->tree->count() - 1,
                $index,
            ));
        }

        $state = new TextState();
        $resources = $this->editor->resolveDictionary($this->tree->inherited($page, 'Resources'));

        // Per page, not per extractor: reading page 2 should cost what
        // page 2 contains whatever page 1 turned out to be.
        $this->budget = self::MAX_XOBJECT_BYTES;
        $this->invocations = self::MAX_XOBJECT_INVOCATIONS;
        $this->decodeBudget = self::MAX_XOBJECT_DECODED_BYTES;
        $this->streams = [];
        $this->truncated = false;

        $this->run($this->contentOf($page), $resources, $state, 0);

        return new PageText($state->fragments, $this->truncated);
    }

    /**
     * Every page's text, joined with a form feed as a page separator.
     *
     * A string has nowhere to say "and there was more of me", so this
     * cannot report a page that stopped at one of the limits below. A
     * caller that needs to know asks page by page and reads
     * PageText::isTruncated().
     */
    public function text(): string
    {
        $pages = [];

        for ($index = 0; $index < $this->tree->count(); ++$index) {
            $pages[] = $this->page($index)->text();
        }

        return implode("\n\f", $pages);
    }

    /** A page's content streams, concatenated as §7.8.2 requires. */
    private function contentOf(Dictionary $page): string
    {
        $contents = $this->editor->resolve($page->get('Contents'));
        $items = $contents instanceof PdfArray ? $contents->items() : [$page->get('Contents')];

        $out = '';

        foreach ($items as $item) {
            $stream = $this->editor->resolve($item);

            if ($stream instanceof Stream && $this->editor->store()->canDecode($stream)) {
                // Joined with a newline: §7.8.2 says the division into
                // streams is arbitrary and a token may not span it, but
                // plenty of writers end one mid-whitespace anyway.
                $out .= $this->editor->store()->decodedStream($stream) . "\n";
            }
        }

        return $out;
    }

    private function run(string $content, ?Dictionary $resources, TextState $state, int $depth): void
    {
        foreach (ContentOperations::of($content) as [$operator, $operands]) {
            match ($operator) {
                'q' => $state->push(),
                'Q' => $state->pop(),
                'cm' => $state->concat(self::numbers($operands)),
                'BT' => $state->beginText(),
                'ET' => $state->endText(),
                'Tf' => $this->setFont($state, $resources, $operands),
                'Td' => $state->nextLine(self::number($operands, 0), self::number($operands, 1)),
                'TD' => $this->setLeadingAndMove($state, $operands),
                'Tm' => $state->setTextMatrix(self::numbers($operands)),
                'T*' => $state->nextLine(0.0, -$state->leading),
                'TL' => $state->leading = self::number($operands, 0),
                'Tc' => $state->characterSpacing = self::number($operands, 0),
                'Tw' => $state->wordSpacing = self::number($operands, 0),
                'Tz' => $state->horizontalScale = self::number($operands, 0) / 100.0,
                'Ts' => $state->rise = self::number($operands, 0),
                'Tr' => $state->renderMode = (int) self::number($operands, 0),
                'Tj' => $this->show($state, self::bytes($operands, 0)),
                'TJ' => $this->showArray($state, $operands[0] ?? null),
                "'" => $this->showOnNextLine($state, $operands),
                '"' => $this->showOnNextLineSpaced($state, $operands),
                'Do' => $this->runXObject($state, $resources, $operands, $depth),
                default => null,
            };
        }
    }

    /** @param list<PdfValue> $operands */
    private function setLeadingAndMove(TextState $state, array $operands): void
    {
        // TD is Td with a side effect: the leading becomes the negated
        // vertical move, which is how a producer says "and this is what a
        // line is worth" once rather than per line.
        $state->leading = -self::number($operands, 1);
        $state->nextLine(self::number($operands, 0), self::number($operands, 1));
    }

    /** @param list<PdfValue> $operands */
    private function showOnNextLine(TextState $state, array $operands): void
    {
        $state->nextLine(0.0, -$state->leading);
        $this->show($state, self::bytes($operands, 0));
    }

    /** @param list<PdfValue> $operands */
    private function showOnNextLineSpaced(TextState $state, array $operands): void
    {
        $state->wordSpacing = self::number($operands, 0);
        $state->characterSpacing = self::number($operands, 1);
        $state->nextLine(0.0, -$state->leading);
        $this->show($state, self::bytes($operands, 2));
    }

    /** @param list<PdfValue> $operands */
    private function setFont(TextState $state, ?Dictionary $resources, array $operands): void
    {
        $state->fontSize = self::number($operands, 1);
        $state->font = null;

        $name = $operands[0] ?? null;

        if (!$name instanceof PdfName) {
            return;
        }

        $fonts = $this->editor->resolveDictionary($resources?->get('Font'));
        $font = $this->editor->resolveDictionary($fonts?->get($name->value()));

        if ($font === null) {
            return;
        }

        // Cached by object id: a page that switches between two fonts a
        // thousand times should build two decoders, and building one
        // means parsing a CMap.
        $key = $font->hasObjectId() ? $font->objectId() : -1;

        $state->font = $key === -1
            ? new FontDecoder($this->editor, $font)
            : ($this->decoders[$key] ??= new FontDecoder($this->editor, $font));
    }

    private function showArray(TextState $state, ?PdfValue $array): void
    {
        if (!$array instanceof PdfArray) {
            return;
        }

        foreach ($array->items() as $item) {
            if ($item instanceof PdfString || $item instanceof PdfHexString) {
                $this->show($state, $item->bytes());

                continue;
            }

            $adjustment = match (true) {
                $item instanceof PdfReal => $item->value(),
                $item instanceof PdfInteger => (float) $item->value(),
                default => null,
            };

            if ($adjustment !== null) {
                // A number in TJ moves the pen back by that many
                // thousandths of an em. This is how kerning is expressed,
                // and also how a producer draws a space without emitting
                // one -- which is why PageText infers spaces from gaps.
                $state->advance(-$adjustment / 1000.0 * $state->fontSize * $state->horizontalScale);
            }
        }
    }

    private function show(TextState $state, string $bytes): void
    {
        $font = $state->font;

        if ($font === null || $bytes === '') {
            return;
        }

        // Render mode 3 is "invisible", which is exactly what an OCR
        // layer under a scanned image uses. That text is wanted -- it is
        // the only text such a page has -- so it is kept. Mode 7 adds the
        // glyphs to the clipping path and paints nothing, and is likewise
        // kept: it is text either way.
        $width = $font->codeLength();
        $length = strlen($bytes);
        $text = '';
        $startX = $state->x();
        $startY = $state->y();

        for ($at = 0; $at + $width <= $length; $at += $width) {
            $code = $width === 2
                ? (ord($bytes[$at]) << 8) | ord($bytes[$at + 1])
                : ord($bytes[$at]);

            $text .= $font->textFor($code);

            $advance = $font->widthFor($code) / 1000.0 * $state->fontSize
                + $state->characterSpacing
                + ($font->isWordSpace($code) ? $state->wordSpacing : 0.0);

            $state->advance($advance * $state->horizontalScale);
        }

        if ($text === '') {
            return;
        }

        // The width is how far the pen actually moved on the page, which
        // for rotated text is not the run's visual width. Good enough for
        // what it is used for -- deciding whether two runs on one baseline
        // have a gap between them -- and rotated text has no baseline to
        // share anyway.
        $state->fragments[] = new TextFragment(
            text: $text,
            x: $startX,
            y: $startY,
            width: $state->x() - $startX,
            fontSize: $state->effectiveFontSize(),
        );
    }

    /**
     * Follows a form XObject.
     *
     * Not an optional refinement: a page stamped by this library's own
     * PageOverlay, or flattened by FormFlattener, keeps everything it drew
     * inside one of these. Skipping them would mean a flattened form
     * extracts as blank.
     *
     * @param list<PdfValue> $operands
     */
    private function runXObject(TextState $state, ?Dictionary $resources, array $operands, int $depth): void
    {
        if ($depth >= self::MAX_XOBJECT_DEPTH || $this->invocations <= 0) {
            $this->truncated = true;

            return;
        }

        --$this->invocations;

        $name = $operands[0] ?? null;

        if (!$name instanceof PdfName) {
            return;
        }

        $xObjects = $this->editor->resolveDictionary($resources?->get('XObject'));
        $form = $this->editor->resolve($xObjects?->get($name->value()));

        if (!$form instanceof Stream || !$this->editor->store()->canDecode($form)) {
            return;
        }

        $subtype = $this->editor->resolve($form->get('Subtype'));

        if (!$subtype instanceof PdfName || $subtype->value() !== 'Form') {
            return;
        }

        $content = $this->decodedContentOf($form);

        // Charged separately from decoding it: this is the budget for
        // *running* the bytes, and a stream is run once per invocation
        // however many times it had to be decoded.
        if ($content === null || strlen($content) > $this->budget) {
            $this->truncated = true;

            return;
        }

        $this->budget -= strlen($content);

        // The XObject draws in its own space: /Matrix maps that space into
        // the one invoking it, on top of whatever transform is current.
        $state->push();
        $state->concat($this->tree->numbers($form->get('Matrix')));

        $inner = $this->editor->resolveDictionary($form->get('Resources')) ?? $resources;

        $this->run($content, $inner, $state, $depth + 1);

        $state->pop();
    }

    /**
     * A form XObject's decoded content, decoded at most once per page, or
     * null once this page has decoded as much as it may.
     *
     * The memo is worth having on its own account -- a stamp placed on
     * every row of a table is one stream inflated once rather than once a
     * row -- and it is what makes the budget a bound rather than a
     * suggestion. See MAX_XOBJECT_DECODED_BYTES.
     */
    private function decodedContentOf(Stream $form): ?string
    {
        $key = $form->hasObjectId() ? $form->objectId() : -1;

        if ($key !== -1 && isset($this->streams[$key])) {
            return $this->streams[$key];
        }

        if ($this->decodeBudget <= 0) {
            $this->truncated = true;

            return null;
        }

        // Charged after the fact because there is no before: what a
        // stream inflates to is not knowable without inflating it. One
        // decode is bounded by StreamFilter, so the overshoot is bounded
        // too, and the next distinct stream is refused.
        $content = $this->editor->store()->decodedStream($form);

        $this->decodeBudget -= strlen($content);

        if ($key !== -1) {
            $this->streams[$key] = $content;
        }

        return $content;
    }

    /** @param list<PdfValue> $operands */
    private static function number(array $operands, int $index): float
    {
        $value = $operands[$index] ?? null;

        return match (true) {
            $value instanceof PdfReal => $value->value(),
            $value instanceof PdfInteger => (float) $value->value(),
            default => 0.0,
        };
    }

    /**
     * @param list<PdfValue> $operands
     * @return list<float>
     */
    private static function numbers(array $operands): array
    {
        return array_map(
            static fn (int $index): float => self::number($operands, $index),
            range(0, max(0, count($operands) - 1)),
        );
    }

    /** @param list<PdfValue> $operands */
    private static function bytes(array $operands, int $index): string
    {
        $value = $operands[$index] ?? null;

        return $value instanceof PdfString || $value instanceof PdfHexString ? $value->bytes() : '';
    }
}
