<?php
/**
 *  Process rss page.  This class outputs the XML content for an RSS feed request.  
 *  The optional sub-page parameter are blog, topic and comment.  
 */
class RssPage extends Page {

	function __construct() {

		parent::__construct();

		$cxt = $this->cxt;

		// Define AppDB access functions used in RssPage

		$this->db->declareFunction( array(
'getArticles'	=> "Set=SELECT id, date, date_edited, title, details, trim_length
		 				FROM   :articles WHERE flag='1' ORDER BY date DESC LIMIT #1",
'getCheckUser'	=> "Set=SELECT id, name FROM :members WHERE MD5(CONCAT('#1',name))='#2'",
'getComments'	=> "Set=SELECT a.title, c.id, c.article_id, c.flag, c.date,
				               c.author, c.ip, c.mail_addr, c.comments
						FROM  :articles a, :comments c 
					    WHERE  a.id = c.article_id ORDER BY c.date DESC", 
		) );

		$this->assign( 'server', $_SERVER['SERVER_NAME'] );

		if( $cxt->subPage == '') {
			$this->assign( 'comments_addr', md5( $cxt->salt . $cxt->user ) );
			$this->output( 'rss' );
			return;

		} elseif( $cxt->subPage == 'blog' ) {

			$articles = $this->db->getArticles( $cxt->rss );
			$pubDate = 0;

			foreach( $articles as &$article) {
				$article['title']	= strip_tags( html_entity_decode( $article['title'], ENT_QUOTES, 'UTF-8' ) );

				$details			= $this->convertArticleRefsToAbsolute( $article['details'] );
				$summary			= substr( $details, 0, $article['trim_length'] );

				$article['summary']	= $this->convertArticleRefsToAbsolute( $summary, $article['id'] );
				$article['details']	= $this->convertArticleRefsToAbsolute( $details, $article['id'] );

				$pubDate = max( $pubdate, $article['date_edited'] );
			}
			unset( $article );

			$this->assign( array( 
				'rss_articles'	=> $articles,
				'pubdate'		=> date( 'r', $pubDate ),
				'language'		=> strtolower( $cxt->languageCode ),
				'server'		=> $cxt->server,

				) );
			$this->contentType	= "text/xml; charset=UTF-8";

			$cxt->set( 'HMTLcacheable', TRUE );                   #  This page can always be cached!
			$this->output( 'rss-blog' );
			return;

		} elseif( substr( $cxt->subPage, 0, 8 ) == 'comments' ) {
			$this->contentType	= "text/xml; charset=UTF-8";
			$md5 = substr( $cxt->subPage, 8 );
			$admin = $this->db->getCheckUser( $cxt->salt, $md5 );
			if( is_array( $admin ) && count( $admin ) == 1 ) {
	#
	#			I use a local rather than global variable for the user because the UID is obtained from 
	#			the URI, rather than a cookie and hence this page CAN be cached.  If the global variable 
	#			is set then the template output function does not cache.
	#
				$cxt->user_local = $admin[0]['name'];

				$comments = $this->db->getComments();

				foreach( $comments as &$comment) {
					$comment['date'] = date( "r", $comment['date'] );
					$comment['uid'] = md5( $cxt->salt . $cxt->user_local . ':' . $comment['id'] ); 
				}
				unset( $comment );

				$this->assign( array (
					'pubdate'      => date( "r" ), 
					'rss_comments' =>  $comments 
					) );
				$this->output( "rss-comments" );
				return;
			}
		}
		$this->invalidPage();
	}

	private $currentID;

	/**
	 * Callback for convertArticleRefsToAbsolute.
	 */
	private function replaceRelativeCallback( $m ) {
		$ref = $m[2] == '' ? $m[1] : ( $this->currentID . $m[2] );
		return "<a\nhref=\"http://{$this->cxt->server}/{$ref}{$m[3]}\"";
	}

	/**
	 * Handle relative article reference lookup.  Articles can contain inter-article links of the format 
	 * &lt;a href="article-N"&gt; <strike> and intra-article links of the format &lt;a href="#anchor"&gt;
	 * </strike>.  These don't work in the case of content embedded in RSS feeds, and so need to be 
	 * replaced by absolute URIs.
   	 */
	private function convertArticleRefsToAbsolute( $content, $id ) {
		$this->currentID = $id;
		return preg_replace_callback( 
					'/\\<a[ \n\t]*href="(article-\d+|search-\w+|(#\w+))(.*?)"/s', 
					array( &$this, 'replaceRelativeCallback'),
					$this->replaceArticleNames( $content ) );
	}
}
