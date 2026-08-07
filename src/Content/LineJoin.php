<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * How two stroked segments meet at a corner (ISO 32000-2 §8.4.3.4).
 *
 * The values are PDF's own. Mitre is the default and is what a box wants;
 * it is also the one with a failure mode, since a sharp enough angle
 * produces a spike, which is what the mitre limit exists to cut off (see
 * ContentStream::setMiterLimit()).
 */
enum LineJoin: int
{
    /** Extended to a point -- crisp on right angles, spiky on sharp ones. */
    case Miter = 0;

    case Round = 1;

    /** The corner cut off flat. */
    case Bevel = 2;
}
