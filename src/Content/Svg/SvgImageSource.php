<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

/**
 * Reads the bytes an `<image>` element points at.
 *
 * **Only data: URIs are followed.** An SVG is a document that may have
 * arrived from anywhere -- the parser is already hardened against XML
 * external entities for that reason (see SvgDocument) -- and an
 * `<image href="/etc/passwd">` or `<image href="https://…">` is the same
 * class of problem wearing different clothes: it asks this library to
 * read something the caller never named and put it in a document that
 * may be sent on. A file path or URL here is skipped, exactly as an
 * unsupported element is.
 *
 * That is not much of a restriction in practice: an SVG carrying a raster
 * image almost always carries it inline, since a file reference stops the
 * drawing being one self-contained file.
 */
final class SvgImageSource
{
    /**
     * Rejects a data URI whose payload is larger than this before
     * decoding it. Base64 inflates by a third, so this bounds the
     * decoded result too -- and an SVG with a 64MB image in it is not a
     * drawing, it is a way to spend somebody's memory.
     */
    private const int MAX_ENCODED_BYTES = 64 * 1024 * 1024;

    private function __construct()
    {
    }

    /** The image bytes, or null where there is nothing this may safely read. */
    public static function bytes(string $href): ?string
    {
        $href = trim($href);

        if (!str_starts_with(strtolower($href), 'data:')) {
            return null;
        }

        $comma = strpos($href, ',');

        if ($comma === false || strlen($href) > self::MAX_ENCODED_BYTES) {
            return null;
        }

        $header = strtolower(substr($href, 5, $comma - 5));
        $payload = substr($href, $comma + 1);

        if (str_contains($header, ';base64')) {
            // Strict: a data URI that is not valid base64 is corrupt,
            // and decoding it loosely would hand the image decoders
            // bytes that are not the image anyone meant.
            $decoded = base64_decode($payload, true);

            return $decoded === false ? null : $decoded;
        }

        return rawurldecode($payload);
    }
}
