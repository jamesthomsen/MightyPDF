<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\Trailer;
use MightyPDF\Assembler\Types\PdfArray;
use MightyPDF\Assembler\Types\PdfHexString;
use PHPUnit\Framework\TestCase;

final class TrailerTest extends TestCase
{
    public function testBuildsMinimalTrailer(): void
    {
        $trailer = Trailer::forNewDocument(size: 4, rootObjectId: 1);

        self::assertSame("trailer\n<< /Size 4 /Root 1 0 R >>\n", $trailer->build());
    }

    public function testIncludesInfoWhenProvided(): void
    {
        $trailer = Trailer::forNewDocument(size: 4, rootObjectId: 1, infoObjectId: 7);

        self::assertSame("trailer\n<< /Size 4 /Root 1 0 R /Info 7 0 R >>\n", $trailer->build());
    }

    public function testIncludesIdWhenProvided(): void
    {
        $id = new PdfArray(new PdfHexString('abc'), new PdfHexString('abc'));
        $trailer = Trailer::forNewDocument(size: 4, rootObjectId: 1, id: $id);

        self::assertSame(
            "trailer\n<< /Size 4 /Root 1 0 R /ID [<616263> <616263>] >>\n",
            $trailer->build(),
        );
    }
}
