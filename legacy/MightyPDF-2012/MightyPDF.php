<?php

class MightyPDF{
	protected $pages = array();
	protected $currentPage = null;
	protected $pageCount = 0;
	protected $lastObjectId = 0;
	//protected $xref = array();
	protected $fileId = null;
	protected $catalog = null;
	
	public function __construct(){
		$this->catalog = new MightyPDF_Catalog($this->getNextObjectId());
	}
	
	public function save(){
		$xref = new MightyPDF_Xref();
		
		$out = "%PDF-1.7\n%";
		$out .= chr(0xe2).chr(0xe3).chr(0xcf).chr(0xd3);
		$out .= "\n";
		
		$pageTree = new MightyPDF_PageTreeNode($this->getNextObjectId());
		
		for($i=0; $i<$this->pageCount; ++$i){
			$xref->addPosition(strlen($out));
			$this->pages[$i]->setParent($pageTree->getObjectId());
			$out .= $this->pages[$i]->build(strlen($out), $xref);
			$pageTree->addKid($this->pages[$i]->getObjectId());
		}
		$xref->addPosition(strlen($out));
		$out .= $pageTree->build();
		$this->catalog->setPages($pageTree->getObjectId());
		$xref->addPosition(strlen($out));
		$out .= $this->catalog->build();
		
		$startxref = strlen($out);
		$out .= $xref->build();
		
		$trailer = new MightyPDF_Trailer($xref->length(), $this->catalog->getObjectId(), 103);
		
		$out .= "trailer\n";
		$out .= $trailer->build();
		$out .= "startxref\n";
		$out .= "$startxref\n";
		$out .= "%%EOF";
		return $out;
	}
	
	public function newPage(){
		$this->pages[] = new MightyPDF_Page($this->getNextObjectId());
		++$this->pageCount;
		$this->currentPage = $this->pageCount;
		
		$this->getCurrentpage()->setMediaBox(0, 0, 612, 792);
	}
	
	public function addStream($streamValue){
		$obj = new MightyPdf_Stream($streamValue);
		$obj->setObjectId($this->getNextObjectId());
		$this->getCurrentpage()->addObject($obj);
	}
	
	protected function getCurrentpage(){
		if($this->pageCount == 0){
			//I should throw an exception instead
			$this->newPage();
		}
		return $this->pages[ $this->currentPage - 1 ];
	}
	
	protected function getNextObjectId(){
		return ++$this->lastObjectId;
	}
}

?>