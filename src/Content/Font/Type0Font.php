<?php

declare(strict_types=1);

namespace MightyPDF\Content\Font;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\DocumentContext;
use MightyPDF\Assembler\Finalizable;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Content\Font\TrueType\FontException;
use MightyPDF\Content\Font\TrueType\TrueTypeFile;
use MightyPDF\Content\Font\TrueType\TrueTypeSubset;
use MightyPDF\Content\Text\Utf8;

/**
 * An embedded TrueType font as it exists inside one document: the
 * composite (Type0) font object PDF requires for text that reaches past
 * 256 characters, plus the four objects hanging off it.
 *
 * The shape is fixed by ISO 32000-2 §9.7 and is worth naming, because
 * five objects for one font looks like ceremony until you see what each
 * is for:
 *
 *   Type0 font        this object -- what the page's /Resources names,
 *                     and where the encoding is declared
 *     CIDFont         the widths, one per character id
 *       descriptor    the metrics a reader needs to substitute or lay
 *                     out the font
 *         FontFile2   the font program itself
 *     ToUnicode       what each character id means as text, so the
 *                     document can be searched and copied out of
 *
 * The encoding is Identity-H: the two bytes in the content stream *are*
 * the glyph number, with no intervening character set. That is the only
 * arrangement that reaches an arbitrary glyph of an arbitrary font, and
 * it is why /ToUnicode is not optional here -- without it, a reader has
 * no way to know that glyph 7 was ever the letter "A", and selecting the
 * text copies gibberish. That failure is invisible in the rendered page,
 * which is exactly how it gets shipped.
 *
 * Glyph numbers are assigned as text is drawn, not as the font is
 * loaded: the first character drawn becomes glyph 1, and so on. That is
 * what lets the font program be subset down to only what was used -- the
 * used set is not known until the document is finished, which is what
 * finalize() is for (see Finalizable).
 */
final class Type0Font extends Dictionary implements Finalizable, FontWriter
{
    /** PDF's default width for a character id the /W array does not mention. */
    private const int DEFAULT_WIDTH = 1000;

    /** ToUnicode CMaps are conventionally written in blocks of at most 100 entries. */
    private const int BFCHAR_BLOCK = 100;

    /** @var array<int, int> original glyph id => character id used in the content stream */
    private array $cidForGlyph = [];

    /** @var list<int> original glyph ids, in the order they were first drawn */
    private array $glyphOrder = [];

    /** @var array<int, int> character id => the code point it was drawn for */
    private array $codePointForCid = [];

    private function __construct(
        int $objectId,
        private readonly TrueTypeFile $file,
        private readonly bool $subset,
        private readonly Dictionary $cidFont,
        private readonly Dictionary $descriptor,
        private readonly Stream $fontFile,
        private readonly Stream $toUnicode,
    ) {
        parent::__construct($objectId);
    }

    /**
     * Builds the whole object graph and registers every part of it.
     *
     * The streams start empty on purpose: a font program cannot be
     * written before it is known which glyphs it has to contain, and the
     * /W array cannot be written before it is known which character ids
     * exist. Both are filled in by finalize(), which runs once the
     * document has stopped growing.
     */
    public static function create(DocumentContext $document, TrueTypeFile $file, bool $subset): self
    {
        $fontFile = new Stream($document->allocate(), '');
        $descriptor = new Dictionary($document->allocate());
        $cidFont = new Dictionary($document->allocate());
        $toUnicode = new Stream($document->allocate(), '');

        $font = new self($document->allocate(), $file, $subset, $cidFont, $descriptor, $fontFile, $toUnicode);

        $font->set('Type', new PdfName('Font'));
        $font->set('Subtype', new PdfName('Type0'));
        $font->set('Encoding', new PdfName('Identity-H'));
        $font->set('DescendantFonts', new PdfArray(new PdfReference($cidFont->objectId())));
        $font->set('ToUnicode', new PdfReference($toUnicode->objectId()));

        $cidFont->set('Type', new PdfName('Font'));
        $cidFont->set('Subtype', new PdfName('CIDFontType2'));
        $cidFont->set('FontDescriptor', new PdfReference($descriptor->objectId()));
        $cidFont->set('DW', new PdfInteger(self::DEFAULT_WIDTH));

        // The character ids written into the content stream are glyph
        // numbers of the embedded program, so the mapping between them
        // is the identity one and needs no table.
        $cidFont->set('CIDToGIDMap', new PdfName('Identity'));
        $cidFont->set('CIDSystemInfo', self::identitySystemInfo());

        $descriptor->set('Type', new PdfName('FontDescriptor'));
        $descriptor->set('FontFile2', new PdfReference($fontFile->objectId()));

        foreach ([$fontFile, $descriptor, $cidFont, $toUnicode, $font] as $object) {
            $document->register($object);
        }

        return $font;
    }

