<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\PageSize;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Tests\Support\SavedDocument;
use PHPUnit\Framework\TestCase;

final class PageRotationTest extends TestCase
{
    /**
     * An unrotated page has no /Rotate at all. Writing 0 explicitly is
     * legal and is noise on every page of every document this library has
     * ever produced.
     */
    public function testAnUnrotatedPageCarriesNoRotateEntry(): void
    {
        $document = new Document();
        $document->newPage();

        // Asked of the page as a reader sees it, inherited entries
        // included -- /Rotate is inheritable, so "not on the page
        // dictionary" and "not in effect for the page" are different
        // claims and only the second one matters.
        self::assertNull(SavedDocument::of($document)->pageEntry(0, 'Rotate'));
        self::assertSame(0, $document->pages()[0]->rotation());
    }

    public function testAPageCanBeTurnedWhenItIsCreated(): void
    {
        $document = new Document();
        $page = $document->newPage(PageSize::A4, rotation: 90);

        self::assertSame(90, $page->rotation());
        self::assertSame(90, SavedDocument::scalar(SavedDocument::of($document)->pageEntry(0, 'Rotate')));
    }

    public function testAPageCanBeTurnedAfterwards(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $page->setRotation(180);

        self::assertSame(180, $page->rotation());
    }

    public function testTurningBackToZeroRemovesTheEntry(): void
    {
        $document = new Document();
        $page = $document->newPage();
        $page->setRotation(90);
        $page->setRotation(0);

        self::assertNull(SavedDocument::of($document)->pageEntry(0, 'Rotate'));
    }

    public function testRotationsAreNormalisedIntoZeroToTwoSeventy(): void
    {
        $document = new Document();

        foreach ([-90 => 270, 450 => 90, 360 => 0, -360 => 0, 720 => 0, -270 => 90] as $given => $expected) {
            $page = $document->newPage();
            $page->setRotation($given);

            self::assertSame($expected, $page->rotation(), "$given degrees");
        }
    }

    public function testAnglesThatAreNotMultiplesOfNinetyAreRefused(): void
    {
        $document = new Document();
        $page = $document->newPage();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('multiples of 90 degrees');

        $page->setRotation(45);
    }

    /**
     * Rotation turns the page as displayed and leaves the coordinate
     * system alone, so anything already drawn keeps the coordinates it
     * was drawn at. That is what makes it right for a page that arrived
     * the wrong way up and wrong for a landscape report -- which wants a
     * landscape media box instead.
     */
    public function testRotationDoesNotMoveWhatWasAlreadyDrawn(): void
    {
        $document = new Document();
        $page = $document->newPage(PageSize::A4);

        (new PageBuilder($document, $page))->drawText(StandardFont::Helvetica, 12.0, 72.0, 700.0, 'x');
        $page->setRotation(90);

        // The content stream itself, which really is a sequence of
        // operator bytes -- so this one stays a string match, taken from
        // the saved file rather than from the object still in hand.
        self::assertStringContainsString('1 0 0 1 72 700 Tm', SavedDocument::of($document)->contentOf(0));
    }
}
