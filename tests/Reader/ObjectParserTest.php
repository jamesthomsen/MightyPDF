<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Reader;

use MightyPDF\Assembler\Dictionary;
use MightyPDF\Assembler\Stream;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfBoolean;
use MightyPDF\Assembler\Types\PdfHexString;
use MightyPDF\Assembler\Types\PdfInteger;
use MightyPDF\Assembler\Types\PdfName;
use MightyPDF\Assembler\Types\PdfNull;
use MightyPDF\Assembler\Types\PdfReal;
use MightyPDF\Assembler\Types\PdfReference;
use MightyPDF\Assembler\Types\PdfString;
use MightyPDF\Assembler\Types\PdfValue;
use MightyPDF\Reader\Lexer;
use MightyPDF\Reader\ObjectParser;
use MightyPDF\Reader\ParseException;
use PHPUnit\Framework\TestCase;

final class ObjectParserTest extends TestCase
{
    public function testParsesBooleansAndNull(): void
    {
        self::assertInstanceOf(PdfBoolean::class, self::parse('true'));
        self::assertTrue(self::parse('true')->value());
        self::assertFalse(self::parse('false')->value());
        self::assertInstanceOf(PdfNull::class, self::parse('null'));
    }

    public function testDistinguishesIntegersFromReals(): void
    {
        self::assertInstanceOf(PdfInteger::class, self::parse('42'));
        self::assertSame(42, self::parse('42')->value());

        self::assertInstanceOf(PdfReal::class, self::parse('-.5'));
        self::assertSame(-0.5, self::parse('-.5')->value());
    }

    public function testParsesAnIndirectReference(): void
    {
        $reference = self::parse('12 3 R');

        self::assertInstanceOf(PdfReference::class, $reference);
        self::assertSame(12, $reference->objectId());
        self::assertSame(3, $reference->generation());
    }

    public function testAnIntegerNotFollowedByRIsJustAnInteger(): void
    {
        // The two-token look-ahead needed to spot "1 0 R" has to be given
        // back when it turns out not to be one, or the following elements
        // vanish.
        $array = self::parse('[1 2 3]');

        self::assertInstanceOf(PdfArray::class, $array);
        self::assertCount(3, $array->items());
        self::assertSame([1, 2, 3], array_map(static fn (PdfValue $v): int => $v->value(), $array->items()));
    }

    public function testMixesReferencesAndNumbersInOneArray(): void
    {
        $array = self::parse('[1 0 R 7 2 0 R]');

        self::assertCount(3, $array->items());
        self::assertInstanceOf(PdfReference::class, $array->items()[0]);
        self::assertInstanceOf(PdfInteger::class, $array->items()[1]);
        self::assertInstanceOf(PdfReference::class, $array->items()[2]);
    }

    public function testKeepsNullElementsInArrays(): void
    {
        // In a dictionary a null value means "absent", but in an array it
        // occupies a position -- dropping it would shift every later index.
        $array = self::parse('[1 0 R null 3 0 R]');

        self::assertCount(3, $array->items());
        self::assertInstanceOf(PdfNull::class, $array->items()[1]);
    }

    public function testParsesNestedDictionaries(): void
    {
        $dictionary = self::parse('<< /Type /Page /Resources << /Font << /F1 5 0 R >> >> >>');

        self::assertInstanceOf(Dictionary::class, $dictionary);
        self::assertSame('Page', $dictionary->get('Type')->value());

        $resources = $dictionary->get('Resources');
        self::assertInstanceOf(Dictionary::class, $resources);

        $fonts = $resources->get('Font');
        self::assertInstanceOf(Dictionary::class, $fonts);
        self::assertInstanceOf(PdfReference::class, $fonts->get('F1'));
    }

    public function testANestedDictionaryGetsNoObjectId(): void
    {
        // A page's inline /Resources has no object number of its own;
        // giving it one would make it render as a second top-level object.
        $parsed = self::parseIndirect("1 0 obj\n<< /Resources << /X 1 >> >>\nendobj\n");

        self::assertSame(1, $parsed->objectId);
        self::assertInstanceOf(Dictionary::class, $parsed->value);

        $resources = $parsed->value->get('Resources');
        self::assertInstanceOf(Dictionary::class, $resources);

        $this->expectException(\LogicException::class);
        $resources->objectId();
    }

