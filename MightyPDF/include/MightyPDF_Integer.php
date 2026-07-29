<?php
class MightyPDF_Integer Extends MightyPDF_TypeBase{
	public function set($value){
		$this->value = intval($value);
	}
	
	public function add($amount){
		$this->value += $amount;
	}
	
	public function format(){
		return $this->value;
	}
}
?>