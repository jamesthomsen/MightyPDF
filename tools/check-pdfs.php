<?php

declare(strict_types=1);

/**
 * Reads finished PDFs back with this library's own reader, strictly.
 *
 * This is the checker the external ones cannot be. qpdf, Ghostscript and
 * poppler all *repair* a damaged file: corrupt the startxref offset of a
 * document produced by this library and gs and poppler both rebuild the
 * table by scanning for "N 0 obj" and report nothing at all. That makes
 * them blind to the single most important thing the writer does, and it
 * is verified rather than assumed -- tools/check-pdfs.sh's own notes
 * record the experiment. This library's reader refuses the same file.
 *
 * The obvious objection is that checking a writer's output with the
 * matching reader is circular, and it is: a misconception the two share
 * is invisible here, which is exactly what the external tools are for.
 * The two halves fail in opposite directions and neither is redundant.
 * What this half proves is that every byte offset in the cross-reference
 * table points at the object it claims, that every stream's /Length
 * describes its data, and that the page tree is walkable -- internal
 * consistency, checked without a repair pass hiding the answer.
 *
 * Usage: php tools/check-pdfs.php [directory-of-pdfs]
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Stream;
use MightyPDF\Editor\PageTree;
use MightyPDF\Editor\PdfEditor;

$directory = $argv[1] ?? __DIR__ . '/../examples/output';

if (!is_dir($directory)) {
    fwrite(STDERR, "No such directory: $directory\n");
    exit(1);
}

$pdfs = glob(rtrim($directory, '/') . '/*.pdf') ?: [];

if ($pdfs === []) {
    fwrite(STDERR, "No PDFs in $directory -- nothing was checked.\n");
    exit(1);
}

printf("Reading %d PDFs in %s back with MightyPDF's own reader\n", count($pdfs), $directory);

$failures = 0;

foreach ($pdfs as $path) {
    $problems = check($path);

    if ($problems !== []) {
        printf("  FAIL  %s\n", basename($path));

        foreach ($problems as $problem) {
            printf("          %s\n", $problem);
        }

        $failures += count($problems);
    }
}

if ($failures !== 0) {
    printf("\n%d problem(s) found.\n", $failures);
    exit(1);
}

echo "All clean.\n";

/**
 * Everything wrong with one file, as a list of sentences.
 *
 * Collected rather than thrown on the first one: "object 12 is not where
 * the table says" and "so are objects 13 through 40" are the same
 * finding, and seeing the extent of it is most of the diagnosis.
 *
 * @return list<string>
 */
function check(string $path): array
{
    $bytes = file_get_contents($path);

    if ($bytes === false) {
        return ["could not be read from disk"];
    }

    try {
        // The empty password opens both an unencrypted document and one
        // whose user password is empty, which is what a reader does
        // before it prompts anybody.
        $editor = PdfEditor::fromBytes($bytes, '');
    } catch (\Throwable $failure) {
        return [sprintf('will not open: %s: %s', shortClass($failure), $failure->getMessage())];
    }

    $problems = [];
    $store = $editor->store();

    foreach ($store->xref()->entries() as $objectId => $entry) {
        try {
            $object = $store->get($objectId);
        } catch (\Throwable $failure) {
            $problems[] = sprintf(
                'object %d does not parse where the cross-reference table points: %s',
                $objectId,
                $failure->getMessage(),
            );

            continue;
        }

        if ($object === null) {
            $problems[] = sprintf('object %d is in the cross-reference table but resolves to nothing', $objectId);

            continue;
        }

        // A stream whose /Length is wrong still parses -- the bytes are
        // simply cut in the wrong place -- and only shows up when
        // something tries to decode it.
        if ($object instanceof Stream && $store->canDecode($object)) {
            try {
                $store->decodedStream($object);
            } catch (\Throwable $failure) {
                $problems[] = sprintf('object %d is a stream that will not decode: %s', $objectId, $failure->getMessage());
            }
        }
    }

    try {
        $pages = (new PageTree($editor))->count();

        if ($pages < 1) {
            $problems[] = 'has no pages';
        }
    } catch (\Throwable $failure) {
        $problems[] = sprintf('page tree will not walk: %s', $failure->getMessage());
    }

    return $problems;
}

function shortClass(\Throwable $failure): string
{
    $parts = explode('\\', $failure::class);

    return end($parts);
}