    public function testParsesStringsAsOpaqueBytes(): void
    {
        $string = self::parse('(a\\(b)');

        self::assertInstanceOf(PdfString::class, $string);
        self::assertSame('(a\\(b)', $string->format());
    }

    public function testRoundTripsAUtf16FieldValue(): void
    {
        // A value written by the writer's own text() path has to come back
        // byte-identical, or every form field with a non-ASCII value is
        // silently mangled on the next edit.
        $original = PdfString::text('Zoë')->format();

        self::assertSame($original, self::parse($original)->format());
    }

    public function testParsesHexStrings(): void
    {
        $string = self::parse('<48656C6C6F>');

        self::assertInstanceOf(PdfHexString::class, $string);
        self::assertSame('<48656c6c6f>', $string->format());
    }

    public function testParsesAnIndirectObjectWithItsGeneration(): void
    {
        $parsed = self::parseIndirect("7 2 obj\n<< /Type /Catalog >>\nendobj\n");

        self::assertSame(7, $parsed->objectId);
        self::assertSame(2, $parsed->generation);
        self::assertInstanceOf(Dictionary::class, $parsed->value);
        self::assertSame(7, $parsed->value->objectId());
    }

    public function testParsesAStreamUsingItsDeclaredLength(): void
    {
        $data = 'BT /F1 12 Tf ET';
        $parsed = self::parseIndirect(sprintf(
            "1 0 obj\n<< /Length %d >>\nstream\n%s\nendstream\nendobj\n",
            strlen($data),
            $data,
        ));

        self::assertInstanceOf(Stream::class, $parsed->value);
        self::assertSame($data, $parsed->value->rawBytes());
    }

    public function testRecoversFromAWrongLength(): void
    {
        // A /Length that does not land on "endstream" is not to be
        // trusted, however well it parses -- wrong lengths are common in
        // files produced by tools that edit bytes without fixing them up.
        $data = 'BT /F1 12 Tf ET';
        $parsed = self::parseIndirect(sprintf(
            "1 0 obj\n<< /Length 3 >>\nstream\n%s\nendstream\nendobj\n",
            $data,
        ));

        self::assertInstanceOf(Stream::class, $parsed->value);
        self::assertSame($data, $parsed->value->rawBytes());
    }

    public function testRecoversWhenLengthIsMissingEntirely(): void
    {
        $parsed = self::parseIndirect("1 0 obj\n<< >>\nstream\nsome data\nendstream\nendobj\n");

        self::assertInstanceOf(Stream::class, $parsed->value);
        self::assertSame('some data', $parsed->value->rawBytes());
    }

    public function testResolvesAnIndirectLength(): void
    {
        $data = 'BT /F1 12 Tf ET';
        $bytes = sprintf("1 0 obj\n<< /Length 2 0 R >>\nstream\n%s\nendstream\nendobj\n", $data);

        $lexer = new Lexer($bytes);
        $parser = new ObjectParser(
            $lexer,
            fn (PdfReference $reference): ?int => $reference->objectId() === 2 ? strlen($data) : null,
        );

        $parsed = $parser->parseIndirectObjectAt(0);

        self::assertInstanceOf(Stream::class, $parsed->value);
        self::assertSame($data, $parsed->value->rawBytes());
    }

    public function testHandlesBinaryStreamDataWithoutCorruptingIt(): void
    {
        $data = random_bytes(512);
        $parsed = self::parseIndirect(sprintf(
            "1 0 obj\n<< /Length %d >>\nstream\n%s\nendstream\nendobj\n",
            strlen($data),
            $data,
        ));

        self::assertInstanceOf(Stream::class, $parsed->value);
        self::assertSame($data, $parsed->value->rawBytes());
    }

