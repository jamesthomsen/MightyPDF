<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Document;
use MightyPDF\Assembler\Duplex;
use MightyPDF\Assembler\PageLayout;
use MightyPDF\Assembler\PageMode;
use MightyPDF\Assembler\PrintScaling;
use PHPUnit\Framework\TestCase;

final class ViewerPreferencesTest extends TestCase
{
    /**
     * A document that asks for nothing should say nothing -- an empty
     * /ViewerPreferences is worse than an absent one, since it is a
     * dictionary a reader has to read to learn it holds no requests.
     */
    public function testADocumentThatAsksForNothingCarriesNoViewerPreferences(): void
    {
        $document = new Document();
        $document->newPage();

        self::assertStringNotContainsString('/ViewerPreferences', $document->save());
    }

    public function testAskingForOneWiresItIntoTheCatalog(): void
    {
        $document = new Document();
        $document->newPage();
        $document->viewerPreferences()->displayDocumentTitle();

        $output = $document->save();

        self::assertStringContainsString('/ViewerPreferences', $output);
        self::assertStringContainsString('/DisplayDocTitle true', $output);
    }

    public function testTheSameDictionaryComesBackEachTime(): void
    {
        $document = new Document();

        self::assertSame($document->viewerPreferences(), $document->viewerPreferences());
    }

    public function testThePreferencesChain(): void
    {
        $document = new Document();
        $document->newPage();

        $document->viewerPreferences()
            ->displayDocumentTitle()
            ->fitWindow()
            ->centerWindow()
            ->hideToolbar()
            ->hideMenubar()
            ->hideWindowUi()
            ->pickTrayByPageSize()
            ->printScaling(PrintScaling::None)
            ->duplex(Duplex::DuplexFlipLongEdge)
            ->nonFullScreenPageMode(PageMode::Thumbnails)
            ->numberOfCopies(3);

        $output = $document->save();

        foreach ([
            '/DisplayDocTitle true',
            '/FitWindow true',
            '/CenterWindow true',
            '/HideToolbar true',
            '/HideMenubar true',
            '/HideWindowUI true',
            '/PickTrayByPDFSize true',
            '/PrintScaling /None',
            '/Duplex /DuplexFlipLongEdge',
            '/NonFullScreenPageMode /UseThumbs',
            '/NumCopies 3',
        ] as $entry) {
            self::assertStringContainsString($entry, $output);
        }
    }

    /**
     * False is written rather than omitted: a reader's own default for
     * some of these is not false, so "do not do this" has to be said
     * rather than implied by silence.
     */
    public function testFalseIsWrittenRatherThanOmitted(): void
    {
        $document = new Document();
        $document->newPage();
        $document->viewerPreferences()->fitWindow(false);

        self::assertStringContainsString('/FitWindow false', $document->save());
    }

    public function testAskingForFewerThanOneCopyIsRefused(): void
    {
        $document = new Document();

        $this->expectException(\InvalidArgumentException::class);

        $document->viewerPreferences()->numberOfCopies(0);
    }

    public function testPageLayoutAndPageModeGoOnTheCatalog(): void
    {
        $document = new Document();
        $document->newPage();
        $document->setPageLayout(PageLayout::TwoPageRight);
        $document->setPageMode(PageMode::Thumbnails);

        $output = $document->save();

        self::assertStringContainsString('/PageLayout /TwoPageRight', $output);
        self::assertStringContainsString('/PageMode /UseThumbs', $output);
    }

    /**
     * A document with bookmarks still asks for the bookmark panel when it
     * has said nothing else -- which is the behaviour outline() has
     * always had.
     */
    public function testABookmarkedDocumentStillAsksForItsPanelByDefault(): void
    {
        $document = new Document();
        $document->newPage();
        $document->outline();

        self::assertStringContainsString('/PageMode /UseOutlines', $document->save());
    }
}
