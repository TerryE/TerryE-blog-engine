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
 */

class AppContext {

    private static $_instance;
	private static $_class = __CLASS__;
    private function __clone() {}

	/**
	 * Initialise the blog context. This is a static method since only one AppContext instance is 
	 * allowed. On first invocation, the private constructor is called to load the blog context from 
	 * the config table and some user and page context from cookies and the page request parameter.
	 */
	public static function get() {
		if ( !isset( self::$_instance) ) self::$_instance = new self::$_class();
		return self::$_instance;
	}

	private $attrib;				// Std class used to hold context attributes
	private $attribType;			// Array used to hold context type (used in set function)
	/*
	 * Load the blog context
	 */
	private function __construct() {

		$this->attrib		= new stdClass;
		$this->attribType	= array();
		$a					= $this->attrib;
		$a->rootDir			= ROOT_DIR;
		$db 				= AppDB::get();   //  Connect to AppDB
		$a->db				= $db;

		// Define access functions used in context processing
		$db->declareFunction( array(
'getConfig'		=> "Set=SELECT * FROM :config",
'checkPassword'	=> "Row=SELECT MD5(CONCAT('#1',password)) AS token, flag, email FROM :members WHERE name = '#2'",
		) );

		// Fetch the config table from the database and add to context
		foreach( $db->getConfig() as $row ) {
			$param		= $row['config_name'];
			$a->$param	= $row['config_value'];
		}

		foreach (array( 'HTMLcacheDir', 'adminDir',  'cacheDir', 'includeDir', 'templateDir' ) as $d) {
			$a->$d = $a->rootDir . DIRECTORY_SEPARATOR . $a->$d . DIRECTORY_SEPARATOR;
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
		$a->isAdmin 	= $a->user && $check['flag'] == 2;

		// Set the request variable count, and set the cacheable flag if the user is a guest, there
        // are no request parameters other than the page parameter and HTML caching is enabled.
		$a->requestCount  = count( $_REQUEST );
		$a->HMTLcacheable = $a->enableHTMLcache && $a->requestCount == 1 && !($a->isAdmin);
			
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
	 *     -  \b # (alias \b G) This is a get variable
     *     -  \b : (alias \b P) This is a post variable
     *     -  \b * (alias \b C) This is a cookie variable
     *     -  \b ! (alias \b F) This is a file parameter
	 *     -  \b I The type is integer   
	 *     -  \b H The type is Hex string   
	 *     -  \b A The type is an Array (used in tabular forms)   
	 *     -  \b S The type is string
	 *     -  \b F The type is (uploaded) file
     *
     *  -  The variable name is a lowercase word.
     *
     *     An optional comma separator can be used to enhance readability.
	 */
	public function allow($varList) {

		$varList	= str_replace( ',', '', $varList );
		$va 		= preg_split( "/([,]?[#:*GPCF][ISAHF]?)/", $varList, -1,  PREG_SPLIT_DELIM_CAPTURE);
		$a 			= $this->attrib;

		array_shift( $va );
		while ( $kind = array_shift ($va) ) {
			$name = array_shift ($va);
			$result = FALSE;
			switch ( substr( $kind, 0, 1 ) ) {

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
					case 'A':
					case 'H':
					case 'F':
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

		if ( isset( $this->attribType[$name] ) && 
		    $this->attribType[$name] == 'C' ) {
			setcookie( 'blog_' . $name,  '', 0, '/', $_SERVER['HTTP_HOST'] );
		} else {
			error_log( "APP: Invalid unset of HTTP variable $name.  Not a cookie." );
		}

		$this->attrib->$name = FALSE;
	}

}
