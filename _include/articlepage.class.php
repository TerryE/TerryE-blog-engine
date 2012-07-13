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

    public  $article;       //< Copy of article, accessed by invoked AuthorArticle.
	private $admin;			//< AuthorArticle object if the user is an article admin

	/** 
	 * Constructor
	 * @param $id    optional to force page ID.  Used by the About page.
	 * @param $cxt   AppContext instance 
     */
	public function __construct( $cxt, $id = NULL ) {

		parent::__construct( $cxt );

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
		$this->article['datetime'] = date( 'D jS F Y, g:i a', $this->article['date'] );

		// If the user is an Admin then load the author utilities
		$this->admin   = $cxt->isAdmin ? new AuthorArticle( $this, $this->cxt ) : NULL;

		// Flag an error if the request is for an invalid or hidden article
		if( sizeof($this->article) == 0 || ( !$this->admin && $this->article['flag'] == 0 ) ) {
			$cxt->set( 'subOpt', 'error' );
		}

		// Acquire any inherited context
		if( is_array( $cxt->message ) ) {
			$this->assign( $cxt->message );
		}

		switch ( $cxt->subOpt) {

			// Get requests are all processed by the article display function
			case '':		$this->processArticle();			break; 

			// Post requests. These issue redirect headers so no content is required

			case 'comment':	$this->processSubmittedComment();	return; 
			case 'edit':	$this->processSubmittedEdit();		return; 

			default:	 
				$this->assign( 'error', $cxt->getTranslation( 'The requested article cannot be found.' ) ); 
		}

		// Finally drop through to display the admin page
		$this->assign( array (
			'title'           => $this->article['title'],
			'article'         => $this->article,
			'enable_comments' => ( $this->article['comments'] == 1 ),
	 		) );
		$this->output( 'article' );
	}

	/**
     * Process the Submit Comment on the Post Comment window.   A comments form is displayed if
     * the GET parameter "comments=enabled".  Submission results in a post to /article-N-comment. 
     * This is processed by AuthorArticle::processComment() and redirected to the article
     * indicating successful submission or on error back to the comment form.  
     */
	private function processSubmittedComment() {

		// Process a returned login form if any (triggered by the existance of the login post variable).
		$id  = $this->article['id'];
		if( $cxt->commentpost ) {

			// AuthorArticle::processComment returns a properly formatted message return 
			$aa = $this-admin ? $this-admin : new AuthorArticle( $this, $this->cxt );
			$commentStatus = $aa->processComment();
			$this->cxt->setMessage( $commentStatus );

			// If there is an error in the comment return, then reenable comment on refresh
			$cmt = ( $commentStatus['status'] == 'ERROR' ) ? "?comments=enabled" : "";
			$this->setLocation( "article-{$id}{$cmt}", '#postcomment' );

		} else {
			// In the case of a malformed post simple redisplay the article.
			$this->setLocation( "article-{$id}" );
		}
	}

	/**
     * Process the Save Buttom on the Edit Article window.  If an admin has requested an 
	 * inline edit then issued a save, the article content returned by the tiny MCE editor is
	 * post processed before updating.
	 * 
	 * The browser is then redirected back to the article.  This redirection is to prevent a
	 * repeated save request.
     */

	private function processSubmittedEdit() {

		// Process a returned login form if any (triggered by the existance of the login post variable).
		$cxt = $this->cxt;
		$cxt->allow( ':article_content' );
		if( $this->admin && $cxt->save ) {
			$this->admin->submittedArticle();
		}
		$this->setLocation( "article-{$this->article['id']}" );
	}
 
	/**
     * Process article for display.  
     */
	private function processArticle() {

		$cxt = $this->cxt;

		// Get any existing comments for the article
		if( $this->article['comment_count'] > 0 ) { 
			$comments = $this->db->getCommentsById( $this->article['id'] );
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
		if( $this->admin && $cxt->edit == 'enabled' ) {
			/**
			 *  - If an admin has requested an inline edit of an article then normal 
			 *    processing path is bypassed and the article content is processed 
			 * 	  using the tiny MCE editor. 
			 **/
			$this->admin->editArticle();

		} else {
			/**
			 *  - Any other article request by an admin generates a file check. See 
			 * 	  AuthorArticle::fileCheckArticle for further details. This returns
			 *    an error status which is blank on success.
			 */
			if( $this->admin ) {
				$adminURI = $this->admin->fileCheckArticle();
				if( substr( $adminURI, 0, 6 ) == 'error:' ) { 
					// The file parse failed.  The error will set by fileCheckArticle  
					// is display instead of article details
					$this->assign( 'error', substr( $adminURI, 6 ) ); 
					return;
				} else {
					$this->assign( 'admin_uri', $adminURI );
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
				$cxt->allow( ':author:code:comment:cookie:mailaddr*user*email' );
				$aa = $this->admin ? $this->admin : new AuthorArticle( $this, $this->cxt );
				$aa->generateCommentForm();
			}
		}

		if( $cxt->edit != 'enabled' ) {
			/**
			 * Note that articles can contain inter-article links of the format \<a href="article-N"\>???\</a\> 
			 * So in the case where the article is being viewed (rather than edited), the ??? need 
			 * to be replaced by the appropriate article titles if not in an admin edit mode.
			 */
			$this->article['details'] = $this->replaceArticleNames( $this->article['details'] );
		}
	}
}
