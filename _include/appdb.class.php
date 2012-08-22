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

	private $query;				/**< Array of registered SQL queries */
	public  $tablePrefix;		/**< Table prefix to be applied to all tables */
	private $tables;			/**< List of allowed tables */

	/**
	 * AppDB constructor. Connect to the database and initialise the list of tables with the given prefix.
	 *
	 * @param $dbContext  String literal containing MySQL connection information 'host:DB:user:password' 
	 * @param $appPrefix  An application prefix, which is prefixed to all table names  
	 * @param $logger     AppLogger instance
	 */
    public function __construct( $dbContext, $appPrefix, $logger = NULL ) {

        parent::init();

		list( $host, $db, $user, $passwd ) = explode ( ':', $dbContext );
		$this->tablePrefix = $appPrefix;

        if( !parent::real_connect($host, $user, $passwd, $db)) {
            throw new Exception ('Connect Error (' . 
				mysqli_connect_errno() . ') ' . mysqli_connect_error() );
        }
		$this->query	= array();
		$this->tables	= array();
		$this->logger	= $logger;

		// The property $tables contains a dictionary of valid table names less the prefix.

		$this->declareFunction( array( 'getTableList'	=> "Set=SHOW TABLES LIKE '#1%'" ) ); 
		$tOffset = strlen( $this->tablePrefix );
		foreach( $this->getTableList( $this->tablePrefix ) as $t) {
			$this->tables[substr( $t[key($t)], $tOffset ) ] = TRUE;
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
	 * - Any \#N arguments (where N is an integer) are replaced by the Nth calling parameter.  Any scalar
	 *   argument is passed through mysqli::real_escape_string() if the parameter is not numeric.  If the
     *   argument is a keyed array then it is replaced by a comma separated list of key='value' where
     *   key with the corresponding \b value (again escaped where necessary) is taken from the array.
	 *
	 * In this way the application is able to wrap all D/B access in a locally implemented functional
	 * form.
	 */
	public function __call($name, $arguments) {

		if( !isset( $this->query[$name] ) ) {
			throw new Exception( "APP: Invalid AppDB::{$name} not defined" );
		}
		$time = microtime( TRUE );

		$this->arguments = $arguments;
		$query = preg_replace_callback( '/(?:#\d+|:\w+)/', 
										array(&$this, 'replaceArguments' ),
										$this->query[$name] );

		//Split type and query.  This pattern will always match, so no if req'd
		preg_match( '/^(Val=|Row=|Set=)?(.*)/s', $query, $m );
		list( $dummy, $type, $query ) = $m;

		switch( $type ) {
			case 'Val=':
				$rtn = $this->queryValue( $query ); break;
		
			case 'Row=':
				$rtn = $this->queryRow( $query );   break;
		
			case 'Set=':
				$rtn = $this->querySet( $query );   break;

			case '':
				$rtn = $this->query( $query );      break;
		}

		$time = round( ( microtime( TRUE ) - $time ) * 1000, 2 );

		if( is_object( $this->logger ) ) {
			$this->logger->log( sprintf( "SQL\t%.2f\t%s",$time  , preg_replace( '/[ \t\n]+/', ' ', $query ) ) );
		}

		return $rtn;
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
			if( is_array( $arg ) ) {
				foreach ( $arg as $k => $v ) {
					if( !preg_match( '/^[a-z]\w+$/i', $k ) ) {
						throw new Exception( "Invalid SQL query substitution parameter: $k" );
					}
					if( !is_numeric( $v ) ) {
						$v = $this->real_escape_string( $v );
					}
					$argList[] = "$k='$v'";
				}
				$arg = implode( ", ", $argList );

 			} elseif( !is_numeric( $arg ) ) {
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
