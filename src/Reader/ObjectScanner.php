<?php

declare(strict_types=1);

namespace MightyPDF\Reader;

/**
 * Locates indirect objects by brute-force scanning for "N G obj", as a
 * fallback for when the cross-reference table is missing or lying.
 *
 * This is not a nicety. Stale or wrong xref offsets are one of the most
 * common defects in real PDFs -- any tool that appends bytes without
 * rewriting the table produces them -- and a reader that trusts the xref
 * absolutely simply cannot open a large slice of the files people actually
 * have. Every robust reader carries a scavenger; this is ours.
 *
 * The index is built once, lazily, and only if something actually needs
 * it: a well-formed file never touches this class at all.
 */
final class ObjectScanner
{
    /**
     * Object number, generation, then "obj" -- separated by any run of
     * PDF white space, and preceded by white space or start-of-line so a
     * digit sequence inside a longer token cannot match.
     */
    private const string PATTERN = '/(?:^|[\x00\t\r\n\f ])(\d+)[\x00\t\r\n\f ]+(\d+)[\x00\t\r\n\f ]+obj\b/m';

    /** @var array<int, int>|null objectId => byte offset */
    private ?array $index = null;

    public function __construct(private readonly string $bytes)
    {
    }

    public function offsetOf(int $objectId): ?int
    {
        $this->index ??= $this->scan();

        return $this->index[$objectId] ?? null;
    }

    /** @return array<int, int> */
    private function scan(): array
    {
        $index = [];

        if (preg_match_all(self::PATTERN, $this->bytes, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $index;
        }

        foreach ($matches[1] as $match) {
            // Later wins. In an incrementally updated file the last
            // definition of an object id is the current one, so this is
            // the correct rule and not merely a tie-break -- and it also
            // happens to be the safer answer when an earlier "match" was
            // really a false positive inside compressed stream data.
            $index[(int) $match[0]] = (int) $match[1];
        }

        return $index;
    }
}
