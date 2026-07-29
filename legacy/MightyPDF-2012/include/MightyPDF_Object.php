<?php

//TODO add encryption 7.4.10 pg 38

class MightyPDF_Object{
	protected $objectId = null;
	protected $indirectObject = false;
	
	public function __construct($objectId, $indirect = false){
		$this->setObjectId($objectId);
		$this->indirectObject = $indirect;
	}
	
	public function __toString(){
		
	}
	
	public function setObjectId($id){
		$this->objectId = $id;
	}
	
	public function getObjectId(){
		return $this->objectId;
	}
	
	public function isIndirectdObject(){
		return $this->indirectObject;
	}
	
	public function build($alreadyBuilt){
		if($this->indirectObject){
			return sprintf("\n%d 0 obj\n%s\nendobj\n", $this->objectId, trim($alreadyBuilt));
		}
		return $alreadyBuilt;
	}
	
	/*protected function asIndirectObject($value){
		$this->indirectObject = true;
		return sprintf("\n%d 0 obj\n%s\nendobj\n", $this->objectId, $value);
	}*/
	
	protected function asFlateDecode($value){
		return gzcompress($value);
	}
	
	//protected function 
}
?>