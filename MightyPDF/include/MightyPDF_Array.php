<?php
class MightyPDF_Array Extends MightyPDF_TypeBase{
	protected $value = array();
	
	public function add($item){
		$this->value[] = $item;
	}
	
	public function format(){
		$out = '[';
		for($i=0; $i<count($this->value); ++$i){
			$out .= $this->value[$i]->format();
			$out .= ' ';
		}
		return trim($out).']';
	}
}
?>