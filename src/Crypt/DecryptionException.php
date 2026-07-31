<?php

declare(strict_types=1);

namespace MightyPDF\Crypt;

/**
 * Thrown when an encrypted PDF cannot be unlocked or its encryption
 * cannot be handled.
 *
 * Separate from ParseException because the two call for different
 * responses: a parse failure means the file is damaged and nothing will
 * help, whereas this usually means the caller has the wrong password, or
 * none, and supplying one would fix it.
 */
final class DecryptionException extends \RuntimeException
{
}
