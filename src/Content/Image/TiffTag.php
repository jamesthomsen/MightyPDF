<?php

declare(strict_types=1);

namespace MightyPDF\Content\Image;

/**
 * The TIFF tags this library reads, by number.
 *
 * Named rather than left as the magic numbers they are in the file, since
 * a tag number is exactly the kind of constant that is unreadable at the
 * call site and impossible to get wrong once named.
 */
enum TiffTag: int
{
    case ImageWidth = 256;
    case ImageLength = 257;
    case BitsPerSample = 258;
    case Compression = 259;
    case PhotometricInterpretation = 262;
    case FillOrder = 266;
    case StripOffsets = 273;
    case SamplesPerPixel = 277;
    case RowsPerStrip = 278;
    case StripByteCounts = 279;
    case PlanarConfiguration = 284;
    case T4Options = 292;
    case T6Options = 293;
    case Predictor = 317;
    case ColorMap = 320;
    case ExtraSamples = 338;
    case SampleFormat = 339;
}
