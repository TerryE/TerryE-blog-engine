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
'getArticlePreamble'	=> "Set=SELECT id, date, title, LEFT(details, 160) AS descript  
				 				FROM   :articles WHERE flag='1' ORDER BY date DESC LIMIT #1",
'getCheckUser'			=> "Set=SELECT id, name FROM :members WHERE MD5(CONCAT('#1',name))='#2'",
'getComments'			=> "Set=SELECT a.title, c.id, c.article_id, c.flag, c.date,
						               c.author, c.ip, c.mail_addr, c.comments
								FROM  :articles a, :comments c 
							    WHERE  a.id = c.article_id ORDER BY c.date DESC", 
		) );

		$this->assign( 'server', $_SERVER['SERVER_NAME'] );

		if( $cxt->subPage == '') {
			$this->assign( 'comments_addr', md5( $cxt->salt . $cxt->user ) );
			echo $this->output( 'rss', 'rss' );
			return;

		} elseif( $cxt->subPage == 'blog' ) {
			$this->assign( 'pubdate', date("r") );
			$articles = $this->db->getArticlePreamble ( $cxt->rss );
			foreach( $articles as &$article) {
				$article['title'] = strip_tags( $article['title'] );
				$desc = trim( strip_tags($article['descript']) );
				$article['descript'] = substr( $desc, 0, strlen( $desc ) - strpos( strrev( $desc ), ' ' ) ) . '...';
				$article['date'] = date("r", $article['date']);
			}
			unset( $article );
			$this->assign( 'rss_articles', $articles );

			header( 'Content-Type: text/xml' );
			$cxt->user = '';                    #  This page can always be cached!
			echo $this->output( 'rss-blog', 'rss-blog' );
			return;

		} elseif( substr( $cxt->subPage, 0, 8 ) == 'comments' ) {
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
				header( 'Content-Type: text/xml' );
				echo $this->output( "rss-comments", "rss-{$cxt->subPage}" );
				return;
			}
		}
		$this->invalidPage();
	}
}
