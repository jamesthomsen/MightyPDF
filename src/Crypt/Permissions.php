<?php

declare(strict_types=1);

namespace MightyPDF\Crypt;

/**
 * The /P permission flags of the standard security handler (ISO 32000-2
 * Table 22).
 *
 * Worth being clear about what these are. They are not enforcement. /P is
 * a request, recorded in the document, that conforming readers are asked
 * to honour -- and every one of them will happily ignore it if told to,
 * because the file has already been decrypted by the time the flags are
 * read. Turning off "copy" does not stop anybody copying anything; it
 * stops Acrobat offering the menu item.
 *
 * They are useful for saying what a document is *for*, and useless as
 * protection. Confidentiality comes from the user password, and only from
 * the user password.
 */
final class Permissions
{
    /** Print, though only at low resolution unless PRINT_HIGH_QUALITY. */
    public const int PRINT = 1 << 2;

    /** Modify the contents by any means other than those below. */
    public const int MODIFY = 1 << 3;

    /** Copy text and graphics out. */
    public const int COPY = 1 << 4;

    /** Add or change annotations, and fill in existing form fields. */
    public const int ANNOTATE = 1 << 5;

    /** Fill in form fields, even where ANNOTATE is withheld. */
    public const int FILL_FORMS = 1 << 8;

    /** Extract text and graphics for accessibility tools. */
    public const int EXTRACT_FOR_ACCESSIBILITY = 1 << 9;

    /** Insert, rotate or delete pages, and make bookmarks. */
    public const int ASSEMBLE = 1 << 10;

    /** Print at full resolution rather than a degraded image. */
    public const int PRINT_HIGH_QUALITY = 1 << 11;

    public const int ALL = self::PRINT
        | self::MODIFY
        | self::COPY
        | self::ANNOTATE
        | self::FILL_FORMS
        | self::EXTRACT_FOR_ACCESSIBILITY
        | self::ASSEMBLE
        | self::PRINT_HIGH_QUALITY;

    private function __construct()
    {
    }

    /**
     * The /P integer granting exactly $granted and nothing else.
     *
     * The spec fixes most of the word: bits 1 and 2 shall be 0, and bits
     * 7, 8 and 13 upwards shall be 1. Only the eight flags above are
     * actually a choice, which is why /P is conventionally seen as a
     * large negative number -- "everything permitted" is -4, not 0.
     */
    public static function allowing(int $granted): int
    {
        return -1 & ~0b11 & ~(self::ALL & ~$granted);
    }

    public static function all(): int
    {
        return self::allowing(self::ALL);
    }
}
