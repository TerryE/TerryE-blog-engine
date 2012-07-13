<?php
##requires class TemplateEN_archive
/**
 * Process archive page.
 * This is a new archive page algorithm which replaces a previous version.  This version moves all 
 * list windowing to the client's browser, instead doing a full extract of the article list.  The
 * major performance benefit server side is that this approach removes the request parameters for
 * month and year, allowing the article list to be HTML cached.
 *
 * Instead a script defined within the rendered page which uses dynamic HTML to implement list
 * expansion.     
 */ 
class ArchivePage extends Page {

	/** 
	 * Constructor   The contructor implements the Archive listing functions.
	 * @param $cxt   AppContext instance 
     */
	function __construct( $cxt ) {
		
		parent::__construct( $cxt );
		$cxt = $this->cxt;

		// Define AppDB access functions used in ArchivePage

		$userClause = $cxt->isAdmin ? '' : 'WHERE flag=1';
		$this->db->declareFunction( array(
'getArticles'	=> "Set=SELECT id, date, title FROM :articles $userClause ORDER BY date DESC", 
		) );

		$articles = $this->db->getArticles();
		$lastYear = date( "Y", $articles[0]['date'] + 0 );
		$lastMon  = date( "F Y", $articles[0]['date'] + 0 );
		$mList    = array();
		$yList    = array();

		// scan articles processing breaks on month and year
		foreach( $articles as $article ) {
			$date = $article['date'] + 0;
			$article['date'] = date( "d M Y", $date );
			$m = date( "F Y", $date );
			list( $dummy, $y) = explode( ' ', $m );

			if( $lastMon != $m ) {
				// break of month -- add month to yearlist
				$yList[$lastMon]	= $mList;
				$lastMon			= $m;
				$mList				= array();
			}

			if( $lastYear != $y ) {
				// break of year -- add year to articleList
				$articleList[$lastYear]	= $yList;
				$lastYear				= $y;
				$yList					= array();
			}

			$mList[]= $article;
		}
		$yList[$y]			= $mList;
		$articleList[$y]	= $yList;
		
		$this->assign( 'year_list', $articleList );
		$this->output( 'archive' );
	}
}
