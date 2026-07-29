<?php
class MightyPDF_TypeBase{
	protected $value;
	
	public function __construct($value = null){
		$this->set($value);
	}
	
	public function set($value){
		$this->value = $value;
	}
	
	public function get(){
		return $this->value;
	}
	
	public function format(){
		return '';
	}
}

?>