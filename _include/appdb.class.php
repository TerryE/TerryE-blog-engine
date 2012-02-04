<?php
/**
 * Database encapsulation class. This class and methods carry out two roles: 
 * - The standard \b mysqli class requires 4-6 line code patterns for most access functions (and 
 *   significantly more if parameters are untrusted and must be escaped to prevent injection attacks).
 *   The base methods make calling the mysqli interface a simple one-line 95% of the time.  
 * - There are also a set of magic methods which allow the invoking applicaton to encapsulate access
 *   to database through application-specific access methods, which mapped to these base methods and
 *   also handle any parameter escaping.  See AppDB::declareFunction for further details.
 */
class AppDB extends mysqli {

	/**
	 * This class uses a standard single class object pattern.
	 */
    private static $_instance;
	private static $_class = __CLASS__;
    private function __clone() {}
	/**
	 * Initialise the blog context. This is a static method since only one AppDB instance is allowed. The
	 * $connectParams must be provided on first invocation.
	 */
	public static function get() {

		if ( !isset( self::$_instance) ) self::$_instance = new self::$_class ();
		return self::$_instance;
	}

	private $query;				/**< Array of registered SQL queries */
	public  $tablePrefix;		/**< Table prefix to be applied to all tables */
	private $tables;			/**< List of allowed tables */

	/**
	 * AppDB constructor. Connect to the database and initialise the list of tables with the given prefix.
	 */
    private function __construct() {

        parent::init();
		list( $host, $db, $user, $passwd, $this->tablePrefix ) = explode ( ':', SQL_CONTEXT );

        if( !parent::real_connect($host, $user, $passwd, $db)) {
            throw new Exception ('Connect Error (' . 
				mysqli_connect_errno() . ') ' . mysqli_connect_error() );
        }
		$this->query		= array();
		$this->tables		= array();

		foreach( $this->querySet( "SHOW TABLES LIKE '{$this->tablePrefix}%'" )
			as $table) {
			$v = array_values( $table );
			$this->tables[substr( $v[0], strlen($this->tablePrefix) )] = TRUE;
		}
    }

	/**
	 * Fetch a query set -- that is a results set of multiple row, each of which is an assoc array.
	 */
	public function querySet( $sql ) {
		if( ( $result = parent::query( $sql ) ) === false ) $this->raiseError ( $sql );
		$rs = array();
		while( $row = $result->fetch_assoc() ) {
			$rs[] = $row;
		}
		$result->close();
		return $rs;
	}

	/**
	 * Fetch a result which is a single row with the fields as an assoc array.
	 */
	public function queryRow( $sql ) {
		if( ( $result = parent::query( $sql ) ) === false ) $this->raiseError ( $sql );
		if( !( $row = $result->fetch_assoc() ) ) {
			$row = array();
		}
		$result->close();
		return $row;
	}

	/**
	 * Fetch a result which is a single row with a single field value.
	 */
	public function queryValue( $sql ) {
		if( ( $result = parent::query( $sql ) ) === false ) $this->raiseError ( $sql );
		if( ( $row = $result->fetch_row() ) ) {
			$result->close();
			return $row[0];
		}	
		return NULL;
	}

	/**
	 * Execute a query which does not return a result set (an UPDATE or INSERT).
	 */
	public function query( $sql ) {
		if( ( $result = parent::query( $sql ) ) === false ) $this->raiseError ( $sql );
		return $result;
	}

	private function raiseError( $sql ) {
		throw new Exception ( sprintf( "Invalid SQL: %s Error: (%d): %s",
			$sql, $this->errno, $this->error ) );
	}

	/**
	 * Register a set of encapsulating functions. 
	 * @param $sqlList Array of name=>SQLstatement.  
	 * This array is used to define the encapsulating functions. The key is the method name and 
	 * the value the SQL statement to be executed.  These are stored in the private $query array. 
	 * See AppDB::__call for how the are processed.
	 */
	public function declareFunction($sqlList) {
		$this->query = array_merge( $this->query, $sqlList );
	}

	/**
	 * Invoke an encapsulated D/B query. The PHP RTS invokes the \b __call method if defined when 
     * any non-explicitly defined AppDB method is invoked. This routine checks the method name against 
	 * the query table.  The type of result (Q/QV/QR/QS) is determined by the first value entry and 
	 * the second value entry is used as the generating SQL query.
	 *
	 * The SQL statement is processed and the follwing substitution of argument patterns is carried out:
	 * - Any :{lower case word} strings are assumed to be table names and the ":" escape character is 
	 *   replaced by tablePrefix property 
	 * - Any \#N arguments (where N is an integer) are replaced by the Nth calling parameter.  The
	 *   argument is passed through mysqli::real_escape_string() if the parameter is not numeric. 
	 *
	 * In this way the application is able to wrap all D/B access in a locally implemented functional
	 * form.
	 */
	public function __call($name, $arguments) {

		if( !isset( $this->query[$name] ) ) {
			throw new Exception( "APP: Invalid AppDB::{$name} not defined" );
		}

		$this->arguments = $arguments;
		$query = preg_replace_callback( '/(?:#\d+|:\w+)/', 
										array(&$this, 'replaceArguments' ),
										$this->query[$name] );

		if( preg_match( '/^(Val|Row|Set)=(.*)/s', $query, $m ) ) {
			switch( $m[1] ) {
				case 'Val':
					return $this->queryValue( $m[2] );
			
				case 'Row':
					return $this->queryRow( $m[2] );
			
				case 'Set':
					return $this->querySet( $m[2] );
			}

		} else {
			return $this->query( $query );
		}	
	}

	private $arguments;

	/**
	 * Callback helper function used by __call.  This enables the argument substitution to 
	 * be implemented using a \b preg_replace_callback call.
	 */
	private function replaceArguments( $m ) {
		$type = $m[0][0];
		$arg = substr( $m[0], 1); 
		if ( $type == '#' && isset( $this->arguments[ $arg - 1 ] ) ) {
			$arg = $this->arguments[ $arg - 1 ];
			if( !is_numeric($arg) ) {
				$arg = $this->real_escape_string( $arg );
 			}
			return $arg;

		} elseif ( $type == ':' && isset( $this->tables[ $arg ] ) ) {
			return $this->tablePrefix . $arg;

		} else {
			throw new Exception( "Invalid SQL query substitution argument: $m[0]" );
		}
	}
}
