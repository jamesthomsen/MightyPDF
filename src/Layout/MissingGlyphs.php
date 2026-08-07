<?php

declare(strict_types=1);

namespace MightyPDF\Layout;

/**
 * What a Flow does with text its font cannot draw.
 *
 * Refuse is the default because it is what the library does everywhere
 * else, and because a silent approximation is a poor default for code
 * that chose an embedded font on purpose.
 *
 * Substitute is the setting for a document built from data nobody
 * proofread -- an invoice addressed to a customer, a report naming a
 * client. There, the character that will break the run is one nobody
 * anticipated, and failing the whole document over it turns a slightly
 * wrong name into an outage. Choose it deliberately, per document; see
 * GlyphFallback for exactly what it produces.
 */
enum MissingGlyphs
{
    case Refuse;
    case Substitute;
}
