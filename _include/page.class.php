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

	/**
	 * Page constructor.  This is called by any page class which extends Page to set up command attributes 
	 */
	public function __construct( ) {
		$this->data		= new stdClass;
		$this->cxt		= AppContext::get();
		$this->db		= $this->cxt->db;
		$this->language	= $this->cxt->languageCode;
		$this->langRtn	= $this->cxt->translateRtn;

		$this->db->declareFunction( array(
'getPhotoList'		=> "Set=SELECT id, title, filename FROM :photos WHERE flag='1' ORDER BY id DESC LIMIT #1",
'getArticleList'	=> "Set=SELECT id, title FROM :articles WHERE flag='1' ORDER BY date DESC LIMIT #1",
'getTitlebyIDs'		=> "Set=SELECT id, title FROM :articles WHERE id IN (#1) AND flag='1'"
		) );

		$this->init();
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
	 * Clear down all data items
	 * @param $template The template to be used to render the HTML page
	 * @param $cacheName If non-blank, the render engine dump of copy of the page to the HTML cache directory.
	 * @param $nested Optional flag.  If true then any included templates are bound at runtime
	 */
	public function output( $template, $cacheName = '', $nested = FALSE ) {

		$cxt = AppContext::get();

		if( $cxt->debug === true ) {
			header( 'Content-Type: text/plain' );
			echo 'FILES = ';	print_r ( $_FILES );
			echo 'GET = ';		print_r ( $_GET );
			echo 'POST = ';		print_r ( $_POST );
			echo 'COOKIE = ';	print_r ( $_COOKIE );
			echo 'DATA = ';		print_r ( $this->data );
			echo 'CONFIG = ';	print_r ( $cxt );
		}
		# Prefix template and compile directories with baseDir if needed

		if( !$nested ) {
			///////// Need to process headers as well
			ob_start();
			}

		$templateClass	  = "Template{$cxt->languageCode}_" . preg_replace( '/\W/', '_', $template  );
		new $templateClass( $this->data, $cxt );

		if( !$nested ) {

			// ob_start / ob_get_clean are handled by outer template
#			$output = preg_replace( '/ ^ \s* \n /xm', '', ob_get_clean() );  // output the page getting rid of blank lines
#			$outputCunks = preg_split( '!(</?pre>)!', ob_get_clean(), -1, PREG_SPLIT_DELIM_CAPTURE );
			$output = ob_get_clean();
			if( $cxt->debug === true ) {
				echo "\n" . strlen($output). " bytes generated\n\n============== HTML as follows ==============\n";
			}
			// write out copy of output to HTML cache file if caching is enable and the cache is specified
			if( $cxt->enableHTMLcache && !empty( $cacheName ) ) {
				file_put_contents( "{$cxt->HTMLcacheDir}/{$cacheName}.{$cxt->pid}", $output );
				rename( "{$cxt->HTMLcacheDir}/{$cacheName}.{$cxt->pid}", 
			            "{$cxt->HTMLcacheDir}/{$cacheName}.html" );
			}
			return $output;
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
			'blogroll' 			=> unserialize( $cxt->blogroll ),
			'header_scripts'	=> array (),
			'side_keywords' 	=> $cxt->keywordList,
			'side_articles' 	=> $cxt->sidebar > 0 ? $this->db->getArticleList( $cxt->sidebar ) : array (),
			'side_photos'		=> $cxt->photos  > 0 ? $this->db->getPhotoList( $cxt->photos ) : array (),
		) ); 
	}

	const ARTICLE_MATCH_PATTERN = '! <a \s href=".*?article- (\d+) "> \s* \?\?\? \s* </a> !x';


	private $titleById; 	  //< This static is used to pass dynamic context into the callback routine

	/**
	 * Callback for replaceArticleNames.  This uses $this->titleById to substituthe correct
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
	public function invalidPage(){

		$cxt = $this->cxt;
/*	if( $blog_server == 'webfusion' ) {
		header( 'HTTP/1.0 404 Not Found' );
		header( 'Content-Type: text/html' );
		echo "<html><head><title>Invalid Page</title><head><body><h1>Page not found</h1></body></html>";
	} else {
*/		header( 'Content-Type: text/html' );
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
		echo $this->output('invalid');
	}
}
