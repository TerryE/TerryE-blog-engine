<?php
/** 
 *  Invalid page.  Hook to call the Page::invalidPage() method.
 */ 
class InvalidPage extends Page {

	/**
	 * Invalid Page constructor.  This simple calls the parent invalidPage method.
	 * @param $cxt   AppContext instance 
	 */

	public function __construct( $cxt ) {

		parent::__construct( $cxt );
		$this->invalidPage();
	}
}
