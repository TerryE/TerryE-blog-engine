<?php
/** 
 *  Home (a.k.a Index) page.  Root page for blog site.  Displays a prelim of a configurable number of 
 *  articles. The length of each prelim is set by the article field \b trim_length which is itself 
 *  calculated on article update from the position of the \b endtaster anchor.
 */ 
class HomePage extends Page {
	/**
	 * Article constructor, which display the blog home page
	 * @param $cxt   AppContext instance 
	 */
	function __construct( $cxt ) {

		parent::__construct( $cxt );
		$cxt = $this->cxt;
		$cacheName = NULL;

		// Define AppDB access functions used in HomePage

		$this->db->declareFunction( array(
'getHomeArticles' => "Set=SELECT  title, author, id, encoding, comment_count, 
                                  LEFT(details, trim_length) as content, 
                                  FROM_UNIXTIME(date,'%a %D %b %Y %k:%i %p') as datetime 
                          FROM   :articles  WHERE flag='1' ORDER BY date DESC LIMIT #1",
		) );
	/*
	 * Dispatch Code for the index page 
	 */
		$articles = $this->db->getHomeArticles( $cxt->home );

		# remove any trailing </p> tags (to keep xhtml 1.0 conformance as this added after the More >>>

		foreach( $articles as &$a ) {
			$a['content'] = $this->replaceArticleNames( $a['content'] );
		}

		$cacheName = ( $cxt->user == '' && $cxt->requestCount == 1 ) ? "index" : NULL;

		$this->assign( 'main_articles', $articles );
		$this->output( 'index' );
	}
}
