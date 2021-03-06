<?php
##require HtmlUtils
/**
 * Admin functions used by author and commentor edits.  The update functions to an article can only
 * be carried out by an author or — in the case of adding a comment only — any user.  They are also
 * accessed relatively infrequently and hence the helper AuthorArticle object only created when needed. 
 */
class AuthorArticle {

    private $page;
	private $isAdmin;
    private $cxt;
	private $db;

	/**
	 * AuthorArticle constructor. Carry out processing to enable create and update functions on an article.
	 *
	 * @param $cxt     AppContext instance
	 * @param $page    Article Page object which is requesting CU functions on article
	 */
	public function __construct( $cxt, $page=NULL ) {

		$this->cxt		= $cxt;
		$this->db		= $cxt->db;
		$this->isAdmin	= $cxt->isAdmin;
		
		$this->db->declareFunction( array(
'updateArticle'		=> "UPDATE :articles SET #2 WHERE id=#1", 
'getAllKeyords' 	=> "Set=SELECT keywords FROM :articles",
'updateKeywordList' => "UPDATE :config SET config_value='#1' WHERE config_name='_keywords'",
'updatePurgeDTS'	=> "UPDATE :config SET config_value='#1' WHERE config_name='date_last_admin_purge'",
'getCommentCount'	=> "Val=SELECT count(*) FROM :comments WHERE ip='#1' AND flag = 0",
'getSameCount'  	=> "Val=SELECT count(*) FROM :comments WHERE date='#1' AND article_id=#2",
'insertComment'		=> "INSERT INTO :comments ( flag, date, article_id, author, ip, mail_addr, comments ) 
					    VALUES ('#1','#2','#3','#4','#5','#6','#7')",
'updateCommentCnt'	=> "UPDATE :articles a 
					    SET    comment_count=(SELECT count(*) FROM :comments c WHERE c.article_id=a.id AND c.flag = 1) 
					    WHERE  a.id=#1",
			) );

		// Set up property synonyms for the article fields from the referenced page if set
		if( is_object( $page ) ) {
			$this->page	= $page;
			foreach( array_keys( $page->article ) as $field ) {
				$this->$field =& $page->article[$field];
			}
		} else {
			$this->page = FALSE;
		}		
	}
	
	/**  
	 *   Check shadow file copy of article. This function is called if an author views an article. 
	 *   -	What it does is to work with a file based shadow of the article in the \b _admin directory. 
	 *   -	The name of this file is based on a hash of the article id.
	 *   -	The file is created from the article if the file doesn't exist or the last edit time is newer 
	 *      than its creation time.
	 *   -	The article is updated from the file if the file exists and its creation time is newer than 
	 *      the last edit time.
	 *   It returns the uri for the article or an error code if the updated file copy can't be parsed.
	 *
	 *   The reason for using a file-based shadow may seem strange for MySQL table based article 
	 *   content.  However the reason for this is pragmatic: the author will have FTP based access to 
	 *   the server, and there are far better WYSIWYG HTML editors that this system can provide for 
	 *   editing article content.  In my case I use the OpenOffice.org web edit mode which allows me
	 *   to toggle between WYSIWYG and HTML mark-up views. 
	 */  
	public function fileCheckArticle() {

		if( !($this->isAdmin) && !($this->page) ) return FALSE;

		$md5       =  md5( $this->cxt->salt . 'article' .  $this->id );
		$adminDir  = $this->cxt->adminDir;
		$fileName  = "{$adminDir}article-{$this->id}-$md5.html";
		$authorURI = "blogedit://{$this->cxt->server}/article-{$this->id}-$md5.html";
		$fileTime  = file_exists( $fileName ) ? filemtime( $fileName ) : 0;

		if( $this->date_edited > $fileTime ) {
			// Create a file copy if the last edit time is newer than the file (or it doesn't exist)
			$this->createArticleFileCopy( $fileName );

		} elseif( $this->date_edited < $fileTime ) {

			// If the file copy is later than the D/B copy then the copy has been edited so update the D/B one
			$status = $this->updateArticleFromFile( $fileName,  $fileTime );

		} else {

			// Garbage collection on admin directory once per week.  Delete shadow copies older than 1 week.
			$now = time();
			if( $now > ( (int) $this->cxt->date_last_admin_purge + 7*24*3000 ) ) {
				if ($dirHandle = opendir($adminDir)) {
					while (false !== ($fileCheck = readdir($dirHandle))) {
						if( $fileCheck[0] != "." && ( filemtime( $adminDir . $fileCheck ) < $now - 7*24*3600 ) ) {
						   unlink( $adminDir . $fileCheck );
						}
					}
					closedir($dirHandle);
				}
			$this->db->updatePurgeDTS( $now );
			}
		}

		return isset( $status ) ? $status : $authorURI ;
	}

