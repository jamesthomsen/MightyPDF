<?php

declare(strict_types=1);

namespace MightyPDF\Content\Svg;

use MightyPDF\Content\ContentStream;

/**
 * Parses an SVG path "d" attribute (SVG 1.1 §8.3) and replays it as
 * ContentStream path operators. PDF has no quadratic-Bezier or elliptical-
 * arc path operators (only moveto/lineto/cubic-Bezier), so Q/T and A
 * commands are converted to one or more cubic Beziers using the standard,
 * widely-published conversion formulas.
 *
 * Known limitation: the tokenizer reads numbers generically and does not
 * special-case the two single-digit arc flags in an "A"/"a" command, so a
 * flag written with no separator before an adjacent multi-digit number
 * (e.g. "11" meaning flag=1 immediately followed by a coordinate that
 * happens to start with 1) would misparse. This is a known SVG authoring
 * edge case; most real-world and tool-exported path data separates
 * arguments with at least a comma or space, which parses correctly.
 */
final class SvgPathParser
{
    private function __construct()
    {
    }

    public static function apply(string $d, ContentStream $stream): void
    {
        $tokens = self::tokenize($d);
        $index = 0;
        $count = count($tokens);

        $command = null;
        $currentX = 0.0;
        $currentY = 0.0;
        $subpathStartX = 0.0;
        $subpathStartY = 0.0;
        $previousControlX = null;
        $previousControlY = null;
        $previousCommandLetter = null;

        while ($index < $count) {
            $token = $tokens[$index];

            if (is_string($token)) {
                $command = $token;
                ++$index;
            } elseif ($command === null) {
                throw new \InvalidArgumentException('SVG path data must start with a command letter.');
            }

            $letter = strtoupper($command);
            $relative = $command !== $letter;

            $readNumber = function () use (&$index, $tokens, $count): float {
                if ($index >= $count || !is_float($tokens[$index])) {
                    throw new \InvalidArgumentException('Malformed SVG path data: expected a number.');
                }

                return $tokens[$index++];
            };

            switch ($letter) {
                case 'M':
                    $x = $readNumber();
                    $y = $readNumber();
                    if ($relative) {
                        $x += $currentX;
                        $y += $currentY;
                    }
                    $stream->moveTo($x, $y);
                    $currentX = $subpathStartX = $x;
                    $currentY = $subpathStartY = $y;
                    // Subsequent coordinate pairs after an initial moveto are implicit linetos.
                    $command = $relative ? 'l' : 'L';
                    break;

                case 'L':
                    $x = $readNumber();
                    $y = $readNumber();
                    if ($relative) {
                        $x += $currentX;
                        $y += $currentY;
                    }
                    $stream->lineTo($x, $y);
                    $currentX = $x;
                    $currentY = $y;
                    break;

                case 'H':
                    $x = $readNumber();
                    if ($relative) {
                        $x += $currentX;
                    }
                    $stream->lineTo($x, $currentY);
                    $currentX = $x;
                    break;

                case 'V':
                    $y = $readNumber();
                    if ($relative) {
                        $y += $currentY;
                    }
                    $stream->lineTo($currentX, $y);
                    $currentY = $y;
                    break;

                case 'C':
                    $x1 = $readNumber();
                    $y1 = $readNumber();
                    $x2 = $readNumber();
                    $y2 = $readNumber();
                    $x = $readNumber();
                    $y = $readNumber();
                    if ($relative) {
                        $x1 += $currentX;
                        $y1 += $currentY;
                        $x2 += $currentX;
                        $y2 += $currentY;
                        $x += $currentX;
                        $y += $currentY;
                    }
                    $stream->curveTo($x1, $y1, $x2, $y2, $x, $y);
                    $previousControlX = $x2;
                    $previousControlY = $y2;
                    $currentX = $x;
                    $currentY = $y;
                    break;

                case 'S':
                    $x2 = $readNumber();
                    $y2 = $readNumber();
                    $x = $readNumber();
                    $y = $readNumber();
                    if ($relative) {
                        $x2 += $currentX;
                        $y2 += $currentY;
                        $x += $currentX;
                        $y += $currentY;
                    }
                    [$x1, $y1] = self::reflectedControlPoint(
                        $currentX,
                        $currentY,
                        $previousControlX,
                        $previousControlY,
                        $previousCommandLetter,
                        ['C', 'S'],
                    );
                    $stream->curveTo($x1, $y1, $x2, $y2, $x, $y);
                    $previousControlX = $x2;
                    $previousControlY = $y2;
                    $currentX = $x;
                    $currentY = $y;
                    break;

                case 'Q':
                    $qx = $readNumber();
                    $qy = $readNumber();
                    $x = $readNumber();
                    $y = $readNumber();
                    if ($relative) {
                        $qx += $currentX;
                        $qy += $currentY;
                        $x += $currentX;
                        $y += $currentY;
                    }
                    self::emitQuadratic($stream, $currentX, $currentY, $qx, $qy, $x, $y);
                    $previousControlX = $qx;
                    $previousControlY = $qy;
                    $currentX = $x;
                    $currentY = $y;
                    break;

                case 'T':
                    $x = $readNumber();
                    $y = $readNumber();
                    if ($relative) {
                        $x += $currentX;
                        $y += $currentY;
                    }
                    [$qx, $qy] = self::reflectedControlPoint(
                        $currentX,
                        $currentY,
                        $previousControlX,
                        $previousControlY,
                        $previousCommandLetter,
                        ['Q', 'T'],
                    );
                    self::emitQuadratic($stream, $currentX, $currentY, $qx, $qy, $x, $y);
                    $previousControlX = $qx;
                    $previousControlY = $qy;
                    $currentX = $x;
                    $currentY = $y;
                    break;

                case 'A':
                    $rx = $readNumber();
                    $ry = $readNumber();
                    $rotation = $readNumber();
                    $largeArc = $readNumber() !== 0.0;
                    $sweep = $readNumber() !== 0.0;
                    $x = $readNumber();
                    $y = $readNumber();
                    if ($relative) {
                        $x += $currentX;
                        $y += $currentY;
                    }
                    SvgArc::emit($stream, $currentX, $currentY, $rx, $ry, $rotation, $largeArc, $sweep, $x, $y);
                    $currentX = $x;
                    $currentY = $y;
                    break;

                case 'Z':
                    $stream->closePath();
                    $currentX = $subpathStartX;
                    $currentY = $subpathStartY;
                    break;

                default:
                    throw new \InvalidArgumentException("Unsupported SVG path command: \"$command\"");
            }

            $previousCommandLetter = $letter;
        }
    }

