<?php
error_reporting(-1);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

$root = dirname($_SERVER["SCRIPT_FILENAME"]);
set_include_path(get_include_path() . PATH_SEPARATOR . "$root/MightyPDF" . PATH_SEPARATOR . "$root/MightyPDF/include");

function MightyPDFLoader($class){
	//This deals with the E_STRICT warning about method overloading.
	$errorLevel = error_reporting(E_ALL & ~E_STRICT);
	require_once "{$class}.php";
	error_reporting($errorLevel);
	
	if(!class_exists($class, false)){
		trigger_error("Unable to load class: $class", E_USER_WARNING);
	}
}
spl_autoload_register('MightyPDFLoader');


$pdf = new MightyPDF;
/*$pdf->addStream('James Thomsen');
$pdf->addStream('What was that?');
$pdf->addStream('Wait What?');*/

$pdf->newPage();
/*
print '<pre>';
var_dump($pdf);
print '</pre>';
*/

//print '<pre>';
print ($pdf->save());
//print '</pre>';

//print 'Done';
?>