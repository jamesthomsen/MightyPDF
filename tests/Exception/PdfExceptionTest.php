<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Exception;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\StreamSink;
use MightyPDF\Content\Font\EmbeddedFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Crypt\DecryptionException;
use MightyPDF\Editor\PdfEditor;
use MightyPDF\Exception\PdfException;
use MightyPDF\Reader\ParseException;
use PHPUnit\Framework\TestCase;

/**
 * One catch for "the PDF layer failed", which is what the marker
 * interface exists to make sayable -- and, just as much, the guarantee
 * that saying it the old way still works.
 */
final class PdfExceptionTest extends TestCase
{
    /**
     * The library's own types and the ones standing in for PHP's are all
     * catchable together. Assembled from four different corners of the
     * library on purpose: the point of a marker is that it holds across
     * the parts that have nothing else in common.
     */
    public function testEverythingThisLibraryThrowsIsCatchableAsOne(): void
    {
        $caught = [];

        foreach (self::failures() as $what => $failing) {
            try {
                $failing();
            } catch (PdfException) {
                $caught[] = $what;

                continue;
            }

            self::fail("$what did not throw something catchable as a PdfException");
        }

        // Counted rather than merely not-failed, so that a failures()
        // list that quietly stopped yielding anything could not pass.
        self::assertSame(array_keys(iterator_to_array(self::failures())), $caught);
    }

    /**
     * The whole reason the classes extend the SPL exceptions they
     * replace rather than merely implementing the marker: code written
     * against this library before any of this existed keeps working. If
     * this test ever fails, the change stopped being additive.
     */
    public function testTheOldCatchesStillWork(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Document())->attach('', 'x');
    }

    public function testALogicErrorIsStillALogicException(): void
    {
        $this->expectException(\LogicException::class);

        $document = new Document();
        $document->encrypt('owner');
        $document->encrypt('owner');
    }

    public function testARuntimeFailureIsStillARuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);

        (new Document())->saveToFile(__DIR__ . '/no-such-directory/out.pdf');
    }

    /** The domain types keep their own identity as well as the marker. */
    public function testTheDomainExceptionsAreStillThemselves(): void
    {
        try {
            PdfEditor::fromBytes('not a PDF at all');
            self::fail('expected the parse to fail');
        } catch (ParseException $failure) {
            self::assertInstanceOf(PdfException::class, $failure);
            self::assertInstanceOf(\RuntimeException::class, $failure);
        }
    }

    /**
     * Each of these exists to fail. They are uniformly void closures
     * that discard whatever the call would have returned, rather than
     * arrow functions typed to whatever each one happens to hand back --
     * the return value is not what is under test, and typing for it
     * made the list say something about itself it does not mean.
     *
     * @return iterable<string, \Closure(): void>
     */
    private static function failures(): iterable
    {
        yield 'an empty attachment name' => static function (): void {
            (new Document())->attach('', 'x');
        };

        yield 'a stream sink with no stream' => static function (): void {
            // @phpstan-ignore-next-line -- the point of the case
            new StreamSink('/not/a/handle');
        };

        yield 'encrypting twice' => static function (): void {
            $document = new Document();
            $document->encrypt('owner');
            $document->encrypt('owner');
        };

        yield 'an unwritable path' => static function (): void {
            (new Document())->saveToFile(__DIR__ . '/no-such-directory/out.pdf');
        };

        yield 'opening something that is not a PDF' => static function (): void {
            PdfEditor::fromBytes('not a PDF at all');
        };

        yield 'a font that is not a font' => static function (): void {
            EmbeddedFont::fromBytes('not a font');
        };

        yield 'a rotation that is not a right angle' => static function (): void {
            $document = new Document();
            $document->newPage()->setRotation(45);
        };

        yield 'drawing an image that is not one' => static function (): void {
            $document = new Document();
            $builder = new PageBuilder($document, $document->newPage());
            $builder->drawJpeg(__FILE__, 0, 0, 10, 10);
        };
    }

    public function testAWrongPasswordIsAlsoOne(): void
    {
        $document = new Document();
        $document->newPage();
        $document->encrypt(ownerPassword: 'owner', userPassword: 'user');

        try {
            PdfEditor::fromBytes($document->save(), 'wrong');
            self::fail('expected the password to be refused');
        } catch (DecryptionException $failure) {
            self::assertInstanceOf(PdfException::class, $failure);
        }
    }
}
