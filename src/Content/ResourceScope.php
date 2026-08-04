<?php

declare(strict_types=1);

namespace MightyPDF\Content;

use MightyPDF\Assembler\Dictionary;

/**
 * One /Resources dictionary and the naming that goes with it: which
 * names have been handed out, and what each font, pattern and graphics
 * state is already called here.
 *
 * A resource name means nothing on its own -- /F1 is whatever the
 * dictionary in scope says it is -- so the name, the counter that
 * produced it and the dictionary it resolves in are one thing, and this
 * is that thing. PageBuilder held all three as loose properties for as
 * long as there was only ever one of them: the page's.
 *
 * There is now a second. An SVG placed on a page is rendered into a form
 * XObject of its own so that placing it twice costs one drawing (see
 * PageBuilder::drawSvg()), and an XObject carries its own /Resources --
 * it has to, since the whole point is that it can be invoked from a page
 * that has never seen the fonts and gradients inside it. Rendering into
 * it is then just "name things in that scope instead of this one", which
 * is a swap of one object rather than a second copy of every naming
 * method.
 *
 * The objects being named stay document-scoped throughout. Only the
 * names are scoped here -- the same font may well be /F1 in one scope
 * and /F3 in another while both point at the one font object the
 * document registered.
 */
final class ResourceScope
{
    /** @var array<string, string> Font::cacheKey() => resource name (e.g. "F1"), for /Resources /Font */
    public array $fontResourceNames = [];

    public int $nextFontResourceNumber = 1;
    public int $nextImageResourceNumber = 1;
    public int $nextPatternResourceNumber = 1;

    /** @var array<string, string> "fillAlpha:strokeAlpha" => resource name (e.g. "GS1") */
    public array $extGStateResourceNames = [];

    public int $nextExtGStateResourceNumber = 1;

    /**
     * A tiling pattern is shared by every shape it paints the same way --
     * see PageBuilder::tilingPatternResourceName() for what "the same
     * way" is.
     *
     * @var array<string, string> tile, matrix and content => resource name
     */
    public array $tilingPatternResourceNames = [];

    public function __construct(public readonly Dictionary $resources)
    {
    }

    /**
     * The dictionary of one resource category, created on first use.
     *
     * Created lazily because an empty category is worse than an absent
     * one: a page that draws nothing but text has no business declaring
     * an empty /Pattern.
     */
    public function category(string $name): Dictionary
    {
        $category = $this->resources->get($name);

        if (!$category instanceof Dictionary) {
            $category = new Dictionary();
            $this->resources->set($name, $category);
        }

        return $category;
    }
}
