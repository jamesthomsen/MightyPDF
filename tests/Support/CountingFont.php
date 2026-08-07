<?php

declare(strict_types=1);

namespace MightyPDF\Tests\Support;

use MightyPDF\Assembler\DocumentContext;
use MightyPDF\Content\Font\Font;
use MightyPDF\Content\Font\FontWriter;

/**
 * A Font that delegates everything and counts what it was asked to
 * measure.
 *
 * For the tests that are about *how much* measuring a routine does
 * rather than what it produces. Counting is the deterministic way to
 * ask that: a wall-clock assertion for the same property passes or
 * fails on how loaded the machine is, and the quadratic it is guarding
 * against is a statement about work done, not about seconds.
 *
 * Both counters matter and they are not the same question. A wrap that
 * measures every prefix of a line makes *fewer* calls than one that
 * measures each word once -- it is the length of each call's argument
 * that grows, so $measuredBytes is what shows the quadratic and $calls
 * is what shows a cache hit.
 */
final class CountingFont implements Font
{
    public int $calls = 0;

    public int $measuredBytes = 0;

    public function __construct(private readonly Font $font)
    {
    }

    /**
     * Distinct per instance, so that a memo keyed on this cannot serve
     * one test's lines to another's identical-looking font.
     */
    public function cacheKey(): string
    {
        return 'counting:' . spl_object_id($this) . ':' . $this->font->cacheKey();
    }

    public function widthOfPt(string $utf8Text, float $sizePt): float
    {
        $this->calls++;
        $this->measuredBytes += strlen($utf8Text);

        return $this->font->widthOfPt($utf8Text, $sizePt);
    }

    public function supports(string $utf8Text): bool
    {
        return $this->font->supports($utf8Text);
    }

    /** @return list<string> */
    public function missingCharacters(string $utf8Text): array
    {
        return $this->font->missingCharacters($utf8Text);
    }

    public function ascentPt(float $sizePt): float
    {
        return $this->font->ascentPt($sizePt);
    }

    public function descentPt(float $sizePt): float
    {
        return $this->font->descentPt($sizePt);
    }

    public function capHeightPt(float $sizePt): float
    {
        return $this->font->capHeightPt($sizePt);
    }

    public function writerFor(DocumentContext $document): FontWriter
    {
        return $this->font->writerFor($document);
    }
}
