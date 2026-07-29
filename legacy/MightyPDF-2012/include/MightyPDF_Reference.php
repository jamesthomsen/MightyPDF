<?php
class MightyPDF_Reference Extends MightyPDF_TypeBase{
	public function format(){
		return sprintf("%d 0 R", $this->value);
	}
}
?>