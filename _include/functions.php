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

	$fileName	= preg_replace( '/[^a-z]/', '.', strtolower( $className ) ) . '.class.php';
	$incFile	= INC_DIR . $fileName;
	$cacheFile	= CACHE_DIR . $fileName;

	if ( strpos( $fileName, 'builder.class.php' ) ) {
		// include the _include version of the builder class
		require( $incFile );
	} else {
		// include the _cache version if it exists
		if( ( @include( $cacheFile ) ) != 1 ) {
			// otherwise determine the builder class
			$builderClass	= preg_match( '/(\w[a-z]*)[A-Z]\w*/', $className, $m) ?
				$m[1] : 'default';

		    if( defined( 'DEFAULT_BUILDER_PATTERN' ) &&
			    preg_match( '/' . DEFAULT_BUILDER_PATTERN . '/', $className, $m) ) {
				$builderClass = 'default';
			}

			// Call the builder to create the _cache version (this may trigger its autoload)
			$loadFile = call_user_func( array( ucfirst( $builderClass ) . 'Builder', 'build'), 
						                       $className );			
			// Now load the built version; "require" is used because a load failure is fatal this time.
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
 * Simple debug message logger
 * @param $msg   Message to be output to debug log
 */
function debugMsg( $msg ) {
    static $debugFile=NULL;
    if( !isset( $debugFile ) ) {
		$debugFile = AppContext::get()->debugFile;
	}
	error_log( $msg . "\n", 3, $debugFile );
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
 * @param $eventName Timer event being displayed 
 */
function pageTimer( $eventName ) {
    static $u0, $s0;

	if( is_null( $pageName ) ) {
		list( $u0, $s0 ) = explode( " ", START_TIME );
		debugMsg( date( 'Y-m-d H:i:s', $u0 ) .  " - Transaction timer set at 0 mSec" );
	}

	list( $u1, $s1 ) = explode( " ", microtime() );
    // Do (s1-s0) ... to avoid loss of precision 
	$elapsed = ( ( (float)$s1 - (float)$s0 ) + ( (float)$u1 - (float)$u0 ) ) * 1000;
	debugMsg( sprintf( "\t%s at %u mSec", $eventName, (int) $elapsed ) );
}
