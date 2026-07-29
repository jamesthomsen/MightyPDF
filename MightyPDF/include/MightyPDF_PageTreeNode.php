<?php
/*
Page 76
*/
class MightyPDF_PageTreeNode Extends MightyPDF_Dictionary{
	public function __construct($objectId){
		parent::__construct($objectId, true);
		
		/*(Required) The type of PDF object that this dictionary describes; shall be Pages
		for a page tree node.*/
		$this->entries['Type'] = new MightyPDF_Name('Pages');    //name
		
		/*(Required except in root node; prohibited in the root node; shall be an indirect
		reference) The page tree node that is the immediate parent of this one.*/
		$this->entries['Parent'] = null;    //dictionary
		
		/*(Required) An array of indirect references to the immediate children of this 
		node. The children shall only be page objects or other page tree nodes.*/
		$this->entries['Kids'] = new MightyPDF_Array();    //array
		
		/*(Required) The number of leaf nodes (page objects) that are descendants of this 
		node within the page tree.*/
		$this->entries['Count'] = new MightyPDF_Integer(0);    //integer
		
		/*A document may specify the same media box for all of its pages by including a 
		MediaBox entry in the root node of the page tree. If necessary, an individual page
		object may override this inherited value with a MediaBox entry of its own.*/
		$this->entries['MediaBox'] = null;    //rectangle
	}
	
	public function addKid($objectId){
		$this->entries['Kids']->add(new MightyPDF_Reference($objectId));
		$this->entries['Count']->add(1);
	}
	/*public function format(){
		return $this->build();
	}*/
}
?>