	/**
	 * Create a file copy of the article for local editing when required.
	 * If the last edit time is newer than the file (or it doesn't exist) then copy D/B version of
	 * the article to a file in the _admin subdirectory, using the article_html template.  This a 
	 * simple HTML wrapper around a body which contains the article text.
	 * @param $fileName File name of the shadow article.  
	 */
	private function createArticleFileCopy( $fileName ) {

		if( !($this->isAdmin) || !($this->page) ) return FALSE;

		// Assign context for article-html template
		$this->page->assign( array(
			'title'    => $this->title,
			'author'   => $this->author,
			'details'  => $this->details,
			'iso_date' => date( 'Y-m-d\TH:i:sO', $this->date ),   // ISO format e.g. 2010-08-06T08:49:37+00:00
			'keywords' => $this->keywords,
			'flag'     => $this->flag,
		) );
		
		// Write to file and set the timestamp to the last date edited 
		file_put_contents( $fileName, $this->page->output( 'article_html', TRUE ) );
		chmod( $fileName, 0660 );
		touch( $fileName , $this->date_edited );
	}

	/**
	 * If the file is newer than the D/B then copy file content back to the D/B.  Basically the 
	 * article style is enforced from the website default style, so all style information and 
	 * unsupported tags are stripped out. The only tags that are used are defined in the 
	 * $articleTags global.  Specific rules are:
	 *
	 * -  The h1 header is a synonym for the article's title.  The article will be renamed if 
	 *    this has changed.
	 * -  In the case of img tags can have the align, alt, height and width set.
	 * -  In tables the col tag can have a width attribute.
	 * -  To accommodate HTML 4.01 transitional, and convert to XHTML, all tags are converted 
	 *    to lowercase and any unpaired \b \<p\> or \b \<li\> tags are closed.
	 * -  Any whitespace outside a \b \<pre\> tag is collapsed to a single space or CR.
	 *
	 * The code also currently assumes that the HTML editor being used by the author produced  
	 * valid HTML markup, so further.
	 */
	private function updateArticleFromFile( $fileName,  $fileTime ){

		if( !($this->isAdmin) || !($this->page) ) return FALSE;

		$newHTML = file_get_contents( $fileName );

		$new = array();

		// Pick out the meta tags IF they correspond to an article field.  Note that OOo writer
		// sometimes duplicates meta tags so I overwrite to use the last occurance
		if( preg_match_all( 
				'! <meta \s+ name    \s*=\s* "(flag|author|date|title|comments|keywords)" \s+ 
                             content \s*=\s* "(.*)" \s* /? > !xi',
				$newHTML, $matches, PREG_SET_ORDER ) ) {
			foreach( $matches as $m ) {
				$new[strtolower( $m[1] )] = $m[2];
			}
		}

		// convert the date to unix format
		if( ( isset( $new['date'] ) && strtotime( $new['date'] ) > 0) ) {
			$new['date'] = strtotime( $new['date'] );
		}

		// Pick up the title and details in the matches arrays $t and $d
		if( !preg_match( '!<h1.*?> (.*?) </h1> !xis', $newHTML, $t ) ) {
			return 'error:' . $this->cxt->getTranslation( 'Title H1 not found.' ) ; 
		}
		if( !preg_match( '!<body.*?> .*? 
						  <div \s+ id="main" .*?> (.*) 
						  </div> [\s\t\r\n]+  
						  </body>  !xsi', $newHTML, $d ) ) {
			return 'error:' . $this->cxt->getTranslation( 'Article body does not contain properly formatted main div.' ) ; 
		}

		$new['title']       = $t[1];
		$utils 				= new HtmlUtils( $this->cxt );
		$new['details']     = $utils->cleanupHTML( $d[1], HtmlUtils::ARTICLE );
		unset( $utils );
		$new['date_edited'] = $fileTime;

		if( preg_match( '! <a \s+ name \s*=\s* "endtaster" \s*> !xi', $new['details'], $m, PREG_OFFSET_CAPTURE ) ) {
			$new['trim_length'] = $m[0][1];     // REG_OFFSET_CAPTURE forces the double index variant
		}

		// Build update set clause using only changed fields
		foreach( array_keys( $new ) as $key ) {
			if ( $this->$key == $new[$key] ) {
				unset( $new[$key] );
			}
		}

		$this->db->updateArticle( $this->id, $new );

		// The keyword list is rarely changed, but the sidebar keyword list also needs updating if it is.
		if( isset( $new['keywords'] ) ){
			$this->regenKeywords();
		}

		$this->page->purgeHTMLcache();
	}

	/**
	 * Admin processing on edit of article.
	 * If the logged on user is an author who has requested an inline edit then the normal processing path  
	 * is bypassed and the article content is processed using the tiny MCE editor.  The idea here is that 
	 * the FTP mapped local editor is used for major edits but if an author wants to do a quick fix, then 
	 * the "Edit Inline" option can be be used for this.  In this case, the full window is used to edit 
	 * the article and comments, etc. are not displayed
	 */
	public function editArticle() {

		if( !($this->isAdmin) || !($this->page) ) return FALSE;

		$content = "<table>".
			"<tr><td><b>Title</b>:</td><td>{$this->title}</td></tr>\n" .
			"<tr><td><b>Keywords</b>:</td><td>{$this->keywords}</td></tr>\n" .
			"<tr><td><b>Flag</b>:</td><td>{$this->flag}</td></tr>\n" .
			"<tr><td><b>Article Date</b>:</td><td>". 
				date( 'D jS F Y, g:i a', $this->date ) . "</td></tr></table><hr/>\n" . $this->details;

		$scriptTag = TinyMCE_Compressor::renderTag( array(
			"url" => "includes/tinymce/tiny_mce_gzip.php",
			"plugins" => "table,save,advlink,emotions,inlinepopups,insertdatetime,searchreplace," . 
					"contextmenu,paste,fullscreen,visualchars,nonbreaking,xhtmlxtras,wordcount,teletype",
			"themes" => "advanced",
			"files" => array( "tiny_mce_article_bootstrap" ),
			), true );

		$this->page->assign( array (
			'escaped_details' => htmlspecialchars( $content ),
			'script_tag'      => $scriptTag,
			'edit_article'    => true,
			) );
	}

	/**
	 * Admin processing on submission of editted article.
	 * If the logged on user is an author who has requested an inline edit then the normal processing path  
	 * is bypassed and the article content is processed using the tiny MCE editor. On submission, the 
	 * editted article needs to validated and if changed then the D/B copy is updated.
	 */
	public function submittedArticle() {

		if( !($this->isAdmin) || !($this->page) ) return FALSE;

		if( $this->cxt->article_content ){
			$oldKeywords = $this->keywords;

			$utils	 = new HtmlUtils( $this->cxt );
 			$content = $utils->cleanupHTML( html_entity_decode( $this->cxt->article_content ), 
			                                HtmlUtils::ARTICLE );
			unset( $utils );

			list( $header, $this->details ) = preg_split( '!<hr/>.!s', $content, 2 );

			if( preg_match_all( '!<tr>\s+<td><b>(Title|Keywords|Flag|Article Date)</b>:</td>\s+<td>([^<]+)</td>\s+</tr>!', 
			                    $header, $matches, PREG_SET_ORDER ) ) {
				foreach( $matches as $m ) {
					$field = strtolower( $m[1] );
					if( $field == 'article date' ) {
						$date = strtotime( $m[2] );
						if( $date > 0 ) {
							$this->date = $date;
						}
					} else {
						$this->$field = $m[2];
					}
				}				
			}

			$this->edit_time = time();

			if( preg_match( '! <a \s+ name \s*=\s* "endtaster" \s*> !xi', $this->details, $m, PREG_OFFSET_CAPTURE ) ) {
				$this->trim_length = $m[0][1];     // REG_OFFSET_CAPTURE forces the double index variant
			}

		 	$this->db->updateArticle( $this->id, array( 
				flag		=> $this->flag, 
				date		=> $this->date, 
				date_edited	=> $this->edit_time, 
				title		=> $this->title,
				details		=> $this->details, 
				keywords	=> $this->keywords, 
				trim_length	=> $this->trim_length,
				) );

			$this->page->purgeHTMLcache();
		}
	}

	/**
	 * Creates the article comment form, if the user has requested to create a comment.
	 */
	public function generateCommentForm() {

		if( !($this->page) ) return FALSE;
		$cxt     = $this->cxt;
		$timeNow = time();

		if( $this->isAdmin ) {
		   /**
			* In the case of a logged on author, the comments are trusted so extra validation is 
			* not required, and no pending post limits apply
			*/ 
			$form = array(
				'comment'  => '',
				'error'    => '',
				'time'     => $timeNow,
				);
		} else { 
		   /**
			* As an anti-spam measure any non-logged on IP can only queue up to 2 pending comments.
			*/
			if( $this->db->getCommentCount( $_SERVER['REMOTE_ADDR'] ) > 1 ) {
				$this->page->assign( array (
					'comment_limit' => true,
					'remote_ip' => $_SERVER['REMOTE_ADDR'],
					) );
			} elseif( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			   /**
				* Some anti-spam measures need to be implemented for non-logged on users . As well as
				* the two pending comments per IP, I have decided to ask the user to answer a simple 
				* sum of the form "What is 3x5 + 4" instead of the normal Captcha-type validation, so 
				* the answer to the sum is encrypted in an MD5 hash on the form so that post action 
				* can validate any response.  In practice this is uncrackable unless the user knows 
				* the source and server config),   Note that a user can only add a comment if the client
				* browser provides an HTTP_USER_AGENT HTTP header.  All standard browsers return this,
				* and if the user has this disabled then it's probably a spammer.
				*/
				list( $d1, $d2, $d3 ) = array( mt_rand(1,9), mt_rand(1,9), mt_rand(1,9) );
				$token = md5( sprintf( 'CHECK: %d %d %s %s %s', ( $d1*$d2 + $d3 ), $timeNow, 
										__FILE__, $this->cxt->salt, $_SERVER['HTTP_USER_AGENT'] ) );

				$check = array( 'd1' => $d1, 'd2' => $d2, 'd3' => $d3, 'token' => $token );

				if( is_array( $cxt->message ) && 
				    isset( $cxt->message['form']) &&
					is_array( $cxt->message['form']) ) {	
					// Initialise error, author, mailaddr, comment fields
					$form = $cxt->message['form'];

				} else {
					$form = array (	
						'error'    => '',
						'author'   => $cxt->user,
						'mailaddr' => $cxt->email,
						'comment'  => '',
						);
				}
				$form = array_merge( $form, array(
					'check'    => $check,
					'time'     => $timeNow,
					'cookie'   => $cxt->cookie ? 'checked' : '',
					) );
			}
		}
	   /**
		* If the anti-spam criteria are met then the form array is initialised and this is used
		* to create the ouput fields as well as generating the TinyMCE_Compressor tag.  Failing
		* this page defaults to a normal article view. 
		*/
		if( isset( $form ) ) { 
			$scriptTag = TinyMCE_Compressor::renderTag( array(
				"url" => "includes/tinymce/tiny_mce_gzip.php",
				"plugins" => "safari,emotions,inlinepopups,preview,searchreplace",
				"themes" => "advanced",
#				"source" => true,
				"files" => array( "tiny_mce_comment_bootstrap" ),
				), true );

			$this->page->assign( array (
				'comment_form_enabled' => true,
				'script_tag' => $scriptTag,
				'form' => $form, 
				 ) );
		}
	}

	/**
	 * This function processes the article comment form after the user has submitted a comment. 
	 * If successful and the user is a logged on author then the comment is inserted into the database 
	 * unconditionally.  If successful and the user is a normal user then it is inserted as an 
	 * unconfirmed comment.  If unsuccessful, any error text and sanitized commment is returned.  Note 
	 * that the comment itself will only be displayed after the user has activated the URI in the 
	 * confirmation email, or an admin activates it.
	 *
     * @returns array( $comment, $errorText ) $comment is set to TRUE if the process is sucessful and 
     *          the sanitized comment text otherwise.
	 */
	public function processComment() {

		if( !($this->page) ) return FALSE;

		$cxt = $this->cxt;
		$cxt->allow( ':author:code:cookie:mailaddr:comment:token:time:article_id:article_content' );

		// Prevent a refresh of the form submitting a duplicate comment	
		if ( $this->db->getSameCount( $cxt->time, $this->id ) > 0 ) { 
			$this->page->setLocation( "article-{$this->id}" );
			exit;
		}

		$info = array();

		if( $this->isAdmin ) {

			// skip validation and set author / mailaddr defaults in the case of an Article admin
			$author		= $cxt->user;
			$mailaddr	= $cxt->email;  

		} else {

			// The comment is from a guest.  Carry out full validation

			$correct_token = md5( sprintf( 'CHECK: %d %d %s %s %s', 
			                               $cxt->code, $cxt->time, __FILE__, 
			                               $cxt->salt, $_SERVER['HTTP_USER_AGENT'] ) );
			$author = $cxt->author;
			$mailaddr = filter_var($cxt->mailaddr, FILTER_SANITIZE_EMAIL);

			// Validate the response fields.  Note that the answer to the "simple" question is one-way hashed and  
			// included on the form to avoid needing to use sessions (and a salt so I can open-source the code).

			if( $this->id != $cxt->article_id ) {
				$info[] = $cxt->getTranslation( 'Corrupt article ID. Please reenter comment.' );
			}
			if( $cxt->time < time() - 1800 ) {
				$info[] = $cxt->getTranslation( 'Session expired. Please reenter comment.' );
			}
			if( $cxt->author == '' ) {
				$info[] = $cxt->getTranslation( 'You must enter a name.' );
			}
			if( $mailaddr === FALSE ) {
				$info[] = $cxt->getTranslation( 'You must specify a valid confirmation_email' );
			}
			if( $cxt->token != $correct_token ) {
				$info[] = $cxt->getTranslation( 'Wrong answer.  Try again.' );
			}
			if( $cxt->cookie == 1 ) {
				$cxt->set( 'user', $author );
				$cxt->set( 'email', $mailaddr );
			}
		}

	   /**
		* Note that we can't trust that the user hasn't disabled the TinyMCE editor and is directly 
		* submitting malicious HTML markup, so any comment text must be cleaned up.  The comment variable
        * also overloaded === FALSE if the comment can't be successfully cleaned up and later === TRUE
		* if the comment has been posted so will not require redisplay.  
		*/
		$utils = new HtmlUtils( $cxt );
		$comment = $utils->cleanupHTML( html_entity_decode($cxt->comment), HtmlUtils::COMMENT );
		unset( $commment );

		if( substr( $comment, 0, 7 ) == '<error>') {
			$info[] = substr( $comment, 7 );
			$comment = '';
		}

		if( count( $info ) > 0 ) {
			// In the case of an error, return the error info and the attempted comment
			$resp = array( 
				'status' => 'ERROR', 
				'form'   => array(
					'error'    => implode( "<br />\n", $info ),
					'author'   => $cxt->author, 
					'mailaddr' => $cxt->mailaddr, 
					'comment'  => $comment ),
				);

		} else {
			// In the case of no error, insert the comment into the comments table and use the last id
			// return to set the comment ID. Note if made by an author then flag = 1 and 
			// no email confirmation is required.
			$this->db->insertComment( 
				( $this->isAdmin ? 1 : 0 ) , $cxt->time, $this->id, $author, 
				$_SERVER['REMOTE_ADDR'], $mailaddr, $comment
				);
			$commentID = $this->db->insert_id;

			if( $this->isAdmin ) {
				// in the case of an admin / author the comment is immediately published 
				// so update the article comment count and refresh to the comments anchor  
				$this->db->updateCommentCnt( $this->id );
				$this->page->purgeHTMLcache();
				$info[] = $cxt->getTranslation( 'Comment registered.' );

			} else {

				// Prepare and send the email confirmation request to the user
				$this->page->assign( array (
					'title' => $this->title,
					'sitename' => $_SERVER['HTTP_HOST'],
					'comment_id'=> $commentID,
					'comment_uid'=> md5( "{$this->cxt->salt}$mailaddr:$commentID" ),
					 ) );
				$mailMsg = $this->page->output( 'confirm_email', TRUE );
				$mailSubject = sprintf( $cxt->getTranslation( 'Confirmation of comment on post %s' ),  $this->title );
				$mailHeaders = implode ("\r\n", array( 
					"From: do_not_reply@ellisons.org.uk",
					"MIME-Version: 1.0",
					"Message-ID: " . sprintf( '%05u.%15.15s', $this->id, md5($cxt->time) ),
					"Content-Type: text/html; charset=UTF-8",
					"Content-Transfer-Encoding: 8bit",
					"Date: " . date( 'r' ),
					"Subject: $mailSubject",
					) );

/*TESTING*/$this->page->debugVar( 'mailHeaders', $mailHeaders );
/*TESTING*/$this->page->debugVar( 'mailMsg', $mailMsg );
/*TESTING*/#	mail( $mailaddr, $mailSubject, $mailMsg, $mailHeaders );
				$info[] =	$cxt->getTranslation( 'Comment registered.  It will be displayed after email confirmation' );
			}
			$resp = array(
				'status' => 'OK', 
				'info_text' => $info[0],
				);
		}
		return $resp;
	}
	/**
	 *  Regenerate keyword summaries in the Config table.
	 *  This function is called whenever the keywords for an article have changes.  The list of unique 
	 *  keywords and their counts is cached in the preudo-static config variable keywords and this needs to
	 *  be regenerated whenever such a change has been made.  I want to avoid doing this every page to
	 *  generate the side-bar, but a simple complete regen per article change is adequate here. 
	 */
	public function regenKeywords() {
		$count = array();
		$maxCount = 0;
		foreach( $this->db->getAllKeyords() as $article ) {
			foreach( preg_split("/[\s,:]+/", $article['keywords'] ) as $keyword ) {
				if( $keyword == '' ) continue; 
				@$count[$keyword]++;
				if( $count[$keyword] > $maxCount ) {
					$maxCount = $count[$keyword];
				}
			}
		}

		foreach( $count as $keyword => &$keyCount ) {
			$keyCount = 75 + round ( 125 * $keyCount / $maxCount, -1 ); # map 0..$maxKeyCount onto 75..200
		}
	 
		uksort( $count, 'strcasecmp' ); # do a case insensitive key sort

		$newKeywordList = serialize( $count );
		if( $this->cxt->keywords != $newKeywordList ) {
			$this->db->updateKeywordList( $newKeywordList );
		}
		return ( $this->cxt->keywords != $newKeywordList );
	}
}
