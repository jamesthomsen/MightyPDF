<?php

declare(strict_types=1);

/**
 * The half of form handling that is not filling one in: getting the
 * values out, and putting somebody else's back in.
 *
 * A filled PDF is a poor place to keep data. What an application wants
 * is the values on their own -- to store, to validate, to send on -- and
 * what it is handed back is usually the same values from a different
 * tool. Two formats cover both ends:
 *
 * - **XFDF** (ISO 19444-1) is what the PDF world speaks. Acrobat's
 *   "Export Data" writes it, its "Import Data" reads it, and a browser
 *   submits it when a form's /SubmitForm action asks for one.
 * - **JSON** is what everything else speaks.
 *
 * Both go in through fill(), so both get its checking: an unknown field
 * name is refused with a suggestion rather than silently ignored, and a
 * checkbox is written to /V *and* /AS -- the mistake that leaves a box
 * ticked in the data and unticked on the page.
 */

require __DIR__ . '/../vendor/autoload.php';

use MightyPDF\Assembler\Document;
use MightyPDF\Content\Font\StandardFont;
use MightyPDF\Content\PageBuilder;
use MightyPDF\Editor\Form\FormFiller;
use MightyPDF\Editor\Form\Xfdf;
use MightyPDF\Editor\PdfEditor;

$out = __DIR__ . '/output';

// -- A blank form to work with ----------------------------------------

$document = new Document();
$page = $document->newPage();
$content = new PageBuilder($document, $page);

$content->drawText(StandardFont::HelveticaBold, 16.0, 60.0, 740.0, 'Membership renewal');

foreach ([['name', 'Name', 700], ['email', 'Email', 665]] as [$field, $label, $y]) {
    $content->drawText(StandardFont::Helvetica, 10.0, 60.0, $y + 6.0, $label);
    $content->addTextField($field, x: 160.0, y: $y, width: 280.0, height: 20.0);
}

$content->drawText(StandardFont::Helvetica, 10.0, 60.0, 636.0, 'Gift Aid');
$content->addCheckbox('gift_aid', x: 160.0, y: 632.0, size: 14.0);

$content->drawText(StandardFont::Helvetica, 10.0, 60.0, 606.0, 'Tier');
$content->addRadioGroup('tier', [
    ['exportValue' => 'standard', 'x' => 160.0, 'y' => 602.0, 'size' => 14.0],
    ['exportValue' => 'concession', 'x' => 200.0, 'y' => 602.0, 'size' => 14.0],
    ['exportValue' => 'life', 'x' => 240.0, 'y' => 602.0, 'size' => 14.0],
], checkedExportValue: 'standard');

$blank = $document->save();
file_put_contents("$out/25-blank-form.pdf", $blank);

// -- Fill it, then export what is in it --------------------------------

$editor = PdfEditor::fromBytes($blank);
$filler = new FormFiller($editor);

$filler->fill([
    'name' => 'Zoë Mikkelsen',
    'email' => 'zoe@example.com',
    'gift_aid' => true,
    'tier' => 'life',
]);

file_put_contents("$out/25-filled-form.pdf", $editor->save());

// The href is a hint about which document the data belongs to. Nothing
// checks it on the way back in -- it is for whoever opens the file.
file_put_contents("$out/25-data.xfdf", $filler->toXfdf('25-blank-form.pdf'));
file_put_contents("$out/25-data.json", $filler->toJson());

echo "Exported:\n", $filler->toJson(), "\n\n";

// -- Take data from elsewhere and fill a fresh copy ---------------------

// As if this had arrived from Acrobat, or from a browser's form submit.
// Note the nesting: "postal.town" is one field with a dotted full name,
// and XFDF writes it as a <field> inside a <field>. This form has no
// such field, so the flat names are what matter here -- but the parser
// resolves both, and Xfdf::export() writes the nested form.
$arrived = <<<'XFDF'
    <?xml version="1.0" encoding="UTF-8"?>
    <xfdf xmlns="http://ns.adobe.com/xfdf/" xml:space="preserve">
      <fields>
        <field name="name"><value>Ada Lovelace</value></field>
        <field name="email"><value>ada@example.com</value></field>
        <field name="gift_aid"><value>Off</value></field>
        <field name="tier"><value>concession</value></field>
      </fields>
    </xfdf>
    XFDF;

$fresh = PdfEditor::fromBytes($blank);
$imported = new FormFiller($fresh);
$imported->fillFromXfdf($arrived);

file_put_contents("$out/25-imported-form.pdf", $fresh->save());

echo "Imported from XFDF:\n";

foreach ($imported->values() as $name => $value) {
    printf("  %-10s %s\n", $name, $value ?? '(empty)');
}

// The array on its own, for a caller that wants to look before filling.
echo "\nParsed without touching a document: ", json_encode(Xfdf::parse($arrived)), "\n";

// -- And the same thing from JSON --------------------------------------

$fromJson = new FormFiller(PdfEditor::fromBytes($blank));
$fromJson->fillFromJson('{"name": "Grace Hopper", "tier": "standard", "gift_aid": "Yes"}');

echo "Imported from JSON: ", $fromJson->values()['name'], "\n";

// A name the form does not have is refused rather than dropped: silently
// ignoring it is how half a form ends up filled and nobody notices.
try {
    $fromJson->fillFromJson('{"e-mail": "typo@example.com"}');
} catch (\MightyPDF\Editor\Form\FormException $error) {
    echo "\nRefused, as it should be:\n  ", $error->getMessage(), "\n";
}
