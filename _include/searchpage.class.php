<?php
/** 
 *  Process Search page.  The search page has three modes.  If either the sub-page denotes a
 *  keyword or the q parameter is set then the apprppriate query against the articles is done. 
 *  Otherwise a blank search form is displayed. 
 */ 
class SearchPage extends Page {

	function __construct() {

		parent::__construct();
		$this->cxt->allow( 'Pq' );
		$cacheName = NULL;

		// Define AppDB access functions used in PhotoPage

		$this->db->declareFunction( array(
'searchKeyword'		=> "Set=SELECT id, date, title FROM :articles WHERE keywords LIKE '%#1%' ORDER BY date DESC",
'searchQuestion'	=> "Set=SELECT id, date, title FROM :articles 
					        WHERE MATCH (title,keywords,details) AGAINST ('#1' IN BOOLEAN MODE) ORDER BY date DESC",
		) );

		// Process parameters and context
		
		$subPage  = $this->cxt->subPage;
		$question = $this->cxt->q;

		if( $subPage ) {
			$matches = $this->db->searchKeyword( $subPage ); 
			$question = $subPage;
		} elseif( $question ) {
			$matches = $this->db->searchQuestion( $question );
		} else {
			$matches = array();
		}

		if( count( $matches ) > 0 ) {

			// Add formatted data to each match

			foreach( $matches as &$match ) {
				$match['date'] = date( "d M Y", $match['date'] + 0 );
			}
			unset( $match );

			$title = 'Search for ' . ($subPage ? $subPage : $question) . ' ';

			// Set the cache name if the page is a keysearch and the user isn't an admin

			if( $subPage && !$this->cxt->isAdmin ) {
				$cacheName = "search-$subPage";
			}

			$this->assign( array (
				'title'   => htmlspecialchars( $title , ENT_QUOTES ),
				'matches' => $matches,
				'count'   => sizeof( $matches ),
			) );

		} else {

			$this->assign( 'count', '' );

		}

		$this->assign( 'question', $question );

		echo $this->output( 'search', $cacheName );

	}
}
