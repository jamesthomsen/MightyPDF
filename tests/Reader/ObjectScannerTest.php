<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Reader;

use MightyPDF\Reader\ObjectScanner;
use PHPUnit\Framework\TestCase;

final class ObjectScannerTest extends TestCase
{
    public function testFindsObjectsByScanning(): void
    {
        $bytes = "%PDF-1.7\n1 0 obj\n<< >>\nendobj\n12 0 obj\n<< >>\nendobj\n";

        $scanner = new ObjectScanner($bytes);

        self::assertSame(9, $scanner->offsetOf(1));
        self::assertSame(strpos($bytes, '12 0 obj'), $scanner->offsetOf(12));
    }

    public function testReturnsNullForAnObjectThatIsNotThere(): void
    {
        self::assertNull((new ObjectScanner("1 0 obj\n<< >>\nendobj\n"))->offsetOf(2));
    }

    public function testAcceptsAnyWhitespaceBetweenTheParts(): void
    {
        self::assertSame(0, (new ObjectScanner("4\r\n0\tobj\n<< >>\nendobj")) ->offsetOf(4));
    }

    public function testDoesNotMatchADigitRunInsideALongerToken(): void
    {
        // "R12 0 obj" is not an object header, and treating it as one
        // would hand back an offset that parses into nonsense.
        self::assertNull((new ObjectScanner('R12 0 obj'))->offsetOf(12));
    }

    public function testDoesNotMatchAKeywordThatMerelyStartsWithObj(): void
    {
        self::assertNull((new ObjectScanner("1 0 object\n"))->offsetOf(1));
    }

    public function testTheLastDefinitionWins(): void
    {
        // In an incrementally updated file the newest definition of an
        // object id is the current one, so later must beat earlier.
        $bytes = "1 0 obj\n<< /V 1 >>\nendobj\n1 0 obj\n<< /V 2 >>\nendobj\n";

        self::assertSame(strrpos($bytes, '1 0 obj'), (new ObjectScanner($bytes))->offsetOf(1));
    }

    public function testScansBinaryDataWithoutTripping(): void
    {
        $bytes = "1 0 obj\n<< /Length 8 >>\nstream\n" . "\x00\xFF\x80\x0A\x0D\x25\x28\x29" . "\nendstream\nendobj\n";

        self::assertSame(0, (new ObjectScanner($bytes))->offsetOf(1));
    }
}
