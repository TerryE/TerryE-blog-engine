<?php
/**
 * Default builder for autoload classes. See AbstractBuilder documentation for a detailed discussion
 * of the builder strategy.
 */
class DefaultBuilder extends AbstractBuilder {
	/**
	 * Standard method for autoloaded builder classes.  
	 *
	 * @param $className Name of class to be built
	 * @returns Fully pathed filename of class file to be loaded.
	 * 
	 * By default the source is compacted and any 
	 * \a \#\#require files are inlined with the new version written to the _cache directory for
	 * subsequent use (see AppSourceUtils).  However setting the debugLevel>0 overrides this behaviour: 
	 *  -  \b =1 then "##require" directives are ignored
	 *  -  \b =2 then the version in the _include directory version is used.
	 *
	 * The reason for this is that when testing and debugging, it is easier if any logged errors 
	 * simply point to specific lines in the orginal source.
	 */
	public static function build ( $className ) {

#error_log( "Building $className" );

		$cxt			= AppContext::get();
#error_log('APP context got' );
		$fileName		= preg_replace( '/[^a-z]/', '.', strtolower( $className ) ) . '.class.php';
		$inFile			= $cxt->includeDir . $fileName;
		$outFile		= $cxt->cacheDir . $fileName;
		$debugLevel		= $cxt->debugLevel;
#error_log("Debug level $debugLevel" );

		$ignoreCompress = ( $debugLevel == 2 );

#error_log("Ignore Compress $ignoreCompress" );

		if( !$ignoreCompress ) {
#error_log( "Compressing $className" );
			$ignoreRequires	= ( $debugLevel == 1 );
			$source			= AppSourceUtils::compress(
									file_get_contents( $inFile ),
									$ignoreRequires );
			if( $source	!= '' ) {
				file_put_contents( $outFile, $source, LOCK_EX );
			}
#error_log( "Build returns $outFile" );
			return $outFile;
		} else {
#error_log( "Build returns $inFile" );
			return $inFile;
		}
	}
}

