<?php
##requires functions getTranslation 
##requires class AppContext
##requires class AppLogger
##requires class AppDB
##requires class Page
##requires class IndexPage
##requires class ArticlePage
##requires class TemplateEN_index
##requires class TemplateEN_article
/**
 * Top level blog dispatch
 */
class Dispatcher {
	/**
	 * Main entry point to blog application. Estabish AppLogger and (request) AppContext.  Then dispatch
	 * to the appropriate page class or InvalidPage if page is unknown. A "try" wrapper arounds
	 * this handle any exceptions and displays a simple error back to the user.
	 */
	static function dispatch() {

		ini_set( "arg_separator.output", "&amp;" );
		ini_set( 'error_reporting', E_ALL  |  E_STRICT );
		ini_set( 'display_errors', False );

		try{
			$cxt = AppContext::get();
			AppLogger::get( defined ( 'START_TIME' ) ? START_TIME : NULL );

			switch ( $cxt->page ) {
				case 'admin':		new AdminPage;					break; 
				case 'about':		new ArticlePage($cxt->aboutme);	break; 
				case 'archive':		new ArchivePage;				break; 
				case 'article':		new ArticlePage;				break; 
				case '':
				case 'index.html':
				case 'index':
				case 'index.php':	new IndexPage;					break; 
				case 'photo':		new PhotoPage;					break; 
				case 'rss':			new RssPage;					break; 
				case 'search':		new SearchPage;					break; 
				case 'test':		new TestPage;					break; 
				case 'info':		phpinfo();						break; 
				case 'sitemap.xml':	new SitemapPage;				break;
				default:			new InvalidPage;	 
			}
		} catch (Exception $e) {
			error_log ( $e->getMessage() );
		}
	}
}
