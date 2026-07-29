<?php
class MightyPDF_Xref{
	protected $positions;
	protected $count;
	
	public function __construct(){
		$this->count = 0;
		$this->positions = array();
	}
	
	public function addPosition($pos){
		$this->positions[] = $pos+1;
		++$this->count;
	}
	
	public function length(){
		return $this->count;
	}
	
	public function build(){
		$out = '';
		if($this->count > 0){
			$out .= "\nxref\n";
			$out .= sprintf("0 %d\n", $this->count+1);
			$out .= "0000000000 65535 f \n";
			for($i=0; $i<$this->count; ++$i){
				$out .= sprintf("%010d 00000 n \n", $this->positions[$i]);
			}
		}
		return $out;
	}
}
?>