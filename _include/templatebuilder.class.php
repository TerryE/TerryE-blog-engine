<?php
/**
 * Template Compiler.  This class translates an HTML output template to PHP code then saves is to a 
 * PHP file. The AbstractBuilder documentation provides a detailed discussion of the general builder
 * strategy. This document discusses the specific scope of template building.  
 *
 * The overall application architecture employs a controller model which partitions processing into 
 * three tiers: D/B access, application logic and ouput presentation.  All webpage requests are 
 * implement by page objects which extend the Page class. The parent Page class includes a set of 
 * methods, such as Page::assign(), that enable the application to create content fields for page 
 * output.  The presentation and any formatting of such output is carried out by calling the 
 * Page::output() method referencing the template to be used.
 * 
 * This output function then activates the template by invoking the constructor for the object
 * <b>Template_<i>XX</i>_<i>YYYYYYYY</i></b> where <b><i>XX</i></b> is the two letter country code
 * for the current language being used and <b><i>YYYYYYYY</i></b> is the output template to be used.
 * Here is the code fragment in Page::output():
 * \code
 *	$templateClass	  = "Template_{$cxt->languageCode}_" . preg_replace( '/\W/', '_', $template  );
 *	new $templateClass( $this, $cxt );\endcode
 *
 * As with all other class references, the autoloader loads the class if it already exists in the
 * \b _cache directory.  If the class doesn't, then the autoloader passes the build request to the 
 * corresponding builder to construct — in this case this TemplateBuilder class.  The builder is
 * therefore invoked only when the compiled class does not exist, and its job is to read in the
 * specified HTML template and to translate it into the corresponding PHP class.  
 *
 * Unlike the other builders, this TemplateBuilder does have a sufficently complex method and 
 * property set to merit adopting the standard PHP single-object class pattern.  Hence the static 
 * build method is a wrapper for <tt>self::get()->generate( ... )</tt>.     
 * 
 * Note that the reason for embedding the National Language (\b NL) code in the class name is simple: 
 * this way each generated template class is language-specific, e.g. \b Template_EN_article,
 * \b Template_FR_article and any language-specific resource translation / mapping is hoisted to the 
 * one-off template generation time. 
 * 
 * The syntax of the template is straight forward: it is essentially standard XHTML 1.0. However the 
 * template author can embed any number of transform tokens which are delimited by braces (<b>{}</b>). 
 * Note that these tokens cannot be nested, and any literal use of such brace characters in the template 
 * itself must be escaped by using the standard HTML \b \&#123; and \b \&#125; character sequences.  
 *
 * The parser will decode the following tokens: \code
 *	{IF:expression}
 *	{ELSE}
 *	{ELSEIF:expression}
 *	{FOREACH:expression:variable}
 *	{FOREACH:expression:variable:variable}
 *	{ENDFOR}
 *	{SWITCH:expression}
 *	{CASE:expression}
 *	{INCLUDE:templateName}
 *	{TR:keyString}
 *	{TR:keyString:argList}
 *	{expression}
 *	{{Any comment not including an end brace} \endcode
 * where:
 * -  \b variable is any uppercase word (inc underscore) not in the above reserved list, e.g. \b 
 *    AUTHOR_NAME.
 *
 * -  \b expression is any valid expression using variables and constants. So you can do logical 
 *    tests: <b>AUTHOR_NAME != '' && COUNT > 1</b>.  Object constructs and PHP function references 
 *    also work fine: e.g. <b>strlen(AUTHOR->name)</b>.  One slight variation is that constant 
 *    character indexes to keyed arrays use the PHP quoted text convention: <b>ARTICLE[id]</b>.
 *
 * -  \b templateName is the name of an included template, e.g. <b>{INCLUDE: header}</b> will 
 *    include the \b header.html template.  If the \b inlineIncludes context property is set to true
 *    then the included template will be expanded inline.
 *
 * -  \b keystring is used to lookup the NL translation of a language resource.  For this blog
 *    I have adopted the convention of using the English text to generate the hash key, which has
 *    the benefit of making the templates immediately readable, and the downside that changing the
 *    English wording means a keychange for the other NLs.  It's my convention for this application,
 *    but there is nothing preventing the alternate convention of using symbolic NL resource names.
 *
 * -  \b argList is a colon delimited set of \b expression. This is used in the second \b TR form 
 *    that passes the \b keystring and \b argList through the PHP sprintf() engine.  This is used
 *    because some NL expressions require such rich morphing. 
 *
 * -  Bare expressions without a known keyword prefix generate an \b echo of the  expression.
 * 
 * Also note that Page collects its output variables in a \b stdClass object (the instance property 
 * Page::$data).  The template parser collect a list of \b data properties that are referenced in
 * the template and this object is passed to the template as its $data parameter.  The template then 
 * runs a \b for loop during initialisation which enumerate this list, so for example if the template 
 * required the parameters \b TITLE, \b THEME, \b HEADER_SCRIPTS and \b SCRIPT then this preamble 
 * would be: 
 * \code
 *	foreach ( explode (':','title:theme:header_scripts:script') as $v) {
 *	$ucvar='var'.strtoupper($v);
 *	$$ucvar = (isset($data->$v)) ? $data->$v : '';} \endcode
 * 
 * Hence <tt>$varTITLE = $data->title</tt> if <b>$data->title</b> is set and the empty string 
 * otherwise.  What this means is that reference to \b TITLE, say, gets translated to a PHP local 
 * variable \b $varTITLE that has the same value as <b>$data->title</b> but with an empty string
 * as default.  
 *
 * For those interested in the performance, this strategy generates very economic PHP in the generated 
 * template, and yet with pretty much optimal runtime performance because of the PHP RTS deferred
 * copy-on-write storage model.  All NL text string are simply embedded text, and there isn't the
 * need for embedded \b isset() guards on the generated code.   
 * 
 * Also note:
 * -  Any parse errors result in death.  There is no attempt at elegant recovery.  
 * -  This builder is also typically executed once per S/W version release, so the emphasis is 
 *    on clarity over runtine efficiency.
 * -  This is the third incarnation of my templating engine originally inspired by Vemplator 0.6.1 
 *    by Alan Szlosek.  My thanks to Alan for this original work.
 */
