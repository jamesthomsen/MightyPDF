<?php
class MightyPDF_HexString Extends MightyPDF_TypeBase{
	public function format(){
		return '<'.bin2hex($this->value).'>';
	}
}
?>