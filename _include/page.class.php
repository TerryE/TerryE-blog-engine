<?php
/**
 * Base class for all web page handling. In this architecture, all web requests of the form 
 * http://{sitename}/action?{parameters} are written as http://{sitename}/index.php?page=action&{parameters}. 
 * The common entrypoint \b index.php trims the action to the leading word part and invokes the class 
 * constructor for {<i>action</i>}Page to action this request.  All {<i>action</i>}Page classes extend
 * this Page class which collects the common methods and properties for webpage handling.  The presentation
 * data, db and context object instances are all maintained as protected methods.
 *
 * A special set of attributes are maintained in the $data stdClass property.  These are used
 * to pass page content to the appropriate page rendering template, and initialised through the
 * assign() and push() methods.
 */
class Page {

	protected $baseDir; 		//< optional base path for template and compile directories
	protected $data; 			//< a stdClass object to hold the data passed to the template
	protected $cxt;				//< current context
	protected $db;				//< current database
	protected $language;		//<	local copy of cxt->languageCode
	protected $langRtn;			//< local copy of cxt->translateRtn
	protected $contentType;		//< default content type header to be issued on page output

	/**
	 * Page constructor.  This is called by any page class which extends Page to set up command attributes 
	 */
	public function __construct( ) {
		$this->data			= new stdClass;
		$this->cxt			= AppContext::get();
		$this->db			= $this->cxt->db;
		$this->language		= $this->cxt->languageCode;
		$this->langRtn		= $this->cxt->translateRtn;
		$this->contentType	= "text/html; charset=UTF-8";

		$this->db->declareFunction( array(
'getPhotoList'		=> "Set=SELECT id, title, filename FROM :photos WHERE flag='1' ORDER BY id DESC LIMIT #1",
'getArticleList'	=> "Set=SELECT id, title FROM :articles WHERE flag='1' ORDER BY date DESC LIMIT #1",
'getTitlebyIDs'		=> "Set=SELECT id, title FROM :articles WHERE id IN (#1) AND flag='1'"
		) );

		$this->init();
//$redirects = array();
//foreach( $_SERVER as $k=>$v ) { if( substr($k,0,9)=='REDIRECT_' ) { $redirects[substr($k,9)]=$v; } }
//debugVar( 'redirects', $redirects );
	}

	/**
	 * Translation hook.  Write access function for exposed private properties. The property if public is 
	 * set to the value.
	 * @param $keyPhrase the key phrase to be translated
	 */
    public function translate($keyPhrase) {
		$langRtn = $this->langRtn;
		return isset($langRtn) ? $langRtn( $keyPhrase ) : $keyPhrase;
	}

