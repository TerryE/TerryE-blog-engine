<?php
/**
 * HTML cleanup parser.  This filter restricts HTML content to a limited grammar defined by a ruleset 
 * as shown below, plus some tag-specific extensions (e.g. to close \b li tags to conform to XTHML 1.0).
 * It also tracks output line length and wraps the stream as necessary.  Since there isn't any sensible
 * HTML cleanup object, the cleanup function is implemented staticly.
 *
 * This class utilises a lot of data-driven code generated from the *_RULES constants, and so it is 
 * structured as a class builder. ( See AbstractBuilder documentation for a detailed discussion of the
 * builder strategy.)  It builds the target class, \b HtmlUtils, which embeds the generated code.
 * So this generator class is named HtmlBuilder under the builder conventions. This transforms itself
 * into the runtime version HtmlUtils, which is in the \b _cache directory, by 
 * -  adding the data-driven code 
 * -  dropping the transform-specific functions no longer required in the runtime version. 
 *
 * Previous versions used lambda-style functions generated on the fly.
 *
 *
 */
class HtmlBuilder extends AbstractBuilder {

//-============== Properies and functions that are not needed in the runtime version ================

	const ARTICLE_RULES =
'a:name:type:href,b,-!br,big,caption,center,-col:width,dd,dl,dt,em,+font:color,!h2,!h3,!h4,-!hr,i,+img:alt:border:height:src:style:title:width:align,+!li,+!ol:class,!p:class,+!pre,q,s,small,+span:style:class,strong,sub,sup,!table:width,tbody,td,tfoot,th,thead,!tr,tt,+u,+ul';

	const COMMENT_RULES =
		'a:name:href,b,-!br,caption,-!hr,!h4,i,+!li,+!ol,!p,+!pre,q,s,+span,strike,strong,sub,sup,tt,+ul';

	/**
	 * Generate the parse function for each valid tag using the tagList.
	 * The tag list is used to generate member function in the target \b HtmlUtils class to decode 
	 * each valid tag and attributes. 
	 *  
	 * In the list, the following prefixes have special meaning:
	 *	-	"-"	prefix indicates (e.g. -img) that the tag is self closing.
	 *	-	"+"	prefix appends a call to an extension routine to handle tag-specific funnies,
	 *			e.g. a pre tag set a flag to indiced that whitespace in bracketed content
	 *			can't be collapsed into a single space / CR.
	 *	- 	"!" prefix is used to force a CR before the opening tag in the output.  	
	 *
	 * Note that the lamda functions invoke callback static methods.
	 */	
	public static function build ( $className ) {

		// Pick up tail content which needs to included in class 
		$thisSource		= file_get_contents( __FILE__ );
		$inSource		= substr( $thisSource, strpos( $thisSource, "\n//+" ) );

		$tagFunctions	= array();
		foreach( array ( 'article' => self::ARTICLE_RULES,
		                 'comment' => self::COMMENT_RULES ) as $type => $tagList ) {
			//Now generate tag parse methods		
			foreach( explode( ',', $tagList ) as $tag ) {
				// calculate the prefix and tags dependent options
				$attrs		= explode ( ':', $tag );
				$tag		= array_shift( $attrs );
				$extn		= $tag[0] == '+';				if( $extn )     $tag = substr( $tag, 1 );
				$noEndTag	= $tag[0] == '-' ? '/' : '';	if( $noEndTag ) $tag = substr( $tag, 1 );
				$forceCR	= $tag[0] == '!';				if( $forceCR )  $tag = substr( $tag, 1 );
		
				$optTagEnd	= $noEndTag	? '' : "</$tag>";
				$optCR		= $forceCR	? '\n' : '';

				$protoCcode = "private static function {$type}_{$tag}( \$endTag, \$attr ) {"; 

				if( count($attrs) == 0 ) {
					// The code for no attibute tags is reasonably straight forward e.g.
					//   $newTag = $endTag ? '</q>' : "<q>";
					//   $newTag = $endTag ? '</pre>' : "\n<pre>";
					$code = '';
					$tagCode = "\$endTag ? '$optTagEnd' : \"$optCR<$tag$noEndTag>\"";  
				} else {
					// The code will include a set of attribute filters such as 
					//   if( isset( $attr['class'] ) ) $newTag .= " class=$attr[class]";
					$attrCode = array();
					foreach( $attrs as $a ) {
						$attrCode [] = "if( isset( \$attr['$a'] ) ) \$newTag .= \" $a=\\\"\$attr[$a]\\\"\";";
					}

					$code = "if( \$endTag ) { \$newTag = '</$tag>'; }\n" .
							"else { \$newTag = \"$optCR<$tag\";\n" . 
							implode ("\n", $attrCode) . "\n" .
							"\$newTag .= '$noEndTag>';\n}";
					$tagCode = '$newTag';
				}

				$returnCode = $extn ? 
							  "return self::{$tag}Extn(\$endTag, \$attr, $tagCode);\n}\n" :
							  "return $tagCode;\n}\n";

				$tagFunctions[] = "$protoCcode$code\n$returnCode";
			}
		}
		$outSource	= "<?php\nclass HtmlUtils {\n" . implode( "\n", $tagFunctions ) . $inSource;
		$outfile	= AppContext::get()->cacheDir . "html.utils.class.php";
		file_put_contents( $outfile, $outSource, LOCK_EX );
		return $outfile;
	}

//+================ Properties and methods that are maintained in the runtime version =================