class TemplateBuilder extends AbstractBuilder {
	/**
	 * This class uses a standard single class object pattern.
	 */
    private static $_instance;
	private static $_class = __CLASS__;
    private function __clone() {}
	/**
	 * Initialise the compiler context. This is a static method since only one compiler instance is allowed,
	 */
	public static function get() {
		return isset(self::$_instance) ? self::$_instance : (self::$_instance = new self::$_class);
	}

	private $templateVars;			//< used to track which variables are referenced in the template
	private $langRtn;				//< local copy of cxt->translateRtn

	/**
	 * Initialise the compiler context. 
	 */
	private function __construct() {
		$this->templateVars = array();
	}

	/**
	 * Standard static build method for autoloaded builder classes.
	 * This instantiates the builder object with a get() and then invokes the generate() function.
	 */
	public static function build ( $className ) {

		$lang				= substr( $className, 8, 2 );
		$template			= substr( $className, 11 );
		$cxt				= AppContext::get();
		$template			= str_replace( '_', '.', strtolower( $template ) );
		$compiledTemplate	= $cxt->cacheDir . 'template' . strtolower( $lang ) . ".$template.class.php";
		$inputTemplate		= $cxt->templateDir . $template . '.html';

		// Do the template compile
		self::get()->generate( $lang, $className, $inputTemplate, $compiledTemplate );
		return $compiledTemplate;
	}

	/**
	 * Generate the compiled template class.
	 */
	public function generate( $lang, $className, $templateFile, $compiledFile ) {

		$cxt			= AppContext::get();
		$this->langRtn	= $cxt->translateRtn;
		$source 		= file_get_contents( $templateFile );
		$templateDir	= dirname ( $templateFile );

		if ( $cxt->inlineIncludes ) {
			/** 
			 * If the compiler is set in "inline includes" mode then any "included" template is 
			 * inlined rather than being called.  The substitution process adopts a KISS strategy,
			 * in that the substitution algo always restarts from the start of the source
			 * replacing the first include it finds.  This is a simple way of handling nested 
			 * includes without worrying about recursion issues within the class.
			 */
			while ( preg_match( '/ (?> \{ ) INCLUDE: ( [^{}] +) \} /x', $source , $matches ) ) {
				$from   = $matches[0];
				$file   = trim( $matches[1] );
				$to     = file_get_contents( "$templateDir/$file.html" );
				$source = str_replace( $from, $to, $source );
			}
		}
	   /**
		* The HTML input stream is split with a single \b preg_split() function call using the 
		* PREG_SPLIT_DELIM_CAPTURE modifier on the regexp <b>(\\{{1,2}|\\})</b> to generate a chunked 
		* <i>token, text, token, text, ...</i> stream. The text is passed through as is; comment 
		* tokens are ignored, and the remainder passed to transformSyntax() which also update the 
		* templateVars property with a list of variables used.  
		*/
		$chunk = preg_split( '/ ( \{{1,2} | \} )  /x', $source, -1, PREG_SPLIT_DELIM_CAPTURE );
		$chunk[] = ''; $chunk[] = '';
		$i = 0; $imax = count( $chunk );

		while ( $i < $imax ) {
			if( $chunk[$i] == '{{' ) {
				$chunk[$i++] = '{';
			} elseif( $chunk[$i] == '{' && $chunk[$i+2] == '}') {
				$chunk[$i]   = '';
				$chunk[$i+1] = $this->transformSyntax( $chunk[$i+1] );
				$chunk[$i+2] = '';
				$i += 3;
			} else {
				$i += 1;
			}
		}

	   /**
		* This templateVars array is then used in the standard generated class preamble, which
        * is then followed by the imploded chunk array. 
		*/
		$wantedVars = strtolower( implode( ':', array_keys( $this->templateVars ) ) );
		$this->templateVars = array();
		$translatedSource = 
"<?php class $className {
public function __construct(\$data, \$context) { 
foreach ( explode (':','$wantedVars') as \$v) {
\$ucvar='var'.strtoupper(\$v);
\$\$ucvar = (isset(\$data->\$v)) ? \$data->\$v : '';
}?>\n"	. implode( '', $chunk ) . "<?php }}\n";

