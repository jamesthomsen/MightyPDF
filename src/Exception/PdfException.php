<?php

declare(strict_types=1);

namespace MightyPDF\Exception;

/**
 * Implemented by everything this library throws, so that a caller can
 * write one catch and mean "the PDF layer failed" -- which until now was
 * not something it was possible to say.
 *
 * Before this, the library threw four exception types of its own and
 * three of PHP's, and a caller wanting to handle "MightyPDF could not do
 * it" had to either list all seven or catch \Throwable and hope. Neither
 * is a boundary. Listing them breaks silently the day an eighth is
 * added, and \Throwable swallows the TypeError from the bug in the
 * caller's own code three lines up.
 *
 * The marker is an interface rather than a base class because the useful
 * distinctions are already made by PHP's hierarchy and this library has
 * no reason to fight it: an argument that was wrong when it was passed
 * is an \InvalidArgumentException whoever throws it, and code catching
 * that should keep catching it. So the classes in this namespace extend
 * the SPL exception they replace and add nothing but this interface,
 * which is what lets the whole change be additive -- every existing
 * catch keeps working, and a new one can be narrower or broader as the
 * caller likes:
 *
 *     try {
 *         $document->saveToFile($path);
 *     } catch (PdfException $failure) {
 *         // Ours, whatever kind.
 *     }
 *
 * The four domain exceptions -- ParseException, FontException,
 * FormException, DecryptionException -- implement it where they are
 * rather than moving here. They belong beside the code that raises them,
 * and a reader looking for "why did parsing fail" should find the answer
 * in the reader, not in a directory of exceptions.
 */
interface PdfException extends \Throwable
{
}