	const ARTICLE = 1;
	const COMMENT = 2;
	const LINE_WRAP = 92;

/**
 * Main HTML cleanup function. This function is not invoked in the HtmlBuilder class, but within the
 * target \b HtmlUtils class, it will scan a valid HTML stream and filter all embedded tags
 * according to a ruleset.  Each individual tags might be accepted, ignored or have its properties 
 * transformed. The target LAMP system does not include suitable php libraries to help here, so this is
 * filter is implemented entirely in this class.  The general approach was inspired by the equivalent 
 * javascript-based TinyMCE HTML filter.  
 *
 * A rules vector is used to generate a dynamic lambda function which will parse the corresponding tag.
 * The format is 
 * 	rule,rule,... 
 * where each rule is of the form:
 * 	(-)(+)(!)tag:att1:att2...
 *
 * The method HTMLutils::genParseFunctions is used to generate these function, and the format of the 
 * tags is described there. 
 * 
 * The input stream is chopped into \<tag\>{optional text stream} repeats, and if a corresponding
 * method exists in the class then this is invoked to parse the tags.  The easiest way to understand 
 * how this works is to view the generated HtmlUtils class. 
 * 
 */
	public static function cleanupHTML( $sourceHTML, $type ) {

		$prefix		= ($type == self::ARTICLE) ? 'article_' : 'comment_';
		$newHTMl	= array();
		$lineLength	= 0;

		// explode HTML on the tag openning character "<"
		foreach (explode ( '<', $sourceHTML ) as $segment) {

			// Parse each <html tag + attributes> ... content ...
			if ( preg_match( '!^ (/?) (\w+) \s* ([^>]*) > (.*) $!xs', $segment, $part ) ) {
				$endTag		= $part[1]=='/';
				$tag		= strtolower( $part[2] );
				$attribs	= $part[3];
				$content	= $part[4];

				$attr		= array();

				// Split the tag attributes section into separate attributes
				if( preg_match_all ( '/ \s* (\w+) \s* = \s* ( \w+ | " [^"]* " ) /xs', $attribs, $matches, PREG_SET_ORDER ) ) {
					foreach( $matches as $m ) {
						$attr[strtolower( $m[1] )] = ($m[2][0]=='"') ? substr( $m[2], 1, -1) : $m[2];
					}
				}

				// For alloweable tags, use the corresponding parser routine to filter; 
				// Otherwise just ignore the entire tag
				if( method_exists ( __CLASS__, $prefix . $tag ) ) {
					$newTag = call_user_func( array( __CLASS__, $prefix . $tag ), $endTag, $attr );
					if(strlen ($newTag) > 0 ) {

						// Do a simple wrap algo.  It doesn't matter that the edge is wragged
						$tagLength = strlen( $newTag );
						$tagSpace = strpos( $newTag, ' ' );
						if( substr( $newTag, 0, 1 ) == "\n" ) {
							$lineLength = $tagLength;
						} elseif( ( $lineLength + $tagLength ) > self::LINE_WRAP && $tagSpace !== false ) { 
							$newTag = substr_replace( $newTag, "\n", $tagSpace, 1);
							$lineLength = $tagLength - $tagSpace;
						} else {
							$lineLength += $tagLength;
						}
						$newHTML[] = $newTag;
					}
				}
				if( $content == '' ) continue;

				// For content text outside PRE tags, compress whitespace and wrap to avoid line becomming too long.
				if( !self::$inPreTag ) {
					$content	= preg_replace( '![\s\r\n]+!', ' ', $content );
					$lineTail	= ($lineLength < self::LINE_WRAP ) ? self::LINE_WRAP - $lineLength : 1;
					$content	= preg_replace( "! (.{{$lineTail}} \S* )\s !x", "\$1\n", $content, 1 );
					$content	= preg_replace( '! (.{' . self::LINE_WRAP . '} \S* )\s !x', "\$1\n", $content );
					$posCR		= strrpos($content, "\n");
					$lineLength	= ($posCR === false) ? strlen( $content ) + $lineLength : strlen( $content ) - $posCR;
				}
				$newHTML[]	= $content; 
			} else {
				$posCR		= strrpos($segment, "\n");
				$lineLength	= ($posCR === false) ? strlen( $segment ) + $lineLength : strlen( $segment ) - $posCR;
				$newHTML[]	= $segment;
			}
		}

		if( $type == self::ARTICLE ) {
			// The article-N anchors are "special" but can be munched by some editors so we need to revert.
			preg_replace( '! <a \s href=".*?article- (\d+) "> \?\?\? </a> !x', 
				       '<a href="article-$1">???</a>', $content );
		}
		return implode ( "", $newHTML ) . "\n";
	}

