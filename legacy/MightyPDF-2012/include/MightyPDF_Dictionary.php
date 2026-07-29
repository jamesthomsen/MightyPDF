<?php
class MightyPDF_Dictionary Extends MightyPDF_Object{
	protected $entries;
	
	public function __construct($objectId, $indirect = false){
		parent::__construct($objectId, $indirect);
		$this->entries = array();
	}
	
	public function build(){
		$out = '<<';
		foreach($this->entries as $name => $value){
			if($value !== null){
				$temp = new MightyPDF_Name($name);
				if(is_object($value) and $value->get() !== null){
					$out .= $temp->format().' '.$value->format().' ';
				}else{
					$out .= $temp->format()." $value ";
				}
			}
		}
		return parent::build("$out>>\n");
	}
	
	public function get(){
		return $this->entries;
	}
}
?>