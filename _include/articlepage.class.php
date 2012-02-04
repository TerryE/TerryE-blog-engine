<?php
/** 
 *  Process article page.
 *
 *  This is perhaps the most complex page hence the intro comments.  
 *   -	The simplest code is for the general user which is to retrieve the article for viewing.
 * 		Articles can be flagged as hidden, in which case they are only visible to an admin. 
 * 
 *   -	If commenting is enabled then the post comment form is added, together with the HTML 
 * 		code to load the TinyMCE editor. Comments can only be inserted using the editor, which
 * 		produces a restricted html grammer in its content. Also note that the option to enable /
 * 		process comments is only allowed if the user has less the N pending comments.
 * 
 *   -	If the comment form is submitted the content is validated. If correct, the comment is
 * 		appended to the pending queue and the comment form disabled.  If there is an error the
 * 		comment form is renabled, with the previous content and an error displayed
 * 
 *   -	If the user is a logged-on admin then an edit option is displayed.  Selecting this
 * 		enables the FTP edit mode.  A copy of the article is dumped into the FTP directory (if
 * 		not already there). On refresh this is copy is checked for update and if so processed 
 * 		to create a new version of the article with an updated edit time. 
 * 
 *  Note that the stored article and comment content is valid UTF-8 xhtml markup, which is then
 *  displayed as-is.  Any grooming and validation is done once at input.  The only exception is that 
 *  any anchor of the form \<a href=article-N\>???\</a\> has the ??? replaced with the relevant title.
 *  This done at display time to ensure that references are tracked across article renaming.
 */ 
class ArticlePage extends Page {

    public $article;          /**< Copy of article, accessed by invoked AuthorArticle.*/

	/** 
	  * Constructor
	  * @param $id optional to force page ID.  Used by the About page.
      */
	function __construct( $id = NULL ) {

		parent::__construct();
		$cxt = $this->cxt;
		$cxt->allow( '#commentaccepted#edit#comments:commentpost:save:comment' );

		// Define AppDB access functions used in PhotoPage
		$this->db->declareFunction( array(
'getArticleById'	=> "Row=SELECT * FROM :articles WHERE id=#1",
'getCommentsById'	=> "Set=SELECT * FROM :comments WHERE article_id=#1 AND flag=1 ORDER BY date", 
		) );

		// Process parameters and context
		$subPage = $cxt->subPage;
		if ( !isset( $id ) ) {
				$id  = is_numeric( $subPage ) ? $subPage : 0;
		}


		// Get the article 
		$this->article = $this->db->getArticleById( $id );

		// If the user is an Admin then load the author utilities
		$admin   = $cxt->isAdmin ? AuthorArticle::get( $this ) : NULL;

		// Raise an error if the request is for an invalid or hidden article
		if( sizeof($this->article) == 0 || ( !$admin && $this->article['flag'] == 0 ) ) {
			$this->assign( 'error', getTranslation( 'The requested article cannot be found.' ) ); 
			$this->output( 'article' );
			return;
		}
		$this->article['datetime'] = date( 'D jS F Y, g:i a', $this->article['date'] );

		#
		# Get any existing comments for the article
		#
		if( $this->article['comment_count'] > 0 ) { 
			$comments = $this->db->getCommentsById( $id );
			$ndx = 1;
			foreach( $comments as &$cmt ) {
				$cmt['datetime'] = date('D jS F Y, g:i a',$cmt['date']);
				$cmt['ndx'] = $ndx++; # Add a running index for display on the article page
			}
			unset( $cmt );

			$this->assign( 'comments', $comments );
		}

		/** 
		 * Article processing can take place in one of a number of modes:
		 */
		if( $admin && $cxt->edit == 'enabled' ) {
			/**
			 *  - If an admin has requested an inline edit of an article then normal 
			 *    processing path is bypassed and the article content is processed 
			 * 	  using the tiny MCE editor. 
			 **/
			$admin->editArticle();

		} elseif( $admin && $cxt->save ) {
			/**
			 *  - If an admin who has requested an inline edit then issued a save, the 
			 *    article content returned by the tiny MCE editor is post processed
			 * 	  before updating.  An admin function is executed in this case before 
			 *	  redirecting back to the article.  This redirection is to prevent a
			 *    repeated save request.
			 **/
			$admin->submittedArticle();
			$this->setLocation( "article-$id" );
			return;

		} else {
			/**
			 *  - Any other article request by an admin generates a file check. See 
			 * 	  AuthorArticle::fileCheckArticle for further details. This returns a error
			 *    status which is blank on success.
			 */
			if( $admin ) {
				$adminURI = $admin->fileCheckArticle();
				if( substr( $adminURI, 0, 6 ) == 'error:' ) { 
					// The file parse failed.  The error will set by fileCheckArticle is  
					// display instead of article details
					$this->assign( 'error', substr( $adminURI, 6 ) ); 
					$this->output( 'article' );
					return;
				}
			}

			if( $cxt->comments == 'enabled' && $this->article['comments'] == 1 ) {
				/**
				 *  - If the GET parameter "comments=enabled", then this indicates that the user
				 *    has requested comments.  If the post fields are present, then the comments
				 *    form is processed including any possible comment post.  If a valid return 
				 *    has been submitted then an "accepted" message is displayed which includes a  
				 *    refresh meta otherwise the comments form is (re)displayed.
				 */
				list( $comment, $infoText ) = ( $cxt->commentpost ) ?
					AuthorArticle::get($this)->processComment() :	
					array( '', '' );
				if( $comment === TRUE ) {
					$this->assign( 'info_text', $infoText );
				} else {
					AuthorArticle::get($this)->generateCommentForm( $infoText, $comment );
				}
			}
		}

		if( $cxt->edit != 'enabled' ) {
			/**
			 * Note that articles can contain inter-article links of the format \<a href="article-N"\>???\</a\> 
			 * So in the case where the article is being viewed (rather than edited), the ??? need 
			 * to be replaced by the appropriate article titles if not in an admin edit mode.
			 */
#debugVar( 'content before', $this->article['details'] );
			$this->article['details'] = $this->replaceArticleNames( $this->article['details'] );
#debugVar( 'content after', $this->article['details'] );
		}

		$this->assign( array (
			'title'           => $this->article['title'],
			'article'         => $this->article,
			'admin_uri'       => isset( $adminURI ) ? $adminURI : '',
			'enable_comments' => ( $this->article['comments'] == 1 ),
	 		) );

		$this->output( 'article' );
	}
}