    public function testReadsAStreamWhoseBodyStartsAfterACarriageReturnLineFeed(): void
    {
        $data = 'payload';
        $parsed = self::parseIndirect(sprintf(
            "1 0 obj\r\n<< /Length %d >>\r\nstream\r\n%s\r\nendstream\r\nendobj\r\n",
            strlen($data),
            $data,
        ));

        self::assertInstanceOf(Stream::class, $parsed->value);
        self::assertSame($data, $parsed->value->rawBytes());
    }

    public function testAParsedStreamIsWrittenBackVerbatimWithItsOwnFilter(): void
    {
        // The point of the whole passthrough design: re-encoding a parsed
        // stream would both corrupt the data and contradict its /Filter.
        $data = gzcompress('hello world');
        $parsed = self::parseIndirect(sprintf(
            "1 0 obj\n<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream\nendobj\n",
            strlen($data),
            $data,
        ));

        self::assertInstanceOf(Stream::class, $parsed->value);

        $rendered = $parsed->value->render(true);

        self::assertStringContainsString($data, $rendered);
        self::assertStringContainsString('/Filter /FlateDecode', $rendered);
        self::assertStringContainsString('/Length ' . strlen($data), $rendered);
    }

    public function testParsesADictionaryKeyThatLooksLikeAnInteger(): void
    {
        // /1 is an ordinary key: a checkbox appearance state named by a
        // numeric export value. PHP turns "1" into an int array key on the
        // way back out, which set()'s string parameter would reject.
        $dictionary = self::parse('<< /1 /On /Off /Off >>');

        self::assertInstanceOf(Dictionary::class, $dictionary);
        self::assertInstanceOf(PdfName::class, $dictionary->get('1'));
        self::assertSame('<< /1 /On /Off /Off >>', $dictionary->format());
    }

    public function testRejectsAStreamThatIsNotAnIndirectObject(): void
    {
        $this->expectException(ParseException::class);

        self::parse("<< /Length 1 >>\nstream\nx\nendstream");
    }

    public function testRejectsAStreamWithNoEndstream(): void
    {
        $this->expectException(ParseException::class);

        self::parseIndirect("1 0 obj\n<< >>\nstream\nno terminator\n");
    }

    public function testSkipsJunkWhereADictionaryKeyShouldBe(): void
    {
        // Verbatim shape of this project's 2012 writer's output, which
        // emitted "/Resources" with no value: /Resources swallows
        // the /MediaBox key and leaves its array dangling. Losing the
        // whole page over that would be the wrong trade -- the other
        // entries are perfectly readable.
        $dictionary = self::parse('<< /Type /Page /Parent 3 /Resources /MediaBox [0 0 612 792] >>');

        self::assertInstanceOf(Dictionary::class, $dictionary);
        self::assertSame('Page', $dictionary->get('Type')?->value());
        self::assertSame(3, $dictionary->get('Parent')?->value());
    }

    public function testConsumesAStrayCompositeWhole(): void
    {
        // Skipping only the "[" would resume parsing inside the array,
        // where every element in turn looks like more junk -- and "/Trap"
        // would then be read as a key belonging to nothing.
        $dictionary = self::parse('<< /A 1 [ /Trap 2 ] /B 3 >>');

        self::assertSame(1, $dictionary->get('A')?->value());
        self::assertSame(3, $dictionary->get('B')?->value());
        self::assertNull($dictionary->get('Trap'));
    }

    public function testAMissingDictionaryCloseEndsAtTheObjectBoundary(): void
    {
        // Without this the recovery loop would hunt for a key through the
        // rest of the file, turning one damaged object into an unreadable
        // document.
        $parsed = self::parseIndirect("1 0 obj\n<< /Type /Page\nendobj\n2 0 obj\n<< /Type /Font >>\nendobj\n");

        self::assertInstanceOf(Dictionary::class, $parsed->value);
        self::assertSame('Page', $parsed->value->get('Type')?->value());
        self::assertNull($parsed->value->get('Font'));
    }

    private static function parse(string $bytes): PdfValue
    {
        return (new ObjectParser(new Lexer($bytes)))->parseValue();
    }

    private static function parseIndirect(string $bytes): \MightyPDF\Reader\IndirectObject
    {
        return (new ObjectParser(new Lexer($bytes)))->parseIndirectObjectAt(0);
    }
}
