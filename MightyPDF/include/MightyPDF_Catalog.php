<?php
/*
Page 72
*/
class MightyPDF_Catalog Extends MightyPDF_Dictionary{
	public function __construct($objectId){
		parent::__construct($objectId, true);
		
		/*(Required) The type of PDF object that this dictionary describes; shall be 
		Catalog for the catalog dictionary.*/
		$this->entries['Type'] = new MightyPDF_Name('Catalog');    //name
		
		/*Optional; PDF 1.4) The version of the PDF specification to which the document 
		conforms (for example, 1.4) if later than the version specified in the file’s 
		header (see 7.5.2, "File Header"). If the header specifies a later version, or if 
		this entry is absent, the document shall conform to the version specified in the 
		header. This entry enables a conforming writer to update the version using an 
		incremental update; see 7.5.6, "Incremental Updates."
		The value of this entry shall be a name object, not a number, and therefore shall 
		be preceded by a SOLIDUS (2Fh) character (/) when written in the PDF file 
		(for example, /1.4).*/
		$this->entries['Version'] = null; //Name
		
		/*(Optional; ISO 32000) An extensions dictionary containing developer prefix 
		identification and version numbers for developer extensions that occur in this 
		document. 7.12, “Extensions Dictionary”, describes this dictionary and how it 
		shall be used.*/
		$this->entries['Extensions'] = null; //Dictionary
		
		/*(Required; shall be an indirect reference) The page tree node that shall be the 
		root of the document’s page tree (see 7.7.3, "Page Tree").*/
		$this->entries['Pages'] = null; //Dictionary
		
		/*(Optional; PDF 1.3) A number tree (see 7.9.7, "Number Trees") defining the page 
		labelling for the document. The keys in this tree shall be page indices; the 
		corresponding values shall be page label dictionaries (see 12.4.2, "Page Labels").
		Each page index shall denote the first page in a labelling range to which the 
		specified page label dictionary applies. The tree shall include a value for page 
		index 0.*/
		$this->entries['PageLabels'] = null; //Number Tree
		
		/*(Optional; PDF 1.2) The document’s name dictionary (see 7.7.4, "Name Dictionary").*/
		$this->entries['Names'] = null; //Dictionary
		
		/*(Optional; PDF 1.1; shall be an indirect reference) A dictionary of names and 
		corresponding destinations (see 12.3.2.3, "Named Destinations").*/
		$this->entries['Dests'] = null; //Dictionary
		
		/*(Optional; PDF 1.2) A viewer preferences dictionary (see 12.2, "Viewer 
		Preferences") specifying the way the document shall be displayed on the screen. If
		this entry is absent, conforming readers shall use their own current user 
		preference settings.*/
		$this->entries['ViewerPreferences'] = null; //Dictionary
		
		/*(Optional) A name object specifying the page layout shall be used when the 
		document is opened:
			SinglePage OneColumn TwoColumnLeft
			Display one page at a time Display the pages in one column
			Display the pages in two columns, with odd- numbered pages on the left
			Display the pages in two columns, with odd- numbered pages on the right
			(PDF 1.5) Display the pages two at a time, with odd-numbered pages on the left
			(PDF 1.5) Display the pages two at a time, with odd-numbered pages on the right
			TwoColumnRight TwoPageLeft TwoPageRight
		Default value: SinglePage.*/
		$this->entries['PageLayout'] = null; //Name
		
		/*(Optional) A name object specifying how the document shall be displayed when opened:
			UseNone - Neither document outline nor thumbnail images visible
			UseOutlines - Document outline visible
			UseThumbs - Thumbnail images visible
			FullScreen - Full-screen mode, with no menu bar, window controls, or any other
				window visible
			UseOC - (PDF 1.5) Optional content group panel visible
			UseAttachments - (PDF 1.6) Attachments panel visible
		Default value: UseNone.*/
		$this->entries['PageMode'] = null; //Name
		
		/*(Optional; shall be an indirect reference) The outline dictionary that shall be 
		the root of the document’s outline hierarchy (see 12.3.3, "Document Outline").*/
		$this->entries['Outlines'] = null; //Dictionary
		
		/*(Optional; PDF 1.1; shall be an indirect reference) An array of thread 
		dictionaries that shall represent the document’s article threads (see 12.4.3, 
		"Articles").*/
		$this->entries['Threads'] = null; //Array
		
		/*(Optional; PDF 1.1) A value specifying a destination that shall be displayed or 
		an action that shall be performed when the document is opened. The value shall be 
		either an array defining a destination (see 12.3.2, "Destinations") or an action 
		dictionary representing an action (12.6, "Actions"). If this entry is absent, the 
		document shall be opened to the top of the first page at the default magnification
		factor.*/
		$this->entries['OpenAction'] = null; //Array or Dictionary
		
		/*(Optional; PDF 1.4) An additional-actions dictionary defining the actions that 
		shall be taken in response to various trigger events affecting the document as a 
		whole (see 12.6.3, "Trigger Events").*/
		$this->entries['AA'] = null; //Dictionary
		
		/*(Optional; PDF 1.1) A URI dictionary containing document-level information for 
		URI (uniform resource identifier) actions (see 12.6.4.7, "URI Actions").*/
		$this->entries['URI'] = null; //Dictionary
		
		/*(Optional; PDF 1.2) The document’s interactive form (AcroForm) dictionary 
		(see 12.7.2, "Interactive Form Dictionary").*/
		$this->entries['AcroForm'] = null; //Dictionary
		
		/*(Optional; PDF 1.4; shall be an indirect reference) A metadata stream that shall
		contain metadata for the document (see 14.3.2, "Metadata Streams").*/
		$this->entries['Metadata'] = null; //Stream
		
		/*(Optional; PDF 1.3) The document’s structure tree root dictionary (see 14.7.2, 
		"Structure Hierarchy").*/
		$this->entries['StructTreeRoot'] = null; //Dictionary
		
		/*(Optional; PDF1.4) A mark information dictionary that shall contain information 
		about the document’s usage of Tagged PDF conventions (see 14.7, "Logical 
		Structure").*/
		$this->entries['MarkInfo'] = null; //Dictionary
		
		/*(Optional; PDF 1.4) A language identifier that shall specify the natural 
		language for all text in the document except where overridden by language 
		specifications for structure elements or marked content (see 14.9.2, "Natural 
		Language Specification"). If this entry is absent, the language shall be 
		considered unknown.*/
		$this->entries['Lang'] = null;  //String
		
		/*(Optional; PDF 1.3) A Web Capture information dictionary that shall contain 
		state information used by any Web Capture extension (see 14.10.2, "Web Capture 
		Information Dictionary").*/
		$this->entries['SpiderInfo'] = null; //Dictionary
		
		/*(Optional; PDF 1.4) An array of output intent dictionaries that shall specify 
		the colour characteristics of output devices on which the document might be 
		rendered (see 14.11.5, "Output Intents").*/
		$this->entries['OutputIntents'] = null; //Array
		
		/*(Optional; PDF 1.4) A page-piece dictionary associated with the document (see 
		14.5, "Page-Piece Dictionaries").*/
		$this->entries['PieceInfo'] = null; //Dictionary
		
		/*(Optional; PDF 1.5; required if a document contains optional content) The 
		document’s optional content properties dictionary (see 8.11.4, "Configuring 
		Optional Content").*/
		$this->entries['OCProperties'] = null; //Dictionary
		
		/*(Optional; PDF 1.5) A permissions dictionary that shall specify user access 
		permissions for the document. 12.8.4, "Permissions", describes this dictionary and
		how it shall be used.*/
		$this->entries['Perms'] = null; //Dictionary
		
		/*(Optional; PDF 1.5) A dictionary that shall contain attestations regarding the 
		content of a PDF document, as it relates to the legality of digital signatures 
		(see 12.8.5, "Legal Content Attestations").*/
		$this->entries['Legal'] = null; //Dictionary
		
		/*(Optional; PDF 1.7) An array of requirement dictionaries that shall represent 
		requirements for the document. 12.10, "Document Requirements", describes this 
		dictionary and how it shall be used.*/
		$this->entries['Requirements'] = null; //Array 
		
		/*(Optional; PDF 1.7) A collection dictionary that a conforming reader shall use 
		to enhance the presentation of file attachments stored in the PDF document. (see 
		12.3.5, "Collections").*/
		$this->entries['Collection'] = null; //Dictionary
		
		/*Optional; PDF 1.7) A flag used to expedite the display of PDF documents 
		containing XFA forms. It specifies whether the document shall be regenerated when 
		the document is first opened.
		See the XML Forms Architecture (XFA) Specification (Bibliography).
		Default value: false.*/
		$this->entries['NeedsRendering'] = null; //boolean
	}
	
	public function setPages($objectId){
		$this->entries['Pages'] = new MightyPDF_Reference($objectId);
	}
	
	/*public function setPageKid($objectId){
		$this->entries['Pages']->addKid($objectId);
	}*/
}

?>