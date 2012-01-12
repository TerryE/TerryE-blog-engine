<?php
/**
 *  Process sitemap.xml page.  This class outputs the XML content for a sitemap request as per the 
 *  V0.9 schema.  See <a href="http://en.wikipedia.org/wiki/Sitemap_index">Wikipedia:Sitemap index</a>
 */
class SitemapPage extends Page {

	function __construct() {

		parent::__construct();
		$cxt = $this->cxt;

		// Define AppDB access functions used in SitemapPage

		$this->db->declareFunction( array(
'getArticles'	=> "Set=SELECT a.id, a.keywords, a.date_edited AS last_edit, MAX(c.date) AS cmt_edit 
						FROM   :articles a LEFT JOIN :comments c 
						ON     a.id=c.article_id WHERE a.flag=1 GROUP BY 1",
		) );

		// Get article listing 
		$articles = $this->db->getArticles();

		$keyword = array_fill_keys( array_keys( $cxt->keywordList ), 0 );

		// Loop over articles  and keyword		
		foreach( $articles as &$a ) {

			// Determine the last edit / comment date for each article
			$lastEdit = ( isset( $a['cmt_edit'] ) && $a['cmt_edit'] > $a['last_edit'] ) ?
							$a['cmt_edit'] : $a['last_edit'];
			$a['last_edit'] = date( 'Y-m-d', $lastEdit );

			// Loop around keywords and update date if this last edit is later
			foreach( explode( ' ', $a['keywords'] ) as $k ){
				if( isset( $keyword[$k] ) && $keyword[$k] < $lastEdit ) {
					$keyword[$k] = $lastEdit;
				}
			}
		}
		// Unset reference loop var for safety
		unset( $a );

	// Generate the sitemap
	$this->assign( array( 
		'host' => $cxt->server,
		'articles' => $articles,
		'keywords' => $keyword,
		'date_format' => 'Y-m-d',
		) );

	$this->contentType = 'text/xml';
	$this->output( 'sitemap' );

	return;
	}
}
