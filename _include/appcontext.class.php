<?php
/**
 * This class provides the blog/user/page context for the application.  The \a context is the
 * (quasi) static configuration within which this particular web request must execute, and this 
 * context is derived from three components:
 *
 *  -  A number of configuration parameters are maintained in the database \b config table, which 
 *     contains a set of name / value pairs.
 *
 *  -  A safe encapsulation providing a unified access mechanism for HTTP get/post/file and cookie
 *     variables.  All such variables must be explicitly declared by the allow() method. This  
 *     encapsulation also enforces type checking in the case of integer variables and does an  
 *     \b htmlentities() scrub of normal strings.  Any missing "allowed" variables are set to FALSE.
 *
 *  -  Some derived parameters based on user-specific cookies which permit persistent user logon, etc..   
 *
 * The class uses a standard single class object pattern, so a single object instance is created on 
 * demand by the static method get.  
 *
 * A key design objective in developing this class was to make referencing such context parameters  
 * simple to code and robust, so all context parameters are referenced as properties to this object, 
 * with read access implemented by overloading using the __get() method. This also handles returns
 * a sensible default so instead of the application code having to guard any context access with 
 * a predicate <tt><b>isset()</b></tt> function call, the application logic can simply refer to 
 * <tt>$cxt->email</tt> or whatever. This will always default to a value ( <tt>=== FALSE</tt> if unset ). 
 *
 * Another issue which the context now addresses is support for output messaging when implementing a
 * <a href="http://en.wikipedia.org/wiki/Post/Redirect/Get" >Post/Redirect/Get</a> template.  All 
 * non-idempotent POSTs must issue a 302 response to a display URI to avoid issues around double 
 * posting.  However, if the POST detects an error or needs to output other content, then this 
 * requires some form of session context to be passed to the GET request.  However, I want to avoid
 * introducing the general overhead of PHP session management for this occasional use, so a message
 * table is used and the context identity ("\b cid") is used.  If the post has cookies set then this
 * is passed by cookie, and if not by a request parameter.  See setMessage() for further details.
 */

class AppContext {

	private $attrib;				// Std class used to hold context attributes
	private $attribType;			// Array used to hold context type (used in set function)

