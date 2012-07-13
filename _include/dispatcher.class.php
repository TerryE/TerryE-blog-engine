<?php
##requires class AppContext
##requires class AppLogger
##requires class AppDB
##requires class Page
##requires class HomePage
##requires class ArticlePage
##requires class TemplateEN_index
##requires class TemplateEN_article

/**
 * Top level blog dispatch. This is a simple container class with a single static method:  dispatch()
 */
class Dispatcher {

	private $cxt;					//< current context
	private $includeDir;			//< used in autoloader 
	private $cacheDir;				//< used in autoloader
	private $defaultBuilderPattern;	//< used in autoloader

	/**
	 * Main entry point to blog application. Estabish AppLogger and (request) AppContext.  Then dispatch
	 * to the appropriate page class or InvalidPage if page is unknown. A "try" wrapper around this
	 * handles any exceptions and displays a simple error back to the user.
	 *
	 * @param $bootstrapContext  A 6 parameter context defined and documented in \ref index.php. 
	 */
	public function __construct( $bootstrapContext ) {

		ini_set( "arg_separator.output", "&amp;" );
		ini_set( 'error_reporting', E_ALL  |  E_STRICT );
		ini_set( 'display_errors', False );

		$this->includeDir				= $bootstrapContext['INC_DIR'] . DIRECTORY_SEPARATOR;
		$this->cacheDir					= $bootstrapContext['CACHE_DIR'] . DIRECTORY_SEPARATOR;
		$this->defaultBuilderPattern	= '/' . $bootstrapContext['DEFAULT_BUILDER_PATTERN'] . '/';
		$this->cxt						= NULL; 

		spl_autoload_register( array( $this, 'autoload' ) );

		try{
			$cxt = new AppContext( $bootstrapContext );
			$this->cxt = $cxt;

			// If this class wasn't loaded from the cache, then for debug levels 0  and 1 we will 
			// still want to create a cache copy.  We need to build this explicitly as the 
			// the autoloader doesn't build this class.
			if( $cxt->debugLevel != 2 && strpos( __FILE__ , $this->cacheDir ) !== 0 ) {
				$tmp = new DefaultBuilder( __CLASS__, $this->includeDir, $this->cxt );
				unset( $tmp );
			}

			$cxt->log->log( sprintf ( "START\t%.2f", ( microtime( TRUE ) - $bootstrapContext['START_TIME'] ) * 1000 ) ); 
 
			switch ( $cxt->page ) {
				case 'admin':		new AdminPage( $cxt );					break; 
				case 'about':		new ArticlePage( $cxt, $cxt->aboutme );	break; 
				case 'archive':		new ArchivePage( $cxt );				break; 
				case 'article':		new ArticlePage( $cxt );				break; 
				case '':
				case 'index.html':
				case 'index':
				case 'index.php':	new HomePage( $cxt );					break; 
				case 'photo':		new PhotoPage( $cxt );					break; 
				case 'rss':			new RssPage( $cxt );					break; 
				case 'search':		new SearchPage( $cxt );					break; 
				case 'test':		new TestPage( $cxt );					break; 
				case 'info':		phpinfo();								break; 
				case 'sitemap.xml':	new SitemapPage( $cxt );				break;
				default:			new InvalidPage( $cxt ); 
			}
		} catch (Exception $e) {
			error_log ( $e->getMessage() );
		}
	}

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
	public function autoload( $className ) {

		$fileName	= preg_replace( '/[^a-z]/', '.', strtolower( $className ) ) . '.class.php';

		if ( strpos( $fileName, 'builder.class.php' ) ) {
			// Class builder are always loaded as-is from include directory
			require( $this->includeDir . $fileName );
		} else {
			// include the _cache version if it exists
			if( ( @include( $this->cacheDir . $fileName ) ) != 1 ) {

				// otherwise determine the builder class
				if( preg_match( $this->defaultBuilderPattern, $className) || 
					!preg_match( '/(\w[a-z]*)[A-Z]\w*/', $className, $m) ) {
					$builderClass = 'default';
				} else {
					$builderClass	=  $m[1];
				}

				// Call the builder to create the _cache version (this may trigger its autoload)
				$builderClass = ucfirst( $builderClass ) . 'Builder';
				$builder = new $builderClass( $className, $this->includeDir, $this->cxt );

				// Now load the built version. "require" is used because a load failure is fatal this time.
				require( $builder->getLoadFile() );
			}
		}
	}
}
