<?php
/**
 * A collection of utilities to manipulate source code
 */
class AppSourceUtils {

	private $pathList;

	/**
	 * "Compress" the given source file.
	 * @param $sourceFile     Name of file to be compressed
     * @param $pathList       Directory list to be scanned for the file
	 * @param $ignoreRequires Boolean to ignore \#\#requires directives if true
	 * @returns compressed source content
	 *
	 * This process involves three aspects:
     * -  The PHP Tokenizer is used to remove all redundant whitespace in the source code.  The pattern
	 *    for this code is pretty much a duplicate of the example given in the PHP documentation for 
	 *    the PHP Tokeniser. 
	 *
	 * -  Any comment lines of the form <tt>\#\#requires class \b className</tt> will be parsed and
     *    replaced by the inlined class source.
	 *
	 * -  Any comment lines of the form <tt>\#\#requires \b fileName \b function</tt> will be parsed and
     *    replaced by the inlined class source.
	 *
	 * The raison d'être for this routine is an issue that I discuss in some of my blog articles.
	 * Most ISPs do not implement PHP opcode caching on their shared hosting service offerings.  Nor do
	 * they use mod_php or FastCGI.  Each PHP request results in
	 * -   PHP image activation (which takes of the order of 100mS)
	 *
	 * -   All source code then needs to be compiled (compiling a few thousand lines will take less 
	 *     than 1mS).
	 *
	 * -   The script then needs to be executed (In the case of my blog engine this takes perhaps 3-7 mS
	 *     on my old laptop, and most of this is in the MySQL engine executing SQL queries).
	 *
     * -   However, the performance killer is the I/O time associated with gather the source files to 
	 *     compile them.  OK, if another other request has just been executed then this file data will
	 *     still be in the server's VFAT filecache.  However, the churn on such servers is such that 
	 *     almost certainly have been flushed after a few minutes this cache will. In this case, a  
	 *     cache miss to the shared NFS storage will add another 5-10mS per file miss, and another 
	 *     50-200mS again if the file isn't in the NFS server's own memory cache and therefore retrieving 
	 *     the content will require physical disk I/O.  
     *
     * Because of this last aspect, aggregrating a dozen, say, logically separate script components, 
	 * into a single load set can materially improve request response.
	 *
	 * A lesser factor is that I use inline commenting (in this case Doxgen) to produce full code 
	 * documentation. Since the class autoloader moves loaded source into a code-cache directory,
	 * compressing on the fly, I know that I pay \a no runtime overhead for documenting properly.
	 *
	 */
	public function compress( $sourceFile, $pathList, $ignoreRequires ) { 

		$this->pathList = $pathList;

		if( !$ignoreRequires ) {
			// scan input file replacing any include or require statements starting in column 1
			$fullSource = preg_replace_callback( '/^ \#\#requires \s+ (\w+) \s+ (\w+)/xm', 
						                         array( $this, 'includeSource' ), 
						                         $this->readFile( $sourceFile ) );
			$tokens = token_get_all( $fullSource );
		} else {
			$tokens = token_get_all( $this->readFile( $sourceFile  ) );
		}

		// Now pack the source code.  This is based on the Tokenizer example in the PHP Documentation
		$output = '';
		foreach( $tokens as $token ) {
		   if(is_string( $token ) ) {
			  // simple 1-character token
			  $output .= $token;
		   } else {
			  // token array
			  list( $id, $text ) = $token;
			  switch( $id ) { 
				 case T_COMMENT: 
				 case T_DOC_COMMENT:
				    break;
				 case T_WHITESPACE:
				    $output .=  ( ( strpos( $text, "\n" ) === false ) ? ' ' : "\n" );
				    break;
				 default:         
				    $output .= $text;   // anything else -> output "as is"
				 break;
			  }
		   }
		}
#error_log( "Compressed " . strlen( $source ) . " bytes into ". strlen( $output ) . " bytes" ); 
	return $output;
	}

	/**
	 * \b Preg_replace_callback helper function. For the regexp '/^ \#\#requires \s+ (\w+) \s+ (\w+)/xm'.
	 * @param $match the standard PHP match array.
	 * @returns the code fragment to be included corresponding to the \a \#\#require class.
	 *
	 * This callback function used by compress() to include inline any required classes or function
	 * files.  
	 */
	private function includeSource ( $match ) {

		$incDirs	= $this->pathList;

#error_log ( "Build including $match[2]" );

		if( $match[1] == 'class' ) {

			// This is a "##requires class <className>" include	
			$className	= $match[2];
			$fileName	= preg_replace( '/[^a-z]/', '.', strtolower( $className ) ) . '.class.php';

		   /**
			* Note that as compile safety feature, the included code is wrapped a class or function
			* existance check.  This is just in case some execution path has already independently loaded   
			* the included function / class. An interesting undocumented PHP feature of actual \b class_exists()
			* function is that it will itself trigger an autoload request for the class before doing
			* the extence evaluation.  This is used to advantage in the compress process to ensure
			* that any dependent classes have been properly build before being included. 
			* 
			* Unfortunately, this means that any required class is also preload during this build. 
			* Hence the declaration of the class must be guarded by a class existence check, but this
			* feature of \b class_exists() is very definitely not what I want to happen as I am already 
			* in the process of loading it! Hence <tt> !in_array( '\a className', get_declared_classes()
			* )</tt> is used as the predicate instead, as this doesn't trigger autoload.
			*/
			if( class_exists( $className ) ) {

				// strip the leading <?php tag.  The trailing close tag is already omitted by convention.
				$incSource = preg_replace ( '/^ \s* <\?php \s* /xs', '', $this->readFile( $fileName ), 1 );
				return "if( !in_array( '$className', get_declared_classes() ) ) { \n$incSource }";

			} else {
				 throw new Exception ( "APP: Attempt to load unknown class $className" );
			}
		}
	}

	/**
	 * Read the requested the given source file.
	 * @param $sourceFile     Name of file to be compressed
 	 * @returns compressed source content
	 */
	private function readFile (	$sourceFile ) {

		foreach( $this->pathList as $path ) {
			if( file_exists( $path . $sourceFile ) ) {
				return file_get_contents( $path . $sourceFile );
			}
		}
		throw new Exception( "APP: Functions file {$sourceFile} not found on include path" );
	}
}
