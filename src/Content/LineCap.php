<?php

declare(strict_types=1);

namespace MightyPDF\Content;

/**
 * How a stroked line's ends are drawn (ISO 32000-2 §8.4.3.3).
 *
 * The values are PDF's own, so the enum is what goes into the operator.
 * Named rather than left as the bare 0/1/2 because at a hairline weight
 * the difference is invisible and at a rule weight it is the difference
 * between a dashed line whose dashes are rectangles and one whose dashes
 * are lozenges.
 */
enum LineCap: int
{
    /** Cut off square at the endpoint -- the default, and what a table rule wants. */
    case Butt = 0;

    /** A half-circle of the line's width centred on the endpoint. */
    case Round = 1;

    /** Square, but projecting half the line width past the endpoint. */
    case Square = 2;
}
