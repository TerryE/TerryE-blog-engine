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

		// Define AppDB access functions used in AdminPage
		$this->db->declareFunction( array(
'getUserDetails'	=> "Row=SELECT id, flag, name FROM :members WHERE email = '#1' AND password='#2' AND flag='2'",
'insertNewArticle'	=> "INSERT INTO :articles (flag, author, date, date_edited, title, details, trim_length) " .
					   "VALUES (0,'#1', #2, #3, '#4',' ',0)",
'getComment'		=> "Row=SELECT * FROM :comments WHERE id=#1 LIMIT 1",
'getUserName'		=> "Val=SELECT name FROM :members WHERE MD5(CONCAT('#1',name,':','#2'))='#3' LIMIT 1",
'getArticle'		=> "Row=SELECT * FROM :articles WHERE id = #1",
'setCommentFlag'	=> "UPDATE :comments SET flag=1 WHERE id=#1 AND flag=0", 
'deleteComment'		=> "DELETE FROM :comments WHERE id=#1 AND flag=0", 
'updateCommentCnt'	=> "UPDATE :articles a 
						SET    comment_count=(SELECT count(*) FROM :comments c WHERE c.article_id=a.id AND c.flag = 1) 
			         	WHERE  a.id=#1",
'getConfig'			=> "Set=SELECT config_name AS name, config_value AS value FROM :config
						WHERE LEFT(config_name,1)!='_'",
'updateConfigValue' => "UPDATE :config SET config_value='#2' WHERE config_name='#1'", 
		) );

		switch ( $cxt->subPage ) {

			// Post requests. These issue headers (and for sync content) so always return

			case 'login':		$this->processLoginForm();		return; 
			case 'form':		$this->processMainForm();		return; 
			case 'sync':		$this->processSyncRPC();		return; 

			// Get requests. (Logout issues a redirection so it returns) 

			case 'logout':		$this->processLogout();			return; 
			case 'comment':		$this->processComment();		break; 
			case '':			                                break; 

			default:  new InvalidPage;	 
		}

		// Finally drop through to display the admin page
		if( is_array( $cxt->message ) ) {
			$this->assign( $cxt->message );
		}
		$this->assign( 'config', $this->getConfig() );
		$this->output( 'admin' );
	}

	/**
     * Process the Login button on login form
     */
	private function processLoginForm() {
		// Process a returned login form if any (triggered by the existance of the login post variable).
		$cxt = $this->cxt;
		$cxt->allow(':login:loginemail:password' );

		$userData = '';
		if( $cxt->login && $cxt->loginemail && $cxt->password ) {
			$userData = $this->db->getUserDetails( $cxt->loginemail, md5( $cxt->password ) );
		}

		if( sizeof( $userData ) != 0 ) {
			$cxt->set( 'user', $userData['name'] );
			$cxt->set( 'token', md5( $cxt->salt . md5( $cxt->password ) ) ); 
		} else {
			$cxt->clear( 'user' );
			$cxt->clear( 'token' );
			$cxt->setMessage( array( 
				'error' => getTranslation( 'Login Error: Unknown administrator or incorrect password' ),
				) );
		}
		$this->setLocation( 'admin' );
    }

	/**
     * Process the Logout request
     */
	private function processLogout() {
		$cxt = $this->cxt;
		$cxt->clear( 'token' );
		$cxt->clear( 'user' );
		$this->setLocation( 'admin' );
	}

	/**
     * Process the Create Article, Sync Articles, Purge Caches, Resync Sidebar and Update Config buttons on main form.
     */
	private function processMainForm() {
		$cxt = $this->cxt;
		$db  = $this->db;

		if( !$cxt->isAdmin ) {
			$this->setLocation( 'admin' );
			return;
		}

		$cxt->allow(':create:newtitle:sync:purge:syncsidebar:update_config:Aconfig' );
	
		// Process a returned article create form if any (triggered by the existance of the create post variable).
		if( $cxt->create && $cxt->newtitle ) {
			// The input title needs to be both HTML escaped
			$title = htmlentities( $cxt->newtitle , ENT_NOQUOTES, 'UTF-8' );
			$date = time();
			$db->insertNewArticle( $cxt->user, $date, $date, $title );
			$check_id = $db->insert_id;
			#
			# If the article is successfully created, a redirect to the new article is issued
			#
			$this->setLocation( isset( $check_id ) ? "article-$check_id" : 'admin' );
			return;

		// Process a remote sync request
		} elseif( $cxt->sync ) {
			$cxt->setMessage( array( 
				'report' => Sync::client( $cxt->dateLastSynced, $cxt->remoteServer ),
				) );
			$this->purgeHTMLcache();

		// Process a purge cache request
		} elseif( $cxt->purge ) {
			$nHTMLpurged = $this->purgeHTMLcache();
			$nCodePurged = $this->unlinkDirFiles( $this->cxt->cacheDir, '.*' );
			$cxt->setMessage( array( 
				'report' => "$nHTMLpurged HTML files purged\n$nCodePurged Code files purged\n",
				) );

		// Process a resync sidebar request
		} elseif( $cxt->syncsidebar ) {
			$sidebarArticle=$db->getArticle( $cxt->sidebarCustomID );
			if( isset( $sidebarArticle['details'] ) ) {
				$cxt->set( '_sidebarCustom', $sidebarArticle['details'] );
				$db->updateConfigValue( '_sidebarCustom', $cxt->_sidebarCustom );
				$this->assign( 'side_custom', $cxt->_sidebarCustom );
				$this->purgeHTMLcache();
			}

		// Process a returned config update form if any (triggered by the existance of the update_config post variable).
		} elseif( $cxt->update_config && $cxt->isAdmin ) {
			$oldConfigs = $this->getConfig();
			$newConfigs = is_array( $cxt->config ) ? $cxt->config : array();
			$cnt        = 0;
			foreach( $oldConfigs as $k => $v ) {
				if( isset( $newConfigs[$k] ) && $newConfigs[$k] != $v ) {
					$db->updateConfigValue( $k, $newConfigs[$k] );
					$cnt++;
				}
			}
			if( $cnt > 0 ) {
				$cxt->setMessage( array( 
					'report' => "$cnt configuration items updated.",
					) );
			}
		}		
		$this->setLocation( 'admin' );
	}	

	/**
     * Process a comment request from RSS feed or confirm email
     */
	private function processComment() {

		$cxt = $this->cxt;
		$db  = $this->db;
		$cxt->allow('#Iid#uid#Saction' );

		// Process the admin-comment page request from RSS feed or confirm email.
		if( $cxt->id && $cxt->uid && $cxt->action ) {
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
					$action = 'Comment confirmed.';
				} else {
					$db->deleteComment( $id );
					$action = 'Comment deleted.';
				}

				if( $db->affected_rows == 0 ) {
					// set/delete did nothing so assume repeated request
					$action = 'Repeated request.  No action taken.';
				}

				$db->updateCommentCnt( $comment['article_id'] );

				$this->purgeHTMLcache();

				$this->assign( array( 
					'article'		=> $article,
					'comment'		=> $comment,
					'user'			=> $user,
					'comment_action'=> getTranslation( $action ), 
					) );
			// This is a get request so fall through to process default admin page template.				
			}
		}
	}

	/**
     * Process a Sync request from the working website synchronise request.
     */
	private function processSyncRPC() {

		$cxt = $this->cxt;
		$cxt->allow( ':check:Rsync_content:last_synced:next_synced' );
		$syncContent = $cxt->sync_content;

		if( $cxt->check == md5( $cxt->salt . $syncContent ) ) {
			$response = Sync::server( $cxt->sync_content, $cxt->last_synced, $cxt->next_synced );
			$this->purgeHTMLcache();

		} else {
			$response = array ( array( 'id' => 0, 'status' => 'MD5 mismatch' ) ); 
		}
		
		header( 'Content-Type: application/gzip' );  # The O/P is a gzipped serialised response
		echo gzcompress( serialize( $response ) );
/// @todo  admin/sync is still not giving a proper status response
	}

	private function getConfig() {
		$config = array();
		foreach( $this->db->getConfig() as $row) {
			if( $row['name'] != 'keywords' ) {
				$config[$row['name']] = $row['value'];
			}
		}
		ksort( $config );
		return $config;
	}
} 
