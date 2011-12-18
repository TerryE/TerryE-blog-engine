<?php
/** 
 *  Invalid page.  Hook to call the Page::invalidPage() method.
 */ 
class InvalidPage extends Page {

	public function __construct() {

		parent::__construct();
		$this->invalidPage();
	}
}