		/*
		 * Lastly the translate source is written out the to generated template class file in the 
		 * _cache directory.  Note that adjacent close / open php escape tags and blank lines are 
		 * removed before doing so.
		 */
		file_put_contents( $compiledFile,
			preg_replace( array( '! ^ \s* \n !xm', '! \?\> [\s\n]* <\?php\s  !xs' ), array( '', '' ), $translatedSource ),
			LOCK_EX );
	}

	/**
	 * A callback routine is used within the parser to collect the list of wanted variables and so
	 * the object property templateVars is used.  Even though the templates can include templated 
	 * leading to recursive calls to output,  the compile itself is not recursive and so a simple 
	 * object property can be used.
	 */
	private function transformCallback( $matches ) {
		if (count( $matches ) == 2) {
			$this->templateVars[$matches[1]]=1; 
			return '$var' . $matches[1];
		} else {
			return "['{$matches[2]}']";
		}
	}
   /**
	* Transform a single template tag into the corresponding generated PHP code. This method does the
	* actual translation of the template syntax constructs, so for example \code
    *	{FOREACH:SIDE_KEYWORDS:KEYWORD:KEYSIZE} \endcode
	* gets translated to \code
	*	<?php foreach( $varSIDE_KEYWORDS as $varKEYWORD => $varKEYSIZE) { ?> \endcode
	*
	* @param $input tag to be translates
	* @returns the translated text
	*/ 
	private function transformSyntax( $input ) {

		$langRtn = $this->langRtn;

		if ( !preg_match( '! ^\s* (?: ( ELSE | ENDIF | ENDSWITCH | ENDFOR | ) \s* | 
			                          ( IF | ELSEIF | SWITCH |CASE |FOREACH |INCLUDE|TR ) \s*:\s* (.*) | 
				                      ( // ) .* |
									  (.*) )$ !xs', $input, $parse) ) {
			echo "Invalid syntax in template token: "; var_dump ( $input ); die;
 		} 

		// Note that the opening "[" bracket is captured in the second case so that the callback can 
		// use the 2/3 matches count from the regexp to descriminate which is appliable.
		$from = array( 
			'/\b ( [A-Z] [A-Z0-9_] *) \b/x',
			'/ (\[) (\w+) \] /x',			
		);

		switch( sizeof( $parse ) ) {

			case 2:						# Keywords with no arguments
					switch( $parse[1] ) {
					case 'ELSE':
						$string = '} else {';
						break;
					case 'ENDIF':
					case 'ENDSWITCH':
					case 'ENDFOR':
						$string = '}';
				}
				break;

			case 4:						# Keywords with one or more arguments
				if( $parse[2] == 'TR') {# TR is handled separately since variable subst is not done in the trans string
					$args = preg_split('/(?<!\\\\):/', $parse[3]);	
					$langStr = str_replace( '\\:', ':', array_shift( $args ) );
					if( isset($langRtn) ) {
						$langStr = $langRtn( $langStr );
					}
					if( sizeof( $args ) == 0 ) {
						return $langStr;  #########  Note early exit for bare translation ########
					}
					$args = preg_replace_callback( $from, array( &$this, 'transformCallback'), $args ); 
					$string = "printf('$langStr'," . implode( ',', $args ) . ");";
					break;
				}

				$arg  = preg_replace_callback( $from, array( &$this, 'transformCallback'), trim ( $parse[3] ) );
				switch( $parse[2] ) {
					case 'IF':
						$string = "if($arg) {";
						break;
					case 'ELSEIF':
						$string = "} elseif($arg) {";
						break;
					case 'SWITCH':
						$string = "switch($arg) { default: ";
						break;
					case 'CASE':
						$string = "break; case $arg :";
						break;
					case 'FOREACH':
						$pieces = explode(':', $arg);
						$string = sizeof($pieces) == 2 ? "foreach( $pieces[0] as $pieces[1]) {" :
								                         "foreach( $pieces[0] as $pieces[1] => $pieces[2]) {";
						break;
					case 'INCLUDE':
						$includedTemplate = trim( $parse[3] );
						$string = "\$page->output('$includedTemplate','' ,true);";
						break;
				}
				break;

			case 5:						# This is a template "//" comment.  So just ignore it
				$string = '';	
				break;

			case 6:						# an echo substitution
		        $string = 'echo ' . preg_replace_callback( 
										$from, 
										array( &$this, 'transformCallback'), 
										trim ( $parse[5] ) ) . ';';
		}
		return  $string ? "<?php $string ?>" : '';
	}
}
