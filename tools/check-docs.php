<?php

declare(strict_types=1);

/**
 * Checks that the documentation still describes this library.
 *
 * Prose goes stale quietly. A method gets renamed, the tests are updated
 * because they would not compile otherwise, and the README goes on
 * confidently describing the old name until somebody copies it into
 * their editor and it does not exist. Nothing in a test suite notices,
 * because nothing in a test suite reads the docs.
 *
 * So this does, and asks three things of them:
 *
 * 1. Every link goes somewhere -- the file exists, and if the link
 *    carries an anchor, some heading in that file actually produces it.
 *    Splitting one README into fourteen pages is exactly the operation
 *    that breaks these, and doing it by hand without a check is how a
 *    docs tree ends up with links nobody has followed since the day
 *    they were written.
 *
 * 2. Every PHP block parses. Cheap, and it catches the copy-paste that
 *    lost a brace.
 *
 * 3. Every class the blocks import exists, and every `Class::member`
 *    they name is a real method, constant or enum case. This is the one
 *    that catches a rename, which is the way documentation actually
 *    goes wrong.
 *
 * What it deliberately does not do is *run* the blocks. Most are
 * fragments -- three lines showing one call, with no document to call it
 * on -- and rewriting them into runnable programs would make them worse
 * as documentation. The runnable, end-to-end versions are in examples/,
 * which CI already executes and checks the output of.
 *
 * Usage: php tools/check-docs.php
 */

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);

/** @var list<string> $documents */
$documents = array_merge([$root . '/README.md'], glob($root . '/docs/*.md') ?: []);

$problems = [];

foreach ($documents as $path) {
    $name = substr($path, strlen($root) + 1);
    $text = (string) file_get_contents($path);

    foreach (checkLinks($text, $path, $root) as $problem) {
        $problems[] = "$name: $problem";
    }

    foreach (phpBlocks($text) as $line => $code) {
        foreach (checkBlock($code) as $problem) {
            $problems[] = "$name:$line: $problem";
        }
    }
}

if ($problems !== []) {
    fwrite(STDERR, sprintf("%d problem(s) in the documentation:\n\n", count($problems)));

    foreach ($problems as $problem) {
        fwrite(STDERR, "  $problem\n");
    }

    exit(1);
}

printf("Checked %d documents: links resolve, PHP parses, and every symbol named exists.\n", count($documents));

/**
 * The anchor GitHub gives a heading: lowercased, punctuation dropped,
 * spaces to hyphens. Kept deliberately simple -- it only has to agree
 * with the headings in this repository, not with every corner of
 * GitHub's implementation.
 */
function anchorOf(string $heading): string
{
    $anchor = strtolower(trim($heading));
    $anchor = preg_replace('/[^\p{L}\p{N} _-]+/u', '', $anchor) ?? '';

    return str_replace(' ', '-', $anchor);
}

/** @return list<string> */
function anchorsIn(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    preg_match_all('/^#{1,6} +(.*)$/m', (string) file_get_contents($path), $matches);

    return array_map(anchorOf(...), $matches[1]);
}

/** @return list<string> */
function checkLinks(string $text, string $path, string $root): array
{
    $problems = [];
    $directory = dirname($path);

    preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/', $text, $matches);

    foreach ($matches[1] as $target) {
        if (preg_match('#^(https?:|mailto:)#', $target) === 1) {
            continue;
        }

        [$file, $anchor] = array_pad(explode('#', $target, 2), 2, null);

        $resolved = $file === '' ? $path : $directory . '/' . $file;

        if ($file !== '' && !file_exists($resolved)) {
            $problems[] = "link to \"$target\" -- no such file";

            continue;
        }

        if ($anchor === null || is_dir($resolved)) {
            continue;
        }

        if (!in_array($anchor, anchorsIn($resolved), true)) {
            $problems[] = "link to \"$target\" -- no heading in " . substr($resolved, strlen($root) + 1) . " makes that anchor";
        }
    }

    return $problems;
}

