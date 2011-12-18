<?php
/** 
 *  Process admin page. 
 *  As a general strategy, the admin functions as an option on the appropriate page if the user
 *  is already logged on as an admin.  Hence there us little to do on the admin page itself.  
 *  Currently it supports limited options: admin logon/off and create a blank article.
 *  \todo syncing, comment confirmation 
 */
class AdminPage extends Page {

	function __construct() {

		parent::__construct();
		$cxt	= $this->cxt;
		$subPage= $cxt->subPage;
		$subOpt = $cxt->subOpt;

		// Define which Get, Post, and user variables are allowed
		$cxt->allow(	'#Iid#uid#Saction' . 
					':login:loginemail:password:create:newtitle:check:sync:sync_content:Harticle_content' );			

		// Define AppDB access functions used in AdminPage

		$db		= $this->db;	
		$db->declareFunction( array(
'getUserDetails'	=> "Row=SELECT id, flag, name FROM :members WHERE email = '#1' AND password='#2' AND flag='2'",
'insertNewArticle'	=> "INSERT INTO :articles (flag, author, date, date_edited, title, details, trim_length) " .
					   "VALUES (0,'#1', #2, #3, '#4',' ',0)",
'getArticleId'		=> "Val=SELECT id FROM :articles WHERE date = #1",
'getComment'		=> "Row=SELECT * FROM :comments WHERE id=#1 LIMIT 1",
'getUserName'		=> "Val=SELECT name FROM :members WHERE MD5(CONCAT('#1',name,':','#2'))='#3' LIMIT 1",
'getArticle'		=> "Row=SELECT * FROM :articles WHERE id = #1",
'setCommentFlag'	=> "UPDATE :comments SET flag=1 WHERE id=#1", 
'deleteComment'		=> "DELETE FROM :comments WHERE id=#1", 
'updateCommentCnt'	=> "UPDATE :articles a 
						SET    comment_count=(SELECT count(*) FROM :comments c WHERE c.article_id=a.id AND c.flag = 1) 
			         	WHERE  a.id=#1",
		) );

		// Process a returned login form if any (triggered by the existance of the login post variable).
		if( $cxt->login && $cxt->loginemail && $cxt->password ) {
			$userData = $db->getUserDetails( $cxt->loginemail, md5( $cxt->password ) );

			if( sizeof( $userData ) == 0 ) {
				$this->assign( 'error', 'Unknown administrator or incorrect passwrd' );
			} else {
				$cxt->set( 'user', $userData['name'] );
				$cxt->set( 'token', md5( $cxt->salt . md5( $cxt->password ) ) ); 
				header( "Location: admin" );
				return;
			}

		// Process a returned article create form if any (triggered by the existance of the create post variable).
		} elseif( $cxt->create && $cxt->newtitle && $cxt->isAdmin ) {
			// The input title needs to be both HTML escaped
			$title = htmlentities( $cxt->newtitle , ENT_NOQUOTES, 'UTF-8' );
			$date = time();
			$db->insertNewArticle( $cxt->user, $date, $date, $title );

			$check_id = $db->getArticleId( $date );
			#
			# If the article is successfully created, a redirect to the new article is issued
			#
			header( "Location: " . ( isset( $check_id ) ? "article-$check_id" : "admin" ) );
			return;

		// Process a remote sync request
		} elseif( $cxt->sync && $cxt->isAdmin ) {
			$this->assign( 'report', Sync::client( $cxt->dateLastSynced, $cxt->remoteServer ) );

		// Process the admin-logout page request
		} elseif( $subPage == 'logout' ) {
			$cxt->clear( 'token' );
			$cxt->clear( 'user' );
			header( "Location: admin" );
			return;

		// Process the admin-comment page request from RSS feed or confirm email.
		} elseif( $cxt->subPage == 'comment' && $cxt->id && $cxt->uid && $cxt->action ) {
			$id      = $cxt->id;
			$uid     = $cxt->uid;
			$confirm = ($cxt->action  == 'confirm');
			$comment = $db->getComment( $id );

			if( count( $comment ) > 0 ) {
				if( md5( "{$cxt->salt}$comment[mail_addr]:$id" ) == $uid ) {
					$name = $comment['author'];
				} else {
					$name = $db->getUserName( $cxt->salt,$id, $uid );
				}
			}

			if( isset ($name ) ) {
				$user = $name;
				$article = $db->getArticle( $comment['article_id'] );

				if( $confirm ) {
					$db->setCommentFlag( $id );
					$action = 'comment confirmed';
				} else {
					$db->deleteComment( $id );
					$action = 'comment deleted';
				}

				$db->updateCommentCnt( $comment['article_id'] );

				unlinkDirFiles( 'html_cache', "article-{$comment['article_id']}.html|sitemap\.html|rss-comment.*\.html" );

				$this->assign( array( 
					'article'		=> $article,
					'comment'		=> $comment,
					'user'			=> $user,
					'comment_action'=> $action, 
					) );
			} else {
				return; # Abort without output unless we have a confirmed action request
			}

		// Process the admin-sync page request from the working website synchronise request.
		} elseif( $subPage == 'sync' ) {
			if( $cxt->check == md5( $cxt->salt . $cxt->sync_content ) ) {
/*????*/		header( 'Content-Type: application/plain' );  # The O/P is a gzipped serialised response
				echo Sync::server( $cxt->sync_content );
			} else {
				header( 'Content-Type: application/gzip' );  # The O/P is a gzipped serialised response
				echo gzcompress( serialize( array ( array( 'id' => 0, 'status' => 'MD5 mismatch' ) ) ) ); 
			}
			return;
		}

		// Finally drop through to display the admin page
		$this->assign( 'blog_server', $cxt->server );
		echo $this->output( 'admin' );
	}
} 