	/*
	 * Load the blog context
	 * @param $bootstrapContext  A 6 parameter context defined and documented in \ref index.php. 
	 */
	public function __construct( $bootstrapContext ) {

		//  Instantiate the  AppLogger, AppDB and stdClass container for publicly readable context properties 
		$log	= new AppLogger( 
					"$_SERVER[REQUEST_METHOD]\t$_SERVER[REQUEST_URI]\t$_SERVER[HTTP_USER_AGENT]",
					$bootstrapContext['START_TIME']
					);
		$db		= new AppDB( $bootstrapContext['DB_CONTEXT'], $log );   
		$a		= new stdClass;

		$a->db				= $db;
		$a->log				= $log;
		$a->rootDir			= $bootstrapContext['ROOT_DIR'];

		$this->attrib		= $a;
		$this->attribType	= array();

		// Define access functions used in context processing
		$db->declareFunction( array(
'getConfig'			=> "Set=SELECT * FROM :config",
'checkPassword'		=> "Row=SELECT MD5(CONCAT('#1',password)) AS token, flag, email FROM :members WHERE name = '#2'",
'insertMessage'		=> "INSERT INTO :messages (message, time) VALUES ('#1', UNIX_TIMESTAMP(NOW()))", 
'getMessage' 		=> "Val=SELECT message FROM :messages WHERE id='#1'",
'pruneMessages' 	=> "DELETE FROM :messages WHERE time<(UNIX_TIMESTAMP(NOW())-#1)",
'getTranslation'	=> "Val=SELECT phrase FROM :language WHERE id = '#1' AND lang_code = '#2'",
'insertTranslation' => "INSERT INTO :language VALUES ('#1', '#2', '#3')",
		) );

		// Fetch the config table from the database and add to context
		foreach( $db->getConfig() as $row ) {
			$param		= $row['config_name'];
			$a->$param	= $row['config_value'];
		}

		// For the std dirs, prefix with the rootDir and add trailing /.  Note that in the case of the
		// includes and cache directories, these entries are option and will default to INC_DIR and CACHE_DIR
		$a->HTMLcacheDir = $a->rootDir . DIRECTORY_SEPARATOR . $a->HTMLcacheDir . DIRECTORY_SEPARATOR;
		$a->adminDir	 = $a->rootDir . DIRECTORY_SEPARATOR . $a->adminDir . DIRECTORY_SEPARATOR;
		$a->templateDir	 = $a->rootDir . DIRECTORY_SEPARATOR . $a->templateDir . DIRECTORY_SEPARATOR;
		$a->cacheDir	 = isset( $a->cacheDir ) ? 
							$a->rootDir . DIRECTORY_SEPARATOR . $a->cacheDir . DIRECTORY_SEPARATOR :
							$bootstrapContext['CACHE_DIR'] . DIRECTORY_SEPARATOR;

		if( isset( $a->includeDir ) ) {
			// includeDir is a searchlist so unpack to array, again each with rootDir prefix and trailing / 
			foreach ( explode( ';', $a->includeDir ) as $d) {
				$includes[]	= $a->rootDir . DIRECTORY_SEPARATOR . $d . DIRECTORY_SEPARATOR;
			}
			$a->includeDir  = $includes; 
		} else {
			$a->includeDir  = $bootstrapContext['INC_DIR'] . DIRECTORY_SEPARATOR; 
		}

		$a->keywordList	= unserialize( $a->_keywords ); 
		$a->pid			= getmypid();

		// The config variable debugParam defines the name of the GET debug switch.  This is 
		// configurable because setting this GET parameter will force the page into debug
		// diagnostics, and therefore must be configurable / removeable.  The context parameter
		// debug is set to this value.
		$debugSwitch 	= $a->debugParam; 
		if( $debugSwitch ) {
			$this->allow( "Cuser,Ctoken,Cemail,Gpage,G$debugSwitch" );
			$a->debug = ($a->$debugSwitch !== FALSE );
		} else {
			$this->allow( "Cuser,Ctoken,Cemail,Gpage" );
			$a->debug = FALSE;
		}

		if( isset( $a->debugFile ) ) {
			$log->setLog( $a->debugFile );
		}

		// Check if the user is logged on.  This is determined from the user and token cookies. So
		// cookies must be enables to log on.  If successful then the users email address is added 
		// to the context

		$check = ( $a->user && $a->token ) ? 
			$db->checkPassword( $a->salt, $a->user ) : 
			array();

		if ( sizeof( $check ) > 0 && $check['token'] == $a->token ) {
			$this->set( 'email', $check['email'] );
		} else {
			$this->clear( 'user'  );
			$this->clear( 'token' );
		}

		// The user is an admin if a user record exists and it is flagged (=2) as as admin
		$a->isAdmin 	= isset( $a->user ) && $a->user && $check['flag'] == 2;

		// Set the request variable count, and set the cacheable flag if the user is a guest, there
        // are no request parameters other than the page parameter and HTML caching is enabled.
		$a->requestCount  = count( $_GET ) + count( $_POST );
		$a->HMTLcacheable = $a->enableHTMLcache && $a->requestCount == 1 && !($a->isAdmin);

		// If the query has a "cid" get parameter, then use this to retrieve the associated message
		$a->message     = $this->getMessage();

		// Decode the requested page. Note that hyphenation is embed arguments.  
		// So the URI for Article 1 is "article-1" etc.
		$parts			= explode( '-', ($a->page ? $a->page : 'index' ) );
		$a->fullPage	= $a->page;
		$a->page		= $parts[0];
		$a->subPage		= isset( $parts[1] ) ? $parts[1] : '';
		$a->subOpt		= isset( $parts[2] ) ? $parts[2] : '';
	}   