    public function dictionary(): Dictionary
    {
        return $this;
    }

    public function usesHexStrings(): bool
    {
        return true;
    }

    /** Word spacing applies to single-byte code 32 only (§9.3.3), and these codes are two bytes. */
    public function supportsWordSpacing(): bool
    {
        return false;
    }

    public function encode(string $utf8Text): string
    {
        $bytes = '';

        foreach (Utf8::codePoints($utf8Text) as $codePoint) {
            $glyph = $this->file->glyphForCodePoint($codePoint);

            if ($glyph === null) {
                throw new FontException(sprintf(
                    'The font "%s" has no glyph for "%s" (U+%04X). Draw this text in a font that contains it, or '
                    . 'call EmbeddedFont::missingCharacters() first to find every character it lacks.',
                    $this->file->postScriptName(),
                    Utf8::fromCodePoint($codePoint),
                    $codePoint,
                ));
            }

            $bytes .= pack('n', $this->characterIdFor($glyph, $codePoint));
        }

        return $bytes;
    }

    /**
     * The character id standing for a glyph in this document, assigning
     * the next free one the first time a glyph is drawn.
     *
     * Subsetting is what makes this an assignment rather than a lookup:
     * the subset font renumbers glyphs 1, 2, 3... in the order asked
     * for, so the id written into the content stream has to be the
     * position in that order. Embedding the file whole keeps the font's
     * own numbering, since the glyphs stay where they were.
     */
    private function characterIdFor(int $glyph, int $codePoint): int
    {
        if (!isset($this->cidForGlyph[$glyph])) {
            $this->glyphOrder[] = $glyph;
            $this->cidForGlyph[$glyph] = $this->subset ? count($this->glyphOrder) : $glyph;
        }

        $cid = $this->cidForGlyph[$glyph];

        // First writer wins: one glyph can be reached from several code
        // points (a font may map both U+00B5 MICRO SIGN and U+03BC GREEK
        // SMALL LETTER MU to one glyph), and /ToUnicode has room for one
        // answer. Either is defensible; changing the answer depending on
        // which was drawn last is not.
        $this->codePointForCid[$cid] ??= $codePoint;

        return $cid;
    }

    public function finalize(): void
    {
        $program = $this->subset
            ? TrueTypeSubset::build($this->file, $this->glyphOrder)
            : $this->file->bytes();

        $this->fontFile->replaceBytes($program);

        // /Length1 is the program's length before compression. A reader
        // that inflates the stream and finds a different length has been
        // handed a font it cannot trust.
        $this->fontFile->set('Length1', new PdfInteger(strlen($program)));

        $baseFont = new PdfName($this->baseFontName());
        $this->set('BaseFont', $baseFont);
        $this->cidFont->set('BaseFont', $baseFont);
        $this->cidFont->set('W', $this->widths());

        $this->describeFont($baseFont);
        $this->toUnicode->replaceBytes($this->buildToUnicodeCMap());
    }

    private function describeFont(PdfName $baseFont): void
    {
        $metrics = $this->file->metrics();
        [$xMin, $yMin, $xMax, $yMax] = $metrics->boundingBox();

        $this->descriptor->set('FontName', $baseFont);
        $this->descriptor->set('Flags', new PdfInteger($metrics->flags()));
        $this->descriptor->set('FontBBox', new PdfArray(
            new PdfInteger($xMin),
            new PdfInteger($yMin),
            new PdfInteger($xMax),
            new PdfInteger($yMax),
        ));
        $this->descriptor->set('ItalicAngle', new PdfReal($metrics->italicAngle));
        $this->descriptor->set('Ascent', new PdfInteger($metrics->toGlyphSpace($metrics->ascent)));
        $this->descriptor->set('Descent', new PdfInteger($metrics->toGlyphSpace($metrics->descent)));
        $this->descriptor->set('CapHeight', new PdfInteger($metrics->capHeightInGlyphSpace()));
        $this->descriptor->set('StemV', new PdfInteger($metrics->stemV()));
    }

