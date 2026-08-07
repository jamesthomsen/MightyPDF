<?php

declare(strict_types=1);

namespace MightyPDF\Output;

/**
 * A finished PDF as an HTTP response: the four headers every web
 * consumer writes by hand, written once.
 *
 * The headers are built separately from sending them so that the
 * interesting part -- what a filename turns into -- is testable without
 * output buffering or a running web server.
 *
 * A filename is usually taken from a record ("Scorecard for Acme Ltd"),
 * which makes it untrusted input in a header value, so:
 *
 * - a carriage return, newline or NUL is refused outright. Those are
 *   what split one header into two, and a response with an attacker's
 *   Set-Cookie in it is not a formatting problem.
 * - quotes and backslashes are escaped, since the ASCII form is a
 *   quoted-string.
 * - anything outside ASCII is also emitted as RFC 5987 `filename*`,
 *   which is how "Rapport financier — 2026.pdf" survives. The plain
 *   `filename` stays as an ASCII transliteration for readers that
 *   predate it; RFC 6266 §4.3 says to send both and says which wins.
 */
final class PdfResponse
{
    private function __construct(
        private readonly string $bytes,
        private readonly string $disposition,
        private readonly string $filename,
    ) {
    }

    /** Shown in the browser's viewer. */
    public static function inline(string $pdfBytes, string $filename = 'document.pdf'): self
    {
        return new self($pdfBytes, 'inline', self::validated($filename));
    }

    /** Offered as a download. */
    public static function attachment(string $pdfBytes, string $filename = 'document.pdf'): self
    {
        return new self($pdfBytes, 'attachment', self::validated($filename));
    }

    /** @return array<string, string> header name => value */
    public function headers(): array
    {
        return [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $this->contentDisposition(),
            'Content-Length' => (string) strlen($this->bytes),
            // A PDF built from live data is not the PDF that was built
            // from it an hour ago, and a browser that cached the first
            // one shows a stale invoice with no way for anyone to tell.
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            // The filename comes from a record and the bytes can come
            // from user-supplied content, which is the pair that makes
            // content sniffing worth turning off: a browser that decides
            // for itself what this is can be talked into treating a
            // document served from this origin as something it executes.
            // Nothing legitimate here needs a PDF to be read as anything
            // but a PDF.
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    public function body(): string
    {
        return $this->bytes;
    }

    /**
     * Sends the headers and the body.
     *
     * Refuses if headers have already gone out, because the alternative
     * is a PDF echoed into the middle of an HTML page -- a stream of
     * mojibake that looks like a corrupt file rather than like the
     * `echo` that caused it.
     */
    public function send(): void
    {
        if (headers_sent($file, $line)) {
            throw new \RuntimeException(
                "Cannot send a PDF response: output already started at $file line $line.",
            );
        }

        foreach ($this->headers() as $name => $value) {
            header("$name: $value");
        }

        echo $this->bytes;
    }

    private function contentDisposition(): string
    {
        $escaped = addcslashes($this->filename, '"\\');
        $disposition = sprintf('%s; filename="%s"', $this->disposition, self::toAscii($escaped));

        if (preg_match('/^[\x20-\x7E]*$/', $this->filename) === 1) {
            return $disposition;
        }

        return $disposition . "; filename*=UTF-8''" . rawurlencode($this->filename);
    }

    /**
     * The ASCII fallback: anything non-ASCII becomes '_' rather than
     * being dropped, so "Rapport financier — 2026.pdf" saves as
     * something with the right shape instead of losing a word.
     */
    private static function toAscii(string $filename): string
    {
        return (string) preg_replace('/[^\x20-\x7E]+/', '_', $filename);
    }

    private static function validated(string $filename): string
    {
        if (preg_match('/[\r\n\x00]/', $filename) === 1) {
            throw new \InvalidArgumentException(
                'A PDF filename cannot contain a carriage return, newline or NUL byte -- '
                . 'those end the header, and a filename that carries them is a header-injection attempt.',
            );
        }

        if (trim($filename) === '') {
            throw new \InvalidArgumentException('A PDF filename cannot be empty.');
        }

        return $filename;
    }
}
