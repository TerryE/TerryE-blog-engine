<?php
/** \file functions.php 
 * Miscellaneous functions.  This file contains the few miscellaneous functions that don't sensibly 
 * or can't fit into the class framework.   
 */

/**
 * Autoload Handler. All material functionality is implemented though classes in this implementation.  
 * Unless explicitly preloaded, classes are loaded by this autoloader.
 *
 * -  By default classes are loaded from a \b _cache directory, if they exist in this directory. 
 *    If a cache fault occurs — that is the class isn't in the \b _cache directory — not then the 
 *    autoloader uses a \a builder \a class to create this cached copy. 
 *
 * -  These builder classes are an exception to the _cache directory location as they are directly
 *    loaded from the _include directory. The builder name is based on a simple convention for the  
 *    class name: if the class name is multiple words (separated by underscore) then the first word
 *    in the name is used for the builder class otherwise the "<i>default</i>" builder class is used.
 *   
 * See AbstractBuilder for further detailed discussion of the builder strategy. 
 * 
 * @param $className The name of the class to be autoloaded.
 */
function __autoload( $className ) {

#error_log( "Autoloading $className" );

	$fileName	= preg_replace( '/[^a-z]/', '.', strtolower( $className ) ) . '.class.php';
	$incFile	= INC_DIR . $fileName;
	$cacheFile	= CACHE_DIR . $fileName;

	if ( strpos( $fileName, 'builder.class.php' ) ) {
		// include the _include version of the builder class
	error_log( "Requiring $incFile" );
		require( $incFile );
	} else {
		// include the _cache version if it exists
		if( ( @include( $cacheFile ) ) != 1 ) {
			// otherwise determine the builder class
#error_log( "Class filename $fileName" );
			$builderClass	= preg_match( '/(\w[a-z]*)[A-Z]\w*/', $className, $m) ?
				$m[1] : 'default';
#error_log( "BuildRoot1 $builderClass" );

		    if( defined( 'DEFAULT_BUILDER_PATTERN' ) &&
			    preg_match( '/' . DEFAULT_BUILDER_PATTERN . '/', $className, $m) ) {
				$builderClass = 'default';
			}
#error_log( "BuildRoot2 $builderClass" );

			// Call the builder to create the _cache version (this may trigger its autoload)
#error_log( 'Calling ' . ucfirst( $builderClass ) . 'Builder::build' );
			$loadFile = call_user_func( array( ucfirst( $builderClass ) . 'Builder', 'build'), 
						                       $className );			
			// Now load the built version; "require" is used because a load failure is fatal this time.
#error_log( "Now require $loadFile" );
			require( $loadFile );
		}
	}
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
function getTranslation( $phrase ) {

   /**
	* The flexibilty of autoloader system has a consequence that the execution path to the initial
	* invocation of \b getTranslation can vary and therefore it must self-initialise its context.  
    * A couple of static variables are used and the context is initialise once if they aren't set,
	* rather than once per call.
	*/
	static $db, $lang;
	if( !isset( $db ) ) {
		$db = AppDB::get();
		$db->declareFunction( array(
'getTranslation'	=> "Val=SELECT phrase FROM :language WHERE id = '#1' AND lang_code = '#2'",
'insertTranslation' => "INSERT INTO :language VALUES ('#1', '#2', '#3')",
			) );
		$lang = AppContext::get()->languageCode;
	}

	if( $lang != 'EN' ) return '????';
	$id = md5( $phrase );
	$translation = $db->getTranslation($id, $lang );

	if( $translation == NULL ) {
		$db->insertTranslation( $id, $lang, $phrase );
	}
	return $translation;
}

/**
 * Delete files from a directory based on PREG filter
 * @param $relDirectory Directory path relative to the root directory
 * @param $pattern      Regexp pattern to be used to decide which files to delete
 */
function unlinkDirFiles( $relDirectory, $pattern ) {
	$rootDir = AppContext::get()->rootDir;
	$cnt = 0;
	// Don't use array_filter callback as this require a per-call lambda function 
	// and ditto preg_filter since this is only for PHP >= 5.3 
	foreach( scandir( "$rootDir/$relDirectory" ) as $f ) {
		if ( preg_match( "/$pattern/", $f) == 1 ) {
			unlink( "$rootDir/$relDirectory/$f" );
			$cnt++;
		}
	}
	return $cnt;
}

/**
 * Simple debug message logger
 * @param $msg   Message to be output to debug log
 */
function debugMsg( $msg ) {
	error_log( $msg . "\n", 3, "/tmp/debug.log" );
}

/**
 * Debug var_export to file
 * @param $title   Title to prefix variable dump
 * @param $var     Variable to be dumped to debug log
 */
function debugVar($title, $var) { 
	 debugMsg( "$title = " . var_export($var, true) );
} 

/**
 * Simple debug transaction timer
 * @param $pageName Name of page being displayed 
 */
function pageTime( $pageName=NULL ) {
    static $u0, $s0;
	if( is_null( $pageName ) ) {
		list( $u0, $s0 ) = explode( " ", microtime() );
	} else {
		list( $u1, $s1 ) = explode( " ", microtime() );
        // Do (s1-s0) ... to avoid loss of precision 
		$elapsed = ( ( (float)$s1 - (float)$s0 ) + ( (float)$u1 - (float)$u0 ) ) * 1000;
		debugMsg( sprintf( "Page %s completed in %u mSec", $pageName, (int) $elapsed ) );
	}
}