    private static function emitQuadratic(
        ContentStream $stream,
        float $x0,
        float $y0,
        float $qx,
        float $qy,
        float $x,
        float $y,
    ): void {
        // Standard quadratic-to-cubic elevation: CP1 = P0 + 2/3(Q-P0), CP2 = P1 + 2/3(Q-P1).
        $x1 = $x0 + (2 / 3) * ($qx - $x0);
        $y1 = $y0 + (2 / 3) * ($qy - $y0);
        $x2 = $x + (2 / 3) * ($qx - $x);
        $y2 = $y + (2 / 3) * ($qy - $y);

        $stream->curveTo($x1, $y1, $x2, $y2, $x, $y);
    }

    /** @param list<string> $reflectableAfter @return array{0: float, 1: float} */
    private static function reflectedControlPoint(
        float $currentX,
        float $currentY,
        ?float $previousControlX,
        ?float $previousControlY,
        ?string $previousCommandLetter,
        array $reflectableAfter,
    ): array {
        if ($previousControlX === null || !in_array($previousCommandLetter, $reflectableAfter, true)) {
            return [$currentX, $currentY];
        }

        return [2 * $currentX - $previousControlX, 2 * $currentY - $previousControlY];
    }

    /** @return list<string|float> command letters as strings, coordinates as floats */
    private static function tokenize(string $d): array
    {
        $tokens = [];
        preg_match_all(
            '/[MmLlHhVvCcSsQqTtAaZz]|-?\d*\.\d+(?:[eE][+-]?\d+)?|-?\d+(?:[eE][+-]?\d+)?/',
            $d,
            $matches,
        );

        foreach ($matches[0] as $match) {
            $tokens[] = ctype_alpha($match) ? $match : (float) $match;
        }

        return $tokens;
    }
}