	/**
	 * Allow access to list of HTTP get/post/cookie variables
	 * @param $varList list of variables to be processed. 
	 *
	 * The application declares which get/post/cookie parameters will be used by declaring them in an
	 * allow() call.  This takes a single argument which gives a compact list of the variables that
	 * can be accessed.  This is an explicit call / list for security reasons: to limit the opportunity 
	 * for injection attacks.  Multiple allow() invocations are permitted. 		
	 *
	 * The variable list is a string which is a repeat of \<type designator\>\<var name\>.
	 *  -  The type designator is a mandatory sygil character denoting request type followed by an
	 *     optional type:
	 *     -  \b G (alias \b #) This is a get variable
     *     -  \b P (alias \b :) This is a post variable
     *     -  \b C (alias \b *) This is a cookie variable
     *     -  \b F (alias \b !) This is a file parameter
	 *     -  \b I The type is integer   
	 *     -  \b H The type is Hex string   
	 *     -  \b A The type is an Array (used in tabular forms)   
	 *     -  \b S The type is string
	 *     -  \b R The type is Raw string (no HTML escaping is done)   
	 *     -  \b F The type is (uploaded) file
     *
     *  -  The variable name is a lowercase word.
     *
     *     An optional comma separator can be used to enhance readability.
	 */
	public function allow($varList) {

		$varList	= str_replace( ',', '', $varList );
		$va 		= preg_split( "/([,]?[#:*GPCF][ISAFHR]?)/", $varList, -1,  PREG_SPLIT_DELIM_CAPTURE);
		$a 			= $this->attrib;

		array_shift( $va );
		while ( $kind = array_shift ($va) ) {
			if( $kind[0] == ',' ) {
				$kind=substr( $kind, 1 );
			} 
			$name = array_shift ($va);
			$result = FALSE;

			switch ( $kind[0] ) {

				case '#':
				case 'G':
					if ( isset( $_GET[$name] ) ) $result = $_GET[$name];
					break;

				case ':':
				case 'P':
					if ( isset( $_POST[$name] ) ) $result = $_POST[$name];
					break;

				case '*':
				case 'C':
					if ( isset( $_COOKIE['blog_' . $name] ) ) $result = $_COOKIE['blog_' . $name];
					$this->attribType[ $name ] = 'C';
					break;

				case '!':
				case 'F':
					if ( is_array( $_FILES[$name] ) ) $result = $_FILES[$name];
					$kind = '!F';
					$this->attribType[ $name ] = 'F';
					break;

				default:
					error_log( "Invalid parameter request, $name, type $kind" );

			}

			if ( $result !== FALSE ) {
				switch ( substr( $kind, 1, 1 ) ) {
					case FALSE:
					case 'S':
						$result = htmlentities ( trim ( $result ) , ENT_NOQUOTES )  ;
						break;
					case 'I':
						settype( $result, "integer" );
						break;
					case 'A': case 'F': case 'H': case 'R':
						break;
				}
			}
			$a->$name = $result;
		}
		return $this;
	}

	/**
	 * Read access function for HTTP variables to overload the  \b -> operator.
	 * @param $name name of variable to be retrieved
	 * @return value of variable, or FALSE if not set
	 *     
	 * The type (GET/POST/FILE/COOKIE) is defined at construction or allowed.  The argument can 
     * be optionally sanitised to integer or stripped string. Invalid requests are logged but
	 * FALSE is returned.     
	 */
    public function __get($name) {

		return( isset( $this->attrib->$name ) ? $this->attrib->$name :  FALSE );

    }

	/**
	 * Assign a context variable. 
     * @param $name Name of context variable to be set
	 * @param $value Value to be set

     * This function enables the application to initial context attributes in the rare where 
     * they need to be overriden after context initialialisation. Unlike read access, context
	 * attributes should not be easily / accidentally overwritten, so  an explicit \b set
	 * function to implement the property write rather than overloading using the <tt>__set</tt>
	 * function.
	 */
	public function set( $name, $value = '' ) {

		if ( isset( $this->attribType[$name] ) && 
		     $this->attribType[$name] == 'C' ) {
			$status = setcookie( "blog_$name", $value, time() + 3600*24*94, '/', $_SERVER['HTTP_HOST'] );
		}

		$this->attrib->$name = $value;
	}

