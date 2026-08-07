<?php

declare(strict_types=1);

namespace MightyPDF\Content\Text;

/**
 * Where text sits vertically in the box it is drawn in.
 *
 * Two middles rather than one, because "centred" means two different
 * things and using the wrong one is a real defect rather than a matter
 * of taste:
 *
 * - Middle centres the em box, ascent to descent. Right for running
 *   prose and for anything mixed-case: the space a "y" or a "g" drops
 *   into belongs to the line whether this particular line uses it or not,
 *   so text stays put when the wording changes.
 * - CapMiddle centres the capitals, baseline to cap height. Right for a
 *   label, a table heading, a figure, a single large letter -- anything
 *   with nothing below the baseline, which centred on the em box reads
 *   as sitting high, by half the descent.
 *
 * The gap between them is proportional to type size, which is why this
 * is an enum and not a flag someone sets once and forgets: at 10pt the
 * two differ by about a point, and at 270pt by two centimetres.
 *
 * See TextPlacement for what each one computes.
 */
enum VerticalAlign
{
    case Top;
    case Middle;
    case CapMiddle;
    case Bottom;
}
