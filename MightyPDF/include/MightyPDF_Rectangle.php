<?php
class MightyPDF_Rectangle Extends MightyPDF_Array{
	public function __construct($x1, $y1, $x2, $y2){
		$this->value = array();
		$this->add(new MightyPDF_Integer($x1));
		$this->add(new MightyPDF_Integer($y1));
		$this->add(new MightyPDF_Integer($x2));
		$this->add(new MightyPDF_Integer($y2));
	}
}
?>