<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Output;

use MightyPDF\Output\PdfResponse;
use PHPUnit\Framework\TestCase;

/**
 * Headers are asserted rather than sent, which is why building them is
 * split from send(): a test that had to send would need a web server or
 * an output buffer, and the interesting part -- what an arbitrary
 * filename turns into -- would go untested.
 */
final class PdfResponseTest extends TestCase
{
    public function testInlineCarriesTheTypeLengthAndDisposition(): void
    {
        $headers = PdfResponse::inline('%PDF-1.7 ...', 'scorecard.pdf')->headers();

        self::assertSame('application/pdf', $headers['Content-Type']);
        self::assertSame('inline; filename="scorecard.pdf"', $headers['Content-Disposition']);
        self::assertSame('12', $headers['Content-Length']);
    }

    public function testAttachmentAsksForADownload(): void
    {
        $headers = PdfResponse::attachment('%PDF', 'report.pdf')->headers();

        self::assertSame('attachment; filename="report.pdf"', $headers['Content-Disposition']);
    }

    public function testContentLengthCountsBytesRatherThanCharacters(): void
    {
        // A PDF is binary, so a multi-byte sequence in it is two bytes
        // and not one character -- a Content-Length off by one truncates
        // the file in the browser.
        $headers = PdfResponse::inline("\xC3\xA9\xC3\xA9")->headers();

        self::assertSame('4', $headers['Content-Length']);
    }

    /**
     * A filename usually comes from a record ("Scorecard for Acme Ltd"),
     * which puts untrusted text in a header value. A newline there ends
     * the header and starts another one, and a response carrying an
     * attacker's Set-Cookie is not a formatting problem.
     */
    public function testANewlineInTheFilenameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('header-injection');

        PdfResponse::inline('%PDF', "report.pdf\r\nSet-Cookie: admin=1");
    }

    public function testANulByteInTheFilenameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PdfResponse::inline('%PDF', "report\x00.pdf");
    }

    public function testAnEmptyFilenameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        PdfResponse::inline('%PDF', '   ');
    }

    /** A quote would otherwise close the quoted-string early. */
    public function testQuotesAndBackslashesAreEscaped(): void
    {
        $headers = PdfResponse::inline('%PDF', 'the "big" report.pdf')->headers();

        self::assertSame('inline; filename="the \"big\" report.pdf"', $headers['Content-Disposition']);
    }

    /**
     * RFC 6266 §4.3: send both forms. Readers that understand
     * filename* use it and get the real name; the rest fall back to the
     * ASCII one, which keeps the shape of the name rather than losing a
     * word to a dropped character.
     */
    public function testANonAsciiFilenameAlsoGoesOutInRfc5987Form(): void
    {
        $disposition = PdfResponse::attachment('%PDF', 'Rapport financier — 2026.pdf')->headers()['Content-Disposition'];

        self::assertStringContainsString('filename="Rapport financier _ 2026.pdf"', $disposition);
        self::assertStringContainsString(
            "filename*=UTF-8''Rapport%20financier%20%E2%80%94%202026.pdf",
            $disposition,
        );
    }

    public function testAnAsciiFilenameGetsNoRedundantSecondForm(): void
    {
        $disposition = PdfResponse::inline('%PDF', 'plain.pdf')->headers()['Content-Disposition'];

        self::assertStringNotContainsString('filename*', $disposition);
    }

    /**
     * A PDF built from live data is not the one built from it an hour
     * ago, and a cached invoice is indistinguishable from a current one.
     */
    public function testTheResponseAsksNotToBeCached(): void
    {
        self::assertStringContainsString('must-revalidate', PdfResponse::inline('%PDF')->headers()['Cache-Control']);
    }

    public function testBodyIsTheBytesItWasGiven(): void
    {
        self::assertSame('%PDF-1.7 body', PdfResponse::inline('%PDF-1.7 body')->body());
    }
    /**
     * The filename comes from a record and the bytes can come from
     * user-supplied content, which is the pair that makes letting a
     * browser decide for itself what this is worth turning off.
     */
    public function testTheResponseRefusesContentSniffing(): void
    {
        self::assertSame('nosniff', PdfResponse::inline('%PDF')->headers()['X-Content-Type-Options']);
    }
}
