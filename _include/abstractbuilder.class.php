<?php
/**
 * Abstract class for autoload builder classes.
 * With the expection of the few functions in functions.php which don't sensibly fit into the class
 * framework, all material functionality is implemented though classes.  The standard PHP 5 autoload
 * feature is used to implement class loading.  The version PHP 5 scripting engine uses this feature
 * resolve any references to an as yet unknown class by calling the function \b __autoload() with 
 * the desired classname as its argument. 
 * 
 * By default, the autoloader loads normal classes from the \b _cache directory.  However if a cache
 * miss occurs — that is the class isn't in the \b _cache directory — then the autoloader employs a 
 *\a builder \a class to create this cached copy.  

 * This AbstractBuilder class is the abstraction class for all such builders, and here I describe
 * the philosophy and the overall strategy for this approach.
 *
 * -  All builder classes have a name of the form \b Xxxx_builder (where \a Xxxx is a title-case word 
 *    that is the builder name). All builder classes are loaded directly from the \b _include directory
 *    using the corresponding filename pattern: <b><em>xxxx</em>.builder.class.php</b>. Using this
 *    directory avoids recursive issues of building builders, and this isn't a material performance 
 *    as building is a rare activity.
 *
 * -  The builder name is based on a simple convention for the class name: if DEFAULT_BUILDER_PATTERN
 *    is defined and the class name matches this pattern then the <em>default</em> builder class is 
 *    used.  If the class name is multiple words (that is separated by underscore or casechange) then 
 *    the first word in the class name is used as the builder name otherwise the <em>default</em>
 *    builder class is used.  
 *
 * -  A standard static method #build($className) is called within the selected builder, the job of 
 *    this \b build method is to construct the required cache copy to be used for this and future class
 *    loads.  The actual approach and strategy for the build itself is left entirely to the builder
 *    class, and hence \b __autoload() code itself is very straightforward.
 * 
 * This approach might seem to be rather convolved on first consideration, but it proves to be 
 * extremely flexible, effective and efficient:
 *
 * -  The builder is typically called once per S/W-release per object to create the \b _cache copy. 
 *    Processing carried out in the builder is therefore hoisted out of the per request or per function 
 *    call runtime overhead entirely.  
 *
 * -  Having the builder phase also effectively removes the need to use PHP runtime \b eval() and  
 *    \b create_function() constructs.  Using these can incur a high runtime penalty if the application
 *    programmer is not aware of the consequences, and they can also create exploitable vulnerabilities 
 *    in applications,  so some shared service environments use <tt>disable_functions = 
 *    eval,create_function</tt> in the PHP ini configuration.
 *
 * -  This also facilitates a safe model for dymanic code generation used by most output templates and 
 *    for use of configuration data driven code generation.  Moreover, as the "dynamic" output is written
 *    to a file in the cache directory, this generated code is immediately and perminently available to 
 *    the programmer for debugging, in a way that code in embedded string variables is not.
 *
 * -  Lastly, this also enables the use of liberal code commenting and splitting of code into sensible
 *    file units without incuring the material runtime burdon on most shared hosting services (which
 *    don't employ PHP opcode caches and therefore have to recompile the relevant application portions
 *    for each request. (See my blog article <a href="http://blog.ellisons.org.uk/article-44"> 
 *    More on optimising PHP applications in a Webfusion shared service</a> for 
 *    further discussion of this and the associated 
 *    <a href="http://blog.ellisons.org.uk/search-Performance">Performance</a> articles.)
 *
 * Examples of where such loaders are employed in this blog application include:
 *
 * -  TemplateBuilder.  This blog application uses a templating model for page content presentation — 
 *    one might describe this as 80% of the Smarty templating framework, but without any material
 *    runtime performance penalties. Each page template is an enriched HTML with embedded control 
 *    structures (\b IF, \b FOR, ...) and easy output variable referencing.  The builder converts
 *    each HTML file into its corresponding class file which is stored in \b _cache.
 *
 * -  HtmlBuilder.  I have an HTML cleanup utility which scrubs submitted HTML content to limit 
 *    the HTML content stored as article or comment content to a restricted grammar which is safe
 *    for inclusion in any HTML emitted as part of my blog.  I can't use a standard library here 
 *    because of the limitations of my Webfusion runtime environment, so this is a "roll-your-own"
 *    implementation largely inspired by the equivalent TinyMCE HTML scrubber.  However the TinyMCE 
 *    version is implemented in javascript and makes heavy use of dynamically generated enclosures. 
 *    This approach works well in javascript, but does not map efficiently onto a server-based PHP  
 *    environment.  Hence the HtmlBuilder generates any require data driven codethe by parsing the 
 *    configuration and morphs itself into an \b HTML_utilities class stored in \b _cache. 
 *
 * -  DefaultBuilder.  The default builder maps the source content from the corresponding \b _include
 *    file.  It makes no changes at the PHP generated bytecode level.  However, it optionally strips
 *    out all of the commenting and redundant whitespace, as well as inline any top level \b require 
 *    inclusions.  This both reduces the size and number of files that need to be compiled on a per
 *    URI request basis and this can make a material improvement to request responsiveness when 
 *    running in a Hosting shared service environment/
 *
 * Note that most builders are simple enough to be implemented by a single method call so the 
 * main build function is in the constructor method.  However, getLoadFile() is used to recover the
 * filename of the generated class.
 * 
 */
abstract class AbstractBuilder {

	protected $loadFile;			//< Name of generated loadfile

	/**
	 * Standard constructor for autoloaded builder classes.
	 * @param $className  Name of class to be build
	 * @param $includeDir Bootstrap context include directory
	 * @param $context    AppContext object to be used
	 */
	public abstract function __construct( $className, $includeDir, $context );

	/**
	 * Standard method for autoloaded builder classes.
	 * @returns the file name to be loaded by the autoloader
	 */
	public function getLoadFile() {
		return $this->loadFile;
	}
}
