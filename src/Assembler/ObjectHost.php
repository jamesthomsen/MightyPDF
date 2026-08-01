<?php

declare(strict_types=1);

namespace MightyPDF\Assembler;

/**
 * Somewhere object numbers come from and finished objects go.
 *
 * The whole of the content layer needs exactly this much and nothing
 * more: a number for the object it is about to build, and somewhere to
 * hand it once built. Naming that as an interface is what lets the same
 * image and drawing code serve a document being written from scratch
 * (where the answer is IndirectObjectRegistry) and one being edited in
 * place (where it is PdfEditor, allocating above everything the file
 * already uses and collecting changes for an incremental update).
 *
 * The two-step shape -- allocate, then construct, then register -- is
 * forced by PdfObject's object id being readonly: it has to be known
 * before the object exists.
 */
interface ObjectHost
{
    public function allocate(): int;

    public function register(PdfObject $object): void;
}
