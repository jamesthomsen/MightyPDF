<?php
/*
Page 42
*/
class MightyPDF_Trailer Extends MightyPDF_Dictionary{
	protected $entries;
	
	public function __construct($size, $root, $info, $id = null){
		/*(Required; shall not be an indirect reference) The total number of entries in 
		the file’s cross-reference table, as defined by the combination of the original 
		section and all update sections. Equivalently, this value shall be 1 greater than 
		the highest object number defined in the file.
		Any object in a cross-reference section whose number is greater than this value 
		shall be ignored and defined to be missing by a conforming reader.
		*/
		$this->entries['Size'] = new MightyPDF_Integer($size);
		
		/*(Present only if the file has more than one cross-reference section; shall be an
		indirect reference) The byte offset in the decoded stream from the beginning of 
		the file to the beginning of the previous cross-reference section.*/
		$this->entries['Prev'] = null;
		
		/*(Required; shall be an indirect reference) The catalog dictionary for the PDF 
		document contained in the file (see 7.7.2, "Document Catalog").*/
		$this->entries['Root'] = new MightyPDF_Reference($root);
		
		/*(Required if document is encrypted; PDF 1.1) The document’s encryption 
		dictionary (see 7.6, "Encryption").*/
		$this->entries['Encrypt'] = null;
		
		/*(Optional; shall be an indirect reference) The document’s information dictionary
		(see 14.3.3, "Document Information Dictionary").*/
		$this->entries['Info'] = null;//new MightyPDF_Reference($info);
		
		/*(Required if an Encrypt entry is present; optional otherwise; PDF 1.1) An array 
		of two byte-strings constituting a file identifier (see 14.4, "File Identifiers") 
		for the file. If there is an Encrypt entry this array and the two byte-strings 
		shall be direct objects and shall be unencrypted.*/
		$this->entries['ID'] = ($id === null) ? null : new MightyPDF_Array(array($id, $id));
	}
}
?>