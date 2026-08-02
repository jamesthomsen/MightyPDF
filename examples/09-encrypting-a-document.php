<?php

/**
 * Encrypting a document this library creates, with Document::encrypt().
 *
 * AES-256 only -- there's no reason to write a broken cipher into a new
 * file. Be clear about what the two passwords buy you:
 *
 * - The user password is needed to open the document at all, and is the
 *   only thing here that provides confidentiality. Left empty (the usual
 *   arrangement, used below), the file opens in every viewer without a
 *   prompt, because the key derives from the empty string, which anybody
 *   has.
 * - The owner password is what a reader asks for before disregarding the
 *   permissions below. Permissions are a *request*, not enforcement --
 *   the file has already been decrypted by the time the flags are read,
 *   so this stops Acrobat offering the "copy" menu item, not a person
 *   with a text editor.
 *
 * Run: php examples/09-encrypting-a-document.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Crypt\Permissions;

$document = new Document();
$page = $document->newPage();
$content = new PageBuilder($document, $page);

$content->drawText(StandardFont::HelveticaBold, 24.0, 72, 720, 'Confidential');
$content->drawText(StandardFont::Helvetica, 12.0, 72, 690, 'This document opens without a password, but printing and copying are restricted.');

$document->encrypt(
    ownerPassword: 'owner-secret',
    userPassword: '',
    permissions: Permissions::allowing(Permissions::PRINT | Permissions::FILL_FORMS),
);

@mkdir(__DIR__ . '/output', recursive: true);
$document->saveToFile(__DIR__ . '/output/09-encrypting-a-document.pdf');

echo 'Wrote ' . __DIR__ . "/output/09-encrypting-a-document.pdf\n";