	/**
	 * Assign a page variable. This can be a single key and value pair, or an associate array of key=>value pairs
	 * @param $key the page variable name or array of names
	 * @param $value optional value if $key is scalar
	 */
	public function assign( $key, $value = '' ) {
		if( is_array( $key ) ) {
			foreach( $key as $n=>$v ) $this->data->$n = $v;
		} elseif( is_object( $key ) ) {
			foreach( get_object_vars( $key ) as $n=>$v ) $this->data->$n = $v;
		} else {
			$this->data->$key = $value;
		}
	}
	/**
	 * Append a string to a page variable.
	 * @param $key the page variable name or array of names
	 * @param $value value to be appended
	 */
	public function append( $key, $value = '' ) {
		if( !property_exists($this->data, $key) ) $this->data->$key = '';
		$this->data->$key .= $value;
	}
	/**
	 * Push additions into a page variable. Note this follows the array_push calling convention, and
     * therefore uses a varargs-style parameter list.  The first parameter is the page variable array 
	 * to be pushed into and the remaining arguments the value(s) to be appended.
	 */
	public function push() {
		$argList = func_get_args();
		$key = array_shift( $argList );
		if( !property_exists( $this->data, $key ) ) $this->data->$key = array();  # Create it if it doesn't already exist
		$dataElt =& $this->data->$key;
		foreach( $argList as $value ) $dataElt[] = $value;  // And the remainder the values to be pushed
	}
	/**
	 * Clear down all data items
	 */
	public function clear() {
		$this->data = new stdClass;
	}
	/**
	 * Output using the specified template.
	 * @param $template The template to be used to render the HTML page
	 * @param $returnOP If true then the template output is return otherwise it is echoed out.
	 * @param $nested Optional flag.  If true then any included templates are bound at runtime.
	 * 
	 * This is the commom method to render the $data content using the specified template.  The
	 * HTML cache is optionally updated if the request is HTML cacheable, \b <tt>$returnOP</tt> 
	 * and \b <tt>$nested</tt> are false.  Note that the html_cache isn't checked since this 
	 * script wouldn't be called if is exists.
	 * 
	 */
	public function output( $template, $returnOP = FALSE, $nested = FALSE ) {

		$cxt = $this->cxt;
		// Clear down any cid that exists
		$cxt->clear('cid');

		$templateClass = "Template{$cxt->languageCode}_" . preg_replace( '/\W/', '_', $template  );

		if( $nested ) {

			// ob_start / ob_get_clean are handled by outer template
			new $templateClass( $this->data, $cxt );

		} else {

			ob_start();

			// If debug mode then switch to text mode and add content dump prologue
			if( $cxt->debug === true ) {
				$this->contentType = "text/plain; charset=UTF-8";
				echo 'FILES = ';	print_r ( $_FILES );
				echo 'GET = ';		print_r ( $_GET );
				echo 'POST = ';		print_r ( $_POST );
				echo 'COOKIE = ';	print_r ( $_COOKIE );
				echo 'DATA = ';		print_r ( $this->data );
				echo 'CONFIG = ';	print_r ( $cxt );
				echo "\n============== HTML as follows ==============\n";
			}

			new $templateClass( $this->data, $cxt );

			$output = ob_get_clean();

			// write out copy of output to HTML cache file if cacheable and not $returnOP
			if( $cxt->HMTLcacheable && !$returnOP ) {
				$HTMLfileName = $cxt->HTMLcacheDir . $cxt->fullPage;
				file_put_contents( $HTMLfileName . $cxt->pid, $output );
				rename( $HTMLfileName . $cxt->pid, $HTMLfileName . '.html' );
			}

			if( $returnOP ) {
				return $output;
			} else {
				header( "Content-Type: {$this->contentType}" );
				echo $output;
			} 
		}
	}

	/**
	 * Initialise common page items
	 */
	public function init() {

		$cxt = $this->cxt;

		# Now assign all of the data elements used on the standard header/footer/sidebar

		$function = $cxt->page == "index.html" ? $cxt->page : 'index';

		$this->assign( array (
			'logged_on_user' 	=> $cxt->user,
			'logged_on_admin' 	=> $cxt->isAdmin,
			'blog_name' 		=> $cxt->title,					# This may be overriden by the page code 
			'title'				=> $cxt->title,	
			'theme'				=> $cxt->skin,
			'forum_enabled' 	=> false,						# Force to be false for now
			'function'			=> $function,
			'enable_sidebar' 	=> ($cxt->sidebar > 0 ),
			'logged_in'			=> isset( $_SESSION['blogemail'] ),
			'header_scripts'	=> array (),
			'side_keywords' 	=> $cxt->keywordList,
			'side_articles' 	=> $cxt->sidebar > 0 ? $this->db->getArticleList( $cxt->sidebar ) : array (),
			'side_photos'		=> $cxt->photos  > 0 ? $this->db->getPhotoList( $cxt->photos ) : array (),
			'side_custom'		=> $cxt->_sidebarCustom,
		) ); 
	}

	const ARTICLE_MATCH_PATTERN = '! <a \s href=".*?article- (\d+) "> \s* \?\?\? \s* </a> !x';


	private $titleById; 	  //< This static is used to pass dynamic context into the callback routine

	/**
	 * Callback for replaceArticleNames.  This uses $this->titleById to substitute the correct
	 * article name into a ??? link. 
	 */
	private function replaceArticleCallback( $m ) {
		$title = array_key_exists( $m[1], $this->titleById ) ? $this->titleById[$m[1]] : "???";
		return "<a href=\"article-$m[1]\">$title</a>";
	}

