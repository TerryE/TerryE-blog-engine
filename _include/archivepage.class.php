<?php
/**
 *  Process archive page. Parameters are:  
 *   - y (optional, POST)  Expand year
 *   - m (optional, POST)  Expand month 
 */ 
class ArchivePage extends Page {

	function __construct() {
		
		parent::__construct();
		$cxt = $this->cxt;
		$cxt->allow( '#Im#Iy' );

		$y = $cxt->y;
		$m = $cxt->m;

		// Define AppDB access functions used in PhotoPage

		$this->db->declareFunction( array(
'getArticlesByMY'	=> "Set=SELECT id, date, title FROM :articles
						    WHERE  MONTH(FROM_UNIXTIME(date))=#1 AND YEAR(FROM_UNIXTIME(date))=#2 ORDER BY date DESC", 
'getArticleCntByMY' => "Set=SELECT YEAR(FROM_UNIXTIME(date)) as year, MONTH(FROM_UNIXTIME(date)) as m,
							       MONTHNAME(FROM_UNIXTIME(date)) as month, COUNT(*) as count 
							FROM  :articles GROUP BY 1 DESC, 2 DESC, 3",
		) );

		# if y or m are invalid, or the article count is 0 then default to default listing; 

		if( $m >0 && $m <= 12 && $y >= 2006 && $y <= date( 'Y' ) ) {

			$articles = $this->db->getArticlesByMY( $m, $y );
			foreach( $articles as &$article ) {
				$article['date'] = date( "d M Y", $article['date'] + 0 );
			}
			unset( $article );

			$this->assign( array( 
				'month_articles'	=> $articles,
				'month'				=> date( "F Y", mktime( 0, 0, 0, $m, 1, $y) ),
				) );
		} else {
			$this->assign( 'month', '' );
		}

		$years = array();
		$months = $this->db->getArticleCntByMY();

		foreach( $months as $mon ) {
			$mmm_yy = $mon['month'] . ' ' . $mon['year'];

			if( !array_key_exists( $mon['year'], $years ) ) {
				$years[ $mon['year'] ] = array();
			}
			$years[$mon['year']][$mmm_yy] = array( 'm' => $mon['m'], 'count' => $mon['count'] );
		}
		$this->assign( 'years', $years );
		echo $this->output( 'archive' ) ;
	}
}
