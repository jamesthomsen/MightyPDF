<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Assembler;

use MightyPDF\Assembler\DocumentInfo;
use PHPUnit\Framework\TestCase;

final class DocumentInfoTest extends TestCase
{
    public function testSetTitleAddsATitleEntry(): void
    {
        $info = new DocumentInfo(1);
        $info->setTitle('Quarterly Report');

        self::assertSame('<< /Title (Quarterly Report) >>', $info->render(false));
    }

    public function testEverySettableFieldRoundTripsThroughRender(): void
    {
        $info = new DocumentInfo(1);
        $info->setTitle('Title');
        $info->setAuthor('Author');
        $info->setSubject('Subject');
        $info->setKeywords('one, two');
        $info->setCreator('Creator');
        $info->setProducer('Producer');

        $rendered = $info->render(false);

        self::assertStringContainsString('/Title (Title)', $rendered);
        self::assertStringContainsString('/Author (Author)', $rendered);
        self::assertStringContainsString('/Subject (Subject)', $rendered);
        self::assertStringContainsString('/Keywords (one, two)', $rendered);
        self::assertStringContainsString('/Creator (Creator)', $rendered);
        self::assertStringContainsString('/Producer (Producer)', $rendered);
    }

    public function testNonAsciiTitleIsEncodedAsUtf16beWithBom(): void
    {
        $info = new DocumentInfo(1);
        $info->setTitle('Zoë');

        $expected = "\xFE\xFF" . iconv('UTF-8', 'UTF-16BE', 'Zoë');
        self::assertSame('<< /Title (' . self::escapeForLiteral($expected) . ') >>', $info->render(false));
    }

    public function testCreationDateFormatsWithAPositiveOffset(): void
    {
        $info = new DocumentInfo(1);
        $info->setCreationDate(new \DateTimeImmutable('2026-08-01 14:30:00', new \DateTimeZone('+02:00')));

        self::assertSame("<< /CreationDate (D:20260801143000+02'00') >>", $info->render(false));
    }

    public function testCreationDateFormatsWithANegativeOffset(): void
    {
        $info = new DocumentInfo(1);
        $info->setCreationDate(new \DateTimeImmutable('2026-08-01 14:30:00', new \DateTimeZone('-05:00')));

        self::assertSame("<< /CreationDate (D:20260801143000-05'00') >>", $info->render(false));
    }

    public function testCreationDateAtUtcUsesTheBareZSuffix(): void
    {
        $info = new DocumentInfo(1);
        $info->setCreationDate(new \DateTimeImmutable('2026-08-01 14:30:00', new \DateTimeZone('UTC')));

        self::assertSame('<< /CreationDate (D:20260801143000Z) >>', $info->render(false));
    }

    private static function escapeForLiteral(string $bytes): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $bytes);
    }
}
