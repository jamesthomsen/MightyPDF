<?php
class MightyPDF_String Extends MightyPDF_TypeBase{
	public function format(){
		$find    = array("\n",  "\r",  "\t",  '\x08',  '\xFF', '(',   ')',   '\\');
		$replace = array('\\n', '\\r', '\\t', '\\b',   '\\f',  '\\(', '\\)', '\\\\');
		$value = str_replace($find, $replace, $this->value);
		//do I need to encode the string in some way?  Maybe UTF-8?
		return "($value)";
	}
}
?>