<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\PdfObject;
use PHPUnit\Framework\TestCase;

final class PdfObjectTest extends TestCase
{
    public function testBareRenderReturnsContentUnwrapped(): void
    {
        $object = $this->fakeObject(1, '<< /Type /Catalog >>');
        self::assertSame('<< /Type /Catalog >>', $object->render(false));
    }

    public function testIndirectRenderWrapsInObjEndobj(): void
    {
        $object = $this->fakeObject(5, '<< /Type /Catalog >>');
        self::assertSame("5 0 obj\n<< /Type /Catalog >>\nendobj\n", $object->render(true));
    }

    public function testIndirectRenderHasNoLeadingNewlineBeforeObj(): void
    {
        // This is the property that eliminates the old "+1 offset fixup"
        // hack: whatever records this object's byte offset can point
        // directly at the start of the returned string.
        $object = $this->fakeObject(1, 'x');
        self::assertStringStartsWith('1 0 obj', $object->render(true));
    }

    public function testStreamShapedContentNeedsNoSpecialMethodToBecomeIndirect(): void
    {
        // Regression test for the 2012 bug: MightyPDF_Stream::build()
        // called a commented-out, never-implemented asIndirectObject()
        // method, so writing any actual page content was a fatal error.
        // A subclass whose content() happens to contain "stream"/
        // "endstream" text must still become indirect through the exact
        // same render() path as any other object -- there is no second
        // mechanism to forget to implement.
        $object = $this->fakeObject(9, "<< /Length 5 >>\nstream\nhello\nendstream");

        self::assertSame(
            "9 0 obj\n<< /Length 5 >>\nstream\nhello\nendstream\nendobj\n",
            $object->render(true),
        );
    }

    public function testObjectIdAccessor(): void
    {
        self::assertSame(42, $this->fakeObject(42, 'x')->objectId());
    }

    public function testFormatIsAnAliasForBareRender(): void
    {
        // This is what lets any PdfObject (a Dictionary, say) be nested
        // as a plain inline value inside another dictionary's entries,
        // e.g. a Page's /Resources sub-dictionary.
        $object = $this->fakeObject(1, '<< /Font << /F1 5 0 R >> >>');
        self::assertSame($object->render(false), $object->format());
    }

    public function testObjectWithNoIdCanStillBeUsedAsAnInlineValue(): void
    {
        $object = $this->fakeInlineObject('<< >>');
        self::assertSame('<< >>', $object->format());
    }

    public function testObjectWithNoIdThrowsIfRenderedIndirectly(): void
    {
        $object = $this->fakeInlineObject('<< >>');

        $this->expectException(\LogicException::class);
        $object->render(true);
    }

    private function fakeObject(int $objectId, string $content): PdfObject
    {
        return new class ($objectId, $content) extends PdfObject {
            public function __construct(int $objectId, private readonly string $fakeContent)
            {
                parent::__construct($objectId);
            }

            protected function content(): string
            {
                return $this->fakeContent;
            }
        };
    }

    private function fakeInlineObject(string $content): PdfObject
    {
        return new class ($content) extends PdfObject {
            public function __construct(private readonly string $fakeContent)
            {
                parent::__construct();
            }

            protected function content(): string
            {
                return $this->fakeContent;
            }
        };
    }
}
