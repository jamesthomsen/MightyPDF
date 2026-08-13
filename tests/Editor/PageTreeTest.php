<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Editor;

use MightyPDF\Editor\PageTree;
use MightyPDF\Editor\PdfEditor;
use PHPUnit\Framework\TestCase;

/**
 * The walk itself, on trees that a well-behaved producer does not make and
 * a hostile file does.
 *
 * Every entry point that opens somebody else's PDF goes through here --
 * PageSelection, PdfMerger, FormFlattener, TextExtractor, DeferredSignature
 * -- so what this guards against, it guards against for all of them.
 */
final class PageTreeTest extends TestCase
{
    public function testWalksAnOrdinaryTree(): void
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 2 /Kids [3 0 R 4 0 R] >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>',
            4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>',
        ];

        self::assertSame(2, self::treeFor(self::assemble($objects))->count());
    }

    /**
     * The one that matters. A node listing the same child twice is not a
     * cycle -- no node is its own ancestor -- so a per-branch "seen" set
     * never fires, and every shared node is walked once per path that
     * reaches it. Sixty-three levels of that is 2^63 out of a file of a
     * few kilobytes.
     *
     * Asserted on the answer rather than on the clock: the tree has one
     * leaf in it however many paths lead there, so a walk that reports one
     * page is a walk that visited each node once. A regression here does
     * not fail this test, it never finishes it.
     */
    public function testAPageReachedByManyPathsIsWalkedOnce(): void
    {
        self::assertSame(1, self::treeFor(self::sharedChildTree(20))->count());
    }

    /**
     * The same shape one level at a time, to show the cost is flat rather
     * than merely survivable at one depth: doubling the depth of a tree
     * whose every level branches must not double anything.
     */
    public function testTheCostOfASharedChildTreeDoesNotGrowWithItsDepth(): void
    {
        foreach ([4, 12, 24, 48] as $levels) {
            self::assertSame(
                1,
                self::treeFor(self::sharedChildTree($levels))->count(),
                "a $levels-level tree sharing every child still has one page in it",
            );
        }
    }

    /** A tree that points back at an ancestor still terminates. */
    public function testACycleIsNotFollowed(): void
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            3 => '<< /Type /Pages /Count 1 /Kids [2 0 R 4 0 R] >>',
            4 => '<< /Type /Page /Parent 3 0 R /MediaBox [0 0 612 792] >>',
        ];

        self::assertSame(1, self::treeFor(self::assemble($objects))->count());
    }

    /**
     * Every intermediate node lists the same child twice, so there are
     * 2^$levels paths from the root to the single leaf and no cycle
     * anywhere.
     */
    private static function sharedChildTree(int $levels): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];

        for ($i = 0; $i < $levels; ++$i) {
            $id = 2 + $i;
            $kid = $id + 1;

            $objects[$id] = "<< /Type /Pages /Count 2 /Kids [$kid 0 R $kid 0 R] >>";
        }

        $objects[2 + $levels] = '<< /Type /Page /MediaBox [0 0 612 792] >>';

        return self::assemble($objects);
    }

    private static function treeFor(string $pdf): PageTree
    {
        return new PageTree(PdfEditor::fromBytes($pdf));
    }

    /** @param array<int, string> $objects */
    private static function assemble(array $objects): string
    {
        ksort($objects);

        $out = "%PDF-1.7\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $highest = max(array_keys($objects));

        $out .= "xref\n0 " . ($highest + 1) . "\n0000000000 65535 f \n";

        for ($id = 1; $id <= $highest; ++$id) {
            $out .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                : "0000000000 65535 f \n";
        }

        return $out
            . "trailer\n<< /Size " . ($highest + 1) . " /Root 1 0 R >>\n"
            . "startxref\n{$xrefOffset}\n%%EOF\n";
    }
}