	static private $inPreTag		= FALSE;
	static private $inLiTag			= array();
	static private $validSpanTag	= array();

	//============================== Non-standard processing extensions ==============================

	/**
	 * The ul extension closes li tags when parsing HTML to conform to XHTML
	 */
	private static function ulExtn($endTag,$attrs,$newTag) { 
		if( !$endTag ) array_unshift( self::$inLiTag, false );
		elseif( array_shift( self::$inLiTag ) ) $newTag = "</li>$newTag"; 
		return $newTag;
	}

	/**
	 * The ol extension closes li tags when parsing HTML to conform to XHTML
	 */
	private static function olExtn($endTag,$attrs,$newTag) {
		return self::ulExtn($endTag,$attrs,$newTag);
	}

	/**
	 * The li extension closes li tags when parsing HTML to conform to XHTML
	 */
	private static function liExtn($endTag,$attrs,$newTag) {
		if( $endTag ) {
			self::$inLiTag[0] = false; 
		} else {
			if( self::$inLiTag[0] ) $newTag = "</li>$newTag";
			else self::$inLiTag[0] = true;
		}
		return $newTag;
	}

	/**
	 * The span extension filters the attributes and only permits the color and underline attributes. Empty spans are removed. 
	 */
	private static function spanExtn($endTag,$attrs,$newTag) {
		if( $endTag ) {
			return array_shift( self::$validSpanTag ) ? $newTag : ''; 
		} else {
			$newTag = isset( $attrs['class'] ) ? "class=\"$attrs[class]\"" : ""; 
			if( preg_match_all( '/ \s* (\S+) \s* : \s* ([^;]+) /x', strtolower( $attrs['style'] ), $m, PREG_PATTERN_ORDER ) ) {
				$styles = array_combine ( $m[1], $m[2] );
				$newStyle = '';
				if( isset( $styles['color'] ) ) $newStyle .= "color : $styles[color];";
				if( isset( $styles['text-decoration'] ) ) $newStyle .= "text-decoration : {$styles['text-decoration']};";
		        if( $newStyle !== '' ) $newTag .= " style=\"$newStyle\""; 
			}
			$valid = ( $newTag !== '' );
			array_unshift( self::$validSpanTag, $valid );
			return $valid ? "<span $newTag>" : ''; 		 
		}
	}

	/**
	 * The img extension maps the align tag onto the equivalent style. 
	 */
	private static function imgExtn($endTag,$attrs,$newTag) {
		debugVar( "image tag", array ( $endTag, $attrs, $newTag ) );
		if( $endTag || !isset( $attrs['align'] ) || isset( $attrs['style'] ) ) return $newTag;
		return substr( $newTag, 0, -2 ) . "style=\"float:$attrs[align];\"" . '/>';
	}

	/**
	 * The font extension replaces \<font color="xx"\> with the \<span style="color: xx"\> equivalent 
	 */
	private static function fontExtn($endTag,$attrs,$newTag) {
		if( $endTag ) return '</span>';
		return "<span style=\"color:$attrs[color];\">";
	} 

	/**
	 * The u extension replaces the tag with the XHTML 1.0 conformant style equivalent
	 */
	private static function uExtn($endTag,$attrs,$newTag) {
		if( $endTag ) return '</span>';
		return '<span style="text-decoration:underline;">';
	} 

	/**
	 * The pre extension sets a global inPreTag flag.  (When this is false, whitespace within content is collapsed)
	 */
	private static function preExtn($endTag,$attrs,$newTag) {
		self::$inPreTag = !$endTag; 
		return $newTag; 
	}
}