	/**
	 * Handle lazy article reference lookup.  Articles can contain inter-article links of the format 
	 * <a href="article-N">???</a>.  The ??? need to be replaced by the appropriate article titles. 
	 * This algo picks up all matches in one pass, so that any such titles can be resolved with 
	 * a single D/B lookup, with a second preg replace to substitute these back into the content. 
	 * This is a little more convolved than one pass, but this two scan approach means that I can 
	 * do this lookup with a single AppDB query
	 */

	protected function replaceArticleNames( $content ) {

		if( preg_match_all( self::ARTICLE_MATCH_PATTERN, $content, $matches, PREG_PATTERN_ORDER ) ) {
			$matched_ids = implode( ',', $matches[1] );

			// Get list for article IDs with anonymous titles and query the D/B to create a id => title map
			$this->titleById = array();
			foreach( $this->db->getTitlebyIDs( $matched_ids ) as $row ) {
				$this->titleById[$row['id']] = $row['title'];
			}
			# Now replace these patterns in the article.  Using preg_replace_callback is the fastest way to do this

			$content = preg_replace_callback( 
					self::ARTICLE_MATCH_PATTERN, 
					array( &$this, 'replaceArticleCallback'),
					$content );
		}
		return $content;
	}


	/**
	 * Common method to display invalid page debug
	 */
	public function invalidPage() {

		$cxt = $this->cxt;
		if( $cxt->remoteServer == '' ) {
			header( 'HTTP/1.0 404 Not Found' );
			header( 'Content-Type: text/html' );
			echo "<html><head><title>Invalid Page</title><head><body><h1>Page not found</h1></body></html>";
		} else {
			$this->assign( array (
				'requested_page' => $cxt->page,
				'sub_page'   => $cxt->subPage,
				'sub_option' => $cxt->subOpt,
				'cookies'    => var_export( $_COOKIE, true ),
				'gets'       => var_export( $_GET, true ),
				'post'       => var_export( $_POST, true ),
				'server'     => var_export( $_SERVER , true ),
				'page'       => var_export( $this, true ),
				) );
			$cxt->set( 'HMTLcacheable', false ); // Disable HTML caching for invalid pages 
			$this->output('invalid');
		}
	}

	/**
	 * Purge HTML cache directory
	 */
	public function purgeHTMLcache() {
		return $this->unlinkDirFiles( $this->cxt->HTMLcacheDir, '.html$' );
	}

	/**
	 * Delete files from a directory based on PREG filter
	 * @param $directory    Absolute Directory path of the root directory
	 * @param $pattern      Regexp pattern to be used to decide which files to delete
	 */
	protected function unlinkDirFiles( $directory, $pattern ) {
		$cnt = 0;
		// Don't use array_filter callback as this require a per-call lambda function 
		// and ditto preg_filter since this is only for PHP >= 5.3
		$files = scandir( $directory );
		if( !is_array( $files ) ) {
			return 0;
		}
 
		foreach( $files as $f ) {
			if ( $f != '.' && $f != '..' && preg_match( "/$pattern/", $f) == 1  ) {
				unlink( "$directory/$f" );
				$cnt++;
			}
		}
		return $cnt;
	}

	/**
	 * Issue Location header to force refresh on (new) page.  
	 * @param $newLocation    Relative location to go to.
	 */
	public function setLocation( $newLocation, $anchor='' ) {
		/**
		 * Note that if the \a cid exists and cookies are not being used, then the cid 
		 * is appended to the URI.
		 */
		$cxt = $this->cxt;
		
		$cid = $cxt->cid && count($_COOKIE) == 0 ? 
					(strpos( $newLocation, "?" ) === FALSE ? '?' : '&') . "cid=" . $cxt->cid :
					'';
 		header( "Location: http://{$cxt->server}/{$newLocation}{$cid}{$anchor}" );
		header( 'Status: 302' );
	}
}