    /**
     * A subset font's name carries a six-letter tag and a "+" (§9.6.4),
     * which is how a reader tells two documents' subsets of the same
     * font apart -- they have the same name and different glyphs, and
     * treating them as one font is how text from a merged document turns
     * into the wrong letters.
     *
     * The tag is derived from the glyph list rather than randomly, so
     * that saving the same document twice produces the same bytes.
     */
    private function baseFontName(): string
    {
        $name = $this->file->postScriptName();

        if (!$this->subset) {
            return $name;
        }

        // Raw digest bytes rather than its hex form: a hex digit only
        // ever reaches 15, so folding one to a letter would use A-P and
        // leave a third of the alphabet unreachable.
        $digest = sha1($name . ':' . implode(',', $this->glyphOrder), true);
        $tag = '';

        for ($i = 0; $i < 6; ++$i) {
            $tag .= chr(ord('A') + ord($digest[$i]) % 26);
        }

        return "$tag+$name";
    }

    /**
     * The /W array: character id to width, in PDF glyph space.
     *
     * Written in the "start [w w w ...]" form over runs of consecutive
     * ids. For a subset that is one run covering every glyph, since the
     * ids were handed out consecutively; embedding whole leaves gaps,
     * and a run breaks at each.
     */
    private function widths(): PdfArray
    {
        $metrics = $this->file->metrics();
        $widths = [];

        foreach ($this->cidForGlyph as $glyph => $cid) {
            $widths[$cid] = $metrics->toGlyphSpace($this->file->advanceWidth($glyph));
        }

        ksort($widths);

        $entries = [];
        $run = [];
        $runStart = 0;

        foreach ($widths as $cid => $width) {
            if ($run !== [] && $cid !== $runStart + count($run)) {
                array_push($entries, new PdfInteger($runStart), new PdfArray(...$run));
                $run = [];
            }

            if ($run === []) {
                $runStart = $cid;
            }

            $run[] = new PdfInteger($width);
        }

        if ($run !== []) {
            array_push($entries, new PdfInteger($runStart), new PdfArray(...$run));
        }

        return new PdfArray(...$entries);
    }

    /**
     * The /ToUnicode CMap: character id to the text it stands for.
     *
     * This is a PostScript-syntax resource rather than a PDF dictionary
     * -- an accident of history, but the format is fixed and readers
     * parse it strictly, so the surrounding boilerplate is not
     * decoration.
     */
    private function buildToUnicodeCMap(): string
    {
        $entries = [];

        foreach ($this->codePointForCid as $cid => $codePoint) {
            $entries[] = sprintf('<%04X> <%s>', $cid, self::utf16beHex($codePoint));
        }

        $blocks = '';

        foreach (array_chunk($entries, self::BFCHAR_BLOCK) as $chunk) {
            $blocks .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }

        return <<<CMAP
            /CIDInit /ProcSet findresource begin
            12 dict begin
            begincmap
            /CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def
            /CMapName /Adobe-Identity-UCS def
            /CMapType 2 def
            1 begincodespacerange
            <0000> <FFFF>
            endcodespacerange
            {$blocks}endcmap
            CMapName currentdict /CMap defineresource pop
            end
            end
            CMAP;
    }

    /**
     * A code point as the hex UTF-16BE that a bfchar entry takes --
     * which for anything past the Basic Multilingual Plane means a
     * surrogate pair, four bytes rather than two. Emoji and the rarer
     * CJK live there, and a reader handed a bare 21-bit value there
     * shows nothing useful when the text is copied.
     *
     * Written out rather than delegated to PdfString::utf16be(), which
     * prepends the byte-order mark that a PDF text string needs and a
     * bfchar entry must not have.
     */
    private static function utf16beHex(int $codePoint): string
    {
        if ($codePoint <= 0xFFFF) {
            return sprintf('%04X', $codePoint);
        }

        $offset = $codePoint - 0x10000;

        return sprintf('%04X%04X', 0xD800 + ($offset >> 10), 0xDC00 + ($offset & 0x3FF));
    }

    private static function identitySystemInfo(): Dictionary
    {
        $info = new Dictionary();
        $info->set('Registry', PdfString::latin1('Adobe'));
        $info->set('Ordering', PdfString::latin1('Identity'));
        $info->set('Supplement', new PdfInteger(0));

        return $info;
    }
}
