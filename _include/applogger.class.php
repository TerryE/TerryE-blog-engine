<?php
/**
 * Application Logger class. This class implements diagnostic logging for the application.  Since 
 * the primary use is for tuning analysis and low volume production logging, the approach taken is
 * to minimise the I/O calls to the logfile.  The log records are assembled in a private array and
 * flushed to the logfile as a single call as part of object destruction.  
 */
class AppLogger {

	private $startTime;			/**< Microtime of logging start */
	private $logArray;			/**< Collection of records to be logged */
    private $logFileName;       /**< Where the log records will be logged */

	/**
	 * AppLogger constructor. Initialise the logging context.
	 */
    public function __construct( $logHeader = '', $time = NULL ) {

		$this->startTime	= is_null( $time ) ? microtime() : $time;
		$this->logArray[]	= $logHeader;
		// This is a default name that can be overridden by setLog()
		$this->logFileName	= "/tmp/application.log";
    }
	/**
	 * AppLogger destructor. Windup the logging context.
	 */
    public function __destruct() {
		$this->flushLog();
	}

	/**
	 * Flush the logged messages to the message log file
	 */
	private function flushLog( ) {

		$elapsedMS = round( ( microtime( TRUE )  -  $this->startTime ) * 1000, 2);
			
		$this->logArray[0] = sprintf( "%s\t%.2f\t%s",
				date( "D M j G:i:s Y", (int) $this->startTime ),
				$elapsedMS,
				$this->logArray[0] );

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