	/**
	 * Clear function for HTTP variables.
	 *     
	 * @param $name Name of variable to be unset.  
	 * In the case of COOKIE variables, the cookie is also cleared.
	 */

    public function clear( $name ) {

		if( isset( $_COOKIE["blog_$name"] ) ) {
			setcookie( 'blog_' . $name,  '', 0, '/', $_SERVER['HTTP_HOST'] );
		}
		unset( $this->attrib->$name );
	}

	/**
	 * Set message/output to be passed to following get as a msg paramerter.  This function 
     * inserts the message in the message table and sets the \b cid based on the auto-increment
	 * id for the message with a 3 digit check prefix to prevent silly abuses.  Note that
	 * unlike most context variables the cid is only published as a public context variable
	 * as part of a setMessage() invocation.
	 *
	 * @param  $msg   string variable or array to be passed.
	 */

    public function setMessage( $msg ) {
		/**
		 * This \b cid is forced to a cookie type if cookies already exist, otherwise it is 
  		 * be passed as a parameter to the subsequent URI for automatic retrieval.
		 */
		if( count( $_COOKIE ) > 0 ) {
			$this->attribType['cid'] = 'C';
		}
		$msg = json_encode( is_array( $msg ) ? $msg : array( $msg ) );
		$this->db->insertMessage( $msg );
		$id  = (string) $this->db->insert_id;
		$this->set( 'cid', substr( md5( $id . 'cid salt' ), 0, 3 ) . $id ); 
	}

	/**
	 * Retrieve message/output previously passed to get as a msg paramerter.  This function 
     * uses the \b cid to retrieve the message from the message table.  The cid can be loaded
     * from either a cookie or get parameter.  The message table is also occasionally pruned 
	 * to remove stale messages.
	 *
	 * @param  $msg   string variable or array to be passed.
	 */

    private function getMessage() {

		$msg = '';
		if( isset( $_GET['cid'] ) || isset( $_COOKIE['blog_cid'] ) ) {

			$cid = isset( $_GET['cid'] ) ? $_GET['cid'] : $_COOKIE['blog_cid'];
			$chk = substr( $cid, 0, 3 );
			$id  = substr( $cid, 3 );

			if( $chk == substr( md5( $id . 'cid salt' ), 0, 3 ) ) {
				$msg = json_decode( $this->db->getMessage( $id ), true );
			}

			// Prune messages older than 1 day on 10% of URIs with cid set. 
			if( rand( 0,9 ) == 0 ) {
				$this->db->pruneMessages( 24*3600 );
			}
		}
		return $msg;
	}

	/**
	 * Handle National Language lookup.  This application and the templating system (described in the 
	 * class TemplateBuilder) is designed to be National Language (NL) capable.  All text resources are
	 * accessed through this \b getTranslation function.  This current implementation is more of a 
	 * hook since the current version is implemented in English.  However each phrase to be translated is 
	 * written to the language table in the D/B. This hook enables the blog engine to implement another 
	 * NL by: 
	 *  - Translating (and adding) the entries in the language table to the target NL. 
	 *  - Decoding the NL context to the set the AppContext property \b languageCode. 
	 * This rotine will then return the text content for the target NL.
	 *     
	 * @param $phrase The phrase to be translated
	 * @return The translation of the phrase.
	 */
	public function getTranslation( $phrase ) {

		$lang = $this->attrib->languageCode;
		if( $lang != 'EN' ) return '????';

		$id = md5( $phrase );
		$translation = $this->db->getTranslation($id, $lang );

		if( $translation == NULL ) {
			$this->db->insertTranslation( $id, $lang, $phrase );
			$translation = $prhase;
		}
		return $translation;
	}
}