/** @return array<int, string> first line number => code */
function phpBlocks(string $text): array
{
    $blocks = [];
    $lines = explode("\n", $text);
    $open = null;
    $buffer = [];

    foreach ($lines as $number => $line) {
        if ($open === null && preg_match('/^```php\s*$/', $line) === 1) {
            $open = $number + 2;
            $buffer = [];

            continue;
        }

        if ($open !== null && preg_match('/^```\s*$/', $line) === 1) {
            $blocks[$open] = implode("\n", $buffer);
            $open = null;

            continue;
        }

        if ($open !== null) {
            $buffer[] = $line;
        }
    }

    return $blocks;
}

/** @return list<string> */
function checkBlock(string $code): array
{
    $problems = [];

    // Fragments are not whole programs, so the statements are wrapped
    // in a function body before being parsed: that is what lets a block
    // of three lines be checked without pretending it is a file.
    //
    // The imports have to stay outside it, because `use X;` is a
    // file-level declaration and PHP rejects it inside a function --
    // which is what the first version of this got wrong, and reported
    // as "syntax error, unexpected token use" against forty perfectly
    // good blocks.
    $imports = [];
    $body = [];

    foreach (explode("\n", $code) as $line) {
        if (preg_match('/^\s*(use [^;]+;|namespace [^;]+;|declare\([^)]*\);)\s*$/', $line) === 1) {
            $imports[] = trim($line);

            continue;
        }

        $body[] = $line;
    }

    $wrapped = "<?php\n" . implode("\n", $imports) . "\n";
    $wrapped .= str_contains($code, 'require ')
        ? implode("\n", $body)
        : "function __docblock() {\n" . implode("\n", $body) . "\n}\n";

    // A block that says it is leaving something out is not claiming to
    // be a complete program, and should not be parsed as one.
    // `fn (Flow $flow) => /* ... */` tells a reader "your code here" far
    // better than any valid stand-in would, so the marker is honoured
    // rather than edited away to keep a tool happy. The symbol checks
    // below still apply: eliding a body says nothing about whether the
    // method being demonstrated still exists.
    $elided = str_contains($code, '/* ... */')
        || str_contains($code, '/* …')
        || str_contains($code, '[...]')
        || preg_match('#= /\* .* \*/;#', $code) === 1;

    $file = $elided ? null : (tempnam(sys_get_temp_dir(), 'mightypdf-doc') ?: null);

    if ($file !== null) {
        file_put_contents($file, $wrapped);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        unlink($file);

        if ($status !== 0) {
            $problems[] = 'does not parse: ' . trim(preg_replace('/ in .*$/m', '', implode(' ', $output)) ?? '');
        }
    }

    $imported = [];

    preg_match_all('/^use (MightyPDF\\\\[^\s;]+)(?: as (\w+))?;/m', $code, $uses, PREG_SET_ORDER);

    foreach ($uses as $use) {
        $class = $use[1];

        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class) && !trait_exists($class)) {
            $problems[] = "imports $class, which does not exist";

            continue;
        }

        $imported[$use[2] ?? substr($class, strrpos($class, '\\') + 1)] = $class;
    }

    // Class::member, for the classes this block imported. Anything else
    // is out of reach -- a method on a variable needs to know the
    // variable's type, which is a job for a type checker and not for a
    // regular expression pretending to be one.
    preg_match_all('/\b(\w+)::(\w+)/', $code, $statics, PREG_SET_ORDER);

    foreach ($statics as [$whole, $short, $member]) {
        if (!isset($imported[$short]) || $member === 'class') {
            continue;
        }

        $class = $imported[$short];

        if (method_exists($class, $member) || defined("$class::$member")) {
            continue;
        }

        $problems[] = "names $whole, which is not a method, constant or case of $class";
    }

    return $problems;
}
