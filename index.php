<?php
/**
 * @file index.php
 * @brief Common entry point for all blog requests.
 *
 * All blog script URIs are written by Apache to be of the form index.php?page=pageName so index.php
 * is the common entry for all blog requests.  This script is deliberately lightweight.  The bulk of 
 * configuration is read from configuration table in the blog database.   A set of six constants need
 * to be defined here to allow the environent to bootstrap.  
 *
 *   - \b ROOT_DIR.  The root directory for the application.  This is typically dirname(__FILE__)
 *     since index.php is normally placed in the application root directory.
 *
 *   - \b INC_DIR.  The root for file includes.  This is typically the \b _include subdirectory.  If 
 *     a more complex include search list is required (for example if another subdirectory is used to
 *     contain a third party package), the \b includeDir parameter can be set in the D/B configuration 
 *     table and DefaultBuilder::build() will use this search this list.
 * 
 *   - \b CACHE_DIR. Code Cache Directory.
 *
 *   - \b DEFAULT_BUILDER_PATTERN.  Any classname matching this PCRE expression will be loaded by the 
 *     default template builder.
 *
 *   - \b APP_PREFIX.  String literal containing the Application prefix, which is used in cookies and 
 *     table names. 
 *
 *   - \b DB_CONTEXT.  String literal containing MySQL connection information 'host:DB:user:password:tablePrefix' 
 *
 *   - \b START_TIME.  An optional microtime of the start of script execution.  Use in logging.
 *
 */
$rootDir = dirname(__FILE__);
$bootstrapContext = array (
	'ROOT_DIR'					=> $rootDir,
	'INC_DIR'					=> "$rootDir/_include",
	'CACHE_DIR'					=> "$rootDir/_cache",
	'DEFAULT_BUILDER_PATTERN'	=> '(^Author|^Tiny|^Text|Page$|Exception$)',
	'APP_PREFIX'				=> 'blog_',
	'DB_CONTEXT'				=> 'host:db:user:password',
	'START_TIME'				=> microtime( TRUE ),
	 );

/**
 * The remainder of the script is a short bootstrap to load and invoke Dispatcher::dispatch().
 */
if( ( @include( "$bootstrapContext[CACHE_DIR]/dispatcher.class.php" ) ) != 1 ) {
	require("$bootstrapContext[INC_DIR]/dispatcher.class.php");
}

new Dispatcher( $bootstrapContext );
