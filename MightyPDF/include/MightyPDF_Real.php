<?php
class MightyPDF_Real Extends MightyPDF_TypeBase{
	public function set($value){
		$this->value = floatval($value);
	}
	
	public function format(){
		return $this->value;
	}
}
?>