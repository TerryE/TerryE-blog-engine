<?php
/**
 * Application Logger class. This class implements diagnostic logging for the application.  Since 
 * the primary use is for tuning analysis and low volume production logging, the approach taken is
 * to minimise the I/O calls to the logfile.  The log records are assembled in a private array and
 * flushed to the logfile as a single call as part of object destruction.  
 */
class AppLogger {

	/**
	 * This class uses a standard single class object pattern.
	 */
    private static $_instance;
	private static $_class = __CLASS__;
    private function __clone() {}

	/**
	 * Initialise the logging context. This is a static method since only one AppLogger instance 
	 * is allowed.
	 * @param $time This can optionally be provided and is only used on the first invocation to
	 *              define the start time.  If not then the current microtime is used.
	 */
	public static function get( $time = NULL ) {

		if ( !isset( self::$_instance) ) self::$_instance = new self::$_class ( $time );
		return self::$_instance;
	}

	private $startTime;			/**< Microtime of logging start */
	private $logArray;			/**< Collection of records to be logged */
    private $logFileName;       /**< Where the log records will be logged */

	/**
	 * AppLogger constructor. Initialise the logging context.
	 */
    private function __construct( $time = NULL ) {

		$thid->startTime	= is_null( $time ) ? microtime() : $time;
		$this->logArray		= array ( 
			"!?!\t$_SERVER[REQUEST_METHOD]\t$_SERVER[REQUEST_URI]\t$_SERVER[HTTP_USER_AGENT]",
			 );

		// This is a default name that can be overridden by setLog()
		$this->logFileName	= "/tmp/application.log";

    }
	/**
	 * AppLogger destructor. Windup the logging context.
	 */
    public function __destruct() {
		$this->flushLog();
		self::$_instance = NULL;
	}

	/**
	 * Flush the logged messages to the message log file
	 */
	public function flushLog( ) {

		if( substr( $this->logArray[0], 0, 3) == "!?!" ) {
  			list( $u0, $s0 ) = explode( " ", START_TIME );
			list( $u1, $s1 ) = explode( " ", microtime() );

		    // Do (s1-s0) ... to avoid loss of precision 
			$elapsedMS = round( ( ( (float)$s1 - (float)$s0 ) + ( (float)$u1 - (float)$u0 ) ) * 1000);
			
			$this->logArray[0] = date( "D M j G:i:s Y\t", $s0 ) . $elapsedMS .
				substr( $this->logArray[0], 3 );
		}
		error_log( implode( "\n", $this->logArray ) . "\n", 3, $this->logFileName );

		$this->logArray = array();
	}

	/**
	 * Simple debug message logger
	 * @param $msg   Message to be output to debug log
	 */
	public function log( $msg ) {
		$this->logArray[] = $msg;
	}

	/**
	 * Set log file name
	 * @param $file   File name of log file
	 */
	public function setLog( $file ) {
		$this->logFileName	= $file;
	}
}

